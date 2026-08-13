# Production polish (от Grok)

## Что добавлено
1. **Security headers** — nosniff, Referrer-Policy, Permissions-Policy, HSTS на HTTPS, X-Frame-Options SAMEORIGIN (не на embed)
2. **Режим техработ** — `/admin/maintenance.php` + файл `storage/maintenance.on`
3. **health.php** — JSON для UptimeRobot (`ok` / `db` / `maintenance`)
4. **Красивые 404/500** — `error_404.php`, `error_500.php` (+ snippet для .htaccess)
5. **Anti-flood** — чат ≥2с, комментарии ≥5с между отправками
6. **robots.txt** — закрыты admin/moderator/storage
7. **sitemap** — видео + ресурсы, base URL с текущего хоста (не прибит к freedev.app)

## Чего на InfinityFree всё ещё нет «из коробки»
- Исходящий DNS к `api.telegram.org` (боты TG) — нужен прокси или другой хост
- WebSocket — чат на polling, это ок

## Рекомендуется после деплоя
1. Распаковать поверх
2. В конец `.htaccess` вставить `htaccess_errors.snippet`
3. Проверить `/health.php` → `{"ok":true...}`
4. В Search Console указать `/sitemap.xml.php`
