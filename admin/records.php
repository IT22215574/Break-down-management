<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');
page_header('Breakdowns', $u);
echo '<h1 class="text-2xl font-bold mb-4">All breakdowns</h1>';
render_records($u, 'records.php');
page_footer();
