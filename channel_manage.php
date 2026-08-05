<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/video_embed.php';
require_once __DIR__ . '/includes/paid_access.php';
require_login();
paid_ensure_schema();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM channels WHERE id = ? AND owner_id = ?');
$stmt->execute([$id, $__user['id']]);
$channel = $stmt->fetch();

if (!$channel) {
  echo '<div class="container"><div class="empty-state"><h2>Не найдено</h2><p>Канал не найден</p></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

// ---- Статистика подписчиков ----
$favCount = 0;
$paidActiveCount = 0;
$paidEverCount = 0;
try {
  $st = db()->prepare('SELECT COUNT(*) FROM favorites WHERE channel_id = ?');
  $st->execute([$id]);
  $favCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}
try {
  // активный платный доступ (не отозван, access_until в будущем или без срока + is_revoked=0)
  $st = db()->prepare(
    "SELECT COUNT(DISTINCT a.user_id) FROM promo_activations a
     JOIN promo_codes p ON p.id = a.promo_id
     WHERE a.is_revoked = 0
       AND (p.channel_id IS NULL OR p.channel_id = ?)
       AND (a.channel_id IS NULL OR a.channel_id = ?)
       AND (a.access_until IS NULL OR a.access_until > NOW())
       AND p.is_active = 1"
  );
  $st->execute([$id, $id]);
  $paidActiveCount = (int)$st->fetchColumn();
} catch (Throwable $e) {
  try {
    $st = db()->prepare(
      "SELECT COUNT(DISTINCT a.user_id) FROM promo_activations a
       JOIN promo_codes p ON p.id = a.promo_id
       WHERE a.is_revoked = 0 AND (p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?)"
    );
    $st->execute([$id, $id]);
    $paidActiveCount = (int)$st->fetchColumn();
  } catch (Throwable $e2) {}
}
try {
  // когда-либо активировали (включая истёкшие и тех, кто потом добавил в избранное)
  $st = db()->prepare(
    "SELECT COUNT(DISTINCT a.user_id) FROM promo_activations a
     JOIN promo_codes p ON p.id = a.promo_id
     WHERE (p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?)"
  );
  $st->execute([$id, $id]);
  $paidEverCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}
// всего «подписчиков» для публичного: избранное ∪ кто платил (unique)
$totalSubs = $favCount;
try {
  $st = db()->prepare(
    "SELECT COUNT(*) FROM (
       SELECT user_id FROM favorites WHERE channel_id = ?
       UNION
       SELECT a.user_id FROM promo_activations a
       JOIN promo_codes p ON p.id = a.promo_id
       WHERE p.channel_id IS NULL OR p.channel_id = ? OR a.channel_id = ?
     ) t"
  );
  $st->execute([$id, $id, $id]);
  $totalSubs = (int)$st->fetchColumn();
} catch (Throwable $e) {
  $totalSubs = max($favCount, $paidEverCount);
}
$isPaid = !empty($channel['paid_content']);


