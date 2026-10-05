<?php
require __DIR__ . '/includes/bootstrap.php';
$u = current_user();
redirect($u ? home_for($u['role']) : 'login.php');
