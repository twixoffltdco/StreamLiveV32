<?php
/**
 * Видимость тем/постов форума (pending только автор и staff).
 * Устойчиво к отсутствию колонки mod_status на любом хостинге.
 */
function forum_is_staff(?array $u): bool {
  if (!$u) return false;
  if (in_array(($u['role'] ?? ''), ['admin', 'moderator'], true)) return true;
  if (function_exists('is_forum_moderator') && is_forum_moderator($u)) return true;
  if (function_exists('cmod_is_moderator') && cmod_is_moderator($u)) return true;
  return false;
}

function forum_mod_status_ok_sql(string $alias = 't'): string {
  // публично: нет статуса / approved; pending/rejected скрыты на уровне SQL только если колонка есть — вызывающий ловит ошибку
  return "( {$alias}.mod_status IS NULL OR {$alias}.mod_status = '' OR {$alias}.mod_status = 'approved' OR {$alias}.mod_status = '0' )";
}

function forum_row_visible(array $row, ?array $user, string $userIdKey = 'user_id'): bool {
  if (!empty($row['is_deleted'])) return false;
  $ms = strtolower(trim((string)($row['mod_status'] ?? 'approved')));
  if ($ms === 'approved' || $ms === '' || $ms === '0') return true;
  if (forum_is_staff($user)) return true;
  $uid = (int)($user['id'] ?? 0);
  return $uid > 0 && (int)($row[$userIdKey] ?? 0) === $uid;
}

function forum_filter_rows(array $rows, ?array $user, string $userIdKey = 'user_id'): array {
  return array_values(array_filter($rows, static function ($r) use ($user, $userIdKey) {
    return forum_row_visible($r, $user, $userIdKey);
  }));
}
