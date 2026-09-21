<?php
// receive_data.php – Handles JSON payloads from CollectData_Hardware ESP32
// Expected payload:
// {
//   "mac_address": "AA:BB:CC:DD:EE:FF",
//   "data": [
//       {"t":10,"ax":0.01,"ay":0.02,"az":9.81,"gx":0,"gy":0,"gz":0},
//       ...
//   ]
// }

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config.php';

$pdo = getDB();

// Read raw POST body
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'No data received']);
    exit;
}

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !isset($data['mac_address']) || !isset($data['data'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid JSON payload']);
    exit;
}

$mac = $data['mac_address'];
$samples = $data['data'];
if (!is_array($samples) || count($samples) === 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'No sensor samples']);
    exit;
}

try {
    // Find the record that is currently "recording" for this device
    $stmt = $pdo->prepare("SELECT id FROM cd_records WHERE device_id = (SELECT id FROM cd_devices WHERE mac_address = ?) AND status = 'recording' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$mac]);
    $record = $stmt->fetch();
    if (!$record) {
        http_response_code(404);
        echo json_encode(['success'=>false,'error'=>'No active recording for this device']);
        exit;
    }
    $record_id = $record['id'];

    // Prepare insert statement for batch insert
    $insert = $pdo->prepare("INSERT INTO cd_sensor_data (record_id, timestamp_ms, accel_x, accel_y, accel_z, gyro_x, gyro_y, gyro_z) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $pdo->beginTransaction();
    foreach ($samples as $sample) {
        $insert->execute([
            $record_id,
            $sample['t'] ?? 0,
            $sample['ax'] ?? 0,
            $sample['ay'] ?? 0,
            $sample['az'] ?? 0,
            $sample['gx'] ?? 0,
            $sample['gy'] ?? 0,
            $sample['gz'] ?? 0,
        ]);
    }
    $pdo->commit();
    echo json_encode(['success'=>true,'inserted'=>count($samples)]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>
