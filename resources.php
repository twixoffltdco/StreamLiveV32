<?php
require_once __DIR__ . '/includes/resources.php';
require_once __DIR__ . '/includes/user_display.php';
if (is_file(__DIR__ . '/includes/recommendations.php')) require_once __DIR__ . '/includes/recommendations.php';
$items = resources_list(false, 80);
$pageTitle = 'Ресурсы';
$seoDescription = 'Каталог ресурсов StreamLive: проекты, репозитории, сборки, документация и полезные ссылки. Скачать можно без регистрации.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:24px 0">
    <div><h1 style="margin:0">📦 Ресурсы</h1><p style="color:var(--text-dim);font-size:13px">GitHub/GitVerse-стиль: README, внешняя ссылка, SEO и RSS. Файлы на сайт не загружаются.</p></div>
    <?php if ($__user): ?><a class="btn btn-primary" href="/resource_new.php">Опубликовать ресурс</a><?php else: ?><a class="btn btn-primary" href="/auth/login.php">Войти и опубликовать</a><?php endif; ?>
  </div>
  <div class="grid">
    <?php foreach ($items as $r): ?>
      <article class="card"><h3><a href="<?= e(resource_url($r)) ?>"><?= e($r['title']) ?></a></h3><p><?= e($r['summary']) ?></p><p style="color:var(--text-dim);font-size:12px">Автор: <?php
        $au = ['id'=>(int)($r['author_id']??$r['user_id']??0),'username'=>$r['username']??'','username_css'=>$r['username_css']??null,'prefix_id'=>$r['prefix_id']??null,'custom_prefix_id'=>$r['custom_prefix_id']??null,'is_verified'=>$r['is_verified']??0,'nick_decor_url'=>$r['nick_decor_url']??null,'nick_decor_pos'=>$r['nick_decor_pos']??null];
        echo function_exists('user_render_username_html') ? user_render_username_html($au) : e($r['username']);
      ?> · <?= e($r['created_at']) ?> · <?= e($r['tags']) ?></p></article>
    <?php endforeach; ?>
    <?php if (!$items): ?><p>Пока нет опубликованных ресурсов.</p><?php endif; ?>
  </div>
</div>
<?php if (function_exists('render_recommendations_section')): ?>
<div class="container">
  <?php render_recommendations_section('resources', 8); ?>
  <?php render_recommendations_section('forum', 6); ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
