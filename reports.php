<?php
require 'config.php';
wajib_login();
ensure_transaction_status_column($pdo);

$productUnitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'satuan'");
$productUnitColumn->execute();
if (!(int)$productUnitColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN satuan VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER stok");
}
$itemCapitalColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'modal_total'");
$itemCapitalColumn->execute();
if (!(int)$itemCapitalColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE transaction_items ADD COLUMN modal_total DECIMAL(12,2) NULL AFTER total");
}
$itemUnitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'satuan'");
$itemUnitColumn->execute();
if (!(int)$itemUnitColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE transaction_items ADD COLUMN satuan VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER qty");
    $pdo->exec("UPDATE transaction_items ti JOIN products p ON p.id=ti.product_id SET ti.satuan=p.satuan");
}
$productPurchaseColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'harga_beli'");
$productPurchaseColumn->execute();
if (!(int)$productPurchaseColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER kode");
}
$productCapitalColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'modal_total'");
$productCapitalColumn->execute();
if (!(int)$productCapitalColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN modal_total DECIMAL(12,2) NULL AFTER harga_beli");
}
$productPackPriceColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'harga_pack'");
$productPackPriceColumn->execute();
if (!(int)$productPackPriceColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN harga_pack DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER harga");
}
$productPackSizeColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'isi_per_pack'");
$productPackSizeColumn->execute();
if (!(int)$productPackSizeColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE products ADD COLUMN isi_per_pack INT NOT NULL DEFAULT 1 AFTER harga_pack");
}

$pageTitle = 'Modal & Keuntungan';
$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-d');
$isValidDate = static function ($value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
};
$from = $isValidDate($_GET['from'] ?? null) ? $_GET['from'] : $defaultFrom;
$to = $isValidDate($_GET['to'] ?? null) ? $_GET['to'] : $defaultTo;
$dateError = '';
if ($from > $to) {
    $dateError = 'Tanggal awal tidak boleh melewati tanggal akhir.';
    $from = $defaultFrom;
    $to = $defaultTo;
}

$sql = "SELECT t.id,t.total,t.created_at,
            item_costs.modal_total AS modal_transaksi,
            item_costs.missing_cost_lines
        FROM transactions t
        LEFT JOIN (
            SELECT ti.transaction_id,
                SUM(CASE
                    WHEN ti.modal_total IS NOT NULL THEN ti.modal_total
                    WHEN p.modal_total IS NOT NULL AND p.stok > 0
                        THEN (p.modal_total / p.stok) * ti.qty
                            * CASE WHEN p.satuan IN ('pack','dus','sachet','box') AND ti.satuan=p.satuan
                                THEN GREATEST(p.isi_per_pack,1) ELSE 1 END
                    WHEN p.id IS NOT NULL
                        THEN p.harga_beli * ti.qty
                            * CASE WHEN p.satuan IN ('pack','dus','sachet','box') AND ti.satuan=p.satuan
                                THEN GREATEST(p.isi_per_pack,1) ELSE 1 END
                    ELSE NULL
                END) AS modal_total,
                SUM(CASE
                    WHEN ti.modal_total IS NULL
                        AND (p.id IS NULL OR (p.modal_total IS NULL AND COALESCE(p.harga_beli,0) <= 0))
                    THEN 1 ELSE 0
                END) AS missing_cost_lines
            FROM transaction_items ti
            LEFT JOIN products p ON p.id=ti.product_id
            GROUP BY ti.transaction_id
        ) item_costs ON item_costs.transaction_id=t.id
        WHERE t.status='Lunas' AND DATE(t.created_at)>=? AND DATE(t.created_at)<=?
        ORDER BY t.created_at ASC";
$statement = $pdo->prepare($sql);
$statement->execute([$from, $to]);
$transactions = $statement->fetchAll();

