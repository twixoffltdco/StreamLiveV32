<?php
/**
 * Мягкий hardening — ничего не удаляет, только заголовки/хелперы.
 * Подключение (по желанию) в includes/header.php:
 *   if (is_file(__DIR__.'/security_hardening.php')) require_once __DIR__.'/security_hardening.php';
 *   if (function_exists('sl_security_headers')) sl_security_headers();
 */
function sl_security_headers(): void {
  if (headers_sent()) return;
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
  // не ставим жёсткий CSP — сломает inline/скрипты площадки
}

function sl_audit_notes(): array {
  return [
    'csrf' => 'Проверьте csrf_field/csrf_verify на POST формах',
    'uploads' => 'Загрузки: только whitelist расширений, без PHP в storage',
    'sql' => 'Только prepared statements',
    'admin' => 'Смените пароли admin и config extension landing',
    'moderation' => 'Очередь /moderation/content.php, своё модерировать нельзя',
    'xss' => 'Ники через user_render_username_html + sanitize CSS',
  ];
}
