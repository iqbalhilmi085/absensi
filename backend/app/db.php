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

    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . SQLITE_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        init_schema($pdo);
        return $pdo;
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
    if (DB_DRIVER === 'sqlite') {
        init_schema_sqlite($pdo);
        migrate_schema($pdo);
        seed_organisasi_default($pdo);
        return;
    }

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
            lat_kantor      DECIMAL(10,7) NULL,
            lng_kantor      DECIMAL(10,7) NULL,
            radius_masuk_m  INT          NOT NULL DEFAULT 0,
            wajib_gps       TINYINT(1)   NOT NULL DEFAULT 0,
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
            lat_masuk       DECIMAL(10,7) NULL,
            lng_masuk       DECIMAL(10,7) NULL,
            lat_pulang      DECIMAL(10,7) NULL,
            lng_pulang      DECIMAL(10,7) NULL,
            akurasi_masuk   INT          NULL,
            akurasi_pulang  INT          NULL,
            jarak_masuk_m   INT          NULL,
            jarak_pulang_m  INT          NULL,
            mode            VARCHAR(20)  NOT NULL DEFAULT '',
            kegiatan        VARCHAR(500) NOT NULL DEFAULT '',
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

        "CREATE TABLE IF NOT EXISTS rate_limit (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            kunci       VARCHAR(190) NOT NULL,
            ip          VARCHAR(45)  NOT NULL,
            pengguna_id INT          NULL,
            dibuat_pada DATETIME     NOT NULL,
            KEY idx_rate (kunci, ip, dibuat_pada)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    migrate_schema($pdo);
}

