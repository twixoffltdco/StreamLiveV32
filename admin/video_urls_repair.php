<?php
require_once __DIR__ . '/_layout_start.php';
require_once __DIR__ . '/../includes/video_url_fix.php';

$report = [];
$do = (string)($_POST['do'] ?? '');

if ($do === 'rewrite') {
  $olds = video_url_old_hosts();
  $curHost = video_url_current_host();
  $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : ('https://' . $curHost);
  $n = 0;
  try {
    $rows = db()->query('SELECT id, embed_url, source_url, thumbnail_url FROM videos')->fetchAll() ?: [];
    $upd = db()->prepare('UPDATE videos SET embed_url = ?, source_url = ?, thumbnail_url = ? WHERE id = ?');
    foreach ($rows as $r) {
      $e = video_url_fix_for_play((string)($r['embed_url'] ?? ''));
      $s = video_url_fix_for_play((string)($r['source_url'] ?? ''));
      $t = video_url_fix_for_play((string)($r['thumbnail_url'] ?? ''));
      if ($e !== (string)($r['embed_url'] ?? '') || $s !== (string)($r['source_url'] ?? '') || $t !== (string)($r['thumbnail_url'] ?? '')) {
        $upd->execute([$e, $s, $t, (int)$r['id']]);
        $n++;
      }
    }
    $report[] = "Обновлено роликов: $n";
  } catch (Throwable $ex) {
    $report[] = $ex->getMessage();
  }
}

if ($do === 'https') {
  $n = 0;
  try {
    foreach (['embed_url', 'source_url', 'thumbnail_url'] as $col) {
      $st = db()->exec("UPDATE videos SET `$col` = REPLACE(`$col`, 'http://', 'https://') WHERE `$col` LIKE 'http://%'");
      $n += (int)$st;
    }
    $report[] = 'http→https затронуто строк (сумма колонок): ' . $n;
  } catch (Throwable $ex) {
    $report[] = $ex->getMessage();
  }
}

// sample broken
$samples = [];
try {
  $samples = db()->query(
    "SELECT id, slug, title, platform, LEFT(embed_url, 120) AS emb, LEFT(source_url, 120) AS src, status
     FROM videos ORDER BY id DESC LIMIT 15"
  )->fetchAll() ?: [];
} catch (Throwable $e) {}
?>
<h2>Ремонт URL видео после переезда</h2>
<p>SITE_URL: <code><?= htmlspecialchars(defined('SITE_URL') ? SITE_URL : '?', ENT_QUOTES, 'UTF-8') ?></code></p>
<?php foreach ($report as $line): ?>
  <div style="padding:10px;margin:8px 0;background:rgba(34,211,238,.12)"><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>
<form method="post" style="margin:10px 0" onsubmit="return confirm('Переписать старые домены в URL видео?');">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <button class="btn btn-primary" name="do" value="rewrite" type="submit">1. Заменить старые домены на текущий</button>
</form>
<form method="post" style="margin:10px 0">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <button class="btn" name="do" value="https" type="submit">2. Все http:// → https:// в URL видео</button>
</form>
<table class="admin-table" style="width:100%;font-size:12px;margin-top:16px">
  <tr><th>id</th><th>slug</th><th>platform</th><th>status</th><th>embed</th></tr>
  <?php foreach ($samples as $s): ?>
  <tr>
    <td><?= (int)$s['id'] ?></td>
    <td><a href="/video?slug=<?= htmlspecialchars(urlencode($s['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars((string)$s['slug'], ENT_QUOTES, 'UTF-8') ?></a></td>
    <td><?= htmlspecialchars((string)$s['platform'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars((string)$s['status'], ENT_QUOTES, 'UTF-8') ?></td>
    <td style="word-break:break-all"><?= htmlspecialchars((string)$s['emb'], ENT_QUOTES, 'UTF-8') ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p style="margin-top:12px;opacity:.75">После работы удали <code>admin/video_urls_repair.php</code>.</p>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
