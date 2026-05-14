<?php
session_start();
require '../db.php';

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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #FAFAFA; --surface: #FFFFFF; --text-main: #18181B; --text-muted: #71717A; --text-light: #A1A1AA; --border: #E4E4E7; --maroon: #8B1538; --maroon-light: #FFF5F7; --input-bg: #F4F4F5; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 40px 30px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 24px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: clamp(2rem, 4vw, 3rem); font-weight: 900; text-transform: uppercase; letter-spacing: -0.02em; line-height: 1; }
        .page-title .accent { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; transition: 0.2s ease; display: inline-block; }
        .btn-back:hover { color: var(--maroon); }
        .layout-grid { display: grid; grid-template-columns: 1fr 1.1fr; gap: 40px; align-items: start; }
        .left-col { display: flex; flex-direction: column; gap: 32px; }
        .right-col { position: sticky; top: 40px; }
        .card { background: var(--surface); border-radius: 20px; padding: 40px; border: 1px solid rgba(0,0,0,0.04); box-shadow: 0 10px 40px rgba(0,0,0,0.03); }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 800; margin-bottom: 32px; border-bottom: 2px solid var(--border); padding-bottom: 16px; color: var(--text-main); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .full-width { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        label { font-size: 0.65rem; font-weight: 700; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.1em; }
        input[type="text"], input[type="date"], input[type="email"], input[type="tel"], input[type="number"], textarea { width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid transparent; background: var(--input-bg); font-size: 0.95rem; font-family: 'DM Sans', sans-serif; font-weight: 500; color: var(--text-main); outline: none; transition: all 0.3s ease; }
        input:focus, textarea:focus { background: var(--surface); border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); }
        textarea { resize: vertical; min-height: 80px; }
        .readonly-input { background: transparent !important; border: 1px solid var(--border) !important; color: var(--text-muted) !important; pointer-events: none; font-weight: 800; letter-spacing: 1px; }
        label.required::after { content: ' *'; color: var(--maroon); font-weight: 900; font-size: 0.8rem; }
        .machine-items-container { display: flex; flex-direction: column; gap: 12px; max-height: 50vh; overflow-y: auto; padding-right: 8px; margin-bottom: 24px; }
        .machine-item { display: grid; grid-template-columns: 64px 1fr 80px; gap: 20px; align-items: center; padding: 16px 20px; border: 1px solid var(--border); border-radius: 16px; }
        .machine-img { width: 64px; height: 64px; border-radius: 10px; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; padding: 4px; background: #FAFAF9; }
        .machine-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .machine-info { flex: 1; min-width: 0; }
        .m-brand { font-size: 0.65rem; font-weight: 800; color: var(--maroon); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 2px; }
        .m-model { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2; }
        .m-price { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-top: 4px; }
        .control-group { display: flex; flex-direction: column; gap: 6px; }
        .input-qty-edit { width: 100%; text-align: center; padding: 10px 4px !important; background: #FAFAF9 !important; border: 1px solid var(--border) !important; border-radius: 8px !important; font-size: 1.05rem !important; font-weight: 700 !important; color: var(--text-main) !important; margin: 0; }
        .financial-summary-block { background: #FAFAF9; padding: 24px; border-radius: 16px; border: 1px dashed var(--border); margin-bottom: 24px; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .summary-row.total-row { border-top: 2px dashed var(--border); padding-top: 16px; margin-top: 16px; margin-bottom: 0; }
        .summary-label { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .summary-value { font-family: 'DM Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text-main); }
        .summary-total { font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 900; color: var(--maroon); }
        
        .action-buttons { display: flex; gap: 16px; margin-top: 20px; }
        .btn-preview { flex: 1; background: #FFF5F7; color: var(--maroon); height: 60px; border: 1px solid rgba(139, 21, 56, 0.2); border-radius: 50px; font-size: 0.95rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; }
        .btn-preview:hover { background: var(--maroon); color: white; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .btn-submit { flex: 1; background: var(--maroon); color: white; height: 60px; border: none; border-radius: 50px; font-size: 0.95rem; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .btn-submit:hover { background: #6A0D28; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(139, 21, 56, 0.3); }

        @media (max-width: 1024px) { .layout-grid { grid-template-columns: 1fr; gap: 32px; } .right-col { position: static; } }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1 class="page-title">Generate <span class="accent">Quotation</span></h1>
            <a href="../index.php" class="btn-back">← Back to Inventory</a>
        </div>

        <form method="POST" autocomplete="off" id="salesQuoteForm">
            <input type="hidden" name="quote_type" value="sales">
            
            <div class="layout-grid">
                
                <div class="left-col">
                    <div class="card">
                        <div class="card-title">Customer Information</div>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="required">Client Name</label>
                                <input type="text" name="client_name" placeholder="Enter client or company name" autocomplete="off" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Client Address</label>
                                <textarea name="client_address" placeholder="Enter complete billing/delivery address" autocomplete="off" required></textarea>
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Attention To</label>
                                <input type="text" name="attention_to" placeholder="Full name of contact person" autocomplete="off" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Client Email Address</label>
                                <input type="email" name="client_email" placeholder="example@domain.com" autocomplete="off" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Contact Number</label>
                                <input type="tel" name="client_contact" placeholder="e.g. 09171234567" autocomplete="off" required oninput="this.value = this.value.replace(/[^0-9]/g, '');">
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
                                <input type="text" name="proposal_purpose" placeholder="e.g. MACHINE EQUIPMENT" autocomplete="off" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Payment Terms</label>
                                <textarea name="payment_terms" placeholder="50% Down payment upon confirmation...&#10;50% Before shipment..." autocomplete="off" required></textarea>
                            </div>
                            <div class="form-group full-width">
                                <label>Inclusions</label>
                                <textarea name="inclusions" placeholder="Optional details (e.g. 1 Year Warranty, Free Delivery...)" autocomplete="off"></textarea>
                            </div>
                            <div class="form-group">
                                <label class="required">Validity Offer Date</label>
                                <input type="text" name="validity_date" placeholder="e.g. 30 Days" autocomplete="off" required>
                            </div>
                            <div class="form-group">
                                <label class="required">ETA</label>
                                <input type="text" name="eta" placeholder="e.g. 120 Days" autocomplete="off" required>
                            </div>
                            
                            <div class="form-group full-width" style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed var(--border);">
                                <label style="color: var(--maroon);">Special Corporate Discount (₱) - Applied to Grand Total</label>
                                <input type="number" name="corporate_discount" id="corporate_discount" value="0" step="0.01" min="0" autocomplete="off" required style="font-size: 1.15rem; font-weight: 700; color: var(--maroon);">
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
                                        <?php if ($machine['picture']): ?><img src="<?=$imgPath?>"><?php else: ?><span style="font-size:0.6rem; font-weight: 700; color: var(--text-light);">NO IMG</span><?php endif; ?>
                                    </div>
                                    <div class="machine-info">
                                        <div class="m-brand"><?=htmlspecialchars($machine['brand'])?></div>
                                        <div class="m-model" title="<?=htmlspecialchars($machine['model_no'])?>"><?=htmlspecialchars($machine['model_no'])?></div>
                                        <div class="m-price" data-price="<?=$machine['selling_price']?>">₱<?=number_format($machine['selling_price'], 2)?></div>
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
                                <span class="summary-value" id="live-discount" style="color: var(--maroon);">- ₱0.00</span>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Live Total Calculation
            const qtyInputs = document.querySelectorAll('.calc-qty');
            const discountInput = document.getElementById('corporate_discount');
            const subtotalEl = document.getElementById('live-subtotal');
            const discountEl = document.getElementById('live-discount');
            const totalEl = document.getElementById('live-total');

            function calculateLiveTotals() {
                let subtotal = 0;
                document.querySelectorAll('.machine-item').forEach(item => {
                    const price = parseFloat(item.querySelector('.m-price').getAttribute('data-price')) || 0;
                    const qty = parseInt(item.querySelector('.calc-qty').value) || 0;
                    subtotal += (price * qty);
                });
                const discount = parseFloat(discountInput.value) || 0;
                const total = Math.max(0, subtotal - discount);

                subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                discountEl.textContent = '- ₱' + discount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                totalEl.textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            qtyInputs.forEach(input => input.addEventListener('input', calculateLiveTotals));
            discountInput.addEventListener('input', calculateLiveTotals);
            calculateLiveTotals();

            // Dynamic Quotation Number Engine
            const dateInput = document.getElementById('quote_date');
            const quoteNoInput = document.getElementById('quotation_no');
            const nextIdStr = "<?= $paddedId ?>";

            dateInput.addEventListener('change', function() {
                if(this.value) {
                    const dateParts = this.value.split('-'); // format from picker is YYYY-MM-DD
                    if(dateParts.length === 3) {
                        const yy = dateParts[0].substring(2); // Extracts '26' from '2026'
                        const mm = dateParts[1];
                        const dd = dateParts[2];
                        quoteNoInput.value = yy + mm + dd + '_AMG_' + nextIdStr;
                    }
                }
            });
        });

        function reviewPDF() {
            const form = document.getElementById('salesQuoteForm');
            if (form.reportValidity()) {
                form.action = 'sales_preview.php';
                form.target = '_blank';
                form.submit();
            }
        }

        function submitToAdmin() {
            const form = document.getElementById('salesQuoteForm');
            if (form.reportValidity()) {
                if(confirm("Are you sure you want to finalize this quote and submit it to the Admin for approval?")) {
                    form.action = 'sales_process.php';
                    form.target = '_self';
                    sessionStorage.removeItem('quoteCartData');
                    form.submit();
                }
            }
        }
    </script>
</body>
</html>