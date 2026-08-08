<?php
/** Показ премьер во вкладке Видео: до/во время + повтор 00–05 МСК */
function premiere_videos_sql_extra(string $alias = 'v'): string {
  // безопасный кусок WHERE: published обычные ИЛИ активная/скоро премьера
  return " AND (
    (COALESCE({$alias}.is_premiere,0) = 0 AND COALESCE({$alias}.status,'published') IN ('published',''))
    OR (
      COALESCE({$alias}.is_premiere,0) = 1
      AND {$alias}.premiere_at IS NOT NULL
      AND (
        {$alias}.premiere_end_at IS NULL
        OR {$alias}.premiere_end_at >= NOW()
        OR (TIME(CONVERT_TZ(NOW(), @@session.time_zone, '+03:00')) >= '00:00:00'
            AND TIME(CONVERT_TZ(NOW(), @@session.time_zone, '+03:00')) < '05:00:00'
            AND {$alias}.premiere_end_at >= DATE_SUB(NOW(), INTERVAL 1 DAY))
      )
    )
  )";
}

function premiere_should_show_in_videos(array $v): bool {
  if (empty($v['is_premiere']) || empty($v['premiere_at'])) {
    return true;
  }
  $at = (int)strtotime((string)$v['premiere_at']);
  $end = !empty($v['premiere_end_at']) ? (int)strtotime((string)$v['premiere_end_at']) : ($at + 7200);
  $now = time();
  if ($now <= $end) return true;
  // replay 00-05 MSK
  try {
    $msk = new DateTime('now', new DateTimeZone('Europe/Moscow'));
    $h = (int)$msk->format('G');
    if ($h < 5 && $end >= $now - 86400) return true;
  } catch (Throwable $e) {}
  return false;
}
