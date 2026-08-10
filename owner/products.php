<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

function resolve_category_id(mysqli $conn, string $categorySelect, string $newCategoryName): int
{
    if ($categorySelect === 'new') {
        $name = trim($newCategoryName);
        if ($name === '') {
            throw new RuntimeException('Please enter a name for the new category.');
        }
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

    $id = (int)$categorySelect;
    if ($id <= 0) {
        throw new RuntimeException('Please choose a category.');
    }
    $check = $conn->prepare('SELECT product_category_id FROM product_category WHERE product_category_id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    $found = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$found) {
        throw new RuntimeException('Selected category no longer exists.');
    }
    return $id;
}

function resolve_supplier_id(mysqli $conn, string $supplierName, string $supplierContact): int
{
    $name = trim($supplierName);
    $contact = trim($supplierContact);
    if ($name === '' || $contact === '') {
        throw new RuntimeException('Supplier name and contact are required.');
    }

    $check = $conn->prepare('SELECT product_supplier_id, supplier_contact FROM product_supplier WHERE supplier_name = ?');
    $check->bind_param('s', $name);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        $id = (int)$existing['product_supplier_id'];
        if ($existing['supplier_contact'] !== $contact) {
            $update = $conn->prepare('UPDATE product_supplier SET supplier_contact = ? WHERE product_supplier_id = ?');
            $update->bind_param('si', $contact, $id);
            $update->execute();
            $update->close();
        }
        return $id;
    }

    $insert = $conn->prepare('INSERT INTO product_supplier (supplier_name, supplier_contact) VALUES (?, ?)');
    $insert->bind_param('ss', $name, $contact);
    $insert->execute();
    $id = (int)$insert->insert_id;
    $insert->close();
    return $id;
}

// Returns a new relative image path if a valid file was uploaded, or null if no file was provided.
// Throws on an invalid/failed upload so the caller can surface a clear error.
function handle_product_image_upload(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Image must be smaller than 5MB.');
    }

    $allowedExtByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowedExtByMime[$mime])) {
        throw new RuntimeException('Please upload a JPG, PNG, GIF, or WEBP image.');
    }

    $destDir = __DIR__ . '/../uploads/products';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not prepare the upload folder.');
    }

    $filename = uniqid('prod_', true) . '.' . $allowedExtByMime[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return 'uploads/products/' . $filename;
}

