/*
 * ESP32 เก็บข้อมูลเซ็นเซอร์ MPU-6050
 * CollectData_HardwareV2 — Fall Detection Data Logger (TinyML Edition)
 *
 * คุณสมบัติ:
 * - อ่านเซ็นเซอร์ MPU-6050 ต่อเนื่อง 50Hz (ทุก 20ms)
 * - เก็บข้อมูลก่อนล้มด้วย Circular Buffer (Pre-event Buffer 1 วินาที)
 * - ใช้ TinyML (Native MLP Forward Pass 18 Features) ในการจำแนกการล้ม
 * - มี Fallback Threshold Heuristic เสริมในกรณีฉุกเฉิน
 * - เมื่อโมเดลตรวจพบการล้ม (Fall Confidence >= เกณฑ์) จะเก็บข้อมูล Post-event ต่ออีก 1 วินาที
 * - ส่งข้อมูลทั้ง Pre-event และ Post-event ไปยัง PHP Server (CollectData_Web)
 * - มี WiFiManager สำหรับตั้งค่า WiFi สะดวกผ่านมือถือ
 * - มี LED และ Buzzer Indicator แสดงสถานะการทำงาน/การเชื่อมต่อ/การส่งข้อมูล
 */

#include "fall_detection.h"
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
FallDetector fallDetector;

// ==================== การตั้งค่า Sampling / Buffer ====================

const int SAMPLE_RATE_MS = 20; // 50Hz

// เก็บข้อมูลก่อนล้มและหลังล้มอย่างละ 1 วินาที (50 ตัวอย่าง)
const int PRE_EVENT_MS = 1000;
const int POST_EVENT_MS = 1000;

const int PRE_EVENT_SAMPLES = PRE_EVENT_MS / SAMPLE_RATE_MS;   // 50 samples
const int POST_EVENT_SAMPLES = POST_EVENT_MS / SAMPLE_RATE_MS; // 50 samples

// Buffer หลักสำหรับ 1 เหตุการณ์: ก่อนล้ม + หลังล้ม + เผื่อ timing เล็กน้อย
const int MAX_EVENT_BUFFER = PRE_EVENT_SAMPLES + POST_EVENT_SAMPLES + 10;

// ส่งข้อมูลเป็น batch
const int BATCH_SIZE = 50;

// Cooldown ป้องกันการ Trigger ซ้ำซ้อนติดๆ กัน
const unsigned long FALL_COOLDOWN_MS = 3000;

// ==================== โครงสร้างข้อมูล ====================

struct SensorSample {
  long timestamp_ms;
  float accel_x, accel_y, accel_z;
  float gyro_x, gyro_y, gyro_z;
};

// Circular Buffer สำหรับข้อมูลก่อนล้ม (Pre-event)
SensorSample preBuffer[PRE_EVENT_SAMPLES];
int preWriteIndex = 0;
int preCount = 0;

// Array สำหรับแปลงข้อมูลส่งเข้า Feature Extraction ของ TinyML
// (50 ตัวอย่าง * 6 แกน = 300 floats)
float mlInputBuffer[PRE_EVENT_SAMPLES * 6];

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
unsigned long lastFallTriggerTime = 0;

// Debug info ตอน Trigger
float triggerConfidence = 0.0f;
float triggerAMagG = 0.0f;
const char* detectedFallType = "fall_general";
int uploadRetryCount = 0;

// สถานะสำหรับ WiFi indicator (LED + Buzzer)
bool lastWiFiConnected = false;
unsigned long lastWiFiBlinkTime = 0;
bool isPlayingMelody = false; // flag เพื่อหยุด WiFi indicator ขณะเล่น melody
bool isSendingData = false;   // flag เพื่อหยุด WiFi indicator ขณะส่งข้อมูล

// ==================== ประกาศฟังก์ชัน ====================

void setupWiFi();
void setupMPU6050();

SensorSample readMPUSample(long timestamp_ms);
void storePreSample(const SensorSample &sample);

float calcAMagG(const SensorSample &sample);
bool checkTinyMLFall(float &confidence);

