<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '' || !preg_match('/^[0-9]{10}$/', $phone)) {
            flash('Enter a technician name and a 10-digit phone number.', 'error');
        } else {
            try {
                $pdo->prepare('INSERT INTO technicians (name,phone) VALUES (?,?)')->execute([$name, $phone]);
                flash('Technician added.');
            } catch (PDOException $e) {
                flash($e->getCode() === '23000' ? 'That phone number is already registered.' : 'Database error.', 'error');
            }
        }
    } elseif ($action === 'edit' && $id > 0) {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($name === '' || !preg_match('/^[0-9]{10}$/', $phone)) {
            flash('Enter a technician name and a 10-digit phone number.', 'error');
        } else {
            try {
                $exists = $pdo->prepare('SELECT 1 FROM technicians WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    flash('Technician not found.', 'error');
                } else {
                    $pdo->prepare('UPDATE technicians SET name=?,phone=? WHERE id=?')->execute([$name, $phone, $id]);
                    flash('Technician updated.');
                }
            } catch (PDOException $e) {
                flash($e->getCode() === '23000' ? 'That phone number is already registered.' : 'Database error.', 'error');
            }
        }
    } elseif ($action === 'delete' && $id > 0) {
        $st = $pdo->prepare('DELETE FROM technicians WHERE id=?');
        $st->execute([$id]);
        flash($st->rowCount() ? 'Technician deleted.' : 'Technician not found.', $st->rowCount() ? 'success' : 'error');
    } elseif ($action === 'toggle' && $id > 0) {
        $st = $pdo->prepare('UPDATE technicians SET active = 1 - active WHERE id=?');
        $st->execute([$id]);
        flash($st->rowCount() ? 'Technician status updated.' : 'Technician not found.', $st->rowCount() ? 'success' : 'error');
    } else {
        flash('Action not allowed.', 'error');
    }
    redirect('admin/technicians.php');
}

$technicians = $pdo->query('SELECT id,name,phone,active FROM technicians ORDER BY name')->fetchAll();
page_header('Technicians', $u);
?>
<h1 class="text-2xl font-bold mb-4">Technicians</h1>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-3 gap-3 items-end"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm">Name<input name="name" required maxlength="120" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Phone number<input type="tel" name="phone" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="technician-phone mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-slate-900 text-white rounded py-2">Add technician</button>
</form>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Name</th><th class="p-3">Phone number</th><th class="p-3">Status</th><th class="p-3">Actions</th></tr></thead><tbody>
<?php foreach ($technicians as $r): $fid = 'edit-technician-' . (int)$r['id']; ?><tr class="border-t technician-row">
  <td class="p-3"><form id="<?= $fid ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"></form>
    <span class="view-mode"><?= e($r['name']) ?></span>
    <input form="<?= $fid ?>" name="name" required maxlength="120" value="<?= e($r['name']) ?>" disabled aria-label="Technician name" class="edit-mode hidden border rounded px-2 py-1"></td>
  <td class="p-3"><span class="view-mode"><?= e($r['phone']) ?></span>
    <input form="<?= $fid ?>" type="tel" name="phone" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" value="<?= e($r['phone']) ?>" disabled aria-label="Technician phone number" class="edit-mode technician-phone hidden border rounded px-2 py-1"></td>
  <td class="p-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $r['active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>"><?= $r['active'] ? 'Active' : 'Disabled' ?></span></td>
  <td class="p-3"><div class="flex gap-3">
    <button type="button" class="edit-btn view-mode text-xs text-blue-600">Edit</button>
    <button form="<?= $fid ?>" class="edit-mode hidden text-xs text-blue-600">Save</button>
    <button type="button" class="cancel-btn edit-mode hidden text-xs text-slate-600">Cancel</button>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="text-xs text-slate-600"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form>
    <form method="post" onsubmit="return confirm('Delete this technician? Assigned breakdowns will remain but become unassigned.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="text-xs text-red-600">Delete</button></form>
  </div></td>
</tr><?php endforeach; if (!$technicians): ?><tr><td colspan="4" class="p-3 text-slate-500">No technicians yet.</td></tr><?php endif; ?></tbody></table></div>
<script>
document.querySelectorAll('.technician-phone').forEach(input => {
  input.addEventListener('input', () => {
    input.value = input.value.replace(/\D/g, '').slice(0, 10);
  });
});
document.querySelectorAll('.technician-row').forEach(row => {
  const setEditing = editing => {
    row.querySelectorAll('.view-mode').forEach(el => el.classList.toggle('hidden', editing));
    row.querySelectorAll('.edit-mode').forEach(el => {
      el.classList.toggle('hidden', !editing);
      if (el.tagName === 'INPUT') el.disabled = !editing;
    });
  };
  row.querySelector('.edit-btn').addEventListener('click', () => setEditing(true));
  row.querySelector('.cancel-btn').addEventListener('click', () => {
    row.querySelectorAll('input.edit-mode').forEach(el => el.value = el.defaultValue);
    setEditing(false);
  });
});
</script>
<?php page_footer();
