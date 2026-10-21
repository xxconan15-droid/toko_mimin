<?php
require 'config.php'; wajib_login();
ensure_transaction_status_column($pdo);
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
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
$promoProductColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promos' AND COLUMN_NAME = 'product_id'");
$promoProductColumn->execute();
if (!(int)$promoProductColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE promos ADD COLUMN product_id INT NULL AFTER id");
}
$transactionUnitColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'satuan'");
$transactionUnitColumn->execute();
if (!(int)$transactionUnitColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE transaction_items ADD COLUMN satuan VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER qty");
    $pdo->exec("UPDATE transaction_items ti JOIN products p ON p.id = ti.product_id SET ti.satuan = p.satuan");
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
$itemCapitalColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'modal_total'");
$itemCapitalColumn->execute();
if (!(int)$itemCapitalColumn->fetchColumn()) {
    $pdo->exec("ALTER TABLE transaction_items ADD COLUMN modal_total DECIMAL(12,2) NULL AFTER total");
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

$parseCartKey = static function ($key): ?array {
    if (preg_match('/^(\d+):(pack|dus|sachet|box|item)$/', (string)$key, $matches)) {
        return [(int)$matches[1], $matches[2]];
    }
    if (ctype_digit((string)$key)) {
        return [(int)$key, 'legacy'];
    }
    return null;
};
$cartKey = static fn(int $id, string $saleUnit): string => $id . ':' . $saleUnit;
$stockUnavailable = false;
$stockUnavailableMessage = 'Stok produk tidak cukup untuk ditambahkan ke keranjang.';
$stockUnavailableProduct = null;
$stockEditProduct = null;
$stockEditError = '';
$showStockEditDialog = false;
$actionNotice = $_SERVER['REQUEST_METHOD'] === 'GET' ? ([
    'added' => 'Barang berhasil ditambahkan ke keranjang.',
    'stock_added' => 'Stok berhasil ditambahkan.',
    'partial_added' => 'Stok terbatas. Sebagian jumlah berhasil ditambahkan ke keranjang.',
    'removed' => 'Barang berhasil dihapus dari keranjang.',
    'cart_cleared' => 'Keranjang berhasil dikosongkan.'
][$_GET['notice'] ?? ''] ?? '') : '';
$discountedUnitPrice = static function (float $basePrice, array $promos): array {
    $percentage = 0.0;
    $nominal = 0.0;
    foreach ($promos as $promo) {
        if ($promo['jenis'] === 'Persentase') $percentage += (float)$promo['nilai'];
        else $nominal += (float)$promo['nilai'];
    }
    $discount = min($basePrice, $basePrice * min(100, $percentage) / 100 + $nominal);
    return [round(max(0, $basePrice - $discount), 2), round($discount, 2)];
};
$promoCache = [];
foreach ($pdo->query("SELECT product_id,jenis,nilai FROM promos WHERE product_id IS NOT NULL AND status='Aktif'")->fetchAll() as $promo) {
    $promoCache[(int)$promo['product_id']][] = $promo;
}
$getProductPromos = static fn(int $productId): array => $promoCache[$productId] ?? [];
$cartBaseQuantity = static function (int $id, array $cart, callable $parseCartKey, string $incomingUnit, string $containerUnit, int $packSize): float {
    $total = 0;
    foreach ($cart as $key => $qty) {
        $parsed = $parseCartKey($key);
        if (!$parsed || $parsed[0] !== $id) continue;
        $unit = $parsed[1] === 'legacy' ? $incomingUnit : $parsed[1];
        $total += (float)$qty * ($unit === $containerUnit ? $packSize : 1);
    }
    return $total;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $id = (int)($_POST['product_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id,nama,stok,satuan,isi_per_pack,satuan_isi FROM products WHERE id=?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        $isKg = $product && $product['satuan'] === 'kg';
        $qtyRaw = $_POST['qty'] ?? '';
        if ($isKg && (!is_scalar($qtyRaw) || !preg_match('/^(?:\d+(?:\.\d{1,3})?|\.\d{1,3})$/', trim((string)$qtyRaw)))) {
            $error = 'Jumlah kg harus berupa angka dengan maksimal tiga angka desimal.';
        }
        $qty = $isKg ? (is_scalar($qtyRaw) ? round((float)$qtyRaw, 3) : 0) : max(1, (int)(is_scalar($qtyRaw) ? $qtyRaw : 1));
        if ($isKg && (!is_finite($qty) || $qty <= 0)) $qty = 0;
        if (!isset($error) && !$product) $error = 'Barang tidak ditemukan atau sudah tidak tersedia.';
        if (!isset($error) && $qty <= 0) $error = 'Jumlah barang harus lebih dari 0.';
        if (!isset($error) && $product && (float)$product['stok'] > 0 && $qty > 0) {
            $containerUnit = $product['satuan'];
            $isBulk = in_array($containerUnit, ['pack', 'dus', 'sachet', 'box'], true);
            $saleUnit = $isBulk && ($_POST['sale_unit'] ?? '') === $containerUnit ? $containerUnit : 'item';
            $packSize = max(1, (int)($product['isi_per_pack'] ?? 1));
            $factor = $saleUnit === $containerUnit ? $packSize : 1;
            $legacySaleUnit = $isBulk ? $containerUnit : 'item';
            $alreadyInCart = $cartBaseQuantity($id, $_SESSION['cart'], $parseCartKey, $legacySaleUnit, $containerUnit, $packSize);
            $available = max(0, (float)$product['stok'] - $alreadyInCart);
            $maxAddable = $isKg ? $available / $factor : floor($available / $factor);
            $addable = min($qty, $maxAddable);
            if ($addable > 0) {
                $key = $cartKey($id, $saleUnit);
                $_SESSION['cart'][$key] = round((float)($_SESSION['cart'][$key] ?? 0) + $addable, 3);
                $actionNotice = $addable < $qty ? 'Stok terbatas. Sebagian jumlah berhasil ditambahkan ke keranjang.' : 'Barang berhasil ditambahkan ke keranjang.';
            } else {
                $error = 'Stok barang tidak mencukupi untuk ditambahkan ke keranjang.';
                $stockUnavailable = true;
                $stockUnavailableProduct = $product;
                $stockUnavailableMessage = 'Stok ' . $product['nama'] . ' tidak cukup untuk ditambahkan ke keranjang.';
            }
        } elseif (!isset($error)) {
            $error = 'Stok barang tidak tersedia.';
            $stockUnavailable = (bool)$product;
            if ($product) {
                $stockUnavailableProduct = $product;
                $stockUnavailableMessage = 'Stok ' . $product['nama'] . ' sedang habis dan tidak dapat ditambahkan ke keranjang.';
            }
        }
    } elseif ($action === 'add_stock') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($_SESSION['user']['role'] !== 'admin') {
            $error = 'Hanya admin yang dapat menambahkan stok.';
        } else {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id=? FOR UPDATE");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if (!$product) {
                $stockEditError = 'Barang tidak ditemukan.';
            } else {
                $stockEditProduct = $product;
                $containerUnit = $product['satuan'];
                $isBulk = in_array($containerUnit, ['pack', 'dus', 'sachet', 'box'], true);
                $quantityRaw = $_POST['stock_add_qty'] ?? '';
                $isKg = $containerUnit === 'kg';
                $quantityPattern = $isKg
                    ? '/^(?:\d+(?:\.\d{1,3})?|\.\d{1,3})$/'
                    : '/^\d+$/';
                if (!is_scalar($quantityRaw) || !preg_match($quantityPattern, trim((string)$quantityRaw))) {
                    $stockEditError = $isKg
                        ? 'Jumlah kg harus berupa angka dengan maksimal tiga angka desimal.'
                        : 'Jumlah stok harus berupa bilangan bulat.';
                } else {
                    $stockAddition = (float)$quantityRaw;
                    if ($isBulk) {
                        $stockAddition *= max(1, (int)($product['isi_per_pack'] ?? 1));
                    }
                    if (!is_finite($stockAddition) || $stockAddition <= 0) {
                        $stockEditError = 'Jumlah stok tambahan harus lebih dari 0.';
                    }
                }

                $purchaseCostRaw = $_POST['stock_add_cost'] ?? '';
                if (
                    $stockEditError === ''
                    && (!is_scalar($purchaseCostRaw) || !preg_match('/^(?:\d+(?:\.\d{1,2})?|\.\d{1,2})$/', trim((string)$purchaseCostRaw)))
                ) {
                    $stockEditError = 'Masukkan total biaya pembelian dengan angka yang valid.';
                } elseif ($stockEditError === '') {
                    $purchaseCost = (float)$purchaseCostRaw;
                    if (!is_finite($purchaseCost) || $purchaseCost <= 0 || $purchaseCost > 9999999999.99) {
                        $stockEditError = 'Total biaya pembelian harus lebih dari 0 dan tidak melebihi batas yang diizinkan.';
                    }
                }
                if ($stockEditError === '' && (float)$product['stok'] + $stockAddition > 999999999.999) {
                    $stockEditError = 'Jumlah stok melebihi batas yang diizinkan.';
                }
                $currentCapital = $product['modal_total'] !== null
                    ? (float)$product['modal_total']
                    : (float)($product['harga_beli'] ?? 0) * (float)$product['stok'];
                if ($stockEditError === '' && $currentCapital + $purchaseCost > 9999999999.99) {
                    $stockEditError = 'Total modal stok melebihi batas yang diizinkan.';
                }
            }
            if ($stockEditError === '') {
                $stmt = $pdo->prepare("UPDATE products SET modal_total=?, stok=stok+? WHERE id=?");
                $stmt->execute([$currentCapital + $purchaseCost, $stockAddition, $id]);
                $pdo->commit();
                $actionNotice = 'Stok ' . $product['nama'] . ' berhasil ditambahkan.';
            } else {
                $pdo->rollBack();
                $error = $stockEditError;
                $showStockEditDialog = true;
            }
        }
    } elseif ($action === 'unit') {
        $id = (int)($_POST['product_id'] ?? 0);
        $oldCartKey = is_string($_POST['current_cart_key'] ?? null) ? $_POST['current_cart_key'] : '';
        $oldParsedKey = $parseCartKey($oldCartKey);
        $requestedSaleUnit = (string)($_POST['sale_unit'] ?? '');
        $stmt = $pdo->prepare("SELECT stok,satuan,isi_per_pack FROM products WHERE id=?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product || !$oldParsedKey || $oldParsedKey[0] !== $id || !isset($_SESSION['cart'][$oldCartKey])) {
            $error = 'Barang tidak ditemukan di keranjang.';
        } else {
            $containerUnit = $product['satuan'];
            $isBulk = in_array($containerUnit, ['pack', 'dus', 'sachet', 'box'], true);
            $newSaleUnit = $isBulk && $requestedSaleUnit === $containerUnit ? $containerUnit : 'item';
            $oldKey = $oldCartKey;
            $newKey = $cartKey($id, $newSaleUnit);
            if (!isset($_SESSION['cart'][$oldKey])) {
                $error = 'Barang tidak ditemukan di keranjang.';
            } else {
                $qty = (float)$_SESSION['cart'][$oldKey];
                $existingTargetQty = $oldKey === $newKey ? 0 : (float)($_SESSION['cart'][$newKey] ?? 0);
                $packSize = max(1, (int)($product['isi_per_pack'] ?? 1));
                $reservedByOtherItems = 0;
                foreach ($_SESSION['cart'] as $otherKey => $otherQty) {
                    $parsed = $parseCartKey($otherKey);
                    if (!$parsed || $parsed[0] !== $id || $otherKey === $oldKey || $otherKey === $newKey) continue;
                    $otherUnit = $parsed[1] === 'legacy' ? ($isBulk ? $containerUnit : 'item') : $parsed[1];
                    $reservedByOtherItems += (float)$otherQty * ($otherUnit === $containerUnit ? $packSize : 1);
                }
                $factor = $newSaleUnit === $containerUnit ? $packSize : 1;
                $availableQty = max(0, (float)$product['stok'] - $reservedByOtherItems) / $factor;
                $maxQty = $product['satuan'] === 'kg' ? round($availableQty, 3) : floor($availableQty);
                $newQty = min($qty + $existingTargetQty, $maxQty);
                if ($newQty <= 0) {
                    $error = 'Stok tidak cukup untuk menggunakan satuan yang dipilih.';
                } else {
                    unset($_SESSION['cart'][$oldKey]);
                    $_SESSION['cart'][$newKey] = $newQty;
                }
            }
        }
    } elseif ($action === 'qty') {
        $id = (int)($_POST['product_id'] ?? 0);
        $requestedSaleUnit = (string)($_POST['sale_unit'] ?? '');
        $saleUnit = in_array($requestedSaleUnit, ['pack', 'dus', 'sachet', 'box'], true) ? $requestedSaleUnit : 'item';
        $stmt = $pdo->prepare("SELECT stok,satuan,isi_per_pack FROM products WHERE id=?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        $isKg = $product && $product['satuan'] === 'kg';
        $qtyRaw = $_POST['qty'] ?? '';
        if ($isKg && (!is_scalar($qtyRaw) || !preg_match('/^(?:\d+(?:\.\d{1,3})?|\.\d{1,3})$/', trim((string)$qtyRaw)))) {
            $error = 'Jumlah kg harus berupa angka dengan maksimal tiga angka desimal.';
        }
        $qty = $isKg ? (is_scalar($qtyRaw) ? round((float)$qtyRaw, 3) : 0) : (int)(is_scalar($qtyRaw) ? $qtyRaw : 0);
        $key = $cartKey($id, $saleUnit);
        if (!isset($error) && $qty <= 0) {
            unset($_SESSION['cart'][$key]);
            $actionNotice = 'Barang berhasil dihapus dari keranjang.';
        } elseif (!isset($error) && $product && (!$isKg || is_finite($qty))) {
                $containerUnit = $product['satuan'];
                $isBulk = in_array($containerUnit, ['pack', 'dus', 'sachet', 'box'], true);
                $saleUnit = $isBulk && $saleUnit === $containerUnit ? $containerUnit : 'item';
                $key = $cartKey($id, $saleUnit);
                $packSize = max(1, (int)($product['isi_per_pack'] ?? 1));
                $otherCartUnits = 0;
                foreach ($_SESSION['cart'] as $otherKey => $otherQty) {
                    $parsed = $parseCartKey($otherKey);
                    if (!$parsed || $parsed[0] !== $id || $otherKey === $key) continue;
                    $otherUnit = $parsed[1] === 'legacy' ? ($isBulk ? $containerUnit : 'item') : $parsed[1];
                    $otherCartUnits += (float)$otherQty * ($otherUnit === $containerUnit ? $packSize : 1);
                }
                $factor = $saleUnit === $containerUnit ? $packSize : 1;
                $availableQty = max(0, (float)$product['stok'] - $otherCartUnits) / $factor;
                $maxQty = $isKg ? $availableQty : floor($availableQty);
                if ($maxQty > 0) {
                    $_SESSION['cart'][$key] = $isKg ? round(min($qty, $maxQty), 3) : min($qty, $maxQty);
                    $actionNotice = '';
                } else {
                    unset($_SESSION['cart'][$key]);
                    $error = 'Stok barang tidak lagi tersedia; barang dihapus dari keranjang.';
                }
        } elseif (!isset($error)) {
            $error = 'Barang tidak ditemukan atau jumlah tidak valid.';
        }
    } elseif ($action === 'remove') {
        $id = (int)($_POST['product_id'] ?? 0);
        $requestedSaleUnit = (string)($_POST['sale_unit'] ?? '');
        $saleUnit = in_array($requestedSaleUnit, ['pack', 'dus', 'sachet', 'box'], true) ? $requestedSaleUnit : 'item';
        if (isset($_SESSION['cart'][$cartKey($id, $saleUnit)])) {
            unset($_SESSION['cart'][$cartKey($id, $saleUnit)]);
            $actionNotice = 'Barang berhasil dihapus dari keranjang.';
        } else {
            $error = 'Barang tidak ditemukan di keranjang.';
        }
    } elseif ($action === 'clear') {
        if ($_SESSION['cart']) {
            $_SESSION['cart'] = [];
            $actionNotice = 'Keranjang berhasil dikosongkan.';
        } else {
            $error = 'Keranjang sudah kosong.';
        }
    } elseif (in_array($action, ['checkout', 'pending'], true) && $_SESSION['cart']) {
        $isPending = $action === 'pending';
        $method = $isPending ? 'Hutang' : 'Tunai';
        $pdo->beginTransaction();
        try {
            $items = [];
            $productsById = [];
            $reservedByProduct = [];
            $capitalByProduct = [];
            $total = 0;
            foreach ($_SESSION['cart'] as $key => $qty) {
                $parsed = $parseCartKey($key);
                if (!$parsed) continue;
                [$id, $saleUnit] = $parsed;
                if (!isset($productsById[$id])) {
                    $stmt = $pdo->prepare("SELECT * FROM products WHERE id=? FOR UPDATE");
                    $stmt->execute([$id]);
                    $productsById[$id] = $stmt->fetch();
                }
                $product = $productsById[$id];
                if (!$product) throw new Exception('Barang dalam keranjang tidak ditemukan.');
                $containerUnit = $product['satuan'];
                $isBulk = in_array($containerUnit, ['pack', 'dus', 'sachet', 'box'], true);
                $saleUnit = $saleUnit === 'legacy' ? ($isBulk ? $containerUnit : 'item') : $saleUnit;
                if ($saleUnit !== 'item' && (!$isBulk || $saleUnit !== $containerUnit)) {
                    throw new Exception('Pilihan satuan tidak berlaku untuk ' . $product['nama'] . '.');
                }
                $qty = $product['satuan'] === 'kg' ? round((float)$qty, 3) : (int)$qty;
                if (!is_finite((float)$qty) || $qty <= 0) continue;
                $packSize = max(1, (int)($product['isi_per_pack'] ?? 1));
                $factor = $saleUnit === $containerUnit ? $packSize : 1;
                $baseQty = $qty * $factor;
                $reservedByProduct[$id] = (float)($reservedByProduct[$id] ?? 0) + $baseQty;
                $originalPrice = $saleUnit === $containerUnit ? (float)$product['harga_pack'] : (float)$product['harga'];
                [$unitPrice, $discountPerUnit] = $discountedUnitPrice($originalPrice, $getProductPromos($id));
                $saleLabel = $saleUnit === $containerUnit ? $containerUnit : ($isBulk ? 'pcs' : ($product['satuan'] ?? 'pcs'));
                $inventoryCapital = $product['modal_total'] !== null
                    ? max(0, (float)$product['modal_total'])
                    : max(0, (float)($product['harga_beli'] ?? 0)) * max(0, (float)$product['stok']);
                $capitalPerBaseUnit = (float)$product['stok'] > 0
                    ? $inventoryCapital / (float)$product['stok']
                    : max(0, (float)($product['harga_beli'] ?? 0));
                $lineCapital = round($capitalPerBaseUnit * $baseQty, 2);
                $capitalByProduct[$id] = (float)($capitalByProduct[$id] ?? 0) + $lineCapital;
                $items[] = ['p' => $product, 'qty' => $qty, 'base_qty' => $baseQty, 'unit' => $saleLabel, 'price' => $unitPrice, 'original_price' => $originalPrice, 'discount' => $discountPerUnit * $qty, 'capital' => $lineCapital];
                $total += $unitPrice * $qty;
            }
            if (!$items) throw new Exception('Keranjang tidak berisi barang yang dapat diproses.');
            foreach ($reservedByProduct as $id => $reservedQty) {
                if ((float)$productsById[$id]['stok'] + 0.000001 < $reservedQty) {
                    throw new Exception('Stok tidak cukup untuk ' . $productsById[$id]['nama'] . '.');
                }
            }
            $kode = 'TRX-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $status = $isPending ? 'Pending' : 'Lunas';
            $stmt = $pdo->prepare("INSERT INTO transactions(kode,user_id,subtotal,pajak,total,payment_method,status) VALUES(?,?,?,?,?,?,?)");
            $stmt->execute([$kode, $_SESSION['user']['id'], $total, 0, $total, $method, $status]);
            $transactionId = $pdo->lastInsertId();
            foreach ($items as $item) {
                $product = $item['p'];
                $lineTotal = $item['price'] * $item['qty'];
                $stmt = $pdo->prepare("INSERT INTO transaction_items(transaction_id,product_id,nama_produk,qty,satuan,harga,total,modal_total) VALUES(?,?,?,?,?,?,?,?)");
                $stmt->execute([$transactionId, $product['id'], $product['nama'], $item['qty'], $item['unit'], $item['price'], $lineTotal, $item['capital']]);
            }
            $updateStock = $pdo->prepare("UPDATE products SET stok=stok-?, modal_total=CASE WHEN modal_total IS NULL THEN NULL ELSE GREATEST(modal_total-?,0) END WHERE id=?");
            foreach ($reservedByProduct as $id => $reservedQty) {
                $updateStock->execute([$reservedQty, $capitalByProduct[$id] ?? 0, $id]);
            }
            $pdo->commit();
            $_SESSION['cart'] = [];
            if ($isPending) {
                header('Location: receipt.php?id=' . $transactionId . '&saved=1');
            } else {
                header('Location: receipt.php?id=' . $transactionId . '&saved=1');
            }
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    } elseif (in_array($action, ['checkout', 'pending'], true)) {
        $error = 'Keranjang kosong. Tambahkan barang sebelum menyimpan transaksi.';
    } else {
        $error = 'Aksi tidak dikenali.';
    }
    if (!isset($error)) {
        $noticeMap = ['add' => 'added', 'add_stock' => 'stock_added', 'remove' => 'removed', 'clear' => 'cart_cleared'];
        $notice = strpos($actionNotice, 'Stok terbatas.') === 0
            ? ($action === 'add' ? 'partial_added' : 'qty_limited')
            : ($noticeMap[$action] ?? '');
        header('Location: transactions.php' . ($notice !== '' ? '?notice=' . $notice : ''));
        exit;
    }
}

$pageTitle = 'Kasir / Transaksi';
$contentClass = 'transactions-page';
$formatQuantity = static fn($value): string => rtrim(rtrim(number_format((float)$value,3,'.',''), '0'), '.');
$products = $pdo->query("SELECT * FROM products ORDER BY nama")->fetchAll();
$cart = [];
$subtotal = 0;
$totalPromoDiscount = 0;
$productIds = [];
foreach (array_keys($_SESSION['cart']) as $key) {
    $parsed = $parseCartKey($key);
    if ($parsed) $productIds[$parsed[0]] = true;
}
$productsById = [];
if ($productIds) {
    $ids = array_keys($productIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $product) $productsById[$product['id']] = $product;
}
foreach ($_SESSION['cart'] as $key => $qty) {
    $parsed = $parseCartKey($key);
    if (!$parsed) continue;
    [$id, $saleUnit] = $parsed;
    if (!isset($productsById[$id])) continue;
    $product = $productsById[$id];
    $containerUnit = $product['satuan'];
    $isBulk = in_array($containerUnit, ['pack', 'dus', 'sachet', 'box'], true);
    $saleUnit = $saleUnit === 'legacy' ? ($isBulk ? $containerUnit : 'item') : $saleUnit;
    $product['cart_key'] = $key;
    $product['sale_unit'] = $saleUnit;
    $product['sale_label'] = $saleUnit === $containerUnit ? $containerUnit : ($isBulk ? 'pcs' : ($product['satuan'] ?? 'pcs'));
    $product['original_sale_price'] = $saleUnit === $containerUnit ? (float)$product['harga_pack'] : (float)$product['harga'];
    [$product['sale_price'], $product['discount_per_unit']] = $discountedUnitPrice($product['original_sale_price'], $getProductPromos((int)$id));
    $product['qty'] = $product['satuan'] === 'kg' ? round((float)$qty, 3) : (int)$qty;
    $product['line'] = $product['qty'] * $product['sale_price'];
    $product['line_discount'] = $product['qty'] * $product['discount_per_unit'];
    $subtotal += $product['line'];
    $totalPromoDiscount += $product['line_discount'];
    $cart[] = $product;
}
require 'includes/header.php';
?>
<?php if (!empty($error) && !$stockUnavailable && !$showStockEditDialog): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($actionNotice !== ''): ?><div class="alert info"><?= e($actionNotice) ?></div><?php endif; ?>
<div class="pos-layout"><section class="panel"><div class="panel-head"><h3>Pilih Barang</h3><span class="muted">Klik tambah untuk memasukkan ke keranjang</span></div><div class="product-search-row"><input id="filterProduct" class="filter-input" placeholder="Cari produk..." aria-label="Cari produk"><button class="btn light barcode-scan-button" type="button" id="open-barcode-scanner" aria-label="Pindai barcode dengan kamera" title="Pindai barcode dengan kamera"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3h7v7H3V3Zm2 2v3h3V5H5Zm9-2h7v7h-7V3Zm2 2v3h3V5h-3ZM3 14h7v7H3v-7Zm2 2v3h3v-3H5Zm9-2h2v2h-2v-2Zm3 0h3v2h-3v-2Zm-3 4h2v3h-2v-3Zm4-1h2v1h1v2h-3v-3Zm-4-1h1v1h-1v-1Z"/></svg></button></div><div class="product-grid" id="productGrid">
<?php foreach ($products as $product): $packSize=max(1,(int)($product['isi_per_pack']??1)); $containerUnit=$product['satuan']??'pcs'; $isBulk=in_array($containerUnit,['pack','dus','sachet','box'],true); $isKg=$containerUnit==='kg'; $packs=$isBulk?intdiv((int)$product['stok'],$packSize):0; $loose=$isBulk?(int)$product['stok']%$packSize:(int)$product['stok']; $productPromos=$getProductPromos((int)$product['id']); [$salePrice,$saleDiscount]=$discountedUnitPrice((float)$product['harga'],$productPromos); [$packSalePrice,$packSaleDiscount]=$discountedUnitPrice((float)$product['harga_pack'],$productPromos); ?>
<article class="product-card" data-name="<?= e(strtolower($product['nama'])) ?>" data-barcode="<?= e((string)($product['barcode']??'')) ?>" data-barcode-pcs="<?= e((string)($product['barcode_pcs']??'')) ?>"><div class="product-emoji">📦</div><b><?= e($product['nama']) ?></b>
<?php if ($isBulk): ?>
<small>Isi <?= $packSize ?> pcs/<?= e($containerUnit) ?> · Stok <?= $packs ?> <?= e($containerUnit) ?><?php if($loose>0): ?> + <?= $loose ?> pcs<?php endif; ?></small>
<strong><?php if($packSaleDiscount>0): ?><del class="promo-original-price"><?= rupiah($product['harga_pack']) ?></del> <span class="promo-sale-price"><?= rupiah($packSalePrice) ?></span><?php else: ?><?= rupiah($product['harga_pack']) ?><?php endif; ?> / <?= e($containerUnit) ?><br><small><?php if($saleDiscount>0): ?><del class="promo-original-price"><?= rupiah($product['harga']) ?></del> <span class="promo-sale-price"><?= rupiah($salePrice) ?></span><?php else: ?><?= rupiah($product['harga']) ?><?php endif; ?> / pcs</small><?php if($productPromos): ?><small class="promo-active-label">Promo aktif</small><?php endif; ?></strong>
<?php if ((int)$product['stok']>0): ?><form method="post" class="add-form"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><input type="hidden" name="sale_unit" value="item"><input type="number" name="qty" min="1" max="<?= (int)$product['stok'] ?>" value="1"><button class="btn blue">＋ Tambah</button></form><?php else: ?><button type="button" class="btn light stock-unavailable-button" data-product-id="<?= $product['id'] ?>" data-product-name="<?= e($product['nama']) ?>" data-unit="<?= e($containerUnit) ?>" data-inner-unit="pcs" data-pack-size="<?= $packSize ?>">Stok Habis</button><?php endif; ?>
<?php else: ?>
<small>Stok <?= $isKg?$formatQuantity($product['stok']):(int)$product['stok'] ?> <?= e($product['satuan']??'pcs') ?></small><strong><?php if($saleDiscount>0): ?><del class="promo-original-price"><?= rupiah($product['harga']) ?></del> <span class="promo-sale-price"><?= rupiah($salePrice) ?></span><?php else: ?><?= rupiah($product['harga']) ?><?php endif; ?> / <?= e($product['satuan']??'pcs') ?><?php if($productPromos): ?><small class="promo-active-label">Promo aktif</small><?php endif; ?></strong><?php if((float)$product['stok']>0): ?><form method="post" class="add-form"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><input type="hidden" name="sale_unit" value="item"><input type="number" name="qty" min="<?= $isKg?'0.001':'1' ?>" step="<?= $isKg?'0.001':'1' ?>" max="<?= $isKg?$product['stok']:(int)$product['stok'] ?>" value="<?= $isKg?min(1,(float)$product['stok']):1 ?>"><button class="btn blue">＋ Tambah</button></form><?php else: ?><button type="button" class="btn light stock-unavailable-button" data-product-id="<?= $product['id'] ?>" data-product-name="<?= e($product['nama']) ?>" data-unit="<?= e($containerUnit) ?>" data-inner-unit="<?= e($product['satuan_isi']??'pcs') ?>" data-pack-size="<?= $packSize ?>">Stok Habis</button><?php endif; ?>
<?php endif; ?>
</article><?php endforeach; ?>
</div><dialog class="stock-edit-dialog barcode-scan-dialog" id="barcode-scan-dialog" aria-labelledby="barcode-scan-title"><section class="panel"><div class="panel-head"><h3 id="barcode-scan-title">Pindai Barcode Barang</h3><button class="link" type="button" id="close-barcode-scanner" aria-label="Tutup">✕</button></div><video id="barcode-scan-video" autoplay playsinline></video><p id="barcode-scan-status" class="muted" role="status" aria-live="polite">Arahkan kamera ke barcode produk.</p></section></dialog></section>
<dialog class="stock-unavailable-dialog" id="stock-unavailable-dialog" aria-labelledby="stock-unavailable-title" <?php if($stockUnavailableProduct): ?>data-product-id="<?= e($stockUnavailableProduct['id']) ?>" data-product-name="<?= e($stockUnavailableProduct['nama']) ?>" data-unit="<?= e($stockUnavailableProduct['satuan']) ?>" data-inner-unit="pcs" data-pack-size="<?= (int)($stockUnavailableProduct['isi_per_pack']??1) ?>"<?php endif; ?>><section class="panel stock-unavailable-content"><div class="panel-head"><h3 id="stock-unavailable-title">Stok Tidak Tersedia</h3><button class="link" type="button" id="close-stock-unavailable" aria-label="Tutup">✕</button></div><p id="stock-unavailable-message"><?= e($stockUnavailableMessage) ?></p><?php if($_SESSION['user']['role']==='admin'): ?><div class="form-actions"><button class="btn light" type="button" id="dismiss-stock-unavailable">Tutup</button><button class="btn gold" type="button" id="open-stock-edit">＋ Tambah Stok</button></div><?php else: ?><p class="muted">Hubungi administrator untuk menambahkan stok produk ini.</p><div class="form-actions"><button class="btn light" type="button" id="dismiss-stock-unavailable">Tutup</button></div><?php endif; ?></section></dialog>
<?php if($_SESSION['user']['role']==='admin'): ?><dialog class="stock-edit-dialog" id="stock-edit-dialog" aria-labelledby="stock-edit-title"><section class="panel"><div class="panel-head"><h3 id="stock-edit-title">Tambah Stok</h3><button class="link" type="button" id="close-stock-edit" aria-label="Tutup">✕</button></div><p id="stock-edit-product-name" class="muted"></p><?php if($stockEditError!==''): ?><div class="alert error"><?= e($stockEditError) ?></div><?php endif; ?><form method="post" id="stock-edit-form"><input type="hidden" name="action" value="add_stock"><input type="hidden" name="product_id" id="stock-edit-product-id" value="<?= e($stockEditProduct['id'] ?? $_POST['product_id'] ?? '') ?>"><label for="stock-add-qty" id="stock-add-qty-label">Jumlah stok yang ditambahkan</label><input type="number" name="stock_add_qty" id="stock-add-qty" min="1" step="1" value="<?= e($_POST['stock_add_qty'] ?? '') ?>" required><label for="stock-add-cost">Total biaya pembelian stok ini (Rp)</label><input type="number" name="stock_add_cost" id="stock-add-cost" min="0.01" max="9999999999.99" step="0.01" value="<?= e($_POST['stock_add_cost'] ?? '') ?>" required><small>Masukkan total biaya untuk jumlah stok yang ditambahkan di atas.</small><div class="form-actions"><button class="btn gold" type="submit">Simpan Stok</button><button class="btn light" type="button" id="cancel-stock-edit">Batal</button></div></form></section></dialog><?php endif; ?>
<aside class="panel cart-panel"><div class="panel-head"><h3>Keranjang</h3><form method="post"><input type="hidden" name="action" value="clear"><button class="link danger">Hapus Semua</button></form></div>
<?php foreach ($cart as $product): $productIsBulk=in_array($product['satuan'],['pack','dus','sachet','box'],true); $productIsKg=$product['satuan']==='kg'; ?><div class="cart-item"><div><b><?= e($product['nama']) ?></b><small><?php if($product['discount_per_unit']>0): ?><del class="promo-original-price"><?= rupiah($product['original_sale_price']) ?></del> <span class="promo-sale-price"><?= rupiah($product['sale_price']) ?></span><?php else: ?><?= rupiah($product['sale_price']) ?><?php endif; ?> / <?= e($product['sale_label']) ?></small><form method="post" class="qty-form"><input type="hidden" name="action" class="qty-action" value="qty"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><input type="hidden" name="current_cart_key" value="<?= e($product['cart_key']) ?>"><?php if($productIsBulk): ?><select name="sale_unit" class="cart-sale-unit" aria-label="Satuan <?= e($product['nama']) ?>"><option value="item" <?= $product['sale_unit']==='item'?'selected':'' ?>>pcs</option><option value="<?= e($product['satuan']) ?>" <?= $product['sale_unit']===$product['satuan']?'selected':'' ?>><?= e($product['satuan']) ?></option></select><?php else: ?><input type="hidden" name="sale_unit" value="item"><span><?= e($product['sale_label']) ?></span><?php endif; ?><input type="number" name="qty" min="<?= $productIsKg?'0':'0' ?>" step="<?= $productIsKg?'0.001':'1' ?>" value="<?= $productIsKg?$formatQuantity($product['qty']):$product['qty'] ?>"><button class="link danger" form="remove<?= $product['id'].'-'.$product['sale_unit'] ?>">×</button></form><form id="remove<?= $product['id'].'-'.$product['sale_unit'] ?>" method="post"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><input type="hidden" name="sale_unit" value="<?= e($product['sale_unit']) ?>"></form></div><strong><?= rupiah($product['line']) ?></strong></div><?php endforeach; ?>
<?php if (!$cart): ?><div class="empty cart-empty">Keranjang masih kosong.<br>Pilih barang untuk memulai transaksi.</div><?php endif; ?>
<div class="totals"><?php if($totalPromoDiscount>0): ?><div><span>Hemat Promo</span><b class="promo-sale-price">−<?= rupiah($totalPromoDiscount) ?></b></div><?php endif; ?><div><span>Subtotal</span><b><?= rupiah($subtotal) ?></b></div><div class="grand"><span>Total Belanja</span><b><?= rupiah($subtotal) ?></b></div></div>
<form method="post" class="checkout-form checkout-actions"><button type="submit" name="action" value="pending" class="btn light checkout-pending-button" <?= !$cart?'disabled':'' ?>>Pending</button><button type="submit" name="action" value="checkout" class="btn gold full checkout-pay-button" <?= !$cart?'disabled':'' ?>>Bayar</button></form>
</aside></div>
<script>
const stockUnavailableDialog = document.getElementById('stock-unavailable-dialog');
const closeStockUnavailable = () => stockUnavailableDialog.close();
document.querySelectorAll('.stock-unavailable-button').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById('stock-unavailable-message').textContent =
            `${button.dataset.productName} sedang kehabisan stok dan tidak dapat ditambahkan ke keranjang.`;
        stockUnavailableDialog.dataset.productId = button.dataset.productId;
        stockUnavailableDialog.dataset.productName = button.dataset.productName;
        stockUnavailableDialog.dataset.unit = button.dataset.unit;
        stockUnavailableDialog.dataset.innerUnit = button.dataset.innerUnit;
        stockUnavailableDialog.dataset.packSize = button.dataset.packSize;
        stockUnavailableDialog.showModal();
    });
});
document.getElementById('close-stock-unavailable').addEventListener('click', closeStockUnavailable);
document.getElementById('dismiss-stock-unavailable').addEventListener('click', closeStockUnavailable);
<?php if($_SESSION['user']['role']==='admin'): ?>
const stockEditDialog = document.getElementById('stock-edit-dialog');
const openStockEdit = (productId, productName, unit, innerUnit, packSize, preserveValues = false) => {
    document.getElementById('stock-edit-product-id').value = productId;
    document.getElementById('stock-edit-product-name').textContent = `Produk: ${productName}`;
    const isBulk = ['pack', 'dus', 'sachet', 'box'].includes(unit);
    const isKg = unit === 'kg';
    const quantityInput = document.getElementById('stock-add-qty');
    const purchaseCostInput = document.getElementById('stock-add-cost');
    quantityInput.step = isKg ? '0.001' : '1';
    quantityInput.min = isKg ? '0.001' : '1';
    document.getElementById('stock-add-qty-label').textContent =
        `Jumlah stok yang ditambahkan (${unit}${isBulk ? ` · 1 ${unit} = ${packSize} ${innerUnit}` : ''})`;
    if (!preserveValues) {
        quantityInput.value = '';
        purchaseCostInput.value = '';
    }
    stockEditDialog.showModal();
};
document.getElementById('open-stock-edit').addEventListener('click', () => {
    const product = stockUnavailableDialog.dataset;
    if (!product.productId) return;
    stockUnavailableDialog.close();
    openStockEdit(
        product.productId,
        product.productName,
        product.unit,
        product.innerUnit,
        product.packSize
    );
});
document.getElementById('close-stock-edit').addEventListener('click', () => stockEditDialog.close());
document.getElementById('cancel-stock-edit').addEventListener('click', () => stockEditDialog.close());
<?php if ($showStockEditDialog && $stockEditProduct): ?>
openStockEdit(
    <?= json_encode((string)$stockEditProduct['id']) ?>,
    <?= json_encode($stockEditProduct['nama']) ?>,
    <?= json_encode($stockEditProduct['satuan']) ?>,
    <?= json_encode('pcs') ?>,
    <?= (int)($stockEditProduct['isi_per_pack'] ?? 1) ?>,
    true
);
<?php endif; ?>
<?php endif; ?>
<?php if ($stockUnavailable): ?>stockUnavailableDialog.showModal();<?php endif; ?>
document.getElementById('filterProduct').addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('.product-card').forEach(el=>el.style.display=el.dataset.name.includes(q)?'':'none')});
const barcodeScanDialog = document.getElementById('barcode-scan-dialog');
const barcodeScanVideo = document.getElementById('barcode-scan-video');
const barcodeScanStatus = document.getElementById('barcode-scan-status');
let barcodeScanStream = null;
let barcodeScanTimer = null;
const stopBarcodeScan = () => {
    if (barcodeScanTimer !== null) window.clearTimeout(barcodeScanTimer);
    barcodeScanTimer = null;
    if (barcodeScanStream) barcodeScanStream.getTracks().forEach((track) => track.stop());
    barcodeScanStream = null;
    barcodeScanVideo.srcObject = null;
};
const scanProductBarcode = async (detector) => {
    if (!barcodeScanDialog.open || !barcodeScanStream) return;
    try {
        const detectedCodes = await detector.detect(barcodeScanVideo);
        const scannedCode = detectedCodes[0]?.rawValue?.trim();
        if (scannedCode) {
            const normalizedCode = scannedCode.toLocaleLowerCase();
            const productCard = Array.from(document.querySelectorAll('.product-card')).find((card) =>
                [card.dataset.barcode, card.dataset.barcodePcs].some((code) =>
                    code && code.trim().toLocaleLowerCase() === normalizedCode
                )
            );
            if (!productCard) {
                barcodeScanStatus.textContent = 'Barcode tidak ditemukan. Coba pindai lagi.';
            } else {
                const addForm = productCard.querySelector('.add-form');
                if (!addForm) {
                    barcodeScanStatus.textContent = `${productCard.querySelector('b').textContent} stoknya habis.`;
                } else {
                    stopBarcodeScan();
                    barcodeScanDialog.close();
                    addForm.requestSubmit();
                    return;
                }
            }
        }
        barcodeScanTimer = window.setTimeout(() => scanProductBarcode(detector), 250);
    } catch (error) {
        stopBarcodeScan();
        barcodeScanStatus.textContent = 'Pemindaian barcode gagal. Periksa kamera lalu coba lagi.';
    }
};
document.getElementById('open-barcode-scanner').addEventListener('click', async () => {
    barcodeScanDialog.showModal();
    if (!('BarcodeDetector' in window)) {
        barcodeScanStatus.textContent = 'Browser ini belum mendukung pemindaian barcode kamera. Gunakan pencarian produk.';
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        barcodeScanStatus.textContent = 'Akses kamera tidak tersedia. Gunakan koneksi HTTPS atau localhost.';
        return;
    }
    barcodeScanStatus.textContent = 'Meminta akses kamera...';
    try {
        const supportedFormats = await window.BarcodeDetector.getSupportedFormats();
        const detector = new window.BarcodeDetector({ formats: supportedFormats });
        barcodeScanStream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' } }
        });
        barcodeScanVideo.srcObject = barcodeScanStream;
        await barcodeScanVideo.play();
        barcodeScanStatus.textContent = 'Arahkan kamera ke barcode produk.';
        scanProductBarcode(detector);
    } catch (error) {
        stopBarcodeScan();
        barcodeScanStatus.textContent = 'Kamera tidak dapat dibuka. Izinkan akses kamera lalu coba lagi.';
    }
});
document.getElementById('close-barcode-scanner').addEventListener('click', () => barcodeScanDialog.close());
barcodeScanDialog.addEventListener('close', stopBarcodeScan);
let hardwareBarcodeBuffer = '';
let hardwareBarcodeLastKeyAt = 0;
let hardwareBarcodeInput = null;
let hardwareBarcodeInputValue = '';
document.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        const scannedCode = hardwareBarcodeBuffer.trim().toLocaleLowerCase();
        const scanWasFast = hardwareBarcodeBuffer.length >= 4
            && Date.now() - hardwareBarcodeLastKeyAt <= 100;
        hardwareBarcodeBuffer = '';
        hardwareBarcodeLastKeyAt = 0;
        if (!scanWasFast) return;
        const productCard = Array.from(document.querySelectorAll('.product-card')).find((card) =>
            [card.dataset.barcode, card.dataset.barcodePcs].some((code) =>
                code && code.trim().toLocaleLowerCase() === scannedCode
            )
        );
        const addForm = productCard?.querySelector('.add-form');
        if (!addForm) return;
        event.preventDefault();
        if (hardwareBarcodeInput instanceof HTMLInputElement) {
            hardwareBarcodeInput.value = hardwareBarcodeInputValue;
            if (hardwareBarcodeInput.id === 'filterProduct') {
                hardwareBarcodeInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        addForm.requestSubmit();
        hardwareBarcodeInput = null;
        hardwareBarcodeInputValue = '';
        return;
    }
    if (event.key.length !== 1 || event.ctrlKey || event.altKey || event.metaKey) {
        hardwareBarcodeBuffer = '';
        hardwareBarcodeLastKeyAt = 0;
        hardwareBarcodeInput = null;
        return;
    }
    const now = Date.now();
    if (hardwareBarcodeLastKeyAt && now - hardwareBarcodeLastKeyAt > 100) {
        hardwareBarcodeBuffer = '';
        hardwareBarcodeInput = null;
    }
    if (!hardwareBarcodeBuffer) {
        hardwareBarcodeInput = event.target instanceof HTMLInputElement ? event.target : null;
        hardwareBarcodeInputValue = hardwareBarcodeInput?.value ?? '';
    }
    hardwareBarcodeBuffer += event.key;
    hardwareBarcodeLastKeyAt = now;
});
document.querySelectorAll('.qty-form input[name="qty"]').forEach((input) => {
    input.addEventListener('change', () => {
        if (input.value !== '' && input.validity.valid) {
            input.form.querySelector('.qty-action').value = 'qty';
            input.form.requestSubmit();
        }
    });
});
document.querySelectorAll('.cart-sale-unit').forEach((select) => {
    select.addEventListener('change', () => {
        select.form.querySelector('.qty-action').value = 'unit';
        select.form.querySelector('input[name="qty"]').removeAttribute('max');
        select.form.requestSubmit();
    });
});
</script>
<?php require 'includes/footer.php'; ?>
