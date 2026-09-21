<?php
/*
 * test.php — ทดสอบ DB + PHP version (ลบหลังใช้งาน)
 */
header('Content-Type: application/json; charset=utf-8');

$result = [
    'php_version' => PHP_VERSION,
    'php_8'       => version_compare(PHP_VERSION, '8.0', '>='),
    'time'        => date('Y-m-d H:i:s'),
];

// Test .env
$envFile = __DIR__ . '/.env';
$result['env_exists'] = file_exists($envFile);

// Test DB
try {
    require_once __DIR__ . '/config/db.php';
    $pdo = getDB();
    $result['db_connected'] = true;

    // ตรวจสอบตาราง
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $result['tables'] = $tables;

    // ตรวจสอบ api_keys
    $keys = $pdo->query("SELECT id, api_key, device_id, is_active FROM api_keys")->fetchAll();
    $result['api_keys'] = $keys;

    // ทดสอบ INSERT fall_events
    $stmt = $pdo->prepare("INSERT INTO fall_events (device_id, severity, severity_score, acceleration_x, acceleration_y, acceleration_z) VALUES (?,?,?,?,?,?)");
    $stmt->execute(['TEST_DEVICE', 'low', 0.1, 0, 0, 9.8]);
    $id = $pdo->lastInsertId();
    $result['test_insert'] = "OK (id=$id)";

    // ลบ test row
    $pdo->prepare("DELETE FROM fall_events WHERE id = ?")->execute([$id]);
    $result['test_delete'] = 'OK';

} catch (Exception $e) {
    $result['db_connected'] = false;
    $result['db_error']     = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
