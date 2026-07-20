# StreamLive (PHP)

PHP-версия платформы StreamLive — тот же функционал, что и в Node.js версии, но на чистом PHP + PDO + MySQL, без внешних фреймворков. Подходит для обычного shared/VPS хостинга с Apache или Nginx+PHP-FPM.

## Возможности

Полный паритет с Node.js версией:

- Авторизация email+пароль и динамические OAuth2-провайдеры (VK, Google, Яндекс из коробки + любой другой добавляется прямо в `/admin/oauth.php`).
- Создание каналов/радио, модерация с уведомлениями, возможность снять с публикации в любой момент.
- SEO-поля на каждый канал.
- Плеер: MP4, M3U8 (hls.js), embed YouTube/VK/Rutube/iframe. Отдельная `embed.php` страница для встраивания.
- Расписание эфира пн–вс с автопереключением активного источника.
- Свои источники вещания у владельца канала.
- Чат в реальном времени **через AJAX polling** (не Socket.io — на обычном PHP-хостинге вебсокеты часто недоступны, поэтому чат опрашивает `/chat_poll.php` каждые 2 секунды; отправка через `/chat_send.php`). Модерация чата, баны, кастомные стикеры.
- Счётчик просмотров.
- Тот же тёмный TikTok-style дизайн (общий CSS с Node-версией).

## Установка — веб-мастер

1. Загрузите содержимое `streamlive-php/` на хостинг так, чтобы корень сайта указывал на эту папку.
2. Убедитесь, что у PHP включены расширения **pdo_mysql**, **mbstring**, **curl** (стандартно есть почти везде).
3. Папка `config/` должна быть доступна на запись веб-серверу (`chmod 775 config`).
4. Откройте `https://ваш-домен/install/` — мастер сам проверит требования и проведёт через 3 шага:
   - **Проект и БД** — название проекта, URL сайта, данные MySQL (мастер сам создаст базу данных, если её нет).
   - **Администратор** — email/логин/пароль. По сохранению автоматически заливается схема (`sql/schema.sql`), создаётся администратор, добавляются пресеты OAuth-провайдеров, пишется `config/config.php`.
   - **Готово** — сразу можно входить.

Повторный заход на `/install/` после установки покажет заглушку "уже установлен". Чтобы переустановить — удалите `config/config.php` и `install/installed.lock`.

## Структура проекта

```
streamlive-php/
├── install/index.php        # веб-мастер установки (проверка требований, БД, админ)
├── config/
│   ├── config.sample.php     # шаблон конфига
│   └── config.php            # создаётся установщиком (в git не хранить)
├── includes/
│   ├── db.php                 # PDO подключение + редирект на /install, если не установлено
│   ├── auth.php               # сессии, current_user(), require_login/require_admin
│   ├── functions.php          # csrf, flash, slugify, resolve_active_source
│   ├── oauth.php               # универсальный OAuth2-клиент для динамических провайдеров
│   ├── header.php / footer.php
├── admin/                    # index, moderation, channels, sources, oauth, users
├── auth/                     # login, register, logout, oauth_start, oauth_callback
├── catalog.php / channel.php / channel_manage.php / new_channel.php / dashboard.php / embed.php
├── chat_send.php / chat_poll.php / chat_delete.php / chat_ban.php   # AJAX чат
├── assets/css/style.css       # общий с Node-версией тёмный TikTok-style
└── sql/schema.sql
```

## Отличия от Node.js версии

- **Чат** — AJAX polling вместо Socket.io/WebSocket. Задержка обновлений ~2 секунды, но не требует отдельного процесса и работает на любом shared-хостинге.
- **Установка** — классический PHP install-wizard (как у форумов/CMS), пишет `config/config.php` вместо `.env`, не требует перезапуска процесса — изменения применяются сразу же на следующий запрос.

## Безопасность

- Пароли — `password_hash`/`password_verify` (bcrypt).
- CSRF-токен на всех формах.
- Подготовленные выражения (PDO prepared statements) везде — без сырых SQL-конкатенаций.
- OAuth `state`-параметр проверяется на callback против session, чтобы исключить CSRF в OAuth-флоу.

## Лицензия

MIT — OinkTech Ltd.
