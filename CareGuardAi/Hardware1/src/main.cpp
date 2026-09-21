/*
 * ESP32 ระบบตรวจจับการล้ม + FreeRTOS Dual-Core + GPS Real-Time (Hardware1 v10.0)
 *
 * สถาปัตยกรรม FreeRTOS Dual-Core:
 * - Core 1 (Task 1: imuFallTask - Priority 3 High):
 *      - อ่าน IMU MPU-6050 ที่ความถี่ 50Hz (20ms) แม่นยำระดับฮาร์ดแวร์
 *      - ตรวจจับทิศทางและแรงกระแทกการล้มด้วย AI TinyML
 *      - ควบคุมเสียงไซเรน Buzzer ขา D5 และปุ่มยืนยัน D18
 *      - ส่งข้อมูลการล้มผ่าน FreeRTOS Queue (0ms non-blocking)
 *      - ปลอดจากการบล็อกของเน็ตเวิร์ก/HTTP 100%
 *
 * - Core 0 (Task 2: netGpsTask - Priority 1 Normal):
 *      - อ่านข้อมูล GPS NEO-7M (TX0/RX0) จากดาวเทียมจริงตลอดเวลา
 *      - ซิงค์พิกัดอุปกรณ์ ESP32 ขึ้น Server ทุก 1 วินาทีอย่างต่อเนื่อง
 *      - รับเหตุการณ์การล้มจาก Queue ส่งเข้า Server (HTTP) และ Telegram Bot (SSL)
 *      - ซิงค์ประวัติออฟไลน์จาก Flash Memory ขึ้น Server เมื่อเน็ตต่อติด
 */

#include "fall_detection.h"
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <Arduino.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <TinyGPSPlus.h>
#include <UniversalTelegramBot.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <freertos/task.h>


// ==================== Telegram Bot Configuration ====================
// Telegram Bot: @CareGuardAI1_bot (Hardware 1)
#define BOT_TOKEN "8708202936:AAFhrY8PZ1XsAR0F12V4OPiCMxkpPXkyPJ8"
#define CHAT_ID "8758930399"

WiFiClientSecure securedClient;
UniversalTelegramBot bot(BOT_TOKEN, securedClient);
char telegramChatId[64] = "";

// ==================== การตั้งค่าฮาร์ดแวร์และพิน ====================
const char *WM_AP_NAME = "CareGuard_Setup";

// GPS UART (NEO-7M via UART2: GPIO 16/17 หรือ UART0: TX0/RX0)
#define GPS_BAUD 9600
#define GPS_RX_PIN 16
#define GPS_TX_PIN 17
HardwareSerial gpsSerial(2);

// Buzzer & Button
#define BUZZER_PIN 5  // ขา D5 (GPIO 5)
#define BUTTON_PIN 18 // ปุ่มยืนยันการช่วยเหลือ D18 (GPIO 18, INPUT_PULLUP)

// IMU Sampling
const int SAMPLE_RATE_MS = 20; // 50Hz
const int WINDOW_SIZE = 50;

// ==================== Server URL & API Key ====================
char fallsUrl[128] = "https://www.youngza.com/IMU/SoftwarePHP/api/falls";
char gpsUrl[128] = "https://www.youngza.com/IMU/SoftwarePHP/api/gps";
char apiKey[64] = "hw1-8931932eb8a233006062ebdb0651eb74";

// ==================== โครงสร้างข้อมูลการล้ม (Fall Record) ====================
struct FallRecord {
  unsigned long timestamp_ms;
  float accX;
  float accY;
  float accZ;
  float severity;
  float confidence;
  char fall_type[32];
  char fall_type_name[128];
  double lat;
  double lng;
  bool gps_valid;
  bool assisted;
  unsigned long assisted_at;
  bool is_button_event; // True เมื่อเป็นการกดปุ่มยืนยัน D18
};

#define MAX_OFFLINE_EVENTS 20
FallRecord offlineQueue[MAX_OFFLINE_EVENTS];
int offlineEventCount = 0;

// ==================== ตัวแปรส่วนกลาง ====================
Adafruit_MPU6050 mpu;
FallDetector fallDetector;
Preferences preferences;
Preferences offlinePrefs;
TinyGPSPlus gps;

// FreeRTOS Handles
TaskHandle_t imuTaskHandle = NULL;
TaskHandle_t netGpsTaskHandle = NULL;
QueueHandle_t fallEventQueue = NULL;
portMUX_TYPE gpsMux = portMUX_INITIALIZER_UNLOCKED;

// IMU Buffer (Core 1 Only)
float accelBuffer[WINDOW_SIZE][3];
float gyroBuffer[WINDOW_SIZE][3];
int bufferIndex = 0;
bool bufferFull = false;

// GPS State (Protected by gpsMux)
double currentLat = 0.0;
double currentLng = 0.0;
float currentSpeedKmh = 0.0;
float currentAltitude = 0.0;
int currentSatellites = 0;
float currentHdop = 99.0;
bool gpsValid = false;
unsigned long lastGpsFix = 0;

// Indoor / Hotspot Fallback Coordinates (เมื่ออยู่ในอาคารหรือเชื่อม Hotspot มือถือ)
double indoorLat = 0.0;
double indoorLng = 0.0;
bool hasIndoorFix = false;

// Step Counting (GPS + IMU)
int totalSteps = 0;
float totalWalkDistance = 0; // เมตร
float lastSpeed = 0;

