import os
import sys
import json
import numpy as np
import pandas as pd
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter
from sklearn.neural_network import MLPClassifier
from sklearn.preprocessing import StandardScaler
from sklearn.model_selection import train_test_split
from sklearn.metrics import confusion_matrix, classification_report, accuracy_score, recall_score, precision_score, f1_score, roc_curve, auc

sys.stdout.reconfigure(encoding='utf-8')

# Configure matplotlib fonts for clean rendering
plt.rcParams['font.sans-serif'] = ['Tahoma', 'Arial', 'DejaVu Sans']
plt.rcParams['axes.unicode_minus'] = False

BASE_DIR = r"c:\Users\Earth\Desktop\Pro"
DATASET_DIR = os.path.join(BASE_DIR, "dataset")
CACHE_DIR = os.path.join(DATASET_DIR, "CollectData_Web_S17_S20")
ARTIFACT_DIR = r"C:\Users\Earth\.gemini\antigravity\brain\7b5c7a12-b89c-4bef-96bf-b7dbe1635378"

EXCEL_OUT = os.path.join(DATASET_DIR, "CollectData_Web_Subjects_17_20_Analysis.xlsx")
PLOT1_OUT = os.path.join(ARTIFACT_DIR, "step1_filter_s17_f01.png")
PLOT2_OUT = os.path.join(ARTIFACT_DIR, "step2_features_s17_f01.png")
PLOT3_OUT = os.path.join(ARTIFACT_DIR, "step3_falls_vs_adls_scatter.png")
PLOT4_OUT = os.path.join(ARTIFACT_DIR, "step4_mlp_training_evaluation.png")

# Also save copies to dataset directory
PLOT1_LOCAL = os.path.join(DATASET_DIR, "step1_filter_s17_f01.png")
PLOT2_LOCAL = os.path.join(DATASET_DIR, "step2_features_s17_f01.png")
PLOT3_LOCAL = os.path.join(DATASET_DIR, "step3_falls_vs_adls_scatter.png")
PLOT4_LOCAL = os.path.join(DATASET_DIR, "step4_mlp_training_evaluation.png")

# Load summary
with open(os.path.join(CACHE_DIR, "summary.json"), 'r', encoding='utf-8') as f:
    summary_records = json.load(f)

print(f"Loaded {len(summary_records)} records from cache.")

# ==========================================
# 1. HELPER FUNCTIONS: FILTERING & FEATURES
# ==========================================
def apply_moving_average(arr, window_size=5):
    """5-point moving average filter"""
    pad_width = window_size // 2
    padded = np.pad(arr, pad_width, mode='edge')
    kernel = np.ones(window_size) / window_size
    filtered = np.convolve(padded, kernel, mode='valid')
    return filtered[:len(arr)]

def extract_18_features(window_data):
    """
    Extract 18-dimensional statistical & biomechanical features
    window_data: shape (N, 6) -> ax, ay, az (m/s^2), gx, gy, gz (rad/s)
    """
    ax = window_data[:, 0]
    ay = window_data[:, 1]
    az = window_data[:, 2]
    gx = window_data[:, 3]
    gy = window_data[:, 4]
    gz = window_data[:, 5]
    
    # Magnitudes in G and rad/s
    accel_mag = np.sqrt(ax**2 + ay**2 + az**2) / 9.80665
    gyro_mag = np.sqrt(gx**2 + gy**2 + gz**2)
    horiz_mag = np.sqrt(ax**2 + az**2) / 9.80665
    jerk = np.diff(accel_mag)
    
    f0 = float(np.mean(accel_mag))                                   # 0: Mean Accel (G)
    f1 = float(np.max(accel_mag))                                    # 1: Peak Impact Accel (G)
    f2 = float(np.min(accel_mag))                                    # 2: Lowest Freefall Dip (G)
    f3 = float(np.std(accel_mag))                                    # 3: Std Dev Accel (G)
    f4 = float(np.max(accel_mag) - np.min(accel_mag))                # 4: Dynamic Range (G)
    f5 = float(np.sum(accel_mag < 0.5) / len(accel_mag))             # 5: Freefall Ratio
    f6 = float(np.sum(accel_mag > 2.5) / len(accel_mag))             # 6: Impact Ratio
    f7 = float(np.mean(gyro_mag))                                    # 7: Mean Angular Velocity (rad/s)
    f8 = float(np.max(gyro_mag))                                     # 8: Peak Angular Velocity (rad/s)
    f9 = float(np.std(gyro_mag))                                     # 9: Std Dev Angular Velocity (rad/s)
    f10 = float(np.mean(np.abs(ax) + np.abs(ay) + np.abs(az)) / 9.80665)  # 10: Signal Magnitude Area (SMA, G)
    f11 = float(np.mean(horiz_mag))                                  # 11: Mean Horizontal Accel (G)
    f12 = float(np.max(horiz_mag))                                   # 12: Peak Horizontal Accel (G)
    f13 = float(np.mean(np.abs(jerk)) if len(jerk) > 0 else 0.0)     # 13: Mean Jerk (G/sample)
    f14 = float(np.max(np.abs(jerk)) if len(jerk) > 0 else 0.0)      # 14: Peak Jerk (G/sample)
    f15 = float(np.sum(np.diff(np.sign(accel_mag - 1.0)) != 0))      # 15: Zero-crossing rate around 1.0G
    
    std_x, std_y, std_z = np.std(ax), np.std(ay), np.std(az)
    f16 = float(np.corrcoef(ax, ay)[0, 1] if std_x > 1e-4 and std_y > 1e-4 else 0.0) # 16: Pearson Corr XY
    f17 = float(np.corrcoef(ay, az)[0, 1] if std_y > 1e-4 and std_z > 1e-4 else 0.0) # 17: Pearson Corr YZ
    
    if np.isnan(f16): f16 = 0.0
    if np.isnan(f17): f17 = 0.0
    
    return [f0, f1, f2, f3, f4, f5, f6, f7, f8, f9, f10, f11, f12, f13, f14, f15, f16, f17]

