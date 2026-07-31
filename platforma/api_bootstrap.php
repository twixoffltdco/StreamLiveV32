<?php
/**
 * Общий bootstrap для Platforma API.
 * Ищет конфиг/БД StreamLife на уровень выше и отдаёт PDO.
 */
declare(strict_types=1);

function pl_root_candidates(): array {
    $here = dirname(__DIR__); // .../platforma
    $up = dirname($here);     // корень сайта
    $list = [$up, $here, $up . '/..', dirname($up)];
    $out = [];
    foreach ($list as $p) {
        $r = realpath($p);
        if ($r && !in_array($r, $out, true)) $out[] = $r;
    }
    return $out;
}

function pl_load_streamlife(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (pl_root_candidates() as $r) {
        foreach (['/includes/db.php', '/config/db.php', '/includes/functions.php', '/config.php', '/config/config.php', '/config.sample.php'] as $rel) {
            if (is_file($r . $rel)) {
                @require_once $r . $rel;
            }
        }
        // типичный config StreamLive
        if (is_file($r . '/config/config.php')) @require_once $r . '/config/config.php';
        if (is_file($r . '/includes/auth.php')) @require_once $r . '/includes/auth.php';
    }
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

function pl_pdo(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($tried) return $pdo;
    $tried = true;
    pl_load_streamlife();

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $pdo = $GLOBALS['pdo'];
    }
    if (function_exists('db')) {
        try {
            $d = db();
            if ($d instanceof PDO) return $pdo = $d;
            // mysqli wrapper?
            if (is_object($d) && method_exists($d, 'query')) {
                // не PDO
            }
        } catch (Throwable $e) {}
    }
    if (function_exists('getDB')) {
        try {
            $d = getDB();
            if ($d instanceof PDO) return $pdo = $d;
        } catch (Throwable $e) {}
    }

    // Константы из config
    $host = $GLOBALS['db_host'] ?? $GLOBALS['DB_HOST'] ?? (defined('DB_HOST') ? DB_HOST : null);
    $name = $GLOBALS['db_name'] ?? $GLOBALS['DB_NAME'] ?? (defined('DB_NAME') ? DB_NAME : null);
    $user = $GLOBALS['db_user'] ?? $GLOBALS['DB_USER'] ?? (defined('DB_USER') ? DB_USER : null);
    $pass = $GLOBALS['db_pass'] ?? $GLOBALS['DB_PASS'] ?? (defined('DB_PASS') ? DB_PASS : null);
    if (!$host && defined('DB_HOSTNAME')) $host = DB_HOSTNAME;
    if (!$name && defined('DB_DATABASE')) $name = DB_DATABASE;
    if (!$user && defined('DB_USERNAME')) $user = DB_USERNAME;
    if ($pass === null && defined('DB_PASSWORD')) $pass = DB_PASSWORD;

    // config.php array style
    if (isset($GLOBALS['config']['db']) && is_array($GLOBALS['config']['db'])) {
        $c = $GLOBALS['config']['db'];
        $host = $host ?: ($c['host'] ?? $c['hostname'] ?? null);
        $name = $name ?: ($c['name'] ?? $c['database'] ?? null);
        $user = $user ?: ($c['user'] ?? $c['username'] ?? null);
        $pass = $pass ?? ($c['pass'] ?? $c['password'] ?? '');
    }

    if ($host && $name && $user !== null) {
        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                (string)$user,
                (string)$pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            return $pdo;
        } catch (Throwable $e) {}
    }

    // Поиск config.php с define
    foreach (pl_root_candidates() as $r) {
        foreach (['/config.php', '/config/config.php', '/includes/config.php'] as $rel) {
            $f = $r . $rel;
            if (!is_file($f)) continue;
            $src = @file_get_contents($f);
            if (!$src) continue;
            if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $host = $m[1];
            if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $name = $m[1];
            if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $user = $m[1];
            if (preg_match("/define\s*\(\s*['\"]DB_PASS['\"]\s*,\s*['\"]([^'\"]*)/", $src, $m)) $pass = $m[1];
            if (preg_match("/['\"]host['\"]\s*=>\s*['\"]([^'\"]+)/", $src, $m)) $host = $host ?: $m[1];
            if (preg_match("/['\"]database['\"]\s*=>\s*['\"]([^'\"]+)/", $src, $m)) $name = $name ?: $m[1];
            if (preg_match("/['\"]username['\"]\s*=>\s*['\"]([^'\"]+)/", $src, $m)) $user = $user ?: $m[1];
            if (preg_match("/['\"]password['\"]\s*=>\s*['\"]([^'\"]*)/", $src, $m)) $pass = $pass ?? $m[1];
        }
    }
    if ($host && $name && $user !== null) {
        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                (string)$user,
                (string)($pass ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            return $pdo;
        } catch (Throwable $e) {}
    }
    return null;
}

function pl_tables(PDO $pdo): array {
    static $tables = null;
    if ($tables !== null) return $tables;
    $tables = [];
    try {
        $st = $pdo->query('SHOW TABLES');
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
    } catch (Throwable $e) {}
    return $tables;
}

function pl_find_table(PDO $pdo, array $candidates): ?string {
    $tables = pl_tables($pdo);
    $lower = array_map('strtolower', $tables);
    foreach ($candidates as $c) {
        $i = array_search(strtolower($c), $lower, true);
        if ($i !== false) return $tables[$i];
    }
    // partial
    foreach ($candidates as $c) {
        foreach ($tables as $t) {
            if (stripos($t, $c) !== false) return $t;
        }
    }
    return null;
}

