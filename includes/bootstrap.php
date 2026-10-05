<?php
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone']);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

try {
    $pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database unavailable. Start MySQL and run "php setup.php".');
}

// Upgrade databases created before companies existed.
$pdo->exec('CREATE TABLE IF NOT EXISTS companies (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
if (!$pdo->query("SHOW COLUMNS FROM breakdowns LIKE 'company_id'")->fetch()) {
    $pdo->exec('ALTER TABLE breakdowns ADD company_id INT NULL AFTER sector_id,
        ADD FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL');
}
$pdo->exec('CREATE TABLE IF NOT EXISTS technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(32) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if ($roleColumn && str_contains($roleColumn['Type'], "'technician'")) {
    $pdo->exec("UPDATE users SET role='support' WHERE role='technician'");
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','support','user') NOT NULL DEFAULT 'user'");
}
if (!$pdo->query("SHOW COLUMNS FROM breakdowns LIKE 'technician_id'")->fetch()) {
    $pdo->exec('ALTER TABLE breakdowns ADD technician_id INT NULL, ADD FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL');
}
if (!$pdo->query("SHOW COLUMNS FROM breakdowns LIKE 'technician_required'")->fetch()) {
    $pdo->exec('ALTER TABLE breakdowns ADD technician_required TINYINT(1) NOT NULL DEFAULT 0');
}

$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$appRoot = realpath(__DIR__ . '/..');
define('APP_URL', str_starts_with($appRoot, $docRoot) ? str_replace('\\', '/', substr($appRoot, strlen($docRoot))) : '');

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $p = ''): string { return APP_URL . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }

const PERSON_NAME_TITLES = [
    '' => 'No title',
    'Mr' => 'Mr.',
    'Miss' => 'Miss',
    'Mrs' => 'Mrs.',
    'Honorable' => 'Honorable',
];

function person_name_with_title(string $name, string $title): ?string {
    $name = trim($name);
    if ($name === '' || !array_key_exists($title, PERSON_NAME_TITLES)) return null;
    return $title === '' ? $name : $title . ' ' . $name;
}

function person_name_length(string $name): int {
    $length = preg_match_all('/./us', $name);
    return $length === false ? PHP_INT_MAX : $length;
}

function person_name_parts(string $name): array {
    foreach (PERSON_NAME_TITLES as $title => $_label) {
        if ($title !== '' && str_starts_with($name, $title . ' ')) {
            return [$title, substr($name, strlen($title) + 1)];
        }
    }
    return ['', $name];
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid CSRF token.');
    }
}

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function current_user(): ?array {
    global $pdo;
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $st = $pdo->prepare('SELECT id,name,email,role FROM users WHERE id=? AND active=1');
            $st->execute([$_SESSION['uid']]);
            $u = $st->fetch() ?: null;
        }
    }
    return $u;
}

function home_for(string $role): string {
    return ['admin' => 'admin/index.php', 'support' => 'support/index.php', 'user' => 'user/index.php'][$role];
}

function require_role(string ...$roles): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('Forbidden'); }
    csrf_check();
    return $u;
}

// Sectors a user may see: all for admin, assigned ones otherwise.
function accessible_sectors(array $u): array {
    global $pdo;
    if ($u['role'] === 'admin') return $pdo->query('SELECT * FROM sectors ORDER BY name')->fetchAll();
    $st = $pdo->prepare('SELECT s.* FROM sectors s JOIN sector_user su ON su.sector_id=s.id WHERE su.user_id=? ORDER BY s.name');
    $st->execute([$u['id']]);
    return $st->fetchAll();
}

function can_access_sector(array $u, int $sid): bool {
    foreach (accessible_sectors($u) as $s) if ((int)$s['id'] === $sid) return true;
    return false;
}

const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'fixed' => 'Fixed'];

function all_companies(): array {
    global $pdo;
    return $pdo->query('SELECT * FROM companies ORDER BY name')->fetchAll();
}

function active_technicians(): array {
    global $pdo;
    return $pdo->query('SELECT id,name,phone FROM technicians WHERE active=1 ORDER BY name')->fetchAll();
}

