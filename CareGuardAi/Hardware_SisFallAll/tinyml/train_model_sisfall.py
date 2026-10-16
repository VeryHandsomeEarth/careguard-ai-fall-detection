"""
สคริปต์เทรนโมเดล TinyML ตรวจจับการล้มและจำแนกท่าทางการล้มจากชุดข้อมูล SisFall Dataset ล้วน (Pure SisFall Edition)
สำหรับโฟลเดอร์: CareGuardAi/Hardware_SisFallAll

ประกอบด้วย 2 โมเดลโครงข่ายประสาทเทียม (Dual-MLP Architecture):
  1. โมเดลตรวจจับการล้ม (Binary Fall Detection MLP: 18 -> 32 -> 16 -> 1)
     - จำแนก กิจวัตรประจำวันปกติ (ADL: D01–D19, Label = 0) ออกจาก การล้ม (Fall: F01–F15, Label = 1)
  2. โมเดลระบุท่าทางและทิศทางการล้ม (Multi-Class Posture MLP: 45 -> 48 -> 24 -> 4)
     - จำแนกทิศทางและท่าทางการล้ม 4 กลุ่มตามมาตรฐาน SisFall:
       * Class 0: fall_forward  (F01, F04, F05, F08, F10, F13 — ล้มไปข้างหน้า)
       * Class 1: fall_backward (F02, F11, F14 — ล้มไปข้างหลัง)
       * Class 2: fall_lateral  (F03, F09, F12, F15 — ล้มไปด้านข้าง ซ้าย/ขวา)
       * Class 3: fall_vertical (F06, F07 — ล้มแนวดิ่ง/ทรุดตัวจากอาการหน้ามืดเป็นลม)

ส่งออกผลลัพธ์เป็น C Header:
  - CareGuardAi/Hardware_SisFallAll/src/fall_model_sisfall.h
"""

import os
import sys
import glob
import time
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler
from sklearn.neural_network import MLPClassifier
from sklearn.metrics import classification_report, confusion_matrix

try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

# ==================== กำหนดพาธไฟล์ ====================
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "..", ".."))

SISFALL_DIR = os.path.join(PROJECT_ROOT, "dataset", "SisFall_Dataset")
if not os.path.exists(SISFALL_DIR):
    SISFALL_DIR = r"D:\ssPro\SisFall_Dataset"

OUTPUT_MODEL_H = os.path.abspath(os.path.join(SCRIPT_DIR, "..", "src", "fall_model_sisfall.h"))

WINDOW_SIZE = 50               # 50 ตัวอย่าง = 1 วินาที ที่ 50Hz
NUM_AXES = 6                   # Accel X,Y,Z + Gyro X,Y,Z
INPUT_FEATURES = 18            # 18 Statistical Features สำหรับตรวจจับการล้ม (Binary)
POSTURE_INPUT_FEATURES = 45    # 45 Directional Features สำหรับระบุท่าทางการล้ม (4 Classes)
POSTURE_NUM_CLASSES = 4        # 0=Forward, 1=Backward, 2=Lateral, 3=Vertical

