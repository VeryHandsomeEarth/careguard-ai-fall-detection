/*
 * ESP32 ระบบตรวจจับการล้ม + FreeRTOS Dual-Core + Wi-Fi Geolocation (Hardware2
 * v1.0)
 *
 * ความแตกต่างจาก Hardware1:
 * - ใช้ Wi-Fi Geolocation (สแกน BSSID/SSID/RSSI) ระบุตำแหน่งแทนโมดูล GPS NEO-7M
 * - ไม่ต้องต่อโมดูล GPS NEO-7M และไม่ต้องถอดสาย TX/RX เวลาอัปโหลดโค้ด
 * - Serial ทำงานที่ 115200 bps สำหรับ Debug Monitor ปกติ
 * - ระบบนับก้าวเดิน (Pedometer) คำนวณจากแรงกระแทกความเร่งของ MPU-6050 โดยตรง
 *
 * สถาปัตยกรรม FreeRTOS Dual-Core:
 * - Core 1 (Task 1: imuFallTask - Priority 3 High):
 *      - อ่าน IMU MPU-6050 ที่ความถี่ 50Hz (20ms) แม่นยำระดับฮาร์ดแวร์
 *      - ตรวจจับทิศทางและแรงกระแทกการล้มด้วย AI TinyML
 *      - นับก้าวเดิน (Pedometer Step Counter) จาก MPU-6050 Accelerometer
 *      - ควบคุมเสียงไซเรน Buzzer ขา D5 และปุ่มยืนยัน D18
 *      - ส่งข้อมูลการล้มผ่าน FreeRTOS Queue (0ms non-blocking)
 *      - ปลอดจากการบล็อกของเน็ตเวิร์ก/HTTP 100%
 *
 * - Core 0 (Task 2: netWifiTask - Priority 1 Normal):
 *      - ซิงค์ข้อมูล Wi-Fi Geolocation ขึ้น Server ทุก 1 วินาที
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
#include <UniversalTelegramBot.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <freertos/task.h>


// ==================== Telegram Bot Configuration ====================
// Telegram Bot: @CareGuardAI2_bot (Hardware 2)
#define BOT_TOKEN "8725825726:AAFOfB9MXOcWHwmbgUoWhXG6ryIHW5ZJlmE"
#define CHAT_ID "8758930399"

WiFiClientSecure securedClient;
UniversalTelegramBot bot(BOT_TOKEN, securedClient);
char telegramChatId[64] = "";

// ==================== การตั้งค่าฮาร์ดแวร์และพิน ====================
const char *WM_AP_NAME = "CareGuard_HW2_Setup";

// Buzzer & Button
#define BUZZER_PIN 5  // ขา D5 (GPIO 5)
#define BUTTON_PIN 18 // ปุ่มยืนยันการช่วยเหลือ D18 (GPIO 18, INPUT_PULLUP)

// IMU Sampling
const int SAMPLE_RATE_MS = 20; // 50Hz
const int WINDOW_SIZE = 50;

// ==================== Server URL & API Key ====================
const char *DEVICE_ID = "ESP32_HW2";
char fallsUrl[128] = "https://www.youngza.com/IMU/SoftwarePHP/api/falls";
char gpsUrl[128] = "https://www.youngza.com/IMU/SoftwarePHP/api/gps";
char apiKey[64] = "hw1-8931932eb8a233006062ebdb0651eb74";

// ==================== โครงสร้างข้อมูลการล้ม (Fall Record) ====================
struct FallRecord {
  double lat;
  double lng;
  unsigned long timestamp_ms;
  float accX;
  float accY;
  float accZ;
  float severity;
  float confidence;
  char fall_type[32];
  char fall_type_name[128];
  bool assisted;
  unsigned long assisted_at;
  bool is_button_event; // True เมื่อเป็นการกดปุ่มยืนยัน D18
};

#define MAX_OFFLINE_EVENTS 20
FallRecord offlineQueue[MAX_OFFLINE_EVENTS];
int offlineEventCount = 0;

// พิกัดตำแหน่งปัจจุบันสำหรับ Wi-Fi Geolocation (รับจาก Server ตาม Wi-Fi Router ที่เชื่อมต่อ)
double currentLat = 0.0;
double currentLng = 0.0;

// ==================== ตัวแปรส่วนกลาง ====================
Adafruit_MPU6050 mpu;
FallDetector fallDetector;
Preferences preferences;
Preferences offlinePrefs;

// FreeRTOS Handles
TaskHandle_t imuTaskHandle = NULL;
TaskHandle_t netWifiTaskHandle = NULL;
QueueHandle_t fallEventQueue = NULL;

// IMU Buffer (Core 1 Only)
float accelBuffer[WINDOW_SIZE][3];
float gyroBuffer[WINDOW_SIZE][3];
int bufferIndex = 0;
bool bufferFull = false;

// Step Counting (IMU Accelerometer Pedometer)
int totalSteps = 0;
float totalWalkDistance = 0.0f; // เมตร
bool stepPeakDetected = false;
unsigned long lastStepTime = 0;

// Timing Configuration
const unsigned long WIFI_GEO_SEND_INTERVAL =
    2500; // 2.5 วินาที ซิงค์ Wi-Fi Geolocation เสถียร ไม่โหลด Wi-Fi stack เกินไป
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
void setupBuzzer();
void setupButton();
void handleButton();
void onButtonPressed();
void readIMU();
void detectStep(float ax, float ay, float az);
bool checkForFall(float &confidenceOut, const char *&fallTypeOut,
                  float &peakAccX, float &peakAccY, float &peakAccZ,
                  float &peakMagG);
void handleFallDetected(float accX, float accY, float accZ, float severity,
                        const char *fallType, float confidence);
bool sendFallAlert(const FallRecord &rec);
void sendWifiGeoData();
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
void netWifiTask(void *pvParameters);

// ==================== SETUP ====================
void setup() {
  Serial.begin(115200);
  delay(400);

  Serial.println("\n========================================================");
  Serial.println("  CareGuard AI - Hardware2 (Wi-Fi Geolocation Edition)");
  Serial.println("  Core 1: IMU 50Hz TinyML Fall Detection + Pedometer");
  Serial.println("  Core 0: Wi-Fi Geolocation Sync (1s) + Telegram Bot");
  Serial.println("  (No GPS NEO-7M module required)");
  Serial.println("========================================================\n");

  setupBuzzer();
  setupButton();

  preferences.begin("falldetect", false);
  currentLat = preferences.getDouble("home_lat", 16.428000);
  currentLng = preferences.getDouble("home_lng", 102.861700);
  // ล้างพิกัดกรุงเทพหรือโคราชที่อาจหลุดมาจาก IP cellular หรือพิกัดเดิม มทร.อีสาน ที่ค้างใน Flash
  bool isInvalidArea =
      ((currentLat > 13.0 && currentLat < 14.2) ||
       (currentLat > 14.8 && currentLat < 15.3) || 
       (currentLat == 0.0) ||
       (fabs(currentLat - 16.430400) < 0.0001 && fabs(currentLng - 102.863600) < 0.0001));
  if (isInvalidArea) {
    currentLat = 16.428000;
    currentLng = 102.861700;
    preferences.putDouble("home_lat", 16.428000);
    preferences.putDouble("home_lng", 102.861700);
  }
  offlinePrefs.begin("fall_offline", false);
  loadOfflineQueueFromFlash();

  setupIMU();
  fallDetector.begin();

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

  // สร้าง Task 2: จัดการ Wi-Fi Geolocation และ Network บน Core 0 (Priority 1)
  xTaskCreatePinnedToCore(netWifiTask, "netWifiTask", 16384, NULL, 1,
                          &netWifiTaskHandle, 0);

  Serial.println("[System] FreeRTOS Tasks เริ่มทำงานสมบูรณ์ทั้ง 2 คอร์\n");
}

// ==================== LOOP ====================
void loop() {
  // FreeRTOS แยกทำงานอิสระบน Core 0 และ Core 1
  vTaskDelay(pdMS_TO_TICKS(1000));
}

// =========================================================================
// FreeRTOS Task 1 (Core 1): IMU Sampling 50Hz, TinyML, Pedometer, Buzzer,
// Button
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
// FreeRTOS Task 2 (Core 0): Wi-Fi Geolocation Sync, Fall Alerts, Telegram,
// Flash Sync
// =========================================================================
void netWifiTask(void *pvParameters) {
  unsigned long lastGeoSend = 0;
  unsigned long lastWifiCheck = 0;
  unsigned long lastOfflineSync = 0;

  for (;;) {
    unsigned long now = millis();

    // 1. ตรวจสอบสถานะการเชื่อมต่อ Wi-Fi ทุก 15 วินาที
    if (now - lastWifiCheck >= WIFI_CHECK_INTERVAL) {
      lastWifiCheck = now;
      checkWiFi();
    }

    // 2. ดักรับเหตุการณ์การล้มหรือปุ่มกดจาก Queue (Core 1) ทันที (Priority สูงสุด ไม่ต้องรอส่ง Geo)
    FallRecord rec;
    if (xQueueReceive(fallEventQueue, &rec, 0) == pdTRUE) {
      if (rec.is_button_event) {
        // ส่งอีเวนต์ยืนยันการช่วยเหลือจากปุ่ม D18
        if (WiFi.status() == WL_CONNECTED) {
          StaticJsonDocument<256> doc;
          doc["device_id"] = DEVICE_ID;
          doc["event_type"] = "fall_assisted";
          doc["action"] = "assist";
          String json;
          serializeJson(doc, json);
          sendToUrl(fallsUrl, json, true);
          Serial.println("[Net Task] ส่งยืนยันการช่วยเหลือปุ่ม D18 ไปยัง Server สำเร็จ");

          String target = getTargetChatId();
          if (target.length() > 0) {
            bot.sendMessage(target,
                            "✅ [CareGuard] ได้รับการช่วยเหลือเรียบร้อยแล้ว (กดยืนยันปุ่ม "
                            "D18 ที่ตัวเครื่อง)",
                            "");
          }
        }
      } else {
        // ส่ง Fall Alert ไปยัง Server ทันที
        bool sent = sendFallAlert(rec);
        if (sent) {
          Serial.println(
              "[Net Task] ส่ง Fall Alert (Wi-Fi Geolocation) ไปยัง Server สำเร็จ");
        } else {
          Serial.println("[Net Task] ส่งไม่สำเร็จ เก็บเข้าคิวออฟไลน์");
          saveOfflineFallEvent(rec);
        }

        // ส่งแจ้งเตือน Telegram Bot
        sendTelegramFallAlert(rec);
      }
    }

    // 3. ซิงค์ Wi-Fi Geolocation ขึ้น Server ทุก 1 วินาที (เมื่อไม่มีคิวการล้ม)
    if (WiFi.status() == WL_CONNECTED &&
        (now - lastGeoSend >= WIFI_GEO_SEND_INTERVAL)) {
      lastGeoSend = now;
      sendWifiGeoData();
    }

    // 4. ซิงค์เหตุการณ์ออฟไลน์จาก Flash
    if (offlineEventCount > 0 && (WiFi.status() == WL_CONNECTED) &&
        (now - lastOfflineSync >= OFFLINE_SYNC_INTERVAL)) {
      lastOfflineSync = now;
      syncOfflineFallEvents();
    }

    // 5. หากยังไม่มี Chat ID ให้ตรวจจับข้อความ /start จาก Telegram ทุก 8 วินาที
    static unsigned long lastTgCheck = 0;
    if (getTargetChatId().length() == 0 && WiFi.status() == WL_CONNECTED &&
        (now - lastTgCheck >= 8000)) {
      lastTgCheck = now;
      int num = bot.getUpdates(bot.last_message_received + 1);
      for (int i = 0; i < num; i++) {
        String fromId = String(bot.messages[i].chat_id);
        if (fromId.length() > 0) {
          strncpy(telegramChatId, fromId.c_str(), sizeof(telegramChatId) - 1);
          preferences.putString("tg_chat_id", fromId);
          bot.sendMessage(fromId, "✅ บันทึก Chat ID สำหรับ Hardware2 เรียบร้อยแล้ว!",
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
    saveOfflineQueueToFlash();
  }

  FallRecord btnRec;
  memset(&btnRec, 0, sizeof(FallRecord));
  btnRec.lat = currentLat;
  btnRec.lng = currentLng;
  btnRec.is_button_event = true;
  btnRec.assisted = true;
  btnRec.timestamp_ms = millis();

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
  rec.lat = currentLat;
  rec.lng = currentLng;
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
  doc["device_id"] = DEVICE_ID;
  doc["mac_address"] = WiFi.macAddress();
  doc["event_type"] = "fall";
  doc["lat"] = rec.lat;
  doc["lng"] = rec.lng;
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

  // Hardware2: ใช้ Wi-Fi Geolocation ตลอดเวลา
  doc["location_source"] = "wifi";
  doc["wifi_ssid"] = WiFi.SSID();
  doc["wifi_bssid"] = WiFi.BSSIDstr();
  doc["wifi_rssi"] = WiFi.RSSI();
  appendWifiAps(doc);

  String json;
  serializeJson(doc, json);
  return sendToUrl(fallsUrl, json, true);
}

// ==================== ซิงค์ข้อมูล Wi-Fi Geolocation ขึ้น Server ตลอดเวลา (Core 0)
// ====================
void sendWifiGeoData() {
  StaticJsonDocument<1024> doc;
  doc["device_id"] = DEVICE_ID;
  doc["mac_address"] = WiFi.macAddress();
  doc["step_count"] = totalSteps;
  doc["walk_distance"] = totalWalkDistance;
  doc["location_source"] = "wifi";
  doc["wifi_ssid"] = WiFi.SSID();
  doc["wifi_bssid"] = WiFi.BSSIDstr();
  doc["wifi_rssi"] = WiFi.RSSI();
  doc["satellites"] = 0;
  doc["hdop"] = 15.0; // ค่าความแม่นยำประมาณการสำหรับ Wi-Fi ในอาคาร (เมตร)
  doc["speed_kmh"] = 0.0;
  doc["altitude_m"] = 0.0;
  appendWifiAps(doc);

  String json;
  serializeJson(doc, json);

  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(gpsUrl);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", apiKey);
    http.setTimeout(4000);
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
          currentLat = l;
          currentLng = g;
          preferences.putDouble("home_lat", l);
          preferences.putDouble("home_lng", g);
        }
      }
    }
    http.end();
  }
}

// ==================== คำนวณก้าวเดินจาก MPU-6050 (Pedometer บน Core 1)
// ====================
void detectStep(float ax, float ay, float az) {
  float mag = sqrt(ax * ax + ay * ay + az * az);
  unsigned long now = millis();

  // ก้าวเดินของมนุษย์จะสร้างแรงกระแทกความเร่งสูงกว่า 12 m/s^2 และมีช่วงหน่วงอย่างน้อย 320ms
  // ต่อก้าว
  if (mag > 12.2f && !stepPeakDetected && (now - lastStepTime > 320)) {
    stepPeakDetected = true;
    lastStepTime = now;
    totalSteps++;
    totalWalkDistance += 0.70f; // ประมาณระยะทางเฉลี่ย 0.70 เมตร/ก้าว
  } else if (mag < 10.2f) {
    stepPeakDetected = false;
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

  // ตรวจจับก้าวเดินจากแรงกระแทก IMU
  detectStep(a.acceleration.x, a.acceleration.y, a.acceleration.z);

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
  // ส่งเสียงบี๊บจังหวะเตือนภัยเป็นช่วงๆ
  bool beepOn = ((now - alarmStartTime) / 250) % 2 == 0;
  digitalWrite(BUZZER_PIN, beepOn ? HIGH : LOW);
}

void stopAlarm() {
  isAlarming = false;
  digitalWrite(BUZZER_PIN, LOW);
}

void beepConfirmation() {
  for (int i = 0; i < 2; i++) {
    digitalWrite(BUZZER_PIN, HIGH);
    delay(80);
    digitalWrite(BUZZER_PIN, LOW);
    delay(80);
  }
}

// ==================== จัดการข้อมูลออฟไลน์ใน Flash Memory ====================
void saveOfflineQueueToFlash() {
  offlinePrefs.putInt("count", offlineEventCount);
  for (int i = 0; i < offlineEventCount; i++) {
    String key = "ev_" + String(i);
    offlinePrefs.putBytes(key.c_str(), &offlineQueue[i], sizeof(FallRecord));
  }
}

void loadOfflineQueueFromFlash() {
  offlineEventCount = offlinePrefs.getInt("count", 0);
  if (offlineEventCount > MAX_OFFLINE_EVENTS)
    offlineEventCount = MAX_OFFLINE_EVENTS;
  for (int i = 0; i < offlineEventCount; i++) {
    String key = "ev_" + String(i);
    offlinePrefs.getBytes(key.c_str(), &offlineQueue[i], sizeof(FallRecord));
  }
  if (offlineEventCount > 0) {
    Serial.printf("[Flash] โหลดประวัติเหตุการณ์ล้มออฟไลน์: %d รายการ\n",
                  offlineEventCount);
  }
}

void saveOfflineFallEvent(const FallRecord &rec) {
  if (offlineEventCount < MAX_OFFLINE_EVENTS) {
    offlineQueue[offlineEventCount++] = rec;
  } else {
    for (int i = 0; i < MAX_OFFLINE_EVENTS - 1; i++) {
      offlineQueue[i] = offlineQueue[i + 1];
    }
    offlineQueue[MAX_OFFLINE_EVENTS - 1] = rec;
  }
  saveOfflineQueueToFlash();
  Serial.printf("[Flash] บันทึกเหตุการณ์ล้มออฟไลน์ (สะสม: %d รายการ)\n",
                offlineEventCount);
}

void syncOfflineFallEvents() {
  if (offlineEventCount == 0 || WiFi.status() != WL_CONNECTED)
    return;
  Serial.printf("[Sync] เริ่มส่งข้อมูลออฟไลน์ขึ้น Server (%d รายการ)...\n",
                offlineEventCount);

  int failedAttempts = 0;
  while (offlineEventCount > 0 && WiFi.status() == WL_CONNECTED) {
    FallRecord &r = offlineQueue[0];

    // กรองข้อมูลเก่าตกค้างใน Flash: ถ้าแรงกระแทกต่ำกว่า 1.8G (ไม่ใช่การล้มจริง
    // และไม่ใช่การกดปุ่ม SOS) ให้ทิ้งทันที
    float magG = calcMagnitude(r.accX, r.accY, r.accZ) / 9.80665f;
    if (magG < 1.8f && !r.is_button_event) {
      Serial.printf(
          "[Sync] 🗑️ ล้างข้อมูลตกค้างที่ไม่ใช่การล้มจริงออกจาก Flash (Mag: %.2f G)\n",
          magG);
      for (int j = 0; j < offlineEventCount - 1; j++) {
        offlineQueue[j] = offlineQueue[j + 1];
      }
      offlineEventCount--;
      saveOfflineQueueToFlash();
      continue;
    }

    StaticJsonDocument<1024> doc;
    doc["device_id"] = DEVICE_ID;
    doc["mac_address"] = WiFi.macAddress();
    doc["event_type"] = "fall";
    doc["fall_type"] = (strlen(r.fall_type) > 0) ? r.fall_type : "fall_general";
    doc["fall_type_name"] =
        (strlen(r.fall_type_name) > 0) ? r.fall_type_name : "การล้มทั่วไป";
    doc["confidence"] = r.confidence;
    doc["severity"] =
        r.severity > 0.7 ? "high" : (r.severity > 0.4 ? "medium" : "low");
    doc["severity_score"] = r.severity;
    doc["acceleration_x"] = r.accX;
    doc["acceleration_y"] = r.accY;
    doc["acceleration_z"] = r.accZ;
    doc["assisted"] = r.assisted ? 1 : 0;
    doc["offline_recorded"] = 1;
    doc["location_source"] = "wifi";
    doc["wifi_ssid"] = WiFi.SSID();
    doc["wifi_bssid"] = WiFi.BSSIDstr();
    doc["wifi_rssi"] = WiFi.RSSI();
    if (r.lat != 0.0 && r.lng != 0.0 && !isnan(r.lat) && !isnan(r.lng)) {
      doc["lat"] = r.lat;
      doc["lng"] = r.lng;
    }
    appendWifiAps(doc);

    unsigned long elapsedSec = (millis() - r.timestamp_ms) / 1000;
    doc["occurred_seconds_ago"] = elapsedSec;

    String json;
    serializeJson(doc, json);
    if (sendToUrl(fallsUrl, json, true)) {
      Serial.printf("[Sync] ✅ ส่งข้อมูลออฟไลน์สำเร็จ 1 รายการ (เหลือ: %d)\n",
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
      Serial.printf("[Sync] ❌ ส่งข้อมูลออฟไลน์ล้มเหลว (ลอง: %d/3)\n",
                    failedAttempts);
      if (failedAttempts >= 3) {
        Serial.println("[Sync] ⚠️ ข้ามข้อมูลรายการที่ล้มเหลวซ้ำ เพื่อไม่ให้ค้างระบบ");
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
    Serial.println("[Sync] ✅ ส่งประวัติออฟไลน์ทั้งหมดสำเร็จเรียบร้อย");
  } else {
    Serial.printf("[Sync] ⚠️ ยังมีข้อมูลออฟไลน์คงค้าง %d รายการ\n", offlineEventCount);
  }
}

// ==================== ระบบจัดการ Wi-Fi และเชื่อมต่อ ====================
void setupWiFi() {
  WiFiManager wm;
  wm.setConfigPortalTimeout(180);
  wm.setConnectTimeout(20);

  Serial.println("[WiFi] กำลังเชื่อมต่อเครือข่าย...");
  if (!wm.autoConnect(WM_AP_NAME)) {
    Serial.println("[WiFi] ไม่สามารถเชื่อมต่อได้ ทำงานในโหมดออฟไลน์ (AP: " +
                   String(WM_AP_NAME) + ")");
  } else {
    Serial.printf("[WiFi] เชื่อมต่อสำเร็จ! IP: %s (SSID: %s, BSSID: %s)\n",
                  WiFi.localIP().toString().c_str(), WiFi.SSID().c_str(),
                  WiFi.BSSIDstr().c_str());
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
  msg += "👤 อุปกรณ์: CareGuardH2\n";
  msg += "⚠️ รูปแบบการล้ม: " + String(rec.fall_type_name) + "\n";
  msg += "🎯 ความมั่นใจ AI: " + String((int)(rec.confidence * 100)) + "%\n";
  msg += "💥 ระดับความรุนแรง: " +
         String(rec.severity > 0.7
                    ? "สูง (High)"
                    : (rec.severity > 0.4 ? "ปานกลาง (Medium)" : "ต่ำ (Low)")) +
         "\n";
  double sendLat = rec.lat;
  double sendLng = rec.lng;
  if (sendLat == 0.0 || (sendLat > 13.0 && sendLat < 14.2) ||
      (sendLat > 14.8 && sendLat < 15.3) ||
      (fabs(sendLat - 16.430400) < 0.0001 && fabs(sendLng - 102.863600) < 0.0001)) {
    sendLat = preferences.getDouble("home_lat", 16.428000);
    sendLng = preferences.getDouble("home_lng", 102.861700);
  }
  msg += "📍 พิกัด: https://maps.google.com/?q=" + String(sendLat, 6) + "," +
         String(sendLng, 6) ;

  bot.sendMessage(target, msg, "");
  Serial.println("[Telegram] ส่งแจ้งเตือนการล้มสำเร็จ");
}
