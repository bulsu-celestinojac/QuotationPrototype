<?php
session_start();
require_once 'auth.php';
require_login();
require_once 'db.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;

// ── Validate Request Parameters ──
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : '';

if ($id <= 0 || !in_array($type, ['sales', 'project'])) {
    die("Invalid request parameters.");
}

try {
    // ── Fetch Quotation Data ──
    if ($type === 'sales') {
        $stmt = $pdo->prepare("SELECT * FROM sales_quotations WHERE id = ?");
        $stmt->execute([$id]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trans) die("Sales quotation not found.");

        $stmtItems = $pdo->prepare("
            SELECT sqi.qty, sqi.unit_price, i.brand, i.model_no as model, i.description, i.picture 
            FROM sales_quotation_items sqi 
            JOIN items i ON sqi.item_id = i.id 
            WHERE sqi.quotation_id = ?
        ");
        $stmtItems->execute([$id]);
        $payload_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $template_path = __DIR__ . '/sales/sales_template.php';

    } else { // Project Type
        $stmt = $pdo->prepare("SELECT * FROM project_quotations WHERE id = ?");
        $stmt->execute([$id]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trans) die("Project quotation not found.");

        // Map project keys to match what the template expects
        $trans['client_name'] = $trans['project_name'] ?? '';
        $trans['client_address'] = $trans['project_location'] ?? '';

        $stmtItems = $pdo->prepare("
            SELECT pqi.qty, pqi.price as unit_price, i.brand, i.model_no as model, i.description, i.picture 
            FROM project_quotation_items pqi 
            JOIN items i ON pqi.item_id = i.id 
            WHERE pqi.quotation_id = ?
        ");
        $stmtItems->execute([$id]);
        $payload_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $template_path = __DIR__ . '/project/project_template.php';
        if (!file_exists($template_path)) {
            $template_path = __DIR__ . '/sales/sales_template.php'; 
        }
    }

    while (ob_get_level()) ob_end_clean();

    // ── Generate PDF with Dompdf ──
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('dpi', 150);

    $dompdf = new Dompdf($options);
    
    ob_start();
    
    // CHECKS DB STATUS: IF NOT APPROVED, ADD WATERMARK
    $is_draft = (isset($trans['status']) && $trans['status'] !== 'approved'); 
    
    include $template_path;
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // ── Save Dompdf output to a temporary file ──
    $quotePdfOutput = $dompdf->output();
    $tempQuoteFile  = tempnam(sys_get_temp_dir(), 'quote_') . '.pdf';
    file_put_contents($tempQuoteFile, $quotePdfOutput);

    // ── Initialize FPDI ──
    $pdf = new Fpdi();

    // ── Import the generated Quotation pages ──
    $pageCount = $pdf->setSourceFile($tempQuoteFile);
    for ($i = 1; $i <= $pageCount; $i++) {
        $templateId = $pdf->importPage($i);
        $size       = $pdf->getTemplateSize($templateId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);
    }

    // ── Append the Terms & Conditions PDF ──
    $tc_path = __DIR__ . '/../pdfs/terms&conditions/T&C.pdf';
    if (file_exists($tc_path)) {
        $pageCountTC = $pdf->setSourceFile($tc_path);
        for ($i = 1; $i <= $pageCountTC; $i++) {
            $templateIdTC = $pdf->importPage($i);
            $sizeTC       = $pdf->getTemplateSize($templateIdTC);
            $pdf->AddPage($sizeTC['orientation'], [$sizeTC['width'], $sizeTC['height']]);
            $pdf->useTemplate($templateIdTC);
        }
    } else {
        error_log("T&C PDF not found at: " . $tc_path);
    }

    // ── Output the merged PDF to the browser ──
    $fileName = isset($trans['quotation_no']) ? $trans['quotation_no'] : 'QUOTE';
    $pdf->Output('I', htmlspecialchars($fileName) . ".pdf");

    if (file_exists($tempQuoteFile)) {
        unlink($tempQuoteFile);
    }

    exit;

} catch (Exception $e) {
    die("An error occurred while generating the PDF: " . htmlspecialchars($e->getMessage()));
}
?>