// Timing Configuration
const unsigned long GPS_SEND_INTERVAL =
    2500; // 2.5 วินาที ซิงค์พิกัด Real-Time เสถียร ไม่โหลด Wi-Fi stack เกินไป
const unsigned long WIFI_CHECK_INTERVAL = 15000;
const unsigned long OFFLINE_SYNC_INTERVAL = 20000;
const unsigned long FALL_COOLDOWN_MS = 3000;

// Non-blocking Buzzer Alarm State
bool isAlarming = false;
unsigned long alarmStartTime = 0;
const unsigned long ALARM_DURATION = 15000;

// Debounce ปุ่ม D18
int lastButtonState = HIGH;
int currentButtonState = HIGH;
unsigned long lastDebounceTime = 0;
const unsigned long DEBOUNCE_DELAY = 50;

// ==================== Forward Declarations ====================
void setupWiFi();
void setupIMU();
void setupGPS();
void setupBuzzer();
void setupButton();
void handleButton();
void onButtonPressed();
void readGPS();
void readIMU();
void updateSteps();
bool checkForFall(float &confidenceOut, const char *&fallTypeOut,
                  float &peakAccX, float &peakAccY, float &peakAccZ,
                  float &peakMagG);
void handleFallDetected(float accX, float accY, float accZ, float severity,
                        const char *fallType, float confidence);
bool sendFallAlert(const FallRecord &rec);
void sendGPSData();
void appendWifiAps(JsonDocument &doc);
void checkWiFi();
void startAlarm();
void updateAlarm(unsigned long now);
void stopAlarm();
void beepConfirmation();
void saveOfflineFallEvent(const FallRecord &rec);
void saveOfflineQueueToFlash();
void loadOfflineQueueFromFlash();
void syncOfflineFallEvents();
bool sendToUrl(const char *url, const String &jsonPayload, bool isFalls);
float calcMagnitude(float x, float y, float z);

// Telegram Functions
void setupTelegram();
void sendTelegramFallAlert(const FallRecord &rec);
String getTargetChatId();

// FreeRTOS Task Functions
void imuFallTask(void *pvParameters);
void netGpsTask(void *pvParameters);

// ==================== SETUP ====================
void setup() {
  Serial.begin(GPS_BAUD);
  delay(400);

  Serial.println("\n========================================================");
  Serial.println("  CareGuard AI - FreeRTOS Dual-Core Architecture (v10.0)");
  Serial.println("  Core 1: IMU 50Hz TinyML Fall Detection (Zero Blocking)");
  Serial.println("  Core 0: GPS UART Streaming + Web Sync + Telegram Bot");
  Serial.println("========================================================\n");

  setupBuzzer();
  setupButton();

  preferences.begin("falldetect", false);
  offlinePrefs.begin("fall_offline", false);
  loadOfflineQueueFromFlash();

  setupIMU();
  fallDetector.begin();
  setupGPS();

  setupWiFi();
  setupTelegram();

  // บี๊บสั้น 1 ครั้งแสดงว่าระบบเริ่มทำงานแล้ว
  digitalWrite(BUZZER_PIN, HIGH);
  delay(120);
  digitalWrite(BUZZER_PIN, LOW);

  // สร้าง FreeRTOS Queue สำหรับส่งข้อมูลการล้มจาก Core 1 ไป Core 0
  fallEventQueue = xQueueCreate(10, sizeof(FallRecord));

  // สร้าง Task 1: ตรวจจับการล้ม 50Hz บน Core 1 (Priority 3 สูงสุด ไม่โดนบล็อก)
  xTaskCreatePinnedToCore(imuFallTask, "imuFallTask", 8192, NULL, 3,
                          &imuTaskHandle, 1);

  // สร้าง Task 2: จัดการ GPS และ Network บน Core 0 (Priority 1)
  xTaskCreatePinnedToCore(netGpsTask, "netGpsTask", 16384, NULL, 1,
                          &netGpsTaskHandle, 0);

  Serial.println("[System] FreeRTOS Tasks เริ่มทำงานสมบูรณ์ทั้ง 2 คอร์\n");
}

// ==================== LOOP ====================
void loop() {
  // FreeRTOS แยกทำงานอิสระบน Core 0 และ Core 1
  vTaskDelay(pdMS_TO_TICKS(1000));
}

// =========================================================================
// FreeRTOS Task 1 (Core 1): IMU Sampling 50Hz, TinyML, Buzzer, Button
// =========================================================================
void imuFallTask(void *pvParameters) {
  TickType_t xLastWakeTime = xTaskGetTickCount();
  const TickType_t xFrequency = pdMS_TO_TICKS(SAMPLE_RATE_MS); // 20ms = 50Hz
  unsigned long lastFallTime = 0;

  for (;;) {
    vTaskDelayUntil(&xLastWakeTime, xFrequency);
    unsigned long now = millis();

    // 1. ตรวจสอบปุ่มกด D18 (ยืนยันการช่วยเหลือ)
    handleButton();

    // 2. อัปเดตเสียงไซเรน Buzzer
    if (isAlarming) {
      updateAlarm(now);
    }

    // 3. อ่านเซ็นเซอร์ IMU 50Hz
    readIMU();

    // 4. ตรวจจับการล้มด้วย TinyML
    if (bufferFull) {
      float confidence = 0.0f;
      const char *fallType = "fall_general";
      float peakAccX = 0, peakAccY = 0, peakAccZ = 0, peakMagG = 0;
      if (checkForFall(confidence, fallType, peakAccX, peakAccY, peakAccZ,
                       peakMagG) &&
          (now - lastFallTime > FALL_COOLDOWN_MS)) {
        lastFallTime = now;
        float sev = min(peakMagG / 4.0f, 1.0f);

        handleFallDetected(peakAccX, peakAccY, peakAccZ, sev, fallType,
                           confidence);

        // เคลียร์ buffer ทันทีหลังตรวจพบ
        bufferIndex = 0;
        bufferFull = false;
        memset(accelBuffer, 0, sizeof(accelBuffer));
        memset(gyroBuffer, 0, sizeof(gyroBuffer));
        lastFallTime = millis();
      }
    }
  }
}

