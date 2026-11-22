<?php
declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/absensi.php';

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'kiosk.info' || $action === 'kiosk.absen') {
    json_error('Mode Kiosk sudah dinonaktifkan. Silakan login menggunakan akun masing-masing.', 410);
}

if ($method === 'POST') {
    verify_csrf();
    // Pembatasan laju umum untuk aksi tulis (lebih ketat untuk login).
    $limitMax = $action === 'auth.login' ? 15 : RATE_LIMIT_PER_MENIT;
    rate_limit('api:' . $action, $limitMax, 60);
}

try {
    route($action, $method);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[Absensi API] ' . $e->getMessage());
    json_error('Terjadi kesalahan internal pada server.', 500);
}

function param_str(string $key, string $default = ''): string
{
    $v = param($key, $default);
    return is_scalar($v) ? (string) $v : $default;
}

function route(string $action, string $method): void
{
    switch ($action) {
        case 'auth.csrf':
            json_response(['ok' => true, 'csrf' => csrf_token()]);

        case 'auth.login':
            $res = login(param_str('email'), param_str('kata_sandi'));
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
            $orgIdMeta = (int) $user['organisasi_id'];
            json_response([
                'ok' => true,
                'pengguna' => public_user($user),
                'organisasi' => get_org($orgIdMeta),
                'divisi_jadwal' => list_divisi_jadwal($orgIdMeta),
                'shift_kerja' => list_shift_kerja($orgIdMeta),
                'preset' => preset_industri(),
                'hari' => hari_label(),
                'tanggal' => date('Y-m-d'),
                'waktu' => date('H:i:s'),
            ]);

        case 'org.update':
            $user = require_role(['admin']);
            update_org($user);
            json_response(['ok' => true, 'organisasi' => get_org((int) $user['organisasi_id'])]);

        case 'divisi.list':
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => list_divisi_jadwal((int) $user['organisasi_id'])]);

        case 'divisi.save':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            $savedDiv = save_divisi_jadwal($user, input_json());
            json_response(['ok' => true, 'id' => $savedDiv, 'data' => list_divisi_jadwal((int) $user['organisasi_id'])]);

        case 'divisi.delete':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            delete_divisi_jadwal($user, (int) param('id', 0));
            json_response(['ok' => true, 'data' => list_divisi_jadwal((int) $user['organisasi_id'])]);

        case 'shift.list':
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => list_shift_kerja((int) $user['organisasi_id'])]);

        case 'shift.save':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            $savedShift = save_shift_kerja($user, input_json());
            json_response(['ok' => true, 'id' => $savedShift, 'data' => list_shift_kerja((int) $user['organisasi_id'])]);

        case 'shift.delete':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            delete_shift_kerja($user, (int) param('id', 0));
            json_response(['ok' => true, 'data' => list_shift_kerja((int) $user['organisasi_id'])]);

        case 'karyawan.list':
            $user = require_role(['admin', 'atasan']);
            json_response(['ok' => true, 'data' => list_karyawan((int) $user['organisasi_id'])]);

        case 'tim.list':
            $user = require_role(['admin', 'atasan']);
            json_response(['ok' => true, 'data' => list_tim((int) $user['organisasi_id'])]);

        case 'karyawan.save':
            $user = require_role(['admin']);
            $saved = save_karyawan($user, input_json());
            json_response(['ok' => true, 'id' => $saved['id'], 'kode_qr' => $saved['kode_qr']]);

        case 'karyawan.import':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            $impRes = import_karyawan_batch($user, input_json());
            json_response(['ok' => true, 'data' => $impRes]);

        case 'karyawan.delete':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            delete_karyawan($user, (int) param('id', 0));
            json_response(['ok' => true]);

        case 'karyawan.toggle':
            $user = require_role(['admin']);
            toggle_karyawan($user);
            json_response(['ok' => true]);

        case 'karyawan.rotate_qr':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            $newQr = rotate_karyawan_qr($user, (int) param('id', 0), param_str('alasan', 'Diterbitkan ulang oleh Admin'));
            json_response(['ok' => true, 'kode_qr' => $newQr]);

        case 'karyawan.revoke_qr':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            revoke_karyawan_qr($user, (int) param('id', 0), param_str('alasan', 'Dicabut oleh Admin'));
            json_response(['ok' => true]);

        case 'qr.lookup':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            rate_limit('qr_lookup', 20, 60, 'Terlalu banyak percobaan scan QR. Tunggu sebentar.');
            $found = resolve_personal_qr(param_str('kode_qr'));
            if (!$found) {
                $errQr = last_qr_error();
                if ($errQr) {
                    json_error($errQr['msg'], 403);
                }
                json_error('Kode QR karyawan tidak dikenali atau sudah tidak aktif.', 404);
            }
            $rec = today_record((int) $found['id']);
            $org = get_org((int) $found['organisasi_id']);
            $jadwalDiv = resolve_employee_schedule($org, (string) ($found['tim'] ?? ''), null);
            $shifts = list_shift_kerja((int) $found['organisasi_id']);
            $stateToday = (!$rec || !$rec['jam_masuk']) ? 'in' : (!$rec['jam_pulang'] ? 'out' : 'done');
            json_response([
                'ok' => true,
                'data' => [
                    'karyawan' => [
                        'nama' => $found['nama'],
                        'tim' => $found['tim'],
                        'jabatan' => $found['jabatan'],
                        'tipe' => $found['tipe'] ?: 'karyawan',
                        'kampus' => $found['kampus'] ?? '',
                        'tgl_mulai' => $found['tgl_mulai'] ?? null,
                        'tgl_selesai' => $found['tgl_selesai'] ?? null,
                        'foto_url' => portrait_signed_url((int) $found['id'], $found['foto'] ?? null),
                    ],
                    'jadwal_divisi' => $jadwalDiv,
                    'shifts' => $shifts,
                    'hari_ini' => [
                        'state' => $stateToday,
                        'jam_masuk' => $rec['jam_masuk'] ?? null,
                        'jam_pulang' => $rec['jam_pulang'] ?? null,
                        'mode' => $rec['mode'] ?? null,
                        'kegiatan' => $rec['kegiatan'] ?? '',
                        'status' => $rec['status'] ?? null,
                        'shift_nama' => $rec['shift_nama'] ?? '',
                        'jam_jadwal_masuk' => $rec['jam_jadwal_masuk'] ?? null,
                        'jam_jadwal_pulang' => $rec['jam_jadwal_pulang'] ?? null,
                        'anomali' => $rec['anomali'] ?? '',
                    ],
                    'aturan' => [
                        'nama' => $org['nama'] ?? APP_NAME,
                        'jam_masuk' => $jadwalDiv['jam_masuk'],
                        'jam_pulang' => $jadwalDiv['jam_pulang'],
                        'toleransi_menit' => (int) ($jadwalDiv['toleransi_menit'] ?? $org['toleransi_menit'] ?? 15),
                        'wajib_foto' => (bool) ($org['wajib_foto'] ?? 1),
                        'wajib_gps' => (int) ($org['wajib_gps'] ?? 0) === 1,
                        'radius_masuk_m' => (int) ($org['radius_masuk_m'] ?? 0),
                        'lat_kantor' => isset($org['lat_kantor']) && $org['lat_kantor'] !== null ? (float) $org['lat_kantor'] : null,
                        'lng_kantor' => isset($org['lng_kantor']) && $org['lng_kantor'] !== null ? (float) $org['lng_kantor'] : null,
                    ],
                ],
            ]);

        case 'qr.absen':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            rate_limit('absensi', RATE_LIMIT_ABSEN, 60, 'Terlalu banyak percobaan absen. Tunggu sebentar lalu coba lagi.');
            $found = resolve_personal_qr(param_str('kode_qr'));
            if (!$found) {
                $errQr = last_qr_error();
                if ($errQr) {
                    json_error($errQr['msg'], 403);
                }
                json_error('Kode QR karyawan tidak dikenali atau sudah tidak aktif.', 404);
            }
            $expectedState = param_str('expected_state');
            $shiftIdRaw = param('shift_id', 0);
            $shiftId = is_numeric($shiftIdRaw) && (int) $shiftIdRaw > 0 ? (int) $shiftIdRaw : null;
            $rec = today_record((int) $found['id']);

            // Pencegahan double-scan tidak sengaja dalam 60 detik bila expected_state sudah tercatat
            if ($rec && !empty($rec['jam_masuk']) && empty($rec['jam_pulang']) && $expectedState === 'in') {
                $elapsed = time() - strtotime(date('Y-m-d') . ' ' . $rec['jam_masuk']);
                if ($elapsed >= 0 && $elapsed < 60) {
                    json_response([
                        'ok' => true,
                        'data' => [
                            'tipe' => 'duplikat_diabaikan',
                            'nama' => $found['nama'],
                            'jam_masuk' => $rec['jam_masuk'],
                            'status' => $rec['status'],
                        ],
                        'pesan' => "Absen masuk {$found['nama']} sudah tercatat pukul {$rec['jam_masuk']}. Scan ganda dalam 60 detik diabaikan.",
                    ]);
                }
            }
            if ($rec && !empty($rec['jam_pulang']) && $expectedState === 'out') {
                $elapsedOut = time() - strtotime(date('Y-m-d') . ' ' . $rec['jam_pulang']);
                if ($elapsedOut >= 0 && $elapsedOut < 60) {
                    json_response([
                        'ok' => true,
                        'data' => [
                            'tipe' => 'duplikat_diabaikan',
                            'nama' => $found['nama'],
                            'jam_pulang' => $rec['jam_pulang'],
                        ],
                        'pesan' => "Absen pulang {$found['nama']} sudah tercatat pukul {$rec['jam_pulang']}. Scan ganda dalam 60 detik diabaikan.",
                    ]);
                }
            }

            if (!$rec || !$rec['jam_masuk']) {
                $res = do_checkin(
                    $found,
                    null,
                    is_string(param('foto')) ? param('foto') : null,
                    param('lat'),
                    param('lng'),
                    param('akurasi'),
                    param_str('mode', 'wfo'),
                    (bool) param('palsu', false),
                    param_str('kegiatan') ?: null,
                    (int) $found['karyawan_id'],
                    true,
                    $shiftId
                );
                $res['tipe'] = 'masuk';
                $res['nama'] = $found['nama'];
                json_response(['ok' => true, 'data' => $res, 'pesan' => "Absen masuk {$found['nama']} berhasil ({$res['jam_masuk']})"]);
            }
            if (!$rec['jam_pulang']) {
                $res = do_checkout(
                    $found,
                    null,
                    is_string(param('foto')) ? param('foto') : null,
                    param('lat'),
                    param('lng'),
                    param('akurasi'),
                    param_str('mode') ?: null,
                    (bool) param('palsu', false),
                    param_str('kegiatan') ?: null,
                    (int) $found['karyawan_id'],
                    true
                );
                $res['tipe'] = 'pulang';
                $res['nama'] = $found['nama'];
                json_response(['ok' => true, 'data' => $res, 'pesan' => "Absen pulang {$found['nama']} berhasil ({$res['jam_pulang']})"]);
            }
            json_error('Anda sudah menyelesaikan absen masuk dan pulang hari ini.', 409);

        case 'qr.riwayat':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            rate_limit('qr_lookup', 20, 60, 'Terlalu banyak permintaan riwayat. Tunggu sebentar.');
            $found = resolve_personal_qr(param_str('kode_qr'));
            if (!$found) {
                $errQr = last_qr_error();
                if ($errQr) {
                    json_error($errQr['msg'], 403);
                }
                json_error('Kode QR karyawan tidak dikenali atau sudah tidak aktif.', 404);
            }
            $selfRiw = riwayat_karyawan((int) $found['id'], 15);
            $selfRing = ringkasan_bulanan((int) $found['id']);
            json_response([
                'ok' => true,
                'data' => ['riwayat' => $selfRiw, 'ringkasan' => $selfRing],
                'riwayat' => $selfRiw,
                'ringkasan' => $selfRing,
            ]);

        case 'qr.izin':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            rate_limit('absensi', RATE_LIMIT_ABSEN, 60, 'Terlalu banyak pengajuan izin. Tunggu sebentar.');
            $found = resolve_personal_qr(param_str('kode_qr'));
            if (!$found) {
                $errQr = last_qr_error();
                if ($errQr) {
                    json_error($errQr['msg'], 403);
                }
                json_error('Kode QR karyawan tidak dikenali atau sudah tidak aktif.', 404);
            }
            $res = do_izin(
                $found,
                param_str('jenis', 'izin'),
                param_str('mulai'),
                param_str('sampai'),
                param_str('catatan'),
                is_string(param('foto')) ? param('foto') : null,
                false
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Pengajuan izin dikirim dan menunggu persetujuan Admin.']);

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
                (bool) param('palsu', false),
                param('kegiatan') ? (string) param('kegiatan') : null
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
            $actor = require_role(['admin']);
            $targetUser = $actor;
            $targetId = (int) param('pengguna_id', 0);
            if ($targetId > 0) {
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
                param_str('jenis', 'izin'),
                param_str('mulai'),
                param_str('sampai'),
                param_str('catatan'),
                is_string(param('foto')) ? param('foto') : null,
                true,
                (int) $actor['id']
            );
            json_response(['ok' => true, 'data' => $res, 'pesan' => 'Catatan izin/sakit berhasil disimpan']);

        case 'absensi.izin_list':
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => daftar_izin((int) $user['organisasi_id'])]);

        case 'absensi.izin_approve':
            if ($method !== 'POST') {
                json_error('Gunakan metode POST.', 405);
            }
            $user = require_role(['admin']);
            $izinRes = process_izin_approval($user, (int) param('id', 0), param_str('keputusan'));
            json_response(['ok' => true, 'data' => $izinRes]);

        case 'audit.list':
            $user = require_role(['admin']);
            json_response(['ok' => true, 'data' => list_audit_logs((int) $user['organisasi_id'])]);

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
            $tipeFilter = param_str('tipe') ?: null;
            json_response(['ok' => true, 'data' => rekap_data((int) $user['organisasi_id'], $dari, $sampai, $tim, false, 10, $tipeFilter)]);

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
            $kVal = (int) param('k', 3);
            if ($kVal < 2 || $kVal > 10) {
                json_error('Jumlah kategori K-Means harus antara 2 sampai 10.', 422);
            }
            $hasilVal = $payload['hasil'] ?? [];
            if (!is_array($hasilVal)) {
                json_error('Format hasil klaster tidak valid.', 422);
            }
            $hasilJson = json_encode($hasilVal, JSON_UNESCAPED_UNICODE);
            if ($hasilJson === false || strlen($hasilJson) > 200000) {
                json_error('Ukuran hasil klaster melebihi batas.', 422);
            }
            $stmt = db()->prepare('INSERT INTO hasil_klaster (organisasi_id, periode, k, hasil, dibuat_pada) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                (int) $user['organisasi_id'],
                clean_text((string) param('periode', date('Y-m')), 60),
                $kVal,
                $hasilJson,
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
    if (!valid_time_hm($jamMasuk) || !valid_time_hm($jamPulang)) {
        json_error('Format jam tidak valid (gunakan HH:MM yang sah).', 422);
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
    $pdo = db();
    $today = date('Y-m-d');
    // Otomatis nonaktifkan peserta magang yang sudah melewati tanggal selesai
    $expStmt = $pdo->prepare("SELECT id FROM pengguna WHERE organisasi_id = ? AND peran = 'karyawan' AND tipe = 'magang' AND tgl_selesai IS NOT NULL AND tgl_selesai <> '' AND tgl_selesai < ? AND aktif = 1");
    $expStmt->execute([$orgId, $today]);
    $expiredIds = array_column($expStmt->fetchAll(), 'id');
    foreach ($expiredIds as $eid) {
        $pdo->prepare('UPDATE pengguna SET aktif = 0 WHERE id = ?')->execute([(int) $eid]);
        $pdo->prepare("UPDATE karyawan SET aktif = 0, status_kartu = 'kedaluwarsa' WHERE legacy_pengguna_id = ?")->execute([(int) $eid]);
    }

    $stmt = $pdo->prepare("SELECT p.id, p.nama, p.email, p.no_hp, p.foto, p.peran, p.tim, p.jabatan, p.tipe, p.kampus, p.tgl_mulai, p.tgl_selesai, p.aktif, p.dibuat_pada,
            k.kode_qr, k.status_kartu, k.alasan_cabut, k.diterbitkan_pada, k.dicabut_pada, k.terakhir_dipakai
        FROM pengguna p
        LEFT JOIN karyawan k ON k.legacy_pengguna_id = p.id
        WHERE p.organisasi_id = ?
        ORDER BY CASE p.peran WHEN 'admin' THEN 0 WHEN 'atasan' THEN 1 ELSE 2 END, p.nama");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        if ($r['peran'] === 'karyawan' && empty($r['kode_qr'])) {
            $newQr = generate_personal_qr($pdo);
            $tsNow = $r['dibuat_pada'] ?: date('Y-m-d H:i:s');
            $ins = $pdo->prepare("INSERT INTO karyawan (organisasi_id, nama, kode_qr, aktif, status_kartu, diterbitkan_pada, tim, jabatan, legacy_pengguna_id, dibuat_pada) VALUES (?, ?, ?, ?, 'aktif', ?, ?, ?, ?, ?)");
            $ins->execute([$orgId, $r['nama'], $newQr, (int) $r['aktif'], $tsNow, $r['tim'], $r['jabatan'], (int) $r['id'], $tsNow]);
            $r['kode_qr'] = $newQr;
            $r['status_kartu'] = 'aktif';
            $r['diterbitkan_pada'] = $tsNow;
        }
        $r['foto_url'] = portrait_signed_url((int) $r['id'], $r['foto'] ?? null);
    }
    unset($r);
    return $rows;
}

