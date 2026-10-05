<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/breakdown_save.php';
$u = require_role('support');
$sectors = accessible_sectors($u);
$sid = (int)($_GET['sector'] ?? ($sectors[0]['id'] ?? 0));
if (!$sectors || !can_access_sector($u, $sid)) { flash('Select one of your sectors.', 'error'); redirect('support/index.php'); }

handle_breakdown_create($u, 'support/records.php?sector=' . $sid);

page_header('Breakdowns', $u);
?>
<div class="flex items-center justify-between mb-4"><h1 class="text-2xl font-bold">Breakdowns</h1>
  <a class="text-sm text-blue-600" href="<?= url('support/index.php') ?>">← My sectors</a></div>
<?php
render_records($u, 'records.php');
page_footer();
