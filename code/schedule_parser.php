<?php
// YOU MUST REQUIRE THE COMPOSER AUTOLOAD FILE TO USE THE LIBRARIES
require '../vendor/autoload.php';

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

function processCsv($filePath) {
    $data = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = [];
        $markIndex = false;
        $keynoteIndex = false;

        for ($i = 0; $i < 10; $i++) {
            $row = fgetcsv($handle, 10000, ",");
            if ($row !== false) {
                $cleanRow = array_map('trim', $row);
                $tempMark = array_search('Mark', $cleanRow);
                $tempKeynote = array_search('Keynote', $cleanRow) ?: array_search('Type Comments', $cleanRow);

                if ($tempMark !== false && $tempKeynote !== false) {
                    $headers = $cleanRow;
                    $markIndex = $tempMark;
                    $keynoteIndex = $tempKeynote;
                    break; 
                }
            }
        }

        if ($markIndex !== false && $keynoteIndex !== false) {
            while (($row = fgetcsv($handle, 10000, ",")) !== false) {
                $mark = trim($row[$markIndex] ?? '');
                $keynote = trim($row[$keynoteIndex] ?? '');
                
                if (!empty($mark) && !empty($keynote)) {
                    $extracted = extractInfo($keynote);
                    $data[] = ['mark' => $mark, 'original_text' => $keynote, 'model' => $extracted['model'], 'brand' => $extracted['brand']];
                }
            }
        } else {
            throw new Exception("Could not find 'Mark' and 'Keynote' columns in CSV.");
        }
        fclose($handle);
    }
    return $data;
}

function processExcel($filePath) {
    $data = [];
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(); 

    $markIndex = false;
    $keynoteIndex = false;

    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex > 10) break; 
        
        $cleanRow = array_map(function($val) { return trim((string)$val); }, $row);
        
        $tempMark = array_search('Mark', $cleanRow);
        $tempKeynote = array_search('Keynote', $cleanRow) ?: array_search('Type Comments', $cleanRow);

        if ($tempMark !== false && $tempKeynote !== false) {
            $markIndex = $tempMark;
            $keynoteIndex = $tempKeynote;
            break; 
        }
    }

    if ($markIndex !== false && $keynoteIndex !== false) {
        foreach ($rows as $row) {
            $mark = trim((string)($row[$markIndex] ?? ''));
            $keynote = trim((string)($row[$keynoteIndex] ?? ''));
            
            if ($mark === 'Mark' || $keynote === 'Keynote' || $keynote === 'Type Comments') continue;

            if (!empty($mark) && !empty($keynote)) {
                $extracted = extractInfo($keynote);
                $data[] = ['mark' => $mark, 'original_text' => $keynote, 'model' => $extracted['model'], 'brand' => $extracted['brand']];
            }
        }
    } else {
        throw new Exception("Could not find 'Mark' and 'Keynote' columns in Excel file.");
    }
    
    return $data;
}

function processPdf($filePath) {
    $data = [];
    $parser = new Parser();
    $pdf = $parser->parseFile($filePath);
    $text = $pdf->getText();

    $lines = explode("\n", $text);
    
    $currentMark = '';
    $currentKeynote = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (preg_match('/^([A-Z]{2,3}-\d{2,3})/', $line, $matches)) {
            if (!empty($currentMark) && !empty($currentKeynote)) {
                $extracted = extractInfo($currentKeynote);
                $data[] = ['mark' => $currentMark, 'original_text' => $currentKeynote, 'model' => $extracted['model'], 'brand' => $extracted['brand']];
            }
            $currentMark = $matches[1];
            $currentKeynote = trim(str_replace($currentMark, '', $line)); 
        } 
        elseif (!empty($currentMark)) {
            $currentKeynote .= " " . $line;
        }
    }

    if (!empty($currentMark) && !empty($currentKeynote)) {
        $extracted = extractInfo($currentKeynote);
        $data[] = ['mark' => $currentMark, 'original_text' => $currentKeynote, 'model' => $extracted['model'], 'brand' => $extracted['brand']];
    }

    if (empty($data)) {
         throw new Exception("Could not extract Mark/Keynote data from PDF. Ensure the PDF contains searchable text (not just an image) and uses formatting like 'BK-01'.");
    }

    return $data;
}

