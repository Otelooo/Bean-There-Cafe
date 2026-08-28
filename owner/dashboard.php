<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

$displayName = $_SESSION['username'] ?? 'Owner';
$initials = strtoupper(substr($displayName, 0, 2));

$settings = get_system_settings($conn);
$criticalStockThreshold = (float)$settings['critical_stock_threshold'];
$lowStockThreshold = (float)$settings['low_stock_threshold'];

$stmt = $conn->prepare("SELECT COALESCE(SUM(transaction_total),0) AS total FROM transactions WHERE transaction_status = 'completed' AND DATE(transaction_date) = CURDATE()");
$stmt->execute();
$todayRevenue = (float)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM transactions WHERE DATE(transaction_date) = CURDATE()");
$stmt->execute();
$todayTxnCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$avgTxnValue = $todayTxnCount > 0 ? $todayRevenue / $todayTxnCount : 0;

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM products WHERE product_type = 'prepared' AND product_stocks_reference > 0 AND (product_stocks / product_stocks_reference) * 100 <= ?");
$stmt->bind_param('d', $criticalStockThreshold);
$stmt->execute();
$criticalStockCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COALESCE(ti.product_name_snapshot, p.product_name, 'Deleted product') AS product_name, SUM(ti.quantity) AS units
    FROM transaction_items ti
    JOIN transactions t ON t.transaction_id = ti.transaction_id
    LEFT JOIN products p ON p.product_id = ti.product_id
    WHERE t.transaction_status = 'completed' AND DATE(t.transaction_date) = CURDATE()
    GROUP BY COALESCE(ti.product_name_snapshot, p.product_name, 'Deleted product')
    ORDER BY units DESC
    LIMIT 1
");
$stmt->execute();
$bestSeller = $stmt->get_result()->fetch_assoc();
$stmt->close();

$recentTransactions = [];
$stmt = $conn->prepare("
    SELECT t.transaction_id, t.transaction_total, t.payment_method, t.transaction_status,
           COALESCE(t.cashier_username, u.username, 'Deleted user') AS username,
           GROUP_CONCAT(CONCAT(ti.quantity, '\xc3\x97', COALESCE(ti.product_name_snapshot, p.product_name, 'Deleted product')) SEPARATOR ', ') AS items_summary
    FROM transactions t
    LEFT JOIN users u ON u.user_id = t.user_id
    LEFT JOIN transaction_items ti ON ti.transaction_id = t.transaction_id
    LEFT JOIN products p ON p.product_id = ti.product_id
    GROUP BY t.transaction_id, t.transaction_total, t.payment_method, t.transaction_status, t.cashier_username, u.username
    ORDER BY t.transaction_date DESC
    LIMIT 5
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recentTransactions[] = $row;
}
$stmt->close();

// Same critical/low % classification the Inventory page uses for its own ingredient list.
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

$ingredientAlerts = [];
$criticalIngredientCount = 0;
$lowIngredientCount = 0;
$stmt = $conn->prepare("SELECT ingredient_name, ingredient_stock, ingredient_stock_reference FROM product_ingredients");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stock = (float)$row['ingredient_stock'];
    $reference = $row['ingredient_stock_reference'] !== null ? (float)$row['ingredient_stock_reference'] : null;
    $level = ingredient_stock_level($stock, $reference, $criticalStockThreshold, $lowStockThreshold);
    if ($level === 'ok') {
        continue;
    }
    $pct = ($reference !== null && $reference > 0) ? ($stock / $reference) * 100 : 0.0;
    if ($level === 'crit') {
        $criticalIngredientCount++;
    } else {
        $lowIngredientCount++;
    }
    $ingredientAlerts[] = ['name' => $row['ingredient_name'], 'level' => $level, 'pct' => $pct];
}
$stmt->close();
usort($ingredientAlerts, fn($a, $b) => $a['pct'] <=> $b['pct']);
$ingredientAlertCount = $criticalIngredientCount + $lowIngredientCount;

