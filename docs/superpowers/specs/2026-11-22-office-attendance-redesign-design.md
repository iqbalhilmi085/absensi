# Desain Ulang Portal Absensi Perkantoran (Clean Corporate HRIS)

**Tanggal:** 2026-11-22  
**Status:** Menunggu Persetujuan  
**Pendekatan Terpilih:** Opsi 1 — Rombak Total ke Desain Korporat Modern + Portal WFO/WFH Penuh (Tanpa Kiosk)

---

## 1. Tujuan & Konteks

Mengubah aplikasi absensi yang saat ini bernuansa retail/shift & mode Kiosk bersama menjadi **portal absensi perkantoran berbasis akun masing-masing** dengan tampilan *clean corporate* (minimalis, profesional, nyaman dibaca di desktop maupun mobile) serta mendukung kerja **WFO (Di Kantor)** dan **WFH (Work From Home)** beserta **catatan kegiatan**.

### Sasaran Pengguna & Peran
1. **Karyawan (`karyawan`)**: Login memakai email & kata sandi sendiri, melakukan absen masuk/pulang dengan pilihan mode **WFO** atau **WFH** + catatan kegiatan, melihat ringkasan & riwayat pribadi, serta mengajukan izin/sakit milik sendiri.
2. **Atasan / Supervisor (`atasan`)**: Login memakai akun sendiri, memantau dashboard kehadiran tim, absen harian (beserta mode WFO/WFH & catatan kegiatan), rekap bulanan, riwayat, penilaian disiplin (K-Means), koreksi absen, serta daftar izin/sakit.
3. **Admin HR (`admin`)**: Seluruh akses Atasan ditambah pengelolaan daftar karyawan/divisi (termasuk setel/reset kata sandi karyawan agar bisa login) dan pengaturan aturan kantor & kode QR.

### Non-Goals & Anti-Goals
- **Non-Goals**: Tidak menambah framework frontend/build step (tetap HTML/CSS/JS statis + PHP native agar langsung jalan di PHP/Laragon); tidak mengubah driver database aktif (`SQLite` tetap default, skema `MySQL` tetap kompatibel); tidak menghapus riwayat absensi historis yang sudah tersimpan di database.
- **Anti-Goals**: Tidak menyisakan tombol atau endpoint Kiosk tanpa autentikasi yang memungkinkan seseorang mengabsenkan orang lain; tidak memblokir karyawan WFH dengan validasi QR kantor atau radius GPS kantor; tidak memicu aturan shift pabrik/retail pada catatan kegiatan kantor (mis. kata *"pagi"* pada kalimat kegiatan dianggap Shift Pagi).

---

## 2. Kendala & Keputusan Arsitektur (Calls)

