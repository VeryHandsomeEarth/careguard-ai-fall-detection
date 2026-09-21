"""
การเทรนโมเดล TinyML สำหรับตรวจจับการล้ม
=====================================
เทรนโมเดล MLP ด้วยข้อมูลจำลองจาก SisFall
ส่งออกค่าน้ำหนักเป็นไฟล์ C Header สำหรับ ESP32
ไม่ต้องใช้ TensorFlow — ใช้ scikit-learn + forward pass แบบ C

วิธีใช้:
    pip install numpy pandas scikit-learn
    python train_model.py

ผลลัพธ์:
    ../src/fall_model.h  (ไฟล์ C Header พร้อมค่าน้ำหนักโมเดล)
"""

import os
import numpy as np
from pathlib import Path

# ==================== การตั้งค่า ====================
OUTPUT_MODEL_H = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'src', 'fall_model.h')

# พารามิเตอร์โมเดล (ตรงกับ MPU-6050 บน ESP32)
TARGET_SAMPLE_RATE = 50       # อัตราสุ่มตัวอย่าง (Hz)
WINDOW_SIZE = 50              # จำนวนตัวอย่างต่อหน้าต่าง (1 วินาที ที่ 50Hz)
NUM_AXES = 6                  # แกนข้อมูล: accel_x, accel_y, accel_z, gyro_x, gyro_y, gyro_z
INPUT_FEATURES = 18           # จำนวน Feature ที่สกัดได้ (ไม่ใช่ข้อมูลดิบ)


def extract_features(window):
    """สกัด Feature ทางสถิติจากหน้าต่างข้อมูลเดียว
    
    อินพุต: อาร์เรย์ขนาด (WINDOW_SIZE, NUM_AXES)
    เอาต์พุต: เวกเตอร์ Feature ความยาว INPUT_FEATURES
    
    Feature (ตามเอกสาร SisFall):
    - ขนาดความเร่ง: ค่าเฉลี่ย, สูงสุด, ต่ำสุด, ส่วนเบี่ยงเบน, พิสัย
    - อัตราส่วนตกอิสระ (ตัวอย่างที่ < 0.3G)
    - อัตราส่วนแรงกระแทก (ตัวอย่างที่ > 3.5G)
    - ขนาดไจโร: ค่าเฉลี่ย, สูงสุด, ส่วนเบี่ยงเบน
    - SMA (Signal Magnitude Area)
    - ขนาดแนวนอน: ค่าเฉลี่ย, สูงสุด
    - พิสัยความเร่ง (สูงสุด - ต่ำสุด)
    - Jerk: ค่าเฉลี่ยอัตราการเปลี่ยนแปลงความเร่ง
    - อัตราตัดผ่านศูนย์
    - ค่าสหสัมพันธ์ระหว่างแกน
    """
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


