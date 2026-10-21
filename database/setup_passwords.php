<?php
// Jalankan sekali melalui browser: http://localhost/tokoku_native_php/database/setup_passwords.php
require dirname(__DIR__) . '/config.php';
$users = [
 ['admin', 'admin123'],
 ['kasir', 'kasir123']
];
foreach ($users as [$username, $password]) {
    $st = $pdo->prepare("UPDATE users SET password=? WHERE username=?");
    $st->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
}
echo "Password demo sudah disetel. Demi keamanan, hapus file setup_passwords.php setelah digunakan.";
