<?php
/**
 * StreamLive Social Bots — VK + Telegram автопостинг.
 * Токены только в зашифрованном виде (secret_crypto.php).
 * Очередь bots_queue + воркер cron/bots_worker.php.
 */

require_once __DIR__ . '/secret_crypto.php';

function bots_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS bots_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
        tg_enabled TINYINT(1) NOT NULL DEFAULT 0,
        vk_enabled TINYINT(1) NOT NULL DEFAULT 0,
        tg_token_enc TEXT NULL,
        vk_token_enc TEXT NULL,
        tg_chat_id VARCHAR(64) NULL,
        vk_group_id VARCHAR(32) NULL,
        tg_parse_mode VARCHAR(16) NOT NULL DEFAULT 'HTML',
        auto_forum TINYINT(1) NOT NULL DEFAULT 0,
        auto_video TINYINT(1) NOT NULL DEFAULT 0,
        auto_rss TINYINT(1) NOT NULL DEFAULT 0,
        auto_ads TINYINT(1) NOT NULL DEFAULT 0,
        default_prefix TEXT NULL,
        default_suffix TEXT NULL,
        updated_at DATETIME NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}

  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS bots_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel ENUM('telegram','vk','both') NOT NULL DEFAULT 'both',
        title VARCHAR(255) NULL,
        body TEXT NOT NULL,
        link VARCHAR(500) NULL,
        image_url VARCHAR(500) NULL,
        source VARCHAR(32) NOT NULL DEFAULT 'manual',
        source_id INT UNSIGNED NULL,
        status ENUM('pending','sent','error','skipped') NOT NULL DEFAULT 'pending',
        error_text VARCHAR(500) NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        KEY idx_status_created (status, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}

  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS bots_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel VARCHAR(16) NOT NULL,
        ok TINYINT(1) NOT NULL DEFAULT 0,
        message VARCHAR(500) NULL,
        raw_response TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}

  // одна строка настроек
  try {
    $n = (int)db()->query('SELECT COUNT(*) FROM bots_settings')->fetchColumn();
    if ($n === 0) {
      db()->exec('INSERT INTO bots_settings (id) VALUES (1)');
    }
  } catch (Throwable $e) {}
}

