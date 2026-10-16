/*
 * การตรวจจับการล้มด้วย TinyML (Native MLP Forward Pass)
 * พร้อมระบบ Threshold-based เป็น Fallback
 *
 * ใช้ค่าน้ำหนัก MLP ที่เทรนจากชุดข้อมูล SisFall
 * ไม่ต้องใช้ TFLite runtime — ใช้ C++ forward pass โดยตรง
 */

#ifndef FALL_DETECTION_H
#define FALL_DETECTION_H

#include <Arduino.h>
#include <math.h>

// ตรวจสอบว่ามีไฟล์โมเดลที่เทรนแล้วหรือไม่
#if __has_include("fall_model.h")
#define USE_ML_MODEL 1
#include "fall_model.h"
#else
#define USE_ML_MODEL 0
#warning "fall_model.h ไม่พบ - รัน tinyml/train_model.py ก่อน"
#endif

class FallDetector {
private:
  bool initialized = false;

  // พารามิเตอร์ Threshold (ใช้เป็นระบบสำรอง)
  const float FREE_FALL_THRESHOLD = 0.3f;  // เกณฑ์การตกอิสระ (G)
  const float IMPACT_THRESHOLD = 3.5f;     // เกณฑ์แรงกระแทก (G)
  const float MIN_FALL_CONFIDENCE = 0.80f; // ค่าความมั่นใจขั้นต่ำ (ปรับเป็น 0.80 เพื่อความเสถียร)

  // บัฟเฟอร์เก็บ Feature
  float features[18]; // ต้องตรงกับ MODEL_INPUT_FEATURES

