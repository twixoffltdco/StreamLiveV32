<?php
// Регистрирует/чинит тему FlexDev в themes.json как CSS-only оверлей поверх
// стандартной разметки includes/header.php+footer.php. Это самый безопасный вариант:
// flexdev.css уже сам по себе задаёт полную цветовую схему и стили компонентов
// (navbar, кнопки, карточки и т.д.) под те же CSS-классы, что использует обычная
// разметка сайта — отдельный кастомный header/footer для неё не нужен.
//
// Если раньше тема была зарегистрирована с кастомными header/footer (например, через
// вставку содержимого assets/themes/header.php в форму /admin/themes.php) — этот
// скрипт вернёт её на безопасный CSS-only вариант.
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/themes.php';

$cssPath = __DIR__ . '/assets/themes/style.css';
if (!file_exists($cssPath)) { die('assets/themes/style.css не найден на диске'); }

$themes = themes_all();
$themes = array_values(array_filter($themes, fn($t) => ($t['slug'] ?? '') !== 'flexdev'));
$themes[] = ['slug' => 'GitGoida', 'name' => 'GitGoida', 'css' => '/assets/themes/gitgoida.css', 'header' => null, 'footer' => null];
themes_save_all($themes);

echo "Тема FlexDev зарегистрирована (CSS-only, без кастомного header/footer).\n";
echo "Выбрать её теперь можно в переключателе тем в шапке сайта.\n";
echo "Этот файл (register_flexdev_theme.php) можно удалить с хостинга после запуска.\n";
