<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM breakdowns b WHERE b.sector_id=s.id) AS cnt FROM sectors s WHERE s.id=?');
$st->execute([$id]);
$s = $st->fetch();
if (!$s) { flash('Sector not found.', 'error'); redirect('admin/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { flash('Name is required.', 'error'); redirect('admin/sector.php?id=' . $id); }
            $pdo->prepare('UPDATE sectors SET name=?, description=? WHERE id=?')
                ->execute([$name, trim($_POST['description'] ?? ''), $id]);
            $pdo->prepare('DELETE FROM sector_user WHERE sector_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT IGNORE INTO sector_user (sector_id,user_id) VALUES (?,?)');
            foreach ((array)($_POST['members'] ?? []) as $uid) $ins->execute([$id, (int)$uid]);
            flash('Sector updated.');
            redirect('admin/sector.php?id=' . $id);
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM sectors WHERE id=?')->execute([$id]);
            flash('Sector deleted.');
            redirect('admin/index.php');
        }
    } catch (PDOException $e) {
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
<h1 class="text-2xl font-bold my-4"><?= e($s['name']) ?></h1>
<form method="post" class="bg-white rounded shadow p-4"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
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
  <div class="mt-4 flex gap-2 items-center">
    <button name="action" value="update" class="bg-blue-600 text-white rounded px-3 py-1.5 text-sm">Save changes</button>
    <button name="action" value="delete" formnovalidate onclick="return confirm('Delete this sector and its <?= (int)$s['cnt'] ?> breakdown record(s)?')" class="bg-red-600 text-white rounded px-3 py-1.5 text-sm">Delete</button>
    <span class="text-xs text-slate-500"><?= (int)$s['cnt'] ?> record(s)</span>
  </div>
</form>
<?php page_footer();
