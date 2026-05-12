<?php
// logout.php
session_start();

// 1. Unset all session variables
$_SESSION = array();

// 2. Destroy the session cookie completely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session itself
session_destroy();

// 4. Send them back to the login screen with the logout success message
header("Location: login.php?error=logout");
exit;
?>