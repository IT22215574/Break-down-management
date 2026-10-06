<?php
// Searchable multi-select dropdown with removable chips. $name is the submitted field name (e.g. technician_ids[]).
function technician_picker(array $technicians, array $selected, string $name): void {
    $selected = array_map('intval', $selected); ?>
<div class="tech-picker relative mt-1" data-name="<?= e($name) ?>">
  <button type="button" class="tp-toggle w-full border rounded px-3 py-2 bg-white text-left flex justify-between items-center">
    <span class="tp-label text-slate-500">Select technicians</span><span aria-hidden="true">▾</span></button>
  <div class="tp-panel hidden absolute z-20 mt-1 w-full bg-white border rounded shadow-lg">
    <input type="search" class="tp-search w-full border-b px-3 py-2 outline-none" placeholder="Search by name or mobile number" autocomplete="off">
    <ul class="tp-list max-h-56 overflow-y-auto">
      <?php foreach ($technicians as $t): ?>
      <li class="tp-item" data-search="<?= e(strtolower($t['name'] . ' ' . ($t['phone'] ?? ''))) ?>">
        <label class="flex items-center gap-2 px-3 py-2 hover:bg-slate-50 cursor-pointer">
          <input type="checkbox" value="<?= (int)$t['id'] ?>" data-name="<?= e($t['name']) ?>" <?= in_array((int)$t['id'], $selected, true) ? 'checked' : '' ?>>
          <span><?= e($t['name']) ?> <span class="text-slate-500">(<?= e($t['phone'] ?? '') ?>)</span></span></label></li>
      <?php endforeach; ?>
      <li class="tp-empty hidden px-3 py-2 text-slate-500"><?= $technicians ? 'No technicians match.' : 'Add a technician before assigning one.' ?></li>
    </ul>
  </div>
  <div class="tp-chips mt-2 flex flex-wrap gap-2"></div>
  <div class="tp-inputs"></div>
</div>
<?php }

// Outputs the picker behaviour once per page, outside any <template>.
function technician_picker_script(): void {
    static $done = false;
    if ($done) return;
    $done = true; ?>
<script>
(function () {
  function refresh(p) {
    const boxes = [...p.querySelectorAll('.tp-list input[type=checkbox]')];
    const chosen = boxes.filter(b => b.checked);
    const chips = p.querySelector('.tp-chips'), inputs = p.querySelector('.tp-inputs');
    chips.textContent = ''; inputs.textContent = '';
    chosen.forEach(b => {
      const chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1 bg-slate-100 border rounded-full pl-3 pr-1 py-0.5 text-sm';
      chip.append(b.dataset.name);
      const x = document.createElement('button');
      x.type = 'button'; x.className = 'tp-remove w-5 h-5 rounded-full hover:bg-red-100 text-red-600 leading-none';
      x.dataset.id = b.value; x.setAttribute('aria-label', 'Remove ' + b.dataset.name); x.textContent = '×';
      chip.append(x); chips.append(chip);
      const h = document.createElement('input');
      h.type = 'hidden'; h.name = p.dataset.name; h.value = b.value; inputs.append(h);
    });
    p.querySelector('.tp-label').textContent = chosen.length ? chosen.length + ' selected' : 'Select technicians';
    p.querySelector('.tp-label').classList.toggle('text-slate-500', !chosen.length);
    const search = p.querySelector('.tp-search');
    const needed = p.dataset.required === '1' && !chosen.length;
    search.setCustomValidity(needed ? 'Select at least one technician.' : '');
  }
  function filter(p) {
    const q = p.querySelector('.tp-search').value.trim().toLowerCase();
    let shown = 0;
    p.querySelectorAll('.tp-item').forEach(li => { const m = li.dataset.search.includes(q); li.classList.toggle('hidden', !m); if (m) shown++; });
    p.querySelector('.tp-empty').classList.toggle('hidden', shown > 0);
  }
  window.techPickerRefresh = refresh;
  window.techPickerRequired = (p, on) => { p.dataset.required = on ? '1' : '0'; refresh(p); };
  document.addEventListener('click', e => {
    const p = e.target.closest('.tech-picker');
    document.querySelectorAll('.tech-picker .tp-panel').forEach(panel => { if (!p || !p.contains(panel)) panel.classList.add('hidden'); });
    if (!p) return;
    if (e.target.closest('.tp-toggle')) {
      const panel = p.querySelector('.tp-panel'); panel.classList.toggle('hidden');
      if (!panel.classList.contains('hidden')) p.querySelector('.tp-search').focus();
    }
    const rm = e.target.closest('.tp-remove');
    if (rm) { const b = [...p.querySelectorAll('.tp-list input')].find(x => x.value === rm.dataset.id); if (b) b.checked = false; refresh(p); }
  });
  document.addEventListener('input', e => { const p = e.target.closest('.tech-picker'); if (p && e.target.matches('.tp-search')) filter(p); });
  document.addEventListener('change', e => { const p = e.target.closest('.tech-picker'); if (p && e.target.matches('.tp-list input')) refresh(p); });
  document.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.tp-search')) e.preventDefault(); });
  document.querySelectorAll('.tech-picker').forEach(refresh);
})();
</script>
<?php }
