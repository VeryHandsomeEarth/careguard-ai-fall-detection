/*
 * ESP32 เก็บข้อมูลเซ็นเซอร์ MPU-6050 สำหรับกิจวัตรประจำวัน (ADL)
 * CollectData_Hardware_ADL — Command-Triggered ADL Data Logger
 *
 * อ้างอิงโปรโตคอลการเก็บข้อมูลจากงานวิจัย SisFall Dataset:
 * Sucerquia, A., López, J. D., & Vargas-Bonilla, J. F. (2017).
 * "SisFall: A Fall and Movement Dataset", Sensors, 17(1), 198. (PMC5298771)
 *
 * ลำดับตรรกะการทำงาน (5-State Machine):
 * 1) IDLE_POLLING     : อุปกรณ์ถามสถานะจากเว็บ CollectData_Web ทุก 1.5 วินาที
 * 2) COUNTDOWN_START  : เมื่อกดเริ่มบนเว็บ อุปกรณ์ส่งสัญญาณเสียงปล่อยตัวแบบไฟแข่งรถ
 *                       "3... 2... 1... GO!" (ปี๊บสั้น 3 ครั้ง ห่างกัน 1 วินาที + ปี๊บยาวเริ่มวิ่ง)
 * 3) RECORDING_ADL    : อ่านค่า MPU-6050 ที่ความถี่ 50Hz (ทุก 20ms) ต่อเนื่องจนครบเวลาที่กำหนด
 *                       (เช่น 12 วินาที = 600 จุดข้อมูล หรือ 15-25 วินาทีตามประเภทท่า ADL)
 * 4) FINISH_ALERT     : เมื่อครบกำหนดเวลา ส่งเสียง Buzzer ดัง 3 จังหวะ (ปี๊บ-ปี๊บ-ปี๊บยาว!)
 *                       เพื่อบอกให้ผู้ทดสอบหยุดทำท่าทางทันที
 * 5) UPLOAD_COMPLETE  : ส่งข้อมูลขึ้นเซิร์ฟเวอร์แบบ Batch พร้อมแนบสถานะ is_final=true
 *                       เพื่อเปลี่ยนสถานะบนเว็บเป็น "เสร็จสิ้น (completed)" อัตโนมัติ
 */

#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <Arduino.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiManager.h>

// ==================== การตั้งค่า WiFi / Server ====================

#define AP_SSID "CollectData_ADL_AP"
#define AP_PASSWORD ""

const char *CHECK_CMD_URL =
    "https://www.youngza.com/IMU/CollectData_Web/api/actions.php";
const char *RECEIVE_DATA_URL =
    "https://www.youngza.com/IMU/CollectData_Web/api/receive_data.php";

String MAC_ADDRESS = "";

// ==================== การตั้งค่าอุปกรณ์ ====================

#define BUZZER_PIN 4
#define LED_PIN 2

Adafruit_MPU6050 mpu;

// ==================== การตั้งค่า Sampling / Buffer ====================

const int SAMPLE_RATE_MS = 20;      // 50Hz (ทุก 20 มิลลิวินาที)
const int DEFAULT_DURATION_S = 12;  // ค่าเริ่มต้นตาม SisFall ADL (12 วินาที)
const int MAX_DURATION_S = 25;      // รองรับสูงสุด 25 วินาทีต่อรอบ (1,250 samples)
const int MAX_ADL_SAMPLES = (MAX_DURATION_S * 1000) / SAMPLE_RATE_MS; // 1,250 จุด
const int BATCH_SIZE = 50;          // ส่งข้อมูลครั้งละ 50 จุด (1 วินาที)

struct SensorSample {
  long timestamp_ms;
  float accel_x, accel_y, accel_z;
  float gyro_x, gyro_y, gyro_z;
};

// Buffer เก็บข้อมูล ADL ต่อ 1 รอบการทดลอง
SensorSample adlBuffer[MAX_ADL_SAMPLES];
int adlSampleCount = 0;
int targetSamples = (DEFAULT_DURATION_S * 1000) / SAMPLE_RATE_MS; // 600 จุด

// ==================== สถานะ State Machine ====================

enum LoggerState {
  STATE_IDLE_POLLING,
  STATE_COUNTDOWN_START,
  STATE_RECORDING_ADL,
  STATE_FINISH_ALERT,
  STATE_UPLOADING
};

LoggerState currentState = STATE_IDLE_POLLING;

int activeRecordId = 0;
String activeActivityCode = "";
String activeActivityName = "";
int activeDurationSec = DEFAULT_DURATION_S;

