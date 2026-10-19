/*
 * ESP32 เก็บข้อมูลเซ็นเซอร์ MPU-6050 แบบ Real-Time Dual-Core FreeRTOS
 * โฟลเดอร์: CollectData/CollectData_Hardware
 *
 * ข้อกำหนดระบบใหม่:
 * ====================================================================================
 * 1) ท่าล้ม (Fall: F01 - F05):
 *    - รับคำสั่งจาก Web -> สัญญาณเสียง "3... 2... 1... GO!"
 *    - รอตรวจจับการกระแทก (Arm Impact Detector พร้อม Circular Pre-buffer 1.0 วินาที = 50 จุด)
 *    - เมื่อเกิดการล้ม: ดึง 50 จุดก่อนล้ม (1s) + บันทึกต่อ 50 จุดหลังล้ม (1s) = รวม 100 จุด (2.0 วินาที)
 *    - ส่งสัญญาณเสียงจบ (Finish Tone) และอัปโหลดขึ้นเซิร์ฟเวอร์
 *
 * 2) ท่ากิจวัตรปกติ (ADL: D01 - D06):
 *    - สั่งจาก Web หรือ กดปุ่มที่ขา D18 บนบอร์ด ESP32
 *    - สัญญาณเสียง "3... 2... 1... GO!"
 *    - วินาทีที่ 0 - 1.0 (50 จุด): ยืนเตรียมตัวนิ่งๆ -> ลำโพงดัง Beep! (บอกให้เริ่มทำท่า)
 *    - วินาทีที่ 1.0 - 9.0 (400 จุด): ทำท่าทางตามกำหนด 8 วินาที -> ลำโพงดัง Beep! (บอกให้กลับมาท่ายืน)
 *    - วินาทีที่ 9.0 - 10.0 (50 จุด): ยืนนิ่งรอ 1 วินาที -> ลำโพงดังเสียงแจ้งเตือนเสร็จสิ้น
 *    - รวมเกิดขึ้นใน 10.0 วินาที (500 จุด)
 *
 * สถาปัตยกรรม Dual-Core FreeRTOS:
 * - Core 1: อ่านเซ็นเซอร์ MPU-6050 ที่ 50Hz แบบ Real-Time ไม่สะดุดด้วย Non-blocking Buzzer
 * - Core 0: สตรีมข้อมูลขึ้นเซิร์ฟเวอร์แบบ Real-Time, จัดการ Wi-Fi, สปูล SPIFFS Flash ออฟไลน์
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

#define BUZZER_PIN 5
#define LED_PIN 2
#define BUTTON_PIN 18 // ปุ่ม D18 สำหรับกดเริ่มบันทึกท่าทางด้วยมือ

Adafruit_MPU6050 mpu;

// ==================== การตั้งค่า Sampling & Streaming Batch ====================

const int SAMPLE_RATE_MS = 20;      // 50Hz (ทุก 20ms)
const int BATCH_SIZE = 50;          // 50 จุดต่อ Batch (1 วินาที)
const int POOL_SIZE = 6;            // จำนวนบัฟเฟอร์ใน Free Pool (6 x 1.4KB = ~8.5KB RAM)

// Auto-Impact Thresholds สำหรับท่าล้ม (Fall Mode)
const int PRE_EVENT_SAMPLES = 50;   // 1 วินาทีก่อนกระแทก (50 จุด)
const int POST_EVENT_SAMPLES = 50;  // 1 วินาทีหลังกระแทก (50 จุด)
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

// แพ็กเก็ตข้อมูลสำหรับส่งข้ามคอร์
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
  int duration_sec;    // Fall = 2, ADL = 10
  int target_samples;  // Fall = 100, ADL = 500
  bool is_fall;        // true: ท่าล้ม 2s (1s ก่อนล้ม + 1s หลังล้ม), false: ADL 10s
  bool is_continuous;
};

// Memory Pool สำหรับ BatchPacket
static BatchPacket packetPool[POOL_SIZE];
static bool packetInUse[POOL_SIZE];
static portMUX_TYPE poolMux = portMUX_INITIALIZER_UNLOCKED;

// Circular buffer สำหรับเก็บ 1 วินาทีก่อนกระแทก (50 จุด)
static SensorSample preBuffer[PRE_EVENT_SAMPLES];
static int preWriteIndex = 0;
static int preCount = 0;

// ==================== ตัวแปรสถานะระบบ ====================

enum SystemMode {
  MODE_IDLE,
  MODE_COUNTDOWN,
  MODE_WAIT_FALL_IMPACT,    // รอตรวจจับการล้ม (เก็บ Circular Buffer 50 จุด 1s ก่อนกระแทก)
  MODE_RECORDING_POST_FALL, // เก็บ 50 จุด (1s) หลังกระแทก รวมเป็น 100 จุด (2.0s)
  MODE_RECORDING_ADL,       // ยืน 1s (beep) -> ทำท่า 8s (beep) -> ยืน 1s (finish tone) รวม 500 จุด (10.0s)
  MODE_FINISHING
};

volatile SystemMode currentMode = MODE_IDLE;
volatile int activeRecordId = 0;
volatile bool stopContinuousRequested = false;
volatile bool isBuzzerBusy = false;
volatile unsigned long asyncBuzzerOffTime = 0;

// FreeRTOS Handles
TaskHandle_t sensorTaskHandle = NULL;
TaskHandle_t networkTaskHandle = NULL;
QueueHandle_t commandQueue = NULL;      // Core 0 -> Core 1
QueueHandle_t batchUploadQueue = NULL;  // Core 1 -> Core 0

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
void startBeepAsync(int duration_ms);
void checkAsyncBuzzer();

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

  pinMode(BUTTON_PIN, INPUT_PULLUP); // ปุ่ม D18 กดบันทึก ADL

  Serial.println("\n==================================================================");
  Serial.println("  CollectData_Hardware — Dual-Core FreeRTOS Sensor Logger");
  Serial.println("  1) ท่าล้ม (F01-F05): รอตรวจจับแรงกระแทก -> หน้า 1s + หลัง 1s = รวม 2s (100 จุด)");
  Serial.println("  2) ท่า ADL (D01-D06): สั่งเว็บ/ปุ่ม D18 -> ยืน 1s -> ท่า 8s -> ยืน 1s = รวม 10s (500 จุด)");
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

  // สร้าง Task บน Core 1: อ่านเซ็นเซอร์ 50Hz (Priority 3 สูงสุด)
  xTaskCreatePinnedToCore(
      sensorCollectTask,
      "SensorCollectTask",
      4096,
      NULL,
      3,
      &sensorTaskHandle,
      1 // Core 1
  );

  // สร้าง Task บน Core 0: สตรีมข้อมูลขึ้นเว็บ + Wi-Fi (Priority 1)
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
// CORE 1 TASK: อ่านเซ็นเซอร์ 50Hz จัดการจังหวะ Fall 2s และ ADL 10s
// ============================================================================

void sensorCollectTask(void *pvParameters) {
  TickType_t xLastWakeTime = xTaskGetTickCount();
  const TickType_t xFrequency = pdMS_TO_TICKS(SAMPLE_RATE_MS); // 20ms = 50Hz

  RecordCommand currentCmd;
  BatchPacket *activePacket = NULL;
  int currentBatchIndex = 0;
  int adlSampleCount = 0;
  int postFallSampleCount = 0;

  unsigned long lastLowGTime = 0;
  unsigned long lastHighGyroTime = 0;
  unsigned long waitFallStartTime = 0;

  // สำหรับปุ่มกด D18
  int lastButtonState = HIGH;
  unsigned long lastButtonDebounce = 0;

  for (;;) {
    vTaskDelayUntil(&xLastWakeTime, xFrequency);
    unsigned long now = millis();
    checkAsyncBuzzer(); // ดับเสียงปี๊บแบบ Non-blocking

    // 1. ตรวจสอบคำสั่งเริ่มบันทึกใหม่จาก Core 0
    if (currentMode == MODE_IDLE) {
      if (xQueueReceive(commandQueue, &currentCmd, 0) == pdTRUE) {
        activeRecordId = currentCmd.record_id;
        stopContinuousRequested = false;
        currentBatchIndex = 0;
        adlSampleCount = 0;
        postFallSampleCount = 0;
        currentMode = MODE_COUNTDOWN;
      }

      // ตรวจสอบการกดปุ่ม D18 บนบอร์ดเพื่อเริ่มบันทึก ADL 10 วินาที
      int btnVal = digitalRead(BUTTON_PIN);
      if (btnVal != lastButtonState) {
        lastButtonDebounce = now;
      }
      if ((now - lastButtonDebounce) > 50) {
        if (btnVal == LOW && lastButtonState == HIGH) {
          Serial.println("\n[ปุ่ม D18] 🔘 ตรวจพบการกดปุ่ม D18 บนบอร์ด -> เริ่มรอบ ADL 10 วินาที!");
          memset(&currentCmd, 0, sizeof(RecordCommand));
          currentCmd.record_id = activeRecordId; // 0 หรือ ID ที่รออยู่
          strncpy(currentCmd.category, "adl", sizeof(currentCmd.category) - 1);
          strncpy(currentCmd.activity_code, "D01", sizeof(currentCmd.activity_code) - 1);
          strncpy(currentCmd.activity_name, "ADL (D18 Trigger)", sizeof(currentCmd.activity_name) - 1);
          currentCmd.duration_sec = 10;
          currentCmd.target_samples = 500;
          currentCmd.is_fall = false;
          adlSampleCount = 0;
          postFallSampleCount = 0;
          currentMode = MODE_COUNTDOWN;
        }
      }
      lastButtonState = btnVal;
    }

    switch (currentMode) {

      // ----------------------------------------------------------------------
      // MODE_IDLE: อัปเดต Circular Buffer 50 จุด (1s) อย่างต่อเนื่อง
      // ----------------------------------------------------------------------
      case MODE_IDLE: {
        SensorSample s = readMPUSample((long)now);
        storePreSample(s);

        float aMagG = calcAMagG(s);
        float gyroDps = calcGyroMagnitudeDps(s);
        if (aMagG <= LOW_G_THRESHOLD) lastLowGTime = now;
        if (gyroDps >= GYRO_THRESHOLD_DPS) lastHighGyroTime = now;
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_COUNTDOWN: ส่งสัญญาณเสียง 3... 2... 1... GO!
      // ----------------------------------------------------------------------
      case MODE_COUNTDOWN: {
        if (currentCmd.is_fall) {
          Serial.printf("\n[Core 1] 🚨 เริ่มโหมดตรวจจับการล้ม (Record #%d - %s) -> นับถอยหลัง 3 2 1...\n",
                        activeRecordId, currentCmd.activity_code);
        } else {
          Serial.printf("\n[Core 1] ⏱️ เริ่มโหมด ADL 10 วินาที (Record #%d - %s) -> นับถอยหลัง 3 2 1...\n",
                        activeRecordId, currentCmd.activity_code);
        }

        playRaceCountdown();
        digitalWrite(LED_PIN, HIGH);

        if (currentCmd.is_fall) {
          // เตรียมพร้อมรอการล้ม (เคลียร์ตัวนับบัฟเฟอร์ก่อนล้ม)
          preCount = 0;
          preWriteIndex = 0;
          waitFallStartTime = millis();
          currentMode = MODE_WAIT_FALL_IMPACT;
          Serial.println("[Core 1] 🛡️ เข้าสู่โหมดตรวจจับแรงกระแทก (เตรียมตัวล้มลงเบาะ)...");
        } else {
          // เริ่มรอบ ADL 10 วินาที (เริ่มที่ท่ายืนเตรียมตัวนิ่งๆ 1 วินาที)
          adlSampleCount = 0;
          currentBatchIndex = 0;
          activePacket = getFreePacket();
          if (activePacket) {
            activePacket->record_id = activeRecordId;
            activePacket->batch_index = currentBatchIndex;
            activePacket->sample_count = 0;
            activePacket->is_final = false;
          }
          currentMode = MODE_RECORDING_ADL;
          Serial.println("[Core 1] 🧍 สเต็ปที่ 1: ยืนเตรียมตัวนิ่งๆ 1 วินาที...");
        }

        xLastWakeTime = xTaskGetTickCount();
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_WAIT_FALL_IMPACT: รอแรงกระแทกการล้ม (เก็บ Circular Pre-buffer 50 จุด)
      // ----------------------------------------------------------------------
      case MODE_WAIT_FALL_IMPACT: {
        SensorSample s = readMPUSample((long)now);
        storePreSample(s);

        float aMagG = calcAMagG(s);
        float gyroDps = calcGyroMagnitudeDps(s);
        if (aMagG <= LOW_G_THRESHOLD) lastLowGTime = now;
        if (gyroDps >= GYRO_THRESHOLD_DPS) lastHighGyroTime = now;

        // ตรวจสอบการกระแทก (Impact Threshold)
        bool impactDetected = shouldTriggerAutoFall(aMagG, gyroDps, now, lastLowGTime, lastHighGyroTime);

        if (impactDetected) {
          Serial.printf("\n[Core 1] 💥 ตรวจพบแรงกระแทก %.2fG! สกัดข้อมูล 1s ก่อนล้ม (50 จุด) + บันทึกต่อ 1s หลังล้ม (50 จุด)\n",
                        aMagG);

          // ส่งแพ็กเก็ต 1: ข้อมูล 50 จุดก่อนการล้ม (1 วินาที)
          BatchPacket *prePkt = getFreePacket();
          if (prePkt) {
            prePkt->record_id = activeRecordId;
            prePkt->batch_index = 0;
            prePkt->sample_count = 0;
            prePkt->is_final = false;

            int startIdx = (preWriteIndex - preCount + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES;
            for (int i = 0; i < preCount; i++) {
              SensorSample ps = preBuffer[(startIdx + i) % PRE_EVENT_SAMPLES];
              ps.timestamp_ms = i * SAMPLE_RATE_MS; // 0, 20, 40, ... 980 ms
              prePkt->samples[prePkt->sample_count++] = ps;
            }

            if (xQueueSend(batchUploadQueue, &prePkt, 0) != pdTRUE) {
              spoolBatchToSpiffs(prePkt);
              releasePacket(prePkt);
            }
          }

          // เตรียมแพ็กเก็ต 2: สำหรับเก็บข้อมูล 50 จุดหลังการล้ม (1 วินาที)
          activePacket = getFreePacket();
          if (activePacket) {
            activePacket->record_id = activeRecordId;
            activePacket->batch_index = 1;
            activePacket->sample_count = 0;
            activePacket->is_final = false;
          }

          postFallSampleCount = 0;
          currentMode = MODE_RECORDING_POST_FALL;
        }

        // หมดเวลา Timeout 60 วินาที หากไม่มีการล้มเกิดขึ้น
        if (now - waitFallStartTime > 60000 || stopContinuousRequested) {
          Serial.println("[Core 1] ⌛ หมดเวลารอตรวจจับการล้ม หรือผู้ใช้สั่งยกเลิก");
          currentMode = MODE_FINISHING;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_RECORDING_POST_FALL: บันทึกต่อ 50 จุดหลังการล้ม (1.0 วินาที)
      // ----------------------------------------------------------------------
      case MODE_RECORDING_POST_FALL: {
        long t_ms = 1000 + (postFallSampleCount * SAMPLE_RATE_MS);
        SensorSample s = readMPUSample(t_ms);

        if (activePacket) {
          activePacket->samples[activePacket->sample_count++] = s;
          postFallSampleCount++;

          if (postFallSampleCount >= POST_EVENT_SAMPLES) {
            activePacket->is_final = true; // จบครบ 100 จุด (2.0s)
            if (xQueueSend(batchUploadQueue, &activePacket, 0) != pdTRUE) {
              spoolBatchToSpiffs(activePacket);
              releasePacket(activePacket);
            }
            activePacket = NULL;
            Serial.println("[Core 1] ✔ บันทึกท่าล้มครบถ้วน 100 จุดข้อมูล (2.0 วินาที)");
            currentMode = MODE_FINISHING;
          }
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_RECORDING_ADL: ยืน 1s (beep) -> ทำท่า 8s (beep) -> ยืน 1s (finish)
      // ----------------------------------------------------------------------
      case MODE_RECORDING_ADL: {
        long t_ms = adlSampleCount * SAMPLE_RATE_MS;
        SensorSample s = readMPUSample(t_ms);

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
          adlSampleCount++;

          // สัญญาณเสียงขั้นตอนที่ 1: ครบ 1 วินาที (ตัวอย่างที่ 50) -> ลำโพงดัง Beep! ให้เริ่มทำท่า
          if (adlSampleCount == 50) {
            startBeepAsync(150);
            Serial.println("  [Core 1] 📢 ลำโพงดัง Beep! -> สเต็ปที่ 2: เริ่มทำท่าทางที่กำหนด (8 วินาที)...");
          }

          // สัญญาณเสียงขั้นตอนที่ 2: ครบ 9 วินาที (ตัวอย่างที่ 450) -> ลำโพงดัง Beep! ให้กลับมาท่ายืน
          if (adlSampleCount == 450) {
            startBeepAsync(150);
            Serial.println("  [Core 1] 📢 ลำโพงดัง Beep! -> สเต็ปที่ 3: กลับมาท่ายืนนิ่งๆ (1 วินาที)...");
          }

          // เมื่อเก็บครบ 50 จุด (1 วินาที) -> ส่งสตรีมขึ้น Core 0
          if (activePacket->sample_count >= BATCH_SIZE) {
            bool isLastBatch = (adlSampleCount >= 500) || stopContinuousRequested;
            if (isLastBatch) {
              activePacket->is_final = true;
            }

            if (xQueueSend(batchUploadQueue, &activePacket, 0) == pdTRUE) {
              activePacket = NULL;
              currentBatchIndex++;
            } else {
              spoolBatchToSpiffs(activePacket);
              releasePacket(activePacket);
              activePacket = NULL;
              currentBatchIndex++;
            }

            if (isLastBatch) {
              Serial.println("[Core 1] ✔ บันทึกรอบ ADL ครบถ้วน 500 จุดข้อมูล (10.0 วินาที)");
              currentMode = MODE_FINISHING;
            }
          }
        }

        if (stopContinuousRequested && currentMode != MODE_FINISHING) {
          currentMode = MODE_FINISHING;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_FINISHING: ส่งเสียงแจ้งเตือนเสร็จสิ้น และกลับสู่สถานะพร้อม
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
        Serial.println("[Core 1] 🏁 บันทึกเสร็จสิ้นสมบูรณ์! -> ส่งสัญญาณเสียงแจ้งเตือนเสร็จสิ้น...");
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
        Serial.println("[Core 0] 🛑 ได้รับสัญญาณหยุดจากหน้าเว็บ");
        stopContinuousRequested = true;
      }

      if (!sentOk) {
        Serial.printf("[Core 0] ⚠️ ส่ง Batch #%d ล้มเหลว -> สำรองลง SPIFFS Flash\n", pkt->batch_index);
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
        Serial.printf("[Core 0] 📥 ได้รับคำสั่งใหม่: Record #%d | รหัส: %s | โหมด: %s (%d วินาที, %d จุด)\n",
                      cmd.record_id, cmd.activity_code,
                      cmd.is_fall ? "💥 ท่าล้ม (1s ก่อนล้ม + 1s หลังล้ม)" : "🧍 ท่า ADL (ยืน 1s -> ท่า 8s -> ยืน 1s)",
                      cmd.duration_sec, cmd.target_samples);
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
    if (currentMode == MODE_IDLE && !isBuzzerBusy && asyncBuzzerOffTime == 0) {
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
      outCmd.duration_sec = doc["duration_sec"] | 10;
      outCmd.target_samples = doc["target_samples"] | 500;
      outCmd.is_fall = (doc["is_fall"] == true || strcmp(outCmd.category, "fall") == 0);
      outCmd.is_continuous = false;
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

// สัญญาณเสียงแบบ Non-blocking สำหรับบอกสเต็ประหว่างเก็บข้อมูลที่ 50Hz
void startBeepAsync(int duration_ms) {
  digitalWrite(BUZZER_PIN, HIGH);
  asyncBuzzerOffTime = millis() + duration_ms;
}

void checkAsyncBuzzer() {
  if (asyncBuzzerOffTime > 0 && millis() >= asyncBuzzerOffTime) {
    digitalWrite(BUZZER_PIN, LOW);
    asyncBuzzerOffTime = 0;
  }
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
    Serial.println("[Serial] สั่งหยุดบันทึกทันที!");
    stopContinuousRequested = true;
  } else if (cmd == "status" || cmd == "s") {
    Serial.println("\n================ SYSTEM STATUS ================");
    Serial.printf("MAC Address   : %s\n", MAC_ADDRESS.c_str());
    Serial.printf("WiFi Status   : %s\n", WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DISCONNECTED");
    Serial.printf("Mode          : %d\n", (int)currentMode);
    Serial.printf("SPIFFS Batches: %d\n", countSpiffsBatches());
    Serial.println("===============================================\n");
  }
}

float round2(float val) {
  return roundf(val * 100.0f) / 100.0f;
}