  // ==================== การสกัด Feature ====================
  // สกัด Feature ทางสถิติ 18 ตัวจากข้อมูลเซ็นเซอร์ดิบ
  // ตรงกับสคริปต์ Python สำหรับเทรนโมเดล
  void extractFeatures(float *data, int numSamples) {
    float accel_mag[50]; // ขนาดความเร่ง
    float gyro_mag[50];  // ขนาดไจโร
    float horiz_mag[50]; // ขนาดแนวนอน

    int n = min(numSamples, 50);

    // คำนวณขนาดเวกเตอร์
    for (int i = 0; i < n; i++) {
      float ax = data[i * 6 + 0]; // ความเร่ง X
      float ay = data[i * 6 + 1]; // ความเร่ง Y
      float az = data[i * 6 + 2]; // ความเร่ง Z
      float gx = data[i * 6 + 3]; // ไจโร X
      float gy = data[i * 6 + 4]; // ไจโร Y
      float gz = data[i * 6 + 5]; // ไจโร Z

      accel_mag[i] = sqrt(ax * ax + ay * ay + az * az) / 9.81f; // หน่วย G
      gyro_mag[i] = sqrt(gx * gx + gy * gy + gz * gz);          // หน่วย rad/s
      horiz_mag[i] = sqrt(ax * ax + az * az) / 9.81f;           // แนวราบ (G)
    }

    // Feature 0: ค่าเฉลี่ยความเร่ง
    float sum_am = 0;
    for (int i = 0; i < n; i++)
      sum_am += accel_mag[i];
    features[0] = sum_am / n;

    // Feature 1: ค่าสูงสุดความเร่ง
    float max_am = accel_mag[0];
    for (int i = 1; i < n; i++)
      if (accel_mag[i] > max_am)
        max_am = accel_mag[i];
    features[1] = max_am;

    // Feature 2: ค่าต่ำสุดความเร่ง
    float min_am = accel_mag[0];
    for (int i = 1; i < n; i++)
      if (accel_mag[i] < min_am)
        min_am = accel_mag[i];
    features[2] = min_am;

    // Feature 3: ส่วนเบี่ยงเบนมาตรฐานความเร่ง
    float mean_am = features[0];
    float var_am = 0;
    for (int i = 0; i < n; i++)
      var_am += (accel_mag[i] - mean_am) * (accel_mag[i] - mean_am);
    features[3] = sqrt(var_am / n);

    // Feature 4: พิสัย (ค่าสูงสุด - ค่าต่ำสุด)
    features[4] = max_am - min_am;

    // Feature 5: อัตราส่วนการตกอิสระ (ตัวอย่างที่ < 0.3G)
    int ff_count = 0;
    for (int i = 0; i < n; i++)
      if (accel_mag[i] < 0.3f)
        ff_count++;
    features[5] = (float)ff_count / n;

    // Feature 6: อัตราส่วนแรงกระแทก (ตัวอย่างที่ > 3.5G)
    int imp_count = 0;
    for (int i = 0; i < n; i++)
      if (accel_mag[i] > 3.5f)
        imp_count++;
    features[6] = (float)imp_count / n;

    // Feature 7: ค่าเฉลี่ยไจโร
    float sum_gm = 0;
    for (int i = 0; i < n; i++)
      sum_gm += gyro_mag[i];
    features[7] = sum_gm / n;

    // Feature 8: ค่าสูงสุดไจโร
    float max_gm = gyro_mag[0];
    for (int i = 1; i < n; i++)
      if (gyro_mag[i] > max_gm)
        max_gm = gyro_mag[i];
    features[8] = max_gm;

    // Feature 9: ส่วนเบี่ยงเบนมาตรฐานไจโร
    float mean_gm = features[7];
    float var_gm = 0;
    for (int i = 0; i < n; i++)
      var_gm += (gyro_mag[i] - mean_gm) * (gyro_mag[i] - mean_gm);
    features[9] = sqrt(var_gm / n);

    // Feature 10: SMA (Signal Magnitude Area) หน่วย G
    float sum_sma = 0;
    for (int i = 0; i < n; i++) {
      sum_sma += (fabs(data[i * 6 + 0]) + fabs(data[i * 6 + 1]) +
                  fabs(data[i * 6 + 2])) /
                 9.81f;
    }
    features[10] = sum_sma / n;

    // Feature 11: ค่าเฉลี่ยขนาดแนวนอน
    float sum_hm = 0;
    for (int i = 0; i < n; i++)
      sum_hm += horiz_mag[i];
    features[11] = sum_hm / n;

    // Feature 12: ค่าสูงสุดขนาดแนวนอน
    float max_hm = horiz_mag[0];
    for (int i = 1; i < n; i++)
      if (horiz_mag[i] > max_hm)
        max_hm = horiz_mag[i];
    features[12] = max_hm;

    // Feature 13: ค่าเฉลี่ย Jerk (อัตราการเปลี่ยนแปลงความเร่ง)
    float sum_jerk = 0;
    for (int i = 1; i < n; i++)
      sum_jerk += fabs(accel_mag[i] - accel_mag[i - 1]);
    features[13] = (n > 1) ? sum_jerk / (n - 1) : 0;

    // Feature 14: ค่าสูงสุด Jerk
    float max_jerk = 0;
    for (int i = 1; i < n; i++) {
      float j = fabs(accel_mag[i] - accel_mag[i - 1]);
      if (j > max_jerk)
        max_jerk = j;
    }
    features[14] = max_jerk;

    // Feature 15: อัตราการตัดผ่านศูนย์ (จำนวนครั้งที่ข้ามค่า 1G)
    int zc_count = 0;
    for (int i = 1; i < n; i++) {
      if ((accel_mag[i] - 1.0f) * (accel_mag[i - 1] - 1.0f) < 0)
        zc_count++;
    }
    features[15] = (float)zc_count;

    // Feature 16: ค่าสหสัมพันธ์ระหว่างแกน X กับ Y (แบบย่อ)
    float sum_xy = 0, sum_x = 0, sum_y = 0, sum_x2 = 0, sum_y2 = 0;
    for (int i = 0; i < n; i++) {
      float x = data[i * 6 + 0], y = data[i * 6 + 1];
      sum_x += x;
      sum_y += y;
      sum_xy += x * y;
      sum_x2 += x * x;
      sum_y2 += y * y;
    }
    float denom =
        sqrt((n * sum_x2 - sum_x * sum_x) * (n * sum_y2 - sum_y * sum_y));
    features[16] = (denom > 0.001f) ? (n * sum_xy - sum_x * sum_y) / denom : 0;

    // Feature 17: ค่าสหสัมพันธ์ระหว่างแกน Y กับ Z
    sum_xy = 0;
    sum_x = 0;
    sum_y = 0;
    sum_x2 = 0;
    sum_y2 = 0;
    for (int i = 0; i < n; i++) {
      float x = data[i * 6 + 1], y = data[i * 6 + 2];
      sum_x += x;
      sum_y += y;
      sum_xy += x * y;
      sum_x2 += x * x;
      sum_y2 += y * y;
    }
    denom = sqrt((n * sum_x2 - sum_x * sum_x) * (n * sum_y2 - sum_y * sum_y));
    features[17] = (denom > 0.001f) ? (n * sum_xy - sum_x * sum_y) / denom : 0;
  }

#if USE_ML_MODEL
  // ==================== MLP Forward Pass ====================
  // ฟังก์ชันกระตุ้น ReLU
  float relu(float x) { return x > 0 ? x : 0; }

  // ฟังก์ชันกระตุ้น Sigmoid
  float sigmoid(float x) {
    if (x > 10.0f)
      return 1.0f;
    if (x < -10.0f)
      return 0.0f;
    return 1.0f / (1.0f + exp(-x));
  }

