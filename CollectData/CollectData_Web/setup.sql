-- =============================================
-- CollectData — สร้างตารางฐานข้อมูล
-- ฐานข้อมูล: thebesti_imu
-- เรียกใช้: import ผ่าน phpMyAdmin หรือ mysql CLI
-- =============================================

-- ตาราง cd_devices — อุปกรณ์ ESP32
CREATE TABLE IF NOT EXISTS cd_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL COMMENT 'ชื่ออุปกรณ์',
    mac_address VARCHAR(17) COMMENT 'MAC Address',
    description VARCHAR(200) COMMENT 'คำอธิบาย',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง cd_subjects — ผู้ทดสอบ
CREATE TABLE IF NOT EXISTS cd_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'ชื่อ-สกุล',
    age INT COMMENT 'อายุ (ปี)',
    height_cm FLOAT COMMENT 'ส่วนสูง (ซม.)',
    weight_kg FLOAT COMMENT 'น้ำหนัก (กก.)',
    gender ENUM('M','F','O') DEFAULT 'O' COMMENT 'เพศ: M=ชาย, F=หญิง, O=อื่นๆ',
    notes TEXT COMMENT 'หมายเหตุ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง cd_fall_types — ประเภทการล้ม (เพิ่มได้ผ่านหน้าเว็บ)
CREATE TABLE IF NOT EXISTS cd_fall_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'รหัสประเภท',
    name_th VARCHAR(100) NOT NULL COMMENT 'ชื่อภาษาไทย',
    name_en VARCHAR(100) COMMENT 'ชื่อภาษาอังกฤษ',
    category ENUM('fall','adl','other') DEFAULT 'other' COMMENT 'หมวด: fall=การล้ม, adl=กิจวัตร, other=อื่นๆ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง cd_trial — รอบการทดลอง
CREATE TABLE IF NOT EXISTS cd_trial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL COMMENT 'ผู้ทดสอบ',
    trial_label VARCHAR(50) COMMENT 'ชื่อรอบทดลอง เช่น Trial-1, รอบที่ 3',
    description TEXT COMMENT 'รายละเอียดรอบทดลอง',
    trial_date DATE COMMENT 'วันที่ทดลอง',
    notes TEXT COMMENT 'หมายเหตุ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES cd_subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง cd_records — บันทึกการล้มแต่ละครั้ง
