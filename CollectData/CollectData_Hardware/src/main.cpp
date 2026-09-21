/*
 * ESP32 เก็บข้อมูลเซ็นเซอร์ MPU-6050
 * CollectData_Hardware — Fall Detection Data Logger
 *
 * เวอร์ชันปรับแก้ตามหลักการที่เสนอ:
 * - อ่าน MPU-6050 ต่อเนื่อง 50Hz (ทุก 20ms)
 * - เก็บข้อมูลก่อนล้มด้วย Circular Buffer ประมาณ 1 วินาที
 * - เมื่อตรวจพบเหตุการณ์ล้ม จะรวมข้อมูลก่อนล้ม + หลังล้ม
 * - ปรับเงื่อนไขตรวจจับจาก aMag > 2.5G อย่างเดียว
 *   เป็น multi-condition เพื่อลด false alarm:
 *     1) Hard impact >= 3.2G ให้ Trigger ได้ทันที
 *     2) Impact >= 2.5G ต้องมี low-G หรือ gyro สูงร่วมด้วย
 * - ถ้า WiFi หลุดขณะส่ง จะเก็บ event ล่าสุดไว้และลองส่งใหม่ภายหลัง
 *
 * หมายเหตุฝั่ง Server/Database:
 * - ค่า t ของข้อมูลก่อนล้มจะเป็นค่าติดลบ เช่น -980, -960, ... ms
 * - field timestamp/t ในฐานข้อมูลควรเป็น INT แบบ signed ไม่ควรใช้ UNSIGNED
 */

#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <Arduino.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiManager.h>

// ==================== การตั้งค่า WiFi / Server ====================

#define AP_SSID "CollectData_AP"
#define AP_PASSWORD ""

const char *API_URL =
    "https://www.youngza.com/IMU/CollectData_Web/api/receive_data.php";

String MAC_ADDRESS = "";

// ==================== การตั้งค่าอุปกรณ์ ====================

#define BUZZER_PIN 4
#define LED_PIN 2

Adafruit_MPU6050 mpu;

// ==================== การตั้งค่า Sampling / Buffer ====================

const int SAMPLE_RATE_MS = 20; // 50Hz

// เก็บข้อมูลก่อนล้มและหลังล้มอย่างละ 1 วินาที
const int PRE_EVENT_MS = 1000;
const int POST_EVENT_MS = 1000;

const int PRE_EVENT_SAMPLES = PRE_EVENT_MS / SAMPLE_RATE_MS;   // 50 samples
const int POST_EVENT_SAMPLES = POST_EVENT_MS / SAMPLE_RATE_MS; // 50 samples

// buffer หลักสำหรับ 1 เหตุการณ์: ก่อนล้ม + หลังล้ม + เผื่อ timing เล็กน้อย
const int MAX_EVENT_BUFFER = PRE_EVENT_SAMPLES + POST_EVENT_SAMPLES + 10;

// ส่งข้อมูลเป็น batch
const int BATCH_SIZE = 50;

// ==================== Threshold สำหรับ Fall Detection ====================

// ค่าแรงกระแทกหลัก
const float IMPACT_THRESHOLD_G = 2.5;

// ถ้าแรงกระแทกสูงมาก ให้ Trigger ได้ทันที
const float HARD_IMPACT_THRESHOLD_G = 3.2;

// ใช้ตรวจช่วง low-G ก่อนล้ม เช่น เสียสมดุล / free-fall บางช่วง
const float LOW_G_THRESHOLD = 0.75;

// ค่า gyro รวม หน่วย degree/s ใช้ช่วยบอกว่ามีการหมุน/เสียหลัก
const float GYRO_THRESHOLD_DPS = 200.0;

// ระยะเวลาย้อนหลังที่ยอมรับว่า low-G หรือ gyro สูงยังสัมพันธ์กับ event นี้
const unsigned long FALL_PATTERN_WINDOW_MS = 800;

// ==================== โครงสร้างข้อมูล ====================

struct SensorSample {
  // ใช้ long เพราะข้อมูลก่อน Trigger จะมีค่าเวลาเป็นลบ
  long timestamp_ms;
  float accel_x, accel_y, accel_z;
  float gyro_x, gyro_y, gyro_z;
};

