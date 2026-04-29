<?php
// ==========================================
// 1. PHP LOGIC & DATABASE PREPARATION
// ==========================================
require 'db.php';

$extracted_json = $_POST['extracted_json'] ?? '';
$incoming_items = [];

if (!empty($extracted_json)) {
    $incoming_items = json_decode($extracted_json, true);
} elseif (!empty($_POST['items'])) {
    $incoming_items = $_POST['items'];
}

if (empty($incoming_items)) {
    header("Location: schedule_parser.php");
    exit;
}

// FUZZY AUTO-MATCH: Strip spaces and hyphens from the incoming parsed model to match the DB safely
foreach ($incoming_items as &$item) {
    $clean_incoming_model = preg_replace('/[\s\-]/', '', $item['model']);
    
    // Check database by ignoring spaces and hyphens on both sides
    $stmt = $pdo->prepare("
        SELECT id, selling_price, description, picture 
        FROM items 
        WHERE REPLACE(REPLACE(model_no, ' ', ''), '-', '') = ? 
        LIMIT 1
    ");
    $stmt->execute([$clean_incoming_model]);
    $match = $stmt->fetch();
    
    if ($match) {
        $item['db_id'] = $match['id'];
        $item['price'] = $match['selling_price'];
        $item['full_desc'] = $match['description'];
        $item['picture'] = $match['picture']; 
    } else {
        $item['db_id'] = null;
        $item['price'] = 0;
        $item['full_desc'] = $item['original_text']; 
        $item['picture'] = null;
    }
}

$stmtId = $pdo->query("SELECT MAX(id) FROM project_quotations");
$nextId = (int)$stmtId->fetchColumn() + 1;
$default_quote_num = date('ydm') . '_PRJ_' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

// Fetch clients & inventory for auto-suggest
$clients = [];
$clean_inventory = [];

try {
    $stmtClients = $pdo->query("SELECT company_name, email, client_address, contact_no FROM clients");
    if ($stmtClients) $clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtItems = $pdo->query("SELECT brand, model_no, description, selling_price, picture FROM items");
    if ($stmtItems) {
        $raw_inventory = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw_inventory as $row) {
            
            $brand = (string)($row['brand'] ?? '');
            $model_no = (string)($row['model_no'] ?? '');
            $desc = (string)($row['description'] ?? '');
            $pic = (string)($row['picture'] ?? '');

            // CRITICAL FIX: Force UTF-8 Encoding to prevent json_encode from crashing on bad characters like ° or ™
            if (function_exists('mb_convert_encoding')) {
                $brand = mb_convert_encoding($brand, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                $model_no = mb_convert_encoding($model_no, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                $desc = mb_convert_encoding($desc, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                $pic = mb_convert_encoding($pic, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }

            $clean_inventory[] = [
                'brand' => trim($brand),
                'model_no' => trim($model_no),
                'description' => trim($desc),
                'selling_price' => (float)($row['selling_price'] ?? 0),
                'picture' => trim($pic)
            ];
        }
    }
} catch (Exception $e) {}

// Safe encoding flags to ensure quotes and brackets don't break the HTML
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$clients_json_safe = json_encode($clients ?: [], $json_flags) ?: '[]';
$inventory_json_safe = json_encode($clean_inventory ?: [], $json_flags) ?: '[]';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Quote Builder - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --bg: #FAFAFA; 
            --surface: #FFFFFF; 
            --text-main: #18181B; 
            --text-muted: #71717A; 
            --text-light: #A1A1AA;
            --border: #F4F4F5; 
            --maroon: #8B1538; 
            --maroon-light: #FDF2F4;
            --input-bg: #F4F4F5;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); line-height: 1.5; padding: 30px 20px; overflow-x: hidden; }
        .container { width: 100%; max-width: 1400px; margin: 0 auto; }

        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 20px; flex-wrap: wrap; gap: 16px; }
        h1 { font-family: 'Outfit', sans-serif; font-size: clamp(2rem, 4vw, 2.75rem); font-weight: 800; margin: 0; letter-spacing: -0.02em; color: var(--text-main); line-height: 1; }
        h1 span { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.2s ease; display: inline-block; }
        .btn-back:hover { color: var(--maroon); }

        .layout-grid { display: flex; flex-direction: column; gap: 40px; }
        
        .card { background: var(--surface); border-radius: 20px; padding: 32px; border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 10px 40px rgba(0,0,0,0.03); width: 100%; }

        .section-title {
            font-family: 'Outfit', sans-serif; 
            font-size: 1.5rem; 
            font-weight: 800; 
            margin-bottom: 24px; 
            color: var(--text-main);
            border-bottom: 2px solid var(--border);
            padding-bottom: 12px;
        }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full { grid-column: 1/-1; }
        label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.08em; display: block; margin-bottom: 6px; }
        input[type="text"], input[type="date"], input[type="number"], input[type="tel"], input[type="email"], select, textarea { 
            width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid transparent; background: var(--input-bg); font-family: 'DM Sans', sans-serif; font-size: 0.95rem; color: var(--text-main); font-weight: 500; transition: all 0.3s ease; outline: none;
        }
        input:focus, textarea:focus, select:focus { background: var(--surface); border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); }
        input::placeholder, textarea::placeholder { color: var(--text-light); font-weight: 400; }
        .readonly-input { background: transparent; border: 1px solid var(--border); color: var(--text-muted); pointer-events: none; }
        hr { border: none; border-top: 1px solid var(--border); margin: 8px 0; }

        .financial-summary-block { background: var(--bg); padding: 24px 32px; border-radius: 16px; border: 1px solid var(--border); margin-top: 32px; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
        .summary-row.total-row { border-top: 1px dashed var(--border); padding-top: 20px; margin-top: 20px; }
        .input-discount { width: 150px !important; text-align: right; padding: 12px 16px !important; border: 1px solid var(--border) !important; border-radius: 10px !important; font-family: 'DM Sans', sans-serif; font-weight: 700; font-size: 1.05rem; color: var(--maroon) !important; background: var(--surface) !important; transition: 0.2s; margin: 0 !important; }
        .input-discount:focus { border-color: var(--maroon) !important; box-shadow: 0 0 0 4px var(--maroon-light) !important; }

        .btn { padding: 16px 32px; border-radius: 50px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; border: none; display: inline-flex; align-items: center; justify-content: center; width: 100%; }
        .btn-primary { background: var(--maroon); color: white; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .btn-primary:hover { background: #6A0D28; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(139, 21, 56, 0.3); }
        .btn-dashed { background: transparent; border: 2px dashed #E4E4E7; color: #71717A; border-radius: 16px; font-weight: 800; font-size: 0.9rem; padding: 20px; transition: all 0.2s ease; margin-bottom: 0; }
        .btn-dashed:hover { background: var(--surface); border-color: var(--text-main); color: var(--text-main); }

        .items-list { display: flex; flex-direction: column; gap: 16px; margin-bottom: 24px; width: 100%; }
        .item-row { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 20px 24px; display: flex; flex-wrap: wrap; gap: 24px; align-items: center; position: relative; transition: all 0.3s ease; z-index: 1; }
        .item-row:hover { border-color: #E4E4E7; box-shadow: 0 10px 30px rgba(0,0,0,0.03); transform: translateY(-1px); }

        .item-mark { min-width: 60px; display: flex; align-items: center; justify-content: center; }
        .mark-badge { background: var(--maroon-light); color: var(--maroon); font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1.05rem; padding: 8px 14px; border-radius: 10px; text-align: center; }
        .item-image { width: 80px; height: 80px; border-radius: 12px; border: 1px solid var(--border); background: #FFF; display: flex; align-items: center; justify-content: center; padding: 6px; cursor: pointer; transition: all 0.3s ease; flex-shrink: 0;}
        .item-image:hover { border-color: var(--maroon); box-shadow: 0 4px 12px var(--maroon-light); }
        .item-image img { width: 100%; height: 100%; object-fit: contain; }
        .item-image span { font-size: 0.5rem; color: var(--text-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }

        .item-details { flex: 1; display: flex; flex-direction: column; justify-content: center; min-width: 250px; position: relative; }
        .item-brand-text { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-light); font-weight: 700; margin-bottom: 4px; }
        .item-model-text { font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 800; color: var(--text-main); margin-bottom: 4px; line-height: 1.2; }
        .item-desc-text { font-size: 0.9rem; color: var(--text-muted); display: block; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .item-details input[type="text"].input-model-search { font-family: 'Outfit', sans-serif !important; font-size: 1.4rem !important; font-weight: 800 !important; color: var(--text-main) !important; background: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; margin-bottom: 4px !important; outline: none !important; width: 100% !important; box-shadow: none !important; line-height: 1.2 !important; }
        .item-details input[type="text"].input-model-search::placeholder { color: var(--text-light); font-weight: 400; }
        .item-details input[type="text"].input-model-search.is-searching { border-bottom: 2px dashed var(--border) !important; }
        .badge-warning { background: #FEF2F2; color: #EF4444; font-size: 0.6rem; font-weight: 700; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; margin-bottom: 6px; align-self: flex-start; }

        .item-metrics { display: flex; gap: 32px; align-items: center; padding-right: 40px; }
        .metric-group { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .metric-group label { margin: 0; text-align: center; width: 100%; }
        .metric-value-text { font-family: 'DM Sans', sans-serif; font-size: 1.1rem; font-weight: 600; color: var(--text-main); white-space: nowrap; }
        
        .input-qty-edit { width: 55px; text-align: center; padding: 6px; background: transparent !important; border: 1px solid transparent !important; border-bottom: 1px dashed var(--text-light) !important; border-radius: 0 !important; font-size: 1.1rem; font-weight: 600; color: var(--text-main); height: auto; margin: 0; box-shadow: none !important; }
        .input-qty-edit:focus { border: 1px solid var(--border) !important; border-bottom: 1px solid var(--maroon) !important; background: var(--surface) !important; border-radius: 6px !important; }
        .input-qty-edit::-webkit-outer-spin-button, .input-qty-edit::-webkit-inner-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        .input-qty-edit[type=number] { -moz-appearance: textfield; appearance: textfield; }

        .btn-delete { position: absolute; right: 24px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: var(--text-light); font-size: 1.5rem; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s ease; }
        .btn-delete:hover { background: #FEF2F2; color: #EF4444; }

        .autocomplete-wrapper { position: relative; width: 100%; z-index: 10; }
        .autocomplete-list { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; max-height: 300px; overflow-y: auto; display: none; margin-top: 4px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); }
        .autocomplete-item { padding: 14px 20px; cursor: pointer; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .autocomplete-item:hover { background: var(--maroon-light); }
        .autocomplete-model { font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--text-main); font-size: 1.1rem;}
        .autocomplete-brand { font-size: 0.7rem; color: var(--maroon); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .autocomplete-no-results { padding: 14px 20px; color: var(--text-muted); font-size: 0.9rem; font-style: italic; text-align: center; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.85); backdrop-filter: blur(8px); }
        .modal-content { margin: auto; display: block; max-width: 85%; max-height: 85%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); border-radius: 16px; }
        .modal-close { position: absolute; top: 30px; right: 40px; color: rgba(255,255,255,0.6); font-size: 40px; font-weight: 300; cursor: pointer; }
        .modal-close:hover { color: #FFF; }

        @media (max-width: 768px) {
            body { padding: 15px 10px; }
            .form-grid { grid-template-columns: 1fr; } 
            .item-row { padding: 16px; flex-direction: column; align-items: flex-start; gap: 12px; }
            .item-mark { width: auto; margin-bottom: 0; }
            .item-details { width: 100%; }
            .item-desc-text { white-space: normal; }
            .item-metrics { width: 100%; justify-content: flex-start; padding-right: 0; border-top: 1px dashed var(--border); padding-top: 12px; margin-top: 8px; gap: 32px; }
            .metric-group { align-items: flex-start; }
            .metric-group label { text-align: left; }
            .btn-delete { position: absolute; top: 16px; right: 16px; transform: none; }
            .summary-row { flex-direction: column; align-items: flex-start; gap: 8px; }
            .input-discount { width: 100% !important; }
        }
    </style>
</head>
<body>

    <script>
        const clientsData = <?= $clients_json_safe ?>;
        const inventoryData = <?= $inventory_json_safe ?>;
        
        // This will print exactly how many items successfully loaded!
        console.log("SUCCESS! Inventory Loaded:", inventoryData.length, "items.");
    </script>

    <div class="container">
        
        <div class="page-header">
            <h1>PROJECT <span>QUOTATION</span></h1>
            <a href="schedule_parser.php" class="btn-back">← Back to Parser</a>
        </div>

        <form action="process_quote.php" method="POST" id="projectForm">
            <input type="hidden" name="quote_type" value="project">
            <input type="hidden" name="items_json" id="items_json" value="">
            
            <div class="layout-grid">

                <div class="project-items-wrapper">
                    <h2 class="section-title">1. Review Equipment List</h2>
                        
                    <div class="items-list" id="items-container">
                        <?php foreach ($incoming_items as $index => $item): 
                            $first_line_desc = explode("\n", str_replace("\r", "", $item['full_desc']))[0];
                        ?>
                            <div class="item-row">
                                <div class="item-mark">
                                    <span class="mark-badge"><?= htmlspecialchars($item['mark']) ?></span>
                                    <input type="hidden" class="i-mark" value="<?= htmlspecialchars($item['mark']) ?>">
                                </div>
                                
                                <div class="item-image" data-large-src="<?= !empty($item['picture']) ? '../images/machine_images/' . htmlspecialchars($item['picture']) : '' ?>">
                                    <?php if (!empty($item['picture'])): ?>
                                        <img src="../images/machine_images/<?= htmlspecialchars($item['picture']) ?>" alt="IMG">
                                    <?php else: ?>
                                        <span>NO IMG</span>
                                    <?php endif; ?>
                                    <input type="hidden" class="i-pic" value="<?= htmlspecialchars($item['picture'] ?? '') ?>">
                                </div>

                                <div class="item-details">
                                    <?php if(!$item['db_id']): ?>
                                        <span class="badge-warning">Not In Inventory</span>
                                    <?php endif; ?>
                                    <span class="item-brand-text"><?= htmlspecialchars($item['brand'] ?: 'NO BRAND') ?></span>
                                    <input type="hidden" class="i-brand" value="<?= htmlspecialchars($item['brand']) ?>">
                                    
                                    <span class="item-model-text"><?= htmlspecialchars($item['model']) ?></span>
                                    <input type="hidden" class="i-model" value="<?= htmlspecialchars($item['model']) ?>">
                                    
                                    <span class="item-desc-text" title="<?= htmlspecialchars($item['full_desc']) ?>"><?= htmlspecialchars($first_line_desc) ?></span>
                                    <input type="hidden" class="i-full-desc" value="<?= htmlspecialchars($item['full_desc']) ?>">
                                </div>

                                <div class="item-metrics">
                                    <div class="metric-group">
                                        <label>QTY</label>
                                        <input type="number" class="input-qty-edit i-qty" value="<?= $item['qty'] ?? 1 ?>" min="1" max="999">
                                    </div>
                                    <div class="metric-group">
                                        <label>PRICE</label>
                                        <span class="metric-value-text"><?= number_format((float)$item['price'], 2) ?></span>
                                        <input type="hidden" class="i-price" value="<?= $item['price'] ?>">
                                    </div>
                                </div>

                                <button type="button" class="btn-delete" title="Remove Item">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="btnAddItem" class="btn btn-dashed">+ ADD ADDITIONAL ITEM</button>
                </div>

                <div class="card project-details-card">
                    <h2 class="section-title" style="border:none; padding:0; margin-bottom:24px;">2. Project Details</h2>
                    
                    <div class="form-grid">
                        <div class="full">
                            <label>Company Name (Auto Caps)</label>
                            <input type="text" name="company_name" id="company_name" list="company_list" style="text-transform: uppercase;" autocomplete="off" required>
                            <datalist id="company_list"></datalist>
                        </div>
                        <div class="full">
                            <label>Project Name (Auto Caps)</label>
                            <input type="text" name="project_name" value="" style="text-transform: uppercase;" required>
                        </div>
                        <div>
                            <label>Contact Person</label>
                            <input type="text" name="contact_name" id="contact_name">
                        </div>
                        <div>
                            <label>Contact No.</label>
                            <input type="tel" name="contact_no" id="contact_no" pattern="^(09|\+639)\d{9}$|^[0-9]{2,3}[-\s]?[0-9]{7}$" placeholder="e.g. 09171234567">
                        </div>
                        <div class="full">
                            <label>Email Address</label>
                            <input type="email" name="email" id="email" placeholder="example@domain.com">
                        </div>
                        <div class="full">
                            <label>Complete Address</label>
                            <textarea name="client_address" id="client_address" rows="2" required></textarea>
                        </div>
                        
                        <div class="full"><hr></div>
                        
                        <div>
                            <label>Offer No.</label>
                            <input type="text" name="quotation_no" class="readonly-input" value="<?= $default_quote_num ?>" readonly tabindex="-1">
                        </div>
                        <div>
                            <label>Date</label>
                            <input type="date" name="quote_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div>
                            <label>Offer Validity</label>
                            <input type="text" name="offer_validity" value="">
                        </div>
                        <div>
                            <label>Mode of Dispatch</label>
                            <select name="mode_of_dispatch">
                                <option value="" disabled selected>Select mode</option>
                                <option value="Air">Air</option>
                                <option value="Land">Land</option>
                                <option value="Shipment">Shipment</option>
                                <option value="To Agree">To Agree</option>
                            </select>
                        </div>
                        <div class="full">
                            <label>Package</label>
                            <input type="text" name="package_type" value="">
                        </div>
                        <div class="full">
                            <label>Delivery Arrangements</label>
                            <input type="text" name="delivery_arrangements" value="">
                        </div>
                        <div class="full">
                            <label>Payment Terms</label>
                            <textarea name="payment_terms" rows="3"></textarea>
                        </div>
                        <div class="full">
                            <label>Inclusions</label>
                            <textarea name="inclusions" rows="2"></textarea>
                        </div>
                        
                        <div>
                            <label>Paper Size</label>
                            <select name="paper_size">
                                <option value="A4">A4 (Standard)</option>
                                <option value="A3">A3 (Large Format)</option>
                            </select>
                        </div>
                        <div class="full">
                            <label>Prepared By</label>
                            <input type="text" name="prepared_by" required>
                        </div>
                    </div>

                    <div class="financial-summary-block">
                        <div class="summary-row" style="margin-bottom: 16px;">
                            <span style="font-size: 0.95rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Subtotal:</span>
                            <span class="summary-value" id="display-subtotal" style="font-size: 1.25rem; font-weight: 700; color: var(--text-main);">₱0.00</span>
                        </div>
                        <div class="summary-row">
                            <span style="font-size: 0.95rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Discount (₱):</span>
                            <input type="number" step="0.01" name="discount_amount" id="discount_amount" value="0" class="input-discount">
                        </div>
                        <div class="summary-row total-row">
                            <span style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); text-transform: uppercase;">Total Net Amount:</span>
                            <span id="display-total" style="font-size: 2rem; color: var(--maroon); font-weight: 800; font-family: 'Outfit', sans-serif;">₱0.00</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 32px; font-size: 1.1rem; padding: 20px;">Generate Project PDF</button>

                </div>
            </div>
        </form>
    </div>

    <div id="imageModal" class="modal">
        <span class="modal-close">&times;</span>
        <img class="modal-content" id="img01">
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            function calculateTotals() {
                let subtotal = 0;
                document.querySelectorAll('.item-row').forEach(row => {
                    const qty = parseFloat(row.querySelector('.i-qty').value) || 0;
                    const price = parseFloat(row.querySelector('.i-price').value) || 0;
                    subtotal += (qty * price);
                });

                const discountInput = document.getElementById('discount_amount');
                const discount = parseFloat(discountInput.value) || 0;
                
                const total = Math.max(0, subtotal - discount);

                document.getElementById('display-subtotal').textContent = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                document.getElementById('display-total').textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
            calculateTotals();
            document.getElementById('discount_amount').addEventListener('input', calculateTotals);

            const companyInput = document.getElementById('company_name');
            if (companyInput) {
                companyInput.addEventListener('input', function() {
                    const val = this.value;
                    const dataList = document.getElementById('company_list');
                    
                    const match = clientsData.find(c => (c.company_name || '').toUpperCase() === val.trim().toUpperCase());
                    if (match) {
                        document.getElementById('email').value = match.email || '';
                        document.getElementById('client_address').value = match.client_address || '';
                        document.getElementById('contact_no').value = match.contact_no || '';
                    }

                    dataList.innerHTML = ''; 
                    if (val.length >= 2) {
                        clientsData.forEach(c => {
                            if ((c.company_name || '').toUpperCase().includes(val.toUpperCase())) {
                                const opt = document.createElement('option');
                                opt.value = c.company_name;
                                dataList.appendChild(opt);
                            }
                        });
                    }
                });
            }

            let addCounter = 1;
            const itemsContainer = document.getElementById('items-container');
            
            if (itemsContainer) {
                
                itemsContainer.addEventListener('focusin', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        const row = e.target.closest('.item-row');
                        if (row) row.style.zIndex = '50';
                    }
                });
                
                itemsContainer.addEventListener('focusout', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        const row = e.target.closest('.item-row');
                        if (row) row.style.zIndex = '1';
                    }
                });

                itemsContainer.addEventListener('keydown', function(e) {
                    if (e.target.classList.contains('input-model-search') && e.key === 'Enter') {
                        e.preventDefault(); 
                    }
                });

                itemsContainer.addEventListener('input', function(e) {
                    if (e.target.classList.contains('i-qty')) {
                        calculateTotals();
                    }
                });

                itemsContainer.addEventListener('input', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        const input = e.target;
                        const val = input.value.trim().toUpperCase();
                        const row = input.closest('.item-row');
                        const list = row.querySelector('.autocomplete-list');
                        
                        if (!list) return;
                        input.classList.add('is-searching');

                        if (val.length < 1) {
                            list.style.display = 'none';
                            return;
                        }
                        
                        const cleanVal = val.replace(/[\s\-]/g, '');

                        const matches = inventoryData.filter(i => {
                            const safeModel = String(i.model_no || '').toUpperCase();
                            const safeBrand = String(i.brand || '').toUpperCase();
                            const cleanModel = safeModel.replace(/[\s\-]/g, '');
                            
                            return cleanModel.includes(cleanVal) || safeBrand.includes(val) || safeModel.includes(val);
                        });
                        
                        list.innerHTML = '';
                        
                        if (matches.length > 0) {
                            matches.slice(0, 15).forEach(match => { 
                                const div = document.createElement('div');
                                div.className = 'autocomplete-item';
                                
                                div.innerHTML = `
                                    <div style="display:flex; flex-direction:column;">
                                        <span class="autocomplete-model">${match.model_no}</span>
                                        <span class="autocomplete-brand">${match.brand || 'NO BRAND'}</span>
                                    </div>
                                `;
                                
                                div.addEventListener('click', function() {
                                    input.value = match.model_no;
                                    input.classList.remove('is-searching');
                                    
                                    row.querySelector('.i-model').value = match.model_no;
                                    row.querySelector('.i-brand').value = match.brand || '';
                                    row.querySelector('.i-full-desc').value = match.description || '';
                                    row.querySelector('.i-price').value = match.selling_price || 0;
                                    
                                    row.querySelector('.item-brand-text').textContent = match.brand || 'NO BRAND';
                                    
                                    let firstLine = (match.description || '').split(/\r?\n/)[0];
                                    row.querySelector('.item-desc-text').textContent = firstLine || 'No description available.';
                                    
                                    const priceFormatted = parseFloat(match.selling_price || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                    row.querySelector('.metric-value-text').textContent = priceFormatted;
                                    
                                    const imgBox = row.querySelector('.item-image');
                                    if (match.picture) {
                                        imgBox.innerHTML = `<img src="../images/machine_images/${match.picture}" alt="IMG"><input type="hidden" class="i-pic" value="${match.picture}">`;
                                        imgBox.setAttribute('data-large-src', `../images/machine_images/${match.picture}`);
                                    } else {
                                        imgBox.innerHTML = `<span>NO IMG</span><input type="hidden" class="i-pic" value="">`;
                                        imgBox.setAttribute('data-large-src', '');
                                    }
                                    
                                    list.style.display = 'none';
                                    calculateTotals();
                                });
                                list.appendChild(div);
                            });
                        } else {
                            list.innerHTML = `<div class="autocomplete-no-results">No match found in database</div>`;
                        }
                        
                        list.style.display = 'block';
                    }
                });

                const modal = document.getElementById('imageModal');
                const modalImg = document.getElementById('img01');
                const spanClose = document.getElementsByClassName('modal-close')[0];

                itemsContainer.addEventListener('click', function(e) {
                    if (e.target.closest('.item-image')) {
                        const imgBox = e.target.closest('.item-image');
                        const largeSrc = imgBox.getAttribute('data-large-src');
                        if (largeSrc) {
                            modal.style.display = 'block';
                            modalImg.src = largeSrc;
                        }
                    }
                    if (e.target.closest('.btn-delete')) {
                        const row = e.target.closest('.item-row');
                        if (row) { 
                            row.style.opacity = '0';
                            row.style.transform = 'translateY(10px)';
                            setTimeout(() => {
                                row.remove();
                                calculateTotals();
                            }, 200); 
                        }
                    }
                });

                document.addEventListener('click', function(e) {
                    if (!e.target.closest('.item-details')) {
                        document.querySelectorAll('.autocomplete-list').forEach(l => l.style.display = 'none');
                    }
                });

                if (spanClose) spanClose.onclick = () => { modal.style.display = 'none'; };
                if (modal) modal.onclick = (e) => { if (e.target === modal) { modal.style.display = 'none'; } };
            }

            const btnAddItem = document.getElementById('btnAddItem');
            if (btnAddItem) {
                btnAddItem.addEventListener('click', function() {
                    const newRow = document.createElement('div');
                    newRow.className = 'item-row';
                    newRow.style.opacity = '0';
                    
                    const currentMark = 'ADD' + addCounter;
                    addCounter++;
                    
                    newRow.innerHTML = `
                        <div class="item-mark">
                            <span class="mark-badge">${currentMark}</span>
                            <input type="hidden" class="i-mark" value="${currentMark}">
                        </div>
                        <div class="item-image" data-large-src="">
                            <span>NO IMG</span>
                            <input type="hidden" class="i-pic" value="">
                        </div>
                        <div class="item-details">
                            <span class="item-brand-text">PENDING...</span>
                            <input type="hidden" class="i-brand" value="">
                            
                            <div class="autocomplete-wrapper">
                                <input type="text" class="input-model-search is-searching" autocomplete="off" placeholder="Search Model...">
                                <div class="autocomplete-list"></div>
                            </div>
                            <input type="hidden" class="i-model" value="">
                            
                            <span class="item-desc-text">Search a model to populate description.</span>
                            <input type="hidden" class="i-full-desc" value="">
                        </div>
                        <div class="item-metrics">
                            <div class="metric-group">
                                <label>QTY</label>
                                <input type="number" class="input-qty-edit i-qty" value="1" min="1" max="999">
                            </div>
                            <div class="metric-group">
                                <label>PRICE</label>
                                <span class="metric-value-text">0.00</span>
                                <input type="hidden" class="i-price" value="0.00">
                            </div>
                        </div>
                        <button type="button" class="btn-delete" title="Remove Item">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                        </button>
                    `;
                    
                    itemsContainer.appendChild(newRow);
                    setTimeout(() => {
                        newRow.style.opacity = '1';
                        newRow.querySelector('.input-model-search').focus();
                    }, 50);
                });
            }

            const projectForm = document.getElementById('projectForm');
            if (projectForm) {
                projectForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const items = [];
                    document.querySelectorAll('.item-row').forEach(row => {
                        const markInput = row.querySelector('.i-mark');
                        const modelInput = row.querySelector('.i-model');
                        
                        if(markInput && modelInput.value.trim() !== '') {
                            items.push({
                                mark: markInput.value,
                                brand: row.querySelector('.i-brand').value,
                                model: modelInput.value,
                                description: row.querySelector('.i-full-desc').value,
                                picture: row.querySelector('.i-pic').value,
                                qty: row.querySelector('.i-qty').value,
                                unit_price: row.querySelector('.i-price').value
                            });
                        }
                    });
                    document.getElementById('items_json').value = JSON.stringify(items);
                    
                    document.querySelectorAll('input.i-qty, input.i-price, input.i-mark, input.i-brand, input.i-model, input.i-full-desc, input.i-pic, .input-model-search').forEach(el => el.removeAttribute('name'));
                    
                    this.submit();
                });
            }
        });
    </script>   
</body>
</html>