CREATE TABLE IF NOT EXISTS cd_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL COMMENT 'ผู้ทดสอบ',
    device_id INT NOT NULL COMMENT 'อุปกรณ์',
    fall_type_id INT NOT NULL COMMENT 'ประเภทการล้ม',
    trial_id INT COMMENT 'รอบทดลอง',
    trial_number INT DEFAULT 1 COMMENT 'ลำดับในรอบทดลอง',
    notes TEXT COMMENT 'หมายเหตุ',
    status ENUM('recording','completed','cancelled') DEFAULT 'completed' COMMENT 'สถานะ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES cd_subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES cd_devices(id) ON DELETE CASCADE,
    FOREIGN KEY (fall_type_id) REFERENCES cd_fall_types(id) ON DELETE CASCADE,
    FOREIGN KEY (trial_id) REFERENCES cd_trial(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง cd_sensor_data — ข้อมูลเซ็นเซอร์ดิบ
CREATE TABLE IF NOT EXISTS cd_sensor_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL COMMENT 'บันทึกที่เชื่อมโยง',
    timestamp_ms BIGINT NOT NULL COMMENT 'เวลา (มิลลิวินาที)',
    accel_x FLOAT COMMENT 'ความเร่งแกน X (m/s²)',
    accel_y FLOAT COMMENT 'ความเร่งแกน Y (m/s²)',
    accel_z FLOAT COMMENT 'ความเร่งแกน Z (m/s²)',
    gyro_x FLOAT COMMENT 'ไจโรสโคปแกน X (°/s)',
    gyro_y FLOAT COMMENT 'ไจโรสโคปแกน Y (°/s)',
    gyro_z FLOAT COMMENT 'ไจโรสโคปแกน Z (°/s)',
    FOREIGN KEY (record_id) REFERENCES cd_records(id) ON DELETE CASCADE,
    INDEX idx_record_time (record_id, timestamp_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- เพิ่มประเภทท่าทางมาตรฐาน (4 ประเภทหลัก: Fall, ADL, Near-Fall, Test)
-- =============================================

INSERT INTO cd_fall_types (code, name_th, name_en, category) VALUES
-- ประเภท 1 การล้ม (Fall) — 15 วินาที
('fall_forward',  'F01 การล้มไปข้างหน้า',  'F01 Fall Forward',      'fall'),
('fall_backward', 'F02 การล้มไปข้างหลัง',  'F02 Fall Backward',     'fall'),
('fall_left',     'F03 การล้มไปด้านซ้าย',  'F03 Fall Left',         'fall'),
('fall_right',    'F04 การล้มไปด้านขวา',   'F04 Fall Right',        'fall'),
('fall_sitting',  'F05 การล้มจากท่านั่ง',   'F05 Fall from Sitting', 'fall'),

-- ประเภท 2 กิจวัตรประจำวัน (ADL) — 12 / 15 / 25 วินาที
('adl_stand',       'D01 การยืนปกติ',          'D01 Standing',              'adl'),
('adl_sit',         'D02 การนั่งปกติ',          'D02 Sitting',               'adl'),
('adl_walk',        'D03 การเดินปกติ',         'D03 Walking',               'adl'),
('adl_jog',         'D04 การวิ่งเหยาะ',         'D04 Jogging',               'adl'),
('adl_sit_down',    'D05 การนั่งลงปกติ',        'D05 Normal Sit Down',       'adl'),
('adl_chair_up',    'D06 การลุกขึ้นจากเก้าอี้',  'D06 Stand Up from Chair',   'adl'),
('adl_stairs_up',   'D07 การขึ้นบันได',         'D07 Upstairs',              'adl'),
('adl_stairs_down', 'D08 การลงบันได',          'D08 Downstairs',            'adl'),
('adl_pickup',      'D09 การก้มเก็บของ',        'D09 Pick Up Object',        'adl'),
('adl_change_pose', 'D10 การเปลี่ยนท่าทางปกติ', 'D10 Normal Posture Change', 'adl'),

-- ประเภท 3 คล้ายล้มแต่ไม่ล้ม (Near-Fall) — 12 วินาที
('impact_forward',  'N01 การเกือบล้มไปข้างหน้าแต่ทรงตัวได้', 'N01 Near Forward Fall',          'other'),
('impact_backward', 'N02 การเกือบล้มไปข้างหลังแต่ทรงตัวได้', 'N02 Near Backward Fall',         'other'),
('impact_left',     'N03 การเกือบล้มไปด้านซ้ายแต่ทรงตัวได้', 'N03 Near Left Fall',             'other'),
('impact_right',    'N04 การเกือบล้มไปด้านขวาแต่ทรงตัวได้',  'N04 Near Right Fall',            'other'),
('impact_stumble',  'N05 การสะดุดแต่ไม่ล้ม',                 'N05 Stumble Without Fall',       'other'),
('impact_hard_sit', 'N06 การนั่งลงแรงแต่ไม่ล้ม',             'N06 Hard Sit Without Fall',      'other'),
('impact_stop',     'N07 การหยุดเดินหรือวิ่งกะทันหัน',        'N07 Sudden Stop',                'other'),
('impact_device',   'N08 อุปกรณ์ถูกกระแทกแต่ผู้สวมใส่ไม่ล้ม', 'N08 Device Impact Without Fall', 'other'),

-- ประเภท 4 ทดสอบระบบ (Test / Exclude) — 12 วินาที
('exclude_device', 'T01 การทดสอบอุปกรณ์',                      'T01 Device Test',         'other'),
('exclude_wifi',   'T02 การทดสอบระบบเครือข่าย (Wi-Fi / Server)', 'T02 Wi-Fi / Server Test', 'other'),
('exclude_sensor', 'T03 การทดสอบเซนเซอร์',                     'T03 Sensor Test',         'other'),
('exclude_error',  'T04 ข้อมูลผิดพลาด (ไม่ใช้เทรนโมเดล)',      'T04 Invalid Data',        'other')
ON DUPLICATE KEY UPDATE
    name_th = VALUES(name_th),
    name_en = VALUES(name_en),
    category = VALUES(category);
