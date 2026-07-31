<?php
// Автодеплой сайта при пуше в GitHub — как обычный CI/CD, только без SSH и без git на
// сервере (бесплатный шаред-хостинг обычно не даёт ни того, ни другого). Вместо `git pull`
// скачиваем свежий архив ветки через codeload.github.com и аккуратно раскладываем поверх
// живого сайта, СТРОГО не трогая:
//   - config/config.php      — тут пароль от БД, перезапись убьёт сайт наглухо
//   - storage/                — загруженные пользователями файлы
//   - services_data/          — задеплоенные пользователями сервисы (это их данные, не код сайта)
//   - .htaccess                — если у тебя на хостинге в него внесены ручные правки
//
// Флоу: скачали zip во временную папку → распаковали во ВРЕМЕННУЮ папку (не поверх сайта
// напрямую) → только если распаковка прошла полностью и без ошибок — копируем поверх
// живых файлов. Если на любом из шагов ошибка — сайт остаётся как был, ничего не тронуто.

const SELF_DEPLOY_EXCLUDED_PATHS = [
  'config/config.php',
  'storage',
  'services_data',
  '.htaccess',
  '.git',
];

function self_deploy_lock_path(): string {
  return sys_get_temp_dir() . '/streamlive_self_deploy.lock';
}

// Блокировка от параллельных деплоев (например, два быстрых пуша подряд) — держим лок-файл,
// пока идёт раскладка. Если лок старше 5 минут — считаем его протухшим (процесс мог упасть)
// и снимаем сами, чтобы не заблокировать деплой навсегда одной неудачной попыткой.
function self_deploy_acquire_lock(): bool {
  $path = self_deploy_lock_path();
  if (file_exists($path) && (time() - filemtime($path)) < 300) return false;
  return file_put_contents($path, (string)time()) !== false;
}

function self_deploy_release_lock(): void {
  @unlink(self_deploy_lock_path());
}

// Рекурсивно удаляет временную папку после деплоя (успешного или нет) — не оставляем мусор.
function self_deploy_rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir . '/' . $item;
    is_dir($path) ? self_deploy_rrmdir($path) : @unlink($path);
  }
  @rmdir($dir);
}

// Копирует $src поверх $dst рекурсивно, пропуская пути из SELF_DEPLOY_EXCLUDED_PATHS
// (проверяются относительно корня проекта). Возвращает число обновлённых файлов.
function self_deploy_copy_tree(string $src, string $dst, string $relativeBase = ''): int {
  $count = 0;
  foreach (scandir($src) as $item) {
    if ($item === '.' || $item === '..') continue;
    $srcPath = $src . '/' . $item;
    $dstPath = $dst . '/' . $item;
    $relPath = $relativeBase === '' ? $item : $relativeBase . '/' . $item;

    if (in_array($relPath, SELF_DEPLOY_EXCLUDED_PATHS, true)) continue;

    if (is_dir($srcPath)) {
      if (!is_dir($dstPath)) @mkdir($dstPath, 0755, true);
      $count += self_deploy_copy_tree($srcPath, $dstPath, $relPath);
    } else {
      if (@copy($srcPath, $dstPath)) $count++;
    }
  }
  return $count;
}

