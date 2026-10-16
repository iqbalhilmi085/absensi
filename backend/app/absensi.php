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

function is_hari_kerja(array $org, string $date): bool
{
    $dow = (int) date('N', strtotime($date));
    $list = array_map('intval', explode(',', (string) $org['hari_kerja']));
    return in_array($dow, $list, true);
}

function hitung_status_masuk(array $org, string $jamMasuk): string
{
    $batas = strtotime(date('Y-m-d') . ' ' . $org['jam_masuk']) + ((int) $org['toleransi_menit'] * 60);
    return strtotime(date('Y-m-d') . ' ' . $jamMasuk) > $batas ? 'terlambat' : 'hadir';
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
function resolve_gps(array $org, $latRaw, $lngRaw, $akurasiRaw, bool $palsu): array
{
    $kosong = ['lat' => null, 'lng' => null, 'akurasi' => null, 'jarak' => null];
    $wajib = (int) ($org['wajib_gps'] ?? 0) === 1;
    $radius = (int) ($org['radius_masuk_m'] ?? 0);
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

function do_checkin(array $user, ?string $barcode, ?string $foto, $lat = null, $lng = null, $akurasi = null, ?string $mode = null, bool $palsu = false): array
{
    $org = get_org((int) $user['organisasi_id']);
    if (!valid_barcode($org, $barcode)) {
        json_error('Barcode tidak dikenali. Pastikan Anda memindai barcode lokasi yang benar.', 422);
    }
    if ((int) $org['wajib_foto'] && empty($foto)) {
        json_error('Foto wajib diambil untuk absen masuk.', 422);
    }
    $mode = in_array((string) $mode, mode_options(), true) ? (string) $mode : 'wfo';
    $gps = resolve_gps($org, $lat, $lng, $akurasi, $palsu);
    $fotoName = save_photo($foto, 'masuk');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Kunci baris untuk mencegah absen ganda pada tanggal yang sama (race condition).
        // SQLite tidak mendukung FOR UPDATE; penguncian database per transaksi tulis sudah cukup.
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
        $status = hitung_status_masuk($org, $now);
        $ts = date('Y-m-d H:i:s');
        $kodeLokasi = clean_text($barcode, 100);
        if ($existing) {
            $upd = $pdo->prepare('UPDATE absensi SET status = ?, jam_masuk = ?, foto_masuk = ?, barcode_masuk = ?, lat_masuk = ?, lng_masuk = ?, akurasi_masuk = ?, jarak_masuk_m = ?, mode = ?, catatan = ?, diperbarui_pada = ? WHERE id = ?');
            $upd->execute([$status, $now, $fotoName, $kodeLokasi, $gps['lat'], $gps['lng'], $gps['akurasi'], $gps['jarak'], $mode, '', $ts, $existing['id']]);
        } else {
            $ins = $pdo->prepare('INSERT INTO absensi (pengguna_id, tanggal, status, jam_masuk, foto_masuk, barcode_masuk, lat_masuk, lng_masuk, akurasi_masuk, jarak_masuk_m, mode, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$user['id'], date('Y-m-d'), $status, $now, $fotoName, $kodeLokasi, $gps['lat'], $gps['lng'], $gps['akurasi'], $gps['jarak'], $mode, $ts, $ts]);
        }
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'check_in', "masuk {$now} status={$status} jarak=" . ($gps['jarak'] ?? '-') . "m mode={$mode}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['status' => $status, 'jam_masuk' => $now, 'foto' => $fotoName, 'jarak' => $gps['jarak'], 'mode' => $mode];
}

function do_checkout(array $user, ?string $barcode, ?string $foto, $lat = null, $lng = null, $akurasi = null, ?string $mode = null, bool $palsu = false, ?string $kegiatan = null): array
{
    $org = get_org((int) $user['organisasi_id']);
    if (!valid_barcode($org, $barcode)) {
        json_error('Barcode tidak dikenali. Pastikan Anda memindai barcode lokasi yang benar.', 422);
    }
    if ((int) $org['wajib_foto'] && empty($foto)) {
        json_error('Foto wajib diambil untuk absen pulang.', 422);
    }
    $mode = in_array((string) $mode, mode_options(), true) ? (string) $mode : 'wfo';
    $gps = resolve_gps($org, $lat, $lng, $akurasi, $palsu);
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
        if (strtotime($now) < strtotime((string) $existing['jam_masuk'])) {
            $pdo->rollBack();
            json_error('Jam pulang tidak boleh lebih awal dari jam masuk.', 422);
        }
        $kegiatan = clean_text($kegiatan, 500);
        $upd = $pdo->prepare('UPDATE absensi SET jam_pulang = ?, foto_pulang = ?, barcode_pulang = ?, lat_pulang = ?, lng_pulang = ?, akurasi_pulang = ?, jarak_pulang_m = ?, mode = ?, kegiatan = ?, diperbarui_pada = ? WHERE id = ?');
        $upd->execute([$now, $fotoName, clean_text($barcode, 100), $gps['lat'], $gps['lng'], $gps['akurasi'], $gps['jarak'], $mode, $kegiatan, date('Y-m-d H:i:s'), $existing['id']]);
        write_audit((int) $user['id'], (int) $user['organisasi_id'], 'check_out', "pulang {$now} jarak=" . ($gps['jarak'] ?? '-') . "m mode={$mode}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['jam_pulang' => $now, 'foto' => $fotoName, 'jarak' => $gps['jarak'], 'mode' => $mode];
}

function do_izin(array $user, string $jenis, string $mulai, string $sampai, string $catatan, ?string $foto, bool $autoApprove = false): array
{
    $org = get_org((int) $user['organisasi_id']);
    $jenis = strtolower(trim($jenis));
    if (!in_array($jenis, ['izin', 'sakit'], true)) {
        json_error('Jenis pengajuan tidak valid.', 422);
    }
    if (!$mulai || !$sampai) {
        json_error('Tanggal mulai dan selesai wajib diisi.', 422);
    }
    if (strtotime($sampai) < strtotime($mulai)) {
        json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
    }
    $diffDays = (int) round((strtotime($sampai) - strtotime($mulai)) / 86400);
    if ($diffDays > 30) {
        json_error('Rentang pengajuan maksimal 30 hari.', 422);
    }
    if ((int) $org['wajib_foto'] && empty($foto)) {
        json_error('Foto bukti wajib dilampirkan untuk pengajuan izin/sakit.', 422);
    }
    $catatan = clean_text($catatan, 500);
    $fotoName = save_photo($foto, $jenis);

    $ts = date('Y-m-d H:i:s');
    $insert = db()->prepare('INSERT INTO absensi (pengguna_id, tanggal, status, catatan, foto_masuk, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $update = db()->prepare('UPDATE absensi SET status = ?, catatan = ?, foto_masuk = COALESCE(?, foto_masuk), diperbarui_pada = ? WHERE id = ?');
    $find = db()->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?');

    $cursor = strtotime($mulai);
    $end = strtotime($sampai);
    $count = 0;
    while ($cursor <= $end) {
        $date = date('Y-m-d', $cursor);
        if (is_hari_kerja($org, $date)) {
            $find->execute([$user['id'], $date]);
            $row = $find->fetch();
            if ($row) {
                $update->execute([$jenis, $catatan, $fotoName, $ts, $row['id']]);
            } else {
                $insert->execute([$user['id'], $date, $jenis, $catatan, $fotoName, $ts, $ts]);
            }
            $count++;
        }
        $cursor = strtotime('+1 day', $cursor);
    }
    write_audit((int) $user['id'], (int) $user['organisasi_id'], 'ajukan_' . $jenis, "{$mulai}..{$sampai} ({$count} hari)");

    return ['hari' => $count, 'jenis' => $jenis];
}

function do_koreksi(array $actor, int $penggunaId, string $tanggal, string $status, string $catatan, ?string $jamMasuk, ?string $jamPulang): array
{
    $catatan = clean_text($catatan, 500);
    if ($catatan === '') {
        json_error('Alasan koreksi wajib diisi.', 422);
    }
    if (!in_array($status, status_options(), true)) {
        json_error('Status tidak valid.', 422);
    }
    $stmt = db()->prepare('SELECT * FROM pengguna WHERE id = ? AND organisasi_id = ?');
    $stmt->execute([$penggunaId, $actor['organisasi_id']]);
    if (!$stmt->fetch()) {
        json_error('Karyawan tidak ditemukan pada organisasi ini.', 404);
    }

    $ts = date('Y-m-d H:i:s');
    $find = db()->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal = ?');
    $find->execute([$penggunaId, $tanggal]);
    $row = $find->fetch();

    $jm = $jamMasuk ?: ($row['jam_masuk'] ?? null);
    $jp = $jamPulang ?: ($row['jam_pulang'] ?? null);
    if (in_array($status, ['izin', 'sakit', 'alpa'], true)) {
        $jm = null;
        $jp = null;
    }

    if ($row) {
        $u = db()->prepare('UPDATE absensi SET status = ?, jam_masuk = ?, jam_pulang = ?, catatan = ?, dikoreksi_oleh = ?, diperbarui_pada = ? WHERE id = ?');
        $u->execute([$status, $jm, $jp, $catatan, $actor['id'], $ts, $row['id']]);
    } else {
        $i = db()->prepare('INSERT INTO absensi (pengguna_id, tanggal, status, jam_masuk, jam_pulang, catatan, dikoreksi_oleh, dibuat_pada, diperbarui_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $i->execute([$penggunaId, $tanggal, $status, $jm, $jp, $catatan, $actor['id'], $ts, $ts]);
    }
    write_audit((int) $actor['id'], (int) $actor['organisasi_id'], 'koreksi_absensi', "pengguna={$penggunaId} {$tanggal} -> {$status}: {$catatan}");

    return ['tanggal' => $tanggal, 'status' => $status];
}

/**
 * Fitur K-Means (bagian 7 PRD).
 * 1) rata-rata menit terlambat pada hari hadir
 * 2) persentase hari tidak hadir
 * 3) rata-rata jam kerja per hari hadir
 */
function rekap_data(int $orgId, string $start, string $end, ?string $tim, bool $onlyEligible = false, int $minHari = 10): array
{
    $params = [$orgId];
    $sql = "SELECT u.id, u.nama, u.tim, u.peran
            FROM pengguna u
            WHERE u.organisasi_id = ? AND u.peran = 'karyawan' AND u.aktif = 1";
    if ($tim) {
        $sql .= ' AND u.tim = ?';
        $params[] = $tim;
    }
    $sql .= ' ORDER BY u.nama';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    $ab = db()->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal BETWEEN ? AND ?');
    $result = [];
    foreach ($employees as $emp) {
        $ab->execute([$emp['id'], $start, $end]);
        $rows = $ab->fetchAll();

        $hadirRows = [];
        $lateMinutes = 0;
        $workMinutes = 0;
        $notPresent = 0;
        $hariTercatat = 0;
        foreach ($rows as $r) {
            if ($r['jam_masuk']) {
                $hariTercatat++;
                $batas = strtotime($r['tanggal'] . ' ' . '00:00:00');
                $hadirRows[] = $r;
                $jadwalMasuk = strtotime($r['tanggal'] . ' ' . org_jam_masuk($orgId));
                $late = max(0, (int) round((strtotime($r['tanggal'] . ' ' . $r['jam_masuk']) - $jadwalMasuk) / 60) - (int) org_toleransi($orgId));
                $lateMinutes += max(0, $late);
                if ($r['jam_pulang']) {
                    $workMinutes += max(0, (int) round((strtotime($r['jam_pulang']) - strtotime($r['jam_masuk'])) / 60));
                }
            } elseif (in_array($r['status'], ['izin', 'sakit', 'alpa'], true)) {
                $notPresent++;
            }
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
            'hari_tercatat' => $hariTercatat,
            'hari_tidak_hadir' => $notPresent,
            'total_hari' => $totalHari,
            'rata_terlambat' => round($avgLate, 2),
            'persen_tidak_hadir' => round($pctAbsent, 2),
            'rata_jam_kerja' => round($avgWorkHours, 2),
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
    $counts = ['hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'belum' => 0];

    $stmt = db()->prepare('SELECT COUNT(*) FROM pengguna WHERE organisasi_id = ? AND peran = ? AND aktif = 1');
    $stmt->execute([$orgId, 'karyawan']);
    $total = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT a.status, COUNT(*) c FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id WHERE u.organisasi_id = ? AND a.tanggal = ? GROUP BY a.status');
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

    // Tren 30 hari
    $start = date('Y-m-d', strtotime('-29 days'));
    $stmt = db()->prepare("SELECT a.tanggal,
        SUM(CASE WHEN a.status IN ('hadir','terlambat') THEN 1 ELSE 0 END) hadir,
        SUM(CASE WHEN a.status = 'terlambat' THEN 1 ELSE 0 END) terlambat,
        SUM(CASE WHEN a.status IN ('izin','sakit') THEN 1 ELSE 0 END) izin_sakit,
        SUM(CASE WHEN a.status = 'alpa' THEN 1 ELSE 0 END) alpa
        FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id
        WHERE u.organisasi_id = ? AND a.tanggal BETWEEN ? AND ?
        GROUP BY a.tanggal ORDER BY a.tanggal");
    $stmt->execute([$orgId, $start, $today]);
    $tren = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT u.nama, a.status, a.jam_masuk, a.catatan FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id
        WHERE u.organisasi_id = ? AND a.tanggal = ? AND a.status IN ('terlambat','alpa')
        ORDER BY a.status DESC, a.jam_masuk DESC LIMIT 10");
    $stmt->execute([$orgId, $today]);
    $perluDicek = $stmt->fetchAll();

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
    $stmt = db()->prepare('SELECT * FROM absensi WHERE pengguna_id = ? AND tanggal BETWEEN ? AND ?');
    $stmt->execute([$userId, $awal, $akhir]);
    $rows = $stmt->fetchAll();
    $out = ['hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
    foreach ($rows as $r) {
        $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
    }
    $out['tidak_hadir'] = $out['izin'] + $out['sakit'] + $out['alpa'];
    return $out;
}
