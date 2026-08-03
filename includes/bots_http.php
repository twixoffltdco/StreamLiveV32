<?php
function bots_http_json(string $url, array $post = [], int $timeout = 25): array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 12,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'StreamLiveBots/1.1',
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
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
      return ['ok' => false, 'error' => 'curl: ' . ($err ?: 'fail'), 'http' => $code];
    }
  } else {
    $opts = [
      'http' => [
        'method' => $post ? 'POST' : 'GET',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: StreamLiveBots/1.1\r\n",
        'timeout' => $timeout,
        'ignore_errors' => true,
      ],
    ];
    if ($post) {
      $opts['http']['content'] = http_build_query($post);
    }
    $body = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
      $code = (int)$m[1];
    }
    if ($body === false) {
      return ['ok' => false, 'error' => 'file_get_contents failed (allow_url_fopen?)', 'http' => $code];
    }
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    return ['ok' => false, 'error' => 'bad json: ' . mb_substr($body, 0, 200), 'http' => $code ?? 0];
  }
  $json['_http'] = $code ?? 0;
  return $json;
}
