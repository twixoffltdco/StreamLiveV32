<?php
/**
 * Подключать после functions.php: принимает csrf и csrf_token.
 * Не ломает штатный csrf_verify — патчит POST до проверки, если нужно.
 */
if (!empty($_POST) && empty($_POST['csrf_token']) && !empty($_POST['csrf'])) {
  $_POST['csrf_token'] = $_POST['csrf'];
}
