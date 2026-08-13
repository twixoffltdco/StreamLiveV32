<?php
$pageTitle = 'StreamLive бренды';
require_once dirname(__DIR__) . '/includes/auth.php';
if (is_file(dirname(__DIR__) . '/includes/partners.php')) {
  require_once dirname(__DIR__) . '/includes/partners.php';
}
require_login();
brands_ensure_schema();

// Управление только с личного аккаунта
if (!empty($_SESSION['brand_act_as'])) {
  brands_switch(null);
}
$realId = brands_real_user_id();
$real = brands_real_user();
if (!$real || !empty($real['is_brand'])) {
  flash_set('error', 'Войдите личным аккаунтом');
  redirect('/auth/login.php');
}

$max = brands_max_for_user($realId);
$owned = brands_owned_count($realId);
$active = brands_active_id();
$list = brands_list_for_user($realId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) {
    try { csrf_verify(); } catch (Throwable $e) {}
  }
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create') {
    $res = brands_create($realId, (string)($_POST['username'] ?? ''), (string)($_POST['display'] ?? ''));
    flash_set(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? ('Бренд @' . $res['username'] . ' создан') : ($res['error'] ?? 'Ошибка'));
    redirect('/brands/');
  }

  if ($action === 'add_member') {
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $uname = trim((string)($_POST['member_username'] ?? ''));
    $role = (string)($_POST['role'] ?? 'editor');
    $st = db()->prepare('SELECT id FROM users WHERE username = ? AND (is_brand IS NULL OR is_brand = 0) AND (deleted_at IS NULL)');
    $st->execute([$uname]);
    $mid = (int)($st->fetchColumn() ?: 0);
    $res = brands_add_member($brandId, $mid, $role);
    flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Участник добавлен' : ($res['error'] ?? 'Ошибка'));
    redirect('/brands/');
  }

  if ($action === 'transfer') {
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $uname = trim((string)($_POST['new_owner'] ?? ''));
    $st = db()->prepare('SELECT id FROM users WHERE username = ? AND (is_brand IS NULL OR is_brand = 0) AND (deleted_at IS NULL)');
    $st->execute([$uname]);
    $nid = (int)($st->fetchColumn() ?: 0);
    $res = brands_transfer_ownership($brandId, $nid);
    flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Владелец передан @' . $uname : ($res['error'] ?? 'Ошибка'));
    redirect('/brands/');
  }

  if ($action === 'delete_brand') {
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $confirm = (string)($_POST['confirm'] ?? '');
    if ($confirm !== 'DELETE') {
      flash_set('error', 'Для удаления введите DELETE');
      redirect('/brands/');
    }
    $res = brands_delete($brandId, 'user_request');
    flash_set($res['ok'] ? 'success' : 'error', $res['ok']
      ? ('Бренд удалён. Данные хранятся 60 дней (до ' . ($res['purge_at'] ?? '') . '), затем удаляются')
      : ($res['error'] ?? 'Ошибка'));
    redirect('/brands/');
  }
}

