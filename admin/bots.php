<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/social_bots.php';
require_admin();
bots_ensure_schema();

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? 'save';

  if ($action === 'save') {
    bots_save_settings($_POST);
    flash_set('success', 'Настройки ботов сохранены (токены зашифрованы)');
    redirect('/admin/bots.php');
  }

  if ($action === 'test_tg') {
    $testResult = ['tg' => bots_test_telegram()];
  }
  if ($action === 'test_vk') {
    $testResult = ['vk' => bots_test_vk()];
  }
  if ($action === 'test_send') {
    $msg = trim((string)($_POST['test_message'] ?? 'Тест StreamLive Bots ✅'));
    $ch = $_POST['test_channel'] ?? 'both';
    $id = bots_enqueue($ch, $msg, ['title' => 'Тест', 'source' => 'test']);
    $stats = bots_process_queue(5);
    flash_set('success', "В очереди #$id, отправлено: {$stats['done']}, ошибок: {$stats['error']}");
    redirect('/admin/bots.php');
  }
  if ($action === 'manual') {
    $body = trim((string)($_POST['body'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $link = trim((string)($_POST['link'] ?? ''));
    $ch = $_POST['channel'] ?? 'both';
    if ($body === '' && $title === '') {
      flash_set('error', 'Введите текст поста');
      redirect('/admin/bots.php');
    }
    $id = bots_enqueue($ch, $body !== '' ? $body : $title, [
      'title' => $title,
      'link' => $link,
      'source' => 'manual',
    ]);
    if (!empty($_POST['send_now'])) {
      bots_process_queue(5);
      flash_set('success', "Пост #$id поставлен и обработан");
    } else {
      flash_set('success', "Пост #$id в очереди (запустит cron/bots_worker.php)");
    }
    redirect('/admin/bots.php');
  }
  if ($action === 'process') {
    $stats = bots_process_queue(20);
    flash_set('success', "Очередь: OK {$stats['done']}, ошибок {$stats['error']}");
    redirect('/admin/bots.php');
  }
}

$s = bots_settings();
$tgTokenPlain = '';
$vkTokenPlain = '';
try {
  if (!empty($s['tg_token_enc'])) $tgTokenPlain = bots_decrypt_secret($s['tg_token_enc']);
  if (!empty($s['vk_token_enc'])) $vkTokenPlain = bots_decrypt_secret($s['vk_token_enc']);
} catch (Throwable $e) {}

$queue = [];
$logs = [];
try {
  $queue = db()->query("SELECT * FROM bots_queue ORDER BY id DESC LIMIT 30")->fetchAll() ?: [];
  $logs = db()->query("SELECT * FROM bots_log ORDER BY id DESC LIMIT 30")->fetchAll() ?: [];
} catch (Throwable $e) {}

$pageTitle = 'Боты VK / Telegram';
require_once __DIR__ . '/_layout_start.php';
?>
<p><a href="/admin/bots_diagnose.php">→ Диагностика (если бот не работает)</a></p>
<h2>🤖 Боты StreamLive — VK + Telegram</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:720px;margin-bottom:16px">
  Единая площадка автопостинга: ручные посты, очередь, хуки (форум/видео/RSS).
  Токены хранятся <b>только в зашифрованном виде</b> (AES-256-GCM), ключ в
  <code>storage/bots_secret.key</code> (закрыт от веба).
</p>

<?php if ($testResult): ?>
  <div class="alert alert-<?= !empty($testResult['tg']['ok']) || !empty($testResult['vk']['ok']) ? 'success' : 'error' ?>">
    <?php if (isset($testResult['tg'])): ?>
      TG: <?= !empty($testResult['tg']['ok']) ? 'OK @' . e($testResult['tg']['username'] ?? '') : e($testResult['tg']['error'] ?? 'fail') ?>
    <?php endif; ?>
    <?php if (isset($testResult['vk'])): ?>
      VK: <?= !empty($testResult['vk']['ok']) ? 'OK ' . e($testResult['vk']['name'] ?? '') : e($testResult['vk']['error'] ?? 'fail') ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="form-card form-wide" style="margin-bottom:20px">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <h3 style="margin-top:0">Telegram</h3>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <input type="checkbox" name="tg_enabled" value="1" <?= !empty($s['tg_enabled']) ? 'checked' : '' ?>>
      Включить Telegram
    </label>
    <label>Токен бота (от @BotFather)</label>
    <input type="password" name="tg_token" autocomplete="new-password" placeholder="<?= $tgTokenPlain !== '' ? e(bots_mask_secret($tgTokenPlain)) . ' — введите новый, чтобы заменить' : '123456:ABC-DEF...' ?>" style="width:100%;max-width:520px">
    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
      <input type="checkbox" name="tg_token_clear" value="1"> Удалить сохранённый токен TG
    </label>
    <label>Chat ID / @channel</label>
    <input type="text" name="tg_chat_id" value="<?= e($s['tg_chat_id'] ?? '') ?>" placeholder="@mychannel или -100..." style="width:100%;max-width:320px">
    <label>Parse mode</label>
    <select name="tg_parse_mode">
      <?php foreach (['HTML','Markdown','MarkdownV2'] as $pm): ?>
        <option value="<?= $pm ?>" <?= ($s['tg_parse_mode'] ?? 'HTML') === $pm ? 'selected' : '' ?>><?= $pm ?></option>
      <?php endforeach; ?>
    </select>

    <h3>ВКонтакте</h3>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <input type="checkbox" name="vk_enabled" value="1" <?= !empty($s['vk_enabled']) ? 'checked' : '' ?>>
      Включить VK
    </label>
    <label>Токен сообщества (права: wall)</label>
    <input type="password" name="vk_token" autocomplete="new-password" placeholder="<?= $vkTokenPlain !== '' ? e(bots_mask_secret($vkTokenPlain)) . ' — введите новый, чтобы заменить' : 'vk1.a....' ?>" style="width:100%;max-width:520px">
    <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
      <input type="checkbox" name="vk_token_clear" value="1"> Удалить сохранённый токен VK
    </label>
    <label>ID группы (без минуса)</label>
    <input type="text" name="vk_group_id" value="<?= e($s['vk_group_id'] ?? '') ?>" placeholder="123456789" style="width:100%;max-width:200px">

    <h3>Автопостинг</h3>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_forum" value="1" <?= !empty($s['auto_forum']) ? 'checked' : '' ?>> Новые темы форума</label>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_video" value="1" <?= !empty($s['auto_video']) ? 'checked' : '' ?>> Новые видео</label>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_rss" value="1" <?= !empty($s['auto_rss']) ? 'checked' : '' ?>> RSS (через хук при fetch)</label>
    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_ads" value="1" <?= !empty($s['auto_ads']) ? 'checked' : '' ?>> Рекламные рассылки (резерв)</label>

    <label style="margin-top:12px">Префикс ко всем постам</label>
    <textarea name="default_prefix" rows="2" style="width:100%;max-width:520px"><?= e($s['default_prefix'] ?? '') ?></textarea>
    <label>Суффикс (ссылка на сайт и т.п.)</label>
    <textarea name="default_suffix" rows="2" style="width:100%;max-width:520px"><?= e($s['default_suffix'] ?? '') ?></textarea>

    <button class="btn btn-primary" type="submit" style="margin-top:14px">Сохранить</button>
  </form>

  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="test_tg"><button class="btn btn-outline btn-sm" type="submit">Проверить TG</button></form>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="test_vk"><button class="btn btn-outline btn-sm" type="submit">Проверить VK</button></form>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="process"><button class="btn btn-outline btn-sm" type="submit">Обработать очередь</button></form>
  </div>
</div>

<div class="form-card form-wide" style="margin-bottom:20px">
  <h3 style="margin-top:0">Ручной пост / реклама</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="manual">
    <label>Куда</label>
    <select name="channel">
      <option value="both">TG + VK</option>
      <option value="telegram">Только Telegram</option>
      <option value="vk">Только VK</option>
    </select>
    <label>Заголовок</label>
    <input type="text" name="title" style="width:100%;max-width:520px">
    <label>Текст</label>
    <textarea name="body" rows="4" style="width:100%;max-width:520px" required></textarea>
    <label>Ссылка</label>
    <input type="url" name="link" style="width:100%;max-width:520px" placeholder="https://...">
    <label style="display:flex;align-items:center;gap:8px;margin:10px 0">
      <input type="checkbox" name="send_now" value="1" checked> Отправить сразу
    </label>
    <button class="btn btn-primary" type="submit">В очередь / отправить</button>
  </form>
</div>

<div class="form-card form-wide" style="margin-bottom:20px">
  <h3 style="margin-top:0">Быстрый тест</h3>
  <form method="POST" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_send">
    <div>
      <label>Сообщение</label>
      <input type="text" name="test_message" value="Тест StreamLive Bots ✅" style="width:280px">
    </div>
    <div>
      <label>Канал</label>
      <select name="test_channel"><option value="both">both</option><option value="telegram">tg</option><option value="vk">vk</option></select>
    </div>
    <button class="btn btn-outline btn-sm" type="submit">Тест-отправка</button>
  </form>
</div>

<h3>Очередь (последние 30)</h3>
<table class="table" style="width:100%;font-size:13px">
  <tr><th>ID</th><th>Куда</th><th>Статус</th><th>Источник</th><th>Создано</th><th>Ошибка</th></tr>
  <?php foreach ($queue as $q): ?>
    <tr>
      <td><?= (int)$q['id'] ?></td>
      <td><?= e($q['channel']) ?></td>
      <td><?= e($q['status']) ?></td>
      <td><?= e($q['source']) ?></td>
      <td><?= e($q['created_at']) ?></td>
      <td style="color:var(--danger);max-width:220px;overflow:hidden;text-overflow:ellipsis"><?= e($q['error_text'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$queue): ?><tr><td colspan="6" style="color:var(--text-dim)">Пусто</td></tr><?php endif; ?>
</table>

<h3>Лог API</h3>
<table class="table" style="width:100%;font-size:12px">
  <tr><th>Время</th><th>Канал</th><th>OK</th><th>Сообщение</th></tr>
  <?php foreach ($logs as $l): ?>
    <tr>
      <td><?= e($l['created_at']) ?></td>
      <td><?= e($l['channel']) ?></td>
      <td><?= !empty($l['ok']) ? '✓' : '✗' ?></td>
      <td><?= e($l['message'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
</table>

<p style="color:var(--text-dim);font-size:12px;margin-top:20px">
  Cron (раз в 1–5 мин): <code>php /path/to/cron/bots_worker.php</code><br>
  Или URL по секретному ключу (см. README).
</p>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
