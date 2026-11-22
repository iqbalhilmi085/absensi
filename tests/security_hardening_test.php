<?php
declare(strict_types=1);

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "[PASS] {$label}\n";
    } else {
        $failures++;
        echo "[FAIL] {$label}\n";
    }
}

$rootDir = realpath(__DIR__ . '/..');

// 1. Static checks: root .htaccess, router.php, app.js badge escaping
check(is_file($rootDir . '/.htaccess'), 'Root .htaccess exists to protect repo metadata and non-public folders');
if (is_file($rootDir . '/.htaccess')) {
    $hta = (string) file_get_contents($rootDir . '/.htaccess');
    check(str_contains($hta, 'Options -Indexes'), 'Root .htaccess disables directory listing');
    check(str_contains($hta, '.git') && str_contains($hta, 'sqlite'), 'Root .htaccess blocks .git and sqlite files');
}
check(is_file($rootDir . '/router.php'), 'router.php exists for safe PHP built-in dev server routing');

$appJs = (string) file_get_contents($rootDir . '/frontend/assets/js/app.js');
check(str_contains($appJs, 'badge-${safeClass}') && str_contains($appJs, 'esc(STATUS_LABEL[s] || s)'), 'app.js badge() escapes status class and label');

// 2. Runtime security checks via ephemeral PHP server using router.php
$tmpDb = tempnam(sys_get_temp_dir(), 'absensi_sec_') . '.sqlite';
define('SQLITE_FILE', $tmpDb);
require_once $rootDir . '/backend/app/db.php';

$pdo = db();
$pdo->exec('UPDATE organisasi SET wajib_foto = 0 WHERE id = 1');
$now = date('Y-m-d H:i:s');
$hash = password_hash('Secret123!', PASSWORD_DEFAULT);
$insUser = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insUser->execute([1, 'Admin HR', 'admin@sec.test', $hash, 'admin', 'HR', 'Manager', 1, $now]);
$adminId = (int) $pdo->lastInsertId();
$insUser->execute([1, 'Karyawan Uji', 'kary@sec.test', $hash, 'karyawan', 'Ops', 'Staf', 1, $now]);
$karyId = (int) $pdo->lastInsertId();

// Seed an HR-corrected row and a checked-in row for Karyawan Uji
$yesterday = date('Y-m-d', strtotime('-1 day'));
$today = date('Y-m-d');
$pdo->prepare('INSERT INTO absensi (pengguna_id, tanggal, status, catatan, dikoreksi_oleh, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$karyId, $yesterday, 'alpa', 'Koreksi HR: tidak masuk tanpa keterangan', $adminId, $now, $now]);
$pdo->prepare('INSERT INTO absensi (pengguna_id, tanggal, status, jam_masuk, mode, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$karyId, $today, 'hadir', '08:00:00', 'wfo', $now, $now]);

$port = 18094;
$wrapperRouter = tempnam(sys_get_temp_dir(), 'wrap_r_') . '.php';
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'absensi_sess_sec_' . getmypid();
@mkdir($sessDir, 0777, true);
$routerPath = $rootDir . '/router.php';
file_put_contents($wrapperRouter, "<?php\nini_set('session.save_path', " . var_export($sessDir, true) . ");\ndefine('SQLITE_FILE', " . var_export($tmpDb, true) . ");\n"
    . (is_file($routerPath) ? "return require " . var_export($routerPath, true) . ";\n" : "return false;\n"));

$nullFile = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$proc = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($rootDir) . ' ' . escapeshellarg($wrapperRouter),
    [0 => ['pipe', 'r'], 1 => ['file', $nullFile, 'w'], 2 => ['file', $nullFile, 'w']],
    $pipes
);
for ($w = 0; $w < 20; $w++) {
    $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($fp) { fclose($fp); break; }
    usleep(100000);
}

$ckKary = tempnam(sys_get_temp_dir(), 'ck_seck_');
$ckAdm  = tempnam(sys_get_temp_dir(), 'ck_seca_');
register_shutdown_function(function () use ($proc, $pipes, $wrapperRouter, $tmpDb, $ckKary, $ckAdm, $sessDir) {
    foreach ($pipes as $p) { if (is_resource($p)) @fclose($p); }
    if (is_resource($proc)) @proc_terminate($proc);
    foreach ([$wrapperRouter, $tmpDb, $tmpDb . '-wal', $tmpDb . '-shm', $ckKary, $ckAdm] as $f) {
        if (is_file($f)) @unlink($f);
    }
    foreach (glob($sessDir . '/*') ?: [] as $sf) @unlink($sf);
    @rmdir($sessDir);
});

function secReq(string $url, ?string $cookieFile = null, ?array $postJson = null, string $csrf = ''): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($postJson !== null) {
        $headers[] = 'Content-Type: application/json';
        if ($csrf !== '') $headers[] = 'X-CSRF-Token: ' . $csrf;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postJson));
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = strtolower(substr($raw, 0, $hSize));
    $body = substr($raw, $hSize);
    return ['code' => $code, 'headers' => $rawHeaders, 'body' => $body, 'json' => json_decode($body, true)];
}

