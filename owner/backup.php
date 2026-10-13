<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../settings_helper.php';

// ── Configuration ───────────────────────────────────────────────────────────
const BACKUP_KEEP   = 10;    // newest archives kept on the server (retention)
const BACKUP_PREFIX = 'bean_there_cafe_backup_';

$backupDir = dirname(__DIR__) . '/backups';
$backupPattern = '/^' . preg_quote(BACKUP_PREFIX, '/') . '\d{4}-\d{2}-\d{2}_\d{6}\.zip$/';

// ── CSRF protection for state-changing actions ──────────────────────────────
if (empty($_SESSION['backup_csrf'])) {
    $_SESSION['backup_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['backup_csrf'];

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Locate mysqldump in a way that works both on the current Laragon setup and
 * on typical internet hosts. Order: MYSQLDUMP_PATH env override, Laragon,
 * XAMPP, common Linux locations, then bare "mysqldump" resolved via PATH.
 */
function backup_find_mysqldump(): string
{
    $override = getenv('MYSQLDUMP_PATH');
    if (is_string($override) && $override !== '' && is_file($override)) {
        return $override;
    }
    $candidates = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [];
    $candidates[] = 'C:/xampp/mysql/bin/mysqldump.exe';
    $candidates[] = '/usr/bin/mysqldump';
    $candidates[] = '/usr/local/bin/mysqldump';
    $candidates[] = '/opt/lampp/bin/mysqldump';
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    // Fall back to the OS PATH search at execution time.
    return 'mysqldump';
}

/** MariaDB's dump tool rejects --set-gtid-purged, so detect it before use. */
function backup_mysqldump_is_mariadb(string $mysqldump): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $process = @proc_open([$mysqldump, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return false;
    }
    $output = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return stripos($output, 'mariadb') !== false;
}

function backup_format_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function backup_list(string $dir, string $pattern): array
{
    $items = [];
    foreach (glob($dir . '/' . BACKUP_PREFIX . '*.zip') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match($pattern, $name) || !is_file($path)) {
            continue;
        }
        clearstatcache(true, $path);
        $items[] = [
            'name'  => $name,
            'size'  => (int)filesize($path),
            'mtime' => (int)filemtime($path),
        ];
    }
    // Timestamped names sort newest-first; mtime as tiebreaker.
    usort($items, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']) ?: ($b['mtime'] <=> $a['mtime']));
    return $items;
}

/** Create the backups folder and block all direct web access to it. */
function backup_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the backups folder. Check the project folder permissions.');
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    $index = $dir . '/index.php';
    if (!is_file($index)) {
        @file_put_contents($index, "<?php // Silence is golden - direct access is denied above.\n");
    }
}

/** Keep only the newest BACKUP_KEEP archives; returns how many were removed. */
function backup_prune(string $dir, string $pattern): int
{
    $removed = 0;
    foreach (array_slice(backup_list($dir, $pattern), BACKUP_KEEP) as $item) {
        if (@unlink($dir . '/' . $item['name'])) {
            $removed++;
        }
    }
    return $removed;
}

/** Append an audit-trail entry for backup activity. */
function backup_log(string $message): void
{
    error_log('[Backup] ' . $message);
}

/**
 * Build a full backup (database dump + uploads + RESTORE.txt) and store it in
 * the server-side backups folder. Returns ['name' => ..., 'size' => ...].
 */
function backup_run_create(string $dir, string $dbName, string $backupPassword): array
{
    global $db_host, $db_user, $db_pass;

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP support is unavailable. Enable the PHP zip extension and try again.');
    }
    if (!method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256')) {
        throw new RuntimeException('AES-256 ZIP encryption is unavailable. Update PHP ZIP support and try again.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('The server does not allow the database backup process to run. On shared hosting, export via phpMyAdmin instead.');
    }

    $mysqldump = backup_find_mysqldump();
    $isMariaDb = backup_mysqldump_is_mariadb($mysqldump);

    backup_ensure_dir($dir);

    $tempDir = sys_get_temp_dir() . '/bean_backup_' . bin2hex(random_bytes(16));
    if (!mkdir($tempDir, 0700)) {
        throw new RuntimeException('Could not prepare temporary backup storage.');
    }
    $cleanup = static function () use ($tempDir): void {
        foreach (glob($tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tempDir);
    };

    try {
        $sqlPath    = $tempDir . '/database.sql';
        $errPath    = $tempDir . '/dump.err';
        $configPath = $tempDir . '/client.cnf';

        // Keep credentials out of command-line arguments and the archive.
        $optionValue = static function (string $value): string {
            return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
        };
        $config = "[client]\nhost=" . $optionValue($db_host)
            . "\nuser=" . $optionValue($db_user)
            . "\npassword=" . $optionValue($db_pass) . "\n";
        if (file_put_contents($configPath, $config) === false) {
            throw new RuntimeException('Could not prepare the database backup configuration.');
        }

        // An argument array avoids shell quoting problems with spaces in paths.
        // No table/date filter: include the entire database, including report source data.
        $command = [
            $mysqldump, '--defaults-extra-file=' . $configPath,
            '--single-transaction', '--routines', '--triggers', '--events',
            '--hex-blob', '--default-character-set=utf8mb4', '--no-tablespaces',
        ];
        if (!$isMariaDb) {
            // MariaDB's mysqldump rejects this option; MySQL supports it.
            $command[] = '--set-gtid-purged=OFF';
        }
        $command[] = '--result-file=' . $sqlPath;
        $command[] = $dbName;

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
        // Verify the dump is complete, not just non-empty.
        $tail = '';
        $handle = fopen($sqlPath, 'rb');
        if ($handle !== false) {
            fseek($handle, max(0, filesize($sqlPath) - 8192));
            $tail = (string)stream_get_contents($handle);
            fclose($handle);
        }
        if (strpos($tail, '-- Dump completed') === false) {
            throw new RuntimeException('The database dump appears to be incomplete. No backup was saved.');
        }

        $zipPath = $tempDir . '/backup.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup ZIP file.');
        }
        if (!$zip->addFile($sqlPath, 'database.sql')
            || !$zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256, $backupPassword)
            || !$zip->addEmptyDir('uploads')) {
            $zip->close();
            throw new RuntimeException('Could not add or encrypt the database in the backup.');
        }

        $uploadsPath = dirname(__DIR__) . '/uploads';
        if (!is_dir($uploadsPath) || !is_readable($uploadsPath) || is_link($uploadsPath)) {
            $zip->close();
            throw new RuntimeException('The uploads folder is missing or unreadable. No incomplete backup was saved.');
        }
        $uploadsPath = realpath($uploadsPath);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            // Never follow links outside the uploads tree or silently omit unreadable files.
            if ($file->isLink() || !$file->isReadable()) {
                $zip->close();
                throw new RuntimeException('An uploaded file is unreadable or is a symbolic link. Check the uploads folder and retry.');
            }
            $entry = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploadsPath) + 1));
            $added = $file->isDir() ? $zip->addEmptyDir($entry) : $zip->addFile($file->getPathname(), $entry);
            if (!$added || (!$file->isDir() && !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $backupPassword))) {
                $zip->close();
                throw new RuntimeException('Could not include or encrypt all uploaded files in the backup.');
            }
        }

        if (!$zip->addFromString('RESTORE.txt', backup_instructions($dbName))
            || !$zip->setEncryptionName('RESTORE.txt', ZipArchive::EM_AES_256, $backupPassword)) {
            $zip->close();
            throw new RuntimeException('Could not include or encrypt the recovery instructions.');
        }
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish the ZIP file. Check available disk space and uploaded files.');
        }

        $name   = BACKUP_PREFIX . date('Y-m-d_His') . '.zip';
        $target = $dir . '/' . $name;
        if (!@rename($zipPath, $target)) {
            // rename() can fail across devices; fall back to copy+unlink.
            if (!@copy($zipPath, $target)) {
                throw new RuntimeException('Could not store the backup in the backups folder. Check folder permissions and disk space.');
            }
            @unlink($zipPath);
        }
        clearstatcache(true, $target);
        return ['name' => $name, 'size' => (int)filesize($target)];
    } finally {
        $cleanup();
    }
}

