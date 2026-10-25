<?php
require 'config.php';
wajib_login();
$pageTitle = 'Perangkat Kasir';
$contentClass = 'devices-content';
require 'includes/header.php';
?>
<section class="panel devices-page">
    <div class="panel-head">
        <div>
            <h3>Scanner Barcode & Printer</h3>
            <p class="devices-description">Cari dan sambungkan perangkat USB atau Bluetooth yang didukung browser. Scanner barcode mode keyboard umumnya dapat langsung digunakan tanpa konfigurasi tambahan.</p>
        </div>
        <div class="device-discovery-actions">
            <button type="button" class="btn blue" id="discoverBluetooth">＋ Cari Bluetooth</button>
            <button type="button" class="btn light" id="discoverUsb">＋ Cari USB</button>
        </div>
    </div>
    <div class="alert info" id="deviceNotice" role="status" aria-live="polite">
        Perangkat yang sudah diizinkan browser akan muncul di daftar. Bluetooth harus dicari lewat tombol; browser akan menampilkan dialog izin. Koneksi berhasil belum tentu berarti printer bisa mencetak: komunikasi cetak bergantung pada dukungan browser dan protokol perangkat.
    </div>
    <div class="device-list" id="deviceList" aria-live="polite">
        <div class="empty">Memeriksa perangkat yang telah diizinkan...</div>
    </div>
