<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require 'db.php';

// Pagination setup
$perPage = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM items";
$sql = "SELECT * FROM items";
$search = $_GET['search'] ?? '';
$params = [];

// Apply search filters
if ($search) {
    $where = " WHERE brand LIKE ? OR model_no LIKE ? OR description LIKE ? OR factor LIKE ?";
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Machine Inventory - AM Group</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/inventory.css">
</head>
<body>
    <div class="container">
        
        <div class="top-bar">
            <h1 class="page-title">Machine <span class="accent">List</span></h1>
            <div class="controls">
                <form method="get" class="search-wrapper">
                    <input type="text" name="search" class="search-input" placeholder="Search inventory..." value="<?=htmlspecialchars($search)?>">
                    <button type="submit" class="search-btn">Find</button>
                </form>
                <a href="add.php" class="btn">+ Add Item</a>
                <a href="schedule_parser.php" class="btn" id="projectQuotationBtn">Project Quotation</a>
                <button class="btn cart-trigger" id="cartTrigger" onclick="openCart()">
                    Quote Cart (<span id="cartBadge">0</span>)
                </button>
            </div>
        </div>

        <div class="grid">
            <?php foreach ($items as $item):
                $serverFilePath = __DIR__ . '/../images/machine_images/' . $item['picture'];
                $publicFilePath = '../images/machine_images/' . htmlspecialchars($item['picture']);
                $hasImage = ($item['picture'] && file_exists($serverFilePath));
            ?>
                <div class="card" id="card-<?= $item['id'] ?>" onclick='openModal(<?=htmlspecialchars(json_encode([
                    "id" => $item["id"],
                    "brand" => $item["brand"],
                    "model_no" => $item["model_no"],
                    "description" => $item["description"],
                    "buying_currency" => $item["buying_currency"],
                    "buying_cost" => $item["buying_cost"],
                    "factor" => $item["factor"],
                    "selling_price" => number_format($item["selling_price"], 2),
                    "image" => $hasImage ? $publicFilePath : null
                ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8")?>)'>
                    
                    <div class="card-image">
                        <?php if ($hasImage): ?>
                            <img src="<?=$publicFilePath?>" alt="<?=htmlspecialchars($item['model_no'])?>">
                        <?php else: ?>
                            <div class="no-img">NO IMAGE</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-content">
                        <div class="card-brand"><?=htmlspecialchars($item['brand'] ?? 'Unknown')?></div>
                        <div class="card-model"><?=htmlspecialchars($item['model_no'])?></div>
                        <?php 
                            $cleanDesc = str_replace("\r", "", $item['description']);
                            $firstLine = explode("\n", $cleanDesc)[0];
                        ?>
                        <div class="card-desc" title="<?=htmlspecialchars($item['description'])?>">
                            <?=htmlspecialchars($firstLine)?>
                        </div>
                        <div class="card-price">₱<?=number_format($item['selling_price'], 2)?></div>
                        <button class="btn-select" onclick="toggleCartItem(event, <?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['brand'])) ?>', '<?= htmlspecialchars(addslashes($item['model_no'])) ?>')">
                            Select
                        </button>
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
                    <a href="<?=$url?>" class="page-link <?=$isActive ? 'active' : ''?>"><?=$i?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
    <div class="cart-drawer" id="cartDrawer">
        <div class="cart-header">
            <h2>Cart (<span id="drawerCount">0</span>)</h2>
            <button class="btn-close" onclick="closeCart()">✕</button>
        </div>
        <div class="cart-items" id="cartItemsList"></div>
        <div class="cart-footer">
            <form action="sales_quote_form.php" method="POST" id="quoteForm">
                <input type="hidden" name="selected_items" id="selectedItemsInput">
                <button type="submit" class="btn-checkout" id="btnProceed" disabled>Proceed to Quotation</button>
            </form>
        </div>
    </div>

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
                    <div class="info-item"><label>Currency</label><div class="value" id="modalCurrency"></div></div>
                    <div class="info-item"><label>Buying Cost</label><div class="value" id="modalCost"></div></div>
                </div>
                
                <div>
                    <label style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.1em; display: block; margin-bottom: 8px;">Technical Description</label>
                    <p id="modalDesc" style="font-size: 0.9rem; line-height: 1.6; white-space: pre-line; color: var(--text-main);"></p>
                </div>
                
                <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border); text-align: right;">
                    <a id="modalDeleteBtn" href="#" style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; text-decoration: underline;">Delete Record</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        let cartData = JSON.parse(sessionStorage.getItem('quoteCartData') || '[]');

        function toggleCartItem(event, id, brand, model) {
            event.stopPropagation(); 
            const index = cartData.findIndex(item => item.id == id);
            if (index > -1) { cartData.splice(index, 1); } 
            else { cartData.push({ id: id, brand: brand, model: model }); }
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
            const count = cartData.length;
            document.getElementById('cartBadge').textContent = count;
            document.getElementById('drawerCount').textContent = count;
            
            const trigger = document.getElementById('cartTrigger');
            if (count > 0) trigger.classList.add('has-items');
            else trigger.classList.remove('has-items');

            const proceedBtn = document.getElementById('btnProceed');
            const hiddenInput = document.getElementById('selectedItemsInput');
            
            if (count > 0) {
                proceedBtn.disabled = false;
                const justIds = cartData.map(item => item.id);
                hiddenInput.value = JSON.stringify(justIds);
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
            document.getElementById('cartOverlay').classList.add('active');
            document.getElementById('cartDrawer').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCart() {
            document.getElementById('cartOverlay').classList.remove('active');
            document.getElementById('cartDrawer').classList.remove('active');
            document.body.style.overflow = '';
        }

        const csrfToken = '<?= $csrf_token ?>';

        function openModal(data) {
            document.getElementById('modalBrand').textContent = data.brand || 'Unbranded';
            document.getElementById('modalModel').textContent = data.model_no || '';
            document.getElementById('modalDesc').textContent = data.description || '';
            document.getElementById('modalCurrency').textContent = data.buying_currency || '-';
            document.getElementById('modalCost').textContent = data.buying_cost || '-';
            document.getElementById('modalPrice').textContent = '₱' + data.selling_price;
            document.getElementById('modalDeleteBtn').href = 'delete.php?id=' + data.id + '&token=' + csrfToken;

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

            document.getElementById('detailModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.addEventListener('DOMContentLoaded', updateCartUI);
    </script>
</body>
</html>