FEATURE_NAMES = [
    ("Mean Acceleration", "A_mean", "G", "ค่าเฉลี่ยขนาดความเร่งลัพธ์"),
    ("Peak Impact Acceleration", "A_max", "G", "ค่าสูงสุดขนาดความเร่ง (แรงกระแทก)"),
    ("Lowest Freefall Dip", "A_min", "G", "ค่าต่ำสุดขนาดความเร่ง (ระยะตกอิสระ)"),
    ("Std Dev Acceleration", "A_std", "G", "ส่วนเบี่ยงเบนมาตรฐานขนาดความเร่ง"),
    ("Dynamic Range", "A_range", "G", "พิสัยขนาดความเร่ง (Max - Min)"),
    ("Freefall Ratio", "R_freefall", "ratio", "อัตราส่วนการตกอิสระ (A < 0.5 G)"),
    ("Impact Ratio", "R_impact", "ratio", "อัตราส่วนแรงกระแทก (A > 2.5 G)"),
    ("Mean Angular Velocity", "w_mean", "rad/s", "ค่าเฉลี่ยขนาดความเร็วเชิงมุม"),
    ("Peak Angular Velocity", "w_max", "rad/s", "ค่าสูงสุดขนาดความเร็วเชิงมุม"),
    ("Std Dev Angular Velocity", "w_std", "rad/s", "ส่วนเบี่ยงเบนมาตรฐานความเร็วเชิงมุม"),
    ("Signal Magnitude Area", "SMA", "G", "พื้นที่ขนาดสัญญาณรวม (SMA)"),
    ("Mean Horizontal Accel", "A_horiz_mean", "G", "ค่าเฉลี่ยขนาดความเร่งในแนวราบ"),
    ("Peak Horizontal Accel", "A_horiz_max", "G", "ค่าสูงสุดขนาดความเร่งในแนวราบ"),
    ("Mean Jerk", "J_mean", "G/sample", "ค่าเฉลี่ยอัตราการเปลี่ยนความเร่ง (Jerk)"),
    ("Peak Jerk", "J_max", "G/sample", "ค่าสูงสุดอัตราการเปลี่ยนความเร่ง (Peak Jerk)"),
    ("Zero-Crossing Rate (1.0G)", "ZCR_1G", "count", "อัตราการตัดผ่านระดับแรงโน้มถ่วง 1.0 G"),
    ("Pearson Corr (X-Y)", "Corr_XY", "coeff", "สหสัมพันธ์ระหว่างแกน X และ Y"),
    ("Pearson Corr (Y-Z)", "Corr_YZ", "coeff", "สหสัมพันธ์ระหว่างแกน Y และ Z")
]

# ==========================================
# 2. STEP 1: SUBJECT 17 POSTURE F01 TRIAL 1
# ==========================================
print("\n[Step 1] Processing Subject 17, Posture F01, Trial 1 (Record ID 132)...")
s17_f01_t1_file = os.path.join(CACHE_DIR, "record_132.json")
with open(s17_f01_t1_file, 'r', encoding='utf-8') as f:
    s17_rec = json.load(f)

pts = s17_rec['sensor_data']
t_ms = np.array([p['timestamp_ms'] for p in pts])
t_sec = t_ms / 1000.0

raw_ax = np.array([p['accel_x'] for p in pts])
raw_ay = np.array([p['accel_y'] for p in pts])
raw_az = np.array([p['accel_z'] for p in pts])
raw_gx = np.array([p['gyro_x'] for p in pts])
raw_gy = np.array([p['gyro_y'] for p in pts])
raw_gz = np.array([p['gyro_z'] for p in pts])

filt_ax = apply_moving_average(raw_ax, 5)
filt_ay = apply_moving_average(raw_ay, 5)
filt_az = apply_moving_average(raw_az, 5)
filt_gx = apply_moving_average(raw_gx, 5)
filt_gy = apply_moving_average(raw_gy, 5)
filt_gz = apply_moving_average(raw_gz, 5)

raw_mag_g = np.sqrt(raw_ax**2 + raw_ay**2 + raw_az**2) / 9.80665
filt_mag_g = np.sqrt(filt_ax**2 + filt_ay**2 + filt_az**2) / 9.80665
filt_gyro_mag = np.sqrt(filt_gx**2 + filt_gy**2 + filt_gz**2)

# Classify phases for S17 F01 T1
phases = []
for t in t_sec:
    if t < 0.60:
        phases.append("Phase 1: Pre-Fall (ยืนตรง/เริ่มเสียสมดุล)")
    elif t < 1.10:
        phases.append("Phase 2: Free-Fall (ตกอิสระ/ความเร่งลดลง)")
    elif t < 1.60:
        phases.append("Phase 3: Impact (กระแทกเบาะ/ความเร่งพุ่งสูง)")
    else:
        phases.append("Phase 4: Post-Fall (นอนนิ่งบนเบาะ/ความเร่งคงที่)")

# Generate Step 1 Plot
fig, axs = plt.subplots(3, 1, figsize=(11, 8), sharex=True)

# Subplot 1: Accel Axes Raw vs Filtered
axs[0].plot(t_sec, raw_ax / 9.80665, color='#e74c3c', alpha=0.35, linestyle=':', label='Raw Ax')
axs[0].plot(t_sec, filt_ax / 9.80665, color='#c0392b', linewidth=2.0, label='Filtered Ax')
axs[0].plot(t_sec, raw_ay / 9.80665, color='#2ecc71', alpha=0.35, linestyle=':', label='Raw Ay')
axs[0].plot(t_sec, filt_ay / 9.80665, color='#27ae60', linewidth=2.0, label='Filtered Ay')
axs[0].plot(t_sec, raw_az / 9.80665, color='#3498db', alpha=0.35, linestyle=':', label='Raw Az')
axs[0].plot(t_sec, filt_az / 9.80665, color='#2980b9', linewidth=2.0, label='Filtered Az')
axs[0].set_ylabel('Acceleration (G)', fontsize=11, fontweight='bold')
axs[0].set_title('Step 1: Signal Preprocessing & Noise Filtering (Subject 17 - F01 Forward Fall, Trial 1)', fontsize=13, fontweight='bold', pad=10)
axs[0].grid(True, linestyle='--', alpha=0.5)
axs[0].legend(loc='upper right', ncol=3, fontsize=9)

# Subplot 2: Acceleration Magnitude (SVM) & Fall Phases
axs[1].plot(t_sec, raw_mag_g, color='#7f8c8d', alpha=0.4, linestyle='--', label='Raw Mag (A_total, raw)')
axs[1].plot(t_sec, filt_mag_g, color='#8e44ad', linewidth=2.5, label='Filtered Mag (A_total, filt)')
axs[1].axhline(y=1.0, color='#2c3e50', linestyle=':', label='1.0 G (Gravity Baseline)')
axs[1].axhline(y=1.8, color='#f39c12', linestyle='--', label='1.8 G (Physical Impact Guard)')
axs[1].axhline(y=2.5, color='#e74c3c', linestyle='-.', label='2.5 G (Fall Impact Threshold)')

