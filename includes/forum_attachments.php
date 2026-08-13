<?php
// Вложения форума (ссылки на файлы, оформленные как в XenForo, со счётчиком реальных
// переходов). Файлы не загружаются на наш сервер — только ссылка, сам файл остаётся
// там, где его разместил автор поста (совпадает с политикой платформы "публиковать
// только по ссылке", уже принятой для модуля Ресурсы).

function forum_attachment_get_or_create(int $postId, string $url, ?string $filename): int {
  $stmt = db()->prepare('SELECT id FROM forum_attachments WHERE post_id = ? AND url = ?');
  $stmt->execute([$postId, $url]);
  $existing = $stmt->fetchColumn();
  if ($existing) return (int)$existing;

  db()->prepare('INSERT INTO forum_attachments (post_id, url, filename) VALUES (?, ?, ?)')
    ->execute([$postId, $url, $filename]);
  return (int)db()->lastInsertId();
}

function forum_attachment_views_count(int $attachId): int {
  $stmt = db()->prepare('SELECT views_count FROM forum_attachments WHERE id = ?');
  $stmt->execute([$attachId]);
  return (int)($stmt->fetchColumn() ?: 0);
}

// Простое русское склонение для счётчика ("1 просмотр", "2 просмотра", "5 просмотров")
function forum_attachment_views_word(int $n): string {
  $n = abs($n) % 100;
  $n1 = $n % 10;
  if ($n > 10 && $n < 20) return 'просмотров';
  if ($n1 === 1) return 'просмотр';
  if ($n1 >= 2 && $n1 <= 4) return 'просмотра';
  return 'просмотров';
}
