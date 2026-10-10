<?php
session_start();



if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

// Do not block the owner's other requests while the archive is being built.
session_write_close();
require_once __DIR__ . '/../db_connect.php';

$tempDir = null;
$zip = null;
$zipOpen = false;
$cleanup = static function () use (&$tempDir, &$zip, &$zipOpen): void {
    if ($zipOpen && $zip instanceof ZipArchive) {
        $zip->close();
        $zipOpen = false;
    }
    if ($tempDir !== null) {
        foreach (glob($tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tempDir);
    }
};
register_shutdown_function($cleanup);

try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP support is unavailable. Enable the PHP zip extension and try again.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('The server does not allow the database backup process to run.');
    }

    // Laragon uses version-specific installation folders.
    $matches = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe');
    $mysqldumpPath = $matches[0] ?? null;
    if ($mysqldumpPath === null) {
        throw new RuntimeException('The MySQL backup tool is unavailable. Check the Laragon installation.');
    }

    set_time_limit(0);
    $tempDir = sys_get_temp_dir() . '/bean_backup_' . bin2hex(random_bytes(16));
    if (!mkdir($tempDir, 0700)) {
        throw new RuntimeException('Could not prepare temporary backup storage.');
    }
    $sqlPath = $tempDir . '/database.sql';
    $zipPath = $tempDir . '/backup.zip';
    $errPath = $tempDir . '/dump.err';
    $configPath = $tempDir . '/client.cnf';

    // Keep credentials out of command-line arguments and the downloaded archive.
    $optionValue = static function (string $value): string {
        return '"' . strtr($value, ["\\" => "\\\\", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    };
    $config = "[client]\nhost=" . $optionValue($db_host)
        . "\nuser=" . $optionValue($db_user)
        . "\npassword=" . $optionValue($db_pass) . "\n";
    if (file_put_contents($configPath, $config) === false) {
        throw new RuntimeException('Could not prepare the database backup configuration.');
    }

    // An argument array avoids shell quoting problems with spaces in Windows paths.
    // No table/date filter: include the entire database, including report source data.
    $command = [
        $mysqldumpPath, '--defaults-extra-file=' . $configPath,
        '--single-transaction', '--routines', '--triggers', '--events',
        '--hex-blob', '--default-character-set=utf8mb4', '--no-tablespaces',
        '--set-gtid-purged=OFF', '--result-file=' . $sqlPath, $db_name,
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['file', $tempDir . '/dump.out', 'w'],
        2 => ['file', $errPath, 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the database backup.');
    }
    fclose($pipes[0]);
    $returnCode = proc_close($process);
    @unlink($configPath);
    clearstatcache(true, $sqlPath);
    if ($returnCode !== 0 || !is_file($sqlPath) || filesize($sqlPath) === 0) {
        error_log('Database backup failed: ' . (is_file($errPath) ? file_get_contents($errPath) : 'No diagnostics'));
        throw new RuntimeException('Could not export the database. Check the server error log and try again.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the backup ZIP file.');
    }
    $zipOpen = true;
    if (!$zip->addFile($sqlPath, 'database.sql') || !$zip->addEmptyDir('uploads')) {
        throw new RuntimeException('Could not add the database to the backup.');
    }

    $uploadsPath = __DIR__ . '/../uploads';
    if (!is_dir($uploadsPath) || !is_readable($uploadsPath) || is_link($uploadsPath)) {
        throw new RuntimeException('The uploads folder is missing or unreadable. No incomplete backup was downloaded.');
    }
    $uploadsPath = realpath($uploadsPath);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file) {
        // Never follow links outside the uploads tree or silently omit unreadable files.
        if ($file->isLink() || !$file->isReadable()) {
            throw new RuntimeException('An uploaded file is unreadable or is a symbolic link. Check the uploads folder and retry.');
        }
        $entry = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploadsPath) + 1));
        $added = $file->isDir() ? $zip->addEmptyDir($entry) : $zip->addFile($file->getPathname(), $entry);
        if (!$added) {
            throw new RuntimeException('Could not include all uploaded files in the backup.');
        }
    }

    $createdAt = date(DATE_ATOM);
    $instructions = <<<TEXT
BEAN THERE CAFE - DATABASE AND UPLOADS BACKUP
Created: {$createdAt}
Database: {$db_name}

CONTENTS
- database.sql: every database table and record, including sales/transactions,
  product details, variants, ingredient details, recipes, suppliers, expenses,
  users, and settings. Sales reports are generated from these records; this
  archive is not a PDF or spreadsheet export of the report screen.
- uploads/: all uploaded files with their original folder names and paths.
  Only existing files can be backed up; previously missing images are not recovered.

RESTORE (test on a separate database and application copy first)
1. Keep a safety copy of the current database and uploads before restoring.
2. Stop staff/owner activity during recovery. Use a compatible MySQL version
   and the matching Bean There Cafe application code (not included here).
3. Create/select the destination database in phpMyAdmin, then import database.sql.
   WARNING: the SQL replaces backed-up tables and their existing records.
   For large backups, use the MySQL client instead of browser upload limits.
4. Restore uploads/ into the application root, retaining all subfolders and
   filenames. Use the matching backed-up uploads rather than unrelated files.
5. Check db_connect.php points to the destination database. Verify sign-in,
   sales totals, products, ingredients, and images before resuming operations.

IMPORTANT
- This ZIP is NOT encrypted. It contains sensitive business and account data.
  Store it securely and keep another copy off this computer.
- Database credentials and application source code are not included.
- For matching database/image contents, download while nobody is editing data
  or uploading/deleting images. Database and filesystem snapshots are not atomic.
- Automatic in-app restoration is not provided by this download feature.
TEXT;
    if (!$zip->addFromString('RESTORE.txt', $instructions)) {
        throw new RuntimeException('Could not include the recovery instructions.');
    }
    if (!$zip->close()) {
        throw new RuntimeException('Could not finish the ZIP file. Check available disk space and uploaded files.');
    }
    $zipOpen = false;
    clearstatcache(true, $zipPath);
    $download = fopen($zipPath, 'rb');
    if ($download === false) {
        throw new RuntimeException('Could not read the completed backup.');
    }

    $filename = 'bean_there_cafe_backup_' . date('Y-m-d_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    fpassthru($download);
    fclose($download);
} catch (Throwable $e) {
    error_log('Backup failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Backup failed. ' . ($e instanceof RuntimeException
        ? $e->getMessage() : 'An unexpected error occurred. Check the server error log and try again.');
} finally {
    $cleanup();
}
exit;
