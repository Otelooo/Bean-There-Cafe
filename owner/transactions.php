<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';
require_once __DIR__ . '/../unit_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

$settings = get_system_settings($conn);

// Feeds the "Inventory" nav-badge — ingredients at critical or low stock.
$navLowStockThreshold = (float)$settings['low_stock_threshold'];
$navStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE (ingredient_stock_reference IS NULL AND ingredient_stock <= 0) OR (ingredient_stock_reference > 0 AND (ingredient_stock / ingredient_stock_reference) * 100 <= ?)");
$navStmt->bind_param('d', $navLowStockThreshold);
$navStmt->execute();
$ingredientAlertCount = (int)$navStmt->get_result()->fetch_assoc()['cnt'];
$navStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    header('Content-Type: application/json');

    $rawCart = json_decode($_POST['cart'] ?? '[]', true);
    $paymentMethod = ($_POST['payment_method'] ?? 'cash') === 'ewallet' ? 'online' : 'cash';
    $discountRate = !empty($_POST['discount']) ? (float)$settings['discount_rate'] : 0.0;

    if (!is_array($rawCart) || count($rawCart) === 0) {
        echo json_encode(['success' => false, 'error' => 'Cart is empty.']);
        exit;
    }

    // Each cart line is its own product+size+flavor combination, so the same product can appear
    // more than once (e.g. a Small and a Large of the same coffee, or two Fries with different
    // flavors chosen).
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
        $variantRaw = $line['variant_id'] ?? null;
        $variantId = ($variantRaw !== null && $variantRaw !== '') ? (int)$variantRaw : null;
        $sugarLevel = trim((string)($line['sugar_level'] ?? ''));
        $cartLines[] = ['product_id' => $pid, 'qty' => $qty, 'flavor_ingredient_id' => $flavorId, 'variant_id' => $variantId, 'sugar_level' => $sugarLevel !== '' ? $sugarLevel : null];
        $totalQtyByProduct[$pid] = ($totalQtyByProduct[$pid] ?? 0) + $qty;
    }

    $conn->begin_transaction();
    try {
        $ids = array_keys($totalQtyByProduct);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT product_id, product_name, product_selling_price, product_stocks, product_type, sugar_level_options FROM products WHERE product_id IN ($placeholders) FOR UPDATE");
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
            // by ingredient stock only.
            if ($product['product_type'] !== 'made_to_order' && (int)$product['product_stocks'] < $totalQty) {
                throw new RuntimeException('Not enough stock for ' . $product['product_name'] . '.');
            }
        }

        // Sizes/options each carry their own price, which always wins over the product's base
        // price for that line — re-fetched here (never trusted from the client) and checked
        // against the product it was picked for, so a tampered request can't apply another
        // product's price.
        $variantIds = [];
        foreach ($cartLines as $line) {
            if ($line['variant_id'] !== null) {
                $variantIds[] = $line['variant_id'];
            }
        }
        $variantsById = [];
        if ($variantIds) {
            $variantIds = array_values(array_unique($variantIds));
            $varPlaceholders = implode(',', array_fill(0, count($variantIds), '?'));
            $varTypes = str_repeat('i', count($variantIds));
            $varStmt = $conn->prepare("SELECT product_variant_id, product_id, variant_name, variant_price FROM product_variants WHERE product_variant_id IN ($varPlaceholders)");
            $varStmt->bind_param($varTypes, ...$variantIds);
            $varStmt->execute();
            $varResult = $varStmt->get_result();
            while ($row = $varResult->fetch_assoc()) {
                $variantsById[(int)$row['product_variant_id']] = $row;
            }
            $varStmt->close();
        }

        // Products that have at least one size/option defined require the cashier to pick one —
        // enforced server-side too, in case the size picker was bypassed client-side.
        $productIdsWithVariants = [];
        $vCheckStmt = $conn->prepare("SELECT DISTINCT product_id FROM product_variants WHERE product_id IN ($placeholders)");
        $vCheckStmt->bind_param($types, ...$ids);
        $vCheckStmt->execute();
        $vCheckResult = $vCheckStmt->get_result();
        while ($row = $vCheckResult->fetch_assoc()) {
            $productIdsWithVariants[(int)$row['product_id']] = true;
        }
        $vCheckStmt->close();

        // Load each cart product's recipe once: required ingredients (always consumed) and
        // flavor-choice options (exactly one consumed — whichever the cashier picked).
        $recipeByProduct = [];
        $recipeStmt = $conn->prepare('
            SELECT pii.product_id, pii.product_ingredients_id, pii.quantity, pii.unit, pii.is_flavor_choice,
                   pi.ingredient_name, pi.ingredient_unit
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
                    'recipe_unit' => $row['unit'],
                    'stock_unit' => $row['ingredient_unit'],
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

            $chosenVariantName = null;
            $unitPrice = (float)$product['product_selling_price'];
            if ($line['variant_id'] !== null) {
                $variant = $variantsById[$line['variant_id']] ?? null;
                if (!$variant || (int)$variant['product_id'] !== $pid) {
                    throw new RuntimeException('Invalid size/option selected for ' . $product['product_name'] . '.');
                }
                $unitPrice = (float)$variant['variant_price'];
                $chosenVariantName = $variant['variant_name'];
            } elseif (isset($productIdsWithVariants[$pid])) {
                throw new RuntimeException('Please choose a size/option for ' . $product['product_name'] . '.');
            }
            $subtotal = $unitPrice * $qty;
            $grossTotal += $subtotal;

            $chosenIngredientName = null;
            $hasChoiceGroup = false;
            foreach ($recipeByProduct[$pid] ?? [] as $r) {
                // Recipe quantities are entered in whatever unit made sense on the product form
                // (e.g. Milliliters), but stock is tracked in the ingredient's own unit (e.g.
                // Liters) — convert before deducting so the two units don't get conflated.
                if (!$r['is_choice']) {
                    $neededPerSale = convert_quantity($r['quantity'], $r['recipe_unit'], $r['stock_unit']);
                    $needed = $neededPerSale * $qty;
                    $ingredientNeeds[$r['ingredient_id']] = ($ingredientNeeds[$r['ingredient_id']] ?? 0) + $needed;
                    continue;
                }
                $hasChoiceGroup = true;
                if ($r['ingredient_id'] === $line['flavor_ingredient_id']) {
                    $neededPerSale = convert_quantity($r['quantity'], $r['recipe_unit'], $r['stock_unit']);
                    $needed = $neededPerSale * $qty;
                    $ingredientNeeds[$r['ingredient_id']] = ($ingredientNeeds[$r['ingredient_id']] ?? 0) + $needed;
                    $chosenIngredientName = $r['ingredient_name'];
                }
            }

            if ($hasChoiceGroup && $chosenIngredientName === null) {
                throw new RuntimeException('Please choose a flavor for ' . $product['product_name'] . '.');
            }

            $allowedSugarLevels = json_decode($product['sugar_level_options'] ?? '[]', true) ?: [];
            $chosenSugarLevel = $line['sugar_level'];
            if ($allowedSugarLevels && !in_array($chosenSugarLevel, $allowedSugarLevels, true)) {
                throw new RuntimeException('Please choose a sugar level for ' . $product['product_name'] . '.');
            }
            if (!$allowedSugarLevels && $chosenSugarLevel !== null) {
                throw new RuntimeException('Invalid sugar level selected for ' . $product['product_name'] . '.');
            }

            $lineItems[] = [
                'product_id' => $pid,
                'product_name' => $product['product_name'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'chosen_ingredient_name' => $chosenIngredientName,
                'chosen_variant_name' => $chosenVariantName,
                'chosen_sugar_level' => $chosenSugarLevel,
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

        // Payment figures are calculated again on the server.  This keeps the stored
        // tender/change values in sync with the authoritative total rather than trusting
        // the receipt preview in the browser.
        if ($paymentMethod === 'online') {
            $amountTendered = $finalTotal;
        } else {
            $amountTenderedRaw = $_POST['amount_tendered'] ?? '';
            if (!is_numeric($amountTenderedRaw)) {
                throw new RuntimeException('Enter the amount tendered.');
            }
            $amountTendered = round((float)$amountTenderedRaw, 2);
            if ($amountTendered < $finalTotal) {
                throw new RuntimeException('Amount tendered is insufficient.');
            }
        }
        // max() guarantees an exact payment is always recorded as 0.00, never a negative
        // floating-point residue.
        $amountChange = max(0, round($amountTendered - $finalTotal, 2));

        $userId = (int)$_SESSION['user_id'];
        $cashierUsername = $_SESSION['username'] ?? '';
        $orderType = $_POST['order_type'] ?? 'dine_in';
        if (!in_array($orderType, ['dine_in', 'takeout'], true)) {
            $orderType = 'dine_in';
        }
        $tableNumberRaw = trim($_POST['table_number'] ?? '');
        $tableNumber = null;
        if ($tableNumberRaw !== '') {
            if (filter_var($tableNumberRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]) === false) {
                throw new RuntimeException('Table number must be between 1 and 999.');
            }
            $tableNumber = (int)$tableNumberRaw;
        }
        $notes = trim($_POST['notes'] ?? '');
        $notes = $notes !== '' ? $notes : null;
        // Snapshot of exactly what was deducted per ingredient, so a later cancellation can restore
        // stock precisely even if the recipe (product_ingredient_items) has changed since this sale.
        $ingredientUsageSnapshot = json_encode($ingredientNeeds);
        $insertTxn = $conn->prepare("INSERT INTO transactions (user_id, cashier_username, transaction_date, transaction_total, transaction_status, payment_method, discount, amount_tendered, amount_change, order_type, table_number, notes, ingredient_usage_snapshot) VALUES (?, ?, NOW(), ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertTxn->bind_param('isdsdddsiss', $userId, $cashierUsername, $finalTotal, $paymentMethod, $discountAmount, $amountTendered, $amountChange, $orderType, $tableNumber, $notes, $ingredientUsageSnapshot);
        $insertTxn->execute();
        $transactionId = $insertTxn->insert_id;
        $insertTxn->close();

        // product_name_snapshot / chosen_ingredient_name_snapshot / variant_name_snapshot preserve
        // what was actually sold even if the product, ingredient, or size/option is deleted later.
        $insertItem = $conn->prepare('INSERT INTO transaction_items (transaction_id, product_id, product_name_snapshot, chosen_ingredient_name_snapshot, variant_name_snapshot, sugar_level_snapshot, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        // Only "prepared" products track their own stock; made-to-order items have no product_stocks
        // to decrement (their ingredient stock is decremented below instead).
        $updateStock = $conn->prepare("UPDATE products SET product_stocks = product_stocks - ? WHERE product_id = ? AND product_type = 'prepared'");
        foreach ($lineItems as $item) {
            $insertItem->bind_param('iissssidd', $transactionId, $item['product_id'], $item['product_name'], $item['chosen_ingredient_name'], $item['chosen_variant_name'], $item['chosen_sugar_level'], $item['quantity'], $item['unit_price'], $item['subtotal']);
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

// Voids a completed sale: flips its status (Sales Report/Dashboard already filter to 'completed'
// only, so this drops it out of revenue automatically) and restores the stock it consumed —
// product_stocks for prepared items via transaction_items, ingredient_stock via the exact amounts
// recorded in ingredient_usage_snapshot at sale time.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_transaction') {
    header('Content-Type: application/json');

    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    if ($transactionId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid transaction.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT ingredient_usage_snapshot FROM transactions WHERE transaction_id = ? AND transaction_status = 'completed' FOR UPDATE");
        $stmt->bind_param('i', $transactionId);
        $stmt->execute();
        $txnRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$txnRow) {
            throw new RuntimeException('Transaction not found or already cancelled.');
        }

        $update = $conn->prepare("UPDATE transactions SET transaction_status = 'cancelled' WHERE transaction_id = ? AND transaction_status = 'completed'");
        $update->bind_param('i', $transactionId);
        $update->execute();
        if ($update->affected_rows === 0) {
            throw new RuntimeException('Transaction not found or already cancelled.');
        }
        $update->close();

        $itemsStmt = $conn->prepare('SELECT product_id, quantity FROM transaction_items WHERE transaction_id = ?');
        $itemsStmt->bind_param('i', $transactionId);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        $restoreStock = $conn->prepare("UPDATE products SET product_stocks = product_stocks + ? WHERE product_id = ? AND product_type = 'prepared'");
        while ($item = $itemsResult->fetch_assoc()) {
            if ($item['product_id'] === null) continue;
            $restoreStock->bind_param('ii', $item['quantity'], $item['product_id']);
            $restoreStock->execute();
        }
        $itemsStmt->close();
        $restoreStock->close();

        $usage = json_decode($txnRow['ingredient_usage_snapshot'] ?? '[]', true) ?: [];
        if ($usage) {
            $restoreIngredient = $conn->prepare('UPDATE product_ingredients SET ingredient_stock = ingredient_stock + ? WHERE product_ingredients_id = ?');
            foreach ($usage as $ingredientId => $qty) {
                $ingredientId = (int)$ingredientId;
                $qty = (float)$qty;
                if ($ingredientId <= 0 || $qty <= 0) continue;
                $restoreIngredient->bind_param('di', $qty, $ingredientId);
                $restoreIngredient->execute();
            }
            $restoreIngredient->close();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'transaction_id' => $transactionId]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$displayName = $_SESSION['username'] ?? 'Owner';
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

// Sizes/options (e.g. Small/Medium/Large, Hot/Iced) — each carries its own price. When a product
// has at least one, the cashier must pick one before it's added to the cart, and that variant's
// price replaces the product's base price for that line.
$variantsByProduct = [];
$variantResult = $conn->query('SELECT product_variant_id, product_id, variant_name, variant_price FROM product_variants ORDER BY product_id, sort_order, product_variant_id');
while ($row = $variantResult->fetch_assoc()) {
    $variantsByProduct[(int)$row['product_id']][] = [
        'id' => (int)$row['product_variant_id'],
        'name' => $row['variant_name'],
        'price' => (float)$row['variant_price'],
    ];
}

$products = [];
$prodResult = $conn->query('
    SELECT p.product_id, p.product_name, p.product_selling_price, p.product_stocks, p.product_category_id, pc.product_category, p.product_image, p.product_type, p.sugar_level_options
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
        'sugar_levels' => json_decode($row['sugar_level_options'] ?? '[]', true) ?: [],
        'category_id' => (int)$row['product_category_id'],
        'category_name' => $row['product_category'],
        'image' => $row['product_image'] ? '../' . $row['product_image'] : null,
        'flavor_options' => $flavorOptionsByProduct[$pid] ?? [],
        'variants' => $variantsByProduct[$pid] ?? [],
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
      width: 42px;
      height: 42px;
      border-radius: 10px;
      background: var(--gold);
      color: var(--mocha-deep);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
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
      width: 20px;
      text-align: center;
      font-size: 16px;
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
      display: flex;
      flex-direction: column;
      gap: 24px;
      min-height: calc(100vh - 76px);
      padding: 24px;
      padding-bottom: 110px;
    }

    /* ── PRODUCTS ── */
    .menu-toolbar {
      display: flex;
      align-items: center;
      gap: 18px;
      margin-bottom: 16px;
    }

    .menu-toolbar-title {
      font-family: var(--font-display);
      font-size: 19px;
      color: var(--mocha-deep);
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0;
      white-space: nowrap;
      flex-shrink: 0;
    }

    .menu-toolbar .product-search-wrap {
      flex: 1;
      margin-bottom: 0;
    }

    .product-search-wrap {
      position: relative;
    }

    .product-search-wrap i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--mocha-mid);
      font-size: 13px;
      pointer-events: none;
    }

    .product-search-input {
      width: 100%;
      padding: 11px 14px 11px 38px;
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      background: var(--cream-light);
      font-family: var(--font-body);
      font-size: 13.5px;
      color: var(--charcoal);
      outline: none;
      transition: border-color .2s, box-shadow .2s;
    }

    .product-search-input:focus {
      border-color: var(--gold);
      box-shadow: 0 0 0 3px rgba(201, 148, 58, .12);
    }

    .category-tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 20px;
    }

    .cat-tab {
      padding: 9px 18px;
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: 99px;
      color: var(--charcoal-mid);
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .cat-tab:hover {
      border-color: var(--gold);
      color: var(--mocha-deep);
    }

    .cat-tab.active {
      background: var(--mocha);
      color: var(--cream);
      border-color: var(--mocha);
      box-shadow: 0 4px 14px rgba(74, 44, 42, .22);
    }

    .products-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
      gap: 16px;
    }

    .product-btn {
      position: relative;
      width: 100%;
      padding: 20px 12px;
      background: var(--cream-light);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius);
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
      font-family: var(--font-body);
      overflow: hidden;
    }

    .product-btn:hover,
    .product-btn.added {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
      border-color: var(--sage);
      background: rgba(122, 158, 126, .08);
    }

    .stock-pill {
      position: absolute;
      top: 8px;
      right: 8px;
      font-size: 9.5px;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 99px;
      letter-spacing: .3px;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .stock-pill.ok {
      background: rgba(122, 158, 126, .16);
      color: #4d7350;
    }

    .stock-pill.low {
      background: rgba(201, 148, 58, .2);
      color: #8a6320;
    }

    .stock-pill.out {
      background: rgba(192, 57, 43, .14);
      color: var(--red-soft);
    }

    .cart-qty-badge {
      position: absolute;
      top: 8px;
      left: 8px;
      background: var(--mocha-deep);
      color: var(--gold-light);
      font-family: var(--font-mono);
      font-size: 11px;
      font-weight: 700;
      min-width: 20px;
      height: 20px;
      border-radius: 99px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 5px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, .25);
    }

    .product-icon {
      width: 100%;
      height: 100px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 8px;
      font-size: 34px;
      color: var(--mocha);
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
    .cart-fab {
      position: fixed;
      right: 28px;
      bottom: 28px;
      z-index: 700;
      display: flex;
      align-items: center;
      gap: 10px;
      background: var(--mocha);
      color: var(--cream);
      border: none;
      border-radius: 99px;
      padding: 8px 20px 8px 8px;
      cursor: pointer;
      box-shadow: var(--shadow-lg);
      transition: all .2s;
      font-family: var(--font-body);
    }

    .cart-fab:hover {
      background: var(--mocha-mid);
      transform: translateY(-2px);
    }

    .cart-fab-icon {
      position: relative;
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: rgba(255, 255, 255, .12);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }

    .cart-fab-badge {
      position: absolute;
      top: -6px;
      right: -6px;
      background: var(--gold);
      color: var(--mocha-deep);
      font-family: var(--font-mono);
      font-size: 11px;
      font-weight: 800;
      min-width: 20px;
      height: 20px;
      border-radius: 99px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 4px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, .3);
    }

    .cart-fab-total {
      font-family: var(--font-mono);
      font-size: 16px;
      font-weight: 800;
      white-space: nowrap;
    }

    .cart-panel {
      position: fixed;
      right: 28px;
      bottom: 96px;
      z-index: 700;
      width: 360px;
      max-width: calc(100vw - 56px);
      max-height: 65vh;
      display: flex;
      flex-direction: column;
      background: var(--cream);
      border: 1.5px solid var(--cream-dark);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
      opacity: 0;
      transform: translateY(12px) scale(.97);
      pointer-events: none;
      transition: opacity .18s ease, transform .18s ease;
    }

    .cart-panel.show {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    .cart-panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 18px 12px;
      border-bottom: 1px solid var(--cream-dark);
      flex-shrink: 0;
    }

    .cart-panel-header h2 {
      font-family: var(--font-display);
      font-size: 17px;
      color: var(--mocha-deep);
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .cart-panel-clear {
      background: var(--red-soft);
      color: #fff;
      padding: 5px 11px;
      border-radius: 6px;
      font-size: 11.5px;
      border: none;
      cursor: pointer;
      font-weight: 600;
    }

    .cart-panel-close {
      background: transparent;
      border: none;
      font-size: 20px;
      line-height: 1;
      color: #aaa;
      cursor: pointer;
      padding: 0 2px;
    }

    .cart-panel-close:hover {
      color: var(--red-soft);
    }

    .cart-panel-body {
      padding: 14px 18px;
      overflow-y: auto;
      flex: 1;
    }

    .cart-panel-footer {
      padding: 12px 18px 18px;
      border-top: 1px solid var(--cream-dark);
      flex-shrink: 0;
    }

    .cart-empty {
      text-align: center;
      color: #aaa;
      padding: 30px 20px;
      font-size: 13px;
    }

    .cart-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 10px 0;
      border-bottom: 1px solid var(--cream-dark);
    }

    .cart-item:last-child {
      border-bottom: none;
    }

    .item-name {
      font-weight: 600;
      font-size: 13.5px;
      color: var(--charcoal);
    }

    .item-qty-price {
      font-size: 12px;
      color: #999;
      margin-top: 2px;
    }

    .qty-controls {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .qty-btn {
      width: 26px;
      height: 26px;
      border-radius: 6px;
      border: 1.5px solid var(--cream-dark);
      background: var(--cream-light);
      color: var(--mocha);
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all .15s;
    }

    .qty-btn:hover {
      border-color: var(--gold);
      color: var(--gold);
    }

    .qty-display {
      min-width: 20px;
      text-align: center;
      font-family: var(--font-mono);
      font-weight: 700;
      font-size: 13px;
    }

    .item-total {
      font-family: var(--font-mono);
      font-weight: 700;
      font-size: 14px;
      color: var(--mocha-deep);
      min-width: 74px;
      text-align: right;
    }

    .item-remove-btn {
      background: none;
      border: none;
      color: #c9a9a5;
      cursor: pointer;
      font-size: 13px;
      padding: 4px;
      transition: color .2s, transform .2s;
    }

    .item-remove-btn:hover {
      color: var(--red-soft);
      transform: scale(1.1);
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

    @media print {
      /* 80 mm wide portrait receipt paper. printReceipt() sets the exact page height. */
      @page { size: 80mm 297mm; margin: 0; }
      body * { visibility: hidden !important; }
      #receipt-modal, #receipt-modal * { visibility: visible !important; }
      #receipt-modal { position: static !important; display: block !important; background: #fff !important; }
      #receipt-modal .modal-box { box-sizing: border-box !important; width: 80mm !important; max-width: 80mm !important; max-height: none !important; overflow: visible !important; padding: 3mm !important; margin: 0 !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; animation: none !important; color: #000 !important; break-inside: avoid !important; page-break-inside: avoid !important; }
      #receipt-back-row, #receipt-confirm-btn, #receipt-done-btn, #receipt-cancel-sale-btn, #receipt-print-btn { display: none !important; }
    }

    /* Lets JavaScript measure the final receipt before setting its one-page print height. */
    #receipt-modal .modal-box.receipt-print-measure { box-sizing: border-box; width: 80mm; max-width: 80mm; max-height: none; overflow: visible; padding: 3mm; }

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
    <a href="transactions.php" class="nav-item active"><i class="fas fa-receipt"></i> Transactions</a>
    <a href="order_queue.php" class="nav-item"><i class="fas fa-list-check"></i> Order Queue</a>
    <a href="transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
    <a href="products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
    <a href="inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
      <?php if ($ingredientAlertCount > 0): ?>
        <span class="nav-badge"><?= $ingredientAlertCount ?></span>
      <?php endif; ?>
    </a>
    <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i>Sales Report</a>
    <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
    <hr class="sidebar-divider" />
    <div class="sidebar-section-label">Settings</div>
    <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> Log</a>
    <a href="backup.php" class="nav-item"><i class="fas fa-database"></i> Data Backup
    </a>
    <div class="sidebar-footer">
      <p>SmartStock v1.0<br />Bean There Café<br />ISO/IEC 25010 Compliant</p>
    </div>
  </nav>

  <div id="main">
    <div class="page-strip">
      <div>
        <h1><i class="fas fa-cash-register" style="color:var(--gold);font-size:22px;margin-right:10px;"></i>Transaction
        </h1>
        <div class="sub" id="pos-date">Live Sales System</div>
      </div>
    </div>
    <div style="padding:22px 26px;">
      <main class="pos-main">
        <section>
          <div class="menu-toolbar">
            <h2 class="menu-toolbar-title"><i class="fas fa-mug-hot" style="color: var(--gold);"></i>Menu Items</h2>
            <div class="product-search-wrap">
              <i class="fas fa-magnifying-glass"></i>
              <input type="text" id="product-search" class="product-search-input" placeholder="Search menu items…" autocomplete="off">
            </div>
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

      <button id="cart-fab" class="cart-fab" onclick="toggleCartPanel()">
        <span class="cart-fab-icon">
          <i class="fas fa-shopping-cart"></i>
          <span id="cart-count" class="cart-fab-badge">0</span>
        </span>
        <span class="cart-fab-total" id="cart-fab-total">₱0.00</span>
      </button>

      <div id="cart-panel" class="cart-panel">
        <div class="cart-panel-header">
          <h2><i class="fas fa-receipt" style="color: var(--sage);"></i>Cart</h2>
          <div style="display:flex;align-items:center;gap:6px;">
            <button id="clear-cart" class="cart-panel-clear">Clear</button>
            <button type="button" class="cart-panel-close" onclick="toggleCartPanel()">&times;</button>
          </div>
        </div>
        <div class="cart-panel-body">
          <div id="cart-items"></div>
        </div>
        <div class="cart-panel-footer">
          <div id="cart-totals"></div>
          <button id="checkout-btn" class="checkout-btn" disabled><i class="fas fa-credit-card"></i> Checkout</button>
        </div>
      </div>
      <div id="size-choice-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 360px;">
          <div class="modal-header">
            <h2 class="modal-title" id="size-choice-title">Choose a size</h2>
            <button class="modal-close" onclick="closeSizePicker()">&times;</button>
          </div>
          <div id="size-choice-list" style="margin: 16px 0;"></div>
          <div class="modal-actions">
            <button class="btn-cancel" onclick="closeSizePicker()">Cancel</button>
            <button class="btn-confirm" onclick="confirmSizeChoice()">Continue</button>
          </div>
        </div>
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
      <div id="sugar-choice-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 360px;">
          <div class="modal-header">
            <h2 class="modal-title" id="sugar-choice-title">Choose a sugar level</h2>
            <button class="modal-close" onclick="closeSugarPicker()">&times;</button>
          </div>
          <div id="sugar-choice-list" style="margin: 16px 0;"></div>
          <div class="modal-actions">
            <button class="btn-cancel" onclick="closeSugarPicker()">Cancel</button>
            <button class="btn-confirm" onclick="confirmSugarChoice()">Add to Cart</button>
          </div>
        </div>
      </div>
      <div id="receipt-modal" class="modal-overlay">
        <div class="modal-box" style="max-width: 400px; border: 2px dashed var(--cream-dark);">
          <div id="receipt-back-row" style="display:none; margin-bottom:14px;">
            <button class="btn-cancel" style="width:auto;padding:7px 16px;font-size:13px;" onclick="backToCheckout()"><i class="fas fa-arrow-left" style="margin-right:6px;"></i>Back</button>
          </div>
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
            <button id="receipt-confirm-btn" class="btn-confirm" style="margin-top: 15px; width: 100%; display:none;" onclick="finalizeCheckout()">Confirm & Complete Sale</button>
            <button id="receipt-print-btn" class="btn-confirm" style="margin-top: 8px; width: 100%; display:none;" onclick="printReceipt()"><i class="fas fa-print" style="margin-right:6px;"></i>Print Receipt</button>
            <button id="receipt-done-btn" class="btn-confirm" style="margin-top: 15px; width: 100%;" onclick="requestNewSale()">Done & New
              Sale</button>
            <button id="receipt-cancel-sale-btn" class="btn-cancel" style="margin-top: 8px; width: 100%; display:none;" onclick="requestCancelThisSale()"><i class="fas fa-ban" style="margin-right:6px;"></i>Cancel This Sale</button>
          </div>
        </div>
      </div>
      <div id="modal-confirm-newsale" class="modal-overlay">
        <div class="modal-box" style="max-width:360px;">
          <h2 class="modal-title">Start a New Sale?</h2>
          <p style="font-size:13px;color:var(--charcoal-mid);margin-bottom:18px;">This will close the receipt and clear the cart for the next customer.</p>
          <button class="btn-confirm" style="width:100%;" onclick="confirmNewSale()">Yes, Start New Sale</button>
          <button class="btn-cancel" style="width:100%;margin-top:8px;" onclick="cancelNewSale()">Cancel</button>
        </div>
      </div>
      <div id="modal-confirm-cancel-sale" class="modal-overlay">
        <div class="modal-box" style="max-width:360px;">
          <h2 class="modal-title">Cancel This Sale?</h2>
          <p style="font-size:13px;color:var(--charcoal-mid);margin-bottom:18px;">This voids the transaction and restores the stock/ingredients it used. This cannot be undone.</p>
          <button class="btn-confirm" style="width:100%;background:var(--red-soft);color:#fff;" onclick="confirmCancelThisSale()">Yes, Cancel Sale</button>
          <button class="btn-cancel" style="width:100%;margin-top:8px;" onclick="closeCancelThisSaleModal()">No, Keep It</button>
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
              style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-tags" style="color:var(--gold);"></i>Discounts</h4>
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
              style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-wallet" style="color:var(--gold);"></i>Payment Method</h4>
            <div class="payment-option active" data-payment="cash">
              <input type="radio" class="payment-radio" name="payment" value="cash" checked>
              <div><i class="fas fa-money-bill-wave" style="font-size: 20px;"></i> Cash</div>
            </div>
            <div class="payment-option" data-payment="ewallet">
              <input type="radio" class="payment-radio" name="payment" value="ewallet">
              <div><i class="fas fa-mobile-alt" style="font-size: 20px;"></i> Online Payment</div>
            </div>
          </div>
          <div class="cash-section">
            <h4
              style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-money-check-dollar" style="color:var(--gold);"></i>Amount Tendered</h4>
            <input type="number" id="amount-tendered-input" class="cash-input" min="0" step="0.01" placeholder="0.00">
            <div id="change-preview" class="change-preview"></div>
          </div>
          <div class="notes-section">
            <h4 style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px; display:flex; align-items:center; gap:8px;"><i class="fas fa-utensils" style="color:var(--gold);"></i>Order Type</h4>
            <select id="checkout-order-type" style="width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream-light);font-family:var(--font-body);font-size:13px;color:var(--charcoal);outline:none;">
              <option value="dine_in">Dine-in</option>
              <option value="takeout">Takeout</option>
            </select>
          </div>
          <div class="notes-section">
            <h4 style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px; display:flex; align-items:center; gap:8px;"><i class="fas fa-chair" style="color:var(--gold);"></i>Table Number <span style="font-size:12px;color:#aaa;font-weight:400;">(optional)</span></h4>
            <input type="number" id="checkout-table-number" min="1" max="999" step="1" placeholder="e.g. 12" style="width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream-light);font-family:var(--font-body);font-size:13px;color:var(--charcoal);outline:none;">
          </div>
          <div class="notes-section">
            <h4
              style="font-family: var(--font-display); font-size: 16px; color: var(--mocha-deep); margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
              <i class="fas fa-note-sticky" style="color:var(--gold);"></i>Notes <span style="font-size:12px;color:#aaa;font-weight:400;">(optional)</span></h4>
            <textarea id="checkout-notes-input" rows="2" maxlength="500" placeholder="e.g. no ice, allergy warning, special request…" style="width:100%;padding:10px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream-light);font-family:var(--font-body);font-size:13px;color:var(--charcoal);outline:none;resize:vertical;"></textarea>
          </div>
          <div class="modal-actions">
            <button class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
            <button class="btn-confirm" onclick="showReceiptPreview()">Review Order</button>
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
        // Escapes free-text (e.g. the cashier's note) before it's interpolated into innerHTML —
        // unlike item/product names, notes are typed fresh each sale and could contain HTML.
        function escapeHtml(str) {
          const div = document.createElement('div');
          div.textContent = str ?? '';
          return div.innerHTML;
        }
        // Plain-text "Name — Size (Flavor)" label used in the checkout summary and receipt.
        function itemLabel(item) {
          let label = item.name;
          if (item.variant_name) label += ' — ' + item.variant_name;
          if (item.flavor_name) label += ' (' + item.flavor_name + ')';
          if (item.sugar_level) label += ' — ' + item.sugar_level + ' sugar';
          return label;
        }
        // Same label with styled spans, for the cart line-item list.
        function cartItemNameHtml(item) {
          let html = item.name;
          if (item.variant_name) html += ` <span style="color:var(--gold);font-weight:600;">— ${item.variant_name}</span>`;
          if (item.flavor_name) html += ` <span style="color:#aaa;font-weight:400;">(${item.flavor_name})</span>`;
          if (item.sugar_level) html += ` <span style="color:#8b6b4e;font-weight:600;">— ${item.sugar_level} sugar</span>`;
          return html;
        }
        let cart = [];
        let currentTransactionId = null;
        let currentCategory = 'all';
        const TAX_RATE = <?= (float)$settings['tax_rate'] ?>;
        const DISCOUNT_RATE = <?= (float)$settings['discount_rate'] ?>;
        // DOM
        const productsGrid = document.getElementById('products-grid');
        const cartItems = document.getElementById('cart-items');
        const cartTotals = document.getElementById('cart-totals');
        const cartBadge = document.getElementById('cart-count');
        const cartFabTotal = document.getElementById('cart-fab-total');
        const cartPanel = document.getElementById('cart-panel');
        const cartFab = document.getElementById('cart-fab');
        const checkoutBtn = document.getElementById('checkout-btn');
        const clearCartBtn = document.getElementById('clear-cart');
        const checkoutModal = document.getElementById('checkout-modal');
        const clockEl = document.getElementById('clock');
        const amountInput = document.getElementById('amount-tendered-input');
        const changePreview = document.getElementById('change-preview');
        const searchInput = document.getElementById('product-search');
        let amountManuallyEdited = false;
        let currentPaymentMethod = 'cash';
        document.addEventListener('DOMContentLoaded', () => {
          renderProducts();
          renderCart();
          updateBadge();
          updateClock();
          setInterval(updateClock, 1000);

          document.querySelectorAll('.cat-tab').forEach(tab => {
            tab.addEventListener('click', e => switchCategory(e.currentTarget.dataset.cat, e.currentTarget));
          });

          clearCartBtn.addEventListener('click', clearCart);
          checkoutBtn.addEventListener('click', showCheckoutModal);
          searchInput.addEventListener('input', () => renderProducts(currentCategory));

          // Modal interactions
          // Only one discount reason applies per sale — checking one clears the other.
          document.querySelectorAll('.discount-checkbox').forEach(cb => cb.addEventListener('change', () => {
            if (cb.checked) {
              document.querySelectorAll('.discount-checkbox').forEach(other => { if (other !== cb) other.checked = false; });
            }
            updateCheckoutTotals();
          }));
          document.querySelectorAll('.payment-option').forEach(opt => opt.addEventListener('click', e => selectPayment(e.currentTarget.dataset.payment, e.currentTarget)));
          amountInput.addEventListener('input', () => { amountManuallyEdited = true; updateChangePreview(); });
        });
        function renderProducts(category = 'all') {
          currentCategory = category;
          const term = ((searchInput && searchInput.value) || '').trim().toLowerCase();
          const filtered = products.filter(p => {
            const matchesCat = category === 'all' || String(p.category_id) === String(category);
            const matchesTerm = !term || p.name.toLowerCase().includes(term);
            return matchesCat && matchesTerm;
          });

          if (products.length === 0) {
            productsGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#aaa;padding:40px 20px;">No menu items yet. Add products from the Products page first.</div>';
            return;
          }
          if (filtered.length === 0) {
            productsGrid.innerHTML = `<div style="grid-column:1/-1;text-align:center;color:#aaa;padding:40px 20px;">${term ? 'No items match your search.' : 'No items in this category.'}</div>`;
            return;
          }

          productsGrid.innerHTML = filtered.map(p => {
            // Made-to-order products have no stock of their own — availability is governed by
            // ingredient stock, checked server-side at checkout.
            const isMotd = p.type === 'made_to_order';
            const outOfStock = !isMotd && p.stock <= 0;
            const lowStock = !outOfStock && p.stock <= 5;
            const pillClass = outOfStock ? 'out' : lowStock ? 'low' : 'ok';
            const pillText = outOfStock ? 'Out of stock' : lowStock ? `${p.stock} left` : `${p.stock} in stock`;
            const qtyInCart = cart.filter(i => i.id === p.id).reduce((sum, i) => sum + i.qty, 0);
            return `<button class="product-btn" data-product-id="${p.id}" ${outOfStock ? 'disabled style="opacity:.5;cursor:not-allowed;"' : ''}>
          ${qtyInCart > 0 ? `<div class="cart-qty-badge">${qtyInCart}</div>` : ''}
          ${isMotd ? '' : `<div class="stock-pill ${pillClass}">${pillText}</div>`}
          <div class="product-icon">${p.image ? `<img src="${p.image}" alt="">` : iconFor(p.category_name)}</div>
          <div class="product-name">${p.name}${isMotd ? ' <span style="font-size:9px;color:#aaa;font-weight:600;">(Made to Order)</span>' : ''}</div>
          <div class="product-price">₱${p.price.toLocaleString()}</div>
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
        let pendingFlavorVariant = null;
        let pendingSugarProduct = null;
        let pendingSugarFlavorId = null;
        let pendingSugarFlavorName = null;
        let pendingSugarVariant = null;
        let pendingSizeProduct = null;
        function addToCart(id) {
          const product = products.find(p => p.id === id);
          if (!product) return;
          if (product.type !== 'made_to_order' && product.stock <= 0) return;

          if (product.variants && product.variants.length > 0) {
            openSizePicker(product);
            return;
          }
          proceedAfterSize(product, null);
        }
        function proceedAfterSize(product, variant) {
          if (product.flavor_options && product.flavor_options.length > 0) {
            openFlavorPicker(product, variant);
            return;
          }
          proceedAfterFlavor(product, null, null, variant);
        }
        function openSizePicker(product) {
          pendingSizeProduct = product;
          document.getElementById('size-choice-title').textContent = 'Choose a serving for ' + product.name;
          document.getElementById('size-choice-list').innerHTML = product.variants.map((v, i) => `
      <label style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--cream-dark);cursor:pointer;font-size:14px;">
        <span style="display:flex;align-items:center;gap:10px;">
          <input type="radio" name="size-choice" value="${v.id}" ${i === 0 ? 'checked' : ''} />
          <span>${v.name}</span>
        </span>
        <span style="font-family:var(--font-mono);color:var(--gold);font-weight:700;">₱${v.price.toLocaleString()}</span>
      </label>
    `).join('');
          document.getElementById('size-choice-modal').classList.add('show');
        }
        function closeSizePicker() {
          document.getElementById('size-choice-modal').classList.remove('show');
          pendingSizeProduct = null;
        }
        function confirmSizeChoice() {
          const selected = document.querySelector('input[name="size-choice"]:checked');
          if (!selected || !pendingSizeProduct) return;
          const variant = pendingSizeProduct.variants.find(v => String(v.id) === selected.value);
          if (!variant) return;
          const product = pendingSizeProduct;
          closeSizePicker();
          proceedAfterSize(product, variant);
        }
        function openFlavorPicker(product, variant = null) {
          pendingFlavorProduct = product;
          pendingFlavorVariant = variant;
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
          pendingFlavorVariant = null;
        }
        function confirmFlavorChoice() {
          const selected = document.querySelector('input[name="flavor-choice"]:checked');
          if (!selected || !pendingFlavorProduct) return;
          const opt = pendingFlavorProduct.flavor_options.find(o => String(o.id) === selected.value);
          if (!opt) return;
          proceedAfterFlavor(pendingFlavorProduct, opt.id, opt.name, pendingFlavorVariant);
          closeFlavorPicker();
        }
        function proceedAfterFlavor(product, flavorId, flavorName, variant) {
          if (product.sugar_levels && product.sugar_levels.length > 0) {
            openSugarPicker(product, flavorId, flavorName, variant);
            return;
          }
          addToCartFinal(product, flavorId, flavorName, variant, null);
        }
        function openSugarPicker(product, flavorId, flavorName, variant) {
          pendingSugarProduct = product; pendingSugarFlavorId = flavorId; pendingSugarFlavorName = flavorName; pendingSugarVariant = variant;
          document.getElementById('sugar-choice-title').textContent = 'Choose a sugar level for ' + product.name;
          document.getElementById('sugar-choice-list').innerHTML = product.sugar_levels.map((level, i) => `<label style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--cream-dark);cursor:pointer;font-size:14px;"><input type="radio" name="sugar-choice" value="${level}" ${i === 0 ? 'checked' : ''} /><span>${level} sugar</span></label>`).join('');
          document.getElementById('sugar-choice-modal').classList.add('show');
        }
        function closeSugarPicker() {
          document.getElementById('sugar-choice-modal').classList.remove('show');
          pendingSugarProduct = null; pendingSugarFlavorId = null; pendingSugarFlavorName = null; pendingSugarVariant = null;
        }
        function confirmSugarChoice() {
          const selected = document.querySelector('input[name="sugar-choice"]:checked');
          if (!selected || !pendingSugarProduct) return;
          addToCartFinal(pendingSugarProduct, pendingSugarFlavorId, pendingSugarFlavorName, pendingSugarVariant, selected.value);
          closeSugarPicker();
        }
        function addToCartFinal(product, flavorId, flavorName, variant = null, sugarLevel = null) {
          const variantId = variant ? variant.id : null;
          const variantName = variant ? variant.name : null;
          const effectivePrice = variant ? variant.price : product.price;
          // A product sold with different sizes and/or flavors needs separate cart lines so
          // quantities and stock checks don't get mixed between them — stock itself is still one
          // shared pool per product, only the price and label differ per line.
          const cartKey = [product.id, variantId, flavorId, sugarLevel].filter(v => v !== null && v !== undefined).join(':');
          const item = cart.find(i => i.cartKey === cartKey);
          const currentQty = item ? item.qty : 0;
          if (product.type !== 'made_to_order' && currentQty >= product.stock) {
            showToast(`Only ${product.stock} unit(s) of ${product.name} available.`, 'warn');
            return;
          }
          if (item) item.qty++;
          else cart.push({ ...product, price: effectivePrice, qty: 1, cartKey, flavor_ingredient_id: flavorId, flavor_name: flavorName, variant_id: variantId, variant_name: variantName, sugar_level: sugarLevel });
          renderCart();
          updateBadge();
          renderProducts(currentCategory);
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
          renderProducts(currentCategory);
        }
        function removeItem(cartKey) {
          cart = cart.filter(i => i.cartKey !== cartKey);
          renderCart();
          updateBadge();
          renderProducts(currentCategory);
        }
        function clearCart() {
          cart = [];
          renderCart();
          updateBadge();
          renderProducts(currentCategory);
        }
        function toggleCartPanel() {
          cartPanel.classList.toggle('show');
        }
        function closeCartPanel() {
          cartPanel.classList.remove('show');
        }
        document.addEventListener('click', e => {
          if (cartPanel.classList.contains('show') && !cartPanel.contains(e.target) && !cartFab.contains(e.target)) {
            closeCartPanel();
          }
        });
        function renderCart() {
          if (cart.length === 0) {
            cartItems.innerHTML = '<div class="cart-empty"><i class="fas fa-shopping-cart" style="font-size: 48px; color: #ccc; margin-bottom: 12px;"></i>Add items to get started</div>';
            cartTotals.innerHTML = '';
            cartFabTotal.textContent = '₱0.00';
            checkoutBtn.disabled = true;
            return;
          }

          cartItems.innerHTML = cart.map(item => {
            const total = item.price * item.qty;
            return `
      <div class="cart-item">
        <div class="item-details">
          <div class="item-name">${cartItemNameHtml(item)}</div>
          <div class="item-qty-price">₱${item.price.toLocaleString()} each</div>
        </div>
        <div class="qty-controls">
          <button class="qty-btn" onclick="updateQty('${item.cartKey}', -1)">−</button>
          <div class="qty-display">${item.qty}</div>
          <button class="qty-btn" onclick="updateQty('${item.cartKey}', 1)">+</button>
        </div>
        <div class="item-total">₱${total.toLocaleString()}</div>
      </div>
    `;
          }).join('');

          const total = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
          const totalFormatted = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          cartTotals.innerHTML = `
      <div class="cart-total-row">
        <span class="total-label">Total</span>
        <span class="grand-total">₱${totalFormatted}</span>
      </div>
    `;
          cartFabTotal.textContent = '₱' + totalFormatted;
          checkoutBtn.disabled = false;
        }
        function updateBadge() {
          if (!cartBadge) return;
          const count = cart.reduce((sum, i) => sum + i.qty, 0);
          cartBadge.textContent = count;
          cartBadge.style.display = count > 0 ? 'inline-block' : 'none';
        }
        function showCheckoutModal() {
          if (cart.length === 0) return;
          closeCartPanel();
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
            changePreview.textContent = 'Exact amount charged via Online Payment — no change due';
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
          <span>${itemLabel(item)} × ${item.qty}</span>
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
          updateCheckoutTotals();
        }
        // Builds the receipt HTML from the current cart/discount/payment state — used both for
        // the pre-save preview (transactionId = null) and the real final receipt (once saved),
        // so there's one source of truth for how a receipt renders.
        function buildReceiptHtml(transactionId) {
          const totalOriginal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
          const hasDiscount = document.getElementById('pwd-discount').checked || document.getElementById('senior-discount').checked;
          const discountVal = hasDiscount ? totalOriginal * DISCOUNT_RATE : 0;
          const finalPayable = totalOriginal - discountVal;
          const paymentMethod = document.querySelector('.payment-option.active').dataset.payment;
          const paymentLabel = paymentMethod === 'ewallet' ? 'ONLINE' : 'CASH';
          const amountTendered = parseFloat(amountInput.value);
          const change = amountTendered - finalPayable;
          const orderType = document.getElementById('checkout-order-type').value;
          const orderTypeLabel = orderType === 'takeout' ? 'TAKEOUT' : 'DINE-IN';
          const notesText = document.getElementById('checkout-notes-input').value.trim();

          const itemsHtml = cart.map(i => `
    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
      <span>${itemLabel(i)} x${i.qty}</span>
      <span>₱${(i.price * i.qty).toLocaleString()}</span>
    </div>
  `).join('');

          return `
    ${transactionId ? `<div style="text-align:center;font-size:11px;color:#999;margin-bottom:10px;">Transaction #${transactionId}</div>` : ''}
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
      <span>Paid (${paymentLabel}):</span>
      <span>₱${amountTendered.toLocaleString()}</span>
    </div>
    <div style="display: flex; justify-content: space-between; font-size: 13px; color: var(--charcoal-mid);">
      <span>Order Type:</span>
      <span>${orderTypeLabel}</span>
    </div>
    <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--sage); margin-bottom: 10px;">
      <span>CHANGE:</span>
      <span>₱${change.toLocaleString(undefined, { minimumFractionDigits: 2 })}</span>
    </div>
    ${notesText ? `<div style="margin-top:8px;padding-top:10px;border-top:1px dashed var(--cream-dark);font-size:12px;color:var(--charcoal-mid);"><strong>Note:</strong> ${escapeHtml(notesText)}</div>` : ''}
    <div style="font-size: 11px; color: #999; text-align: center; margin-top: 15px;">
      VAT Included (12%): ₱${(finalPayable - (finalPayable / 1.12)).toLocaleString(undefined, { minimumFractionDigits: 2 })}
    </div>
  `;
        }

        // Shows a preview of the receipt before anything is saved — no server call here, so
        // "Back" can safely return to the checkout modal with nothing to undo.
        function showReceiptPreview() {
          const totalOriginal = cart.reduce((sum, i) => sum + (i.price * i.qty), 0);
          const hasDiscount = document.getElementById('pwd-discount').checked || document.getElementById('senior-discount').checked;
          const discountVal = hasDiscount ? totalOriginal * DISCOUNT_RATE : 0;
          const finalPayable = totalOriginal - discountVal;
          const amountTendered = parseFloat(amountInput.value);

          if (isNaN(amountTendered) || amountTendered < finalPayable) {
            showToast('Insufficient amount entered.', 'warn');
            amountInput.focus();
            return;
          }

          document.getElementById('receipt-date').textContent = new Date().toLocaleString();
          document.getElementById('receipt-content').innerHTML = buildReceiptHtml(null);
          document.getElementById('receipt-back-row').style.display = 'block';
          document.getElementById('receipt-confirm-btn').style.display = 'block';
          document.getElementById('receipt-print-btn').style.display = 'none';
          document.getElementById('receipt-done-btn').style.display = 'none';
          document.getElementById('receipt-cancel-sale-btn').style.display = 'none';
          currentTransactionId = null;

          closeCheckoutModal();
          document.getElementById('receipt-modal').classList.add('show');
        }

        // Returns to the checkout modal from the preview — its DOM was only hidden, never
        // cleared, so the cart/discount/payment/amount-tendered are all still exactly as left.
        function backToCheckout() {
          document.getElementById('receipt-modal').classList.remove('show');
          checkoutModal.classList.add('show');
        }

        // Actually persists the sale — inserts the transaction and deducts stock/ingredients.
        async function finalizeCheckout() {
          const hasDiscount = document.getElementById('pwd-discount').checked || document.getElementById('senior-discount').checked;
          const paymentMethod = document.querySelector('.payment-option.active').dataset.payment;

          const confirmBtn = document.getElementById('receipt-confirm-btn');
          confirmBtn.disabled = true;
          confirmBtn.textContent = 'Processing…';

          let serverResult;
          try {
            const response = await fetch('transactions.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({
                action: 'checkout',
                cart: JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty, flavor_ingredient_id: i.flavor_ingredient_id || null, variant_id: i.variant_id || null, sugar_level: i.sugar_level || null }))),
                payment_method: paymentMethod,
                discount: hasDiscount ? '1' : '',
                amount_tendered: amountInput.value,
                order_type: document.getElementById('checkout-order-type').value,
                table_number: document.getElementById('checkout-table-number').value,
                notes: document.getElementById('checkout-notes-input').value.trim()
              })
            });
            serverResult = await response.json();
          } catch (err) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm & Complete Sale';
            showToast('Could not reach the server. Please try again.', 'warn');
            return;
          }

          confirmBtn.disabled = false;
          confirmBtn.textContent = 'Confirm & Complete Sale';

          if (!serverResult || !serverResult.success) {
            showToast(serverResult && serverResult.error ? serverResult.error : 'Transaction failed.', 'warn');
            return;
          }

          currentTransactionId = serverResult.transaction_id;
          document.getElementById('receipt-content').innerHTML = buildReceiptHtml(serverResult.transaction_id);
          document.getElementById('receipt-back-row').style.display = 'none';
          document.getElementById('receipt-confirm-btn').style.display = 'none';
          document.getElementById('receipt-print-btn').style.display = 'block';
          document.getElementById('receipt-done-btn').style.display = 'block';
          document.getElementById('receipt-cancel-sale-btn').style.display = 'block';
        }

        // Lets the cashier immediately void a just-completed sale (e.g. wrong items rung up)
        // without leaving this page — same cancel_transaction endpoint Transaction History uses.
        function requestCancelThisSale() {
          if (!currentTransactionId) return;
          document.getElementById('modal-confirm-cancel-sale').classList.add('show');
        }
        function closeCancelThisSaleModal() {
          document.getElementById('modal-confirm-cancel-sale').classList.remove('show');
        }
        async function confirmCancelThisSale() {
          closeCancelThisSaleModal();
          if (!currentTransactionId) return;
          const btn = document.getElementById('receipt-cancel-sale-btn');
          btn.disabled = true;
          btn.textContent = 'Cancelling…';
          try {
            const res = await fetch('transactions.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({ action: 'cancel_transaction', transaction_id: currentTransactionId })
            });
            const data = await res.json();
            if (!data.success) {
              showToast(data.error || 'Could not cancel this sale.', 'warn');
              btn.disabled = false;
              btn.innerHTML = '<i class="fas fa-ban" style="margin-right:6px;"></i>Cancel This Sale';
              return;
            }
            showToast(`Transaction #${currentTransactionId} cancelled — stock restored.`, 'success');
            btn.style.display = 'none';
            document.getElementById('receipt-content').insertAdjacentHTML('afterbegin',
              '<div style="text-align:center;font-weight:800;color:var(--red-soft);letter-spacing:1px;margin-bottom:10px;">CANCELLED</div>');
          } catch (err) {
            showToast('Could not reach the server. Please try again.', 'warn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-ban" style="margin-right:6px;"></i>Cancel This Sale';
          }
        }

        function requestNewSale() {
          document.getElementById('modal-confirm-newsale').classList.add('show');
        }
        function printReceipt() {
          const receipt = document.querySelector('#receipt-modal .modal-box');
          if (!receipt) return;
          const printWindow = window.open('', '_blank', 'width=420,height=720');
          if (!printWindow) {
            showToast('Please allow pop-ups to print the receipt.', 'warn');
            return;
          }

          // Print an isolated copy instead of the page modal. This guarantees that every line
          // item and total is included, without dashboard/page styles interfering with the print.
          const printableReceipt = receipt.cloneNode(true);
          printableReceipt.querySelectorAll('#receipt-back-row, #receipt-confirm-btn, #receipt-print-btn, #receipt-done-btn, #receipt-cancel-sale-btn').forEach(el => el.remove());

          const printDoc = printWindow.document;
          printDoc.open();
          printDoc.write(`<!doctype html><html><head><meta charset="utf-8"><title>Receipt</title><style id="receipt-page-style">
            :root { --cream-dark:#d6d6d6; --charcoal-mid:#333; --mocha-deep:#000; --mocha:#000; --red-soft:#000; --sage:#000; --font-mono:'Courier New',monospace; --font-display:Arial,sans-serif; }
            @page { size: 80mm 200mm; margin: 0; }
            * { box-sizing: border-box; }
            html, body { width:80mm; margin:0; padding:0; background:#fff; color:#000; }
            .modal-box { width:80mm !important; max-width:80mm !important; min-height:0; max-height:none !important; overflow:visible !important; margin:0 !important; padding:3mm !important; border:0 !important; border-radius:0 !important; box-shadow:none !important; background:#fff !important; font-family:Arial,sans-serif; }
            .pos-logo { display:none !important; }
          </style></head><body><div id="receipt-print-root"></div></body></html>`);
          printDoc.close();
          printDoc.getElementById('receipt-print-root').appendChild(printableReceipt);

          // Thermal rolls have a fixed width but variable length. Measure the fully rendered
          // receipt (including prices, total, payment and change) then make one portrait page.
          setTimeout(() => {
            const pageHeight = Math.max(360, Math.ceil(printDoc.documentElement.scrollHeight + 12));
            printDoc.getElementById('receipt-page-style').textContent += `@page { size: 80mm ${pageHeight}px; margin: 0; }`;
            printWindow.focus();
            printWindow.print();
          }, 100);
        }
        function cancelNewSale() {
          document.getElementById('modal-confirm-newsale').classList.remove('show');
        }
        // Reload so the product list reflects the stock the sale just consumed
        function confirmNewSale() {
          location.reload();
        }
        function updateClock() {
          clockEl.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
