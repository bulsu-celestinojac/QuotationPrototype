<?php
// admin/index.php
session_start();
require_once '../auth.php';
require_role(['admin', 'super_admin']); // Only admins & super admins access this layer
require '../db.php';
require_once '../functions.php';

// Generate CSRF Token for security actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

// ==========================================
// 1. PROCESS QUOTATION APPROVALS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_quote', 'reject_quote'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $quote_id = (int)$_POST['quote_id'];
        $action = $_POST['action']; 
        $type = $_POST['type']; // 'sales' or 'project'
        $notes = trim($_POST['admin_notes'] ?? '');
        
        $table = ($type === 'project') ? 'project_quotations' : 'sales_quotations';
        $new_status = ($action === 'approve_quote') ? 'pending_super' : 'revision';
        
        try {
            // NOTIFICATION ENGINE WIRED: is_notified = 1 added to flag the user!
            $stmt = $pdo->prepare("UPDATE {$table} SET status = ?, admin_notes = ?, is_notified = 1 WHERE id = ?");
            $stmt->execute([$new_status, $notes, $quote_id]);
            
            $action_text = ($action === 'approve_quote') ? "Approved to Super Admin" : "Requested Revision";
            log_activity($pdo, 'ADMIN_REVIEW', "Admin {$action_text} for {$type} quote ID: {$quote_id}");
            
            $success = "Quotation successfully processed and updated.";
        } catch (Exception $e) {
            $error = "Database error processing quotation.";
        }
    }
}

// ==========================================
// 2. PROCESS INVENTORY APPROVALS (Add/Update)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_inv', 'reject_inv'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $approval_id = $_POST['approval_id'] ?? null;
        $action = $_POST['action']; 

        if ($approval_id) {
            $stmt = $pdo->prepare("SELECT * FROM pending_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch();

            if ($pending) {
                if ($action === 'approve_inv') {
                    try {
                        $pdo->beginTransaction();

                        if ($pending['action_type'] === 'add') {
                            $insertStmt = $pdo->prepare("INSERT INTO items (brand, model_no, description, picture, buying_currency, buying_cost, factor, selling_price, pdf_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $insertStmt->execute([
                                $pending['brand'], $pending['model_no'], $pending['description'], 
                                $pending['picture'], $pending['buying_currency'], $pending['buying_cost'], 
                                $pending['factor'], $pending['selling_price'], $pending['pdf_path']
                            ]);
                            log_activity($pdo, 'ADMIN_REVIEW', "Approved NEW machine request: {$pending['brand']} {$pending['model_no']}");
                            $success = "New machine added to live inventory.";
                        } 
                        elseif ($pending['action_type'] === 'edit') {
                            $updateStmt = $pdo->prepare("UPDATE items SET brand = ?, model_no = ?, description = ?, buying_currency = ?, buying_cost = ?, factor = ?, selling_price = ?, picture = ?, pdf_path = ? WHERE id = ?");
                            $updateStmt->execute([
                                $pending['brand'], $pending['model_no'], $pending['description'], 
                                $pending['buying_currency'], $pending['buying_cost'], $pending['factor'], 
                                $pending['selling_price'], $pending['picture'], $pending['pdf_path'], 
                                $pending['item_id']
                            ]);
                            log_activity($pdo, 'ADMIN_REVIEW', "Approved UPDATE request for Machine ID #{$pending['item_id']}");
                            $success = "Machine #{$pending['item_id']} updated in live inventory.";
                        }

                        $statusStmt = $pdo->prepare("UPDATE pending_approvals SET status = 'approved' WHERE id = ?");
                        $statusStmt->execute([$approval_id]);
                        
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Database error during approval process.";
                    }
                } 
                elseif ($action === 'reject_inv') {
                    $rejectStmt = $pdo->prepare("UPDATE pending_approvals SET status = 'rejected' WHERE id = ?");
                    if ($rejectStmt->execute([$approval_id])) {
                        log_activity($pdo, 'ADMIN_REVIEW', "REJECTED inventory database request for: {$pending['brand']} {$pending['model_no']}");
                        $success = "Inventory request has been rejected and discarded.";
                    }
                }
            } else {
                $error = "This inventory request no longer exists or was already processed.";
            }
        }
    }
}

