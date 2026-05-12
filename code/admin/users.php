<?php
// admin/users.php
session_start();
require_once '../auth.php';
require_role(['admin', 'super_admin']); 
require '../db.php';
require_once '../functions.php';

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        // 1. CREATE NEW USER
        if (isset($_POST['action']) && $_POST['action'] === 'create_user') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'sales'; 
            
            // Security: Prevent admins from creating super admins
            if ($role === 'super_admin' && $_SESSION['user_role'] !== 'super_admin') {
                $error = "Only a Super Admin can create another Super Admin.";
            } elseif (empty($username) || empty($password)) {
                $error = "Username and Password are required.";
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "Username already exists. Please choose another.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $status = ($_SESSION['user_role'] === 'super_admin') ? 'active' : 'pending';
                    
                    $insertStmt = $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, ?)");
                    if ($insertStmt->execute([$username, $hashed_password, $role, $status])) {
                        log_activity($pdo, 'USER_MANAGEMENT', "Created new {$role} account for: {$username}");
                        $success = "User account created successfully! Status: " . ucfirst($status);
                    } else {
                        $error = "Failed to create user account.";
                    }
                }
            }
        }
        
        // 2. CHANGE USER STATUS (Activate, Suspend)
        elseif (isset($_POST['action']) && $_POST['action'] === 'change_status') {
            $target_user_id = (int)$_POST['user_id'];
            $new_status = $_POST['new_status']; // 'active', 'suspended', 'pending'
            
            if ($target_user_id === $_SESSION['user_id']) {
                $error = "You cannot change your own account status.";
            } else {
                // Security Check: Admins cannot suspend super_admins
                $checkStmt = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
                $checkStmt->execute([$target_user_id]);
                $target_user = $checkStmt->fetch();
                
                if ($target_user && $target_user['role'] === 'super_admin' && $_SESSION['user_role'] !== 'super_admin') {
                    $error = "Permission denied. You cannot modify a Super Admin account.";
                } else {
                    $updateStmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                    if ($updateStmt->execute([$new_status, $target_user_id])) {
                        log_activity($pdo, 'USER_MANAGEMENT', "Changed status of {$target_user['username']} to {$new_status}");
                        $success = "User status updated to " . ucfirst($new_status);
                    } else {
                        $error = "Failed to update user status.";
                    }
                }
            }
        }
    }
}

