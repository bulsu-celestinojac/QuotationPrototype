<?php
session_start();
require 'db.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['user_role'] ?? '';
$user_id = $_SESSION['user_id'];

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = $_GET['id'] ?? null;
if (!$id) {
    die("Error: No Machine ID provided.");
}

$error = '';
$success = '';
$pending_msg = '';

// 1. Fetch Latest Live Data
$stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die("Error: Machine record not found.");
}

// 2. Process Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } else {
        $brand = trim($_POST['brand'] ?? '');
        $model_no = trim($_POST['model_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $buying_currency = $_POST['buying_currency'] ?? '';
        
        $buying_cost = str_replace(',', '', $_POST['buying_cost'] ?? '0');
        $factor = str_replace(',', '', $_POST['factor'] ?? '0');
        $selling_price = str_replace(',', '', $_POST['selling_price'] ?? '0');

        $picture = $item['picture'];
        $pdf_path = $item['pdf_path'];

        if (!is_numeric($factor) || floatval($factor) <= 0) {
            $error = 'Factor must be a positive number greater than zero.';
        } else {
            // --- PROCESS NEW IMAGE (IF UPLOADED) ---
            if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $ext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_extensions)) {
                    $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
                    $filename = $safe_model_no . '_' . time() . '.' . $ext;
                    $target_dir = __DIR__ . '/../images/machine_images/';
                    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                    if (move_uploaded_file($_FILES['picture']['tmp_name'], $target_dir . $filename)) $picture = $filename;
                }
            }

            // --- PROCESS NEW PDF (IF UPLOADED) ---
            if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                $pdf_ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
                if ($pdf_ext === 'pdf') {
                    $safe_brand = !empty($brand) ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $brand) : 'UNBRANDED';
                    $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
                    $pdf_filename = $safe_model_no . '_' . time() . '.pdf';
                    $relative_pdf_path = $safe_brand . '/' . $pdf_filename;
                    $pdf_target_dir = __DIR__ . '/../pdfs/machine_pdfs/' . $safe_brand . '/';
                    if (!is_dir($pdf_target_dir)) mkdir($pdf_target_dir, 0755, true);
                    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdf_target_dir . $pdf_filename)) $pdf_path = $relative_pdf_path;
                }
            }

            // --- THE APPROVAL ENGINE ---
            if (in_array($user_role, ['admin', 'super_admin'])) {
                $updateStmt = $pdo->prepare("UPDATE items SET brand = ?, model_no = ?, description = ?, buying_currency = ?, buying_cost = ?, factor = ?, selling_price = ?, picture = ?, pdf_path = ? WHERE id = ?");
                if ($updateStmt->execute([$brand, $model_no, $description, $buying_currency, $buying_cost, $factor, $selling_price, $picture, $pdf_path, $id])) {
                    $success = 'Machine record updated instantly.';
                    $stmt->execute([$id]); // Refresh data
                    $item = $stmt->fetch();
                } else {
                    $error = 'Failed to update the database.';
                }
            } else {
                $pendingStmt = $pdo->prepare("INSERT INTO pending_approvals (action_type, item_id, requested_by, brand, model_no, description, buying_currency, buying_cost, factor, selling_price, picture, pdf_path) VALUES ('edit', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($pendingStmt->execute([$id, $user_id, $brand, $model_no, $description, $buying_currency, $buying_cost, $factor, $selling_price, $picture, $pdf_path])) {
                    $pending_msg = 'Update submitted! Waiting for Admin approval.';
                } else {
                    $error = 'Failed to submit update request.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Update Machine - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Modernized Premium Palette */
            --bg: #F4F7F9; 
            --surface: #FFFFFF;
            --text-main: #0F172A; 
            --text-muted: #64748B; 
            --text-light: #94A3B8;
            --border: #E2E8F0;
            --maroon: #8B1538;
            --maroon-hover: #700E2B;
            --maroon-light: #FFF1F5;
            --danger: #EF4444;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --shadow-md: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            --shadow-lg: 0 24px 60px -10px rgba(0, 0, 0, 0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
        }

        /* ELEVATED MODAL CARD */
        .modal-card {
            background: var(--surface);
            width: 100%;
            max-width: 860px; 
            border-radius: 32px;
            padding: 0;
            position: relative;
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: var(--shadow-lg);
            margin: auto;
            overflow: hidden;
        }

        .modal-header {
            background: #F8FAFC;
            padding: 32px 40px 24px 40px;
            border-bottom: 1px solid var(--border);
            position: relative;
        }

        .modal-form-wrapper { padding: 32px 40px 40px 40px; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px 24px;
        }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }

        .modal-title { 
            font-family: 'Outfit', sans-serif; 
            font-size: 2rem; 
            font-weight: 900; 
            color: var(--maroon); 
            text-transform: uppercase;
            letter-spacing: -0.02em;
            line-height: 1;
        }

        .modal-subtitle { font-size: 0.9rem; color: var(--text-muted); margin-top: 8px; font-weight: 500; }

        /* CIRCULAR ROTATING CLOSE BUTTON */
        .close-btn {
            position: absolute;
            top: 24px;
            right: 24px;
            background: #F4F7F9;
            border: 1px solid var(--border);
            font-size: 20px;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            box-shadow: var(--shadow-sm);
        }
        .close-btn:hover { color: var(--surface); border-color: var(--danger); background: var(--danger); transform: rotate(90deg); }

        .section-label {
            grid-column: 1 / -1;
            font-family: 'Outfit', sans-serif;
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--text-main);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-bottom: 1px dashed var(--border);
            padding-bottom: 8px;
            margin-top: 16px;
            margin-bottom: 4px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-label svg { color: var(--maroon); }

        .form-group { display: flex; flex-direction: column; gap: 6px; position: relative; }

        label {
            font-size: 0.7rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }

        label.required::after {
            content: ' *';
            color: var(--maroon);
            font-weight: 900;
            font-size: 0.9rem;
        }

        .helper-text { font-size: 0.7rem; color: var(--text-light); font-style: italic; font-weight: 500;}

        /* PREMIUM ROUNDED INPUTS */
        input[type="text"], input[type="number"], select, textarea {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #F8FAFC;
            font-size: 0.95rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-main);
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
        }

        .compact-field { padding: 12px 14px; font-size: 0.9rem; }

        select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%237A102E' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 12px;
            padding-right: 36px;
        }

        textarea { resize: vertical; min-height: 80px; }

        input:focus:not([readonly]), textarea:focus, select:focus {
            border-color: var(--maroon);
            background: var(--surface);
            box-shadow: 0 0 0 4px var(--maroon-light);
            transform: translateY(-1px);
        }
        
        .input-readonly {
            background-color: var(--bg) !important;
            color: var(--maroon) !important;
            font-weight: 800;
            cursor: not-allowed;
            border-color: transparent !important;
        }

        .price-wrapper { position: relative; display: flex; align-items: center; }
        .price-wrapper::before {
            content: "₱";
            position: absolute;
            left: 14px;
            font-weight: 800;
            font-size: 1rem;
            color: var(--maroon);
            z-index: 1;
            pointer-events: none;
        }
        .price-wrapper input.compact-field { padding-left: 36px !important; }

        /* SLEEK DRAG & DROP ZONES */
        .file-drop-area {
            border: 2px dashed var(--border);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            background: #F8FAFC;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%; 
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
        }

        .file-drop-area svg { color: var(--text-light); transition: all 0.3s ease; width: 28px; height: 28px; }
        .file-drop-area:hover, .file-drop-area.is-active { background: var(--maroon-light); border-color: var(--maroon); border-style: solid;}
        .file-drop-area:hover svg { color: var(--maroon); transform: scale(1.1); }

        .file-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 1; }
        .file-msg { font-size: 0.8rem; color: var(--text-muted); position: relative; z-index: 1; transition: color 0.2s; font-weight: 600; }
        .file-drop-area.is-active .file-msg { color: var(--maroon); font-weight: 800; }

        .preview-container { position: absolute; inset: 0; z-index: 10; display: none; background: var(--surface); padding: 8px; border-radius: 14px; }
        .preview-img { width: 100%; height: 100%; border-radius: 8px; cursor: zoom-in; object-fit: contain; transition: transform 0.2s ease; }
        .remove-img-btn { position: absolute; top: 8px; right: 8px; background: var(--surface); color: var(--danger); border: 1px solid var(--border); border-radius: 50%; width: 28px; height: 28px; font-size: 0.9rem; font-weight: 900; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm); transition: all 0.2s ease; }
        .remove-img-btn:hover { background: var(--danger); color: var(--surface); transform: scale(1.1); border-color: var(--danger); }

        /* ACTION BUTTONS */
        .action-buttons { display: flex; gap: 16px; margin-top: 32px; }
        
        .btn-submit {
            flex: 1;
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%);
            color: white;
            height: 56px;
            border: none;
            border-radius: 16px;
            font-size: 0.95rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25);
        }
        .btn-submit:hover { filter: brightness(1.1); transform: translateY(-2px); box-shadow: 0 12px 25px rgba(139, 21, 56, 0.35); }

        .btn-cancel {
            flex: 1;
            background: var(--surface);
            color: var(--text-main);
            height: 56px;
            border: 1px solid var(--border);
            border-radius: 16px;
            font-size: 0.95rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        .btn-cancel:hover { background: #F8FAFC; border-color: var(--text-muted); transform: translateY(-2px); box-shadow: var(--shadow-md); }

        .alert { padding: 16px 24px; margin: 0 0 20px 0; font-size: 0.9rem; font-weight: 700; border-radius: 12px; }
        .alert-error { color: var(--maroon); background: var(--maroon-light); border-left: 4px solid var(--maroon); }
        .alert-success { color: #059669; background: #ECFDF5; border-left: 4px solid #10B981; }
        .alert-warning { color: #D97706; background: #FFFBEB; border-left: 4px solid #F59E0B; }

        .zoom-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
        .zoom-overlay.active { opacity: 1; pointer-events: all; }
        .zoom-overlay img { max-width: 90vw; max-height: 90vh; border-radius: 16px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2); transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .zoom-overlay.active img { transform: scale(1); }
        .zoom-close-btn { position: absolute; top: 24px; right: 32px; background: white; border: none; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; transition: all 0.2s ease; width: 48px; height: 48px; border-radius: 50%; box-shadow: var(--shadow-sm); display: flex; justify-content: center; align-items: center;}
        .zoom-close-btn:hover { color: var(--danger); transform: rotate(90deg); }

        @media (max-width: 768px) {
            body { padding: 20px 12px; }
            .form-grid { grid-template-columns: 1fr; gap: 16px; }
            .span-2, .span-4 { grid-column: 1 / -1; }
            .modal-card { border-radius: 24px; }
            .modal-form-wrapper { padding: 24px 20px; }
            .modal-header { padding: 24px 20px 16px 20px; border-radius: 24px 24px 0 0; }
            .modal-title { font-size: 1.6rem; }
            .close-btn { top: 20px; right: 20px; width: 36px; height: 36px; font-size: 16px; }
            .action-buttons { flex-direction: column; gap: 12px; }
            .btn-cancel, .btn-submit { height: 50px; }
            .file-drop-area { min-height: 100px; padding: 16px; }
        }
    </style>
</head>
<body>

    <div class="modal-card">
        <button class="close-btn" onclick="window.location.href='index.php'" title="Close">✕</button>
        
        <div class="modal-header">
            <h2 class="modal-title">Update Record</h2>
            <div class="modal-subtitle">Modify machine details in the inventory.</div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($pending_msg): ?><div class="alert alert-warning">⏳ <?php echo htmlspecialchars($pending_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <div class="modal-form-wrapper">
        <form method="post" enctype="multipart/form-data" id="updateMachineForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-grid">
                
                <div class="section-label span-4">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Basic Details
                </div>

                <div class="form-group span-2">
                    <label for="brand" class="required">Brand</label>
                    <input type="text" name="brand" id="brand" value="<?php echo htmlspecialchars($item['brand'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="form-group span-2">
                    <label for="model_no" class="required">Model Number</label>
                    <input type="text" name="model_no" id="model_no" value="<?php echo htmlspecialchars($item['model_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="form-group span-4">
                    <label for="description" class="required">Description</label>
                    <textarea name="description" id="description" required><?php echo htmlspecialchars($item['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="section-label span-4">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    Pricing Data
                </div>

                <div class="form-group">
                    <label for="buying_currency" class="required">Currency</label>
                    <select name="buying_currency" id="buying_currency" class="compact-field" required>
                        <option value="" disabled></option>
                        <option value="USD" <?php echo ($item['buying_currency'] === 'USD') ? 'selected' : ''; ?>>USD - US Dollar</option>
                        <option value="EUR" <?php echo ($item['buying_currency'] === 'EUR') ? 'selected' : ''; ?>>EUR - Euro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="buying_cost" class="required">Buying Cost</label>
                    <input type="text" name="buying_cost" id="buying_cost" class="compact-field" value="<?php echo number_format((float)($item['buying_cost'] ?? 0), 2); ?>" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label for="factor" class="required">Factor</label>
                    <input type="number" step="any" min="0.0001" name="factor" id="factor" class="compact-field" value="<?php echo htmlspecialchars($item['factor'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label for="selling_price" class="required">Selling Price</label>
                    <div class="price-wrapper">
                        <input type="text" name="selling_price" id="selling_price" class="input-readonly compact-field" value="<?php echo number_format((float)($item['selling_price'] ?? 0), 2); ?>" readonly required autocomplete="off">
                    </div>
                </div>

                <div class="section-label span-4">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                    Media & Documents
                </div>

                <div class="form-group span-2">
                    <label for="picture">Product Image</label>
                    
                    <?php 
                        $existing_img = '';
                        $show_preview = 'none';
                        $has_image_class = '';
                        if (!empty($item['picture']) && file_exists(__DIR__ . '/../images/machine_images/' . $item['picture'])) {
                            $existing_img = '../images/machine_images/' . htmlspecialchars($item['picture'], ENT_QUOTES, 'UTF-8');
                            $show_preview = 'block';
                            $has_image_class = 'is-active';
                        }
                    ?>
                    
                    <div class="file-drop-area <?= $has_image_class ?>" id="drop-area">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <span class="file-msg" id="file-msg">
                            <?php echo !empty($item['picture']) ? 'Replace existing image' : 'Drop or Paste Image'; ?>
                        </span>
                        
                        <div class="preview-container" id="preview-container" style="display: <?= $show_preview ?>;">
                            <img id="image-preview" class="preview-img" src="<?= $existing_img ?>" alt="Preview" title="Click to zoom">
                            <button type="button" class="remove-img-btn" id="remove-img-btn" title="Remove Image">✕</button>
                        </div>

                        <input type="file" name="picture" id="picture" class="file-input" accept="image/*">
                    </div>
                    <div class="helper-text">*Leave empty to keep current image.</div>
                </div>

                <div class="form-group span-2">
                    <label for="pdf_file">Specification PDF</label>
                    <div class="file-drop-area" id="pdf-drop-area">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span class="file-msg" id="pdf-file-msg">
                            <?php echo !empty($item['pdf_path']) ? 'Replace existing PDF' : 'Browse or Drop PDF'; ?>
                        </span>
                        <input type="file" name="pdf_file" id="pdf_file" class="file-input" accept="application/pdf">
                    </div>
                    <div class="helper-text">*Leave empty to keep current PDF.</div>
                </div>

            </div>

            <div class="action-buttons">
                <a href="index.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-submit">
                    <?php echo in_array($user_role, ['admin', 'super_admin']) ? 'Save Updates' : 'Submit Update for Approval'; ?>
                </button>
            </div>
        </form>
        </div>
    </div>

    <div class="zoom-overlay" id="zoom-overlay">
        <button class="zoom-close-btn" id="zoom-close">✕</button>
        <img id="zoomed-image" src="" alt="Zoomed Product">
    </div>

   <script>
        const costInput = document.getElementById('buying_cost');
        const factorInput = document.getElementById('factor');
        const priceInput = document.getElementById('selling_price');

        function unformat(val) { return parseFloat(val.toString().replace(/,/g, '')) || 0; }

        function calculatePrice() {
            const cost = unformat(costInput.value);
            const factor = parseFloat(factorInput.value) || 0;
            if (factor <= 0 && factorInput.value !== '') factorInput.setCustomValidity("Factor must be greater than 0");
            else factorInput.setCustomValidity("");

            const total = cost * factor;
            if (total > 0) priceInput.value = total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            else priceInput.value = '';
        }

        costInput.addEventListener('blur', function() {
            let val = unformat(this.value);
            if (val > 0) this.value = val.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            else this.value = '';
            calculatePrice();
        });

        costInput.addEventListener('focus', function() {
            let val = unformat(this.value);
            if (val > 0) this.value = val;
        });

        factorInput.addEventListener('input', calculatePrice);

        document.querySelectorAll('input[type="text"], textarea').forEach(input => {
            input.addEventListener('paste', function(e) {
                let pastedText = (e.clipboardData || window.clipboardData).getData('text');
                if (pastedText) {
                    e.preventDefault();
                    pastedText = pastedText.replace(/^"|"$/g, '').trim();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    this.value = this.value.substring(0, start) + pastedText + this.value.substring(end);
                    this.selectionStart = this.selectionEnd = start + pastedText.length;
                    this.dispatchEvent(new Event('input'));
                }
            });
        });

        const fileInput = document.getElementById('picture');
        const fileMsg = document.getElementById('file-msg');
        const dropArea = document.getElementById('drop-area');
        const previewContainer = document.getElementById('preview-container');
        const imagePreview = document.getElementById('image-preview');
        const removeBtn = document.getElementById('remove-img-btn');
        const zoomOverlay = document.getElementById('zoom-overlay');
        const zoomedImage = document.getElementById('zoomed-image');
        const zoomClose = document.getElementById('zoom-close');

        function resetImage() {
            fileInput.value = ''; 
            previewContainer.style.display = 'none';
            dropArea.classList.remove('is-active');
            imagePreview.src = '';
            fileMsg.innerHTML = "<?php echo !empty($item['picture']) ? 'Replace existing image' : 'Drop or Paste Image'; ?>";
        }

        removeBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); resetImage(); });
        
        imagePreview.addEventListener('click', function(e) { 
            e.preventDefault(); 
            e.stopPropagation(); 
            if(this.src && this.src !== window.location.href) {
                zoomedImage.src = this.src; 
                zoomOverlay.classList.add('active'); 
            }
        });
        
        zoomClose.addEventListener('click', () => zoomOverlay.classList.remove('active'));
        zoomOverlay.addEventListener('click', function(e) { if (e.target === this) zoomOverlay.classList.remove('active'); });

        document.addEventListener('paste', function(e) {
            const activeTag = document.activeElement ? document.activeElement.tagName : '';
            if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') return;
            const clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData) return;
            const items = clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                if (item.kind === 'file' && item.type.startsWith('image/')) {
                    e.preventDefault(); 
                    const blob = item.getAsFile();
                    const dataTransfer = new DataTransfer();
                    const file = new File([blob], "pasted_image_" + Date.now() + ".png", { type: blob.type });
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;
                    fileInput.dispatchEvent(new Event('change'));
                    dropArea.style.backgroundColor = 'var(--maroon-light)';
                    setTimeout(() => { dropArea.style.backgroundColor = ''; }, 200);
                    return; 
                }
            }
        });

        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                const file = this.files[0];
                dropArea.classList.add('is-active');
                const reader = new FileReader();
                reader.onload = function(e) { imagePreview.src = e.target.result; previewContainer.style.display = 'block'; }
                reader.readAsDataURL(file);
            } else resetImage();
        });

        const pdfInput = document.getElementById('pdf_file');
        const pdfMsg = document.getElementById('pdf-file-msg');
        const pdfDropArea = document.getElementById('pdf-drop-area');

        pdfInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                pdfDropArea.classList.add('is-active');
                pdfMsg.innerHTML = '📄 <strong>' + this.files[0].name + '</strong>';
            } else {
                pdfDropArea.classList.remove('is-active');
                pdfMsg.innerHTML = "<?php echo !empty($item['pdf_path']) ? 'Replace existing PDF' : 'Browse or Drop PDF'; ?>";
            }
        });
    </script>
</body>
</html>