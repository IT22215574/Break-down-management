<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('support');
$sectors = accessible_sectors($u);
page_header('My sectors', $u);
?>
<h1 class="text-2xl font-bold mb-4">My sectors</h1>
<div class="grid md:grid-cols-3 gap-4">
<?php foreach ($sectors as $s): ?>
  <a href="<?= url('support/records.php?sector=' . $s['id']) ?>" class="bg-white rounded shadow p-4 hover:ring-2 ring-slate-400">
    <div class="font-semibold"><?= e($s['name']) ?></div><div class="text-sm text-slate-500"><?= e($s['description']) ?></div>
  </a>
<?php endforeach; if (!$sectors): ?><p class="text-slate-500">You have not been assigned to any sector. Ask an admin.</p><?php endif; ?>
</div>
<?php page_footer();
