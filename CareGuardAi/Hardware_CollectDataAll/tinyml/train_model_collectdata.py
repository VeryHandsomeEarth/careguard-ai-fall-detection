"""
สคริปต์เทรนโมเดล TinyML ตรวจจับการล้มจากชุดข้อมูล CollectData Dataset ล้วน (Pure CollectData Edition)
สำหรับโฟลเดอร์: CareGuardAi/Hardware_CollectDataAll

แหล่งข้อมูลที่ใช้เทรน:
  - ใช้ข้อมูลจากชุดเซนเซอร์ MPU-6050 ที่บันทึกจริงผ่านเว็บ CollectData_Web ล้วน 100%
  - ข้อมูลการล้ม (Fall / Label = 1): สกัดหน้าต่างรอบจุดกระแทกสูงสุด (Impact-centered Windows)
  - ข้อมูลกิจวัตรปกติ (ADL / Label = 0): สกัดจากไฟล์หมวด ADL และช่วงสภาวะปกติก่อน/หลังการล้ม
  - สกัด 18 คุณลักษณะทางสถิติและจลศาสตร์ (18 Features)
  - เทรนโครงข่ายประสาทเทียม MLP (18 -> 32 -> 16 -> 1) และส่งออกเป็น C Header (src/fall_model_collectdata.h)
"""

import os
import glob
import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler
from sklearn.neural_network import MLPClassifier
from sklearn.metrics import classification_report, confusion_matrix

# ==================== กำหนดพาธไฟล์ ====================
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "..", ".."))

# รองรับทั้งชื่อโฟลเดอร์ CollectData_Dataset, CollectDatal_Dataset และ CollectData_Web_Dataset
CANDIDATE_DIRS = [
    os.path.join(PROJECT_ROOT, "dataset", "CollectData_Dataset", "sensor_records"),
    os.path.join(PROJECT_ROOT, "dataset", "CollectDatal_Dataset", "sensor_records"),
    os.path.join(PROJECT_ROOT, "dataset", "CollectData_Web_Dataset", "sensor_records"),
    r"D:\ssPro\CollectData_Web_Dataset\sensor_records",
]

COLLECTDATA_DIR = CANDIDATE_DIRS[1]
for d in CANDIDATE_DIRS:
    if os.path.exists(d):
        COLLECTDATA_DIR = d
        break

OUTPUT_MODEL_H = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "src", "fall_model_collectdata.h"))

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


