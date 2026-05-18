<?php
require_once 'auth.php';
require_login();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require 'db.php';
require_once 'functions.php';

// Grab the current user's role
$user_role = $_SESSION['user_role'] ?? '';

// Fetch data for search suggestions
$suggestionStmt = $pdo->query("SELECT brand, model_no FROM items WHERE status = 'active'");
$allSuggestions = $suggestionStmt->fetchAll(PDO::FETCH_ASSOC);

// Pagination setup
$perPage = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM items WHERE status = 'active'";
$sql = "SELECT * FROM items WHERE status = 'active'";
$search = $_GET['search'] ?? '';
$params = [];

// Apply search filters
if ($search) {
    $where = " AND (brand LIKE ? OR model_no LIKE ? OR description LIKE ? OR factor LIKE ?)";
    $sql .= $where;
    $countSql .= $where;
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

// Get total count for pagination
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalItems / $perPage));

// Fetch actual data items for the current page
$sql .= " ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// =========================================================================
// STRICT, WARNING-FREE DATA DICTIONARY
// =========================================================================
$js_item_data = [];
foreach ($items as $item) {
    $picture = $item['picture'] ?? '';
    // Path configuration
    $hasImage = ($picture !== '' && @file_exists(__DIR__ . '/../images/machine_images/' . $picture));
    $publicFilePath = $hasImage ? '../images/machine_images/' . htmlspecialchars($picture, ENT_QUOTES, 'UTF-8') : null;

    $js_item_data[$item['id']] = [
        "id" => $item["id"] ?? 0,
        "brand" => $item["brand"] ?? 'Unbranded',
        "model_no" => $item["model_no"] ?? 'Unknown',
        "description" => $item["description"] ?? '',
        "buying_currency" => $item["buying_currency"] ?? '',
        "buying_cost" => $item["buying_cost"] ?? 0,
        "factor" => $item["factor"] ?? 1,
        "selling_price" => (float)($item["selling_price"] ?? 0), 
        "image" => $publicFilePath,
        "pdf_path" => $item["pdf_path"] ?? null
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Machine Inventory - AM Group</title>
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
            --shadow-hover: 0 20px 40px -10px rgba(139, 21, 56, 0.12); 
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); min-height: 100vh; overflow-x: hidden; }
        
        .container { max-width: 1600px; margin: 0 auto; padding: 0 30px; }

        /* =======================================================
           FROSTED GLASS STICKY HEADER
           ======================================================= */
        .top-bar-wrapper {
            position: sticky;
            top: 0;
            z-index: 900;
            background: rgba(244, 247, 249, 0.85); 
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 24px 0;
            margin-bottom: 30px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }
        
        .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: clamp(1.8rem, 3vw, 2.2rem); font-weight: 900; letter-spacing: -0.04em; text-transform: uppercase; line-height: 1; margin: 0; flex-shrink: 0; }
        .page-title .accent { color: var(--maroon); }
        .controls { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: flex-end; flex: 1; }

        /* MODERN PILL SEARCH BAR (FIXED HEIGHT) */
        .search-wrapper { position: relative; overflow: visible !important; background: var(--surface); display: flex; align-items: center; border: 1px solid var(--border); border-radius: 9999px; flex: 1 1 250px; max-width: 400px; height: 48px; min-height: 48px; max-height: 48px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 100; box-shadow: var(--shadow-sm); }
        .search-wrapper:focus-within { border-color: var(--maroon); box-shadow: 0 0 0 4px var(--maroon-light); transform: translateY(-1px); }
        .search-input { flex: 1; border: none; background: transparent; padding: 0 24px; font-size: 0.95rem; font-weight: 500; outline: none; color: var(--text-main); min-width: 0; width: 100%; height: 100%; }
        .search-input::placeholder { color: var(--text-light); }
        .search-btn { background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; border: none; border-radius: 0 9999px 9999px 0; height: 100%; padding: 0 24px; font-weight: 800; font-family: 'Outfit', sans-serif; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; }
        .search-btn:hover { filter: brightness(1.1); }
        .clear-btn { background: transparent; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer; padding: 0 16px; line-height: 1; display: none; align-items: center; justify-content: center; height: 100%; transition: all 0.2s ease; }
        .clear-btn:hover { color: var(--danger); transform: scale(1.15); }

        .custom-dropdown { position: absolute; top: calc(100% + 12px); left: 0; width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; max-height: 300px; overflow-y: auto; z-index: 1000; display: none; box-shadow: var(--shadow-md); padding: 8px; }
        .custom-dropdown-item { padding: 12px 16px; font-size: 0.9rem; color: var(--text-main); cursor: pointer; transition: all 0.2s ease; border-radius: 8px; display: flex; flex-direction: column; gap: 4px; }
        .custom-dropdown-item:hover { background: var(--bg); transform: translateX(4px); }
        .sugg-model { font-family: 'Outfit', sans-serif; font-weight: 800; color: var(--text-main); font-size: 1.05rem; }
        .sugg-brand { font-size: 0.7rem; color: var(--maroon); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }

        /* MODERN PILL BUTTONS */
        .btn { font-family: 'Outfit', sans-serif; height: 48px; padding: 0 20px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid var(--border); border-radius: 9999px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; background: var(--surface); color: var(--text-main); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap; box-shadow: var(--shadow-sm); }
        .btn:hover { border-color: var(--maroon); color: var(--maroon); transform: translateY(-2px); box-shadow: 0 8px 16px rgba(139, 21, 56, 0.08); }

        .cart-trigger.has-items { background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; border: none; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); }
        .cart-trigger.has-items:hover { filter: brightness(1.1); transform: translateY(-2px); box-shadow: 0 12px 25px rgba(139, 21, 56, 0.35); }

        /* PULSING NOTIFICATION BADGE */
        @keyframes pulse-ring {
            0% { transform: scale(0.8); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
            100% { transform: scale(0.8); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .notif-badge {
            position: absolute; top: -6px; right: -6px; background: var(--danger); color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; font-weight: 900; box-shadow: 0 4px 8px rgba(239,68,68,0.3);
            animation: pulse-ring 2s infinite;
        }

        .btn-logout { background: #FFF5F5 !important; color: var(--danger) !important; border-color: #FECACA !important; }
        .btn-logout:hover { background: var(--danger) !important; color: white !important; border-color: var(--danger) !important; box-shadow: 0 8px 20px rgba(239, 68, 68, 0.2) !important; }

        /* =======================================================
           ELEVATED BENTO CARDS
           ======================================================= */
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 28px; margin-bottom: 80px; }
        
        .card { 
            background: var(--surface); 
            border: 1px solid rgba(226, 232, 240, 0.8); 
            border-radius: 24px; 
            cursor: pointer; 
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); 
            display: flex; 
            flex-direction: column; 
            position: relative; 
            overflow: hidden; 
            box-shadow: var(--shadow-md);
        }
        .card:hover { 
            border-color: rgba(139, 21, 56, 0.15); 
            transform: translateY(-8px); 
            box-shadow: var(--shadow-hover); 
        }
        .card.is-selected { 
            border: 2px solid var(--maroon); 
            transform: translateY(-4px); 
            box-shadow: 0 12px 30px rgba(139, 21, 56, 0.15); 
        }
        
        .card-image { 
            width: 100%; 
            height: 220px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            padding: 30px; 
            background: linear-gradient(180deg, #F8FAFC 0%, #FFFFFF 100%); 
            border-bottom: 1px solid var(--border);
            position: relative;
        }
        .card-image img { max-width: 100%; max-height: 100%; object-fit: contain; transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1); filter: drop-shadow(0 10px 15px rgba(0,0,0,0.05)); }
        .card:hover .card-image img { transform: scale(1.08); }
        .card-image .no-img { color: var(--text-light); font-weight: 800; font-size: 0.75rem; letter-spacing: 0.15em; text-transform: uppercase; background: #F1F5F9; padding: 12px 20px; border-radius: 12px;}
        
        .card-content { padding: 24px; flex: 1; display: flex; flex-direction: column; }
        .card-brand { font-size: 0.65rem; text-transform: uppercase; font-weight: 800; color: var(--maroon); letter-spacing: 0.15em; margin-bottom: 8px; display: inline-block; background: var(--maroon-light); padding: 4px 10px; border-radius: 50px; align-self: flex-start;}
        .card-model { font-family: 'Outfit', sans-serif; font-size: 1.3rem; font-weight: 900; color: var(--text-main); margin-bottom: 8px; line-height: 1.2; word-wrap: break-word; }
        .card-desc { font-size: 0.85rem; font-weight: 500; color: var(--text-muted); margin-bottom: 20px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .card-price { font-size: 1.3rem; font-weight: 900; color: var(--maroon); margin-bottom: 24px; letter-spacing: -0.02em;}
        
        /* IMPROVED ADD TO QUOTE BUTTON - HIGH VISIBILITY */
        .btn-select { 
            margin-top: auto; 
            width: 100%; 
            height: 48px; 
            background: var(--surface); 
            border: 2px solid var(--maroon); 
            border-radius: 16px; 
            color: var(--maroon); 
            font-family: 'Outfit', sans-serif; 
            font-weight: 800; 
            font-size: 0.85rem; 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            cursor: pointer; 
            transition: all 0.3s ease; 
        }
        .btn-select:hover { 
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); 
            color: white; 
            transform: translateY(-2px); 
            box-shadow: 0 8px 16px rgba(139, 21, 56, 0.2);
        }
        .card.is-selected .btn-select { 
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); 
            color: white; 
            border: none; 
            font-size: 0; 
            box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); 
        }
        .card.is-selected .btn-select::after { content: "SELECTED"; font-size: 0.85rem; }

        /* UPGRADED PREMIUM PAGINATION */
        .pagination { display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 12px; margin-top: 50px; padding-bottom: 20px; }
        .page-link { 
            width: 46px; 
            height: 46px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border: 1px solid var(--border); 
            border-radius: 50%; /* Perfect circle */
            color: var(--text-muted); 
            text-decoration: none; 
            font-family: 'Outfit', sans-serif;
            font-weight: 800; 
            font-size: 1rem; 
            background: var(--surface); 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            box-shadow: var(--shadow-sm); 
        }
        .page-link:hover { 
            border-color: var(--maroon); 
            color: var(--maroon); 
            transform: translateY(-3px); 
            box-shadow: var(--shadow-md); 
        }
        .page-link.active { 
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); 
            color: white; 
            border: none; 
            box-shadow: 0 8px 20px rgba(139, 21, 56, 0.3); 
            transform: scale(1.05); /* Aggressive pop for the active state */
        }

        /* CART & MODAL OVERLAYS */
        .cart-overlay, .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 998; display: none; opacity: 0; transition: opacity 0.3s ease; }
        .cart-overlay.active, .modal-overlay.active { display: flex; opacity: 1; }
        .modal-overlay.active { align-items: center; justify-content: center; padding: 20px; }
        
        .cart-drawer { position: fixed; top: 0; right: 0; width: 420px; height: 100vh; background: var(--surface); box-shadow: -10px 0 40px rgba(0,0,0,0.1); z-index: 999; display: flex; flex-direction: column; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .cart-drawer.active { transform: translateX(0); }
        
        .cart-header { padding: 30px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #F8FAFC; }
        .cart-header h2 { font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 900; text-transform: uppercase; color: var(--text-main); }
        .btn-close { background: white; border: 1px solid var(--border); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; cursor: pointer; color: var(--text-muted); transition: all 0.3s ease; box-shadow: var(--shadow-sm); }
        .btn-close:hover { color: var(--danger); border-color: var(--danger); transform: rotate(90deg); }
        
        .cart-items { flex: 1; overflow-y: auto; padding: 30px; display: flex; flex-direction: column; gap: 20px; }
        .cart-item-row { display: flex; justify-content: space-between; align-items: center; padding: 20px; background: #F8FAFC; border: 1px solid var(--border); border-radius: 16px; transition: all 0.3s ease; }
        .cart-item-row:hover { border-color: rgba(139, 21, 56, 0.2); background: var(--surface); box-shadow: var(--shadow-sm); }
        .cart-item-info .c-brand { font-size: 0.65rem; text-transform: uppercase; color: var(--maroon); font-weight: 800; letter-spacing: 0.1em; }
        .cart-item-info .c-model { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 1.15rem; color: var(--text-main); margin-top: 4px; }
        .btn-remove { background: white; border: 1px solid var(--border); color: var(--danger); font-size: 0.7rem; text-transform: uppercase; font-weight: 800; cursor: pointer; padding: 8px 16px; border-radius: 50px; transition: all 0.3s ease; letter-spacing: 0.05em; box-shadow: var(--shadow-sm); }
        .btn-remove:hover { background: var(--danger); color: white; border-color: var(--danger); transform: translateY(-2px); }
        
        .cart-footer { padding: 30px; border-top: 1px solid var(--border); background: var(--surface); box-shadow: 0 -10px 20px rgba(0,0,0,0.02); }
        .btn-checkout { width: 100%; height: 60px; font-size: 1rem; background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; border: none; border-radius: 16px; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25); }
        .btn-checkout:hover { filter: brightness(1.1); transform: translateY(-2px); }
        .btn-checkout:disabled { background: var(--border); color: var(--text-muted); cursor: not-allowed; box-shadow: none; transform: none; }

        /* PREMIUM MODAL */
        .modal-card { background: var(--surface); box-shadow: 0 24px 60px rgba(0, 0, 0, 0.2); border-radius: 32px; max-width: 900px; width: 100%; display: flex; text-align: left; position: relative; max-height: 90vh; overflow: hidden; }
        .modal-img { flex: 1; padding: 40px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #F4F7F9 0%, #FFFFFF 100%); border-right: 1px solid var(--border); overflow-y: auto; }
        .modal-img img { max-width: 100%; max-height: 100%; object-fit: contain; filter: drop-shadow(0 20px 30px rgba(0,0,0,0.1)); }
        
        .modal-details { flex: 1; padding: 50px; display: flex; flex-direction: column; position: relative; overflow-y: auto; background: var(--surface); }
        .modal-close-btn { position: absolute; top: 24px; right: 24px; background: #F4F7F9; border: 1px solid var(--border); font-size: 20px; cursor: pointer; color: var(--text-muted); transition: all 0.3s ease; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 10; box-shadow: var(--shadow-sm); }
        .modal-close-btn:hover { color: var(--danger); border-color: var(--danger); background: white; transform: rotate(90deg); }
        
        .modal-title { font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 900; line-height: 1.1; margin-bottom: 24px; text-transform: uppercase; color: var(--text-main); }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px; padding-bottom: 30px; border-bottom: 1px dashed var(--border); }
        .info-item label { font-size: 0.65rem; text-transform: uppercase; font-weight: 800; color: var(--text-light); letter-spacing: 0.1em; display: block; margin-bottom: 6px; }
        .info-item .value { font-size: 1.15rem; font-weight: 700; color: var(--text-main); word-wrap: break-word; }

        @media (max-width: 1024px) {
            .top-bar-wrapper { padding: 16px 0; margin-bottom: 20px; }
            .top-bar { flex-direction: column; align-items: stretch; gap: 16px; }
            .controls { justify-content: flex-start; }
            .search-wrapper { width: 100%; max-width: none; order: -1; } 
            .modal-card { flex-direction: column; border-radius: 24px; }
            .modal-img { border-right: none; border-bottom: 1px dashed var(--border); padding: 30px; min-height: 300px; }
            .modal-details { padding: 30px; }
        }

        /* FIXED MOBILE CSS FOR SEARCH BAR */
        @media (max-width: 600px) {
            .container { padding: 0 16px; }
            .page-title { font-size: 2.2rem; text-align: center; }
            .controls { flex-direction: column; align-items: stretch; gap: 10px; }
            
            /* FORCE SEARCH BAR TO BE EXACTLY 50PX TALL */
            .search-wrapper { 
                width: 100% !important; 
                max-width: 100% !important; 
                flex: 0 0 50px !important; 
                height: 50px !important; 
                min-height: 50px !important;
                max-height: 50px !important;
                display: flex;
                flex-direction: row;
                margin-bottom: 5px;
            }
            .search-input { height: 100%; border-radius: 50px 0 0 50px; }
            .search-btn { height: 100%; }

            .btn { width: 100% !important; flex: none; height: 50px; font-size: 0.85rem; } 
            .grid { grid-template-columns: 1fr; gap: 20px; margin-bottom: 40px; }
            .cart-drawer { width: 100%; }
            .cart-item-row { flex-direction: column; align-items: flex-start; gap: 16px; }
            .btn-remove { width: 100%; }
            .modal-title { font-size: 1.8rem; margin-bottom: 16px; }
            .modal-details { padding: 24px; }
            .modal-close-btn { top: 16px; right: 16px; }
            .info-grid { grid-template-columns: 1fr; gap: 16px; }
        }
    </style>
</head>
<body>

    <div id="systemData" style="display: none;" 
         data-items="<?php echo htmlspecialchars(json_encode($js_item_data, JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8'); ?>"
         data-suggestions="<?php echo htmlspecialchars(json_encode($allSuggestions, JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8'); ?>"
         data-csrf="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>"
         data-admin="<?php echo in_array($user_role, ['admin', 'super_admin']) ? 'true' : 'false'; ?>"
         data-is-search="<?php echo !empty($search) ? 'true' : 'false'; ?>">
    </div>

    <div class="top-bar-wrapper">
        <div class="container">
            <div class="top-bar">
                <h1 class="page-title">Machine <span class="accent">List</span></h1>
                <div class="controls">
                    
                    <form method="get" class="search-wrapper" id="searchForm">
                        <input type="text" name="search" id="searchInput" class="search-input" placeholder="Search by brand or model..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                        <button type="button" id="clearSearchBtn" class="clear-btn" style="display: <?php echo $search ? 'flex' : 'none'; ?>;" title="Clear Search">&times;</button>
                        <button type="submit" class="search-btn">Search</button>
                        <div id="searchSuggestions" class="custom-dropdown"></div>
                    </form>

                    <a href="add.php" class="btn">+ Add Item</a>
                    
                    <?php if (in_array($user_role, ['project', 'admin', 'super_admin'])): ?>
                        <a href="project/parser.php" class="btn" id="projectQuotationBtn">Project Quotation</a>
                    <?php endif; ?>
                    
                    <?php if (in_array($user_role, ['sales', 'admin', 'super_admin'])): ?>
                        <button class="btn cart-trigger" id="cartTrigger" onclick="openCart()">
                            Quote Cart (<span id="cartBadge">0</span>)
                        </button>
                    <?php endif; ?>

                    <?php if (in_array($user_role, ['admin', 'super_admin'])): ?>
                        <a href="admin/index.php" class="btn" style="color: var(--maroon); border-color: rgba(139, 21, 56, 0.2); background: var(--maroon-light);">
                            ⚙️ Command Center
                        </a>
                    <?php elseif ($user_role === 'sales'): 
                        $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM sales_quotations WHERE user_id = ? AND is_notified = 1");
                        $notifStmt->execute([$_SESSION['user_id']]);
                        $unreadNotifs = (int)$notifStmt->fetchColumn();
                    ?>
                        <a href="history.php" class="btn" style="position: relative;">
                            History
                            <?php if($unreadNotifs > 0): ?>
                                <span class="notif-badge"><?= $unreadNotifs ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <a href="logout.php" class="btn btn-logout">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php display_flash_message(); ?>

        <div class="grid">
            <?php foreach ($items as $item):
                $picture = $item['picture'] ?? '';
                $hasImage = ($picture !== '' && @file_exists(__DIR__ . '/../images/machine_images/' . $picture));
                $publicFilePath = '../images/machine_images/' . htmlspecialchars($picture, ENT_QUOTES, 'UTF-8');
            ?>
                <div class="card" id="card-<?php echo (int)$item['id']; ?>" onclick="openModal(<?php echo (int)$item['id']; ?>)">
                    
                    <div class="card-image">
                        <?php if ($hasImage): ?>
                            <img src="<?php echo $publicFilePath; ?>" alt="<?php echo htmlspecialchars($item['model_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                            <div class="no-img">NO IMAGE</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-content">
                        <div class="card-brand"><?php echo htmlspecialchars($item['brand'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="card-model"><?php echo htmlspecialchars($item['model_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php 
                            $cleanDesc = str_replace("\r", "", $item['description'] ?? '');
                            $firstLine = explode("\n", $cleanDesc)[0];
                        ?>
                        <div class="card-desc" title="<?php echo htmlspecialchars($item['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($firstLine, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="card-price">₱<?php echo number_format((float)($item['selling_price'] ?? 0), 2); ?></div>
                        
                        <?php if (in_array($user_role, ['sales', 'admin', 'super_admin'])): ?>
                            <button class="btn-select" onclick="toggleCartItem(event, <?php echo (int)$item['id']; ?>)">
                                Add to Quote
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $queryStr = http_build_query(array_filter(['search' => $search ?: null]));
                for ($i = 1; $i <= $totalPages; $i++):
                    $isActive = $i === $page;
                    $url = '?' . ($queryStr ? $queryStr . '&' : '') . 'page=' . $i;
                ?>
                    <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" class="page-link <?php echo $isActive ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (in_array($user_role, ['sales', 'admin', 'super_admin'])): ?>
    <div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
    <div class="cart-drawer" id="cartDrawer">
        <div class="cart-header">
            <h2>Quotation Cart (<span id="drawerCount">0</span>)</h2>
            <button class="btn-close" onclick="closeCart()">✕</button>
        </div>
        <div class="cart-items" id="cartItemsList"></div>
        <div class="cart-footer">
            
            <div class="cart-total-wrapper" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <span style="font-size: 0.85rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Est. Base Total</span>
                <span id="cartTotalPrice" style="font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 900; color: var(--maroon);">₱0.00</span>
            </div>

            <form action="sales/sales_form.php" method="POST" id="quoteForm">
                <input type="hidden" name="selected_items" id="selectedItemsInput">
                <button type="submit" class="btn-checkout" id="btnProceed" disabled>Proceed to Builder</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal-overlay" id="detailModal">
        <div class="modal-card">
            <div class="modal-img" id="modalImg">
                <img src="" id="modalImage" alt="Product" style="display: none;">
                <div class="no-img" id="modalNoImg" style="display: none;">NO IMAGE</div>
            </div>
            <div class="modal-details">
                <button class="modal-close-btn" onclick="closeModal()">✕</button>
                <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: var(--maroon); letter-spacing: 0.15em; margin-bottom: 8px;" id="modalBrand"></div>
                <h2 class="modal-title" id="modalModel"></h2>
                
                <div class="info-grid">
                    <div class="info-item"><label>Base Price</label><div class="value" style="color: var(--maroon);" id="modalPrice"></div></div>
                    <div class="info-item admin-only"><label>Currency</label><div class="value" id="modalCurrency"></div></div>
                    <div class="info-item admin-only"><label>Buying Cost</label><div class="value" id="modalCost"></div></div>
                </div>
                
                <div>
                    <label style="font-size: 0.7rem; text-transform: uppercase; font-weight: 800; color: var(--text-light); letter-spacing: 0.1em; display: block; margin-bottom: 12px;">Technical Description</label>
                    <p id="modalDesc" style="font-size: 0.95rem; line-height: 1.7; white-space: pre-line; color: var(--text-muted);"></p>
                </div>
                
                <div id="modalPdfSection" style="margin-top: 30px; padding-top: 24px; border-top: 1px dashed var(--border);"></div>
                
                <div style="margin-top: auto; padding-top: 30px; display: flex; gap: 12px; justify-content: flex-end;">
                    <a id="modalEditBtn" href="#" style="background: var(--maroon-light); color: var(--maroon); border: 1px solid rgba(139, 21, 56, 0.2); font-size: 0.8rem; font-family: 'Outfit', sans-serif; text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; text-decoration: none; padding: 12px 24px; border-radius: 12px; transition: all 0.3s ease; box-shadow: var(--shadow-sm);">
                        Edit Record
                    </a>

                    <a id="modalDeleteBtn" href="#" class="admin-only" style="background: #FFF5F5; color: var(--danger); border: 1px solid #FECACA; font-size: 0.8rem; font-family: 'Outfit', sans-serif; text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; text-decoration: none; padding: 12px 24px; border-radius: 12px; transition: all 0.3s ease; box-shadow: var(--shadow-sm);">
                        Delete
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const domData = document.getElementById('systemData');
        
        let itemDatabase = {};
        let suggestionsData = [];
        
        try {
            itemDatabase = JSON.parse(domData.getAttribute('data-items') || '{}');
            suggestionsData = JSON.parse(domData.getAttribute('data-suggestions') || '[]');
        } catch (e) {
            console.error("Failed to parse system data:", e);
        }

        const csrfToken = domData.getAttribute('data-csrf') || '';
        const isAdmin = domData.getAttribute('data-admin') === 'true';
        const isSearchActive = domData.getAttribute('data-is-search') === 'true';

        // Hide admin-only elements
        if (!isAdmin) {
            document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'none');
        }

        // 1. SEARCH
        const searchInput = document.getElementById('searchInput');
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        const suggestionBox = document.getElementById('searchSuggestions');
        const searchForm = document.getElementById('searchForm');

        if(searchInput) {
            searchInput.addEventListener('input', function() {
                const val = this.value.trim().toLowerCase();
                clearSearchBtn.style.display = val ? 'flex' : 'none'; 
                suggestionBox.innerHTML = '';
                
                if (val.length >= 2) {
                    const matches = suggestionsData.filter(item => 
                        (item.brand && item.brand.toLowerCase().includes(val)) || 
                        (item.model_no && item.model_no.toLowerCase().includes(val))
                    );

                    if (matches.length > 0) {
                        const uniqueMatches = new Map();
                        matches.forEach(item => {
                            if (!uniqueMatches.has(item.model_no)) {
                                uniqueMatches.set(item.model_no, item.brand);
                            }
                        });

                        let count = 0;
                        uniqueMatches.forEach((brand, model) => {
                            if (count >= 10) return;
                            const div = document.createElement('div');
                            div.className = 'custom-dropdown-item';
                            div.innerHTML = `<span class="sugg-model">${model}</span><span class="sugg-brand">${brand || 'UNBRANDED'}</span>`;
                            div.addEventListener('click', () => {
                                searchInput.value = model;
                                suggestionBox.style.display = 'none';
                                searchForm.submit(); 
                            });
                            suggestionBox.appendChild(div);
                            count++;
                        });
                        suggestionBox.style.display = 'block';
                    } else {
                        suggestionBox.style.display = 'none';
                    }
                } else {
                    suggestionBox.style.display = 'none';
                }
            });

            clearSearchBtn.addEventListener('click', () => {
                searchInput.value = '';
                window.location.href = 'index.php'; 
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-wrapper')) suggestionBox.style.display = 'none';
            });
        }

        // 2. TIMEOUT
        let idleTimer;
        function resetIdleTimer() {
            clearTimeout(idleTimer);
            const isCurrentlySearching = searchInput && searchInput.value.trim() !== '' || isSearchActive;
            if (isCurrentlySearching) {
                idleTimer = setTimeout(() => { window.location.href = 'index.php'; }, 30000);
            }
        }
        ['mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(evt => window.addEventListener(evt, resetIdleTimer));
        resetIdleTimer();

        // 3. CART LOGIC
        let cartData = JSON.parse(sessionStorage.getItem('quoteCartData') || '[]');

        function toggleCartItem(event, id) {
            event.stopPropagation(); 
            const index = cartData.findIndex(item => item.id == id);
            
            if (index > -1) { 
                cartData.splice(index, 1); 
            } else {
                const data = itemDatabase[id];
                if(data) cartData.push({ id: id, brand: data.brand, model: data.model_no }); 
            }
            sessionStorage.setItem('quoteCartData', JSON.stringify(cartData));
            updateCartUI();
        }

        function removeCartItem(id) {
            const index = cartData.findIndex(item => item.id == id);
            if (index > -1) {
                cartData.splice(index, 1);
                sessionStorage.setItem('quoteCartData', JSON.stringify(cartData));
                updateCartUI();
            }
        }

        function updateCartUI() {
            const badge = document.getElementById('cartBadge');
            if(!badge) return; // Exit if not Sales/Admin

            const count = cartData.length;
            badge.textContent = count;
            document.getElementById('drawerCount').textContent = count;
            
            const trigger = document.getElementById('cartTrigger');
            if (count > 0) trigger.classList.add('has-items');
            else trigger.classList.remove('has-items');

            const proceedBtn = document.getElementById('btnProceed');
            const hiddenInput = document.getElementById('selectedItemsInput');
            
            if (count > 0) {
                proceedBtn.disabled = false;
                hiddenInput.value = JSON.stringify(cartData.map(item => item.id));
            } else {
                proceedBtn.disabled = true;
                hiddenInput.value = '';
            }

            const listContainer = document.getElementById('cartItemsList');
            listContainer.innerHTML = ''; 
            
            let cartTotal = 0; // Initialize Total Counter

            cartData.forEach(item => {
                const data = itemDatabase[item.id];
                const price = data ? parseFloat(data.selling_price) || 0 : 0;
                cartTotal += price; // Add to Live Total

                const row = document.createElement('div');
                row.className = 'cart-item-row';
                // Added the dynamic price string beneath the model name
                row.innerHTML = `
                    <div class="cart-item-info">
                        <div class="c-brand">${item.brand || 'Unbranded'}</div>
                        <div class="c-model">${item.model}</div>
                        <div style="font-size: 0.85rem; font-family: 'DM Sans', sans-serif; font-weight: 700; color: var(--text-muted); margin-top: 4px;">₱${price.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                    </div>
                    <button class="btn-remove" onclick="removeCartItem(${item.id})">Remove</button>
                `;
                listContainer.appendChild(row);
            });

            // Update Total UI element
            const cartTotalEl = document.getElementById('cartTotalPrice');
            if(cartTotalEl) {
                cartTotalEl.textContent = '₱' + cartTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            document.querySelectorAll('.card').forEach(card => card.classList.remove('is-selected'));
            cartData.forEach(item => {
                const cardEl = document.getElementById('card-' + item.id);
                if (cardEl) cardEl.classList.add('is-selected');
            });
        }

        function openCart() {
            const overlay = document.getElementById('cartOverlay');
            if(overlay) {
                overlay.classList.add('active');
                document.getElementById('cartDrawer').classList.add('active');
            }
        }

        function closeCart() {
            const overlay = document.getElementById('cartOverlay');
            if(overlay) {
                overlay.classList.remove('active');
                document.getElementById('cartDrawer').classList.remove('active');
            }
        }

        // 4. MODAL LOGIC 
        function openModal(id) {
            const data = itemDatabase[id];
            if(!data) return;

            document.getElementById('modalBrand').textContent = data.brand;
            document.getElementById('modalModel').textContent = data.model_no;
            document.getElementById('modalDesc').textContent = data.description || '';
            
            const rawPrice = parseFloat(data.selling_price) || 0;
            document.getElementById('modalPrice').textContent = '₱' + rawPrice.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            const editBtn = document.getElementById('modalEditBtn');
            if (editBtn) editBtn.href = 'edit.php?id=' + data.id;

            if (isAdmin) {
                const currencyEl = document.getElementById('modalCurrency');
                if (currencyEl) currencyEl.textContent = data.buying_currency || '-';
                
                const costEl = document.getElementById('modalCost');
                if (costEl) {
                    const costVal = parseFloat(data.buying_cost) || 0;
                    costEl.textContent = costVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                
                const deleteBtn = document.getElementById('modalDeleteBtn');
                if (deleteBtn) deleteBtn.href = 'delete.php?id=' + data.id + '&token=' + csrfToken;
            }

            const imgElement = document.getElementById('modalImage');
            const noImgElement = document.getElementById('modalNoImg');

            if (data.image) {
                imgElement.src = data.image;
                imgElement.style.display = 'block';
                noImgElement.style.display = 'none';
            } else {
                imgElement.style.display = 'none';
                noImgElement.style.display = 'block';
            }

            const pdfSection = document.getElementById('modalPdfSection');
            if (data.pdf_path) {
                // FIXED: Restored the proper ../ linking to the pdfs folder!
                pdfSection.innerHTML = `
                    <label style="font-size: 0.7rem; text-transform: uppercase; font-weight: 800; color: var(--text-light); letter-spacing: 0.1em; display: block; margin-bottom: 12px;">Documentation</label>
                    <a href="../pdfs/machine_pdfs/${data.pdf_path}" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-hover) 100%); color: white; padding: 12px 24px; border-radius: 12px; text-decoration: none; font-weight: 800; font-family: 'Outfit', sans-serif; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.85rem; transition: all 0.3s ease; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.25);">
                        📄 View PDF Brochure
                    </a>
                `;
            } else {
                pdfSection.innerHTML = `
                    <label style="font-size: 0.7rem; text-transform: uppercase; font-weight: 800; color: var(--maroon); letter-spacing: 0.1em; display: block; margin-bottom: 12px;">Upload PDF Documentation</label>
                    <form action="upload_pdf.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                        <input type="hidden" name="item_id" value="${data.id}">
                        <input type="hidden" name="token" value="${csrfToken}">
                        <input type="file" name="pdf_file" accept="application/pdf" required style="font-size: 0.9rem; font-weight: 500; font-family: 'DM Sans', sans-serif; border: 1px dashed var(--maroon); background: var(--maroon-light); border-radius: 12px; padding: 12px; flex: 1; min-width: 200px; color: var(--maroon);">
                        <button type="submit" style="background: var(--maroon); border: none; color: white; padding: 14px 24px; border-radius: 12px; font-family: 'Outfit', sans-serif; text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; font-size: 0.85rem; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2);">Upload</button>
                    </form>
                `;
            }

            document.getElementById('detailModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('active');
        }

        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ESC KEY EVENT LISTENER
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeCart();
            }
        });

        document.addEventListener('DOMContentLoaded', updateCartUI);
    </script>
</body>
</html>