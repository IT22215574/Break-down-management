<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $pw = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8 || !in_array($role, ['admin', 'support', 'user'], true)) {
            flash('Valid name, email, role and a password of 8+ characters are required.', 'error');
        } else {
            try {
                $pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role]);
                flash('User created.');
            } catch (PDOException $e) { flash('That email is already registered.', 'error'); }
        }
    } elseif ($action === 'toggle' && (int)$_POST['id'] !== $u['id']) {
        $pdo->prepare('UPDATE users SET active = 1 - active WHERE id=?')->execute([(int)$_POST['id']]);
        flash('User updated.');
    } elseif ($action === 'password' && strlen($_POST['password'] ?? '') >= 8) {
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), (int)$_POST['id']]);
        flash('Password changed.');
    } else flash('Action not allowed or password too short (8+).', 'error');
    redirect('admin/users.php');
}
$users = $pdo->query('SELECT * FROM users ORDER BY role,name')->fetchAll();
page_header('Users', $u);
?>
<h1 class="text-2xl font-bold mb-4">Users</h1>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-5 gap-3 items-end"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label class="text-sm">Name<input name="name" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Email<input type="email" name="email" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Password<input type="password" name="password" minlength="8" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Role<select name="role" class="mt-1 w-full border rounded px-3 py-2">
    <option value="support">IT support</option><option value="user">User (view only)</option><option value="admin">Admin</option></select></label>
  <button class="bg-slate-900 text-white rounded py-2">Add user</button>
</form>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Name</th><th class="p-3">Email</th><th class="p-3">Role</th><th class="p-3">Status</th><th class="p-3">Actions</th></tr></thead><tbody>
<?php foreach ($users as $r): ?><tr class="border-t">
  <td class="p-3"><?= e($r['name']) ?></td><td class="p-3"><?= e($r['email']) ?></td><td class="p-3"><?= e($r['role']) ?></td>
  <td class="p-3"><?= $r['active'] ? 'Active' : 'Disabled' ?></td>
  <td class="p-3"><div class="flex gap-2 items-center">
    <form method="post" class="flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <input type="password" name="password" minlength="8" placeholder="New password" class="border rounded px-2 py-1 text-xs"><button class="text-blue-600 text-xs">Set</button></form>
    <?php if ($r['id'] != $u['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="text-xs text-red-600"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form><?php endif; ?>
  </div></td></tr><?php endforeach; ?></tbody></table></div>
<?php page_footer();