function bots_settings(): array {
  bots_ensure_schema();
  try {
    $row = db()->query('SELECT * FROM bots_settings WHERE id = 1')->fetch();
    return $row ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function bots_save_settings(array $data): void {
  bots_ensure_schema();
  $s = bots_settings();

  $tgEnabled = !empty($data['tg_enabled']) ? 1 : 0;
  $vkEnabled = !empty($data['vk_enabled']) ? 1 : 0;
  $tgChat = trim((string)($data['tg_chat_id'] ?? ''));
  $vkGroup = preg_replace('/\D+/', '', (string)($data['vk_group_id'] ?? '')) ?: null;
  $parse = in_array(($data['tg_parse_mode'] ?? ''), ['HTML', 'Markdown', 'MarkdownV2'], true)
    ? $data['tg_parse_mode'] : 'HTML';

  $tgEnc = $s['tg_token_enc'] ?? null;
  $vkEnc = $s['vk_token_enc'] ?? null;

  // новый токен только если не пустой и не маска
  $tgNew = trim((string)($data['tg_token'] ?? ''));
  if ($tgNew !== '' && strpos($tgNew, '•') === false) {
    $tgEnc = bots_encrypt_secret($tgNew);
  }
  if (!empty($data['tg_token_clear'])) {
    $tgEnc = null;
  }

  $vkNew = trim((string)($data['vk_token'] ?? ''));
  if ($vkNew !== '' && strpos($vkNew, '•') === false) {
    $vkEnc = bots_encrypt_secret($vkNew);
  }
  if (!empty($data['vk_token_clear'])) {
    $vkEnc = null;
  }

  db()->prepare(
    'UPDATE bots_settings SET
      tg_enabled=?, vk_enabled=?,
      tg_token_enc=?, vk_token_enc=?,
      tg_chat_id=?, vk_group_id=?,
      tg_parse_mode=?,
      auto_forum=?, auto_video=?, auto_rss=?, auto_ads=?,
      default_prefix=?, default_suffix=?,
      updated_at=NOW()
     WHERE id=1'
  )->execute([
    $tgEnabled, $vkEnabled,
    $tgEnc, $vkEnc,
    $tgChat !== '' ? $tgChat : null,
    $vkGroup,
    $parse,
    !empty($data['auto_forum']) ? 1 : 0,
    !empty($data['auto_video']) ? 1 : 0,
    !empty($data['auto_rss']) ? 1 : 0,
    !empty($data['auto_ads']) ? 1 : 0,
    mb_substr(trim((string)($data['default_prefix'] ?? '')), 0, 500),
    mb_substr(trim((string)($data['default_suffix'] ?? '')), 0, 500),
  ]);
}

function bots_tg_token(): string {
  $s = bots_settings();
  if (empty($s['tg_token_enc'])) return '';
  try {
    return bots_decrypt_secret((string)$s['tg_token_enc']);
  } catch (Throwable $e) {
    return '';
  }
}

function bots_vk_token(): string {
  $s = bots_settings();
  if (empty($s['vk_token_enc'])) return '';
  try {
    return bots_decrypt_secret((string)$s['vk_token_enc']);
  } catch (Throwable $e) {
    return '';
  }
}

function bots_http_json(string $url, array $post = [], int $timeout = 20): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'StreamLiveBots/1.0',
  ]);
  if ($post) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  }
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false) {
    return ['ok' => false, 'error' => $err ?: 'curl failed', 'http' => $code];
  }
  $json = json_decode($body, true);
  if (!is_array($json)) {
    return ['ok' => false, 'error' => 'bad json', 'http' => $code, 'raw' => substr($body, 0, 500)];
  }
  $json['_http'] = $code;
  return $json;
}

function bots_log(string $channel, bool $ok, string $message, $raw = null): void {
  try {
    bots_ensure_schema();
    db()->prepare(
      'INSERT INTO bots_log (channel, ok, message, raw_response) VALUES (?,?,?,?)'
    )->execute([
      $channel,
      $ok ? 1 : 0,
      mb_substr($message, 0, 500),
      $raw !== null ? mb_substr(is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE), 0, 4000) : null,
    ]);
  } catch (Throwable $e) {}
}

/** Текст поста с префиксом/суффиксом */
function bots_format_message(string $title, string $body, ?string $link, array $s): string {
  $parts = [];
  $prefix = trim((string)($s['default_prefix'] ?? ''));
  $suffix = trim((string)($s['default_suffix'] ?? ''));
  if ($prefix !== '') $parts[] = $prefix;
  if ($title !== '') $parts[] = $title;
  if ($body !== '') $parts[] = $body;
  if ($link !== '') $parts[] = $link;
  if ($suffix !== '') $parts[] = $suffix;
  return trim(implode("\n\n", $parts));
}

function bots_telegram_send(string $text, ?string $imageUrl = null): array {
  $s = bots_settings();
  $token = bots_tg_token();
  $chat = trim((string)($s['tg_chat_id'] ?? ''));
  if ($token === '' || $chat === '') {
    return ['ok' => false, 'error' => 'Telegram: нет токена или chat_id'];
  }
  $parse = $s['tg_parse_mode'] ?? 'HTML';
  $base = 'https://api.telegram.org/bot' . rawurlencode($token) . '/';

  if ($imageUrl && preg_match('#^https?://#i', $imageUrl)) {
    $res = bots_http_json($base . 'sendPhoto', [
      'chat_id' => $chat,
      'photo' => $imageUrl,
      'caption' => mb_substr($text, 0, 1024),
      'parse_mode' => $parse,
      'disable_web_page_preview' => '0',
    ]);
  } else {
    $res = bots_http_json($base . 'sendMessage', [
      'chat_id' => $chat,
      'text' => mb_substr($text, 0, 4096),
      'parse_mode' => $parse,
      'disable_web_page_preview' => '0',
    ]);
  }
  $ok = !empty($res['ok']);
  bots_log('telegram', $ok, $ok ? 'sent' : ($res['description'] ?? $res['error'] ?? 'fail'), $res);
  return $ok ? ['ok' => true] : ['ok' => false, 'error' => $res['description'] ?? $res['error'] ?? 'TG error'];
}

