<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/breakdown_save.php';
$u = require_role('admin');
handle_breakdown_create($u, 'admin/records.php?sector=' . (int)($_POST['sector_id'] ?? 0));
page_header('Breakdowns', $u);
echo '<h1 class="text-2xl font-bold mb-4">All breakdowns</h1>';
render_records($u, 'records.php');
page_footer();
