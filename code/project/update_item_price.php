<?php
// Look UP to root
require_once '../auth.php';
require_role(['project', 'admin', 'super_admin']);

require '../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $model_no = trim($_POST['model_no'] ?? '');
    $selling_price = (float)($_POST['selling_price'] ?? 0);

    if (!empty($model_no)) {
        try {
            $stmt = $pdo->prepare("UPDATE items SET selling_price = ? WHERE model_no = ?");
            $success = $stmt->execute([$selling_price, $model_no]);
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Price updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update database.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Model number is missing.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
