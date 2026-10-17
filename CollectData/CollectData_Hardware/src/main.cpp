/*
 * ESP32 เก็บข้อมูลเซ็นเซอร์ MPU-6050 แบบ Real-Time Streaming Dual-Core
 * โฟลเดอร์: CollectData/CollectData_Hardware
 * รองรับทั้ง:
 *   1) กำหนดเวลา SisFall มาตรฐาน (12s / 15s / 25s) และ ขยายเวลายาวพิเศษ (60s)
 *   2) โหมดกดเริ่ม - กดหยุดแบบไม่จำกัดเวลา (Continuous Streaming: 1 นาที, 5 นาที, 10 นาที+)
 *
 * สถาปัตยกรรม Streaming Batch FreeRTOS Dual-Core:
 * ====================================================================================
 * - Core 1 (sensorCollectTask - Priority 3 High):
 *     - อ่านเซ็นเซอร์ MPU-6050 ที่ 50Hz (ทุก 20ms) ด้วย vTaskDelayUntil
 *     - รวบรวมข้อมูลเป็น Batch ละ 50 จุด (1 วินาที = 1,400 ไบต์)
 *     - ส่ง Batch ให้ Core 0 ผ่าน FreeRTOS Queue ทันที
 *     - ใช้ RAM รวมเพียง ~10KB (แก้ปัญหา DRAM Overflow ได้ 100%)
 *
 * - Core 0 (networkUploadTask - Priority 1 Normal):
 *     - รับ Batch จาก Queue แล้วส่ง HTTP POST ขึ้นเซิร์ฟเวอร์แบบ Real-Time ทีละวินาที
 *     - เช็กสัญญาณ "⏹️ กดหยุดบันทึก" จากเว็บผ่านผลตอบกลับของ receive_data.php
 *     - หาก Wi-Fi หลุด จะสปูล Batch ลง SPIFFS Flash และทยอยส่งซ่อมเมื่อเน็ตกลับมา
 */

#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <Arduino.h>
#include <ArduinoJson.h>
#include <FS.h>
#include <HTTPClient.h>
#include <SPIFFS.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <freertos/semphr.h>
#include <freertos/task.h>

// ==================== การตั้งค่า WiFi / Server ====================

#define AP_SSID "CollectData_AP"
#define AP_PASSWORD ""

const char *CHECK_CMD_URL =
    "https://www.youngza.com/IMU/CollectData_Web/api/actions.php";
const char *RECEIVE_DATA_URL =
    "https://www.youngza.com/IMU/CollectData_Web/api/receive_data.php";

String MAC_ADDRESS = "";

// ==================== การตั้งค่าฮาร์ดแวร์ ====================

#define BUZZER_PIN 4
#define LED_PIN 2

Adafruit_MPU6050 mpu;

// ==================== การตั้งค่า Sampling & Streaming Batch ====================

const int SAMPLE_RATE_MS = 20;      // 50Hz (ทุก 20ms)
const int BATCH_SIZE = 50;          // 50 จุดต่อ Batch (1 วินาที)
const int MAX_DURATION_S = 60;      // รองรับโหมดตั้งเวลาได้สูงสุด 60 วินาที
const int POOL_SIZE = 6;            // จำนวนบัฟเฟอร์ใน Free Pool (6 x 1.4KB = ~8.5KB RAM)

// Auto-Impact Thresholds
const int PRE_EVENT_SAMPLES = 50;
const float IMPACT_THRESHOLD_G = 2.5f;
const float HARD_IMPACT_THRESHOLD_G = 3.2f;
const float LOW_G_THRESHOLD = 0.75f;
const float GYRO_THRESHOLD_DPS = 200.0f;
const unsigned long FALL_PATTERN_WINDOW_MS = 800;

// ==================== โครงสร้างข้อมูล ====================

struct SensorSample {
  long timestamp_ms;
  float accel_x, accel_y, accel_z;
  float gyro_x, gyro_y, gyro_z;
};

