# Платформа + StreamLife — переключатель режимов

## Как работает

В шапке сайта два режима:

- **StreamLife** — обычный интерфейс
- **Платформа** — каталог в стиле YouTube / plvideo

Выбор сохраняется в cookie `pl_ui_mode` на год.

Клик по каналу в Платформе открывает **обычный** `/channel.php?id=...` (не iframe).

## Установка

1. Папка `/platforma/` на хосте (все файлы из ZIP).

2. В **header.php** StreamLife добавь переключатель (в шапку):

```php
<?php
// если header.php в корне:
@include __DIR__ . '/platforma/header_switcher.php';
// если header в includes/:
// @include dirname(__DIR__) . '/platforma/header_switcher.php';
?>
```

3. (Опционально) блок рекомендаций на главной StreamLife:

```php
<?php @include __DIR__ . '/platforma/recommendations_block.php'; ?>
```

4. В футере ссылка:

```html
<a href="/platforma/">Платформа</a>
```

## Файлы

| Файл | Назначение |
|------|------------|
| `index.php` | Главная Платформы |
| `switch.php` | Смена режима + cookie |
| `header_switcher.php` | Виджет в шапку |
| `recommendations_block.php` | Рекомендации для StreamLife |
| `api_*.php` | Каналы, эфир, форум, рекомендации |

## Если channel.php?id= не открывает канал

Открой любой канал с главной StreamLife и посмотри URL в браузере.
Напиши его — подставим точный формат (иногда `?c=slug` или `/channel/slug`).
