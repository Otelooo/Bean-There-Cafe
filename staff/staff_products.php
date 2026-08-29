<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';
require_once __DIR__ . '/../unit_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe staff') {
    header('Location: ../signin.php');
    exit;
}

function resolve_category_id(mysqli $conn, string $categorySelect): int
{
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

// Normalizes a user-typed category name to Title Case (e.g. "pasta" / "PASTA" -> "Pasta")
// so categories display consistently no matter how the owner/staff typed them in.
function format_category_name(string $raw): string
{
    return mb_convert_case(trim($raw), MB_CASE_TITLE, 'UTF-8');
}

// Made-to-order products don't have a real supplier (they're assembled in-house from ingredients),
// but product_supplier_id is a required column, so we point every made-to-order product at one shared row.
function resolve_inhouse_supplier_id(mysqli $conn): int
{
    $name = 'In-house';
    $check = $conn->prepare('SELECT product_supplier_id FROM product_supplier WHERE supplier_name = ?');
    $check->bind_param('s', $name);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        return (int)$existing['product_supplier_id'];
    }

    $contact = '—';
    $insert = $conn->prepare('INSERT INTO product_supplier (supplier_name, supplier_contact) VALUES (?, ?)');
    $insert->bind_param('ss', $name, $contact);
    $insert->execute();
    $id = (int)$insert->insert_id;
    $insert->close();
    return $id;
}

