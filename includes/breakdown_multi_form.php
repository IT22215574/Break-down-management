<?php
// Expects $formSector (int), $formAction (string), $u.
$cls = 'mt-1 w-full border rounded px-3 py-2';
$now = date('Y-m-d\TH:i');
$companies = all_companies();
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
    <label class="text-sm">Contacted by (client side)<input data-n="client_name" maxlength="150" class="<?= $cls ?>"></label>
    <label class="text-sm">Fixed by (company side)<input data-n="fixed_by" maxlength="150" value="<?= e($u['name']) ?>" class="<?= $cls ?>"></label>
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
