<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';

$u = current_user();
if (!$u) {
  if (function_exists('redirect')) redirect('/auth/login.php');
  header('Location: /auth/login.php');
  exit;
}
$uid = (int)$u['id'];
$pdo = db();

$channels = [];
foreach ([
  'SELECT id, title FROM channels WHERE owner_id = ? ORDER BY title',
  'SELECT id, title FROM channels WHERE user_id = ? ORDER BY title',
] as $sql) {
  try {
    $st = $pdo->prepare($sql);
    $st->execute([$uid]);
    $channels = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($channels) break;
  } catch (Throwable $e) {}
}

$channelId = (int)($_GET['channel_id'] ?? ($channels[0]['id'] ?? 0));
$weekOffset = (int)($_GET['w'] ?? 0);
$monday = strtotime('monday this week') + $weekOffset * 7 * 86400;
$days = [];
for ($i = 0; $i < 7; $i++) {
  $ts = $monday + $i * 86400;
  $days[] = [
    'label' => date('D d.m', $ts),
    'date' => date('Y-m-d', $ts),
    'dow' => (int)date('N', $ts) - 1,
  ];
}

$schedule = [];
$premieres = [];
if ($channelId > 0) {
  try {
    $s = $pdo->prepare('SELECT * FROM schedule WHERE channel_id = ?');
    $s->execute([$channelId]);
    $schedule = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
  try {
    $from = date('Y-m-d 00:00:00', $monday);
    $to = date('Y-m-d 23:59:59', $monday + 6 * 86400);
    $s = $pdo->prepare('SELECT id, title, slug, premiere_at FROM videos WHERE channel_id = ? AND is_premiere = 1 AND premiere_at BETWEEN ? AND ? ORDER BY premiere_at');
    $s->execute([$channelId, $from, $to]);
    $premieres = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
}

$studio_title = 'Календарь';
$studio_active = 'calendar';
require __DIR__ . '/_layout.php';
?>
<div class="st-main">
  <h1 class="st-h1">Календарь недели</h1>
  <p class="st-sub">Слоты эфира + премьеры. Создать премьеру: <a href="/platforma/studio/schedule.php?channel_id=<?= (int)$channelId ?>" style="color:var(--st-accent2)">Расписание и премьеры</a></p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
    <a href="?channel_id=<?= (int)$channelId ?>&w=<?= $weekOffset - 1 ?>" style="padding:6px 12px;border-radius:8px;border:1px solid var(--st-border);color:inherit">←</a>
    <span><?= date('d.m.Y', $monday) ?> — <?= date('d.m.Y', $monday + 6 * 86400) ?></span>
    <a href="?channel_id=<?= (int)$channelId ?>&w=<?= $weekOffset + 1 ?>" style="padding:6px 12px;border-radius:8px;border:1px solid var(--st-border);color:inherit">→</a>
    <?php if ($channels): ?>
    <form method="get" style="margin-left:auto">
      <input type="hidden" name="w" value="<?= (int)$weekOffset ?>">
      <select name="channel_id" onchange="this.form.submit()" style="padding:8px;border-radius:8px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border)">
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $channelId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$c['title'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$channels): ?>
    <p style="color:var(--st-muted)">Нет каналов.</p>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px">
    <?php foreach ($days as $d): ?>
      <div style="min-height:120px;padding:10px;border-radius:12px;background:var(--st-card);border:1px solid var(--st-border)">
        <div style="font-size:12px;color:var(--st-muted);margin-bottom:8px"><?= htmlspecialchars($d['label']) ?></div>
        <?php foreach ($schedule as $s):
          if ((int)($s['day_of_week'] ?? -1) !== $d['dow']) continue; ?>
          <div style="font-size:11px;padding:6px;margin-bottom:6px;border-radius:8px;background:rgba(34,211,238,.12);border:1px solid rgba(34,211,238,.25)">
            <?= htmlspecialchars(substr((string)($s['start_time'] ?? ''), 0, 5)) ?> · <?= htmlspecialchars((string)($s['program_title'] ?? 'Эфир'), ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endforeach; ?>
        <?php foreach ($premieres as $v):
          if (date('Y-m-d', strtotime((string)$v['premiere_at'])) !== $d['date']) continue; ?>
          <div style="font-size:11px;padding:6px;margin-bottom:6px;border-radius:8px;background:rgba(167,139,250,.15);border:1px solid rgba(167,139,250,.35)">
            🎬 <?= htmlspecialchars(date('H:i', strtotime((string)$v['premiere_at']))) ?> · <?= htmlspecialchars((string)$v['title'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<style>@media(max-width:900px){.st-main>div[style*="repeat(7"]{grid-template-columns:1fr 1fr!important}}</style>
<?php require __DIR__ . '/_layout_end.php'; ?>