unsigned long lastPollTime = 0;
unsigned long lastSampleTime = 0;
unsigned long recordingStartTime = 0;
unsigned long lastWiFiCheck = 0;

// ==================== ประกาศฟังก์ชัน ====================

void setupWiFi();
void setupMPU6050();
SensorSample readMPUSample(long timestamp_ms);

bool pollServerCommand();
void playRaceCountdown();
void playFinishAlert();
void playSuccessMelody();
void beepTone(int duration_ms, int pause_ms);

bool uploadADLData();
void handleSerial();
float round2(float val);

// ==================== Setup ====================

void setup() {
  Serial.begin(115200);
  delay(1000);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  Serial.println("\n=======================================================");
  Serial.println("  CollectData_Hardware_ADL — SisFall Protocol Logger");
  Serial.println("  Command-Triggered + 3-2-1-GO Countdown + Finish Alert");
  Serial.println("=======================================================");

  setupWiFi();
  setupMPU6050();

  MAC_ADDRESS = WiFi.macAddress();
  Serial.printf("[อุปกรณ์] MAC Address: %s\n", MAC_ADDRESS.c_str());
  Serial.println("[พร้อม] กำลังรอคำสั่งเริ่มบันทึกกิจวัตร (ADL) จากหน้าเว็บ CollectData_Web...");
}

// ==================== Loop หลัก ====================

void loop() {
  unsigned long now = millis();

  // ตรวจสอบ WiFi ทุก 30 วินาทีขณะอยู่ในโหมดรอคำสั่ง
  if (currentState == STATE_IDLE_POLLING && now - lastWiFiCheck >= 30000) {
    lastWiFiCheck = now;
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("[WiFi] ขาดการเชื่อมต่อ กำลังเชื่อมต่อใหม่...");
      WiFi.reconnect();
    }
  }

  switch (currentState) {

    // ---------------------------------------------------------
    // 1. โหมดรอคำสั่งจากเว็บเซิร์ฟเวอร์ (Polling ทุก 1.5 วินาที)
    // ---------------------------------------------------------
    case STATE_IDLE_POLLING:
      if (now - lastPollTime >= 1500) {
        lastPollTime = now;
        if (WiFi.status() == WL_CONNECTED && pollServerCommand()) {
          currentState = STATE_COUNTDOWN_START;
        }
      }
      break;

    // ---------------------------------------------------------
    // 2. โหมดส่งสัญญาณเสียงปล่อยตัว (3 - 2 - 1 - GO!)
    // ---------------------------------------------------------
    case STATE_COUNTDOWN_START:
      Serial.printf("\n[เริ่มงาน] เตรียมบันทึก #%d (%s - %s) เป็นเวลา %d วินาที (%d จุด)\n",
                    activeRecordId, activeActivityCode.c_str(),
                    activeActivityName.c_str(), activeDurationSec, targetSamples);
      playRaceCountdown();

      adlSampleCount = 0;
      recordingStartTime = millis();
      lastSampleTime = recordingStartTime;
      digitalWrite(LED_PIN, HIGH); // ไฟ LED ติดค้างตลอดช่วงที่กำลังบันทึก
      currentState = STATE_RECORDING_ADL;
      Serial.println("[กำลังบันทึก] เริ่มเก็บข้อมูล 50Hz...");
      break;

    // ---------------------------------------------------------
    // 3. โหมดเก็บข้อมูลจริงที่ความถี่ 50Hz (ทุก 20ms)
    // ---------------------------------------------------------
    case STATE_RECORDING_ADL:
      if (now - lastSampleTime >= SAMPLE_RATE_MS) {
        lastSampleTime += SAMPLE_RATE_MS;
        long elapsed_ms = (long)(now - recordingStartTime);

        if (adlSampleCount < targetSamples && adlSampleCount < MAX_ADL_SAMPLES) {
          adlBuffer[adlSampleCount] = readMPUSample(elapsed_ms);
          adlSampleCount++;

          // แสดงความคืบหน้าทุกๆ 1 วินาที (50 ตัวอย่าง)
          if (adlSampleCount % 50 == 0) {
            Serial.printf("  -> บันทึกแล้ว %d / %d จุด (%d วินาที)\n",
                          adlSampleCount, targetSamples, adlSampleCount / 50);
          }
        }

        // เมื่อเก็บครบจำนวนจุดตามเวลาที่กำหนดแล้ว
        if (adlSampleCount >= targetSamples || adlSampleCount >= MAX_ADL_SAMPLES) {
          digitalWrite(LED_PIN, LOW);
          currentState = STATE_FINISH_ALERT;
        }
      }
      break;

    // ---------------------------------------------------------
    // 4. โหมดส่งเสียงดังแจ้งเตือนสิ้นสุดการบันทึกข้อมูล
    // ---------------------------------------------------------
    case STATE_FINISH_ALERT:
      Serial.println("[ครบกำหนดเวลา] ส่งสัญญาณเสียงแจ้งเตือนสิ้นสุดการบันทึก!");
      playFinishAlert();
      currentState = STATE_UPLOADING;
      break;

    // ---------------------------------------------------------
    // 5. โหมดส่งข้อมูลขึ้นเซิร์ฟเวอร์และปิดงานอัตโนมัติ
    // ---------------------------------------------------------
    case STATE_UPLOADING:
      if (uploadADLData()) {
        Serial.println("[สำเร็จ] ส่งข้อมูลและปิดสถานะบันทึกเสร็จสมบูรณ์!");
        playSuccessMelody();
        activeRecordId = 0;
        currentState = STATE_IDLE_POLLING;
        Serial.println("\n[พร้อม] รอคำสั่งรอบต่อไปจากหน้าเว็บ...");
      } else {
        Serial.println("[ล้มเหลว] ส่งข้อมูลไม่สำเร็จ จะลองส่งใหม่ใน 3 วินาที...");
        delay(3000);
      }
      break;
  }

  handleSerial();
}

