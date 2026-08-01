<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_auth.php';
header('Content-Type: application/json; charset=utf-8');

$apiState = api_guard();
if (!$apiState['ok']) exit;

$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

if ($categoryId > 0) {
    // Получить темы в категории
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $stmt = db()->prepare(
        "SELECT t.id, t.title, t.created_at, t.last_post_at,
                u.username AS author_username,
                (SELECT COUNT(*) FROM forum_posts WHERE thread_id = t.id AND is_deleted = 0) AS post_count
         FROM forum_threads t
         JOIN users u ON u.id = t.user_id
         WHERE t.category_id = ? AND t.is_deleted = 0
         ORDER BY t.last_post_at DESC LIMIT ?"
    );
    $stmt->execute([$categoryId, $limit]);
    $threads = $stmt->fetchAll();

    echo json_encode([
        'ok' => true,
        'authenticated' => $apiState['authenticated'],
        'warning' => $apiState['warning'],
        'category_id' => $categoryId,
        'threads' => $threads,
    ]);
} else {
    // Список категорий со статистикой и последней темой
    $categories = db()->query(
        "SELECT fc.id, fc.title, fc.description,
                (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = fc.id AND t.is_deleted = 0) AS thread_count,
                (SELECT COUNT(*) FROM forum_posts p
                 JOIN forum_threads t ON t.id = p.thread_id
                 WHERE t.category_id = fc.id AND t.is_deleted = 0 AND p.is_deleted = 0) AS post_count
         FROM forum_categories fc
         ORDER BY fc.sort_order ASC, fc.id ASC"
    )->fetchAll();

    // Добавляем последнюю тему для каждой категории
    foreach ($categories as &$cat) {
        $stmt = db()->prepare(
            "SELECT t.id, t.title, t.last_post_at, u.username
             FROM forum_threads t
             JOIN users u ON u.id = t.user_id
             WHERE t.category_id = ? AND t.is_deleted = 0
             ORDER BY t.last_post_at DESC LIMIT 1"
        );
        $stmt->execute([$cat['id']]);
        $cat['last_thread'] = $stmt->fetch();
    }
    unset($cat);

    echo json_encode([
        'ok' => true,
        'authenticated' => $apiState['authenticated'],
        'warning' => $apiState['warning'],
        'categories' => $categories,
    ]);
}