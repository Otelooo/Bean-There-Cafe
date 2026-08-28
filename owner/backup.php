<?php
session_start();
require_once __DIR__ . '/../db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cafe owner') {
    header('Location: ../signin.php');
    exit;
}

// Laragon bundles MySQL under a version-specific folder name, so locate the binary at
// runtime rather than hardcoding a version that will drift on the next Laragon update.
$matches = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe');
$mysqldumpPath = $matches[0] ?? null;
if ($mysqldumpPath === null) {
    http_response_code(500);
    die('Backup failed: mysqldump.exe was not found under C:\\laragon\\bin\\mysql.');
}

$timestamp = date('Y-m-d_His');
$filename = "bean_there_cafe_backup_{$timestamp}.sql";
$tempPath = sys_get_temp_dir() . '/' . uniqid('bean_backup_', true) . '.sql';
$errPath = $tempPath . '.err';

// escapeshellarg() here isn't primarily about untrusted input (these values come from
// db_connect.php, not the request) — it's needed because the project path itself
// ("BEAN THERE CAFE") contains a space, so unquoted paths would break the command.
$cmd = escapeshellarg($mysqldumpPath)
    . ' --host=' . escapeshellarg($db_host)
    . ' --user=' . escapeshellarg($db_user)
    . ($db_pass !== '' ? ' --password=' . escapeshellarg($db_pass) : '')
    . ' --single-transaction --routines --triggers '
    . escapeshellarg($db_name)
    . ' > ' . escapeshellarg($tempPath) . ' 2> ' . escapeshellarg($errPath);

exec($cmd, $unused, $returnCode);

if ($returnCode !== 0 || !is_file($tempPath) || filesize($tempPath) === 0) {
    $errMsg = is_file($errPath) ? trim(file_get_contents($errPath)) : 'Unknown error';
    @unlink($tempPath);
    @unlink($errPath);
    http_response_code(500);
    die('Backup failed: ' . htmlspecialchars($errMsg !== '' ? $errMsg : 'mysqldump produced no output.'));
}
@unlink($errPath);

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tempPath));
readfile($tempPath);
@unlink($tempPath);
exit;
