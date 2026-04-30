<?php
// YOU MUST REQUIRE THE COMPOSER AUTOLOAD FILE TO USE THE LIBRARIES
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

$extractedData = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "Error uploading file. Please try again.";
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filePath = $file['tmp_name'];
        
        try {
            // Processing directly into the final array (NO GROUPING/MERGING!)
            if ($ext === 'csv') {
                $extractedData = processCsv($filePath);
            } elseif ($ext === 'xlsx' || $ext === 'xls') {
                $extractedData = processExcel($filePath);
            } elseif ($ext === 'pdf') {
                $extractedData = processPdf($filePath);
            } else {
                $error = "Unsupported file format. Please upload .csv, .xlsx, or .pdf";
            }
        } catch (Exception $e) {
            $error = "Error processing file: " . $e->getMessage();
        }
    }
}

// ==============================================================
// 1. ROBUST CSV PARSER (WITH STRICT COLUMN CHECKING)
// ==============================================================
function processCsv($filePath) {
    $data = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $markIndex = false;
        $descIndex = false;
        $qtyIndex = false;

        $markAliases = ['mark', 'item', 'tag', 'eq no', 'eq. no', 'equipment no', 'code', 'id', 'item no', 'no.'];
        $descAliases = ['keynote', 'type comments', 'description', 'specification', 'remarks', 'details', 'equipment', 'item description', 'article'];
        $qtyAliases = ['count', 'qty', 'quantity', 'q.t.y.', 'amount'];

        for ($i = 0; $i < 20; $i++) {
            $row = fgetcsv($handle, 10000, ",");
            if ($row !== false) {
                if ($i === 0 && isset($row[0])) { $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]); }
                
                $lowerRow = array_map(function($val) { return strtolower(trim((string)$val)); }, $row);
                
                foreach ($lowerRow as $idx => $val) {
                    if ($markIndex === false && in_array($val, $markAliases)) $markIndex = $idx;
                    if ($descIndex === false && in_array($val, $descAliases)) $descIndex = $idx;
                    if ($qtyIndex === false && in_array($val, $qtyAliases)) $qtyIndex = $idx;
                }
                // Break early ONLY if all three are found
                if ($markIndex !== false && $descIndex !== false && $qtyIndex !== false) break; 
            }
        }

        // --- STRICT ERROR CHECKING ---
        $missingColumns = [];
        if ($markIndex === false) $missingColumns[] = "MARK";
        if ($qtyIndex === false) $missingColumns[] = "COUNT / QTY";
        if ($descIndex === false) $missingColumns[] = "KEYNOTE / DESCRIPTION";

        if (!empty($missingColumns)) {
            fclose($handle);
            throw new Exception("Invalid Template. Missing required column(s): " . implode(", ", $missingColumns) . ".");
        }
        // -----------------------------

        while (($row = fgetcsv($handle, 10000, ",")) !== false) {
            $mark = trim($row[$markIndex] ?? '');
            
            // FLATTEN TEXT
            $rawDesc = str_replace(["\xA0", "\xC2\xA0"], ' ', (string)($row[$descIndex] ?? ''));
            $desc = trim(preg_replace('/\s+/', ' ', $rawDesc));
            
            // STRICT QTY EXTRACTION
            $qty = 1;
            if (isset($row[$qtyIndex])) {
                if (preg_match('/[0-9]+(?:\.[0-9]+)?/', (string)$row[$qtyIndex], $matches)) {
                    $parsed = floatval($matches[0]);
                    if ($parsed > 0) $qty = $parsed;
                }
            }
            
            if (empty($mark) || empty($desc) || in_array(strtolower($mark), $markAliases)) continue;

            $extracted = extractInfo($desc);
            $data[] = [
                'mark' => $mark, 
                'qty' => $qty, 
                'original_text' => $desc, 
                'model' => $extracted['model'], 
                'brand' => $extracted['brand']
            ];
        }
        fclose($handle);
    }
    return $data;
}

