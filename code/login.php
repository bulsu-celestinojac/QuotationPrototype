<?php
session_start();
require 'db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'inactive') {
        $error = "Your account is pending approval or suspended.";
    }
    if ($_GET['error'] === 'logout') {
        $error = "You have been successfully logged out.";
    }
}
if (isset($_GET['success']) && $_GET['success'] === 'reset') {
    $error = "Password successfully reset. You may now log in.";
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'super_admin') {
        header("Location: superadmin/index.php");
    } elseif ($_SESSION['user_role'] === 'admin') {
        header("Location: admin/index.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
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
                    
                    if ($user['role'] === 'super_admin') {
                        header("Location: superadmin/index.php");
                    } elseif ($user['role'] === 'admin') {
                        header("Location: admin/index.php");
                    } else {
                        header("Location: index.php");
                    }
                    exit;
                } else {
                    $error = "Invalid credentials or inactive account.";
                }
            } else {
                $error = "Invalid credentials or inactive account.";
            }
        } else {
            $error = "Please fill in all fields.";
        }
    }
}
?><!DOCTYPE html>
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
                        $is_success = (strpos($error, 'successfully') !== false || strpos($error, 'logout') !== false); 
                        $alert_class = $is_success ? 'alert-success' : 'alert-error';
                    ?>
                    <div class="alert <?php echo $alert_class; ?>">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

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
            
            <div class="footer-text">
                &copy; <?php echo date('Y'); ?> AM Group Kitchen Equipment and Supplies, Inc.
            </div>
        </div>
    </div>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordField = document.getElementById('passwordField');
        if(togglePassword) {
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