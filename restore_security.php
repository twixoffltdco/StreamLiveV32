<?php
/**
 * Один раз: /restore_security.php
 * - удаляет storage/geo_off (чтобы гео снова слушало админку)
 * - НЕ выключает antibot (он в includes/antibot.php как в оригинале)
 * Потом удали этот файл.
 */
header('Content-Type: text/plain; charset=utf-8');
$root = __DIR__;
$off = $root . '/storage/geo_off';
if (is_file($off)) {
  @unlink($off);
  echo "removed storage/geo_off\n";
} else {
  echo "geo_off already absent\n";
}
echo "antibot: use includes/antibot.php from this ZIP (original limits)\n";
echo "geo: controlled again by Admin → Security (geo_restrict_enabled)\n";
echo "Delete restore_security.php\n";
