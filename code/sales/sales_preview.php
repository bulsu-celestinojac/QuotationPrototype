<?php
session_start();
require_once '../auth.php';
require_login();
require_once '../db.php';
require_once '../vendor/autoload.php';
require_once 'sales_validate.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF Protection ──
    if (!validateCSRF($_POST)) {
        die("Invalid security token. Please close this window, refresh the form, and try again.");
    }

    // ── Server-Side Validation ──
    $errors = validateQuotationInput($_POST);
    if (!empty($errors)) {
        echo "<!DOCTYPE html><html><head><title>Validation Error</title>";
        echo "<style>body{font-family:Arial,sans-serif;padding:40px;background:#f9f9f9;}";
        echo ".box{max-width:500px;margin:0 auto;background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:30px;box-shadow:0 4px 12px rgba(0,0,0,0.08);}";
        echo "h2{color:#8B1538;margin-bottom:16px;font-size:1.3rem;}";
        echo "ul{margin:0;padding-left:20px;color:#444;line-height:1.8;}";
        echo "a{display:inline-block;margin-top:20px;padding:10px 24px;background:#8B1538;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;}</style></head><body>";
        echo "<div class='box'><h2>⚠ Validation Failed</h2><ul>";
        foreach ($errors as $err) echo "<li>" . htmlspecialchars($err) . "</li>";
        echo "</ul><a href='javascript:window.close()'>Close Window</a></div></body></html>";
        exit;
    }

    // ── Sanitize Input ──
    $trans = sanitizeQuotationInput($_POST);
    $items = $_POST['items'] ?? [];

    $payload_items = [];
    $stmtItemFetch = $pdo->prepare("SELECT brand, model_no, description, picture FROM items WHERE id = ?");

    foreach ($items as $item) {
        $item_id      = (int)$item['id'];
        $qty          = (int)$item['qty'];
        $custom_price = (float)str_replace(',', '', $item['price'] ?? 0);

        $stmtItemFetch->execute([$item_id]);
        $machineData = $stmtItemFetch->fetch();

        if ($machineData) {
            $payload_items[] = [
                'brand'      => $machineData['brand'],
                'model'      => $machineData['model_no'],
                'description'=> $machineData['description'],
                'picture'    => $machineData['picture'],
                'qty'        => $qty,
                'unit_price' => $custom_price
            ];
        }
    }

    $pdf_template = __DIR__ . '/sales_template.php';

    // Clear any output buffers to prevent PDF corruption
    while (ob_get_level()) ob_end_clean();

    // ── Generate PDF with Dompdf ──
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('dpi', 150);

    $dompdf = new Dompdf($options);
    ob_start();
    
    // ENSURES WATERMARK IS ALWAYS ON FOR PREVIEWS
    $is_draft = true; 
    
    include $pdf_template;
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // ── Merge Quotation + T&C via FPDI ──
    $quotePdfOutput = $dompdf->output();
    $tempQuoteFile  = tempnam(sys_get_temp_dir(), 'quote_') . '.pdf';
    file_put_contents($tempQuoteFile, $quotePdfOutput);

    $pdf = new Fpdi();

    $pageCount = $pdf->setSourceFile($tempQuoteFile);
    for ($i = 1; $i <= $pageCount; $i++) {
        $templateId = $pdf->importPage($i);
        $size       = $pdf->getTemplateSize($templateId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);
    }

    $tc_path = __DIR__ . '/../../pdfs/terms&conditions/T&C.pdf';
    if (file_exists($tc_path)) {
        $pageCountTC = $pdf->setSourceFile($tc_path);
        for ($i = 1; $i <= $pageCountTC; $i++) {
            $templateIdTC = $pdf->importPage($i);
            $sizeTC       = $pdf->getTemplateSize($templateIdTC);
            $pdf->AddPage($sizeTC['orientation'], [$sizeTC['width'], $sizeTC['height']]);
            $pdf->useTemplate($templateIdTC);
        }
    }

    $pdf->Output('I', "PREVIEW_" . htmlspecialchars($trans['quotation_no'] ?? 'QUOTE') . ".pdf");

    if (file_exists($tempQuoteFile)) {
        unlink($tempQuoteFile);
    }

    exit;
}
?>