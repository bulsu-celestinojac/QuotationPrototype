<?php
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

// Generate Quotation Number (YY/DD/MM + AMG + Incrementing ID)
$stmtId = $pdo->query("
    SELECT AUTO_INCREMENT 
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'sales_quotations'
");
$nextId = (int)$stmtId->fetchColumn();

// Fallback just in case the table is brand new and empty
if ($nextId === 0) {
    $stmtFallback = $pdo->query("SELECT MAX(id) FROM sales_quotations");
    $nextId = (int)$stmtFallback->fetchColumn() + 1;
}

// Formatted as 260405_AMG_0015 (YearDayMonth)
$default_quote_num = date('ydm') . '_AMG_' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Sales Quotation Builder - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #FAFAFA;
            --surface: #FFFFFF;
            --text-main: #18181B; 
            --text-muted: #71717A;
            --text-light: #A1A1AA;
            --border: #E4E4E7;
            --maroon: #8B1538; 
            --maroon-light: #FFF5F7;
            --input-bg: #F4F4F5;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'DM Sans', sans-serif; 
            background: var(--bg); 
            color: var(--text-main); 
            padding: 40px 30px; 
            min-height: 100vh;
        }
        
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header { 
            margin-bottom: 40px; 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-end; 
            border-bottom: 1px solid var(--border);
            padding-bottom: 24px;
        }
        
        .page-title { 
            font-family: 'Outfit', sans-serif; 
            font-size: clamp(2rem, 4vw, 3rem); 
            font-weight: 900; 
            text-transform: uppercase;
            letter-spacing: -0.02em;
            line-height: 1;
        }
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
        
        input[type="text"], input[type="date"], input[type="email"], input[type="tel"], input[type="number"], textarea {
            width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid transparent; background: var(--input-bg); font-size: 0.95rem; font-family: 'DM Sans', sans-serif; font-weight: 500; color: var(--text-main); outline: none; transition: all 0.3s ease;
        }
        input:focus, textarea:focus { background: var(--surface); border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); }
        textarea { resize: vertical; min-height: 80px; }
        input::placeholder, textarea::placeholder { color: var(--text-light); font-weight: 400; }
        
        .readonly-input { background: transparent !important; border: 1px solid var(--border) !important; color: var(--text-muted) !important; pointer-events: none; }

        /* --- FORM VALIDATION STYLES --- */
        label.required::after {
            content: ' *';
            color: var(--maroon);
            font-weight: 900;
            font-size: 0.8rem;
        }

        input:invalid:not(:placeholder-shown):not(:focus),
        textarea:invalid:not(:placeholder-shown):not(:focus) {
            border-color: #EF4444 !important;
            background-color: #FEF2F2 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }

        /* Premium Item Grid */
        .machine-items-container { display: flex; flex-direction: column; gap: 12px; max-height: 50vh; overflow-y: auto; padding-right: 8px; margin-bottom: 24px; }
        .machine-items-container::-webkit-scrollbar { width: 6px; }
        .machine-items-container::-webkit-scrollbar-thumb { background: rgba(139, 21, 56, 0.2); border-radius: 10px; }

        .machine-item { 
            display: grid; 
            grid-template-columns: 64px 1fr 80px; 
            gap: 20px; 
            align-items: center; 
            padding: 16px 20px; 
            border: 1px solid var(--border); 
            border-radius: 16px; 
            transition: all 0.2s ease;
        }
        .machine-item:hover { border-color: rgba(139, 21, 56, 0.3); box-shadow: 0 6px 16px rgba(139, 21, 56, 0.06); transform: translateY(-1px); }

        .machine-img { width: 64px; height: 64px; border-radius: 10px; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; padding: 4px; background: #FAFAF9; }
        .machine-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .machine-info { flex: 1; min-width: 0; }
        .m-brand { font-size: 0.65rem; font-weight: 800; color: var(--maroon); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 2px; }
        .m-model { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2; }
        .m-price { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); margin-top: 4px; }
        
        .control-group { display: flex; flex-direction: column; gap: 6px; }
        
        .input-qty-edit { 
            width: 100%; text-align: center; padding: 10px 4px !important; background: #FAFAF9 !important; border: 1px solid var(--border) !important; border-radius: 8px !important; font-size: 1.05rem !important; font-weight: 700 !important; color: var(--text-main) !important; margin: 0;
        }
        .input-qty-edit:focus { border-color: var(--maroon) !important; background: var(--surface) !important; box-shadow: 0 0 0 3px var(--maroon-light) !important; }

        /* Remove default number arrows */
        .input-qty-edit::-webkit-outer-spin-button, 
        .input-qty-edit::-webkit-inner-spin-button { 
            -webkit-appearance: none; appearance: none; margin: 0; 
        }
        .input-qty-edit[type=number] { -moz-appearance: textfield; appearance: textfield; }

        /* Live Financial Summary */
        .financial-summary-block { background: #FAFAF9; padding: 24px; border-radius: 16px; border: 1px dashed var(--border); margin-bottom: 24px; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .summary-row.total-row { border-top: 2px dashed var(--border); padding-top: 16px; margin-top: 16px; margin-bottom: 0; }
        .summary-label { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .summary-value { font-family: 'DM Sans', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text-main); }
        .summary-total { font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 900; color: var(--maroon); }

        .btn-submit { background: var(--maroon); color: white; width: 100%; height: 60px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .btn-submit:hover { background: #6A0D28; transform: translateY(-2px); box-shadow: 0 12px 24px rgba(139, 21, 56, 0.3); }

        /* =======================================================
           RESPONSIVE DESIGN (Tablets & Mobile)
           ======================================================= */
        @media (max-width: 1024px) {
            .layout-grid { grid-template-columns: 1fr; gap: 32px; }
            .right-col { position: static; }
            .machine-items-container { max-height: none; }
        }

        @media (max-width: 600px) {
            body { padding: 20px 16px; }
            .header { flex-direction: column; align-items: flex-start; gap: 16px; margin-bottom: 24px; padding-bottom: 16px; }
            .page-title { font-size: 2.2rem; }
            
            .card { padding: 24px; border-radius: 16px; }
            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            
            .machine-item { 
                grid-template-columns: 64px 1fr; 
                gap: 16px; 
            }
            .control-group { 
                grid-column: 1 / -1; 
                flex-direction: row; 
                align-items: center; 
                justify-content: space-between; 
                background: var(--bg);
                padding: 12px;
                border-radius: 10px;
            }
            .control-group label { margin: 0; }
            .control-group .input-qty-edit { width: 100px; padding: 8px !important; }
            
            .financial-summary-block { padding: 20px; }
            .summary-total { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1 class="page-title">Generate <span class="accent">Quotation</span></h1>
            <a href="../index.php" class="btn-back">← Back to Inventory</a>
        </div>

        <form action="sales_process.php" method="POST" target="_blank" autocomplete="off" id="salesQuoteForm">
            <input type="hidden" name="quote_type" value="sales">
            
            <div class="layout-grid">
                
                <div class="left-col">
                    <div class="card">
                        <div class="card-title">Customer Information</div>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="required">Client Name</label>
                                <input type="text" name="client_name" placeholder="Enter client or company name" autocomplete="off" required>
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
                                <input type="tel" name="client_contact" placeholder="e.g. 09171234567" pattern="^(09|\+639)\d{9}$|^[0-9]{2,3}[-\s]?[0-9]{7}$" title="Please enter a valid PH mobile number or landline." autocomplete="off" required>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title">Transaction Details</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="required">Date</label>
                                <input type="date" name="quote_date" value="<?=date('Y-m-d')?>" autocomplete="off" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Quotation No.</label>
                                <input type="text" name="quotation_no" class="readonly-input" value="<?=$default_quote_num?>" readonly tabindex="-1" required>
                            </div>
                            <div class="form-group full-width">
                                <label class="required">Proposal Purpose</label>
                                <input type="text" name="proposal_purpose" placeholder="e.g. MACHINE EQUIPMENT" autocomplete="off" required>
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
                                <input type="text" name="prepared_by" placeholder="Your Full Name" autocomplete="off" required>
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

                        <button type="submit" class="btn-submit">Generate PDF Document</button>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const qtyInputs = document.querySelectorAll('.calc-qty');
            const discountInput = document.getElementById('corporate_discount');
            
            const subtotalEl = document.getElementById('live-subtotal');
            const discountEl = document.getElementById('live-discount');
            const totalEl = document.getElementById('live-total');

            function calculateLiveTotals() {
                let subtotal = 0;
                
                // Calculate Subtotal from machine items
                document.querySelectorAll('.machine-item').forEach(item => {
                    const price = parseFloat(item.querySelector('.m-price').getAttribute('data-price')) || 0;
                    const qty = parseInt(item.querySelector('.calc-qty').value) || 0;
                    subtotal += (price * qty);
                });

                // Get Discount
                const discount = parseFloat(discountInput.value) || 0;
                
                // Calculate Total
                const total = Math.max(0, subtotal - discount);

                // Update UI text strings
                subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                discountEl.textContent = '- ₱' + discount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                totalEl.textContent = '₱' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            // Listen for user typing in QTY or Discount
            qtyInputs.forEach(input => {
                input.addEventListener('input', calculateLiveTotals);
            });
            discountInput.addEventListener('input', calculateLiveTotals);

            // Initial calculation on page load
            calculateLiveTotals();
            
            // Validate form, empty the cart, and send the user back to the main dashboard
            const form = document.getElementById('salesQuoteForm');
            form.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    this.reportValidity();
                } else {
                    // This is the vacuum that clears out the user's cart items
                    sessionStorage.removeItem('quoteCartData');
                    
                    // Small delay to allow the new tab to open the PDF before refreshing this tab
                    setTimeout(() => {
                        window.location.href = '../index.php';
                    }, 500);
                }
            });
        });
    </script>
</body>
</html>