<?php
/*
 * config/db.php — PDO MySQL Connection + Schema
 */

require_once __DIR__ . '/env.php';

date_default_timezone_set('Asia/Bangkok');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = getenv('MYSQL_HOST') ?: 'localhost';
    $port = getenv('MYSQL_PORT') ?: '3306';
    $user = getenv('MYSQL_USER') ?: 'thebesti_imu';
    $pass = getenv('MYSQL_PASSWORD') ?: '5HgL4wSlos#@7fsk';
    $db   = getenv('MYSQL_DATABASE') ?: 'thebesti_imu';

    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+07:00'");
        createTables($pdo);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
        exit;
    }
}

function createTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fall_events (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        device_id    VARCHAR(64)  NOT NULL DEFAULT 'ESP32_001',
        fall_type    VARCHAR(32)  NOT NULL DEFAULT 'fall_general',
        fall_type_name VARCHAR(64) NOT NULL DEFAULT 'การล้มทั่วไป',
        confidence   FLOAT        NOT NULL DEFAULT 0,
        severity     VARCHAR(16)  NOT NULL DEFAULT 'medium',
        severity_score FLOAT      NOT NULL DEFAULT 0,
        acceleration_x FLOAT,
        acceleration_y FLOAT,
        acceleration_z FLOAT,
        lat          DOUBLE,
        lng          DOUBLE,
        address      VARCHAR(255),
        assisted     TINYINT(1)   NOT NULL DEFAULT 0,
        assisted_at  DATETIME     NULL,
        offline_recorded TINYINT(1) NOT NULL DEFAULT 0,
        is_confirmed TINYINT(1)   NOT NULL DEFAULT 1,
        notified     TINYINT(1)   NOT NULL DEFAULT 0,
        timestamp    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_device (device_id),
        INDEX idx_timestamp (timestamp),
        INDEX idx_assisted (assisted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $cols = $pdo->query("DESCRIBE fall_events")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fall_type', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN fall_type VARCHAR(32) NOT NULL DEFAULT 'fall_general'");
        }
        if (!in_array('fall_type_name', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN fall_type_name VARCHAR(64) NOT NULL DEFAULT 'การล้มทั่วไป'");
        }
        if (!in_array('confidence', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN confidence FLOAT NOT NULL DEFAULT 0.0");
        }
        if (!in_array('assisted', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN assisted TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('assisted_at', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN assisted_at DATETIME NULL");
        }
        if (!in_array('offline_recorded', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN offline_recorded TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('location_source', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN location_source VARCHAR(16) NOT NULL DEFAULT 'gps'");
        }
    } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS gps_log (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        device_id     VARCHAR(64)  NOT NULL DEFAULT 'ESP32_001',
        lat           DOUBLE       NOT NULL,
        lng           DOUBLE       NOT NULL,
        speed_kmh     FLOAT        NOT NULL DEFAULT 0,
        altitude_m    FLOAT,
        satellites    TINYINT,
        hdop          FLOAT,
        location_source VARCHAR(16) NOT NULL DEFAULT 'gps',
        step_count    INT          NOT NULL DEFAULT 0,
        walk_distance FLOAT        NOT NULL DEFAULT 0,
        timestamp     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_device (device_id),
        INDEX idx_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $colsGps = $pdo->query("DESCRIBE gps_log")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('location_source', $colsGps)) {
            $pdo->exec("ALTER TABLE gps_log ADD COLUMN location_source VARCHAR(16) NOT NULL DEFAULT 'gps'");
        }
    } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS wifi_locations (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        bssid          VARCHAR(32)  NOT NULL UNIQUE,
        ssid           VARCHAR(64),
        lat            DOUBLE       NOT NULL,
        lng            DOUBLE       NOT NULL,
        accuracy_m     FLOAT        NOT NULL DEFAULT 25.0,
        label          VARCHAR(128) NOT NULL DEFAULT 'บ้านผู้ใช้งาน (Home)',
        updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_bssid (bssid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_summary (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        device_id      VARCHAR(64) NOT NULL DEFAULT 'ESP32_001',
        summary_date   DATE        NOT NULL,
        total_steps    INT         NOT NULL DEFAULT 0,
        total_distance FLOAT       NOT NULL DEFAULT 0,
        fall_count     INT         NOT NULL DEFAULT 0,
        active_minutes INT         NOT NULL DEFAULT 0,
        UNIQUE KEY uq_device_date (device_id, summary_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        api_key    VARCHAR(64)  NOT NULL UNIQUE,
        device_id  VARCHAR(64)  NOT NULL,
        label      VARCHAR(128),
        is_active  TINYINT(1)   NOT NULL DEFAULT 1,
        last_used  DATETIME,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key (api_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