function bots_vk_send(string $text, ?string $imageUrl = null): array {
  $s = bots_settings();
  $token = bots_vk_token();
  $gid = preg_replace('/\D+/', '', (string)($s['vk_group_id'] ?? ''));
  if ($token === '' || $gid === '') {
    return ['ok' => false, 'error' => 'VK: нет токена или group_id'];
  }
  $ownerId = '-' . $gid;
  $params = [
    'access_token' => $token,
    'v' => '5.199',
    'owner_id' => $ownerId,
    'from_group' => 1,
    'message' => mb_substr($text, 0, 15000),
  ];
  // картинка: упрощённо через attachments url (если публичный URL) — VK часто требует upload;
  // для стабильности шлём текст + ссылку на картинку в тексте
  if ($imageUrl && preg_match('#^https?://#i', $imageUrl) && strpos($text, $imageUrl) === false) {
    $params['message'] = mb_substr($text . "\n\n" . $imageUrl, 0, 15000);
  }
  $res = bots_http_json('https://api.vk.com/method/wall.post', $params);
  $ok = isset($res['response']['post_id']);
  bots_log('vk', $ok, $ok ? 'sent post_id=' . $res['response']['post_id'] : ($res['error']['error_msg'] ?? $res['error'] ?? 'fail'), $res);
  return $ok ? ['ok' => true, 'post_id' => $res['response']['post_id']] : ['ok' => false, 'error' => $res['error']['error_msg'] ?? $res['error'] ?? 'VK error'];
}

