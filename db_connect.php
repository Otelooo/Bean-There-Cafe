<?php
$db_host = "localhost";
$db_user = "root";       // Default user for Laragon
$db_pass = "";           // Leave blank unless you set a password
$db_name = "bean_there_cafe";   // Must match your database name in phpMyAdmin

// Create connection
try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    die("❌ Connection failed: " . $e->getMessage());
}

?>
