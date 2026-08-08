<?php
/**
 * Универсальный OAuth-клиент (VK, Yandex, Google, GitHub и др.)
 * Провайдеры — таблица oauth_providers (админка /admin/oauth.php).
 */
require_once __DIR__ . '/db.php';

class OAuthClient {
  public array $provider;

  public function __construct(array $provider) {
    $this->provider = $provider;
  }

  public static function find(string $name): ?OAuthClient {
    try {
      $stmt = db()->prepare('SELECT * FROM oauth_providers WHERE name = ? AND enabled = 1');
      $stmt->execute([$name]);
      $row = $stmt->fetch();
      return $row ? new self($row) : null;
    } catch (Throwable $e) {
      return null;
    }
  }

  public static function findAny(string $name): ?OAuthClient {
    try {
      $stmt = db()->prepare('SELECT * FROM oauth_providers WHERE name = ?');
      $stmt->execute([$name]);
      $row = $stmt->fetch();
      return $row ? new self($row) : null;
    } catch (Throwable $e) {
      return null;
    }
  }

  public function redirectUri(): string {
    return rtrim(SITE_URL, '/') . '/auth/oauth_callback.php?provider=' . rawurlencode((string)$this->provider['name']);
  }

  public function authorizeUrl(): string {
    $p = $this->provider;
    $name = (string)$p['name'];
    $params = [
      'client_id'     => $p['client_id'],
      'redirect_uri'  => $this->redirectUri(),
      'response_type' => 'code',
      'scope'         => (string)($p['scope'] ?? ''),
      'state'         => $_SESSION['oauth_state'] = bin2hex(random_bytes(16)),
    ];
    // GitHub
    if ($name === 'github') {
      $params['allow_signup'] = 'true';
    }
    // Yandex
    if ($name === 'yandex') {
      $params['force_confirm'] = 'false';
    }
    // VK ID / VK
    if ($name === 'vk' || $name === 'vkontakte') {
      if (empty($params['scope'])) $params['scope'] = 'email';
      $params['v'] = '5.199';
    }
    // Google
    if ($name === 'google') {
      $params['access_type'] = 'online';
      $params['prompt'] = 'select_account';
    }
    $base = rtrim((string)$p['auth_url'], '?&');
    return $base . (strpos($base, '?') !== false ? '&' : '?') . http_build_query($params);
  }

  public function exchangeCode(string $code): ?array {
    $p = $this->provider;
    $name = (string)$p['name'];
    $params = [
      'client_id'     => $p['client_id'],
      'client_secret' => $p['client_secret'],
      'grant_type'    => 'authorization_code',
      'code'          => $code,
      'redirect_uri'  => $this->redirectUri(),
    ];
    $headers = ['Accept: application/json'];
    // GitHub требует Accept application/json
    if ($name === 'github') {
      $headers[] = 'Accept: application/json';
    }
    $resp = $this->httpPost((string)$p['token_url'], $params, $headers);
    $data = json_decode($resp, true);
    // VK и некоторые отдают query-string
    if (!is_array($data) || empty($data['access_token'])) {
      $parsed = [];
      parse_str($resp, $parsed);
      if (!empty($parsed['access_token'])) {
        $data = $parsed;
      }
    }
    if (!is_array($data) || empty($data['access_token'])) {
      return null;
    }
    return $data;
  }

  public function fetchProfile(string $accessToken): ?array {
    $p = $this->provider;
    $name = (string)$p['name'];
    $url = (string)$p['profile_url'];
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $accessToken];

    // VK: токен в query, метод users.get
    if ($name === 'vk' || $name === 'vkontakte') {
      $url = 'https://api.vk.com/method/users.get?' . http_build_query([
        'access_token' => $accessToken,
        'fields'       => 'photo_200,email',
        'v'            => '5.199',
      ]);
      $headers = ['Accept: application/json'];
    }

    // Yandex
    if ($name === 'yandex') {
      $url = $url !== '' ? $url : 'https://login.yandex.ru/info?format=json';
      $headers = ['Accept: application/json', 'Authorization: OAuth ' . $accessToken];
    }

