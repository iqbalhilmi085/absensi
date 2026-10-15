<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(FOTO_DIR)) {
        mkdir(FOTO_DIR, 0777, true);
    }

    // Koneksi ke server (tanpa nama basis data) agar bisa membuat DB bila belum ada.
    $dsnBase = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsnBase, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec("SET time_zone = '+07:00'");

    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS organisasi (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            nama            VARCHAR(150) NOT NULL,
            industri        VARCHAR(40)  NOT NULL DEFAULT 'kustom',
            kode_lokasi     VARCHAR(100) NOT NULL,
            jam_masuk       CHAR(5)      NOT NULL DEFAULT '08:00',
            jam_pulang      CHAR(5)      NOT NULL DEFAULT '17:00',
            toleransi_menit INT          NOT NULL DEFAULT 15,
            hari_kerja      VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5',
            wajib_foto      TINYINT(1)   NOT NULL DEFAULT 1,
            wajib_barcode   TINYINT(1)   NOT NULL DEFAULT 1,
            dibuat_pada     DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS pengguna (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            organisasi_id INT          NOT NULL,
            nama          VARCHAR(150) NOT NULL,
            email         VARCHAR(190) NOT NULL,
            kata_sandi    VARCHAR(255) NOT NULL,
            peran         VARCHAR(20)  NOT NULL DEFAULT 'admin',
            tim           VARCHAR(100) NOT NULL DEFAULT 'Umum',
            jabatan       VARCHAR(100) NOT NULL DEFAULT '',
            aktif         TINYINT(1)   NOT NULL DEFAULT 1,
            dibuat_pada   DATETIME     NOT NULL,
            UNIQUE KEY uq_pengguna_email (email),
            KEY idx_pengguna_org (organisasi_id),
            CONSTRAINT fk_pengguna_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS karyawan (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            organisasi_id      INT          NOT NULL,
            nama               VARCHAR(150) NOT NULL,
            kode_qr            VARCHAR(64)  NOT NULL,
            aktif              TINYINT(1)   NOT NULL DEFAULT 1,
            tim                VARCHAR(100) NOT NULL DEFAULT 'Umum',
            jabatan            VARCHAR(100) NOT NULL DEFAULT '',
            legacy_pengguna_id INT          NULL,
            dibuat_pada        DATETIME     NOT NULL,
            UNIQUE KEY uq_karyawan_kode_qr (kode_qr),
            KEY idx_karyawan_org (organisasi_id),
            KEY idx_karyawan_legacy (legacy_pengguna_id),
            CONSTRAINT fk_karyawan_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS absensi (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            karyawan_id     INT          NULL,
            pengguna_id     INT          NULL,
            tanggal         DATE         NOT NULL,
            status          VARCHAR(20)  NOT NULL,
            jam_masuk       TIME         NULL,
            jam_pulang      TIME         NULL,
            catatan         VARCHAR(500) NOT NULL DEFAULT '',
            foto_masuk      VARCHAR(190) NULL,
            foto_pulang     VARCHAR(190) NULL,
            barcode_masuk   VARCHAR(190) NULL,
            barcode_pulang  VARCHAR(190) NULL,
            dikoreksi_oleh  INT          NULL,
            dibuat_pada     DATETIME     NOT NULL,
            diperbarui_pada DATETIME     NOT NULL,
            UNIQUE KEY uq_absensi_karyawan_tgl (karyawan_id, tanggal),
            KEY idx_absensi_karyawan (karyawan_id, tanggal),
            KEY idx_absensi_pengguna (pengguna_id, tanggal),
            CONSTRAINT fk_absensi_karyawan FOREIGN KEY (karyawan_id) REFERENCES karyawan(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS hasil_klaster (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            organisasi_id INT         NOT NULL,
            periode       VARCHAR(60) NOT NULL,
            k             INT         NOT NULL,
            hasil         LONGTEXT    NOT NULL,
            dibuat_pada   DATETIME    NOT NULL,
            KEY idx_klaster_org (organisasi_id),
            CONSTRAINT fk_klaster_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            pengguna_id   INT          NULL,
            organisasi_id INT          NULL,
            aksi          VARCHAR(60)  NOT NULL,
            detail        VARCHAR(500) NOT NULL DEFAULT '',
            dibuat_pada   DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS login_attempt (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            email       VARCHAR(190) NOT NULL,
            ip          VARCHAR(45)  NOT NULL,
            sukses      TINYINT(1)   NOT NULL DEFAULT 0,
            dibuat_pada DATETIME     NOT NULL,
            KEY idx_attempt (email, ip, sukses, dibuat_pada)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}
