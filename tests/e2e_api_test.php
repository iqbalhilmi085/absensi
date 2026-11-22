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
$scannerJs = (string) file_get_contents($rootDir . '/frontend/assets/js/scanner.js');
$appJs     = (string) file_get_contents($rootDir . '/frontend/assets/js/app.js');

// 1. JS syntax checks via node --check
foreach (['frontend/assets/js/config.js', 'frontend/assets/js/api.js', 'frontend/assets/js/scanner.js', 'frontend/assets/js/pdf.js', 'frontend/assets/js/app.js'] as $rel) {
    $cmd = 'node --check ' . escapeshellarg($rootDir . '/' . $rel) . ' 2>&1';
    exec($cmd, $out, $rc);
    check($rc === 0, "node --check {$rel} passes" . ($rc ? ' -> ' . implode(' ', $out) : ''));
}

// 2. Frontend feature & copy checks
check(str_contains($scannerJs, "mode === 'wfh'") || str_contains($scannerJs, 'isWfh'), 'scanner.js handles WFH/Liputan mode bypass');
check(str_contains($appJs, 'badge-wfo') && str_contains($appJs, 'badge-wfh'), 'app.js renders WFO and WFH badges');
check(str_contains($appJs, 'renderModeKegiatanCell'), 'app.js uses centralized renderModeKegiatanCell helper');
check(str_contains($appJs, 'top-schedule'), 'app.js updates #top-schedule with active office hours');
check(str_contains($appJs, 'Divisi'), 'app.js uses Divisi corporate terminology');
check(str_contains($appJs, 'Mode & Kegiatan') || str_contains($appJs, 'Mode &amp; Kegiatan'), 'app.js uses Mode & Kegiatan column label');
check(str_contains($appJs, 'id="a-mode"'), 'app.js adds mode filter #a-mode in Riwayat Absen');
check(!str_contains($appJs, 'Bagian / Shift') && !str_contains($appJs, 'Keterangan Shift'), 'app.js removes legacy Shift labels');
check(!str_contains($appJs, '<th>Shift</th>') && !str_contains($appJs, "'Shift'"), 'app.js removes Shift table/export headers');
check(!str_contains($appJs, 'salah pilih shift') && !str_contains($appJs, 'Toko Maju Jaya'), 'app.js removes retail/shift placeholders');
check(!str_contains($appJs, 'esc(state.org.nama)} hari ini'), 'app.js avoids double HTML-escaping in pageHead()');
check(str_contains($appJs, "(r.kegiatan || '').toLowerCase().includes(q)") && str_contains($appJs, "(r.mode || '').toLowerCase().includes(q)"), 'app.js searches r.kegiatan and r.mode in viewAxel');
check(!preg_match('/🌙|☀️|📍/u', $appJs), 'app.js contains no emoji characters');

// 3. Full E2E HTTP scenario: Personal QR check-in/out + Admin-only management
$tmpDb = tempnam(sys_get_temp_dir(), 'absensi_e2e_') . '.sqlite';
define('SQLITE_FILE', $tmpDb);
require_once $rootDir . '/backend/app/db.php';

$pdo = db();
$now = date('Y-m-d H:i:s');
$pdo->prepare("UPDATE organisasi SET nama=?, industri=?, kode_lokasi=?, jam_masuk=?, jam_pulang=?, toleransi_menit=?, hari_kerja=?, wajib_foto=?, wajib_barcode=?, wajib_gps=?, radius_masuk_m=? WHERE id=1")
    ->execute(['PT Ketik Media Siber (Ketik.com)', 'media', 'KETIK-SBY-01', '08:00', '17:00', 15, '1,2,3,4,5', 0, 1, 1, 100]);

$hash = password_hash('Secret123!', PASSWORD_DEFAULT);
$insUser = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insUser->execute([1, 'Admin Ketik', 'admin@ketik.com', $hash, 'admin', 'HR', 'HR Manager', 1, $now]);
$insUser->execute([1, 'Andi Reporter', 'andi@ketik.com', $hash, 'karyawan', 'Liputan / Jurnalis', 'Reporter', 1, $now]);
$andiId = (int) $pdo->lastInsertId();
$andiQr = 'KTK-ANDI9988776655';
$pdo->prepare('INSERT INTO karyawan (organisasi_id, nama, kode_qr, aktif, tim, jabatan, legacy_pengguna_id, dibuat_pada) VALUES (?, ?, ?, 1, ?, ?, ?, ?)')
    ->execute([1, 'Andi Reporter', $andiQr, 'Liputan / Jurnalis', 'Reporter', $andiId, $now]);

