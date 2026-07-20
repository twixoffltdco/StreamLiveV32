<?php
// Минимальная обёртка над GitHub REST API v3 для конструктора сервисов.
// Использует Personal Access Token пользователя (classic или fine-grained,
// достаточно прав "repo" на чтение — мы только читаем и скачиваем архив кода).

function github_api_get(string $token, string $path): ?array {
  $targetUrl = 'https://api.github.com' . $path;
  $actualUrl = (defined('NETWORK_PROXY_URL') && NETWORK_PROXY_URL !== '')
    ? NETWORK_PROXY_URL . '?url=' . urlencode($targetUrl)
    : $targetUrl;

  $headers = [
    'Authorization: Bearer ' . $token,
    'Accept: application/vnd.github+json',
    'X-GitHub-Api-Version: 2022-11-28',
    'User-Agent: StreamLive-VK-Constructor',
  ];
  if (defined('NETWORK_PROXY_SECRET') && NETWORK_PROXY_SECRET !== '') {
    $headers[] = 'X-Proxy-Secret: ' . NETWORK_PROXY_SECRET;
  }

  $ch = curl_init($actualUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => $headers,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false || $code >= 400) return null;
  $json = json_decode($body, true);
  return is_array($json) ? $json : null;
}

// Возвращает данные аккаунта GitHub, если токен валиден, иначе null
function github_verify_token(string $token): ?array {
  return github_api_get($token, '/user');
}

// Список репозиториев, доступных этому токену (свои + те, куда есть доступ), новые сверху
function github_list_repos(string $token): array {
  $repos = github_api_get($token, '/user/repos?per_page=100&sort=updated&affiliation=owner,collaborator');
  return $repos ?? [];
}

// Скачивает zip-архив ветки репозитория во временный файл. Возвращает путь к файлу или null.
function github_download_repo_zip(string $owner, string $repo, string $branch): ?string {
  $targetUrl = "https://codeload.github.com/{$owner}/{$repo}/zip/refs/heads/" . rawurlencode($branch);
  $actualUrl = (defined('NETWORK_PROXY_URL') && NETWORK_PROXY_URL !== '')
    ? NETWORK_PROXY_URL . '?url=' . urlencode($targetUrl)
    : $targetUrl;

  $tmpFile = tempnam(sys_get_temp_dir(), 'ghrepo_') . '.zip';
  $fp = fopen($tmpFile, 'w');
  if (!$fp) return null;

  $headers = [];
  if (defined('NETWORK_PROXY_SECRET') && NETWORK_PROXY_SECRET !== '') {
    $headers[] = 'X-Proxy-Secret: ' . NETWORK_PROXY_SECRET;
  }

  $ch = curl_init($actualUrl);
  curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_USERAGENT      => 'StreamLive-VK-Constructor',
    CURLOPT_HTTPHEADER     => $headers,
  ]);
  $ok = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($fp);

  if (!$ok || $code >= 400) { @unlink($tmpFile); return null; }
  return $tmpFile;
}
