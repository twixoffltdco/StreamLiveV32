<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_once __DIR__ . '/../includes/mod_bots.php';
require_moderator();

$__user = current_user();
$uid = (int)$__user['id'];
mod_bots_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? 'save';
  if ($action === 'save') {
    $r = mod_bots_save($uid, $_POST);
    flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? ('Сохранено' . (!empty($r['username']) ? ' (@' . $r['username'] . ')' : '')) : ($r['error'] ?? 'Ошибка'));
    redirect('/moderator/my_bot.php');
  }
  if ($action === 'test') {
    $r = mod_bots_send($uid, '✅ Тест личного бота модератора StreamLive');
    flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Сообщение ушло в chat_id' : ($r['error'] ?? 'fail'));
    redirect('/moderator/my_bot.php');
  }
  if ($action === 'set_webhook') {
    $token = mod_bots_token($uid);
    if ($token === '') {
      flash_set('error', 'Сначала сохрани токен');
      redirect('/moderator/my_bot.php');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $url = $scheme . '://' . $host . '/bots_webhook.php?mod=' . $uid;
    $secret = bin2hex(random_bytes(12));
    $res = bots_http_json('https://api.telegram.org/bot' . rawurlencode($token) . '/setWebhook', [
      'url' => $url,
      'secret_token' => $secret,
      'allowed_updates' => json_encode(['message']),
      'drop_pending_updates' => '1',
    ]);
    // secret только у этого мода в сессии не храним в shared settings — опционально в mod_bots table
    try {
      db()->exec("ALTER TABLE mod_bots ADD COLUMN webhook_secret VARCHAR(64) NULL");
    } catch (Throwable $e) {}
    try {
      db()->prepare('UPDATE mod_bots SET webhook_secret=? WHERE user_id=?')->execute([$secret, $uid]);
    } catch (Throwable $e) {}
    flash_set(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? ('Webhook: ' . $url) : ($res['description'] ?? 'setWebhook fail'));
    redirect('/moderator/my_bot.php');
  }
}

$row = mod_bots_get($uid) ?: [];
$hasToken = !empty($row['tg_token_enc']);
$mask = '';
if ($hasToken) {
  try {
    $p = bots_decrypt_secret($row['tg_token_enc']);
    $mask = function_exists('bots_mask_secret') ? bots_mask_secret($p) : '••••••';
  } catch (Throwable $e) {
    $mask = '••••••';
  }
}

$pageTitle = 'Мой бот';
require_once __DIR__ . '/_layout_start.php';
?>
<div class="container" style="max-width:640px">
  <h1>🤖 Мой Telegram-бот</h1>
  <p style="color:var(--text-dim);font-size:13px">
    Только <b>один</b> бот на ваш аккаунт. Токен зашифрован: другие модераторы и админы
    <b>не видят</b> ваш ключ и не могут им пользоваться из панели.
  </p>

  <form method="POST" class="card" style="padding:16px;margin:16px 0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <input type="checkbox" name="tg_enabled" value="1" <?= !empty($row['tg_enabled']) ? 'checked' : '' ?>> Включить бота
    </label>
    <label>Токен (@BotFather)<?= $hasToken ? ' — сейчас: ' . e($mask) : '' ?></label>
    <input type="text" name="tg_token" autocomplete="off" spellcheck="false" placeholder="<?= $hasToken ? 'Оставьте пустым, чтобы не менять' : '123456:AA...' ?>" style="width:100%;padding:10px;margin:6px 0">
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="tg_token_clear" value="1"> Удалить токен</label>
    <label style="margin-top:10px">Куда постить новый контент (chat_id / @channel)</label>
    <input type="text" name="tg_chat_id" value="<?= e($row['tg_chat_id'] ?? '') ?>" style="width:100%;padding:10px;margin:6px 0" placeholder="@mychannel">
    <?php if (!empty($row['bot_username'])): ?>
      <p style="font-size:13px">Бот: <b>@<?= e($row['bot_username']) ?></b></p>
    <?php endif; ?>

    <h3 style="margin:16px 0 8px;font-size:15px">Автопосты от вашего бота</h3>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_resource" value="1" <?= !isset($row['auto_resource']) || !empty($row['auto_resource']) ? 'checked' : '' ?>> Новые ресурсы («скачивайте, наслаждайтесь»)</label>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_video" value="1" <?= !empty($row['auto_video']) ? 'checked' : '' ?>> Новые видео</label>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_forum" value="1" <?= !empty($row['auto_forum']) ? 'checked' : '' ?>> Новые темы форума</label>
    <p style="font-size:12px;color:var(--text-dim);margin:8px 0">Нейрохам в <b>группах</b>: добавь бота в чат, в @BotFather → /setprivacy → <b>Disable</b>, иначе Telegram не шлёт обычные сообщения боту.</p>
    <label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" name="neuroham_enabled" value="1" <?= !isset($row['neuroham_enabled']) || !empty($row['neuroham_enabled']) ? 'checked' : '' ?>> Нейрохам: ругать буйных мягко</label>

    <button class="btn btn-primary" type="submit" style="margin-top:14px">Сохранить</button>
  </form>

  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn btn-outline btn-sm" type="submit">Тест в канал</button></form>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="set_webhook"><button class="btn btn-outline btn-sm" type="submit">Включить ответы в личку</button></form>
  </div>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
