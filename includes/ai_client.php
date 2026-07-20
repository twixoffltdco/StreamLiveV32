<?php
// Клиент локального ИИ-ассистента T2000. Ключ и модель хранятся в таблице settings (MySQL),
// настраиваются в /admin/ai.php. Поддержка: OpenAI-совместимые API (chat/completions) и Anthropic (v1/messages).

function t2000_call(array $messages): array {
  $provider = get_setting('ai_provider', 'openai');
  $apiKey = get_setting('ai_api_key', '');
  $model = get_setting('ai_model', '');
  $baseUrl = get_setting('ai_base_url', '');

  if (!$apiKey || !$model) {
    return ['ok' => false, 'error' => 'ИИ T2000 ещё не настроен администратором (нет API-ключа или модели в /admin/ai.php)'];
  }

  if ($provider === 'anthropic') {
    return t2000_call_anthropic($messages, $apiKey, $model, $baseUrl);
  }
  return t2000_call_openai($messages, $apiKey, $model, $baseUrl);
}

function t2000_call_anthropic(array $messages, string $apiKey, string $model, string $baseUrl): array {
  $url = rtrim($baseUrl ?: 'https://api.anthropic.com', '/') . '/v1/messages';
  $system = null;
  $chatMessages = [];
  foreach ($messages as $m) {
    if ($m['role'] === 'system') { $system = $m['content']; continue; }
    $chatMessages[] = ['role' => $m['role'], 'content' => $m['content']];
  }
  $payload = ['model' => $model, 'max_tokens' => 4096, 'messages' => $chatMessages];
  if ($system) $payload['system'] = $system;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'x-api-key: ' . $apiKey,
      'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 60,
  ]);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false) return ['ok' => false, 'error' => 'Сетевая ошибка: ' . $err];
  $data = json_decode($resp, true);
  if (!empty($data['content'][0]['text'])) return ['ok' => true, 'text' => $data['content'][0]['text']];
  return ['ok' => false, 'error' => $data['error']['message'] ?? 'Неизвестная ошибка ИИ (проверьте ключ и модель в /admin/ai.php)'];
}

function t2000_call_openai(array $messages, string $apiKey, string $model, string $baseUrl): array {
  $url = rtrim($baseUrl ?: 'https://api.openai.com/v1', '/') . '/chat/completions';
  $payload = ['model' => $model, 'messages' => $messages];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT => 60,
  ]);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false) return ['ok' => false, 'error' => 'Сетевая ошибка: ' . $err];
  $data = json_decode($resp, true);
  if (!empty($data['choices'][0]['message']['content'])) return ['ok' => true, 'text' => $data['choices'][0]['message']['content']];
  return ['ok' => false, 'error' => $data['error']['message'] ?? 'Неизвестная ошибка ИИ (проверьте ключ и модель в /admin/ai.php)'];
}