// ==============================================================
// 2. ROBUST EXCEL PARSER (WITH STRICT COLUMN CHECKING)
// ==============================================================
function processExcel($filePath) {
    $data = [];
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(); 

    $markIndex = false;
    $descIndex = false;
    $qtyIndex = false;
    
    $markAliases = ['mark', 'item', 'tag', 'eq no', 'eq. no', 'equipment no', 'code', 'id', 'item no', 'no.'];
    $descAliases = ['keynote', 'type comments', 'description', 'specification', 'remarks', 'details', 'equipment', 'item description', 'article'];
    $qtyAliases = ['count', 'qty', 'quantity', 'q.t.y.', 'amount'];

    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex > 20) break; 
        
        $lowerRow = array_map(function($val) { return strtolower(trim((string)$val)); }, $row);
        
        foreach ($lowerRow as $idx => $val) {
            if ($markIndex === false && in_array($val, $markAliases)) $markIndex = $idx;
            if ($descIndex === false && in_array($val, $descAliases)) $descIndex = $idx;
            if ($qtyIndex === false && in_array($val, $qtyAliases)) $qtyIndex = $idx;
        }
        // Break early ONLY if all three are found
        if ($markIndex !== false && $descIndex !== false && $qtyIndex !== false) break; 
    }

    // --- STRICT ERROR CHECKING ---
    $missingColumns = [];
    if ($markIndex === false) $missingColumns[] = "MARK";
    if ($qtyIndex === false) $missingColumns[] = "COUNT / QTY";
    if ($descIndex === false) $missingColumns[] = "KEYNOTE / DESCRIPTION";

    if (!empty($missingColumns)) {
        throw new Exception("Invalid Template. Missing required column(s): " . implode(", ", $missingColumns) . ".");
    }
    // -----------------------------

    foreach ($rows as $row) {
        $mark = trim((string)($row[$markIndex] ?? ''));
        
        // FLATTEN TEXT
        $rawDesc = str_replace(["\xA0", "\xC2\xA0"], ' ', (string)($row[$descIndex] ?? ''));
        $desc = trim(preg_replace('/\s+/', ' ', $rawDesc));
        
        // STRICT QTY EXTRACTION
        $qty = 1;
        if (isset($row[$qtyIndex])) {
            if (preg_match('/[0-9]+(?:\.[0-9]+)?/', (string)$row[$qtyIndex], $matches)) {
                $parsed = floatval($matches[0]);
                if ($parsed > 0) $qty = $parsed;
            }
        }
        
        if (empty($mark) || in_array(strtolower($mark), $markAliases)) continue;

        if (!empty($desc)) {
            $extracted = extractInfo($desc);
            $data[] = [
                'mark' => $mark, 
                'qty' => $qty, 
                'original_text' => $desc, 
                'model' => $extracted['model'], 
                'brand' => $extracted['brand']
            ];
        }
    }
    
    return $data;
}

// ==============================================================
// 3. SMART PDF PARSER
// ==============================================================
function processPdf($filePath) {
    $data = [];
    $parser = new Parser();
    $pdf = $parser->parseFile($filePath);
    $text = $pdf->getText();

    $lines = explode("\n", $text);
    $currentMark = '';
    $currentDesc = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (preg_match('/^([A-Z]{1,5}[-\s\.]?\d{1,4}[A-Za-z]?)\b/i', $line, $matches)) {
            if (!empty($currentMark) && !empty($currentDesc)) {
                $currentDesc = str_replace(["\xA0", "\xC2\xA0"], ' ', $currentDesc);
                $currentDesc = trim(preg_replace('/\s+/', ' ', $currentDesc));
                $extracted = extractInfo($currentDesc);
                $data[] = [
                    'mark' => $currentMark, 
                    'qty' => 1,
                    'original_text' => $currentDesc, 
                    'model' => $extracted['model'], 
                    'brand' => $extracted['brand']
                ];
            }
            
            $currentMark = strtoupper(trim($matches[1]));
            $currentDesc = trim(preg_replace('/^' . preg_quote($matches[1], '/') . '/i', '', $line, 1)); 
        } 
        elseif (!empty($currentMark)) {
            $currentDesc .= " " . $line; 
        }
    }

    if (!empty($currentMark) && !empty($currentDesc)) {
        $currentDesc = str_replace(["\xA0", "\xC2\xA0"], ' ', $currentDesc);
        $currentDesc = trim(preg_replace('/\s+/', ' ', $currentDesc));
        $extracted = extractInfo($currentDesc);
        $data[] = [
            'mark' => $currentMark, 
            'qty' => 1, 
            'original_text' => $currentDesc, 
            'model' => $extracted['model'], 
            'brand' => $extracted['brand']
        ];
    }

    if (empty($data)) throw new Exception("Could not extract Mark data from PDF.");

    return $data;
}

