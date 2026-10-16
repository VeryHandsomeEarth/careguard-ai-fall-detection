"""
สคริปต์เทรนโมเดล TinyML ตรวจจับการล้มจากชุดข้อมูล SisFall Dataset ล้วน (Pure SisFall Edition)
สำหรับโฟลเดอร์: CareGuardAi/Hardware_SisFallAll

แหล่งข้อมูลที่ใช้เทรน:
  1. ข้อมูลการล้ม (Fall / Label = 1): ดึงจากไฟล์ F01–F15 (F*.txt) ใน dataset/SisFall_Dataset
  2. ข้อมูลกิจวัตรประจำวัน (ADL / Label = 0): ดึงจากไฟล์ D01–D19 (D*.txt) ใน dataset/SisFall_Dataset

การปรับเทียบสัญญาณ (Signal Calibration):
  - แปลงค่าดิบ ADXL345 (±16g, 13-bit) -> m/s²
  - แปลงค่าดิบ ITG3200 (±2000°/s, 16-bit) -> rad/s
  - ลดความถี่ (Downsampling) จาก 200Hz -> 50Hz (หาร 4) ให้ตรงกับฮาร์ดแวร์ ESP32 + MPU-6050
  - สกัด 18 คุณลักษณะทางสถิติและจลศาสตร์ (18 Features)
  - เทรนโครงข่ายประสาทเทียม MLP (18 -> 32 -> 16 -> 1) และส่งออกเป็น C Header (src/fall_model.h)
"""

import os
import glob
import re
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler
from sklearn.neural_network import MLPClassifier
from sklearn.metrics import classification_report, confusion_matrix

# ==================== กำหนดพาธไฟล์ ====================
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "..", ".."))

SISFALL_DIR = os.path.join(PROJECT_ROOT, "dataset", "SisFall_Dataset")
if not os.path.exists(SISFALL_DIR):
    SISFALL_DIR = r"D:\ssPro\SisFall_Dataset"

OUTPUT_MODEL_H = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "src", "fall_model.h"))

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
        np.mean(accel_mag),                                      # 0: ค่าเฉลี่ยขนาดความเร่ง
        np.max(accel_mag),                                       # 1: ค่าสูงสุดขนาดความเร่ง
        np.min(accel_mag),                                       # 2: ค่าต่ำสุดขนาดความเร่ง
        np.std(accel_mag),                                       # 3: ส่วนเบี่ยงเบนมาตรฐาน
        np.max(accel_mag) - np.min(accel_mag),                   # 4: พิสัย
        np.sum(accel_mag < 0.3) / len(accel_mag),                # 5: อัตราส่วนตกอิสระ
        np.sum(accel_mag > 3.5) / len(accel_mag),                # 6: อัตราส่วนแรงกระแทก
        np.mean(gyro_mag),                                       # 7: ค่าเฉลี่ยไจโร
        np.max(gyro_mag),                                        # 8: ค่าสูงสุดไจโร
        np.std(gyro_mag),                                        # 9: ส่วนเบี่ยงเบนไจโร
        np.mean(np.abs(ax) + np.abs(ay) + np.abs(az)) / 9.81,   # 10: SMA
        np.mean(horiz_mag),                                      # 11: ค่าเฉลี่ยแนวนอน
        np.max(horiz_mag),                                       # 12: ค่าสูงสุดแนวนอน
        np.mean(np.abs(jerk)) if len(jerk) > 0 else 0,           # 13: ค่าเฉลี่ย Jerk
        np.max(np.abs(jerk)) if len(jerk) > 0 else 0,            # 14: ค่าสูงสุด Jerk
        np.sum(np.diff(np.sign(accel_mag - 1.0)) != 0),          # 15: อัตราตัดผ่านศูนย์
        np.corrcoef(ax, ay)[0, 1] if np.std(ax) > 0 and np.std(ay) > 0 else 0,  # 16: สหสัมพันธ์ XY
        np.corrcoef(ay, az)[0, 1] if np.std(ay) > 0 and np.std(az) > 0 else 0,  # 17: สหสัมพันธ์ YZ
    ]

    return np.array(features, dtype=np.float32)


def parse_sisfall_file(filepath):
    """อ่านไฟล์ SisFall แปลงหน่วยเป็น SI (m/s², rad/s) และ Downsample 200Hz -> 50Hz"""
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as file:
        lines = [line.strip().rstrip(';') for line in file if line.strip()]

    rows = []
    for line in lines:
        parts = [float(p) for p in re.split(r'[,;\s]+', line) if p]
        if len(parts) >= 6:
            rows.append(parts[:6])

    if len(rows) < 200:
        return None

    raw_data = np.array(rows, dtype=np.float32)
    converted = np.zeros((len(raw_data), 6), dtype=np.float32)
    # ADXL345: ±16g, 13-bit -> m/s²
    converted[:, 0:3] = (raw_data[:, 0:3] * (32.0 / 8192.0)) * 9.81
    # ITG3200: ±2000 deg/s, 16-bit -> rad/s
    converted[:, 3:6] = (raw_data[:, 3:6] * (2000.0 / 32768.0)) * (np.pi / 180.0)

    # Downsample 200Hz -> 50Hz
    return converted[::4]


