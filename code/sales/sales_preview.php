<?php
session_start();
require_once '../auth.php';
require_login();
require_once '../db.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = $_POST['items'] ?? [];
    if (empty($items)) die("No items selected.");

    $trans = $_POST; 
    $payload_items = [];

    $stmtItemFetch = $pdo->prepare("SELECT brand, model_no, description, picture, selling_price FROM items WHERE id = ?");

    foreach ($items as $item) {
        $item_id = (int)$item['id'];
        $qty = (int)$item['qty'];
        $stmtItemFetch->execute([$item_id]);
        $machineData = $stmtItemFetch->fetch();

        if ($machineData) {
            $payload_items[] = [
                'brand' => $machineData['brand'],
                'model' => $machineData['model_no'],
                'description' => $machineData['description'],
                'picture' => $machineData['picture'], 
                'qty' => $qty,
                'unit_price' => $machineData['selling_price']
            ];
        }
    }
    
    $pdf_template = __DIR__ . '/sales_template.php';

    if (ob_get_length()) ob_end_clean(); 

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
    
    // Generates a temporary PREVIEW PDF
    $dompdf->stream("PREVIEW_" . $trans['quotation_no'] . ".pdf", ["Attachment" => false]);
    exit;
}
?>