$base = "http://127.0.0.1:{$port}";

// A) Dev server router protection against direct access to sensitive files
foreach (['/backend/data/absensi_dev.sqlite', '/backend/app/config.php', '/.git/HEAD', '/tests/backend_auth_privacy_test.php'] as $sensitivePath) {
    $res = secReq($base . $sensitivePath);
    check($res['code'] === 403 || $res['code'] === 404, "Sensitive path {$sensitivePath} blocked (got {$res['code']})");
}

// B) Security headers on API responses
$csrfRes = secReq("{$base}/backend/api.php?action=auth.csrf", $ckKary);
check(str_contains($csrfRes['headers'], 'x-content-type-options: nosniff'), 'API sets X-Content-Type-Options: nosniff');
check(str_contains($csrfRes['headers'], 'x-frame-options: deny'), 'API sets X-Frame-Options: DENY');
check(str_contains($csrfRes['headers'], 'referrer-policy:'), 'API sets Referrer-Policy header');

// C) Employee cannot overwrite HR-corrected or already-checked-in record via absensi.izin
$csrfKary = $csrfRes['json']['csrf'] ?? '';
secReq("{$base}/backend/api.php?action=auth.login", $ckKary, ['email' => 'kary@sec.test', 'kata_sandi' => 'Secret123!'], $csrfKary);
$izinOverwriteHr = secReq("{$base}/backend/api.php?action=absensi.izin", $ckKary, [
    'jenis' => 'izin', 'mulai' => $yesterday, 'sampai' => $yesterday, 'catatan' => 'Coba timpa koreksi HR'
], $csrfKary);
check($izinOverwriteHr['code'] === 422, "Employee cannot overwrite HR-corrected record via absensi.izin (got {$izinOverwriteHr['code']})");

$izinOverwriteCheckin = secReq("{$base}/backend/api.php?action=absensi.izin", $ckKary, [
    'jenis' => 'izin', 'mulai' => $today, 'sampai' => $today, 'catatan' => 'Coba timpa absen masuk'
], $csrfKary);
check($izinOverwriteCheckin['code'] === 422, "Employee cannot overwrite checked-in day via absensi.izin (got {$izinOverwriteCheckin['code']})");

// D) Admin password policy, org time validation, and klaster bounds
$csrfAdm = secReq("{$base}/backend/api.php?action=auth.csrf", $ckAdm)['json']['csrf'] ?? '';
secReq("{$base}/backend/api.php?action=auth.login", $ckAdm, ['email' => 'admin@sec.test', 'kata_sandi' => 'Secret123!'], $csrfAdm);

$weakAdminPass = secReq("{$base}/backend/api.php?action=karyawan.save", $ckAdm, [
    'nama' => 'Admin Baru', 'email' => 'admin2@sec.test', 'peran' => 'admin', 'kata_sandi' => '123456'
], $csrfAdm);
check($weakAdminPass['code'] === 422, "Creating admin with < 8 char password fails 422 (got {$weakAdminPass['code']})");

$badOrgTime = secReq("{$base}/backend/api.php?action=org.update", $ckAdm, [
    'nama' => 'PT Uji', 'industri' => 'kantor', 'jam_masuk' => '99:99', 'jam_pulang' => '17:00',
    'toleransi_menit' => 15, 'hari_kerja' => [1, 2, 3, 4, 5], 'kode_lokasi' => 'KANTOR-01'
], $csrfAdm);
check($badOrgTime['code'] === 422, "org.update rejects invalid time 99:99 with 422 (got {$badOrgTime['code']})");

$badKlasterK = secReq("{$base}/backend/api.php?action=klaster.save", $ckAdm, [
    'periode' => '2026-11', 'k' => 999, 'hasil' => []
], $csrfAdm);
check($badKlasterK['code'] === 422, "klaster.save rejects out-of-range k=999 with 422 (got {$badKlasterK['code']})");

exit($failures > 0 ? 1 : 0);