// ---- Обработка форм ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'toggle_broadcast') {
    $newState = !empty($_POST['pause']) ? 1 : 0;
    db()->prepare('UPDATE channels SET is_broadcast_paused = ? WHERE id = ? AND owner_id = ?')
      ->execute([$newState, $id, $__user['id']]);
    flash_set('success', $newState ? 'Трансляция остановлена. Зрители увидят «эфир завершён», пока вы её не включите обратно.' : 'Трансляция включена — эфир снова идёт по расписанию.');
    redirect('/channel_manage.php?id=' . $id);
  } elseif ($action === 'update_settings') {
    $stmt = db()->prepare(
      'UPDATE channels SET title=?, description=?, logo_url=?, default_source_id=?, seo_title=?, seo_description=?, seo_keywords=?, is_public=?, paid_content=?
       WHERE id = ? AND owner_id = ?'
    );
    $stmt->execute([
      trim($_POST['title']), trim($_POST['description']), trim($_POST['logo_url']) ?: null,
      $_POST['default_source_id'] !== '' ? (int)$_POST['default_source_id'] : null,
      trim($_POST['seo_title']), trim($_POST['seo_description']), trim($_POST['seo_keywords']),
      !empty($_POST['is_public']) ? 1 : 0,
      !empty($_POST['paid_content']) ? 1 : 0,
      $id, $__user['id']
    ]);
    maybe_auto_approve_channel($id);
    flash_set('success', 'Настройки сохранены');
  } elseif ($action === 'add_source') {
    require_once __DIR__ . '/includes/embed_helper.php';
    ensure_sources_direct_type();

    $rawUrl = trim($_POST['url'] ?? '');
    $type = (string)($_POST['type'] ?? '');

    // Галочка (is_verified) снимает строгую проверку ТОЛЬКО для прямых потоков
    // (m3u8/mp4/file://) — доверенный человек может вставить что угодно похожее на
    // прямую ссылку на видео, вплоть до file://, ЭТО НИКОГДА НЕ ИДЁТ НА СЕРВЕР (curl/
    // file_get_contents и т.п.) — только напрямую в <video src> у зрителя в браузере,
    // так что чтение файлов сервера через эту лазейку невозможно даже теоретически.
    // Заодно: file:// физически не загрузится в браузере на https-странице — это
    // ограничение самого браузера (mixed content), не моё — так что это скорее задел
    // на локальное тестирование самим доверенным человеком, чем реальный рабочий кейс
    // для обычных зрителей.
    //
    // Для type === 'iframe' (встраиваемые чужие плееры) галочка НИЧЕГО не снимает —
    // там всегда полная проверка через validate_player_url(), потому что iframe может
    // выполнять чужой JS в контексте страницы, а это совсем другой уровень риска,
    // чем видеопоток в <video>.
    $isDirectStreamType = in_array($type, ['direct', 'm3u8', 'mp4'], true);

    if (!empty($__user['is_verified']) && $isDirectStreamType) {
      if ($rawUrl === '') {
        flash_set('error', 'Вставьте ссылку');
        redirect('/channel_manage.php?id=' . $id);
      }
      $isFileUrl = stripos($rawUrl, 'file://') === 0;
      $isHttpsUrl = stripos($rawUrl, 'https://') === 0 && filter_var($rawUrl, FILTER_VALIDATE_URL);
      if (!$isFileUrl && !$isHttpsUrl) {
        flash_set('error', 'Доверенным доступна ссылка https:// (любая) или file:// (для локального теста) — это не похоже ни на одну из них');
        redirect('/channel_manage.php?id=' . $id);
      }
      // Поддержка ?file= — некоторые ссылки приходят обёрнутыми в чужую плеер-страницу вида
      // .../embed?file=https://cdn.../video.m3u8. Если находим такой параметр — используем
      // РЕАЛЬНУЮ ссылку на поток из него, а не саму страницу-обёртку (та не воспроизведётся
      // как <video src>, это HTML, а не поток).
      $extractedFileUrl = $isHttpsUrl ? extract_file_param_url($rawUrl) : null;
      if ($extractedFileUrl) $rawUrl = $extractedFileUrl;
      $result = $isFileUrl ? $rawUrl : normalize_embed_url($type, $rawUrl); // file:// не нормализуем — незачем гонять через regex под чужие видеохостинги
    } else {
      [$isValid, $result] = validate_player_url($_POST['type'], trim($_POST['url']));
      if (!$isValid) {
        flash_set('error', $result . ' Либо получите галочку верификации — тогда это ограничение снимается (запросите у модератора).');
        redirect('/channel_manage.php?id=' . $id);
      }
    }
    $stmt = db()->prepare('INSERT INTO sources (name, type, url, created_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([trim($_POST['name']), $_POST['type'], $result, $__user['id']]);
    $newSourceId = (int)db()->lastInsertId();
    // Если у канала ещё не было источника по умолчанию — назначаем только что добавленный
    // и сразу пробуем автомодерацию (частый случай: создали канал пустым, потом донастроили).
    $stmt2 = db()->prepare('SELECT default_source_id FROM channels WHERE id = ?');
    $stmt2->execute([$id]);
    if (!$stmt2->fetchColumn()) {
      db()->prepare('UPDATE channels SET default_source_id = ? WHERE id = ?')->execute([$newSourceId, $id]);
    }
    maybe_auto_approve_channel($id);
  } elseif ($action === 'add_schedule') {
    $stmt = db()->prepare(
      'INSERT INTO schedule (channel_id, day_of_week, start_time, end_time, source_id, program_title) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, (int)$_POST['day_of_week'], $_POST['start_time'], $_POST['end_time'], (int)$_POST['source_id'], trim($_POST['program_title']) ?: null]);
  } elseif ($action === 'delete_schedule') {
    $stmt = db()->prepare('DELETE FROM schedule WHERE id = ? AND channel_id = ?');
    $stmt->execute([(int)$_POST['schedule_id'], $id]);
  } elseif ($action === 'add_sticker') {
    $stmt = db()->prepare('INSERT INTO stickers (channel_id, code, image_url) VALUES (?, ?, ?)');
    $stmt->execute([$id, trim($_POST['code']), trim($_POST['image_url'])]);
  } elseif ($action === 'add_moderator') {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'])]);
    $u = $stmt->fetch();
    if ($u) {
      db()->prepare('INSERT IGNORE INTO chat_moderators (channel_id, user_id) VALUES (?, ?)')->execute([$id, $u['id']]);
    }
  } elseif ($action === 'unban_user') {
    db()->prepare('DELETE FROM chat_bans WHERE channel_id = ? AND user_id = ?')->execute([$id, (int)$_POST['user_id']]);
    flash_set('success', 'Пользователь разблокирован на канале');
  } elseif ($action === 'ban_user') {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'])]);
    $u = $stmt->fetch();
    if ($u) {
      db()->prepare('INSERT IGNORE INTO chat_bans (channel_id, user_id) VALUES (?, ?)')->execute([$id, $u['id']]);
      flash_set('success', 'Пользователь заблокирован на канале');
    } else {
      flash_set('error', 'Пользователь с таким логином не найден');
    }
  }
  redirect('/channel_manage.php?id=' . $id);
}

$sources = db()->prepare('SELECT * FROM sources WHERE created_by IS NULL OR created_by = ?');
$sources->execute([$__user['id']]);
$sources = $sources->fetchAll();

$schedule = db()->prepare('SELECT * FROM schedule WHERE channel_id = ? ORDER BY day_of_week, start_time');
$schedule->execute([$id]);
$schedule = $schedule->fetchAll();

$stickers = db()->prepare('SELECT * FROM stickers WHERE channel_id = ?');
$stickers->execute([$id]);
$stickers = $stickers->fetchAll();

$mods = db()->prepare('SELECT u.id, u.username FROM chat_moderators cm JOIN users u ON u.id = cm.user_id WHERE cm.channel_id = ?');
$mods->execute([$id]);
$mods = $mods->fetchAll();

$banned = db()->prepare('SELECT u.id, u.username FROM chat_bans cb JOIN users u ON u.id = cb.user_id WHERE cb.channel_id = ?');
$banned->execute([$id]);
$banned = $banned->fetchAll();

$days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

// Видео этого канала — для блока импорта/списка на странице управления
$channelVideos = [];
try {
  $cv = db()->prepare('SELECT id, slug, title, status, views_count, thumbnail_url FROM videos WHERE channel_id = ? ORDER BY created_at DESC');
  $cv->execute([$id]);
  $channelVideos = $cv->fetchAll();
} catch (\Throwable $e) { /* миграция видео ещё не залита — просто не показываем блок */ }
?>
<div class="container">
  <h2 style="margin-top:24px">Управление: <?= e($channel['title']) ?>
    <span class="status-pill status-<?= e($channel['status']) ?>"><?= e($channel['status']) ?></span>
  </h2>

  <div class="form-card form-wide" style="margin:16px 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
    <?php if ($isPaid): ?>
      <div style="padding:14px;border-radius:12px;background:rgba(254,44,85,.08);border:1px solid rgba(254,44,85,.25)">
        <div style="font-size:12px;opacity:.75">Платный доступ (активен)</div>
        <div style="font-size:28px;font-weight:700;margin-top:4px"><?= (int)$paidActiveCount ?></div>
        <div style="font-size:11px;opacity:.65">оформили промокод, доступ не истёк</div>
      </div>
      <div style="padding:14px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;opacity:.75">Платили когда-либо</div>
        <div style="font-size:28px;font-weight:700;margin-top:4px"><?= (int)$paidEverCount ?></div>
        <div style="font-size:11px;opacity:.65">включая истёкшие</div>
      </div>
      <div style="padding:14px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;opacity:.75">В избранном</div>
        <div style="font-size:28px;font-weight:700;margin-top:4px"><?= (int)$favCount ?></div>
      </div>
    <?php else: ?>
      <div style="padding:14px;border-radius:12px;background:rgba(62,166,255,.1);border:1px solid rgba(62,166,255,.25)">
        <div style="font-size:12px;opacity:.75">Подписчики (всего)</div>
        <div style="font-size:28px;font-weight:700;margin-top:4px"><?= (int)$totalSubs ?></div>
        <div style="font-size:11px;opacity:.65">избранное + кто раньше оформлял платный доступ</div>
      </div>
      <div style="padding:14px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;opacity:.75">В избранном</div>
        <div style="font-size:28px;font-weight:700;margin-top:4px"><?= (int)$favCount ?></div>
      </div>
      <?php if ($paidEverCount > 0): ?>
      <div style="padding:14px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)">
        <div style="font-size:12px;opacity:.75">Раньше платили</div>
        <div style="font-size:28px;font-weight:700;margin-top:4px"><?= (int)$paidEverCount ?></div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <p style="color:var(--text-dim);font-size:13px">
    Публичная страница: <a href="/channel.php?slug=<?= e($channel['slug']) ?>" style="color:var(--accent-2)">/channel.php?slug=<?= e($channel['slug']) ?></a> ·
    Embed-код: <code style="color:var(--accent-2);word-break:break-all;white-space:normal;display:inline-block">&lt;iframe src="<?= e(SITE_URL) ?>/embed.php?slug=<?= e($channel['slug']) ?>"&gt;&lt;/iframe&gt;</code>
  </p>

  <div class="form-card form-wide" style="margin:24px 0">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <h3 style="margin:0">🎬 Видео канала (<?= count($channelVideos) ?>)</h3>
      <a href="/video_import.php?channel_id=<?= (int)$id ?>" class="btn btn-primary btn-sm">+ Импортировать видео</a>
    </div>
    <?php if (!$channelVideos): ?>
      <p style="color:var(--text-dim);font-size:13px;margin-top:10px">Ещё ни одного видео. Нажмите «Импортировать видео» — вставьте ссылку с YouTube/VK/Rutube/TikTok/Twitch/ok.ru и т.д.</p>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-top:12px">
        <?php foreach ($channelVideos as $v): ?>
          <a href="/video.php?slug=<?= e($v['slug']) ?>" style="text-decoration:none;color:inherit">
            <div style="aspect-ratio:16/9;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:8px;position:relative">
              <span class="status-pill status-<?= $v['status']==='published'?'approved':($v['status']==='failed'?'rejected':'pending') ?>" style="position:absolute;top:6px;right:6px;font-size:10px"><?= e($v['status']) ?></span>
            </div>
            <div style="font-size:12px;padding:4px 2px"><?= e(mb_substr($v['title'], 0, 40)) ?> · <?= (int)$v['views_count'] ?> просм.</div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="form-card form-wide" style="margin:24px 0;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div>
      <h3 style="margin:0 0 4px">Трансляция</h3>
      <?php if (!empty($channel['is_broadcast_paused'])): ?>
        <p style="color:var(--danger);font-size:13px;margin:0">⏸ Сейчас остановлена вручную — зрители видят «эфир завершён» вместо плеера.</p>
      <?php else: ?>
        <p style="color:var(--text-dim);font-size:13px;margin:0">▶ Идёт как обычно, по расписанию/источнику по умолчанию.</p>
      <?php endif; ?>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_broadcast">
      <?php if (!empty($channel['is_broadcast_paused'])): ?>
        <input type="hidden" name="pause" value="0">
        <button class="btn btn-primary" type="submit">▶ Включить трансляцию</button>
      <?php else: ?>
        <input type="hidden" name="pause" value="1">
        <button class="btn btn-outline" type="submit" style="border-color:var(--danger);color:var(--danger)">⏸ Стоп трансляция</button>
      <?php endif; ?>
    </form>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3>Основные настройки и SEO</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_settings">
      <label>Название</label>
      <input type="text" name="title" value="<?= e($channel['title']) ?>" required>
      <label>Описание</label>
      <textarea name="description"><?= e($channel['description']) ?></textarea>
      <label>Логотип</label>
      <input type="url" name="logo_url" value="<?= e($channel['logo_url']) ?>">
      <label>Источник по умолчанию</label>
      <select name="default_source_id">
        <option value="">— нет —</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $channel['default_source_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e($s['type']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <label>SEO заголовок</label>
      <input type="text" name="seo_title" value="<?= e($channel['seo_title']) ?>">
      <label>SEO описание</label>
      <textarea name="seo_description"><?= e($channel['seo_description']) ?></textarea>
      <label>SEO ключевые слова</label>
      <input type="text" name="seo_keywords" value="<?= e($channel['seo_keywords']) ?>" placeholder="тв онлайн, смотреть бесплатно, ...">
      <label style="margin-top:14px;display:flex;align-items:center;gap:8px;font-weight:400">
        <input type="checkbox" name="is_public" value="1" style="width:auto" <?= $channel['is_public'] ? 'checked' : '' ?>>
        Показывать канал в моём публичном профиле
      </label>
      <label style="margin-top:14px;display:flex;align-items:center;gap:8px;font-weight:400">
        <input type="checkbox" name="paid_content" value="1" style="width:auto" <?= !empty($channel['paid_content']) ? 'checked' : '' ?>>
        Платный / закрытый контент (доступ по промокоду)
      </label>
      <p style="font-size:12px;color:var(--text-dim);margin:6px 0 0">При включении зрители без активированного промокода не увидят плеер (channel / embed).</p>
      <button class="btn btn-primary" style="margin-top:20px" type="submit">Сохранить</button>
    </form>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3>Свои источники вещания</h3>
    <?php if (!empty($__user['is_verified'])): ?>
      <p style="font-size:12.5px;color:var(--accent-2);margin-top:-6px">✓ У вас верифицированный аккаунт — можно указать любую ссылку, без проверки на «похоже на плеер».</p>
    <?php else: ?>
      <p style="font-size:12.5px;color:var(--text-dim);margin-top:-6px">Ссылка должна быть прямым embed-плеером (YouTube/VK/Rutube/mp4/m3u8 либо известный видеохостинг). Если нужна нестандартная ссылка — запросите у модератора верификацию (галочку ✓).</p>
    <?php endif; ?>
    <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_source">
      <div><label>Название</label><input type="text" name="name" required></div>
      <div><label>Тип</label>
        <select name="type">
          <?php if (!empty($__user['is_verified'])): ?><option value="direct">Прямая ссылка (доверенным, любая https:// или file://)</option><?php endif; ?>
          <option value="mp4">MP4</option><option value="m3u8">M3U8 (HLS)</option>
          <option value="youtube">YouTube</option><option value="vk">VK Видео</option>
          <option value="rutube">Rutube</option><option value="iframe">Другое (iframe)</option>
        </select>
      </div>
      <div style="flex:1"><label>URL</label><input type="text" name="url" required placeholder="https://... или file:///путь (только доверенным)"></div>
      <button class="btn btn-primary" type="submit">Добавить</button>
    </form>
    <?php if (!empty($__user['is_verified'])): ?>
      <p style="font-size:11.5px;color:var(--text-dim);margin-top:6px">
        ⚠️ file:// сохранится, но современные браузеры (Chrome/Edge) блокируют ЗАГРУЗКУ file://
        на https-странице как небезопасный контент — это ограничение самого браузера при просмотре,
        а не при сохранении ссылки. Подходит для локального теста в собственном браузере с
        отключённой этой защитой, не для обычных зрителей.
      </p>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin-top:16px">
      <?php foreach ($sources as $s):
        $__previewPlatform = in_array($s['type'], ['mp4', 'm3u8'], true) ? $s['type'] : (detect_video_platform($s['url']) ?? 'iframe');
      ?>
        <div style="border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--bg-elevated)">
          <b style="font-size:13px;display:block;margin-bottom:2px"><?= e($s['name']) ?></b>
          <span style="font-size:11px;color:var(--text-dim)"><?= e($s['type']) ?></span>
          <div style="margin-top:8px;transform:scale(1);border-radius:8px;overflow:hidden">
            <?php render_player_embed($__previewPlatform, $s['url'], $s['url'], 'sourcePreview' . (int)$s['id']); ?>
          </div>
          <p style="font-size:10.5px;color:var(--text-dim);margin-top:6px">Если тут не воспроизводится — не будет работать и у зрителей. Проверьте ссылку или удалите источник.</p>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$sources): ?><p style="color:var(--text-dim);font-size:13px;margin-top:10px">Источников пока нет.</p><?php endif; ?>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3>Расписание эфира (пн–вс)</h3>
    <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_schedule">
      <div><label>День</label>
        <select name="day_of_week">
          <?php foreach ($days as $i => $d): ?><option value="<?= $i ?>"><?= $d ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>С</label><input type="time" name="start_time" required></div>
      <div><label>До</label><input type="time" name="end_time" required></div>
      <div><label>Источник</label>
        <select name="source_id" required>
          <?php foreach ($sources as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Название программы</label><input type="text" name="program_title"></div>
      <button class="btn btn-primary" type="submit">Добавить в расписание</button>
    </form>
    <table class="schedule-table">
      <thead><tr><th>День</th><th>Время</th><th>Программа</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($schedule as $s): ?>
        <tr>
          <td><?= $days[(int)$s['day_of_week']] ?></td>
          <td><?= e($s['start_time']) ?>–<?= e($s['end_time']) ?></td>
          <td><?= e($s['program_title'] ?: '—') ?></td>
          <td>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_schedule">
              <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">Удалить</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3>Свои стикеры чата</h3>
    <form method="POST" style="display:flex;gap:10px;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_sticker">
      <div><label>Код</label><input type="text" name="code" placeholder=":myHype:" required></div>
      <div style="flex:1"><label>Ссылка на изображение</label><input type="url" name="image_url" required></div>
      <button class="btn btn-primary" type="submit">Добавить</button>
    </form>
    <div class="sticker-row" style="margin-top:14px">
      <?php foreach ($stickers as $s): ?><img src="<?= e($s['image_url']) ?>" title="<?= e($s['code']) ?>"><?php endforeach; ?>
    </div>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3>Модераторы чата</h3>
    <form method="POST" style="display:flex;gap:10px;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_moderator">
      <div style="flex:1"><label>Логин пользователя</label><input type="text" name="username" required></div>
      <button class="btn btn-primary" type="submit">Назначить</button>
    </form>
    <ul>
      <?php foreach ($mods as $m): ?><li><?= e($m['username']) ?></li><?php endforeach; ?>
    </ul>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3>Заблокированные на канале</h3>
    <p style="color:var(--text-dim);font-size:13px">Блокировка закрывает и чат, и саму страницу канала (показывает «УВЫ, ВАС ЗАБЛОКИРОВАЛИ»). Ставится вручную или автоматически при удалении комментария.</p>
    <form method="POST" style="display:flex;gap:10px;align-items:end;margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ban_user">
      <div style="flex:1"><label>Логин пользователя</label><input type="text" name="username" required></div>
      <button class="btn btn-danger" type="submit">Заблокировать</button>
    </form>
    <ul style="margin-top:10px">
      <?php foreach ($banned as $b): ?>
        <li style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
          <span><?= e($b['username']) ?></span>
          <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unban_user">
            <input type="hidden" name="user_id" value="<?= (int)$b['id'] ?>">
            <button type="submit" style="background:none;border:none;color:var(--accent-2);font-size:12px;cursor:pointer">разблокировать</button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if (empty($banned)): ?><li style="color:var(--text-dim);font-size:13px;list-style:none">Заблокированных нет</li><?php endif; ?>
    </ul>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