| Area | Keputusan | Alasan |
| :--- | :--- | :--- |
| **Autentikasi Semua Peran** | `login()` dan `require_login()` di `backend/app/auth.php` mengizinkan `karyawan`, `atasan`, dan `admin` yang `aktif = 1`. | Mendukung login akun masing-masing; hak akses per endpoint tetap dibatasi oleh `require_role(...)` di `backend/api.php`. |
| **Penghapusan Kiosk** | Hapus UI Kiosk dari `frontend/login.html`; endpoint `kiosk.info` dan `kiosk.absen` di `backend/api.php` dinonaktifkan (mengembalikan HTTP `410 Gone` dengan pesan informatif). | Menutup celah absen tanpa login sesuai keputusan Opsi 1, sekaligus memberi respons jelas bila masih ada klien lama yang memanggil endpoint tersebut. |
| **Privasi Data Karyawan** | `absensi.izin_list` dan `backend/media.php` membatasi akses peran `karyawan` hanya ke baris/foto miliknya sendiri (`pengguna_id = $user['id']`), sedangkan `admin` & `atasan` tetap dapat melihat seluruh organisasi. | Saat `karyawan` bisa login, endpoint lama yang memfilter hanya berdasarkan `organisasi_id` akan membocorkan data izin & foto rekan kerja jika tidak dibatasi. |
| **Validasi WFO vs WFH** | Mode yang tersedia di UI adalah **`wfo`** dan **`wfh`**. Mode `wfo` menjalankan aturan QR (`wajib_barcode`) & GPS/radius (`wajib_gps`/`radius_masuk_m`). Mode `wfh` melewati validasi QR kantor & penolakan radius, tetap meminta foto bila `wajib_foto = 1`, merekam koordinat GPS secara opsional (jika diizinkan browser tanpa memblokir pengguna laptop), dan **mewajibkan catatan kegiatan**. | Karyawan WFH tidak berada di gedung kantor sehingga tidak mungkin memindai poster QR kantor atau berada di dalam radius kantor. |
| **Kegiatan Masuk & Pulang** | `absensi.checkin` menerima parameter opsional `kegiatan` (wajib saat `mode = 'wfh'`, maks 500 karakter) dan menyimpannya ke kolom `absensi.kegiatan`. `absensi.checkout` menampilkan kembali `kegiatan` hari ini agar dapat diperbarui/dilengkapi menjadi laporan hasil kerja pulang (wajib diisi bila `mode = 'wfh'`). | Memanfaatkan kolom `absensi.kegiatan` yang sudah ada di skema database tanpa perlu migrasi kolom baru. |
| **Perbaikan Bug Perhitungan Telat** | Di `hitung_terlambat_row()` (`backend/app/absensi.php`), hapus normalisasi `$netLate % 60` untuk keterlambatan > 180 menit sehingga durasi terlambat dihitung apa adanya (menit sebenarnya). Di `jadwal_shift_row()`, pencocokan shift lama diperketat hanya pada tag eksplisit (`Shift: Pagi`, `Shift: Siang`, `Shift: Malam`, `Shift: Middle`), bukan kata umum `"pagi"`/`"siang"`/`"malam"` di dalam catatan kegiatan. | Mencegah bug keterlambatan kelipatan 60 menit (mis. telat 240 menit terbaca 0 menit / `hadir`) dan mencegah teks kegiatan seperti *"Meeting pagi"* menggeser jadwal masuk dari `08:00` ke `06:00`. |
| **Data & Istilah Kantor** | Seluruh label UI diganti dari istilah shift/retail (`Bagian / Shift`, `Keterangan Shift`, `Shift Pagi`) menjadi istilah perkantoran (`Divisi / Departemen`, `Mode & Kegiatan`, contoh `Operasional / Finance / IT`). Data historis di `absensi_dev.sqlite` tetap dipertahankan; `seed_organisasi_default()` di `db.php` memakai preset `'kantor'` (`08:00–17:00`, toleransi `15` menit, hari kerja `1,2,3,4,5`). | Memastikan aplikasi langsung bernuansa kantor tanpa merusak 2.526 data absensi yang sudah ada. Admin dapat mengubah preset organisasi aktif dari menu Pengaturan Kantor kapan saja. |

---

## 3. Rancangan Backend (`backend/`)

### 3.1 `backend/app/auth.php`
- `require_login()`: Hanya memeriksa sesi login (`current_user()`) dan bahwa pengguna masih aktif (`aktif = 1`). Menghapus pengecekan `($user['peran'] ?? '') !== 'admin'`.
- `login()`: Menghapus blokade `($user['peran'] ?? '') !== 'admin'`. Tetap mempertahankan pembatasan percobaan gagal (`LOGIN_MAX_FAILS`, `LOGIN_LOCK_MINUTES`), `session_regenerate_id(true)`, dan `write_audit(...)`.