# แผนผังรหัสท่าการล้มใน SisFall (F01-F15) สู่คลาสทิศทางการล้ม
SISFALL_POSTURE_MAP = {
    'F01': (0, 'ล้มไปข้างหน้าขณะเดินเพราะลื่น (Slip Forward)'),
    'F02': (1, 'ล้มไปข้างหลังขณะเดินเพราะลื่น (Slip Backward)'),
    'F03': (2, 'ล้มไปด้านข้างขณะเดินเพราะลื่น (Slip Lateral)'),
    'F04': (0, 'ล้มไปข้างหน้าขณะเดินเพราะสะดุด (Trip Forward)'),
    'F05': (0, 'ล้มไปข้างหน้าขณะวิ่งเหยาะเพราะสะดุด (Jogging Trip Forward)'),
    'F06': (3, 'ล้มแนวดิ่ง/ทรุดตัวขณะเดินเพราะหน้ามืดเป็นลม (Fainting Vertical)'),
    'F07': (3, 'ล้มขณะเดินโดยใช้มือยันโต๊ะพยุงเพราะหน้ามืด (Dampened Fainting)'),
    'F08': (0, 'ล้มไปข้างหน้าขณะพยายามลุกขึ้นยืน (Get-up Forward)'),
    'F09': (2, 'ล้มไปด้านข้างขณะพยายามลุกขึ้นยืน (Get-up Lateral)'),
    'F10': (0, 'ล้มไปข้างหน้าขณะกำลังนั่งลง (Sit-down Forward)'),
    'F11': (1, 'ล้มไปข้างหลังขณะกำลังนั่งลง (Sit-down Backward)'),
    'F12': (2, 'ล้มไปด้านข้างขณะกำลังนั่งลง (Sit-down Lateral)'),
    'F13': (0, 'ล้มไปข้างหน้าจากท่านั่งเพราะหน้ามืด/หลับใน (Sitting Forward)'),
    'F14': (1, 'ล้มไปข้างหลังจากท่านั่งเพราะหน้ามืด/หลับใน (Sitting Backward)'),
    'F15': (2, 'ล้มไปด้านข้างจากท่านั่งเพราะหน้ามืด/หลับใน (Sitting Lateral)'),
}

POSTURE_CLASS_NAMES = [
    'fall_forward (ล้มไปข้างหน้า)',
    'fall_backward (ล้มไปข้างหลัง)',
    'fall_lateral (ล้มไปด้านข้าง)',
    'fall_vertical (ล้มแนวดิ่ง/ทรุดตัว)',
]


def extract_features(window):
    """สกัด 18 คุณลักษณะทางสถิติจากหน้าต่างข้อมูล 50 จุด x 6 แกน (สำหรับตรวจจับ Fall vs ADL)"""
    ax, ay, az = window[:, 0], window[:, 1], window[:, 2]
    gx, gy, gz = window[:, 3], window[:, 4], window[:, 5]

    accel_mag = np.sqrt(ax**2 + ay**2 + az**2) / 9.81
    gyro_mag = np.sqrt(gx**2 + gy**2 + gz**2)
    horiz_mag = np.sqrt(ax**2 + az**2) / 9.81
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


def extract_posture_features(window):
    """
    สกัด 45 คุณลักษณะเชิงทิศทางและจลศาสตร์ (Directional & Kinematic Posture Features)
    ตรงกับฟังก์ชัน extractPostureFeatures() ใน fall_detection_sisfall.h ทุกประการ
    """
    accel = window[:, 0:3] / 9.81  # แปลงเป็นหน่วย G
    gyro = window[:, 3:6]          # หน่วย rad/s
    mags = np.linalg.norm(accel, axis=1)
    pk = int(np.argmax(mags))

    pre = accel[:15]               # ช่วงก่อนล้ม (15 ตัวอย่างแรก: 0..14)
    post = accel[32:]              # ช่วงหลังกระแทกพื้น (18 ตัวอย่างท้าย: 32..49)
    s_pk = max(0, pk - 3)
    e_pk = min(len(window), pk + 4)
    imp_a = accel[s_pk:e_pk]       # ช่วงจุดกระแทก (peakIdx - 3 .. peakIdx + 3)
    imp_g = gyro[s_pk:e_pk]

    pre_mean = np.mean(pre, axis=0)            # 0..2
    pre_std = np.std(pre, axis=0)              # 3..5
    post_mean = np.mean(post, axis=0)          # 6..8
    post_abs_mean = np.mean(np.abs(post), axis=0)  # 9..11
    post_std = np.std(post, axis=0)            # 12..14
    delta_mean = post_mean - pre_mean          # 15..17

    pk_a = accel[pk]                           # 18..20
    pk_g = gyro[pk]                            # 21..23
    imp_a_mean = np.mean(imp_a, axis=0)        # 24..26
    imp_g_mean = np.mean(imp_g, axis=0)        # 27..29

    max_a = np.max(accel, axis=0)              # 30..32
    min_a = np.min(accel, axis=0)              # 33..35
    max_g = np.max(gyro, axis=0)               # 36..38
    min_g = np.min(gyro, axis=0)               # 39..41

    rot_sum = np.sum(gyro[10:35], axis=0) * 0.02  # 42..44 (มุมหมุนสะสมช่วงล้ม)

    return np.concatenate([
        pre_mean, pre_std,
        post_mean, post_abs_mean, post_std,
        delta_mean,
        pk_a, pk_g,
        imp_a_mean, imp_g_mean,
        max_a, min_a, max_g, min_g,
        rot_sum
    ]).astype(np.float32)


