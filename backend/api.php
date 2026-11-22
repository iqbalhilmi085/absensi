<?php
declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/absensi.php';

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    verify_csrf();
    // Pembatasan laju umum untuk aksi tulis, terpisah dari penguncian login.
    rate_limit('api:' . $action, RATE_LIMIT_PER_MENIT, 60);
}

try {
    route($action, $method);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    json_error('Terjadi kesalahan pada server: ' . $e->getMessage(), 500);
}

function route(string $action, string $method): void
{
    switch ($action) {
        case 'auth.csrf':
            json_response(['ok' => true, 'csrf' => csrf_token()]);

        case 'auth.login':
            $res = login((string) param('email', ''), (string) param('kata_sandi', ''));
            if (!$res['ok']) {
                json_error($res['error'], $res['status'] ?? 401);
            }
            json_response(['ok' => true, 'pengguna' => public_user($res['user'])]);

        case 'auth.me':
            $user = require_login();
            json_response(['ok' => true, 'pengguna' => public_user($user), 'organisasi' => get_org((int) $user['organisasi_id'])]);

        case 'auth.logout':
            logout();
            json_response(['ok' => true]);

        case 'meta':
            $user = require_login();
            json_response([
                'ok' => true,
                'pengguna' => public_user($user),
                'organisasi' => get_org((int) $user['organisasi_id']),
                'preset' => preset_industri(),
                'hari' => hari_label(),
                'tanggal' => date('Y-m-d'),
                'waktu' => date('H:i:s'),
            ]);

        case 'org.update':
            $user = require_role(['admin']);
            update_org($user);
            json_response(['ok' => true, 'organisasi' => get_org((int) $user['organisasi_id'])]);

        case 'karyawan.list':
            $user = require_role(['admin', 'atasan']);
            json_response(['ok' => true, 'data' => list_karyawan((int) $user['organisasi_id'])]);

        case 'tim.list':
            $user = require_role(['admin', 'atasan']);
            json_response(['ok' => true, 'data' => list_tim((int) $user['organisasi_id'])]);

        case 'karyawan.save':
            $user = require_role(['admin']);
            $id = save_karyawan($user, input_json());
            json_response(['ok' => true, 'id' => $id]);

        case 'karyawan.toggle':
            $user = require_role(['admin']);
            toggle_karyawan($user);
            json_response(['ok' => true]);

        case 'absensi.today':
            $user = require_login();
            json_response(['ok' => true, 'data' => own_today($user), 'riwayat' => riwayat_karyawan((int) $user['id'], 7), 'ringkasan' => ringkasan_bulanan((int) $user['id'])]);

        case 'absensi.checkin':
            $user = require_role(['karyawan']);
            rate_limit('absensi', RATE_LIMIT_ABSEN, 60, 'Terlalu banyak percobaan absen. Tunggu sebentar lalu coba lagi.');
            $res = do_checkin(
                $user,
                (string) param('barcode', ''),
                param('foto'),
                param('lat'),
                param('lng'),
                param('akurasi'),
                param('mode') ? (string) param('mode') : null,
                (bool) param('palsu', false)
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Absen masuk berhasil']);

        case 'absensi.checkout':
            $user = require_role(['karyawan']);
            rate_limit('absensi', RATE_LIMIT_ABSEN, 60, 'Terlalu banyak percobaan absen. Tunggu sebentar lalu coba lagi.');
            $res = do_checkout(
                $user,
                (string) param('barcode', ''),
                param('foto'),
                param('lat'),
                param('lng'),
                param('akurasi'),
                param('mode') ? (string) param('mode') : null,
                (bool) param('palsu', false),
                param('kegiatan') ? (string) param('kegiatan') : null
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Absen pulang berhasil']);

        case 'absensi.izin':
            $actor = require_role(['karyawan', 'admin', 'atasan']);
            $targetUser = $actor;
            $targetId = (int) param('pengguna_id', 0);
            if (in_array($actor['peran'], ['admin', 'atasan'], true) && $targetId > 0) {
                $st = db()->prepare('SELECT * FROM pengguna WHERE id = ? AND organisasi_id = ?');
                $st->execute([$targetId, $actor['organisasi_id']]);
                $found = $st->fetch();
                if (!$found) {
                    json_error('Karyawan tidak ditemukan.', 404);
                }
                $targetUser = $found;
            }
            $res = do_izin(
                $targetUser,
                (string) param('jenis', 'izin'),
                (string) param('mulai', ''),
                (string) param('sampai', ''),
                (string) param('catatan', ''),
                param('foto'),
                in_array($actor['peran'], ['admin', 'atasan'], true)
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Catatan izin/sakit berhasil disimpan']);

        case 'absensi.izin_list':
            $user = require_role(['admin', 'atasan', 'karyawan']);
            $onlyUserId = $user['peran'] === 'karyawan' ? (int) $user['id'] : null;
            json_response(['ok' => true, 'data' => daftar_izin((int) $user['organisasi_id'], $onlyUserId)]);

        case 'kiosk.info':
        case 'kiosk.absen':
            json_error('Mode Kiosk sudah dinonaktifkan. Silakan login menggunakan akun masing-masing.', 410);

        case 'absensi.koreksi':
            $user = require_role(['admin', 'atasan']);
            $res = do_koreksi(
                $user,
                (int) param('pengguna_id', 0),
                (string) param('tanggal', ''),
                (string) param('status', ''),
                (string) param('catatan', ''),
                param('jam_masuk') ?: null,
                param('jam_pulang') ?: null
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Catatan absensi diperbarui']);

        case 'absensi.harian':
            $user = require_role(['admin', 'atasan']);
            $tgl = (string) param('tanggal', date('Y-m-d'));
            if (!valid_iso_date($tgl)) {
                json_error('Format tanggal tidak valid.', 422);
            }
            json_response(['ok' => true, 'data' => daftar_harian((int) $user['organisasi_id'], $tgl)]);

        case 'rekap':
            $user = require_role(['admin', 'atasan']);
            $dari = (string) param('dari', date('Y-m-01'));
            $sampai = (string) param('sampai', date('Y-m-t'));
            if (!valid_iso_date($dari) || !valid_iso_date($sampai)) {
                json_error('Format tanggal tidak valid.', 422);
            }
            if (strtotime($sampai) < strtotime($dari)) {
                json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
            }
            $tim = param('tim') ?: null;
            json_response(['ok' => true, 'data' => rekap_data((int) $user['organisasi_id'], $dari, $sampai, $tim)]);

        case 'dashboard':
            $user = require_role(['admin', 'atasan']);
            json_response(['ok' => true, 'data' => dashboard_summary((int) $user['organisasi_id'])]);

        case 'kmeans.dataset':
            $user = require_role(['admin', 'atasan']);
            $dari = (string) param('dari', date('Y-m-01'));
            $sampai = (string) param('sampai', date('Y-m-t'));
            if (!valid_iso_date($dari) || !valid_iso_date($sampai)) {
                json_error('Format tanggal tidak valid.', 422);
            }
            if (strtotime($sampai) < strtotime($dari)) {
                json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
            }
            $tim = param('tim') ?: null;
            json_response([
                'ok' => true,
                'data' => rekap_data((int) $user['organisasi_id'], $dari, $sampai, $tim),
                'periode' => ['dari' => $dari, 'sampai' => $sampai],
                'min_hari' => 10,
            ]);

        case 'klaster.save':
            $user = require_role(['admin', 'atasan']);
            $payload = input_json();
            $stmt = db()->prepare('INSERT INTO hasil_klaster (organisasi_id, periode, k, hasil, dibuat_pada) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                (int) $user['organisasi_id'],
                (string) param('periode', date('Y-m')),
                (int) param('k', 3),
                json_encode($payload['hasil'] ?? [], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
            ]);
            write_audit((int) $user['id'], (int) $user['organisasi_id'], 'klaster_save', 'menyimpan hasil K-Means');
            json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);

        case 'klaster.list':
            $user = require_role(['admin', 'atasan']);
            $stmt = db()->prepare('SELECT id, periode, k, hasil, dibuat_pada FROM hasil_klaster WHERE organisasi_id = ? ORDER BY id DESC LIMIT 20');
            $stmt->execute([$user['organisasi_id']]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['hasil'] = json_decode((string) ($r['hasil'] ?? '[]'), true) ?: [];
            }
            unset($r);
            json_response(['ok' => true, 'data' => $rows]);

        case 'axel.dataset':
            $user = require_role(['admin', 'atasan']);
            json_response(axel_dataset($user));

        default:
            json_error('Aksi tidak dikenali: ' . $action, 404);
    }
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'nama' => $user['nama'],
        'email' => $user['email'],
        'peran' => $user['peran'],
        'tim' => $user['tim'],
        'jabatan' => $user['jabatan'],
        'organisasi_id' => (int) $user['organisasi_id'],
    ];
}

function update_org(array $user): void
{
    $preset = preset_industri();
    $industri = (string) param('industri', 'kustom');
    if (!isset($preset[$industri])) {
        json_error('Preset industri tidak valid.', 422);
    }
    $nama = trim((string) param('nama', ''));
    if ($nama === '') {
        json_error('Nama organisasi wajib diisi.', 422);
    }
    $jamMasuk = (string) param('jam_masuk', $preset[$industri]['jam_masuk']);
    $jamPulang = (string) param('jam_pulang', $preset[$industri]['jam_pulang']);
    if (!preg_match('/^\d{2}:\d{2}$/', $jamMasuk) || !preg_match('/^\d{2}:\d{2}$/', $jamPulang)) {
        json_error('Format jam tidak valid (gunakan HH:MM).', 422);
    }
    $toleransi = max(0, min(180, (int) param('toleransi_menit', $preset[$industri]['toleransi_menit'])));
    $hari = param('hari_kerja', []);
    if (is_string($hari)) {
        $hari = array_filter(array_map('trim', explode(',', $hari)));
    }
    $hari = array_values(array_unique(array_filter(array_map('intval', (array) $hari), fn($v) => $v >= 1 && $v <= 7)));
    sort($hari);
    if (!$hari) {
        json_error('Pilih minimal satu hari kerja.', 422);
    }
    $kode = clean_text(param('kode_lokasi', ''), 100);
    if ($kode === '') {
        json_error('Kode lokasi barcode wajib diisi.', 422);
    }

    // Konfigurasi GPS kantor & radius.
    $wajibGps = (int) (bool) param('wajib_gps', 0);
    $radius = max(0, min(100000, (int) param('radius_masuk_m', 0)));
    $latRaw = trim((string) param('lat_kantor', ''));
    $lngRaw = trim((string) param('lng_kantor', ''));
    if (($latRaw !== '' && !is_numeric($latRaw)) || ($lngRaw !== '' && !is_numeric($lngRaw))) {
        json_error('Koordinat GPS kantor harus berupa angka yang valid.', 422);
    }
    if (($latRaw === '') !== ($lngRaw === '')) {
        json_error('Lengkapi kedua koordinat (Latitude dan Longitude) kantor.', 422);
    }
    $latKantor = $latRaw === '' ? null : (float) $latRaw;
    $lngKantor = $lngRaw === '' ? null : (float) $lngRaw;
    if ($latKantor !== null && ($latKantor < -90 || $latKantor > 90)) {
        json_error('Latitude kantor tidak valid.', 422);
    }
    if ($lngKantor !== null && ($lngKantor < -180 || $lngKantor > 180)) {
        json_error('Longitude kantor tidak valid.', 422);
    }
    if ($latKantor !== null && $lngKantor !== null && abs($latKantor) < 0.0000001 && abs($lngKantor) < 0.0000001) {
        json_error('Koordinat kantor tidak boleh 0, 0. Gunakan tombol ambil lokasi otomatis.', 422);
    }
    if (($radius > 0 || $wajibGps) && ($latKantor === null || $lngKantor === null)) {
        json_error('Isi koordinat kantor terlebih dahulu sebelum mengaktifkan GPS/radius.', 422);
    }

    $stmt = db()->prepare('UPDATE organisasi SET nama = ?, industri = ?, kode_lokasi = ?, jam_masuk = ?, jam_pulang = ?, toleransi_menit = ?, hari_kerja = ?, wajib_foto = ?, wajib_barcode = ?, lat_kantor = ?, lng_kantor = ?, radius_masuk_m = ?, wajib_gps = ? WHERE id = ?');
    $stmt->execute([
        $nama,
        $industri,
        $kode,
        $jamMasuk,
        $jamPulang,
        $toleransi,
        implode(',', $hari),
        (int) (bool) param('wajib_foto', 1),
        (int) (bool) param('wajib_barcode', 1),
        $latKantor,
        $lngKantor,
        $radius,
        $wajibGps,
        $user['organisasi_id'],
    ]);
    write_audit((int) $user['id'], (int) $user['organisasi_id'], 'update_org', "preset={$industri} gps={$wajibGps} radius={$radius}");
}

function list_karyawan(int $orgId): array
{
    $stmt = db()->prepare("SELECT id, nama, email, peran, tim, jabatan, aktif, dibuat_pada FROM pengguna WHERE organisasi_id = ? ORDER BY CASE peran WHEN 'admin' THEN 0 WHEN 'atasan' THEN 1 ELSE 2 END, nama");
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
}

function list_tim(int $orgId): array
{
    $stmt = db()->prepare("SELECT DISTINCT tim FROM pengguna WHERE organisasi_id = ? AND peran = 'karyawan' AND tim <> '' ORDER BY tim");
    $stmt->execute([$orgId]);
    return array_column($stmt->fetchAll(), 'tim');
}

function save_karyawan(array $actor, array $data): int
{
    $nama = clean_text($data['nama'] ?? '', 150);
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $peran = (string) ($data['peran'] ?? 'karyawan');
    $tim = clean_text($data['tim'] ?? '', 100) ?: 'Umum';
    $jabatan = clean_text($data['jabatan'] ?? '', 100);
    $aktif = isset($data['aktif']) ? (int) (bool) $data['aktif'] : 1;
    $id = (int) ($data['id'] ?? 0);
    $password = (string) ($data['kata_sandi'] ?? '');

    if ($nama === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Nama dan email yang valid wajib diisi.', 422);
    }
    if (!in_array($peran, peran_options(), true)) {
        json_error('Peran tidak valid.', 422);
    }

    $exists = db()->prepare('SELECT id FROM pengguna WHERE email = ? AND id <> ?');
    $exists->execute([$email, $id]);
    if ($exists->fetch()) {
        json_error('Email sudah dipakai pengguna lain.', 409);
    }

    $now = date('Y-m-d H:i:s');
    if ($id > 0) {
        $chk = db()->prepare('SELECT id FROM pengguna WHERE id = ? AND organisasi_id = ?');
        $chk->execute([$id, $actor['organisasi_id']]);
        if (!$chk->fetch()) {
            json_error('Karyawan tidak ditemukan.', 404);
        }
        if ($password !== '') {
            if (strlen($password) < 6) {
                json_error('Kata sandi minimal 6 karakter.', 422);
            }
            $stmt = db()->prepare('UPDATE pengguna SET nama=?, email=?, peran=?, tim=?, jabatan=?, aktif=?, kata_sandi=? WHERE id=? AND organisasi_id=?');
            $stmt->execute([$nama, $email, $peran, $tim, $jabatan, $aktif, password_hash($password, PASSWORD_DEFAULT), $id, $actor['organisasi_id']]);
        } else {
            $stmt = db()->prepare('UPDATE pengguna SET nama=?, email=?, peran=?, tim=?, jabatan=?, aktif=? WHERE id=? AND organisasi_id=?');
            $stmt->execute([$nama, $email, $peran, $tim, $jabatan, $aktif, $id, $actor['organisasi_id']]);
        }
        write_audit((int) $actor['id'], (int) $actor['organisasi_id'], 'update_karyawan', $email);
        return $id;
    }

    if (strlen($password) < 6) {
        json_error('Kata sandi minimal 6 karakter.', 422);
    }
    $stmt = db()->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$actor['organisasi_id'], $nama, $email, password_hash($password, PASSWORD_DEFAULT), $peran, $tim, $jabatan, $aktif, $now]);
    $newId = (int) db()->lastInsertId();
    write_audit((int) $actor['id'], (int) $actor['organisasi_id'], 'tambah_karyawan', $email);
    return $newId;
}

