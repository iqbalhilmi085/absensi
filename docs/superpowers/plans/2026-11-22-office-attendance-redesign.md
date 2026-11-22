# Portal Absensi Perkantoran (Clean Corporate HRIS) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mengubah aplikasi absensi menjadi portal perkantoran *clean corporate* dengan login per akun (`karyawan`, `atasan`, `admin`), tanpa Kiosk, dukungan absen **WFO** vs **WFH + catatan kegiatan**, privasi data karyawan, dan perbaikan bug perhitungan keterlambatan.

**Architecture:** Backend PHP native (`backend/app/auth.php`, `backend/app/absensi.php`, `backend/api.php`, `backend/media.php`, `backend/app/db.php`) mempertahankan SQLite default & skema MySQL yang ada sambil membuka autentikasi semua peran, memisahkan aturan validasi WFO (QR/GPS/foto) vs WFH (bebas QR/radius, wajib `kegiatan`), dan menonaktifkan Kiosk (`HTTP 410`). Frontend statis (`frontend/login.html`, `frontend/index.html`, `frontend/assets/css/app.css`, `frontend/assets/js/scanner.js`, `frontend/assets/js/app.js`) didesain ulang dengan palet korporat slate/navy yang bersih tanpa framework build tambahan.

**Tech Stack:** PHP 8.3 (PDO SQLite/MySQL), HTML5, CSS3, Vanilla ES6+ JavaScript, jsQR, QRCode, jsPDF + AutoTable, Node.js (`node --check` untuk validasi sintaks JS).

