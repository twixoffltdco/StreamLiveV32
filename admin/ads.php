<?php
require_once __DIR__ . '/_layout_start.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  set_setting('player_ads_enabled', !empty($_POST['enabled']) ? '1' : '0');
  set_setting('player_ads_skip_after', (string)max(0, min(30, (int)($_POST['skip_after'] ?? 5))));
  set_setting('player_ads_cooldown', (string)max(10, min(3600, (int)($_POST['cooldown'] ?? 60))));
  set_setting('player_ads_image_url', trim((string)($_POST['image_url'] ?? '')));
  set_setting('player_ads_click_url', trim((string)($_POST['click_url'] ?? '')));
  set_setting('player_ads_video_url', trim((string)($_POST['video_url'] ?? '')));
  // HTML только админу — без фильтрации тегов (как custom BB в админке)
  set_setting('player_ads_html', (string)($_POST['html'] ?? ''));
  flash_set('success', 'Настройки рекламы в плеерах сохранены');
  redirect('/admin/ads.php');
}

$enabled = get_setting('player_ads_enabled', '0') === '1';
$skip = get_setting('player_ads_skip_after', '5');
$cool = get_setting('player_ads_cooldown', '60');
$image = get_setting('player_ads_image_url', '');
$click = get_setting('player_ads_click_url', '');
$video = get_setting('player_ads_video_url', '');
$html = get_setting('player_ads_html', '');
?>
<h2>Реклама в плеерах</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:640px">
  Pre-roll перед видео и в embed ТВ/радио — как на russtube / нашютуб:
  кнопка «Пропустить» через N секунд, после скипа или конца ролика реклама
  <b>не показывается</b> повторно <?= (int)$cool ?> сек (кулдаун в браузере).
</p>

<div class="form-card form-wide">
  <form method="POST">
    <?= csrf_field() ?>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
      Включить рекламу в плеерах
    </label>

    <label>Секунд до «Пропустить»</label>
    <input type="number" name="skip_after" min="0" max="30" value="<?= e($skip) ?>" style="width:120px">

    <label>Кулдаун после скипа (сек)</label>
    <input type="number" name="cooldown" min="10" max="3600" value="<?= e($cool) ?>" style="width:120px">

    <label>Картинка баннера (URL)</label>
    <input type="url" name="image_url" value="<?= e($image) ?>" placeholder="https://…/banner.jpg">

    <label>Ссылка по клику</label>
    <input type="url" name="click_url" value="<?= e($click) ?>" placeholder="https://partner.example/">

    <label>MP4 pre-roll (URL, необязательно)</label>
    <input type="url" name="video_url" value="<?= e($video) ?>" placeholder="https://…/preroll.mp4">

    <label>Свой HTML-креатив (имеет приоритет над картинкой, если заполнен)</label>
    <textarea name="html" rows="5" placeholder="<div>…</div>"><?= e($html) ?></textarea>

    <button class="btn btn-primary" type="submit" style="margin-top:14px">Сохранить</button>
  </form>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
