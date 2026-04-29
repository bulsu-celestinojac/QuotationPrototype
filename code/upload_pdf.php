<?php
session_start();
require 'db.php';
require 'functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Check
    if (!isset($_POST['token']) || !verify_csrf_token($_POST['token'])) {
        die("Security error: Invalid CSRF token.");
    }

    $item_id = (int)$_POST['item_id'];

    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $pdf_ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
        
        if ($pdf_ext === 'pdf') {
            $stmt = $pdo->prepare("SELECT brand, model_no FROM items WHERE id = ?");
            $stmt->execute([$item_id]);
            $item = $stmt->fetch();

            if ($item) {
                // Ensure proper folder structuring
                $brand = $item['brand'];
                $safe_brand = !empty($brand) ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $brand) : 'UNBRANDED';
                
                $model_no = $item['model_no'];
                $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
                if (empty($safe_model_no)) $safe_model_no = 'unnamed_model_' . time();
                
                $pdf_filename = $safe_model_no . '.pdf';
                $relative_pdf_path = $safe_brand . '/' . $pdf_filename;
                $pdf_target_dir = __DIR__ . '/../pdfs/machine_pdfs/' . $safe_brand . '/';
                
                // Creates Brand folder if it doesn't exist
                if (!is_dir($pdf_target_dir)) { 
                    mkdir($pdf_target_dir, 0755, true); 
                }
                
                $pdf_target_file = $pdf_target_dir . $pdf_filename;
                
                if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdf_target_file)) {
                    // Update Database
                    $updateStmt = $pdo->prepare("UPDATE items SET pdf_path = ? WHERE id = ?");
                    $updateStmt->execute([$relative_pdf_path, $item_id]);
                    set_flash_message('success', 'PDF documentation uploaded successfully.');
                } else {
                    set_flash_message('error', 'Failed to upload PDF file.');
                }
            }
        } else {
            set_flash_message('error', 'Invalid file type. Only PDFs are allowed.');
        }
    }
}

// Redirect back to dashboard seamlessly
header("Location: index.php");
exit;
?>