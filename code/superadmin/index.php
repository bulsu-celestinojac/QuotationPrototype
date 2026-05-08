<?php
// superadmin/index.php
require_once '../auth.php';
require_role(['super_admin']); // STRICT: Only Super Admin
require '../db.php';

$success = '';

// Final Approval Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_id'], $_POST['action'], $_POST['type'])) {
    $quote_id = (int)$_POST['quote_id'];
    $action = $_POST['action']; // 'approve' or 'revision'
    $type = $_POST['type']; 
    $notes = trim($_POST['admin_notes'] ?? '');
    
    $table = ($type === 'project') ? 'project_quotations' : 'sales_quotations';
    $new_status = ($action === 'approve') ? 'approved' : 'revision';
    
    $stmt = $pdo->prepare("UPDATE {$table} SET status = ?, admin_notes = ? WHERE id = ?");
    $stmt->execute([$new_status, $notes, $quote_id]);
    
    log_activity($pdo, 'SUPER_REVIEW', "Super Admin finalized {$type} quote ID: {$quote_id} to status: {$new_status}");
    $success = "Final review processed successfully.";
}

// Fetch Quotes pending SUPER admin
$sales_final = $pdo->query("SELECT * FROM sales_quotations WHERE status = 'pending_super' ORDER BY created_at DESC")->fetchAll();
$project_final = $pdo->query("SELECT * FROM project_quotations WHERE status = 'pending_super' ORDER BY created_at DESC")->fetchAll();

// Fetch Audit Logs
$logs = $pdo->query("SELECT l.*, u.username FROM activity_logs l JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Super Admin Console</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #18181B; color: #FAFAFA; padding: 40px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .card { background: #27272A; padding: 30px; border-radius: 16px; margin-bottom: 30px; border: 1px solid #3F3F46; }
        h1 { font-family: 'Outfit', sans-serif; font-size: 2.5rem; color: #F43F5E; text-transform: uppercase; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #3F3F46; color: #E4E4E7; }
        th { background: #3F3F46; color: #F43F5E; font-family: 'Outfit', sans-serif; text-transform: uppercase; }
        .btn { padding: 8px 16px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; color: white; }
        .btn-approve { background: #10B981; }
        .btn-reject { background: #EF4444; }
        textarea { width: 100%; padding: 8px; border-radius: 8px; background: #3F3F46; color: white; border: 1px solid #52525B; }
        .badge { background: #3F3F46; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-family: monospace; color: #F43F5E; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>Final Authority <span style="color: #FAFAFA;">Console</span></h1>
            <a href="../logout.php" class="btn" style="background: #F43F5E; text-decoration: none;">Emergency Logout</a>
        </div>
        
        <?php if ($success) echo "<div style='background: #064E3B; color: #34D399; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>$success</div>"; ?>

        <div class="card">
            <h2>Awaiting Final Approval</h2>
            <table>
                <tr><th>Type</th><th>Quote No</th><th>Client/Project</th><th>Notes from Admin</th><th>Controls</th></tr>
                <?php foreach($sales_final as $q): ?>
                <tr>
                    <td><span class="badge">SALES</span></td>
                    <td><strong><?= htmlspecialchars($q['quotation_no']) ?></strong></td>
                    <td><?= htmlspecialchars($q['client_name']) ?></td>
                    <td><em><?= htmlspecialchars($q['admin_notes']) ?></em></td>
                    <form method="POST">
                        <input type="hidden" name="quote_id" value="<?= $q['id'] ?>"><input type="hidden" name="type" value="sales">
                        <td>
                            <button type="submit" name="action" value="approve" class="btn btn-approve">Finalize & Unlock PDF</button>
                            <button type="submit" name="action" value="revision" class="btn btn-reject">Return to User</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card">
            <h2>System Audit Trail (Live)</h2>
            <table>
                <tr><th>Timestamp</th><th>User</th><th>Action Flag</th><th>Details</th><th>IP Address</th></tr>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td style="color: #34D399; font-weight: bold;"><?= htmlspecialchars($log['username']) ?></td>
                    <td><span class="badge"><?= htmlspecialchars($log['action_type']) ?></span></td>
                    <td><?= htmlspecialchars($log['details']) ?></td>
                    <td style="font-size: 0.8rem; color: #A1A1AA;"><?= htmlspecialchars($log['ip_address']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</body>
</html>