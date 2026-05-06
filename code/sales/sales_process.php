<?php
require '../db.php';
require '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $quote_type = $_POST['quote_type'] ?? 'sales'; 

    try {
        $pdo->beginTransaction();

        $payload_items = []; 
        $gross_total = 0;

        // ==========================================
        // SALES QUOTATION (PER PIECE)
        // ==========================================
        if ($quote_type === 'sales') {
            
            $items = $_POST['items'] ?? [];
            if (empty($items)) die("No items selected. Please go back and try again.");

            $trans = [
                'quotation_no'       => trim($_POST['quotation_no'] ?? ''),
                'client_name'        => trim($_POST['client_name'] ?? ''),
                'client_address'     => trim($_POST['client_address'] ?? ''),
                'attention_to'       => trim($_POST['attention_to'] ?? ''),
                'client_email'       => trim($_POST['client_email'] ?? ''), 
                'client_contact'     => trim($_POST['client_contact'] ?? ''),
                'quote_date'         => $_POST['quote_date'] ?? date('Y-m-d'),
                'payment_terms'      => trim($_POST['payment_terms'] ?? ''),
                'validity_date'      => $_POST['validity_date'] ?? '',
                'eta'                => trim($_POST['eta'] ?? ''),
                'proposal_purpose'   => trim($_POST['proposal_purpose'] ?? ''),
                'corporate_discount' => (float)($_POST['corporate_discount'] ?? 0),
                'prepared_by'        => trim($_POST['prepared_by'] ?? '')
            ];

            $stmtTrans = $pdo->prepare("
                INSERT INTO sales_quotations 
                (quotation_no, client_name, client_address, attention_to, client_email, client_contact, quote_date, payment_terms, validity_date, eta, proposal_purpose, corporate_discount, prepared_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtTrans->execute(array_values($trans));
            $quotation_id = $pdo->lastInsertId();

            $stmtItemInsert = $pdo->prepare("INSERT INTO sales_quotation_items (quotation_id, item_id, qty, unit_price, discount) VALUES (?, ?, ?, ?, ?)");
            $stmtItemFetch = $pdo->prepare("SELECT brand, model_no, description, picture, selling_price FROM items WHERE id = ?");

            foreach ($items as $item) {
                $item_id = (int)$item['id'];
                $qty = (int)$item['qty'];

                $stmtItemFetch->execute([$item_id]);
                $machineData = $stmtItemFetch->fetch();

                if ($machineData) {
                    $unit_price = $machineData['selling_price'];
                    $gross_total += ($qty * $unit_price);
                    
                    $stmtItemInsert->execute([$quotation_id, $item_id, $qty, $unit_price, 0]);
                    
                    $payload_items[] = [
                        'brand' => $machineData['brand'],
                        'model' => $machineData['model_no'],
                        'description' => $machineData['description'],
                        'picture' => $machineData['picture'], 
                        'qty' => $qty,
                        'unit_price' => $unit_price
                    ];
                }
            }
            
            $pdf_template = 'sales_template.php';
            $paper_size = 'A4'; // Sales quotes always A4

        } else {
            die("Invalid Quote Type.");
        }

        $pdo->commit();

        // GENERATE THE PDF
        $options = new Options();
        $options->set('isRemoteEnabled', true); 
        $options->set('dpi', 150); 
        $options->set('defaultFont', 'DejaVu Sans'); 
        
        $dompdf = new Dompdf($options);
        ob_start();
        include $pdf_template; // The template will now have access to $paper_size
        $html = ob_get_clean();

        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper_size, 'portrait');
        $dompdf->render();
        $dompdf->stream($trans['quotation_no'] . ".pdf", ["Attachment" => false]);
        exit;

} catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        
        // Safely grab the quotation number, falling back to POST data if $trans isn't defined yet
        $failed_quote_no = $trans['quotation_no'] ?? $_POST['quotation_no'] ?? 'Unknown';
        
        if ($e->getCode() == 23000) { 
            die("Error: Quotation number '" . $failed_quote_no . "' already exists."); 
        }
        die("System Error: " . $e->getMessage());
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>