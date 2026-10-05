<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'support');

$breakdownId = (int)($_GET['breakdown_id'] ?? 0);
$sectorId = (int)($_GET['sector_id'] ?? 0);
$quote = [
    'quote_date' => date('Y-m-d'),
    'sector_name' => '',
    'sector_address' => '',
    'contact_name' => trim((string)($_GET['contact_name'] ?? '')),
    'contact_phone' => '',
    'machine_model' => trim((string)($_GET['machine_model'] ?? '')),
    'breakdown' => trim((string)($_GET['breakdown'] ?? '')),
    'remark' => trim((string)($_GET['remark'] ?? '')),
];

if ($breakdownId) {
    $st = $pdo->prepare('SELECT b.*, s.name AS sector_name, s.address AS sector_address
        FROM breakdowns b JOIN sectors s ON s.id=b.sector_id WHERE b.id=?');
    $st->execute([$breakdownId]);
    $row = $st->fetch();
    if (!$row || !can_access_sector($u, (int)$row['sector_id']) || empty($row['technician_required'])) {
        http_response_code(404);
        exit('Not found');
    }
    $quote['sector_name'] = $row['sector_name'];
    $quote['sector_address'] = $row['sector_address'] ?? '';
    $quote['contact_name'] = $row['client_name'];
    $quote['machine_model'] = $row['system_name'];
    $quote['breakdown'] = $row['description'] ?? '';
    $quote['remark'] = $row['note'] ?? '';
} else {
    foreach (accessible_sectors($u) as $sector) {
        if ((int)$sector['id'] === $sectorId) {
            $quote['sector_name'] = $sector['name'];
            $quote['sector_address'] = $sector['address'] ?? '';
            break;
        }
    }
    if ($quote['sector_name'] === '') {
        http_response_code(404);
        exit('Not found');
    }
}

page_header('Make Quotations', $u);
?>
<div class="max-w-3xl mx-auto bg-white rounded shadow p-6">
  <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div><h1 class="text-2xl font-bold">Quotation</h1><p class="text-sm text-slate-500">Complete any missing details, then print or save as PDF.</p></div>
    <?php if ($breakdownId): ?><a class="text-sm text-blue-600 print:hidden" href="<?= url('breakdown.php?id=' . $breakdownId) ?>">&larr; Back to breakdown</a><?php endif; ?>
  </div>
  <div class="grid md:grid-cols-2 gap-4">
    <label class="text-sm">Date<input type="date" value="<?= e($quote['quote_date']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm">Sector name<input value="<?= e($quote['sector_name']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm md:col-span-2">Sector address<textarea rows="2" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"><?= e($quote['sector_address']) ?></textarea></label>
    <label class="text-sm">Contacted by (client side)<input value="<?= e($quote['contact_name']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm">Phone number<input type="tel" value="<?= e($quote['contact_phone']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm md:col-span-2">Machine model<input value="<?= e($quote['machine_model']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm md:col-span-2">Breakdown<textarea rows="4" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"><?= e($quote['breakdown']) ?></textarea></label>
    <label class="text-sm md:col-span-2">Remark<textarea rows="3" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"><?= e($quote['remark']) ?></textarea></label>
  </div>
  <div class="mt-6 print:hidden"><button type="button" onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Print / Save as PDF</button></div>
</div>
<style>
@media print {
  nav, footer { display: none !important; }
  body { background: white !important; }
  .shadow { box-shadow: none !important; }
}
</style>
<?php page_footer(); ?>
