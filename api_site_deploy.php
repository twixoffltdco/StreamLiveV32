<?php
/**
 * Деплой мини-сайта по описанию + API-ключу StreamLive.
 * НЕ ИИ: шаблон HTML/JS, который ходит в /api.php с ключом пользователя.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_helpers.php';
require_login();
$__user = current_user();
deployed_services_ensure_schema();

function api_site_slugify(string $name): string {
  $s = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
  $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $s = strtr($s, $map);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = trim($s, '-') ?: 'app';
  return substr($s, 0, 40) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function api_site_build_html(string $title, string $description, string $apiKey, string $apiBase, array $widgets): string {
  $titleE = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $descE = htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $keyJs = json_encode($apiKey, JSON_UNESCAPED_UNICODE);
  $baseJs = json_encode(rtrim($apiBase, '/'), JSON_UNESCAPED_UNICODE);
  $widgetsJs = json_encode(array_values($widgets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  return '<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . $titleE . '</title>
<style>
:root{--bg:#0e1621;--card:#17212b;--text:#f5f5f5;--muted:#8b9aab;--accent:#2AABEE;--border:#243041}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text)}
header{padding:20px 16px;border-bottom:1px solid var(--border);background:var(--card)}
h1{margin:0 0 6px;font-size:22px}p.lead{margin:0;color:var(--muted);font-size:14px}
main{max-width:960px;margin:0 auto;padding:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px}
.card h2{margin:0 0 10px;font-size:15px;color:var(--accent)}
.item{padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.item:last-child{border-bottom:none}
.item a{color:var(--text);text-decoration:none}.item a:hover{color:var(--accent)}
.meta{color:var(--muted);font-size:11px;margin-top:2px}
.err{color:#f87171;font-size:13px}.ok{color:#4ade80;font-size:12px}
footer{text-align:center;padding:24px;color:var(--muted);font-size:12px}
</style>
</head>
<body>
<header>
  <h1>' . $titleE . '</h1>
  <p class="lead">' . $descE . '</p>
</header>
<main>
  <p class="ok" id="status">Загрузка данных через StreamLive API…</p>
  <div class="grid" id="root"></div>
</main>
<footer>Собрано из StreamLive API · шаблон + ваш ключ (не ИИ)</footer>
<script>
(function(){
  var API_BASE = ' . $baseJs . ';
  var API_KEY = ' . $keyJs . ';
  var WIDGETS = ' . $widgetsJs . ';
  var statusEl = document.getElementById("status");
  var root = document.getElementById("root");
  function apiUrl(type, extra) {
    var u = API_BASE + "/api.php?type=" + encodeURIComponent(type) + "&api_key=" + encodeURIComponent(API_KEY);
    if (extra) u += "&" + extra;
    return u;
  }
  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function itemLink(href, title, meta) {
    var d = el("div", "item");
    var a = el("a");
    a.href = href || "#";
    a.textContent = title || "—";
    d.appendChild(a);
    if (meta) d.appendChild(el("div", "meta", meta));
    return d;
  }
  function renderList(title, rows, mapFn) {
    var card = el("section", "card");
    card.appendChild(el("h2", null, title));
    if (!rows || !rows.length) {
      card.appendChild(el("div", "meta", "Нет данных"));
      root.appendChild(card);
      return;
    }
    rows.slice(0, 12).forEach(function(row) { card.appendChild(mapFn(row)); });
    root.appendChild(card);
  }
  Promise.all(WIDGETS.map(function(w) {
    return fetch(apiUrl(w.type, w.query || "limit=12"))
      .then(function(r){ return r.json(); })
      .then(function(j){ return { w: w, j: j }; })
      .catch(function(e){ return { w: w, j: { ok:false, error: String(e) } }; });
  })).then(function(results) {
    statusEl.textContent = "Данные загружены";
    results.forEach(function(pack) {
      var w = pack.w, j = pack.j;
      if (!j || !j.ok) {
        var c = el("section", "card");
        c.appendChild(el("h2", null, w.title || w.type));
        c.appendChild(el("div", "err", (j && j.error) ? j.error : "Ошибка API"));
        root.appendChild(c);
        return;
      }
      var data = j.data;
      if (Array.isArray(data)) {
        renderList(w.title || w.type, data, function(row) {
          if (w.type === "videos") {
            var href = row.slug ? (API_BASE + "/video/" + encodeURIComponent(row.slug)) : (API_BASE + "/video.php?id=" + row.id);
            return itemLink(href, row.title, (row.channel_title || "") + " · " + (row.views_count || 0) + " просм.");
          }
          if (w.type === "channels") {
            var href2 = row.slug ? (API_BASE + "/channel.php?slug=" + encodeURIComponent(row.slug)) : (API_BASE + "/channel.php?id=" + row.id);
            return itemLink(href2, row.title, (row.type || "") + " · " + (row.views || 0));
          }
          if (w.type === "resources") return itemLink(row.url || "#", row.title, row.summary || "");
          if (w.type === "forum") return itemLink(row.url || (API_BASE + "/forum_thread.php?id=" + (row.id || "")), row.title || "Тема", row.username || "");
          return itemLink("#", row.title || "item", "");
        });
      } else if (data && typeof data === "object") {
        var c2 = el("section", "card");
        c2.appendChild(el("h2", null, w.title || w.type));
        c2.appendChild(el("pre", "item", JSON.stringify(data, null, 2)));
        root.appendChild(c2);
      }
    });
  });
})();
</script>
</body>
</html>';
}


$error = null;
$success = null;
$previewUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $name = trim((string)($_POST['name'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $apiKey = trim((string)($_POST['api_key'] ?? ''));
  $wantVideos = !empty($_POST['w_videos']);
  $wantChannels = !empty($_POST['w_channels']);
  $wantForum = !empty($_POST['w_forum']);
  $wantResources = !empty($_POST['w_resources']);

  if ($name === '' || mb_strlen($name) < 2) {
    $error = 'Укажите название проекта';
  } elseif ($apiKey === '' || strlen($apiKey) < 8) {
    $error = 'Укажите API-ключ StreamLive (X-Api-Key / api_key)';
  } elseif (!$wantVideos && !$wantChannels && !$wantForum && !$wantResources) {
    $error = 'Выберите хотя бы один блок данных';
  } else {
    $limit = deployed_service_limit_for($__user);
    $active = deployed_service_active_count((int)$__user['id']);
    if ($active >= $limit) {
      $error = 'Лимит сервисов исчерпан (' . $active . '/' . $limit . ')';
    } else {
      $slug = api_site_slugify($name);
      $widgets = [];
      if ($wantVideos) $widgets[] = ['type' => 'videos', 'title' => 'Видео', 'query' => 'limit=12'];
      if ($wantChannels) $widgets[] = ['type' => 'channels', 'title' => 'Каналы ТВ/Радио', 'query' => 'limit=12'];
      if ($wantForum) $widgets[] = ['type' => 'forum', 'title' => 'Форум', 'query' => 'limit=12'];
      if ($wantResources) $widgets[] = ['type' => 'resources', 'title' => 'Ресурсы', 'query' => 'limit=12'];

      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $apiBase = $scheme . '://' . $host;

      $html = api_site_build_html($name, $description !== '' ? $description : ('Клиент API: ' . $name), $apiKey, $apiBase, $widgets);

      $dir = __DIR__ . '/services_data/' . $slug;
      if (!is_dir(__DIR__ . '/services_data')) @mkdir(__DIR__ . '/services_data', 0755, true);
      if (!is_dir($dir)) @mkdir($dir, 0755, true);
      $ok = @file_put_contents($dir . '/index.html', $html) !== false;
      if (!$ok) {
        $error = 'Не удалось записать файлы (права на services_data/)';
      } else {
        try {
          db()->prepare(
            'INSERT INTO deployed_services (user_id, name, description, repo_full_name, branch, slug, status, is_public, deployed_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())'
          )->execute([
            (int)$__user['id'],
            mb_substr($name, 0, 150),
            mb_substr($description, 0, 500),
            'api-builder/' . $slug,
            'main',
            $slug,
            'live',
            1,
          ]);
          $previewUrl = '/services_data/' . rawurlencode($slug) . '/index.html';
          $success = 'Сайт собран и задеплоен';
        } catch (Throwable $e) {
          $error = 'Файлы записаны, но БД не обновилась: проверьте deployed_services';
          $previewUrl = '/services_data/' . rawurlencode($slug) . '/index.html';
        }
      }
    }
  }
}

$pageTitle = 'Собрать сайт из API';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:720px;margin:24px auto">
  <h1>Собрать сайт из StreamLive API</h1>
  <p style="color:var(--text-dim);font-size:14px">
    Не ИИ. Укажите название, описание и API-ключ — система соберёт статический мини-сайт-клиент,
    который ходит в <code>/api.php</code> и показывает выбранные блоки (видео, каналы, форум, ресурсы).
  </p>

  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?>
      <?php if ($previewUrl): ?> · <a href="<?= e($previewUrl) ?>" target="_blank">открыть</a><?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" class="card" style="padding:16px">
    <?= csrf_field() ?>
    <label>Название проекта
      <input type="text" name="name" required maxlength="80" placeholder="Мой каталог стримов" style="width:100%;padding:8px;margin:6px 0 12px">
    </label>
    <label>Описание (как будет выглядеть / зачем)
      <textarea name="description" rows="3" maxlength="500" placeholder="Тёмная витрина видео и ТВ-каналов платформы для встраивания…" style="width:100%;padding:8px;margin:6px 0 12px"></textarea>
    </label>
    <label>API-ключ
      <input type="text" name="api_key" required placeholder="из /account или admin API keys" style="width:100%;padding:8px;margin:6px 0 12px" autocomplete="off">
    </label>
    <div style="margin-bottom:12px;font-size:14px">
      <div style="margin-bottom:6px;color:var(--text-dim)">Блоки на сайте:</div>
      <label><input type="checkbox" name="w_videos" value="1" checked> Видео</label>
      <label style="margin-left:12px"><input type="checkbox" name="w_channels" value="1" checked> ТВ / Радио</label>
      <label style="margin-left:12px"><input type="checkbox" name="w_forum" value="1"> Форум</label>
      <label style="margin-left:12px"><input type="checkbox" name="w_resources" value="1"> Ресурсы</label>
    </div>
    <button class="btn btn-primary" type="submit">Собрать и задеплоить</button>
    <a class="btn btn-outline" href="/api.php" target="_blank">Документация /api</a>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
