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
    ->execute(['PT Ketik Media Siber (Ketik.com)', 'media', 'KETIK-SBY-01', '08:00', '17:00', 15, '1,2,3,4,5', 0, 1, -7.316800, 112.725500, 100, 1]);

$hash = password_hash('Secret123!', PASSWORD_DEFAULT);
$insUser = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insUser->execute([1, 'Admin Ketik', 'admin@ketik.com', $hash, 'admin', 'HR', 'HR Manager', 1, $now]);
$insUser->execute([1, 'Rina Liputan', 'rina@ketik.com', $hash, 'karyawan', 'Liputan / Jurnalis', 'Reporter', 1, $now]);
$rinaId = (int) $pdo->lastInsertId();
$insUser->execute([1, 'Dedi Redaksi', 'dedi@ketik.com', $hash, 'karyawan', 'Redaksi', 'Editor', 1, $now]);
$dediId = (int) $pdo->lastInsertId();

$rinaQr = 'KTK-RINA1122334455';
$dediQr = 'KTK-DEDI1122334455';
$insKar = $pdo->prepare('INSERT INTO karyawan (organisasi_id, nama, kode_qr, aktif, tim, jabatan, legacy_pengguna_id, dibuat_pada) VALUES (?, ?, ?, 1, ?, ?, ?, ?)');
$insKar->execute([1, 'Rina Liputan', $rinaQr, 'Liputan / Jurnalis', 'Reporter', $rinaId, $now]);
$rinaKarId = (int) $pdo->lastInsertId();
$insKar->execute([1, 'Dedi Redaksi', $dediQr, 'Redaksi', 'Editor', $dediId, $now]);

// Unit checks on pure functions
$late240 = hitung_terlambat_row(['tanggal' => '2026-11-22', 'jam_masuk' => '12:15:00', 'catatan' => '', 'kegiatan' => ''], '08:00', 15);
check($late240 === 240, "hitung_terlambat_row(08:00 + 15m, 12:15) === 240 (got {$late240})");

$schedOffice = jadwal_shift_row(['catatan' => '', 'kegiatan' => 'Meeting pagi bersama tim redaksi'], '08:00');
check($schedOffice === '08:00', "jadwal_shift_row ignores 'pagi' inside kegiatan (got {$schedOffice})");

$schedLegacy = jadwal_shift_row(['catatan' => 'Shift: Pagi (06:00-14:00)', 'kegiatan' => ''], '08:00');
check($schedLegacy === '06:00', "jadwal_shift_row keeps matching explicit 'Shift: Pagi' (got {$schedLegacy})");

// Division schedules, shift resolution, cross-midnight check-in, and snapshot lateness
$orgRow = get_org(1);
$divSched = resolve_employee_schedule($orgRow, 'Liputan / Jurnalis', null);
check($divSched['jam_masuk'] === '09:00' && $divSched['jam_pulang'] === '18:00', "resolve_employee_schedule uses Liputan/Jurnalis hours 09:00-18:00 (got {$divSched['jam_masuk']}-{$divSched['jam_pulang']})");

$shifts = list_shift_kerja(1);
$nightShift = array_values(array_filter($shifts, fn($s) => $s['nama'] === 'Shift Malam'))[0] ?? null;
check($nightShift !== null && $nightShift['jam_masuk'] === '22:00', 'list_shift_kerja includes overnight Shift Malam (22:00-06:00)');

$ovSched = resolve_employee_schedule($orgRow, 'Liputan / Jurnalis', (int) ($nightShift['id'] ?? 0));
check($ovSched['jam_masuk'] === '22:00' && $ovSched['sumber'] === 'shift', 'resolve_employee_schedule overrides division hours when shift_id is supplied');

check(hitung_status_masuk_jadwal('09:00', 15, '09:12:00') === 'hadir', 'hitung_status_masuk_jadwal treats 09:12 on 09:00+15m shift as hadir');
check(hitung_status_masuk_jadwal('09:00', 15, '09:20:00') === 'terlambat', 'hitung_status_masuk_jadwal treats 09:20 on 09:00+15m shift as terlambat');
check(hitung_status_masuk_jadwal('22:00', 15, '00:10:00') === 'terlambat', 'hitung_status_masuk_jadwal treats 00:10 after 22:00 overnight shift as terlambat');

$lateSnap = hitung_terlambat_row(['tanggal' => '2026-11-23', 'jam_masuk' => '13:20:00', 'jam_jadwal_masuk' => '13:00', 'catatan' => ''], '08:00', 15);
check($lateSnap === 5, "hitung_terlambat_row uses snapshot jam_jadwal_masuk=13:00 (got {$lateSnap})");

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

