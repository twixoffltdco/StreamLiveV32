<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/forum_engine.php';
forum_engine_ensure();
$q = trim((string)($_GET['q'] ?? ''));
$results = $q !== '' ? forum_search($q, 40) : [];
$pageTitle = 'Поиск по форуму';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:800px">
  <h1>Поиск по форуму</h1>
  <form method="GET" style="display:flex;gap:8px;margin:16px 0">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Тема или текст…" style="flex:1;padding:10px;border-radius:8px;border:1px solid #333;background:#12121a;color:#eee">
    <button class="btn btn-primary" type="submit">Найти</button>
  </form>
  <?php if ($q !== '' && !$results): ?><p style="color:var(--text-dim)">Ничего не найдено</p><?php endif; ?>
  <?php foreach ($results as $r): ?>
    <a href="<?= e($r['url']) ?>" style="display:block;padding:12px;margin:8px 0;border-radius:10px;background:#14141c;color:inherit;text-decoration:none">
      <div style="font-size:11px;opacity:.6"><?= $r['type'] === 'thread' ? 'Тема' : 'Сообщение' ?></div>
      <div style="font-weight:600"><?= e($r['title']) ?></div>
      <div style="font-size:13px;color:var(--text-dim)"><?= e($r['meta']) ?></div>
    </a>
  <?php endforeach; ?>
  <p style="margin-top:16px"><a href="/forum.php" style="color:var(--accent-2)">← Форум</a></p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
