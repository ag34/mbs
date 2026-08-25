-- ====================================================================
-- Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS)
-- MySQL Veritabanı Şeması (cPanel phpMyAdmin 100% Uyumlu)
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 0. Temiz Kurulum İçin Önceki Tabloları Kaldır
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `defin_kayitlari`;
DROP TABLE IF EXISTS `mezarliklar`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `users`;

-- 1. Mezarlıklar Tablosu
CREATE TABLE `mezarliklar` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ad` VARCHAR(150) NOT NULL,
    `kod` VARCHAR(50) NOT NULL UNIQUE,
    `aciklama` TEXT NULL,
    `tarihce` TEXT NULL,
    `kapasite` INT NULL,
    `alan_m2` INT NULL,
    `durum` ENUM('aktif', 'pasif') DEFAULT 'aktif',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varsayılan Mezarlıklar
INSERT INTO `mezarliklar` (`id`, `ad`, `kod`) VALUES 
(1, 'Yeni Mezarlık', 'MEZ-01'),
(2, 'Sultaniye Mezarlığı', 'MEZ-02'),
(3, '500 Evler Mezarlığı', 'MEZ-03'),
(4, 'Köyiçi Mezarlık', 'MEZ-04')
ON DUPLICATE KEY UPDATE `ad` = VALUES(`ad`);

-- 2. Kullanıcılar Tablosu (Temiz Kurulum İçin Önce Sıfırlanır)
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `ad_soyad` VARCHAR(100) NOT NULL,
    `unvan` VARCHAR(100) DEFAULT 'Belediye Personeli',
    `role` VARCHAR(20) DEFAULT 'staff',
    `durum` VARCHAR(20) DEFAULT 'aktif',
    `eposta` VARCHAR(150) NULL,
    `telefon` VARCHAR(20) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `otp_code` VARCHAR(10) NULL,
    `otp_expires_at` DATETIME NULL,
    `can_delete` INT DEFAULT 0,
    `can_edit` INT DEFAULT 1,
    `can_add` INT DEFAULT 1,
    `can_export` INT DEFAULT 1,
    `auth_token` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varsayılan Kullanıcılar
INSERT INTO `users` (`username`, `password`, `ad_soyad`, `unvan`, `role`, `eposta`, `telefon`) VALUES 
('admin', '123456', 'Ahmet Yılmaz', 'Bilgi İşlem Müdürü', 'superadmin', 'ahmet.yilmaz@ciftlikkoy.bel.tr', '0532 123 4567');

-- 3. Defin Kayıtları Tablosu
CREATE TABLE `defin_kayitlari` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tc_no` VARCHAR(11) NULL,
    `ad` VARCHAR(100) NOT NULL,
    `soyad` VARCHAR(100) NOT NULL,
    `baba_adi` VARCHAR(100) NULL,
    `anne_adi` VARCHAR(100) NULL,
    `dogum_tarihi` DATE NULL,
    `dogum_yeri` VARCHAR(100) DEFAULT 'Çiftlikköy',
    `olum_tarihi` DATE NULL,
    `belge_no` VARCHAR(50) NULL,
    `defin_tarihi` DATE NOT NULL,
    `defin_bolum` VARCHAR(50) DEFAULT 'SIRADAN',
    `olum_nedeni` VARCHAR(150) DEFAULT 'Doğal Ölüm',
    `mezarlik_id` INT NOT NULL,
    `ada_no_text` VARCHAR(50) NULL,
    `parsel_no_text` VARCHAR(50) NULL,
    `lat` DECIMAL(10, 8) NULL,
    `lng` DECIMAL(11, 8) NULL,
    `yakin_ad_soyad` VARCHAR(150) NULL,
    `yakin_akrabalik` VARCHAR(50) DEFAULT 'YAKINI',
    `yakin_telefon` VARCHAR(20) NULL,
    `yakin_adres` VARCHAR(255) NULL,
    `aciklama` TEXT NULL,
    `onay_durumu` ENUM('bekliyor', 'onaylandi', 'reddedildi') DEFAULT 'onaylandi',
    `gorsel_url` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`mezarlik_id`) REFERENCES `mezarliklar`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Audit Logs Tablosu
CREATE TABLE `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `islem_tipi` VARCHAR(50) NOT NULL,
    `modul` VARCHAR(50) NOT NULL,
    `detay` TEXT NOT NULL,
    `kullanici` VARCHAR(100) DEFAULT 'Sistem',
    `ip_adresi` VARCHAR(50) DEFAULT '127.0.0.1',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Sistem Ayarları Tablosu
CREATE TABLE `system_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('logo_url', 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg'),
('birim_sorumlusu_ad', 'Ali KAPLAN'),
('birim_sorumlusu_unvan', 'Park ve Bahçeler Müd. V.'),
('mezarlik_sorumlusu_ad', 'Bedri DEMİREL'),
('mezarlik_sorumlusu_unvan', 'Mezarlık Birim Sorumlusu'),
('belediye_adi', 'T.C. ÇİFTLİKKÖY BELEDİYESİ BAŞKANLIĞI'),
('mudurluk_adi', 'PARK VE BAHÇELER MÜDÜRLÜĞÜ')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

SET FOREIGN_KEY_CHECKS = 1;
