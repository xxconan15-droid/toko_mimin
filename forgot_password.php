<?php
require 'config.php';
ensure_password_reset_schema($pdo);

$message = '';
$messageType = 'info';
$tokenInput = $_GET['token'] ?? $_POST['token'] ?? '';
$token = is_string($tokenInput) ? $tokenInput : '';
$isResetRequest = $token !== '';
$tokenRow = null;

if ($isResetRequest) {
    $stmt = $pdo->prepare("SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $tokenRow = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.';
        $messageType = 'error';
    } elseif (($_POST['action'] ?? '') === 'request') {
        $usernameInput = $_POST['username'] ?? '';
        $emailInput = $_POST['email'] ?? '';
        $username = is_string($usernameInput) ? trim($usernameInput) : '';
        $email = is_string($emailInput) ? trim($emailInput) : '';

        if ($username !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT id,email FROM users WHERE username=? AND email=? LIMIT 1");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();
            if ($user) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=? AND created_at>DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
                $stmt->execute([$user['id']]);
                if (!(int)$stmt->fetchColumn()) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $baseUrl = getenv('TOKOMIMIN_BASE_URL') ?: 'http://localhost/toko_mimin';
                    $sender = getenv('TOKOMIMIN_MAIL_FROM') ?: ini_get('sendmail_from');
                    $baseParts = parse_url($baseUrl);
                    $validBaseUrl = filter_var($baseUrl, FILTER_VALIDATE_URL)
                        && is_array($baseParts)
                        && in_array($baseParts['scheme'] ?? '', ['http', 'https'], true)
                        && isset($baseParts['host'])
                        && !isset($baseParts['user'])
                        && !isset($baseParts['pass']);

                    if (!$validBaseUrl || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                        error_log('Password reset email is not configured. Set TOKOMIMIN_BASE_URL and TOKOMIMIN_MAIL_FROM, and configure PHP mail/SMTP.');
                    } else {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id=?");
                        $stmt->execute([$user['id']]);
                        $stmt = $pdo->prepare("INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                        $stmt->execute([$user['id'], $tokenHash]);
                        $pdo->commit();

                        $resetUrl = rtrim($baseUrl, '/') . '/forgot_password.php?token=' . rawurlencode($token);
                        $subject = 'Reset Kata Sandi Toko Mimin';
                        $body = "Kami menerima permintaan untuk mengatur ulang kata sandi akun Toko Mimin Anda.\n\n"
                            . "Buka tautan berikut dalam 1 jam untuk membuat kata sandi baru:\n"
                            . $resetUrl
                            . "\n\nJika Anda tidak meminta reset, abaikan email ini.";
                        $headers = [
                            'From' => $sender,
                            'Content-Type' => 'text/plain; charset=UTF-8',
                            'X-Mailer' => 'PHP/' . PHP_VERSION
                        ];
                        if (!mail($user['email'], $subject, $body, $headers)) {
                            $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE token_hash=?");
                            $stmt->execute([$tokenHash]);
                            error_log('Password reset email could not be sent by PHP mail(). Check SMTP configuration.');
                        }
                    }
                }
            }
        }
        $message = 'Jika username dan email cocok dengan akun serta layanan email tersedia, instruksi reset akan dikirim. Periksa kotak masuk atau spam.';
    } elseif (($_POST['action'] ?? '') === 'reset') {
        $passwordInput = $_POST['password_baru'] ?? '';
        $confirmationInput = $_POST['konfirmasi'] ?? '';
        $newPassword = is_string($passwordInput) ? $passwordInput : '';
        $confirmation = is_string($confirmationInput) ? $confirmationInput : '';

        if (!$tokenRow) {
            $message = 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan reset yang baru.';
            $messageType = 'error';
            $isResetRequest = false;
        } elseif (strlen($newPassword) < 8) {
            $message = 'Kata sandi baru minimal 8 karakter.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmation) {
            $message = 'Konfirmasi kata sandi tidak sama.';
            $messageType = 'error';
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1 FOR UPDATE");
            $stmt->execute([hash('sha256', $token)]);
            $tokenRow = $stmt->fetch();
            if (!$tokenRow) {
                $pdo->rollBack();
                $message = 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan reset yang baru.';
                $messageType = 'error';
                $isResetRequest = false;
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $tokenRow['user_id']]);
                $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id=?");
                $stmt->execute([$tokenRow['user_id']]);
                $pdo->commit();
                unset($_SESSION['csrf_token']);
                $isResetRequest = false;
                $tokenRow = null;
                $message = 'Kata sandi berhasil diubah. Silakan masuk dengan kata sandi baru.';
            }
        }
    } else {
        $message = 'Permintaan tidak dikenali.';
        $messageType = 'error';
    }
}

if ($isResetRequest && !$tokenRow && $message === '') {
    $message = 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan reset yang baru.';
    $messageType = 'error';
}

$pageTitle = 'Lupa Kata Sandi';
$stylesheetVersion = '20261025-44';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= e($pageTitle) ?> - Toko Mimin</title>
    <link rel="stylesheet" href="assets/style.css?v=20261025-44">
</head>
<body class="login-body">
<main class="login-wrap">
    <section class="login-brand">
        <div class="brand-icon">🛒</div>
        <h1>Toko Mimin</h1>
        <p>Sistem Kasir Toko Kelontong</p>
    </section>
    <section class="login-card">
        <h2><?= $isResetRequest ? 'Buat Kata Sandi Baru' : 'Lupa Kata Sandi?' ?></h2>
        <p class="muted"><?= $isResetRequest ? 'Pilih kata sandi baru untuk akun Anda.' : 'Masukkan username dan email pemulihan yang terdaftar.' ?></p>
        <?php if ($message !== ''): ?><div class="alert <?= e($messageType) ?>" role="status"><?= e($message) ?></div><?php endif; ?>
        <?php if ($isResetRequest && $tokenRow): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label for="reset-password">Kata Sandi Baru</label>
                <input id="reset-password" type="password" name="password_baru" minlength="8" autocomplete="new-password" required>
                <label for="reset-confirmation">Konfirmasi Kata Sandi Baru</label>
                <input id="reset-confirmation" type="password" name="konfirmasi" minlength="8" autocomplete="new-password" required>
                <button class="btn gold full" type="submit">Simpan Kata Sandi</button>
            </form>
        <?php elseif (!$isResetRequest): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="request">
                <label for="reset-username">Username</label>
                <input id="reset-username" name="username" autocomplete="username" required>
                <label for="reset-email">Email Pemulihan</label>
                <input id="reset-email" type="email" name="email" autocomplete="email" required>
                <button class="btn gold full" type="submit">Kirim Tautan Reset</button>
            </form>
            <p class="password-reset-help">Tautan berlaku selama 1 jam. Email dan SMTP server harus sudah dikonfigurasi.</p>
        <?php endif; ?>
        <a class="forgot-password-link" href="index.php">Kembali ke Login</a>
    </section>
</main>
</body>
</html>
