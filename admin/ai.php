<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_client.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $provider = $_POST['provider'] ?? 'openai';
  $presets = t2000_provider_presets();
  $apiKey = trim($_POST['api_key'] ?? '');
  $baseUrl = trim($_POST['base_url'] ?? '');
  $model = trim($_POST['model'] ?? '');

  // автоподстановка base/model из пресета если пусто
  if (isset($presets[$provider])) {
    if ($baseUrl === '' && $presets[$provider]['base'] !== '') $baseUrl = $presets[$provider]['base'];
    if ($model === '' && $presets[$provider]['model'] !== '') $model = $presets[$provider]['model'];
  }

  // provider для клиента: anthropic отдельно, остальное openai-compatible
  $storeProvider = ($provider === 'anthropic') ? 'anthropic' : 'openai';
  set_setting('ai_provider', $storeProvider);
  set_setting('ai_provider_preset', $provider);
  if ($apiKey !== '') set_setting('ai_api_key', $apiKey);
  set_setting('ai_base_url', $baseUrl);
  set_setting('ai_model', $model);
  set_setting('ai_max_tokens', (string)max(256, (int)($_POST['max_tokens'] ?? 4096)));
  set_setting('ai_system_prompt', trim($_POST['system_prompt'] ?? ''));
  set_setting('ai_rate_limit', (string)max(1, (int)($_POST['rate_limit'] ?? 2)));
  set_setting('ai_rate_window_hours', (string)max(1, (int)($_POST['rate_window'] ?? 6)));

  flash_set('success', 'Настройки ИИ T2000 сохранены');
  redirect('/admin/ai.php');
}

$preset = get_setting('ai_provider_preset', 'openai') ?: 'openai';
$provider = get_setting('ai_provider', 'openai');
$apiKey = get_setting('ai_api_key', '');
$baseUrl = get_setting('ai_base_url', '');
$model = get_setting('ai_model', '');
$maxTokens = get_setting('ai_max_tokens', '4096');
$systemPrompt = get_setting('ai_system_prompt', '');
$rateLimit = get_setting('ai_rate_limit', '2');
$rateWindow = get_setting('ai_rate_window_hours', '6');
$presets = t2000_provider_presets();

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Настройки ИИ T2000</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:720px">
  Ключ хранится в <code>settings</code>. OpenAI-compatible (OpenRouter, Grok, DeepSeek, ProxyAPI для РФ, Ollama) и Anthropic.
  Студия: <a href="/ai.php">/ai.php</a> · <a href="/admin/ai_test.php">Тест запроса</a>
</p>
<div class="form-card form-wide">
  <form method="POST">
    <?= csrf_field() ?>
    <label>Пресет провайдера</label>
    <select name="provider" id="ai-preset">
      <?php foreach ($presets as $k => $p): ?>
        <option value="<?= e($k) ?>" <?= $preset === $k ? 'selected' : '' ?>
          data-base="<?= e($p['base']) ?>" data-model="<?= e($p['model']) ?>"><?= e($p['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>API-ключ (оставьте пустым, чтобы не менять)</label>
    <input type="password" name="api_key" value="" placeholder="<?= $apiKey ? '•••• сохранён' : 'sk-…' ?>">
    <label>Base URL</label>
    <input type="text" name="base_url" id="ai-base" value="<?= e($baseUrl) ?>" placeholder="https://api.openai.com/v1">
    <label>Модель</label>
    <input type="text" name="model" id="ai-model" value="<?= e($model) ?>" placeholder="gpt-4o-mini">
    <label>Max tokens</label>
    <input type="number" name="max_tokens" value="<?= e($maxTokens) ?>" min="256" max="128000">
    <label>Лимит запросов на окно</label>
    <input type="number" name="rate_limit" value="<?= e($rateLimit) ?>" min="1" max="1000">
    <label>Окно лимита (часов)</label>
    <input type="number" name="rate_window" value="<?= e($rateWindow) ?>" min="1" max="168">
    <label>System prompt (пусто = по умолчанию)</label>
    <textarea name="system_prompt" rows="4" style="width:100%"><?= e($systemPrompt) ?></textarea>
    <button class="btn btn-primary" style="margin-top:16px" type="submit">Сохранить</button>
  </form>
</div>
<script>
document.getElementById('ai-preset').addEventListener('change', function () {
  var o = this.selectedOptions[0];
  if (!o) return;
  var b = o.getAttribute('data-base') || '';
  var m = o.getAttribute('data-model') || '';
  if (b) document.getElementById('ai-base').value = b;
  if (m) document.getElementById('ai-model').value = m;
});
</script>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
