<?php
/**
 * Алиас каталога каналов.
 * Оболочка Платформа и часть ссылок ожидают /channels.php,
 * а в StreamLife каталог лежит в catalog.php — проксируем без 404/403.
 */
require_once __DIR__ . '/catalog.php';
