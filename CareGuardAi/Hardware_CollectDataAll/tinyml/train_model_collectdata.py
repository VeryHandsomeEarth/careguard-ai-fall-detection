"""
สคริปต์ฝึกสอนโมเดล TinyML ตรวจจับการล้มจากชุดข้อมูล CollectData_Web (Subjects 17-20 Edition)
สำหรับโฟลเดอร์: CareGuardAi/Hardware_CollectDataAll

ข้อมูลที่ใช้ในการฝึกสอน:
  - แหล่งข้อมูล: ข้อมูลเซ็นเซอร์ MPU-6050 บันทึกจริงจากระบบ CollectData_Web (อาสาสมัครคนที่ 17, 18, 19, 20)
  - จำนวนข้อมูล: 223 บันทึก (หรือ 222 บันทึกเมื่อตัดรอบซ้ำเกินออก)
  - การกรองสัญญาณ: 5-Point Moving Average Filter ทั้ง 6 แกน (Ax, Ay, Az, Gx, Gy, Gz)
  - หน้าต่างเวลา: 50 จุดตัวอย่าง (1.0 วินาที ที่ 50 Hz) รอบจุดกระแทกสูงสุด (Peak Impact)
  - คุณลักษณะ: สกัด 18 คุณลักษณะทางสถิติและชีวกลศาสตร์ (18 Features)
  - สถาปัตยกรรมโครงข่ายประสาทเทียม: MLP (18 -> 32 -> 16 -> 1)
  - ผลลัพธ์: ส่งออก C Header (src/fall_model_collectdata.h) สำหรับฝังบนไมโครคอนโทรลเลอร์ ESP32
"""

import os
import sys
import glob
import json
import numpy as np
import pandas as pd
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler
from sklearn.neural_network import MLPClassifier
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score, recall_score, precision_score, f1_score

sys.stdout.reconfigure(encoding='utf-8')

# ==================== กำหนดพาธไดเรกทอรี ====================
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "..", ".."))

# ไดเรกทอรีเก็บข้อมูลแคชของอาสาสมัคร 17-20
CACHE_DIR_S17_S20 = os.path.join(PROJECT_ROOT, "dataset", "CollectData_Web_S17_S20")

# ไดเรกทอรีสำรองกรณีโหลดจากไฟล์ CSV
CANDIDATE_CSV_DIRS = [
    os.path.join(PROJECT_ROOT, "dataset", "CollectData_Web_Dataset", "sensor_records"),
    os.path.join(PROJECT_ROOT, "dataset", "CollectDatal_Dataset", "sensor_records"),
    os.path.join(PROJECT_ROOT, "dataset", "sensor_records"),
]

OUTPUT_MODEL_H = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "src", "fall_model_collectdata.h"))

WINDOW_SIZE = 50       # 50 จุดตัวอย่าง = 1.0 วินาที @ 50Hz
NUM_AXES = 6           # Accel X,Y,Z + Gyro X,Y,Z
INPUT_FEATURES = 18    # จำนวน 18 Statistical Features


def apply_moving_average(arr, window_size=5):
    """ฟิลเตอร์ Moving Average 5 จุด พร้อม Edge Padding"""
    pad_width = window_size // 2
    padded = np.pad(arr, pad_width, mode='edge')
    kernel = np.ones(window_size) / window_size
    return np.convolve(padded, kernel, mode='valid')[:len(arr)]