// Circular Buffer สำหรับข้อมูลก่อนล้ม
SensorSample preBuffer[PRE_EVENT_SAMPLES];
int preWriteIndex = 0;
int preCount = 0;

// Event Buffer สำหรับส่ง Server
SensorSample eventBuffer[MAX_EVENT_BUFFER];
int eventCount = 0;
int postSampleCount = 0;

// ==================== สถานะระบบ ====================

bool isRecordingEvent = false;
bool isBuzzing = false;
bool pendingUpload = false;

unsigned long triggerTime = 0;
unsigned long buzzerEndTime = 0;
unsigned long lastSampleTime = 0;
unsigned long lastWiFiCheck = 0;
unsigned long lastUploadRetry = 0;

unsigned long lastLowGTime = 0;
unsigned long lastHighGyroTime = 0;

// สถานะสำหรับ WiFi indicator (LED + Buzzer)
bool lastWiFiConnected = false;
unsigned long lastWiFiBlinkTime = 0;
bool isPlayingMelody = false; // flag เพื่อหยุด WiFi indicator ขณะเล่น melody
bool isSendingData = false;   // flag เพื่อหยุด WiFi indicator ขณะส่งข้อมูล

// เก็บค่า debug ตอน Trigger
float triggerAMagG = 0.0;
float triggerGyroDps = 0.0;
bool triggerHadLowG = false;
bool triggerHadHighGyro = false;
bool triggerHardImpact = false;

// ==================== ประกาศฟังก์ชัน ====================

void setupWiFi();
void setupMPU6050();

SensorSample readMPUSample(long timestamp_ms);
void storePreSample(const SensorSample &sample);

float calcAMagG(const SensorSample &sample);
float calcGyroMagnitudeDps(const SensorSample &sample);
bool shouldTriggerFall(float aMagG, float gyroDps, unsigned long now);

void startFallEvent(unsigned long now, float aMagG, float gyroDps);
void collectPostEventSample();
void finishFallEvent();

bool trySendEventData();
void handleSerial();
float round2(float val);

void playSuccessMelody();
void handleWiFiIndicator(unsigned long now);

// ==================== Setup ====================

void setup() {
  Serial.begin(115200);
  delay(1000);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  Serial.println("\n==========================================");
  Serial.println("  CollectData Hardware — Fall Detection");
  Serial.println("  Pre-event + Post-event Buffer");
  Serial.println("==========================================");

  setupWiFi();
  setupMPU6050();

  MAC_ADDRESS = WiFi.macAddress();
  Serial.printf("[อุปกรณ์] MAC Address: %s\n", MAC_ADDRESS.c_str());

  Serial.println("\n[พร้อม] ระบบเริ่มทำงาน!");
  Serial.println("[หลักการ]");
  Serial.println("  - อ่าน MPU-6050 ทุก 20 ms");
  Serial.println("  - เก็บข้อมูลก่อนล้มไว้ตลอดเวลา 1 วินาทีล่าสุด");
  Serial.println("  - เมื่อตรวจพบการล้ม จะรวมข้อมูลก่อนล้ม + หลังล้ม");
  Serial.println("  - ใช้เงื่อนไข impact + low-G/gyro เพื่อลด false alarm");
}

// ==================== Loop หลัก ====================

