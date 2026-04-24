<?php
$host = 'localhost';
$db   = 'sales';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     // Log the actual error internally to your server logs
     error_log("Database connection failed: " . $e->getMessage());
     
     // Output a generic message to the browser to protect sensitive data
     die("A database connection error occurred. Please contact the administrator.");
}
?>