// Deleting a category shouldn't be blocked by products still using it — they get moved here instead.
function resolve_uncategorized_category_id(mysqli $conn): int
{
    $name = 'Uncategorized';
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

// Replaces a product's recipe with whatever ingredient rows were submitted on the Add/Edit form.
// A "prepared" product always ends up with no recipe rows, since only made-to-order items consume ingredients.
// $units holds the display unit chosen per row (e.g. "Tablespoon"); it's purely a label —
// stock deduction at checkout always works in the ingredient's own inventory unit/quantity,
// so an unset/blank choice just falls back to that ingredient's inventory unit.
// $choiceFlags marks a row as a flavor-choice option (e.g. Cheese Powder vs BBQ vs Sour Cream) —
// at checkout, exactly one option from that group gets consumed instead of all of them. Rows not
// marked are always-required ingredients (e.g. potatoes, oil), consumed on every sale as before.
function save_product_recipe(mysqli $conn, int $productId, string $productType, array $ingredientIds, array $quantities, array $units = [], array $choiceFlags = []): void
{
    $del = $conn->prepare('DELETE FROM product_ingredient_items WHERE product_id = ?');
    $del->bind_param('i', $productId);
    $del->execute();
    $del->close();

    if ($productType !== 'made_to_order') {
        return;
    }

    $ins = $conn->prepare('INSERT INTO product_ingredient_items (product_id, product_ingredients_id, quantity, unit, is_flavor_choice) VALUES (?, ?, ?, ?, ?)');
    $checkStmt = $conn->prepare('SELECT ingredient_unit FROM product_ingredients WHERE product_ingredients_id = ?');

    foreach ($ingredientIds as $i => $rawIngredientId) {
        $ingredientId = (int)$rawIngredientId;
        $qty = (float)($quantities[$i] ?? 0);
        if ($ingredientId <= 0 || $qty <= 0) {
            continue;
        }
        $checkStmt->bind_param('i', $ingredientId);
        $checkStmt->execute();
        $ingredientRow = $checkStmt->get_result()->fetch_assoc();
        if (!$ingredientRow) {
            continue;
        }
        $unitKey = normalize_unit_key(trim($units[$i] ?? ''));
        // An unrecognized/blank unit falls back to the ingredient's own unit, so the recipe
        // quantity always ends up expressed in something convert_quantity() can work with.
        $unit = $unitKey ?? (normalize_unit_key($ingredientRow['ingredient_unit']) ?? $ingredientRow['ingredient_unit']);
        $isChoice = !empty($choiceFlags[$i]) ? 1 : 0;
        $ins->bind_param('iidsi', $productId, $ingredientId, $qty, $unit, $isChoice);
        $ins->execute();
    }
    $ins->close();
    $checkStmt->close();
}

// Replaces a product's sizes/options with whatever rows were submitted on the Add/Edit form —
// same delete-then-reinsert shape as save_product_recipe(), but with no product-type gate, since
// sizes apply to any product (the standalone "Sizes" button already works for both types).
function save_product_variants(mysqli $conn, int $productId, array $variantNames, array $variantPrices): void
{
    $del = $conn->prepare('DELETE FROM product_variants WHERE product_id = ?');
    $del->bind_param('i', $productId);
    $del->execute();
    $del->close();

    $ins = $conn->prepare('INSERT INTO product_variants (product_id, variant_name, variant_price, sort_order) VALUES (?, ?, ?, ?)');
    $sort = 0;
    foreach ($variantNames as $i => $rawName) {
        $name = trim($rawName);
        $price = $variantPrices[$i] ?? '';
        if ($name === '' || !is_numeric($price) || (float)$price < 0) {
            continue;
        }
        $priceVal = (float)$price;
        $ins->bind_param('isdi', $productId, $name, $priceVal, $sort);
        $ins->execute();
        $sort++;
    }
    $ins->close();
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
        $productType = $_POST['product_type'] ?? '';
        $price = $_POST['price'] ?? '';
        $isPrepared = $productType === 'prepared';
        $stock = $_POST['stock'] ?? '';
        $cost = $_POST['cost'] ?? '';

        if (!in_array($productType, ['made_to_order', 'prepared'], true)) {
            $msg = 'Please choose a product type.';
            $msgType = 'warn';
        } elseif ($name === '' || !is_numeric($price) || (float)$price < 0) {
            $msg = 'Please fill in all product fields with valid values.';
            $msgType = 'warn';
        } elseif ($isPrepared && (!is_numeric($stock) || (int)$stock < 0)) {
            $msg = 'Please fill in all product fields with valid values.';
            $msgType = 'warn';
        } elseif (!is_numeric($cost) || (float)$cost < 0) {
            $msg = 'Please fill in all product fields with valid values.';
            $msgType = 'warn';
        } else {
            try {
                $categoryId = resolve_category_id($conn, $_POST['category'] ?? '');
                $priceVal = (float)$price;
                $newImagePath = handle_product_image_upload($_FILES['product_image'] ?? null);
                // Made to Order products have no stock count of their own — availability is governed
                // by the recipe's ingredient stock instead (see the ingredient check in transactions.php).
                $stockInt = $isPrepared ? (int)$stock : 0;
                $costVal = (float)$cost;

                if ($isPrepared) {
                    $supplierId = resolve_supplier_id($conn, $_POST['supplier_name'] ?? '', $_POST['supplier_contact'] ?? '');
                } else {
                    $supplierId = resolve_inhouse_supplier_id($conn);
                }

                if ($action === 'add') {
                    $stmt = $conn->prepare('INSERT INTO products (product_name, product_category_id, product_stocks, product_stocks_reference, product_cost, product_selling_price, product_supplier_id, product_type, product_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('siiiddiss', $name, $categoryId, $stockInt, $stockInt, $costVal, $priceVal, $supplierId, $productType, $newImagePath);
                    $stmt->execute();
                    $newProductId = (int)$stmt->insert_id;
                    $stmt->close();

                    save_product_recipe($conn, $newProductId, $productType, $_POST['ingredient_id'] ?? [], $_POST['ingredient_qty'] ?? [], $_POST['ingredient_unit'] ?? [], $_POST['ingredient_is_choice'] ?? []);
                    save_product_variants($conn, $newProductId, $_POST['variant_name'] ?? [], $_POST['variant_price'] ?? []);
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

                        $stmt = $conn->prepare('UPDATE products SET product_name = ?, product_category_id = ?, product_stocks = ?, product_stocks_reference = ?, product_cost = ?, product_selling_price = ?, product_supplier_id = ?, product_type = ?, product_image = ? WHERE product_id = ?');
                        $stmt->bind_param('siiiddissi', $name, $categoryId, $stockInt, $stockInt, $costVal, $priceVal, $supplierId, $productType, $newImagePath, $productId);
                        $stmt->execute();
                        $stmt->close();
                        delete_product_image_file($oldImage);
                    } else {
                        $stmt = $conn->prepare('UPDATE products SET product_name = ?, product_category_id = ?, product_stocks = ?, product_stocks_reference = ?, product_cost = ?, product_selling_price = ?, product_supplier_id = ?, product_type = ? WHERE product_id = ?');
                        $stmt->bind_param('siiiddisi', $name, $categoryId, $stockInt, $stockInt, $costVal, $priceVal, $supplierId, $productType, $productId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    save_product_recipe($conn, $productId, $productType, $_POST['ingredient_id'] ?? [], $_POST['ingredient_qty'] ?? [], $_POST['ingredient_unit'] ?? [], $_POST['ingredient_is_choice'] ?? []);
                    save_product_variants($conn, $productId, $_POST['variant_name'] ?? [], $_POST['variant_price'] ?? []);
                    $msg = 'Product updated.';
                }
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $msgType = 'warn';
            }
        }
    } elseif ($action === 'add_category') {
        $categoryName = format_category_name($_POST['category_name'] ?? '');
        if ($categoryName === '') {
            $msg = 'Please enter a category name.';
            $msgType = 'warn';
        } else {
            $check = $conn->prepare('SELECT product_category_id FROM product_category WHERE LOWER(product_category) = LOWER(?)');
            $check->bind_param('s', $categoryName);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $msg = 'That category already exists.';
                $msgType = 'warn';
            } else {
                $insert = $conn->prepare('INSERT INTO product_category (product_category) VALUES (?)');
                $insert->bind_param('s', $categoryName);
                $insert->execute();
                $insert->close();
                $msg = 'Category added.';
            }
        }
    } elseif ($action === 'edit_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $categoryName = format_category_name($_POST['category_name'] ?? '');
        if ($categoryId <= 0 || $categoryName === '') {
            $msg = 'Please enter a category name.';
            $msgType = 'warn';
        } else {
            $check = $conn->prepare('SELECT product_category_id FROM product_category WHERE LOWER(product_category) = LOWER(?) AND product_category_id != ?');
            $check->bind_param('si', $categoryName, $categoryId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                $msg = 'That category already exists.';
                $msgType = 'warn';
            } else {
                $update = $conn->prepare('UPDATE product_category SET product_category = ? WHERE product_category_id = ?');
                $update->bind_param('si', $categoryName, $categoryId);
                $update->execute();
                $update->close();
                $msg = 'Category updated.';
            }
        }
    } elseif ($action === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        try {
            $uncategorizedId = resolve_uncategorized_category_id($conn);
            if ($uncategorizedId !== $categoryId) {
                $reassign = $conn->prepare('UPDATE products SET product_category_id = ? WHERE product_category_id = ?');
                $reassign->bind_param('ii', $uncategorizedId, $categoryId);
                $reassign->execute();
                $reassign->close();
            }

            $stmt = $conn->prepare('DELETE FROM product_category WHERE product_category_id = ?');
            $stmt->bind_param('i', $categoryId);
            $stmt->execute();
            $stmt->close();
            $msg = 'Category deleted.';
        } catch (Throwable $e) {
            $msg = 'Cannot delete this category.';
            $msgType = 'warn';
        }
    } elseif ($action === 'delete') {
        $productId = (int)($_POST['product_id'] ?? 0);
        try {
            $imgStmt = $conn->prepare('SELECT product_image FROM products WHERE product_id = ?');
            $imgStmt->bind_param('i', $productId);
            $imgStmt->execute();
            $imageToDelete = $imgStmt->get_result()->fetch_assoc()['product_image'] ?? null;
            $imgStmt->close();

            // Recipe rows are just configuration for this product, not sales history — safe to drop.
            // Past transaction_items keep their own product_name_snapshot, so deleting the product
            // itself no longer erases what was actually sold.
            $delRecipe = $conn->prepare('DELETE FROM product_ingredient_items WHERE product_id = ?');
            $delRecipe->bind_param('i', $productId);
            $delRecipe->execute();
            $delRecipe->close();

            $stmt = $conn->prepare('DELETE FROM products WHERE product_id = ?');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->close();
            delete_product_image_file($imageToDelete);
            $msg = 'Product deleted.';
        } catch (Throwable $e) {
            $msg = 'Could not delete this product.';
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
                $chosenUnitKey = normalize_unit_key(trim($_POST['unit'] ?? ''));
                $unit = $chosenUnitKey ?? (normalize_unit_key($ingRow['ingredient_unit']) ?? $ingRow['ingredient_unit']);
                $isChoice = !empty($_POST['is_choice']) ? 1 : 0;
                $existing = $conn->prepare('SELECT product_ingredient_items_id FROM product_ingredient_items WHERE product_id = ? AND product_ingredients_id = ?');
                $existing->bind_param('ii', $productId, $ingredientId);
                $existing->execute();
                $existingRow = $existing->get_result()->fetch_assoc();
                $existing->close();

                if ($existingRow) {
                    $itemId = (int)$existingRow['product_ingredient_items_id'];
                    $upd = $conn->prepare('UPDATE product_ingredient_items SET quantity = ?, unit = ?, is_flavor_choice = ? WHERE product_ingredient_items_id = ?');
                    $upd->bind_param('dsii', $qtyVal, $unit, $isChoice, $itemId);
                    $upd->execute();
                    $upd->close();
                } else {
                    $ins = $conn->prepare('INSERT INTO product_ingredient_items (product_id, product_ingredients_id, quantity, unit, is_flavor_choice) VALUES (?, ?, ?, ?, ?)');
                    $ins->bind_param('iidsi', $productId, $ingredientId, $qtyVal, $unit, $isChoice);
                    $ins->execute();
                    $ins->close();
                }
                $msg = 'Recipe updated.';
            }
        }
        header('Location: staff_products.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType) . '&recipe=' . $productId);
        exit;
    } elseif ($action === 'recipe_remove') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM product_ingredient_items WHERE product_ingredient_items_id = ?');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        header('Location: staff_products.php?msg=' . urlencode('Ingredient removed from recipe.') . '&type=success&recipe=' . $productId);
        exit;
    } elseif ($action === 'variant_add') {
        // Sizes/options (e.g. Small/Medium/Large, Hot/Iced) each carry their own selling price —
        // when a product has at least one, the cashier must pick one at checkout instead of using
        // the product's base price (see the size picker in transactions.php).
        $productId = (int)($_POST['product_id'] ?? 0);
        $variantName = trim($_POST['variant_name'] ?? '');
        $variantPrice = $_POST['variant_price'] ?? '';

        if ($productId <= 0 || $variantName === '' || !is_numeric($variantPrice) || (float)$variantPrice < 0) {
            $msg = 'Please enter a valid size/option name and price.';
            $msgType = 'warn';
        } else {
            $priceVal = (float)$variantPrice;
            $sortStmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort FROM product_variants WHERE product_id = ?');
            $sortStmt->bind_param('i', $productId);
            $sortStmt->execute();
            $nextSort = (int)$sortStmt->get_result()->fetch_assoc()['next_sort'];
            $sortStmt->close();

            $ins = $conn->prepare('INSERT INTO product_variants (product_id, variant_name, variant_price, sort_order) VALUES (?, ?, ?, ?)');
            $ins->bind_param('isdi', $productId, $variantName, $priceVal, $nextSort);
            $ins->execute();
            $ins->close();
            $msg = 'Size/option added.';
        }
        header('Location: staff_products.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType) . '&sizes=' . $productId);
        exit;
    } elseif ($action === 'variant_remove') {
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM product_variants WHERE product_variant_id = ?');
        $stmt->bind_param('i', $variantId);
        $stmt->execute();
        $stmt->close();
        header('Location: staff_products.php?msg=' . urlencode('Size/option removed.') . '&type=success&sizes=' . $productId);
        exit;
    }

    header('Location: staff_products.php?msg=' . urlencode($msg) . '&type=' . urlencode($msgType));
    exit;
}

$msg = $_GET['msg'] ?? '';
$msgType = $_GET['type'] ?? 'success';

$settings = get_system_settings($conn);
$criticalStockThreshold = (float)$settings['critical_stock_threshold'];
$lowStockThreshold = (float)$settings['low_stock_threshold'];

// Feeds the red badge on the "Products" nav-item — prepared products only, since made-to-order
// items have no product_stocks of their own and would otherwise all read as critical.
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM products WHERE product_type = 'prepared' AND product_stocks_reference > 0 AND (product_stocks / product_stocks_reference) * 100 <= ?");
$stmt->bind_param('d', $criticalStockThreshold);
$stmt->execute();
$criticalCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Feeds the "Inventory" nav-badge — ingredients at critical or low stock.
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE (ingredient_stock_reference IS NULL AND ingredient_stock <= 0) OR (ingredient_stock_reference > 0 AND (ingredient_stock / ingredient_stock_reference) * 100 <= ?)");
$stmt->bind_param('d', $lowStockThreshold);
$stmt->execute();
$ingredientAlertCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Critical/Low are percentages of product_stocks_reference — the stock count last typed into
// the Add/Edit form, which becomes the new "100%" mark every time stock is manually set/restocked.
function stock_level(int $stock, ?int $reference, float $criticalPct, float $lowPct): string
{
    if ($reference === null || $reference <= 0) {
        return $stock <= 0 ? 'crit' : 'ok';
    }
    $pct = ($stock / $reference) * 100;
    if ($pct <= $criticalPct) return 'crit';
    if ($pct <= $lowPct) return 'low';
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

$displayName = $_SESSION['username'] ?? 'Staff';
$initials = strtoupper(substr($displayName, 0, 2));

$categories = [];
$catResult = $conn->query('SELECT product_category_id, product_category FROM product_category ORDER BY product_category');
while ($row = $catResult->fetch_assoc()) {
    $categories[] = $row;
}

$ingredients = [];
$ingResult = $conn->query('SELECT product_ingredients_id, ingredient_name, ingredient_unit FROM product_ingredients ORDER BY ingredient_name');
while ($row = $ingResult->fetch_assoc()) {
    // Normalize to a canonical unit key so the recipe form can convert between it and whatever
    // unit the recipe line itself is entered in (e.g. ingredient stocked in Liters, recipe in Milliliters).
    $unitKey = normalize_unit_key($row['ingredient_unit']);
    $row['ingredient_unit'] = $unitKey ?? $row['ingredient_unit'];
    $row['ingredient_unit_label'] = $unitKey ? unit_label($unitKey) : $row['ingredient_unit'];
    $ingredients[] = $row;
}

// Units offered on each recipe line, grouped by family (Weight/Volume/Count) — a recipe line can
// use a different unit than the ingredient's own stock unit as long as they're the same family;
// checkout converts between them automatically.
$unitGroups = unit_options_grouped();

$recipesByProduct = [];
$recipeResult = $conn->query('
    SELECT pii.product_ingredient_items_id, pii.product_id, pii.product_ingredients_id, pii.quantity, pii.unit, pii.is_flavor_choice,
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
        'is_choice' => (bool)$row['is_flavor_choice'],
    ];
}

$variantsByProduct = [];
$variantResult = $conn->query('SELECT product_variant_id, product_id, variant_name, variant_price FROM product_variants ORDER BY product_id, sort_order, product_variant_id');
while ($row = $variantResult->fetch_assoc()) {
    $variantsByProduct[(int)$row['product_id']][] = [
        'variant_id' => (int)$row['product_variant_id'],
        'name' => $row['variant_name'],
        'price' => (float)$row['variant_price'],
    ];
}

$products = [];
$prodResult = $conn->query('
    SELECT p.product_id, p.product_name, p.product_stocks, p.product_stocks_reference, p.product_cost, p.product_selling_price,
           p.product_category_id, pc.product_category, p.product_image, p.product_type, s.supplier_name, s.supplier_contact
    FROM products p
    JOIN product_category pc ON pc.product_category_id = p.product_category_id
    JOIN product_supplier s ON s.product_supplier_id = p.product_supplier_id
    ORDER BY pc.product_category, p.product_name
');
while ($row = $prodResult->fetch_assoc()) {
    $stock = (int)$row['product_stocks'];
    $reference = $row['product_stocks_reference'] !== null ? (int)$row['product_stocks_reference'] : null;
    $pid = (int)$row['product_id'];
    $products[] = [
        'id' => $pid,
        'name' => $row['product_name'],
        'category_id' => (int)$row['product_category_id'],
        'category_name' => $row['product_category'],
        'tag_class' => category_tag_class($row['product_category']),
        'type' => $row['product_type'],
        'stock' => $stock,
        'level' => stock_level($stock, $reference, $criticalStockThreshold, $lowStockThreshold),
        'cost' => (float)$row['product_cost'],
        'price' => (float)$row['product_selling_price'],
        'image' => $row['product_image'] ? '../' . $row['product_image'] : null,
        'supplier_name' => $row['supplier_name'],
        'supplier_contact' => $row['supplier_contact'],
        'recipe' => $recipesByProduct[$pid] ?? [],
        'variants' => $variantsByProduct[$pid] ?? [],
    ];
}

$reopenRecipeProductId = (int)($_GET['recipe'] ?? 0);
$reopenSizesProductId = (int)($_GET['sizes'] ?? 0);
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
      position: sticky; top: var(--header-h); z-index: 500;
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
    .content-row { display:flex; gap:20px; align-items:flex-start; }
    .content-row .table-wrap { flex:1; min-width:0; }
    .legend-card { width:190px; flex-shrink:0; background:var(--cream); border:1.5px solid var(--cream-dark); border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); padding:16px 18px; }
    .legend-card-title { font-family:var(--font-display); font-weight:700; font-size:13px; color:var(--mocha-deep); margin-bottom:12px; }
    .legend-item { display:flex; align-items:flex-start; gap:8px; margin-bottom:12px; }
    .legend-item:last-child { margin-bottom:0; }
    .legend-dot { width:9px; height:9px; border-radius:50%; margin-top:4px; flex-shrink:0; }
    .legend-dot.ok   { background:var(--sage); }
    .legend-dot.low  { background:#e67e22; }
    .legend-dot.crit { background:var(--red-soft); }
    .legend-label { font-size:12px; font-weight:700; color:var(--charcoal); }
    .legend-desc  { font-size:11px; color:#999; margin-top:2px; line-height:1.4; }
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
<body data-reopen-recipe="<?= $reopenRecipeProductId ?>" data-reopen-sizes="<?= $reopenSizesProductId ?>">

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
  <a href="staff_transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="staff_products.php" class="nav-item active"><i class="fas fa-boxes-stacked"></i> Products
    <?php if ($criticalCount > 0): ?>
      <span class="nav-badge"><?= $criticalCount ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  <a href="staff_reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <hr class="sidebar-divider"/>
  <div class="sidebar-footer">
    <p>SmartStock v1.0<br />Bean There Café<br />ISO/IEC 25010 Compliant</p>
  </div>
</nav>

<div id="main">
  <div class="page-strip">
    <div>
      <h1><i class="fas fa-boxes-stacked" style="color:var(--gold);font-size:18px;margin-right:8px;"></i>Products</h1>
      <div class="sub">Track stock levels, unit costs, and supplier contacts</div>
    </div>
    <div style="display:flex;gap:10px;">
      <button class="btn-outline" onclick="openModal('modal-add-category')"><i class="fas fa-tag"></i> Categories</button>
      <button class="btn-primary" onclick="openModal('modal-add-item')"><i class="fas fa-plus"></i> Add Product</button>
    </div>
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
    <div class="content-row">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Image</th><th>Product Name</th><th>Category</th><th>Stock</th><th>Unit Cost</th><th>Selling Price</th><th>Actions</th></tr></thead>
          <tbody id="product-tbody"></tbody>
        </table>
      </div>
      <div class="legend-card">
        <div class="legend-card-title">Legend</div>
        <div class="legend-item"><span class="legend-dot ok"></span><div><div class="legend-label">OK</div><div class="legend-desc">Stock at a healthy level</div></div></div>
        <div class="legend-item"><span class="legend-dot low"></span><div><div class="legend-label">Low</div><div class="legend-desc">Running low, reorder soon</div></div></div>
        <div class="legend-item"><span class="legend-dot crit"></span><div><div class="legend-label">Critical</div><div class="legend-desc">Reorder immediately</div></div></div>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-add-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-plus-circle" style="color:var(--gold);margin-right:8px;"></i>Add New Product</div>
    <div class="modal-sub">Fill in product details to add to the product catalog.</div>
    <form method="POST" action="staff_products.php" enctype="multipart/form-data" onsubmit="return checkDuplicateAndConfirm(event, this.elements['name'].value, products.map(p => p.name), 'product')">
      <input type="hidden" name="action" value="add">
      <div class="modal-field">
        <label>Product Name</label>
        <input type="text" name="name" placeholder="e.g. Caramel Macchiato" list="product-name-list" autocomplete="off" required />
        <datalist id="product-name-list">
          <?php foreach ($products as $p): ?>
            <option value="<?= htmlspecialchars($p['name']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="modal-field"><label>Product Image</label><input type="file" name="product_image" accept="image/*" /></div>
      <div class="modal-field">
        <label>Product Type</label>
        <select name="product_type" required onchange="toggleProductTypeFields(this, 'add-prepared-fields', 'add-madetoorder-fields')">
          <option value="">Select type…</option>
          <option value="made_to_order">Made to Order</option>
          <option value="prepared">Prepared</option>
        </select>
      </div>
      <div class="modal-field">
        <label>Category</label>
        <select name="category">
          <option value="">Select category…</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-field"><label>Selling Price (₱)</label><input type="number" name="price" min="0" step="0.01" placeholder="0.00" required /></div>
      <div class="modal-field"><label>Unit Cost (₱)</label><input type="number" name="cost" min="0" step="0.01" placeholder="0.00" required /></div>

      <div class="modal-field">
        <label>Sizes / Options <span style="text-transform:none;font-weight:400;">(optional — leave empty for a single-price product)</span></label>
        <div id="add-variant-rows"></div>
        <button type="button" class="btn-outline" style="margin-top:6px;" onclick="addVariantRow('add-variant-rows')"><i class="fas fa-plus"></i> Add Size</button>
      </div>

      <div id="add-madetoorder-fields" style="display:none;">
        <div class="modal-field">
          <label>Ingredients Needed</label>
          <div id="add-ingredient-rows"></div>
          <button type="button" class="btn-outline" style="margin-top:6px;" onclick="addIngredientRow('add-ingredient-rows')"><i class="fas fa-plus"></i> Add Ingredient</button>
        </div>
      </div>

      <div id="add-prepared-fields" style="display:none;">
        <div class="modal-field"><label>Stock Quantity</label><input type="number" name="stock" min="0" placeholder="0" /></div>
        <div class="modal-field"><label>Supplier Name</label><input type="text" name="supplier_name" placeholder="Supplier company"  /></div>
        <div class="modal-field"><label>Supplier Contact</label><input type="text" name="supplier_contact" placeholder="09XX-XXX-XXXX"  /></div>
      </div>

      <button type="submit" class="btn-modal-primary"><i class="fas fa-check" style="margin-right:6px;"></i>Add Product</button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-add-item')">Cancel</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modal-add-category">
  <div class="modal-box" style="max-width:400px;">
    <div class="modal-title"><i class="fas fa-tag" style="color:var(--gold);margin-right:8px;"></i>Manage Categories</div>
    <div class="modal-sub">Add, rename, or remove product categories.</div>

    <div id="category-list" style="margin-bottom:16px;max-height:240px;overflow-y:auto;">
      <?php foreach ($categories as $cat): ?>
        <div class="category-row" data-category-id="<?= (int)$cat['product_category_id'] ?>" data-category-name="<?= htmlspecialchars($cat['product_category'], ENT_QUOTES) ?>" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--cream-dark);">
          <span style="flex:1;font-size:13px;"><?= htmlspecialchars($cat['product_category']) ?></span>
          <button type="button" class="tbl-btn tbl-btn-edit" onclick="startEditCategory(this)">Edit</button>
          <form method="POST" action="staff_products.php" style="display:contents;" onsubmit="return confirmSubmit(event, 'Delete category &quot;<?= htmlspecialchars($cat['product_category'], ENT_QUOTES) ?>&quot;? Products still using it will need a new category first.', 'Delete', true)">
            <input type="hidden" name="action" value="delete_category">
            <input type="hidden" name="category_id" value="<?= (int)$cat['product_category_id'] ?>">
            <button type="submit" class="tbl-btn tbl-btn-del">Delete</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (empty($categories)): ?>
        <div style="color:#aaa;font-size:13px;padding:8px 0;">No categories yet.</div>
      <?php endif; ?>
    </div>

    <form method="POST" action="staff_products.php" style="border-top:1px solid var(--cream-dark);padding-top:14px;" onsubmit="return checkDuplicateAndConfirm(event, this.category_name.value, Array.from(document.querySelectorAll('#category-name-list option')).map(o => o.value), 'category')">
      <input type="hidden" name="action" value="add_category">
      <div class="modal-field">
        <label>New Category Name</label>
        <input type="text" name="category_name" placeholder="e.g. Pastries" list="category-name-list" autocomplete="off" required />
        <datalist id="category-name-list">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['product_category']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-plus" style="margin-right:6px;"></i>Add Category</button>
    </form>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-add-category')">Close</button>
  </div>
</div>

<div class="modal-overlay" id="modal-edit-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-pen" style="color:var(--gold);margin-right:8px;"></i>Edit Product</div>
    <div class="modal-sub">Update product details.</div>
    <form method="POST" action="staff_products.php" enctype="multipart/form-data">
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
        <label>Product Type</label>
        <select name="product_type" id="edit-product-type" required onchange="toggleProductTypeFields(this, 'edit-prepared-fields', 'edit-madetoorder-fields')">
          <option value="made_to_order">Made to Order</option>
          <option value="prepared">Prepared</option>
        </select>
      </div>
      <div class="modal-field">
        <label>Category</label>
        <select name="category" id="edit-category">
          <option value="">Select category…</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['product_category_id'] ?>"><?= htmlspecialchars($cat['product_category']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-field"><label>Selling Price (₱)</label><input type="number" name="price" id="edit-price" min="0" step="0.01" required /></div>
      <div class="modal-field"><label>Unit Cost (₱)</label><input type="number" name="cost" id="edit-cost" min="0" step="0.01" required /></div>

      <div class="modal-field">
        <label>Sizes / Options <span style="text-transform:none;font-weight:400;">(optional — leave empty for a single-price product)</span></label>
        <div id="edit-variant-rows"></div>
        <button type="button" class="btn-outline" style="margin-top:6px;" onclick="addVariantRow('edit-variant-rows')"><i class="fas fa-plus"></i> Add Size</button>
      </div>

      <div id="edit-madetoorder-fields" style="display:none;">
        <div class="modal-field">
          <label>Ingredients Needed</label>
          <div id="edit-ingredient-rows"></div>
          <button type="button" class="btn-outline" style="margin-top:6px;" onclick="addIngredientRow('edit-ingredient-rows')"><i class="fas fa-plus"></i> Add Ingredient</button>
        </div>
      </div>

      <div id="edit-prepared-fields" style="display:none;">
        <div class="modal-field"><label>Stock Quantity</label><input type="number" name="stock" id="edit-stock" min="0" /></div>
        <div class="modal-field"><label>Supplier Name</label><input type="text" name="supplier_name" id="edit-supplier-name" /></div>
        <div class="modal-field"><label>Supplier Contact</label><input type="text" name="supplier_contact" id="edit-supplier-contact" /></div>
      </div>

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
      <form method="POST" action="staff_products.php" style="border-top:1px solid var(--cream-dark);padding-top:14px;">
        <input type="hidden" name="action" value="recipe_add">
        <input type="hidden" name="product_id" id="recipe-add-product-id" value="">
        <div class="modal-field">
          <label>Ingredient</label>
          <select name="ingredient_id" id="recipe-ingredient-select" onchange="updateRecipeUnitLabel()" required>
            <option value="">Select ingredient…</option>
            <?php foreach ($ingredients as $ing): ?>
              <option value="<?= (int)$ing['product_ingredients_id'] ?>" data-unit="<?= htmlspecialchars($ing['ingredient_unit']) ?>" data-unit-label="<?= htmlspecialchars($ing['ingredient_unit_label']) ?>"><?= htmlspecialchars($ing['ingredient_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="modal-field">
            <label>Quantity per unit sold <span id="recipe-unit-label" style="text-transform:none;font-weight:400;"></span></label>
            <input type="number" name="quantity" id="recipe-quantity-input" min="0" step="0.01" placeholder="0.00" required />
          </div>
          <div class="modal-field">
            <label>Unit</label>
            <select name="unit" id="recipe-unit-select" required>
              <option value="">Select unit…</option>
              <?php foreach ($unitGroups as $family => $opts): ?>
                <optgroup label="<?= htmlspecialchars(UNIT_FAMILY_LABELS[$family] ?? ucfirst($family)) ?>">
                  <?php foreach ($opts as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['key']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <label class="modal-field" style="display:flex;align-items:center;gap:6px;font-size:12px;color:#888;cursor:pointer;font-weight:400;">
          <input type="checkbox" name="is_choice" value="1" style="width:auto;" />
          Flavor choice option — customer picks one; only that ingredient is deducted from stock
        </label>
        <button type="submit" class="btn-modal-primary"><i class="fas fa-plus" style="margin-right:6px;"></i>Add to Recipe</button>
      </form>
    <?php endif; ?>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-recipe-item')">Close</button>
  </div>
</div>

<div class="modal-overlay" id="modal-sizes-item">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-ruler-combined" style="color:var(--gold);margin-right:8px;"></i>Sizes / Options — <span id="sizes-product-name"></span></div>
    <div class="modal-sub">e.g. Small / Medium / Large for pizza, or Hot / Iced for coffee. Each option has its own price — leave empty for a single-size product. When at least one exists, the cashier must pick one at checkout.</div>
    <div id="sizes-items-list" style="margin-bottom:16px;"></div>
    <form method="POST" action="staff_products.php" style="border-top:1px solid var(--cream-dark);padding-top:14px;">
      <input type="hidden" name="action" value="variant_add">
      <input type="hidden" name="product_id" id="sizes-add-product-id" value="">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="modal-field">
          <label>Size / Option Name</label>
          <input type="text" name="variant_name" placeholder="e.g. Small, Hot" required />
        </div>
        <div class="modal-field">
          <label>Price (₱)</label>
          <input type="number" name="variant_price" min="0" step="0.01" placeholder="0.00" required />
        </div>
      </div>
      <button type="submit" class="btn-modal-primary"><i class="fas fa-plus" style="margin-right:6px;"></i>Add Size / Option</button>
    </form>
    <button type="button" class="btn-modal-cancel" onclick="closeModal('modal-sizes-item')">Close</button>
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
<script id="ingredients-data" type="application/json"><?= json_encode($ingredients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script id="measurement-units-data" type="application/json"><?= json_encode($unitGroups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

<script>
  // Product catalog — loaded from the products table (see products-data script tag above)
  const products = JSON.parse(document.getElementById('products-data').textContent);
  // Ingredient list (for the made-to-order recipe builder) — from product_ingredients
  const allIngredients = JSON.parse(document.getElementById('ingredients-data').textContent);
  // Unit choices offered on each recipe line, grouped by family: { mass: [...], volume: [...], count: [...] }
  const unitGroups = JSON.parse(document.getElementById('measurement-units-data').textContent);
  const unitFamilyLabels = { mass: 'Weight', volume: 'Volume', count: 'Count' };
  const unitLabelByKey = Object.fromEntries(Object.values(unitGroups).flat().map(o => [o.key, o.label]));

  document.addEventListener('DOMContentLoaded', () => {
    updateClock(); setInterval(updateClock, 1000);
    renderProducts('', '', '');

    const reopenId = parseInt(document.body.dataset.reopenRecipe || '0', 10);
    if (reopenId > 0) openRecipeModal(reopenId);

    const reopenSizesId = parseInt(document.body.dataset.reopenSizes || '0', 10);
    if (reopenSizesId > 0) openSizesModal(reopenSizesId);

    <?php if ($msg): ?>
      showToast(<?= json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($msgType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
    <?php endif; ?>
  });

  function updateClock() {
    const clock = document.getElementById('clock');
    if (clock) clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function toggleProductTypeFields(select, preparedWrapId, madeToOrderWrapId) {
    const isPrepared = select.value === 'prepared';
    const isMadeToOrder = select.value === 'made_to_order';
    document.getElementById(preparedWrapId).style.display = isPrepared ? 'block' : 'none';
    document.getElementById(madeToOrderWrapId).style.display = isMadeToOrder ? 'block' : 'none';
    // Hidden fields shouldn't block submission, so only require them for the visible type
    document.querySelectorAll('#' + preparedWrapId + ' input').forEach(inp => { inp.required = isPrepared; });
  }

  function addIngredientRow(containerId, selectedIngredientId = '', quantity = '', selectedUnit = '', isChoice = false) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const row = document.createElement('div');
    row.style.cssText = 'padding-bottom:8px;margin-bottom:8px;border-bottom:1px solid var(--cream-dark);';
    const options = allIngredients.map(ing =>
      `<option value="${ing.product_ingredients_id}" data-unit="${ing.ingredient_unit}" ${String(ing.product_ingredients_id) === String(selectedIngredientId) ? 'selected' : ''}>${ing.ingredient_name} (${ing.ingredient_unit_label})</option>`
    ).join('');
    // Default the unit to the ingredient's own unit when nothing was passed in (new row), so the
    // common case — recipe unit matches stock unit — needs no conversion and can't be mismatched.
    const selectedIngredient = allIngredients.find(ing => String(ing.product_ingredients_id) === String(selectedIngredientId));
    const effectiveSelectedUnit = selectedUnit || (selectedIngredient ? selectedIngredient.ingredient_unit : '');
    const unitOptionGroups = Object.entries(unitGroups).map(([family, opts]) => `
      <optgroup label="${unitFamilyLabels[family] || family}">
        ${opts.map(o => `<option value="${o.key}" ${o.key === effectiveSelectedUnit ? 'selected' : ''}>${o.label}</option>`).join('')}
      </optgroup>
    `).join('');
    row.innerHTML = `
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
        <select name="ingredient_id[]" style="flex:2;padding:9px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);">
          <option value="">Select ingredient…</option>
          ${options}
        </select>
        <input type="number" name="ingredient_qty[]" min="0" step="0.01" placeholder="Qty" value="${quantity}" style="flex:1;padding:9px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);" />
        <select name="ingredient_unit[]" style="flex:1;padding:9px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);">
          <option value="">Unit…</option>
          ${unitOptionGroups}
        </select>
        <button type="button" class="tbl-btn tbl-btn-del" onclick="this.parentElement.parentElement.remove()">✕</button>
      </div>
      <label style="display:flex;align-items:center;gap:6px;font-size:11px;color:#888;cursor:pointer;">
        <input type="hidden" name="ingredient_is_choice[]" value="${isChoice ? '1' : '0'}">
        <input type="checkbox" ${isChoice ? 'checked' : ''} onchange="this.previousElementSibling.value = this.checked ? '1' : '0'" style="width:auto;" />
        Flavor choice option — customer picks one; only that ingredient is deducted from stock
      </label>
    `;
    container.appendChild(row);
  }

  function addVariantRow(containerId, name = '', price = '') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px;';
    const nameInput = document.createElement('input');
    nameInput.type = 'text'; nameInput.name = 'variant_name[]'; nameInput.placeholder = 'e.g. Small, Hot'; nameInput.value = name;
    nameInput.style.cssText = 'flex:2;padding:9px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);';
    const priceInput = document.createElement('input');
    priceInput.type = 'number'; priceInput.name = 'variant_price[]'; priceInput.min = '0'; priceInput.step = '0.01'; priceInput.placeholder = 'Price'; priceInput.value = price;
    priceInput.style.cssText = 'flex:1;padding:9px 13px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);';
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button'; removeBtn.className = 'tbl-btn tbl-btn-del'; removeBtn.textContent = '✕';
    removeBtn.onclick = () => row.remove();
    row.append(nameInput, priceInput, removeBtn);
    container.appendChild(row);
  }

  function money(n) {
    return '₱' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function renderProducts(search, catId, level) {
    const tbody = document.getElementById('product-tbody');
    if (!tbody) return;

    if (products.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:24px 8px;">No products yet. Click "Add Product" to add your first item.</td></tr>';
      return;
    }

    const filtered = products.filter(p => {
      const s = !search || p.name.toLowerCase().includes(search.toLowerCase()) || p.supplier_name.toLowerCase().includes(search.toLowerCase());
      const c = !catId || String(p.category_id) === String(catId);
      const l = !level || p.level === level;
      return s && c && l;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#aaa;padding:24px 8px;">No products match your filters.</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map(p => {
      const isMotd = p.type === 'made_to_order';
      return `
      <tr>
        <td>${p.image ? `<img src="${p.image}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;">` : `<div style="width:40px;height:40px;border-radius:6px;background:var(--cream-dark);"></div>`}</td>
        <td style="font-weight:600;">${p.name}${isMotd ? ' <span class="tag tag-supply" style="margin-left:4px;">Made to Order</span>' : ''}</td>
        <td><span class="tag ${p.tag_class}">${p.category_name}</span></td>
        <td>${isMotd ? '<span class="text-muted">—</span>' : `<div class="stock-indicator stock-${p.level}"><div class="stock-dot"></div>${p.stock}</div>`}</td>
        <td class="text-mono">${money(p.cost)}</td>
        <td class="text-mono">${money(p.price)}</td>
        <td style="display:flex;gap:6px;align-items:center;">
          <button class="tbl-btn tbl-btn-edit" onclick="openEditModal(${p.id})">Edit</button>
          ${isMotd ? `<button class="tbl-btn tbl-btn-edit" onclick="openRecipeModal(${p.id})">Recipe</button>` : ''}
          <button class="tbl-btn tbl-btn-edit" onclick="openSizesModal(${p.id})">Sizes${p.variants.length > 0 ? ` (${p.variants.length})` : ''}</button>
          <form method="POST" action="staff_products.php" style="display:contents;" onsubmit="return confirmDelete(event, 'Delete ${p.name.replace(/'/g, "\\'")}?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="product_id" value="${p.id}">
            <button type="submit" class="tbl-btn tbl-btn-del">Delete</button>
          </form>
        </td>
      </tr>
    `;
    }).join('');
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
    document.getElementById('edit-price').value = p.price;

    const typeSelect = document.getElementById('edit-product-type');
    typeSelect.value = p.type;
    toggleProductTypeFields(typeSelect, 'edit-prepared-fields', 'edit-madetoorder-fields');

    document.getElementById('edit-stock').value = p.type === 'prepared' ? p.stock : '';
    document.getElementById('edit-cost').value = p.cost;
    document.getElementById('edit-supplier-name').value = p.type === 'prepared' ? p.supplier_name : '';
    document.getElementById('edit-supplier-contact').value = p.type === 'prepared' ? p.supplier_contact : '';

    const ingredientRows = document.getElementById('edit-ingredient-rows');
    ingredientRows.innerHTML = '';
    (p.recipe || []).forEach(r => addIngredientRow('edit-ingredient-rows', r.ingredient_id, r.quantity, r.unit, r.is_choice));

    const variantRows = document.getElementById('edit-variant-rows');
    variantRows.innerHTML = '';
    (p.variants || []).forEach(v => addVariantRow('edit-variant-rows', v.name, v.price));

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
    const unitSelect = document.getElementById('recipe-unit-select');
    if (unitSelect) unitSelect.value = '';
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
        <div style="font-size:13px;"><strong>${r.ingredient_name}</strong> — ${r.quantity} ${unitLabelByKey[r.unit] || r.unit} per unit sold${r.is_choice ? ' <span class="tag tag-supply">Flavor choice</span>' : ''}</div>
        <form method="POST" action="staff_products.php" onsubmit="return confirmDelete(event, 'Remove ${r.ingredient_name.replace(/'/g, "\\'")} from this recipe?')">
          <input type="hidden" name="action" value="recipe_remove">
          <input type="hidden" name="item_id" value="${r.item_id}">
          <input type="hidden" name="product_id" value="${p.id}">
          <button type="submit" class="tbl-btn tbl-btn-del">Remove</button>
        </form>
      </div>
    `).join('');
  }

  function openSizesModal(id) {
    const p = products.find(x => x.id === id);
    if (!p) return;
    document.getElementById('sizes-product-name').textContent = p.name;
    document.getElementById('sizes-add-product-id').value = p.id;
    renderSizeItems(p);
    openModal('modal-sizes-item');
  }

  function renderSizeItems(p) {
    const list = document.getElementById('sizes-items-list');
    if (!p.variants || p.variants.length === 0) {
      list.innerHTML = '<div style="color:#aaa;font-size:13px;padding:8px 0;">No sizes/options yet — this product sells at its base price only.</div>';
      return;
    }
    list.innerHTML = p.variants.map(v => `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--cream-dark);">
        <div style="font-size:13px;"><strong>${v.name}</strong> — ${money(v.price)}</div>
        <form method="POST" action="staff_products.php" onsubmit="return confirmDelete(event, 'Remove the ${v.name.replace(/'/g, "\\'")} option?')">
          <input type="hidden" name="action" value="variant_remove">
          <input type="hidden" name="variant_id" value="${v.variant_id}">
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
    if (label) label.textContent = opt && opt.dataset.unitLabel ? '(' + opt.dataset.unitLabel + ')' : '';
    // Default the Unit dropdown to the ingredient's own unit — the common case needs no
    // conversion at checkout, so this is the safest default (still freely changeable).
    const unitSelect = document.getElementById('recipe-unit-select');
    if (unitSelect && opt && opt.dataset.unit) unitSelect.value = opt.dataset.unit;
  }

  function startEditCategory(btn) {
    const row = btn.closest('.category-row');
    const originalHtml = row.innerHTML;
    const id = row.dataset.categoryId;
    const currentName = row.dataset.categoryName;
    row.innerHTML = `
      <form method="POST" action="staff_products.php" style="display:flex;gap:8px;align-items:center;flex:1;" onsubmit="return confirmSubmit(event, 'Rename this category to \\'' + this.category_name.value.trim() + '\\'?', 'Save')">
        <input type="hidden" name="action" value="edit_category">
        <input type="hidden" name="category_id" value="${id}">
        <input type="text" name="category_name" value="${currentName.replace(/"/g, '&quot;')}" style="flex:1;padding:7px 10px;border-radius:8px;border:1.5px solid var(--cream-dark);background:var(--cream);font-family:var(--font-body);font-size:13px;color:var(--charcoal);" required />
        <button type="submit" class="tbl-btn tbl-btn-edit">Save</button>
        <button type="button" class="tbl-btn tbl-btn-del" data-cancel-edit>Cancel</button>
      </form>
    `;
    row.querySelector('[data-cancel-edit]').addEventListener('click', () => { row.innerHTML = originalHtml; });
  }

  function openModal(id)  { document.getElementById(id).classList.add('show'); }
  function closeModal(id) { document.getElementById(id).classList.remove('show'); }
  document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); })
  );

  // In-page replacement for the native confirm() dialog — reused for deletes and other
  // actions (e.g. Add Category) that should ask "are you sure?" before submitting.
  let pendingConfirmForm = null;
  function confirmSubmit(event, message, confirmLabel = 'Confirm', danger = false) {
    event.preventDefault();
    pendingConfirmForm = event.target;
    document.getElementById('confirm-delete-message').textContent = message;
    const yesBtn = document.getElementById('confirm-delete-yes');
    yesBtn.textContent = confirmLabel;
    yesBtn.style.background = danger ? 'var(--red-soft)' : 'var(--mocha)';
    openModal('modal-confirm-delete');
    return false;
  }
  function confirmDelete(event, message) {
    return confirmSubmit(event, message, 'Delete', true);
  }
  document.getElementById('confirm-delete-yes').addEventListener('click', () => {
    closeModal('modal-confirm-delete');
    if (pendingConfirmForm) { pendingConfirmForm.submit(); pendingConfirmForm = null; }
  });

  // Warns before inserting a product/category/ingredient that looks like it might already
  // exist (typo, different casing, or a near-identical name) — a cheap Levenshtein-distance
  // check against everything already in the list, no external library needed.
  function normalizeText(s) {
    return (s || '').toLowerCase().trim().replace(/\s+/g, ' ');
  }
  // Character bigrams with whitespace stripped — order/spacing-independent, so "ground pork"
  // and "porkground" still overlap almost completely even though the words are swapped and
  // the space is gone; only the bigram at the word "seam" changes.
  function bigrams(s) {
    const flat = s.replace(/\s+/g, '');
    const grams = [];
    for (let i = 0; i < flat.length - 1; i++) grams.push(flat.slice(i, i + 2));
    return grams;
  }
  // Sørensen–Dice coefficient: 2 * shared bigrams / total bigrams — 0 (nothing alike) to 1 (identical).
  function diceCoefficient(a, b) {
    const gramsA = bigrams(a), gramsB = bigrams(b);
    if (gramsA.length === 0 || gramsB.length === 0) return gramsA.join('') === gramsB.join('') ? 1 : 0;
    const counts = new Map();
    for (const g of gramsA) counts.set(g, (counts.get(g) || 0) + 1);
    let shared = 0;
    for (const g of gramsB) {
      const c = counts.get(g) || 0;
      if (c > 0) { shared++; counts.set(g, c - 1); }
    }
    return (2 * shared) / (gramsA.length + gramsB.length);
  }
  const SIMILARITY_THRESHOLD = 0.7;
  // Returns the existing name this looks like a near-duplicate of, or null if it looks distinct.
  function findSimilarExisting(newName, existingNames) {
    const norm = normalizeText(newName);
    if (!norm) return null;
    for (const existing of existingNames) {
      const existingNorm = normalizeText(existing);
      if (!existingNorm) continue;
      if (existingNorm === norm) return existing;
      if (norm.length >= 4 && existingNorm.length >= 4) {
        if (existingNorm.includes(norm) || norm.includes(existingNorm)) return existing;
        if (diceCoefficient(norm, existingNorm) >= SIMILARITY_THRESHOLD) return existing;
      }
    }
    return null;
  }
  function checkDuplicateAndConfirm(event, newName, existingNames, itemType) {
    const match = findSimilarExisting(newName, existingNames);
    if (!match) return true;
    event.preventDefault();
    pendingConfirmForm = event.target;
    document.getElementById('confirm-delete-message').textContent =
      `Are you sure you want to add this ${itemType}? It seems "${match}" is already inserted inside.`;
    const yesBtn = document.getElementById('confirm-delete-yes');
    yesBtn.textContent = 'Add Anyway';
    yesBtn.style.background = 'var(--mocha)';
    openModal('modal-confirm-delete');
    return false;
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