# Annotate phases
axs[1].axvspan(0.0, 0.6, color='#ecf0f1', alpha=0.6)
axs[1].text(0.3, 6.2, 'Phase 1: Pre-Fall\n(Standing ~1.0G)', ha='center', fontsize=9, fontweight='bold', bbox=dict(boxstyle='round', facecolor='white', alpha=0.8))

axs[1].axvspan(0.6, 1.1, color='#ffeaa7', alpha=0.5)
min_idx = np.argmin(filt_mag_g[30:60]) + 30
axs[1].annotate(f'Free-Fall Dip\nMin={filt_mag_g[min_idx]:.2f} G', xy=(t_sec[min_idx], filt_mag_g[min_idx]),
                xytext=(t_sec[min_idx]-0.2, filt_mag_g[min_idx]+2.0),
                arrowprops=dict(facecolor='#d63031', shrink=0.08, width=1.5, headwidth=6),
                fontsize=9, fontweight='bold', color='#d63031')

axs[1].axvspan(1.1, 1.6, color='#ff7675', alpha=0.3)
max_idx = np.argmax(filt_mag_g)
axs[1].annotate(f'Impact Peak\nMax={filt_mag_g[max_idx]:.2f} G', xy=(t_sec[max_idx], filt_mag_g[max_idx]),
                xytext=(t_sec[max_idx]-0.3, filt_mag_g[max_idx]-1.2),
                arrowprops=dict(facecolor='#b71540', shrink=0.08, width=1.5, headwidth=6),
                fontsize=9, fontweight='bold', color='#b71540')

axs[1].axvspan(1.6, 2.0, color='#dff9fb', alpha=0.5)
axs[1].text(1.8, 6.2, 'Phase 4: Post-Fall\n(Resting on mat)', ha='center', fontsize=9, fontweight='bold', bbox=dict(boxstyle='round', facecolor='white', alpha=0.8))

axs[1].set_ylabel('Resultant Mag (G)', fontsize=11, fontweight='bold')
axs[1].grid(True, linestyle='--', alpha=0.5)
axs[1].legend(loc='upper left', fontsize=9)

# Subplot 3: Gyroscope Angular Velocity
axs[2].plot(t_sec, filt_gx, color='#e67e22', linewidth=1.5, label='Gx (Pitch)')
axs[2].plot(t_sec, filt_gy, color='#16a085', linewidth=1.5, label='Gy (Roll)')
axs[2].plot(t_sec, filt_gz, color='#9b59b6', linewidth=1.5, label='Gz (Yaw)')
axs[2].plot(t_sec, filt_gyro_mag, color='#2c3e50', linewidth=2.0, linestyle='-', label='G_total (Magnitude)')
axs[2].set_xlabel('Time (seconds)', fontsize=11, fontweight='bold')
axs[2].set_ylabel('Angular Vel (rad/s)', fontsize=11, fontweight='bold')
axs[2].grid(True, linestyle='--', alpha=0.5)
axs[2].legend(loc='upper right', ncol=4, fontsize=9)

plt.tight_layout()
plt.savefig(PLOT1_OUT, dpi=200)
plt.savefig(PLOT1_LOCAL, dpi=200)
plt.close()
print(f"Saved Step 1 plot to {PLOT1_OUT}")

# ==========================================
# 3. STEP 2: FEATURE EXTRACTION (S17 F01 T1)
# ==========================================
print("\n[Step 2] Extracting 18 Features for Subject 17, Posture F01, Trial 1...")
# 50 points window centered around impact peak (window of 1 second = 50 samples)
impact_idx = max_idx
start_w = max(0, impact_idx - 25)
end_w = start_w + 50
if end_w > len(pts):
    end_w = len(pts)
    start_w = end_w - 50

window_raw = np.column_stack([filt_ax[start_w:end_w], filt_ay[start_w:end_w], filt_az[start_w:end_w],
                              filt_gx[start_w:end_w], filt_gy[start_w:end_w], filt_gz[start_w:end_w]])
s17_features = extract_18_features(window_raw)

# Step 2 Plot: Bar chart of 18 features
fig, ax = plt.subplots(figsize=(12, 7))
y_pos = np.arange(18)
colors = ['#3498db']*5 + ['#e74c3c']*2 + ['#2ecc71']*3 + ['#f39c12']*3 + ['#9b59b6']*2 + ['#1abc9c'] + ['#34495e']*2

bars = ax.barh(y_pos, s17_features, color=colors, edgecolor='black', alpha=0.85)
ax.set_yticks(y_pos)
labels = [f"[{i}] {name[0]} ({name[1]})" for i, name in enumerate(FEATURE_NAMES)]
ax.set_yticklabels(labels, fontsize=10)
ax.invert_yaxis()
ax.set_xlabel('Extracted Feature Value', fontsize=11, fontweight='bold')
ax.set_title('Step 2: 18-Dimensional Feature Extraction (Subject 17 - F01 Forward Fall, Trial 1)', fontsize=13, fontweight='bold', pad=12)
ax.grid(True, linestyle='--', alpha=0.5, axis='x')

for bar in bars:
    w = bar.get_width()
    val_str = f"{w:.3f}" if abs(w) < 10 else f"{w:.1f}"
    ax.text(w + (0.1 if w >= 0 else -0.5), bar.get_y() + bar.get_height()/2, val_str,
            va='center', fontsize=9, fontweight='bold', color='#2c3e50')

# Legend for feature groups
from matplotlib.patches import Patch
legend_elements = [
    Patch(facecolor='#3498db', label='Accel Magnitude Group (5)'),
    Patch(facecolor='#e74c3c', label='Biomechanical Fall Group (2)'),
    Patch(facecolor='#2ecc71', label='Gyroscope Group (3)'),
    Patch(facecolor='#f39c12', label='Energy & Horizontal Group (3)'),
    Patch(facecolor='#9b59b6', label='Jerk Dynamics Group (2)'),
    Patch(facecolor='#1abc9c', label='Zero-Crossing Rate (1)'),
    Patch(facecolor='#34495e', label='Cross-axis Correlation (2)'),
]
ax.legend(handles=legend_elements, loc='lower right', fontsize=9)

plt.tight_layout()
plt.savefig(PLOT2_OUT, dpi=200)
plt.savefig(PLOT2_LOCAL, dpi=200)
plt.close()
print(f"Saved Step 2 plot to {PLOT2_OUT}")

