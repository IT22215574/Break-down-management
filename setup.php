<?php
// Run once from the terminal: php setup.php [admin_email] [admin_password]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from CLI only.'); }
$c = require __DIR__ . '/config.php';
$pdo = new PDO("mysql:host={$c['db_host']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$c['db_name']}`");
foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $sql) $pdo->exec($sql);
$sectorColumns = $pdo->query('SHOW COLUMNS FROM sectors')->fetchAll(PDO::FETCH_COLUMN);
foreach (['phone' => 'VARCHAR(32) NULL', 'email' => 'VARCHAR(190) NULL', 'address' => 'VARCHAR(255) NULL'] as $column => $definition) {
    if (!in_array($column, $sectorColumns, true)) {
        $pdo->exec("ALTER TABLE sectors ADD COLUMN {$column} {$definition}");
    }
}
$email = $argv[1] ?? 'admin@example.com';
$pass = $argv[2] ?? 'Admin@12345';
$st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
$st->execute([$email]);
if (!$st->fetchColumn()) {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('Administrator',?,?,'admin')")
        ->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
    echo "Admin created: $email / $pass (change it after login)\n";
}
echo "Setup complete.\n";
