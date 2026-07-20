<?php
require_once __DIR__ . '/includes/resources.php';
$r = resource_find((string)($_GET['slug'] ?? ''), false);
if (!$r) { http_response_code(404); die('Ресурс не найден'); }
$url = $r['download_url'] ?: $r['external_url'];
header('Location: ' . $url, true, 302);