function fetch_breakdowns(array $u, array $f): array {
    global $pdo;
    $ids = array_map(fn($s) => (int)$s['id'], accessible_sectors($u));
    if (!$ids) return [];
    $where = ['b.sector_id IN (' . implode(',', $ids) . ')']; $p = [];
    if (!empty($f['sector'])) { $where[] = 'b.sector_id=?'; $p[] = (int)$f['sector']; }
    if (!empty($f['status']) && isset(STATUSES[$f['status']])) { $where[] = 'b.status=?'; $p[] = $f['status']; }
    if (!empty($f['from'])) { $where[] = 'b.occurred_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to'])) { $where[] = 'b.occurred_at <= ?'; $p[] = $f['to'] . ' 23:59:59'; }
    if (!empty($f['q'])) {
        $where[] = '(b.system_name LIKE ? OR c.name LIKE ? OR b.client_name LIKE ? OR b.fixed_by LIKE ? OR b.note LIKE ?)';
        $like = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($p, $like, $like, $like, $like, $like);
    }
    $st = $pdo->prepare('SELECT b.*, s.name AS sector_name, c.name AS company_name, t.name AS technician_name FROM breakdowns b JOIN sectors s ON s.id=b.sector_id LEFT JOIN companies c ON c.id=b.company_id LEFT JOIN technicians t ON t.id=b.technician_id WHERE '
        . implode(' AND ', $where) . ' ORDER BY b.occurred_at DESC, b.id DESC');
    $st->execute($p);
    return $st->fetchAll();
}

function filters_from_request(): array {
    $f = [];
    foreach (['sector', 'status', 'from', 'to', 'q'] as $k) $f[$k] = trim((string)($_GET[$k] ?? ''));
    foreach (['from', 'to'] as $k) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
    return $f;
}

function page_header(string $title, ?array $u = null): void {
    $f = flash();
    $nav = [];
    if ($u) {
        if ($u['role'] === 'admin') $nav = ['admin/index.php' => 'Sectors', 'admin/companies.php' => 'Company', 'admin/users.php' => 'Users', 'admin/technicians.php' => 'Technicians', 'admin/records.php' => 'Breakdowns'];
        if ($u['role'] === 'support') $nav = ['support/index.php' => 'My sectors'];
        if ($u['role'] === 'user') $nav = ['user/index.php' => 'Breakdowns'];
    }
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · Breakdown Management</title>
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-100 text-slate-800 min-h-screen">
<?php if ($u): ?>
<header class="bg-slate-900 text-white print:hidden"><div class="max-w-6xl mx-auto px-4 py-3 flex flex-wrap items-center gap-4">
  <a href="<?= url(home_for($u['role'])) ?>" class="font-bold text-lg">⚙ Breakdown Management</a>
  <button id="nav-toggle" type="button" class="ml-auto rounded p-2 hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-white md:hidden" aria-controls="primary-navigation" aria-expanded="false">
    <span class="sr-only">Toggle navigation</span>
    <svg aria-hidden="true" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
  </button>
  <div id="primary-navigation" class="hidden w-full flex-col gap-3 border-t border-slate-700 pt-3 md:flex md:w-auto md:flex-1 md:flex-row md:items-center md:border-0 md:pt-0">
    <nav class="flex flex-col gap-3 text-sm md:flex-1 md:flex-row">
      <?php foreach ($nav as $href => $label): ?><a class="hover:underline" href="<?= url($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <span class="text-sm text-slate-300"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</span>
    <a href="<?= url('logout.php') ?>" class="w-fit text-sm bg-slate-700 hover:bg-slate-600 rounded px-3 py-1">Logout</a>
  </div>
</div></header>
<script>
(function () {
  const toggle = document.getElementById('nav-toggle');
  const navigation = document.getElementById('primary-navigation');
  toggle.addEventListener('click', () => {
    const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', String(!isExpanded));
    navigation.classList.toggle('hidden', isExpanded);
  });
})();
</script>
<?php endif; ?>
<main class="max-w-6xl mx-auto px-4 py-6">
<?php if ($f): ?><div class="mb-4 rounded px-4 py-3 text-sm <?= $f[1] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>"><?= e($f[0]) ?></div><?php endif;
}
function page_footer(): void { echo '</main></body></html>'; }

