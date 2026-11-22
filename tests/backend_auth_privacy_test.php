<?php
declare(strict_types=1);

$tmpDb = tempnam(sys_get_temp_dir(), 'absensi_t1_') . '.sqlite';
define('SQLITE_FILE', $tmpDb);

require_once __DIR__ . '/../backend/app/auth.php';
require_once __DIR__ . '/../backend/app/absensi.php';

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

$pdo = db();

// 1. Seed org default must be 'media' (Ketik.com, 08:00-17:00, 1,2,3,4,5)
$org = $pdo->query('SELECT * FROM organisasi ORDER BY id LIMIT 1')->fetch();
check(($org['industri'] ?? '') === 'media', "seed_organisasi_default sets industri='media' (got: " . ($org['industri'] ?? 'null') . ")");
check(str_contains((string) ($org['nama'] ?? ''), 'Ketik'), 'seed_organisasi_default sets Ketik.com organization name');
check(isset(preset_industri()['media']), 'preset_industri() defines media preset');
check(($org['jam_masuk'] ?? '') === '08:00' && ($org['jam_pulang'] ?? '') === '17:00', 'seed_organisasi_default sets 08:00-17:00');
check(($org['hari_kerja'] ?? '') === '1,2,3,4,5', 'seed_organisasi_default sets hari_kerja=1,2,3,4,5');

$orgId = (int) $org['id'];
$now = date('Y-m-d H:i:s');
$hash = password_hash('Secret123!', PASSWORD_DEFAULT);

$insUser = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insUser->execute([$orgId, 'Admin Ketik', 'admin@ketik.com', $hash, 'admin', 'HR', 'HR Manager', 1, $now]);
$adminId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Redaktur', 'redaktur@ketik.com', $hash, 'atasan', 'Redaksi', 'Redaktur', 1, $now]);
$atasanId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Budi Reporter', 'budi@ketik.com', $hash, 'karyawan', 'Liputan', 'Reporter', 1, $now]);
$budiId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Nonaktif User', 'nonaktif@ketik.com', $hash, 'karyawan', 'Redaksi', 'Editor', 0, $now]);

$budiQr = 'KTK-TESTBUDI000011';
$pdo->prepare('INSERT INTO karyawan (organisasi_id, nama, kode_qr, aktif, tim, jabatan, legacy_pengguna_id, dibuat_pada) VALUES (?, ?, ?, 1, ?, ?, ?, ?)')
    ->execute([$orgId, 'Budi Reporter', $budiQr, 'Liputan', 'Reporter', $budiId, $now]);

// 2. Admin-only login checks
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$rAdmin = @login('admin@ketik.com', 'Secret123!');
check($rAdmin['ok'] === true, 'login() succeeds ONLY for admin');

$rAtasan = @login('redaktur@ketik.com', 'Secret123!');
check($rAtasan['ok'] === false && $rAtasan['status'] === 403, 'login() blocks atasan with 403 (admin-only website)');

$rKary = @login('budi@ketik.com', 'Secret123!');
check($rKary['ok'] === false && $rKary['status'] === 403, 'login() blocks karyawan with 403 (admin-only website)');

$rInactive = @login('nonaktif@ketik.com', 'Secret123!');
check($rInactive['ok'] === false && $rInactive['status'] === 403, 'login() blocks inactive user with 403');

// 3. HTTP server checks for admin-only lock + personal QR flow
$port = 18091;
$routerFile = tempnam(sys_get_temp_dir(), 'router_') . '.php';
$rootDir = realpath(__DIR__ . '/..');
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'absensi_sess_' . getmypid();
@mkdir($sessDir, 0777, true);
file_put_contents($routerFile, "<?php\nini_set('session.save_path', " . var_export($sessDir, true) . ");\ndefine('SQLITE_FILE', " . var_export($tmpDb, true) . ");\n"
    . "\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
    . "if (\$uri === '/backend/api.php') { \$_SERVER['SCRIPT_NAME'] = '/backend/api.php'; require " . var_export($rootDir . '/backend/api.php', true) . "; return true; }\n"
    . "if (\$uri === '/backend/media.php') { \$_SERVER['SCRIPT_NAME'] = '/backend/media.php'; require " . var_export($rootDir . '/backend/media.php', true) . "; return true; }\n"
    . "return false;\n");

$nullFile = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$proc = proc_open(
    'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($rootDir) . ' ' . escapeshellarg($routerFile),
    [0 => ['pipe', 'r'], 1 => ['file', $nullFile, 'w'], 2 => ['file', $nullFile, 'w']],
    $pipes
);
for ($w = 0; $w < 20; $w++) {
    $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($fp) { fclose($fp); break; }
    usleep(100000);
}

$cookiePub = tempnam(sys_get_temp_dir(), 'ck_pub_');
$cookieAdmin = tempnam(sys_get_temp_dir(), 'ck_adm_');

register_shutdown_function(function () use ($proc, $pipes, $routerFile, $tmpDb, $cookiePub, $cookieAdmin, $sessDir) {
    foreach ($pipes as $p) {
        if (is_resource($p)) @fclose($p);
    }
    if (is_resource($proc)) @proc_terminate($proc);
    foreach ([$routerFile, $tmpDb, $tmpDb . '-wal', $tmpDb . '-shm', $cookiePub, $cookieAdmin] as $f) {
        if (is_file($f)) @unlink($f);
    }
    foreach (glob($sessDir . '/*') ?: [] as $sf) @unlink($sf);
    @rmdir($sessDir);
});

