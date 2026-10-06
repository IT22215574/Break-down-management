<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'support');

$st = $pdo->prepare('SELECT * FROM quotations WHERE id=?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$q = $st->fetch();
if (!$q || !can_access_sector($u, (int)$q['sector_id'])) {
    http_response_code(404);
    exit('Not found');
}
$machines = json_decode($q['machines_json'], true) ?: [];
$accessories = json_decode($q['accessories_json'], true) ?: [];

page_header('Quotation', $u);
?>
<div class="max-w-3xl mx-auto bg-white rounded shadow p-6 print:shadow-none print:p-0">
  <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <h1 class="text-2xl font-bold">Job ID #<?= (int)$q['id'] ?></h1>
    <div class="flex gap-2 print:hidden">
      <a href="<?= url(($u['role'] === 'admin' ? 'admin/records.php' : 'support/records.php') . '?sector=' . (int)$q['sector_id']) ?>" class="text-sm text-blue-600 self-center">&larr; Back to breakdowns</a>
      <button type="button" onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm">Download / Save as PDF</button>
    </div>
  </div>
  <div class="grid gap-2 text-sm mb-4">
    <div>Date: <?= e($q['quote_date']) ?></div>
    <div>Contacted by: <?= e($q['contact_name']) ?></div>
    <div>Phone number: <?= e($q['contact_phone']) ?></div>
  </div>

  <?php if ($machines): ?>
  <h2 class="font-semibold mb-2">Machines</h2>
  <table class="w-full text-sm border-collapse mb-4">
    <thead><tr class="border-b border-t"><th class="text-left py-1 pr-2">Category</th><th class="text-left py-1 pr-2">Brand</th><th class="text-left py-1 pr-2">Model</th><th class="text-left py-1">Model code</th></tr></thead>
    <tbody>
    <?php foreach ($machines as $m): ?>
      <tr><td class="py-1 pr-2"><?= e($m['category']) ?></td><td class="py-1 pr-2"><?= e($m['brand']) ?></td><td class="py-1 pr-2"><?= e($m['model']) ?></td><td class="py-1"><?= e($m['model_code']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($accessories): ?>
  <h2 class="font-semibold mb-2">Accessories</h2>
  <table class="w-full text-sm border-collapse">
    <thead><tr class="border-b border-t">
      <th class="text-left py-1 pr-2">Name</th><th class="text-left py-1 pr-2">Brand</th><th class="text-right py-1 pr-2">Price</th><th class="text-right py-1 pr-2">Qty</th><th class="text-right py-1 pr-2">Discounted price</th><th class="text-right py-1">Line total</th>
    </tr></thead>
    <tbody>
    <?php foreach ($accessories as $a): ?>
      <tr>
        <td class="py-1 pr-2"><?= e($a['name']) ?></td><td class="py-1 pr-2"><?= e($a['brand']) ?></td>
        <td class="py-1 pr-2 text-right"><?= number_format((float)$a['price'], 2) ?></td>
        <td class="py-1 pr-2 text-right"><?= (int)$a['quantity'] ?></td>
        <td class="py-1 pr-2 text-right"><?= $a['discount_price'] === null ? '' : number_format((float)$a['discount_price'], 2) ?></td>
        <td class="py-1 text-right"><?= number_format((float)$a['line_total'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="flex justify-end mt-3 font-bold">Total: <span class="ml-2"><?= number_format((float)$q['total'], 2) ?></span></div>
  <?php endif; ?>

  <div class="mt-4 text-sm"><div class="font-bold">Breakdown</div><div class="whitespace-pre-line"><?= e($q['breakdown']) ?></div></div>
  <div class="mt-4 text-sm"><div class="font-bold">Remark</div><div class="whitespace-pre-line"><?= e($q['remark']) ?></div></div>
</div>
<style>@media print { nav, footer { display: none !important; } body { background: white !important; } }</style>
<?php page_footer(); ?>
