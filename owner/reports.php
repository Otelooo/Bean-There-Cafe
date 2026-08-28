<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

function report_category_tag_class(string $name): string
{
    $n = strtolower($name);
    if (str_contains($n, 'hot')) return 'tag-hot';
    if (str_contains($n, 'iced') || str_contains($n, 'cold')) return 'tag-iced';
    if (str_contains($n, 'pastr') || str_contains($n, 'bread') || str_contains($n, 'bake')) return 'tag-pastry';
    return 'tag-supply';
}

function report_buckets(string $granularity, string $dateFrom, string $dateTill): array
{
    $buckets = [];

    if ($granularity === 'hour') {
        for ($h = 0; $h < 24; $h++) {
            $buckets[str_pad((string)$h, 2, '0', STR_PAD_LEFT)] = date('g A', mktime($h, 0, 0));
        }
        return $buckets;
    }

    $start = new DateTime($dateFrom);
    $end = new DateTime($dateTill);
    if ($end < $start) {
        $end = clone $start;
    }

    if ($granularity === 'month') {
        $cursor = new DateTime($start->format('Y-m-01'));
        $endMonth = new DateTime($end->format('Y-m-01'));
        $guard = 0;
        while ($cursor <= $endMonth && $guard < 120) {
            $buckets[$cursor->format('Y-m')] = $cursor->format('M Y');
            $cursor->modify('+1 month');
            $guard++;
        }
        return $buckets;
    }

    $cursor = clone $start;
    $guard = 0;
    while ($cursor <= $end && $guard < 400) {
        $buckets[$cursor->format('Y-m-d')] = $cursor->format('M j');
        $cursor->modify('+1 day');
        $guard++;
    }
    return $buckets;
}

function report_bucket_key(string $granularity, string $datetime): string
{
    $dt = new DateTime($datetime);
    if ($granularity === 'hour') return $dt->format('H');
    if ($granularity === 'month') return $dt->format('Y-m');
    return $dt->format('Y-m-d');
}

function report_trend_sublabel(string $period, string $dateFrom, string $dateTill): string
{
    if ($period === 'daily') return 'Sales by hour of day';
    if ($period === 'yearly') return 'Sales by month';
    return 'Sales by day (' . date('M j', strtotime($dateFrom)) . ' – ' . date('M j', strtotime($dateTill)) . ')';
}

