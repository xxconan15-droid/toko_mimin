<?php
require 'config.php'; hanya_admin();
// Keep the product link available on older databases.
$promoProductColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promos' AND COLUMN_NAME = 'product_id'");
$promoProductColumn->execute();
if (!(int)$promoProductColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE promos ADD COLUMN product_id INT NULL AFTER id");
}

$formError = '';
$formValues = [];
$actionNotice = $_SERVER['REQUEST_METHOD'] === 'GET' ? ([
    'created' => 'Promo berhasil ditambahkan.',
    'updated' => 'Promo berhasil diperbarui.',
    'deleted' => 'Promo berhasil dihapus.',
    'delete_failed' => 'Promo tidak ditemukan atau gagal dihapus.'
][$_GET['notice'] ?? ''] ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM promos WHERE id=?");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: promos.php?notice='.($stmt->rowCount()?'deleted':'delete_failed'));
        exit;
    }
    if ($action === 'create' || $action === 'update') {
        $formValues = $_POST;
        $promoId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $type = $_POST['jenis'] ?? '';
        $valueRaw = $_POST['nilai'] ?? '';
        $value = is_scalar($valueRaw) ? (float)$valueRaw : 0;
        $starts = '1000-01-01';
        $ends = '9999-12-31';
        $status = $_POST['status'] ?? '';
        if (($action === 'update' && !$promoId) || !$productId) {
            $formError = 'Pilih produk yang valid.';
        } elseif (!in_array($type, ['Persentase', 'Nominal'], true) || !is_numeric($valueRaw) || !is_finite($value) || $value <= 0 || ($type === 'Persentase' && $value > 100) || $value > 9999999999.99) {
            $formError = 'Jenis atau nilai diskon tidak valid. Persentase maksimal 100%.';
        } elseif (!in_array($status, ['Aktif', 'Nonaktif'], true)) {
            $formError = 'Status promo tidak valid.';
        } else {
            $productCheck = $pdo->prepare("SELECT id,nama FROM products WHERE id=?");
            $productCheck->execute([$productId]);
            $selectedProduct = $productCheck->fetch();
            if (!$selectedProduct) {
                $formError = 'Produk tidak ditemukan.';
            } else {
                $name = $selectedProduct['nama'];
                if ($action === 'update') {
                    $promoCheck = $pdo->prepare("SELECT id FROM promos WHERE id=?");
                    $promoCheck->execute([$promoId]);
                    if (!$promoCheck->fetchColumn()) {
                        $formError = 'Promo tidak ditemukan.';
                    } else {
                        $stmt = $pdo->prepare("UPDATE promos SET product_id=?,nama=?,jenis=?,nilai=?,mulai=?,selesai=?,status=? WHERE id=?");
                        $stmt->execute([$productId, $name, $type, $value, $starts, $ends, $status, $promoId]);
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO promos(product_id,nama,jenis,nilai,mulai,selesai,status) VALUES(?,?,?,?,?,?,?)");
                    $stmt->execute([$productId, $name, $type, $value, $starts, $ends, $status]);
                }
                if ($formError === '') {
                    header('Location: promos.php?notice='.($action==='update'?'updated':'created'));
                    exit;
                }
            }
        }
    }
}

