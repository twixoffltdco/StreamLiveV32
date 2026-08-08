<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/forum_plugins.php';

$u = current_user();
if (!$u) { flash_set('error', 'Войдите, чтобы голосовать'); redirect('/auth/login.php'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/forum.php'); }
if (function_exists('csrf_verify')) csrf_verify();

$pollId = (int)($_POST['poll_id'] ?? 0);
$threadId = (int)($_POST['thread_id'] ?? 0);
$opts = $_POST['opt'] ?? [];
if (!is_array($opts)) $opts = [$opts];

$poll = null;
try {
  $st = db()->prepare('SELECT * FROM forum_polls WHERE id = ?');
  $st->execute([$pollId]);
  $poll = $st->fetch();
} catch (Throwable $e) {}

if (!$poll) { flash_set('error', 'Опрос не найден'); redirect('/forum.php'); }
$ok = forum_poll_vote($pollId, (int)$u['id'], $opts, !empty($poll['is_multi']));
flash_set($ok ? 'success' : 'error', $ok ? 'Голос учтён' : 'Не удалось проголосовать (возможно, опрос закрыт)');
redirect('/forum_thread.php?id=' . max($threadId, (int)$poll['thread_id']));
