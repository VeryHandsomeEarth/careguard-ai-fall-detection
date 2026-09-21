<?php
/*
 * index.php — Front-controller with Authentication
 */

require_once __DIR__ . '/config/env.php';

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$fullUri   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = str_starts_with($fullUri, $scriptDir) ? substr($fullUri, strlen($scriptDir)) : $fullUri;
if ($uri === '' || $uri === false) $uri = '/';

$method = $_SERVER['REQUEST_METHOD'];

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if ($method === 'OPTIONS') { http_response_code(200); exit; }

$_SERVER['PATH_INFO'] = $uri;
$publicDir = __DIR__ . '/public';

// ==================== Static files (CSS, JS, images) ====================
$staticPath = $publicDir . $uri;
if ($uri !== '/' && file_exists($staticPath) && is_file($staticPath)) {
    $mime = [
        'css'=>'text/css;charset=utf-8','js'=>'application/javascript;charset=utf-8',
        'html'=>'text/html;charset=utf-8','png'=>'image/png','jpg'=>'image/jpeg',
        'jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml',
        'ico'=>'image/x-icon','json'=>'application/json',
        'woff'=>'font/woff','woff2'=>'font/woff2','map'=>'application/json',
    ];
    $ext = strtolower(pathinfo($staticPath, PATHINFO_EXTENSION));

    // HTML pages ต้องผ่าน routing (login check, auth) — ไม่ serve ตรงจาก static
    if ($ext === 'html') {
        // ปล่อยให้ไหลไป login route / protected pages ด้านล่าง
    } else {
        header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=3600');
        readfile($staticPath); exit;
    }
}

// ==================== API Routing ====================
if (str_starts_with($uri, '/api/')) {
    $seg = array_values(array_filter(explode('/', $uri)));
    $api = $seg[1] ?? '';
    switch ($api) {
        // Auth — ไม่ต้องล็อกอิน
        case 'auth':     require __DIR__ . '/api/auth.php';     exit;
        // Public & Dashboard APIs (ไม่ต้องล็อกอินสำหรับอ่านข้อมูล Dashboard)
        case 'falls':    require __DIR__ . '/api/falls.php';    exit;
        case 'gps':      require __DIR__ . '/api/gps.php';      exit;
        case 'position': require __DIR__ . '/api/gps.php';      exit;
        case 'stats':    require __DIR__ . '/api/stats.php';    exit;
        case 'profile':  require __DIR__ . '/api/profile.php';  exit;
        case 'devices':  require __DIR__ . '/api/devices.php';  exit;
        case 'chat':     require __DIR__ . '/api/chat.php';     exit;
        case 'admin':    requireSession(); require __DIR__ . '/api/admin.php';    exit;
        default:
            http_response_code(404); header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>'API not found']); exit;
    }
}

// ==================== Login page (ไม่ต้องล็อกอิน) ====================
if ($uri === '/login' || $uri === '/login.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($publicDir . '/login.html'); exit;
}

// ==================== Protected HTML pages ====================
session_start();
$loggedIn = !empty($_SESSION['user_id']);

$pages = [
    '/'            => 'index.html',
    '/index.html'  => 'index.html',
    '/health'      => 'health.html',
    '/health.html' => 'health.html',
    '/graphs'      => 'graphs.html',
    '/graphs.html' => 'graphs.html',
    '/report'      => 'report.html',
    '/report.html' => 'report.html',
    '/chat'        => 'chat.html',
    '/chat.html'   => 'chat.html',
    '/admin'       => 'admin.html',
    '/admin.html'  => 'admin.html',
];
if (isset($pages[$uri])) {
    if (!$loggedIn) {
        header("Location: $scriptDir/login.html"); exit;
    }
    // ตรวจสอบสิทธิ์เฉพาะหน้า admin.html
    if ($pages[$uri] === 'admin.html' && ($_SESSION['user']['role'] ?? '') !== 'admin') {
        header("Location: $scriptDir/index.html"); exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($publicDir . '/' . $pages[$uri]); exit;
}

http_response_code(404);
echo '<h1>404</h1><p><a href="' . $scriptDir . '/">กลับหน้าหลัก</a></p>';

// ==================== Session check for APIs ====================
function requireSession(): void {
    session_start();
    if (empty($_SESSION['user_id'])) {
        // ถ้ามี X-API-Key → อนุญาต (สำหรับ device)
        if (!empty($_SERVER['HTTP_X_API_KEY'])) return;
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'กรุณาเข้าสู่ระบบ']);
        exit;
    }
}
