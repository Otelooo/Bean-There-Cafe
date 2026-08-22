<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';
require_once __DIR__ . '/../unit_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe staff') {
    header('Location: ../signin.php');
    exit;
}

// Normalizes a user-typed category name to Title Case (e.g. "pasta" / "PASTA" -> "Pasta")
// so categories display consistently no matter how the owner/staff typed them in.
function format_category_name(string $raw): string
{
    return mb_convert_case(trim($raw), MB_CASE_TITLE, 'UTF-8');
}

// Deleting a category shouldn't be blocked by products still using it — they get moved here instead.
function resolve_uncategorized_category_id(mysqli $conn): int
{
    $name = 'Uncategorized';
    $check = $conn->prepare('SELECT product_category_id FROM product_category WHERE product_category = ?');
    $check->bind_param('s', $name);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        return (int)$existing['product_category_id'];
    }

    $insert = $conn->prepare('INSERT INTO product_category (product_category) VALUES (?)');
    $insert->bind_param('s', $name);
    $insert->execute();
    $id = (int)$insert->insert_id;
    $insert->close();
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg = '';
    $msgType = 'success';

    if ($action === 'add_category') {
        $categoryName = format_category_name($_POST['category_name'] ?? '');
        if ($categoryName === '') {
            $msg = 'Please enter a category name.';
            $msgType = 'warn';
        } else {
            $check = $conn->prepare('SELECT product_category_id FROM product_category WHERE LOWER(product_category) = LOWER(?)');
            $check->bind_param('s', $categoryName);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $msg = 'That category already exists.';
                $msgType = 'warn';
            } else {
                $insert = $conn->prepare('INSERT INTO product_category (product_category) VALUES (?)');
                $insert->bind_param('s', $categoryName);
                $insert->execute();
                $insert->close();
                $msg = 'Category added.';
            }
        }
    } elseif ($action === 'edit_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryName = format_category_name($_POST['category_name'] ?? '');
        if ($categoryId <= 0 || $categoryName === '') {
            $msg = 'Please enter a category name.';
            $msgType = 'warn';
        } else {
            $check = $conn->prepare('SELECT product_category_id FROM product_category WHERE LOWER(product_category) = LOWER(?) AND product_category_id != ?');
            $check->bind_param('si', $categoryName, $categoryId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $msg = 'That category already exists.';
                $msgType = 'warn';
            } else {
                $update = $conn->prepare('UPDATE product_category SET product_category = ? WHERE product_category_id = ?');
                $update->bind_param('si', $categoryName, $categoryId);
                $update->execute();
                $update->close();
                $msg = 'Category updated.';
            }
        }
    } elseif ($action === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        try {
            $uncategorizedId = resolve_uncategorized_category_id($conn);
            if ($uncategorizedId !== $categoryId) {
                $reassign = $conn->prepare('UPDATE products SET product_category_id = ? WHERE product_category_id = ?');
                $reassign->bind_param('ii', $uncategorizedId, $categoryId);
                $reassign->execute();
                $reassign->close();
            }

            $stmt = $conn->prepare('DELETE FROM product_category WHERE product_category_id = ?');
            $stmt->bind_param('i', $categoryId);
            $stmt->execute();
            $stmt->close();
            $msg = 'Category deleted.';
        } catch (Throwable $e) {
            $msg = 'Cannot delete this category.';
            $msgType = 'warn';
        }
    }

    header('Location: staff_products.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
    exit;
}

$msg = $_GET['msg'] ?? '';
$msgType = $_GET['type'] ?? 'success';

$settings = get_system_settings($conn);
$criticalStockThreshold = (int)$settings['critical_stock_threshold'];
$lowStockThreshold = (int)$settings['low_stock_threshold'];

function stock_level(int $stock, int $critical, int $low): string
{
    if ($stock <= $critical) return 'crit';
    if ($stock <= $low) return 'low';
    return 'ok';
}

function category_tag_class(string $name): string
{
    $n = strtolower($name);
    if (str_contains($n, 'hot')) return 'tag-hot';
    if (str_contains($n, 'iced') || str_contains($n, 'cold')) return 'tag-iced';
    if (str_contains($n, 'pastr') || str_contains($n, 'bread') || str_contains($n, 'bake')) return 'tag-pastry';
    return 'tag-supply';
}