function toggle_karyawan(array $actor): void
{
    $id = (int) param('id', 0);
    $aktif = (int) (bool) param('aktif', 1);
    if ($id === (int) $actor['id']) {
        json_error('Anda tidak dapat menonaktifkan akun sendiri.', 422);
    }
    $stmt = db()->prepare('UPDATE pengguna SET aktif = ? WHERE id = ? AND organisasi_id = ?');
    $stmt->execute([$aktif, $id, $actor['organisasi_id']]);
    write_audit((int) $actor['id'], (int) $actor['organisasi_id'], 'toggle_karyawan', "id={$id} aktif={$aktif}");
}

function own_today(array $user): array
{
    $org = get_org((int) $user['organisasi_id']);
    $rec = today_record((int) $user['id']);
    return [
        'record' => $rec,
        'jam_sekarang' => date('H:i:s'),
        'tanggal' => date('Y-m-d'),
        'hari_kerja' => is_hari_kerja($org, date('Y-m-d')),
        'wajib_foto' => (bool) $org['wajib_foto'],
        'wajib_barcode' => (bool) $org['wajib_barcode'],
        'kode_lokasi' => $org['kode_lokasi'],
        'jam_masuk_jadwal' => $org['jam_masuk'],
        'jam_pulang_jadwal' => $org['jam_pulang'],
        'toleransi_menit' => (int) $org['toleransi_menit'],
        'wajib_gps' => (int) ($org['wajib_gps'] ?? 0) === 1,
        'radius_masuk_m' => (int) ($org['radius_masuk_m'] ?? 0),
        'lat_kantor' => isset($org['lat_kantor']) && $org['lat_kantor'] !== null ? (float) $org['lat_kantor'] : null,
        'lng_kantor' => isset($org['lng_kantor']) && $org['lng_kantor'] !== null ? (float) $org['lng_kantor'] : null,
    ];
}

