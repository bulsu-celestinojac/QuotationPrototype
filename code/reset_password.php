<?php
session_start();
require 'db.php';

// FORCE SYNCHRONIZED TIMEZONE
date_default_timezone_set('Asia/Manila');

$error = '';
$success = '';
$valid_token = false;
$user_id = null;

// Verify the Token
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $pdo->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $current_time = time();
        $expires_time = strtotime($user['reset_expires']);

        if ($current_time <= $expires_time) {
            $valid_token = true;
            $user_id = $user['id'];
        } else {
            $error = "This password reset link has expired.";
        }
    } else {
        $error = "This password reset link is invalid.";
    }
} else {
    $error = "No reset token was provided. Please request a new link.";
}

// Process the New Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // SECURITY UPGRADE: Strict Password Complexity
        if (empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all fields.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $error = "Password must include at least one uppercase letter, one lowercase letter, and one number.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL, failed_attempts = 0, locked_until = NULL WHERE id = ?");
            
            if ($updateStmt->execute([$hashed_password, $user_id])) {
                header("Location: login.php?success=reset");
                exit;
            } else {
                $error = "Failed to update password. Please try again or contact IT.";
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set New Password - AM Group</title>
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

        .helper-text { font-size: 0.7rem; color: var(--text-light); margin-top: 6px; display: block;}
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
                    <h1 class="title">New Password</h1>
                    <div class="subtitle">Secure your account with a new password.</div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($valid_token): ?>
                    <form method="POST" action="" id="resetForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="form-group anim-item anim-delay-1">
                            <label>New Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="new_password" id="new_password" placeholder="••••••••" required autofocus pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Must contain at least one number and one uppercase and lowercase letter, and at least 8 or more characters">
                                <svg class="password-toggle" id="toggleNewPassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </div>
                            <span class="helper-text">Min 8 chars, 1 uppercase, 1 lowercase, 1 number.</span>
                        </div>
                        
                        <div class="form-group anim-item anim-delay-2">
                            <label>Confirm Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="confirm_password" id="confirm_password" placeholder="••••••••" required>
                            </div>
                            <span class="helper-text" id="matchMsg" style="color: #DC2626; display: none;">Passwords do not match.</span>
                        </div>
                        
                        <button type="submit" class="btn-submit anim-item anim-delay-3" id="submitBtn">
                            Save Password
                            <svg class="btn-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </button>
                    </form>
                <?php endif; ?>

                <div class="anim-item anim-delay-3">
                    <a href="login.php" class="btn-back-premium">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Back to Login
                    </a>
                </div>

            </div>
            <div class="footer-text">&copy; <?php echo date('Y'); ?> AM Group Kitchen Equipment and Supplies, Inc.</div>
        </div>
    </div>

    <script>
        const toggleBtn = document.getElementById('toggleNewPassword');
        const pass1 = document.getElementById('new_password');
        const pass2 = document.getElementById('confirm_password');
        const matchMsg = document.getElementById('matchMsg');
        const submitBtn = document.getElementById('submitBtn');

        if(toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                const type = pass1.getAttribute('type') === 'password' ? 'text' : 'password';
                pass1.setAttribute('type', type);
                pass2.setAttribute('type', type);

                if (type === 'text') {
                    this.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                } else {
                    this.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                }
            });
        }

        // Live validation for password matching
        if(pass1 && pass2) {
            function validateMatch() {
                if(pass2.value !== '' && pass1.value !== pass2.value) {
                    matchMsg.style.display = 'block';
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                } else {
                    matchMsg.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                }
            }
            pass1.addEventListener('input', validateMatch);
            pass2.addEventListener('input', validateMatch);
        }
    </script>
</body>
</html>