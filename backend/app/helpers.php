<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    start_session();
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $token)) {
        json_error('Token keamanan tidak valid. Muat ulang halaman.', 419);
    }
}

function input_json(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    $cache = is_array($data) ? $data : [];
    return $cache;
}

function param(string $key, $default = null)
{
    $json = input_json();
    return $_POST[$key] ?? $_GET[$key] ?? $json[$key] ?? $default;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Membersihkan teks yang diketik pengguna: buang karakter kontrol, rapikan
 * spasi, dan batasi panjang agar tidak ada muatan berbahaya yang tersimpan.
 * (Lapisan tambahan di atas prepared statement.)
 */
function clean_text($value, int $max = 500): string
{
    $s = (string) $value;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = trim($s);
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    } else {
        $s = substr($s, 0, $max);
    }
    return $s;
}

/** Jarak lingkaran besar (Haversine) dalam meter. */
function haversine_meter(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    return (int) round($r * $c);
}

/**
 * Pembatasan laju permintaan per (kunci, ip). Jika melebihi batas dalam
 * jendela waktu tertentu, permintaan ditolak dengan status 429.
 */
function rate_limit(string $kunci, int $maks, int $jendelaDetik = 60, string $pesan = ''): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    $pdo = db();
    $sejak = date('Y-m-d H:i:s', time() - $jendelaDetik);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit WHERE kunci = ? AND ip = ? AND dibuat_pada >= ?');
    $stmt->execute([$kunci, $ip, $sejak]);
    if ((int) $stmt->fetchColumn() >= $maks) {
        json_error($pesan !== '' ? $pesan : 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.', 429);
    }
    $ins = $pdo->prepare('INSERT INTO rate_limit (kunci, ip, pengguna_id, dibuat_pada) VALUES (?, ?, ?, ?)');
    $userId = isset($_SESSION['pengguna_id']) ? (int) $_SESSION['pengguna_id'] : null;
    $ins->execute([$kunci, $ip, $userId, date('Y-m-d H:i:s')]);
    if (random_int(1, 25) === 1) {
        $batas = date('Y-m-d H:i:s', time() - 3600);
        $pdo->prepare('DELETE FROM rate_limit WHERE dibuat_pada < ?')->execute([$batas]);
    }
}

function hari_label(): array
{
    return [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
}

function hari_kerja_labels(string $csv): string
{
    $map = hari_label();
    $parts = array_filter(array_map('intval', explode(',', $csv)), fn($v) => isset($map[$v]));
    $labels = array_map(fn($v) => $map[$v], $parts);
    return implode(', ', $labels);
}

function status_label(string $status): string
{
    return [
        'hadir' => 'Hadir',
        'terlambat' => 'Terlambat',
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'alpa' => 'Alpa',
    ][$status] ?? ucfirst($status);
}

function peran_label(string $peran): string
{
    return ['karyawan' => 'Karyawan', 'atasan' => 'Atasan', 'admin' => 'Admin HR'][$peran] ?? ucfirst($peran);
}

function write_audit(?int $userId, ?int $orgId, string $aksi, string $detail = ''): void
{
    $stmt = db()->prepare('INSERT INTO audit_log (pengguna_id, organisasi_id, aksi, detail, dibuat_pada) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $orgId, $aksi, $detail, date('Y-m-d H:i:s')]);
}

/**
 * Menyimpan foto dari data URL. Berkas divalidasi ulang di peladen:
 * - harus berupa data URL gambar yang sah
 * - ukuran biner tidak melebihi MAX_FOTO_BYTES
 * - isi benar-benar gambar dan tipenya sesuai (getimagesize + finfo)
 * - dimensi dalam rentang wajar
 */
function save_photo(?string $dataUrl, string $prefix = 'foto'): ?string
{
    if (!is_string($dataUrl) || $dataUrl === '') {
        return null;
    }
    if (!preg_match('#^data:(image/(?:jpeg|jpg|png|webp));base64,([A-Za-z0-9+/=\s]+)$#i', $dataUrl, $m)) {
        throw new RuntimeException('Format foto tidak valid. Gunakan JPEG, PNG, atau WebP.');
    }
    $binary = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Data foto rusak. Ambil ulang foto.');
    }
    if (strlen($binary) > MAX_FOTO_BYTES) {
        throw new RuntimeException('Ukuran foto melebihi ' . round(MAX_FOTO_BYTES / 1048576, 1) . ' MB.');
    }

    $info = @getimagesizefromstring($binary);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        throw new RuntimeException('Berkas yang dikirim bukan gambar yang sah.');
    }
    $petaMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (string) ($info['mime'] ?? '');
    if (!isset($petaMime[$mime])) {
        throw new RuntimeException('Tipe gambar tidak diizinkan (hanya JPEG, PNG, WebP).');
    }
    if ($info[0] > MAX_FOTO_DIMENSI || $info[1] > MAX_FOTO_DIMENSI) {
        throw new RuntimeException('Dimensi foto terlalu besar (maks ' . MAX_FOTO_DIMENSI . ' px per sisi).');
    }
    if ($info[0] < 20 || $info[1] < 20) {
        throw new RuntimeException('Dimensi foto terlalu kecil.');
    }
    // Verifikasi tambahan via finfo bila ekstensi tersedia.
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($binary);
        if ($detected && !isset($petaMime[$detected])) {
            throw new RuntimeException('Isi berkas tidak cocok dengan gambar.');
        }
    }

    $ext = $petaMime[$mime];
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $path = FOTO_DIR . DIRECTORY_SEPARATOR . $name;
    if (file_put_contents($path, $binary) === false) {
        throw new RuntimeException('Gagal menyimpan foto.');
    }
    @chmod($path, 0644);
    return $name;
}

function minutes_between(?string $a, ?string $b): ?int
{
    if (!$a || !$b) {
        return null;
    }
    return (int) round((strtotime($b) - strtotime($a)) / 60);
}
