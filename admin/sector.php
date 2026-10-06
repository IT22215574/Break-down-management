<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM breakdowns b WHERE b.sector_id=s.id) AS cnt FROM sectors s WHERE s.id=?');
$st->execute([$id]);
$s = $st->fetch();
if (!$s) { flash('Sector not found.', 'error'); redirect('admin/index.php'); }
$pst = $pdo->prepare('SELECT phone FROM sector_phones WHERE sector_id=? ORDER BY id');
$pst->execute([$id]);
$sectorPhones = $pst->fetchAll(PDO::FETCH_COLUMN) ?: array_filter([$s['phone'] ?? '']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            require_edit_lock('sector', $id, 'admin/sector.php?id=' . $id);
            $name = trim($_POST['name'] ?? '');
            $phones = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['phone'] ?? [])), fn($p) => $p !== '')));
            $phonesValid = $phones && !array_filter($phones, fn($p) => !preg_match('/^[0-9]{10}$/', $p));
            $phone = $phones[0] ?? '';
            $address = trim($_POST['address'] ?? '');
            if ($name === '' || !$phonesValid || $address === '' || mb_strlen($address) > 255) {
                flash('Enter a sector name, at least one 10-digit phone number, and an address.', 'error');
                redirect('admin/sector.php?id=' . $id);
            }
            [$err, $email, $loginId, $hash, $enc] = sector_login_input($id);
            if ($err) {
                $pdo->rollBack();
                flash($err, 'error');
                redirect('admin/sector.php?id=' . $id);
            }
            $pdo->prepare('UPDATE sectors SET name=?, phone=?, email=?, login_id=?, password_hash=COALESCE(?, password_hash), password_enc=COALESCE(?, password_enc), address=?, description=? WHERE id=?')
                ->execute([$name, $phone, $email, $loginId, $hash, $enc, $address, trim($_POST['description'] ?? ''), $id]);
            if ($loginId !== null) $pdo->prepare('UPDATE sector_login_ids SET used=1 WHERE login_id=?')->execute([$loginId]);
            $pdo->prepare('DELETE FROM sector_phones WHERE sector_id=?')->execute([$id]);
            $pins = $pdo->prepare('INSERT INTO sector_phones (sector_id,phone) VALUES (?,?)');
            foreach ($phones as $p) $pins->execute([$id, $p]);
            $pdo->prepare('DELETE FROM sector_user WHERE sector_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT IGNORE INTO sector_user (sector_id,user_id) VALUES (?,?)');
            foreach ((array)($_POST['members'] ?? []) as $uid) $ins->execute([$id, (int)$uid]);
            release_edit_lock('sector', $id, (string)$_POST['edit_lock_token']);
            flash('Sector updated.');
            redirect('admin/sector.php?id=' . $id);
        } elseif ($action === 'delete') {
            require_edit_lock('sector', $id, 'admin/sector.php?id=' . $id);
            $pdo->prepare('DELETE FROM sectors WHERE id=?')->execute([$id]);
            release_edit_lock('sector', $id, (string)$_POST['edit_lock_token']);
            flash('Sector deleted.');
            redirect('admin/index.php');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getCode() === '23000' ? 'A sector with that name already exists.' : 'Database error.', 'error');
        redirect('admin/sector.php?id=' . $id);
    }
}

$m = $pdo->prepare('SELECT user_id FROM sector_user WHERE sector_id=?');
$m->execute([$id]);
$m = $m->fetchAll(PDO::FETCH_COLUMN);
$people = $pdo->query("SELECT id,name,email,role FROM users WHERE role IN ('support','user') AND active=1 ORDER BY role,name")->fetchAll();

page_header($s['name'], $u);
?>
<a href="index.php" class="text-sm text-blue-600">&larr; Back to sectors</a>
<div class="flex items-center justify-between my-4">
  <h1 class="text-2xl font-bold"><?= e($s['name']) ?></h1>
  <button type="button" id="sector-toggle" class="bg-emerald-600 hover:bg-emerald-700 text-white rounded px-3 py-1.5 text-sm">Edit</button>
</div>
<form id="sector-form" method="post" class="bg-white rounded shadow p-4" data-edit-lock="sector" data-edit-lock-id="<?= $id ?>" data-edit-lock-manual="true"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <p data-edit-lock-status class="mb-3 rounded bg-amber-100 px-3 py-2 text-sm text-amber-900 empty:hidden" role="status"></p>
  <div class="grid md:grid-cols-2 gap-3">
    <label class="text-sm">Name<input name="name" required maxlength="150" value="<?= e($s['name']) ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
    <div class="text-sm">Phone numbers
      <div id="phone-list" class="space-y-2 mt-1">
<?php foreach ($sectorPhones as $i => $ph): ?>
        <div class="phone-row flex gap-2"><input type="tel" name="phone[]" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" value="<?= e($ph) ?>" class="sector-phone w-full border rounded px-3 py-2"><button type="button" class="rm-phone hidden text-red-600 px-2" aria-label="Remove">&times;</button></div>
