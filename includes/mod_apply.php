<?php
/** Заявки «Стать модератором» */
function mod_apply_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS moderator_applications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        reason TEXT NOT NULL,
        experience TEXT NULL,
        contacts VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        reviewed_by INT UNSIGNED NULL,
        reviewed_at DATETIME NULL,
        admin_note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id),
        KEY idx_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function mod_apply_pending_for(int $userId): ?array {
  mod_apply_ensure();
  try {
    $st = db()->prepare("SELECT * FROM moderator_applications WHERE user_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function mod_apply_submit(int $userId, string $reason, string $experience = '', string $contacts = ''): array {
  mod_apply_ensure();
  $reason = trim(mb_substr($reason, 0, 2000));
  if ($reason === '' || mb_strlen($reason) < 20) {
    return ['ok' => false, 'error' => 'Опишите, почему хотите стать модератором (минимум 20 символов)'];
  }
  try {
    $u = db()->prepare('SELECT id, role, is_banned FROM users WHERE id=?');
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return ['ok' => false, 'error' => 'Пользователь не найден'];
    if (!empty($user['is_banned'])) return ['ok' => false, 'error' => 'Аккаунт заблокирован'];
    if (in_array(($user['role'] ?? ''), ['admin', 'moderator'], true)) {
      return ['ok' => false, 'error' => 'Вы уже модератор или администратор'];
    }
    if (mod_apply_pending_for($userId)) {
      return ['ok' => false, 'error' => 'Заявка уже на рассмотрении'];
    }
    db()->prepare(
      'INSERT INTO moderator_applications (user_id, reason, experience, contacts, status) VALUES (?,?,?,?,?)'
    )->execute([$userId, $reason, mb_substr($experience, 0, 2000), mb_substr($contacts, 0, 255), 'pending']);
    return ['ok' => true, 'error' => ''];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Не удалось отправить: ' . $e->getMessage()];
  }
}