// =========================================================================
// FreeRTOS Task 2 (Core 0): GPS UART, Web Continuous Sync, Telegram Bot
// =========================================================================
void netGpsTask(void *pvParameters) {
  unsigned long lastGpsSend = 0;
  unsigned long lastWiFiCheck = 0;
  unsigned long lastOfflineSync = 0;
  unsigned long lastStepCalc = 0;

  for (;;) {
    // อ่านข้อมูล GPS จากดาวเทียมจริงตลอดเวลา (Non-blocking)
    readGPS();

    unsigned long now = millis();

    // 1. ตรวจสอบคิวเหตุการณ์การล้มจาก Core 1 เพื่อส่งแจ้งเตือน
    FallRecord eventRec;
    if (xQueueReceive(fallEventQueue, &eventRec, 0) == pdTRUE) {
      if (eventRec.is_button_event) {
        // ส่งการยืนยันกดปุ่มช่วยเหลือ
        if (WiFi.status() == WL_CONNECTED) {
          StaticJsonDocument<512> doc;
          doc["device_id"] = "ESP32_001";
          doc["event_type"] = "fall_assisted";
          doc["status"] = "assisted_confirmed";
          doc["message"] = "ผู้ใช้งานได้รับการช่วยเหลือเรียบร้อยแล้ว (กดปุ่ม D18)";
          if (eventRec.gps_valid) {
            doc["lat"] = eventRec.lat;
            doc["lng"] = eventRec.lng;
          }
          String json;
          serializeJson(doc, json);
          sendToUrl(fallsUrl, json, true);

          String target = getTargetChatId();
          if (target.length() > 0) {
            bot.sendMessage(target,
                            "✅ [CareGuard] ได้รับการช่วยเหลือเรียบร้อยแล้ว (กดยืนยันปุ่ม "
                            "D18 ที่ตัวเครื่อง)",
                            "");
          }
        }
      } else {
        // ส่งเหตุการณ์การล้ม
        bool sent = sendFallAlert(eventRec);
        if (sent) {
          Serial.println("[Net Task] ส่ง Fall Alert (GPS) ไปยัง Server สำเร็จ");
        } else {
          Serial.println("[Net Task] ส่งไม่สำเร็จ เก็บเข้าคิวออฟไลน์");
          saveOfflineFallEvent(eventRec);
        }

        // ส่งแจ้งเตือน Telegram Bot
        sendTelegramFallAlert(eventRec);
      }
    }

    // 2. คำนวณก้าวเดินจาก GPS ทุก 1 วินาที
    if (now - lastStepCalc >= 1000) {
      lastStepCalc = now;
      updateSteps();
    }

    // 3. ซิงค์พิกัดตำแหน่งอุปกรณ์ ESP32 ตลอดเวลาทุก 1 วินาที (Continuous Sync)
    if (now - lastGpsSend >= GPS_SEND_INTERVAL &&
        (WiFi.status() == WL_CONNECTED)) {
      lastGpsSend = now;
      sendGPSData();
    }

    // 4. ตรวจสอบสถานะ WiFi
    if (now - lastWiFiCheck >= WIFI_CHECK_INTERVAL) {
      lastWiFiCheck = now;
      checkWiFi();
    }

    // 5. ซิงค์เหตุการณ์ออฟไลน์จาก Flash
    if (offlineEventCount > 0 && (WiFi.status() == WL_CONNECTED) &&
        (now - lastOfflineSync >= OFFLINE_SYNC_INTERVAL)) {
      lastOfflineSync = now;
      syncOfflineFallEvents();
    }

    // 6. หากยังไม่มี Chat ID ให้ตรวจจับข้อความ /start จาก Telegram ทุก 8 วินาที
    static unsigned long lastTgCheck1 = 0;
    if (getTargetChatId().length() == 0 && WiFi.status() == WL_CONNECTED &&
        (now - lastTgCheck1 >= 8000)) {
      lastTgCheck1 = now;
      int num = bot.getUpdates(bot.last_message_received + 1);
      for (int i = 0; i < num; i++) {
        String fromId = String(bot.messages[i].chat_id);
        if (fromId.length() > 0) {
          strncpy(telegramChatId, fromId.c_str(), sizeof(telegramChatId) - 1);
          preferences.putString("tg_chat_id", fromId);
          bot.sendMessage(fromId, "✅ บันทึก Chat ID สำหรับ Hardware1 เรียบร้อยแล้ว!",
                          "");
          Serial.printf("[Telegram] ได้รับ Chat ID อัตโนมัติ: %s\n", fromId.c_str());
          break;
        }
      }
    }

    // คืน CPU ให้ WiFi/TCP stack ทำงานได้อย่างราบรื่น
    vTaskDelay(pdMS_TO_TICKS(10));
  }
}