// ==========================================
// 3. FETCH DATASETS FOR TABS
// ==========================================

$sales_pending = $pdo->query("SELECT * FROM sales_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
$project_pending = $pdo->query("SELECT * FROM project_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();

$pending_inventory = $pdo->query("
    SELECT p.*, u.username as requested_by_name 
    FROM pending_approvals p 
    JOIN users u ON p.requested_by = u.id 
    WHERE p.status = 'pending' 
    ORDER BY p.created_at ASC
")->fetchAll();

$history_logs = $pdo->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 100")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Command Center - AM Group</title>
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
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 40px 20px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        /* HEADER */
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; border-bottom: 2px solid var(--border); padding-bottom: 20px; }
        .header h1 { font-family: 'Outfit', sans-serif; font-size: 2.5rem; font-weight: 900; color: var(--text-main); margin: 0; text-transform: uppercase; letter-spacing: -0.02em; }
        .header h1 span { color: var(--maroon); }
        .header-controls { display: flex; gap: 12px; }
        .btn-nav { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 0.9rem; transition: 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .btn-nav:hover { border-color: var(--maroon); color: var(--maroon); transform: translateY(-2px); }
        .btn-logout { background: #FEF2F2 !important; color: #EF4444 !important; border-color: #FECACA !important; }
        .btn-logout:hover { background: #EF4444 !important; color: white !important; }

        /* ALERTS */
        .alert { padding: 14px 20px; border-radius: 10px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from{ opacity:0; transform: translateY(-10px); } to{ opacity:1; transform: translateY(0); } }
        .alert-success { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; border-left: 4px solid var(--success); }
        .alert-error { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; border-left: 4px solid var(--danger); }

        /* TABS NAVIGATION */
        .tabs-nav { display: flex; gap: 10px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 4px; }
        .tab-btn {
            background: var(--surface); border: 1px solid var(--border); color: var(--text-muted);
            padding: 12px 24px; border-radius: 50px; font-family: 'Outfit', sans-serif; font-size: 0.9rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
            cursor: pointer; transition: all 0.2s ease; white-space: nowrap; box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }
        .tab-btn:hover { border-color: var(--maroon); color: var(--maroon); }
        .tab-btn.active { background: var(--maroon); color: white; border-color: var(--maroon); box-shadow: 0 8px 16px rgba(122, 16, 46, 0.2); }

        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* CARD & TABLE SYSTEM */
        .card { background: var(--surface); border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid var(--border); }
        .card h2 { font-family: 'Outfit', sans-serif; font-size: 1.4rem; color: var(--maroon); margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }
        
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: #F9FAFB; font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800; letter-spacing: 0.05em; border-top: 1px solid var(--border); }
        td { font-size: 0.95rem; vertical-align: middle; }
        tr:hover td { background: #F9FAFB; }

        /* QUOTE CONTROLS */
        .quote-form { display: flex; align-items: center; gap: 10px; margin: 0; }
        .note-input { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 0.85rem; width: 200px; outline: none; transition: 0.2s; }
        .note-input:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px var(--maroon-light); }

        /* BUTTONS */
        .btn { padding: 10px 16px; font-weight: 800; border: none; border-radius: 8px; cursor: pointer; font-size: 0.8rem; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-family: 'Outfit', sans-serif; text-transform: uppercase; letter-spacing: 0.05em; }
        .btn-pdf { background: #EEF2FF; color: #1D4ED8; border: 1px solid #C7D2FE; }
        .btn-pdf:hover { background: #1D4ED8; color: white; }
        .btn-approve { background: #ECFDF5; color: var(--success); border: 1px solid #A7F3D0; }
        .btn-approve:hover { background: var(--success); color: white; }
        .btn-reject { background: #FEF2F2; color: var(--danger); border: 1px solid #FECACA; }
        .btn-reject:hover { background: var(--danger); color: white; }

        /* BADGES */
        .badge { padding: 6px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-add { background: #EEF2FF; color: #1D4ED8; border: 1px solid #C7D2FE; }
        .badge-edit { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }

        /* INVENTORY APPROVAL CARDS */
        .request-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 12px 30px rgba(122, 16, 46, 0.03); margin-bottom: 24px; overflow: hidden; transition: transform 0.2s; }
        .request-card:hover { border-color: #D1D5DB; box-shadow: 0 15px 35px rgba(122, 16, 46, 0.08); }
        .card-header { background: #FAFAFA; padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .request-meta { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }
        .request-meta strong { color: var(--text-main); font-weight: 700; }
        .card-body { padding: 24px; }
        .data-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px 16px; }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }
        .data-group { display: flex; flex-direction: column; gap: 4px; }
        .data-label { font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .data-value { font-size: 1rem; font-weight: 600; color: var(--text-main); background: #F9FAFB; padding: 10px 12px; border-radius: 8px; border: 1px solid #E5E7EB; }
        .data-value.price { color: var(--maroon); font-weight: 800; background: var(--maroon-light); border-color: #FCE7F3; }
        .media-link { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 700; color: var(--maroon); text-decoration: none; padding: 8px 12px; border-radius: 6px; background: var(--maroon-light); border: 1px solid #FCE7F3; transition: all 0.2s; }
        .media-link:hover { background: var(--maroon); color: white; }
        .card-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #FFFFFF; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); font-weight: 500; border: 2px dashed var(--border); border-radius: 16px; background: var(--surface); }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1>Admin <span>Command Center</span></h1>
            <div class="header-controls">
                <a href="users.php" class="btn-nav">👥 Manage Users</a>
                <a href="../index.php" class="btn-nav">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    Employee Portal
                </a>
                <a href="../logout.php" class="btn-nav btn-logout">Logout</a>
            </div>
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

        <div class="tabs-nav">
            <button class="tab-btn active" onclick="openTab('tab-quotes', this)">📄 Quotation Review</button>
            <button class="tab-btn" onclick="openTab('tab-inventory', this)">📦 Inventory Approvals</button>
            <button class="tab-btn" onclick="openTab('tab-history', this)">🕒 Activity History</button>
        </div>

        <div id="tab-quotes" class="tab-content active">
            
            <div class="card">
                <h2>Sales Quotations (Pending Admin)</h2>
                <?php if (empty($sales_pending)): ?>
                    <div class="empty-state">No pending sales quotations require approval right now.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th>Quote No</th>
                                <th>Client / Submitted By</th>
                                <th>Date</th>
                                <th>Action Notes</th>
                                <th>Controls</th>
                            </tr>
                            <?php foreach($sales_pending as $q): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($q['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <div style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($q['client_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">By: Sales Team</div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($q['quote_date'])); ?></td>
                                <td colspan="2">
                                    <form method="POST" class="quote-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="quote_id" value="<?php echo $q['id']; ?>">
                                        <input type="hidden" name="type" value="sales">
                                        
                                        <a href="../generate_pdf.php?id=<?php echo $q['id']; ?>&type=sales" target="_blank" class="btn btn-pdf">View PDF</a>
                                        
                                        <input type="text" name="admin_notes" class="note-input" placeholder="Notes (Optional)...">
                                        
                                        <button type="submit" name="action" value="approve_quote" class="btn btn-approve" onclick="return confirm('Send to Super Admin for final check?');">Approve</button>
                                        <button type="submit" name="action" value="reject_quote" class="btn btn-reject" onclick="return confirm('Reject and request revision?');">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Project Quotations (Pending Admin)</h2>
                <?php if (empty($project_pending)): ?>
                    <div class="empty-state">No pending project quotations require approval right now.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th>Quote No</th>
                                <th>Project / Submitted By</th>
                                <th>Date</th>
                                <th>Action Notes</th>
                                <th>Controls</th>
                            </tr>
                            <?php foreach($project_pending as $p): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($p['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <div style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($p['project_name'] ?? 'Project', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">By: Project Team</div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($p['quote_date'])); ?></td>
                                <td colspan="2">
                                    <form method="POST" class="quote-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="quote_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="type" value="project">
                                        
                                        <a href="../generate_pdf.php?id=<?php echo $p['id']; ?>&type=project" target="_blank" class="btn btn-pdf">View PDF</a>
                                        
                                        <input type="text" name="admin_notes" class="note-input" placeholder="Notes (Optional)...">
                                        
                                        <button type="submit" name="action" value="approve_quote" class="btn btn-approve" onclick="return confirm('Send to Super Admin for final check?');">Approve</button>
                                        <button type="submit" name="action" value="reject_quote" class="btn btn-reject" onclick="return confirm('Reject and request revision?');">Reject</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <div id="tab-inventory" class="tab-content">
            <?php if (empty($pending_inventory)): ?>
                <div class="empty-state">
                    <h2>You're all caught up!</h2>
                    <p>There are currently no new machines or price updates waiting for approval.</p>
                </div>
            <?php else: ?>
                <?php foreach ($pending_inventory as $req): ?>
                    <div class="request-card">
                        <div class="card-header">
                            <div class="badge-group">
                                <?php if ($req['action_type'] === 'add'): ?>
                                    <span class="badge badge-add">➕ NEW Addition</span>
                                <?php else: ?>
                                    <span class="badge badge-edit">✏️ UPDATE (ID: #<?php echo $req['item_id']; ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="request-meta">
                                Requested by <strong><?php echo htmlspecialchars(ucfirst($req['requested_by_name']), ENT_QUOTES, 'UTF-8'); ?></strong> 
                                on <?php echo date('M d, Y - h:i A', strtotime($req['created_at'])); ?>
                            </div>
                        </div>

                        <div class="card-body">
                            <div class="data-grid">
                                <div class="data-group span-2">
                                    <span class="data-label">Brand</span>
                                    <div class="data-value"><?php echo htmlspecialchars($req['brand'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="data-group span-2">
                                    <span class="data-label">Model Number</span>
                                    <div class="data-value"><?php echo htmlspecialchars($req['model_no'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="data-group span-4">
                                    <span class="data-label">Description</span>
                                    <div class="data-value desc"><?php echo htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="data-group">
                                    <span class="data-label">Currency</span>
                                    <div class="data-value"><?php echo htmlspecialchars($req['buying_currency'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="data-group">
                                    <span class="data-label">Buying Cost</span>
                                    <div class="data-value"><?php echo number_format($req['buying_cost'], 2); ?></div>
                                </div>
                                <div class="data-group">
                                    <span class="data-label">Factor</span>
                                    <div class="data-value"><?php echo htmlspecialchars($req['factor'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="data-group">
                                    <span class="data-label">Proposed Selling Price</span>
                                    <div class="data-value price">₱ <?php echo number_format($req['selling_price'], 2); ?></div>
                                </div>

                                <?php if (!empty($req['picture']) || !empty($req['pdf_path'])): ?>
                                    <div class="data-group span-4" style="flex-direction: row; gap: 16px; margin-top: 10px;">
                                        <?php if (!empty($req['picture'])): ?>
                                            <a href="../../images/machine_images/<?php echo htmlspecialchars($req['picture'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="media-link">🖼️ View Uploaded Image</a>
                                        <?php endif; ?>
                                        <?php if (!empty($req['pdf_path'])): ?>
                                            <a href="../../pdfs/machine_pdfs/<?php echo htmlspecialchars($req['pdf_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="media-link">📄 View Attached PDF</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-footer">
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to REJECT and delete this request?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="approval_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" name="action" value="reject_inv" class="btn btn-reject">Reject Request</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this request? This will instantly update the live inventory.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="approval_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" name="action" value="approve_inv" class="btn btn-approve">Approve & Publish</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="tab-history" class="tab-content">
            <div class="card">
                <h2>System Activity Logs (Last 100 Actions)</h2>
                <?php if (empty($history_logs)): ?>
                    <div class="empty-state">No history recorded yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th>Timestamp</th>
                                <th>Action Type</th>
                                <th>Details</th>
                            </tr>
                            <?php foreach($history_logs as $log): ?>
                            <tr>
                                <td style="white-space: nowrap; color: var(--text-muted); font-size: 0.85rem;">
                                    <?php echo date('M d, Y - h:i:s A', strtotime($log['created_at'])); ?>
                                </td>
                                <td><span class="badge" style="background:#F3F4F6; color:var(--text-main); border:1px solid #E5E7EB;"><?php echo htmlspecialchars($log['action'] ?? 'SYSTEM_EVENT', ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($log['details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        function openTab(tabId, btnElement) {
            // Hide all tabs
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab and set button as active
            document.getElementById(tabId).classList.add('active');
            btnElement.classList.add('active');
        }
    </script>
</body>
</html>