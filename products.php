<?php
require 'config.php'; wajib_login();
$formError = '';
$formValues = [];
$actionNotice = $_SERVER['REQUEST_METHOD'] === 'GET' ? ([
    'created' => 'Barang berhasil ditambahkan.',
    'updated' => 'Barang berhasil diperbarui.',
    'deleted' => 'Barang berhasil dihapus.',
    'bulk_deleted' => 'Barang terpilih berhasil dihapus.',
    'delete_failed' => 'Barang tidak ditemukan atau gagal dihapus.'
][$_GET['notice'] ?? ''] ?? '') : '';
$hasUnitColumn = false;
if ($_SESSION['user']['role']==='admin') {
    $imageColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'gambar'");
    $imageColumn->execute();
    if (!(int)$imageColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN gambar VARCHAR(255) NULL AFTER stok");
    }
    $unitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'satuan'");
    $unitColumn->execute();
    if (!(int)$unitColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN satuan VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER stok");
    }
    $purchasePriceColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'harga_beli'");
    $purchasePriceColumn->execute();
    if (!(int)$purchasePriceColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER kode");
    }
    $stockCapitalColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'modal_total'");
    $stockCapitalColumn->execute();
    if (!(int)$stockCapitalColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN modal_total DECIMAL(12,2) NULL AFTER harga_beli");
    }
    $barcodeColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'barcode'");
    $barcodeColumn->execute();
    if (!(int)$barcodeColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL AFTER kode");
    }
    $barcodePcsColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'barcode_pcs'");
    $barcodePcsColumn->execute();
    if (!(int)$barcodePcsColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN barcode_pcs VARCHAR(100) NULL AFTER barcode");
    }
    $packPriceColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'harga_pack'");
    $packPriceColumn->execute();
    if (!(int)$packPriceColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN harga_pack DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER harga");
        $pdo->exec("UPDATE products SET harga_pack = harga WHERE satuan = 'pack'");
    }
    $packSizeColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'isi_per_pack'");
    $packSizeColumn->execute();
    if (!(int)$packSizeColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN isi_per_pack INT NOT NULL DEFAULT 1 AFTER harga_pack");
    }
    $packUnitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'satuan_isi'");
    $packUnitColumn->execute();
    if (!(int)$packUnitColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN satuan_isi VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER isi_per_pack");
    }
    $stockType = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'stok'");
    $stockType->execute();
    if (in_array(strtolower((string)$stockType->fetchColumn()), ['int','bigint','smallint','mediumint','tinyint'], true)) {
        $pdo->exec("ALTER TABLE products MODIFY stok DECIMAL(12,3) NOT NULL DEFAULT 0");
    }
    $quantityType = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'qty'");
    $quantityType->execute();
    if (in_array(strtolower((string)$quantityType->fetchColumn()), ['int','bigint','smallint','mediumint','tinyint'], true)) {
        $pdo->exec("ALTER TABLE transaction_items MODIFY qty DECIMAL(12,3) NOT NULL");
    }
    $pdo->exec("UPDATE products SET satuan='galon' WHERE satuan='liter'");
    $transactionUnitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'satuan'");
    $transactionUnitColumn->execute();
    if (!(int)$transactionUnitColumn->fetchColumn()) {
        $pdo->exec("ALTER TABLE transaction_items ADD COLUMN satuan VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER qty");
        $pdo->exec("UPDATE transaction_items ti JOIN products p ON p.id = ti.product_id SET ti.satuan = p.satuan");
    }
    $hasUnitColumn = true;
} else {
    $unitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'satuan'");
    $unitColumn->execute();
    $hasUnitColumn = (bool)$unitColumn->fetchColumn();
}
$nextProductCode = static function () use ($pdo): string {
    $codes = $pdo->query("SELECT kode FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $highestNumber = 0;
    foreach ($codes as $existingCode) {
        if (preg_match('/^BRG(\d+)$/i', $existingCode, $matches)) {
            $highestNumber = max($highestNumber, (int)$matches[1]);
        }
    }
    do {
        $code = 'BRG' . str_pad((string)++$highestNumber, 3, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT 1 FROM products WHERE kode=?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
};
$categories=$pdo->query("SELECT DISTINCT kategori FROM products ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    hanya_admin();
    $action = $_POST['action'] ?? '';
    if ($action==='delete') { $stmt=$pdo->prepare("DELETE FROM products WHERE id=?"); $stmt->execute([(int)$_POST['id']]); header('Location: products.php?notice='.($stmt->rowCount()?'deleted':'delete_failed')); exit; }
    elseif ($action==='bulk_delete') {
        $submittedIds=is_array($_POST['ids']??null)?$_POST['ids']:[];
        $ids=array_values(array_unique(array_filter(array_map(
            static fn($id)=>is_scalar($id)?filter_var($id,FILTER_VALIDATE_INT):false,
            $submittedIds
        ),static fn($id)=>$id!==false&&$id>0)));
        if(!$ids){
            header('Location: products.php?notice=delete_failed'); exit;
        }
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("DELETE FROM products WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        header('Location: products.php?notice='.($stmt->rowCount()?'bulk_deleted':'delete_failed')); exit;
    }
    elseif ($action==='save') {
        $id=(int)($_POST['id']??0);
        if($id){
            $codeStatement=$pdo->prepare("SELECT kode FROM products WHERE id=?");
            $codeStatement->execute([$id]);
            $kode=$codeStatement->fetchColumn();
            if($kode===false)$kode='';
        }else{
            $kode=$nextProductCode();
        }
        $nama=trim($_POST['nama']); $kategoriPilihan=trim($_POST['kategori']??''); $kategori=$kategoriPilihan==='__kategori_baru__'?trim($_POST['kategori_baru']??''):$kategoriPilihan; $modalTotalRaw=is_scalar($_POST['modal_total']??null)?$_POST['modal_total']:''; $modalTotal=(float)$modalTotalRaw; $hargaRaw=is_scalar($_POST['harga']??null)?$_POST['harga']:''; $harga=(float)$hargaRaw; $stockRaw=is_scalar($_POST['stok']??null)?$_POST['stok']:''; $satuan=is_string($_POST['satuan']??null)?$_POST['satuan']:''; $barcodeUnits=['pcs','pack','dus','botol','sachet']; $barcode=in_array($satuan,$barcodeUnits,true)&&is_string($_POST['barcode']??null)?trim($_POST['barcode']):''; $barcodePcsUnits=['pack','dus','sachet']; $barcodePcs=in_array($satuan,$barcodePcsUnits,true)&&is_string($_POST['barcode_pcs']??null)?trim($_POST['barcode_pcs']):''; $isKg=$satuan==='kg'; $isPack=in_array($satuan,['pack','dus','sachet','box'],true); $packSizeRaw=is_scalar($_POST['isi_per_pack']??null)?trim((string)$_POST['isi_per_pack']):''; $isiPerPack=$isPack&&preg_match('/^\d+$/',$packSizeRaw)?(int)$packSizeRaw:($isPack?0:1); $satuanIsi=$isPack?$satuan:'pcs'; $hargaPack=$isPack?(float)($_POST['harga_pack']??0):0; $packCountRaw=is_scalar($_POST['stok_pack']??null)?trim((string)$_POST['stok_pack']):''; $looseCountRaw=is_scalar($_POST['stok_lepas']??null)?trim((string)$_POST['stok_lepas']):''; $stokPack=$isPack&&preg_match('/^\d+$/',$packCountRaw)?(int)$packCountRaw:($isPack?-1:0); $stokLepas=$isPack&&$looseCountRaw===''?0:($isPack&&preg_match('/^\d+$/',$looseCountRaw)?(int)$looseCountRaw:($isPack?-1:0)); $stockInPieces=$stokPack*$isiPerPack+$stokLepas;
        $stok=$isPack?$stockInPieces:($isKg?(float)$stockRaw:(int)$stockRaw);
        $formValues=$_POST;
        $allowedUnits=['pcs','pack','dus','kg','galon','botol','sachet','box'];
        if($kategori==='')$formError='Kategori barang wajib diisi.';
        if(!in_array($satuan,$allowedUnits,true))$formError='Pilih satuan stok yang tersedia.';
        if($formError===''&&(!is_numeric($modalTotalRaw)||!is_finite($modalTotal)||$modalTotal<0))$formError='Masukkan total modal stok berupa angka yang valid dan tidak kurang dari 0.';
        if($formError===''&&$modalTotal>9999999999.99)$formError='Total modal stok melebihi batas yang diizinkan.';
        if($formError===''&&$isKg&&(!is_numeric($stockRaw)||!is_numeric($hargaRaw)||!is_finite($stok)||!is_finite($harga)))$formError='Isi stok kg dan harga jual dengan angka yang valid.';
        if($formError===''&&$isPack&&$isiPerPack<1)$formError='Jumlah isi per '.$satuan.' minimal 1.';
        if($formError===''&&($stok<0||$stokPack<0||$stokLepas<0))$formError='Jumlah stok tidak boleh kurang dari 0.';
        if($formError===''&&$isKg&&(!is_finite($stok)||round($stok,3)!=$stok))$formError='Stok kilogram maksimal tiga angka desimal.';
        if($formError===''&&$isKg&&$harga<0)$formError='Harga jual tidak boleh kurang dari 0.';
        if($formError===''&&$stok>999999999.999)$formError='Jumlah stok melebihi batas yang diizinkan.';
        if($formError===''&&strlen($barcode)>100)$formError='Kode barcode maksimal 100 karakter.';
        if($formError===''&&strlen($barcodePcs)>100)$formError='Kode QR PCS maksimal 100 karakter.';
        if($formError===''&&$kode==='')$formError='Barang tidak ditemukan. Muat ulang halaman lalu coba kembali.';
        $imagePath=null;
        $imageUpload=$_FILES['gambar']??null;
        if($formError===''&&$imageUpload && $imageUpload['error']!==UPLOAD_ERR_NO_FILE){
            if($imageUpload['error']!==UPLOAD_ERR_OK){
                $formError=$imageUpload['error']===UPLOAD_ERR_INI_SIZE||$imageUpload['error']===UPLOAD_ERR_FORM_SIZE?'Ukuran gambar terlalu besar. Maksimal 5 MB.':'Gambar gagal diunggah.';
            }elseif($imageUpload['size']>5*1024*1024){
                $formError='Ukuran gambar terlalu besar. Maksimal 5 MB.';
            }else{
                $fileInfo=finfo_open(FILEINFO_MIME_TYPE);
                $mimeType=$fileInfo?finfo_file($fileInfo,$imageUpload['tmp_name']):false;
                if($fileInfo)finfo_close($fileInfo);
                $allowedTypes=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
                if(!$mimeType||!isset($allowedTypes[$mimeType])){
                    $formError='Format gambar harus JPG, PNG, WEBP, atau GIF.';
                }else{
                    $uploadDirectory=__DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'products';
                    if(!is_dir($uploadDirectory)&&!mkdir($uploadDirectory,0755,true)&&!is_dir($uploadDirectory)){
                        $formError='Folder penyimpanan gambar tidak dapat dibuat.';
                    }else{
                        $filename=bin2hex(random_bytes(16)).'.'.$allowedTypes[$mimeType];
                        $imagePath='uploads/products/'.$filename;
                        if(!move_uploaded_file($imageUpload['tmp_name'],$uploadDirectory.DIRECTORY_SEPARATOR.$filename)){
                            $imagePath=null;
                            $formError='Gambar tidak dapat disimpan. Periksa izin folder uploads.';
                        }
                    }
                }
            }
        }
        if($formError===''){
            if($id){
                if($imagePath){
                    $stmt=$pdo->prepare("UPDATE products SET kode=?,barcode=?,barcode_pcs=?,nama=?,kategori=?,modal_total=?,harga=?,harga_pack=?,isi_per_pack=?,satuan_isi=?,stok=?,satuan=?,gambar=? WHERE id=?");$stmt->execute([$kode,$barcode?:null,$barcodePcs?:null,$nama,$kategori,$modalTotal,$harga,$hargaPack,$isiPerPack,$satuanIsi,$stok,$satuan,$imagePath,$id]);
                }else{
                    $stmt=$pdo->prepare("UPDATE products SET kode=?,barcode=?,barcode_pcs=?,nama=?,kategori=?,modal_total=?,harga=?,harga_pack=?,isi_per_pack=?,satuan_isi=?,stok=?,satuan=? WHERE id=?");$stmt->execute([$kode,$barcode?:null,$barcodePcs?:null,$nama,$kategori,$modalTotal,$harga,$hargaPack,$isiPerPack,$satuanIsi,$stok,$satuan,$id]);
                }
            }else{
                $stmt=$pdo->prepare("INSERT INTO products(kode,barcode,barcode_pcs,nama,kategori,modal_total,harga,harga_pack,isi_per_pack,satuan_isi,stok,satuan,gambar) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");$stmt->execute([$kode,$barcode?:null,$barcodePcs?:null,$nama,$kategori,$modalTotal,$harga,$hargaPack,$isiPerPack,$satuanIsi,$stok,$satuan,$imagePath]);
            }
            header('Location: products.php?notice='.($id?'updated':'created')); exit;
        }
    }
    if($action==='delete'){header('Location: products.php'); exit;}
}
$pageTitle='Kelola Barang';
$contentClass = 'products-page';
$edit=null;
if(isset($_GET['edit']) && $_SESSION['user']['role']==='admin'){ $st=$pdo->prepare("SELECT * FROM products WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }
$q=trim($_GET['q']??'');
$kategoriFilter=trim($_GET['kategori']??'');
$satuanFilter=trim($_GET['satuan']??'');
$units=$hasUnitColumn?$pdo->query("SELECT DISTINCT satuan FROM products ORDER BY satuan")->fetchAll(PDO::FETCH_COLUMN):['pcs'];
$conditions=[];$params=[];
if($q!==''){$conditions[]='nama LIKE ?';$params[]="%$q%";}
if($kategoriFilter!==''){$conditions[]='kategori = ?';$params[]=$kategoriFilter;}
if($satuanFilter!==''&&$hasUnitColumn){$conditions[]='satuan = ?';$params[]=$satuanFilter;}
$sql="SELECT * FROM products".($conditions?' WHERE '.implode(' AND ',$conditions):'')." ORDER BY nama";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$products=$stmt->fetchAll();
if (isset($_GET['live'])) {
    header('Content-Type: application/json; charset=utf-8');
    $liveFields = ['id', 'nama', 'kategori', 'harga', 'harga_pack', 'stok', 'satuan', 'isi_per_pack', 'satuan_isi', 'gambar'];
    if ($_SESSION['user']['role'] === 'admin') {
        $liveFields = array_merge($liveFields, ['harga_beli', 'modal_total']);
    }
    $safeProducts = array_map(static function (array $product) use ($liveFields): array {
        $safeProduct = array_intersect_key($product, array_flip($liveFields));
        if (in_array($product['satuan'] ?? '', ['pack', 'dus', 'sachet', 'box'], true)) {
            $safeProduct['satuan_isi'] = $product['satuan_isi'] ?? $product['satuan'];
        }
        return $safeProduct;
    }, $products);
    echo json_encode($safeProducts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$formUnit=$formValues['satuan']??$edit['satuan']??'pcs';
$formBarcodeEnabled=in_array($formUnit,['pcs','pack','dus','botol','sachet'],true);
$formBarcodePcsEnabled=in_array($formUnit,['pack','dus','sachet'],true);
$formIsBulk=in_array($formUnit,['pack','dus','sachet','box'],true);
$formIsKg=$formUnit==='kg';
$formatQuantity=static fn($value): string => rtrim(rtrim(number_format((float)$value,3,'.',''), '0'), '.');
$formPackSize=max(1,(int)($formValues['isi_per_pack']??$edit['isi_per_pack']??1));
$existingStock=(float)($edit['stok']??0);
$formStandardStock=array_key_exists('stok',$formValues)
    ? $formValues['stok']
    : ($formIsKg?$formatQuantity($existingStock):(string)(int)$existingStock);
$legacyModalTotal=isset($edit['harga_beli'])?((($edit['satuan']??'')==='kg')?(float)$edit['harga_beli']:(float)$edit['harga_beli']*(float)($edit['stok']??0)):0;
$formModalTotal=$formValues['modal_total']??($edit['modal_total']??$legacyModalTotal);
$formPackStock=$formValues['stok_pack']??intdiv($existingStock,$formPackSize);
$formLooseStock=$formValues['stok_lepas']??($existingStock%$formPackSize);
require 'includes/header.php';
?>
<div class="toolbar"><form method="get" class="search-form products-filter-form"><input id="product-search-input" name="q" value="<?= e($q) ?>" placeholder="Cari nama barang..." autocomplete="off"><select name="kategori" aria-label="Filter kategori"><option value="">Semua Kategori</option><?php foreach($categories as $category): ?><option value="<?= e($category) ?>" <?= $kategoriFilter===$category?'selected':'' ?>><?= e($category) ?></option><?php endforeach; ?></select><select name="satuan" aria-label="Filter satuan stok"><option value="">Semua Satuan</option><?php foreach($units as $unit): ?><option value="<?= e($unit) ?>" <?= $satuanFilter===$unit?'selected':'' ?>><?= e(strtoupper($unit)) ?></option><?php endforeach; ?></select><button class="btn blue">Terapkan</button><?php if($q!==''||$kategoriFilter!==''||$satuanFilter!==''): ?><a class="btn light" href="products.php">Reset</a><?php endif; ?></form><?php if($_SESSION['user']['role']==='admin'): ?><div class="product-toolbar-actions"><button class="btn delete-confirm" type="button" id="delete-selected-products" hidden>Hapus pilihan</button><button class="btn gold" type="button" id="open-product-form">＋ Tambah Barang</button></div><?php endif; ?></div>
<?php if($_SESSION['user']['role']==='admin'): ?><template id="barcode-pcs-template"><div class="product-barcode-field" id="product-barcode-pcs-field" <?= $formBarcodePcsEnabled?'':'hidden' ?>><label for="product-barcode-pcs-input">QR Barcode PCS (opsional)</label><div class="barcode-input-row"><input id="product-barcode-pcs-input" name="barcode_pcs" maxlength="100" value="<?= e($formValues['barcode_pcs']??$edit['barcode_pcs']??'') ?>" placeholder="Scan atau ketik QR PCS" autocomplete="off" <?= $formBarcodePcsEnabled?'':'disabled' ?>><button class="btn blue barcode-qr-button" id="scan-qr-pcs-button" type="button" aria-label="Pindai QR PCS dengan kamera" <?= $formBarcodePcsEnabled?'':'disabled' ?>>▦ Pindai QR PCS</button></div></div></template><?php endif; ?>
<?php if($formError!==''): ?><div class="alert error" data-refresh-products="true"><?= e($formError) ?></div><?php endif; ?>
<?php if($actionNotice!==''): ?><div class="alert <?= ($_GET['notice'] ?? '')==='delete_failed'?'error':'info' ?>"><?= e($actionNotice) ?></div><?php endif; ?>
<?php if($_SESSION['user']['role']==='admin'): ?><dialog class="product-dialog" id="product-dialog"><section class="panel"><div class="panel-head"><h3 id="product-form-title"><?= $edit?'Edit Barang':'Tambah Barang' ?></h3><button class="link" type="button" id="close-product-form" aria-label="Tutup">✕</button></div><form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($edit['id']??'') ?>">
<div class="product-image-name"><div><label for="product-image-input">Gambar (opsional)</label><label class="product-image-picker" for="product-image-input"><img id="product-image-preview" src="<?= e($edit['gambar']??'') ?>" alt="Pratinjau gambar barang" <?= empty($edit['gambar'])?'hidden':'' ?>><span class="product-image-placeholder" <?= !empty($edit['gambar'])?'hidden':'' ?>>📦</span><span class="product-image-picker-button">＋ Pilih Gambar</span><input id="product-image-input" type="file" name="gambar" accept="image/jpeg,image/png,image/webp,image/gif"></label><small class="product-image-help">JPG, PNG, WEBP atau GIF · Maks. 5 MB</small></div><div class="product-main-fields"><div><label for="product-name-input">Nama Barang</label><input id="product-name-input" name="nama" required value="<?= e($formValues['nama']??$edit['nama']??'') ?>" placeholder="Nama produk"></div><div><label id="purchase-price-label">Total Modal Seluruh Stok (Rp)</label><input id="purchase-price-input" type="number" min="0" step="0.01" name="modal_total" required value="<?= e($formModalTotal) ?>"></div><div><label id="single-price-label" for="single-price-input"><?= $formIsKg?'Harga Jual per kg (Rp)':'Harga Jual (Rp)' ?></label><input id="single-price-input" type="number" min="0" step="0.01" name="harga" required value="<?= e($formValues['harga']??$edit['harga']??'') ?>"></div><div class="pack-fields" <?= $formIsBulk?'':'hidden' ?>><label for="pack-price-input">Harga Jual per <?= e(strtoupper($formUnit)) ?> (Rp)</label><input id="pack-price-input" type="number" min="0" step="0.01" name="harga_pack" value="<?= e($formValues['harga_pack']??$edit['harga_pack']??'0') ?>"></div><div class="product-barcode-field" id="product-barcode-field" <?= $formBarcodeEnabled?'':'hidden' ?>><label for="product-barcode-input">Barcode Produk (opsional)</label><div class="barcode-input-row"><input id="product-barcode-input" name="barcode" maxlength="100" value="<?= e($formValues['barcode']??$edit['barcode']??'') ?>" placeholder="Klik untuk scan atau ketik barcode" autocomplete="off" <?= $formBarcodeEnabled?'':'disabled' ?>><button class="btn blue barcode-qr-button" id="scan-qr-button" type="button" aria-label="Pindai QR dengan kamera" <?= $formBarcodeEnabled?'':'disabled' ?>>▦ Pindai QR</button></div><p class="barcode-notice" id="barcode-notice" role="status" aria-live="polite" hidden></p><video class="barcode-camera-preview" id="barcode-camera-preview" autoplay playsinline hidden></video></div></div></div>
<div><label for="product-category-select">Kategori</label><select id="product-category-select" name="kategori" required><option value="">Pilih kategori</option><?php foreach($categories as $category): ?><option value="<?= e($category) ?>" <?= ($formValues['kategori']??$edit['kategori']??'')===$category?'selected':'' ?>><?= e($category) ?></option><?php endforeach; ?><option value="__kategori_baru__" <?= ($formValues['kategori']??'')==='__kategori_baru__'?'selected':'' ?>>Kategori baru...</option></select><input id="new-category-input" name="kategori_baru" type="text" placeholder="Ketik kategori baru" value="<?= e($formValues['kategori_baru']??'') ?>" hidden></div><div class="standard-stock-field" <?= $formIsBulk?'hidden':'' ?>><label id="stock-label" for="stock-input"><?= $formIsKg?'Stok (kg)':'Stok' ?></label><input id="stock-input" type="number" min="0" step="<?= $formIsKg?'0.001':'1' ?>" name="stok" value="<?= e($formStandardStock) ?>"></div><div><label for="stock-unit-select">Satuan Stok</label><select id="stock-unit-select" name="satuan" required><?php foreach(['pcs','pack','dus','kg','galon','botol','sachet'] as $unit): ?><option value="<?= e($unit) ?>" <?= $formUnit===$unit?'selected':'' ?>><?= e(strtoupper($unit)) ?></option><?php endforeach; ?></select></div><div class="pack-fields" <?= $formIsBulk?'':'hidden' ?>><label for="pack-count-input">Stok (jumlah <?= e($formUnit) ?>)</label><input id="pack-count-input" type="number" min="0" name="stok_pack" value="<?= e($formPackStock) ?>"></div><div class="pack-fields" <?= $formIsBulk?'':'hidden' ?>><label for="pack-size-input">Jumlah isi per <?= e($formUnit) ?></label><input id="pack-size-input" type="number" min="1" name="isi_per_pack" value="<?= e($formValues['isi_per_pack']??$edit['isi_per_pack']??1) ?>"><small id="stock-pieces-summary" role="status" aria-live="polite"></small></div><div class="pack-fields" <?= $formIsBulk?'':'hidden' ?>><label for="loose-stock-input">Stok sisa (PCS, opsional)</label><input id="loose-stock-input" type="number" min="0" step="1" name="stok_lepas" value="<?= e($formValues['stok_lepas']??$formLooseStock) ?>"></div><div class="pack-fields" hidden><input type="hidden" id="pack-inner-unit-select" name="satuan_isi" value="<?= e($formValues['satuan_isi']??$formUnit) ?>"></div><div class="form-actions"><button class="btn gold">Simpan Barang</button><button class="btn light" type="button" id="cancel-product-form">Batal</button></div></form></section></dialog><?php endif; ?>
<section class="panel"><div class="panel-head"><h3>Daftar Barang</h3><span class="muted" id="product-count"><?= count($products) ?> produk</span></div><div class="table-wrap"><table class="products-table"><thead><tr><th><input id="select-all-products" type="checkbox" aria-label="Pilih semua barang"></th><th>Gambar</th><th>Nama Barang</th><th>Kategori</th><?php if($_SESSION['user']['role']==='admin'): ?><th>Harga Beli (Total Stok)</th><?php endif; ?><th>Harga Jual</th><th>Stok</th><?php if($_SESSION['user']['role']==='admin'): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody id="products-table-body">
<?php foreach($products as $p): $stockUnit=$p['satuan']??'pcs'; $isBulkUnit=in_array($stockUnit,['pack','dus','sachet','box'],true); $packSize=max(1,(int)($p['isi_per_pack']??1)); $stockPieces=(int)$p['stok']; $fullPacks=$isBulkUnit?intdiv($stockPieces,$packSize):0; $loosePieces=$isBulkUnit?$stockPieces%$packSize:0; $legacyModalTotal=($stockUnit==='kg')?(float)($p['harga_beli']??0):(float)($p['harga_beli']??0)*(float)$p['stok']; $stockCapital=$p['modal_total']??$legacyModalTotal; ?><tr><td><input class="product-row-checkbox" type="checkbox" value="<?= (int)$p['id'] ?>" aria-label="Pilih <?= e($p['nama']) ?>"></td><td><?php if(!empty($p['gambar'])): ?><img class="product-thumbnail" src="<?= e($p['gambar']) ?>" alt="<?= e($p['nama']) ?>"><?php else: ?><span class="product-thumbnail-placeholder" aria-label="Tidak ada gambar">📦</span><?php endif; ?></td><td><b><?= e($p['nama']) ?></b></td><td><?= e($p['kategori']) ?></td><?php if($_SESSION['user']['role']==='admin'): ?><td><?= rupiah($stockCapital) ?></td><?php endif; ?><td><?php if($isBulkUnit): ?><div><?= rupiah($p['harga_pack']??0) ?> / <?= e($stockUnit) ?></div><small><?= rupiah($p['harga']) ?> / pcs</small><?php else: ?><?= rupiah($p['harga']) ?> / <?= e($stockUnit) ?><?php endif; ?></td><td><span class="badge <?= $p['stok']<10?'warning':'success' ?>"><?php if($isBulkUnit): ?><?= $stockPieces ?> pcs (<?= $fullPacks ?> <?= e($stockUnit) ?><?php if($loosePieces>0): ?> + sisa <?= $loosePieces ?> pcs<?php endif; ?>)<?php else: ?><?= $stockUnit==='kg'?$formatQuantity($p['stok']):(int)$p['stok'] ?> <?= e($stockUnit) ?><?php endif; ?></span></td><?php if($_SESSION['user']['role']==='admin'): ?><td class="actions"><div class="product-action-card"><a class="product-action-button edit" href="products.php?edit=<?= $p['id'] ?>"><span aria-hidden="true">✎</span> Edit</a><button class="product-action-button delete delete-product-button" type="button" data-product-id="<?= (int)$p['id'] ?>" data-product-name="<?= e($p['nama']) ?>"><span aria-hidden="true">⌫</span> Hapus</button></div></td><?php endif; ?></tr><?php endforeach; ?>
<?php if(!$products): ?><tr><td colspan="<?= $_SESSION['user']['role']==='admin'?8:6 ?>" class="empty">Barang tidak ditemukan.</td></tr><?php endif; ?>
</tbody></table></div></section>
<script>
const productFilterForm = document.querySelector('.products-filter-form');
const productSearchInput = document.getElementById('product-search-input');
const productsTableBody = document.getElementById('products-table-body');
const productCount = document.getElementById('product-count');
const selectAllProducts = document.getElementById('select-all-products');
const deleteSelectedProductsButton = document.getElementById('delete-selected-products');
const isProductAdmin = <?= json_encode($_SESSION['user']['role'] === 'admin') ?>;
const updateSelectAllProducts = () => {
    const rowCheckboxes = Array.from(productsTableBody.querySelectorAll('.product-row-checkbox'));
    const selectedCount = rowCheckboxes.filter((checkbox) => checkbox.checked).length;
    selectAllProducts.checked = rowCheckboxes.length > 0 && selectedCount === rowCheckboxes.length;
    selectAllProducts.indeterminate = selectedCount > 0 && selectedCount < rowCheckboxes.length;
    if (deleteSelectedProductsButton) deleteSelectedProductsButton.hidden = selectedCount === 0;
};
selectAllProducts.addEventListener('change', () => {
    productsTableBody.querySelectorAll('.product-row-checkbox').forEach((checkbox) => {
        checkbox.checked = selectAllProducts.checked;
    });
    selectAllProducts.indeterminate = false;
    updateSelectAllProducts();
});
productsTableBody.addEventListener('change', (event) => {
    if (event.target.matches('.product-row-checkbox')) updateSelectAllProducts();
});
let productSearchTimer;
let productSearchRequest = 0;
const formatProductPrice = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0
}).format(Number(value) || 0);
const appendProductCell = (row, content, className = '') => {
    const cell = document.createElement('td');
    if (className) cell.className = className;
    if (content instanceof Node) cell.append(content);
    else cell.textContent = content;
    row.append(cell);
    return cell;
};
const renderProducts = (products) => {
    productsTableBody.replaceChildren();
    productCount.textContent = `${products.length} produk`;
    if (products.length === 0) {
        const row = document.createElement('tr');
        const empty = appendProductCell(row, 'Barang tidak ditemukan.', 'empty');
        empty.colSpan = isProductAdmin ? 8 : 6;
        selectAllProducts.checked = false;
        selectAllProducts.indeterminate = false;
        productsTableBody.append(row);
        return;
    }
    products.forEach((product) => {
        const row = document.createElement('tr');
        const selectionCell = document.createElement('td');
        const rowCheckbox = document.createElement('input');
        rowCheckbox.className = 'product-row-checkbox';
        rowCheckbox.type = 'checkbox';
        rowCheckbox.value = product.id;
        rowCheckbox.setAttribute('aria-label', `Pilih ${product.nama}`);
        selectionCell.append(rowCheckbox);
        row.append(selectionCell);
        const imageCell = document.createElement('td');
        if (product.gambar) {
            const image = document.createElement('img');
            image.className = 'product-thumbnail';
            image.src = product.gambar;
            image.alt = product.nama;
            imageCell.append(image);
        } else {
            const placeholder = document.createElement('span');
            placeholder.className = 'product-thumbnail-placeholder';
            placeholder.setAttribute('aria-label', 'Tidak ada gambar');
            placeholder.textContent = '📦';
            imageCell.append(placeholder);
        }
        row.append(imageCell);
        const productName = document.createElement('b');
        productName.textContent = product.nama;
        appendProductCell(row, productName);
        appendProductCell(row, product.kategori);

        const unit = product.satuan || 'pcs';
        const isBulk = ['pack', 'dus', 'sachet', 'box'].includes(unit);
        const stock = Number(product.stok) || 0;
        if (isProductAdmin) {
            const legacyPurchaseTotal = unit === 'kg'
                ? Number(product.harga_beli) || 0
                : (Number(product.harga_beli) || 0) * stock;
            appendProductCell(row, formatProductPrice(product.modal_total ?? legacyPurchaseTotal));
        }

        const priceCell = document.createElement('td');
        if (isBulk) {
            const packPrice = document.createElement('div');
            packPrice.textContent = `${formatProductPrice(product.harga_pack)} / ${unit}`;
            const singlePrice = document.createElement('small');
            singlePrice.textContent = `${formatProductPrice(product.harga)} / pcs`;
            priceCell.append(packPrice, singlePrice);
        } else {
            priceCell.textContent = `${formatProductPrice(product.harga)} / ${unit}`;
        }
        row.append(priceCell);

        const stockBadge = document.createElement('span');
        stockBadge.className = `badge ${stock < 10 ? 'warning' : 'success'}`;
        if (isBulk) {
            const packSize = Math.max(1, Number.parseInt(product.isi_per_pack, 10) || 1);
            const wholeStock = Math.trunc(stock);
            const fullPacks = Math.floor(wholeStock / packSize);
            const loosePieces = wholeStock % packSize;
            stockBadge.textContent = `${wholeStock} pcs (${fullPacks} ${unit}${loosePieces > 0 ? `, sisa ${loosePieces} pcs` : ''})`;
        } else {
            const shownStock = unit === 'kg'
                ? stock.toFixed(3).replace(/\.?0+$/, '')
                : Math.trunc(stock);
            stockBadge.textContent = `${shownStock} ${unit}`;
        }
        appendProductCell(row, stockBadge);

        if (isProductAdmin) {
            const actions = document.createElement('td');
            actions.className = 'actions';
            const actionCard = document.createElement('div');
            actionCard.className = 'product-action-card';
            const editLink = document.createElement('a');
            editLink.className = 'product-action-button edit';
            editLink.href = `products.php?edit=${encodeURIComponent(product.id)}`;
            editLink.innerHTML = '<span aria-hidden="true">✎</span> Edit';
            const deleteButton = document.createElement('button');
            deleteButton.className = 'product-action-button delete delete-product-button';
            deleteButton.type = 'button';
            deleteButton.dataset.productId = product.id;
            deleteButton.dataset.productName = product.nama;
            deleteButton.innerHTML = '<span aria-hidden="true">⌫</span> Hapus';
            actionCard.append(editLink, deleteButton);
            actions.append(actionCard);
            row.append(actions);
        }
        productsTableBody.append(row);
    });
    updateSelectAllProducts();
};
const refreshProducts = async () => {
    const currentRequest = ++productSearchRequest;
    const query = new URLSearchParams(new FormData(productFilterForm));
    query.set('live', '1');
    try {
        const response = await fetch(`products.php?${query.toString()}`, {
            headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('Daftar barang tidak dapat diperbarui.');
        const products = await response.json();
        if (currentRequest === productSearchRequest) renderProducts(products);
    } catch (error) {
        if (currentRequest !== productSearchRequest) return;
        productsTableBody.replaceChildren();
        const row = document.createElement('tr');
        const message = appendProductCell(row, 'Daftar barang gagal diperbarui. Silakan coba lagi.', 'empty');
        message.colSpan = isProductAdmin ? 8 : 6;
        productsTableBody.append(row);
    }
};
productSearchInput.addEventListener('input', () => {
    window.clearTimeout(productSearchTimer);
    productSearchTimer = window.setTimeout(refreshProducts, 120);
});
productFilterForm.querySelectorAll('select').forEach((filter) => {
    filter.addEventListener('change', refreshProducts);
});
window.addEventListener('safeproductsrefresh', refreshProducts);
</script>
<?php if($_SESSION['user']['role']==='admin'): ?><dialog class="product-dialog product-delete-dialog" id="delete-product-dialog" aria-labelledby="delete-product-title"><section class="panel"><div class="panel-head"><h3 id="delete-product-title">Konfirmasi Hapus Barang</h3></div><p id="delete-product-prompt">Yakin ingin menghapus barang <strong id="delete-product-name"></strong>?</p><form method="post" id="delete-product-form" class="form-actions"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delete-product-id"><button class="btn delete-confirm" type="submit">Ya, Hapus</button><button class="btn light" type="button" id="cancel-delete-product">Batal</button></form></section></dialog><script>
const productDialog = document.getElementById('product-dialog');
const productImageInput = document.getElementById('product-image-input');
const barcodeInput = document.getElementById('product-barcode-input');
const barcodeField = document.getElementById('product-barcode-field');
const barcodePcsTemplate = document.getElementById('barcode-pcs-template');
const barcodePcsFragment = barcodePcsTemplate.content.cloneNode(true);
document.querySelector('.product-main-fields').append(barcodePcsFragment);
const barcodePcsInput = document.getElementById('product-barcode-pcs-input');
const barcodePcsField = document.getElementById('product-barcode-pcs-field');
const barcodeNotice = document.getElementById('barcode-notice');
const barcodeVideo = document.getElementById('barcode-camera-preview');
const scanQrButton = document.getElementById('scan-qr-button');
const scanQrPcsButton = document.getElementById('scan-qr-pcs-button');
let scannerTimeout = null;
let scannerWaiting = false;
let cameraStream = null;
let cameraScanTimeout = null;
let cameraLimitTimeout = null;
let barcodeDetector = null;
let scannerKeyBuffer = '';
let scannerKeyTimeout = null;
let scannerLastKeyTime = 0;
let scannerInputSnapshot = null;
let scannerTargetInput = barcodeInput;
let cameraTargetInput = barcodeInput;
const showBarcodeNotice = (message, type = 'info') => {
    barcodeNotice.textContent = message;
    barcodeNotice.className = `barcode-notice ${type}`;
    barcodeNotice.hidden = false;
    if (type === 'success' || type === 'error' || type === 'warning') {
        window.showActionNotification(message, type === 'success' ? 'success' : 'error');
    }
};
const stopBarcodeCamera = () => {
    if (cameraScanTimeout !== null) clearTimeout(cameraScanTimeout);
    if (cameraLimitTimeout !== null) clearTimeout(cameraLimitTimeout);
    cameraScanTimeout = null;
    cameraLimitTimeout = null;
    if (cameraStream) cameraStream.getTracks().forEach((track) => track.stop());
    cameraStream = null;
    barcodeDetector = null;
    barcodeVideo.srcObject = null;
    barcodeVideo.hidden = true;
};
const startBarcodeCamera = async (qrOnly = false, targetInput = barcodeInput) => {
    if (targetInput.disabled) return;
    cameraTargetInput = targetInput;
    if (cameraStream) return;
    if (!('BarcodeDetector' in window)) {
        showBarcodeNotice('Scanner belum mengirim kode dan browser tidak mendukung pemindaian kamera. Silakan ketik barcode secara manual.', 'warning');
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        showBarcodeNotice('Scanner belum mengirim kode dan akses kamera tidak tersedia. Buka halaman melalui localhost atau ketik barcode manual.', 'warning');
        return;
    }
    try {
        const supportedFormats = await window.BarcodeDetector.getSupportedFormats();
        const formats = (qrOnly ? ['qr_code'] : ['aztec', 'code_128', 'code_39', 'code_93', 'data_matrix', 'ean_13', 'ean_8', 'itf', 'pdf417', 'qr_code', 'upc_a', 'upc_e']).filter((format) => supportedFormats.includes(format));
        if (formats.length === 0) {
            showBarcodeNotice(qrOnly ? 'Browser tidak mendukung pemindaian QR. Ketik kode secara manual atau gunakan scanner.' : 'Scanner belum mengirim kode dan browser tidak mendukung format barcode kamera. Silakan ketik barcode manual.', 'warning');
            return;
        }
        barcodeDetector = new window.BarcodeDetector({ formats });
        cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        barcodeVideo.srcObject = cameraStream;
        barcodeVideo.hidden = false;
        await barcodeVideo.play();
        showBarcodeNotice(qrOnly ? 'Kamera aktif. Arahkan QR ke kamera.' : 'Scanner tidak mengirim kode. Kamera aktif sebagai alternatif; arahkan barcode ke kamera.', qrOnly ? 'info' : 'warning');
        cameraLimitTimeout = setTimeout(() => {
            stopBarcodeCamera();
            showBarcodeNotice('Kamera dihentikan setelah 60 detik. Klik kolom barcode untuk mencoba lagi atau ketik kode manual.', 'info');
        }, 60000);
        const detectBarcode = async () => {
            if (!cameraStream || !barcodeDetector) return;
            try {
                const detections = await barcodeDetector.detect(barcodeVideo);
                if (!cameraStream || !barcodeDetector) return;
                if (detections.length > 0 && detections[0].rawValue) {
                    cameraTargetInput.value = detections[0].rawValue;
                    cameraTargetInput.dispatchEvent(new Event('input', { bubbles: true }));
                    showBarcodeNotice('Barcode berhasil dibaca dari kamera.', 'success');
                    stopBarcodeCamera();
                    return;
                }
                cameraScanTimeout = setTimeout(detectBarcode, 250);
            } catch (error) {
                stopBarcodeCamera();
                showBarcodeNotice(`Pemindaian kamera gagal: ${error.message || 'terjadi kesalahan.'} Periksa izin kamera atau ketik barcode manual.`, 'error');
            }
        };
        detectBarcode();
    } catch (error) {
        stopBarcodeCamera();
        showBarcodeNotice(`Kamera tidak dapat digunakan: ${error.message || 'akses ditolak.'} Periksa izin kamera atau ketik barcode manual.`, 'error');
    }
};
const finishKeyboardScan = () => {
    if (scannerKeyTimeout !== null) clearTimeout(scannerKeyTimeout);
    scannerKeyTimeout = null;
    if (scannerKeyBuffer.length < 4) {
        scannerKeyBuffer = '';
        scannerInputSnapshot = null;
        return;
    }
    if (scannerInputSnapshot) {
        const { element, value, start, end } = scannerInputSnapshot;
        const expectedValue = value.slice(0, start) + scannerKeyBuffer + value.slice(end);
        if (element.isConnected && element.value === expectedValue) {
            element.value = value;
            element.setSelectionRange(start, end);
            element.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    scannerTargetInput.value = scannerKeyBuffer;
    scannerTargetInput.dispatchEvent(new Event('input', { bubbles: true }));
    scannerWaiting = false;
    if (scannerTimeout !== null) clearTimeout(scannerTimeout);
    scannerTimeout = null;
    if (cameraStream) stopBarcodeCamera();
    showBarcodeNotice('Barcode diterima dari scanner.', 'success');
    scannerKeyBuffer = '';
    scannerInputSnapshot = null;
};
document.addEventListener('keydown', (event) => {
    if (barcodeInput.disabled && barcodePcsInput.disabled) return;
    if (event.ctrlKey || event.altKey || event.metaKey) return;
    if (event.key === 'Enter') {
        if (scannerKeyBuffer.length >= 4 && performance.now() - scannerLastKeyTime <= 100) {
            event.preventDefault();
            event.stopPropagation();
            finishKeyboardScan();
        }
        return;
    }
    if (event.key.length !== 1) return;
    const now = performance.now();
    if (now - scannerLastKeyTime > 50) {
        scannerKeyBuffer = '';
        const activeElement = document.activeElement;
        scannerTargetInput = activeElement === barcodePcsInput && !barcodePcsInput.disabled ? barcodePcsInput : barcodeInput;
        if (scannerTargetInput.disabled) scannerTargetInput = barcodePcsInput;
        const supportsSelection = activeElement instanceof HTMLTextAreaElement
            || (activeElement instanceof HTMLInputElement && ['text', 'search', 'tel', 'url', 'password'].includes(activeElement.type));
        scannerInputSnapshot = supportsSelection
            ? { element: activeElement, value: activeElement.value, start: activeElement.selectionStart ?? activeElement.value.length, end: activeElement.selectionEnd ?? activeElement.value.length }
            : null;
    }
    scannerKeyBuffer += event.key;
    scannerLastKeyTime = now;
    if (scannerKeyBuffer.length > 100) {
        scannerKeyBuffer = '';
        scannerInputSnapshot = null;
        if (scannerKeyTimeout !== null) clearTimeout(scannerKeyTimeout);
        return;
    }
    if (scannerKeyTimeout !== null) clearTimeout(scannerKeyTimeout);
    if (scannerKeyBuffer.length >= 4) scannerKeyTimeout = setTimeout(finishKeyboardScan, 80);
});
scanQrButton.addEventListener('click', () => {
    if (scannerTimeout !== null) clearTimeout(scannerTimeout);
    scannerTimeout = null;
    scannerWaiting = false;
    if (cameraStream) stopBarcodeCamera();
    startBarcodeCamera(true);
});
scanQrPcsButton.addEventListener('click', () => {
    if (scannerTimeout !== null) clearTimeout(scannerTimeout);
    scannerTimeout = null;
    scannerWaiting = false;
    if (cameraStream) stopBarcodeCamera();
    startBarcodeCamera(true, barcodePcsInput);
});
const setupBarcodeInput = (input, qrOnly) => {
input.addEventListener('focus', () => {
    if (input.value !== '' || cameraStream) return;
    if (scannerTimeout !== null) clearTimeout(scannerTimeout);
    scannerWaiting = true;
    showBarcodeNotice('Siap menerima kode dari scanner. Arahkan scanner ke produk.', 'info');
    scannerTimeout = setTimeout(() => {
        scannerWaiting = false;
        startBarcodeCamera(qrOnly, input);
    }, 8000);
});
input.addEventListener('input', () => {
    if (input.value === '') return;
    scannerWaiting = false;
    if (scannerTimeout !== null) clearTimeout(scannerTimeout);
    scannerTimeout = null;
    if (cameraStream) stopBarcodeCamera();
    showBarcodeNotice('Kode diterima.', 'success');
});
};
setupBarcodeInput(barcodeInput, false);
setupBarcodeInput(barcodePcsInput, true);
productDialog.addEventListener('close', () => {
    scannerWaiting = false;
    if (scannerTimeout !== null) clearTimeout(scannerTimeout);
    scannerTimeout = null;
    stopBarcodeCamera();
    if (cameraLimitTimeout !== null) clearTimeout(cameraLimitTimeout);
    cameraLimitTimeout = null;
    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.has('edit')) {
        currentUrl.searchParams.delete('edit');
        window.history.replaceState({}, '', currentUrl);
    }
});
const categorySelect = document.getElementById('product-category-select');
const newCategoryInput = document.getElementById('new-category-input');
const stockUnitSelect = document.getElementById('stock-unit-select');
const standardStockField = document.querySelector('.standard-stock-field');
const stockInput = document.getElementById('stock-input');
const packFields = document.querySelectorAll('.pack-fields');
const singlePriceLabel = document.getElementById('single-price-label');
const singlePriceInput = document.getElementById('single-price-input');
const packPriceInput = document.getElementById('pack-price-input');
const packCountInput = document.getElementById('pack-count-input');
const packSizeInput = document.getElementById('pack-size-input');
const looseStockInput = document.getElementById('loose-stock-input');
const purchasePriceLabel = document.getElementById('purchase-price-label');
const stockLabel = document.getElementById('stock-label');
const stockPiecesSummary = document.getElementById('stock-pieces-summary');
const packInnerUnitInput = document.getElementById('pack-inner-unit-select');
const updateStockPiecesSummary = () => {
    const packCount = Math.max(0, Number.parseInt(packCountInput.value, 10) || 0);
    const packSize = Math.max(0, Number.parseInt(packSizeInput.value, 10) || 0);
    const looseCount = Math.max(0, Number.parseInt(looseStockInput.value, 10) || 0);
    const piecesFromPackages = packCount * packSize;
    const totalPieces = piecesFromPackages + looseCount;
    const packageUnit = stockUnitSelect.value;
    const packageCalculation = `${packCount.toLocaleString('id-ID')} ${packageUnit} × ${packSize.toLocaleString('id-ID')} pcs`;
    const looseCalculation = looseCount > 0
        ? ` + ${looseCount.toLocaleString('id-ID')} pcs lepas`
        : '';
    stockPiecesSummary.textContent =
        `${packageCalculation}${looseCalculation} = ${totalPieces.toLocaleString('id-ID')} pcs`;
};
const replaceLiterOption = (select) => {
    const selectedValue = select.value;
    const literOption = Array.from(select.options).find((option) => option.value === 'liter');
    if (literOption) literOption.remove();
    if (!Array.from(select.options).some((option) => option.value === 'galon')) {
        select.add(new Option('GALON', 'galon'));
    }
    select.value = selectedValue === 'liter' ? 'galon' : selectedValue;
};
replaceLiterOption(stockUnitSelect);
const togglePackFields = () => {
    const containerUnit = stockUnitSelect.value;
    packInnerUnitInput.value = ['pack', 'dus', 'sachet', 'box'].includes(containerUnit)
        ? containerUnit
        : 'pcs';
    const barcodeEnabled = ['pcs', 'pack', 'dus', 'botol', 'sachet'].includes(containerUnit);
    const isPack = ['pack', 'dus', 'sachet', 'box'].includes(containerUnit);
    const isKg = containerUnit === 'kg';
    barcodeField.hidden = !barcodeEnabled;
    barcodeInput.disabled = !barcodeEnabled;
    scanQrButton.disabled = !barcodeEnabled;
    const barcodePcsEnabled = ['pack', 'dus', 'sachet'].includes(containerUnit);
    barcodePcsField.hidden = !barcodePcsEnabled;
    barcodePcsInput.disabled = !barcodePcsEnabled;
    scanQrPcsButton.disabled = !barcodePcsEnabled;
    if (!barcodeEnabled || (cameraStream && cameraTargetInput.disabled)) {
        scannerWaiting = false;
        if (scannerTimeout !== null) clearTimeout(scannerTimeout);
        scannerTimeout = null;
        stopBarcodeCamera();
    }
    standardStockField.hidden = isPack;
    stockInput.disabled = isPack;
    stockInput.required = !isPack;
    stockInput.step = isKg ? '0.001' : '1';
    stockLabel.textContent = isKg ? 'Stok (kg)' : 'Stok';
    packFields.forEach((field) => {
        field.hidden = !isPack;
        const input = field.querySelector('input, select');
        input.disabled = !isPack;
    });
    packCountInput.required = isPack;
    packSizeInput.required = isPack;
    looseStockInput.required = false;
    singlePriceLabel.textContent = isPack
        ? 'Harga Jual per PCS (Rp)'
        : isKg ? 'Harga Jual per kg (Rp)' : 'Harga Jual (Rp)';
    purchasePriceLabel.textContent = 'Total Modal Seluruh Stok (Rp)';
    packPriceInput.parentElement.querySelector('label').textContent = `Harga Jual per ${containerUnit.toUpperCase()} (Rp)`;
    packCountInput.parentElement.querySelector('label').textContent = `Stok (jumlah ${containerUnit})`;
    packSizeInput.parentElement.querySelector('label').textContent = `Jumlah isi per ${containerUnit}`;
    packPriceInput.parentElement.hidden = !isPack;
    packPriceInput.disabled = !isPack;
    packPriceInput.required = isPack;
};
stockUnitSelect.addEventListener('change', togglePackFields);
packSizeInput.addEventListener('change', updateStockPiecesSummary);
packSizeInput.addEventListener('input', updateStockPiecesSummary);
packCountInput.addEventListener('input', updateStockPiecesSummary);
looseStockInput.addEventListener('input', updateStockPiecesSummary);
looseStockInput.addEventListener('blur', () => {
    if (['pack', 'dus', 'sachet', 'box'].includes(stockUnitSelect.value) && looseStockInput.value.trim() === '') {
        looseStockInput.value = '0';
        updateStockPiecesSummary();
    }
});
togglePackFields();
updateStockPiecesSummary();
productDialog.querySelector('form').addEventListener('submit', () => {
    if (['pack', 'dus', 'sachet', 'box'].includes(stockUnitSelect.value) && looseStockInput.value.trim() === '') {
        looseStockInput.value = '0';
    }
});
const toggleNewCategoryInput = () => {
    const isNewCategory = categorySelect.value === '__kategori_baru__';
    newCategoryInput.hidden = !isNewCategory;
    newCategoryInput.required = isNewCategory;
};
categorySelect.addEventListener('change', toggleNewCategoryInput);
toggleNewCategoryInput();
productImageInput.addEventListener('change', () => {
    const imagePreview = document.getElementById('product-image-preview');
    const imagePlaceholder = document.querySelector('.product-image-placeholder');
    const selectedImage = productImageInput.files[0];
    if (!selectedImage) return;
    imagePreview.src = URL.createObjectURL(selectedImage);
    imagePreview.hidden = false;
    imagePlaceholder.hidden = true;
});
document.getElementById('open-product-form').addEventListener('click', () => {
    const productForm = productDialog.querySelector('form');
    productForm.reset();
    productForm.querySelector('input[name="id"]').value = '';
    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.has('edit')) {
        currentUrl.searchParams.delete('edit');
        window.history.replaceState(window.history.state, '', currentUrl);
    }
    document.getElementById('product-form-title').textContent = 'Tambah Barang';
    document.getElementById('product-name-input').value = '';
    document.getElementById('purchase-price-input').value = '';
    singlePriceInput.value = '';
    packPriceInput.value = '0';
    barcodeInput.value = '';
    barcodePcsInput.value = '';
    categorySelect.value = '';
    newCategoryInput.value = '';
    newCategoryInput.hidden = true;
    stockUnitSelect.value = 'pcs';
    stockInput.value = '0';
    packCountInput.value = '0';
    packSizeInput.value = '1';
    looseStockInput.value = '0';
    packInnerUnitInput.value = 'pcs';
    productImageInput.value = '';
    document.getElementById('product-image-preview').removeAttribute('src');
    document.getElementById('product-image-preview').hidden = true;
    document.querySelector('.product-image-placeholder').hidden = false;
    barcodeNotice.hidden = true;
    barcodeNotice.textContent = '';
    togglePackFields();
    toggleNewCategoryInput();
    updateStockPiecesSummary();
    productDialog.showModal();
});
document.getElementById('close-product-form').addEventListener('click', () => productDialog.close());
document.getElementById('cancel-product-form').addEventListener('click', () => productDialog.close());
const deleteProductDialog = document.getElementById('delete-product-dialog');
const deleteProductForm = document.getElementById('delete-product-form');
const deleteProductAction = deleteProductForm.querySelector('[name="action"]');
const deleteProductId = document.getElementById('delete-product-id');
const deleteProductPrompt = document.getElementById('delete-product-prompt');
const deleteProductName = document.getElementById('delete-product-name');
const resetBulkDeleteFields = () => deleteProductForm.querySelectorAll('[name="ids[]"]').forEach((field) => field.remove());
document.getElementById('delete-selected-products').addEventListener('click', () => {
    const selectedRows = Array.from(productsTableBody.querySelectorAll('.product-row-checkbox:checked'));
    if (!selectedRows.length) return;
    resetBulkDeleteFields();
    deleteProductAction.value = 'bulk_delete';
    deleteProductId.disabled = true;
    selectedRows.forEach((checkbox) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = checkbox.value;
        deleteProductForm.append(input);
    });
    deleteProductName.textContent = `${selectedRows.length} barang terpilih`;
    deleteProductPrompt.firstChild.textContent = 'Yakin ingin menghapus ';
    deleteProductDialog.showModal();
});
productsTableBody.addEventListener('click', (event) => {
    const button = event.target.closest('.delete-product-button');
    if (!button) return;
    resetBulkDeleteFields();
    deleteProductAction.value = 'delete';
    deleteProductId.disabled = false;
    deleteProductId.value = button.dataset.productId;
    deleteProductName.textContent = button.dataset.productName;
    deleteProductPrompt.firstChild.textContent = 'Yakin ingin menghapus barang ';
    deleteProductDialog.showModal();
});
document.getElementById('cancel-delete-product').addEventListener('click', () => deleteProductDialog.close());
deleteProductDialog.addEventListener('click', (event) => {
    if (event.target === deleteProductDialog) deleteProductDialog.close();
});
productDialog.addEventListener('click', (event) => {
    if (event.target === productDialog) productDialog.close();
});
<?php if($edit||$formError!==''): ?>productDialog.showModal();<?php endif; ?>
</script><?php endif; ?>
<?php require 'includes/footer.php'; ?>
