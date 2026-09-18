<?php
// Session Security & Hardening
if (session_status() === PHP_SESSION_NONE) {
    // Secure session cookies
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'] ?: '/',
        'domain' => $cookieParams['domain'],
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function currentUser() {
    global $pdo;
    if (!isLoggedIn()) return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: login');
        exit;
    }

    // Strict email verification lock: non-admin artists MUST be verified
    if (!isAdmin()) {
        $u = currentUser();
        if (!$u || intval($u['email_verified'] ?? 0) === 0) {
            $user_email = $u['email'] ?? '';
            // Clear unverified session
            unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_role']);
            header('Location: verify-email?pending=1' . ($user_email ? '&email=' . urlencode($user_email) : ''));
            exit;
        }
    }
}

function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        header('Location: dashboard?error=unauthorized');
        exit;
    }
}

// Session Hardening
function regenerateUserSession() {
    session_regenerate_id(true);
}

// CSRF Token Helpers
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

function verifyCsrfToken($token) {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
