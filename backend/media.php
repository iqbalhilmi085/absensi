<?php
declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';

// Jalur foto profil anggota ber-tanda tangan HMAC (untuk layar konfirmasi scan anggota tanpa login)
$portraitId = (int) ($_GET['p'] ?? 0);
$portraitSig = (string) ($_GET['sig'] ?? '');
if ($portraitId > 0 && $portraitSig !== '') {
    $stmt = db()->prepare('SELECT foto FROM pengguna WHERE id = ? AND aktif = 1');
    $stmt->execute([$portraitId]);
    $fotoFile = (string) ($stmt->fetchColumn() ?: '');
    $expected = hash_hmac('sha256', "portrait:{$portraitId}:{$fotoFile}", QR_HMAC_SECRET);
    if ($fotoFile === '' || !hash_equals($expected, $portraitSig) || !preg_match('/^[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$/', $fotoFile)) {
        http_response_code(404);
        exit('Foto tidak ditemukan.');
    }
    $path = FOTO_DIR . DIRECTORY_SEPARATOR . $fotoFile;
    if (!is_file($path)) {
        http_response_code(404);
        exit('Foto tidak ditemukan.');
    }
    $ext = strtolower(pathinfo($fotoFile, PATHINFO_EXTENSION));
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    send_security_headers(true);
    header('Content-Type: ' . $mimes[$ext]);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

$user = require_login();
$file = (string) ($_GET['f'] ?? '');

if (!preg_match('/^[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$/', $file)) {
    http_response_code(400);
    exit('Nama berkas tidak valid.');
}

// Pastikan foto memang milik organisasi pengguna (absensi atau foto profil anggota).
$sql = "SELECT COUNT(*) FROM absensi a
    JOIN pengguna u ON u.id = a.pengguna_id
    WHERE u.organisasi_id = ? AND (a.foto_masuk = ? OR a.foto_pulang = ?)";
$stmt = db()->prepare($sql);
$stmt->execute([$user['organisasi_id'], $file, $file]);
$foundCount = (int) $stmt->fetchColumn();
if ($foundCount === 0) {
    $stEmp = db()->prepare('SELECT COUNT(*) FROM pengguna WHERE organisasi_id = ? AND foto = ?');
    $stEmp->execute([$user['organisasi_id'], $file]);
    $foundCount = (int) $stEmp->fetchColumn();
}
if ($foundCount === 0) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}

$path = FOTO_DIR . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
send_security_headers(true);
header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