/** Re-encrypt an existing archive, replacing it only after the new archive verifies. */
function backup_change_password(string $dir, string $name, string $pattern, string $currentPassword, string $newPassword): void
{
    if (!preg_match($pattern, $name)) {
        throw new RuntimeException('Invalid backup file.');
    }

    $target = $dir . '/' . $name;
    if (!is_file($target) || is_link($target)) {
        throw new RuntimeException('That backup is missing or cannot be changed.');
    }

    $workDir = sys_get_temp_dir() . '/bean_rekey_' . bin2hex(random_bytes(16));
    $stagingDir = $dir . '/.rekey_' . bin2hex(random_bytes(16));
    if (!mkdir($workDir, 0700)) {
        throw new RuntimeException('Could not prepare secure temporary backup storage.');
    }
    if (!mkdir($stagingDir, 0700)) {
        @rmdir($workDir);
        throw new RuntimeException('Could not prepare safe archive replacement storage.');
    }

    $source = new ZipArchive();
    $replacement = new ZipArchive();
    $sourceOpen = false;
    $replacementOpen = false;
    $originalMoved = false;
    $originalPath = $stagingDir . '/original.zip';
    $replacementPath = $stagingDir . '/replacement.zip';
    $preserveOriginal = false;

    try {
        if ($source->open($target) !== true) {
            throw new RuntimeException('Could not open the selected backup archive.');
        }
        $sourceOpen = true;
        if ($currentPassword !== '' && !$source->setPassword($currentPassword)) {
            throw new RuntimeException('Could not prepare the current backup password.');
        }

        if ($replacement->open($replacementPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create a temporary replacement archive.');
        }
        $replacementOpen = true;

        $entryNames = [];
        $fileEntries = [];
        for ($index = 0; $index < $source->numFiles; $index++) {
            $nameAtIndex = $source->getNameIndex($index);
            $stat = $source->statIndex($index);
            if ($nameAtIndex === false || $stat === false) {
                throw new RuntimeException('Could not read the backup archive contents.');
            }

            $entryName = str_replace('\\', '/', $nameAtIndex);
            $segments = explode('/', rtrim($entryName, '/'));
            if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('/^[A-Za-z]:/', $entryName)
                || in_array('..', $segments, true) || isset($entryNames[$entryName])) {
                throw new RuntimeException('The archive contains an invalid or duplicate path.');
            }
            $entryNames[$entryName] = true;

            $isDirectory = str_ends_with($entryName, '/');
            if ($isDirectory) {
                if (!$replacement->addEmptyDir(rtrim($entryName, '/'))) {
                    throw new RuntimeException('Could not copy a folder from the backup archive.');
                }
                continue;
            }

            $input = $source->getStream($nameAtIndex);
            if ($input === false) {
                throw new RuntimeException('The current password is incorrect or the archive is damaged.');
            }

            $plainPath = $workDir . '/entry_' . $index . '.tmp';
            $output = fopen($plainPath, 'wb');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('Could not prepare temporary archive contents.');
            }

            $bytesWritten = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1048576);
                    if ($chunk === false || ($chunk === '' && !feof($input))) {
                        throw new RuntimeException('Could not read the backup archive contents.');
                    }
                    if ($chunk !== '') {
                        $written = 0;
                        $chunkLength = strlen($chunk);
                        while ($written < $chunkLength) {
                            $count = fwrite($output, substr($chunk, $written));
                            if ($count === false || $count === 0) {
                                throw new RuntimeException('Could not stage the backup archive contents.');
                            }
                            $written += $count;
                        }
                        $bytesWritten += $written;
                    }
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            if ($bytesWritten !== (int)$stat['size']
                || !$replacement->addFile($plainPath, $entryName)
                || !$replacement->setEncryptionName($entryName, ZipArchive::EM_AES_256, $newPassword)) {
                throw new RuntimeException('Could not copy and encrypt every file in the backup.');
            }
            $fileEntries[] = ['name' => $entryName, 'size' => (int)$stat['size']];
        }

        if ($fileEntries === []) {
            throw new RuntimeException('The selected archive contains no files.');
        }
        if (!$replacement->close()) {
            throw new RuntimeException('Could not finish the encrypted replacement archive.');
        }
        $replacementOpen = false;
        $source->close();
        $sourceOpen = false;

        $verification = new ZipArchive();
        if ($verification->open($replacementPath) !== true || !$verification->setPassword($newPassword)) {
            throw new RuntimeException('Could not verify the encrypted replacement archive.');
        }
        try {
            foreach ($fileEntries as $entry) {
                $index = $verification->locateName($entry['name']);
                $stat = $index === false ? false : $verification->statIndex($index);
                $stream = $verification->getStream($entry['name']);
                if ($stat === false || (int)($stat['encryption_method'] ?? -1) !== ZipArchive::EM_AES_256 || $stream === false) {
                    throw new RuntimeException('The replacement archive failed its encryption check.');
                }

                $bytesRead = 0;
                while (!feof($stream)) {
                    $chunk = fread($stream, 1048576);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) {
                        fclose($stream);
                        throw new RuntimeException('The replacement archive failed its content check.');
                    }
                    $bytesRead += strlen($chunk);
                }
                fclose($stream);
                if ($bytesRead !== $entry['size']) {
                    throw new RuntimeException('The replacement archive failed its content check.');
                }
            }
        } finally {
            $verification->close();
        }

        if (!rename($target, $originalPath)) {
            throw new RuntimeException('Could not safely stage the original archive for replacement.');
        }
        $originalMoved = true;
        if (!rename($replacementPath, $target)) {
            if (!rename($originalPath, $target)) {
                throw new RuntimeException('Replacement failed; the original archive is preserved in temporary storage. Contact the administrator.');
            }
            $originalMoved = false;
            throw new RuntimeException('Could not replace the archive. The original backup was restored.');
        }
        $originalMoved = false;
    } finally {
        if ($sourceOpen) {
            $source->close();
        }
        if ($replacementOpen) {
            $replacement->close();
        }
        if ($originalMoved && is_file($originalPath)) {
            if (!is_file($target) && @rename($originalPath, $target)) {
                $originalMoved = false;
            } else {
                $preserveOriginal = true;
                error_log('Could not restore original backup archive from temporary storage: ' . $originalPath);
            }
        }
        foreach (glob($workDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($workDir);
        foreach (glob($stagingDir . '/*') ?: [] as $path) {
            if ($preserveOriginal && $path === $originalPath) {
                continue;
            }
            @unlink($path);
        }
        if (!$preserveOriginal) {
            @rmdir($stagingDir);
        }
    }
}

/** Recovery instructions embedded in every archive as RESTORE.txt. */
function backup_instructions(string $dbName): string
{
    $createdAt = date(DATE_ATOM);
    return <<<TEXT
BEAN THERE CAFE - DATABASE AND UPLOADS BACKUP
Created: {$createdAt}
Database: {$dbName}

CONTENTS
- database.sql: every database table and record, including sales/transactions,
  product details, variants, ingredient details, recipes, suppliers, expenses,
  users, and settings. Sales reports are generated from these records; this
  archive is not a PDF or spreadsheet export of the report screen.
- uploads/: all uploaded files with their original folder names and paths.
  Only existing files can be backed up; previously missing images are not recovered.

RESTORE (test on a separate database and application copy first)
1. Extract the ZIP using a current ZIP utility that supports AES-256 encryption
   (such as 7-Zip) and enter the password provided when the backup was created.
   The application does not store the password. If it is lost, this archive
   cannot be recovered.
2. Keep a safety copy of the current database and uploads before restoring.
3. Stop staff/owner activity during recovery. Use a compatible MySQL version
   and the matching Bean There Cafe application code (not included here).
4. Create/select the destination database in phpMyAdmin, then import database.sql.
   WARNING: the SQL replaces backed-up tables and their existing records.
   For large backups, use the MySQL client instead of browser upload limits.
5. Restore uploads/ into the application root, retaining all subfolders and
   filenames. Use the matching backed-up uploads rather than unrelated files.
6. Check db_connect.php points to the destination database. Verify sign-in,
   sales totals, products, ingredients, and images before resuming operations.

IMPORTANT
- Files in this ZIP are encrypted with AES-256 and contain sensitive business
  and account data. Store the password separately from the archive and keep
  the archive in a secure location.
- ZIP encryption protects file contents, but does not hide the archive's
  filenames or directory listing.
- Existing backups created before password protection was enabled remain
  unencrypted; create new backups to get encrypted copies.
- Database credentials and application source code are not included.
- For matching database/image contents, download while nobody is editing data
  or uploading/deleting images. Database and filesystem snapshots are not atomic.
- Automatic in-app restoration is not provided by this download feature.
TEXT;
}


// ── Request handling (before any HTML output) ───────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // All POST actions require a valid CSRF token.
    $postedToken = (string)($_POST['backup_csrf'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_backup') {
        $backupPassword = (string)($_POST['backup_password'] ?? '');
        $backupPasswordConfirmation = (string)($_POST['backup_password_confirmation'] ?? '');
        if (strlen($backupPassword) < 12) {
            header('Location: backup.php?msg=' . urlencode('Use a backup password with at least 12 characters.') . '&type=warn');
            exit;
        }
        if (!hash_equals($backupPassword, $backupPasswordConfirmation)) {
            header('Location: backup.php?msg=' . urlencode('The backup password and confirmation do not match.') . '&type=warn');
            exit;
        }

        // Release the session lock so other tabs stay responsive during the dump.
        session_write_close();
        set_time_limit(0);
        try {
            $created = backup_run_create($backupDir, $db_name, $backupPassword);
            $pruned = backup_prune($backupDir, $backupPattern);
            $message = 'Backup created: ' . $created['name'] . ' (' . backup_format_size($created['size']) . ').';
            if ($pruned > 0) {
                $message .= ' ' . $pruned . ' older archive(s) removed by retention (keeping ' . BACKUP_KEEP . ').';
            }
            backup_log('Created ' . $created['name'] . ' (' . $created['size'] . ' bytes) by ' . ($_SESSION['username'] ?? 'owner'));
            header('Location: backup.php?msg=' . urlencode($message) . '&type=success');
        } catch (Throwable $e) {
            backup_log('Backup failed: ' . $e->getMessage());
            header('Location: backup.php?msg=' . urlencode('Backup failed. ' . ($e instanceof RuntimeException ? $e->getMessage() : 'An unexpected error occurred. Check the server error log.')) . '&type=warn');
        }
        exit;
    }

    if ($action === 'change_backup_password') {
        $name = (string)($_POST['file'] ?? '');
        $currentPassword = (string)($_POST['current_backup_password'] ?? '');
        $newPassword = (string)($_POST['new_backup_password'] ?? '');
        $newPasswordConfirmation = (string)($_POST['new_backup_password_confirmation'] ?? '');

        if (strlen($newPassword) < 12) {
            header('Location: backup.php?msg=' . urlencode('Use a new backup password with at least 12 characters.') . '&type=warn');
            exit;
        }
        if (!hash_equals($newPassword, $newPasswordConfirmation)) {
            header('Location: backup.php?msg=' . urlencode('The new backup password and confirmation do not match.') . '&type=warn');
            exit;
        }

        $ownerName = $_SESSION['username'] ?? 'owner';
        session_write_close();
        try {
            backup_change_password($backupDir, $name, $backupPattern, $currentPassword, $newPassword);
            backup_log('Changed encryption password for ' . $name . ' by ' . $ownerName);
            header('Location: backup.php?msg=' . urlencode('Backup password changed for ' . $name . '.') . '&type=success');
        } catch (Throwable $e) {
            backup_log('Backup password change failed for ' . $name . ': ' . $e->getMessage());
            header('Location: backup.php?msg=' . urlencode('Could not change the backup password. ' . ($e instanceof RuntimeException ? $e->getMessage() : 'Check the server error log.')) . '&type=warn');
        }
        exit;
    }

    if ($action === 'delete_backup') {
        $name = (string)($_POST['file'] ?? '');
        // Strict pattern check prevents any path traversal.
        if (!preg_match($backupPattern, $name)) {
            header('Location: backup.php?msg=' . urlencode('Invalid backup file.') . '&type=warn');
            exit;
        }
        $path = $backupDir . '/' . $name;
        if (!is_file($path)) {
            header('Location: backup.php?msg=' . urlencode('That backup no longer exists.') . '&type=warn');
            exit;
        }
        if (@unlink($path)) {
            backup_log('Deleted ' . $name . ' by ' . ($_SESSION['username'] ?? 'owner'));
            header('Location: backup.php?msg=' . urlencode('Backup deleted: ' . $name) . '&type=success');
        } else {
            header('Location: backup.php?msg=' . urlencode('Could not delete the backup file. Check folder permissions.') . '&type=warn');
        }
        exit;
    }

    header('Location: backup.php');
    exit;
}

// GET download — read-only, filename strictly validated against the pattern.
if (($_GET['action'] ?? '') === 'download') {
    $name = (string)($_GET['file'] ?? '');
    if (!preg_match($backupPattern, $name)) {
        http_response_code(400);
        exit('Invalid backup file.');
    }
    $path = $backupDir . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Backup file not found.');
    }
    backup_log('Downloaded ' . $name . ' by ' . ($_SESSION['username'] ?? 'owner'));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $download = fopen($path, 'rb');
    if ($download === false) {
        http_response_code(500);
        exit('Could not read the backup file.');
    }
    fpassthru($download);
    fclose($download);
    exit;
}