$revenue = 0.0;
$capital = 0.0;
$profit = 0.0;
$missingCapitalCount = 0;
$profitableCount = 0;
$lossCount = 0;
$breakEvenCount = 0;
$unavailableCount = 0;
$timeline = [];
foreach ($transactions as $transaction) {
    $saleTotal = (float)$transaction['total'];
    $revenue += $saleTotal;
    $dateKey = date('Y-m-d', strtotime($transaction['created_at']));
    if (!isset($timeline[$dateKey])) {
        $timeline[$dateKey] = ['revenue' => 0.0, 'capital' => 0.0, 'profit' => 0.0, 'capital_available' => true];
    }
    $timeline[$dateKey]['revenue'] += $saleTotal;

    if ($transaction['modal_transaksi'] === null || (int)$transaction['missing_cost_lines'] > 0) {
        $missingCapitalCount++;
        $unavailableCount++;
        $timeline[$dateKey]['capital_available'] = false;
        continue;
    }
    $transactionCapital = (float)$transaction['modal_transaksi'];
    $transactionProfit = $saleTotal - $transactionCapital;
    $capital += $transactionCapital;
    $profit += $transactionProfit;
    $timeline[$dateKey]['capital'] += $transactionCapital;
    $timeline[$dateKey]['profit'] += $transactionProfit;
    if ($transactionProfit > 0) {
        $profitableCount++;
    } elseif ($transactionProfit < 0) {
        $lossCount++;
    } else {
        $breakEvenCount++;
    }
}

$chartLabels = [];
$chartRevenue = [];
$chartCapital = [];
$chartProfit = [];
foreach ($timeline as $date => $values) {
    $chartLabels[] = date('d M', strtotime($date));
    $chartRevenue[] = round($values['revenue'], 2);
    $chartCapital[] = $values['capital_available'] ? round($values['capital'], 2) : null;
    $chartProfit[] = $values['capital_available'] ? round($values['profit'], 2) : null;
}
$chartData = [
    'labels' => $chartLabels,
    'revenue' => $chartRevenue,
    'capital' => $chartCapital,
    'profit' => $chartProfit,
    'transactions' => [$profitableCount, $lossCount, $breakEvenCount, $unavailableCount],
];
$contentClass = 'reports-page';
require 'includes/header.php';
?>
<?php if ($dateError !== ''): ?><div class="alert error"><?= e($dateError) ?></div><?php endif; ?>
<section class="panel">
    <div class="panel-head"><h3>Periode Perhitungan</h3><span class="muted">Hanya transaksi lunas</span></div>
    <form class="toolbar filters" method="get">
        <div><label for="report-from">Dari tanggal</label><input id="report-from" type="date" name="from" value="<?= e($from) ?>"></div>
        <div><label for="report-to">Sampai tanggal</label><input id="report-to" type="date" name="to" value="<?= e($to) ?>"></div>
        <button class="btn blue">Terapkan</button>
    </form>
    <div class="report-profit-summary">
        <div class="report-total"><span>Total omzet lunas</span><strong><?= rupiah($revenue) ?></strong></div>
        <div class="report-total"><span>Total HPP / modal</span><strong><?= $missingCapitalCount > 0 ? 'Data belum lengkap' : rupiah($capital) ?></strong></div>
        <div class="report-total"><span>Keuntungan kotor</span><strong><?= $missingCapitalCount > 0 ? 'Data belum lengkap' : rupiah($profit) ?></strong><small><?= count($transactions) ?> transaksi</small></div>
    </div>
    <p class="muted report-capital-note">HPP transaksi baru menggunakan snapshot modal saat penjualan, dihitung dari rata-rata modal stok dan biaya pembelian yang dicatat ketika stok ditambahkan.</p>
    <?php if ($missingCapitalCount > 0): ?><div class="alert error">HPP tidak dapat dihitung untuk <?= $missingCapitalCount ?> transaksi karena data biaya beli yang valid belum tersedia.</div><?php endif; ?>
</section>

