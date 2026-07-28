<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT url FROM forum_attachments WHERE id = ?');
$stmt->execute([$id]);
$url = $stmt->fetchColumn();

if (!$url) { http_response_code(404); die('Вложение не найдено'); }

db()->prepare('UPDATE forum_attachments SET views_count = views_count + 1 WHERE id = ?')->execute([$id]);

redirect($url);
