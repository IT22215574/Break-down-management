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
        } else flash('Name is required.', 'error');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000' ? 'A sector with that name already exists.' : 'Database error.', 'error');
    }
    redirect('admin/index.php');
}

$sectors = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM breakdowns b WHERE b.sector_id=s.id) AS cnt FROM sectors s ORDER BY name')->fetchAll();
$mcount = [];
foreach ($pdo->query('SELECT sector_id,user_id FROM sector_user') as $r) $mcount[$r['sector_id']] = ($mcount[$r['sector_id']] ?? 0) + 1;

page_header('Sectors', $u);
?>
<h1 class="text-2xl font-bold mb-4">Sectors</h1>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-4 gap-3 items-end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm md:col-span-1">Sector name<input name="name" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm md:col-span-2">Description<input name="description" class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-slate-900 text-white rounded py-2">Create sector</button>
</form>

<label class="block text-base font-semibold mb-3">Search sectors
  <input id="sector-search" type="search" placeholder="Sector name" class="mt-1 w-full border rounded px-3 py-2" bold="true">
</label>
<div class="space-y-2">
<?php foreach ($sectors as $s): ?>
  <a data-sector-name="<?= e($s['name']) ?>" href="sector.php?id=<?= (int)$s['id'] ?>" class="flex items-center justify-between gap-4 bg-white rounded shadow px-4 py-3 hover:bg-slate-50">
    <div class="min-w-0">
      <div class="font-semibold truncate"><?= e($s['name']) ?></div>
      <div class="text-sm text-slate-500 truncate"><?= e($s['description'] ?? '') ?></div>
    </div>
    <div class="text-xs text-slate-500 whitespace-nowrap"><?= (int)($mcount[$s['id']] ?? 0) ?> member(s) &middot; <?= (int)$s['cnt'] ?> record(s) &rsaquo;</div>
  </a>
<?php endforeach; ?>
  <p id="sector-search-empty" class="text-slate-500" <?= $sectors ? 'hidden' : '' ?>><?= $sectors ? 'No sectors found.' : 'No sectors yet.' ?></p>
</div>
<script>
(function () {
  const search = document.getElementById('sector-search');
  const cards = document.querySelectorAll('[data-sector-name]');
  const emptyMessage = document.getElementById('sector-search-empty');
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase();
    let visibleCount = 0;
    cards.forEach(card => {
      const matches = card.dataset.sectorName.toLocaleLowerCase().includes(query);
      card.classList.toggle('hidden', !matches);
      if (matches) visibleCount++;
    });
    emptyMessage.classList.toggle('hidden', visibleCount > 0);
  });
})();
</script>
<?php page_footer();
