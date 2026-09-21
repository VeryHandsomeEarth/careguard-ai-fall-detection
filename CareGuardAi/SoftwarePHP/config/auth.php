<?php
/*
 * config/auth.php — Session-based authentication
 */

session_start();

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        // API request → 401
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'กรุณาเข้าสู่ระบบ']);
            exit;
        }
        // HTML page → redirect to login
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header("Location: $base/login.html");
        exit;
    }
}

function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}
