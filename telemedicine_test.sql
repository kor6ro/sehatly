-- ============================================================================
-- APLIKASI TELEMEDISIN INDONESIA (Model Halodoc)
-- ----------------------------------------------------------------------------
-- Versi      : 1.0
-- MySQL      : 8.0+
-- Charset    : utf8mb4 (mendukung penuh karakter Unicode/emoji)
-- Skema      : 75 tabel, 2 view, seed data master
-- Kepatuhan  : Permenkes 24/2022 (RME), UU PDP 27/2022, SATUSEHAT, BPJS V-Claim
-- ============================================================================

CREATE DATABASE IF NOT EXISTS telemedisin_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE telemedisin_db;

-- ============================================================================
-- [0] RESET (aman untuk re-import berkali-kali)
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW IF EXISTS v_pendapatan_bulanan;
DROP VIEW IF EXISTS v_dokter_katalog;

DROP TABLE IF EXISTS akses_rekam_medis_log, persetujuan_pdp, audit_log;
DROP TABLE IF EXISTS home_care_pesanan, artikel, artikel_kategori;
DROP TABLE IF EXISTS ulasan_dokter, notifikasi;
DROP TABLE IF EXISTS klaim_bpjs, promo_redemption, master_promo;
DROP TABLE IF EXISTS refund, pembayaran, invoice, master_metode_pembayaran;
DROP TABLE IF EXISTS lab_hasil, lab_permintaan_detail, lab_permintaan;
DROP TABLE IF EXISTS lab_paket_item, master_lab_paket, master_lab_tindakan;
DROP TABLE IF EXISTS apotek_stok, pesanan_obat_tracking, pesanan_obat;
DROP TABLE IF EXISTS resep_verifikasi, resep_item, resep, obat_interaksi, master_obat;
DROP TABLE IF EXISTS rekam_medis_persetujuan, rekam_medis_lampiran;
DROP TABLE IF EXISTS rekam_medis_tindakan, rekam_medis_diagnosa, rekam_medis;
DROP TABLE IF EXISTS rujukan, surat_keterangan, konsultasi_chat, konsultasi, booking;
DROP TABLE IF EXISTS dokter_libur, dokter_jadwal, dokter_pendidikan;
DROP TABLE IF EXISTS dokter_faskes, dokter_spesialisasi, dokter, master_spesialisasi;
DROP TABLE IF EXISTS faskes_layanan, faskes;
DROP TABLE IF EXISTS pasien_penjamin, master_penjamin, pasien_tanda_vital;
DROP TABLE IF EXISTS pasien_imunisasi, pasien_riwayat_penyakit, pasien_alergi;
DROP TABLE IF EXISTS pasien_anggota_keluarga, pasien;
DROP TABLE IF EXISTS user_refresh_tokens, user_devices, user_otp;
DROP TABLE IF EXISTS user_roles, role_permissions, permissions, roles, users;
DROP TABLE IF EXISTS master_icd9cm, master_icd10, master_hubungan_keluarga;
DROP TABLE IF EXISTS master_status_pernikahan, master_pendidikan;
DROP TABLE IF EXISTS master_golongan_darah, master_agama;
DROP TABLE IF EXISTS master_kelurahan, master_kecamatan;
DROP TABLE IF EXISTS master_kabupaten_kota, master_provinsi;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- [1] MASTER DATA
-- ============================================================================

-- 1.1 Wilayah administratif Indonesia (kode Kemendagri/BPS)
CREATE TABLE master_provinsi (
  id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode CHAR(2) NOT NULL UNIQUE COMMENT 'Kode Kemendagri/BPS',
  nama VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE master_kabupaten_kota (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  provinsi_id TINYINT UNSIGNED NOT NULL,
  kode CHAR(4) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id)
) ENGINE=InnoDB;

CREATE TABLE master_kecamatan (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kabupaten_kota_id SMALLINT UNSIGNED NOT NULL,
  kode CHAR(7) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  FOREIGN KEY (kabupaten_kota_id) REFERENCES master_kabupaten_kota(id)
) ENGINE=InnoDB;

CREATE TABLE master_kelurahan (
  id MEDIUMINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kecamatan_id SMALLINT UNSIGNED NOT NULL,
  kode CHAR(10) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  FOREIGN KEY (kecamatan_id) REFERENCES master_kecamatan(id)
) ENGINE=InnoDB;

