<?php
require __DIR__ . '/../src/bootstrap.php';

if (Auth::hasAnyUser()) {
    header('Location: login.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($username) < 3) {
        $error = 'Username troppo corto (minimo 3 caratteri).';
    } elseif (strlen($password) < 8) {
        $error = 'La password deve avere almeno 8 caratteri.';
    } elseif ($password !== $password2) {
        $error = 'Le due password non coincidono.';
    } else {
        Auth::createUser($username, $password);
        Auth::attempt($username, $password);
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Setup iniziale';
require __DIR__ . '/partials/head.php';
?>
<div class="auth-wrap">
  <div class="auth-box">
    <div class="logo">◈ ORBITALEYE</div>
    <div class="sub">Configurazione account operatore</div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required minlength="8">
      </div>
      <div class="field">
        <label>Conferma password</label>
        <input type="password" name="password2" required minlength="8">
      </div>
      <button class="btn btn-primary" type="submit" style="width:100%;">Crea account e accedi</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer_bare.php'; ?>
