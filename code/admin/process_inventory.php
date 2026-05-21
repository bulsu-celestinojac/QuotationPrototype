<?php
// admin/process_inventory.php
require_once '../auth.php';
require_role(['admin', 'super_admin']);
require '../db.php';
require_once '../functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_inv', 'reject_inv'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['flash_error'] = "Security validation failed.";
    } else {
        $approval_id = $_POST['approval_id'] ?? null;
        $action = $_POST['action']; 

        if ($approval_id) {
            $stmt = $pdo->prepare("SELECT * FROM pending_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch();

            if ($pending) {
                if ($action === 'approve_inv') {
                    try {
                        $pdo->beginTransaction();
                        if ($pending['action_type'] === 'add') {
                            $insertStmt = $pdo->prepare("INSERT INTO items (brand, model_no, description, picture, buying_currency, buying_cost, factor, selling_price, pdf_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $insertStmt->execute([
                                $pending['brand'], $pending['model_no'], $pending['description'], 
                                $pending['picture'], $pending['buying_currency'], $pending['buying_cost'], 
                                $pending['factor'], $pending['selling_price'], $pending['pdf_path']
                            ]);
                            $_SESSION['flash_success'] = "New machine added to live inventory.";
                        } 
                        elseif ($pending['action_type'] === 'edit') {
                            $updateStmt = $pdo->prepare("UPDATE items SET brand = ?, model_no = ?, description = ?, buying_currency = ?, buying_cost = ?, factor = ?, selling_price = ?, picture = ?, pdf_path = ? WHERE id = ?");
                            $updateStmt->execute([
                                $pending['brand'], $pending['model_no'], $pending['description'], 
                                $pending['buying_currency'], $pending['buying_cost'], $pending['factor'], 
                                $pending['selling_price'], $pending['picture'], $pending['pdf_path'], 
                                $pending['item_id']
                            ]);
                            $_SESSION['flash_success'] = "Machine #{$pending['item_id']} updated in live inventory.";
                        }
                        $pdo->prepare("UPDATE pending_approvals SET status = 'approved' WHERE id = ?")->execute([$approval_id]);
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $_SESSION['flash_error'] = "Database error during approval.";
                    }
                } 
                elseif ($action === 'reject_inv') {
                    if ($pdo->prepare("UPDATE pending_approvals SET status = 'rejected' WHERE id = ?")->execute([$approval_id])) {
                        $_SESSION['flash_success'] = "Inventory request has been rejected.";
                    }
                }
            } else {
                $_SESSION['flash_error'] = "This inventory request no longer exists.";
            }
        }
    }
}
header("Location: index.php");
exit;
?>