// Главная функция — вызывается и из вебхука (автоматически при пуше), и из админки
// (кнопка "Задеплоить сейчас" для ручного запуска/повторной попытки).
function self_deploy_run(string $branch, ?string $commitSha = null, ?string $commitMessage = null): array {
  $startedAt = microtime(true);

  if (!class_exists('ZipArchive')) {
    return self_deploy_log_and_return(false, 'На хостинге нет расширения PHP ZipArchive — автодеплой физически невозможен. Попроси у хостера включить zip в PHP.', null, $startedAt, $commitSha, $commitMessage);
  }
  if (!self_deploy_acquire_lock()) {
    return self_deploy_log_and_return(false, 'Другой деплой уже идёт (или завис недавно) — пропускаю, чтобы не раскладывать два архива одновременно.', null, $startedAt, $commitSha, $commitMessage);
  }

  try {
    $owner = get_setting('self_deploy_repo_owner', '');
    $repo = get_setting('self_deploy_repo_name', '');
    if (!$owner || !$repo) {
      return self_deploy_log_and_return(false, 'Репозиторий не настроен — задай владельца и имя репозитория в /admin/auto_deploy.php.', null, $startedAt, $commitSha, $commitMessage);
    }

    $zipUrl = "https://codeload.github.com/{$owner}/{$repo}/zip/refs/heads/" . rawurlencode($branch);
    $actualUrl = (defined('NETWORK_PROXY_URL') && NETWORK_PROXY_URL !== '')
      ? NETWORK_PROXY_URL . '?url=' . urlencode($zipUrl)
      : $zipUrl;

    $tmpDir = sys_get_temp_dir() . '/streamlive_deploy_' . uniqid();
    @mkdir($tmpDir, 0755, true);
    $zipPath = $tmpDir . '/repo.zip';

    $ch = curl_init($actualUrl);
    $fp = fopen($zipPath, 'w');
    curl_setopt_array($ch, [
      CURLOPT_FILE => $fp,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_USERAGENT => 'StreamLive-SelfDeploy/1.0',
    ]);
    if (defined('NETWORK_PROXY_SECRET') && NETWORK_PROXY_SECRET !== '') {
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Proxy-Secret: ' . NETWORK_PROXY_SECRET]);
    }
    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode !== 200 || filesize($zipPath) < 50) {
      self_deploy_rrmdir($tmpDir);
      return self_deploy_log_and_return(false, "Не удалось скачать архив с GitHub (HTTP {$httpCode}). Если хостинг режет исходящие запросы — настрой NETWORK_PROXY_URL (см. README_NETWORK_PROXY.md).", null, $startedAt, $commitSha, $commitMessage);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
      self_deploy_rrmdir($tmpDir);
      return self_deploy_log_and_return(false, 'Скачанный архив повреждён, пустой или не открылся как zip (возможно, GitHub вернул страницу ошибки вместо архива — проверь владельца/имя репозитория/ветку).', null, $startedAt, $commitSha, $commitMessage);
    }
    $extractDir = $tmpDir . '/extracted';
    @mkdir($extractDir, 0755, true);
    $zip->extractTo($extractDir);
    $zip->close();

    // GitHub кладёт всё внутрь одной вложенной папки вида "repo-branch/" — находим её.
    $entries = array_values(array_diff(scandir($extractDir), ['.', '..']));
    if (count($entries) !== 1 || !is_dir($extractDir . '/' . $entries[0])) {
      self_deploy_rrmdir($tmpDir);
      return self_deploy_log_and_return(false, 'Неожиданная структура архива с GitHub — раскладку прервал, ничего не тронул.', null, $startedAt, $commitSha, $commitMessage);
    }
    $sourceRoot = $extractDir . '/' . $entries[0];

    // Только теперь, когда всё скачано и распаковано УСПЕШНО во временную папку —
    // копируем поверх живого сайта. Если сайт упадёт прямо во время копирования (маловероятно,
    // но теоретически возможно при обрыве) — это единственный рискованный момент во всём
    // процессе, и он длится доли секунды на файл, а не всю скачку.
    $projectRoot = dirname(__DIR__);
    $filesUpdated = self_deploy_copy_tree($sourceRoot, $projectRoot);

    self_deploy_rrmdir($tmpDir);
    return self_deploy_log_and_return(true, null, $filesUpdated, $startedAt, $commitSha, $commitMessage);
  } finally {
    self_deploy_release_lock();
  }
}

function self_deploy_log_and_return(bool $success, ?string $error, ?int $filesUpdated, float $startedAt, ?string $commitSha, ?string $commitMessage): array {
  $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
  try {
    db()->prepare(
      'INSERT INTO self_deploy_log (commit_sha, commit_message, status, error_message, files_updated, duration_ms) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$commitSha, $commitMessage, $success ? 'success' : 'failed', $error, $filesUpdated, $durationMs]);
  } catch (\Throwable $e) { /* лог не критичен для самого деплоя */ }
  return ['success' => $success, 'error' => $error, 'files_updated' => $filesUpdated, 'duration_ms' => $durationMs];
}
