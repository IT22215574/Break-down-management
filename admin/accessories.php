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
        } elseif ($action === 'accessory_save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $price = trim((string)($_POST['price'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120 || mb_strlen($brand) > 120 || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
                throw new InvalidArgumentException('Enter an accessory name, an optional brand (max 120 characters), and a valid non-negative price.');
            }
            $brand = $brand === '' ? null : $brand;
            $pdo->beginTransaction();
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
        flash($e->getCode() === '23000' && in_array($action, ['brand_tag_add', 'brand_tag_update'], true)
            ? 'That accessory brand tag already exists.'
            : 'Could not save the accessory.', 'error');
    }
    redirect('admin/accessories.php' . ($action === 'accessory_save' && $id && isset($_SESSION['flash']) && ($_SESSION['flash'][1] ?? '') === 'error' ? '?edit=' . $id : ''));
}

$accessories = $pdo->query('SELECT * FROM accessories ORDER BY name')->fetchAll();
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
  <label class="text-sm">Name<input name="name" required maxlength="120" value="<?= e($edit['name'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Brand <span class="text-slate-400">(optional)</span><input name="brand" list="accessory-brand-tags" maxlength="120" autocomplete="off" value="<?= e($edit['brand'] ?? '') ?>" placeholder="Type or select a brand" class="mt-1 w-full border rounded px-3 py-2"><datalist id="accessory-brand-tags"><?php foreach ($brandTags as $brandTag): ?><option value="<?= e($brandTag['name']) ?>"></option><?php endforeach; ?></datalist><span class="block min-h-5 text-xs text-slate-500">New brands are saved as suggestions.</span></label>
  <label class="text-sm">Price<input type="number" name="price" required min="0" max="99999999.99" step="0.01" value="<?= e($edit['price'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
  <div class="md:col-span-3 flex gap-2">
    <button class="bg-slate-900 text-white rounded px-4 py-2"><?= $edit ? 'Update accessory' : 'Add accessory' ?></button>
    <?php if ($edit): ?><a href="accessories.php" class="px-3 py-2 text-sm text-slate-600">Cancel</a><?php endif; ?>
  </div>
</form>

<section class="bg-white rounded shadow p-4 mb-6">
  <h2 class="font-semibold mb-1">Accessory brand tags</h2>
  <p class="text-sm text-slate-500 mb-3">Create suggestions for the optional Brand field. These tags are separate from machine brands.</p>
  <form method="post" class="flex flex-wrap gap-2 mb-4">
    <?= csrf_field() ?><input type="hidden" name="action" value="brand_tag_add">
    <input name="brand_tag" required maxlength="120" placeholder="New accessory brand" class="flex-1 min-w-48 border rounded px-3 py-2">
    <button class="bg-slate-900 text-white rounded px-4 py-2 text-sm">Add brand tag</button>
  </form>
  <div class="flex flex-wrap gap-2">
    <?php foreach ($brandTags as $brandTag): ?>
      <div class="flex items-center gap-2 rounded border p-2 text-sm">
        <form method="post" class="flex items-center gap-2">
          <?= csrf_field() ?><input type="hidden" name="action" value="brand_tag_update"><input type="hidden" name="id" value="<?= (int)$brandTag['id'] ?>">
          <input name="brand_tag" required maxlength="120" value="<?= e($brandTag['name']) ?>" aria-label="Edit <?= e($brandTag['name']) ?>" class="w-40 border rounded px-2 py-1">
          <button class="text-blue-600">Save</button>
        </form>
        <form method="post" onsubmit="return confirm('Remove this accessory brand tag?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="brand_tag_delete"><input type="hidden" name="id" value="<?= (int)$brandTag['id'] ?>">
          <button class="text-red-600" aria-label="Remove <?= e($brandTag['name']) ?>">Remove</button>
        </form>
      </div>
    <?php endforeach; if (!$brandTags): ?><p class="text-sm text-slate-500">No accessory brand tags yet.</p><?php endif; ?>
  </div>
</section>

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
<?php page_footer();
