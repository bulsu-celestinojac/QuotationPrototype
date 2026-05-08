<?php
require 'db.php';

$stmt = $pdo->query("SELECT id, brand, model_no, pdf_path, status FROM items");
$items = $stmt->fetchAll();

$successCount = 0;
$notFoundCount = 0;
$missingFolders = 0;

echo "<h2>Starting BULLETPROOF PDF Synchronization...</h2>";

foreach ($items as $item) {
    if (!empty($item['pdf_path'])) {
        continue; 
    }

    $id = $item['id'];
    $model_no = $item['model_no']; 
    $status = strtoupper($item['status']);
    
    $safe_brand = preg_replace('/[^A-Za-z0-9_\- ]/', '_', trim($item['brand']));
    if (empty($safe_brand)) $safe_brand = 'Unbranded';
    
    // The correct path stepping out of the 'code' folder into 'pdfs'
    $brand_dir = __DIR__ . '/../pdfs/machine_pdfs/' . $safe_brand . '/'; 
    
    // Check if the brand folder even exists
    if (!is_dir($brand_dir)) {
        echo "<p style='color: orange;'>Folder Missing: Cannot find a folder named <strong>$safe_brand</strong></p>";
        $missingFolders++;
        continue;
    }

    // --- BULLETPROOF MATCHING LOGIC ---
    // 1. Strip EVERYTHING except letters and numbers from the database model
    $clean_db_model = strtolower(preg_replace('/[^a-z0-9]/i', '', $model_no));

    $files_in_folder = scandir($brand_dir);
    $file_found = false;
    $old_filepath = '';
    $matched_name = '';

    // 2. Loop through every physical file in that brand's folder
    foreach ($files_in_folder as $file) {
        if ($file === '.' || $file === '..') continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') continue; // Only check PDFs

        $filename_only = pathinfo($file, PATHINFO_FILENAME);
        
        // 3. Strip EVERYTHING except letters and numbers from the physical file
        $clean_file_name = strtolower(preg_replace('/[^a-z0-9]/i', '', $filename_only));

        // 4. Compare the stripped versions. If they match, we found our file!
        if ($clean_db_model === $clean_file_name) {
            $file_found = true;
            $old_filepath = $brand_dir . $file;
            $matched_name = $file;
            break;
        }
    }

    if ($file_found) {
        // Standardize the final filename (e.g., F70_70LA_P.pdf)
        $safe_model = preg_replace('/[^A-Za-z0-9_\- ]/', '_', trim($model_no));
        $new_filename = $safe_model . '.pdf';
        $new_filepath = $brand_dir . $new_filename;
        
        $db_pdf_path = $safe_brand . '/' . $new_filename;

        // Rename the file to standard
        if ($old_filepath !== $new_filepath) {
            rename($old_filepath, $new_filepath);
        }

        // Update the database
        $updateStmt = $pdo->prepare("UPDATE items SET pdf_path = ? WHERE id = ?");
        $updateStmt->execute([$db_pdf_path, $id]);
        
        echo "<p style='color: green;'>Linked [{$status}]: <strong>$model_no</strong> -> Found matching file: <em>$matched_name</em></p>";
        $successCount++;
    } else {
        echo "<p style='color: red;'>Missing: Looked in <strong>$safe_brand</strong> folder but could not find a match for <strong>$model_no</strong></p>";
        $notFoundCount++;
    }
}

echo "<h3>Sync Complete!</h3>";
echo "<p>Successfully linked: <strong>$successCount</strong> files.</p>";
echo "<p>Missing files: <strong>$notFoundCount</strong>.</p>";
if ($missingFolders > 0) {
    echo "<p>Missing Brand Folders: <strong>$missingFolders</strong>.</p>";
}
?>