### 3.2 `backend/app/absensi.php`
- **`do_checkin(...)`**:
  - Tambah parameter opsional `?string $kegiatan = null`.
  - Validasi mode: normalisasi ke `mode_options()` (default `'wfo'`).
  - Jika `$mode === 'wfh'`:
    - Wajibkan `$kegiatan` terisi minimal 3 karakter (`clean_text($kegiatan, 500)`), jika kosong kembalikan `422`: `"Catatan kegiatan wajib diisi saat absen WFH."`
    - Lewati `valid_barcode(...)` (simpan `barcode_masuk = ''`).
    - Koordinat GPS bersifat opsional & tidak ditolak oleh radius kantor (`resolve_gps` hanya memvalidasi format koordinat & flag `palsu` bila koordinat dikirim, tanpa memblokir bila koordinat kosong atau di luar radius).
  - Jika `$mode !== 'wfh'` (`wfo` dan mode di kantor lainnya):
    - Jalankan `valid_barcode($org, $barcode)` dan `resolve_gps($org, ...)` seperti saat ini.
  - Simpan `kegiatan` pada `INSERT` maupun `UPDATE` tabel `absensi`.
- **`do_checkout(...)`**:
  - Baca `$modeEfektif = $mode ?: ($existing['mode'] ?: 'wfo')`.
  - Terapkan aturan WFO vs WFH yang konsisten dengan check-in: bila `$modeEfektif === 'wfh'`, lewati `valid_barcode` dan penolakan radius kantor, serta wajibkan `$kegiatan` terisi minimal 3 karakter (`clean_text($kegiatan, 500)`).
  - Jika `$kegiatan` pada checkout kosong saat `wfo`, pertahankan `$existing['kegiatan']` jika sudah ada atau simpan string bersih.
- **`daftar_izin(int $orgId, ?int $onlyUserId = null)`**:
  - Tambahkan parameter opsional `$onlyUserId`. Jika diisi (untuk peran `karyawan`), tambahkan `AND a.pengguna_id = ?` agar hanya mengembalikan riwayat izin/sakit milik karyawan tersebut.
- **`hitung_terlambat_row()` & `jadwal_shift_row()`**:
  - Hapus blok `if ($netLate > 180) { $netLate = min(60, $netLate % 60); }`.
  - Di `jadwal_shift_row()`, gunakan pencocokan regex eksplisit `/Shift:\s*(Pagi|Siang|Malam|Middle)/i` pada `catatan` agar kata bebas di `kegiatan` tidak memicu jadwal shift lama.
- **`daftar_harian(...)`**:
  - Pastikan kolom `a.mode` ikut di-SELECT agar halaman `Absen Hari Ini` dapat menampilkan badge `WFO` / `WFH`.
- **`axel_dataset(...)`**:
  - Pastikan kolom `a.mode` ikut di-SELECT dan dikembalikan untuk filter/tampilan/ekspor `Riwayat Absen`.

### 3.3 `backend/api.php` & `backend/media.php`
- `api.php`:
  - `absensi.checkin`: teruskan `param('kegiatan') ? (string) param('kegiatan') : null` ke `do_checkin(...)`.
  - `absensi.izin_list`: jika `$user['peran'] === 'karyawan'`, panggil `daftar_izin((int) $user['organisasi_id'], (int) $user['id'])`.
  - `kiosk.info` & `kiosk.absen`: kembalikan `json_error('Mode Kiosk sudah dinonaktifkan. Silakan login menggunakan akun masing-masing.', 410)`.
  - `axel.dataset`: dukung filter opsional `mode` (`wfo` / `wfh`) bila dipilih pada halaman Riwayat Absen.
  - `karyawan.save` & `karyawan.toggle`: tetap `require_role(['admin'])`.
- `media.php`:
  - Jika `$user['peran'] === 'karyawan'`, query verifikasi foto wajib menyertakan `AND a.pengguna_id = ?` (berdasarkan `$user['id']`) agar karyawan tidak bisa membuka foto milik karyawan lain.

---

## 4. Rancangan Desain UI "Clean Kantoran" (`frontend/`)