function delete_product_image_file(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $fullPath = __DIR__ . '/../' . $relativePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg = '';
    $msgType = 'success';

    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $stock = $_POST['stock'] ?? '';
        $cost = $_POST['cost'] ?? '';
        $price = $_POST['price'] ?? '';

        if ($name === '' || !is_numeric($stock) || (int)$stock < 0 || !is_numeric($cost) || (float)$cost < 0 || !is_numeric($price) || (float)$price < 0) {
            $msg = 'Please fill in all product fields with valid values.';
            $msgType = 'warn';
        } else {
            try {
                $categoryId = resolve_category_id($conn, $_POST['category'] ?? '', $_POST['new_category_name'] ?? '');
                $supplierId = resolve_supplier_id($conn, $_POST['supplier_name'] ?? '', $_POST['supplier_contact'] ?? '');
                $stockInt = (int)$stock;
                $costVal = (float)$cost;
                $priceVal = (float)$price;
                $newImagePath = handle_product_image_upload($_FILES['product_image'] ?? null);

                if ($action === 'add') {
                    $stmt = $conn->prepare("INSERT INTO products (product_name, product_category_id, product_stocks, product_cost, product_selling_price, product_supplier_id, product_type, product_image) VALUES (?, ?, ?, ?, ?, ?, 'prepared', ?)");
                    $stmt->bind_param('siiddis', $name, $categoryId, $stockInt, $costVal, $priceVal, $supplierId, $newImagePath);
                    $stmt->execute();
                    $stmt->close();
                    $msg = 'Product added.';
                } else {
                    $productId = (int)($_POST['product_id'] ?? 0);
                    if ($productId <= 0) {
                        throw new RuntimeException('Invalid product.');
                    }

                    if ($newImagePath !== null) {
                        $oldStmt = $conn->prepare('SELECT product_image FROM products WHERE product_id = ?');
                        $oldStmt->bind_param('i', $productId);
                        $oldStmt->execute();
                        $oldImage = $oldStmt->get_result()->fetch_assoc()['product_image'] ?? null;
                        $oldStmt->close();

                        $stmt = $conn->prepare('UPDATE products SET product_name = ?, product_category_id = ?, product_stocks = ?, product_cost = ?, product_selling_price = ?, product_supplier_id = ?, product_image = ? WHERE product_id = ?');
                        $stmt->bind_param('siiddisi', $name, $categoryId, $stockInt, $costVal, $priceVal, $supplierId, $newImagePath, $productId);
                        $stmt->execute();
                        $stmt->close();
                        delete_product_image_file($oldImage);
                    } else {
                        $stmt = $conn->prepare('UPDATE products SET product_name = ?, product_category_id = ?, product_stocks = ?, product_cost = ?, product_selling_price = ?, product_supplier_id = ? WHERE product_id = ?');
                        $stmt->bind_param('siiddii', $name, $categoryId, $stockInt, $costVal, $priceVal, $supplierId, $productId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $msg = 'Product updated.';
                }
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $msgType = 'warn';
            }
        }
    } elseif ($action === 'delete') {
        $productId = (int)($_POST['product_id'] ?? 0);
        try {
            $imgStmt = $conn->prepare('SELECT product_image FROM products WHERE product_id = ?');
            $imgStmt->bind_param('i', $productId);
            $imgStmt->execute();
            $imageToDelete = $imgStmt->get_result()->fetch_assoc()['product_image'] ?? null;
            $imgStmt->close();

            $stmt = $conn->prepare('DELETE FROM products WHERE product_id = ?');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->close();
            delete_product_image_file($imageToDelete);
            $msg = 'Product deleted.';
        } catch (Throwable $e) {
            $msg = 'Cannot delete this product — it already has recorded sales.';
            $msgType = 'warn';
        }
    } elseif ($action === 'recipe_add') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
        $quantity = $_POST['quantity'] ?? '';

        if ($productId <= 0 || $ingredientId <= 0 || !is_numeric($quantity) || (float)$quantity <= 0) {
            $msg = 'Please choose an ingredient and enter a valid quantity.';
            $msgType = 'warn';
        } else {
            $qtyVal = (float)$quantity;
            $unitStmt = $conn->prepare('SELECT ingredient_unit FROM product_ingredients WHERE product_ingredients_id = ?');
            $unitStmt->bind_param('i', $ingredientId);
            $unitStmt->execute();
            $ingRow = $unitStmt->get_result()->fetch_assoc();
            $unitStmt->close();

            if (!$ingRow) {
                $msg = 'Selected ingredient no longer exists.';
                $msgType = 'warn';
            } else {
                $unit = $ingRow['ingredient_unit'];
                $existing = $conn->prepare('SELECT product_ingredient_items_id FROM product_ingredient_items WHERE product_id = ? AND product_ingredients_id = ?');
                $existing->bind_param('ii', $productId, $ingredientId);
                $existing->execute();
                $existingRow = $existing->get_result()->fetch_assoc();
                $existing->close();

                if ($existingRow) {
                    $itemId = (int)$existingRow['product_ingredient_items_id'];
                    $upd = $conn->prepare('UPDATE product_ingredient_items SET quantity = ?, unit = ? WHERE product_ingredient_items_id = ?');
                    $upd->bind_param('dsi', $qtyVal, $unit, $itemId);
                    $upd->execute();
                    $upd->close();
                } else {
                    $ins = $conn->prepare('INSERT INTO product_ingredient_items (product_id, product_ingredients_id, quantity, unit) VALUES (?, ?, ?, ?)');
                    $ins->bind_param('iids', $productId, $ingredientId, $qtyVal, $unit);
                    $ins->execute();
                    $ins->close();
                }
                $msg = 'Recipe updated.';
            }
        }
        header('Location: products.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType) . '&recipe=' . $productId);
        exit;
    } elseif ($action === 'recipe_remove') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM product_ingredient_items WHERE product_ingredient_items_id = ?');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        header('Location: products.php?msg=' . urlencode('Ingredient removed from recipe.') . '&type=success&recipe=' . $productId);
        exit;
    }

    header('Location: products.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
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

$displayName = $_SESSION['username'] ?? 'Owner';
$initials = strtoupper(substr($displayName, 0, 2));

$categories = [];
$catResult = $conn->query('SELECT product_category_id, product_category FROM product_category ORDER BY product_category');
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
}

$ingredients = [];
$ingResult = $conn->query('SELECT product_ingredients_id, ingredient_name, ingredient_unit FROM product_ingredients ORDER BY ingredient_name');
while ($row = $ingResult->fetch_assoc()) {
    $ingredients[] = $row;
}

$recipesByProduct = [];
$recipeResult = $conn->query('
    SELECT pii.product_ingredient_items_id, pii.product_id, pii.product_ingredients_id, pii.quantity, pii.unit,
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
    ];
}

