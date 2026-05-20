<?php
session_start();
require_once '../auth.php';
require_login();
require_once '../db.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi; // Import the new PDF Merger library

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = $_POST['items'] ?? [];
    if (empty($items)) die("No items selected.");

    $trans = $_POST; 
    
    // Safely strip commas from the submitted corporate discount!
    $trans['corporate_discount'] = (float)str_replace(',', '', $_POST['corporate_discount'] ?? 0);
    
    $payload_items = [];
    $stmtItemFetch = $pdo->prepare("SELECT brand, model_no, description, picture FROM items WHERE id = ?");

    foreach ($items as $item) {
        $item_id = (int)$item['id'];
        $qty = (int)$item['qty'];
        
        // Safely strip commas from the custom entered item price!
        $custom_price = (float)str_replace(',', '', $item['price'] ?? 0);

        $stmtItemFetch->execute([$item_id]);
        $machineData = $stmtItemFetch->fetch();

        if ($machineData) {
            $payload_items[] = [
                'brand' => $machineData['brand'],
                'model' => $machineData['model_no'],
                'description' => $machineData['description'],
                'picture' => $machineData['picture'], 
                'qty' => $qty,
                'unit_price' => $custom_price
            ];
        }
    }
    
    $pdf_template = __DIR__ . '/sales_template.php';

    if (ob_get_length()) ob_end_clean(); 

    // Generate the Quotation using Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', true); 
    $options->set('dpi', 150); 
    
    $dompdf = new Dompdf($options);
    ob_start();
    include $pdf_template; 
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // =========================================================================
    // THE MERGE LOGIC (Stitching the Quote and T&C together)
    // =========================================================================
    
    // 1. Get the generated Quotation PDF as raw string data
    $quotePdfOutput = $dompdf->output();
    
    // 2. Save it to a temporary system file (FPDI requires actual files to read)
    $tempQuoteFile = tempnam(sys_get_temp_dir(), 'quote_') . '.pdf';
    file_put_contents($tempQuoteFile, $quotePdfOutput);
    
    // 3. Initialize the FPDI Merger
    $pdf = new Fpdi();
    
    // 4. Import and append the newly generated Quotation pages
    $pageCount = $pdf->setSourceFile($tempQuoteFile);
    for ($i = 1; $i <= $pageCount; $i++) {
        $templateId = $pdf->importPage($i);
        $size = $pdf->getTemplateSize($templateId);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);
    }
    
    // 5. Locate and append the official T&C PDF (From your screenshot path)
    $tc_path = __DIR__ . '/../../pdfs/terms&conditions/T&C.pdf';
    if (file_exists($tc_path)) {
        $pageCountTC = $pdf->setSourceFile($tc_path);
        for ($i = 1; $i <= $pageCountTC; $i++) {
            $templateIdTC = $pdf->importPage($i);
            $sizeTC = $pdf->getTemplateSize($templateIdTC);
            $pdf->AddPage($sizeTC['orientation'], [$sizeTC['width'], $sizeTC['height']]);
            $pdf->useTemplate($templateIdTC);
        }
    }

    // 6. Stream the final merged master document to the browser
    $pdf->Output('I', "PREVIEW_" . ($trans['quotation_no'] ?? 'QUOTE') . ".pdf");
    
    // 7. Clean up and delete the temporary quote file from the server
    if (file_exists($tempQuoteFile)) {
        unlink($tempQuoteFile);
    }
    
    exit;
}
?>  