function extractInfo($text) {
    $model = '-';
    $brand = '-';

    $stopWordsArray = [
        'Brand', 'Model', 'Dimensions?', 'Dimension', 'Weight', 'Cooling', 'Defrost', 
        'Capacity', 'Voltage', 'Power', 'Phase', 'Hz', 'Ph', 'kW', 'Watts?', 'Amps?', 
        'Electrical', 'Material', 'Type', 'Temp', 'Temperature', 'Accessories', 
        'Warranty', 'Include', 'Included', 'Gas', 'Water', 'Drain', 'Volume', 'Net', 'Gross', 'Speed',
        'Standard', 'Fork', 'Dose', 'Adjustment', 'Adjustable', 'Blade', 'Blades', 'Diameter', 'Revs', 'RPM',
        'Container', 'Bin', 'Dolly', 'Machine', 'Unit', 'System', 'Set', 'Kit', 
        'Mixer', 'Fridge', 'Refrigerator', 'Freezer', 'Oven', 'Stove', 'Grill', 
        'Kneading', 'Flour', 'Bowl', 'Trash', 'Cart', 'Grinder', 'Coffee', 'Dispenser', 'Maker'
    ];

    $stopWords = implode('|', $stopWordsArray);

    if (preg_match('/Model\s*[:\-]?\s*(.*?)(?=[,;\n\r\(]|\s+(?:' . $stopWords . ')\b|\s+[A-Za-z]+\s*:|$)/i', $text, $matches)) {
        $model = trim($matches[1]);
        $model = preg_replace('/[^A-Za-z0-9]$/', '', $model); 
        $model = rtrim($model, " :"); 
    }

    if (preg_match('/Brand\s*[:\-]?\s*(.*?)(?=[,;\n\r\(]|\s+(?:' . $stopWords . ')\b|\s+[A-Za-z]+\s*:|$)/i', $text, $matches)) {
        $brand = trim($matches[1]);
        $brand = preg_replace('/[^A-Za-z0-9]$/', '', $brand);
        $brand = rtrim($brand, " :");
    }

    if (strlen($model) > 40) { $model = trim(substr($model, 0, 40)); }
    if (strlen($brand) > 40) { $brand = trim(substr($brand, 0, 40)); }

    return [
        'model' => !empty($model) ? $model : '-', 
        'brand' => !empty($brand) ? $brand : '-'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Project Quotation - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #F8F6F5; --surface: #FFFFFF; --text-main: #2A0808; --text-muted: #8C7373; --border: #E8D8D7; --maroon: #8B1538; --maroon-light: #FAF5F6; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 40px 30px; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { margin-bottom: 40px; display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border); padding-bottom: 24px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 900; letter-spacing: -0.04em; text-transform: uppercase; line-height: 1; }
        .page-title .accent { color: var(--maroon); }
        .btn-back { color: var(--text-muted); text-decoration: none; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; transition: color 0.2s ease; }
        .btn-back:hover { color: var(--maroon); }
        .card { background: var(--surface); border-radius: 24px; padding: 40px 48px; border: 1px solid var(--border); margin-bottom: 32px; }
        .card-title { font-family: 'Outfit', sans-serif; font-size: 1.6rem; font-weight: 700; color: var(--text-main); letter-spacing: -0.01em; margin-bottom: 12px; }
        .card-subtitle { color: var(--text-muted); margin-bottom: 32px; font-size: 0.95rem; }
        .file-drop-area { border: 1px dashed var(--border); border-radius: 16px; padding: 40px 24px; text-align: center; background: var(--surface); cursor: pointer; transition: all 0.2s ease; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-direction: column; margin-bottom: 32px; }
        .file-drop-area:hover, .file-drop-area.is-active { background: var(--maroon-light); border-color: var(--maroon); }
        .file-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 1; }
        .file-msg { font-size: 1rem; color: var(--text-muted); position: relative; z-index: 1; transition: color 0.2s; }
        .file-drop-area.is-active .file-msg { color: var(--maroon); font-weight: 700; }
        .btn-submit { background: var(--maroon); color: white; display: block; width: 100%; max-width: 300px; margin: 0 auto; height: 56px; border: none; border-radius: 50px; font-size: 1rem; font-family: 'Outfit', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.2s ease; }
        .btn-submit:hover { background: #5A0000; }
        .alert { padding: 16px 24px; border-radius: 12px; font-size: 0.9rem; font-weight: 500; margin-bottom: 32px; }
        .alert-error { color: var(--maroon); background: var(--maroon-light); border: 1px solid #ebccd1; }
        .table-container { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 20px 24px; border-bottom: 1px solid var(--border); }
        th { font-family: 'Outfit', sans-serif; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); border-bottom: 2px solid var(--border); }
        td { font-size: 0.95rem; color: var(--text-main); vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background-color: #FAFAFA; }
        .item-number { font-weight: 700; color: var(--text-muted); font-family: 'Outfit', sans-serif; }
        .badge-brand { background: var(--maroon-light); color: var(--maroon); padding: 6px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; }
        .model-text { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 1.15rem; }
        .desc-text { color: var(--text-muted); font-size: 0.85rem; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.5; }
        .layout-grid { display: grid; grid-template-columns: 1fr; gap: 32px; }
    </style>
</head>
<body>
    <div class="container">
        
        <div class="header">
            <h1 class="page-title">Project <span class="accent">Quotation</span></h1>
            <a href="index.php" class="btn-back">← Back to Inventory</a>
        </div>

        <div class="layout-grid">
            
            <div class="card" style="text-align: center;">
                <h2 class="card-title">Upload Equipment Schedule</h2>
                <p class="card-subtitle">Upload a .csv, .xlsx, or .pdf file to extract equipment Models and Brands.</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="file-drop-area" id="drop-area">
                        <span class="file-msg" id="file-msg">Browse or drop a file here</span>
                        <input type="file" name="import_file" id="import_file" class="file-input" accept=".csv, .xlsx, .xls, .pdf" required>
                    </div>
                    <button type="submit" class="btn-submit">Process File</button>
                </form>
            </div>

            <?php if (!empty($extractedData)): ?>
                <form action="project_quote_form.php" method="POST">
                    <div class="card" style="padding: 24px 0 0 0; overflow: hidden;">
                        <div style="padding: 0 48px 24px 48px; border-bottom: 1px solid var(--border);">
                            <h2 class="card-title" style="margin: 0;">Extracted Data</h2>
                            <div class="card-subtitle" style="margin-top: 8px; margin-bottom: 0;">Review the parsed schedule items below.</div>
                        </div>
                        
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 5%; padding-left: 48px;">#</th>
                                        <th style="width: 10%;">Mark</th>
                                        <th style="width: 20%;">Extracted Brand</th>
                                        <th style="width: 20%;">Extracted Model</th>
                                        <th style="width: 45%; padding-right: 48px;">Original Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $itemNumber = 1;
                                    foreach ($extractedData as $index => $row): 
                                    ?>
                                        <tr>
                                            <td class="item-number" style="padding-left: 48px;"><?= $itemNumber++ ?></td>
                                            <td><strong><?= htmlspecialchars($row['mark']) ?></strong></td>
                                            <td>
                                                <?php if ($row['brand'] !== '-'): ?>
                                                    <span class="badge-brand"><?= htmlspecialchars($row['brand']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--border); font-weight: bold; font-size: 0.8rem;">UNKNOWN</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="model-text"><?= htmlspecialchars($row['model']) ?></td>
                                            <td class="desc-text" title="<?= htmlspecialchars($row['original_text']) ?>" style="padding-right: 48px;">
                                                <?= htmlspecialchars($row['original_text']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <input type="hidden" name="extracted_json" value="<?= htmlspecialchars(json_encode($extractedData), ENT_QUOTES, 'UTF-8') ?>">
                        
                        <div style="padding: 32px 48px; border-top: 1px solid var(--border); text-align: right; background: var(--maroon-light);">
                            <button type="submit" class="btn-submit" style="margin: 0; display: inline-block; width: auto; padding: 0 40px; box-shadow: 0 4px 12px rgba(139, 21, 56, 0.15);">Project Quotation Form</button>
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
                fileMsg.textContent = "Selected: " + this.files[0].name;
            } else {
                dropArea.classList.remove('is-active');
                fileMsg.textContent = "Browse or drop a file here";
            }
        });
    </script>
</body>
</html>