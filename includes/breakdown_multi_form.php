<?php
// Expects $formSector (int), $formAction (string), $u.
$cls = 'mt-1 w-full border rounded px-3 py-2';
$now = date('Y-m-d\TH:i');
$companies = all_companies();
$technicians = $u['role'] === 'admin' ? active_technicians() : [];
$fixedByName = person_name_parts((string)$u['name']);
?>
<form method="post" action="<?= e($formAction . '?sector=' . $formSector) ?>" class="mb-6"><?= csrf_field() ?>
  <input type="hidden" name="action" value="create_many"><input type="hidden" name="sector_id" value="<?= (int)$formSector ?>">
  <div id="forms" class="space-y-3"></div>
  <div class="mt-3 flex gap-2">
    <button type="button" id="addForm" class="border border-slate-400 rounded px-4 py-2 text-sm">+ Add another form</button>
    <button class="bg-slate-900 text-white rounded px-4 py-2 text-sm">Save all</button>
  </div>
</form>
<template id="formTpl">
  <div class="bg-white rounded shadow p-4 grid md:grid-cols-3 gap-3 entry">
    <div class="md:col-span-3 flex justify-between"><h2 class="font-semibold">Breakdown form <span class="num"></span></h2>
      <button type="button" class="remove text-sm text-red-600">Remove</button></div>
    <label class="text-sm">Company<select data-n="company_id" class="<?= $cls ?>">
      <option value="">— Select company —</option>
      <?php foreach ($companies as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label class="text-sm">System broken down<input data-n="system_name" maxlength="190" class="<?= $cls ?>"></label>
    <label class="text-sm">Date &amp; time<input type="datetime-local" data-n="occurred_at" value="<?= $now ?>" class="<?= $cls ?>"></label>
    <label class="text-sm">Status<select data-n="status" class="<?= $cls ?>">
      <?php foreach (STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'fixed' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="text-sm">Contacted by (client side)<div class="mt-1 flex gap-1">
      <select data-n="client_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>"><?= e($title) ?></option><?php endforeach; ?></select>
      <input data-n="client_name" maxlength="138" class="<?= $cls ?> mt-0"></div></label>
    <label class="text-sm">Fixed by (company side)<div class="mt-1 flex gap-1">
      <select data-n="fixed_by_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $fixedByName[0] === $value ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?></select>
      <input data-n="fixed_by" maxlength="138" value="<?= e($fixedByName[1]) ?>" class="<?= $cls ?> mt-0"></div></label>
    <?php if ($u['role'] === 'admin'): ?>
    <label class="text-sm flex items-center gap-2"><input type="checkbox" data-n="technician_required" value="1"> Technician required (for non-online issues)</label>
    <label class="text-sm technician-select-wrap hidden">Assign technician<select data-n="technician_id" class="<?= $cls ?>">
      <option value="">Select a technician</option>
      <?php foreach ($technicians as $technician): ?><option value="<?= (int)$technician['id'] ?>"><?= e($technician['name']) ?> (<?= e($technician['phone'] ?? '') ?>)</option><?php endforeach; ?></select>
      <?php if (!$technicians): ?><span class="text-xs text-slate-500">Add a technician before assigning one.</span><?php endif; ?>
    </label>
    <?php endif; ?>
    <label class="text-sm md:col-span-3">Note (what was done to fix it)<textarea data-n="note" rows="2" class="<?= $cls ?>"></textarea></label>
  </div>
</template>
<script>
(function () {
  const box = document.getElementById('forms'), tpl = document.getElementById('formTpl');
  function renumber() {
    box.querySelectorAll('.entry').forEach((el, i) => {
      el.querySelector('.num').textContent = '#' + (i + 1);
      el.querySelectorAll('[data-n]').forEach(f => f.name = 'entries[' + i + '][' + f.dataset.n + ']');
      const required = el.querySelector('[data-n="technician_required"]');
      if (required) {
        const select = el.querySelector('[data-n="technician_id"]');
        required.onchange = () => {
          el.querySelector('.technician-select-wrap').classList.toggle('hidden', !required.checked);
          select.required = required.checked;
        };
      }
    });
  }
  function add() {
    const el = tpl.content.firstElementChild.cloneNode(true);
    el.querySelector('.remove').onclick = () => { if (box.children.length > 1) { el.remove(); renumber(); } };
    box.appendChild(el); renumber();
  }
  document.getElementById('addForm').onclick = add;
  add();
})();
</script>
