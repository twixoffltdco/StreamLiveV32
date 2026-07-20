<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare("SELECT * FROM channels WHERE slug = ? AND status = 'approved'");
$stmt->execute([$slug]);
$channel = $stmt->fetch();

if (!$channel) {
  http_response_code(404);
  echo 'Канал недоступен';
  exit;
}

$__user = current_user();
if ($__user && is_user_banned_on_channel($channel['id'], $__user['id'])) {
  http_response_code(403);
  echo 'УВЫ, ВАС ЗАБЛОКИРОВАЛИ';
  exit;
}

db()->prepare('UPDATE channels SET views = views + 1 WHERE id = ?')->execute([$channel['id']]);
$activeSource = resolve_active_source($channel);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($channel['title']) ?></title>
 <script src= "https://player.twitch.tv/js/embed/v1.js?version=3.1.1"></script>
<style>
  html,body{margin:0;padding:0;background:#000;height:100%}
  .player-wrap{width:100%;height:100vh;position:relative;display:flex;align-items:center;justify-content:center}
  #playerjs-container{width:100%;height:100%}
  .logo{position:absolute;bottom:10px;right:10px;width:32px;height:32px;border-radius:8px;opacity:.85;z-index:5;pointer-events:none}
</style>
</head>
<body>
<div class="player-wrap" id="player-wrap">
  <div id="playerjs-container"></div>
  <?php if ($channel['logo_url']): ?><img class="logo" src="<?= e($channel['logo_url']) ?>" alt=""><?php endif; ?>
</div>

<script src="playerjs.js"></script>
<script>
  const channelId = <?= (int)$channel['id'] ?>;
  let currentSourceId = <?= $activeSource ? (int)$activeSource['id'] : 'null' ?>;
  let playerInstance = null;
  let mp4SyncInterval = null;
  let mp4SyncDisabled = false;

  // ---------- PlayerJS ----------
  function destroyPlayer() {
    if (playerInstance) {
      try { playerInstance.destroy(); } catch (e) {}
      playerInstance = null;
    }
    const container = document.getElementById('playerjs-container');
    if (container) container.innerHTML = '';
  }

  function initPlayer(source, isPaused) {
    destroyPlayer();
    const container = document.getElementById('playerjs-container');
    if (!container) return;

    if (!source) {
      const msg = isPaused ? 'Трансляция завершена. Владелец канала временно остановил эфир.' : 'Эфир недоступен';
      container.innerHTML = '<div style="color:#888;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;text-align:center;padding:20px">' + msg + '</div>';
      return;
    }

    // Для iframe-источников (например, YouTube) используем прямой iframe
    if (source.type === 'iframe') {
      container.innerHTML = `<iframe src="${source.url.replace(/&/g, '&amp;')}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen style="width:100%;height:100%;border:0;"></iframe>`;
      return;
    }

    const options = {
      id: 'playerjs-container',
      file: source.url,
      autoplay: true,
      muted: true,
      poster: <?= json_encode($channel['logo_url']) ?: '' ?>,
      // PlayerJS сам определит тип по расширению, но можно явно:
      // type: source.type === 'm3u8' ? 'hls' : 'mp4'
    };

    try {
      playerInstance = new Playerjs(options);
      if (source.type === 'mp4' && source.sync_epoch) {
        startMp4Sync(source.sync_epoch);
      } else {
        stopMp4Sync();
      }
    } catch (e) {
      console.error('PlayerJS init error:', e);
      container.innerHTML = '<div style="color:#f44;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif">Ошибка загрузки плеера</div>';
    }
  }

  // ---------- Синхронизация MP4 ----------
  function stopMp4Sync() {
    if (mp4SyncInterval) {
      clearInterval(mp4SyncInterval);
      mp4SyncInterval = null;
    }
    mp4SyncDisabled = false;
  }

  function startMp4Sync(epoch) {
    stopMp4Sync();
    if (!epoch) return;
    mp4SyncDisabled = false;

    function applySync() {
      if (mp4SyncDisabled || !playerInstance) return;
      try {
        const duration = playerInstance.getDuration();
        if (!duration || !isFinite(duration)) return;
        const target = ((Date.now() / 1000 - epoch) % duration + duration) % duration;
        const current = playerInstance.getCurrentTime();
        if (Math.abs(current - target) > 2.5) {
          playerInstance.setCurrentTime(target);
        }
      } catch (e) {
        mp4SyncDisabled = true;
      }
    }

    // Ждём загрузки метаданных
    const checkReady = setInterval(() => {
      if (playerInstance && playerInstance.getDuration && playerInstance.getDuration() > 0) {
        clearInterval(checkReady);
        applySync();
        mp4SyncInterval = setInterval(applySync, 8000);
      }
    }, 500);
    setTimeout(() => clearInterval(checkReady), 10000);
  }

  // ---------- Переключение источников по расписанию ----------
  async function checkSchedule() {
    try {
      const resp = await fetch(`/now_playing.php?channel_id=${channelId}`);
      const data = await resp.json();
      if (!data.ok) return;
      const source = data.source;
      const newId = source ? source.id : null;
      if (newId !== currentSourceId) {
        currentSourceId = newId;
        initPlayer(source, data.is_paused);
      }
    } catch (e) {}

    // Проверка живости потока для ЛЮБОГО активного m3u8 (не только RTMP-relay) —
    // если стрим пропал, показываем сообщение прямо на плеере без перезагрузки;
    // как только вещание возобновится — плеер сам переинициализируется.
    try {
      const liveResp = await fetch(`/check_stream_live.php?channel_id=${channelId}`);
      const liveData = await liveResp.json();
      const container = document.getElementById('playerjs-container') || document.getElementById('player-wrap');
      if (liveData.applicable && container) {
        if (!liveData.live && !container.dataset.offlineShown) {
          container.innerHTML = '<div style="color:#888;display:flex;align-items:center;justify-content:center;height:100%;text-align:center;padding:20px;font-family:sans-serif">Стрим сейчас выключен. Включится сам, как только вещание возобновится.</div>';
          container.dataset.offlineShown = '1';
        } else if (liveData.live && container.dataset.offlineShown) {
          container.dataset.offlineShown = '';
          currentSourceId = null;
        }
      }
    } catch (e) {}
  }

  // Стартуем плеер с активным источником
  setTimeout(() => {
    initPlayer(<?= json_encode($activeSource) ?>, <?= !empty($channel['is_broadcast_paused']) ? 'true' : 'false' ?>);
  }, 100);

  // Проверяем расписание каждые 15 секунд
  setInterval(checkSchedule, 15000);
</script>
</body>
</html>