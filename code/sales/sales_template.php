<?php
/**
 * @var array $trans
 * @var array $payload_items
 */

// 1. Base64 Encode the Header Logo (UP TWO LEVELS to images folder)
$logoPath = __DIR__ . '/../../images/other_images/AMGLOGO.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoData = file_get_contents($logoPath);
    $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
    $logoBase64 = 'data:image/' . $logoType . ';base64,' . base64_encode($logoData);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotation PDF</title>
    <style>
        @page { margin: 460px 40px 60px 40px; }
        header { position: fixed; top: -420px; left: 0px; right: 0px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #000; margin: 0; padding: 0; font-size: 12px; line-height: 1.4; }
        .header-container { text-align: center; }
        .header-title { font-size: 26px; font-weight: bold; margin: 10px 0 6px 0; color: #000; letter-spacing: -0.5px; }
        .header-address { font-size: 13px; margin: 0 0 4px 0; color: #000; }
        .header-contacts { font-size: 13px; margin: 0; color: #000; }
        .header-link { color: #000; text-decoration: underline; }
        .section-title { font-size: 13px; font-weight: bold; text-transform: uppercase; text-decoration: underline; margin-bottom: 8px; margin-top: 5px; }
        .info-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .info-table td { padding: 4px 0; vertical-align: top; }
        .info-label { font-weight: bold; width: 40%; }
        .proposal-title { text-align: center; font-size: 18px; font-weight: bold; margin: 10px 0 20px 0; text-transform: uppercase; }
        .items-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 20px; }
        .items-table th { background-color: #000; color: #fff; padding: 8px 6px; text-align: center; font-weight: bold; text-transform: uppercase; border: 1px solid #000; }
        .items-table td { border: 1px solid #000; padding: 8px 6px; text-align: center; vertical-align: middle; }
        .item-image { max-width: 120px; max-height: 110px; width: auto; height: auto; display: block; margin: 0 auto; }
        .summary-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 15px; }
        .summary-table td { padding: 6px; font-weight: bold; }
        .text-red { color: #cc0000; }
        .border-top { border-top: 1px solid #000; }
        .bottom-section { width: 100%; border-collapse: collapse; margin-top: 30px; font-size: 12px; page-break-inside: avoid; }
        .terms-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .terms-table td { padding: 4px 0; vertical-align: top; }
        .terms-label { font-weight: bold; width: 120px; }
    </style>
</head>
<body>

    <?php if (isset($is_draft) && $is_draft): ?>
        <div style="position: fixed; top: 40%; left: 5%; font-size: 75px; color: rgba(220, 38, 38, 0.15); transform: rotate(-45deg); z-index: -999; font-weight: 900; letter-spacing: 5px; pointer-events: none;">
            UNAPPROVED DRAFT
        </div>
    <?php endif; ?>

    <header>
        <div class="header-container">
            <?php if ($logoBase64): ?>
                <img src="<?= $logoBase64 ?>" alt="AM GROUP Logo" style="max-height: 80px; width: auto;">
            <?php else: ?>
                <span style="font-weight:bold; font-size: 24px;">LOGO</span>
            <?php endif; ?>
            
            <h1 class="header-title">AM GROUP Kitchen Equipment and Supplies, Inc.</h1>
            <p class="header-address">
                5/F Builders Center Bldg., 170 Salcedo St., Legaspi Village Makati City 1229 Philippines
            </p>
            <p class="header-contacts">
                *Telephone +632 7752 3091 &nbsp;&nbsp;&nbsp;&nbsp; *Mobile +63917 174 1082<br>
                *Email: <a href="mailto:info@amgroup.asia" class="header-link">info@amgroup.asia</a> &nbsp;&nbsp;&nbsp;&nbsp; *Website: www.amgroup.asia
            </p>
        </div>
        
        <hr style="border: 0; border-top: 1px solid #000; margin: 15px 0;">

        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                    <div class="section-title">CUSTOMER INFORMATION:</div>
                    <table class="info-table">
                        <tr><td class="info-label">Client Name:</td><td><?= htmlspecialchars($trans['client_name'] ?? '') ?></td></tr>
                        <tr><td class="info-label">Client Address:</td><td><?= htmlspecialchars($trans['client_address'] ?? '') ?></td></tr>
                        <tr><td class="info-label">Attention To:</td><td><?= htmlspecialchars($trans['attention_to'] ?? '') ?></td></tr>
                        <tr><td class="info-label">Client Email Address:</td><td><?= htmlspecialchars($trans['client_email'] ?? '') ?></td></tr>
                        <tr><td class="info-label">Contact Number:</td><td><?= htmlspecialchars($trans['client_contact'] ?? '') ?></td></tr>
                    </table>
                </td>
                
                <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                    <div class="section-title">TRANSACTION DETAILS:</div>
                    <table class="info-table">
                        <tr><td class="info-label">Date:</td><td><?= htmlspecialchars(date('d-M-y', strtotime($trans['quote_date'] ?? date('Y-m-d')))) ?></td></tr>
                        <tr><td class="info-label">Quotation No.:</td><td style="font-weight: bold;"><?= htmlspecialchars($trans['quotation_no'] ?? '') ?></td></tr>
                        <tr><td class="info-label">Payment Terms:</td><td style="white-space: pre-line;"><?= htmlspecialchars($trans['payment_terms'] ?? '') ?></td></tr>
                        <tr><td class="info-label">Validity Offer:</td><td><?= htmlspecialchars($trans['validity_date'] ?? '') ?></td></tr>
                        <tr><td class="info-label">ETA:</td><td><?= htmlspecialchars($trans['eta'] ?? '') ?></td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </header>

    <main>
        
        <div class="proposal-title">PROPOSAL FOR <?= htmlspecialchars(strtoupper($trans['proposal_purpose'] ?? '')) ?></div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 4%;">NO.</th>
                    <th style="width: 25%;">IMAGE</th>
                    <th style="width: 14%;">MODEL</th>
                    <th style="width: 10%;">BRAND</th>
                    <th style="width: 32%;">DESCRIPTION</th>
                    <th style="width: 4%;">QTY</th>
                    <th style="width: 11%; text-align: right; padding-right: 10px;">TOTAL (PHP)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $subtotal = 0;
                if (!empty($payload_items)) :
                    foreach ($payload_items as $index => $item) : 
                        
                        $itemImgBase64 = '';
                        $hasItemImage = false;
                        
                        if (!empty($item['picture'])) {
                            $itemImgPath = __DIR__ . '/../../images/machine_images/' . $item['picture'];
                            if (file_exists($itemImgPath)) {
                                $itemImgData = file_get_contents($itemImgPath);
                                $itemImgType = pathinfo($itemImgPath, PATHINFO_EXTENSION);
                                $itemImgBase64 = 'data:image/' . $itemImgType . ';base64,' . base64_encode($itemImgData);
                                $hasItemImage = true;
                            }
                        }
                        
                        $qty = (int)($item['qty'] ?? 1);
                        $price = (float)str_replace(',', '', $item['unit_price'] ?? 0);
                        $lineTotal = $qty * $price;
                        $subtotal += $lineTotal;
                ?>
                    <tr>
                        <td style="font-weight: bold;"><?= $index + 1 ?></td>
                        <td style="padding: 10px 5px;">
                            <?php if ($hasItemImage): ?>
                                <img src="<?= $itemImgBase64 ?>" class="item-image" alt="IMG">
                            <?php else: ?>
                                <span style="font-weight: bold; color: #666;">NO IMG</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight: bold;"><?= htmlspecialchars($item['model'] ?? '') ?></td>
                        <td style="font-weight: bold;"><?= htmlspecialchars($item['brand'] ?? '') ?></td>
                        <td style="text-align: left; padding: 10px 12px; white-space: pre-line; line-height: 1.4;">
                            <?= htmlspecialchars($item['description'] ?? '') ?>
                        </td>
                        <td style="font-weight: bold;"><?= $qty ?></td>
                        <td style="font-weight: bold; text-align: right; padding-right: 10px;">
                            <?= number_format($lineTotal, 2) ?>
                        </td>
                    </tr>
                <?php 
                    endforeach; 
                endif; 
                ?>
            </tbody>
        </table>

        <?php
            $discount = (float)str_replace(',', '', $trans['corporate_discount'] ?? 0);
            $netTotal = max(0, $subtotal - $discount);
        ?>
        <table class="summary-table">
            <tr>
                <td style="width: 75%; text-align: right; padding-right: 15px;">TOTAL AMOUNT PRICE :</td>
                <td style="width: 25%; text-align: right; padding-right: 10px;"><?= number_format($subtotal, 2) ?></td>
            </tr>
            <tr>
                <td class="text-red" style="width: 75%; text-align: right; padding-right: 15px;">LESS: SPECIAL CORPORATE DISCOUNT :</td>
                <td class="text-red" style="width: 25%; text-align: right; padding-right: 10px;">- <?= number_format($discount, 2) ?></td>
            </tr>
            <tr>
                <td class="border-top" style="width: 75%; text-align: right; padding-right: 15px;">TOTAL NET PRICE (VAT INCLUDED) :</td>
                <td class="border-top" style="width: 25%; text-align: right; padding-right: 10px;"><?= number_format($netTotal, 2) ?></td>
            </tr>
        </table>

        <table class="bottom-section">
            <tr>
                <td style="width: 60%; vertical-align: top;">
                    
                    <table class="terms-table">
                        <tr>
                            <td class="terms-label">Payment terms:</td>
                            <td style="white-space: pre-line;"><?= htmlspecialchars($trans['payment_terms'] ?? '') ?></td>
                        </tr>
                        <tr>
                            <td class="terms-label">Delivery:</td>
                            <td><?= htmlspecialchars($trans['eta'] ?? '') ?></td>
                        </tr>
                        <tr>
                            <td class="terms-label">Inclusion:</td>
                            <td style="white-space: pre-line;"><?= htmlspecialchars($trans['inclusions'] ?? '') ?></td>
                        </tr>
                    </table>

                    <div style="font-weight: bold; text-transform: uppercase; margin-top: 20px; margin-bottom: 5px;">OUR BANK DETAILS:</div>
                    <div style="margin-left: 20px; line-height: 1.4;">
                        ACCOUNT NAME: AM GROUP Kitchen Equipment and Supplies, Inc.<br>
                        ACCOUNT NO.: 1511-0078-24<br>
                        BANK DETAILS: Bank of the Philippine Islands<br>
                        BANK ADDRESS: 6781 Insular Life Makati Bldg., Ayala Ave., cor. Paseo de Roxas,<br>
                        Makati, 1000 Metro Manila, Philippines<br>
                        SWIFT CODE: BOPIPHMM
                    </div>

                    <div style="margin-top: 40px;">
                        <div style="border-top: 1px solid #000; display: inline-block; padding-top: 4px; min-width: 220px;">
                            Prepared by: <?= htmlspecialchars($trans['prepared_by'] ?? '') ?>
                        </div>
                    </div>

                </td>

                <td style="width: 40%; vertical-align: bottom; text-align: center; padding-left: 20px;">
                    <div style="font-style: italic; font-weight: bold; margin-bottom: 60px;">
                        Please confirm your order of this quote by signing this document.
                    </div>
                    <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 5px;">
                        Signature over printed name
                    </div>
                </td>
            </tr>
        </table>
    </main>

</body>
</html>