<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/breakdown_save.php';
$u = require_role('support');
$sectors = accessible_sectors($u);
$sid = (int)($_GET['sector'] ?? ($sectors[0]['id'] ?? 0));
if (!$sectors || !can_access_sector($u, $sid)) { flash('Select one of your sectors.', 'error'); redirect('support/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$d, $err] = read_breakdown_post($u);
    if ($err) flash($err, 'error');
    else {
        $pdo->prepare('INSERT INTO breakdowns (sector_id,system_name,description,occurred_at,fixed_by,client_name,status,note,created_by)
            VALUES (:sector_id,:system_name,:description,:occurred_at,:fixed_by,:client_name,:status,:note,:by)')
            ->execute($d + ['by' => $u['id']]);
        flash('Breakdown recorded.');
        $sid = $d['sector_id'];
    }
    redirect('support/records.php?sector=' . $sid);
}

$sector = array_values(array_filter($sectors, fn($s) => $s['id'] == $sid))[0];
$st = $pdo->prepare('SELECT b.*, ? AS sector_name FROM breakdowns b WHERE sector_id=? ORDER BY occurred_at DESC, id DESC');
$st->execute([$sector['name'], $sid]);
$row = ['sector_id' => $sid, 'system_name' => '', 'client_name' => '', 'fixed_by' => $u['name'], 'description' => '',
    'note' => '', 'status' => 'open', 'occurred_at' => date('Y-m-d H:i:s')];
page_header($sector['name'], $u);
?>
<div class="flex items-center justify-between mb-4"><h1 class="text-2xl font-bold"><?= e($sector['name']) ?></h1>
  <a class="text-sm text-blue-600" href="<?= url('support/index.php') ?>">← My sectors</a></div>
<?php
$heading = 'Record a breakdown'; $submit = 'Save record';
include __DIR__ . '/../includes/breakdown_form.php';
records_table($st->fetchAll(), true);
page_footer();
