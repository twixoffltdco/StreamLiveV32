ФИКС: светлая тема + аватар в профиле + install

Залей поверх:
  assets/css/vibe.css
  assets/css/profile-glass.css
  assets/css/light-force.css
  assets/js/theme-toggle.js
  assets/js/theme-boot.js
  assets/js/vibe-avatars.js  (отключён — больше не ломает DOM)
  includes/header.php
  includes/footer.php
  install/   — установщик из V31 (если папки не было)

Ctrl+F5 обязателен.

Светлая тема: кнопка ☀️/🌙 + cookie site_color_mode и pl_skin.
Аватар: рамка через box-shadow внутри hero (overflow:hidden), не вылезает.
