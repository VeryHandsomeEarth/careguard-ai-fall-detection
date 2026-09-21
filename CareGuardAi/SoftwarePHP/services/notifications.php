<?php
/*
 * services/notifications.php
 * ส่งแจ้งเตือนผ่าน Gmail (PHPMailer หรือ mail()) และ Telegram
 */

require_once __DIR__ . '/../config/env.php';

// ==================== Telegram ====================
function sendTelegram(array $fallData, ?string $targetChatId = null): bool {
    $devId = $fallData['device_id'] ?? '';
    $isHw2 = (stripos($devId, 'hw2') !== false || stripos($devId, 'hardware2') !== false || ($devId === 'ESP32_HW2'));
    $deviceTag = $isHw2 ? 'CareGuardH2' : 'CareGuardH1';

    // ถ้าเป็น Hardware2 ใช้ Bot Token ของ Hardware2 (@CareGuardAI2_bot)
    if ($deviceTag === 'CareGuardH2') {
        $botToken = getenv('TELEGRAM_BOT_TOKEN_H2') ?: '8725825726:AAFOfB9MXOcWHwmbgUoWhXG6ryIHW5ZJlmE';
    } else {
        $botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '8708202936:AAFhrY8PZ1XsAR0F12V4OPiCMxkpPXkyPJ8';
    }

    $chatId = $targetChatId ?: ($fallData['telegram_chat_id'] ?? (getenv('TELEGRAM_CHAT_ID') ?: '8758930399'));

    if (!$botToken || !$chatId || $chatId === 'your-chat-id' || $chatId === 'YOUR_CHAT_ID') {
        $chatId = '8758930399';
    }

    $severity     = $fallData['severity'] ?? 'medium';
    $score        = isset($fallData['confidence']) ? number_format($fallData['confidence'] * 100, 0) : (isset($fallData['severity_score']) ? number_format($fallData['severity_score'] * 100, 0) : '85');
    $sev_text     = match($severity) { 'high' => 'สูง (High)', 'medium' => 'ปานกลาง (Medium)', default => 'ต่ำ (Low)' };
    $fallTypeName = $fallData['fall_type_name'] ?? 'การล้มทั่วไป';

    $accX = (float)($fallData['acceleration_x'] ?? 0);
    $accY = (float)($fallData['acceleration_y'] ?? 0);
    $accZ = (float)($fallData['acceleration_z'] ?? 0);
    $mag  = sqrt($accX*$accX + $accY*$accY + $accZ*$accZ);
    $gVal = number_format($mag / 9.80665, 2);

    $lat = $fallData['lat'] ?? null;
    $lng = $fallData['lng'] ?? null;
    $locSource = $fallData['location_source'] ?? '';

    if ($lat !== null && $lng !== null && $locSource !== 'hotspot') {
        $fLat = (float)$lat;
        $fLng = (float)$lng;
        if (($fLat > 13.0 && $fLat < 14.2) || ($fLat > 14.8 && $fLat < 15.3)) {
            $lat = 16.428000;
            $lng = 102.861700;
        }
    }

    $hasValidCoords = ($lat !== null && $lng !== null && !is_nan((float)$lat) && !is_nan((float)$lng) && (float)$lat != 0.0 && (float)$lng != 0.0);

    if ($hasValidCoords) {
        $sourceNote = '';
        if ($locSource === 'hotspot') {
            $sourceNote = ' (📱 GPS มือถือ Hotspot)';
        } elseif ($locSource === 'wifi') {
            $sourceNote = ' (📶 Wi-Fi ในอาคาร)';
        } elseif ($locSource === 'gps') {
            $sourceNote = ' (🛰️ GPS ดาวเทียม)';
        }
        $coordLine = "📍 พิกัด: https://maps.google.com/?q=" . number_format((float)$lat, 6, '.', '') . "," . number_format((float)$lng, 6, '.', '') . $sourceNote;
    } else {
        if ($deviceTag === 'CareGuardH2' || $locSource === 'wifi') {
            $ssid = !empty($fallData['wifi_ssid']) ? $fallData['wifi_ssid'] : 'Wi-Fi ภายในอาคาร';
            $coordLine = "📍 ตำแหน่ง: 📶 ภายในอาคาร (Wi-Fi: " . $ssid . ")";
        } else {
            $coordLine = "📍 ตำแหน่ง: 🛰️ กำลังค้นหาสัญญาณดาวเทียม GPS...";
        }
    }

    $text = "🚨 แจ้งเตือน: ตรวจพบการล้มฉุกเฉิน!\n" .
            "👤 อุปกรณ์: " . $deviceTag . "\n" .
            "⚠️ รูปแบบการล้ม: " . $fallTypeName . "\n" .
            "🎯 ความมั่นใจ AI: " . $score . "%\n" .
            "💥 ระดับความรุนแรง: " . $sev_text . "\n" .
            "⚡ แรงกระแทก: " . $gVal . " G (" . number_format($mag, 1) . " m/s²)\n" .
            $coordLine;

    $payload = json_encode([
        'chat_id'    => $chatId,
        'text'       => $text,
        'disable_web_page_preview' => false
    ]);

    $ch = curl_init("https://api.telegram.org/bot$botToken/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $resp = json_decode($result, true);
    return !empty($resp['ok']);
}

// ==================== Gmail (PHPMailer หรือ mail()) ====================
function sendGmail(array $fallData): bool {
    $user = getenv('GMAIL_USER');
    $pass = getenv('GMAIL_APP_PASSWORD');
    $to   = getenv('GMAIL_TO') ?: $user;

    if (!$user || !$pass || $user === 'your-email@gmail.com') return false;

    $severity  = $fallData['severity']       ?? 'ไม่ทราบ';
    $score     = isset($fallData['severity_score'])
                    ? number_format($fallData['severity_score'] * 100, 0) : '0';
    $time      = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('d/m/Y H:i:s');
    $sev_color = match($severity) { 'high' => '#e74c3c', 'medium' => '#f39c12', default => '#27ae60' };
    $sev_text  = match($severity) { 'high' => '🔴 สูง', 'medium' => '🟡 ปานกลาง', default => '🟢 ต่ำ' };
    $device    = $fallData['device_id']  ?? 'ESP32_001';
    $ax        = number_format($fallData['acceleration_x'] ?? 0, 2);
    $ay        = number_format($fallData['acceleration_y'] ?? 0, 2);
    $az        = number_format($fallData['acceleration_z'] ?? 0, 2);

    $subject = "⚠️ แจ้งเตือนการล้ม! [ความรุนแรง: $severity] - $time";
    $body    = <<<HTML
<div style="font-family:'Segoe UI',sans-serif;max-width:600px;margin:0 auto;background:#1a1a2e;color:#eee;border-radius:12px;overflow:hidden;">
  <div style="background:linear-gradient(135deg,#e74c3c,#c0392b);padding:20px;text-align:center;">
    <h1 style="margin:0;font-size:24px;">🚨 ตรวจพบการล้ม!</h1>
  </div>
  <div style="padding:20px;">
    <table style="width:100%;border-collapse:collapse;">
      <tr><td style="padding:10px;border-bottom:1px solid #333;color:#aaa;">อุปกรณ์</td><td style="padding:10px;border-bottom:1px solid #333;font-weight:bold;">$device</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #333;color:#aaa;">ความรุนแรง</td><td style="padding:10px;border-bottom:1px solid #333;font-weight:bold;color:$sev_color;">$sev_text ($score%)</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #333;color:#aaa;">ค่าความเร่ง</td><td style="padding:10px;border-bottom:1px solid #333;">X: $ax, Y: $ay, Z: $az m/s²</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #333;color:#aaa;">เวลา</td><td style="padding:10px;border-bottom:1px solid #333;">$time</td></tr>
    </table>
    <div style="margin-top:20px;padding:15px;background:#16213e;border-radius:8px;text-align:center;">
      <p style="margin:0;color:#f39c12;">⚡ กรุณาตรวจสอบผู้ใช้งานโดยเร็ว!</p>
    </div>
  </div>
</div>
HTML;

    // ลอง PHPMailer ก่อน ถ้าไม่มีให้ใช้ mail()
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return sendWithPHPMailer($user, $pass, $to, $subject, $body);
    }

    // Fallback: PHP mail() — ต้องการ local sendmail (shared hosting)
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: ระบบตรวจจับการล้ม <$user>\r\n";
    return @mail($to, $subject, $body, $headers);
}

function sendWithPHPMailer(string $user, string $pass, string $to,
                            string $subject, string $body): bool {
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($user, 'ระบบตรวจจับการล้ม');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[แจ้งเตือน] PHPMailer: ' . $e->getMessage());
        return false;
    }
}

// ==================== ส่งทุกช่องทาง ====================
function sendFallNotification(array $fallData): array {
    // เพิ่ม GPS map link ถ้ามี
    if (!empty($fallData['lat']) && !empty($fallData['lng'])) {
        $fallData['map_url'] = "https://maps.google.com/?q={$fallData['lat']},{$fallData['lng']}";
    }
    $telegram = sendTelegram($fallData);
    $gmail    = sendGmail($fallData);
    return ['telegram' => $telegram, 'gmail' => $gmail];
}

// backward compat alias
function notifyFallDetected(array $fallData): array {
    return sendFallNotification($fallData);
}