void loop() {
  unsigned long now = millis();

  // ปิด Buzzer/LED เมื่อครบเวลา
  if (isBuzzing && now >= buzzerEndTime) {
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
    isBuzzing = false;
  }

  // อ่านเซ็นเซอร์ตามรอบเวลา 20 ms
  if (now - lastSampleTime >= SAMPLE_RATE_MS) {
    lastSampleTime = now;

    if (!isRecordingEvent) {
      // อ่านค่าและเก็บลง preBuffer ตลอดเวลา
      SensorSample sample = readMPUSample((long)now);
      storePreSample(sample);

      float aMagG = calcAMagG(sample);
      float gyroDps = calcGyroMagnitudeDps(sample);

      // จำ pattern ที่เป็นสัญญาณก่อน/ขณะล้ม
      if (aMagG <= LOW_G_THRESHOLD) {
        lastLowGTime = now;
      }

      if (gyroDps >= GYRO_THRESHOLD_DPS) {
        lastHighGyroTime = now;
      }

      // หากมีข้อมูลค้างส่งอยู่ จะไม่เริ่ม event ใหม่ เพื่อไม่ให้ eventBuffer ถูกเขียนทับ
      if (!pendingUpload && shouldTriggerFall(aMagG, gyroDps, now)) {
        startFallEvent(now, aMagG, gyroDps);
      }
    } else {
      // หลัง Trigger แล้ว เก็บข้อมูลหลังล้มต่ออีก 1 วินาที
      collectPostEventSample();
    }
  }

  // ตรวจสอบ WiFi ทุก 30 วินาที
  if (now - lastWiFiCheck >= 30000) {
    lastWiFiCheck = now;
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("[WiFi] ขาดการเชื่อมต่อ กำลังพยายามเชื่อมต่อใหม่...");
      WiFi.reconnect();
    }
  }

  // หากมีข้อมูลค้างส่ง ให้ลองส่งใหม่ทุก 5 วินาทีเมื่อ WiFi พร้อม
  if (pendingUpload && WiFi.status() == WL_CONNECTED &&
      now - lastUploadRetry >= 5000) {
    lastUploadRetry = now;
    Serial.println("[Upload] มีข้อมูลค้างส่ง กำลังลองส่งใหม่...");
    trySendEventData();
  }

  // ตรวจสอบและจัดการ WiFi indicator (LED + Buzzer)
  // (ถ้าไม่ได้เล่น melody และไม่ได้ส่งข้อมูล)
  if (!isPlayingMelody && !isSendingData) {
    handleWiFiIndicator(now);
  }

  handleSerial();
}

// ==================== ตั้งค่า WiFi ====================

void setupWiFi() {
  Serial.println("[WiFi] เริ่มต้น WiFiManager...");

  WiFiManager wm;

  // หากต้องการล้างค่า WiFi เดิม ให้เปิดใช้บรรทัดนี้ชั่วคราว
  // wm.resetSettings();

  Serial.println("[WiFi] กำลังเชื่อมต่อ WiFi เดิม หรือเปิด AP เพื่อตั้งค่า...");
  bool res = wm.autoConnect(AP_SSID, AP_PASSWORD);

  if (!res) {
    Serial.println("[WiFi] เชื่อมต่อไม่สำเร็จ รีสตาร์ตบอร์ด...");
    delay(1000);
    ESP.restart();
  }

  Serial.printf("[WiFi] เชื่อมต่อสำเร็จ: %s (IP: %s)\n",
                WiFi.SSID().c_str(),
                WiFi.localIP().toString().c_str());
}

// ==================== ตั้งค่า MPU-6050 ====================

void setupMPU6050() {
  Serial.println("[MPU] กำลังเริ่มต้น MPU-6050...");

  if (!mpu.begin()) {
    Serial.println("[MPU] ไม่พบ MPU-6050! ตรวจสอบสายต่อ SDA/SCL และไฟเลี้ยง");
    while (1) {
      delay(100);
    }
  }

  mpu.setAccelerometerRange(MPU6050_RANGE_16_G); // ±16G
  mpu.setGyroRange(MPU6050_RANGE_500_DEG);       // ±500°/s
  mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);    // Filter 21Hz

  Serial.println("[MPU] เริ่มต้นสำเร็จ (Accel ±16G, Gyro ±500°/s)");
}

// ==================== อ่านค่า MPU-6050 ====================

SensorSample readMPUSample(long timestamp_ms) {
  sensors_event_t a, g, temp;
  mpu.getEvent(&a, &g, &temp);

  SensorSample sample;
  sample.timestamp_ms = timestamp_ms;
  sample.accel_x = a.acceleration.x;
  sample.accel_y = a.acceleration.y;
  sample.accel_z = a.acceleration.z;
  sample.gyro_x = g.gyro.x;
  sample.gyro_y = g.gyro.y;
  sample.gyro_z = g.gyro.z;

  return sample;
}

// ==================== Circular Buffer ก่อนล้ม ====================

void storePreSample(const SensorSample &sample) {
  preBuffer[preWriteIndex] = sample;
  preWriteIndex = (preWriteIndex + 1) % PRE_EVENT_SAMPLES;

  if (preCount < PRE_EVENT_SAMPLES) {
    preCount++;
  }
}

