<?php
// Вставочный блок для profile.php. Требует $profileUser (уже есть в profile.php).
// Как подключить: в profile.php после `require_once __DIR__ . '/includes/auth.php';`
// добавить `require_once __DIR__ . '/includes/gamification.php';`, а в HTML внутри
// .profile-header, сразу после блока .profile-stats — вставить php-инклюд этого файла
// (include __DIR__ . '/includes/profile_gamification_block.php')

$__g = get_user_gamification((int)$profileUser['id']);
if ($__g): ?>
  <div class="profile-gamification" style="margin-top:12px;padding:12px;border-radius:12px;background:var(--card-bg,rgba(255,255,255,0.04))">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
      <b><?= e($__g['title']) ?></b>
      <a href="/rating" style="font-size:13px;color:var(--accent-2)">место в рейтинге: #<?= get_user_rating_place((int)$profileUser['id']) ?></a>
    </div>
    <div style="height:6px;background:rgba(255,255,255,0.08);border-radius:4px;margin:8px 0;overflow:hidden">
      <div style="height:100%;width:<?= (int)$__g['progress_percent'] ?>%;background:var(--accent-2)"></div>
    </div>
    <div style="font-size:12px;color:var(--text-dim)">
      <?= (int)$__g['xp'] ?> XP
      <?= $__g['next_title'] ? ' · до «' . e($__g['next_title']) . '» ещё ' . ((int)$__g['next_min'] - (int)$__g['xp']) . ' XP' : ' · максимальный ранг' ?>
      · круг №<?= (int)$__g['cycle_number'] ?>, день <?= (int)$__g['total_active_days'] ?>/4000
      <?= $__g['login_streak_days'] > 1 ? ' · серия входов: ' . (int)$__g['login_streak_days'] . ' дн.' : '' ?>
    </div>
  </div>
<?php endif; ?>
