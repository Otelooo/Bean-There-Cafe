<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe staff') {
    header('Location: ../signin.php');
    exit;
}

const HISTORY_PER_PAGE = 25;

function history_category_tag_class(string $name): string
{
    $n = strtolower($name);
    if (str_contains($n, 'hot')) return 'tag-hot';
    if (str_contains($n, 'iced') || str_contains($n, 'cold')) return 'tag-iced';
    if (str_contains($n, 'pastr') || str_contains($n, 'bread') || str_contains($n, 'bake')) return 'tag-pastry';
    return 'tag-supply';
}

// Builds one page of transaction history for the given date range/category filter — shared by
// the first paint (below) and the action=history JSON endpoint, so both stay in sync.
//
// Unlike staff_reports.php (Sales Report), Transaction History intentionally shows ALL
// transactions to every staff member, not just their own — a confirmed product decision.
// Do not add a "WHERE t.user_id = ?" scope here.
function build_history_result(mysqli $conn, string $dateFrom, string $dateTill, int $categoryId, int $page): array
{
    if ($dateTill < $dateFrom) {
        [$dateFrom, $dateTill] = [$dateTill, $dateFrom];
    }
    $perPage = HISTORY_PER_PAGE;

    // A transaction is included if ANY of its line items belong to the selected category —
    // an EXISTS check keeps the main query's row shape untouched (still one row per transaction,
    // still able to show that transaction's full item list, not just the matching items).
    $categorySql = $categoryId > 0
        ? ' AND EXISTS (
              SELECT 1 FROM transaction_items ti2
              JOIN products p2 ON p2.product_id = ti2.product_id
              WHERE ti2.transaction_id = t.transaction_id AND p2.product_category_id = ?
          )'
        : '';

    $countSql = "SELECT COUNT(*) AS cnt FROM transactions t WHERE DATE(t.transaction_date) BETWEEN ? AND ?" . $categorySql;
    $stmt = $conn->prepare($countSql);
    if ($categoryId > 0) {
        $stmt->bind_param('ssi', $dateFrom, $dateTill, $categoryId);
    } else {
        $stmt->bind_param('ss', $dateFrom, $dateTill);
    }
    $stmt->execute();
    $totalCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $totalPages = max(1, (int)ceil($totalCount / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    // No transaction_status filter — this is a historical record, so cancelled sales show too
    // (distinguished by the status pill), unlike Sales Report's revenue-only 'completed' queries.
    $listSql = "SELECT t.transaction_id, t.transaction_date, t.transaction_total, t.transaction_status,
                       t.payment_method, t.discount, t.notes,
                       COALESCE(t.cashier_username, u.username, 'Deleted user') AS cashier_name
                FROM transactions t
                LEFT JOIN users u ON u.user_id = t.user_id
                WHERE DATE(t.transaction_date) BETWEEN ? AND ?" . $categorySql . "
                ORDER BY t.transaction_date DESC
                LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($listSql);
    if ($categoryId > 0) {
        $stmt->bind_param('ssiii', $dateFrom, $dateTill, $categoryId, $perPage, $offset);
    } else {
        $stmt->bind_param('ssii', $dateFrom, $dateTill, $perPage, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rowsByTxn = [];
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $tid = (int)$row['transaction_id'];
        $rowsByTxn[$tid] = $row;
        $ids[] = $tid;
    }
    $stmt->close();

    // Batched line-items for just this page's transactions (same dynamic-IN-placeholder pattern
    // used for variant lookups in transactions.php) — embedded eagerly since the page is small,
    // so the detail modal needs no extra request.
    $itemsByTxn = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $itemSql = "SELECT ti.transaction_id,
                           COALESCE(ti.product_name_snapshot, p.product_name, 'Deleted product') AS product_name,
                           ti.variant_name_snapshot, ti.chosen_ingredient_name_snapshot,
                           ti.quantity, ti.unit_price, ti.subtotal,
                           pc.product_category
                    FROM transaction_items ti
                    LEFT JOIN products p ON p.product_id = ti.product_id
                    LEFT JOIN product_category pc ON pc.product_category_id = p.product_category_id
                    WHERE ti.transaction_id IN ($placeholders)
                    ORDER BY ti.transaction_id, ti.transaction_item_id";
        $stmt = $conn->prepare($itemSql);
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $itemsByTxn[(int)$row['transaction_id']][] = $row;
        }
        $stmt->close();
    }

    $transactions = [];
    foreach ($rowsByTxn as $tid => $t) {
        $items = $itemsByTxn[$tid] ?? [];
        $summaryParts = [];
        $itemsOut = [];
        foreach ($items as $it) {
            $qty = (int)$it['quantity'];
            $summaryParts[] = $qty . '× ' . $it['product_name'];
            $itemsOut[] = [
                'name' => $it['product_name'],
                'variant' => $it['variant_name_snapshot'],
                'flavor' => $it['chosen_ingredient_name_snapshot'],
                'category' => $it['product_category'] ?? 'Uncategorized',
                'quantity' => $qty,
                'unitPrice' => round((float)$it['unit_price'], 2),
                'subtotal' => round((float)$it['subtotal'], 2),
            ];
        }
        $dt = new DateTime($t['transaction_date']);
        $transactions[] = [
            'id' => $tid,
            'dateLabel' => $dt->format('M j, Y'),
            'timeLabel' => $dt->format('g:i A'),
            'cashier' => $t['cashier_name'],
            'paymentLabel' => $t['payment_method'] === 'online' ? 'Online' : 'Cash',
            'status' => $t['transaction_status'],
            'statusLabel' => $t['transaction_status'] === 'completed' ? 'Completed' : 'Cancelled',
            'itemsSummary' => $summaryParts ? implode(', ', $summaryParts) : '—',
            'discount' => round((float)$t['discount'], 2),
            'total' => round((float)$t['transaction_total'], 2),
            'notes' => $t['notes'],
            'items' => $itemsOut,
        ];
    }

    return [
        'meta' => [
            'dateFrom' => $dateFrom,
            'dateTill' => $dateTill,
            'category' => $categoryId,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
        ],
        'transactions' => $transactions,
    ];
}