// ==================== คำนวณค่าสำหรับตรวจจับ ====================

float calcAMagG(const SensorSample &sample) {
  float mag = sqrt(sample.accel_x * sample.accel_x +
                   sample.accel_y * sample.accel_y +
                   sample.accel_z * sample.accel_z);

  // Adafruit_MPU6050 ให้ acceleration เป็น m/s^2
  // หาร 9.81 เพื่อแปลงเป็นหน่วย G
  return mag / 9.81;
}

float calcGyroMagnitudeDps(const SensorSample &sample) {
  float gyroRad = sqrt(sample.gyro_x * sample.gyro_x +
                       sample.gyro_y * sample.gyro_y +
                       sample.gyro_z * sample.gyro_z);

  // Adafruit_MPU6050 ให้ gyro เป็น rad/s
  // แปลงเป็น degree/s
  return gyroRad * 180.0 / PI;
}

bool shouldTriggerFall(float aMagG, float gyroDps, unsigned long now) {
  bool impact = (aMagG >= IMPACT_THRESHOLD_G);
  bool hardImpact = (aMagG >= HARD_IMPACT_THRESHOLD_G);

  bool hadLowGRecently =
      (lastLowGTime > 0) && ((now - lastLowGTime) <= FALL_PATTERN_WINDOW_MS);

  bool hadHighGyroRecently =
      (lastHighGyroTime > 0) && ((now - lastHighGyroTime) <= FALL_PATTERN_WINDOW_MS);

  triggerHadLowG = hadLowGRecently;
  triggerHadHighGyro = hadHighGyroRecently;
  triggerHardImpact = hardImpact;

  // เงื่อนไขที่ 1: แรงกระแทกสูงมาก Trigger ได้ทันที
  if (hardImpact) {
    return true;
  }

  // เงื่อนไขที่ 2: แรงกระแทก >= 2.5G ต้องมี low-G หรือ gyro สูงร่วมด้วย
  if (impact && (hadLowGRecently || hadHighGyroRecently)) {
    return true;
  }

  return false;
}

// ==================== เริ่มบันทึกเหตุการณ์ล้ม ====================

