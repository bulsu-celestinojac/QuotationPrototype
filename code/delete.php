<?php
session_start();
require 'db.php';

// 1. CSRF Token Validation
if (!isset($_GET['token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
    die("Security error: Invalid CSRF token.");
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];

    // 2. Fetch both the image and pdf filenames
    $stmt = $pdo->prepare("SELECT picture, pdf_path FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if ($item) {
        // 3. Delete physical image file if it exists (Secured with basename)
        if (!empty($item['picture'])) {
            $safe_pic = basename($item['picture']);
            $picPath = __DIR__ . '/../images/machine_images/' . $safe_pic;
            if (file_exists($picPath)) {
                unlink($picPath);
            }
        }
        
        // 4. Delete physical PDF file if it exists (Secured against traversal)
        if (!empty($item['pdf_path'])) {
            // pdf_path contains a brand folder (e.g. "BrandName/file.pdf"), so we validate the path safely
            $realBase = realpath(__DIR__ . '/../pdfs/machine_pdfs/');
            $targetPath = realpath(__DIR__ . '/../pdfs/machine_pdfs/' . $item['pdf_path']);
            
            // Ensure the resolved target path actually starts with our intended base directory
            if ($targetPath && strpos($targetPath, $realBase) === 0 && file_exists($targetPath)) {
                unlink($targetPath);
            }
        }

        // 5. Delete the database record
        $deleteStmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
        $deleteStmt->execute([$id]);
    }
}

// Redirect back to the dashboard seamlessly
header("Location: index.php");
exit;
?>