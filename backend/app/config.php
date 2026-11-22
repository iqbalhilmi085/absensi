<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'data');
define('FOTO_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'foto');

// Cadangan lama (SQLite) — tidak dipakai lagi, disimpan sebagai arsip.
define('DB_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'absensi.sqlite');

// Driver aktif: 'sqlite' (sementara) atau 'mysql'.
// Ubah ke 'mysql' untuk kembali memakai MySQL Laragon port 3307.
define('DB_DRIVER', 'sqlite');
if (!defined('SQLITE_FILE')) {
    define('SQLITE_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'absensi_dev.sqlite');
}

// Database utama (dipakai bila DB_DRIVER = 'mysql'): MySQL Laragon port 3307.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3307);
define('DB_NAME', 'absensi');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'Absensi Ketik.com');
define('QR_HMAC_SECRET', getenv('ABSENSI_QR_SECRET') ?: hash('sha256', __DIR__ . '|ketik-com-qr-v1-secret'));

// Keamanan login (LANGKAH 2)
define('LOGIN_MAX_FAILS', 5);        // jumlah percobaan gagal sebelum dikunci
define('LOGIN_LOCK_MINUTES', 15);    // lama penguncian (menit)
define('MIN_ADMIN_PASSWORD', 8);     // panjang minimal kata sandi admin

// Validasi berkas foto (Fase 2 — pengerasan backend)
define('MAX_FOTO_BYTES', 4 * 1024 * 1024); // 4 MB sebelum kompresi klien
define('MAX_FOTO_DIMENSI', 6000);          // piksel maksimum per sisi

// GPS & radius (Fase 2)
define('GPS_AKURASI_MAKS_M', 100);   // akurasi (meter) dianggap wajar saat radius aktif
define('GPS_KECEPATAN_MAKS', 300);   // m/s — di atas ini dianggap lompatan tidak wajar

// Pembatasan laju permintaan (rate limit) per menit
define('RATE_LIMIT_PER_MENIT', 60);  // umum untuk aksi tulis
define('RATE_LIMIT_ABSEN', 10);      // khusus check-in / check-out / izin per menit

// Preset industri: mengisi jam masuk, jam pulang, toleransi, hari kerja (F-01).
// 1=Senin ... 7=Minggu
function preset_industri(): array
{
    return [
        'media' => [
            'label' => 'Media & Redaksi (Ketik.com)',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
            'toleransi_menit' => 15,
            'hari_kerja' => '1,2,3,4,5',
        ],
        'kantor' => [
            'label' => 'Kantor',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
            'toleransi_menit' => 15,
            'hari_kerja' => '1,2,3,4,5',
        ],
        'pabrik' => [
            'label' => 'Pabrik',
            'jam_masuk' => '07:00',
            'jam_pulang' => '15:00',
            'toleransi_menit' => 5,
            'hari_kerja' => '1,2,3,4,5,6',
        ],
        'retail' => [
            'label' => 'Retail / Toko',
            'jam_masuk' => '09:00',
            'jam_pulang' => '21:00',
            'toleransi_menit' => 10,
            'hari_kerja' => '1,2,3,4,5,6,7',
        ],
        'rumah_sakit' => [
            'label' => 'Rumah Sakit',
            'jam_masuk' => '07:00',
            'jam_pulang' => '19:00',
            'toleransi_menit' => 5,
            'hari_kerja' => '1,2,3,4,5,6,7',
        ],
        'sekolah' => [
            'label' => 'Sekolah',
            'jam_masuk' => '07:00',
            'jam_pulang' => '14:00',
            'toleransi_menit' => 15,
            'hari_kerja' => '1,2,3,4,5',
        ],
        'restoran' => [
            'label' => 'Restoran / Kafe',
            'jam_masuk' => '10:00',
            'jam_pulang' => '22:00',
            'toleransi_menit' => 10,
            'hari_kerja' => '1,2,3,4,5,6,7',
        ],
        'kustom' => [
            'label' => 'Kustom',
            'jam_masuk' => '08:00',
            'jam_pulang' => '17:00',
            'toleransi_menit' => 15,
            'hari_kerja' => '1,2,3,4,5',
        ],
    ];
}

function status_options(): array
{
    return ['hadir', 'terlambat', 'izin', 'sakit', 'alpa'];
}

function peran_options(): array
{
    return ['karyawan', 'atasan', 'admin'];
}
