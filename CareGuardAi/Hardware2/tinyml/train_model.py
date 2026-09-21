"""
การเทรนโมเดล TinyML สำหรับตรวจจับการล้ม (Hybrid Dataset Edition)
============================================================
- Fall Data: ข้อมูลจริงจาก CollectData_Web (ESP32 + MPU-6050)
- ADL Data: ข้อมูลจริงจาก Kaggle SisFall Dataset (38 ผู้ทดสอบ, 19 กิจกรรม D01-D19)
- โมเดล: MLP (18 -> 32 -> 16 -> 1) รันแบบ Native C++ บน ESP32

ส่งออกผลลัพธ์:
  - CollectData_HardwareV2/src/fall_model.h
  - Hardware1/src/fall_model.h
  - D:\ssPro\fall_model.h
"""

import os
import sys
import glob
import re

# ตั้งค่า stdout ให้รองรับ utf-8
try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

import numpy as np
import pandas as pd
from sklearn.neural_network import MLPClassifier
from sklearn.preprocessing import StandardScaler
from sklearn.model_selection import train_test_split
from sklearn.metrics import classification_report, confusion_matrix

# ==================== การตั้งค่าเส้นทาง ====================
BASE_DIR = r"C:\Users\Earth\Desktop\Pro"
OUTPUT_MODEL_H = os.path.join(BASE_DIR, 'CollectData_HardwareV2', 'src', 'fall_model.h')
OUTPUT_MODEL_H_HW1 = os.path.join(BASE_DIR, 'Hardware1', 'src', 'fall_model.h')
OUTPUT_MODEL_H_DSPRO = r"D:\ssPro\fall_model.h"

COLLECTDATA_WEB_DIR = r"D:\ssPro\CollectData_Web_Dataset\sensor_records"
SISFALL_DIR = r"D:\ssPro\SisFall_Dataset"

WINDOW_SIZE = 50       # 50 ตัวอย่าง = 1 วินาที ที่ 50Hz
NUM_AXES = 6           # Accel X,Y,Z + Gyro X,Y,Z
INPUT_FEATURES = 18    # จำนวน 18 Statistical Features


def extract_features(window):
    """สกัด 18 คุณลักษณะทางสถิติจากหน้าต่างข้อมูล 50 จุด x 6 แกน"""
    ax, ay, az = window[:, 0], window[:, 1], window[:, 2]
    gx, gy, gz = window[:, 3], window[:, 4], window[:, 5]
    
    # คำนวณขนาดเวกเตอร์ (หน่วย G)
    accel_mag = np.sqrt(ax**2 + ay**2 + az**2) / 9.81
    gyro_mag = np.sqrt(gx**2 + gy**2 + gz**2)
    horiz_mag = np.sqrt(ax**2 + az**2) / 9.81
    
    # Jerk (อนุพันธ์ของความเร่ง)
    jerk = np.diff(accel_mag)
    
    features = [
        np.mean(accel_mag),                          # 0: ค่าเฉลี่ยขนาดความเร่ง
        np.max(accel_mag),                           # 1: ค่าสูงสุดขนาดความเร่ง
        np.min(accel_mag),                           # 2: ค่าต่ำสุดขนาดความเร่ง
        np.std(accel_mag),                           # 3: ส่วนเบี่ยงเบนมาตรฐาน
        np.max(accel_mag) - np.min(accel_mag),       # 4: พิสัย
        np.sum(accel_mag < 0.3) / len(accel_mag),    # 5: อัตราส่วนตกอิสระ
        np.sum(accel_mag > 3.5) / len(accel_mag),    # 6: อัตราส่วนแรงกระแทก
        np.mean(gyro_mag),                           # 7: ค่าเฉลี่ยไจโร
        np.max(gyro_mag),                            # 8: ค่าสูงสุดไจโร
        np.std(gyro_mag),                            # 9: ส่วนเบี่ยงเบนไจโร
        np.mean(np.abs(ax) + np.abs(ay) + np.abs(az)) / 9.81,  # 10: SMA
        np.mean(horiz_mag),                          # 11: ค่าเฉลี่ยแนวนอน
        np.max(horiz_mag),                           # 12: ค่าสูงสุดแนวนอน
        np.mean(np.abs(jerk)) if len(jerk) > 0 else 0,  # 13: ค่าเฉลี่ย Jerk
        np.max(np.abs(jerk)) if len(jerk) > 0 else 0,   # 14: ค่าสูงสุด Jerk
        np.sum(np.diff(np.sign(accel_mag - 1.0)) != 0),  # 15: อัตราตัดผ่านศูนย์
        np.corrcoef(ax, ay)[0, 1] if np.std(ax) > 0 and np.std(ay) > 0 else 0,  # 16: สหสัมพันธ์ XY
        np.corrcoef(ay, az)[0, 1] if np.std(ay) > 0 and np.std(az) > 0 else 0,  # 17: สหสัมพันธ์ YZ
    ]
    
    return np.array(features, dtype=np.float32)


