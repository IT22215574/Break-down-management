<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('user');
page_header('Breakdowns', $u);
echo '<h1 class="text-2xl font-bold mb-4">Breakdown records</h1>';
render_records($u, 'index.php');
page_footer();
