# Absensi Universal

Aplikasi absensi karyawan berbasis web (PHP native + MySQL) dengan frontend statis
(HTML/CSS/JavaScript). Mendukung presensi masuk/pulang via barcode/QR, foto, GPS,
pengajuan izin, rekap, analisis K-Means, dan ekspor PDF.

## Fitur

- **Presensi karyawan**: masuk/pulang dengan barcode/QR + foto, deteksi keterlambatan.
- **Mode kiosk**: karyawan absen tanpa login akun.
- **Admin HR**: kelola karyawan, tim, jam kerja, toleransi, dan lokasi barcode.
- **Rekap & laporan**: rekap harian/bulanan, ringkasan dashboard, ekspor PDF.
- **K-Means**: klasterisasi kehadiran karyawan (dataset + simpan hasil).
- **Pengaturan organisasi**: preset industri (kantor, pabrik, retail, RS, sekolah, restoran, kustom).
- **Keamanan**: CSRF token, pembatasan percobaan login, audit log, proteksi berkas backend.

## Kebutuhan

- PHP 8.3+ (ekstensi PDO SQLite — bawaan; PDO MySQL hanya perlu bila memakai MySQL)
- Web server Apache (mis. Laragon) dengan `mod_rewrite`
- MySQL 8.x (opsional — hanya bila `DB_DRIVER = 'mysql'`, proyek dikonfigurasi di port **3307**)

## Konfigurasi

Driver database diatur di `backend/app/config.php` melalui `DB_DRIVER`:

- `'sqlite'` (default saat ini) — data di `backend/data/absensi_dev.sqlite`, tanpa server DB eksternal.
- `'mysql'` — koneksi ke MySQL Laragon; sesuaikan:

```php
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'absensi');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Skema dibuat otomatis saat pertama kali diakses. Pada SQLite, satu baris
organisasi default juga disisipkan otomatis agar aplikasi langsung bisa dipakai.
Skema MySQL manual tersedia di `backend/migrasi/schema_mysql.sql`.

## Menjalankan

Arahkan document root ke folder proyek ini, lalu buka:

- **Login admin**: `frontend/login.html`
- **Dashboard**: `frontend/index.html`
- **API**: `backend/api.php`

### Membuat / mereset akun admin

```bash
php backend/tools/admin.php
```

### Migrasi data dari arsip SQLite (opsional)

```bash
php backend/migrasi/migrate.php          # migrasi
php backend/migrasi/migrate.php --force  # kosongkan MySQL lalu migrasi ulang
```

## Struktur

```
backend/    API PHP, autentikasi, logika absensi, skema & migrasi
  app/      config, db, auth, absensi, helpers
  migrasi/  skema MySQL + skrip migrasi SQLite
  tools/    utilitas CLI (buat/reset admin)
frontend/   UI statis (HTML/CSS/JS) + aset vendor (jsQR, qrcode, jsPDF)
docs/       dokumentasi
```

## Catatan

- Login hanya untuk peran **admin**; karyawan melakukan absensi via mode kiosk.
- Berkas data runtime (`backend/data/*.sqlite`, `backend/data/foto/`) tidak
  disertakan dalam repositori.
