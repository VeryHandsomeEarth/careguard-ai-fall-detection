<?php
/*
 * api/position.php
 * GET/POST — ข้อมูลตำแหน่ง / ก้าว (Hardware2)
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

$uri    = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', $uri)));
// ['api','position'] / ['api','position','latest'] / ['api','position','distance'] / ['api','position','history','ESP32_002']
$segment2 = $parts[2] ?? null;
$segment3 = $parts[3] ?? null;

// ==================== Routing ====================

// GET /api/position/latest
if ($method === 'GET' && $segment2 === 'latest') {
    $stmt = $pdo->query("
        SELECT s1.* FROM step_data s1
        INNER JOIN (
            SELECT device_id, MAX(timestamp) as max_time
            FROM step_data
            GROUP BY device_id
        ) s2 ON s1.device_id = s2.device_id AND s1.timestamp = s2.max_time
        ORDER BY s1.device_id
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// GET /api/position/distance
if ($method === 'GET' && $segment2 === 'distance') {
    $stmt = $pdo->query("
        SELECT s1.device_id, s1.position_x, s1.position_y, s1.distance, s1.rssi
        FROM step_data s1
        INNER JOIN (
            SELECT device_id, MAX(timestamp) as max_time
            FROM step_data
            GROUP BY device_id
        ) s2 ON s1.device_id = s2.device_id AND s1.timestamp = s2.max_time
    ");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $distances = [];
    for ($i = 0; $i < count($nodes); $i++) {
        for ($j = $i + 1; $j < count($nodes); $j++) {
            $dx = $nodes[$i]['position_x'] - $nodes[$j]['position_x'];
            $dy = $nodes[$i]['position_y'] - $nodes[$j]['position_y'];
            $dist = sqrt($dx * $dx + $dy * $dy);
            $distances[] = [
                'from'     => $nodes[$i]['device_id'],
                'to'       => $nodes[$j]['device_id'],
                'distance' => round($dist, 2),
                'from_pos' => ['x' => $nodes[$i]['position_x'], 'y' => $nodes[$i]['position_y']],
                'to_pos'   => ['x' => $nodes[$j]['position_x'], 'y' => $nodes[$j]['position_y']],
            ];
        }
    }

    echo json_encode(['success' => true, 'nodes' => $nodes, 'distances' => $distances]);
    exit;
}

// GET /api/position/history/{device_id}
if ($method === 'GET' && $segment2 === 'history' && $segment3) {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $stmt  = $pdo->prepare('SELECT * FROM step_data WHERE device_id = ? ORDER BY timestamp DESC LIMIT ?');
    $stmt->execute([$segment3, $limit]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// POST /api/position
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    $device_id     = $body['device_id']     ?? '';
    $rssi          = $body['rssi']           ?? null;
    $distance      = $body['distance']       ?? null;
    $step_count    = $body['step_count']     ?? null;
    $walk_distance = $body['walk_distance']  ?? 0;
    $position      = $body['position']       ?? ['x' => 0, 'y' => 0];

    if (!$device_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ต้องระบุ device_id']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO step_data (device_id, rssi, distance, step_count, walk_distance, position_x, position_y)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $device_id, $rssi, $distance, $step_count,
        $walk_distance,
        $position['x'] ?? 0,
        $position['y'] ?? 0
    ]);
    $insertId = $pdo->lastInsertId();

    // บันทึก raw log
    $logStmt = $pdo->prepare('INSERT INTO sensor_log (type, device_id, data) VALUES (?, ?, ?)');
    $logStmt->execute(['position_data', $device_id, json_encode($body)]);

    // อัปเดตสรุปรายวัน
    updateDailySummary($pdo, $step_count ?? 0, $walk_distance);

    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลตำแหน่งสำเร็จ', 'id' => $insertId]);
    exit;
}

// GET /api/position
if ($method === 'GET') {
    $limit     = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $device_id = $_GET['device_id'] ?? null;

    if ($device_id) {
        $stmt = $pdo->prepare('SELECT * FROM step_data WHERE device_id = ? ORDER BY timestamp DESC LIMIT ?');
        $stmt->execute([$device_id, $limit]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM step_data ORDER BY timestamp DESC LIMIT ?');
        $stmt->execute([$limit]);
    }

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);

// ==================== Helper ====================
function updateDailySummary(PDO $pdo, int $steps, float $distance): void {
    try {
        $today = date('Y-m-d');
        $stmt  = $pdo->prepare("
            INSERT INTO daily_summary (date, total_steps, total_distance)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_steps    = total_steps + VALUES(total_steps),
                total_distance = total_distance + VALUES(total_distance)
        ");
        $stmt->execute([$today, $steps, $distance]);
    } catch (Exception $e) {
        // ไม่บล็อก response หลัก
    }
}