### 4.1 Arah Visual & Sistem Desain (`frontend/assets/css/app.css`)
- **Estetika**: *Clean Corporate HRIS* — bersih, tenang, terstruktur, kontras tinggi, bebas ornamen berlebihan.
- **Palet Warna (Mode Terang — Default)**:
  - Latar halaman: `#f8fafc` (Slate 50)
  - Permukaan kartu / topbar / sidebar: `#ffffff` dengan border tipis `1px solid #e2e8f0` dan bayangan halus (`0 1px 2px rgba(15, 23, 42, 0.04)`)
  - Teks utama: `#0f172a` (Slate 900); sekunder: `#334155`; muted: `#64748b`
  - Aksen utama korporat: `#1d4ed8` / `#2563eb` (Corporate Blue) dengan soft highlight `#eff6ff`
  - Status & Mode badges: pill lembut dengan border tipis (`Hadir` hijau lembut, `Terlambat` amber, `Izin/Sakit` biru langit, `Alpa` merah, `WFO` biru korporat, `WFH` ungu/indigo lembut `#eef2ff` / `#4338ca`).
- **Mode Gelap**: Dipertahankan dan diselaraskan (`#0b1220` canvas, `#111827` / `#162032` surface, border `#1e293b` / `#293548`).
- **Ikonografi & Tipografi**: Font `Plus Jakarta Sans`, angka tabel & jam memakai `tabular-nums`. Seluruh emoji pada tombol/label UI (seperti `🌙`, `☀️`, `📍`) diganti dengan ikon SVG garis tipis yang seragam.

### 4.2 Halaman Login (`frontend/login.html`)
- **Layout Split-Screen Kantoran (Desktop)** / **Centered Card (Mobile)**:
  - **Panel Kiri (Corporate Intro)**: Logo/monogram perusahaan, judul portal (*Portal Absensi Karyawan*), deskripsi singkat, dan ringkasan fitur absen (WFO via QR/GPS, WFH dengan catatan kegiatan, rekap & izin mandiri).
  - **Panel Kanan (Form Masuk)**: Judul *"Masuk ke Akun Anda"*, input `Email Kantor`, input `Kata Sandi` lengkap dengan tombol tampilkan/sembunyikan kata sandi, tombol utama `Masuk`, dan catatan kecil di bawah form: *"Belum punya akses atau lupa kata sandi? Hubungi Admin HR."*
  - **Tanpa Tombol Kiosk** dan tanpa script/modal Kiosk.

### 4.3 Shell Aplikasi (`frontend/index.html` & `frontend/assets/js/app.js`)
- **Sidebar**: Lebar `252px`, pengelompokan navigasi rapi:
  - Untuk `karyawan`: **Absen Saya**, **Pengajuan Izin / Sakit**.
  - Untuk `admin` / `atasan`: kelompok **Pemantauan** (`Beranda`, `Absen Hari Ini`), **Laporan & Analitik** (`Rekap Bulanan`, `Riwayat Absen`, `Penilaian Disiplin`), **Pengelolaan** (`Catat Izin / Sakit`, serta khusus `admin`: `Daftar Karyawan`, `Pengaturan Kantor`).
- **Topbar**: Menampilkan nama organisasi aktif, tanggal & jam WIB real-time, serta jam kerja kantor aktif (menggantikan lencana "Aktif" yang berkedip).

### 4.4 Portal Karyawan (`viewBeranda` di `app.js` + `scanner.js`)
- **Kartu Utama Presensi (Grid 2 Kolom di Desktop)**:
  - **Kiri (Panel Absen)**:
    - Sapaan personal, tanggal hari ini, jam real-time besar (`tabular-nums`), dan info jadwal kerja kantor (`Jam Kerja 08:00–17:00 · Toleransi 15 mnt`).
    - **Segmented Control Mode Kerja** (saat belum absen masuk): pilih **`Di Kantor (WFO)`** atau **`Work From Home (WFH)`**, dengan keterangan syarat otomatis berubah di bawahnya (WFO: QR/Foto/GPS sesuai pengaturan; WFH: tanpa QR kantor, wajib isi catatan kegiatan).
    - **Field Catatan Kegiatan**:
      - Saat akan **Absen Masuk**: label *"Rencana / Catatan Kegiatan Hari Ini"* (`wajib` jika pilih WFH, `opsional` jika WFO).
      - Saat sudah masuk dan akan **Absen Pulang**: tampilkan mode kerja aktif hari ini (`WFO`/`WFH`), prefill catatan kegiatan sebelumnya, dan field *"Ringkasan Kegiatan Hari Ini"* (`wajib` jika WFH, `opsional` jika WFO).
    - **Tombol Utama**: `Absen Masuk Sekarang` / `Absen Pulang Sekarang` / status selesai jika sudah pulang.
  - **Kanan (Ringkasan Hari Ini & Statistik Bulan Ini)**:
    - Kartu status hari ini (Jam Masuk, Jam Pulang, Mode `WFO`/`WFH`, Status, dan Catatan Kegiatan).
    - 3 kartu metrik bulan berjalan (`Hadir Tepat Waktu`, `Terlambat`, `Izin / Sakit / Alpa`).
