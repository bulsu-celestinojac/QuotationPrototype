<?php
// forgot_password.php
session_start();
require 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $updateStmt->execute([$token, $expires, $user['id']]);

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
                $mail->addAddress($email, $user['username']);

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Request - AM Group';
                $mail->Body    = "Hi {$user['username']},<br><br>You requested a password reset. Click the link below to set a new password:<br><br><a href='{$resetLink}'>{$resetLink}</a><br><br>This link will expire in 1 hour.<br><br>If you did not request this, please ignore this email.";

                $mail->send();
                $message = "A reset link has been sent to your email address.";
                $msgType = "success";
            } catch (Exception $e) {
                $message = "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
                $msgType = "error";
            }
        } else {
            $message = "If that email exists in our system, a reset link has been sent.";
            $msgType = "success";
        }
    } else {
        $message = "Please enter a valid email address.";
        $msgType = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - AM Group</title>
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
                    <h1 class="title">Reset Password</h1>
                    <div class="subtitle">Enter your email to receive a secure link.</div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $msgType; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group anim-item anim-delay-1">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            <input type="email" name="email" placeholder="example@amgroup.asia" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit anim-item anim-delay-2">
                        Send Reset Link
                        <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>
                </form>
                
                <a href="login.php" class="back-link anim-item anim-delay-3">← Back to Login</a>
            </div>
        </div>
    </div>

</body>
</html>