function list_tim(int $orgId): array
{
    $pdo = db();
    seed_divisi_shift_defaults($pdo, $orgId);
    $stmt1 = $pdo->prepare("SELECT DISTINCT tim FROM pengguna WHERE organisasi_id = ? AND peran = 'karyawan' AND tim <> ''");
    $stmt1->execute([$orgId]);
    $stmt2 = $pdo->prepare('SELECT nama FROM divisi_jadwal WHERE organisasi_id = ?');
    $stmt2->execute([$orgId]);
    $merged = array_values(array_unique(array_merge(
        array_column($stmt1->fetchAll(), 'tim'),
        array_column($stmt2->fetchAll(), 'nama')
    )));
    sort($merged, SORT_NATURAL | SORT_FLAG_CASE);
    return $merged;
}

function save_divisi_jadwal(array $actor, array $data): int
{
    $id = (int) ($data['id'] ?? 0);
    $nama = clean_text($data['nama'] ?? '', 100);
    $kodeUnik = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', (string) ($data['kode_unik'] ?? '')) ?: '');
    $warnaKartu = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($data['warna_kartu'] ?? '')) ? (string) $data['warna_kartu'] : '#0A88C9';
    $jm = (string) ($data['jam_masuk'] ?? '');
    $jp = (string) ($data['jam_pulang'] ?? '');
    $tol = isset($data['toleransi_menit']) ? (int) $data['toleransi_menit'] : 15;
    $aktif = isset($data['aktif']) ? (int) (bool) $data['aktif'] : 1;
    if ($nama === '') {
        json_error('Nama divisi wajib diisi.', 422);
    }
    if (!valid_time_hm($jm) || !valid_time_hm($jp)) {
        json_error('Format jam divisi harus HH:MM.', 422);
    }
    if ($tol < 0 || $tol > 180) {
        json_error('Toleransi keterlambatan harus 0–180 menit.', 422);
    }
    if ($kodeUnik === '') {
        $kodeUnik = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nama) ?: 'DIV', 0, 4));
    }
    $pdo = db();
    $orgId = (int) $actor['organisasi_id'];
    $dup = $pdo->prepare('SELECT id FROM divisi_jadwal WHERE organisasi_id = ? AND LOWER(nama) = LOWER(?) AND id <> ?');
    $dup->execute([$orgId, $nama, $id]);
    if ($dup->fetch()) {
        json_error('Nama divisi sudah ada.', 409);
    }
    if ($id > 0) {
        $chk = $pdo->prepare('SELECT id FROM divisi_jadwal WHERE id = ? AND organisasi_id = ?');
        $chk->execute([$id, $orgId]);
        if (!$chk->fetch()) {
            json_error('Divisi tidak ditemukan.', 404);
        }
        $pdo->prepare('UPDATE divisi_jadwal SET nama = ?, kode_unik = ?, warna_kartu = ?, jam_masuk = ?, jam_pulang = ?, toleransi_menit = ?, aktif = ? WHERE id = ? AND organisasi_id = ?')
            ->execute([$nama, $kodeUnik, $warnaKartu, $jm, $jp, $tol, $aktif, $id, $orgId]);
        write_audit((int) $actor['id'], $orgId, 'save_divisi', "{$nama} ({$kodeUnik}) {$jm}-{$jp}");
        return $id;
    }
    $pdo->prepare('INSERT INTO divisi_jadwal (organisasi_id, nama, kode_unik, warna_kartu, jam_masuk, jam_pulang, toleransi_menit, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $nama, $kodeUnik, $warnaKartu, $jm, $jp, $tol, $aktif, date('Y-m-d H:i:s')]);
    $newId = (int) $pdo->lastInsertId();
    write_audit((int) $actor['id'], $orgId, 'save_divisi', "{$nama} ({$kodeUnik}) {$jm}-{$jp}");
    return $newId;
}