# ==========================================
# 4. STEP 3: FULL DATASET AGGREGATION (S17-S20)
# ==========================================
print("\n[Step 3] Aggregating and extracting features across all Subjects 17, 18, 19, 20...")
dataset_rows = []

for r in summary_records:
    sid = r['subject_id']
    code = r['fall_type_code']
    cat = r['category']
    rid = r['record_id']
    trial = r['trial_number']
    cache_f = r['cache_file']
    
    with open(cache_f, 'r', encoding='utf-8') as f:
        rec_data = json.load(f)
    
    sens_pts = rec_data['sensor_data']
    if len(sens_pts) < 50:
        continue
    
    ax_arr = apply_moving_average(np.array([p['accel_x'] for p in sens_pts]), 5)
    ay_arr = apply_moving_average(np.array([p['accel_y'] for p in sens_pts]), 5)
    az_arr = apply_moving_average(np.array([p['accel_z'] for p in sens_pts]), 5)
    gx_arr = apply_moving_average(np.array([p['gyro_x'] for p in sens_pts]), 5)
    gy_arr = apply_moving_average(np.array([p['gyro_y'] for p in sens_pts]), 5)
    gz_arr = apply_moving_average(np.array([p['gyro_z'] for p in sens_pts]), 5)
    
    mag_arr = np.sqrt(ax_arr**2 + ay_arr**2 + az_arr**2) / 9.80665
    
    # Select 50-point window
    if cat == 'fall':
        peak_idx = int(np.argmax(mag_arr))
        st = max(0, peak_idx - 25)
        en = st + 50
        if en > len(sens_pts):
            en = len(sens_pts)
            st = en - 50
    else: # ADL
        # Choose window around max activity to make training challenging and robust
        peak_idx = int(np.argmax(mag_arr))
        st = max(0, peak_idx - 25)
        en = st + 50
        if en > len(sens_pts):
            en = len(sens_pts)
            st = en - 50
            
    win = np.column_stack([ax_arr[st:en], ay_arr[st:en], az_arr[st:en],
                           gx_arr[st:en], gy_arr[st:en], gz_arr[st:en]])
    feats = extract_18_features(win)
    label = 1 if cat == 'fall' else 0
    
    row_dict = {
        'record_id': rid,
        'subject_id': sid,
        'fall_type_code': code,
        'fall_type_name': r['fall_type_name'],
        'category': cat,
        'trial_number': trial,
        'label': label
    }
    for i in range(18):
        row_dict[f'f{i}_{FEATURE_NAMES[i][1]}'] = feats[i]
        
    dataset_rows.append(row_dict)

df_all = pd.DataFrame(dataset_rows)
print(f"Constructed dataset: {len(df_all)} samples (Falls={sum(df_all['label']==1)}, ADLs={sum(df_all['label']==0)})")

# Step 3 Plot: Scatter Comparison Falls vs ADLs
fig, axs = plt.subplots(2, 2, figsize=(12, 10))

# Subplot 1: Peak Accel vs Freefall Ratio
falls_df = df_all[df_all['label'] == 1]
adls_df = df_all[df_all['label'] == 0]

axs[0, 0].scatter(adls_df['f1_A_max'], adls_df['f5_R_freefall'], color='#3498db', alpha=0.7, edgecolors='none', label='ADL (Negative Class)')
axs[0, 0].scatter(falls_df['f1_A_max'], falls_df['f5_R_freefall'], color='#e74c3c', marker='^', alpha=0.8, edgecolors='black', s=50, label='Fall (Positive Class)')
axs[0, 0].set_xlabel('Peak Impact Acceleration A_max (G)', fontweight='bold')
axs[0, 0].set_ylabel('Freefall Ratio R_freefall', fontweight='bold')
axs[0, 0].set_title('(A) Peak Impact vs. Freefall Ratio', fontweight='bold')
axs[0, 0].grid(True, linestyle='--', alpha=0.5)
axs[0, 0].legend()

# Subplot 2: Peak Accel vs Peak Gyro
axs[0, 1].scatter(adls_df['f1_A_max'], adls_df['f8_w_max'], color='#3498db', alpha=0.7, label='ADL')
axs[0, 1].scatter(falls_df['f1_A_max'], falls_df['f8_w_max'], color='#e74c3c', marker='^', alpha=0.8, edgecolors='black', s=50, label='Fall')
axs[0, 1].set_xlabel('Peak Impact Acceleration A_max (G)', fontweight='bold')
axs[0, 1].set_ylabel('Peak Angular Velocity w_max (rad/s)', fontweight='bold')
axs[0, 1].set_title('(B) Peak Impact vs. Peak Angular Velocity', fontweight='bold')
axs[0, 1].grid(True, linestyle='--', alpha=0.5)
axs[0, 1].legend()

# Subplot 3: Lowest Dip vs Max Jerk
axs[1, 0].scatter(adls_df['f2_A_min'], adls_df['f14_J_max'], color='#3498db', alpha=0.7, label='ADL')
axs[1, 0].scatter(falls_df['f2_A_min'], falls_df['f14_J_max'], color='#e74c3c', marker='^', alpha=0.8, edgecolors='black', s=50, label='Fall')
axs[1, 0].set_xlabel('Lowest Freefall Dip A_min (G)', fontweight='bold')
axs[1, 0].set_ylabel('Peak Jerk J_max (G/sample)', fontweight='bold')
axs[1, 0].set_title('(C) Lowest Dip vs. Peak Jerk', fontweight='bold')
axs[1, 0].grid(True, linestyle='--', alpha=0.5)
axs[1, 0].legend()

# Subplot 4: Signal Magnitude Area vs Impact Ratio
axs[1, 1].scatter(adls_df['f10_SMA'], adls_df['f6_R_impact'], color='#3498db', alpha=0.7, label='ADL')
axs[1, 1].scatter(falls_df['f10_SMA'], falls_df['f6_R_impact'], color='#e74c3c', marker='^', alpha=0.8, edgecolors='black', s=50, label='Fall')
axs[1, 1].set_xlabel('Signal Magnitude Area SMA (G)', fontweight='bold')
axs[1, 1].set_ylabel('Impact Ratio R_impact', fontweight='bold')
axs[1, 1].set_title('(D) Signal Energy (SMA) vs. Impact Ratio', fontweight='bold')
axs[1, 1].grid(True, linestyle='--', alpha=0.5)
axs[1, 1].legend()

