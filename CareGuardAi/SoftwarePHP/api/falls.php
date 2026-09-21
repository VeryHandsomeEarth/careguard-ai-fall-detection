<?php
/*
 * api/falls.php — Fall Events API
 * POST /api/falls               — บันทึกการล้ม / ยืนยันการช่วยเหลือ
 * GET  /api/falls               — ดึงรายการล้ม
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/geo.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/api/falls';

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    rateLimit(120);
    $pdo = getDB();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Init: ' . $e->getMessage()]);
    exit;
}

// ตรวจสอบและเพิ่มคอลัมน์อัตโนมัติทีละคอลัมน์
function ensureFallColumns($pdo) {
    try {
        $cols = $pdo->query("DESCRIBE fall_events")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fall_type', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN fall_type VARCHAR(32) NOT NULL DEFAULT 'fall_general'");
        }
        if (!in_array('fall_type_name', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN fall_type_name VARCHAR(64) NOT NULL DEFAULT 'การล้มทั่วไป'");
        }
        if (!in_array('confidence', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN confidence FLOAT NOT NULL DEFAULT 0.0");
        }
        if (!in_array('assisted', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN assisted TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('assisted_at', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN assisted_at DATETIME NULL");
        }
        if (!in_array('offline_recorded', $cols)) {
            $pdo->exec("ALTER TABLE fall_events ADD COLUMN offline_recorded TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $ignored) {}
}

ensureFallColumns($pdo);

// ==================== POST / PUT — บันทึกการล้ม / ยืนยันการช่วยเหลือ ====================
if ($method === 'POST' || $method === 'PUT') {
    try {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        // ยืนยันการช่วยเหลือ (จากปุ่ม D18 หรือกดบนหน้าเว็บ)
        $action = $_GET['action'] ?? ($body['action'] ?? ($body['event_type'] ?? ''));

        if ($action === 'assist' || $action === 'fall_assisted') {
            ensureFallColumns($pdo);
            $fallId = (int)($_GET['id'] ?? ($body['id'] ?? 0));
            $devId  = sanitize($body['device_id'] ?? 'ESP32_001');

            try {
                if ($fallId > 0) {
                    $stmt = $pdo->prepare("UPDATE fall_events SET assisted = 1, assisted_at = NOW() WHERE id = ?");
                    $stmt->execute([$fallId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE fall_events SET assisted = 1, assisted_at = NOW() WHERE device_id = ? AND assisted = 0 ORDER BY timestamp DESC LIMIT 1");
                    $stmt->execute([$devId]);
                }
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'message' => 'Assistance confirmed']);
            exit;
        }

        $deviceId = requireApiKey();

        if (!$body) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $deviceId        = sanitize($body['device_id']        ?? $deviceId);
        $mac             = strtoupper(trim($body['mac_address'] ?? ''));
        $fallType        = strtolower(trim(sanitize($body['fall_type'] ?? 'fall_general')));
        if ($fallType === 'fall_left') $fallType = 'fall_lateral_left';
        if ($fallType === 'fall_right') $fallType = 'fall_lateral_right';

        $stdTypeNames = [
            'fall_forward'       => 'ล้มไปข้างหน้า (Fall Forward)',
            'fall_backward'      => 'ล้มไปข้างหลัง (Fall Backward)',
            'fall_lateral_left'  => 'ล้มไปด้านข้างซ้าย (Fall Lateral Left)',
            'fall_lateral_right' => 'ล้มไปด้านข้างขวา (Fall Lateral Right)',
            'fall_vertical'      => 'ล้มแนวดิ่ง/ทรุดตัว (Vertical Fall)',
            'fall_general'       => 'การล้มทั่วไป (General Fall)',
        ];

        $fallTypeName = sanitize($body['fall_type_name'] ?? '');
        if (empty($fallTypeName) || strpos($fallTypeName, '?') !== false || $fallTypeName === 'การล้มทั่วไป' || !isset($stdTypeNames[$fallType])) {
            $fallTypeName = $stdTypeNames[$fallType] ?? 'การล้มทั่วไป (General Fall)';
        }
        $confidence      = (float)($body['confidence']        ?? 0.0);
        $severity        = sanitize($body['severity']         ?? 'medium');
        $severityScore   = (float)($body['severity_score']    ?? 0.5);
        $accX            = (float)($body['acceleration_x']    ?? 0);
        $accY            = (float)($body['acceleration_y']    ?? 0);
        $accZ            = (float)($body['acceleration_z']    ?? 0);
        $lat             = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng             = isset($body['lng']) ? (float)$body['lng'] : null;
        $assisted        = !empty($body['assisted']) ? 1 : 0;
        $offlineRecorded = !empty($body['offline_recorded']) ? 1 : 0;

        // ซิงค์ device_id กับ user_devices ตาม MAC Address อัตโนมัติ
        if (!empty($mac)) {
            try {
                $pdo->prepare("UPDATE user_devices SET device_id = ? WHERE mac_address = ? AND device_id != ?")
                    ->execute([$deviceId, $mac, $deviceId]);
            } catch (Exception $e) {}
        }

        
        $locationSource = sanitize($body['location_source'] ?? 'gps');
        $address = null;

        // ลำดับความสำคัญ: GPS จริงเป็นหลัก
        if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0 && $locationSource === 'gps') {
            $locationSource = 'gps';
            $address = "🛰 พิกัดดาวเทียม GPS NEO-7M จริง";
        } elseif ($lat !== null && $lng !== null && $lat != 0 && $lng != 0 && $locationSource === 'hotspot') {
            $locationSource = 'hotspot';
            $address = "📱 พิกัดสดจากมือถือ (Hotspot)";
        } else {
            // ตรวจสอบพิกัดสดจากมือถือ Hotspot ก่อน (สำหรับ Hardware 2 หรือ Hardware 1 ในอาคาร)
            $hotspotFile = sys_get_temp_dir() . '/hotspot_gps_' . $deviceId . '.json';
            if (!file_exists($hotspotFile)) {
                $hotspotFile = sys_get_temp_dir() . '/hotspot_gps_latest.json';
            }
            $useHotspot = false;
            if (file_exists($hotspotFile)) {
                $hs = @json_decode(@file_get_contents($hotspotFile), true);
                if (!empty($hs['lat']) && !empty($hs['lng']) && (time() - ($hs['timestamp'] ?? 0)) < 7200) {
                    $lat            = (float)$hs['lat'];
                    $lng            = (float)$hs['lng'];
                    $locationSource = 'hotspot';
                    $address        = "📱 พิกัดสดจากมือถือ (Hotspot)";
                    $useHotspot     = true;
                }
            }

            if (!$useHotspot) {
                // โหมด Wi-Fi Geolocation (ในบ้าน/อาคาร)
                require_once __DIR__ . '/../config/geo.php';
                $geo = resolveDeviceLocation(
                    $body['wifi_aps'] ?? null,
                    getClientIp(),
                    $body['wifi_bssid'] ?? null,
                    $body['wifi_ssid'] ?? null
                );
                if ($geo !== null) {
                    $lat            = $geo['lat'];
                    $lng            = $geo['lng'];
                    $locationSource = 'wifi';
                    $address        = $geo['label'] ?? "📶 Wi-Fi Geolocation (ในบ้าน/อาคาร)";
                } else {
                    $lat            = null;
                    $lng            = null;
                    $locationSource = 'unknown';
                    $address        = "รอสัญญาณ GPS/Wi-Fi";
                }
            }
        }

        // กรองและแก้ไขพิกัดกรุงเทพหรือโคราชที่หลุดมาจาก IP cellular ของค่ายมือถือ (เฉพาะกรณีไม่ใช่ Hotspot GPS สดจากมือถือ)
        if ($locationSource !== 'hotspot' && $lat !== null && $lng !== null) {
            if (($lat > 13.0 && $lat < 14.2) || ($lat > 14.8 && $lat < 15.3)) {
                $lat            = 16.428000;
                $lng            = 102.861700;
                $locationSource = 'wifi';
                $address        = "ย่านศรีจันทร์ ขอนแก่น (บ้านผู้ใช้งาน)";
            }
        }

        if (!in_array($severity, ['low','medium','high'])) $severity = 'medium';
        $severityScore = max(0, min(1, $severityScore));
        $confidence    = max(0, min(1, $confidence));

        // ป้องกันการบันทึกเหตุการณ์การล้มซ้ำซ้อนจากอุปกรณ์เดียวกัน
        $occurredAgo = max(0, (int)($body['occurred_seconds_ago'] ?? 0));
        if ($offlineRecorded && $occurredAgo > 0) {
            $checkDup = $pdo->prepare("SELECT id FROM fall_events 
                                       WHERE device_id = ? 
                                         AND fall_type = ? 
                                         AND ABS(acceleration_z - ?) < 0.1
                                         AND ABS(TIMESTAMPDIFF(SECOND, timestamp, DATE_SUB(NOW(), INTERVAL ? SECOND))) <= 5
                                       LIMIT 1");
            $checkDup->execute([$deviceId, $fallType, $accZ, $occurredAgo]);
        } else {
            $checkDup = $pdo->prepare("SELECT id FROM fall_events 
                                       WHERE device_id = ? 
                                         AND fall_type = ? 
                                         AND ABS(acceleration_z - ?) < 0.1
                                         AND timestamp >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
                                       LIMIT 1");
            $checkDup->execute([$deviceId, $fallType, $accZ]);
        }
        $dup = $checkDup->fetch();
        if ($dup) {
            echo json_encode([
                'success' => true,
                'id' => (int)$dup['id'],
                'fall_type' => $fallType,
                'fall_type_name' => $fallTypeName,
                'message' => 'Duplicate fall ignored (already recorded)',
                'duplicate' => true
            ]);
            exit;
        }

        if ($offlineRecorded && $occurredAgo > 0) {
            $stmt = $pdo->prepare("INSERT INTO fall_events
                (device_id, fall_type, fall_type_name, confidence, severity, severity_score, acceleration_x, acceleration_y, acceleration_z, lat, lng, address, location_source, assisted, assisted_at, offline_recorded, timestamp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?=1, NOW(), NULL), ?, DATE_SUB(NOW(), INTERVAL ? SECOND))");
            $stmt->execute([
                $deviceId, $fallType, $fallTypeName, $confidence,
                $severity, $severityScore, $accX, $accY, $accZ,
                $lat, $lng, $address, $locationSource, $assisted, $assisted, $offlineRecorded, $occurredAgo
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO fall_events
                (device_id, fall_type, fall_type_name, confidence, severity, severity_score, acceleration_x, acceleration_y, acceleration_z, lat, lng, address, location_source, assisted, assisted_at, offline_recorded)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?=1, NOW(), NULL), ?)");
            $stmt->execute([
                $deviceId, $fallType, $fallTypeName, $confidence,
                $severity, $severityScore, $accX, $accY, $accZ,
                $lat, $lng, $address, $locationSource, $assisted, $assisted, $offlineRecorded
            ]);
        }
        $newId = $pdo->lastInsertId();

        // อัปเดต daily_summary ทันที
        try {
            $pdo->prepare("INSERT INTO daily_summary (device_id, summary_date, fall_count)
                VALUES (?, CURDATE(), 1)
                ON DUPLICATE KEY UPDATE fall_count = fall_count + 1")
                ->execute([$deviceId]);
        } catch (Exception $e) {}

        $respPayload = json_encode([
            'success' => true,
            'id' => (int)$newId,
            'fall_type' => $fallType,
            'fall_type_name' => $fallTypeName,
            'assisted' => $assisted,
            'message' => 'Fall recorded successfully'
        ]);

        // หากรันบน PHP-FPM ส่ง Response กลับให้บอร์ดทันที (<50ms) บอร์ดจะได้ไม่ต้องค้างรอส่ง Telegram
        if (function_exists('fastcgi_finish_request')) {
            echo $respPayload;
            fastcgi_finish_request();
        }

        // แจ้งเตือนผ่าน Telegram Bot ทันทีเมื่อเกิดการล้ม
        try {
            require_once __DIR__ . '/../services/notifications.php';
            // ค้นหาโปรไฟล์ของผู้ใช้ที่ผูกกับ device_id หรือ MAC Address นี้
            $pStmt = $pdo->prepare("SELECT p.email, p.phone, p.name, p.emergency_name, p.emergency_phone, p.emergency_email, p.emergency_contact, p.telegram_chat_id 
                                    FROM user_devices d 
                                    INNER JOIN user_profile p ON d.user_id = p.user_id 
                                    WHERE d.device_id = ? OR (d.mac_address = ? AND d.mac_address != '')
                                    ORDER BY (d.device_id = ?) DESC 
                                    LIMIT 1");
            $pStmt->execute([$deviceId, $mac, $deviceId]);
            $prof = $pStmt->fetch(PDO::FETCH_ASSOC);

            // หากยังไม่พบ ให้ดึง user_profile ล่าสุดที่ระบุ telegram_chat_id ไว้
            if (!$prof || empty($prof['telegram_chat_id'])) {
                $pFallback = $pdo->query("SELECT email, phone, name, emergency_name, emergency_phone, emergency_email, emergency_contact, telegram_chat_id 
                                          FROM user_profile 
                                          WHERE telegram_chat_id IS NOT NULL AND telegram_chat_id != '' 
                                          ORDER BY updated_at DESC LIMIT 1");
                $fallbackProf = $pFallback ? $pFallback->fetch(PDO::FETCH_ASSOC) : null;
                if ($fallbackProf) $prof = $fallbackProf;
            }

            $fallAlertData = [
                'device_id'        => $deviceId,
                'user_name'        => $prof['name'] ?? $deviceId,
                'fall_type_name'   => $fallTypeName,
                'severity'         => $severity,
                'severity_score'   => $severityScore,
                'acceleration_x'   => $accX,
                'acceleration_y'   => $accY,
                'acceleration_z'   => $accZ,
                'lat'              => $lat,
                'lng'              => $lng,
                'location_source'  => $locationSource,
                'emergency_name'   => $prof['emergency_name'] ?? '',
                'emergency_phone'  => $prof['emergency_phone'] ?? ($prof['emergency_contact'] ?? ''),
                'telegram_chat_id' => $prof['telegram_chat_id'] ?? ''
            ];

            // 1. ส่ง Telegram Bot
            sendTelegram($fallAlertData, !empty($prof['telegram_chat_id']) ? $prof['telegram_chat_id'] : null);

            // 2. ส่ง Email แจ้งเตือนเมื่อเป็นการล้มระดับรุนแรง
            if ($severity === 'high' || $severityScore >= 0.75) {
                if ($prof) {
                    $recipients = [];
                    if (!empty($prof['emergency_email']) && filter_var($prof['emergency_email'], FILTER_VALIDATE_EMAIL)) {
                        $recipients[] = $prof['emergency_email'];
                    }
                    if (!empty($prof['email']) && filter_var($prof['email'], FILTER_VALIDATE_EMAIL) && !in_array($prof['email'], $recipients)) {
                        $recipients[] = $prof['email'];
                    }

                    if (!empty($recipients)) {
                        $to = implode(', ', $recipients);
                        $subject = "🚨 [CareGuard ฉุกเฉิน] ตรวจพบการล้มรุนแรงของผู้ใช้งาน: " . ($prof['name'] ?: $deviceId);
                        $mag = round(sqrt($accX*$accX + $accY*$accY + $accZ*$accZ), 1);
                        $gVal = round($mag / 9.81, 2);
                        $emerName = $prof['emergency_name'] ?: 'ญาติ / ผู้ดูแล';
                        $emerPhone = $prof['emergency_phone'] ?: ($prof['emergency_contact'] ?: 'ไม่ได้ระบุ');
                        $emerEmail = $prof['emergency_email'] ?: 'ไม่ได้ระบุ';

                        $msg = "แจ้งเตือนเหตุฉุกเฉิน CareGuard AI\n\n"
                             . "🚨 ตรวจพบเหตุการณ์การล้มระดับรุนแรง (HIGH SEVERITY FALL)\n"
                             . "👤 ผู้ใช้งาน: " . ($prof['name'] ?: $deviceId) . "\n"
                             . "📞 เบอร์โทรผู้ใช้งาน: " . ($prof['phone'] ?: 'ไม่ได้ระบุ') . "\n\n"
                             . "👨‍👩‍👧 ข้อมูลญาติ / ผู้ติดต่อฉุกเฉิน:\n"
                             . "   • ชื่อญาติ/ผู้ดูแล: " . $emerName . "\n"
                             . "   • เบอร์โทรติดต่อญาติ: " . $emerPhone . "\n"
                             . "   • อีเมลญาติ: " . $emerEmail . "\n\n"
                             . "⚠️ ทิศทางการล้ม: " . $fallTypeName . "\n"
                             . "💥 แรงกระแทก: " . $gVal . " G (" . $mag . " m/s²)\n"
                             . "⏰ เวลาที่เกิดเหตุ: " . date('Y-m-d H:i:s') . "\n";
                        if ($lat && $lng) {
                            $msg .= "📍 พิกัดจุดเกิดเหตุ: https://maps.google.com/?q={$lat},{$lng}\n";
                        }
                        $msg .= "\nกรุณาเข้าตรวจสอบหรือติดต่อผู้ใช้งานทันที!\nCareGuard AI System";
                        $headers = "From: alert@youngza.com\r\nReply-To: alert@youngza.com\r\nContent-Type: text/plain; charset=UTF-8";
                        @mail($to, $subject, $msg, $headers);
                    }
                }
            }
        } catch (Exception $ignored) {}

        if (!function_exists('fastcgi_finish_request')) {
            echo $respPayload;
        }
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'POST: ' . $e->getMessage()]);
        exit;
    }
}

// ==================== GET — ดึงรายการล้ม ====================
if ($method === 'GET') {
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $user_id = $_SESSION['user_id'] ?? null;
        $userRole = $_SESSION['user']['role'] ?? 'user';

        $limit    = min((int)($_GET['limit'] ?? 50), 100);
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $offset   = ($page - 1) * $limit;
        $date     = $_GET['date'] ?? null;
        $fallType = $_GET['fall_type'] ?? null;
        $assisted = isset($_GET['assisted']) && $_GET['assisted'] !== '' ? (int)$_GET['assisted'] : null;

        $where  = [];
        $params = [];

        // กรองเฉพาะอุปกรณ์ที่ผู้ใช้รายนี้ผูกไว้ (หากเป็น admin จะเห็นเหตุการณ์ทุกตัวในระบบ)
        if ($userRole !== 'admin') {
            if (!$user_id) {
                echo json_encode([
                    'success' => true,
                    'data'    => [],
                    'total'   => 0,
                    'page'    => $page,
                    'limit'   => $limit,
                    'message' => 'No devices bound to this user'
                ]);
                exit;
            }
            try {
                $devStmt = $pdo->prepare("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices WHERE user_id = ?");
                $devStmt->execute([$user_id]);
                $userDevices = $devStmt->fetchAll(PDO::FETCH_ASSOC);

                $userDevIds = [];
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

                if (!empty($userDevIds)) {
                    $inPlaceholders = implode(',', array_fill(0, count($userDevIds), '?'));
                    $where[] = "device_id IN ($inPlaceholders)";
                    foreach ($userDevIds as $did) {
                        $params[] = $did;
                    }
                } else {
                    // ผู้ใช้ยังไม่เคยผูกอุปกรณ์ใดๆ -> ไม่แสดงเหตุการณ์ของผู้อื่น
                    echo json_encode([
                        'success' => true,
                        'data'    => [],
                        'total'   => 0,
                        'page'    => $page,
                        'limit'   => $limit,
                        'message' => 'No devices bound to this user'
                    ]);
                    exit;
                }
            } catch (Exception $e) {}
        }

        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[]  = '(DATE(timestamp) = ? OR DATE(CONVERT_TZ(timestamp, "+00:00", "+07:00")) = ?)';
            $params[] = $date;
            $params[] = $date;
        }
        if ($fallType) {
            $where[]  = 'fall_type = ?';
            $params[] = $fallType;
        }
        if ($assisted !== null) {
            $where[]  = 'assisted = ?';
            $params[] = $assisted;
        }
        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = $pdo->prepare("SELECT COUNT(*) FROM fall_events $whereStr");
        $total->execute($params);

        $stmt = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS seconds_ago FROM fall_events $whereStr ORDER BY timestamp DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'data'    => $stmt->fetchAll(),
            'total'   => (int)$total->fetchColumn(),
            'page'    => $page,
            'limit'   => $limit,
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'GET: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(405);
