StreamLive V27 — троттлинг hits/CPU (ответ на замечание про rate-limit слой)

1) includes/poll_throttle.php — в корень/includes/
2) *poll*.php из архива — поверх своих (rate limit 429)
3) .htaccess — целиком или вставь htaccess_THROTTLE_SNIPPET.txt в конец текущего
4) assets/js/push-notify.js — опрос реже (если есть в архиве)

Лимиты по умолчанию (запросов / 60 сек с одного IP):
  message_poll 30 | notif 12 | chat 40 | comments/forum 20 | watch_room 24

Это НЕ спасёт от DDoS, но снизит шанс бана InfinityFree за hits/CPU
при нескольких открытых вкладках.

Клод прав: без nginx на free-хостинге только PHP + что даст Apache.
