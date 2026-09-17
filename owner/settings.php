<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

// Preset options for the "Account Recovery" security-question picker below — the owner can also
// type a fully custom question via the <select>'s "Write your own…" option.
const SECURITY_QUESTION_PRESETS = [
    "What was the name of your first pet?",
    "What is your mother's maiden name?",
    "What city were you born in?",
    "What was the name of your first school?",
    "What was your childhood nickname?",
    "What street did you grow up on?",
    "What was the name of your first café or business?",
];

// Answers are compared case/whitespace-insensitively — the owner shouldn't get locked out over
// "Manila" vs "manila " months later.
function normalize_security_answer(string $answer): string
{
    return mb_strtolower(trim($answer));
}

// Separate action from the main café-settings save below — this updates the logged-in owner's own
// users row, not the shared system_settings table, so it gets its own form and its own handler.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_security_questions') {
    $ownerId = (int)$_SESSION['user_id'];
    $q1 = trim($_POST['security_question_1'] ?? '');
    $a1 = trim($_POST['security_answer_1'] ?? '');
    $q2 = trim($_POST['security_question_2'] ?? '');
    $a2 = trim($_POST['security_answer_2'] ?? '');

    $msg = '';
    $msgType = 'success';

    if ($q1 === '' || $a1 === '' || $q2 === '' || $a2 === '') {
        $msg = 'Please fill in both security questions and their answers.';
        $msgType = 'warn';
    } elseif ($q1 === $q2) {
        $msg = 'Please choose two different security questions.';
        $msgType = 'warn';
    } else {
        $hash1 = password_hash(normalize_security_answer($a1), PASSWORD_DEFAULT);
        $hash2 = password_hash(normalize_security_answer($a2), PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET security_question_1 = ?, security_answer_1_hash = ?, security_question_2 = ?, security_answer_2_hash = ? WHERE user_id = ?');
        $stmt->bind_param('ssssi', $q1, $hash1, $q2, $hash2, $ownerId);
        $stmt->execute();
        $stmt->close();
        $msg = 'Account recovery questions saved.';
    }

    header('Location: settings.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cafeName = trim($_POST['cafe_name'] ?? '');
    $cafeAddress = trim($_POST['cafe_address'] ?? '');
    $cafeContact = trim($_POST['cafe_contact'] ?? '');
    $taxRatePercent = $_POST['tax_rate_percent'] ?? '';
    $discountRatePercent = $_POST['discount_rate_percent'] ?? '';
    $criticalThreshold = $_POST['critical_stock_threshold'] ?? '';
    $lowThreshold = $_POST['low_stock_threshold'] ?? '';
    $receiptFooter = trim($_POST['receipt_footer_message'] ?? '');

    $msg = '';
    $msgType = 'success';

    if ($cafeName === '') {
        $msg = 'Café name is required.';
        $msgType = 'warn';
    } elseif (!is_numeric($taxRatePercent) || (float)$taxRatePercent < 0 || (float)$taxRatePercent > 100) {
        $msg = 'Tax rate must be a number between 0 and 100.';
        $msgType = 'warn';
    } elseif (!is_numeric($discountRatePercent) || (float)$discountRatePercent < 0 || (float)$discountRatePercent > 100) {
        $msg = 'Discount rate must be a number between 0 and 100.';
        $msgType = 'warn';
    } elseif (!is_numeric($criticalThreshold) || (float)$criticalThreshold < 0 || (float)$criticalThreshold > 100) {
        $msg = 'Critical stock threshold must be a percentage between 0 and 100.';
        $msgType = 'warn';
    } elseif (!is_numeric($lowThreshold) || (float)$lowThreshold < 0 || (float)$lowThreshold > 100) {
        $msg = 'Low stock threshold must be a percentage between 0 and 100.';
        $msgType = 'warn';
    } elseif ((float)$lowThreshold < (float)$criticalThreshold) {
        $msg = 'Low stock threshold must be greater than or equal to the critical threshold.';
        $msgType = 'warn';
    } else {
        try {
            $updates = [
                'cafe_name' => $cafeName,
                'cafe_address' => $cafeAddress,
                'cafe_contact' => $cafeContact,
                'tax_rate' => number_format((float)$taxRatePercent / 100, 4, '.', ''),
                'discount_rate' => number_format((float)$discountRatePercent / 100, 4, '.', ''),
                'critical_stock_threshold' => (string)(float)$criticalThreshold,
                'low_stock_threshold' => (string)(float)$lowThreshold,
                'receipt_footer_message' => $receiptFooter,
            ];

            $stmt = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($updates as $key => $value) {
                $stmt->bind_param('ss', $key, $value);
                $stmt->execute();
            }
            $stmt->close();
            $msg = 'Settings saved.';
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $msgType = 'warn';
        }
    }

    header('Location: settings.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
    exit;
}

$msg = $_GET['msg'] ?? '';
$msgType = $_GET['type'] ?? 'success';

$displayName = $_SESSION['username'] ?? 'Owner';
$initials = strtoupper(substr($displayName, 0, 2));

$settings = get_system_settings($conn);
$taxRatePercentDisplay = rtrim(rtrim(number_format((float)$settings['tax_rate'] * 100, 2, '.', ''), '0'), '.');
$discountRatePercentDisplay = rtrim(rtrim(number_format((float)$settings['discount_rate'] * 100, 2, '.', ''), '0'), '.');

$ownerId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT security_question_1, security_question_2 FROM users WHERE user_id = ?');
$stmt->bind_param('i', $ownerId);
$stmt->execute();
$ownerSecurityRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$hasSecurityQuestions = !empty($ownerSecurityRow['security_question_1']) && !empty($ownerSecurityRow['security_question_2']);

// Feeds the "Inventory" nav-badge — ingredients at critical OR low stock (not products).
$lowThresholdFloat = (float)$settings['low_stock_threshold'];
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE (ingredient_stock_reference IS NULL AND ingredient_stock <= 0) OR (ingredient_stock_reference > 0 AND (ingredient_stock / ingredient_stock_reference) * 100 <= ?)");
$stmt->bind_param('d', $lowThresholdFloat);
$stmt->execute();
$ingredientAlertCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Settings | SmartStock — Bean There Café</title>
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

    .btn-primary { padding:9px 17px; border-radius:8px; background:var(--mocha); color:var(--cream); border:none; font-family:var(--font-body); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:6px; }
    .btn-primary:hover { background:var(--mocha-mid); }

    /* ── SETTINGS CARDS ── */
    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 960px) { .settings-grid { grid-template-columns: 1fr; } }
    .settings-card {
      background: var(--cream); border: 1.5px solid var(--cream-dark); border-radius: var(--radius-lg);
      padding: 22px 24px; box-shadow: var(--shadow-sm); margin-bottom: 20px;
    }
    .settings-card-title {
      font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep);
      margin-bottom: 4px; display: flex; align-items: center; gap: 8px;
    }
    .settings-card-title i { color: var(--gold); font-size: 14px; }
    .settings-card-sub { font-size: 12px; color: #888; margin-bottom: 18px; }
    .settings-field { margin-bottom: 16px; }
    .settings-field:last-child { margin-bottom: 0; }
    .settings-field label { display:block; font-size:11px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:#888; margin-bottom:6px; }
    .settings-field input, .settings-field textarea, .settings-field select {
      width:100%; padding:11px 14px; border-radius:8px; border:1.5px solid var(--cream-dark);
      background:var(--cream-light); font-family:var(--font-body); font-size:13.5px; color:var(--charcoal);
      outline:none; transition:border-color .2s;
    }
    .settings-field input:focus, .settings-field textarea:focus, .settings-field select:focus { border-color: var(--mocha); }
    .security-question-custom { display: none; margin-top: 8px; }
    .settings-field .hint { font-size: 11px; color: #999; margin-top: 5px; }
    .settings-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    /* ── TOAST ── */
    #toast-container { position:fixed; bottom:22px; right:22px; z-index:99999; display:flex; flex-direction:column; gap:7px; }
    .toast-msg { background:var(--charcoal); color:var(--cream); font-size:13px; font-weight:500; padding:11px 16px; border-radius:10px; box-shadow:var(--shadow-md); display:flex; align-items:center; gap:8px; animation:toastIn .28s ease; }
    .toast-msg.success { border-left:3px solid var(--sage); }
    .toast-msg.warn    { border-left:3px solid #e67e22; }
    @keyframes toastIn { from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);} }
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
  <a href="order_queue.php" class="nav-item"><i class="fas fa-list-check"></i> Order Queue</a>
  <a href="transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
  <a href="inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
  <hr class="sidebar-divider"/>
  <div class="sidebar-section-label">Settings</div>
  <a href="settings.php" class="nav-item active"><i class="fas fa-gear"></i> System Settings</a>
  <a href="backup.php" class="nav-item"><i class="fas fa-database"></i> Log</a>
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
      <h1><i class="fas fa-gear" style="color:var(--gold);font-size:22px;margin-right:10px;"></i>System Settings</h1>
      <div class="sub">Configure café identity, POS rates, and inventory alert thresholds</div>
    </div>
  </div>

  <form method="POST" action="settings.php">
    <div style="padding:22px 26px;">
      <div class="settings-grid">
        <div class="settings-card">
          <div class="settings-card-title"><i class="fas fa-store"></i>Café Identity</div>
          <div class="settings-card-sub">Shown on printed receipts and used across the system.</div>
          <div class="settings-field">
            <label>Café Name</label>
            <input type="text" name="cafe_name" value="<?= htmlspecialchars($settings['cafe_name']) ?>" required>
          </div>
          <div class="settings-field">
            <label>Address</label>
            <input type="text" name="cafe_address" value="<?= htmlspecialchars($settings['cafe_address']) ?>" placeholder="e.g. 123 Session Road, Baguio City">
          </div>
          <div class="settings-field">
            <label>Contact Number</label>
            <input type="text" name="cafe_contact" value="<?= htmlspecialchars($settings['cafe_contact']) ?>" placeholder="e.g. 0917-000-0000">
          </div>
        </div>

        <div class="settings-card">
          <div class="settings-card-title"><i class="fas fa-cash-register"></i>Point of Sale</div>
          <div class="settings-card-sub">Applied to every transaction at checkout.</div>
          <div class="settings-field-row">
            <div class="settings-field">
              <label>Tax Rate (%)</label>
              <input type="number" name="tax_rate_percent" value="<?= htmlspecialchars($taxRatePercentDisplay) ?>" min="0" max="100" step="0.01" required>
              <div class="hint">VAT shown in the price breakdown at checkout.</div>
            </div>
            <div class="settings-field">
              <label>PWD / Senior Discount (%)</label>
              <input type="number" name="discount_rate_percent" value="<?= htmlspecialchars($discountRatePercentDisplay) ?>" min="0" max="100" step="0.01" required>
              <div class="hint">Applied when a discount is selected at checkout.</div>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <div class="settings-card-title"><i class="fas fa-triangle-exclamation"></i>Inventory Alerts</div>
          <div class="settings-card-sub">Controls the OK / Low / Critical stock labels and dashboard alerts. Both are a percentage of the amount last set/restocked for each item, not a raw unit count.</div>
          <div class="settings-field-row">
            <div class="settings-field">
              <label>Critical Stock Threshold (%)</label>
              <input type="number" name="critical_stock_threshold" value="<?= htmlspecialchars($settings['critical_stock_threshold']) ?>" min="0" max="100" step="0.1" required>
              <div class="hint">Stock at or below this % of the last restocked amount is "Critical".</div>
            </div>
            <div class="settings-field">
              <label>Low Stock Threshold (%)</label>
              <input type="number" name="low_stock_threshold" value="<?= htmlspecialchars($settings['low_stock_threshold']) ?>" min="0" max="100" step="0.1" required>
              <div class="hint">Stock at or below this % (but above critical) is "Low".</div>
            </div>
          </div>
        </div>

        <div class="settings-card">
          <div class="settings-card-title"><i class="fas fa-receipt"></i>Receipt</div>
          <div class="settings-card-sub">Printed at the bottom of every sales receipt.</div>
          <div class="settings-field">
            <label>Footer Message</label>
            <input type="text" name="receipt_footer_message" value="<?= htmlspecialchars($settings['receipt_footer_message']) ?>" placeholder="e.g. Thank you for bean here!">
          </div>
        </div>
      </div>

      <button type="submit" class="btn-primary"><i class="fas fa-check"></i> Save Settings</button>
    </div>
  </form>

  <form method="POST" action="settings.php" style="padding:0 26px 22px;" onsubmit="return validateSecurityQuestionsForm(this);">
    <input type="hidden" name="action" value="save_security_questions">
    <div class="settings-grid">
      <div class="settings-card" style="grid-column:1/-1;">
        <div class="settings-card-title"><i class="fas fa-key"></i>Account Recovery</div>
        <div class="settings-card-sub">
          <?php if ($hasSecurityQuestions): ?>
            Security questions are set up for your owner login (<strong><?= htmlspecialchars($displayName) ?></strong>). Saving again replaces both questions and answers.
          <?php else: ?>
            <strong>Not set up yet.</strong> If you ever forget this account's password, there's no one above the owner role to reset it for you — these questions are the only way back in. Set them up now, while you still have access.
          <?php endif; ?>
        </div>
        <div class="settings-field-row">
          <div class="settings-field">
            <label>Security Question 1</label>
            <select class="security-question-select" onchange="toggleCustomQuestion(this)" required>
              <option value="">Choose a question…</option>
              <?php foreach (SECURITY_QUESTION_PRESETS as $preset): ?>
                <option value="<?= htmlspecialchars($preset) ?>"<?= ($ownerSecurityRow['security_question_1'] ?? '') === $preset ? ' selected' : '' ?>><?= htmlspecialchars($preset) ?></option>
              <?php endforeach; ?>
              <option value="__custom__"<?= (!empty($ownerSecurityRow['security_question_1']) && !in_array($ownerSecurityRow['security_question_1'], SECURITY_QUESTION_PRESETS, true)) ? ' selected' : '' ?>>Write your own…</option>
            </select>
            <div class="security-question-custom">
              <input type="text" class="security-question-custom-input" placeholder="Type your own question" maxlength="255" value="<?= (!empty($ownerSecurityRow['security_question_1']) && !in_array($ownerSecurityRow['security_question_1'], SECURITY_QUESTION_PRESETS, true)) ? htmlspecialchars($ownerSecurityRow['security_question_1']) : '' ?>">
            </div>
            <input type="hidden" name="security_question_1" class="security-question-hidden">
          </div>
          <div class="settings-field">
            <label>Answer 1</label>
            <input type="text" name="security_answer_1" placeholder="Answer (not case-sensitive)" autocomplete="off">
          </div>
        </div>
        <div class="settings-field-row">
          <div class="settings-field">
            <label>Security Question 2</label>
            <select class="security-question-select" onchange="toggleCustomQuestion(this)" required>
              <option value="">Choose a question…</option>
              <?php foreach (SECURITY_QUESTION_PRESETS as $preset): ?>
                <option value="<?= htmlspecialchars($preset) ?>"<?= ($ownerSecurityRow['security_question_2'] ?? '') === $preset ? ' selected' : '' ?>><?= htmlspecialchars($preset) ?></option>
              <?php endforeach; ?>
              <option value="__custom__"<?= (!empty($ownerSecurityRow['security_question_2']) && !in_array($ownerSecurityRow['security_question_2'], SECURITY_QUESTION_PRESETS, true)) ? ' selected' : '' ?>>Write your own…</option>
            </select>
            <div class="security-question-custom">
              <input type="text" class="security-question-custom-input" placeholder="Type your own question" maxlength="255" value="<?= (!empty($ownerSecurityRow['security_question_2']) && !in_array($ownerSecurityRow['security_question_2'], SECURITY_QUESTION_PRESETS, true)) ? htmlspecialchars($ownerSecurityRow['security_question_2']) : '' ?>">
            </div>
            <input type="hidden" name="security_question_2" class="security-question-hidden">
          </div>
          <div class="settings-field">
            <label>Answer 2</label>
            <input type="text" name="security_answer_2" placeholder="Answer (not case-sensitive)" autocomplete="off">
          </div>
        </div>
        <button type="submit" class="btn-primary" style="margin-top:4px;"><i class="fas fa-shield-halved"></i> Save Recovery Questions</button>
      </div>
    </div>
  </form>
</div>

<div id="toast-container"></div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    updateClock(); setInterval(updateClock, 1000);
    document.querySelectorAll('.security-question-select').forEach(toggleCustomQuestion);
  });

  // Shows/hides the free-text box next to each security-question picker — "Write your own…"
  // reveals it, any preset hides it, matching this app's usual toggle pattern elsewhere.
  function toggleCustomQuestion(select) {
    const customBox = select.closest('.settings-field').querySelector('.security-question-custom');
    customBox.style.display = select.value === '__custom__' ? 'block' : 'none';
  }

  // The <select> is UI-only (its "Write your own…" option isn't a real question); this copies
  // whichever text is actually intended — the preset or the custom box — into the hidden input
  // that gets submitted, and blocks submit if either question ended up empty.
  function validateSecurityQuestionsForm(form) {
    const selects = form.querySelectorAll('.security-question-select');
    for (const select of selects) {
      const hidden = select.closest('.settings-field').querySelector('.security-question-hidden');
      if (select.value === '__custom__') {
        const customText = select.closest('.settings-field').querySelector('.security-question-custom-input').value.trim();
        if (customText === '') {
          showToast('Please type your custom security question.', 'warn');
          return false;
        }
        hidden.value = customText;
      } else if (select.value === '') {
        showToast('Please choose both security questions.', 'warn');
        return false;
      } else {
        hidden.value = select.value;
      }
    }
    return true;
  }

  function updateClock() {
    const clock = document.getElementById('clock');
    if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
