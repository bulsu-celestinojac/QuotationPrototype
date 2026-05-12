<?php
// admin/approvals.php
session_start();

// 1. STRICT SECURITY: Use your native auth system
require_once '../auth.php';
require_role(['admin', 'super_admin']); // Only Admins access this layer
require '../db.php';                    // Corrected path (up one level)
require_once '../functions.php';        // Added to support log_activity()

// Generate CSRF Token for security actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success_msg = '';
$error_msg = '';

// =========================================================================
// 2. PROCESS APPROVE OR REJECT ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_msg = "Security validation failed. Please try again.";
    } else {
        $approval_id = $_POST['approval_id'] ?? null;
        $action = $_POST['action'] ?? ''; // 'approve' or 'reject'

        if ($approval_id && $action) {
            // Fetch the pending request data
            $stmt = $pdo->prepare("SELECT * FROM pending_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch();

            if ($pending) {
                if ($action === 'approve') {
                    try {
                        $pdo->beginTransaction();

                        if ($pending['action_type'] === 'add') {
                            // PUSH TO LIVE: Add New Machine
                            $insertStmt = $pdo->prepare("INSERT INTO items (brand, model_no, description, picture, buying_currency, buying_cost, factor, selling_price, pdf_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $insertStmt->execute([
                                $pending['brand'], $pending['model_no'], $pending['description'], 
                                $pending['picture'], $pending['buying_currency'], $pending['buying_cost'], 
                                $pending['factor'], $pending['selling_price'], $pending['pdf_path']
                            ]);
                            
                            // Log the activity
                            log_activity($pdo, 'ADMIN_REVIEW', "Admin approved NEW machine request: {$pending['brand']} {$pending['model_no']}");
                            $success_msg = "New machine added to live inventory.";
                        } 
                        elseif ($pending['action_type'] === 'edit') {
                            // PUSH TO LIVE: Update Existing Machine
                            $updateStmt = $pdo->prepare("UPDATE items SET brand = ?, model_no = ?, description = ?, buying_currency = ?, buying_cost = ?, factor = ?, selling_price = ?, picture = ?, pdf_path = ? WHERE id = ?");
                            $updateStmt->execute([
                                $pending['brand'], $pending['model_no'], $pending['description'], 
                                $pending['buying_currency'], $pending['buying_cost'], $pending['factor'], 
                                $pending['selling_price'], $pending['picture'], $pending['pdf_path'], 
                                $pending['item_id']
                            ]);
                            
                            // Log the activity
                            log_activity($pdo, 'ADMIN_REVIEW', "Admin approved UPDATE request for Machine ID #{$pending['item_id']}");
                            $success_msg = "Machine #{$pending['item_id']} updated in live inventory.";
                        }

                        // Mark as Approved
                        $statusStmt = $pdo->prepare("UPDATE pending_approvals SET status = 'approved' WHERE id = ?");
                        $statusStmt->execute([$approval_id]);
                        
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error_msg = "Database error during approval process.";
                    }
                } 
                elseif ($action === 'reject') {
                    // Mark as Rejected
                    $rejectStmt = $pdo->prepare("UPDATE pending_approvals SET status = 'rejected' WHERE id = ?");
                    if ($rejectStmt->execute([$approval_id])) {
                        // Log the activity
                        log_activity($pdo, 'ADMIN_REVIEW', "Admin REJECTED database request for: {$pending['brand']} {$pending['model_no']}");
                        $success_msg = "Request has been rejected and discarded.";
                    }
                }
            } else {
                $error_msg = "This request no longer exists or was already processed.";
            }
        }
    }
}

// =========================================================================
// 3. FETCH ALL PENDING REQUESTS
// =========================================================================
$query = "
    SELECT p.*, u.username as requested_by_name 
    FROM pending_approvals p 
    JOIN users u ON p.requested_by = u.id 
    WHERE p.status = 'pending' 
    ORDER BY p.created_at ASC
