<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

$kinds = ['category' => 'Category', 'brand' => 'Brand', 'model' => 'Model'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $kind = $_POST['kind'] ?? '';
    $name = trim($_POST['name'] ?? '');
    try {
        if ($action === 'tag_add' || $action === 'tag_update') {
            if (!isset($kinds[$kind]) || $name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a tag name (max 120 characters).');
            if ($action === 'tag_add') {
                $pdo->prepare('INSERT INTO machine_tags (kind,name) VALUES (?,?)')->execute([$kind, $name]);
                flash($kinds[$kind] . ' tag added.');
            } else {
                $pdo->prepare('UPDATE machine_tags SET name=? WHERE id=? AND kind=?')->execute([$name, $id, $kind]);
                flash('Tag updated. Existing machines keep their saved values.');
            }
        } elseif ($action === 'tag_delete') {
            $pdo->prepare('DELETE FROM machine_tags WHERE id=?')->execute([$id]);
            flash('Tag removed.');
        } elseif ($action === 'machine_save') {
            $v = [];
            foreach (['category', 'brand', 'model', 'model_code'] as $f) {
                $v[$f] = trim($_POST[$f] ?? '');
                if (($v[$f] === '' && $f !== 'model_code') || mb_strlen($v[$f]) > 120) throw new InvalidArgumentException('Fill in category, brand and model (model code is optional).');
            }
            $addTag = $pdo->prepare('INSERT IGNORE INTO machine_tags (kind,name) VALUES (?,?)');
            foreach (['category', 'brand', 'model'] as $f) $addTag->execute([$f, $v[$f]]);
            if ($id) {
                $pdo->prepare('UPDATE machines SET category=?, brand=?, model=?, model_code=? WHERE id=?')->execute([$v['category'], $v['brand'], $v['model'], $v['model_code'], $id]);
                flash('Machine updated.');
            } else {
                $pdo->prepare('INSERT INTO machines (category,brand,model,model_code) VALUES (?,?,?,?)')->execute([$v['category'], $v['brand'], $v['model'], $v['model_code']]);
                flash('Machine added.');
            }
        } elseif ($action === 'machine_delete') {
            $pdo->prepare('DELETE FROM machines WHERE id=?')->execute([$id]);
            flash('Machine deleted.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000' ? 'That tag already exists.' : 'Database error.', 'error');
    }
    redirect('admin/machines.php' . ($action === 'machine_save' && $id && isset($_SESSION['flash']) && ($_SESSION['flash'][1] ?? '') === 'error' ? '?edit=' . $id : ''));
}

$tags = array_fill_keys(array_keys($kinds), []);
foreach ($pdo->query('SELECT * FROM machine_tags ORDER BY name')->fetchAll() as $t) $tags[$t['kind']][] = $t;
$machines = $pdo->query('SELECT * FROM machines ORDER BY category, brand, model')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) foreach ($machines as $m) if ((int)$m['id'] === (int)$_GET['edit']) $edit = $m;

function machine_input(string $field, array $options, ?string $current): void { ?>
  <input name="<?= $field ?>" list="dl-<?= $field ?>" required maxlength="120" autocomplete="off" value="<?= e($current ?? '') ?>" placeholder="Type or pick a <?= $field ?>" class="mt-1 w-full border rounded px-3 py-2">
  <datalist id="dl-<?= $field ?>"><?php foreach ($options as $o): ?><option value="<?= e($o['name']) ?>"></option><?php endforeach; ?></datalist>
<?php }

page_header('Machines', $u);
?>
<h1 class="text-2xl font-bold mb-4">Machines</h1>

<form method="post" class="bg-white rounded shadow p-4 mb-6 grid gap-3 md:grid-cols-5 items-end">
  <?= csrf_field() ?><input type="hidden" name="action" value="machine_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label class="text-sm">Category<?php machine_input('category', $tags['category'], $edit['category'] ?? null); ?></label>
  <label class="text-sm">Brand<?php machine_input('brand', $tags['brand'], $edit['brand'] ?? null); ?></label>
  <label class="text-sm">Model<?php machine_input('model', $tags['model'], $edit['model'] ?? null); ?></label>
  <label class="text-sm">Model code <span class="text-slate-400">(optional)</span><input name="model_code" maxlength="120" value="<?= e($edit['model_code'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
  <div class="flex gap-2">
    <button class="bg-slate-900 text-white rounded px-4 py-2"><?= $edit ? 'Update' : 'Add machine' ?></button>
    <?php if ($edit): ?><a href="machines.php" class="px-3 py-2 text-sm text-slate-600">Cancel</a><?php endif; ?>
  </div>
</form>

<div class="grid gap-4 md:grid-cols-3 mb-6">
<?php foreach ($kinds as $kind => $label): ?>
  <section class="bg-white rounded shadow p-4">
    <h2 class="font-semibold mb-1"><?= $label ?> tags</h2>
    <p class="text-xs text-slate-500 mb-3">Suggested while typing in the machine form. New values you type are added here automatically.</p>
    <form method="post" class="flex gap-2 mb-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="tag_add"><input type="hidden" name="kind" value="<?= $kind ?>">
      <input name="name" required maxlength="120" placeholder="e.g. <?= ['category' => 'Computers', 'brand' => 'Asus', 'model' => 'E35'][$kind] ?>" class="flex-1 min-w-0 border rounded px-3 py-1.5 text-sm">
      <button class="bg-slate-900 text-white rounded px-3 py-1.5 text-sm">Add</button>
    </form>
    <div class="space-y-2">
    <?php foreach ($tags[$kind] as $t): ?>
      <div class="flex gap-2 items-center">
        <form method="post" class="flex gap-2 flex-1 min-w-0">
          <?= csrf_field() ?><input type="hidden" name="action" value="tag_update"><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <input name="name" required maxlength="120" value="<?= e($t['name']) ?>" class="flex-1 min-w-0 border rounded px-2 py-1 text-sm bg-slate-50">
          <button class="text-sm text-blue-600">Save</button>
        </form>
        <form method="post" onsubmit="return confirm('Remove this tag?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="tag_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="text-sm text-red-600">Remove</button>
        </form>
      </div>
    <?php endforeach; if (!$tags[$kind]) echo '<p class="text-sm text-slate-500">No tags yet.</p>'; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<div class="bg-white rounded shadow overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-left"><tr><th class="p-3">Category</th><th class="p-3">Brand</th><th class="p-3">Model</th><th class="p-3">Model code</th><th class="p-3"></th></tr></thead>
    <tbody>
    <?php foreach ($machines as $m): ?>
      <tr class="border-t">
        <td class="p-3"><?= e($m['category']) ?></td><td class="p-3"><?= e($m['brand']) ?></td><td class="p-3"><?= e($m['model']) ?></td><td class="p-3"><?= e($m['model_code']) ?></td>
        <td class="p-3 text-right whitespace-nowrap">
          <a href="machines.php?edit=<?= (int)$m['id'] ?>" class="text-blue-600 mr-3">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this machine?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="machine_delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="text-red-600">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; if (!$machines) echo '<tr><td class="p-3 text-slate-500" colspan="5">No machines yet.</td></tr>'; ?>
    </tbody>
  </table>
</div>
<?php page_footer();
