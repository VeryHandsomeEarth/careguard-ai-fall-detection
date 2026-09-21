<?php
/*
 * api/auth.php — Login / Logout / Register API
 * POST /api/auth/login    — เข้าสู่ระบบ
 * POST /api/auth/logout   — ออกจากระบบ
 * GET  /api/auth/me       — ข้อมูลผู้ใช้ปัจจุบัน
 */

session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', $uri)));
$action = $parts[2] ?? '';

try {
    $pdo = getDB();

    // สร้างตาราง users ถ้ายังไม่มี
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        display_name VARCHAR(128) NOT NULL DEFAULT '',
        role ENUM('admin','user') NOT NULL DEFAULT 'user',
        avatar VARCHAR(16) DEFAULT '👤',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // สร้าง admin default ถ้ายังไม่มี
    $exists = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
    if (!$exists) {
        $hash = password_hash('admin1234', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, display_name, role, avatar) VALUES (?, ?, ?, 'admin', '👨‍💼')")
            ->execute(['admin', $hash, 'ผู้ดูแลระบบ']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
}

// ==================== POST /api/auth/register ====================
if ($method === 'POST' && $action === 'register') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || strlen($password) < 4) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ต้องใส่ชื่อผู้ใช้และรหัสผ่านอย่างน้อย 4 ตัว']); exit;
    }

    $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $exists->execute([$username]);
    if ($exists->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ชื่อผู้ใช้นี้มีคนใช้แล้ว']); exit;
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)")->execute([$username, $hash]);
        $userId = $pdo->lastInsertId();

        // สร้างโปรไฟล์เบื้องต้นให้
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_profile (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, name VARCHAR(128) DEFAULT '', age INT DEFAULT 0,
            weight FLOAT DEFAULT 0, height FLOAT DEFAULT 0, gender VARCHAR(16) DEFAULT '', health_conditions TEXT,
            step_goal INT DEFAULT 10000, distance_goal FLOAT DEFAULT 5000, emergency_contact VARCHAR(64) DEFAULT '',
            emergency_name VARCHAR(128) DEFAULT '', notes TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->prepare("INSERT INTO user_profile (user_id, name) VALUES (?, ?)")->execute([$userId, $username]);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user'] = ['id' => $userId, 'username' => $username, 'display_name' => '', 'role' => 'user', 'avatar' => '👤'];
        echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['success' => false, 'error' => 'สร้างบัญชีไม่สำเร็จ']);
    }
    exit;
}

// ==================== POST /api/auth/login ====================
if ($method === 'POST' && $action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน']); exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']); exit;
    }

    // อัปเดต last_login
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // ตั้ง session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = [
        'id'           => $user['id'],
        'username'     => $user['username'],
        'display_name' => $user['display_name'],
        'role'         => $user['role'],
        'avatar'       => $user['avatar'],
    ];

    echo json_encode(['success' => true, 'user' => $_SESSION['user']]);
    exit;
}

// ==================== POST /api/auth/logout ====================
if ($method === 'POST' && $action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'ออกจากระบบแล้ว']);
    exit;
}

// ==================== GET /api/auth/me ====================
if ($method === 'GET' && $action === 'me') {
    if (!empty($_SESSION['user_id'])) {
        echo json_encode(['success' => true, 'loggedIn' => true, 'user' => $_SESSION['user']]);
    } else {
        echo json_encode(['success' => true, 'loggedIn' => false]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Unknown auth action']);
