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

$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
$appRoot = realpath(__DIR__ . '/..');
define('APP_URL', str_starts_with($appRoot, $docRoot) ? str_replace('\\', '/', substr($appRoot, strlen($docRoot))) : '');

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $p = ''): string { return APP_URL . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }

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
        $where[] = '(b.system_name LIKE ? OR b.client_name LIKE ? OR b.fixed_by LIKE ? OR b.note LIKE ?)';
        $like = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($p, $like, $like, $like, $like);
    }
    $st = $pdo->prepare('SELECT b.*, s.name AS sector_name FROM breakdowns b JOIN sectors s ON s.id=b.sector_id WHERE '
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
        if ($u['role'] === 'admin') $nav = ['admin/index.php' => 'Sectors', 'admin/users.php' => 'Users', 'admin/records.php' => 'Breakdowns'];
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
  <nav class="flex gap-3 text-sm flex-1">
    <?php foreach ($nav as $href => $label): ?><a class="hover:underline" href="<?= url($href) ?>"><?= e($label) ?></a><?php endforeach; ?>
  </nav>
  <span class="text-sm text-slate-300"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</span>
  <a href="<?= url('logout.php') ?>" class="text-sm bg-slate-700 hover:bg-slate-600 rounded px-3 py-1">Logout</a>
</div></header>
<?php endif; ?>
<main class="max-w-6xl mx-auto px-4 py-6">
<?php if ($f): ?><div class="mb-4 rounded px-4 py-3 text-sm <?= $f[1] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>"><?= e($f[0]) ?></div><?php endif;
}
function page_footer(): void { echo '</main></body></html>'; }

function status_badge(string $s): string {
    $c = ['open' => 'bg-red-100 text-red-700', 'in_progress' => 'bg-yellow-100 text-yellow-800', 'fixed' => 'bg-green-100 text-green-700'][$s] ?? '';
    return '<span class="px-2 py-0.5 rounded text-xs font-medium ' . $c . '">' . e(STATUSES[$s] ?? $s) . '</span>';
}

// Shared read-only filter bar + table (used by admin, support and user views).
function render_records(array $u, string $action): void {
    $f = filters_from_request();
    $rows = fetch_breakdowns($u, $f);
    $sectors = accessible_sectors($u);
    $qs = http_build_query(array_filter($f));
    $cls = 'border rounded px-2 py-1 text-sm';
    ?>
<form method="get" action="<?= e($action) ?>" class="bg-white rounded shadow p-4 mb-4 grid gap-3 md:grid-cols-6 items-end print:hidden">
  <label class="text-xs">Sector<select name="sector" class="<?= $cls ?> w-full"><option value="">All</option>
    <?php foreach ($sectors as $s): ?><option value="<?= $s['id'] ?>" <?= $f['sector'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
  <label class="text-xs">Status<select name="status" class="<?= $cls ?> w-full"><option value="">All</option>
    <?php foreach (STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <label class="text-xs">From<input type="date" name="from" value="<?= e($f['from']) ?>" class="<?= $cls ?> w-full"></label>
  <label class="text-xs">To<input type="date" name="to" value="<?= e($f['to']) ?>" class="<?= $cls ?> w-full"></label>
  <label class="text-xs">Search<input name="q" value="<?= e($f['q']) ?>" class="<?= $cls ?> w-full"></label>
  <button class="bg-slate-800 text-white rounded px-3 py-1.5 text-sm">Filter</button>
</form>
<div class="flex flex-wrap gap-2 mb-3 print:hidden">
  <a href="<?= url('report.php?' . $qs) ?>" class="bg-green-600 hover:bg-green-700 text-white rounded px-3 py-1.5 text-sm">⬇ Download report (CSV)</a>
  <a href="<?= url('report.php?' . $qs . ($qs ? '&' : '') . 'format=print') ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm">🖨 Printable / PDF report</a>
  <span class="text-sm text-slate-500 self-center"><?= count($rows) ?> record(s)</span>
</div>
<?php records_table($rows); }

function records_table(array $rows, bool $actions = false): void { ?>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr>
  <th class="p-3">Date &amp; time</th><th class="p-3">Sector</th><th class="p-3">System</th><th class="p-3">Client</th>
  <th class="p-3">Fixed by</th><th class="p-3">Status</th><th class="p-3">Note</th><?php if ($actions): ?><th class="p-3"></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="border-t align-top">
  <td class="p-3 whitespace-nowrap"><?= e(date('Y-m-d H:i', strtotime($r['occurred_at']))) ?></td>
  <td class="p-3"><?= e($r['sector_name']) ?></td>
  <td class="p-3"><div class="font-medium"><?= e($r['system_name']) ?></div><div class="text-slate-500"><?= nl2br(e($r['description'])) ?></div></td>
  <td class="p-3"><?= e($r['client_name']) ?></td><td class="p-3"><?= e($r['fixed_by']) ?></td>
  <td class="p-3"><?= status_badge($r['status']) ?></td><td class="p-3"><?= nl2br(e($r['note'])) ?></td>
  <?php if ($actions): ?><td class="p-3 whitespace-nowrap"><a class="text-blue-600" href="<?= url('support/edit.php?id=' . $r['id']) ?>">Edit</a></td><?php endif; ?>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="8" class="p-6 text-center text-slate-500">No breakdowns found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php }
