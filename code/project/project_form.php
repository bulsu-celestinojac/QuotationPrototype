<?php
// Look UP one level to root
require_once '../auth.php';
require_role(['project', 'admin', 'super_admin']); // THE LOCK

require '../db.php';
// ... rest of your code ...

$extracted_json = $_POST['extracted_json'] ?? '';
$incoming_items = [];

if (!empty($extracted_json)) {
    $incoming_items = json_decode($extracted_json, true);
} elseif (!empty($_POST['items'])) {
    $incoming_items = $_POST['items'];
}

if (empty($incoming_items)) {
    // Redirects to local parser
    header("Location: parser.php");
    exit;
}

// FUZZY AUTO-MATCH
foreach ($incoming_items as &$item) {
    $clean_incoming_model = preg_replace('/[\s\-]/', '', $item['model']);
    
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

$maxAdd = 0;
try {
    $stmtAdd = $pdo->query("SELECT mark FROM project_quotation_items WHERE mark LIKE 'ADD%'");
    if ($stmtAdd) {
        $addMarks = $stmtAdd->fetchAll(PDO::FETCH_COLUMN);
        foreach ($addMarks as $m) {
            $num = (int)str_ireplace('ADD', '', $m);
            if ($num > $maxAdd) {
                $maxAdd = $num;
            }
        }
    }
} catch (Exception $e) {}

foreach ($incoming_items as $item) {
    $markText = trim((string)($item['mark'] ?? ''));
    if (strpos(strtoupper($markText), 'ADD') === 0) {
        $num = (int)str_ireplace('ADD', '', $markText);
        if ($num > $maxAdd) {
            $maxAdd = $num;
        }
    }
}
$nextAddCounter = $maxAdd + 1;

$clients = [];
$clean_inventory = [];

try {
    $stmtClients = $pdo->query("SELECT company_name, email, client_address, contact_no FROM clients");
    if ($stmtClients) $clients = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    $stmtItems = $pdo->query("SELECT brand, model_no, description, selling_price, picture FROM items");
    if ($stmtItems) {
        $raw_inventory = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw_inventory as $row) {
            $clean_inventory[] = [
                'brand' => trim((string)($row['brand'] ?? '')),
                'model_no' => trim((string)($row['model_no'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
                'selling_price' => (float)($row['selling_price'] ?? 0),
                'picture' => trim((string)($row['picture'] ?? ''))
            ];
        }
    }
} catch (Exception $e) {}

$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

$clients_json_safe = json_encode($clients ?: [], $json_flags) ?: '[]';
$inventory_json_safe = json_encode($clean_inventory ?: [], $json_flags) ?: '[]';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Quote Builder - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0"> 
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
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
        
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); line-height: 1.5; padding: 30px 20px; overflow-x: hidden; min-height: 100vh; }
        .container { width: 100%; max-width: 1400px; margin: 0 auto; overflow-x: hidden; } 

        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 20px; flex-wrap: wrap; gap: 16px; }
        h1 { font-family: 'Outfit', sans-serif; font-size: clamp(2rem, 4vw, 2.75rem); font-weight: 900; margin: 0; letter-spacing: -0.02em; color: var(--text-main); line-height: 1; text-transform: uppercase; }
        h1 span { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.2s ease; display: inline-block; }
        .btn-back:hover { color: var(--maroon); }

        .stepper-container { display: flex; align-items: center; justify-content: center; margin-bottom: 40px; gap: 16px; padding: 0 20px; }
        .step-indicator { display: flex; align-items: center; gap: 12px; color: var(--text-light); transition: all 0.3s ease; }
        .step-indicator.active { color: var(--maroon); }
        .step-indicator.completed { color: #18181B; } 
        .step-circle { width: 40px; height: 40px; border-radius: 50%; background: var(--surface); border: 2px solid currentColor; display: flex; align-items: center; justify-content: center; font-weight: 800; font-family: 'Outfit', sans-serif; font-size: 1.1rem; transition: all 0.3s ease; }
        .step-indicator.active .step-circle { background: var(--maroon); color: white; border-color: var(--maroon); box-shadow: 0 4px 12px rgba(139,21,56,0.2); }
        .step-indicator.completed .step-circle { background: #18181B; color: white; border-color: #18181B; }
        .step-label { font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.9rem; }
        .step-connector { flex: 1; max-width: 150px; height: 2px; background: var(--border); transition: all 0.3s ease; }
        .step-connector.active { background: #18181B; }

        .step-section { animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

        .step-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border); }
        .step-actions.right { justify-content: flex-end; }

        .card { background: var(--surface); border-radius: 20px; padding: 40px; border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 10px 40px rgba(0,0,0,0.03); width: 100%; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full { grid-column: 1/-1; }
        label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.08em; display: block; margin-bottom: 6px; }
        
        input[type="text"], input[type="date"], input[type="number"], input[type="tel"], input[type="email"], select, textarea { 
            width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid transparent; background: var(--input-bg); font-family: 'DM Sans', sans-serif; font-size: 0.95rem; color: var(--text-main); font-weight: 500; transition: all 0.3s ease; outline: none; box-sizing: border-box;
        }
        input:focus, textarea:focus, select:focus { background: var(--surface); border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); }
        input::placeholder, textarea::placeholder { color: var(--text-light); font-weight: 400; }
        .readonly-input { background: transparent !important; border: 1px solid var(--border) !important; color: var(--text-muted) !important; pointer-events: none; }
        hr { border: none; border-top: 1px solid var(--border); margin: 8px 0; }

        label.required::after {
            content: ' *';
            color: var(--maroon);
            font-weight: 900;
            font-size: 0.8rem;
        }

        input:invalid:not(:placeholder-shown):not(:focus),
        textarea:invalid:not(:placeholder-shown):not(:focus),
        select:invalid.touched {
            border-color: #EF4444 !important;
            background-color: #FEF2F2 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }

        .financial-summary-block { background: #FAFAF9; padding: 32px; border-radius: 16px; border: 1px dashed var(--border); margin-top: 32px; box-shadow: inset 0 2px 10px rgba(0,0,0,0.01); }
        .summary-row { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; }
        .summary-row.total-row { border-top: 2px dashed var(--border); padding-top: 24px; margin-top: 24px; margin-bottom: 0; }
        .input-discount { width: 150px !important; text-align: right; padding: 12px 16px !important; border: 1px solid var(--border) !important; border-radius: 10px !important; font-family: 'DM Sans', sans-serif; font-weight: 700; font-size: 1.05rem; color: var(--maroon) !important; background: var(--surface) !important; transition: 0.2s; margin: 0 !important; }
        .input-discount:focus { border-color: var(--maroon) !important; box-shadow: 0 0 0 4px var(--maroon-light) !important; }

        .btn { padding: 16px 36px; border-radius: 50px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; border: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn-primary { background: var(--maroon); color: white; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .btn-primary:hover { background: #6A0D28; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(139, 21, 56, 0.3); }
        .btn-secondary { background: var(--surface); color: var(--text-main); border: 1px solid var(--border); box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .btn-secondary:hover { border-color: var(--text-main); }
        .btn-dashed { background: transparent; border: 2px dashed #E4E4E7; color: #71717A; border-radius: 16px; font-weight: 800; font-size: 0.9rem; padding: 20px; transition: all 0.2s ease; margin-bottom: 0; width: 100%; box-sizing: border-box; }
        .btn-dashed:hover { background: var(--surface); border-color: var(--text-main); color: var(--text-main); }

        .items-list-container { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.02); margin-bottom: 24px; width: 100%; box-sizing: border-box; }
        .list-header { display: grid; grid-template-columns: 100px 70px 1fr 280px 40px; gap: 32px; padding: 0 32px 16px 32px; border-bottom: 2px solid var(--border); margin-bottom: 12px; color: var(--text-light); font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; align-items: center; }
        .header-metrics { display: grid; grid-template-columns: 80px 1fr; gap: 24px; padding-left: 32px; }
        .items-list { display: flex; flex-direction: column; width: 100%; }
        
        .item-row { display: grid; grid-template-columns: 100px 70px 1fr 280px 40px; gap: 32px; padding: 20px 32px; align-items: center; position: relative; transition: all 0.2s ease; border-bottom: 1px solid var(--border); }
        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: #FAFAF9; }

        .item-mark { display: flex; align-items: center; justify-content: center; width: 100%; }
        .mark-badge { background: var(--maroon-light); color: var(--maroon); font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 0.95rem; padding: 8px 10px; border-radius: 8px; text-align: center; width: 100%; white-space: nowrap; box-sizing: border-box; }
        
        .item-image { width: 70px; height: 70px; border-radius: 10px; border: 1px solid var(--border); background: #FFF; display: flex; align-items: center; justify-content: center; padding: 4px; cursor: pointer; transition: all 0.3s ease; }
        .item-image:hover { border-color: var(--maroon); box-shadow: 0 4px 12px var(--maroon-light); }
        .item-image img { width: 100%; height: 100%; object-fit: contain; }
        .item-image span { font-size: 0.5rem; color: var(--text-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }

        .item-details { display: flex; flex-direction: column; justify-content: center; min-width: 0; width: 100%; }
        .item-brand-text { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-light); font-weight: 800; margin-bottom: 2px; }
        
        .item-desc-text { 
            font-size: 0.85rem; color: var(--text-muted); 
            display: -webkit-box; -webkit-line-clamp: 1; line-clamp: 1;
            -webkit-box-orient: vertical; overflow: hidden; 
        }

        .item-details input[type="text"].input-model-search { font-family: 'Outfit', sans-serif !important; font-size: 1.35rem !important; font-weight: 900 !important; color: var(--text-main) !important; background: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; margin-bottom: 4px !important; outline: none !important; width: 100% !important; box-shadow: none !important; line-height: 1.2 !important; }
        .item-details input[type="text"].input-model-search::placeholder { color: var(--text-light); font-weight: 400; }
        .item-details input[type="text"].input-model-search.is-searching { border-bottom: 2px dashed var(--border) !important; }
        
        .badge-warning { background: #FEF2F2; color: #EF4444; font-size: 0.6rem; font-weight: 800; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; margin-bottom: 6px; align-self: flex-start; }

        .item-metrics { display: grid; grid-template-columns: 80px 1fr; gap: 24px; align-items: center; border-left: 1px dashed var(--border); padding-left: 32px; }
        
        .input-qty-edit { 
            width: 100%; text-align: center; padding: 10px 4px; background: #FAFAF9 !important; border: 1px solid var(--border) !important; border-radius: 8px !important; font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin: 0; transition: all 0.2s ease; box-sizing: border-box;
        }
        .input-qty-edit:focus { border-color: var(--maroon) !important; background: var(--surface) !important; box-shadow: 0 0 0 3px var(--maroon-light) !important; }

        .input-qty-edit::-webkit-outer-spin-button, .input-qty-edit::-webkit-inner-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        .input-qty-edit[type=number] { -moz-appearance: textfield; appearance: textfield; }

        .price-edit-wrapper { display: flex; align-items: center; background: #FFF5F7; border: 1px solid rgba(139, 21, 56, 0.15); border-radius: 8px; padding: 0 12px; transition: all 0.2s ease; width: 140px; box-sizing: border-box; }
        .price-edit-wrapper:focus-within { border-color: var(--maroon); box-shadow: 0 0 0 3px var(--maroon-light); background: var(--surface); }
        .price-currency { font-weight: 800; color: var(--maroon); font-size: 1.05rem; }
        
        .input-price-edit { width: 100%; text-align: right; padding: 10px 0 10px 8px; background: transparent !important; border: none !important; font-size: 1.05rem; font-weight: 800; color: var(--maroon) !important; outline: none !important; box-shadow: none !important; font-family: 'DM Sans', sans-serif; margin: 0; box-sizing: border-box; }
        
        .btn-delete { background: transparent; border: none; color: var(--text-light); font-size: 1.4rem; cursor: pointer; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 10px; transition: all 0.2s ease; }
        .btn-delete:hover { background: #FFF5F7; color: var(--maroon); }

        .autocomplete-wrapper { position: relative; width: 100%; z-index: 10; }
        .autocomplete-list { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; max-height: 300px; overflow-y: auto; display: none; margin-top: 4px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); z-index: 9999; }
        .autocomplete-item { padding: 14px 20px; cursor: pointer; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .autocomplete-item:hover, .autocomplete-item.active-sugg { background: var(--maroon-light); }
        .autocomplete-model { font-weight: 800; font-family: 'Outfit', sans-serif; color: var(--text-main); font-size: 1.1rem;}
        .autocomplete-brand { font-size: 0.7rem; color: var(--maroon); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .autocomplete-no-results { padding: 14px 20px; color: var(--text-muted); font-size: 0.9rem; font-style: italic; text-align: center; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.85); backdrop-filter: blur(8px); }
        .modal-content { margin: auto; display: block; max-width: 85%; max-height: 85%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); border-radius: 16px; }
        .modal-close { position: absolute; top: 30px; right: 40px; color: rgba(255,255,255,0.6); font-size: 40px; font-weight: 300; cursor: pointer; }
        .modal-close:hover { color: #FFF; }

        #step-2 .card { max-width: 1000px; margin: 0 auto; }

        @media (max-width: 1024px) {
            .list-header { display: none; } 
            .item-row { grid-template-columns: 1fr; padding: 24px; gap: 16px; border: 1px solid var(--border); margin-bottom: 16px; border-radius: 16px; }
            .item-mark { width: auto; display: inline-block; margin-bottom: 8px; }
            .item-image { margin: 0 auto 16px auto; width: 120px; height: 120px; justify-content: center; }
            .item-metrics { grid-template-columns: 1fr 1fr; border-left: none; border-top: 1px dashed var(--border); padding-left: 0; padding-top: 16px; margin-top: 8px; }
            .btn-delete { position: absolute; top: 16px; right: 16px; }
            .form-grid { grid-template-columns: 1fr; } 
        }

        @media (max-width: 768px) {
            body { padding: 20px 16px; overflow-x: hidden; }
            .container { max-width: 100vw; overflow-x: hidden; box-sizing: border-box; }
            .items-list-container { padding: 16px; border-radius: 16px; }
            .item-row { padding: 16px; gap: 12px; }
            .item-metrics { grid-template-columns: 1fr; gap: 12px; }
            .price-edit-wrapper { width: 100%; justify-content: space-between; padding: 4px 16px; }
            .input-price-edit { text-align: right; }
            .btn-delete { position: relative; top: auto; right: auto; width: 100%; margin-top: 16px; background: #FFF5F7; color: var(--maroon); border: 1px dashed rgba(139, 21, 56, 0.3); }
            .card { padding: 24px 16px; }
            .financial-summary-block { padding: 20px; }
            .step-actions { flex-direction: column-reverse; gap: 16px; }
            .step-actions .btn { width: 100%; }
        }
    </style>
</head>
<body>

    <script>
        const clientsData = <?= $clients_json_safe ?>;
        const inventoryData = <?= $inventory_json_safe ?>;
    </script>

    <div class="container">
        
        <div class="page-header">
            <h1>PROJECT <span>QUOTATION</span></h1>
            <a href="parser.php" class="btn-back">← Back to Parser</a>
        </div>

        <div class="stepper-container">
            <div class="step-indicator active" id="ind-1">
                <div class="step-circle">1</div>
                <div class="step-label">Review Equipment</div>
            </div>
            <div class="step-connector" id="conn-1"></div>
            <div class="step-indicator" id="ind-2">
                <div class="step-circle">2</div>
                <div class="step-label">Project Details</div>
            </div>
        </div>

        <form action="project_process.php" method="POST" id="projectForm" autocomplete="off" novalidate>
            <input type="hidden" name="quote_type" value="project">
            <input type="hidden" name="items_json" id="items_json" value="">
            
            <div id="step-1" class="step-section">
                <div class="items-list-container">
                    
                    <div class="list-header">
                        <div style="text-align: center;">Mark</div>
                        <div>Image</div>
                        <div>Equipment Details</div>
                        <div class="header-metrics">
                            <span style="text-align: center;">Qty</span>
                            <span>Unit Price (₱)</span>
                        </div>
                        <div></div>
                    </div>

                    <div class="items-list" id="items-container">
                        <?php foreach ($incoming_items as $index => $item): 
                            $first_line_desc = explode("\n", str_replace("\r", "", $item['full_desc']))[0];
                            $formatted_price = number_format((float)($item['price'] ?? 0), 2, '.', ',');
                        ?>
                            <div class="item-row">
                                <div class="item-mark">
                                    <span class="mark-badge"><?= htmlspecialchars($item['mark']) ?></span>
                                    <input type="hidden" class="i-mark" value="<?= htmlspecialchars($item['mark']) ?>">
                                </div>
                                
                                <div class="item-image" data-large-src="<?= !empty($item['picture']) ? '../../images/machine_images/' . htmlspecialchars($item['picture']) : '' ?>">
                                    <?php if (!empty($item['picture'])): ?>
                                        <img src="../../images/machine_images/<?= htmlspecialchars($item['picture']) ?>" alt="IMG">
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
                                    
                                    <div class="autocomplete-wrapper">
                                        <input type="text" class="input-model-search" value="<?= htmlspecialchars($item['model']) ?>" autocomplete="new-password" placeholder="Search Model...">
                                        <div class="autocomplete-list"></div>
                                    </div>
                                    <input type="hidden" class="i-model" value="<?= htmlspecialchars($item['model']) ?>">
                                    
                                    <span class="item-desc-text" title="<?= htmlspecialchars($item['full_desc']) ?>"><?= htmlspecialchars($first_line_desc) ?></span>
                                    <input type="hidden" class="i-full-desc" value="<?= htmlspecialchars($item['full_desc']) ?>">
                                </div>

                                <div class="item-metrics">
                                    <input type="number" class="input-qty-edit i-qty" value="<?= $item['qty'] ?? 1 ?>" min="1" max="999" autocomplete="off">
                                    <div class="price-edit-wrapper">
                                        <span class="price-currency">₱</span>
                                        <input type="text" class="input-price-edit i-price update-db-price" value="<?= $formatted_price ?>" data-model="<?= htmlspecialchars($item['model']) ?>" autocomplete="off">
                                    </div>
                                </div>

                                <button type="button" class="btn-delete" title="Remove Item">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                                    <span style="display:none;" class="mobile-delete-text">Remove Item</span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="button" id="btnAddItem" class="btn btn-dashed">+ ADD ADDITIONAL ITEM</button>

                <div class="step-actions right">
                    <button type="button" class="btn btn-primary" onclick="goToStep(2)">Next: Project Details →</button>
                </div>
            </div>

            <div id="step-2" class="step-section" style="display: none;">
                <div class="card project-details-card">
                    <div class="form-grid">
                        <div class="full">
                            <label class="required">Company Name</label>
                            <input type="text" name="company_name" id="company_name" list="company_list" style="text-transform: uppercase;" autocomplete="new-password" placeholder="Enter company name" required>
                            <datalist id="company_list"></datalist>
                        </div>
                        <div class="full">
                            <label class="required">Project Name</label>
                            <input type="text" name="project_name" value="" style="text-transform: uppercase;" autocomplete="new-password" placeholder="Enter project name" required>
                        </div>
                        <div>
                            <label class="required">Contact Person</label>
                            <input type="text" name="contact_name" id="contact_name" style="text-transform: uppercase;" autocomplete="new-password" placeholder="Full Name" required>
                        </div>
                        <div>
                            <label class="required">Contact No.</label>
                            <input type="tel" name="contact_no" id="contact_no" pattern="^(09|\+639)\d{9}$|^[0-9]{2,3}[-\s]?[0-9]{7}$" title="Please enter a valid PH mobile number (e.g. 09171234567) or landline." autocomplete="new-password" placeholder="e.g. 09171234567" required>
                        </div>
                        <div class="full">
                            <label class="required">Email Address</label>
                            <input type="email" name="email" id="email" autocomplete="new-password" placeholder="example@domain.com" required>
                        </div>
                        <div class="full">
                            <label class="required">Complete Address</label>
                            <textarea name="client_address" id="client_address" rows="2" autocomplete="off" placeholder="Enter full delivery/billing address" required></textarea>
                        </div>
                        
                        <div class="full"><hr></div>
                        
                        <div>
                            <label class="required">Offer No.</label>
                            <input type="text" name="quotation_no" class="readonly-input" value="<?= $default_quote_num ?>" readonly tabindex="-1" required>
                        </div>
                        <div>
                            <label class="required">Date</label>
                            <input type="date" name="quote_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label class="required">Offer Validity</label>
                            <input type="text" name="offer_validity" value="" autocomplete="new-password" placeholder="e.g. 30 Days" required>
                        </div>
                        <div>
                            <label class="required">Mode of Dispatch</label>
                            <select name="mode_of_dispatch" required onchange="this.classList.add('touched')">
                                <option value="" disabled selected>Select mode</option>
                                <option value="Air">Air</option>
                                <option value="Land">Land</option>
                                <option value="Shipment">Shipment</option>
                                <option value="To Agree">To Agree</option>
                            </select>
                        </div>
                        <div class="full">
                            <label class="required">Package</label>
                            <input type="text" name="package_type" value="" autocomplete="new-password" placeholder="e.g. Standard Crating / To Agree" required>
                        </div>
                        <div class="full">
                            <label class="required">Delivery Arrangements</label>
                            <input type="text" name="delivery_arrangements" value="" autocomplete="new-password" placeholder="e.g. Delivered to site / Ex-works" required>
                        </div>
                        <div class="full">
                            <label class="required">Payment Terms</label>
                            <textarea name="payment_terms" rows="3" autocomplete="off" placeholder="e.g. 50% Downpayment upon confirmation, 50% Before dispatch." required></textarea>
                        </div>
                        <div class="full">
                            <label>Inclusions</label>
                            <textarea name="inclusions" rows="2" autocomplete="off" placeholder="Optional details (e.g. Installation, 1 Year Warranty...)"></textarea>
                        </div>
                        
                        <div>
                            <label class="required">Paper Size</label>
                            <select name="paper_size" required>
                                <option value="A4" selected>A4 (Standard)</option>
                                <option value="A3">A3 (Large Format)</option>
                            </select>
                        </div>
                        <div class="full">
                            <label class="required">Prepared By</label>
                            <input type="text" name="prepared_by" style="text-transform: uppercase;" autocomplete="new-password" placeholder="Your Full Name" required>
                        </div>
                    </div>

                    <div class="financial-summary-block">
                        <div class="summary-row" style="margin-bottom: 16px;">
                            <span style="font-size: 0.95rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Subtotal:</span>
                            <span class="summary-value" id="display-subtotal" style="font-size: 1.25rem; font-weight: 700; color: var(--text-main);">₱0.00</span>
                        </div>
                        <div class="summary-row">
                            <span style="font-size: 0.95rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Discount (₱):</span>
                            <input type="number" step="0.01" name="discount_amount" id="discount_amount" value="0" class="input-discount" autocomplete="off">
                        </div>
                        <div class="summary-row total-row">
                            <span style="font-size: 1.15rem; font-weight: 800; color: var(--text-main); text-transform: uppercase;">Total Net Amount:</span>
                            <span id="display-total" style="font-size: 2.2rem; color: var(--maroon); font-weight: 900; font-family: 'Outfit', sans-serif;">₱0.00</span>
                        </div>
                    </div>
                </div>

                <div class="step-actions">
                    <button type="button" class="btn btn-secondary" onclick="goToStep(1)">← Back to Equipment</button>
                    <button type="submit" class="btn btn-primary">Generate Project PDF</button>
                </div>
            </div>

        </form>
    </div>

    <div id="imageModal" class="modal">
        <span class="modal-close">&times;</span>
        <img class="modal-content" id="img01">
    </div>

    <script>
        function goToStep(step) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            if (step === 1) {
                document.getElementById('step-1').style.display = 'block';
                document.getElementById('step-2').style.display = 'none';
                
                document.getElementById('ind-1').classList.add('active');
                document.getElementById('ind-1').classList.remove('completed');
                document.getElementById('ind-2').classList.remove('active');
                document.getElementById('conn-1').classList.remove('active');
            } else {
                document.getElementById('step-1').style.display = 'none';
                document.getElementById('step-2').style.display = 'block';
                
                document.getElementById('ind-1').classList.remove('active');
                document.getElementById('ind-1').classList.add('completed');
                document.getElementById('ind-2').classList.add('active');
                document.getElementById('conn-1').classList.add('active');
                
                calculateTotals(); 
            }
        }

        document.getElementById('projectForm').addEventListener('submit', function(e) {
            if (!this.checkValidity()) {
                e.preventDefault(); 
                this.reportValidity(); 
                goToStep(2); 

                this.querySelectorAll('select:invalid').forEach(field => {
                    field.classList.add('touched');
                });
            } else {
                const items = [];
                document.querySelectorAll('.item-row').forEach(row => {
                    const markInput = row.querySelector('.i-mark');
                    const modelInput = row.querySelector('.i-model');
                    
                    if(markInput && modelInput.value.trim() !== '') {
                        const priceStr = row.querySelector('.i-price').value;
                        const cleanPrice = parseFloat(String(priceStr).replace(/,/g, '')) || 0;

                        items.push({
                            mark: markInput.value,
                            brand: row.querySelector('.i-brand').value,
                            model: modelInput.value,
                            description: row.querySelector('.i-full-desc').value,
                            picture: row.querySelector('.i-pic').value,
                            qty: row.querySelector('.i-qty').value,
                            unit_price: cleanPrice
                        });
                    }
                });
                document.getElementById('items_json').value = JSON.stringify(items);
                
                document.querySelectorAll('input.i-qty, input.i-price, input.i-mark, input.i-brand, input.i-model, input.i-full-desc, input.i-pic, .input-model-search').forEach(el => el.removeAttribute('name'));
            }
        });

        function calculateTotals() {
            let subtotal = 0;
            const rowData = [];
            
            document.querySelectorAll('.item-row').forEach(row => {
                const priceStr = row.querySelector('.i-price').value;
                const cleanPrice = parseFloat(String(priceStr).replace(/,/g, '')) || 0;
                
                rowData.push({
                    qty: parseFloat(row.querySelector('.i-qty').value) || 0,
                    price: cleanPrice
                });
            });

            const discount = parseFloat(document.getElementById('discount_amount').value) || 0;

            rowData.forEach(data => {
                subtotal += (data.qty * data.price);
            });
            const total = Math.max(0, subtotal - discount);

            document.getElementById('display-subtotal').textContent = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('display-total').textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        document.addEventListener('DOMContentLoaded', function() {
            
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

            let addCounter = <?= $nextAddCounter ?>;
            const itemsContainer = document.getElementById('items-container');
            
            function applyInventoryMatch(row, match, input, forceValue = false) {
                if (input && forceValue) {
                    input.value = match.model_no;
                }
                if (input) input.classList.remove('is-searching');
                
                row.querySelector('.i-model').value = match.model_no;
                row.querySelector('.i-brand').value = match.brand || '';
                row.querySelector('.i-full-desc').value = match.description || '';
                
                const priceInput = row.querySelector('.i-price');
                const matchedPrice = parseFloat(match.selling_price) || 0;
                priceInput.value = matchedPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                priceInput.setAttribute('data-model', match.model_no || '');
                
                row.querySelector('.item-brand-text').textContent = match.brand || 'NO BRAND';
                
                const warningBadge = row.querySelector('.badge-warning');
                if(warningBadge) warningBadge.style.display = 'none';

                let firstLine = (match.description || '').split(/\r?\n/)[0];
                row.querySelector('.item-desc-text').textContent = firstLine || 'No description available.';
                
                const imgBox = row.querySelector('.item-image');
                if (match.picture) {
                    // UP TWO LEVELS NOW
                    imgBox.innerHTML = `<img src="../../images/machine_images/${match.picture}" alt="IMG"><input type="hidden" class="i-pic" value="${match.picture}">`;
                    imgBox.setAttribute('data-large-src', `../../images/machine_images/${match.picture}`);
                } else {
                    imgBox.innerHTML = `<span>NO IMG</span><input type="hidden" class="i-pic" value="">`;
                    imgBox.setAttribute('data-large-src', '');
                }
                calculateTotals();
            }

            function clearInventoryMatch(row, typedValue) {
                row.querySelector('.i-model').value = typedValue;
                row.querySelector('.i-brand').value = '';
                row.querySelector('.i-full-desc').value = '';
                
                const priceInput = row.querySelector('.i-price');
                priceInput.value = '0.00';
                priceInput.setAttribute('data-model', typedValue);
                
                row.querySelector('.item-brand-text').textContent = 'PENDING...';
                row.querySelector('.item-desc-text').textContent = 'Search a model to populate description.';
                
                const imgBox = row.querySelector('.item-image');
                imgBox.innerHTML = `<span>NO IMG</span><input type="hidden" class="i-pic" value="">`;
                imgBox.setAttribute('data-large-src', '');
                
                const warningBadge = row.querySelector('.badge-warning');
                if(warningBadge) warningBadge.style.display = 'inline-block';
                
                calculateTotals();
            }

            if (itemsContainer) {
                
                itemsContainer.addEventListener('change', function(e) {
                    if (e.target.classList.contains('update-db-price')) {
                        const input = e.target;
                        const cleanPrice = parseFloat(String(input.value).replace(/,/g, '')) || 0;
                        const row = input.closest('.item-row');
                        const modelNo = row.querySelector('.i-model').value;
                        
                        if (modelNo && modelNo.trim() !== '') {
                            const wrapper = input.closest('.price-edit-wrapper');
                            wrapper.style.opacity = '0.5';
                            
                            // USING LOCAL ENDPOINT IN PROJECT FOLDER
                            fetch('update_item_price.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `model_no=${encodeURIComponent(modelNo)}&selling_price=${encodeURIComponent(cleanPrice)}`
                            })
                            .then(response => response.json())
                            .then(data => {
                                wrapper.style.opacity = '1';
                                if (data.success) {
                                    wrapper.style.backgroundColor = '#ecfdf5';
                                    wrapper.style.borderColor = '#10b981';
                                    setTimeout(() => { 
                                        wrapper.style.backgroundColor = ''; 
                                        wrapper.style.borderColor = ''; 
                                    }, 1000);
                                }
                            })
                            .catch(err => {
                                wrapper.style.opacity = '1';
                                console.error('Fetch error:', err);
                            });
                        }
                    }
                });
                
                itemsContainer.addEventListener('input', function(e) {
                    if (e.target.classList.contains('i-qty') || e.target.classList.contains('i-price')) {
                        calculateTotals();
                    }
                });

                const performSearch = debounce(function(input) {
                    const val = input.value.trim().toUpperCase();
                    const row = input.closest('.item-row');
                    const list = row.querySelector('.autocomplete-list');
                    
                    if (!list) return;

                    if (val.length < 1) {
                        list.style.display = 'none';
                        clearInventoryMatch(row, val);
                        input.classList.remove('is-searching');
                        return;
                    }
                    
                    const cleanVal = val.replace(/[\s\-]/g, '');

                    const exactMatch = inventoryData.find(i => {
                        const safeModel = String(i.model_no || '').toUpperCase();
                        return safeModel === val || safeModel.replace(/[\s\-]/g, '') === cleanVal;
                    });

                    if (exactMatch) {
                        applyInventoryMatch(row, exactMatch, input, false); 
                        list.style.display = 'none';
                        input.classList.remove('is-searching');
                        return;
                    } else {
                        clearInventoryMatch(row, val); 
                    }

                    const matches = inventoryData.filter(i => {
                        const safeModel = String(i.model_no || '').toUpperCase();
                        const safeBrand = String(i.brand || '').toUpperCase();
                        const cleanModel = safeModel.replace(/[\s\-]/g, '');
                        return cleanModel.includes(cleanVal) || cleanVal.includes(cleanModel) || safeBrand.includes(val) || val.includes(safeBrand) || safeModel.includes(val) || val.includes(safeModel);
                    });
                    
                    list.innerHTML = '';
                    
                    if (matches.length > 0) {
                        const frag = document.createDocumentFragment();
                        matches.slice(0, 15).forEach(match => { 
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.innerHTML = `
                                <div style="display:flex; flex-direction:column; pointer-events:none;">
                                    <span class="autocomplete-model">${match.model_no}</span>
                                    <span class="autocomplete-brand">${match.brand || 'NO BRAND'}</span>
                                </div>
                            `;
                            div.addEventListener('mousedown', function(evt) {
                                evt.preventDefault();
                                applyInventoryMatch(row, match, input, true); 
                                list.style.display = 'none';
                            });
                            frag.appendChild(div);
                        });
                        list.appendChild(frag);
                    } else {
                        list.innerHTML = `<div class="autocomplete-no-results">No match found in database</div>`;
                    }
                    
                    list.style.display = 'block';
                }, 150);

                itemsContainer.addEventListener('input', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        e.target.classList.add('is-searching');
                        performSearch(e.target);
                    }
                });

                itemsContainer.addEventListener('keydown', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        const input = e.target;
                        const row = input.closest('.item-row');
                        const list = row.querySelector('.autocomplete-list');
                        
                        if (!list || list.style.display === 'none') {
                            if (e.key === 'Enter') e.preventDefault();
                            return;
                        }

                        const items = list.querySelectorAll('.autocomplete-item');
                        if (items.length === 0) return;

                        let currentIndex = Array.from(items).findIndex(item => item.classList.contains('active-sugg'));

                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            if (currentIndex < items.length - 1) {
                                if (currentIndex >= 0) items[currentIndex].classList.remove('active-sugg');
                                items[currentIndex + 1].classList.add('active-sugg');
                                items[currentIndex + 1].scrollIntoView({ block: 'nearest' });
                            }
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            if (currentIndex > 0) {
                                items[currentIndex].classList.remove('active-sugg');
                                items[currentIndex - 1].classList.add('active-sugg');
                                items[currentIndex - 1].scrollIntoView({ block: 'nearest' });
                            }
                        } else if (e.key === 'Enter') {
                            e.preventDefault();
                            if (currentIndex >= 0) {
                                items[currentIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                            }
                        }
                    }
                });

                itemsContainer.addEventListener('focusin', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        const row = e.target.closest('.item-row');
                        if (row) row.style.zIndex = '50';
                    }
                    if (e.target.classList.contains('i-price')) {
                        const cleanVal = String(e.target.value).replace(/,/g, '');
                        e.target.value = cleanVal === '0.00' ? '' : cleanVal; 
                    }
                });
                
                itemsContainer.addEventListener('focusout', function(e) {
                    if (e.target.classList.contains('input-model-search')) {
                        const row = e.target.closest('.item-row');
                        if (row) row.style.zIndex = '1';
                        
                        const list = row.querySelector('.autocomplete-list');
                        if(list) list.style.display = 'none';
                        e.target.classList.remove('is-searching');
                    }
                    if (e.target.classList.contains('i-price')) {
                        const parsed = parseFloat(String(e.target.value).replace(/,/g, '')) || 0;
                        e.target.value = parsed.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
                            <span class="badge-warning">Not In Inventory</span>
                            <span class="item-brand-text">PENDING...</span>
                            <input type="hidden" class="i-brand" value="">
                            
                            <div class="autocomplete-wrapper">
                                <input type="text" class="input-model-search is-searching" autocomplete="new-password" placeholder="Search Model...">
                                <div class="autocomplete-list"></div>
                            </div>
                            <input type="hidden" class="i-model" value="">
                            
                            <span class="item-desc-text">Search a model to populate description.</span>
                            <input type="hidden" class="i-full-desc" value="">
                        </div>
                        
                        <div class="item-metrics">
                            <input type="number" class="input-qty-edit i-qty" value="1" min="1" max="999" autocomplete="off">
                            <div class="price-edit-wrapper">
                                <span class="price-currency">₱</span>
                                <input type="text" class="input-price-edit i-price update-db-price" value="0.00" data-model="" autocomplete="off">
                            </div>
                        </div>

                        <button type="button" class="btn-delete" title="Remove Item">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                            <span style="display:none;" class="mobile-delete-text">Remove Item</span>
                        </button>
                    `;
                    
                    itemsContainer.appendChild(newRow);
                    setTimeout(() => {
                        newRow.style.opacity = '1';
                        newRow.querySelector('.input-model-search').focus();
                    }, 50);
                });
            }
        });
    </script>   
</body>
</html>