// Feeds the "Inventory" nav-badge — ingredients at critical or low stock.
$navSettings = get_system_settings($conn);
$navLowStockThreshold = (float)$navSettings['low_stock_threshold'];
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE (ingredient_stock_reference IS NULL AND ingredient_stock <= 0) OR (ingredient_stock_reference > 0 AND (ingredient_stock / ingredient_stock_reference) * 100 <= ?)");
$stmt->bind_param('d', $navLowStockThreshold);
$stmt->execute();
$ingredientAlertCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$displayName = $_SESSION['username'] ?? 'Staff';
$initials = strtoupper(substr($displayName, 0, 2));

$categories = [];
$catResult = $conn->query('SELECT product_category_id, product_category FROM product_category ORDER BY product_category');
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
}

if (isset($_GET['action']) && $_GET['action'] === 'history') {
    header('Content-Type: application/json');

    $categoryId = (int)($_GET['category'] ?? 0);
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTill = $_GET['date_till'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTill)) $dateTill = date('Y-m-d');
    $page = max(1, (int)($_GET['page'] ?? 1));

    echo json_encode(build_history_result($conn, $dateFrom, $dateTill, $categoryId, $page), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

$todayStr = date('Y-m-d');
$initialHistory = build_history_result($conn, $todayStr, $todayStr, 0, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Transaction History | SmartStock — Bean There Café</title>
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
    .nav-badge { margin-left: auto; background: var(--red-soft); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; }
    .nav-item:hover { background: rgba(255,255,255,.06); color: var(--cream); }
    .nav-item.active { background: rgba(201,148,58,.12); color: var(--gold-light); border-left-color: var(--gold); }
    .nav-item.active i { color: var(--gold); }
    .sidebar-divider { border: none; border-top: 1px solid rgba(255,255,255,.07); margin: 8px 16px; }

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
    .data-table td { padding: 10px 15px; font-size: 13px; color: var(--charcoal); border-bottom: 1px solid var(--cream-dark); vertical-align: top; }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: rgba(201,148,58,.03); }
    .table-wrap { background: var(--cream); border: 1.5px solid var(--cream-dark); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); }

    .rec-table { width: 100%; border-collapse: collapse; }
    .rec-table th { font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #888; border-bottom: 1.5px solid var(--cream-dark); padding: 0 8px 8px; text-align: left; }
    .rec-table td { padding: 8px 8px; font-size: 12.5px; border-bottom: 1px solid var(--cream-dark); color: var(--charcoal); }
    .rec-table tr:last-child td { border-bottom: none; }

    .status-pill { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; }
    .pill-success { background: rgba(122,158,126,.14); color: var(--sage); }
    .pill-red { background: rgba(192,57,43,.10); color: var(--red-soft); }

    .tag { display:inline-block; padding:2px 8px; border-radius:99px; font-size:10px; font-weight:700; }
    .tag-hot    { background:rgba(192,57,43,.1);  color:#c0392b; }
    .tag-iced   { background:rgba(52,152,219,.1); color:#2980b9; }
    .tag-pastry { background:rgba(243,156,18,.12);color:#d68910; }
    .tag-supply { background:rgba(122,158,126,.12);color:var(--sage); }

    .tbl-btn { padding:4px 11px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; border:1.5px solid; transition:all .15s; background:transparent; }
    .tbl-btn-edit { border-color:var(--gold); color:var(--gold); } .tbl-btn-edit:hover { background:var(--gold); color:#fff; }

    /* ── TOOLBAR ── */
    .filter-select { padding:8px 13px; border-radius:8px; border:1.5px solid var(--cream-dark); background:var(--cream); font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; cursor:pointer; }
    .btn-outline { padding:8px 16px; border-radius:8px; background:transparent; color:var(--mocha-mid); border:1.5px solid var(--cream-dark); font-family:var(--font-body); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
    .btn-outline:hover { border-color:var(--mocha); color:var(--mocha); }
    .btn-outline:disabled { opacity:.4; cursor:not-allowed; }
    .btn-outline:disabled:hover { border-color:var(--cream-dark); color:var(--mocha-mid); }

    .period-tabs { display: flex; gap: 6px; }
    .period-tab {
      padding: 7px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
      border: 1.5px solid var(--cream-dark); background: var(--cream); color: var(--charcoal-mid);
      transition: all .2s;
    }
    .period-tab.active { background: var(--mocha); color: var(--cream); border-color: var(--mocha); }

    .time-filter-wrapper { display: flex; align-items: center; gap: 8px; background: var(--cream); border: 1.5px solid var(--cream-dark); border-radius: 8px; padding: 6px 12px; height: 38px; }
    .time-filter-wrapper input[type="date"] { border:none; background:transparent; font-family:var(--font-mono); font-size:13px; font-weight:600; color:var(--charcoal); outline:none; width:110px; }

    /* ── TEXT UTILS ── */
    .text-mono  { font-family: var(--font-mono); }
    .text-gold  { color: var(--gold); }

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
    .btn-modal-cancel { width:100%; padding:9px; margin-top:14px; background:transparent; color:#bbb; border:1.5px solid var(--cream-dark); border-radius:8px; font-family:var(--font-body); font-size:13px; cursor:pointer; transition:all .2s; }
    .btn-modal-cancel:hover { color:var(--red-soft); border-color:var(--red-soft); }

    .txn-summary-row { display:flex; justify-content:space-between; font-size:13px; padding:5px 0; }
    .txn-summary-row.total { font-weight:700; font-size:15px; color:var(--mocha-deep); border-top:1.5px solid var(--cream-dark); margin-top:6px; padding-top:10px; }
    .txn-summary-row.discount { color:var(--red-soft); }
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
  <a href="staff_transaction_history.php" class="nav-item active"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="staff_products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
  <a href="staff_inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <hr class="sidebar-divider" />
</nav>

<div id="main">
  <div class="page-strip">
    <div>
      <h1><i class="fas fa-clock-rotate-left" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Transaction History</h1>
      <div class="sub">Browse every past sale by time period and category</div>
    </div>
  </div>
  <div style="padding:22px 26px;">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
      <div class="period-tabs" id="period-tabs">
        <button class="period-tab active" onclick="switchPeriod('daily',this)">Day</button>
        <button class="period-tab" onclick="switchPeriod('weekly',this)">Week</button>
        <button class="period-tab" onclick="switchPeriod('monthly',this)">Month</button>
        <button class="period-tab" onclick="switchPeriod('yearly',this)">Year</button>
      </div>
      <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <div class="time-filter-wrapper" title="Filter by date range">
          <i class="fas fa-calendar-alt" style="color:#aaa; font-size:13px;"></i>
          <input type="date" id="date-from" value="<?= htmlspecialchars($todayStr) ?>" onchange="onDateChange()">
          <span style="font-size:12px; color:#aaa; font-weight:600;">to</span>
          <input type="date" id="date-till" value="<?= htmlspecialchars($todayStr) ?>" onchange="onDateChange()">
        </div>
        <select class="filter-select" id="history-category" onchange="switchCategory(this.value)" style="border-color:var(--mocha); font-weight:600; height:38px;">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>Date &amp; Time</th><th>Items</th><th>Cashier</th><th>Payment</th><th>Total</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody id="history-tbody"></tbody>
      </table>
    </div>
    <div id="history-pagination" style="display:flex; justify-content:space-between; align-items:center; margin-top:14px;"></div>
  </div>
</div>

<div class="modal-overlay" id="txn-detail-modal">
  <div class="modal-box" style="max-width:560px;">
    <div class="modal-title" id="txn-detail-title"><i class="fas fa-receipt" style="color:var(--gold);margin-right:8px;"></i>Transaction</div>
    <div class="modal-sub" id="txn-detail-sub"></div>
    <table class="rec-table">
      <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
      <tbody id="txn-detail-items"></tbody>
    </table>
    <div style="margin-top:14px;">
      <div class="txn-summary-row"><span>Subtotal</span><span id="txn-detail-subtotal">₱0.00</span></div>
      <div class="txn-summary-row discount" id="txn-detail-discount-row" style="display:none;"><span>Discount</span><span id="txn-detail-discount">-₱0.00</span></div>
      <div class="txn-summary-row total"><span>Total</span><span id="txn-detail-total">₱0.00</span></div>
      <div class="txn-summary-row"><span>Payment Method</span><span id="txn-detail-payment"></span></div>
      <div class="txn-summary-row"><span>Cashier</span><span id="txn-detail-cashier"></span></div>
    </div>
    <div id="txn-detail-notes-row" style="display:none;margin-top:12px;padding:10px 12px;background:var(--cream);border:1px dashed var(--cream-dark);border-radius:8px;font-size:12.5px;color:var(--charcoal-mid);">
      <strong>Note:</strong> <span id="txn-detail-notes"></span>
    </div>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('txn-detail-modal')">Close</button>
  </div>
</div>

<div id="toast-container"></div>
<script id="history-data" type="application/json"><?= json_encode($initialHistory, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

<script>
  let currentCategory = '0', currentPage = 1, currentTransactions = [];

  document.addEventListener('DOMContentLoaded', () => {
    updateClock(); setInterval(updateClock, 1000);
    renderHistory(JSON.parse(document.getElementById('history-data').textContent));
  });

  function updateClock() {
    const clock = document.getElementById('clock');
    if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function pad(n) { return String(n).padStart(2, '0'); }
  function toDateStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

  // Picks a sensible default date range for the chosen period; users can still override it manually
  function applyPeriodDefaultRange(period) {
    const today = new Date();
    const from = new Date(today);
    if (period === 'weekly') from.setDate(from.getDate() - 6);
    else if (period === 'monthly') from.setDate(from.getDate() - 29);
    else if (period === 'yearly') from.setDate(from.getDate() - 364);
    document.getElementById('date-from').value = toDateStr(from);
    document.getElementById('date-till').value = toDateStr(today);
  }

  function switchPeriod(p, el) {
    document.querySelectorAll('.period-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    applyPeriodDefaultRange(p);
    currentPage = 1;
    fetchHistory();
  }

  function switchCategory(cat) {
    currentCategory = cat;
    currentPage = 1;
    fetchHistory();
  }

  // Manually editing a date deselects the period tabs — unlike Sales Report, no chart here
  // rides on which tab is "active", so leaving a stale tab highlighted would just be confusing.
  function onDateChange() {
    document.querySelectorAll('.period-tab').forEach(t => t.classList.remove('active'));
    currentPage = 1;
    fetchHistory();
  }

  function goToPage(delta) {
    currentPage += delta;
    fetchHistory();
  }

  async function fetchHistory() {
    const params = new URLSearchParams({
      action: 'history',
      category: currentCategory,
      date_from: document.getElementById('date-from').value,
      date_till: document.getElementById('date-till').value,
      page: currentPage
    });
    try {
      const res = await fetch('staff_transaction_history.php?' + params.toString());
      renderHistory(await res.json());
    } catch (err) {
      showToast('Could not load transaction history.', 'warn');
    }
  }

  function money(n) {
    return '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function renderHistory(data) {
    currentTransactions = data.transactions;
    currentPage = data.meta.page;
    renderHistoryTable(data.transactions);
    renderPagination(data.meta);
  }

  function renderHistoryTable(transactions) {
    const tbody = document.getElementById('history-tbody');
    if (!transactions || transactions.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#aaa;padding:24px 8px;">No transactions found for this period.</td></tr>';
      return;
    }
    tbody.innerHTML = transactions.map(t => `
      <tr>
        <td class="text-mono">#${t.id}</td>
        <td>${t.dateLabel}<br><span style="color:#aaa;font-size:11.5px;">${t.timeLabel}</span></td>
        <td>${t.itemsSummary}</td>
        <td>${t.cashier}</td>
        <td>${t.paymentLabel}</td>
        <td class="text-mono text-gold">${money(t.total)}</td>
        <td><span class="status-pill ${t.status === 'completed' ? 'pill-success' : 'pill-red'}">${t.statusLabel}</span></td>
        <td><button class="tbl-btn tbl-btn-edit" onclick="openTxnDetail(${t.id})"><i class="fas fa-eye"></i> View</button></td>
      </tr>
    `).join('');
  }

  function renderPagination(meta) {
    const el = document.getElementById('history-pagination');
    if (!el) return;
    if (meta.totalCount === 0) { el.innerHTML = ''; return; }
    const start = (meta.page - 1) * meta.perPage + 1;
    const end = Math.min(meta.page * meta.perPage, meta.totalCount);
    el.innerHTML = `
      <span style="font-size:12px;color:#888;">Showing ${start}–${end} of ${meta.totalCount}</span>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn-outline" ${meta.page <= 1 ? 'disabled' : ''} onclick="goToPage(-1)"><i class="fas fa-chevron-left"></i></button>
        <span style="font-size:12px;color:#888;">Page ${meta.page} of ${meta.totalPages}</span>
        <button class="btn-outline" ${meta.page >= meta.totalPages ? 'disabled' : ''} onclick="goToPage(1)"><i class="fas fa-chevron-right"></i></button>
      </div>
    `;
  }

  // Plain-text "Name — Size (Flavor)" label, same convention as the checkout page's cart/receipt.
  function itemLine(item) {
    let label = item.name;
    if (item.variant) label += ' — ' + item.variant;
    if (item.flavor) label += ' (' + item.flavor + ')';
    return label;
  }

  function historyCategoryTagClass(name) {
    const n = (name || '').toLowerCase();
    if (n.includes('hot')) return 'tag-hot';
    if (n.includes('iced') || n.includes('cold')) return 'tag-iced';
    if (n.includes('pastr') || n.includes('bread') || n.includes('bake')) return 'tag-pastry';
    return 'tag-supply';
  }

  function openTxnDetail(id) {
    const t = currentTransactions.find(x => x.id === id);
    if (!t) return;
    document.getElementById('txn-detail-title').innerHTML = `<i class="fas fa-receipt" style="color:var(--gold);margin-right:8px;"></i>Transaction #${t.id}`;
    document.getElementById('txn-detail-sub').innerHTML = `${t.dateLabel} · ${t.timeLabel} <span class="status-pill ${t.status === 'completed' ? 'pill-success' : 'pill-red'}" style="margin-left:6px;">${t.statusLabel}</span>`;
    document.getElementById('txn-detail-items').innerHTML = t.items.map(it => `
      <tr>
        <td>${itemLine(it)} <span class="tag ${historyCategoryTagClass(it.category)}" style="margin-left:4px;">${it.category}</span></td>
        <td class="text-mono">${it.quantity}</td>
        <td class="text-mono">${money(it.unitPrice)}</td>
        <td class="text-mono">${money(it.subtotal)}</td>
      </tr>
    `).join('');
    const subtotal = t.items.reduce((sum, it) => sum + it.subtotal, 0);
    document.getElementById('txn-detail-subtotal').textContent = money(subtotal);
    const discountRow = document.getElementById('txn-detail-discount-row');
    if (t.discount > 0) {
      discountRow.style.display = 'flex';
      document.getElementById('txn-detail-discount').textContent = '-' + money(t.discount);
    } else {
      discountRow.style.display = 'none';
    }
    document.getElementById('txn-detail-total').textContent = money(t.total);
    document.getElementById('txn-detail-payment').textContent = t.paymentLabel;
    document.getElementById('txn-detail-cashier').textContent = t.cashier;
    const notesRow = document.getElementById('txn-detail-notes-row');
    if (t.notes) {
      notesRow.style.display = 'block';
      document.getElementById('txn-detail-notes').textContent = t.notes;
    } else {
      notesRow.style.display = 'none';
    }
    openModal('txn-detail-modal');
  }

  function openModal(id)  { document.getElementById(id).classList.add('show'); }
  function closeModal(id) { document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); })
  );

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
