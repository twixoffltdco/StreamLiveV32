<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/themes.php';
$admin = require_admin();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $name = trim($_POST['name'] ?? '');
  $css = (string)($_POST['css'] ?? '');
  $header = (string)($_POST['header'] ?? '');
  $footer = (string)($_POST['footer'] ?? '');

  if ($name === '') {
    $error = 'Введите название темы.';
  } else {
    try {
      $slug = themes_slug($name);
      themes_save($slug, $name, $css, $header, $footer);
      flash_set('success', 'Тема сохранена полностью: CSS, header и footer доступны в переключателе тем.');
      redirect('/admin/themes.php');
    } catch (Throwable $e) {
      $error = 'Не удалось сохранить тему: ' . $e->getMessage();
    }
  }
}

$themes = themes_all();
$pageTitle='Темы'; require_once __DIR__ . '/_layout_start.php';
?>
<h1>Темы</h1><?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?>
<form method="post" class="form-card"><?=csrf_field()?><label>Название темы</label><input name="name" required style="width:100%;padding:10px"><label>CSS код</label><textarea name="css" rows="10" style="width:100%;padding:10px"></textarea><label>Header HTML</label><textarea name="header" rows="5" style="width:100%;padding:10px"></textarea><label>Footer HTML</label><textarea name="footer" rows="5" style="width:100%;padding:10px"></textarea><button class="btn btn-primary">Сохранить тему</button></form>
<table class="admin-table"><tr><th>Тема</th><th>CSS</th><th>Header</th><th>Footer</th></tr><?php foreach($themes as $t):?><tr><td><?=e($t['name'])?></td><td><?=e($t['css'])?></td><td><?=e($t['header'] ?? '')?></td><td><?=e($t['footer'] ?? '')?></td></tr><?php endforeach;?></table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