";
$pending_requests = $pdo->query($query)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Approvals Dashboard - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F8F6F5;
            --surface: #FFFFFF;
            --text-main: #2A0808;
            --text-muted: #8C7373;
            --border: #E8D8D7;
            --maroon: #8B1538; 
            --maroon-hover: #5A0000;
            --maroon-light: #FFF5F7;
            --success: #10B981;
            --danger: #EF4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            padding: 40px 30px;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* HEADER (Matched to your users.php styling) */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 40px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
        }
        .header-left h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 3rem;
            font-weight: 900;
            color: var(--text-main);
            text-transform: uppercase;
            line-height: 1;
            margin-bottom: 6px;
        }
        .header-left h1 span.accent { color: var(--maroon); }
        .header-left p { color: var(--text-muted); font-size: 0.95rem; font-weight: 500; }
        
        .btn-nav { 
            color: var(--text-muted); 
            text-decoration: none; 
            font-weight: 700; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            transition: 0.2s; 
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-nav:hover { color: var(--maroon); }

        /* ALERTS */
        .alert { padding: 16px; border-radius: 12px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from{ opacity:0; transform: translateY(-10px); } to{ opacity:1; transform: translateY(0); } }
        .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #34D399; }
        .alert-error { background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); }

        /* EMPTY STATE */
        .empty-state {
            background: var(--surface);
            border: 2px dashed var(--border);
            border-radius: 20px;
            padding: 60px 20px;
            text-align: center;
        }
        .empty-icon { color: var(--border); margin-bottom: 16px; }
        .empty-title { font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 800; color: var(--maroon); margin-bottom: 8px; text-transform: uppercase; }
        .empty-subtitle { color: var(--text-muted); font-size: 0.95rem; }

        /* REQUEST CARD */
        .request-card {
            background: var(--surface);
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 30px rgba(139, 21, 56, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .request-card:hover { transform: translateY(-2px); box-shadow: 0 15px 35px rgba(139, 21, 56, 0.08); }

        /* CARD HEADER */
        .card-header {
            background: #FAFAFA;
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-group { display: flex; align-items: center; gap: 12px; }
        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge-add { background: #EEF2FF; color: #1D4ED8; border: 1px solid #C7D2FE; }
        .badge-edit { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        
        .request-meta { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }
        .request-meta strong { color: var(--maroon); font-weight: 800; text-transform: uppercase; }

        /* CARD BODY (DATA GRID) */
        .card-body { padding: 24px; }
        .data-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px 16px; }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }

        .data-group { display: flex; flex-direction: column; gap: 4px; }
        .data-label { font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .data-value { font-size: 1rem; font-weight: 600; color: var(--text-main); background: #F8F6F6; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); }
        .data-value.price { color: var(--maroon); font-weight: 800; background: var(--maroon-light); border-color: rgba(139, 21, 56, 0.2); }
        .data-value.desc { font-size: 0.9rem; white-space: pre-wrap; font-family: inherit; }

        /* CORRECTED MEDIA PATHS FOR ADMIN FOLDER */
        .media-link { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 700; color: var(--maroon); text-decoration: none; padding: 8px 12px; border-radius: 6px; background: var(--maroon-light); border: 1px solid rgba(139, 21, 56, 0.2); transition: all 0.2s; }
        .media-link:hover { background: var(--maroon); color: white; }

        /* CARD FOOTER (ACTIONS) */
        .card-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #FFFFFF;
        }

        .action-form { display: inline; margin: 0; }
        .btn {
            height: 42px;
            padding: 0 24px;
            border: none;
            border-radius: 50px;
            font-size: 0.85rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-reject { background: #FEF2F2; color: var(--danger); border: 1px solid #FECACA; }
        .btn-reject:hover { background: var(--danger); color: white; border-color: var(--danger); }
        
        .btn-approve { background: var(--success); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); }
        .btn-approve:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3); }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: flex-start; gap: 16px; }
            .data-grid { grid-template-columns: 1fr; gap: 16px; }
            .span-2, .span-4 { grid-column: 1 / -1; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .card-footer { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

    <div class="dashboard-container">
        
        <div class="page-header">
            <div class="header-left">
                <h1 class="page-title">Pending <span class="accent">Approvals</span></h1>
                <p>Review new inventory additions and price updates from the Sales & Project teams.</p>
            </div>
            <a href="index.php" class="btn-nav">← Back to Dashboard</a>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($pending_requests)): ?>
            <div class="empty-state">
                <svg class="empty-icon" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <h2 class="empty-title">You're all caught up!</h2>
                <p class="empty-subtitle">There are currently no additions or updates waiting for your approval.</p>
            </div>
        <?php else: ?>
            
            <?php foreach ($pending_requests as $req): ?>
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
                                        <a href="../../images/machine_images/<?php echo htmlspecialchars($req['picture'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="media-link">
                                            🖼️ View Uploaded Image
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($req['pdf_path'])): ?>
                                        <a href="../../pdfs/machine_pdfs/<?php echo htmlspecialchars($req['pdf_path'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="media-link">
                                            📄 View Attached PDF
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="card-footer">
                        <form method="POST" class="action-form" onsubmit="return confirm('Are you sure you want to REJECT and delete this request?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="approval_id" value="<?php echo $req['id']; ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-reject">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                Reject Request
                            </button>
                        </form>

                        <form method="POST" class="action-form" onsubmit="return confirm('Approve this request? This will instantly update the live inventory.');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="approval_id" value="<?php echo $req['id']; ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-approve">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                Approve & Publish
                            </button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</body>
</html>