$ckPub = tempnam(sys_get_temp_dir(), 'ck_pub2_');
$ckAdmin = tempnam(sys_get_temp_dir(), 'ck_adm2_');

register_shutdown_function(function () use ($proc, $pipes, $routerFile, $tmpDb, $ckPub, $ckAdmin, $sessDir) {
    foreach ($pipes as $p) {
        if (is_resource($p)) @fclose($p);
    }
    if (is_resource($proc)) @proc_terminate($proc);
    foreach ([$routerFile, $tmpDb, $tmpDb . '-wal', $tmpDb . '-shm', $ckPub, $ckAdmin] as $f) {
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
$csrfPub = httpReq2("{$base}/backend/api.php?action=auth.csrf", $ckPub)['json']['csrf'] ?? '';

// 1) Personal QR Liputan/WFH with empty kegiatan -> 422
$wfhEmpty = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $rinaQr, 'mode' => 'wfh', 'kegiatan' => ''], $csrfPub);
check($wfhEmpty['code'] === 422, "qr.absen WFH/Liputan with empty kegiatan fails 422 (got {$wfhEmpty['code']})");

// 2) Personal QR Liputan/WFH with valid kegiatan, even far from office GPS -> 200 & sets karyawan_id
$wfhOk = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, [
    'kode_qr' => $rinaQr,
    'mode' => 'wfh',
    'kegiatan' => 'Liputan agenda Pemkot Surabaya',
    'lat' => -7.250000,
    'lng' => 112.750000,
    'akurasi' => 20,
], $csrfPub);
check($wfhOk['code'] === 200 && ($wfhOk['json']['data']['mode'] ?? '') === 'wfh', "qr.absen WFH/Liputan succeeds 200 without office radius block (got {$wfhOk['code']})");

$savedKid = (int) $pdo->query("SELECT karyawan_id FROM absensi WHERE pengguna_id = {$rinaId}")->fetchColumn();
check($savedKid === $rinaKarId, "qr.absen saves karyawan_id={$rinaKarId} alongside pengguna_id (got {$savedKid})");

// 3) Personal QR checkout (second scan on same day auto-detects checkout)
$wfhOutEmpty = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $rinaQr, 'mode' => 'wfh', 'kegiatan' => ''], $csrfPub);
check($wfhOutEmpty['code'] === 422, "qr.absen WFH checkout with empty kegiatan fails 422 (got {$wfhOutEmpty['code']})");

$wfhOutOk = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $rinaQr, 'mode' => 'wfh', 'kegiatan' => 'Naskah berita dikirim ke redaktur'], $csrfPub);
check($wfhOutOk['code'] === 200 && ($wfhOutOk['json']['data']['tipe'] ?? '') === 'pulang', "qr.absen second scan performs checkout (got {$wfhOutOk['code']})");

// 4) Third scan on same day -> 409 already completed
$wfhDone = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $rinaQr, 'mode' => 'wfh', 'kegiatan' => 'Lagi'], $csrfPub);
check($wfhDone['code'] === 409, "qr.absen third scan on same day returns 409 (got {$wfhDone['code']})");

// 5) Personal QR WFO enforces office GPS radius
$wfoOutRadius = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $dediQr, 'mode' => 'wfo', 'lat' => -7.400000, 'lng' => 112.725500, 'akurasi' => 15], $csrfPub);
check($wfoOutRadius['code'] === 422, "qr.absen WFO outside 100m radius fails 422 (got {$wfoOutRadius['code']})");

$wfoOk = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, ['kode_qr' => $dediQr, 'mode' => 'wfo', 'lat' => -7.316850, 'lng' => 112.725500, 'akurasi' => 15], $csrfPub);
check($wfoOk['code'] === 200 && ($wfoOk['json']['data']['mode'] ?? '') === 'wfo', "qr.absen WFO inside office radius succeeds 200 (got {$wfoOk['code']})");

// 6) Admin dashboard, harian, and axel.dataset work cleanly
$csrfAdmin = httpReq2("{$base}/backend/api.php?action=auth.csrf", $ckAdmin)['json']['csrf'] ?? '';
httpReq2("{$base}/backend/api.php?action=auth.login", $ckAdmin, ['email' => 'admin@ketik.com', 'kata_sandi' => 'Secret123!'], $csrfAdmin);

$dash = httpReq2("{$base}/backend/api.php?action=dashboard", $ckAdmin);
$h = $dash['json']['data']['hari_ini'] ?? [];
check(($h['wfo'] ?? -1) === 1 && ($h['wfh'] ?? -1) === 1, 'dashboard_summary returns hari_ini.wfo=1 and hari_ini.wfh=1');

