<?php
function ensure_transaction_item_discount_tracking(mysqli $conn): void
{
    $column = $conn->query("SHOW COLUMNS FROM transaction_items LIKE 'discount_applied'");
    if (!$column) {
        throw new RuntimeException('Could not check transaction item discount tracking: ' . $conn->error);
    }

    $needsColumn = $column->num_rows === 0;
    $column->close();

    if ($needsColumn && !$conn->query('ALTER TABLE transaction_items ADD COLUMN discount_applied TINYINT(1) DEFAULT NULL AFTER subtotal')) {
        throw new RuntimeException('Could not add transaction item discount tracking: ' . $conn->error);
    }
}
