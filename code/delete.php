<?php
session_start();
require_once 'auth.php';
require_role(['admin', 'super_admin']);

require 'db.php';
require 'functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// 1. CSRF Token Validation
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    die("Security error: Invalid CSRF token.");
}

if (isset($_POST['id']) && is_numeric($_POST['id'])) {
    $id = (int)$_POST['id'];

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