function status_badge(string $s): string {
    $c = ['open' => 'bg-red-100 text-red-700', 'in_progress' => 'bg-yellow-100 text-yellow-800', 'fixed' => 'bg-green-100 text-green-700'][$s] ?? '';
    return '<span class="px-2 py-0.5 rounded text-xs font-medium ' . $c . '">' . e(STATUSES[$s] ?? $s) . '</span>';
}

// Step 1: searchable list of sectors. Step 2 (?sector=ID): that sector's breakdowns only.
function render_records(array $u, string $action): void {
    global $pdo;
    $sectors = accessible_sectors($u);
    $sid = (int)($_GET['sector'] ?? 0);
    $cls = 'border rounded px-2 py-1 text-sm';
    $sector = null;
    foreach ($sectors as $s) if ((int)$s['id'] === $sid) $sector = $s;

    if (!$sector) {
        $q = trim((string)($_GET['q'] ?? ''));
        $counts = [];
        foreach ($pdo->query('SELECT sector_id, COUNT(*) c FROM breakdowns GROUP BY sector_id') as $r) $counts[$r['sector_id']] = $r['c'];
        ?>
<div class="bg-white rounded shadow p-4 mb-4">
  <label class="text-xs">Search sector by name<input id="sector-search" value="<?= e($q) ?>" placeholder="Sector name" class="<?= $cls ?> w-full"></label>
</div>
<div class="space-y-2">
<?php foreach ($sectors as $s): ?>
  <a data-sector-name="<?= e($s['name']) ?>" href="<?= e($action . '?sector=' . (int)$s['id']) ?>" class="flex items-center justify-between gap-4 bg-white rounded shadow px-4 py-3 hover:bg-slate-50">
    <div class="min-w-0"><div class="font-semibold truncate"><?= e($s['name']) ?></div>
      <div class="text-sm text-slate-500 truncate"><?= e($s['description'] ?? '') ?></div></div>
    <div class="text-xs text-slate-500 whitespace-nowrap"><?= (int)($counts[$s['id']] ?? 0) ?> record(s) &rsaquo;</div>
  </a>
<?php endforeach; ?>
  <p id="sector-search-empty" class="hidden text-slate-500">No sectors found.</p>
</div>
<script>
  const sectorSearch = document.getElementById('sector-search');
  const sectorCards = document.querySelectorAll('[data-sector-name]');
  const sectorSearchEmpty = document.getElementById('sector-search-empty');
  const filterSectors = () => {
    const query = sectorSearch.value.trim().toLocaleLowerCase();
    let visibleCount = 0;
    sectorCards.forEach(card => {
      const matches = card.dataset.sectorName.toLocaleLowerCase().includes(query);
      card.classList.toggle('hidden', !matches);
      if (matches) visibleCount++;
    });
    sectorSearchEmpty.classList.toggle('hidden', visibleCount > 0);
  };
  sectorSearch.addEventListener('input', filterSectors);
  filterSectors();
</script>
<?php   return;
    }

    $status = (string)($_GET['status'] ?? '');
    if (!isset(STATUSES[$status])) $status = '';
    $f = ['sector' => (string)$sid, 'status' => $status, 'from' => '', 'to' => '', 'q' => ''];
    $rows = fetch_breakdowns($u, $f);
    $qs = http_build_query(array_filter($f));
    ?>
<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-semibold"><?= e($sector['name']) ?></h2>
  <a class="text-sm text-blue-600" href="<?= e($action) ?>">&larr; All sectors</a>
</div>
<?php if (in_array($u['role'], ['admin', 'support'], true)) { $formSector = $sid; $formAction = $action; include __DIR__ . '/breakdown_multi_form.php'; } ?>
<form method="get" action="<?= e($action) ?>" class="bg-white rounded shadow p-4 mb-3 flex flex-wrap items-end gap-3 print:hidden">
  <input type="hidden" name="sector" value="<?= (int)$sid ?>">
  <label class="text-sm">Status<select name="status" onchange="this.form.submit()" class="mt-1 block border rounded px-3 py-2">
    <option value="">All statuses</option>
    <?php foreach (STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select></label>
  <?php if ($status !== ''): ?><a href="<?= e($action . '?sector=' . (int)$sid) ?>" class="text-sm text-slate-600 py-2">Clear</a><?php endif; ?>
</form>
<div class="flex flex-wrap gap-2 mb-3 print:hidden">
  <a href="<?= url('report.php?' . $qs) ?>" class="bg-green-600 hover:bg-green-700 text-white rounded px-3 py-1.5 text-sm">⬇ Download report (CSV)</a>
  <a href="<?= url('report.php?' . $qs . '&format=print') ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm">🖨 Printable / PDF report</a>
  <span class="text-sm text-slate-500 self-center"><?= count($rows) ?> record(s)</span>
</div>
<?php records_cards($rows); }

function records_table(array $rows, bool $actions = false): void { ?>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr>
  <th class="p-3">Date &amp; time</th><th class="p-3">Sector</th><th class="p-3">Company</th><th class="p-3">System</th><th class="p-3">Client</th>
  <th class="p-3">Fixed by</th><th class="p-3">Status</th><th class="p-3">Technician</th><th class="p-3">Note</th><?php if ($actions): ?><th class="p-3"></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="border-t align-top">
  <td class="p-3 whitespace-nowrap"><?= e(date('Y-m-d H:i', strtotime($r['occurred_at']))) ?></td>
  <td class="p-3"><?= e($r['sector_name']) ?></td><td class="p-3"><?= e($r['company_name'] ?? '') ?></td>
  <td class="p-3"><div class="font-medium"><?= e($r['system_name']) ?></div><div class="text-slate-500"><?= nl2br(e($r['description'])) ?></div></td>
  <td class="p-3"><?= e($r['client_name']) ?></td><td class="p-3"><?= e($r['fixed_by']) ?></td>
  <td class="p-3"><?= status_badge($r['status']) ?></td>
  <td class="p-3"><?= !empty($r['technician_required']) ? e($r['technician_name'] ?? 'Not assigned') : '—' ?></td>
  <td class="p-3"><?= nl2br(e($r['note'])) ?></td>
  <?php if ($actions): ?><td class="p-3 whitespace-nowrap"><a class="text-blue-600" href="<?= url('support/edit.php?id=' . $r['id']) ?>">Edit</a></td><?php endif; ?>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= $actions ? 10 : 9 ?>" class="p-6 text-center text-slate-500">No breakdowns found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php }

// Line-card list; each card opens the breakdown detail page.
function records_cards(array $rows): void { ?>
<div class="space-y-2">
<?php foreach ($rows as $r): ?>
  <a href="<?= url('breakdown.php?id=' . (int)$r['id']) ?>" class="flex flex-wrap items-center justify-between gap-3 bg-white rounded shadow px-4 py-3 hover:bg-slate-50">
    <div class="min-w-0">
      <div class="font-semibold truncate"><?= e($r['system_name']) ?></div>
      <div class="text-sm text-slate-500 truncate"><?= e($r['sector_name']) ?> &middot; <?= e($r['company_name'] ?? 'No company') ?> &middot; <?= e(date('Y-m-d H:i', strtotime($r['occurred_at']))) ?><?php if (!empty($r['technician_required'])): ?> &middot; Technician: <?= e($r['technician_name'] ?? 'Not assigned') ?><?php endif; ?></div>
    </div>
    <div class="text-sm text-slate-500">Client: <?= e($r['client_name']) ?> &middot; Fixed by: <?= e($r['fixed_by']) ?></div>
    <div><?= status_badge($r['status']) ?> <span class="text-slate-400">&rsaquo;</span></div>
  </a>
<?php endforeach; if (!$rows): ?><p class="text-slate-500">No breakdowns found.</p><?php endif; ?>
</div>
<?php }
