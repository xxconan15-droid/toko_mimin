<?php
wajib_login();
$pageTitle = $pageTitle ?? 'Dashboard';
$namaPengguna = $_SESSION['user']['nama'] ?? $_SESSION['user']['username'] ?? 'Pengguna';
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($pageTitle) ?> - TokoKu</title><link rel="stylesheet" href="assets/style.css?v=20261021-38"></head><body>
<div class="app">
<aside class="sidebar"><a class="brand" href="dashboard.php"><span class="brand-icon small">🛒</span><span><b>TokoKu</b><small><?= e(ucfirst($_SESSION['user']['role'])) ?></small></span></a>
<nav>
<a class="<?= aktif('dashboard.php') ?>" href="dashboard.php">▦ &nbsp; Dashboard</a>
<a class="<?= aktif('products.php') ?>" href="products.php">▤ &nbsp; Kelola Barang</a>
<?php if ($_SESSION['user']['role'] === 'admin'): ?><a class="<?= aktif('promos.php') ?>" href="promos.php">◇ &nbsp; Diskon & Promo</a><?php endif; ?>
<a class="<?= aktif('transactions.php') ?>" href="transactions.php">▣ &nbsp; Transaksi / Kasir</a>
<a class="<?= aktif('reports.php') ?>" href="reports.php">▥ &nbsp; Modal & Keuntungan</a>
<?php if ($_SESSION['user']['role'] === 'admin'): ?><a class="<?= aktif('devices.php') ?>" href="devices.php">▧ &nbsp; Perangkat</a><a class="<?= aktif('settings.php') ?>" href="settings.php">⚙ &nbsp; Pengaturan</a><?php endif; ?>
</nav><div class="sidebar-bottom"><div>👤 <?= e($namaPengguna) ?></div><a href="logout.php">⇥ &nbsp; Logout</a></div></aside>
<main class="main"><header class="topbar"><div><span class="muted"><?= date('l, d M Y') ?></span><h2><?= e($pageTitle) ?></h2></div><div class="user-pill">👤 <?= e($namaPengguna) ?> <span class="muted">· <?= e(ucfirst($_SESSION['user']['role'])) ?></span></div></header><div class="content <?= e($contentClass ?? '') ?>">
