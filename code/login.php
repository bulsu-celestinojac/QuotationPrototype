<?php
// login.php - MAXIMUM SECURITY EDITION
session_start();

// FORCE SYNCHRONIZED TIMEZONE
date_default_timezone_set('Asia/Manila'); 

// ── ANTI-CACHING HEADERS ──
// Prevents the browser from caching this page, stopping "Back Button" attacks
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$username_input = ''; 

if (empty($error)) {
    if (isset($_GET['error'])) {
        if ($_GET['error'] === 'inactive') $error = "Your account is pending approval or suspended.";
        if ($_GET['error'] === 'logout') $error = "You have been successfully logged out.";
        if ($_GET['error'] === 'timeout') $error = "Session expired due to inactivity. Please log in again.";
        if ($_GET['error'] === 'hijack_prevented') $error = "Security protocol triggered. Session terminated.";
    }
    if (isset($_GET['success']) && $_GET['success'] === 'reset') {
        $error = "Password successfully reset. You may now log in.";
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'super_admin') header("Location: superadmin/index.php");
    elseif ($_SESSION['user_role'] === 'admin') header("Location: admin/index.php");
    else header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $username_input = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username_input) && !empty($password)) {
            
            $stmt = $pdo->prepare("SELECT id, username, email, password, role, status, failed_attempts, locked_until FROM users WHERE username = ?");
            $stmt->execute([$username_input]);
            $user = $stmt->fetch();

            if ($user) {
                if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
                    $minutes_left = ceil((strtotime($user['locked_until']) - time()) / 60);
                    $error = "Account locked due to security protocols. Please try again in {$minutes_left} minute(s).";
                } 
                else {
                    if (password_verify($password, $user['password']) && $user['status'] === 'active') {
                        
                        $resetStmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
                        $resetStmt->execute([$user['id']]);

                        // ── SECURITY UPGRADE: Prevent Session Fixation attacks ──
                        session_regenerate_id(true);
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_status'] = $user['status'];
                        $_SESSION['last_activity'] = time(); // Set initial activity timestamp
                        
                        if ($user['role'] === 'super_admin') header("Location: superadmin/index.php");
                        elseif ($user['role'] === 'admin') header("Location: admin/index.php");
                        else header("Location: index.php");
                        exit;

                    } else {
                        $attempts = $user['failed_attempts'] + 1;
                        
                        if ($attempts >= 5) {
                            $lockoutTime = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                            $lockStmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?");
                            $lockStmt->execute([$attempts, $lockoutTime, $user['id']]);

                            // Send lock email
                            if (!empty($user['email'])) {
                                $token = bin2hex(random_bytes(16)); 
                                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                                $updateTokenStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                                $updateTokenStmt->execute([$token, $expires, $user['id']]);

                                $resetLink = "http://127.0.0.1/asalesquotation/code/reset_password.php?token=" . $token;

                                $mail = new PHPMailer(true);
                                try {
                                    $mail->isSMTP();
                                    $mail->Host       = 'smtp.gmail.com';
                                    $mail->SMTPAuth   = true;
                                    $mail->Username   = 'it.amgroupp@gmail.com'; 
                                    $mail->Password   = 'yoruuuphsufblgvl'; 
                                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                    $mail->Port       = 587;

                                    $mail->setFrom('it.amgroupp@gmail.com', 'AM Group IT Support');
                                    $mail->addAddress($user['email'], $user['username']);
                                    $mail->isHTML(true);
                                    $mail->Subject = 'Security Alert: Account Locked - AM Group';
                                    
                                    $mail->Body = "
                                    <div style='font-family: Arial, sans-serif; padding: 40px; text-align: center;'>
                                        <h1 style='color: #DC2626;'>Account Locked</h1>
                                        <p>We noticed 5 consecutive failed login attempts on your account. It has been locked for 15 minutes.</p>
                                        <p>You can wait, or unlock it immediately by resetting your password:</p>
                                        <a href='{$resetLink}' style='display:inline-block; padding:15px 30px; background:#8B1538; color:#fff; text-decoration:none; font-weight:bold; border-radius:5px;'>Unlock & Reset Password</a>
                                    </div>";
                                    $mail->send();
                                } catch (Exception $e) {}
                            }
                            
                            $error = "Account locked due to multiple failed attempts. A recovery link has been sent to your email.";
                        } else {
                            $incStmt = $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
                            $incStmt->execute([$attempts, $user['id']]);
                            
                            $attempts_left = 5 - $attempts;
                            $error = "Invalid username or password. {$attempts_left} attempt(s) remaining.";
                        }
                    }
                }
            } else {
                // Keep the error generic to prevent username enumeration
                $error = "Invalid username or password. Please try again.";
            }
        } else {
            $error = "Please fill in all fields.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - AM Group System</title>
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
                    <h1 class="title">System Login</h1>
                    <div class="subtitle">Corporate Quoting & Inventory Engine</div>
                </div>

                <?php if ($error): ?>
                    <?php 
                        $is_success = (strpos($error, 'successfully') !== false || strpos($error, 'reset') !== false || strpos($error, 'logout') !== false); 
                        $alert_class = $is_success ? 'alert-success' : 'alert-error';
                        $icon = $is_success 
                            ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
                            : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
                    ?>
                    <div class="alert <?php echo $alert_class; ?>">
                        <?php echo $icon; ?>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group anim-item anim-delay-1">
                        <label>Username</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <input type="text" name="username" placeholder="Enter your username" value="<?php echo htmlspecialchars($username_input, ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="username">
                        </div>
                    </div>
                    
                    <div class="form-group anim-item anim-delay-2">
                        <div class="password-header">
                            <label>Password</label>
                            <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                        </div>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            <input type="password" name="password" id="passwordField" placeholder="••••••••" required autocomplete="current-password">
                            <svg class="password-toggle" id="togglePassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit anim-item anim-delay-3">
                        Secure Login
                        <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </button>
                </form>
            </div>
            <div class="footer-text">&copy; <?php echo date('Y'); ?> AM Group Kitchen Equipment and Supplies, Inc.</div>
        </div>
    </div>
    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordField = document.getElementById('passwordField');
        if(togglePassword && !passwordField.disabled) {
            togglePassword.addEventListener('click', function () {
                const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordField.setAttribute('type', type);
                if (type === 'text') {
                    this.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                } else {
                    this.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                }
            });
        }
    </script>
</body>
</html>