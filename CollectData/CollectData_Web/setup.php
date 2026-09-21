<?php
/**
 * สร้างตารางฐานข้อมูลและข้อมูลเริ่มต้น
 * เรียกครั้งเดียวเพื่อ setup ระบบ
 * URL: https://www.youngza.com/IMU/CollectData_Web/setup.php
 */

require_once 'config.php';
$pdo = getDB();

$messages = [];

try {
    // ==================== สร้างตาราง ====================

    // ตาราง cd_devices — อุปกรณ์ ESP32
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cd_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            mac_address VARCHAR(17),
            description VARCHAR(200),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "✅ สร้างตาราง cd_devices สำเร็จ";

    // ตาราง cd_subjects — ผู้ทดสอบ
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cd_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            age INT,
            height_cm FLOAT,
            weight_kg FLOAT,
            gender ENUM('M','F','O') DEFAULT 'O',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "✅ สร้างตาราง cd_subjects สำเร็จ";

    // ตาราง cd_fall_types — ประเภทการล้ม (เพิ่มได้)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cd_fall_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            name_th VARCHAR(100) NOT NULL,
            name_en VARCHAR(100),
            category ENUM('fall','adl','other') DEFAULT 'other',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "✅ สร้างตาราง cd_fall_types สำเร็จ";

    // ตาราง cd_trial — รอบการทดลอง
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cd_trial (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            trial_label VARCHAR(50),
            description TEXT,
            trial_date DATE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subject_id) REFERENCES cd_subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "✅ สร้างตาราง cd_trial สำเร็จ";

    // ตาราง cd_records — บันทึกการล้มแต่ละครั้ง
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cd_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            device_id INT NOT NULL,
            fall_type_id INT NOT NULL,
            trial_id INT,
            trial_number INT DEFAULT 1,
            notes TEXT,
            status ENUM('recording','completed','cancelled') DEFAULT 'completed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subject_id) REFERENCES cd_subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (device_id) REFERENCES cd_devices(id) ON DELETE CASCADE,
            FOREIGN KEY (fall_type_id) REFERENCES cd_fall_types(id) ON DELETE CASCADE,
            FOREIGN KEY (trial_id) REFERENCES cd_trial(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "✅ สร้างตาราง cd_records สำเร็จ";

    // ตาราง cd_sensor_data — ข้อมูลเซ็นเซอร์ดิบ
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cd_sensor_data (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            record_id INT NOT NULL,
            timestamp_ms BIGINT NOT NULL,
            accel_x FLOAT,
            accel_y FLOAT,
            accel_z FLOAT,
            gyro_x FLOAT,
            gyro_y FLOAT,
            gyro_z FLOAT,
            FOREIGN KEY (record_id) REFERENCES cd_records(id) ON DELETE CASCADE,
            INDEX idx_record_time (record_id, timestamp_ms)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = "✅ สร้างตาราง cd_sensor_data สำเร็จ";

    // ==================== เพิ่มประเภทการล้มเริ่มต้น ====================
    $defaultTypes = [
        ['fall_forward',  'ล้มไปข้างหน้า',    'Fall Forward',    'fall'],
        ['fall_backward', 'ล้มไปข้างหลัง',    'Fall Backward',   'fall'],
        ['fall_lateral',  'ล้มไปด้านข้าง',    'Lateral Fall',    'fall'],
        ['fall_sitting',  'ล้มจากท่านั่ง',    'Fall from Sitting','fall'],
        ['near_fall',     'เกือบล้ม',         'Near Fall',       'fall'],
        ['adl_walk',      'เดิน',             'Walking',         'adl'],
        ['adl_jog',       'วิ่งเหยาะ',        'Jogging',         'adl'],
        ['adl_sit',       'นั่ง',             'Sitting',         'adl'],
        ['adl_stand',     'ยืน',              'Standing',        'adl'],
        ['adl_stairs',    'ขึ้นลงบันได',      'Stairs',          'adl'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO cd_fall_types (code, name_th, name_en, category)
        VALUES (?, ?, ?, ?)
    ");
    $inserted = 0;
    foreach ($defaultTypes as $type) {
        $stmt->execute($type);
        if ($stmt->rowCount() > 0) $inserted++;
    }
    $messages[] = "✅ เพิ่มประเภทการล้มเริ่มต้น {$inserted} รายการ";

    $messages[] = "\n🎉 ติดตั้งเสร็จสมบูรณ์!";

} catch (PDOException $e) {
    $messages[] = "❌ ข้อผิดพลาด: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Setup — CollectData</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #16213e; padding: 30px; border-radius: 12px; }
        h1 { color: #00d2ff; }
        .msg { padding: 8px 0; font-size: 15px; }
        a { color: #00d2ff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛠️ Setup CollectData</h1>
        <?php foreach ($messages as $msg): ?>
            <div class="msg"><?= $msg ?></div>
        <?php endforeach; ?>
        <br>
        <a href="index.php">→ ไปหน้าหลัก</a>
    </div>
</body>
</html>
