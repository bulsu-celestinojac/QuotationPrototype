<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Project Quotation</title>
    <style>
        @page { margin: 0.5in 0.5in 1.0in 0.5in; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #000; font-size: 11px; }

        /* ==========================================
           DYNAMIC A3 SCALING
           ========================================== */
        <?php if (isset($paper_size) && $paper_size === 'A3'): ?>
            body { font-size: 14px; }
            .company-name { font-size: 20px !important; }
            .company-details { font-size: 12px !important; }
            .info-grid td { font-size: 12px !important; padding: 10px 12px !important; }
            .items-table th { font-size: 12px !important; padding: 14px 6px !important; }
            .items-table td { font-size: 12px !important; }
            .desc-col { font-size: 11px !important; }
            .mark-badge { font-size: 15px !important; }
            .summary-table { font-size: 14px !important; }
            .footer-terms { font-size: 12px !important; }
        <?php endif; ?>
        /* ========================================== */
        
        /* Main Header Layout matching your image */
        .top-header { width: 100%; margin-bottom: 20px; }
        .top-header td { vertical-align: middle; }
        .company-name { font-size: 16px; font-weight: bold; }
        .company-details { font-size: 10px; line-height: 1.4; }

        /* The Grid Header matching your image */
        .info-grid { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 20px; }
        .info-grid td { border: 1px solid #000; padding: 6px 8px; vertical-align: top; line-height: 1.3; }
        .info-grid .dark-cell { background-color: #f0f0f0; }

        /* The Equipment Table */
        .items-table { width: 100%; border-collapse: collapse; border: 1px solid #000; margin-bottom: 10px; }
        .items-table th { background-color: #000; color: #FFF; font-size: 10px; text-transform: uppercase; padding: 10px 4px; border: 1px solid #000; }
        .items-table td { padding: 12px 4px; text-align: center; border: 1px solid #000; vertical-align: middle; }
        .desc-col { text-align: left !important; font-size: 9px; line-height: 1.4; }
        .mark-badge { font-weight: bold; font-size: 13px; color: #8B1538; }

        /* Subtotal / Footer Layout */
        .summary-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 30px; }
        .summary-table td { padding: 6px 8px; border: 1px solid #000; }
        .summary-table .label-col { text-align: right; font-weight: bold; }
        .summary-table .amount-col { text-align: right; font-weight: bold; }

        /* Footer Details matching image 2 */
        .footer-terms { width: 100%; font-size: 10px; border-collapse: collapse; }
        .footer-terms td { vertical-align: top; padding: 5px 0; }
        .term-label { font-weight: bold; width: 15%; }
    </style>
</head>
<body>

    <table class="top-header">
        <tr>
            <td style="width: 15%;">
                <?php 
                $logoPath = __DIR__ . '/../images/other_images/AMGLOGO.png';
                $logoBase64 = '';
                if (file_exists($logoPath)) {
                    $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
                    $logoData = file_get_contents($logoPath);
                    $logoBase64 = 'data:image/' . $logoType . ';base64,' . base64_encode($logoData);
                }
                ?>
                <?php if ($logoBase64): ?>
                    <img src="<?= $logoBase64 ?>" style="height: 50px; width: auto;">
                <?php endif; ?>
            </td>
            <td style="width: 85%;">
                <div class="company-name">AM GROUP Kitchen Equipment and Supplies, Inc.</div>
                <div class="company-details">
                    5/F Builders Center Bldg., 170 Salcedo St., Legaspi Village Makati City 1229 Philippines<br>
                    *Telephone +632 7752 3091 &nbsp;&nbsp;&nbsp; *Mobile +63917 174 1082<br>
                    *Email: <span style="text-decoration: underline;">info@amgroup.asia</span> &nbsp;&nbsp;&nbsp; *Website: <strong>www.amgroup.asia</strong>
                </div>
            </td>
        </tr>
    </table>

    <table class="info-grid">
        <tr>
            <td colspan="2" style="width: 50%;">
                Contact: <?= htmlspecialchars($trans['contact_name'] ?? '') ?><br>
                Email: <?= htmlspecialchars($trans['email'] ?? '') ?><br>
                Position: <?= htmlspecialchars($trans['position'] ?? '') ?><br>
                Address: <?= nl2br(htmlspecialchars($trans['client_address'] ?? '')) ?><br>
                <strong style="font-size: 12px; text-transform: uppercase;"><?= htmlspecialchars($trans['company_name'] ?? '') ?></strong>
            </td>
            <td colspan="2" class="dark-cell" style="width: 50%; text-align: center; vertical-align: middle; font-size: 16px; font-weight: bold; text-transform: uppercase;">
                <?= htmlspecialchars($trans['project_name'] ?? 'EQUIPMENT OFFER') ?>
            </td>
        </tr>
        <tr>
            <td style="width: 25%;"><em>Date:</em> <?= date('F d, Y', strtotime($trans['quote_date'])) ?></td>
            <td style="width: 25%;"><em>Offer Validity</em></td>
            <td colspan="2" style="width: 50%;"><em>Delivery Arrangements</em></td>
        </tr>
        <tr>
            <td><strong>Offer No. <?= htmlspecialchars($trans['quotation_no'] ?? '') ?></strong></td>
            <td><?= htmlspecialchars($trans['offer_validity'] ?? '') ?></td>
            <td colspan="2"><strong><?= htmlspecialchars($trans['delivery_arrangements'] ?? '') ?></strong></td>
        </tr>
        <tr>
            <td><em>Mode of Dispatch</em></td>
            <td><em>Package</em></td>
            <td colspan="2"><em>Payment</em></td>
        </tr>
        <tr>
            <td><strong><?= htmlspecialchars($trans['mode_of_dispatch'] ?? '') ?></strong></td>
            <td><strong><?= htmlspecialchars($trans['package_type'] ?? '') ?></strong></td>
            <td colspan="2"><strong><?= nl2br(htmlspecialchars($trans['payment_terms'] ?? '')) ?></strong></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 10%;">Location Code</th>
                <th style="width: 22%;">Image</th>
                <th style="width: 20%;">Brand / Model</th>
                <th style="width: 23%;">Description</th>
                <th style="width: 5%;">Qty</th>
                <th style="width: 8%;">Unit Price</th>      
                <th style="width: 12%;">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sub_total = 0;
            if (isset($payload_items) && is_array($payload_items)):
                foreach ($payload_items as $item): 
                    $amount = $item['qty'] * $item['unit_price'];
                    $sub_total += $amount;
                    
                    $base64_img = '';
                    if (!empty($item['picture'])) {
                        $imagePath = __DIR__ . '/../images/machine_images/' . $item['picture'];
                        if (file_exists($imagePath)) {
                            $type = pathinfo($imagePath, PATHINFO_EXTENSION);
                            $data = file_get_contents($imagePath);
                            $base64_img = 'data:image/' . $type . ';base64,' . base64_encode($data);
                        }
                    }
            ?>
            <tr>
                <td class="mark-badge"><?= htmlspecialchars($item['mark']) ?></td>
                
                <td>
                    <?php if ($base64_img): ?>
                        <img src="<?= $base64_img ?>" style="max-width: 140px; max-height: 140px; object-fit: contain;">
                    <?php else: ?>
                        <span style="color: #999; font-size: 8px; font-weight: bold;">NO IMAGE</span>
                    <?php endif; ?>
                </td>

                <td>
                    <strong style="text-transform: uppercase; font-size: 11px;"><?= htmlspecialchars($item['brand']) ?></strong><br>
                    <span style="font-size: 12px; font-weight: bold;">/ <?= htmlspecialchars($item['model']) ?></span>
                </td>
                
                <td class="desc-col"><?= nl2br(htmlspecialchars($item['description'])) ?></td>
                <td style="font-weight: bold; font-size: 12px;"><?= $item['qty'] ?></td>
                <td style="font-size: 10px;"><?= number_format($item['unit_price'], 2) ?></td>
                <td style="font-size: 11px;"><strong><?= number_format($amount, 2) ?></strong></td>
            </tr>
            <?php 
                endforeach; 
            endif;
            ?>
            
            <tr>
                <td colspan="6" class="label-col" style="text-align: right; font-weight: bold; border: none; padding-top: 15px;">SUB-TOTAL : PHP</td>
                <td class="amount-col" style="border: none; border-bottom: 1px solid #000; padding-top: 15px;"><?= number_format($sub_total, 2) ?></td>
            </tr>
            
            <?php 
            $discount = $trans['discount_amount'] ?? 0;
            if ($discount > 0): 
            ?>
            <tr>
                <td colspan="6" class="label-col" style="text-align: right; font-weight: bold; border: none; color: #8B1538;">LESS DISCOUNT : PHP</td>
                <td class="amount-col" style="border: none; border-bottom: 1px solid #000; color: #8B1538;">- <?= number_format($discount, 2) ?></td>
            </tr>
            <?php endif; ?>

            <tr class="dark-cell">
                <?php $net_total = $sub_total - $discount; ?>
                <td colspan="6" class="label-col" style="text-align: right; font-weight: bold; font-size: 12px; border: 1px solid #000;">Total Amount (Vat-Included) ₱</td>
                <td class="amount-col" style="font-size: 12px; border: 1px solid #000;"><strong><?= number_format($net_total, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div style="font-weight: bold; margin-bottom: 10px; font-size: 11px;">Terms and Conditions:</div>
    <table class="footer-terms">
        <tr>
            <td class="term-label">Payment:</td>
            <td style="width: 40%;"><?= nl2br(htmlspecialchars($trans['payment_terms'] ?? '')) ?></td>
            <td style="width: 45%; text-align: center; vertical-align: bottom; padding-bottom: 10px;">
                Conforme:<br><br><br>
                ____________________________________________________<br>
                Signature over printed name and date
            </td>
        </tr>
        <tr>
            <td class="term-label"><br><br><br>Delivery:</td>
            <td colspan="2"><br><br><br><?= htmlspecialchars($trans['delivery_arrangements'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="term-label"><br><br><br>Included:</td>
            <td colspan="2"><br><br><br><?= nl2br(htmlspecialchars($trans['inclusions'] ?? '')) ?></td>
        </tr>
    </table>

    <table style="width: 100%; border: none; margin-top: 40px; font-size: 10px;">
        <tr>
            <td style="width: 60%;">
                <div style="font-weight: bold; text-transform: uppercase;">OUR BANK DETAILS :</div>
                <strong>Bank of the Philippine Islands, Ayala Avenue II Branch</strong><br>
                <strong>Account No.: 1511-0078-24 / Swift Code: BOPIPHMM</strong><br>
                <strong>Account Name: AM Group Kitchen Equipment and Supplies, Inc.</strong>
                <br><br><br>
                Prepared by:<br><br><br>
                ______________________________________<br>
                <strong style="text-transform: uppercase;"><?= htmlspecialchars($trans['prepared_by'] ?? '') ?></strong><br>
                Signature over printed name
            </td>
            <td style="width: 40%; text-align: center; vertical-align: top; font-style: italic;">
                <br><br>Thank you and we hope to receive your valued order soon.
            </td>
        </tr>
    </table>

</body>
</html>