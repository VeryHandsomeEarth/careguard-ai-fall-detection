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
            $trial_id = !empty($_POST['trial_id']) ? intval($_POST['trial_id']) : null;
            $stmt = $pdo->prepare("
                INSERT INTO cd_records (subject_id, device_id, fall_type_id, trial_id, trial_number, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                intval($_POST['subject_id'] ?? 0),
                intval($_POST['device_id'] ?? 0),
                intval($_POST['fall_type_id'] ?? 0),
                $trial_id,
                intval($_POST['trial_number'] ?? 1),
                $_POST['notes'] ?? '',
                $_POST['status'] ?? 'completed'
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
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

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
