-- --------------------------------------------------------
-- Changes made to bean_there_cafe during this session that are
-- NOT reflected in bean_there_cafe.sql.
--
-- Everything below has already been applied to the live database.
-- This file is only a record of what changed, so bean_there_cafe.sql
-- can be re-exported/updated to match, or so these statements can be
-- replayed against another copy of the database.
-- --------------------------------------------------------

-- Dumping structure changes for table bean_there_cafe.transactions
-- Adds a permanent snapshot of who processed the sale, and lets a deleted
-- user account detach from old transactions instead of blocking deletion.
ALTER TABLE transactions ADD COLUMN cashier_username VARCHAR(50) NULL AFTER user_id;

-- Lets the cashier attach a free-text note to a sale at checkout (e.g. an allergy
-- warning, a special request, a reminder for a follow-up) — optional, shown on the
-- receipt when present and reviewable later from the Transaction History detail view.
ALTER TABLE transactions ADD COLUMN notes TEXT NULL AFTER discount;

UPDATE transactions t JOIN users u ON u.user_id = t.user_id
SET t.cashier_username = u.username
WHERE t.cashier_username IS NULL;

ALTER TABLE transactions MODIFY user_id INT NULL;
ALTER TABLE transactions DROP FOREIGN KEY fk_transactions_user;
ALTER TABLE transactions ADD CONSTRAINT fk_transactions_user
  FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL;


-- Dumping structure changes for table bean_there_cafe.transaction_items
-- Adds permanent snapshots of what was sold and which flavor/choice ingredient
-- was picked, and lets a deleted product detach from old transaction_items
-- instead of blocking deletion.
ALTER TABLE transaction_items ADD COLUMN product_name_snapshot VARCHAR(50) NULL AFTER product_id;

UPDATE transaction_items ti JOIN products p ON p.product_id = ti.product_id
SET ti.product_name_snapshot = p.product_name
WHERE ti.product_name_snapshot IS NULL;

ALTER TABLE transaction_items MODIFY product_id INT NULL;
ALTER TABLE transaction_items DROP FOREIGN KEY fk_product_id;
ALTER TABLE transaction_items ADD CONSTRAINT fk_product_id
  FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE SET NULL;

ALTER TABLE transaction_items ADD COLUMN chosen_ingredient_name_snapshot VARCHAR(50) NULL AFTER product_name_snapshot;


-- Dumping structure changes for table bean_there_cafe.product_ingredient_items
-- Marks a recipe row as one of several mutually-exclusive flavor options
-- (e.g. Cheese Powder vs BBQ vs Sour Cream) instead of an always-required
-- ingredient. Default 0 keeps every existing recipe row "always required".
ALTER TABLE product_ingredient_items ADD COLUMN is_flavor_choice TINYINT(1) NOT NULL DEFAULT 0 AFTER unit;


-- Dumping structure changes for table bean_there_cafe.product_ingredients
-- Supplier and contact are now tracked per ingredient (set when adding/editing
-- an ingredient in Inventory) instead of per prepared product.
ALTER TABLE product_ingredients ADD COLUMN ingredient_supplier VARCHAR(100) NULL AFTER ingredient_unit;
ALTER TABLE product_ingredients ADD COLUMN ingredient_contact VARCHAR(100) NULL AFTER ingredient_supplier;


-- Dumping structure changes for table bean_there_cafe.products and bean_there_cafe.product_ingredients
-- Critical/Low Stock Threshold settings are now percentages instead of raw unit counts. A
-- percentage needs a "100%" reference point, so these columns record the stock amount that was
-- last typed into the Add/Edit form — that becomes the new 100% mark every time an item is
-- manually set/restocked. The backfill sets every existing row's reference to its current
-- stock, so nothing reads as under-threshold immediately after this migration.
ALTER TABLE products ADD COLUMN product_stocks_reference INT NULL AFTER product_stocks;
UPDATE products SET product_stocks_reference = product_stocks WHERE product_stocks_reference IS NULL;

ALTER TABLE product_ingredients ADD COLUMN ingredient_stock_reference DECIMAL(10,2) NULL AFTER ingredient_stock;
UPDATE product_ingredients SET ingredient_stock_reference = ingredient_stock WHERE ingredient_stock_reference IS NULL;


-- Dumping structure changes for table bean_there_cafe.users
-- Lets the owner set two security questions (with hashed answers) so a forgotten
-- password can be reset via recover_account.php without needing email/SMS delivery.
ALTER TABLE users
  ADD COLUMN security_question_1 VARCHAR(255) NULL AFTER status,
  ADD COLUMN security_answer_1_hash VARCHAR(255) NULL AFTER security_question_1,
  ADD COLUMN security_question_2 VARCHAR(255) NULL AFTER security_answer_1_hash,
  ADD COLUMN security_answer_2_hash VARCHAR(255) NULL AFTER security_question_2;


-- Dumping structure changes for table bean_there_cafe.transactions
-- Lets a completed sale be voided (transaction_status = 'cancelled') with stock restored
-- precisely: product_stocks via transaction_items, and ingredient_stock via the exact
-- per-ingredient amounts recorded here at sale time, even if the recipe has changed since.
ALTER TABLE transactions ADD COLUMN ingredient_usage_snapshot JSON NULL AFTER notes;
ALTER TABLE transactions MODIFY transaction_status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed';
