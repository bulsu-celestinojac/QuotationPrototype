<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate and retrieve CSRF Token
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF Token
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Set a session-based flash message for redirects
function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

// Display and clear the flash message
function display_flash_message() {
    if (isset($_SESSION['flash'])) {
        $type = $_SESSION['flash']['type'] === 'error' ? 'alert-error' : 'alert-success';
        $msg = htmlspecialchars($_SESSION['flash']['message'], ENT_QUOTES, 'UTF-8');
        echo "<div class='alert {$type}' style='padding: 16px 24px; border-radius: 12px; margin-bottom: 24px; font-weight: 500; text-align: center; border: 1px solid currentColor;'>{$msg}</div>";
        unset($_SESSION['flash']);
    }
}
?>