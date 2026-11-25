<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function get_org(int $orgId): array
{
    $stmt = db()->prepare('SELECT * FROM organisasi WHERE id = ?');
    $stmt->execute([$orgId]);
    return $stmt->fetch() ?: [];
}

function today_record(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?');
    $stmt->execute([$userId, date('Y-m-d')]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function valid_barcode(array $org, ?string $barcode): bool
{
    if (!(int) $org['wajib_barcode']) {
        return true;
    }
    $expected = trim((string) $org['kode_lokasi']);
    $given = trim((string) $barcode);
    if ($given === '' || $expected === '') {
        return false;
    }
    return strcasecmp($given, $expected) === 0;
}

function list_hari_libur(int $orgId): array
{
    $stmt = db()->prepare('SELECT id, tanggal, keterangan FROM hari_libur WHERE organisasi_id = ? ORDER BY tanggal ASC');
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function is_hari_libur(int $orgId, string $date): bool
{
    static $cache = [];
    if (!isset($cache[$orgId])) {
        $stmt = db()->prepare('SELECT tanggal FROM hari_libur WHERE organisasi_id = ?');
        $stmt->execute([$orgId]);
        $cache[$orgId] = array_flip(array_column($stmt->fetchAll(), 'tanggal'));
    }
    return isset($cache[$orgId][$date]);
}

function is_hari_kerja(array $org, string $date): bool
{
    $dow = (int) date('N', strtotime($date));
    $list = array_map('intval', explode(',', (string) $org['hari_kerja']));
    if (!in_array($dow, $list, true)) {
        return false;
    }
    $orgId = (int) ($org['id'] ?? 0);
    return $orgId > 0 ? !is_hari_libur($orgId, $date) : true;
}

function hitung_status_masuk(array $org, string $jamMasuk): string
{
    return hitung_status_masuk_jadwal((string) ($org['jam_masuk'] ?? '08:00'), (int) ($org['toleransi_menit'] ?? 15), $jamMasuk);
}

function hitung_status_masuk_jadwal(string $jamJadwalMasuk, int $toleransiMenit, string $jamMasukAktual): string
{
    $today = date('Y-m-d');
    $jadwalTs = strtotime($today . ' ' . $jamJadwalMasuk);
    $aktualTs = strtotime($today . ' ' . $jamMasukAktual);
    $hJadwal = (int) substr($jamJadwalMasuk, 0, 2);
    $hAktual = (int) substr($jamMasukAktual, 0, 2);
    if ($hJadwal >= 20 && $hAktual < 6) {
        $aktualTs += 86400;
    } elseif ($hJadwal <= 2 && $hAktual >= 20) {
        $aktualTs -= 86400;
    }
    return $aktualTs > ($jadwalTs + ($toleransiMenit * 60)) ? 'terlambat' : 'hadir';
}

function seed_divisi_shift_defaults(PDO $pdo, int $orgId): void
{
    $cntDiv = (int) $pdo->query("SELECT COUNT(*) FROM divisi_jadwal WHERE organisasi_id = {$orgId}")->fetchColumn();
    if ($cntDiv === 0) {
        $now = date('Y-m-d H:i:s');
        $insDiv = $pdo->prepare('INSERT INTO divisi_jadwal (organisasi_id, nama, kode_unik, warna_kartu, jam_masuk, jam_pulang, toleransi_menit, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
        foreach ([
            ['Redaksi', 'RED', '#0A88C9', '08:00', '17:00', 15],
            ['Liputan / Jurnalis', 'LIP', '#0F766E', '09:00', '18:00', 15],
            ['Editor', 'EDT', '#1D4ED8', '10:00', '19:00', 15],
            ['Magang Redaksi', 'MGN', '#3ABCA5', '09:00', '15:00', 15],
            ['IT & Digital', 'ITD', '#0369A1', '08:30', '17:30', 15],
        ] as [$nama, $kode, $warna, $jm, $jp, $tol]) {
            $insDiv->execute([$orgId, $nama, $kode, $warna, $jm, $jp, $tol, $now]);
        }
    }

    $cntShift = (int) $pdo->query("SELECT COUNT(*) FROM shift_kerja WHERE organisasi_id = {$orgId}")->fetchColumn();
    if ($cntShift === 0) {
        $insShift = $pdo->prepare('INSERT INTO shift_kerja (organisasi_id, nama, jam_masuk, jam_pulang, aktif) VALUES (?, ?, ?, ?, 1)');
        foreach ([
            ['Shift Pagi', '06:00', '14:00'],
            ['Shift Siang', '13:00', '21:00'],
            ['Shift Malam', '22:00', '06:00'],
        ] as [$nama, $jm, $jp]) {
            $insShift->execute([$orgId, $nama, $jm, $jp]);
        }
    }
}

function list_divisi_jadwal(int $orgId, bool $onlyActive = false): array
{
    $pdo = db();
    seed_divisi_shift_defaults($pdo, $orgId);
    $sql = 'SELECT id, nama, kode_unik, warna_kartu, jam_masuk, jam_pulang, toleransi_menit, aktif FROM divisi_jadwal WHERE organisasi_id = ?';
    if ($onlyActive) {
        $sql .= ' AND aktif = 1';
    }
    $sql .= ' ORDER BY nama';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function list_shift_kerja(int $orgId): array
{
    $pdo = db();
    seed_divisi_shift_defaults($pdo, $orgId);
    $stmt = $pdo->prepare('SELECT id, nama, jam_masuk, jam_pulang FROM shift_kerja WHERE organisasi_id = ? AND aktif = 1 ORDER BY jam_masuk, id');
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function find_active_shift(int $orgId, int $shiftId): array
{
    $st = db()->prepare('SELECT id, nama, jam_masuk, jam_pulang FROM shift_kerja WHERE id = ? AND organisasi_id = ? AND aktif = 1');
    $st->execute([$shiftId, $orgId]);
    $row = $st->fetch();
    if (!$row) {
        json_error('Shift kerja yang dipilih tidak ditemukan atau sudah tidak aktif.', 422);
    }
    return $row;
}

function resolve_employee_schedule(array $org, string $tim, ?int $shiftId = null): array
{
    $orgId = (int) ($org['id'] ?? 1);
    $pdo = db();
    seed_divisi_shift_defaults($pdo, $orgId);

    $defTol = (int) ($org['toleransi_menit'] ?? 15);
    $timClean = trim($tim);
    $divInfo = null;
    if ($timClean !== '') {
        $stDiv = $pdo->prepare('SELECT nama, kode_unik, warna_kartu, jam_masuk, jam_pulang, toleransi_menit FROM divisi_jadwal WHERE organisasi_id = ? AND LOWER(nama) = LOWER(?) AND aktif = 1 LIMIT 1');
        $stDiv->execute([$orgId, $timClean]);
        $divInfo = $stDiv->fetch() ?: null;
    }

    if ($shiftId !== null && $shiftId > 0) {
        $row = find_active_shift($orgId, $shiftId);
        return [
            'shift_id' => (int) $row['id'],
            'shift_nama' => $row['nama'],
            'jam_masuk' => $row['jam_masuk'],
            'jam_pulang' => $row['jam_pulang'],
            'toleransi_menit' => $divInfo ? (int) $divInfo['toleransi_menit'] : $defTol,
            'warna_kartu' => $divInfo['warna_kartu'] ?? '#0A88C9',
            'kode_divisi' => $divInfo['kode_unik'] ?? '',
            'sumber' => 'shift',
        ];
    }

    if ($divInfo) {
        return [
            'shift_id' => 0,
            'shift_nama' => 'Divisi ' . $divInfo['nama'],
            'jam_masuk' => $divInfo['jam_masuk'],
            'jam_pulang' => $divInfo['jam_pulang'],
            'toleransi_menit' => (int) $divInfo['toleransi_menit'],
            'warna_kartu' => $divInfo['warna_kartu'] ?: '#0A88C9',
            'kode_divisi' => $divInfo['kode_unik'] ?: '',
            'sumber' => 'divisi',
        ];
    }

    return [
        'shift_id' => 0,
        'shift_nama' => $timClean !== '' ? 'Divisi ' . $timClean : 'Reguler Kantor',
        'jam_masuk' => (string) ($org['jam_masuk'] ?? '08:00'),
        'jam_pulang' => (string) ($org['jam_pulang'] ?? '17:00'),
        'toleransi_menit' => $defTol,
        'warna_kartu' => '#0A88C9',
        'kode_divisi' => '',
        'sumber' => 'kantor',
    ];
}

function mode_options(): array
{
    return ['wfo', 'wfh', 'on_call', 'piket', 'ijin'];
}

/**
 * Validasi koordinat GPS di sisi peladen. Peladen TIDAK mempercayai klaim
 * jarak dari perangkat: koordinat dihitung ulang terhadap lokasi kantor
 * memakai Haversine. Mengembalikan lat, lng, akurasi, dan jarak (meter).
 */
function resolve_gps(array $org, $latRaw, $lngRaw, $akurasiRaw, bool $palsu, string $mode = 'wfo'): array
{
    $kosong = ['lat' => null, 'lng' => null, 'akurasi' => null, 'jarak' => null];
    $isWfh = $mode === 'wfh';
    $wajib = !$isWfh && (int) ($org['wajib_gps'] ?? 0) === 1;
    $radius = $isWfh ? 0 : (int) ($org['radius_masuk_m'] ?? 0);
    $adaKoordinat = $latRaw !== null && $latRaw !== '' && $lngRaw !== null && $lngRaw !== '';

    if (!$wajib && $radius === 0 && !$adaKoordinat) {
        return $kosong;
    }
    if (!$adaKoordinat) {
        json_error('Lokasi GPS wajib diaktifkan untuk absen. Izinkan akses lokasi di peramban lalu coba lagi.', 422);
    }

    $lat = (float) $latRaw;
    $lng = (float) $lngRaw;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        json_error('Koordinat lokasi di luar rentang yang valid.', 422);
    }
    if (abs($lat) < 0.0000001 && abs($lng) < 0.0000001) {
        json_error('Koordinat lokasi tidak valid (0,0). Nyalakan GPS perangkat Anda.', 422);
    }

    $akurasi = ($akurasiRaw === null || $akurasiRaw === '') ? null : (int) $akurasiRaw;
    if ($akurasi !== null && ($akurasi < 0 || $akurasi > 100000)) {
        json_error('Nilai akurasi GPS tidak wajar.', 422);
    }

    if ($palsu) {
        json_error('Terdeteksi lokasi tidak asli (kemungkinan GPS palsu). Nonaktifkan aplikasi mock location lalu coba lagi.', 422);
    }

    $jarak = null;
    $oLat = $org['lat_kantor'] ?? null;
    $oLng = $org['lng_kantor'] ?? null;
    if ($oLat !== null && $oLat !== '' && $oLng !== null && $oLng !== '') {
        $jarak = haversine_meter((float) $oLat, (float) $oLng, $lat, $lng);
    }

    if ($radius > 0) {
        if ($jarak === null) {
            json_error('Lokasi kantor belum dikonfigurasi admin, sehingga radius tidak dapat diverifikasi.', 422);
        }
        $batasAkurasi = max(GPS_AKURASI_MAKS_M, $radius);
        if ($akurasi !== null && $akurasi > $batasAkurasi) {
            json_error("Sinyal GPS lemah (akurasi ±{$akurasi} m). Keluar ke area terbuka lalu coba lagi.", 422);
        }
        if ($jarak > $radius) {
            json_error("Anda berada {$jarak} m dari kantor — di luar radius {$radius} m yang diizinkan.", 422);
        }
    }

    return ['lat' => $lat, 'lng' => $lng, 'akurasi' => $akurasi, 'jarak' => $jarak];
}

function sign_qr_token(string $base): string
{
    $sig = strtoupper(substr(hash_hmac('sha256', strtoupper($base), QR_HMAC_SECRET), 0, 8));
    return strtoupper($base) . '-' . $sig;
}

function verify_signed_qr_format(string $code): bool
{
    $upper = strtoupper(trim($code));
    if (preg_match('/^(KTK-[0-9A-F]{12})-([0-9A-F]{8})$/', $upper, $m)) {
        $expected = strtoupper(substr(hash_hmac('sha256', $m[1], QR_HMAC_SECRET), 0, 8));
        return hash_equals($expected, $m[2]);
    }
    return true;
}

function generate_personal_qr(PDO $pdo): string
{
    $chk = $pdo->prepare('SELECT id FROM karyawan WHERE kode_qr = ?');
    do {
        $base = 'KTK-' . strtoupper(bin2hex(random_bytes(6)));
        $code = sign_qr_token($base);
        $chk->execute([$code]);
    } while ($chk->fetch());
    return $code;
}

function last_qr_error(?string $set = null): ?array
{
    static $err = null;
    if ($set !== null) {
        if ($set === '__clear__') {
            $err = null;
        } else {
            $err = ['msg' => $set];
        }
    }
    return $err;
}

function resolve_personal_qr(string $kodeQr): ?array
{
    last_qr_error('__clear__');
    $clean = clean_text($kodeQr, 64);
    if ($clean === '' || !verify_signed_qr_format($clean)) {
        return null;
    }
    $stmt = db()->prepare("SELECT u.*, k.id AS karyawan_id, k.kode_qr, k.status_kartu, k.alasan_cabut, k.diterbitkan_pada, k.terakhir_dipakai
        FROM karyawan k
        JOIN pengguna u ON u.id = k.legacy_pengguna_id
        WHERE UPPER(k.kode_qr) = UPPER(?)
          AND u.peran = 'karyawan'
        LIMIT 1");
    $stmt->execute([$clean]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (($row['status_kartu'] ?? 'aktif') === 'dicabut') {
        last_qr_error('Kartu QR ini telah dicabut oleh Admin. Hubungi Admin untuk mendapatkan kartu baru.');
        return null;
    }
    if (($row['tipe'] ?? 'karyawan') === 'magang' && !empty($row['tgl_selesai']) && date('Y-m-d') > $row['tgl_selesai']) {
        $pdo = db();
        $pdo->prepare("UPDATE karyawan SET status_kartu = 'kedaluwarsa', aktif = 0 WHERE id = ?")->execute([(int) $row['karyawan_id']]);
        $pdo->prepare('UPDATE pengguna SET aktif = 0 WHERE id = ?')->execute([(int) $row['id']]);
        write_audit((int) $row['id'], (int) $row['organisasi_id'], 'magang_kedaluwarsa', "auto nonaktif > {$row['tgl_selesai']}");
        last_qr_error("Masa berlaku kartu magang berakhir pada {$row['tgl_selesai']}. Hubungi Admin jika magang diperpanjang.");
        return null;
    }
    if ((int) $row['aktif'] !== 1 || ($row['status_kartu'] ?? 'aktif') !== 'aktif') {
        return null;
    }
    $kActive = db()->prepare('SELECT aktif FROM karyawan WHERE id = ?');
    $kActive->execute([(int) $row['karyawan_id']]);
    if ((int) $kActive->fetchColumn() !== 1) {
        return null;
    }
    return $row;
}

function portrait_signed_url(int $userId, ?string $fotoFile): ?string
{
    if (!$fotoFile) {
        return null;
    }
    $sig = hash_hmac('sha256', "portrait:{$userId}:{$fotoFile}", QR_HMAC_SECRET);
    return "media.php?p={$userId}&sig={$sig}";
}

function current_device_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $devId = (string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? '');
    return substr(hash('sha256', "{$ip}|{$ua}|{$devId}"), 0, 24);
}

function detect_checkin_anomalies(PDO $pdo, array $user, array $jadwal, array $gps, string $deviceHash, string $mode, string $jamNow): string
{
    $flags = [];
    if ($deviceHash !== '') {
        // Deteksi titip absen bila perangkat yang sama memindai anggota berbeda dalam jeda < 5 menit
        $windowSince = date('Y-m-d H:i:s', time() - 300);
        $st = $pdo->prepare('SELECT COUNT(DISTINCT pengguna_id) FROM absensi WHERE tanggal = ? AND device_hash = ? AND pengguna_id <> ? AND dibuat_pada >= ?');
        $st->execute([date('Y-m-d'), $deviceHash, (int) $user['id'], $windowSince]);
        if ((int) $st->fetchColumn() > 0) {
            $flags[] = 'multi_perangkat';
        }
    }
    $isOvernight = $jadwal['jam_pulang'] < $jadwal['jam_masuk'];
    if (!$isOvernight) {
        $diffMin = (int) round((strtotime(date('Y-m-d') . ' ' . $jamNow) - strtotime(date('Y-m-d') . ' ' . $jadwal['jam_masuk'])) / 60);
        if ($diffMin < -180 || $diffMin > 240) {
            $flags[] = 'luar_jam_wajar';
        }
    }
    if ($mode === 'wfo' && $gps['lat'] === null) {
        $flags[] = 'gps_tidak_terbaca';
    }
    return implode(',', $flags);
}

function do_checkin(array $user, ?string $barcode, ?string $foto, $lat = null, $lng = null, $akurasi = null, ?string $mode = null, bool $palsu = false, ?string $kegiatan = null, ?int $karyawanId = null, bool $bypassOfficeBarcode = false, ?int $shiftId = null): array
{
    $org = get_org((int) $user['organisasi_id']);
    $mode = in_array((string) $mode, mode_options(), true) ? (string) $mode : 'wfo';
    $kegiatanClean = clean_text($kegiatan, 500);
    $kegLen = function_exists('mb_strlen') ? mb_strlen($kegiatanClean, 'UTF-8') : strlen($kegiatanClean);
    if ($mode === 'wfh' && $kegLen < 3) {
        json_error('Catatan kegiatan wajib diisi minimal 3 karakter untuk absen WFH / Liputan.', 422);
    }
    if (!$bypassOfficeBarcode && $mode !== 'wfh' && !valid_barcode($org, $barcode)) {
        json_error('Barcode tidak dikenali. Pastikan Anda memindai barcode lokasi yang benar.', 422);
    }
    if ((int) $org['wajib_foto'] && empty($foto)) {
        json_error('Foto wajib diambil untuk absen masuk.', 422);
    }
    $jadwal = resolve_employee_schedule($org, (string) ($user['tim'] ?? ''), $shiftId);
    $gps = resolve_gps($org, $lat, $lng, $akurasi, $palsu, $mode);
    $fotoName = save_photo($foto, 'masuk');
    $deviceHash = current_device_hash();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = DB_DRIVER === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?' . $lock);
        $stmt->execute([$user['id'], date('Y-m-d')]);
        $existing = $stmt->fetch() ?: null;
        if ($existing && $existing['jam_masuk']) {
            $pdo->rollBack();
            json_error('Anda sudah melakukan absen masuk hari ini.', 409);
        }

        // Zona waktu & jam diambil dari peladen, bukan dari perangkat klien.
        $now = date('H:i:s');
        $toleransiAktif = (int) ($jadwal['toleransi_menit'] ?? $org['toleransi_menit'] ?? 15);
        $status = hitung_status_masuk_jadwal($jadwal['jam_masuk'], $toleransiAktif, $now);
        $anomali = detect_checkin_anomalies($pdo, $user, $jadwal, $gps, $deviceHash, $mode, $now);
        $ts = date('Y-m-d H:i:s');
        $kodeLokasi = $mode === 'wfh' ? '' : clean_text($barcode ?: ($user['kode_qr'] ?? ''), 100);
        if ($existing) {
            $upd = $pdo->prepare("UPDATE absensi SET karyawan_id = COALESCE(?, karyawan_id), status = ?, jam_masuk = ?, foto_masuk = ?, barcode_masuk = ?, lat_masuk = ?, lng_masuk = ?, akurasi_masuk = ?, jarak_masuk_m = ?, mode = ?, kegiatan = ?, shift_nama = ?, jam_jadwal_masuk = ?, jam_jadwal_pulang = ?, device_hash = ?, anomali = ?, approval_status = 'disetujui', catatan = ?, diperbarui_pada = ? WHERE id = ?");
            $upd->execute([$karyawanId, $status, $now, $fotoName, $kodeLokasi, $gps['lat'], $gps['lng'], $gps['akurasi'], $gps['jarak'], $mode, $kegiatanClean, $jadwal['shift_nama'], $jadwal['jam_masuk'], $jadwal['jam_pulang'], $deviceHash, $anomali, '', $ts, $existing['id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO absensi (karyawan_id, pengguna_id, tanggal, status, jam_masuk, foto_masuk, barcode_masuk, lat_masuk, lng_masuk, akurasi_masuk, jarak_masuk_m, mode, kegiatan, shift_nama, jam_jadwal_masuk, jam_jadwal_pulang, device_hash, anomali, approval_status, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'disetujui', ?, ?)");
            $ins->execute([$karyawanId, $user['id'], date('Y-m-d'), $status, $now, $fotoName, $kodeLokasi, $gps['lat'], $gps['lng'], $gps['akurasi'], $gps['jarak'], $mode, $kegiatanClean, $jadwal['shift_nama'], $jadwal['jam_masuk'], $jadwal['jam_pulang'], $deviceHash, $anomali, $ts, $ts]);
        }
        if ($karyawanId) {
            $pdo->prepare('UPDATE karyawan SET terakhir_dipakai = ? WHERE id = ?')->execute([$ts, $karyawanId]);
        }
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'check_in', "masuk {$now} status={$status} jarak=" . ($gps['jarak'] ?? '-') . "m mode={$mode} shift={$jadwal['shift_nama']}" . ($anomali ? " anomali={$anomali}" : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'status' => $status,
        'jam_masuk' => $now,
        'foto' => $fotoName,
        'jarak' => $gps['jarak'],
        'mode' => $mode,
        'kegiatan' => $kegiatanClean,
        'shift_nama' => $jadwal['shift_nama'],
        'jam_jadwal_masuk' => $jadwal['jam_masuk'],
        'jam_jadwal_pulang' => $jadwal['jam_pulang'],
        'anomali' => $anomali,
    ];
}

function do_checkout(array $user, ?string $barcode, ?string $foto, $lat = null, $lng = null, $akurasi = null, ?string $mode = null, bool $palsu = false, ?string $kegiatan = null, ?int $karyawanId = null, bool $bypassOfficeBarcode = false): array
{
    $org = get_org((int) $user['organisasi_id']);
    $existingPre = today_record((int) $user['id']);
    $modeEfektif = in_array((string) $mode, mode_options(), true)
        ? (string) $mode
        : (($existingPre['mode'] ?? '') ?: 'wfo');
    $kegiatanClean = clean_text($kegiatan, 500);
    $kegLen = function_exists('mb_strlen') ? mb_strlen($kegiatanClean, 'UTF-8') : strlen($kegiatanClean);
    if ($modeEfektif === 'wfh' && $kegLen < 3) {
        json_error('Ringkasan kegiatan wajib diisi minimal 3 karakter untuk absen pulang WFH / Liputan.', 422);
    }
    if (!$bypassOfficeBarcode && $modeEfektif !== 'wfh' && !valid_barcode($org, $barcode)) {
        json_error('Barcode tidak dikenali. Pastikan Anda memindai barcode lokasi yang benar.', 422);
    }
    if ((int) $org['wajib_foto'] && empty($foto)) {
        json_error('Foto wajib diambil untuk absen pulang.', 422);
    }
    $gps = resolve_gps($org, $lat, $lng, $akurasi, $palsu, $modeEfektif);
    $fotoName = save_photo($foto, 'pulang');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = DB_DRIVER === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?' . $lock);
        $stmt->execute([$user['id'], date('Y-m-d')]);
        $existing = $stmt->fetch() ?: null;
        if (!$existing || !$existing['jam_masuk']) {
            $pdo->rollBack();
            json_error('Anda belum melakukan absen masuk hari ini.', 409);
        }
        if ($existing['jam_pulang']) {
            $pdo->rollBack();
            json_error('Anda sudah melakukan absen pulang hari ini.', 409);
        }

        $now = date('H:i:s');
        $isOvernight = !empty($existing['jam_jadwal_masuk']) && !empty($existing['jam_jadwal_pulang'])
            && $existing['jam_jadwal_pulang'] < $existing['jam_jadwal_masuk'];
        if (!$isOvernight && strtotime($now) < strtotime((string) $existing['jam_masuk'])) {
            $pdo->rollBack();
            json_error('Jam pulang tidak boleh lebih awal dari jam masuk.', 422);
        }
        $kegSave = $kegiatanClean !== '' ? $kegiatanClean : (string) ($existing['kegiatan'] ?? '');
        $kodeLokasi = $modeEfektif === 'wfh' ? '' : clean_text($barcode ?: ($user['kode_qr'] ?? ''), 100);
        $tsOut = date('Y-m-d H:i:s');
        $upd = $pdo->prepare('UPDATE absensi SET karyawan_id = COALESCE(?, karyawan_id), jam_pulang = ?, foto_pulang = ?, barcode_pulang = ?, lat_pulang = ?, lng_pulang = ?, akurasi_pulang = ?, jarak_pulang_m = ?, mode = ?, kegiatan = ?, diperbarui_pada = ? WHERE id = ?');
        $upd->execute([$karyawanId, $now, $fotoName, $kodeLokasi, $gps['lat'], $gps['lng'], $gps['akurasi'], $gps['jarak'], $modeEfektif, $kegSave, $tsOut, $existing['id']]);
        if ($karyawanId) {
            $pdo->prepare('UPDATE karyawan SET terakhir_dipakai = ? WHERE id = ?')->execute([$tsOut, $karyawanId]);
        }
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'check_out', "pulang {$now} jarak=" . ($gps['jarak'] ?? '-') . "m mode={$modeEfektif}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['jam_pulang' => $now, 'foto' => $fotoName, 'jarak' => $gps['jarak'], 'mode' => $modeEfektif, 'kegiatan' => $kegSave];
}

function do_izin(array $user, string $jenis, string $mulai, string $sampai, string $catatan, ?string $foto, bool $autoApprove = false, ?int $actorId = null): array
{
    $org = get_org((int) $user['organisasi_id']);
    $jenis = strtolower(trim($jenis));
    if (!in_array($jenis, ['izin', 'sakit', 'dinas_luar'], true)) {
        json_error('Jenis pengajuan tidak valid.', 422);
    }
    $dbStatus = $jenis === 'sakit' ? 'sakit' : 'izin';
    $approvalStatus = $autoApprove ? 'disetujui' : 'menunggu';
    if (!$mulai || !$sampai) {
        json_error('Tanggal mulai dan selesai wajib diisi.', 422);
    }
    if (!valid_iso_date($mulai) || !valid_iso_date($sampai)) {
        json_error('Format tanggal mulai/selesai tidak valid.', 422);
    }
    if (strtotime($sampai) < strtotime($mulai)) {
        json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
    }
    $diffDays = (int) round((strtotime($sampai) - strtotime($mulai)) / 86400);
    if ($diffDays > 30) {
        json_error('Rentang pengajuan maksimal 30 hari.', 422);
    }
    $catatan = clean_text($catatan, 500);
    if ($catatan === '') {
        json_error('Keterangan / alasan wajib diisi.', 422);
    }
    $fotoName = save_photo($foto, $jenis);

    $pdo = db();
    $stKid = $pdo->prepare('SELECT id FROM karyawan WHERE legacy_pengguna_id = ? LIMIT 1');
    $stKid->execute([(int) $user['id']]);
    $karyawanIdVal = ($tmpKid = $stKid->fetchColumn()) ? (int) $tmpKid : null;

    $ts = date('Y-m-d H:i:s');
    $insert = $pdo->prepare('INSERT INTO absensi (karyawan_id, pengguna_id, tanggal, status, jenis_izin, approval_status, diproses_oleh, catatan, foto_masuk, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $update = $pdo->prepare('UPDATE absensi SET karyawan_id = COALESCE(karyawan_id, ?), status = ?, jenis_izin = ?, approval_status = ?, diproses_oleh = ?, catatan = ?, foto_masuk = COALESCE(?, foto_masuk), diperbarui_pada = ? WHERE id = ?');
    $find = $pdo->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?');

    $cursor = strtotime($mulai);
    $end = strtotime($sampai);
    $dates = [];
    while ($cursor <= $end) {
        $date = date('Y-m-d', $cursor);
        if (is_hari_kerja($org, $date)) {
            $find->execute([$user['id'], $date]);
            $row = $find->fetch();
            if (!$autoApprove && $row && (!empty($row['dikoreksi_oleh']) || !empty($row['jam_masuk']))) {
                json_error("Tanggal {$date} sudah tercatat hadir atau dikoreksi HR. Hubungi admin untuk perubahan.", 422);
            }
            $dates[] = [$date, $row];
        }
        $cursor = strtotime('+1 day', $cursor);
    }
    if (!$dates) {
        json_error('Tidak ada hari kerja aktif pada rentang tanggal yang dipilih.', 422);
    }
    $procBy = $autoApprove ? ($actorId ?: (int) $user['id']) : null;
    foreach ($dates as [$date, $row]) {
        if ($row) {
            $update->execute([$karyawanIdVal, $dbStatus, $jenis, $approvalStatus, $procBy, $catatan, $fotoName, $ts, $row['id']]);
        } else {
            $insert->execute([$karyawanIdVal, $user['id'], $date, $dbStatus, $jenis, $approvalStatus, $procBy, $catatan, $fotoName, $ts, $ts]);
        }
    }
    $count = count($dates);
    write_audit((int) $user['id'], (int) $user['organisasi_id'], 'ajukan_' . $jenis, "{$mulai}..{$sampai} ({$count} hari) status={$approvalStatus}");

    return ['hari' => $count, 'jenis' => $jenis, 'approval_status' => $approvalStatus];
}

function do_koreksi(array $actor, int $penggunaId, string $tanggal, string $status, string $catatan, ?string $jamMasuk, ?string $jamPulang): array
{
    $catatan = clean_text($catatan, 500);
    if ($catatan === '') {
        json_error('Alasan koreksi wajib diisi.', 422);
    }
    if (!valid_iso_date($tanggal)) {
        json_error('Format tanggal tidak valid.', 422);
    }
    if ($jamMasuk !== null && $jamMasuk !== '' && !valid_time_hm($jamMasuk)) {
        json_error('Format jam masuk tidak valid.', 422);
    }
    if ($jamPulang !== null && $jamPulang !== '' && !valid_time_hm($jamPulang)) {
        json_error('Format jam pulang tidak valid.', 422);
    }
    if (!in_array($status, status_options(), true)) {
        json_error('Status tidak valid.', 422);
    }
    $stmt = db()->prepare('SELECT * FROM pengguna WHERE id = ? AND organisasi_id = ?');
    $stmt->execute([$penggunaId, $actor['organisasi_id']]);
    if (!$stmt->fetch()) {
        json_error('Karyawan tidak ditemukan pada organisasi ini.', 404);
    }

    $pdo = db();
    $stKid = $pdo->prepare('SELECT id FROM karyawan WHERE legacy_pengguna_id = ? LIMIT 1');
    $stKid->execute([$penggunaId]);
    $karyawanIdVal = ($tmpKid = $stKid->fetchColumn()) ? (int) $tmpKid : null;

    $ts = date('Y-m-d H:i:s');
    $find = $pdo->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?');
    $find->execute([$penggunaId, $tanggal]);
    $row = $find->fetch();

    $jm = $jamMasuk ?: ($row['jam_masuk'] ?? null);
    $jp = $jamPulang ?: ($row['jam_pulang'] ?? null);
    if (in_array($status, ['izin', 'sakit', 'alpa'], true)) {
        $jm = null;
        $jp = null;
    }

    if ($row) {
        $u = $pdo->prepare("UPDATE absensi SET karyawan_id = COALESCE(karyawan_id, ?), status = ?, jam_masuk = ?, jam_pulang = ?, approval_status = 'disetujui', catatan = ?, dikoreksi_oleh = ?, diperbarui_pada = ? WHERE id = ?");
        $u->execute([$karyawanIdVal, $status, $jm, $jp, $catatan, $actor['id'], $ts, $row['id']]);
    } else {
        $i = $pdo->prepare("INSERT INTO absensi (karyawan_id, pengguna_id, tanggal, status, jam_masuk, jam_pulang, approval_status, catatan, dikoreksi_oleh, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, 'disetujui', ?, ?, ?, ?)");
        $i->execute([$karyawanIdVal, $penggunaId, $tanggal, $status, $jm, $jp, $catatan, $actor['id'], $ts, $ts]);
    }
    write_audit((int) $actor['id'], (int) $actor['organisasi_id'], 'koreksi_absensi', "pengguna={$penggunaId} {$tanggal} -> {$status}: {$catatan}");

    return ['tanggal' => $tanggal, 'status' => $status];
}

function jadwal_shift_row(array $r, string $defaultMasuk = '08:00'): string
{
    $catatan = (string) ($r['catatan'] ?? '');
    if (preg_match('/Shift:\s*(Pagi|Siang|Malam|Middle)/i', $catatan, $m)) {
        return [
            'pagi' => '06:00',
            'siang' => '14:00',
            'malam' => '22:00',
            'middle' => '10:00',
        ][strtolower($m[1])] ?? $defaultMasuk;
    }
    return $defaultMasuk;
}

function hitung_terlambat_row(array $r, string $defaultJamMasuk, int $toleransi): ?int
{
    if (empty($r['jam_masuk'])) {
        return null;
    }
    $jamJadwal = !empty($r['jam_jadwal_masuk']) ? (string) $r['jam_jadwal_masuk'] : jadwal_shift_row($r, $defaultJamMasuk);
    $jadwalTs = strtotime($r['tanggal'] . ' ' . $jamJadwal);
    $masukTs = strtotime($r['tanggal'] . ' ' . $r['jam_masuk']);
    $hJadwal = (int) substr($jamJadwal, 0, 2);
    $hMasuk = (int) substr((string) $r['jam_masuk'], 0, 2);
    if ($hJadwal >= 20 && $hMasuk < 12) {
        $masukTs += 86400;
    }
    $late = (int) round(($masukTs - $jadwalTs) / 60);
    return max(0, $late - $toleransi);
}

function hitung_durasi_row(?string $jamMasuk, ?string $jamPulang): ?int
{
    if (!$jamMasuk || !$jamPulang) {
        return null;
    }
    $m = strtotime($jamMasuk);
    $p = strtotime($jamPulang);
    if ($p < $m) {
        $p += 86400;
    }
    $dur = max(0, (int) round(($p - $m) / 60));
    return min(1440, $dur);
}

function hitung_hari_kerja_periode(array $org, string $start, string $end): int
{
    $batasAkhir = min($end, date('Y-m-d'));
    $cur = strtotime($start);
    $endTs = strtotime($batasAkhir);
    if ($cur === false || $endTs === false || $cur > $endTs) {
        return 0;
    }
    $count = 0;
    while ($cur <= $endTs) {
        if (is_hari_kerja($org, date('Y-m-d', $cur))) {
            $count++;
        }
        $cur = strtotime('+1 day', $cur);
    }
    return $count;
}

/**
 * Fitur K-Means (bagian 7 PRD).
 * 1) rata-rata menit terlambat pada hari hadir
 * 2) persentase hari tidak hadir
 * 3) rata-rata jam kerja per hari hadir
 */
function rekap_data(int $orgId, string $start, string $end, ?string $tim, bool $onlyEligible = false, int $minHari = 10, ?string $tipe = null): array
{
    $org = get_org($orgId);
    $hariKerjaDefault = $org ? hitung_hari_kerja_periode($org, $start, $end) : 0;

    $params = [$orgId];
    $sql = "SELECT u.id, u.nama, u.tim, u.jabatan, u.peran, u.tipe, u.kampus, u.tgl_mulai, u.tgl_selesai, u.no_hp
            FROM pengguna u
            WHERE u.organisasi_id = ? AND u.peran = 'karyawan' AND u.aktif = 1";
    if ($tim) {
        $sql .= ' AND u.tim = ?';
        $params[] = $tim;
    }
    if ($tipe && in_array($tipe, ['karyawan', 'magang'], true)) {
        $sql .= ' AND u.tipe = ?';
        $params[] = $tipe;
    }
    $sql .= ' ORDER BY u.nama';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    $ab = db()->prepare("SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal BETWEEN ? AND ? AND COALESCE(approval_status, 'disetujui') <> 'ditolak' ORDER BY tanggal ASC");
    $result = [];
    foreach ($employees as $emp) {
        $ab->execute([$emp['id'], $start, $end]);
        $rows = $ab->fetchAll();

        $hadirRows = [];
        $lateMinutes = 0;
        $workMinutes = 0;
        $notPresent = 0;
        $hariTercatat = 0;
        $hadirTepat = 0;
        $hadirTerlambat = 0;
        $minTgl = null;
        $maxTgl = null;

        foreach ($rows as $r) {
            if ($minTgl === null || $r['tanggal'] < $minTgl) {
                $minTgl = $r['tanggal'];
            }
            if ($maxTgl === null || $r['tanggal'] > $maxTgl) {
                $maxTgl = $r['tanggal'];
            }
            if (!empty($r['jam_masuk']) || in_array($r['status'], ['hadir', 'terlambat'], true)) {
                $hariTercatat++;
                $hadirRows[] = $r;
                if (!empty($r['jam_masuk'])) {
                    $late = hitung_terlambat_row($r, org_jam_masuk($orgId), (int) org_toleransi($orgId)) ?? 0;
                    $lateMinutes += $late;
                    if ($late > 0 || $r['status'] === 'terlambat') {
                        $hadirTerlambat++;
                    } else {
                        $hadirTepat++;
                    }
                    $dur = hitung_durasi_row($r['jam_masuk'], $r['jam_pulang']);
                    if ($dur !== null) {
                        $workMinutes += $dur;
                    }
                } elseif ($r['status'] === 'terlambat') {
                    $hadirTerlambat++;
                } else {
                    $hadirTepat++;
                }
            } elseif (in_array($r['status'], ['izin', 'sakit', 'alpa'], true)) {
                $notPresent++;
            }
        }

        // Hitung hari kerja sesuai masa aktif karyawan dalam rentang terpilih
        $hariKerjaEmp = $hariKerjaDefault;
        if ($org && $minTgl !== null && $maxTgl !== null) {
            $effStart = max($start, $minTgl);
            $effEnd = min($end, $maxTgl);
            $hariKerjaEmp = max($hariTercatat, hitung_hari_kerja_periode($org, $effStart, $effEnd));
        }

        if ($hariKerjaEmp > 0 && ($hariTercatat + $notPresent) < $hariKerjaEmp) {
            $notPresent = max($notPresent, $hariKerjaEmp - $hariTercatat);
        }

        $totalHari = $hariTercatat + $notPresent;
        $avgLate = $hariTercatat > 0 ? $lateMinutes / $hariTercatat : 0;
        $pctAbsent = $totalHari > 0 ? ($notPresent / $totalHari) * 100 : 0;
        $avgWorkHours = $hariTercatat > 0 ? ($workMinutes / $hariTercatat) / 60 : 0;

        $eligible = $hariTercatat >= $minHari;
        if ($onlyEligible && !$eligible) {
            continue;
        }

        $result[] = [
            'id' => (int) $emp['id'],
            'nama' => $emp['nama'],
            'tim' => $emp['tim'],
            'jabatan' => $emp['jabatan'] ?? '',
            'tipe' => $emp['tipe'] ?: 'karyawan',
            'kampus' => $emp['kampus'] ?? '',
            'tgl_mulai' => $emp['tgl_mulai'] ?? null,
            'tgl_selesai' => $emp['tgl_selesai'] ?? null,
            'hari_tercatat' => $hariTercatat,
            'hadir_tepat' => $hadirTepat,
            'hadir_terlambat' => $hadirTerlambat,
            'hari_tidak_hadir' => $notPresent,
            'total_hari' => $totalHari,
            'rata_terlambat' => round($avgLate, 2),
            'persen_tidak_hadir' => round($pctAbsent, 2),
            'persen_hadir' => $totalHari > 0 ? round(($hariTercatat / $totalHari) * 100, 1) : 0,
            'rata_jam_kerja' => round($avgWorkHours, 2),
            'periode_aktif' => ($minTgl && $maxTgl) ? "{$minTgl} s/d {$maxTgl}" : '—',
            'eligible' => $eligible,
        ];
    }
    return $result;
}

function org_jam_masuk(int $orgId): string
{
    static $cache = [];
    if (!isset($cache[$orgId])) {
        $cache[$orgId] = get_org($orgId)['jam_masuk'] ?? '08:00';
    }
    return $cache[$orgId];
}

function org_toleransi(int $orgId): int
{
    static $cache = [];
    if (!isset($cache[$orgId])) {
        $cache[$orgId] = (int) (get_org($orgId)['toleransi_menit'] ?? 0);
    }
    return $cache[$orgId];
}

function dashboard_summary(int $orgId): array
{
    $today = date('Y-m-d');
    $counts = ['hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'belum' => 0, 'wfo' => 0, 'wfh' => 0];

    $stmt = db()->prepare('SELECT COUNT(*) FROM pengguna WHERE organisasi_id = ? AND peran = ? AND aktif = 1');
    $stmt->execute([$orgId, 'karyawan']);
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare("SELECT a.status, COUNT(*) c FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id WHERE u.organisasi_id = ? AND a.tanggal = ? AND COALESCE(a.approval_status, 'disetujui') <> 'ditolak' GROUP BY a.status");
    $stmt->execute([$orgId, $today]);
    $hadirSet = 0;
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['c'];
        if (in_array($row['status'], ['hadir', 'terlambat'], true)) {
            $hadirSet += (int) $row['c'];
        }
    }
    $counts['belum'] = max(0, $total - $hadirSet - $counts['izin'] - $counts['sakit'] - $counts['alpa']);
    $counts['total_karyawan'] = $total;

    $stmt = db()->prepare("SELECT a.mode, COUNT(*) c FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id WHERE u.organisasi_id = ? AND a.tanggal = ? AND COALESCE(a.approval_status, 'disetujui') <> 'ditolak' AND a.status IN ('hadir', 'terlambat') GROUP BY a.mode");
    $stmt->execute([$orgId, $today]);
    foreach ($stmt->fetchAll() as $row) {
        if (($row['mode'] ?? '') === 'wfh') {
            $counts['wfh'] += (int) $row['c'];
        } else {
            $counts['wfo'] += (int) $row['c'];
        }
    }

    // Tren 30 hari
    $start = date('Y-m-d', strtotime('-29 days'));
    $stmt = db()->prepare("SELECT a.tanggal,
        SUM(CASE WHEN a.status IN ('hadir','terlambat') THEN 1 ELSE 0 END) hadir,
        SUM(CASE WHEN a.status = 'terlambat' THEN 1 ELSE 0 END) terlambat,
        SUM(CASE WHEN a.status IN ('izin','sakit') AND COALESCE(a.approval_status, 'disetujui') <> 'ditolak' THEN 1 ELSE 0 END) izin_sakit,
        SUM(CASE WHEN a.status = 'alpa' THEN 1 ELSE 0 END) alpa
        FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id
        WHERE u.organisasi_id = ? AND a.tanggal BETWEEN ? AND ? AND COALESCE(a.approval_status, 'disetujui') <> 'ditolak'
        GROUP BY a.tanggal ORDER BY a.tanggal");
    $stmt->execute([$orgId, $start, $today]);
    $tren = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT u.nama, u.tim, u.tipe, a.status, a.jam_masuk, a.catatan, a.anomali FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id
        WHERE u.organisasi_id = ? AND a.tanggal = ? AND (a.status IN ('terlambat','alpa') OR (a.anomali IS NOT NULL AND a.anomali <> ''))
        ORDER BY a.status DESC, a.jam_masuk DESC LIMIT 12");
    $stmt->execute([$orgId, $today]);
    $perluDicek = $stmt->fetchAll();

    $stAnom = db()->prepare("SELECT COUNT(*) FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id WHERE u.organisasi_id = ? AND a.tanggal = ? AND a.anomali IS NOT NULL AND a.anomali <> ''");
    $stAnom->execute([$orgId, $today]);
    $counts['anomali'] = (int) $stAnom->fetchColumn();

    $stWait = db()->prepare("SELECT COUNT(*) FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id WHERE u.organisasi_id = ? AND a.approval_status = 'menunggu'");
    $stWait->execute([$orgId]);
    $counts['izin_menunggu'] = (int) $stWait->fetchColumn();

    return ['hari_ini' => $counts, 'tren' => $tren, 'perlu_dicek' => $perluDicek];
}

function riwayat_karyawan(int $userId, int $limit = 7): array
{
    $stmt = db()->prepare('SELECT * FROM absensi WHERE pengguna_id = ? ORDER BY tanggal DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function ringkasan_bulanan(int $userId): array
{
    $awal = date('Y-m-01');
    $akhir = date('Y-m-t');
    $stmt = db()->prepare("SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal BETWEEN ? AND ? AND COALESCE(approval_status, 'disetujui') <> 'ditolak'");
    $stmt->execute([$userId, $awal, $akhir]);
    $rows = $stmt->fetchAll();
    $out = ['hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
    foreach ($rows as $r) {
        $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
    }
    $out['tidak_hadir'] = $out['izin'] + $out['sakit'] + $out['alpa'];
    return $out;
}
