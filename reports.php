<?php
require 'config.php';
hanya_admin();
ensure_transaction_status_column($pdo);

$purchasePriceColumn = $pdo->prepare(
    "SELECT COUNT(*)
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'products'
       AND COLUMN_NAME = 'harga_beli'"
);
$purchasePriceColumn->execute();
if (!(int)$purchasePriceColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER kode");
}

$stockCapitalColumn = $pdo->prepare(
    "SELECT COUNT(*)
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'products'
       AND COLUMN_NAME = 'modal_total'"
);
$stockCapitalColumn->execute();
if (!(int)$stockCapitalColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN modal_total DECIMAL(12,2) NULL AFTER harga_beli");
}

$totalCapital = (float)$pdo->query(
    "SELECT COALESCE(SUM(COALESCE(modal_total, harga_beli * stok, 0)), 0) FROM products"
)->fetchColumn();

$paidTransactions = $pdo->query(
    "SELECT COUNT(*) AS transaction_count, COALESCE(SUM(total), 0) AS revenue
     FROM transactions
     WHERE status = 'Lunas'"
)->fetch();
$transactionCount = (int)$paidTransactions['transaction_count'];
$totalRevenue = (float)$paidTransactions['revenue'];
$netProfit = $totalRevenue - $totalCapital;
$averageTransaction = $transactionCount > 0 ? $totalRevenue / $transactionCount : 0;

$pageTitle = 'Laporan Keuangan';
$contentClass = 'reports-page';
require 'includes/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <h3>Ringkasan Keuangan</h3>
            <span class="muted">Nilai beli seluruh stok dan transaksi yang sudah lunas</span>
        </div>
    </div>
    <div class="report-profit-summary">
        <article class="report-total">
            <span>Total modal</span>
            <strong><?= rupiah($totalCapital) ?></strong>
            <small>Total harga beli seluruh stok barang</small>
        </article>
        <article class="report-total">
            <span>Total pendapatan</span>
            <strong><?= rupiah($totalRevenue) ?></strong>
            <small>Dari transaksi berstatus lunas</small>
        </article>
        <article class="report-total">
            <span>Laba bersih</span>
            <strong><?= rupiah($netProfit) ?></strong>
            <small>Pendapatan dikurangi total modal</small>
        </article>
        <article class="report-total">
            <span>Jumlah transaksi</span>
            <strong><?= number_format($transactionCount, 0, ',', '.') ?></strong>
            <small>Total transaksi lunas</small>
        </article>
        <article class="report-total">
            <span>Rata-rata transaksi</span>
            <strong><?= rupiah($averageTransaction) ?></strong>
            <small>Pendapatan dibagi jumlah transaksi lunas</small>
        </article>
    </div>
</section>
<?php require 'includes/footer.php'; ?>