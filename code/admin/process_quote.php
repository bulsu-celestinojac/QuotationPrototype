<?php
// admin/process_quote.php
require_once '../auth.php';
require_role(['admin', 'super_admin']);
require '../db.php';
require_once '../functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'modal_quote_action') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_error'] = "Security validation failed. Please try again.";
    } else {
        $quote_id = (int)$_POST['quote_id'];
        $type = $_POST['quote_type'];
        $submit_type = $_POST['submit_type'];

        if ($submit_type === 'decline') {
            $new_status = 'revision';
        } elseif ($submit_type === 'approve') {
            $new_status = 'pending_super'; // Routes to Super Admin
        } else {
            $new_status = 'pending_admin'; // Just saving changes, stays with Admin
        }
        
        $discount = isset($_POST['corporate_discount']) ? (float)str_replace(',', '', $_POST['corporate_discount']) : 0;
        $is_notified = ($submit_type !== 'save') ? 1 : 0;

        try {
            $pdo->beginTransaction();

            if ($type === 'sales') {
                $upd = $pdo->prepare("UPDATE sales_quotations SET client_name=?, client_address=?, attention_to=?, client_email=?, client_contact=?, proposal_purpose=?, payment_terms=?, validity_date=?, eta=?, corporate_discount=?, status=?, is_notified=? WHERE id=?");
                $upd->execute([
                    $_POST['client_name'], $_POST['client_address'], $_POST['attention_to'], $_POST['client_email'], $_POST['client_contact'], $_POST['proposal_purpose'], $_POST['payment_terms'], $_POST['validity_date'], $_POST['eta'], $discount, $new_status, $is_notified, $quote_id
                ]);

                if (isset($_POST['items'])) {
                    foreach ($_POST['items'] as $itemId => $itemData) {
                        $uPrice = (float)str_replace(',', '', $itemData['price']);
                        $qty = (int)$itemData['qty'];
                        $pdo->prepare("UPDATE sales_quotation_items SET unit_price=?, qty=? WHERE quotation_id=? AND item_id=?")->execute([$uPrice, $qty, $quote_id, $itemId]);

                        if ($submit_type === 'approve') {
                            $pdo->prepare("UPDATE items SET selling_price=? WHERE id=?")->execute([$uPrice, $itemId]);
                        }
                    }
                }
            } else {
                $upd = $pdo->prepare("UPDATE project_quotations SET project_name=?, project_location=?, attention_to=?, client_email=?, client_contact=?, proposal_purpose=?, payment_terms=?, validity_date=?, eta=?, corporate_discount=?, status=?, is_notified=? WHERE id=?");
                $upd->execute([
                    $_POST['client_name'], $_POST['client_address'], $_POST['attention_to'], $_POST['client_email'], $_POST['client_contact'], $_POST['proposal_purpose'], $_POST['payment_terms'], $_POST['validity_date'], $_POST['eta'], $discount, $new_status, $is_notified, $quote_id
                ]);

                if (isset($_POST['items'])) {
                    foreach ($_POST['items'] as $itemId => $itemData) {
                        $uPrice = (float)str_replace(',', '', $itemData['price']);
                        $qty = (int)$itemData['qty'];
                        $pdo->prepare("UPDATE project_quotation_items SET price=?, qty=? WHERE quotation_id=? AND item_id=?")->execute([$uPrice, $qty, $quote_id, $itemId]);
                    }
                }
            }

            $pdo->commit();
            
            if ($submit_type === 'save') $_SESSION['flash_success'] = "Changes successfully saved.";
            elseif ($submit_type === 'approve') $_SESSION['flash_success'] = "Quotation approved and sent to Super Admin.";
            else $_SESSION['flash_error'] = "Quotation declined and returned for revision.";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to process quotation: " . $e->getMessage();
        }
    }
}
header("Location: index.php");
exit;
?>