// ── Revenue overview: this month, this year, and each vs. its prior period ──
$stmt = $conn->prepare("SELECT COALESCE(SUM(transaction_total),0) AS total FROM transactions WHERE transaction_status = 'completed' AND YEAR(transaction_date) = YEAR(CURDATE()) AND MONTH(transaction_date) = MONTH(CURDATE())");
$stmt->execute();
$monthRevenue = (float)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(transaction_total),0) AS total FROM transactions WHERE transaction_status = 'completed' AND YEAR(transaction_date) = YEAR(CURDATE() - INTERVAL 1 MONTH) AND MONTH(transaction_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)");
$stmt->execute();
$lastMonthRevenue = (float)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$monthChangePct = $lastMonthRevenue > 0 ? (($monthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : null;

$stmt = $conn->prepare("SELECT COALESCE(SUM(transaction_total),0) AS total FROM transactions WHERE transaction_status = 'completed' AND YEAR(transaction_date) = YEAR(CURDATE())");
$stmt->execute();
$yearRevenue = (float)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(transaction_total),0) AS total FROM transactions WHERE transaction_status = 'completed' AND YEAR(transaction_date) = YEAR(CURDATE()) - 1");
$stmt->execute();
$lastYearRevenue = (float)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$yearChangePct = $lastYearRevenue > 0 ? (($yearRevenue - $lastYearRevenue) / $lastYearRevenue) * 100 : null;

$monthsElapsedThisYear = (int)date('n');
$avgMonthlyThisYear = $monthsElapsedThisYear > 0 ? $yearRevenue / $monthsElapsedThisYear : 0.0;

// Last 6 months (oldest to newest, ending with the current month) for the trend chart.
$monthlyTotalsByYm = [];
$stmt = $conn->prepare("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym, COALESCE(SUM(transaction_total),0) AS total
    FROM transactions
    WHERE transaction_status = 'completed'
      AND transaction_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
    GROUP BY ym
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $monthlyTotalsByYm[$row['ym']] = (float)$row['total'];
}
$stmt->close();

$monthlyTrend = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-{$i} month", strtotime(date('Y-m-01')));
    $ym = date('Y-m', $ts);
    $monthlyTrend[] = [
        'label' => date('M', $ts),
        'total' => $monthlyTotalsByYm[$ym] ?? 0.0,
    ];
}
$maxMonthlyTotal = max(array_column($monthlyTrend, 'total'));
if ($maxMonthlyTotal <= 0) {
    $maxMonthlyTotal = 1.0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard | SmartStock — Bean There Café</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
  <style>
    /* Paste ALL your original CSS here. I've included it all below for the first file. */
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
      --font-display: 'Playfair Display', serif;
      --font-body: 'DM Sans', sans-serif;
      --font-mono: 'DM Mono', monospace;
      --shadow-sm: 0 2px 8px rgba(74, 44, 42, .10);
      --shadow-md: 0 6px 24px rgba(74, 44, 42, .15);
      --shadow-lg: 0 12px 40px rgba(74, 44, 42, .22);
      --radius: 12px;
      --radius-lg: 18px;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--font-body);
      background: var(--cream-light);
      color: var(--charcoal);
      min-height: 100vh;
      overflow-x: hidden;
    }

    ::-webkit-scrollbar {
      width: 6px;
    }

    ::-webkit-scrollbar-track {
      background: var(--cream);
    }

    ::-webkit-scrollbar-thumb {
      background: var(--mocha-mid);
      border-radius: 99px;
    }

    /* ── HEADER ── */
    #app-header {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 1000;
      height: var(--header-h);
      background: var(--mocha-deep);
      display: flex;
      align-items: center;
      padding: 0 24px 0 0;
      box-shadow: 0 2px 20px rgba(0, 0, 0, .35);
    }

    .header-brand {
      width: var(--sidebar-w);
      flex-shrink: 0;
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0 20px;
      border-right: 1px solid rgba(255, 255, 255, .08);
    }

    .brand-logo {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: var(--gold);
      color: var(--mocha-deep);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      box-shadow: 0 2px 10px rgba(201, 148, 58, .45);
      flex-shrink: 0;
    }

    .brand-text .name {
      font-family: var(--font-display);
      font-size: 15px;
      font-weight: 700;
      color: var(--cream);
    }

    .brand-text .sub {
      font-size: 10px;
      color: var(--gold-light);
      letter-spacing: 1.5px;
      text-transform: uppercase;
    }

    .header-center {
      padding-left: 22px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .portal-badge {
      background: rgba(201, 148, 58, .18);
      color: var(--gold-light);
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      padding: 4px 10px;
      border-radius: 99px;
    }

    .header-view-label {
      font-size: 13px;
      color: rgba(245, 236, 215, .5);
    }

    .header-right {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .header-clock {
      font-family: var(--font-mono);
      font-size: 13px;
      color: rgba(245, 236, 215, .55);
    }

    .header-link {
      display: flex;
      align-items: center;
      gap: 7px;
      background: rgba(122, 158, 126, .14);
      border: 1px solid rgba(122, 158, 126, .28);
      color: #9ecba2;
      font-size: 12px;
      font-weight: 600;
      padding: 6px 14px;
      border-radius: 99px;
      cursor: pointer;
      text-decoration: none;
      transition: all .2s;
    }

    .header-link:hover {
      background: rgba(122, 158, 126, .26);
      color: #9ecba2;
    }

    .header-user {
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(255, 255, 255, .07);
      border: 1px solid rgba(255, 255, 255, .10);
      border-radius: 99px;
      padding: 5px 14px 5px 5px;
      cursor: pointer;
    }

    .header-avatar {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--gold);
      color: var(--mocha-deep);
      font-weight: 700;
      font-size: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .header-user-name {
      font-size: 13px;
      color: var(--cream);
      font-weight: 500;
    }

    .logout-link {
      display: flex;
      align-items: center;
      gap: 6px;
      background: rgba(192, 57, 43, .12);
      border: 1px solid rgba(192, 57, 43, .28);
      color: #e08a80;
      font-size: 12px;
      font-weight: 600;
      padding: 6px 14px;
      border-radius: 99px;
      cursor: pointer;
      text-decoration: none;
      transition: all .2s;
    }

    .logout-link:hover {
      background: rgba(192, 57, 43, .22);
      color: #e08a80;
    }

    /* ── SIDEBAR ── */
    #sidebar {
      position: fixed;
      top: var(--header-h);
      left: 0;
      bottom: 0;
      width: var(--sidebar-w);
      z-index: 900;
      background: var(--mocha-deep);
      display: flex;
      flex-direction: column;
      overflow-y: auto;
    }

    .sidebar-section-label {
      padding: 20px 20px 6px;
      font-size: 9.5px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: rgba(245, 236, 215, .3);
    }

    a.nav-item {
      text-decoration: none;
    }

    /* <-- NEW ADDITION */
    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 11px 20px;
      color: rgba(245, 236, 215, .6);
      cursor: pointer;
      font-size: 13.5px;
      font-weight: 500;
      border-left: 3px solid transparent;
      transition: all .2s;
      user-select: none;
    }

    .nav-item i {
      width: 18px;
      text-align: center;
      font-size: 14px;
    }

    .nav-item:hover {
      background: rgba(255, 255, 255, .06);
      color: var(--cream);
    }

    .nav-item.active {
      background: rgba(201, 148, 58, .12);
      color: var(--gold-light);
      border-left-color: var(--gold);
    }

    .nav-item.active i {
      color: var(--gold);
    }

    .nav-badge {
      margin-left: auto;
      background: var(--red-soft);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      padding: 1px 7px;
      border-radius: 99px;
    }

    .sidebar-divider {
      border: none;
      border-top: 1px solid rgba(255, 255, 255, .07);
      margin: 8px 16px;
    }

    .sidebar-footer {
      margin-top: auto;
      padding: 16px 20px;
      border-top: 1px solid rgba(255, 255, 255, .07);
      text-align: center;
    }

    .sidebar-footer p {
      font-size: 10px;
      color: rgba(245, 236, 215, .22);
      line-height: 1.7;
    }

    /* ── MAIN ── */
    #main {
      margin-left: var(--sidebar-w);
      margin-top: var(--header-h);
      min-height: calc(100vh - var(--header-h));
    }

    .page-strip {
      background: var(--cream);
      border-bottom: 1px solid var(--cream-dark);
      padding: 15px 26px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky; top: var(--header-h); z-index: 500;
    }

    .page-strip h1 {
      font-family: var(--font-display);
      font-size: 21px;
      font-weight: 700;
      color: var(--mocha-deep);
    }

    .page-strip .sub {
      font-size: 12px;
      color: var(--mocha-mid);
      margin-top: 1px;
    }

    /* ── KPI CARDS ── */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
      gap: 12px;
      margin-bottom: 24px;
    }

    .kpi-card {
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      padding: 14px 16px 12px;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;
      transition: transform .2s, box-shadow .2s;
    }

    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: var(--accent, var(--gold));
      border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    }

    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }

    .kpi-icon {
      width: 30px;
      height: 30px;
      border-radius: 9px;
      background: var(--accent-bg, rgba(201, 148, 58, .1));
      color: var(--accent, var(--gold));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      margin-bottom: 8px;
    }

    .kpi-label {
      font-size: 9.5px;
      color: #888;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      margin-bottom: 3px;
    }

    .kpi-value {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 700;
      color: var(--mocha-deep);
      line-height: 1;
    }

    .kpi-sub {
      font-size: 10.5px;
      color: #aaa;
      margin-top: 3px;
    }

    .kpi-trend {
      font-size: 10.5px;
      font-weight: 600;
      margin-top: 3px;
    }

    .kpi-trend.up {
      color: var(--sage);
    }

    .kpi-trend.warn {
      color: #e67e22;
    }

    /* ── TWO-COL GRID ── */
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
      margin-bottom: 18px;
    }

    @media (max-width:960px) {
      .two-col {
        grid-template-columns: 1fr;
      }
    }

    .dash-card {
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      padding: 20px 22px;
      box-shadow: var(--shadow-sm);
    }

    .dash-card-title {
      font-family: var(--font-display);
      font-size: 16px;
      color: var(--mocha-deep);
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .dash-card-title i {
      color: var(--gold);
      font-size: 14px;
    }

    /* ── TABLES ── */
    .data-table {
      width: 100%;
      border-collapse: collapse;
    }

    .data-table thead {
      background: var(--mocha-deep);
    }

    .data-table th {
      padding: 11px 15px;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: rgba(245, 236, 215, .65);
      text-align: left;
    }

    .data-table td {
      padding: 10px 15px;
      font-size: 13px;
      color: var(--charcoal);
      border-bottom: 1px solid var(--cream-dark);
    }

    .data-table tr:last-child td {
      border-bottom: none;
    }

    .data-table tr:hover td {
      background: rgba(201, 148, 58, .03);
    }

    .table-wrap {
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .status-pill {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 99px;
      font-size: 10px;
      font-weight: 700;
    }

    .pill-success {
      background: rgba(122, 158, 126, .14);
      color: var(--sage);
    }

    .pill-warn {
      background: rgba(230, 126, 34, .12);
      color: #e67e22;
    }

    .pill-gold {
      background: rgba(201, 148, 58, .14);
      color: var(--gold);
    }

    .pill-red {
      background: rgba(192, 57, 43, .10);
      color: var(--red-soft);
    }

    .tag {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 99px;
      font-size: 10px;
      font-weight: 700;
    }

    .tag-hot {
      background: rgba(192, 57, 43, .1);
      color: #c0392b;
    }

    .tag-iced {
      background: rgba(52, 152, 219, .1);
      color: #2980b9;
    }

    .tag-pastry {
      background: rgba(243, 156, 18, .12);
      color: #d68910;
    }

    .tag-supply {
      background: rgba(122, 158, 126, .12);
      color: var(--sage);
    }

    .stock-indicator {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-weight: 600;
      font-family: var(--font-mono);
      font-size: 12px;
    }

    .stock-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .stock-ok .stock-dot {
      background: var(--sage);
    }

    .stock-ok {
      color: var(--sage);
    }

    .stock-low .stock-dot {
      background: #e67e22;
    }

    .stock-low {
      color: #e67e22;
    }

    .stock-crit .stock-dot {
      background: var(--red-soft);
    }

    .stock-crit {
      color: var(--red-soft);
    }

    .tbl-btn {
      padding: 4px 11px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      border: 1.5px solid;
      transition: all .15s;
      background: transparent;
    }

    .tbl-btn-edit {
      border-color: var(--gold);
      color: var(--gold);
    }

    .tbl-btn-edit:hover {
      background: var(--gold);
      color: #fff;
    }

    .tbl-btn-del {
      border-color: #c9a;
      color: #c88;
    }

    .tbl-btn-del:hover {
      background: var(--red-soft);
      border-color: var(--red-soft);
      color: #fff;
    }

    .tbl-btn-act {
      border-color: var(--sage);
      color: var(--sage);
    }

    .tbl-btn-act:hover {
      background: var(--sage);
      color: #fff;
    }

    /* ── SEARCH / TOOLBAR ── */
    .inv-toolbar {
      display: flex;
      gap: 10px;
      align-items: center;
      margin-bottom: 18px;
      flex-wrap: wrap;
    }

    .search-box {
      flex: 1;
      min-width: 200px;
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: 8px;
      padding: 8px 13px;
      transition: border-color .2s;
    }

    .search-box:focus-within {
      border-color: var(--mocha);
    }

    .search-box i {
      color: #bbb;
      font-size: 13px;
    }

    .search-box input {
      border: none;
      background: transparent;
      font-family: var(--font-body);
      font-size: 13px;
      color: var(--charcoal);
      outline: none;
      width: 100%;
    }

    .filter-select {
      padding: 8px 13px;
      border-radius: 8px;
      border: 1.5px solid var(--cream-dark);
      background: var(--cream);
      font-family: var(--font-body);
      font-size: 13px;
      color: var(--charcoal);
      outline: none;
    }

    .btn-primary {
      padding: 9px 17px;
      border-radius: 8px;
      background: var(--mocha);
      color: var(--cream);
      border: none;
      font-family: var(--font-body);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .btn-primary:hover {
      background: var(--mocha-mid);
    }

    .btn-outline {
      padding: 8px 16px;
      border-radius: 8px;
      background: transparent;
      color: var(--mocha-mid);
      border: 1.5px solid var(--cream-dark);
      font-family: var(--font-body);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .btn-outline:hover {
      border-color: var(--mocha);
      color: var(--mocha);
    }

    /* ── CHARTS ── */
    .charts-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 18px;
      margin-bottom: 18px;
    }

    @media (max-width:900px) {
      .charts-grid {
        grid-template-columns: 1fr;
      }
    }

    .chart-card {
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      padding: 20px 22px;
      box-shadow: var(--shadow-sm);
    }

    .chart-title {
      font-family: var(--font-display);
      font-size: 15px;
      color: var(--mocha-deep);
      margin-bottom: 3px;
    }

    .chart-sub {
      font-size: 11px;
      color: #aaa;
      margin-bottom: 14px;
    }

    .period-tabs {
      display: flex;
      gap: 6px;
      margin-bottom: 20px;
    }

    .period-tab {
      padding: 7px 20px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      border: 1.5px solid var(--cream-dark);
      background: var(--cream);
      color: var(--charcoal-mid);
      transition: all .2s;
    }

    .period-tab.active {
      background: var(--mocha);
      color: var(--cream);
      border-color: var(--mocha);
    }

    .report-kpis {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
      gap: 12px;
      margin-bottom: 20px;
    }

    .rpt-mini {
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      padding: 13px 16px;
    }

    .rpt-mini-label {
      font-size: 10px;
      color: #888;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      margin-bottom: 4px;
    }

    .rpt-mini-value {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 700;
      color: var(--mocha-deep);
    }

    .rpt-mini-trend {
      font-size: 11px;
      color: var(--sage);
      margin-top: 3px;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* ── REVENUE TREND BAR CHART ── */
    .bar-chart {
      display: flex;
      align-items: flex-end;
      gap: 14px;
      height: 170px;
      padding-top: 10px;
      border-top: 1px solid var(--cream-dark);
    }

    .bar-col {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-end;
      height: 100%;
      gap: 6px;
      min-width: 0;
    }

    .bar-value {
      font-size: 10px;
      font-weight: 700;
      color: var(--mocha-mid);
      font-family: var(--font-mono);
      white-space: nowrap;
    }

    .bar {
      width: 100%;
      max-width: 36px;
      background: linear-gradient(180deg, var(--gold-light), var(--gold));
      border-radius: 6px 6px 2px 2px;
      transition: height .4s ease;
      min-height: 4px;
    }

    .bar.bar-current {
      background: linear-gradient(180deg, #9ecba2, var(--sage));
    }

    .bar-label {
      font-size: 11px;
      color: #999;
      font-weight: 600;
    }

    /* ── TEXT UTILS ── */
    .text-mono {
      font-family: var(--font-mono);
    }

    .text-gold {
      color: var(--gold);
    }

    .text-sage {
      color: var(--sage);
    }

    .text-muted {
      color: #aaa;
    }

    /* ── RECENT TABLE ── */
    .rec-table {
      width: 100%;
      border-collapse: collapse;
    }

    .rec-table th {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: #888;
      border-bottom: 1.5px solid var(--cream-dark);
      padding: 0 8px 8px;
      text-align: left;
    }

    .rec-table td {
      padding: 8px 8px;
      font-size: 12.5px;
      border-bottom: 1px solid var(--cream-dark);
      color: var(--charcoal);
    }

    .rec-table tr:last-child td {
      border-bottom: none;
    }

    /* ── TOAST ── */
    #toast-container {
      position: fixed;
      bottom: 22px;
      right: 22px;
      z-index: 99999;
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .toast-msg {
      background: var(--charcoal);
      color: var(--cream);
      font-size: 13px;
      font-weight: 500;
      padding: 11px 16px;
      border-radius: 10px;
      box-shadow: var(--shadow-md);
      display: flex;
      align-items: center;
      gap: 8px;
      animation: toastIn .28s ease;
    }

    .toast-msg.success {
      border-left: 3px solid var(--sage);
    }

    .toast-msg.warn {
      border-left: 3px solid #e67e22;
    }

    @keyframes toastIn {
      from {
        opacity: 0;
        transform: translateY(14px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* ── MODAL ── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: rgba(20, 10, 8, .58);
      display: none;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(4px);
    }

    .modal-overlay.show {
      display: flex;
    }

    .modal-box {
      background: var(--cream-light);
      border-radius: var(--radius-lg);
      padding: 28px 30px;
      max-width: 420px;
      width: 92%;
      box-shadow: var(--shadow-lg);
      animation: popIn .25s cubic-bezier(.34, 1.56, .64, 1);
    }

    @keyframes popIn {
      from {
        opacity: 0;
        transform: scale(.88);
      }

      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    .modal-title {
      font-family: var(--font-display);
      font-size: 19px;
      color: var(--mocha-deep);
      margin-bottom: 5px;
    }

    .modal-sub {
      font-size: 13px;
      color: #888;
      margin-bottom: 18px;
    }

    .modal-field {
      margin-bottom: 14px;
    }

    .modal-field label {
      display: block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .6px;
      text-transform: uppercase;
      color: #888;
      margin-bottom: 5px;
    }

    .modal-field input,
    .modal-field select {
      width: 100%;
      padding: 9px 13px;
      border-radius: 8px;
      border: 1.5px solid var(--cream-dark);
      background: var(--cream);
      font-family: var(--font-body);
      font-size: 13px;
      color: var(--charcoal);
      outline: none;
      transition: border-color .2s;
    }

    .modal-field input:focus,
    .modal-field select:focus {
      border-color: var(--mocha);
    }

    .btn-modal-primary {
      width: 100%;
      padding: 12px;
      background: var(--mocha);
      color: var(--cream);
      border: none;
      border-radius: var(--radius);
      font-family: var(--font-body);
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s;
    }

    .btn-modal-primary:hover {
      background: var(--mocha-mid);
    }

    .btn-modal-cancel {
      width: 100%;
      padding: 9px;
      margin-top: 7px;
      background: transparent;
      color: #bbb;
      border: 1.5px solid var(--cream-dark);
      border-radius: 8px;
      font-family: var(--font-body);
      font-size: 13px;
      cursor: pointer;
      transition: all .2s;
    }

    .btn-modal-cancel:hover {
      color: var(--red-soft);
      border-color: var(--red-soft);
    }
  </style>
</head>

<body>

  <header id="app-header">
    <div class="header-brand">
      <div class="brand-logo"><i class="fas fa-mug-hot"></i></div>
      <div class="brand-text">
        <div class="name">SmartStock</div>
        <div class="sub">Bean There Café</div>
      </div>
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
    <a href="dashboard.php" class="nav-item active"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="transactions.php" class="nav-item"><i class="fas fa-receipt"></i> Transactions</a>
    <a href="transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
    <a href="products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products
      <?php if ($criticalStockCount > 0): ?>
        <span class="nav-badge"><?= $criticalStockCount ?></span>
      <?php endif; ?>
    </a>
    <a href="inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
      <?php if ($ingredientAlertCount > 0): ?>
        <span class="nav-badge"><?= $ingredientAlertCount ?></span>
      <?php endif; ?>
    </a>
    <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
    <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
    <hr class="sidebar-divider" />
    <div class="sidebar-section-label">Settings</div>
    <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> System
      Settings</a>
    <a href="backup.php" class="nav-item"><i class="fas fa-database"></i> Data Backup
    </a>
    <div class="sidebar-footer">
      <p>SmartStock v1.0<br />Bean There Café<br />ISO/IEC 25010 Compliant</p>
    </div>
  </nav>

  <div id="main">
    <div class="page-strip">
      <div>
        <h1><i class="fas fa-chart-line" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Dashboard</h1>
        <div class="sub" id="dash-date">Overview — Loading…</div>
      </div>
      <button class="btn-primary" onclick="location.reload()">
        <i class="fas fa-arrows-rotate"></i> Refresh
      </button>
    </div>
    <div style="padding:22px 26px;">

      <div class="kpi-grid">
        <div class="kpi-card" style="--accent:var(--gold);--accent-bg:rgba(201,148,58,.10);">
          <div class="kpi-icon"><i class="fas fa-peso-sign"></i></div>
          <div class="kpi-label">Daily Revenue</div>
          <div class="kpi-value">₱<?= number_format($todayRevenue, 2) ?></div>
          <div class="kpi-sub">From <?= $todayTxnCount ?> completed transaction<?= $todayTxnCount === 1 ? '' : 's' ?> today</div>
        </div>
        <div class="kpi-card" style="--accent:var(--sage);--accent-bg:rgba(122,158,126,.10);">
          <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
          <div class="kpi-label">Total Transactions</div>
          <div class="kpi-value"><?= $todayTxnCount ?></div>
          <div class="kpi-sub">Avg. ₱<?= number_format($avgTxnValue, 2) ?> per transaction</div>
        </div>
        <div class="kpi-card" style="--accent:#e67e22;--accent-bg:rgba(230,126,34,.10);">
          <div class="kpi-icon"><i class="fas fa-triangle-exclamation"></i></div>
          <div class="kpi-label">Critical Stock</div>
          <div class="kpi-value"><?= $ingredientAlertCount ?></div>
          <div class="kpi-sub"><?= $criticalIngredientCount ?> critical, <?= $lowIngredientCount ?> low</div>
          <?php if ($ingredientAlertCount > 0): ?>
            <div class="kpi-trend warn"><i class="fas fa-circle-exclamation"></i> Action required</div>
          <?php else: ?>
            <div class="kpi-trend up"><i class="fas fa-circle-check"></i> Stock levels healthy</div>
          <?php endif; ?>
        </div>
        <div class="kpi-card" style="--accent:#2980b9;--accent-bg:rgba(41,128,185,.10);">
          <div class="kpi-icon"><i class="fas fa-fire"></i></div>
          <div class="kpi-label">Best Seller Today</div>
          <?php if ($bestSeller): ?>
            <div class="kpi-value" style="font-size:18px;line-height:1.2;margin-top:3px;"><?= htmlspecialchars($bestSeller['product_name']) ?></div>
            <div class="kpi-sub"><?= (int)$bestSeller['units'] ?> unit<?= (int)$bestSeller['units'] === 1 ? '' : 's' ?> sold today</div>
          <?php else: ?>
            <div class="kpi-value" style="font-size:18px;line-height:1.2;margin-top:3px;">—</div>
            <div class="kpi-sub">No sales recorded yet today</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="chart-card" style="margin-bottom:18px;">
        <div class="chart-title"><i class="fas fa-chart-column" style="color:var(--gold);margin-right:6px;"></i>Revenue Overview</div>
        <div class="chart-sub">Monthly and yearly totals, with a 6-month trend</div>

        <div class="report-kpis">
          <div class="rpt-mini">
            <div class="rpt-mini-label">This Month</div>
            <div class="rpt-mini-value">₱<?= number_format($monthRevenue, 2) ?></div>
            <?php if ($monthChangePct === null): ?>
              <div class="rpt-mini-trend" style="color:#aaa;"><i class="fas fa-minus"></i> No sales last month</div>
            <?php else: ?>
              <div class="rpt-mini-trend" style="color:<?= $monthChangePct >= 0 ? 'var(--sage)' : 'var(--red-soft)' ?>;">
                <i class="fas <?= $monthChangePct >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' ?>"></i>
                <?= ($monthChangePct >= 0 ? '+' : '') . number_format($monthChangePct, 1) ?>% vs last month
              </div>
            <?php endif; ?>
          </div>
          <div class="rpt-mini">
            <div class="rpt-mini-label">This Year</div>
            <div class="rpt-mini-value">₱<?= number_format($yearRevenue, 2) ?></div>
            <?php if ($yearChangePct === null): ?>
              <div class="rpt-mini-trend" style="color:#aaa;"><i class="fas fa-minus"></i> No sales last year</div>
            <?php else: ?>
              <div class="rpt-mini-trend" style="color:<?= $yearChangePct >= 0 ? 'var(--sage)' : 'var(--red-soft)' ?>;">
                <i class="fas <?= $yearChangePct >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' ?>"></i>
                <?= ($yearChangePct >= 0 ? '+' : '') . number_format($yearChangePct, 1) ?>% vs last year
              </div>
            <?php endif; ?>
          </div>
          <div class="rpt-mini">
            <div class="rpt-mini-label">Avg. Monthly (<?= date('Y') ?>)</div>
            <div class="rpt-mini-value">₱<?= number_format($avgMonthlyThisYear, 2) ?></div>
            <div class="rpt-mini-trend" style="color:#aaa;"><i class="fas fa-calendar-days"></i> Based on <?= $monthsElapsedThisYear ?> month<?= $monthsElapsedThisYear === 1 ? '' : 's' ?></div>
          </div>
        </div>

        <div class="bar-chart">
          <?php foreach ($monthlyTrend as $i => $m): ?>
            <?php $heightPct = max(4, round(($m['total'] / $maxMonthlyTotal) * 100)); ?>
            <div class="bar-col">
              <div class="bar-value">₱<?= number_format($m['total'], 0) ?></div>
              <div class="bar<?= $i === count($monthlyTrend) - 1 ? ' bar-current' : '' ?>" style="height:<?= $heightPct ?>%;"></div>
              <div class="bar-label"><?= htmlspecialchars($m['label']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="two-col">
        <div class="dash-card">
          <div class="dash-card-title"><i class="fas fa-clock-rotate-left"></i>Recent Transactions</div>
          <table class="rec-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Items</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Staff</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentTransactions)): ?>
                <tr>
                  <td colspan="6" style="text-align:center;color:#aaa;padding:16px 8px;">No transactions recorded yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentTransactions as $tx): ?>
                  <?php
                    $statusLabel = $tx['transaction_status'] === 'completed' ? 'Completed' : 'Cancelled';
                    $statusPillClass = $tx['transaction_status'] === 'completed' ? 'pill-success' : 'pill-red';
                    $methodLabel = $tx['payment_method'] === 'online' ? 'Online' : 'Cash';
                  ?>
                  <tr>
                    <td class="text-mono">#<?= (int)$tx['transaction_id'] ?></td>
                    <td><?= htmlspecialchars($tx['items_summary'] ?? '—') ?></td>
                    <td class="text-mono text-gold">₱<?= number_format((float)$tx['transaction_total'], 2) ?></td>
                    <td><?= $methodLabel ?></td>
                    <td><?= htmlspecialchars($tx['username']) ?></td>
                    <td><span class="status-pill <?= $statusPillClass ?>"><?= $statusLabel ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="dash-card">
          <div class="dash-card-title"><i class="fas fa-boxes-stacked"></i>Stock Alerts</div>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <?php if (empty($ingredientAlerts)): ?>
              <div style="padding:14px 12px;text-align:center;color:#aaa;font-size:13px;">All stock levels are healthy.</div>
            <?php else: ?>
              <?php foreach (array_slice($ingredientAlerts, 0, 6) as $item): ?>
                <?php
                  $isCritical = $item['level'] === 'crit';
                  $barColor = $isCritical ? 'var(--red-soft)' : '#e67e22';
                  $barBg = $isCritical ? 'rgba(192,57,43,.05)' : 'rgba(230,126,34,.05)';
                  $badgeBg = $isCritical ? 'rgba(192,57,43,.14)' : 'rgba(230,126,34,.14)';
                ?>
                <div
                  style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:<?= $barBg ?>;border-radius:8px;border-left:3px solid <?= $barColor ?>;">
                  <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($item['name']) ?></div>
                  <span style="font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;background:<?= $badgeBg ?>;color:<?= $barColor ?>;"><?= $isCritical ? 'Critical' : 'Low' ?></span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
            <button class="btn-primary" style="width:100%;justify-content:center;margin-top:4px;"
              onclick="location.href='inventory.php'">
              <i class="fas fa-arrow-right"></i> Manage Inventory
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="toast-container"></div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      updateClock(); setInterval(updateClock, 1000);

      const dashDate = document.getElementById('dash-date');
      if (dashDate) dashDate.textContent = 'Overview — ' + new Date().toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    });

    function updateClock() {
      const clock = document.getElementById('clock');
      if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function openModal(id) { document.getElementById(id).classList.add('show'); }
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