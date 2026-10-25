<?php
require 'config.php';
if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = $_POST['username'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $username = is_string($usernameInput) ? trim($usernameInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $storedPassword = is_array($user) && is_string($user['password'] ?? null)
            ? $user['password']
            : '';
        $passwordInfo = password_get_info($storedPassword);
        $passwordIsHashed = $passwordInfo['algo'] !== null && $passwordInfo['algo'] !== 0;
        $passwordMatches = $user && (
            ($passwordIsHashed && password_verify($password, $storedPassword))
            || (!$passwordIsHashed && hash_equals($storedPassword, $password))
        );
        if ($passwordMatches) {
            if (!$passwordIsHashed) {
                $rehash = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            $_SESSION['user'] = ['id' => $user['id'], 'nama' => $user['nama'], 'username' => $user['username'], 'role' => $user['role']];
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Username atau password tidak sesuai.';
    }
}
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Login - Toko Mimin</title><link rel="stylesheet" href="assets/style.css?v=20261025-44"></head>
<body class="login-body">
<div class="login-wrap">
  <section class="login-brand"><div class="brand-icon">🛒</div><h1>Toko Mimin</h1><p>Sistem Kasir Toko Kelontong</p><small>Mudah | Cepat | Akurat</small><ul><li>📦 Kelola stok barang</li><li>⚡ Transaksi cepat</li><li>📈 Hitung HPP & keuntungan</li></ul></section>
  <section class="login-card"><h2>Masuk ke Akun Anda</h2><p class="muted">Masukkan username dan password Anda</p>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Username</label><input name="username" placeholder="Masukkan username" required>
      <label>Password</label><input type="password" name="password" placeholder="Masukkan password" required>
      <button class="btn gold full" type="submit">Login</button>
    </form>
    <a class="forgot-password-link" href="forgot_password.php">Lupa kata sandi?</a>
    </section>
</div>
</body></html>
