<?php
require_once 'auth.php';
require_login();
require 'db.php';
require_once 'functions.php';

$error = '';
$user_role = $_SESSION['user_role'] ?? 'sales';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die("Item not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = strtoupper(trim($_POST['brand'] ?? ''));
    $model_no = strtoupper(trim($_POST['model_no'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $selling_price = $_POST['selling_price'] ?? 0;
    
    // Admins/Purchasers can edit cost data, Sales only edits public data
    if (in_array($user_role, ['admin', 'super_admin', 'purchaser'])) {
        $buying_currency = $_POST['buying_currency'] ?? $item['buying_currency'];
        $buying_cost = $_POST['buying_cost'] ?? $item['buying_cost'];
        $factor = $_POST['factor'] ?? $item['factor'];
    } else {
        $buying_currency = $item['buying_currency'];
        $buying_cost = $item['buying_cost'];
        $factor = $item['factor'];
    }

    try {
        $updateStmt = $pdo->prepare("
            UPDATE items SET 
                brand = ?, model_no = ?, description = ?, 
                buying_currency = ?, buying_cost = ?, factor = ?, selling_price = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $brand, $model_no, $description, 
            $buying_currency, $buying_cost, $factor, $selling_price, 
            $id
        ]);
        
        set_flash_message('success', "Item #{$model_no} has been successfully updated.");
        header("Location: index.php");
        exit;
    } catch (Exception $e) {
        $error = 'Database Error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Item - AM Group</title>
    <style>
        body { font-family: 'DM Sans', sans-serif; background: #F8F6F5; padding: 40px; }
        .card { background: white; max-width: 800px; margin: 0 auto; padding: 40px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; font-size: 0.8rem; text-transform: uppercase; color: #8C7373; }
        input, textarea, select { width: 100%; padding: 12px; border: 1px solid #E8D8D7; border-radius: 8px; }
        .btn { background: #8B1538; color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color: #8B1538; margin-bottom: 30px;">Edit Machine: <?php echo htmlspecialchars($item['model_no']); ?></h2>
        <?php if ($error): ?><div style="color: red; margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        
        <form method="POST">
            <div class="form-group"><label>Brand</label><input type="text" name="brand" value="<?php echo htmlspecialchars($item['brand']); ?>" required></div>
            <div class="form-group"><label>Model Number</label><input type="text" name="model_no" value="<?php echo htmlspecialchars($item['model_no']); ?>" required></div>
            <div class="form-group"><label>Description</label><textarea name="description" rows="6" required><?php echo htmlspecialchars($item['description']); ?></textarea></div>
            
            <?php if (in_array($user_role, ['admin', 'super_admin', 'purchaser'])): ?>
            <div class="form-group"><label>Buying Currency</label><select name="buying_currency"><option value="PHP" <?php if($item['buying_currency']=='PHP') echo 'selected'; ?>>PHP</option><option value="USD" <?php if($item['buying_currency']=='USD') echo 'selected'; ?>>USD</option><option value="EUR" <?php if($item['buying_currency']=='EUR') echo 'selected'; ?>>EUR</option></select></div>
            <div class="form-group"><label>Buying Cost</label><input type="number" step="0.01" name="buying_cost" value="<?php echo htmlspecialchars($item['buying_cost']); ?>"></div>
            <div class="form-group"><label>Pricing Factor</label><input type="number" step="0.01" name="factor" value="<?php echo htmlspecialchars($item['factor']); ?>"></div>
            <?php endif; ?>
            
            <div class="form-group"><label>Selling Price (₱)</label><input type="number" step="0.01" name="selling_price" value="<?php echo htmlspecialchars($item['selling_price']); ?>" required></div>
            
            <button type="submit" class="btn">Update Database</button>
            <a href="index.php" style="margin-left: 20px; color: #8C7373; text-decoration: none;">Cancel</a>
        </form>
    </div>
</body>
</html>