def load_fall_data_from_collectdata_web():
    """โหลดข้อมูลการล้มจริงจากไฟล์ CSV ใน CollectData_Web (Label = 1)"""
    print("[1/4] กำลังโหลดข้อมูลการล้มจาก CollectData_Web...")
    csv_files = glob.glob(os.path.join(COLLECTDATA_WEB_DIR, "*.csv"))
    print(f"  พบไฟล์เซ็นเซอร์การล้มทั้งหมด: {len(csv_files)} ไฟล์")
    
    fall_features = []
    adl_from_prefall = []
    
    for f in csv_files:
        try:
            df = pd.read_csv(f)
            # ดึง 6 แกน: accel_x, accel_y, accel_z, gyro_x, gyro_y, gyro_z
            data = df[['accel_x', 'accel_y', 'accel_z', 'gyro_x', 'gyro_y', 'gyro_z']].values
            
            if len(data) < 50:
                continue
                
            # หาจุดกระแทกสูงสุด (Impact Peak)
            accel_mag = np.linalg.norm(data[:, 0:3], axis=1) / 9.81
            peak_idx = np.argmax(accel_mag)
            
            # ตัด Window 50 จุด รอบจุดกระแทก (Impact-centered Window)
            start_idx = max(0, peak_idx - 20)
            end_idx = start_idx + 50
            if end_idx > len(data):
                end_idx = len(data)
                start_idx = max(0, end_idx - 50)
                
            window_main = data[start_idx:end_idx]
            if len(window_main) == 50:
                fall_features.append(extract_features(window_main))
                
            # Data Augmentation เล็กน้อยเพื่อความแม่นยำ
            # Window 2: เริ่มล้ม
            start_2 = max(0, peak_idx - 25)
            if start_2 + 50 <= len(data):
                fall_features.append(extract_features(data[start_2:start_2+50]))
                
            # Window 3: กระแทกและเริ่มนิ่ง
            start_3 = max(0, peak_idx - 15)
            if start_3 + 50 <= len(data):
                fall_features.append(extract_features(data[start_3:start_3+50]))
                
            # Pre-fall (ช่วงปกติก่อนล้ม 50 จุดแรก) นำมาเป็นตัวอย่าง ADL
            if len(data) >= 70:
                adl_from_prefall.append(extract_features(data[0:50]))
                
        except Exception as e:
            print(f"  ข้อผิดพลาดไฟล์ {os.path.basename(f)}: {e}")
            
    print(f"  [สำเร็จ] สกัดตัวอย่าง Fall ได้ {len(fall_features)} หน้าต่าง (Label = 1)")
    print(f"  [สำเร็จ] สกัดตัวอย่าง Normal ก่อนล้มได้ {len(adl_from_prefall)} หน้าต่าง (Label = 0)")
    
    return fall_features, adl_from_prefall


def load_adl_data_from_sisfall(target_count=300):
    """โหลดข้อมูล ADL (D01-D19) จาก Kaggle SisFall Dataset (Label = 0)"""
    print(f"\n[2/4] กำลังโหลดข้อมูลกิจวัตรประจำวัน (ADL) จาก Kaggle SisFall...")
    adl_files = glob.glob(os.path.join(SISFALL_DIR, "**", "D*.txt"), recursive=True)
    print(f"  พบไฟล์ ADL ทั้งหมด: {len(adl_files)} ไฟล์ (จาก 38 ผู้ทดสอบ)")
    
    # สุ่มเลือกไฟล์ให้กระจายครบทุกกิจกรรม D01-D19
    np.random.seed(42)
    np.random.shuffle(adl_files)
    
    adl_features = []
    
    for f in adl_files:
        if len(adl_features) >= target_count:
            break
        try:
            # อ่านข้อมูล 9 คอลัมน์
            with open(f, 'r') as file:
                lines = [line.strip().rstrip(';') for line in file if line.strip()]
            
            rows = []
            for line in lines:
                parts = [float(p) for p in re.split(r'[,;\s]+', line) if p]
                if len(parts) >= 6:
                    rows.append(parts[:6])
                    
            if len(rows) < 200: # สั้นเกินไป
                continue
                
            raw_data = np.array(rows, dtype=np.float32)
            
            # 1. แปลงหน่วย Bits -> SI Units
            converted = np.zeros((len(raw_data), 6), dtype=np.float32)
            # ADXL345: ±16g, 13-bit -> m/s²
            converted[:, 0:3] = (raw_data[:, 0:3] * (32.0 / 8192.0)) * 9.81
            # ITG3200: ±2000 deg/s, 16-bit -> rad/s
            converted[:, 3:6] = (raw_data[:, 3:6] * (2000.0 / 32768.0)) * (np.pi / 180.0)
            
            # 2. Downsample 200Hz -> 50Hz (ลด 4 เท่า)
            data_50hz = converted[::4]
            
            # 3. ตัด Sliding Window 50 จุด
            for start_idx in range(0, len(data_50hz) - 50 + 1, 50): # ไม่ซ้อนทับกัน
                window = data_50hz[start_idx:start_idx + 50]
                adl_features.append(extract_features(window))
                if len(adl_features) >= target_count:
                    break
                    
        except Exception as e:
            continue
            
    print(f"  [สำเร็จ] สกัดตัวอย่าง ADL จาก SisFall ได้ {len(adl_features)} หน้าต่าง (Label = 0)")
    return adl_features


