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
    } elseif ($action === 'status' && $id > 0 && in_array($_POST['active'] ?? '', ['0', '1'], true)) {
        $st = $pdo->prepare('UPDATE technicians SET active=? WHERE id=?');
        $st->execute([(int)$_POST['active'], $id]);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json');
            exit(json_encode(['ok' => true]));
        }
        flash('Technician status updated.');
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
  <td class="p-3"><form id="<?= $fid ?>" method="post" data-edit-lock-manual="true" data-edit-lock="technician" data-edit-lock-id="<?= (int)$r['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"></form>
    <span class="view-mode"><?= e($r['name']) ?></span>
    <div class="edit-mode hidden"><select form="<?= $fid ?>" name="name_title" aria-label="Technician title" class="border rounded px-1 py-1">
      <?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $nameParts[0] === $value ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?>
    </select><input form="<?= $fid ?>" name="name" required maxlength="108" value="<?= e($nameParts[1]) ?>" disabled aria-label="Technician name" class="border rounded px-2 py-1"></div></td>
  <td class="p-3"><span class="view-mode"><?= e($r['phone']) ?></span>
    <input form="<?= $fid ?>" type="tel" name="phone" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" value="<?= e($r['phone']) ?>" disabled aria-label="Technician phone number" class="edit-mode technician-phone hidden border rounded px-2 py-1"></td>
  <td class="p-3"><form method="post" class="status-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <select name="active" aria-label="Technician status" class="border rounded px-2 py-1 text-xs font-medium <?= $r['active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
      <option value="1" <?= $r['active'] ? 'selected' : '' ?>>Active</option><option value="0" <?= $r['active'] ? '' : 'selected' ?>>Disabled</option>
    </select></form></td>
  <td class="p-3"><div class="flex gap-3">
    <button type="button" class="edit-btn bg-emerald-600 hover:bg-emerald-700 text-white rounded px-2 py-1 text-xs">Edit</button>
    <button form="<?= $fid ?>" class="edit-mode hidden bg-blue-600 hover:bg-blue-700 text-white rounded px-2 py-1 text-xs">Save</button>
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
// Saves the status in the background so unsaved edits in the row are kept.
document.querySelectorAll('.status-form').forEach(form => {
  const select = form.querySelector('select');
  let previous = select.value;
  const paint = () => {
    const on = select.value === '1';
    select.classList.toggle('bg-green-100', on); select.classList.toggle('text-green-700', on);
    select.classList.toggle('bg-red-100', !on); select.classList.toggle('text-red-700', !on);
  };
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
});
document.querySelectorAll('.technician-row').forEach(row => {
  const toggle = row.querySelector('.edit-btn');
  const form = row.querySelector('form[id]');
  let editing = false;
  const setEditing = on => {
    editing = on;
    row.querySelectorAll('.view-mode').forEach(el => el.classList.toggle('hidden', on));
    row.querySelectorAll('.edit-mode').forEach(el => el.classList.toggle('hidden', !on));
    row.querySelectorAll('.edit-mode input, input.edit-mode, .edit-mode select').forEach(el => el.disabled = !on);
    toggle.textContent = on ? 'Cancel' : 'Edit';
    toggle.classList.toggle('bg-emerald-600', !on); toggle.classList.toggle('hover:bg-emerald-700', !on);
    toggle.classList.toggle('bg-amber-500', on); toggle.classList.toggle('hover:bg-amber-600', on);
  };
  toggle.addEventListener('click', async () => {
    if (editing) {
      form.dataset.lockCancel = '1';
      if (form.dataset.lockToken) { delete form.dataset.lockCancel; await window.EditLocks.release(form); }
      row.querySelectorAll('.edit-mode input, input.edit-mode').forEach(el => el.value = el.defaultValue);
      row.querySelectorAll('.edit-mode select').forEach(select => {
        Array.from(select.options).forEach(option => option.selected = option.defaultSelected);
      });
      const note = row.querySelector('[data-edit-lock-status]');
      if (note) note.textContent = '';
      setEditing(false);
      return;
    }
    delete form.dataset.lockCancel;
    setEditing(true);
    if (!await window.EditLocks.acquire(form)) setEditing(false);
  });
});
</script>
<?php page_footer();
