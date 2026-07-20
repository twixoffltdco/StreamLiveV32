<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  set_setting('ai_provider', in_array($_POST['provider'] ?? '', ['openai','anthropic'], true) ? $_POST['provider'] : 'openai');
  set_setting('ai_api_key', trim($_POST['api_key'] ?? ''));
  set_setting('ai_base_url', trim($_POST['base_url'] ?? ''));
  set_setting('ai_model', trim($_POST['model'] ?? ''));
  flash_set('success', 'Настройки ИИ T2000 сохранены');
  redirect('/admin/ai.php');
}
$provider = get_setting('ai_provider', 'openai');
$apiKey = get_setting('ai_api_key', '');
$baseUrl = get_setting('ai_base_url', '');
$model = get_setting('ai_model', '');
?>
<h2>Настройки ИИ T2000</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:640px">
  Ключ и модель хранятся в таблице <code>settings</code> в MySQL — не в файлах. Поддерживается любой
  OpenAI-совместимый API (chat/completions — OpenAI, Groq, DeepSeek, локальные модели и т.д.) и Anthropic
  напрямую (v1/messages). Пользователям на сайте доступно 2 запроса, дальше 6 часов кулдаун на аккаунт.
</p>
<div class="form-card form-wide">
  <form method="POST">
    <?= csrf_field() ?>
    <label>Провайдер</label>
    <select name="provider">
      <option value="openai" <?= $provider === 'openai' ? 'selected' : '' ?>>OpenAI-совместимый (chat/completions)</option>
      <option value="anthropic" <?= $provider === 'anthropic' ? 'selected' : '' ?>>Anthropic (v1/messages)</option>
    </select>
    <label>API-ключ</label>
    <input type="text" name="api_key" value="<?= e($apiKey) ?>" placeholder="sk-...">
    <label>Base URL (не обязательно — по умолчанию официальный эндпоинт провайдера)</label>
    <input type="text" name="base_url" value="<?= e($baseUrl) ?>" placeholder="https://api.openai.com/v1">
    <label>Модель</label>
    <input type="text" name="model" value="<?= e($model) ?>" placeholder="gpt-4o-mini / claude-sonnet-4-5-20250929 / и т.д.">
    <button class="btn btn-primary" style="margin-top:16px" type="submit">Сохранить</button>
  </form>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
