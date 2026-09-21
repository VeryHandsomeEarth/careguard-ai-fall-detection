<?php
/*
 * api/admin.php — CareGuard Admin Management API
 * เข้าถึงได้เฉพาะผู้ใช้ที่มีสิทธิ์ admin เท่านั้น
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบสิทธิ์ Admin อย่างเคร่งครัด
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentUserRole = $_SESSION['user']['role'] ?? '';

if (!$currentUserId || $currentUserRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$body   = in_array($method, ['POST', 'PUT', 'DELETE']) ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$uri    = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', $uri)));

// ค้นหาตำแหน่ง admin หรือ admin.php ใน URL ป้องกันปัญหากรณีรันบน Sub-directory เช่น /IMU/SoftwarePHP/
$adminIdx = array_search('admin', $parts);
if ($adminIdx === false) {
    $adminIdx = array_search('admin.php', $parts);
}

if ($adminIdx !== false && isset($parts[$adminIdx + 1])) {
    $section = $parts[$adminIdx + 1];
    $sub     = $parts[$adminIdx + 2] ?? ($_GET['sub'] ?? null);
} else {
    $section = $_GET['section'] ?? ($parts[2] ?? 'overview');
    $sub     = $_GET['sub'] ?? ($parts[3] ?? null);
}

$pdo = getDB();

// ==================== 1. สรุปภาพรวม (Overview KPI) ====================
if ($method === 'GET' && ($section === 'overview' || $section === '')) {
    try {
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalDevices = (int)$pdo->query("SELECT COUNT(*) FROM user_devices")->fetchColumn();
        
        $stmtFallsToday = $pdo->query("SELECT 
            COUNT(*) AS total_today,
            COALESCE(SUM(CASE WHEN assisted = 1 THEN 1 ELSE 0 END), 0) AS assisted_today,
            COALESCE(SUM(CASE WHEN assisted = 0 THEN 1 ELSE 0 END), 0) AS pending_today
            FROM fall_events 
            WHERE (DATE(timestamp) = CURDATE() OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = CURDATE())");
        $fallsToday = $stmtFallsToday->fetch(PDO::FETCH_ASSOC);

        $totalFallsAllTime = (int)$pdo->query("SELECT COUNT(*) FROM fall_events")->fetchColumn();

        // ตรวจสอบสถานะออนไลน์ของฮาร์ดแวร์ (ส่งสัญญาณภายใน 20 วินาทีล่าสุด)
        $hw1Check = $pdo->query("SELECT timestamp FROM gps_log WHERE device_id = 'ESP32_001' ORDER BY timestamp DESC LIMIT 1")->fetch();
        $hw1Online = false;
        if ($hw1Check) {
            $diff1 = time() - strtotime($hw1Check['timestamp']);
            $hw1Online = ($diff1 >= 0 && $diff1 <= 20);
        }

        $hw2Check = $pdo->query("SELECT timestamp FROM gps_log WHERE device_id = 'ESP32_HW2' ORDER BY timestamp DESC LIMIT 1")->fetch();
        $hw2Online = false;
        if ($hw2Check) {
            $diff2 = time() - strtotime($hw2Check['timestamp']);
            $hw2Online = ($diff2 >= 0 && $diff2 <= 20);
        }

        // ล่าสุด 5 เหตุการณ์ล้ม
        $recentFallsStmt = $pdo->query("SELECT f.id, f.device_id, f.fall_type_name, f.severity, f.confidence, f.lat, f.lng, f.assisted, f.timestamp,
                                               d.device_name, u.username, u.display_name
                                        FROM fall_events f
                                        LEFT JOIN user_devices d ON f.device_id = d.device_id
                                        LEFT JOIN users u ON d.user_id = u.id
                                        ORDER BY f.timestamp DESC LIMIT 5");
        $recentFalls = $recentFallsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'total_users'         => $totalUsers,
                'total_devices'       => $totalDevices,
                'falls_today'         => (int)($fallsToday['total_today'] ?? 0),
                'assisted_today'      => (int)($fallsToday['assisted_today'] ?? 0),
                'pending_today'       => (int)($fallsToday['pending_today'] ?? 0),
                'total_falls_all'     => $totalFallsAllTime,
                'hw1_online'          => $hw1Online,
                'hw2_online'          => $hw2Online,
                'recent_falls'        => $recentFalls
            ]
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ==================== 2. จัดการผู้ใช้งาน (Users Management) ====================
if ($section === 'users') {
    if ($method === 'GET') {
        try {
            $stmt = $pdo->query("SELECT u.id, u.username, u.display_name, u.role, u.avatar, u.created_at, u.last_login,
                                        p.name AS profile_name, p.phone, p.emergency_name, p.emergency_phone,
                                        (SELECT COUNT(*) FROM user_devices d WHERE d.user_id = u.id) AS device_count
                                 FROM users u
                                 LEFT JOIN user_profile p ON u.id = p.user_id
                                 ORDER BY u.id ASC");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $users]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? 'create';

        // 2.1 เพิ่มผู้ใช้ใหม่
        if ($action === 'create') {
            $username = trim($body['username'] ?? '');
            $password = $body['password'] ?? '';
            $displayName = trim($body['display_name'] ?? '');
            $role = ($body['role'] === 'admin') ? 'admin' : 'user';

            if (!$username || strlen($password) < 4) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ต้องระบุชื่อผู้ใช้และรหัสผ่านอย่างน้อย 4 ตัวอักษร']);
                exit;
            }

            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว']);
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO users (username, password, display_name, role, avatar) VALUES (?, ?, ?, ?, '👤')");
            $ins->execute([$username, $hash, $displayName ?: $username, $role]);
            $newUserId = $pdo->lastInsertId();

            $pIns = $pdo->prepare("INSERT INTO user_profile (user_id, name) VALUES (?, ?)");
            $pIns->execute([$newUserId, $displayName ?: $username]);

            echo json_encode(['success' => true, 'message' => 'สร้างผู้ใช้ใหม่สำเร็จ', 'id' => $newUserId]);
            exit;
        }

        // 2.2 เปลี่ยนสิทธิ์ (Toggle Role: user <-> admin)
        if ($action === 'update_role') {
            $targetId = (int)($body['user_id'] ?? 0);
            $newRole = ($body['role'] === 'admin') ? 'admin' : 'user';

            if ($targetId === (int)$currentUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ไม่สามารถเปลี่ยนสิทธิ์ของตนเองได้']);
                exit;
            }

            $upd = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $upd->execute([$newRole, $targetId]);
            echo json_encode(['success' => true, 'message' => "เปลี่ยนสิทธิ์เป็น {$newRole} สำเร็จ"]);
            exit;
        }

        // 2.3 รีเซ็ตรหัสผ่านผู้ใช้
        if ($action === 'reset_password') {
            $targetId = (int)($body['user_id'] ?? 0);
            $newPassword = $body['password'] ?? '';

            if (!$targetId || strlen($newPassword) < 4) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย 4 ตัวอักษร']);
                exit;
            }

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upd->execute([$hash, $targetId]);
            echo json_encode(['success' => true, 'message' => 'รีเซ็ตรหัสผ่านสำเร็จ']);
            exit;
        }

        // 2.4 ลบผู้ใช้
        if ($action === 'delete') {
            $targetId = (int)($body['user_id'] ?? 0);
            if ($targetId === (int)$currentUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ไม่สามารถลบบัญชีของตนเองได้']);
                exit;
            }

            $pdo->prepare("DELETE FROM user_profile WHERE user_id = ?")->execute([$targetId]);
            $pdo->prepare("DELETE FROM user_devices WHERE user_id = ?")->execute([$targetId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);

            echo json_encode(['success' => true, 'message' => 'ลบผู้ใช้และข้อมูลที่เกี่ยวข้องเรียบร้อยแล้ว']);
            exit;
        }
    }
}

// ==================== 3. จัดการอุปกรณ์ (Devices Management) ====================
if ($section === 'devices') {
    if ($method === 'GET') {
        try {
            $stmt = $pdo->query("SELECT d.id, d.user_id, d.device_id, d.device_name, d.device_type, d.mac_address, d.is_primary, d.created_at,
                                        u.username, u.display_name
                                 FROM user_devices d
                                 LEFT JOIN users u ON d.user_id = u.id
                                 ORDER BY d.created_at DESC");
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $devices]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';

        // โอนย้ายอุปกรณ์ไปให้ผู้ใช้อื่น
        if ($action === 'reassign') {
            $deviceId = (int)($body['id'] ?? 0);
            $newUserId = (int)($body['user_id'] ?? 0);

            if (!$deviceId || !$newUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ข้อมูลไม่ครบถ้วน']);
                exit;
            }

            $pdo->prepare("UPDATE user_devices SET user_id = ? WHERE id = ?")->execute([$newUserId, $deviceId]);
            echo json_encode(['success' => true, 'message' => 'โอนย้ายอุปกรณ์เรียบร้อยแล้ว']);
            exit;
        }

        // ลบอุปกรณ์
        if ($action === 'delete') {
            $deviceId = (int)($body['id'] ?? 0);
            $pdo->prepare("DELETE FROM user_devices WHERE id = ?")->execute([$deviceId]);
            echo json_encode(['success' => true, 'message' => 'ยกเลิกการผูกอุปกรณ์เรียบร้อยแล้ว']);
            exit;
        }
    }
}

// ==================== 4. มอนิเตอร์เหตุการณ์ล้มทั้งระบบ (Fall Monitor) ====================
if ($section === 'falls') {
    if ($method === 'GET') {
        try {
            $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
            $filterDev = $_GET['device_id'] ?? '';
            $filterAssisted = $_GET['assisted'] ?? '';

            $where = "WHERE 1=1";
            $params = [];
            if (!empty($filterDev)) {
                $where .= " AND f.device_id = ?";
                $params[] = $filterDev;
            }
            if ($filterAssisted !== '') {
                $where .= " AND f.assisted = ?";
                $params[] = (int)$filterAssisted;
            }

            $stmt = $pdo->prepare("SELECT f.*, d.device_name, u.username, u.display_name
                                   FROM fall_events f
                                   LEFT JOIN user_devices d ON f.device_id = d.device_id
                                   LEFT JOIN users u ON d.user_id = u.id
                                   $where
                                   ORDER BY f.timestamp DESC LIMIT $limit");
            $stmt->execute($params);
            $falls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $falls]);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $fallId = (int)($body['id'] ?? 0);

        if ($fallId > 0) {
            $pdo->prepare("UPDATE fall_events SET assisted = 1, assisted_at = NOW() WHERE id = ?")->execute([$fallId]);
            echo json_encode(['success' => true, 'message' => 'ยืนยันการช่วยเหลือเรียบร้อย']);
            exit;
        }
    }
}

// ==================== 5. ล้างข้อมูลระบบ (Clear Maintenance) ====================
if ($method === 'DELETE' || ($method === 'POST' && $section === 'clear')) {
    $action = $sub ?? ($_GET['action'] ?? ($body['action'] ?? ''));

    if ($action === 'steps') {
        $pdo->exec('DELETE FROM step_data');
        $pdo->exec('DELETE FROM daily_summary');
        $pdo->exec("DELETE FROM sensor_log WHERE type = 'position_data'");
        echo json_encode(['success' => true, 'message' => 'ล้างข้อมูลก้าวเดินสำเร็จ']);
        exit;
    }

    if ($action === 'falls') {
        $pdo->exec('DELETE FROM fall_events');
        $pdo->exec("DELETE FROM sensor_log WHERE type = 'fall_event'");
        echo json_encode(['success' => true, 'message' => 'ล้างข้อมูลเหตุการณ์ล้มทั้งหมดสำเร็จ']);
        exit;
    }

    if ($action === 'all') {
        $pdo->exec('DELETE FROM step_data');
        $pdo->exec('DELETE FROM fall_events');
        $pdo->exec('DELETE FROM daily_summary');
        $pdo->exec('DELETE FROM sensor_log');
        $pdo->exec('DELETE FROM chat_history');
        echo json_encode(['success' => true, 'message' => 'รีเซ็ตข้อมูลระบบทั้งหมดเรียบร้อยแล้ว']);
        exit;
    }
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Endpoint ไม่ถูกต้อง']);
