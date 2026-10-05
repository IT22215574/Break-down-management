<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'create' && $name !== '') {
            $pdo->prepare('INSERT INTO companies (name) VALUES (?)')->execute([$name]);
            flash('Company created.');
        } elseif ($action === 'update' && $name !== '') {
            require_edit_lock('company', $id, 'admin/companies.php');
            $pdo->prepare('UPDATE companies SET name=? WHERE id=?')->execute([$name, $id]);
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
<form method="post" class="bg-white rounded shadow p-4 mb-6 flex gap-3 items-end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm flex-1">Company name<input name="name" required maxlength="150" class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-slate-900 text-white rounded px-4 py-2">Create company</button>
</form>
<div class="space-y-2">
<?php foreach ($companies as $c): ?>
  <form method="post" class="bg-white rounded shadow px-4 py-3 flex flex-wrap items-center gap-3" data-edit-lock="company" data-edit-lock-id="<?= (int)$c['id'] ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
    <input name="name" required maxlength="150" value="<?= e($c['name']) ?>" class="flex-1 min-w-[12rem] border rounded px-3 py-1.5">
    <span class="text-xs text-slate-500"><?= (int)$c['cnt'] ?> record(s)</span>
    <button name="action" value="update" class="bg-blue-600 text-white rounded px-3 py-1.5 text-sm">Save</button>
    <button name="action" value="delete" formnovalidate onclick="return confirm('Delete this company? Its <?= (int)$c['cnt'] ?> breakdown record(s) will be kept without a company.')" class="bg-red-600 text-white rounded px-3 py-1.5 text-sm">Delete</button>
  </form>
<?php endforeach; if (!$companies): ?><p class="text-slate-500">No companies yet.</p><?php endif; ?>
</div>
<?php page_footer();
