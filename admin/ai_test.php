<?php
/**
 * Быстрый тест ИИ из админки: /admin/ai_test.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_admin();

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $result = t2000_call([
    ['role' => 'user', 'content' => 'Ответь одним словом: работает'],
  ]);
}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Тест ИИ T2000</h2>
<p style="color:var(--text-dim);font-size:13px">
  Base: <code><?= e(get_setting('ai_base_url','')) ?></code> ·
  Model: <code><?= e(get_setting('ai_model','')) ?></code> ·
  Provider: <code><?= e(get_setting('ai_provider','')) ?></code>
  / preset <code><?= e(get_setting('ai_provider_preset','')) ?></code>
</p>
<form method="post"><?= csrf_field() ?>
  <button class="btn btn-primary" type="submit">Проверить запрос</button>
  <a class="btn btn-outline" href="/admin/ai.php">← Настройки</a>
</form>
<?php if ($result): ?>
  <div class="form-card" style="margin-top:16px">
    <?php if (!empty($result['ok'])): ?>
      <p style="color:var(--success,#3ddc84)"><b>OK</b></p>
      <pre style="white-space:pre-wrap"><?= e($result['text'] ?? '') ?></pre>
    <?php else: ?>
      <p style="color:#f66"><b>Ошибка</b></p>
      <pre style="white-space:pre-wrap"><?= e($result['error'] ?? 'unknown') ?></pre>
      <?php if (!empty($result['url'])): ?><p class="muted">URL: <code><?= e($result['url']) ?></code></p><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