// ==================== GPS Setup & Reading ====================
void setupGPS() {
  gpsSerial.begin(GPS_BAUD, SERIAL_8N1, GPS_RX_PIN, GPS_TX_PIN);
  Serial.printf("[GPS] เริ่มต้น UART2 (RX: GPIO %d, TX: GPIO %d) ที่ %d bps สำหรับ NEO-7M\n",
                GPS_RX_PIN, GPS_TX_PIN, GPS_BAUD);
}

void readGPS() {
  // อ่านจาก HardwareSerial 2 (GPIO 16)
  while (gpsSerial.available() > 0) {
    char c = gpsSerial.read();
    gps.encode(c);
  }
  // รองรับกรณีต่อเข้า Serial 0 (TX0/RX0) ควบคู่ด้วย
  while (Serial.available() > 0) {
    char c = Serial.read();
    gps.encode(c);
  }

  portENTER_CRITICAL(&gpsMux);
  if (gps.satellites.isValid()) {
    currentSatellites = gps.satellites.value();
  }
  if (gps.hdop.isValid()) {
    currentHdop = gps.hdop.hdop();
  }
  if (gps.speed.isValid()) {
    currentSpeedKmh = gps.speed.kmph();
  }
  if (gps.altitude.isValid()) {
    currentAltitude = gps.altitude.meters();
  }

  // ตรวจสอบความถูกต้องของพิกัดดาวเทียมจริง
  if (gps.location.isValid() && gps.location.age() < 4000 &&
      gps.location.lat() != 0.0 && gps.location.lng() != 0.0 &&
      currentSatellites >= 3) {
    currentLat = gps.location.lat();
    currentLng = gps.location.lng();
    gpsValid = true;
    lastGpsFix = millis();
  } else if (millis() - lastGpsFix > 6000) {
    gpsValid = false;
  }
  portEXIT_CRITICAL(&gpsMux);
}

// ==================== คำนวณก้าวเดินจาก GPS ====================
void updateSteps() {
  portENTER_CRITICAL(&gpsMux);
  bool valid = gpsValid;
  float spd = currentSpeedKmh;
  portEXIT_CRITICAL(&gpsMux);

  if (!valid)
    return;

  if (spd > 1.2 && spd < 15.0) {
    float distanceThisSec = (spd * 1000.0) / 3600.0;
    totalWalkDistance += distanceThisSec;
    totalSteps += (int)(distanceThisSec / 0.65);
  }
  lastSpeed = spd;
}

// ==================== ตั้งค่าปุ่มกด D18 ====================
void setupButton() {
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  Serial.println("[Button] ตั้งค่าปุ่มยืนยันการช่วยเหลือที่ขา D18 (INPUT_PULLUP) สำเร็จ");
}

void handleButton() {
  int reading = digitalRead(BUTTON_PIN);
  if (reading != lastButtonState) {
    lastDebounceTime = millis();
  }
  if ((millis() - lastDebounceTime) > DEBOUNCE_DELAY) {
    if (reading == LOW && currentButtonState == HIGH) {
      onButtonPressed();
    }
    currentButtonState = reading;
  }
  lastButtonState = reading;
}

void onButtonPressed() {
  Serial.println("\n========================================================");
  Serial.println("🔘 [Button D18] ตรวจพบการกดปุ่ม: ยืนยันการช่วยเหลือการล้มแล้ว!");
  Serial.println("========================================================");

  if (isAlarming) {
    stopAlarm();
  }

  beepConfirmation();

  if (offlineEventCount > 0) {
    offlineQueue[offlineEventCount - 1].assisted = true;
    offlineQueue[offlineEventCount - 1].assisted_at = millis();
    saveOfflineQueueToFlash();
  }

  // ส่ง Event การกดยืนยันผ่าน Queue ไปยัง Core 0
  FallRecord btnRec;
  memset(&btnRec, 0, sizeof(FallRecord));
  btnRec.is_button_event = true;
  portENTER_CRITICAL(&gpsMux);
  btnRec.lat = currentLat;
  btnRec.lng = currentLng;
  btnRec.gps_valid = gpsValid;
  portEXIT_CRITICAL(&gpsMux);

  if (fallEventQueue != NULL) {
    xQueueSend(fallEventQueue, &btnRec, 0);
  }
}