  // รัน MLP forward pass (คำนวณผลลัพธ์จากน้ำหนักโมเดล)
  float mlpForward(float *input) {
    // ปรับค่าให้เป็นมาตรฐานด้วย Scaler
    float normalized[MODEL_INPUT_FEATURES];
    for (int i = 0; i < MODEL_INPUT_FEATURES; i++) {
      normalized[i] = (input[i] - MODEL_SCALER_MEAN[i]) / MODEL_SCALER_SCALE[i];
    }

    // ชั้นที่ 0: อินพุต(18) -> ชั้นซ่อน1(32) ผ่าน ReLU
    float h1[32];
    for (int j = 0; j < 32; j++) {
      float sum = MODEL_B0[j];
      for (int i = 0; i < MODEL_INPUT_FEATURES; i++) {
        sum += normalized[i] * MODEL_W0[i * 32 + j];
      }
      h1[j] = relu(sum);
    }

    // ชั้นที่ 1: ชั้นซ่อน1(32) -> ชั้นซ่อน2(16) ผ่าน ReLU
    float h2[16];
    for (int j = 0; j < 16; j++) {
      float sum = MODEL_B1[j];
      for (int i = 0; i < 32; i++) {
        sum += h1[i] * MODEL_W1[i * 16 + j];
      }
      h2[j] = relu(sum);
    }

    // ชั้นที่ 2: ชั้นซ่อน2(16) -> เอาต์พุต(1) ผ่าน Sigmoid
    float output = MODEL_B2[0];
    for (int i = 0; i < 16; i++) {
      output += h2[i] * MODEL_W2[i];
    }

    return sigmoid(output);
  }
#endif

public:
  bool begin() {
    initialized = true;

#if USE_ML_MODEL
    Serial.println("[ตรวจจับ] โหลดโมเดล TinyML สำเร็จ (MLP 18->32->16->1)");
    Serial.printf("[ตรวจจับ] ขนาดโมเดล: %d พารามิเตอร์\n",
                  MODEL_INPUT_FEATURES * 32 + 32 + 32 * 16 + 16 + 16 * 1 + 1);
#else
    Serial.println("[ตรวจจับ] ใช้ระบบ Threshold เท่านั้น");
    Serial.println("[ตรวจจับ] รัน tinyml/train_model.py เพื่อสร้างโมเดล ML");
#endif
    Serial.println("[ตรวจจับ] เกณฑ์ตกอิสระ: 0.3G, แรงกระแทก: 3.5G");

    return true;
  }

  // ทำนายการล้มจากข้อมูลเซ็นเซอร์ดิบ
  // คืนค่าความมั่นใจ 0.0 - 1.0
  float predict(float *data, int dataLength) {
    if (!initialized)
      return 0.0f;

    int numSamples = dataLength / 6;
    float mlConfidence = 0.0f;
    float thresholdConfidence = 0.0f;

#if USE_ML_MODEL
    // สกัด Feature แล้วรัน MLP
    extractFeatures(data, numSamples);
    mlConfidence = mlpForward(features);
#endif

    // ระบบ Threshold สำรอง
    thresholdConfidence = predictThreshold(data, numSamples);

    // ใช้ค่าความมั่นใจที่สูงกว่า
    return max(mlConfidence, thresholdConfidence);
  }

  // ระบบตรวจจับแบบ Threshold (สำรอง)
  float predictThreshold(float *data, int numSamples) {
    float maxAccelMag = 0;
    int freeFallCount = 0, impactCount = 0, highGyroCount = 0;
    bool hadFreeFallThenImpact = false;
    bool inFreeFall = false;

    for (int i = 0; i < numSamples; i++) {
      float ax = data[i * 6 + 0]; // ความเร่ง X
      float ay = data[i * 6 + 1]; // ความเร่ง Y
      float az = data[i * 6 + 2]; // ความเร่ง Z
      float gx = data[i * 6 + 3]; // ไจโร X
      float gy = data[i * 6 + 4]; // ไจโร Y
      float gz = data[i * 6 + 5]; // ไจโร Z

      float accelMag = sqrt(ax * ax + ay * ay + az * az) / 9.81f; // หน่วย G
      float gyroMag = sqrt(gx * gx + gy * gy + gz * gz);          // หน่วย rad/s

      if (accelMag > maxAccelMag)
        maxAccelMag = accelMag;

      // ตรวจจับการตกอิสระ (ความเร่ง < 0.3G)
      if (accelMag < FREE_FALL_THRESHOLD) {
        freeFallCount++;
        inFreeFall = true;
      }
      // ตรวจจับแรงกระแทกหลังตกอิสระ (ความเร่ง > 3.5G)
      if (inFreeFall && accelMag > IMPACT_THRESHOLD) {
        impactCount++;
        hadFreeFallThenImpact = true;
        inFreeFall = false;
      }
      // นับไจโรสูง (หมุนตัวเร็ว > 3 rad/s)
      if (gyroMag > 3.0f)
        highGyroCount++;
    }

    // คำนวณความน่าจะเป็นของการล้ม
    float probability = 0.0f;
    if (hadFreeFallThenImpact && freeFallCount >= 3 && impactCount >= 2)
      probability += 0.6f; // มีรูปแบบตกอิสระ + กระแทก
    if (hadFreeFallThenImpact && maxAccelMag > 4.0f)
      probability += 0.2f; // แรงกระแทกสูงมาก
    if (hadFreeFallThenImpact && highGyroCount >= 5)
      probability += 0.15f; // มีการหมุนตัว

    return min(probability, 1.0f);
  }

