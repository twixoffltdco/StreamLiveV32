<?php
declare(strict_types=1);
$studio_title = 'Подписчики';
$studio_active = 'subscribers';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (is_file(dirname(__DIR__, 2) . '/includes/paid_access.php')) {
  require_once dirname(__DIR__, 2) . '/includes/paid_access.php';
  if (function_exists('paid_ensure_schema')) paid_ensure_schema();
}
$user = current_user();
if (!$user) { header('Location: /login.php'); exit; }
$uid = (int)$user['id'];

$channelId = (int)($_GET['channel_id'] ?? 0);
$channels = [];
try {
  $st = db()->prepare('SELECT id, title, slug, paid_content FROM channels WHERE owner_id = ? ORDER BY id DESC');
  $st->execute([$uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {}
if ($channelId <= 0 && $channels) $channelId = (int)$channels[0]['id'];

$fav = $paidActive = $paidEver = $total = 0;
$recent = [];
$ch = null;
foreach ($channels as $c) {
  if ((int)$c['id'] === $channelId) { $ch = $c; break; }
}

if ($channelId > 0) {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM favorites WHERE channel_id = ?');
    $st->execute([$channelId]);
    $fav = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare(
      "SELECT COUNT(DISTINCT a.user_id) FROM promo_activations a
       JOIN promo_codes p ON p.id = a.promo_id
       WHERE a.is_revoked = 0 AND (a.access_until IS NOT NULL AND a.access_until > NOW())
         AND (p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?)"
    );
    $st->execute([$channelId, $channelId]);
    $paidActive = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare(
      "SELECT COUNT(DISTINCT a.user_id) FROM promo_activations a
       JOIN promo_codes p ON p.id = a.promo_id
       WHERE p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?"
    );
    $st->execute([$channelId, $channelId]);
    $paidEver = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare(
      "SELECT COUNT(*) FROM (
         SELECT user_id FROM favorites WHERE channel_id = ?
         UNION
         SELECT a.user_id FROM promo_activations a JOIN promo_codes p ON p.id = a.promo_id
         WHERE p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?
       ) t"
    );
    $st->execute([$channelId, $channelId, $channelId]);
    $total = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $total = max($fav, $paidEver);
  }
  try {
    $st = db()->prepare(
      "SELECT * FROM (
         SELECT u.id, u.username, u.avatar, 'favorite' AS kind, f.created_at AS at
         FROM favorites f JOIN users u ON u.id = f.user_id WHERE f.channel_id = ?
         UNION ALL
         SELECT u.id, u.username, u.avatar, 'paid' AS kind, a.activated_at AS at
         FROM promo_activations a
         JOIN promo_codes p ON p.id = a.promo_id
         JOIN users u ON u.id = a.user_id
         WHERE p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?
       ) x ORDER BY at DESC LIMIT 50"
    );
    $st->execute([$channelId, $channelId, $channelId]);
    $raw = $st->fetchAll() ?: [];
    $seen = [];
    foreach ($raw as $r) {
      $i = (int)$r['id'];
      if (isset($seen[$i])) continue;
      $seen[$i] = true;
      $recent[] = $r;
    }
  } catch (Throwable $e) {
    try {
      $st = db()->prepare(
        "SELECT u.id, u.username, u.avatar, 'favorite' AS kind, NULL AS at
         FROM favorites f JOIN users u ON u.id = f.user_id WHERE f.channel_id = ? LIMIT 40"
      );
      $st->execute([$channelId]);
      $recent = $st->fetchAll() ?: [];
    } catch (Throwable $e2) {}
  }
}

require __DIR__ . '/_layout.php';
$isPaid = $ch && !empty($ch['paid_content']);
?>
<h1 class="st-h1">Подписчики</h1>
<p class="st-sub">Кто добавил канал в избранное и кто активировал платный доступ.</p>

<?php if (!$channels): ?>
  <div class="st-panel muted">Сначала создайте канал.</div>
<?php else: ?>
  <form method="get" class="st-panel" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label class="muted">Канал</label>
    <select name="channel_id" onchange="this.form.submit()">
      <?php foreach ($channels as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===$channelId?'selected':'' ?>><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?><?= !empty($c['paid_content'])?' · платный':'' ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <div class="st-cards">
    <?php if ($isPaid): ?>
      <div class="st-card"><div class="lbl">Платный (активен)</div><div class="val"><?= $paidActive ?></div></div>
      <div class="st-card"><div class="lbl">Платили когда-либо</div><div class="val"><?= $paidEver ?></div></div>
      <div class="st-card"><div class="lbl">В избранном</div><div class="val"><?= $fav ?></div></div>
    <?php else: ?>
      <div class="st-card"><div class="lbl">Всего подписчиков</div><div class="val"><?= $total ?></div></div>
      <div class="st-card"><div class="lbl">В избранном</div><div class="val"><?= $fav ?></div></div>
      <?php if ($paidEver): ?><div class="st-card"><div class="lbl">Раньше платили</div><div class="val"><?= $paidEver ?></div></div><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="st-panel">
    <h2>Последние</h2>
    <?php if (!$recent): ?>
      <p class="muted">Пока пусто.</p>
    <?php else: foreach ($recent as $s):
      $un = $s['username'] ?? 'user';
      $av = trim((string)($s['avatar'] ?? ''));
      $kind = ($s['kind'] ?? '') === 'paid' ? 'Платный доступ' : 'Избранное';
      $at = !empty($s['at']) ? date('d.m.Y H:i', strtotime($s['at'])) : '';
    ?>
      <a class="st-sub-row" href="/u/<?= rawurlencode($un) ?>">
        <?php if ($av): ?><img class="st-av" src="<?= htmlspecialchars($av, ENT_QUOTES, 'UTF-8') ?>" alt="">
        <?php else: ?><span class="st-av-ph"><?= htmlspecialchars(mb_strtoupper(mb_substr($un,0,1)), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        <span style="flex:1;min-width:0">
          <strong>@<?= htmlspecialchars($un, ENT_QUOTES, 'UTF-8') ?></strong><br>
          <span class="muted"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?><?= $at ? ' · '.$at : '' ?></span>
        </span>
      </a>
    <?php endforeach; endif; ?>
  </div>
  <p class="muted"><a class="btn btn-outline" href="/channel_manage.php?id=<?= (int)$channelId ?>">Полное управление каналом</a></p>
<?php endif; ?>
<?php require __DIR__ . '/_layout_end.php'; ?>