require_once dirname(__DIR__) . '/includes/header.php';
$flash = function_exists('flash_get') ? flash_get() : [];
$premium = $max >= BRANDS_MAX_PREMIUM;
?>
<style>
.brands-wrap{max-width:720px;margin:24px auto;padding:0 16px}
.brands-wrap h1{font-size:22px;font-weight:900;margin:0 0 8px}
.brands-wrap .sub{color:var(--text-dim,#94a3b8);margin:0 0 20px;line-height:1.5}
.brand-card{background:var(--card,#1e293b);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px 16px;margin-bottom:10px}
.brand-card .name{font-weight:800}
.brand-card .meta{font-size:12px;color:#94a3b8;margin-top:4px}
.btn-b{display:inline-block;padding:8px 12px;border-radius:10px;font-weight:800;text-decoration:none;border:0;cursor:pointer;font-size:13px}
.btn-primary{background:#00a2ff;color:#fff}
.btn-ghost{background:#334155;color:#fff}
.btn-danger{background:#7f1d1d;color:#fecaca}
.form-card{background:var(--card,#1e293b);border-radius:14px;padding:16px;margin-top:20px}
.form-card label{display:block;font-size:13px;margin:10px 0 4px}
.form-card input,.form-card select{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#fff;box-sizing:border-box}
.badge-on{background:#022c22;color:#4ade80;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:700}
.badge-prem{background:#1e3a5f;color:#93c5fd;font-size:11px;padding:2px 8px;border-radius:999px;font-weight:700}
.row-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
</style>
<div class="brands-wrap">
  <h1>StreamLive бренды</h1>
  <p class="sub">
    Как организации на GitHub. Лимит: <b><?= (int)$owned ?>/<?= (int)$max ?></b>
    <?php if ($premium): ?>
      <span class="badge-prem">премиум до 50</span>
    <?php else: ?>
      · галочка или <a href="/partners_welcome.php">партнёрский промокод</a> → до <?= BRANDS_MAX_PREMIUM ?>
    <?php endif; ?>
    <br>Сейчас вы: <b>@<?= e($real['username']) ?></b>
    <?php if ($active): ?> · активен бренд #<?= (int)$active ?><?php endif; ?>
  </p>

  <?php foreach ($flash as $type => $msg): ?>
    <p style="padding:10px;border-radius:10px;background:<?= $type==='error'?'#450a0a':'#052e16' ?>"><?= e(is_array($msg)?implode(', ',$msg):(string)$msg) ?></p>
  <?php endforeach; ?>

  <?php if (!$list): ?>
    <p class="sub">Пока нет брендов — создайте первый.</p>
  <?php endif; ?>

  <?php foreach ($list as $b):
    $role = brands_role($realId, (int)$b['id']) ?? '—';
  ?>
    <div class="brand-card">
      <div class="name">@<?= e($b['username']) ?>
        <?php if (!empty($b['brand_verified'])): ?><span title="Бренд">✓</span><?php endif; ?>
        <?php if ($active === (int)$b['id']): ?><span class="badge-on">активен</span><?php endif; ?>
      </div>
      <div class="meta">роль: <?= e($role) ?>
        · <a href="/profile.php?username=<?= e(urlencode($b['username'])) ?>">профиль</a>
      </div>
      <div class="row-actions">
        <?php if ($active === (int)$b['id']): ?>
          <a class="btn-b btn-ghost" href="/brands/switch.php?to=0">Личный аккаунт</a>
        <?php else: ?>
          <a class="btn-b btn-primary" href="/brands/switch.php?to=<?= (int)$b['id'] ?>">Переключиться</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($owned < $max): ?>
  <div class="form-card">
    <h2 style="margin:0 0 8px;font-size:16px">Создать бренд</h2>
    <form method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">
      <label>Ник бренда (латиница)</label>
      <input name="username" required minlength="3" maxlength="24" pattern="[A-Za-z0-9_]+" placeholder="MyBrand">
      <label>Отображаемое имя</label>
      <input name="display" maxlength="80" placeholder="Мой бренд">
      <button class="btn-b btn-primary" style="margin-top:14px" type="submit">Создать</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($list): ?>
  <div class="form-card">
    <h2 style="margin:0 0 8px;font-size:16px">Команда (admin / editor)</h2>
    <form method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="add_member">
      <label>Бренд</label>
      <select name="brand_id">
        <?php foreach ($list as $b): ?>
          <option value="<?= (int)$b['id'] ?>">@<?= e($b['username']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Ник пользователя</label>
      <input name="member_username" required placeholder="username">
      <label>Роль</label>
      <select name="role"><option value="editor">editor</option><option value="admin">admin</option></select>
      <button class="btn-b btn-ghost" style="margin-top:14px" type="submit">Добавить</button>
    </form>
  </div>

  <div class="form-card">
    <h2 style="margin:0 0 8px;font-size:16px">Передать владельца</h2>
    <p class="sub" style="margin-bottom:8px">Только текущий owner. Вы станете admin, новый пользователь — owner.</p>
    <form method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="transfer">
      <label>Бренд</label>
      <select name="brand_id">
        <?php foreach ($list as $b): ?>
          <?php if (brands_role($realId, (int)$b['id']) === 'owner'): ?>
            <option value="<?= (int)$b['id'] ?>">@<?= e($b['username']) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <label>Ник нового владельца</label>
      <input name="new_owner" required placeholder="username">
      <button class="btn-b btn-primary" style="margin-top:14px" type="submit">Передать</button>
    </form>
  </div>

  <div class="form-card">
    <h2 style="margin:0 0 8px;font-size:16px;color:#fca5a5">Удалить бренд</h2>
    <p class="sub" style="margin-bottom:8px">Данные хранятся <b>60 дней</b>, затем удаляются. Введите <code>DELETE</code> для подтверждения.</p>
    <form method="post" onsubmit="return confirm('Удалить бренд? Данные 60 дней, потом purge.');">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="delete_brand">
      <label>Бренд</label>
      <select name="brand_id">
        <?php foreach ($list as $b): ?>
          <?php if (brands_role($realId, (int)$b['id']) === 'owner'): ?>
            <option value="<?= (int)$b['id'] ?>">@<?= e($b['username']) ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>
      <label>Подтверждение</label>
      <input name="confirm" required placeholder="DELETE" autocomplete="off">
      <button class="btn-b btn-danger" style="margin-top:14px" type="submit">Удалить бренд</button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