def generate_training_data():
    """สร้างข้อมูลจำลองสำหรับเทรน (การล้ม/กิจวัตรประจำวัน)"""
    
    print("[ข้อมูล] กำลังสร้างข้อมูลสำหรับเทรน...")
    np.random.seed(42)
    
    num_fall_samples = 3000   # จำนวนตัวอย่างการล้ม
    num_adl_samples = 4000    # จำนวนตัวอย่างกิจวัตรประจำวัน (ADL)
    
    all_features = []
    all_labels = []
    
    # สร้างตัวอย่างการล้ม
    for _ in range(num_fall_samples):
        window = np.zeros((WINDOW_SIZE, NUM_AXES))
        
        # สุ่มรูปแบบการล้ม
        phase1_end = np.random.randint(8, 20)      # สิ้นสุดเฟสปกติ
        phase2_end = phase1_end + np.random.randint(3, 10)  # สิ้นสุดเฟสตกอิสระ
        phase3_end = phase2_end + np.random.randint(3, 8)   # สิ้นสุดเฟสกระแทก
        
        # เฟส 1: ปกติ (ก่อนล้ม)
        for i in range(min(phase1_end, WINDOW_SIZE)):
            window[i, 0] = np.random.normal(0, 1.5)
            window[i, 1] = np.random.normal(-9.81, 1.5)
            window[i, 2] = np.random.normal(0, 1.5)
            window[i, 3:6] = np.random.normal(0, 0.5, 3)
        
        # เฟส 2: ตกอิสระ (ความเร่งต่ำมาก ~0G)
        for i in range(phase1_end, min(phase2_end, WINDOW_SIZE)):
            window[i, 0:3] = np.random.normal(0, 0.3, 3)
            window[i, 3:6] = np.random.normal(0, np.random.uniform(1.5, 4.0), 3)
        
        # เฟส 3: กระแทก (ค่า G สูงมาก)
        impact_g = np.random.uniform(25, 80)
        for i in range(phase2_end, min(phase3_end, WINDOW_SIZE)):
            angle = np.random.uniform(0, 2 * np.pi)
            window[i, 0] = impact_g * np.cos(angle) * np.random.uniform(0.5, 1.5)
            window[i, 1] = impact_g * np.sin(angle) - 9.81
            window[i, 2] = np.random.normal(0, impact_g * 0.3)
            window[i, 3:6] = np.random.normal(0, np.random.uniform(3.0, 8.0), 3)
        
        # เฟส 4: นิ่ง (หลังล้ม)
        for i in range(phase3_end, WINDOW_SIZE):
            window[i, 0] = np.random.normal(0, 0.3)
            window[i, 1] = np.random.normal(-9.81, 0.3)
            window[i, 2] = np.random.normal(0, 0.3)
            window[i, 3:6] = np.random.normal(0, 0.1, 3)
        
        all_features.append(extract_features(window))
        all_labels.append(1)  # 1 = การล้ม
    
    # สร้างตัวอย่างกิจวัตรประจำวัน (ADL)
    activities = ['walk', 'sit', 'stand', 'jog', 'stairs', 'sit_stand', 'bend']
    # กิจกรรม: เดิน, นั่ง, ยืน, วิ่งเหยาะ, ขึ้นบันได, ลุก-นั่ง, ก้มตัว
    
    for _ in range(num_adl_samples):
        activity = np.random.choice(activities)
        window = np.zeros((WINDOW_SIZE, NUM_AXES))
        
        for i in range(WINDOW_SIZE):
            t = i / TARGET_SAMPLE_RATE
            
            if activity == 'walk':   # เดิน
                freq = np.random.uniform(1.5, 2.5)
                window[i, 0] = np.random.normal(0, 2.0) + 1.5 * np.sin(2 * np.pi * freq * t)
                window[i, 1] = -9.81 + np.random.normal(0, 1.5) + 2.0 * np.sin(2 * np.pi * freq * 2 * t)
                window[i, 2] = np.random.normal(0, 1.5)
                window[i, 3:6] = np.random.normal(0, 0.8, 3)
            elif activity == 'jog':  # วิ่งเหยาะ
                freq = np.random.uniform(2.5, 4.0)
                window[i, 0] = np.random.normal(0, 3.0) + 3.0 * np.sin(2 * np.pi * freq * t)
                window[i, 1] = -9.81 + np.random.normal(0, 3.0) + 5.5 * np.sin(2 * np.pi * freq * 2 * t)
                window[i, 2] = np.random.normal(0, 2.5)
                window[i, 3:6] = np.random.normal(0, 1.5, 3)
            elif activity == 'sit':  # นั่ง
                window[i, 0] = np.random.normal(0, 0.15)
                window[i, 1] = np.random.normal(-9.81, 0.15)
                window[i, 2] = np.random.normal(0, 0.15)
                window[i, 3:6] = np.random.normal(0, 0.03, 3)
            elif activity == 'stand':  # ยืน
                window[i, 0] = np.random.normal(0, 0.3)
                window[i, 1] = np.random.normal(-9.81, 0.3)
                window[i, 2] = np.random.normal(0, 0.3)
                window[i, 3:6] = np.random.normal(0, 0.08, 3)
            elif activity == 'stairs':  # ขึ้นบันได
                freq = np.random.uniform(1.0, 2.0)
                window[i, 0] = np.random.normal(0, 2.5) + 2.0 * np.sin(2 * np.pi * freq * t)
                window[i, 1] = -9.81 + np.random.normal(0, 3.0) + 4.0 * np.sin(2 * np.pi * freq * t)
                window[i, 2] = np.random.normal(0, 2.0)
                window[i, 3:6] = np.random.normal(0, 1.0, 3)
            elif activity == 'sit_stand':  # ลุก-นั่ง
                progress = i / WINDOW_SIZE
                window[i, 0] = np.random.normal(0, 0.5 + progress * 2.0)
                window[i, 1] = -9.81 + np.random.normal(0, 1.0) + 3.0 * np.sin(np.pi * progress)
                window[i, 2] = np.random.normal(0, 0.5 + progress)
                window[i, 3:6] = np.random.normal(0, 0.5 + progress, 3)
            elif activity == 'bend':  # ก้มตัว
                progress = np.sin(2 * np.pi * 0.5 * t)
                window[i, 0] = np.random.normal(0, 1.0) 
                window[i, 1] = -9.81 + 4.0 * progress + np.random.normal(0, 0.5)
                window[i, 2] = np.random.normal(0, 1.0) + 2.0 * progress
                window[i, 3:6] = np.random.normal(0, 1.5, 3)
        
        all_features.append(extract_features(window))
        all_labels.append(0)  # 0 = กิจวัตรประจำวัน
    
    X = np.array(all_features, dtype=np.float32)
    y = np.array(all_labels, dtype=np.float32)
    
    # จัดการค่า NaN/Inf
    X = np.nan_to_num(X, nan=0.0, posinf=10.0, neginf=-10.0)
    
    print(f"[สำเร็จ] สร้างตัวอย่างล้ม {num_fall_samples} + กิจวัตร {num_adl_samples} = {len(X)} ตัวอย่างทั้งหมด")
    print(f"[สำเร็จ] ขนาดเวกเตอร์ Feature: {INPUT_FEATURES}")
    
    return X, y