  // ดึงค่าความมั่นใจขั้นต่ำ
  float getMinConfidence() { return MIN_FALL_CONFIDENCE; }

  // ==================== การจำแนกประเภท/ทิศทางการล้ม (Fall Identification) ====================
  // วิเคราะห์ทิศทางแรงกระแทกและเวกเตอร์ความเร่งเพื่อระบุท่าทางการล้ม
  const char* identifyFallType(float *data, int dataLength) {
    int numSamples = dataLength / 6;
    if (numSamples <= 0) return "fall_general";

    int peakIdx = 0;
    float maxMag = 0.0f;

    // 1. หาตำแหน่ง Impact Peak สูงสุด
    for (int i = 0; i < numSamples; i++) {
      float ax = data[i * 6 + 0];
      float ay = data[i * 6 + 1];
      float az = data[i * 6 + 2];
      float mag = sqrt(ax * ax + ay * ay + az * az);
      if (mag > maxMag) {
        maxMag = mag;
        peakIdx = i;
      }
    }

    // 2. วิเคราะห์ทิศทางแรงกระแทก ณ ช่วง Impact (เฉลี่ยรอบ peak)
    int startIdx = max(0, peakIdx - 2);
    int endIdx = min(numSamples - 1, peakIdx + 2);
    float avgAx = 0, avgAy = 0, avgAz = 0;
    int count = 0;

    for (int i = startIdx; i <= endIdx; i++) {
      avgAx += data[i * 6 + 0];
      avgAy += data[i * 6 + 1];
      avgAz += data[i * 6 + 2];
      count++;
    }
    if (count > 0) {
      avgAx /= count;
      avgAy /= count;
      avgAz /= count;
    }

    // 3. จำแนกตามแกนที่เกิดแรงกระแทกเด่นชัดที่สุด
    float absX = fabs(avgAx);
    float absY = fabs(avgAy);
    float absZ = fabs(avgAz);

    if (absX > absZ && absX > 10.0f) {
      if (avgAx > 0) {
        return "fall_lateral_right"; // ล้มไปด้านข้างขวา
      } else {
        return "fall_lateral_left";  // ล้มไปด้านข้างซ้าย
      }
    } else if (absZ >= absX && absZ > 10.0f) {
      if (avgAz > 0) {
        return "fall_forward";       // ล้มไปข้างหน้า
      } else {
        return "fall_backward";      // ล้มไปข้างหลัง
      }
    } else if (absY > 22.0f) {
      return "fall_vertical";        // ล้มแนวดิ่ง / ทรุดตัว
    }

    return "fall_general";           // การล้มทั่วไป
  }

  // คืนค่าชื่อภาษาไทยสำหรับแสดงผล
  const char* getFallTypeNameThai(const char* fallType) {
    if (strcmp(fallType, "fall_forward") == 0) return "ล้มไปข้างหน้า (Fall Forward)";
    if (strcmp(fallType, "fall_backward") == 0) return "ล้มไปข้างหลัง (Fall Backward)";
    if (strcmp(fallType, "fall_lateral_left") == 0) return "ล้มไปด้านข้างซ้าย (Fall Lateral Left)";
    if (strcmp(fallType, "fall_lateral_right") == 0) return "ล้มไปด้านข้างขวา (Fall Lateral Right)";
    if (strcmp(fallType, "fall_vertical") == 0) return "ล้มแนวดิ่ง/ทรุดตัว (Vertical Fall)";
    return "การล้มทั่วไป (General Fall)";
  }

  // ตรวจสอบว่าโมเดล ML ทำงานอยู่หรือไม่
  bool isMLModelActive() {
#if USE_ML_MODEL
    return true;
#else
    return false;
#endif
  }

  // รีเซ็ตตัวตรวจจับ
  void reset() {}
};

#endif // FALL_DETECTION_H
