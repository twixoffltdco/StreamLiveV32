<?php
/**
 * Вставь этот кусок в footer.php StreamLife (перед закрывающим </footer> или в список ссылок).
 * Не конфликтует с существующим кодом.
 *
 * Пример:
 *   <?php include __DIR__ . '/platforma/footer_snippet.php'; ?>
 *
 * Или просто скопируй HTML ниже.
 */
$pl_base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($pl_base === '' || $pl_base === '.') $pl_base = '';
// если footer уже в подпапке — скорректируй путь:
$pl_href = $pl_base . '/platforma.php';
?>
<!-- Platforma link -->
<a href="<?= htmlspecialchars($pl_href) ?>" class="footer-link platforma-link" style="margin-left:12px;color:#3ea6ff;font-weight:500;">
  Платформа
</a>