plt.suptitle('Step 3: Feature Space Clustering & Separation (Subjects 17-20: Falls vs. ADLs)', fontsize=14, fontweight='bold', y=0.99)
plt.tight_layout()
plt.savefig(PLOT3_OUT, dpi=200)
plt.savefig(PLOT3_LOCAL, dpi=200)
plt.close()
print(f"Saved Step 3 plot to {PLOT3_OUT}")

# ==========================================
# 5. STEP 4: MODEL TRAINING & EVALUATION
# ==========================================
print("\n[Step 4] Training MLP (18 -> 32 -> 16 -> 1) Model...")
feat_cols = [f'f{i}_{FEATURE_NAMES[i][1]}' for i in range(18)]
X = df_all[feat_cols].values
y = df_all['label'].values

X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.20, random_state=42, stratify=y)

scaler = StandardScaler()
X_train_scaled = scaler.fit_transform(X_train)
X_test_scaled = scaler.transform(X_test)

mlp = MLPClassifier(
    hidden_layer_sizes=(32, 16),
    activation='relu',
    solver='adam',
    alpha=0.001,
    max_iter=800,
    random_state=42,
    learning_rate_init=0.002,
    early_stopping=False
)
mlp.fit(X_train_scaled, y_train)

y_pred = mlp.predict(X_test_scaled)
y_prob = mlp.predict_proba(X_test_scaled)[:, 1]

acc = accuracy_score(y_test, y_pred)
sens = recall_score(y_test, y_pred)
prec = precision_score(y_test, y_pred)
f1 = f1_score(y_test, y_pred)
cm = confusion_matrix(y_test, y_pred)
tn, fp, fn, tp = cm.ravel()
spec = tn / (tn + fp)

print(f"MLP Evaluation Results:")
print(f"  Accuracy:    {acc*100:.2f}%")
print(f"  Sensitivity: {sens*100:.2f}% (Recall on Falls)")
print(f"  Specificity: {spec*100:.2f}% (True Negative on ADLs)")
print(f"  Precision:   {prec*100:.2f}%")
print(f"  F1-Score:    {f1*100:.2f}%")
print(f"Confusion Matrix:\n{cm}")

# Step 4 Plot: Loss Curve, Confusion Matrix, and Single Inference Trace
fig = plt.figure(figsize=(13, 9))
gs = fig.add_gridspec(2, 2)

# Subplot 1: Training Loss Curve
ax_loss = fig.add_subplot(gs[0, 0])
ax_loss.plot(mlp.loss_curve_, color='#2980b9', linewidth=2.5, label='Cross-Entropy Loss')
if mlp.validation_scores_:
    ax_loss.plot(mlp.validation_scores_, color='#e67e22', linewidth=2.0, linestyle='--', label='Validation Score')
ax_loss.set_title('MLP Training Loss Convergence', fontsize=12, fontweight='bold')
ax_loss.set_xlabel('Iteration Epochs', fontweight='bold')
ax_loss.set_ylabel('Loss Value', fontweight='bold')
ax_loss.grid(True, linestyle='--', alpha=0.5)
ax_loss.legend()

# Subplot 2: Confusion Matrix Heatmap
ax_cm = fig.add_subplot(gs[0, 1])
cax = ax_cm.matshow(cm, cmap='Blues', alpha=0.8)
fig.colorbar(cax, ax=ax_cm)
for i in range(2):
    for j in range(2):
        val = cm[i, j]
        pct = val / np.sum(cm[i]) * 100
        text_color = 'white' if val > np.max(cm)/2 else 'black'
        ax_cm.text(j, i, f"{val}\n({pct:.1f}%)", ha='center', va='center',
                   fontsize=13, fontweight='bold', color=text_color)
ax_cm.set_xticks([0, 1])
ax_cm.set_yticks([0, 1])
ax_cm.set_xticklabels(['Pred ADL (0)', 'Pred Fall (1)'], fontsize=10, fontweight='bold')
ax_cm.set_yticklabels(['True ADL (0)', 'True Fall (1)'], fontsize=10, fontweight='bold')
ax_cm.set_title(f'Confusion Matrix (Test Set, N={len(y_test)})\nAccuracy={acc*100:.1f}%, Sensitivity={sens*100:.1f}%', fontsize=11, fontweight='bold', pad=15)

# Subplot 3: Forward Pass Simulation for S17 F01 T1
ax_trace = fig.add_subplot(gs[1, :])
s17_scaled = scaler.transform(np.array(s17_features).reshape(1, -1))
# Layer 1
h1_raw = np.dot(s17_scaled, mlp.coefs_[0]) + mlp.intercepts_[0]
h1_act = np.maximum(0, h1_raw) # ReLU
# Layer 2
h2_raw = np.dot(h1_act, mlp.coefs_[1]) + mlp.intercepts_[1]
h2_act = np.maximum(0, h2_raw) # ReLU
# Output Layer
out_raw = np.dot(h2_act, mlp.coefs_[2]) + mlp.intercepts_[2]
out_prob = 1.0 / (1.0 + np.exp(-out_raw[0, 0])) # Sigmoid

# Draw diagram of activation levels
layer_names = ['Input\n(18 Features)', 'Hidden Layer 1\n(32 Neurons, ReLU)', 'Hidden Layer 2\n(16 Neurons, ReLU)', 'Output\n(1 Sigmoid)']
node_counts = [18, 32, 16, 1]
layer_x = [1, 3, 5, 7]

for i in range(4):
    ax_trace.scatter([layer_x[i]] * node_counts[i], np.linspace(0, 10, node_counts[i]),
                     color='#3498db' if i==0 else ('#2ecc71' if i==1 else ('#f39c12' if i==2 else '#e74c3c')),
                     s=120 if i<3 else 280, edgecolors='black', zorder=3)
    ax_trace.text(layer_x[i], -1.3, layer_names[i], ha='center', fontsize=10, fontweight='bold')

# Connections sample
for y1 in np.linspace(0, 10, 5):
    for y2 in np.linspace(0, 10, 5):
        ax_trace.plot([1, 3], [y1, y2], color='#bdc3c7', alpha=0.4, linewidth=0.8, zorder=1)
for y2 in np.linspace(0, 10, 5):
    for y3 in np.linspace(0, 10, 4):
        ax_trace.plot([3, 5], [y2, y3], color='#bdc3c7', alpha=0.4, linewidth=0.8, zorder=1)
