<?php
require __DIR__ . '/../src/bootstrap.php';

if (!Auth::hasAnyUser()) {
    header('Location: setup.php');
    exit;
}
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (Auth::attempt($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Credenziali non valide.';
}

$pageTitle = 'Accesso';
require __DIR__ . '/partials/head.php';
?>
<div class="auth-wrap">
  <div class="auth-box">
    <div class="logo">◈ ORBITALEYE</div>
    <div class="sub">Satellite Change Intelligence Terminal</div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Accedi</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer_bare.php'; ?>
