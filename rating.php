<?php
if (is_file(__DIR__ . '/includes/vibe.php')) require_once __DIR__ . '/includes/vibe.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gamification.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare('SELECT id, username, avatar, gravatar_email, xp, cycle_number, total_active_days, is_verified FROM users WHERE is_banned = 0 ORDER BY xp DESC, id ASC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$top = $stmt->fetchAll();

$totalUsers = (int)db()->query('SELECT COUNT(*) FROM users WHERE is_banned = 0')->fetchColumn();

$pageTitle = 'Рейтинг пользователей';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h1>Рейтинг пользователей</h1>
  <p style="color:var(--text-dim)">Место определяется суммарным опытом (XP). Опыт начисляется за ежедневный вход, серия дней подряд даёт бонус.</p>

  <table class="admin-table" style="width:100%">
    <thead><tr><th>#</th><th>Пользователь</th><th>Ранг</th><th>XP</th><th>Круг</th></tr></thead>
    <tbody>
      <?php foreach ($top as $i => $u): $rank = get_rank_for_xp((int)$u['xp']); ?>
      <tr>
        <td><?= $offset + $i + 1 ?></td>
        <td>
          <a href="/profile.php?username=<?= urlencode($u['username']) ?>" style="display:flex;align-items:center;gap:8px;color:var(--accent-2);text-decoration:none">
            <?php
              $__av = function_exists('user_avatar_url') ? user_avatar_url($u, 64) : (trim((string)($u['avatar'] ?? '')) ?: '/assets/img/avatar-placeholder.png');
              $__fc = function_exists('vibe_nick_frame_class') ? vibe_nick_frame_class($u) : '';
            ?>
            <span class="vibe-avatar-wrap<?= $__fc ? ' ' . e($__fc) : '' ?>" style="width:28px;height:28px">
              <img src="<?= e($__av) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='https://www.gravatar.com/avatar/?d=mp&s=64'">
            </span>
            @<?= e($u['username']) ?>
          </a>
        </td>
        <td><?= e($rank['title']) ?></td>
        <td><?= (int)$u['xp'] ?></td>
        <td>№<?= (int)$u['cycle_number'] ?> · день <?= (int)$u['total_active_days'] ?>/4000</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="pagination" style="margin-top:16px;display:flex;gap:8px">
    <?php if ($page > 1): ?><a class="btn btn-outline btn-sm" href="?page=<?= $page - 1 ?>">← Назад</a><?php endif; ?>
    <?php if ($offset + $perPage < $totalUsers): ?><a class="btn btn-outline btn-sm" href="?page=<?= $page + 1 ?>">Далее →</a><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
