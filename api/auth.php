<?php
/**
 * MilkBook - Authentication API
 * Single-admin session-based login/logout/status check with CSRF token generation.
 * Enabled when AUTH_ENABLED in config.php is true.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : ($method === 'POST' ? 'login' : 'status');

// 1. Session & Auth Status
if ($action === 'status') {
    json_response([
        'auth_enabled' => defined('AUTH_ENABLED') ? (bool)AUTH_ENABLED : false,
        'is_logged_in' => !defined('AUTH_ENABLED') || !AUTH_ENABLED || !empty($_SESSION['auth_user']),
        'user'         => $_SESSION['auth_user'] ?? (AUTH_ENABLED ? null : ADMIN_USER),
        'csrf_token'   => get_csrf_token()
    ]);
}

// 2. Login
if ($action === 'login') {
    if ($method !== 'POST') {
        json_response(null, 405, false, ['message' => 'Login requires POST request.']);
    }

    $input = read_json_body();
    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        json_response(null, 422, false, ['message' => 'Username and password are required.']);
    }

    if ($username === ADMIN_USER && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['auth_user'] = ADMIN_USER;
        $token = get_csrf_token();

        json_response([
            'user'         => ADMIN_USER,
            'csrf_token'   => $token,
            'auth_enabled' => AUTH_ENABLED,
            'message'      => 'Login successful.'
        ]);
    } else {
        json_response(null, 401, false, ['message' => 'Invalid username or password.']);
    }
}

// 3. Logout
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();

    json_response([
        'message' => 'Logged out successfully.'
    ]);
}

json_response(null, 400, false, ['message' => "Unknown action '{$action}'."]);
