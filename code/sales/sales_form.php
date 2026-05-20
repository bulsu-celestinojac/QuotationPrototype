<?php
session_start();
require '../db.php';

// ── Generate CSRF Token (once per session) ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$selected_items_json = $_POST['selected_items'] ?? '[]';
$selected_ids = json_decode($selected_items_json, true);

if (empty($selected_ids) || !is_array($selected_ids)) {
    header("Location: ../index.php");
    exit;
}

$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
$stmt->execute($selected_ids);
$machines = $stmt->fetchAll();

// Auto-fill Prepared By (Fetches Full Name from database)
$user_id = $_SESSION['user_id'] ?? 0;
$stmtUser = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$currentUser = $stmtUser->fetch();
$prepared_name = !empty($currentUser['full_name']) ? $currentUser['full_name'] : ($currentUser['username'] ?? '');

// Generate Initial Quotation Number (YYMMDD_AMG_0001)
$stmtId = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_quotations'");
$nextId = (int)$stmtId->fetchColumn();
if ($nextId === 0) {
    $stmtFallback = $pdo->query("SELECT MAX(id) FROM sales_quotations");
    $nextId = (int)$stmtFallback->fetchColumn() + 1;
}
$paddedId = str_pad($nextId, 4, '0', STR_PAD_LEFT);
$default_quote_num = date('ymd') . '_AMG_' . $paddedId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Sales Quotation Builder - AM Group</title>
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
            --shadow-lg: 0 24px 60px -10px rgba(0, 0, 0, 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 40px 20px; min-height: 100vh; overflow-x: hidden; }
        .container { max-width: 1500px; margin: 0 auto; padding: 0 20px; }
        
        /* FLUID HEADER */
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 40px; border-bottom: 1px solid rgba(226, 232, 240, 0.8); padding-bottom: 24px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: clamp(2rem, 4vw, 2.5rem); font-weight: 900; text-transform: uppercase; letter-spacing: -0.02em; line-height: 1; margin: 0; }
        .page-title .accent { color: var(--maroon); }
        
        /* PREMIUM ANIMATED BACK BUTTON */
        .btn-back { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            padding: 12px 24px; 
            background: var(--surface); 
            border: 1px solid rgba(139, 21, 56, 0.2); 
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

        .layout-grid { display: grid; grid-template-columns: 1fr 1.1fr; gap: 40px; align-items: start; }
        .left-col { display: flex; flex-direction: column; gap: 32px; min-width: 0; }
        .right-col { position: sticky; top: 40px; min-width: 0; }
        
        /* PREMIUM CARDS */
        .card { 
            background: var(--surface); 
            border-radius: 32px; 
            padding: 40px; 
            border: 1px solid rgba(226, 232, 240, 0.8); 
            box-shadow: var(--shadow-md); 
        }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 900; margin-bottom: 32px; color: var(--text-main); letter-spacing: -0.02em; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .full-width { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        
        label { font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        label.required::after { content: ' *'; color: var(--maroon); font-weight: 900; font-size: 0.9rem; }

        /* PREMIUM INPUTS */
        input[type="text"], input[type="date"], input[type="email"], input[type="tel"], input[type="number"], textarea { 
            width: 100%; 
            padding: 14px 18px; 
            border-radius: 16px; 
            border: 1px solid var(--border); 
            background: #F8FAFC; 
            font-size: 0.95rem; 
            font-family: 'DM Sans', sans-serif; 
            font-weight: 500; 
            color: var(--text-main); 
            outline: none; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        input:focus, textarea:focus { background: var(--surface); border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); transform: translateY(-1px); }
        textarea { resize: vertical; min-height: 100px; }
        .readonly-input { background: transparent !important; border: 1px dashed var(--border) !important; color: var(--text-muted) !important; pointer-events: none; font-weight: 800; letter-spacing: 1px; }

        /* VALIDATION FEEDBACK */
        input:invalid:not(:placeholder-shown):not(:focus):not(.readonly-input),
        textarea:invalid:not(:placeholder-shown):not(:focus) {
            border-color: var(--danger);
            background: #FEF2F2;
        }
        .validation-hint {
            display: none;
            font-size: 0.7rem;
            color: var(--danger);
            font-weight: 600;
            margin-top: 4px;
            letter-spacing: 0.02em;
        }
        input:invalid:not(:placeholder-shown):not(:focus):not(.readonly-input) ~ .validation-hint,
        textarea:invalid:not(:placeholder-shown):not(:focus) ~ .validation-hint {
            display: block;
        }
        input:valid:not(.readonly-input):not([type="date"]) {
            border-color: #22C55E20;
        }
        
        /* MACHINE ITEMS LIST */
        .machine-items-container { display: flex; flex-direction: column; gap: 16px; max-height: 55vh; overflow-y: auto; padding-right: 8px; margin-bottom: 32px; }
        .machine-item { display: grid; grid-template-columns: 80px 1fr 130px 90px; gap: 20px; align-items: center; padding: 20px; border: 1px solid var(--border); border-radius: 20px; background: var(--surface); transition: all 0.3s ease; }
        .machine-item:hover { border-color: rgba(139, 21, 56, 0.2); box-shadow: var(--shadow-sm); }
        
        .machine-img { width: 80px; height: 80px; border-radius: 12px; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; padding: 8px; background: #F8FAFC; position: relative; overflow: hidden; cursor: zoom-in; }
        .machine-img img { max-width: 100%; max-height: 100%; object-fit: contain; transition: transform 0.3s ease; }
        .machine-img:hover img { transform: scale(1.1); }
        .machine-img::after { content: "🔍"; position: absolute; inset: 0; background: rgba(139, 21, 56, 0.1); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; font-size: 1.2rem; pointer-events: none; }
        .machine-img:hover::after { opacity: 1; }
        
        .machine-info { flex: 1; min-width: 0; }
        .m-brand { font-size: 0.65rem; font-weight: 800; color: var(--maroon); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 4px; display: inline-block; background: var(--maroon-light); padding: 4px 10px; border-radius: 50px; }
        .m-model { font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2; color: var(--text-main); margin-top: 6px; }
        
        .control-group { display: flex; flex-direction: column; gap: 8px; }
        
        .input-qty-edit { width: 100%; text-align: center; padding: 12px 8px !important; background: #F8FAFC !important; border: 1px solid var(--border) !important; border-radius: 12px !important; font-size: 1.1rem !important; font-weight: 800 !important; color: var(--text-main) !important; margin: 0; }
        .input-price-edit { width: 100%; text-align: right; padding: 12px 12px !important; background: #FFF1F5 !important; border: 1px solid rgba(139, 21, 56, 0.2) !important; border-radius: 12px !important; font-size: 1rem !important; font-weight: 800 !important; color: var(--maroon) !important; margin: 0; }

        /* FINANCIAL SUMMARY */
        .financial-summary-block { background: #F8FAFC; padding: 32px; border-radius: 24px; border: 1px dashed var(--border); margin-bottom: 32px; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .summary-row.total-row { border-top: 2px dashed var(--border); padding-top: 24px; margin-top: 24px; margin-bottom: 0; }
        .summary-label { font-size: 0.85rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .summary-value { font-family: 'DM Sans', sans-serif; font-size: 1.2rem; font-weight: 800; color: var(--text-main); }
        .summary-total { font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 900; color: var(--maroon); letter-spacing: -0.02em; }
        
        /* PREMIUM ACTION BUTTONS */
        .action-buttons { display: flex; gap: 16px; margin-top: 20px; }
        .btn-preview { flex: 1; background: var(--maroon-light); color: var(--maroon); height: 60px; border: 1px solid rgba(139, 21, 56, 0.2); border-radius: 16px; font-size: 0.95rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-preview:hover { background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; border-color: transparent; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); }
        
        .btn-submit { flex: 1; background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; height: 60px; border: none; border-radius: 16px; font-size: 0.95rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); }
        .btn-submit:hover { filter: brightness(1.1); transform: translateY(-2px); box-shadow: 0 12px 25px rgba(139, 21, 56, 0.35); }

        /* FULL SCREEN ZOOM MODAL */
        .zoom-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
        .zoom-overlay.active { opacity: 1; pointer-events: all; }
        .zoom-overlay img { max-width: 90vw; max-height: 90vh; border-radius: 20px; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25); transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); background: white; padding: 10px;}
        .zoom-overlay.active img { transform: scale(1); }
        .zoom-close-btn { position: absolute; top: 24px; right: 32px; background: white; border: none; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; transition: all 0.2s ease; width: 48px; height: 48px; border-radius: 50%; box-shadow: var(--shadow-sm); display: flex; justify-content: center; align-items: center; z-index: 100000;}
        .zoom-close-btn:hover { color: var(--danger); transform: rotate(90deg); }

        /* ==========================================
           CUSTOM PREMIUM CONFIRMATION MODAL
           ========================================== */
        .confirm-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 100000; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; padding: 20px; }
        .confirm-overlay.active { display: flex; opacity: 1; }
        .confirm-card { background: var(--surface); border-radius: 24px; padding: 32px; max-width: 420px; width: 100%; box-shadow: 0 24px 60px rgba(0,0,0,0.2); transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); text-align: center; }
        .confirm-overlay.active .confirm-card { transform: scale(1); }
        .confirm-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; transition: all 0.3s ease; background: var(--maroon-light); color: var(--maroon); }
        .confirm-title { font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 900; color: var(--text-main); margin-bottom: 12px; }
        .confirm-msg { font-size: 1rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 30px; font-weight: 500; }
        .confirm-actions { display: flex; gap: 12px; justify-content: center; }
        .btn-confirm-cancel { flex: 1; background: #F8FAFC; color: var(--text-main); border: 1px solid var(--border); padding: 12px 20px; border-radius: 12px; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.2s ease; }
        .btn-confirm-cancel:hover { background: #E2E8F0; }
        .btn-confirm-proceed { flex: 1; color: white; border: none; padding: 12px 20px; border-radius: 12px; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); box-shadow: 0 8px 16px rgba(139, 21, 56, 0.2); }
        .btn-confirm-proceed:hover { transform: translateY(-2px); filter: brightness(1.1); }

        /* TABLET RESPONSIVENESS */
        @media (max-width: 1024px) { 
            .layout-grid { grid-template-columns: 1fr; gap: 32px; } 
            .right-col { position: static; } 
        }

        /* EXACT MOBILE RESPONSIVENESS FIXES */
        @media (max-width: 600px) {
            body { padding: 15px 0; }
            .container { padding: 0 16px; }
            .header { flex-direction: column; align-items: stretch; gap: 16px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: none; }
            .page-title { font-size: 2.2rem; text-align: center; width: 100%; margin-bottom: 10px; }
            .btn-back { width: 100%; justify-content: center; height: 50px; font-size: 0.9rem; border-radius: 12px; }
            
            .card { padding: 24px 20px; border-radius: 24px; }
            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            
            .machine-item { grid-template-columns: 70px 1fr; gap: 16px; padding: 16px; }
            .machine-info { grid-column: 2; }
            
            .control-group { grid-column: 1 / -1; display: flex; flex-direction: row; justify-content: space-between; align-items: center; background: #F8FAFC; padding: 12px 16px; border-radius: 16px; }
            .control-group label { margin-bottom: 0; }
            .input-qty-edit { width: 80px !important; padding: 10px !important; }
            .input-price-edit { width: 140px !important; padding: 10px !important; }

            .financial-summary-block { padding: 24px 20px; border-radius: 20px; }
            .summary-total { font-size: 1.8rem; }

            .action-buttons { flex-direction: column; gap: 12px; }
            .btn-preview, .btn-submit { width: 100%; flex: none; height: 56px; border-radius: 16px; }

            .zoom-close-btn { top: 16px; right: 16px; width: 40px; height: 40px; font-size: 1.2rem;}
            .confirm-actions { flex-direction: column; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1 class="page-title">Sales <span class="accent">Quotation</span></h1>
            <a href="../index.php" class="btn-back">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to Inventory
            </a>
        </div>

        <form method="POST" autocomplete="off" id="salesQuoteForm">
            <input type="hidden" name="quote_type" value="sales">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <div class="layout-grid">
                
                <div class="left-col">
                    <div class="card">
                        <div class="card-title">Customer Information</div>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="required">Client Name</label>
                                <input type="text" name="client_name" placeholder="Enter client or company name" autocomplete="off" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[0-9]/g, '');" pattern="[A-Za-z\s\-\.,'&()]+" title="Letters only — no numbers allowed">
                                <span class="validation-hint">Letters only — numbers are not allowed</span>
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Client Address</label>
                                <textarea name="client_address" placeholder="Enter complete billing/delivery address" autocomplete="off" required minlength="5" title="Enter the complete address (minimum 5 characters)"></textarea>
                                <span class="validation-hint">Please enter a complete address (at least 5 characters)</span>
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Attention To</label>
                                <input type="text" name="attention_to" placeholder="Full name of contact person" autocomplete="off" required oninput="this.value = this.value.replace(/[0-9]/g, '');" pattern="[A-Za-z\s\-\.,' ]+" title="Letters only — no numbers allowed">
                                <span class="validation-hint">Letters only — numbers are not allowed</span>
                            </div>
                            <div class="form-group">
                                <label class="required">Client Email Address</label>
                                <input type="email" name="client_email" placeholder="example@domain.com" autocomplete="off" required pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}" title="Enter a valid email address (e.g. name@domain.com)">
                                <span class="validation-hint">Enter a valid email (e.g. name@domain.com)</span>
                            </div>
                            <div class="form-group">
                                <label class="required">Contact Number</label>
                                <input type="tel" name="client_contact" placeholder="e.g. 09171234567" autocomplete="off" required oninput="this.value = this.value.replace(/[^0-9]/g, '');" minlength="7" maxlength="15" pattern="[0-9]{7,15}" title="Enter a valid phone number (7-15 digits)">
                                <span class="validation-hint">Enter 7 to 15 digits only</span>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title">Transaction Details</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Date</label>
                                <input type="date" name="quote_date" id="quote_date" value="<?=date('Y-m-d')?>" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Quotation No.</label>
                                <input type="text" name="quotation_no" id="quotation_no" class="readonly-input" value="<?=$default_quote_num?>" readonly tabindex="-1" required>
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Proposal Purpose</label>
                                <input type="text" name="proposal_purpose" placeholder="e.g. MACHINE EQUIPMENT" autocomplete="off" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[0-9]/g, '');" pattern="[A-Za-z\s\-\.,'&()/]+" title="Letters only — no numbers allowed">
                                <span class="validation-hint">Letters only — numbers are not allowed</span>
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Payment Terms</label>
                                <textarea name="payment_terms" placeholder="50% Down payment upon confirmation...&#10;50% Before shipment..." autocomplete="off" required minlength="3" title="Enter the payment terms (minimum 3 characters)"></textarea>
                                <span class="validation-hint">Please enter the payment terms (at least 3 characters)</span>
                            </div>
                            <div class="form-group full-width">
                                <label>Inclusions</label>
                                <textarea name="inclusions" placeholder="Optional details (e.g. 1 Year Warranty, Free Delivery...)" autocomplete="off"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="required">Validity Offer Date</label>
                                <input type="text" name="validity_date" placeholder="e.g. 30 Days" autocomplete="off" required minlength="1" title="Enter validity period (e.g. 30 Days)">
                            </div>
                            <div class="form-group">
                                <label class="required">ETA</label>
                                <input type="text" name="eta" placeholder="e.g. 120 Days" autocomplete="off" required minlength="1" title="Enter estimated delivery time (e.g. 120 Days)">
                            </div>
                            
                            <div class="form-group full-width" style="margin-top: 16px; padding-top: 24px; border-top: 1px dashed var(--border);">
                                <label style="color: var(--maroon);">Special Corporate Discount (₱) - Applied to Grand Total</label>
                                <input type="text" name="corporate_discount" id="corporate_discount" value="0.00" autocomplete="off" required style="font-size: 1.25rem; font-weight: 800; color: var(--maroon); background: #FFF1F5; border-color: rgba(139, 21, 56, 0.2);">
                            </div>
                            
                            <div class="form-group full-width">
                                <label class="required">Prepared By</label>
                                <input type="text" name="prepared_by" class="readonly-input" value="<?= htmlspecialchars($prepared_name) ?>" tabindex="-1" readonly required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="right-col">
                    <div class="card">
                        <div class="card-title">Selected Machines (<?=count($machines)?>)</div>
                        
                        <div class="machine-items-container">
                            <?php foreach ($machines as $index => $machine): 
                                $imgPath = '../../images/machine_images/' . htmlspecialchars($machine['picture']);
                            ?>
                                <div class="machine-item">
                                    <input type="hidden" name="items[<?=$index?>][id]" value="<?=$machine['id']?>">
                                    
                                    <div class="machine-img">
                                        <?php if ($machine['picture']): ?>
                                            <img src="<?=$imgPath?>" class="preview-zoom" alt="Product Image">
                                        <?php else: ?>
                                            <span style="font-size:0.6rem; font-weight: 800; color: var(--text-light);">NO IMG</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="machine-info">
                                        <div class="m-brand"><?=htmlspecialchars($machine['brand'])?></div>
                                        <div class="m-model" title="<?=htmlspecialchars($machine['model_no'])?>"><?=htmlspecialchars($machine['model_no'])?></div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label style="color: var(--maroon);">Price (₱)</label>
                                        <input type="text" name="items[<?=$index?>][price]" class="input-price-edit calc-price" value="<?= number_format((float)$machine['selling_price'], 2) ?>" autocomplete="off" required>
                                    </div>

                                    <div class="control-group">
                                        <label>QTY</label>
                                        <input type="number" name="items[<?=$index?>][qty]" class="input-qty-edit calc-qty" value="1" min="1" autocomplete="off" required>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="financial-summary-block">
                            <div class="summary-row">
                                <span class="summary-label">Subtotal</span>
                                <span class="summary-value" id="live-subtotal">₱0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Discount</span>
                                <span class="summary-value" id="live-discount" style="color: var(--danger);">- ₱0.00</span>
                            </div>
                            <div class="summary-row total-row">
                                <span class="summary-label" style="color: var(--text-main);">Net Total</span>
                                <span class="summary-total" id="live-total">₱0.00</span>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button type="button" class="btn-preview" onclick="reviewPDF()">Review PDF Preview</button>
                            <button type="button" class="btn-submit" onclick="submitToAdmin()">Submit to Admin</button>
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <div class="zoom-overlay" id="zoom-overlay">
        <button type="button" class="zoom-close-btn" id="zoom-close">✕</button>
        <img id="zoomed-image" src="" alt="Zoomed Product">
    </div>

    <div class="confirm-overlay" id="customConfirmModal">
        <div class="confirm-card">
            <div class="confirm-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <h3 class="confirm-title">Finalize Quote?</h3>
            <p class="confirm-msg" id="confirmMessage">Are you sure you want to finalize this quote and submit it to the Admin for approval?</p>
            <div class="confirm-actions">
                <button class="btn-confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button class="btn-confirm-proceed" id="confirmProceedBtn">Yes, Submit it!</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Live Financial Calculations ---
            const qtyInputs = document.querySelectorAll('.calc-qty');
            const priceInputs = document.querySelectorAll('.calc-price');
            const discountInput = document.getElementById('corporate_discount');
            const subtotalEl = document.getElementById('live-subtotal');
            const discountEl = document.getElementById('live-discount');
            const totalEl = document.getElementById('live-total');

            function unformat(val) { return parseFloat(val.toString().replace(/,/g, '')) || 0; }
            function format(val) { return val.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

            function calculateLiveTotals() {
                let subtotal = 0;
                document.querySelectorAll('.machine-item').forEach(item => {
                    const price = unformat(item.querySelector('.calc-price').value);
                    const qty = unformat(item.querySelector('.calc-qty').value);
                    subtotal += (price * qty);
                });
                const discount = unformat(discountInput.value);
                const total = Math.max(0, subtotal - discount);

                subtotalEl.textContent = '₱' + format(subtotal);
                discountEl.textContent = '- ₱' + format(discount);
                totalEl.textContent = '₱' + format(total);
            }

            qtyInputs.forEach(input => input.addEventListener('input', calculateLiveTotals));
            priceInputs.forEach(input => input.addEventListener('input', calculateLiveTotals));
            discountInput.addEventListener('input', calculateLiveTotals);
            calculateLiveTotals();

            document.querySelectorAll('.calc-price, #corporate_discount').forEach(input => {
                input.addEventListener('blur', function() {
                    let val = unformat(this.value);
                    this.value = format(val);
                    calculateLiveTotals();
                });
                input.addEventListener('focus', function() {
                    let val = unformat(this.value);
                    if(val > 0) this.value = val;
                    else this.value = '';
                });
            });

            // --- Auto Quotation Number Generator ---
            const dateInput = document.getElementById('quote_date');
            const quoteNoInput = document.getElementById('quotation_no');
            const nextIdStr = "<?= $paddedId ?>";

            dateInput.addEventListener('change', function() {
                if(this.value) {
                    const dateParts = this.value.split('-'); 
                    if(dateParts.length === 3) {
                        const yy = dateParts[0].substring(2); 
                        const mm = dateParts[1];
                        const dd = dateParts[2];
                        quoteNoInput.value = yy + mm + dd + '_AMG_' + nextIdStr;
                    }
                }
            });

            // --- FIXED IMAGE ZOOM LOGIC ---
            const zoomOverlay = document.getElementById('zoom-overlay');
            const zoomedImage = document.getElementById('zoomed-image');
            const zoomClose = document.getElementById('zoom-close');

            document.querySelectorAll('.machine-img').forEach(container => {
                container.addEventListener('click', function(e) {
                    const img = this.querySelector('img');
                    if (img) {
                        e.preventDefault();
                        e.stopPropagation();
                        zoomedImage.src = img.src;
                        zoomOverlay.classList.add('active');
                    }
                });
            });

            zoomClose.addEventListener('click', () => zoomOverlay.classList.remove('active'));
            zoomOverlay.addEventListener('click', function(e) { 
                if (e.target === this) zoomOverlay.classList.remove('active'); 
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    zoomOverlay.classList.remove('active');
                    closeConfirmModal();
                }
            });

            // --- Prevent number keys on text-only fields ---
            document.querySelectorAll('[name="client_name"], [name="attention_to"], [name="proposal_purpose"]').forEach(function(field) {
                field.addEventListener('keydown', function(e) {
                    if ([8, 9, 13, 27, 35, 36, 37, 38, 39, 40, 46].indexOf(e.keyCode) !== -1) return;
                    if ((e.ctrlKey || e.metaKey) && [65, 67, 86, 88, 90].indexOf(e.keyCode) !== -1) return;
                    if ((e.keyCode >= 48 && e.keyCode <= 57) || (e.keyCode >= 96 && e.keyCode <= 105)) {
                        e.preventDefault();
                    }
                });
            });

            // --- Prevent non-numeric keys on contact number ---
            document.querySelector('[name="client_contact"]').addEventListener('keydown', function(e) {
                if ([8, 9, 13, 27, 35, 36, 37, 38, 39, 40, 46].indexOf(e.keyCode) !== -1) return;
                if ((e.ctrlKey || e.metaKey) && [65, 67, 86, 88, 90].indexOf(e.keyCode) !== -1) return;
                if ((e.keyCode >= 48 && e.keyCode <= 57) || (e.keyCode >= 96 && e.keyCode <= 105)) return;
                e.preventDefault();
            });
        });

        // --- Form Validation Helper ---
        function validateForm() {
            const form = document.getElementById('salesQuoteForm');

            const textOnlyFields = form.querySelectorAll('[name="client_name"], [name="attention_to"], [name="proposal_purpose"]');
            let hasTextError = false;
            textOnlyFields.forEach(function(field) {
                field.value = field.value.replace(/[0-9]/g, '');
                if (field.value.trim() === '') {
                    field.focus();
                    hasTextError = true;
                }
            });
            if (hasTextError) {
                form.reportValidity();
                return false;
            }

            const contactField = form.querySelector('[name="client_contact"]');
            if (contactField) {
                contactField.value = contactField.value.replace(/[^0-9]/g, '');
                if (contactField.value.length < 7 || contactField.value.length > 15) {
                    contactField.focus();
                    contactField.reportValidity();
                    return false;
                }
            }

            const emailField = form.querySelector('[name="client_email"]');
            if (emailField) {
                const emailPattern = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;
                if (!emailPattern.test(emailField.value.trim())) {
                    emailField.focus();
                    emailField.reportValidity();
                    return false;
                }
            }

            if (!form.reportValidity()) return false;

            return true;
        }

        // --- Form Submissions ---
        function reviewPDF() {
            if (validateForm()) {
                const form = document.getElementById('salesQuoteForm');
                form.action = 'sales_preview.php';
                form.target = '_blank';
                form.submit();
            }
        }

        // ==========================================
        // CUSTOM SUBMIT CONFIRMATION
        // ==========================================
        let confirmActionCallback = null;

        function showConfirm(message, callback) {
            document.getElementById('confirmMessage').textContent = message;
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

        function submitToAdmin() {
            if (validateForm()) {
                showConfirm("Are you sure you want to finalize this quote and submit it to the Admin for approval?", function() {
                    const form = document.getElementById('salesQuoteForm');
                    form.action = 'sales_process.php';
                    form.target = '_self';
                    sessionStorage.removeItem('quoteCartData');
                    form.submit();
                });
            }
        }
    </script>
</body>
</html>