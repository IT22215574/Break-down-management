<?php
require __DIR__ . '/includes/bootstrap.php';
if ($u = current_user()) redirect(home_for($u['role']));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $st = $pdo->prepare('SELECT * FROM users WHERE email=? AND active=1');
    $st->execute([$email]);
    $row = $st->fetch();
    // Always run a hash check so timing doesn't reveal whether the email exists.
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsaltbutnotreal.abcdefghijklmnopqrstu';
    if (password_verify($_POST['password'] ?? '', $hash) && $row) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $row['id'];
        redirect(home_for($row['role']));
    }
    $error = 'Invalid email or password.';
}
page_header('Login');
?>
<div class="max-w-sm mx-auto mt-16 bg-white rounded shadow p-6">
  <h1 class="text-xl font-bold mb-1">Breakdown Management</h1>
  <p class="text-sm text-slate-500 mb-4">Sign in with your email and password.</p>
  <?php if ($error): ?><div class="mb-3 rounded bg-red-100 text-red-800 text-sm px-3 py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="space-y-3"><?= csrf_field() ?>
    <label class="block text-sm">Email<input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" class="mt-1 w-full border rounded px-3 py-2"></label>
    <label class="block text-sm">Password<input type="password" name="password" required class="mt-1 w-full border rounded px-3 py-2"></label>
    <button class="w-full bg-slate-900 text-white rounded py-2">Login</button>
  </form>
</div>
<?php page_footer();
