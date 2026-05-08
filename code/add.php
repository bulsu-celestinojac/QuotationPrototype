<?php
require_once 'auth.php';
require_login(); // Any employee can add, but status depends on role
require 'db.php';
require_once 'functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = strtoupper(trim($_POST['brand'] ?? ''));
    $model_no = strtoupper(trim($_POST['model_no'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    
    $buying_currency = $_POST['buying_currency'] ?? 'PHP';
    $buying_cost = $_POST['buying_cost'] ?? 0;
    $factor = $_POST['factor'] ?? 1;
    $selling_price = $_POST['selling_price'] ?? 0;
    
    $picture = '';
    $pdf_path = null;

    if (!is_numeric($factor) || floatval($factor) <= 0) {
        $error = 'Factor must be a positive number greater than zero.';
    } else {
        // --- IMAGE UPLOAD LOGIC ---
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));
            $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
            if (empty($safe_model_no)) $safe_model_no = 'unnamed_' . time();
            
            $picture = $safe_model_no . '_' . time() . '.' . $ext;
            $target_file = __DIR__ . '/images/machine_images/' . $picture;
            
            move_uploaded_file($_FILES['picture']['tmp_name'], $target_file);
        }

        // --- HIERARCHY LOGIC ---
        // If an Admin/Super Admin adds it, it's immediately active. If an Employee adds it, it needs approval.
        $status = has_role(['admin', 'super_admin']) ? 'active' : 'pending_admin';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO items 
                (brand, model_no, description, buying_currency, buying_cost, factor, selling_price, picture, pdf_path, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $brand, $model_no, $description, $buying_currency, $buying_cost, 
                $factor, $selling_price, $picture, $pdf_path, $status
            ]);
            
            log_activity($pdo, 'ITEM_ADDED', "Added machine model: $model_no (Status: $status)");

            $msg = ($status === 'active') 
                ? "Machine successfully added to the active inventory." 
                : "Machine added successfully. It is currently pending Admin approval before it appears in inventory.";
                
            set_flash_message('success', $msg);
            header("Location: index.php");
            exit;
            
        } catch (Exception $e) {
            $error = "Failed to add item to database: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Machine - AM Group</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body style="background: #F8F6F5;">
    <div style="max-width: 800px; margin: 40px auto; padding: 40px; background: white; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); font-family: 'DM Sans', sans-serif;">
        <h1 style="text-align: left; margin-top: 0; font-family: 'Outfit', sans-serif;">Add <span style="color:#8B1538;">Machine</span></h1>
        
        <?php if ($error): ?>
            <div style="background: #FFF5F7; color: #8B1538; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 700;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!has_role(['admin', 'super_admin'])): ?>
            <div style="background: #F3F4F6; color: #4B5563; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem;">
                <strong>Employee Notice:</strong> Items you add will require Admin approval before appearing in the main inventory dashboard.
            </div>
        <?php endif; ?>

        <form action="add.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 20px;">
            <div>
                <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373;">Brand Name</label>
                <input type="text" name="brand" required style="width: 100%; text-transform: uppercase; padding: 12px; border-radius: 8px; border: 1px solid #E8D8D7;">
            </div>
            <div>
                <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373;">Model Number</label>
                <input type="text" name="model_no" required style="width: 100%; text-transform: uppercase; padding: 12px; border-radius: 8px; border: 1px solid #E8D8D7;">
            </div>
            <div>
                <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373;">Description</label>
                <textarea name="description" rows="4" style="width: 100%; padding: 12px; border: 1.5px solid #E8D8D7; border-radius: 8px;" required></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                <?php if (has_role(['admin', 'super_admin'])): ?>
                <div>
                    <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373;">Buying Cost (₱)</label>
                    <input type="number" step="0.01" name="buying_cost" value="0" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #E8D8D7;">
                </div>
                <div>
                    <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373;">Markup Factor</label>
                    <input type="number" step="0.01" name="factor" value="1.0" style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #E8D8D7;">
                </div>
                <?php endif; ?>
                <div>
                    <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8B1538;">Final Selling Price (₱)</label>
                    <input type="number" step="0.01" name="selling_price" value="0" required style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #8B1538; font-weight: bold;">
                </div>
            </div>

            <div>
                <label style="font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373;">Product Image (Optional)</label>
                <input type="file" name="picture" accept="image/*" style="width: 100%; padding: 10px; border: 1px dashed #E8D8D7; border-radius: 8px; background: #FAFAF9;">
            </div>

            <div style="display: flex; gap: 15px; margin-top: 20px;">
                <a href="index.php" style="padding: 14px 24px; text-decoration: none; color: #8C7373; font-weight: bold; font-family: 'Outfit', sans-serif;">Cancel</a>
                <button type="submit" style="flex: 1; padding: 14px; background: #8B1538; color: white; border: none; border-radius: 50px; font-weight: bold; font-family: 'Outfit', sans-serif; cursor: pointer; text-transform: uppercase;">Submit Item</button>
            </div>
        </form>
    </div>
</body>
</html>