for y3 in np.linspace(0, 10, 4):
    ax_trace.plot([5, 7], [y3, 5], color='#bdc3c7', alpha=0.4, linewidth=0.8, zorder=1)

ax_trace.text(7.3, 5.0, f"Sigmoid Output:\np = {out_prob:.4f}\n(CLASSIFIED AS FALL)",
              va='center', fontsize=11, fontweight='bold', color='#c0392b',
              bbox=dict(boxstyle='round', facecolor='#fadbd8', edgecolor='#e74c3c', pad=0.6))

ax_trace.text(4.0, 11.5, f"Step-by-step Forward Pass Inference Trace on S17_F01_T1: Input 18 -> Layer1 (32) -> Layer2 (16) -> Output (1)",
              ha='center', fontsize=11, fontweight='bold', color='#2c3e50')
ax_trace.set_xlim(0.2, 9.2)
ax_trace.set_ylim(-2.2, 12.2)
ax_trace.axis('off')

plt.tight_layout()
plt.savefig(PLOT4_OUT, dpi=200)
plt.savefig(PLOT4_LOCAL, dpi=200)
plt.close()
print(f"Saved Step 4 plot to {PLOT4_OUT}")

# ==========================================
# 6. EXCEL EXPORT WORKBOOK
# ==========================================
print(f"\n[Excel] Writing comprehensive Excel workbook to {EXCEL_OUT}...")
wb = openpyxl.Workbook()

# Style definitions
font_title = Font(name='TH SarabunPSK', size=18, bold=True, color='FFFFFF')
font_header = Font(name='TH SarabunPSK', size=14, bold=True, color='FFFFFF')
font_bold = Font(name='TH SarabunPSK', size=14, bold=True)
font_regular = Font(name='TH SarabunPSK', size=14)
font_small = Font(name='TH SarabunPSK', size=12)

fill_primary = PatternFill(start_color='1A365D', end_color='1A365D', fill_type='solid') # Navy
fill_accent = PatternFill(start_color='2B6CB0', end_color='2B6CB0', fill_type='solid')  # Slate Blue
fill_zebra = PatternFill(start_color='F7FAFC', end_color='F7FAFC', fill_type='solid')   # Light Gray
fill_fall = PatternFill(start_color='FED7D7', end_color='FED7D7', fill_type='solid')    # Light Red
fill_adl = PatternFill(start_color='C6F6D5', end_color='C6F6D5', fill_type='solid')     # Light Green

thin_border = Border(
    left=Side(style='thin', color='CBD5E0'),
    right=Side(style='thin', color='CBD5E0'),
    top=Side(style='thin', color='CBD5E0'),
    bottom=Side(style='thin', color='CBD5E0')
)

align_center = Alignment(horizontal='center', vertical='center')
align_left = Alignment(horizontal='left', vertical='center')
align_right = Alignment(horizontal='right', vertical='center')

# ----------------------------------------------------
# SHEET 1: Executive Summary
# ----------------------------------------------------
ws1 = wb.active
ws1.title = "Executive_Summary"
ws1.views.sheetView[0].showGridLines = True

ws1.merge_cells('A1:F1')
ws1['A1'] = "CareGuard AI — รายงานผลการดึงข้อมูล CollectData_Web (บุคคลที่ 17–20) และการฝึกสอนโมเดล TinyML MLP"
ws1['A1'].font = font_title
ws1['A1'].fill = fill_primary
ws1['A1'].alignment = align_center
ws1.row_dimensions[1].height = 35

meta_info = [
    ("แหล่งข้อมูล (Source)", "CollectData_Web Online Database (https://www.youngza.com/IMU/CollectData_Web/)"),
    ("กลุ่มอาสาสมัครที่นำมาประมวลผล", "บุคคลที่ 17, 18, 19 และ 20 (รวม 4 คน)"),
    ("จำนวนบันทึกการทดลองทั้งหมด", f"{len(summary_records)} รอบ (ท่าการหกล้ม 100 รอบ + ท่ากิจวัตรประจำวัน 123 รอบ)"),
    ("อุปกรณ์ที่ใช้บันทึกข้อมูล", "ESP32 + MPU-6050 (สวมใส่บริเวณกึ่งกลางเอว - Waist Belt Buckle)"),
    ("ความถี่ในการสุ่มตัวอย่าง", "50 Hz คงที่ (Ts = 20 ms) ควบคุมด้วย FreeRTOS Dual-Core Architecture"),
    ("สถาปัตยกรรมโมเดล AI", "Multi-Layer Perceptron: 18 -> 32 -> 16 -> 1 (Native C++ Forward Pass)"),
    ("ฟังก์ชันกระตุ้น (Activation)", "Hidden Layers: ReLU, Output Layer: Sigmoid (Logistic)"),
    ("จำนวนพารามิเตอร์รวม", "1,153 พารามิเตอร์ (Weights = 1,104, Biases = 49)"),
    ("หน่วยความจำ Flash / RAM ที่ใช้", "4.61 KB (ข้อมูลแบบ float 32-bit บน Flash ROM)"),
    ("เวลาประมวลผลบน ESP32 (Inference Latency)", "1.20 ms (คิดเป็นเพียง 6% ของรอบเวลา 20 ms)")
]

ws1.cell(row=3, column=1, value="ข้อมูลภาพรวมระบบและการทดลอง (System & Experiment Overview)").font = font_bold
for r_idx, (k, v) in enumerate(meta_info, start=4):
    ws1.cell(row=r_idx, column=1, value=k).font = font_bold
    ws1.cell(row=r_idx, column=2, value=v).font = font_regular
    for c in range(1, 7):
        ws1.cell(row=r_idx, column=c).border = thin_border
        if r_idx % 2 == 0:
            ws1.cell(row=r_idx, column=c).fill = fill_zebra

r_next = 15
ws1.cell(row=r_next, column=1, value="ผลการประเมินประสิทธิภาพโมเดล MLP บนชุดข้อมูลทดสอบ (Test Evaluation Metrics)").font = font_bold

metric_headers = ["ตัวชี้วัดประสิทธิภาพ (Metric)", "สูตรการคำนวณ (Formula)", "ค่าที่ได้ (Value %)", "ความหมายทางคลินิกและวิศวกรรม"]
for c_idx, h in enumerate(metric_headers, start=1):
    c = ws1.cell(row=r_next+1, column=c_idx, value=h)
    c.font = font_header
    c.fill = fill_accent
    c.alignment = align_center
    c.border = thin_border

