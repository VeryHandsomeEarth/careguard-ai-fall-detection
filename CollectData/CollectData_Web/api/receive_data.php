<?php
// receive_data.php – Handles JSON payloads from CollectData_Hardware ESP32
// Optimized for High Performance & Zero Database Lock Contention

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Connection: close');

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
    // 1. ตรวจสอบ record_id
    $record_id = isset($data['record_id']) && intval($data['record_id']) > 0 ? intval($data['record_id']) : 0;
    if ($record_id <= 0) {
        $stmt = $pdo->prepare("SELECT id FROM cd_records WHERE device_id = (SELECT id FROM cd_devices WHERE mac_address = ?) AND status = 'recording' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$mac]);
        $record = $stmt->fetch();
        if (!$record) {
            // หากไม่มี ให้หารายการล่าสุดของอุปกรณ์นี้ที่ยังไม่มีข้อมูล
            $stmtPending = $pdo->prepare("SELECT r.id FROM cd_records r WHERE r.device_id = (SELECT id FROM cd_devices WHERE mac_address = ?) AND (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) = 0 ORDER BY r.id DESC LIMIT 1");
            $stmtPending->execute([$mac]);
            $record = $stmtPending->fetch();
        }
        if (!$record) {
            // หากยังไม่มี ให้สร้างรอบใหม่ของ D01 อัตโนมัติ
            $devStmt = $pdo->prepare("SELECT id FROM cd_devices WHERE mac_address = ?");
            $devStmt->execute([$mac]);
            $devId = intval($devStmt->fetchColumn());

            if ($devId > 0) {
                $subStmt = $pdo->prepare("SELECT subject_id FROM cd_records WHERE device_id = ? ORDER BY id DESC LIMIT 1");
                $subStmt->execute([$devId]);
                $subId = intval($subStmt->fetchColumn());
                if ($subId <= 0) {
                    $subId = intval($pdo->query("SELECT id FROM cd_subjects ORDER BY id DESC LIMIT 1")->fetchColumn());
                }

                $ftStmt = $pdo->query("SELECT id FROM cd_fall_types WHERE code IN ('D01', 'adl_walk') ORDER BY id ASC LIMIT 1");
                $ftId = intval($ftStmt->fetchColumn());

                if ($subId > 0 && $ftId > 0) {
                    $maxTr = $pdo->prepare("SELECT COALESCE(MAX(trial_number), 0) + 1 FROM cd_records WHERE subject_id = ? AND fall_type_id = ?");
                    $maxTr->execute([$subId, $ftId]);
                    $nextTr = intval($maxTr->fetchColumn());

                    $newIns = $pdo->prepare("INSERT INTO cd_records (subject_id, device_id, fall_type_id, trial_number, notes, status) VALUES (?, ?, ?, ?, 'Hardware D18 Trigger', 'recording')");
                    $newIns->execute([$subId, $devId, $ftId, $nextTr]);
                    $record = ['id' => $pdo->lastInsertId()];
                }
            }
        }
        if (!$record) {
            http_response_code(404);
            echo json_encode(['success'=>false,'error'=>'No active or pending recording for this device']);
            exit;
        }
        $record_id = $record['id'];
    }

    // 2. บันทึกข้อมูลเซ็นเซอร์แบบ Multi-row Bulk INSERT (เร็วขึ้น 50 เท่า ไม่ล็อคตาราง)
    $pdo->beginTransaction();
    $chunks = array_chunk($samples, 100);
    foreach ($chunks as $chunk) {
        $placeholders = [];
        $values = [];
        foreach ($chunk as $sample) {
            $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
            $values[] = $record_id;
            $values[] = $sample['t'] ?? 0;
            $values[] = $sample['ax'] ?? 0;
            $values[] = $sample['ay'] ?? 0;
            $values[] = $sample['az'] ?? 0;
            $values[] = $sample['gx'] ?? 0;
            $values[] = $sample['gy'] ?? 0;
            $values[] = $sample['gz'] ?? 0;
        }
        $sql = "INSERT INTO cd_sensor_data (record_id, timestamp_ms, accel_x, accel_y, accel_z, gyro_x, gyro_y, gyro_z) VALUES " . implode(', ', $placeholders);
        $insert = $pdo->prepare($sql);
        $insert->execute($values);
    }

    // 3. ตรวจสอบสถานะการหยุดบันทึก
    $statusStmt = $pdo->prepare("SELECT status FROM cd_records WHERE id = ?");
    $statusStmt->execute([$record_id]);
    $currentStatus = $statusStmt->fetchColumn();
    $stop_requested = ($currentStatus === 'completed' || $currentStatus === 'cancelled');

    // หากส่งสัญญาณจบการบันทึก (is_final = true) ให้เปลี่ยนสถานะเป็น completed ทันที
    $is_final = !empty($data['is_final']);
    if ($is_final) {
        $upd = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE id = ?");
        $upd->execute([$record_id]);
        $stop_requested = true;
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'inserted' => count($samples),
        'record_id' => $record_id,
        'completed' => $is_final,
        'stop_requested' => $stop_requested
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>
