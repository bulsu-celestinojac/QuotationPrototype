<?php
session_start();
require_once 'auth.php';
require_login();
require 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Clear the unread notifications for this user upon visiting
$pdo->prepare("UPDATE sales_quotations SET is_notified = 0 WHERE user_id = ?")->execute([$user_id]);

// Fetch user's quotes
$stmt = $pdo->prepare("SELECT * FROM sales_quotations WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My Submissions - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: #FFFFFF; --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 40px 30px; margin: 0; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        .top-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 900; letter-spacing: -0.04em; text-transform: uppercase; line-height: 1; margin:0;}
        .page-title .accent { color: var(--maroon); }
        
        .btn-back { font-family: 'Outfit', sans-serif; height: 46px; padding: 0 24px; font-weight: 800; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid var(--border); border-radius: 50px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; background: var(--surface); color: var(--text-main); transition: all 0.2s ease; }
        .btn-back:hover { border-color: var(--maroon); color: var(--maroon); box-shadow: 0 8px 16px rgba(139, 21, 56, 0.1); }

        .card { background: var(--surface); border-radius: 16px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border); }
        th { font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 800; letter-spacing: 0.05em; }
        td { font-size: 0.95rem; vertical-align: middle; }
        tr:hover td { background: #FAFAFA; }

        .badge { padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; }
        .badge-pending { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .badge-revision { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
        .badge-approved { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .badge-super { background: #EEF2FF; color: #1D4ED8; border: 1px solid #C7D2FE; }

        .admin-note { font-size: 0.85rem; color: #B91C1C; font-weight: 600; margin-top: 6px; background: #FEF2F2; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #EF4444; }
        .alert { padding: 14px 20px; border-radius: 10px; font-size: 0.9rem; font-weight: 700; margin-bottom: 24px; background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; border-left: 4px solid #10B981;}
    </style>
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <h1 class="page-title">Submission <span class="accent">History</span></h1>
            <a href="index.php" class="btn-back">← Back to Dashboard</a>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert">
                <?= htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <div class="card" style="overflow-x: auto;">
            <?php if (empty($quotes)): ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-muted); font-weight: 500;">
                    You have not submitted any quotations yet.
                </div>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Quote No</th>
                        <th>Client</th>
                        <th>Date Submitted</th>
                        <th>Current Status</th>
                        <th>Action</th>
                    </tr>
                    <?php foreach($quotes as $q): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($q['quotation_no']) ?></strong></td>
                        <td><?= htmlspecialchars($q['client_name']) ?></td>
                        <td style="color: var(--text-muted); font-size: 0.85rem;"><?= date('M d, Y - h:i A', strtotime($q['created_at'])) ?></td>
                        <td>
                            <?php 
                                if ($q['status'] === 'pending_admin') echo '<span class="badge badge-pending">⏳ Pending Admin</span>';
                                elseif ($q['status'] === 'revision') echo '<span class="badge badge-revision">❌ Revision Needed</span>';
                                elseif ($q['status'] === 'pending_super') echo '<span class="badge badge-super">🛡️ Pending Super Admin</span>';
                                elseif ($q['status'] === 'approved') echo '<span class="badge badge-approved">✅ Approved</span>';
                            ?>
                            <?php if (!empty($q['admin_notes'])): ?>
                                <div class="admin-note">Note: <?= htmlspecialchars($q['admin_notes']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="generate_pdf.php?id=<?= $q['id'] ?>&type=sales" target="_blank" class="btn-back" style="height: 36px; font-size: 0.7rem;">View PDF</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>