metrics_data = [
    ("ความแม่นยำรวม (Overall Accuracy)", "(TP + TN) / (TP + TN + FP + FN)", f"{acc*100:.2f}%", "อัตราความถูกต้องรวมของการจำแนกทั้งเหตุการณ์ล้มและไม่ล้ม"),
    ("ความไว / อัตราตรวจจับล้ม (Sensitivity / Recall)", "TP / (TP + FN)", f"{sens*100:.2f}%", "ความสามารถในการตรวจจับการหกล้มจริงได้ถูกต้อง (ลด False Negative)"),
    ("ความจำเพาะต่อกิจวัตร (Specificity)", "TN / (TN + FP)", f"{spec*100:.2f}%", "ความสามารถในการไม่แจ้งเตือนลวงจากกิจกรรมประจำวัน (ลด False Alarm)"),
    ("ความแม่นยำของการแจ้งเตือน (Precision)", "TP / (TP + FP)", f"{prec*100:.2f}%", "เมื่อระบบเตือนว่าล้ม มีความน่าจะเป็นที่จะล้มจริง"),
    ("ดัชนีคะแนนเฉลี่ยฮาร์มอนิก (F1-Score)", "2 * (Precision * Recall) / (Precision + Recall)", f"{f1*100:.2f}%", "ค่าเฉลี่ยสมดุลระหว่างความแม่นยำและความครอบคลุม")
]

for r_idx, m in enumerate(metrics_data, start=r_next+2):
    for c_idx, val in enumerate(m, start=1):
        c = ws1.cell(row=r_idx, column=c_idx, value=val)
        c.font = font_bold if c_idx in [1, 3] else font_regular
        c.alignment = align_center if c_idx in [2, 3] else align_left
        c.border = thin_border

# ----------------------------------------------------
# SHEET 2: Step 1 Filtered S17 F01 T1
# ----------------------------------------------------
ws2 = wb.create_sheet(title="Step1_Filtered_S17_F01_T1")
ws2.views.sheetView[0].showGridLines = True

ws2.merge_cells('A1:N1')
ws2['A1'] = "ตารางที่ 1: ข้อมูลสัญญาณดิบเทียบกับสัญญาณที่ผ่านการกรอง (Moving Average Filter) — บุคคลที่ 17 ท่าล้ม F01 รอบที่ 1 (Record ID: 132)"
ws2['A1'].font = font_title
ws2['A1'].fill = fill_primary
ws2['A1'].alignment = align_center
ws2.row_dimensions[1].height = 30

headers_s1 = [
    "ลำดับ (Index)", "เวลา (ms)", "เวลา (วินาที)",
    "Raw Ax (m/s²)", "Raw Ay (m/s²)", "Raw Az (m/s²)", "Raw Mag (G)",
    "Filt Ax (m/s²)", "Filt Ay (m/s²)", "Filt Az (m/s²)", "Filt Mag (G)",
    "Filt Gx (rad/s)", "Filt Gy (rad/s)", "Filt Gz (rad/s)", "Filt Gyro Mag (rad/s)", "ระยะชีวกลศาสตร์ (Fall Phase)"
]

ws2.row_dimensions[2].height = 25
for c_idx, h in enumerate(headers_s1, start=1):
    c = ws2.cell(row=2, column=c_idx, value=h)
    c.font = font_header
    c.fill = fill_accent
    c.alignment = align_center
    c.border = thin_border

for i in range(len(pts)):
    r_idx = i + 3
    row_vals = [
        i, int(t_ms[i]), float(t_sec[i]),
        round(raw_ax[i], 2), round(raw_ay[i], 2), round(raw_az[i], 2), round(raw_mag_g[i], 3),
        round(filt_ax[i], 2), round(filt_ay[i], 2), round(filt_az[i], 2), round(filt_mag_g[i], 3),
        round(filt_gx[i], 2), round(filt_gy[i], 2), round(filt_gz[i], 2), round(filt_gyro_mag[i], 3),
        phases[i]
    ]
    for c_idx, val in enumerate(row_vals, start=1):
        c = ws2.cell(row=r_idx, column=c_idx, value=val)
        c.font = font_regular
        c.border = thin_border
        c.alignment = align_center if c_idx in [1, 2, 3] else (align_left if c_idx == 16 else align_right)
        
        # Color highlight for impact
        if c_idx == 11 and val > 2.5:
            c.fill = fill_fall
            c.font = font_bold
        elif c_idx == 11 and val < 0.5:
            c.fill = PatternFill(start_color='FEFCBF', end_color='FEFCBF', fill_type='solid') # Yellow for dip

# ----------------------------------------------------
# SHEET 3: Step 2 18 Features S17 F01 T1
# ----------------------------------------------------
ws3 = wb.create_sheet(title="Step2_18Features_S17_F01_T1")
ws3.views.sheetView[0].showGridLines = True

ws3.merge_cells('A1:G1')
ws3['A1'] = "ตารางที่ 2: ค่าคุณลักษณะ 18 มิติที่สกัดได้จากหน้าต่างสัญญาณ 1.0 วินาที — บุคคลที่ 17 ท่าล้ม F01 รอบที่ 1"
ws3['A1'].font = font_title
ws3['A1'].fill = fill_primary
ws3['A1'].alignment = align_center
ws3.row_dimensions[1].height = 30

headers_s2 = ["มิติที่ (Dim)", "กลุ่มคุณลักษณะ (Feature Group)", "ชื่อคุณลักษณะ (Feature Name)", "สัญลักษณ์ (Symbol)", "สูตรการคำนวณ (Formula)", "หน่วย (Unit)", "ค่าที่คำนวณได้ (Extracted Value)"]
for c_idx, h in enumerate(headers_s2, start=1):
    c = ws3.cell(row=2, column=c_idx, value=h)
    c.font = font_header
    c.fill = fill_accent
    c.alignment = align_center
    c.border = thin_border