- **Riwayat 7 Hari Terakhir**: Tabel/daftar rapi menampilkan Tanggal, Jam Masuk–Pulang, Mode (`WFO`/`WFH`), Catatan Kegiatan, dan Badge Status.
- **`scanner.js`**: Tambahkan dukungan agar `Scanner.open(...)` menerima `mode`:
  - Jika `wajibBarcode = false` dan `wajibFoto = false` dan `butuhGps = false` (mis. WFH tanpa wajib foto), `Scanner.open` tidak perlu memaksa membuka kamera kosong—atau langsung konfirmasi/submit dengan ringkasan mode & kegiatan.
  - Saat `mode === 'wfh'`, `Scanner.open` melewati fase QR dan tidak memblokir tombol kirim karena tidak ada GPS bila perangkat laptop tidak mendukung/mengizinkan GPS.

### 4.5 Penyesuaian Halaman Admin & Atasan (`app.js`)
- **`Beranda` (`viewDasbor`)**: Pertahankan KPI, donut komposisi hari ini, grafik tren 7/14/30 hari, dan daftar perlu dicek dengan styling korporat baru + ringkasan jumlah **WFO vs WFH** hari ini.
- **`Absen Hari Ini` (`viewHarian`) & `Riwayat Absen` (`viewAxel`)**:
  - Kolom `Bagian / Shift` diganti menjadi **`Divisi`**.
  - Kolom `Keterangan Shift` diganti menjadi **`Mode & Kegiatan`** (menampilkan badge `WFO` / `WFH` + isi `kegiatan` + `catatan`).
  - Filter mode kerja (`Semua Mode`, `WFO`, `WFH`) ditambahkan di `Riwayat Absen`, serta ikut masuk ke ekspor **CSV, Excel, dan PDF**.
- **`Rekap Bulanan` (`viewRekap`) & `Penilaian Disiplin` (`viewKmeans`)**: Ganti label `Bagian / Shift` menjadi **`Divisi`** dan perbarui styling kartu/tabel.
- **`Daftar Karyawan` (`viewKaryawan`)**:
  - Label form: `Divisi / Departemen` (default `Operasional`), `Jabatan`, `Hak Akses` (`Karyawan`, `Atasan / Supervisor`, `Admin HR`), `Kata Sandi`.
  - Tambahkan filter peran/divisi dan catatan bantu bahwa kata sandi dipakai karyawan untuk login akun masing-masing.
- **`Pengaturan Kantor` (`viewPengaturan`)**:
  - Tambahkan info jelas bahwa pengaturan QR & radius GPS berlaku untuk absensi **WFO (Di Kantor)**, sedangkan karyawan **WFH** tetap diizinkan absen dengan wajib mengisi catatan kegiatan.
  - Pertahankan pembuat & pencetak poster QR kantor.

---

## 5. Yang Diserahkan ke Implementor (Rencana Langkah Berikutnya)
- Detail struktur CSS variabel, spacing, dan markup HTML spesifik di `login.html`, `index.html`, `app.css`, dan `app.js`.
- Kasus uji verifikasi end-to-end setelah implementasi (login per peran `karyawan`/`atasan`/`admin`, absen WFO & WFH, validasi bug `hitung_terlambat_row` dan `jadwal_shift_row`, privasi `izin_list` & `media.php`, penonaktifan `kiosk.*`).
