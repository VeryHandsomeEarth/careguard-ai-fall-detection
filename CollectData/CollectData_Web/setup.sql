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
-- เพิ่มประเภทการล้มเริ่มต้น (10 ประเภท)
-- =============================================

INSERT IGNORE INTO cd_fall_types (code, name_th, name_en, category) VALUES
('fall_forward',  'ล้มไปข้างหน้า',    'Fall Forward',     'fall'),
('fall_backward', 'ล้มไปข้างหลัง',    'Fall Backward',    'fall'),
('fall_lateral',  'ล้มไปด้านข้าง',    'Lateral Fall',     'fall'),
('fall_sitting',  'ล้มจากท่านั่ง',    'Fall from Sitting', 'fall'),
('near_fall',     'เกือบล้ม',         'Near Fall',        'fall'),
('adl_walk',      'เดิน',             'Walking',          'adl'),
('adl_jog',       'วิ่งเหยาะ',        'Jogging',          'adl'),
('adl_sit',       'นั่ง',             'Sitting',          'adl'),
('adl_stand',     'ยืน',              'Standing',         'adl'),
('adl_stairs',    'ขึ้นลงบันได',      'Stairs',           'adl');
