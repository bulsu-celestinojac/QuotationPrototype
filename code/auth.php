<?php
// auth.php - MAXIMUM SECURITY EDITION

// ── 1. STRICT SESSION CONFIGURATION ──
// These MUST be set before session_start() is called
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');

// Automatically enforce 'Secure' flag if running on HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── 2. GLOBAL HTTP SECURITY HEADERS ──
header("X-Frame-Options: DENY"); // Kills Clickjacking
header("X-XSS-Protection: 1; mode=block"); // Forces browser XSS filtering
header("X-Content-Type-Options: nosniff"); // Prevents MIME-type sniffing
header("Referrer-Policy: strict-origin-when-cross-origin");
// Strict CSP: Only allows scripts/styles from your domain, CDNs, and Google Fonts
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:;");

// Define your absolute base URL path to prevent routing errors in subfolders
define('BASE_URL', '/asalesquotation/code/');

// ── 3. SESSION HIJACKING PREVENTION (FINGERPRINTING) ──
function verify_session_integrity(): void {
    $current_ip = $_SERVER['REMOTE_ADDR'];
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_agent';

    if (!isset($_SESSION['user_ip_fingerprint'])) {
        $_SESSION['user_ip_fingerprint'] = $current_ip;
        $_SESSION['user_ua_fingerprint'] = $current_ua;
    } else {
        // If the IP or Browser suddenly changes during a session, destroy it immediately
        if ($_SESSION['user_ip_fingerprint'] !== $current_ip || $_SESSION['user_ua_fingerprint'] !== $current_ua) {
            session_unset();
            session_destroy();
            header("Location: " . BASE_URL . "login.php?error=hijack_prevented");
            exit;
        }
    }
}

// ── 4. IDLE TIMEOUT (30 MINUTES) ──
function check_idle_timeout(): void {
    $timeout_duration = 1800; // 1800 seconds = 30 minutes
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "login.php?error=timeout");
        exit;
    }
    $_SESSION['last_activity'] = time(); // Update activity timestamp
}

// ── 5. FORCE LOGIN CHECK ──
function require_login(): void {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "login.php");
        exit;
    }
    
    // Run Security Checks
    verify_session_integrity();
    check_idle_timeout();
    
    // Check if user account is suspended or still pending
    if (isset($_SESSION['user_status']) && $_SESSION['user_status'] !== 'active') {
        header("Location: " . BASE_URL . "logout.php?error=inactive");
        exit;
    }
}

// ── 6. STRICT ROLE VERIFICATION ──
function require_role(array $allowed_roles): bool {
    require_login();
    
    // Super Admin automatically bypasses all restrictions
    if ($_SESSION['user_role'] === 'super_admin') {
        return true; 
    }
    
    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        // Log unauthorized access attempt
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            log_activity($pdo, 'SECURITY_ALERT', "Unauthorized access attempt to " . $_SERVER['REQUEST_URI']);
        }
        die("<div style='background:#FEF2F2; border:1px solid #FECACA; padding:40px; text-align:center; border-radius:16px; max-width:500px; margin:50px auto; font-family:sans-serif;'><h2 style='color:#B91C1C; margin:0 0 10px 0;'>Security Alert</h2><p style='color:#475569; margin:0;'>You do not have the required permissions to access this directory.</p></div>");
    }
    
    return true;
}

// ── 7. UI HELPER ──
function has_role(array|string $roles): bool {
    if (!isset($_SESSION['user_role'])) return false;
    if ($_SESSION['user_role'] === 'super_admin') return true;
    return in_array($_SESSION['user_role'], (array)$roles);
}

// ── 8. ENTERPRISE AUDIT LOGGER ──
function log_activity(PDO $pdo, string $action_type, string $details): bool {
    if (!isset($_SESSION['user_id'])) return false;
    
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'], 
            $action_type, 
            $details, 
            $_SERVER['REMOTE_ADDR']
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Audit Log Failed: " . $e->getMessage());
        return false;
    }
}
?>