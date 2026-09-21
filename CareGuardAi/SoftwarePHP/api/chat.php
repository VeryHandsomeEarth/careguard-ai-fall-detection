<?php
/*
 * api/chat.php — AI Health Advisor (Gemini 2.0 Flash)
 * ใช้ข้อมูลผู้ใช้จาก user_profile + ข้อมูลสุขภาพจาก gps_log/fall_events
 */

session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', $uri)));
$sub    = $parts[2] ?? null;

try { $pdo = getDB(); } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
}

// ==================== GET /api/chat/history ====================
if ($method === 'GET' && ($sub === 'history' || ($_GET['action'] ?? '') === 'history')) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $stmt = $pdo->prepare("SELECT role, content, timestamp FROM chat_history WHERE session_id = ? ORDER BY id DESC LIMIT 50");
    $stmt->execute(['u' . $user_id]);
    echo json_encode(['success' => true, 'data' => array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC))]);
    exit;
}

// ==================== DELETE /api/chat/history ====================
if ($method === 'DELETE') {
    $user_id = $_SESSION['user_id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM chat_history WHERE session_id = ?");
    $stmt->execute(['u' . $user_id]);
    echo json_encode(['success' => true, 'message' => 'ล้างประวัติของตนเองแล้ว']);
    exit;
}

// ==================== POST /api/chat ====================
if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $message = trim($body['message'] ?? '');
    $clientContext = $body['context'] ?? [];

    if (!$message) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ต้องระบุข้อความ']); exit;
    }

    try {
        $user_id  = $_SESSION['user_id'] ?? 0;
        $userRole = $_SESSION['user']['role'] ?? 'user';
        $profile  = getUserProfile($pdo, $user_id);
        $context  = getHealthContext($pdo, $profile, $user_id, $userRole, $clientContext);

        $stmt = $pdo->prepare("SELECT role, content FROM chat_history WHERE session_id = ? ORDER BY id DESC LIMIT 10");
        $stmt->execute(['u' . $user_id]);
        $hist = $stmt->fetchAll();
        $history = array_reverse($hist);

        $aiResponse = callGemini($message, $context, $history, $profile, $userRole);

        // --- ระบบดักจับการตั้งเป้าหมายด้วย AI ---
        if (preg_match('/\[SET_GOAL:\s*({.*?})\s*\]/is', $aiResponse, $matches)) {
            try {
                $goalData = json_decode($matches[1], true);
                if ($goalData) {
                    $newStep = (int)($goalData['step_goal'] ?? $profile['step_goal']);
                    $newDist = (float)($goalData['dist_goal'] ?? $profile['distance_goal']);
                    $pdo->prepare("UPDATE user_profile SET step_goal = ?, distance_goal = ? WHERE user_id = ?")
                        ->execute([$newStep, $newDist, $user_id]);
                    // เอา tag คำสั่งซ่อนออกจากข้อความที่จะตอบ
                    $aiResponse = trim(str_replace($matches[0], "\n\n*(✅ อัปเดตเป้าหมาย: ก้าว $newStep, ระยะทาง $newDist เมตร เรียบร้อยแล้ว)*\n\n", $aiResponse));
                }
            } catch (Exception $e) {}
        }
        // ----------------------------------------

        $ins = $pdo->prepare("INSERT INTO chat_history (session_id, role, content) VALUES (?, ?, ?)");
        $sid = 'u' . $user_id;
        $ins->execute([$sid, 'user', $message]);
        $ins->execute([$sid, 'assistant', $aiResponse]);

        echo json_encode(['success' => true, 'response' => $aiResponse]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);

// ==================== Helper Functions ====================

function getUserProfile(PDO $pdo, int $userId): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['name'=>'','age'=>0,'weight'=>0,'height'=>0,'gender'=>'','health_conditions'=>'','step_goal'=>10000,'distance_goal'=>5000];
    } catch (Exception $e) {
        return ['name'=>'','age'=>0,'weight'=>0,'height'=>0,'gender'=>'','health_conditions'=>'','step_goal'=>10000,'distance_goal'=>5000];
    }
}