def extract_features(window):
    """
    สกัด 18 คุณลักษณะทางสถิติจากหน้าต่าง 50 จุด x 6 แกน
    ฟังก์ชันนี้ตรงกับฟังก์ชัน extractFeatures() ใน fall_detection_collectdata.h ทุกประการ
    """
    ax, ay, az = window[:, 0], window[:, 1], window[:, 2]
    gx, gy, gz = window[:, 3], window[:, 4], window[:, 5]

    # คำนวณขนาดเวกเตอร์ (หน่วย G และ rad/s)
    accel_mag = np.sqrt(ax**2 + ay**2 + az**2) / 9.81
    gyro_mag = np.sqrt(gx**2 + gy**2 + gz**2)
    horiz_mag = np.sqrt(ax**2 + az**2) / 9.81

    # Jerk (อนุพันธ์ของความเร่ง)
    jerk = np.diff(accel_mag)

    features = [
        np.mean(accel_mag),                                      # 0: ค่าเฉลี่ยความเร่งลัพธ์
        np.max(accel_mag),                                       # 1: ค่าสูงสุดความเร่งลัพธ์ (จุดกระแทก)
        np.min(accel_mag),                                       # 2: ค่าต่ำสุดความเร่งลัพธ์ (ช่วงตกอิสระ)
        np.std(accel_mag),                                       # 3: ส่วนเบี่ยงเบนมาตรฐานความเร่ง
        np.max(accel_mag) - np.min(accel_mag),                   # 4: พิสัยความเร่ง (Max - Min)
        np.sum(accel_mag < 0.3) / len(accel_mag),                # 5: สัดส่วนการตกอิสระ (A < 0.3G)
        np.sum(accel_mag > 3.5) / len(accel_mag),                # 6: สัดส่วนแรงกระแทกสูง (A > 3.5G)
        np.mean(gyro_mag),                                       # 7: ค่าเฉลี่ยความเร็วเชิงมุม
        np.max(gyro_mag),                                        # 8: ค่าสูงสุดความเร็วเชิงมุม
        np.std(gyro_mag),                                        # 9: ส่วนเบี่ยงเบนความเร็วเชิงมุม
        np.mean(np.abs(ax) + np.abs(ay) + np.abs(az)) / 9.81,   # 10: SMA (Signal Magnitude Area, G)
        np.mean(horiz_mag),                                      # 11: ค่าเฉลี่ยความเร่งแนวนอน
        np.max(horiz_mag),                                       # 12: ค่าสูงสุดความเร่งแนวนอน
        np.mean(np.abs(jerk)) if len(jerk) > 0 else 0.0,         # 13: ค่าเฉลี่ย Jerk
        np.max(np.abs(jerk)) if len(jerk) > 0 else 0.0,          # 14: ค่าสูงสุด Jerk
        np.sum(np.diff(np.sign(accel_mag - 1.0)) != 0),          # 15: อัตราการตัดผ่าน 1.0G (ZCR)
        np.corrcoef(ax, ay)[0, 1] if np.std(ax) > 1e-4 and np.std(ay) > 1e-4 else 0.0,  # 16: สหสัมพันธ์ XY
        np.corrcoef(ay, az)[0, 1] if np.std(ay) > 1e-4 and np.std(az) > 1e-4 else 0.0,  # 17: สหสัมพันธ์ YZ
    ]

    # จัดการกรณีค่า NaN
    if np.isnan(features[16]): features[16] = 0.0
    if np.isnan(features[17]): features[17] = 0.0

    return np.array(features, dtype=np.float32)


