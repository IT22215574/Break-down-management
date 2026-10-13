<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');
require __DIR__ . '/../includes/dashboard_data.php';
$totals = dashboard_totals($pdo);
$ranking = dashboard_sector_ranking($pdo, 'desc', 5);
$summary = [
    ['Users', 'users', 'admin/users.php'],
    ['Companies', 'companies', 'admin/companies.php'],
    ['Technicians', 'technicians', 'admin/technicians.php'],
    ['Accessories', 'accessories', 'admin/accessories.php'],
    ['Sectors', 'sectors', 'admin/index.php'],
    ['Machines', 'machines', 'admin/machines.php'],
    ['Quotations', 'quotations', 'admin/records.php'],
];
page_header('Dashboard', $u);
?>
<h1 class="text-2xl font-bold mb-1">Dashboard</h1>
<p class="text-sm text-slate-500 mb-6">An overview of all records and breakdown activity.</p>

<section aria-labelledby="summary-title" class="bg-white rounded shadow p-4 mb-6">
  <h2 id="summary-title" class="text-lg font-semibold mb-4">System summary</h2>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <?php foreach ($summary as [$label, $key, $path]): ?>
    <a href="<?= e(url($path)) ?>" class="block rounded border border-slate-200 p-4 hover:bg-blue-50 hover:border-blue-200">
      <div class="text-sm text-slate-500"><?= e($label) ?></div>
      <div class="text-3xl font-bold text-slate-800 mt-2"><?= number_format((int)$totals[$key]) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</section>

<section aria-labelledby="breakdowns-title" class="bg-white rounded shadow p-4 mb-6">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 id="breakdowns-title" class="text-lg font-semibold">Breakdown summary</h2>
    <a href="<?= e(url('admin/records.php')) ?>" class="text-sm text-blue-700 hover:underline">View breakdowns &rarr;</a>
  </div>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <?php foreach ([['Total breakdowns', 'breakdowns', 'bg-blue-50 text-blue-800'], ['Opened', 'opened', 'bg-red-50 text-red-700'], ['Ongoing', 'ongoing', 'bg-amber-50 text-amber-800'], ['Completed', 'completed', 'bg-green-50 text-green-700']] as [$label, $key, $color]): ?>
    <div class="rounded p-4 <?= $color ?>">
      <div class="text-sm font-medium"><?= e($label) ?></div>
      <div class="text-3xl font-bold mt-2"><?= number_format((int)$totals[$key]) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section aria-labelledby="ranking-title" class="bg-white rounded shadow overflow-hidden">
  <div class="p-4 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 id="ranking-title" class="text-lg font-semibold">Top 5 sectors with the most breakdowns</h2>
      <p class="text-sm text-slate-500 mt-1">All-time totals, ordered from most to least breakdowns.</p>
    </div>
    <a href="<?= e(url('admin/breakdown_ranking.php')) ?>" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm">View all: least to most</a>
  </div>
  <?php dashboard_ranking_table($ranking); ?>
</section>
<?php page_footer();