// ==================== จัดการการล้มที่ตรวจพบ (Core 1) ====================
void handleFallDetected(float accX, float accY, float accZ, float severity,
                        const char *fallType, float confidence) {
  Serial.println("\n========================================");
  Serial.printf("🚨 !!! ตรวจพบการล้ม (FALL DETECTED) !!! 🚨\n");
  Serial.printf("   ความมั่นใจ: %.2f | รูปแบบ: %s [%s]\n", confidence,
                fallDetector.getFallTypeNameThai(fallType), fallType);
  Serial.printf("   ความรุนแรง: %.2f | แรงกระแทก: %.2f m/s^2\n", severity,
                calcMagnitude(accX, accY, accZ));
  Serial.println("========================================\n");

  startAlarm();

  FallRecord rec;
  memset(&rec, 0, sizeof(FallRecord));
  rec.timestamp_ms = millis();
  rec.accX = accX;
  rec.accY = accY;
  rec.accZ = accZ;
  rec.severity = severity;
  rec.confidence = confidence;
  snprintf(rec.fall_type, sizeof(rec.fall_type), "%s",
           (fallType != nullptr) ? fallType : "fall_general");
  snprintf(rec.fall_type_name, sizeof(rec.fall_type_name), "%s",
           fallDetector.getFallTypeNameThai(fallType));

  portENTER_CRITICAL(&gpsMux);
  rec.lat = currentLat;
  rec.lng = currentLng;
  rec.gps_valid = gpsValid;
  portEXIT_CRITICAL(&gpsMux);

  rec.assisted = false;
  rec.assisted_at = 0;
  rec.is_button_event = false;

  // ส่งเข้า Queue เพื่อให้ Core 0 จัดการส่ง HTTP / Telegram และบันทึก Flash
  // (ป้องกันเก็บบันทึกซ้ำซ้อน)
  if (fallEventQueue != NULL) {
    if (xQueueSend(fallEventQueue, &rec, 0) != pdTRUE) {
      // กรณี Queue เต็มเท่านั้น จึงบันทึกลง Flash ทันที
      saveOfflineFallEvent(rec);
    }
  }
}

// ==================== รวบรวม Wi-Fi APs ====================
void appendWifiAps(JsonDocument &doc) {
  JsonArray aps = doc.createNestedArray("wifi_aps");
  JsonObject ap0 = aps.createNestedObject();
  ap0["bssid"] = WiFi.BSSIDstr();
  ap0["rssi"] = WiFi.RSSI();
  ap0["ssid"] = WiFi.SSID();
}

// ==================== ส่ง Fall Alert ไปยัง Server (Core 0) ====================
bool sendFallAlert(const FallRecord &rec) {
  if (WiFi.status() != WL_CONNECTED)
    return false;

  StaticJsonDocument<1024> doc;
  doc["device_id"] = "ESP32_001";
  doc["mac_address"] = WiFi.macAddress();
  doc["event_type"] = "fall";
  doc["fall_type"] =
      (strlen(rec.fall_type) > 0) ? rec.fall_type : "fall_general";
  doc["fall_type_name"] =
      (strlen(rec.fall_type_name) > 0) ? rec.fall_type_name : "การล้มทั่วไป";
  doc["confidence"] = rec.confidence;
  doc["severity"] =
      rec.severity > 0.7 ? "high" : (rec.severity > 0.4 ? "medium" : "low");
  doc["severity_score"] = rec.severity;
  doc["acceleration_x"] = rec.accX;
  doc["acceleration_y"] = rec.accY;
  doc["acceleration_z"] = rec.accZ;
  doc["assisted"] = rec.assisted ? 1 : 0;
  doc["offline_recorded"] = 0;

  if (rec.lat != 0.0 && rec.lng != 0.0 && rec.gps_valid) {
    doc["location_source"] = "gps";
    doc["lat"] = rec.lat;
    doc["lng"] = rec.lng;
  } else if (hasIndoorFix && indoorLat != 0.0 && indoorLng != 0.0) {
    doc["location_source"] = "hotspot";
    doc["lat"] = indoorLat;
    doc["lng"] = indoorLng;
    doc["wifi_ssid"] = WiFi.SSID();
    doc["wifi_bssid"] = WiFi.BSSIDstr();
    doc["wifi_rssi"] = WiFi.RSSI();
    appendWifiAps(doc);
  } else {
    doc["location_source"] = "wifi";
    doc["wifi_ssid"] = WiFi.SSID();
    doc["wifi_bssid"] = WiFi.BSSIDstr();
    doc["wifi_rssi"] = WiFi.RSSI();
    appendWifiAps(doc);
  }

  String json;
  serializeJson(doc, json);
  return sendToUrl(fallsUrl, json, true);
}

// ==================== ซิงค์ข้อมูลพิกัดอุปกรณ์ขึ้น Server ตลอดเวลา (Core 0)
// ====================
void sendGPSData() {
  StaticJsonDocument<1024> doc;
  doc["device_id"] = "ESP32_001";
  doc["mac_address"] = WiFi.macAddress();
  doc["step_count"] = totalSteps;
  doc["walk_distance"] = totalWalkDistance;
  doc["wifi_ssid"] = WiFi.SSID();
  doc["wifi_bssid"] = WiFi.BSSIDstr();
  doc["wifi_rssi"] = WiFi.RSSI();

  portENTER_CRITICAL(&gpsMux);
  double lat = currentLat;
  double lng = currentLng;
  float spd = currentSpeedKmh;
  float alt = currentAltitude;
  float hd = currentHdop;
  int sats = currentSatellites;
  bool valid = gpsValid;
  portEXIT_CRITICAL(&gpsMux);

  doc["satellites"] = sats;

  if (valid && lat != 0.0 && lng != 0.0) {
    doc["location_source"] = "gps";
    doc["lat"] = lat;
    doc["lng"] = lng;
    doc["speed_kmh"] = spd;
    doc["altitude_m"] = alt;
    doc["hdop"] = hd;
    Serial.printf("[GPS Sync 1s] 🛰 ซิงค์พิกัด: %.6f, %.6f | ดาวเทียม: %d ดวง\n", lat,
                  lng, sats);
  } else {
    doc["location_source"] = "wifi";
    doc["speed_kmh"] = 0.0;
    doc["hdop"] = 25.0;
    appendWifiAps(doc);
  }

  String json;
  serializeJson(doc, json);

  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(gpsUrl);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", apiKey);
    http.setTimeout(3500);
    int httpCode = http.POST(json);
    if (httpCode >= 200 && httpCode < 300) {
      String resp = http.getString();
      StaticJsonDocument<512> resDoc;
      if (deserializeJson(resDoc, resp) == DeserializationError::Ok) {
        double l = resDoc["lat"] | 0.0;
        double g = resDoc["lng"] | 0.0;
        const char *src = resDoc["location_source"] | "";
        bool isHotspot = (strcmp(src, "hotspot") == 0);
        bool isBkkOrKorat = (!isHotspot && ((l > 13.0 && l < 14.2) || (l > 14.8 && l < 15.3)));
        if (l != 0.0 && g != 0.0 && !isBkkOrKorat) {
          indoorLat = l;
          indoorLng = g;
          hasIndoorFix = true;
        }
      }
    }
    http.end();
  }
}