def load_dataset():
    """
    โหลดข้อมูลจากแคช CollectData_Web_S17_S20 (Subjects 17-20) หรือ CSV สำรอง
    """
    records = []
    summary_path = os.path.join(CACHE_DIR_S17_S20, "summary.json")

    if os.path.exists(summary_path):
        print(f"[1/4] โหลดข้อมูลจากแคชอาสาสมัคร 17-20: {CACHE_DIR_S17_S20}")
        with open(summary_path, 'r', encoding='utf-8') as f:
            summary_records = json.load(f)

        print(f"  พบรายการทั้งหมด: {len(summary_records)} บันทึก")

        fall_samples = []
        adl_samples = []

        for r in summary_records:
            cache_file = r['cache_file']
            if not os.path.exists(cache_file):
                continue

            with open(cache_file, 'r', encoding='utf-8') as cf:
                rec_data = json.load(cf)

            pts = rec_data.get('sensor_data', [])
            if len(pts) < WINDOW_SIZE:
                continue

            ax = apply_moving_average(np.array([p['accel_x'] for p in pts]))
            ay = apply_moving_average(np.array([p['accel_y'] for p in pts]))
            az = apply_moving_average(np.array([p['accel_z'] for p in pts]))
            gx = apply_moving_average(np.array([p['gyro_x'] for p in pts]))
            gy = apply_moving_average(np.array([p['gyro_y'] for p in pts]))
            gz = apply_moving_average(np.array([p['gyro_z'] for p in pts]))

            mag = np.sqrt(ax**2 + ay**2 + az**2) / 9.81
            peak_idx = int(np.argmax(mag))
            st = max(0, peak_idx - 25)
            en = st + WINDOW_SIZE
            if en > len(pts):
                en = len(pts)
                st = en - WINDOW_SIZE

            win = np.column_stack([ax[st:en], ay[st:en], az[st:en], gx[st:en], gy[st:en], gz[st:en]])
            feat = extract_features(win)

            if r['category'] == 'fall':
                fall_samples.append(feat)
            else:
                adl_samples.append(feat)

        print(f"  [OK] สกัดคุณลักษณะท่าล้ม (Fall / Label = 1): {len(fall_samples)} ตัวอย่าง")
        print(f"  [OK] สกัดคุณลักษณะท่าปกติ (ADL / Label = 0):  {len(adl_samples)} ตัวอย่าง")
        return fall_samples, adl_samples

    # หากไม่มีไฟล์แคช json ให้ค้นหาจากไฟล์ CSV สำรอง
    print(f"[1/4] ไม่พบแคช json, กำลังค้นหาไฟล์ CSV สำรอง...")
    csv_dir = None
    for d in CANDIDATE_CSV_DIRS:
        if os.path.exists(d):
            csv_dir = d
            break

    if not csv_dir:
        raise FileNotFoundError(f"ไม่พบโฟลเดอร์ข้อมูลทั้ง {CACHE_DIR_S17_S20} และ CSV สำรอง")

    csv_files = glob.glob(os.path.join(csv_dir, "*.csv"))
    print(f"  พบไฟล์ CSV ใน {csv_dir}: {len(csv_files)} ไฟล์")

    fall_samples = []
    adl_samples = []

    for f in csv_files:
        try:
            fname = os.path.basename(f).lower()
            df = pd.read_csv(f)
            data = df[['accel_x', 'accel_y', 'accel_z', 'gyro_x', 'gyro_y', 'gyro_z']].values
            if len(data) < WINDOW_SIZE:
                continue

            for col in range(6):
                data[:, col] = apply_moving_average(data[:, col])

            mag = np.sqrt(data[:, 0]**2 + data[:, 1]**2 + data[:, 2]**2) / 9.81
            peak_idx = int(np.argmax(mag))
            st = max(0, peak_idx - 25)
            en = st + WINDOW_SIZE
            if en > len(data):
                en = len(data)
                st = en - WINDOW_SIZE

            feat = extract_features(data[st:en])
            is_adl = any(f"_d{i:02d}" in fname or fname.startswith(f"d{i:02d}") for i in range(1, 20))
            if is_adl or 'adl' in fname:
                adl_samples.append(feat)
            else:
                fall_samples.append(feat)
        except Exception as e:
            print(f"  เกิดข้อผิดพลาดในการอ่านไฟล์ {f}: {e}")

    return fall_samples, adl_samples


def export_mlp_to_c(clf, scaler, output_path):
    """ส่งออกน้ำหนักโมเดล MLP และค่า Scaler ออกเป็นไฟล์ C Header"""
    weights = clf.coefs_
    biases = clf.intercepts_
    n_layers = len(weights)
    total_params = sum(w.size for w in weights) + sum(b.size for b in biases)

    header_content = [
        "/*",
        " * โมเดล TinyML ตรวจจับการล้ม (CareGuard AI - Subjects 17-20 Edition)",
        " * เทรนจากข้อมูลจริง: CollectData_Web (Subjects 17, 18, 19, 20)",
        f" * สถาปัตยกรรม: MLP {n_layers} ชั้น (18 -> 32 -> 16 -> 1)",
        f" * พารามิเตอร์ทั้งหมด: {total_params} (Flash ROM ~{total_params*4/1024:.2f} KB)",
        " */\n",
        "#ifndef FALL_MODEL_COLLECTDATA_H",
        "#define FALL_MODEL_COLLECTDATA_H\n",
        f"#define MODEL_NUM_LAYERS {n_layers}",
        f"#define MODEL_INPUT_FEATURES {INPUT_FEATURES}\n",
    ]

    layer_sizes = [weights[0].shape[0]] + [w.shape[1] for w in weights]
    header_content.append(f"const int MODEL_LAYER_SIZES[{len(layer_sizes)}] = {{{', '.join(str(s) for s in layer_sizes)}}};\n")

    header_content.append(f"const float MODEL_SCALER_MEAN[{INPUT_FEATURES}] = {{")
    header_content.append("    " + ", ".join(f"{v:.8f}f" for v in scaler.mean_))
    header_content.append("};\n")

    header_content.append(f"const float MODEL_SCALER_SCALE[{INPUT_FEATURES}] = {{")
    header_content.append("    " + ", ".join(f"{v:.8f}f" for v in scaler.scale_))
    header_content.append("};\n")

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

    header_content.append("#endif // FALL_MODEL_COLLECTDATA_H\n")

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write("\n".join(header_content))
    print(f"  [OK] ส่งออก C Header สำเร็จ: {output_path}")


