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
    <title>Quotation History - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Modernized Premium Palette */
            --bg: #F4F7F9; 
            --surface: #FFFFFF;
            --text-main: #0F172A; 
            --text-muted: #64748B; 
            --text-light: #94A3B8;
            --border: #E2E8F0;
            --maroon: #8B1538;
            --maroon-hover: #700E2B;
            --maroon-light: #FFF1F5;
            --danger: #EF4444;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 50px 30px; margin: 0; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* HEADER */
        .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 40px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: clamp(2rem, 4vw, 2.8rem); font-weight: 900; letter-spacing: -0.04em; text-transform: uppercase; line-height: 1; margin:0; color: var(--text-main);}
        .page-title .accent { color: var(--maroon); }
        
        /* PREMIUM BACK BUTTON */
        .btn-back { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            padding: 12px 24px; 
            background: var(--maroon-light); 
            border: 1px solid rgba(139, 21, 56, 0.15); 
            border-radius: 50px; 
            color: var(--maroon); 
            text-decoration: none; 
            font-family: 'Outfit', sans-serif; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            font-size: 0.85rem; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: var(--shadow-sm); 
        }
        .btn-back svg { transition: transform 0.3s ease; }
        .btn-back:hover { 
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); 
            color: #FFFFFF; 
            transform: translateX(-4px); 
            box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); 
            border-color: transparent;
        }
        .btn-back:hover svg { transform: translateX(-3px); }

        /* BENTO CARD */
        .card { 
            background: var(--surface); 
            border-radius: 24px; 
            padding: 40px; 
            box-shadow: var(--shadow-md); 
            border: 1px solid rgba(226, 232, 240, 0.8); 
            overflow: hidden; 
        }
        
        /* SPACIOUS TABLE */
        .table-responsive { overflow-x: auto; margin: 0 -10px; padding: 0 10px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
        th, td { padding: 20px 16px; border-bottom: 1px dashed var(--border); vertical-align: middle; }
        
        th { font-size: 0.7rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; background: transparent; padding-bottom: 24px; }
        td { font-size: 0.95rem; font-weight: 600; transition: background 0.2s ease; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #F8FAFC; }

        .text-center { text-align: center; }
        .text-left { text-align: left; }

        /* MODERN PILL BADGES */
        .badge { padding: 8px 16px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .badge-pending { background: #FFFBEB; color: #D97706; border: 1px solid #FEF3C7; }
        .badge-revision { background: #FEF2F2; color: #DC2626; border: 1px solid #FEE2E2; }
        .badge-approved { background: #ECFDF5; color: #059669; border: 1px solid #D1FAE5; }
        .badge-super { background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE; }

        .admin-note { font-size: 0.8rem; color: #DC2626; font-weight: 600; margin-top: 10px; background: #FEF2F2; padding: 10px 14px; border-radius: 12px; border-left: 3px solid #EF4444; }
        
        .alert { padding: 16px 24px; border-radius: 16px; font-size: 0.95rem; font-weight: 700; margin-bottom: 30px; background: #ECFDF5; color: #059669; border: 1px solid #D1FAE5; box-shadow: var(--shadow-sm); }

        /* ACTION BUTTON */
        .btn-view { padding: 10px 20px; background: var(--maroon-light); border: 1px solid rgba(139, 21, 56, 0.15); color: var(--maroon); font-family: 'Outfit', sans-serif; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; border-radius: 50px; text-decoration: none; transition: all 0.3s ease; display: inline-block; white-space: nowrap; box-shadow: var(--shadow-sm); }
        .btn-view:hover { background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; transform: translateY(-2px); box-shadow: 0 8px 16px rgba(139, 21, 56, 0.2); border-color: transparent; }

        /* MOBILE RESPONSIVENESS */
        @media (max-width: 600px) {
            body { padding: 20px 16px; }
            .top-bar { flex-direction: column; align-items: stretch; gap: 20px; margin-bottom: 30px; }
            .page-title { font-size: 2.4rem; text-align: center; }
            .btn-back { width: 100%; justify-content: center; height: 50px; font-size: 0.9rem; }
            .card { padding: 20px; border-radius: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-bar">
            <h1 class="page-title">Quotation <span class="accent">History</span></h1>
            <a href="index.php" class="btn-back">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Dashboard
            </a>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert">
                <?= htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>

        <div class="card">
            <?php if (empty($quotes)): ?>
                <div style="text-align: center; padding: 80px 20px; color: var(--text-muted); font-weight: 600; font-size: 1.1rem; border: 2px dashed var(--border); border-radius: 16px; background: #F8FAFC;">
                    You have not submitted any quotations yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <tr>
                            <th class="text-center">Quote No</th>
                            <th class="text-left">Client</th>
                            <th class="text-center">Date Submitted</th>
                            <th class="text-center">Current Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                        <?php foreach($quotes as $q): ?>
                        <tr>
                            <td class="text-center"><strong style="font-family: 'Outfit', sans-serif; font-size: 1.1rem; color: var(--maroon);"><?= htmlspecialchars($q['quotation_no']) ?></strong></td>
                            <td class="text-left" style="font-weight: 800; color: var(--text-main); font-size: 1.05rem;"><?= htmlspecialchars($q['client_name']) ?></td>
                            <td class="text-center" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;"><?= date('M d, Y – h:i A', strtotime($q['created_at'])) ?></td>
                            <td class="text-center">
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
                            <td class="text-center">
                                <a href="generate_pdf.php?id=<?= $q['id'] ?>&type=sales" target="_blank" class="btn-view">View PDF</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>