$today = date('Y-m-d');
$harian = httpReq2("{$base}/backend/api.php?action=absensi.harian&tanggal={$today}", $ckAdmin);
$rinaRow = array_values(array_filter($harian['json']['data'] ?? [], fn($r) => (int) $r['id'] === $rinaId))[0] ?? null;
check(($rinaRow['mode'] ?? '') === 'wfh' && str_contains((string) ($rinaRow['kegiatan'] ?? ''), 'Naskah berita'), 'daftar_harian includes mode and kegiatan');

$axelWfh = httpReq2("{$base}/backend/api.php?action=axel.dataset&dari={$today}&sampai={$today}&mode=wfh", $ckAdmin);
check(count($axelWfh['json']['data'] ?? []) === 1 && ($axelWfh['json']['data'][0]['mode'] ?? '') === 'wfh', 'axel.dataset supports mode=wfh filter');

// 7) 60-second double-scan guard + device multi_perangkat anomaly + member self-service riwayat & izin approval
$emp3 = httpReq2("{$base}/backend/api.php?action=karyawan.save", $ckAdmin, [
    'nama' => 'Magang Test 60s',
    'email' => 'magang60@ketik.com',
    'peran' => 'karyawan',
    'tipe' => 'magang',
    'kampus' => 'UB',
    'tgl_mulai' => $today,
    'tgl_selesai' => date('Y-m-d', strtotime('+60 days')),
    'tim' => 'Redaksi',
    'jabatan' => 'Magang Redaksi',
    'aktif' => 1,
], $csrfAdmin);
$mQr = (string) ($emp3['json']['kode_qr'] ?? '');
$mIn = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, [
    'kode_qr' => $mQr,
    'mode' => 'wfh',
    'expected_state' => 'in',
    'kegiatan' => 'Liputan lapangan pagi',
], $csrfPub);
check($mIn['code'] === 200 && ($mIn['json']['data']['tipe'] ?? '') === 'masuk', 'Magang QR checkin with expected_state=in succeeds');

$mDup = httpReq2("{$base}/backend/api.php?action=qr.absen", $ckPub, [
    'kode_qr' => $mQr,
    'mode' => 'wfh',
    'expected_state' => 'in',
    'kegiatan' => 'Liputan lapangan pagi',
], $csrfPub);
check($mDup['code'] === 200 && ($mDup['json']['data']['tipe'] ?? '') === 'duplikat_diabaikan', '60s duplicate scan with expected_state=in returns duplikat_diabaikan');

$anomCol = (string) $pdo->query("SELECT anomali FROM absensi WHERE pengguna_id = " . (int) $emp3['json']['id'] . " AND tanggal = '{$today}'")->fetchColumn();
check(str_contains($anomCol, 'multi_perangkat'), "Same device checking in multiple cards triggers multi_perangkat anomaly (got: {$anomCol})");

$mRiw = httpReq2("{$base}/backend/api.php?action=qr.riwayat", $ckPub, ['kode_qr' => $mQr], $csrfPub);
check($mRiw['code'] === 200 && count($mRiw['json']['riwayat'] ?? []) >= 1, 'qr.riwayat returns own member attendance history without login');

// Self-service leave request on tomorrow + Admin approve
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$mIzin = httpReq2("{$base}/backend/api.php?action=qr.izin", $ckPub, [
    'kode_qr' => $mQr,
    'jenis' => 'dinas_luar',
    'mulai' => $tomorrow,
    'sampai' => $tomorrow,
    'catatan' => 'Liputan luar kota Jakarta',
], $csrfPub);
check($mIzin['code'] === 200 && ($mIzin['json']['data']['approval_status'] ?? '') === 'menunggu', 'qr.izin submits dinas_luar with approval_status=menunggu');

$izinList = httpReq2("{$base}/backend/api.php?action=absensi.izin_list", $ckAdmin);
$pendingRow = array_values(array_filter($izinList['json']['data'] ?? [], fn($x) => ($x['approval_status'] ?? '') === 'menunggu'))[0] ?? null;
$apv = httpReq2("{$base}/backend/api.php?action=absensi.izin_approve", $ckAdmin, ['id' => (int) ($pendingRow['id'] ?? 0), 'keputusan' => 'disetujui'], $csrfAdmin);
check($apv['code'] === 200 && ($apv['json']['data']['approval_status'] ?? '') === 'disetujui', 'absensi.izin_approve approves pending leave request');

$rekapMagang = httpReq2("{$base}/backend/api.php?action=rekap&dari={$today}&sampai={$today}&tipe=magang", $ckAdmin);
check($rekapMagang['code'] === 200 && count($rekapMagang['json']['data'] ?? []) === 1 && ($rekapMagang['json']['data'][0]['kampus'] ?? '') === 'UB', 'rekap?tipe=magang returns filtered magang certificate fields');

exit($failures > 0 ? 1 : 0);
