-- =============================================
-- SoftwarePHP — สร้างตารางฐานข้อมูล (สำหรับระบบตรวจจับการล้มด้วย GPS และ AI)
-- ฐานข้อมูล: thebesti_imu
-- เรียกใช้: import ผ่าน phpMyAdmin หรือ mysql CLI
-- =============================================

-- ตาราง fall_events — บันทึกเหตุการณ์การล้ม
CREATE TABLE IF NOT EXISTS fall_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001' COMMENT 'ไอดีอุปกรณ์',
    fall_type VARCHAR(32) NOT NULL DEFAULT 'fall_general' COMMENT 'รหัสประเภทการล้ม (forward, backward, lateral_left, lateral_right, vertical, general)',
    fall_type_name VARCHAR(64) NOT NULL DEFAULT 'การล้มทั่วไป' COMMENT 'ชื่อประเภทการล้มภาษาไทย',
    confidence FLOAT NOT NULL DEFAULT 0.0 COMMENT 'ค่าความมั่นใจของโมเดล TinyML (0.0 - 1.0)',
    severity VARCHAR(16) NOT NULL DEFAULT 'medium' COMMENT 'ระดับความรุนแรง (low, medium, high)',
    severity_score FLOAT NOT NULL DEFAULT 0 COMMENT 'คะแนนความรุนแรง',
    acceleration_x FLOAT COMMENT 'ความเร่งแกน X',
    acceleration_y FLOAT COMMENT 'ความเร่งแกน Y',
    acceleration_z FLOAT COMMENT 'ความเร่งแกน Z',
    lat DOUBLE COMMENT 'ละติจูด',
    lng DOUBLE COMMENT 'ลองจิจูด',
    address VARCHAR(255) COMMENT 'ที่อยู่โดยประมาณ',
    assisted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ยืนยันการช่วยเหลือแล้ว (1=ช่วยเหลือแล้วจากปุ่ม D18 หรือเว็บ, 0=ยังไม่ช่วยเหลือ)',
    assisted_at DATETIME NULL COMMENT 'วันเวลาที่ได้รับการช่วยเหลือ',
    offline_recorded TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'บันทึกในโหมดออฟไลน์แล้วส่งย้อนหลัง (1=ใช่, 0=สด)',
    is_confirmed TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'ยืนยันการล้ม (1=จริง, 0=ล้มหลอก)',
    notified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'แจ้งเตือนแล้วหรือไม่',
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_assisted (assisted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง gps_log — บันทึกตำแหน่ง GPS และข้อมูลการนับก้าวแบบเรียลไทม์
CREATE TABLE IF NOT EXISTS gps_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001' COMMENT 'ไอดีอุปกรณ์',
    lat DOUBLE NOT NULL COMMENT 'ละติจูด',
    lng DOUBLE NOT NULL COMMENT 'ลองจิจูด',
    speed_kmh FLOAT NOT NULL DEFAULT 0 COMMENT 'ความเร็ว (กม./ชม.)',
    altitude_m FLOAT COMMENT 'ความสูงจากระดับน้ำทะเล',
    satellites TINYINT COMMENT 'จำนวนดาวเทียม',
    hdop FLOAT COMMENT 'ค่าความแม่นยำ GPS (HDOP)',
    step_count INT NOT NULL DEFAULT 0 COMMENT 'จำนวนก้าวสะสม',
    walk_distance FLOAT NOT NULL DEFAULT 0 COMMENT 'ระยะทางสะสม (เมตร)',
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง daily_summary — บันทึกสรุปรายวันรายอุปกรณ์
CREATE TABLE IF NOT EXISTS daily_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001' COMMENT 'ไอดีอุปกรณ์',
    summary_date DATE NOT NULL COMMENT 'วันที่สรุปผล',
    total_steps INT NOT NULL DEFAULT 0 COMMENT 'ก้าวเดินรวม',
    total_distance FLOAT NOT NULL DEFAULT 0 COMMENT 'ระยะทางรวม',
    fall_count INT NOT NULL DEFAULT 0 COMMENT 'จำนวนการล้มรวม',
    active_minutes INT NOT NULL DEFAULT 0 COMMENT 'เวลากิจกรรม (นาที)',
    UNIQUE KEY uq_device_date (device_id, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง chat_history — ประวัติแชทกับ AI
CREATE TABLE IF NOT EXISTS chat_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL COMMENT 'ไอดีห้องแชท',
    role ENUM('user','assistant') NOT NULL COMMENT 'ผู้ส่งข้อมูล',
    content TEXT NOT NULL COMMENT 'ข้อความแชท',
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ตาราง api_keys — คีย์ความปลอดภัยสำหรับเชื่อมต่อฮาร์ดแวร์
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'คีย์ API',
    device_id VARCHAR(64) NOT NULL COMMENT 'ผูกกับไอดีอุปกรณ์',
    label VARCHAR(128) COMMENT 'ป้ายชื่อคีย์',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะคีย์',
    last_used DATETIME COMMENT 'ใช้งานล่าสุด',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- เพิ่ม API Key เริ่มต้นสำหรับบอร์ด Hardware1
-- =============================================
INSERT IGNORE INTO api_keys (api_key, device_id, label) VALUES 
('hw1-8931932eb8a233006062ebdb0651eb74', 'ESP32_001', 'Hardware1 Default Key');
