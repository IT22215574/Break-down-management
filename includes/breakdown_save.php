<?php
// Validates POST data; returns [data|null, error|null].
function read_breakdown_post(array $u): array {
    $d = [];
    foreach (['system_name', 'client_name', 'fixed_by', 'description', 'note'] as $k) $d[$k] = trim((string)($_POST[$k] ?? ''));
    $d['sector_id'] = (int)($_POST['sector_id'] ?? 0);
    $d['status'] = $_POST['status'] ?? '';
    $t = DateTime::createFromFormat('Y-m-d\TH:i', (string)($_POST['occurred_at'] ?? ''));
    if (!$t) return [null, 'Enter a valid date and time.'];
    $d['occurred_at'] = $t->format('Y-m-d H:i:s');
    if ($d['system_name'] === '' || $d['client_name'] === '' || $d['fixed_by'] === '') return [null, 'System, client and fixed-by are required.'];
    if (!isset(STATUSES[$d['status']])) return [null, 'Invalid status.'];
    if (!can_access_sector($u, $d['sector_id'])) return [null, 'You cannot record in that sector.'];
    return [$d, null];
}