// แพ็กเก็ตข้อมูล 1 วินาที (50 จุด) สำหรับส่งข้ามคอร์
struct BatchPacket {
  int record_id;
  int batch_index;
  int sample_count;
  bool is_final;
  SensorSample samples[BATCH_SIZE];
};

// คำสั่งเริ่มบันทึกจาก Core 0 -> Core 1
struct RecordCommand {
  int record_id;
  char category[16];
  char activity_code[24];
  char activity_name[96];
  int duration_sec;    // >0: ตั้งเวลา (เช่น 15, 25, 60 วินาที), 0: บันทึกต่อเนื่องไม่จำกัดเวลา (กดเริ่ม-กดหยุด)
  bool is_continuous;  // true: โหมดกดเริ่ม-กดหยุด
};

// Memory Pool สำหรับ BatchPacket เพื่อหลีกเลี่ยง malloc/free และไม่เปลือง DRAM
static BatchPacket packetPool[POOL_SIZE];
static bool packetInUse[POOL_SIZE];
static portMUX_TYPE poolMux = portMUX_INITIALIZER_UNLOCKED;

// Circular buffer สำหรับเก็บ 1 วินาทีก่อนกระแทก (โหมด Auto-Impact)
static SensorSample preBuffer[PRE_EVENT_SAMPLES];
static int preWriteIndex = 0;
static int preCount = 0;

// ==================== ตัวแปรสถานะระบบ ====================

enum SystemMode {
  MODE_IDLE,
  MODE_COUNTDOWN,
  MODE_RECORDING_TIMED,
  MODE_RECORDING_CONTINUOUS,
  MODE_FINISHING
};

volatile SystemMode currentMode = MODE_IDLE;
volatile int activeRecordId = 0;
volatile int activeTargetSamples = 0;     // สำหรับโหมดตั้งเวลา (0 = ไม่จำกัด)
volatile bool stopContinuousRequested = false; // เมื่อได้รับคำสั่งหยุดจากเว็บ
volatile bool isBuzzerBusy = false;
volatile bool autoFallTriggerEnabled = false;

// FreeRTOS Handles
TaskHandle_t sensorTaskHandle = NULL;
TaskHandle_t networkTaskHandle = NULL;
QueueHandle_t commandQueue = NULL;      // Core 0 -> Core 1
QueueHandle_t batchUploadQueue = NULL;  // Core 1 -> Core 0 (ส่ง BatchPacket*)

// ==================== ฟังก์ชัน Memory Pool ====================

BatchPacket* getFreePacket() {
  portENTER_CRITICAL(&poolMux);
  for (int i = 0; i < POOL_SIZE; i++) {
    if (!packetInUse[i]) {
      packetInUse[i] = true;
      portEXIT_CRITICAL(&poolMux);
      packetPool[i].sample_count = 0;
      packetPool[i].is_final = false;
      return &packetPool[i];
    }
  }
  portEXIT_CRITICAL(&poolMux);
  return NULL; // Pool เต็ม
}

void releasePacket(BatchPacket* p) {
  if (!p) return;
  portENTER_CRITICAL(&poolMux);
  for (int i = 0; i < POOL_SIZE; i++) {
    if (&packetPool[i] == p) {
      packetInUse[i] = false;
      break;
    }
  }
  portEXIT_CRITICAL(&poolMux);
}

// ==================== ประกาศฟังก์ชัน ====================

void setupWiFi();
void setupMPU6050();
void setupSPIFFS();

void sensorCollectTask(void *pvParameters); // Core 1
void networkUploadTask(void *pvParameters); // Core 0

SensorSample readMPUSample(long timestamp_ms);
void storePreSample(const SensorSample &sample);
float calcAMagG(const SensorSample &sample);
float calcGyroMagnitudeDps(const SensorSample &sample);
bool shouldTriggerAutoFall(float aMagG, float gyroDps, unsigned long now,
                           unsigned long lastLowGTime, unsigned long lastHighGyroTime);

