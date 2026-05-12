<?php
// forgot_password.php
session_start();
require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Find user by email
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Save token to database
                $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $updateStmt->execute([$token, $expires, $user['id']]);

                // Create recovery link
                $resetLink = "http://127.0.0.1/asalesquotation/code/reset_password.php?token=" . $token;

                // Send Email via PHPMailer
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
                    $mail->addAddress($email, $user['username']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request - AM Group';
                    $mail->Body    = "Hi {$user['username']},<br><br>You recently requested to reset your password for your AM Group account.<br><br>Click the link below to set a new password:<br><a href='{$resetLink}'>{$resetLink}</a><br><br>If you did not request this, you can safely ignore this email. This link will expire in 1 hour.";
                    $mail->send();
                } catch (Exception $e) {}
            }
            
            // Security best practice: Always show a generic success message to prevent email enumeration (hackers guessing emails)
            $success = "If that email exists in our system, a secure reset link has been sent.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - AM Group System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/auth_style.css">
    <style>
        /* Premium custom styling for the back link */
        .back-to-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 24px;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .back-to-login svg {
            width: 16px;
            height: 16px;
            transition: transform 0.3s ease;
        }
        .back-to-login:hover {
            color: var(--maroon);
        }
        .back-to-login:hover svg {
            transform: translateX(-4px);
        }
    </style>
</head>
<body>
    
    <div class="auth-master-layout">
        <div class="shape-1"></div>
        <div class="shape-2"></div>
        
        <div class="login-wrapper">
            <div class="login-card">
                
                <img src="../images/other_images/AMGLOGO.png" alt="AM Group Logo" class="login-logo">
                
                <div class="header-text anim-item">
                    <h1 class="title">Reset Password</h1>
                    <div class="subtitle">Enter your email to receive a secure link.</div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group anim-item anim-delay-1">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            <input type="email" name="email" placeholder="example@amgroup.asia" required autocomplete="email">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit anim-item anim-delay-2">
                        Send Reset Link
                        <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>
                </form>
                <?php endif; ?>

                <a href="login.php" class="back-to-login anim-item anim-delay-3">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Back to Login
                </a>
                
            </div>
            
            <div class="footer-text">
                &copy; <?php echo date('Y'); ?> AM Group Kitchen Equipment and Supplies, Inc.
            </div>
        </div>
    </div>

</body>
</html>