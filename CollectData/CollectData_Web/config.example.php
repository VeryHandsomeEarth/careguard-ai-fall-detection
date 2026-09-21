<?php
/**
 * การตั้งค่าการเชื่อมต่อฐานข้อมูล (Configuration Template)
 * CollectData — ระบบเก็บข้อมูลการทดลองสำหรับ TinyML
 */

// ตั้งค่าเข้ารหัสเป็น UTF-8
header('Content-Type: text/html; charset=utf-8');

// ข้อมูลฐานข้อมูล MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'thebesti_imu');
define('DB_USER', 'thebesti_imu');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

// ฟังก์ชันเชื่อมต่อฐานข้อมูล
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("เชื่อมต่อฐานข้อมูลไม่สำเร็จ: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Base URL สำหรับลิงก์
define('BASE_URL', '/IMU/CollectData_Web');
?>
