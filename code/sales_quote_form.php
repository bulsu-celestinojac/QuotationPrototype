<?php
require 'db.php';

$selected_items_json = $_POST['selected_items'] ?? '[]';
$selected_ids = json_decode($selected_items_json, true);

if (empty($selected_ids) || !is_array($selected_ids)) {
    header("Location: index.php");
    exit;
}

$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
$stmt->execute($selected_ids);
$machines = $stmt->fetchAll();

// Generate Quotation Number (YY/DD/MM + AMG + Incrementing ID)
$stmtId = $pdo->query("SELECT MAX(id) FROM sales_quotations");
$nextId = (int)$stmtId->fetchColumn() + 1;
// Formatted as 261604_AMG_0001 (YearDayMonth)
$default_quote_num = date('ydm') . '_AMG_' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Sales Quotation Builder - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F8F6F5;
            --surface: #FFFFFF;
            --text-main: #2A0808; 
            --text-muted: #8C7373;
            --border: #E8D8D7;
            --maroon: #8B1538; 
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
            font-size: 3rem; 
            font-weight: 900; 
            text-transform: uppercase;
        }
        .page-title .accent { color: var(--maroon); }
        
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .btn-back:hover { color: var(--maroon); }

        .layout-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 32px; align-items: start; }
        @media (max-width: 1024px) { .layout-grid { grid-template-columns: 1fr; } }

        .left-col { display: flex; flex-direction: column; gap: 32px; }
        .right-col { position: sticky; top: 40px; }

        .card { background: var(--surface); border-radius: 24px; padding: 40px 48px; border: 1px solid var(--border); }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 700; margin-bottom: 32px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .full-width { grid-column: 1 / -1; }

        .form-group { display: flex; flex-direction: column; gap: 8px; }
        label { font-size: 0.65rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; }
        
        input[type="text"], input[type="date"], input[type="number"], textarea {
            width: 100%; padding: 14px 16px; border-radius: 12px; border: 1px solid var(--border); font-size: 0.95rem; font-family: 'DM Sans', sans-serif; outline: none;
        }
        input:focus, textarea:focus { border-color: var(--maroon); }
        textarea { resize: vertical; min-height: 80px; }

        .machine-item { display: flex; align-items: center; gap: 20px; padding: 16px 20px; border: 1px solid var(--border); border-radius: 16px; margin-bottom: 16px;}
        .machine-img { width: 70px; height: 70px; border-radius: 8px; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; }
        .machine-img img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .machine-info { flex: 1; }
        .m-brand { font-size: 0.65rem; font-weight: 800; color: var(--maroon); text-transform: uppercase; }
        .m-model { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 900; }
        .m-price { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); }
        .machine-controls { display: flex; gap: 12px; }
        .control-group { width: 100px; }

        .btn-submit { background: var(--maroon); color: white; width: 100%; height: 56px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 700; text-transform: uppercase; cursor: pointer; margin-top: 40px; }
        .btn-submit:hover { background: #5A0000; }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1 class="page-title">Generate <span class="accent">Quotation</span></h1>
            <a href="index.php" class="btn-back">← Back to Inventory</a>
        </div>

        <form action="process_quote.php" method="POST" autocomplete="off">
            <input type="hidden" name="quote_type" value="sales">
            
            <div class="layout-grid">
                
                <div class="left-col">
                    <div class="card">
                        <div class="card-title">Customer Information</div>
                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label>Client Name</label>
                                <input type="text" name="client_name" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Client Address</label>
                                <textarea name="client_address" required></textarea>
                            </div>
                            <div class="form-group full-width">
                                <label>Attention To</label>
                                <input type="text" name="attention_to" required>
                            </div>
                            <div class="form-group">
                                <label>Client Email Address</label>
                                <input type="text" name="client_email" required>
                            </div>
                            <div class="form-group">
                                <label>Client Contact Number 1 / 2</label>
                                <input type="text" name="client_contact" required>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title">Transaction Details</div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Date</label>
                                <input type="date" name="quote_date" value="<?=date('Y-m-d')?>" required>
                            </div>
                            <div class="form-group">
                                <label>Quotation No.</label>
                                <input type="text" name="quotation_no" value="<?=$default_quote_num?>" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Proposal Purpose</label>
                                <input type="text" name="proposal_purpose" placeholder="e.g. MACHINE EQUIPMENT" required>
                            </div>
                            <div class="form-group full-width">
                                <label>Payment Terms</label>
                                <textarea name="payment_terms" placeholder="50% Down payment...&#10;50% Before shipment..." required></textarea>
                            </div>
                            <div class="form-group">
                                <label>Validity Offer Date</label>
                                <input type="date" name="validity_date" required>
                            </div>
                            <div class="form-group">
                                <label>ETA</label>
                                <input type="text" name="eta" placeholder="e.g. 120 Days" required>
                            </div>
                            
                            <div class="form-group full-width" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
                                <label>Special Corporate Discount (₱) - Applied to Grand Total</label>
                                <input type="number" name="corporate_discount" value="0" step="0.01" min="0" required>
                            </div>
                            
                            <div class="form-group full-width">
                                <label>Prepared By</label>
                                <input type="text" name="prepared_by" placeholder="Your Name" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="right-col">
                    <div class="card">
                        <div class="card-title">Selected Machines (<?=count($machines)?>)</div>
                        <div>
                            <?php foreach ($machines as $index => $machine): 
                                $imgPath = '../images/machine_images/' . htmlspecialchars($machine['picture']);
                            ?>
                                <div class="machine-item">
                                    <input type="hidden" name="items[<?=$index?>][id]" value="<?=$machine['id']?>">
                                    <div class="machine-img">
                                        <?php if ($machine['picture']): ?><img src="<?=$imgPath?>"><?php else: ?><span style="font-size:0.6rem;">NO IMG</span><?php endif; ?>
                                    </div>
                                    <div class="machine-info">
                                        <div class="m-brand"><?=htmlspecialchars($machine['brand'])?></div>
                                        <div class="m-model"><?=htmlspecialchars($machine['model_no'])?></div>
                                        <div class="m-price">₱<?=number_format($machine['selling_price'], 2)?></div>
                                    </div>
                                    <div class="machine-controls">
                                        <div class="control-group">
                                            <label>QTY</label>
                                            <input type="number" name="items[<?=$index?>][qty]" value="1" min="1" required>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn-submit">Generate PDF Document</button>
                    </div>
                </div>

            </div>
        </form>
    </div>

</body>
</html>