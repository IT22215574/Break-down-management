<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

$id = (int)($_POST['id'] ?? $_GET['edit'] ?? 0);
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'brand_tag_add') {
            $brandTag = trim((string)($_POST['brand_tag'] ?? ''));
            if ($brandTag === '' || mb_strlen($brandTag) > 120) {
                throw new InvalidArgumentException('Enter a brand tag (max 120 characters).');
            }
            $pdo->prepare('INSERT INTO accessory_brand_tags (name) VALUES (?)')->execute([$brandTag]);
            flash('Accessory brand tag added.');
        } elseif ($action === 'brand_tag_update') {
            $brandTag = trim((string)($_POST['brand_tag'] ?? ''));
            if ($brandTag === '' || mb_strlen($brandTag) > 120) {
                throw new InvalidArgumentException('Enter a brand tag (max 120 characters).');
            }
            $pdo->prepare('UPDATE accessory_brand_tags SET name=? WHERE id=?')->execute([$brandTag, $id]);
            flash('Accessory brand tag updated. Existing accessories keep their saved brand.');
        } elseif ($action === 'brand_tag_delete') {
            $pdo->prepare('DELETE FROM accessory_brand_tags WHERE id=?')->execute([$id]);
            flash('Accessory brand tag removed. Existing accessories keep their saved brand.');
        } elseif ($action === 'name_tag_add' || $action === 'name_tag_update') {
            $nameTag = trim((string)($_POST['name_tag'] ?? ''));
            if ($nameTag === '' || mb_strlen($nameTag) > 120) {
                throw new InvalidArgumentException('Enter a name tag (max 120 characters).');
            }
            if ($action === 'name_tag_add') {
                $pdo->prepare('INSERT INTO accessory_name_tags (name) VALUES (?)')->execute([$nameTag]);
                flash('Accessory name tag added.');
            } else {
                $pdo->prepare('UPDATE accessory_name_tags SET name=? WHERE id=?')->execute([$nameTag, $id]);
                flash('Accessory name tag updated. Existing accessories keep their saved name.');
            }
        } elseif ($action === 'name_tag_delete') {
            $pdo->prepare('DELETE FROM accessory_name_tags WHERE id=?')->execute([$id]);
            flash('Accessory name tag removed. Existing accessories keep their saved name.');
        } elseif ($action === 'accessory_save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $price = trim((string)($_POST['price'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120 || mb_strlen($brand) > 120 || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
                throw new InvalidArgumentException('Enter an accessory name, an optional brand (max 120 characters), and a valid non-negative price.');
            }
            $brand = $brand === '' ? null : $brand;
            $pdo->beginTransaction();
            $pdo->prepare('INSERT IGNORE INTO accessory_name_tags (name) VALUES (?)')->execute([$name]);
            if ($brand !== null) {
                $pdo->prepare('INSERT IGNORE INTO accessory_brand_tags (name) VALUES (?)')->execute([$brand]);
            }
            if ($id) {
                $pdo->prepare('UPDATE accessories SET name=?, brand=?, price=? WHERE id=?')->execute([$name, $brand, $price, $id]);
                flash('Accessory updated.');
            } else {
                $pdo->prepare('INSERT INTO accessories (name,brand,price) VALUES (?,?,?)')->execute([$name, $brand, $price]);
                flash('Accessory added.');
            }
            $pdo->commit();
        } elseif ($action === 'accessory_delete') {
            $pdo->prepare('DELETE FROM accessories WHERE id=?')->execute([$id]);
            flash('Accessory deleted.');
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getCode() === '23000' && in_array($action, ['brand_tag_add', 'brand_tag_update', 'name_tag_add', 'name_tag_update'], true)
            ? 'That accessory tag already exists.'
            : 'Could not save the accessory.', 'error');
    }
    redirect('admin/accessories.php' . ($action === 'accessory_save' && $id && isset($_SESSION['flash']) && ($_SESSION['flash'][1] ?? '') === 'error' ? '?edit=' . $id : ''));
}