/** Поставить в очередь */
function bots_enqueue(string $channel, string $body, array $opts = []): int {
  bots_ensure_schema();
  if (!in_array($channel, ['telegram', 'vk', 'both'], true)) $channel = 'both';
  db()->prepare(
    'INSERT INTO bots_queue (channel, title, body, link, image_url, source, source_id, status)
     VALUES (?,?,?,?,?,?,?,\'pending\')'
  )->execute([
    $channel,
    mb_substr((string)($opts['title'] ?? ''), 0, 255) ?: null,
    $body,
    mb_substr((string)($opts['link'] ?? ''), 0, 500) ?: null,
    mb_substr((string)($opts['image_url'] ?? ''), 0, 500) ?: null,
    mb_substr((string)($opts['source'] ?? 'manual'), 0, 32),
    isset($opts['source_id']) ? (int)$opts['source_id'] : null,
  ]);
  return (int)db()->lastInsertId();
}

/** Обработать до $limit задач из очереди */
function bots_process_queue(int $limit = 10): array {
  bots_ensure_schema();
  $s = bots_settings();
  $st = db()->prepare(
    "SELECT * FROM bots_queue WHERE status = 'pending' AND attempts < 5 ORDER BY id ASC LIMIT " . (int)$limit
  );
  $st->execute();
  $rows = $st->fetchAll() ?: [];
  $stats = ['done' => 0, 'error' => 0];

  foreach ($rows as $row) {
    $id = (int)$row['id'];
    db()->prepare('UPDATE bots_queue SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);

    $text = bots_format_message(
      (string)($row['title'] ?? ''),
      (string)$row['body'],
      $row['link'] ?? null,
      $s
    );
    $img = $row['image_url'] ?? null;
    $ch = $row['channel'];
    $errs = [];
    $anyOk = false;

    if (($ch === 'telegram' || $ch === 'both') && !empty($s['tg_enabled'])) {
      $r = bots_telegram_send($text, $img);
      if (!empty($r['ok'])) $anyOk = true; else $errs[] = 'TG: ' . ($r['error'] ?? 'fail');
    }
    if (($ch === 'vk' || $ch === 'both') && !empty($s['vk_enabled'])) {
      $r = bots_vk_send($text, $img);
      if (!empty($r['ok'])) $anyOk = true; else $errs[] = 'VK: ' . ($r['error'] ?? 'fail');
    }

    if ($anyOk && !$errs) {
      db()->prepare("UPDATE bots_queue SET status='sent', sent_at=NOW(), error_text=NULL WHERE id=?")->execute([$id]);
      $stats['done']++;
    } elseif ($anyOk) {
      db()->prepare("UPDATE bots_queue SET status='sent', sent_at=NOW(), error_text=? WHERE id=?")
        ->execute([mb_substr(implode('; ', $errs), 0, 500), $id]);
      $stats['done']++;
    } else {
      $err = $errs ? implode('; ', $errs) : 'nothing enabled';
      db()->prepare("UPDATE bots_queue SET status='error', error_text=? WHERE id=?")
        ->execute([mb_substr($err, 0, 500), $id]);
      $stats['error']++;
    }
  }
  return $stats;
}

/** Хук: новая тема форума */
function bots_notify_forum_thread(int $threadId, string $title, string $url): void {
  $s = bots_settings();
  if (empty($s['auto_forum'])) return;
  if (empty($s['tg_enabled']) && empty($s['vk_enabled'])) return;
  bots_enqueue('both', $title, [
    'title' => '📝 Новая тема на форуме',
    'link' => $url,
    'source' => 'forum',
    'source_id' => $threadId,
  ]);
}

/** Хук: новое видео */
function bots_notify_video(int $videoId, string $title, string $url, ?string $thumb = null): void {
  $s = bots_settings();
  if (empty($s['auto_video'])) return;
  if (empty($s['tg_enabled']) && empty($s['vk_enabled'])) return;
  bots_enqueue('both', $title, [
    'title' => '🎬 Новое видео',
    'link' => $url,
    'image_url' => $thumb,
    'source' => 'video',
    'source_id' => $videoId,
  ]);
}

/** Тест соединения */
function bots_test_telegram(): array {
  $token = bots_tg_token();
  if ($token === '') return ['ok' => false, 'error' => 'Токен не задан'];
  $res = bots_http_json('https://api.telegram.org/bot' . rawurlencode($token) . '/getMe');
  if (!empty($res['ok'])) {
    $u = $res['result']['username'] ?? '';
    return ['ok' => true, 'username' => $u, 'id' => $res['result']['id'] ?? null];
  }
  return ['ok' => false, 'error' => $res['description'] ?? 'getMe failed'];
}

function bots_test_vk(): array {
  $token = bots_vk_token();
  $s = bots_settings();
  $gid = preg_replace('/\D+/', '', (string)($s['vk_group_id'] ?? ''));
  if ($token === '') return ['ok' => false, 'error' => 'Токен не задан'];
  $params = [
    'access_token' => $token,
    'v' => '5.199',
  ];
  if ($gid !== '') $params['group_id'] = $gid;
  $res = bots_http_json('https://api.vk.com/method/groups.getById', $params);
  if (isset($res['response'][0]['id']) || isset($res['response']['groups'][0]['id'])) {
    $g = $res['response']['groups'][0] ?? $res['response'][0];
    return ['ok' => true, 'name' => $g['name'] ?? '', 'id' => $g['id'] ?? null];
  }
  $res2 = bots_http_json('https://api.vk.com/method/users.get', [
    'access_token' => $token,
    'v' => '5.199',
  ]);
  if (isset($res2['response'][0]['id'])) {
    return ['ok' => true, 'name' => 'user ' . $res2['response'][0]['id'], 'id' => $res2['response'][0]['id']];
  }
  return ['ok' => false, 'error' => $res['error']['error_msg'] ?? $res2['error']['error_msg'] ?? 'VK API error'];
}
