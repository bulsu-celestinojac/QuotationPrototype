<?php
session_start();

// FORCE SYNCHRONIZED TIMEZONE
date_default_timezone_set('Asia/Manila');

require 'db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

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

        // SECURITY UPGRADE: Strict Email Format Validation
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // 32-CHARACTER TOKEN & MANILA TIME EXPIRATION
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
                    $mail->Subject = 'Password Reset Request - AM Group';
                    
                    $mail->Body = <<<HTML
                    <div style="font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F4F7F9; padding: 40px 20px; color: #0F172A;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; margin: 0 auto; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05);">
                            <tr>
                                <td style="padding: 40px; text-align: center; border-bottom: 4px solid #8B1538; background-color: #FAFAFA;">
                                    <h2 style="margin: 0; color: #8B1538; font-size: 28px; font-weight: 900; letter-spacing: -0.5px;">AM GROUP</h2>
                                    <p style="margin: 5px 0 0 0; color: #64748B; font-size: 13px; text-transform: uppercase; letter-spacing: 1px;">Corporate Inventory Engine</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 40px;">
                                    <h1 style="margin: 0 0 20px 0; font-size: 22px; color: #0F172A;">Password Reset Request</h1>
                                    <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #475569;">Hi <strong>{$user['username']}</strong>,</p>
                                    <p style="margin: 0 0 35px 0; font-size: 16px; line-height: 1.6; color: #475569;">We received a request to reset the password for your AM Group account. Click the button below to set a new password. For your security, this link will safely expire in <strong>1 hour</strong>.</p>
                                    
                                    <div style="text-align: center; margin-bottom: 35px;">
                                        <a href="{$resetLink}" style="display: inline-block; background-color: #8B1538; color: #FFFFFF; padding: 16px 32px; text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px;">Reset My Password</a>
                                    </div>
                                    
                                    <div style="background-color: #F8FAFC; padding: 20px; border-radius: 8px; border: 1px dashed #E2E8F0;">
                                        <p style="margin: 0 0 10px 0; font-size: 13px; color: #64748B; font-weight: bold;">If the button doesn't work, copy and paste this link into your browser:</p>
                                        <p style="margin: 0; font-size: 13px; color: #8B1538; word-break: break-all;">{$resetLink}</p>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
HTML;
                    $mail->send();
                    $success = "If an active account matches that email, a reset link has been sent.";
                } catch (Exception $e) {
                    $error = "Failed to send the email. Please contact IT support.";
                }
            } else {
                $success = "If an active account matches that email, a reset link has been sent.";
            }
        }
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
    <style>
        /* PREMIUM BACK BUTTON INLINE STYLES */
        .btn-back-premium {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 20px;
            background: #F8FAFC;
            color: #475569;
            text-decoration: none;
            font-family: 'Outfit', sans-serif;
            font-size: 0.85rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 12px;
        }
        .btn-back-premium:hover {
            background: #FFFFFF;
            color: var(--maroon);
            border-color: var(--maroon);
            box-shadow: 0 4px 12px rgba(139, 21, 56, 0.1);
            transform: translateY(-2px);
        }
        .btn-back-premium svg { transition: transform 0.3s ease; }
        .btn-back-premium:hover svg { transform: translateX(-4px); }
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
                    <div class="subtitle">Enter your email to receive a recovery link.</div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-group anim-item anim-delay-1">
                        <label>Email Address</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                            <input type="email" name="email" placeholder="example@amgroup.com" required autofocus>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit anim-item anim-delay-2">
                        Send Reset Link
                        <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>

                    <div class="anim-item anim-delay-3">
                        <a href="login.php" class="btn-back-premium">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            Back to Login
                        </a>
                    </div>
                </form>
            </div>
            
            <div class="footer-text">&copy; <?php echo date('Y'); ?> AM Group Kitchen Equipment and Supplies, Inc.</div>
        </div>
    </div>
</body>
</html>