function delete_divisi_jadwal(array $actor, int $id): void
{
    $orgId = (int) $actor['organisasi_id'];
    $st = db()->prepare('DELETE FROM divisi_jadwal WHERE id = ? AND organisasi_id = ?');
    $st->execute([$id, $orgId]);
    if ($st->rowCount() === 0) {
        json_error('Divisi tidak ditemukan.', 404);
    }
    write_audit((int) $actor['id'], $orgId, 'delete_divisi', "id={$id}");
}

function save_shift_kerja(array $actor, array $data): int
{
    $id = (int) ($data['id'] ?? 0);
    $nama = clean_text($data['nama'] ?? '', 100);
    $jm = (string) ($data['jam_masuk'] ?? '');
    $jp = (string) ($data['jam_pulang'] ?? '');
    if ($nama === '') {
        json_error('Nama shift wajib diisi.', 422);
    }
    if (!valid_time_hm($jm) || !valid_time_hm($jp)) {
        json_error('Format jam shift harus HH:MM.', 422);
    }
    $pdo = db();
    $orgId = (int) $actor['organisasi_id'];
    if ($id > 0) {
        $chk = $pdo->prepare('SELECT id FROM shift_kerja WHERE id = ? AND organisasi_id = ?');
        $chk->execute([$id, $orgId]);
        if (!$chk->fetch()) {
            json_error('Shift tidak ditemukan.', 404);
        }
        $pdo->prepare('UPDATE shift_kerja SET nama = ?, jam_masuk = ?, jam_pulang = ? WHERE id = ? AND organisasi_id = ?')
            ->execute([$nama, $jm, $jp, $id, $orgId]);
        write_audit((int) $actor['id'], $orgId, 'save_shift', "{$nama} {$jm}-{$jp}");
        return $id;
    }
    $pdo->prepare('INSERT INTO shift_kerja (organisasi_id, nama, jam_masuk, jam_pulang, aktif) VALUES (?, ?, ?, ?, 1)')
        ->execute([$orgId, $nama, $jm, $jp]);
    $newId = (int) $pdo->lastInsertId();
    write_audit((int) $actor['id'], $orgId, 'save_shift', "{$nama} {$jm}-{$jp}");
    return $newId;
}

