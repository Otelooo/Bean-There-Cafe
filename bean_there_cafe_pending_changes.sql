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
