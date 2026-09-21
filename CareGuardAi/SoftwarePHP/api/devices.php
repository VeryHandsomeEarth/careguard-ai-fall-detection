<?php
/*
 * api/devices.php — Device & MAC Address Binding API
 * GET    /api/devices         — รายการอุปกรณ์ของผู้ใช้ (หรือทั้งหมดถ้าเป็น admin)
 * POST   /api/devices         — ผูกอุปกรณ์ใหม่ด้วย mac_address
 * DELETE /api/devices?id=X    — ลบการผูกอุปกรณ์
 */

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getDB();

    // สร้างตาราง user_devices ถ้ายังไม่มี
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_id VARCHAR(64) NOT NULL DEFAULT 'ESP32_001',
        mac_address VARCHAR(32) NOT NULL,
        device_name VARCHAR(128) NOT NULL DEFAULT 'CareGuard Device',
        device_type ENUM('hw1_gps', 'hw2_wifi', 'auto') NOT NULL DEFAULT 'auto',
        is_primary TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_mac (mac_address),
        INDEX idx_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ซิงค์และแก้ไขข้อมูลเดิมที่ผูกไว้สำหรับ Hardware 2
    $pdo->exec("UPDATE user_devices 
                SET device_id = 'ESP32_HW2' 
                WHERE (device_type = 'hw2_wifi' OR device_name LIKE '%HW2%' OR device_name LIKE '%Hardware2%' OR device_name LIKE '%Hardware 2%') 
                  AND device_id = 'ESP32_001'");

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['user']['role'] ?? 'user';

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'กรุณาเข้าสู่ระบบก่อนทำรายการ']);
    exit;
}

// ==================== GET — ดึงรายการอุปกรณ์ ====================
if ($method === 'GET') {
    try {
        if ($user_role === 'admin') {
            // โรล admin: เห็นอุปกรณ์ทุกตัวในระบบ พร้อมชื่อเจ้าของ
            $stmt = $pdo->query("SELECT d.*, u.username, u.display_name 
                                 FROM user_devices d 
                                 LEFT JOIN users u ON d.user_id = u.id 
                                 ORDER BY d.created_at DESC");
            $devices = $stmt->fetchAll();
        } else {
            // โรล ผู้ใช้ทั่วไป: เห็นเฉพาะอุปกรณ์ที่เชื่อมต่อของตัวเองเท่านั้น
            $stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id = ? ORDER BY is_primary DESC, id ASC");
            $stmt->execute([$user_id]);
            $devices = $stmt->fetchAll();
        }

        echo json_encode(['success' => true, 'data' => $devices, 'role' => $user_role]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ==================== POST — ผูกอุปกรณ์ใหม่ (MAC Address) ====================
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $mac  = strtoupper(trim($body['mac_address'] ?? ''));
    $name = sanitize($body['device_name'] ?? 'บอร์ด ESP32');
    $type = sanitize($body['device_type'] ?? 'auto');
    $devId = sanitize($body['device_id'] ?? '');

    if (!preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', $mac)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'รูปแบบ MAC Address ไม่ถูกต้อง (เช่น 24:6F:28:1A:2B:3C)']);
        exit;
    }

    $mac = str_replace('-', ':', $mac);

    // กำหนด device_id ให้ถูกต้องตามประเภทอุปกรณ์และชื่อ
    if (empty($devId) || $devId === 'ESP32_001') {
        if ($type === 'hw2_wifi' || stripos($name, 'hw2') !== false || stripos($name, 'hardware2') !== false || stripos($name, 'hardware 2') !== false) {
            $devId = 'ESP32_HW2';
        } else if ($type === 'hw1_gps' || stripos($name, 'hw1') !== false || stripos($name, 'hardware1') !== false || stripos($name, 'hardware 1') !== false) {
            $devId = 'ESP32_001';
        } else {
            $devId = ($type === 'hw2_wifi') ? 'ESP32_HW2' : 'ESP32_001';
        }
    }

    $isPrimary = !empty($body['is_primary']) ? 1 : 0;
    // หากยังไม่มีอุปกรณ์ ให้เป็นอุปกรณ์หลัก (Primary) ทันที
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM user_devices WHERE user_id = ?");
    $cntStmt->execute([$user_id]);
    if ($cntStmt->fetchColumn() == 0) {
        $isPrimary = 1;
    }
    if ($isPrimary == 1) {
        $pdo->prepare("UPDATE user_devices SET is_primary = 0 WHERE user_id = ?")->execute([$user_id]);
    }

    try {
        // ตรวจสอบว่า MAC นี้ผูกอยู่แล้วหรือไม่
        $check = $pdo->prepare("SELECT id FROM user_devices WHERE user_id = ? AND mac_address = ? LIMIT 1");
        $check->execute([$user_id, $mac]);
        if ($check->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'MAC Address นี้ถูกผูกไว้ในบัญชีของคุณแล้ว']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO user_devices (user_id, device_id, mac_address, device_name, device_type, is_primary) 
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $devId, $mac, $name, $type, $isPrimary]);

        echo json_encode(['success' => true, 'message' => 'ผูกอุปกรณ์สำเร็จ', 'id' => $pdo->lastInsertId(), 'device_id' => $devId]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
        exit;
    }
}

// ==================== DELETE — ลบการผูกอุปกรณ์ ====================
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ต้องระบุ ID อุปกรณ์']);
        exit;
    }

    try {
        if ($user_role === 'admin') {
            $stmt = $pdo->prepare("DELETE FROM user_devices WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM user_devices WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
        }
        echo json_encode(['success' => true, 'message' => 'ลบอุปกรณ์เรียบร้อยแล้ว']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