$products = [];
$prodResult = $conn->query('
    SELECT p.product_id, p.product_name, p.product_stocks, p.product_cost, p.product_selling_price,
           p.product_category_id, pc.product_category, p.product_image, s.supplier_name, s.supplier_contact
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
        'stock' => $stock,
        'level' => stock_level($stock, $criticalStockThreshold, $lowStockThreshold),
        'cost' => (float)$row['product_cost'],
        'price' => (float)$row['product_selling_price'],
        'image' => $row['product_image'] ? '../' . $row['product_image'] : null,
        'supplier_name' => $row['supplier_name'],
        'supplier_contact' => $row['supplier_contact'],
        'recipe' => $recipesByProduct[$pid] ?? [],
    ];
}

$reopenRecipeProductId = (int)($_GET['recipe'] ?? 0);
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
<body data-reopen-recipe="<?= $reopenRecipeProductId ?>">

<header id="app-header">
  <div class="header-brand">
    <div class="brand-logo"><i class="fas fa-mug-hot"></i></div>
    <div class="brand-text"><div class="name">SmartStock</div><div class="sub">Bean There Café</div></div>
  </div>
  <div class="header-center"><span class="portal-badge">Owner Panel</span><span class="header-view-label">Products</span></div>
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
  <a href="products.php" class="nav-item active"><i class="fas fa-boxes-stacked"></i> Products</a>
  <a href="inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory</a>
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
  <hr class="sidebar-divider"/>
  <div class="sidebar-section-label">Settings</div>
  <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> System Settings</a>
  <div class="nav-item" onclick="showToast('Backup started!','success')"><i class="fas fa-database"></i> Data Backup</div>
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
      <h1><i class="fas fa-boxes-stacked" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Products</h1>
      <div class="sub">Track stock levels, unit costs, and supplier contacts</div>
    </div>
    <button class="btn-primary" onclick="openModal('modal-add-item')"><i class="fas fa-plus"></i> Add Product</button>
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
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Image</th><th>Product Name</th><th>Category</th><th>Stock</th><th>Unit Cost</th><th>Selling Price</th><th>Supplier</th><th>Contact</th><th>Actions</th></tr></thead>
        <tbody id="product-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-add-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-plus-circle" style="color:var(--gold);margin-right:8px;"></i>Add New Product</div>
    <div class="modal-sub">Fill in product details to add to the product catalog.</div>
    <form method="POST" action="products.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <div class="modal-field"><label>Product Name</label><input type="text" name="name" placeholder="e.g. Caramel Macchiato" required /></div>
      <div class="modal-field"><label>Product Image</label><input type="file" name="product_image" accept="image/*" /></div>
      <div class="modal-field">
        <label>Category</label>
        <select name="category" onchange="toggleNewCategoryField(this, 'add-new-category-wrap')">
          <option value="">Select category…</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
          <?php endforeach; ?>
          <option value="new">+ Add new category…</option>
        </select>
      </div>
      <div class="modal-field" id="add-new-category-wrap" style="display:none;"><label>New Category Name</label><input type="text" name="new_category_name" placeholder="e.g. Pastries" /></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="modal-field"><label>Stock Quantity</label><input type="number" name="stock" min="0" placeholder="0" required /></div>
        <div class="modal-field"><label>Unit Cost (₱)</label><input type="number" name="cost" min="0" step="0.01" placeholder="0.00" required /></div>
      </div>
      <div class="modal-field"><label>Selling Price (₱)</label><input type="number" name="price" min="0" step="0.01" placeholder="0.00" required /></div>
      <div class="modal-field"><label>Supplier Name</label><input type="text" name="supplier_name" placeholder="Supplier company" required /></div>
      <div class="modal-field"><label>Supplier Contact</label><input type="text" name="supplier_contact" placeholder="09XX-XXX-XXXX" required /></div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-check" style="margin-right:6px;"></i>Add Product</button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-add-item')">Cancel</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modal-edit-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-pen" style="color:var(--gold);margin-right:8px;"></i>Edit Product</div>
    <div class="modal-sub">Update product details.</div>
    <form method="POST" action="products.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="product_id" id="edit-product-id" value="" />
      <div class="modal-field"><label>Product Name</label><input type="text" name="name" id="edit-name" required /></div>
      <div class="modal-field" style="display:flex;align-items:center;gap:12px;">
        <img id="edit-current-image" src="" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1.5px solid var(--cream-dark);display:none;" />
        <div style="flex:1;">
          <label>Product Image</label>
          <input type="file" name="product_image" accept="image/*" />
          <div class="modal-sub" style="margin:4px 0 0;">Leave blank to keep the current image.</div>
        </div>
      </div>
      <div class="modal-field">
        <label>Category</label>
        <select name="category" id="edit-category" onchange="toggleNewCategoryField(this, 'edit-new-category-wrap')">
          <option value="">Select category…</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
          <?php endforeach; ?>
          <option value="new">+ Add new category…</option>
        </select>
      </div>
      <div class="modal-field" id="edit-new-category-wrap" style="display:none;"><label>New Category Name</label><input type="text" name="new_category_name" /></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="modal-field"><label>Stock Quantity</label><input type="number" name="stock" id="edit-stock" min="0" required /></div>
        <div class="modal-field"><label>Unit Cost (₱)</label><input type="number" name="cost" id="edit-cost" min="0" step="0.01" required /></div>
      </div>
      <div class="modal-field"><label>Selling Price (₱)</label><input type="number" name="price" id="edit-price" min="0" step="0.01" required /></div>
      <div class="modal-field"><label>Supplier Name</label><input type="text" name="supplier_name" id="edit-supplier-name" required /></div>
      <div class="modal-field"><label>Supplier Contact</label><input type="text" name="supplier_contact" id="edit-supplier-contact" required /></div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-check" style="margin-right:6px;"></i>Save Changes</button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-edit-item')">Cancel</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modal-recipe-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-flask" style="color:var(--gold);margin-right:8px;"></i>Recipe — <span id="recipe-product-name"></span></div>
    <div class="modal-sub">Ingredients consumed from Inventory each time this product is sold.</div>
    <div id="recipe-items-list" style="margin-bottom:16px;"></div>
    <?php if (empty($ingredients)): ?>
      <div style="font-size:12px;color:#aaa;padding:8px 0;">No ingredients yet — add some on the Inventory page first.</div>
    <?php else: ?>
      <form method="POST" action="products.php" style="border-top:1px solid var(--cream-dark);padding-top:14px;">
        <input type="hidden" name="action" value="recipe_add">
        <input type="hidden" name="product_id" id="recipe-add-product-id" value="">
        <div class="modal-field">
          <label>Ingredient</label>
          <select name="ingredient_id" id="recipe-ingredient-select" onchange="updateRecipeUnitLabel()" required>
            <option value="">Select ingredient…</option>
            <?php foreach ($ingredients as $ing): ?>
              <option value="<?= (int)$ing['product_ingredients_id'] ?>" data-unit="<?= htmlspecialchars($ing['ingredient_unit']) ?>"><?= htmlspecialchars($ing['ingredient_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-field">
          <label>Quantity per unit sold <span id="recipe-unit-label" style="text-transform:none;font-weight:400;"></span></label>
          <input type="number" name="quantity" id="recipe-quantity-input" min="0" step="0.01" placeholder="0.00" required />
        </div>
        <button type="submit" class="btn-modal-primary"><i class="fas fa-plus" style="margin-right:6px;"></i>Add to Recipe</button>
      </form>
    <?php endif; ?>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-recipe-item')">Close</button>
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

    const reopenId = parseInt(document.body.dataset.reopenRecipe || '0', 10);
    if (reopenId > 0) openRecipeModal(reopenId);
  });

  function updateClock() {
    const clock = document.getElementById('clock');
    if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function toggleNewCategoryField(select, wrapId) {
    document.getElementById(wrapId).style.display = select.value === 'new' ? 'block' : 'none';
  }

  function money(n) {
    return '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function renderProducts(search, catId, level) {
    const tbody = document.getElementById('product-tbody');
    if (!tbody) return;

    if (products.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#aaa;padding:24px 8px;">No products yet. Click "Add Product" to add your first item.</td></tr>';
      return;
    }

    const filtered = products.filter(p => {
      const s = !search || p.name.toLowerCase().includes(search.toLowerCase()) || p.supplier_name.toLowerCase().includes(search.toLowerCase());
      const c = !catId || String(p.category_id) === String(catId);
      const l = !level || p.level === level;
      return s && c && l;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#aaa;padding:24px 8px;">No products match your filters.</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map(p => `
      <tr>
        <td>${p.image ? `<img src="${p.image}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">` : `<div style="width:40px;height:40px;border-radius:6px;background:var(--cream-dark);"></div>`}</td>
        <td style="font-weight:600;">${p.name}</td>
        <td><span class="tag ${p.tag_class}">${p.category_name}</span></td>
        <td><div class="stock-indicator stock-${p.level}"><div class="stock-dot"></div>${p.stock}</div></td>
        <td class="text-mono">${money(p.cost)}</td>
        <td class="text-mono">${money(p.price)}</td>
        <td>${p.supplier_name}</td>
        <td class="text-mono" style="font-size:12px;">${p.supplier_contact}</td>
        <td style="display:flex;gap:6px;align-items:center;">
          <button class="tbl-btn tbl-btn-edit" onclick="openEditModal(${p.id})">Edit</button>
          <button class="tbl-btn tbl-btn-edit" onclick="openRecipeModal(${p.id})">Recipe</button>
          <form method="POST" action="products.php" style="display:contents;" onsubmit="return confirmDelete(event, 'Delete ${p.name.replace(/'/g, "\\'")}?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="product_id" value="${p.id}">
            <button type="submit" class="tbl-btn tbl-btn-del">Delete</button>
          </form>
        </td>
      </tr>
    `).join('');
  }

  function filterProducts() {
    renderProducts(
      document.getElementById('product-search').value,
      document.getElementById('product-cat-filter').value,
      document.getElementById('product-stock-filter').value
    );
  }

  function openEditModal(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    document.getElementById('edit-product-id').value = p.id;
    document.getElementById('edit-name').value = p.name;
    document.getElementById('edit-category').value = p.category_id;
    document.getElementById('edit-new-category-wrap').style.display = 'none';
    document.getElementById('edit-stock').value = p.stock;
    document.getElementById('edit-cost').value = p.cost;
    document.getElementById('edit-price').value = p.price;
    document.getElementById('edit-supplier-name').value = p.supplier_name;
    document.getElementById('edit-supplier-contact').value = p.supplier_contact;
    const preview = document.getElementById('edit-current-image');
    if (p.image) {
      preview.src = p.image;
      preview.style.display = 'block';
    } else {
      preview.removeAttribute('src');
      preview.style.display = 'none';
    }
    openModal('modal-edit-item');
  }

  function openRecipeModal(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    document.getElementById('recipe-product-name').textContent = p.name;
    const addProductIdField = document.getElementById('recipe-add-product-id');
    if (addProductIdField) addProductIdField.value = p.id;
    const select = document.getElementById('recipe-ingredient-select');
    if (select) select.value = '';
    const unitLabel = document.getElementById('recipe-unit-label');
    if (unitLabel) unitLabel.textContent = '';
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
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--cream-dark);">
        <div style="font-size:13px;"><strong>${r.ingredient_name}</strong> — ${r.quantity} ${r.unit} per unit sold</div>
        <form method="POST" action="products.php" onsubmit="return confirmDelete(event, 'Remove ${r.ingredient_name.replace(/'/g, "\\'")} from this recipe?')">
          <input type="hidden" name="action" value="recipe_remove">
          <input type="hidden" name="item_id" value="${r.item_id}">
          <input type="hidden" name="product_id" value="${p.id}">
          <button type="submit" class="tbl-btn tbl-btn-del">Remove</button>
        </form>
      </div>
    `).join('');
  }

  function updateRecipeUnitLabel() {
    const select = document.getElementById('recipe-ingredient-select');
    if (!select) return;
    const opt = select.options[select.selectedIndex];
    const label = document.getElementById('recipe-unit-label');
    if (label) label.textContent = opt && opt.dataset.unit ? '(' + opt.dataset.unit + ')' : '';
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
    openModal('modal-confirm-delete');
    return false;
  }
  document.getElementById('confirm-delete-yes').addEventListener('click', () => {
    closeModal('modal-confirm-delete');
    if (pendingDeleteForm) { pendingDeleteForm.submit(); pendingDeleteForm = null; }
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
