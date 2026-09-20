<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe staff') {
    header('Location: ../signin.php');
    exit;
}

$settings = get_system_settings($conn);

// One-time safety migration: ensures the soft-remove flag exists so removing an order from the
// queue never has to delete it — Transaction History and Sales Report keep the data.
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'removed_from_queue'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE transactions ADD COLUMN removed_from_queue TINYINT(1) NOT NULL DEFAULT 0 AFTER order_status");
}
if ($colCheck) $colCheck->close();

// Feeds the "Inventory" nav-badge — ingredients at critical or low stock.
$navLowStockThreshold = (float)$settings['low_stock_threshold'];
$navStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE (ingredient_stock_reference IS NULL AND ingredient_stock <= 0) OR (ingredient_stock_reference > 0 AND (ingredient_stock / ingredient_stock_reference) * 100 <= ?)");
$navStmt->bind_param('d', $navLowStockThreshold);
$navStmt->execute();
$ingredientAlertCount = (int)$navStmt->get_result()->fetch_assoc()['cnt'];
$navStmt->close();

// Update order status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    header('Content-Type: application/json');

    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $newStatus = $_POST['order_status'] ?? '';

    if ($transactionId <= 0 || !in_array($newStatus, ['done', 'pending', 'cancelled'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid order or status.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE transactions SET order_status = ? WHERE transaction_id = ?");
    $stmt->bind_param('si', $newStatus, $transactionId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'transaction_id' => $transactionId, 'order_status' => $newStatus]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Order not found or status unchanged.']);
    }
    $stmt->close();
    exit;
}

// Remove order from queue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_order') {
    header('Content-Type: application/json');

    $transactionId = (int)($_POST['transaction_id'] ?? 0);

    if ($transactionId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid order.']);
        exit;
    }

    // Soft-remove: flag the order so it leaves the queue, but keep the record so Transaction
    // History and Sales Report data are not deleted.
    $stmt = $conn->prepare("UPDATE transactions SET removed_from_queue = 1 WHERE transaction_id = ? AND transaction_status = 'completed' AND removed_from_queue = 0");
    $stmt->bind_param('i', $transactionId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'transaction_id' => $transactionId]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Order not found or already removed.']);
    }
    $stmt->close();
    exit;
}

