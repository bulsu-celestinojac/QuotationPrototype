<?php
// generate_pdf.php (Place in your main 'code' folder)
session_start();
require_once 'auth.php';
// Security: Require the user to be logged in to view PDFs
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
        // 1. Fetch the main quotation details submitted by the Sales Team
        $stmt = $pdo->prepare("SELECT * FROM sales_quotations WHERE id = ?");
        $stmt->execute([$id]);
        $trans = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trans) {
            die("Error: Sales quotation not found in the database.");
        }

        // 2. Fetch the specific machines they added to this quote
        // We use an ALIAS (as model) to match exactly what your sales_template.php expects
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

        // 3. Point to your template folder
        $pdf_template = __DIR__ . '/sales/sales_template.php'; 
        $paper_size = 'A4';

    } elseif ($type === 'project') {
        // We will build this logic next when we tackle the Project Team
        die("Error: Project Quotation PDF generator is currently under construction.");
    } else {
        die("Error: Invalid quotation type.");
    }

    // ==========================================
    // 4. RENDER THE PDF
    // ==========================================
    $options = new Options();
    $options->set('isRemoteEnabled', true); // Required to load the AM Group Logo
    $options->set('dpi', 150); 
    $options->set('defaultFont', 'DejaVu Sans'); 
    
    $dompdf = new Dompdf($options);
    
    // Start output buffering to safely load the HTML template
    ob_start();
    include $pdf_template; // This loads your sales_template.php and passes $trans & $payload_items
    $html = ob_get_clean();

    $dompdf->loadHtml($html);
    $dompdf->setPaper($paper_size, 'portrait');
    $dompdf->render();
    
    // OUTPUT: "Attachment" => false forces the PDF to OPEN in the browser tab instead of downloading
    $dompdf->stream($trans['quotation_no'] . ".pdf", ["Attachment" => false]);
    exit;

} catch (Exception $e) {
    die("System Error: " . $e->getMessage());
}
?>