function getHealthContext(PDO $pdo, array $profile, int $userId, string $userRole, array $clientContext = []): string {
    try {
        $lines = [];
        $bmi = ($profile['weight'] > 0 && $profile['height'] > 0) 
            ? round($profile['weight'] / (($profile['height']/100) ** 2), 1) : null;

        $displayName = !empty($profile['name']) ? $profile['name'] : ($_SESSION['user']['username'] ?? 'ผู้ใช้งาน');
        $lines[] = "👤 ผู้ใช้งาน: {$displayName}" . ($userRole === 'admin' ? " [👑 ผู้ดูแลระบบ (Admin)]" : " [ผู้ใช้ทั่วไป]");
        if ($profile['age'] > 0) $lines[] = "🎂 อายุ: {$profile['age']} ปี";
        if ($profile['gender']) $lines[] = "⚧ เพศ: {$profile['gender']}";
        if ($profile['weight'] > 0) $lines[] = "⚖️ น้ำหนัก: {$profile['weight']} กก.";
        if ($profile['height'] > 0) $lines[] = "📏 ส่วนสูง: {$profile['height']} ซม.";
        if ($bmi) $lines[] = "📊 BMI: $bmi";
        if ($profile['health_conditions']) $lines[] = "🏥 โรคประจำตัว: {$profile['health_conditions']}";
        $lines[] = "🎯 เป้าหมายก้าว: " . number_format($profile['step_goal']) . " ก้าว/วัน";
        $lines[] = "📍 เป้าหมายระยะทาง: " . number_format($profile['distance_goal']) . " ม./วัน";
        $lines[] = "";

        // กรณีเป็น Admin ที่ต้องการดูภาพรวมระบบ
        if ($userRole === 'admin') {
            $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $totalDevs  = $pdo->query("SELECT COUNT(*) FROM user_devices")->fetchColumn();
            $stmtFalls  = $pdo->query("SELECT COUNT(*) AS total_falls,
                                       COALESCE(SUM(CASE WHEN assisted=1 THEN 1 ELSE 0 END), 0) AS assisted_cnt,
                                       COALESCE(SUM(CASE WHEN assisted=0 THEN 1 ELSE 0 END), 0) AS pending_cnt
                                       FROM fall_events 
                                       WHERE (DATE(timestamp) = CURDATE() OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = CURDATE())");
            $fallSummary = $stmtFalls->fetch(PDO::FETCH_ASSOC);

            $lines[] = "👑 ข้อมูลภาพรวมทั้งระบบ (โหมดผู้ดูแลระบบ):";
            $lines[] = "  - ผู้ใช้งานทั้งหมดในระบบ: {$totalUsers} คน";
            $lines[] = "  - อุปกรณ์ที่ลงทะเบียน: {$totalDevs} เครื่อง";
            $lines[] = "  - สถิติการล้มรวมทั้งระบบวันนี้: " . ($fallSummary['total_falls'] ?? 0) . " ครั้ง (ช่วยเหลือแล้ว " . ($fallSummary['assisted_cnt'] ?? 0) . " ครั้ง, รอดำเนินการ " . ($fallSummary['pending_cnt'] ?? 0) . " ครั้ง)";
            return implode("\n", $lines);
        }

        // =========================================================================
        // กรณีผู้ใช้ทั่วไป: กรองคำนวณเฉพาะอุปกรณ์ที่ผูกกับผู้ใช้รายนี้ (อุปกรณ์ของตัวเอง)
        // =========================================================================
        $devStmt = $pdo->prepare("SELECT id, device_id, device_name, device_type, mac_address FROM user_devices WHERE user_id = ?");
        $devStmt->execute([$userId]);
        $userDevices = $devStmt->fetchAll(PDO::FETCH_ASSOC);

        $userDevIds = [];
        $devNames = [];
        foreach ($userDevices as $ud) {
            $did = $ud['device_id'];
            $isHw2 = ($ud['device_type'] === 'hw2_wifi' || stripos($ud['device_name'], 'hw2') !== false || stripos($ud['device_name'], 'hardware2') !== false || stripos($ud['device_name'], 'hardware 2') !== false);
            if ($isHw2 && $did === 'ESP32_001') {
                $did = 'ESP32_HW2';
            }
            $userDevIds[] = $did;
            if ($isHw2) $userDevIds[] = 'ESP32_HW2';
            $devNames[] = $ud['device_name'] . " (" . $did . ")";
        }
        $userDevIds = array_values(array_unique(array_filter($userDevIds)));

        $lines[] = "📱 อุปกรณ์ที่เชื่อมต่อกับบัญชีของคุณ: " . (!empty($devNames) ? implode(", ", $devNames) : "ยังไม่มีอุปกรณ์ที่ผูก");

        $fallCount = 0;
        $assistedCount = 0;
        $pendingCount = 0;
        $stepCount = 0;
        $walkDist = 0.0;
        $weeklySteps = 0;
        $recentFallsList = [];

        if (!empty($userDevIds)) {
            $inClause = implode(',', array_fill(0, count($userDevIds), '?'));

            // 1. นับจำนวนครั้งการล้มวันนี้ เฉพาะอุปกรณ์ของผู้ใช้
            $fStmt = $pdo->prepare("SELECT COUNT(*) AS cnt,
                                           COALESCE(SUM(CASE WHEN assisted = 1 THEN 1 ELSE 0 END), 0) AS assisted_cnt,
                                           COALESCE(SUM(CASE WHEN assisted = 0 THEN 1 ELSE 0 END), 0) AS pending_cnt
                                    FROM fall_events 
                                    WHERE (DATE(timestamp) = CURDATE() OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = CURDATE())
                                      AND device_id IN ($inClause)");
            $fStmt->execute($userDevIds);
            $fRow = $fStmt->fetch(PDO::FETCH_ASSOC);
            if ($fRow) {
                $fallCount     = (int)$fRow['cnt'];
                $assistedCount = (int)$fRow['assisted_cnt'];
                $pendingCount  = (int)$fRow['pending_cnt'];
            }

            // 2. ดึงสถิติก้าวเดินและระยะทางวันนี้ เฉพาะอุปกรณ์ของผู้ใช้
            $gStmt = $pdo->prepare("SELECT COALESCE(MAX(step_count), 0) AS steps,
                                           COALESCE(MAX(walk_distance), 0) AS dist
                                    FROM gps_log 
                                    WHERE (DATE(timestamp) = CURDATE() OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = CURDATE())
                                      AND device_id IN ($inClause)");
            $gStmt->execute($userDevIds);
            $gRow = $gStmt->fetch(PDO::FETCH_ASSOC);
            if ($gRow) {
                $stepCount = (int)$gRow['steps'];
                $walkDist  = (float)$gRow['dist'];
            }

            // 3. ดึงค่าเฉลี่ย 7 วัน เฉพาะอุปกรณ์ของผู้ใช้
            $wStmt = $pdo->prepare("SELECT ROUND(AVG(total_steps)) AS avg_steps
                                    FROM daily_summary 
                                    WHERE summary_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                      AND device_id IN ($inClause)");
            $wStmt->execute($userDevIds);
            $wRow = $wStmt->fetch(PDO::FETCH_ASSOC);
            if ($wRow && $wRow['avg_steps']) {
                $weeklySteps = (int)$wRow['avg_steps'];
            }

            // 4. รายการล้มล่าสุด 3 รายการของอุปกรณ์ผู้ใช้รายนี้
            $rfStmt = $pdo->prepare("SELECT fall_type_name, confidence, severity, acceleration_z,
                                            DATE_FORMAT(CONVERT_TZ(timestamp, '+00:00', '+07:00'), '%H:%i:%s') AS fall_time,
                                            assisted
                                     FROM fall_events 
                                     WHERE (DATE(timestamp) = CURDATE() OR DATE(CONVERT_TZ(timestamp, '+00:00', '+07:00')) = CURDATE())
                                       AND device_id IN ($inClause)
                                     ORDER BY timestamp DESC LIMIT 3");
            $rfStmt->execute($userDevIds);
            $recentFallsList = $rfStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // ซิงค์สถิติจากหน้าจอของผู้ใช้ (client context) หากมีค่ามากกว่า 0
        if (!empty($clientContext['falls']) && is_numeric($clientContext['falls']) && (int)$clientContext['falls'] > 0) {
            $fallCount = (int)$clientContext['falls'];
        }
        if (!empty($clientContext['steps']) && is_numeric($clientContext['steps']) && (int)$clientContext['steps'] > 0) {
            $stepCount = (int)$clientContext['steps'];
        }

        $lines[] = "";
        $lines[] = "📊 สถิติสุขภาพวันนี้ (คำนวณจากอุปกรณ์ของผู้ใช้รายนี้เท่านั้น):";
        $lines[] = "  - จำนวนการล้มวันนี้: {$fallCount} ครั้ง (ได้รับการช่วยเหลือแล้ว {$assistedCount} ครั้ง, รอดำเนินการ {$pendingCount} ครั้ง)";
        $lines[] = "  - ก้าวเดินวันนี้: {$stepCount} ก้าว (" . round(($stepCount / max(1, $profile['step_goal'])) * 100) . "% ของเป้าหมาย)";
        $lines[] = "  - ระยะทางสะสมวันนี้: " . number_format($walkDist, 1) . " ม.";
        if ($weeklySteps > 0) {
            $lines[] = "  - ก้าวเดินเฉลี่ย 7 วัน: {$weeklySteps} ก้าว/วัน";
        }

        if (!empty($recentFallsList)) {
            $lines[] = "";
            $lines[] = "🚨 รายละเอียดการล้มล่าสุดวันนี้:";
            foreach ($recentFallsList as $rf) {
                $statusText = $rf['assisted'] ? "✅ ได้รับการช่วยเหลือแล้ว" : "⏳ รอดำเนินการ";
                $lines[] = "    • เวลา {$rf['fall_time']} น. | รูปแบบ: {$rf['fall_type_name']} | ความมั่นใจ: " . round($rf['confidence'] * 100) . "% | สถานะ: {$statusText}";
            }
        }

        return implode("\n", $lines);
    } catch (Exception $e) {
        return 'ไม่มีข้อมูลสุขภาพ';
    }
}

function callGemini(string $message, string $context, array $history, array $profile, string $userRole = 'user'): string {
    $rawKeys = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
    $keys = array_values(array_filter(array_map('trim', explode(',', $rawKeys))));
    
    // Default fallback keys if none configured
    $defaultKeys = [
        'AQ.Ab8RN6JkQdEtofeZE3aOAz5-eoWEzrlAEmOQuH87BrCB8289GA',
        'AIzaSyB-hdmZUZkKx_S31eXhFxZFr9AvC3lFI4c'
    ];
    if (empty($keys)) {
        $keys = $defaultKeys;
    } else {
        foreach ($defaultKeys as $dk) {
            if (!in_array($dk, $keys, true)) {
                $keys[] = $dk;
            }
        }
    }

    $systemPrompt = "คุณเป็น AI Health Advisor ผู้เชี่ยวชาญด้านสุขภาพสำหรับผู้สูงอายุและระบบตรวจจับการล้ม CareGuard AI

บทบาท:
1. ให้คำแนะนำเรื่องสุขภาพ ป้องกันการล้ม ออกกำลังกาย และวิเคราะห์เหตุการณ์ล้ม
2. รายงานข้อมูลสถิติสุขภาพ ก้าวเดิน ระยะทาง และจำนวนครั้งที่ล้ม โดยต้องยึดตาม 'ข้อมูลสุขภาพ' ด้านล่างนี้อย่างเคร่งครัด 100%
3. ช่วยตั้งเป้าหมายสุขภาพที่เหมาะสมตามอายุ น้ำหนัก โรคประจำตัว
4. ให้กำลังใจและแสดงความห่วงใยผู้ใช้งาน

กฎสำคัญ:
- ตอบเป็นภาษาไทยเสมอ ใช้ emoji เช่น 💪 👣 🎯 🚨
- **การตอบจำนวนครั้งการล้มและก้าวเดิน**: ต้องตอบตรงตามตัวเลขในส่วน 'ข้อมูลสุขภาพ' ด้านล่างนี้เท่านั้น ห้ามสร้างตัวเลขขึ้นเอง และห้ามนำตัวเลขจากภายนอกมาตอบเด็ดขาด
- หากผู้ใช้ถามว่า 'วันนี้มีเหตุการณ์การล้มหรือไม่' หรือ 'ล้มกี่ครั้ง': ให้อ้างอิงจากตัวเลข 'จำนวนการล้มวันนี้' ในข้อมูลสุขภาพ หากเป็น 0 ให้ตอบว่าไม่พบการล้ม หากมากกว่า 0 ให้บอกจำนวนครั้งตามข้อมูลสุขภาพและรายละเอียดตามข้อมูลที่มี พร้อมถามไถ่ความปลอดภัย
- คำแนะนำต้องปฏิบัติได้จริง เหมาะกับข้อมูลผู้ใช้
- หากมีโรคประจำตัว ให้ระวังการแนะนำที่อาจขัดกัน
- หากอาการรุนแรง แนะนำพบแพทย์
- หากจากการพูดคุยคุณต้องการ 'ตั้งเป้าหมายก้าวและระยะทางใหม่ให้ผู้ใช้' ให้ตอบกลับสอดแทรกคำสั่งนี้เข้าไปในข้อความ (ห้ามขึ้นบรรทัดใหม่ตรงกลาง JSON):
[SET_GOAL: {\"step_goal\":5000, \"dist_goal\":2500}]

ข้อมูลสุขภาพ:
$context";

    $contents = [];
    foreach ($history as $h) {
        $role = ($h['role'] === 'assistant') ? 'model' : 'user';
        $contents[] = ['role' => $role, 'parts' => [['text' => $h['content']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    $payload = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
    ]);

    $models = ['gemini-3.6-flash', 'gemini-flash-latest', 'gemini-3.5-flash'];

    foreach ($keys as $apiKey) {
        if (!$apiKey || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') continue;

        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if (!$err && $httpCode === 200 && $result) {
                $data = json_decode($result, true);
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text) {
                    return $text;
                }
            }
        }
    }

    return getFallbackResponse($message, $context);
}

function getFallbackResponse(string $msg, string $ctx): string {
    $l = mb_strtolower($msg);
    if (str_contains($l, 'ล้ม'))
        return "🏥 **ป้องกันการล้ม**\n1. ติดราวจับในห้องน้ำ\n2. เพิ่มแสงสว่าง\n3. บริหารกล้ามเนื้อขา\n4. สวมรองเท้ากันลื่น\n\n$ctx";
    if (str_contains($l, 'ก้าว') || str_contains($l, 'เดิน'))
        return "👣 **เป้าหมายก้าวเดิน**\nแนะนำ 6,000-10,000 ก้าว/วัน\n\n$ctx\n\n💡 เดินช้าๆ สม่ำเสมอ ดื่มน้ำเพียงพอ";
    if (str_contains($l, 'เป้าหมาย') || str_contains($l, 'goal'))
        return "🎯 **ตั้งเป้าหมาย**\nไปที่หน้า \"สุขภาพ\" แล้วกดแก้ไขโปรไฟล์ เพื่อตั้งเป้าหมายก้าวเดินและระยะทาง\n\n$ctx";
    return "🤖 ถามเกี่ยวกับสุขภาพได้เลยครับ\n- ป้องกันการล้ม\n- ออกกำลังกาย\n- ตั้งเป้าหมาย\n\n$ctx";
}
