<?php
// Expects $row (array), $sectors, $heading, $submit.
$cls = 'mt-1 w-full border rounded px-3 py-2';
$clientName = person_name_parts((string)$row['client_name']);
$fixedByName = person_name_parts((string)$row['fixed_by']);
?>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-3 gap-3"><?= csrf_field() ?>
  <h2 class="md:col-span-3 font-semibold"><?= e($heading) ?></h2>
  <label class="text-sm">Sector<select name="sector_id" class="<?= $cls ?>">
    <?php foreach ($sectors as $s): ?><option value="<?= $s['id'] ?>" <?= $row['sector_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
  <label class="text-sm">Company<select name="company_id" class="<?= $cls ?>"><option value="">— None —</option>
    <?php foreach (all_companies() as $c): ?><option value="<?= $c['id'] ?>" <?= ($row['company_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
  <label class="text-sm">System broken down<input name="system_name" required maxlength="190" value="<?= e($row['system_name']) ?>" class="<?= $cls ?>"></label>
  <label class="text-sm">Date &amp; time<input type="datetime-local" name="occurred_at" required value="<?= e(date('Y-m-d\TH:i', strtotime($row['occurred_at']))) ?>" class="<?= $cls ?>"></label>
  <label class="text-sm">Contacted by (client side)<div class="mt-1 flex gap-1">
    <select name="client_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $clientName[0] === $value ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?></select>
    <input name="client_name" required maxlength="138" value="<?= e($clientName[1]) ?>" class="<?= $cls ?> mt-0"></div></label>
  <label class="text-sm">Fixed by (company side)<div class="mt-1 flex gap-1">
    <select name="fixed_by_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $fixedByName[0] === $value ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?></select>
    <input name="fixed_by" required maxlength="138" value="<?= e($fixedByName[1]) ?>" class="<?= $cls ?> mt-0"></div></label>
  <label class="text-sm">Status<select name="status" class="<?= $cls ?>">
    <?php foreach (STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $row['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <?php if ($u['role'] === 'admin'): ?>
  <label class="text-sm flex items-center gap-2"><input id="technician-required" type="checkbox" name="technician_required" value="1" <?= !empty($row['technician_required']) ? 'checked' : '' ?>> Technician required (for non-online issues)</label>
  <label id="technician-select-wrap" class="text-sm <?= empty($row['technician_required']) ? 'hidden' : '' ?>">Assign technician<select id="technician-select" name="technician_id" class="<?= $cls ?>">
    <option value="">Select a technician</option>
    <?php foreach ($technicians as $technician): ?><option value="<?= (int)$technician['id'] ?>" <?= (int)($row['technician_id'] ?? 0) === (int)$technician['id'] ? 'selected' : '' ?>><?= e($technician['name']) ?> (<?= e($technician['phone'] ?? '') ?>)</option><?php endforeach; ?>
  </select><?php if (!$technicians): ?><span class="text-xs text-slate-500">Add a technician before assigning one.</span><?php endif; ?></label>
  <?php endif; ?>
  <label class="text-sm md:col-span-3">Description<textarea name="description" rows="2" class="<?= $cls ?>"><?= e($row['description']) ?></textarea></label>
  <label class="text-sm md:col-span-3">Note (what was done to fix it)<textarea name="note" rows="3" class="<?= $cls ?>"><?= e($row['note']) ?></textarea></label>
  <div class="md:col-span-3 flex gap-2"><button class="bg-slate-900 text-white rounded px-4 py-2"><?= e($submit) ?></button>
    <?php if (!empty($row['id'])): ?><button name="delete" value="1" formnovalidate onclick="return confirm('Delete this record?')" class="bg-red-600 text-white rounded px-4 py-2">Delete</button><?php endif; ?></div>
</form>
<?php if ($u['role'] === 'admin'): ?>
<script>
(function () {
  const required = document.getElementById('technician-required');
  const selector = document.getElementById('technician-select-wrap');
  const technician = document.getElementById('technician-select');
  required.addEventListener('change', () => {
    selector.classList.toggle('hidden', !required.checked);
    technician.required = required.checked;
  });
  technician.required = required.checked;
})();
</script>
<?php endif; ?>