def old_heuristic_identify(window):
    """ตรรกะระบุท่าทางแบบเก่า (ก่อนเทรนด้วย SisFall) เพื่อใช้เปรียบเทียบความแม่นยำ"""
    mags = np.linalg.norm(window[:, 0:3], axis=1)
    pk = int(np.argmax(mags))
    s = max(0, pk - 2)
    e = min(len(window) - 1, pk + 2)
    avg = np.mean(window[s:e+1, 0:3], axis=0)
    ax, ay, az = avg[0], avg[1], avg[2]
    absX, absY, absZ = abs(ax), abs(ay), abs(az)
    if absX > absZ and absX > 10.0:
        return 2  # lateral
    elif absZ >= absX and absZ > 10.0:
        return 0 if az > 0 else 1  # forward / backward
    elif absY > 22.0:
        return 3  # vertical
    return -1


def parse_sisfall_file(filepath):
    """อ่านไฟล์ SisFall แปลงหน่วยเป็น SI (m/s², rad/s) และ Downsample 200Hz -> 50Hz อย่างรวดเร็ว"""
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        txt = f.read().replace(';', '').replace('\t', ',')
    lines = [l.strip() for l in txt.splitlines() if l.strip() and not l.startswith('#')]
    if len(lines) < 200:
        return None

    sub_lines = lines[::4]  # Downsample 200Hz -> 50Hz
    arr = np.fromstring(','.join(sub_lines), dtype=np.float32, sep=',')
    cols = len(lines[0].split(','))
    if cols < 6:
        return None
    n = len(arr) // cols
    if n < WINDOW_SIZE:
        return None
    arr = arr[:n * cols].reshape(n, cols)[:, :6]

    # ADXL345: ±16g, 13-bit -> m/s²
    arr[:, 0:3] = (arr[:, 0:3] * (32.0 / 8192.0)) * 9.81
    # ITG3200: ±2000 deg/s, 16-bit -> rad/s
    arr[:, 3:6] = (arr[:, 3:6] * (2000.0 / 32768.0)) * (np.pi / 180.0)
    return arr


