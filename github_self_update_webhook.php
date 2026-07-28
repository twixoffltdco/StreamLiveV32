<?php
// Эндпоинт для GitHub Webhook (Settings → Webhooks в репозитории на GitHub).
// URL для GitHub: https://твой-домен/github_self_update_webhook.php
// Content type: application/json, Secret: тот же, что задан в /admin/auto_deploy.php.
//
// БЕЗОПАСНОСТЬ: без проверки подписи (X-Hub-Signature-256) этот эндпоинт был бы дырой —
// кто угодно мог бы дёрнуть URL и заставить сайт "передеплоиться" произвольным репозиторием
// (если бы ещё и репозиторий был настраиваем через запрос, чего тут нет — но даже лишний
// повторный деплой чужим триггером — это уже DoS сам по себе). Поэтому подпись проверяется
// ДО любых других действий, и без верного секрета запрос отклоняется.

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/self_deploy.php';
header('Content-Type: application/json; charset=utf-8');

$secret = get_setting('self_deploy_webhook_secret', '');
if (!$secret) {
  http_response_code(503);
  echo json_encode(['ok' => false, 'error' => 'Автодеплой не настроен (нет секрета) — задай его в /admin/auto_deploy.php']);
  exit;
}

$payload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!$signatureHeader || !hash_equals($expectedSignature, $signatureHeader)) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Неверная подпись — запрос не от GitHub или секрет не совпадает']);
  exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
  // GitHub шлёт ping сразу после создания вебхука, чтобы проверить, что эндпоинт живой —
  // отвечаем 200 без запуска деплоя.
  echo json_encode(['ok' => true, 'message' => 'pong']);
  exit;
}
if ($event !== 'push') {
  echo json_encode(['ok' => true, 'message' => 'Событие проигнорировано (не push)']);
  exit;
}

$data = json_decode($payload, true) ?: [];
$ref = $data['ref'] ?? ''; // например "refs/heads/master"
$targetBranch = get_setting('self_deploy_branch', 'master');

if ($ref !== "refs/heads/{$targetBranch}") {
  echo json_encode(['ok' => true, 'message' => "Пуш в другую ветку ({$ref}), деплоим только {$targetBranch} — пропущено"]);
  exit;
}

$commitSha = $data['after'] ?? null;
$commitMessage = $data['head_commit']['message'] ?? null;

$result = self_deploy_run($targetBranch, $commitSha, $commitMessage);
echo json_encode($result);
