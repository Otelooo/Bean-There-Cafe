<?php
session_start();
require_once __DIR__ . '/../db_connect.php';

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
            $stmt = $conn->prepare('INSERT INTO expenses (expense_name, expense_price, expense_time, expense_category) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('sdss', $name, $priceValue, $timeValue, $category);
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
$result = $conn->query('SELECT expense_id, expense_name, expense_price, expense_time, expense_category FROM expenses ORDER BY expense_time DESC, expense_id DESC');
while ($row = $result->fetch_assoc()) {
    $row['expense_id'] = (int)$row['expense_id'];
    $row['expense_price'] = (float)$row['expense_price'];
    $expenses[] = $row;
}
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
    :root{--mocha:#4A2C2A;--deep:#2E1A18;--mid:#6B3D3A;--cream:#F5ECD7;--light:#FBF6EE;--line:#E8D8BA;--gold:#C9943A;--gold-light:#E8B860;--text:#2C2C2C;--red:#C0392B;--sidebar:240px;--header:64px;--display:'Playfair Display',serif;--body:'DM Sans',sans-serif;--mono:'DM Mono',monospace}*{box-sizing:border-box}body{margin:0;background:var(--light);color:var(--text);font-family:var(--body)}a{text-decoration:none}header{position:fixed;inset:0 0 auto;height:var(--header);z-index:5;background:var(--deep);display:flex;align-items:center;padding-right:24px;box-shadow:0 2px 18px #0005}.brand{width:var(--sidebar);height:100%;display:flex;align-items:center;gap:12px;padding:0 20px;border-right:1px solid #fff1;color:var(--cream)}.logo{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:var(--gold);color:var(--deep);font-size:20px}.brand strong{display:block;font:700 15px var(--display)}.brand small{color:var(--gold-light);font-size:10px;letter-spacing:1.5px;text-transform:uppercase}.header-right{margin-left:auto;display:flex;align-items:center;gap:14px;color:var(--cream);font-size:13px}.clock{color:#f5ecd799;font:13px var(--mono)}.user{display:flex;align-items:center;gap:8px}.avatar{width:28px;height:28px;display:grid;place-items:center;border-radius:50%;background:var(--gold);color:var(--deep);font-weight:700;font-size:12px}.logout{color:#e08a80;border:1px solid #c0392b55;border-radius:99px;padding:6px 14px;font-weight:600}nav{position:fixed;top:var(--header);bottom:0;width:var(--sidebar);background:var(--deep);padding-top:18px}.section{padding:0 20px 7px;color:#f5ecd74d;font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase}.nav-item{display:flex;align-items:center;gap:12px;padding:11px 20px;color:#f5ecd799;border-left:3px solid transparent;font-size:13.5px}.nav-item i{width:20px;text-align:center}.nav-item:hover,.nav-item.active{color:var(--gold-light);background:#c9943a1f;border-left-color:var(--gold)}.divider{margin:8px 16px;border:0;border-top:1px solid #fff1}main{margin:var(--header) 0 0 var(--sidebar);min-height:calc(100vh - var(--header))}.strip{padding:17px 26px;background:var(--cream);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:15px}h1{margin:0;color:var(--deep);font:700 22px var(--display)}.sub{margin-top:3px;color:var(--mid);font-size:12px}.content{padding:22px 26px}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}.filters{display:flex;gap:10px;flex-wrap:wrap}input,select{border:1.5px solid var(--line);border-radius:8px;background:var(--cream);color:var(--text);font:13px var(--body);padding:9px 12px}.search{min-width:240px}button{border:0;border-radius:8px;padding:10px 16px;color:var(--cream);background:var(--mocha);font:600 13px var(--body);cursor:pointer}button:hover{background:var(--mid)}.table-wrap{overflow:auto;background:var(--cream);border:1.5px solid var(--line);border-radius:14px;box-shadow:0 2px 8px #4a2c2a1a}table{width:100%;border-collapse:collapse;min-width:680px}th{padding:12px 15px;background:var(--deep);color:#f5ecd7aa;text-align:left;font-size:10px;letter-spacing:1.2px;text-transform:uppercase}td{padding:12px 15px;border-bottom:1px solid var(--line);font-size:13px}tr:last-child td{border-bottom:0}.money{color:var(--mocha);font:500 13px var(--mono)}.tag{display:inline-block;padding:4px 9px;border-radius:99px;background:#c9943a22;color:var(--mocha);font-size:11px;font-weight:700}.actions{display:flex;gap:6px}.small{padding:5px 10px;border:1px solid var(--gold);color:var(--gold);background:transparent}.danger{border-color:#c0392b99;color:var(--red)}.empty{text-align:center;color:#999;padding:30px}.overlay{position:fixed;inset:0;z-index:10;display:none;place-items:center;background:#140a0899;backdrop-filter:blur(3px)}.overlay.show{display:grid}.modal{width:min(460px,92vw);padding:27px 30px;border-radius:16px;background:var(--light);box-shadow:0 12px 40px #0005}.modal h2{margin:0 0 5px;color:var(--deep);font:700 20px var(--display)}.modal p{margin:0 0 18px;color:#888;font-size:13px}.field{margin-bottom:14px}label{display:block;margin-bottom:5px;color:#777;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase}.field input,.field select{width:100%}.cancel{width:100%;margin-top:8px;border:1px solid var(--line);color:#999;background:transparent}.toast{position:fixed;right:22px;bottom:22px;z-index:20;padding:12px 16px;border-left:3px solid var(--gold);border-radius:8px;background:var(--text);color:var(--cream);font-size:13px}.toast.warn{border-left-color:#e67e22}@media(max-width:760px){:root{--sidebar:0px}nav{display:none}.brand{width:auto;border:0}.brand small{display:none}.header-right{gap:8px}.header-right .user,.clock{display:none}main{margin-left:0}.strip,.content{padding-left:16px;padding-right:16px}.strip{align-items:flex-start;flex-direction:column}}
    /* Shared portal shell: keep Expenses identical to Inventory and Products. */
    header{height:var(--header);padding:0 24px 0 0;box-shadow:0 2px 20px rgba(0,0,0,.35)}.brand{width:var(--sidebar);min-width:var(--sidebar);padding:0 20px;border-right:1px solid rgba(255,255,255,.08)}.logo{width:42px;height:42px;border-radius:10px;font-size:20px;box-shadow:0 2px 10px rgba(201,148,58,.45);flex-shrink:0}.brand strong{font-family:var(--display);font-size:15px;font-weight:700}.brand small{font-size:10px;letter-spacing:1.5px}.header-right{gap:14px}.clock{font-family:var(--mono);font-size:13px;color:rgba(245,236,215,.55)}.user{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.10);border-radius:99px;padding:5px 14px 5px 5px;cursor:pointer}.avatar{width:28px;height:28px;border-radius:50%;font-size:12px;display:flex;align-items:center;justify-content:center}.logout{display:flex;align-items:center;gap:6px;font-size:12px;padding:6px 14px}nav{top:var(--header);width:var(--sidebar);overflow-y:auto;display:flex;flex-direction:column}.section{padding:20px 20px 6px;font-size:9.5px;letter-spacing:2px;color:rgba(245,236,215,.3)}.nav-item{gap:12px;padding:11px 20px;color:rgba(245,236,215,.6);font-size:13.5px;font-weight:500;border-left:3px solid transparent}.nav-item i{width:20px;text-align:center;font-size:16px}.nav-item:hover{background:rgba(255,255,255,.06);color:var(--cream)}.nav-item.active{background:rgba(201,148,58,.12);color:var(--gold-light);border-left-color:var(--gold)}.nav-item.active i{color:var(--gold)}.divider{border-top:1px solid rgba(255,255,255,.07);margin:8px 16px}.sidebar-footer{margin-top:auto;padding:16px 20px;border-top:1px solid rgba(255,255,255,.07);text-align:center}.sidebar-footer p{margin:0;font-size:10px;color:rgba(245,236,215,.22);line-height:1.7}.strip{padding:15px 26px}h1{font-family:var(--display);font-size:21px;font-weight:700}.sub{margin-top:1px;font-size:12px}.strip h1 i{font-size:22px !important;margin-right:10px !important}@media(max-width:760px){.brand{width:auto;min-width:0}nav{width:0}main{margin-left:0}}
        .strip-actions{display:flex;gap:10px;flex-wrap:wrap}.filter-panel{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--cream)}.filter-panel .field{margin:0}.filter-panel label{margin-bottom:4px}.outline{border:1px solid var(--gold);color:var(--mocha);background:transparent}.outline:hover{color:var(--cream);background:var(--mocha)}@media print{header,nav,.strip-actions,.toolbar,.filter-panel,.actions,.toast{display:none!important}main{margin:0}.content{padding:0}.table-wrap{border:0;box-shadow:none}body{background:#fff}}
    </style>
</head>
<body>
<header><div class="brand"><div class="logo"><i class="fas fa-mug-hot"></i></div><div><strong>SmartStock</strong><small>Bean There Café</small></div></div><div class="header-right"><span class="clock" id="clock"></span><span class="user"><span class="avatar"><?= htmlspecialchars($initials) ?></span><?= htmlspecialchars($displayName) ?></span><a class="logout" href="../logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a></div></header>
<nav><div class="section">Staff Panel</div><a class="nav-item" href="staffdashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a><a class="nav-item" href="staff_transactions.php"><i class="fas fa-receipt"></i> Transactions</a><a class="nav-item" href="staff_order_queue.php"><i class="fas fa-list-check"></i> Order Queue</a><a class="nav-item" href="staff_transaction_history.php"><i class="fas fa-clock-rotate-left"></i> Transaction History</a><a class="nav-item" href="staff_products.php"><i class="fas fa-boxes-stacked"></i> Products</a><a class="nav-item" href="staff_inventory.php"><i class="fas fa-warehouse"></i> Inventory</a><a class="nav-item active" href="staff_expenses.php"><i class="fas fa-wallet"></i> Expenses</a><a class="nav-item" href="staff_reports.php"><i class="fas fa-chart-bar"></i> Sales Report</a><div class="sidebar-footer"><p>SmartStock v1.0<br>Bean There Café<br>ISO/IEC 25010 Compliant</p></div></nav>
<main><div class="strip"><div><h1><i class="fas fa-wallet" style="color:var(--gold);font-size:21px;margin-right:9px"></i>Expenses</h1><div class="sub">Record and review operating expenses by category and time.</div></div><div class="strip-actions"><button type="button" class="outline" onclick="printExpenses()"><i class="fas fa-print"></i> Print</button><button type="button" onclick="openExpenseModal()"><i class="fas fa-plus"></i> Add Expense</button></div></div><div class="content"><div class="filter-panel"><div class="field"><label for="date-from">Date from</label><input id="date-from" type="date" onchange="renderExpenses()"></div><div class="field"><label for="date-to">Date to</label><input id="date-to" type="date" onchange="renderExpenses()"></div><div class="field"><label for="hour-from">Hour from</label><input id="hour-from" type="time" onchange="renderExpenses()"></div><div class="field"><label for="hour-to">Hour to</label><input id="hour-to" type="time" onchange="renderExpenses()"></div><button type="button" class="outline" onclick="clearExpenseFilters()"><i class="fas fa-rotate-left"></i> Clear</button></div><div class="toolbar"><div class="filters"><input class="search" id="search" type="search" placeholder="Search expenses..." oninput="renderExpenses()"><select id="category-filter" onchange="renderExpenses()"><option value="">All Categories</option><?php foreach ($categories as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div><strong id="total" class="money"></strong></div><div class="table-wrap"><table><thead><tr><th>Expense</th><th>Category</th><th>Price</th><th>Time</th><th>Actions</th></tr></thead><tbody id="expense-list"></tbody></table></div></div></main>
<div class="overlay" id="expense-modal"><div class="modal"><h2 id="modal-title">Add Expense</h2><p>Enter the expense details below.</p><form method="post"><input type="hidden" name="action" id="form-action" value="add"><input type="hidden" name="expense_id" id="expense-id"><div class="field"><label>Expense Name</label><input name="expense_name" id="expense-name" maxlength="100" required></div><div class="field"><label>Price (₱)</label><input type="number" name="expense_price" id="expense-price" min="0" step="0.01" required></div><div class="field"><label>Time</label><input type="datetime-local" name="expense_time" id="expense-time" required></div><div class="field"><label>Expense Category</label><select name="expense_category" id="expense-category" required><?php foreach ($categories as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div><button type="submit"><i class="fas fa-check"></i> Save Expense</button><button type="button" class="cancel" onclick="closeExpenseModal()">Cancel</button></form></div></div>
<?php if ($msg): ?><div class="toast <?= $msgType === 'warn' ? 'warn' : '' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<script>
const expenses=<?= json_encode($expenses, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>; const categoryLabels=<?= json_encode($categories) ?>;
function money(value){return '₱'+Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} function esc(value){const node=document.createElement('span');node.textContent=value??'';return node.innerHTML} function displayTime(value){const date=new Date(String(value).replace(' ','T'));return Number.isNaN(date.getTime())?value:date.toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'short'})}
function filteredExpenses(){const search=document.getElementById('search').value.toLowerCase().trim(),category=document.getElementById('category-filter').value,dateFrom=document.getElementById('date-from').value,dateTo=document.getElementById('date-to').value,hourFrom=document.getElementById('hour-from').value,hourTo=document.getElementById('hour-to').value;return expenses.filter(item=>{const value=String(item.expense_time).replace(' ','T'),date=value.slice(0,10),hour=value.slice(11,16),hourMatches=(!hourFrom||!hourTo)||(hourFrom<=hourTo?hour>=hourFrom&&hour<=hourTo:hour>=hourFrom||hour<=hourTo);return(!search||item.expense_name.toLowerCase().includes(search))&&(!category||item.expense_category===category)&&(!dateFrom||date>=dateFrom)&&(!dateTo||date<=dateTo)&&hourMatches})}
function renderExpenses(){const rows=filteredExpenses();document.getElementById('total').textContent=money(rows.reduce((sum,item)=>sum+Number(item.expense_price),0));document.getElementById('expense-list').innerHTML=rows.length?rows.map(item=>`<tr><td><strong>${esc(item.expense_name)}</strong></td><td><span class="tag">${esc(categoryLabels[item.expense_category])}</span></td><td class="money">${money(item.expense_price)}</td><td>${esc(displayTime(item.expense_time))}</td><td><div class="actions"><button type="button" class="small" onclick="editExpense(${item.expense_id})"><i class="fas fa-pen"></i></button><form method="post" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="expense_id" value="${item.expense_id}"><button type="submit" class="small danger"><i class="fas fa-trash"></i></button></form></div></td></tr>`).join(''):'<tr><td class="empty" colspan="5">No expenses match the selected filters.</td></tr>'}
function clearExpenseFilters(){['search','category-filter','date-from','date-to','hour-from','hour-to'].forEach(id=>document.getElementById(id).value='');renderExpenses()} function printExpenses(){if(!filteredExpenses().length){alert('No expenses match the selected filters.');return}window.print()}
function openExpenseModal(item=null){document.getElementById('expense-modal').classList.add('show');document.getElementById('modal-title').textContent=item?'Edit Expense':'Add Expense';document.getElementById('form-action').value=item?'edit':'add';document.getElementById('expense-id').value=item?.expense_id||'';document.getElementById('expense-name').value=item?.expense_name||'';document.getElementById('expense-price').value=item?.expense_price??'';document.getElementById('expense-time').value=item?item.expense_time.replace(' ','T').slice(0,16):new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16);document.getElementById('expense-category').value=item?.expense_category||'foods'} function editExpense(id){openExpenseModal(expenses.find(item=>item.expense_id===id))} function closeExpenseModal(){document.getElementById('expense-modal').classList.remove('show')} document.getElementById('expense-modal').addEventListener('click',event=>{if(event.target.id==='expense-modal')closeExpenseModal()});document.getElementById('clock').textContent=new Date().toLocaleTimeString('en-PH');setInterval(()=>document.getElementById('clock').textContent=new Date().toLocaleTimeString('en-PH'),1000);renderExpenses();
</script>
</body>
</html>
