<?php
// login.php
session_start();
require 'db.php';

$error = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'inactive') $error = "Your account is pending approval or suspended.";
    if ($_GET['error'] === 'logout') $error = "You have been successfully logged out.";
}
if (isset($_GET['success']) && $_GET['success'] === 'reset') {
    $error = "Password successfully reset. You may now log in.";
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'super_admin') header("Location: superadmin/index.php");
    elseif ($_SESSION['user_role'] === 'admin') header("Location: admin/index.php");
    else header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'active') {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_status'] = $user['status'];
                
                if ($user['role'] === 'super_admin') header("Location: superadmin/index.php");
                elseif ($user['role'] === 'admin') header("Location: admin/index.php");
                else header("Location: index.php");
                exit;
            } else {
                $error = "Account is not active. Please contact the Super Admin.";
            }
        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - AM Group System</title>
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
        .login-card { background: var(--surface); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border-radius: 28px; padding: 50px 40px; border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: 0 24px 60px rgba(42, 8, 8, 0.08), inset 0 1px 0 rgba(255,255,255,0.8); animation: slideUpFade 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards; opacity: 0; transform: translateY(30px); }
        @keyframes slideUpFade { to { opacity: 1; transform: translateY(0); } }
        
        /* FIX: Removed border-radius and shadow to stop cropping the logo */
        .login-logo { width: 110px; height: auto; object-fit: contain; border-radius: 0; margin: 0 auto 28px auto; display: block; animation: floatLogo 6s ease-in-out infinite; }
        @keyframes floatLogo { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        
        .header-text { text-align: center; margin-bottom: 36px; }
        .title { font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 900; color: var(--text-main); text-transform: uppercase; letter-spacing: -0.01em; line-height: 1.1; margin-bottom: 6px; }
        .subtitle { color: var(--text-muted); font-size: 0.95rem; font-weight: 500; }
        .anim-item { opacity: 0; transform: translateY(15px); animation: fadeUpItem 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .anim-delay-1 { animation-delay: 0.2s; } .anim-delay-2 { animation-delay: 0.3s; } .anim-delay-3 { animation-delay: 0.4s; }
        @keyframes fadeUpItem { to { opacity: 1; transform: translateY(0); } }
        .form-group { margin-bottom: 24px; position: relative; }
        label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 18px; color: #BBAAAA; width: 20px; height: 20px; transition: color 0.3s ease; pointer-events: none; }
        input[type="text"], input[type="password"] { width: 100%; padding: 16px 18px 16px 50px; border-radius: 14px; border: 1px solid var(--border); background: #FDFBFC; font-size: 1rem; font-family: 'DM Sans', sans-serif; color: var(--text-main); font-weight: 600; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); outline: none; }
        input::placeholder { color: #C8BDBD; font-weight: 400; }
        input:focus { border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); background: #FFFFFF; }
        input:focus + .input-icon, .input-wrapper:focus-within .input-icon { color: var(--maroon); }
        .password-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px; }
        .password-header label { margin-bottom: 0; }
        .forgot-link { font-size: 0.75rem; font-weight: 700; color: var(--maroon); text-decoration: none; transition: all 0.2s ease; padding: 4px 8px; border-radius: 6px; margin-right: -8px; }
        .forgot-link:hover { color: #5A0000; background: var(--maroon-light); }
        .btn-submit { background: linear-gradient(135deg, var(--maroon) 0%, #6A0D28 100%); color: white; width: 100%; height: 58px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); margin-top: 12px; box-shadow: 0 8px 24px rgba(139, 21, 56, 0.25); display: flex; align-items: center; justify-content: center; gap: 10px; overflow: hidden; position: relative; }
        .btn-icon { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(139, 21, 56, 0.35); }
        .btn-submit:hover .btn-icon { transform: translateX(6px); }
        .alert { padding: 16px; border-radius: 14px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; text-align: center; line-height: 1.4; animation: fadeInAlert 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes fadeInAlert { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .alert-error { background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.15); }
        .alert-success { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
        .footer-text { text-align: center; margin-top: 32px; font-size: 0.8rem; color: var(--text-muted); font-weight: 500; opacity: 0; animation: slideUpFade 0.5s ease forwards; animation-delay: 0.6s; }
        @media (max-width: 480px) { .login-card { padding: 40px 24px; border-radius: 20px; } .title { font-size: 1.9rem; } }
    </style>
</head>
<body>
    <div class="shape-1"></div>
    <div class="shape-2"></div>
    
    <div class="login-wrapper">
        <div class="login-card">
            
            <img src="../images/other_images/AMGLOGO.png" alt="AM Group Logo" class="login-logo anim-item">
            
            <div class="header-text anim-item">
                <h1 class="title">System Login</h1>
                <div class="subtitle">Corporate Quoting & Inventory Engine</div>
            </div>

            <?php if ($error): ?>
                <?php $is_success = (strpos($error, 'successfully') !== false || strpos($error, 'logout') !== false); ?>
                <div class="alert <?= $is_success ? 'alert-success' : 'alert-error' ?>">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group anim-item anim-delay-1">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <input type="text" name="username" placeholder="Enter your username" required autocomplete="username">
                    </div>
                </div>
                
                <div class="form-group anim-item anim-delay-2">
                    <div class="password-header">
                        <label>Password</label>
                        <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                    </div>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                    </div>
                </div>
                
                <button type="submit" class="btn-submit anim-item anim-delay-3">
                    Secure Login
                    <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </button>
            </form>
        </div>
        
        <div class="footer-text">
            &copy; <?= date('Y') ?> AM Group Kitchen Equipment and Supplies, Inc.
        </div>
    </div>
</body>
</html>