<section class="report-chart-grid" aria-label="Grafik modal dan keuntungan">
    <article class="panel report-chart-panel">
        <div class="panel-head"><h3>Omzet, HPP, dan Keuntungan</h3><span class="muted">Grafik batang harian</span></div>
        <canvas class="report-chart" id="profit-bar-chart" role="img" aria-label="Grafik batang omzet, HPP, dan keuntungan harian"></canvas>
        <div class="report-legend"><span><i class="legend-dot revenue"></i>Omzet</span><span><i class="legend-dot capital"></i>HPP</span><span><i class="legend-dot profit"></i>Keuntungan</span></div>
    </article>
    <article class="panel report-chart-panel">
        <div class="panel-head"><h3>Tren Keuntungan</h3><span class="muted">Grafik garis harian</span></div>
        <canvas class="report-chart" id="profit-line-chart" role="img" aria-label="Grafik garis tren keuntungan harian"></canvas>
    </article>
    <article class="panel report-chart-panel report-chart-donut-panel">
        <div class="panel-head"><h3>Rasio Hasil Transaksi</h3><span class="muted">Grafik bundar berdasarkan transaksi</span></div>
        <div class="report-donut-wrap">
            <canvas class="report-donut" id="profit-donut-chart" role="img" aria-label="Grafik bundar transaksi untung, rugi, dan HPP tidak tersedia"></canvas>
            <div class="report-donut-total"><strong><?= count($transactions) ?></strong><span>transaksi lunas</span></div>
        </div>
        <div class="report-legend report-donut-legend"><span><i class="legend-dot profit"></i>Untung (<?= $profitableCount ?>)</span><span><i class="legend-dot loss"></i>Rugi (<?= $lossCount ?>)</span><span><i class="legend-dot break-even"></i>Impas (<?= $breakEvenCount ?>)</span><span><i class="legend-dot unavailable"></i>HPP tidak tersedia (<?= $unavailableCount ?>)</span></div>
    </article>
