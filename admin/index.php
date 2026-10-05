<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    try {
        if ($action === 'create' && $name !== '') {
            $pdo->prepare('INSERT INTO sectors (name,description,created_by) VALUES (?,?,?)')
                ->execute([$name, trim($_POST['description'] ?? ''), $u['id']]);
            flash('Sector created.');
        } elseif ($action === 'update' && $name !== '') {
            $pdo->prepare('UPDATE sectors SET name=?, description=? WHERE id=?')
                ->execute([$name, trim($_POST['description'] ?? ''), (int)$_POST['id']]);
            $pdo->prepare('DELETE FROM sector_user WHERE sector_id=?')->execute([(int)$_POST['id']]);
            $ins = $pdo->prepare('INSERT IGNORE INTO sector_user (sector_id,user_id) VALUES (?,?)');
            foreach ((array)($_POST['members'] ?? []) as $uid) $ins->execute([(int)$_POST['id'], (int)$uid]);
            flash('Sector updated.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM sectors WHERE id=?')->execute([(int)$_POST['id']]);
            flash('Sector deleted.');
        } else flash('Name is required.', 'error');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000' ? 'A sector with that name already exists.' : 'Database error.', 'error');
    }
    redirect('admin/index.php');
}

$sectors = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM breakdowns b WHERE b.sector_id=s.id) AS cnt FROM sectors s ORDER BY name')->fetchAll();
$members = [];
foreach ($pdo->query('SELECT sector_id,user_id FROM sector_user') as $r) $members[$r['sector_id']][] = $r['user_id'];
$people = $pdo->query("SELECT id,name,email,role FROM users WHERE role IN ('support','user') AND active=1 ORDER BY role,name")->fetchAll();

page_header('Sectors', $u);
?>
<h1 class="text-2xl font-bold mb-4">Sectors</h1>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-4 gap-3 items-end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm md:col-span-1">Sector name<input name="name" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm md:col-span-2">Description<input name="description" class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-slate-900 text-white rounded py-2">Create sector</button>
</form>

<div class="space-y-4">
<?php foreach ($sectors as $s): $m = $members[$s['id']] ?? []; ?>
  <form method="post" class="bg-white rounded shadow p-4"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
    <div class="grid md:grid-cols-2 gap-3">
      <label class="text-sm">Name<input name="name" required value="<?= e($s['name']) ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
      <label class="text-sm">Description<input name="description" value="<?= e($s['description']) ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
    </div>
    <div class="mt-3 text-sm font-medium">Members (IT support can record; users can view)</div>
    <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-1 mt-1 text-sm">
      <?php foreach ($people as $p): ?>
        <label class="flex gap-2 items-center"><input type="checkbox" name="members[]" value="<?= $p['id'] ?>" <?= in_array($p['id'], $m) ? 'checked' : '' ?>>
          <?= e($p['name']) ?> <span class="text-xs text-slate-500">(<?= e($p['role']) ?>)</span></label>
      <?php endforeach; if (!$people): ?><span class="text-slate-500">Create users first.</span><?php endif; ?>
    </div>
    <div class="mt-3 flex gap-2 items-center">
      <button name="action" value="update" class="bg-blue-600 text-white rounded px-3 py-1.5 text-sm">Save</button>
      <button name="action" value="delete" onclick="return confirm('Delete this sector and its <?= (int)$s['cnt'] ?> breakdown record(s)?')" class="bg-red-600 text-white rounded px-3 py-1.5 text-sm">Delete</button>
      <span class="text-xs text-slate-500"><?= (int)$s['cnt'] ?> record(s)</span>
    </div>
  </form>
<?php endforeach; if (!$sectors): ?><p class="text-slate-500">No sectors yet.</p><?php endif; ?>
</div>
<?php page_footer();