$pageTitle = 'Diskon & Promo';
$contentClass = 'promos-content';
$rows = $pdo->query("SELECT promos.*, products.nama nama_produk, products.kategori kategori_produk, products.harga harga_produk, products.harga_pack harga_pack_produk, products.satuan, products.satuan_isi FROM promos LEFT JOIN products ON products.id=promos.product_id ORDER BY promos.id DESC")->fetchAll();
$products = $pdo->query("SELECT id,nama,kategori,harga,harga_beli,modal_total,stok,satuan,isi_per_pack,satuan_isi FROM products ORDER BY nama")->fetchAll();
$search = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['kategori'] ?? '');
$unitFilter = trim($_GET['satuan'] ?? '');
$categories = array_values(array_unique(array_column($products, 'kategori')));
$units = array_values(array_unique(array_column($products, 'satuan')));
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
sort($units, SORT_NATURAL | SORT_FLAG_CASE);
$hasProductFilters = $search !== '' || $categoryFilter !== '' || $unitFilter !== '';
$matchesProductFilters = static function (array $product) use ($search, $categoryFilter, $unitFilter): bool {
    return ($search === '' || stripos((string)$product['nama'], $search) !== false)
        && ($categoryFilter === '' || (string)$product['kategori'] === $categoryFilter)
        && ($unitFilter === '' || (string)$product['satuan'] === $unitFilter);
};
$filteredPromos = array_values(array_filter($rows, static function (array $promo) use ($matchesProductFilters, $hasProductFilters): bool {
    if ($promo['nama_produk'] === null) return !$hasProductFilters;
    return $matchesProductFilters([
        'nama' => $promo['nama_produk'],
        'kategori' => $promo['kategori_produk'],
        'satuan' => $promo['satuan']
    ]);
}));
$filteredProducts = array_values(array_filter($products, $matchesProductFilters));
$promoCount = count($filteredPromos);
$productCount = count($filteredProducts);
$selectedPromoProductName = '';
foreach ($products as $product) {
    if ((string)$product['id'] === (string)($formValues['product_id'] ?? '')) {
        $selectedPromoProductName = $product['nama'];
        break;
    }
}
$formatQuantity = static fn($value): string => rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
$discountedPromoPrice = static function (float $price, array $promo): float {
    $discount = $promo['jenis'] === 'Persentase'
        ? $price * min(100, (float)$promo['nilai']) / 100
        : (float)$promo['nilai'];
    return round(max(0, $price - $discount), 2);
};
require 'includes/header.php'; ?>
<?php if ($formError !== ''): ?><div class="alert error"><?= e($formError) ?></div><?php endif; ?>
<?php if ($actionNotice !== ''): ?><div class="alert <?= ($_GET['notice'] ?? '')==='delete_failed'?'error':'info' ?>"><?= e($actionNotice) ?></div><?php endif; ?>
<div class="promos-page"><form method="get" class="toolbar filters products-filter-form shared-promo-filters" id="shared-promo-filters"><div><label for="shared-promo-search">Cari produk</label><input id="shared-promo-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama barang"></div><div><label for="shared-promo-category">Kategori</label><select id="shared-promo-category" name="kategori"><option value="">Semua Kategori</option><?php foreach($categories as $category): ?><option value="<?= e($category) ?>" <?= $categoryFilter===$category?'selected':'' ?>><?= e($category) ?></option><?php endforeach; ?></select></div><div><label for="shared-promo-unit">Satuan</label><select id="shared-promo-unit" name="satuan"><option value="">Semua Satuan</option><?php foreach($units as $unit): ?><option value="<?= e($unit) ?>" <?= $unitFilter===$unit?'selected':'' ?>><?= e(strtoupper($unit)) ?></option><?php endforeach; ?></select></div><?php if($search!==''||$categoryFilter!==''||$unitFilter!==''): ?><a class="btn light" href="promos.php">Reset</a><?php endif; ?></form><section class="panel" id="daftar-promo"><div class="panel-head"><h3>Daftar Promo</h3><span class="muted"><?= $promoCount ?> promo</span></div><div class="table-wrap"><table><thead><tr><th>Produk</th><th>Nilai</th><th>Harga Setelah Promo</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($filteredPromos as $r): ?><tr><td><?= e($r['nama_produk']??'Produk tidak tersedia') ?></td><td><?= e($r['jenis'])==='Persentase'?e($r['nilai']).'%':rupiah($r['nilai']) ?></td><td class="promo-result-price"><?php if($r['nama_produk']===null): ?>—<?php else: ?><?php $isBulkPromo=in_array($r['satuan'],['pack','dus','sachet','box'],true); ?><span><del><?= rupiah($r['harga_produk']) ?></del> <strong><?= rupiah($discountedPromoPrice((float)$r['harga_produk'],$r)) ?></strong><?php if($isBulkPromo): ?> / <?= e($r['satuan_isi']??'pcs') ?><?php endif; ?></span><?php if($isBulkPromo): ?><span><del><?= rupiah($r['harga_pack_produk']) ?></del> <strong><?= rupiah($discountedPromoPrice((float)$r['harga_pack_produk'],$r)) ?></strong> / <?= e($r['satuan']) ?></span><?php endif; ?><?php endif; ?></td><td><span class="badge <?= $r['status']==='Aktif'?'success':'warning' ?>"><?= e($r['status']) ?></span></td><td class="actions"><div class="product-action-card"><button class="product-action-button edit edit-promo" type="button" data-promo='<?= e(json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>'><span aria-hidden="true">✎</span> Edit</button><button class="product-action-button delete open-promo-delete" type="button" data-promo-id="<?= (int)$r['id'] ?>" data-promo-name="<?= e($r['nama']) ?>"><span aria-hidden="true">⌫</span> Hapus</button></div></td></tr><?php endforeach; ?><?php if(!$filteredPromos): ?><tr><td colspan="5" class="empty"><?= $rows?'Tidak ada promo yang cocok dengan pencarian atau filter.':'Belum ada promo.' ?></td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel" id="data-barang"><div class="panel-head"><h3>Data Barang</h3><span class="muted"><?= $productCount ?> produk</span></div><div class="table-wrap"><table><thead><tr><th>Nama Produk</th><th>Kategori</th><th>Harga Beli (Total Stok)</th><th>Harga Beli per Stok</th><th>Harga Jual</th><th>Stok</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($filteredProducts as $product): $unit=$product['satuan']??'pcs'; $isBulk=in_array($unit,['pack','dus','sachet','box'],true); $packSize=max(1,(int)($product['isi_per_pack']??1)); $stockQuantity=(float)$product['stok']; $fullPacks=$isBulk?intdiv((int)$product['stok'],$packSize):0; $looseStock=$isBulk?(int)$product['stok']%$packSize:(int)$product['stok']; $legacyModalTotal=$unit==='kg'?(float)($product['harga_beli']??0):(float)($product['harga_beli']??0)*$stockQuantity; $purchaseTotal=$product['modal_total']??$legacyModalTotal; $purchasePerStock=$stockQuantity>0?(float)$purchaseTotal/$stockQuantity:null; ?>
<tr><td><?= e($product['nama']) ?></td><td><?= e($product['kategori']) ?></td><td><?= rupiah($purchaseTotal) ?></td><td><?= $purchasePerStock===null?'—':rupiah($purchasePerStock) ?><?php if($purchasePerStock!==null): ?> / <?= e($unit) ?><?php endif; ?></td><td><?= rupiah($product['harga']) ?><?php if($isBulk): ?><small> / <?= e($product['satuan_isi']??'pcs') ?></small><?php endif; ?></td><td><?php if($isBulk): ?><?= $fullPacks ?> <?= e($unit) ?><?php if($looseStock>0): ?> + <?= $looseStock ?> <?= e($product['satuan_isi']??'pcs') ?><?php endif; ?><?php else: ?><?= $unit==='kg'?$formatQuantity($product['stok']):(int)$product['stok'] ?> <?= e($unit) ?><?php endif; ?></td><td><button class="btn blue add-product-promo" type="button" data-product-id="<?= (int)$product['id'] ?>" data-product-name="<?= e($product['nama']) ?>">Tambah Promo</button></td></tr>
<?php endforeach; ?><?php if(!$filteredProducts): ?><tr><td colspan="7" class="empty"><?= $products?'Tidak ada barang yang cocok dengan pencarian atau filter.':'Data produk belum tersedia.' ?></td></tr><?php endif; ?>
</tbody></table></div></section></div>
<dialog class="product-dialog promo-delete-dialog" id="delete-promo-dialog" aria-labelledby="delete-promo-title"><section class="panel"><div class="panel-head"><h3 id="delete-promo-title">Konfirmasi Hapus Promo</h3></div><p>Yakin ingin menghapus promo <strong id="delete-promo-name"></strong>?</p><form method="post" id="delete-promo-form" class="form-actions"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delete-promo-id"><button class="btn delete-confirm" type="submit">Ya, Hapus</button><button class="btn light" type="button" id="cancel-delete-promo">Batal</button></form></section></dialog>
<dialog class="product-dialog" id="product-promo-dialog"><section class="panel"><div class="panel-head"><h3 id="promo-dialog-title">Tambah Promo Produk</h3><button class="link" type="button" id="close-product-promo" aria-label="Tutup">✕</button></div><form method="post" class="form-grid"><input type="hidden" id="promo-action" name="action" value="create"><input type="hidden" id="promo-id" name="id" value="<?= e($formValues['id']??'') ?>"><input type="hidden" id="promo-product-id" name="product_id" value="<?= e($formValues['product_id']??'') ?>"><div><label for="promo-product-name">Produk</label><input id="promo-product-name" type="text" readonly value="<?= e($selectedPromoProductName) ?>"></div><div><label for="promo-type">Jenis Diskon</label><select id="promo-type" name="jenis" required><option value="Persentase" <?= ($formValues['jenis']??'')==='Persentase'?'selected':'' ?>>Persentase</option><option value="Nominal" <?= ($formValues['jenis']??'')==='Nominal'?'selected':'' ?>>Nominal</option></select></div><div><label for="promo-value">Nilai Diskon</label><input id="promo-value" type="number" name="nilai" min="0.01" max="<?= ($formValues['jenis']??'Persentase')==='Persentase'?'100':'9999999999.99' ?>" step="0.01" required value="<?= e($formValues['nilai']??'') ?>"></div><div><label for="promo-status">Status</label><select id="promo-status" name="status"><option value="Aktif" <?= ($formValues['status']??'Aktif')==='Aktif'?'selected':'' ?>>Aktif</option><option value="Nonaktif" <?= ($formValues['status']??'Aktif')==='Nonaktif'?'selected':'' ?>>Nonaktif</option></select></div><div class="form-actions"><button class="btn gold" id="save-promo-button" type="submit">Simpan Promo</button><button class="btn light" type="button" id="cancel-product-promo">Batal</button></div></form></section></dialog>
<script>
const sharedPromoFilters = document.getElementById('shared-promo-filters');
['shared-promo-category', 'shared-promo-unit'].forEach((id) => {
    document.getElementById(id).addEventListener('change', () => sharedPromoFilters.requestSubmit());
});
const deletePromoDialog = document.getElementById('delete-promo-dialog');
document.querySelectorAll('.open-promo-delete').forEach((button) => button.addEventListener('click', () => {
    document.getElementById('delete-promo-id').value = button.dataset.promoId;
    document.getElementById('delete-promo-name').textContent = button.dataset.promoName;
    deletePromoDialog.showModal();
}));
document.getElementById('cancel-delete-promo').addEventListener('click', () => deletePromoDialog.close());
deletePromoDialog.addEventListener('click', (event) => {
    if (event.target === deletePromoDialog) deletePromoDialog.close();
});
const promoDialog = document.getElementById('product-promo-dialog');
const promoProductId = document.getElementById('promo-product-id');
const promoProductName = document.getElementById('promo-product-name');
const promoForm = promoDialog.querySelector('form');
const promoAction = document.getElementById('promo-action');
const promoId = document.getElementById('promo-id');
const promoTitle = document.getElementById('promo-dialog-title');
const promoSaveButton = document.getElementById('save-promo-button');
document.querySelectorAll('.add-product-promo').forEach((button) => button.addEventListener('click', () => {
    promoForm.reset();
    promoAction.value = 'create';
    promoId.value = '';
    promoProductId.value = button.dataset.productId;
    promoProductName.value = button.dataset.productName;
    promoTitle.textContent = 'Tambah Promo Produk';
    promoSaveButton.textContent = 'Simpan Promo';
    promoDialog.showModal();
}));
document.querySelectorAll('.edit-promo').forEach((button) => button.addEventListener('click', () => {
    const promo = JSON.parse(button.dataset.promo);
    promoForm.reset();
    promoAction.value = 'update';
    promoId.value = promo.id;
    promoProductId.value = promo.product_id || '';
    promoProductName.value = promo.nama_produk || 'Produk tidak tersedia';
    promoForm.elements.jenis.value = promo.jenis;
    promoForm.elements.nilai.value = promo.nilai;
    promoForm.elements.status.value = promo.status;
    document.getElementById('promo-value').max = promo.jenis === 'Persentase' ? '100' : '9999999999.99';
    promoTitle.textContent = 'Edit Promo';
    promoSaveButton.textContent = 'Simpan Perubahan';
    promoDialog.showModal();
}));
document.getElementById('close-product-promo').addEventListener('click', () => promoDialog.close());
document.getElementById('cancel-product-promo').addEventListener('click', () => promoDialog.close());
document.getElementById('promo-type').addEventListener('change', (event) => {
    document.getElementById('promo-value').max = event.target.value === 'Persentase' ? '100' : '9999999999.99';
});
<?php if ($formError !== ''): ?>
promoAction.value = <?= json_encode(($formValues['action'] ?? '') === 'update' ? 'update' : 'create') ?>;
promoId.value = <?= json_encode((string)($formValues['id'] ?? '')) ?>;
promoProductId.value = <?= json_encode((string)($formValues['product_id'] ?? '')) ?>;
promoForm.elements.jenis.value = <?= json_encode((string)($formValues['jenis'] ?? 'Persentase')) ?>;
promoForm.elements.nilai.value = <?= json_encode((string)($formValues['nilai'] ?? '')) ?>;
promoForm.elements.status.value = <?= json_encode((string)($formValues['status'] ?? 'Aktif')) ?>;
promoTitle.textContent = promoAction.value === 'update' ? 'Edit Promo' : 'Tambah Promo Produk';
promoSaveButton.textContent = promoAction.value === 'update' ? 'Simpan Perubahan' : 'Simpan Promo';
promoProductName.value = <?= json_encode($selectedPromoProductName) ?>;
promoDialog.showModal();
<?php endif; ?>
</script>
<?php require 'includes/footer.php'; ?>
