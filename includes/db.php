<?php
if (!defined('DB_HOST')) {
  $configFile = __DIR__ . '/../config/config.php';
  if (!file_exists($configFile)) {
    header('Location: /install/index.php');
    exit;
  }
  require_once $configFile;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      // Некоторые хостинги игнорируют charset в DSN (старый libmysqlclient) —
      // принудительно фиксируем кодировку соединения после коннекта.
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
  }
  return $pdo;
}