function delete_shift_kerja(array $actor, int $id): void
{
    $orgId = (int) $actor['organisasi_id'];
    $st = db()->prepare('UPDATE shift_kerja SET aktif = 0 WHERE id = ? AND organisasi_id = ?');
    $st->execute([$id, $orgId]);
    if ($st->rowCount() === 0) {
        json_error('Shift tidak ditemukan.', 404);
    }
    write_audit((int) $actor['id'], $orgId, 'delete_shift', "id={$id}");
}

function sync_karyawan_qr_row(PDO $pdo, int $orgId, int $penggunaId, string $nama, string $peran, string $tim, string $jabatan, int $aktif, string $now): ?string
{
    $find = $pdo->prepare('SELECT id, kode_qr, status_kartu, diterbitkan_pada FROM karyawan WHERE legacy_pengguna_id = ?');
    $find->execute([$penggunaId]);
    $existing = $find->fetch();
    $existingId = $existing ? (int) $existing['id'] : 0;
    if ($peran !== 'karyawan') {
        if ($existingId > 0) {
            $pdo->prepare('UPDATE karyawan SET aktif = 0 WHERE id = ?')->execute([$existingId]);
        }
        return null;
    }
    if ($existingId > 0) {
        $kodeQr = !empty($existing['kode_qr']) ? (string) $existing['kode_qr'] : generate_personal_qr($pdo);
        $statusKartu = $aktif ? 'aktif' : (($existing['status_kartu'] ?? '') === 'dicabut' ? 'dicabut' : 'aktif');
        $diterbitkan = !empty($existing['diterbitkan_pada']) ? $existing['diterbitkan_pada'] : $now;
        $pdo->prepare('UPDATE karyawan SET nama = ?, kode_qr = ?, tim = ?, jabatan = ?, aktif = ?, status_kartu = ?, diterbitkan_pada = ? WHERE id = ?')
            ->execute([$nama, $kodeQr, $tim, $jabatan, $aktif, $statusKartu, $diterbitkan, $existingId]);
        return $kodeQr;
    }
    $kodeQr = generate_personal_qr($pdo);
    $pdo->prepare("INSERT INTO karyawan (organisasi_id, nama, kode_qr, aktif, status_kartu, diterbitkan_pada, tim, jabatan, legacy_pengguna_id, dibuat_pada) VALUES (?, ?, ?, ?, 'aktif', ?, ?, ?, ?, ?)")
        ->execute([$orgId, $nama, $kodeQr, $aktif, $now, $tim, $jabatan, $penggunaId, $now]);
    return $kodeQr;
}