def export_mlp_to_c(clf, scaler, output_path):
    """ส่งออกค่าน้ำหนัก scikit-learn MLPClassifier เป็นไฟล์ C Header"""
    
    weights = clf.coefs_
    biases = clf.intercepts_
    n_layers = len(weights)
    
    # คำนวณขนาดโมเดลทั้งหมด
    total_params = sum(w.size for w in weights) + sum(b.size for b in biases)
    total_bytes = total_params * 4  # float32
    
    print(f"[ข้อมูล] จำนวนชั้นโมเดล: {n_layers}")
    for i, (w, b) in enumerate(zip(weights, biases)):
        print(f"  ชั้นที่ {i}: {w.shape[0]} -> {w.shape[1]} + {b.shape[0]} ไบอัส")
    print(f"[ข้อมูล] พารามิเตอร์ทั้งหมด: {total_params} ({total_bytes} ไบต์)")
    
    with open(output_path, 'w') as f:
        f.write("/*\n")
        f.write(" * โมเดล TinyML ตรวจจับการล้ม\n")
        f.write(" * สร้างอัตโนมัติด้วย train_model.py — ห้ามแก้ไข\n")
        f.write(f" * โมเดล: MLP จำนวน {n_layers} ชั้น\n")
        f.write(f" * Feature อินพุต: {INPUT_FEATURES}\n")
        f.write(f" * พารามิเตอร์ทั้งหมด: {total_params}\n")
        f.write(" * เอาต์พุต: ความน่าจะเป็นของการล้ม (0.0 - 1.0)\n")
        f.write(" */\n\n")
        f.write("#ifndef FALL_MODEL_H\n")
        f.write("#define FALL_MODEL_H\n\n")
        
        f.write(f"#define MODEL_NUM_LAYERS {n_layers}\n")
        f.write(f"#define MODEL_INPUT_FEATURES {INPUT_FEATURES}\n\n")
        
        # ขนาดแต่ละชั้น
        layer_sizes = [weights[0].shape[0]] + [w.shape[1] for w in weights]
        f.write(f"const int MODEL_LAYER_SIZES[{len(layer_sizes)}] = {{")
        f.write(", ".join(str(s) for s in layer_sizes))
        f.write("};\n\n")
        
        # ค่าปกติ: ค่าเฉลี่ยและสเกลจาก StandardScaler
        f.write(f"const float MODEL_SCALER_MEAN[{INPUT_FEATURES}] = {{\n")
        vals = ", ".join(f"{v:.8f}f" for v in scaler.mean_)
        f.write(f"    {vals}\n}};\n\n")
        
        f.write(f"const float MODEL_SCALER_SCALE[{INPUT_FEATURES}] = {{\n")
        vals = ", ".join(f"{v:.8f}f" for v in scaler.scale_)
        f.write(f"    {vals}\n}};\n\n")
        
        # ค่าน้ำหนักและไบอัสแต่ละชั้น
        for layer_idx, (w, b) in enumerate(zip(weights, biases)):
            rows, cols = w.shape
            
            # ค่าน้ำหนัก (แบบ row-major)
            f.write(f"const float MODEL_W{layer_idx}[{rows * cols}] = {{\n")
            flat = w.flatten()
            for i in range(0, len(flat), 8):
                chunk = flat[i:i+8]
                vals = ", ".join(f"{v:.8f}f" for v in chunk)
                f.write(f"    {vals},\n")
            f.write("};\n\n")
            
            # ค่าไบอัส
            f.write(f"const float MODEL_B{layer_idx}[{cols}] = {{\n")
            vals = ", ".join(f"{v:.8f}f" for v in b)
            f.write(f"    {vals}\n}};\n\n")
        
        f.write("#endif // FALL_MODEL_H\n")
    
    print(f"[สำเร็จ] ส่งออกโมเดลไปที่ {output_path}")


