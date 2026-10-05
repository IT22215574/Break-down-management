<?php
require __DIR__ . '/../includes/bootstrap.php';
redirect('breakdown.php?id=' . (int)($_GET['id'] ?? 0));
