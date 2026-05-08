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

                $mail->setFrom('it.amgroupp@gmail.com', 'AM Group');
                $mail->addAddress($email);

                $mail->addEmbeddedImage('../images/other_images/AMGLOGO.png', 'amg_logo');

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset - AM Group System';
                
                // Email Body - Uncropped Logo
                $mail->Body = "
                <div style=\"background-color: #F8F6F5; padding: 40px 20px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; text-align: center;\">
                    <table align=\"center\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 500px; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; border: 1px solid #E8D8D7; box-shadow: 0 4px 20px rgba(139, 21, 56, 0.05);\">
                        <tr>
                            <td align=\"center\" style=\"padding: 40px 40px 20px 40px;\">
                                <img src=\"cid:amg_logo\" alt=\"AM Group Logo\" style=\"width: 110px; height: auto; display: block; border: none; outline: none;\">
                            </td>
                        </tr>
                        <tr>
                            <td style=\"padding: 10px 40px 30px 40px; text-align: center;\">
                                <h2 style=\"color: #2A0808; margin: 0 0 15px 0; font-size: 22px; font-weight: 800;\">Password Reset Request</h2>
                                <p style=\"color: #6B7280; font-size: 15px; line-height: 1.6; margin: 0 0 30px 0;\">
                                    Hi <strong style=\"color: #2A0808;\">{$user['username']}</strong>,<br><br>
                                    We received a request to reset your password for the AM Group System.
                                </p>
                                <a href=\"{$resetLink}\" style=\"display: inline-block; background-color: #8B1538; color: #FFFFFF; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: bold; font-size: 15px; letter-spacing: 0.5px;\">Reset Password</a>
                            </td>
                        </tr>
                        <tr>
                            <td style=\"padding: 25px 40px 35px 40px; text-align: center; border-top: 1px solid #F8F6F5;\">
                                <p style=\"color: #8C7373; font-size: 12px; line-height: 1.5; margin: 0;\">
                                    <strong>Note:</strong> If your email client disabled the button above, copy and paste this link:<br><br>
                                    <a href=\"{$resetLink}\" style=\"color: #8B1538; text-decoration: none; word-break: break-all;\">{$resetLink}</a>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                ";
                
                $mail->AltBody = "Hello {$user['username']},\n\nCopy and paste this link to reset your password:\n\n{$resetLink}";

                $mail->send();
                $message = "Link sent! Check your email.";
                $msgType = 'success';
                
            } catch (Exception $e) {
                $message = "Mailer Error: " . $mail->ErrorInfo;
                $msgType = 'error';
            }
        } else {
            $message = "If an active account exists, a link was sent.";
            $msgType = 'success';
        }
    } else {
        $message = "Please enter a valid email address.";
        $msgType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: rgba(255, 255, 255, 0.85); --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; --maroon-light: #FFF5F7; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--bg); color: var(--text-main); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; position: relative; overflow: hidden; }
        .shape-1, .shape-2 { position: absolute; border-radius: 50%; filter: blur(80px); z-index: 0; animation: drift 10s ease-in-out infinite alternate; }
        .shape-1 { width: 400px; height: 400px; background: rgba(139, 21, 56, 0.06); top: -100px; left: -100px; }
        .shape-2 { width: 500px; height: 500px; background: rgba(139, 21, 56, 0.04); bottom: -150px; right: -150px; animation-delay: -5s; }
        @keyframes drift { from { transform: translate(0, 0) scale(1); } to { transform: translate(30px, 30px) scale(1.05); } }
        .login-wrapper { width: 100%; max-width: 440px; z-index: 1; perspective: 1000px; }
        .login-card { background: var(--surface); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-radius: 28px; padding: 50px 40px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 24px 60px rgba(42, 8, 8, 0.08), inset 0 1px 0 rgba(255,255,255,0.8); animation: slideUpFade 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes slideUpFade { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        
        /* FIX: Removed border-radius and shadow */
        .login-logo { width: 110px; height: auto; object-fit: contain; border-radius: 0; margin: 0 auto 28px auto; display: block; animation: floatLogo 6s ease-in-out infinite; }
        @keyframes floatLogo { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        
        .header-text { text-align: center; margin-bottom: 36px; }
        .title { font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 900; color: var(--text-main); text-transform: uppercase; line-height: 1.1; margin-bottom: 6px; }
        .subtitle { color: var(--text-muted); font-size: 0.95rem; font-weight: 500; }
        .form-group { margin-bottom: 24px; position: relative; }
        label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 18px; color: #BBAAAA; width: 20px; height: 20px; transition: color 0.3s ease; pointer-events: none; }
        input[type="email"] { width: 100%; padding: 16px 18px 16px 50px; border-radius: 14px; border: 1px solid var(--border); background: #FDFBFC; font-size: 1rem; font-family: 'DM Sans', sans-serif; font-weight: 600; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); outline: none; }
        input:focus { border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); background: #FFFFFF; }
        input:focus + .input-icon, .input-wrapper:focus-within .input-icon { color: var(--maroon); }
        .btn-submit { background: linear-gradient(135deg, var(--maroon) 0%, #6A0D28 100%); color: white; width: 100%; height: 58px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); margin-top: 12px; box-shadow: 0 8px 24px rgba(139, 21, 56, 0.25); display: flex; align-items: center; justify-content: center; }
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(139, 21, 56, 0.35); }
        .alert { padding: 16px; border-radius: 14px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; text-align: center; line-height: 1.4; }
        .alert-error { background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.15); }
        .alert-success { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
        .back-link { display: block; text-align: center; margin-top: 24px; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-decoration: none; transition: 0.2s; }
        .back-link:hover { color: var(--maroon); }
    </style>
</head>
<body>
    <div class="shape-1"></div>
    <div class="shape-2"></div>
    
    <div class="login-wrapper">
        <div class="login-card">
            <img src="../images/other_images/AMGLOGO.png" alt="AM Group Logo" class="login-logo">
            <div class="header-text">
                <h1 class="title">Reset Password</h1>
                <div class="subtitle">Enter your email to receive a link.</div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label>Email Address</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <input type="email" name="email" placeholder="example@amgroup.asia" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">Send Link</button>
                <a href="login.php" class="back-link">← Return to Login</a>
            </form>
        </div>
    </div>
</body>
</html>