<?php
/*
 * api/stats.php — Statistics API
 * GET /api/stats/daily        — สถิติวันนี้
 * GET /api/stats/weekly       — สรุป 7 วัน (ก้าว/ระยะทาง/ล้ม)
 * GET /api/stats/hourly       — กิจกรรมรายชั่วโมงวันนี้
 * GET /api/stats/falls/weekly — จำนวนล้มรายวัน 7 วัน
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json; charset=utf-8');
rateLimit(120);

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/api/stats';
$parts  = array_values(array_filter(explode('/', $uri)));
$type   = $parts[2] ?? 'daily'; // daily | weekly | hourly | falls
$sub    = $parts[3] ?? null;    // weekly (for falls/weekly)

if ($method !== 'GET') jsonError(405, 'Method not allowed');

$pdo = getDB();

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['user']['role'] ?? 'user';

// ตรวจสอบและดึงรายการ device_id ที่ผู้ใช้ผูกไว้
$userDevIds = [];
if ($user_id && $userRole !== 'admin') {
    try {
        $devStmt = $pdo->prepare("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices WHERE user_id = ?");
        $devStmt->execute([$user_id]);
        $userDevices = $devStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($userDevices as $ud) {
            $did = $ud['device_id'];
            $isHw2 = ($ud['device_type'] === 'hw2_wifi' || stripos($ud['device_name'], 'hw2') !== false || stripos($ud['device_name'], 'hardware2') !== false || stripos($ud['device_name'], 'hardware 2') !== false);
            if ($isHw2 && $did === 'ESP32_001') {
                $did = 'ESP32_HW2';
                $pdo->prepare("UPDATE user_devices SET device_id = 'ESP32_HW2' WHERE id = ?")->execute([$ud['id']]);
            }
            $userDevIds[] = $did;
        }
        $userDevIds = array_values(array_unique(array_filter($userDevIds)));
    } catch (Exception $e) {}
}

function getDeviceFilterSql(?int $userId, string $userRole, array $devIds, string $col = 'device_id'): array {
    if ($userRole === 'admin') {
        // โรล admin: เห็นสถิติของอุปกรณ์ทุกตัวในระบบ
        return ['', []];
    }
    if (!$userId || empty($devIds)) {
        // โรล ผู้ใช้ทั่วไป: ถ้ายังไม่มีอุปกรณ์ผูกไว้ ไม่แสดงสถิติของผู้อื่น
        return [" AND 1=0", []];
    }
    $inP = implode(',', array_fill(0, count($devIds), '?'));
    return [" AND {$col} IN ($inP)", $devIds];
}

// ทำความสะอาดชื่อประเภทการล้มที่มี ? หรือชื่อไม่ตรงในฐานข้อมูลอัตโนมัติ
try {
    $pdo->exec("UPDATE fall_events SET fall_type_name = 'ล้มไปข้างหน้า (Fall Forward)' WHERE fall_type = 'fall_forward' AND (fall_type_name LIKE '%?%' OR fall_type_name = 'การล้มทั่วไป' OR fall_type_name IS NULL)");
    $pdo->exec("UPDATE fall_events SET fall_type_name = 'ล้มไปข้างหลัง (Fall Backward)' WHERE fall_type = 'fall_backward' AND (fall_type_name LIKE '%?%' OR fall_type_name = 'การล้มทั่วไป' OR fall_type_name IS NULL)");
    $pdo->exec("UPDATE fall_events SET fall_type_name = 'ล้มไปด้านข้างซ้าย (Fall Lateral Left)' WHERE (fall_type = 'fall_lateral_left' OR fall_type = 'fall_left') AND (fall_type_name LIKE '%?%' OR fall_type_name = 'การล้มทั่วไป' OR fall_type_name IS NULL)");
    $pdo->exec("UPDATE fall_events SET fall_type_name = 'ล้มไปด้านข้างขวา (Fall Lateral Right)' WHERE (fall_type = 'fall_lateral_right' OR fall_type = 'fall_right') AND (fall_type_name LIKE '%?%' OR fall_type_name = 'การล้มทั่วไป' OR fall_type_name IS NULL)");
    $pdo->exec("UPDATE fall_events SET fall_type_name = 'ล้มแนวดิ่ง/ทรุดตัว (Vertical Fall)' WHERE fall_type = 'fall_vertical' AND (fall_type_name LIKE '%?%' OR fall_type_name = 'การล้มทั่วไป' OR fall_type_name IS NULL)");
    $pdo->exec("UPDATE fall_events SET fall_type_name = 'การล้มทั่วไป (General Fall)' WHERE (fall_type = 'fall_general' OR fall_type IS NULL OR fall_type = '') AND (fall_type_name LIKE '%?%' OR fall_type_name = 'การล้มทั่วไป' OR fall_type_name IS NULL)");
} catch (Exception $e) {}

// ==================== สถิติวันนี้ ====================
if ($type === 'daily') {
    $today = date('Y-m-d');
    
    list($devWhereGps, $devParamsGps) = getDeviceFilterSql($user_id, $userRole, $userDevIds);
    list($devWhereFall, $devParamsFall) = getDeviceFilterSql($user_id, $userRole, $userDevIds);

    $paramsGps = array_merge([$today, $today], $devParamsGps);
    $paramsFall = array_merge([$today, $today], $devParamsFall);

    $stmtSteps = $pdo->prepare("SELECT
        COALESCE(MAX(step_count),0)    AS total_steps,
        COALESCE(MAX(walk_distance),0) AS total_distance,
        COALESCE(AVG(speed_kmh),0)     AS avg_speed,
        COUNT(*)                        AS readings
        FROM gps_log WHERE (DATE(timestamp) = ? OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = ?)$devWhereGps");
    $stmtSteps->execute($paramsGps);
    $steps = $stmtSteps->fetch();

    $stmtFalls = $pdo->prepare("SELECT
        COUNT(*) AS fall_count,
        COALESCE(SUM(is_confirmed), 0) AS confirmed_falls,
        COALESCE(SUM(CASE WHEN assisted = 1 THEN 1 ELSE 0 END), 0) AS assisted_count,
        COALESCE(SUM(CASE WHEN assisted = 0 THEN 1 ELSE 0 END), 0) AS pending_assist_count,
        COALESCE(SUM(CASE WHEN offline_recorded = 1 THEN 1 ELSE 0 END), 0) AS offline_sync_count
        FROM fall_events WHERE (DATE(timestamp) = ? OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = ?)$devWhereFall");
    $stmtFalls->execute($paramsFall);
    $falls = $stmtFalls->fetch();

    $stmtTypes = $pdo->prepare("SELECT
        COALESCE(fall_type, 'fall_general') AS fall_type,
        COUNT(*) AS count
        FROM fall_events
        WHERE 1=1 $devWhereFall
        GROUP BY fall_type
        ORDER BY count DESC");
    $stmtTypes->execute($devParamsFall);
    $rawFallTypes = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

    $typeNamesMap = [
        'fall_forward'       => 'ล้มไปข้างหน้า (Fall Forward)',
        'fall_backward'      => 'ล้มไปข้างหลัง (Fall Backward)',
        'fall_lateral_left'  => 'ล้มไปด้านข้างซ้าย (Fall Lateral Left)',
        'fall_left'          => 'ล้มไปด้านข้างซ้าย (Fall Lateral Left)',
        'fall_lateral_right' => 'ล้มไปด้านข้างขวา (Fall Lateral Right)',
        'fall_right'         => 'ล้มไปด้านข้างขวา (Fall Lateral Right)',
        'fall_vertical'      => 'ล้มแนวดิ่ง/ทรุดตัว (Vertical Fall)',
        'fall_general'       => 'การล้มทั่วไป (General Fall)',
    ];

    $consolidatedTypes = [];
    foreach ($rawFallTypes as $rft) {
        $ft = strtolower(trim($rft['fall_type'] ?? 'fall_general'));
        if ($ft === 'fall_left') $ft = 'fall_lateral_left';
        if ($ft === 'fall_right') $ft = 'fall_lateral_right';
        $cnt = (int)($rft['count'] ?? 0);
        $name = $typeNamesMap[$ft] ?? 'การล้มทั่วไป (General Fall)';
        $consolidatedTypes[$name] = ($consolidatedTypes[$name] ?? 0) + $cnt;
    }

    $fallTypes = [];
    foreach ($consolidatedTypes as $name => $cnt) {
        $fallTypes[] = [
            'fall_type_name' => $name,
            'count'          => $cnt
        ];
    }

    $stmtLastGps = $pdo->prepare("SELECT lat, lng, speed_kmh, satellites, altitude_m, hdop, timestamp
        FROM gps_log WHERE 1=1 $devWhereGps ORDER BY timestamp DESC LIMIT 1");
    $stmtLastGps->execute($devParamsGps);
    $lastGps = $stmtLastGps->fetch();

    echo json_encode(['success' => true, 'data' => [
        'steps'      => $steps,
        'falls'      => $falls,
        'fallTypes'  => $fallTypes,
        'lastGps'    => $lastGps ?: null,
        'date'       => $today,
    ]]);
    exit;
}

// ==================== สรุป 7 วัน ====================
if ($type === 'weekly') {
    list($devWhereGps, $devParamsGps) = getDeviceFilterSql($user_id, $userRole, $userDevIds);
    list($devWhereFall, $devParamsFall) = getDeviceFilterSql($user_id, $userRole, $userDevIds);

    $stmtWeeklySteps = $pdo->prepare("SELECT
        DATE(timestamp)                 AS date,
        COALESCE(MAX(step_count), 0)    AS total_steps,
        COALESCE(MAX(walk_distance), 0) AS total_distance,
        COALESCE(AVG(speed_kmh), 0)     AS avg_speed
        FROM gps_log
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) $devWhereGps
        GROUP BY DATE(timestamp)
        ORDER BY date ASC");
    $stmtWeeklySteps->execute($devParamsGps);
    $weeklySteps = $stmtWeeklySteps->fetchAll();

    $stmtWeeklyFalls = $pdo->prepare("SELECT
        DATE(timestamp) AS date,
        COUNT(*)        AS fall_count,
        COALESCE(SUM(CASE WHEN severity='high' THEN 1 ELSE 0 END), 0) AS high_count,
        COALESCE(SUM(CASE WHEN severity='medium' THEN 1 ELSE 0 END), 0) AS medium_count,
        COALESCE(SUM(CASE WHEN severity='low' THEN 1 ELSE 0 END), 0) AS low_count
        FROM fall_events
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) $devWhereFall
        GROUP BY DATE(timestamp)
        ORDER BY date ASC");
    $stmtWeeklyFalls->execute($devParamsFall);
    $fallsWeekly = $stmtWeeklyFalls->fetchAll();

    echo json_encode(['success' => true, 'data' => [
        'weeklySteps' => $weeklySteps,
        'weeklyFalls' => $fallsWeekly,
    ]]);
    exit;
}

// ==================== รายชั่วโมงวันนี้ ====================
if ($type === 'hourly') {
    $today = date('Y-m-d');
    list($devWhereGps, $devParamsGps) = getDeviceFilterSql($user_id, $userRole, $userDevIds);

    $paramsHourly = array_merge([$today], $devParamsGps);
    $stmt = $pdo->prepare("SELECT
        HOUR(timestamp)          AS hour,
        MAX(step_count)          AS steps,
        ROUND(AVG(speed_kmh),2)  AS avg_speed,
        COUNT(*)                 AS readings
        FROM gps_log
        WHERE DATE(timestamp) = ? $devWhereGps
        GROUP BY HOUR(timestamp)
        ORDER BY hour ASC");
    $stmt->execute($paramsHourly);
    $hourly = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => ['hourlySteps' => $hourly]]);
    exit;
}

// ==================== ล้มรายวัน 7 วัน ====================
if ($type === 'falls') {
    list($devWhereFall, $devParamsFall) = getDeviceFilterSql($user_id, $userRole, $userDevIds);

    $stmtFalls7 = $pdo->prepare("SELECT
        DATE(timestamp) AS date,
        COUNT(*)        AS fall_count,
        COALESCE(SUM(CASE WHEN severity='high' THEN 1 ELSE 0 END), 0) AS high_count,
        COALESCE(SUM(CASE WHEN severity='medium' THEN 1 ELSE 0 END), 0) AS medium_count,
        COALESCE(SUM(CASE WHEN severity='low' THEN 1 ELSE 0 END), 0) AS low_count
        FROM fall_events
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) $devWhereFall
        GROUP BY DATE(timestamp)
        ORDER BY date ASC");
    $stmtFalls7->execute($devParamsFall);
    $weekly = $stmtFalls7->fetchAll();

    echo json_encode(['success' => true, 'data' => $weekly]);
    exit;
}

jsonError(404, 'Stats type not found');
