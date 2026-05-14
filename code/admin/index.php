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
        $type = $_POST['type']; 
        $notes = ''; // Notes removed per request
        
        $table = ($type === 'project') ? 'project_quotations' : 'sales_quotations';
        $new_status = ($action === 'approve_quote') ? 'pending_super' : 'revision';
        
        try {
            $stmt = $pdo->prepare("UPDATE {$table} SET status = ?, admin_notes = ?, is_notified = 1 WHERE id = ?");
            $stmt->execute([$new_status, $notes, $quote_id]);
            
            // AUTO-SYNC PRICE ENGINE (Updates Master Inventory)
            if ($action === 'approve_quote' && $type === 'sales') {
                $stmtFetchItems = $pdo->prepare("SELECT item_id, unit_price FROM sales_quotation_items WHERE quotation_id = ?");
                $stmtFetchItems->execute([$quote_id]);
                $quoteItems = $stmtFetchItems->fetchAll();
                
                $stmtUpdateMasterPrice = $pdo->prepare("UPDATE items SET selling_price = ? WHERE id = ?");
                foreach ($quoteItems as $qi) {
                    $stmtUpdateMasterPrice->execute([$qi['unit_price'], $qi['item_id']]);
                }
            }
            
            $success = "Quotation successfully processed.";
        } catch (Exception $e) {
            $error = "Database error processing quotation.";
        }
    }
}

// ==========================================
// 2. PROCESS INVENTORY APPROVALS
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
// 3. FETCH DATASETS & COUNTS
// ==========================================
// Fetch Pending Sales + Username
$sales_pending = $pdo->query("SELECT sq.*, u.username FROM sales_quotations sq LEFT JOIN users u ON sq.user_id = u.id WHERE sq.status = 'pending_admin' ORDER BY sq.created_at DESC")->fetchAll();
$sales_count = count($sales_pending);

