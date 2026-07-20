<?php
require_once __DIR__ . '/../includes/resources.php';
require_once __DIR__ . '/_layout_start.php';
$note = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $kind = $_POST['kind'] ?? 'resources';
  $payload = trim((string)($_POST['payload'] ?? ''));
  $created = 0;
  if ($kind === 'resources' && $payload !== '') {
    resources_ensure_table();
    foreach (preg_split('/\R+/', $payload) as $line) {
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) < 2 || !filter_var($parts[1], FILTER_VALIDATE_URL)) continue;
      $title = mb_substr($parts[0], 0, 180); $url = $parts[1]; $summary = $parts[2] ?? '';
      db()->prepare('INSERT IGNORE INTO resources (user_id, slug, title, summary, readme, external_url, download_url, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$__user['id'], resource_slug($title), $title, $summary, $summary, $url, $url, 'import']);
      $created++;
    }
  }
  $note = 'Импорт обработан. Создано/пропущено строк: ' . $created . '. Формат можно использовать для переноса ссылок из XenForo, PlayTube и других движков.';
}
?>
<h1>Импорт из XenForo / PlayTube / других движков</h1>
<?php if ($note): ?><div class="alert alert-success"><?= e($note) ?></div><?php endif; ?>
<div class="form-card"><p style="color:var(--text-dim)">Быстрый импорт ресурсов без загрузки файлов: одна строка = <code>Название | https://ссылка | краткое описание</code>. Это безопасная заготовка под миграцию: данные публикуются как внешние ресурсы, скачать можно без регистрации.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="kind" value="resources"><textarea name="payload" rows="14" style="width:100%;padding:10px" placeholder="Мой проект | https://gitverse.ru/user/repo | README и описание проекта"></textarea><button class="btn btn-primary" type="submit">Импортировать ресурсы</button></form></div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
