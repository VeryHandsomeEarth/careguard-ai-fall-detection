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
#include <WiFiClientSecure.h>
#include <esp_wifi.h>
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"
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
const int BATCH_SIZE = 500;         // 500 จุดต่อ Batch -> 1 รอบทดสอบส่งเพียง 1 ครั้งเดียว (Gentle Request)
const int POOL_SIZE = 2;            // 2 x 14KB = ~28KB RAM (ป้องกันเซิร์ฟเวอร์แบน 100%)

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
  MODE_WAIT_ADL_BUTTON,     // ได้รับคำสั่ง ADL จากเว็บ -> ส่งเสียงเตือน & รอกดปุ่ม D18
  MODE_COUNTDOWN,           // นับถอยหลัง 3... 2... 1... เริ่ม!
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
  // ปิด Brownout Detector ป้องกันบอร์ด CD2 รีเซ็ตหรือตัด Wi-Fi เมื่อไฟ 3.3V ดรอปชั่วขณะตอนส่งข้อมูล
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);

  Serial.begin(115200);
  delay(800);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  pinMode(BUTTON_PIN, INPUT_PULLDOWN); // ปุ่ม D18 กดบันทึก ADL (Active HIGH)

  // เสียงเปิดเครื่อง (Boot Tones) ยืนยันว่าฮาร์ดแวร์และลำโพงพร้อมทำงาน
  beepTone(80, 60);
  beepTone(120, 60);

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
      16384,
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
  unsigned long countdownStartTime = 0;
  int countdownStep = -1;

  // สำหรับปุ่มกด D18 (Active HIGH: ปกติ 0V / กดแล้วเป็น 3.3V HIGH)
  int lastRawBtnState = LOW;
  int stableButtonState = LOW;
  unsigned long lastButtonDebounce = 0;
  unsigned long waitAdlStartTime = 0;

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

        if (currentCmd.is_fall) {
          // ท่าล้ม: เริ่มนับถอยหลัง 3 2 1 ทันทีตามข้อกำหนด
          Serial.printf("\n[Core 1] 🚨 ได้รับคำสั่งท่าล้ม (Record #%d - %s) -> เริ่มนับถอยหลัง 3 2 1 ทันที\n",
                        activeRecordId, currentCmd.activity_code);
          countdownStartTime = now;
          countdownStep = -1;
          currentMode = MODE_COUNTDOWN;
        } else {
          // ท่า ADL: เข้าสู่โหมดรอกดปุ่ม D18 เพื่อให้ผู้ทดสอบพร้อม
          waitAdlStartTime = now;
          currentMode = MODE_WAIT_ADL_BUTTON;
          Serial.println("\n==================================================================");
          Serial.printf("  [Core 1] 📥 ได้รับคำสั่ง ADL (Record #%d - %s : %s)\n",
                        activeRecordId, currentCmd.activity_code, currentCmd.activity_name);
          Serial.println("  👉 ผู้ทดสอบกรุณาเข้าประจำที่ในท่ายืนเตรียมตัว");
          Serial.println("  🔘 เมื่อพร้อมแล้ว ให้กดปุ่ม D18 เพื่อเริ่มนับถอยหลังและบันทึก 10 วินาที");
          Serial.println("==================================================================");

          // ส่งสัญญาณเสียงเตือน 2 ปี๊บแจ้งให้รู้ว่าได้รับคำสั่งเรียบร้อยแล้ว
          beepTone(100, 80);
          beepTone(180, 50);
        }
      }
    }

    // 2. ตรวจจับการกดปุ่ม D18 แบบ Debounce ที่เสถียร (Active HIGH: ปกติ 0V / กดแล้วเป็น 3.3V)
    int rawBtn = digitalRead(BUTTON_PIN);
    if (rawBtn != lastRawBtnState) {
      lastButtonDebounce = now;
      lastRawBtnState = rawBtn;
    }

    bool buttonJustPressed = false;
    if ((now - lastButtonDebounce) >= 40) {
      if (rawBtn != stableButtonState) {
        stableButtonState = rawBtn;
        if (stableButtonState == HIGH) { // กดปุ่ม (Rising Edge: LOW -> HIGH)
          buttonJustPressed = true;
        }
      }
    }

    if (buttonJustPressed) {
      if (currentMode == MODE_WAIT_ADL_BUTTON) {
        Serial.printf("\n[ปุ่ม D18] 🔘 ตรวจพบการกดปุ่ม D18 (Active HIGH) -> เริ่มรอบ ADL 10 วินาที! (Record #%d - %s)\n",
                      activeRecordId, currentCmd.activity_code);
        beepTone(80, 50); // เสียงปี๊บตอบสนองการกดทันที
        countdownStartTime = millis();
        countdownStep = -1;
        currentMode = MODE_COUNTDOWN;
      } else if (currentMode == MODE_IDLE) {
        Serial.println("\n[ปุ่ม D18] 🔘 ตรวจพบการกดปุ่ม D18 (Active HIGH)");
        beepTone(80, 50); // เสียงปี๊บตอบสนองทันที
        if (activeRecordId > 0 && !currentCmd.is_fall) {
          Serial.printf("           เริ่มรอบ ADL ที่รออยู่ทันที (Record #%d - %s)\n",
                        activeRecordId, currentCmd.activity_code);
          countdownStartTime = millis();
          countdownStep = -1;
          currentMode = MODE_COUNTDOWN;
        } else {
          Serial.println("           ปุ่ม D18 และลำโพงทำงานได้ปกติ (พร้อมรับคำสั่งจากหน้าเว็บ)");
        }
      }
    }

    // หมดเวลารอการกดปุ่ม D18 (120 วินาที) -> คืนสู่โหมด IDLE
    if (currentMode == MODE_WAIT_ADL_BUTTON && (now - waitAdlStartTime > 120000)) {
      Serial.println("\n[Core 1] ⚠️ หมดเวลารอกดปุ่ม D18 (120 วินาที) -> ยกเลิกและกลับสู่โหมดพร้อม");
      currentMode = MODE_IDLE;
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
      // MODE_WAIT_ADL_BUTTON: รอกดปุ่ม D18 (อ่าน MPU6050 เตรียมพร้อม)
      // ----------------------------------------------------------------------
      case MODE_WAIT_ADL_BUTTON: {
        SensorSample s = readMPUSample((long)now);
        storePreSample(s);
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_COUNTDOWN: นับถอยหลัง 3... 2... 1... GO! พร้อมอ่านข้อมูล 50Hz ตลอดเวลา
      // ----------------------------------------------------------------------
      case MODE_COUNTDOWN: {
        SensorSample s = readMPUSample((long)now);
        storePreSample(s);

        float aMagG = calcAMagG(s);
        float gyroDps = calcGyroMagnitudeDps(s);
        if (aMagG <= LOW_G_THRESHOLD) lastLowGTime = now;
        if (gyroDps >= GYRO_THRESHOLD_DPS) lastHighGyroTime = now;

        unsigned long elapsed = now - countdownStartTime;

        if (elapsed < 1000) {
          if (countdownStep != 3) {
            countdownStep = 3;
            Serial.printf("\n[Core 1] 📢 นับถอยหลัง 3... (Record #%d - %s)\n",
                          activeRecordId, currentCmd.activity_code);
          }
          bool on = (elapsed < 180);
          digitalWrite(BUZZER_PIN, on ? HIGH : LOW);
          digitalWrite(LED_PIN, on ? HIGH : LOW);
        } else if (elapsed < 2000) {
          if (countdownStep != 2) {
            countdownStep = 2;
            Serial.println("[Core 1] 📢 นับถอยหลัง 2...");
          }
          bool on = ((elapsed - 1000) < 180);
          digitalWrite(BUZZER_PIN, on ? HIGH : LOW);
          digitalWrite(LED_PIN, on ? HIGH : LOW);
        } else if (elapsed < 3000) {
          if (countdownStep != 1) {
            countdownStep = 1;
            Serial.println("[Core 1] 📢 นับถอยหลัง 1...");
          }
          bool on = ((elapsed - 2000) < 180);
          digitalWrite(BUZZER_PIN, on ? HIGH : LOW);
          digitalWrite(LED_PIN, on ? HIGH : LOW);
        } else {
          // ครบ 3.0 วินาที: ส่งเสียง GO! ยาว 650ms แบบ Non-blocking และเริ่มระบบทันที!
          Serial.println("[Core 1] 🚀 GO! เริ่มต้นทันที! (เปิดตรวจจับแรงกระแทก/บันทึก)");
          startBeepAsync(650);
          digitalWrite(LED_PIN, HIGH);

          if (currentCmd.is_fall) {
            // ไม่เคลียร์ preCount! บัฟเฟอร์ 50 จุดก่อนหน้าบรรจุข้อมูลท่ายืน 1.0 วินาทีล่าสุดพร้อมแล้ว
            waitFallStartTime = millis();
            currentMode = MODE_WAIT_FALL_IMPACT;
            Serial.println("[Core 1] 🛡️ เข้าสู่โหมดตรวจจับแรงกระแทก (เตรียมตัวล้มลงเบาะ)...");
          } else {
            // เริ่มรอบ ADL 10 วินาที (สเต็ปยืนเตรียมตัว 1 วินาทีแรก)
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
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_WAIT_FALL_IMPACT: รอแรงกระแทกการล้ม (สกัด 50 จุดก่อนล้ม + 50 จุดหลังล้ม = 100 จุด)
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

          // บันทึกรวมข้อมูล 50 จุดก่อนล้ม + 50 จุดหลังล้ม ลงใน 1 แพ็กเก็ตเดียว (100 จุด = 1 HTTP POST)
          activePacket = getFreePacket();
          if (activePacket) {
            activePacket->record_id = activeRecordId;
            activePacket->batch_index = 0;
            activePacket->sample_count = 0;
            activePacket->is_final = false;

            // ดึงข้อมูล 50 จุด (1.0 วินาที) ก่อนเกิดแรงกระแทกจาก Circular Buffer
            // รับประกันว่า activePacket จะได้ข้อมูลก่อนล้มครบ 50 จุดแน่นอน 100%
            int availablePre = (preCount > PRE_EVENT_SAMPLES) ? PRE_EVENT_SAMPLES : preCount;
            int padCount = PRE_EVENT_SAMPLES - availablePre;

            // กรณีมีข้อมูลก่อนล้มไม่ถึง 50 จุด ให้เติมตัวอย่างแรกสุดซ้ำเพื่อรับประกันว่าครบ 50 จุดเสมอ
            if (padCount > 0) {
              int oldestIdx = (preCount > 0) ? ((preWriteIndex - preCount + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES) : 0;
              SensorSample padSample = (preCount > 0) ? preBuffer[oldestIdx] : s;
              for (int i = 0; i < padCount; i++) {
                SensorSample ps = padSample;
                ps.timestamp_ms = i * SAMPLE_RATE_MS;
                activePacket->samples[activePacket->sample_count++] = ps;
              }
            }

            int startIdx = (preWriteIndex - availablePre + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES;
            for (int i = 0; i < availablePre; i++) {
              SensorSample ps = preBuffer[(startIdx + i) % PRE_EVENT_SAMPLES];
              ps.timestamp_ms = (padCount + i) * SAMPLE_RATE_MS; // 0, 20, 40, ... 980 ms
              activePacket->samples[activePacket->sample_count++] = ps;
            }
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

          // เมื่อเก็บครบ 500 จุด (10 วินาที) -> ไปโหมดส่งเสียงจบก่อนแล้วค่อยส่งขึ้น Core 0
          if (activePacket->sample_count >= BATCH_SIZE) {
            bool isLastBatch = (adlSampleCount >= 500) || stopContinuousRequested;
            if (isLastBatch) {
              activePacket->is_final = true;
              Serial.println("[Core 1] ✔ บันทึกรอบ ADL ครบถ้วน 500 จุดข้อมูล (10.0 วินาที)");
              currentMode = MODE_FINISHING;
            } else {
              if (xQueueSend(batchUploadQueue, &activePacket, 0) == pdTRUE) {
                activePacket = NULL;
                currentBatchIndex++;
              } else {
                spoolBatchToSpiffs(activePacket);
                releasePacket(activePacket);
                activePacket = NULL;
                currentBatchIndex++;
              }
            }
          }
        }

        if (stopContinuousRequested && currentMode != MODE_FINISHING) {
          currentMode = MODE_FINISHING;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // MODE_FINISHING: ส่งเสียงแจ้งเตือนเสร็จสิ้นให้จบก่อน แล้วจึงส่งข้อมูลผ่าน Wi-Fi
      // ----------------------------------------------------------------------
      case MODE_FINISHING: {
        digitalWrite(LED_PIN, LOW);
        Serial.println("[Core 1] 🏁 บันทึกเสร็จสิ้นสมบูรณ์! -> ส่งสัญญาณเสียงแจ้งเตือนเสร็จสิ้น...");
        // ส่งเสียงลำโพงให้เสร็จก่อนเริ่มส่ง Wi-Fi เพื่อไม่ให้ดึงกระแสไฟ 3.3V พร้อมกันจนไฟตก/เน็ตหลุด!
        playFinishAlert();
        vTaskDelay(pdMS_TO_TICKS(150)); // พักให้ระดับแรงดันไฟนิ่งสนิทหลังลำโพงดับ

        if (activePacket && activePacket->sample_count > 0) {
          activePacket->is_final = true;
          if (xQueueSend(batchUploadQueue, &activePacket, 0) != pdTRUE) {
            spoolBatchToSpiffs(activePacket);
            releasePacket(activePacket);
          }
          activePacket = NULL;
        }

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
  unsigned long disconnectedSince = 0;
  unsigned long lastSpiffsSync = 0;

  for (;;) {
    unsigned long now = millis();

    // 1. รับ Batch จาก Core 1 แล้วยิง HTTP POST ขึ้นเว็บทันที (พร้อมระบบรอ Wi-Fi & Retry 3 รอบ)
    BatchPacket *pkt = NULL;
    if (xQueueReceive(batchUploadQueue, &pkt, 0) == pdTRUE && pkt != NULL) {
      bool stopFromWeb = false;
      bool sentOk = false;

      // หาก Wi-Fi สะดุดชั่วคราวขณะผู้ทดสอบเคลื่อนไหวร่างกาย ให้กระตุ้น esp_wifi_connect() และรอสูงสุด 12 วินาที
      // (esp_wifi_connect() จะต่อใหม่เฉพาะเมื่อหลุดอยู่เท่านั้น ไม่ไปตัดการเชื่อมต่อที่กำลังดำเนินการอยู่)
      for (int w = 0; w < 24 && WiFi.status() != WL_CONNECTED; w++) {
        if (w % 6 == 0) {
          Serial.println("[Core 0] ⏳ รอสัญญาณ Wi-Fi เชื่อมต่อกลับก่อนส่งข้อมูล...");
          esp_wifi_connect();
        }
        vTaskDelay(pdMS_TO_TICKS(500));
      }

      // ส่งข้อมูลขึ้นเซิร์ฟเวอร์ (ลองซ้ำสูงสุด 3 ครั้งหากเน็ตสะดุด)
      for (int attempt = 1; attempt <= 3 && !sentOk; attempt++) {
        if (WiFi.status() != WL_CONNECTED) {
          esp_wifi_connect();
          for (int w = 0; w < 10 && WiFi.status() != WL_CONNECTED; w++) {
            vTaskDelay(pdMS_TO_TICKS(500));
          }
        }
        if (WiFi.status() == WL_CONNECTED) {
          sentOk = uploadBatchToServer(pkt, stopFromWeb);
        }
        if (!sentOk && attempt < 3) {
          Serial.printf("[Core 0] 🔄 ส่งข้อมูลรอบที่ %d ไม่สำเร็จ -> รอ 2 วินาทีแล้วลองใหม่...\n", attempt);
          vTaskDelay(pdMS_TO_TICKS(2000));
        }
      }

      if (stopFromWeb) {
        Serial.println("[Core 0] 🛑 ได้รับสัญญาณหยุดจากหน้าเว็บ");
        stopContinuousRequested = true;
      }

      if (!sentOk) {
        Serial.printf("[Core 0] ⚠️ ส่ง Batch #%d ล้มเหลวหลังลอง 3 ครั้ง -> สำรองลง SPIFFS Flash\n", pkt->batch_index);
        spoolBatchToSpiffs(pkt);
      } else if (pkt->is_final) {
        playSuccessMelody();
      }

      releasePacket(pkt);
      vTaskDelay(pdMS_TO_TICKS(2000)); // พัก 2 วินาทีหลังส่งข้อมูล เพื่อให้เซิร์ฟเวอร์ประมวลผลเสร็จสมบูรณ์
      lastPollTime = millis();         // เว้นระยะก่อนเริ่มถามคำสั่งถัดไป
    }

    // 2. ซิงค์ข้อมูลที่ค้างอยู่ใน Flash เมื่อเน็ตพร้อมและอยู่ในโหมดว่าง (เว้นระยะ 25 วินาที)
    if (currentMode == MODE_IDLE && WiFi.status() == WL_CONNECTED && now - lastSpiffsSync >= 25000) {
      lastSpiffsSync = now;
      if (countSpiffsBatches() > 0) {
        syncSpiffsBatches();
      }
    }

    // 3. ถามคำสั่งเริ่มบันทึกจากหน้าเว็บแบบอ่อนโยน (ทุก 6.0 - 8.5 วินาที + Jitter ป้องกัน IP Ban)
    static unsigned long nextPollInterval = 7000;
    if (currentMode == MODE_IDLE && WiFi.status() == WL_CONNECTED &&
        (now - lastPollTime >= nextPollInterval)) {
      lastPollTime = now;
      nextPollInterval = 6000 + random(0, 2500);
      RecordCommand cmd;
      if (pollServerCommand(cmd)) {
        Serial.printf("[Core 0] 📥 ได้รับคำสั่งใหม่: Record #%d | รหัส: %s | โหมด: %s (%d วินาที, %d จุด)\n",
                      cmd.record_id, cmd.activity_code,
                      cmd.is_fall ? "💥 ท่าล้ม (1s ก่อนล้ม + 1s หลังล้ม)" : "🧍 ท่า ADL (ยืน 1s -> ท่า 8s -> ยืน 1s)",
                      cmd.duration_sec, cmd.target_samples);
        xQueueSend(commandQueue, &cmd, 0);
      }
    }

    // 4. ตรวจสอบ Wi-Fi ทุก 5 วินาทีด้วย esp_wifi_connect()
    // ข้อดีของ esp_wifi_connect(): ถ้ากำลังเชื่อมค้างอยู่จะไม่ไปสั่ง disconnect ซ้ำ แต่ถ้าหลุดนิ่งอยู่จะสั่งเกาะ AP ทันที!
    if (WiFi.status() == WL_CONNECTED) {
      disconnectedSince = 0;
    } else {
      if (disconnectedSince == 0) {
        disconnectedSince = now;
      } else if (now - disconnectedSince >= 5000) {
        disconnectedSince = now;
        Serial.println("[WiFi] 🔄 กระตุ้นการเชื่อมต่อ Wi-Fi (esp_wifi_connect)...");
        esp_wifi_connect();
      }
    }

    // 5. ไฟสถานะ & คำสั่ง Serial
    if (currentMode == MODE_IDLE && !isBuzzerBusy && asyncBuzzerOffTime == 0) {
      handleWiFiIndicator(now);
    } else if (currentMode == MODE_WAIT_ADL_BUTTON) {
      static unsigned long lastWaitBlink = 0;
      if (now - lastWaitBlink >= 200) {
        lastWaitBlink = now;
        digitalWrite(LED_PIN, !digitalRead(LED_PIN));
      }
    }
    handleSerialCommands();

    vTaskDelay(pdMS_TO_TICKS(20));
  }
}

// ============================================================================
// การส่งข้อมูลขึ้นเซิร์ฟเวอร์แบบ Zero-Heap Chunked Stream & สปูล SPIFFS Flash
// ============================================================================

// คลาส Stream สร้าง JSON แบบ On-the-Fly ทีละชิ้นเล็กๆ (ใช้ Heap 0 bytes!)
// แก้ปัญหาการจอง String 36KB และการส่งก้อนใหญ่รวดเดียวที่ทำให้ Wi-Fi หลุดตอนจบ ADL
class BatchJsonStream : public Stream {
private:
  const BatchPacket *_pkt;
  String _header;
  int _sampleIdx;
  char _sampleBuf[112];
  size_t _sampleBufLen;
  size_t _sampleBufPos;
  size_t _headerPos;
  bool _footerDone;
  size_t _totalLen;
  size_t _bytesSent;

  void formatSample(int idx) {
    const SensorSample &s = _pkt->samples[idx];
    _sampleBufLen = snprintf(
        _sampleBuf, sizeof(_sampleBuf),
        "%s{\"t\":%ld,\"ax\":%.2f,\"ay\":%.2f,\"az\":%.2f,\"gx\":%.2f,\"gy\":%.2f,\"gz\":%.2f}",
        (idx > 0) ? "," : "",
        s.timestamp_ms,
        round2(s.accel_x), round2(s.accel_y), round2(s.accel_z),
        round2(s.gyro_x), round2(s.gyro_y), round2(s.gyro_z));
    _sampleBufPos = 0;
  }

public:
  BatchJsonStream(const BatchPacket *pkt, const String &mac) : _pkt(pkt) {
    _header = "{\"mac_address\":\"" + mac + "\"";
    if (_pkt->record_id > 0) {
      _header += ",\"record_id\":" + String(_pkt->record_id);
    }
    if (_pkt->is_final) {
      _header += ",\"is_final\":true";
    }
    _header += ",\"data\":[";

    _totalLen = _header.length() + 2; // +2 สำหรับ "]}"
    for (int i = 0; i < _pkt->sample_count; i++) {
      formatSample(i);
      _totalLen += _sampleBufLen;
    }

    _headerPos = 0;
    _sampleIdx = 0;
    _sampleBufLen = 0;
    _sampleBufPos = 0;
    _footerDone = false;
    _bytesSent = 0;
    if (_pkt->sample_count > 0) {
      formatSample(0);
    }
  }

  size_t totalLength() const { return _totalLen; }

  virtual int available() override {
    if (_bytesSent >= _totalLen) return 0;
    size_t rem = _totalLen - _bytesSent;
    return (rem > 1024) ? 1024 : (int)rem;
  }

  virtual int read() override {
    char c;
    if (readBytes(&c, 1) == 1) return (unsigned char)c;
    return -1;
  }

  virtual int peek() override { return -1; }
  virtual void flush() override {}
  virtual size_t write(uint8_t) override { return 0; }

  virtual size_t readBytes(char *buffer, size_t length) override {
    if (length > 1024) length = 1024;
    size_t written = 0;

    while (_headerPos < _header.length() && written < length) {
      buffer[written++] = _header[_headerPos++];
      _bytesSent++;
    }

    while (_sampleIdx < _pkt->sample_count && written < length) {
      while (_sampleBufPos < _sampleBufLen && written < length) {
        buffer[written++] = _sampleBuf[_sampleBufPos++];
        _bytesSent++;
      }
      if (_sampleBufPos >= _sampleBufLen) {
        _sampleIdx++;
        if (_sampleIdx < _pkt->sample_count) {
          formatSample(_sampleIdx);
        }
      }
    }

    const char *footer = "]}";
    while (_sampleIdx >= _pkt->sample_count && !_footerDone && written < length) {
      size_t footerIdx = _bytesSent - (_totalLen - 2);
      if (footerIdx < 2) {
        buffer[written++] = footer[footerIdx];
        _bytesSent++;
      }
      if (_bytesSent >= _totalLen) {
        _footerDone = true;
      }
    }

    // พัก 2ms ต่อทุกๆ 1KB เพื่อให้ Wi-Fi Driver ส่งแพ็กเก็ตออกไปได้ทัน ไม่กินไฟกระชาก
    vTaskDelay(pdMS_TO_TICKS(2));
    return written;
  }
};

bool uploadBatchToServer(BatchPacket *pkt, bool &outStopRequested) {
  outStopRequested = false;
  if (!pkt || pkt->sample_count <= 0) return true;
  if (WiFi.status() != WL_CONNECTED) return false;

  BatchJsonStream jsonStream(pkt, MAC_ADDRESS);
  Serial.printf("[Core 0] 📤 กำลังสตรีมข้อมูล Record #%d จำนวน %d จุด (ขนาด %u bytes | Free Heap: %u bytes)...\n",
                pkt->record_id, pkt->sample_count, jsonStream.totalLength(), ESP.getFreeHeap());

  WiFiClientSecure client;
  client.setInsecure();
  client.setTimeout(15);

  HTTPClient http;
  http.begin(client, RECEIVE_DATA_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("User-Agent", "ESP32-IMU-Client/2.0");
  http.addHeader("Connection", "close");
  http.setTimeout(15000);

  int httpCode = http.sendRequest("POST", &jsonStream, jsonStream.totalLength());
  if (httpCode == HTTP_CODE_OK) {
    String resp = http.getString();
    http.end();
    client.stop();

    Serial.printf("[Core 0] ✅ ส่งข้อมูลสำเร็จครบ %d จุด! ตอบกลับ: %s\n", pkt->sample_count, resp.c_str());

    DynamicJsonDocument respDoc(512);
    deserializeJson(respDoc, resp);
    if (respDoc["stop_requested"] == true) {
      outStopRequested = true;
    }
    return true;
  } else {
    Serial.printf("[Core 0] ❌ ส่งข้อมูลไม่สำเร็จ (HTTP Code: %d)\n", httpCode);
    http.end();
    client.stop();
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

    // ใช้ Memory Pool แทนการสร้างตัวแปร BatchPacket (14KB) บน Stack
    // ป้องกันอาการ Guru Meditation Error: Core 0 panic'ed (Double exception) 100%!
    BatchPacket *pkt = getFreePacket();
    if (!pkt) {
      f.close();
      return;
    }

    size_t readBytes = f.read((uint8_t*)pkt, sizeof(BatchPacket));
    f.close();

    if (readBytes == sizeof(BatchPacket) && pkt->sample_count > 0 && pkt->sample_count <= BATCH_SIZE) {
      bool dummyStop = false;
      if (uploadBatchToServer(pkt, dummyStop)) {
        SPIFFS.remove(p);
      }
    } else {
      SPIFFS.remove(p);
    }

    releasePacket(pkt);
    break;
  }
}

bool pollServerCommand(RecordCommand &outCmd) {
  if (WiFi.status() != WL_CONNECTED) return false;

  WiFiClientSecure client;
  client.setInsecure();
  client.setTimeout(4);

  HTTPClient http;
  String url = String(CHECK_CMD_URL) + "?action=check_command&mac=" + MAC_ADDRESS;

  http.begin(client, url);
  http.addHeader("User-Agent", "ESP32-IMU-Client/2.0");
  http.addHeader("Connection", "close");
  http.setTimeout(4000);
  int httpCode = http.GET();

  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    http.end();
    client.stop();

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
    client.stop();
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
    // ล้างไฟล์ Batch เก่าที่ขนาดโครงสร้างไม่ตรงกับเวอร์ชันปัจจุบันทิ้งอัตโนมัติ
    for (int i = 0; i < 20; i++) {
      String p = "/batch_" + String(i) + ".bin";
      if (SPIFFS.exists(p)) {
        File f = SPIFFS.open(p, FILE_READ);
        if (f) {
          size_t sz = f.size();
          f.close();
          if (sz != sizeof(BatchPacket)) {
            SPIFFS.remove(p);
          }
        }
      }
    }
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
  WiFi.setSleep(false);                 // ปิด Wi-Fi Modem Sleep 100% ป้องกันหลุดการเชื่อมต่อจากเราเตอร์
  WiFi.setAutoReconnect(true);          // เปิด Auto Reconnect ในระดับ Driver
  WiFi.persistent(true);
  WiFi.setTxPower(WIFI_POWER_17dBm);  // ใช้ 17dBm เพื่อไม่ให้ภาคจ่ายไฟ 3.3V ของบอร์ด CD2 วูบตอนส่งข้อมูล

  // ลงทะเบียนตรวจจับเหตุการณ์ Wi-Fi (ใช้ esp_wifi_connect() ซึ่งไม่สั่ง disconnect ซ้ำซ้อน)
  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
    switch (event) {
      case ARDUINO_EVENT_WIFI_STA_CONNECTED:
        Serial.println("[WiFi] 📶 เชื่อมต่อกับ Access Point สำเร็จ");
        break;
      case ARDUINO_EVENT_WIFI_STA_GOT_IP:
        Serial.printf("[WiFi] 🌐 ได้รับ IP: %s | RSSI: %d dBm\n",
                      WiFi.localIP().toString().c_str(), WiFi.RSSI());
        break;
      case ARDUINO_EVENT_WIFI_STA_DISCONNECTED:
        Serial.printf("[WiFi] ⚠️ Wi-Fi สะดุดชั่วคราว (Reason Code: %d) -> กำลังเชื่อมต่อกลับอัตโนมัติ...\n",
                      info.wifi_sta_disconnected.reason);
        esp_wifi_connect();
        break;
      default:
        break;
    }
  });

  WiFiManager wm;
  wm.setConfigPortalTimeout(180);
  if (wm.autoConnect(AP_SSID, AP_PASSWORD)) {
    WiFi.setSleep(false);                 // ยืนยันปิด Sleep ซ้ำอีกครั้ง
    WiFi.setAutoReconnect(true);          // ยืนยัน Auto Reconnect
    WiFi.setTxPower(WIFI_POWER_17dBm);    // ยืนยันกำลังส่ง 17dBm หลังผ่าน WiFiManager
    Serial.printf("[WiFi] เชื่อมต่อสำเร็จ: %s (IP: %s | RSSI: %d dBm)\n",
                  WiFi.SSID().c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
    // เสียงเมื่อต่อ Wi-Fi สำเร็จ (Melody 3 ขั้น)
    beepTone(90, 50);
    beepTone(130, 50);
    beepTone(180, 70);
  } else {
    Serial.println("[WiFi] ⚠️ หมดเวลารอตั้งค่า Wi-Fi Captive Portal (เข้าสู่โหมด Offline)");
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
  } else if (cmd == "wifi-reset") {
    Serial.println("[WiFi] 🔄 ล้างค่า Wi-Fi และรีสตาร์ทเพื่อเปิด Captive Portal ใหม่...");
    WiFi.disconnect(true, true);
    delay(1000);
    ESP.restart();
  } else if (cmd == "status" || cmd == "s") {
    Serial.println("\n================ SYSTEM STATUS ================");
    Serial.printf("MAC Address   : %s\n", MAC_ADDRESS.c_str());
    Serial.printf("WiFi Status   : %s (%s, IP: %s, RSSI: %d dBm)\n",
                  WiFi.status() == WL_CONNECTED ? "CONNECTED" : "DISCONNECTED",
                  WiFi.SSID().c_str(), WiFi.localIP().toString().c_str(), WiFi.RSSI());
    Serial.printf("Free Heap     : %d bytes (Min: %d bytes)\n", ESP.getFreeHeap(), ESP.getMinFreeHeap());
    Serial.printf("Mode          : %d\n", (int)currentMode);
    Serial.printf("SPIFFS Batches: %d\n", countSpiffsBatches());
    Serial.println("===============================================\n");
  }
}

float round2(float val) {
  return roundf(val * 100.0f) / 100.0f;
}
