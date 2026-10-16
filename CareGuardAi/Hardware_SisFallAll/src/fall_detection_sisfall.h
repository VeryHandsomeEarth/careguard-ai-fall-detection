/*
 * การตรวจจับการล้มและระบุท่าทางการล้มด้วย TinyML (Dual-MLP Native Forward Pass) — SisFall Edition
 * เทรนจากชุดข้อมูลมาตรฐานสากล SisFall Dataset (train_model_sisfall.py -> fall_model_sisfall.h)
 *
 * 1) โมเดลตรวจจับการล้ม (Binary Fall Detection MLP : 18 -> 32 -> 16 -> 1)
 * 2) โมเดลระบุท่าทางและทิศทางการล้ม (Posture Direction MLP : 45 -> 48 -> 24 -> 4)
 *    - Class 0: fall_forward       (F01, F04, F05, F08, F10, F13 — ล้มไปข้างหน้า)
 *    - Class 1: fall_backward      (F02, F11, F14 — ล้มไปข้างหลัง)
 *    - Class 2: fall_lateral_left / fall_lateral_right (F03, F09, F12, F15 — ล้มไปด้านข้าง)
 *    - Class 3: fall_vertical      (F06, F07 — ล้มแนวดิ่ง/ทรุดตัวจากอาการหน้ามืดเป็นลม)
 */

#ifndef FALL_DETECTION_SISFALL_H
#define FALL_DETECTION_SISFALL_H

#include <Arduino.h>
#include <math.h>

// ตรวจสอบว่ามีไฟล์โมเดล SisFall ที่เทรนแล้วหรือไม่
#if __has_include("fall_model_sisfall.h")
#define USE_ML_MODEL 1
#include "fall_model_sisfall.h"
#else
#define USE_ML_MODEL 0
#warning "fall_model_sisfall.h ไม่พบ - รัน tinyml/train_model_sisfall.py ก่อน"
#endif

class FallDetector {
private:
  bool initialized = false;

  // พารามิเตอร์ Threshold (ใช้เป็นระบบสำรอง)
  const float FREE_FALL_THRESHOLD = 0.3f;  // เกณฑ์การตกอิสระ (G)
  const float IMPACT_THRESHOLD = 3.5f;     // เกณฑ์แรงกระแทก (G)
  const float MIN_FALL_CONFIDENCE = 0.80f; // ค่าความมั่นใจขั้นต่ำ

  // บัฟเฟอร์เก็บ Feature สำหรับตรวจจับการล้ม (18 ตัว) และระบุท่าทาง (45 ตัว)
  float features[18];
  float postureFeatures[45];

