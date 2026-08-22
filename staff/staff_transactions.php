<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe staff') {
    header('Location: ../signin.php');
    exit;
}

$settings = get_system_settings($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    header('Content-Type: application/json');

    $rawCart = json_decode($_POST['cart'] ?? '[]', true);
    $paymentMethod = ($_POST['payment_method'] ?? 'cash') === 'ewallet' ? 'online' : 'cash';
    $discountRate = !empty($_POST['discount']) ? (float)$settings['discount_rate'] : 0.0;

    if (!is_array($rawCart) || count($rawCart) === 0) {
        echo json_encode(['success' => false, 'error' => 'Cart is empty.']);
        exit;
    }

    // Each cart line is its own product+flavor combination, so the same product can appear
    // more than once (e.g. two orders of Fries with different flavors chosen).
    $cartLines = [];
    $totalQtyByProduct = [];
    foreach ($rawCart as $line) {
        $pid = (int)($line['id'] ?? 0);
        $qty = (int)($line['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid cart item.']);
            exit;
        }
        $flavorRaw = $line['flavor_ingredient_id'] ?? null;
        $flavorId = ($flavorRaw !== null && $flavorRaw !== '') ? (int)$flavorRaw : null;
        $cartLines[] = ['product_id' => $pid, 'qty' => $qty, 'flavor_ingredient_id' => $flavorId];
        $totalQtyByProduct[$pid] = ($totalQtyByProduct[$pid] ?? 0) + $qty;
    }

    $conn->begin_transaction();
    try {
        $ids = array_keys($totalQtyByProduct);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT product_id, product_name, product_selling_price, product_stocks, product_type FROM products WHERE product_id IN ($placeholders) FOR UPDATE");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $productsById = [];
        while ($row = $result->fetch_assoc()) {
            $productsById[(int)$row['product_id']] = $row;
        }
        $stmt->close();

        if (count($productsById) !== count($totalQtyByProduct)) {
            throw new RuntimeException('One or more items are no longer available.');
        }

        foreach ($totalQtyByProduct as $pid => $totalQty) {
            $product = $productsById[$pid];
            // Made-to-order products have no product_stocks of their own — availability is governed
            // entirely by the ingredient stock check below.
            if ($product['product_type'] !== 'made_to_order' && (int)$product['product_stocks'] < $totalQty) {
                throw new RuntimeException('Not enough stock for ' . $product['product_name'] . '.');
            }
        }

        // Load each cart product's recipe once: required ingredients (always consumed) and
        // flavor-choice options (exactly one consumed — whichever the cashier picked).
        $recipeByProduct = [];
        $recipeStmt = $conn->prepare('
            SELECT pii.product_id, pii.product_ingredients_id, pii.quantity, pii.is_flavor_choice, pi.ingredient_name
            FROM product_ingredient_items pii
            JOIN product_ingredients pi ON pi.product_ingredients_id = pii.product_ingredients_id
            WHERE pii.product_id = ?
        ');
        foreach ($totalQtyByProduct as $pid => $ignoredTotalQty) {
            $recipeStmt->bind_param('i', $pid);
            $recipeStmt->execute();
            $recipeResult = $recipeStmt->get_result();
            while ($row = $recipeResult->fetch_assoc()) {
                $recipeByProduct[$pid][] = [
                    'ingredient_id' => (int)$row['product_ingredients_id'],
                    'ingredient_name' => $row['ingredient_name'],
                    'quantity' => (float)$row['quantity'],
                    'is_choice' => (bool)$row['is_flavor_choice'],
                ];
            }
        }
        $recipeStmt->close();

        $ingredientNeeds = [];
        $grossTotal = 0.0;
        $lineItems = [];
        foreach ($cartLines as $line) {
            $pid = $line['product_id'];
            $qty = $line['qty'];
            $product = $productsById[$pid];
            $unitPrice = (float)$product['product_selling_price'];
            $subtotal = $unitPrice * $qty;
            $grossTotal += $subtotal;

            $chosenIngredientName = null;
            $hasChoiceGroup = false;
            foreach ($recipeByProduct[$pid] ?? [] as $r) {
                if (!$r['is_choice']) {
                    $needed = $r['quantity'] * $qty;
                    $ingredientNeeds[$r['ingredient_id']] = ($ingredientNeeds[$r['ingredient_id']] ?? 0) + $needed;
                    continue;
                }
                $hasChoiceGroup = true;
                if ($r['ingredient_id'] === $line['flavor_ingredient_id']) {
                    $needed = $r['quantity'] * $qty;
                    $ingredientNeeds[$r['ingredient_id']] = ($ingredientNeeds[$r['ingredient_id']] ?? 0) + $needed;
                    $chosenIngredientName = $r['ingredient_name'];
                }
            }

            if ($hasChoiceGroup && $chosenIngredientName === null) {
                throw new RuntimeException('Please choose a flavor for ' . $product['product_name'] . '.');
            }

            $lineItems[] = [
                'product_id' => $pid,
                'product_name' => $product['product_name'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'chosen_ingredient_name' => $chosenIngredientName,
            ];
        }

        if ($ingredientNeeds) {
            $ingIds = array_keys($ingredientNeeds);
            $ingPlaceholders = implode(',', array_fill(0, count($ingIds), '?'));
            $ingTypes = str_repeat('i', count($ingIds));
            $ingStmt = $conn->prepare("SELECT product_ingredients_id, ingredient_name, ingredient_stock FROM product_ingredients WHERE product_ingredients_id IN ($ingPlaceholders) FOR UPDATE");
            $ingStmt->bind_param($ingTypes, ...$ingIds);
            $ingStmt->execute();
            $ingResult = $ingStmt->get_result();
            $ingredientsById = [];
            while ($row = $ingResult->fetch_assoc()) {
                $ingredientsById[(int)$row['product_ingredients_id']] = $row;
            }
            $ingStmt->close();

            foreach ($ingredientNeeds as $ingId => $needed) {
                $ing = $ingredientsById[$ingId] ?? null;
                if (!$ing || (float)$ing['ingredient_stock'] < $needed) {
                    $ingName = $ing['ingredient_name'] ?? ('ingredient #' . $ingId);
                    throw new RuntimeException('Not enough ' . $ingName . ' in stock to complete this sale.');
                }
            }
        }

        $discountAmount = round($grossTotal * $discountRate, 2);
        $finalTotal = round($grossTotal - $discountAmount, 2);

        $userId = (int)$_SESSION['user_id'];
        $cashierUsername = $_SESSION['username'] ?? '';
        $insertTxn = $conn->prepare("INSERT INTO transactions (user_id, cashier_username, transaction_date, transaction_total, transaction_status, payment_method, discount) VALUES (?, ?, NOW(), ?, 'completed', ?, ?)");
        $insertTxn->bind_param('isdsd', $userId, $cashierUsername, $finalTotal, $paymentMethod, $discountAmount);
        $insertTxn->execute();
        $transactionId = $insertTxn->insert_id;
        $insertTxn->close();

        // product_name_snapshot / chosen_ingredient_name_snapshot preserve what was actually sold
        // even if the product or ingredient is deleted later.
        $insertItem = $conn->prepare('INSERT INTO transaction_items (transaction_id, product_id, product_name_snapshot, chosen_ingredient_name_snapshot, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)');
        // Only "prepared" products track their own stock; made-to-order items have no product_stocks to decrement.
        $updateStock = $conn->prepare("UPDATE products SET product_stocks = product_stocks - ? WHERE product_id = ? AND product_type = 'prepared'");
        foreach ($lineItems as $item) {
            $insertItem->bind_param('iissidd', $transactionId, $item['product_id'], $item['product_name'], $item['chosen_ingredient_name'], $item['quantity'], $item['unit_price'], $item['subtotal']);
            $insertItem->execute();

            $updateStock->bind_param('ii', $item['quantity'], $item['product_id']);
            $updateStock->execute();
        }
        $insertItem->close();
        $updateStock->close();

        if ($ingredientNeeds) {
            $updateIngredient = $conn->prepare('UPDATE product_ingredients SET ingredient_stock = ingredient_stock - ? WHERE product_ingredients_id = ?');
            foreach ($ingredientNeeds as $ingId => $needed) {
                $updateIngredient->bind_param('di', $needed, $ingId);
                $updateIngredient->execute();
            }
            $updateIngredient->close();
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'transaction_id' => $transactionId,
            'total' => $finalTotal,
            'discount' => $discountAmount,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$displayName = $_SESSION['username'] ?? 'Staff';
$initials = strtoupper(substr($displayName, 0, 2));

$categories = [];
$catResult = $conn->query('SELECT product_category_id, product_category FROM product_category ORDER BY product_category');
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
}

// Flavor-choice ingredient options per product (e.g. Fries: Cheese Powder / BBQ / Sour Cream) —
// the cashier picks exactly one of these at add-to-cart time; everything else on the recipe is
// always included.
$flavorOptionsByProduct = [];
$flavorResult = $conn->query('
    SELECT pii.product_id, pi.product_ingredients_id, pi.ingredient_name
    FROM product_ingredient_items pii
    JOIN product_ingredients pi ON pi.product_ingredients_id = pii.product_ingredients_id
    WHERE pii.is_flavor_choice = 1
    ORDER BY pi.ingredient_name
');
while ($row = $flavorResult->fetch_assoc()) {
    $flavorOptionsByProduct[(int)$row['product_id']][] = [
        'id' => (int)$row['product_ingredients_id'],
        'name' => $row['ingredient_name'],
    ];
}

$products = [];
$prodResult = $conn->query('
    SELECT p.product_id, p.product_name, p.product_selling_price, p.product_stocks, p.product_category_id, pc.product_category, p.product_image, p.product_type
    FROM products p
    JOIN product_category pc ON pc.product_category_id = p.product_category_id
    ORDER BY pc.product_category, p.product_name
');
while ($row = $prodResult->fetch_assoc()) {
    $pid = (int)$row['product_id'];
    $products[] = [
        'id' => $pid,
        'name' => $row['product_name'],
        'price' => (float)$row['product_selling_price'],
        'stock' => (int)$row['product_stocks'],
        'type' => $row['product_type'],
        'category_id' => (int)$row['product_category_id'],
        'category_name' => $row['product_category'],
        'image' => $row['product_image'] ? '../' . $row['product_image'] : null,
        'flavor_options' => $flavorOptionsByProduct[$pid] ?? [],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Transactions | SmartStock — Bean There Café</title>
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

    /* ── POS ORIGINAL ── */
    #pos-header {
      background: var(--mocha-deep);
      padding: 16px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 20px rgba(0, 0, 0, .35);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .pos-brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .pos-logo {
      width: 42px;
      height: 42px;
      background: var(--gold);
      color: var(--mocha-deep);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
    }

    .pos-title {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 700;
      color: var(--cream);
    }

    .cart-badge {
      background: var(--gold);
      color: var(--mocha-deep);
      font-weight: 700;
      font-size: 12px;
      padding: 4px 8px;
      border-radius: 12px;
      margin-left: 8px;
    }

    .pos-clock {
      font-family: var(--font-mono);
      font-size: 18px;
      color: var(--gold-light);
      font-weight: 600;
    }

    /* ── MAIN ── */
    .pos-main {
      min-height: calc(100vh - 76px);
      padding: 24px;
      padding-bottom: 110px;
    }

    /* ── PRODUCTS ── */
    .section-header {
      margin-bottom: 20px;
    }

    .section-title {
      font-family: var(--font-display);
      font-size: 20px;
      color: var(--mocha-deep);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .category-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 20px;
    }

    .cat-tab {
      padding: 10px 20px;
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      color: var(--charcoal-mid);
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .cat-tab.active {
      background: var(--mocha);
      color: var(--cream);
      border-color: var(--mocha);
    }

    .products-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 16px;
    }

    .product-btn {
      width: 100%;
      padding: 20px 12px;
      background: var(--cream-light);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
      font-family: var(--font-body);
    }

    .product-btn:hover,
    .product-btn.added {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
      border-color: var(--sage);
      background: rgba(122, 158, 126, .08);
    }

    .product-icon {
      font-size: 28px;
      color: var(--mocha);
      margin-bottom: 8px;
      height: 100px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .product-icon img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }

    .product-name {
      font-weight: 600;
      color: var(--mocha-deep);
      margin-bottom: 4px;
      font-size: 13px;
    }

    .product-price {
      font-family: var(--font-mono);
      font-size: 16px;
      font-weight: 700;
      color: var(--gold);
    }

    /* ── CART ── */
    .mini-cart {
      position: fixed;
      left: calc(var(--sidebar-w) + 24px);
      bottom: 24px;
      z-index: 500;
      display: flex;
      align-items: center;
      gap: 16px;
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      padding: 12px 16px;
      box-shadow: var(--shadow-lg);
    }

    .mini-cart-clear {
      width: 40px;
      height: 40px;
      flex-shrink: 0;
      border-radius: var(--radius);
      border: 1.5px solid var(--cream-dark);
      background: transparent;
      color: var(--red-soft);
      font-size: 15px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .2s;
    }

    .mini-cart-clear:hover {
      background: var(--red-soft);
      border-color: var(--red-soft);
      color: #fff;
    }

    .mini-cart-total {
      display: flex;
      flex-direction: column;
      line-height: 1.25;
    }

    .mini-cart-total-label {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      color: #888;
    }

    .mini-cart-total-value {
      font-family: var(--font-mono);
      font-size: 20px;
      font-weight: 800;
      color: var(--mocha-deep);
    }

    .cart-total-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 0 8px;
      border-top: 2px solid var(--cream-dark);
      margin-top: 12px;
    }

    .total-label {
      font-size: 14px;
      font-weight: 600;
      color: var(--charcoal);
    }

    .grand-total {
      font-family: var(--font-display);
      font-size: 28px;
      font-weight: 800;
      color: var(--mocha-deep);
    }

    .checkout-btn {
      padding: 14px 24px;
      background: var(--gold);
      color: var(--mocha-deep);
      border: none;
      border-radius: var(--radius);
      font-family: var(--font-body);
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: all .3s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      white-space: nowrap;
    }

    .checkout-btn:hover:not(:disabled) {
      background: var(--gold-light);
      transform: translateY(-3px);
      box-shadow: var(--shadow-lg);
    }

    .checkout-btn:disabled {
      background: var(--charcoal-mid);
      color: var(--cream);
      cursor: not-allowed;
      opacity: .6;
    }

    /* ── CHECKOUT MODAL ── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: rgba(44, 44, 44, .75);
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
      padding: 32px;
      max-width: 520px;
      width: 90%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-lg);
      animation: modalPop .25s cubic-bezier(.34, 1.56, .64, 1);
    }

    @keyframes modalPop {
      from {
        opacity: 0;
        transform: scale(.9) translateY(20px);
      }

      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
    }

    .modal-title {
      font-family: var(--font-display);
      font-size: 22px;
      color: var(--mocha-deep);
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      color: var(--charcoal-mid);
      cursor: pointer;
      padding: 4px;
    }

    .order-items {
      margin-bottom: 20px;
      max-height: 200px;
      overflow-y: auto;
    }

    .order-item {
      display: flex;
      justify-content: space-between;
      padding: 12px 0;
      border-bottom: 1px solid var(--cream-dark);
    }

    .discount-section {
      margin: 20px 0;
    }

    .discount-option {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
      cursor: pointer;
    }

    .discount-checkbox {
      width: 20px;
      height: 20px;
      accent-color: var(--gold);
    }

    .discount-total-row {
      font-weight: 700;
      font-size: 16px;
    }

    .payment-options {
      margin: 20px 0;
    }

    .payment-option {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      cursor: pointer;
      margin-bottom: 8px;
      transition: all .2s;
    }

    .payment-option:hover {
      border-color: var(--gold);
    }

    .payment-option.active {
      border-color: var(--gold);
      background: rgba(201, 148, 58, .08);
    }

    .payment-radio {
      width: 18px;
      height: 18px;
      accent-color: var(--gold);
    }

    .cash-section {
      margin: 20px 0;
    }

    .cash-input {
      width: 100%;
      padding: 12px 14px;
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      font-family: var(--font-mono);
      font-size: 16px;
      font-weight: 700;
      color: var(--charcoal);
      outline: none;
      transition: border-color .2s;
    }

    .cash-input:focus {
      border-color: var(--gold);
    }

    .cash-input:disabled {
      background: var(--cream-dark);
      color: var(--charcoal-mid);
      cursor: not-allowed;
    }

    .ewallet-qr-section {
      margin: 20px 0;
      text-align: center;
      padding: 16px;
      background: var(--cream-light);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
    }

    .ewallet-qr-section img {
      max-width: 200px;
      width: 100%;
      border-radius: 8px;
      border: 1.5px solid var(--cream-dark);
    }

    .ewallet-qr-empty {
      font-size: 12px;
      color: #999;
      padding: 20px 10px;
    }

    .change-preview {
      margin-top: 8px;
      font-size: 13px;
      font-weight: 600;
      color: var(--sage);
    }

    .change-preview.negative {
      color: var(--red-soft);
    }

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
      background: var(--charcoal-mid);
      color: #fff;
      font-size: 13px;
      font-weight: 500;
      padding: 11px 16px;
      border-radius: 10px;
      box-shadow: var(--shadow-lg);
      display: flex;
      align-items: center;
      gap: 8px;
      animation: toastIn .28s ease;
    }

    .toast-msg.success {
      border-left: 3px solid var(--sage);
    }

    .toast-msg.warn {
      border-left: 3px solid var(--red-soft);
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

    .modal-actions {
      display: flex;
      gap: 12px;
      margin-top: 24px;
    }

    .btn-confirm {
      flex: 1;
      padding: 14px;
      background: var(--gold);
      color: var(--mocha-deep);
      border: none;
      border-radius: var(--radius);
      font-weight: 700;
      font-size: 15px;
      cursor: pointer;
      transition: all .2s;
    }

    .btn-confirm:hover {
      background: var(--gold-light);
    }

    .btn-cancel {
      flex: 1;
      padding: 14px;
      background: transparent;
      color: var(--charcoal-mid);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      font-weight: 600;
      cursor: pointer;
    }

    .btn-cancel:hover {
      border-color: var(--red-soft);
      color: var(--red-soft);
    }

    @media (max-width: 1024px) {
      .pos-main {
        padding: 16px;
        padding-bottom: 110px;
      }
    }
  </style>
</head>

<body>
  <!-- POS CONTENT -->
  <header id="app-header">
    <div class="header-brand">
      <div class="brand-logo"><i class="fas fa-mug-hot"></i></div>
      <div class="brand-text">
        <div class="name">SmartStock</div>
        <div class="sub">Bean There Café</div>
      </div>
    </div>
    <div class="header-center">
      <span class="portal-badge">Staff Panel</span>
      <span class="header-view-label">Point of Sale</span>
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

  <nav id="sidebar">
    <div class="sidebar-section-label">Staff Panel</div>
    <a href="staffdashboard.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="staff_transactions.php" class="nav-item active"><i class="fas fa-cash-register"></i> Transaction</a>
    <a href="staff_products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
    <a href="staff_inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory</a>
    <a href="staff_reports.php" class="nav-item"><i class="fas fa-chart-bar"></i>Sales Report</a>
    <hr class="sidebar-divider" />
    
    <div class="sidebar-footer">
      <p>SmartStock v1.0<br />Bean There Café</p>
    </div>
  </nav>

  <div id="main">
    <div class="page-strip">
      <div>
        <h1><i class="fas fa-cash-register" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Transaction
        </h1>
        <div class="sub" id="pos-date">Live Sales System</div>
      </div>
      <button class="btn-primary" onclick="clearCart()">
        <i class="fas fa-arrows-rotate"></i> New Sale
      </button>
    </div>
    <div style="padding:22px 26px;">
      <main class="pos-main">
        <section>
          <div class="section-header">
            <h1 class="section-title">
              <i class="fas fa-mug-hot" style="color: var(--gold);"></i>Menu Items
            </h1>
          </div>
          <div class="category-tabs">
            <div class="cat-tab active" data-cat="all">All</div>
            <?php foreach ($categories as $cat): ?>
              <div class="cat-tab" data-cat="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></div>
            <?php endforeach; ?>
          </div>
          <div class="products-grid" id="products-grid"></div>
          <script id="products-data" type="application/json"><?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
        </section>
      </main>
      <div class="mini-cart" id="mini-cart">
        <button id="clear-cart" class="mini-cart-clear" title="Clear cart"><i class="fas fa-trash"></i></button>
        <div class="mini-cart-total">
          <span class="mini-cart-total-label">Total</span>
          <span class="mini-cart-total-value" id="mini-cart-total-value">₱0.00</span>
        </div>
        <button id="checkout-btn" class="checkout-btn" disabled><i class="fas fa-credit-card"></i> Checkout</button>
      </div>
      <div id="flavor-choice-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 360px;">
          <div class="modal-header">
            <h2 class="modal-title" id="flavor-choice-title">Choose a flavor</h2>
            <button class="modal-close" onclick="closeFlavorPicker()">&times;</button>
          </div>
          <div id="flavor-choice-list" style="margin: 16px 0;"></div>
          <div class="modal-actions">
            <button class="btn-cancel" onclick="closeFlavorPicker()">Cancel</button>
            <button class="btn-confirm" onclick="confirmFlavorChoice()">Add to Cart</button>
          </div>
        </div>
      </div>
      <div id="receipt-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 400px; border: 2px dashed var(--cream-dark);">
          <div style="text-align: center; margin-bottom: 20px;">
            <div class="pos-logo" style="margin: 0 auto 10px;"><i class="fas fa-mug-hot"></i></div>
            <div style="font-family: var(--font-display); font-weight: 700; color: var(--mocha-deep); font-size: 15px;"><?= htmlspecialchars($settings['cafe_name']) ?></div>
            <?php if ($settings['cafe_address'] !== ''): ?>
              <div style="font-size: 11px; color: var(--charcoal-mid);"><?= htmlspecialchars($settings['cafe_address']) ?></div>
            <?php endif; ?>
            <?php if ($settings['cafe_contact'] !== ''): ?>
              <div style="font-size: 11px; color: var(--charcoal-mid);"><?= htmlspecialchars($settings['cafe_contact']) ?></div>
            <?php endif; ?>
            <h2 class="modal-title" style="letter-spacing: 2px;margin-top:10px;">RECEIPT</h2>
            <p style="font-size: 12px; color: var(--charcoal-mid);" id="receipt-date"></p>
          </div>

          <div id="receipt-content" style="font-family: var(--font-mono); font-size: 14px;">
          </div>

          <div
            style="margin-top: 24px; text-align: center; border-top: 2px dashed var(--cream-dark); padding-top: 20px;">
            <?php if ($settings['receipt_footer_message'] !== ''): ?>
              <p style="font-family: var(--font-display); font-weight: 700; color: var(--mocha);"><?= htmlspecialchars($settings['receipt_footer_message']) ?>
              </p>
            <?php endif; ?>
            <button class="btn-confirm" style="margin-top: 15px; width: 100%;" onclick="closeReceiptModal()">Done & New
              Sale</button>
          </div>
        </div>
      </div>
      <div id="checkout-modal" class="modal-overlay">
        <div class="modal-box">
          <div class="modal-header">
            <h2 class="modal-title">Checkout</h2>
            <button class="modal-close" onclick="closeCheckoutModal()">&times;</button>
          </div>
          <div id="order-items" class="order-items"></div>

          <div id="totals-breakdown"></div>
          <div class="discount-section">
            <h4
              style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px;">
              Discounts</h4>
            <label class="discount-option">
              <input type="checkbox" class="discount-checkbox" id="pwd-discount">
              <span>Person with Disability (20%)</span>
            </label>
            <label class="discount-option">
              <input type="checkbox" class="discount-checkbox" id="senior-discount">
              <span>Senior Citizen (20%)</span>
            </label>
          </div>
          <div class="payment-options">
            <h4
              style="font-family: var(--font-display); font-size: 16px; color: var(--mochadeep); margin-bottom: 12px;">
              Payment Method</h4>
            <div class="payment-option active" data-payment="cash">
              <input type="radio" class="payment-radio" name="payment" value="cash" checked>
              <div><i class="fas fa-money-bill-wave" style="font-size: 20px;"></i> Cash</div>
            </div>
            <div class="payment-option" data-payment="ewallet">
              <input type="radio" class="payment-radio" name="payment" value="ewallet">
              <div><i class="fas fa-mobile-alt" style="font-size: 20px;"></i> E-Wallet (Gcash)</div>
            </div>
          </div>
          <div class="ewallet-qr-section" id="ewallet-qr-section" style="display:none;">
            <h4
              style="font-family: var(--font-display); font-size: 14px; color: var(--mocha-deep); margin-bottom: 10px;">
              Scan to Pay via GCash</h4>
            <?php if ($settings['ewallet_qr_image'] !== ''): ?>
              <img src="../<?= htmlspecialchars($settings['ewallet_qr_image']) ?>" alt="GCash QR code">
            <?php else: ?>
              <div class="ewallet-qr-empty">No QR code has been uploaded yet. Ask the owner to add one in System Settings.</div>
            <?php endif; ?>
          </div>
          <div class="cash-section">
            <h4
              style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px;">
              Amount Tendered</h4>
            <input type="number" id="amount-tendered-input" class="cash-input" min="0" step="0.01" placeholder="0.00">
            <div id="change-preview" class="change-preview"></div>
          </div>
          <div class="modal-actions">
            <button class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
            <button class="btn-confirm" onclick="confirmCheckout()">Complete Sale</button>
          </div>
        </div>
      </div>
      <div id="toast-container"></div>
      <script>
        // PRODUCTS — loaded from the products table (see products-data script tag above)
        const products = JSON.parse(document.getElementById('products-data').textContent);
        function iconFor(categoryName) {
          const c = (categoryName || '').toLowerCase();
          if (c.includes('iced') || c.includes('cold')) return '🧊';
          if (c.includes('hot')) return '☕';
          if (c.includes('pastr') || c.includes('bread') || c.includes('bake')) return '🥐';
          return '🍽️';
        }
        let cart = [];
        const TAX_RATE = <?= (float)$settings['tax_rate'] ?>;
        const DISCOUNT_RATE = <?= (float)$settings['discount_rate'] ?>;
        // DOM
        const productsGrid = document.getElementById('products-grid');
        const miniCartTotal = document.getElementById('mini-cart-total-value');
        const cartBadge = document.getElementById('cart-count');
        const checkoutBtn = document.getElementById('checkout-btn');
        const clearCartBtn = document.getElementById('clear-cart');
        const checkoutModal = document.getElementById('checkout-modal');
        const clockEl = document.getElementById('clock');
        const amountInput = document.getElementById('amount-tendered-input');
        const changePreview = document.getElementById('change-preview');
        let amountManuallyEdited = false;
        let currentPaymentMethod = 'cash';
        document.addEventListener('DOMContentLoaded', () => {
          renderProducts();
          updateClock();
          setInterval(updateClock, 1000);

          document.querySelectorAll('.cat-tab').forEach(tab => {
            tab.addEventListener('click', e => switchCategory(e.currentTarget.dataset.cat, e.currentTarget));
          });

          clearCartBtn.addEventListener('click', clearCart);
          checkoutBtn.addEventListener('click', showCheckoutModal);

          // Modal interactions
          document.querySelectorAll('.discount-checkbox').forEach(cb => cb.addEventListener('change', updateCheckoutTotals));
          document.querySelectorAll('.payment-option').forEach(opt => opt.addEventListener('click', e => selectPayment(e.currentTarget.dataset.payment, e.currentTarget)));
          amountInput.addEventListener('input', () => { amountManuallyEdited = true; updateChangePreview(); });
        });
        function renderProducts(category = 'all') {
          const filtered = products.filter(p => category === 'all' || String(p.category_id) === String(category));

          if (products.length === 0) {
            productsGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#aaa;padding:40px 20px;">No menu items yet. Add products from the Products page first.</div>';
            return;
          }
          if (filtered.length === 0) {
            productsGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#aaa;padding:40px 20px;">No items in this category.</div>';
            return;
          }

          productsGrid.innerHTML = filtered.map(p => {
            // Made-to-order items aren't tracked by product_stocks — availability depends on ingredient
            // stock instead, which is checked server-side at checkout.
            const isMotd = p.type === 'made_to_order';
            const outOfStock = !isMotd && p.stock <= 0;
            return `<button class="product-btn" data-product-id="${p.id}" ${outOfStock ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''}>
          <div class="product-icon">${p.image ? `<img src="${p.image}" alt="">` : iconFor(p.category_name)}</div>
          <div class="product-name">${p.name}</div>
          <div class="product-price">₱${p.price.toLocaleString()}</div>
          <div style="font-size:11px;color:${outOfStock ? 'var(--red-soft)' : '#aaa'};margin-top:4px;">${isMotd ? 'Made to order' : (outOfStock ? 'Out of stock' : p.stock + ' in stock')}</div>
        </button>`;
          }).join('');

          document.querySelectorAll('.product-btn').forEach(btn => {
            if (!btn.disabled) btn.onclick = () => addToCart(parseInt(btn.dataset.productId));
          });
        }
        function switchCategory(cat, el) {
          document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
          el.classList.add('active');
          renderProducts(cat);
        }
        let pendingFlavorProduct = null;
        function addToCart(id) {
          const product = products.find(p => p.id === id);
          if (!product) return;
          const isMotd = product.type === 'made_to_order';
          if (!isMotd && product.stock <= 0) return;

          if (product.flavor_options && product.flavor_options.length > 0) {
            openFlavorPicker(product);
            return;
          }
          addToCartFinal(product, null, null);
        }
        function openFlavorPicker(product) {
          pendingFlavorProduct = product;
          document.getElementById('flavor-choice-title').textContent = 'Choose a flavor for ' + product.name;
          document.getElementById('flavor-choice-list').innerHTML = product.flavor_options.map((opt, i) => `
      <label style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--cream-dark);cursor:pointer;font-size:14px;">
        <input type="radio" name="flavor-choice" value="${opt.id}" ${i === 0 ? 'checked' : ''} />
        <span>${opt.name}</span>
      </label>
    `).join('');
          document.getElementById('flavor-choice-modal').classList.add('show');
        }
        function closeFlavorPicker() {
          document.getElementById('flavor-choice-modal').classList.remove('show');
          pendingFlavorProduct = null;
        }
        function confirmFlavorChoice() {
          const selected = document.querySelector('input[name="flavor-choice"]:checked');
          if (!selected || !pendingFlavorProduct) return;
          const opt = pendingFlavorProduct.flavor_options.find(o => String(o.id) === selected.value);
          if (!opt) return;
          addToCartFinal(pendingFlavorProduct, opt.id, opt.name);
          closeFlavorPicker();
        }
        function addToCartFinal(product, flavorId, flavorName) {
          const isMotd = product.type === 'made_to_order';
          // A product sold with different flavors needs separate cart lines so quantities and
          // stock checks don't get mixed between flavors.
          const cartKey = flavorId ? `${product.id}:${flavorId}` : String(product.id);
          const item = cart.find(i => i.cartKey === cartKey);
          const currentQty = item ? item.qty : 0;
          if (!isMotd && currentQty >= product.stock) {
            showToast(`Only ${product.stock} unit(s) of ${product.name} available.`, 'warn');
            return;
          }
          if (item) item.qty++;
          else cart.push({ ...product, qty: 1, cartKey, flavor_ingredient_id: flavorId, flavor_name: flavorName });
          renderCart();
          updateBadge();
        }
        function updateQty(cartKey, delta) {
          const item = cart.find(i => i.cartKey === cartKey);
          if (!item) return;
          const product = products.find(p => p.id === item.id);
          if (delta > 0 && product && product.type !== 'made_to_order' && item.qty >= product.stock) {
            showToast(`Only ${product.stock} unit(s) of ${product.name} available.`, 'warn');
            return;
          }
          item.qty += delta;
          if (item.qty <= 0) cart = cart.filter(i => i.cartKey !== cartKey);
          renderCart();
          updateBadge();
        }
        function clearCart() {
          cart = [];
          renderCart();
          updateBadge();
        }
        function renderCart() {
          // The persistent cart is now a compact bar (Clear / Total / Checkout only) — the
          // itemized breakdown still shows up in the Checkout modal via renderOrderSummary().
          const total = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
          miniCartTotal.textContent = '₱' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          checkoutBtn.disabled = cart.length === 0;
        }
        function updateBadge() {
          if (!cartBadge) return;
          const count = cart.reduce((sum, i) => sum + i.qty, 0);
          cartBadge.textContent = count;
        }
        function showCheckoutModal() {
          if (cart.length === 0) return;
          amountManuallyEdited = false;
          checkoutModal.classList.add('show');
          renderOrderSummary();
          updateCheckoutTotals();
        }
        function closeCheckoutModal() {
          checkoutModal.classList.remove('show');
        }
        function computeFinalPayable() {
          const totalOriginal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
          const hasDiscount = document.getElementById('pwd-discount').checked || document.getElementById('senior-discount').checked;
          const discountAmount = hasDiscount ? totalOriginal * DISCOUNT_RATE : 0;
          return totalOriginal - discountAmount;
        }
        function updateChangePreview() {
          const finalPayable = computeFinalPayable();
          const tendered = parseFloat(amountInput.value);
          if (isNaN(tendered)) {
            changePreview.textContent = '';
            changePreview.classList.remove('negative');
            return;
          }
          if (currentPaymentMethod === 'ewallet') {
            changePreview.textContent = 'Exact amount charged via E-Wallet — no change due';
            changePreview.classList.remove('negative');
            return;
          }
          const change = tendered - finalPayable;
          if (change < 0) {
            changePreview.textContent = `Insufficient — need ₱${Math.abs(change).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} more`;
            changePreview.classList.add('negative');
          } else {
            changePreview.textContent = `Change: ₱${change.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            changePreview.classList.remove('negative');
          }
        }
        function renderOrderSummary() {
          document.getElementById('order-items').innerHTML = cart.map(item => {
            const total = item.price * item.qty;
            return `<div class="order-item">
          <span>${item.name}${item.flavor_name ? ' (' + item.flavor_name + ')' : ''} × ${item.qty}</span>
          <span>₱${total.toLocaleString()}</span>
        </div>`;
          }).join('');
        }
        function updateCheckoutTotals() {
          const totalOriginal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);

          // Calculate discount if applicable
          const hasDiscount = document.getElementById('pwd-discount').checked || document.getElementById('senior-discount').checked;
          const discountAmount = hasDiscount ? totalOriginal * DISCOUNT_RATE : 0;

          const finalPayable = totalOriginal - discountAmount;

          // Calculate VAT breakdown of the final amount
          const netAmount = finalPayable / (1 + TAX_RATE);
          const vatAmount = finalPayable - netAmount;

          document.getElementById('totals-breakdown').innerHTML = `
    <div class="cart-total-row">
      <span class="total-label">Gross Amount</span>
      <span>₱${totalOriginal.toLocaleString()}</span>
    </div>
    ${discountAmount > 0 ? `<div class="cart-total-row">
      <span class="total-label" style="color: var(--sage);">Discount (20%)</span>
      <span style="color: var(--sage);">−₱${discountAmount.toLocaleString()}</span>
    </div>` : ''}
    <div class="cart-total-row">
      <span class="total-label">Net of VAT</span>
      <span>₱${netAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
    </div>
    <div class="cart-total-row">
      <span class="total-label">VAT (12%)</span>
      <span>₱${vatAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
    </div>
    <div class="cart-total-row discount-total-row">
      <span class="grand-total">Total to Pay</span>
      <span style="font-family: var(--font-mono); font-size: 24px; font-weight: 800; color: var(--gold);">₱${finalPayable.toLocaleString()}</span>
    </div>
  `;
          if (currentPaymentMethod === 'ewallet') {
            amountInput.value = finalPayable.toFixed(2);
            amountInput.disabled = true;
          } else {
            amountInput.disabled = false;
            if (!amountManuallyEdited) amountInput.value = finalPayable.toFixed(2);
          }
          updateChangePreview();
        }
        function selectPayment(method, el) {
          document.querySelectorAll('.payment-option').forEach(opt => opt.classList.remove('active'));
          el.classList.add('active');
          currentPaymentMethod = method;
          document.getElementById('ewallet-qr-section').style.display = method === 'ewallet' ? 'block' : 'none';
          updateCheckoutTotals();
        }
        async function confirmCheckout() {
          const totalOriginal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
          const hasDiscount = document.getElementById('pwd-discount').checked || document.getElementById('senior-discount').checked;
          const discountVal = hasDiscount ? totalOriginal * DISCOUNT_RATE : 0;
          const finalPayable = totalOriginal - discountVal;
          const paymentMethod = document.querySelector('.payment-option.active').dataset.payment;

          // 1. Read the amount tendered from the in-page field
          const amountTendered = parseFloat(amountInput.value);

          if (isNaN(amountTendered) || amountTendered < finalPayable) {
            showToast('Insufficient amount entered.', 'warn');
            amountInput.focus();
            return;
          }

          const change = amountTendered - finalPayable;

          // 1b. Persist the sale to the database
          const confirmBtn = document.querySelector('#checkout-modal .btn-confirm');
          confirmBtn.disabled = true;
          confirmBtn.textContent = 'Processing…';

          let serverResult;
          try {
            const response = await fetch('staff_transactions.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({
                action: 'checkout',
                cart: JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty, flavor_ingredient_id: i.flavor_ingredient_id || null }))),
                payment_method: paymentMethod,
                discount: hasDiscount ? '1' : ''
              })
            });
            serverResult = await response.json();
          } catch (err) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Complete Sale';
            showToast('Could not reach the server. Please try again.', 'warn');
            return;
          }

          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Complete Sale';

          if (!serverResult || !serverResult.success) {
            showToast(serverResult && serverResult.error ? serverResult.error : 'Transaction failed.', 'warn');
            return;
          }

          // 2. Build Receipt UI
          document.getElementById('receipt-date').textContent = new Date().toLocaleString();

          const itemsHtml = cart.map(i => `
    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
      <span>${i.name}${i.flavor_name ? ' (' + i.flavor_name + ')' : ''} x${i.qty}</span>
      <span>₱${(i.price * i.qty).toLocaleString()}</span>
    </div>
  `).join('');

          document.getElementById('receipt-content').innerHTML = `
    <div style="text-align:center;font-size:11px;color:#999;margin-bottom:10px;">Transaction #${serverResult.transaction_id}</div>
    <div style="border-bottom: 1px solid var(--cream-dark); padding-bottom: 10px; margin-bottom: 10px;">
      ${itemsHtml}
    </div>
    <div style="display: flex; justify-content: space-between;">
      <span>Subtotal:</span>
      <span>₱${totalOriginal.toLocaleString()}</span>
    </div>
    ${hasDiscount ? `
    <div style="display: flex; justify-content: space-between; color: var(--red-soft);">
      <span>Discount (20%):</span>
      <span>-₱${discountVal.toLocaleString()}</span>
    </div>` : ''}
    <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 18px; margin: 10px 0; color: var(--mocha-deep);">
      <span>TOTAL:</span>
      <span>₱${finalPayable.toLocaleString()}</span>
    </div>
    <div style="display: flex; justify-content: space-between; font-size: 13px; color: var(--charcoal-mid);">
      <span>Paid (${paymentMethod.toUpperCase()}):</span>
      <span>₱${amountTendered.toLocaleString()}</span>
    </div>
    <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--sage); margin-bottom: 10px;">
      <span>CHANGE:</span>
      <span>₱${change.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
    </div>
    <div style="font-size: 11px; color: #999; text-align: center; margin-top: 15px;">
      VAT Included (12%): ₱${(finalPayable - (finalPayable / 1.12)).toLocaleString(undefined, { minimumFractionDigits: 2 })}
    </div>
  `;

          // 3. Show Modal
          closeCheckoutModal();
          document.getElementById('receipt-modal').classList.add('show');
        }

        // Reload so the product list reflects the stock the sale just consumed
        function closeReceiptModal() {
          location.reload();
        }
        function updateClock() {
          clockEl.textContent = new Date().toLocaleTimeString('en-PH', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
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