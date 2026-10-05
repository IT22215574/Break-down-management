<?php
// Expects $row (array), $sectors, $heading, $submit.
$cls = 'mt-1 w-full border rounded px-3 py-2';
?>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-3 gap-3"><?= csrf_field() ?>
  <h2 class="md:col-span-3 font-semibold"><?= e($heading) ?></h2>
  <label class="text-sm">Sector<select name="sector_id" class="<?= $cls ?>">
    <?php foreach ($sectors as $s): ?><option value="<?= $s['id'] ?>" <?= $row['sector_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
  <label class="text-sm">System broken down<input name="system_name" required maxlength="190" value="<?= e($row['system_name']) ?>" class="<?= $cls ?>"></label>
  <label class="text-sm">Date &amp; time<input type="datetime-local" name="occurred_at" required value="<?= e(date('Y-m-d\TH:i', strtotime($row['occurred_at']))) ?>" class="<?= $cls ?>"></label>
  <label class="text-sm">Client who contacted the office<input name="client_name" required maxlength="150" value="<?= e($row['client_name']) ?>" class="<?= $cls ?>"></label>
  <label class="text-sm">Fixed by<input name="fixed_by" required maxlength="150" value="<?= e($row['fixed_by']) ?>" class="<?= $cls ?>"></label>
  <label class="text-sm">Status<select name="status" class="<?= $cls ?>">
    <?php foreach (STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $row['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label class="text-sm md:col-span-3">Description<textarea name="description" rows="2" class="<?= $cls ?>"><?= e($row['description']) ?></textarea></label>
  <label class="text-sm md:col-span-3">Note<textarea name="note" rows="3" class="<?= $cls ?>"><?= e($row['note']) ?></textarea></label>
  <div class="md:col-span-3 flex gap-2"><button class="bg-slate-900 text-white rounded px-4 py-2"><?= e($submit) ?></button>
    <?php if (!empty($row['id'])): ?><button name="delete" value="1" formnovalidate onclick="return confirm('Delete this record?')" class="bg-red-600 text-white rounded px-4 py-2">Delete</button><?php endif; ?></div>
</form>