def load_sisfall_dataset(max_fall_files=500, max_adl_windows=1000):
    """โหลดทั้งข้อมูลการล้ม (F01-F15) และข้อมูลกิจวัตร (D01-D19) จาก SisFall Dataset"""
    print(f"[1/3] กำลังค้นหาไฟล์ใน SisFall Dataset: {SISFALL_DIR}")
    fall_files = glob.glob(os.path.join(SISFALL_DIR, "**", "F*.txt"), recursive=True)
    adl_files = glob.glob(os.path.join(SISFALL_DIR, "**", "D*.txt"), recursive=True)

    print(f"  • พบไฟล์การล้ม (F01-F15): {len(fall_files)} ไฟล์")
    print(f"  • พบไฟล์กิจวัตร (D01-D19): {len(adl_files)} ไฟล์")

    np.random.seed(42)
    np.random.shuffle(fall_files)
    np.random.shuffle(adl_files)

    fall_features = []
    for f in fall_files[:max_fall_files]:
        try:
            data_50hz = parse_sisfall_file(f)
            if data_50hz is None or len(data_50hz) < WINDOW_SIZE:
                continue

            accel_mag = np.linalg.norm(data_50hz[:, 0:3], axis=1) / 9.81
            peak_idx = np.argmax(accel_mag)

            start_idx = max(0, peak_idx - 20)
            end_idx = start_idx + WINDOW_SIZE
            if end_idx > len(data_50hz):
                end_idx = len(data_50hz)
                start_idx = max(0, end_idx - WINDOW_SIZE)

            win = data_50hz[start_idx:end_idx]
            if len(win) == WINDOW_SIZE:
                fall_features.append(extract_features(win))
        except Exception:
            continue

    adl_features = []
    for f in adl_files:
        if len(adl_features) >= max_adl_windows:
            break
        try:
            data_50hz = parse_sisfall_file(f)
            if data_50hz is None or len(data_50hz) < WINDOW_SIZE:
                continue

            for start_idx in range(0, len(data_50hz) - WINDOW_SIZE + 1, WINDOW_SIZE):
                win = data_50hz[start_idx:start_idx + WINDOW_SIZE]
                adl_features.append(extract_features(win))
                if len(adl_features) >= max_adl_windows:
                    break
        except Exception:
            continue

    print(f"  [สำเร็จ] สกัดหน้าต่าง Fall (F01-F15) ได้: {len(fall_features)} หน้าต่าง (Label = 1)")
    print(f"  [สำเร็จ] สกัดหน้าต่าง ADL  (D01-D19) ได้: {len(adl_features)} หน้าต่าง (Label = 0)")
    return fall_features, adl_features


def export_mlp_to_c(clf, scaler, output_path):
    """ส่งออกค่าน้ำหนัก MLP และตัวแปร Scaler เป็น C Header"""
    weights = clf.coefs_
    biases = clf.intercepts_
    n_layers = len(weights)
    total_params = sum(w.size for w in weights) + sum(b.size for b in biases)

    header_content = [
        "/*",
        " * โมเดล TinyML ตรวจจับการล้ม (Pure SisFall Dataset Edition)",
        " * เทรนจากชุดข้อมูลมาตรฐานสากล: SisFall Dataset (F01-F15 Falls & D01-D19 ADLs)",
        f" * สถาปัตยกรรม: MLP {n_layers} ชั้น (18 -> 32 -> 16 -> 1)",
        f" * พารามิเตอร์ทั้งหมด: {total_params}",
        " */\n",
        "#ifndef FALL_MODEL_H",
        "#define FALL_MODEL_H\n",
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

    header_content.append("#endif // FALL_MODEL_H\n")

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write("\n".join(header_content))
    print(f"  [สำเร็จ] ส่งออก C Header ไปที่: {output_path}")


def main():
    print("=" * 65)
    print("  [START] เทรนโมเดล TinyML ด้วย SisFall Dataset ล้วน (train_model_sisfall.py)")
    print("=" * 65)

    fall_feats, adl_feats = load_sisfall_dataset(max_fall_files=500, max_adl_windows=900)

    X_fall = np.array(fall_feats, dtype=np.float32)
    y_fall = np.ones(len(X_fall), dtype=np.float32)

    X_adl = np.array(adl_feats, dtype=np.float32)
    y_adl = np.zeros(len(X_adl), dtype=np.float32)

    X = np.vstack([X_fall, X_adl])
    y = np.concatenate([y_fall, y_adl])
    X = np.nan_to_num(X, nan=0.0, posinf=10.0, neginf=-10.0)

    print(f"\n[2/3] สรุปชุดข้อมูล SisFall สำหรับการเทรน: รวม {len(X)} หน้าต่าง")
    scaler = StandardScaler()
    X_scaled = scaler.fit_transform(X)

    X_train, X_test, y_train, y_test = train_test_split(
        X_scaled, y, test_size=0.2, random_state=42, stratify=y
    )

    print("\n[3/3] กำลังเทรนโมเดล MLP (18 -> 32 -> 16 -> 1)...")
    clf = MLPClassifier(
        hidden_layer_sizes=(32, 16),
        activation='relu',
        solver='adam',
        max_iter=600,
        random_state=42,
        early_stopping=True,
        validation_fraction=0.15,
        n_iter_no_change=25,
        learning_rate_init=0.001
    )
    clf.fit(X_train, y_train)

    y_pred = clf.predict(X_test)
    cm = confusion_matrix(y_test, y_pred)
    tn, fp, fn, tp = cm.ravel()
    print(f"\n  - Test Accuracy: {clf.score(X_test, y_test)*100:.2f}% (TP={tp}, TN={tn}, FP={fp}, FN={fn})")
    print(classification_report(y_test, y_pred, target_names=['ADL (0)', 'Fall (1)'], digits=4))

    export_mlp_to_c(clf, scaler, OUTPUT_MODEL_H)


if __name__ == '__main__':
    main()
