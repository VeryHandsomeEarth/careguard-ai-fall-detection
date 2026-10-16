/*
 * ESP32 เก็บข้อมูลเซ็นเซอร์ MPU-6050 แบบรวมศูนย์ (Unified Fall & ADL Data Logger)
 * โฟลเดอร์: CollectData/CollectData_Hardware
 * อ้างอิงโปรโตคอล SisFall Dataset: Sucerquia et al. (2017), Sensors 17(1), 198 (PMC5298771)
 *
 * สถาปัตยกรรม FreeRTOS Dual-Core (แยกออกจาก loop() รวมอย่างสมบูรณ์):
 * ====================================================================================
 * 1) Core 1 — sensorCollectTask (Priority 3: High / Real-Time 50Hz):
 *    - อ่านค่าเซ็นเซอร์ MPU-6050 ที่ความถี่แม่นยำ 50Hz (ทุก 20ms ด้วย vTaskDelayUntil)
 *      โดยไม่ถูกบล็อกจาก Wi-Fi หรือการส่ง HTTP POST แม้แต่มิลลิวินาทีเดียว
 *    - รองรับการเก็บข้อมูลครบทั้ง 2 รูปแบบในโปรเจกต์เดียว:
 *        (A) โหมดรับคำสั่งจากเว็บ CollectData_Web (รองรับทั้งท่าล้ม F01-F15 [15s]
 *            และท่ากิจวัตรปกติ ADL D01-D19 [12s/15s/25s]):
 *            -> รับคำสั่งจาก Core 0 ผ่าน Queue
 *            -> ส่งเสียงสัญญาณปล่อยตัว "3... 2... 1... GO!"
 *            -> บันทึกข้อมูล 50Hz จนครบเวลาที่กำหนด (สูงสุด 25 วินาที = 1,250 จุด)
 *            -> ส่งเสียงดังแจ้งเตือนสิ้นสุดการบันทึก (ปี๊บ-ปี๊บ-ปี๊บยาว!)
 *            -> ส่งมอบชุดข้อมูลให้ Core 0 ผ่าน FreeRTOS Queue ทันที (Non-blocking)
 *        (B) โหมดตรวจจับแรงกระแทกอัตโนมัติ (Auto-Impact Pre 1s + Post 1s):
 *            -> เก็บ Circular Buffer ล่วงหน้า 1 วินาที (50 จุด) ตลอดเวลา
 *            -> สามารถเปิด/ปิดโหมด Auto-Trigger ผ่าน Serial ("auto on" / "auto off")
 *
 * 2) Core 0 — networkUploadTask (Priority 1: Normal / Network & Offline Persistence):
 *    - ดูแลการเชื่อมต่อ Wi-Fi และไฟสถานะแจ้งเตือน
 *    - ถามคำสั่งเริ่มบันทึกจากเว็บ (actions.php?action=check_command) ทุก 1.5 วินาที
 *    - รับชุดข้อมูลที่บันทึกเสร็จจาก Core 1 แล้วส่ง HTTP POST แบบ Batch (ครั้งละ 50 จุด)
 *      ขึ้นเซิร์ฟเวอร์ (receive_data.php) พร้อมแนบ is_final=true เมื่อครบจุดสุดท้าย
 *    - ระบบป้องกันการออฟไลน์ (Offline Protection ด้วย RAM + SPIFFS Flash):
 *        -> หากเน็ตหลุดหรือเซิร์ฟเวอร์ขัดข้องระหว่างส่งข้อมูล ระบบจะจำตำแหน่ง Batch ล่าสุด
 *           (uploadedSampleIndex) และบันทึกลงหน่วยความจำ Flash (SPIFFS) ทันที
 *        ->Core 1 ยังคงเก็บข้อมูลรอบถัดไปต่อได้โดยไม่ค้าง และเมื่อ Wi-Fi กลับมาเชื่อมต่อ
 *          Core 0 จะทยอยส่งข้อมูลที่ค้างใน Flash ขึ้นเซิร์ฟเวอร์อัตโนมัติจนครบ 100%
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

// ==================== การตั้งค่า Sampling & Buffer ====================

const int SAMPLE_RATE_MS = 20;      // 50Hz (ทุก 20ms)
const int DEFAULT_DURATION_S = 15;  // ค่าเริ่มต้น 15 วินาที
const int MAX_DURATION_S = 25;      // สูงสุด 25 วินาที (สำหรับท่าขึ้น-ลงบันได D05-D08)
const int MAX_SESSION_SAMPLES = (MAX_DURATION_S * 1000) / SAMPLE_RATE_MS; // 1,250 จุด
const int BATCH_SIZE = 50;          // ส่ง HTTP POST ครั้งละ 50 จุด (1 วินาที)

// สำหรับโหมด Auto-Impact Trigger (Pre-event 1s + Post-event 1s)
const int PRE_EVENT_SAMPLES = 50;   // 1 วินาทีก่อนกระแทก
const int POST_EVENT_SAMPLES = 50;  // 1 วินาทีหลังกระแทก
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

// คำสั่งเริ่มบันทึกที่ส่งจาก Core 0 -> Core 1
struct RecordCommand {
  int record_id;
  char category[16];
  char activity_code[24];
  char activity_name[96];
  int duration_sec;
};

// ชุดข้อมูลที่บันทึกเสร็จแล้วสำหรับส่งจาก Core 1 -> Core 0 (และบันทึกลง SPIFFS เมื่อออฟไลน์)
struct SessionHeader {
  uint32_t magic;               // 0xCD515FA1
  int record_id;                // ID รายการบันทึกบนเว็บ (0 หากเป็น Auto-Trigger)
  char category[16];            // "fall" หรือ "adl"
  char activity_code[24];       // เช่น "F01", "D01"
  char activity_name[96];       // ชื่อท่าทางภาษาไทย
  int total_samples;            // จำนวนจุดข้อมูลทั้งหมดในรอบนี้
  int uploaded_samples;         // จำนวนจุดที่ส่งสำเร็จไปแล้ว (ป้องกันการส่งซ้ำเมื่อเน็ตหลุดกลางคัน)
  float peak_accel_g;           // ค่าความเร่งสูงสุดระหว่างบันทึก (G)
  float peak_gyro_dps;          // ค่าความเร็วเชิงมุมสูงสุดระหว่างบันทึก (deg/s)
};

const uint32_t SESSION_MAGIC = 0xCD515FA1;
const int MAX_OFFLINE_FILES = 10; // เก็บสำรองใน SPIFFS ได้สูงสุด 10 รอบการทดลอง

// Buffer ฝั่ง Core 1 (เขียนขณะอ่านเซ็นเซอร์ 50Hz)
static SensorSample core1SampleBuffer[MAX_SESSION_SAMPLES];
static SessionHeader core1Header;

// Staging Buffer สำหรับส่งต่อระหว่าง Core 1 -> Core 0
static SensorSample stagingSampleBuffer[MAX_SESSION_SAMPLES];
static SessionHeader stagingHeader;
static volatile bool stagingBufferOccupied = false;

// Buffer ฝั่ง Core 0 (ใช้ส่ง HTTP POST หรืออ่าน/เขียนจาก SPIFFS)
static SensorSample core0SampleBuffer[MAX_SESSION_SAMPLES];
static SessionHeader core0Header;
static bool hasPendingRamSession = false;

// Circular Buffer สำหรับโหมด Auto-Impact ก่อนล้ม 1 วินาที (บน Core 1)
static SensorSample preBuffer[PRE_EVENT_SAMPLES];
static int preWriteIndex = 0;
static int preCount = 0;

// ==================== สถานะการทำงานร่วมกันระหว่าง 2 คอร์ ====================

enum CollectorState {
  STATE_IDLE,
  STATE_COUNTDOWN_START,
  STATE_RECORDING_TIMED,
  STATE_RECORDING_AUTO_POST,
  STATE_FINISH_ALERT
};

volatile CollectorState core1State = STATE_IDLE;
volatile int currentActiveRecordId = 0;
volatile int lastCompletedRecordId = 0;
volatile bool isBuzzerBusy = false;
volatile bool autoFallTriggerEnabled = false; // เปิดได้หากต้องการให้ตรวจจับกระแทกอัตโนมัติโดยไม่กดจากเว็บ

// FreeRTOS Handles
TaskHandle_t sensorTaskHandle = NULL;
TaskHandle_t networkTaskHandle = NULL;
QueueHandle_t commandQueue = NULL;     // Core 0 -> Core 1 (ส่งคำสั่งเริ่มบันทึก)
QueueHandle_t uploadSignalQueue = NULL; // Core 1 -> Core 0 (แจ้งเตือนว่ามีข้อมูลพร้อมส่ง)
SemaphoreHandle_t stagingMutex = NULL;  // ป้องกันการชนกันของ Staging Buffer

// ==================== ประกาศฟังก์ชัน ====================

void setupWiFi();
void setupMPU6050();
void setupSPIFFS();

// Tasks บนแต่ละคอร์
void sensorCollectTask(void *pvParameters); // Core 1
void networkUploadTask(void *pvParameters); // Core 0

// ฟังก์ชันฝั่ง Core 1 (Sensor & Audio Signals)
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
void handoffSessionToCore0();

// ฟังก์ชันฝั่ง Core 0 (Network & Offline SPIFFS)
bool pollServerCommand(RecordCommand &outCmd);
bool uploadSessionToServer(SessionHeader &hdr, const SensorSample *samples);
bool saveSessionToSpiffs(const SessionHeader &hdr, const SensorSample *samples);
int countOfflineFiles();
bool syncNextOfflineFile();
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
  Serial.println("  CollectData_Hardware — Unified Fall & ADL Logger (FreeRTOS v2.0)");
  Serial.println("  Core 1: Real-Time 50Hz MPU-6050 Reader & Recording Engine");
  Serial.println("  Core 0: Web Command Polling + Batch HTTP POST + SPIFFS Offline");
  Serial.println("==================================================================");

  setupSPIFFS();
  setupMPU6050();
  setupWiFi();

  MAC_ADDRESS = WiFi.macAddress();
  Serial.printf("[อุปกรณ์] MAC Address: %s\n", MAC_ADDRESS.c_str());
  Serial.printf("[Offline] พบไฟล์ค้างส่งใน SPIFFS Flash: %d ไฟล์\n", countOfflineFiles());

  // สร้าง Mutex และ Queue สำหรับสื่อสารข้ามคอร์
  stagingMutex = xSemaphoreCreateMutex();
  commandQueue = xQueueCreate(4, sizeof(RecordCommand));
  uint8_t dummySignal = 1;
  uploadSignalQueue = xQueueCreate(8, sizeof(uint8_t));

  // สร้าง Task บน Core 1: อ่านและเก็บข้อมูลเซ็นเซอร์ 50Hz (Priority 3 สูงสุด)
  xTaskCreatePinnedToCore(
      sensorCollectTask,
      "SensorCollectTask",
      8192,
      NULL,
      3,
      &sensorTaskHandle,
      1 // Core 1
  );

  // สร้าง Task บน Core 0: เชื่อมต่อเว็บและส่ง HTTP POST + Offline Sync (Priority 1)
  xTaskCreatePinnedToCore(
      networkUploadTask,
      "NetworkUploadTask",
      12288,
      NULL,
      1,
      &networkTaskHandle,
      0 // Core 0
  );

  Serial.println("[พร้อม] เริ่มต้น FreeRTOS Dual-Core สมบูรณ์ (แยกออกจาก loop รวม 100%)\n");
}

// ==================== LOOP หลัก (แยกออกจาก FreeRTOS Tasks) ====================

void loop() {
  // ไม่ใช้ loop() รวมอีกต่อไป การทำงานทั้งหมดแยกอยู่บน Core 1 และ Core 0
  vTaskDelay(pdMS_TO_TICKS(1000));
}

// ============================================================================
// CORE 1 TASK: อ่านและบันทึกข้อมูลเซ็นเซอร์ MPU-6050 ที่ 50Hz แม่นยำ
// ============================================================================

void sensorCollectTask(void *pvParameters) {
  TickType_t xLastWakeTime = xTaskGetTickCount();
  const TickType_t xFrequency = pdMS_TO_TICKS(SAMPLE_RATE_MS); // 20ms (50Hz)

  unsigned long recordingStartTime = 0;
  unsigned long lastLowGTime = 0;
  unsigned long lastHighGyroTime = 0;
  int postSampleCount = 0;

  for (;;) {
    // รอให้ครบรอบ 20ms อย่างแม่นยำด้วย Hardware Tick
    vTaskDelayUntil(&xLastWakeTime, xFrequency);
    unsigned long now = millis();

    // ตรวจสอบว่ามีคำสั่งเริ่มบันทึกใหม่จาก Core 0 หรือไม่ (ขณะอยู่ในสถานะ IDLE)
    if (core1State == STATE_IDLE) {
      RecordCommand cmd;
      if (xQueueReceive(commandQueue, &cmd, 0) == pdTRUE) {
        memset(&core1Header, 0, sizeof(SessionHeader));
        core1Header.magic = SESSION_MAGIC;
        core1Header.record_id = cmd.record_id;
        strncpy(core1Header.category, cmd.category, sizeof(core1Header.category) - 1);
        strncpy(core1Header.activity_code, cmd.activity_code, sizeof(core1Header.activity_code) - 1);
        strncpy(core1Header.activity_name, cmd.activity_name, sizeof(core1Header.activity_name) - 1);

        int dur = cmd.duration_sec;
        if (dur < 3) dur = 3;
        if (dur > MAX_DURATION_S) dur = MAX_DURATION_S;

        core1Header.total_samples = (dur * 1000) / SAMPLE_RATE_MS;
        core1Header.uploaded_samples = 0;
        core1Header.peak_accel_g = 0.0f;
        core1Header.peak_gyro_dps = 0.0f;

        currentActiveRecordId = cmd.record_id;
        core1State = STATE_COUNTDOWN_START;
      }
    }

    switch (core1State) {

      // ----------------------------------------------------------------------
      // 1. สถานะเฝ้าระวัง / รอคำสั่ง (อ่านเซ็นเซอร์เข้า preBuffer ตลอดเวลาที่ 50Hz)
      // ----------------------------------------------------------------------
      case STATE_IDLE: {
        SensorSample sample = readMPUSample((long)now);
        storePreSample(sample);

        float aMagG = calcAMagG(sample);
        float gyroDps = calcGyroMagnitudeDps(sample);

        if (aMagG <= LOW_G_THRESHOLD) {
          lastLowGTime = now;
        }
        if (gyroDps >= GYRO_THRESHOLD_DPS) {
          lastHighGyroTime = now;
        }

        // หากเปิดโหมด Auto-Impact Trigger ไว้ จะเริ่มเก็บข้อมูลก่อนล้ม 1s + หลังล้ม 1s อัตโนมัติ
        if (autoFallTriggerEnabled &&
            shouldTriggerAutoFall(aMagG, gyroDps, now, lastLowGTime, lastHighGyroTime)) {
          memset(&core1Header, 0, sizeof(SessionHeader));
          core1Header.magic = SESSION_MAGIC;
          core1Header.record_id = 0; // ให้เซิร์ฟเวอร์จับคู่กับรายการที่เปิด recording ล่าสุดของ MAC นี้
          strncpy(core1Header.category, "fall", sizeof(core1Header.category) - 1);
          strncpy(core1Header.activity_code, "AUTO_FALL", sizeof(core1Header.activity_code) - 1);
          strncpy(core1Header.activity_name, "ตรวจจับการล้มอัตโนมัติ (Pre 1s + Post 1s)", sizeof(core1Header.activity_name) - 1);
          core1Header.peak_accel_g = aMagG;
          core1Header.peak_gyro_dps = gyroDps;

          // คัดลอกข้อมูลก่อนกระแทก 1 วินาทีจาก preBuffer
          int startIdx = (preWriteIndex - preCount + PRE_EVENT_SAMPLES) % PRE_EVENT_SAMPLES;
          int copied = 0;
          for (int i = 0; i < preCount && copied < MAX_SESSION_SAMPLES; i++) {
            int idx = (startIdx + i) % PRE_EVENT_SAMPLES;
            SensorSample s = preBuffer[idx];
            s.timestamp_ms = s.timestamp_ms - (long)now; // ก่อนกระแทกมีเวลาเป็นลบ (-980..0 ms)
            core1SampleBuffer[copied++] = s;
          }
          core1Header.total_samples = copied;
          postSampleCount = 0;
          recordingStartTime = now;

          Serial.printf("\n[Core 1 - AUTO FALL] ตรวจพบแรงกระแทก %.2fG (Gyro %.1f dps)! เก็บ Pre %d จุด + กำลังเก็บ Post 50 จุด...\n",
                        aMagG, gyroDps, copied);
          digitalWrite(LED_PIN, HIGH);
          core1State = STATE_RECORDING_AUTO_POST;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // 2. สถานะส่งเสียงสัญญาณปล่อยตัว (3... 2... 1... GO!)
      // ----------------------------------------------------------------------
      case STATE_COUNTDOWN_START: {
        int durationSec = (core1Header.total_samples * SAMPLE_RATE_MS) / 1000;
        Serial.printf("\n[Core 1] เริ่มคำสั่งบันทึก #%d | หมวด: %s | ท่า: %s (%s) | เวลา: %d วินาที (%d จุด)\n",
                      core1Header.record_id,
                      core1Header.category,
                      core1Header.activity_code,
                      core1Header.activity_name,
                      durationSec,
                      core1Header.total_samples);

        playRaceCountdown();

        // รีเซ็ตตัวนับและฐานเวลาหลังจบเสียง GO!
        int target = core1Header.total_samples;
        core1Header.total_samples = 0; // ใช้เก็บจำนวนที่อ่านได้จริงแล้วเทียบกับ target
        core1Header.uploaded_samples = target; // พักค่า target ไว้ชั่วคราวในระหว่างอัด
        recordingStartTime = millis();
        digitalWrite(LED_PIN, HIGH);
        core1State = STATE_RECORDING_TIMED;

        // ปรับฐานเวลาของ vTaskDelayUntil ใหม่เพื่อไม่ให้เร่งชดเชยช่วงเสียง Countdown
        xLastWakeTime = xTaskGetTickCount();
        Serial.println("[Core 1] กำลังบันทึกข้อมูล MPU-6050 ที่ความถี่ 50Hz (ทุก 20ms)...");
        break;
      }

      // ----------------------------------------------------------------------
      // 3. สถานะกำลังบันทึกข้อมูลตามเวลาคำสั่งเว็บ (12s / 15s / 25s)
      // ----------------------------------------------------------------------
      case STATE_RECORDING_TIMED: {
        int targetSamples = core1Header.uploaded_samples;
        int currentIdx = core1Header.total_samples;
        long elapsed_ms = (long)(millis() - recordingStartTime);

        if (currentIdx < targetSamples && currentIdx < MAX_SESSION_SAMPLES) {
          SensorSample s = readMPUSample(elapsed_ms);
          core1SampleBuffer[currentIdx] = s;
          core1Header.total_samples = currentIdx + 1;

          float g = calcAMagG(s);
          float dps = calcGyroMagnitudeDps(s);
          if (g > core1Header.peak_accel_g) core1Header.peak_accel_g = g;
          if (dps > core1Header.peak_gyro_dps) core1Header.peak_gyro_dps = dps;

          if ((currentIdx + 1) % 50 == 0) {
            Serial.printf("  [Core 1] บันทึกแล้ว %d / %d จุด (%d วินาที) | Peak: %.2fG, %.1f dps\n",
                          currentIdx + 1, targetSamples, (currentIdx + 1) / 50,
                          core1Header.peak_accel_g, core1Header.peak_gyro_dps);
          }
        }

        if (core1Header.total_samples >= targetSamples ||
            core1Header.total_samples >= MAX_SESSION_SAMPLES) {
          core1Header.uploaded_samples = 0; // รีเซ็ตเป็น 0 สำหรับเริ่มต้นส่งให้ Core 0
          digitalWrite(LED_PIN, LOW);
          core1State = STATE_FINISH_ALERT;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // 4. สถานะเก็บข้อมูลหลังกระแทก 1 วินาที (เฉพาะโหมด Auto-Impact)
      // ----------------------------------------------------------------------
      case STATE_RECORDING_AUTO_POST: {
        long elapsed_ms = (long)(millis() - recordingStartTime);
        if (postSampleCount < POST_EVENT_SAMPLES &&
            core1Header.total_samples < MAX_SESSION_SAMPLES) {
          SensorSample s = readMPUSample(elapsed_ms);
          core1SampleBuffer[core1Header.total_samples++] = s;
          postSampleCount++;

          float g = calcAMagG(s);
          float dps = calcGyroMagnitudeDps(s);
          if (g > core1Header.peak_accel_g) core1Header.peak_accel_g = g;
          if (dps > core1Header.peak_gyro_dps) core1Header.peak_gyro_dps = dps;
        }

        if (postSampleCount >= POST_EVENT_SAMPLES ||
            core1Header.total_samples >= MAX_SESSION_SAMPLES) {
          core1Header.uploaded_samples = 0;
          digitalWrite(LED_PIN, LOW);
          core1State = STATE_FINISH_ALERT;
        }
        break;
      }

      // ----------------------------------------------------------------------
      // 5. สถานะส่งเสียงแจ้งเตือนจบการบันทึก และส่งมอบข้อมูลให้ Core 0
      // ----------------------------------------------------------------------
      case STATE_FINISH_ALERT: {
        Serial.printf("[Core 1] บันทึกเสร็จสมบูรณ์ (%d จุด, Peak %.2fG)! ส่งเสียงแจ้งเตือนสิ้นสุดการบันทึก...\n",
                      core1Header.total_samples, core1Header.peak_accel_g);
        playFinishAlert();

        // ส่งมอบข้อมูลจาก Core 1 ไปให้ Core 0 จัดการอัปโหลดหรือเก็บลง Flash ออฟไลน์
        handoffSessionToCore0();

        lastCompletedRecordId = core1Header.record_id;
        currentActiveRecordId = 0;
        core1State = STATE_IDLE;
        xLastWakeTime = xTaskGetTickCount();
        Serial.println("[Core 1] กลับสู่โหมดพร้อมรับคำสั่งรอบถัดไปทันที (Non-blocking)\n");
        break;
      }
    }
  }
}

// ============================================================================
// CORE 0 TASK: เชื่อมต่อ Wi-Fi, ถามคำสั่งเว็บ, ส่ง HTTP POST และจัดการ Offline Flash
// ============================================================================

void networkUploadTask(void *pvParameters) {
  unsigned long lastPollTime = 0;
  unsigned long lastWiFiCheck = 0;
  unsigned long lastOfflineRetry = 0;

  for (;;) {
    unsigned long now = millis();

    // 1. รับข้อมูลชุดใหม่ที่เพิ่งบันทึกเสร็จจาก Core 1 (ผ่าน Staging Buffer)
    uint8_t sig = 0;
    if (xQueueReceive(uploadSignalQueue, &sig, 0) == pdTRUE) {
      if (xSemaphoreTake(stagingMutex, pdMS_TO_TICKS(500)) == pdTRUE) {
        if (stagingBufferOccupied) {
          // หากใน Core 0 มีข้อมูลเก่าค้างอยู่ยังส่งไม่เสร็จ ให้ย้ายข้อมูลเก่าลง SPIFFS ก่อน
          if (hasPendingRamSession) {
            Serial.println("[Core 0] ย้ายข้อมูลชุดก่อนหน้าลง SPIFFS Flash เพื่อรับชุดใหม่...");
            saveSessionToSpiffs(core0Header, core0SampleBuffer);
            hasPendingRamSession = false;
          }
          // คัดลอกจาก Staging Buffer มาไว้ที่ Core 0 Buffer
          memcpy(&core0Header, &stagingHeader, sizeof(SessionHeader));
          memcpy(core0SampleBuffer, stagingSampleBuffer,
                 stagingHeader.total_samples * sizeof(SensorSample));
          stagingBufferOccupied = false;
          hasPendingRamSession = true;
        }
        xSemaphoreGive(stagingMutex);
      }
    }

    // 2. หากมีชุดข้อมูลรอส่งอยู่ใน RAM ของ Core 0 ให้พยายามส่งขึ้นเซิร์ฟเวอร์ทันที
    if (hasPendingRamSession) {
      if (WiFi.status() == WL_CONNECTED) {
        if (uploadSessionToServer(core0Header, core0SampleBuffer)) {
          Serial.printf("[Core 0] ✅ ส่งข้อมูล Record #%d (%s) ขึ้นเซิร์ฟเวอร์ครบ %d จุดเรียบร้อย!\n",
                        core0Header.record_id, core0Header.activity_code, core0Header.total_samples);
          hasPendingRamSession = false;
          playSuccessMelody();
        } else {
          // ส่งไม่สำเร็จ (เน็ตหลุดกลางคัน หรือเซิร์ฟเวอร์ไม่ตอบสนอง) -> บันทึกลง SPIFFS Flash ทันที
          Serial.printf("[Core 0] ⚠️ ส่งข้อมูลไม่สำเร็จ (ส่งไปได้ %d/%d จุด) -> สำรองข้อมูลลง SPIFFS Flash!\n",
                        core0Header.uploaded_samples, core0Header.total_samples);
          if (saveSessionToSpiffs(core0Header, core0SampleBuffer)) {
            hasPendingRamSession = false;
          }
          lastOfflineRetry = now;
        }
      } else {
        // กรณีออฟไลน์ขณะบันทึกเสร็จ -> บันทึกลง SPIFFS Flash ทันทีเพื่อไม่ให้ข้อมูลสูญหาย
        Serial.println("[Core 0] ⚠️ Wi-Fi ออฟไลน์! สำรองข้อมูลชุดนี้ลง SPIFFS Flash อัตโนมัติ...");
        if (saveSessionToSpiffs(core0Header, core0SampleBuffer)) {
          hasPendingRamSession = false;
        }
        lastOfflineRetry = now;
      }
    }

    // 3. หากมีไฟล์ค้างส่งใน SPIFFS Flash (หรือใน RAM) และ Wi-Fi กลับมาเชื่อมต่อแล้ว ให้ทยอยส่งทุก 5 วินาที
    if (!hasPendingRamSession && WiFi.status() == WL_CONNECTED &&
        (now - lastOfflineRetry >= 5000)) {
      lastOfflineRetry = now;
      if (countOfflineFiles() > 0) {
        syncNextOfflineFile();
      }
    }

    // 4. ถามคำสั่งเริ่มบันทึกจากหน้าเว็บ CollectData_Web ทุก 1.5 วินาที (เฉพาะเมื่อ Core 1 ว่าง)
    if (core1State == STATE_IDLE && !hasPendingRamSession &&
        WiFi.status() == WL_CONNECTED && (now - lastPollTime >= 1500)) {
      lastPollTime = now;
      RecordCommand cmd;
      if (pollServerCommand(cmd)) {
        // ป้องกันการรับคำสั่งเดิมซ้ำในขณะที่เพิ่งส่งเสร็จหรือกำลังประมวลผล
        if (cmd.record_id != currentActiveRecordId &&
            cmd.record_id != lastCompletedRecordId) {
          Serial.printf("[Core 0] 📥 ได้รับคำสั่งจากเว็บ: Record #%d | %s (%s) เวลา %d วินาที -> ส่งให้ Core 1 เริ่มทำงาน!\n",
                        cmd.record_id, cmd.activity_code, cmd.activity_name, cmd.duration_sec);
          xQueueSend(commandQueue, &cmd, pdMS_TO_TICKS(100));
        }
      } else {
        // หากบนเว็บไม่มีคำสั่งค้างแล้ว ให้รีเซ็ต lastCompletedRecordId เพื่อพร้อมรับคำสั่งใหม่ทุกไอดี
        lastCompletedRecordId = 0;
      }
    }

    // 5. ตรวจสอบการเชื่อมต่อ Wi-Fi ทุก 15 วินาที
    if (now - lastWiFiCheck >= 15000) {
      lastWiFiCheck = now;
      if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[Core 0 - WiFi] ขาดการเชื่อมต่อ กำลังพยายามเชื่อมต่อใหม่...");
        WiFi.reconnect();
      }
    }

    // 6. แสดงไฟสถานะ Wi-Fi (เมื่อ Core 1 ไม่ได้บันทึกและไม่ได้ส่งเสียง)
    if (core1State == STATE_IDLE && !isBuzzerBusy) {
      handleWiFiIndicator(now);
    }

    // 7. รับคำสั่งผ่าน Serial Monitor
    handleSerialCommands();

    // คืนเวลาให้ระบบ Wi-Fi / TCP Stack ของ ESP32 บน Core 0
    vTaskDelay(pdMS_TO_TICKS(20));
  }
}

// ============================================================================
// การส่งมอบข้อมูลจาก Core 1 -> Core 0 และระบบสำรองข้อมูลออฟไลน์ (SPIFFS Flash)
// ============================================================================

void handoffSessionToCore0() {
  if (xSemaphoreTake(stagingMutex, pdMS_TO_TICKS(500)) == pdTRUE) {
    // หากใน Staging มีข้อมูลค้างอยู่ ให้บันทึกลง SPIFFS ก่อนเพื่อไม่ให้ข้อมูลทับกัน
    if (stagingBufferOccupied) {
      saveSessionToSpiffs(stagingHeader, stagingSampleBuffer);
    }
    memcpy(&stagingHeader, &core1Header, sizeof(SessionHeader));
    memcpy(stagingSampleBuffer, core1SampleBuffer,
           core1Header.total_samples * sizeof(SensorSample));
    stagingBufferOccupied = true;
    xSemaphoreGive(stagingMutex);

    uint8_t sig = 1;
    xQueueSend(uploadSignalQueue, &sig, 0);
  } else {
    // Fallback กรณี Mutex ไม่ว่าง ให้เขียนลง SPIFFS โดยตรง
    saveSessionToSpiffs(core1Header, core1SampleBuffer);
  }
}

bool saveSessionToSpiffs(const SessionHeader &hdr, const SensorSample *samples) {
  if (hdr.total_samples <= 0) return true;

  // หาช่องไฟล์ว่าง /offline_0.bin ถึง /offline_9.bin
  String targetPath = "";
  for (int i = 0; i < MAX_OFFLINE_FILES; i++) {
    String path = "/offline_" + String(i) + ".bin";
    if (!SPIFFS.exists(path)) {
      targetPath = path;
      break;
    }
  }

  // หากเต็มทั้ง 10 ช่อง ให้เขียนทับช่องที่ 0
  if (targetPath == "") {
    targetPath = "/offline_0.bin";
  }

  File f = SPIFFS.open(targetPath, FILE_WRITE);
  if (!f) {
    Serial.printf("[SPIFFS] ❌ ไม่สามารถเปิดไฟล์ %s เพื่อบันทึกข้อมูลออฟไลน์ได้!\n", targetPath.c_str());
    return false;
  }

  size_t wHdr = f.write((const uint8_t *)&hdr, sizeof(SessionHeader));
  size_t dataBytes = hdr.total_samples * sizeof(SensorSample);
  size_t wData = f.write((const uint8_t *)samples, dataBytes);
  f.close();

  if (wHdr == sizeof(SessionHeader) && wData == dataBytes) {
    Serial.printf("[SPIFFS] 💾 บันทึกข้อมูลออฟไลน์ลง %s สำเร็จ (Record #%d, ค้างส่ง %d/%d จุด)\n",
                  targetPath.c_str(), hdr.record_id,
                  hdr.total_samples - hdr.uploaded_samples, hdr.total_samples);
    return true;
  } else {
    Serial.printf("[SPIFFS] ❌ เขียนไฟล์ %s ไม่สมบูรณ์ ลบไฟล์ทิ้ง\n", targetPath.c_str());
    SPIFFS.remove(targetPath);
    return false;
  }
}

int countOfflineFiles() {
  int count = 0;
  for (int i = 0; i < MAX_OFFLINE_FILES; i++) {
    String path = "/offline_" + String(i) + ".bin";
    if (SPIFFS.exists(path)) {
      count++;
    }
  }
  return count;
}

bool syncNextOfflineFile() {
  for (int i = 0; i < MAX_OFFLINE_FILES; i++) {
    String path = "/offline_" + String(i) + ".bin";
    if (!SPIFFS.exists(path)) continue;

    File f = SPIFFS.open(path, FILE_READ);
    if (!f) continue;

    SessionHeader hdr;
    if (f.read((uint8_t *)&hdr, sizeof(SessionHeader)) != sizeof(SessionHeader) ||
        hdr.magic != SESSION_MAGIC ||
        hdr.total_samples <= 0 ||
        hdr.total_samples > MAX_SESSION_SAMPLES) {
      f.close();
      SPIFFS.remove(path);
      continue;
    }

    size_t dataBytes = hdr.total_samples * sizeof(SensorSample);
    if (f.read((uint8_t *)core0SampleBuffer, dataBytes) != dataBytes) {
      f.close();
      SPIFFS.remove(path);
      continue;
    }
    f.close();

    Serial.printf("\n[Offline Sync] 🔄 กำลังส่งข้อมูลออฟไลน์จาก %s (Record #%d, %s, ต่อจากจุดที่ %d/%d)...\n",
                  path.c_str(), hdr.record_id, hdr.activity_code,
                  hdr.uploaded_samples, hdr.total_samples);

    if (uploadSessionToServer(hdr, core0SampleBuffer)) {
      SPIFFS.remove(path);
      Serial.printf("[Offline Sync] ✅ ซิงค์ไฟล์ออฟไลน์ %s สำเร็จและลบไฟล์สำรองแล้ว!\n", path.c_str());
      playSuccessMelody();
      return true;
    } else {
      // อัปเดตตำแหน่ง uploaded_samples ล่าสุดกลับลงไฟล์เดิม เพื่อไม่ต้องส่งซ้ำในรอบหน้า
      File fw = SPIFFS.open(path, FILE_WRITE);
      if (fw) {
        fw.write((const uint8_t *)&hdr, sizeof(SessionHeader));
        fw.write((const uint8_t *)core0SampleBuffer, dataBytes);
        fw.close();
      }
      return false;
    }
  }
  return false;
}

// ============================================================================
// ฟังก์ชันติดต่อเซิร์ฟเวอร์ (Polling คำสั่ง & ส่งข้อมูล Batch HTTP POST)
// ============================================================================

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
      strncpy(outCmd.category, doc["category"] | "fall", sizeof(outCmd.category) - 1);
      strncpy(outCmd.activity_code, doc["activity_code"] | "F01", sizeof(outCmd.activity_code) - 1);
      strncpy(outCmd.activity_name, doc["activity_name"] | "บันทึกท่าทาง", sizeof(outCmd.activity_name) - 1);
      outCmd.duration_sec = doc["duration_sec"] | DEFAULT_DURATION_S;
      return (outCmd.record_id > 0);
    }
  } else {
    http.end();
  }
  return false;
}

bool uploadSessionToServer(SessionHeader &hdr, const SensorSample *samples) {
  if (WiFi.status() != WL_CONNECTED || hdr.total_samples <= 0) {
    return false;
  }

  int startFrom = hdr.uploaded_samples;
  if (startFrom < 0 || startFrom >= hdr.total_samples) {
    startFrom = 0;
  }

  int totalBatches = (hdr.total_samples + BATCH_SIZE - 1) / BATCH_SIZE;
  int startBatch = startFrom / BATCH_SIZE;

  Serial.printf("[Core 0 - Upload] กำลังส่งข้อมูล Record #%d (%s) จำนวน %d จุด (เริ่มจากจุดที่ %d)...\n",
                hdr.record_id, hdr.activity_code, hdr.total_samples, startFrom);

  for (int b = startBatch; b < totalBatches; b++) {
    if (WiFi.status() != WL_CONNECTED) {
      return false;
    }

    int startIdx = b * BATCH_SIZE;
    int endIdx = min(startIdx + BATCH_SIZE, hdr.total_samples);
    bool isFinalBatch = (endIdx >= hdr.total_samples);

    DynamicJsonDocument doc((endIdx - startIdx) * 140 + 350);
    doc["mac_address"] = MAC_ADDRESS;
    if (hdr.record_id > 0) {
      doc["record_id"] = hdr.record_id;
    }
    if (isFinalBatch) {
      doc["is_final"] = true; // แจ้งเซิร์ฟเวอร์ให้เปลี่ยนสถานะบนเว็บเป็น "เสร็จสิ้น (completed)" ทันที
    }

    JsonArray dataArr = doc.createNestedArray("data");
    for (int i = startIdx; i < endIdx; i++) {
      JsonObject obj = dataArr.createNestedObject();
      obj["t"] = samples[i].timestamp_ms;
      obj["ax"] = round2(samples[i].accel_x);
      obj["ay"] = round2(samples[i].accel_y);
      obj["az"] = round2(samples[i].accel_z);
      obj["gx"] = round2(samples[i].gyro_x);
      obj["gy"] = round2(samples[i].gyro_y);
      obj["gz"] = round2(samples[i].gyro_z);
    }

    String jsonPayload;
    serializeJson(doc, jsonPayload);

    HTTPClient http;
    http.begin(RECEIVE_DATA_URL);
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(8000);

    int httpCode = http.POST(jsonPayload);
    http.end();

    if (httpCode == HTTP_CODE_OK) {
      hdr.uploaded_samples = endIdx; // บันทึกความคืบหน้าทันทีที่ส่ง Batch นี้ผ่าน
      Serial.printf("  -> ✅ Batch %d/%d สำเร็จ (จุดที่ %d-%d)\n",
                    b + 1, totalBatches, startIdx, endIdx - 1);
    } else {
      Serial.printf("  -> ❌ Batch %d/%d ล้มเหลว (HTTP %d)\n",
                    b + 1, totalBatches, httpCode);
      return false;
    }

    vTaskDelay(pdMS_TO_TICKS(40));
  }

  return true;
}

// ============================================================================
// ฟังก์ชันอ่านค่าเซ็นเซอร์ และคำนวณเงื่อนไขตรวจจับ
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
  if (preCount < PRE_EVENT_SAMPLES) {
    preCount++;
  }
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

// ============================================================================
// ฟังก์ชันสัญญาณเสียง (Buzzer & LED)
// ============================================================================

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
  Serial.println("[Countdown] เตรียมตัว... 3 - 2 - 1 - เริ่ม!");
  Serial.println("  [3] ปี๊บ!");
  beepTone(180, 820);
  Serial.println("  [2] ปี๊บ!");
  beepTone(180, 820);
  Serial.println("  [1] ปี๊บ!");
  beepTone(180, 820);
  Serial.println("  [GO!] ปี๊บบบบบยาว! (เริ่มเคลื่อนไหวทันที)");
  beepTone(650, 50);
}

void playFinishAlert() {
  // เสียงดัง 3 จังหวะ (ปี๊บ-ปี๊บ-ปี๊บยาว!) บอกผู้ทดสอบว่าครบเวลาบันทึกแล้ว
  beepTone(200, 120);
  beepTone(200, 120);
  beepTone(800, 100);
}

void playSuccessMelody() {
  // เสียงสั้น 2 จังหวะเมื่อส่งข้อมูลขึ้นเซิร์ฟเวอร์เสร็จสมบูรณ์
  beepTone(90, 70);
  beepTone(90, 70);
}

// ============================================================================
// ตั้งค่าเริ่มต้น (SPIFFS, MPU-6050, Wi-Fi) และคำสั่ง Serial
// ============================================================================

void setupSPIFFS() {
  if (!SPIFFS.begin(true)) {
    Serial.println("[SPIFFS] ❌ ไม่สามารถเริ่มต้น SPIFFS ได้!");
  } else {
    Serial.printf("[SPIFFS] เริ่มต้นสำเร็จ (พื้นที่ทั้งหมด: %u bytes, ใช้ไป: %u bytes)\n",
                  SPIFFS.totalBytes(), SPIFFS.usedBytes());
  }
}

void setupMPU6050() {
  Serial.println("[MPU6050] กำลังเริ่มต้นเซ็นเซอร์...");
  if (!mpu.begin()) {
    Serial.println("[MPU6050] ❌ ไม่พบเซ็นเซอร์ MPU-6050! ตรวจสอบสาย SDA/SCL");
    while (1) {
      digitalWrite(LED_PIN, !digitalRead(LED_PIN));
      delay(200);
    }
  }
  mpu.setAccelerometerRange(MPU6050_RANGE_16_G);
  mpu.setGyroRange(MPU6050_RANGE_2000_DEG);
  mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
  Serial.println("[MPU6050] ตั้งค่าเรียบร้อย (Accel ±16G, Gyro ±2000°/s, BW 21Hz)");
}

void setupWiFi() {
  WiFi.mode(WIFI_STA);
  WiFiManager wm;
  wm.setConfigPortalTimeout(180);
  Serial.println("[WiFi] กำลังเชื่อมต่อ Wi-Fi หรือเปิด AP: " AP_SSID "...");
  if (!wm.autoConnect(AP_SSID, AP_PASSWORD)) {
    Serial.println("[WiFi] ⚠️ ไม่สามารถเชื่อมต่อ Wi-Fi ได้ เริ่มทำงานต่อในโหมด Offline Buffer...");
  } else {
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
    // กระพริบไฟ LED เมื่อ Wi-Fi หลุด
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

  if (cmd == "status" || cmd == "s") {
    Serial.println("\n================ SYSTEM STATUS ================");
    Serial.printf("MAC Address      : %s\n", MAC_ADDRESS.c_str());
    Serial.printf("Wi-Fi Status     : %s (%s)\n",
                  WiFi.status() == WL_CONNECTED ? "CONNECTED" : "OFFLINE",
                  WiFi.localIP().toString().c_str());
    Serial.printf("Core 1 State     : %d (0=IDLE, 1=COUNTDOWN, 2=REC_TIMED, 3=REC_AUTO, 4=FINISH)\n",
                  (int)core1State);
    Serial.printf("Auto-Fall Mode   : %s\n", autoFallTriggerEnabled ? "ON" : "OFF (Web Command Mode)");
    Serial.printf("Pending RAM Data : %s\n", hasPendingRamSession ? "YES" : "NO");
    Serial.printf("Offline Files    : %d files in SPIFFS\n", countOfflineFiles());
    Serial.println("===============================================\n");
  } else if (cmd == "auto on") {
    autoFallTriggerEnabled = true;
    Serial.println("[Mode] เปิดโหมด Auto-Impact Fall Trigger ควบคู่กับคำสั่งเว็บแล้ว");
  } else if (cmd == "auto off") {
    autoFallTriggerEnabled = false;
    Serial.println("[Mode] ปิดโหมด Auto-Impact (ใช้การรับคำสั่งพร้อมเสียง 3-2-1-GO จากเว็บอย่างเดียว)");
  } else if (cmd == "sync") {
    Serial.println("[Serial] สั่งซิงค์ไฟล์ออฟไลน์จาก SPIFFS ทันที...");
    syncNextOfflineFile();
  } else if (cmd == "clear") {
    for (int i = 0; i < MAX_OFFLINE_FILES; i++) {
      String path = "/offline_" + String(i) + ".bin";
      if (SPIFFS.exists(path)) SPIFFS.remove(path);
    }
    hasPendingRamSession = false;
    Serial.println("[Serial] ล้างข้อมูลออฟไลน์ทั้งหมดใน SPIFFS เรียบร้อย");
  } else if (cmd == "help" || cmd == "h") {
    Serial.println("\n================ SERIAL COMMANDS ================");
    Serial.println("status / s : แสดงสถานะของ Core 0, Core 1 และไฟล์ออฟไลน์");
    Serial.println("auto on    : เปิดโหมดตรวจจับการล้มอัตโนมัติ (Pre 1s + Post 1s)");
    Serial.println("auto off   : ปิดโหมดตรวจจับอัตโนมัติ (รับคำสั่งจากเว็บอย่างเดียว)");
    Serial.println("sync       : บังคับส่งข้อมูลออฟไลน์ที่ค้างอยู่ใน SPIFFS ทันที");
    Serial.println("clear      : ลบไฟล์สำรองออฟไลน์ทั้งหมดใน SPIFFS");
    Serial.println("=================================================\n");
  }
}

float round2(float val) {
  return roundf(val * 100.0f) / 100.0f;
}
