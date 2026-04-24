<?php
require 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = trim($_POST['brand'] ?? '');
    $model_no = trim($_POST['model_no'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $buying_currency = $_POST['buying_currency'] ?? '';
    $buying_cost = $_POST['buying_cost'] ?? '';
    $factor = $_POST['factor'] ?? '';
    $selling_price = $_POST['selling_price'] ?? '';
    $picture = '';
    $target_file = '';

    if (!is_numeric($factor) || floatval($factor) <= 0) {
        $error = 'Factor must be a positive number greater than zero.';
    } else {
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['picture']['tmp_name']);
            // finfo_close removed to prevent deprecation warnings in PHP 8+

            $ext = strtolower(pathinfo($_FILES['picture']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_extensions) || !in_array($mime_type, $allowed_mime_types)) {
                $error = 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.';
            } else {
                $safe_model_no = preg_replace('/[^A-Za-z0-9_\-]/', '_', $model_no);
                
                if (empty($safe_model_no)) {
                    $safe_model_no = 'unnamed_model_' . time();
                }

                $filename = $safe_model_no . '.' . $ext;
                $target_dir = __DIR__ . '/../images/machine_images/';
                
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0755, true);
                }
                
                $target_file = $target_dir . $filename;
                
                if (move_uploaded_file($_FILES['picture']['tmp_name'], $target_file)) {
                    $picture = $filename;
                } else {
                    $error = 'Image upload failed. Please check folder permissions.';
                }
            }
        }

        if (!$error) {
            // Duplicate Model Check
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE model_no = ?");
            $checkStmt->execute([$model_no]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $error = 'A machine with this Model Number already exists.';
                
                // Image Cleanup on rejected duplicate
                if ($picture && file_exists($target_file)) {
                    unlink($target_file);
                }
            } else {
                $stmt = $pdo->prepare("INSERT INTO items (brand, model_no, description, picture, buying_currency, buying_cost, factor, selling_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$brand, $model_no, $description, $picture, $buying_currency, $buying_cost, $factor, $selling_price])) {
                    $success = 'Machine added successfully!';
                } else {
                    $error = 'Database error occurred.';
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
            --maroon: #8B1538;
            --maroon-light: #FAF5F6;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .modal-card {
            background: var(--surface);
            width: 100%;
            max-width: 650px;
            border-radius: 24px;
            padding: 0;
            position: relative;
            max-height: 95vh;
            overflow-y: auto;
            border: 1px solid var(--border);
        }

        .modal-card::-webkit-scrollbar { width: 0px; }

        .modal-form-wrapper { padding: 40px 48px; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .modal-header {
            background: var(--surface);
            padding: 40px 48px 24px 48px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-title { 
            font-family: 'Outfit', sans-serif; 
            font-size: 2.2rem; 
            font-weight: 900; 
            color: var(--text-main); 
            text-transform: uppercase;
            letter-spacing: -0.02em;
            line-height: 1;
        }

        .modal-subtitle { 
            font-size: 0.95rem; 
            color: var(--text-muted); 
            margin-top: 8px; 
            font-weight: 400; 
        }

        .close-btn {
            position: absolute;
            top: 36px;
            right: 40px;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.2s ease;
            z-index: 20;
        }
        .close-btn:hover { color: var(--maroon); }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        .full-width { grid-column: 1 / -1; }

        label {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .helper-text { font-size: 0.75rem; color: var(--text-muted); margin-top: -4px; font-style: italic; }

        input[type="text"], input[type="number"], select, textarea {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--surface);
            font-size: 0.95rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-main);
            transition: all 0.2s ease;
            outline: none;
        }

        select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%238B1538' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 12px;
            padding-right: 40px;
        }

        textarea { resize: vertical; min-height: 120px; }

        input:focus:not([readonly]), textarea:focus, select:focus {
            border-color: var(--maroon);
        }
        
        .input-readonly {
            background-color: var(--bg) !important;
            color: var(--maroon) !important;
            font-weight: 700;
            cursor: not-allowed;
            border-color: transparent !important;
        }

        .price-wrapper { position: relative; display: flex; align-items: center; }

        .price-wrapper::before {
            content: "₱";
            position: absolute;
            left: 16px;
            font-weight: 700;
            color: var(--maroon);
            z-index: 1;
        }

        .price-wrapper input { padding-left: 36px; }

        /* Modified File Drop Area for Preview & Remove */
        .file-drop-area {
            border: 1px dashed var(--border);
            border-radius: 16px;
            padding: 32px 24px;
            text-align: center;
            background: var(--surface);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            min-height: 140px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .file-drop-area:hover, .file-drop-area.is-active {
            background: var(--maroon-light);
            border-color: var(--maroon);
        }

        .file-input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 1;
        }

        .file-msg { font-size: 0.9rem; color: var(--text-muted); position: relative; z-index: 1; transition: color 0.2s; }
        .file-drop-area.is-active .file-msg { color: var(--maroon); font-weight: 700; }

        .preview-container {
            position: relative;
            z-index: 10; /* Above the file input to capture clicks */
            display: none;
            width: fit-content;
            margin: 0 auto;
        }

        .preview-img {
            max-height: 110px;
            border-radius: 8px;
            cursor: zoom-in;
            object-fit: contain;
            box-shadow: 0 4px 12px rgba(42, 8, 8, 0.1);
            transition: transform 0.2s ease;
        }
        
        .preview-img:hover { transform: scale(1.02); }

        .remove-img-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            background: var(--surface);
            color: var(--maroon);
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 28px;
            height: 28px;
            font-size: 0.9rem;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }

        .remove-img-btn:hover {
            background: var(--maroon);
            color: var(--surface);
            transform: scale(1.1);
        }

        .btn-submit {
            background: var(--maroon);
            color: white;
            width: 100%;
            height: 56px;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            cursor: pointer;
            margin-top: 40px;
            transition: background 0.3s ease;
        }

        .btn-submit:hover { background: #5A0000; }

        .alert { padding: 16px 48px; margin: 0; font-size: 0.9rem; font-weight: 500; border-bottom: 1px solid var(--border); }
        .alert-error { color: var(--maroon); background: var(--maroon-light); }
        .alert-success { color: #166534; background: #F0FDF4; }

        .custom-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .custom-dropdown.active { display: block; }
        .custom-dropdown-item { padding: 12px 16px; font-size: 0.9rem; color: var(--text-main); cursor: pointer; transition: all 0.2s ease; }
        .custom-dropdown-item:hover { background: var(--maroon-light); color: var(--maroon); font-weight: 500; }

        /* Zoom Overlay Styles */
        .zoom-overlay {
            position: fixed;
            inset: 0;
            background: rgba(248, 246, 245, 0.95);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .zoom-overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        .zoom-overlay img {
            max-width: 90vw;
            max-height: 90vh;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(42, 8, 8, 0.15);
            transform: scale(0.95);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .zoom-overlay.active img { transform: scale(1); }

        .zoom-close-btn {
            position: absolute;
            top: 40px;
            right: 40px;
            background: transparent;
            border: none;
            font-size: 2rem;
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .zoom-close-btn:hover { color: var(--maroon); }

    </style>
</head>
<body>

    <div class="modal-card">
        <button class="close-btn" onclick="window.location.href='index.php'" title="Close Dashboard">✕</button>
        
        <div class="modal-header">
            <h2 class="modal-title">New Record</h2>
            <div class="modal-subtitle">Add a machine to the inventory.</div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?=htmlspecialchars($error)?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?=htmlspecialchars($success)?></div><?php endif; ?>

        <div class="modal-form-wrapper">
        <form method="post" enctype="multipart/form-data" id="machineForm" autocomplete="off">
            <div class="form-grid">
                
                <div class="form-group" style="position: relative;">
                    <label for="brand">Brand</label>
                    <input type="text" name="brand" id="brand" placeholder="Enter or select..." readonly onfocus="this.removeAttribute('readonly');" required>
                    <div id="custom-brand-list" class="custom-dropdown"></div>
                </div>

                <div class="form-group">
                    <label for="model_no">Model Number</label>
                    <input type="text" name="model_no" id="model_no" placeholder="e.g. M-1000" readonly onfocus="this.removeAttribute('readonly');" required>
                    <div class="helper-text">*Determines the image file name.</div>
                </div>

                <div class="form-group full-width">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" placeholder="Technical specifications..." autocomplete="off" required></textarea>
                </div>

                <div class="form-group full-width">
                    <label for="picture">Product Image</label>
                    <div class="file-drop-area" id="drop-area">
                        <span class="file-msg" id="file-msg">Browse, drop, or paste an image here (Ctrl+V)</span>
                        
                        <div class="preview-container" id="preview-container">
                            <img id="image-preview" class="preview-img" src="" alt="Preview" title="Click to zoom">
                            <button type="button" class="remove-img-btn" id="remove-img-btn" title="Remove Image">✕</button>
                        </div>

                        <input type="file" name="picture" id="picture" class="file-input" accept="image/*">
                    </div>
                </div>

                <div class="form-group">
                    <label for="buying_currency">Currency</label>
                    <select name="buying_currency" id="buying_currency" required>
                        <option value="" disabled selected>Select...</option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="buying_cost">Buying Cost</label>
                    <input type="number" step="0.01" min="0" name="buying_cost" id="buying_cost" placeholder="0.00" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label for="factor">Factor</label>
                    <input type="number" step="any" min="0.0001" name="factor" id="factor" placeholder="0.00" autocomplete="off" required>
                </div>

                <div class="form-group">
                    <label for="selling_price">Selling Price</label>
                    <div class="price-wrapper">
                        <input type="number" step="0.01" name="selling_price" id="selling_price" class="input-readonly" placeholder="0.00" readonly required autocomplete="off">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">Save to Inventory</button>
        </form>
        </div>
    </div>

    <div class="zoom-overlay" id="zoom-overlay">
        <button class="zoom-close-btn" id="zoom-close">✕</button>
        <img id="zoomed-image" src="" alt="Zoomed Product">
    </div>

   <script>
        // --- 1. EXCEL TEXT PASTE CLEANER ---
        document.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(input => {
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

        // --- 2. IMAGE UPLOAD, PASTE, REMOVE & ZOOM LOGIC ---
        const fileInput = document.getElementById('picture');
        const fileMsg = document.getElementById('file-msg');
        const dropArea = document.getElementById('drop-area');
        
        const previewContainer = document.getElementById('preview-container');
        const imagePreview = document.getElementById('image-preview');
        const removeBtn = document.getElementById('remove-img-btn');

        const zoomOverlay = document.getElementById('zoom-overlay');
        const zoomedImage = document.getElementById('zoomed-image');
        const zoomClose = document.getElementById('zoom-close');

        // Reset/Remove Image
        function resetImage() {
            fileInput.value = ''; // Clear input files
            previewContainer.style.display = 'none';
            fileMsg.style.display = 'block';
            dropArea.classList.remove('is-active');
            imagePreview.src = '';
        }

        removeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Stop click from opening the file dialog
            resetImage();
        });

        // Image Zoom
        imagePreview.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Stop click from opening the file dialog
            zoomedImage.src = this.src;
            zoomOverlay.classList.add('active');
        });

        zoomClose.addEventListener('click', () => zoomOverlay.classList.remove('active'));
        zoomOverlay.addEventListener('click', function(e) {
            if (e.target === this) zoomOverlay.classList.remove('active');
        });

        // Paste Logic
        document.addEventListener('paste', function(e) {
            const activeTag = document.activeElement ? document.activeElement.tagName : '';
            if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') { return; }

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

        // File Selection/Drop logic
        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                const file = this.files[0];
                dropArea.classList.add('is-active');
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    previewContainer.style.display = 'block';
                    fileMsg.style.display = 'none';
                }
                reader.readAsDataURL(file);
                
            } else {
                resetImage();
            }
        });

        // --- 3. PRICING LOGIC ---
        const costInput = document.getElementById('buying_cost');
        const factorInput = document.getElementById('factor');
        const priceInput = document.getElementById('selling_price');

        function calculatePrice() {
            const cost = parseFloat(costInput.value) || 0;
            const factor = parseFloat(factorInput.value) || 0;
            
            if (factor <= 0 && factorInput.value !== '') {
                factorInput.setCustomValidity("Factor must be greater than 0");
            } else {
                factorInput.setCustomValidity("");
            }

            const total = cost * factor;
            if (total > 0) {
                priceInput.value = total.toFixed(2);
            } else {
                priceInput.value = '';
            }
        }

        costInput.addEventListener('input', calculatePrice);
        factorInput.addEventListener('input', calculatePrice);

        // --- 4. CUSTOM BRAND DROPDOWN LOGIC ---
        let allBrands = [];
        const brandInput = document.getElementById('brand');
        const customDropdown = document.getElementById('custom-brand-list');

        async function loadBrandSuggestions() {
            try {
                const response = await fetch('get_brands.php');
                allBrands = await response.json();
            } catch (error) {
                console.log('Could not load brand suggestions');
            }
        }

        brandInput.addEventListener('input', function() {
            const val = this.value.trim().toLowerCase();
            customDropdown.innerHTML = '';
            
            if (val.length >= 2) {
                const filteredBrands = allBrands.filter(brand => 
                    brand.toLowerCase().includes(val)
                );

                if (filteredBrands.length > 0) {
                    filteredBrands.forEach(brand => {
                        const div = document.createElement('div');
                        div.className = 'custom-dropdown-item';
                        div.textContent = brand;
                        
                        div.addEventListener('click', function() {
                            brandInput.value = brand;
                            customDropdown.classList.remove('active');
                        });
                        
                        customDropdown.appendChild(div);
                    });
                    customDropdown.classList.add('active'); 
                } else {
                    customDropdown.classList.remove('active');
                }
            } else {
                customDropdown.classList.remove('active');
            }
        });

        document.addEventListener('click', function(e) {
            if (e.target !== brandInput && e.target !== customDropdown) {
                customDropdown.classList.remove('active');
            }
        });

        loadBrandSuggestions();
    </script>
</body>
</html>