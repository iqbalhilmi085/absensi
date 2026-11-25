<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

define('APP_ROOT', dirname(__DIR__));
$envDataDir = getenv('ABSENSI_DATA_DIR');
$hasCustomDataDir = ($envDataDir !== false && trim($envDataDir) !== '');
define('DATA_DIR', $hasCustomDataDir
    ? rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($envDataDir)), DIRECTORY_SEPARATOR)
    : APP_ROOT . DIRECTORY_SEPARATOR . 'data');

// Cadangan lama (SQLite) — tidak dipakai lagi, disimpan sebagai arsip.
define('DB_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'absensi.sqlite');

// Driver aktif: 'sqlite' (default pengembangan) atau 'mysql' via env ABSENSI_DB_DRIVER.
$envDriver = getenv('ABSENSI_DB_DRIVER');
define('DB_DRIVER', ($envDriver !== false && in_array(strtolower(trim($envDriver)), ['sqlite', 'mysql'], true))
    ? strtolower(trim($envDriver))
    : 'sqlite');
if (!defined('SQLITE_FILE')) {
    $envSqlite = getenv('ABSENSI_SQLITE_FILE');
    define('SQLITE_FILE', ($envSqlite !== false && trim($envSqlite) !== '')
        ? trim($envSqlite)
        : DATA_DIR . DIRECTORY_SEPARATOR . 'absensi_dev.sqlite');
}

// Jika SQLITE_FILE diarahkan ke direktori terpisah (mis. isolasi test/temp) atau DATA_DIR read-only,
// simpan foto & file runtime bersama direktori SQLITE_FILE yang writable.
$sqliteDir = dirname(SQLITE_FILE);
$dataDirNorm = strtolower(str_replace('\\', '/', rtrim(DATA_DIR, '/\\')));
$sqliteDirNorm = strtolower(str_replace('\\', '/', rtrim($sqliteDir, '/\\')));
$useSqliteSiblingStore = (!$hasCustomDataDir && DB_DRIVER === 'sqlite' && $sqliteDirNorm !== $dataDirNorm && is_dir($sqliteDir))
    || (!is_writable(DATA_DIR) && is_writable($sqliteDir));
if (!defined('FOTO_DIR')) {
    define(
        'FOTO_DIR',
        $useSqliteSiblingStore
            ? ($sqliteDir . DIRECTORY_SEPARATOR . 'absensi_foto_' . substr(md5(SQLITE_FILE), 0, 8))
            : (DATA_DIR . DIRECTORY_SEPARATOR . 'foto')
    );
}

// Konfigurasi MySQL (wajib diset via environment pada produksi).
define('DB_HOST', getenv('ABSENSI_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('ABSENSI_DB_PORT') ?: 3307));
define('DB_NAME', getenv('ABSENSI_DB_NAME') ?: 'absensi');
define('DB_USER', getenv('ABSENSI_DB_USER') ?: 'root');
define('DB_PASS', getenv('ABSENSI_DB_PASS') !== false ? (string) getenv('ABSENSI_DB_PASS') : '');
define('AUTO_CREATE_DB', getenv('ABSENSI_AUTO_CREATE_DB') === '1');
define('SCHEMA_VERSION', 3);

define('APP_NAME', 'Absensi Ketik.com');

function resolve_qr_hmac_secret(bool $useSqliteSiblingStore = false): string
{
    $env = getenv('ABSENSI_QR_SECRET');
    if (is_string($env) && strlen(trim($env)) >= 16) {
        return trim($env);
    }
    if ($useSqliteSiblingStore) {
        $secretFile = dirname(SQLITE_FILE) . DIRECTORY_SEPARATOR . '.ketik_secret_' . substr(md5(SQLITE_FILE), 0, 12);
    } else {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0750, true);
        }
        $secretFile = DATA_DIR . DIRECTORY_SEPARATOR . '.app_secret';
        if (!is_file($secretFile) && !is_writable(DATA_DIR)) {
            $secretFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.ketik_secret_' . substr(md5(DATA_DIR), 0, 12);
        }
    }
    if (is_file($secretFile)) {
        $stored = trim((string) @file_get_contents($secretFile));
        if (strlen($stored) >= 32) {
            return $stored;
        }
    }
    $generated = bin2hex(random_bytes(32));
    @file_put_contents($secretFile, $generated);
    @chmod($secretFile, 0600);
    return $generated;
}
define('QR_HMAC_SECRET', resolve_qr_hmac_secret($useSqliteSiblingStore));

// Keamanan login & sesi admin (LANGKAH 2 + hardening)
define('LOGIN_MAX_FAILS', 5);        // jumlah percobaan gagal sebelum dikunci
define('LOGIN_LOCK_MINUTES', 15);    // lama penguncian (menit)
define('MIN_ADMIN_PASSWORD', 8);     // panjang minimal kata sandi admin
define('SESSION_IDLE_MINUTES', max(5, (int) (getenv('ABSENSI_SESSION_IDLE_MIN') ?: 30)));

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
