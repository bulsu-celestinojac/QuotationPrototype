<?php
// admin/index.php
require_once '../auth.php';
require_role(['admin', 'super_admin']); // Only admins access this layer natively
require '../db.php';
require_once '../functions.php';

$success = '';

// ==========================================
// 1. Handle New Item Approvals
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_item') {
    $item_id = (int)$_POST['item_id'];
    $stmt = $pdo->prepare("UPDATE items SET status = 'active' WHERE id = ?");
    $stmt->execute([$item_id]);
    
    log_activity($pdo, 'ADMIN_REVIEW', "Admin approved pending Item ID: {$item_id} into live inventory.");
    $success = "Item ID {$item_id} has been approved and is now live in the inventory!";
}

// ==========================================
// 2. Handle Quotation Approvals / Rejections
// ==========================================
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

// Fetch Pending Datasets
$pending_items = $pdo->query("SELECT * FROM items WHERE status = 'pending_approval' ORDER BY id DESC")->fetchAll();
$sales_pending = $pdo->query("SELECT * FROM sales_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
$project_pending = $pdo->query("SELECT * FROM project_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #8B1538;
            --bg-light: #F8F6F5;
            --surface: #FFFFFF;
            --text-main: #2A0808;
            --border: #E8D8D7;
            --success: #10B981;
            --danger: #EF4444;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg-light); color: var(--text-main); margin: 0; padding: 40px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; border-bottom: 2px solid var(--border); padding-bottom: 20px; }
        h1 { font-family: 'Outfit', sans-serif; font-size: 2.5rem; font-weight: 900; color: var(--primary); margin: 0; text-transform: uppercase; }
        
        .btn-nav { display: inline-block; padding: 12px 24px; background: var(--surface); border: 2px solid var(--border); border-radius: 8px; color: var(--text-main); text-decoration: none; font-weight: 700; transition: 0.3s; }
        .btn-nav:hover { background: var(--primary); color: white; border-color: var(--primary); }

        .card { background: var(--surface); border-radius: 16px; padding: 30px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .card h2 { font-family: 'Outfit', sans-serif; font-size: 1.5rem; margin-top: 0; margin-bottom: 20px; color: var(--text-main); border-bottom: 1px solid var(--border); padding-bottom: 10px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: #FAFAFA; font-size: 0.8rem; text-transform: uppercase; color: #8C7373; font-weight: 800; letter-spacing: 0.05em; }
        td { font-size: 0.95rem; vertical-align: middle; }

        .btn { padding: 8px 16px; font-weight: 700; border: none; border-radius: 6px; cursor: pointer; text-transform: uppercase; font-size: 0.75rem; transition: 0.3s; }
        .btn-approve { background: #ECFDF5; color: var(--success); border: 1px solid #A7F3D0; }
        .btn-approve:hover { background: var(--success); color: white; }
        .btn-reject { background: #FEF2F2; color: var(--danger); border: 1px solid #FECACA; }
        .btn-reject:hover { background: var(--danger); color: white; }
        
        textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; resize: vertical; min-height: 40px; font-family: 'DM Sans', sans-serif; }
        
        .alert-success { background: #ECFDF5; color: #065F46; padding: 16px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #A7F3D0; font-weight: 600; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Admin Command Center</h1>
            <a href="../index.php" class="btn-nav">← Back to Dashboard</a>
        </div>

        <?php if ($success): ?>
            <div class="alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Inventory Items (Pending Approval)</h2>
            <table>
                <tr>
                    <th>Brand</th>
                    <th>Model No.</th>
                    <th>Proposed Price</th>
                    <th>Action</th>
                </tr>
                <?php foreach($pending_items as $item): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($item['brand'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td style="font-family: 'Outfit', sans-serif; font-weight: 700;"><?php echo htmlspecialchars($item['model_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>₱<?php echo number_format($item['selling_price'], 2); ?></td>
                    <td>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" name="action" value="approve_item" class="btn btn-approve">Approve to Live Catalog</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$pending_items) echo "<tr><td colspan='4' style='text-align:center; color:#8C7373;'>No new inventory items pending approval.</td></tr>"; ?>
            </table>
        </div>

        <div class="card">
            <h2>Sales Quotes (Pending Admin Review)</h2>
            <table>
                <tr>
                    <th>Quote No</th>
                    <th>Client</th>
                    <th>Date</th>
                    <th>Action Notes</th>
                    <th>Controls</th>
                </tr>
                <?php foreach($sales_pending as $q): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($q['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><?php echo htmlspecialchars($q['client_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($q['quote_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <form method="POST">
                        <input type="hidden" name="quote_id" value="<?php echo $q['id']; ?>">
                        <input type="hidden" name="type" value="sales">
                        <td><textarea name="admin_notes" placeholder="Reason if rejecting..."></textarea></td>
                        <td>
                            <button type="submit" name="action" value="approve" class="btn btn-approve">Approve</button>
                            <button type="submit" name="action" value="revision" class="btn btn-reject" style="margin-left: 5px;">Reject</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if(!$sales_pending) echo "<tr><td colspan='5' style='text-align:center; color:#8C7373;'>No pending sales quotes.</td></tr>"; ?>
            </table>
        </div>

        <div class="card">
            <h2>Project Quotes (Pending Admin Review)</h2>
            <table>
                <tr>
                    <th>Quote No</th>
                    <th>Project Name</th>
                    <th>Date</th>
                    <th>Action Notes</th>
                    <th>Controls</th>
                </tr>
                <?php foreach($project_pending as $p): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($p['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><?php echo htmlspecialchars($p['project_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($p['quote_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <form method="POST">
                        <input type="hidden" name="quote_id" value="<?php echo $p['id']; ?>">
                        <input type="hidden" name="type" value="project">
                        <td><textarea name="admin_notes" placeholder="Reason if rejecting..."></textarea></td>
                        <td>
                            <button type="submit" name="action" value="approve" class="btn btn-approve">Approve</button>
                            <button type="submit" name="action" value="revision" class="btn btn-reject" style="margin-left: 5px;">Reject</button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
                <?php if(!$project_pending) echo "<tr><td colspan='5' style='text-align:center; color:#8C7373;'>No pending project quotes.</td></tr>"; ?>
            </table>
        </div>
    </div>
</body>
</html>