$accessories = $pdo->query('SELECT * FROM accessories ORDER BY name')->fetchAll();
$nameTags = $pdo->query('SELECT id,name FROM accessory_name_tags ORDER BY name')->fetchAll();
$brandTags = $pdo->query('SELECT id,name FROM accessory_brand_tags ORDER BY name')->fetchAll();
foreach ($accessories as $accessory) {
    if ((int)$accessory['id'] === $id) {
        $edit = $accessory;
        break;
    }
}

page_header('Accessories', $u);
?>
<h1 class="text-2xl font-bold mb-4">Accessories</h1>

<form method="post" class="bg-white rounded shadow p-4 mb-6 grid gap-3 md:grid-cols-3 items-start">
  <?= csrf_field() ?><input type="hidden" name="action" value="accessory_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label class="text-sm">Name<input name="name" list="accessory-name-tags" required maxlength="120" autocomplete="off" value="<?= e($edit['name'] ?? '') ?>" placeholder="Type or select a name" class="mt-1 w-full border rounded px-3 py-2"><datalist id="accessory-name-tags"><?php foreach ($nameTags as $nameTag): ?><option value="<?= e($nameTag['name']) ?>"></option><?php endforeach; ?></datalist><span class="block min-h-5 text-xs text-slate-500">New names are saved as suggestions.</span></label>
  <label class="text-sm">Brand <span class="text-slate-400">(optional)</span><input name="brand" list="accessory-brand-tags" maxlength="120" autocomplete="off" value="<?= e($edit['brand'] ?? '') ?>" placeholder="Type or select a brand" class="mt-1 w-full border rounded px-3 py-2"><datalist id="accessory-brand-tags"><?php foreach ($brandTags as $brandTag): ?><option value="<?= e($brandTag['name']) ?>"></option><?php endforeach; ?></datalist><span class="block min-h-5 text-xs text-slate-500">New brands are saved as suggestions.</span></label>
  <label class="text-sm">Price<input type="number" name="price" required min="0" max="99999999.99" step="0.01" value="<?= e($edit['price'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
  <div class="md:col-span-3 flex gap-2 justify-end">
    <button class="bg-slate-900 text-white rounded px-4 py-2"><?= $edit ? 'Update accessory' : 'Add accessory' ?></button>
    <?php if ($edit): ?><a href="accessories.php" class="px-3 py-2 text-sm text-slate-600">Cancel</a><?php endif; ?>
  </div>
</form>

