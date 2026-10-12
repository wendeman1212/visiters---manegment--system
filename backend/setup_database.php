<?php
// setup_database.php
echo "Setting up VMS database...\n";

$host = '127.0.0.1';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;port=3306", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS vms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database 'vms_db' created or already exists.\n";
} catch (PDOException $e) {
    echo "Database setup error: " . $e->getMessage() . "\n";
    exit(1);
}

// Generate application key
echo "Generating Laravel application key...\n";
passthru('php artisan key:generate');

echo "Setup script completed.\n";
