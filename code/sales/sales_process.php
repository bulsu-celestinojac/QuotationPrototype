<?php
session_start();
require_once '../auth.php';
require_login();
require_once '../db.php';
require_once '../functions.php';
require_once 'sales_validate.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CSRF Protection ──
    if (!validateCSRF($_POST)) {
        $_SESSION['flash_error'] = "Security validation failed. Please try again.";
        header("Location: ../index.php");
        exit;
    }

    // ── Server-Side Validation ──
    $errors = validateQuotationInput($_POST);
    if (!empty($errors)) {
        $_SESSION['flash_error'] = "Validation Failed: " . implode(" | ", $errors);
        header("Location: ../index.php");
        exit;
    }

    $quote_type = $_POST['quote_type'] ?? 'sales';

    try {
        // Start strict transaction
        $pdo->beginTransaction();

        if ($quote_type === 'sales') {
            $items = $_POST['items'] ?? [];
            if (empty($items)) {
                throw new Exception("No items selected for quotation.");
            }

            $user_id = (int)($_SESSION['user_id'] ?? 0);

            // ── Sanitize All Input ──
            $trans = sanitizeQuotationInput($_POST);

            // ── Insert Quotation Header ──
            $stmtTrans = $pdo->prepare("
                INSERT INTO sales_quotations 
                (user_id, status, is_notified, quotation_no, client_name, client_address, 
                 attention_to, client_email, client_contact, quote_date, payment_terms, 
                 validity_date, eta, proposal_purpose, corporate_discount, prepared_by, inclusions) 
                VALUES (?, 'pending_admin', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmtTrans->execute([
                $user_id,
                $trans['quotation_no'],
                $trans['client_name'],
                $trans['client_address'],
                $trans['attention_to'],
                $trans['client_email'],
                $trans['client_contact'],
                $trans['quote_date'],
                $trans['payment_terms'],
                $trans['validity_date'],
                $trans['eta'],
                $trans['proposal_purpose'],
                $trans['corporate_discount'],
                $trans['prepared_by'],
                $trans['inclusions']
            ]);
            $quotation_id = $pdo->lastInsertId();

            // ── Insert Quotation Items ──
            $stmtItemInsert = $pdo->prepare("INSERT INTO sales_quotation_items (quotation_id, item_id, qty, unit_price, discount) VALUES (?, ?, ?, ?, ?)");
            $stmtItemFetch  = $pdo->prepare("SELECT id FROM items WHERE id = ?");

            foreach ($items as $item) {
                $item_id      = (int)$item['id'];
                $qty          = (int)$item['qty'];
                $custom_price = (float)str_replace(',', '', $item['price'] ?? 0);

                // Verify item exists before inserting
                $stmtItemFetch->execute([$item_id]);
                if ($stmtItemFetch->fetch()) {
                    $stmtItemInsert->execute([$quotation_id, $item_id, $qty, $custom_price, 0]);
                } else {
                    throw new Exception("Invalid item selected (ID: {$item_id}).");
                }
            }
        }

        log_activity($pdo, 'QUOTE_SUBMITTED', "User submitted Sales Quote #{$trans['quotation_no']} for Admin Approval.");
        
        // Commit if everything perfectly executed
        $pdo->commit();

        // Regenerate CSRF token after successful state-changing action
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $_SESSION['flash_success'] = "Success! Quotation #{$trans['quotation_no']} has been submitted to the Admin for approval.";
        header("Location: ../history.php");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { 
            $pdo->rollBack(); 
        }
        // Log the actual error internally, but show a clean error to the user
        error_log("Quotation Submission Error: " . $e->getMessage());
        $_SESSION['flash_error'] = "System Error: Unable to process quotation. Please try again.";
        header("Location: ../index.php");
        exit;
    }
}
?>