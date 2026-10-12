<?php
$isProduction = strtolower((string) (getenv('APP_ENV') ?: 'local')) === 'production';
$dbDefaults = [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'bean_there_cafe',
    'DB_USER' => 'root',
    'DB_PASSWORD' => '',
];
$dbEnvironment = [];

foreach ($dbDefaults as $key => $default) {
    $value = getenv($key);
    $dbEnvironment[$key] = $value === false ? $default : $value;
}

$missingProductionConfig = $isProduction
    ? array_keys(array_filter($dbEnvironment, static fn($value) => $value === ''))
    : [];

if ($missingProductionConfig !== []) {
    error_log('Missing required production database environment variables: ' . implode(', ', $missingProductionConfig));
    http_response_code(500);
    exit('Database configuration error. Contact the administrator.');
}

$db_host = $dbEnvironment['DB_HOST'];
$db_name = $dbEnvironment['DB_NAME'];
$db_user = $dbEnvironment['DB_USER'];
$db_pass = $dbEnvironment['DB_PASSWORD'];

// Create connection
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    die("❌ Connection failed: " . $e->getMessage());
}

?>
