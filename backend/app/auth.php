<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['pengguna_id'])) {
        return null;
    }
    static $user = null;
    if ($user !== null && (int) $user['id'] === (int) $_SESSION['pengguna_id']) {
        return $user;
    }
    $stmt = db()->prepare('SELECT * FROM pengguna WHERE id = ? AND aktif = 1');
    $stmt->execute([$_SESSION['pengguna_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

/**
 * Semua halaman dan endpoint API memerlukan sesi login yang valid.
 * Tanpa login -> 401 (API) / redirect ke login (halaman). Peran tidak dikenal -> 403.
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        if (is_api_request()) {
            json_error('Sesi berakhir. Silakan masuk kembali.', 401);
        }
        header('Location: ../frontend/login.html');
        exit;
    }
    if (!in_array($user['peran'] ?? '', peran_options(), true)) {
        if (is_api_request()) {
            json_error('Peran akun tidak valid.', 403);
        }
        http_response_code(403);
        exit('Akses ditolak. Peran akun tidak valid.');
    }
    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['peran'], $roles, true)) {
        if (is_api_request()) {
            json_error('Anda tidak punya hak akses untuk aksi ini.', 403);
        }
        http_response_code(403);
        exit('Akses ditolak.');
    }
    return $user;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function is_api_request(): bool
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return str_contains($script, 'api.php');
}

/**
 * Jika terkunci, kembalikan sisa menit; jika tidak, kembalikan 0.
 */
function login_lock_remaining(string $email, string $ip): int
{
    $sejak = date('Y-m-d H:i:s', time() - (LOGIN_LOCK_MINUTES * 60));
    $stmt = db()->prepare(
        'SELECT MIN(dibuat_pada) AS pertama, COUNT(*) AS gagal
         FROM login_attempt
         WHERE email = ? AND ip = ? AND sukses = 0
           AND dibuat_pada >= ?'
    );
    $stmt->execute([$email, $ip, $sejak]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['gagal'] < LOGIN_MAX_FAILS) {
        return 0;
    }
    $unlock = strtotime((string) $row['pertama']) + (LOGIN_LOCK_MINUTES * 60);
    return max(1, (int) ceil(($unlock - time()) / 60));
}

function record_login_attempt(string $email, string $ip, bool $sukses): void
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO login_attempt (email, ip, sukses, dibuat_pada) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, $ip, $sukses ? 1 : 0, date('Y-m-d H:i:s')]);
    // Bersihkan riwayat lama (> 1 hari) agar tabel tetap ringkas.
    $batas = date('Y-m-d H:i:s', time() - 86400);
    $pdo->prepare('DELETE FROM login_attempt WHERE dibuat_pada < ?')->execute([$batas]);
}

function clear_login_attempts(string $email, string $ip): void
{
    $stmt = db()->prepare('DELETE FROM login_attempt WHERE email = ? AND ip = ?');
    $stmt->execute([$email, $ip]);
}

/**
 * Proses login untuk seluruh peran valid yang aktif (karyawan, atasan, admin).
 * Mengembalikan array: ['ok' => bool, 'error' => string, 'status' => int, 'user' => ?array]
 */
function login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $ip = client_ip();

    $locked = login_lock_remaining($email, $ip);
    if ($locked > 0) {
        write_audit(null, null, 'login_diblokir', "email={$email} ip={$ip}");
        return [
            'ok' => false,
            'status' => 429,
            'error' => "Terlalu banyak percobaan gagal. Coba lagi dalam {$locked} menit.",
        ];
    }

    $stmt = db()->prepare('SELECT * FROM pengguna WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['kata_sandi'])) {
        record_login_attempt($email, $ip, false);
        write_audit($user ? (int) $user['id'] : null, $user ? (int) $user['organisasi_id'] : null, 'login_gagal', "email={$email} ip={$ip}");
        return ['ok' => false, 'status' => 401, 'error' => 'Email atau kata sandi salah.'];
    }

    if ((int) $user['aktif'] !== 1) {
        record_login_attempt($email, $ip, false);
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'login_gagal', "akun_nonaktif email={$email}");
        return ['ok' => false, 'status' => 403, 'error' => 'Akun ini dinonaktifkan. Hubungi admin.'];
    }

    if (!in_array($user['peran'] ?? '', peran_options(), true)) {
        record_login_attempt($email, $ip, false);
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'login_ditolak', "peran_invalid email={$email}");
        return ['ok' => false, 'status' => 403, 'error' => 'Peran akun tidak dikenali. Hubungi admin.'];
    }

    clear_login_attempts($email, $ip);
    start_session();
    session_regenerate_id(true);
    $_SESSION['pengguna_id'] = (int) $user['id'];
    write_audit((int) $user['id'], (int) $user['organisasi_id'], 'login', $email);

    return ['ok' => true, 'status' => 200, 'error' => '', 'user' => $user];
}

function logout(): void
{
    start_session();
    $user = current_user();
    if ($user) {
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'logout', $user['email']);
    }
    $_SESSION = [];
    session_destroy();
}
