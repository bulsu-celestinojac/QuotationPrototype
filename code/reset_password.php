<?php
// reset_password.php
session_start();
require 'db.php';

$error = '';
$success = false;
$valid_token = false;
$user_id = null;

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $valid_token = true;
        $user_id = $user['id'];
    } else {
        $error = "This password reset link is invalid or has expired.";
    }
} else {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        
        if ($updateStmt->execute([$hashed_password, $user_id])) {
            $success = true;
        } else {
            $error = "Failed to update password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set New Password - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/auth_style.css">
</head>
<body>
    
    <div class="auth-master-layout">
        <div class="shape-1"></div>
        <div class="shape-2"></div>
        
        <div class="login-wrapper">
            <div class="login-card">
                <img src="../images/other_images/AMGLOGO.png" alt="AM Group Logo" class="login-logo">
                
                <div class="header-text anim-item">
                    <h1 class="title">New Password</h1>
                    <div class="subtitle">Secure your account with a new password.</div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">Password successfully reset!</div>
                    <a href="login.php" class="btn-submit" style="text-decoration:none;">Return to Login</a>
                <?php elseif ($valid_token): ?>
                    <form method="POST" action="">
                        <div class="form-group anim-item anim-delay-1">
                            <label>New Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="new_password" placeholder="Min 6 characters" required>
                            </div>
                        </div>
                        
                        <div class="form-group anim-item anim-delay-2">
                            <label>Confirm Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="confirm_password" placeholder="Retype password" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-submit anim-item anim-delay-3">
                            Save Password
                            <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="back-link">← Back to Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>