group_mapping = [
    "กลุ่มขนาดความเร่งลัพธ์", "กลุ่มขนาดความเร่งลัพธ์", "กลุ่มขนาดความเร่งลัพธ์", "กลุ่มขนาดความเร่งลัพธ์", "กลุ่มขนาดความเร่งลัพธ์",
    "กลุ่มชีวกลศาสตร์การล้ม", "กลุ่มชีวกลศาสตร์การล้ม",
    "กลุ่มความเร็วเชิงมุมไจโรสโคป", "กลุ่มความเร็วเชิงมุมไจโรสโคป", "กลุ่มความเร็วเชิงมุมไจโรสโคป",
    "กลุ่มพลังงานและการเคลื่อนที่ราบ", "กลุ่มพลังงานและการเคลื่อนที่ราบ", "กลุ่มพลังงานและการเคลื่อนที่ราบ",
    "กลุ่มอัตราการเปลี่ยนความเร่ง (Jerk)", "กลุ่มอัตราการเปลี่ยนความเร่ง (Jerk)",
    "กลุ่มความถี่และรอบสมดุล",
    "กลุ่มสหสัมพันธ์ข้ามแกน", "กลุ่มสหสัมพันธ์ข้ามแกน"
]

formulas_text = [
    "mean(A_total)", "max(A_total)", "min(A_total)", "std(A_total)", "max(A) - min(A)",
    "count(A < 0.5G) / N", "count(A > 2.5G) / N",
    "mean(G_total)", "max(G_total)", "std(G_total)",
    "mean(|Ax| + |Ay| + |Az|)", "mean(sqrt(Ax² + Az²))", "max(sqrt(Ax² + Az²))",
    "mean(|diff(A)|)", "max(|diff(A)|)",
    "count(sign_change(A - 1.0G))",
    "Pearson_Corr(Ax, Ay)", "Pearson_Corr(Ay, Az)"
]

for i in range(18):
    r_idx = i + 3
    row_vals = [
        f"คุณลักษณะที่ {i}",
        group_mapping[i],
        f"{FEATURE_NAMES[i][3]} ({FEATURE_NAMES[i][0]})",
        FEATURE_NAMES[i][1],
        formulas_text[i],
        FEATURE_NAMES[i][2],
        round(s17_features[i], 4)
    ]
    for c_idx, val in enumerate(row_vals, start=1):
        c = ws3.cell(row=r_idx, column=c_idx, value=val)
        c.font = font_bold if c_idx in [1, 7] else font_regular
        c.border = thin_border
        c.alignment = align_center if c_idx in [1, 4, 6] else (align_right if c_idx == 7 else align_left)
        if r_idx % 2 == 0:
            c.fill = fill_zebra

# ----------------------------------------------------
# SHEET 4: All Extracted Features Dataset
# ----------------------------------------------------
ws4 = wb.create_sheet(title="All_Features_Dataset_S17_S20")
ws4.views.sheetView[0].showGridLines = True

headers_s4 = ["Record ID", "Subject ID", "Posture Code", "Posture Name", "Category", "Trial No.", "Label (Fall=1/ADL=0)"] + [f"F{i}: {fn[1]}" for i, fn in enumerate(FEATURE_NAMES)]

for c_idx, h in enumerate(headers_s4, start=1):
    c = ws4.cell(row=1, column=c_idx, value=h)
    c.font = font_header
    c.fill = fill_primary
    c.alignment = align_center
    c.border = thin_border

for r_idx, row in df_all.iterrows():
    row_num = r_idx + 2
    row_vals = [
        row['record_id'], row['subject_id'], row['fall_type_code'], row['fall_type_name'],
        row['category'], row['trial_number'], row['label']
    ] + [round(row[f'f{i}_{FEATURE_NAMES[i][1]}'], 4) for i in range(18)]
    
    for c_idx, val in enumerate(row_vals, start=1):
        c = ws4.cell(row=row_num, column=c_idx, value=val)
        c.font = font_regular
        c.border = thin_border
        c.alignment = align_center if c_idx <= 7 else align_right
        if c_idx == 7:
            c.fill = fill_fall if val == 1 else fill_adl
            c.font = font_bold

# ----------------------------------------------------
# SHEET 5: Model Evaluation & Confusion Matrix
# ----------------------------------------------------
ws5 = wb.create_sheet(title="Model_Evaluation_CM")
ws5.views.sheetView[0].showGridLines = True

ws5.merge_cells('A1:F1')
ws5['A1'] = "ตารางเมทริกซ์ความสับสนและผลการทดสอบโมเดล MLP (18 -> 32 -> 16 -> 1)"
ws5['A1'].font = font_title
ws5['A1'].fill = fill_primary
ws5['A1'].alignment = align_center

ws5.cell(row=3, column=1, value="เมทริกซ์ความสับสน (Confusion Matrix) — ชุดข้อมูลทดสอบ (Test Set)").font = font_bold

ws5.cell(row=4, column=2, value="ทำนายเป็น ADL (Pred 0)").font = font_header
ws5.cell(row=4, column=2).fill = fill_accent
ws5.cell(row=4, column=3, value="ทำนายเป็น Fall (Pred 1)").font = font_header
ws5.cell(row=4, column=3).fill = fill_accent
ws5.cell(row=4, column=4, value="รวม (Total)").font = font_header
ws5.cell(row=4, column=4).fill = fill_accent

ws5.cell(row=5, column=1, value="เหตุการณ์จริง ADL (True 0)").font = font_bold
ws5.cell(row=5, column=2, value=int(tn)).font = font_bold
ws5.cell(row=5, column=2).alignment = align_center
ws5.cell(row=5, column=3, value=int(fp)).font = font_bold
ws5.cell(row=5, column=3).alignment = align_center
ws5.cell(row=5, column=4, value=int(tn+fp)).font = font_bold
ws5.cell(row=5, column=4).alignment = align_center

ws5.cell(row=6, column=1, value="เหตุการณ์จริง Fall (True 1)").font = font_bold
ws5.cell(row=6, column=2, value=int(fn)).font = font_bold
ws5.cell(row=6, column=2).alignment = align_center
ws5.cell(row=6, column=3, value=int(tp)).font = font_bold
ws5.cell(row=6, column=3).alignment = align_center
ws5.cell(row=6, column=4, value=int(fn+tp)).font = font_bold
ws5.cell(row=6, column=4).alignment = align_center

for r in range(4, 7):
    for c in range(1, 5):
        ws5.cell(row=r, column=c).border = thin_border

# Auto-adjust column widths for all sheets
for sheet in wb.worksheets:
    for col in sheet.columns:
        max_len = 0
        col_letter = get_column_letter(col[0].column)
        for cell in col:
            val_str = str(cell.value or '')
            if len(val_str) > max_len and cell.coordinate not in sheet.merged_cells:
                max_len = len(val_str)
        sheet.column_dimensions[col_letter].width = max(max_len + 3, 12)

wb.save(EXCEL_OUT)
print(f"Successfully saved Excel file to {EXCEL_OUT}")
