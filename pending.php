<?php
require 'config.php';
wajib_login();
ensure_transaction_status_column($pdo);

$stmt = $pdo->query(
    "SELECT t.id,t.kode,t.total,t.payment_method,t.created_at,COALESCE(u.nama,'-') AS kasir,
        (SELECT COUNT(*) FROM transaction_items ti WHERE ti.transaction_id=t.id) AS item_count
     FROM transactions t
     LEFT JOIN users u ON u.id=t.user_id
     WHERE t.status='Pending'
     ORDER BY t.created_at DESC,t.id DESC"
);
$transactions = $stmt->fetchAll();
$pendingTotal = array_sum(array_column($transactions, 'total'));
$pageTitle = 'Transaksi Pending';
require 'includes/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <h3>Transaksi Belum Lunas</h3>
            <span class="muted"><?= count($transactions) ?> transaksi · Total <?= rupiah($pendingTotal) ?></span>
        </div>
        <a class="btn light" href="transactions.php">＋ Transaksi Baru</a>
    </div>
    <?php if (!$transactions): ?>
        <div class="empty">Belum ada transaksi pending.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No. Transaksi</th>
                        <th>Tanggal</th>
                        <th>Kasir</th>
                        <th>Jumlah Barang</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?= e($transaction['kode']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?></td>
                            <td><?= e($transaction['kasir']) ?></td>
                            <td><?= (int)$transaction['item_count'] ?></td>
                            <td><strong><?= rupiah($transaction['total']) ?></strong></td>
                            <td><span class="badge warning">Pending</span></td>
                            <td>
                                <div class="pending-actions">
                                    <a class="btn light" href="receipt.php?id=<?= (int)$transaction['id'] ?>">👁️ Lihat Struk</a>
                                    <form method="post" action="receipt.php?id=<?= (int)$transaction['id'] ?>" onsubmit="return confirm('Tandai transaksi ini sudah dibayar tunai?')">
                                        <input type="hidden" name="action" value="settle">
                                        <button class="btn gold" type="submit">Lunasi</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require 'includes/footer.php'; ?>
