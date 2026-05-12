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

// Generate CSRF Token for Security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =========================================================================
// 1. AJAX ENDPOINT: REAL-TIME DUPLICATE DETECTOR
// =========================================================================
if (isset($_POST['ajax_check_model'])) {
    header('Content-Type: application/json');
    $modelToCheck = trim($_POST['model_no'] ?? '');
    
    $stmt = $pdo->prepare("SELECT brand FROM items WHERE model_no = ?");
    $stmt->execute([$modelToCheck]);
    $rows = $stmt->fetchAll();
    
    if (count($rows) > 0) {
        $brands = array_unique(array_column($rows, 'brand'));
        echo json_encode(['exists' => true, 'brands' => $brands]);
    } else {
        echo json_encode(['exists' => false]);
    }
    exit; 
}
// =========================================================================

$error = '';
$success = '';
$pending_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_model'])) {
    
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
        
        $picture = '';
        $target_file = '';
        $pdf_path = null;

        if (!is_numeric($factor) || floatval($factor) <= 0) {
            $error = 'Factor must be a positive number greater than zero.';
        } else {
            // --- IMAGE UPLOAD ---
            if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $_FILES['picture']['tmp_name']);
                $ext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed_extensions) || !in_array($mime_type, $allowed_mime_types)) {
                    $error = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
                } else {
                    $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
                    if (empty($safe_model_no)) $safe_model_no = 'unnamed_model_' . time();
                    
                    $filename = $safe_model_no . '_' . time() . '.' . $ext;
                    $target_dir = __DIR__ . '/../images/machine_images/';
                    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                    
                    $target_file = $target_dir . $filename;
                    if (move_uploaded_file($_FILES['picture']['tmp_name'], $target_file)) {
                        $picture = $filename;
                    } else {
                        $error = 'Image upload failed. Please check folder permissions.';
                    }
                }
            }

            // --- PDF UPLOAD ---
            if (!$error && isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                $pdf_ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
                if ($pdf_ext !== 'pdf') {
                    $error = 'Invalid document type. Only PDF files are allowed.';
                } else {
                    $safe_brand = !empty($brand) ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $brand) : 'UNBRANDED';
                    $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
                    if (empty($safe_model_no)) $safe_model_no = 'unnamed_model_' . time();
                    
                    $pdf_filename = $safe_model_no . '_' . time() . '.pdf';
                    $relative_pdf_path = $safe_brand . '/' . $pdf_filename;
                    $pdf_target_dir = __DIR__ . '/../pdfs/machine_pdfs/' . $safe_brand . '/';
                    if (!is_dir($pdf_target_dir)) mkdir($pdf_target_dir, 0755, true);
                    
                    $pdf_target_file = $pdf_target_dir . $pdf_filename;
                    if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdf_target_file)) {
                        $pdf_path = $relative_pdf_path;
                    } else {
                        $error = 'PDF upload failed. Please check folder permissions.';
                    }
                }
            }

            // --- SMART DB INSERT (APPROVAL ENGINE) ---
            if (!$error) {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE brand = ? AND model_no = ?");
                $checkStmt->execute([$brand, $model_no]);
                
                if ($checkStmt->fetchColumn() > 0) {
                    $error = "The machine '{$brand} - {$model_no}' already exists in the live inventory.";
                    if ($picture && file_exists($target_file)) unlink($target_file);
                    if ($pdf_path && file_exists(__DIR__ . '/../pdfs/machine_pdfs/' . $pdf_path)) unlink(__DIR__ . '/../pdfs/machine_pdfs/' . $pdf_path);
                } else {
                    if (in_array($user_role, ['admin', 'super_admin'])) {
                        $stmt = $pdo->prepare("INSERT INTO items (brand, model_no, description, picture, buying_currency, buying_cost, factor, selling_price, pdf_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$brand, $model_no, $description, $picture, $buying_currency, $buying_cost, $factor, $selling_price, $pdf_path])) {
                            $success = 'Machine added successfully to Live Inventory!';
                        } else {
                            $error = 'Database error occurred.';
                        }
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO pending_approvals (action_type, item_id, requested_by, brand, model_no, description, picture, buying_currency, buying_cost, factor, selling_price, pdf_path) VALUES ('add', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$user_id, $brand, $model_no, $description, $picture, $buying_currency, $buying_cost, $factor, $selling_price, $pdf_path])) {
                            $pending_msg = 'New Machine submitted! Waiting for Admin approval.';
                        } else {
                            $error = 'Failed to submit approval request.';
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Add Machine - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F8F6F5;
            --surface: #FFFFFF;
            --text-main: #2A0808;
            --text-muted: #8C7373;
            --border: #E8D8D7;
            --maroon: #7A102E; 
            --maroon-hover: #5A081E;
            --maroon-light: #FAF5F6;
            --danger: #DC2626;
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
            padding: 24px 20px;
        }

        .modal-card {
            background: var(--surface);
            width: 100%;
            max-width: 820px; 
            border-radius: 16px;
            padding: 0;
            position: relative;
            border: 1px solid var(--border);
            box-shadow: 0 12px 32px rgba(122, 16, 46, 0.08);
            margin: auto;
        }

        .modal-header {
            background: var(--surface);
            padding: 24px 32px 16px 32px;
            border-radius: 16px 16px 0 0;
            position: relative;
            border-bottom: 1px solid var(--border);
        }

        .modal-form-wrapper { padding: 20px 32px 32px 32px; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px 16px;
        }
        .span-2 { grid-column: span 2; }
        .span-4 { grid-column: 1 / -1; }

        .modal-title { 
            font-family: 'Outfit', sans-serif; 
            font-size: 1.8rem; 
            font-weight: 900; 
            color: var(--maroon); 
            text-transform: uppercase;
            letter-spacing: -0.02em;
            line-height: 1;
        }

        .modal-subtitle { font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; font-weight: 500; }

        .close-btn {
            position: absolute;
            top: 24px;
            right: 28px;
            background: transparent;
            border: none;
            font-size: 1.4rem;
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.2s ease;
            z-index: 20;
        }
        .close-btn:hover { color: var(--maroon); }

        .section-label {
            grid-column: 1 / -1;
            font-family: 'Outfit', sans-serif;
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--maroon);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--maroon-light);
            padding-bottom: 4px;
            margin-top: 10px;
            margin-bottom: 0px; 
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group { display: flex; flex-direction: column; gap: 4px; position: relative; }

        label {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }

        .helper-text { font-size: 0.65rem; color: var(--text-muted); font-style: italic; }

        .duplicate-warning {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            z-index: 10;
            color: var(--danger);
            font-size: 0.7rem;
            font-weight: 700;
            background: #FEF2F2;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #FECACA;
            margin-top: 4px;
            display: none;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
            animation: fadeIn 0.2s ease;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        input[type="text"], input[type="number"], select, textarea {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #F8F6F6;
            font-size: 0.9rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-main);
            transition: all 0.2s ease;
            outline: none;
        }

        .compact-field { padding: 8px 10px; font-size: 0.85rem; }

        select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%237A102E' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 10px;
            padding-right: 28px;
        }

        textarea { resize: vertical; min-height: 60px; }

        input:focus:not([readonly]), textarea:focus, select:focus {
            border-color: var(--maroon);
            background: var(--surface);
            box-shadow: 0 4px 10px rgba(122, 16, 46, 0.08);
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
            left: 10px;
            font-weight: 800;
            font-size: 0.9rem;
            color: var(--maroon);
            z-index: 1;
            pointer-events: none;
        }
        .price-wrapper input.compact-field { padding-left: 28px !important; }

        .file-drop-area {
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            background: #F8F6F6;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            height: 100%; 
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 6px;
        }

        .file-drop-area svg { color: #C8BDBD; transition: color 0.3s ease; width: 20px; height: 20px; }
        .file-drop-area:hover, .file-drop-area.is-active { background: var(--maroon-light); border-color: var(--maroon); }
        .file-drop-area:hover svg { color: var(--maroon); }

        .file-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 1; }
        .file-msg { font-size: 0.75rem; color: var(--text-muted); position: relative; z-index: 1; transition: color 0.2s; font-weight: 500; }
        .file-drop-area.is-active .file-msg { color: var(--maroon); font-weight: 700; }

        .preview-container { position: absolute; inset: 0; z-index: 10; display: none; background: var(--surface); padding: 4px; border-radius: 8px; }
        .preview-img { width: 100%; height: 100%; border-radius: 4px; cursor: zoom-in; object-fit: contain; transition: transform 0.2s ease; }
        .remove-img-btn { position: absolute; top: 4px; right: 4px; background: var(--surface); color: var(--maroon); border: 1px solid var(--border); border-radius: 50%; width: 20px; height: 20px; font-size: 0.7rem; font-weight: 900; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1); transition: all 0.2s ease; }
        .remove-img-btn:hover { background: var(--maroon); color: var(--surface); transform: scale(1.1); }

        .action-buttons { display: flex; gap: 12px; margin-top: 24px; }
        
        .btn-submit {
            flex: 1;
            background: var(--maroon);
            color: white;
            height: 48px;
            border: none;
            border-radius: 12px;
            font-size: 0.9rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 6px 16px rgba(122, 16, 46, 0.2);
        }
        .btn-submit:hover { background: var(--maroon-hover); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(122, 16, 46, 0.3); }

        .btn-cancel {
            flex: 1;
            background: #F8F6F6;
            color: var(--text-muted);
            height: 48px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 0.9rem;
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
        }
        .btn-cancel:hover { background: var(--border); color: var(--text-main); }

        .alert { padding: 12px 24px; margin: 0; font-size: 0.85rem; font-weight: 700; border-bottom: 1px solid var(--border); }
        .alert-error { color: var(--maroon); background: var(--maroon-light); }
        .alert-success { color: #166534; background: #F0FDF4; }
        .alert-warning { color: #9A6324; background: #FFF8DC; border-color: #F5DEB3; }

        .custom-dropdown { position: absolute; top: calc(100% + 4px); left: 0; width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; max-height: 180px; overflow-y: auto; z-index: 9999; box-shadow: 0 8px 20px rgba(122, 16, 46, 0.1); display: none; }
        .custom-dropdown.active { display: block; }
        .custom-dropdown-item { padding: 8px 12px; font-size: 0.8rem; color: var(--text-main); cursor: pointer; transition: all 0.2s ease; }
        .custom-dropdown-item:hover { background: var(--maroon-light); color: var(--maroon); font-weight: 500; }

        .zoom-overlay { position: fixed; inset: 0; background: rgba(248, 246, 245, 0.95); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
        .zoom-overlay.active { opacity: 1; pointer-events: all; }
        .zoom-overlay img { max-width: 90vw; max-height: 90vh; border-radius: 12px; box-shadow: 0 20px 40px rgba(122, 16, 46, 0.15); transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .zoom-overlay.active img { transform: scale(1); }
        .zoom-close-btn { position: absolute; top: 24px; right: 32px; background: transparent; border: none; font-size: 1.8rem; color: var(--text-muted); cursor: pointer; transition: color 0.2s ease; }
        .zoom-close-btn:hover { color: var(--maroon); }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; gap: 12px; }
            .span-2, .span-4 { grid-column: 1 / -1; }
            .modal-form-wrapper { padding: 20px 24px; }
            .modal-header { padding: 20px 24px 14px 24px; }
            .close-btn { top: 18px; right: 20px; }
            .action-buttons { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>

    <div class="modal-card">
        <button class="close-btn" onclick="window.location.href='index.php'" title="Close Dashboard">✕</button>
        
        <div class="modal-header">
            <h2 class="modal-title">New Record</h2>
            <div class="modal-subtitle">Add a machine to the inventory.</div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php if ($pending_msg): ?><div class="alert alert-warning">⏳ <?php echo htmlspecialchars($pending_msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

        <div class="modal-form-wrapper">
        <form method="post" enctype="multipart/form-data" id="machineForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-grid">
                
                <div class="section-label span-4">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Basic Details
                </div>

                <div class="form-group span-2" style="position: relative;">
                    <label for="brand">Brand</label>
                    <input type="text" name="brand" id="brand" autocomplete="off" required>
                    <div id="custom-brand-list" class="custom-dropdown"></div>
                </div>

                <div class="form-group span-2">
                    <label for="model_no">Model Number</label>
                    <input type="text" name="model_no" id="model_no" autocomplete="off" required>
                    <div class="helper-text">*Determines the file names.</div>
                    <div id="model-warning" class="duplicate-warning"></div>
                </div>

                <div class="form-group span-4">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" autocomplete="off" required></textarea>
                </div>

                <div class="section-label span-4">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    Pricing Data
                </div>

                <div class="form-group">
                    <label for="buying_currency">Currency</label>
                    <select name="buying_currency" id="buying_currency" class="compact-field" required>
                        <option value="" disabled selected></option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="buying_cost">Buying Cost</label>
                    <input type="text" name="buying_cost" id="buying_cost" class="compact-field" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label for="factor">Factor</label>
                    <input type="number" step="any" min="0.0001" name="factor" id="factor" class="compact-field" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label for="selling_price">Selling Price</label>
                    <div class="price-wrapper">
                        <input type="text" name="selling_price" id="selling_price" class="input-readonly compact-field" readonly required autocomplete="off">
                    </div>
                </div>

                <div class="section-label span-4">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                    Media & Documents
                </div>

                <div class="form-group span-2">
                    <label for="picture">Product Image</label>
                    <div class="file-drop-area" id="drop-area">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <span class="file-msg" id="file-msg">Drop or Paste (Ctrl+V)</span>
                        
                        <div class="preview-container" id="preview-container">
                            <img id="image-preview" class="preview-img" src="" alt="Preview" title="Click to zoom">
                            <button type="button" class="remove-img-btn" id="remove-img-btn" title="Remove Image">✕</button>
                        </div>

                        <input type="file" name="picture" id="picture" class="file-input" accept="image/*">
                    </div>
                </div>

                <div class="form-group span-2">
                    <label for="pdf_file">Specification PDF (Optional)</label>
                    <div class="file-drop-area" id="pdf-drop-area">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span class="file-msg" id="pdf-file-msg">Browse or Drop PDF</span>
                        <input type="file" name="pdf_file" id="pdf_file" class="file-input" accept="application/pdf">
                    </div>
                </div>

            </div>

            <div class="action-buttons">
                <a href="index.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-submit">
                    <?php echo in_array($user_role, ['admin', 'super_admin']) ? 'Save to Live Inventory' : 'Submit for Approval'; ?>
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
        const modelInput = document.getElementById('model_no');
        const modelWarning = document.getElementById('model-warning');
        let duplicateDebounceTimer;

        modelInput.addEventListener('input', function() {
            clearTimeout(duplicateDebounceTimer);
            modelWarning.style.display = 'none'; 
            const val = this.value.trim();
            if (val.length > 2) {
                duplicateDebounceTimer = setTimeout(() => {
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'ajax_check_model=1&model_no=' + encodeURIComponent(val)
                    }).then(res => res.json()).then(data => {
                        if (data.exists) {
                            modelWarning.innerHTML = '⚠️ Exists under brand: ' + data.brands.join(', ');
                            modelWarning.style.display = 'block';
                        }
                    }).catch(err => console.error(err));
                }, 500);
            }
        });

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
        }

        removeBtn.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); resetImage(); });
        imagePreview.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); zoomedImage.src = this.src; zoomOverlay.classList.add('active'); });
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
                pdfMsg.textContent = 'Browse or Drop PDF';
            }
        });

        let allBrands = [];
        const brandInput = document.getElementById('brand');
        const customDropdown = document.getElementById('custom-brand-list');

        async function loadBrandSuggestions() {
            try { const response = await fetch('get_brands.php'); allBrands = await response.json(); } 
            catch (error) { console.log('Could not load brand suggestions'); }
        }

        function showBrands(filterText = '') {
            customDropdown.innerHTML = '';
            const val = filterText.trim().toLowerCase();
            const filteredBrands = allBrands.filter(brand => brand.toLowerCase().includes(val));

            if (filteredBrands.length > 0) {
                filteredBrands.forEach(brand => {
                    const div = document.createElement('div');
                    div.className = 'custom-dropdown-item';
                    div.textContent = brand;
                    div.addEventListener('click', function(e) { e.stopPropagation(); brandInput.value = brand; customDropdown.classList.remove('active'); });
                    customDropdown.appendChild(div);
                });
                customDropdown.classList.add('active'); 
            } else customDropdown.classList.remove('active');
        }

        brandInput.addEventListener('focus', function() { showBrands(this.value); });
        brandInput.addEventListener('input', function() { showBrands(this.value); });
        document.addEventListener('click', function(e) { if (e.target !== brandInput && e.target !== customDropdown) customDropdown.classList.remove('active'); });
        loadBrandSuggestions();
    </script>
</body>
</html>