function save_karyawan(array $actor, array $data): array
{
    $nama = clean_text($data['nama'] ?? '', 150);
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $noHp = clean_text($data['no_hp'] ?? '', 32);
    $peran = (string) ($data['peran'] ?? 'karyawan');
    $tim = clean_text($data['tim'] ?? '', 100) ?: 'Redaksi';
    $jabatan = clean_text($data['jabatan'] ?? '', 100);
    $tipeRaw = strtolower(trim((string) ($data['tipe'] ?? 'karyawan')));
    $tipe = in_array($tipeRaw, ['karyawan', 'magang'], true) ? $tipeRaw : 'karyawan';
    $kampus = clean_text($data['kampus'] ?? '', 150);
    $tglMulai = ($tmpM = trim((string) ($data['tgl_mulai'] ?? ''))) !== '' ? $tmpM : null;
    $tglSelesai = ($tmpS = trim((string) ($data['tgl_selesai'] ?? ''))) !== '' ? $tmpS : null;
    $aktif = isset($data['aktif']) ? (int) (bool) $data['aktif'] : 1;
    $id = (int) ($data['id'] ?? 0);
    $password = (string) ($data['kata_sandi'] ?? '');
    $fotoRaw = $data['foto'] ?? null;

    if ($nama === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Nama dan email yang valid wajib diisi.', 422);
    }
    if (!in_array($peran, peran_options(), true)) {
        json_error('Peran tidak valid.', 422);
    }
    if ($tglMulai !== null && !valid_iso_date($tglMulai)) {
        json_error('Format tanggal mulai magang tidak valid.', 422);
    }
    if ($tglSelesai !== null && !valid_iso_date($tglSelesai)) {
        json_error('Format tanggal selesai magang tidak valid.', 422);
    }
    if ($tglMulai !== null && $tglSelesai !== null && $tglSelesai < $tglMulai) {
        json_error('Tanggal selesai magang tidak boleh sebelum tanggal mulai.', 422);
    }

    $fotoSaved = is_string($fotoRaw) && str_starts_with($fotoRaw, 'data:image/') ? save_photo($fotoRaw, 'profil') : null;

    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM pengguna WHERE email = ? AND id <> ?');
    $exists->execute([$email, $id]);
    if ($exists->fetch()) {
        json_error('Email sudah dipakai pengguna lain.', 409);
    }

    $now = date('Y-m-d H:i:s');
    $orgId = (int) $actor['organisasi_id'];
    if ($id > 0) {
        $chk = $pdo->prepare('SELECT id, foto FROM pengguna WHERE id = ? AND organisasi_id = ?');
        $chk->execute([$id, $orgId]);
        $curRow = $chk->fetch();
        if (!$curRow) {
            json_error('Karyawan tidak ditemukan.', 404);
        }
        $fotoFinal = $fotoSaved ?: ($curRow['foto'] ?? null);
        if ($password !== '') {
            $minPassLen = $peran === 'admin' ? MIN_ADMIN_PASSWORD : 6;
            if (strlen($password) < $minPassLen) {
                json_error("Kata sandi minimal {$minPassLen} karakter.", 422);
            }
            $stmt = $pdo->prepare('UPDATE pengguna SET nama=?, email=?, no_hp=?, foto=?, peran=?, tim=?, jabatan=?, tipe=?, kampus=?, tgl_mulai=?, tgl_selesai=?, aktif=?, kata_sandi=? WHERE id=? AND organisasi_id=?');
            $stmt->execute([$nama, $email, $noHp, $fotoFinal, $peran, $tim, $jabatan, $tipe, $kampus, $tglMulai, $tglSelesai, $aktif, password_hash($password, PASSWORD_DEFAULT), $id, $orgId]);
        } else {
            $stmt = $pdo->prepare('UPDATE pengguna SET nama=?, email=?, no_hp=?, foto=?, peran=?, tim=?, jabatan=?, tipe=?, kampus=?, tgl_mulai=?, tgl_selesai=?, aktif=? WHERE id=? AND organisasi_id=?');
            $stmt->execute([$nama, $email, $noHp, $fotoFinal, $peran, $tim, $jabatan, $tipe, $kampus, $tglMulai, $tglSelesai, $aktif, $id, $orgId]);
        }
        $kodeQr = sync_karyawan_qr_row($pdo, $orgId, $id, $nama, $peran, $tim, $jabatan, $aktif, $now);
        write_audit((int) $actor['id'], $orgId, 'update_karyawan', "{$email} ({$tipe})");
        return ['id' => $id, 'kode_qr' => $kodeQr];
    }

    if ($peran === 'admin') {
        if (strlen($password) < MIN_ADMIN_PASSWORD) {
            json_error('Kata sandi admin minimal ' . MIN_ADMIN_PASSWORD . ' karakter.', 422);
        }
        $plainPass = $password;
    } else {
        $plainPass = $password !== '' ? $password : bin2hex(random_bytes(16));
        if ($password !== '' && strlen($password) < 6) {
            json_error('Kata sandi minimal 6 karakter.', 422);
        }
    }
    $stmt = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, no_hp, foto, kata_sandi, peran, tim, jabatan, tipe, kampus, tgl_mulai, tgl_selesai, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$orgId, $nama, $email, $noHp, $fotoSaved, password_hash($plainPass, PASSWORD_DEFAULT), $peran, $tim, $jabatan, $tipe, $kampus, $tglMulai, $tglSelesai, $aktif, $now]);
    $newId = (int) $pdo->lastInsertId();
    $kodeQr = sync_karyawan_qr_row($pdo, $orgId, $newId, $nama, $peran, $tim, $jabatan, $aktif, $now);
    write_audit((int) $actor['id'], $orgId, 'tambah_karyawan', "{$email} ({$tipe})");
    return ['id' => $newId, 'kode_qr' => $kodeQr];
}