// ==================== ฟังก์ชันเสียงสัญญาณ (Race Countdown & Finish) ====================

void beepTone(int duration_ms, int pause_ms) {
  digitalWrite(BUZZER_PIN, HIGH);
  digitalWrite(LED_PIN, HIGH);
  delay(duration_ms);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(LED_PIN, LOW);
  if (pause_ms > 0) {
    delay(pause_ms);
  }
}

void playRaceCountdown() {
  Serial.println("[Countdown] เตรียมตัว... 3 - 2 - 1 - เริ่ม!");
  // จังหวะ 3
  Serial.println("  [3] ปี๊บ!");
  beepTone(180, 820);
  // จังหวะ 2
  Serial.println("  [2] ปี๊บ!");
  beepTone(180, 820);
  // จังหวะ 1
  Serial.println("  [1] ปี๊บ!");
  beepTone(180, 820);
  // จังหวะ GO! (ลากเสียงยาวให้ผู้ทดสอบเริ่มเคลื่อนไหวทันที)
  Serial.println("  [GO!] ปี๊บบบบบยาว! (เริ่มเคลื่อนไหว)");
  beepTone(650, 50);
}

void playFinishAlert() {
  // เสียงดัง 3 จังหวะ (สั้น-สั้น-ยาว) บอกให้ผู้ทดสอบหยุดทันที
  beepTone(200, 120);
  beepTone(200, 120);
  beepTone(800, 100);
}

void playSuccessMelody() {
  beepTone(80, 60);
  beepTone(80, 60);
}

// ==================== ตรวจสอบคำสั่งจากเซิร์ฟเวอร์ ====================

bool pollServerCommand() {
  HTTPClient http;
  String url = String(CHECK_CMD_URL) + "?action=check_command&mac=" + MAC_ADDRESS;

  http.begin(url);
  http.setTimeout(4000);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    http.end();

    JsonDocument doc;
    DeserializationError err = deserializeJson(doc, payload);
    if (!err && doc["success"] == true && doc["has_command"] == true) {
      activeRecordId = doc["record_id"] | 0;
      activeActivityCode = doc["activity_code"] | "ADL";
      activeActivityName = doc["activity_name"] | "กิจวัตรปกติ";
      activeDurationSec = doc["duration_sec"] | DEFAULT_DURATION_S;

      if (activeDurationSec > MAX_DURATION_S) {
        activeDurationSec = MAX_DURATION_S;
      }
      if (activeDurationSec < 3) {
        activeDurationSec = 3;
      }

      targetSamples = (activeDurationSec * 1000) / SAMPLE_RATE_MS;
      return true;
    }
  } else {
    http.end();
  }
  return false;
}

// ==================== ส่งข้อมูล ADL ขึ้นเซิร์ฟเวอร์ ====================