def export_mlp_to_c(clf, scaler, output_paths):
    """ส่งออกค่าน้ำหนัก MLP และตัวแปร Scaler เป็น C Header"""
    weights = clf.coefs_
    biases = clf.intercepts_
    n_layers = len(weights)
    total_params = sum(w.size for w in weights) + sum(b.size for b in biases)
    
    header_content = []
    header_content.append("/*")
    header_content.append(" * โมเดล TinyML ตรวจจับการล้ม (Hybrid Dataset Model)")
    header_content.append(" * เทรนจากข้อมูลจริง: CollectData_Web (Fall) + Kaggle SisFall (ADL)")
    header_content.append(f" * สถาปัตยกรรม: MLP {n_layers} ชั้น (18 -> 32 -> 16 -> 1)")
    header_content.append(f" * พารามิเตอร์ทั้งหมด: {total_params}")
    header_content.append(" */\n")
    header_content.append("#ifndef FALL_MODEL_H")
    header_content.append("#define FALL_MODEL_H\n")
    header_content.append(f"#define MODEL_NUM_LAYERS {n_layers}")
    header_content.append(f"#define MODEL_INPUT_FEATURES {INPUT_FEATURES}\n")
    
    layer_sizes = [weights[0].shape[0]] + [w.shape[1] for w in weights]
    header_content.append(f"const int MODEL_LAYER_SIZES[{len(layer_sizes)}] = {{{', '.join(str(s) for s in layer_sizes)}}};\n")
    
    # Scaler Mean & Scale
    header_content.append(f"const float MODEL_SCALER_MEAN[{INPUT_FEATURES}] = {{")
    header_content.append("    " + ", ".join(f"{v:.8f}f" for v in scaler.mean_))
    header_content.append("};\n")
    
    header_content.append(f"const float MODEL_SCALER_SCALE[{INPUT_FEATURES}] = {{")
    header_content.append("    " + ", ".join(f"{v:.8f}f" for v in scaler.scale_))
    header_content.append("};\n")
    
    # Weights & Biases
    for layer_idx, (w, b) in enumerate(zip(weights, biases)):
        rows, cols = w.shape
        header_content.append(f"const float MODEL_W{layer_idx}[{rows * cols}] = {{")
        flat = w.flatten()
        for i in range(0, len(flat), 8):
            chunk = flat[i:i+8]
            vals = ", ".join(f"{v:.8f}f" for v in chunk)
            header_content.append(f"    {vals},")
        header_content.append("};\n")
        
        header_content.append(f"const float MODEL_B{layer_idx}[{cols}] = {{")
        header_content.append("    " + ", ".join(f"{v:.8f}f" for v in b))
        header_content.append("};\n")
        
    header_content.append("#endif // FALL_MODEL_H\n")
    
    full_text = "\n".join(header_content)
    
    for path in output_paths:
        try:
            os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
            with open(path, 'w', encoding='utf-8') as f:
                f.write(full_text)
            print(f"  [สำเร็จ] ส่งออก C Header ไปที่: {path}")
        except Exception as e:
            print(f"  ข้อผิดพลาดในการบันทึก {path}: {e}")


