<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

$categoryId = (int)($_GET['category_id'] ?? $_POST['category_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM forum_categories WHERE id = ?');
$stmt->execute([$categoryId]);
$category = $stmt->fetch();

if (!$category) {
  http_response_code(404);
  $pageTitle = 'Категория не найдена';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Категория не найдена</h2><a href="/forum.php" class="btn btn-primary" style="margin-top:14px">На форум</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $title = trim(mb_substr($_POST['title'] ?? '', 0, 200));
  $message = trim(mb_substr($_POST['message'] ?? '', 0, 2000000));

  if ($title !== '' && $message !== '') {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO forum_threads (category_id, user_id, title) VALUES (?, ?, ?)');
    $stmt->execute([$categoryId, $__user['id'], $title]);
    $threadId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO forum_posts (thread_id, user_id, message) VALUES (?, ?, ?)')
      ->execute([$threadId, $__user['id'], $message]);
    $pdo->commit();
    
    if (is_file(__DIR__ . '/includes/social_bots.php')) {
      require_once __DIR__ . '/includes/social_bots.php';
      try {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $__botUrl = ($host !== '' ? $scheme . '://' . $host : '') . '/forum_thread?id=' . (int)$threadId;
        bots_notify_forum_thread((int)$threadId, (string)$title, $__botUrl);
      } catch (Throwable $e) {}
    }

    redirect('/forum_thread.php?id=' . $threadId);
  }
  flash_set('error', 'Укажите заголовок темы и текст сообщения');
}

$pageTitle = 'Новая тема — ' . $category['title'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <p style="margin:20px 0 4px"><a href="/forum_category.php?id=<?= (int)$categoryId ?>" style="color:var(--accent-2);font-size:13px">← <?= e($category['title']) ?></a></p>
  <h1 style="margin:0 0 20px">Новая тема</h1>

  <form method="POST" class="form-card form-wide">
    <?= csrf_field() ?>
    <input type="hidden" name="category_id" value="<?= (int)$categoryId ?>">
    <label>Заголовок темы</label>
    <input type="text" name="title" maxlength="200" required>
    <label>Сообщение</label>
    <?php include __DIR__ . '/includes/bbcode_toolbar.php'; ?>
    <textarea id="bb-editor" name="message" rows="10" maxlength="2000000" required></textarea>
    <button class="btn btn-primary" style="margin-top:16px" type="submit">Создать тему</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