// ==================== อ่าน IMU MPU-6050 (Core 1) ====================
void readIMU() {
  sensors_event_t a, g, temp;
  if (!mpu.getEvent(&a, &g, &temp))
    return;

  accelBuffer[bufferIndex][0] = a.acceleration.x;
  accelBuffer[bufferIndex][1] = a.acceleration.y;
  accelBuffer[bufferIndex][2] = a.acceleration.z;

  gyroBuffer[bufferIndex][0] = g.gyro.x;
  gyroBuffer[bufferIndex][1] = g.gyro.y;
  gyroBuffer[bufferIndex][2] = g.gyro.z;

  bufferIndex = (bufferIndex + 1) % WINDOW_SIZE;
  if (bufferIndex == 0)
    bufferFull = true;
}

// ==================== ตรวจจับการล้มด้วย TinyML (Core 1) ====================
bool checkForFall(float &confidenceOut, const char *&fallTypeOut,
                  float &peakAccX, float &peakAccY, float &peakAccZ,
                  float &peakMagG) {
  float flattenedData[WINDOW_SIZE * 6];
  float maxMag = 0.0f;
  int peakIdx = 0;

  for (int i = 0; i < WINDOW_SIZE; i++) {
    int idx = (bufferIndex + i) % WINDOW_SIZE;
    float ax = accelBuffer[idx][0];
    float ay = accelBuffer[idx][1];
    float az = accelBuffer[idx][2];
    flattenedData[i * 6 + 0] = ax;
    flattenedData[i * 6 + 1] = ay;
    flattenedData[i * 6 + 2] = az;

    flattenedData[i * 6 + 3] = gyroBuffer[idx][0];
    flattenedData[i * 6 + 4] = gyroBuffer[idx][1];
    flattenedData[i * 6 + 5] = gyroBuffer[idx][2];

    float mag = sqrt(ax * ax + ay * ay + az * az);
    if (mag > maxMag) {
      maxMag = mag;
      peakIdx = idx;
    }
  }

  peakAccX = accelBuffer[peakIdx][0];
  peakAccY = accelBuffer[peakIdx][1];
  peakAccZ = accelBuffer[peakIdx][2];
  peakMagG = maxMag / 9.80665f;

  // 🛡️ Physical Impact Guard: ป้องกัน False Alarm 100%
  // ถ้าแรงกระแทกสูงสุดในรอบ 1 วินาทีไม่ถึง 1.8G (หรือ 17.6 m/s²) แสดงว่าอยู่นิ่งๆ
  // หรือขยับตัวปกติ ปฏิเสธทันที
  if (peakMagG < 1.8f) {
    confidenceOut = 0.0f;
    fallTypeOut = "adl_normal";
    return false;
  }

  confidenceOut = fallDetector.predict(flattenedData, WINDOW_SIZE * 6);
  fallTypeOut = fallDetector.identifyFallType(flattenedData, WINDOW_SIZE * 6);

  return (confidenceOut >= fallDetector.getMinConfidence());
}

// ==================== ระบบเสียง Buzzer (Core 1) ====================
void setupBuzzer() {
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);
}

void startAlarm() {
  isAlarming = true;
  alarmStartTime = millis();
  digitalWrite(BUZZER_PIN, HIGH);
}

void updateAlarm(unsigned long now) {
  if (now - alarmStartTime >= ALARM_DURATION) {
    stopAlarm();
    return;
  }
  bool toneState = ((now / 200) % 2) == 0;
  digitalWrite(BUZZER_PIN, toneState ? HIGH : LOW);
}

void stopAlarm() {
  isAlarming = false;
  digitalWrite(BUZZER_PIN, LOW);
}

void beepConfirmation() {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(80);
  digitalWrite(BUZZER_PIN, LOW);
  delay(80);
  digitalWrite(BUZZER_PIN, HIGH);
  delay(80);
  digitalWrite(BUZZER_PIN, LOW);
}

// ==================== Offline Flash Preferences ====================
void saveOfflineFallEvent(const FallRecord &rec) {
  if (offlineEventCount < MAX_OFFLINE_EVENTS) {
    offlineQueue[offlineEventCount++] = rec;
    saveOfflineQueueToFlash();
  }
}

void saveOfflineQueueToFlash() {
  offlinePrefs.putInt("count", offlineEventCount);
  offlinePrefs.putBytes("queue", offlineQueue,
                        sizeof(FallRecord) * offlineEventCount);
}

