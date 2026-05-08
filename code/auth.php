<?php
// auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Force Login Check
function require_login(): void {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    
    // Check if user account is suspended or still pending
    if (isset($_SESSION['user_status']) && $_SESSION['user_status'] !== 'active') {
        header("Location: logout.php?error=inactive");
        exit;
    }
}

// 2. Strict Role Verification (Added 'array' type hint)
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
        die("<h2 style='color:#8B1538; text-align:center; margin-top:50px; font-family:sans-serif;'>Security Alert: Unauthorized access.</h2>");
    }
    
    return true;
}

// 3. UI Helper (Added 'array|string' type hint)
function has_role(array|string $roles): bool {
    if (!isset($_SESSION['user_role'])) return false;
    if ($_SESSION['user_role'] === 'super_admin') return true;
    return in_array($_SESSION['user_role'], (array)$roles);
}

// 4. Enterprise Audit Logger (Added 'PDO', 'string', 'string' type hints)
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