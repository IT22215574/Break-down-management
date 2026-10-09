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

$revisions = quotation_revisions($q);
$latest = $revisions[0];
$back = url(($u['role'] === 'admin' ? 'admin/records.php' : 'support/records.php') . '?sector=' . (int)$q['sector_id'] . '&tab=quotations');
$jobLabel = $q['job_no'] ?: '#' . (int)$q['id'];

function quote_revision_meta(array $r, bool $isFirst): string {
    $when = e(date('Y-m-d H:i', strtotime($r['created_at'])));
    $who = e($r['editor_name'] ?? 'Unknown user');
    return ($isFirst ? 'Created' : 'Edited') . " $when by $who";
}

function render_quote_body(array $r, bool $signatures): void {
    $machines = json_decode($r['machines_json'], true) ?: [];
    $accessories = json_decode($r['accessories_json'], true) ?: [];
    $returned = json_decode((string)($r['returned_json'] ?? ''), true) ?: [];
    ?>
  <div class="grid gap-2 text-sm mb-4">
    <div>Date: <?= e($r['quote_date']) ?></div>
    <div>Contacted by: <?= e($r['contact_name']) ?></div>
    <div>Phone number: <?= e($r['contact_phone']) ?></div>
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
  <div class="flex justify-end mt-3 font-bold">Total: <span class="ml-2"><?= number_format((float)$r['total'], 2) ?></span></div>
  <?php endif; ?>
  <?php if ($returned): ?>
  <h2 class="font-semibold mt-4 mb-2">Items received from customer</h2>
  <table class="w-full text-sm border-collapse">
    <thead><tr class="border-b border-t"><th class="text-left py-1 pr-2">Name</th><th class="text-left py-1 pr-2">Brand</th><th class="text-right py-1 pr-2">Qty</th><th class="text-left py-1">Note</th></tr></thead>
    <tbody>
    <?php foreach ($returned as $ret): ?>
      <tr><td class="py-1 pr-2"><?= e($ret['name']) ?></td><td class="py-1 pr-2"><?= e($ret['brand']) ?></td><td class="py-1 pr-2 text-right"><?= (int)$ret['quantity'] ?></td><td class="py-1"><?= e($ret['note']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="mt-4 text-sm"><div class="font-bold">Breakdown</div><div class="whitespace-pre-line"><?= e($r['breakdown']) ?></div></div>
  <div class="mt-4 text-sm"><div class="font-bold">Remark</div><div class="whitespace-pre-line"><?= e($r['remark']) ?></div></div>
  <?php if ($signatures): ?>
  <section class="quote-signatures mt-10 grid grid-cols-2 gap-8 text-sm">
    <div>
      <div class="signature-line"></div>
      <div>Customer Signature</div>
      <p class="mt-4"><strong>Please Note:</strong> Clear the goods within 3 months.</p>
    </div>
    <div>
      <div class="signature-line"></div>
      <div>Authorized Signature</div>
    </div>
  </section>
  <?php endif;
}

page_header('Quotation', $u);
?>
<div class="max-w-3xl mx-auto bg-white rounded shadow p-6 print:shadow-none print:p-0">
  <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div>
      <h1 class="text-2xl font-bold">Job ID <?= e($jobLabel) ?></h1>
      <div class="text-xs text-slate-500 mt-1 print:hidden"><?= count($revisions) > 1 ? 'Latest version &middot; ' : '' ?><?= quote_revision_meta($latest, count($revisions) === 1) ?></div>
    </div>
    <div class="flex flex-wrap gap-2 print:hidden">
      <a href="<?= $back ?>" class="text-sm text-blue-600 self-center">&larr; Back to quotations</a>
      <a href="<?= url('quotation.php?quotation_id=' . (int)$q['id']) ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white rounded px-4 py-2 text-sm">Edit</a>
      <button type="button" onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 text-sm">Download / Save as PDF</button>
    </div>
  </div>
  <?php render_quote_body($latest, true); ?>
</div>
<?php if (count($revisions) > 1): ?>
<div class="max-w-3xl mx-auto mt-6 print:hidden">
  <h2 class="text-lg font-semibold mb-3">Previous versions</h2>
  <div class="space-y-3">
  <?php foreach (array_slice($revisions, 1) as $r): ?>
    <details class="bg-white rounded shadow p-4">
      <summary class="cursor-pointer text-sm font-medium">Version <?= (int)$r['revision_no'] ?> &middot; <?= quote_revision_meta($r, (int)$r['revision_no'] === 1) ?> &middot; Total <?= number_format((float)$r['total'], 2) ?></summary>
      <div class="mt-4"><?php render_quote_body($r, false); ?></div>
    </details>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<style>
.signature-line { width: 50%; height: 4rem; border-bottom: 1px dotted #334155; margin-bottom: 0.5rem; }
@media print {
  nav, footer { display: none !important; }
  body { background: white !important; }
  .quote-signatures { break-before: page; page-break-before: always; break-inside: avoid; page-break-inside: avoid; }
}
</style>
<?php page_footer(); ?>
