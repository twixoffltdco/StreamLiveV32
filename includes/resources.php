<?php
require_once __DIR__ . '/functions.php';

function resources_ensure_table(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    slug VARCHAR(180) UNIQUE NOT NULL,
    title VARCHAR(180) NOT NULL,
    summary VARCHAR(500) DEFAULT NULL,
    readme MEDIUMTEXT,
    external_url VARCHAR(1000) NOT NULL,
    download_url VARCHAR(1000) DEFAULT NULL,
    repo_full_name VARCHAR(255) DEFAULT NULL,
    repo_stars INT DEFAULT NULL,
    repo_language VARCHAR(80) DEFAULT NULL,
    tags VARCHAR(500) DEFAULT NULL,
    status ENUM('published','hidden') NOT NULL DEFAULT 'published',
    hidden_reason VARCHAR(500) DEFAULT NULL,
    views BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_created (status, created_at),
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $columns = [
    'summary VARCHAR(500) DEFAULT NULL',
    'readme MEDIUMTEXT',
    'external_url VARCHAR(1000) DEFAULT NULL',
    'download_url VARCHAR(1000) DEFAULT NULL',
    'repo_full_name VARCHAR(255) DEFAULT NULL',
    'repo_stars INT DEFAULT NULL',
    'repo_language VARCHAR(80) DEFAULT NULL',
    'tags VARCHAR(500) DEFAULT NULL',
    "status ENUM('published','hidden') NOT NULL DEFAULT 'published'",
    'hidden_reason VARCHAR(500) DEFAULT NULL',
    'views BIGINT NOT NULL DEFAULT 0',
    'created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
    'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  ];
  foreach ($columns as $def) {
    $col = strtok($def, ' ');
    try { if (!table_column_exists('resources', $col)) db()->exec('ALTER TABLE resources ADD COLUMN ' . $def); } catch (Throwable $e) {}
  }
}

function resource_slug(string $title): string { return slugify($title); }
function resource_url(array $r): string { return '/resource.php?slug=' . urlencode($r['slug']); }
function resource_can_moderate(?array $u): bool { return $u && in_array($u['role'], ['moderator','admin'], true); }

function resources_list(bool $includeHidden = false, int $limit = 50): array {
  resources_ensure_table();
  $sql = "SELECT r.*, u.username FROM resources r JOIN users u ON u.id = r.user_id" . ($includeHidden ? '' : " WHERE r.status = 'published'") . " ORDER BY r.created_at DESC LIMIT " . max(1, min(200, $limit));
  return db()->query($sql)->fetchAll();
}

function resource_find(string $slug, bool $includeHidden = false): ?array {
  resources_ensure_table();
  $sql = "SELECT r.*, u.username FROM resources r JOIN users u ON u.id = r.user_id WHERE r.slug = ?" . ($includeHidden ? '' : " AND r.status = 'published'") . " LIMIT 1";
  $stmt = db()->prepare($sql); $stmt->execute([$slug]);
  return $stmt->fetch() ?: null;
}


function resource_parse_github_repo(string $url): ?array {
  $parts = parse_url($url);
  if (!$parts || strtolower($parts['host'] ?? '') !== 'github.com') return null;
  $path = trim((string)($parts['path'] ?? ''), '/');
  $bits = explode('/', $path);
  if (count($bits) < 2) return null;
  if (!preg_match('/^[\w.-]+$/', $bits[0]) || !preg_match('/^[\w.-]+$/', $bits[1])) return null;
  return [$bits[0], preg_replace('/\.git$/', '', $bits[1])];
}

function resource_fetch_github_meta(string $url): array {
  $repo = resource_parse_github_repo($url);
  if (!$repo) return [null, null, null];
  [$owner, $name] = $repo;
  $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: StreamLiveResourceParser\r\nAccept: application/vnd.github+json\r\n"]]);
  $json = @file_get_contents('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name), false, $ctx);
  $data = $json ? json_decode($json, true) : null;
  return [$owner . '/' . $name, isset($data['stargazers_count']) ? (int)$data['stargazers_count'] : null, $data['language'] ?? null];
}
