<?php
// Второй слой защиты, поверх твоего includes/antibot.php (его не трогаю, он остаётся
// как есть — это защита "N запросов с одного IP за N секунд").
//
// Этот модуль решает то, что antibot.php в принципе не может решить, потому что сам
// обращается к БД на каждый запрос: если одновременных запросов сразу МНОГО (не важно,
// с одного IP или с ботнета из тысяч разных IP), БД захлёбывается в соединениях раньше,
// чем антибот успевает кого-то заблокировать. Именно так падает большинство дешёвых
// хостингов при атаке — не от самого трафика, а от исчерпания connection pool MySQL.
//
// ЧЕСТНО: это не останавливает реальный волюметрический DDoS (флуд пакетами/каналом) —
// это в принципе невозможно на уровне PHP-кода, это происходит до того, как запрос
// доходит до PHP. Это защищает конкретно от перегрузки БД и от прицельного злоупотребления
// дорогими операциями (импорт видео, деплой) — то есть от "L7" атак, которые реально
// возможны без прокси перед сервером.

const DDOS_MAX_CONCURRENT = 40;      // сколько запросов разрешаем обрабатывать ОДНОВРЕМЕННО
const DDOS_LOCK_DIR = __DIR__ . '/../storage/ddos_locks';

// --- Слой 1: ограничитель одновременных запросов (circuit breaker) -----------------
// Работает на файлах, НЕ трогает БД — специально, чтобы сработать даже когда БД уже
// перегружена. Каждый входящий запрос создаёт файл-маркер на время своей обработки,
// если маркеров одновременно больше лимита — новый запрос сразу получает лёгкую
// статическую страницу без единого обращения к MySQL.
function ddos_concurrency_guard(): ?string {
  if (!is_dir(DDOS_LOCK_DIR)) @mkdir(DDOS_LOCK_DIR, 0755, true);
  if (!is_dir(DDOS_LOCK_DIR)) return null; // нет прав на запись — пропускаем эту защиту, не роняем сайт

  // Чистим маркеры старше 30 сек (застрявшие от упавших процессов) — иначе счётчик
  // будет только расти и в итоге заблокирует вообще всех, включая настоящих гостей.
  foreach (glob(DDOS_LOCK_DIR . '/*.lock') ?: [] as $file) {
    if (time() - filemtime($file) > 30) @unlink($file);
  }

  $current = count(glob(DDOS_LOCK_DIR . '/*.lock') ?: []);
  if ($current >= DDOS_MAX_CONCURRENT) {
    return DDOS_LOCK_DIR; // сигнал вызывающему коду — сайт перегружен, см. ddos_guard()
  }

  $markerFile = DDOS_LOCK_DIR . '/' . uniqid('', true) . '.lock';
  @touch($markerFile);
  register_shutdown_function(function () use ($markerFile) { @unlink($markerFile); });
  return null;
}

// --- Слой 2: отдельные, более жёсткие лимиты для дорогих операций ------------------
// Импорт видео и GitHub-деплой каждый раз дёргают curl наружу и/или пишут файлы на
// диск — это на порядок дороже обычного просмотра страницы. Общий лимит антибота
// (5 запросов/10 сек) для них слишком мягкий, отдельно режем жёстче.
function ddos_expensive_routes(): array {
  return [
    '/video_import.php'  => ['limit' => 5,  'window' => 300],  // 5 попыток импорта за 5 минут
    '/github_deploy.php' => ['limit' => 3,  'window' => 600],  // 3 деплоя за 10 минут
    '/auth/login.php'    => ['limit' => 10, 'window' => 300],  // защита от брутфорса пароля
    '/auth/register.php' => ['limit' => 5,  'window' => 600],  // защита от массовой регистрации ботами
  ];
}

function ddos_expensive_route_guard(string $path, string $ip): bool {
  $routes = ddos_expensive_routes();
  if (!isset($routes[$path])) return true; // не в списке — не наша забота, обычный антибот уже отработал

  $rule = $routes[$path];
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS ddos_route_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route VARCHAR(191) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_route_ip (route, ip, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  } catch (\Throwable $e) { return true; } // нет доступа к БД — не наша забота, антибот уже решил вопрос выше

  $cutoff = date('Y-m-d H:i:s', time() - $rule['window']);
  $stmt = db()->prepare('SELECT COUNT(*) FROM ddos_route_log WHERE route = ? AND ip = ? AND created_at > ?');
  $stmt->execute([$path, $ip, $cutoff]);
  $count = (int)$stmt->fetchColumn();

  if ($count >= $rule['limit']) return false;

  db()->prepare('INSERT INTO ddos_route_log (route, ip) VALUES (?, ?)')->execute([$path, $ip]);
  return true;
}