  // ==================== 1. การสกัด 18 Features (Binary Fall Detection) ====================
  void extractFeatures(float *data, int numSamples) {
    float accel_mag[50];
    float gyro_mag[50];
    float horiz_mag[50];

    int n = min(numSamples, 50);

    for (int i = 0; i < n; i++) {
      float ax = data[i * 6 + 0];
      float ay = data[i * 6 + 1];
      float az = data[i * 6 + 2];
      float gx = data[i * 6 + 3];
      float gy = data[i * 6 + 4];
      float gz = data[i * 6 + 5];

      accel_mag[i] = sqrt(ax * ax + ay * ay + az * az) / 9.81f;
      gyro_mag[i] = sqrt(gx * gx + gy * gy + gz * gz);
      horiz_mag[i] = sqrt(ax * ax + az * az) / 9.81f;
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

    // Feature 4: พิสัย
    features[4] = max_am - min_am;

    // Feature 5: อัตราส่วนการตกอิสระ (< 0.3G)
    int ff_count = 0;
    for (int i = 0; i < n; i++)
      if (accel_mag[i] < 0.3f)
        ff_count++;
    features[5] = (float)ff_count / n;

    // Feature 6: อัตราส่วนแรงกระแทก (> 3.5G)
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

    // Feature 10: SMA (Signal Magnitude Area)
    float sum_sma = 0;
    for (int i = 0; i < n; i++) {
      sum_sma += (fabs(data[i * 6 + 0]) + fabs(data[i * 6 + 1]) +
                  fabs(data[i * 6 + 2])) /
                 9.81f;
    }
    features[10] = sum_sma / n;

    // Feature 11-12: ขนาดแนวนอนเฉลี่ยและสูงสุด
    float sum_hm = 0;
    float max_hm = horiz_mag[0];
    for (int i = 0; i < n; i++) {
      sum_hm += horiz_mag[i];
      if (horiz_mag[i] > max_hm)
        max_hm = horiz_mag[i];
    }
    features[11] = sum_hm / n;
    features[12] = max_hm;

    // Feature 13-14: Jerk เฉลี่ยและสูงสุด
    float sum_jerk = 0;
    float max_jerk = 0;
    for (int i = 1; i < n; i++) {
      float j = fabs(accel_mag[i] - accel_mag[i - 1]);
      sum_jerk += j;
      if (j > max_jerk)
        max_jerk = j;
    }
    features[13] = (n > 1) ? sum_jerk / (n - 1) : 0;
    features[14] = max_jerk;

    // Feature 15: อัตราการตัดผ่าน 1G
    int zc_count = 0;
    for (int i = 1; i < n; i++) {
      if ((accel_mag[i] - 1.0f) * (accel_mag[i - 1] - 1.0f) < 0)
        zc_count++;
    }
    features[15] = (float)zc_count;

    // Feature 16: สหสัมพันธ์ XY
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

    // Feature 17: สหสัมพันธ์ YZ
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

  // ==================== 2. การสกัด 45 Posture Features (Multi-Class Posture MLP) ====================
  // ตรงกับ extract_posture_features() ใน train_model_sisfall.py ทุกประการ
  void extractPostureFeatures(float *data, int numSamples) {
    int n = min(numSamples, 50);
    if (n <= 0) {
      for (int i = 0; i < 45; i++)
        postureFeatures[i] = 0.0f;
      return;
    }

    float accel[50][3];
    float gyro[50][3];
    int pk = 0;
    float maxMag = -1.0f;

    for (int i = 0; i < n; i++) {
      accel[i][0] = data[i * 6 + 0] / 9.81f;
      accel[i][1] = data[i * 6 + 1] / 9.81f;
      accel[i][2] = data[i * 6 + 2] / 9.81f;
      gyro[i][0] = data[i * 6 + 3];
      gyro[i][1] = data[i * 6 + 4];
      gyro[i][2] = data[i * 6 + 5];

      float mag = sqrt(accel[i][0] * accel[i][0] + accel[i][1] * accel[i][1] +
                       accel[i][2] * accel[i][2]);
      if (mag > maxMag) {
        maxMag = mag;
        pk = i;
      }
    }

    int preEnd = min(n, 15);
    int postStart = min(n - 1, 32);
    int postCount = max(1, n - postStart);

    // 0..2: pre_mean & 3..5: pre_std
    for (int a = 0; a < 3; a++) {
      float sum = 0;
      for (int i = 0; i < preEnd; i++)
        sum += accel[i][a];
      float mean = sum / preEnd;
      postureFeatures[0 + a] = mean;

      float var = 0;
      for (int i = 0; i < preEnd; i++) {
        float d = accel[i][a] - mean;
        var += d * d;
      }
      postureFeatures[3 + a] = sqrt(var / preEnd);
    }

    // 6..8: post_mean, 9..11: post_abs_mean, 12..14: post_std, 15..17: delta_mean
    for (int a = 0; a < 3; a++) {
      float sum = 0, absSum = 0;
      for (int i = postStart; i < n; i++) {
        sum += accel[i][a];
        absSum += fabs(accel[i][a]);
      }
      float mean = sum / postCount;
      postureFeatures[6 + a] = mean;
      postureFeatures[9 + a] = absSum / postCount;

      float var = 0;
      for (int i = postStart; i < n; i++) {
        float d = accel[i][a] - mean;
        var += d * d;
      }
      postureFeatures[12 + a] = sqrt(var / postCount);
      postureFeatures[15 + a] = mean - postureFeatures[0 + a];
    }

    // 18..20: pk_a, 21..23: pk_g
    for (int a = 0; a < 3; a++) {
      postureFeatures[18 + a] = accel[pk][a];
      postureFeatures[21 + a] = gyro[pk][a];
    }

    // 24..26: imp_a_mean, 27..29: imp_g_mean
    int sPk = max(0, pk - 3);
    int ePk = min(n, pk + 4);
    int impCount = max(1, ePk - sPk);
    for (int a = 0; a < 3; a++) {
      float sumA = 0, sumG = 0;
      for (int i = sPk; i < ePk; i++) {
        sumA += accel[i][a];
        sumG += gyro[i][a];
      }
      postureFeatures[24 + a] = sumA / impCount;
      postureFeatures[27 + a] = sumG / impCount;
    }

    // 30..32: max_a, 33..35: min_a, 36..38: max_g, 39..41: min_g
    for (int a = 0; a < 3; a++) {
      float maxA = accel[0][a], minA = accel[0][a];
      float maxG = gyro[0][a], minG = gyro[0][a];
      for (int i = 1; i < n; i++) {
        if (accel[i][a] > maxA) maxA = accel[i][a];
        if (accel[i][a] < minA) minA = accel[i][a];
        if (gyro[i][a] > maxG) maxG = gyro[i][a];
        if (gyro[i][a] < minG) minG = gyro[i][a];
      }
      postureFeatures[30 + a] = maxA;
      postureFeatures[33 + a] = minA;
      postureFeatures[36 + a] = maxG;
      postureFeatures[39 + a] = minG;
    }

    // 42..44: rot_sum (samples 10..34)
    int rStart = min(n, 10);
    int rEnd = min(n, 35);
    for (int a = 0; a < 3; a++) {
      float rSum = 0;
      for (int i = rStart; i < rEnd; i++)
        rSum += gyro[i][a];
      postureFeatures[42 + a] = rSum * 0.02f;
    }
  }

#if USE_ML_MODEL
  // ==================== MLP Forward Pass ====================
  float relu(float x) { return x > 0 ? x : 0; }

  float sigmoid(float x) {
    if (x > 10.0f)
      return 1.0f;
    if (x < -10.0f)
      return 0.0f;
    return 1.0f / (1.0f + exp(-x));
  }

  // 1) Binary Fall Detection Forward Pass (18 -> 32 -> 16 -> 1)
  float mlpForward(float *input) {
    float normalized[MODEL_INPUT_FEATURES];
    for (int i = 0; i < MODEL_INPUT_FEATURES; i++) {
      normalized[i] = (input[i] - MODEL_SCALER_MEAN[i]) / MODEL_SCALER_SCALE[i];
    }

    float h1[32];
    for (int j = 0; j < 32; j++) {
      float sum = MODEL_B0[j];
      for (int i = 0; i < MODEL_INPUT_FEATURES; i++) {
        sum += normalized[i] * MODEL_W0[i * 32 + j];
      }
      h1[j] = relu(sum);
    }

    float h2[16];
    for (int j = 0; j < 16; j++) {
      float sum = MODEL_B1[j];
      for (int i = 0; i < 32; i++) {
        sum += h1[i] * MODEL_W1[i * 16 + j];
      }
      h2[j] = relu(sum);
    }

    float output = MODEL_B2[0];
    for (int i = 0; i < 16; i++) {
      output += h2[i] * MODEL_W2[i];
    }

    return sigmoid(output);
  }

#if defined(HAS_POSTURE_ML_MODEL) && HAS_POSTURE_ML_MODEL
  // 2) Multi-Class Posture Identification Forward Pass (45 -> 48 -> 24 -> 4)
  // คืนค่าคลาสที่มีคะแนนสูงสุด: 0 = Forward, 1 = Backward, 2 = Lateral, 3 = Vertical
  int postureMlpForward(float *input) {
    float norm[POSTURE_INPUT_FEATURES];
    for (int i = 0; i < POSTURE_INPUT_FEATURES; i++) {
      norm[i] = (input[i] - POSTURE_SCALER_MEAN[i]) / POSTURE_SCALER_SCALE[i];
    }

    float h1[48];
    for (int j = 0; j < 48; j++) {
      float sum = POSTURE_B0[j];
      for (int i = 0; i < POSTURE_INPUT_FEATURES; i++) {
        sum += norm[i] * POSTURE_W0[i * 48 + j];
      }
      h1[j] = relu(sum);
    }

    float h2[24];
    for (int j = 0; j < 24; j++) {
      float sum = POSTURE_B1[j];
      for (int i = 0; i < 48; i++) {
        sum += h1[i] * POSTURE_W1[i * 24 + j];
      }
      h2[j] = relu(sum);
    }

    int bestClass = 0;
    float bestLogit = -1e9f;
    for (int k = 0; k < POSTURE_NUM_CLASSES; k++) {
      float logit = POSTURE_B2[k];
      for (int i = 0; i < 24; i++) {
        logit += h2[i] * POSTURE_W2[i * POSTURE_NUM_CLASSES + k];
      }
      if (logit > bestLogit) {
        bestLogit = logit;
        bestClass = k;
      }
    }
    return bestClass;
  }
#endif
#endif

public:
  bool begin() {
    initialized = true;

#if USE_ML_MODEL
    Serial.println("[ตรวจจับ-SisFall] โหลดโมเดล Dual-MLP สำเร็จ (Fall 18->32->16->1 + Posture 45->48->24->4)");
#else
    Serial.println("[ตรวจจับ-SisFall] ใช้ระบบ Threshold เท่านั้น");
    Serial.println("[ตรวจจับ-SisFall] รัน tinyml/train_model_sisfall.py เพื่อสร้าง fall_model_sisfall.h");
#endif
    Serial.println("[ตรวจจับ-SisFall] เกณฑ์ตกอิสระ: 0.3G, แรงกระแทก: 3.5G");

    return true;
  }

  // ทำนายการล้มจากข้อมูลเซ็นเซอร์ดิบ (คืนค่าความมั่นใจ 0.0 - 1.0)
  float predict(float *data, int dataLength) {
    if (!initialized)
      return 0.0f;

    int numSamples = dataLength / 6;
    float mlConfidence = 0.0f;
    float thresholdConfidence = 0.0f;

#if USE_ML_MODEL
    extractFeatures(data, numSamples);
    mlConfidence = mlpForward(features);
#endif

    thresholdConfidence = predictThreshold(data, numSamples);
    return max(mlConfidence, thresholdConfidence);
  }

  // ระบบตรวจจับแบบ Threshold (สำรอง)
  float predictThreshold(float *data, int numSamples) {
    float maxAccelMag = 0;
    int freeFallCount = 0, impactCount = 0, highGyroCount = 0;
    bool hadFreeFallThenImpact = false;
    bool inFreeFall = false;

    for (int i = 0; i < numSamples; i++) {
      float ax = data[i * 6 + 0];
      float ay = data[i * 6 + 1];
      float az = data[i * 6 + 2];
      float gx = data[i * 6 + 3];
      float gy = data[i * 6 + 4];
      float gz = data[i * 6 + 5];

      float accelMag = sqrt(ax * ax + ay * ay + az * az) / 9.81f;
      float gyroMag = sqrt(gx * gx + gy * gy + gz * gz);

      if (accelMag > maxAccelMag)
        maxAccelMag = accelMag;

      if (accelMag < FREE_FALL_THRESHOLD) {
        freeFallCount++;
        inFreeFall = true;
      }
      if (inFreeFall && accelMag > IMPACT_THRESHOLD) {
        impactCount++;
        hadFreeFallThenImpact = true;
        inFreeFall = false;
      }
      if (gyroMag > 3.0f)
        highGyroCount++;
    }

    float probability = 0.0f;
    if (hadFreeFallThenImpact && freeFallCount >= 3 && impactCount >= 2)
      probability += 0.6f;
    if (hadFreeFallThenImpact && maxAccelMag > 4.0f)
      probability += 0.2f;
    if (hadFreeFallThenImpact && highGyroCount >= 5)
      probability += 0.15f;

    return min(probability, 1.0f);
  }

  float getMinConfidence() { return MIN_FALL_CONFIDENCE; }

  // ==================== การจำแนกประเภท/ทิศทางการล้มด้วย SisFall Posture MLP ====================
  const char* identifyFallType(float *data, int dataLength) {
    int numSamples = dataLength / 6;
    if (numSamples <= 0) return "fall_general";

    extractPostureFeatures(data, numSamples);

#if USE_ML_MODEL && defined(HAS_POSTURE_ML_MODEL) && HAS_POSTURE_ML_MODEL
    int predClass = postureMlpForward(postureFeatures);
    if (predClass == 0) {
      return "fall_forward";       // ล้มไปข้างหน้า (F01, F04, F05, F08, F10, F13)
    } else if (predClass == 1) {
      return "fall_backward";      // ล้มไปข้างหลัง (F02, F11, F14)
    } else if (predClass == 2) {
      // แยกซ้าย-ขวาตามทิศทางเวกเตอร์แกน X หลังกระแทกพื้น (post_mean_x = postureFeatures[6])
      float postAx = postureFeatures[6];
      if (fabs(postAx) < 0.1f) postAx = postureFeatures[24]; // fallback: imp_mean_ax
      return (postAx >= 0.0f) ? "fall_lateral_right" : "fall_lateral_left";
    } else if (predClass == 3) {
      return "fall_vertical";      // ล้มแนวดิ่ง/ทรุดตัวจากอาการหน้ามืด (F06, F07)
    }
#endif

    // ระบบสำรอง (Calibrated SisFall Post-Impact Gravity Orientation)
    float postAx = postureFeatures[6];
    float postAy = postureFeatures[7];
    float postAz = postureFeatures[8];
    if (fabs(postAx) > fabs(postAz) && fabs(postAx) > 0.45f) {
      return (postAx >= 0.0f) ? "fall_lateral_right" : "fall_lateral_left";
    } else if (fabs(postAz) >= fabs(postAx) && fabs(postAz) > 0.40f) {
      // ตามแกนเซนเซอร์ SisFall: ล้มคว่ำไปข้างหน้า az < 0, ล้มหงายไปข้างหลัง az > 0
      return (postAz < 0.0f) ? "fall_forward" : "fall_backward";
    } else if (fabs(postAy) > 0.6f) {
      return "fall_vertical";
    }
    return "fall_general";
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

  bool isMLModelActive() {
#if USE_ML_MODEL
    return true;
#else
    return false;
#endif
  }

  void reset() {}
};

#endif // FALL_DETECTION_SISFALL_H
