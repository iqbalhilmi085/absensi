<?php
declare(strict_types=1);

require_once __DIR__ . '/app/auth.php';

$user = require_login();
$file = (string) ($_GET['f'] ?? '');

if (!preg_match('/^[A-Za-z0-9_]+\.(jpg|jpeg|png|webp)$/', $file)) {
    http_response_code(400);
    exit('Nama berkas tidak valid.');
}

// Pastikan foto memang milik organisasi pengguna.
$stmt = db()->prepare("SELECT COUNT(*) FROM absensi a
    JOIN pengguna u ON u.id = a.pengguna_id
    WHERE u.organisasi_id = ? AND (a.foto_masuk = ? OR a.foto_pulang = ?)");
$stmt->execute([$user['organisasi_id'], $file, $file]);
if ((int) $stmt->fetchColumn() === 0) {
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
header('Content-Type: ' . $mimes[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
