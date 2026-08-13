<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
brands_ensure_schema();
$to = (int)($_GET['to'] ?? 0);
if ($to <= 0) {
  brands_switch(null);
  flash_set('success', 'Снова личный аккаунт');
} else {
  if (brands_switch($to)) {
    $b = brands_get($to);
    flash_set('success', 'Активен бренд @' . ($b['username'] ?? ''));
  } else {
    flash_set('error', 'Нет доступа к бренду');
  }
}
$redirect = (string)($_GET['redirect'] ?? '/brands/');
if ($redirect === '' || $redirect[0] !== '/') $redirect = '/brands/';
redirect($redirect);
