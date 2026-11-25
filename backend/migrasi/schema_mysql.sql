-- ===========================================================
-- Absensi QR Ketik.com — Skema MySQL (PRD v1.1 + Schema v3)
-- ===========================================================

CREATE DATABASE IF NOT EXISTS `absensi`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `absensi`;

CREATE TABLE IF NOT EXISTS organisasi (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nama            VARCHAR(150) NOT NULL,
    industri        VARCHAR(40)  NOT NULL DEFAULT 'media',
    kode_lokasi     VARCHAR(100) NOT NULL,
    jam_masuk       CHAR(5)      NOT NULL DEFAULT '08:00',
    jam_pulang      CHAR(5)      NOT NULL DEFAULT '17:00',
    toleransi_menit INT          NOT NULL DEFAULT 15,
    hari_kerja      VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5',
    wajib_foto      TINYINT(1)   NOT NULL DEFAULT 1,
    wajib_barcode   TINYINT(1)   NOT NULL DEFAULT 0,
    lat_kantor      DECIMAL(10,7) NULL,
    lng_kantor      DECIMAL(10,7) NULL,
    radius_masuk_m  INT          NOT NULL DEFAULT 0,
    wajib_gps       TINYINT(1)   NOT NULL DEFAULT 0,
    dibuat_pada     DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS divisi_jadwal (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    organisasi_id   INT          NOT NULL,
    nama            VARCHAR(100) NOT NULL,
    kode_unik       VARCHAR(24)  NOT NULL DEFAULT '',
    warna_kartu     VARCHAR(20)  NOT NULL DEFAULT '#0A88C9',
    jam_masuk       CHAR(5)      NOT NULL DEFAULT '08:00',
    jam_pulang      CHAR(5)      NOT NULL DEFAULT '17:00',
    toleransi_menit INT          NOT NULL DEFAULT 15,
    aktif           TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_pada     DATETIME     NOT NULL,
    UNIQUE KEY uq_divisi_org_nama (organisasi_id, nama),
    CONSTRAINT fk_divisi_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shift_kerja (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    organisasi_id INT          NOT NULL,
    nama          VARCHAR(100) NOT NULL,
    jam_masuk     CHAR(5)      NOT NULL DEFAULT '08:00',
    jam_pulang    CHAR(5)      NOT NULL DEFAULT '17:00',
    aktif         TINYINT(1)   NOT NULL DEFAULT 1,
    CONSTRAINT fk_shift_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hari_libur (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    organisasi_id INT          NOT NULL,
    tanggal       DATE         NOT NULL,
    keterangan    VARCHAR(150) NOT NULL,
    dibuat_pada   DATETIME     NOT NULL,
    UNIQUE KEY uq_libur_org_tgl (organisasi_id, tanggal),
    CONSTRAINT fk_libur_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pengguna (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    organisasi_id INT          NOT NULL,
    nama          VARCHAR(150) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    no_hp         VARCHAR(32)  NOT NULL DEFAULT '',
    foto          VARCHAR(190) NULL,
    kata_sandi    VARCHAR(255) NOT NULL,
    peran         VARCHAR(20)  NOT NULL DEFAULT 'karyawan',
    tim           VARCHAR(100) NOT NULL DEFAULT 'Redaksi',
    jabatan       VARCHAR(100) NOT NULL DEFAULT '',
    tipe          VARCHAR(20)  NOT NULL DEFAULT 'karyawan',
    kampus        VARCHAR(150) NOT NULL DEFAULT '',
    tgl_mulai     DATE         NULL,
    tgl_selesai   DATE         NULL,
    aktif         TINYINT(1)   NOT NULL DEFAULT 1,
    dibuat_pada   DATETIME     NOT NULL,
    UNIQUE KEY uq_pengguna_email (email),
    KEY idx_pengguna_org (organisasi_id),
    CONSTRAINT fk_pengguna_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS karyawan (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    organisasi_id      INT          NOT NULL,
    nama               VARCHAR(150) NOT NULL,
    kode_qr            VARCHAR(64)  NOT NULL,
    aktif              TINYINT(1)   NOT NULL DEFAULT 1,
    status_kartu       VARCHAR(20)  NOT NULL DEFAULT 'aktif',
    alasan_cabut       VARCHAR(255) NOT NULL DEFAULT '',
    diterbitkan_pada   DATETIME     NULL,
    dicabut_pada       DATETIME     NULL,
    terakhir_dipakai   DATETIME     NULL,
    tim                VARCHAR(100) NOT NULL DEFAULT 'Redaksi',
    jabatan            VARCHAR(100) NOT NULL DEFAULT '',
    legacy_pengguna_id INT          NULL,
    dibuat_pada        DATETIME     NOT NULL,
    UNIQUE KEY uq_karyawan_kode_qr (kode_qr),
    KEY idx_karyawan_org (organisasi_id),
    KEY idx_karyawan_legacy (legacy_pengguna_id),
    CONSTRAINT fk_karyawan_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS absensi (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    karyawan_id       INT           NULL,
    pengguna_id       INT           NULL,
    tanggal           DATE          NOT NULL,
    status            VARCHAR(20)   NOT NULL,
    jam_masuk         TIME          NULL,
    jam_pulang        TIME          NULL,
    catatan           VARCHAR(500)  NOT NULL DEFAULT '',
    foto_masuk        VARCHAR(190)  NULL,
    foto_pulang       VARCHAR(190)  NULL,
    barcode_masuk     VARCHAR(190)  NULL,
    barcode_pulang    VARCHAR(190)  NULL,
    lat_masuk         DECIMAL(10,7) NULL,
    lng_masuk         DECIMAL(10,7) NULL,
    lat_pulang        DECIMAL(10,7) NULL,
    lng_pulang        DECIMAL(10,7) NULL,
    akurasi_masuk     INT           NULL,
    akurasi_pulang    INT           NULL,
    jarak_masuk_m     INT           NULL,
    jarak_pulang_m    INT           NULL,
    mode              VARCHAR(20)   NOT NULL DEFAULT '',
    kegiatan          VARCHAR(500)  NOT NULL DEFAULT '',
    shift_nama        VARCHAR(100)  NOT NULL DEFAULT '',
    jam_jadwal_masuk  CHAR(5)       NULL,
    jam_jadwal_pulang CHAR(5)       NULL,
    device_hash       VARCHAR(64)   NOT NULL DEFAULT '',
    anomali           VARCHAR(255)  NOT NULL DEFAULT '',
    approval_status   VARCHAR(20)   NOT NULL DEFAULT 'disetujui',
    diproses_oleh     INT           NULL,
    jenis_izin        VARCHAR(30)   NOT NULL DEFAULT '',
    dikoreksi_oleh    INT           NULL,
    dibuat_pada       DATETIME      NOT NULL,
    diperbarui_pada   DATETIME      NOT NULL,
    UNIQUE KEY uq_absensi_karyawan_tgl (karyawan_id, tanggal),
    UNIQUE KEY uq_absensi_pengguna_tgl (pengguna_id, tanggal),
    KEY idx_absensi_karyawan (karyawan_id, tanggal),
    KEY idx_absensi_pengguna (pengguna_id, tanggal),
    CONSTRAINT fk_absensi_karyawan FOREIGN KEY (karyawan_id) REFERENCES karyawan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hasil_klaster (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    organisasi_id INT         NOT NULL,
    periode       VARCHAR(60) NOT NULL,
    k             INT         NOT NULL,
    hasil         LONGTEXT    NOT NULL,
    dibuat_pada   DATETIME    NOT NULL,
    KEY idx_klaster_org (organisasi_id),
    CONSTRAINT fk_klaster_org FOREIGN KEY (organisasi_id) REFERENCES organisasi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    pengguna_id   INT          NULL,
    organisasi_id INT          NULL,
    aksi          VARCHAR(60)  NOT NULL,
    detail        VARCHAR(500) NOT NULL DEFAULT '',
    dibuat_pada   DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempt (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    sukses      TINYINT(1)   NOT NULL DEFAULT 0,
    dibuat_pada DATETIME     NOT NULL,
    KEY idx_attempt (email, ip, sukses, dibuat_pada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limit (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    kunci       VARCHAR(190) NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    pengguna_id INT          NULL,
    dibuat_pada DATETIME     NOT NULL,
    KEY idx_rate (kunci, ip, dibuat_pada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schema_meta (
    id  INT PRIMARY KEY,
    ver INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO schema_meta (id, ver) VALUES (1, 3) ON DUPLICATE KEY UPDATE ver = 3;
