<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $phones = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['phone'] ?? [])), fn($p) => $p !== '')));
    $phonesValid = $phones && !array_filter($phones, fn($p) => !preg_match('/^[0-9]{10}$/', $p));
    $phone = $phones[0] ?? '';
    $address = trim($_POST['address'] ?? '');
    try {
        if ($action === 'create' && $name !== '' && $phonesValid && $address !== '' && mb_strlen($address) <= 255) {
            [$err, $email, $loginId, $hash, $enc] = sector_login_input();
            if ($err) {
                flash($err, 'error');
            } else {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO sectors (name,phone,email,login_id,password_hash,password_enc,address,description,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$name, $phone, $email, $loginId, $hash, $enc, $address, trim($_POST['description'] ?? ''), $u['id']]);
                $sid = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO sector_phones (sector_id,phone) VALUES (?,?)');
                foreach ($phones as $p) $ins->execute([$sid, $p]);
                if ($loginId !== null) $pdo->prepare('UPDATE sector_login_ids SET used=1 WHERE login_id=?')->execute([$loginId]);
                $pdo->commit();
                flash('Sector created.');
            }
        } else {
            flash('Enter a sector name, at least one 10-digit phone number, and an address.', 'error');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-3 gap-3 items-start">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm">Sector name<input name="name" required maxlength="150" class="mt-1 w-full border rounded px-3 py-2"></label>
  <div class="text-sm">Phone numbers
    <div id="phone-list" class="space-y-2 mt-1">
      <div class="phone-row flex gap-2"><input type="tel" name="phone[]" required minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="sector-phone w-full border rounded px-3 py-2"></div>
    </div>
    <button type="button" id="add-phone" class="mt-2 text-blue-600 text-sm">+ Add another number</button>
  </div>
  <label class="text-sm">Email address (optional if login ID is set)<input type="email" name="email" maxlength="190" class="mt-1 w-full border rounded px-3 py-2"></label>
  <div class="text-sm">Login ID (required without email)
    <div class="mt-1 flex gap-2"><input name="login_id" readonly maxlength="8" class="login-id w-full border rounded px-3 py-2 bg-slate-50 font-mono"><button type="button" class="gen-id bg-blue-600 hover:bg-blue-700 text-white rounded px-3 text-sm">Generate</button></div></div>
  <label class="text-sm">Login password<span class="relative block"><input type="password" name="password" required minlength="8" autocomplete="new-password" class="pr-10 mt-1 w-full border rounded px-3 py-2"><button type="button" class="pw-toggle absolute right-2 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-800" aria-label="Show password" aria-pressed="false"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/><path class="pw-slash hidden" d="M3 3l18 18"/></svg></button></span></label>
  <label class="text-sm">Address<input name="address" required maxlength="255" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm md:col-span-2">Description<input name="description" class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="self-end bg-blue-600 hover:bg-blue-700 text-white rounded py-2">Create sector</button>
</form>
<script>
document.querySelectorAll('.gen-id').forEach(btn => btn.addEventListener('click', async () => {
  const body = new URLSearchParams({csrf: <?= json_encode(csrf_token()) ?>});
  const r = await fetch('generate_id.php', {method: 'POST', body});
  if (r.ok) btn.closest('div').querySelector('.login-id').value = (await r.json()).id;
}));
const bindPhone = input => input.addEventListener('input', () => {
  input.value = input.value.replace(/\D/g, '').slice(0, 10);
});
document.querySelectorAll('.sector-phone').forEach(bindPhone);
document.getElementById('add-phone').addEventListener('click', () => {
  const row = document.createElement('div');
  row.className = 'phone-row flex gap-2';
  row.innerHTML = '<input type="tel" name="phone[]" minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" class="sector-phone w-full border rounded px-3 py-2"><button type="button" class="rm-phone text-red-600 px-2" aria-label="Remove">&times;</button>';
  row.querySelector('.rm-phone').addEventListener('click', () => row.remove());
  bindPhone(row.querySelector('input'));
  document.getElementById('phone-list').appendChild(row);
  row.querySelector('input').focus();
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
