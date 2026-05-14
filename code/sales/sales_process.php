<?php
session_start();
require_once '../auth.php';
require_login();
require_once '../db.php';
require_once '../functions.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $quote_type = $_POST['quote_type'] ?? 'sales'; 

    try {
        $pdo->beginTransaction();

        if ($quote_type === 'sales') {
            $items = $_POST['items'] ?? [];
            if (empty($items)) die("No items selected. Please go back and try again.");

            $user_id = $_SESSION['user_id'] ?? null;

            // Date comes in perfectly from the Date Picker (YYYY-MM-DD)
            $quote_date_input = $_POST['quote_date'] ?? date('Y-m-d');

            $trans = [
                'quotation_no'       => trim($_POST['quotation_no'] ?? ''),
                'client_name'        => trim($_POST['client_name'] ?? ''),
                'client_address'     => trim($_POST['client_address'] ?? ''),
                'attention_to'       => trim($_POST['attention_to'] ?? ''),
                'client_email'       => trim($_POST['client_email'] ?? ''), 
                'client_contact'     => trim($_POST['client_contact'] ?? ''),
                'quote_date'         => $quote_date_input, 
                'payment_terms'      => trim($_POST['payment_terms'] ?? ''),
                'validity_date'      => $_POST['validity_date'] ?? '',
                'eta'                => trim($_POST['eta'] ?? ''),
                'proposal_purpose'   => trim($_POST['proposal_purpose'] ?? ''),
                'corporate_discount' => (float)($_POST['corporate_discount'] ?? 0),
                'prepared_by'        => trim($_POST['prepared_by'] ?? ''),
                'inclusions'         => trim($_POST['inclusions'] ?? '')
            ];

            // Insert Quotation Data
            $stmtTrans = $pdo->prepare("
                INSERT INTO sales_quotations 
                (user_id, status, is_notified, quotation_no, client_name, client_address, attention_to, client_email, client_contact, quote_date, payment_terms, validity_date, eta, proposal_purpose, corporate_discount, prepared_by) 
                VALUES (?, 'pending_admin', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtTrans->execute([
                $user_id,
                $trans['quotation_no'], $trans['client_name'], $trans['client_address'], 
                $trans['attention_to'], $trans['client_email'], $trans['client_contact'], 
                $trans['quote_date'], $trans['payment_terms'], $trans['validity_date'], 
                $trans['eta'], $trans['proposal_purpose'], $trans['corporate_discount'], 
                $trans['prepared_by']
            ]);
            $quotation_id = $pdo->lastInsertId();

            $stmtItemInsert = $pdo->prepare("INSERT INTO sales_quotation_items (quotation_id, item_id, qty, unit_price, discount) VALUES (?, ?, ?, ?, ?)");
            $stmtItemFetch = $pdo->prepare("SELECT selling_price FROM items WHERE id = ?");

            foreach ($items as $item) {
                $item_id = (int)$item['id'];
                $qty = (int)$item['qty'];
                $stmtItemFetch->execute([$item_id]);
                $machineData = $stmtItemFetch->fetch();

                if ($machineData) {
                    $stmtItemInsert->execute([$quotation_id, $item_id, $qty, $machineData['selling_price'], 0]);
                }
            }
        } 

        log_activity($pdo, 'QUOTE_SUBMITTED', "User submitted Sales Quote #{$trans['quotation_no']} for Admin Approval.");
        $pdo->commit();

        $_SESSION['flash_success'] = "Success! Quotation #{$trans['quotation_no']} has been submitted to the Admin for approval.";
        header("Location: ../history.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        die("System Error: " . $e->getMessage());
    }
}
?>  