-- 1.2 Master umum (format KTP & SATUSEHAT)
CREATE TABLE master_agama (
  id TINYINT UNSIGNED PRIMARY KEY,
  nama VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE master_golongan_darah (
  id TINYINT UNSIGNED PRIMARY KEY,
  kode ENUM('A','B','AB','O') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE master_pendidikan (
  id TINYINT UNSIGNED PRIMARY KEY,
  nama VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE master_status_pernikahan (
  id TINYINT UNSIGNED PRIMARY KEY,
  nama ENUM('belum_menikah','menikah','cerai_hidup','cerai_mati') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE master_hubungan_keluarga (
  id TINYINT UNSIGNED PRIMARY KEY,
  nama VARCHAR(50) NOT NULL COMMENT 'Pasangan/Anak/Orang Tua/Saudara/Lainnya'
) ENGINE=InnoDB;

-- 1.3 Kodingan medis standar Kemenkes
CREATE TABLE master_icd10 (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(8) NOT NULL UNIQUE,
  deskripsi VARCHAR(255) NOT NULL,
  INDEX idx_icd10 (kode)
) ENGINE=InnoDB;

CREATE TABLE master_icd9cm (
  id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(8) NOT NULL UNIQUE,
  deskripsi VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ============================================================================
-- [2] USER & AUTENTIKASI
-- ============================================================================

CREATE TABLE users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL UNIQUE,
  nama_lengkap VARCHAR(150) NOT NULL,
  email VARCHAR(255) NULL UNIQUE,
  no_telepon VARCHAR(20) NOT NULL UNIQUE,
  kata_sandi_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon2',
  tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') NOT NULL DEFAULT 'pasien',
  status ENUM('pending_verifikasi','aktif','nonaktif','ditangguhkan') NOT NULL DEFAULT 'pending_verifikasi',
  foto_profil VARCHAR(500) NULL,
  bahasa ENUM('id','en') NOT NULL DEFAULT 'id',
  telepon_terverifikasi TINYINT(1) NOT NULL DEFAULT 0,
  email_terverifikasi TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  dihapus_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE roles (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nama VARCHAR(50) NOT NULL UNIQUE,
  deskripsi VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(100) NOT NULL UNIQUE COMMENT 'cth: rekam_medis.lihat, resep.buat',
  nama VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_otp (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  kode_hash VARCHAR(255) NOT NULL COMMENT 'Simpan hash, bukan OTP asli',
  tujuan ENUM('verifikasi_telepon','verifikasi_email','reset_kata_sandi','login') NOT NULL,
  kedaluwarsa_at DATETIME NOT NULL,
  sudah_dipakai TINYINT(1) NOT NULL DEFAULT 0,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_devices (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  device_id VARCHAR(255) NOT NULL,
  platform ENUM('android','ios','web') NOT NULL,
  fcm_token VARCHAR(255) NULL,
  app_versi VARCHAR(20) NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  last_active_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_device (user_id, device_id)
) ENGINE=InnoDB;

CREATE TABLE user_refresh_tokens (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  kedaluwarsa_at DATETIME NOT NULL,
  dicabut TINYINT(1) NOT NULL DEFAULT 0,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- [3] PASIEN
-- ============================================================================

CREATE TABLE pasien (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  nomor_rm VARCHAR(20) NULL UNIQUE COMMENT 'Nomor rekam medis aplikasi: RM-YYYYMM-XXXXXX',
  nik_cipher TEXT NULL COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP',
  nomor_kk CHAR(16) NULL,
  nomor_ihs_satusehat VARCHAR(50) NULL UNIQUE COMMENT 'Nomor Induk Satu Sehat (Kemenkes)',
  jenis_kelamin ENUM('L','P') NOT NULL,
  tanggal_lahir DATE NOT NULL,
  tempat_lahir VARCHAR(100) NULL,
  golongan_darah_id TINYINT UNSIGNED NULL,
  rhesus ENUM('positif','negatif','tidak_diketahui') NOT NULL DEFAULT 'tidak_diketahui',
  agama_id TINYINT UNSIGNED NULL,
  pendidikan_id TINYINT UNSIGNED NULL,
  pekerjaan VARCHAR(100) NULL,
  status_pernikahan_id TINYINT UNSIGNED NULL,
  alamat_lengkap TEXT NOT NULL,
  provinsi_id TINYINT UNSIGNED NULL,
  kabupaten_kota_id SMALLINT UNSIGNED NULL,
  kecamatan_id SMALLINT UNSIGNED NULL,
  kelurahan_id MEDIUMINT UNSIGNED NULL,
  rt VARCHAR(5) NULL,
  rw VARCHAR(5) NULL,
  kode_pos CHAR(5) NULL,
  catatan_alergi TEXT NULL,
  tinggi_badan_cm DECIMAL(5,1) NULL,
  berat_badan_kg DECIMAL(5,2) NULL,
  is_meninggal TINYINT(1) NOT NULL DEFAULT 0,
  tanggal_meninggal DATE NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  dihapus_at TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (golongan_darah_id) REFERENCES master_golongan_darah(id),
  FOREIGN KEY (agama_id) REFERENCES master_agama(id),
  FOREIGN KEY (pendidikan_id) REFERENCES master_pendidikan(id),
  FOREIGN KEY (status_pernikahan_id) REFERENCES master_status_pernikahan(id),
  INDEX idx_pasien_lahir (tanggal_lahir)
) ENGINE=InnoDB;

-- Anggota keluarga (didaftarkan oleh pasien, TANPA akun sendiri)
CREATE TABLE pasien_anggota_keluarga (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pasien_id BIGINT UNSIGNED NOT NULL COMMENT 'Pemegang akun',
  hubungan_id TINYINT UNSIGNED NOT NULL,
  nik CHAR(16) NULL,
  nama_lengkap VARCHAR(150) NOT NULL,
  jenis_kelamin ENUM('L','P') NOT NULL,
  tanggal_lahir DATE NOT NULL,
  no_telepon VARCHAR(20) NULL,
  catatan_alergi TEXT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE,
  FOREIGN KEY (hubungan_id) REFERENCES master_hubungan_keluarga(id)
) ENGINE=InnoDB;

CREATE TABLE pasien_alergi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pasien_id BIGINT UNSIGNED NOT NULL,
  tipe_alergen ENUM('obat','makanan','lingkungan','lainnya') NOT NULL,
  nama_alergen VARCHAR(150) NOT NULL,
  reaksi VARCHAR(255) NULL,
  keparahan ENUM('ringan','sedang','berat','anafilaksis') NOT NULL DEFAULT 'ringan',
  dicatat_oleh_user_id BIGINT UNSIGNED NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pasien_riwayat_penyakit (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pasien_id BIGINT UNSIGNED NOT NULL,
  tipe ENUM('pribadi','keluarga') NOT NULL,
  nama_penyakit VARCHAR(150) NOT NULL,
  icd10_kode VARCHAR(8) NULL,
  tahun_terdiagnosis YEAR NULL,
  status ENUM('aktif','kronis','sembuh') NOT NULL DEFAULT 'aktif',
  keterangan TEXT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE,
  INDEX idx_icd10 (icd10_kode)
) ENGINE=InnoDB;

CREATE TABLE pasien_imunisasi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pasien_id BIGINT UNSIGNED NOT NULL,
  nama_vaksin VARCHAR(150) NOT NULL,
  tanggal DATE NOT NULL,
  dosis_ke TINYINT UNSIGNED NULL,
  no_batch VARCHAR(50) NULL,
  pemberi VARCHAR(150) NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pasien_tanda_vital (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pasien_id BIGINT UNSIGNED NOT NULL,
  rekam_medis_id BIGINT UNSIGNED NULL,
  sistolik SMALLINT UNSIGNED NULL,
  diastolik SMALLINT UNSIGNED NULL,
  nadi SMALLINT UNSIGNED NULL,
  suhu DECIMAL(4,1) NULL,
  laju_pernapasan SMALLINT UNSIGNED NULL,
  spo2 TINYINT UNSIGNED NULL,
  tinggi_cm DECIMAL(5,1) NULL,
  berat_kg DECIMAL(5,2) NULL,
  glukosa_darah DECIMAL(6,1) NULL,
  sumber ENUM('mandiri','dokter','perawat','iot_device') NOT NULL DEFAULT 'mandiri',
  diukur_at DATETIME NOT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE,
  INDEX idx_vital_pasien (pasien_id, diukur_at)
) ENGINE=InnoDB;

-- 3.1 Penjamin bayar (BPJS & Asuransi)
CREATE TABLE master_penjamin (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nama VARCHAR(150) NOT NULL,
  tipe ENUM('bpjs','asuransi_swasta','perusahaan','tunai') NOT NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE pasien_penjamin (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pasien_id BIGINT UNSIGNED NOT NULL,
  penjamin_id SMALLINT UNSIGNED NOT NULL,
  nomor_peserta VARCHAR(30) NOT NULL COMMENT '13 digit untuk BPJS',
  kelas_rawat ENUM('kelas_1','kelas_2','kelas_3') NULL,
  faskes_rujukan_id BIGINT UNSIGNED NULL COMMENT 'Faskes tingkat 1 (untuk BPJS)',
  masa_berlaku_akhir DATE NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  file_kartu VARCHAR(500) NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id) ON DELETE CASCADE,
  FOREIGN KEY (penjamin_id) REFERENCES master_penjamin(id),
  UNIQUE KEY uq_peserta (penjamin_id, nomor_peserta)
) ENGINE=InnoDB;

-- ============================================================================
-- [4] FASILITAS KESEHATAN
-- ============================================================================

CREATE TABLE faskes (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode_faskes VARCHAR(20) NULL UNIQUE COMMENT 'Kode faskes BPJS/Kemenkes',
  satusehat_org_id VARCHAR(50) NULL,
  nama VARCHAR(200) NOT NULL,
  tipe ENUM('rumah_sakit','klinik','puskesmas','apotek','laboratorium') NOT NULL,
  kelas_rs VARCHAR(50) NULL COMMENT 'A/B/C/D (khusus rumah sakit)',
  alamat TEXT NOT NULL,
  provinsi_id TINYINT UNSIGNED NULL,
  kabupaten_kota_id SMALLINT UNSIGNED NULL,
  kecamatan_id SMALLINT UNSIGNED NULL,
  kode_pos CHAR(5) NULL,
  latitude DECIMAL(10,8) NULL,
  longitude DECIMAL(11,8) NULL,
  telepon VARCHAR(20) NULL,
  email VARCHAR(255) NULL,
  akreditasi ENUM('belum','dasar','utama','maju','paripurna') NULL,
  jam_operasional JSON NULL COMMENT '{"senin":{"buka":"07:00","tutup":"22:00"},...}',
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (provinsi_id) REFERENCES master_provinsi(id),
  FOREIGN KEY (kabupaten_kota_id) REFERENCES master_kabupaten_kota(id),
  FOREIGN KEY (kecamatan_id) REFERENCES master_kecamatan(id),
  INDEX idx_faskes_tipe (tipe, status_aktif),
  INDEX idx_faskes_geo (latitude, longitude)
) ENGINE=InnoDB;

CREATE TABLE faskes_layanan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  faskes_id BIGINT UNSIGNED NOT NULL,
  nama_layanan VARCHAR(150) NOT NULL,
  deskripsi TEXT NULL,
  harga DECIMAL(12,2) NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (faskes_id) REFERENCES faskes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- [5] TENAGA MEDIS
-- ============================================================================

CREATE TABLE master_spesialisasi (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(10) NOT NULL UNIQUE COMMENT 'SP.PD, SP.A, SP.OG, dst',
  nama VARCHAR(100) NOT NULL,
  tipe ENUM('dokter_umum','spesialis','subspesialis') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE dokter (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  tipe ENUM('dokter_umum','dokter_spesialis','dokter_gigi','psikolog','bidan','perawat','apoteker') NOT NULL,
  nomor_str VARCHAR(30) NOT NULL UNIQUE,
  str_berlaku_sampai DATE NOT NULL,
  nomor_sip VARCHAR(50) NULL,
  sip_berlaku_sampai DATE NULL,
  nomor_ihs_satusehat VARCHAR(50) NULL,
  pengalaman_tahun SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bio TEXT NULL,
  biaya_konsultasi_online DECIMAL(12,2) NOT NULL DEFAULT 0,
  biaya_luar_jam DECIMAL(12,2) NULL,
  durasi_default_menit SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  rating_rata_rata DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  jumlah_ulasan INT UNSIGNED NOT NULL DEFAULT 0,
  jumlah_konsultasi INT UNSIGNED NOT NULL DEFAULT 0,
  tersedia_telemedisin TINYINT(1) NOT NULL DEFAULT 1,
  status_verifikasi ENUM('pending','terverifikasi','ditolak') NOT NULL DEFAULT 'pending',
  file_str_url VARCHAR(500) NULL,
  file_sip_url VARCHAR(500) NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_dokter_tipe (tipe, status_aktif, tersedia_telemedisin)
) ENGINE=InnoDB;

CREATE TABLE dokter_spesialisasi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  dokter_id BIGINT UNSIGNED NOT NULL,
  spesialisasi_id SMALLINT UNSIGNED NOT NULL,
  is_utama TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (dokter_id) REFERENCES dokter(id) ON DELETE CASCADE,
  FOREIGN KEY (spesialisasi_id) REFERENCES master_spesialisasi(id),
  UNIQUE KEY uq_dokter_spes (dokter_id, spesialisasi_id)
) ENGINE=InnoDB;

CREATE TABLE dokter_faskes (
  dokter_id BIGINT UNSIGNED NOT NULL,
  faskes_id BIGINT UNSIGNED NOT NULL,
  is_utama TINYINT(1) NOT NULL DEFAULT 0,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (dokter_id, faskes_id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id) ON DELETE CASCADE,
  FOREIGN KEY (faskes_id) REFERENCES faskes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dokter_pendidikan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  dokter_id BIGINT UNSIGNED NOT NULL,
  jenjang ENUM('s1_kedokteran','profesi','sp1','sp2','s2','s3','lainnya') NOT NULL,
  institusi VARCHAR(200) NOT NULL,
  tahun_lulus SMALLINT UNSIGNED NULL,
  FOREIGN KEY (dokter_id) REFERENCES dokter(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- [6] JADWAL & BOOKING
-- ============================================================================

CREATE TABLE dokter_jadwal (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  dokter_id BIGINT UNSIGNED NOT NULL,
  faskes_id BIGINT UNSIGNED NULL COMMENT 'NULL = layanan online murni',
  tipe_layanan ENUM('online','klinik','home_visit') NOT NULL DEFAULT 'online',
  hari TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu',
  jam_mulai TIME NOT NULL,
  jam_selesai TIME NOT NULL,
  durasi_slot_menit SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  kuota_per_sesi SMALLINT UNSIGNED NULL,
  berlaku_mulai DATE NOT NULL,
  berlaku_sampai DATE NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (dokter_id) REFERENCES dokter(id) ON DELETE CASCADE,
  FOREIGN KEY (faskes_id) REFERENCES faskes(id),
  INDEX idx_jadwal (dokter_id, hari, status_aktif)
) ENGINE=InnoDB;

CREATE TABLE dokter_libur (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  dokter_id BIGINT UNSIGNED NOT NULL,
  tanggal DATE NOT NULL,
  alasan VARCHAR(200) NULL,
  FOREIGN KEY (dokter_id) REFERENCES dokter(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE booking (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_booking VARCHAR(30) NOT NULL UNIQUE,
  pasien_id BIGINT UNSIGNED NOT NULL,
  anggota_keluarga_id BIGINT UNSIGNED NULL COMMENT 'NULL = untuk pasien sendiri',
  dokter_id BIGINT UNSIGNED NOT NULL,
  jadwal_id BIGINT UNSIGNED NULL,
  faskes_id BIGINT UNSIGNED NULL,
  tipe_layanan ENUM('chat','video_call','kunjungan_klinik','home_visit') NOT NULL,
  tanggal_kunjungan DATE NOT NULL,
  slot_mulai TIME NOT NULL,
  slot_selesai TIME NOT NULL,
  nomor_antrian SMALLINT UNSIGNED NULL,
  keluhan TEXT NULL,
  lampiran_keluhan JSON NULL,
  is_rujukan TINYINT(1) NOT NULL DEFAULT 0,
  is_konsultasi_lanjutan TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai',
              'dibatalkan','no_show','kadaluarsa') NOT NULL DEFAULT 'menunggu_pembayaran',
  dibatalkan_oleh ENUM('pasien','dokter','sistem') NULL,
  alasan_pembatalan VARCHAR(255) NULL,
  dibuat_oleh_user_id BIGINT UNSIGNED NOT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (anggota_keluarga_id) REFERENCES pasien_anggota_keluarga(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id),
  FOREIGN KEY (jadwal_id) REFERENCES dokter_jadwal(id),
  FOREIGN KEY (faskes_id) REFERENCES faskes(id),
  FOREIGN KEY (dibuat_oleh_user_id) REFERENCES users(id),
  INDEX idx_booking_dokter (dokter_id, tanggal_kunjungan),
  INDEX idx_booking_pasien (pasien_id, status)
) ENGINE=InnoDB;

-- ============================================================================
-- [7] KONSULTASI (CORE TELEMEDISIN)
-- ============================================================================

CREATE TABLE konsultasi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NULL UNIQUE COMMENT 'NULL = fitur "Tanya Dokter" instan 24 jam',
  pasien_id BIGINT UNSIGNED NOT NULL,
  dokter_id BIGINT UNSIGNED NOT NULL,
  tipe ENUM('chat','video_call','telepon') NOT NULL,
  status ENUM('menunggu_dokter','berlangsung','menunggu_resep','selesai','dibatalkan','gagal')
         NOT NULL DEFAULT 'menunggu_dokter',
  room_id VARCHAR(100) NULL COMMENT 'ID room video SDK (Agora/Twilio/100ms)',
  mulai_at DATETIME NULL,
  selesai_at DATETIME NULL,
  total_durasi_detik INT UNSIGNED NULL,
  catatan_subjektif TEXT NULL,
  catatan_objektif TEXT NULL,
  catatan_asessment TEXT NULL,
  catatan_plan TEXT NULL,
  diagnosis_kerja VARCHAR(255) NULL,
  saran_tindak_lanjut TEXT NULL,
  biaya_konsultasi DECIMAL(12,2) NOT NULL DEFAULT 0,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES booking(id),
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id),
  INDEX idx_konsultasi_pasien (pasien_id, status)
) ENGINE=InnoDB;

CREATE TABLE konsultasi_chat (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  konsultasi_id BIGINT UNSIGNED NOT NULL,
  pengirim_user_id BIGINT UNSIGNED NOT NULL,
  pengirim_tipe ENUM('pasien','dokter','sistem') NOT NULL,
  tipe_pesan ENUM('teks','gambar','dokumen','audio','video_note','resep','surat_keterangan','sistem')
             NOT NULL DEFAULT 'teks',
  isi TEXT NULL,
  file_url VARCHAR(500) NULL,
  file_nama VARCHAR(255) NULL,
  file_ukuran_kb INT UNSIGNED NULL,
  dibaca_at DATETIME NULL,
  terkirim_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (konsultasi_id) REFERENCES konsultasi(id) ON DELETE CASCADE,
  FOREIGN KEY (pengirim_user_id) REFERENCES users(id),
  INDEX idx_chat (konsultasi_id, terkirim_at)
) ENGINE=InnoDB;

CREATE TABLE surat_keterangan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_surat VARCHAR(50) NOT NULL UNIQUE,
  konsultasi_id BIGINT UNSIGNED NULL,
  tipe ENUM('surat_sakit','surat_sehat','surat_rujukan','surat_kematian') NOT NULL,
  pasien_id BIGINT UNSIGNED NOT NULL,
  dokter_id BIGINT UNSIGNED NOT NULL,
  tanggal_mulai DATE NULL,
  tanggal_selesai DATE NULL,
  jumlah_hari TINYINT UNSIGNED NULL,
  isi TEXT NULL,
  qr_token VARCHAR(100) NOT NULL COMMENT 'Token QR verifikasi keaslian',
  file_url VARCHAR(500) NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id)
) ENGINE=InnoDB;

CREATE TABLE rujukan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  surat_keterangan_id BIGINT UNSIGNED NOT NULL,
  faskes_asal_id BIGINT UNSIGNED NULL,
  faskes_tujuan_id BIGINT UNSIGNED NOT NULL,
  dokter_perujuk_id BIGINT UNSIGNED NOT NULL,
  diagnosis_kerja VARCHAR(255) NULL,
  icd10_kode VARCHAR(8) NULL,
  alasan_rujukan TEXT NULL,
  berlaku_sampai DATE NOT NULL,
  nomor_sep VARCHAR(30) NULL COMMENT 'Diisi jika klaim BPJS (V-Claim)',
  status ENUM('aktif','terpakai','kedaluwarsa') NOT NULL DEFAULT 'aktif',
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (surat_keterangan_id) REFERENCES surat_keterangan(id),
  FOREIGN KEY (faskes_tujuan_id) REFERENCES faskes(id),
  FOREIGN KEY (dokter_perujuk_id) REFERENCES dokter(id)
) ENGINE=InnoDB;

-- ============================================================================
-- [8] REKAM MEDIS (Permenkes 24/2022)
-- ============================================================================

CREATE TABLE rekam_medis (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL UNIQUE,
  pasien_id BIGINT UNSIGNED NOT NULL,
  faskes_id BIGINT UNSIGNED NULL,
  dokter_id BIGINT UNSIGNED NOT NULL,
  konsultasi_id BIGINT UNSIGNED NULL,
  satusehat_encounter_id VARCHAR(50) NULL,
  tipe_kunjungan ENUM('telemedisin','rawat_jalan','rawat_inap','igd','home_visit') NOT NULL,
  tanggal_periksa DATETIME NOT NULL,
  keluhan_utama TEXT NULL,
  riwayat_penyakit_sekarang TEXT NULL,
  riwayat_penyakit_dahulu TEXT NULL,
  riwayat_keluarga TEXT NULL,
  riwayat_psikososial TEXT NULL COMMENT 'Merokok, alkohol, aktivitas fisik',
  hasil_pemeriksaan_fisik TEXT NULL,
  subjektif TEXT NULL COMMENT 'SOAP - S',
  objektif TEXT NULL COMMENT 'SOAP - O',
  asesmen TEXT NULL COMMENT 'SOAP - A',
  plan TEXT NULL COMMENT 'SOAP - P',
  diagnosis_kerja VARCHAR(255) NULL,
  instruksi_tindak_lanjut TEXT NULL,
  status_tindak_lanjut ENUM('pulang_dengan_obat','kontrol','rujuk','rawat_inap','ke_igd') NULL,
  jadwal_kontrol DATE NULL,
  status_dokumen ENUM('draft','final','diamendemen') NOT NULL DEFAULT 'final',
  versi TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ditandatangani_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (faskes_id) REFERENCES faskes(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id),
  FOREIGN KEY (konsultasi_id) REFERENCES konsultasi(id),
  INDEX idx_rm_pasien (pasien_id, tanggal_periksa)
) ENGINE=InnoDB;

CREATE TABLE rekam_medis_diagnosa (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  rekam_medis_id BIGINT UNSIGNED NOT NULL,
  icd10_kode VARCHAR(8) NOT NULL,
  deskripsi VARCHAR(255) NULL,
  jenis ENUM('utama','sekunder','diferensial','komplikasi') NOT NULL,
  tipe_kasus ENUM('baru','lama') NOT NULL DEFAULT 'baru',
  is_terkonfirmasi TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE,
  INDEX idx_diag_icd10 (icd10_kode)
) ENGINE=InnoDB;

CREATE TABLE rekam_medis_tindakan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  rekam_medis_id BIGINT UNSIGNED NOT NULL,
  icd9cm_kode VARCHAR(8) NULL,
  nama_tindakan VARCHAR(255) NOT NULL,
  keterangan TEXT NULL,
  tanggal_tindakan DATETIME NOT NULL,
  dokter_pelaksana_id BIGINT UNSIGNED NULL,
  FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE,
  FOREIGN KEY (dokter_pelaksana_id) REFERENCES dokter(id)
) ENGINE=InnoDB;

CREATE TABLE rekam_medis_lampiran (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  rekam_medis_id BIGINT UNSIGNED NOT NULL,
  nama_file VARCHAR(255) NOT NULL,
  file_url VARCHAR(500) NOT NULL,
  tipe ENUM('hasil_lab','radiologi','foto_klinis','dokumen_lain') NOT NULL,
  diunggah_oleh BIGINT UNSIGNED NOT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rekam_medis_persetujuan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  rekam_medis_id BIGINT UNSIGNED NOT NULL,
  tipe ENUM('general_consent','persetujuan_tindakan','penolakan_tindakan') NOT NULL,
  isi_persetujuan TEXT NOT NULL,
  ditandatangani_oleh VARCHAR(150) NOT NULL,
  hubungan_dengan_pasien VARCHAR(50) NULL,
  tanda_tangan_url VARCHAR(500) NULL,
  ditandatangani_at DATETIME NOT NULL,
  FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- [9] RESEP & FARMASI
-- ============================================================================

CREATE TABLE master_obat (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode_obat VARCHAR(30) NOT NULL UNIQUE,
  nama_generik VARCHAR(255) NOT NULL,
  nama_brand VARCHAR(255) NULL,
  bentuk_sediaan ENUM('tablet','kaplet','kapsul','sirup','salep','krim','gel',
                      'tetes','injeksi','inhaler','suppositoria','lainnya') NOT NULL,
  kekuatan VARCHAR(50) NULL COMMENT '500 mg',
  satuan ENUM('tablet','kapsul','botol','tube','ampul','sachet','strip','box') NOT NULL,
  pabrikan VARCHAR(150) NULL,
  kelas_terapi VARCHAR(100) NULL COMMENT 'Antibiotik, Analgetik, dll',
  kelas_obat ENUM('bebas','bebas_terbatas','keras','fitofarmaka','narkotika','psikotropika') NOT NULL,
  requires_resep TINYINT(1) NOT NULL DEFAULT 1,
  aturan_pakai_umum VARCHAR(255) NULL COMMENT '3 x 1 tablet sesudah makan',
  indikasi TEXT NULL,
  kontraindikasi TEXT NULL,
  harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_obat_nama (nama_generik)
) ENGINE=InnoDB;

CREATE TABLE obat_interaksi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  obat_a_id BIGINT UNSIGNED NOT NULL,
  obat_b_id BIGINT UNSIGNED NOT NULL,
  tingkat ENUM('ringan','sedang','berat','kontraindikasi') NOT NULL,
  deskripsi TEXT NULL,
  FOREIGN KEY (obat_a_id) REFERENCES master_obat(id) ON DELETE CASCADE,
  FOREIGN KEY (obat_b_id) REFERENCES master_obat(id) ON DELETE CASCADE,
  UNIQUE KEY uq_interaksi (obat_a_id, obat_b_id)
) ENGINE=InnoDB;

CREATE TABLE resep (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_resep VARCHAR(30) NOT NULL UNIQUE,
  konsultasi_id BIGINT UNSIGNED NULL,
  rekam_medis_id BIGINT UNSIGNED NULL,
  pasien_id BIGINT UNSIGNED NOT NULL,
  dokter_id BIGINT UNSIGNED NOT NULL,
  apotek_id BIGINT UNSIGNED NULL COMMENT 'Apotek penuh (faskes tipe apotek)',
  tipe ENUM('digital','manual') NOT NULL DEFAULT 'digital',
  status ENUM('aktif','diproses','diverifikasi','dipenuhi','dikirim','selesai',
              'kedaluwarsa','dibatalkan') NOT NULL DEFAULT 'aktif',
  catatan_dokter TEXT NULL,
  tanggal_resep DATETIME NOT NULL,
  berlaku_sampai DATE NOT NULL COMMENT 'E-resep berlaku 7 hari',
  is_iter TINYINT(1) NOT NULL DEFAULT 0,
  jumlah_iter TINYINT UNSIGNED NOT NULL DEFAULT 0,
  qr_token VARCHAR(100) NOT NULL COMMENT 'Verifikasi keaslian e-resep',
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id),
  FOREIGN KEY (apotek_id) REFERENCES faskes(id),
  INDEX idx_resep_pasien (pasien_id, status)
) ENGINE=InnoDB;

CREATE TABLE resep_item (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  resep_id BIGINT UNSIGNED NOT NULL,
  obat_id BIGINT UNSIGNED NULL COMMENT 'NULL = racikan / obat non-katalog',
  nama_obat VARCHAR(255) NOT NULL COMMENT 'Snapshot nama saat diresepkan',
  kekuatan VARCHAR(50) NULL,
  aturan_pakai VARCHAR(255) NOT NULL,
  jumlah SMALLINT UNSIGNED NOT NULL,
  satuan VARCHAR(30) NULL,
  is_racikan TINYINT(1) NOT NULL DEFAULT 0,
  racikan_nama VARCHAR(100) NULL,
  harga_satuan DECIMAL(12,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  catatan_apoteker TEXT NULL,
  FOREIGN KEY (resep_id) REFERENCES resep(id) ON DELETE CASCADE,
  FOREIGN KEY (obat_id) REFERENCES master_obat(id)
) ENGINE=InnoDB;

-- Wajib secara hukum: e-resep diverifikasi apoteker sebelum dipenuhi
CREATE TABLE resep_verifikasi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  resep_id BIGINT UNSIGNED NOT NULL UNIQUE,
  apoteker_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('sesuai','ada_koreksi','ditolak') NOT NULL,
  catatan TEXT NULL,
  diverifikasi_at DATETIME NOT NULL,
  FOREIGN KEY (resep_id) REFERENCES resep(id),
  FOREIGN KEY (apoteker_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE pesanan_obat (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_pesanan VARCHAR(30) NOT NULL UNIQUE,
  resep_id BIGINT UNSIGNED NULL,
  pasien_id BIGINT UNSIGNED NOT NULL,
  apotek_id BIGINT UNSIGNED NOT NULL,
  tipe ENUM('resep_dokter','obat_bebas','produk_kesehatan') NOT NULL DEFAULT 'resep_dokter',
  alamat_kirim TEXT NOT NULL,
  kurir ENUM('internal','grab_express','gojek','jne','jnt','sicepat') NULL,
  no_resi VARCHAR(50) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  biaya_kirim DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('menunggu_pembayaran','diproses','siap','sedang_dikirim','selesai','dibatalkan')
         NOT NULL DEFAULT 'menunggu_pembayaran',
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (resep_id) REFERENCES resep(id),
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (apotek_id) REFERENCES faskes(id)
) ENGINE=InnoDB;

CREATE TABLE pesanan_obat_tracking (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pesanan_obat_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(100) NOT NULL,
  keterangan VARCHAR(255) NULL,
  lokasi VARCHAR(255) NULL,
  waktu DATETIME NOT NULL,
  FOREIGN KEY (pesanan_obat_id) REFERENCES pesanan_obat(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE apotek_stok (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  apotek_id BIGINT UNSIGNED NOT NULL,
  obat_id BIGINT UNSIGNED NOT NULL,
  jumlah_stok INT NOT NULL DEFAULT 0,
  stok_minimum INT NOT NULL DEFAULT 0,
  harga_jual DECIMAL(12,2) NOT NULL DEFAULT 0,
  kedaluwarsa DATE NULL,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (apotek_id) REFERENCES faskes(id),
  FOREIGN KEY (obat_id) REFERENCES master_obat(id),
  UNIQUE KEY uq_stok (apotek_id, obat_id)
) ENGINE=InnoDB;

-- ============================================================================
-- [10] LABORATORIUM
-- ============================================================================

CREATE TABLE master_lab_tindakan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(20) NOT NULL UNIQUE,
  nama VARCHAR(200) NOT NULL,
  kelompok ENUM('darah','urine','hormon','kimia_darah','serologi','mikrobiologi','lainnya') NOT NULL,
  satuan VARCHAR(50) NULL,
  nilai_rujukan_laki VARCHAR(100) NULL,
  nilai_rujukan_perempuan VARCHAR(100) NULL,
  kode_loinc VARCHAR(20) NULL,
  harga DECIMAL(12,2) NOT NULL DEFAULT 0,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE master_lab_paket (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nama VARCHAR(200) NOT NULL,
  deskripsi TEXT NULL,
  harga DECIMAL(12,2) NOT NULL DEFAULT 0,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE lab_paket_item (
  paket_id BIGINT UNSIGNED NOT NULL,
  tindakan_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (paket_id, tindakan_id),
  FOREIGN KEY (paket_id) REFERENCES master_lab_paket(id) ON DELETE CASCADE,
  FOREIGN KEY (tindakan_id) REFERENCES master_lab_tindakan(id)
) ENGINE=InnoDB;

CREATE TABLE lab_permintaan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_permintaan VARCHAR(30) NOT NULL UNIQUE,
  rekam_medis_id BIGINT UNSIGNED NULL,
  konsultasi_id BIGINT UNSIGNED NULL,
  pasien_id BIGINT UNSIGNED NOT NULL,
  dokter_id BIGINT UNSIGNED NOT NULL,
  faskes_lab_id BIGINT UNSIGNED NULL,
  status ENUM('diminta','sampel_diangkat','diproses','hasil_terbit','dibatalkan')
         NOT NULL DEFAULT 'diminta',
  catatan_klinis TEXT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id),
  FOREIGN KEY (faskes_lab_id) REFERENCES faskes(id)
) ENGINE=InnoDB;

CREATE TABLE lab_permintaan_detail (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  lab_permintaan_id BIGINT UNSIGNED NOT NULL,
  tindakan_id BIGINT UNSIGNED NULL,
  paket_id BIGINT UNSIGNED NULL,
  prioritas ENUM('rutin','cepat','cito') NOT NULL DEFAULT 'rutin',
  FOREIGN KEY (lab_permintaan_id) REFERENCES lab_permintaan(id) ON DELETE CASCADE,
  FOREIGN KEY (tindakan_id) REFERENCES master_lab_tindakan(id),
  FOREIGN KEY (paket_id) REFERENCES master_lab_paket(id)
) ENGINE=InnoDB;

CREATE TABLE lab_hasil (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  lab_permintaan_id BIGINT UNSIGNED NOT NULL,
  tindakan_id BIGINT UNSIGNED NOT NULL,
  nilai VARCHAR(100) NOT NULL,
  satuan VARCHAR(50) NULL,
  nilai_rujukan VARCHAR(100) NULL,
  is_abnormal TINYINT(1) NOT NULL DEFAULT 0,
  keterangan TEXT NULL,
  diperiksa_oleh BIGINT UNSIGNED NULL,
  tanggal_hasil DATETIME NOT NULL,
  file_pdf_url VARCHAR(500) NULL,
  FOREIGN KEY (lab_permintaan_id) REFERENCES lab_permintaan(id) ON DELETE CASCADE,
  FOREIGN KEY (tindakan_id) REFERENCES master_lab_tindakan(id)
) ENGINE=InnoDB;

-- ============================================================================
-- [11] TAGIHAN, PEMBAYARAN & KLAIM BPJS
-- ============================================================================

CREATE TABLE master_metode_pembayaran (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(30) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  tipe ENUM('va_bank','e_wallet','qris','kartu_kredit','gerai_retail','cod','tunai','bpjs','asuransi') NOT NULL,
  penyedia VARCHAR(50) NULL,
  biaya_admin_flat DECIMAL(12,2) NOT NULL DEFAULT 0,
  biaya_admin_persen DECIMAL(5,2) NOT NULL DEFAULT 0,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE invoice (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_invoice VARCHAR(30) NOT NULL UNIQUE,
  pasien_id BIGINT UNSIGNED NOT NULL,
  referensi_tipe ENUM('booking','konsultasi','resep','pesanan_obat','lab_permintaan','home_care') NOT NULL,
  referensi_id BIGINT UNSIGNED NOT NULL COMMENT 'Polimorfik',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  diskon DECIMAL(14,2) NOT NULL DEFAULT 0,
  biaya_admin DECIMAL(14,2) NOT NULL DEFAULT 0,
  biaya_pengiriman DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL,
  status ENUM('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan',
              'refund_sebagian','refund_penuh') NOT NULL DEFAULT 'menunggu_pembayaran',
  jatuh_tempo DATETIME NULL,
  lunas_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  INDEX idx_invoice (pasien_id, status),
  INDEX idx_ref (referensi_tipe, referensi_id)
) ENGINE=InnoDB;

CREATE TABLE pembayaran (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  metode_id SMALLINT UNSIGNED NOT NULL,
  jumlah DECIMAL(14,2) NOT NULL,
  nomor_referensi VARCHAR(100) NULL COMMENT 'Transaction ID payment gateway',
  va_number VARCHAR(30) NULL,
  gateway ENUM('midtrans','xendit','doku','flip') NULL,
  status ENUM('pending','berhasil','gagal','kedaluwarsa','refund') NOT NULL DEFAULT 'pending',
  dibayar_at DATETIME NULL,
  webhook_payload JSON NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (invoice_id) REFERENCES invoice(id),
  FOREIGN KEY (metode_id) REFERENCES master_metode_pembayaran(id),
  INDEX idx_bayar_status (status, dibayar_at)
) ENGINE=InnoDB;

CREATE TABLE refund (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  pembayaran_id BIGINT UNSIGNED NOT NULL,
  jumlah DECIMAL(14,2) NOT NULL,
  alasan VARCHAR(255) NULL,
  status ENUM('diajukan','diproses','berhasil','ditolak') NOT NULL DEFAULT 'diajukan',
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pembayaran_id) REFERENCES pembayaran(id)
) ENGINE=InnoDB;

CREATE TABLE master_promo (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kode VARCHAR(30) NOT NULL UNIQUE,
  nama VARCHAR(150) NOT NULL,
  tipe_diskon ENUM('persen','nominal','gratis_ongkir') NOT NULL,
  nilai DECIMAL(12,2) NOT NULL,
  min_transaksi DECIMAL(12,2) NOT NULL DEFAULT 0,
  maks_diskon DECIMAL(12,2) NULL,
  kuota_total INT UNSIGNED NULL,
  kuota_per_user TINYINT UNSIGNED NOT NULL DEFAULT 1,
  mulai_at DATETIME NOT NULL,
  selesai_at DATETIME NOT NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE promo_redemption (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  promo_id BIGINT UNSIGNED NOT NULL,
  pasien_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  nilai_diskon DECIMAL(12,2) NOT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (promo_id) REFERENCES master_promo(id),
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (invoice_id) REFERENCES invoice(id)
) ENGINE=InnoDB;

CREATE TABLE klaim_bpjs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NULL,
  rekam_medis_id BIGINT UNSIGNED NULL,
  nomor_sep VARCHAR(30) NOT NULL UNIQUE COMMENT 'Surat Eligibilitas Peserta (V-Claim)',
  nomor_kartu CHAR(13) NOT NULL,
  tipe_layanan ENUM('rawat_jalan','rawat_inap') NOT NULL DEFAULT 'rawat_jalan',
  diagnosa_icd10 VARCHAR(8) NULL,
  tindakan_icd9cm VARCHAR(8) NULL,
  biaya_klaim DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','diajukan','terkirim','disetujui','ditolak','perlu_perbaikan')
         NOT NULL DEFAULT 'draft',
  tanggal_sep DATE NULL,
  tanggal_pulang DATE NULL,
  berkas_url VARCHAR(500) NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_klaim_status (status)
) ENGINE=InnoDB;

-- ============================================================================
-- [12] NOTIFIKASI, ULASAN, KONTEN & HOME CARE
-- ============================================================================

CREATE TABLE notifikasi (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  judul VARCHAR(200) NOT NULL,
  isi VARCHAR(500) NOT NULL,
  tipe ENUM('booking','pembayaran','resep','chat','lab','promo','sistem') NOT NULL,
  tautan VARCHAR(500) NULL,
  payload JSON NULL,
  dibaca_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notif (user_id, dibaca_at)
) ENGINE=InnoDB;

CREATE TABLE ulasan_dokter (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  konsultasi_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT '1 konsultasi = 1 ulasan',
  pasien_id BIGINT UNSIGNED NOT NULL,
  dokter_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
  rating_komunikasi TINYINT UNSIGNED NULL CHECK (rating_komunikasi BETWEEN 1 AND 5),
  rating_akurasi TINYINT UNSIGNED NULL CHECK (rating_akurasi BETWEEN 1 AND 5),
  isi TEXT NULL,
  is_anonim TINYINT(1) NOT NULL DEFAULT 1,
  balasan_dokter TEXT NULL,
  dibalas_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (dokter_id) REFERENCES dokter(id),
  INDEX idx_ulasan_dokter (dokter_id, rating)
) ENGINE=InnoDB;

CREATE TABLE artikel_kategori (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nama VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE artikel (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kategori_id SMALLINT UNSIGNED NOT NULL,
  penulis_user_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NULL COMMENT 'Reviewer medis (revisi medis)',
  judul VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  ringkasan VARCHAR(500) NULL,
  konten LONGTEXT NOT NULL,
  cover_url VARCHAR(500) NULL,
  status ENUM('draft','review','terbit','arsip') NOT NULL DEFAULT 'draft',
  jumlah_view INT UNSIGNED NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (kategori_id) REFERENCES artikel_kategori(id),
  FOREIGN KEY (penulis_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE home_care_pesanan (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  nomor_pesanan VARCHAR(30) NOT NULL UNIQUE,
  pasien_id BIGINT UNSIGNED NOT NULL,
  anggota_keluarga_id BIGINT UNSIGNED NULL,
  tipe_layanan ENUM('perawat','fisioterapi','dokter','bidan','vaksinasi_rumah') NOT NULL,
  tenaga_medis_id BIGINT UNSIGNED NULL,
  alamat_kunjungan TEXT NOT NULL,
  jadwal_kunjungan DATETIME NOT NULL,
  durasi_jam TINYINT UNSIGNED NOT NULL DEFAULT 1,
  keluhan TEXT NULL,
  status ENUM('menunggu_pembayaran','terjadwal','perjalanan','berlangsung','selesai','dibatalkan')
         NOT NULL DEFAULT 'menunggu_pembayaran',
  biaya DECIMAL(12,2) NOT NULL DEFAULT 0,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diubah_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (pasien_id) REFERENCES pasien(id),
  FOREIGN KEY (anggota_keluarga_id) REFERENCES pasien_anggota_keluarga(id),
  FOREIGN KEY (tenaga_medis_id) REFERENCES dokter(id)
) ENGINE=InnoDB;

-- ============================================================================
-- [13] AUDIT & KEPATUHAN (UU PDP No. 27/2022)
-- ============================================================================

CREATE TABLE audit_log (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  aksi ENUM('create','read','update','delete','login','logout','download','export') NOT NULL,
  tabel_target VARCHAR(64) NULL,
  record_id VARCHAR(64) NULL,
  data_lama JSON NULL,
  data_baru JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  endpoint VARCHAR(200) NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user (user_id, dibuat_at),
  INDEX idx_audit_tabel (tabel_target, record_id, dibuat_at)
) ENGINE=InnoDB;

CREATE TABLE persetujuan_pdp (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  jenis ENUM('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',
             'pemasaran','komunikasi_tindak_lanjut') NOT NULL,
  versi_dokumen VARCHAR(20) NOT NULL,
  disetujui TINYINT(1) NOT NULL,
  disetujui_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  -- uq_consent (user_id, jenis, versi_dokumen) DROPPED 2026-10-01 (F02): append-only ledger; see docs/schema-notes.md
) ENGINE=InnoDB;

CREATE TABLE akses_rekam_medis_log (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  rekam_medis_id BIGINT UNSIGNED NOT NULL,
  pengakses_user_id BIGINT UNSIGNED NOT NULL,
  tujuan_akses ENUM('perawatan','klaim','audit','pasien_sendiri','kepentingan_hukum') NOT NULL,
  dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rekam_medis_id) REFERENCES rekam_medis(id) ON DELETE CASCADE,
  FOREIGN KEY (pengakses_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================================
-- [14] FOREIGN KEY TAMBAHAN (hindari ketergantungan silang saat CREATE)
-- ============================================================================

ALTER TABLE pasien_tanda_vital
  ADD CONSTRAINT fk_vital_rm FOREIGN KEY (rekam_medis_id)
  REFERENCES rekam_medis(id) ON DELETE SET NULL;

-- ============================================================================
-- [15] VIEW
-- ============================================================================

-- Katalog dokter untuk halaman pencarian
CREATE OR REPLACE VIEW v_dokter_katalog AS
SELECT
  d.id AS dokter_id,
  u.nama_lengkap,
  d.tipe,
  d.biaya_konsultasi_online,
  d.rating_rata_rata,
  d.jumlah_konsultasi,
  GROUP_CONCAT(s.nama SEPARATOR ', ') AS spesialisasi
FROM dokter d
JOIN users u ON u.id = d.user_id
LEFT JOIN dokter_spesialisasi ds ON ds.dokter_id = d.id
LEFT JOIN master_spesialisasi s ON s.id = ds.spesialisasi_id
WHERE d.status_verifikasi = 'terverifikasi'
  AND d.status_aktif = 1
  AND d.tersedia_telemedisin = 1
GROUP BY d.id, u.nama_lengkap, d.tipe, d.biaya_konsultasi_online,
         d.rating_rata_rata, d.jumlah_konsultasi;

-- Rekap pendapatan bulanan
CREATE OR REPLACE VIEW v_pendapatan_bulanan AS
SELECT DATE_FORMAT(p.dibayar_at, '%Y-%m') AS bulan,
       COUNT(*) AS jumlah_transaksi,
       SUM(p.jumlah) AS total_pendapatan
FROM pembayaran p
WHERE p.status = 'berhasil'
GROUP BY bulan;

-- ============================================================================
-- [16] SEED DATA — MASTER
-- ============================================================================

-- 16.1 Provinsi Indonesia (38 provinsi, kode Kemendagri)
INSERT INTO master_provinsi (kode, nama) VALUES
('11','Aceh'),('12','Sumatera Utara'),('13','Sumatera Barat'),('14','Riau'),
('15','Jambi'),('16','Sumatera Selatan'),('17','Bengkulu'),('18','Lampung'),
('19','Kepulauan Bangka Belitung'),('21','Kepulauan Riau'),('31','DKI Jakarta'),
('32','Jawa Barat'),('33','Jawa Tengah'),('34','DI Yogyakarta'),('35','Jawa Timur'),
('36','Banten'),('51','Bali'),('52','Nusa Tenggara Barat'),('53','Nusa Tenggara Timur'),
('61','Kalimantan Barat'),('62','Kalimantan Tengah'),('63','Kalimantan Selatan'),
('64','Kalimantan Timur'),('65','Kalimantan Utara'),('71','Sulawesi Utara'),
('72','Sulawesi Tengah'),('73','Sulawesi Selatan'),('74','Sulawesi Tenggara'),
('75','Gorontalo'),('76','Sulawesi Barat'),('81','Maluku'),('82','Maluku Utara'),
('91','Papua'),('92','Papua Barat'),('93','Papua Tengah'),('94','Papua Pegunungan'),
('95','Papua Selatan'),('96','Papua Barat Daya');

-- 16.2 Agama, golongan darah, pendidikan, status pernikahan, hubungan keluarga
INSERT INTO master_agama VALUES
(1,'Islam'),(2,'Kristen Protestan'),(3,'Katolik'),(4,'Hindu'),
(5,'Buddha'),(6,'Khonghucu'),(7,'Lainnya');

INSERT INTO master_golongan_darah VALUES
(1,'A'),(2,'B'),(3,'AB'),(4,'O');

INSERT INTO master_pendidikan VALUES
(1,'Tidak Sekolah'),(2,'SD/Sederajat'),(3,'SMP/Sederajat'),(4,'SMA/SMK/Sederajat'),
(5,'Diploma (D1-D3)'),(6,'Sarjana (S1)'),(7,'Magister (S2)'),(8,'Doktor (S3)');

INSERT INTO master_status_pernikahan VALUES
(1,'belum_menikah'),(2,'menikah'),(3,'cerai_hidup'),(4,'cerai_mati');

INSERT INTO master_hubungan_keluarga VALUES
(1,'Pasangan'),(2,'Anak Kandung'),(3,'Orang Tua/Kandung'),(4,'Saudara Kandung'),
(5,'Paman/Tante'),(6,'Kakek/Nenek'),(7,'Lainnya');

-- 16.3 Spesialisasi dokter
INSERT INTO master_spesialisasi (kode, nama, tipe) VALUES
('UMUM','Dokter Umum','dokter_umum'),
('SP.PD','Spesialis Penyakit Dalam','spesialis'),
('SP.A','Spesialis Anak','spesialis'),
('SP.OG','Spesialis Obstetri & Ginekologi','spesialis'),
('SP.M','Spesialis Mata','spesialis'),
('SP.THT','Spesialis Telinga Hidung Tenggorokan','spesialis'),
('SP.KJ','Spesialis Kedokteran Jiwa','spesialis'),
('SP.B','Spesialis Bedah','spesialis'),
('SP.BP','Spesialis Bedah Plastik','spesialis'),
('SP.JP','Spesialis Jantung & Pembuluh Darah','spesialis'),
('SP.P','Spesialis Paru','spesialis'),
('SP.KK','Spesialis Kulit & Kelamin','spesialis'),
('SP.S','Spesialis Orthopaedi & Traumatologi','spesialis'),
('SP.N','Spesialis Saraf','spesialis'),
('SP.U','Spesialis Urologi','spesialis'),
('GIGI','Dokter Gigi','spesialis');

-- 16.4 Penjamin bayar
INSERT INTO master_penjamin (nama, tipe) VALUES
('BPJS Kesehatan','bpjs'),
('Tunai / Mandiri','tunai'),
('Prudential','asuransi_swasta'),
('AXA Mandiri','asuransi_swasta'),
('Allianz','asuransi_swasta'),
('Astra Life','asuransi_swasta');

-- 16.5 Metode pembayaran Indonesia
INSERT INTO master_metode_pembayaran (kode, nama, tipe, penyedia) VALUES
('VA_BCA','Virtual Account BCA','va_bank','BCA'),
('VA_MANDIRI','Virtual Account Mandiri','va_bank','Mandiri'),
('VA_BNI','Virtual Account BNI','va_bank','BNI'),
('VA_BRI','Virtual Account BRI','va_bank','BRI'),
('VA_PERMATA','Virtual Account Permata','va_bank','Permata'),
('GOPAY','GoPay','e_wallet','Gojek'),
('OVO','OVO','e_wallet','OVO'),
('DANA','DANA','e_wallet','DANA'),
('SHOPEEPAY','ShopeePay','e_wallet','Shopee'),
('LINKAJA','LinkAja','e_wallet','LinkAja'),
('QRIS','QRIS','qris','GPN'),
('COD','Bayar di Tempat','cod','Internal'),
('TUNAI','Tunai','tunai','Internal'),
('BPJS','BPJS Kesehatan','bpjs','BPJS Kesehatan');

-- 16.6 Sampel ICD-10 (lengkapnya import dari file Kemenkes resmi)
INSERT INTO master_icd10 (kode, deskripsi) VALUES
('A09','Diare dan gastroenteritis yang diduga akibat infeksi'),
('J06','Infeksi akut pada saluran pernapasan atas, multifokus'),
('J45','Asma'),
('I10','Hipertensi esensial (primer)'),
('E11','Diabetes melitus tipe 2'),
('K29','Gastritis dan duodenitis'),
('K02','Karies gigi'),
('M54','Dorsopati lain'),
('B34','Infeksi virus dengan lesi lain'),
('U07.1','COVID-19, virus teridentifikasi'),
('U07.2','COVID-19, virus tidak teridentifikasi'),
('K35','Apendisitis akut'),
('N39.0','Infeksi saluran kemih tanpa lokasi yang ditentukan'),
('K76.9','Penyakit hati, tidak ditentukan'),
('O80','Persalinan tunggal spontan');

-- 16.7 Sampel ICD-9-CM (lengkapnya import dari file Kemenkes resmi)
INSERT INTO master_icd9cm (kode, deskripsi) VALUES
('88.72','Ultrasonografi diagnostik jantung'),
('87.44','Arteriografi koroner dengan dua kateter'),
('93.94','Rehabilitasi jantung'),
('99.04','Transfusi darah sel darah merah'),
('96.04','Pemasangan pipa endotrakeal'),
('31.9','Lainnya intervensi pada telinga luar/institusi');

-- 16.8 Sampel katalog obat & lab
INSERT INTO master_obat (kode_obat, nama_generik, nama_brand, bentuk_sediaan, kekuatan,
                         satuan, pabrikan, kelas_terapi, kelas_obat, requires_resep,
                         aturan_pakai_umum, harga_jual) VALUES
('OBT-0001','Paracetamol','Panadol','tablet','500 mg','tablet','GSK','Analgetik-Antipiretik','bebas_terbatas',0,'3 x 1 tablet sesudah makan',4500.00),
('OBT-0002','Amoxicillin','Amoxsan','kapsul','500 mg','kapsul','Sanbe','Antibiotik','keras',1,'3 x 1 kapsul sesudah makan',7500.00),
('OBT-0003','Cetirizine','Zenriz','tablet','10 mg','tablet','Novell','Antihistamin','keras',1,'1 x 1 tablet malam hari',5200.00),
('OBT-0004','Omeprazole','Losec','kapsul','20 mg','kapsul','AstraZeneca','PPI','keras',1,'1 x 1 kapsul sebelum makan',9800.00),
('OBT-0005','Metformin','Glucophage','tablet','500 mg','tablet','Merck','Antidiabetik','keras',1,'2 x 1 tablet sesudah makan',6300.00),
('OBT-0006','Amlodipine','Norvasc','tablet','10 mg','tablet','Pfizer','Antihipertensi','keras',1,'1 x 1 tablet pagi',8700.00),
('OBT-0007','ORS','Oralit','sirup','sachet','sachet','Kimia Farma','Rehidrasi','bebas',0,'1 sachet dilarutkan 200ml air, diminum bertahap',1500.00);

INSERT INTO master_lab_tindakan (kode, nama, kelompok, satuan, nilai_rujukan_laki,
                                nilai_rujukan_perempuan, harga) VALUES
('LAB-001','Hemoglobin (Hb)','darah','g/dL','13.0-17.0','12.0-15.5',35000.00),
('LAB-002','Laju Endap Darah (LED)','darah','mm/jam','0-10','0-20',25000.00),
('LAB-003','Glukosa Darah Puasa','kimia_darah','mg/dL','70-100','70-100',30000.00),
('LAB-004','Kolesterol Total','kimia_darah','mg/dL','<200','<200',45000.00),
('LAB-005','Fungsi Hati (SGOT)','kimia_darah','U/L','<37','<31',40000.00),
('LAB-006','Fungsi Hati (SGPT)','kimia_darah','U/L','<40','<32',40000.00),
('LAB-007','Ureum','kimia_darah','mg/dL','17-43','17-43',35000.00),
('LAB-008','Kreatinin','kimia_darah','mg/dL','0.7-1.3','0.6-1.1',35000.00),
('LAB-009','Urinalisa Lengkap','urine','-',NULL,NULL,50000.00),
('LAB-010','Widal Test','serologi','titer','negatif','negatif',60000.00);

INSERT INTO master_lab_paket (nama, deskripsi, harga) VALUES
('Medical Check Up Dasar','Hb, LED, Golongan Darah, Urinalisa',120000.00),
('Cek Gula & Kolesterol','Glukosa Puasa, Kolesterol Total, Trigliserida, HDL, LDL',180000.00),
('Fungsi Hati Lengkap','SGOT, SGPT, Bilirubin Total & Direk, Albumin',250000.00);

-- 16.9 Kategori artikel kesehatan
INSERT INTO artikel_kategori (nama, slug) VALUES
('Kesehatan Umum','kesehatan-umum'),
('Kesehatan Ibu & Anak','kesehatan-ibu-anak'),
('Penyakit Dalam','penyakit-dalam'),
('Kesehatan Mental','kesehatan-mental'),
('Gizi & Diet','gizi-diet'),
('Covid-19','covid-19');

-- ============================================================================
-- SELESAI
-- ============================================================================
SELECT 'Database telemedisin_db berhasil dibuat!' AS status;