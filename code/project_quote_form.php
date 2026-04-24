<?php
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Project Quote Builder - AM Group</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: #FFFFFF; --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); padding: 40px; color: var(--text-main); }
        .container { max-width: 1500px; margin: 0 auto; }
        .layout-grid { display: grid; grid-template-columns: 1fr 1.8fr; gap: 32px; align-items: start; }
        .card { background: var(--surface); border-radius: 24px; padding: 40px; border: 1px solid var(--border); margin-bottom: 24px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .full { grid-column: 1/-1; }
        label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); }
        input[type="text"], input[type="date"], input[type="number"], select, textarea { width: 100%; padding: 12px; border-radius: 10px; border: 1px solid var(--border); margin-top: 5px; box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
        .btn-submit { background: var(--maroon); color: white; border: none; padding: 15px 30px; border-radius: 50px; font-weight: 700; cursor: pointer; width: 100%; text-transform: uppercase; margin-top: 20px; }
        
        .item-row { display: grid; grid-template-columns: 70px 70px 100px 1fr 120px; gap: 15px; padding: 15px; border-bottom: 1px solid var(--border); align-items: center; }
        .machine-img { width: 60px; height: 60px; border-radius: 8px; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; background: #fff;}
        .machine-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .unmatched { background: #fff5f5; border-radius: 10px; border: 1px solid #ffcccc; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="font-family: 'Outfit'; font-size: 3rem; margin-bottom: 40px;">PROJECT <span style="color: var(--maroon)">QUOTATION</span></h1>
        
        <form action="process_quote.php" method="POST" id="projectForm">
            <input type="hidden" name="quote_type" value="project">
            <input type="hidden" name="items_json" id="items_json" value="">
            
            <div class="layout-grid">
                <div class="left-col">
                    <div class="card">
                        <h3 style="margin-bottom: 20px; font-family:'Outfit';">Project Details</h3>
                        <div class="form-grid">
                            <div class="full"><label>Company Name (Auto Caps)</label><input type="text" name="company_name" style="text-transform: uppercase;" required></div>
                            <div class="full"><label>Project Name (Auto Caps)</label><input type="text" name="project_name" value="EQUIPMENT OFFER" style="text-transform: uppercase;" required></div>
                            
                            <div><label>Contact Name</label><input type="text" name="contact_name"></div>
                            <div><label>Email</label><input type="text" name="email"></div>
                            <div class="full"><label>Position</label><input type="text" name="position"></div>
                            
                            <div class="full"><label>Complete Address (With Zip Code - Two Lines)</label><textarea name="client_address" rows="2" required></textarea></div>
                            
                            <div><label>Offer No.</label><input type="text" name="quotation_no" value="<?= $default_quote_num ?>"></div>
                            <div><label>Date</label><input type="date" name="quote_date" value="<?= date('Y-m-d') ?>"></div>
                            
                            <div><label>Offer Validity</label><input type="text" name="offer_validity" value="30 days"></div>
                            <div><label>Mode of Dispatch</label><input type="text" name="mode_of_dispatch" value="To Agree"></div>
                            <div class="full"><label>Package</label><input type="text" name="package_type" value="Standard Pack"></div>
                            
                            <div class="full"><label>Delivery Arrangements</label><input type="text" name="delivery_arrangements" value="150 to 180 Working days if not on-stock after Downpayment and Drawing Confirmation"></div>
                            <div class="full"><label>Payment Terms</label><textarea name="payment_terms" rows="3">50% Downpayment As Order Confirmation&#10;40% Prior Shipment from Italy/ China&#10;10% Upon Installation</textarea></div>
                            <div class="full"><label>Inclusions</label><textarea name="inclusions" rows="3">1 Year Warranty&#10;1 Year After Sales Service&#10;Delivery Included</textarea></div>

                            <div class="full"><label>Discount Amount (₱)</label><input type="number" step="0.01" name="discount_amount" value="0"></div>
                            
                            <div class="full">
                                <label>Paper Size</label>
                                <select name="paper_size">
                                    <option value="A4">A4 (Standard)</option>
                                    <option value="A3">A3 (Large Format)</option>
                                </select>
                            </div>
                            
                            <div class="full"><label>Prepared By</label><input type="text" name="prepared_by" required></div>
                        </div>
                    </div>
                </div>

                <div class="right-col">
                    <div class="card">
                        <h3 style="margin-bottom: 20px; font-family:'Outfit';">Extracted Schedule Items (<?=count($incoming_items)?>)</h3>
                        <?php foreach ($incoming_items as $index => $item): ?>
                            <div class="item-row <?= !$item['db_id'] ? 'unmatched' : '' ?>">
                                
                                <div style="font-weight: 900; color: var(--maroon); font-size: 1.1rem;"><?= htmlspecialchars($item['mark']) ?></div>
                                
                                <div class="machine-img">
                                    <?php if (!empty($item['picture'])): ?>
                                        <img src="../images/machine_images/<?= htmlspecialchars($item['picture']) ?>" alt="IMG">
                                    <?php else: ?>
                                        <span style="font-size: 0.55rem; color: var(--text-muted); font-weight: bold;">NO IMG</span>
                                    <?php endif; ?>
                                </div>

                                <div style="font-size: 0.8rem; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars($item['brand']) ?></div>
                                
                                <div>
                                    <div style="font-weight: 900; font-family: 'Outfit'; font-size: 1.1rem;"><?= htmlspecialchars($item['model']) ?></div>
                                    <?php if(!$item['db_id']): ?>
                                        <span style="font-size: 0.65rem; color: #cc0000; font-weight: bold;">⚠️ NOT IN INVENTORY</span>
                                    <?php else: ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"><?= htmlspecialchars($item['full_desc']) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label>Qty</label>
                                    <input type="number" class="i-qty" value="1" min="1" style="margin-bottom: 5px;">
                                    <label>Unit Price (₱)</label>
                                    <input type="number" class="i-price" step="0.01" value="<?= $item['price'] ?>">
                                    
                                    <input type="hidden" class="i-mark" value="<?= htmlspecialchars($item['mark']) ?>">
                                    <input type="hidden" class="i-brand" value="<?= htmlspecialchars($item['brand']) ?>">
                                    <input type="hidden" class="i-model" value="<?= htmlspecialchars($item['model']) ?>">
                                    <input type="hidden" class="i-desc" value="<?= htmlspecialchars($item['full_desc']) ?>">
                                    <input type="hidden" class="i-pic" value="<?= htmlspecialchars($item['picture'] ?? '') ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn-submit">Generate Project PDF</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        document.getElementById('projectForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const items = [];
            const rows = document.querySelectorAll('.item-row');
            
            rows.forEach(row => {
                if(row.querySelector('.i-mark')) {
                    items.push({
                        mark: row.querySelector('.i-mark').value,
                        brand: row.querySelector('.i-brand').value,
                        model: row.querySelector('.i-model').value,
                        description: row.querySelector('.i-desc').value,
                        picture: row.querySelector('.i-pic').value,
                        qty: row.querySelector('.i-qty').value,
                        unit_price: row.querySelector('.i-price').value
                    });
                }
            });
            
            document.getElementById('items_json').value = JSON.stringify(items);
            document.querySelectorAll('.i-qty, .i-price').forEach(el => el.removeAttribute('name'));
            
            this.submit();
        });
    </script>
</body>
</html>