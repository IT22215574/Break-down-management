<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'create') {
        $name = person_name_with_title((string)($_POST['name'] ?? ''), (string)($_POST['name_title'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        if ($name === null || person_name_length($name) > 120 || !preg_match('/^[0-9]{10}$/', $phone)) {
            flash('Enter a technician name and a 10-digit phone number.', 'error');
        } else {
            try {
                $pdo->prepare('INSERT INTO technicians (name,phone) VALUES (?,?)')->execute([$name, $phone]);
                flash('Technician added.');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash($e->getCode() === '23000' ? 'That phone number is already registered.' : 'Database error.', 'error');
            }
        }
    } elseif ($action === 'edit' && $id > 0) {
        require_edit_lock('technician', $id, 'admin/technicians.php');
        $name = person_name_with_title((string)($_POST['name'] ?? ''), (string)($_POST['name_title'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        if ($name === null || person_name_length($name) > 120 || !preg_match('/^[0-9]{10}$/', $phone)) {
            flash('Enter a technician name and a 10-digit phone number.', 'error');
        } else {
            try {
                $exists = $pdo->prepare('SELECT 1 FROM technicians WHERE id=?');
                $exists->execute([$id]);
                if (!$exists->fetchColumn()) {
                    flash('Technician not found.', 'error');
                } else {
                    $pdo->prepare('UPDATE technicians SET name=?,phone=? WHERE id=?')->execute([$name, $phone, $id]);
                    release_edit_lock('technician', $id, (string)$_POST['edit_lock_token']);
                    flash('Technician updated.');
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash($e->getCode() === '23000' ? 'That phone number is already registered.' : 'Database error.', 'error');
            }
        }
    } elseif ($action === 'delete' && $id > 0) {
        require_edit_lock('technician', $id, 'admin/technicians.php');
        $st = $pdo->prepare('DELETE FROM technicians WHERE id=?');
        $st->execute([$id]);
        release_edit_lock('technician', $id, (string)$_POST['edit_lock_token']);
        flash($st->rowCount() ? 'Technician deleted.' : 'Technician not found.', $st->rowCount() ? 'success' : 'error');
    } elseif ($action === 'toggle' && $id > 0) {
        require_edit_lock('technician', $id, 'admin/technicians.php');
        $st = $pdo->prepare('UPDATE technicians SET active = 1 - active WHERE id=?');
        $st->execute([$id]);
        release_edit_lock('technician', $id, (string)$_POST['edit_lock_token']);
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
  <label class="text-sm">Name<div class="mt-1 flex gap-1">
    <select name="name_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>"><?= e($title) ?></option><?php endforeach; ?></select>
    <input name="name" required maxlength="108" class="min-w-0 w-full border rounded px-3 py-2"></div></label>
  <label class="text-sm">Phone number<input type="tel" name="phone" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="technician-phone mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-slate-900 text-white rounded py-2">Add technician</button>
</form>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Name</th><th class="p-3">Phone number</th><th class="p-3">Status</th><th class="p-3">Actions</th></tr></thead><tbody>
<?php foreach ($technicians as $r): $fid = 'edit-technician-' . (int)$r['id']; $nameParts = person_name_parts($r['name']); ?><tr class="border-t technician-row">
  <td class="p-3"><form id="<?= $fid ?>" method="post" data-edit-lock="technician" data-edit-lock-id="<?= (int)$r['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"></form>
    <span class="view-mode"><?= e($r['name']) ?></span>
    <div class="edit-mode hidden"><select form="<?= $fid ?>" name="name_title" aria-label="Technician title" class="border rounded px-1 py-1">
      <?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $nameParts[0] === $value ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?>
    </select><input form="<?= $fid ?>" name="name" required maxlength="108" value="<?= e($nameParts[1]) ?>" disabled aria-label="Technician name" class="border rounded px-2 py-1"></div></td>
  <td class="p-3"><span class="view-mode"><?= e($r['phone']) ?></span>
    <input form="<?= $fid ?>" type="tel" name="phone" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" value="<?= e($r['phone']) ?>" disabled aria-label="Technician phone number" class="edit-mode technician-phone hidden border rounded px-2 py-1"></td>
  <td class="p-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $r['active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>"><?= $r['active'] ? 'Active' : 'Disabled' ?></span></td>
  <td class="p-3"><div class="flex gap-3">
    <button type="button" class="edit-btn view-mode text-xs text-blue-600">Edit</button>
    <button form="<?= $fid ?>" class="edit-mode hidden text-xs text-blue-600">Save</button>
    <button type="button" class="cancel-btn edit-mode hidden text-xs text-slate-600">Cancel</button>
    <form method="post" data-edit-lock="technician" data-edit-lock-id="<?= (int)$r['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="text-xs text-slate-600"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form>
    <form method="post" data-edit-lock="technician" data-edit-lock-id="<?= (int)$r['id'] ?>" onsubmit="return confirm('Delete this technician? Assigned breakdowns will remain but become unassigned.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
    });
    row.querySelectorAll('.edit-mode input, .edit-mode select').forEach(el => el.disabled = !editing);
  };
  row.querySelector('.edit-btn').addEventListener('click', async event => {
    const form = document.getElementById(event.currentTarget.closest('tr').querySelector('form[id]').id);
    if (!await window.EditLocks.acquire(form)) return;
    setEditing(true);
  });
  row.querySelector('.cancel-btn').addEventListener('click', () => {
    row.querySelectorAll('.edit-mode input').forEach(el => el.value = el.defaultValue);
    row.querySelectorAll('.edit-mode select').forEach(select => {
      Array.from(select.options).forEach(option => option.selected = option.defaultSelected);
    });
    setEditing(false);
    window.EditLocks.release(row.querySelector('form[id]'));
  });
});
</script>
<?php page_footer();