function daftar_harian(int $orgId, string $tanggal): array
{
    $stmt = db()->prepare("SELECT u.id, u.nama, u.tim, u.jabatan, a.id AS absensi_id, a.status, a.jam_masuk, a.jam_pulang, a.catatan, a.kegiatan, a.foto_masuk, a.foto_pulang, a.dikoreksi_oleh
        FROM pengguna u
        LEFT JOIN absensi a ON a.pengguna_id = u.id AND a.tanggal = ?
        WHERE u.organisasi_id = ? AND u.peran = 'karyawan' AND u.aktif = 1
        ORDER BY u.nama");
    $stmt->execute([$tanggal, $orgId]);
    return $stmt->fetchAll();
}

function daftar_izin(int $orgId, ?int $onlyUserId = null): array
{
    $sql = "SELECT a.id, a.pengguna_id, a.tanggal, a.status, a.catatan, a.foto_masuk, a.dibuat_pada, u.nama, u.tim, u.jabatan
        FROM absensi a
        JOIN pengguna u ON u.id = a.pengguna_id
        WHERE u.organisasi_id = ? AND a.status IN ('izin', 'sakit')";
    $params = [$orgId];
    if ($onlyUserId !== null) {
        $sql .= ' AND a.pengguna_id = ?';
        $params[] = $onlyUserId;
    }
    $sql .= ' ORDER BY a.tanggal DESC, u.nama ASC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function kiosk_info(): array
{
    $org = db()->query('SELECT * FROM organisasi ORDER BY id LIMIT 1')->fetch() ?: [];
    $orgId = (int) ($org['id'] ?? 1);
    $today = date('Y-m-d');
    $stmt = db()->prepare("SELECT u.id, u.nama, u.tim, u.jabatan, a.status, a.jam_masuk, a.jam_pulang, a.catatan
        FROM pengguna u
        LEFT JOIN absensi a ON a.pengguna_id = u.id AND a.tanggal = ?
        WHERE u.organisasi_id = ? AND u.peran = 'karyawan' AND u.aktif = 1
        ORDER BY u.nama");
    $stmt->execute([$today, $orgId]);
    return [
        'organisasi' => [
            'id' => $orgId,
            'nama' => $org['nama'] ?? APP_NAME,
            'kode_lokasi' => $org['kode_lokasi'] ?? '',
            'jam_masuk' => $org['jam_masuk'] ?? '06:00',
            'jam_pulang' => $org['jam_pulang'] ?? '14:00',
            'toleransi_menit' => (int) ($org['toleransi_menit'] ?? 15),
            'wajib_foto' => (bool) ($org['wajib_foto'] ?? 0),
            'wajib_barcode' => (bool) ($org['wajib_barcode'] ?? 0),
            'wajib_gps' => (int) ($org['wajib_gps'] ?? 0) === 1,
            'radius_masuk_m' => (int) ($org['radius_masuk_m'] ?? 0),
            'lat_kantor' => isset($org['lat_kantor']) && $org['lat_kantor'] !== null ? (float) $org['lat_kantor'] : null,
            'lng_kantor' => isset($org['lng_kantor']) && $org['lng_kantor'] !== null ? (float) $org['lng_kantor'] : null,
        ],
        'tanggal' => $today,
        'jam_sekarang' => date('H:i:s'),
        'karyawan' => $stmt->fetchAll(),
    ];
}

function kiosk_absen(): array
{
    $penggunaId = (int) param('pengguna_id', 0);
    $tipe = strtolower(trim((string) param('tipe', 'masuk')));
    $shift = clean_text(param('shift', 'Pagi (06:00-14:00)'), 100);
    $barcode = (string) param('barcode', '');
    $foto = param('foto');

    $stmt = db()->prepare("SELECT * FROM pengguna WHERE id = ? AND peran = 'karyawan' AND aktif = 1");
    $stmt->execute([$penggunaId]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('Pilih nama karyawan yang valid.', 422);
    }

    if ($tipe === 'pulang') {
        $res = do_checkout(
            $user,
            $barcode,
            $foto,
            param('lat'),
            param('lng'),
            param('akurasi'),
            'wfo',
            (bool) param('palsu', false),
            param('kegiatan') ? (string) param('kegiatan') : null
        );
        $res['pesan'] = "Presensi pulang {$user['nama']} berhasil dicatat ({$res['jam_pulang']})";
        return $res;
    }

    $res = do_checkin(
        $user,
        $barcode,
        $foto,
        param('lat'),
        param('lng'),
        param('akurasi'),
        'wfo',
        (bool) param('palsu', false)
    );
    if ($shift !== '') {
        $today = date('Y-m-d');
        $org = get_org((int) $user['organisasi_id']);
        $toleransi = (int) ($org['toleransi_menit'] ?? 15);
        $rowCheck = ['tanggal' => $today, 'jam_masuk' => $res['jam_masuk'], 'catatan' => "Shift: {$shift}"];
        $lateMin = hitung_terlambat_row($rowCheck, $org['jam_masuk'] ?? '06:00', $toleransi) ?? 0;
        $statusShift = $lateMin > 0 ? 'terlambat' : 'hadir';
        $upd = db()->prepare('UPDATE absensi SET catatan = ?, status = ? WHERE pengguna_id = ? AND tanggal = ?');
        $upd->execute(["Shift: {$shift}", $statusShift, $user['id'], $today]);
        $res['status'] = $statusShift;
    }
    $res['pesan'] = "Presensi masuk {$user['nama']} berhasil dicatat ({$res['jam_masuk']})";
    return $res;
}

function axel_dataset(array $user): array
{
    $orgId = (int) $user['organisasi_id'];
    $dari = (string) param('dari', date('Y-m-01'));
    $sampai = (string) param('sampai', date('Y-m-t'));
    if (!valid_iso_date($dari) || !valid_iso_date($sampai)) {
        json_error('Format tanggal tidak valid.', 422);
    }
    $karyawan = (int) param('karyawan', 0);
    $tim = param('tim') ?: null;
    $status = param('status') ?: null;
    if ($status !== null && !in_array($status, status_options(), true)) {
        $status = null;
    }

    if (strtotime($sampai) < strtotime($dari)) {
        json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
    }

    $sql = "SELECT a.id, a.pengguna_id, a.tanggal, u.nama, u.tim, u.jabatan, a.status, a.jam_masuk, a.jam_pulang,
                   a.foto_masuk, a.foto_pulang, a.barcode_masuk, a.catatan, a.kegiatan,
                   k.nama AS dikoreksi_nama
            FROM absensi a
            JOIN pengguna u ON u.id = a.pengguna_id
            LEFT JOIN pengguna k ON k.id = a.dikoreksi_oleh
            WHERE u.organisasi_id = ? AND a.tanggal BETWEEN ? AND ?";
    $params = [$orgId, $dari, $sampai];
    if ($karyawan > 0) {
        $sql .= ' AND u.id = ?';
        $params[] = $karyawan;
    }
    if ($tim) {
        $sql .= ' AND u.tim = ?';
        $params[] = $tim;
    }
    if ($status) {
        $sql .= ' AND a.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY a.tanggal DESC, u.nama ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $org = get_org($orgId);
    $toleransi = (int) $org['toleransi_menit'];
    $jamMasukJadwal = $org['jam_masuk'];
    $summary = ['total' => 0, 'hadir' => 0, 'terlambat' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'rata_terlambat' => 0];
    $lateSum = 0;
    $lateCount = 0;

    $out = [];
    foreach ($rows as $r) {
        $menitTerlambat = hitung_terlambat_row($r, $jamMasukJadwal, $toleransi);
        if ($menitTerlambat !== null) {
            $lateSum += $menitTerlambat;
            $lateCount++;
        }
        $durasi = hitung_durasi_row($r['jam_masuk'] ?? null, $r['jam_pulang'] ?? null);
        $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1;
        $summary['total']++;
        $out[] = array_merge($r, [
            'menit_terlambat' => $menitTerlambat,
            'durasi_kerja' => $durasi,
            'punya_foto' => (bool) ($r['foto_masuk'] || $r['foto_pulang']),
        ]);
    }
    $summary['rata_terlambat'] = $lateCount > 0 ? round($lateSum / $lateCount, 1) : 0;

    return [
        'ok' => true,
        'data' => $out,
        'ringkasan' => $summary,
        'filter' => [
            'dari' => $dari,
            'sampai' => $sampai,
            'karyawan' => $karyawan,
            'tim' => $tim,
            'status' => $status,
        ],
        'organisasi' => ['nama' => $org['nama'], 'industri' => $org['industri']],
    ];
}
