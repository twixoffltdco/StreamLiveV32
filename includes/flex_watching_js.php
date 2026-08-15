<?php
if (empty($__fw_title)) return;
$__fw_url = $__fw_url ?? '';
$__fw_source = $__fw_source ?? 'video';
?>
<script>
(function () {
  var payload = {
    title: <?= json_encode((string)$__fw_title, JSON_UNESCAPED_UNICODE) ?>,
    url: <?= json_encode((string)$__fw_url, JSON_UNESCAPED_UNICODE) ?>,
    source: <?= json_encode((string)$__fw_source, JSON_UNESCAPED_UNICODE) ?>
  };
  function beat() {
    try {
      fetch('/api/flex_watching.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      }).catch(function () {});
    } catch (e) {}
  }
  beat();
  setInterval(beat, 45000);
  window.addEventListener('beforeunload', function () {
    try {
      navigator.sendBeacon && navigator.sendBeacon('/api/flex_watching.php', new Blob([JSON.stringify({ clear: 1 })], { type: 'application/json' }));
    } catch (e) {}
  });
})();
</script>