def main():
    print("=" * 70)
    print("  [START] ฝึกสอน TinyML MLP ตรวจจับการล้ม (CareGuard AI - Subjects 17-20)")
    print("=" * 70)

    fall_feats, adl_feats = load_dataset()

    X_fall = np.array(fall_feats, dtype=np.float32)
    y_fall = np.ones(len(X_fall), dtype=np.float32)

    X_adl = np.array(adl_feats, dtype=np.float32)
    y_adl = np.zeros(len(X_adl), dtype=np.float32)

    X = np.vstack([X_fall, X_adl])
    y = np.concatenate([y_fall, y_adl])
    X = np.nan_to_num(X, nan=0.0, posinf=10.0, neginf=-10.0)

    total_samples = len(X)
    print(f"\n[2/4] สรุปชุดข้อมูลรวม: {total_samples} ตัวอย่าง (Fall={len(X_fall)}, ADL={len(X_adl)})")

    # 1. แบ่งชุดข้อมูลแบบ Stratified 80:20
    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.20, random_state=42, stratify=y
    )

    # 2. ปรับสเกลข้อมูล (Z-score Standardization)
    scaler = StandardScaler()
    X_train_scaled = scaler.fit_transform(X_train)
    X_test_scaled = scaler.transform(X_test)

    print(f"  - ชุดฝึกสอน (Train Set): {len(X_train)} ตัวอย่าง (Fall={int(sum(y_train))}, ADL={int(len(y_train)-sum(y_train))})")
    print(f"  - ชุดทดสอบ (Test Set):   {len(X_test)} ตัวอย่าง (Fall={int(sum(y_test))}, ADL={int(len(y_test)-sum(y_test))})")

    # 3. กำหนดสถาปัตยกรรม MLP: 18 -> 32 -> 16 -> 1
    print("\n[3/4] กำลังฝึกสอนโมเดล MLP (18 -> 32 -> 16 -> 1)...")
    clf = MLPClassifier(
        hidden_layer_sizes=(32, 16),
        activation='relu',
        solver='adam',
        alpha=0.001,
        max_iter=800,
        random_state=42,
        learning_rate_init=0.002,
        early_stopping=False
    )
    clf.fit(X_train_scaled, y_train)

    # 4. ประเมินผล
    y_pred = clf.predict(X_test_scaled)
    acc = accuracy_score(y_test, y_pred)
    sens = recall_score(y_test, y_pred)
    prec = precision_score(y_test, y_pred)
    f1 = f1_score(y_test, y_pred)
    cm = confusion_matrix(y_test, y_pred)
    tn, fp, fn, tp = cm.ravel()
    spec = tn / (tn + fp)

    print("\n" + "=" * 70)
    print("  [4/4] สรุปผลการประเมินประสิทธิภาพโมเดล MLP บน Test Set:")
    print("=" * 70)
    print(f"  - ความถูกต้องโดยรวม (Accuracy):     {acc * 100:.2f}%")
    print(f"  - ความไวต่อการล้ม (Sensitivity / Recall): {sens * 100:.2f}% (ตรวจจับล้มได้ {tp}/{tp+fn})")
    print(f"  - ความจำเพาะต่อท่าปกติ (Specificity):   {spec * 100:.2f}% (ไม่เตือนหลอก {tn}/{tn+fp})")
    print(f"  - ค่าความแม่นยำ (Precision):           {prec * 100:.2f}%")
    print(f"  - ค่าคะแนนเฉลี่ย F1-Score:             {f1 * 100:.2f}%")
    print(f"  - รอบการลู่เข้า (Epochs Convergence):    {clf.n_iter_} รอบ (Loss สุดท้าย = {clf.loss_:.4f})")
    print(f"\n  ตาราง Confusion Matrix:")
    print(f"                    ทำนายเป็น ADL (0)    ทำนายเป็น Fall (1)")
    print(f"    ความจริงเป็น ADL (0)    {tn:<18} {fp:<18}")
    print(f"    ความจริงเป็น Fall (1)   {fn:<18} {tp:<18}")
    print("=" * 70)

    # 5. ส่งออก C Header
    export_mlp_to_c(clf, scaler, OUTPUT_MODEL_H)
    print("\n[FINISH] การฝึกสอนและส่งออกโมเดล TinyML เสร็จสมบูรณ์เรียบร้อยแล้ว!")


if __name__ == '__main__':
    main()