**Spec:** [2026-11-22-office-attendance-redesign-design.md](file:///C:/laragon/www/Absensi/docs/superpowers/specs/2026-11-22-office-attendance-redesign-design.md)

## Global Constraints

- Tetap menggunakan HTML/CSS/JS statis + PHP 8.3+ native tanpa framework frontend atau build step tambahan.
- Driver database default tetap `sqlite` (`backend/data/absensi_dev.sqlite`) dan skema tetap kompatibel dengan `mysql`.
- Data historis absensi (2.526 baris di `absensi_dev.sqlite`) tidak dihapus atau dimodifikasi strukturnya.
- Seluruh UI Kiosk di `frontend/login.html` dihapus; endpoint `kiosk.info` dan `kiosk.absen` mengembalikan HTTP `410`.
- Peran `karyawan`, `atasan`, dan `admin` yang `aktif = 1` dapat login; pembatasan fitur per peran dijaga lewat `require_role(...)` dan filter kepemilikan data karyawan (`absensi.izin_list` & `backend/media.php`).
- Mode `wfh` melewati `valid_barcode` dan penolakan radius GPS kantor, serta wajib mengisi `kegiatan` minimal 3 karakter (`maks 500` karakter) saat check-in dan check-out.
- Desain *clean corporate* memakai font `Plus Jakarta Sans`, palet slate/navy (`#f8fafc` bg, `#ffffff` permukaan, `#e2e8f0` border, `#1d4ed8`/`#2563eb` aksen), ikon SVG seragam (tanpa emoji di UI), dan istilah perkantoran (`Divisi / Departemen`, `Mode & Kegiatan`).

## Review Focus

1. **Privasi data karyawan pada izin & foto (`absensi.izin_list` dan `backend/media.php`)**: Karyawan A yang login hanya boleh melihat daftar izin/sakit miliknya sendiri dan hanya boleh mengakses foto miliknya sendiri (akses foto rekan kerja harus ditolak `404`), sementara `admin` & `atasan` tetap melihat seluruh organisasi.
2. **Validasi kontras WFO vs WFH saat `wajib_barcode = 1` & `radius_masuk_m = 100`**: Check-in `mode = 'wfh'` tanpa QR dan tanpa GPS harus berhasil bila `kegiatan` >= 3 karakter, dan harus gagal (`422`) bila `kegiatan` kosong/kurang dari 3 karakter; sebaliknya check-in `mode = 'wfo'` dengan QR salah atau di luar radius harus tetap ditolak (`422`).
3. **Bug keterlambatan > 3 jam & false-positive kata shift di `kegiatan`**: `hitung_terlambat_row()` dengan jadwal `08:00`, toleransi `15` menit, dan jam masuk `12:15` harus mengembalikan `240` menit (`terlambat`), bukan `0`; teks `kegiatan = "Meeting pagi dengan klien"` tanpa tag `Shift: Pagi` di `catatan` harus tetap dinilai terhadap jadwal organisasi (`08:00`), bukan `06:00`.
4. **Penonaktifan Kiosk & batas akses peran**: Request ke `api.php?action=kiosk.info` dan `api.php?action=kiosk.absen` harus mengembalikan HTTP `410` tanpa menulis ke DB; akun `karyawan` yang mencoba mengakses `dashboard` atau `karyawan.save` harus mendapat HTTP `403`.
5. **Alur `Scanner.open` untuk WFH tanpa kamera/GPS & WFO dengan QR**: Ketika `mode === 'wfh'` dan `wajibFoto === false` (serta GPS tidak wajib), `Scanner.open` tidak boleh macet menunggu kamera/QR dan harus bisa langsung konfirmasi; ketika `wajibFoto === true` dan kamera tidak tersedia, fallback unggah file foto harus tetap melengkapi payload WFH.

---

### Task 1: Multi-Role Authentication, Kiosk Deprecation & Privacy Scoping

**Files:**
- Modify: `backend/app/auth.php:27-45,108-156`
- Modify: `backend/api.php:139-150`
- Modify: `backend/app/absensi.php:445-455`
- Modify: `backend/media.php:14-22`
- Modify: `backend/app/db.php:310-318`
- Create: `tests/backend_auth_privacy_test.php`

**Interfaces:**
- Produces:
  - `login(string $email, string $password): array` — mengizinkan `karyawan`, `atasan`, `admin` yang `aktif = 1`.
  - `require_login(): array` — memvalidasi sesi pengguna aktif semua peran valid.
  - `daftar_izin(int $orgId, ?int $onlyUserId = null): array` — memfilter `a.pengguna_id = ?` bila `$onlyUserId !== null`.
  - `api.php` `kiosk.info` / `kiosk.absen` -> HTTP `410`.
  - `media.php` -> pembatasan akses foto milik sendiri untuk `peran === 'karyawan'`.

- [ ] **Step 1: Write the failing test `tests/backend_auth_privacy_test.php`**

Buat skrip uji PHP mandiri memakai database SQLite temporer (`sys_get_temp_dir()`) yang menguji:
1. `login()` untuk akun `karyawan`, `atasan`, dan `admin` (`ok === true`), dan menolak akun `aktif = 0` (`status === 403`).
2. `require_login()` dalam sesi `karyawan` mengembalikan data user tanpa `403`.
3. `daftar_izin($orgId, $karyawanAId)` hanya mengembalikan baris izin milik Karyawan A, bukan milik Karyawan B; sedangkan `daftar_izin($orgId, null)` mengembalikan keduanya.
4. Query kepemilikan foto seperti pada `media.php` mengembalikan `0` saat Karyawan A mengecek nama file foto milik Karyawan B, dan `>= 1` untuk foto miliknya sendiri.
5. `seed_organisasi_default()` menyisipkan organisasi baru dengan `industri = 'kantor'`, `jam_masuk = '08:00'`, `jam_pulang = '17:00'`, `hari_kerja = '1,2,3,4,5'`.
6. Simulasi HTTP ke `backend/api.php?action=kiosk.info` dan `kiosk.absen` mengembalikan status `410`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/backend_auth_privacy_test.php`  
Expected: FAIL (login karyawan ditolak 403, `daftar_izin` belum menerima `$onlyUserId`, `kiosk.info` masih 200).

- [ ] **Step 3: Implement auth, privacy scoping, kiosk 410, and office default seed**

1. Di `backend/app/auth.php`:
   - Pada `require_login()`, hapus blok `if (($user['peran'] ?? '') !== 'admin')` dan ganti dengan pengecekan `if (!in_array($user['peran'] ?? '', peran_options(), true))`.
   - Pada `login()`, hapus blok `if (($user['peran'] ?? '') !== 'admin')` dan ganti dengan `if (!in_array($user['peran'] ?? '', peran_options(), true))` agar seluruh peran valid yang aktif dapat masuk.
2. Di `backend/app/absensi.php`:
   - Ubah signature `function daftar_izin(int $orgId, ?int $onlyUserId = null): array`; tambahkan kondisi `AND a.pengguna_id = ?` bila `$onlyUserId !== null`.
3. Di `backend/api.php`:
   - Pada `case 'absensi.izin_list':`, kirim `(int) $user['id']` sebagai argumen kedua `daftar_izin(...)` jika `$user['peran'] === 'karyawan'`, selain itu `null`.
   - Pada `case 'kiosk.info':` dan `case 'kiosk.absen':`, panggil `json_error('Mode Kiosk sudah dinonaktifkan. Silakan login menggunakan akun masing-masing.', 410);`.
4. Di `backend/media.php`:
   - Setelah `$user = require_login();` dan validasi nama file, jika `$user['peran'] === 'karyawan'`, jalankan query dengan tambahan `AND a.pengguna_id = ?` (`[$user['organisasi_id'], $file, $file, $user['id']]`).
5. Di `backend/app/db.php`:
   - Pada `seed_organisasi_default()`, ubah `'kustom'` menjadi `'kantor'` agar instalasi baru langsung bertipe kantor (`08:00–17:00`, `1,2,3,4,5`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/backend_auth_privacy_test.php`  
Expected: PASS seluruh assertion (keluar dengan kode `0`).

- [ ] **Step 5: Commit**

```bash
git add backend/app/auth.php backend/app/absensi.php backend/app/db.php backend/api.php backend/media.php tests/backend_auth_privacy_test.php
git commit -m "feat: allow multi-role login, deprecate kiosk, and scope employee privacy"
```

---

### Task 2: WFO vs WFH Attendance Logic, Activity Notes (`kegiatan`), and Lateness/Shift Bug Fixes

**Files:**
- Modify: `backend/app/absensi.php:53-211,317-353,434-443,515-612`
- Modify: `backend/api.php:84-114`
- Create: `tests/backend_absensi_wfh_test.php`

**Interfaces:**
- Consumes: `auth.php` multi-role login dari Task 1.
- Produces:
  - `resolve_gps(array $org, $latRaw, $lngRaw, $akurasiRaw, bool $palsu, string $mode = 'wfo'): array`
  - `do_checkin(array $user, ?string $barcode, ?string $foto, $lat = null, $lng = null, $akurasi = null, ?string $mode = null, bool $palsu = false, ?string $kegiatan = null): array`
  - `do_checkout(array $user, ?string $barcode, ?string $foto, $lat = null, $lng = null, $akurasi = null, ?string $mode = null, bool $palsu = false, ?string $kegiatan = null): array`
  - `dashboard_summary(int $orgId): array` — `hari_ini` menyertakan `wfo` dan `wfh`.
  - `daftar_harian(int $orgId, string $tanggal): array` — menyertakan `a.mode`.
  - `axel_dataset(array $user): array` — menyertakan `a.mode` dan mendukung filter `?mode=wfo|wfh`.

- [ ] **Step 1: Write the failing test `tests/backend_absensi_wfh_test.php`**

Buat skrip uji PHP menggunakan SQLite temporer dengan organisasi `wajib_barcode = 1`, `kode_lokasi = 'KANTOR-01'`, `wajib_foto = 0`, `wajib_gps = 1`, `radius_masuk_m = 100`, `lat_kantor = -6.200000`, `lng_kantor = 106.816666`, `jam_masuk = '08:00'`, `toleransi_menit = 15`:
1. `do_checkin(..., mode: 'wfh', kegiatan: '')` harus gagal (`422` / error catatan kegiatan wajib).
2. `do_checkin(..., mode: 'wfh', kegiatan: 'Mengerjakan laporan bulanan dari rumah')` tanpa barcode dan tanpa koordinat GPS harus **berhasil** (`mode === 'wfh'`, kolom `kegiatan` tersimpan).
3. `do_checkout(..., mode: 'wfh', kegiatan: '')` harus gagal (`422`), sedangkan dengan `kegiatan: 'Selesai merapikan laporan bulanan'` tanpa barcode/GPS harus **berhasil** dan memperbarui `kegiatan`.
4. `do_checkin(..., barcode: 'SALAH', mode: 'wfo')` harus gagal (`422`), dan `do_checkin` `wfo` dengan barcode benar tapi GPS di luar radius (`-6.25, 106.816666`) harus gagal (`422`).
5. `hitung_terlambat_row(['tanggal' => '2026-11-22', 'jam_masuk' => '12:15:00', 'catatan' => '', 'kegiatan' => ''], '08:00', 15)` harus mengembalikan `240` (bukan `0`).
6. `jadwal_shift_row(['catatan' => '', 'kegiatan' => 'Meeting pagi bersama tim keuangan'], '08:00')` harus mengembalikan `'08:00'`, sedangkan `['catatan' => 'Shift: Pagi (06:00-14:00)', 'kegiatan' => '']` mengembalikan `'06:00'`.
7. `daftar_harian`, `dashboard_summary`, dan `axel_dataset` mengembalikan field `mode` (`'wfh'` / `'wfo'`) serta jumlah `hari_ini['wfo']` / `hari_ini['wfh']`, dan `axel_dataset` mendukung filter `$_GET['mode'] = 'wfh'`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/backend_absensi_wfh_test.php`  
Expected: FAIL (WFH check-in ditolak karena barcode/GPS, `hitung_terlambat_row` mengembalikan `0`, `jadwal_shift_row` mengembalikan `'06:00'`).

- [ ] **Step 3: Implement WFO/WFH logic, `kegiatan` handling, and bug fixes**

1. Di `backend/app/absensi.php`:
   - Ubah `resolve_gps(..., string $mode = 'wfo')`: jika `$mode === 'wfh'`, GPS bersifat opsional bila tidak ada koordinat (`return $kosong`), tetap cek rentang koordinat & `$palsu` bila koordinat dikirim, namun lewati pengecekan akurasi & penolakan `$jarak > $radius`.
   - Ubah `do_checkin(..., ?string $kegiatan = null)`:
     - Normalisasi `$mode` lebih awal.
     - `$kegiatanClean = clean_text($kegiatan, 500);`
     - Jika `$mode === 'wfh'` dan `mb_strlen($kegiatanClean) < 3`, panggil `json_error('Catatan kegiatan wajib diisi minimal 3 karakter untuk absen WFH.', 422);`.
     - Jika `$mode !== 'wfh'`, jalankan `valid_barcode($org, $barcode)`.
     - Panggil `resolve_gps($org, $lat, $lng, $akurasi, $palsu, $mode)`.
     - Simpan `$kegiatanClean` ke kolom `kegiatan` pada `UPDATE` maupun `INSERT` (dan tambahkan `kegiatan` ke return value).
   - Ubah `do_checkout(...)`:
     - Baca `$existing` lebih awal atau validasi `$modeEfektif`: gunakan `$mode` yang dikirim (atau fallback ke `$existing['mode'] ?: 'wfo'` setelah record dibaca). Pastikan untuk `wfh`: barcode dan radius GPS dilewati, dan `$kegiatanClean` minimal 3 karakter (bila dikirim kosong dan `$existing['kegiatan']` sudah ada, tetap izinkan atau gunakan `$kegiatanClean ?: $existing['kegiatan']`, tapi bila hasil akhirnya `< 3` karakter, tolak dengan `422`). Untuk `wfo`, bila `$kegiatanClean === ''`, pertahankan `$existing['kegiatan']`.
   - Perbaiki `jadwal_shift_row()`: cocokkan hanya tag eksplisit `preg_match('/Shift:\s*(Pagi|Siang|Malam|Middle)/i', (string) ($r['catatan'] ?? ''), $m)` agar kata umum pada `kegiatan` tidak memicu jadwal shift.
   - Perbaiki `hitung_terlambat_row()`: hapus blok `if ($netLate > 180) { $netLate = min(60, $netLate % 60); }`.
  - Perbarui `dashboard_summary()`: hitung jumlah karyawan hadir hari ini yang `mode = 'wfh'` vs `mode <> 'wfh'` (`'wfo'`) ke dalam `$counts['wfo']` dan `$counts['wfh']`.
2. Di `backend/api.php`:
   - Pada `case 'absensi.checkin':`, teruskan `param('kegiatan') ? (string) param('kegiatan') : null` ke `do_checkin(...)`.
   - Pada `daftar_harian()` dan `axel_dataset()`, sertakan `a.mode` dalam `SELECT`. Pada `axel_dataset()`, tambahkan filter opsional `mode` (`wfo` / `wfh`) bila `param('mode')` valid.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/backend_absensi_wfh_test.php`  
Expected: PASS seluruh assertion (keluar dengan kode `0`).

- [ ] **Step 5: Commit**

```bash
git add backend/app/absensi.php backend/api.php tests/backend_absensi_wfh_test.php
git commit -m "feat: support WFO/WFH work modes with activity notes and fix lateness bugs"
```

---

### Task 3: Clean Corporate Design System, Split-Screen Login (No Kiosk) & App Shell

**Files:**
- Modify: `frontend/assets/css/app.css`
- Modify: `frontend/login.html`
- Modify: `frontend/index.html`
- Create: `tests/frontend_smoke_test.php`

**Interfaces:**
- Produces:
  - `frontend/login.html`: Split-screen corporate portal login (Email + Kata Sandi + toggle tampilkan sandi), tanpa elemen/script Kiosk (`kiosk-open-btn` dan `kiosk.info` dihapus total).
  - `frontend/index.html`: Shell korporat dengan `.sidebar` terkelompok (`#nav-desktop`), `.topbar` menampilkan `#top-org`, `#top-clock`, dan `#top-schedule` (pengganti badge "Aktif" berkedip), `#nav-mobile`, dan `#theme-toggle` tanpa emoji.
  - `frontend/assets/css/app.css`: Token & komponen Clean Corporate HRIS (`.auth-shell`, `.auth-hero`, `.auth-panel`, `.pass-wrap`, `.mode-switch`, `.badge-wfo`, `.badge-wfh`, `.beranda-grid`, `.activity-box`, `.nav-group-label`, serta dukungan mode terang/gelap).

- [ ] **Step 1: Write the failing smoke test `tests/frontend_smoke_test.php`**

Buat skrip uji PHP yang memverifikasi markup `frontend/login.html` dan `frontend/index.html`:
1. `login.html` memiliki `#login-form`, input `name="email"`, input `name="kata_sandi"`, tombol toggle sandi `#toggle-pass`, dan **tidak mengandung** string `kiosk-open-btn`, `kiosk.info`, `kiosk.absen`, atau `Masuk sebagai Admin`.
2. `index.html` memiliki `#nav-desktop`, `#nav-mobile`, `#top-org`, `#top-clock`, `#top-schedule`, `#theme-toggle`, dan **tidak mengandung** teks `Aktif` badge berkedip di topbar atau karakter emoji `🌙`/`☀️`/`📍`.
3. `app.css` mendefinisikan selector `.auth-shell`, `.mode-switch`, `.badge-wfo`, `.badge-wfh`, `.beranda-grid`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/frontend_smoke_test.php`  
Expected: FAIL (masih ada `kiosk-open-btn` di `login.html` dan class baru belum ada di `app.css`).

- [ ] **Step 3: Implement Clean Corporate CSS, `login.html`, and `index.html`**

1. Perbarui `frontend/assets/css/app.css`:
   - Pertahankan seluruh class utilitas yang masih dipakai (`glass`, `card`, `grid-2/3/4`, `metric`, `table-wrap`, `badge-*`, `modal`, `toast`, `donut-*`, `bars`, `config-*`, `cam-wrap`), lalu rapikan ke palet korporat yang lebih bersih (`#f8fafc` page bg, `#ffffff` surface, `#1d4ed8`/`#2563eb` accent, shadow tipis, border `#e2e8f0`), hilangkan animasi `pulseDot` berkedip di topbar, dan tambahkan gaya:
     - `.auth-shell` (2 kolom desktop: `.auth-hero` kiri berwarna navy/slate korporat dengan branding + highlight fitur WFO/WFH, `.auth-panel` kanan putih bersih berisi form login; 1 kolom rapi di mobile).
     - `.pass-wrap` & `.pass-toggle` untuk ikon mata tampil/sembunyi sandi.
     - `.mode-switch` (segmented button untuk memilih `WFO` vs `WFH`), `.badge-wfo`, `.badge-wfh`, `.beranda-grid` (2 kolom workspace karyawan), `.nav-group-label` di sidebar, `.schedule-pill` di topbar.
2. Perbarui `frontend/login.html`:
   - Terapkan struktur `.auth-shell` split-screen korporat: form login universal (`Email Kantor` & `Kata Sandi`, tombol `Masuk ke Portal`), toggle tampilkan kata sandi, tautan info lupa kata sandi ("Hubungi Admin HR"), tanpa tombol Kiosk, tanpa `scanner.js`/`jsQR.js` yang tidak dipakai di halaman login.
3. Perbarui `frontend/index.html`:
   - Rapikan brand header sidebar (ikon SVG korporat + `Portal Absensi`), topbar dengan `#top-org`, `#top-clock`, dan `#top-schedule` (ikon jam SVG + info jam kerja kantor), serta tombol `#theme-toggle` berikon SVG (bukan emoji).

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/frontend_smoke_test.php`  
Expected: PASS seluruh assertion.

- [ ] **Step 5: Commit**

```bash
git add frontend/assets/css/app.css frontend/login.html frontend/index.html tests/frontend_smoke_test.php
git commit -m "feat(ui): redesign clean corporate login and app shell without kiosk"
```

---

### Task 4: Employee WFO/WFH Workspace (`scanner.js` & `viewBeranda`) + Corporate Admin Views (`app.js`)

**Files:**
- Modify: `frontend/assets/js/scanner.js:139-225,366-438`
- Modify: `frontend/assets/js/app.js`
- Create: `tests/e2e_api_test.php`

**Interfaces:**
- Consumes:
  - Backend endpoints Task 1 & 2 (`auth.login`, `meta`, `absensi.today`, `absensi.checkin`, `absensi.checkout`, `absensi.izin_list`, `absensi.harian`, `axel.dataset`, `kiosk.*` `410`).
  - CSS classes dari Task 3 (`.mode-switch`, `.badge-wfo`, `.badge-wfh`, `.beranda-grid`, `.nav-group-label`).
- Produces:
  - `Scanner.open(options)`: mendukung `options.mode` (`'wfo'` | `'wfh'`); saat `'wfh'`, melewati fase QR dan tidak memblokir submit jika GPS tidak tersedia; jika tidak ada kebutuhan kamera/QR/GPS sama sekali, langsung resolve `{ barcode: null, foto: null, gps: null, palsu: false }`.
  - `App` views:
    - `viewBeranda`: segmented `WFO` / `WFH`, textarea `kegiatan` (wajib saat WFH, opsional saat WFO, prefilled saat pulang), kartu status hari ini, KPI bulan ini, riwayat 7 hari dengan Mode & Kegiatan.
    - `viewDasbor`, `viewHarian`, `viewRekap`, `viewAxel`, `viewKmeans`, `viewIzin`, `viewKaryawan`, `viewPengaturan`: istilah `Divisi / Departemen` & `Mode & Kegiatan`, filter & ekspor CSV/Excel/PDF menyertakan Mode & Kegiatan, zero emoji di JS UI.

- [ ] **Step 1: Write the failing test `tests/e2e_api_test.php`**

Buat skrip uji end-to-end HTTP + static JS audit (`tests/e2e_api_test.php`) yang menjalankan server PHP sementara pada SQLite test DB (atau memverifikasi lewat CLI + HTTP curl dengan cookie jar):
1. `node --check frontend/assets/js/scanner.js` dan `node --check frontend/assets/js/app.js` lolos tanpa error sintaks.
2. `app.js` tidak lagi mengandung istilah lama (`Bagian / Shift`, `Keterangan Shift`, `🌙`, `☀️`, `📍`) dan sudah menggunakan `Divisi` serta `Mode & Kegiatan`, mengirim `mode` & `kegiatan` pada `absensi.checkin` & `absensi.checkout`.
3. `scanner.js` menangani `opt.mode === 'wfh'` (bypass `expectsBarcode()`, non-blocking GPS).
4. Skenario HTTP penuh ke server test sementara (cookie session + CSRF token):
   - Login sebagai `karyawan` (`200`) -> `meta` (`200`) -> `absensi.checkin` dengan `mode: 'wfh'` dan `kegiatan: 'Menyiapkan materi presentasi kuartal'` (`200`) -> `absensi.checkout` dengan `mode: 'wfh'` dan `kegiatan: 'Presentasi selesai dan notulen dikirim'` (`200`) -> `absensi.izin_list` hanya mengembalikan milik sendiri -> `dashboard` mengembalikan `403`.
   - Login sebagai `admin` (`200`) -> `dashboard` (`200`) -> `absensi.harian` berisi `mode: 'wfh'` & `kegiatan` -> `axel.dataset&mode=wfh` berisi baris WFH tersebut -> `kiosk.info` mengembalikan `410`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/e2e_api_test.php`  
Expected: FAIL (`app.js` masih memakai label `Bagian / Shift` & emoji, `viewBeranda` belum mengirim `mode`/`kegiatan`).

- [ ] **Step 3: Implement `scanner.js` WFH support & `app.js` corporate redesign**

1. Di `frontend/assets/js/scanner.js`:
   - Tambahkan `mode: 'wfo'` pada default `opt`.
   - `const isWfh = opt.mode === 'wfh';`
   - `const needBarcode = !isWfh && !!opt.wajibBarcode;`
   - `const needFoto = !!opt.wajibFoto;`
   - `const needGps = !isWfh && !!(opt.wajibGps || opt.radius > 0);`
   - Jika `!needBarcode && !needFoto && !needGps`, langsung `resolve({ barcode: null, foto: null, gps: null, palsu: false })` tanpa membuka modal kamera kosong.
   - Jika `isWfh`, GPS tetap boleh diambil opsional di latar belakang jika tersedia, namun `readyToSubmit()` tidak pernah memblokir karena `!state.gps`.
2. Di `frontend/assets/js/app.js`:
   - Perbarui `ICONS` (tambah ikon `sun`, `moon`, `mapPin`, `briefcase`, `laptop`, `logOut`) dan gunakan SVG alih-alih emoji di `initTheme()` dan `viewPengaturan()`.
   - Perbarui `renderNav()` agar sidebar menampilkan label kelompok menu rapi (`.nav-group-label`) sesuai peran.
   - Perbarui `init()` untuk mengisi `#top-schedule` dengan jam kerja kantor aktif (`${state.org.jam_masuk}–${state.org.jam_pulang} WIB`).
   - Redesign `viewBeranda(view)`:
     - Layout `.beranda-grid` (2 kolom):
       - Kolom utama: Jam real-time, info jadwal kantor, segmented toggle **WFO** vs **WFH** (aktif sebelum check-in; saat sudah check-in menampilkan mode aktif hari ini), input textarea **Catatan / Rencana Kegiatan** (ditandai wajib jika WFH; saat checkout otomatis terisi dari `rec.kegiatan` agar karyawan bisa melengkapi ringkasan hasil kerjanya), validasi klien minimal 3 karakter saat WFH, dan tombol **Absen Masuk Sekarang** / **Absen Pulang Sekarang**.
       - Kolom samping: Kartu ringkasan status absen hari ini (Status badge, Mode badge `WFO`/`WFH`, Jam Masuk, Jam Pulang, Kegiatan) + 3 kartu statistik bulanan.
     - Bagian bawah: Tabel/daftar riwayat 7 hari terakhir dengan kolom/badge Mode (`WFO`/`WFH`) dan cuplikan `kegiatan`.
   - Perbarui `viewDasbor`, `viewHarian`, `viewRekap`, `viewAxel`, `viewKmeans`, `viewIzin`, `viewKaryawan`, `viewPengaturan`:
     - Ganti seluruh label `"Bagian / Shift"` -> `"Divisi"`, `"Keterangan Shift"` -> `"Mode & Kegiatan"`, placeholder `"Shift Pagi"` -> `"Contoh: Operasional"`, `"Staf Operasional"` -> `"Staf"`, dan tampilkan badge `WFO`/`WFH` (`modeBadge(r.mode)`) + `kegiatan` via helper terpusat `renderModeKegiatanCell(r)` (tidak menduplikasi logic di `viewHarian` & `viewAxel`).
     - Tambahkan filter `Mode Kerja` (`Semua Mode`, `WFO`, `WFH`) pada `viewAxel` dan sertakan kolom `Mode` & `Kegiatan` pada `exportCsv`, `exportExcel`, dan `PdfExport.dataset`.
     - Pada `viewDasbor`, tambahkan ringkasan jumlah karyawan `WFO` vs `WFH` pada kartu/ringkasan hari ini.
     - Pada `viewKaryawan`, tambahkan catatan bantu bahwa kata sandi digunakan karyawan untuk login portal akun masing-masing.
     - Pada `viewPengaturan`, tambahkan keterangan bahwa QR & radius GPS berlaku untuk absen **WFO**, sedangkan **WFH** wajib mengisi catatan kegiatan.

- [ ] **Step 4: Run all tests & syntax verification**

Run:
`node --check frontend/assets/js/scanner.js && node --check frontend/assets/js/app.js && php tests/backend_auth_privacy_test.php && php tests/backend_absensi_wfh_test.php && php tests/frontend_smoke_test.php && php tests/e2e_api_test.php`  
Expected: PASS semua uji sintaks, unit, smoke, dan e2e HTTP.

- [ ] **Step 5: Commit**

```bash
git add frontend/assets/js/scanner.js frontend/assets/js/app.js tests/e2e_api_test.php
git commit -m "feat(frontend): redesign corporate office attendance UI and WFO/WFH flows"
```
