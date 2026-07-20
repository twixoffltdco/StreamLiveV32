<?php
require_once __DIR__ . '/../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  logout_user();
}
redirect('/');
