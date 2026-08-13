<?php
/** Подключать точечно: bp_hooks + battle_pass */
if (!function_exists('bp_hook_forum_post')) {
  function bp_hook_forum_post(int $userId): void {
    if (!function_exists('bp_progress_task')) {
      $f = __DIR__ . '/battle_pass.php';
      if (is_file($f)) require_once $f;
    }
    if (function_exists('bp_progress_task')) bp_progress_task($userId, 'forum_post', 1);
  }
  function bp_hook_forum_thread(int $userId): void {
    if (!function_exists('bp_progress_task')) {
      $f = __DIR__ . '/battle_pass.php';
      if (is_file($f)) require_once $f;
    }
    if (function_exists('bp_progress_task')) bp_progress_task($userId, 'forum_thread', 1);
  }
}
