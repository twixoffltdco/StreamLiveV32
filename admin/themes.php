<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/themes.php';
$admin = require_admin(); $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $name = trim($_POST['name'] ?? ''); $css = (string)($_POST['css'] ?? ''); $header = (string)($_POST['header'] ?? ''); $footer = (string)($_POST['footer'] ?? '');
  if ($name === '') $error = 'Введите название темы.';
  else { $slug = themes_slug($name); themes_bootstrap(); file_put_contents(themes_public_css_dir()."/$slug.css", $css); file_put_contents(themes_dir()."/$slug.header.html", $header); file_put_contents(themes_dir()."/$slug.footer.html", $footer); $themes=themes_all(); $themes=array_values(array_filter($themes, fn($t)=>($t['slug']??'')!==$slug)); $themes[]=['slug'=>$slug,'name'=>$name,'css'=>'/assets/themes/'.$slug.'.css','header'=>"$slug.header.html",'footer'=>"$slug.footer.html"]; themes_save_all($themes); flash_set('success','Тема сохранена в файлы.'); redirect('/admin/themes.php'); }
}
$themes = themes_all(); $pageTitle='Темы'; require_once __DIR__ . '/_layout_start.php';
?>
<h1>Темы</h1><?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?>
<form method="post" class="form-card"><?=csrf_field()?><label>Название темы</label><input name="name" required style="width:100%;padding:10px"><label>CSS код</label><textarea name="css" rows="10" style="width:100%;padding:10px"></textarea><label>Header HTML</label><textarea name="header" rows="5" style="width:100%;padding:10px"></textarea><label>Footer HTML</label><textarea name="footer" rows="5" style="width:100%;padding:10px"></textarea><button class="btn btn-primary">Сохранить в файловое хранилище</button></form>
<table class="admin-table"><tr><th>Тема</th><th>CSS</th></tr><?php foreach($themes as $t):?><tr><td><?=e($t['name'])?></td><td><?=e($t['css'])?></td></tr><?php endforeach;?></table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
