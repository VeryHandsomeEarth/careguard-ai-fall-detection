<?php
/*
 * api/gps.php — Hybrid Positioning (Real GPS Primary + Wi-Fi Geolocation Indoor)
 * POST /api/gps?action=calibrate — บันทึกพิกัดตำแหน่งบ้าน (Wi-Fi Geolocation Home)
 * POST /api/gps                  — บันทึกพิกัด GPS จริง หรือ Wi-Fi Geolocation + ก้าวเดิน
 * GET  /api/gps/latest           — ตำแหน่งล่าสุด
 * GET  /api/gps/track            — เส้นทางวันนี้ (เฉพาะพิกัดจริง)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/geo.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? '/api/gps';
$parts  = array_values(array_filter(explode('/', $uri)));
$sub    = $parts[2] ?? null;

header('Content-Type: application/json; charset=utf-8');
rateLimit(180);
$pdo = getDB();

// ==================== POST — Calibrate หรือ บันทึกพิกัด ====================
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $_GET['action'] ?? ($body['action'] ?? '');

    // ==================== Auto Hotspot Phone GPS Sync ====================
    if ($action === 'hotspot_sync' || $action === 'phone_gps') {
        $lat       = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng       = isset($body['lng']) ? (float)$body['lng'] : null;
        $acc       = isset($body['accuracy']) ? (float)$body['accuracy'] : 10.0;
        $targetDev = sanitize($body['device_id'] ?? '');

        // ถ้าระบุ 'auto' หรือเว้นว่าง ให้ดึงอุปกรณ์จริงของผู้ใช้ที่ล็อกอินอยู่
        if (empty($targetDev) || $targetDev === 'auto') {
            if (session_status() === PHP_SESSION_NONE) session_start();
            $userId = $_SESSION['user_id'] ?? null;
            if ($userId) {
                try {
                    $uStmt = $pdo->prepare("SELECT device_id FROM user_devices WHERE user_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
                    $uStmt->execute([$userId]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if (!empty($uRow['device_id'])) {
                        $targetDev = $uRow['device_id'];
                    }
                } catch (Exception $e) {}
            }
            if (empty($targetDev) || $targetDev === 'auto') {
                $latestDev = $pdo->query("SELECT device_id FROM gps_log ORDER BY timestamp DESC LIMIT 1")->fetchColumn();
                $targetDev = $latestDev ?: 'ESP32_HW2';
            }
        }

        if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0 && abs($lat) <= 90) {
            $hotspotData = [
                'device_id' => $targetDev,
                'lat'       => $lat,
                'lng'       => $lng,
                'accuracy'  => $acc,
                'timestamp' => time(),
                'datetime'  => date('Y-m-d H:i:s')
            ];
            @file_put_contents(sys_get_temp_dir() . '/hotspot_gps_' . $targetDev . '.json', json_encode($hotspotData));
            @file_put_contents(sys_get_temp_dir() . '/hotspot_gps_latest.json', json_encode($hotspotData));

            $stmtLog = $pdo->prepare("INSERT INTO gps_log
                (device_id, lat, lng, speed_kmh, altitude_m, satellites, hdop, location_source, step_count, walk_distance)
                VALUES (?, ?, ?, 0, 0, 0, ?, 'hotspot', 0, 0)");
            $stmtLog->execute([$targetDev, $lat, $lng, $acc]);

            try {
                $devRow = $pdo->prepare("SELECT last_bssid, last_ssid FROM user_devices WHERE device_id = ? LIMIT 1");
                $devRow->execute([$targetDev]);
                $dr = $devRow->fetch();
                if ($dr && !empty($dr['last_bssid'])) {
                    saveWifiLocation($dr['last_bssid'], $dr['last_ssid'], $lat, $lng, 'พิกัดสดจากมือถือ (Hotspot)', $acc);
                }
            } catch (Exception $e) {}

            echo json_encode([
                'success'         => true,
                'device_id'       => $targetDev,
                'lat'             => $lat,
                'lng'             => $lng,
                'location_source' => 'hotspot',
                'message'         => 'Hotspot phone GPS synced to ' . $targetDev . ' successfully'
            ]);
            exit;
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
            exit;
        }
    }

    // ฟังก์ชัน Calibrate / ผูกพิกัดจริงเข้ากับ Wi-Fi Router ของ Hardware 1 & 2
    if ($action === 'calibrate' || $action === 'set_home') {
        $lat   = isset($body['lat']) ? (float)$body['lat'] : null;
        $lng   = isset($body['lng']) ? (float)$body['lng'] : null;
        $bssid = sanitize($body['bssid'] ?? ($body['wifi_bssid'] ?? ''));
        $ssid  = sanitize($body['ssid'] ?? ($body['wifi_ssid'] ?? ''));
        $label = sanitize($body['label'] ?? 'Wi-Fi Router ประจำอุปกรณ์');
        $targetDevId = sanitize($body['device_id'] ?? '');

        if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0) {
            // 1. ถ้าไม่ได้ส่ง BSSID มาตรงๆ ให้ดึง BSSID/SSID ล่าสุดที่ Hardware กำลังเชื่อมต่ออยู่
            if (empty($bssid)) {
                $cachedWifi = @json_decode(@file_get_contents(sys_get_temp_dir() . '/last_hw_wifi.json'), true);
                if (!empty($cachedWifi['bssid'])) {
                    $bssid = $cachedWifi['bssid'];
                    if (empty($ssid)) $ssid = $cachedWifi['ssid'] ?? '';
                    if (empty($targetDevId)) $targetDevId = $cachedWifi['device_id'] ?? '';
                }
            }
            if (empty($bssid) && !empty($targetDevId)) {
                try {
                    $devRow = $pdo->prepare("SELECT last_bssid, last_ssid FROM user_devices WHERE device_id = ? LIMIT 1");
                    $devRow->execute([$targetDevId]);
                    $dr = $devRow->fetch();
                    if (!empty($dr['last_bssid'])) {
                        $bssid = $dr['last_bssid'];
                        if (empty($ssid)) $ssid = $dr['last_ssid'];
                    }
                } catch (Exception $e) {}
            }

            // 2. ผูกพิกัดจริงเข้ากับ Wi-Fi Router (BSSID & SSID) ใน wifi_locations ทันที
            saveWifiLocation($bssid, $ssid, $lat, $lng, $label, 15.0);

            // 3. ล้างพิกัดเก่า มทร.อีสาน ขอนแก่น ออกจากฐานข้อมูลเฉพาะเจาะจง
            $cleanBssidNorm = normalizeMac($bssid);
            if ($cleanBssidNorm) {
                $pdo->exec("DELETE FROM wifi_locations WHERE (ROUND(lat, 4) = 16.4304 AND ROUND(lng, 4) = 102.8636) AND bssid != " . $pdo->quote($cleanBssidNorm));
            } else {
                $pdo->exec("DELETE FROM wifi_locations WHERE (ROUND(lat, 4) = 16.4304 AND ROUND(lng, 4) = 102.8636)");
            }
            $pdo->exec("DELETE FROM gps_log WHERE (ROUND(lat, 4) = 16.4304 AND ROUND(lng, 4) = 102.8636) OR lat = 16.44 OR lat = 13.7101 OR lat = 13.7618 OR lat = 0");

            // 4. บันทึกพิกัดใหม่ลง gps_log ทันที เพื่อให้อัปเดตแผนที่สด (Real-Time) ทันที
            if (empty($targetDevId)) {
                $latestDev = $pdo->query("SELECT device_id FROM gps_log ORDER BY timestamp DESC LIMIT 1")->fetchColumn();
                $targetDevId = $latestDev ?: 'ESP32_HW2';
            }

            $stmtLog = $pdo->prepare("INSERT INTO gps_log
                (device_id, lat, lng, speed_kmh, altitude_m, satellites, hdop, location_source, step_count, walk_distance)
                VALUES (?, ?, ?, 0, 0, 0, 15.0, 'wifi', 0, 0)");
            $stmtLog->execute([$targetDevId, $lat, $lng]);

            echo json_encode([
                'success'         => true,
                'lat'             => $lat,
                'lng'             => $lng,
                'bssid'           => $bssid,
                'ssid'            => $ssid,
                'device_id'       => $targetDevId,
                'location_source' => 'wifi',
                'message'         => 'ผูกพิกัดจริงเข้ากับ Wi-Fi Router ของอุปกรณ์เรียบร้อยแล้ว และอัปเดตแผนที่สดทันที'
            ]);
            exit;
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'พิกัดละติจูดและลองจิจูดไม่ถูกต้อง']);
            exit;
        }
    }

    // ล้างพิกัดเก่า มทร.อีสาน และรีเซ็ตพิกัดทดสอบทั้งหมด
    if ($action === 'clear_calibration' || $action === 'reset_calibration' || $action === 'clear_rmuti') {
        $pdo->exec("DELETE FROM wifi_locations WHERE (lat BETWEEN 16.420000 AND 16.445000 AND lng BETWEEN 102.850000 AND 102.875000) OR lat = 16.44 OR lat = 13.7101 OR lat = 13.7618 OR lat = 0");
        $pdo->exec("DELETE FROM gps_log WHERE (lat BETWEEN 16.420000 AND 16.445000 AND lng BETWEEN 102.850000 AND 102.875000) OR lat = 16.44 OR lat = 13.7101 OR lat = 13.7618 OR lat = 0");
        $pdo->exec("DELETE FROM fall_events WHERE (lat BETWEEN 16.420000 AND 16.445000 AND lng BETWEEN 102.850000 AND 102.875000) OR address LIKE '%[Calibrated]%'");
        echo json_encode([
            'success' => true,
            'message' => 'ล้างพิกัดเก่า มทร.อีสาน และพิกัดทดสอบออกจากระบบเรียบร้อยแล้ว'
        ]);
        exit;
    }

    if ($action === 'clear_all_gps') {
        $pdo->exec("TRUNCATE TABLE gps_log");
        echo json_encode([
            'success' => true,
            'message' => 'ล้างประวัติพิกัด GPS ทั้งหมดเรียบร้อยแล้ว'
        ]);
        exit;
    }

    if ($action === 'clear_hotspot_cache') {
        @unlink(sys_get_temp_dir() . '/hotspot_gps_latest.json');
        @unlink(sys_get_temp_dir() . '/hotspot_gps_ESP32_001.json');
        @unlink(sys_get_temp_dir() . '/hotspot_gps_ESP32_HW2.json');
        $pdo->exec("DELETE FROM wifi_locations WHERE label LIKE '%Hotspot%' OR label LIKE '%มือถือ%' OR ssid = 'VeryHandsome'");
        echo json_encode(['success' => true, 'message' => 'Cleared stale hotspot cache']);
        exit;
    }

    requireApiKey();

    $deviceId       = sanitize($body['device_id']    ?? 'ESP32_001');
    $mac            = strtoupper(trim($body['mac_address'] ?? ''));
    $speedKmh       = max(0, (float)($body['speed_kmh']    ?? 0));
    $stepCount      = max(0, (int)($body['step_count']   ?? 0));
    $walkDist       = max(0, (float)($body['walk_distance'] ?? 0));
    $wifiSsid       = sanitize($body['wifi_ssid'] ?? ($body['ssid'] ?? ''));
    $wifiBssid      = sanitize($body['wifi_bssid'] ?? ($body['bssid'] ?? ''));
    $wifiRssi       = isset($body['wifi_rssi']) ? (int)$body['wifi_rssi'] : null;
    $locationSource = sanitize($body['location_source'] ?? 'gps');

    // บันทึกสถานะ Wi-Fi Router ล่าสุดของ Hardware ลง Cache และตาราง user_devices
    if (!empty($wifiBssid)) {
        @file_put_contents(sys_get_temp_dir() . '/last_hw_wifi.json', json_encode([
            'bssid'     => $wifiBssid,
            'ssid'      => $wifiSsid,
            'device_id' => $deviceId,
            'mac'       => $mac,
            'time'      => time()
        ]));

        try {
            $colsUd = $pdo->query("DESCRIBE user_devices")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('last_bssid', $colsUd)) {
                $pdo->exec("ALTER TABLE user_devices ADD COLUMN last_bssid VARCHAR(32) NULL, ADD COLUMN last_ssid VARCHAR(64) NULL");
            }
            $pdo->prepare("UPDATE user_devices SET last_bssid = ?, last_ssid = ? WHERE device_id = ? OR mac_address = ?")
                ->execute([$wifiBssid, $wifiSsid, $deviceId, $mac]);
        } catch (Exception $e) {}
    }

    // ซิงค์ device_id กับ user_devices ตาม MAC Address อัตโนมัติ
    if (!empty($mac)) {
        try {
            $pdo->prepare("UPDATE user_devices SET device_id = ? WHERE mac_address = ? AND device_id != ?")
                ->execute([$deviceId, $mac, $deviceId]);
        } catch (Exception $e) {}
    }

    $lat         = isset($body['lat']) ? (float)$body['lat'] : null;
    $lng         = isset($body['lng']) ? (float)$body['lng'] : null;
    $satellites  = isset($body['satellites']) ? (int)$body['satellites'] : 0;
    $hdop        = isset($body['hdop']) ? (float)$body['hdop'] : 99.0;
    $altitudeM   = isset($body['altitude_m']) ? (float)$body['altitude_m'] : 0.0;

    // ==================== ลำดับความสำคัญ: GPS จริงจาก Hardware 1 เป็นหลัก ====================
    if ($lat !== null && $lng !== null && $lat != 0 && $lng != 0 && $locationSource === 'gps') {
        // โหมด 1: GPS จริงจากดาวเทียม (NEO-7M) ของ Hardware 1
        $locationSource = 'gps';

        // Auto-learn: เมื่อ Hardware 1 รับสัญญาณดาวเทียมได้ ให้ผูกพิกัดเข้ากับ Wi-Fi Router อัตโนมัติ
        if (!empty($wifiBssid) && $satellites >= 3 && $hdop < 6.0) {
            saveWifiLocation($wifiBssid, $wifiSsid, $lat, $lng, "ตำแหน่งจาก Hardware 1 (GPS NEO-7M)", 15.0);
        }
    } else {
        // โหมด 2: Wi-Fi / Hotspot Geolocation (Hardware 2 หรือ Hardware 1 ขณะอยู่ในอาคาร)
        $hotspotFile = sys_get_temp_dir() . '/hotspot_gps_' . $deviceId . '.json';
        if (!file_exists($hotspotFile)) {
            $hotspotFile = sys_get_temp_dir() . '/hotspot_gps_latest.json';
        }
        $useHotspot = false;
        if (file_exists($hotspotFile)) {
            $hs = @json_decode(@file_get_contents($hotspotFile), true);
            // พิกัดสดจากมือถือ Hotspot ต้องสดใหม่ (มีอายุไม่เกิน 120 วินาที / 2 นาที) เพื่อป้องกันพิกัดเก่าค้างเมื่อผู้ใช้ย้ายที่
            if (!empty($hs['lat']) && !empty($hs['lng']) && (time() - ($hs['timestamp'] ?? 0)) < 120) {
                $lat            = (float)$hs['lat'];
                $lng            = (float)$hs['lng'];
                $locationSource = 'hotspot';
                $hdop           = (float)($hs['accuracy'] ?? 10.0);
                $useHotspot     = true;
            }
        }

        if (!$useHotspot) {
            $geo = resolveDeviceLocation($body['wifi_aps'] ?? null, getClientIp(), $wifiBssid, $wifiSsid);
            if ($geo !== null) {
                $lat            = $geo['lat'];
                $lng            = $geo['lng'];
                $locationSource = 'wifi';
                $hdop           = $geo['accuracy_m'] ?? 25.0;
            } else {
                // ยังไม่พบพิกัด Wi-Fi และ GPS ยังค้นหาดาวเทียม
                $lat            = 0.0;
                $lng            = 0.0;
                $locationSource = 'searching';
            }
        }
    }

    // กรองพิกัดกรุงเทพหรือโคราชที่อาจหลุดมาจาก IP cellular ของค่ายมือถือ (เฉพาะกรณีไม่ใช่ Hotspot GPS สดจากมือถือ)
    if ($locationSource !== 'hotspot' && (($lat > 13.0 && $lat < 14.2) || ($lat > 14.8 && $lat < 15.3))) {
        $lat = 16.428000;
        $lng = 102.861700;
        $locationSource = 'wifi';
    }

    $stmt = $pdo->prepare("INSERT INTO gps_log
        (device_id, lat, lng, speed_kmh, altitude_m, satellites, hdop, location_source, step_count, walk_distance)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$deviceId, $lat, $lng, $speedKmh, $altitudeM, $satellites, $hdop, $locationSource, $stepCount, $walkDist]);

    $pdo->prepare("INSERT INTO daily_summary (device_id, summary_date, total_steps, total_distance)
        VALUES (?, CURDATE(), ?, ?)
        ON DUPLICATE KEY UPDATE
            total_steps    = GREATEST(total_steps, VALUES(total_steps)),
            total_distance = GREATEST(total_distance, VALUES(total_distance))")
        ->execute([$deviceId, $stepCount, $walkDist]);

    echo json_encode([
        'success'         => true,
        'lat'             => $lat,
        'lng'             => $lng,
        'location_source' => $locationSource,
        'satellites'      => $satellites,
        'message'         => ($locationSource === 'gps') ? 'Hardware 1 real satellite GPS logged' : (($locationSource === 'hotspot') ? 'Phone hotspot GPS synced' : 'Hardware Wi-Fi geolocation logged')
    ]);
    exit;
}

// ==================== GET /api/gps/latest ====================
if ($method === 'GET' && $sub === 'latest') {
    $stmt = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS seconds_ago FROM gps_log ORDER BY timestamp DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // ดึงอุปกรณ์ที่ผูกกับบัญชีของผู้ใช้ปัจจุบันเท่านั้น (Admin จะเห็นอุปกรณ์ทุกตัวในระบบ)
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user_id = $_SESSION['user_id'] ?? null;
    $userRole = $_SESSION['user']['role'] ?? 'user';
    $device = null;
    $hasUserDevice = false;

    if ($userRole === 'admin') {
        // โรล admin: สามารถดูอุปกรณ์ใดก็ได้ในระบบ
        $reqDevId = !empty($_GET['device_id']) ? sanitize($_GET['device_id']) : null;
        if ($reqDevId) {
            $devStmt = $pdo->prepare("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices WHERE device_id = ? LIMIT 1");
            $devStmt->execute([$reqDevId]);
            $device = $devStmt->fetch(PDO::FETCH_ASSOC);
            if (!$device) {
                $device = ['id' => 0, 'device_id' => $reqDevId, 'device_name' => $reqDevId, 'mac_address' => '', 'device_type' => 'auto'];
            }
            $hasUserDevice = true;
        } else {
            // ดึงอุปกรณ์ของ admin เอง หรือตัวแรกที่มีในระบบ
            $devStmt = $pdo->prepare("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices WHERE user_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
            $devStmt->execute([$user_id]);
            $device = $devStmt->fetch(PDO::FETCH_ASSOC);
            if (!$device) {
                $devStmt = $pdo->query("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices ORDER BY is_primary DESC, id ASC LIMIT 1");
                $device = $devStmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($device) {
                $hasUserDevice = true;
            }
        }
    } else if ($user_id) {
        // โรล ผู้ใช้ทั่วไป: มีแค่อุปกรณ์ที่เชื่อมต่อของตัวเองเท่านั้น!
        try {
            $devStmt = $pdo->prepare("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices WHERE user_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1");
            $devStmt->execute([$user_id]);
            $device = $devStmt->fetch(PDO::FETCH_ASSOC);
            if ($device) {
                $hasUserDevice = true;
                // Auto-fix หากชื่อหรือประเภทคือ Hardware 2
                $isHw2 = ($device['device_type'] === 'hw2_wifi' || stripos($device['device_name'], 'hw2') !== false || stripos($device['device_name'], 'hardware2') !== false || stripos($device['device_name'], 'hardware 2') !== false);
                if ($isHw2 && $device['device_id'] === 'ESP32_001') {
                    $device['device_id'] = 'ESP32_HW2';
                    $pdo->prepare("UPDATE user_devices SET device_id = 'ESP32_HW2' WHERE id = ?")->execute([$device['id']]);
                }
            }
        } catch (Exception $e) {}
    }

    // หากมีอุปกรณ์ ให้ดึง log เฉพาะของ device_id นั้น
    if ($hasUserDevice && !empty($device['device_id'])) {
        $devId = $device['device_id'];
        $stmtUser = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS seconds_ago FROM gps_log WHERE device_id = ? ORDER BY timestamp DESC LIMIT 1");
        $stmtUser->execute([$devId]);
        $rowUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $curSecondsAgo = isset($rowUser['seconds_ago']) ? (int)$rowUser['seconds_ago'] : null;

        // ถ้า device ปัจจุบันออฟไลน์ (> 45 วิ หรือไม่มีข้อมูล) ให้เช็กว่ามีอุปกรณ์ตัวอื่นที่ผู้ใช้รายนี้ผูกไว้กำลังออนไลน์สดๆ อยู่หรือไม่
        if (!$rowUser || $curSecondsAgo === null || $curSecondsAgo > 45) {
            // ตรวจสอบจาก user_devices เฉพาะของ user รายนี้เท่านั้น
            $allDevs = $pdo->prepare("SELECT id, device_id, device_name, mac_address, device_type FROM user_devices WHERE user_id = ? ORDER BY is_primary DESC");
            $allDevs->execute([$user_id]);
            $userDevList = $allDevs->fetchAll(PDO::FETCH_ASSOC);

            foreach ($userDevList as $ud) {
                if ($ud['device_id'] === $devId) continue;
                $s = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS seconds_ago FROM gps_log WHERE device_id = ? ORDER BY timestamp DESC LIMIT 1");
                $s->execute([$ud['device_id']]);
                $r = $s->fetch(PDO::FETCH_ASSOC);
                if ($r && isset($r['seconds_ago']) && (int)$r['seconds_ago'] <= 60) {
                    $rowUser = $r;
                    $device = $ud;
                    break;
                }
            }
        }

        if ($rowUser) {
            // หากบันทึกล่าสุดยังไม่มีพิกัด (lat = 0) ให้ดึงพิกัดที่บันทึกได้ล่าสุดของอุปกรณ์มาแสดงอัตโนมัติ
            if ((float)($rowUser['lat'] ?? 0) == 0 || (float)($rowUser['lng'] ?? 0) == 0) {
                $lastFixStmt = $pdo->prepare("SELECT lat, lng, hdop, location_source, satellites, altitude_m, speed_kmh, timestamp AS last_fix_timestamp 
                                              FROM gps_log 
                                              WHERE device_id = ? AND lat != 0 AND lng != 0 
                                              ORDER BY timestamp DESC LIMIT 1");
                $lastFixStmt->execute([$rowUser['device_id']]);
                $lastFix = $lastFixStmt->fetch(PDO::FETCH_ASSOC);
                if ($lastFix) {
                    $rowUser['lat'] = (float)$lastFix['lat'];
                    $rowUser['lng'] = (float)$lastFix['lng'];
                    $rowUser['hdop'] = (float)$lastFix['hdop'];
                    $rowUser['location_source'] = $lastFix['location_source'];
                    $rowUser['last_fix_timestamp'] = $lastFix['last_fix_timestamp'];
                }
            }
            $row = $rowUser;
        } else {
            // หากยังไม่มีข้อมูลล่าสุดเลย ให้ดึงพิกัดล่าสุดที่เคยบันทึกได้ของอุปกรณ์นี้
            $lastKnownStmt = $pdo->prepare("SELECT *, TIMESTAMPDIFF(SECOND, timestamp, NOW()) AS seconds_ago 
                                            FROM gps_log 
                                            WHERE device_id = ? AND lat != 0 AND lng != 0 
                                            ORDER BY timestamp DESC LIMIT 1");
            $lastKnownStmt->execute([$devId]);
            $row = $lastKnownStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // หากยังไม่มีพิกัด valid (lat == 0 หรือ null) ให้คำนวณพิกัดอัตโนมัติผ่าน Wi-Fi / IP Geolocation ของอุปกรณ์
        if (empty($row) || (float)($row['lat'] ?? 0) == 0 || (float)($row['lng'] ?? 0) == 0) {
            $lastBssid = $device['last_bssid'] ?? '';
            $lastSsid  = $device['last_ssid'] ?? '';
            $autoGeo = resolveDeviceLocation(null, getClientIp(), $lastBssid, $lastSsid);
            if ($autoGeo !== null && !empty($autoGeo['lat']) && !empty($autoGeo['lng'])) {
                if (!$row) {
                    $row = [
                        'device_id'       => $devId,
                        'speed_kmh'       => 0,
                        'altitude_m'      => 0,
                        'satellites'      => 0,
                        'step_count'      => 0,
                        'walk_distance'   => 0,
                        'timestamp'       => date('Y-m-d H:i:s'),
                        'seconds_ago'     => 9999
                    ];
                }
                $row['lat']             = (float)$autoGeo['lat'];
                $row['lng']             = (float)$autoGeo['lng'];
                $row['hdop']            = (float)($autoGeo['accuracy_m'] ?? 25.0);
                $row['location_source'] = 'wifi';
            }
        }

        // กรองและแก้ไขพิกัดกรุงเทพหรือโคราชที่หลุดมาจาก IP cellular ของค่ายมือถือ
        if ($row && !empty($row['lat'])) {
            $curLat = (float)$row['lat'];
            $curLng = (float)$row['lng'];
            if (($curLat > 13.0 && $curLat < 14.2) || ($curLat > 14.8 && $curLat < 15.3)) {
                $row['lat']             = 16.428000;
                $row['lng']             = 102.861700;
                $row['location_source'] = 'wifi';
                $row['hdop']            = 20.0;
            }
        }

        $deviceName = !empty($device['device_name']) ? $device['device_name'] : $device['device_id'];
    } else {
        // ผู้ใช้รายนี้ยังไม่ได้ผูกอุปกรณ์ใดๆ เลย -> ไม่นำอุปกรณ์ของคนอื่นมาแสดง
        $deviceName = 'ยังไม่ได้เชื่อมต่ออุปกรณ์';
        $row = null;
    }

    $secondsAgo = isset($row['seconds_ago']) ? (int)$row['seconds_ago'] : null;
    $isOnline   = ($hasUserDevice && $secondsAgo !== null && $secondsAgo >= 0 && $secondsAgo <= 45);

    echo json_encode([
        'success' => true,
        'data'    => $row ?: null,
        'device'  => [
            'device_id'      => $devId ?? ($device['device_id'] ?? 'ESP32_HW2'),
            'name'           => $deviceName,
            'is_online'      => $isOnline,
            'has_device'     => $hasUserDevice,
            'seconds_ago'    => $secondsAgo,
            'mac_address'    => $device['mac_address'] ?? null,
            'device_type'    => $device['device_type'] ?? 'auto'
        ]
    ]);
    exit;
}

// ==================== GET /api/gps/track ====================
if ($method === 'GET' && $sub === 'track') {
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $deviceId = sanitize($_GET['device_id'] ?? 'ESP32_001');
    $limit    = min((int)($_GET['limit'] ?? 500), 2000);

    $stmt = $pdo->prepare("SELECT lat, lng, speed_kmh, step_count, location_source, timestamp
        FROM gps_log
        WHERE device_id = ? AND DATE(timestamp) = ? AND lat != 0 AND lng != 0
        ORDER BY timestamp ASC
        LIMIT $limit");
    $stmt->execute([$deviceId, $date]);

    echo json_encode(['success' => true, 'date' => $date, 'track' => $stmt->fetchAll()]);
    exit;
}

// ==================== GET /api/gps/router_info ====================
if ($method === 'GET' && ($sub === 'router_info' || ($_GET['action'] ?? '') === 'router_info')) {
    $cachedWifi = @json_decode(@file_get_contents(sys_get_temp_dir() . '/last_hw_wifi.json'), true) ?? [];
    $bssid = $cachedWifi['bssid'] ?? '';
    $ssid  = $cachedWifi['ssid'] ?? '';
    $devId = $cachedWifi['device_id'] ?? '';

    // ถ้าไม่มีใน temp cache ให้ลองดึงจาก user_devices
    if (empty($bssid)) {
        try {
            $rowDev = $pdo->query("SELECT device_id, last_bssid, last_ssid FROM user_devices WHERE last_bssid IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch();
            if ($rowDev) {
                $bssid = $rowDev['last_bssid'];
                $ssid  = $rowDev['last_ssid'] ?? '';
                $devId = $rowDev['device_id'] ?? '';
            }
        } catch (Exception $e) {}
    }

    $isCalibrated = false;
    $locData = null;
    if (!empty($bssid)) {
        $clean = normalizeMac($bssid);
        if ($clean) {
            $s = $pdo->prepare("SELECT lat, lng, accuracy_m, label, updated_at FROM wifi_locations WHERE bssid = ? LIMIT 1");
            $s->execute([$clean]);
            $locData = $s->fetch(PDO::FETCH_ASSOC);
            if ($locData) $isCalibrated = true;
        }
    }

    echo json_encode([
        'success'       => true,
        'device_id'     => $devId ?: 'ESP32_HW2',
        'wifi_bssid'    => $bssid,
        'wifi_ssid'     => $ssid,
        'is_calibrated' => $isCalibrated,
        'router_location' => $locData
    ]);
    exit;
}

// ==================== GET /api/gps ====================
if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM gps_log ORDER BY timestamp DESC LIMIT 1");
    $stmt->execute();
    echo json_encode(['success' => true, 'data' => $stmt->fetch() ?: null]);
    exit;
}

jsonError(405, 'Method not allowed');