function init_schema_sqlite(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS organisasi (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            nama            TEXT NOT NULL,
            industri        TEXT NOT NULL DEFAULT 'kustom',
            kode_lokasi     TEXT NOT NULL,
            jam_masuk       TEXT NOT NULL DEFAULT '08:00',
            jam_pulang      TEXT NOT NULL DEFAULT '17:00',
            toleransi_menit INTEGER NOT NULL DEFAULT 15,
            hari_kerja      TEXT NOT NULL DEFAULT '1,2,3,4,5',
            wajib_foto      INTEGER NOT NULL DEFAULT 1,
            wajib_barcode   INTEGER NOT NULL DEFAULT 1,
            lat_kantor      REAL NULL,
            lng_kantor      REAL NULL,
            radius_masuk_m  INTEGER NOT NULL DEFAULT 0,
            wajib_gps       INTEGER NOT NULL DEFAULT 0,
            dibuat_pada     TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS pengguna (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            organisasi_id INTEGER NOT NULL,
            nama          TEXT NOT NULL,
            email         TEXT NOT NULL,
            kata_sandi    TEXT NOT NULL,
            peran         TEXT NOT NULL DEFAULT 'admin',
            tim           TEXT NOT NULL DEFAULT 'Umum',
            jabatan       TEXT NOT NULL DEFAULT '',
            aktif         INTEGER NOT NULL DEFAULT 1,
            dibuat_pada   TEXT NOT NULL,
            UNIQUE (email),
            FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_pengguna_org ON pengguna (organisasi_id)',

        "CREATE TABLE IF NOT EXISTS karyawan (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            organisasi_id      INTEGER NOT NULL,
            nama               TEXT NOT NULL,
            kode_qr            TEXT NOT NULL,
            aktif              INTEGER NOT NULL DEFAULT 1,
            tim                TEXT NOT NULL DEFAULT 'Umum',
            jabatan            TEXT NOT NULL DEFAULT '',
            legacy_pengguna_id INTEGER NULL,
            dibuat_pada        TEXT NOT NULL,
            UNIQUE (kode_qr),
            FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_karyawan_org ON karyawan (organisasi_id)',
        'CREATE INDEX IF NOT EXISTS idx_karyawan_legacy ON karyawan (legacy_pengguna_id)',

        "CREATE TABLE IF NOT EXISTS absensi (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            karyawan_id     INTEGER NULL,
            pengguna_id     INTEGER NULL,
            tanggal         TEXT NOT NULL,
            status          TEXT NOT NULL,
            jam_masuk       TEXT NULL,
            jam_pulang      TEXT NULL,
            catatan         TEXT NOT NULL DEFAULT '',
            foto_masuk      TEXT NULL,
            foto_pulang     TEXT NULL,
            barcode_masuk   TEXT NULL,
            barcode_pulang  TEXT NULL,
            lat_masuk       REAL NULL,
            lng_masuk       REAL NULL,
            lat_pulang      REAL NULL,
            lng_pulang      REAL NULL,
            akurasi_masuk   INTEGER NULL,
            akurasi_pulang  INTEGER NULL,
            jarak_masuk_m   INTEGER NULL,
            jarak_pulang_m  INTEGER NULL,
            mode            TEXT NOT NULL DEFAULT '',
            kegiatan        TEXT NOT NULL DEFAULT '',
            dikoreksi_oleh  INTEGER NULL,
            dibuat_pada     TEXT NOT NULL,
            diperbarui_pada TEXT NOT NULL,
            FOREIGN KEY (karyawan_id) REFERENCES karyawan(id) ON DELETE CASCADE
        )",
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_absensi_karyawan_tgl ON absensi (karyawan_id, tanggal)',
        'CREATE INDEX IF NOT EXISTS idx_absensi_karyawan ON absensi (karyawan_id, tanggal)',
        'CREATE INDEX IF NOT EXISTS idx_absensi_pengguna ON absensi (pengguna_id, tanggal)',

        "CREATE TABLE IF NOT EXISTS hasil_klaster (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            organisasi_id INTEGER NOT NULL,
            periode       TEXT NOT NULL,
            k             INTEGER NOT NULL,
            hasil         TEXT NOT NULL,
            dibuat_pada   TEXT NOT NULL,
            FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_klaster_org ON hasil_klaster (organisasi_id)',

        "CREATE TABLE IF NOT EXISTS audit_log (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            pengguna_id   INTEGER NULL,
            organisasi_id INTEGER NULL,
            aksi          TEXT NOT NULL,
            detail        TEXT NOT NULL DEFAULT '',
            dibuat_pada   TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS login_attempt (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            email       TEXT NOT NULL,
            ip          TEXT NOT NULL,
            sukses      INTEGER NOT NULL DEFAULT 0,
            dibuat_pada TEXT NOT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_attempt ON login_attempt (email, ip, sukses, dibuat_pada)',

        "CREATE TABLE IF NOT EXISTS rate_limit (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            kunci       TEXT NOT NULL,
            ip          TEXT NOT NULL,
            pengguna_id INTEGER NULL,
            dibuat_pada TEXT NOT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_rate ON rate_limit (kunci, ip, dibuat_pada)',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

/** SQLite minimal: sisipkan 1 organisasi agar aplikasi langsung bisa dipakai. */
function seed_organisasi_default(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM organisasi')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO organisasi (nama, industri, kode_lokasi, jam_masuk, jam_pulang, toleransi_menit, hari_kerja, wajib_foto, wajib_barcode, dibuat_pada) VALUES ('PT Ketik Media Siber (Ketik.com)', 'media', 'KETIK-SBY-01', '08:00', '17:00', 15, '1,2,3,4,5', 1, 1, ?)");
    $stmt->execute([date('Y-m-d H:i:s')]);
}

/**
 * Menambahkan kolom baru pada tabel yang sudah ada (idempoten).
 * MySQL 8 tidak mendukung "ADD COLUMN IF NOT EXISTS", jadi dicek via
 * INFORMATION_SCHEMA terlebih dahulu; SQLite memakai PRAGMA table_info.
 */
function migrate_schema(PDO $pdo): void
{
    $kolom = [
        'organisasi' => [
            'lat_kantor'     => DB_DRIVER === 'sqlite' ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'lng_kantor'     => DB_DRIVER === 'sqlite' ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'radius_masuk_m' => 'INT NOT NULL DEFAULT 0',
            'wajib_gps'      => DB_DRIVER === 'sqlite' ? 'INTEGER NOT NULL DEFAULT 0' : 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'absensi' => [
            'lat_masuk'      => DB_DRIVER === 'sqlite' ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'lng_masuk'      => DB_DRIVER === 'sqlite' ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'lat_pulang'     => DB_DRIVER === 'sqlite' ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'lng_pulang'     => DB_DRIVER === 'sqlite' ? 'REAL NULL' : 'DECIMAL(10,7) NULL',
            'akurasi_masuk'  => 'INT NULL',
            'akurasi_pulang' => 'INT NULL',
            'jarak_masuk_m'  => 'INT NULL',
            'jarak_pulang_m' => 'INT NULL',
            'mode'           => "VARCHAR(20) NOT NULL DEFAULT ''",
            'kegiatan'       => "VARCHAR(500) NOT NULL DEFAULT ''",
        ],
    ];

    foreach ($kolom as $tabel => $definisi) {
        $ada = table_columns($pdo, $tabel);
        foreach ($definisi as $nama => $sql) {
            if (!in_array($nama, $ada, true)) {
                $pdo->exec("ALTER TABLE `{$tabel}` ADD COLUMN `{$nama}` {$sql}");
            }
        }
    }
}

function table_columns(PDO $pdo, string $tabel): array
{
    if (DB_DRIVER === 'sqlite') {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $tabel . ')');
        $stmt->execute();
        return array_map('strval', array_column($stmt->fetchAll(), 'name'));
    }

    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$tabel]);
    return array_map('strval', array_column($stmt->fetchAll(), 'COLUMN_NAME'));
}