function import_karyawan_batch(array $actor, array $payload): array
{
    $rows = $payload['rows'] ?? null;
    if (!is_array($rows) || !$rows) {
        json_error('Data impor tidak boleh kosong.', 422);
    }
    if (count($rows) > 300) {
        json_error('Maksimal 300 baris per impor.', 422);
    }
    $created = 0;
    $skipped = 0;
    $errors = [];
    foreach ($rows as $idx => $r) {
        if (!is_array($r)) {
            $skipped++;
            continue;
        }
        try {
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $errors[] = 'Baris ' . ($idx + 1) . ': email tidak valid';
                continue;
            }
            $chk = db()->prepare('SELECT id FROM pengguna WHERE email = ?');
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $skipped++;
                $errors[] = 'Baris ' . ($idx + 1) . ": {$email} sudah ada";
                continue;
            }
            save_karyawan($actor, [
                'nama' => (string) ($r['nama'] ?? ''),
                'email' => $email,
                'no_hp' => (string) ($r['no_hp'] ?? ''),
                'peran' => 'karyawan',
                'tim' => (string) ($r['tim'] ?? 'Redaksi'),
                'jabatan' => (string) ($r['jabatan'] ?? ''),
                'tipe' => strtolower(trim((string) ($r['tipe'] ?? 'karyawan'))) === 'magang' ? 'magang' : 'karyawan',
                'kampus' => (string) ($r['kampus'] ?? ''),
                'tgl_mulai' => (string) ($r['tgl_mulai'] ?? ''),
                'tgl_selesai' => (string) ($r['tgl_selesai'] ?? ''),
                'aktif' => 1,
            ]);
            $created++;
        } catch (Throwable $e) {
            $skipped++;
            $errors[] = 'Baris ' . ($idx + 1) . ': ' . $e->getMessage();
        }
    }
    write_audit((int) $actor['id'], (int) $actor['organisasi_id'], 'import_karyawan', "created={$created} skipped={$skipped}");
    return ['created' => $created, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
}

