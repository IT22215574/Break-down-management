<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/breakdown_save.php';
$u = require_role('admin', 'support', 'user');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, c.name AS company_name, t.name AS technician_name FROM breakdowns b LEFT JOIN companies c ON c.id=b.company_id LEFT JOIN technicians t ON t.id=b.technician_id WHERE b.id=?'); $st->execute([$id]);
$row = $st->fetch();
if (!$row || !can_access_sector($u, (int)$row['sector_id'])) { http_response_code(404); exit('Not found'); }
$canEdit = in_array($u['role'], ['admin', 'support'], true);
$back = ['admin' => 'admin/records.php', 'support' => 'support/records.php', 'user' => 'user/index.php'][$u['role']] . '?sector=' . $row['sector_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!empty($_POST['delete'])) {
        require_edit_lock('breakdown', $id, 'breakdown.php?id=' . $id);
        $pdo->prepare('DELETE FROM breakdowns WHERE id=?')->execute([$id]);
        release_edit_lock('breakdown', $id, (string)$_POST['edit_lock_token']);
        flash('Record deleted.'); redirect($back);
    }
    require_edit_lock('breakdown', $id, 'breakdown.php?id=' . $id);
    [$d, $err] = read_breakdown_post($u);
    if ($err) { flash($err, 'error'); redirect('breakdown.php?id=' . $id); }
    $technicianFields = $u['role'] === 'admin' ? ',technician_id=:technician_id,technician_required=:technician_required' : '';
    $pdo->prepare('UPDATE breakdowns SET sector_id=:sector_id,company_id=:company_id,system_name=:system_name,description=:description,occurred_at=:occurred_at,
        fixed_by=:fixed_by,client_name=:client_name,status=:status,note=:note' . $technicianFields . ' WHERE id=:id')->execute($d + ['id' => $id]);
    release_edit_lock('breakdown', $id, (string)$_POST['edit_lock_token']);
    flash('Changes saved.'); redirect('breakdown.php?id=' . $id);
}

page_header($row['system_name'], $u);
echo '<a class="text-sm text-blue-600" href="' . e(url($back)) . '">&larr; Back to breakdowns</a><div class="my-4"></div>';
if ($canEdit) {
    $sectors = accessible_sectors($u);
    $technicians = $u['role'] === 'admin' ? active_technicians() : [];
    if ($u['role'] !== 'admin' && !empty($row['technician_required'])) {
        echo '<p class="mb-3 text-sm text-slate-600">Assigned technician: ' . e($row['technician_name'] ?? 'Not assigned') . '</p>';
    }
    $heading = 'Breakdown #' . $id; $submit = 'Save changes';
    include __DIR__ . '/includes/breakdown_form.php';
} else { ?>
<div class="bg-white rounded shadow p-4 grid md:grid-cols-3 gap-4 text-sm">
  <h1 class="md:col-span-3 text-xl font-bold"><?= e($row['system_name']) ?> <?= status_badge($row['status']) ?></h1>
  <div><div class="text-xs text-slate-500">Company</div><?= e($row['company_name'] ?? '—') ?></div>
  <div><div class="text-xs text-slate-500">Date &amp; time</div><?= e(date('Y-m-d H:i', strtotime($row['occurred_at']))) ?></div>
  <div><div class="text-xs text-slate-500">Contacted by (client side)</div><?= e($row['client_name']) ?></div>
  <div><div class="text-xs text-slate-500">Fixed by (company side)</div><?= e($row['fixed_by']) ?></div>
  <?php if (!empty($row['technician_required'])): ?><div><div class="text-xs text-slate-500">Assigned technician</div><?= e($row['technician_name'] ?? 'Not assigned') ?></div><?php endif; ?>
  <div class="md:col-span-3"><div class="text-xs text-slate-500">Description</div><?= nl2br(e($row['description'])) ?></div>
  <div class="md:col-span-3"><div class="text-xs text-slate-500">Note</div><?= nl2br(e($row['note'])) ?></div>
</div>
<?php }
page_footer();
