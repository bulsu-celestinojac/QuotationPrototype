<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Quotation</title>
    <style>
        @page { margin: 0.5in 0.5in 1.0in 0.5in; }

        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; color: #000000; font-size: 11px; margin: 0; padding: 0; }
        table { width: 100%; border-collapse: collapse; }

        #footer { position: fixed; bottom: -0.6in; left: 0; right: 0; text-align: right; font-size: 10px; font-weight: bold; }
        .page-number:after { content: "Page " counter(page) " of " counter(pages); }

        .header-container { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 15px; }
        .company-name { font-size: 18px; font-weight: bold; color: #000000; text-transform: uppercase; vertical-align: middle; display: inline-block; }
        .company-details { font-size: 10px; margin-top: 8px; line-height: 1.4; }
        
        .meta-table { margin-bottom: 30px; }
        .meta-table td { vertical-align: top; }
        .client-info { width: 55%; padding-right: 20px; line-height: 1.5; }
        .doc-info { width: 45%; text-align: left; line-height: 1.5; }
        .meta-label { font-weight: bold; color: #000000; text-transform: uppercase; font-size: 11px; text-decoration: underline; }
        
        .items-table { width: 100%; border: 1px solid #000000; margin-bottom: 25px; table-layout: fixed; }
        .items-table th { background-color: #000000; color: #FFFFFF; font-size: 9px; text-transform: uppercase; padding: 10px 4px; border: 1px solid #000000; }
        .items-table td { padding: 12px 4px; text-align: center; border: 1px solid #000000; vertical-align: middle; word-wrap: break-word; }
        .desc-col { text-align: left !important; font-size: 10px; line-height: 1.4; }
        
        .summary-table { width: 100%; font-size: 11px; margin-bottom: 30px; border-collapse: collapse; }
        .summary-table td { padding: 6px 8px; border-bottom: 1px solid #EEEEEE; }
        .summary-table .label-col { text-align: right; font-weight: bold; width: 75%; text-transform: uppercase; }
        .summary-table .amount-col { text-align: right; font-weight: bold; width: 25%; white-space: nowrap; font-size: 12px; }
        
        .footer-heading { font-weight: bold; text-transform: uppercase; margin-bottom: 5px; font-size: 11px; text-decoration: underline; }
    </style>
</head>
<body>

    <div id="footer">
        <span class="page-number"></span>
    </div>

    <div class="header-container">
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
            <img src="<?= $logoBase64 ?>" style="height: 35px; width: auto; vertical-align: middle; margin-right: 15px;">
        <?php endif; ?>
        
        <div class="company-name">AM Group Kitchen Equipment and supplies, Inc.</div>
        <div class="company-details">
            5/F Builders Center Bldg., 170 Salcedo St., Legaspi Village Makati City 1229 Philippines<br>
            *Telephone +632 7752 3091 &nbsp;&nbsp;&nbsp; *Mobile +63917 174 1082 &nbsp;&nbsp;&nbsp; *Email: info@amgroup.asia &nbsp;&nbsp;&nbsp; *Website: www.amgroup.asia
        </div>
    </div>

    <table class="meta-table">
        <tr>
            <td class="client-info">
                <div class="meta-label" style="margin-bottom: 8px;">CUSTOMER INFORMATION:</div>
                <table style="width: 100%;">
                    <tr><td style="width: 35%;"><strong>Client Name:</strong></td><td><?= htmlspecialchars($trans['client_name']) ?></td></tr>
                    <tr><td style="vertical-align: top;"><strong>Client Address:</strong></td><td><?= nl2br(htmlspecialchars($trans['client_address'])) ?></td></tr>
                    <tr><td colspan="2">&nbsp;</td></tr>
                    <tr><td><strong>Attention To:</strong></td><td><?= htmlspecialchars($trans['attention_to']) ?></td></tr>
                    <tr><td><strong>Client Email Address:</strong></td><td><?= htmlspecialchars($trans['client_email']) ?></td></tr>
                    <tr><td><strong>Client Contact Number 1 / 2:</strong></td><td><?= htmlspecialchars($trans['client_contact']) ?></td></tr>
                </table>
            </td>
            <td class="doc-info">
                <div class="meta-label" style="margin-bottom: 8px;">TRANSACTION DETAILS:</div>
                <table style="width: 100%;">
                    <tr><td style="width: 35%;"><strong>Date:</strong></td><td><?= date('d/m/y', strtotime($trans['quote_date'])) ?></td></tr>
                    <tr><td><strong>Quotation No.:</strong></td><td><strong><?= htmlspecialchars($trans['quotation_no']) ?></strong></td></tr>
                    <tr><td style="vertical-align: top;"><strong>Payment Terms:</strong></td><td><?= nl2br(htmlspecialchars($trans['payment_terms'])) ?></td></tr>
                    <tr><td><strong>Validity Offer:</strong></td><td><?= date('d/m/y', strtotime($trans['validity_date'])) ?></td></tr>
                    <tr><td><strong>ETA:</strong></td><td><?= htmlspecialchars($trans['eta']) ?></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="text-align: center; font-weight: bold; font-size: 15px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px;">
        PROPOSAL FOR <?= htmlspecialchars($trans['proposal_purpose']) ?>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 5%;">No.</th>
                <th style="width: 20%;">Image</th>   
                <th style="width: 15%;">Model</th>
                <th style="width: 15%;">Brand</th>
                <th style="width: 25%;">Description</th>
                <th style="width: 5%;">Qty</th>      
                <th style="width: 15%;">Total (PHP)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $gross_total = 0;
            $counter = 1;
            foreach ($payload_items as $item): 
                $amount = $item['qty'] * $item['unit_price'];
                $gross_total += $amount;
                
                $imagePath = __DIR__ . '/../images/machine_images/' . $item['picture'];
                $base64_img = '';
                if ($item['picture'] && file_exists($imagePath)) {
                    $type = pathinfo($imagePath, PATHINFO_EXTENSION);
                    $data = file_get_contents($imagePath);
                    $base64_img = 'data:image/' . $type . ';base64,' . base64_encode($data);
                }
            ?>
            <tr>
                <td style="font-weight: bold;"><?= $counter++ ?></td>
                <td>
                    <?php if ($base64_img): ?>
                        <img src="<?= $base64_img ?>" style="max-width: 120px; max-height: 120px; object-fit: contain;">
                    <?php else: ?>
                        <span style="color: #999; font-size: 9px; font-weight: bold;">NO IMAGE</span>
                    <?php endif; ?>
                </td>
                <td><strong style="font-size: 11px;"><?= htmlspecialchars($item['model']) ?></strong></td>
                <td style="font-weight: bold; text-transform: uppercase; font-size: 10px;"><?= htmlspecialchars($item['brand']) ?></td>
                <td class="desc-col"><?= nl2br(htmlspecialchars($item['description'])) ?></td>
                <td style="font-weight: bold;"><?= $item['qty'] ?></td>
                <td style="font-size: 11px;"><strong><?= number_format($amount, 2) ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
        $net_total = $gross_total - $trans['corporate_discount']; 
    ?>
    <table class="summary-table">
        <tr>
            <td class="label-col">TOTAL AMOUNT PRICE :</td>
            <td class="amount-col"><?= number_format($gross_total, 2) ?></td>
        </tr>
        <tr>
            <td class="label-col" style="color: #8B1538;">LESS: SPECIAL CORPORATE DISCOUNT :</td>
            <td class="amount-col" style="color: #8B1538;">- <?= number_format($trans['corporate_discount'], 2) ?></td>
        </tr>
        <tr style="border-top: 2px solid #000;">
            <td class="label-col" style="font-size: 13px;">TOTAL NET PRICE (VAT INCLUDED) :</td>
            <td class="amount-col" style="font-size: 14px;"><?= number_format($net_total, 2) ?></td>
        </tr>
    </table>

    <div class="footer-heading" style="margin-top: 40px;">OUR BANK DETAILS:</div>
    <table style="width: 100%; font-size: 11px; margin-bottom: 60px;">
        <tr>
            <td colspan="2">Bank of the Philippine Islands, Ayala Avenue II Branch</td>
        </tr>
        <tr>
            <td style="width: 12%;">Account No.:</td>
            <td><strong>1511-0078-24 / Swift Code : BOPIPHMM</strong></td>
        </tr>
        <tr>
            <td>Account Name:</td>
            <td><strong>AM Group Kitchen Equipment and supplies, Inc.</strong></td>
        </tr>
    </table>

    <table style="width: 100%; font-size: 11px; page-break-inside: avoid;">
        <tr>
            <td style="width: 40%; vertical-align: top;">
                Prepared By:<br><br><br><br>
                ________________________________________________<br>
                <strong style="text-transform: uppercase; font-size: 12px;"><?= htmlspecialchars($trans['prepared_by']) ?></strong>
            </td>
            <td></td>
        </tr>
    </table>

</body>
</html>