void startFallEvent(unsigned long now, float confidence, float aMagG);
void collectPostEventSample();
void finishFallEvent();
void clearBuffersAfterEvent();

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
  Serial.println("  CollectData Hardware V2 — TinyML Edition");
  Serial.println("  Pre-event + Post-event Buffer + TinyML");
  Serial.println("==========================================");

  setupWiFi();
  setupMPU6050();

  if (!fallDetector.begin()) {
    Serial.println("[Error] เริ่มต้น TinyML FallDetector ไม่สำเร็จ!");
  } else {
    Serial.println("[TinyML] FallDetector เริ่มต้นสำเร็จ พร้อมใช้งาน");
  }

  MAC_ADDRESS = WiFi.macAddress();
  Serial.printf("[อุปกรณ์] MAC Address: %s\n", MAC_ADDRESS.c_str());

  Serial.println("\n[พร้อม] ระบบเริ่มทำงาน!");
  Serial.println("[หลักการ]");
  Serial.println("  - อ่าน MPU-6050 ทุก 20 ms (50Hz)");
  Serial.println("  - เก็บข้อมูลก่อนล้มใน Circular Buffer (1 วินาทีล่าสุด)");
  Serial.println("  - ใช้โมเดล TinyML (MLP) วิเคราะห์ Window 50 ตัวอย่าง");
  Serial.println("  - เมื่อตรวจพบการล้ม จะเก็บข้อมูลหลังล้มต่ออีก 1 วินาทีและส่ง Server");
}

// ==================== Loop หลัก ====================

