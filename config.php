<?php
session_start();

$host = 'localhost';
$dbname = 'tokoku_kasir';
$dbuser = 'root';
$dbpass = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbuser,
        $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Koneksi database gagal. Pastikan database tokoku_kasir sudah di-import dari folder database. Detail: " . htmlspecialchars($e->getMessage()));
}

function ensure_transaction_status_column(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'status'");
    $stmt->execute();
    if (!(int)$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Lunas' AFTER payment_method");
    }
}

function ensure_password_reset_schema(PDO $pdo): void {
    $emailColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'");
    $emailColumn->execute();
    if (!(int)$emailColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(254) NULL AFTER username");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME NULL,
        INDEX idx_password_reset_user_created (user_id, created_at),
        CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}
function wajib_login() {
    if (empty($_SESSION['user'])) {
        header('Location: index.php');
        exit;
    }
}
function hanya_admin() {
    wajib_login();
    if ($_SESSION['user']['role'] !== 'admin') {
        header('Location: dashboard.php');
        exit;
    }
}
function aktif($file) {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
