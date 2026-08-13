<?php
require_once __DIR__ . '/includes/resources.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/content_moderation.php')) require_once __DIR__ . '/includes/content_moderation.php';
$u = require_login(); $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $title = trim(mb_substr($_POST['title'] ?? '', 0, 180));
  $summary = trim(mb_substr($_POST['summary'] ?? '', 0, 500));
  $readme = trim((string)($_POST['readme'] ?? ''));
  $external = trim((string)($_POST['external_url'] ?? ''));
  $download = trim((string)($_POST['download_url'] ?? ''));
  $tags = trim(mb_substr($_POST['tags'] ?? '', 0, 500));
  if ($title === '' || !filter_var($external, FILTER_VALIDATE_URL)) $error = 'Укажите название и корректную ссылку на ресурс.';
  elseif ($download !== '' && !filter_var($download, FILTER_VALIDATE_URL)) $error = 'Ссылка скачивания должна быть корректным URL.';
  else { $slugBase = resource_slug($title); $slug = $slugBase; $i = 2; resources_ensure_table(); while (resource_find($slug, true)) { $slug = $slugBase . '-' . $i++; } [$repoFull, $repoStars, $repoLang] = resource_fetch_github_meta($external); db()->prepare('INSERT INTO resources (user_id, slug, title, summary, readme, external_url, download_url, repo_full_name, repo_stars, repo_language, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$u['id'],$slug,$title,$summary,$readme,$external,$download ?: $external,$repoFull,$repoStars,$repoLang,$tags]); $newId = (int)db()->lastInsertId();
        try { db()->prepare("UPDATE resources SET mod_status='pending' WHERE id=?")->execute([$newId]); } catch (Throwable $e) {}
        if ($newId > 0 && function_exists('cmod_enqueue')) {
          try { cmod_enqueue('resource', $newId, (int)$u['id'], $title, mb_substr($summary, 0, 300)); } catch (Throwable $e) {}
        }
        redirect('/resource.php?slug=' . urlencode($slug)); }
}
$pageTitle = 'Новый ресурс'; require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:760px"><h1>Опубликовать ресурс</h1><?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-card"><?= csrf_field() ?><label>Название</label><input name="title" required style="width:100%;padding:10px"><label>Краткое описание для SEO</label><textarea name="summary" rows="3" style="width:100%;padding:10px"></textarea><label>README (BBCode/Markdown-текст)</label><textarea name="readme" rows="12" style="width:100%;padding:10px" placeholder="# Описание&#10;Установка, возможности, ссылки..."></textarea><label>Ссылка на ресурс/репозиторий</label><input type="url" name="external_url" required style="width:100%;padding:10px"><label>Ссылка скачивания (если пусто — используется ссылка ресурса)</label><input type="url" name="download_url" style="width:100%;padding:10px"><label>Теги</label><input name="tags" style="width:100%;padding:10px"><button class="btn btn-primary" type="submit">Опубликовать</button></form></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