void playRaceCountdown();
void playFinishAlert();
void playSuccessMelody();
void beepTone(int duration_ms, int pause_ms);

bool pollServerCommand(RecordCommand &outCmd);
bool uploadBatchToServer(BatchPacket *pkt, bool &outStopRequested);
void spoolBatchToSpiffs(BatchPacket *pkt);
int countSpiffsBatches();
void syncSpiffsBatches();

void handleWiFiIndicator(unsigned long now);
void handleSerialCommands();
float round2(float val);

// ==================== SETUP ====================

void setup() {
  Serial.begin(115200);
  delay(800);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  Serial.println("\n==================================================================");
  Serial.println("  CollectData_Hardware — Real-Time Streaming Dual-Core Logger");
  Serial.println("  Features: 12-60s Timed Buffer + Unlimited Start-Stop Continuous");
  Serial.println("  Core 1: 50Hz Real-Time Sensor Streamer (RAM ~10KB)");
  Serial.println("  Core 0: Live Chunked HTTP POST + Web Stop Signal + SPIFFS Cache");
  Serial.println("==================================================================");

  for (int i = 0; i < POOL_SIZE; i++) {
    packetInUse[i] = false;
  }

  setupSPIFFS();
  setupMPU6050();
  setupWiFi();

  MAC_ADDRESS = WiFi.macAddress();
  Serial.printf("[อุปกรณ์] MAC Address: %s\n", MAC_ADDRESS.c_str());

  commandQueue = xQueueCreate(4, sizeof(RecordCommand));
  batchUploadQueue = xQueueCreate(POOL_SIZE, sizeof(BatchPacket*));

  // สร้าง Task บน Core 1: อ่านเซ็นเซอร์ 50Hz (Priority 3)
  xTaskCreatePinnedToCore(
      sensorCollectTask,
      "SensorCollectTask",
      4096,
      NULL,
      3,
      &sensorTaskHandle,
      1 // Core 1
  );

  // สร้าง Task บน Core 0: สตรีมข้อมูลขึ้นเซิร์ฟเวอร์ (Priority 1)
  xTaskCreatePinnedToCore(
      networkUploadTask,
      "NetworkUploadTask",
      8192,
      NULL,
      1,
      &networkTaskHandle,
      0 // Core 0
  );

  Serial.println("[พร้อม] ระบบ FreeRTOS Streaming เริ่มทำงานทั้ง 2 คอร์สมบูรณ์\n");
}

// ==================== LOOP หลัก ====================

void loop() {
  vTaskDelay(pdMS_TO_TICKS(1000));
}

// ============================================================================
// CORE 1 TASK: อ่านเซ็นเซอร์ 50Hz และส่งเป็น Batch สตรีมให้ Core 0
// ============================================================================

