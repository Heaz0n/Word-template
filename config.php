<?php
session_start();

$host = '127.0.0.1';
$dbname = 'SystemDocument';
$username = 'root';
$password = '';
$port = '3306';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $_SESSION['notification'] = ['type' => 'error', 'message' => 'Ошибка подключения к базе данных: ' . $e->getMessage()];
    header('Location: main-group.php');
    exit;
}