def main():
    from sklearn.neural_network import MLPClassifier
    from sklearn.preprocessing import StandardScaler
    from sklearn.model_selection import train_test_split
    from sklearn.metrics import classification_report, confusion_matrix
    
    print("=" * 50)
    print("  TinyML ตรวจจับการล้ม — เทรนโมเดล")
    print("  (scikit-learn MLP → C Header)")
    print("=" * 50)
    print()
    
    # ขั้นตอน 1: สร้างข้อมูล
    print("[ขั้นตอน 1] กำลังสร้างข้อมูลสำหรับเทรน...")
    X, y = generate_training_data()
    print()
    
    # ขั้นตอน 2: ปรับค่าให้เป็นมาตรฐาน
    print("[ขั้นตอน 2] กำลังปรับค่า Feature ให้เป็นมาตรฐาน...")
    scaler = StandardScaler()
    X_scaled = scaler.fit_transform(X)
    print(f"  ช่วงค่าเฉลี่ย: [{scaler.mean_.min():.2f}, {scaler.mean_.max():.2f}]")
    print(f"  ช่วงสเกล: [{scaler.scale_.min():.4f}, {scaler.scale_.max():.4f}]")
    print()
    
    # ขั้นตอน 3: แบ่งข้อมูล
    print("[ขั้นตอน 3] กำลังแบ่งข้อมูล เทรน/ทดสอบ...")
    X_train, X_test, y_train, y_test = train_test_split(
        X_scaled, y, test_size=0.2, random_state=42, stratify=y
    )
    print(f"  เทรน: {len(X_train)}, ทดสอบ: {len(X_test)}")
    print()
    
    # ขั้นตอน 4: เทรน MLP
    print("[ขั้นตอน 4] กำลังเทรนโมเดล MLP...")
    print("  สถาปัตยกรรม: 18 -> 32 -> 16 -> 1")
    
    clf = MLPClassifier(
        hidden_layer_sizes=(32, 16),
        activation='relu',
        solver='adam',
        max_iter=500,
        random_state=42,
        early_stopping=True,
        validation_fraction=0.15,
        n_iter_no_change=20,
        verbose=True,
        learning_rate_init=0.001
    )
    
    clf.fit(X_train, y_train)
    print()
    
    # ขั้นตอน 5: ประเมินผล
    print("[ขั้นตอน 5] กำลังประเมินผลโมเดล...")
    accuracy = clf.score(X_test, y_test)
    print(f"  ความแม่นยำ: {accuracy:.4f} ({accuracy*100:.1f}%)")
    
    y_pred = clf.predict(X_test)
    print("\n  รายงานการจำแนก:")
    print(classification_report(y_test, y_pred, target_names=['กิจวัตร', 'การล้ม']))
    
    cm = confusion_matrix(y_test, y_pred)
    print("  ตาราง Confusion Matrix:")
    print(f"    TN={cm[0][0]:>4d}  FP={cm[0][1]:>4d}")
    print(f"    FN={cm[1][0]:>4d}  TP={cm[1][1]:>4d}")
    
    # Sensitivity & Specificity
    tn, fp, fn, tp = cm.ravel()
    sensitivity = tp / (tp + fn) if (tp + fn) > 0 else 0
    specificity = tn / (tn + fp) if (tn + fp) > 0 else 0
    print(f"\n  Sensitivity (ตรวจจับการล้มได้): {sensitivity:.4f} ({sensitivity*100:.1f}%)")
    print(f"  Specificity (แยกกิจวัตรได้): {specificity:.4f} ({specificity*100:.1f}%)")
    print()
    
    # ขั้นตอน 6: ส่งออก
    print("[ขั้นตอน 6] กำลังส่งออกโมเดลเป็นไฟล์ C Header...")
    os.makedirs(os.path.dirname(os.path.abspath(OUTPUT_MODEL_H)), exist_ok=True)
    export_mlp_to_c(clf, scaler, OUTPUT_MODEL_H)
    
    print()
    print("=" * 50)
    print(f"  เสร็จสิ้น! โมเดล → {OUTPUT_MODEL_H}")
    print(f"  ความแม่นยำ: {accuracy*100:.1f}%")
    print("=" * 50)


if __name__ == '__main__':
    main()
