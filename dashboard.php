<?php
require 'config.php'; wajib_login();
ensure_transaction_status_column($pdo);
$pageTitle = 'Dashboard';
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$yearStart = date('Y-01-01');
$nextYearStart = date('Y-01-01', strtotime('+1 year'));
$monthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-01', strtotime('first day of next month'));
$isCashier = $_SESSION['user']['role'] === 'kasir';
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) omzet FROM transactions WHERE status='Lunas' AND created_at >= ? AND created_at < ?");
$stmt->execute([$today, $tomorrow]); $todayData = $stmt->fetch();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM transactions WHERE status='Lunas' AND created_at >= ? AND created_at < ?");
$stmt->execute([$monthStart, $nextMonthStart]); $monthlyRevenue = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM transactions WHERE status='Pending'");
$stmt->execute(); $pendingTotal = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE status='Lunas' AND created_at >= ? AND created_at < ?");
$stmt->execute([$today, $tomorrow]); $todayPaidTransactionCount = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COALESCE(SUM(ti.qty),0) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE t.status='Lunas' AND t.created_at >= ? AND t.created_at < ?");
$stmt->execute([$monthStart, $nextMonthStart]); $monthlySoldCount = (float)$stmt->fetchColumn();
$monthlySoldCountDisplay = rtrim(rtrim(number_format($monthlySoldCount,3,'.',''), '0'), '.');
$chartPeriodLabel = $isCashier ? date('d/m/Y') : date('m/Y');
$chartTitle = $isCashier ? 'Akumulasi Penjualan Hari Ini' : 'Akumulasi Penjualan Bulan Ini';
$chartDescription = $isCashier ? 'Total penjualan lunas berjalan setiap jam' : 'Total penjualan berjalan tiap hari';
$chartAriaLabel = $isCashier ? 'Diagram akumulasi penjualan per jam hari ini' : 'Diagram gunung akumulasi penjualan per hari bulan ' . date('m/Y');
$chartScrollPosition = 0;
if ($isCashier) {
    $stmt = $pdo->prepare("SELECT HOUR(created_at) jam, SUM(total) omzet FROM transactions WHERE status='Lunas' AND created_at >= ? AND created_at < ? GROUP BY HOUR(created_at)");
    $stmt->execute([$today, $tomorrow]);
    $hourlySales = array_fill(0, 24, 0.0);
    foreach ($stmt->fetchAll() as $sale) {
        $hourlySales[(int)$sale['jam']] = (float)$sale['omzet'];
    }
    $salesSlots = $hourlySales;
    $chartSlotCount = 24;
    $chartDaySpacing = 48;
    $chartScrollPosition = (int)date('G');
} else {
    $stmt = $pdo->prepare("SELECT DATE(created_at) tanggal, SUM(total) omzet FROM transactions WHERE status='Lunas' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)");
    $stmt->execute([$monthStart, $nextMonthStart]);
    $monthlyDays = (int)date('t');
    $dailySales = array_fill(1, $monthlyDays, 0.0);
    foreach ($stmt->fetchAll() as $sale) {
        $dailySales[(int)date('j', strtotime($sale['tanggal']))] = (float)$sale['omzet'];
    }
    $salesSlots = $dailySales;
    $chartSlotCount = $monthlyDays;
    $chartDaySpacing = 34;
    $chartScrollPosition = (int)date('j') - 1;
}
$cumulativeSales = [];
$runningSales = 0.0;
foreach ($salesSlots as $slot => $amount) {
    $runningSales += $amount;
    $cumulativeSales[$slot] = $runningSales;
}
$maxCumulativeSales = max($cumulativeSales);
$chartScale = $maxCumulativeSales > 0 ? $maxCumulativeSales : 1;
$chartLeft = 72;
$chartRight = $chartLeft + ($chartSlotCount - 1) * $chartDaySpacing;
$chartWidth = $chartRight + 24;
$chartTop = 28;
$chartBottom = 222;
$chartCoordinates = [];
$chartPoints = [];
foreach ($cumulativeSales as $day => $amount) {
    $x = $chartLeft + ($isCashier ? $day : $day - 1) * $chartDaySpacing;
    $y = $chartBottom - ($amount / $chartScale) * ($chartBottom - $chartTop);
    $chartCoordinates[$day] = [$x, $y];
    $chartPoints[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
}
$areaPoints = implode(' ', $chartPoints);
$chartAreaPoints = $chartLeft . ',' . $chartBottom . ' ' . $areaPoints . ' ' . $chartRight . ',' . $chartBottom;
require 'includes/header.php';
?>
<div class="dashboard-page">
<div class="stats dashboard-stats">
<div class="stat blue"><span>Penjualan Hari Ini</span><strong><?= rupiah($todayData['omzet']) ?></strong><small>Omzet tanggal <?= date('d/m/Y') ?></small></div>
<div class="stat green"><span>Pendapatan Bulan Ini</span><strong><?= rupiah($monthlyRevenue) ?></strong><small>Omzet bulan <?= date('m/Y') ?></small></div>
<div class="stat purple"><span>Jumlah Transaksi Lunas Hari Ini</span><strong><?= $todayPaidTransactionCount ?></strong><small>Transaksi lunas tanggal <?= date('d/m/Y') ?></small></div>
<div class="stat purple"><span>Barang Terjual Bulan Ini</span><strong><?= e($monthlySoldCountDisplay) ?></strong><small>Akumulasi barang bulan <?= date('m/Y') ?></small></div>
<a class="stat gold" href="pending.php"><span>Total Pending</span><strong><?= rupiah($pendingTotal) ?></strong><small>Belum dibayar · lihat transaksi pending</small></a>
</div>
<div class="grid-two dashboard-panels"><section class="panel monthly-mountain-panel">
<div class="panel-head"><div><h3><?= e($chartTitle) ?></h3><small><?= e($chartDescription) ?></small></div><span class="chart-year"><?= e($chartPeriodLabel) ?></span></div>
<div class="monthly-mountain-scroll" tabindex="0" aria-label="Diagram dapat digeser untuk melihat tanggal lain">
<svg class="monthly-mountain" width="<?= $chartWidth ?>" height="280" viewBox="0 0 <?= $chartWidth ?> 280" role="img" aria-label="<?= e($chartAriaLabel) ?>">
<title><?= e($chartTitle) ?></title>
<defs>
<linearGradient id="mountainFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#78d7cc" stop-opacity=".72"></stop><stop offset="100%" stop-color="#267d91" stop-opacity=".08"></stop></linearGradient>
<linearGradient id="mountainLine" x1="0" x2="1" y1="0" y2="0"><stop offset="0%" stop-color="#8be5d6"></stop><stop offset="100%" stop-color="#f1d18e"></stop></linearGradient>
</defs>
<?php for ($tick = 0; $tick <= 4; $tick++): $tickY = $chartTop + ($chartBottom - $chartTop) * $tick / 4; $tickAmount = $maxCumulativeSales * (4 - $tick) / 4; ?>
<line x1="<?= $chartLeft ?>" y1="<?= number_format($tickY, 1, '.', '') ?>" x2="<?= $chartRight ?>" y2="<?= number_format($tickY, 1, '.', '') ?>" class="mountain-grid-line"></line>
<text x="<?= $chartLeft - 8 ?>" y="<?= number_format($tickY + 4, 1, '.', '') ?>" text-anchor="end" class="mountain-axis-label"><?= e(rupiah($tickAmount)) ?></text>
<?php endfor; ?>
<polygon points="<?= e($chartAreaPoints) ?>" class="mountain-area"></polygon>
<polyline points="<?= e($areaPoints) ?>" class="mountain-line"></polyline>
<?php foreach ($cumulativeSales as $day => $amount): [$pointX, $pointY] = $chartCoordinates[$day]; $detail = $isCashier ? sprintf('%02d:00 — %s', $day, rupiah($amount)) : sprintf('%02d/%02d/%s — %s', $day, (int)date('m'), date('Y'), rupiah($amount)); ?>
<circle cx="<?= number_format($pointX, 1, '.', '') ?>" cy="<?= number_format($pointY, 1, '.', '') ?>" r="10" class="mountain-hover-point" tabindex="0" role="img" aria-label="<?= e($detail) ?>" data-detail="<?= e($detail) ?>"><title><?= e($detail) ?></title></circle>
<circle cx="<?= number_format($pointX, 1, '.', '') ?>" cy="<?= number_format($pointY, 1, '.', '') ?>" r="3.5" class="mountain-visible-point" aria-hidden="true"></circle>
<?php if (!$isCashier || $day % 3 === 0 || $day === 23): ?>
<text x="<?= number_format($pointX, 1, '.', '') ?>" y="252" text-anchor="middle" class="mountain-axis-label"><?= $day ?></text>
<?php endif; ?>
<?php endforeach; ?>
</svg>
</div>
<div class="mountain-tooltip" role="status" hidden></div>
</section>
<section class="panel connected-devices-panel"><div class="panel-head"><div><h3>Perangkat Kasir</h3><small>Kelola koneksi alat yang digunakan</small></div><a href="devices.php">Sambungkan →</a></div>
<a class="connected-device" href="devices.php"><span class="connected-device-icon">▦</span><div><b>Scanner Barcode</b><small>Atur koneksi USB atau Bluetooth</small></div><span class="device-status-indicator"></span></a>
<a class="connected-device" href="devices.php"><span class="connected-device-icon">▤</span><div><b>Printer Struk</b><small>Atur koneksi USB atau Bluetooth</small></div><span class="device-status-indicator"></span></a>
<p class="device-note">Dukungan koneksi bergantung pada browser dan protokol perangkat.</p>
</section></div>
<script>
const mountainPanel = document.querySelector('.monthly-mountain-panel');
const mountainScroll = mountainPanel.querySelector('.monthly-mountain-scroll');
mountainScroll.scrollLeft = Math.max(0, <?= (int)$chartCoordinates[$isCashier ? (int)date('G') : (int)date('j')][0] ?> - mountainScroll.clientWidth * 0.75);
const mountainTooltip = mountainPanel.querySelector('.mountain-tooltip');
const mountainPoints = mountainPanel.querySelectorAll('.mountain-hover-point');
const showMountainTooltip = (point, clientX, clientY) => {
    const bounds = mountainPanel.getBoundingClientRect();
    mountainTooltip.textContent = point.dataset.detail;
    mountainTooltip.hidden = false;
    mountainTooltip.style.left = `${Math.min(Math.max(clientX - bounds.left, 100), bounds.width - 100)}px`;
    mountainTooltip.style.top = `${Math.max(clientY - bounds.top - 12, 8)}px`;
};
mountainPoints.forEach((point) => {
    point.addEventListener('pointerenter', (event) => showMountainTooltip(point, event.clientX, event.clientY));
    point.addEventListener('pointermove', (event) => showMountainTooltip(point, event.clientX, event.clientY));
    point.addEventListener('pointerleave', () => { mountainTooltip.hidden = true; });
    point.addEventListener('focus', () => {
        const bounds = point.getBoundingClientRect();
        showMountainTooltip(point, bounds.left + bounds.width / 2, bounds.top);
    });
    point.addEventListener('blur', () => { mountainTooltip.hidden = true; });
});
</script>
 </div>
<?php require 'includes/footer.php'; ?>
