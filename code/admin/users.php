<?php
require_once '../auth.php';
require_role(['admin', 'super_admin']); // Only Admins and Super Admins
require '../db.php';
require_once '../functions.php';

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. CREATE NEW USER
    if (isset($_POST['action']) && $_POST['action'] === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'sales'; // Default to standard employee
        
        // Prevent admins from creating super admins
        if ($role === 'super_admin' && $_SESSION['user_role'] !== 'super_admin') {
            $error = "Only a Super Admin can create another Super Admin.";
        } elseif (empty($username) || empty($password)) {
            $error = "Username and Password are required.";
        } else {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username already exists. Please choose another.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Hierarchy: Super Admins create active users. Admins create pending users.
                $status = ($_SESSION['user_role'] === 'super_admin') ? 'active' : 'pending';
                
                $insertStmt = $pdo->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, ?)");
                if ($insertStmt->execute([$username, $hashed_password, $role, $status])) {
                    log_activity($pdo, 'USER_CREATED', "Created new {$role} account: {$username} (Status: {$status})");
                    $success = "User account created successfully! " . ($status === 'pending' ? "Waiting for Super Admin approval." : "");
                } else {
                    $error = "Failed to create user account.";
                }
            }
        }
    }
    
    // 2. CHANGE USER STATUS (Super Admin Only)
    if (isset($_POST['action']) && $_POST['action'] === 'change_status' && $_SESSION['user_role'] === 'super_admin') {
        $target_user_id = (int)$_POST['user_id'];
        $new_status = $_POST['new_status'];
        
        // Prevent Super Admin from suspending themselves
        if ($target_user_id === $_SESSION['user_id']) {
            $error = "You cannot change your own account status.";
        } else {
            $updateStmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $updateStmt->execute([$new_status, $target_user_id]);
            log_activity($pdo, 'USER_UPDATED', "Changed status of User ID {$target_user_id} to {$new_status}");
            $success = "User status updated to {$new_status}.";
        }
    }
}

// Fetch all users
$stmtUsers = $pdo->query("SELECT id, username, role, status, created_at FROM users ORDER BY created_at DESC");
$usersList = $stmtUsers->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>User Management - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: #FFFFFF; --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; --maroon-light: #FFF5F7; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 40px 30px; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 900; text-transform: uppercase; line-height: 1; }
        .page-title .accent { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; transition: 0.2s; }
        .btn-back:hover { color: var(--maroon); }

        .grid-layout { display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start; }
        
        .card { background: var(--surface); border-radius: 20px; padding: 30px; border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(139, 21, 56, 0.03); }
        .card h2 { font-family: 'Outfit', sans-serif; font-size: 1.5rem; color: var(--maroon); margin-bottom: 20px; text-transform: uppercase; }

        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 0.75rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 12px 16px; border-radius: 12px; border: 1px solid var(--border); background: #FAFAFA; font-size: 1rem; font-family: 'DM Sans', sans-serif; transition: all 0.3s ease; outline: none; }
        input:focus, select:focus { border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); background: var(--surface); }
        
        .btn-submit { background: var(--maroon); color: white; width: 100%; height: 50px; border: none; border-radius: 50px; font-family: 'Outfit', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; margin-top: 10px; }
        .btn-submit:hover { background: #5A0000; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: var(--maroon-light); color: var(--maroon); font-family: 'Outfit', sans-serif; text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.05em; border-bottom: 2px solid var(--border); }
        
        .badge { padding: 6px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-active { background: #D1FAE5; color: #065F46; }
        .badge-pending { background: #FEF3C7; color: #92400E; }
        .badge-suspended { background: #FEE2E2; color: #991B1B; }

        .alert { padding: 16px; border-radius: 12px; font-weight: 600; margin-bottom: 24px; text-align: center; }
        .alert-error { background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); }
        .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #34D399; }

        .action-form { display: inline-flex; gap: 8px; }
        .btn-action { padding: 6px 12px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; border: none; cursor: pointer; transition: 0.2s; }
        .btn-approve { background: #10B981; color: white; }
        .btn-suspend { background: #EF4444; color: white; }
        
        @media (max-width: 900px) {
            .grid-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="page-title">User <span class="accent">Management</span></h1>
            <a href="../index.php" class="btn-back">← Back to Dashboard</a>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <div class="grid-layout">
            
            <div class="card">
                <h2>Create Account</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_user">
                    
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" required autocomplete="off">
                    </div>
                    
                    <div class="form-group">
                        <label>Temporary Password</label>
                        <input type="password" name="password" required autocomplete="new-password">
                    </div>
                    
                    <div class="form-group">
                        <label>Account Role</label>
                        <select name="role" required>
                            <option value="sales">Employee (Standard Access)</option>
                            <option value="admin">Administrator (Level 1)</option>
                            <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                                <option value="super_admin">Super Admin (Full Authority)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-submit">Register User</button>
                </form>
            </div>

            <div class="card" style="overflow-x: auto;">
                <h2>System Users</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Date Created</th>
                            <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersList as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                            <td style="text-transform: uppercase; font-size: 0.8rem; font-weight: 700; color: var(--text-muted);">
                                <?= str_replace('_', ' ', htmlspecialchars($u['role'])) ?>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'active'): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php elseif ($u['status'] === 'pending'): ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-suspended">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.85rem; color: var(--text-muted);">
                                <?= date('M d, Y', strtotime($u['created_at'])) ?>
                            </td>
                            
                            <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                            <td>
                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                    <form method="POST" action="" class="action-form">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        
                                        <?php if ($u['status'] !== 'active'): ?>
                                            <button type="submit" name="new_status" value="active" class="btn-action btn-approve">Approve / Activate</button>
                                        <?php endif; ?>
                                        
                                        <?php if ($u['status'] !== 'suspended'): ?>
                                            <button type="submit" name="new_status" value="suspended" class="btn-action btn-suspend">Suspend</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Current User</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</body>
</html>