<?php
/** Подключить на video/channel: heartbeat «сейчас смотрит» */
if (empty($__nw_type) || empty($__nw_id)) return;
$__nw_title = $__nw_title ?? '';
$__nw_url = $__nw_url ?? '';
?>
<script>
(function(){
  if (!<?= !empty($GLOBALS['__user']) || (function_exists('current_user') && current_user()) ? 'true' : 'false' ?>) return;
  var payload = {
    type: <?= json_encode($__nw_type) ?>,
    id: <?= (int)$__nw_id ?>,
    title: <?= json_encode($__nw_title, JSON_UNESCAPED_UNICODE) ?>,
    url: <?= json_encode($__nw_url, JSON_UNESCAPED_UNICODE) ?>
  };
  function beat(){
    try {
      fetch('/api/now_watching.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify(payload)
      }).catch(function(){});
    } catch(e){}
  }
  beat();
  setInterval(beat, 60000);
})();
</script>