<?php
function tag_row(array $tag, string $kind, string $label): void { ?>
      <div class="flex gap-2 items-center" data-tag="<?= e(mb_strtolower($tag['name'])) ?>">
        <form method="post" class="flex gap-2 flex-1 min-w-0">
          <?= csrf_field() ?><input type="hidden" name="action" value="<?= $kind ?>_tag_update"><input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
          <input name="<?= $kind ?>_tag" required maxlength="120" value="<?= e($tag['name']) ?>" aria-label="Edit <?= e($tag['name']) ?>" class="flex-1 min-w-0 border rounded px-2 py-1 text-sm bg-slate-50">
          <button class="text-sm text-blue-600">Save</button>
        </form>
        <form method="post" onsubmit="return confirm('Remove this accessory <?= $label ?> tag?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="<?= $kind ?>_tag_delete"><input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
          <button class="text-sm text-red-600" aria-label="Remove <?= e($tag['name']) ?>">Remove</button>
        </form>
      </div>
<?php }
$tagSections = [
    'name' => ['label' => 'name', 'tags' => $nameTags, 'desc' => 'Create suggestions for the accessory Name field.'],
    'brand' => ['label' => 'brand', 'tags' => $brandTags, 'desc' => 'Create suggestions for the optional Brand field. These tags are separate from machine brands.'],
];
?>
<div class="grid gap-4 md:grid-cols-2 items-start mb-6">
<?php foreach ($tagSections as $kind => $sec): $list = $sec['tags']; $label = $sec['label']; ?>
<section class="bg-white rounded shadow p-4">
  <h2 class="font-semibold mb-1">Accessory <?= $label ?> tags</h2>
  <p class="text-xs text-slate-500 mb-3"><?= $sec['desc'] ?></p>
  <form method="post" class="flex gap-2 mb-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $kind ?>_tag_add">
    <input name="<?= $kind ?>_tag" required maxlength="120" placeholder="New accessory <?= $label ?>" class="flex-1 min-w-0 border rounded px-3 py-1.5 text-sm">
    <button class="bg-slate-900 text-white rounded px-3 py-1.5 text-sm">Add</button>
  </form>
  <div class="space-y-2">
    <?php foreach (array_slice($list, 0, 6) as $tag) tag_row($tag, $kind, $label); if (!$list): ?><p class="text-sm text-slate-500">No accessory <?= $label ?> tags yet.</p><?php endif; ?>
  </div>
  <?php if (count($list) > 6): ?>
    <div class="mt-3 text-right"><button type="button" data-open="modal-<?= $kind ?>-tags" class="bg-slate-900 text-white rounded px-3 py-1.5 text-sm">Show more (<?= count($list) ?>)</button></div>
    <div id="modal-<?= $kind ?>-tags" class="hidden fixed inset-0 z-50 bg-black/50 items-center justify-center p-4">
      <div class="bg-white rounded shadow w-full max-w-lg max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between p-4 border-b">
          <h3 class="font-semibold">Accessory <?= $label ?> tags</h3>
          <button type="button" data-close aria-label="Close" class="text-2xl leading-none text-slate-500 hover:text-slate-900 px-2">&times;</button>
        </div>
        <div class="p-4 pb-2"><input type="search" data-search placeholder="Search tags..." class="w-full border rounded px-3 py-1.5 text-sm"></div>
        <div class="px-4 pb-4 overflow-y-scroll max-h-[27.5rem] space-y-2" data-list>
          <?php foreach ($list as $tag) tag_row($tag, $kind, $label); ?>
          <p data-empty class="hidden text-sm text-slate-500">No matching tags.</p>
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
</div>

<div class="bg-white rounded shadow overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-left"><tr><th class="p-3">Name</th><th class="p-3">Brand</th><th class="p-3">Price</th><th class="p-3"></th></tr></thead>
    <tbody>
    <?php foreach ($accessories as $accessory): ?>
      <tr class="border-t">
        <td class="p-3"><?= e($accessory['name']) ?></td>
        <td class="p-3"><?= e($accessory['brand'] ?? '') ?></td>
        <td class="p-3"><?= e(number_format((float)$accessory['price'], 2)) ?></td>
        <td class="p-3 text-right whitespace-nowrap">
          <a href="accessories.php?edit=<?= (int)$accessory['id'] ?>" class="text-blue-600 mr-3">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this accessory?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="accessory_delete"><input type="hidden" name="id" value="<?= (int)$accessory['id'] ?>">
            <button class="text-red-600">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; if (!$accessories): ?><tr><td class="p-3 text-slate-500" colspan="4">No accessories yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<script>
document.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => {
  const m = document.getElementById(b.dataset.open); m.classList.remove('hidden'); m.classList.add('flex'); document.body.style.overflow = 'hidden';
  m.querySelector('[data-search]').focus();
}));
document.querySelectorAll('[id^="modal-"]').forEach(m => {
  const close = () => { m.classList.add('hidden'); m.classList.remove('flex'); document.body.style.overflow = ''; };
  m.querySelector('[data-close]').addEventListener('click', close);
  m.addEventListener('click', e => { if (e.target === m) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !m.classList.contains('hidden')) close(); });
  const rows = m.querySelectorAll('[data-tag]'), empty = m.querySelector('[data-empty]');
  m.querySelector('[data-search]').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase(); let n = 0;
    rows.forEach(r => { const ok = r.dataset.tag.includes(q); r.classList.toggle('hidden', !ok); n += ok; });
    empty.classList.toggle('hidden', n > 0);
  });
});
</script>
<?php page_footer();
