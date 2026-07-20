<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rss.php';

$categoryId = (int)($_GET['category'] ?? 0);
$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

$pdo = db();

// Основной запрос с подзапросом на первый пост
if ($categoryId) {
    $stmt = $pdo->prepare(
        'SELECT t.*, u.username,
            (SELECT message FROM forum_posts
             WHERE thread_id = t.id AND is_deleted = 0
             ORDER BY created_at ASC LIMIT 1) AS first_post_message
         FROM forum_threads t
         JOIN users u ON u.id = t.user_id
         WHERE t.category_id = ? AND t.is_deleted = 0
         ORDER BY t.id DESC LIMIT 100'
    );
    $stmt->execute([$categoryId]);

    $catStmt = $pdo->prepare('SELECT title FROM forum_categories WHERE id = ?');
    $catStmt->execute([$categoryId]);
    $catTitle = $catStmt->fetchColumn() ?: 'Форум';
} else {
    $stmt = $pdo->query(
        'SELECT t.*, u.username,
            (SELECT message FROM forum_posts
             WHERE thread_id = t.id AND is_deleted = 0
             ORDER BY created_at ASC LIMIT 1) AS first_post_message
         FROM forum_threads t
         JOIN users u ON u.id = t.user_id
         WHERE t.is_deleted = 0
         ORDER BY t.id DESC LIMIT 100'
    );
    $catTitle = 'Все разделы';
}

$threads = $stmt->fetchAll();

// Формируем элементы RSS
$items = array_map(function ($t) use ($base, $siteName) {
    // Берём текст первого поста или заголовок как запасной
    $raw = $t['first_post_message'] ?? $t['title'];
    // Обрезаем до 50 символов (с учётом многобайтовых)
    $short = mb_substr($raw, 0, 100);
    if (mb_strlen($raw) > 100) {
        $short .= '…'; // многоточие для красоты
    }
    // Добавляем призыв к действию
    $description = $short . ' Читать на ' . $siteName;

    return [
        'title'    => $t['title'],
        'link'     => $base . '/forum_thread.php?id=' . (int)$t['id'],
        'description' => $description,
        'pub_date' => $t['created_at'],
    ];
}, $threads);

// Отдаём RSS
render_rss_xml(
    (defined('SITE_NAME') ? SITE_NAME : 'StreamLive') . ' — Форум: ' . $catTitle,
    $base . '/forum.php',
    'Новые темы форума ' . $catTitle,
    $items
);