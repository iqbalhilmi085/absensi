<?php
declare(strict_types=1);

$tmpDb = tempnam(sys_get_temp_dir(), 'absensi_t2_') . '.sqlite';
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
$now = date('Y-m-d H:i:s');
$pdo->prepare("UPDATE organisasi SET nama=?, industri=?, kode_lokasi=?, jam_masuk=?, jam_pulang=?, toleransi_menit=?, hari_kerja=?, wajib_foto=?, wajib_barcode=?, lat_kantor=?, lng_kantor=?, radius_masuk_m=?, wajib_gps=? WHERE id=1")
    ->execute(['PT Kantor Bersih', 'kantor', 'KANTOR-01', '08:00', '17:00', 15, '1,2,3,4,5', 0, 1, -6.200000, 106.816666, 100, 1]);

$hash = password_hash('Secret123!', PASSWORD_DEFAULT);
$insUser = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insUser->execute([1, 'Admin HR', 'admin@kantor.test', $hash, 'admin', 'HR', 'HR Manager', 1, $now]);
$insUser->execute([1, 'Rina WFH', 'rina@kantor.test', $hash, 'karyawan', 'Finance', 'Akuntan', 1, $now]);
$rinaId = (int) $pdo->lastInsertId();
$insUser->execute([1, 'Dedi WFO', 'dedi@kantor.test', $hash, 'karyawan', 'Operasional', 'Staf', 1, $now]);
$dediId = (int) $pdo->lastInsertId();

// Unit checks on pure functions
$late240 = hitung_terlambat_row(['tanggal' => '2026-11-22', 'jam_masuk' => '12:15:00', 'catatan' => '', 'kegiatan' => ''], '08:00', 15);
check($late240 === 240, "hitung_terlambat_row(08:00 + 15m, 12:15) === 240 (got {$late240})");

$schedOffice = jadwal_shift_row(['catatan' => '', 'kegiatan' => 'Meeting pagi bersama tim keuangan'], '08:00');
check($schedOffice === '08:00', "jadwal_shift_row ignores 'pagi' inside kegiatan (got {$schedOffice})");

$schedLegacy = jadwal_shift_row(['catatan' => 'Shift: Pagi (06:00-14:00)', 'kegiatan' => ''], '08:00');
check($schedLegacy === '06:00', "jadwal_shift_row keeps matching explicit 'Shift: Pagi' (got {$schedLegacy})");

// HTTP integration checks via ephemeral server
$port = 18092;
$routerFile = tempnam(sys_get_temp_dir(), 'router2_') . '.php';
$sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'absensi_sess2_' . getmypid();
@mkdir($sessDir, 0777, true);
$rootDir = realpath(__DIR__ . '/..');
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

$ckRina = tempnam(sys_get_temp_dir(), 'ck_rina_');
$ckDedi = tempnam(sys_get_temp_dir(), 'ck_dedi_');
$ckAdmin = tempnam(sys_get_temp_dir(), 'ck_adm2_');

register_shutdown_function(function () use ($proc, $pipes, $routerFile, $tmpDb, $ckRina, $ckDedi, $ckAdmin, $sessDir) {
    foreach ($pipes as $p) {
        if (is_resource($p)) @fclose($p);
    }
    if (is_resource($proc)) @proc_terminate($proc);
    foreach ([$routerFile, $tmpDb, $tmpDb . '-wal', $tmpDb . '-shm', $ckRina, $ckDedi, $ckAdmin] as $f) {
        if (is_file($f)) @unlink($f);
    }
    foreach (glob($sessDir . '/*') ?: [] as $sf) @unlink($sf);
    @rmdir($sessDir);
});

function httpReq2(string $url, ?string $cookieFile = null, ?array $postJson = null, string $csrf = ''): array
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

// Login Rina (WFH)
$csrfRina = httpReq2("{$base}/backend/api.php?action=auth.csrf", $ckRina)['json']['csrf'] ?? '';
httpReq2("{$base}/backend/api.php?action=auth.login", $ckRina, ['email' => 'rina@kantor.test', 'kata_sandi' => 'Secret123!'], $csrfRina);

// WFH checkin with empty kegiatan must fail 422
$wfhEmpty = httpReq2("{$base}/backend/api.php?action=absensi.checkin", $ckRina, ['mode' => 'wfh', 'kegiatan' => ''], $csrfRina);
check($wfhEmpty['code'] === 422, "WFH checkin with empty kegiatan fails 422 (got {$wfhEmpty['code']})");

