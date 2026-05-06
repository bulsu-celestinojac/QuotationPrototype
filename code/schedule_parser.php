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
function processCsv(string $filePath) {
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
function processExcel(string $filePath) {
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
function processPdf(string $filePath) {
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
            $currentDesc = trim(preg_replace('/^' . preg_quote($matches[1], '/.') . '/i', '', $line, 1)); 
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
// 4. BEAST MODE EXTRACTION ALGORITHM
// ==============================================================
function extractInfo(string $text) {
    $cleanText = trim(preg_replace('/\s+/', ' ', $text)); 
    $model = 'NO MODEL';
    $brand = 'NO BRAND';

    if (empty($cleanText)) return ['model' => $model, 'brand' => $brand];

    // Strip out common dimensions and power specs so they aren't confused for models
    $analysisText = preg_replace('/\b\d+(?:\.\d+)?\s*[xX*]\s*\d+(?:\.\d+)?\s*(?:[xX*]\s*\d+(?:\.\d+)?)?\s*(?:mm|cm|in|inches|")?\b/i', '', $cleanText);
    $analysisText = preg_replace('/\b\d+(?:\.\d+)?\s*(?:V|Hz|kW|W|Ph|Amp|A|HP)\b/i', '', $analysisText);

    $foundModel = false;
    $foundBrand = false;

    $stopWords = 'Brand|Make|Mfg|Manufacturer|Model|Mdl|Mod|Item No|Dim|Dimensions|Cap|Capacity|Desc|Description|Weight|Volts|Voltage|Power|Temp|Cooling|Net|Gross|Fuel|Container|Dolly|Open stand|Vanishing|Volume|Max|Average|Electric|Supply|Distance|Defrosting|Ambient|Refrigerant|Materials|Controller|Yield|Climate|Valve|Bowl|Kneading|Flour|Motor|Speed|Gas|Phase|Cycle|Rate|Absorbed|Current|Internal|External|Productivity|Fire up|Charcoal|Performance|Broiling|Exhaust|Heat|Extraction|Installation|Cord';
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
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.2s ease; display: inline-block;}
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
        
        /* PREMIUM TABLE STYLES - Centered & Streamlined */
        .extracted-card { padding: 24px 0 0 0; overflow: hidden; background: var(--surface); border-radius: 24px; border: 1px solid var(--border); box-shadow: 0 12px 40px rgba(42,8,8,0.04); }
        .extracted-header { padding: 0 48px 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .extracted-footer { padding: 32px 48px; border-top: 1px solid var(--border); text-align: right; background: var(--bg); }
        
        .table-container { width: 100%; overflow-x: auto; max-height: 650px; overflow-y: auto; border-radius: 0 0 24px 24px; }
        table { width: 100%; border-collapse: collapse; text-align: center; position: relative; } 
        
        th { 
            font-family: 'Outfit', sans-serif; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; 
            color: var(--text-muted); border-bottom: 1px solid var(--border); padding: 20px 24px; 
            position: sticky; top: 0; z-index: 10;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        
        td { padding: 16px 24px; border-bottom: none; font-size: 0.95rem; color: var(--text-main); vertical-align: middle; transition: background 0.2s; }
        
        /* Utility spacing for table ends */
        .pad-left { padding-left: 48px; }
        .pad-right { padding-right: 48px; }
        
        tbody tr:nth-child(even) td { background-color: #FAFAF9; }
        tbody tr:hover td { background-color: var(--maroon-light); }
        
        .qty-badge { background: var(--surface); color: var(--maroon); padding: 6px 16px; border: 1px solid rgba(139,21,56,0.2); border-radius: 50px; font-weight: 900; font-size: 0.95rem; text-align: center; display: inline-block; min-width: 44px; box-shadow: 0 2px 8px rgba(139,21,56,0.05); }
        .badge-brand { background: var(--maroon-light); color: var(--maroon); padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; white-space: nowrap; }
        .model-text { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 1.15rem; color: var(--text-main); white-space: nowrap; }
        .badge-error { background: #FEF2F2; color: #EF4444; border: 1px solid #FCA5A5; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; display: inline-block; letter-spacing: 0.05em; white-space: nowrap; }

        .btn-view { background: transparent; color: var(--maroon); border: 1px solid var(--maroon); padding: 8px 20px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; font-family: 'Outfit', sans-serif; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.2s ease; white-space: nowrap; }
        .btn-view:hover { background: var(--maroon); color: white; box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2); }

        /* Modal Styles */
        .modal-overlay { position: fixed; inset: 0; background: rgba(248, 246, 245, 0.9); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 40px; }
        .modal-overlay.active { display: flex; }
        
        .modal-card-small { background: var(--surface); border: 1px solid var(--maroon); box-shadow: 0 24px 60px rgba(139, 21, 56, 0.12); border-radius: 24px; max-width: 600px; width: 100%; padding: 40px; position: relative; }
        .modal-close-btn { position: absolute; top: 24px; right: 24px; background: transparent; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); transition: all 0.2s ease; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
        .modal-close-btn:hover { color: var(--maroon); background: #FFF5F7; border-radius: 50%; }

        .detail-item { margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .detail-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .detail-label { font-size: 0.7rem; font-weight: 800; color: var(--maroon); text-transform: uppercase; letter-spacing: 0.1em; display: block; margin-bottom: 4px; }
        .detail-value { font-size: 1rem; color: var(--text-main); font-weight: 500; }
        .detail-flex { display: flex; gap: 40px; }

        /* =======================================================
           RESPONSIVE DESIGN (Tablets & Mobile)
           ======================================================= */
        @media (max-width: 1024px) {
            .detail-flex { flex-direction: column; gap: 16px !important; }
        }

        @media (max-width: 600px) {
            body { padding: 20px 16px; }
            .header { flex-direction: column; align-items: flex-start; gap: 16px; padding-bottom: 16px; margin-bottom: 24px; }
            .page-title { font-size: 2.2rem; }
            
            .card { padding: 24px 20px; border-radius: 16px; }
            .file-drop-area { padding: 40px 16px; }
            .file-msg { font-size: 1rem; }
            .btn-submit { max-width: 100%; height: 50px; font-size: 0.95rem; }
            
            /* Table responsive adjustments */
            th, td { padding: 12px 16px !important; font-size: 0.85rem; }
            .pad-left, .pad-right { padding: 12px 16px !important; }
            
            /* Extracted Data Card Mobile Layout */
            .extracted-header { flex-direction: column; align-items: flex-start !important; gap: 16px; padding: 0 20px 20px 20px !important; }
            .extracted-footer { padding: 24px 20px !important; text-align: center !important; }
            .extracted-footer .btn-submit { width: 100%; padding: 0; }

            /* Modal responsive */
            .modal-card-small { padding: 24px; border-radius: 16px; max-height: 90vh; overflow-y: auto; }
            .modal-overlay { padding: 20px; }
            .modal-close-btn { top: 16px; right: 16px; }
        }
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
                    <div class="extracted-card">
                        <div class="extracted-header">
                            <div>
                                <h2 class="card-title" style="margin: 0;">EXTRACT REVIT DATA</h2>
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
                                        <th class="pad-left" style="width: 10%; text-align: center;">Qty</th>
                                        <th style="width: 20%; text-align: center;">Mark</th>
                                        <th style="width: 25%; text-align: center;">Smart Brand</th>
                                        <th style="width: 30%; text-align: center;">Smart Model</th>
                                        <th class="pad-right" style="width: 15%; text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($extractedData as $index => $row): ?>
                                        <tr>
                                            <td class="pad-left">
                                                <div class="qty-badge"><?= htmlspecialchars($row['qty']) ?></div>
                                            </td>
                                            
                                            <td style="white-space: nowrap;">
                                                <strong style="font-size: 1.1rem; color: var(--maroon);"><?= htmlspecialchars($row['mark']) ?></strong>
                                            </td>
                                            
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

                                            <td class="pad-right" style="text-align: right;">
                                                <button type="button" class="btn-view" onclick='viewDetails(<?= json_encode([
                                                    "mark" => $row["mark"],
                                                    "qty" => $row["qty"],
                                                    "brand" => $row["brand"],
                                                    "model" => $row["model"],
                                                    "desc" => $row["original_text"]
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View Details</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <input type="hidden" name="extracted_json" value="<?= htmlspecialchars(json_encode($extractedData), ENT_QUOTES, 'UTF-8') ?>">
                        
                        <div class="extracted-footer">
                            <button type="submit" class="btn-submit" style="margin: 0; display: inline-block; width: auto; padding: 0 40px;">Proceed to Quote Builder →</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <!-- Comprehensive Details Modal -->
    <div class="modal-overlay" id="descModal">
        <div class="modal-card-small">
            <button type="button" class="modal-close-btn" onclick="closeDescModal()">✕</button>
            <h3 style="font-family: 'Outfit', sans-serif; color: var(--maroon); font-size: 1.5rem; margin-bottom: 24px; text-transform: uppercase; font-weight: 900;">Item Details</h3>
            
            <div class="detail-item">
                <span class="detail-label">Mark</span>
                <div class="detail-value"><strong style="color: var(--maroon);" id="modalMarkVal"></strong></div>
            </div>
            
            <div class="detail-item detail-flex">
                <div>
                    <span class="detail-label">Quantity</span>
                    <div class="detail-value" id="modalQtyVal"></div>
                </div>
                <div>
                    <span class="detail-label">Brand</span>
                    <div class="detail-value" id="modalBrandVal"></div>
                </div>
                <div>
                    <span class="detail-label">Model</span>
                    <div class="detail-value"><strong id="modalModelVal"></strong></div>
                </div>
            </div>

            <div class="detail-item" style="border: none; padding-bottom: 0;">
                <span class="detail-label">Raw Description</span>
                <div class="detail-value" id="modalDescContent" style="font-size: 0.9rem; line-height: 1.6; padding: 12px; background: #FAFAF9; border-radius: 8px; border: 1px solid var(--border);"></div>
            </div>
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

        // Comprehensive Modal Logic
        const descModal = document.getElementById('descModal');
        
        function viewDetails(data) {
            document.getElementById('modalMarkVal').textContent = data.mark || '-';
            document.getElementById('modalQtyVal').textContent = data.qty || '-';
            document.getElementById('modalBrandVal').textContent = data.brand || '-';
            document.getElementById('modalModelVal').textContent = data.model || '-';
            document.getElementById('modalDescContent').textContent = data.desc || 'No description available.';
            
            descModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeDescModal() {
            descModal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close when clicking outside
        descModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDescModal();
            }
        });
    </script>
</body>
</html>