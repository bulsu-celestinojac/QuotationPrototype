<?php
// admin/ajax_get_quote.php
require_once '../auth.php';
require_role(['admin', 'super_admin']);
require '../db.php';

if (isset($_POST['ajax_get_quote_details'])) {
    header('Content-Type: application/json');
    $quote_id = (int)$_POST['quote_id'];
    $type = $_POST['type'];

    try {
        if ($type === 'sales') {
            $stmt = $pdo->prepare("SELECT sq.*, u.full_name, u.username FROM sales_quotations sq LEFT JOIN users u ON sq.user_id = u.id WHERE sq.id = ?");
            $stmt->execute([$quote_id]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($quote) {
                $itemStmt = $pdo->prepare("SELECT sqi.*, i.brand, i.model_no, i.picture FROM sales_quotation_items sqi LEFT JOIN items i ON sqi.item_id = i.id WHERE sqi.quotation_id = ?");
                $itemStmt->execute([$quote_id]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'quote' => $quote, 'items' => $items]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Quote not found']);
            }
        } elseif ($type === 'project') {
            $stmt = $pdo->prepare("SELECT * FROM project_quotations WHERE id = ?");
            $stmt->execute([$quote_id]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($quote) {
                $itemStmt = $pdo->prepare("SELECT pqi.*, i.brand, i.model_no, i.picture FROM project_quotation_items pqi LEFT JOIN items i ON pqi.item_id = i.id WHERE pqi.quotation_id = ?");
                $itemStmt->execute([$quote_id]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'quote' => $quote, 'items' => $items]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Quote not found']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>