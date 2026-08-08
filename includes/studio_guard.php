<?php
/**
 * Студия только для стиля «Платформа» + авторизованный пользователь.
 */
function studio_current_ui_mode(): string {
  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
  $mode = 'streamlife';
  if (!empty($_COOKIE['pl_ui_mode'])) {
    $mode = (string)$_COOKIE['pl_ui_mode'];
  } elseif (!empty($_SESSION['pl_ui_mode'])) {
    $mode = (string)$_SESSION['pl_ui_mode'];
  }
  $allowed = ['streamlife', 'platforma', 'telegram', 'prohub'];
  if (!in_array($mode, $allowed, true)) {
    $mode = 'streamlife';
  }
  return $mode;
}

function studio_require_platforma_access(): void {
  if (!function_exists('current_user')) {
    $root = dirname(__DIR__);
    if (is_file($root . '/includes/auth.php')) {
      require_once $root . '/includes/auth.php';
    }
  }
  $u = function_exists('current_user') ? current_user() : null;
  if (!$u) {
    $login = is_file(dirname(__DIR__) . '/auth/login.php') ? '/auth/login.php' : '/login.php';
    header('Location: ' . $login . '?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/platforma/studio/'));
    exit;
  }
  $mode = studio_current_ui_mode();
  if ($mode !== 'platforma') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $switch = '/platforma/switch.php?mode=platforma&redirect=' . rawurlencode('/platforma/studio/');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Студия только в стиле Платформа</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0b0b12;color:#f4f4f8;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px;text-align:center}';
    echo 'a{display:inline-block;margin:8px;padding:12px 18px;border-radius:12px;background:linear-gradient(135deg,#a78bfa,#22d3ee);color:#0b0b12;font-weight:700;text-decoration:none}';
    echo '.m{opacity:.7;font-size:14px;margin-top:12px}</style></head><body><div>';
    echo '<h1 style="margin:0 0 10px;font-size:22px">Студия доступна только в стиле «Платформа»</h1>';
    echo '<p class="m">Сейчас включён стиль: <b>' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '</b></p>';
    echo '<p><a href="' . htmlspecialchars($switch, ENT_QUOTES, 'UTF-8') . '">Включить Платформу и открыть студию</a></p>';
    echo '<p><a href="/" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:#f4f4f8">На главную</a></p>';
    echo '</div></body></html>';
    exit;
  }
}
