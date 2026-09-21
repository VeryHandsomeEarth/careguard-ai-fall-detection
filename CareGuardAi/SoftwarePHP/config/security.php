<?php
/*
 * config/security.php — API Key validation + Rate Limiting
 */

require_once __DIR__ . '/db.php';

/**
 * ตรวจสอบ API Key จาก Header X-API-Key
 * ถ้าไม่ส่ง key มา → อนุญาตแต่ใช้ device_id default
 * ถ้าส่ง key มา → ตรวจสอบว่าถูกต้อง
 */
function requireApiKey(): string {
    $key = $_SERVER['HTTP_X_API_KEY']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_GET['api_key'] ?? '');

    // ตัดคำว่า "Bearer " ออก
    $key = preg_replace('/^Bearer\s+/i', '', trim($key));

    // ถ้าไม่มี key → อนุญาตแบบ anonymous (สำหรับ first-time setup)
    if (empty($key)) {
        error_log('[Security] Request without API key from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return 'ESP32_001'; // default device
    }

    // ตรวจสอบรูปแบบ (ป้องกัน injection)
    if (!preg_match('/^[a-zA-Z0-9_\-]{8,128}$/', $key)) {
        jsonError(401, 'Invalid API key format.');
    }

    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            "SELECT device_id FROM api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            jsonError(401, 'Invalid or inactive API key.');
        }

        // อัปเดต last_used
        $pdo->prepare("UPDATE api_keys SET last_used = NOW() WHERE api_key = ?")
            ->execute([$key]);

        return $row['device_id'];
    } catch (Exception $e) {
        jsonError(500, 'Auth error: ' . $e->getMessage());
    }
}

/**
 * Rate limiting อย่างง่าย (ใช้ไฟล์ tmp)
 * จำกัด N request ต่อนาทีต่อ IP
 */
function rateLimit(int $maxPerMinute = 60): void {
    // อุปกรณ์ฮาร์ดแวร์ที่ส่ง X-API-Key หรือมีเซสชัน ไม่ถูกจำกัด rate limit
    if (!empty($_SERVER['HTTP_X_API_KEY']) || (!empty($_SESSION['user_id']))) {
        return;
    }

    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $ip);
    $file = sys_get_temp_dir() . '/rl_' . $safe . '.json';

    $now  = time();
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : ['count' => 0, 'window' => $now];

    if ($now - $data['window'] > 60) {
        $data = ['count' => 1, 'window' => $now];
    } else {
        $data['count']++;
    }

    file_put_contents($file, json_encode($data));

    if ($data['count'] > $maxPerMinute) {
        header('Retry-After: 60');
        jsonError(429, 'Too many requests. Try again in 1 minute.');
    }
}

/**
 * Sanitize string input
 */
function sanitize(string $value, int $maxLen = 255): string {
    return substr(trim(strip_tags($value)), 0, $maxLen);
}

/**
 * Validate float in range
 */
function validateFloat($val, float $min, float $max): float {
    $f = (float) $val;
    if ($f < $min || $f > $max) {
        jsonError(422, "Value $f out of range [$min, $max]");
    }
    return $f;
}

function jsonError(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
