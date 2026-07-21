<?php

function deployed_services_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;

  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS deployed_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(500) DEFAULT NULL,
        repo_full_name VARCHAR(191) NOT NULL,
        branch VARCHAR(100) NOT NULL DEFAULT "main",
        slug VARCHAR(180) NOT NULL UNIQUE,
        status ENUM("deploying","live","failed") NOT NULL DEFAULT "deploying",
        is_public TINYINT(1) NOT NULL DEFAULT 1,
        suspended TINYINT(1) NOT NULL DEFAULT 0,
        suspended_reason VARCHAR(255) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        deployed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_public (is_public, status, suspended)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  } catch (\Throwable $e) { error_log('[services schema] ' . $e->getMessage()); }

  foreach ([
    'is_public' => 'ALTER TABLE deployed_services ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1',
    'suspended' => 'ALTER TABLE deployed_services ADD COLUMN suspended TINYINT(1) NOT NULL DEFAULT 0',
    'suspended_reason' => 'ALTER TABLE deployed_services ADD COLUMN suspended_reason VARCHAR(255) DEFAULT NULL',
  ] as $column => $sql) {
    try { if (!table_column_exists('deployed_services', $column)) db()->exec($sql); } catch (\Throwable $e) {}
  }
}

function deployed_service_limit_for(array $user): int {
  return !empty($user['is_verified']) ? PHP_INT_MAX : 1;
}

function deployed_service_active_count(int $userId): int {
  deployed_services_ensure_schema();
  $stmt = db()->prepare("SELECT COUNT(*) FROM deployed_services WHERE user_id = ? AND status = 'live' AND suspended = 0");
  $stmt->execute([$userId]);
  return (int)$stmt->fetchColumn();
}

function deployed_service_preview_url(string $slug): string {
  return '/services_data/' . rawurlencode($slug) . '/index.html';
}

function deployed_service_autostop(array $service): array {
  if (!empty($service['owner_verified']) || !empty($service['is_verified']) || !empty($service['suspended'])) return $service;
  $lastActive = $service['last_active_date'] ?? null;
  if ($lastActive && strtotime((string)$lastActive) >= strtotime('-30 days')) return $service;

  $reason = 'Автостоп: владелец не заходил на платформу 30+ дней. Продлите услугу входом на платформу.';
  db()->prepare('UPDATE deployed_services SET suspended = 1, suspended_reason = ? WHERE id = ?')->execute([$reason, (int)$service['id']]);
  $service['suspended'] = 1;
  $service['suspended_reason'] = $reason;
  return $service;
}

function banned_user_notice(array $user): string {
  if (empty($user['is_banned'])) return '';
  return '<div class="blocked-author-notice"><b>Пользователь заблокирован</b><span>Автор этого сообщения заблокирован администрацией платформы. Контент оставлен только как часть истории обсуждения.</span></div>';
}
