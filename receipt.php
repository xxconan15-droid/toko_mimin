<?php
require 'config.php'; wajib_login(); ensure_transaction_status_column($pdo);
$id=(int)($_GET['id']??0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle') {
    $stmt = $pdo->prepare("UPDATE transactions SET status='Lunas', payment_method='Tunai' WHERE id=? AND status='Pending'");
    $stmt->execute([$id]);
    header('Location: receipt.php?id=' . $id . '&settled=' . ($stmt->rowCount() ? '1' : '0'));
    exit;
}
$st=$pdo->prepare("SELECT t.*,u.nama kasir FROM transactions t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=?");$st->execute([$id]);$trx=$st->fetch();if(!$trx){header('Location: transactions.php');exit;}
$st=$pdo->prepare("SELECT * FROM transaction_items WHERE transaction_id=?");$st->execute([$id]);$items=$st->fetchAll();
$formatQuantity=static fn($value): string => rtrim(rtrim(number_format((float)$value,3,'.',''), '0'), '.');
$pageTitle='Struk Transaksi';require 'includes/header.php';
?>
<div class="receipt-actions"><a class="btn light" href="transactions.php">← Kembali ke Kasir</a><button class="btn blue" onclick="window.print()">Cetak Struk</button></div>
<?php if (($_GET['saved'] ?? '') === '1'): ?><div class="alert info">Transaksi berhasil disimpan.</div><?php endif; ?>
<?php if (($_GET['settled'] ?? '') === '1'): ?><div class="alert info">Transaksi berhasil ditandai lunas.</div><?php elseif (($_GET['settled'] ?? '') === '0'): ?><div class="alert error">Transaksi tidak ditemukan atau sudah bukan berstatus pending.</div><?php endif; ?>
<div class="receipt"><div class="receipt-brand">🛒 <b>TokoKu</b><small>Toko Kelontong</small></div><p class="center">Jl. Contoh No. 12, Indonesia<br>Terima kasih telah berbelanja</p><hr><b><?= $trx['status'] === 'Pending' ? 'CATATAN HUTANG' : 'STRUK PEMBELIAN' ?></b><p>No. Transaksi: <?= e($trx['kode']) ?><br>Tanggal: <?= date('d/m/Y H:i',strtotime($trx['created_at'])) ?><br>Kasir: <?= e($trx['kasir']) ?><br>Status: <?= e($trx['status']) ?></p><table><thead><tr><th>Nama Barang</th><th>Qty</th><th>Total</th></tr></thead><tbody><?php foreach($items as $it): ?><tr><td><?= e($it['nama_produk']) ?><br><small><?= rupiah($it['harga']) ?> / <?= e($it['satuan']??'pcs') ?></small></td><td><?= $it['satuan']==='kg'?$formatQuantity($it['qty']):(int)$it['qty'] ?> <?= e($it['satuan']??'pcs') ?></td><td><?= rupiah($it['total']) ?></td></tr><?php endforeach; ?></tbody></table><div class="receipt-total"><span>Total</span><b><?= rupiah($trx['total']) ?></b></div><p>Metode pembayaran: <?= e($trx['payment_method']) ?></p><p class="center"><?= $trx['status'] === 'Pending' ? 'Pembayaran belum diterima.' : 'Simpan struk ini sebagai bukti transaksi.' ?></p></div>
<?php if ($trx['status'] === 'Pending'): ?><form method="post" class="receipt-actions" onsubmit="return confirm('Tandai transaksi ini sudah dibayar tunai?')"><input type="hidden" name="action" value="settle"><button class="btn gold" type="submit">Tandai Lunas</button></form><?php endif; ?>
<?php require 'includes/footer.php'; ?>
