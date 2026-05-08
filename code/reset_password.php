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
            header("refresh:2;url=login.php?success=reset");
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
    <title>Create New Password - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: rgba(255, 255, 255, 0.85); --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; --maroon-light: #FFF5F7; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; position: relative; overflow: hidden;}
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
        
        .header-text { text-align: center; margin-bottom: 32px; }
        .title { font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 900; color: var(--text-main); text-transform: uppercase; line-height: 1.1; margin-bottom: 8px; }
        .subtitle { color: var(--text-muted); font-size: 0.95rem; font-weight: 500; }
        .form-group { margin-bottom: 24px; position: relative; }
        label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 18px; color: #BBAAAA; width: 20px; height: 20px; transition: color 0.3s ease; pointer-events: none; }
        input[type="password"] { width: 100%; padding: 16px 18px 16px 50px; border-radius: 14px; border: 1px solid var(--border); background: #FDFBFC; font-size: 1rem; font-family: 'DM Sans', sans-serif; font-weight: 600; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); outline: none; }
        input:focus { border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); background: #FFFFFF; }
        input:focus + .input-icon, .input-wrapper:focus-within .input-icon { color: var(--maroon); }
        .btn-submit { background: linear-gradient(135deg, var(--maroon) 0%, #6A0D28 100%); color: white; width: 100%; height: 58px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); margin-top: 12px; box-shadow: 0 8px 24px rgba(139, 21, 56, 0.25); display: flex; align-items: center; justify-content: center; }
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(139, 21, 56, 0.35); }
        .alert { padding: 16px; border-radius: 14px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; text-align: center; line-height: 1.4; }
        .alert-error { background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.15); }
        .alert-success { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
    </style>
</head>
<body>
    <div class="shape-1"></div>
    <div class="shape-2"></div>
    
    <div class="login-wrapper">
        <div class="login-card">
            <img src="../images/other_images/AMGLOGO.png" alt="AM Group Logo" class="login-logo">
            <div class="header-text">
                <h1 class="title">New Password</h1>
                <div class="subtitle">Secure your corporate account.</div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">Password updated! Redirecting...</div>
            <?php elseif ($valid_token): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>New Password</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            <input type="password" name="new_password" placeholder="Min 6 characters" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            <input type="password" name="confirm_password" placeholder="Retype password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit">Save Password</button>
                </form>
            <?php else: ?>
                <a href="login.php" class="btn-submit" style="text-decoration: none;">Return to Login</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>