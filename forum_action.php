<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/forum_engine.php';

forum_engine_ensure();

$wantJson = (
  (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
  || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
  || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
);

function forum_action_respond(array $data, bool $json): void {
  if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
  }
  $to = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '/forum.php';
  if (!empty($data['redirect'])) $to = $data['redirect'];
  if (empty($data['ok']) && function_exists('flash_set')) {
    flash_set('error', $data['error'] ?? 'Ошибка');
  } elseif (!empty($data['ok']) && function_exists('flash_set') && isset($data['flash'])) {
    flash_set('success', $data['flash']);
  }
  redirect($to);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  forum_action_respond(['ok' => false, 'error' => 'POST only'], $wantJson);
}

try {
  csrf_verify();
} catch (Throwable $e) {
  forum_action_respond(['ok' => false, 'error' => 'csrf'], $wantJson);
}

$user = current_user();
if (!$user) {
  forum_action_respond(['ok' => false, 'error' => 'login'], $wantJson);
}

$uid = (int)$user['id'];
$mod = forum_is_mod($user);
$action = (string)($_POST['action'] ?? '');

if ($action === 'like') {
  forum_action_respond(forum_post_like_toggle((int)($_POST['post_id'] ?? 0), $uid), $wantJson);
}
if ($action === 'watch') {
  $r = forum_thread_watch_toggle((int)($_POST['thread_id'] ?? 0), $uid);
  $r['redirect'] = '/forum_thread.php?id=' . (int)($_POST['thread_id'] ?? 0);
  forum_action_respond($r, $wantJson);
}
if ($action === 'report') {
  $r = forum_report_post((int)($_POST['post_id'] ?? 0), $uid, (string)($_POST['reason'] ?? 'spam'));
  if (!empty($r['ok'])) $r['flash'] = 'Жалоба отправлена';
  forum_action_respond($r, $wantJson);
}
if ($action === 'edit') {
  $r = forum_edit_post((int)($_POST['post_id'] ?? 0), $uid, (string)($_POST['message'] ?? ''), $mod);
  if (!empty($r['ok'])) {
    $r['redirect'] = '/forum_thread.php?id=' . (int)$r['thread_id'] . '#post-' . (int)$_POST['post_id'];
    $r['flash'] = 'Сохранено';
  }
  forum_action_respond($r, $wantJson);
}

forum_action_respond(['ok' => false, 'error' => 'unknown action'], $wantJson);
