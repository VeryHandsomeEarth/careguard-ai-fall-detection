<?php
/**
 * API CRUD — จัดการข้อมูลทั้งหมด
 * URL: https://www.youngza.com/IMU/CollectData_Web/api/actions.php
 * 
 * Parameters:
 *   action = add_subject | delete_subject
 *          | add_device | delete_device
 *          | add_record | delete_record
 *          | add_fall_type
 *          | add_trial | delete_trial
 *          | get_sensor_data | get_records | get_fall_types
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../config.php';

$pdo = getDB();
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ==================== ผู้ทดสอบ ====================
        case 'add_subject':
            $stmt = $pdo->prepare("
                INSERT INTO cd_subjects (name, age, height_cm, weight_kg, gender, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'] ?? '',
                intval($_POST['age'] ?? 0),
                floatval($_POST['height_cm'] ?? 0),
                floatval($_POST['weight_kg'] ?? 0),
                $_POST['gender'] ?? 'O',
                $_POST['notes'] ?? ''
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'delete_subject':
            $stmt = $pdo->prepare("DELETE FROM cd_subjects WHERE id = ?");
            $stmt->execute([intval($_POST['id'] ?? 0)]);
            echo json_encode(['success' => true]);
            break;

        // ==================== อุปกรณ์ ====================
        case 'add_device':
            $stmt = $pdo->prepare("
                INSERT INTO cd_devices (name, mac_address, description)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'] ?? '',
                $_POST['mac_address'] ?? '',
                $_POST['description'] ?? ''
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'delete_device':
            $stmt = $pdo->prepare("DELETE FROM cd_devices WHERE id = ?");
            $stmt->execute([intval($_POST['id'] ?? 0)]);
            echo json_encode(['success' => true]);
            break;

        // ==================== บันทึกการล้ม ====================
        case 'add_record':
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $fall_type_id = intval($_POST['fall_type_id'] ?? 0);
            $device_id = intval($_POST['device_id'] ?? 0);
            $req_status = $_POST['status'] ?? 'pending';

            if ($subject_id <= 0 || $fall_type_id <= 0 || $device_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'กรุณาเลือกท่าทางและอุปกรณ์ให้ครบถ้วน']);
                break;
            }

            // คำนวณลำดับรอบอัตโนมัติสำหรับท่านี้ (Auto-increment trial_number)
            $trialStmt = $pdo->prepare("SELECT COALESCE(MAX(trial_number), 0) + 1 FROM cd_records WHERE subject_id = ? AND fall_type_id = ?");
            $trialStmt->execute([$subject_id, $fall_type_id]);
            $next_trial_num = intval($trialStmt->fetchColumn());
            if ($next_trial_num < 1) $next_trial_num = 1;

            $trial_id = !empty($_POST['trial_id']) ? intval($_POST['trial_id']) : null;
            // สถานะเริ่มต้น: 'pending' (รอส่งคำสั่ง) เก็บเป็น 'completed' ที่มี 0 จุดข้อมูล
            $db_status = ($req_status === 'recording') ? 'recording' : 'completed';

            $pdo->beginTransaction();
            if ($db_status === 'recording') {
                // เคลียร์รายการอื่นของอุปกรณ์เดียวกันที่ค้างสถานะ recording อยู่
                $resetStmt = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE device_id = ? AND status = 'recording'");
                $resetStmt->execute([$device_id]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO cd_records (subject_id, device_id, fall_type_id, trial_id, trial_number, notes, status)
                VALUES (?, ?, ?, ?, ?, '', ?)
            ");
            $stmt->execute([
                $subject_id,
                $device_id,
                $fall_type_id,
                $trial_id,
                $next_trial_num,
                $db_status
            ]);
            $new_id = $pdo->lastInsertId();
            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $new_id, 'trial_number' => $next_trial_num, 'status' => $db_status]);
            break;

        case 'delete_record':
            $stmt = $pdo->prepare("DELETE FROM cd_records WHERE id = ?");
            $stmt->execute([intval($_POST['id'] ?? 0)]);
            echo json_encode(['success' => true]);
            break;

        case 'update_record_status':
            $stmt = $pdo->prepare("UPDATE cd_records SET status = ? WHERE id = ?");
            $stmt->execute([
                $_POST['status'] ?? 'completed',
                intval($_POST['id'] ?? 0)
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'send_command':
            $record_id = intval($_POST['id'] ?? ($_REQUEST['record_id'] ?? 0));
            if ($record_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid record_id']);
                break;
            }
            $recStmt = $pdo->prepare("SELECT id, device_id FROM cd_records WHERE id = ?");
            $recStmt->execute([$record_id]);
            $rec = $recStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rec) {
                echo json_encode(['success' => false, 'error' => 'ไม่พบรายการบันทึกที่ระบุ']);
                break;
            }
            $pdo->beginTransaction();
            // ยกเลิกสถานะรอรับข้อมูลของรายการอื่นที่ใช้ device เดียวกัน เพื่อไม่ให้คำสั่งชนกัน
            $resetStmt = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE device_id = ? AND status = 'recording' AND id != ?");
            $resetStmt->execute([$rec['device_id'], $record_id]);
            // ล้างข้อมูลเซ็นเซอร์เก่าของรายการนี้ (กรณีส่งคำสั่งซ้ำ) เพื่อรอรับข้อมูลชุดใหม่จาก ESP32
            $delData = $pdo->prepare("DELETE FROM cd_sensor_data WHERE record_id = ?");
            $delData->execute([$record_id]);
            // ตั้งสถานะรายการนี้เป็น recording (รอ ESP32 ส่งข้อมูลกลับมา)
            $updStmt = $pdo->prepare("UPDATE cd_records SET status = 'recording' WHERE id = ?");
            $updStmt->execute([$record_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'record_id' => $record_id, 'status' => 'recording']);
            break;

        case 'cancel_command':
            $record_id = intval($_POST['id'] ?? ($_REQUEST['record_id'] ?? 0));
            if ($record_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid record_id']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE id = ?");
            $stmt->execute([$record_id]);
            echo json_encode(['success' => true, 'record_id' => $record_id]);
            break;

        case 'check_recording_status':
            $subject_id = intval($_REQUEST['subject_id'] ?? 0);
            $finalize_id = intval($_REQUEST['finalize_id'] ?? 0);
            if ($finalize_id > 0) {
                // เปลี่ยนเป็นเสร็จสิ้นเฉพาะเมื่อมีข้อมูลจาก ESP32 ส่งเข้ามาแล้วเท่านั้น (data_points > 0)
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = ?");
                $cntStmt->execute([$finalize_id]);
                if ((int)$cntStmt->fetchColumn() > 0) {
                    $finStmt = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE id = ?");
                    $finStmt->execute([$finalize_id]);
                }
            }
            $stmt = $pdo->prepare("
                SELECT r.id, r.status, r.device_id, r.trial_number,
                       (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points
                FROM cd_records r
                WHERE r.subject_id = ?
                ORDER BY r.id DESC
            ");
            $stmt->execute([$subject_id]);
            echo json_encode(['success' => true, 'records' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==================== ประเภทการล้ม ====================
        case 'add_fall_type':
            $code = $_POST['code'] ?? '';
            $name_th = $_POST['name_th'] ?? '';
            $name_en = $_POST['name_en'] ?? '';
            $category = $_POST['category'] ?? 'other';

            // สร้าง code อัตโนมัติถ้าไม่ระบุ
            if (empty($code)) {
                $code = 'custom_' . time();
            }

            $stmt = $pdo->prepare("
                INSERT INTO cd_fall_types (code, name_th, name_en, category)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$code, $name_th, $name_en, $category]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        // ==================== รอบทดลอง ====================
        case 'add_trial':
            $stmt = $pdo->prepare("
                INSERT INTO cd_trial (subject_id, trial_label, description, trial_date, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                intval($_POST['subject_id'] ?? 0),
                $_POST['trial_label'] ?? '',
                $_POST['description'] ?? '',
                $_POST['trial_date'] ?? null,
                $_POST['notes'] ?? ''
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'delete_trial':
            $stmt = $pdo->prepare("DELETE FROM cd_trial WHERE id = ?");
            $stmt->execute([intval($_POST['id'] ?? 0)]);
            echo json_encode(['success' => true]);
            break;

        // ==================== ดึงข้อมูล ====================
        case 'get_fall_types':
            $stmt = $pdo->query("SELECT * FROM cd_fall_types ORDER BY category, id");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'get_records':
            $subject_id = intval($_GET['subject_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT r.*, ft.name_th as fall_type_name, ft.code as fall_type_code, ft.category,
                       d.name as device_name,
                       (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points
                FROM cd_records r
                JOIN cd_fall_types ft ON r.fall_type_id = ft.id
                JOIN cd_devices d ON r.device_id = d.id
                WHERE r.subject_id = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$subject_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'get_sensor_data':
            $record_id = intval($_GET['record_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT timestamp_ms, accel_x, accel_y, accel_z, gyro_x, gyro_y, gyro_z
                FROM cd_sensor_data
                WHERE record_id = ?
                ORDER BY timestamp_ms ASC
            ");
            $stmt->execute([$record_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ==================== ส่งออกข้อมูล CSV ====================
        case 'export_records':
            // เงื่อนไขกรองข้อมูลเดียวกัน
            $where_clauses = [];
            $params = [];

            $filter_category = $_GET['category'] ?? '';
            $filter_subject = intval($_GET['subject_id'] ?? 0);
            $filter_type = intval($_GET['fall_type_id'] ?? 0);

            if (!empty($filter_category)) {
                $where_clauses[] = "ft.category = ?";
                $params[] = $filter_category;
            }
            if ($filter_subject > 0) {
                $where_clauses[] = "r.subject_id = ?";
                $params[] = $filter_subject;
            }
            if ($filter_type > 0) {
                $where_clauses[] = "r.fall_type_id = ?";
                $params[] = $filter_type;
            }

            $where_sql = "";
            if (count($where_clauses) > 0) {
                $where_sql = "WHERE " . implode(" AND ", $where_clauses);
            }

            $query = "
                SELECT r.id,
                       s.name as subject_name,
                       ft.category as fall_type_category,
                       ft.name_th as fall_type_name,
                       r.trial_number,
                       d.name as device_name,
                       (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points,
                       r.status,
                       r.created_at
                FROM cd_records r
                JOIN cd_subjects s ON r.subject_id = s.id
                JOIN cd_devices d ON r.device_id = d.id
                JOIN cd_fall_types ft ON r.fall_type_id = ft.id
                $where_sql
                ORDER BY r.id DESC
            ";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ตั้งค่า header สำหรับการดาวน์โหลด CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=trial_records_export_' . date('Ymd_His') . '.csv');
            
            // ใส่ UTF-8 BOM
            echo "\xEF\xBB\xBF";
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'ผู้ทดสอบ', 'หมวดหมู่', 'ประเภทกิจกรรม', 'รอบที่', 'อุปกรณ์', 'จำนวนจุดข้อมูล', 'สถานะ', 'วันที่บันทึก']);
            
            foreach ($records as $r) {
                $category_th = $r['fall_type_category'] == 'fall' ? 'ล้ม (Fall)' : ($r['fall_type_category'] == 'adl' ? 'ADL' : 'อื่นๆ');
                $status_th = $r['status'] == 'completed' ? 'เสร็จสิ้น' : ($r['status'] == 'recording' ? 'กำลังบันทึก' : 'ยกเลิก');
                
                fputcsv($output, [
                    $r['id'],
                    $r['subject_name'],
                    $category_th,
                    $r['fall_type_name'],
                    $r['trial_number'],
                    $r['device_name'],
                    $r['data_points'],
                    $status_th,
                    $r['created_at']
                ]);
            }
            fclose($output);
            exit;

        case 'export_sensor_data':
            $record_id = intval($_GET['record_id'] ?? 0);
            
            // ดึงข้อมูลบันทึกเพื่อใช้ตั้งชื่อไฟล์
            $record = $pdo->prepare("
                SELECT r.id, ft.code as fall_type_code, s.name as subject_name
                FROM cd_records r
                JOIN cd_fall_types ft ON r.fall_type_id = ft.id
                JOIN cd_subjects s ON r.subject_id = s.id
                WHERE r.id = ?
            ");
            $record->execute([$record_id]);
            $record = $record->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                http_response_code(404);
                echo "ไม่พบบันทึกการทดลองที่ต้องการ";
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT timestamp_ms, accel_x, accel_y, accel_z, gyro_x, gyro_y, gyro_z
                FROM cd_sensor_data
                WHERE record_id = ?
                ORDER BY timestamp_ms ASC
            ");
            $stmt->execute([$record_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            $filename = 'sensor_data_record_' . $record_id . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $record['subject_name']) . '_' . $record['fall_type_code'] . '.csv';
            header('Content-Disposition: attachment; filename=' . $filename);
            
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['timestamp_ms', 'accel_x', 'accel_y', 'accel_z', 'gyro_x', 'gyro_y', 'gyro_z']);
            
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['timestamp_ms'],
                    $row['accel_x'],
                    $row['accel_y'],
                    $row['accel_z'],
                    $row['gyro_x'],
                    $row['gyro_y'],
                    $row['gyro_z']
                ]);
            }
            fclose($output);
            exit;

        // ==================== ตรวจสอบคำสั่งเริ่มบันทึกสำหรับ ESP32 (Polling) ====================
        case 'check_command':
            $mac = $_GET['mac'] ?? ($_GET['mac_address'] ?? '');
            if (empty($mac)) {
                echo json_encode(['success' => false, 'has_command' => false, 'error' => 'Missing MAC address']);
                break;
            }
            $stmt = $pdo->prepare("
                SELECT r.id as record_id, r.trial_number, r.notes,
                       ft.code as activity_code, ft.name_th as activity_name, ft.category
                FROM cd_records r
                JOIN cd_devices d ON r.device_id = d.id
                JOIN cd_fall_types ft ON r.fall_type_id = ft.id
                WHERE d.mac_address = ? AND r.status = 'recording'
                ORDER BY r.id DESC
                LIMIT 1
            ");
            $stmt->execute([$mac]);
            $cmd = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cmd) {
                $code = strtoupper(trim($cmd['activity_code']));
                $cat = strtolower(trim($cmd['category']));
                $is_fall = ($cat === 'fall' || strpos($code, 'F0') === 0 || stripos($code, 'FALL') !== false);

                // ท่าล้ม: 2 วินาที (ก่อนล้ม 1 วิ + หลังล้ม 1 วิ = 100 จุด ที่ 50Hz)
                // ท่า ADL: 10 วินาที (ยืนเตรียมตัว 1 วิ + ทำท่า 8 วิ + ยืน 1 วิ = 500 จุด ที่ 50Hz)
                $duration_sec = $is_fall ? 2 : 10;
                $target_samples = $is_fall ? 100 : 500;

                echo json_encode([
                    'success' => true,
                    'has_command' => true,
                    'record_id' => intval($cmd['record_id']),
                    'category' => $is_fall ? 'fall' : 'adl',
                    'activity_code' => $cmd['activity_code'],
                    'activity_name' => $cmd['activity_name'],
                    'trial_number' => intval($cmd['trial_number']),
                    'duration_sec' => $duration_sec,
                    'target_samples' => $target_samples,
                    'is_fall' => $is_fall,
                    'is_continuous' => false,
                    'countdown_sec' => 3
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'has_command' => false
                ]);
            }
            break;

        case 'delete_posture_records':
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $fall_type_id = intval($_POST['fall_type_id'] ?? 0);
            if ($subject_id > 0 && $fall_type_id > 0) {
                $pdo->beginTransaction();
                $recIdsStmt = $pdo->prepare("SELECT id FROM cd_records WHERE subject_id = ? AND fall_type_id = ?");
                $recIdsStmt->execute([$subject_id, $fall_type_id]);
                $recIds = $recIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($recIds)) {
                    $inClause = implode(',', array_map('intval', $recIds));
                    $pdo->query("DELETE FROM cd_sensor_data WHERE record_id IN ($inClause)");
                    $pdo->query("DELETE FROM cd_records WHERE id IN ($inClause)");
                }
                $pdo->commit();
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            }
            break;

        case 'stop_command':
            $record_id = intval($_POST['id'] ?? ($_REQUEST['record_id'] ?? 0));
            if ($record_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid record_id']);
                break;
            }
            // ปรับสถานะเป็น completed เพื่อให้ receive_data.php ส่ง stop_requested: true ให้ ESP32
            $stmt = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE id = ?");
            $stmt->execute([$record_id]);
            echo json_encode(['success' => true, 'record_id' => $record_id, 'status' => 'completed']);
            break;

        case 'complete_record':
            $record_id = intval($_REQUEST['record_id'] ?? 0);
            if ($record_id > 0) {
                $stmt = $pdo->prepare("UPDATE cd_records SET status = 'completed' WHERE id = ?");
                $stmt->execute([$record_id]);
                echo json_encode(['success' => true, 'record_id' => $record_id, 'status' => 'completed']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid record_id']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
