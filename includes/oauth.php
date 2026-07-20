<?php
require_once __DIR__ . '/db.php';

class OAuthClient {
  public array $provider;

  public function __construct(array $provider) {
    $this->provider = $provider;
  }

  public static function find(string $name): ?OAuthClient {
    $stmt = db()->prepare('SELECT * FROM oauth_providers WHERE name = ? AND enabled = 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ? new self($row) : null;
  }

  public function authorizeUrl(): string {
    $p = $this->provider;
    $params = [
      'client_id' => $p['client_id'],
      'redirect_uri' => rtrim(SITE_URL, '/') . '/auth/oauth_callback.php?provider=' . $p['name'],
      'response_type' => 'code',
      'scope' => $p['scope'],
      'state' => $_SESSION['oauth_state'] = bin2hex(random_bytes(16)),
    ];
    return $p['auth_url'] . '?' . http_build_query($params);
  }

  public function exchangeCode(string $code): ?array {
    $p = $this->provider;
    $params = [
      'client_id' => $p['client_id'],
      'client_secret' => $p['client_secret'],
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => rtrim(SITE_URL, '/') . '/auth/oauth_callback.php?provider=' . $p['name'],
    ];
    $resp = $this->httpPost($p['token_url'], $params);
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
  }

  public function fetchProfile(string $accessToken): ?array {
    $p = $this->provider;
    $resp = $this->httpGet($p['profile_url'], ['Authorization: Bearer ' . $accessToken]);
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
  }

  private function httpPost(string $url, array $params): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => http_build_query($params),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
      CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ?: '{}';
  }

  private function httpGet(string $url, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
      CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ?: '{}';
  }

  // Список ID друзей ВК (только для провайдера vk, требует scope=friends при авторизации).
  // Используется для "Возможно, вы знакомы" — сверяем со своими users.oauth_id.
  public function fetchVkFriendIds(string $accessToken): array {
    if ($this->provider['name'] !== 'vk') return [];
    $url = 'https://api.vk.com/method/friends.get?' . http_build_query([
      'access_token' => $accessToken,
      'v' => '5.199',
    ]);
    $resp = $this->httpGet($url);
    $data = json_decode($resp, true);
    $ids = $data['response']['items'] ?? $data['response'] ?? [];
    return is_array($ids) ? array_map('strval', $ids) : [];
  }

  // Приводит разный формат ответов провайдеров (VK/Google/Яндекс/др.) к единому виду.
  // ВАЖНО: раньше при отсутствии id в ответе подставлялся uniqid() — то есть случайный
  // id на КАЖДЫЙ вход, из-за чего при втором входе через соцсеть создавался новый
  // аккаунт вместо входа в старый. Теперь id ищется по всем известным форматам ответов,
  // а если реально не найден — возвращается null и решение принимает вызывающий код
  // (см. auth/oauth_callback.php), а не генерируется случайное значение.
  public static function normalizeProfile(array $data): array {
    $id = $data['id']
      ?? $data['sub']
      ?? $data['user_id']
      ?? ($data['response'][0]['id'] ?? null)
      ?? ($data['user']['id'] ?? null)
      ?? ($data['user']['user_id'] ?? null);
    $email = $data['email']
      ?? $data['default_email']
      ?? ($data['user']['email'] ?? null);
    $name = $data['name']
      ?? $data['login']
      ?? $data['first_name']
      ?? ($data['response'][0]['first_name'] ?? null)
      ?? ($data['user']['first_name'] ?? null);
    $avatar = $data['picture']
      ?? $data['avatar_url']
      ?? ($data['response'][0]['photo_100'] ?? null)
      ?? ($data['user']['avatar'] ?? null);
    return [
      'id' => $id !== null ? (string)$id : null,
      'email' => $email,
      'name' => $name,
      'avatar' => $avatar,
    ];
  }
}
