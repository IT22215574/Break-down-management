<?php
require __DIR__ . '/includes/bootstrap.php';
$pdo->prepare('DELETE FROM edit_locks WHERE owner_session=?')->execute([session_id()]);
$_SESSION = [];
session_destroy();
redirect('login.php');