$displayName = $_SESSION['username'] ?? 'Staff';
$initials = strtoupper(substr($displayName, 0, 2));

$categories = [];
$catResult = $conn->query('SELECT product_category_id, product_category FROM product_category ORDER BY product_category');
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
}

$recipesByProduct = [];
$recipeResult = $conn->query('
    SELECT pii.product_ingredient_items_id, pii.product_id, pii.product_ingredients_id, pii.quantity, pii.unit, pii.is_flavor_choice,
           pi.ingredient_name
    FROM product_ingredient_items pii
    JOIN product_ingredients pi ON pi.product_ingredients_id = pii.product_ingredients_id
    ORDER BY pi.ingredient_name
');
while ($row = $recipeResult->fetch_assoc()) {
    $pid = (int)$row['product_id'];
    $recipesByProduct[$pid][] = [
        'item_id' => (int)$row['product_ingredient_items_id'],
        'ingredient_id' => (int)$row['product_ingredients_id'],
        'ingredient_name' => $row['ingredient_name'],
        'quantity' => (float)$row['quantity'],
        'unit' => $row['unit'],
        'unit_label' => unit_label($row['unit']),
        'is_choice' => (bool)$row['is_flavor_choice'],
    ];
}

$variantsByProduct = [];
$variantResult = $conn->query('SELECT product_variant_id, product_id, variant_name, variant_price FROM product_variants ORDER BY product_id, sort_order, product_variant_id');
while ($row = $variantResult->fetch_assoc()) {
    $variantsByProduct[(int)$row['product_id']][] = [
        'variant_id' => (int)$row['product_variant_id'],
        'name' => $row['variant_name'],
        'price' => (float)$row['variant_price'],
    ];
}

$products = [];
$prodResult = $conn->query('
    SELECT p.product_id, p.product_name, p.product_stocks, p.product_cost, p.product_selling_price,
           p.product_category_id, pc.product_category, p.product_image, p.product_type, s.supplier_name, s.supplier_contact
    FROM products p
    JOIN product_category pc ON pc.product_category_id = p.product_category_id
    JOIN product_supplier s ON s.product_supplier_id = p.product_supplier_id
    ORDER BY pc.product_category, p.product_name
');
while ($row = $prodResult->fetch_assoc()) {
    $stock = (int)$row['product_stocks'];
    $pid = (int)$row['product_id'];
    $products[] = [
        'id' => $pid,
        'name' => $row['product_name'],
        'category_id' => (int)$row['product_category_id'],
        'category_name' => $row['product_category'],
        'tag_class' => category_tag_class($row['product_category']),
        'type' => $row['product_type'],
        'stock' => $stock,
        'level' => stock_level($stock, $criticalStockThreshold, $lowStockThreshold),
        'cost' => (float)$row['product_cost'],
        'price' => (float)$row['product_selling_price'],
        'image' => $row['product_image'] ? '../' . $row['product_image'] : null,
        'supplier_name' => $row['supplier_name'],
        'supplier_contact' => $row['supplier_contact'],
        'recipe' => $recipesByProduct[$pid] ?? [],
        'variants' => $variantsByProduct[$pid] ?? [],
    ];
}

$criticalCount = count(array_filter($products, fn($p) => $p['level'] === 'crit'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Products | SmartStock — Bean There Café</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>

  <style>
     :root {
      --mocha:        #4A2C2A;
      --mocha-deep:   #2E1A18;
      --mocha-mid:    #6B3D3A;
      --cream:        #F5ECD7;
      --cream-light:  #FBF6EE;
      --cream-dark:   #E8D8BA;
      --charcoal:     #2C2C2C;
      --charcoal-mid: #444444;
      --gold:         #C9943A;
      --gold-light:   #E8B860;
      --sage:         #7A9E7E;
      --red-soft:     #C0392B;
      --sidebar-w:    240px;
      --header-h:     64px;
      --font-display: 'Playfair Display', serif;
      --font-body:    'DM Sans', sans-serif;
      --font-mono:    'DM Mono', monospace;
      --shadow-sm:    0 2px 8px rgba(74,44,42,.10);
      --shadow-md:    0 6px 24px rgba(74,44,42,.15);
      --shadow-lg:    0 12px 40px rgba(74,44,42,.22);
      --radius:       12px;
      --radius-lg:    18px;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font-body); background: var(--cream-light); color: var(--charcoal); min-height: 100vh; overflow-x: hidden; }
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--cream); }
    ::-webkit-scrollbar-thumb { background: var(--mocha-mid); border-radius: 99px; }

    /* ── HEADER ── */
    #app-header {
      position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
      height: var(--header-h);
      background: var(--mocha-deep);
      display: flex; align-items: center; padding: 0 24px 0 0;
      box-shadow: 0 2px 20px rgba(0,0,0,.35);
    }
    .header-brand {
      width: var(--sidebar-w); flex-shrink: 0;
      display: flex; align-items: center; gap: 12px;
      padding: 0 20px;
      border-right: 1px solid rgba(255,255,255,.08);
    }
    .brand-logo {
      width: 38px; height: 38px; border-radius: 10px;
      background: var(--gold); color: var(--mocha-deep);
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; box-shadow: 0 2px 10px rgba(201,148,58,.45);
      flex-shrink: 0;
    }
    .brand-text .name { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--cream); }
    .brand-text .sub  { font-size: 10px; color: var(--gold-light); letter-spacing: 1.5px; text-transform: uppercase; }
    .header-center { padding-left: 22px; display: flex; align-items: center; gap: 10px; }
    .portal-badge {
      background: rgba(201,148,58,.18); color: var(--gold-light);
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; padding: 4px 10px; border-radius: 99px;
    }
    .header-view-label { font-size: 13px; color: rgba(245,236,215,.5); }
    .header-right { margin-left: auto; display: flex; align-items: center; gap: 14px; }
    .header-clock { font-family: var(--font-mono); font-size: 13px; color: rgba(245,236,215,.55); }
    .header-user {
      display: flex; align-items: center; gap: 8px;
      background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.10);
      border-radius: 99px; padding: 5px 14px 5px 5px; cursor: pointer;
    }
    .header-avatar {
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--gold); color: var(--mocha-deep);
      font-weight: 700; font-size: 12px;
      display: flex; align-items: center; justify-content: center;
    }
    .header-user-name { font-size: 13px; color: var(--cream); font-weight: 500; }
    .logout-link { display:flex; align-items:center; gap:6px; background:rgba(192,57,43,.12); border:1px solid rgba(192,57,43,.28); color:#e08a80; font-size:12px; font-weight:600; padding:6px 14px; border-radius:99px; cursor:pointer; text-decoration:none; transition:all .2s; }
    .logout-link:hover { background:rgba(192,57,43,.22); color:#e08a80; }

    /* ── SIDEBAR ── */
    #sidebar {
      position: fixed; top: var(--header-h); left: 0; bottom: 0;
      width: var(--sidebar-w); z-index: 900;
      background: var(--mocha-deep);
      display: flex; flex-direction: column;
      overflow-y: auto;
    }
    .sidebar-section-label {
      padding: 20px 20px 6px;
      font-size: 9.5px; font-weight: 700; letter-spacing: 2px;
      text-transform: uppercase; color: rgba(245,236,215,.3);
    }
    a.nav-item { text-decoration: none; }
    .nav-item {
      display: flex; align-items: center; gap: 12px;
      padding: 11px 20px; color: rgba(245,236,215,.6);
      cursor: pointer; font-size: 13.5px; font-weight: 500;
      border-left: 3px solid transparent; transition: all .2s;
      user-select: none;
    }
    .nav-item i { width: 18px; text-align: center; font-size: 14px; }
    .nav-item:hover { background: rgba(255,255,255,.06); color: var(--cream); }
    .nav-item.active { background: rgba(201,148,58,.12); color: var(--gold-light); border-left-color: var(--gold); }
    .nav-item.active i { color: var(--gold); }
    .nav-badge {
      margin-left: auto; background: var(--red-soft); color: #fff;
      font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px;
    }
    .sidebar-divider { border: none; border-top: 1px solid rgba(255,255,255,.07); margin: 8px 16px; }
    .sidebar-footer {
      margin-top: auto; padding: 16px 20px;
      border-top: 1px solid rgba(255,255,255,.07);
      text-align: center;
    }
    .sidebar-footer p { font-size: 10px; color: rgba(245,236,215,.22); line-height: 1.7; }

    /* ── MAIN ── */
    #main { margin-left: var(--sidebar-w); margin-top: var(--header-h); min-height: calc(100vh - var(--header-h)); }
    .page-strip {
      background: var(--cream); border-bottom: 1px solid var(--cream-dark);
      padding: 15px 26px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: var(--header-h); z-index: 500;
    }
    .page-strip h1 { font-family: var(--font-display); font-size: 21px; font-weight: 700; color: var(--mocha-deep); }
    .page-strip .sub { font-size: 12px; color: var(--mocha-mid); margin-top: 1px; }

    /* ── TABLES ── */
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table thead { background: var(--mocha-deep); }
    .data-table th { padding: 11px 15px; font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: rgba(245,236,215,.65); text-align: left; }
    .data-table td { padding: 10px 15px; font-size: 13px; color: var(--charcoal); border-bottom: 1px solid var(--cream-dark); }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(201,148,58,.03); }
    .table-wrap { background: var(--cream); border: 1.5px solid var(--cream-dark); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }
    .tag { display:inline-block; padding:2px 8px; border-radius:99px; font-size:10px; font-weight:700; }
    .tag-hot    { background:rgba(192,57,43,.1);  color:#c0392b; }
    .tag-iced   { background:rgba(52,152,219,.1); color:#2980b9; }
    .tag-pastry { background:rgba(243,156,18,.12);color:#d68910; }
    .tag-supply { background:rgba(122,158,126,.12);color:var(--sage); }
    .stock-indicator { display:inline-flex; align-items:center; gap:5px; font-weight:600; font-family:var(--font-mono); font-size:12px; }
    .stock-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    .stock-ok   .stock-dot { background:var(--sage); } .stock-ok   { color:var(--sage); }
    .stock-low  .stock-dot { background:#e67e22; } .stock-low  { color:#e67e22; }
    .stock-crit .stock-dot { background:var(--red-soft); } .stock-crit { color:var(--red-soft); }
    .content-row { display:flex; gap:20px; align-items:flex-start; }
    .content-row .table-wrap { flex:1; min-width:0; }
    .legend-card { width:190px; flex-shrink:0; background:var(--cream); border:1.5px solid var(--cream-dark); border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); padding:16px 18px; }
    .legend-card-title { font-family:var(--font-display); font-weight:700; font-size:13px; color:var(--mocha-deep); margin-bottom:12px; }
    .legend-item { display:flex; align-items:flex-start; gap:8px; margin-bottom:12px; }
    .legend-item:last-child { margin-bottom:0; }
    .legend-dot { width:9px; height:9px; border-radius:50%; margin-top:4px; flex-shrink:0; }
    .legend-dot.ok   { background:var(--sage); }
    .legend-dot.low  { background:#e67e22; }
    .legend-dot.crit { background:var(--red-soft); }
    .legend-label { font-size:12px; font-weight:700; color:var(--charcoal); }
    .legend-desc  { font-size:11px; color:#999; margin-top:2px; line-height:1.4; }
    .tbl-btn { padding:4px 11px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; border:1.5px solid; transition:all .15s; background:transparent; }
    .tbl-btn-edit { border-color:var(--gold); color:var(--gold); } .tbl-btn-edit:hover { background:var(--gold); color:#fff; }
    .tbl-btn-del  { border-color:#c9a; color:#c88; } .tbl-btn-del:hover { background:var(--red-soft); border-color:var(--red-soft); color:#fff; }

    /* ── SEARCH / TOOLBAR ── */
    .product-toolbar { display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
    .search-box { flex:1; min-width:200px; display:flex; align-items:center; gap:8px; background:var(--cream); border:1.5px solid var(--cream-dark); border-radius:8px; padding:8px 13px; transition:border-color .2s; }
    .search-box:focus-within { border-color:var(--mocha); }
    .search-box i { color:#bbb; font-size:13px; }
    .search-box input { border:none; background:transparent; font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; width:100%; }
    .filter-select { padding:8px 13px; border-radius:8px; border:1.5px solid var(--cream-dark); background:var(--cream); font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; }
    .btn-primary { padding:9px 17px; border-radius:8px; background:var(--mocha); color:var(--cream); border:none; font-family:var(--font-body); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
    .btn-primary:hover { background:var(--mocha-mid); }
    .btn-outline { padding:8px 16px; border-radius:8px; background:transparent; color:var(--mocha-mid); border:1.5px solid var(--cream-dark); font-family:var(--font-body); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
    .btn-outline:hover { border-color:var(--mocha); color:var(--mocha); }

    /* ── TEXT UTILS ── */
    .text-mono  { font-family:var(--font-mono); }
    .text-gold  { color:var(--gold); }
    .text-sage  { color:var(--sage); }
    .text-muted { color:#aaa; }

    /* ── TOAST ── */
    #toast-container { position:fixed; bottom:22px; right:22px; z-index:99999; display:flex; flex-direction:column; gap:7px; }
    .toast-msg { background:var(--charcoal); color:var(--cream); font-size:13px; font-weight:500; padding:11px 16px; border-radius:10px; box-shadow:var(--shadow-md); display:flex; align-items:center; gap:8px; animation:toastIn .28s ease; }
    .toast-msg.success { border-left:3px solid var(--sage); }
    .toast-msg.warn    { border-left:3px solid #e67e22; }
    @keyframes toastIn { from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);} }

    /* ── MODAL ── */
    .modal-overlay { position:fixed; inset:0; z-index:9999; background:rgba(20,10,8,.58); display:none; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
    .modal-overlay.show { display:flex; }
    .modal-box { background:var(--cream-light); border-radius:var(--radius-lg); padding:28px 30px; max-width:420px; width:92%; max-height:88vh; overflow-y:auto; box-shadow:var(--shadow-lg); animation:popIn .25s cubic-bezier(.34,1.56,.64,1); }
    @keyframes popIn { from{opacity:0;transform:scale(.88);}to{opacity:1;transform:scale(1);} }
    .modal-title { font-family:var(--font-display); font-size:19px; color:var(--mocha-deep); margin-bottom:5px; }
    .modal-sub   { font-size:13px; color:#888; margin-bottom:18px; }
    .modal-field { margin-bottom:14px; }
    .modal-field label { display:block; font-size:11px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:#888; margin-bottom:5px; }
    .modal-field input, .modal-field select { width:100%; padding:9px 13px; border-radius:8px; border:1.5px solid var(--cream-dark); background:var(--cream); font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; transition:border-color .2s; }
    .modal-field input:focus, .modal-field select:focus { border-color:var(--mocha); }
    .btn-modal-primary { width:100%; padding:12px; background:var(--mocha); color:var(--cream); border:none; border-radius:var(--radius); font-family:var(--font-body); font-size:14px; font-weight:700; cursor:pointer; transition:all .2s; }
    .btn-modal-primary:hover { background:var(--mocha-mid); }
    .btn-modal-cancel { width:100%; padding:9px; margin-top:7px; background:transparent; color:#bbb; border:1.5px solid var(--cream-dark); border-radius:8px; font-family:var(--font-body); font-size:13px; cursor:pointer; transition:all .2s; }
    .btn-modal-cancel:hover { color:var(--red-soft); border-color:var(--red-soft); }
  </style>
</head>
<body>

<header id="app-header">
  <div class="header-brand">
    <div class="brand-logo"><i class="fas fa-mug-hot"></i></div>
    <div class="brand-text"><div class="name">SmartStock</div><div class="sub">Bean There Café</div></div>
  </div>
  <div class="header-center"></div>
  <div class="header-right">
    <div class="header-clock" id="clock"></div>
    <div class="header-user">
      <div class="header-avatar"><?= htmlspecialchars($initials) ?></div>
      <span class="header-user-name"><?= htmlspecialchars($displayName) ?></span>
    </div>
    <a href="../logout.php" class="logout-link"><i class="fas fa-right-from-bracket"></i> Logout</a>
  </div>
</header>

<nav id="sidebar">
  <div class="sidebar-section-label">Staff Panel</div>
  <a href="staffdashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
  <a href="staff_transactions.php" class="nav-item"><i class="fas fa-receipt"></i> Transactions</a>
  <a href="staff_products.php" class="nav-item active"><i class="fas fa-boxes-stacked"></i> Products
    <?php if ($criticalCount > 0): ?>
      <span class="nav-badge"><?= $criticalCount ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <hr class="sidebar-divider"/>
</nav>

<div id="main">
  <div class="page-strip">
    <div>
      <h1><i class="fas fa-boxes-stacked" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Products</h1>
      <div class="sub">Track stock levels, unit costs, and supplier contacts</div>
    </div>
    <div style="display:flex;gap:10px;">
      <button class="btn-outline" onclick="openModal('modal-add-category')"><i class="fas fa-tag"></i> Categories</button>
    </div>
  </div>
  <div style="padding:22px 26px;">
    <div class="product-toolbar">
      <div class="search-box">
        <i class="fas fa-magnifying-glass"></i>
        <input type="text" id="product-search" placeholder="Search products, suppliers…" oninput="filterProducts()"/>
      </div>
      <select class="filter-select" id="product-cat-filter" onchange="filterProducts()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
        <?php endforeach; ?>
      </select>
      <select class="filter-select" id="product-stock-filter" onchange="filterProducts()"><option value="">All Stock Levels</option><option value="ok">OK</option><option value="low">Low</option><option value="crit">Critical</option></select>
    </div>
    <div class="content-row">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Image</th><th>Product Name</th><th>Category</th><th>Stock</th><th>Unit Cost</th><th>Selling Price</th><th>Recipe</th></tr></thead>
          <tbody id="product-tbody"></tbody>
        </table>
      </div>
      <div class="legend-card">
        <div class="legend-card-title">Legend</div>
        <div class="legend-item"><span class="legend-dot ok"></span><div><div class="legend-label">OK</div><div class="legend-desc">Stock at a healthy level</div></div></div>
        <div class="legend-item"><span class="legend-dot low"></span><div><div class="legend-label">Low</div><div class="legend-desc">Running low, reorder soon</div></div></div>
        <div class="legend-item"><span class="legend-dot crit"></span><div><div class="legend-label">Critical</div><div class="legend-desc">Reorder immediately</div></div></div>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-add-category">
  <div class="modal-box" style="max-width:400px;">
    <div class="modal-title"><i class="fas fa-tag" style="color:var(--gold);margin-right:8px;"></i>Manage Categories</div>
    <div class="modal-sub">Add, rename, or remove product categories.</div>

    <div id="category-list" style="margin-bottom:16px;max-height:240px;overflow-y:auto;">
      <?php foreach ($categories as $cat): ?>
        <div class="category-row" data-category-id="<?= (int)$cat['product_category_id'] ?>" data-category-name="<?= htmlspecialchars($cat['product_category'], ENT_QUOTES) ?>" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--cream-dark);">
          <span style="flex:1;font-size:13px;"><?= htmlspecialchars($cat['product_category']) ?></span>
          <button type="button" class="tbl-btn tbl-btn-edit" onclick="startEditCategory(this)">Edit</button>
          <form method="POST" action="staff_products.php" style="display:contents;" onsubmit="return confirmSubmit(event, 'Delete category &quot;<?= htmlspecialchars($cat['product_category'], ENT_QUOTES) ?>&quot;? Products still using it will need a new category first.', 'Delete', true)">
            <input type="hidden" name="action" value="delete_category">
            <input type="hidden" name="category_id" value="<?= (int)$cat['product_category_id'] ?>">
            <button type="submit" class="tbl-btn tbl-btn-del">Delete</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (empty($categories)): ?>
        <div style="color:#aaa;font-size:13px;padding:8px 0;">No categories yet.</div>
      <?php endif; ?>
    </div>

    <form method="POST" action="staff_products.php" style="border-top:1px solid var(--cream-dark);padding-top:14px;" onsubmit="return confirmSubmit(event, 'Add category &quot;' + this.category_name.value.trim() + '&quot;?', 'Add Category')">
      <input type="hidden" name="action" value="add_category">
      <div class="modal-field"><label>New Category Name</label><input type="text" name="category_name" placeholder="e.g. Pastries" required /></div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-plus" style="margin-right:6px;"></i>Add Category</button>
    </form>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-add-category')">Close</button>
  </div>
</div>

<div class="modal-overlay" id="modal-recipe-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-flask" style="color:var(--gold);margin-right:8px;"></i>Recipe — <span id="recipe-product-name"></span></div>
    <div class="modal-sub">Ingredients consumed from Inventory each time this product is sold.</div>
    <div id="recipe-items-list" style="margin-bottom:16px;"></div>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-recipe-item')">Close</button>
  </div>
</div>

<div class="modal-overlay" id="modal-sizes-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-ruler-combined" style="color:var(--gold);margin-right:8px;"></i>Sizes / Options — <span id="sizes-product-name"></span></div>
    <div class="modal-sub">Available sizes/options and their prices for this product.</div>
    <div id="sizes-items-list" style="margin-bottom:16px;"></div>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-sizes-item')">Close</button>
  </div>
</div>

<div class="modal-overlay" id="modal-confirm-delete">
  <div class="modal-box" style="max-width:360px;">
    <div class="modal-title"><i class="fas fa-triangle-exclamation" style="color:var(--red-soft);margin-right:8px;"></i>Please Confirm</div>
    <div class="modal-sub" id="confirm-delete-message">Are you sure?</div>
    <button type="button" class="btn-modal-primary" style="background:var(--red-soft);" id="confirm-delete-yes">Delete</button>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-confirm-delete')">Cancel</button>
  </div>
</div>

<div id="toast-container"></div>
<script id="products-data" type="application/json"><?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

<script>
  // Product catalog — loaded from the products table (see products-data script tag above)
  const products = JSON.parse(document.getElementById('products-data').textContent);

  document.addEventListener('DOMContentLoaded', () => {
    updateClock(); setInterval(updateClock, 1000);
    renderProducts('', '', '');

    <?php if ($msg): ?>
      showToast(<?= json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($msgType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
    <?php endif; ?>
  });

  function updateClock() {
    const clock = document.getElementById('clock');
    if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function money(n) {
    return '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function renderProducts(search, catId, level) {
    const tbody = document.getElementById('product-tbody');
    if (!tbody) return;

    if (products.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:24px 8px;">No products yet.</td></tr>';
      return;
    }

    const filtered = products.filter(p => {
      const s = !search || p.name.toLowerCase().includes(search.toLowerCase()) || p.supplier_name.toLowerCase().includes(search.toLowerCase());
      const c = !catId || String(p.category_id) === String(catId);
      const l = !level || p.level === level;
      return s && c && l;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:24px 8px;">No products match your filters.</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map(p => {
      const isMotd = p.type === 'made_to_order';
      return `
      <tr>
        <td>${p.image ? `<img src="${p.image}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">` : `<div style="width:40px;height:40px;border-radius:6px;background:var(--cream-dark);"></div>`}</td>
        <td style="font-weight:600;">${p.name}${isMotd ? ' <span class="tag tag-supply" style="margin-left:4px;">Made to Order</span>' : ''}</td>
        <td><span class="tag ${p.tag_class}">${p.category_name}</span></td>
        <td><div class="stock-indicator stock-${p.level}"><div class="stock-dot"></div>${p.stock}</div></td>
        <td class="text-mono">${money(p.cost)}</td>
        <td class="text-mono">${money(p.price)}</td>
        <td style="display:flex;gap:6px;align-items:center;">
          ${isMotd ? `<button class="tbl-btn tbl-btn-edit" onclick="openRecipeModal(${p.id})">Recipe</button>` : ''}
          ${p.variants.length > 0 ? `<button class="tbl-btn tbl-btn-edit" onclick="openSizesModal(${p.id})">Sizes (${p.variants.length})</button>` : '<span class="text-muted">—</span>'}
        </td>
      </tr>
    `;
    }).join('');
  }

  function filterProducts() {
    renderProducts(
      document.getElementById('product-search').value,
      document.getElementById('product-cat-filter').value,
      document.getElementById('product-stock-filter').value
    );
  }

  function openRecipeModal(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    document.getElementById('recipe-product-name').textContent = p.name;
    renderRecipeItems(p);
    openModal('modal-recipe-item');
  }

  function renderRecipeItems(p) {
    const list = document.getElementById('recipe-items-list');
    if (!p.recipe || p.recipe.length === 0) {
      list.innerHTML = '<div style="color:#aaa;font-size:13px;padding:8px 0;">No ingredients linked to this product yet.</div>';
      return;
    }
    list.innerHTML = p.recipe.map(r => `
      <div style="padding:8px 0;border-bottom:1px solid var(--cream-dark);">
        <div style="font-size:13px;"><strong>${r.ingredient_name}</strong> — ${r.quantity} ${r.unit_label} per unit sold${r.is_choice ? ' <span class="tag tag-supply">Flavor choice</span>' : ''}</div>
      </div>
    `).join('');
  }

  function openSizesModal(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    document.getElementById('sizes-product-name').textContent = p.name;
    renderSizeItems(p);
    openModal('modal-sizes-item');
  }

  function renderSizeItems(p) {
    const list = document.getElementById('sizes-items-list');
    if (!p.variants || p.variants.length === 0) {
      list.innerHTML = '<div style="color:#aaa;font-size:13px;padding:8px 0;">No sizes/options for this product.</div>';
      return;
    }
    list.innerHTML = p.variants.map(v => `
      <div style="padding:8px 0;border-bottom:1px solid var(--cream-dark);">
        <div style="font-size:13px;"><strong>${v.name}</strong> — ${money(v.price)}</div>
      </div>
    `).join('');
  }

  function startEditCategory(btn) {
    const row = btn.closest('.category-row');
    const originalHtml = row.innerHTML;
    const id = row.dataset.categoryId;
    const currentName = row.dataset.categoryName;
    row.innerHTML = `
      <form method="POST" action="staff_products.php" style="display:flex;gap:8px;align-items:center;flex:1;" onsubmit="return confirmSubmit(event, 'Rename this category to \\'' + this.category_name.value.trim() + '\\'?', 'Save')">
        <input type="hidden" name="action" value="edit_category">
        <input type="hidden" name="category_id" value="${id}">
        <input type="text" name="category_name" value="${currentName.replace(/"/g, '&quot;')}" style="flex:1;padding:7px 10px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);" required />
        <button type="submit" class="tbl-btn tbl-btn-edit">Save</button>
        <button type="button" class="tbl-btn tbl-btn-del" data-cancel-edit>Cancel</button>
      </form>
    `;
    row.querySelector('[data-cancel-edit]').addEventListener('click', () => { row.innerHTML = originalHtml; });
  }

  function openModal(id)  { document.getElementById(id).classList.add('show'); }
  function closeModal(id) { document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); })
  );

  // In-page replacement for the native confirm() dialog — reused for deletes and other
  // actions (e.g. Add Category) that should ask "are you sure?" before submitting.
  let pendingConfirmForm = null;
  function confirmSubmit(event, message, confirmLabel = 'Confirm', danger = false) {
    event.preventDefault();
    pendingConfirmForm = event.target;
    document.getElementById('confirm-delete-message').textContent = message;
    const yesBtn = document.getElementById('confirm-delete-yes');
    yesBtn.textContent = confirmLabel;
    yesBtn.style.background = danger ? 'var(--red-soft)' : 'var(--mocha)';
    openModal('modal-confirm-delete');
    return false;
  }
  document.getElementById('confirm-delete-yes').addEventListener('click', () => {
    closeModal('modal-confirm-delete');
    if (pendingConfirmForm) { pendingConfirmForm.submit(); pendingConfirmForm = null; }
  });

  function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-container');
    if (!c) return;
    const t = document.createElement('div');
    t.className = `toast-msg ${type}`;
    t.innerHTML = `<i class="fas ${type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'}"></i> ${msg}`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 2800);
  }
</script>
</body>
</html>