$port = 18093;
$routerFile = tempnam(sys_get_temp_dir(), 'router_e2e_') . '.php';
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'absensi_sess_e2e_' . getmypid();
@mkdir($sessDir, 0777, true);
file_put_contents($routerFile, "<?php\nini_set('session.save_path', " . var_export($sessDir, true) . ");\ndefine('SQLITE_FILE', " . var_export($tmpDb, true) . ");\n"
    . "\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
    . "if (\$uri === '/backend/api.php') { \$_SERVER['SCRIPT_NAME'] = '/backend/api.php'; require " . var_export($rootDir . '/backend/api.php', true) . "; return true; }\n"
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

$ckPub = tempnam(sys_get_temp_dir(), 'ck_pub_');
$ckAdm = tempnam(sys_get_temp_dir(), 'ck_adm_');

register_shutdown_function(function () use ($proc, $pipes, $routerFile, $tmpDb, $ckPub, $ckAdm, $sessDir) {
    foreach ($pipes as $p) { if (is_resource($p)) @fclose($p); }
    if (is_resource($proc)) @proc_terminate($proc);
    foreach ([$routerFile, $tmpDb, $tmpDb . '-wal', $tmpDb . '-shm', $ckPub, $ckAdm] as $f) {
        if (is_file($f)) @unlink($f);
    }
    foreach (glob($sessDir . '/*') ?: [] as $sf) @unlink($sf);
    @rmdir($sessDir);
});

function e2eReq(string $url, ?string $cookieFile = null, ?array $postJson = null, string $csrf = ''): array
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

// Non-admin cannot log in
$csrfPub = e2eReq("{$base}/backend/api.php?action=auth.csrf", $ckPub)['json']['csrf'] ?? '';
$logKary = e2eReq("{$base}/backend/api.php?action=auth.login", $ckPub, ['email' => 'andi@ketik.com', 'kata_sandi' => 'Secret123!'], $csrfPub);
check($logKary['code'] === 403, 'E2E: karyawan login blocked with 403');

// Reporter scans personal QR -> lookup -> WFH/Liputan checkin -> lookup shows 'out' -> checkout
$lk1 = e2eReq("{$base}/backend/api.php?action=qr.lookup", $ckPub, ['kode_qr' => $andiQr], $csrfPub);
check($lk1['code'] === 200 && ($lk1['json']['data']['hari_ini']['state'] ?? '') === 'in', 'E2E: qr.lookup shows state=in before checkin');

$inWfh = e2eReq("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $andiQr, 'mode' => 'wfh', 'kegiatan' => 'Liputan rapat paripurna DPRD'], $csrfPub);
check($inWfh['code'] === 200 && ($inWfh['json']['data']['tipe'] ?? '') === 'masuk', 'E2E: personal QR WFH/Liputan checkin succeeds');

$lk2 = e2eReq("{$base}/backend/api.php?action=qr.lookup", $ckPub, ['kode_qr' => $andiQr], $csrfPub);
check(($lk2['json']['data']['hari_ini']['state'] ?? '') === 'out', 'E2E: qr.lookup shows state=out after checkin');

$outWfh = e2eReq("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $andiQr, 'mode' => 'wfh', 'kegiatan' => 'Liputan selesai dan naskah terkirim'], $csrfPub);
check($outWfh['code'] === 200 && ($outWfh['json']['data']['tipe'] ?? '') === 'pulang', 'E2E: personal QR WFH/Liputan checkout succeeds');

// Admin logs in -> views dashboard & axel.dataset
$csrfAdm = e2eReq("{$base}/backend/api.php?action=auth.csrf", $ckAdm)['json']['csrf'] ?? '';
$logAdm = e2eReq("{$base}/backend/api.php?action=auth.login", $ckAdm, ['email' => 'admin@ketik.com', 'kata_sandi' => 'Secret123!'], $csrfAdm);
check($logAdm['code'] === 200, 'E2E: admin login succeeds');

$dashAdm = e2eReq("{$base}/backend/api.php?action=dashboard", $ckAdm);
check(($dashAdm['json']['data']['hari_ini']['wfh'] ?? 0) === 1, 'E2E: admin dashboard counts hari_ini.wfh = 1');

$today = date('Y-m-d');
$axelAdm = e2eReq("{$base}/backend/api.php?action=axel.dataset&dari={$today}&sampai={$today}&mode=wfh", $ckAdm);
check(count($axelAdm['json']['data'] ?? []) === 1 && str_contains((string) ($axelAdm['json']['data'][0]['kegiatan'] ?? ''), 'naskah terkirim'), 'E2E: admin axel.dataset mode=wfh returns Andi Liputan record');

$kiosk = e2eReq("{$base}/backend/api.php?action=kiosk.info");
check($kiosk['code'] === 410, 'E2E: kiosk.info returns 410');

exit($failures > 0 ? 1 : 0);