function pl_columns(PDO $pdo, string $table): array {
    $cols = [];
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    } catch (Throwable $e) {}
    return $cols;
}

function pl_pick_col(array $cols, array $want): ?string {
    $map = [];
    foreach ($cols as $c) $map[strtolower($c)] = $c;
    foreach ($want as $w) {
        if (isset($map[strtolower($w)])) return $map[strtolower($w)];
    }
    return null;
}

function pl_site_web(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $web = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/'); // up from /platforma
    if (preg_match('#/platforma$#i', $web)) {
        $web = preg_replace('#/platforma$#i', '', $web);
    }
    if ($web === '/' || $web === '.' || $web === '') return '';
    return $web;
}

function pl_normalize_channel(array $row, array $cols, string $site): array {
    // StreamLife real columns: id, slug, title, type, logo_url, cover_url, views,
    // last_stream_live, status, is_public, description, ...
    $idCol = pl_pick_col($cols, ['id', 'channel_id', 'cid']);
    $titleCol = pl_pick_col($cols, ['title', 'name', 'channel_name', 'stream_title', 'display_name']);
    $slugCol = pl_pick_col($cols, ['slug', 'key', 'username', 'login']);
    $thumbCol = pl_pick_col($cols, ['logo_url', 'cover_url', 'thumbnail', 'thumb', 'image', 'poster', 'cover', 'avatar', 'logo']);
    $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live', 'live', 'online', 'stream_status']);
    $viewCol = pl_pick_col($cols, ['views', 'viewers', 'viewer_count', 'watchers', 'online_count']);
    $typeCol = pl_pick_col($cols, ['type', 'category', 'kind', 'channel_type']);
    $statusCol = pl_pick_col($cols, ['status']);
    $publicCol = pl_pick_col($cols, ['is_public']);

    $id = $idCol ? ($row[$idCol] ?? '') : '';
    $title = $titleCol ? (string)($row[$titleCol] ?? 'Канал') : 'Канал';
    $slug = $slugCol ? (string)($row[$slugCol] ?? '') : '';

    $thumb = '';
    if ($thumbCol) $thumb = (string)($row[$thumbCol] ?? '');
    // cover fallback if logo empty
    if ($thumb === '') {
        $cover = pl_pick_col($cols, ['cover_url', 'logo_url']);
        if ($cover && $cover !== $thumbCol) $thumb = (string)($row[$cover] ?? '');
    }

    $live = false;
    if ($liveCol) {
        $v = $row[$liveCol];
        $live = ($v === 1 || $v === '1' || $v === true || $v === 'live' || $v === 'online' || $v === 'on');
    }
    $viewers = $viewCol ? (int)($row[$viewCol] ?? 0) : 0;

    $type = 'channel';
    if ($typeCol) {
        $t = strtolower(trim((string)($row[$typeCol] ?? '')));
        if ($t === 'tv' || strpos($t, 'tv') !== false || strpos($t, 'tele') !== false) $type = 'tv';
        elseif ($t === 'radio' || strpos($t, 'radio') !== false) $type = 'radio';
        elseif ($t !== '') $type = $t;
    }
    $tl = function_exists('mb_strtolower') ? mb_strtolower($title) : strtolower($title);
    if ($type === 'channel' || $type === '') {
        if (strpos($tl, 'радио') !== false || strpos($tl, 'radio') !== false || preg_match('/\bfm\b/u', $tl)) $type = 'radio';
        elseif (strpos($tl, 'телеканал') !== false || strpos($tl, 'тв') !== false || strpos($tl, 'tv') !== false) $type = 'tv';
    }

    $meta = $live ? 'В эфире' : ($type === 'tv' ? 'ТВ' : ($type === 'radio' ? 'Радио' : 'Канал'));
    if ($viewers > 0) $meta .= ' · ' . number_format($viewers);

    $key = $slug !== '' ? $slug : (string)$id;
    // StreamLife channel.php принимает slug (предпочтительно), id и c.
    // Красивый URL /channel/{slug} через .htaccess; фолбэки — query-параметры.
    if ($slug !== '') {
        $embed = $site . '/channel/' . rawurlencode($slug);
    } elseif ($id !== '') {
        $embed = $site . '/channel.php?id=' . rawurlencode((string)$id);
    } else {
        $embed = $site . '/';
    }
    $embed_alt = [];
    if ($slug !== '') {
        $embed_alt[] = $site . '/channel.php?slug=' . rawurlencode($slug);
        $embed_alt[] = $site . '/channel/' . rawurlencode($slug);
    }
    if ($id !== '') {
        $embed_alt[] = $site . '/channel.php?id=' . rawurlencode((string)$id);
    }
    if ($key !== '') {
        $embed_alt[] = $site . '/embed.php?slug=' . rawurlencode($key);
        $embed_alt[] = $site . '/embed.php?channel=' . rawurlencode($key);
    }
    $embed_alt = array_values(array_unique($embed_alt));

    if ($thumb !== '' && strpos($thumb, 'http') !== 0 && isset($thumb[0]) && $thumb[0] !== '/') {
        $thumb = $site . '/' . ltrim($thumb, '/');
    }

    return [
        'id' => $id !== '' ? $id : $key,
        'title' => $title,
        'type' => $type,
        'meta' => $meta,
        'live' => $live,
        'thumb' => $thumb,
        'embed' => $embed,
        'embed_alt' => $embed_alt,
        'slug' => $slug,
    ];
}

function pl_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=30');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
