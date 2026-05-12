<?php
session_start();
require_once 'auth.php';
// Allows any logged in user (Sales or Admin) to view the PDFs
require_login(); 
require 'db.php';
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$id = $_GET['id'] ?? null;
$type = $_GET['type'] ?? 'sales';

if (!$id) {
    die("Error: Quotation ID is missing.");
}

try {
    if ($type === 'sales') {
        $stmt = $pdo->prepare("SELECT * FROM sales_quotations WHERE id = ?");
        $stmt->execute([$id]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trans) {
            die("Error: Sales quotation not found in the database.");
        }

        $stmtItems = $pdo->prepare("
            SELECT 
                qi.qty, 
                qi.unit_price,
                i.brand, 
                i.model_no as model, 
                i.description, 
                i.picture 
            FROM sales_quotation_items qi
            JOIN items i ON qi.item_id = i.id
            WHERE qi.quotation_id = ?
        ");
        $stmtItems->execute([$id]);
        $payload_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $pdf_template = __DIR__ . '/sales_template.php'; 
        $paper_size = 'A4';

    } else {
        die("Error: Only Sales Quotes are supported right now.");
    }

    // ==========================================
    // GENERATE AND DISPLAY PDF
    // ==========================================

    // Clean any accidental blank spaces that corrupt PDFs
    if (ob_get_length()) {
        ob_end_clean();
    }

    $options = new Options();
    $options->set('isRemoteEnabled', true); 
    $options->set('dpi', 150); 
    $options->set('defaultFont', 'DejaVu Sans'); 
    
    $dompdf = new Dompdf($options);
    
    ob_start();
    include $pdf_template; 
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper($paper_size, 'portrait');
    $dompdf->render();
    
    // Output directly to the browser
    $dompdf->stream($trans['quotation_no'] . ".pdf", ["Attachment" => false]);
    exit;

} catch (Exception $e) {
    die("PDF Generation Error: " . $e->getMessage());
}
?>