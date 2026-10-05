<?php
function company_exists(int $id): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT 1 FROM companies WHERE id=?'); $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

// Validates POST data; returns [data|null, error|null].
function read_breakdown_post(array $u): array {
    $d = [];
    foreach (['system_name', 'client_name', 'fixed_by', 'description', 'note'] as $k) $d[$k] = trim((string)($_POST[$k] ?? ''));
    $d['sector_id'] = (int)($_POST['sector_id'] ?? 0);
    $d['company_id'] = (int)($_POST['company_id'] ?? 0) ?: null;
    $d['status'] = $_POST['status'] ?? '';
    $t = DateTime::createFromFormat('Y-m-d\TH:i', (string)($_POST['occurred_at'] ?? ''));
    if (!$t) return [null, 'Enter a valid date and time.'];
    $d['occurred_at'] = $t->format('Y-m-d H:i:s');
    if ($d['system_name'] === '' || $d['client_name'] === '' || $d['fixed_by'] === '') return [null, 'System, client and fixed-by are required.'];
    if (!isset(STATUSES[$d['status']])) return [null, 'Invalid status.'];
    if ($d['company_id'] !== null && !company_exists($d['company_id'])) return [null, 'Select a valid company.'];
    if (!can_access_sector($u, $d['sector_id'])) return [null, 'You cannot record in that sector.'];
    return [$d, null];
}

// Handles the multi-entry "add breakdowns" POST for one sector; redirects back to $back.
function handle_breakdown_create(array $u, string $back): void {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'create_many') return;
    $sid = (int)($_POST['sector_id'] ?? 0);
    if (!can_access_sector($u, $sid)) { flash('You cannot record in that sector.', 'error'); redirect($back); }
    $hasCompanies = (bool)all_companies();
    $rows = [];
    foreach ((array)($_POST['entries'] ?? []) as $i => $e) {
        $e = (array)$e;
        $r = [];
        foreach (['system_name', 'client_name', 'fixed_by', 'note'] as $k) $r[$k] = trim((string)($e[$k] ?? ''));
        $r['status'] = $e['status'] ?? 'fixed';
        $r['company_id'] = (int)($e['company_id'] ?? 0) ?: null;
        if (!$r['company_id'] && $r['system_name'] === '' && $r['client_name'] === '' && $r['fixed_by'] === '' && $r['note'] === '') continue;
        $t = DateTime::createFromFormat('Y-m-d\TH:i', (string)($e['occurred_at'] ?? ''));
        if (!$r['company_id'] && $hasCompanies) $t = false;
        if (!$t || $r['system_name'] === '' || $r['client_name'] === '' || $r['fixed_by'] === '' || !isset(STATUSES[$r['status']])
            || ($r['company_id'] !== null && !company_exists($r['company_id']))) {
            flash('Form #' . ((int)$i + 1) . ': company, system, date, contacted by and fixed by are required.', 'error'); redirect($back);
        }
        $r['occurred_at'] = $t->format('Y-m-d H:i:s');
        $rows[] = $r;
    }
    if (!$rows) { flash('Fill in at least one form.', 'error'); redirect($back); }
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO breakdowns (sector_id,company_id,system_name,description,occurred_at,fixed_by,client_name,status,note,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) $ins->execute([$sid, $r['company_id'], $r['system_name'], '', $r['occurred_at'], $r['fixed_by'], $r['client_name'], $r['status'], $r['note'], $u['id']]);
    $pdo->commit();
    flash(count($rows) . ' breakdown record(s) saved.');
    redirect($back);
}