// Fetch Pending Projects 
$project_pending = $pdo->query("SELECT * FROM project_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
$project_count = count($project_pending);

// Fetch Pending Inventory
$pending_inventory = $pdo->query("SELECT p.*, u.username as requested_by_name FROM pending_approvals p JOIN users u ON p.requested_by = u.id WHERE p.status = 'pending' ORDER BY p.created_at ASC")->fetchAll();
$inv_count = count($pending_inventory);

// Fetch Quotation History (Fixed: Using created_at instead of updated_at)
$history_stmt = $pdo->query("
    SELECT 'Sales' as quote_type, quotation_no, client_name as reference_name, status, created_at 
    FROM sales_quotations 
    WHERE status != 'pending_admin' AND status != 'draft'
    UNION ALL
    SELECT 'Project' as quote_type, quotation_no, project_name as reference_name, status, created_at 
    FROM project_quotations 
    WHERE status != 'pending_admin' AND status != 'draft'
    ORDER BY created_at DESC
    LIMIT 150
");
$quote_history = $history_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Command Center - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --bg: #FAFAFA; 
            --surface: #FFFFFF; 
            --text-main: #18181B; 
            --text-muted: #71717A; 
            --text-light: #A1A1AA; 
            --border: #E4E4E7; 
            --maroon: #8B1538; 
            --maroon-hover: #6A0D28;
            --maroon-light: #FFF5F7; 
            --input-bg: #F4F4F5;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 30px 20px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        /* HEADER */
        .header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 20px; flex-wrap: wrap; gap: 20px; }
        .header h1 { font-family: 'Outfit', sans-serif; font-size: clamp(1.8rem, 4vw, 2.8rem); font-weight: 900; text-transform: uppercase; letter-spacing: -0.02em; line-height: 1; color: var(--text-main); }
        .header h1 span { color: var(--maroon); }
        
        .header-controls { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-nav { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 50px; color: var(--text-main); text-decoration: none; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.8rem; transition: all 0.3s ease; box-shadow: 0 4px 10px rgba(0,0,0,0.02); white-space: nowrap; }
        .btn-nav:hover { border-color: var(--maroon); color: var(--maroon); transform: translateY(-2px); box-shadow: 0 8px 15px rgba(139, 21, 56, 0.08); }
        .btn-logout { background: #FEF2F2 !important; color: #EF4444 !important; border-color: #FECACA !important; }
        .btn-logout:hover { background: #EF4444 !important; color: white !important; box-shadow: 0 8px 15px rgba(239, 68, 68, 0.2); }

        /* ALERTS */
        .alert { padding: 14px 20px; border-radius: 12px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from{ opacity:0; transform: translateY(-10px); } to{ opacity:1; transform: translateY(0); } }
        .alert-success { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .alert-error { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

        /* TABS NAVIGATION */
        .tabs-nav { display: flex; gap: 8px; margin-bottom: 30px; overflow-x: auto; padding-bottom: 4px; border-bottom: 1px solid var(--border); -webkit-overflow-scrolling: touch; scrollbar-width: none; }
        .tabs-nav::-webkit-scrollbar { display: none; }
        .tab-btn {
            background: transparent; border: none; border-bottom: 3px solid transparent; color: var(--text-muted);
            padding: 12px 16px; font-family: 'Outfit', sans-serif; font-size: 0.95rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
            cursor: pointer; transition: all 0.3s ease; white-space: nowrap; border-radius: 0; display: flex; align-items: center; gap: 8px;
        }
        .tab-btn:hover { color: var(--maroon); }
        .tab-btn.active { color: var(--maroon); border-bottom-color: var(--maroon); }
        
        .badge-count { background: var(--danger); color: white; padding: 2px 8px; border-radius: 50px; font-size: 0.75rem; font-weight: 900; line-height: 1; }

        .tab-content { display: none; animation: fadeIn 0.4s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* CARDS */
        .card { background: var(--surface); border-radius: 20px; padding: 30px; margin-bottom: 32px; border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; }
        .card h2 { font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 800; margin-bottom: 24px; border-bottom: 2px solid var(--border); padding-bottom: 16px; color: var(--text-main); }
        
        /* TABLES */
        .table-responsive { overflow-x: auto; margin: 0 -10px; padding: 0 10px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 16px 14px; text-align: left; border-bottom: 1px solid var(--border); }
        th { font-size: 0.75rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; background: #FAFAF9; border-radius: 8px 8px 0 0; }
        td { font-size: 0.95rem; font-weight: 500; vertical-align: middle; }
        tr:hover td { background: #FAFAF9; }

        /* CONTROLS */
        .quote-form { display: flex; align-items: center; gap: 10px; margin: 0; }
        
        /* BUTTONS */
        .btn { padding: 10px 16px; font-weight: 800; border: none; border-radius: 50px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-family: 'Outfit', sans-serif; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
        .btn-pdf { background: #FFF5F7; color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); }
        .btn-pdf:hover { background: var(--maroon); color: white; border-color: var(--maroon); }
        .btn-approve { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .btn-approve:hover { background: #047857; color: white; border-color: #047857; }
        .btn-reject { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
        .btn-reject:hover { background: #B91C1C; color: white; border-color: #B91C1C; }

        /* BADGES */
        .badge { padding: 6px 12px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; display: inline-block; }
        .badge-add { background: #FFF5F7; color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); }
        .badge-edit { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .b-super { background: #EEF2FF; color: #1D4ED8; border: 1px solid #C7D2FE; }
        .b-rev { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
        .b-app { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }

        /* INVENTORY APPROVAL CARDS */
        .request-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.02); margin-bottom: 24px; overflow: hidden; transition: all 0.3s ease; }
        .card-header { background: #FAFAF9; padding: 16px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .request-meta { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; }
        .request-meta strong { color: var(--text-main); font-weight: 800; }
        .card-body { padding: 24px; }
        .data-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }
        .data-group { display: flex; flex-direction: column; gap: 6px; }
        .data-label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; }
        .data-value { font-size: 1rem; font-weight: 700; color: var(--text-main); background: #FAFAF9; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border); word-break: break-word; }
        .data-value.price { color: var(--maroon); font-weight: 900; background: var(--maroon-light); border-color: rgba(139, 21, 56, 0.2); }
        .media-link { display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 800; color: var(--maroon); text-decoration: none; padding: 8px 14px; border-radius: 50px; background: var(--maroon-light); border: 1px solid rgba(139, 21, 56, 0.2); text-transform: uppercase; }
        .card-footer { padding: 16px 24px; border-top: 1px dashed var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #FAFAF9; flex-wrap: wrap; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); font-weight: 600; border: 2px dashed var(--border); border-radius: 16px; background: #FAFAF9; font-size: 1rem; }

        @media (max-width: 768px) {
            .data-grid { grid-template-columns: 1fr; }
            .span-2, .span-4 { grid-column: 1 / -1; }
        }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1>Admin <span class="accent">Command Center</span></h1>
            <div class="header-controls">
                <a href="../index.php" class="btn-nav">Employee Portal</a>
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
            <button class="tab-btn active" onclick="openTab('tab-sales', this)">
                Sales Quotes <?php if($sales_count > 0) echo "<span class='badge-count'>$sales_count</span>"; ?>
            </button>
            <button class="tab-btn" onclick="openTab('tab-project', this)">
                Project Quotes <?php if($project_count > 0) echo "<span class='badge-count'>$project_count</span>"; ?>
            </button>
            <button class="tab-btn" onclick="openTab('tab-inventory', this)">
                Inventory Approvals <?php if($inv_count > 0) echo "<span class='badge-count'>$inv_count</span>"; ?>
            </button>
            <button class="tab-btn" onclick="openTab('tab-history', this)">
                Quotation History
            </button>
        </div>

        <div id="tab-sales" class="tab-content active">
            <div class="card">
                <h2>Sales Quotations (Pending Review)</h2>
                <?php if (empty($sales_pending)): ?>
                    <div class="empty-state">No pending sales quotations require approval right now.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th>Quote No</th>
                                <th>Client / Submitter</th>
                                <th>Date Submitted</th>
                                <th>Controls</th>
                            </tr>
                            <?php foreach($sales_pending as $q): ?>
                            <tr>
                                <td><strong style="font-family: 'Outfit', sans-serif; font-size: 1.1rem; color: var(--maroon);"><?php echo htmlspecialchars($q['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <div style="font-weight: 800; color: var(--text-main); font-size: 1.05rem;"><?php echo htmlspecialchars($q['client_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-top: 4px;">
                                        Prepared By: <?php echo htmlspecialchars(ucfirst($q['username'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?> (Sales Team)
                                    </div>
                                </td>
                                <td><div style="font-weight: 700; color: var(--text-main);"><?php echo date('M d, Y', strtotime($q['quote_date'])); ?></div></td>
                                <td>
                                    <form method="POST" class="quote-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="quote_id" value="<?php echo $q['id']; ?>">
                                        <input type="hidden" name="type" value="sales">
                                        
                                        <a href="../generate_pdf.php?id=<?php echo $q['id']; ?>&type=sales" target="_blank" class="btn btn-pdf">View PDF</a>
                                        <button type="submit" name="action" value="approve_quote" class="btn btn-approve" onclick="return confirm('Approve quote? Note: This will sync the custom prices to the master inventory database.');">Approve</button>
                                        <button type="submit" name="action" value="reject_quote" class="btn btn-reject" onclick="return confirm('Reject and request revision?');">Decline</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-project" class="tab-content">
            <div class="card">
                <h2>Project Quotations (Pending Review)</h2>
                <?php if (empty($project_pending)): ?>
                    <div class="empty-state">No pending project quotations require approval right now.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th>Quote No</th>
                                <th>Project / Submitter</th>
                                <th>Date Submitted</th>
                                <th>Controls</th>
                            </tr>
                            <?php foreach($project_pending as $p): ?>
                            <tr>
                                <td><strong style="font-family: 'Outfit', sans-serif; font-size: 1.1rem; color: var(--maroon);"><?php echo htmlspecialchars($p['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>
                                    <div style="font-weight: 800; color: var(--text-main); font-size: 1.05rem;"><?php echo htmlspecialchars($p['project_name'] ?? 'Project', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; margin-top: 4px;">
                                        Prepared By: <?php echo htmlspecialchars(ucfirst($p['prepared_by'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?> (Project Team)
                                    </div>
                                </td>
                                <td><div style="font-weight: 700; color: var(--text-main);"><?php echo date('M d, Y', strtotime($p['quote_date'])); ?></div></td>
                                <td>
                                    <form method="POST" class="quote-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="quote_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="type" value="project">
                                        
                                        <a href="../generate_pdf.php?id=<?php echo $p['id']; ?>&type=project" target="_blank" class="btn btn-pdf">View PDF</a>
                                        <button type="submit" name="action" value="approve_quote" class="btn btn-approve" onclick="return confirm('Send to Super Admin for final check?');">Approve</button>
                                        <button type="submit" name="action" value="reject_quote" class="btn btn-reject" onclick="return confirm('Reject and request revision?');">Decline</button>
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
                    You are all caught up!<br>
                    <span style="font-size: 0.9rem; font-weight: 500;">There are currently no new machines or price updates waiting for approval.</span>
                </div>
            <?php else: ?>
                <?php foreach ($pending_inventory as $req): ?>
                    <div class="request-card">
                        <div class="card-header">
                            <div>
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
                                    <div class="data-value" style="font-family: 'Outfit', sans-serif; font-size: 1.2rem;"><?php echo htmlspecialchars($req['model_no'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="data-group span-4">
                                    <span class="data-label">Description</span>
                                    <div class="data-value" style="white-space: pre-line; line-height: 1.5; font-size: 0.95rem;"><?php echo htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8'); ?></div>
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
                                    <div class="data-group span-4" style="flex-direction: row; gap: 16px; margin-top: 16px; padding-top: 16px; border-top: 1px dashed var(--border);">
                                        <?php if (!empty($req['picture'])): ?>
                                            <a href="../../images/machine_images/<?php echo htmlspecialchars($req['picture'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="media-link">🖼️ View Image</a>
                                        <?php endif; ?>
                                        <?php if (!empty($req['pdf_path'])): ?>
                                            <a href="../../pdfs/machine_pdfs/<?php echo htmlspecialchars($req['pdf_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="media-link">📄 View PDF</a>
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
                <h2>Quotation History Log</h2>
                <?php if (empty($quote_history)): ?>
                    <div class="empty-state">No processed quotations found in history.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th>Department</th>
                                <th>Quote No.</th>
                                <th>Client / Project</th>
                                <th>Last Updated</th>
                                <th>Status</th>
                            </tr>
                            <?php foreach($quote_history as $log): ?>
                            <tr>
                                <td><span class="badge" style="background:#F4F4F5; color:var(--text-main); border:1px solid var(--border);"><?php echo htmlspecialchars($log['quote_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><strong style="font-family: 'Outfit', sans-serif; font-size: 1.05rem;"><?php echo htmlspecialchars($log['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($log['reference_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">
                                    <?php echo date('M d, Y - h:i A', strtotime($log['created_at'])); ?>
                                </td>
                                <td>
                                    <?php 
                                        if ($log['status'] === 'pending_super') echo '<span class="badge b-super">Sent to Super Admin</span>';
                                        elseif ($log['status'] === 'revision') echo '<span class="badge b-rev">Declined / Revision</span>';
                                        elseif ($log['status'] === 'approved') echo '<span class="badge b-app">Approved (Final)</span>';
                                        elseif ($log['status'] === 'rejected') echo '<span class="badge b-rev">Rejected (Final)</span>';
                                        else echo '<span class="badge" style="background:#E4E4E7; color:#3F3F46;">' . htmlspecialchars($log['status']) . '</span>';
                                    ?>
                                </td>
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
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            document.getElementById(tabId).classList.add('active');
            btnElement.classList.add('active');
        }
    </script>
</body>
</html>