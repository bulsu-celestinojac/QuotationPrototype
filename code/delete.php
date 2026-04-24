<?php
session_start();
require 'db.php';

// 1. CSRF Token Validation
if (!isset($_GET['token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
    die("Security error: Invalid CSRF token.");
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int)$_GET['id'];

    // 2. Fetch the image filename first to allow for physical deletion
    $stmt = $pdo->prepare("SELECT picture FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if ($item) {
        // 3. Delete physical file if it exists
        if (!empty($item['picture'])) {
            $filePath = __DIR__ . '/../images/machine_images/' . $item['picture'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // 4. Delete the database record
        $deleteStmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
        $deleteStmt->execute([$id]);
    }
}

// Redirect back to the dashboard seamlessly
header("Location: index.php");
exit;