</section>
<script>
(() => {
    const list = document.getElementById('deviceList');
    const notice = document.getElementById('deviceNotice');
    const knownDevices = new Map();
    const favoritesKey = 'tokomimin-device-favorites';
    let favorites = {};

    try {
        favorites = JSON.parse(localStorage.getItem(favoritesKey) || '{}');
        if (!favorites || typeof favorites !== 'object' || Array.isArray(favorites)) favorites = {};
    } catch (error) {
        notice.textContent = 'Daftar favorit tidak dapat dibaca dari penyimpanan browser.';
    }

    const deviceKey = (type, device) => type + ':' + (type === 'usb'
        ? [device.vendorId, device.productId, device.serialNumber || ''].join('-')
        : device.id);

    const setNotice = (message, isError = false) => {
        notice.textContent = message;
        notice.classList.toggle('error', isError);
        notice.classList.toggle('info', !isError);
        if (typeof window.showActionNotification === 'function') {
            window.showActionNotification(message, isError ? 'error' : 'success');
        }
    };

    const rememberDevice = (type, device, connected = false) => {
        const key = deviceKey(type, device);
        const current = knownDevices.get(key) || {};
        knownDevices.set(key, {
            type,
            device,
            key,
            connected: connected || current.connected || false
        });
        renderDevices();
        return knownDevices.get(key);
    };

    const renderDevices = () => {
        const devices = Array.from(knownDevices.values()).sort((a, b) =>
            Number(Boolean(favorites[b.key])) - Number(Boolean(favorites[a.key]))
        );
        list.replaceChildren();
        if (!devices.length) {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'Belum ada perangkat. Klik Cari Bluetooth atau Cari USB untuk menyambungkan scanner atau printer yang didukung.';
            list.append(empty);
            return;
        }

        devices.forEach((entry) => {
            const card = document.createElement('article');
            card.className = 'device-entry';
            const icon = document.createElement('span');
            icon.className = 'device-entry-icon';
            icon.textContent = entry.type === 'usb' ? '▦' : 'ᛒ';

            const details = document.createElement('div');
            details.className = 'device-entry-details';
            const name = document.createElement('strong');
            name.textContent = entry.device.productName || entry.device.name || (entry.type === 'usb' ? 'Perangkat USB' : 'Perangkat Bluetooth');
            const status = document.createElement('small');
            status.textContent = (entry.connected ? 'Tersambung' : 'Tidak tersambung') + ' · ' + (entry.type === 'usb' ? 'USB' : 'Bluetooth');
            details.append(name, status);

            const actions = document.createElement('div');
            actions.className = 'device-entry-actions';
            const connect = document.createElement('button');
            connect.type = 'button';
            connect.className = 'btn ' + (entry.connected ? 'light' : 'blue');
            connect.textContent = entry.connected ? 'Putuskan' : 'Hubungkan';
            connect.addEventListener('click', () => entry.connected ? disconnectDevice(entry) : connectDevice(entry));

            const favorite = document.createElement('button');
            favorite.type = 'button';
            favorite.className = 'btn ' + (entry.connected ? 'device-favorite-connected' : 'light');
            favorite.textContent = favorites[entry.key] ? '★ Favorit' : '☆ Tambah ke favorit';
            favorite.setAttribute('aria-pressed', favorites[entry.key] ? 'true' : 'false');
            favorite.addEventListener('click', () => {
                try {
                    if (favorites[entry.key]) delete favorites[entry.key];
                    else favorites[entry.key] = true;
                    localStorage.setItem(favoritesKey, JSON.stringify(favorites));
                    renderDevices();
                    setNotice(favorites[entry.key] ? 'Perangkat berhasil ditambahkan ke favorit.' : 'Perangkat berhasil dihapus dari favorit.');
                } catch (error) {
                    setNotice('Favorit perangkat gagal disimpan: ' + error.message, true);
                }
            });

            const forget = document.createElement('button');
            forget.type = 'button';
            forget.className = 'btn light';
            forget.textContent = 'Lupakan';
            forget.title = 'Putuskan perangkat dan cabut izin browser jika didukung';
            forget.addEventListener('click', () => forgetDevice(entry));

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn device-remove-button';
            remove.textContent = 'Hapus perangkat';
            remove.title = 'Hapus dari daftar halaman ini; izin browser tetap tersimpan';
            remove.addEventListener('click', () => removeDevice(entry));

            actions.append(connect, favorite, forget, remove);
            card.append(icon, details, actions);
            list.append(card);
        });
    };

    const connectDevice = async (entry) => {
        try {
            if (entry.type === 'bluetooth') {
                if (!entry.device.gatt) throw new Error('Perangkat ini tidak menyediakan koneksi Bluetooth GATT yang dapat digunakan browser.');
                await entry.device.gatt.connect();
                entry.device.addEventListener('gattserverdisconnected', () => {
                    entry.connected = false;
                    renderDevices();
                });
            } else {
                await entry.device.open();
                if (!entry.device.configuration && entry.device.configurations.length) {
                    await entry.device.selectConfiguration(entry.device.configurations[0].configurationValue);
                }
            }
            entry.connected = true;
            renderDevices();
            setNotice('Perangkat berhasil tersambung. Komunikasi untuk printer, scanner, atau timbangan tetap bergantung pada dukungan/protokol perangkat.');
        } catch (error) {
            setNotice('Gagal menyambungkan perangkat: ' + error.message, true);
        }
    };

    const disconnectDevice = async (entry, showResult = true) => {
        try {
            if (entry.type === 'bluetooth') {
                if (entry.device.gatt && entry.device.gatt.connected) entry.device.gatt.disconnect();
            } else if (entry.device.opened) {
                await entry.device.close();
            }
            entry.connected = false;
            renderDevices();
            if (showResult) setNotice('Perangkat telah diputuskan. Izin browser dan status favorit tidak dihapus.');
            return true;
        } catch (error) {
            if (showResult) setNotice('Gagal memutuskan perangkat: ' + error.message, true);
            return false;
        }
    };

    const forgetDevice = async (entry) => {
        try {
            if (entry.connected && !(await disconnectDevice(entry, false))) return;
            if (typeof entry.device.forget === 'function') {
                await entry.device.forget();
            } else {
                setNotice('Browser ini tidak menyediakan pencabutan izin perangkat. Hapus izin dari pengaturan situs browser untuk melupakannya sepenuhnya.', true);
                return;
            }
            knownDevices.delete(entry.key);
            delete favorites[entry.key];
            localStorage.setItem(favoritesKey, JSON.stringify(favorites));
            renderDevices();
            setNotice('Izin perangkat telah dilupakan oleh browser.');
        } catch (error) {
            setNotice('Gagal melupakan perangkat: ' + error.message, true);
        }
    };

    const removeDevice = async (entry) => {
        try {
            if (entry.connected && !(await disconnectDevice(entry, false))) return;
            knownDevices.delete(entry.key);
            delete favorites[entry.key];
            localStorage.setItem(favoritesKey, JSON.stringify(favorites));
            renderDevices();
            setNotice('Perangkat dihapus dari daftar halaman ini. Izin browser tetap tersimpan dan perangkat dapat muncul lagi saat halaman dibuka ulang.');
        } catch (error) {
            setNotice('Gagal menghapus perangkat: ' + error.message, true);
        }
    };

    document.getElementById('discoverBluetooth').addEventListener('click', async () => {
        if (!navigator.bluetooth) {
            setNotice('Web Bluetooth tidak tersedia. Gunakan browser Chromium terbaru pada localhost atau HTTPS.', true);
            return;
        }
        try {
            const device = await navigator.bluetooth.requestDevice({acceptAllDevices: true});
            const entry = rememberDevice('bluetooth', device);
            await connectDevice(entry);
        } catch (error) {
            if (error.name !== 'NotFoundError') setNotice('Pencarian Bluetooth gagal: ' + error.message, true);
        }
    });

    document.getElementById('discoverUsb').addEventListener('click', async () => {
        if (!navigator.usb) {
            setNotice('WebUSB tidak tersedia. Gunakan browser Chromium terbaru pada localhost atau HTTPS.', true);
            return;
        }
        try {
            const device = await navigator.usb.requestDevice({filters: [{}]});
            const entry = rememberDevice('usb', device);
            await connectDevice(entry);
        } catch (error) {
            if (error.name !== 'NotFoundError') setNotice('Pencarian USB gagal: ' + error.message, true);
        }
    });

    if (navigator.usb) {
        navigator.usb.addEventListener('connect', (event) => {
            const entry = rememberDevice('usb', event.device);
            connectDevice(entry);
        });
        navigator.usb.addEventListener('disconnect', (event) => {
            const entry = knownDevices.get(deviceKey('usb', event.device));
            if (entry) {
                entry.connected = false;
                renderDevices();
            }
        });
    }

    const loadAuthorizedDevices = async () => {
        try {
            if (navigator.usb) {
                for (const device of await navigator.usb.getDevices()) rememberDevice('usb', device);
            }
            if (navigator.bluetooth && typeof navigator.bluetooth.getDevices === 'function') {
                for (const device of await navigator.bluetooth.getDevices()) rememberDevice('bluetooth', device);
            }
            renderDevices();
        } catch (error) {
            setNotice('Perangkat yang pernah diizinkan tidak dapat dimuat: ' + error.message, true);
        }
    };

    loadAuthorizedDevices();
})();
</script>
<?php require 'includes/footer.php'; ?>