// Fetch all users
$stmt = $pdo->query("SELECT id, username, role, status, created_at, failed_attempts FROM users ORDER BY role ASC, created_at DESC");
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F8F9FA;
            --surface: #FFFFFF;
            --text-main: #111827;
            --text-muted: #6B7280;
            --border: #E5E7EB;
            --maroon: #7A102E; 
            --maroon-hover: #5A081E;
            --maroon-light: #FFF5F7;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 40px 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* HEADER */
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; border-bottom: 2px solid var(--border); padding-bottom: 20px; }
        .header h1 { font-family: 'Outfit', sans-serif; font-size: 2.5rem; font-weight: 900; color: var(--text-main); margin: 0; text-transform: uppercase; letter-spacing: -0.02em; }
        .header h1 span { color: var(--maroon); }
        .btn-nav { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 0.9rem; transition: 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .btn-nav:hover { border-color: var(--maroon); color: var(--maroon); transform: translateY(-2px); }

        /* ALERTS */
        .alert { padding: 14px 20px; border-radius: 10px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from{ opacity:0; transform: translateY(-10px); } to{ opacity:1; transform: translateY(0); } }
        .alert-success { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .alert-error { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

        .layout-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start; }

        .card { background: var(--surface); border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid var(--border); }
        .card h2 { font-family: 'Outfit', sans-serif; font-size: 1.3rem; color: var(--maroon); margin-bottom: 20px; text-transform: uppercase; border-bottom: 1px solid var(--maroon-light); padding-bottom: 10px;}

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.75rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.05em; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 0.95rem; background: #F9FAFB; outline: none; transition: 0.2s; }
        .form-group input:focus, .form-group select:focus { border-color: var(--maroon); background: #FFF; box-shadow: 0 0 0 3px var(--maroon-light); }
        
        .btn-submit { background: var(--maroon); color: white; width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 0.95rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn-submit:hover { background: var(--maroon-hover); transform: translateY(-2px); box-shadow: 0 6px 12px rgba(139, 21, 56, 0.2); }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: #F9FAFB; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800; letter-spacing: 0.05em; }
        td { font-size: 0.95rem; }
        
        .badge { padding: 6px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-active { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .badge-pending { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .badge-suspended { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
        .badge-role { background: #EEF2FF; color: #1D4ED8; border: 1px solid #C7D2FE; }

        .action-form { display: inline-flex; gap: 8px; }
        .btn-action { padding: 6px 12px; font-size: 0.75rem; font-weight: 800; border: none; border-radius: 6px; cursor: pointer; font-family: 'Outfit', sans-serif; text-transform: uppercase; transition: 0.2s; }
        .btn-approve { background: #ECFDF5; color: var(--success); border: 1px solid #A7F3D0; }
        .btn-approve:hover { background: var(--success); color: white; }
        .btn-suspend { background: #FEF2F2; color: var(--danger); border: 1px solid #FECACA; }
        .btn-suspend:hover { background: var(--danger); color: white; }

        @media (max-width: 900px) {
            .layout-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; align-items: flex-start; gap: 16px; }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1>Account <span>Management</span></h1>
            <a href="index.php" class="btn-nav">← Back to Command Center</a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="layout-grid">
            
            <div class="card">
                <h2>Create New Account</h2>
                <form method="POST" action="" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="create_user">
                    
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" placeholder="Employee Name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Temporary Password</label>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Account Role</label>
                        <select name="role" required>
                            <option value="sales">Sales Team</option>
                            <option value="project">Project Team</option>
                            <option value="admin">Admin</option>
                            <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                                <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-submit">Create Account</button>
                    <?php if ($_SESSION['user_role'] === 'admin'): ?>
                        <p style="font-size:0.7rem; color:var(--text-muted); margin-top:10px; text-align:center;">*Accounts created by Admins start as 'Pending' until reviewed by Super Admin.</p>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card">
                <h2>System Directory</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Security</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $u): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><span class="badge badge-role"><?= htmlspecialchars(str_replace('_', ' ', $u['role']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <?php 
                                        if ($u['status'] === 'active') echo '<span class="badge badge-active">Active</span>';
                                        elseif ($u['status'] === 'pending') echo '<span class="badge badge-pending">Pending</span>';
                                        else echo '<span class="badge badge-suspended">Suspended</span>';
                                    ?>
                                </td>
                                <td style="font-size: 0.8rem; color: var(--text-muted);">
                                    <?php if($u['failed_attempts'] > 0): ?>
                                        <span style="color:var(--danger); font-weight:bold;"><?= $u['failed_attempts'] ?> Failed Logins</span>
                                    <?php else: ?>
                                        Clean
                                    <?php endif; ?>
                                </td>
                                
                                <?php if ($_SESSION['user_role'] === 'super_admin' || ($u['role'] !== 'super_admin')): ?>
                                <td>
                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                        <form method="POST" action="" class="action-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            
                                            <?php if ($u['status'] !== 'active'): ?>
                                                <button type="submit" name="new_status" value="active" class="btn-action btn-approve" onclick="return confirm('Activate this account?');">Activate</button>
                                            <?php endif; ?>
                                            
                                            <?php if ($u['status'] !== 'suspended'): ?>
                                                <button type="submit" name="new_status" value="suspended" class="btn-action btn-suspend" onclick="return confirm('Suspend this account? They will not be able to log in.');">Suspend</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Current User</span>
                                    <?php endif; ?>
                                </td>
                                <?php else: ?>
                                    <td><span style="font-size: 0.75rem; color: var(--text-muted);">Restricted</span></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</body>
</html>