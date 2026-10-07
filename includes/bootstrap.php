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
$pdo->exec('CREATE TABLE IF NOT EXISTS sector_phones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sector_id INT NOT NULL,
    phone VARCHAR(32) NOT NULL,
    FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE CASCADE
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
if (!$pdo->query("SHOW COLUMNS FROM breakdowns LIKE 'contact_phone'")->fetch()) {
    $pdo->exec('ALTER TABLE breakdowns ADD contact_phone VARCHAR(32) NULL');
}
if (!$pdo->query("SHOW TABLES LIKE 'breakdown_technicians'")->fetch()) {
    $pdo->exec('CREATE TABLE breakdown_technicians (
        breakdown_id INT NOT NULL,
        technician_id INT NOT NULL,
        PRIMARY KEY (breakdown_id, technician_id),
        FOREIGN KEY (breakdown_id) REFERENCES breakdowns(id) ON DELETE CASCADE,
        FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO breakdown_technicians (breakdown_id, technician_id) SELECT id, technician_id FROM breakdowns WHERE technician_id IS NOT NULL');
}
$pdo->exec('CREATE TABLE IF NOT EXISTS machine_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM(\'category\',\'brand\',\'model\') NOT NULL,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY kind_name (kind, name)
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS machines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(120) NOT NULL,
    brand VARCHAR(120) NOT NULL,
    model VARCHAR(120) NOT NULL,
    model_code VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS accessories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    brand VARCHAR(120) NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
if (!$pdo->query("SHOW COLUMNS FROM accessories LIKE 'brand'")->fetch()) {
    $pdo->exec('ALTER TABLE accessories ADD brand VARCHAR(120) NULL AFTER name');
}
$pdo->exec('CREATE TABLE IF NOT EXISTS accessory_brand_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$pdo->exec('INSERT IGNORE INTO accessory_brand_tags (name) SELECT DISTINCT brand FROM accessories WHERE brand IS NOT NULL AND brand <> \'\'');
$pdo->exec('CREATE TABLE IF NOT EXISTS accessory_name_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$pdo->exec('INSERT IGNORE INTO accessory_name_tags (name) SELECT DISTINCT name FROM accessories WHERE name <> \'\'');
$pdo->exec('CREATE TABLE IF NOT EXISTS quotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    breakdown_id INT NULL,
    sector_id INT NOT NULL,
    created_by INT NOT NULL,
    quote_date DATE NOT NULL,
    contact_name VARCHAR(190) NOT NULL DEFAULT \'\',
    contact_phone VARCHAR(32) NOT NULL DEFAULT \'\',
    breakdown TEXT NULL,
    remark TEXT NULL,
    machines_json MEDIUMTEXT NOT NULL,
    accessories_json MEDIUMTEXT NOT NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (sector_id), INDEX (breakdown_id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS edit_locks (
    resource_type VARCHAR(32) NOT NULL,
    resource_id INT NOT NULL,
    owner_session VARCHAR(128) NOT NULL,
    owner_name VARCHAR(120) NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (resource_type, resource_id),
    INDEX (expires_at)
) ENGINE=InnoDB');

// Sector login: optional email and/or generated ID. Issued IDs are kept forever so they are never reused after deletion.
$pdo->exec("CREATE TABLE IF NOT EXISTS sector_login_ids (
    login_id VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
if (!$pdo->query("SHOW COLUMNS FROM sectors LIKE 'login_id'")->fetch()) {
    $pdo->exec("ALTER TABLE sectors ADD login_id VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL UNIQUE, ADD password_hash VARCHAR(255) NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sectors LIKE 'password_enc'")->fetch()) {
    $pdo->exec('ALTER TABLE sectors ADD password_enc TEXT NULL');
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

function has_edit_lock(string $type, int $id, string $token): bool {
    global $pdo;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $st = $pdo->prepare('SELECT 1 FROM edit_locks WHERE resource_type=? AND resource_id=? AND owner_session=? AND token=? AND expires_at > NOW()');
    $st->execute([$type, $id, session_id(), $token]);
    return (bool)$st->fetchColumn();
}

function release_edit_lock(string $type, int $id, string $token): void {
    global $pdo;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return;
    $st = $pdo->prepare('DELETE FROM edit_locks WHERE resource_type=? AND resource_id=? AND owner_session=? AND token=?');
    $st->execute([$type, $id, session_id(), $token]);
    if ($pdo->inTransaction()) $pdo->commit();
}

function require_edit_lock(string $type, int $id, string $returnTo): void {
    global $pdo;
    $token = (string)($_POST['edit_lock_token'] ?? '');
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT 1 FROM edit_locks WHERE resource_type=? AND resource_id=? AND owner_session=? AND token=? AND expires_at > NOW() FOR UPDATE');
    $st->execute([$type, $id, session_id(), $token]);
    if (!$st->fetchColumn()) {
        $pdo->rollBack();
        flash('This item is being edited by someone else or your edit session expired. Reload the page before trying again.', 'error');
        redirect($returnTo);
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
        if (!empty($_SESSION['sector_id'])) {
            $st = $pdo->prepare('SELECT id,name,email FROM sectors WHERE id=? AND password_hash IS NOT NULL');
            $st->execute([$_SESSION['sector_id']]);
            if ($sec = $st->fetch()) $u = ['id' => 0, 'name' => $sec['name'], 'email' => (string)$sec['email'], 'role' => 'user', 'sector_id' => (int)$sec['id']];
        } elseif (!empty($_SESSION['uid'])) {
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
    if (!empty($u['sector_id'])) {
        $st = $pdo->prepare('SELECT * FROM sectors WHERE id=?');
        $st->execute([$u['sector_id']]);
        return $st->fetchAll();
    }
    $st = $pdo->prepare('SELECT s.* FROM sectors s JOIN sector_user su ON su.sector_id=s.id WHERE su.user_id=? ORDER BY s.name');
    $st->execute([$u['id']]);
    return $st->fetchAll();
}

// Reuse an issued-but-unsaved ID if one exists, otherwise issue a new one.
function generate_sector_login_id(): string {
    global $pdo;
    $id = $pdo->query('SELECT login_id FROM sector_login_ids WHERE used=0 ORDER BY created_at, login_id LIMIT 1')->fetchColumn();
    if ($id !== false) return $id;
    $digits = '0123456789'; $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $ins = $pdo->prepare('INSERT IGNORE INTO sector_login_ids (login_id) VALUES (?)');
    for ($i = 0; $i < 100; $i++) {
        $chars = [];
        for ($j = 0; $j < 4; $j++) { $chars[] = $digits[random_int(0, 9)]; $chars[] = $letters[random_int(0, 51)]; }
        for ($j = 7; $j > 0; $j--) { $k = random_int(0, $j); [$chars[$j], $chars[$k]] = [$chars[$k], $chars[$j]]; }
        $id = implode('', $chars);
        $ins->execute([$id]);
        if ($ins->rowCount() === 1) return $id;
    }
    throw new RuntimeException('Could not generate a unique ID.');
}

function generate_user_login_id(): string {
    global $pdo;
    $id = $pdo->query("SELECT login_id FROM user_login_ids WHERE used=0 AND login_id REGEXP '[0-9].*[0-9]' ORDER BY created_at, login_id LIMIT 1")->fetchColumn();
    if ($id !== false) return $id;
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $ins = $pdo->prepare('INSERT IGNORE INTO user_login_ids (login_id) VALUES (?)');
    for ($i = 0; $i < 100; $i++) {
        $id = '';
        for ($j = 0; $j < 4; $j++) $id .= $alphabet[random_int(0, 61)];
        if (preg_match_all('/[0-9]/', $id) < 2) continue;
        $ins->execute([$id]);
        if ($ins->rowCount() === 1) return $id;
    }
    throw new RuntimeException('Could not generate a unique ID.');
}

// Admin-viewable sector passwords are stored encrypted with a key kept in a file outside the database.
function sector_password_key(): string {
    $f = __DIR__ . '/.sector_key';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); chmod($f, 0600); }
    return hex2bin(trim(file_get_contents($f)));
}
function encrypt_sector_password(string $pw): string {
    $iv = random_bytes(12);
    $ct = openssl_encrypt($pw, 'aes-256-gcm', sector_password_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}
// User login: mandatory 4-character ID (digits, lowercase, uppercase); email is optional.
$pdo->exec("CREATE TABLE IF NOT EXISTS user_login_ids (
    login_id VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'login_id'")->fetch()) {
    $pdo->exec("ALTER TABLE users ADD login_id VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL UNIQUE, MODIFY email VARCHAR(190) NULL");
}
foreach ($pdo->query('SELECT id FROM users WHERE login_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
    $lid = generate_user_login_id();
    $pdo->prepare('UPDATE users SET login_id=? WHERE id=?')->execute([$lid, $uid]);
    $pdo->prepare('UPDATE user_login_ids SET used=1 WHERE login_id=?')->execute([$lid]);
}

function decrypt_sector_password(?string $enc): string {
    $raw = $enc ? base64_decode($enc, true) : false;
    if ($raw === false || strlen($raw) < 28) return '';
    $pw = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', sector_password_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pw === false ? '' : $pw;
}

// Validates the optional email, login ID and password of a sector form. Returns [error|null, email|null, loginId|null, hash|null, encrypted|null].
function sector_login_input(int $sectorId = 0): array {
    global $pdo;
    $email = trim($_POST['email'] ?? '');
    $loginId = trim($_POST['login_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' && $loginId === '') return ['Enter an email address or generate a login ID.'];
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return ['Enter a valid email address.'];
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM users WHERE email=?) + (SELECT COUNT(*) FROM sectors WHERE email=? AND id<>?)');
        $st->execute([$email, $email, $sectorId]);
        if ($st->fetchColumn()) return ['That email is already used for login.'];
    }
    if ($loginId !== '') {
        if (!preg_match('/^[0-9A-Za-z]{8}$/', $loginId)) return ['Invalid login ID.'];
        $st = $pdo->prepare('SELECT login_id FROM sectors WHERE login_id=? AND id<>?');
        $st->execute([$loginId, $sectorId]);
        if ($st->fetchColumn()) return ['That login ID is already in use.'];
        $st = $pdo->prepare('SELECT used FROM sector_login_ids WHERE login_id=?');
        $st->execute([$loginId]);
        $row = $st->fetch();
        $own = false;
        if ($sectorId) {
            $o = $pdo->prepare('SELECT 1 FROM sectors WHERE id=? AND login_id=?');
            $o->execute([$sectorId, $loginId]);
            $own = (bool)$o->fetchColumn();
        }
        if (!$own && (!$row || (int)$row['used'] === 1)) return ['Use the Generate button to create a valid login ID.'];
    }
    $hash = $enc = null;
    if ($password !== '') {
        if (strlen($password) < 8) return ['Password must be at least 8 characters.'];
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $enc = encrypt_sector_password($password);
    } elseif ($sectorId) {
        $st = $pdo->prepare('SELECT password_hash FROM sectors WHERE id=?');
        $st->execute([$sectorId]);
        if (!$st->fetchColumn()) return ['Set a password for sector login.'];
    } else {
        return ['Set a password for sector login.'];
    }
    return [null, $email === '' ? null : $email, $loginId === '' ? null : $loginId, $hash, $enc];
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
        $where[] = '(b.system_name LIKE ? OR c.name LIKE ? OR b.client_name LIKE ? OR b.contact_phone LIKE ? OR b.fixed_by LIKE ? OR b.note LIKE ?)';
        $like = '%' . addcslashes($f['q'], '%_\\') . '%'; array_push($p, $like, $like, $like, $like, $like, $like);
    }
    $st = $pdo->prepare('SELECT b.*, s.name AS sector_name, c.name AS company_name, (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR \', \') FROM breakdown_technicians bt JOIN technicians t ON t.id=bt.technician_id WHERE bt.breakdown_id=b.id) AS technician_name FROM breakdowns b JOIN sectors s ON s.id=b.sector_id LEFT JOIN companies c ON c.id=b.company_id WHERE '
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
        if ($u['role'] === 'admin') $nav = ['admin/index.php' => 'Sectors', 'admin/companies.php' => 'Company', 'admin/users.php' => 'Users', 'admin/technicians.php' => 'Technicians', 'admin/machines.php' => 'Machines', 'admin/accessories.php' => 'Accessories', 'admin/records.php' => 'Breakdowns'];
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
  <button id="nav-toggle" type="button" class="ml-auto rounded p-2 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-white md:hidden" aria-controls="primary-navigation" aria-expanded="false">
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
    <a href="<?= url('logout.php') ?>" class="w-fit text-sm bg-blue-600 hover:bg-blue-700 rounded px-3 py-1">Logout</a>
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
function page_footer(): void {
    ?>
</main>
<script>
(function () {
  const endpoint = <?= json_encode(url('includes/edit_lock.php')) ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const controlsByForm = new WeakMap();
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

  async function request(action, form, token) {
    const body = new URLSearchParams({
      csrf,
      action,
      type: form.dataset.editLock,
      id: form.dataset.editLockId,
      token
    });
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body
    });
    const result = await response.json();
    if (!response.ok) {
      const error = new Error(result.error || 'Could not coordinate this edit.');
      error.fatal = response.status >= 400 && response.status < 500;
      throw error;
    }
    return result;
  }

  function lockKey(form) {
    return form.dataset.editLock + ':' + form.dataset.editLockId;
  }

  function setDisabled(form, disabled) {
    if (disabled && !controlsByForm.has(form)) {
      controlsByForm.set(form, Array.from(form.elements).map(control => [control, control.disabled]));
    }
    const controls = controlsByForm.get(form);
    if (!controls) return;
    controls.forEach(([control, wasDisabled]) => { control.disabled = disabled || wasDisabled; });
    if (!disabled) controlsByForm.delete(form);
  }

  function statusNode(form) {
    const row = form.closest('tr');
    let node = (row || form).querySelector('[data-edit-lock-status]');
    if (!node) {
      node = document.createElement('p');
      node.dataset.editLockStatus = '';
      node.className = 'mb-3 rounded bg-amber-100 px-3 py-2 text-sm text-amber-900';
      node.setAttribute('role', 'status');
      if (row) row.querySelector('td')?.prepend(node);
      else form.prepend(node);
    }
    return node;
  }

  async function acquire(form) {
    if (form.dataset.lockToken) return form.dataset.lockToken;
    if (form.dataset.lockPromise) return form.lockPromise;
    const token = Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2, '0')).join('');
    form.dataset.lockPromise = 'pending';
    form.lockPromise = (async () => {
      setDisabled(form, true);
      const status = statusNode(form);
      status.textContent = 'Waiting to edit this item...';
      let waited = false;
      while (true) {
        if (form.dataset.lockCancel === '1') {
          delete form.dataset.lockCancel;
          setDisabled(form, false);
          status.textContent = '';
          return null;
        }
        try {
          const result = await request('acquire', form, token);
          if (result.acquired && form.dataset.lockCancel === '1') {
            delete form.dataset.lockCancel;
            await request('release', form, result.token).catch(() => {});
            setDisabled(form, false);
            status.textContent = '';
            return null;
          }
          if (result.acquired) {
            form.dataset.lockToken = result.token;
            const refreshKey = 'edit-lock-refresh:' + lockKey(form);
            if (waited) {
              sessionStorage.setItem(refreshKey, '1');
              form.dataset.lockReloading = '1';
              status.textContent = 'The item was updated by another user. Reloading the latest version...';
              window.location.reload();
              return result.token;
            }
            const refreshed = sessionStorage.getItem(refreshKey) === '1';
            sessionStorage.removeItem(refreshKey);
            status.textContent = refreshed
              ? 'The latest saved version is loaded. You can now make changes.'
              : 'You are editing this item. Other users will wait until you save or leave.';
            status.className = 'mb-3 rounded bg-blue-100 px-3 py-2 text-sm text-blue-900';
            setDisabled(form, false);
            return result.token;
          }
          waited = true;
          status.textContent = 'This item is being edited by ' + result.owner + '. Waiting for it to be saved...';
        } catch (error) {
          if (error.fatal) {
            status.textContent = error.message;
            return null;
          }
          status.textContent = error.message + ' Retrying...';
        }
        await sleep(2000);
      }
    })().finally(() => {
      delete form.dataset.lockPromise;
      delete form.lockPromise;
    });
    return form.lockPromise;
  }

  async function release(form) {
    if (!form.dataset.lockToken) return;
    const token = form.dataset.lockToken;
    delete form.dataset.lockToken;
    try {
      await request('release', form, token);
    } catch (error) {
      console.error('Could not release edit lock:', error);
    }
  }

  window.EditLocks = {acquire, release};
  const forms = Array.from(document.querySelectorAll('form[data-edit-lock]'));
  forms.forEach(form => {
    const refreshKey = 'edit-lock-refresh:' + lockKey(form);
    if (form.dataset.editLockOnLoad !== 'true' && sessionStorage.getItem(refreshKey) === '1') {
      sessionStorage.removeItem(refreshKey);
      statusNode(form).textContent = 'Another user saved this item while you were waiting. The latest data is loaded; review it and retry your action if needed.';
    }
  });
  forms.forEach(form => {
    form.addEventListener('focusin', () => {
      if (!form.dataset.lockToken && form.dataset.editLockManual !== 'true') acquire(form);
    });
    form.addEventListener('submit', async event => {
      if (form.dataset.lockSubmitting === '1' || event.defaultPrevented) return;
      event.preventDefault();
      const submitter = event.submitter;
      if (!await acquire(form)) return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'edit_lock_token';
      input.value = form.dataset.lockToken;
      form.append(input);
      form.dataset.lockSubmitting = '1';
      if (submitter && submitter.name && submitter.value) {
        const buttonValue = document.createElement('input');
        buttonValue.type = 'hidden';
        buttonValue.name = submitter.name;
        buttonValue.value = submitter.value;
        form.append(buttonValue);
      }
      form.submit();
    });
    if (form.dataset.editLockOnLoad === 'true') acquire(form);
  });

  window.setInterval(() => {
    forms.forEach(async form => {
      if (!form.dataset.lockToken || form.dataset.lockReloading === '1') return;
      try {
        const result = await request('heartbeat', form, form.dataset.lockToken);
        if (!result.renewed) {
          delete form.dataset.lockToken;
          setDisabled(form, true);
          statusNode(form).textContent = 'Your edit lock expired. Waiting to reacquire it...';
          acquire(form);
        }
      } catch (error) {
        statusNode(form).textContent = error.message + ' Retrying the edit lock...';
      }
    });
  }, 15000);

  window.addEventListener('pagehide', () => {
    forms.forEach(form => {
      if (!form.dataset.lockToken || form.dataset.lockSubmitting === '1' || form.dataset.lockReloading === '1') return;
      const body = new URLSearchParams({
        csrf,
        action: 'release',
        type: form.dataset.editLock,
        id: form.dataset.editLockId,
        token: form.dataset.lockToken
      });
      navigator.sendBeacon(endpoint, new Blob([body], {type: 'application/x-www-form-urlencoded'}));
    });
  });
})();
// Clear 10-digit requirement messages for phone fields.
(function () {
  const sel = 'input[type="tel"][pattern="[0-9]{10}"]';
  document.addEventListener('invalid', e => {
    if (!e.target.matches(sel)) return;
    const n = e.target.value.length;
    e.target.setCustomValidity(n === 0
      ? 'Enter a 10-digit phone number.'
      : 'Phone number must be exactly 10 digits. You entered ' + n + ' digit' + (n === 1 ? '' : 's') + '.');
  }, true);
  document.addEventListener('input', e => {
    if (e.target.matches(sel)) e.target.setCustomValidity('');
  });
})();
// Stop mouse wheel / trackpad scrolling from changing focused number inputs.
document.addEventListener('wheel', e => {
  const t = e.target;
  if (t instanceof HTMLInputElement && t.type === 'number' && document.activeElement === t) t.blur();
}, {passive: true});
</script>
</body></html>
    <?php
}

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
<?php records_cards($rows, $u);
    if (in_array($u['role'], ['admin', 'support'], true)) {
        $qst = $pdo->prepare('SELECT * FROM quotations WHERE sector_id=? ORDER BY created_at DESC, id DESC');
        $qst->execute([$sid]);
        $savedQuotes = $qst->fetchAll(); ?>
<h2 class="text-xl font-semibold mt-8 mb-3">Saved quotations</h2>
<div class="space-y-2">
<?php foreach ($savedQuotes as $sq): ?>
  <div class="flex flex-wrap items-center justify-between gap-3 bg-white rounded shadow px-4 py-3">
    <div class="min-w-0">
      <div class="font-semibold">Job ID #<?= (int)$sq['id'] ?></div>
      <div class="text-sm text-slate-500"><?= e($sq['quote_date']) ?> &middot; <?= e($sq['contact_name']) ?> &middot; Total <?= number_format((float)$sq['total'], 2) ?></div>
    </div>
    <a href="<?= url('quotation_view.php?id=' . (int)$sq['id']) ?>" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-2 text-sm">View</a>
  </div>
<?php endforeach; if (!$savedQuotes): ?><p class="text-slate-500">No saved quotations for this sector yet.</p><?php endif; ?>
</div>
<?php }
}

function records_table(array $rows, bool $actions = false): void { ?>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr>
  <th class="p-3">Date &amp; time</th><th class="p-3">Sector</th><th class="p-3">Company</th><th class="p-3">System</th><th class="p-3">Client</th><th class="p-3">Phone</th>
  <th class="p-3">Fixed by</th><th class="p-3">Status</th><th class="p-3">Technician</th><th class="p-3">Note</th><?php if ($actions): ?><th class="p-3"></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr class="border-t align-top">
  <td class="p-3 whitespace-nowrap"><?= e(date('Y-m-d H:i', strtotime($r['occurred_at']))) ?></td>
  <td class="p-3"><?= e($r['sector_name']) ?></td><td class="p-3"><?= e($r['company_name'] ?? '') ?></td>
  <td class="p-3"><div class="font-medium"><?= e($r['system_name']) ?></div><div class="text-slate-500"><?= nl2br(e($r['description'])) ?></div></td>
  <td class="p-3"><?= e($r['client_name']) ?></td><td class="p-3"><?= e($r['contact_phone'] ?? '') ?></td><td class="p-3"><?= e($r['fixed_by']) ?></td>
  <td class="p-3"><?= status_badge($r['status']) ?></td>
  <td class="p-3"><?= !empty($r['technician_required']) ? e($r['technician_name'] ?? 'Not assigned') : '—' ?></td>
  <td class="p-3"><?= nl2br(e($r['note'])) ?></td>
  <?php if ($actions): ?><td class="p-3 whitespace-nowrap"><a class="bg-emerald-600 hover:bg-emerald-700 text-white rounded px-3 py-1 text-xs" href="<?= url('support/edit.php?id=' . $r['id']) ?>">Edit</a></td><?php endif; ?>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= $actions ? 11 : 10 ?>" class="p-6 text-center text-slate-500">No breakdowns found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php }

// Line-card list; each card opens the breakdown detail page.
function records_cards(array $rows, array $u): void { ?>
<div class="space-y-2">
<?php foreach ($rows as $r): ?>
  <div class="flex flex-wrap items-center justify-between gap-3 bg-white rounded shadow px-4 py-3">
    <a href="<?= url('breakdown.php?id=' . (int)$r['id']) ?>" class="min-w-0 flex-1 hover:text-blue-700">
      <div class="font-semibold truncate"><?= e($r['system_name']) ?></div>
      <div class="text-sm text-slate-500 truncate"><?= e($r['sector_name']) ?> &middot; <?= e($r['company_name'] ?? 'No company') ?> &middot; <?= e(date('Y-m-d H:i', strtotime($r['occurred_at']))) ?><?php if (!empty($r['technician_required'])): ?> &middot; Technicians: <?= e($r['technician_name'] ?? 'Not assigned') ?><?php endif; ?></div>
      <div class="text-sm text-slate-500">Client: <?= e($r['client_name']) ?><?php if (!empty($r['contact_phone'])): ?> (<?= e($r['contact_phone']) ?>)<?php endif; ?> &middot; Fixed by: <?= e($r['fixed_by']) ?></div>
      <div class="mt-1"><?= status_badge($r['status']) ?></div>
    </a>
    <?php if ($u['role'] === 'admin' && !empty($r['technician_required'])): ?>
      <a href="<?= url('quotation.php?breakdown_id=' . (int)$r['id']) ?>" target="_blank" rel="noopener" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-2 text-sm whitespace-nowrap">Make Quotations</a>
    <?php endif; ?>
  </div>
<?php endforeach; if (!$rows): ?><p class="text-slate-500">No breakdowns found.</p><?php endif; ?>
</div>
<?php }
