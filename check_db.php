<?php
try {
    $pdo = new PDO('mysql:host=81.90.181.58;port=3306', 'xuser', 'yZ3wR1bM4x');
    echo "Connected successfully!\n";
    
    $stmt = $pdo->query('SHOW DATABASES');
    echo "Available databases:\n";
    while ($row = $stmt->fetch()) {
        echo "- " . $row[0] . "\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}
