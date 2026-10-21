CREATE DATABASE IF NOT EXISTS tokoku_kasir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tokoku_kasir;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS transaction_items;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS promos;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY,
 nama VARCHAR(100) NOT NULL,
 username VARCHAR(50) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 role ENUM('admin','kasir') NOT NULL DEFAULT 'kasir',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
 id INT AUTO_INCREMENT PRIMARY KEY,
 kode VARCHAR(30) NOT NULL UNIQUE,
 barcode VARCHAR(100) NULL,
 barcode_pcs VARCHAR(100) NULL,
 harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0,
 modal_total DECIMAL(12,2) NULL,
 nama VARCHAR(150) NOT NULL,
 kategori VARCHAR(80) NOT NULL DEFAULT 'Umum',
 harga DECIMAL(12,2) NOT NULL DEFAULT 0,
 harga_pack DECIMAL(12,2) NOT NULL DEFAULT 0,
 isi_per_pack INT NOT NULL DEFAULT 1,
 satuan_isi VARCHAR(20) NOT NULL DEFAULT 'pcs',
 stok DECIMAL(12,3) NOT NULL DEFAULT 0,
 satuan VARCHAR(20) NOT NULL DEFAULT 'pcs',
 gambar VARCHAR(255) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE transactions (
 id INT AUTO_INCREMENT PRIMARY KEY,
 kode VARCHAR(40) NOT NULL UNIQUE,
 user_id INT NULL,
 subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
 pajak DECIMAL(12,2) NOT NULL DEFAULT 0,
 total DECIMAL(12,2) NOT NULL DEFAULT 0,
 payment_method VARCHAR(30) NOT NULL DEFAULT 'Tunai',
 status VARCHAR(20) NOT NULL DEFAULT 'Lunas',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_transaction_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE transaction_items (
 id INT AUTO_INCREMENT PRIMARY KEY,
 transaction_id INT NOT NULL,
 product_id INT NULL,
 nama_produk VARCHAR(150) NOT NULL,
 qty DECIMAL(12,3) NOT NULL,
 satuan VARCHAR(20) NOT NULL DEFAULT 'pcs',
 harga DECIMAL(12,2) NOT NULL,
 total DECIMAL(12,2) NOT NULL,
 modal_total DECIMAL(12,2) NULL,
 CONSTRAINT fk_item_transaction FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
 CONSTRAINT fk_item_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE promos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 product_id INT NULL,
 nama VARCHAR(150) NOT NULL,
 jenis ENUM('Persentase','Nominal') NOT NULL,
 nilai DECIMAL(12,2) NOT NULL DEFAULT 0,
 mulai DATE NOT NULL,
 selesai DATE NOT NULL,
 status ENUM('Aktif','Nonaktif') NOT NULL DEFAULT 'Aktif'
) ENGINE=InnoDB;

-- Password demo: admin123 dan kasir123
INSERT INTO users(nama,username,password,role) VALUES
('Administrator','admin','$2y$10$88gae.N1mM5626TZgPtR9.iL2zs/AwkO3HLJsC5J5mkoGRUHdV51W','admin'),
('Kasir Toko','kasir','$2y$10$t7GlxZaKmhrhata2VsuUF.SW7hzJGictq1b.OERLJfI2vwr9RFBSW','kasir');

-- Password demo sudah disimpan dalam format hash PHP password_hash().
INSERT INTO products(kode,nama,kategori,harga,stok) VALUES
('BRG001','Beras Premium 5kg','Sembako',65000,25),
('BRG002','Minyak Goreng 1L','Sembako',18000,40),
('BRG003','Gula Pasir 1kg','Sembako',14000,35),
('BRG004','Tepung Terigu 1kg','Sembako',12000,28),
('BRG005','Indomie Goreng','Makanan',2500,100),
('BRG006','Susu Ultra 1L','Minuman',16000,20),
('BRG007','Teh Manis','Minuman',5000,50),
('BRG008','Kopi Susu','Minuman',12000,30),
('BRG009','Air Mineral','Minuman',5000,60),
('BRG010','Roti Manis','Snack',6000,22),
('BRG011','Telur Ayam 1kg','Sembako',29000,18),
('BRG012','Sabun Mandi','Perawatan',4500,45);

INSERT INTO promos(nama,jenis,nilai,mulai,selesai,status) VALUES
('Diskon Sembako','Persentase',10,'2026-10-01','2026-10-31','Aktif'),
('Promo Minyak Goreng','Nominal',2000,'2026-10-10','2026-10-25','Aktif');

-- Contoh transaksi dummy (opsional untuk laporan awal)
INSERT INTO transactions(kode,user_id,subtotal,pajak,total,payment_method,created_at) VALUES
('TRX-DEMO-001',2,29500,0,29500,'Tunai',NOW() - INTERVAL 1 DAY),
('TRX-DEMO-002',2,77000,0,77000,'QRIS',NOW() - INTERVAL 2 DAY);
INSERT INTO transaction_items(transaction_id,product_id,nama_produk,qty,harga,total) VALUES
(1,7,'Teh Manis',1,5000,5000),(1,5,'Indomie Goreng',2,2500,5000),(1,9,'Air Mineral',1,5000,5000),(1,10,'Roti Manis',1,6000,6000),(1,12,'Sabun Mandi',1,4500,4500),
(2,1,'Beras Premium 5kg',1,65000,65000),(2,3,'Gula Pasir 1kg',1,12000,12000);
