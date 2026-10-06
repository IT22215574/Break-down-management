<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/breakdown_save.php';
$u = require_role('admin', 'support', 'user');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT b.*, c.name AS company_name, (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \') FROM breakdown_technicians bt JOIN technicians t ON t.id=bt.technician_id WHERE bt.breakdown_id=b.id) AS technician_name FROM breakdowns b LEFT JOIN companies c ON c.id=b.company_id WHERE b.id=?'); $st->execute([$id]);
$row = $st->fetch();
if ($row) $row['technician_ids'] = breakdown_technician_ids($id);
if (!$row || !can_access_sector($u, (int)$row['sector_id'])) { http_response_code(404); exit('Not found'); }
$canEdit = in_array($u['role'], ['admin', 'support'], true);
$back = ['admin' => 'admin/records.php', 'support' => 'support/records.php', 'user' => 'user/index.php'][$u['role']] . '?sector=' . $row['sector_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && ($_POST['action'] ?? '') === 'status') {
    $ok = isset(STATUSES[$_POST['status'] ?? '']);
    if ($ok) $pdo->prepare('UPDATE breakdowns SET status=? WHERE id=?')->execute([$_POST['status'], $id]);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        exit(json_encode(['ok' => $ok]));
    }
    flash($ok ? 'Status updated.' : 'Invalid status.', $ok ? 'success' : 'error'); redirect('breakdown.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!empty($_POST['delete'])) {
        require_edit_lock('breakdown', $id, 'breakdown.php?id=' . $id . '&edit=1');
        $pdo->prepare('DELETE FROM breakdowns WHERE id=?')->execute([$id]);
        release_edit_lock('breakdown', $id, (string)$_POST['edit_lock_token']);
        flash('Record deleted.'); redirect($back);
    }
    require_edit_lock('breakdown', $id, 'breakdown.php?id=' . $id . '&edit=1');
    [$d, $err] = read_breakdown_post($u);
    if ($err) { flash($err, 'error'); redirect('breakdown.php?id=' . $id . '&edit=1'); }
    $technicianFields = $u['role'] === 'admin' ? ',technician_id=:technician_id,technician_required=:technician_required' : '';
    $technicianIds = $d['technician_ids'] ?? null; unset($d['technician_ids']);
    if ($u['role'] === 'admin') $d['technician_id'] = $technicianIds[0] ?? null;
    $pdo->prepare('UPDATE breakdowns SET sector_id=:sector_id,company_id=:company_id,system_name=:system_name,description=:description,occurred_at=:occurred_at,
        fixed_by=:fixed_by,client_name=:client_name,contact_phone=:contact_phone,status=:status,note=:note' . $technicianFields . ' WHERE id=:id')->execute($d + ['id' => $id]);
    if ($u['role'] === 'admin') save_breakdown_technicians($id, $technicianIds);
    release_edit_lock('breakdown', $id, (string)$_POST['edit_lock_token']);
    flash('Changes saved.'); redirect('breakdown.php?id=' . $id);
}

page_header($row['system_name'], $u);
echo '<a class="text-sm text-blue-600" href="' . e(url($back)) . '">&larr; Back to breakdowns</a><div class="my-4"></div>';
$editing = $canEdit && !empty($_GET['edit']);
if ($editing) {
    $sectors = accessible_sectors($u);
    $technicians = $u['role'] === 'admin' ? active_technicians() : [];
    if ($u['role'] !== 'admin' && !empty($row['technician_required'])) {
        echo '<p class="mb-3 text-sm text-slate-600">Assigned technicians: ' . e($row['technician_name'] ?? 'Not assigned') . '</p>';
    }
    $heading = 'Breakdown #' . $id; $submit = 'Save changes'; $cancelUrl = url('breakdown.php?id=' . $id);
    include __DIR__ . '/includes/breakdown_form.php';
} else { ?>
<div class="bg-white rounded shadow p-4 grid md:grid-cols-3 gap-4 text-sm">
  <h1 class="md:col-span-3 text-xl font-bold"><?= e($row['system_name']) ?>
    <?php if ($canEdit): ?>
    <form method="post" id="status-form" class="inline ml-2 align-middle"><?= csrf_field() ?><input type="hidden" name="action" value="status">
      <select name="status" aria-label="Change status" class="border rounded px-2 py-1 text-xs font-medium">
        <?php foreach (STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $row['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></form>
    <?php else: ?><?= status_badge($row['status']) ?><?php endif; ?>
    <?php if ($canEdit): ?><a href="<?= e(url('breakdown.php?id=' . $id . '&edit=1')) ?>" class="ml-3 align-middle bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-normal rounded px-3 py-1.5">Edit</a><?php endif; ?></h1>
  <div><div class="text-xs text-slate-500">Company</div><?= e($row['company_name'] ?? '—') ?></div>
  <div><div class="text-xs text-slate-500">Date &amp; time</div><?= e(date('Y-m-d H:i', strtotime($row['occurred_at']))) ?></div>
  <div><div class="text-xs text-slate-500">Contacted by (client side)</div><?= e($row['client_name']) ?></div>
  <div><div class="text-xs text-slate-500">Contact number</div><?= e($row['contact_phone'] ?? '—') ?></div>
  <div><div class="text-xs text-slate-500">Fixed by (company side)</div><?= e($row['fixed_by']) ?></div>
  <?php if (!empty($row['technician_required'])): ?><div><div class="text-xs text-slate-500">Assigned technicians</div><?= e($row['technician_name'] ?? 'Not assigned') ?></div><?php endif; ?>
  <div class="md:col-span-3"><div class="text-xs text-slate-500">Description</div><?= nl2br(e($row['description'])) ?></div>
  <div class="md:col-span-3"><div class="text-xs text-slate-500">Note</div><?= nl2br(e($row['note'])) ?></div>
</div>
<?php if ($canEdit): ?>
<script>
(function () {
  const form = document.getElementById('status-form');
  const select = form.querySelector('select');
  const colors = {open: ['bg-red-100', 'text-red-700'], in_progress: ['bg-yellow-100', 'text-yellow-800'], fixed: ['bg-green-100', 'text-green-700']};
  const paint = () => {
    Object.values(colors).forEach(c => select.classList.remove(...c));
    select.classList.add(...colors[select.value]);
  };
  let previous = select.value;
  paint();
  select.addEventListener('change', async () => {
    const body = new FormData(form);
    select.disabled = true;
    try {
      const res = await fetch(location.href, {method: 'POST', headers: {'X-Requested-With': 'fetch'}, body});
      if (!(await res.json()).ok) throw new Error();
      previous = select.value;
    } catch (e) {
      select.value = previous;
      alert('Could not update the status.');
    }
    select.disabled = false;
    paint();
  });
})();
</script>
<?php endif; ?>
<?php }
page_footer();
