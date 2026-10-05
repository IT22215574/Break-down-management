<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    try {
        if ($action === 'create' && $name !== '' && preg_match('/^[0-9]{10}$/', $phone) && filter_var($email, FILTER_VALIDATE_EMAIL) && $address !== '' && mb_strlen($address) <= 255) {
            $pdo->prepare('INSERT INTO sectors (name,phone,email,address,description,created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$name, $phone, $email, $address, trim($_POST['description'] ?? ''), $u['id']]);
            flash('Sector created.');
        } else {
            flash('Enter a sector name, a 10-digit phone number, and a valid email address, and an address.', 'error');
        }
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
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-3 gap-3 items-end">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm">Sector name<input name="name" required maxlength="150" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Phone number<input type="tel" name="phone" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="sector-phone mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Email address<input type="email" name="email" required maxlength="190" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Address<input name="address" required maxlength="255" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm md:col-span-2">Description<input name="description" class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="bg-slate-900 text-white rounded py-2">Create sector</button>
</form>
<script>
document.querySelectorAll('.sector-phone').forEach(input => {
  input.addEventListener('input', () => {
    input.value = input.value.replace(/\D/g, '').slice(0, 10);
  });
});
</script>

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
