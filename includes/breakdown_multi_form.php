<?php
require_once __DIR__ . '/technician_picker.php';
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
    <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm">Save all</button>
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
    <label class="text-sm">Contact mobile number<input type="tel" data-n="contact_phone" minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" title="Enter exactly 10 digits" placeholder="e.g. 0771234567" class="<?= $cls ?>"></label>
    <label class="text-sm">Fixed by (company side)<div class="mt-1 flex gap-1">
      <select data-n="fixed_by_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $fixedByName[0] === $value ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?></select>
      <input data-n="fixed_by" maxlength="138" value="<?= e($fixedByName[1]) ?>" class="<?= $cls ?> mt-0"></div></label>
    <?php if ($u['role'] === 'admin'): ?>
    <label class="text-sm flex items-center gap-2"><input type="checkbox" data-n="technician_required" value="1"> Technician required (for non-online issues)</label>
    <div class="text-sm technician-select-wrap hidden md:col-span-2">Assign technicians
      <?php technician_picker($technicians, [], 'technician_ids[]'); ?></div>
    <a class="quotation-link hidden bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-2 text-sm text-center self-end" href="<?= url('quotation.php?sector_id=' . $formSector) ?>" target="_blank" rel="noopener">Make Quotations</a>
    <?php endif; ?>
    <label class="text-sm md:col-span-3">Breakdown<textarea data-n="description" rows="2" class="<?= $cls ?>"></textarea></label>
    <label class="text-sm md:col-span-3">Note (what was done to fix it)<textarea data-n="note" rows="2" class="<?= $cls ?>"></textarea></label>
  </div>
</template>
<?php technician_picker_script(); ?>
<script>
document.addEventListener('input', e => {
  if (e.target.matches('[name="contact_phone"], [data-n="contact_phone"]')) e.target.value = e.target.value.replace(/\D/g, '').slice(0, 10);
});
(function () {
  const box = document.getElementById('forms'), tpl = document.getElementById('formTpl');
  function renumber() {
    box.querySelectorAll('.entry').forEach((el, i) => {
      el.querySelector('.num').textContent = '#' + (i + 1);
      el.querySelectorAll('[data-n]').forEach(f => f.name = 'entries[' + i + '][' + f.dataset.n + ']');
      const required = el.querySelector('[data-n="technician_required"]');
      if (required) {
        const picker = el.querySelector('.tech-picker');
        picker.dataset.name = 'entries[' + i + '][technician_ids][]';
        window.techPickerRefresh(picker);
        const quotationLink = el.querySelector('.quotation-link');
        required.onchange = () => {
          el.querySelector('.technician-select-wrap').classList.toggle('hidden', !required.checked);
          quotationLink.classList.toggle('hidden', !required.checked);
          window.techPickerRequired(picker, required.checked);
        };
        quotationLink.onclick = event => {
          event.preventDefault();
          const quoteUrl = new URL(quotationLink.href, window.location.href);
          const title = el.querySelector('[data-n="client_title"]').value;
          const contactName = el.querySelector('[data-n="client_name"]').value;
          quoteUrl.searchParams.set('contact_name', title ? title + '. ' + contactName : contactName);
          const companyId = el.querySelector('[data-n="company_id"]').value;
          if (companyId) quoteUrl.searchParams.set('company_id', companyId);
          quoteUrl.searchParams.set('contact_phone', el.querySelector('[data-n="contact_phone"]').value);
          quoteUrl.searchParams.set('machine_model', el.querySelector('[data-n="system_name"]').value);
          quoteUrl.searchParams.set('breakdown', el.querySelector('[data-n="description"]').value);
          window.open(quoteUrl.toString(), '_blank', 'noopener');
        };
      }
    });
  }
  function add() {
    const el = tpl.content.firstElementChild.cloneNode(true);
    el.querySelector('.remove').onclick = () => {
      el.remove();
      if (box.children.length === 0) add();
      else renumber();
    };
    box.appendChild(el); renumber();
  }
  document.getElementById('addForm').onclick = add;
  add();
})();
</script>
