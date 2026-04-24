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

// Auto-match models with inventory to fetch prices, descriptions, and pictures
foreach ($incoming_items as &$item) {
    $stmt = $pdo->prepare("SELECT id, selling_price, description, picture FROM items WHERE model_no = ? LIMIT 1");
    $stmt->execute([$item['model']]);
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
    
    // CRITICAL FIX: Explicitly query only the columns we know exist.
    $stmtItems = $pdo->query("SELECT model_no, description, selling_price, picture FROM items");
    if ($stmtItems) {
        $raw_inventory = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw_inventory as $row) {
            // CRITICAL FIX: Force convert to UTF-8 to prevent JSON from silently crashing on special characters
            $clean_inventory[] = [
                'model_no' => mb_convert_encoding((string)($row['model_no'] ?? ''), 'UTF-8', 'auto'),
                'description' => mb_convert_encoding((string)($row['description'] ?? ''), 'UTF-8', 'auto'),
                'selling_price' => $row['selling_price'] ?? 0,
                'picture' => mb_convert_encoding((string)($row['picture'] ?? ''), 'UTF-8', 'auto')
            ];
        }
    }
} catch (Exception $e) {}

// JSON_INVALID_UTF8_SUBSTITUTE protects against any remaining bad characters
$clients_json = json_encode($clients ?: []);
$inventory_json = json_encode($clean_inventory ?: [], JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Quote Builder - AM Group</title>
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

        body { font-family: 'DM Sans', sans-serif; background: var(--bg); padding: 40px; color: var(--text-main); line-height: 1.5; }
        .container { max-width: 1500px; margin: 0 auto; }

        /* Header & Back Button */
        .page-header { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 24px; }
        h1 { font-family: 'Outfit', sans-serif; font-size: 2.75rem; font-weight: 800; margin: 0; letter-spacing: -0.02em; color: var(--text-main); line-height: 1; }
        h1 span { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.2s ease; margin-bottom: 6px; display: inline-block; }
        .btn-back:hover { color: var(--maroon); }

        h3 { font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 700; margin-bottom: 24px; margin-top: 0; color: var(--text-main); }

        /* Layout Grid */
        .layout-grid { display: grid; grid-template-columns: 550px 1fr; gap: 40px; align-items: start; }
        .card { background: var(--surface); border-radius: 20px; padding: 32px; border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 10px 40px rgba(0,0,0,0.03); }
        
        /* Form Inputs (Left Column) */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full { grid-column: 1/-1; }
        label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--text-light); letter-spacing: 0.08em; display: block; margin-bottom: 6px; }
        input[type="text"], input[type="date"], input[type="number"], input[type="tel"], input[type="email"], select, textarea { 
            width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid transparent; background: var(--input-bg); font-family: 'DM Sans', sans-serif; font-size: 0.95rem; color: var(--text-main); font-weight: 500; transition: all 0.3s ease; outline: none; box-sizing: border-box;
        }
        input:focus, textarea:focus, select:focus { background: var(--surface); border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); }
        input::placeholder, textarea::placeholder { color: var(--text-light); font-weight: 400; }
        .readonly-input { background: transparent; border: 1px solid var(--border); color: var(--text-muted); pointer-events: none; }
        hr { border: none; border-top: 1px solid var(--border); margin: 10px 0; }

        /* Buttons */
        .btn { padding: 16px 32px; border-radius: 50px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; border: none; display: inline-flex; align-items: center; justify-content: center; width: 100%; }
        .btn-primary { background: var(--maroon); color: white; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .btn-primary:hover { background: #6A0D28; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(139, 21, 56, 0.3); }
        
        /* Dashed Add Item Button */
        .btn-dashed { background: transparent; border: 2px dashed #E4E4E7; color: #71717A; border-radius: 16px; font-weight: 800; font-size: 0.9rem; padding: 20px; transition: all 0.2s ease; margin-bottom: 24px; }
        .btn-dashed:hover { background: #FAFAFA; border-color: var(--text-main); color: var(--text-main); }

        /* Project Items Design (Right Column) */
        .items-list { display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px; }
        .item-row { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 24px; display: flex; gap: 24px; align-items: center; position: relative; transition: all 0.3s ease; z-index: 1; overflow: visible !important; }
        .item-row:hover { border-color: #E4E4E7; box-shadow: 0 10px 30px rgba(0,0,0,0.03); transform: translateY(-1px); }

        /* Item Components */
        .item-mark { min-width: 65px; display: flex; align-items: center; justify-content: center; }
        .mark-badge { background: var(--maroon-light); color: var(--maroon); font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 1rem; padding: 8px 14px; border-radius: 10px; text-align: center; }
        .item-image { width: 72px; height: 72px; border-radius: 14px; border: 1px solid var(--border); background: #FFF; display: flex; align-items: center; justify-content: center; padding: 6px; cursor: pointer; transition: all 0.3s ease; flex-shrink: 0;}
        .item-image:hover { border-color: var(--maroon); box-shadow: 0 4px 12px var(--maroon-light); }
        .item-image img { width: 100%; height: 100%; object-fit: contain; }
        .item-image span { font-size: 0.5rem; color: var(--text-light); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }

        /* Details */
        .item-details { flex: 1; display: flex; flex-direction: column; justify-content: center; min-width: 0; position: relative; overflow: visible !important; }
        .item-brand-text { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-light); font-weight: 700; margin-bottom: 2px; }
        .item-model-text { font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 800; color: var(--text-main); margin-bottom: 4px; line-height: 1.2; }
        .item-desc-text { font-size: 0.85rem; color: var(--text-muted); display: block; width: 100%; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Added Model Search (Invisible background) */
        .item-details input[type="text"].input-model-search { font-family: 'Outfit', sans-serif !important; font-size: 1.35rem !important; font-weight: 800 !important; color: var(--text-main) !important; background: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; margin-bottom: 4px !important; outline: none !important; width: 100% !important; box-shadow: none !important; line-height: 1.2 !important; }
        .item-details input[type="text"].input-model-search::placeholder { color: var(--text-light); font-weight: 400; }
        .item-details input[type="text"].input-model-search.is-searching { border-bottom: 2px dashed var(--border) !important; }
        .badge-warning { background: #FEF2F2; color: #EF4444; font-size: 0.6rem; font-weight: 700; padding: 4px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; margin-bottom: 6px; align-self: flex-start; }

        /* Metrics & QTY (Includes CSS Warning Fix) */
        .item-metrics { display: flex; gap: 32px; align-items: center; margin-right: 16px; }
        .metric-group { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .metric-group label { margin: 0; text-align: center; width: 100%; }
        .metric-value-text { font-family: 'DM Sans', sans-serif; font-size: 1rem; font-weight: 600; color: var(--text-main); }
        
        .input-qty-edit { 
            width: 45px; text-align: center; padding: 6px 4px; background: transparent !important; border: 1px solid transparent !important; 
            border-bottom: 1px dashed var(--text-light) !important; border-radius: 0 !important; font-size: 1rem; font-weight: 600; color: var(--text-main); height: auto; margin: 0; box-shadow: none !important;
        }
        .input-qty-edit:focus { border: 1px solid var(--border) !important; border-bottom: 1px solid var(--maroon) !important; background: var(--surface) !important; box-shadow: none !important; border-radius: 6px !important; }
        .input-qty-edit::-webkit-outer-spin-button, .input-qty-edit::-webkit-inner-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        .input-qty-edit[type=number] { -moz-appearance: textfield; appearance: textfield; }

        /* Actions & Modals */
        .btn-delete { background: transparent; border: none; color: var(--text-light); font-size: 1.25rem; cursor: pointer; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s ease; }
        .btn-delete:hover { background: #FEF2F2; color: #EF4444; }

        /* Enhanced Autocomplete Dropdown Positioning */
        .autocomplete-wrapper { position: relative; width: 100%; overflow: visible !important; z-index: 10; }
        .autocomplete-list { position: absolute; top: 100%; left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; max-height: 250px; overflow-y: auto; z-index: 99999 !important; display: none; margin-top: 4px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); }
        .autocomplete-item { padding: 14px 20px; cursor: pointer; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; transition: background 0.2s; }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: var(--bg); }
        .autocomplete-model { font-weight: 700; font-family: 'Outfit', sans-serif; color: var(--text-main); font-size: 1rem;}
        .autocomplete-brand { font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .autocomplete-no-results { padding: 14px 20px; color: var(--text-muted); font-size: 0.9rem; font-style: italic; text-align: center; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.85); backdrop-filter: blur(8px); }
        .modal-content { margin: auto; display: block; max-width: 85%; max-height: 85%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-close { position: absolute; top: 30px; right: 40px; color: rgba(255,255,255,0.6); font-size: 40px; font-weight: 300; transition: 0.3s; cursor: pointer; line-height: 1; }
        .modal-close:hover { color: #FFF; transform: scale(1.1); }

        /* Automated Totals Summary Card */
        .summary-card { background: var(--surface); border-radius: 20px; padding: 24px 32px; border: 1px solid var(--border); margin-bottom: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.02); }
        .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; font-size: 1.05rem; font-weight: 600; color: var(--text-muted); }
        .summary-row.total-row { border-top: 1px dashed var(--border); padding-top: 20px; margin-top: 20px; margin-bottom: 0; font-size: 1.5rem; font-weight: 800; color: var(--text-main); font-family: 'Outfit', sans-serif; }
        .summary-value { font-family: 'DM Sans', sans-serif; font-weight: 700; color: var(--text-main); }
        
        .input-discount { 
            width: 140px !important; text-align: right; padding: 10px 14px !important; border: 1px solid var(--border) !important; 
            border-radius: 10px !important; font-family: 'DM Sans', sans-serif; font-weight: 700; color: var(--maroon) !important; background: var(--surface) !important; transition: 0.2s; margin-top: 0 !important;
        }
        .input-discount:focus { border-color: var(--maroon) !important; box-shadow: 0 0 0 4px var(--maroon-light) !important; }
    </style>
</head>
<body>

    <div class="container">
        
        <div class="page-header">
            <h1>PROJECT <span>QUOTATION</span></h1>
            <a href="schedule_parser.php" class="btn-back">← Back to Parser</a>
        </div>

        <form action="process_quote.php" method="POST" id="projectForm">
            <input type="hidden" name="quote_type" value="project">
            <input type="hidden" name="items_json" id="items_json" value="">
            
            <div class="layout-grid">
                
                <div class="card project-details-card">
                    <h3>Project Details</h3>
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
                </div>

                <div class="project-items-wrapper">
                    <div style="margin-bottom: 24px;">
                        <h3 style="margin: 0;">Project Items</h3>
                    </div>
                        
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
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" id="btnAddItem" class="btn btn-dashed">+ ADD ADDITIONAL ITEM</button>

                    <div class="summary-card">
                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span class="summary-value" id="display-subtotal">₱0.00</span>
                        </div>
                        <div class="summary-row">
                            <span>Discount Amount (₱):</span>
                            <input type="number" step="0.01" name="discount_amount" id="discount_amount" value="0" class="input-discount">
                        </div>
                        <div class="summary-row total-row">
                            <span>Total Net Amount:</span>
                            <span id="display-total">₱0.00</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 0;">Generate Project PDF</button>
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
            
            // Injecting PHP Arrays safely
            const clientsData = <?= $clients_json ?>;
            const inventoryData = <?= $inventory_json ?>;
            
            // Helpful console log to verify data loaded
            console.log("Database Inventory Loaded:", inventoryData.length, "items.");

            // === Automated Calculation Logic ===
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

            // === Company Auto-Suggest Logic ===
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

            // === Inventory Model Auto-Suggest & Z-Index Management ===
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
                        e.preventDefault(); // Prevent accidental form submission
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

                        if (val.length < 2) {
                            list.style.display = 'none';
                            return;
                        }
                        
                        const matches = inventoryData.filter(i => {
                            const safeModel = String(i.model_no || '').toUpperCase();
                            return safeModel.includes(val);
                        });
                        
                        list.innerHTML = '';
                        
                        if (matches.length > 0) {
                            matches.slice(0, 15).forEach(match => { 
                                const div = document.createElement('div');
                                div.className = 'autocomplete-item';
                                div.innerHTML = `
                                    <span class="autocomplete-model">${match.model_no}</span>
                                `;
                                
                                div.addEventListener('click', function() {
                                    input.value = match.model_no;
                                    input.classList.remove('is-searching');
                                    
                                    row.querySelector('.i-model').value = match.model_no;
                                    row.querySelector('.i-full-desc').value = match.description || '';
                                    row.querySelector('.i-price').value = match.selling_price || 0;
                                    
                                    row.querySelector('.item-brand-text').textContent = 'DATABASE ITEM';
                                    
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
                            // Provide visual feedback if typing but no match found
                            list.innerHTML = `<div class="autocomplete-no-results">No match found in database</div>`;
                        }
                        
                        list.style.display = 'block';
                    }
                });

                // === Image Modal and Delete Row ===
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

            // === Add Additional Item Block ===
            const btnAddItem = document.getElementById('btnAddItem');
            if (btnAddItem) {
                btnAddItem.addEventListener('click', function() {
                    const newRow = document.createElement('div');
                    newRow.className = 'item-row';
                    newRow.style.opacity = '0';
                    
                    const currentMark = 'ADD' + addCounter;
                    addCounter++;
                    
                    // Note the autocomplete-wrapper div added for robust positioning
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
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                        </button>
                    `;
                    
                    itemsContainer.appendChild(newRow);
                    setTimeout(() => {
                        newRow.style.opacity = '1';
                        newRow.querySelector('.input-model-search').focus();
                    }, 50);
                });
            }

            // === Form Submission ===
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
                    
                    // Clean inputs so they don't double-post
                    document.querySelectorAll('input.i-qty, input.i-price, input.i-mark, input.i-brand, input.i-model, input.i-full-desc, input.i-pic, .input-model-search').forEach(el => el.removeAttribute('name'));
                    
                    this.submit();
                });
            }
        });
    </script>
</body>
</html>