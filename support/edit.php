<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/breakdown_save.php';
$u = require_role('support');
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM breakdowns WHERE id=?'); $st->execute([$id]);
$row = $st->fetch();
if (!$row || !can_access_sector($u, (int)$row['sector_id'])) { http_response_code(404); exit('Not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['delete'])) {
        $pdo->prepare('DELETE FROM breakdowns WHERE id=?')->execute([$id]);
        flash('Record deleted.'); redirect('support/records.php?sector=' . $row['sector_id']);
    }
    [$d, $err] = read_breakdown_post($u);
    if ($err) { flash($err, 'error'); redirect('support/edit.php?id=' . $id); }
    $pdo->prepare('UPDATE breakdowns SET sector_id=:sector_id,system_name=:system_name,description=:description,occurred_at=:occurred_at,
        fixed_by=:fixed_by,client_name=:client_name,status=:status,note=:note WHERE id=:id')->execute($d + ['id' => $id]);
    flash('Record updated.'); redirect('support/records.php?sector=' . $d['sector_id']);
}
$sectors = accessible_sectors($u);
page_header('Edit breakdown', $u);
$heading = 'Edit breakdown #' . $id; $submit = 'Update record';
include __DIR__ . '/../includes/breakdown_form.php';
echo '<a class="text-sm text-blue-600" href="' . e(url('support/records.php?sector=' . $row['sector_id'])) . '">← Back</a>';
page_footer();
