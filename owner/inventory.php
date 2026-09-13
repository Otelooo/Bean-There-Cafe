<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';
require_once __DIR__ . '/../unit_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg = '';
    $msgType = 'success';

    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $stock = $_POST['stock'] ?? '';
        $unit = trim($_POST['unit'] ?? '');
        $code = strtoupper(trim($_POST['ingredient_code'] ?? ''));
        $supplier = trim($_POST['supplier_name'] ?? '');
        $contact = trim($_POST['supplier_contact'] ?? '');
        $restockDetails = trim($_POST['restock_delivery_details'] ?? '');

        if ($name === '' || !preg_match('/^[A-Z0-9-]{1,50}$/', $code) || !is_numeric($stock) || (float)$stock < 0 || $unit === '') {
            $msg = 'Please fill in all fields with valid values.';
            $msgType = 'warn';
        } else {
            $stockVal = (float)$stock;
            $supplierVal = $supplier !== '' ? $supplier : null;
            $contactVal = $contact !== '' ? $contact : null;
            $restockDetailsVal = $restockDetails !== '' ? $restockDetails : null;

            $currentIngredientId = $action === 'edit' ? (int)($_POST['ingredient_id'] ?? 0) : 0;
            $codeCheck = $conn->prepare('SELECT product_ingredients_id FROM product_ingredients WHERE ingredient_code = ? AND product_ingredients_id <> ? LIMIT 1');
            $codeCheck->bind_param('si', $code, $currentIngredientId);
            $codeCheck->execute();
            $codeAlreadyUsed = (bool)$codeCheck->get_result()->fetch_assoc();
            $codeCheck->close();

            if ($codeAlreadyUsed) {
                $msg = 'That ingredient code is already in use.';
                $msgType = 'warn';
            } elseif ($action === 'add') {
                $stmt = $conn->prepare('INSERT INTO product_ingredients (ingredient_code, ingredient_name, ingredient_stock, ingredient_stock_reference, ingredient_unit, ingredient_supplier, ingredient_contact, restock_delivery_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssddssss', $code, $name, $stockVal, $stockVal, $unit, $supplierVal, $contactVal, $restockDetailsVal);
                $stmt->execute();
                $stmt->close();
                $msg = 'Ingredient added.';
            } else {
                $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
                if ($ingredientId <= 0) {
                    $msg = 'Invalid ingredient.';
                    $msgType = 'warn';
                } else {
                    $stmt = $conn->prepare('UPDATE product_ingredients SET ingredient_code = ?, ingredient_name = ?, ingredient_stock = ?, ingredient_stock_reference = ?, ingredient_unit = ?, ingredient_supplier = ?, ingredient_contact = ?, restock_delivery_details = ? WHERE product_ingredients_id = ?');
                    $stmt->bind_param('ssddssssi', $code, $name, $stockVal, $stockVal, $unit, $supplierVal, $contactVal, $restockDetailsVal, $ingredientId);
                    $stmt->execute();
                    $stmt->close();
                    $msg = 'Ingredient updated.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
        try {
            $stmt = $conn->prepare('DELETE FROM product_ingredients WHERE product_ingredients_id = ?');
            $stmt->bind_param('i', $ingredientId);
            $stmt->execute();
            $stmt->close();
            $msg = 'Ingredient deleted.';
        } catch (Throwable $e) {
            $msg = 'Cannot delete this ingredient — it is used in a product recipe.';
            $msgType = 'warn';
        }
    }

    header('Location: inventory.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
    exit;
}

$msg = $_GET['msg'] ?? '';
$msgType = $_GET['type'] ?? 'success';

$settings = get_system_settings($conn);
$criticalStockThreshold = (float)$settings['critical_stock_threshold'];
$lowStockThreshold = (float)$settings['low_stock_threshold'];

// Critical/Low are percentages of ingredient_stock_reference — the stock amount last typed into
// the Add/Edit form, which becomes the new "100%" mark every time stock is manually set/restocked.
function ingredient_stock_level(float $stock, ?float $reference, float $criticalPct, float $lowPct): string
{
    if ($reference === null || $reference <= 0) {
        return $stock <= 0 ? 'crit' : 'ok';
    }
    $pct = ($stock / $reference) * 100;
    if ($pct <= $criticalPct) return 'crit';
    if ($pct <= $lowPct) return 'low';
    return 'ok';
}

$displayName = $_SESSION['username'] ?? 'Owner';
$initials = strtoupper(substr($displayName, 0, 2));

$ingredients = [];
$result = $conn->query('SELECT product_ingredients_id, ingredient_code, ingredient_name, ingredient_stock, ingredient_stock_reference, ingredient_unit, ingredient_supplier, ingredient_contact, restock_delivery_details, ingredient_updated_at FROM product_ingredients ORDER BY ingredient_name');
while ($row = $result->fetch_assoc()) {
    $stock = (float)$row['ingredient_stock'];
    $reference = $row['ingredient_stock_reference'] !== null ? (float)$row['ingredient_stock_reference'] : null;
    // Recognized units (kg, ml, piece, etc.) normalize to their canonical key so the Edit form's
    // dropdown can preselect the right option; anything unrecognized (old free-typed text) is
    // passed through as-is so it still displays, but won't match a dropdown option until re-saved.
    $unitKey = normalize_unit_key($row['ingredient_unit']);
    $ingredients[] = [
        'id' => (int)$row['product_ingredients_id'],
        'code' => $row['ingredient_code'] ?? '',
        'name' => $row['ingredient_name'],
        'stock' => $stock,
        'unit' => $unitKey ?? $row['ingredient_unit'],
        'unit_label' => $unitKey ? unit_label($unitKey) : $row['ingredient_unit'],
        'supplier' => $row['ingredient_supplier'] ?? '',
        'contact' => $row['ingredient_contact'] ?? '',
        'restock_details' => $row['restock_delivery_details'] ?? '',
        'updated_at' => $row['ingredient_updated_at'] ?? '',
        'level' => ingredient_stock_level($stock, $reference, $criticalStockThreshold, $lowStockThreshold),
    ];
}

$ingredientAlertCount = count(array_filter($ingredients, fn($i) => $i['level'] !== 'ok'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inventory | SmartStock — Bean There Café</title>
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
      width: 42px; height: 42px; border-radius: 10px;
      background: var(--gold); color: var(--mocha-deep);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; box-shadow: 0 2px 10px rgba(201,148,58,.45);
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
    .nav-item i { width: 20px; text-align: center; font-size: 16px; }
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
    .status-pill { display:inline-block; padding:2px 10px; border-radius:99px; font-size:10px; font-weight:700; }
    .pill-success { background:rgba(122,158,126,.14); color:var(--sage); }
    .pill-warn    { background:rgba(230,126,34,.12);  color:#e67e22; }
    .pill-red     { background:rgba(192,57,43,.10);   color:var(--red-soft); }
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
    .inv-toolbar { display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
    .search-box { flex:1; min-width:200px; display:flex; align-items:center; gap:8px; background:var(--cream); border:1.5px solid var(--cream-dark); border-radius:8px; padding:8px 13px; transition:border-color .2s; }
    .search-box:focus-within { border-color:var(--mocha); }
    .search-box i { color:#bbb; font-size:13px; }
    .search-box input { border:none; background:transparent; font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; width:100%; }
    .filter-select { padding:8px 13px; border-radius:8px; border:1.5px solid var(--cream-dark); background:var(--cream); font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; }
    .btn-primary { padding:9px 17px; border-radius:8px; background:var(--mocha); color:var(--cream); border:none; font-family:var(--font-body); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
    .btn-primary:hover { background:var(--mocha-mid); }

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
    .modal-field input, .modal-field select, .modal-field textarea { width:100%; padding:9px 13px; border-radius:8px; border:1.5px solid var(--cream-dark); background:var(--cream); font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; transition:border-color .2s; }
    .modal-field textarea { resize:vertical; }
    .modal-field input:focus, .modal-field select:focus, .modal-field textarea:focus { border-color:var(--mocha); }
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
  <div class="sidebar-section-label">Owner Panel</div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
  <a href="transactions.php" class="nav-item"><i class="fas fa-receipt"></i> Transactions</a>
  <a href="transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
  <a href="inventory.php" class="nav-item active"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
  <hr class="sidebar-divider"/>
  <div class="sidebar-section-label">Settings</div>
  <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> System Settings</a>
  <a href="backup.php" class="nav-item"><i class="fas fa-database"></i> Data Backup</a>
  <div class="sidebar-footer">
    <p>SmartStock v1.0<br />Bean There Café<br />ISO/IEC 25010 Compliant</p>
  </div>
</nav>

<div id="main">
  <?php if ($msg): ?>
    <div
      style="margin:16px 26px 0;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;background:<?= $msgType === 'warn' ? 'rgba(192,57,43,.10)' : 'rgba(122,158,126,.14)' ?>;color:<?= $msgType === 'warn' ? 'var(--red-soft)' : 'var(--sage)' ?>;">
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>
  <div class="page-strip">
    <div>
      <h1><i class="fas fa-warehouse" style="color:var(--gold);font-size:22px;margin-right:10px;"></i>Inventory</h1>
      <div class="sub">Track raw ingredient and supply stock used to make your products</div>
    </div>
    <button class="btn-primary" onclick="openModal('modal-add-item')"><i class="fas fa-plus"></i> Add Ingredient</button>
  </div>
  <div style="padding:22px 26px;">
    <div class="inv-toolbar">
      <div class="search-box">
        <i class="fas fa-magnifying-glass"></i>
        <input type="text" id="ing-search" placeholder="Search ingredients…" oninput="filterIngredients()"/>
      </div>
      <select class="filter-select" id="ing-stock-filter" onchange="filterIngredients()"><option value="">All Stock Levels</option><option value="ok">OK</option><option value="low">Low</option><option value="crit">Critical</option></select>
    </div>
    <div class="content-row">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Code</th><th>Ingredient</th><th>Stock</th><th>Unit</th><th>Supplier</th><th>Contact</th><th>Last Restock / Delivery</th><th>Last Updated</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="ing-tbody"></tbody>
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

<div class="modal-overlay" id="modal-add-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-plus-circle" style="color:var(--gold);margin-right:8px;"></i>Add New Ingredient</div>
    <div class="modal-sub">Add a raw ingredient or supply to track stock for.</div>
    <form method="POST" action="inventory.php" onsubmit="return checkDuplicateAndConfirm(event, this.elements['name'].value, ingredients.map(i => i.name), 'ingredient')">
      <input type="hidden" name="action" value="add">
      <div class="modal-field">
        <label>Ingredient Name</label>
        <input type="text" name="name" placeholder="e.g. Espresso Beans" list="ingredient-name-list" autocomplete="off" required />
        <datalist id="ingredient-name-list">
          <?php foreach ($ingredients as $ing): ?>
            <option value="<?= htmlspecialchars($ing['name']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="modal-field"><label>Ingredient Code</label><input type="text" name="ingredient_code" maxlength="50" pattern="[A-Za-z0-9-]+" placeholder="e.g. BEAN-001" required /></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="modal-field"><label>Stock Quantity</label><input type="number" name="stock" min="0" step="0.01" placeholder="0" required /></div>
        <div class="modal-field"><label>Unit</label>
          <select name="unit" required>
            <option value="">Select unit…</option>
            <?php foreach (unit_options_grouped() as $family => $opts): ?>
              <optgroup label="<?= htmlspecialchars(UNIT_FAMILY_LABELS[$family] ?? ucfirst($family)) ?>">
                <?php foreach ($opts as $opt): ?>
                  <option value="<?= htmlspecialchars($opt['key']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-field"><label>Supplier Name</label><input type="text" name="supplier_name" placeholder="Supplier company" /></div>
      <div class="modal-field"><label>Supplier Contact</label><input type="text" name="supplier_contact" placeholder="09XX-XXX-XXXX" /></div>
      <div class="modal-field"><label>Last Restock / Delivery Details</label><textarea name="restock_delivery_details" rows="2" maxlength="1000" placeholder="e.g. Delivered by ABC Supplies, 10 bags received."></textarea></div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-check" style="margin-right:6px;"></i>Add Ingredient</button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-add-item')">Cancel</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modal-edit-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-pen" style="color:var(--gold);margin-right:8px;"></i>Edit Ingredient</div>
    <div class="modal-sub">Update ingredient stock details.</div>
    <form method="POST" action="inventory.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="ingredient_id" id="edit-ingredient-id" value="" />
      <div class="modal-field"><label>Ingredient Name</label><input type="text" name="name" id="edit-name" required /></div>
      <div class="modal-field"><label>Ingredient Code</label><input type="text" name="ingredient_code" id="edit-ingredient-code" maxlength="50" pattern="[A-Za-z0-9-]+" required /></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="modal-field"><label>Stock Quantity</label><input type="number" name="stock" id="edit-stock" min="0" step="0.01" required /></div>
        <div class="modal-field"><label>Unit</label>
          <select name="unit" id="edit-unit" required>
            <option value="">Select unit…</option>
            <?php foreach (unit_options_grouped() as $family => $opts): ?>
              <optgroup label="<?= htmlspecialchars(UNIT_FAMILY_LABELS[$family] ?? ucfirst($family)) ?>">
                <?php foreach ($opts as $opt): ?>
                  <option value="<?= htmlspecialchars($opt['key']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-field"><label>Supplier Name</label><input type="text" name="supplier_name" id="edit-supplier-name" /></div>
      <div class="modal-field"><label>Supplier Contact</label><input type="text" name="supplier_contact" id="edit-supplier-contact" /></div>
      <div class="modal-field"><label>Last Restock / Delivery Details</label><textarea name="restock_delivery_details" id="edit-restock-details" rows="2" maxlength="1000"></textarea></div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-check" style="margin-right:6px;"></i>Save Changes</button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-edit-item')">Cancel</button>
    </form>
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
<script id="ingredients-data" type="application/json"><?= json_encode($ingredients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

<script>
  // Ingredient stock — loaded from the product_ingredients table (see ingredients-data script tag above)
  const ingredients = JSON.parse(document.getElementById('ingredients-data').textContent);

  document.addEventListener('DOMContentLoaded', () => {
    updateClock(); setInterval(updateClock, 1000);
    renderIngredients('', '');
  });

  function updateClock() {
    const clock = document.getElementById('clock');
    if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function qty(n) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  const levelLabels = { ok: 'OK', low: 'Low', crit: 'Critical' };

  function renderIngredients(search, level) {
    const tbody = document.getElementById('ing-tbody');
    if (!tbody) return;

    if (ingredients.length === 0) {
      tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#aaa;padding:24px 8px;">No ingredients yet. Click "Add Ingredient" to add your first item.</td></tr>';
      return;
    }

    const filtered = ingredients.filter(i => {
      const s = !search || i.name.toLowerCase().includes(search.toLowerCase()) || i.code.toLowerCase().includes(search.toLowerCase());
      const l = !level || i.level === level;
      return s && l;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#aaa;padding:24px 8px;">No ingredients match your filters.</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map(i => `
      <tr>
        <td class="text-mono">${i.code || '<span class="text-muted">—</span>'}</td>
        <td style="font-weight:600;">${i.name}</td>
        <td><div class="stock-indicator stock-${i.level}"><div class="stock-dot"></div>${qty(i.stock)}</div></td>
        <td class="text-mono">${i.unit_label}</td>
        <td>${i.supplier ? i.supplier : '<span class="text-muted">—</span>'}</td>
        <td class="text-mono" style="font-size:12px;">${i.contact ? i.contact : '<span class="text-muted">—</span>'}</td>
        <td title="${i.restock_details}">${i.restock_details ? i.restock_details.slice(0, 55) + (i.restock_details.length > 55 ? '…' : '') : '<span class="text-muted">—</span>'}</td>
        <td class="text-mono" style="font-size:11px;">${i.updated_at || '<span class="text-muted">—</span>'}</td>
        <td><span class="status-pill ${i.level === 'ok' ? 'pill-success' : i.level === 'low' ? 'pill-warn' : 'pill-red'}">${levelLabels[i.level]}</span></td>
        <td style="display:flex;gap:6px;align-items:center;">
          <button class="tbl-btn tbl-btn-edit" onclick="openEditModal(${i.id})">Edit</button>
          <form method="POST" action="inventory.php" style="display:contents;" onsubmit="return confirmDelete(event, 'Delete ${i.name.replace(/'/g, "\\'")}?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="ingredient_id" value="${i.id}">
            <button type="submit" class="tbl-btn tbl-btn-del">Delete</button>
          </form>
        </td>
      </tr>
    `).join('');
  }

  function filterIngredients() {
    renderIngredients(
      document.getElementById('ing-search').value,
      document.getElementById('ing-stock-filter').value
    );
  }

  function openEditModal(id) {
    const i = ingredients.find(x => x.id === id);
    if (!i) return;
    document.getElementById('edit-ingredient-id').value = i.id;
    document.getElementById('edit-name').value = i.name;
    document.getElementById('edit-ingredient-code').value = i.code;
    document.getElementById('edit-stock').value = i.stock;
    document.getElementById('edit-unit').value = i.unit;
    document.getElementById('edit-supplier-name').value = i.supplier;
    document.getElementById('edit-supplier-contact').value = i.contact;
    document.getElementById('edit-restock-details').value = i.restock_details;
    openModal('modal-edit-item');
  }

  function openModal(id)  { document.getElementById(id).classList.add('show'); }
  function closeModal(id) { document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); })
  );

  // In-page replacement for the native confirm() dialog on delete forms
  let pendingDeleteForm = null;
  function confirmDelete(event, message) {
    event.preventDefault();
    pendingDeleteForm = event.target;
    document.getElementById('confirm-delete-message').textContent = message;
    const yesBtn = document.getElementById('confirm-delete-yes');
    yesBtn.textContent = 'Delete';
    yesBtn.style.background = 'var(--red-soft)';
    openModal('modal-confirm-delete');
    return false;
  }
  document.getElementById('confirm-delete-yes').addEventListener('click', () => {
    closeModal('modal-confirm-delete');
    if (pendingDeleteForm) { pendingDeleteForm.submit(); pendingDeleteForm = null; }
  });

  // Warns before inserting an ingredient that looks like it might already exist (typo,
  // different casing, or a near-identical name) — a cheap Levenshtein-distance check against
  // everything already in the list, no external library needed.
  function normalizeText(s) {
    return (s || '').toLowerCase().trim().replace(/\s+/g, ' ');
  }
  // Character bigrams with whitespace stripped — order/spacing-independent, so "ground pork"
  // and "porkground" still overlap almost completely even though the words are swapped and
  // the space is gone; only the bigram at the word "seam" changes.
  function bigrams(s) {
    const flat = s.replace(/\s+/g, '');
    const grams = [];
    for (let i = 0; i < flat.length - 1; i++) grams.push(flat.slice(i, i + 2));
    return grams;
  }
  // Sørensen–Dice coefficient: 2 * shared bigrams / total bigrams — 0 (nothing alike) to 1 (identical).
  function diceCoefficient(a, b) {
    const gramsA = bigrams(a), gramsB = bigrams(b);
    if (gramsA.length === 0 || gramsB.length === 0) return gramsA.join('') === gramsB.join('') ? 1 : 0;
    const counts = new Map();
    for (const g of gramsA) counts.set(g, (counts.get(g) || 0) + 1);
    let shared = 0;
    for (const g of gramsB) {
      const c = counts.get(g) || 0;
      if (c > 0) { shared++; counts.set(g, c - 1); }
    }
    return (2 * shared) / (gramsA.length + gramsB.length);
  }
  const SIMILARITY_THRESHOLD = 0.7;
  // Returns the existing name this looks like a near-duplicate of, or null if it looks distinct.
  function findSimilarExisting(newName, existingNames) {
    const norm = normalizeText(newName);
    if (!norm) return null;
    for (const existing of existingNames) {
      const existingNorm = normalizeText(existing);
      if (!existingNorm) continue;
      if (existingNorm === norm) return existing;
      if (norm.length >= 4 && existingNorm.length >= 4) {
        if (existingNorm.includes(norm) || norm.includes(existingNorm)) return existing;
        if (diceCoefficient(norm, existingNorm) >= SIMILARITY_THRESHOLD) return existing;
      }
    }
    return null;
  }
  function checkDuplicateAndConfirm(event, newName, existingNames, itemType) {
    const match = findSimilarExisting(newName, existingNames);
    if (!match) return true;
    event.preventDefault();
    pendingDeleteForm = event.target;
    document.getElementById('confirm-delete-message').textContent =
      `Are you sure you want to add this ${itemType}? It seems "${match}" is already inserted inside.`;
    const yesBtn = document.getElementById('confirm-delete-yes');
    yesBtn.textContent = 'Add Anyway';
    yesBtn.style.background = 'var(--mocha)';
    openModal('modal-confirm-delete');
    return false;
  }

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
