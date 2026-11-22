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

// 1. Seed org default must be 'kantor' (08:00-17:00, 1,2,3,4,5)
$org = $pdo->query('SELECT * FROM organisasi ORDER BY id LIMIT 1')->fetch();
check(($org['industri'] ?? '') === 'kantor', "seed_organisasi_default sets industri='kantor' (got: " . ($org['industri'] ?? 'null') . ")");
check(($org['jam_masuk'] ?? '') === '08:00' && ($org['jam_pulang'] ?? '') === '17:00', 'seed_organisasi_default sets 08:00-17:00');
check(($org['hari_kerja'] ?? '') === '1,2,3,4,5', 'seed_organisasi_default sets hari_kerja=1,2,3,4,5');

$orgId = (int) $org['id'];
$now = date('Y-m-d H:i:s');
$hash = password_hash('Secret123!', PASSWORD_DEFAULT);

$insUser = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insUser->execute([$orgId, 'Admin HR', 'admin@kantor.test', $hash, 'admin', 'HR', 'HR Manager', 1, $now]);
$adminId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Atasan Ops', 'atasan@kantor.test', $hash, 'atasan', 'Operasional', 'Supervisor', 1, $now]);
$atasanId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Budi Karyawan', 'budi@kantor.test', $hash, 'karyawan', 'Operasional', 'Staf', 1, $now]);
$budiId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Siti Karyawan', 'siti@kantor.test', $hash, 'karyawan', 'Finance', 'Staf', 1, $now]);
$sitiId = (int) $pdo->lastInsertId();
$insUser->execute([$orgId, 'Nonaktif User', 'nonaktif@kantor.test', $hash, 'karyawan', 'Operasional', 'Staf', 0, $now]);

// 2. Multi-role login() checks
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$rAdmin = @login('admin@kantor.test', 'Secret123!');
check($rAdmin['ok'] === true, 'login() succeeds for admin');

$rAtasan = @login('atasan@kantor.test', 'Secret123!');
check($rAtasan['ok'] === true, 'login() succeeds for atasan');

$rKary = @login('budi@kantor.test', 'Secret123!');
check($rKary['ok'] === true, 'login() succeeds for karyawan');

$rInactive = @login('nonaktif@kantor.test', 'Secret123!');
check($rInactive['ok'] === false && $rInactive['status'] === 403, 'login() blocks inactive user with 403');

// 3. Prepare izin/sakit records for Budi and Siti
$insAb = $pdo->prepare('INSERT INTO absensi (pengguna_id, tanggal, status, catatan, foto_masuk, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?)');
$insAb->execute([$budiId, '2026-11-20', 'izin', 'Urusan keluarga', 'izin_budi.jpg', $now, $now]);
$insAb->execute([$sitiId, '2026-11-20', 'sakit', 'Demam', 'sakit_siti.jpg', $now, $now]);

// 4. Start ephemeral PHP server with router injecting SQLITE_FILE for real HTTP testing
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

$cookieBudi = tempnam(sys_get_temp_dir(), 'ck_budi_');
$cookieAdmin = tempnam(sys_get_temp_dir(), 'ck_adm_');

register_shutdown_function(function () use ($proc, $pipes, $routerFile, $tmpDb, $cookieBudi, $cookieAdmin, $sessDir) {
    foreach ($pipes as $p) {
        if (is_resource($p)) @fclose($p);
    }
    if (is_resource($proc)) @proc_terminate($proc);
    foreach ([$routerFile, $tmpDb, $tmpDb . '-wal', $tmpDb . '-shm', $cookieBudi, $cookieAdmin] as $f) {
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

$kioskInfo = httpReq("{$base}/backend/api.php?action=kiosk.info");
check($kioskInfo['code'] === 410, "HTTP GET kiosk.info returns 410 (got {$kioskInfo['code']})");

$csrfBudi = httpReq("{$base}/backend/api.php?action=auth.csrf", $cookieBudi)['json']['csrf'] ?? '';
$kioskAbsen = httpReq("{$base}/backend/api.php?action=kiosk.absen", $cookieBudi, ['pengguna_id' => $budiId], $csrfBudi);
check($kioskAbsen['code'] === 410, "HTTP POST kiosk.absen returns 410 (got {$kioskAbsen['code']})");

$loginBudi = httpReq("{$base}/backend/api.php?action=auth.login", $cookieBudi, ['email' => 'budi@kantor.test', 'kata_sandi' => 'Secret123!'], $csrfBudi);
check($loginBudi['code'] === 200, "HTTP login as karyawan succeeds (got {$loginBudi['code']})");

$metaBudi = httpReq("{$base}/backend/api.php?action=meta", $cookieBudi);
check($metaBudi['code'] === 200 && ($metaBudi['json']['pengguna']['peran'] ?? '') === 'karyawan', "HTTP meta for logged-in karyawan returns 200");

$izinBudi = httpReq("{$base}/backend/api.php?action=absensi.izin_list", $cookieBudi);
check($izinBudi['code'] === 200 && count($izinBudi['json']['data'] ?? []) === 1 && (int) ($izinBudi['json']['data'][0]['pengguna_id'] ?? 0) === $budiId, 'HTTP absensi.izin_list for karyawan returns only own izin');

$dashBudi = httpReq("{$base}/backend/api.php?action=dashboard", $cookieBudi);
check($dashBudi['code'] === 403, "HTTP dashboard blocked for karyawan with 403 (got {$dashBudi['code']})");

$mediaLeak = httpReq("{$base}/backend/media.php?f=sakit_siti.jpg", $cookieBudi);
check($mediaLeak['code'] === 404, "HTTP media.php blocks karyawan from colleague photo with 404 (got {$mediaLeak['code']})");

$csrfAdmin = httpReq("{$base}/backend/api.php?action=auth.csrf", $cookieAdmin)['json']['csrf'] ?? '';
$loginAdmin = httpReq("{$base}/backend/api.php?action=auth.login", $cookieAdmin, ['email' => 'admin@kantor.test', 'kata_sandi' => 'Secret123!'], $csrfAdmin);
check($loginAdmin['code'] === 200, "HTTP login as admin succeeds (got {$loginAdmin['code']})");

$izinAdmin = httpReq("{$base}/backend/api.php?action=absensi.izin_list", $cookieAdmin);
check($izinAdmin['code'] === 200 && count($izinAdmin['json']['data'] ?? []) === 2, 'HTTP absensi.izin_list for admin returns all org izin');

exit($failures > 0 ? 1 : 0);