def load_collectdata_only():
    """โหลดข้อมูลการล้มและข้อมูลกิจวัตรปกติจาก CollectData_Web ล้วน"""
    print(f"[1/3] กำลังโหลดข้อมูลจาก: {COLLECTDATA_DIR}")
    csv_files = glob.glob(os.path.join(COLLECTDATA_DIR, "*.csv"))
    print(f"  • พบไฟล์ CSV ทั้งหมด: {len(csv_files)} ไฟล์")

    fall_features = []
    adl_features = []

    for f in csv_files:
        try:
            fname = os.path.basename(f).lower()
            df = pd.read_csv(f)
            data = df[['accel_x', 'accel_y', 'accel_z', 'gyro_x', 'gyro_y', 'gyro_z']].values

            if len(data) < WINDOW_SIZE:
                continue

            # หากเป็นไฟล์บันทึกกิจวัตรปกติ (ADL: D01-D19 หรือ adl_*) โดยตรง
            is_adl_code = any(f"_d{i:02d}" in fname or fname.startswith(f"d{i:02d}") for i in range(1, 20))
            if is_adl_code or "adl" in fname or "เดิน" in fname or "วิ่ง" in fname or "นั่ง" in fname or "ยืน" in fname:
                for s in range(0, len(data) - WINDOW_SIZE + 1, 25):
                    adl_features.append(extract_features(data[s:s + WINDOW_SIZE]))
                continue

            # หาจุดกระแทกสูงสุด (Impact Peak) สำหรับไฟล์การล้ม
            accel_mag = np.linalg.norm(data[:, 0:3], axis=1) / 9.81
            peak_idx = np.argmax(accel_mag)

            # 1) หน้าต่างการล้ม (Label = 1)
            start_main = max(0, peak_idx - 20)
            end_main = start_main + WINDOW_SIZE
            if end_main > len(data):
                end_main = len(data)
                start_main = max(0, end_main - WINDOW_SIZE)

            if len(data[start_main:end_main]) == WINDOW_SIZE:
                fall_features.append(extract_features(data[start_main:end_main]))

            start_left = max(0, peak_idx - 25)
            if start_left + WINDOW_SIZE <= len(data):
                fall_features.append(extract_features(data[start_left:start_left + WINDOW_SIZE]))

            start_right = max(0, peak_idx - 15)
            if start_right + WINDOW_SIZE <= len(data):
                fall_features.append(extract_features(data[start_right:start_right + WINDOW_SIZE]))

            # 2) หน้าต่างสภาวะปกติก่อนล้ม (Pre-fall Normal segments -> Label = 0)
            if len(data) >= 70:
                adl_features.append(extract_features(data[0:50]))
                if peak_idx >= 35:
                    # สกัดหน้าต่างช่วงเริ่มต้นพร้อมเติม Noise เล็กน้อยเพื่อความสมดุลของคลาส
                    w_pre = data[0:50].copy()
                    np.random.seed(int(peak_idx))
                    w_aug1 = w_pre + np.random.normal(0, 0.15, w_pre.shape)
                    w_aug2 = w_pre + np.random.normal(0, 0.25, w_pre.shape)
                    adl_features.append(extract_features(w_aug1))
                    adl_features.append(extract_features(w_aug2))

        except Exception as e:
            print(f"  ข้อผิดพลาดในไฟล์ {os.path.basename(f)}: {e}")

    print(f"  [สำเร็จ] สกัดหน้าต่าง Fall ได้: {len(fall_features)} หน้าต่าง (Label = 1)")
    print(f"  [สำเร็จ] สกัดหน้าต่าง ADL  ได้: {len(adl_features)} หน้าต่าง (Label = 0)")
    return fall_features, adl_features


def export_mlp_to_c(clf, scaler, output_path):
    """ส่งออกค่าน้ำหนัก MLP และตัวแปร Scaler เป็น C Header"""
    weights = clf.coefs_
    biases = clf.intercepts_
    n_layers = len(weights)
    total_params = sum(w.size for w in weights) + sum(b.size for b in biases)

    header_content = [
        "/*",
        " * โมเดล TinyML ตรวจจับการล้ม (Pure CollectData Dataset Edition)",
        " * เทรนจากชุดข้อมูลเซนเซอร์ MPU-6050 ที่เก็บรวบรวมเองผ่านระบบ CollectData_Web",
        f" * สถาปัตยกรรม: MLP {n_layers} ชั้น (18 -> 32 -> 16 -> 1)",
        f" * พารามิเตอร์ทั้งหมด: {total_params}",
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
    print(f"  [สำเร็จ] ส่งออก C Header ไปที่: {output_path}")


def main():
    print("=" * 65)
    print("  [START] เทรนโมเดล TinyML ด้วย CollectData ล้วน (train_model_collectdata.py)")
    print("=" * 65)

    fall_feats, adl_feats = load_collectdata_only()

    X_fall = np.array(fall_feats, dtype=np.float32)
    y_fall = np.ones(len(X_fall), dtype=np.float32)

    X_adl = np.array(adl_feats, dtype=np.float32)
    y_adl = np.zeros(len(X_adl), dtype=np.float32)

    X = np.vstack([X_fall, X_adl])
    y = np.concatenate([y_fall, y_adl])
    X = np.nan_to_num(X, nan=0.0, posinf=10.0, neginf=-10.0)

    print(f"\n[2/3] สรุปชุดข้อมูล CollectData สำหรับการเทรน: รวม {len(X)} หน้าต่าง")
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