def load_full_sisfall_dataset():
    """โหลดข้อมูลทั้งหมดจาก SisFall Dataset ทั้ง 1,798 ไฟล์การล้ม (F01-F15) และ 2,707 ไฟล์กิจวัตร (D01-D19)"""
    t0 = time.time()
    print(f"[1/4] กำลังอ่านข้อมูลทั้งหมดจาก SisFall Dataset: {SISFALL_DIR}")
    fall_files = sorted(glob.glob(os.path.join(SISFALL_DIR, "**", "F*.txt"), recursive=True))
    adl_files = sorted(glob.glob(os.path.join(SISFALL_DIR, "**", "D*.txt"), recursive=True))

    print(f"  • พบไฟล์การล้ม (F01–F15): {len(fall_files)} ไฟล์")
    print(f"  • พบไฟล์กิจวัตร (D01–D19): {len(adl_files)} ไฟล์")

    fall_bin_feats = []
    fall_bin_codes = []

    posture_feats = []
    posture_labels = []
    posture_codes = []
    old_heur_preds = []

    for f in fall_files:
        try:
            code = os.path.basename(f).split('_')[0].upper()
            data_50hz = parse_sisfall_file(f)
            if data_50hz is None or len(data_50hz) < WINDOW_SIZE:
                continue

            accel_mag = np.linalg.norm(data_50hz[:, 0:3], axis=1) / 9.81
            peak_idx = int(np.argmax(accel_mag))

            # หน้าต่างหลักรอบจุดกระแทก [peak - 20 : peak + 30]
            start_idx = max(0, peak_idx - 20)
            end_idx = start_idx + WINDOW_SIZE
            if end_idx > len(data_50hz):
                end_idx = len(data_50hz)
                start_idx = max(0, end_idx - WINDOW_SIZE)

            win = data_50hz[start_idx:end_idx]
            if len(win) == WINDOW_SIZE:
                fall_bin_feats.append(extract_features(win))
                fall_bin_codes.append(code)

                if code in SISFALL_POSTURE_MAP:
                    p_label = SISFALL_POSTURE_MAP[code][0]
                    posture_feats.append(extract_posture_features(win))
                    posture_labels.append(p_label)
                    posture_codes.append(code)
                    old_heur_preds.append(old_heuristic_identify(win))
        except Exception:
            continue

    adl_bin_feats = []
    adl_bin_codes = []
    for f in adl_files:
        try:
            code = os.path.basename(f).split('_')[0].upper()
            data_50hz = parse_sisfall_file(f)
            if data_50hz is None or len(data_50hz) < WINDOW_SIZE:
                continue

            # สกัดหน้าต่างที่มีความเร่งสูงสุดของแต่ละไฟล์ ADL (Hardest Negative Window ของไฟล์นั้น)
            # เพื่อให้โมเดลเรียนรู้การแยก ADL ที่มีแรงกระแทกสูง (เช่น วิ่ง, กระโดด, ลงบันได, ทิ้งตัวนั่ง) ออกจาก Fall
            accel_mag = np.linalg.norm(data_50hz[:, 0:3], axis=1) / 9.81
            peak_idx = int(np.argmax(accel_mag))
            start_idx = max(0, peak_idx - 20)
            end_idx = start_idx + WINDOW_SIZE
            if end_idx > len(data_50hz):
                end_idx = len(data_50hz)
                start_idx = max(0, end_idx - WINDOW_SIZE)

            win_peak = data_50hz[start_idx:end_idx]
            if len(win_peak) == WINDOW_SIZE:
                adl_bin_feats.append(extract_features(win_peak))
                adl_bin_codes.append(code)
        except Exception:
            continue

    elapsed = time.time() - t0
    print(f"  [สำเร็จ] ประมวลผล 4,505 ไฟล์เสร็จใน {elapsed:.2f} วินาที")
    print(f"  • หน้าต่างการล้ม (F01–F15): {len(fall_bin_feats)} หน้าต่าง")
    print(f"  • หน้าต่างกิจวัตร (D01–D19): {len(adl_bin_feats)} หน้าต่าง")

    return (
        np.array(fall_bin_feats, dtype=np.float32),
        np.array(fall_bin_codes),
        np.array(adl_bin_feats, dtype=np.float32),
        np.array(adl_bin_codes),
        np.array(posture_feats, dtype=np.float32),
        np.array(posture_labels, dtype=np.int32),
        np.array(posture_codes),
        np.array(old_heur_preds, dtype=np.int32),
    )


