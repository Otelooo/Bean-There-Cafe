<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe staff') {
    header('Location: ../signin.php');
    exit;
}

$categories = ['drinks' => 'Drinks', 'foods' => 'Foods', 'prepared_product' => 'Prepared Product'];
$msg = $_GET['msg'] ?? '';
$msgType = $_GET['type'] ?? 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['expense_name'] ?? '');
    $price = $_POST['expense_price'] ?? '';
    $time = trim($_POST['expense_time'] ?? '');
    $category = $_POST['expense_category'] ?? '';
    $insertedBy = $_SESSION['username'] ?? 'Staff';
    $message = '';
    $type = 'success';
    if (($action === 'add' || $action === 'edit') && ($name === '' || !is_numeric($price) || (float)$price < 0 || $time === '' || !isset($categories[$category]))) {
        $message = 'Please fill in all expense fields with valid values.';
        $type = 'warn';
    } elseif ($action === 'add' || $action === 'edit') {
        $priceValue = (float)$price;
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            $message = 'Please enter a valid expense time.';
            $type = 'warn';
        } elseif ($action === 'add') {
            $timeValue = date('Y-m-d H:i:s', $timestamp);
            $stmt = $conn->prepare('INSERT INTO expenses (expense_name, expense_price, expense_time, expense_category, inserted_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sdsss', $name, $priceValue, $timeValue, $category, $insertedBy);
            $stmt->execute();
            $stmt->close();
            $message = 'Expense added.';
        } else {
            $expenseId = (int)($_POST['expense_id'] ?? 0);
            if ($expenseId <= 0) {
                $message = 'Invalid expense.';
                $type = 'warn';
            } else {
                $timeValue = date('Y-m-d H:i:s', $timestamp);
                $stmt = $conn->prepare('UPDATE expenses SET expense_name = ?, expense_price = ?, expense_time = ?, expense_category = ? WHERE expense_id = ?');
                $stmt->bind_param('sdssi', $name, $priceValue, $timeValue, $category, $expenseId);
                $stmt->execute();
                $stmt->close();
                $message = 'Expense updated.';
            }
        }
    } elseif ($action === 'delete') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM expenses WHERE expense_id = ?');
        $stmt->bind_param('i', $expenseId);
        $stmt->execute();
        $stmt->close();
        $message = 'Expense deleted.';
    } else {
        $message = 'Invalid expense action.';
        $type = 'warn';
    }
    header('Location: staff_expenses.php?msg=' . urlencode($message) . '&type=' . urlencode($type));
    exit;
}