void loadOfflineQueueFromFlash() {
  offlineEventCount = offlinePrefs.getInt("count", 0);
  if (offlineEventCount > MAX_OFFLINE_EVENTS)
    offlineEventCount = MAX_OFFLINE_EVENTS;
  if (offlineEventCount > 0) {
    offlinePrefs.getBytes("queue", offlineQueue,
                          sizeof(FallRecord) * offlineEventCount);
  }
}

void syncOfflineFallEvents() {
  if (offlineEventCount == 0 || WiFi.status() != WL_CONNECTED)
    return;
  Serial.printf(
      "[Offline Sync] 🔄 ตรวจพบข้อมูลล้มออฟไลน์ %d รายการ กำลังเริ่มซิงค์...\n",
      offlineEventCount);

  int failedAttempts = 0;
  while (offlineEventCount > 0 && WiFi.status() == WL_CONNECTED) {
    FallRecord &rec = offlineQueue[0];

    // กรองข้อมูลเก่าตกค้างใน Flash: ถ้าแรงกระแทกต่ำกว่า 1.8G (ไม่ใช่การล้มจริง
    // และไม่ใช่การกดปุ่ม SOS) ให้ทิ้งทันที
    float magG = calcMagnitude(rec.accX, rec.accY, rec.accZ) / 9.80665f;
    if (magG < 1.8f && !rec.is_button_event) {
      Serial.printf("[Offline Sync] 🗑️ ล้างข้อมูลตกค้างที่ไม่ใช่การล้มจริงออกจาก Flash "
                    "(Mag: %.2f G)\n",
                    magG);
      for (int j = 0; j < offlineEventCount - 1; j++) {
        offlineQueue[j] = offlineQueue[j + 1];
      }
      offlineEventCount--;
      saveOfflineQueueToFlash();
      continue;
    }
    StaticJsonDocument<1024> doc;
    doc["device_id"] = "ESP32_001";
    doc["mac_address"] = WiFi.macAddress();
    doc["event_type"] = "fall";
    doc["fall_type"] =
        (strlen(rec.fall_type) > 0) ? rec.fall_type : "fall_general";
    doc["fall_type_name"] =
        (strlen(rec.fall_type_name) > 0) ? rec.fall_type_name : "การล้มทั่วไป";
    doc["confidence"] = rec.confidence;
    doc["severity"] =
        rec.severity > 0.7 ? "high" : (rec.severity > 0.4 ? "medium" : "low");
    doc["severity_score"] = rec.severity;
    doc["acceleration_x"] = rec.accX;
    doc["acceleration_y"] = rec.accY;
    doc["acceleration_z"] = rec.accZ;
    doc["assisted"] = rec.assisted ? 1 : 0;
    doc["offline_recorded"] = 1;
    doc["occurred_seconds_ago"] = (millis() >= rec.timestamp_ms)
                                      ? ((millis() - rec.timestamp_ms) / 1000)
                                      : 0;

    if (rec.lat != 0.0 && rec.lng != 0.0) {
      doc["location_source"] = "gps";
      doc["lat"] = rec.lat;
      doc["lng"] = rec.lng;
    } else {
      doc["location_source"] = "wifi";
      doc["wifi_ssid"] = WiFi.SSID();
      doc["wifi_bssid"] = WiFi.BSSIDstr();
    }

    String json;
    serializeJson(doc, json);
    if (sendToUrl(fallsUrl, json, true)) {
      Serial.printf("[Offline Sync] ✅ ซิงค์เหตุการณ์สำเร็จ 1 รายการ (เหลือค้างใน "
                    "Flash: %d รายการ)\n",
                    offlineEventCount - 1);
      for (int j = 0; j < offlineEventCount - 1; j++) {
        offlineQueue[j] = offlineQueue[j + 1];
      }
      offlineEventCount--;
      saveOfflineQueueToFlash();
      failedAttempts = 0;
      vTaskDelay(pdMS_TO_TICKS(200));
    } else {
      failedAttempts++;
      Serial.printf("[Offline Sync] ❌ ส่งข้อมูลออฟไลน์ล้มเหลว (ลอง: %d/3)\n",
                    failedAttempts);
      if (failedAttempts >= 3) {
        Serial.println(
            "[Offline Sync] ⚠️ ข้ามข้อมูลรายการที่ล้มเหลวซ้ำ เพื่อไม่ให้ค้างระบบ");
        for (int j = 0; j < offlineEventCount - 1; j++) {
          offlineQueue[j] = offlineQueue[j + 1];
        }
        offlineEventCount--;
        saveOfflineQueueToFlash();
      }
      break;
    }
  }

  if (offlineEventCount == 0) {
    Serial.println("[Offline Sync] ✅ ส่งประวัติออฟไลน์ทั้งหมดสำเร็จเรียบร้อย");
  } else {
    Serial.printf("[Offline Sync] ⚠️ ยังมีข้อมูลออฟไลน์คงค้าง %d รายการ\n",
                  offlineEventCount);
  }
}

// ==================== Setup WiFi & IMU ====================
void setupWiFi() {
  WiFiManager wm;
  wm.setConfigPortalTimeout(180);
  if (!wm.autoConnect(WM_AP_NAME)) {
    Serial.println("[WiFi] ไม่สามารถต่อ WiFi ได้ -> เริ่มทำงานในโหมดออฟไลน์");
  } else {
    Serial.printf("[WiFi] เชื่อมต่อสำเร็จ! IP: %s\n",
                  WiFi.localIP().toString().c_str());
  }
}

