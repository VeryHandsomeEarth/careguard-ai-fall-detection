<?php
/*
 * config/geo.php — Hybrid Positioning: Real GPS + Wi-Fi Geolocation
 */

require_once __DIR__ . '/db.php';

function resolveDeviceLocation(?array $wifiAps = null, ?string $clientIp = null, ?string $bssid = null, ?string $ssid = null): ?array {
    $pdo = getDB();

    // 1. ตรวจสอบ BSSID (MAC Address) ของ Wi-Fi ที่เชื่อมต่ออยู่โดยตรง (ถ้าเป็น Hotspot มือถือ ต้องสดใหม่ไม่เกิน 180 วินาที)
    if (!empty($bssid)) {
        $cleanBssid = normalizeMac($bssid);
        if ($cleanBssid) {
            $stmt = $pdo->prepare("SELECT lat, lng, accuracy_m, label, ssid, updated_at FROM wifi_locations WHERE bssid = ? LIMIT 1");
            $stmt->execute([$cleanBssid]);
            $row = $stmt->fetch();
            if ($row && !empty($row['lat']) && !empty($row['lng'])) {
                $lbl = $row['label'] ?? '';
                $isMobileHotspot = (stripos($lbl, 'hotspot') !== false || stripos($lbl, 'มือถือ') !== false || stripos($ssid ?? '', 'hotspot') !== false || stripos($ssid ?? '', 'VeryHandsome') !== false);
                $isExpired = false;
                if ($isMobileHotspot && !empty($row['updated_at'])) {
                    $age = time() - strtotime($row['updated_at']);
                    if ($age > 180) {
                        $isExpired = true;
                    }
                }
                if (!$isExpired) {
                    return [
                        'lat'             => (float)$row['lat'],
                        'lng'             => (float)$row['lng'],
                        'accuracy_m'      => (float)$row['accuracy_m'],
                        'location_source' => $isMobileHotspot ? 'hotspot' : 'wifi',
                        'label'           => $row['label'] ?: 'Wi-Fi ประจำอุปกรณ์',
                        'ssid'            => $row['ssid'] ?: $ssid
                    ];
                }
            }
        }
    }

    // 2. ตรวจสอบเฉพาะกรณีที่เชื่อมต่อกับเราเตอร์ของ มทร.อีสาน ขอนแก่น (RMUTI / ECP) โดยตรงเท่านั้น (ห้ามตรวจจาก Neighbor APs ที่สแกนเจอข้างบ้าน)
    if (!empty($ssid)) {
        $candUpper = strtoupper(trim($ssid));
        $isEcp   = (strpos($candUpper, 'ECP') !== false || strpos($candUpper, 'SERVERECP') !== false);
        $isRmuti = (strpos($candUpper, 'RMUTI') !== false || strpos($candUpper, 'IOR-RMUTI') !== false || strpos($candUpper, 'RMUTI-ONE') !== false);

        if ($isEcp || $isRmuti) {
            $latEcp = 16.430400;
            $lngEcp = 102.863600;
            $lblEcp = $isEcp 
                ? "อาคาร 18 วศ.อิเล็กทรอนิกส์และคอมพิวเตอร์ (ECP) มทร.อีสาน ($ssid)" 
                : "มทร.อีสาน วิทยาเขตขอนแก่น ($ssid)";
            return [
                'lat'             => $latEcp,
                'lng'             => $lngEcp,
                'accuracy_m'      => $isEcp ? 12.0 : 20.0,
                'location_source' => 'wifi',
                'label'           => $lblEcp,
                'ssid'            => $ssid
            ];
        }
    }

    // 3. ตรวจสอบ BSSID จากรายการ Wi-Fi APs ที่สแกนพบรอบตัว (ถ้าเป็น Hotspot มือถือ ต้องสดใหม่ไม่เกิน 180 วินาที)
    if (!empty($wifiAps) && is_array($wifiAps)) {
        foreach ($wifiAps as $ap) {
            $mac = normalizeMac($ap['macAddress'] ?? ($ap['bssid'] ?? ''));
            if ($mac) {
                $stmt = $pdo->prepare("SELECT lat, lng, accuracy_m, label, ssid, updated_at FROM wifi_locations WHERE bssid = ? LIMIT 1");
                $stmt->execute([$mac]);
                $row = $stmt->fetch();
                if ($row && !empty($row['lat']) && !empty($row['lng'])) {
                    $lbl = $row['label'] ?? '';
                    $isMobileHotspot = (stripos($lbl, 'hotspot') !== false || stripos($lbl, 'มือถือ') !== false || stripos($row['ssid'] ?? '', 'hotspot') !== false || stripos($row['ssid'] ?? '', 'VeryHandsome') !== false);
                    if ($isMobileHotspot && !empty($row['updated_at']) && (time() - strtotime($row['updated_at'])) > 180) {
                        continue; // ข้าม Hotspot มือถือที่ไม่มีการซิงค์สดใหม่เกิน 3 นาที
                    }
                    return [
                        'lat'             => (float)$row['lat'],
                        'lng'             => (float)$row['lng'],
                        'accuracy_m'      => (float)$row['accuracy_m'],
                        'location_source' => $isMobileHotspot ? 'hotspot' : 'wifi',
                        'label'           => $row['label'] ?: 'บ้านผู้ใช้งาน (Wi-Fi AP)',
                        'ssid'            => $row['ssid'] ?: ($ap['ssid'] ?? '')
                    ];
                }
            }
        }
    }

    // 4. ตรวจสอบจากชื่อ SSID ของเครือข่าย Wi-Fi ที่เคยบันทึกไว้ (เฉพาะที่ไม่ใช่ Hotspot มือถือ)
    if (!empty($ssid) && strlen($ssid) > 1 && stripos($ssid, 'hotspot') === false && stripos($ssid, 'VeryHandsome') === false) {
        $stmt = $pdo->prepare("SELECT lat, lng, accuracy_m, label FROM wifi_locations WHERE ssid = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$ssid]);
        $row = $stmt->fetch();
        if ($row && !empty($row['lat']) && !empty($row['lng'])) {
            return [
                'lat'             => (float)$row['lat'],
                'lng'             => (float)$row['lng'],
                'accuracy_m'      => (float)$row['accuracy_m'],
                'location_source' => 'wifi',
                'label'           => $row['label'] ?: "Wi-Fi: $ssid",
                'ssid'            => $ssid
            ];
        }
    }

    // 4.5 ตรวจสอบพิกัดจากเราเตอร์รอบตัว (Wi-Fi Positioning ผ่าน Unwired Labs & Google)
    $externalWifi = resolveExternalWifiLocation($wifiAps, $bssid);
    if ($externalWifi !== null) {
        return $externalWifi;
    }

    // 5. ตรวจสอบพิกัดบ้านล่าสุดที่ผู้ใช้เคยตั้งค่าไว้ (wifi_locations) ที่ไม่ใช่ RMUTI เก่า และไม่ใช่ Hotspot มือถือ
    $stmt = $pdo->query("SELECT lat, lng, accuracy_m, label FROM wifi_locations WHERE (ROUND(lat, 4) != 16.4304 OR ROUND(lng, 4) != 102.8636) AND lat != 0 AND label NOT LIKE '%Hotspot%' AND label NOT LIKE '%มือถือ%' ORDER BY updated_at DESC LIMIT 1");
    $latestHome = $stmt->fetch();
    if ($latestHome && !empty($latestHome['lat']) && !empty($latestHome['lng'])) {
        $hLat = (float)$latestHome['lat'];
        $hLng = (float)$latestHome['lng'];
        if (!($hLat > 13.0 && $hLat < 14.2) && !($hLat > 14.8 && $hLat < 15.3)) {
            return [
                'lat'             => $hLat,
                'lng'             => $hLng,
                'accuracy_m'      => (float)$latestHome['accuracy_m'],
                'location_source' => 'wifi',
                'label'           => $latestHome['label'] ?: 'จุดติดตั้งอุปกรณ์ (บ้านผู้ใช้งาน)'
            ];
        }
    }

    // 6. Fallback ค่าเริ่มต้นสำหรับย่านบ้านผู้ใช้งาน (ถนนศรีจันทร์ ขอนแก่น)
    return [
        'lat'             => 16.428000,
        'lng'             => 102.861700,
        'accuracy_m'      => 30.0,
        'location_source' => 'wifi',
        'label'           => 'ขอนแก่น (ย่านที่พักอาศัย/ศรีจันทร์)'
    ];
}

function saveWifiLocation(string $bssid, ?string $ssid, float $lat, float $lng, string $label = 'บ้านผู้ใช้งาน (Home)', float $accuracy = 25.0): bool {
    try {
        $pdo = getDB();
        $cleanBssid = normalizeMac($bssid);
        if (!$cleanBssid) {
            $cleanBssid = 'DEFAULT_HOME_' . substr(md5($ssid ?: 'home'), 0, 12);
        }

        $stmt = $pdo->prepare("INSERT INTO wifi_locations (bssid, ssid, lat, lng, accuracy_m, label, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                ssid        = VALUES(ssid),
                lat         = VALUES(lat),
                lng         = VALUES(lng),
                accuracy_m  = VALUES(accuracy_m),
                label       = VALUES(label),
                updated_at  = NOW()");
        return $stmt->execute([$cleanBssid, $ssid, $lat, $lng, $accuracy, $label]);
    } catch (Exception $e) {
        return false;
    }
}

function normalizeMac(string $mac): ?string {
    $mac = trim($mac);
    if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
        return str_replace('-', ':', strtoupper($mac));
    }
    return null;
}

function resolveIpLocation(string $ip): ?array {
    $cacheFile = sys_get_temp_dir() . '/geo_ip_' . md5($ip) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 7200)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cached['lat']) && !empty($cached['lng'])) {
            return $cached;
        }
    }

    try {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,regionName,city,lat,lon,query";
        $json = @file_get_contents($url, false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['status']) && $data['status'] === 'success') {
                $res = [
                    'lat'             => (float)$data['lat'],
                    'lng'             => (float)$data['lon'],
                    'accuracy_m'      => 1500.0,
                    'location_source' => 'ip',
                    'city'            => $data['city'] ?? '',
                    'country'         => $data['country'] ?? 'Thailand'
                ];
                @file_put_contents($cacheFile, json_encode($res));
                return $res;
            }
        }
    } catch (Exception $e) {}

    return null;
}

