<?php
/**
 * ИИ T2000 для StreamLive — OpenAI-compatible + Anthropic.
 * Grok (xAI): base https://api.x.ai/v1 , модель например grok-4.5 / grok-3
 */

function t2000_system_prompt(): string {
  $custom = get_setting('ai_system_prompt', '');
  if ($custom !== null && trim((string)$custom) !== '') return trim((string)$custom);
  $name = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
  return "Ты — ИИ-ассистент платформы {$name} (T2000). Помогаешь с кодом, текстом, идеями и вопросами. "
    . "Отвечай на языке пользователя, по делу. Код — в markdown-блоках.";
}

function t2000_call(array $messages): array {
  $provider = get_setting('ai_provider', 'openai') ?: 'openai';
  $apiKey = trim((string)(get_setting('ai_api_key', '') ?: ''));
  $model = trim((string)(get_setting('ai_model', '') ?: ''));
  $baseUrl = trim((string)(get_setting('ai_base_url', '') ?: ''));
  $preset = get_setting('ai_provider_preset', '') ?: '';

  if ($apiKey === '' || $model === '') {
    return ['ok' => false, 'error' => 'ИИ не настроен: укажите API-ключ и модель в /admin/ai.php'];
  }

  // Авто-фикс типичных ошибок настроек Grok
  if ($preset === 'xai' || stripos($baseUrl, 'api.x.ai') !== false || stripos($model, 'grok') !== false) {
    if ($baseUrl === '' || stripos($baseUrl, 'openai.com') !== false) {
      $baseUrl = 'https://api.x.ai/v1';
    }
    // устаревшие имена моделей → актуальные
    $map = [
      'grok-2-latest' => 'grok-4.5',
      'grok-2' => 'grok-4.5',
      'grok-beta' => 'grok-4.5',
      'grok-2-1212' => 'grok-4.5',
    ];
    if (isset($map[$model])) $model = $map[$model];
  }

  $hasSystem = false;
  foreach ($messages as $m) {
    if (($m['role'] ?? '') === 'system') { $hasSystem = true; break; }
  }
  if (!$hasSystem) {
    array_unshift($messages, ['role' => 'system', 'content' => t2000_system_prompt()]);
  }

  if ($provider === 'anthropic' || $preset === 'anthropic') {
    return t2000_call_anthropic($messages, $apiKey, $model, $baseUrl);
  }
  return t2000_call_openai($messages, $apiKey, $model, $baseUrl);
}

function t2000_normalize_openai_url(string $baseUrl): string {
  $base = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.openai.com/v1', '/');
  // уже полный путь к completions
  if (preg_match('#/chat/completions$#i', $base)) return $base;
  // .../v1 или корень
  if (preg_match('#/v1$#i', $base)) return $base . '/chat/completions';
  // api.x.ai без /v1
  if (preg_match('#api\.x\.ai$#i', $base)) return $base . '/v1/chat/completions';
  return $base . '/chat/completions';
}

function t2000_http_post_json(string $url, array $headers, array $payload): array {
  // Прокси StreamLive (если хостинг режет исходящий curl)
  $actualUrl = $url;
  if (defined('NETWORK_PROXY_URL') && NETWORK_PROXY_URL !== '') {
    $actualUrl = NETWORK_PROXY_URL . '?url=' . urlencode($url);
    if (defined('NETWORK_PROXY_SECRET') && NETWORK_PROXY_SECRET !== '') {
      $headers[] = 'X-Proxy-Secret: ' . NETWORK_PROXY_SECRET;
    }
  }

  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($body === false) {
    return ['ok' => false, 'error' => 'Не удалось сформировать JSON запроса', 'http' => 0, 'raw' => ''];
  }

  $ch = curl_init($actualUrl);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 25,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'StreamLive-T2000/1.1',
  ];
  // на кривых shared-хостингах иногда ломается CA bundle
  if (defined('AI_SSL_INSECURE') && AI_SSL_INSECURE) {
    $opts[CURLOPT_SSL_VERIFYPEER] = false;
    $opts[CURLOPT_SSL_VERIFYHOST] = 0;
  }
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $errno = curl_errno($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    $hint = $err ?: ('curl #' . $errno);
    if ($errno === 6 || $errno === 7) {
      $hint .= ' — хостинг не достучался до API (блок/DNS/VPN). Для РФ: ProxyAPI/DeepSeek или NETWORK_PROXY_URL.';
    }
    if ($errno === 60 || $errno === 35) {
      $hint .= ' — SSL. Можно define(\'AI_SSL_INSECURE\', true) в config.php (только временно).';
    }
    return ['ok' => false, 'error' => 'Сеть: ' . $hint, 'http' => 0, 'raw' => ''];
  }

  return ['ok' => true, 'http' => $code, 'raw' => $resp];
}

