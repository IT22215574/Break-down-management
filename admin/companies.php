<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $jobTag = trim($_POST['job_tag'] ?? '');
    if ($jobTag !== '' && !preg_match('#^[A-Za-z0-9/_-]{1,30}$#', $jobTag)) { flash('Job ID tag may only contain letters, numbers, / _ - (max 30).', 'error'); redirect('admin/companies.php'); }
    $jobTag = $jobTag === '' ? null : $jobTag;
    try {
        if ($action === 'create' && $name !== '') {
            $pdo->prepare('INSERT INTO companies (name, job_tag) VALUES (?, ?)')->execute([$name, $jobTag]);
            flash('Company created.');
        } elseif ($action === 'update' && $name !== '') {
            require_edit_lock('company', $id, 'admin/companies.php');
            $pdo->prepare('UPDATE companies SET name=?, job_tag=? WHERE id=?')->execute([$name, $jobTag, $id]);
            release_edit_lock('company', $id, (string)$_POST['edit_lock_token']);
            flash('Company updated.');
        } elseif ($action === 'delete') {
            require_edit_lock('company', $id, 'admin/companies.php');
            $pdo->prepare('DELETE FROM companies WHERE id=?')->execute([$id]);
            release_edit_lock('company', $id, (string)$_POST['edit_lock_token']);
            flash('Company deleted.');
        } else flash('Name is required.', 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getCode() === '23000' ? 'A company with that name already exists.' : 'Database error.', 'error');
    }
    redirect('admin/companies.php');
}

$companies = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM breakdowns b WHERE b.company_id=c.id) AS cnt FROM companies c ORDER BY name')->fetchAll();
page_header('Company', $u);
?>
<h1 class="text-2xl font-bold mb-4">Companies</h1>
<style>
  .co-card [data-edit-lock-status] { flex-basis: 100%; margin: 0 0 .25rem; padding: .5rem .75rem; font-size: .875rem; }
  .co-card:not(.editing) .co-edit-only { display: none; }
</style>
<form method="post" class="bg-white rounded shadow p-4 mb-6 flex gap-3 items-end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm flex-1">Company name<input name="name" required maxlength="150" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Job ID tag<input name="job_tag" maxlength="30" placeholder="EK/JB/" class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Create company</button>
</form>
<div class="space-y-2">
<?php foreach ($companies as $c): ?>
  <div class="co-card bg-white rounded shadow px-4 py-3 flex flex-wrap items-center gap-3">
    <form method="post" class="contents" data-edit-lock="company" data-edit-lock-id="<?= (int)$c['id'] ?>" data-edit-lock-manual="true"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
      <p data-edit-lock-status class="rounded bg-amber-100 text-amber-900 empty:hidden" role="status"></p>
      <input name="name" required maxlength="150" readonly value="<?= e($c['name']) ?>" class="co-name flex-1 min-w-[12rem] border border-transparent bg-slate-50 rounded px-3 py-1.5">
      <input name="job_tag" maxlength="30" readonly placeholder="Job ID tag" value="<?= e($c['job_tag'] ?? '') ?>" class="co-name w-36 border border-transparent bg-slate-50 rounded px-3 py-1.5">
      <span class="text-xs text-slate-500"><?= (int)$c['cnt'] ?> record(s)</span>
      <button name="action" value="update" class="co-edit-only bg-blue-600 text-white rounded px-3 py-1.5 text-sm">Save</button>
      <button name="action" value="delete" formnovalidate onclick="return confirm('Delete this company? Its <?= (int)$c['cnt'] ?> breakdown record(s) will be kept without a company.')" class="co-edit-only bg-red-600 text-white rounded px-3 py-1.5 text-sm">Delete</button>
    </form>
    <button type="button" class="co-toggle bg-emerald-600 hover:bg-emerald-700 text-white rounded px-3 py-1.5 text-sm">Edit</button>
  </div>
<?php endforeach; if (!$companies): ?><p class="text-slate-500">No companies yet.</p><?php endif; ?>
</div>
<script>
document.querySelectorAll('.co-card').forEach(card => {
  const form = card.querySelector('form'), inputs = [...card.querySelectorAll('.co-name')], input = inputs[0], toggle = card.querySelector('.co-toggle');
  const originals = inputs.map(i => i.value);
  let active = false;
  const setMode = on => {
    active = on;
    card.classList.toggle('editing', on);
    inputs.forEach(input => {
      input.readOnly = !on;
      input.classList.toggle('border-slate-300', on);
      input.classList.toggle('bg-white', on);
      input.classList.toggle('border-transparent', !on);
      input.classList.toggle('bg-slate-50', !on);
    });
    toggle.textContent = on ? 'Cancel' : 'Edit';
    toggle.classList.toggle('bg-emerald-600', !on); toggle.classList.toggle('hover:bg-emerald-700', !on);
    toggle.classList.toggle('bg-amber-500', on); toggle.classList.toggle('hover:bg-amber-600', on);
  };
  toggle.addEventListener('click', async () => {
    if (active) {
      form.dataset.lockCancel = '1';
      if (form.dataset.lockToken) { delete form.dataset.lockCancel; await window.EditLocks.release(form); }
      inputs.forEach((i, n) => i.value = originals[n]);
      const note = form.querySelector('[data-edit-lock-status]');
      if (note) { note.textContent = ''; }
      setMode(false);
      return;
    }
    delete form.dataset.lockCancel;
    setMode(true);
    if (!await window.EditLocks.acquire(form) && active) { setMode(false); return; }
    if (active) input.focus();
  });
});
</script>
<?php page_footer();