void sensorCollectTask(void *pvParameters) {
  TickType_t xLastWakeTime = xTaskGetTickCount();
  const TickType_t xFrequency = pdMS_TO_TICKS(SAMPLE_RATE_MS); // 20ms = 50Hz

  RecordCommand currentCmd;
  BatchPacket *activePacket = NULL;
  int currentBatchIndex = 0;
  int totalSamplesRecorded = 0;
  unsigned long recordingStartTime = 0;
  unsigned long lastLowGTime = 0;
  unsigned long lastHighGyroTime = 0;

  for (;;) {
    vTaskDelayUntil(&xLastWakeTime, xFrequency);
    unsigned long now = millis();

    // 1. ตรวจสอบคำสั่งเริ่มบันทึกใหม่จาก Core 0
    if (currentMode == MODE_IDLE) {
      if (xQueueReceive(commandQueue, &currentCmd, 0) == pdTRUE) {
        activeRecordId = currentCmd.record_id;
        stopContinuousRequested = false;
        currentBatchIndex = 0;
        totalSamplesRecorded = 0;

        if (currentCmd.is_continuous || currentCmd.duration_sec <= 0) {
          activeTargetSamples = 0; // 0 = โหมดต่อเนื่องไม่จำกัดเวลา (กดเริ่ม-กดหยุด)
        } else {
          int dur = currentCmd.duration_sec;
          if (dur > MAX_DURATION_S) dur = MAX_DURATION_S;
          activeTargetSamples = (dur * 1000) / SAMPLE_RATE_MS;
        }

        currentMode = MODE_COUNTDOWN;
      }
    }

    switch (currentMode) {

      // ----------------------------------------------------------------------
      // MODE_IDLE: อ่านค่า MPU6050 ลง Circular Buffer ก่อนล้ม (Pre-buffer 1s)
      // ----------------------------------------------------------------------
      case MODE_IDLE: {
        SensorSample s = readMPUSample((long)now);
        storePreSample(s);

        float aMagG = calcAMagG(s);
        float gyroDps = calcGyroMagnitudeDps(s);
        if (aMagG <= LOW_G_THRESHOLD) lastLowGTime = now;
        if (gyroDps >= GYRO_THRESHOLD_DPS) lastHighGyroTime = now;

        // Auto Fall Trigger (ถ้าเปิดใช้งาน)
        if (autoFallTriggerEnabled &&
            shouldTriggerAutoFall(aMagG, gyroDps, now, lastLowGTime, lastHighGyroTime)) {
          Serial.printf("\n[Core 1] 🚨 Auto Impact Trigger: %.2fG (Gyro %.1f dps)\n", aMagG, gyroDps);
          // ทำงานต่อเนื่องแบบ Auto Fall
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_COUNTDOWN: ส่งสัญญาณเสียง 3... 2... 1... GO!
      // ----------------------------------------------------------------------
      case MODE_COUNTDOWN: {
        if (activeTargetSamples == 0) {
          Serial.printf("\n[Core 1] 🔴 เริ่มโหมดบันทึกต่อเนื่องไม่จำกัดเวลา (Record #%d - %s) [กดเริ่ม - กดหยุด]\n",
                        activeRecordId, currentCmd.activity_code);
        } else {
          Serial.printf("\n[Core 1] ⏱️ เริ่มโหมดตั้งเวลา %d วินาที (%d จุด) [Record #%d - %s]\n",
                        activeTargetSamples / 50, activeTargetSamples, activeRecordId, currentCmd.activity_code);
        }

        playRaceCountdown();

        activePacket = getFreePacket();
        if (activePacket) {
          activePacket->record_id = activeRecordId;
          activePacket->batch_index = currentBatchIndex;
          activePacket->sample_count = 0;
          activePacket->is_final = false;
        }

        recordingStartTime = millis();
        totalSamplesRecorded = 0;
        digitalWrite(LED_PIN, HIGH);

        if (activeTargetSamples == 0) {
          currentMode = MODE_RECORDING_CONTINUOUS;
        } else {
          currentMode = MODE_RECORDING_TIMED;
        }

        xLastWakeTime = xTaskGetTickCount();
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_RECORDING_TIMED / CONTINUOUS: อ่านค่า 50Hz แล้วแพ็กส่งทีละ Batch
      // ----------------------------------------------------------------------
      case MODE_RECORDING_TIMED:
      case MODE_RECORDING_CONTINUOUS: {
        long elapsed_ms = (long)(millis() - recordingStartTime);
        SensorSample s = readMPUSample(elapsed_ms);

        if (!activePacket) {
          activePacket = getFreePacket();
          if (activePacket) {
            activePacket->record_id = activeRecordId;
            activePacket->batch_index = currentBatchIndex;
            activePacket->sample_count = 0;
            activePacket->is_final = false;
          }
        }

        if (activePacket) {
          activePacket->samples[activePacket->sample_count++] = s;
          totalSamplesRecorded++;

          // เมื่อเก็บครบ 50 จุด (1 วินาที)
          if (activePacket->sample_count >= BATCH_SIZE) {
            bool timeUp = (currentMode == MODE_RECORDING_TIMED &&
                           totalSamplesRecorded >= activeTargetSamples);
            bool stopRequested = stopContinuousRequested;

            if (timeUp || stopRequested) {
              activePacket->is_final = true;
            }

            // ส่ง Batch ให้ Core 0 นำไปอัปโหลดขึ้นเว็บทันที
            if (xQueueSend(batchUploadQueue, &activePacket, 0) == pdTRUE) {
              activePacket = NULL; // Core 0 รับไปแล้ว
              currentBatchIndex++;
            } else {
              // ถ้า Queue เต็ม Core 1 สปูลลง Flash ทันที
              spoolBatchToSpiffs(activePacket);
              releasePacket(activePacket);
              activePacket = NULL;
              currentBatchIndex++;
            }

            Serial.printf("  [Core 1] สตรีมแล้ว %d จุด (%d วินาที)...\n",
                          totalSamplesRecorded, totalSamplesRecorded / 50);

            if (timeUp || stopRequested) {
              currentMode = MODE_FINISHING;
            }
          }
        }

        // ตรวจสอบกรณีถูกสั่งหยุดกลางคันระหว่างยังไม่ครบ 50 จุด
        if (stopContinuousRequested && currentMode != MODE_FINISHING) {
          currentMode = MODE_FINISHING;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_FINISHING: ส่งจุดที่เหลือ (ถ้ามี) พร้อมแนบ is_final=true แล้วส่งเสียงจบ
      // ----------------------------------------------------------------------
      case MODE_FINISHING: {
        if (activePacket && activePacket->sample_count > 0) {
          activePacket->is_final = true;
          if (xQueueSend(batchUploadQueue, &activePacket, 0) != pdTRUE) {
            spoolBatchToSpiffs(activePacket);
            releasePacket(activePacket);
          }
          activePacket = NULL;
        }

        digitalWrite(LED_PIN, LOW);
        Serial.printf("[Core 1] 🏁 บันทึกเสร็จสิ้น! รวมทั้งหมด %d จุดข้อมูล -> ส่งสัญญาณเสียงสิ้นสุด...\n",
                      totalSamplesRecorded);
        playFinishAlert();

        activeRecordId = 0;
        currentMode = MODE_IDLE;
        xLastWakeTime = xTaskGetTickCount();
        Serial.println("[Core 1] พร้อมรับคำสั่งรอบถัดไป\n");
        break;
      }
    }
  }
}

// ============================================================================
// CORE 0 TASK: รับ Batch แล้วส่ง HTTP POST ขึ้นเว็บแบบ Live Real-Time
// ============================================================================

void networkUploadTask(void *pvParameters) {
  unsigned long lastPollTime = 0;
  unsigned long lastWiFiCheck = 0;
  unsigned long lastSpiffsSync = 0;

  for (;;) {
    unsigned long now = millis();

    // 1. รับ Batch จาก Core 1 แล้วยิง HTTP POST ขึ้นเว็บทันที
    BatchPacket *pkt = NULL;
    if (xQueueReceive(batchUploadQueue, &pkt, 0) == pdTRUE && pkt != NULL) {
      bool stopFromWeb = false;
      bool sentOk = false;

      if (WiFi.status() == WL_CONNECTED) {
        sentOk = uploadBatchToServer(pkt, stopFromWeb);
      }

      if (stopFromWeb) {
        Serial.println("[Core 0] 🛑 ได้รับสัญญาณ [หยุดบันทึก] จากหน้าเว็บ!");
        stopContinuousRequested = true;
      }

      if (!sentOk) {
        Serial.printf("[Core 0] ⚠️ ส่ง Batch #%d ล้มเหลว -> สปูลลง SPIFFS Flash\n", pkt->batch_index);
        spoolBatchToSpiffs(pkt);
      } else if (pkt->is_final) {
        playSuccessMelody();
      }

      releasePacket(pkt);
    }

    // 2. ซิงค์ข้อมูลที่ค้างอยู่ใน Flash เมื่อเน็ตพร้อม
    if (WiFi.status() == WL_CONNECTED && now - lastSpiffsSync >= 4000) {
      lastSpiffsSync = now;
      if (countSpiffsBatches() > 0) {
        syncSpiffsBatches();
      }
    }

    // 3. ถามคำสั่งเริ่มบันทึกจากหน้าเว็บทุก 1.5 วินาที (เฉพาะเมื่อ Core 1 ว่าง)
    if (currentMode == MODE_IDLE && WiFi.status() == WL_CONNECTED &&
        (now - lastPollTime >= 1500)) {
      lastPollTime = now;
      RecordCommand cmd;
      if (pollServerCommand(cmd)) {
        Serial.printf("[Core 0] 📥 คำสั่งใหม่: Record #%d | ท่า: %s | โหมด: %s\n",
                      cmd.record_id, cmd.activity_code,
                      cmd.is_continuous ? "🔴 ต่อเนื่องไม่จำกัดเวลา (กดเริ่ม-กดหยุด)" : (String(cmd.duration_sec) + " วินาที").c_str());
        xQueueSend(commandQueue, &cmd, 0);
      }
    }

    // 4. ตรวจสอบการเชื่อมต่อ Wi-Fi ทุก 15 วินาที
    if (now - lastWiFiCheck >= 15000) {
      lastWiFiCheck = now;
      if (WiFi.status() != WL_CONNECTED) {
        WiFi.reconnect();
      }
    }

    // 5. ไฟสถานะ & คำสั่ง Serial
    if (currentMode == MODE_IDLE && !isBuzzerBusy) {
      handleWiFiIndicator(now);
    }
    handleSerialCommands();

    vTaskDelay(pdMS_TO_TICKS(15));
  }
}

// ============================================================================
// การส่งข้อมูลขึ้นเซิร์ฟเวอร์ & สปูล SPIFFS Flash
// ============================================================================

bool uploadBatchToServer(BatchPacket *pkt, bool &outStopRequested) {
  outStopRequested = false;
  if (!pkt || pkt->sample_count <= 0) return true;

  DynamicJsonDocument doc(pkt->sample_count * 130 + 350);
  doc["mac_address"] = MAC_ADDRESS;
  if (pkt->record_id > 0) {
    doc["record_id"] = pkt->record_id;
  }
  if (pkt->is_final) {
    doc["is_final"] = true;
  }

  JsonArray dataArr = doc.createNestedArray("data");
  for (int i = 0; i < pkt->sample_count; i++) {
    JsonObject obj = dataArr.createNestedObject();
    obj["t"] = pkt->samples[i].timestamp_ms;
    obj["ax"] = round2(pkt->samples[i].accel_x);
    obj["ay"] = round2(pkt->samples[i].accel_y);
    obj["az"] = round2(pkt->samples[i].accel_z);
    obj["gx"] = round2(pkt->samples[i].gyro_x);
    obj["gy"] = round2(pkt->samples[i].gyro_y);
    obj["gz"] = round2(pkt->samples[i].gyro_z);
  }

  String jsonPayload;
  serializeJson(doc, jsonPayload);

  HTTPClient http;
  http.begin(RECEIVE_DATA_URL);
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(4500);

  int httpCode = http.POST(jsonPayload);
  if (httpCode == HTTP_CODE_OK) {
    String resp = http.getString();
    http.end();

    DynamicJsonDocument respDoc(512);
    deserializeJson(respDoc, resp);
    if (respDoc["stop_requested"] == true) {
      outStopRequested = true;
    }
    return true;
  } else {
    http.end();
    return false;
  }
}

void spoolBatchToSpiffs(BatchPacket *pkt) {
  if (!pkt) return;
  for (int i = 0; i < 20; i++) {
    String p = "/batch_" + String(i) + ".bin";
    if (!SPIFFS.exists(p)) {
      File f = SPIFFS.open(p, FILE_WRITE);
      if (f) {
        f.write((const uint8_t*)pkt, sizeof(BatchPacket));
        f.close();
      }
      break;
    }
  }
}

int countSpiffsBatches() {
  int count = 0;
  for (int i = 0; i < 20; i++) {
    String p = "/batch_" + String(i) + ".bin";
    if (SPIFFS.exists(p)) count++;
  }
  return count;
}

void syncSpiffsBatches() {
  for (int i = 0; i < 20; i++) {
    String p = "/batch_" + String(i) + ".bin";
    if (!SPIFFS.exists(p)) continue;

    File f = SPIFFS.open(p, FILE_READ);
    if (!f) continue;

    BatchPacket pkt;
    if (f.read((uint8_t*)&pkt, sizeof(BatchPacket)) == sizeof(BatchPacket)) {
      f.close();
      bool dummyStop = false;
      if (uploadBatchToServer(&pkt, dummyStop)) {
        SPIFFS.remove(p);
      }
    } else {
      f.close();
      SPIFFS.remove(p);
    }
    break;
  }
}

bool pollServerCommand(RecordCommand &outCmd) {
  HTTPClient http;
  String url = String(CHECK_CMD_URL) + "?action=check_command&mac=" + MAC_ADDRESS;

  http.begin(url);
  http.setTimeout(3500);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    http.end();

    DynamicJsonDocument doc(1024);
    DeserializationError err = deserializeJson(doc, payload);
    if (!err && doc["success"] == true && doc["has_command"] == true) {
      memset(&outCmd, 0, sizeof(RecordCommand));
      outCmd.record_id = doc["record_id"] | 0;
      strncpy(outCmd.category, doc["category"] | "adl", sizeof(outCmd.category) - 1);
      strncpy(outCmd.activity_code, doc["activity_code"] | "D01", sizeof(outCmd.activity_code) - 1);
      strncpy(outCmd.activity_name, doc["activity_name"] | "บันทึกข้อมูล", sizeof(outCmd.activity_name) - 1);
      outCmd.duration_sec = doc["duration_sec"] | 15;
      outCmd.is_continuous = (doc["is_continuous"] == true || outCmd.duration_sec == 0);
      return (outCmd.record_id > 0);
    }
  } else {
    http.end();
  }
  return false;
}

// ============================================================================
// ฟังก์ชันเซ็นเซอร์ & สัญญาณเสียง
// ============================================================================

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

void storePreSample(const SensorSample &sample) {
  preBuffer[preWriteIndex] = sample;
  preWriteIndex = (preWriteIndex + 1) % PRE_EVENT_SAMPLES;
  if (preCount < PRE_EVENT_SAMPLES) preCount++;
}

float calcAMagG(const SensorSample &sample) {
  float mag = sqrtf(sample.accel_x * sample.accel_x +
                    sample.accel_y * sample.accel_y +
                    sample.accel_z * sample.accel_z);
  return mag / 9.81f;
}

float calcGyroMagnitudeDps(const SensorSample &sample) {
  float gyroRad = sqrtf(sample.gyro_x * sample.gyro_x +
                        sample.gyro_y * sample.gyro_y +
                        sample.gyro_z * sample.gyro_z);
  return gyroRad * 180.0f / PI;
}

bool shouldTriggerAutoFall(float aMagG, float gyroDps, unsigned long now,
                           unsigned long lastLowGTime, unsigned long lastHighGyroTime) {
  bool impact = (aMagG >= IMPACT_THRESHOLD_G);
  bool hardImpact = (aMagG >= HARD_IMPACT_THRESHOLD_G);
  bool hadLowG = (lastLowGTime > 0) && ((now - lastLowGTime) <= FALL_PATTERN_WINDOW_MS);
  bool hadHighGyro = (lastHighGyroTime > 0) && ((now - lastHighGyroTime) <= FALL_PATTERN_WINDOW_MS);
  if (hardImpact) return true;
  if (impact && (hadLowG || hadHighGyro)) return true;
  return false;
}

void beepTone(int duration_ms, int pause_ms) {
  isBuzzerBusy = true;
  digitalWrite(BUZZER_PIN, HIGH);
  digitalWrite(LED_PIN, HIGH);
  vTaskDelay(pdMS_TO_TICKS(duration_ms));
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(LED_PIN, LOW);
  if (pause_ms > 0) {
    vTaskDelay(pdMS_TO_TICKS(pause_ms));
  }
  isBuzzerBusy = false;
}

void playRaceCountdown() {
  Serial.println("[Countdown] 3 - 2 - 1 - GO!");
  beepTone(180, 820);
  beepTone(180, 820);
  beepTone(180, 820);
  beepTone(650, 50);
}

void playFinishAlert() {
  beepTone(200, 120);
  beepTone(200, 120);
  beepTone(800, 100);
}

void playSuccessMelody() {
  beepTone(90, 70);
  beepTone(90, 70);
}

// ============================================================================
// ตั้งค่าระบบ (SPIFFS, MPU6050, WiFi, Serial)
// ============================================================================

void setupSPIFFS() {
  if (SPIFFS.begin(true)) {
    Serial.printf("[SPIFFS] เริ่มต้นสำเร็จ (ใช้ไป: %u / %u bytes)\n",
                  SPIFFS.usedBytes(), SPIFFS.totalBytes());
  }
}

void setupMPU6050() {
  if (!mpu.begin()) {
    Serial.println("[MPU6050] ❌ ไม่พบเซ็นเซอร์ MPU-6050!");
    while (1) {
      digitalWrite(LED_PIN, !digitalRead(LED_PIN));
      delay(200);
    }
  }
  mpu.setAccelerometerRange(MPU6050_RANGE_16_G);
  mpu.setGyroRange(MPU6050_RANGE_2000_DEG);
  mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
  Serial.println("[MPU6050] เริ่มต้นสำเร็จ (±16G, ±2000°/s, 21Hz)");
}

void setupWiFi() {
  WiFi.mode(WIFI_STA);
  WiFiManager wm;
  wm.setConfigPortalTimeout(180);
  if (wm.autoConnect(AP_SSID, AP_PASSWORD)) {
    Serial.printf("[WiFi] เชื่อมต่อสำเร็จ: %s (IP: %s)\n",
                  WiFi.SSID().c_str(), WiFi.localIP().toString().c_str());
  }
}

void handleWiFiIndicator(unsigned long now) {
  static unsigned long lastBlink = 0;
  if (WiFi.status() == WL_CONNECTED) {
    digitalWrite(LED_PIN, HIGH);
    digitalWrite(BUZZER_PIN, LOW);
  } else {
    if (now - lastBlink >= 500) {
      lastBlink = now;
      digitalWrite(LED_PIN, !digitalRead(LED_PIN));
    }
  }
}

void handleSerialCommands() {
  if (!Serial.available()) return;
  String cmd = Serial.readStringUntil('\n');
  cmd.trim();

  if (cmd == "stop") {
    Serial.println("[Serial] สั่งหยุดบันทึกโหมดต่อเนื่องทันที!");
    stopContinuousRequested = true;
  } else if (cmd == "status" || cmd == "s") {
    Serial.println("\n================ SYSTEM STATUS ================");
    Serial.printf("MAC Address   : %s\n", MAC_ADDRESS.c_str());
    Serial.printf("WiFi Status   : %s\n", WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DISCONNECTED");
    Serial.printf("Mode          : %d (0=IDLE, 1=COUNTDOWN, 2=TIMED, 3=CONTINUOUS, 4=FINISHING)\n", (int)currentMode);
    Serial.printf("Target Samples: %d\n", activeTargetSamples);
    Serial.printf("SPIFFS Batches: %d\n", countSpiffsBatches());
    Serial.println("===============================================\n");
  }
}

float round2(float val) {
  return roundf(val * 100.0f) / 100.0f;
}
