<?php
/*
 * api/profile.php — User Profile API
 * GET  /api/profile   — ดึงข้อมูลผู้ใช้
 * POST /api/profile   — บันทึก/อัปเดตข้อมูลผู้ใช้
 */

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getDB();

    // สร้างตาราง user_profile ถ้ายังไม่มี
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        name VARCHAR(128) NOT NULL DEFAULT '',
        email VARCHAR(128) NOT NULL DEFAULT '',
        phone VARCHAR(32) NOT NULL DEFAULT '',
        avatar VARCHAR(32) NOT NULL DEFAULT '👤',
        age INT DEFAULT 0,
        weight FLOAT DEFAULT 0,
        height FLOAT DEFAULT 0,
        gender VARCHAR(16) DEFAULT '',
        health_conditions TEXT,
        step_goal INT NOT NULL DEFAULT 10000,
        distance_goal FLOAT NOT NULL DEFAULT 5000,
        emergency_contact VARCHAR(64) DEFAULT '',
        emergency_name VARCHAR(128) DEFAULT '',
        notes TEXT,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $cols = $pdo->query("DESCRIBE user_profile")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('email', $cols)) $pdo->exec("ALTER TABLE user_profile ADD COLUMN email VARCHAR(128) NOT NULL DEFAULT ''");
        if (!in_array('phone', $cols)) $pdo->exec("ALTER TABLE user_profile ADD COLUMN phone VARCHAR(32) NOT NULL DEFAULT ''");
        if (!in_array('avatar', $cols)) $pdo->exec("ALTER TABLE user_profile ADD COLUMN avatar VARCHAR(32) NOT NULL DEFAULT '👤'");
        if (!in_array('emergency_email', $cols)) $pdo->exec("ALTER TABLE user_profile ADD COLUMN emergency_email VARCHAR(128) NOT NULL DEFAULT ''");
        if (!in_array('emergency_phone', $cols)) $pdo->exec("ALTER TABLE user_profile ADD COLUMN emergency_phone VARCHAR(32) NOT NULL DEFAULT ''");
        if (!in_array('telegram_chat_id', $cols)) $pdo->exec("ALTER TABLE user_profile ADD COLUMN telegram_chat_id VARCHAR(64) NOT NULL DEFAULT ''");
    } catch (Exception $e) {}

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
}

$user_id = $_SESSION['user_id'] ?? 1; // default user_id 1 if not set
$username = $_SESSION['user']['username'] ?? 'ผู้ใช้ทั่วไป';
$user_role = $_SESSION['user']['role'] ?? null;

if (!$user_role) {
    try {
        $uStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$user_id]);
        $user_role = $uStmt->fetchColumn() ?: 'user';
    } catch (Exception $e) {
        $user_role = 'user';
    }
}

// ==================== GET — ดึงข้อมูลผู้ใช้ ====================
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare("INSERT INTO user_profile (user_id, name, email, phone, avatar) VALUES (?, ?, 'user@careguard.local', '0812345678', '👤') ON DUPLICATE KEY UPDATE user_id=user_id")
            ->execute([$user_id, $username]);
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
    }
    echo json_encode(['success' => true, 'data' => $row, 'username' => $username, 'role' => $user_role]);
    exit;
}

// ==================== POST — บันทึกข้อมูลผู้ใช้ ====================
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $email = trim($body['email'] ?? '');
    $phone = trim($body['phone'] ?? ($body['emergency_contact'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกอีเมลที่ถูกต้อง (จำเป็นสำหรับการแจ้งเตือนฉุกเฉิน)']);
        exit;
    }
    if (empty($phone) || strlen($phone) < 9) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกเบอร์โทรศัพท์ติดต่อ (จำเป็นสำหรับการติดต่อทันที)']);
        exit;
    }

    $emerEmail = trim($body['emergency_email'] ?? '');
    if (!empty($emerEmail) && !filter_var($emerEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกอีเมลญาติ/ผู้ดูแลให้ถูกต้อง']);
        exit;
    }

    $emerPhone = trim($body['emergency_phone'] ?? ($body['emergency_contact'] ?? ''));

    $fields = [
        'name'              => sanitize($body['name'] ?? ''),
        'email'             => sanitize($email),
        'phone'             => sanitize($phone),
        'avatar'            => sanitize($body['avatar'] ?? '👤'),
        'age'               => (int)($body['age'] ?? 0),
        'weight'            => (float)($body['weight'] ?? 0),
        'height'            => (float)($body['height'] ?? 0),
        'gender'            => sanitize($body['gender'] ?? ''),
        'health_conditions' => sanitize($body['health_conditions'] ?? ''),
        'step_goal'         => max(1000, (int)($body['step_goal'] ?? 10000)),
        'distance_goal'     => max(100, (float)($body['distance_goal'] ?? 5000)),
        'emergency_contact' => sanitize($emerPhone ?: $phone),
        'emergency_phone'   => sanitize($emerPhone),
        'emergency_email'   => sanitize($emerEmail),
        'emergency_name'    => sanitize($body['emergency_name'] ?? ''),
        'telegram_chat_id'  => sanitize($body['telegram_chat_id'] ?? ''),
        'notes'             => sanitize($body['notes'] ?? ''),
    ];

    $stmt = $pdo->prepare("SELECT id FROM user_profile WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $user_id;
        $pdo->prepare("UPDATE user_profile SET " . implode(', ', $sets) . " WHERE user_id = ?")->execute($vals);
    } else {
        $cols = 'user_id, ' . implode(', ', array_keys($fields));
        $ph   = '?, ' . implode(', ', array_fill(0, count($fields), '?'));
        $vals = array_merge([$user_id], array_values($fields));
        $pdo->prepare("INSERT INTO user_profile ($cols) VALUES ($ph)")->execute($vals);
    }

    echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลแล้ว']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