    // GitHub
    if ($name === 'github') {
      $url = 'https://api.github.com/user';
      $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $accessToken,
        'User-Agent: StreamLive-OAuth',
        'X-GitHub-Api-Version: 2022-11-28',
      ];
    }

    $resp = $this->httpGet($url, $headers);
    $data = json_decode($resp, true);
    if (!is_array($data)) return null;

    // GitHub: email часто null без отдельного запроса
    if ($name === 'github' && empty($data['email'])) {
      $emailsRaw = $this->httpGet('https://api.github.com/user/emails', $headers);
      $emails = json_decode($emailsRaw, true);
      if (is_array($emails)) {
        $primary = null;
        foreach ($emails as $em) {
          if (!empty($em['email']) && !empty($em['primary'])) {
            $primary = $em['email'];
            break;
          }
        }
        if (!$primary) {
          foreach ($emails as $em) {
            if (!empty($em['email']) && !empty($em['verified'])) {
              $primary = $em['email'];
              break;
            }
          }
        }
        if ($primary) $data['email'] = $primary;
      }
    }

    return $data;
  }

  private function httpPost(string $url, array $params, array $headers = []): string {
    if (!function_exists('curl_init')) {
      $opts = [
        'http' => [
          'method'  => 'POST',
          'header'  => implode("\r\n", array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers)),
          'content' => http_build_query($params),
          'timeout' => 15,
        ],
      ];
      $r = @file_get_contents($url, false, stream_context_create($opts));
      return $r !== false ? $r : '{}';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => http_build_query($params),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp !== false ? $resp : '{}';
  }

  private function httpGet(string $url, array $headers = []): string {
    if (!function_exists('curl_init')) {
      $opts = [
        'http' => [
          'method'  => 'GET',
          'header'  => implode("\r\n", $headers),
          'timeout' => 15,
        ],
      ];
      $r = @file_get_contents($url, false, stream_context_create($opts));
      return $r !== false ? $r : '{}';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_FOLLOWLOCATION => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp !== false ? $resp : '{}';
  }

  public function fetchVkFriendIds(string $accessToken): array {
    if (($this->provider['name'] ?? '') !== 'vk') return [];
    $url = 'https://api.vk.com/method/friends.get?' . http_build_query([
      'access_token' => $accessToken,
      'v' => '5.199',
    ]);
    $resp = $this->httpGet($url);
    $data = json_decode($resp, true);
    $ids = $data['response']['items'] ?? $data['response'] ?? [];
    return is_array($ids) ? array_map('strval', $ids) : [];
  }

  public static function normalizeProfile(array $data, string $providerName = ''): array {
    // VK users.get
    if (isset($data['response'][0]) && is_array($data['response'][0])) {
      $data = array_merge($data, $data['response'][0]);
    }
    $id = $data['id']
      ?? $data['sub']
      ?? $data['user_id']
      ?? ($data['user']['id'] ?? null)
      ?? ($data['user']['user_id'] ?? null);
    $email = $data['email']
      ?? $data['default_email']
      ?? ($data['user']['email'] ?? null);
    $name = $data['name']
      ?? $data['login']
      ?? $data['display_name']
      ?? $data['real_name']
      ?? null;
    if ($name === null || $name === '') {
      $fn = $data['first_name'] ?? ($data['response'][0]['first_name'] ?? '');
      $ln = $data['last_name'] ?? ($data['response'][0]['last_name'] ?? '');
      $name = trim($fn . ' ' . $ln) ?: null;
    }
    $avatar = $data['picture']
      ?? $data['avatar_url']
      ?? $data['avatar']
      ?? $data['photo_200']
      ?? $data['photo_100']
      ?? ($data['response'][0]['photo_200'] ?? null)
      ?? ($data['default_avatar_id'] ?? null);
    // Yandex default avatar
    if ($providerName === 'yandex' && is_string($avatar) && $avatar !== '' && strpos($avatar, 'http') !== 0) {
      $avatar = 'https://avatars.yandex.net/get-yapic/' . $avatar . '/islands-200';
    }
    return [
      'id'     => $id !== null ? (string)$id : null,
      'email'  => $email ? (string)$email : null,
      'name'   => $name ? (string)$name : null,
      'avatar' => $avatar ? (string)$avatar : null,
    ];
  }
}

/** Пресеты для админки (кнопка «добавить GitHub» и т.д.) */
function oauth_provider_presets(): array {
  return [
    'github' => [
      'name' => 'github',
      'display_name' => 'GitHub',
      'icon_url' => 'https://github.githubassets.com/favicons/favicon.svg',
      'auth_url' => 'https://github.com/login/oauth/authorize',
      'token_url' => 'https://github.com/login/oauth/access_token',
      'profile_url' => 'https://api.github.com/user',
      'scope' => 'read:user user:email',
    ],
    'yandex' => [
      'name' => 'yandex',
      'display_name' => 'Яндекс',
      'icon_url' => 'https://yastatic.net/s3/home/logos/firefox/browser.svg',
      'auth_url' => 'https://oauth.yandex.ru/authorize',
      'token_url' => 'https://oauth.yandex.ru/token',
      'profile_url' => 'https://login.yandex.ru/info?format=json',
      'scope' => 'login:email login:info login:avatar',
    ],
    'vk' => [
      'name' => 'vk',
      'display_name' => 'ВКонтакте',
      'icon_url' => 'https://vk.com/images/icons/favicons/fav_logo.ico',
      'auth_url' => 'https://oauth.vk.com/authorize',
      'token_url' => 'https://oauth.vk.com/access_token',
      'profile_url' => 'https://api.vk.com/method/users.get',
      'scope' => 'email',
    ],
    'google' => [
      'name' => 'google',
      'display_name' => 'Google',
      'icon_url' => 'https://www.google.com/favicon.ico',
      'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
      'token_url' => 'https://oauth2.googleapis.com/token',
      'profile_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
      'scope' => 'openid email profile',
    ],
  ];
}

function oauth_ensure_table(): void {
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS oauth_providers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL,
        display_name VARCHAR(128) NOT NULL,
        icon_url VARCHAR(500) NULL,
        client_id VARCHAR(255) NULL,
        client_secret VARCHAR(255) NULL,
        auth_url VARCHAR(500) NOT NULL,
        token_url VARCHAR(500) NOT NULL,
        profile_url VARCHAR(500) NOT NULL,
        scope VARCHAR(255) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_name (name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}