def export_dual_mlp_to_c(bin_clf, bin_scaler, pos_clf, pos_scaler, output_path):
    """ส่งออกทั้งโมเดล Binary Fall MLP และโมเดล Posture Classification MLP ลงใน fall_model_sisfall.h"""
    bw = bin_clf.coefs_
    bb = bin_clf.intercepts_
    b_layers = len(bw)
    b_params = sum(w.size for w in bw) + sum(b.size for b in bb)

    pw = pos_clf.coefs_
    pb = pos_clf.intercepts_
    p_layers = len(pw)
    p_params = sum(w.size for w in pw) + sum(b.size for b in pb)

    lines = [
        "/*",
        " * โมเดล TinyML ตรวจจับการล้มและจำแนกท่าทางการล้ม (Pure SisFall Dataset Edition)",
        " * เทรนจากชุดข้อมูลมาตรฐานสากล: SisFall Dataset ครบทั้ง 4,505 ไฟล์ (F01-F15 Falls & D01-D19 ADLs)",
        f" * 1) Binary Fall Detection MLP : {b_layers} ชั้น (18 -> 32 -> 16 -> 1) [{b_params} พารามิเตอร์]",
        f" * 2) Posture Direction MLP     : {p_layers} ชั้น (45 -> 48 -> 24 -> 4) [{p_params} พารามิเตอร์]",
        " */\n",
        "#ifndef FALL_MODEL_SISFALL_H",
        "#define FALL_MODEL_SISFALL_H\n",
        "// ==================== 1. Binary Fall Detection Model (18 -> 32 -> 16 -> 1) ====================",
        f"#define MODEL_NUM_LAYERS {b_layers}",
        f"#define MODEL_INPUT_FEATURES {INPUT_FEATURES}\n",
    ]

    b_sizes = [bw[0].shape[0]] + [w.shape[1] for w in bw]
    lines.append(f"const int MODEL_LAYER_SIZES[{len(b_sizes)}] = {{{', '.join(str(s) for s in b_sizes)}}};\n")

    lines.append(f"const float MODEL_SCALER_MEAN[{INPUT_FEATURES}] = {{")
    lines.append("    " + ", ".join(f"{v:.8f}f" for v in bin_scaler.mean_))
    lines.append("};\n")

    lines.append(f"const float MODEL_SCALER_SCALE[{INPUT_FEATURES}] = {{")
    lines.append("    " + ", ".join(f"{v:.8f}f" for v in bin_scaler.scale_))
    lines.append("};\n")

    for idx, (w, b) in enumerate(zip(bw, bb)):
        rows, cols = w.shape
        lines.append(f"const float MODEL_W{idx}[{rows * cols}] = {{")
        flat = w.flatten()
        for i in range(0, len(flat), 8):
            chunk = flat[i:i+8]
            lines.append("    " + ", ".join(f"{v:.8f}f" for v in chunk) + ",")
        lines.append("};\n")

        lines.append(f"const float MODEL_B{idx}[{cols}] = {{")
        lines.append("    " + ", ".join(f"{v:.8f}f" for v in b))
        lines.append("};\n")

    lines.append("// ==================== 2. Multi-Class Posture Identification Model (45 -> 48 -> 24 -> 4) ====================")
    lines.append("#define HAS_POSTURE_ML_MODEL 1")
    lines.append(f"#define POSTURE_NUM_LAYERS {p_layers}")
    lines.append(f"#define POSTURE_INPUT_FEATURES {POSTURE_INPUT_FEATURES}")
    lines.append(f"#define POSTURE_NUM_CLASSES {POSTURE_NUM_CLASSES}\n")

    lines.append(f"const float POSTURE_SCALER_MEAN[{POSTURE_INPUT_FEATURES}] = {{")
    for i in range(0, POSTURE_INPUT_FEATURES, 9):
        chunk = pos_scaler.mean_[i:i+9]
        lines.append("    " + ", ".join(f"{v:.8f}f" for v in chunk) + ",")
    lines.append("};\n")

    lines.append(f"const float POSTURE_SCALER_SCALE[{POSTURE_INPUT_FEATURES}] = {{")
    for i in range(0, POSTURE_INPUT_FEATURES, 9):
        chunk = pos_scaler.scale_[i:i+9]
        lines.append("    " + ", ".join(f"{v:.8f}f" for v in chunk) + ",")
    lines.append("};\n")

    for idx, (w, b) in enumerate(zip(pw, pb)):
        rows, cols = w.shape
        lines.append(f"const float POSTURE_W{idx}[{rows * cols}] = {{")
        flat = w.flatten()
        for i in range(0, len(flat), 8):
            chunk = flat[i:i+8]
            lines.append("    " + ", ".join(f"{v:.8f}f" for v in chunk) + ",")
        lines.append("};\n")

        lines.append(f"const float POSTURE_B{idx}[{cols}] = {{")
        lines.append("    " + ", ".join(f"{v:.8f}f" for v in b))
        lines.append("};\n")

    lines.append("#endif // FALL_MODEL_SISFALL_H\n")

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write("\n".join(lines))
    print(f"\n  [สำเร็จ] ส่งออก Dual-MLP C Header ไปที่: {output_path}")


