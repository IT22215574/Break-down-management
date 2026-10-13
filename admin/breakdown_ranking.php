<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');
require __DIR__ . '/../includes/dashboard_data.php';
$order = ($_GET['order'] ?? '') === 'desc' ? 'desc' : 'asc';
$ranking = dashboard_sector_ranking($pdo, $order);
page_header('Breakdown ranking', $u);
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div>
    <h1 class="text-2xl font-bold">Breakdowns by sector</h1>
    <p class="text-sm text-slate-500 mt-1">Every sector, including sectors with no breakdowns.</p>
  </div>
  <a href="<?= e(url('admin/dashboard.php')) ?>" class="text-sm text-blue-700 hover:underline">&larr; Dashboard</a>
</div>
<form method="get" class="bg-white rounded shadow p-4 mb-4 flex flex-wrap items-end gap-3">
  <label class="text-sm">Sort by breakdown count
    <select name="order" class="block mt-1 border rounded px-3 py-2">
      <option value="asc" <?= $order === 'asc' ? 'selected' : '' ?>>Least to most breakdowns</option>
      <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>Most to least breakdowns</option>
    </select>
  </label>
  <button class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm">Apply</button>
  <span class="text-sm text-slate-500 py-2"><?= number_format(count($ranking)) ?> sector(s)</span>
</form>
<div class="bg-white rounded shadow overflow-hidden">
  <?php dashboard_ranking_table($ranking); ?>
</div>
<?php page_footer();