// Bulk remove orders from queue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_orders_bulk') {
    header('Content-Type: application/json');

    $ids = array_map('intval', (array)($_POST['transaction_ids'] ?? []));
    $ids = array_values(array_unique(array_filter($ids, function ($id) { return $id > 0; })));

    if (empty($ids)) {
        echo json_encode(['success' => false, 'error' => 'No orders selected.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    // Soft-remove: flag the orders so they leave the queue, but keep the records so Transaction
    // History and Sales Report data are not deleted.
    $stmt = $conn->prepare("UPDATE transactions SET removed_from_queue = 1 WHERE transaction_id IN ($placeholders) AND transaction_status = 'completed' AND removed_from_queue = 0");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['success' => true, 'deleted' => $removed]);
    exit;
}

// Fetch queued orders (completed transactions not yet removed from the queue) with their items
$orders = [];
$orderStmt = $conn->query("
    SELECT t.transaction_id, t.transaction_date, t.transaction_total, t.transaction_status,
           t.order_status, t.payment_method, t.discount, t.order_type, t.table_number, t.notes,
           COALESCE(t.cashier_username, u.username, 'Deleted user') AS cashier_name
    FROM transactions t
    LEFT JOIN users u ON u.user_id = t.user_id
    WHERE t.transaction_status = 'completed' AND t.removed_from_queue = 0
    ORDER BY t.transaction_date DESC
");
while ($row = $orderStmt->fetch_assoc()) {
    $tid = (int)$row['transaction_id'];
    $orders[$tid] = $row;
    $orders[$tid]['items'] = [];
}

if ($orders) {
    $ids = array_keys($orders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $itemStmt = $conn->prepare("
        SELECT ti.transaction_id,
               COALESCE(ti.product_name_snapshot, p.product_name, 'Deleted product') AS product_name,
               ti.variant_name_snapshot, ti.chosen_ingredient_name_snapshot,
               ti.sugar_level_snapshot, ti.quantity, ti.unit_price, ti.subtotal
        FROM transaction_items ti
        LEFT JOIN products p ON p.product_id = ti.product_id
        WHERE ti.transaction_id IN ($placeholders)
        ORDER BY ti.transaction_id, ti.transaction_item_id
    ");
    $itemStmt->bind_param($types, ...$ids);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    while ($item = $itemResult->fetch_assoc()) {
        $orders[(int)$item['transaction_id']]['items'][] = $item;
    }
    $itemStmt->close();
}

$displayName = $_SESSION['username'] ?? 'Staff';
$initials = strtoupper(substr($displayName, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Order Queue | SmartStock — Bean There Café</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
  <style>
    :root {
      --mocha: #4A2C2A;
      --mocha-deep: #2E1A18;
      --mocha-mid: #6B3D3A;
      --cream: #F5ECD7;
      --cream-light: #FBF6EE;
      --cream-dark: #E8D8BA;
      --charcoal: #2C2C2C;
      --charcoal-mid: #444444;
      --gold: #C9943A;
      --gold-light: #E8B860;
      --sage: #7A9E7E;
      --red-soft: #C0392B;
      --sidebar-w: 240px;
      --header-h: 64px;
      --shadow-sm: 0 2px 8px rgba(74, 44, 42, .10);
      --shadow-md: 0 6px 24px rgba(74, 44, 42, .15);
      --shadow-lg: 0 12px 40px rgba(74, 44, 42, .22);
      --radius: 12px;
      --radius-lg: 18px;
      --font-display: 'Playfair Display', serif;
      --font-body: 'DM Sans', sans-serif;
      --font-mono: 'DM Mono', monospace;
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
    .nav-badge { margin-left: auto; background: var(--red-soft); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; }
    .nav-item:hover { background: rgba(255,255,255,.06); color: var(--cream); }
    .nav-item.active { background: rgba(201,148,58,.12); color: var(--gold-light); border-left-color: var(--gold); }
    .nav-item.active i { color: var(--gold); }
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
    .pill-pending { background: rgba(201,148,58,.14); color: #8a6320; }

    .order-status-select {
      padding: 6px 10px;
      border-radius: 8px;
      border: 1.5px solid var(--cream-dark);
      background: var(--cream-light);
      font-family: var(--font-body);
      font-size: 12.5px;
      font-weight: 600;
      color: var(--charcoal);
      outline: none;
      cursor: pointer;
      transition: border-color .2s;
    }
    .order-status-select:focus { border-color: var(--gold); }
    .order-status-select.status-done { border-color: var(--sage); color: var(--sage); }
    .order-status-select.status-pending { border-color: var(--gold); color: #8a6320; }
    .order-status-select.status-cancelled { border-color: var(--red-soft); color: var(--red-soft); }

    .tbl-btn { padding:4px 11px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; border:1.5px solid; transition:all .15s; background:transparent; }
    .tbl-btn-view { border-color:var(--gold); color:var(--gold); } .tbl-btn-view:hover { background:var(--gold); color:#fff; }
    .tbl-btn-remove { border-color:var(--red-soft); color:var(--red-soft); } .tbl-btn-remove:hover { background:var(--red-soft); color:#fff; }

    /* ── BULK SELECT ── */
    .col-check { width:34px; text-align:center; }
    .order-check { width:16px; height:16px; accent-color:var(--red-soft); cursor:pointer; }

    /* ── TOOLBAR ── */
    .filter-select { padding:8px 13px; border-radius:8px; border:1.5px solid var(--cream-dark); background:var(--cream); font-family:var(--font-body); font-size:13px; color:var(--charcoal); outline:none; cursor:pointer; }

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
    .modal-box { background:var(--cream-light); border-radius:var(--radius-lg); padding:28px 30px; max-width:560px; width:92%; max-height:88vh; overflow-y:auto; box-shadow:var(--shadow-lg); animation:popIn .25s cubic-bezier(.34,1.56,.64,1); }
    @keyframes popIn { from{opacity:0;transform:scale(.88);}to{opacity:1;transform:scale(1);} }
    .modal-title { font-family:var(--font-display); font-size:19px; color:var(--mocha-deep); margin-bottom:5px; }
    .modal-sub   { font-size:13px; color:#888; margin-bottom:18px; }
    .btn-modal-cancel { width:100%; padding:9px; margin-top:14px; background:transparent; color:#bbb; border:1.5px solid var(--cream-dark); border-radius:8px; font-family:var(--font-body); font-size:13px; cursor:pointer; transition:all .2s; }
    .btn-modal-cancel:hover { color:var(--red-soft); border-color:var(--red-soft); }

    .txn-summary-row { display:flex; justify-content:space-between; font-size:13px; padding:5px 0; }
    .txn-summary-row.total { font-weight:700; font-size:15px; color:var(--mocha-deep); border-top:1.5px solid var(--cream-dark); margin-top:6px; padding-top:10px; }
    .txn-summary-row.discount { color:var(--red-soft); }

    .empty-state {
      text-align: center;
      padding: 50px 20px;
      color: #aaa;
    }
    .empty-state i { font-size: 48px; color: #ccc; margin-bottom: 12px; }
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
  <a href="staff_order_queue.php" class="nav-item active"><i class="fas fa-list-check"></i> Order Queue</a>
  <a href="staff_transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="staff_products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
  <a href="staff_inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_expenses.php" class="nav-item"><i class="fas fa-wallet"></i> Expenses</a>
  <a href="staff_reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <hr class="sidebar-divider" />
  <div class="sidebar-footer">
    <p>SmartStock v1.0<br />Bean There Café<br />ISO/IEC 25010 Compliant</p>
  </div>
</nav>

<div id="main">
  <div class="page-strip">
    <div>
      <h1><i class="fas fa-list-check" style="color:var(--gold);font-size:22px;margin-right:10px;"></i>Order Queue</h1>
      <div class="sub">Track and update the status of customer orders</div>
    </div>
  </div>
  <div style="padding:22px 26px;">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; gap:8px; align-items:center;">
        <div class="status-pill pill-pending" style="font-size:12px; padding:5px 14px;"><i class="fas fa-hourglass-half" style="margin-right:5px;"></i>Pending</div>
        <div class="status-pill pill-success" style="font-size:12px; padding:5px 14px;"><i class="fas fa-check-circle" style="margin-right:5px;"></i>Done</div>
        <div class="status-pill pill-red" style="font-size:12px; padding:5px 14px;"><i class="fas fa-ban" style="margin-right:5px;"></i>Cancelled</div>
      </div>
      <div style="display:flex; gap:10px; align-items:center;">
        <button type="button" id="bulk-remove-btn" style="display:none; padding:8px 16px; border-radius:8px; border:none; background:var(--red-soft); color:#fff; font-family:var(--font-body); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; box-shadow:0 2px 8px rgba(192,57,43,.25);" onclick="confirmBulkRemove()">
          <i class="fas fa-trash" style="margin-right:6px;"></i>Remove Selected (<span id="bulk-count">0</span>)
        </button>
        <select class="filter-select" id="status-filter" onchange="filterByStatus(this.value)">
          <option value="all">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="done">Done</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th class="col-check"><input type="checkbox" class="order-check" id="select-all-cb" title="Select all" onchange="toggleSelectAll(this)"></th>
            <th>#</th><th>Date & Time</th><th>Items</th><th>Order Type</th><th>Table</th><th>Total</th><th>Order Status</th><th>Action</th>
          </tr>
        </thead>
        <tbody id="order-tbody">
          <?php if (empty($orders)): ?>
            <tr><td colspan="9">
              <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <div>No orders yet. Complete a transaction to see it here.</div>
              </div>
            </td></tr>
          <?php else: ?>
            <?php foreach ($orders as $tid => $order): ?>
              <?php
                $dt = new DateTime($order['transaction_date']);
                $statusClass = $order['order_status'] === 'done' ? 'pill-success' : ($order['order_status'] === 'cancelled' ? 'pill-red' : 'pill-pending');
                $statusLabel = ucfirst($order['order_status']);
                $orderTypeLabel = ($order['order_type'] ?? 'dine_in') === 'takeout' ? 'Takeout' : 'Dine-in';
                $itemsSummary = [];
                foreach ($order['items'] as $item) {
                    $label = $item['product_name'];
                    if ($item['variant_name_snapshot']) $label .= ' — ' . $item['variant_name_snapshot'];
                    if ($item['chosen_ingredient_name_snapshot']) $label .= ' (' . $item['chosen_ingredient_name_snapshot'] . ')';
                    if ($item['sugar_level_snapshot']) $label .= ' — ' . $item['sugar_level_snapshot'] . ' sugar';
                    $itemsSummary[] = (int)$item['quantity'] . '× ' . $label;
                }
              ?>
              <tr data-status="<?= htmlspecialchars($order['order_status']) ?>" data-txn-id="<?= $tid ?>">
                <td class="col-check"><input type="checkbox" class="order-check order-checkbox" value="<?= $tid ?>" title="Select order #<?= $tid ?>" onchange="updateBulkRemoveState()"></td>
                <td class="text-mono">#<?= $tid ?></td>
                <td>
                  <div style="font-weight:600;"><?= $dt->format('M j, Y') ?></div>
                  <div style="font-size:11px;color:#999;"><?= $dt->format('g:i A') ?></div>
                </td>
                <td style="max-width:280px;">
                  <div style="font-size:12px;line-height:1.6;color:var(--charcoal-mid);"><?= htmlspecialchars(implode(', ', $itemsSummary)) ?></div>
                </td>
                <td><span class="status-pill" style="background:rgba(74,44,42,.08);color:var(--mocha);"><?= $orderTypeLabel ?></span></td>
                <td><?= $order['table_number'] ? 'Table ' . (int)$order['table_number'] : '—' ?></td>
                <td class="text-mono" style="font-weight:700;color:var(--mocha-deep);">₱<?= number_format((float)$order['transaction_total'], 2) ?></td>
                <td>
                  <select class="order-status-select status-<?= htmlspecialchars($order['order_status']) ?>" data-txn-id="<?= $tid ?>" onchange="updateOrderStatus(this)">
                    <option value="pending" <?= $order['order_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="done" <?= $order['order_status'] === 'done' ? 'selected' : '' ?>>Done</option>
                    <option value="cancelled" <?= $order['order_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                  </select>
                </td>
                <td>
                  <button class="tbl-btn tbl-btn-remove" onclick="confirmRemoveOrder(<?= $tid ?>)"><i class="fas fa-trash" style="margin-right:4px;"></i>Remove</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Remove Order Confirmation Modal -->
<div class="modal-overlay" id="remove-order-modal">
  <div class="modal-box" style="max-width:420px;text-align:center;">
    <div style="font-size:44px;color:var(--red-soft);margin-bottom:12px;"><i class="fas fa-trash-can"></i></div>
    <div class="modal-title" style="font-size:20px;">Remove Order</div>
    <div class="modal-sub" style="margin-bottom:20px;">Are you sure you want to remove <strong>Order #<span id="remove-order-id"></span></strong> from the queue? This action cannot be undone.</div>
    <div style="display:flex;gap:10px;">
      <button type="button" class="btn-modal-cancel" style="flex:1;margin-top:0;" onclick="closeModal('remove-order-modal')">Cancel</button>
      <button type="button" id="confirm-remove-btn" style="flex:1;padding:9px;background:var(--red-soft);color:#fff;border:none;border-radius:8px;font-family:var(--font-body);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;" onclick="removeOrder()"><i class="fas fa-trash" style="margin-right:5px;"></i>Remove</button>
    </div>
  </div>
</div>

<!-- Bulk Remove Confirmation Modal -->
<div class="modal-overlay" id="bulk-remove-modal">
  <div class="modal-box" style="max-width:440px;text-align:center;">
    <div style="font-size:44px;color:var(--red-soft);margin-bottom:12px;"><i class="fas fa-trash-can"></i></div>
    <div class="modal-title" style="font-size:20px;">Remove Selected Orders</div>
    <div class="modal-sub" style="margin-bottom:10px;">You are about to remove <strong><span id="bulk-remove-count">0</span> order(s)</strong> from the queue.</div>
    <div id="bulk-remove-ids" style="font-family:var(--font-mono);font-size:12.5px;color:var(--mocha-mid);background:var(--cream);border:1px dashed var(--cream-dark);border-radius:8px;padding:10px 12px;margin-bottom:10px;word-break:break-word;"></div>
    <div class="modal-sub" style="margin-bottom:20px;">This action cannot be undone.</div>
    <div style="display:flex;gap:10px;">
      <button type="button" class="btn-modal-cancel" style="flex:1;margin-top:0;" onclick="closeModal('bulk-remove-modal')">Cancel</button>
      <button type="button" id="confirm-bulk-remove-btn" style="flex:1;padding:9px;background:var(--red-soft);color:#fff;border:none;border-radius:8px;font-family:var(--font-body);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;" onclick="bulkRemoveOrders()"><i class="fas fa-trash" style="margin-right:5px;"></i>Remove All</button>
    </div>
  </div>
</div>

<!-- Order Detail Modal -->
<div class="modal-overlay" id="order-detail-modal">
  <div class="modal-box">
    <div class="modal-title" id="order-detail-title"><i class="fas fa-clipboard-list" style="color:var(--gold);margin-right:8px;"></i>Order</div>
    <div class="modal-sub" id="order-detail-sub"></div>
    <table class="rec-table">
      <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
      <tbody id="order-detail-items"></tbody>
    </table>
    <div style="margin-top:14px;">
      <div class="txn-summary-row"><span>Subtotal</span><span id="order-detail-subtotal">₱0.00</span></div>
      <div class="txn-summary-row discount" id="order-detail-discount-row" style="display:none;"><span>Discount</span><span id="order-detail-discount">-₱0.00</span></div>
      <div class="txn-summary-row total"><span>Total</span><span id="order-detail-total">₱0.00</span></div>
      <div class="txn-summary-row"><span>Payment Method</span><span id="order-detail-payment"></span></div>
      <div class="txn-summary-row"><span>Order Type</span><span id="order-detail-order-type"></span></div>
      <div class="txn-summary-row"><span>Table Number</span><span id="order-detail-table"></span></div>
      <div class="txn-summary-row"><span>Cashier</span><span id="order-detail-cashier"></span></div>
      <div class="txn-summary-row"><span>Order Status</span><span id="order-detail-status"></span></div>
    </div>
    <div id="order-detail-notes-row" style="display:none;margin-top:12px;padding:10px 12px;background:var(--cream);border:1px dashed var(--cream-dark);border-radius:8px;font-size:12.5px;color:var(--charcoal-mid);">
      <strong>Note:</strong> <span id="order-detail-notes"></span>
    </div>
    <button type="button" class="btn-modal-cancel" style="margin-top:16px;" onclick="closeModal('order-detail-modal')">Close</button>
  </div>
</div>

<div id="toast-container"></div>

<script>
  // Order data embedded for the detail modal
  const ordersData = <?= json_encode(array_values($orders), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  function filterByStatus(status) {
    const rows = document.querySelectorAll('#order-tbody tr[data-status]');
    rows.forEach(row => {
      if (status === 'all' || row.dataset.status === status) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });

    // Reset selections when the filter changes so hidden orders are never removed by mistake
    document.querySelectorAll('.order-checkbox').forEach(cb => { cb.checked = false; });
    updateBulkRemoveState();
  }

  async function updateOrderStatus(select) {
    const txnId = select.dataset.txnId;
    const newStatus = select.value;

    // Optimistic UI update
    select.className = 'order-status-select status-' + newStatus;

    try {
      const res = await fetch('staff_order_queue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'update_status', transaction_id: txnId, order_status: newStatus })
      });
      const data = await res.json();
      if (!data.success) {
        showToast(data.error || 'Could not update order status.', 'warn');
        // Revert
        select.value = select.dataset.originalStatus || 'pending';
        select.className = 'order-status-select status-' + select.value;
        return;
      }
      select.dataset.originalStatus = newStatus;
      showToast(`Order #${txnId} marked as ${newStatus}.`, 'success');
      setTimeout(() => location.reload(), 800);
    } catch (err) {
      showToast('Could not reach the server. Please try again.', 'warn');
      select.value = select.dataset.originalStatus || 'pending';
      select.className = 'order-status-select status-' + select.value;
    }
  }

  let pendingRemoveId = null;

  function confirmRemoveOrder(txnId) {
    pendingRemoveId = txnId;
    document.getElementById('remove-order-id').textContent = txnId;
    document.getElementById('remove-order-modal').classList.add('show');
  }

  async function removeOrder() {
    if (!pendingRemoveId) return;

    const txnId = pendingRemoveId;
    const btn = document.getElementById('confirm-remove-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:5px;"></i>Removing...';

    try {
      const res = await fetch('staff_order_queue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'remove_order', transaction_id: txnId })
      });
      const data = await res.json();
      if (!data.success) {
        showToast(data.error || 'Could not remove the order.', 'warn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash" style="margin-right:5px;"></i>Remove';
        return;
      }

      // Remove the row from the table
      const row = document.querySelector(`#order-tbody tr[data-txn-id="${txnId}"]`);
      if (row) row.remove();

      // If no rows remain, show empty state
      const tbody = document.getElementById('order-tbody');
      if (tbody.querySelectorAll('tr[data-txn-id]').length === 0) {
        tbody.innerHTML = `<tr><td colspan="9">
          <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <div>No orders yet. Complete a transaction to see it here.</div>
          </div>
        </td></tr>`;
      }

      closeModal('remove-order-modal');
      showToast(`Order #${txnId} removed from the queue.`, 'success');
      setTimeout(() => location.reload(), 800);
    } catch (err) {
      showToast('Could not reach the server. Please try again.', 'warn');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-trash" style="margin-right:5px;"></i>Remove';
    }
  }

  // ── BULK REMOVE ──
  function getCheckedBoxes() {
    return Array.from(document.querySelectorAll('.order-checkbox:checked'));
  }

  function getVisibleRowChecks() {
    return Array.from(document.querySelectorAll('.order-checkbox')).filter(cb => cb.closest('tr').style.display !== 'none');
  }

  function updateBulkRemoveState() {
    const checkedCount = getCheckedBoxes().length;
    const btn = document.getElementById('bulk-remove-btn');
    document.getElementById('bulk-count').textContent = checkedCount;
    btn.style.display = checkedCount > 0 ? '' : 'none';

    const selectAll = document.getElementById('select-all-cb');
    const visible = getVisibleRowChecks();
    if (visible.length === 0) {
      selectAll.checked = false;
      selectAll.indeterminate = false;
    } else {
      const allChecked = visible.every(cb => cb.checked);
      selectAll.checked = allChecked;
      selectAll.indeterminate = !allChecked && visible.some(cb => cb.checked);
    }
  }

  function toggleSelectAll(master) {
    getVisibleRowChecks().forEach(cb => { cb.checked = master.checked; });
    updateBulkRemoveState();
  }

  let pendingBulkIds = [];

  function confirmBulkRemove() {
    const ids = getCheckedBoxes().map(cb => parseInt(cb.value, 10));
    if (!ids.length) return;
    pendingBulkIds = ids;

    document.getElementById('bulk-remove-count').textContent = ids.length;
    const labels = ids.map(id => '#' + id);
    const summary = labels.length <= 5
      ? labels.join(', ')
      : labels.slice(0, 5).join(', ') + ' … +' + (labels.length - 5) + ' more';
    document.getElementById('bulk-remove-ids').textContent = summary;

    document.getElementById('bulk-remove-modal').classList.add('show');
  }

  async function bulkRemoveOrders() {
    if (!pendingBulkIds.length) return;

    const btn = document.getElementById('confirm-bulk-remove-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:5px;"></i>Removing...';

    try {
      const params = new URLSearchParams();
      params.append('action', 'remove_orders_bulk');
      pendingBulkIds.forEach(id => params.append('transaction_ids[]', id));

      const res = await fetch('staff_order_queue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      });
      const data = await res.json();
      if (!data.success) {
        showToast(data.error || 'Could not remove the selected orders.', 'warn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash" style="margin-right:5px;"></i>Remove All';
        return;
      }

      // Remove the rows from the table
      pendingBulkIds.forEach(id => {
        const row = document.querySelector(`#order-tbody tr[data-txn-id="${id}"]`);
        if (row) row.remove();
      });

      // If no rows remain, show empty state
      const tbody = document.getElementById('order-tbody');
      if (tbody.querySelectorAll('tr[data-txn-id]').length === 0) {
        tbody.innerHTML = `<tr><td colspan="9">
          <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <div>No orders yet. Complete a transaction to see it here.</div>
          </div>
        </td></tr>`;
      }

      closeModal('bulk-remove-modal');
      pendingBulkIds = [];
      updateBulkRemoveState();
      showToast(`${data.deleted} order(s) removed from the queue.`, 'success');
      setTimeout(() => location.reload(), 800);
    } catch (err) {
      showToast('Could not reach the server. Please try again.', 'warn');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-trash" style="margin-right:5px;"></i>Remove All';
    }
  }

  function viewOrder(txnId) {
    const order = ordersData.find(o => o.transaction_id === txnId);
    if (!order) return;

    const dt = new Date(order.transaction_date);
    document.getElementById('order-detail-title').textContent = 'Order #' + txnId;
    document.getElementById('order-detail-sub').textContent = dt.toLocaleString();

    // Items
    const itemsBody = document.getElementById('order-detail-items');
    let subtotal = 0;
    itemsBody.innerHTML = order.items.map(item => {
      let label = item.product_name;
      if (item.variant_name_snapshot) label += ' — ' + item.variant_name_snapshot;
      if (item.chosen_ingredient_name_snapshot) label += ' (' + item.chosen_ingredient_name_snapshot + ')';
      if (item.sugar_level_snapshot) label += ' — ' + item.sugar_level_snapshot + ' sugar';
      const lineTotal = parseFloat(item.subtotal);
      subtotal += lineTotal;
      return `<tr>
        <td>${label}</td>
        <td>${item.quantity}</td>
        <td>₱${parseFloat(item.unit_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
        <td>₱${lineTotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
      </tr>`;
    }).join('');

    document.getElementById('order-detail-subtotal').textContent = '₱' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2});

    const discount = parseFloat(order.discount);
    if (discount > 0) {
      document.getElementById('order-detail-discount-row').style.display = '';
      document.getElementById('order-detail-discount').textContent = '-₱' + discount.toLocaleString(undefined, {minimumFractionDigits: 2});
    } else {
      document.getElementById('order-detail-discount-row').style.display = 'none';
    }

    document.getElementById('order-detail-total').textContent = '₱' + parseFloat(order.transaction_total).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('order-detail-payment').textContent = order.payment_method === 'online' ? 'Online' : 'Cash';
    document.getElementById('order-detail-order-type').textContent = (order.order_type || 'dine_in') === 'takeout' ? 'Takeout' : 'Dine-in';
    document.getElementById('order-detail-table').textContent = order.table_number ? 'Table ' + order.table_number : '—';
    document.getElementById('order-detail-cashier').textContent = order.cashier_name;

    const statusEl = document.getElementById('order-detail-status');
    const statusClass = order.order_status === 'done' ? 'pill-success' : (order.order_status === 'cancelled' ? 'pill-red' : 'pill-pending');
    statusEl.innerHTML = `<span class="status-pill ${statusClass}">${order.order_status.charAt(0).toUpperCase() + order.order_status.slice(1)}</span>`;

    const notesRow = document.getElementById('order-detail-notes-row');
    if (order.notes) {
      notesRow.style.display = '';
      document.getElementById('order-detail-notes').textContent = order.notes;
    } else {
      notesRow.style.display = 'none';
    }

    document.getElementById('order-detail-modal').classList.add('show');
  }

  function closeModal(id) {
    document.getElementById(id).classList.remove('show');
  }

  function updateClock() {
    const el = document.getElementById('clock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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

  // Initialize original status on each select
  document.querySelectorAll('.order-status-select').forEach(sel => {
    sel.dataset.originalStatus = sel.value;
  });

  updateClock();
  setInterval(updateClock, 1000);
</script>
</body>

</html>