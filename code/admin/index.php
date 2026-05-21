<?php
// admin/index.php
// Removed session_start() because auth.php safely handles it (Fixes the warning!)
require_once '../auth.php';
require_role(['admin', 'super_admin']); // Only admins & super admins access this layer
require '../db.php';
require_once '../functions.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Retrieve Flash Messages
$success = $_SESSION['flash_success'] ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ==========================================
// FETCH DATASETS & COUNTS
// ==========================================
$sales_pending = $pdo->query("SELECT sq.*, u.username, u.full_name FROM sales_quotations sq LEFT JOIN users u ON sq.user_id = u.id WHERE sq.status = 'pending_admin' ORDER BY sq.created_at DESC")->fetchAll();
$sales_count = count($sales_pending);

$project_pending = $pdo->query("SELECT * FROM project_quotations WHERE status = 'pending_admin' ORDER BY created_at DESC")->fetchAll();
$project_count = count($project_pending);

$pending_inventory = $pdo->query("SELECT p.*, u.username as requested_by_name, u.full_name as requested_by_fullname FROM pending_approvals p JOIN users u ON p.requested_by = u.id WHERE p.status = 'pending' ORDER BY p.created_at ASC")->fetchAll();
$inv_count = count($pending_inventory);

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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
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
            --success: #059669;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            --shadow-lg: 0 24px 60px -10px rgba(0, 0, 0, 0.1);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); min-height: 100vh; overflow-x: hidden; }
        .container { max-width: 1300px; margin: 0 auto; padding: 0 30px; padding-bottom: 60px; }
        
        .top-bar-wrapper { position: sticky; top: 0; z-index: 900; background: rgba(244, 247, 249, 0.85); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 24px 0; margin-bottom: 30px; border-bottom: 1px solid rgba(226, 232, 240, 0.6); }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        .header h1 { font-family: 'Outfit', sans-serif; font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 900; text-transform: uppercase; letter-spacing: -0.02em; line-height: 1; margin: 0; }
        .header h1 span { color: var(--maroon); }
        
        .header-controls { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-nav { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: var(--surface); border: 1px solid var(--border); border-radius: 9999px; color: var(--text-main); text-decoration: none; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.8rem; transition: all 0.3s ease; box-shadow: var(--shadow-sm); }
        .btn-nav:hover { border-color: var(--maroon); color: var(--maroon); transform: translateY(-2px); box-shadow: 0 8px 16px rgba(139, 21, 56, 0.1); }
        .btn-logout { background: #FFF5F5 !important; color: var(--danger) !important; border-color: #FECACA !important; }
        .btn-logout:hover { background: var(--danger) !important; color: white !important; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.2) !important; }

        .alert { padding: 16px 24px; border-radius: 16px; font-size: 0.95rem; font-weight: 700; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; animation: slideDown 0.3s ease; box-shadow: var(--shadow-sm); }
        @keyframes slideDown { from{ opacity:0; transform: translateY(-10px); } to{ opacity:1; transform: translateY(0); } }
        .alert-success { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .alert-error { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

        /* PREMIUM TABS */
        .tabs-container { margin-bottom: 30px; display: flex; overflow-x: auto; scrollbar-width: none; padding-bottom: 10px; }
        .tabs-container::-webkit-scrollbar { display: none; }
        .tabs-nav { display: inline-flex; gap: 8px; background: var(--surface); padding: 8px; border-radius: 9999px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); }
        .tab-btn { background: transparent; border: none; color: var(--text-muted); padding: 12px 24px; font-family: 'Outfit', sans-serif; font-size: 0.9rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; white-space: nowrap; border-radius: 9999px; display: flex; align-items: center; gap: 8px; }
        .tab-btn svg { width: 18px; height: 18px; opacity: 0.7; transition: opacity 0.3s ease; }
        .tab-btn:hover { color: var(--text-main); }
        .tab-btn:hover svg { opacity: 1; }
        .tab-btn.active { background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); }
        .tab-btn.active svg { opacity: 1; }
        
        @keyframes pulse-red { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        .badge-count { background: var(--danger); color: white; padding: 4px 8px; border-radius: 50px; font-size: 0.75rem; font-weight: 900; line-height: 1; animation: pulse-red 2s infinite; box-shadow: 0 2px 4px rgba(0,0,0,0.2); border: 2px solid white; margin-left: 4px;}

        .tab-content { display: none; animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .section-title { font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 900; margin-bottom: 24px; color: var(--text-main); letter-spacing: -0.02em; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); font-weight: 600; border: 2px dashed var(--border); border-radius: 24px; background: var(--surface); font-size: 1rem; }

        /* ==========================================
           PREMIUM INTERACTIVE DATA ROWS
           ========================================== */
        .quote-list { display: flex; flex-direction: column; gap: 14px; }
        
        .quote-row { 
            display: flex; align-items: center; justify-content: space-between; 
            padding: 24px; background: var(--surface); border: 1px solid var(--border); 
            border-radius: 20px; cursor: pointer; box-shadow: var(--shadow-sm); 
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); 
        }
        .quote-row:hover { 
            border-color: rgba(139, 21, 56, 0.3); box-shadow: 0 12px 24px rgba(139, 21, 56, 0.08); 
            transform: translateY(-3px); 
        }

        .q-meta { display: flex; flex-direction: column; gap: 6px; width: 200px; flex-shrink: 0; }
        .q-num { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 900; color: var(--maroon); letter-spacing: 0.02em;}
        .q-date { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); display: flex; align-items: center; gap: 4px;}
        .q-date svg { width: 14px; height: 14px; opacity: 0.7;}

        .q-details { display: flex; flex-direction: column; gap: 6px; flex: 1; padding: 0 20px; border-left: 1px solid var(--border); margin-left: 10px; padding-left: 30px;}
        .q-client { font-size: 1.1rem; font-weight: 800; color: var(--text-main); }
        .q-prep { font-size: 0.85rem; font-weight: 600; color: var(--text-light); }

        .q-action { display: flex; align-items: center; justify-content: flex-end; width: 160px; flex-shrink: 0; }
        .q-btn { 
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; 
            color: var(--maroon); background: var(--maroon-light); border: 1px solid rgba(139, 21, 56, 0.1);
            padding: 10px 20px; border-radius: 50px; transition: all 0.3s ease; 
            display: inline-flex; align-items: center; gap: 6px;
        }
        .quote-row:hover .q-btn { background: var(--maroon); color: white; border-color: var(--maroon); box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2); }
        .q-btn svg { transition: transform 0.3s ease; }
        .quote-row:hover .q-btn svg { transform: translateX(4px); }

        /* History Status Columns */
        .q-type { width: 120px; display: flex; align-items: center; }
        .type-badge { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; background: var(--bg); color: var(--text-muted); padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border); }
        .q-status { width: 180px; display: flex; align-items: center; justify-content: flex-end; }
        
        .badge { padding: 8px 16px; border-radius: 50px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; display: inline-flex; align-items: center; gap: 6px; text-align: center; justify-content: center;}
        .b-super { background: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE; }
        .b-rev { background: #FEF2F2; color: #DC2626; border: 1px solid #FEE2E2; }
        .b-app { background: #ECFDF5; color: #059669; border: 1px solid #D1FAE5; }

        /* Inventory Cards (Retained and Polished) */
        .request-card { background: var(--surface); border-radius: 24px; border: 1px solid rgba(226, 232, 240, 0.8); box-shadow: var(--shadow-sm); margin-bottom: 24px; overflow: hidden; transition: all 0.4s ease; }
        .request-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: rgba(139, 21, 56, 0.15); }
        .card-header { background: #F8FAFC; padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .request-meta { font-size: 0.85rem; color: var(--text-muted); font-weight: 600; }
        .request-meta strong { color: var(--text-main); font-weight: 800; }
        .badge-add { background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); }
        .badge-edit { background: #FFFBEB; color: #D97706; border: 1px solid #FEF3C7; }
        .card-body { padding: 24px; }
        .data-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }
        .data-group { display: flex; flex-direction: column; gap: 6px; }
        .data-label { font-size: 0.65rem; font-weight: 800; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; }
        .data-value { font-size: 1rem; font-weight: 600; color: var(--text-main); background: var(--bg); padding: 12px 16px; border-radius: 12px; border: 1px solid var(--border); word-break: break-word; }
        .data-value.price { color: var(--maroon); font-weight: 900; background: var(--maroon-light); border-color: rgba(139, 21, 56, 0.2); }
        .media-link { display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 800; color: var(--maroon); text-decoration: none; padding: 10px 20px; border-radius: 9999px; background: var(--maroon-light); border: 1px solid rgba(139, 21, 56, 0.2); text-transform: uppercase; transition: all 0.3s ease;}
        .media-link:hover { background: var(--maroon); color: white; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2); }
        
        .card-footer { padding: 20px 24px; border-top: 1px dashed var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #F8FAFC; flex-wrap: wrap; }
        .btn { padding: 12px 20px; font-weight: 800; border: none; border-radius: 12px; cursor: pointer; font-size: 0.75rem; transition: all 0.3s ease; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-family: 'Outfit', sans-serif; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: var(--shadow-sm); }
        .btn-pdf { background: var(--surface); color: var(--text-main); border: 1px solid var(--border); }
        .btn-pdf:hover { background: #E2E8F0; transform: translateY(-2px); }
        .btn-approve { background: linear-gradient(135deg, #10B981 0%, #047857 100%); color: white; }
        .btn-approve:hover { filter: brightness(1.1); box-shadow: 0 8px 16px rgba(4, 120, 87, 0.25); transform: translateY(-2px); }
        .btn-reject { background: linear-gradient(135deg, #EF4444 0%, #B91C1C 100%); color: white; }
        .btn-reject:hover { filter: brightness(1.1); box-shadow: 0 8px 16px rgba(185, 28, 28, 0.25); transform: translateY(-2px); }

        /* ==========================================
           PREMIUM AJAX EDITOR MODAL
           ========================================== */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 9998; display: none; opacity: 0; transition: opacity 0.3s ease; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.active { display: flex; opacity: 1; }
        
        .modal-card { background: var(--surface); box-shadow: var(--shadow-lg); border-radius: 32px; max-width: 1100px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; transform: translateY(20px); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); border: 1px solid rgba(226, 232, 240, 0.8); }
        .modal-overlay.active .modal-card { transform: translateY(0); }
        
        .modal-header { padding: 30px 40px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #F8FAFC; position: relative; flex-shrink: 0; }
        .modal-header h2 { font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 900; color: var(--text-main); margin: 0; display: flex; align-items: center; gap: 12px;}
        .modal-type-badge { font-size: 0.75rem; padding: 6px 12px; border-radius: 50px; background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); letter-spacing: 0.1em; text-transform: uppercase;}
        
        .btn-close-modal { background: white; border: 1px solid var(--border); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; cursor: pointer; color: var(--text-muted); transition: all 0.3s ease; box-shadow: var(--shadow-sm); }
        .btn-close-modal:hover { color: var(--danger); border-color: var(--danger); transform: rotate(90deg); }
        
        .modal-body { padding: 40px; overflow-y: auto; flex: 1; background: var(--surface); }
        
        .m-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 30px; margin-bottom: 40px; }
        .m-box { background: #F8FAFC; padding: 24px; border-radius: 20px; border: 1px dashed var(--border); }
        .m-box-title { font-family: 'Outfit', sans-serif; font-size: 0.85rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 16px; border-bottom: 2px solid var(--border); padding-bottom: 8px; }
        .m-row { display: flex; flex-direction: column; margin-bottom: 12px; }
        .m-label { font-size: 0.65rem; text-transform: uppercase; font-weight: 800; color: var(--text-light); }
        
        .m-input { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: #FFFFFF; font-size: 0.95rem; font-family: 'DM Sans', sans-serif; color: var(--text-main); font-weight: 500; transition: all 0.3s ease; outline: none; margin-top: 4px; }
        .m-input:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px var(--maroon-light); }
        textarea.m-input { min-height: 60px; resize: vertical; }

        .m-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .m-table th { background: #F8FAFC; padding: 12px 16px; font-size: 0.7rem; color: var(--text-muted); border-radius: 8px; border: none; text-align: left; }
        .m-table td { padding: 12px 16px; border-bottom: 1px dashed var(--border); font-size: 0.95rem; font-weight: 600; vertical-align: middle;}
        .m-table tr:last-child td { border-bottom: none; }
        .item-img { width: 48px; height: 48px; border-radius: 8px; object-fit: contain; background: #F8FAFC; padding: 4px; border: 1px solid var(--border); }
        
        .m-totals { display: flex; flex-direction: column; gap: 8px; align-items: flex-end; margin-top: 30px; padding-top: 20px; border-top: 2px dashed var(--border); }
        .m-tot-row { display: flex; justify-content: space-between; align-items: center; width: 320px; font-size: 1rem; font-weight: 700; color: var(--text-muted); }
        .m-tot-net { display: flex; justify-content: space-between; align-items: center; width: 320px; font-size: 1.6rem; font-family: 'Outfit', sans-serif; font-weight: 900; color: var(--maroon); margin-top: 8px; border-top: 1px solid var(--border); padding-top: 8px;}

        .modal-footer { padding: 20px 40px; background: #F8FAFC; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; flex-shrink: 0; flex-wrap: wrap;}

        /* ==========================================
           CUSTOM PREMIUM CONFIRMATION MODAL
           ========================================== */
        .confirm-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 10000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; padding: 20px; }
        .confirm-overlay.active { display: flex; opacity: 1; }
        .confirm-card { background: var(--surface); border-radius: 24px; padding: 32px; max-width: 420px; width: 100%; box-shadow: 0 24px 60px rgba(0,0,0,0.2); transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); text-align: center; }
        .confirm-overlay.active .confirm-card { transform: scale(1); }
        
        .confirm-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 2rem; transition: all 0.3s ease; }
        .confirm-title { font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 900; color: var(--text-main); margin-bottom: 12px; }
        .confirm-msg { font-size: 1rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 30px; font-weight: 500; }
        .confirm-actions { display: flex; gap: 12px; justify-content: center; }
        
        .btn-confirm-cancel { flex: 1; background: #F8FAFC; color: var(--text-main); border: 1px solid var(--border); padding: 12px 20px; border-radius: 12px; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.2s ease; }
        .btn-confirm-cancel:hover { background: #E2E8F0; }
        
        .btn-confirm-proceed { flex: 1; color: white; border: none; padding: 12px 20px; border-radius: 12px; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; }
        .btn-confirm-proceed:hover { transform: translateY(-2px); }

        .btn-confirm-proceed.approve-theme { background: linear-gradient(135deg, #10B981 0%, #047857 100%); box-shadow: 0 8px 16px rgba(4, 120, 87, 0.2); }
        .btn-confirm-proceed.reject-theme { background: linear-gradient(135deg, #EF4444 0%, #B91C1C 100%); box-shadow: 0 8px 16px rgba(185, 28, 28, 0.2); }
        .btn-confirm-proceed.save-theme { background: linear-gradient(135deg, #6366F1 0%, #4F46E5 100%); box-shadow: 0 8px 16px rgba(79, 70, 229, 0.2); }
        
        .confirm-icon.approve-theme { background: #ECFDF5; color: #059669; }
        .confirm-icon.reject-theme { background: #FEF2F2; color: #DC2626; }
        .confirm-icon.save-theme { background: #EEF2FF; color: #4F46E5; }

        @media (max-width: 900px) {
            .quote-row { flex-direction: column; align-items: flex-start; gap: 16px; }
            .q-meta, .q-details, .q-action { width: 100%; border-left: none; margin-left: 0; padding-left: 0; justify-content: flex-start;}
            .q-action { justify-content: flex-start; }
            .q-status { justify-content: flex-start; margin-top: 10px; }
        }

        @media (max-width: 768px) {
            .data-grid { grid-template-columns: 1fr; }
            .span-2, .span-4 { grid-column: 1 / -1; }
            .tabs-nav { width: 100%; flex-wrap: nowrap; overflow-x: auto; justify-content: flex-start; }
            .top-bar-wrapper { padding: 16px 0; margin-bottom: 20px; }
            .header { flex-direction: column; align-items: stretch; }
            .header-controls { justify-content: flex-start; }
            .m-grid { grid-template-columns: 1fr; gap: 20px; }
            .modal-header { padding: 20px 24px; }
            .modal-body { padding: 20px 24px; }
            .m-tot-row, .m-tot-net { width: 100%; }
            .modal-footer { padding: 20px 24px; flex-direction: column; gap: 10px;}
            .modal-footer .btn { width: 100%; margin: 0 !important; }
            .confirm-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="top-bar-wrapper">
        <div class="container" style="padding-bottom: 0;">
            <div class="header">
                <h1>Admin <span class="accent">Command Center</span></h1>
                <div class="header-controls">
                    <a href="../index.php" class="btn-nav">Employee Portal</a>
                    <a href="../logout.php" class="btn-nav btn-logout">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
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

        <div class="tabs-container">
            <div class="tabs-nav">
                <button class="tab-btn active" onclick="openTab('tab-sales', this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                    Sales Quotes <?php if($sales_count > 0) echo "<span class='badge-count'>$sales_count</span>"; ?>
                </button>
                <button class="tab-btn" onclick="openTab('tab-project', this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                    Project Quotes <?php if($project_count > 0) echo "<span class='badge-count'>$project_count</span>"; ?>
                </button>
                <button class="tab-btn" onclick="openTab('tab-inventory', this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                    Inventory Approvals <?php if($inv_count > 0) echo "<span class='badge-count'>$inv_count</span>"; ?>
                </button>
                <button class="tab-btn" onclick="openTab('tab-history', this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    Quotation History
                </button>
            </div>
        </div>

        <div id="tab-sales" class="tab-content active">
            <h2 class="section-title">Sales Quotations (Pending Review)</h2>
            <?php if (empty($sales_pending)): ?>
                <div class="empty-state">No pending sales quotations require approval right now.</div>
            <?php else: ?>
                <div class="quote-list">
                    <?php foreach($sales_pending as $q): 
                        $submitterName = !empty($q['full_name']) ? $q['full_name'] : ($q['username'] ?? 'Unknown');
                    ?>
                    <div class="quote-row" onclick="loadQuoteDetails(<?php echo $q['id']; ?>, 'sales')">
                        <div class="q-meta">
                            <span class="q-num"><?php echo htmlspecialchars($q['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="q-date">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                <?php echo date('M d, Y', strtotime($q['quote_date'])); ?>
                            </span>
                        </div>
                        <div class="q-details">
                            <span class="q-client"><?php echo htmlspecialchars($q['client_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="q-prep">Prepared By: <?php echo htmlspecialchars(ucwords(strtolower($submitterName)), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="q-action">
                            <span class="q-btn">
                                Review & Edit
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-project" class="tab-content">
            <h2 class="section-title">Project Quotations (Pending Review)</h2>
            <?php if (empty($project_pending)): ?>
                <div class="empty-state">No pending project quotations require approval right now.</div>
            <?php else: ?>
                <div class="quote-list">
                    <?php foreach($project_pending as $p): 
                            $submitterName = !empty($p['prepared_by']) ? $p['prepared_by'] : 'Unknown';
                    ?>
                    <div class="quote-row" onclick="loadQuoteDetails(<?php echo $p['id']; ?>, 'project')">
                        <div class="q-meta">
                            <span class="q-num"><?php echo htmlspecialchars($p['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="q-date">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                <?php echo date('M d, Y', strtotime($p['quote_date'])); ?>
                            </span>
                        </div>
                        <div class="q-details">
                            <span class="q-client"><?php echo htmlspecialchars($p['project_name'] ?? 'Project', ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="q-prep">Prepared By: <?php echo htmlspecialchars(ucwords(strtolower($submitterName)), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="q-action">
                            <span class="q-btn">
                                Review & Edit
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-inventory" class="tab-content">
            <h2 class="section-title">Inventory Approvals</h2>
            <?php if (empty($pending_inventory)): ?>
                <div class="empty-state">
                    <span style="font-size: 1.2rem; display: block; margin-bottom: 8px;">🎉</span>
                    You are all caught up!<br>
                    <span style="font-size: 0.9rem; font-weight: 500;">There are currently no new machines or price updates waiting for approval.</span>
                </div>
            <?php else: ?>
                <?php foreach ($pending_inventory as $req): 
                    $reqName = !empty($req['requested_by_fullname']) ? $req['requested_by_fullname'] : ($req['requested_by_name'] ?? 'Unknown');
                ?>
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
                                Requested by <strong><?php echo htmlspecialchars(ucwords(strtolower($reqName)), ENT_QUOTES, 'UTF-8'); ?></strong> 
                                on <?php echo date('M d, Y – h:i A', strtotime($req['created_at'])); ?>
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
                                    <div class="data-value" style="white-space: pre-line; line-height: 1.6; font-size: 0.95rem;"><?php echo htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8'); ?></div>
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
                            </div>
                        </div>
                        <div class="card-footer">
                            <a href="edit_inventory_request.php?id=<?php echo $req['id']; ?>" class="btn btn-pdf" style="margin-right: auto;">Edit Data</a>
                            
                            <form action="process_inventory.php" method="POST" style="display:inline;" id="inv-form-reject-<?php echo $req['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="approval_id" value="<?php echo $req['id']; ?>">
                                <button type="button" class="btn btn-reject" onclick="confirmFormSubmission(this, 'reject_inv', 'Are you sure you want to REJECT and delete this request?', 'reject')">Reject Request</button>
                            </form>
                            
                            <form action="process_inventory.php" method="POST" style="display:inline;" id="inv-form-approve-<?php echo $req['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="approval_id" value="<?php echo $req['id']; ?>">
                                <button type="button" class="btn btn-approve" onclick="confirmFormSubmission(this, 'approve_inv', 'Approve this request? This will instantly update the live inventory.', 'approve')">Approve & Publish</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="tab-history" class="tab-content">
            <h2 class="section-title">Quotation History Log</h2>
            <?php if (empty($quote_history)): ?>
                <div class="empty-state">No processed quotations found in history.</div>
            <?php else: ?>
                <div class="quote-list">
                    <?php foreach($quote_history as $log): ?>
                    <div class="quote-row" style="cursor: default;">
                        <div class="q-type">
                            <span class="type-badge"><?php echo htmlspecialchars($log['quote_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="q-meta">
                            <span class="q-num" style="color: var(--text-main);"><?php echo htmlspecialchars($log['quotation_no'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="q-date">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                <?php echo date('M d, Y – h:i A', strtotime($log['created_at'])); ?>
                            </span>
                        </div>
                        <div class="q-details" style="border-left: none; margin-left: 0; padding-left: 0;">
                            <span class="q-client"><?php echo htmlspecialchars($log['reference_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="q-status">
                            <?php 
                                if ($log['status'] === 'pending_super') echo '<span class="badge b-super"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Sent to Super Admin</span>';
                                elseif ($log['status'] === 'revision') echo '<span class="badge b-rev"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> Declined / Revision</span>';
                                elseif ($log['status'] === 'approved') echo '<span class="badge b-app"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg> Approved (Final)</span>';
                                elseif ($log['status'] === 'rejected') echo '<span class="badge b-rev"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg> Rejected (Final)</span>';
                                else echo '<span class="badge" style="background:#E2E8F0; color:#0F172A;">' . htmlspecialchars($log['status']) . '</span>';
                            ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="modal-overlay" id="quoteModal">
        <form class="modal-card" id="modalEditForm" method="POST" action="process_quote.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="modal_quote_action">
            <input type="hidden" name="quote_id" id="mq-quote-id">
            <input type="hidden" name="quote_type" id="mq-quote-type">

            <div class="modal-header">
                <h2><span id="mq-num">Quote No</span> <span class="modal-type-badge" id="mq-type">TYPE</span></h2>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <a href="#" id="mq-pdf-btn" target="_blank" class="btn btn-pdf">Preview PDF</a>
                    <button type="button" class="btn-close-modal" onclick="closeQuoteModal()">✕</button>
                </div>
            </div>
            
            <div class="modal-body">
                <div class="m-grid">
                    <div class="m-box">
                        <div class="m-box-title">Client / Project Details</div>
                        <div class="m-row">
                            <span class="m-label">Name / Project Name</span>
                            <input type="text" name="client_name" id="mq-client" class="m-input" required>
                        </div>
                        <div class="m-row">
                            <span class="m-label">Address / Location</span>
                            <textarea name="client_address" id="mq-address" class="m-input" required></textarea>
                        </div>
                        <div class="m-row">
                            <span class="m-label">Attention To</span>
                            <input type="text" name="attention_to" id="mq-attn" class="m-input">
                        </div>
                        <div class="m-row" style="flex-direction:row; gap:16px;">
                            <div style="flex:1;"><span class="m-label">Email</span><input type="email" name="client_email" id="mq-email" class="m-input"></div>
                            <div style="flex:1;"><span class="m-label">Contact</span><input type="text" name="client_contact" id="mq-contact" class="m-input"></div>
                        </div>
                    </div>
                    
                    <div class="m-box">
                        <div class="m-box-title">Transaction Details</div>
                        <div class="m-row"><span class="m-label">Purpose</span><input type="text" name="proposal_purpose" id="mq-purp" class="m-input"></div>
                        <div class="m-row" style="flex-direction:row; gap:16px;">
                            <div style="flex:1;"><span class="m-label">Validity</span><input type="text" name="validity_date" id="mq-val" class="m-input"></div>
                            <div style="flex:1;"><span class="m-label">ETA</span><input type="text" name="eta" id="mq-eta" class="m-input"></div>
                        </div>
                        <div class="m-row">
                            <span class="m-label">Terms</span>
                            <textarea name="payment_terms" id="mq-terms" class="m-input"></textarea>
                        </div>
                    </div>
                </div>

                <div class="m-box-title">Requested Machines</div>
                <div class="table-responsive">
                    <table class="m-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Brand & Model</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:right;">Unit Price (₱)</th>
                                <th style="text-align:right;">Line Total (₱)</th>
                            </tr>
                        </thead>
                        <tbody id="mq-items-tbody">
                            </tbody>
                    </table>
                </div>

                <div class="m-totals">
                    <div class="m-tot-row"><span>Subtotal:</span> <span id="mq-sub">₱0.00</span></div>
                    <div class="m-tot-row" style="color:var(--danger);">
                        <span style="padding-top:10px;">Corporate Discount:</span> 
                        <input type="text" name="corporate_discount" id="mq-discount" class="m-input text-right calc-input" style="width: 140px; color:var(--danger); font-weight: 800;" value="0.00">
                    </div>
                    <div class="m-tot-net"><span>Net Total:</span> <span id="mq-net">₱0.00</span></div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-pdf" style="margin-right: auto;" onclick="confirmModalAction('modalEditForm', 'save', 'Just save changes to database without approving?', 'save')">Save Changes Only</button>
                <button type="button" class="btn btn-reject" onclick="confirmModalAction('modalEditForm', 'decline', 'Decline this quotation and send back for revision?', 'reject')">Decline & Revision</button>
                <button type="button" class="btn btn-approve" onclick="confirmModalAction('modalEditForm', 'approve', 'Save changes and submit this quotation to the Super Admin for final approval?', 'approve')">Approve & Send to Super Admin</button>
            </div>
        </form>
    </div>

    <div class="confirm-overlay" id="customConfirmModal">
        <div class="confirm-card">
            <div class="confirm-icon" id="confirmIconWrap">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
            <h3 class="confirm-title">Are you sure?</h3>
            <p class="confirm-msg" id="confirmMessage">Please confirm your action.</p>
            <div class="confirm-actions">
                <button class="btn-confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button class="btn-confirm-proceed" id="confirmProceedBtn">Yes, Proceed</button>
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

        // ==========================================
        // CUSTOM CONFIRMATION LOGIC
        // ==========================================
        let confirmActionCallback = null;

        function showConfirm(message, type, callback) {
            document.getElementById('confirmMessage').textContent = message;
            
            const btn = document.getElementById('confirmProceedBtn');
            const iconWrap = document.getElementById('confirmIconWrap');
            
            btn.className = 'btn-confirm-proceed';
            iconWrap.className = 'confirm-icon';
            
            if(type === 'approve') {
                btn.classList.add('approve-theme');
                iconWrap.classList.add('approve-theme');
            } else if (type === 'reject') {
                btn.classList.add('reject-theme');
                iconWrap.classList.add('reject-theme');
            } else {
                btn.classList.add('save-theme');
                iconWrap.classList.add('save-theme');
            }
            
            confirmActionCallback = callback;
            document.getElementById('customConfirmModal').classList.add('active');
        }

        function closeConfirmModal() {
            document.getElementById('customConfirmModal').classList.remove('active');
            confirmActionCallback = null;
        }

        document.getElementById('confirmProceedBtn').addEventListener('click', function() {
            if (confirmActionCallback) confirmActionCallback();
            closeConfirmModal();
        });

        function confirmModalAction(formId, submitType, message, themeType) {
            showConfirm(message, themeType, function() {
                const form = document.getElementById(formId);
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'submit_type';
                input.value = submitType;
                form.appendChild(input);
                form.submit();
            });
        }

        function confirmFormSubmission(buttonElement, actionValue, message, themeType) {
            showConfirm(message, themeType, function() {
                const form = buttonElement.closest('form');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'action';
                input.value = actionValue;
                form.appendChild(input);
                form.submit();
            });
        }

        // ==========================================
        // AJAX MODAL EDITOR LOGIC
        // ==========================================
        const modal = document.getElementById('quoteModal');
        
        function formatMoney(amount) {
            return parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        function unformatMoney(val) {
            return parseFloat(val.toString().replace(/,/g, '')) || 0;
        }

        function loadQuoteDetails(quoteId, type) {
            const formData = new FormData();
            formData.append('ajax_get_quote_details', '1');
            formData.append('quote_id', quoteId);
            formData.append('type', type);

            fetch('ajax_get_quote.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    populateModal(data.quote, data.items, type);
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden'; 
                } else {
                    alert('Error loading details: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error loading details.');
            });
        }

        function populateModal(quote, items, type) {
            document.getElementById('mq-quote-id').value = quote.id;
            document.getElementById('mq-quote-type').value = type;
            
            document.getElementById('mq-num').textContent = quote.quotation_no;
            document.getElementById('mq-type').textContent = type.toUpperCase() + ' QUOTE';
            
            // --- LOGIC TO HIDE TOOLBAR IF NOT APPROVED ---
            const viewMode = (quote.status === 'approved') ? '' : '#toolbar=0';
            document.getElementById('mq-pdf-btn').href = `../generate_pdf.php?id=${quote.id}&type=${type}${viewMode}`;
            // -----------------------------------------------------

            document.getElementById('mq-client').value = (type === 'sales') ? quote.client_name : quote.project_name;
            document.getElementById('mq-address').value = (type === 'sales') ? quote.client_address : (quote.project_location || '');
            document.getElementById('mq-attn').value = quote.attention_to || '';
            document.getElementById('mq-email').value = quote.client_email || '';
            document.getElementById('mq-contact').value = quote.client_contact || '';
            
            document.getElementById('mq-purp').value = quote.proposal_purpose || '';
            document.getElementById('mq-val').value = quote.validity_date || '';
            document.getElementById('mq-eta').value = quote.eta || '';
            document.getElementById('mq-terms').value = quote.payment_terms || '';
            
            document.getElementById('mq-discount').value = formatMoney(quote.corporate_discount || 0);

            const tbody = document.getElementById('mq-items-tbody');
            tbody.innerHTML = '';
            
            items.forEach((item, index) => {
                const imgPath = item.picture ? `../../images/machine_images/${item.picture}` : '';
                const imgTag = item.picture ? `<img src="${imgPath}" class="item-img">` : `<div class="item-img" style="display:flex;align-items:center;justify-content:center;font-size:0.6rem;color:var(--text-light);font-weight:bold;">NO IMG</div>`;
                
                const unitPrice = parseFloat(item.unit_price || item.price || 0);
                const qty = parseInt(item.qty || 1);
                const itemId = item.item_id;

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="width: 60px;">${imgTag}</td>
                    <td>
                        <div style="font-size: 0.65rem; color: var(--maroon); font-weight: 800; text-transform: uppercase;">${item.brand || 'N/A'}</div>
                        <div style="font-family: 'Outfit', sans-serif; font-weight: 800; color: var(--text-main); font-size: 1.05rem;">${item.model_no || 'N/A'}</div>
                    </td>
                    <td style="text-align:center; width: 100px;">
                        <input type="number" name="items[${itemId}][qty]" class="m-input text-center calc-input" style="text-align:center;" value="${qty}" min="1" required>
                    </td>
                    <td style="text-align:right; width: 160px;">
                        <input type="text" name="items[${itemId}][price]" class="m-input calc-input" style="text-align:right; color:var(--maroon); font-weight:800;" value="${formatMoney(unitPrice)}" required>
                    </td>
                    <td style="text-align:right; color:var(--maroon); font-weight:800; vertical-align: middle;" id="line-total-${index}">
                        ₱${formatMoney(unitPrice * qty)}
                    </td>
                `;
                tbody.appendChild(tr);
            });

            document.querySelectorAll('.calc-input').forEach(el => {
                el.addEventListener('input', recalcModalTotals);
                el.addEventListener('blur', function() {
                    if(this.id === 'mq-discount' || this.name.includes('[price]')) {
                        this.value = formatMoney(unformatMoney(this.value));
                    }
                    recalcModalTotals();
                });
            });

            recalcModalTotals();
        }

        function recalcModalTotals() {
            let subtotal = 0;
            const tbody = document.getElementById('mq-items-tbody');
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach((row, idx) => {
                const qtyInput = row.querySelector('input[type="number"]');
                const priceInput = row.querySelector('input[type="text"]');
                if (qtyInput && priceInput) {
                    const qty = unformatMoney(qtyInput.value);
                    const price = unformatMoney(priceInput.value);
                    const lineTotal = qty * price;
                    subtotal += lineTotal;
                    document.getElementById(`line-total-${idx}`).textContent = '₱' + formatMoney(lineTotal);
                }
            });

            const discount = unformatMoney(document.getElementById('mq-discount').value);
            const net = Math.max(0, subtotal - discount);

            document.getElementById('mq-sub').textContent = '₱' + formatMoney(subtotal);
            document.getElementById('mq-net').textContent = '₱' + formatMoney(net);
        }

        function closeQuoteModal() {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto'; 
        }

        modal.addEventListener('click', function(e) {
            if (e.target === this) closeQuoteModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeQuoteModal();
                closeConfirmModal();
            }
        });
    </script>
</body>
</html>