</section>
<script>
(() => {
    const data = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const colors = { revenue: '#1265dc', capital: '#f4bd43', profit: '#10a779', loss: '#e05252', breakEven: '#8960ec', unavailable: '#9aa8ba', grid: '#e8eef5', text: '#74849b' };
    const money = (value) => {
        const abs = Math.abs(value);
        if (abs >= 1000000) return `Rp${(value / 1000000).toFixed(1)} jt`;
        if (abs >= 1000) return `Rp${(value / 1000).toFixed(0)} rb`;
        return `Rp${Math.round(value)}`;
    };
    const prepareCanvas = (canvas) => {
        const rect = canvas.getBoundingClientRect();
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(1, Math.floor(rect.width * ratio));
        canvas.height = Math.max(1, Math.floor(rect.height * ratio));
        const ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        return { ctx, width: rect.width, height: rect.height };
    };
    const drawAxes = (ctx, width, height, min, max, left, right, top, bottom) => {
        const chartHeight = height - top - bottom;
        const steps = 4;
        ctx.font = '10px Inter, Arial, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        for (let index = 0; index <= steps; index++) {
            const value = max - ((max - min) * index / steps);
            const y = top + chartHeight * index / steps;
            ctx.strokeStyle = colors.grid;
            ctx.beginPath();
            ctx.moveTo(left, y);
            ctx.lineTo(width - right, y);
            ctx.stroke();
            ctx.fillStyle = colors.text;
            ctx.fillText(money(value), left - 8, y);
        }
        return { chartHeight, chartWidth: width - left - right };
    };
    const drawBarChart = () => {
        const canvas = document.getElementById('profit-bar-chart');
        if (!canvas) return;
        const { ctx, width, height } = prepareCanvas(canvas);
        const values = [...data.revenue, ...data.capital, ...data.profit].filter(Number.isFinite);
        const max = Math.max(1, ...values);
        const min = Math.min(0, ...data.profit);
        const left = 65, right = 12, top = 14, bottom = 32;
        const { chartHeight, chartWidth } = drawAxes(ctx, width, height, min, max, left, right, top, bottom);
        const zeroY = top + chartHeight * max / (max - min || 1);
        const count = data.labels.length;
        if (!count) {
            ctx.fillStyle = colors.text;
            ctx.textAlign = 'center';
            ctx.fillText('Belum ada transaksi lunas pada periode ini', width / 2, height / 2);
            return;
        }
        const groupWidth = chartWidth / count;
        const barWidth = Math.max(2, Math.min(16, groupWidth * 0.22));
        const series = [[data.revenue, colors.revenue], [data.capital, colors.capital], [data.profit, colors.profit]];
        data.labels.forEach((label, index) => {
            const center = left + groupWidth * (index + 0.5);
            series.forEach(([items, color], seriesIndex) => {
                const value = items[index];
                if (!Number.isFinite(value)) return;
                const yValue = top + chartHeight * (max - value) / (max - min || 1);
                ctx.fillStyle = color;
                ctx.fillRect(center + (seriesIndex - 1) * barWidth - barWidth / 2, Math.min(yValue, zeroY), barWidth, Math.max(1, Math.abs(zeroY - yValue)));
            });
            if (count <= 12 || index % Math.ceil(count / 12) === 0) {
                ctx.fillStyle = colors.text;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'top';
                ctx.fillText(label, center, height - bottom + 9);
            }
        });
    };
    const drawLineChart = () => {
        const canvas = document.getElementById('profit-line-chart');
        if (!canvas) return;
        const { ctx, width, height } = prepareCanvas(canvas);
        const values = data.profit;
        const availableValues = values.filter(Number.isFinite);
        const minValue = Math.min(0, ...availableValues);
        const maxValue = Math.max(1, ...availableValues);
        const pad = (maxValue - minValue) * 0.12 || 1;
        const min = minValue - pad;
        const max = maxValue + pad;
        const left = 65, right = 12, top = 14, bottom = 32;
        const { chartHeight, chartWidth } = drawAxes(ctx, width, height, min, max, left, right, top, bottom);
        if (!availableValues.length) {
            ctx.fillStyle = colors.text;
            ctx.textAlign = 'center';
            ctx.fillText('Belum ada data keuntungan pada periode ini', width / 2, height / 2);
            return;
        }
        const xAt = (index) => left + (values.length === 1 ? chartWidth / 2 : chartWidth * index / (values.length - 1));
        const yAt = (value) => top + chartHeight * (max - value) / (max - min || 1);
        ctx.strokeStyle = colors.profit;
        ctx.lineWidth = 2.5;
        ctx.beginPath();
        let hasPreviousPoint = false;
        values.forEach((value, index) => {
            if (!Number.isFinite(value)) {
                hasPreviousPoint = false;
                return;
            }
            if (!hasPreviousPoint) ctx.moveTo(xAt(index), yAt(value));
            else ctx.lineTo(xAt(index), yAt(value));
            hasPreviousPoint = true;
        });
        ctx.stroke();
        values.forEach((value, index) => {
            if (!Number.isFinite(value)) return;
            ctx.fillStyle = colors.profit;
            ctx.beginPath();
            ctx.arc(xAt(index), yAt(value), 3.5, 0, Math.PI * 2);
            ctx.fill();
            if (data.labels.length <= 12 || index % Math.ceil(data.labels.length / 12) === 0) {
                ctx.fillStyle = colors.text;
                ctx.font = '10px Inter, Arial, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'top';
                ctx.fillText(data.labels[index], xAt(index), height - bottom + 9);
            }
        });
    };
    const drawDonutChart = () => {
        const canvas = document.getElementById('profit-donut-chart');
        if (!canvas) return;
        const { ctx, width, height } = prepareCanvas(canvas);
        const values = data.transactions;
        const total = values.reduce((sum, value) => sum + value, 0);
        const colorsByIndex = [colors.profit, colors.loss, colors.breakEven, colors.unavailable];
        const centerX = width / 2, centerY = height / 2, radius = Math.min(width, height) * 0.39;
        if (!total) {
            ctx.strokeStyle = colors.grid;
            ctx.lineWidth = Math.max(14, radius * 0.25);
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
            ctx.stroke();
            return;
        }
        let start = -Math.PI / 2;
        values.forEach((value, index) => {
            if (!value) return;
            const end = start + Math.PI * 2 * value / total;
            ctx.strokeStyle = colorsByIndex[index];
            ctx.lineWidth = Math.max(14, radius * 0.25);
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius, start, end);
            ctx.stroke();
            start = end;
        });
    };
    const renderCharts = () => {
        drawBarChart();
        drawLineChart();
        drawDonutChart();
    };
    renderCharts();
    window.addEventListener('resize', renderCharts);
})();
</script>
<?php require 'includes/footer.php'; ?>