void startFallEvent(unsigned long now, float aMagG, float gyroDps) {
  triggerTime = now;
  triggerAMagG = aMagG;
  triggerGyroDps = gyroDps;

  isRecordingEvent = true;
  eventCount = 0;
  postSampleCount = 0;

  // เปิด Buzzer/LED 1 วินาที
  digitalWrite(BUZZER_PIN, HIGH);
  digitalWrite(LED_PIN, HIGH);
  isBuzzing = true;
  buzzerEndTime = now + 1000;

  Serial.println("\n[FALL] ตรวจพบเหตุการณ์ที่เข้าเงื่อนไขการล้ม");
  Serial.printf("       aMag = %.2fG, gyro = %.1f deg/s\n",
                triggerAMagG, triggerGyroDps);
  Serial.printf("       low-G recently: %s, high gyro recently: %s, hard impact: %s\n",
                triggerHadLowG ? "YES" : "NO",
                triggerHadHighGyro ? "YES" : "NO",
                triggerHardImpact ? "YES" : "NO");

  // คัดลอกข้อมูลก่อนล้มจาก preBuffer เข้า eventBuffer
  // เรียงจากเก่าสุด -> ใหม่สุด
  int startIndex =
      (preWriteIndex - preCount + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES;

  for (int i = 0; i < preCount && eventCount < MAX_EVENT_BUFFER; i++) {
    int idx = (startIndex + i) % PRE_EVENT_SAMPLES;
    SensorSample sample = preBuffer[idx];

    // ตั้ง trigger เป็น t = 0
    // ข้อมูลก่อนล้มจะเป็นค่าลบ เช่น -980, -960, ...
    sample.timestamp_ms = sample.timestamp_ms - (long)triggerTime;

    eventBuffer[eventCount++] = sample;
  }

  Serial.printf("       copied pre-event samples: %d\n", eventCount);
  Serial.println("       เริ่มเก็บข้อมูลหลังล้ม...");
}

// ==================== เก็บข้อมูลหลัง Trigger ====================

void collectPostEventSample() {
  unsigned long now = millis();
  unsigned long elapsed = now - triggerTime;

  if (elapsed > POST_EVENT_MS ||
      postSampleCount >= POST_EVENT_SAMPLES ||
      eventCount >= MAX_EVENT_BUFFER) {
    finishFallEvent();
    return;
  }

  SensorSample sample = readMPUSample((long)elapsed);

  if (eventCount < MAX_EVENT_BUFFER) {
    eventBuffer[eventCount++] = sample;
    postSampleCount++;
  }

  if (elapsed >= POST_EVENT_MS ||
      postSampleCount >= POST_EVENT_SAMPLES ||
      eventCount >= MAX_EVENT_BUFFER) {
    finishFallEvent();
  }
}

// ==================== จบเหตุการณ์และส่งข้อมูล ====================

void finishFallEvent() {
  isRecordingEvent = false;
  pendingUpload = true;

  Serial.printf("[FALL] เก็บข้อมูลครบแล้ว: รวม %d ตัวอย่าง "
                "(ก่อนล้มประมาณ %d, หลังล้ม %d)\n",
                eventCount, preCount, postSampleCount);

  trySendEventData();
}

// ==================== ส่งข้อมูลไป Server ====================

bool trySendEventData() {
  if (!pendingUpload) {
    return true;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[Upload] WiFi ไม่เชื่อมต่อ เก็บข้อมูลไว้ก่อน");
    return false;
  }

  if (eventCount <= 0) {
    Serial.println("[Upload] ไม่มีข้อมูลให้ส่ง");
    pendingUpload = false;
    return true;
  }

  Serial.printf("[Upload] กำลังส่ง %d ตัวอย่างไป Server...\n", eventCount);

  // ตั้ง flag และเปิด Buzzer ยาว ระหว่างการส่งข้อมูล
  isSendingData = true;
  digitalWrite(BUZZER_PIN, HIGH);

  int sent = 0;

  for (int start = 0; start < eventCount; start += BATCH_SIZE) {
    int end = min(start + BATCH_SIZE, eventCount);
    int batchLen = end - start;

    DynamicJsonDocument doc(batchLen * 140 + 300);

    // คงรูปแบบเดิมเพื่อให้เข้ากับ API เดิม
    doc["mac_address"] = MAC_ADDRESS;

    JsonArray dataArr = doc.createNestedArray("data");
    for (int i = start; i < end; i++) {
      JsonObject obj = dataArr.createNestedObject();
      obj["t"] = eventBuffer[i].timestamp_ms;
      obj["ax"] = round2(eventBuffer[i].accel_x);
      obj["ay"] = round2(eventBuffer[i].accel_y);
      obj["az"] = round2(eventBuffer[i].accel_z);
      obj["gx"] = round2(eventBuffer[i].gyro_x);
      obj["gy"] = round2(eventBuffer[i].gyro_y);
      obj["gz"] = round2(eventBuffer[i].gyro_z);
    }

    HTTPClient http;
    http.begin(API_URL);
    http.addHeader("Content-Type", "application/json");

    String payload;
    serializeJson(doc, payload);

    int httpCode = http.POST(payload);

    if (httpCode == 200) {
      sent += batchLen;
      Serial.printf("  ✅ Batch %d-%d ส่งสำเร็จ\n", start, end - 1);
    } else {
      Serial.printf("  ❌ Batch %d-%d ล้มเหลว (HTTP %d)\n",
                    start, end - 1, httpCode);
      Serial.println("     " + http.getString());
      http.end();
      Serial.println("[Upload] ส่งไม่ครบ จะเก็บข้อมูลไว้และลองใหม่ภายหลัง");
      
      // ปิด Buzzer และ flag ก่อนกลับ
      digitalWrite(BUZZER_PIN, LOW);
      isSendingData = false;
      return false;
    }

    http.end();
    delay(100);
  }

  Serial.printf("[Upload] ส่งสำเร็จ %d/%d ตัวอย่าง\n", sent, eventCount);
  Serial.println("[System] กลับสู่โหมดเฝ้าระวัง\n");

  // ปิด Buzzer ยาว และ flag
  digitalWrite(BUZZER_PIN, LOW);
  isSendingData = false;

  // เล่นทำนองบูซเซอร์เมื่อส่งข้อมูลสำเร็จ (บีบ-บีบ-บีบ)
  playSuccessMelody();

  pendingUpload = false;
  eventCount = 0;
  postSampleCount = 0;

  return true;
}

// ==================== อ่านคำสั่ง Serial ====================

void handleSerial() {
  if (!Serial.available()) {
    return;
  }

  String cmd = Serial.readStringUntil('\n');
  cmd.trim();

  if (cmd == "status" || cmd == "s") {
    Serial.println("\n========== STATUS ==========");
    Serial.printf("MAC: %s\n", MAC_ADDRESS.c_str());
    Serial.printf("WiFi: %s\n",
                  WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DISCONNECTED");
    Serial.printf("Recording event: %s\n", isRecordingEvent ? "YES" : "NO");
    Serial.printf("Pending upload: %s\n", pendingUpload ? "YES" : "NO");
    Serial.printf("preBuffer: %d/%d\n", preCount, PRE_EVENT_SAMPLES);
    Serial.printf("eventBuffer: %d/%d\n", eventCount, MAX_EVENT_BUFFER);
    Serial.printf("postSampleCount: %d/%d\n", postSampleCount, POST_EVENT_SAMPLES);
    Serial.printf("Threshold: impact %.2fG, hard %.2fG, low-G %.2fG, gyro %.1f dps\n",
                  IMPACT_THRESHOLD_G,
                  HARD_IMPACT_THRESHOLD_G,
                  LOW_G_THRESHOLD,
                  GYRO_THRESHOLD_DPS);
    Serial.println("============================\n");
  } else if (cmd == "send") {
    if (eventCount > 0) {
      pendingUpload = true;
      trySendEventData();
    } else {
      Serial.println("[Serial] ไม่มีข้อมูล eventBuffer ให้ส่ง");
    }
  } else if (cmd == "help" || cmd == "h") {
    Serial.println("\n========== COMMANDS ==========");
    Serial.println("status / s  : แสดงสถานะระบบ");
    Serial.println("send        : ส่งข้อมูล eventBuffer ค้างอยู่");
    Serial.println("help / h    : แสดงคำสั่งทั้งหมด");
    Serial.println("==============================\n");
  }
}

// ==================== Utility ====================

float round2(float val) {
  return round(val * 100.0f) / 100.0f;
}

// ==================== Buzzer Melody ====================

void playSuccessMelody() {
  // ทำนองชี้ว่าส่งข้อมูลสำเร็จ: บีบ-บีบ-บีบ
  isPlayingMelody = true; // หยุด WiFi indicator ชั่วคราว
  
  for (int i = 0; i < 3; i++) {
    digitalWrite(BUZZER_PIN, LOW);
    delay(150);
    digitalWrite(BUZZER_PIN, HIGH);
    delay(200);
    digitalWrite(BUZZER_PIN, LOW);
    delay(150);
  }
  
  isPlayingMelody = false; // อนุญาต WiFi indicator ให้ทำงานต่อ
}

// ==================== WiFi Indicator (LED + Buzzer) ====================

void handleWiFiIndicator(unsigned long now) {
  bool wifiConnected = (WiFi.status() == WL_CONNECTED);

  if (wifiConnected) {
    // WiFi เชื่อมต่ออยู่: LED ติดตลอด
    digitalWrite(LED_PIN, HIGH);
    // ปิด Buzzer ถ้าไม่ได้เล่น melody
    if (!isPlayingMelody) {
      digitalWrite(BUZZER_PIN, LOW);
    }
  } else {
    // WiFi ขาดการเชื่อมต่อ: LED + Buzzer กระพริบเป็นระยะ (ระยะกระพริบ 500ms)
    if (now - lastWiFiBlinkTime >= 500) {
      lastWiFiBlinkTime = now;
      
      // สลับสถานะ LED และ Buzzer
      int currentState = digitalRead(LED_PIN);
      int newState = (currentState == HIGH) ? LOW : HIGH;
      
      digitalWrite(LED_PIN, newState);
      digitalWrite(BUZZER_PIN, newState);
    }
  }

  lastWiFiConnected = wifiConnected;
}
