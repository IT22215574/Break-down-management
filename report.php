<?php
require __DIR__ . '/includes/bootstrap.php';
$u = current_user();
if (!$u) redirect('login.php');
$f = filters_from_request();
$rows = fetch_breakdowns($u, $f);
$head = ['ID', 'Date & time', 'Sector', 'Company', 'System', 'Description', 'Client', 'Fixed by', 'Status', 'Technician', 'Note'];

if (($_GET['format'] ?? '') === 'print') {
    page_header('Breakdown report');
    ?>
    <div class="flex justify-between items-center mb-4"><div><h1 class="text-2xl font-bold">Breakdown Report</h1>
      <p class="text-sm text-slate-500">Generated <?= e(date('Y-m-d H:i')) ?> · <?= count($rows) ?> record(s)</p></div>
      <button onclick="print()" class="bg-blue-600 text-white rounded px-3 py-1.5 text-sm print:hidden">Print / Save as PDF</button></div>
    <?php records_table($rows); page_footer(); exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="breakdown-report-' . date('Ymd-His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
// Prefix formula-triggering characters so spreadsheets don't execute cell content.
$safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;
fputcsv($out, $head, ',', '"', '');
foreach ($rows as $r) {
    fputcsv($out, array_map($safe, [$r['id'], $r['occurred_at'], $r['sector_name'], $r['company_name'], $r['system_name'], $r['description'],
        $r['client_name'], $r['fixed_by'], STATUSES[$r['status']], !empty($r['technician_required']) ? ($r['technician_name'] ?? 'Not assigned') : '', $r['note']]), ',', '"', '');
}
