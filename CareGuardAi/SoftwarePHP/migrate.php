<?php
/*
 * migrate.php — ลบตารางเก่า สร้างใหม่ (รันครั้งเดียว แล้วลบ)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/env.php';

$host = getenv('MYSQL_HOST') ?: 'localhost';
$port = getenv('MYSQL_PORT') ?: '3306';
$user = getenv('MYSQL_USER') ?: 'thebesti_imu';
$pass = getenv('MYSQL_PASSWORD') ?: '5HgL4wSlos#@7fsk';
$db   = getenv('MYSQL_DATABASE') ?: 'thebesti_imu';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$results = [];

// 1. Drop ตารางเก่าที่ชื่อซ้ำ
$drops = ['fall_events', 'gps_log', 'daily_summary', 'chat_history', 'api_keys'];
foreach ($drops as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
    $results[] = "Dropped $t";
}

// 2. สร้างใหม่
$pdo->exec("CREATE TABLE fall_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001',
    fall_type VARCHAR(32) NOT NULL DEFAULT 'fall_general',
    fall_type_name VARCHAR(64) NOT NULL DEFAULT 'การล้มทั่วไป',
    confidence FLOAT NOT NULL DEFAULT 0.0,
    severity VARCHAR(16) NOT NULL DEFAULT 'medium',
    severity_score FLOAT NOT NULL DEFAULT 0,
    acceleration_x FLOAT, acceleration_y FLOAT, acceleration_z FLOAT,
    lat DOUBLE, lng DOUBLE, address VARCHAR(255),
    assisted TINYINT(1) NOT NULL DEFAULT 0,
    assisted_at DATETIME NULL,
    offline_recorded TINYINT(1) NOT NULL DEFAULT 0,
    is_confirmed TINYINT(1) NOT NULL DEFAULT 1,
    notified TINYINT(1) NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_id), INDEX idx_timestamp (timestamp), INDEX idx_assisted (assisted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$results[] = "Created fall_events with full Hardware1 v6 schema ✅";

$pdo->exec("CREATE TABLE gps_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001',
    lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
    speed_kmh FLOAT NOT NULL DEFAULT 0,
    altitude_m FLOAT, satellites TINYINT, hdop FLOAT,
    step_count INT NOT NULL DEFAULT 0,
    walk_distance FLOAT NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_id), INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$results[] = "Created gps_log ✅";

$pdo->exec("CREATE TABLE daily_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001',
    summary_date DATE NOT NULL,
    total_steps INT NOT NULL DEFAULT 0,
    total_distance FLOAT NOT NULL DEFAULT 0,
    fall_count INT NOT NULL DEFAULT 0,
    active_minutes INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_device_date (device_id, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$results[] = "Created daily_summary ✅";

$pdo->exec("CREATE TABLE chat_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$results[] = "Created chat_history ✅";

$pdo->exec("CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    device_id VARCHAR(64) NOT NULL,
    label VARCHAR(128),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_used DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$results[] = "Created api_keys ✅";

// 3. สร้าง API Key
$key = 'hw1-8931932eb8a233006062ebdb0651eb74';
$pdo->prepare("INSERT INTO api_keys (api_key, device_id, label) VALUES (?, 'ESP32_001', 'Hardware1')")
    ->execute([$key]);
$results[] = "API Key: $key ✅";

// 4. ตรวจสอบ
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$cols   = $pdo->query("DESCRIBE fall_events")->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'success' => true,
    'results' => $results,
    'all_tables' => $tables,
    'fall_events_columns' => $cols,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
