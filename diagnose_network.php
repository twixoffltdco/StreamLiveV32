<?php
// ВАЖНО: тут НЕ подключаем includes/header.php — он не служебный файл с функциями,
// а сразу печатает всю HTML-страницу (шапку, меню). Нам нужны только функции
// (db(), require_login()), они лежат в auth.php/functions.php.
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

header('Content-Type: text/plain; charset=utf-8'); // теперь это ПЕРВЫЙ вывод — сработает как надо

echo "=== Диагностика исходящих запросов с сервера ===\n\n";
echo "curl расширение: " . (extension_loaded('curl') ? "включено\n" : "ВЫКЛЮЧЕНО — вот и вся причина, писать в поддержку хостинга\n");
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? "включено\n" : "выключено (не критично, если curl работает)\n");
echo "openssl: " . (extension_loaded('openssl') ? "включено\n\n" : "ВЫКЛЮЧЕНО — https-запросы невозможны\n\n");

$targets = [
  'YouTube oEmbed'      => 'https://www.youtube.com/oembed?format=json&url=' . urlencode('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
  'VK (обычная страница)' => 'https://vk.com',
  'GitHub API'           => 'https://api.github.com',
  'GitHub codeload (архивы)' => 'https://codeload.github.com',
];

foreach ($targets as $label => $url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DiagBot/1.0)',
  ]);
  $start = microtime(true);
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $time = round((microtime(true) - $start) * 1000);
  curl_close($ch);

  echo "-- {$label} ({$url}) --\n";
  if ($errno !== 0) {
    echo "ОШИБКА curl #{$errno}: {$error}\n";
  } else {
    echo "OK, HTTP {$httpCode}, {$time} мс, получено " . strlen((string)$body) . " байт\n";
    if ($label === 'YouTube oEmbed' && $httpCode === 200) {
      $json = json_decode($body, true);
      echo "  title из ответа: " . ($json['title'] ?? '(не распарсилось)') . "\n";
    }
  }
  echo "\n";
}

echo "Эту страницу можно удалить с хостинга после проверки.\n";
