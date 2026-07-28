<?php
require_once __DIR__ . '/includes/resources.php';
require_once __DIR__ . '/includes/bbcode.php';
require_once __DIR__ . '/includes/auth.php';
$r = resource_find((string)($_GET['slug'] ?? ''), true);
$u = current_user();
if (!$r || ($r['status'] !== 'published' && !resource_can_moderate($u))) { http_response_code(404); die('Ресурс не найден'); }
db()->prepare('UPDATE resources SET views = views + 1 WHERE id = ?')->execute([$r['id']]);
$pageTitle = $r['title'];
$__resourceText = strip_tags((string)($r['readme'] ?? ''));
$__resourceExcerpt = function_exists('mb_substr') ? mb_substr($__resourceText, 0, 160) : substr($__resourceText, 0, 160);
$seoDescription = $r['summary'] ?: $__resourceExcerpt;
$seoKeywords = $r['tags'] ?? '';
require_once __DIR__ . '/includes/header.php';
$downloadHref = '/resource_download.php?slug=' . urlencode($r['slug']);
?>
<div class="container" style="max-width:980px">
  <p><a href="/resources">← Все ресурсы</a></p><h1><?= e($r['title']) ?></h1>
  <p style="color:var(--text-dim)">Автор: <a href="/profile?username=<?= e($r['username']) ?>"><?= e($r['username']) ?></a> · просмотров: <?= (int)$r['views'] ?> · <?= e($r['created_at']) ?></p>
  <?php if ($r['status']==='hidden'): ?><div class="alert alert-error">Скрыто: <?= e($r['hidden_reason']) ?></div><?php endif; ?>
  <?php if (!empty($r['repo_full_name'])): ?><section class="repo-card"><h2 style="margin-top:0">📦 <?= e($r['repo_full_name']) ?></h2><div class="repo-meta"><span>★ <?= e((string)($r['repo_stars'] ?? 0)) ?></span><?php if ($r['repo_language']): ?><span><?= e($r['repo_language']) ?></span><?php endif; ?><span>Источник GitHub распознан автоматически</span></div></section><?php endif; ?>
  <p><button class="btn btn-primary" type="button" onclick="openResourceRisk()">Скачать без регистрации</button> <a class="btn btn-outline" href="<?= e($r['external_url']) ?>" target="_blank" rel="noopener">Открыть источник</a></p>
  <section class="form-card"><h2>README</h2><div class="forum-post-body"><?= bbcode_to_html($r['readme'] ?: $r['summary']) ?></div></section>
</div>
<div id="resourceRisk" class="resource-modal-backdrop" role="dialog" aria-modal="true"><div class="resource-modal"><h2>Перед скачиванием</h2><p>Файлы размещают пользователи. Вы скачиваете ресурс на свой риск: StreamLive не несёт ответственности за действия авторов и последствия использования. Мы периодически проверяем ресурсы на вирусы, но не можем гарантировать абсолютную безопасность.</p><p><a class="btn btn-primary" href="<?= e($downloadHref) ?>" rel="nofollow noopener">Понимаю, скачать</a> <button class="btn btn-outline" type="button" onclick="closeResourceRisk()">Отмена</button></p></div></div>
<script>function openResourceRisk(){document.getElementById('resourceRisk').classList.add('open')}function closeResourceRisk(){document.getElementById('resourceRisk').classList.remove('open')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