void loop() {
  unsigned long now = millis();

  // ปิด Buzzer/LED เมื่อครบเวลาเตือนชั่วคราว
  if (isBuzzing && now >= buzzerEndTime) {
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
    isBuzzing = false;
  }

  // อ่านเซ็นเซอร์ตามรอบเวลา 20 ms
  if (now - lastSampleTime >= SAMPLE_RATE_MS) {
    lastSampleTime = now;

    if (!isRecordingEvent) {
      // 1. อ่านค่าและเก็บลง preBuffer ตลอดเวลา
      SensorSample sample = readMPUSample((long)now);
      storePreSample(sample);

      // 2. ถ้า Buffer เต็ม 50 ตัวอย่าง และไม่ได้อยู่ในช่วงคูลดาวน์หรือรออัปโหลด
      if (!pendingUpload && preCount >= PRE_EVENT_SAMPLES && (now - lastFallTriggerTime >= FALL_COOLDOWN_MS)) {
        float confidence = 0.0f;
        if (checkTinyMLFall(confidence)) {
          float aMagG = calcAMagG(sample);
          startFallEvent(now, confidence, aMagG);
        }
      }
    } else {
      // 3. หลัง Trigger แล้ว เก็บข้อมูลหลังล้มต่ออีก 1 วินาที (Post-event)
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
  if (pendingUpload && WiFi.status() == WL_CONNECTED && (now - lastUploadRetry >= 5000)) {
    lastUploadRetry = now;
    Serial.println("[Upload] มีข้อมูลค้างส่ง กำลังลองส่งใหม่...");
    trySendEventData();
  }

  // ตรวจสอบและจัดการ WiFi indicator (LED + Buzzer)
  if (!isPlayingMelody && !isSendingData) {
    handleWiFiIndicator(now);
  }

  handleSerial();
}

// ==================== ตั้งค่า WiFi ====================

void setupWiFi() {
  Serial.println("[WiFi] เริ่มต้น WiFiManager...");

  WiFiManager wm;

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

// ==================== คำนวณค่าและรัน TinyML ====================

float calcAMagG(const SensorSample &sample) {
  float mag = sqrt(sample.accel_x * sample.accel_x +
                   sample.accel_y * sample.accel_y +
                   sample.accel_z * sample.accel_z);
  return mag / 9.81f;
}

bool checkTinyMLFall(float &confidence) {
  // เรียงข้อมูลจาก preBuffer (เก่าสุด -> ใหม่สุด) เข้า mlInputBuffer
  int startIndex = (preWriteIndex - preCount + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES;

  for (int i = 0; i < PRE_EVENT_SAMPLES; i++) {
    int idx = (startIndex + i) % PRE_EVENT_SAMPLES;
    mlInputBuffer[i * 6 + 0] = preBuffer[idx].accel_x;
    mlInputBuffer[i * 6 + 1] = preBuffer[idx].accel_y;
    mlInputBuffer[i * 6 + 2] = preBuffer[idx].accel_z;
    mlInputBuffer[i * 6 + 3] = preBuffer[idx].gyro_x;
    mlInputBuffer[i * 6 + 4] = preBuffer[idx].gyro_y;
    mlInputBuffer[i * 6 + 5] = preBuffer[idx].gyro_z;
  }

  // ส่งข้อมูลขนาด 300 floats (50 ตัวอย่าง * 6 แกน) ให้ TinyML ทำนาย
  confidence = fallDetector.predict(mlInputBuffer, PRE_EVENT_SAMPLES * 6);

  // ตรวจสอบว่า Confidence ผ่านเกณฑ์ขั้นต่ำหรือไม่ (default: 0.75)
  if (confidence >= fallDetector.getMinConfidence()) {
    return true;
  }

  return false;
}

// ==================== เคลียร์บัฟเฟอร์หลังจบเหตุการณ์ ====================

void clearBuffersAfterEvent() {
  pendingUpload = false;
  eventCount = 0;
  postSampleCount = 0;

  // เคลียร์ preBuffer ทั้งหมด เพื่อไม่ให้ TinyML วิเคราะห์ซ้ำบนข้อมูลการล้มรอบเดิม
  preCount = 0;
  preWriteIndex = 0;
  memset(preBuffer, 0, sizeof(preBuffer));
  memset(eventBuffer, 0, sizeof(eventBuffer));

  // รีเซ็ตตัวนับการลองส่งใหม่
  uploadRetryCount = 0;

  // อัปเดตเวลาคูลดาวน์ให้เริ่มนับหลังจากจบการอัปโหลดและเคลียร์ข้อมูลเรียบร้อย
  lastFallTriggerTime = millis();
  fallDetector.reset();

  Serial.println("[System] เคลียร์บัฟเฟอร์ข้อมูลเดิมออกเรียบร้อย พร้อมเริ่มตรวจจับรอบใหม่\n");
}

// ==================== เริ่มบันทึกเหตุการณ์ล้ม ====================

void startFallEvent(unsigned long now, float confidence, float aMagG) {
  triggerTime = now;
  lastFallTriggerTime = now;
  triggerConfidence = confidence;
  triggerAMagG = aMagG;

  // ระบุประเภทและทิศทางการล้ม
  detectedFallType = fallDetector.identifyFallType(mlInputBuffer, PRE_EVENT_SAMPLES * 6);

  isRecordingEvent = true;
  eventCount = 0;
  postSampleCount = 0;

  // เปิด Buzzer/LED 1 วินาที
  digitalWrite(BUZZER_PIN, HIGH);
  digitalWrite(LED_PIN, HIGH);
  isBuzzing = true;
  buzzerEndTime = now + 1000;

  Serial.println("\n[FALL] 🚨 ตรวจพบการล้มด้วย TinyML!");
  Serial.printf("       ความมั่นใจ: %.2f (เกณฑ์ >= %.2f), ความเร่งสูงสุด: %.2fG\n",
                triggerConfidence, fallDetector.getMinConfidence(), triggerAMagG);
  Serial.printf("       รูปแบบการล้ม: %s [%s]\n",
                fallDetector.getFallTypeNameThai(detectedFallType), detectedFallType);

  // คัดลอกข้อมูลก่อนล้มจาก preBuffer เข้า eventBuffer
  int startIndex =
      (preWriteIndex - preCount + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES;

  for (int i = 0; i < preCount && eventCount < MAX_EVENT_BUFFER; i++) {
    int idx = (startIndex + i) % PRE_EVENT_SAMPLES;
    SensorSample sample = preBuffer[idx];

    // ตั้งเวลา trigger เป็น t = 0 (ข้อมูลก่อนล้มจะเป็นค่าลบ)
    sample.timestamp_ms = sample.timestamp_ms - (long)triggerTime;
    eventBuffer[eventCount++] = sample;
  }

  Serial.printf("       คัดลอก Pre-event Samples: %d ตัวอย่าง\n", eventCount);
  Serial.println("       กำลังเก็บข้อมูล Post-event หลังล้มต่ออีก 1 วินาที...");
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

  Serial.printf("[FALL] เก็บข้อมูลครบถ้วน: รวมทั้งหมด %d ตัวอย่าง "
                "(ก่อนล้ม %d, หลังล้ม %d)\n",
                eventCount, preCount, postSampleCount);

  trySendEventData();
}

// ==================== ส่งข้อมูลไป Server ====================

bool trySendEventData() {
  if (!pendingUpload) {
    return true;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[Upload] WiFi ไม่เชื่อมต่อ เก็บข้อมูลไว้รอส่งใหม่");
    return false;
  }

  if (eventCount <= 0) {
    Serial.println("[Upload] ไม่มีข้อมูลให้ส่ง");
    clearBuffersAfterEvent();
    return true;
  }

  Serial.printf("[Upload] กำลังส่ง %d ตัวอย่างไป Server (%s)...\n", eventCount, API_URL);

  // ตั้ง flag และเปิด Buzzer ระหว่างส่งข้อมูล
  isSendingData = true;
  digitalWrite(BUZZER_PIN, HIGH);

  int sent = 0;

  for (int start = 0; start < eventCount; start += BATCH_SIZE) {
    int end = min(start + BATCH_SIZE, eventCount);
    int batchLen = end - start;

    DynamicJsonDocument doc(batchLen * 140 + 300);

    doc["mac_address"] = MAC_ADDRESS;
    doc["fall_type"] = detectedFallType;

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
    } else if (httpCode == 404 || httpCode == 400) {
      // กรณี Web สิ้นสุดการบันทึกแล้ว หรือไม่มี Active Session
      Serial.printf("  ⚠️ Server แจ้ง (HTTP %d): สิ้นสุดการบันทึกบนเว็บแล้วหรือไม่พบ Session\n", httpCode);
      Serial.println("[Upload] เคลียร์ข้อมูลออกเพื่อป้องกันการส่งซ้ำ");
      http.end();
      digitalWrite(BUZZER_PIN, LOW);
      isSendingData = false;
      clearBuffersAfterEvent();
      return true;
    } else {
      Serial.printf("  ❌ Batch %d-%d ล้มเหลว (HTTP %d)\n",
                    start, end - 1, httpCode);
      Serial.println("     " + http.getString());
      http.end();

      digitalWrite(BUZZER_PIN, LOW);
      isSendingData = false;

      uploadRetryCount++;
      if (uploadRetryCount >= 3) {
        Serial.println("[Upload] ⚠️ พยายามส่งครบ 3 ครั้งแล้วไม่สำเร็จ เคลียร์ข้อมูลออกเพื่อไม่ให้ค้างส่ง");
        clearBuffersAfterEvent();
        return true;
      }

      Serial.println("[Upload] จะลองส่งใหม่ในรอบถัดไป...");
      return false;
    }

    http.end();
    delay(100);
  }

  Serial.printf("[Upload] 🎉 ส่งสำเร็จครบ %d/%d ตัวอย่าง\n", sent, eventCount);

  digitalWrite(BUZZER_PIN, LOW);
  isSendingData = false;

  playSuccessMelody();
  clearBuffersAfterEvent();

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
    Serial.println("\n========== STATUS (V2 TinyML) ==========");
    Serial.printf("MAC: %s\n", MAC_ADDRESS.c_str());
    Serial.printf("WiFi: %s\n",
                  WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DISCONNECTED");
    Serial.printf("ML Model Active: %s\n", fallDetector.isMLModelActive() ? "YES" : "NO");
    Serial.printf("Min Confidence: %.2f\n", fallDetector.getMinConfidence());
    Serial.printf("Recording event: %s\n", isRecordingEvent ? "YES" : "NO");
    Serial.printf("Pending upload: %s\n", pendingUpload ? "YES" : "NO");
    Serial.printf("preBuffer: %d/%d\n", preCount, PRE_EVENT_SAMPLES);
    Serial.printf("eventBuffer: %d/%d\n", eventCount, MAX_EVENT_BUFFER);
    Serial.println("=========================================\n");
  } else if (cmd == "send") {
    if (eventCount > 0) {
      pendingUpload = true;
      trySendEventData();
    } else {
      Serial.println("[Serial] ไม่มีข้อมูล eventBuffer ให้ส่ง");
    }
  } else if (cmd == "help" || cmd == "h") {
    Serial.println("\n========== COMMANDS ==========");
    Serial.println("status / s  : แสดงสถานะระบบและ TinyML");
    Serial.println("send        : สั่งส่งข้อมูล eventBuffer ที่ค้างอยู่");
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
  isPlayingMelody = true;

  for (int i = 0; i < 3; i++) {
    digitalWrite(BUZZER_PIN, LOW);
    delay(150);
    digitalWrite(BUZZER_PIN, HIGH);
    delay(200);
    digitalWrite(BUZZER_PIN, LOW);
    delay(150);
  }

  isPlayingMelody = false;
}

// ==================== WiFi Indicator (LED + Buzzer) ====================

void handleWiFiIndicator(unsigned long now) {
  bool wifiConnected = (WiFi.status() == WL_CONNECTED);

  if (wifiConnected) {
    digitalWrite(LED_PIN, HIGH);
    if (!isPlayingMelody) {
      digitalWrite(BUZZER_PIN, LOW);
    }
  } else {
    if (now - lastWiFiBlinkTime >= 500) {
      lastWiFiBlinkTime = now;
      int currentState = digitalRead(LED_PIN);
      int newState = (currentState == HIGH) ? LOW : HIGH;

      digitalWrite(LED_PIN, newState);
      digitalWrite(BUZZER_PIN, newState);
    }
  }

  lastWiFiConnected = wifiConnected;
}
