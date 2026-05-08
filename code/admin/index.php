<?php
// admin/index.php
require_once '../auth.php';
require_role(['admin']); // Only standard admins can access this level natively
require '../db.php';

$success = '';

// Handle Approval/Rejection Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_id'], $_POST['action'], $_POST['type'])) {
    $quote_id = (int)$_POST['quote_id'];
    $action = $_POST['action']; // 'approve' or 'revision'
    $type = $_POST['type']; // 'sales' or 'project'
    $notes = trim($_POST['admin_notes'] ?? '');
    
    $table = ($type === 'project') ? 'project_quotations' : 'sales_quotations';
    $new_status = ($action === 'approve') ? 'pending_super' : 'revision';
    
    $stmt = $pdo->prepare("UPDATE {$table} SET status = ?, admin_notes = ? WHERE id = ?");
    $stmt->execute([$new_status, $notes, $quote_id]);
    
    $action_text = ($action === 'approve') ? "Approved to Super Admin" : "Requested Revision";
    log_activity($pdo, 'ADMIN_REVIEW', "Admin {$action_text} for {$type} quote ID: {$quote_id}");
    
    $success = "Quotation successfully processed.";
}

// Fetch Pending Quotes
$sales_pending = $pdo->query("SELECT * FROM sales_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
$project_pending = $pdo->query("SELECT * FROM project_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Approval Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #F8F6F5; color: #2A0808; padding: 40px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 30px; border-radius: 16px; margin-bottom: 30px; border: 1px solid #E8D8D7; box-shadow: 0 10px 30px rgba(0,0,0,0.03); }
        h1 { font-family: 'Outfit', sans-serif; font-size: 2.5rem; color: #8B1538; text-transform: uppercase; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #E8D8D7; }
        th { background: #FFF5F7; color: #8B1538; font-family: 'Outfit', sans-serif; text-transform: uppercase; }
        .btn { padding: 8px 16px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; color: white; }
        .btn-approve { background: #10B981; }
        .btn-reject { background: #EF4444; }
        textarea { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h1>Level 1 <span style="color: #2A0808;">Approvals</span></h1>
            <a href="../logout.php" class="btn" style="background: #2A0808; text-decoration: none;">Logout</a>
        </div>
        
        <?php if ($success) echo "<div style='background: #D1FAE5; color: #065F46; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>$success</div>"; ?>

        <div class="card">
            <h2>Sales Quotes (Pending Admin)</h2>
            <table>
                <tr><th>Quote No</th><th>Client</th><th>Date</th><th>Action Notes</th><th>Controls</th></tr>
                <?php foreach($sales_pending as $q): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($q['quotation_no']) ?></strong></td>
                    <td><?= htmlspecialchars($q['client_name']) ?></td>
                    <td><?= htmlspecialchars($q['quote_date']) ?></td>
                    <form method="POST">
                        <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                        <input type="hidden" name="type" value="sales">
                        <td><textarea name="admin_notes" placeholder="Reason if rejecting..."></textarea></td>
                        <td>
                            <button type="submit" name="action" value="approve" class="btn btn-approve">Approve</button>
                            <button type="submit" name="action" value="revision" class="btn btn-reject">Reject</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if(!$sales_pending) echo "<tr><td colspan='5'>No pending sales quotes.</td></tr>"; ?>
            </table>
        </div>
        
        <div class="card">
            <h2>Project Quotes (Pending Admin)</h2>
            <table>
                <tr><th>Quote No</th><th>Project Name</th><th>Date</th><th>Action Notes</th><th>Controls</th></tr>
                <?php foreach($project_pending as $p): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($p['quotation_no']) ?></strong></td>
                    <td><?= htmlspecialchars($p['project_name']) ?></td>
                    <td><?= htmlspecialchars($p['quote_date']) ?></td>
                    <form method="POST">
                        <input type="hidden" name="quote_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="type" value="project">
                        <td><textarea name="admin_notes" placeholder="Reason if rejecting..."></textarea></td>
                        <td>
                            <button type="submit" name="action" value="approve" class="btn btn-approve">Approve</button>
                            <button type="submit" name="action" value="revision" class="btn btn-reject">Reject</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if(!$project_pending) echo "<tr><td colspan='5'>No pending project quotes.</td></tr>"; ?>
            </table>
        </div>
    </div>
</body>
</html>