function t2000_parse_error_body(string $raw, int $code): string {
  $data = json_decode($raw, true);
  if (is_array($data)) {
    if (!empty($data['error']['message'])) return (string)$data['error']['message'];
    if (!empty($data['error']) && is_string($data['error'])) return $data['error'];
    if (!empty($data['message'])) return (string)$data['message'];
  }
  $snip = trim(mb_substr(strip_tags($raw), 0, 180));
  return $snip !== '' ? ('HTTP ' . $code . ': ' . $snip) : ('HTTP ' . $code);
}

function t2000_call_anthropic(array $messages, string $apiKey, string $model, string $baseUrl): array {
  $base = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.anthropic.com', '/');
  $url = preg_match('#/v1/messages$#', $base) ? $base : ($base . '/v1/messages');
  $system = null;
  $chatMessages = [];
  foreach ($messages as $m) {
    if (($m['role'] ?? '') === 'system') { $system = $m['content']; continue; }
    $chatMessages[] = ['role' => $m['role'], 'content' => $m['content']];
  }
  $payload = ['model' => $model, 'max_tokens' => 4096, 'messages' => $chatMessages];
  if ($system) $payload['system'] = $system;

  $res = t2000_http_post_json($url, [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
    'anthropic-version: 2023-06-01',
  ], $payload);

  if (empty($res['ok'])) return $res;
  if ($res['http'] >= 400) {
    return ['ok' => false, 'error' => t2000_parse_error_body($res['raw'], $res['http'])];
  }
  $data = json_decode($res['raw'], true);
  if (!empty($data['content'][0]['text'])) {
    return ['ok' => true, 'text' => (string)$data['content'][0]['text']];
  }
  return ['ok' => false, 'error' => 'Пустой ответ Anthropic'];
}

function t2000_call_openai(array $messages, string $apiKey, string $model, string $baseUrl): array {
  $url = t2000_normalize_openai_url($baseUrl);
  $maxTokens = (int)(get_setting('ai_max_tokens', '4096') ?: 4096);

  // xAI иногда капризничает на temperature+max_tokens вместе с reasoning-моделями —
  // шлём минимально совместимый payload
  $payload = [
    'model' => $model,
    'messages' => $messages,
  ];
  // max_tokens — стандарт OpenAI-compatible (Grok chat/completions его принимает)
  if ($maxTokens > 0) {
    $payload['max_tokens'] = max(256, min(128000, $maxTokens));
  }

  $headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'Accept: application/json',
  ];
  if (stripos($url, 'openrouter.ai') !== false) {
    $headers[] = 'HTTP-Referer: ' . (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://streamlive.local');
    $headers[] = 'X-Title: StreamLive-T2000';
  }

  $res = t2000_http_post_json($url, $headers, $payload);
  if (empty($res['ok'])) return $res;

  if ($res['http'] >= 400) {
    $msg = t2000_parse_error_body($res['raw'], $res['http']);
    // Частые подсказки
    if ($res['http'] === 401 || $res['http'] === 403) {
      $msg .= ' — проверьте API-ключ xAI (console.x.ai).';
    }
    if ($res['http'] === 400 && stripos($msg, 'model') !== false) {
      $msg .= ' — укажите актуальную модель, например: grok-4.5';
    }
    if ($res['http'] === 404) {
      $msg .= ' — неверный Base URL. Для Grok: https://api.x.ai/v1';
    }
    return ['ok' => false, 'error' => $msg, 'http' => $res['http'], 'url' => $url];
  }

  $data = json_decode($res['raw'], true);
  $text = $data['choices'][0]['message']['content'] ?? null;
  if (is_string($text) && $text !== '') {
    return ['ok' => true, 'text' => $text];
  }
  // content как массив частей
  if (is_array($text)) {
    $parts = [];
    foreach ($text as $p) {
      if (is_string($p)) $parts[] = $p;
      elseif (is_array($p) && isset($p['text'])) $parts[] = $p['text'];
    }
    if ($parts) return ['ok' => true, 'text' => implode("\n", $parts)];
  }
  return ['ok' => false, 'error' => 'Пустой ответ модели (проверьте model/ключ). HTTP ' . $res['http']];
}

function t2000_provider_presets(): array {
  return [
    'xai' => ['label' => 'xAI Grok', 'base' => 'https://api.x.ai/v1', 'model' => 'grok-4.5'],
    'openai' => ['label' => 'OpenAI', 'base' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini'],
    'openrouter' => ['label' => 'OpenRouter', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'openrouter/auto'],
    'deepseek' => ['label' => 'DeepSeek', 'base' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat'],
    'proxyapi' => ['label' => 'ProxyAPI (РФ)', 'base' => 'https://api.proxyapi.ru/openai/v1', 'model' => 'gpt-4o-mini'],
    'ollama' => ['label' => 'Ollama (локально)', 'base' => 'http://127.0.0.1:11434/v1', 'model' => 'llama3.2'],
    'anthropic' => ['label' => 'Anthropic Claude', 'base' => 'https://api.anthropic.com', 'model' => 'claude-sonnet-4-20250514'],
    'custom' => ['label' => 'Свой endpoint', 'base' => '', 'model' => ''],
  ];
}