def main():
    print("=" * 65)
    print("  [START] เริ่มกระบวนการเทรนโมเดล TinyML Fall Detection (Hybrid Dataset)")
    print("=" * 65)
    
    # 1. โหลดข้อมูล Fall จาก CollectData_Web
    fall_feats, adl_prefall = load_fall_data_from_collectdata_web()
    
    # 2. โหลดข้อมูล ADL จาก SisFall (ต้องการสัดส่วนประมาณ 1.5x - 2.0x ของ Fall)
    target_adl_count = int(len(fall_feats) * 1.8)
    sisfall_adl_feats = load_adl_data_from_sisfall(target_count=target_adl_count)
    
    all_adl_feats = adl_prefall + sisfall_adl_feats
    
    # สร้างเมทริกซ์ X, y
    X_fall = np.array(fall_feats, dtype=np.float32)
    y_fall = np.ones(len(X_fall), dtype=np.float32)
    
    X_adl = np.array(all_adl_feats, dtype=np.float32)
    y_adl = np.zeros(len(X_adl), dtype=np.float32)
    
    X = np.vstack([X_fall, X_adl])
    y = np.concatenate([y_fall, y_adl])
    
    # จัดการ NaN/Inf
    X = np.nan_to_num(X, nan=0.0, posinf=10.0, neginf=-10.0)
    
    print("\n[3/4] สรุปชุดข้อมูลสำหรับการเทรน:")
    print(f"  • จำนวนตัวอย่างทั้งหมด: {len(X)} หน้าต่าง (18 Features)")
    print(f"  • การล้ม (Fall / Label 1): {len(X_fall)} ตัวอย่าง ({len(X_fall)/len(X)*100:.1f}%)")
    print(f"  • กิจวัตรปกติ (ADL / Label 0): {len(X_adl)} ตัวอย่าง ({len(X_adl)/len(X)*100:.1f}%)")
    
    # ปรับค่ามาตรฐาน (StandardScaler)
    scaler = StandardScaler()
    X_scaled = scaler.fit_transform(X)
    
    # แบ่งข้อมูล Train 80% / Test 20%
    X_train, X_test, y_train, y_test = train_test_split(
        X_scaled, y, test_size=0.2, random_state=42, stratify=y
    )
    print(f"  • แบ่งข้อมูล: เทรน {len(X_train)} ตัวอย่าง, ทดสอบ {len(X_test)} ตัวอย่าง")
    
    # 3. เทรนโมเดล MLP
    print("\n[4/4] กำลังเทรนโมเดล MLP (18 -> 32 -> 16 -> 1)...")
    clf = MLPClassifier(
        hidden_layer_sizes=(32, 16),
        activation='relu',
        solver='adam',
        max_iter=600,
        random_state=42,
        early_stopping=True,
        validation_fraction=0.15,
        n_iter_no_change=25,
        verbose=False,
        learning_rate_init=0.001
    )
    
    clf.fit(X_train, y_train)
    
    # ประเมินผลโมเดล
    train_acc = clf.score(X_train, y_train)
    test_acc = clf.score(X_test, y_test)
    y_pred = clf.predict(X_test)
    
    cm = confusion_matrix(y_test, y_pred)
    tn, fp, fn, tp = cm.ravel()
    sensitivity = tp / (tp + fn) if (tp + fn) > 0 else 0
    specificity = tn / (tn + fp) if (tn + fp) > 0 else 0
    
    print("\n" + "=" * 65)
    print("  [EVALUATION] ผลการประเมินประสิทธิภาพโมเดล TinyML")
    print("=" * 65)
    print(f"  - Train Accuracy (ความแม่นยำบน Train Set): {train_acc*100:.2f}%")
    print(f"  - Test Accuracy (ความแม่นยำบน Test Set):   {test_acc*100:.2f}%")
    print(f"  - Sensitivity (อัตราตรวจจับการล้มถูกต้อง): {sensitivity*100:.2f}% (TP={tp}, FN={fn})")
    print(f"  - Specificity (อัตราจำแนกท่าปกติถูกต้อง):   {specificity*100:.2f}% (TN={tn}, FP={fp})")
    print("\nรายงานการจำแนก (Classification Report):")
    print(classification_report(y_test, y_pred, target_names=['กิจวัตร (ADL)', 'การล้ม (Fall)'], digits=4))
    
    # ส่งออกเป็น C Header
    print("กำลังส่งออกค่าน้ำหนักโมเดลไปยังไฟล์ C Header...")
    export_mlp_to_c(clf, scaler, [OUTPUT_MODEL_H, OUTPUT_MODEL_H_HW1, OUTPUT_MODEL_H_DSPRO])
    
    print("\n" + "=" * 65)
    print("  [SUCCESS] เทรนโมเดลและส่งออกไฟล์ C Header สำเร็จเรียบร้อยแล้ว!")
    print("=" * 65)


if __name__ == '__main__':
    main()
