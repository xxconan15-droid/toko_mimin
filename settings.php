<?php
require 'config.php';
wajib_login();
ensure_password_reset_schema($pdo);

$message = '';
$messageType = 'success';
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$_SESSION['user']['id']]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    exit('Akun tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.';
        $messageType = 'error';
    } else {
        $nameInput = $_POST['nama'] ?? '';
        $emailInput = $_POST['email'] ?? '';
        $passwordInput = $_POST['password_baru'] ?? '';
        $confirmationInput = $_POST['konfirmasi'] ?? '';
        $name = is_string($nameInput) ? trim($nameInput) : '';
        $email = is_string($emailInput) ? trim($emailInput) : '';
        $newPassword = is_string($passwordInput) ? $passwordInput : '';
        $confirmation = is_string($confirmationInput) ? $confirmationInput : '';

        if ($name === '' || strlen($name) > 100) {
            $message = 'Nama wajib diisi dan maksimal 100 karakter.';
            $messageType = 'error';
        } elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) {
            $message = 'Masukkan alamat email yang valid.';
            $messageType = 'error';
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            $message = 'Kata sandi baru minimal 8 karakter.';
            $messageType = 'error';
        } elseif ($newPassword !== '' && $newPassword !== $confirmation) {
            $message = 'Konfirmasi kata sandi tidak sama.';
            $messageType = 'error';
        } else {
            if ($newPassword !== '') {
                $stmt = $pdo->prepare("UPDATE users SET nama=?,email=?,password=? WHERE id=?");
                $stmt->execute([$name, $email !== '' ? $email : null, password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
                $message = 'Profil dan kata sandi berhasil diperbarui.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET nama=?,email=? WHERE id=?");
                $stmt->execute([$name, $email !== '' ? $email : null, $user['id']]);
                $message = 'Profil berhasil diperbarui.';
            }
            $_SESSION['user']['nama'] = $name;
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$user['id']]);
            $user = $stmt->fetch();
        }
    }
}

$pageTitle = 'Pengaturan Profil';
require 'includes/header.php';
?>
<div class="grid-two">
    <section class="panel">
        <div class="profile-head">
            <div class="avatar">👤</div>
            <div><h3><?= e($user['nama']) ?></h3><p class="muted"><?= e($user['username']) ?> · <?= e(ucfirst($user['role'])) ?></p></div>
        </div>
        <?php if ($message !== ''): ?><div class="alert <?= e($messageType) ?>"><?= e($message) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="profile-name">Nama Lengkap</label>
            <input id="profile-name" name="nama" maxlength="100" value="<?= e($user['nama']) ?>" required>
            <label for="profile-username">Username</label>
            <input id="profile-username" value="<?= e($user['username']) ?>" disabled>
            <label for="profile-email">Email Pemulihan Kata Sandi</label>
            <input id="profile-email" type="email" name="email" maxlength="254" autocomplete="email" value="<?= e($user['email'] ?? '') ?>" placeholder="nama@contoh.com">
            <small>Gunakan email yang dapat Anda buka untuk menerima tautan reset kata sandi.</small>
            <label for="new-password">Kata Sandi Baru (opsional)</label>
            <input id="new-password" type="password" name="password_baru" minlength="8" autocomplete="new-password">
            <label for="confirm-password">Konfirmasi Kata Sandi Baru</label>
            <input id="confirm-password" type="password" name="konfirmasi" minlength="8" autocomplete="new-password">
            <button class="btn gold" type="submit">Simpan Perubahan</button>
        </form>
    </section>
    <section class="panel">
        <h3>Informasi Akun</h3>
        <div class="info-row"><span>Nama</span><b><?= e($user['nama']) ?></b></div>
        <div class="info-row"><span>Role</span><b><?= e(ucfirst($user['role'])) ?></b></div>
        <div class="info-row"><span>Username</span><b><?= e($user['username']) ?></b></div>
        <div class="info-row"><span>Email pemulihan</span><b><?= e($user['email'] ?: 'Belum diatur') ?></b></div>
        <div class="info-row"><span>Dibuat</span><b><?= e($user['created_at']) ?></b></div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
