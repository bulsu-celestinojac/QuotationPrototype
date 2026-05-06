<?php
require '../db.php';
require '../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $quote_type = $_POST['quote_type'] ?? 'project'; 

    try {
        $pdo->beginTransaction();

        $payload_items = []; 
        $gross_total = 0;

        if ($quote_type === 'project') {
            
            $items = json_decode($_POST['items_json'] ?? '[]', true);
            if (empty($items)) die("No project items received. Please try again.");

            $trans = [
                'quotation_no'       => trim($_POST['quotation_no'] ?? ''),
                'project_name'       => strtoupper(trim($_POST['project_name'] ?? 'EQUIPMENT OFFER')),
                'company_name'       => strtoupper(trim($_POST['company_name'] ?? '')),
                'contact_name'       => trim($_POST['contact_name'] ?? ''),
                'email'              => trim($_POST['email'] ?? ''),
                'position'           => trim($_POST['position'] ?? ''),
                'client_address'     => trim($_POST['client_address'] ?? ''),
                'quote_date'         => $_POST['quote_date'] ?? date('Y-m-d'),
                'offer_validity'     => trim($_POST['offer_validity'] ?? ''),
                'mode_of_dispatch'   => trim($_POST['mode_of_dispatch'] ?? ''),
                'package_type'       => trim($_POST['package_type'] ?? ''),
                'delivery_arrangements' => trim($_POST['delivery_arrangements'] ?? ''),
                'payment_terms'      => trim($_POST['payment_terms'] ?? ''),
                'inclusions'         => trim($_POST['inclusions'] ?? ''),
                'discount_amount'    => (float)($_POST['discount_amount'] ?? 0),
                'prepared_by'        => trim($_POST['prepared_by'] ?? '')
            ];

            $paper_size = strtoupper($_POST['paper_size'] ?? 'A4');
            if (!in_array($paper_size, ['A4', 'A3'])) {
                $paper_size = 'A4'; 
            }

            $stmtTrans = $pdo->prepare("
                INSERT INTO project_quotations 
                (quotation_no, company_name, contact_name, email, position, client_address, project_name, quote_date, offer_validity, mode_of_dispatch, package_type, delivery_arrangements, payment_terms, inclusions, discount_amount, prepared_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmtTrans->execute([
                $trans['quotation_no'], $trans['company_name'], $trans['contact_name'], $trans['email'], $trans['position'], $trans['client_address'],
                $trans['project_name'], $trans['quote_date'], $trans['offer_validity'], $trans['mode_of_dispatch'], $trans['package_type'], 
                $trans['delivery_arrangements'], $trans['payment_terms'], $trans['inclusions'], $trans['discount_amount'], $trans['prepared_by']
            ]);
            $project_id = $pdo->lastInsertId();

            $stmtItemInsert = $pdo->prepare("INSERT INTO project_quotation_items (project_id, mark, brand, model_no, description, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)");

            foreach ($items as $item) {
                $qty = (int)$item['qty'];
                $unit_price = (float)$item['unit_price'];
                $gross_total += ($qty * $unit_price);

                $stmtItemInsert->execute([
                    $project_id, $item['mark'], $item['brand'], $item['model'], $item['description'], $qty, $unit_price
                ]);

                $payload_items[] = [
                    'mark' => $item['mark'],
                    'brand' => $item['brand'],
                    'model' => $item['model'],
                    'description' => $item['description'],
                    'picture' => $item['picture'] ?? '', 
                    'qty' => $qty,
                    'unit_price' => $unit_price
                ];
            }

            $stmtTotal = $pdo->prepare("UPDATE project_quotations SET total_amount = ? WHERE id = ?");
            $stmtTotal->execute([$gross_total, $project_id]);

            $pdf_template = 'project_template.php';

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
        include $pdf_template; 
        $html = ob_get_clean();

        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper_size, 'portrait');
        $dompdf->render();
        $dompdf->stream($trans['quotation_no'] . ".pdf", ["Attachment" => false]);
        exit;

} catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        
        $failed_quote_no = $trans['quotation_no'] ?? $_POST['quotation_no'] ?? 'Unknown';
        
        if ($e->getCode() == 23000) { 
            die("Error: Quotation number '" . $failed_quote_no . "' already exists."); 
        }
        die("System Error: " . $e->getMessage());
    }
} else {
    header("Location: parser.php");
    exit;
}
?>