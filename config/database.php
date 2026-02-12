<?php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'u825803348_cvsu_saoms');
define('DB_PASS', 'CvSUsaoms@987');
define('DB_NAME', 'u825803348_cvsu_saoms');

// Create connection without killing the request on failure
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn && $conn->connect_error) {
    error_log('DB connect failed: ' . $conn->connect_error);
    $conn = null;
}

// Only run connection-dependent queries if connection is valid
if ($conn) {
    // Set timezone for database connection to Philippine Time
    @$conn->query("SET time_zone = '+08:00'");
    @$conn->query("SET NAMES utf8mb4");
}
?> 