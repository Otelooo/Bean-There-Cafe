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

function ensure_daily_transaction_numbering(mysqli $conn): void
{
    $column = $conn->query("SHOW COLUMNS FROM transactions LIKE 'daily_transaction_number'");
    if (!$column) {
        throw new RuntimeException('Could not check daily transaction numbering: ' . $conn->error);
    }
    $columnInfo = $column->fetch_assoc();
    $needsColumn = $columnInfo === null;
    $needsNotNull = $needsColumn || strtoupper($columnInfo['Null']) === 'YES';
    $column->close();

    if ($needsColumn && !$conn->query('ALTER TABLE transactions ADD COLUMN daily_transaction_number INT UNSIGNED NULL AFTER transaction_id')) {
        $column = $conn->query("SHOW COLUMNS FROM transactions LIKE 'daily_transaction_number'");
        $addedByAnotherRequest = $column && $column->num_rows > 0;
        if ($column) {
            $column->close();
        }
        if (!$addedByAnotherRequest) {
            throw new RuntimeException('Could not add daily transaction numbering: ' . $conn->error);
        }
    }

    $sequenceTable = $conn->query("SHOW TABLES LIKE 'daily_transaction_sequences'");
    if (!$sequenceTable) {
        throw new RuntimeException('Could not check daily transaction sequences: ' . $conn->error);
    }
    $needsSequenceSeed = $sequenceTable->num_rows === 0;
    $sequenceTable->close();

    if (!$conn->query("CREATE TABLE IF NOT EXISTS daily_transaction_sequences (
        sequence_date DATE NOT NULL,
        last_number INT UNSIGNED NOT NULL,
        PRIMARY KEY (sequence_date)
    ) ENGINE=InnoDB")) {
        throw new RuntimeException('Could not create daily transaction sequence table: ' . $conn->error);
    }

    $missingNumbers = $conn->query('SELECT 1 FROM transactions WHERE daily_transaction_number IS NULL LIMIT 1');
    if (!$missingNumbers) {
        throw new RuntimeException('Could not check existing daily transaction numbers: ' . $conn->error);
    }
    $needsBackfill = $missingNumbers->num_rows > 0;
    $missingNumbers->close();

    if ($needsBackfill) {
        if (!$conn->query('
            UPDATE transactions t
            JOIN (
                SELECT transaction_id,
                       ROW_NUMBER() OVER (PARTITION BY DATE(transaction_date) ORDER BY transaction_id) AS daily_number
                FROM transactions
            ) numbered ON numbered.transaction_id = t.transaction_id
            SET t.daily_transaction_number = numbered.daily_number
            WHERE t.daily_transaction_number IS NULL
        ')) {
            throw new RuntimeException('Could not assign daily numbers to existing transactions: ' . $conn->error);
        }
    }
    if ($needsNotNull || $needsBackfill) {
        if (!$conn->query('ALTER TABLE transactions MODIFY daily_transaction_number INT UNSIGNED NOT NULL')) {
            throw new RuntimeException('Could not finalize daily transaction numbering: ' . $conn->error);
        }
    }

    if ($needsSequenceSeed || $needsBackfill) {
        if (!$conn->query('
            INSERT INTO daily_transaction_sequences (sequence_date, last_number)
            SELECT DATE(transaction_date), MAX(daily_transaction_number)
            FROM transactions
            GROUP BY DATE(transaction_date)
            ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))
        ')) {
            throw new RuntimeException('Could not initialize daily transaction sequences: ' . $conn->error);
        }
    }
}

function next_daily_transaction_number(mysqli $conn): int
{
    if (!$conn->query('
        INSERT INTO daily_transaction_sequences (sequence_date, last_number)
        VALUES (CURDATE(), 1)
        ON DUPLICATE KEY UPDATE last_number = last_number + 1
    ')) {
        throw new RuntimeException('Could not allocate a daily transaction number: ' . $conn->error);
    }

    $result = $conn->query('SELECT last_number FROM daily_transaction_sequences WHERE sequence_date = CURDATE() FOR UPDATE');
    if (!$result) {
        throw new RuntimeException('Could not retrieve the daily transaction number: ' . $conn->error);
    }
    $row = $result->fetch_assoc();
    $result->close();
    if (!$row) {
        throw new RuntimeException('The daily transaction number was not allocated.');
    }

    return (int)$row['last_number'];
}
