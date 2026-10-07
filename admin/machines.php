<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

$kinds = ['category' => 'Category', 'brand' => 'Brand', 'model' => 'Model'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
    $name = trim($_POST['name'] ?? '');
    try {
        if ($action === 'tags_save') {
            $changes = json_decode((string)($_POST['tag_changes'] ?? ''), true);
            if (!isset($kinds[$kind]) || !is_array($changes)) {
                throw new InvalidArgumentException('Invalid tag changes.');
            }
            $updates = $changes['updates'] ?? [];
            $deletes = $changes['deletes'] ?? [];
            $additions = $changes['additions'] ?? [];
            if (!is_array($updates) || !is_array($deletes) || !is_array($additions)) {
                throw new InvalidArgumentException('Invalid tag changes.');
            }
            foreach ($updates as $tagId => $tagName) {
                if ((int)$tagId < 1 || !is_string($tagName) || trim($tagName) === '' || mb_strlen(trim($tagName)) > 120) {
                    throw new InvalidArgumentException('Enter tag names up to 120 characters.');
                }
            }
            foreach ($deletes as $tagId) {
                if (!is_scalar($tagId) || (int)$tagId < 1) throw new InvalidArgumentException('Invalid tag to remove.');
            }
            foreach ($additions as $tagName) {
                if (!is_string($tagName) || trim($tagName) === '' || mb_strlen(trim($tagName)) > 120) {
                    throw new InvalidArgumentException('Enter tag names up to 120 characters.');
                }
            }
            $pdo->beginTransaction();
            $updateTag = $pdo->prepare('UPDATE machine_tags SET name=? WHERE id=? AND kind=?');
            foreach ($updates as $tagId => $tagName) $updateTag->execute([trim($tagName), (int)$tagId, $kind]);
            $deleteTag = $pdo->prepare('DELETE FROM machine_tags WHERE id=? AND kind=?');
            foreach (array_unique(array_map('intval', $deletes)) as $tagId) $deleteTag->execute([$tagId, $kind]);
            $addTag = $pdo->prepare('INSERT INTO machine_tags (kind,name) VALUES (?,?)');
            foreach ($additions as $tagName) $addTag->execute([$kind, trim($tagName)]);
            $pdo->commit();
            flash('All tag changes saved.');
        } elseif ($action === 'tag_add' || $action === 'tag_update') {
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getCode() === '23000' ? 'That tag already exists.' : 'Database error.', 'error');
    }
    redirect($action === 'tags_save' && isset($kinds[$kind])
        ? 'admin/machines.php?open=' . rawurlencode($kind)
        : 'admin/machines.php' . ($action === 'machine_save' && $id && isset($_SESSION['flash']) && ($_SESSION['flash'][1] ?? '') === 'error' ? '?edit=' . $id : ''));
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
    <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2"><?= $edit ? 'Update' : 'Add machine' ?></button>
    <?php if ($edit): ?><a href="machines.php" class="px-3 py-2 text-sm text-slate-600">Cancel</a><?php endif; ?>
  </div>
</form>

<?php
function tag_row(array $t, string $kind, bool $staged = false): void { ?>
    <?php if ($staged): ?>
      <div class="flex gap-2 items-center" data-tag-row data-original-id="<?= (int)$t['id'] ?>">
        <input data-tag-id="<?= (int)$t['id'] ?>" required maxlength="120" value="<?= e($t['name']) ?>" class="flex-1 min-w-0 border rounded px-2 py-1 text-sm bg-slate-50">
        <button type="button" data-remove-tag class="text-sm text-red-600">Remove</button>
      </div>
    <?php else: ?>
      <div class="flex gap-2 items-center" data-tag="<?= e(mb_strtolower($t['name'])) ?>">
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
    <?php endif; ?>
<?php } ?>
<div class="grid gap-4 md:grid-cols-3 mb-6">
<?php foreach ($kinds as $kind => $label): ?>
  <section class="bg-white rounded shadow p-4">
    <h2 class="font-semibold mb-1"><?= $label ?> tags</h2>
    <p class="text-xs text-slate-500 mb-3">Suggested while typing in the machine form. New values you type are added here automatically.</p>
    <form method="post" class="flex gap-2 mb-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="tag_add"><input type="hidden" name="kind" value="<?= $kind ?>">
      <input name="name" required maxlength="120" placeholder="e.g. <?= ['category' => 'Computers', 'brand' => 'Asus', 'model' => 'E35'][$kind] ?>" class="flex-1 min-w-0 border rounded px-3 py-1.5 text-sm">
      <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm">Add</button>
    </form>
    <div class="space-y-2">
    <?php foreach (array_slice($tags[$kind], 0, 5) as $t) tag_row($t, $kind); if (!$tags[$kind]) echo '<p class="text-sm text-slate-500">No tags yet.</p>'; ?>
    </div>
    <?php if (count($tags[$kind]) > 5): ?>
      <div class="mt-3 text-right"><button type="button" data-open="modal-<?= $kind ?>" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm">Show more (<?= count($tags[$kind]) ?>)</button></div>
    <?php endif; ?>
    <?php if (count($tags[$kind]) > 5 || ($_GET['open'] ?? '') === $kind): ?>
      <div id="modal-<?= $kind ?>" class="hidden fixed inset-0 z-50 bg-black/50 items-center justify-center p-4">
        <div class="bg-white rounded shadow w-full max-w-lg max-h-[85vh] flex flex-col" data-kind="<?= $kind ?>">
          <div class="flex items-center justify-between p-4 border-b">
            <h3 class="font-semibold"><?= $label ?> tags</h3>
            <button type="button" data-close aria-label="Close without saving" class="text-2xl leading-none text-slate-500 hover:text-slate-900 px-2">&times;</button>
          </div>
          <div class="p-4 pb-2"><input type="search" data-search placeholder="Search tags..." class="w-full border rounded px-3 py-1.5 text-sm"></div>
          <div class="px-4 pb-4 overflow-y-scroll max-h-[27.5rem] space-y-2" data-list>
            <?php foreach ($tags[$kind] as $t) tag_row($t, $kind, true); ?>
            <p data-empty class="hidden text-sm text-slate-500">No matching tags.</p>
          </div>
          <div class="border-t p-4 space-y-3">
            <div class="flex gap-2">
              <input type="text" data-new-tag-input maxlength="120" placeholder="Add a tag..." class="flex-1 min-w-0 border rounded px-3 py-1.5 text-sm">
              <button type="button" data-add-tag class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm">Add</button>
            </div>
            <div class="flex justify-end gap-2">
              <button type="button" data-cancel class="border rounded px-3 py-1.5 text-sm">Cancel</button>
              <button type="button" data-save-all class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm">Save all</button>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
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
<script>
const refreshTagList = modal => {
  const query = modal.querySelector('[data-search]').value.trim().toLowerCase();
  let visible = 0;
  modal.querySelectorAll('[data-tag-row]').forEach(row => {
    const removed = row.dataset.removed === 'true';
    const input = row.querySelector('input');
    const matches = !removed && input.value.toLowerCase().includes(query);
    row.classList.toggle('hidden', !matches);
    if (matches) visible++;
  });
  modal.querySelector('[data-empty]').classList.toggle('hidden', visible > 0);
};
const closeTagModal = modal => {
  modal.querySelectorAll('[data-tag-id]').forEach(input => { input.value = input.defaultValue; });
  modal.querySelectorAll('[data-original-id]').forEach(row => { delete row.dataset.removed; });
  modal.querySelectorAll('[data-new-tag]').forEach(row => row.remove());
  modal.querySelector('[data-new-tag-input]').value = '';
  modal.querySelector('[data-search]').value = '';
  refreshTagList(modal);
  modal.classList.add('hidden');
  modal.classList.remove('flex');
  document.body.style.overflow = '';
};
const openTagModal = modal => {
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  document.body.style.overflow = 'hidden';
  modal.querySelector('[data-search]').focus();
};
document.querySelectorAll('[data-open]').forEach(button => button.addEventListener('click', () => {
  openTagModal(document.getElementById(button.dataset.open));
}));
document.querySelectorAll('[id^="modal-"]').forEach(modal => {
  modal.querySelector('[data-close]').addEventListener('click', () => closeTagModal(modal));
  modal.querySelector('[data-cancel]').addEventListener('click', () => closeTagModal(modal));
  modal.querySelector('[data-search]').addEventListener('input', () => refreshTagList(modal));
  modal.querySelector('[data-list]').addEventListener('input', event => {
    if (event.target.matches('input')) refreshTagList(modal);
  });
  modal.querySelector('[data-list]').addEventListener('click', event => {
    const removeButton = event.target.closest('[data-remove-tag]');
    if (!removeButton) return;
    const row = removeButton.closest('[data-tag-row]');
    if (row.hasAttribute('data-new-tag')) row.remove();
    else row.dataset.removed = 'true';
    refreshTagList(modal);
  });
  modal.querySelector('[data-add-tag]').addEventListener('click', () => {
    const input = modal.querySelector('[data-new-tag-input]');
    const name = input.value.trim();
    if (!name || name.length > 120) {
      input.focus();
      return;
    }
    const row = document.createElement('div');
    row.className = 'flex gap-2 items-center';
    row.dataset.tagRow = '';
    row.dataset.newTag = '';
    const tagInput = document.createElement('input');
    tagInput.value = name;
    tagInput.maxLength = 120;
    tagInput.required = true;
    tagInput.className = 'flex-1 min-w-0 border rounded px-2 py-1 text-sm bg-slate-50';
    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.dataset.removeTag = '';
    removeButton.className = 'text-sm text-red-600';
    removeButton.textContent = 'Remove';
    row.append(tagInput, removeButton);
    modal.querySelector('[data-empty]').before(row);
    input.value = '';
    refreshTagList(modal);
  });
  modal.querySelector('[data-save-all]').addEventListener('click', () => {
    const updates = {};
    const deletes = [];
    const additions = [];
    let valid = true;
    modal.querySelectorAll('[data-tag-row]').forEach(row => {
      if (row.dataset.removed === 'true') {
        if (row.dataset.originalId) deletes.push(row.dataset.originalId);
        return;
      }
      const input = row.querySelector('input');
      const value = input.value.trim();
      if (!value) {
        input.focus();
        valid = false;
        return;
      }
      if (row.hasAttribute('data-new-tag')) additions.push(value);
      else if (value !== input.defaultValue) updates[input.dataset.tagId] = value;
    });
    if (!valid) return;
    const form = document.createElement('form');
    form.method = 'post';
    form.action = <?= json_encode(url('admin/machines.php')) ?>;
    const fields = {
      csrf: <?= json_encode(csrf_token()) ?>,
      action: 'tags_save',
      kind: modal.querySelector('[data-kind]').dataset.kind,
      tag_changes: JSON.stringify({updates, deletes, additions})
    };
    Object.entries(fields).forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.append(input);
    });
    document.body.append(form);
    form.submit();
  });
});
<?php if (isset($kinds[$_GET['open'] ?? ''])): ?>
openTagModal(document.getElementById(<?= json_encode('modal-' . $_GET['open']) ?>));
<?php endif; ?>
</script>
<?php page_footer();
