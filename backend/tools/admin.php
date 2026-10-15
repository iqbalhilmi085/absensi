<?php
declare(strict_types=1);

/**
 * Buat atau atur ulang akun admin.
 *
 * Jalankan melalui terminal:
 *   php backend/tools/admin.php
 *
 * Kata sandi diketik tersembunyi (tidak ditampilkan di layar) dan
 * TIDAK pernah dicatat ke log atau basis data dalam bentuk asli.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya boleh dijalankan melalui CLI.');
}

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

function prompt(string $label, string $default = ''): string
{
    echo $default !== '' ? "{$label} [{$default}]: " : "{$label}: ";
    $line = fgets(STDIN);
    if ($line === false) {
        return $default;
    }
    $line = rtrim($line, "\r\n");
    return $line === '' ? $default : $line;
}

function prompt_hidden(string $label): string
{
    echo "{$label}: ";
    // Bila input dialihkan (pipe/otomatisasi), baca biasa tanpa menyembunyikan.
    $isTty = function_exists('stream_isatty') ? stream_isatty(STDIN) : true;
    if (!$isTty) {
        $line = fgets(STDIN);
        return $line === false ? '' : rtrim($line, "\r\n");
    }
    if (stripos(PHP_OS, 'WIN') === 0) {
        $ps = '$s = Read-Host -AsSecureString; '
            . '[System.Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))';
        $out = shell_exec('powershell -NoLogo -NoProfile -Command "' . $ps . '"');
        return $out === null ? '' : rtrim($out, "\r\n");
    }
    shell_exec('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    shell_exec('stty echo 2>/dev/null');
    echo PHP_EOL;
    return $line === false ? '' : rtrim($line, "\r\n");
}

echo "== Absensi Universal — buat / atur ulang admin ==\n";
echo PHP_EOL;

$pdo = db();

$orgs = $pdo->query('SELECT id, nama FROM organisasi ORDER BY id')->fetchAll();
if (!$orgs) {
    fwrite(STDERR, "Belum ada organisasi di basis data. Jalankan migrasi lebih dulu.\n");
    exit(1);
}

$email = strtolower(trim(prompt('Email admin', 'admin@demo.test')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email tidak valid.\n");
    exit(1);
}

// Tentukan organisasi
if (count($orgs) === 1) {
    $orgId = (int) $orgs[0]['id'];
    echo "Organisasi: {$orgs[0]['nama']} (#{$orgId})\n";
} else {
    echo "Pilih organisasi:\n";
    foreach ($orgs as $o) {
        echo "  [{$o['id']}] {$o['nama']}\n";
    }
    $orgId = (int) prompt('ID organisasi', (string) $orgs[0]['id']);
}

// Data admin yang sudah ada (bila ada)
$exists = $pdo->prepare('SELECT id, nama FROM pengguna WHERE email = ?');
$exists->execute([$email]);
$current = $exists->fetch();

$nama = trim(prompt('Nama admin', $current['nama'] ?? 'Admin'));
if ($nama === '') {
    $nama = 'Admin';
}

$pass1 = prompt_hidden('Kata sandi baru (minimal ' . MIN_ADMIN_PASSWORD . ' karakter)');
if (strlen($pass1) < MIN_ADMIN_PASSWORD) {
    fwrite(STDERR, 'Kata sandi minimal ' . MIN_ADMIN_PASSWORD . " karakter.\n");
    exit(1);
}
$pass2 = prompt_hidden('Ulangi kata sandi');
if ($pass1 !== $pass2) {
    fwrite(STDERR, "Kata sandi tidak sama.\n");
    exit(1);
}

$hash = password_hash($pass1, PASSWORD_DEFAULT);
$now = date('Y-m-d H:i:s');

if ($current) {
    $stmt = $pdo->prepare('UPDATE pengguna SET nama = ?, kata_sandi = ?, peran = ?, aktif = 1, organisasi_id = ? WHERE id = ?');
    $stmt->execute([$nama, $hash, 'admin', $orgId, (int) $current['id']]);
    write_audit((int) $current['id'], $orgId, 'admin_reset', "email={$email}");
    echo "Akun admin diperbarui.\n";
} else {
    $stmt = $pdo->prepare('INSERT INTO pengguna (organisasi_id, nama, email, kata_sandi, peran, tim, jabatan, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $stmt->execute([$orgId, $nama, $email, $hash, 'admin', 'Umum', 'Admin', $now]);
    $newId = (int) $pdo->lastInsertId();
    write_audit($newId, $orgId, 'admin_create', "email={$email}");
    echo "Akun admin dibuat.\n";
}

echo "Selesai. Kata sandi tidak ditampilkan dan tidak dicatat.\n";