function build_sales_report(mysqli $conn, string $period, int $categoryId, string $dateFrom, string $dateTill, bool $allDay, int $timeStart, int $timeEnd): array
{
    $granularity = $period === 'daily' ? 'hour' : ($period === 'yearly' ? 'month' : 'day');

    if ($dateTill < $dateFrom) {
        [$dateFrom, $dateTill] = [$dateTill, $dateFrom];
    }

    $timeSql = $allDay ? '' : ' AND HOUR(t.transaction_date) BETWEEN ? AND ?';

    // Transaction-level totals (authoritative revenue/count when no category filter is applied)
    $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(transaction_total),0) AS rev
            FROM transactions t
            WHERE t.transaction_status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?" . $timeSql;
    $stmt = $conn->prepare($sql);
    if ($allDay) {
        $stmt->bind_param('ss', $dateFrom, $dateTill);
    } else {
        $stmt->bind_param('ssii', $dateFrom, $dateTill, $timeStart, $timeEnd);
    }
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $allRevenue = (float)$totals['rev'];
    $allTxnCount = (int)$totals['cnt'];

    // Line-item rows for category breakdown / top products / category-filtered KPIs
    $itemSql = "SELECT t.transaction_id, t.transaction_date, ti.quantity, ti.subtotal,
                       p.product_id, p.product_name, p.product_category_id, pc.product_category
                FROM transaction_items ti
                JOIN transactions t ON t.transaction_id = ti.transaction_id
                JOIN products p ON p.product_id = ti.product_id
                JOIN product_category pc ON pc.product_category_id = p.product_category_id
                WHERE t.transaction_status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?" . $timeSql;
    $stmt = $conn->prepare($itemSql);
    if ($allDay) {
        $stmt->bind_param('ss', $dateFrom, $dateTill);
    } else {
        $stmt->bind_param('ssii', $dateFrom, $dateTill, $timeStart, $timeEnd);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    // Category breakdown always reflects ALL categories in the current window, regardless of the dropdown
    $categoryTotals = [];
    foreach ($items as $row) {
        $cid = (int)$row['product_category_id'];
        if (!isset($categoryTotals[$cid])) {
            $categoryTotals[$cid] = ['name' => $row['product_category'], 'revenue' => 0.0];
        }
        $categoryTotals[$cid]['revenue'] += (float)$row['subtotal'];
    }
    uasort($categoryTotals, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $itemRevenueTotal = array_sum(array_column($items, 'subtotal'));

    $filteredItems = $categoryId > 0
        ? array_values(array_filter($items, fn($row) => (int)$row['product_category_id'] === $categoryId))
        : $items;

    // Revenue/transaction trend
    $buckets = report_buckets($granularity, $dateFrom, $dateTill);
    $trendRevenue = array_fill_keys(array_keys($buckets), 0.0);
    $trendTxnSets = array_fill_keys(array_keys($buckets), []);

    if ($categoryId > 0) {
        foreach ($filteredItems as $row) {
            $key = report_bucket_key($granularity, $row['transaction_date']);
            if (!array_key_exists($key, $trendRevenue)) continue;
            $trendRevenue[$key] += (float)$row['subtotal'];
            $trendTxnSets[$key][$row['transaction_id']] = true;
        }
    } else {
        $sqlTrend = "SELECT transaction_id, transaction_date, transaction_total
                     FROM transactions t
                     WHERE transaction_status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?" . $timeSql;
        $stmt = $conn->prepare($sqlTrend);
        if ($allDay) {
            $stmt->bind_param('ss', $dateFrom, $dateTill);
        } else {
            $stmt->bind_param('ssii', $dateFrom, $dateTill, $timeStart, $timeEnd);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $key = report_bucket_key($granularity, $row['transaction_date']);
            if (!array_key_exists($key, $trendRevenue)) continue;
            $trendRevenue[$key] += (float)$row['transaction_total'];
            $trendTxnSets[$key][$row['transaction_id']] = true;
        }
        $stmt->close();
    }

    $trendLabels = array_values($buckets);
    $trendRevenueOut = [];
    $trendTxnOut = [];
    foreach (array_keys($buckets) as $key) {
        $trendRevenueOut[] = round($trendRevenue[$key], 2);
        $trendTxnOut[] = count($trendTxnSets[$key]);
    }

    // KPIs
    if ($categoryId > 0) {
        $periodRevenue = array_sum(array_column($filteredItems, 'subtotal'));
        $txnIds = [];
        foreach ($filteredItems as $row) {
            $txnIds[$row['transaction_id']] = true;
        }
        $periodTxnCount = count($txnIds);
    } else {
        $periodRevenue = $allRevenue;
        $periodTxnCount = $allTxnCount;
    }

    $daysInRange = (new DateTime($dateFrom))->diff(new DateTime($dateTill))->days + 1;

    if ($period === 'daily') {
        $avgLabel = 'Avg. Order Value';
        $avgValue = $periodTxnCount > 0 ? $periodRevenue / $periodTxnCount : 0;
        $avgSub = 'Per transaction';
    } else {
        $avgLabel = 'Avg. Daily';
        $avgValue = $daysInRange > 0 ? $periodRevenue / $daysInRange : 0;
        $avgSub = (!$allDay) ? 'For selected hours' : 'Per operating day';
    }

    if ($categoryId > 0) {
        $topLabel = 'Top Product';
        $productTotals = [];
        foreach ($filteredItems as $row) {
            $pid = (int)$row['product_id'];
            if (!isset($productTotals[$pid])) {
                $productTotals[$pid] = ['name' => $row['product_name'], 'revenue' => 0.0];
            }
            $productTotals[$pid]['revenue'] += (float)$row['subtotal'];
        }
        uasort($productTotals, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $top = reset($productTotals);
        $topValue = $top ? $top['name'] : '—';
        $topSub = $top ? 'Best seller' : 'No sales yet';
    } else {
        $topLabel = 'Top Category';
        $top = reset($categoryTotals);
        $topValue = $top ? $top['name'] : '—';
        $topSub = ($top && $itemRevenueTotal > 0) ? round(($top['revenue'] / $itemRevenueTotal) * 100) . '% of sales' : 'No sales yet';
    }

    $catLabels = [];
    $catData = [];
    foreach ($categoryTotals as $c) {
        $catLabels[] = $c['name'];
        $catData[] = round($c['revenue'], 2);
    }

    $productAgg = [];
    foreach ($filteredItems as $row) {
        $pid = (int)$row['product_id'];
        if (!isset($productAgg[$pid])) {
            $productAgg[$pid] = [
                'name' => $row['product_name'],
                'category_name' => $row['product_category'],
                'units' => 0,
                'revenue' => 0.0,
            ];
        }
        $productAgg[$pid]['units'] += (int)$row['quantity'];
        $productAgg[$pid]['revenue'] += (float)$row['subtotal'];
    }
    uasort($productAgg, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $totalProductRevenue = array_sum(array_column($productAgg, 'revenue'));
    $topProducts = [];
    $rank = 1;
    foreach (array_slice($productAgg, 0, 5, true) as $p) {
        $topProducts[] = [
            'rank' => $rank++,
            'name' => $p['name'],
            'category_name' => $p['category_name'],
            'tag_class' => report_category_tag_class($p['category_name']),
            'units' => $p['units'],
            'revenue' => round($p['revenue'], 2),
            'share' => $totalProductRevenue > 0 ? round(($p['revenue'] / $totalProductRevenue) * 100) : 0,
        ];
    }

    return [
        'kpis' => [
            'revenue' => round($periodRevenue, 2),
            'transactions' => $periodTxnCount,
            'avgLabel' => $avgLabel,
            'avgValue' => round($avgValue, 2),
            'avgSub' => $avgSub,
            'topLabel' => $topLabel,
            'topValue' => $topValue,
            'topSub' => $topSub,
        ],
        'trend' => [
            'sublabel' => report_trend_sublabel($period, $dateFrom, $dateTill),
            'labels' => $trendLabels,
            'revenue' => $trendRevenueOut,
            'txn' => $trendTxnOut,
        ],
        'categoryBreakdown' => [
            'labels' => $catLabels,
            'data' => $catData,
        ],
        'topProducts' => $topProducts,
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

$displayName = $_SESSION['username'] ?? 'Owner';
$initials = strtoupper(substr($displayName, 0, 2));

$categories = [];
$catResult = $conn->query('SELECT product_category_id, product_category FROM product_category ORDER BY product_category');
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
}

if (isset($_GET['action']) && $_GET['action'] === 'report') {
    header('Content-Type: application/json');

    $period = in_array($_GET['period'] ?? '', ['daily', 'weekly', 'monthly', 'yearly'], true) ? $_GET['period'] : 'daily';
    $categoryId = (int)($_GET['category'] ?? 0);
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTill = $_GET['date_till'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTill)) $dateTill = date('Y-m-d');
    $allDay = ($_GET['all_day'] ?? '1') !== '0';
    $timeStart = max(0, min(23, (int)($_GET['time_start'] ?? 0)));
    $timeEnd = max(0, min(23, (int)($_GET['time_end'] ?? 23)));

    echo json_encode(build_sales_report($conn, $period, $categoryId, $dateFrom, $dateTill, $allDay, $timeStart, $timeEnd));
    exit;
}

$todayStr = date('Y-m-d');
$initialReport = build_sales_report($conn, 'daily', 0, $todayStr, $todayStr, true, 0, 23);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sales Reports | SmartStock</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
    rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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
      grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
      gap: 15px;
      margin-bottom: 24px;
    }

    .kpi-card {
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      padding: 20px 20px 18px;
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
      width: 38px;
      height: 38px;
      border-radius: 9px;
      background: var(--accent-bg, rgba(201, 148, 58, .1));
      color: var(--accent, var(--gold));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      margin-bottom: 13px;
    }

    .kpi-label {
      font-size: 10.5px;
      color: #888;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      margin-bottom: 5px;
    }

    .kpi-value {
      font-family: var(--font-display);
      font-size: 28px;
      font-weight: 700;
      color: var(--mocha-deep);
      line-height: 1;
    }

    .kpi-sub {
      font-size: 11px;
      color: #aaa;
      margin-top: 4px;
    }

    .kpi-trend {
      font-size: 11px;
      font-weight: 600;
      margin-top: 4px;
    }

    .kpi-trend.up {
      color: var(--sage);
    }

    .kpi-trend.warn {
      color: #e67e22;
    }

    /* ── DASH CARDS ── */
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

    /* ── SEARCH / TOOLBAR ── */
    .filter-select {
      padding: 8px 13px;
      border-radius: 8px;
      border: 1.5px solid var(--cream-dark);
      background: var(--cream);
      font-family: var(--font-body);
      font-size: 13px;
      color: var(--charcoal);
      outline: none;
      cursor: pointer;
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
      margin-bottom: 0;
    }

    .period-tab {
      padding: 7px 20px;
      height: 38px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
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
      transition: all 0.3s ease;
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
    }

    /* ── TEXT UTILS ── */
    .text-mono {
      font-family: var(--font-mono);
    }

    .text-gold {
      color: var(--gold);
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
      transition: all 0.3s ease;
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

    /* CUSTOM TIME FILTER INPUT STYLING */
    .time-filter-wrapper {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: 8px;
      padding: 6px 12px;
      height: 38px;
    }

    .time-filter-wrapper input[type="time"] {
      border: none;
      background: transparent;
      font-family: var(--font-mono);
      font-size: 13px;
      font-weight: 600;
      color: var(--mocha-deep);
      outline: none;
      cursor: pointer;
    }

    .all-day-label {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      font-weight: 600;
      color: var(--mocha-deep);
      cursor: pointer;
      user-select: none;
    }

    #print-report-header {
      display: none;
    }

    #print-report-header h2 {
      font-family: var(--font-display);
      font-size: 20px;
      color: var(--mocha-deep);
      margin-bottom: 2px;
    }

    #print-report-header .print-context {
      font-size: 12px;
      color: #666;
      margin-bottom: 18px;
    }

    @media print {
      #app-header, #sidebar, #report-toolbar, .page-strip .btn-outline, .page-strip .btn-primary, #toast-container {
        display: none !important;
      }
      #main {
        margin: 0 !important;
      }
      #print-report-header {
        display: block !important;
      }
      body {
        background: #fff;
      }
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
    <a href="dashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="transactions.php" class="nav-item"><i class="fas fa-receipt"></i> Transactions</a>
    <a href="transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
    <a href="products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
    <a href="inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
      <?php if ($ingredientAlertCount > 0): ?>
        <span class="nav-badge"><?= $ingredientAlertCount ?></span>
      <?php endif; ?>
    </a>
    <a href="reports.php" class="nav-item active"><i class="fas fa-chart-bar"></i> Sales Report</a>
    <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
    <hr class="sidebar-divider" />
    <div class="sidebar-section-label">Settings</div>
    <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> System
      Settings</a>
    <a href="backup.php" class="nav-item"><i class="fas fa-database"></i> Data Backup
    </a>
  </nav>

  <div id="main">
    <div class="page-strip">
      <div>
        <h1><i class="fas fa-chart-bar" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Sales Reports
        </h1>
        <div class="sub">Analyze performance trends across time periods</div>
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn-outline" onclick="printReport()"><i class="fas fa-print"></i>
          Print</button>
        <button class="btn-primary" id="export-pdf-btn" onclick="exportReportPdf()"><i class="fas fa-file-pdf"></i>
          Export PDF</button>
      </div>
    </div>

    <div style="padding:22px 26px;">

      <div id="report-toolbar"
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:nowrap; overflow-x:auto; padding-bottom:4px; gap:12px;">

        <div class="period-tabs" id="period-tabs" style="flex-shrink:0;">
          <button class="period-tab active" onclick="switchPeriod('daily',this)">Daily</button>
          <button class="period-tab" onclick="switchPeriod('weekly',this)">Weekly</button>
          <button class="period-tab" onclick="switchPeriod('monthly',this)">Monthly</button>
          <button class="period-tab" onclick="switchPeriod('yearly',this)">Yearly</button>
        </div>

        <div style="display:flex; gap:12px; align-items:center; flex-shrink:0;">

          <div class="time-filter-wrapper" title="Filter by hours of the day">
            <label class="all-day-label">
              <input type="checkbox" id="all-day-cb" checked onchange="toggleAllDay()"> All Day
            </label>

            <div id="time-inputs"
              style="display:flex; visibility:hidden; pointer-events:none; align-items:center; gap:8px; padding-left:10px; border-left:1.5px solid var(--cream-dark); margin-left:4px;">
              <i class="fas fa-clock" style="color:#aaa; font-size:13px;"></i>
              <input type="time" id="time-start" value="07:00" onchange="updateReportDisplay()">
              <span style="font-size:12px; color:#aaa; font-weight:600;">to</span>
              <input type="time" id="time-end" value="19:00" onchange="updateReportDisplay()">
            </div>
          </div>

          <div class="time-filter-wrapper" title="Filter by date range" style="padding:6px 12px;">
            <i class="fas fa-calendar-alt" style="color:#aaa; font-size:13px; margin-right:4px;"></i>
            <input type="date" id="date-from" value="<?= htmlspecialchars($todayStr) ?>" onchange="updateReportDisplay()"
              style="border:none; background:transparent; font-family:var(--font-mono); font-size:13px; font-weight:600; color:var(--charcoal); outline:none; width:110px;">
            <span style="font-size:12px; color:#aaa; font-weight:600; margin:0 8px;">to</span>
            <input type="date" id="date-till" value="<?= htmlspecialchars($todayStr) ?>" onchange="updateReportDisplay()"
              style="border:none; background:transparent; font-family:var(--font-mono); font-size:13px; font-weight:600; color:var(--charcoal); outline:none; width:110px;">
          </div>

          <select class="filter-select" id="report-category" onchange="switchCategory(this.value)"
            style="border-color:var(--mocha); font-weight:600; height: 38px;">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>


      </div>

      <div id="report-print-area">
      <div id="print-report-header">
        <h2>Bean There Café — Sales Report</h2>
        <div class="print-context" id="print-report-context"></div>
      </div>

      <div class="report-kpis">
        <div class="rpt-mini">
          <div class="rpt-mini-label">Period Revenue</div>
          <div class="rpt-mini-value" id="rpt-rev">₱0.00</div>
        </div>
        <div class="rpt-mini">
          <div class="rpt-mini-label">Transactions</div>
          <div class="rpt-mini-value" id="rpt-txn">0</div>
        </div>

        <div class="rpt-mini">
          <div class="rpt-mini-label" id="rpt-avg-title">Avg. Order Value</div>
          <div class="rpt-mini-value" id="rpt-avg">₱0.00</div>
          <div class="rpt-mini-trend" style="color:#aaa;" id="rpt-avg-lbl">Per transaction</div>
        </div>

        <div class="rpt-mini">
          <div class="rpt-mini-label" id="rpt-top-lbl">Top Category</div>
          <div class="rpt-mini-value" id="rpt-top-val" style="font-size:16px;">—</div>
          <div class="rpt-mini-trend" id="rpt-top-sub" style="color:#aaa;">No sales yet</div>
        </div>
      </div>

      <div class="charts-grid">
        <div class="chart-card">
          <div class="chart-title">Revenue Trend</div>
          <div class="chart-sub" id="chart-sublabel">Today's sales breakdown by hour</div>
          <canvas id="salesChart" height="230"></canvas>
        </div>
        <div class="chart-card">
          <div class="chart-title">Sales by Category</div>
          <div class="chart-sub">Proportion per product category</div>
          <canvas id="catChart" height="230"></canvas>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-title"><i class="fas fa-trophy"></i>Top Selling Products</div>
        <table class="rec-table">
          <thead>
            <tr>
              <th>Rank</th>
              <th>Product</th>
              <th>Category</th>
              <th>Units Sold</th>
              <th>Revenue</th>
              <th>Share</th>
            </tr>
          </thead>
          <tbody id="top-selling-tbody"></tbody>
        </table>
      </div>
      </div>
    </div>
  </div>

  <div id="toast-container"></div>
  <script id="report-data" type="application/json"><?= json_encode($initialReport, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

  <script>
    let salesChart = null, catChart = null, currentPeriod = 'daily', currentCategory = '0';

    document.addEventListener('DOMContentLoaded', () => {
      updateClock(); setInterval(updateClock, 1000);
      renderReport(JSON.parse(document.getElementById('report-data').textContent));
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
      currentPeriod = p;
      document.querySelectorAll('.period-tab').forEach(t => t.classList.remove('active'));
      el.classList.add('active');
      applyPeriodDefaultRange(p);
      fetchReport();
    }

    function switchCategory(cat) {
      currentCategory = cat;
      fetchReport();
    }

    // Shows or hides the specific time inputs based on the All Day checkbox
    function toggleAllDay() {
      const isAllDay = document.getElementById('all-day-cb').checked;
      const timeInputs = document.getElementById('time-inputs');
      timeInputs.style.visibility = isAllDay ? 'hidden' : 'visible';
      timeInputs.style.pointerEvents = isAllDay ? 'none' : 'auto';
      fetchReport();
    }

    function updateReportDisplay() {
      fetchReport();
    }

    async function fetchReport() {
      const params = new URLSearchParams({
        action: 'report',
        period: currentPeriod,
        category: currentCategory,
        date_from: document.getElementById('date-from').value,
        date_till: document.getElementById('date-till').value,
        all_day: document.getElementById('all-day-cb').checked ? '1' : '0',
        time_start: (document.getElementById('time-start').value || '00:00').split(':')[0],
        time_end: (document.getElementById('time-end').value || '23:59').split(':')[0]
      });

      try {
        const res = await fetch('reports.php?' + params.toString());
        renderReport(await res.json());
      } catch (err) {
        showToast('Could not load report data.', 'warn');
      }
    }

    function money(n) {
      return '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderReport(data) {
      document.getElementById('chart-sublabel').textContent = data.trend.sublabel;

      document.getElementById('rpt-rev').textContent = money(data.kpis.revenue);
      document.getElementById('rpt-txn').textContent = data.kpis.transactions.toLocaleString();

      document.getElementById('rpt-avg-title').textContent = data.kpis.avgLabel;
      document.getElementById('rpt-avg').textContent = money(data.kpis.avgValue);
      document.getElementById('rpt-avg-lbl').textContent = data.kpis.avgSub;

      document.getElementById('rpt-top-lbl').textContent = data.kpis.topLabel;
      document.getElementById('rpt-top-val').textContent = data.kpis.topValue;
      document.getElementById('rpt-top-sub').textContent = data.kpis.topSub;

      renderTopProducts(data.topProducts);
      renderCharts(data);
    }

    function renderTopProducts(products) {
      const tbody = document.getElementById('top-selling-tbody');
      if (!products || products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px 8px;">No sales recorded for this period yet.</td></tr>';
        return;
      }
      const medals = { 1: '🥇', 2: '🥈', 3: '🥉' };
      tbody.innerHTML = products.map(p => `
        <tr>
          <td>${medals[p.rank] || p.rank}</td>
          <td style="font-weight:600;">${p.name}</td>
          <td><span class="tag ${p.tag_class}">${p.category_name}</span></td>
          <td class="text-mono">${p.units.toLocaleString()}</td>
          <td class="text-mono text-gold">${money(p.revenue)}</td>
          <td>
            <div style="display:flex;align-items:center;gap:7px;">
              <div style="width:70px;height:6px;background:var(--cream-dark);border-radius:3px;overflow:hidden;">
                <div style="width:${p.share}%;height:100%;background:var(--gold);border-radius:3px;"></div>
              </div><span style="font-size:12px;">${p.share}%</span>
            </div>
          </td>
        </tr>
      `).join('');
    }

    function renderCharts(data) {
      const sc = document.getElementById('salesChart');
      const cc = document.getElementById('catChart');
      if (!sc || !cc) return;
      if (salesChart) salesChart.destroy();
      if (catChart) catChart.destroy();

      salesChart = new Chart(sc, {
        type: 'bar',
        data: {
          labels: data.trend.labels,
          datasets: [
            { label: 'Revenue (₱)', data: data.trend.revenue, backgroundColor: '#C9943Acc', borderColor: '#C9943A', borderWidth: 2, borderRadius: 6, yAxisID: 'y' },
            { label: 'Transactions', data: data.trend.txn, type: 'line', borderColor: '#4A2C2A', backgroundColor: '#4A2C2A22', tension: .4, pointBackgroundColor: '#4A2C2A', pointRadius: 4, yAxisID: 'y1' }
          ]
        },
        options: {
          responsive: true, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { labels: { font: { family: "'DM Sans',sans-serif", size: 11 }, color: '#666' } } },
          scales: {
            y: { grid: { color: 'rgba(0,0,0,.05)' }, ticks: { color: '#999', font: { size: 10 }, callback: v => '₱' + v.toLocaleString() } },
            y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#999', font: { size: 10 }, precision: 0 } },
            x: { grid: { display: false }, ticks: { color: '#999', font: { size: 11 } } }
          }
        }
      });

      const hasCatData = data.categoryBreakdown.labels.length > 0;
      catChart = new Chart(cc, {
        type: 'doughnut',
        data: {
          labels: hasCatData ? data.categoryBreakdown.labels : ['No sales yet'],
          datasets: [{
            data: hasCatData ? data.categoryBreakdown.data : [1],
            backgroundColor: hasCatData ? ['#4A2C2A', '#C9943A', '#7A9E7E', '#2980b9', '#d68910', '#c0392b'] : ['#e0e0e0'],
            borderWidth: 0, hoverOffset: hasCatData ? 8 : 0
          }]
        },
        options: { responsive: true, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { font: { family: "'DM Sans',sans-serif", size: 12 }, color: '#666', padding: 16 } } } }
      });
    }

    function reportContextLabel() {
      const periodLabel = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly', yearly: 'Yearly' }[currentPeriod] || currentPeriod;
      const catSelect = document.getElementById('report-category');
      const catLabel = catSelect.options[catSelect.selectedIndex].textContent;
      const from = document.getElementById('date-from').value;
      const till = document.getElementById('date-till').value;
      return `${periodLabel} report · ${catLabel} · ${from} to ${till} · Generated ${new Date().toLocaleString('en-PH')}`;
    }

    function printReport() {
      document.getElementById('print-report-context').textContent = reportContextLabel();
      window.print();
    }

    async function exportReportPdf() {
      const btn = document.getElementById('export-pdf-btn');
      const originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';

      const header = document.getElementById('print-report-header');
      document.getElementById('print-report-context').textContent = reportContextLabel();
      header.style.display = 'block';

      try {
        const target = document.getElementById('report-print-area');
        const canvas = await html2canvas(target, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
        const imgData = canvas.toDataURL('image/png');

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const margin = 24;
        const imgWidth = pageWidth - margin * 2;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        const pageContentHeight = pageHeight - margin * 2;

        let position = margin;
        let remainingHeight = imgHeight;
        let shown = 0;
        pdf.addImage(imgData, 'PNG', margin, position, imgWidth, imgHeight);
        remainingHeight -= pageContentHeight;
        shown += pageContentHeight;

        while (remainingHeight > 0) {
          pdf.addPage();
          position = margin - shown;
          pdf.addImage(imgData, 'PNG', margin, position, imgWidth, imgHeight);
          remainingHeight -= pageContentHeight;
          shown += pageContentHeight;
        }

        const from = document.getElementById('date-from').value;
        const till = document.getElementById('date-till').value;
        pdf.save(`sales-report-${from}-to-${till}.pdf`);
        showToast('PDF exported.', 'success');
      } catch (err) {
        showToast('Could not generate the PDF. Please try again.', 'warn');
      } finally {
        header.style.display = 'none';
        btn.disabled = false;
        btn.innerHTML = originalHtml;
      }
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