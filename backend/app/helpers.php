<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

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

function save_photo(?string $dataUrl, string $prefix = 'foto'): ?string
{
    if (!is_string($dataUrl) || $dataUrl === '') {
        return null;
    }
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#i', $dataUrl, $m)) {
        return null;
    }
    $binary = base64_decode($m[2], true);
    if ($binary === false) {
        return null;
    }
    if (strlen($binary) > 4 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto melebihi 4 MB.');
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents(FOTO_DIR . DIRECTORY_SEPARATOR . $name, $binary) === false) {
        throw new RuntimeException('Gagal menyimpan foto.');
    }
    return $name;
}

function minutes_between(?string $a, ?string $b): ?int
{
    if (!$a || !$b) {
        return null;
    }
    return (int) round((strtotime($b) - strtotime($a)) / 60);
}