// WFH checkin with valid kegiatan, no barcode, no GPS must succeed 200
$wfhOk = httpReq2("{$base}/backend/api.php?action=absensi.checkin", $ckRina, ['mode' => 'wfh', 'kegiatan' => 'Mengerjakan laporan bulanan dari rumah'], $csrfRina);
check($wfhOk['code'] === 200 && ($wfhOk['json']['data']['mode'] ?? '') === 'wfh', "WFH checkin without QR/GPS succeeds 200 (got {$wfhOk['code']})");

// WFH checkout with empty kegiatan must fail 422
$wfhOutEmpty = httpReq2("{$base}/backend/api.php?action=absensi.checkout", $ckRina, ['mode' => 'wfh', 'kegiatan' => ''], $csrfRina);
check($wfhOutEmpty['code'] === 422, "WFH checkout with empty kegiatan fails 422 (got {$wfhOutEmpty['code']})");

// WFH checkout with valid kegiatan, no barcode/GPS must succeed 200
$wfhOutOk = httpReq2("{$base}/backend/api.php?action=absensi.checkout", $ckRina, ['mode' => 'wfh', 'kegiatan' => 'Selesai merapikan laporan bulanan'], $csrfRina);
check($wfhOutOk['code'] === 200, "WFH checkout without QR/GPS succeeds 200 (got {$wfhOutOk['code']})");

// Login Dedi (WFO)
$csrfDedi = httpReq2("{$base}/backend/api.php?action=auth.csrf", $ckDedi)['json']['csrf'] ?? '';
httpReq2("{$base}/backend/api.php?action=auth.login", $ckDedi, ['email' => 'dedi@kantor.test', 'kata_sandi' => 'Secret123!'], $csrfDedi);

$wfoBadQr = httpReq2("{$base}/backend/api.php?action=absensi.checkin", $ckDedi, ['mode' => 'wfo', 'barcode' => 'SALAH', 'lat' => -6.200000, 'lng' => 106.816666, 'akurasi' => 15], $csrfDedi);
check($wfoBadQr['code'] === 422, "WFO checkin with wrong QR fails 422 (got {$wfoBadQr['code']})");

$wfoOutRadius = httpReq2("{$base}/backend/api.php?action=absensi.checkin", $ckDedi, ['mode' => 'wfo', 'barcode' => 'KANTOR-01', 'lat' => -6.250000, 'lng' => 106.816666, 'akurasi' => 15], $csrfDedi);
check($wfoOutRadius['code'] === 422, "WFO checkin outside 100m radius fails 422 (got {$wfoOutRadius['code']})");

$wfoOk = httpReq2("{$base}/backend/api.php?action=absensi.checkin", $ckDedi, ['mode' => 'wfo', 'barcode' => 'KANTOR-01', 'lat' => -6.200300, 'lng' => 106.816666, 'akurasi' => 15], $csrfDedi);
check($wfoOk['code'] === 200 && ($wfoOk['json']['data']['mode'] ?? '') === 'wfo', "WFO checkin with valid QR & GPS succeeds 200 (got {$wfoOk['code']})");

// Login Admin -> verify dashboard, harian, axel.dataset mode counts & filtering
$csrfAdmin = httpReq2("{$base}/backend/api.php?action=auth.csrf", $ckAdmin)['json']['csrf'] ?? '';
httpReq2("{$base}/backend/api.php?action=auth.login", $ckAdmin, ['email' => 'admin@kantor.test', 'kata_sandi' => 'Secret123!'], $csrfAdmin);

$dash = httpReq2("{$base}/backend/api.php?action=dashboard", $ckAdmin);
$h = $dash['json']['data']['hari_ini'] ?? [];
check(($h['wfo'] ?? -1) === 1 && ($h['wfh'] ?? -1) === 1, 'dashboard_summary returns hari_ini.wfo=1 and hari_ini.wfh=1');

$today = date('Y-m-d');
$harian = httpReq2("{$base}/backend/api.php?action=absensi.harian&tanggal={$today}", $ckAdmin);
$rinaRow = array_values(array_filter($harian['json']['data'] ?? [], fn($r) => (int) $r['id'] === $rinaId))[0] ?? null;
check(($rinaRow['mode'] ?? '') === 'wfh' && str_contains((string) ($rinaRow['kegiatan'] ?? ''), 'Selesai merapikan'), 'daftar_harian includes mode and kegiatan');

$axelWfh = httpReq2("{$base}/backend/api.php?action=axel.dataset&dari={$today}&sampai={$today}&mode=wfh", $ckAdmin);
check(count($axelWfh['json']['data'] ?? []) === 1 && ($axelWfh['json']['data'][0]['mode'] ?? '') === 'wfh', 'axel.dataset supports mode=wfh filter');

exit($failures > 0 ? 1 : 0);
