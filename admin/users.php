<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $isUpdate = $action === 'update';
        $id = (int)($_POST['id'] ?? 0);
        $name = person_name_with_title((string)($_POST['name'] ?? ''), (string)($_POST['name_title'] ?? ''));
        $email = trim($_POST['email'] ?? ''); $pw = $_POST['password'] ?? '';
        $loginId = trim($_POST['login_id'] ?? '');
        $role = $_POST['role'] ?? '';
        $err = null;
        if ($name === null || person_name_length($name) > 120 || !in_array($role, ['admin', 'support', 'user'], true)) $err = 'Valid name and role are required.';
        elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) $err = 'Enter a valid email address or leave it empty.';
        elseif (!preg_match('/^[0-9A-Za-z]{4}$/', $loginId)) $err = 'A 4-character login ID is required (use Generate).';
        elseif (!$isUpdate && strlen($pw) < 8) $err = 'A password of 8+ characters is required.';
        elseif ($isUpdate && $pw !== '' && strlen($pw) < 8) $err = 'Password must be 8+ characters.';
        if (!$err) {
            $st = $pdo->prepare('SELECT used FROM user_login_ids WHERE login_id=?');
            $st->execute([$loginId]);
            $issued = $st->fetch();
            $own = false;
            if ($isUpdate) {
                $o = $pdo->prepare('SELECT 1 FROM users WHERE id=? AND login_id=?');
                $o->execute([$id, $loginId]);
                $own = (bool)$o->fetchColumn();
            }
            if (!$own && (preg_match_all('/[0-9]/', $loginId) < 2 || !$issued || (int)$issued['used'] === 1)) $err = 'Use the Generate button to create a valid login ID.';
        }
        if ($isUpdate && !$err && $id === $u['id'] && $role !== 'admin') $err = 'You cannot change your own role.';
        if ($err) {
            flash($err, 'error');
        } elseif ($isUpdate) {
            require_edit_lock('user', $id, 'admin/users.php');
            try {
                $pdo->prepare('UPDATE users SET name=?, email=?, login_id=?, role=?, password_hash=COALESCE(?, password_hash) WHERE id=?')
                    ->execute([$name, $email === '' ? null : $email, $loginId, $role, $pw === '' ? null : password_hash($pw, PASSWORD_DEFAULT), $id]);
                $pdo->prepare('UPDATE user_login_ids SET used=1 WHERE login_id=?')->execute([$loginId]);
                flash('User updated.');
            } catch (PDOException $e) { flash('That email is already registered.', 'error'); }
            release_edit_lock('user', $id, (string)$_POST['edit_lock_token']);
        } else {
            try {
                $pdo->prepare('INSERT INTO users (name,email,login_id,password_hash,role) VALUES (?,?,?,?,?)')
                    ->execute([$name, $email === '' ? null : $email, $loginId, password_hash($pw, PASSWORD_DEFAULT), $role]);
                $pdo->prepare('UPDATE user_login_ids SET used=1 WHERE login_id=?')->execute([$loginId]);
                flash('User created.');
            } catch (PDOException $e) { flash('That email is already registered.', 'error'); }
        }
    } elseif ($action === 'delete' && (int)$_POST['id'] !== $u['id']) {
        $id = (int)$_POST['id'];
        require_edit_lock('user', $id, 'admin/users.php');
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        release_edit_lock('user', $id, (string)$_POST['edit_lock_token']);
        flash('User deleted.');
    } elseif ($action === 'toggle' && (int)$_POST['id'] !== $u['id']) {
        $id = (int)$_POST['id'];
        require_edit_lock('user', $id, 'admin/users.php');
        $pdo->prepare('UPDATE users SET active = 1 - active WHERE id=?')->execute([$id]);
        release_edit_lock('user', $id, (string)$_POST['edit_lock_token']);
        flash('User updated.');
    } elseif ($action === 'password' && strlen($_POST['password'] ?? '') >= 8) {
        $id = (int)$_POST['id'];
        require_edit_lock('user', $id, 'admin/users.php');
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
        release_edit_lock('user', $id, (string)$_POST['edit_lock_token']);
        flash('Password changed.');
    } else flash('Action not allowed or password too short (8+).', 'error');
    redirect('admin/users.php');
}
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) { $st = $pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([$editId]); $edit = $st->fetch() ?: null; }
$users = $pdo->query('SELECT * FROM users ORDER BY role,name')->fetchAll();
page_header('Users', $u);
?>
<h1 class="text-2xl font-bold mb-4">Users</h1>
<?php
[$nameTitle, $nameRest] = person_name_parts($edit['name'] ?? '');
?>
<form method="post" class="bg-white rounded shadow p-4 mb-6 grid md:grid-cols-3 gap-3 items-end"<?= $edit ? ' data-edit-lock="user" data-edit-lock-id="' . (int)$edit['id'] . '"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
  <label class="text-sm">Name<div class="mt-1 flex gap-1">
    <select name="name_title" class="border rounded px-2 py-2"><?php foreach (PERSON_NAME_TITLES as $value => $title): ?><option value="<?= e($value) ?>" <?= $value === $nameTitle ? 'selected' : '' ?>><?= e($title) ?></option><?php endforeach; ?></select>
    <input name="name" required maxlength="108" value="<?= e($nameRest) ?>" class="min-w-0 w-full border rounded px-3 py-2"></div></label>
  <label class="text-sm">Email (optional)<input type="email" name="email" maxlength="190" value="<?= e($edit['email'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
  <div class="text-sm">Login ID (required)
    <div class="mt-1 flex gap-2"><input name="login_id" required readonly maxlength="4" value="<?= e($edit['login_id'] ?? '') ?>" class="login-id w-full border rounded px-3 py-2 bg-slate-50 font-mono"><button type="button" class="gen-id bg-slate-700 text-white rounded px-3 text-sm">Generate</button></div></div>
  <label class="text-sm">Password<?= $edit ? ' (leave blank to keep)' : '' ?><input type="password" name="password" minlength="8" <?= $edit ? '' : 'required' ?> autocomplete="new-password" class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="text-sm">Role<select name="role" class="mt-1 w-full border rounded px-3 py-2">
    <?php foreach (['support' => 'IT support', 'admin' => 'Admin'] as $v => $l): ?><option value="<?= $v ?>" <?= ($edit['role'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <div class="flex gap-2"><button class="bg-slate-900 text-white rounded py-2 px-4"><?= $edit ? 'Save user' : 'Add user' ?></button><?php if ($edit): ?><a href="users.php" class="py-2 px-3 text-sm text-slate-600">Cancel</a><?php endif; ?></div>
</form>
<script>
document.querySelectorAll('.gen-id').forEach(btn => btn.addEventListener('click', async () => {
  const body = new URLSearchParams({csrf: <?= json_encode(csrf_token()) ?>, type: 'user'});
  const r = await fetch('generate_id.php', {method: 'POST', body});
  if (r.ok) btn.closest('div').querySelector('.login-id').value = (await r.json()).id;
}));
</script>
<div class="bg-white rounded shadow overflow-x-auto"><table class="w-full text-sm">
<thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Name</th><th class="p-3">Login ID</th><th class="p-3">Email</th><th class="p-3">Role</th><th class="p-3">Status</th><th class="p-3">Actions</th></tr></thead><tbody>
<?php foreach ($users as $r): ?><tr class="border-t">
  <td class="p-3"><?= e($r['name']) ?></td><td class="p-3 font-mono"><?= e($r['login_id']) ?></td><td class="p-3"><?= e($r['email'] ?? '') ?></td><td class="p-3"><?= e($r['role']) ?></td>
  <td class="p-3"><?= $r['active'] ? 'Active' : 'Disabled' ?></td>
  <td class="p-3"><div class="flex gap-2 items-center">
    <form method="post" class="flex gap-1" data-edit-lock="user" data-edit-lock-id="<?= (int)$r['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <input type="password" name="password" minlength="8" placeholder="New password" class="border rounded px-2 py-1 text-xs"><button class="text-blue-600 text-xs">Set</button></form>
    <a href="users.php?edit=<?= (int)$r['id'] ?>" class="text-xs text-blue-600">Edit</a>
    <?php if ($r['id'] != $u['id']): ?><form method="post" data-edit-lock="user" data-edit-lock-id="<?= (int)$r['id'] ?>" onsubmit="return confirm('Delete this user?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="text-xs text-red-600">Delete</button></form><form method="post" data-edit-lock="user" data-edit-lock-id="<?= (int)$r['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="text-xs text-red-600"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form><?php endif; ?>
  </div></td></tr><?php endforeach; ?></tbody></table></div>
<?php page_footer();