bool uploadADLData() {
  if (WiFi.status() != WL_CONNECTED || adlSampleCount <= 0) {
    return false;
  }

  Serial.printf("[Upload] กำลังส่งข้อมูลทั้งหมด %d จุด (Record #%d)...\n",
                adlSampleCount, activeRecordId);

  int totalBatches = (adlSampleCount + BATCH_SIZE - 1) / BATCH_SIZE;

  for (int b = 0; b < totalBatches; b++) {
    int startIdx = b * BATCH_SIZE;
    int endIdx = min(startIdx + BATCH_SIZE, adlSampleCount);
    bool isFinalBatch = (b == totalBatches - 1);

    JsonDocument doc;
    doc["mac_address"] = MAC_ADDRESS;
    if (activeRecordId > 0) {
      doc["record_id"] = activeRecordId;
    }
    if (isFinalBatch) {
      doc["is_final"] = true; // สั่งให้เซิร์ฟเวอร์เปลี่ยนสถานะเป็น completed ทันที
    }

    JsonArray dataArr = doc["data"].to<JsonArray>();
    for (int i = startIdx; i < endIdx; i++) {
      JsonObject obj = dataArr.add<JsonObject>();
      obj["t"] = adlBuffer[i].timestamp_ms;
      obj["ax"] = round2(adlBuffer[i].accel_x);
      obj["ay"] = round2(adlBuffer[i].accel_y);
      obj["az"] = round2(adlBuffer[i].accel_z);
      obj["gx"] = round2(adlBuffer[i].gyro_x);
      obj["gy"] = round2(adlBuffer[i].gyro_y);
      obj["gz"] = round2(adlBuffer[i].gyro_z);
    }

    String jsonPayload;
    serializeJson(doc, jsonPayload);

    HTTPClient http;
    http.begin(RECEIVE_DATA_URL);
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(8000);

    int httpCode = http.POST(jsonPayload);
    http.end();

    if (httpCode != HTTP_CODE_OK) {
      Serial.printf("  [Error] Batch %d/%d ล้มเหลว (HTTP %d)\n", b + 1, totalBatches, httpCode);
      return false;
    }
    Serial.printf("  [OK] ส่ง Batch %d/%d สำเร็จ (%d จุด)\n",
                  b + 1, totalBatches, endIdx - startIdx);
  }

  return true;
}

// ==================== อ่านค่า MPU-6050 ====================

SensorSample readMPUSample(long timestamp_ms) {
  sensors_event_t a, g, temp;
  mpu.getEvent(&a, &g, &temp);

  SensorSample s;
  s.timestamp_ms = timestamp_ms;
  s.accel_x = a.acceleration.x;
  s.accel_y = a.acceleration.y;
  s.accel_z = a.acceleration.z;
  s.gyro_x = g.gyro.x;
  s.gyro_y = g.gyro.y;
  s.gyro_z = g.gyro.z;
  return s;
}

// ==================== ตั้งค่า WiFi & MPU-6050 ====================

void setupWiFi() {
  WiFi.mode(WIFI_STA);
  WiFiManager wm;
  wm.setConfigPortalTimeout(180);
  if (!wm.autoConnect(AP_SSID, AP_PASSWORD)) {
    Serial.println("[WiFi] ไม่สามารถเชื่อมต่อได้ กำลังรีสตาร์ท...");
    delay(2000);
    ESP.restart();
  }
  Serial.printf("[WiFi] เชื่อมต่อสำเร็จ! IP: %s\n", WiFi.localIP().toString().c_str());
}

void setupMPU6050() {
  if (!mpu.begin()) {
    Serial.println("[MPU6050] ไม่พบเซ็นเซอร์ MPU-6050! กรุณาตรวจสอบสาย I2C");
    while (1) {
      digitalWrite(LED_PIN, !digitalRead(LED_PIN));
      delay(200);
    }
  }
  mpu.setAccelerometerRange(MPU6050_RANGE_16_G);
  mpu.setGyroRange(MPU6050_RANGE_2000_DEG);
  mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
  Serial.println("[MPU6050] ตั้งค่าเรียบร้อย (±16G, ±2000°/s, BW 21Hz)");
}

float round2(float val) {
  return roundf(val * 100.0f) / 100.0f;
}

void handleSerial() {
  if (Serial.available()) {
    char c = Serial.read();
    if (c == 's' && currentState == STATE_IDLE_POLLING) {
      activeRecordId = 0;
      activeActivityCode = "MANUAL_ADL";
      activeActivityName = "ทดสอบบันทึกด้วยมือ";
      activeDurationSec = 12;
      targetSamples = (activeDurationSec * 1000) / SAMPLE_RATE_MS;
      currentState = STATE_COUNTDOWN_START;
    }
  }
}