function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) return $_SERVER['HTTP_X_REAL_IP'];
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function isPrivateIp(string $ip): bool {
    return !filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
}

function resolveExternalWifiLocation(?array $wifiAps, ?string $connectedBssid = null): ?array {
    $wifiList = [];
    if (!empty($connectedBssid)) {
        $norm = normalizeMac($connectedBssid);
        if ($norm) $wifiList[] = ['bssid' => strtolower($norm), 'signal' => -55];
    }
    if (!empty($wifiAps) && is_array($wifiAps)) {
        foreach ($wifiAps as $ap) {
            $mac = normalizeMac($ap['macAddress'] ?? ($ap['bssid'] ?? ''));
            if ($mac) {
                $wifiList[] = [
                    'bssid'  => strtolower($mac),
                    'signal' => (int)($ap['signalStrength'] ?? ($ap['rssi'] ?? -65))
                ];
            }
        }
    }

    if (empty($wifiList)) return null;

    // ระบบแคชผลลัพธ์พิกัด Wi-Fi เพื่อไม่ให้ยิง API ภายนอกซ้ำๆ ทุกวินาที (หน่วงเวลา)
    $cacheKey  = md5(json_encode($wifiList));
    $cacheFile = sys_get_temp_dir() . '/wifi_geo_' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        $cached = @json_decode(@file_get_contents($cacheFile), true);
        if ($cached === 'NONE') return null;
        if (is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) return $cached;
    }

    // 1. ลองค้นหาผ่าน Unwired Labs API (ใช้ Token ที่ระบุ)
    $unwiredToken = getenv('UNWIRED_TOKEN') ?: 'pk.8c90c906d873d84b92bea5169357ddc9';
    if (!empty($unwiredToken)) {
        try {
            $ch = curl_init('https://us1.unwiredlabs.com/v2/process.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'token'   => $unwiredToken,
                'wifi'    => array_slice($wifiList, 0, 10),
                'address' => 1
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $resp = curl_exec($ch);
            curl_close($ch);

            if ($resp) {
                $data = json_decode($resp, true);
                if (!empty($data['status']) && $data['status'] === 'ok' && !empty($data['lat']) && !empty($data['lon'])) {
                    $lat  = (float)$data['lat'];
                    $lng  = (float)$data['lon'];
                    $acc  = (float)($data['accuracy'] ?? 30.0);
                    $addr = $data['address'] ?? 'Unwired Labs Wi-Fi';
                    if (!empty($connectedBssid)) {
                        @saveWifiLocation($connectedBssid, null, $lat, $lng, "Wi-Fi: $addr", $acc);
                    }
                    $res = [
                        'lat'             => $lat,
                        'lng'             => $lng,
                        'accuracy_m'      => $acc,
                        'location_source' => 'wifi',
                        'label'           => "พิกัดจากเราเตอร์รอบตัว (Unwired Labs: $addr)"
                    ];
                    @file_put_contents($cacheFile, json_encode($res));
                    return $res;
                }
            }
        } catch (Exception $e) {}
    }

    // 2. ลองค้นหาผ่าน Google Geolocation API (หากเปิดใช้งาน)
    $googleKey = getenv('GOOGLE_MAPS_API_KEY') ?: 'AIzaSyB-hdmZUZkKx_S31eXhFxZFr9AvC3lFI4c';
    if (!empty($googleKey)) {
        try {
            $gAps = [];
            foreach ($wifiList as $w) {
                $gAps[] = [
                    'macAddress'     => $w['bssid'],
                    'signalStrength' => $w['signal']
                ];
            }
            $ch = curl_init("https://www.googleapis.com/geolocation/v1/geolocate?key={$googleKey}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'wifiAccessPoints' => array_slice($gAps, 0, 10)
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $resp = curl_exec($ch);
            curl_close($ch);

            if ($resp) {
                $data = json_decode($resp, true);
                if (!empty($data['location']['lat']) && !empty($data['location']['lng'])) {
                    $lat = (float)$data['location']['lat'];
                    $lng = (float)$data['location']['lng'];
                    $acc = (float)($data['accuracy'] ?? 25.0);
                    if (!empty($connectedBssid)) {
                        @saveWifiLocation($connectedBssid, null, $lat, $lng, "Wi-Fi: Google Geolocation", $acc);
                    }
                    $res = [
                        'lat'             => $lat,
                        'lng'             => $lng,
                        'accuracy_m'      => $acc,
                        'location_source' => 'wifi',
                        'label'           => "พิกัดจากเราเตอร์รอบตัว (Google Geolocation)"
                    ];
                    @file_put_contents($cacheFile, json_encode($res));
                    return $res;
                }
            }
        } catch (Exception $e) {}
    }

    @file_put_contents($cacheFile, json_encode('NONE'));
    return null;
}