def main():
    print("=" * 75)
    print("  [START] เจาะลึกและเทรนโมเดล SisFall ครบวงจร (train_model_sisfall.py)")
    print("=" * 75)

    (
        X_fall, fall_codes,
        X_adl, adl_codes,
        X_pos, y_pos, pos_codes, old_heur_preds
    ) = load_full_sisfall_dataset()

    # -------------------------------------------------------------------------
    # ส่วนที่ 1: เทรนโมเดลตรวจจับการล้ม (Binary Fall vs ADL: 18 -> 32 -> 16 -> 1)
    # -------------------------------------------------------------------------
    y_fall = np.ones(len(X_fall), dtype=np.float32)
    y_adl = np.zeros(len(X_adl), dtype=np.float32)

    X_bin = np.nan_to_num(np.vstack([X_fall, X_adl]), nan=0.0, posinf=10.0, neginf=-10.0)
    y_bin = np.concatenate([y_fall, y_adl])
    all_codes = np.concatenate([fall_codes, adl_codes])

    bin_scaler = StandardScaler()
    X_bin_sc = bin_scaler.fit_transform(X_bin)

    X_tr, X_te, y_tr, y_te, c_tr, c_te = train_test_split(
        X_bin_sc, y_bin, all_codes, test_size=0.2, random_state=42, stratify=all_codes
    )

    print(f"\n[2/4] เทรนโมเดลที่ 1 — Binary Fall Detection MLP (18 -> 32 -> 16 -> 1)")
    print(f"  • จำนวนข้อมูลทั้งหมด: {len(X_bin)} หน้าต่าง (Train: {len(X_tr)}, Test: {len(X_te)})")
    bin_clf = MLPClassifier(
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
    bin_clf.fit(X_tr, y_tr)

    y_pred = bin_clf.predict(X_te)
    cm = confusion_matrix(y_te, y_pred)
    tn, fp, fn, tp = cm.ravel()
    print(f"  • Binary Test Accuracy : {bin_clf.score(X_te, y_te)*100:.2f}% (TP={tp}, TN={tn}, FP={fp}, FN={fn})")
    print(classification_report(y_te, y_pred, target_names=['ADL (0)', 'Fall (1)'], digits=4))

    # ประเมินความแม่นยำแยกตามรหัสท่าทาง F01-F15 และ D01-D19 ทั้งชุดข้อมูล
    y_all_pred = bin_clf.predict(X_bin_sc)
    print("  [ความแม่นยำการตรวจจับแยกตามรหัสท่าทาง (Binary Fall vs ADL)]")
    for prefix, title in [('F', 'กลุ่มท่าล้ม (F01-F15)'), ('D', 'กลุ่มกิจวัตรประจำวัน (D01-D19)')]:
        print(f"  --- {title} ---")
        unique_c = sorted([c for c in np.unique(all_codes) if c.startswith(prefix)])
        for c in unique_c:
            m = (all_codes == c)
            acc = np.mean(y_all_pred[m] == y_bin[m]) * 100
            print(f"    {c}: {np.sum(y_all_pred[m] == y_bin[m]):3d}/{np.sum(m):3d} ({acc:6.2f}%)")

    # -------------------------------------------------------------------------
    # ส่วนที่ 2: เทรนโมเดลระบุท่าทางและทิศทางการล้ม (Posture MLP: 45 -> 48 -> 24 -> 4)
    # -------------------------------------------------------------------------
    print(f"\n[3/4] เทรนโมเดลที่ 2 — Multi-Class Posture Direction MLP (45 -> 48 -> 24 -> 4)")
    old_acc = np.mean(old_heur_preds == y_pos) * 100
    print(f"  • ความแม่นยำการระบุท่าทางแบบเดิม (Heuristic ก่อนเทรน): {np.sum(old_heur_preds == y_pos)}/{len(y_pos)} ({old_acc:.2f}%)")

    X_pos = np.nan_to_num(X_pos, nan=0.0, posinf=10.0, neginf=-10.0)
    pos_scaler = StandardScaler()
    X_pos_sc = pos_scaler.fit_transform(X_pos)

    Xp_tr, Xp_te, yp_tr, yp_te, cp_tr, cp_te = train_test_split(
        X_pos_sc, y_pos, pos_codes, test_size=0.2, random_state=42, stratify=pos_codes
    )

    pos_clf = MLPClassifier(
        hidden_layer_sizes=(48, 24),
        activation='relu',
        solver='adam',
        alpha=0.01,
        max_iter=800,
        random_state=42,
        early_stopping=True,
        validation_fraction=0.15,
        n_iter_no_change=30,
        learning_rate_init=0.001
    )
    pos_clf.fit(Xp_tr, yp_tr)

    yp_pred_te = pos_clf.predict(Xp_te)
    yp_pred_all = pos_clf.predict(X_pos_sc)
    print(f"  • Posture MLP Test Accuracy  (Unseen 20% : {len(Xp_te)} ไฟล์): {pos_clf.score(Xp_te, yp_te)*100:.2f}%")
    print(f"  • Posture MLP Total Accuracy (All 100%   : {len(X_pos)} ไฟล์): {np.mean(yp_pred_all == y_pos)*100:.2f}%")
    print(classification_report(yp_te, yp_pred_te, target_names=POSTURE_CLASS_NAMES, digits=4))

    print("  [เจาะลึกความแม่นยำการระบุท่าทางการล้มรายท่า (F01–F15): ก่อนเทรน vs หลังเทรน]")
    for c in sorted(SISFALL_POSTURE_MAP.keys()):
        m_all = (pos_codes == c)
        m_te = (cp_te == c)
        old_c_acc = np.mean(old_heur_preds[m_all] == y_pos[m_all]) * 100
        new_te_acc = np.mean(yp_pred_te[m_te] == yp_te[m_te]) * 100
        new_all_acc = np.mean(yp_pred_all[m_all] == y_pos[m_all]) * 100
        desc = SISFALL_POSTURE_MAP[c][1]
        print(f"    {c} ({desc:52s}): เดิม {old_c_acc:5.1f}%  -->  ใหม่ (All) {new_all_acc:5.1f}% | (Test) {new_te_acc:5.1f}%")

    # -------------------------------------------------------------------------
    # ส่วนที่ 3: ส่งออกทั้ง 2 โมเดลลงใน src/fall_model_sisfall.h
    # -------------------------------------------------------------------------
    print("\n[4/4] ส่งออกค่าน้ำหนักโมเดลสู่ไฟล์ C Header...")
    export_dual_mlp_to_c(bin_clf, bin_scaler, pos_clf, pos_scaler, OUTPUT_MODEL_H)


if __name__ == '__main__':
    main()