$expenses = [];
$result = $conn->query('SELECT expense_id, expense_name, expense_price, expense_time, expense_category, inserted_by FROM expenses ORDER BY expense_time DESC, expense_id DESC');
while ($row = $result->fetch_assoc()) {
    $row['expense_id'] = (int)$row['expense_id'];
    $row['expense_price'] = (float)$row['expense_price'];
    $expenses[] = $row;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Expenses | SmartStock — Bean There Café</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" /><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
  <style>
    :root{--mocha:#4A2C2A;--deep:#2E1A18;--mid:#6B3D3A;--cream:#F5ECD7;--light:#FBF6EE;--line:#E8D8BA;--gold:#C9943A;--gold-light:#E8B860;--text:#2C2C2C;--red:#C0392B;--sidebar:240px;--header:64px;--display:'Playfair Display',serif;--body:'DM Sans',sans-serif;--mono:'DM Mono',monospace}*{box-sizing:border-box}body{margin:0;background:var(--light);color:var(--text);font-family:var(--body)}a{text-decoration:none}.nav-item{display:flex;align-items:center;gap:12px;padding:11px 20px;color:#f5ecd799;border-left:3px solid transparent;font-size:13.5px}.nav-item i{width:20px;text-align:center}.nav-item:hover,.nav-item.active{color:var(--gold-light);background:#c9943a1f;border-left-color:var(--gold)}main{margin:var(--header) 0 0 var(--sidebar);min-height:calc(100vh - var(--header))}.strip{padding:17px 26px;background:var(--cream);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:15px}h1{margin:0;color:var(--deep);font:700 22px var(--display)}.sub{margin-top:3px;color:var(--mid);font-size:12px}.content{padding:22px 26px}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}.filters{display:flex;gap:10px;flex-wrap:wrap}input,select{border:1.5px solid var(--line);border-radius:8px;background:var(--cream);color:var(--text);font:13px var(--body);padding:9px 12px}.search{min-width:240px}button{border:0;border-radius:8px;padding:10px 16px;color:var(--cream);background:var(--mocha);font:600 13px var(--body);cursor:pointer}button:hover{background:var(--mid)}.table-wrap{overflow:auto;background:var(--cream);border:1.5px solid var(--line);border-radius:14px;box-shadow:0 2px 8px #4a2c2a1a}table{width:100%;border-collapse:collapse;min-width:680px}th{padding:12px 15px;background:var(--deep);color:#f5ecd7aa;text-align:left;font-size:10px;letter-spacing:1.2px;text-transform:uppercase}td{padding:12px 15px;border-bottom:1px solid var(--line);font-size:13px}tr:last-child td{border-bottom:0}.money{color:var(--mocha);font:500 13px var(--mono)}.tag{display:inline-block;padding:4px 9px;border-radius:99px;background:#c9943a22;color:var(--mocha);font-size:11px;font-weight:700}.actions{display:flex;gap:6px}.small{padding:5px 10px;border:1px solid var(--gold);color:var(--gold);background:transparent}.danger{border-color:#c0392b99;color:var(--red)}.empty{text-align:center;color:#999;padding:30px}.overlay{position:fixed;inset:0;z-index:10;display:none;place-items:center;background:#140a0899;backdrop-filter:blur(3px)}.overlay.show{display:grid}.modal{width:min(460px,92vw);padding:27px 30px;border-radius:16px;background:var(--light);box-shadow:0 12px 40px #0005}.modal h2{margin:0 0 5px;color:var(--deep);font:700 20px var(--display)}.modal p{margin:0 0 18px;color:#888;font-size:13px}.field{margin-bottom:14px}label{display:block;margin-bottom:5px;color:#777;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase}.field input,.field select{width:100%}.cancel{width:100%;margin-top:8px;border:1px solid var(--line);color:#999;background:transparent}.toast{position:fixed;right:22px;bottom:22px;z-index:20;padding:12px 16px;border-left:3px solid var(--gold);border-radius:8px;background:var(--text);color:var(--cream);font-size:13px}.toast.warn{border-left-color:#e67e22}@media(max-width:760px){:root{--sidebar:0px}nav{display:none}.header-right{gap:8px}main{margin-left:0}.strip,.content{padding-left:16px;padding-right:16px}.strip{align-items:flex-start;flex-direction:column}}
    /* ── HEADER (same as other staff pages) ── */
    #app-header {
      position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
      height: var(--header);
      background: var(--deep);
      display: flex; align-items: center; padding: 0 24px 0 0;
      box-shadow: 0 2px 20px rgba(0,0,0,.35);
    }
    .header-brand {
      width: var(--sidebar); flex-shrink: 0;
      display: flex; align-items: center; gap: 12px;
      padding: 0 20px;
      border-right: 1px solid rgba(255,255,255,.08);
    }
    .brand-logo {
      width: 42px; height: 42px; border-radius: 10px;
      background: var(--gold); color: var(--deep);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; box-shadow: 0 2px 10px rgba(201,148,58,.45);
      flex-shrink: 0;
    }
    .brand-text .name { font-family: var(--display); font-size: 15px; font-weight: 700; color: var(--cream); }
    .brand-text .sub  { margin: 0; font-size: 10px; color: var(--gold-light); letter-spacing: 1.5px; text-transform: uppercase; }
    .header-right { margin-left: auto; display: flex; align-items: center; gap: 14px; }
    .header-clock { font-family: var(--mono); font-size: 13px; color: rgba(245,236,215,.55); }
    .header-user {
      display: flex; align-items: center; gap: 8px;
      background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.10);
      border-radius: 99px; padding: 5px 14px 5px 5px; cursor: pointer;
    }
    .header-avatar {
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--gold); color: var(--deep);
      font-weight: 700; font-size: 12px;
      display: flex; align-items: center; justify-content: center;
    }
    .header-user-name { font-size: 13px; color: var(--cream); font-weight: 500; }
    .logout-link { display:flex; align-items:center; gap:6px; background:rgba(192,57,43,.12); border:1px solid rgba(192,57,43,.28); color:#e08a80; font-size:12px; font-weight:600; padding:6px 14px; border-radius:99px; cursor:pointer; text-decoration:none; transition:all .2s; }
    .logout-link:hover { background:rgba(192,57,43,.22); color:#e08a80; }
    @media(max-width:760px){.header-brand{width:auto}.brand-text .sub{display:none}.header-right{gap:8px}.header-user,.header-clock{display:none}}
    .strip{padding:15px 26px}h1{font-family:var(--display);font-size:21px;font-weight:700}.sub{margin-top:1px;font-size:12px}.strip h1 i{font-size:22px !important;margin-right:10px !important}@media(max-width:760px){.header-brand{width:auto;min-width:0;border:0}nav{width:0}main{margin-left:0}}
        .strip-actions{display:flex;gap:10px;flex-wrap:wrap}.filter-panel{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--cream)}.filter-panel .field{margin:0}.filter-panel label{margin-bottom:4px}.outline{border:1px solid var(--gold);color:var(--mocha);background:transparent}.outline:hover{color:var(--cream);background:var(--mocha)}@media print{header,nav,.strip-actions,.toolbar,.filter-panel,.actions,.toast{display:none!important}main{margin:0}.content{padding:0}.table-wrap{border:0;box-shadow:none}body{background:#fff}}
    .overlay.show{display:grid!important;visibility:visible}
    #sidebar{position:fixed;top:var(--header);left:0;bottom:0;width:var(--sidebar);z-index:900;background:var(--deep);display:flex;flex-direction:column;overflow-y:auto;padding-top:0}
    .sidebar-section-label{padding:20px 20px 6px;font-size:9.5px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(245,236,215,.3)}
    #sidebar .nav-item{display:flex;align-items:center;gap:12px;padding:11px 20px;color:rgba(245,236,215,.6);cursor:pointer;font-size:13.5px;font-weight:500;border-left:3px solid transparent;transition:all .2s;user-select:none}
    #sidebar .nav-item i{width:20px;text-align:center;font-size:16px}
    #sidebar .nav-item:hover{background:rgba(255,255,255,.06);color:var(--cream)}
    #sidebar .nav-item.active{background:rgba(201,148,58,.12);color:var(--gold-light);border-left-color:var(--gold)}
    #sidebar .nav-item.active i{color:var(--gold)}
    .nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px}
    .sidebar-divider{border:0;border-top:1px solid rgba(255,255,255,.07);margin:8px 16px}
    #sidebar .sidebar-footer{margin-top:auto;padding:16px 20px;border-top:1px solid rgba(255,255,255,.07);text-align:center}
    #sidebar .sidebar-footer p{margin:0;font-size:10px;color:rgba(245,236,215,.22);line-height:1.7}
    .menu-toggle,.sidebar-backdrop{display:none}
    @media(max-width:991.98px){
      main{margin-left:0}
      #sidebar{display:flex!important;width:min(240px,84vw);transform:translateX(-100%);transition:transform .25s ease;box-shadow:0 12px 40px rgba(74,44,42,.22)}
      body.sidebar-open #sidebar{transform:translateX(0)}
      .menu-toggle{display:inline-flex;width:40px;height:40px;align-items:center;justify-content:center;flex-shrink:0;border:1px solid rgba(255,255,255,.18);border-radius:8px;background:rgba(255,255,255,.07);color:var(--cream);font-size:18px;cursor:pointer}
      .header-brand{width:auto;border:0;padding:0 14px}
      #app-header{padding-left:12px}
      .sidebar-backdrop{position:fixed;inset:var(--header) 0 0;z-index:850;background:rgba(20,10,8,.5)}
      body.sidebar-open .sidebar-backdrop{display:block}
    }
    @media(max-width:767.98px){
      :root{--header:58px}
      #app-header{padding-right:12px}
      .brand-logo{width:36px;height:36px;font-size:17px}
      .brand-text .name{font-size:13px}
      .brand-text .sub,.header-clock,.header-user-name{display:none}
      .header-right{gap:8px}
      .header-user{padding:4px}
      .logout-link{padding:7px 10px;font-size:0}
      .logout-link i{font-size:14px}
      .strip{padding:13px 16px;align-items:flex-start}
      .strip-actions{width:100%;flex-direction:column}
      .strip-actions button{width:100%;min-height:42px;justify-content:center}
      .content{padding:16px}
      .filter-panel,.toolbar,.filters{width:100%;align-items:stretch;flex-direction:column}
      .filter-panel .field,.filters>*{width:100%}
      .field input,.filters input,.filters select{width:100%;min-height:42px}
      .toolbar{gap:10px}
      .table-wrap{overflow:visible;background:transparent;border:0;box-shadow:none}
      table{display:block;width:100%;min-width:0}
      thead{display:none}
      tbody{display:grid;gap:12px}
      tbody tr[data-card]{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0 12px;padding:12px;background:var(--cream);border:1px solid var(--line);border-radius:12px;box-shadow:0 2px 8px #4a2c2a1a}
      tbody tr[data-card] td{min-width:0;display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}
      tbody tr[data-card] td::before{content:attr(data-label);flex:0 0 auto;color:#81736a;font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase}
      tbody tr[data-card] td:first-child{grid-column:1/-1}
      tbody tr[data-card] td:last-child{grid-column:1/-1;justify-content:flex-end;border-bottom:0}
      .actions{flex-wrap:wrap}
      .actions button{min-width:42px;min-height:40px}
      .modal{padding:22px 18px;max-height:92vh;overflow-y:auto}
      .toast{left:12px;right:12px;bottom:12px}
    }
    @media(max-width:380px){
      .header-brand{gap:8px;padding:0 8px}
      #app-header{padding-left:8px}
      tbody tr[data-card]{grid-template-columns:minmax(0,1fr)}
      tbody tr[data-card] td:first-child,tbody tr[data-card] td:last-child{grid-column:1}
    }
    </style>
</head>
<body>
<header id="app-header">
  <button type="button" class="menu-toggle" id="menu-toggle" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
  <div class="header-brand">
    <div class="brand-logo"><i class="fas fa-mug-hot"></i></div>
    <div class="brand-text"><div class="name">SmartStock</div><div class="sub">Bean There Café</div></div>
  </div>
  <div class="header-right">
    <div class="header-clock" id="clock"></div>
    <div class="header-user">
      <div class="header-avatar"><?= htmlspecialchars($initials) ?></div>
      <span class="header-user-name"><?= htmlspecialchars($displayName) ?></span>
    </div>
    <a href="../logout.php" class="logout-link"><i class="fas fa-right-from-bracket"></i> Logout</a>
  </div>
</header>
<div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>
<nav id="sidebar">
  <div class="sidebar-section-label">Staff Panel</div>
  <a href="staffdashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
  <a href="staff_transactions.php" class="nav-item"><i class="fas fa-receipt"></i> Transactions</a>
  <a href="staff_order_queue.php" class="nav-item"><i class="fas fa-list-check"></i> Order Queue</a>
  <a href="staff_transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="staff_inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_expenses.php" class="nav-item active"><i class="fas fa-wallet"></i> Expenses</a>
  <hr class="sidebar-divider">
  <div class="sidebar-footer">
    <p>SmartStock v1.0<br>Bean There Café<br>ISO/IEC 25010 Compliant</p>
  </div>
</nav>
<main><div class="strip"><div><h1><i class="fas fa-wallet" style="color:var(--gold);font-size:21px;margin-right:9px"></i>Expenses</h1><div class="sub">Record and review operating expenses by category and time.</div></div><div class="strip-actions"><button type="button" class="outline" onclick="printExpenses()"><i class="fas fa-print"></i> Print</button><button type="button" onclick="openExpenseModal()"><i class="fas fa-plus"></i> Add Expense</button></div></div><div class="content"><div class="filter-panel"><div class="field"><label for="date-from">Date from</label><input id="date-from" type="date" onchange="renderExpenses()"></div><div class="field"><label for="date-to">Date to</label><input id="date-to" type="date" onchange="renderExpenses()"></div><div class="field"><label for="hour-from">Hour from</label><input id="hour-from" type="time" onchange="renderExpenses()"></div><div class="field"><label for="hour-to">Hour to</label><input id="hour-to" type="time" onchange="renderExpenses()"></div><button type="button" class="outline" onclick="clearExpenseFilters()"><i class="fas fa-rotate-left"></i> Clear</button></div><div class="toolbar"><div class="filters"><input class="search" id="search" type="search" placeholder="Search expenses..." oninput="renderExpenses()"><select id="category-filter" onchange="renderExpenses()"><option value="">All Categories</option><?php foreach ($categories as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div><strong id="total" class="money"></strong></div><div class="table-wrap"><table><thead><tr><th>Expense</th><th>Category</th><th>Price</th><th>Time</th><th>Inserted By</th><th>Actions</th></tr></thead><tbody id="expense-list"></tbody></table></div></div></main>
<div class="overlay" id="expense-modal"><div class="modal"><h2 id="modal-title">Add Expense</h2><p>Enter the expense details below.</p><form method="post"><input type="hidden" name="action" id="form-action" value="add"><input type="hidden" name="expense_id" id="expense-id"><div class="field"><label>Expense Name</label><input name="expense_name" id="expense-name" maxlength="100" required></div><div class="field"><label>Price (₱)</label><input type="number" name="expense_price" id="expense-price" min="0" step="0.01" required></div><div class="field"><label>Time</label><input type="datetime-local" name="expense_time" id="expense-time" required></div><div class="field"><label>Expense Category</label><select name="expense_category" id="expense-category" required><?php foreach ($categories as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div><button type="submit"><i class="fas fa-check"></i> Save Expense</button><button type="button" class="cancel" onclick="closeExpenseModal()">Cancel</button></form></div></div>
<?php if ($msg): ?><div class="toast <?= $msgType === 'warn' ? 'warn' : '' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<script>
const menuToggle=document.getElementById('menu-toggle'),sidebarBackdrop=document.getElementById('sidebar-backdrop');
function setSidebarOpen(isOpen){document.body.classList.toggle('sidebar-open',isOpen);menuToggle.setAttribute('aria-expanded',String(isOpen));menuToggle.setAttribute('aria-label',isOpen?'Close navigation menu':'Open navigation menu');menuToggle.innerHTML=`<i class="fas ${isOpen?'fa-xmark':'fa-bars'}" aria-hidden="true"></i>`}
menuToggle.addEventListener('click',()=>setSidebarOpen(!document.body.classList.contains('sidebar-open')));
sidebarBackdrop.addEventListener('click',()=>setSidebarOpen(false));
document.querySelectorAll('#sidebar a').forEach(link=>link.addEventListener('click',()=>setSidebarOpen(false)));
document.addEventListener('keydown',event=>{if(event.key==='Escape')setSidebarOpen(false)});
window.addEventListener('resize',()=>{if(window.innerWidth>=992)setSidebarOpen(false)});
</script>
<script>
const expenses=<?= json_encode($expenses, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>; const categoryLabels=<?= json_encode($categories) ?>;
function money(value){return '₱'+Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} function esc(value){const node=document.createElement('span');node.textContent=value??'';return node.innerHTML} function displayTime(value){const date=new Date(String(value).replace(' ','T'));return Number.isNaN(date.getTime())?value:date.toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'short'})}
function filteredExpenses(){const search=document.getElementById('search').value.toLowerCase().trim(),category=document.getElementById('category-filter').value,dateFrom=document.getElementById('date-from').value,dateTo=document.getElementById('date-to').value,hourFrom=document.getElementById('hour-from').value,hourTo=document.getElementById('hour-to').value;return expenses.filter(item=>{const value=String(item.expense_time).replace(' ','T'),date=value.slice(0,10),hour=value.slice(11,16),hourMatches=(!hourFrom||!hourTo)||(hourFrom<=hourTo?hour>=hourFrom&&hour<=hourTo:hour>=hourFrom||hour<=hourTo);return(!search||item.expense_name.toLowerCase().includes(search))&&(!category||item.expense_category===category)&&(!dateFrom||date>=dateFrom)&&(!dateTo||date<=dateTo)&&hourMatches})}
function renderExpenses(){const rows=filteredExpenses();document.getElementById('total').textContent=money(rows.reduce((sum,item)=>sum+Number(item.expense_price),0));document.getElementById('expense-list').innerHTML=rows.length?rows.map(item=>`<tr data-card><td data-label="Expense"><strong>${esc(item.expense_name)}</strong></td><td data-label="Category"><span class="tag">${esc(categoryLabels[item.expense_category])}</span></td><td data-label="Price" class="money">${money(item.expense_price)}</td><td data-label="Time">${esc(displayTime(item.expense_time))}</td><td data-label="Inserted By">${esc(item.inserted_by||'Unknown')}</td><td data-label="Actions"><div class="actions"><button type="button" class="small" aria-label="Edit expense" title="Edit" onclick="editExpense(${item.expense_id})"><i class="fas fa-pen"></i></button><form method="post" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="expense_id" value="${item.expense_id}"><button type="submit" class="small danger" aria-label="Delete expense" title="Delete"><i class="fas fa-trash"></i></button></form></div></td></tr>`).join(''):'<tr><td class="empty" colspan="6">No expenses match the selected filters.</td></tr>'}
function clearExpenseFilters(){['search','category-filter','date-from','date-to','hour-from','hour-to'].forEach(id=>document.getElementById(id).value='');renderExpenses()} function printExpenses(){if(!filteredExpenses().length){alert('No expenses match the selected filters.');return}window.print()}
function openExpenseModal(item=null){document.getElementById('expense-modal').classList.add('show');document.getElementById('modal-title').textContent=item?'Edit Expense':'Add Expense';document.getElementById('form-action').value=item?'edit':'add';document.getElementById('expense-id').value=item?.expense_id||'';document.getElementById('expense-name').value=item?.expense_name||'';document.getElementById('expense-price').value=item?.expense_price??'';document.getElementById('expense-time').value=item?item.expense_time.replace(' ','T').slice(0,16):new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16);document.getElementById('expense-category').value=item?.expense_category||'foods'} function editExpense(id){openExpenseModal(expenses.find(item=>item.expense_id===id))} function closeExpenseModal(){document.getElementById('expense-modal').classList.remove('show')} document.getElementById('expense-modal').addEventListener('click',event=>{if(event.target.id==='expense-modal')closeExpenseModal()});document.getElementById('clock').textContent=new Date().toLocaleTimeString('en-PH');setInterval(()=>document.getElementById('clock').textContent=new Date().toLocaleTimeString('en-PH'),1000);renderExpenses();
</script>
</body>
</html>
