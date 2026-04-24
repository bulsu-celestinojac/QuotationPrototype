<?php
require 'db.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT DISTINCT brand FROM items WHERE brand IS NOT NULL AND brand != '' ORDER BY brand ASC");
    $stmt->execute();
    $brands = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode($brands);
} catch (Exception $e) {
    echo json_encode([]);
}
?>