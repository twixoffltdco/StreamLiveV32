<?php
/**
 * Редирект коротких ссылок /g/CODE → цель
 * Также: g.php?c=CODE
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/shortlink.php';

$code = trim((string)($_GET['c'] ?? ''));
if ($code === '' && !empty($_SERVER['REQUEST_URI'])) {
  if (preg_match('#/g/([a-zA-Z0-9]+)#', (string)$_SERVER['REQUEST_URI'], $m)) {
    $code = $m[1];
  }
}
$url = shortlink_resolve($code);
if (!$url) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Ссылка не найдена\n";
  exit;
}
header('Location: ' . $url, true, 302);
exit;
