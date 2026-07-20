<?php
require_once __DIR__ . '/auth.php';

// В отличие от require_admin() (только role='admin'), сюда пускаем ещё и
// role='moderator' — у модераторов доступ только к разделу /moderator/,
// полная админка (/admin/) им по-прежнему закрыта через require_admin().
function require_moderator(): array {
  $user = require_login();
  if (!in_array($user['role'], ['moderator', 'admin'], true)) {
    http_response_code(403);
    die('Доступ только для модераторов и администраторов');
  }
  return $user;
}
