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
    // FIXED: Added ../ to properly target the images folder outside of code/
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg: #F8F6F5;
            --surface: #FFFFFF;
            --text-main: #2A0808;
            --text-muted: #8C7373;
            --border: #E8D8D7;
            --maroon: #8B1538;
            --maroon-light: #FFF5F7;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-main); padding: 40px 30px; min-height: 100vh; }
        .container { max-width: 1600px; margin: 0 auto; }

        .top-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 40px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .page-title { font-family: 'Outfit', sans-serif; font-size: 3rem; font-weight: 900; letter-spacing: -0.04em; text-transform: uppercase; line-height: 1; }
        .page-title .accent { color: var(--maroon); }
        .controls { display: flex; gap: 16px; align-items: center; }

        .search-wrapper { position: relative; overflow: visible !important; background: var(--surface); display: flex; align-items: center; border: 1px solid var(--border); border-radius: 50px; width: 350px; height: 48px; transition: border-color 0.3s ease; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.02); }
        .search-wrapper:focus-within { border-color: var(--maroon); box-shadow: 0 4px 16px rgba(139, 21, 56, 0.08); }
        .search-input { flex: 1; border: none; background: transparent; padding: 0 20px; font-size: 0.9rem; outline: none; color: var(--text-main); min-width: 0; }
        .search-btn { background: var(--maroon); color: white; border: none; border-radius: 0 50px 50px 0; height: 100%; padding: 0 24px; font-weight: 800; font-family: 'Outfit', sans-serif; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; cursor: pointer; transition: all 0.2s ease; }
        .search-btn:hover { background: #5A0000; }
        .clear-btn { background: transparent; border: none; color: var(--maroon); font-size: 1.4rem; cursor: pointer; padding: 0 12px; line-height: 1; display: none; align-items: center; justify-content: center; height: 100%; transition: all 0.2s ease; }
        .clear-btn:hover { color: #5A0000; transform: scale(1.15); }

        .custom-dropdown { position: absolute; top: calc(100% + 8px); left: 0; width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; max-height: 250px; overflow-y: auto; z-index: 1000; display: none; box-shadow: 0 10px 30px rgba(42, 8, 8, 0.08); }
        .custom-dropdown-item { padding: 12px 20px; font-size: 0.9rem; color: var(--text-main); cursor: pointer; transition: all 0.2s ease; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 2px; }
        .custom-dropdown-item:last-child { border-bottom: none; }
        .custom-dropdown-item:hover { background: var(--maroon-light); padding-left: 26px; }
        .sugg-model { font-family: 'Outfit', sans-serif; font-weight: 800; color: var(--text-main); font-size: 1rem; }
        .sugg-brand { font-size: 0.7rem; color: var(--maroon); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }

        .btn { font-family: 'Outfit', sans-serif; height: 48px; padding: 0 24px; font-weight: 800; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; border: none; border-radius: 50px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; background: var(--maroon-light); color: var(--maroon); transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); white-space: nowrap; }
        .btn:hover { background: var(--maroon); color: white; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(139, 21, 56, 0.15); }

        .cart-trigger { background: var(--surface); border: 2px solid var(--border); color: var(--text-main); }
        .cart-trigger:hover { border-color: var(--text-main); background: var(--surface); color: var(--text-main); }
        .cart-trigger.has-items { background: var(--maroon); color: white; border: none; box-shadow: 0 8px 20px rgba(139, 21, 56, 0.2); }
        .cart-trigger.has-items:hover { background: #5A0000; }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 24px; margin-bottom: 60px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; cursor: pointer; transition: all 0.3s ease; display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .card:hover { border-color: var(--maroon); transform: translateY(-4px); box-shadow: 0 10px 30px rgba(139, 21, 56, 0.05); }
        .card.is-selected { border: 2px solid var(--maroon); transform: translateY(0); }
        .card-image { width: 100%; height: 200px; display: flex; align-items: center; justify-content: center; padding: 24px; border-bottom: 1px solid var(--border); background: #FFFFFF; }
        .card-image img { max-width: 100%; max-height: 100%; object-fit: contain; transition: transform 0.5s ease; }
        .card:hover .card-image img { transform: scale(1.05); }
        .card-image .no-img { color: #CCCCCC; font-weight: 700; font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; }
        .card-content { padding: 24px; flex: 1; display: flex; flex-direction: column; }
        .card-brand { font-size: 0.65rem; text-transform: uppercase; font-weight: 800; color: var(--maroon); letter-spacing: 0.15em; margin-bottom: 6px; }
        .card-model { font-family: 'Outfit', sans-serif; font-size: 1.25rem; font-weight: 900; color: var(--text-main); margin-bottom: 6px; line-height: 1.2; word-wrap: break-word; }
        .card-desc { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .card-price { font-size: 1.15rem; font-weight: 900; color: var(--maroon); margin-bottom: 24px; }
        .btn-select { margin-top: auto; width: 100%; padding: 12px; background: transparent; border: 1px solid var(--border); border-radius: 50px; color: var(--text-main); font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: all 0.3s ease; }
        .btn-select:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-light); }
        .card.is-selected .btn-select { background: var(--maroon); color: white; border-color: var(--maroon); font-size: 0; }
        .card.is-selected .btn-select::after { content: "SELECTED"; font-size: 0.8rem; }

        .pagination { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 50px; }
        .page-link { padding: 10px 18px; border: 1px solid var(--border); border-radius: 8px; color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 0.85rem; background: var(--surface); transition: all 0.2s ease; }
        .page-link:hover { border-color: var(--maroon); color: var(--maroon); background: var(--maroon-light); }
        .page-link.active { background: var(--maroon); color: white; border-color: var(--maroon); }

        .cart-overlay { position: fixed; inset: 0; background: rgba(248, 246, 245, 0.85); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 998; display: none; opacity: 0; transition: opacity 0.3s ease; }
        .cart-overlay.active { display: block; opacity: 1; }
        .cart-drawer { position: fixed; top: 0; right: 0; width: 400px; height: 100vh; background: var(--surface); border-left: 1px solid var(--border); z-index: 999; display: flex; flex-direction: column; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
        .cart-drawer.active { transform: translateX(0); }
        .cart-header { padding: 30px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .cart-header h2 { font-family: 'Outfit', sans-serif; font-size: 1.5rem; font-weight: 900; text-transform: uppercase; }
        .btn-close { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); transition: color 0.2s; }
        .btn-close:hover { color: var(--maroon); }
        .cart-items { flex: 1; overflow-y: auto; padding: 30px; display: flex; flex-direction: column; gap: 20px; }
        .cart-item-row { display: flex; justify-content: space-between; align-items: center; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
        .cart-item-info .c-brand { font-size: 0.65rem; text-transform: uppercase; color: var(--maroon); font-weight: 800; letter-spacing: 0.1em; }
        .cart-item-info .c-model { font-family: 'Outfit', sans-serif; font-weight: 900; font-size: 1.15rem; color: var(--text-main); margin-top: 2px; }
        .btn-remove { background: #FFF5F7; border: 1px solid rgba(139, 21, 56, 0.15); color: var(--maroon); font-size: 0.7rem; text-transform: uppercase; font-weight: 800; cursor: pointer; padding: 8px 14px; border-radius: 50px; transition: all 0.2s ease; letter-spacing: 0.05em; }
        .btn-remove:hover { background: var(--maroon); color: white; box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2); transform: translateY(-1px); }
        .cart-footer { padding: 30px; border-top: 1px solid var(--border); background: var(--surface); }
        .btn-checkout { width: 100%; height: 56px; font-size: 1rem; background: var(--maroon); color: white; border: none; border-radius: 50px; font-family: 'Outfit', sans-serif; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; transition: background 0.3s; }
        .btn-checkout:hover { background: #5A0000; }
        .btn-checkout:disabled { background: var(--border); color: var(--text-muted); cursor: not-allowed; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(248, 246, 245, 0.9); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 1000; display: none; padding: 5vh 20px; overflow-y: auto; text-align: center; }
        .modal-overlay.active { display: block; }
        .modal-card { background: var(--surface); border: 1px solid var(--maroon); box-shadow: 0 24px 60px rgba(139, 21, 56, 0.12); border-radius: 24px; max-width: 900px; width: 100%; display: inline-flex; text-align: left; position: relative; margin: 0 auto; }
        .modal-img { flex: 1; padding: 40px; border-right: 1px dashed rgba(139, 21, 56, 0.3); display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #FFFFFF 0%, #FFF5F7 100%); border-radius: 24px 0 0 24px; }
        .modal-img img { max-width: 100%; max-height: 100%; object-fit: contain; filter: drop-shadow(0 10px 20px rgba(0,0,0,0.05)); }
        
        .modal-details { flex: 1; padding: 50px; display: flex; flex-direction: column; position: relative; }
        .modal-close-btn { position: absolute; top: 24px; right: 24px; background: transparent; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); transition: all 0.2s ease; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; z-index: 10; }
        .modal-close-btn:hover { color: var(--maroon); background: #FFF5F7; border-radius: 50%; }
        
        .modal-title { font-family: 'Outfit', sans-serif; font-size: 2.2rem; font-weight: 900; line-height: 1.1; margin-bottom: 24px; text-transform: uppercase; color: var(--maroon); }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px; padding-bottom: 30px; border-bottom: 1px solid var(--border); }
        .info-item label { font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.1em; display: block; margin-bottom: 4px; }
        .info-item .value { font-size: 1.1rem; font-weight: 700; color: var(--text-main); word-wrap: break-word; }

        @media (max-width: 1024px) {
            .top-bar { flex-direction: column; align-items: stretch; gap: 24px; margin-bottom: 30px; }
            .controls { flex-wrap: wrap; justify-content: space-between; gap: 12px; }
            .search-wrapper { width: 100%; order: -1; } 
            .btn { flex: 1 1 calc(33.333% - 12px); justify-content: center; padding: 0 12px; font-size: 0.75rem; }
            .modal-card { flex-direction: column; }
            .modal-img { border-right: none; border-bottom: 1px dashed rgba(139, 21, 56, 0.3); padding: 24px; min-height: 250px; border-radius: 24px 24px 0 0; }
            .modal-details { padding: 30px; }
            .info-grid { grid-template-columns: 1fr; gap: 16px; margin-bottom: 20px; padding-bottom: 20px; }
        }

        @media (max-width: 600px) {
            body { padding: 20px 16px; }
            .page-title { font-size: 2.2rem; text-align: center; }
            .controls { flex-direction: column; align-items: stretch; gap: 14px; }
            .search-wrapper { height: 54px; }
            .btn { flex: 1 1 100%; width: 100%; height: 54px; font-size: 0.95rem; } 
            .grid { grid-template-columns: 1fr; gap: 16px; margin-bottom: 40px; }
            .cart-drawer { width: 100%; }
            .cart-header, .cart-items, .cart-footer { padding: 20px; }
            .cart-item-row { flex-direction: column; align-items: flex-start; gap: 12px; }
            .btn-remove { align-self: flex-start; }
            .modal-title { font-size: 1.8rem; margin-bottom: 16px; }
            .modal-details { padding: 20px; }
            .modal-close-btn { top: 10px; right: 10px; }
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

    <div class="container">
        <div class="top-bar">
            <h1 class="page-title">Machine <span class="accent">List</span></h1>
            <div class="controls">
                
                <form method="get" class="search-wrapper" id="searchForm">
                    <input type="text" name="search" id="searchInput" class="search-input" placeholder="Search inventory..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    <button type="button" id="clearSearchBtn" class="clear-btn" style="display: <?php echo $search ? 'flex' : 'none'; ?>;" title="Clear Search">&times;</button>
                    <button type="submit" class="search-btn">Find</button>
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
            </div>
        </div>

        <?php display_flash_message(); ?>

        <div class="grid">
            <?php foreach ($items as $item):
                $picture = $item['picture'] ?? '';
                // FIXED: Added ../ to path
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
                                Select
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
            <h2>Cart (<span id="drawerCount">0</span>)</h2>
            <button class="btn-close" onclick="closeCart()">✕</button>
        </div>
        <div class="cart-items" id="cartItemsList"></div>
        <div class="cart-footer">
            <form action="sales/sales_form.php" method="POST" id="quoteForm">
                <input type="hidden" name="selected_items" id="selectedItemsInput">
                <button type="submit" class="btn-checkout" id="btnProceed" disabled>Proceed to Quotation</button>
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
                <h2 class="modal-title" id="modalModel"></h2>
                
                <div class="info-grid">
                    <div class="info-item"><label>Brand</label><div class="value" id="modalBrand"></div></div>
                    <div class="info-item"><label>Base Price</label><div class="value" style="color: var(--maroon);" id="modalPrice"></div></div>
                    <div class="info-item admin-only"><label>Currency</label><div class="value" id="modalCurrency"></div></div>
                    <div class="info-item admin-only"><label>Buying Cost</label><div class="value" id="modalCost"></div></div>
                </div>
                
                <div>
                    <label style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.1em; display: block; margin-bottom: 8px;">Technical Description</label>
                    <p id="modalDesc" style="font-size: 0.9rem; line-height: 1.6; white-space: pre-line; color: var(--text-main);"></p>
                </div>
                
                <div id="modalPdfSection" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border);"></div>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border); text-align: right;">
                    <a id="modalEditBtn" href="#" style="color: #fff; background: #8B1538; font-size: 0.75rem; text-transform: uppercase; font-weight: 800; text-decoration: none; padding: 8px 16px; border-radius: 8px; margin-right: 10px; display: inline-block;">
                        Edit Record
                    </a>

                    <a id="modalDeleteBtn" href="#" class="admin-only" style="color: var(--maroon); font-size: 0.75rem; text-transform: uppercase; font-weight: 800; text-decoration: none; padding: 8px 16px; border: 1px solid rgba(139, 21, 56, 0.2); border-radius: 8px; transition: all 0.2s; background: #FFF5F7; display: inline-block;">
                        Delete Record
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
            
            cartData.forEach(item => {
                const row = document.createElement('div');
                row.className = 'cart-item-row';
                row.innerHTML = `
                    <div class="cart-item-info">
                        <div class="c-brand">${item.brand || 'Unbranded'}</div>
                        <div class="c-model">${item.model}</div>
                    </div>
                    <button class="btn-remove" onclick="removeCartItem(${item.id})">Remove</button>
                `;
                listContainer.appendChild(row);
            });

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
            
            // Format to 000,000.00 using toLocaleString
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
                // FIXED: Added ../ to PDF path
                pdfSection.innerHTML = `
                    <label style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.1em; display: block; margin-bottom: 8px;">Documentation</label>
                    <a href="../pdfs/machine_pdfs/${data.pdf_path}" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; background: var(--maroon); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.85rem; transition: background 0.2s;">
                        📄 View PDF Brochure
                    </a>
                `;
            } else {
                pdfSection.innerHTML = `
                    <label style="font-size: 0.65rem; text-transform: uppercase; font-weight: 800; color: var(--maroon); letter-spacing: 0.1em; display: block; margin-bottom: 8px;">Upload PDF Documentation</label>
                    <form action="upload_pdf.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                        <input type="hidden" name="item_id" value="${data.id}">
                        <input type="hidden" name="token" value="${csrfToken}">
                        <input type="file" name="pdf_file" accept="application/pdf" required style="font-size: 0.85rem; border: 1px dashed var(--maroon); background: #FFF5F7; border-radius: 8px; padding: 8px; flex: 1; min-width: 200px; color: var(--maroon); font-weight: 500;">
                        <button type="submit" style="background: var(--maroon); border: none; color: white; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: background 0.3s; box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2);">Upload</button>
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

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            const detailModal = document.getElementById('detailModal');
            if (detailModal && detailModal.classList.contains('active')) {
                closeModal();
                return;
            }
            const cartDrawer = document.getElementById('cartDrawer');
            if (cartDrawer && cartDrawer.classList.contains('active')) {
                closeCart();
            }
        });

        document.addEventListener('DOMContentLoaded', updateCartUI);
    </script>
</body>
</html>