function httpReq(string $url, ?string $cookieFile = null, ?array $postJson = null, string $csrf = ''): array
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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

$base = "http://127.0.0.1:{$port}";

// Deprecated kiosk endpoints stay 410 without DB writes
$kioskInfo = httpReq("{$base}/backend/api.php?action=kiosk.info");
check($kioskInfo['code'] === 410, "HTTP GET kiosk.info returns 410 (got {$kioskInfo['code']})");

$kioskAbsenNoCsrf = httpReq("{$base}/backend/api.php?action=kiosk.absen", null, ['pengguna_id' => $budiId]);
check($kioskAbsenNoCsrf['code'] === 410, "HTTP POST kiosk.absen without CSRF short-circuits 410 (got {$kioskAbsenNoCsrf['code']})");

// Public cannot access admin endpoints
$unauthKar = httpReq("{$base}/backend/api.php?action=karyawan.list");
check($unauthKar['code'] === 401, "karyawan.list (with QR codes) rejects unauthenticated with 401 (got {$unauthKar['code']})");

// Non-admin HTTP login blocked 403
$csrfPub = httpReq("{$base}/backend/api.php?action=auth.csrf", $cookiePub)['json']['csrf'] ?? '';
$karyHttpLogin = httpReq("{$base}/backend/api.php?action=auth.login", $cookiePub, ['email' => 'budi@ketik.com', 'kata_sandi' => 'Secret123!'], $csrfPub);
check($karyHttpLogin['code'] === 403, "HTTP login as karyawan rejected with 403 (got {$karyHttpLogin['code']})");

// Personal QR lookup: unknown QR -> 404; valid QR -> 200 single owner only
$qrUnknown = httpReq("{$base}/backend/api.php?action=qr.lookup", $cookiePub, ['kode_qr' => 'SALAH-999'], $csrfPub);
check($qrUnknown['code'] === 404, "qr.lookup with unknown QR returns 404 (got {$qrUnknown['code']})");

$qrFound = httpReq("{$base}/backend/api.php?action=qr.lookup", $cookiePub, ['kode_qr' => $budiQr], $csrfPub);
check(
    $qrFound['code'] === 200
    && ($qrFound['json']['data']['karyawan']['nama'] ?? '') === 'Budi Reporter'
    && !isset($qrFound['json']['data']['karyawan']['email']),
    'qr.lookup returns only matched employee public profile without exposing email or others'
);

// Admin login -> karyawan.save without password creates personal kode_qr -> karyawan.rotate_qr invalidates old QR
$csrfAdmin = httpReq("{$base}/backend/api.php?action=auth.csrf", $cookieAdmin)['json']['csrf'] ?? '';
$loginAdmin = httpReq("{$base}/backend/api.php?action=auth.login", $cookieAdmin, ['email' => 'admin@ketik.com', 'kata_sandi' => 'Secret123!'], $csrfAdmin);
check($loginAdmin['code'] === 200, "HTTP login as admin succeeds (got {$loginAdmin['code']})");

$addEmp = httpReq("{$base}/backend/api.php?action=karyawan.save", $cookieAdmin, [
    'nama' => 'Dina Liputan',
    'email' => 'dina@ketik.com',
    'peran' => 'karyawan',
    'tim' => 'Liputan / Jurnalis',
    'jabatan' => 'Reporter / Jurnalis',
    'kata_sandi' => '',
    'aktif' => 1,
], $csrfAdmin);
check($addEmp['code'] === 200 && !empty($addEmp['json']['id']) && str_starts_with((string) ($addEmp['json']['kode_qr'] ?? ''), 'KTK-'), "Admin can add karyawan without manual password and receives kode_qr immediately (got {$addEmp['code']})");

$listEmp = httpReq("{$base}/backend/api.php?action=karyawan.list", $cookieAdmin);
$dinaRow = array_values(array_filter($listEmp['json']['data'] ?? [], fn($r) => $r['email'] === 'dina@ketik.com'))[0] ?? null;
$dinaQr = $dinaRow['kode_qr'] ?? '';
check(str_starts_with($dinaQr, 'KTK-') && strlen($dinaQr) >= 16, "New karyawan automatically receives high-entropy personal QR ({$dinaQr})");

$rot = httpReq("{$base}/backend/api.php?action=karyawan.rotate_qr", $cookieAdmin, ['id' => (int) ($dinaRow['id'] ?? 0)], $csrfAdmin);
$dinaQrNew = $rot['json']['kode_qr'] ?? '';
check($rot['code'] === 200 && $dinaQrNew !== '' && $dinaQrNew !== $dinaQr, "karyawan.rotate_qr issues a new personal QR");

$oldQrCheck = httpReq("{$base}/backend/api.php?action=qr.lookup", $cookiePub, ['kode_qr' => $dinaQr], $csrfPub);
check($oldQrCheck['code'] === 404, "Old personal QR immediately fails after rotation (got {$oldQrCheck['code']})");

exit($failures > 0 ? 1 : 0);