// ==============================================================
// 4. BEAST MODE EXTRACTION ALGORITHM (FIXED)
// ==============================================================
function extractInfo($text) {
    $cleanText = trim(preg_replace('/\s+/', ' ', $text)); 
    $model = 'NO MODEL';
    $brand = 'NO BRAND';

    if (empty($cleanText)) return ['model' => $model, 'brand' => $brand];

    // Strip out common dimensions and power specs so they aren't confused for models
    $analysisText = preg_replace('/\b\d+(?:\.\d+)?\s*[xX*]\s*\d+(?:\.\d+)?\s*(?:[xX*]\s*\d+(?:\.\d+)?)?\s*(?:mm|cm|in|inches|")?\b/i', '', $cleanText);
    $analysisText = preg_replace('/\b\d+(?:\.\d+)?\s*(?:V|Hz|kW|W|Ph|Amp|A|HP)\b/i', '', $analysisText);

    $foundModel = false;
    $foundBrand = false;

    // List of words that signal the end of a Model or Brand string
    $stopWords = 'Brand|Make|Mfg|Manufacturer|Model|Mdl|Mod|Item No|Dim|Dimensions|Cap|Capacity|Desc|Description|Weight|Volts|Voltage|Power|Temp|Cooling|Net|Gross|Fuel|Container|Dolly|Open stand|Vanishing|Volume|Max|Average|Electric|Supply|Distance|Defrosting|Ambient|Refrigerant|Materials|Controller|Yield|Climate|Valve|Bowl|Kneading|Flour|Motor|Speed|Gas|Phase|Cycle|Rate|Absorbed|Current|Internal|External|Productivity|Fire up|Charcoal|Performance|Broiling|Exhaust|Heat|Extraction|Installation|Cord';
    
    // THE FIX: Removed the aggressive colon-catcher that was truncating to 1 letter.
    $stopPattern = '(?=\s*(?:' . $stopWords . ')\b|$)';

    // Extract Model
    if (preg_match('/\b(?:Model|Mdl|Mod|Item No\.?)\b[\s:#\-\.]*([A-Za-z0-9\&\.\-\/\|\+\s]+?)' . $stopPattern . '/i', $analysisText, $matchModel)) {
        $model = strtoupper(trim($matchModel[1]));
        $analysisText = str_replace($matchModel[0], '', $analysisText);
        $foundModel = true;
    }

    // Extract Brand
    if (preg_match('/\b(?:Brand|Make|Mfg|Manufacturer)\b[\s:#\-\.]*([A-Za-z0-9\&\.\-\/\|\+\s]+?)' . $stopPattern . '/i', $analysisText, $matchBrand)) {
        $brand = strtoupper(trim($matchBrand[1]));
        $analysisText = str_replace($matchBrand[0], '', $analysisText);
        $foundBrand = true;
    }

    // Fallback logic if explicit "Model:" or "Brand:" labels are missing
    if (!$foundModel) {
        if (preg_match('/\b([A-Z]{1,4}[-\s]?\d{2,5}[A-Z0-9\-\/\|\._+]*)\b/i', $analysisText, $m)) {
            $model = strtoupper($m[1]);
            $analysisText = str_replace($m[0], '', $analysisText);
        } 
        elseif (preg_match('/\b(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9\-\/\|\._+]{4,}\b/', $analysisText, $m)) {
             $model = strtoupper($m[0]);
             $analysisText = str_replace($m[0], '', $analysisText);
        }
    }

    // Fallback logic for Brand if missing
    if (!$foundBrand && !empty($analysisText)) {
        $words = explode(' ', trim($analysisText));
        if (isset($words[0]) && preg_match('/^[A-Za-z]+$/', $words[0]) && strlen($words[0]) > 2) {
            
            $ignoreList = ['gas', 'electric', 'stainless', 'steel', 'heavy', 'duty', 'commercial', 'supply', 'custom', 'fabricated', 'table', 'sink', 'rack', 'shelf', 'wall', 'mounted', 'freestanding', 'undercounter', 'upright', 'single', 'double', 'triple', 'bowl', 'neutral', 'element', 'cabinet', 'doors', 'door', 'open', 'stand', 'tray', 'holder', 'trash', 'bin', 'water', 'ice', 'drop', 'down', 'exhaust', 'hood'];
            
            if (!in_array(strtolower($words[0]), $ignoreList)) {
                $brand = strtoupper($words[0]);
                if (isset($words[1]) && preg_match('/^[A-Za-z]+$/', $words[1]) && strlen($words[1]) > 2 && !in_array(strtolower($words[1]), $ignoreList)) {
                    $brand .= ' ' . strtoupper($words[1]); 
                }
            }
        }
    }

    // Clean up trailing punctuation just in case
    $model = rtrim($model, '.-_+|:');
    $brand = rtrim($brand, '.-_+|:');

    return [
        'model' => substr(trim($model), 0, 80), 
        'brand' => substr(trim($brand), 0, 80)
    ];
}
// ==============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parser Engine - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: #FFFFFF; --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; --maroon-light: #FAF5F6; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: linear-gradient(135deg, #F8F6F5 0%, #FAFAF9 100%); color: var(--text-main); padding: 40px 30px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 24px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 900; letter-spacing: -0.04em; text-transform: uppercase; line-height: 1; }
        .page-title .accent { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.2s ease; }
        .btn-back:hover { color: var(--maroon); }
        
        .card { background: var(--surface); border-radius: 24px; padding: 40px 48px; border: 1px solid var(--border); margin-bottom: 32px; box-shadow: 0 12px 40px rgba(42,8,8,0.04); transition: transform 0.3s ease; }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 700; color: var(--text-main); letter-spacing: -0.01em; margin-bottom: 12px; }
        .card-subtitle { color: var(--text-muted); margin-bottom: 32px; font-size: 0.95rem; }
        
        .file-drop-area { border: 2px dashed var(--border); border-radius: 16px; padding: 60px 24px; text-align: center; background: var(--surface); cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-direction: column; margin-bottom: 32px; }
        .file-drop-area:hover, .file-drop-area.is-active { background: var(--maroon-light); border-color: var(--maroon); transform: translateY(-2px); }
        .file-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 1; }
        .file-msg { font-size: 1.1rem; color: var(--text-muted); position: relative; z-index: 1; transition: color 0.2s; font-weight: 500; }
        .file-drop-area.is-active .file-msg { color: var(--maroon); font-weight: 700; font-size: 1.25rem; }
        
        .btn-submit { background: var(--maroon); color: white; display: block; width: 100%; max-width: 300px; margin: 0 auto; height: 56px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 24px rgba(139,21,56,0.2); }
        .btn-submit:hover { background: #5A0000; transform: translateY(-2px); box-shadow: 0 12px 30px rgba(139,21,56,0.3); }
        
        .alert { padding: 16px 24px; border-radius: 12px; font-size: 0.9rem; font-weight: 700; margin-bottom: 32px; text-align: center; }
        .alert-error { color: var(--maroon); background: var(--maroon-light); border: 1px solid #ebccd1; }
        
        /* PREMIUM TABLE STYLES */
        .table-container { width: 100%; overflow-x: auto; max-height: 650px; overflow-y: auto; border-radius: 0 0 24px 24px; }
        table { width: 100%; border-collapse: collapse; text-align: left; position: relative; }
        
        /* Glassmorphism Header */
        th { 
            font-family: 'Outfit', sans-serif; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; 
            color: var(--text-muted); border-bottom: 1px solid var(--border); padding: 20px 24px; 
            position: sticky; top: 0; z-index: 10;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        
        td { padding: 24px; border-bottom: 1px solid var(--border); font-size: 0.95rem; color: var(--text-main); vertical-align: top; transition: background 0.2s; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: var(--maroon-light); }
        
        /* UI Badges & Typography */
        .item-number { font-weight: 700; color: var(--text-muted); font-family: 'Outfit', sans-serif; }
        .qty-badge { background: #1E293B; color: #FFFFFF; padding: 6px 12px; border-radius: 8px; font-weight: 900; font-size: 1rem; text-align: center; display: inline-block; min-width: 40px; box-shadow: 0 4px 10px rgba(30,41,59,0.2); }
        
        .badge-brand { background: var(--maroon-light); color: var(--maroon); padding: 6px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; }
        .model-text { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 1.25rem; color: var(--text-main); }
        
        /* Error Chips */
        .badge-error { background: #FEF2F2; color: #EF4444; border: 1px solid #FCA5A5; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; display: inline-block; letter-spacing: 0.05em; }
        
        .desc-text { color: var(--text-muted); font-size: 0.9rem; display: -webkit-box; -webkit-line-clamp: 3; line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1 class="page-title">Data <span class="accent">Parser</span></h1>
            <a href="index.php" class="btn-back">← Back to Inventory</a>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 32px;">
            
            <div class="card" style="text-align: center;">
                <h2 class="card-title">Upload Equipment Schedule</h2>
                <p class="card-subtitle">Engine engineered to process messy .csv, .xlsx, or .pdf files into actionable data.</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="file-drop-area" id="drop-area">
                        <span class="file-msg" id="file-msg">Browse or drag-and-drop a file here</span>
                        <input type="file" name="import_file" id="import_file" class="file-input" accept=".csv, .xlsx, .xls, .pdf" required>
                    </div>
                    <button type="submit" class="btn-submit">Run Engine</button>
                </form>
            </div>

            <?php if (!empty($extractedData)): ?>
                <form action="project_quote_form.php" method="POST">
                    <div class="card" style="padding: 24px 0 0 0; overflow: hidden;">
                        <div style="padding: 0 48px 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h2 class="card-title" style="margin: 0;">Extraction Results</h2>
                                <div class="card-subtitle" style="margin-top: 8px; margin-bottom: 0;">Review the parsed data before finalizing in the quote builder.</div>
                            </div>
                            <div style="background: var(--maroon-light); color: var(--maroon); font-weight: 800; padding: 10px 20px; border-radius: 50px; font-family: 'Outfit', sans-serif;">
                                <?= count($extractedData) ?> Line Items Found
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 5%; padding-left: 48px;">Qty</th>
                                        <th style="width: 10%;">Mark</th>
                                        <th style="width: 15%;">Smart Brand</th>
                                        <th style="width: 20%;">Smart Model</th>
                                        <th style="width: 50%; padding-right: 48px;">Raw Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($extractedData as $index => $row): ?>
                                        <tr>
                                            <td style="padding-left: 48px;">
                                                <div class="qty-badge">x<?= htmlspecialchars($row['qty']) ?></div>
                                            </td>
                                            
                                            <td><strong style="font-size: 1.1rem; color: var(--maroon);"><?= htmlspecialchars($row['mark']) ?></strong></td>
                                            
                                            <td>
                                                <?php if ($row['brand'] !== 'NO BRAND'): ?>
                                                    <span class="badge-brand"><?= htmlspecialchars($row['brand']) ?></span>
                                                <?php else: ?>
                                                    <span class="badge-error">NO BRAND</span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td class="model-text">
                                                <?php if ($row['model'] !== 'NO MODEL'): ?>
                                                    <?= htmlspecialchars($row['model']) ?>
                                                <?php else: ?>
                                                    <span class="badge-error">NO MODEL</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="desc-text" title="<?= htmlspecialchars($row['original_text']) ?>" style="padding-right: 48px;">
                                                <?= htmlspecialchars($row['original_text']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <input type="hidden" name="extracted_json" value="<?= htmlspecialchars(json_encode($extractedData), ENT_QUOTES, 'UTF-8') ?>">
                        
                        <div style="padding: 32px 48px; border-top: 1px solid var(--border); text-align: right; background: var(--bg);">
                            <button type="submit" class="btn-submit" style="margin: 0; display: inline-block; width: auto; padding: 0 40px;">Proceed to Quote Builder →</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <script>
        const fileInput = document.getElementById('import_file');
        const fileMsg = document.getElementById('file-msg');
        const dropArea = document.getElementById('drop-area');

        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                dropArea.classList.add('is-active');
                fileMsg.innerHTML = "📄 <strong>" + this.files[0].name + "</strong> ready to process";
            } else {
                dropArea.classList.remove('is-active');
                fileMsg.textContent = "Browse or drag-and-drop a file here";
            }
        });
    </script>
</body>
</html>