<?php endforeach; ?>
      </div>
      <button type="button" id="add-phone" class="hidden mt-2 text-blue-600 text-sm">+ Add another number</button>
    </div>
    <label class="text-sm">Email address (optional if login ID is set)<input type="email" name="email" maxlength="190" value="<?= e($s['email'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
    <div class="text-sm">Login ID (required without email)
      <div class="mt-1 flex gap-2"><input name="login_id" readonly maxlength="8" value="<?= e($s['login_id'] ?? '') ?>" class="login-id w-full border rounded px-3 py-2 font-mono"><button type="button" id="gen-id" disabled class="bg-slate-700 text-white rounded px-3 text-sm disabled:opacity-50">Generate</button></div></div>
    <label class="text-sm">Login password<span class="relative block"><input type="password" name="password" minlength="8" value="<?= e(decrypt_sector_password($s['password_enc'] ?? null)) ?>" autocomplete="new-password" placeholder="<?= !$s['password_hash'] ? 'Required' : ($s['password_enc'] ? 'Leave blank to keep current' : 'Not viewable - click Edit and set a new password') ?>" class="pr-10 mt-1 w-full border rounded px-3 py-2"><button type="button" class="pw-toggle absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-800" aria-label="Show password" aria-pressed="false"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/><path class="pw-slash hidden" d="M3 3l18 18"/></svg></button></span></label>
    <label class="text-sm">Address<input name="address" required maxlength="255" value="<?= e($s['address'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm">Description<input name="description" value="<?= e($s['description']) ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
  </div>
  <div class="mt-3 text-sm font-medium">Members (IT support can record; users can view)</div>
  <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-1 mt-1 text-sm">
    <?php foreach ($people as $p): ?>
      <label class="flex gap-2 items-center"><input type="checkbox" name="members[]" value="<?= $p['id'] ?>" <?= in_array($p['id'], $m) ? 'checked' : '' ?>>
        <?= e($p['name']) ?> <span class="text-xs text-slate-500">(<?= e($p['role']) ?>)</span></label>
    <?php endforeach; if (!$people): ?><span class="text-slate-500">Create users first.</span><?php endif; ?>
  </div>
  <div class="mt-4 flex gap-2 items-center">
    <span id="sector-actions" class="flex gap-2 items-center hidden">
    <button name="action" value="update" class="bg-blue-600 text-white rounded px-3 py-1.5 text-sm">Save changes</button>
    <button name="action" value="delete" formnovalidate onclick="return confirm('Delete this sector and its <?= (int)$s['cnt'] ?> breakdown record(s)?')" class="bg-red-600 text-white rounded px-3 py-1.5 text-sm">Delete</button>
    </span>
    <span class="text-xs text-slate-500"><?= (int)$s['cnt'] ?> record(s)</span>
  </div>
</form>
<script>
document.getElementById('phone-list').addEventListener('input', e => {
  if (e.target.classList.contains('sector-phone')) e.target.value = e.target.value.replace(/\D/g, '').slice(0, 10);
});
</script>
<script>
(function () {
  const form = document.getElementById('sector-form'), toggle = document.getElementById('sector-toggle');
  const actions = document.getElementById('sector-actions');
  const phoneList = document.getElementById('phone-list'), addPhone = document.getElementById('add-phone');
  const phoneHtml = phoneList.innerHTML;
  const rowHtml = '<input type="tel" name="phone[]" minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="sector-phone w-full border rounded px-3 py-2"><button type="button" class="rm-phone text-red-600 px-2" aria-label="Remove">&times;</button>';
  let texts = [];
  const collect = () => { texts = [...form.querySelectorAll('input:not([type=hidden]):not([type=checkbox])')]; };
  collect();
  const boxes = [...form.querySelectorAll('input[type=checkbox]')];
  const initial = new Map([...texts, ...boxes].map(c => [c, c.type === 'checkbox' ? c.checked : c.value]));
  phoneList.addEventListener('click', e => {
    const b = e.target.closest('.rm-phone');
    if (b && active && phoneList.children.length > 1) { b.parentElement.remove(); collect(); }
  });
  addPhone.addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'phone-row flex gap-2';
    row.innerHTML = rowHtml;
    phoneList.appendChild(row);
    collect();
    row.querySelector('input').focus();
  });
  const genBtn = document.getElementById('gen-id');
  const idInput = form.querySelector('.login-id');
  genBtn.addEventListener('click', async () => {
    const r = await fetch('generate_id.php', {method: 'POST', body: new URLSearchParams({csrf: <?= json_encode(csrf_token()) ?>})});
    if (r.ok) idInput.value = (await r.json()).id;
  });
  let active = false;
  const setMode = on => {
    active = on;
    texts.forEach(t => { t.readOnly = !on; t.classList.toggle('bg-slate-50', !on); });
    boxes.forEach(b => { b.disabled = !on; });
    genBtn.disabled = !on;
    addPhone.classList.toggle('hidden', !on);
    phoneList.querySelectorAll('.rm-phone').forEach(b => b.classList.toggle('hidden', !on));
    actions.classList.toggle('hidden', !on);
    toggle.textContent = on ? 'Cancel' : 'Edit';
    toggle.classList.toggle('bg-emerald-600', !on); toggle.classList.toggle('hover:bg-emerald-700', !on);
    toggle.classList.toggle('bg-amber-500', on); toggle.classList.toggle('hover:bg-amber-600', on);
  };
  setMode(false);
  toggle.addEventListener('click', async () => {
    if (active) {
      form.dataset.lockCancel = '1';
      if (form.dataset.lockToken) { delete form.dataset.lockCancel; await window.EditLocks.release(form); }
      phoneList.innerHTML = phoneHtml; collect();
      initial.forEach((v, c) => { if (c.type === 'checkbox') c.checked = v; else c.value = v; });
      const note = form.querySelector('[data-edit-lock-status]');
      if (note) note.textContent = '';
      setMode(false);
      return;
    }
    delete form.dataset.lockCancel;
    setMode(true);
    if (!await window.EditLocks.acquire(form)) { setMode(false); return; }
    if (active) { setMode(true); texts[0]?.focus(); }
  });
})();
</script>
<script>
document.querySelectorAll('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
  const input = btn.parentElement.querySelector('input');
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', show);
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  btn.querySelector('.pw-slash').classList.toggle('hidden', !show);
}));
</script>
<?php page_footer();