// ── Page data ───────────────────────────────────────────────────────────────

$msg     = $_GET['msg'] ?? '';
$msgType = $_GET['type'] ?? 'success';

$mysqldumpPath    = backup_find_mysqldump();
$toolReady        = function_exists('proc_open')
    && class_exists('ZipArchive')
    && method_exists('ZipArchive', 'setEncryptionName')
    && defined('ZipArchive::EM_AES_256');
$dirReady         = true;
$dirError         = '';
$backups          = [];
$totalBackupBytes = 0;
$lastBackup       = null;
try {
    backup_ensure_dir($backupDir);
    $backups = backup_list($backupDir, $backupPattern);
    foreach ($backups as $item) {
        $totalBackupBytes += $item['size'];
    }
    $lastBackup = $backups[0] ?? null;
} catch (RuntimeException $e) {
    $dirReady = false;
    $dirError = $e->getMessage();
}

$settings             = get_system_settings($conn);
$navLowStockThreshold = (float)$settings['low_stock_threshold'];
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE (ingredient_stock_reference IS NULL AND ingredient_stock <= 0) OR (ingredient_stock_reference > 0 AND (ingredient_stock / ingredient_stock_reference) * 100 <= ?)");
$stmt->bind_param('d', $navLowStockThreshold);
$stmt->execute();
$ingredientAlertCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$displayName = $_SESSION['username'] ?? 'Owner';
$initials    = strtoupper(substr($displayName, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Backup &amp; Recovery | SmartStock — Bean There Café</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
  <style>
    :root {
      --mocha: #4A2C2A; --mocha-deep: #2E1A18; --mocha-mid: #6B3D3A;
      --cream: #F5ECD7; --cream-light: #FBF6EE; --cream-dark: #E8D8BA;
      --charcoal: #2C2C2C; --charcoal-mid: #444444;
      --gold: #C9943A; --gold-light: #E8B860;
      --sage: #7A9E7E; --red-soft: #C0392B;
      --sidebar-w: 240px; --header-h: 64px;
      --font-display: 'Playfair Display', serif;
      --font-body: 'DM Sans', sans-serif;
      --font-mono: 'DM Mono', monospace;
      --shadow-sm: 0 2px 8px rgba(74,44,42,.10);
      --shadow-md: 0 6px 24px rgba(74,44,42,.15);
      --shadow-lg: 0 12px 40px rgba(74,44,42,.22);
      --radius: 12px; --radius-lg: 18px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font-body); background: var(--cream-light); color: var(--charcoal); min-height: 100vh; overflow-x: hidden; }
    a { text-decoration: none; }
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--cream); }
    ::-webkit-scrollbar-thumb { background: var(--mocha-mid); border-radius: 99px; }

    /* ── HEADER ── */
    #app-header { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; height: var(--header-h); background: var(--mocha-deep); display: flex; align-items: center; padding: 0 24px 0 0; box-shadow: 0 2px 20px rgba(0,0,0,.35); }
    .header-brand { width: var(--sidebar-w); flex-shrink: 0; display: flex; align-items: center; gap: 12px; padding: 0 20px; border-right: 1px solid rgba(255,255,255,.08); }
    .brand-logo { width: 42px; height: 42px; border-radius: 10px; background: var(--gold); color: var(--mocha-deep); display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 2px 10px rgba(201,148,58,.45); flex-shrink: 0; }
    .brand-text .name { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: var(--cream); }
    .brand-text .sub { font-size: 10px; color: var(--gold-light); letter-spacing: 1.5px; text-transform: uppercase; }
    .header-center { padding-left: 22px; display: flex; align-items: center; gap: 10px; }
    .header-right { margin-left: auto; display: flex; align-items: center; gap: 14px; }
    .header-clock { font-family: var(--font-mono); font-size: 13px; color: rgba(245,236,215,.55); }
    .header-user { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.10); border-radius: 99px; padding: 5px 14px 5px 5px; }
    .header-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--gold); color: var(--mocha-deep); font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; }
    .header-user-name { font-size: 13px; color: var(--cream); font-weight: 500; }
    .logout-link { display: flex; align-items: center; gap: 6px; background: rgba(192,57,43,.12); border: 1px solid rgba(192,57,43,.28); color: #e08a80; font-size: 12px; font-weight: 600; padding: 6px 14px; border-radius: 99px; transition: all .2s; }
    .logout-link:hover { background: rgba(192,57,43,.22); }

    /* ── SIDEBAR ── */
    #sidebar { position: fixed; top: var(--header-h); left: 0; bottom: 0; width: var(--sidebar-w); z-index: 900; background: var(--mocha-deep); display: flex; flex-direction: column; overflow-y: auto; }
    .sidebar-section-label { padding: 20px 20px 6px; font-size: 9.5px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: rgba(245,236,215,.3); }
    a.nav-item { text-decoration: none; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(245,236,215,.6); cursor: pointer; font-size: 13.5px; font-weight: 500; border-left: 3px solid transparent; transition: all .2s; user-select: none; }
    .nav-item i { width: 20px; text-align: center; font-size: 16px; }
    .nav-item:hover { background: rgba(255,255,255,.06); color: var(--cream); }
    .nav-item.active { background: rgba(201,148,58,.12); color: var(--gold-light); border-left-color: var(--gold); }
    .nav-item.active i { color: var(--gold); }
    .nav-badge { margin-left: auto; background: var(--red-soft); color: #fff; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 99px; }
    .sidebar-divider { border: 0; border-top: 1px solid rgba(255,255,255,.07); margin: 8px 16px; }
    .sidebar-footer { margin-top: auto; padding: 16px 20px; border-top: 1px solid rgba(255,255,255,.07); text-align: center; }
    .sidebar-footer p { font-size: 10px; color: rgba(245,236,215,.22); line-height: 1.7; }
    .menu-toggle { display: none; }
    @media (max-width: 760px) {
      .header-brand { width: auto; flex: 1; border: 0; }
      .header-center { display: none; }
      .brand-text .sub, .header-user, .header-clock { display: none; }
      .menu-toggle {
        display: inline-flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; margin-left: auto; border: 1px solid rgba(255,255,255,.16);
        border-radius: 9px; background: rgba(255,255,255,.07); color: var(--cream);
        font-size: 18px; cursor: pointer;
      }
      #sidebar { transform: translateX(-100%); transition: transform .22s ease; box-shadow: var(--shadow-lg); }
      body.sidebar-open #sidebar { transform: translateX(0); }
      body.sidebar-open::after { content: ""; position: fixed; inset: var(--header-h) 0 0; z-index: 899; background: rgba(20,10,8,.4); }
      body.sidebar-open { overflow-y: hidden; }
      #main { margin-left: 0 !important; }
      .page-strip { align-items: stretch; flex-direction: column; padding: 16px 18px; }
      .page-strip > form { width: 100%; }
      .backup-password-fields { flex-direction: column; margin: 0 0 8px; }
      .backup-password-fields input { width: 100%; }
      #create-backup-btn { justify-content: center; width: 100%; }
      .content-pad { padding: 18px; }
      .info-grid { grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr)); }
      .modal-actions { flex-direction: column; }
      .modal-actions .btn { justify-content: center; width: 100%; }
      .panel-head { padding: 15px 18px; }
      .banner { margin-right: 18px; margin-left: 18px; }
    }
    @media (max-width: 420px) {
      #app-header { padding-right: 14px; }
      .header-brand { gap: 8px; padding: 0 14px; }
      .page-strip h1 { font-size: 19px; }
      .content-pad { padding: 14px; }
      table.backups tr { margin: 10px; padding: 8px 11px; }
    }

    /* ── MAIN ── */
    #main { margin-left: var(--sidebar-w); margin-top: var(--header-h); min-height: calc(100vh - var(--header-h)); }
    .page-strip { background: var(--cream); border-bottom: 1px solid var(--cream-dark); padding: 15px 26px; display: flex; justify-content: space-between; align-items: center; gap: 15px; position: sticky; top: var(--header-h); z-index: 500; }
    .page-strip h1 { font-family: var(--font-display); font-size: 21px; font-weight: 700; color: var(--mocha-deep); }
    .page-strip .sub { font-size: 12px; color: var(--mocha-mid); margin-top: 1px; }

    /* ── CONTENT ── */
    .content-pad { padding: 22px 26px; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-bottom: 22px; }
    .info-card { background: var(--cream); border: 1px solid var(--cream-dark); border-radius: var(--radius); padding: 16px 18px; box-shadow: var(--shadow-sm); }
    .info-card .label { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--mocha-mid); margin-bottom: 6px; }
    .info-card .value { font-family: var(--font-mono); font-size: 15px; font-weight: 500; color: var(--mocha-deep); word-break: break-word; }
    .info-card .value.ok { color: var(--sage); }
    .info-card .value.warn { color: var(--red-soft); }

    .panel { background: var(--cream-light); border: 1px solid var(--cream-dark); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 22px; }
    .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 16px 22px; border-bottom: 1px solid var(--cream-dark); background: var(--cream); flex-wrap: wrap; }
    .panel-head h2 { font-family: var(--font-display); font-size: 17px; color: var(--mocha-deep); }
    .panel-head .hint { font-size: 11.5px; color: var(--mocha-mid); margin-top: 2px; }

    table.backups { width: 100%; border-collapse: collapse; }
    table.backups th { padding: 11px 22px; text-align: left; font-size: 10px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--mocha-mid); background: var(--cream); border-bottom: 1px solid var(--cream-dark); }
    table.backups td { padding: 12px 22px; border-bottom: 1px solid var(--cream-dark); font-size: 13px; vertical-align: middle; }
    table.backups tr:last-child td { border-bottom: 0; }
    table.backups .file-name { font-family: var(--font-mono); font-size: 12px; color: var(--mocha-deep); font-weight: 500; }
    table.backups .empty { text-align: center; color: var(--charcoal-mid); padding: 34px 22px; }
    .row-actions { display: flex; gap: 8px; }
    .backup-password-fields { display: flex; gap: 8px; margin-right: 10px; }
    .backup-password-fields label { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .backup-password-fields input { width: 180px; min-width: 0; border: 1px solid var(--cream-dark); border-radius: 8px; background: var(--cream-light); color: var(--charcoal); padding: 9px 10px; font: 12px var(--font-body); }
    @media (max-width: 960px) {
      .row-actions { flex-direction: column; align-items: stretch; }
      .row-actions .btn { justify-content: center; }
      table.backups, table.backups tbody { display: block; width: 100%; }
      table.backups thead { display: none; }
      table.backups tr { display: block; margin: 12px; padding: 8px 14px; border: 1px solid var(--cream-dark); border-radius: var(--radius); background: var(--cream-light); box-shadow: var(--shadow-sm); }
      table.backups td { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--cream-dark); overflow-wrap: anywhere; }
      table.backups td::before { content: attr(data-label); flex: 0 0 76px; font-size: 10px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; color: var(--mocha-mid); }
      table.backups td:last-child { border-bottom: 0; }
      table.backups td.empty { display: block; text-align: center; }
      table.backups td.empty::before { content: none; }
      .row-actions { flex: 1; min-width: 0; }
    }

    /* ── BUTTONS ── */
    .btn { display: inline-flex; align-items: center; gap: 7px; border: 0; border-radius: 9px; padding: 10px 16px; font: 600 13px var(--font-body); cursor: pointer; transition: all .2s; }
    .btn-primary { background: var(--mocha); color: var(--cream); }
    .btn-primary:hover { background: var(--mocha-mid); }
    .btn-outline { background: transparent; border: 1px solid var(--gold); color: var(--mocha); }
    .btn-outline:hover { background: var(--gold); color: var(--mocha-deep); }
    .btn-danger-soft { background: transparent; border: 1px solid rgba(192,57,43,.45); color: var(--red-soft); }
    .btn-danger-soft:hover { background: rgba(192,57,43,.10); }
    .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 8px; }
    .btn[disabled] { opacity: .55; cursor: not-allowed; }

    /* ── NOTICE / BANNER ── */
    .banner { margin: 16px 26px 0; padding: 12px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; }
    .banner.success { background: rgba(122,158,126,.14); color: var(--sage); }
    .banner.warn { background: rgba(192,57,43,.10); color: var(--red-soft); }

    /* ── GUIDE LIST ── */
    .guide ol { margin: 8px 0 0 18px; font-size: 13px; color: var(--charcoal-mid); line-height: 1.8; }
    .guide p { font-size: 13px; color: var(--charcoal-mid); line-height: 1.7; }
    .guide .warn-note { margin-top: 10px; padding: 10px 14px; border-left: 3px solid var(--red-soft); background: rgba(192,57,43,.07); border-radius: 0 8px 8px 0; font-size: 12.5px; color: var(--mocha-deep); }
    .guide-pad { padding: 18px 22px; }

    /* ── MODAL ── */
    .modal-overlay { position: fixed; inset: 0; z-index: 9999; background: rgba(20,10,8,.58); display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-overlay.show { display: flex; }
    .modal-box { background: var(--cream-light); border-radius: var(--radius-lg); padding: 28px 30px; max-width: 430px; width: 92%; box-shadow: var(--shadow-lg); }
    .modal-title { font-family: var(--font-display); font-size: 19px; color: var(--mocha-deep); margin-bottom: 5px; }
    .modal-body { font-size: 13.5px; color: var(--charcoal-mid); line-height: 1.6; margin-bottom: 18px; }
    .modal-body code { font-family: var(--font-mono); font-size: 12px; background: var(--cream); padding: 2px 6px; border-radius: 5px; word-break: break-all; }
    .rekey-field { margin-bottom: 14px; }
    .rekey-field label { display: block; margin-bottom: 5px; color: var(--charcoal-mid); font-size: 11px; font-weight: 700; }
    .rekey-field input { width: 100%; border: 1px solid var(--cream-dark); border-radius: 8px; background: var(--cream); color: var(--charcoal); padding: 10px 12px; font: 13px var(--font-body); }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

    /* ── TOAST ── */
    #toast-container { position: fixed; bottom: 22px; right: 22px; z-index: 99999; display: flex; flex-direction: column; gap: 7px; }
    .toast-msg { background: var(--charcoal); color: var(--cream); font-size: 13px; font-weight: 500; padding: 11px 16px; border-radius: 10px; box-shadow: var(--shadow-md); display: flex; align-items: center; gap: 8px; animation: toastIn .28s ease; }
    .toast-msg.success { border-left: 3px solid var(--sage); }
    .toast-msg.warn { border-left: 3px solid #e67e22; }
    @keyframes toastIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
  </style>
</head>

<body>

<header id="app-header">
  <div class="header-brand">
    <div class="brand-logo"><i class="fas fa-mug-hot"></i></div>
    <div class="brand-text"><div class="name">SmartStock</div><div class="sub">Bean There Café</div></div>
    <button type="button" class="menu-toggle" id="sidebar-toggle" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false"><i class="fas fa-bars" aria-hidden="true"></i></button>
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
  <a href="transactions.php" class="nav-item"><i class="fas fa-receipt"></i> Transactions</a>
  <a href="order_queue.php" class="nav-item"><i class="fas fa-list-check"></i> Order Queue</a>
  <a href="transaction_history.php" class="nav-item"><i class="fas fa-clock-rotate-left"></i> Transaction History</a>
  <a href="products.php" class="nav-item"><i class="fas fa-boxes-stacked"></i> Products</a>
  <a href="inventory.php" class="nav-item"><i class="fas fa-warehouse"></i> Inventory
    <?php if ($ingredientAlertCount > 0): ?>
      <span class="nav-badge"><?= $ingredientAlertCount ?></span>
    <?php endif; ?>
  </a>
  <a href="expenses.php" class="nav-item"><i class="fas fa-wallet"></i> Expenses</a>
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Report</a>
  <a href="users.php" class="nav-item"><i class="fas fa-users-gear"></i> User Management</a>
  <hr class="sidebar-divider"/>
  <div class="sidebar-section-label">Settings</div>
  <a href="settings.php" class="nav-item"><i class="fas fa-gear"></i> System Settings</a>
  <a href="backup.php" class="nav-item active"><i class="fas fa-database"></i> Backup &amp; Recovery</a>
  <div class="sidebar-footer">
    <p>SmartStock v1.0<br/>Bean There Café<br/>ISO/IEC 25010 Compliant</p>
  </div>
</nav>

<div id="main">
  <?php if ($msg && $msgType === 'warn'): ?>
    <div class="banner warn"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="page-strip">
    <div>
      <h1><i class="fas fa-database" style="color:var(--gold);font-size:22px;margin-right:10px;"></i>Backup &amp; Recovery</h1>
      <div class="sub">Create full database + image backups, download them, and keep recovery copies on the server</div>
    </div>
    <form method="POST" action="backup.php" id="create-backup-form" style="display:inline;">
      <input type="hidden" name="action" value="create_backup">
      <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="backup-password-fields">
        <label for="backup-password">Backup password (minimum 12 characters)</label>
        <input type="password" id="backup-password" name="backup_password" placeholder="Backup password (12+ chars)" minlength="12" autocomplete="new-password" required>
        <label for="backup-password-confirmation">Confirm backup password</label>
        <input type="password" id="backup-password-confirmation" name="backup_password_confirmation" placeholder="Confirm backup password" minlength="12" autocomplete="new-password" required>
      </div>
      <button type="submit" class="btn btn-primary" id="create-backup-btn"<?= $toolReady && $dirReady ? '' : ' disabled' ?>>
        <i class="fas fa-plus"></i> Create Backup Now
      </button>
    </form>
  </div>

  <div class="content-pad">
    <div class="info-grid">
      <div class="info-card">
        <div class="label">Database</div>
        <div class="value"><?= htmlspecialchars($db_name) ?></div>
      </div>
      <div class="info-card">
        <div class="label">Backup Tool</div>
        <?php if ($toolReady): ?>
          <div class="value ok"><i class="fas fa-circle-check"></i> Ready</div>
        <?php else: ?>
          <div class="value warn"><i class="fas fa-circle-xmark"></i> Unavailable</div>
        <?php endif; ?>
      </div>
      <div class="info-card">
        <div class="label">Stored Backups</div>
        <div class="value"><?= count($backups) ?> / <?= BACKUP_KEEP ?> kept</div>
      </div>
      <div class="info-card">
        <div class="label">Total Size on Server</div>
        <div class="value"><?= backup_format_size($totalBackupBytes) ?></div>
      </div>
      <div class="info-card">
        <div class="label">Last Backup</div>
        <?php if ($lastBackup): ?>
          <div class="value" style="font-size:12.5px;"><?= date('M j, Y g:i:s A', $lastBackup['mtime']) ?></div>
        <?php else: ?>
          <div class="value warn">None yet</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$toolReady): ?>
      <div class="banner warn" style="margin:0 0 22px;">
        <i class="fas fa-triangle-exclamation"></i>
        The server lacks a required backup feature (<code>proc_open</code>, ZIP support, or AES-256 ZIP encryption).
        Ask your host to enable them, or export the database with phpMyAdmin as a fallback.
      </div>
    <?php endif; ?>
    <?php if (!$dirReady): ?>
      <div class="banner warn" style="margin:0 0 22px;">
        <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($dirError) ?>
      </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-head">
        <div>
          <h2><i class="fas fa-box-archive" style="color:var(--gold);margin-right:8px;"></i>Stored Backups</h2>
          <div class="hint">Each backup contains the full database and all uploaded images, plus a RESTORE.txt guide. The newest <?= BACKUP_KEEP ?> are kept automatically.</div>
        </div>
      </div>
      <table class="backups">
        <thead>
          <tr>
            <th>File</th>
            <th>Created</th>
            <th>Size</th>
            <th style="width:320px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$backups): ?>
            <tr><td class="empty" colspan="4"><i class="fas fa-inbox" style="display:block;font-size:26px;color:var(--cream-dark);margin-bottom:10px;"></i>No backups stored yet. Click <strong>Create Backup Now</strong> to make the first one.</td></tr>
          <?php else: foreach ($backups as $item): ?>
            <tr>
              <td class="file-name" data-label="File"><?= htmlspecialchars($item['name']) ?></td>
              <td data-label="Created"><?= date('M j, Y g:i:s A', $item['mtime']) ?></td>
              <td data-label="Size"><?= backup_format_size($item['size']) ?></td>
              <td data-label="Actions">
                <div class="row-actions">
                  <a class="btn btn-outline btn-sm" href="backup.php?action=download&file=<?= urlencode($item['name']) ?>"><i class="fas fa-download"></i> Download</a>
                  <button type="button" class="btn btn-outline btn-sm" onclick="openChangePassword('<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')"><i class="fas fa-key"></i> Change Password</button>
                  <button type="button" class="btn btn-danger-soft btn-sm" onclick="confirmDelete('<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>')"><i class="fas fa-trash"></i> Delete</button>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="panel guide">
      <div class="panel-head">
        <h2><i class="fas fa-life-ring" style="color:var(--gold);margin-right:8px;"></i>How Recovery Works</h2>
      </div>
      <div class="guide-pad">
        <p>Every archive contains <strong>database.sql</strong> (all sales, products, ingredients, users, and settings) and <strong>uploads/</strong> (all images), with a step-by-step <strong>RESTORE.txt</strong> inside the ZIP.</p>
        <ol>
          <li>Enter and confirm a backup password of at least 12 characters, then click <strong>Create Backup Now</strong>. Files in the ZIP are encrypted with AES-256; the password is not stored by the system.</li>
          <li>Click <strong>Download</strong> to keep an off-server copy (recommended — a server failure loses everything stored only on the server).</li>
          <li>To recover: first extract the ZIP with a current AES-256-compatible utility (such as 7-Zip) and the same password, then import <code>database.sql</code> via phpMyAdmin and restore the <code>uploads/</code> folder — full steps are in RESTORE.txt.</li>
        </ol>
        <div class="warn-note">
          <strong>Important:</strong> each new backup's file contents are encrypted with the password you enter. The system does not save that password; if lost, the backup cannot be opened. ZIP filenames remain visible, and existing backups created before this change are still unencrypted. Store the password separately from the archive and keep downloaded copies securely.
          A backup is a snapshot: for perfectly matching database and image contents, create it while nobody is selling or editing data.
          The newest <?= BACKUP_KEEP ?> backups are kept on the server; older ones are removed automatically when a new backup is created.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Change backup password modal -->
<div class="modal-overlay" id="change-password-modal">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-key" style="color:var(--gold);margin-right:8px;"></i>Change Backup Password</div>
    <div class="modal-body">
      Changing the password re-encrypts this archive. Enter its current password; leave it blank only for an older unencrypted backup.
    </div>
    <form method="POST" action="backup.php" id="change-password-form">
      <input type="hidden" name="action" value="change_backup_password">
      <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="file" id="change-password-file" value="">
      <div class="rekey-field">
        <label for="current-backup-password">Current password</label>
        <input type="password" id="current-backup-password" name="current_backup_password" autocomplete="current-password">
      </div>
      <div class="rekey-field">
        <label for="new-backup-password">New password (minimum 12 characters)</label>
        <input type="password" id="new-backup-password" name="new_backup_password" minlength="12" autocomplete="new-password" required>
      </div>
      <div class="rekey-field">
        <label for="new-backup-password-confirmation">Confirm new password</label>
        <input type="password" id="new-backup-password-confirmation" name="new_backup_password_confirmation" minlength="12" autocomplete="new-password" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeChangePasswordModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="change-password-submit"><i class="fas fa-key"></i> Change Password</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal-box">
    <div class="modal-title"><i class="fas fa-triangle-exclamation" style="color:var(--red-soft);margin-right:8px;"></i>Delete Backup?</div>
    <div class="modal-body">
      This permanently removes <code id="delete-file-name"></code> from the server.
      If it is your only copy, that data cannot be recovered. Download it first if you are unsure.
    </div>
    <form method="POST" action="backup.php" id="delete-form">
      <input type="hidden" name="action" value="delete_backup">
      <input type="hidden" name="backup_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="file" id="delete-file-input" value="">
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:var(--red-soft);"><i class="fas fa-trash"></i> Delete Backup</button>
      </div>
    </form>
  </div>
</div>

<div id="toast-container" aria-live="polite" aria-atomic="true">
  <?php if ($msg && $msgType !== 'warn'): ?>
    <div class="toast-msg success"><i class="fas fa-circle-check" aria-hidden="true"></i><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
</div>

<script>
document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-PH');
setInterval(() => document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-PH'), 1000);

const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebar = document.getElementById('sidebar');
function setSidebarOpen(open) {
  document.body.classList.toggle('sidebar-open', open);
  sidebarToggle.setAttribute('aria-expanded', String(open));
  sidebarToggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
  sidebarToggle.innerHTML = `<i class="fas ${open ? 'fa-xmark' : 'fa-bars'}" aria-hidden="true"></i>`;
}
sidebarToggle.addEventListener('click', () => setSidebarOpen(!document.body.classList.contains('sidebar-open')));
document.addEventListener('click', event => {
  if (document.body.classList.contains('sidebar-open') && !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) setSidebarOpen(false);
});
document.addEventListener('keydown', event => { if (event.key === 'Escape') setSidebarOpen(false); });
window.addEventListener('resize', () => { if (window.innerWidth > 760) setSidebarOpen(false); });

document.querySelectorAll('#toast-container .toast-msg').forEach(toast => {
  window.setTimeout(() => {
    toast.style.transition = 'opacity .25s ease, transform .25s ease';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    window.setTimeout(() => toast.remove(), 250);
  }, 4000);
});

// Busy state while a backup is being created (can take a while).
document.getElementById('create-backup-form').addEventListener('submit', function () {
  const btn = document.getElementById('create-backup-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating backup… do not close this page';
});

// Delete confirmation modal.
function confirmDelete(name) {
  document.getElementById('delete-file-name').textContent = name;
  document.getElementById('delete-file-input').value = name;
  document.getElementById('delete-modal').classList.add('show');
}
function closeDeleteModal() {
  document.getElementById('delete-modal').classList.remove('show');
}
function openChangePassword(name) {
  document.getElementById('change-password-file').value = name;
  document.getElementById('current-backup-password').value = '';
  document.getElementById('new-backup-password').value = '';
  document.getElementById('new-backup-password-confirmation').value = '';
  document.getElementById('change-password-modal').classList.add('show');
}
function closeChangePasswordModal() {
  document.getElementById('change-password-modal').classList.remove('show');
}
document.getElementById('change-password-form').addEventListener('submit', function () {
  const btn = document.getElementById('change-password-submit');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing password…';
});
document.querySelectorAll('.modal-overlay').forEach(m =>
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); })
);
</script>
</body>
</html>