// --- Режим "под атакой" (опциональный, включает владелец сайта вручную) ------------
// Похоже по идее на "I'm Under Attack" у Cloudflare, только гораздо проще: показывает
// JS-задачку (вычислить хэш с нужным условием) перед тем, как пустить на сайт.
// Простые флуд-боты обычно не выполняют JS вообще — отсеивает их за копейки CPU,
// не требуя внешних сервисов. Настоящего человека с браузером не замечает — проверка
// занимает доли секунды и проходит автоматически.
function ddos_under_attack_mode_enabled(): bool {
  try {
    return get_setting('ddos_under_attack_mode', '0') === '1';
  } catch (\Throwable $e) { return false; }
}

function ddos_show_busy_page(): void {
  http_response_code(503);
  header('Retry-After: 5');
  echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Сайт перегружен</title>' .
    '<meta http-equiv="refresh" content="5"><style>body{font-family:sans-serif;background:#0d0d0d;color:#eee;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}</style></head>' .
    '<body><div><h1>⏳ Сейчас слишком много запросов</h1><p>Страница обновится сама через несколько секунд.</p></div></body></html>';
  exit;
}

function ddos_show_pow_challenge(): void {
  http_response_code(503);
  ?>
  <!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Проверка браузера</title>
  <style>body{font-family:sans-serif;background:#0d0d0d;color:#eee;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}</style></head>
  <body><div><h1>🛡️ Проверка браузера</h1><p id="status">Идёт проверка, подождите секунду...</p></div>
  <script>
  // Простая proof-of-work: подбираем nonce так, чтобы SHA-256(seed+nonce) начинался с нулей.
  // Задача тривиальна для одного честного браузера (доли секунды), но заметно накладна
  // для бота, который пытается открыть тысячи страниц одновременно без исполнения JS вообще.
  async function solve() {
    const seed = <?= json_encode(bin2hex(random_bytes(8))) ?>;
    let nonce = 0;
    while (true) {
      const enc = new TextEncoder().encode(seed + nonce);
      const hashBuf = await crypto.subtle.digest('SHA-256', enc);
      const hex = Array.from(new Uint8Array(hashBuf)).map(b => b.toString(16).padStart(2,'0')).join('');
      if (hex.startsWith('0000')) break;
      nonce++;
    }
    document.cookie = 'ddos_pow=' + seed + ':' + nonce + '; path=/; max-age=1800';
    location.reload();
  }
  solve();
  </script>
  </body></html>
  <?php
  exit;
}

function ddos_verify_pow_cookie(): bool {
  $val = $_COOKIE['ddos_pow'] ?? '';
  if (!$val || !str_contains($val, ':')) return false;
  [$seed, $nonce] = explode(':', $val, 2);
  $hash = hash('sha256', $seed . $nonce);
  return str_starts_with($hash, '0000');
}

// --- Главная точка входа — вызывается из functions.php, ДО antibot_guard() ---------
function ddos_guard(): void {
  // Шаг 1 (первый!): не перегружен ли сайт прямо сейчас — файловый счётчик,
  // ни одного обращения к БД. Именно поэтому идёт раньше всех остальных проверок:
  // если БД уже захлёбывается, мы не должны добавлять к этому ещё один запрос.
  if (ddos_concurrency_guard() !== null) {
    ddos_show_busy_page();
  }

  // Шаг 2: под атакой — просим доказать, что это настоящий браузер (тут уже
  // обращаемся к БД за настройкой, но только если шаг 1 подтвердил, что сайт не в перегрузе)
  if (ddos_under_attack_mode_enabled() && !ddos_verify_pow_cookie()) {
    ddos_show_pow_challenge();
  }

  // Шаг 3: отдельный жёсткий лимит для дорогих маршрутов (импорт видео, деплой, логин)
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path && !ddos_expensive_route_guard($path, antibot_client_ip())) {
    http_response_code(429);
    die('Слишком много попыток. Подождите немного и попробуйте снова.');
  }
}
