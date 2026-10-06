<?php
function company_exists(int $id): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT 1 FROM companies WHERE id=?'); $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function active_technician_exists(int $id): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT 1 FROM technicians WHERE id=? AND active=1');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

function breakdown_technician_ids(int $breakdownId): array {
    global $pdo;
    $st = $pdo->prepare('SELECT technician_id FROM breakdown_technicians WHERE breakdown_id=?');
    $st->execute([$breakdownId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// Replaces the technicians assigned to a breakdown.
function save_breakdown_technicians(int $breakdownId, array $ids): void {
    global $pdo;
    $pdo->prepare('DELETE FROM breakdown_technicians WHERE breakdown_id=?')->execute([$breakdownId]);
    $ins = $pdo->prepare('INSERT INTO breakdown_technicians (breakdown_id, technician_id) VALUES (?,?)');
    foreach ($ids as $tid) $ins->execute([$breakdownId, $tid]);
}

// Unique positive ids from a submitted list.
function read_technician_ids($raw): array {
    return array_values(array_unique(array_filter(array_map('intval', (array)$raw), fn($i) => $i > 0)));
}

function read_contact_phone($raw): ?string {
    $v = trim((string)$raw);
    return $v === '' ? null : $v;
}

function valid_contact_phone(?string $v): bool {
    return $v === null || preg_match('/^[0-9]{10}$/', $v) === 1;
}

// Validates POST data; returns [data|null, error|null].
function read_breakdown_post(array $u): array {
    $d = [];
    foreach (['system_name', 'client_name', 'fixed_by', 'description', 'note'] as $k) $d[$k] = trim((string)($_POST[$k] ?? ''));
    foreach (['client_name' => 'client_title', 'fixed_by' => 'fixed_by_title'] as $name => $title) {
        $d[$name] = person_name_with_title($d[$name], (string)($_POST[$title] ?? ''));
        if ($d[$name] === null || person_name_length($d[$name]) > 150) return [null, 'Enter valid contacted-by and fixed-by names.'];
    }
    $d['sector_id'] = (int)($_POST['sector_id'] ?? 0);
    $d['company_id'] = (int)($_POST['company_id'] ?? 0) ?: null;
    $d['status'] = $_POST['status'] ?? '';
    if ($u['role'] === 'admin') {
        $d['technician_required'] = !empty($_POST['technician_required']) ? 1 : 0;
        $d['technician_ids'] = $d['technician_required'] ? read_technician_ids($_POST['technician_ids'] ?? []) : [];
        if ($d['technician_required'] && !$d['technician_ids']) return [null, 'Select at least one active technician when a technician is required.'];
        foreach ($d['technician_ids'] as $tid) if (!active_technician_exists($tid)) return [null, 'Select active technicians only.'];
    }
    $d['contact_phone'] = read_contact_phone($_POST['contact_phone'] ?? '');
    if (!valid_contact_phone($d['contact_phone'])) return [null, 'Enter a valid 10-digit contact mobile number.'];
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
        foreach (['system_name', 'client_name', 'fixed_by', 'description', 'note'] as $k) $r[$k] = trim((string)($e[$k] ?? ''));
        $r['status'] = $e['status'] ?? 'fixed';
        $r['company_id'] = (int)($e['company_id'] ?? 0) ?: null;
        if (!$r['company_id'] && $r['system_name'] === '' && $r['client_name'] === '' && $r['fixed_by'] === '' && $r['description'] === '' && $r['note'] === '') continue;
        foreach (['client_name' => 'client_title', 'fixed_by' => 'fixed_by_title'] as $name => $title) {
            $r[$name] = person_name_with_title($r[$name], (string)($e[$title] ?? ''));
            if ($r[$name] === null || person_name_length($r[$name]) > 150) {
                flash('Form #' . ((int)$i + 1) . ': enter valid contacted-by and fixed-by names.', 'error'); redirect($back);
            }
        }
        if ($u['role'] === 'admin') {
            $r['technician_required'] = !empty($e['technician_required']) ? 1 : 0;
            $r['technician_ids'] = $r['technician_required'] ? read_technician_ids($e['technician_ids'] ?? []) : [];
            $valid = (bool)$r['technician_ids'] || !$r['technician_required'];
            foreach ($r['technician_ids'] as $tid) if (!active_technician_exists($tid)) $valid = false;
            if (!$valid) {
                flash('Form #' . ((int)$i + 1) . ': select at least one active technician when one is required.', 'error'); redirect($back);
            }
        } else {
            $r['technician_required'] = 0;
            $r['technician_ids'] = [];
        }
        $r['contact_phone'] = read_contact_phone($e['contact_phone'] ?? '');
        if (!valid_contact_phone($r['contact_phone'])) {
            flash('Form #' . ((int)$i + 1) . ': enter a valid 10-digit contact mobile number.', 'error'); redirect($back);
        }
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
    $ins = $pdo->prepare('INSERT INTO breakdowns (sector_id,company_id,system_name,description,occurred_at,fixed_by,client_name,status,note,created_by,technician_id,technician_required,contact_phone)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $ins->execute([$sid, $r['company_id'], $r['system_name'], $r['description'], $r['occurred_at'], $r['fixed_by'], $r['client_name'], $r['status'], $r['note'], $u['id'], $r['technician_ids'][0] ?? null, $r['technician_required'], $r['contact_phone']]);
        save_breakdown_technicians((int)$pdo->lastInsertId(), $r['technician_ids']);
    }
    $pdo->commit();
    flash(count($rows) . ' breakdown record(s) saved.');
    redirect($back);
}