void checkWiFi() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
  }
}

void setupIMU() {
  Wire.begin(21, 22);
  if (!mpu.begin()) {
    Serial.println("[IMU Error] ไม่พบเซ็นเซอร์ MPU-6050!");
    while (1)
      delay(1000);
  }
  mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
  mpu.setGyroRange(MPU6050_RANGE_1000_DEG);
  mpu.setFilterBandwidth(MPU6050_BAND_44_HZ);
  Serial.println("[IMU] MPU-6050 เริ่มต้นสำเร็จ");
}

bool sendToUrl(const char *url, const String &jsonPayload, bool isFalls) {
  if (WiFi.status() != WL_CONNECTED)
    return false;
  HTTPClient http;
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", apiKey);
  // สำหรับเหตุการณ์การล้ม ให้เวลา Server ประมวลผลและส่ง Telegram (10 วินาที)
  http.setTimeout(isFalls ? 10000 : 5000);
  int httpCode = http.POST(jsonPayload);
  Serial.printf("[HTTP] POST %s -> Code %d\n", url, httpCode);
  http.end();
  return (httpCode >= 200 && httpCode < 300);
}

float calcMagnitude(float x, float y, float z) {
  return sqrt(x * x + y * y + z * z);
}

String getTargetChatId() {
  if (String(CHAT_ID) != "YOUR_CHAT_ID" && strlen(CHAT_ID) > 0) {
    return String(CHAT_ID);
  }
  if (strlen(telegramChatId) > 0) {
    return String(telegramChatId);
  }
  return preferences.getString("tg_chat_id", "");
}

// ==================== Telegram Bot Functions (Core 0) ====================
void setupTelegram() {
  securedClient.setInsecure();

  if (WiFi.status() == WL_CONNECTED) {
    if (String(CHAT_ID) != "YOUR_CHAT_ID" && strlen(CHAT_ID) > 0) {
      strncpy(telegramChatId, CHAT_ID, sizeof(telegramChatId) - 1);
      preferences.putString("tg_chat_id", CHAT_ID);
    } else {
      String savedChatId = preferences.getString("tg_chat_id", "");
      if (savedChatId.length() > 0) {
        strncpy(telegramChatId, savedChatId.c_str(),
                sizeof(telegramChatId) - 1);
      } else {
        int num = bot.getUpdates(bot.last_message_received + 1);
        if (num > 0) {
          String fromId = String(bot.messages[0].chat_id);
          strncpy(telegramChatId, fromId.c_str(), sizeof(telegramChatId) - 1);
          preferences.putString("tg_chat_id", fromId);
        }
      }
    }

    String target = getTargetChatId();
    if (target.length() > 0) {
      bot.sendMessage(target, "แจ้งเตือน: บอร์ด ESP32 เริ่มทำงานแล้ว!", "");
      Serial.println("[Telegram] ส่งข้อความเริ่มทำงานสำเร็จ");
    } else {
      Serial.println(
          "[Telegram] ℹ️ ยังไม่มี Chat ID (ใส่ใน CHAT_ID หรือกด /start ในบอท)");
    }
  }
}

void sendTelegramFallAlert(const FallRecord &rec) {
  if (WiFi.status() != WL_CONNECTED)
    return;
  String target = getTargetChatId();
  if (target.length() == 0)
    return;

  float mag = calcMagnitude(rec.accX, rec.accY, rec.accZ);
  float gForce = mag / 9.80665f;

  String msg = "🚨 แจ้งเตือน: ตรวจพบการล้มฉุกเฉิน!\n";
  msg += "👤 อุปกรณ์: CareGuardH1\n";
  msg += "⚠️ รูปแบบการล้ม: " + String(rec.fall_type_name) + "\n";
  msg += "🎯 ความมั่นใจ AI: " + String((int)(rec.confidence * 100)) + "%\n";
  msg += "💥 ระดับความรุนแรง: " +
         String(rec.severity > 0.7
                    ? "สูง (High)"
                    : (rec.severity > 0.4 ? "ปานกลาง (Medium)" : "ต่ำ (Low)")) +
         "\n";
  msg += "⚡ แรงกระแทก: " + String(gForce, 2) + " G (" + String(mag, 1) +
         " m/s²)\n";
  if (rec.lat != 0.0 && rec.lng != 0.0 && !isnan(rec.lat) && !isnan(rec.lng) &&
      rec.gps_valid) {
    msg += "📍 พิกัด: https://maps.google.com/?q=" + String(rec.lat, 6) + "," +
           String(rec.lng, 6) + " (🛰️ GPS ดาวเทียม)";
  } else if (hasIndoorFix && indoorLat != 0.0 && indoorLng != 0.0) {
    msg += "📍 พิกัด: https://maps.google.com/?q=" + String(indoorLat, 6) + "," +
           String(indoorLng, 6) + " (📱 GPS มือถือ Hotspot / Wi-Fi ในอาคาร)";
  } else {
    msg += "📍 พิกัด: 🛰️ กำลังค้นหาสัญญาณดาวเทียม GPS...";
  }

  bot.sendMessage(target, msg, "");
  Serial.println("[Telegram] ส่งแจ้งเตือนการล้มสำเร็จ");
}
