<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create_pack') {
    $title = trim($_POST['title'] ?? '');
    if (mb_strlen($title) < 2) {
      flash_set('error', 'Название пака — минимум 2 символа');
      redirect('/sticker_packs.php');
    }
    $slug = slugify($title);
    db()->prepare('INSERT INTO sticker_packs (owner_id, title, slug) VALUES (?, ?, ?)')
      ->execute([$__user['id'], $title, $slug]);
    flash_set('success', 'Пак создан! Теперь добавьте в него стикеры.');
    redirect('/sticker_packs.php?slug=' . urlencode($slug));
  } elseif ($action === 'add_sticker') {
    $packId = (int)($_POST['pack_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM sticker_packs WHERE id = ? AND owner_id = ?');
    $stmt->execute([$packId, $__user['id']]);
    $pack = $stmt->fetch();
    if (!$pack) { flash_set('error', 'Пак не найден'); redirect('/sticker_packs.php'); }

    $imageUrl = trim($_POST['image_url'] ?? '');
    $codeRaw = trim($_POST['code'] ?? '');
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL) || stripos($imageUrl, 'https://') !== 0) {
      flash_set('error', 'Нужна прямая https-ссылка на картинку стикера');
      redirect('/sticker_packs.php?slug=' . urlencode($pack['slug']));
    }
    $code = ':' . preg_replace('/[^a-z0-9_]/i', '', $codeRaw ?: ($pack['slug'] . '_' . random_int(100, 999))) . ':';
    try {
      db()->prepare('INSERT INTO sticker_pack_items (pack_id, code, image_url) VALUES (?, ?, ?)')->execute([$packId, $code, $imageUrl]);
      flash_set('success', 'Стикер добавлен: ' . $code);
    } catch (\Throwable $e) {
      flash_set('error', 'Такой код стикера уже занят (в любом паке) — придумайте другой');
    }
    redirect('/sticker_packs.php?slug=' . urlencode($pack['slug']));
  } elseif ($action === 'subscribe') {
    db()->prepare('INSERT IGNORE INTO sticker_pack_subscriptions (pack_id, user_id) VALUES (?, ?)')->execute([(int)$_POST['pack_id'], $__user['id']]);
    redirect('/sticker_packs.php');
  } elseif ($action === 'unsubscribe') {
    db()->prepare('DELETE FROM sticker_pack_subscriptions WHERE pack_id = ? AND user_id = ?')->execute([(int)$_POST['pack_id'], $__user['id']]);
    redirect('/sticker_packs.php');
  }
}

$slug = $_GET['slug'] ?? '';
$activePack = null;
$activeItems = [];
if ($slug) {
  $stmt = db()->prepare('SELECT sp.*, u.username FROM sticker_packs sp JOIN users u ON u.id = sp.owner_id WHERE sp.slug = ?');
  $stmt->execute([$slug]);
  $activePack = $stmt->fetch();
  if ($activePack) {
    $stmt = db()->prepare('SELECT * FROM sticker_pack_items WHERE pack_id = ? ORDER BY id ASC');
    $stmt->execute([$activePack['id']]);
    $activeItems = $stmt->fetchAll();
  }
}

$packs = db()->query(
  "SELECT sp.*, u.username, (SELECT COUNT(*) FROM sticker_pack_items WHERE pack_id = sp.id) AS item_count,
     (SELECT COUNT(*) FROM sticker_pack_subscriptions WHERE pack_id = sp.id) AS sub_count
   FROM sticker_packs sp JOIN users u ON u.id = sp.owner_id ORDER BY sp.id DESC LIMIT 60"
)->fetchAll();

$mySubs = array_column(
  (function () use ($__user) {
    $stmt = db()->prepare('SELECT pack_id FROM sticker_pack_subscriptions WHERE user_id = ?');
    $stmt->execute([$__user['id']]);
    return $stmt->fetchAll();
  })(),
  'pack_id'
);

$pageTitle = 'Стикер-паки';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h2 style="margin:24px 0 6px">🎨 Стикер-паки</h2>
  <p style="color:var(--text-dim);font-size:13px;margin-bottom:20px">
    Создайте свой пак стикеров (просто вставляйте прямые ссылки на картинки), поделитесь кодом
    вида <code>:код:</code> — он заработает в чате и комментариях любого канала, как в Telegram.
  </p>

  <?php if ($activePack): ?>
    <div class="form-card form-wide" style="margin-bottom:20px">
      <h3><?= e($activePack['title']) ?> <span style="color:var(--text-dim);font-size:12px">от <?= e($activePack['username']) ?></span></h3>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0">
        <?php foreach ($activeItems as $it): ?>
          <div style="text-align:center;width:70px">
            <img src="<?= e($it['image_url']) ?>" style="width:48px;height:48px;object-fit:contain;border-radius:8px;background:var(--bg-elevated)" onerror="this.style.opacity='0.2'">
            <div style="font-size:10px;color:var(--text-dim);word-break:break-all"><?= e($it['code']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$activeItems): ?><p style="color:var(--text-dim);font-size:13px">В паке пока нет стикеров.</p><?php endif; ?>
      </div>
      <?php if ((int)$activePack['owner_id'] === (int)$__user['id']): ?>
        <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:14px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_sticker">
          <input type="hidden" name="pack_id" value="<?= (int)$activePack['id'] ?>">
          <div style="flex:1;min-width:220px"><label>Прямая ссылка на картинку (https)</label><input type="url" name="image_url" required placeholder="https://.../sticker.png"></div>
          <div><label>Код (необязательно)</label><input type="text" name="code" placeholder="wow" style="width:120px"></div>
          <button class="btn btn-primary" type="submit">Добавить стикер</button>
        </form>
      <?php endif; ?>
      <a href="/sticker_packs.php" style="display:inline-block;margin-top:14px;font-size:12.5px;color:var(--accent-2)">← ко всем пакам</a>
    </div>
  <?php endif; ?>

  <div class="form-card form-wide" style="margin-bottom:20px">
    <h3>Создать новый пак</h3>
    <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_pack">
      <div style="flex:1;min-width:200px"><label>Название пака</label><input type="text" name="title" required maxlength="100" placeholder="Например: Мемы StreamLive"></div>
      <button class="btn btn-primary" type="submit">Создать</button>
    </form>
  </div>

  <h3 style="margin:24px 0 12px">Все паки</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
    <?php foreach ($packs as $p): $subscribed = in_array($p['id'], $mySubs); ?>
      <div class="form-card" style="margin:0">
        <a href="/sticker_packs.php?slug=<?= e($p['slug']) ?>" style="color:inherit;text-decoration:none">
          <b><?= e($p['title']) ?></b>
        </a>
        <div style="color:var(--text-dim);font-size:12px;margin:4px 0 10px">от <?= e($p['username']) ?> · <?= (int)$p['item_count'] ?> стикеров · <?= (int)$p['sub_count'] ?> добавили</div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="pack_id" value="<?= (int)$p['id'] ?>">
          <?php if ($subscribed): ?>
            <input type="hidden" name="action" value="unsubscribe">
            <button class="btn btn-outline btn-sm" type="submit">✓ Добавлен</button>
          <?php else: ?>
            <input type="hidden" name="action" value="subscribe">
            <button class="btn btn-primary btn-sm" type="submit">Добавить себе</button>
          <?php endif; ?>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$packs): ?><p style="color:var(--text-dim)">Пока нет ни одного пака — создайте первый!</p><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
