<?php
declare(strict_types=1);

/**
 * Migrasi data dari SQLite (cadangan lama) ke MySQL Laragon port 3307.
 *
 * Jalankan melalui terminal (CLI):
 *   php backend/migrasi/migrate.php
 *   php backend/migrasi/migrate.php --force   (mengosongkan MySQL lalu migrasi ulang)
 *
 * Berkas SQLite TIDAK diubah/dihapus, hanya dibaca.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip migrasi hanya boleh dijalankan melalui CLI.');
}

require_once __DIR__ . '/../app/db.php';

$sqlitePath = DATA_DIR . DIRECTORY_SEPARATOR . 'absensi.sqlite';
$argvList = $argv ?? [];
$force = in_array('--force', $argvList, true);

function generate_kode_qr(array &$used): string
{
    do {
        $qr = bin2hex(random_bytes(16));
    } while (isset($used[$qr]));
    $used[$qr] = true;
    return $qr;
}

function line(string $s = ''): void
{
    echo $s . PHP_EOL;
}

if (!is_file($sqlitePath)) {
    fwrite(STDERR, 'SQLite tidak ditemukan: ' . $sqlitePath . PHP_EOL);
    exit(1);
}

line('== Migrasi SQLite -> MySQL ==');
line('SQLite : ' . $sqlitePath);
line('MySQL  : ' . DB_HOST . ':' . DB_PORT . ' / ' . DB_NAME);
line();

// 1) Baca SQLite (read-only secara logis)
$lite = new PDO('sqlite:' . $sqlitePath);
$lite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 2) Siapkan MySQL (db() membuat database + tabel bila belum ada)
$my = db();

$existing = (int) $my->query('SELECT COUNT(*) FROM organisasi')->fetchColumn();
if ($existing > 0 && !$force) {
    line("MySQL sudah berisi {$existing} organisasi. Migrasi dibatalkan.");
    line('Jalankan ulang dengan --force bila ingin mengosongkan MySQL lalu migrasi ulang.');
    exit(0);
}
if ($force && $existing > 0) {
    line('Mengosongkan tabel MySQL (--force)...');
    $my->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['absensi', 'hasil_klaster', 'audit_log', 'karyawan', 'pengguna', 'organisasi'] as $t) {
        $my->exec('TRUNCATE TABLE ' . $t);
    }
    $my->exec('SET FOREIGN_KEY_CHECKS = 1');
}

$my->beginTransaction();

// --- organisasi ---
$orgRows = $lite->query('SELECT * FROM organisasi ORDER BY id')->fetchAll();
$insOrg = $my->prepare('INSERT INTO organisasi
    (id, nama, industri, kode_lokasi, jam_masuk, jam_pulang, toleransi_menit, hari_kerja, wajib_foto, wajib_barcode, dibuat_pada)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($orgRows as $r) {
    $insOrg->execute([
        (int) $r['id'], $r['nama'], $r['industri'], $r['kode_lokasi'],
        $r['jam_masuk'], $r['jam_pulang'], (int) $r['toleransi_menit'], $r['hari_kerja'],
        (int) $r['wajib_foto'], (int) $r['wajib_barcode'], $r['dibuat_pada'],
    ]);
}

// --- pengguna (admin tetap; atasan nonaktif) & karyawan (tabel baru, kode_qr acak) ---
$userRows = $lite->query('SELECT * FROM pengguna ORDER BY id')->fetchAll();
$insUser = $my->prepare('INSERT INTO pengguna
    (id, organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insKar = $my->prepare('INSERT INTO karyawan
    (organisasi_id, nama, kode_qr, aktif, tim, jabatan, legacy_pengguna_id, dibuat_pada)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

$usedQr = [];
$mapUserToKaryawan = [];
$movedKaryawan = 0;
$keptAdmin = 0;
$disabledAtasan = 0;

foreach ($userRows as $u) {
    if ($u['peran'] === 'karyawan') {
        $qr = generate_kode_qr($usedQr);
        $insKar->execute([
            (int) $u['organisasi_id'], $u['nama'], $qr, (int) $u['aktif'],
            $u['tim'], $u['jabatan'], (int) $u['id'], $u['dibuat_pada'],
        ]);
        $mapUserToKaryawan[(int) $u['id']] = (int) $my->lastInsertId();
        $movedKaryawan++;
    } else {
        $aktif = $u['peran'] === 'admin' ? (int) $u['aktif'] : 0;
        $insUser->execute([
            (int) $u['id'], (int) $u['organisasi_id'], $u['nama'], $u['email'],
            $u['kata_sandi'], $u['peran'], $u['tim'], $u['jabatan'], $aktif, $u['dibuat_pada'],
        ]);
        if ($u['peran'] === 'admin') {
            $keptAdmin++;
        } else {
            $disabledAtasan++;
        }
    }
}

// --- absensi (karyawan -> karyawan_id; admin/atasan -> arsip karyawan_id NULL) ---
$absRows = $lite->query('SELECT * FROM absensi ORDER BY id')->fetchAll();
$insAbs = $my->prepare('INSERT INTO absensi
    (id, karyawan_id, pengguna_id, tanggal, status, jam_masuk, jam_pulang, catatan,
     foto_masuk, foto_pulang, barcode_masuk, barcode_pulang, dikoreksi_oleh, dibuat_pada, diperbarui_pada)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

$absKaryawan = 0;
$absArsip = 0;
foreach ($absRows as $a) {
    $legacy = (int) $a['pengguna_id'];
    $karId = $mapUserToKaryawan[$legacy] ?? null;
    if ($karId !== null) {
        $absKaryawan++;
    } else {
        $absArsip++;
    }
    $insAbs->execute([
        (int) $a['id'],
        $karId,
        $legacy, // arsip id pengguna asli
        $a['tanggal'],
        $a['status'],
        $a['jam_masuk'],
        $a['jam_pulang'],
        (string) ($a['catatan'] ?? ''),
        $a['foto_masuk'],
        $a['foto_pulang'],
        $a['barcode_masuk'],
        $a['barcode_pulang'],
        isset($a['dikoreksi_oleh']) ? (int) $a['dikoreksi_oleh'] : null,
        $a['dibuat_pada'],
        $a['diperbarui_pada'],
    ]);
}

// --- hasil_klaster ---
$klasRows = $lite->query('SELECT * FROM hasil_klaster ORDER BY id')->fetchAll();
$insKlas = $my->prepare('INSERT INTO hasil_klaster (id, organisasi_id, periode, k, hasil, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($klasRows as $r) {
    $insKlas->execute([(int) $r['id'], (int) $r['organisasi_id'], $r['periode'], (int) $r['k'], $r['hasil'], $r['dibuat_pada']]);
}

// --- audit_log ---
$auditRows = $lite->query('SELECT * FROM audit_log ORDER BY id')->fetchAll();
$insAudit = $my->prepare('INSERT INTO audit_log (id, pengguna_id, organisasi_id, aksi, detail, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($auditRows as $r) {
    $insAudit->execute([
        (int) $r['id'],
        isset($r['pengguna_id']) ? (int) $r['pengguna_id'] : null,
        isset($r['organisasi_id']) ? (int) $r['organisasi_id'] : null,
        $r['aksi'],
        (string) ($r['detail'] ?? ''),
        $r['dibuat_pada'],
    ]);
}

$my->commit();

// --- Ringkasan ---
line('Migrasi selesai.');
line('  organisasi        : ' . count($orgRows));
line('  pengguna (admin)  : ' . $keptAdmin);
line('  pengguna (atasan, nonaktif) : ' . $disabledAtasan);
line('  karyawan (baru + kode_qr)   : ' . $movedKaryawan);
line('  absensi karyawan  : ' . $absKaryawan);
line('  absensi arsip (admin/atasan, karyawan_id NULL) : ' . $absArsip);
line('  hasil_klaster     : ' . count($klasRows));
line('  audit_log         : ' . count($auditRows));
line();

// --- Bandingkan jumlah baris ---
$tables = [
    ['organisasi', 'organisasi'],
    ['pengguna', 'pengguna'],
    ['absensi', 'absensi'],
    ['hasil_klaster', 'hasil_klaster'],
    ['audit_log', 'audit_log'],
];
line('== Perbandingan jumlah baris ==');
printf("%-16s %10s %10s %s\n", 'tabel', 'SQLite', 'MySQL', 'status');
$allMatch = true;
foreach ($tables as [$name, $myTable]) {
    $liteCount = (int) $lite->query('SELECT COUNT(*) FROM ' . $name)->fetchColumn();
    $myCount = (int) $my->query('SELECT COUNT(*) FROM ' . $myTable)->fetchColumn();
    $ok = $liteCount === $myCount;
    $allMatch = $allMatch && $ok;
    printf("%-16s %10d %10d %s\n", $name, $liteCount, $myCount, $ok ? 'COCOK' : 'BEDA!');
}
// Tabel baru: karyawan dihitung dari jumlah baris pengguna berperan karyawan di SQLite.
$liteKaryawan = (int) $lite->query("SELECT COUNT(*) FROM pengguna WHERE peran = 'karyawan'")->fetchColumn();
$myKaryawan = (int) $my->query('SELECT COUNT(*) FROM karyawan')->fetchColumn();
$ok = $liteKaryawan === $myKaryawan;
$allMatch = $allMatch && $ok;
printf("%-16s %10d %10d %s\n", 'karyawan', $liteKaryawan, $myKaryawan, $ok ? 'COCOK' : 'BEDA!');

line();
line($allMatch ? 'SEMUA JUMLAH BARIS COCOK.' : 'ADA PERBEDAAN JUMLAH BARIS — periksa di atas.');
exit($allMatch ? 0 : 2);
