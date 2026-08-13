<?php
declare(strict_types=1);
$__sg = dirname(__DIR__, 2) . '/includes/studio_guard.php';
if (is_file($__sg)) { require_once $__sg; studio_require_platforma_access(); }
/**
 * Студия: расписание эфира + премьеры видео.
 * Работает с owner_id (V29) и user_id.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
if (is_file($root . '/includes/premiere_helpers.php')) require_once $root . '/includes/premiere_helpers.php';
if (function_exists('premiere_ensure_columns')) premiere_ensure_columns();

$u = current_user();
if (!$u) {
  if (function_exists('redirect')) redirect('/auth/login.php');
  header('Location: /auth/login.php');
  exit;
}
$uid = (int)$u['id'];
$pdo = db();

function studio_user_channels(PDO $pdo, int $uid): array {
  foreach ([
    'SELECT id, title, slug FROM channels WHERE owner_id = ? ORDER BY title',
    'SELECT id, title, slug FROM channels WHERE user_id = ? ORDER BY title',
    'SELECT id, title, slug FROM channels WHERE owner_id = ? OR user_id = ? ORDER BY title',
  ] as $sql) {
    try {
      $st = $pdo->prepare($sql);
      if (substr_count($sql, '?') === 2) $st->execute([$uid, $uid]);
      else $st->execute([$uid]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($rows) return $rows;
    } catch (Throwable $e) {}
  }
  return [];
}

function studio_owns_channel(PDO $pdo, int $channelId, int $uid): bool {
  foreach (['owner_id', 'user_id'] as $col) {
    try {
      $st = $pdo->prepare("SELECT id FROM channels WHERE id = ? AND `$col` = ?");
      $st->execute([$channelId, $uid]);
      if ($st->fetch()) return true;
    } catch (Throwable $e) {}
  }
  return false;
}

function studio_ensure_premiere_cols(PDO $pdo): void {
  foreach ([
    "ALTER TABLE videos ADD COLUMN premiere_at DATETIME NULL DEFAULT NULL",
    "ALTER TABLE videos ADD COLUMN is_premiere TINYINT(1) NOT NULL DEFAULT 0",
  ] as $q) {
    try { $pdo->exec($q); } catch (Throwable $e) {}
  }
}

studio_ensure_premiere_cols($pdo);

$channels = studio_user_channels($pdo, $uid);
$channelId = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? ($channels[0]['id'] ?? 0));
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $channelId > 0) {
  try {
    if (function_exists('csrf_verify')) csrf_verify();
  } catch (Throwable $e) {}

  if (!studio_owns_channel($pdo, $channelId, $uid)) {
    $flashErr = 'Нет доступа к каналу';
  } else {
    $action = (string)($_POST['action'] ?? '');
    try {
      if ($action === 'add_schedule') {
        $src = (int)($_POST['source_id'] ?? 0);
        $pdo->prepare(
          'INSERT INTO schedule (channel_id, day_of_week, start_time, end_time, source_id, program_title) VALUES (?,?,?,?,?,?)'
        )->execute([
          $channelId,
          (int)($_POST['day_of_week'] ?? 0),
          (string)($_POST['start_time'] ?? '18:00') . (strlen((string)$_POST['start_time']) <= 5 ? ':00' : ''),
          (string)($_POST['end_time'] ?? '20:00') . (strlen((string)$_POST['end_time']) <= 5 ? ':00' : ''),
          $src > 0 ? $src : null,
          trim(mb_substr((string)($_POST['program_title'] ?? ''), 0, 200)) ?: null,
        ]);
        $flashOk = 'Слот добавлен';
      } elseif ($action === 'delete_schedule') {
        $pdo->prepare('DELETE FROM schedule WHERE id = ? AND channel_id = ?')
          ->execute([(int)$_POST['schedule_id'], $channelId]);
        $flashOk = 'Слот удалён';
      } elseif ($action === 'set_premiere') {
        $vid = (int)($_POST['video_id'] ?? 0);
        $at = trim((string)($_POST['premiere_at'] ?? ''));
        $atEnd = trim((string)($_POST['premiere_end_at'] ?? ''));
        if (function_exists('premiere_apply')) {
          $r = premiere_apply($vid, $channelId, $at, $atEnd);
          if (!empty($r['ok'])) $flashOk = 'Премьера: ' . $r['start'] . ' – ' . $r['end'];
          else $flashErr = $r['error'] ?? 'Ошибка';
        } else {
          $flashErr = 'Нет premiere_helpers.php';
        }
      } elseif ($action === 'clear_premiere') {
        $vid = (int)($_POST['video_id'] ?? 0);
        $pdo->prepare("UPDATE videos SET is_premiere = 0, premiere_at = NULL, status = 'published' WHERE id = ? AND channel_id = ?")
          ->execute([$vid, $channelId]);
        $flashOk = 'Премьера снята';
      }
    } catch (Throwable $e) {
      $flashErr = 'Ошибка: ' . $e->getMessage();
    }
  }
}

$schedule = [];
$videos = [];
$sources = [];
if ($channelId > 0) {
  try {
    $s = $pdo->prepare('SELECT * FROM schedule WHERE channel_id = ? ORDER BY day_of_week, start_time');
    $s->execute([$channelId]);
    $schedule = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
  try {
    $s = $pdo->prepare('SELECT id, title, status, premiere_at, is_premiere, thumbnail_url, slug FROM videos WHERE channel_id = ? ORDER BY id DESC LIMIT 80');
    $s->execute([$channelId]);
    $videos = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    try {
      $s = $pdo->prepare('SELECT id, title, status, thumbnail_url, slug FROM videos WHERE channel_id = ? ORDER BY id DESC LIMIT 80');
      $s->execute([$channelId]);
      $videos = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($videos as &$vv) {
        $vv['premiere_at'] = null;
        $vv['is_premiere'] = 0;
      }
      unset($vv);
    } catch (Throwable $e2) {}
  }
  try {
    $s = $pdo->prepare('SELECT id, name AS title FROM sources WHERE created_by = ? OR created_by IS NULL ORDER BY name');
    $s->execute([$uid]);
    $sources = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
}

$days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
$studio_title = 'Расписание и премьеры';
$studio_active = 'schedule';
require __DIR__ . '/_layout.php';
?>
<div class="st-main">
  <h1 class="st-h1">Расписание и премьеры</h1>
  <p class="st-sub">Слоты эфира канала и назначение премьер на видео.</p>

  <?php if ($flashOk): ?><div style="padding:12px 14px;margin:0 0 14px;border-radius:10px;background:rgba(34,211,238,.12);border:1px solid rgba(34,211,238,.3)"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div style="padding:12px 14px;margin:0 0 14px;border-radius:10px;background:rgba(251,113,133,.12);border:1px solid rgba(251,113,133,.35)"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <?php if (!$channels): ?>
    <div style="padding:20px;border-radius:14px;background:var(--st-card);border:1px solid var(--st-border)">
      Нет каналов. Создай канал, затем вернись сюда.
      <div style="margin-top:10px"><a href="/platforma/studio/channel.php" style="color:var(--st-accent2)">Мои каналы →</a></div>
    </div>
  <?php else: ?>
    <form method="get" style="margin-bottom:18px">
      <label style="font-size:13px;color:var(--st-muted)">Канал</label>
      <select name="channel_id" onchange="this.form.submit()" style="display:block;margin-top:6px;padding:10px 12px;border-radius:10px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border);min-width:260px">
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $channelId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$c['title'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
      <section style="padding:18px;border-radius:14px;background:var(--st-card);border:1px solid var(--st-border)">
        <h2 style="margin:0 0 12px;font-size:16px">📅 Расписание эфира</h2>
        <form method="POST" style="display:grid;gap:8px;margin-bottom:16px">
          <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
          <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
          <input type="hidden" name="action" value="add_schedule">
          <select name="day_of_week" style="padding:8px;border-radius:8px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border)">
            <?php foreach ($days as $i => $d): ?><option value="<?= $i ?>"><?= $d ?></option><?php endforeach; ?>
          </select>
          <div style="display:flex;gap:8px">
            <input type="time" name="start_time" value="18:00" required style="flex:1;padding:8px;border-radius:8px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border)">
            <input type="time" name="end_time" value="20:00" required style="flex:1;padding:8px;border-radius:8px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border)">
          </div>
          <input type="text" name="program_title" placeholder="Название передачи" maxlength="200" style="padding:8px;border-radius:8px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border)">
          <?php if ($sources): ?>
          <select name="source_id" style="padding:8px;border-radius:8px;background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border)">
            <option value="0">Источник по умолчанию</option>
            <?php foreach ($sources as $src): ?>
              <option value="<?= (int)$src['id'] ?>"><?= htmlspecialchars((string)($src['title'] ?? '#' . $src['id']), ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <button type="submit" style="padding:10px;border:0;border-radius:10px;background:linear-gradient(135deg,#a78bfa,#22d3ee);color:#0b0b12;font-weight:700;cursor:pointer">Добавить слот</button>
        </form>
        <?php if (!$schedule): ?>
          <p style="color:var(--st-muted);font-size:13px">Слотов пока нет.</p>
        <?php else: ?>
          <table style="width:100%;font-size:13px;border-collapse:collapse">
            <?php foreach ($schedule as $s): ?>
              <tr style="border-top:1px solid var(--st-border)">
                <td style="padding:8px 4px"><?= $days[(int)($s['day_of_week'] ?? 0)] ?? '?' ?></td>
                <td><?= htmlspecialchars(substr((string)($s['start_time'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string)($s['end_time'] ?? ''), 0, 5)) ?></td>
                <td><?= htmlspecialchars((string)($s['program_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Удалить?')">
                    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                    <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
                    <input type="hidden" name="action" value="delete_schedule">
                    <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                    <button type="submit" style="background:transparent;border:0;color:var(--st-danger);cursor:pointer">✕</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </section>

      <section style="padding:18px;border-radius:14px;background:var(--st-card);border:1px solid var(--st-border)">
        <h2 style="margin:0 0 8px;font-size:16px">🎬 Премьеры видео</h2>
        <p style="color:var(--st-muted);font-size:13px;margin:0 0 12px">
          Выбери видео → дату и время → «Поставить премьеру».<br>
          В «Видео» до и во время премьеры; после окончания — только канал (+ повтор 00:00–05:00 МСК).
        </p>
        <?php if (!$videos): ?>
          <p style="color:var(--st-muted);font-size:13px">Нет видео. Сначала импортируй во вкладке «Импорт видео».</p>
          <a href="/platforma/studio/import.php" style="color:var(--st-accent2);font-size:13px">Импорт →</a>
        <?php else: ?>
          <div style="display:grid;gap:12px;max-height:520px;overflow:auto">
            <?php foreach ($videos as $v):
              $__canPrem = function_exists('premiere_can_schedule') ? premiere_can_schedule($v, $channelId) : ['ok'=>true];
              $hasPrem = !empty($v['is_premiere']) && !empty($v['premiere_at']);
              $localVal = '';
              if (!empty($v['premiere_at'])) {
                $tts = strtotime((string)$v['premiere_at']);
                if ($tts) $localVal = date('Y-m-d\TH:i', $tts);
              }
            ?>
              <div style="display:flex;gap:10px;align-items:flex-start;padding:10px;border-radius:10px;background:rgba(0,0,0,.2);border:1px solid var(--st-border)">
                <?php if (!empty($v['thumbnail_url'])): ?>
                  <img src="<?= htmlspecialchars((string)$v['thumbnail_url'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:72px;height:40px;object-fit:cover;border-radius:6px;flex-shrink:0">
                <?php endif; ?>
                <div style="flex:1;min-width:0">
                  <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars((string)($v['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                  <div style="font-size:11px;color:var(--st-muted);margin-top:2px">
                    <?php if ($hasPrem): ?>
                      Премьера: <?= htmlspecialchars((string)$v['premiere_at'], ENT_QUOTES, 'UTF-8') ?>
                      <?php if (strtotime((string)$v['premiere_at']) > time()): ?> · ожидает<?php else: ?> · уже прошла<?php endif; ?>
                    <?php else: ?>
                      Статус: <?= htmlspecialchars((string)($v['status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                  </div>
                  <form method="POST" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;align-items:center">
                    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
                    <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
                    <input type="hidden" name="video_id" value="<?= (int)$v['id'] ?>">
                    <input type="datetime-local" name="premiere_at" value="<?= htmlspecialchars($localVal, ENT_QUOTES, 'UTF-8') ?>" required style="font-size:12px;padding:5px 6px;border-radius:6px;border:1px solid var(--st-border);background:var(--st-elev);color:var(--st-text)" title="Начало">
                    <input type="datetime-local" name="premiere_end_at" style="font-size:12px;padding:5px 6px;border-radius:6px;border:1px solid var(--st-border);background:var(--st-elev);color:var(--st-text)" title="Окончание (пусто = +2 часа)">
                    <?php if (empty($__canPrem['ok'])): ?>
                    <span style="font-size:11px;color:#fb7185"><?= htmlspecialchars($__canPrem['reason'] ?? 'Премьера уже была', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                    <button type="submit" name="action" value="set_premiere" style="font-size:12px;padding:6px 12px;border-radius:8px;border:0;background:var(--st-accent);color:#0b0b12;cursor:pointer;font-weight:700">Поставить премьеру</button>
                    <?php endif; ?>
                    <?php if ($hasPrem): ?>
                    <button type="submit" name="action" value="clear_premiere" style="font-size:12px;padding:6px 10px;border-radius:8px;border:1px solid var(--st-border);background:transparent;color:var(--st-muted);cursor:pointer">Снять</button>
                    <?php endif; ?>
                    <?php if (!empty($v['slug'])): ?>
                      <a href="/video.php?slug=<?= htmlspecialchars(urlencode((string)$v['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="font-size:12px;color:var(--st-accent2)">Открыть</a>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</div>
<style>@media(max-width:900px){.st-main>div[style*="grid-template-columns"]{grid-template-columns:1fr!important}}</style>
<?php require __DIR__ . '/_layout_end.php'; ?>
