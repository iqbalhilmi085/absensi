<?php
declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/absensi.php';

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    verify_csrf();
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
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => list_karyawan((int) $user['organisasi_id'])]);

        case 'tim.list':
            $user = require_role(['admin']);
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
            $res = do_checkin($user, (string) param('barcode', ''), param('foto'));
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Absen masuk berhasil']);

        case 'absensi.checkout':
            $user = require_role(['karyawan']);
            $res = do_checkout($user, (string) param('barcode', ''), param('foto'));
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Absen pulang berhasil']);

        case 'absensi.izin':
            $user = require_role(['karyawan']);
            $res = do_izin(
                $user,
                (string) param('jenis', 'izin'),
                (string) param('mulai', ''),
                (string) param('sampai', ''),
                (string) param('catatan', ''),
                param('foto')
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Pengajuan terkirim']);

        case 'absensi.koreksi':
            $user = require_role(['admin']);
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
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => daftar_harian((int) $user['organisasi_id'], (string) param('tanggal', date('Y-m-d')))]);

        case 'rekap':
            $user = require_role(['admin']);
            $dari = (string) param('dari', date('Y-m-01'));
            $sampai = (string) param('sampai', date('Y-m-t'));
            $tim = param('tim') ?: null;
            json_response(['ok' => true, 'data' => rekap_data((int) $user['organisasi_id'], $dari, $sampai, $tim)]);

        case 'dashboard':
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => dashboard_summary((int) $user['organisasi_id'])]);

        case 'kmeans.dataset':
            $user = require_role(['admin']);
            $dari = (string) param('dari', date('Y-m-01'));
            $sampai = (string) param('sampai', date('Y-m-t'));
            $tim = param('tim') ?: null;
            json_response([
                'ok' => true,
                'data' => rekap_data((int) $user['organisasi_id'], $dari, $sampai, $tim),
                'periode' => ['dari' => $dari, 'sampai' => $sampai],
                'min_hari' => 10,
            ]);

        case 'klaster.save':
            $user = require_role(['admin']);
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
            $user = require_role(['admin']);
            $stmt = db()->prepare('SELECT id, periode, k, dibuat_pada FROM hasil_klaster WHERE organisasi_id = ? ORDER BY id DESC LIMIT 20');
            $stmt->execute([$user['organisasi_id']]);
            json_response(['ok' => true, 'data' => $stmt->fetchAll()]);

        case 'axel.dataset':
            $user = require_role(['admin']);
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
    $kode = trim((string) param('kode_lokasi', ''));
    if ($kode === '') {
        json_error('Kode lokasi barcode wajib diisi.', 422);
    }

    $stmt = db()->prepare('UPDATE organisasi SET nama = ?, industri = ?, kode_lokasi = ?, jam_masuk = ?, jam_pulang = ?, toleransi_menit = ?, hari_kerja = ?, wajib_foto = ?, wajib_barcode = ? WHERE id = ?');
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
        $user['organisasi_id'],
    ]);
    write_audit((int) $user['id'], (int) $user['organisasi_id'], 'update_org', "preset={$industri}");
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
    $nama = trim((string) ($data['nama'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $peran = (string) ($data['peran'] ?? 'karyawan');
    $tim = trim((string) ($data['tim'] ?? 'Umum')) ?: 'Umum';
    $jabatan = trim((string) ($data['jabatan'] ?? ''));
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
    ];
}

function daftar_harian(int $orgId, string $tanggal): array
{
    $stmt = db()->prepare("SELECT u.id, u.nama, u.tim, a.id AS absensi_id, a.status, a.jam_masuk, a.jam_pulang, a.catatan, a.foto_masuk, a.foto_pulang, a.dikoreksi_oleh
        FROM pengguna u
        LEFT JOIN absensi a ON a.pengguna_id = u.id AND a.tanggal = ?
        WHERE u.organisasi_id = ? AND u.peran = 'karyawan' AND u.aktif = 1
        ORDER BY u.nama");
    $stmt->execute([$tanggal, $orgId]);
    return $stmt->fetchAll();
}

function axel_dataset(array $user): array
{
    $orgId = (int) $user['organisasi_id'];
    $dari = (string) param('dari', date('Y-m-01'));
    $sampai = (string) param('sampai', date('Y-m-t'));
    $karyawan = (int) param('karyawan', 0);
    $tim = param('tim') ?: null;
    $status = param('status') ?: null;
    if ($status !== null && !in_array($status, status_options(), true)) {
        $status = null;
    }

    if (strtotime($sampai) < strtotime($dari)) {
        json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
    }

    $sql = "SELECT a.tanggal, u.nama, u.tim, u.jabatan, a.status, a.jam_masuk, a.jam_pulang,
                   a.foto_masuk, a.foto_pulang, a.barcode_masuk, a.catatan,
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
        $menitTerlambat = null;
        $durasi = null;
        if ($r['jam_masuk']) {
            $late = (int) round((strtotime($r['tanggal'] . ' ' . $r['jam_masuk']) - strtotime($r['tanggal'] . ' ' . $jamMasukJadwal)) / 60);
            $menitTerlambat = max(0, $late - $toleransi);
            if ($menitTerlambat !== null) {
                $lateSum += $menitTerlambat;
                $lateCount++;
            }
        }
        if ($r['jam_masuk'] && $r['jam_pulang']) {
            $durasi = (int) round((strtotime($r['jam_pulang']) - strtotime($r['jam_masuk'])) / 60);
        }
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