function delete_karyawan(array $actor, int $id): void
{
    if ($id <= 0) {
        json_error('ID anggota tidak valid.', 422);
    }
    if ($id === (int) $actor['id']) {
        json_error('Tidak dapat menghapus akun Anda sendiri.', 422);
    }
    $pdo = db();
    $orgId = (int) $actor['organisasi_id'];
    $st = $pdo->prepare('SELECT id, email, nama FROM pengguna WHERE id = ? AND organisasi_id = ?');
    $st->execute([$id, $orgId]);
    $emp = $st->fetch();
    if (!$emp) {
        json_error('Anggota tidak ditemukan.', 404);
    }
    $pdo->prepare('DELETE FROM absensi WHERE pengguna_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM karyawan WHERE legacy_pengguna_id = ? AND organisasi_id = ?')->execute([$id, $orgId]);
    $pdo->prepare('DELETE FROM pengguna WHERE id = ? AND organisasi_id = ?')->execute([$id, $orgId]);
    write_audit((int) $actor['id'], $orgId, 'hapus_karyawan', "{$emp['nama']} ({$emp['email']})");
}

function rotate_karyawan_qr(array $actor, int $penggunaId, string $alasan = 'Diterbitkan ulang oleh Admin'): string
{
    $pdo = db();
    $orgId = (int) $actor['organisasi_id'];
    $chk = $pdo->prepare("SELECT id, nama, tim, jabatan, aktif, dibuat_pada FROM pengguna WHERE id = ? AND organisasi_id = ? AND peran = 'karyawan'");
    $chk->execute([$penggunaId, $orgId]);
    $emp = $chk->fetch();
    if (!$emp) {
        json_error('Karyawan tidak ditemukan.', 404);
    }
    $newQr = generate_personal_qr($pdo);
    $now = date('Y-m-d H:i:s');
    $alasanClean = clean_text($alasan, 255) ?: 'Diterbitkan ulang oleh Admin';
    $find = $pdo->prepare('SELECT id FROM karyawan WHERE legacy_pengguna_id = ?');
    $find->execute([$penggunaId]);
    $kid = $find->fetchColumn();
    if ($kid) {
        $pdo->prepare("UPDATE karyawan SET kode_qr = ?, status_kartu = 'aktif', aktif = 1, diterbitkan_pada = ?, dicabut_pada = NULL, alasan_cabut = ? WHERE id = ?")
            ->execute([$newQr, $now, $alasanClean, (int) $kid]);
        $pdo->prepare('UPDATE pengguna SET aktif = 1 WHERE id = ?')->execute([$penggunaId]);
    } else {
        $pdo->prepare("INSERT INTO karyawan (organisasi_id, nama, kode_qr, aktif, status_kartu, diterbitkan_pada, alasan_cabut, tim, jabatan, legacy_pengguna_id, dibuat_pada) VALUES (?, ?, ?, 1, 'aktif', ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $emp['nama'], $newQr, $now, $alasanClean, $emp['tim'], $emp['jabatan'], $penggunaId, $emp['dibuat_pada'] ?: $now]);
    }
    write_audit((int) $actor['id'], $orgId, 'rotate_qr', "pengguna={$penggunaId} alasan={$alasanClean}");
    return $newQr;
}

function revoke_karyawan_qr(array $actor, int $penggunaId, string $alasan = 'Dicabut oleh Admin'): void
{
    $pdo = db();
    $orgId = (int) $actor['organisasi_id'];
    $alasanClean = clean_text($alasan, 255) ?: 'Dicabut oleh Admin';
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("UPDATE karyawan SET status_kartu = 'dicabut', aktif = 0, dicabut_pada = ?, alasan_cabut = ? WHERE legacy_pengguna_id = ? AND organisasi_id = ?");
    $st->execute([$now, $alasanClean, $penggunaId, $orgId]);
    if ($st->rowCount() === 0) {
        json_error('Kartu QR anggota tidak ditemukan.', 404);
    }
    write_audit((int) $actor['id'], $orgId, 'cabut_qr', "pengguna={$penggunaId} alasan={$alasanClean}");
}

function process_izin_approval(array $actor, int $absensiId, string $keputusan): array
{
    $keputusan = strtolower(trim($keputusan));
    if (!in_array($keputusan, ['disetujui', 'ditolak'], true)) {
        json_error('Keputusan harus disetujui atau ditolak.', 422);
    }
    $pdo = db();
    $orgId = (int) $actor['organisasi_id'];
    $chk = $pdo->prepare('SELECT a.id, a.pengguna_id, a.tanggal, a.status FROM absensi a JOIN pengguna u ON u.id = a.pengguna_id WHERE a.id = ? AND u.organisasi_id = ?');
    $chk->execute([$absensiId, $orgId]);
    $row = $chk->fetch();
    if (!$row) {
        json_error('Pengajuan izin tidak ditemukan.', 404);
    }
    $pdo->prepare('UPDATE absensi SET approval_status = ?, diproses_oleh = ?, diperbarui_pada = ? WHERE id = ?')
        ->execute([$keputusan, (int) $actor['id'], date('Y-m-d H:i:s'), $absensiId]);
    write_audit((int) $actor['id'], $orgId, 'proses_izin', "id={$absensiId} keputusan={$keputusan}");
    return ['id' => $absensiId, 'approval_status' => $keputusan];
}

function list_audit_logs(int $orgId): array
{
    $stmt = db()->prepare('SELECT l.id, l.aksi, l.detail, l.dibuat_pada, u.nama AS aktor_nama, u.peran AS aktor_peran
        FROM audit_log l
        LEFT JOIN pengguna u ON u.id = l.pengguna_id
        WHERE l.organisasi_id = ? OR l.organisasi_id IS NULL
        ORDER BY l.id DESC
        LIMIT 150');
    $stmt->execute([$orgId]);
    return $stmt->fetchAll();
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
    $stmt = db()->prepare("SELECT u.id, u.nama, u.tim, u.jabatan, u.tipe, a.id AS absensi_id, a.status, a.jam_masuk, a.jam_pulang, a.mode, a.catatan, a.kegiatan, a.shift_nama, a.jam_jadwal_masuk, a.jam_jadwal_pulang, a.anomali, a.approval_status, a.jenis_izin, a.foto_masuk, a.foto_pulang, a.dikoreksi_oleh
        FROM pengguna u
        LEFT JOIN absensi a ON a.pengguna_id = u.id AND a.tanggal = ?
        WHERE u.organisasi_id = ? AND u.peran = 'karyawan' AND u.aktif = 1
        ORDER BY u.nama");
    $stmt->execute([$tanggal, $orgId]);
    return $stmt->fetchAll();
}

function daftar_izin(int $orgId, ?int $onlyUserId = null): array
{
    $sql = "SELECT a.id, a.pengguna_id, a.tanggal, a.status, a.jenis_izin, a.approval_status, a.catatan, a.foto_masuk, a.dibuat_pada, u.nama, u.tim, u.jabatan, u.tipe
        FROM absensi a
        JOIN pengguna u ON u.id = a.pengguna_id
        WHERE u.organisasi_id = ? AND a.status IN ('izin', 'sakit')";
    $params = [$orgId];
    if ($onlyUserId !== null) {
        $sql .= ' AND a.pengguna_id = ?';
        $params[] = $onlyUserId;
    }
    $sql .= " ORDER BY CASE COALESCE(a.approval_status,'disetujui') WHEN 'menunggu' THEN 0 WHEN 'disetujui' THEN 1 ELSE 2 END, a.tanggal DESC, u.nama ASC LIMIT 200";
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
    $mode = param('mode') ?: null;
    if ($mode !== null && !in_array($mode, ['wfo', 'wfh'], true)) {
        $mode = null;
    }

    if (strtotime($sampai) < strtotime($dari)) {
        json_error('Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
    }

    $sql = "SELECT a.id, a.pengguna_id, a.tanggal, u.nama, u.tim, u.jabatan, u.tipe, a.status, a.jam_masuk, a.jam_pulang,
                   a.foto_masuk, a.foto_pulang, a.barcode_masuk, a.mode, a.catatan, a.kegiatan, a.shift_nama, a.jam_jadwal_masuk, a.jam_jadwal_pulang, a.anomali, a.approval_status, a.jenis_izin,
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
    if ($mode) {
        $sql .= ' AND a.mode = ?';
        $params[] = $mode;
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
            'mode' => $mode,
        ],
        'organisasi' => ['nama' => $org['nama'], 'industri' => $org['industri']],
    ];
}
