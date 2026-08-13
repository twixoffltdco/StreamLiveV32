<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
  csrf_verify();
  if (!$user) { redirect('/auth/login.php'); }
  $videoId = (int)$_POST['video_id'];
  $stmt = db()->prepare("SELECT platform FROM videos WHERE id = ? AND status = 'published'");
  $stmt->execute([$videoId]);
  $video = $stmt->fetch();
  if (!$video || !in_array($video['platform'], ['mp4', 'm3u8'], true)) {
    flash_set('error', 'Совместный просмотр доступен только для .mp4/.m3u8 видео — у сторонних плееров (YouTube/VK/итд) нет доступа к управлению со стороннего сайта');
    redirect('/video.php?id=' . $videoId);
  }
  $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
  $stmt = db()->prepare('INSERT INTO watch_rooms (video_id, host_user_id, room_code) VALUES (?, ?, ?)');
  $stmt->execute([$videoId, $user['id'], $code]);
  redirect('/watch_room.php?code=' . $code);
}

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if ($code === '') { http_response_code(404); die('Комната не указана'); }

$stmt = db()->prepare(
  "SELECT wr.*, v.slug, v.title, v.embed_url, v.platform, u.username AS host_name FROM watch_rooms wr
   JOIN videos v ON v.id = wr.video_id JOIN users u ON u.id = wr.host_user_id WHERE wr.room_code = ?"
);
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) { http_response_code(404); require_once __DIR__ . '/includes/header.php'; echo '<div class="container"><p>Комната не найдена или уже закрыта</p></div>'; require_once __DIR__ . '/includes/footer.php'; exit; }

$isHost = $user && (int)$user['id'] === (int)$room['host_user_id'];
$guestName = $user['username'] ?? ('Гость' . substr(md5((string)session_id()), 0, 4));

$pageTitle = 'Смотрим вместе: ' . $room['title'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:900px">
  <h1>👥 Смотрим вместе</h1>
  <p style="color:var(--text-dim)">
    <?= e($room['title']) ?> · хост: <?= e($room['host_name']) ?> ·
    код комнаты: <b style="letter-spacing:2px"><?= e($room['room_code']) ?></b>
    <button onclick="navigator.clipboard.writeText(location.href);this.textContent='Скопировано!'" class="btn btn-outline btn-sm" style="margin-left:8px">Скопировать ссылку</button>
  </p>

  <div class="player-wrap" style="position:relative;padding-top:56.25%;background:#000;border-radius:10px;overflow:hidden">
    <video id="syncPlayer" <?= $room['platform'] === 'mp4' ? '' : '' ?> controls style="position:absolute;top:0;left:0;width:100%;height:100%"></video>
  </div>
  <?php if (!$isHost): ?><p style="color:var(--text-dim);font-size:12px;margin-top:6px">Плеер синхронизируется с хостом автоматически каждые 2 сек — небольшая задержка это нормально.</p><?php endif; ?>

  <h2 style="font-size:16px;margin-top:20px">Чат комнаты</h2>
  <div id="roomChat" style="height:200px;overflow-y:auto;background:var(--card);border-radius:10px;padding:10px;margin-bottom:8px"></div>
  <form id="roomChatForm" style="display:flex;gap:8px">
    <input type="text" id="roomChatInput" placeholder="Написать в чат комнаты..." style="flex:1;padding:8px" maxlength="500">
    <button type="submit" class="btn btn-primary btn-sm">Отправить</button>
  </form>
</div>
<script>
(function () {
  var video = document.getElementById('syncPlayer');
  var src = <?= json_encode($room['embed_url']) ?>;
  var isHost = <?= $isHost ? 'true' : 'false' ?>;
  var roomCode = <?= json_encode($room['room_code']) ?>;
  var lastServerUpdate = 0;

  <?php if ($room['platform'] === 'm3u8'): ?>
  var hlsScript = document.createElement('script');
  hlsScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js';
  hlsScript.onload = function () {
    if (window.Hls && Hls.isSupported()) { var hls = new Hls(); hls.loadSource(src); hls.attachMedia(video); }
    else { video.src = src; }
  };
  document.head.appendChild(hlsScript);
  <?php else: ?>
  video.src = src;
  <?php endif; ?>

  if (isHost) {
    // Хост шлёт своё состояние раз в 2 сек — этого достаточно для комнаты друзей,
    // не претендуем на покадровую синхронизацию как в проф. watch-party сервисах
    setInterval(function () {
      fetch('/watch_room_action', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({room_code: roomCode, is_playing: !video.paused, position: video.currentTime})
      });
    }, 30000);
  } else {
    setInterval(function () {
      fetch('/watch_room_poll?code=' + roomCode).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) return;
        var drift = Math.abs(video.currentTime - d.position);
        if (drift > 2) video.currentTime = d.position; // рассинхрон больше 2 сек — подтягиваем
        if (d.is_playing && video.paused) video.play().catch(function(){});
        if (!d.is_playing && !video.paused) video.pause();
      });
    }, 30000);
  }

  // Чат комнаты (тот же polling-паттерн, что у тебя в остальном сайте)
  var chatBox = document.getElementById('roomChat');
  var lastMsgId = 0;
  function renderMsg(m) {
    var div = document.createElement('div');
    div.style.marginBottom = '4px';
    div.innerHTML = '<b>' + m.author.replace(/</g,'&lt;') + ':</b> ' + m.message.replace(/</g,'&lt;');
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight;
  }
  function pollChat() {
    fetch('/watch_room_chat_poll?code=' + roomCode + '&after=' + lastMsgId)
      .then(function (r) { return r.json(); }).then(function (d) {
        (d.messages || []).forEach(function (m) { renderMsg(m); lastMsgId = m.id; });
      }).finally(function () { setTimeout(pollChat, 30000); });
  }
  pollChat();

  document.getElementById('roomChatForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('roomChatInput');
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    fetch('/watch_room_chat_send', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({room_code: roomCode, message: text})
    });
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
