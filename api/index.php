<?php
/**
 * Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS)
 * RESTful API Endpoint Router (Kullanıcı Rolü Otomatik Tanılama & Dinamik Şablon Ayarları)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
set_exception_handler(function ($e) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Sunucu Hatası: ' . $e->getMessage()
    ], 500);
});

$pdo = getDbConnection();

// Güvenlik ve Uyumluluk: Users tablosuna eksik token sütunlarını ekle
try { $pdo->exec("ALTER TABLE users ADD COLUMN auth_token VARCHAR(255) NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN otp_expires_at DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN can_approve INT DEFAULT 0"); } catch (Exception $e) {}

// Mezarlıklar tablosuna eksik veri alanlarını ekle
try { $pdo->exec("ALTER TABLE mezarliklar ADD COLUMN aciklama TEXT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE mezarliklar ADD COLUMN tarihce TEXT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE mezarliklar ADD COLUMN kapasite INT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE mezarliklar ADD COLUMN alan_m2 INT NULL"); } catch (Exception $e) {}

// SMTP varsayılan ayarları ekle
try {
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
        ('smtp_host', 'mail.example.com'),
        ('smtp_port', '587'),
        ('smtp_user', 'no-reply@example.com'),
        ('smtp_pass', 'password'),
        ('smtp_secure', 'tls'),
        ('2fa_method', 'sms')
    ");
} catch (Exception $e) {}

function checkAuth($pdo) {
    $token = $_SERVER['HTTP_X_MBS_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? null;
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = trim($matches[1]);
        }
    }
    if (!$token && function_exists('getallheaders')) {
        $allHeaders = getallheaders();
        $authHeader = $allHeaders['Authorization'] ?? $allHeaders['authorization'] ?? null;
        if ($authHeader && preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        }
    }
    if (!$token) {
        sendJsonResponse(['status' => 'error', 'message' => 'Oturum tokeni eksik! Lütfen tekrar giriş yapın.'], 401);
    }
    
    $stmt = $pdo->prepare("SELECT id, username, role, ad_soyad, can_add, can_edit, can_delete, can_export FROM users WHERE auth_token = :token AND durum = 'aktif' LIMIT 1");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['status' => 'error', 'message' => 'Yetkisiz erişim veya oturum süreniz dolmuş.'], 401);
    }
    
    return $user;
}

function checkAdmin($pdo) {
    global $currentUser;
    if (!$currentUser || $currentUser['role'] !== 'superadmin') {
        sendJsonResponse(['status' => 'error', 'message' => 'Bu işlem için yetkiniz bulunmamaktadır! (Süper Admin Gerekli)'], 403);
    }
}

function requirePermission($permission) {
    global $currentUser;
    if (!$currentUser) {
        sendJsonResponse(['status' => 'error', 'message' => 'Yetkisiz erişim. Oturum bulunamadı.'], 401);
    }
    if ($currentUser['role'] === 'superadmin') return true;
    if (intval($currentUser[$permission] ?? 0) !== 1) {
        sendJsonResponse(['status' => 'error', 'message' => 'Bu işlem için yetkiniz bulunmamaktadır!'], 403);
    }
    return true;
}

function checkMethod($method) {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        sendJsonResponse(['status' => 'error', 'message' => "Sadece {$method} isteklerine izin verilir."], 405);
    }
}

function checkIPRestriction($pdo, $action) {
    // 1. Kiosk Özel IP Kısıtlaması
    if ($action === 'kiosk_settings' || $action === 'kiosk_save_location') {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'kiosk_ip_restriction_enabled'");
            $kioskEnabled = $stmt->fetchColumn();
            if ($kioskEnabled == '1') {
                $stmtIps = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'kiosk_allowed_ips'");
                $kioskIpsStr = $stmtIps->fetchColumn();
                if (!empty(trim($kioskIpsStr))) {
                    $kioskIps = preg_split('/[\s,]+/', trim($kioskIpsStr), -1, PREG_SPLIT_NO_EMPTY);
                    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
                    if (!in_array($clientIp, $kioskIps)) {
                        sendJsonResponse(['status' => 'error', 'message' => 'Kiosk Yetkisiz Erişim (IP Kısıtlaması Aktif). Sizin IP: ' . $clientIp], 403);
                    }
                }
            }
        } catch(Exception $e) {}
        return; // Kiosk endpointleri için genel admin IP kontrolüne girme
    }

    // 2. Genel (Admin) IP Kısıtlaması
    if (in_array($action, ['vatandas_sorgu', 'qr_code', 'help', 'mezarliklar'])) {
        return;
    }

    try {
        $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ip_restriction_enabled'");
        $isEnabled = $stmt->fetchColumn();

        if ($isEnabled == '1') {
            $stmtIps = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'allowed_ips'");
            $allowedIpsStr = $stmtIps->fetchColumn();
            
            if (!empty(trim($allowedIpsStr))) {
                // Hem virgül hem de satır sonunu (alt alta yazmayı) desteklemek için preg_split
                $allowedIps = preg_split('/[\s,]+/', trim($allowedIpsStr), -1, PREG_SPLIT_NO_EMPTY);
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

                if (!in_array($clientIp, $allowedIps)) {
                    sendJsonResponse(['status' => 'error', 'message' => 'Yönetici Yetkisiz Erişim (IP Kısıtlaması Aktif). Sizin IP: ' . $clientIp], 403);
                }
            }
        }
    } catch(Exception $e) {}
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'help';
checkIPRestriction($pdo, $action);

// Kimlik Doğrulama Bariyeri (Zorunlu)
$currentUser = null;
if (!in_array($action, ['login', 'vatandas_sorgu', 'qr_code', 'help', 'mezarliklar', 'kiosk_settings', 'kiosk_save_location', 'download_excel_template'])) {
    $currentUser = checkAuth($pdo);
}

switch ($action) {
    case 'login':
        handleLogin($pdo);
        break;

    case 'logout':
        handleLogout($pdo);
        break;

    case 'export_pdf':
        handleExportPDF($pdo);
        break;

    case 'export_tekli_pdf':
        handleExportTekliPDF($pdo);
        break;

    case 'export_docx':
        handleExportDocx($pdo);
        break;

    case 'print_qr_pdf':
        handlePrintQrPdf($pdo);
        break;

    case 'export_excel':
        handleExportExcel($pdo);
        break;

    case 'kullanicilar':
        handleKullanicilar($pdo);
        break;

    case 'kullanici_ekle':
        handleKullaniciEkle($pdo);
        break;

    case 'kullanici_duzenle':
        handleKullaniciDuzenle($pdo);
        break;

    case 'kullanici_yetki_guncelle':
        handleKullaniciYetkiGuncelle($pdo);
        break;

    case 'kullanici_sil':
        handleKullaniciSil($pdo);
        break;

    case 'mezarlik_ekle':
        checkMethod('POST');
        handleMezarlikEkle($pdo);
        break;

    case 'mezarlik_sil':
        checkMethod('POST');
        handleMezarlikSil($pdo);
        break;

    case 'qr_code':
        handleQrCode($pdo);
        break;

    case 'settings':
        handleSettings($pdo);
        break;

    case 'settings_save':
        handleSettingsSave($pdo);
        break;

    case 'vatandas_sorgu':
        handleVatandasSorgu($pdo);
        break;

    case 'mezarliklar':
        handleMezarliklar($pdo);
        break;

    case 'harita_verisi':
        handleHaritaVerisi($pdo);
        break;

    case 'defin_ekle':
        handleDefinEkle($pdo);
        break;

    case 'toplu_kayit_ekle':
        handleTopluKayıtEkle($pdo);
        break;

    case 'defin_guncelle':
        handleDefinGuncelle($pdo);
        break;

    case 'defin_sil':
        handleDefinSil($pdo);
        break;

    case 'onay_guncelle':
        handleOnayGuncelle($pdo);
        break;

    case 'audit_logs':
        handleAuditLogs($pdo);
        break;

    case 'upload_gorsel':
        handleUploadGorsel($pdo);
        break;

    case 'download_excel_template':
        handleDownloadExcelTemplate();
        break;

    case 'upload_logo':
        handleUploadGeneric($pdo, 'logo');
        break;

    case 'kiosk_upload_ad':
        handleUploadGeneric($pdo, 'kiosk');
        break;
        
    case 'kiosk_settings':
        handleKioskSettings($pdo);
        break;

    case 'kiosk_save_location':
        handleKioskSaveLocation($pdo);
        break;

    case 'export_pdf':
        handleExportPdf($pdo);
        break;

    case 'raporlar':
        handleRaporlar($pdo);
        break;

    case 'stats':
        handleStats($pdo);
        break;

    default:
        sendJsonResponse([
            'system' => 'Çiftlikköy Belediyesi MBS RESTful API v7.5',
            'status' => 'online',
            'endpoints' => [
                'POST ?action=login' => 'Kullanıcı girişi',
                'GET ?action=export_tekli_pdf&id={id}' => 'Tekli resmi defin belgesi',
                'GET ?action=settings' => 'Dinamik sistem ayarları ve yetkili bilgileri',
                'POST ?action=settings_save' => 'Dinamik sistem ayarlarını kaydetme',
                'GET ?action=audit_logs' => 'Sistem denetim ve erişim logları'
            ]
        ]);
}

/**
 * DİNAMİK SİSTEM AYARLARI ÇEKME VE KAYDETME
 */
function getSystemSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_merge([
            'logo_url' => 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg',
            'birim_sorumlusu_ad' => 'Ali KAPLAN',
            'birim_sorumlusu_unvan' => 'Park ve Bahçeler Müd. V.',
            'mezarlik_sorumlusu_ad' => 'Bedri DEMİREL',
            'mezarlik_sorumlusu_unvan' => 'Mezarlık Birim Sorumlusu',
            'belediye_adi' => 'T.C. ÇİFTLİKKÖY BELEDİYESİ BAŞKANLIĞI',
            'mudurluk_adi' => 'PARK VE BAHÇELER MÜDÜRLÜĞÜ'
        ], $rows);
    } catch (Exception $e) {
        return [
            'logo_url' => 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg',
            'birim_sorumlusu_ad' => 'Ali KAPLAN',
            'birim_sorumlusu_unvan' => 'Park ve Bahçeler Müd. V.',
            'mezarlik_sorumlusu_ad' => 'Bedri DEMİREL',
            'mezarlik_sorumlusu_unvan' => 'Mezarlık Birim Sorumlusu',
            'belediye_adi' => 'T.C. ÇİFTLİKKÖY BELEDİYESİ BAŞKANLIĞI',
            'mudurluk_adi' => 'PARK VE BAHÇELER MÜDÜRLÜĞÜ'
        ];
    }
}

function handleSettings($pdo) {
    sendJsonResponse([
        'status' => 'success',
        'data' => getSystemSettings($pdo)
    ]);
}

function handleKioskSettings($pdo) {
    $settings = getSystemSettings($pdo);
    sendJsonResponse([
        'status' => 'success',
        'data' => [
            'belediye_adi' => $settings['belediye_adi'] ?? '',
            'logo_url' => $settings['logo_url'] ?? '',
            'kiosk_ads' => $settings['kiosk_ads'] ?? '[]',
            'kiosk_lat' => $settings['kiosk_lat'] ?? null,
            'kiosk_lng' => $settings['kiosk_lng'] ?? null
        ]
    ]);
}

function handleKioskSaveLocation($pdo) {
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;
        
        if (!isset($input['lat']) || !isset($input['lng'])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Eksik parametre'], 400);
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2");
            $stmt->execute([':key' => 'kiosk_lat', ':val' => $input['lat'], ':val2' => $input['lat']]);
            $stmt->execute([':key' => 'kiosk_lng', ':val' => $input['lng'], ':val2' => $input['lng']]);
        } else {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (:key, :val)");
            $stmt->execute([':key' => 'kiosk_lat', ':val' => $input['lat']]);
            $stmt->execute([':key' => 'kiosk_lng', ':val' => $input['lng']]);
        }

        sendJsonResponse(['status' => 'success', 'message' => 'Konum kaydedildi.']);
    } catch(Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Sunucu Hatası: ' . $e->getMessage()], 500);
    }
}

function handleSettingsSave($pdo) {
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (:key, :val)
                                   ON DUPLICATE KEY UPDATE setting_value = :val2");
        } else {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (:key, :val)");
        }

        $fields = [
            'logo_url', 'birim_sorumlusu_ad', 'birim_sorumlusu_unvan',
            'mezarlik_sorumlusu_ad', 'mezarlik_sorumlusu_unvan',
            'belediye_adi', 'mudurluk_adi', 'ip_restriction_enabled', 'allowed_ips',
            'kiosk_ip_restriction_enabled', 'kiosk_allowed_ips',
            'vatansms_api_id', 'vatansms_api_key', 'vatansms_sender', 'kiosk_ads',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', '2fa_method'
        ];

        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $val = trim($input[$f]);
                if ($driver === 'mysql') {
                    $stmt->execute([':key' => $f, ':val' => $val, ':val2' => $val]);
                } else {
                    $stmt->execute([':key' => $f, ':val' => $val]);
                }
            }
        }

        logAuditAction($pdo, 'AYARLAR_GUNCELLENDI', 'YONETIM_PANELI', "Sistem genel ayarları, logo ve yetkili bilgileri güncellendi");

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Sistem ayarları ve yetkili bilgileri başarıyla kaydedildi.',
            'data' => getSystemSettings($pdo)
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Ayarlar kaydedilirken hata oluştu: ' . $e->getMessage()], 500);
    }
}

/**
 * TARİH FORMATLAMA YARDIMCI FONKSİYONLARI
 */
function formatDisplayDate($dateStr) {
    if (empty($dateStr)) return '';
    $timestamp = strtotime($dateStr);
    if ($timestamp === false) return $dateStr;
    return date('d/m/Y', $timestamp);
}

function formatDbDate($dateStr) {
    if (empty($dateStr)) return null;
    $dateStr = trim($dateStr);
    if (preg_match('/^(\d{2})[\/\.-](\d{2})[\/\.-](\d{4})$/', $dateStr, $matches)) {
        return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
    }
    return $dateStr;
}

function logAuditAction($pdo, $islemTipi, $modul, $detay) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '127.0.0.1');
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                islem_tipi TEXT NOT NULL,
                modul TEXT NOT NULL,
                detay TEXT NOT NULL,
                kullanici TEXT DEFAULT 'Sistem',
                ip_adresi TEXT DEFAULT '127.0.0.1',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            try { $pdo->exec("ALTER TABLE audit_logs ADD COLUMN kullanici TEXT DEFAULT 'Sistem'"); } catch(Exception $e) {}
            try { $pdo->exec("ALTER TABLE audit_logs ADD COLUMN ip_adresi TEXT DEFAULT '127.0.0.1'"); } catch(Exception $e) {}
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                islem_tipi VARCHAR(50) NOT NULL,
                modul VARCHAR(50) NOT NULL,
                detay TEXT NOT NULL,
                kullanici VARCHAR(150) DEFAULT 'Sistem',
                ip_adresi VARCHAR(50) DEFAULT '127.0.0.1',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            try { $pdo->exec("ALTER TABLE audit_logs ADD COLUMN kullanici VARCHAR(150) DEFAULT 'Sistem'"); } catch(Exception $e) {}
            try { $pdo->exec("ALTER TABLE audit_logs ADD COLUMN ip_adresi VARCHAR(50) DEFAULT '127.0.0.1'"); } catch(Exception $e) {}
        }

        // HTTP Headers veya Request Payload üzerinden oturum açan yetkiliyi bul
        $headerUser = $_SERVER['HTTP_X_MBS_USER'] ?? null;
        $headerName = $_SERVER['HTTP_X_MBS_NAME'] ?? null;
        $headerRole = $_SERVER['HTTP_X_MBS_ROLE'] ?? null;

        $userStr = 'Sistem';
        if ($headerName && $headerUser) {
            $userStr = urldecode($headerName) . ' (@' . urldecode($headerUser) . ')';
        } else if ($headerUser) {
            $userStr = '@' . urldecode($headerUser);
        } else {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true);
            if (!empty($input['logged_in_user'])) {
                $userStr = urldecode($input['logged_in_user']);
            } else if (!empty($input['username'])) {
                $userStr = '@' . urldecode($input['username']);
            }
        }

        if ($headerRole) {
            $roleName = urldecode($headerRole);
            $roleMap = ['superadmin' => 'Süper Admin', 'staff' => 'Personel', 'viewer' => 'Gözlemci'];
            $roleLabel = $roleMap[$roleName] ?? $roleName;
            $userStr .= " [{$roleLabel}]";
        }

        $stmt = $pdo->prepare("INSERT INTO audit_logs (islem_tipi, modul, detay, kullanici, ip_adresi) VALUES (:tip, :modul, :detay, :user, :ip)");
        $stmt->execute([
            ':tip' => $islemTipi,
            ':modul' => $modul,
            ':detay' => $detay,
            ':user' => $userStr,
            ':ip' => $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Failure: " . $e->getMessage());
    }
}

/**
 * 🔑 KULLANICI OTOMATİK ROL GİRİŞİ (2FA E-Posta / Telefon Seçimli)
 */
function maskEmail($email) {
    if (empty($email)) return '-';
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1] ?? '';
    $len = strlen($name);
    if ($len <= 2) {
        return $name . '@' . $domain;
    }
    return substr($name, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($name, -2) . '@' . $domain;
}

function maskPhone($phone) {
    if (empty($phone)) return '-';
    $clean = preg_replace('/\D/', '', $phone);
    if (strlen($clean) >= 10) {
        return substr($clean, 0, 4) . ' *** ** ' . substr($clean, -2);
    }
    return $phone;
}

function handleLogin($pdo) {
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $otp      = trim($input['otp'] ?? '');
        $action   = trim($input['action_step'] ?? ''); // 'request_2fa', 'send_2fa' or 'verify_2fa'

        if (empty($username) || empty($password)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Kullanıcı adı ve şifre gereklidir.'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, username, password, ad_soyad, unvan, role, durum, eposta, telefon, can_delete, can_edit, can_add, can_export FROM users WHERE username = :user LIMIT 1");
        $stmt->execute([':user' => $username]);
        $user = $stmt->fetch();

        $hashedInputPassword = hash('sha512', $password);

        if (!$user || $user['password'] !== $hashedInputPassword) {
            logAuditAction($pdo, 'LOGIN_FAILED', 'YONETIM_PANELI', "Başarısız giriş denemesi - Kullanıcı Adı: $username");
            sendJsonResponse(['status' => 'error', 'message' => 'Hatalı kullanıcı adı veya şifre!'], 401);
        }

        if ($user['durum'] !== 'aktif') {
            sendJsonResponse(['status' => 'error', 'message' => 'Hesabınız pasife alınmıştır.'], 403);
        }

        // Adım 1: Kullanıcı kanalları seçimi (request_2fa)
        if ($action === 'request_2fa') {
            $settings = getSystemSettings($pdo);
            $available = [];

            if (!empty($user['telefon']) && !empty($settings['vatansms_api_id']) && !empty($settings['vatansms_api_key'])) {
                $available[] = 'sms';
            }
            if (!empty($user['eposta']) && !empty($settings['smtp_host'])) {
                $available[] = 'email';
            }

            if (empty($available)) {
                sendJsonResponse(['status' => 'error', 'message' => '2FA için sistem ayarlarınız eksik veya iletişim bilgileriniz tanımlı değil.'], 400);
            }

            sendJsonResponse([
                'status' => '2fa_choice_required',
                'message' => 'Doğrulama yöntemi seçiniz.',
                'available_methods' => $available,
                'user' => [
                    'username' => $user['username'],
                    'ad_soyad' => $user['ad_soyad'],
                    'eposta' => maskEmail($user['eposta'] ?? ''),
                    'telefon' => maskPhone($user['telefon'] ?? '')
                ]
            ]);
        }

        // Adım 1.5: Seçilen yöntemle kod gönderimi (send_2fa)
        if ($action === 'send_2fa') {
            $selectedMethod = trim($input['method'] ?? '');
            if (!in_array($selectedMethod, ['sms', 'email'])) {
                sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz doğrulama yöntemi.'], 400);
            }
            
            $settings = getSystemSettings($pdo);

            // 6 Haneli rastgele kod üretimi
            $generatedOtp = rand(100000, 999999);
            
            // Veritabanına kaydet (3 dakika geçerli)
            $updateStmt = $pdo->prepare("UPDATE users SET otp_code = :otp, otp_expires_at = DATE_ADD(NOW(), INTERVAL 3 MINUTE) WHERE id = :id");
            $updateStmt->execute([':otp' => $generatedOtp, ':id' => $user['id']]);

            $smsStatus = 'not_sent';
            $emailStatus = 'not_sent';
            $smsDebug = null;
            $emailDebug = null;

            // SMS Gönderimi
            if ($selectedMethod === 'sms' && !empty($user['telefon'])) {
                $smsId = $settings['vatansms_api_id'] ?? '';
                $smsKey = $settings['vatansms_api_key'] ?? '';
                $smsSender = $settings['vatansms_sender'] ?? '';
                
                if (!empty($smsId) && !empty($smsKey) && !empty($smsSender)) {
                    $cleanPhone = preg_replace('/[^0-9]/', '', $user['telefon']);
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.vatansms.net/api/v1/otp');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'api_id' => $smsId,
                        'api_key' => $smsKey,
                        'sender' => $smsSender,
                        'message_type' => 'normal',
                        'message' => "MBS Sistemine giriş için doğrulama kodunuz: {$generatedOtp}",
                        'phones' => [$cleanPhone]
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    $response = curl_exec($ch);
                    curl_close($ch);
                    
                    $smsStatus = 'sent';
                    $smsDebug = $response;
                } else {
                    sendJsonResponse(['status' => 'error', 'message' => 'SMS ayarları eksik.'], 400);
                }
            }

            // E-Posta Gönderimi
            if ($selectedMethod === 'email' && !empty($user['eposta'])) {
                if (!empty($settings['smtp_host'])) {
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = $settings['smtp_host'];
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $settings['smtp_user'] ?? '';
                        $mail->Password   = $settings['smtp_pass'] ?? '';
                        if (!empty($settings['smtp_secure'])) {
                            $mail->SMTPSecure = $settings['smtp_secure'];
                        }
                        $mail->Port       = $settings['smtp_port'] ?? 587;
                        $mail->CharSet    = 'UTF-8';

                        $mail->setFrom($settings['smtp_user'] ?? '', 'MBS Sistem');
                        $mail->addAddress($user['eposta']);

                        $mail->isHTML(true);
                        $mail->Subject = 'MBS Sistemine Giriş (Doğrulama Kodu)';
                        $mail->Body    = "MBS Sistemine giriş için doğrulama kodunuz: <b>{$generatedOtp}</b>";

                        $mail->send();
                        $emailStatus = 'sent';
                    } catch (PHPMailerException $e) {
                        $emailStatus = 'failed';
                        $emailDebug = $mail->ErrorInfo;
                        sendJsonResponse(['status' => 'error', 'message' => 'E-Posta gönderilemedi: ' . $mail->ErrorInfo], 500);
                    }
                } else {
                    sendJsonResponse(['status' => 'error', 'message' => 'SMTP ayarları eksik.'], 400);
                }
            }

            sendJsonResponse([
                'status' => '2fa_required',
                'message' => '2FA doğrulama kodunuz gönderildi.',
                'sms_status' => $smsStatus,
                'email_status' => $emailStatus,
                'sms_debug' => $smsDebug,
                'email_debug' => $emailDebug,
                'user' => [
                    'username' => $user['username'],
                    'ad_soyad' => $user['ad_soyad'],
                    'eposta' => maskEmail($user['eposta'] ?? ''),
                    'telefon' => maskPhone($user['telefon'] ?? '')
                ]
            ]);
        }

        // Adım 2: 2FA Kod doğrulaması
        if (empty($otp)) {
            sendJsonResponse(['status' => 'error', 'message' => '2FA doğrulama kodu (OTP) gereklidir.'], 400);
        }

        $stmtOtp = $pdo->prepare("SELECT otp_code, otp_expires_at FROM users WHERE id = :id");
        $stmtOtp->execute([':id' => $user['id']]);
        $otpData = $stmtOtp->fetch();

        if (!$otpData || empty($otpData['otp_code'])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçerli bir doğrulama kodu bulunamadı. Lütfen tekrar giriş yapın.'], 401);
        }

        if (strtotime($otpData['otp_expires_at']) < time()) {
            sendJsonResponse(['status' => 'error', 'message' => 'Doğrulama kodunun süresi dolmuş (3 Dakika). Lütfen tekrar giriş yapın.'], 401);
        }

        if ($otp !== $otpData['otp_code']) {
            logAuditAction($pdo, '2FA_FAILED', 'YONETIM_PANELI', "Hatalı 2FA Kod Girişi - Kullanıcı: $username");
            sendJsonResponse(['status' => 'error', 'message' => 'Hatalı 2FA doğrulama kodu!'], 401);
        }

        // Başarılı girişte OTP'yi sıfırla ve yeni Auth Token üret
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE users SET otp_code = NULL, otp_expires_at = NULL, auth_token = :token WHERE id = :id")->execute([
            ':id' => $user['id'],
            ':token' => $token
        ]);

        logAuditAction($pdo, 'LOGIN_SUCCESS', 'YONETIM_PANELI', "Kullanıcı 2FA ile başarıyla giriş yaptı (ID: #{$user['id']}, Kullanıcı Adı: {$user['username']}, Rol: {$user['role']})");

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Giriş başarılı.',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'ad_soyad' => $user['ad_soyad'],
                'unvan' => $user['unvan'],
                'role' => $user['role'],
                'eposta' => $user['eposta'] ?? '',
                'telefon' => $user['telefon'] ?? '',
                'can_delete' => intval($user['can_delete'] ?? ($user['role'] === 'superadmin' ? 1 : 0)),
                'can_edit' => intval($user['can_edit'] ?? 1),
                'can_add' => intval($user['can_add'] ?? 1),
                'can_export' => intval($user['can_export'] ?? 1)
            ]
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Giriş işlemi hatası: ' . $e->getMessage()], 500);
    }
}

/**
 * 📄 TEKLİ RESMİ DEFİN İZİN VE KAYIT BELGESİ RAPORU (Dinamik Ayarlar İle)
 */
function handleExportTekliPDF($pdo) {
    requirePermission('can_export');
    try {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz Kayıt ID!'], 400);
        }

        $stmt = $pdo->prepare("SELECT d.*, COALESCE(m.ad, 'Yeni Mezarlık') as mezarlik_adi FROM defin_kayitlari d LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id WHERE d.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch();

        if (!$record) {
            sendJsonResponse(['status' => 'error', 'message' => 'Belge oluşturulacak mefta kaydı bulunamadı.'], 404);
        }

        logAuditAction($pdo, 'EXPORT_TEKLI_BELGE', 'YONETIM_PANELI', "Tekli resmi defin belgesi oluşturuldu (ID: #$id, Mefta: {$record['ad']} {$record['soyad']})");

        if (ob_get_length()) {
            ob_end_clean();
        }

        $fieldsParam = $_GET['fields'] ?? '{}';
        $fields = json_decode($fieldsParam, true) ?: [];

        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('\Mpdf\Mpdf')) {
                try {
                    $mpdf = new \Mpdf\Mpdf([
                        'mode' => 'utf-8',
                        'format' => 'A4-P',
                        'default_font' => 'dejavusans',
                        'margin_left' => 10,
                        'margin_right' => 10,
                        'margin_top' => 10,
                        'margin_bottom' => 10,
                        'tempDir' => sys_get_temp_dir()
                    ]);

                    $mpdf->SetTitle("Çiftlikköy Belediyesi Defin Belgesi - #{$id}");
                    $mpdf->WriteHTML(getSingleDefinCertificateHtml($record, $pdo, false, $fields));

                    $pdfOutput = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

                    $adSoyad = trim($record['ad'] . '_' . $record['soyad']);
                    $safeName = str_replace(' ', '_', $adSoyad);
                    $fileName = "MBS_{$safeName}_Defin_Belgesi.pdf";

                    header('Content-Type: application/pdf');
                    header('Content-Length: ' . strlen($pdfOutput));
                    header("Content-Disposition: inline; filename=\"{$fileName}\"");
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');

                    echo $pdfOutput;
                    exit();
                } catch (Exception $mpdfEx) {}
            }
        }

        header("Content-Type: text/html; charset=UTF-8");
        echo getSingleDefinCertificateHtml($record, $pdo, true, $fields);
        exit();

    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Belge oluşturma hatası: ' . $e->getMessage()], 500);
    }
}

/**
 * DİNAMİK LOGO VE DİNAMİK YETKİLİ BİLGİLERİ İLE RESMİ BELGE HTML TASARIMI
 */
function getSingleDefinCertificateHtml($r, $pdo, $autoPrint = false, $fields = [], $isWord = false) {
    error_log("getSingleDefinCertificateHtml received fields: " . json_encode($fields));
    $sys = getSystemSettings($pdo);
    
    $sayiNo = date('Y') . " / " . str_pad($r['id'], 3, '0', STR_PAD_LEFT);
    $definTarihiFormat = date('d.m.Y', strtotime($r['defin_tarihi']));
    
    // Word (.doc HTML formatı) için logo her halükarda eklenecek, genişlik Word'e sığacak şekilde kısıtlanıyor.
    $logoHtml = "<div style='text-align:center; margin-bottom:5px;'><img src='https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg' height='65' width='65' alt='Logo'></div>";

    $html = "<html><head><style>
        @page { margin: 1cm; }
        body { font-family: 'Calibri', 'Helvetica', sans-serif; font-size: 10pt; color: #111; margin: 0; padding: 0; }
        .bordered-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .bordered-table td { padding: 4px; border: 1px solid #000; vertical-align: middle; }
        .label { font-weight: bold; width: 35%; background-color: #f1f5f9; }
        .section-title { font-weight: bold; font-size: 11pt; color: #1e3a8a; margin-top: 10px; margin-bottom: 4px; border-bottom: 2px solid #cbd5e1; padding-bottom: 2px; }
    </style></head><body onload='" . ($autoPrint ? "window.print();" : "") . "'>";
    
    $html .= $logoHtml;
    $belediyeAdi = !empty($sys['belediye_adi']) ? mb_strtoupper($sys['belediye_adi'], 'UTF-8') : 'ÇİFTLİKKÖY BELEDİYESİ';
    $mudurlukAdi = !empty($sys['mudurluk_adi']) ? $sys['mudurluk_adi'] : 'Park ve Bahçeler Müdürlüğü';
    
    $html .= "<div style='text-align:center; border-bottom:2px solid #1e3a8a; padding-bottom:8px; margin-bottom:10px;'>";
    $html .= "<h1 style='font-size:14pt; color:#1e3a8a; margin:0;'>T.C. {$belediyeAdi} BAŞKANLIĞI</h1>";
    $html .= "<h2 style='font-size:11pt; color:#333; margin:3px 0 0 0;'>{$mudurlukAdi}<br>Resmi Defin Kayıt Belgesi</h2>";
    $html .= "</div>";
    
    $html .= "<table style='width:100%; margin-bottom:10px;'>";
    $html .= "<tr><td width='50%'><b>Belge Kayıt No:</b> {$sayiNo}</td><td width='50%' style='text-align:right;'><b>Düzenlenme Tarihi:</b> " . date('d.m.Y') . "</td></tr>";
    $html .= "</table>";
    
    $html .= "<div class='section-title'>1. VEFAT EDENİN BİLGİLERİ</div>";
    $html .= "<table class='bordered-table' style='width:100%; border-collapse:collapse; border:1px solid #000;'>";
    if (!isset($fields['tc_no']) || $fields['tc_no'] === true) {
        $html .= "<tr><td class='label' style='font-weight:bold; width:35%; border:1px solid #000; padding:4px;'>T.C. Kimlik No</td><td style='border:1px solid #000; padding:4px;'>{$r['tc_no']}</td></tr>";
    }
    $html .= "<tr><td class='label' style='font-weight:bold; width:35%; border:1px solid #000; padding:4px;'>Adı ve Soyadı</td><td style='border:1px solid #000; padding:4px;'><b>{$r['ad']} {$r['soyad']}</b></td></tr>";
    
    $baba = (!isset($fields['baba_adi']) || $fields['baba_adi'] === true) ? ($r['baba_adi'] ?: '-') : '-';
    $anne = (!isset($fields['anne_adi']) || $fields['anne_adi'] === true) ? ($r['anne_adi'] ?: '-') : '-';
    
    if (!isset($fields['baba_adi']) || $fields['baba_adi'] === true || !isset($fields['anne_adi']) || $fields['anne_adi'] === true) {
        $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Baba / Anne Adı</td><td style='border:1px solid #000; padding:4px;'>{$baba} / {$anne}</td></tr>";
    }
    
    $dogumTarihi = formatDisplayDate($r['dogum_tarihi'] ?? '');
    $dogumYeri = $r['dogum_yeri'] ?? 'Bilinmiyor';
    $dogumStr = (!empty($dogumTarihi)) ? "{$dogumTarihi} - {$dogumYeri}" : $dogumYeri;
    if (!isset($fields['dogum_tarihi']) || $fields['dogum_tarihi'] === true || !isset($fields['il_ilce']) || $fields['il_ilce'] === true) {
        $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Doğum Yeri ve Tarihi</td><td style='border:1px solid #000; padding:4px;'>{$dogumStr}</td></tr>";
    }
    
    $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Ölüm Nedeni</td><td style='border:1px solid #000; padding:4px;'>" . mb_strtoupper($r['olum_nedeni'] ?: 'DOĞAL ÖLÜM', 'UTF-8') . "</td></tr>";
    
    $olumTarihi = formatDisplayDate($r['olum_tarihi'] ?? '');
    $olumBelgeTarihi = !empty($olumTarihi) ? $olumTarihi : $definTarihiFormat;
    $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Ölüm Tarihi</td><td style='border:1px solid #000; padding:4px;'>{$olumBelgeTarihi}</td></tr>";
    $html .= "</table>";
    
    $html .= "<div class='section-title'>2. DEFİN BİLGİLERİ</div>";
    $html .= "<table class='bordered-table' style='width:100%; border-collapse:collapse; border:1px solid #000;'>";
    $html .= "<tr><td class='label' style='font-weight:bold; width:35%; border:1px solid #000; padding:4px;'>Mezarlık Alanı</td><td style='border:1px solid #000; padding:4px;'><b>{$r['mezarlik_adi']}</b></td></tr>";
    $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Defin Bölümü Tipi</td><td style='border:1px solid #000; padding:4px;'>" . ($r['defin_bolum'] ?: 'SIRADAN') . "</td></tr>";
    
    if (!isset($fields['ada_parsel']) || $fields['ada_parsel'] === true) {
        $ada = $r['ada_no_text'] ?? '-';
        $parsel = $r['parsel_no_text'] ?? '-';
        $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Gömüleceği Konum</td><td style='border:1px solid #000; padding:4px;'><b>Ada:</b> {$ada} &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp; <b>Parsel:</b> {$parsel}</td></tr>";
    }
    $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Resmi Defin Tarihi</td><td style='border:1px solid #000; padding:4px;'>{$definTarihiFormat}</td></tr>";
    $html .= "</table>";
    
    if (!isset($fields['yakin_bilgisi']) || $fields['yakin_bilgisi'] === true) {
        $html .= "<div class='section-title'>3. CENAZE YAKINI BİLGİLERİ</div>";
        $html .= "<table class='bordered-table' style='width:100%; border-collapse:collapse; border:1px solid #000;'>";
        $html .= "<tr><td class='label' style='font-weight:bold; width:35%; border:1px solid #000; padding:4px;'>Yakının Adı Soyadı</td><td style='border:1px solid #000; padding:4px;'>{$r['yakin_ad_soyad']}</td></tr>";
        $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>Akrabalık Derecesi</td><td style='border:1px solid #000; padding:4px;'>{$r['yakin_akrabalik']}</td></tr>";
        $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>İletişim Numarası</td><td style='border:1px solid #000; padding:4px;'>{$r['yakin_telefon']}</td></tr>";
        $html .= "<tr><td class='label' style='font-weight:bold; border:1px solid #000; padding:4px;'>İkamet Adresi</td><td style='border:1px solid #000; padding:4px;'>{$r['yakin_adres']}</td></tr>";
        $html .= "</table>";
    }
    
    $mezSorumlu = !empty($sys['mezarlik_sorumlusu_ad']) ? $sys['mezarlik_sorumlusu_ad'] : '';
    $mezUnvan = !empty($sys['mezarlik_sorumlusu_unvan']) ? $sys['mezarlik_sorumlusu_unvan'] : 'Mezarlık Birim Sorumlusu';
    if (empty($mezSorumlu)) {
        $mezSorumlu = '_______________________';
    }
    
    $sagImzaTipi = $fields['sag_imza_tipi'] ?? 'mudur';
    if ($sagImzaTipi === 'yakin') {
        $sagSorumlu = $r['yakin_ad_soyad'] ?: '_______________________';
        $sagUnvan = 'Cenaze Yakını';
        $sagBaslik = 'Teslim Alan';
    } else {
        $sagSorumlu = !empty($sys['birim_sorumlusu_ad']) ? $sys['birim_sorumlusu_ad'] : '';
        $sagUnvan = !empty($sys['birim_sorumlusu_unvan']) ? $sys['birim_sorumlusu_unvan'] : 'Park ve Bahçeler Müdürü';
        if (empty($sagSorumlu)) {
            $sagSorumlu = '_______________________';
        }
    $sagBaslik = 'Tasdik Eden';
    }
    
    if (!isset($fields['muvafakatname']) || $fields['muvafakatname'] === true) {
        $html .= "<br>";
        $html .= "<table style='width:100%; border:2px dashed #94a3b8; background-color:#f8fafc; padding:10px; margin-top:10px;'>";
        $html .= "<tr><td style='font-size:11pt; font-weight:bold; color:#1e3a8a; padding-bottom:5px;'>Dijital Platformlarda Yayın Onayı</td></tr>";
        $html .= "<tr><td style='font-size:10pt; text-align:justify;'>Yukarıda kimlik ve defin bilgileri yazılı bulunan vefat eden yakınımın cenaze ilanının, defin konum bilgilerinin ve mezar fotoğraflarının Çiftlikköy Belediyesi'ne ait <b>e-Çiftlikköy mobil uygulamasında</b>, belediye resmi web sitesinde ve dijital bilgi ekranlarında halka açık şekilde yayınlanmasına kendi özgür irademle;</td></tr>";
        $html .= "<tr><td style='padding-top:10px;'>";
        $html .= "<span style='margin-right:30px;'><input type='checkbox' style='vertical-align:middle;'> ONAY VERİYORUM</span>";
        $html .= "<span><input type='checkbox' style='vertical-align:middle;'> ONAY VERMİYORUM</span>";
        $html .= "</td></tr>";
        $html .= "</table>";
    }

    $showSol = !isset($fields['sol_imza']) || $fields['sol_imza'] === true;
    $showSag = !isset($fields['sag_imza']) || $fields['sag_imza'] === true;

    if ($showSol || $showSag) {
        $html .= "<br><br>";
        $html .= "<table style='width:100%; text-align:center;'>";
        $html .= "<tr>";
        
        if ($showSol) {
            $html .= "<td width='50%'><b>Onaylayan Yetkili</b><br><br><br>{$mezSorumlu}<br><i>{$mezUnvan}</i></td>";
        } else {
            $html .= "<td width='50%'></td>";
        }
        
        if ($showSag) {
            $html .= "<td width='50%'><b>{$sagBaslik}</b><br><br><br>{$sagSorumlu}<br><i>{$sagUnvan}</i></td>";
        } else {
            $html .= "<td width='50%'></td>";
        }
        
        $html .= "</tr>";
        $html .= "</table>";
    }
    
    $html .= "</body></html>";
    
    return $html;
}

function handleExportDocx($pdo) {
    requirePermission('can_export');
    try {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz Kayıt ID!'], 400);
        }

        $stmt = $pdo->prepare("SELECT d.*, COALESCE(m.ad, 'Yeni Mezarlık') as mezarlik_adi FROM defin_kayitlari d LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id WHERE d.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch();

        if (!$record) {
            sendJsonResponse(['status' => 'error', 'message' => 'Belge oluşturulacak mefta kaydı bulunamadı.'], 404);
        }

        logAuditAction($pdo, 'EXPORT_TEKLI_BELGE', 'YONETIM_PANELI', "Tekli DOC belgesi oluşturuldu (ID: #$id, Mefta: {$record['ad']} {$record['soyad']})");

        if (ob_get_length()) {
            ob_end_clean();
        }

        $fieldsParam = $_GET['fields'] ?? '{}';
        $fields = json_decode($fieldsParam, true) ?: [];

        $html = getSingleDefinCertificateHtml($record, $pdo, false, $fields, true);
        
        $adSoyad = trim($record['ad'] . '_' . $record['soyad']);
        $safeName = str_replace(' ', '_', $adSoyad);
        $fileName = "MBS_{$safeName}_Defin_Belgesi.doc";

        header("Content-Type: application/vnd.ms-word; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"{$fileName}\"");
        header('Cache-Control: max-age=0');
        
        echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>";
        echo "<head><meta charset='utf-8'><title>Defin Belgesi</title></head>";
        echo "<body>";
        echo $html;
        echo "</body></html>";
        exit();

    } catch (Exception $e) {
        error_log("Docx Hatası: " . $e->getMessage());
        sendJsonResponse(['status' => 'error', 'message' => 'Word oluşturulurken hata meydana geldi.'], 500);
    }
}

/**
 * SUNUCU TARAFLI SEÇİLİ VEYA TÜM KAYITLARI ÇIKARMA (PDF - DENGELİ BAŞLIK)
 */
function handleExportPDF($pdo) {
    try {
        $logExport = isset($_GET['log_export']) ? intval($_GET['log_export']) : 0;
        if ($logExport === 1) {
            $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 200");
            $logs = $stmt->fetchAll();

            if (ob_get_length()) ob_end_clean();
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sistem İşlem Logları - Çiftlikköy Belediyesi</title>';
            echo '<style>body{font-family:sans-serif; font-size:12px; margin:20px;} table{width:100%; border-collapse:collapse; margin-top:15px;} th,td{border:1px solid #cbd5e1; padding:8px; text-align:left;} th{background:#1e3a8a; color:white;} tr:nth-child(even){background:#f8fafc;}</style>';
            echo '</head><body onload="window.print()">';
            echo '<h2 style="color:#1e3a8a; margin:0;">T.C. ÇİFTLİKKÖY BELEDİYESİ - MEZARLIK BİLGİ SİSTEMİ</h2>';
            echo '<h3 style="color:#475569; margin:4px 0 15px 0;">Sistem İşlem Ve Denetim Logları Raporu (' . date('d/m/Y H:i') . ')</h3>';
            echo '<table><thead><tr><th>ID</th><th>İşlem Tipi</th><th>Modül</th><th>Yetkili Personel</th><th>İşlem Detayı</th><th>IP Adresi</th><th>Tarih</th></tr></thead><tbody>';
            foreach ($logs as $l) {
                echo '<tr>';
                echo '<td>#' . $l['id'] . '</td>';
                echo '<td><b>' . htmlspecialchars($l['islem_tipi']) . '</b></td>';
                echo '<td>' . htmlspecialchars($l['modul']) . '</td>';
                echo '<td>' . htmlspecialchars($l['kullanici'] ?? 'Sistem') . '</td>';
                echo '<td>' . htmlspecialchars($l['detay']) . '</td>';
                echo '<td>' . htmlspecialchars($l['ip_adresi'] ?? '127.0.0.1') . '</td>';
                echo '<td>' . date('d/m/Y H:i', strtotime($l['created_at'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></body></html>';
            exit();
        }

        $idsParam = $_GET['ids'] ?? '';
        $q = trim($_GET['q'] ?? '');
        $mezarlikId = intval($_GET['mezarlik_id'] ?? 0);
        $sql = "SELECT d.*, COALESCE(m.ad, 'Yeni Mezarlık') as mezarlik_adi FROM defin_kayitlari d LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id WHERE 1=1";
        $params = [];

        if (!empty($idsParam)) {
            $idArray = array_map('intval', explode(',', $idsParam));
            $idArray = array_filter($idArray, fn($i) => $i > 0);
            if (!empty($idArray)) {
                $inClause = implode(',', $idArray);
                $sql .= " AND d.id IN ($inClause)";
            }
        } else {
            if ($mezarlikId > 0) {
                $sql .= " AND d.mezarlik_id = :mezarlik_id";
                $params[':mezarlik_id'] = $mezarlikId;
            }
            if (!empty($q)) {
                $qClean = str_replace(['İ','I','ı','Ü','ü','Ö','ö','Ş','ş','Ç','ç','Ğ','ğ'], ['i','i','i','u','u','o','o','s','s','c','c','g','g'], $q);
                $words = array_filter(explode(' ', mb_strtolower($qClean, 'UTF-8')));
                $i = 0;
                foreach ($words as $word) {
                    $i++;
                    $paramKey = ":q{$i}";
                    $trRepl = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), 'İ', 'i'), 'I', 'i'), 'ı', 'i'), 'Ü', 'u'), 'Ö', 'o'), 'Ş', 's'), 'Ç', 'c'), 'Ğ', 'g')";
                    $sqlAd = sprintf($trRepl, "d.ad");
                    $sqlSoyad = sprintf($trRepl, "d.soyad");
                    $sqlConcat = sprintf($trRepl, "CONCAT(d.ad, ' ', d.soyad)");
                    $sql .= " AND ({$sqlAd} LIKE {$paramKey} OR {$sqlSoyad} LIKE {$paramKey} OR {$sqlConcat} LIKE {$paramKey})";
                    $params[$paramKey] = '%' . $word . '%';
                }
            }
        }

        $sql .= " ORDER BY d.id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        logAuditAction($pdo, 'EXPORT_PDF_MPDF', 'YONETIM_PANELI', "Sunucu taraflı PDF rapor üretildi (" . count($results) . " kayıt)");

        if (ob_get_length()) {
            ob_end_clean();
        }

        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('\Mpdf\Mpdf')) {
                try {
                    $mpdf = new \Mpdf\Mpdf([
                        'mode' => 'utf-8',
                        'format' => 'A4-L',
                        'default_font' => 'dejavusans',
                        'margin_left' => 10,
                        'margin_right' => 10,
                        'margin_top' => 10,
                        'margin_bottom' => 10,
                        'tempDir' => sys_get_temp_dir()
                    ]);

                    $sys = getSystemSettings($pdo);
                    $belediyeBaslik = !empty($sys['belediye_adi']) ? $sys['belediye_adi'] : 'Çiftlikköy Belediyesi';
                    $mpdf->SetTitle("{$belediyeBaslik} Defin Raporu");
                    $mpdf->WriteHTML(getPdfReportHtml($results, false, $sys));

                    $pdfOutput = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

                    $fileName = "MBS_Toplu_Defin_Listesi.pdf";
                    if (count($results) === 1) {
                        $adSoyad = trim($results[0]['ad'] . '_' . $results[0]['soyad']);
                        $safeName = str_replace(' ', '_', $adSoyad);
                        $fileName = "MBS_{$safeName}_Rapor.pdf";
                    }

                    header('Content-Type: application/pdf');
                    header('Content-Length: ' . strlen($pdfOutput));
                    header("Content-Disposition: inline; filename=\"{$fileName}\"");
                    header('Cache-Control: private, max-age=0, must-revalidate');

                    echo $pdfOutput;
                    exit();
                } catch (Exception $mpdfEx) {}
            }
        }

        $sys = getSystemSettings($pdo);
        header("Content-Type: text/html; charset=UTF-8");
        echo getPdfReportHtml($results, true, $sys);
        exit();

    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'PDF Rapor üretme hatası: ' . $e->getMessage()], 500);
    }
}

/**
 * SUNUCU TARAFLI SEÇİLİ VEYA TÜM KAYITLARI ÇIKARMA (EXCEL PHPSPREADSHEET)
 */
function handleExportExcel($pdo) {
    requirePermission('can_export');
    try {
        $idsParam = $_GET['ids'] ?? '';
        $q = trim($_GET['q'] ?? '');
        $mezarlikId = intval($_GET['mezarlik_id'] ?? 0);
        $sql = "SELECT d.*, COALESCE(m.ad, 'Yeni Mezarlık') as mezarlik_adi FROM defin_kayitlari d LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id WHERE 1=1";
        $params = [];

        if (!empty($idsParam)) {
            $idArray = array_map('intval', explode(',', $idsParam));
            $idArray = array_filter($idArray, fn($i) => $i > 0);
            if (!empty($idArray)) {
                $inClause = implode(',', $idArray);
                $sql .= " AND d.id IN ($inClause)";
            }
        } else {
            if ($mezarlikId > 0) {
                $sql .= " AND d.mezarlik_id = :mezarlik_id";
                $params[':mezarlik_id'] = $mezarlikId;
            }
            if (!empty($q)) {
                $qClean = str_replace(['İ','I','ı','Ü','ü','Ö','ö','Ş','ş','Ç','ç','Ğ','ğ'], ['i','i','i','u','u','o','o','s','s','c','c','g','g'], $q);
                $words = array_filter(explode(' ', mb_strtolower($qClean, 'UTF-8')));
                $i = 0;
                foreach ($words as $word) {
                    $i++;
                    $paramKey = ":q{$i}";
                    $trRepl = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), 'İ', 'i'), 'I', 'i'), 'ı', 'i'), 'Ü', 'u'), 'Ö', 'o'), 'Ş', 's'), 'Ç', 'c'), 'Ğ', 'g')";
                    $sqlAd = sprintf($trRepl, "d.ad");
                    $sqlSoyad = sprintf($trRepl, "d.soyad");
                    $sqlConcat = sprintf($trRepl, "CONCAT(d.ad, ' ', d.soyad)");
                    $sql .= " AND ({$sqlAd} LIKE {$paramKey} OR {$sqlSoyad} LIKE {$paramKey} OR {$sqlConcat} LIKE {$paramKey})";
                    $params[$paramKey] = '%' . $word . '%';
                }
            }
        }

        $sql .= " ORDER BY d.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        logAuditAction($pdo, 'EXPORT_EXCEL_PHPSPREADSHEET', 'YONETIM_PANELI', "Sunucu taraflı Excel arşivi indirildi (" . count($results) . " kayıt)");

        if (ob_get_length()) {
            ob_end_clean();
        }

        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Defin Kayıtları');

                $headers = ['Kayıt ID', 'Vefat Eden Adı', 'Vefat Eden Soyadı', 'TC Kimlik No', 'Mezarlık Alanı', 'Ada No', 'Parsel No', 'Defin Tarihi', 'Cenaze Yakını', 'Yakın Telefon', 'Ölüm Nedeni'];
                $sheet->fromArray($headers, NULL, 'A1');

                $sheet->getStyle('A1:K1')->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
                $sheet->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('1E3A8A');

                $rowNum = 2;
                foreach ($results as $r) {
                    $adaNo = $r['ada_no_text'] ?? '';
                    $parselNo = $r['parsel_no_text'] ?? '';
                    $sheet->fromArray([
                        $r['id'],
                        $r['ad'],
                        $r['soyad'],
                        $r['tc_no'] ?? '',
                        $r['mezarlik_adi'],
                        $adaNo,
                        $parselNo,
                        formatDisplayDate($r['defin_tarihi']),
                        $r['yakin_ad_soyad'] ?? '',
                        $r['yakin_telefon'] ?? '',
                        $r['olum_nedeni'] ?? 'Doğal Ölüm'
                    ], NULL, 'A' . $rowNum);
                    $rowNum++;
                }

                foreach (range('A', 'K') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $fileName = "MBS_Toplu_Defin_Listesi_" . date('Y-m-d') . ".xlsx";
                if (count($results) === 1) {
                    $adSoyad = trim($results[0]['ad'] . '_' . $results[0]['soyad']);
                    $safeName = str_replace(' ', '_', $adSoyad);
                    $fileName = "MBS_{$safeName}_Rapor.xlsx";
                }

                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment;filename="' . $fileName . '"');
                header('Cache-Control: max-age=0');

                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
                exit();
            }
        }

        $fileName = "MBS_Toplu_Defin_Listesi_" . date('Y-m-d') . ".csv";
        if (count($results) === 1) {
            $adSoyad = trim($results[0]['ad'] . '_' . $results[0]['soyad']);
            $safeName = str_replace(' ', '_', $adSoyad);
            $fileName = "MBS_{$safeName}_Rapor.csv";
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Kayıt ID', 'Adı', 'Soyadı', 'TC Kimlik No', 'Mezarlık Alanı', 'Ada No', 'Parsel No', 'Defin Tarihi', 'Cenaze Yakını', 'Yakın Telefon', 'Ölüm Nedeni'], ';');

        foreach ($results as $r) {
            $adaNo = $r['ada_no_text'] ?? '';
            $parselNo = $r['parsel_no_text'] ?? '';
            fputcsv($output, [
                $r['id'],
                $r['ad'],
                $r['soyad'],
                $r['tc_no'] ?? '',
                $r['mezarlik_adi'],
                $adaNo,
                $parselNo,
                formatDisplayDate($r['defin_tarihi']),
                $r['yakin_ad_soyad'] ?? '',
                $r['yakin_telefon'] ?? '',
                $r['olum_nedeni'] ?? 'Doğal Ölüm'
            ], ';');
        }

        fclose($output);
        exit();

    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Excel üretme hatası: ' . $e->getMessage()], 500);
    }
}

function getPdfReportHtml($results, $autoPrint = false, $sys = []) {
    $belediyeAdi = !empty($sys['belediye_adi']) ? mb_strtoupper($sys['belediye_adi'], 'UTF-8') : 'ÇİFTLİKKÖY BELEDİYESİ';
    $mudurlukAdi = !empty($sys['mudurluk_adi']) ? mb_strtoupper($sys['mudurluk_adi'], 'UTF-8') : 'PARK VE BAHÇELER MÜDÜRLÜĞÜ';

    $rowsHtml = '';
    foreach ($results as $r) {
        $adaNo = $r['ada_no_text'] ?? '-';
        $parselNo = $r['parsel_no_text'] ?? '-';
        $rowsHtml .= "
            <tr>
                <td style='padding:6px; border:1px solid #cbd5e1; text-align:center;'>#{$r['id']}</td>
                <td style='padding:6px; border:1px solid #cbd5e1;'><strong>{$r['ad']} {$r['soyad']}</strong></td>
                <td style='padding:6px; border:1px solid #cbd5e1; text-align:center;'>" . ($r['tc_no'] ?: '-') . "</td>
                <td style='padding:6px; border:1px solid #cbd5e1;'>{$r['mezarlik_adi']}</td>
                <td style='padding:6px; border:1px solid #cbd5e1; text-align:center;'>Ada: {$adaNo} / P: {$parselNo}</td>
                <td style='padding:6px; border:1px solid #cbd5e1; text-align:center;'>" . formatDisplayDate($r['defin_tarihi']) . "</td>
                <td style='padding:6px; border:1px solid #cbd5e1;'>" . ($r['yakin_ad_soyad'] ?: '-') . " (" . ($r['yakin_telefon'] ?: '-') . ")</td>
            </tr>
        ";
    }

    $logoPath = file_exists(__DIR__ . '/../assets/img/logo.svg')
        ? __DIR__ . '/../assets/img/logo.svg'
        : 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg';

    $printScript = $autoPrint ? "<script>window.onload = function() { window.print(); }</script>" : "";

    return "
    <!DOCTYPE html>
    <html lang='tr'>
    <head>
        <meta charset='UTF-8'>
        <title>T.C. {$belediyeAdi} Resmi Defin Raporu</title>
        <style>
            body { font-family: dejavusans; font-size: 11px; color: #0f172a; margin: 15px; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; border-bottom: 3px solid #1e3a8a; padding-bottom: 10px; }
            .report-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .report-table th { background-color: #1e3a8a; color: #ffffff; padding: 8px; border: 1px solid #172554; text-align: left; font-size: 10px; }
            .footer-info { margin-top: 20px; font-size: 9px; color: #64748b; text-align: right; }
        </style>
        {$printScript}
    </head>
    <body>
        <table class='header-table'>
            <tr>
                <td style='width: 90px; text-align: left; vertical-align: middle;'>
                    <img src='{$logoPath}' height='70'>
                </td>
                <td style='text-align: center; vertical-align: middle;'>
                    <h2 style='margin:0; color:#1e3a8a; font-size:17px;'>T.C. {$belediyeAdi} BAŞKANLIĞI</h2>
                    <h3 style='margin:4px 0 0 0; color:#475569; font-size:13px;'>{$mudurlukAdi} - MEZARLIK BİLGİ SİSTEMİ DEFIN RAPORU</h3>
                </td>
                <td style='width: 140px; text-align: right; font-size: 9.5px; color: #475569;'>
                    <b>Rapor Tarihi:</b> " . date('d/m/Y H:i') . "<br>
                    <b>Toplam Kayıt:</b> " . count($results) . "
                </td>
            </tr>
        </table>

        <table class='report-table'>
            <thead>
                <tr>
                    <th style='width:50px; text-align:center;'>ID</th>
                    <th>Vefat Eden Ad Soyad</th>
                    <th style='text-align:center;'>TC Kimlik No</th>
                    <th>Mezarlık Alanı</th>
                    <th style='text-align:center;'>Ada / Parsel</th>
                    <th style='text-align:center;'>Defin Tarihi</th>
                    <th>Cenaze Yakını & Tel</th>
                </tr>
            </thead>
            <tbody>
                {$rowsHtml}
            </tbody>
        </table>

        <div class='footer-info'>
            Bu belge Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS) sunucusu tarafından elektronik ortamda üretilmiştir.
        </div>
        
        <br><br><br>
        <table style='width:100%; text-align:center; margin-top:30px; font-size:11px; color:#0f172a; page-break-inside: avoid;'>
            <tr>
                <td width='50%'><b>Onaylayan Yetkili</b><br><br><br><br>
                " . (!empty($sys['mezarlik_sorumlusu_ad']) ? $sys['mezarlik_sorumlusu_ad'] : '_______________________') . "<br>
                <i>" . (!empty($sys['mezarlik_sorumlusu_unvan']) ? $sys['mezarlik_sorumlusu_unvan'] : 'Mezarlık Birim Sorumlusu') . "</i></td>
                
                <td width='50%'><b>Tasdik Eden</b><br><br><br><br>
                " . (!empty($sys['birim_sorumlusu_ad']) ? $sys['birim_sorumlusu_ad'] : '_______________________') . "<br>
                <i>" . (!empty($sys['birim_sorumlusu_unvan']) ? $sys['birim_sorumlusu_unvan'] : 'Park ve Bahçeler Müdürü') . "</i></td>
            </tr>
        </table>
    </body>
    </html>
    ";
}

function handleKullanicilar($pdo) {
    requirePermission('can_add');
    try {
        $stmt = $pdo->query("SELECT id, username, ad_soyad, unvan, role, durum, eposta, telefon, can_delete, can_edit, can_add, can_export, created_at FROM users ORDER BY id DESC");
        $users = $stmt->fetchAll();
        foreach ($users as &$u) {
            $u['created_at'] = formatDisplayDate($u['created_at']);
            $u['can_delete'] = intval($u['can_delete'] ?? ($u['role'] === 'superadmin' ? 1 : 0));
            $u['can_edit'] = intval($u['can_edit'] ?? 1);
            $u['can_add'] = intval($u['can_add'] ?? 1);
            $u['can_export'] = intval($u['can_export'] ?? 1);
        }
        sendJsonResponse(['status' => 'success', 'data' => $users]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleKullaniciEkle($pdo) {
    requirePermission('can_add');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '123456');
        $adSoyad  = trim($input['ad_soyad'] ?? '');
        $unvan    = trim($input['unvan'] ?? 'Belediye Personeli');
        $role     = trim($input['role'] ?? 'staff');
        $eposta   = trim($input['eposta'] ?? '');
        $telefon  = trim($input['telefon'] ?? '');

        $canDelete = isset($input['can_delete']) ? intval($input['can_delete']) : ($role === 'superadmin' ? 1 : 0);
        $canEdit   = isset($input['can_edit']) ? intval($input['can_edit']) : 1;
        $canAdd    = isset($input['can_add']) ? intval($input['can_add']) : 1;
        $canExport = isset($input['can_export']) ? intval($input['can_export']) : 1;

        if (empty($username) || empty($adSoyad)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Kullanıcı Adı ve Ad Soyad alanları zorunludur!'], 400);
        }

        $sql = "INSERT INTO users (username, password, ad_soyad, unvan, role, durum, eposta, telefon, can_delete, can_edit, can_add, can_export) 
                VALUES (:user, :pass, :ad_soyad, :unvan, :role, 'aktif', :eposta, :telefon, :can_delete, :can_edit, :can_add, :can_export)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user' => $username,
            ':pass' => $hashedPassword,
            ':ad_soyad' => $adSoyad,
            ':unvan' => $unvan,
            ':role' => $role,
            ':eposta' => $eposta,
            ':telefon' => $telefon,
            ':can_delete' => $canDelete,
            ':can_edit' => $canEdit,
            ':can_add' => $canAdd,
            ':can_export' => $canExport
        ]);

        $newId = $pdo->lastInsertId();
        logAuditAction($pdo, 'KULLANICI_EKLENDI', 'YONETIM_PANELI', "Süper Admin tarafından yeni kullanıcı oluşturuldu (ID: #$newId, Kullanıcı Adı: $username, Ad Soyad: $adSoyad, Rol: $role, Silme Yetkisi: $canDelete)");

        sendJsonResponse([
            'status' => 'success',
            'message' => "Yeni kullanıcı '$adSoyad' ($username) başarıyla oluşturuldu.",
            'id' => $newId
        ], 201);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Kullanıcı ekleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleKullaniciDuzenle($pdo) {
    requirePermission('can_edit');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;
        
        $id = intval($input['id'] ?? 0);
        $username = trim($input['username'] ?? '');
        $adSoyad  = trim($input['ad_soyad'] ?? '');
        $unvan    = trim($input['unvan'] ?? '');
        $telefon  = trim($input['telefon'] ?? '');
        $eposta   = trim($input['eposta'] ?? '');
        $durum    = trim($input['durum'] ?? 'aktif');
        
        global $currentUser;
        $isSuperAdmin = ($currentUser['role'] === 'superadmin');

        $params = [
            ':a' => $adSoyad,
            ':unv' => $unvan,
            ':r' => $input['role'] ?? 'staff',
            ':ep' => $eposta,
            ':tel' => $telefon,
            ':d' => $durum,
            ':id' => $id
        ];

        if (!$isSuperAdmin) {
            if ($currentUser['id'] != $id) {
                sendJsonResponse(['status' => 'error', 'message' => 'Sadece kendi profilinizi düzenleyebilirsiniz!'], 403);
            }
            if (empty($input['password'])) {
                sendJsonResponse(['status' => 'error', 'message' => 'Değişiklik yapmak için yeni bir şifre girmelisiniz.'], 400);
            }
            $stmt = $pdo->prepare("UPDATE users SET password = :pass WHERE id = :id");
            $stmt->execute([':pass' => hash('sha512', $input['password']), ':id' => $id]);
            logAuditAction($pdo, 'KULLANICI_SIFRE_DEGISTI', 'YONETIM_PANELI', "Kullanıcı kendi şifresini güncelledi (ID: #$id)");
            sendJsonResponse(['status' => 'success', 'message' => 'Şifreniz başarıyla güncellendi.']);
            return;
        }

        // --- SADECE SUPERADMIN BURAYA ULAŞABİLİR ---
        if ($id <= 0 || empty($username) || empty($adSoyad)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Kullanıcı Adı ve Ad Soyad alanları zorunludur!'], 400);
        }
        
        if (!empty($input['password'])) {
            $sql = "UPDATE users SET username = :user, ad_soyad = :a, unvan = :unv, telefon = :tel, eposta = :ep, durum = :d, password = :pass WHERE id = :id";
            $params[':pass'] = hash('sha512', $input['password']);
            $params[':user'] = $username;
        } else {
            $sql = "UPDATE users SET username = :user, ad_soyad = :a, unvan = :unv, telefon = :tel, eposta = :ep, durum = :d WHERE id = :id";
            $params[':user'] = $username;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        logAuditAction($pdo, 'KULLANICI_DUZENLENDI', 'YONETIM_PANELI', "Kullanıcı profili güncellendi (ID: #$id, Kullanıcı Adı: $username)");
        sendJsonResponse(['status' => 'success', 'message' => 'Kullanıcı bilgileri güncellendi.']);
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
            sendJsonResponse(['status' => 'error', 'message' => 'Bu Kullanıcı Adı zaten sistemde kayıtlı!'], 400);
        }
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleKullaniciYetkiGuncelle($pdo) {
    requirePermission('can_add');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz Kullanıcı ID!'], 400);
        }

        $canDelete  = intval($input['can_delete'] ?? 0);
        $canEdit    = intval($input['can_edit'] ?? 1);
        $canAdd     = intval($input['can_add'] ?? 1);
        $canExport  = intval($input['can_export'] ?? 1);
        $canApprove = intval($input['can_approve'] ?? 0);

        $stmt = $pdo->prepare("UPDATE users SET can_delete = :del, can_edit = :edit, can_add = :add, can_export = :exp, can_approve = :app WHERE id = :id");
        $stmt->execute([
            ':id'   => $id,
            ':del'  => $canDelete,
            ':edit' => $canEdit,
            ':add'  => $canAdd,
            ':exp'  => $canExport,
            ':app'  => $canApprove
        ]);

        logAuditAction($pdo, 'KULLANICI_YETKİ_GÜNCELLENDİ', 'KULLANICI_YONETIMI', "Süper Admin tarafından #$id ID'li kullanıcının özel izinleri güncellendi (Silme Yetkisi: $canDelete)");

        sendJsonResponse([
            'status' => 'success',
            'message' => "Kullanıcı özel izinleri veritabanında güncellendi."
        ]);
    } catch(Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Yetki güncelleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleKullaniciSil($pdo) {
    requirePermission('can_delete');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz Kullanıcı ID!'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);

        logAuditAction($pdo, 'KULLANICI_SILINDI', 'YONETIM_PANELI', "Süper Admin tarafından kullanıcı sistemden silindi (ID: #$id)");

        sendJsonResponse([
            'status' => 'success',
            'message' => "#$id Nolu kullanıcı veritabanından silindi."
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleLogout($pdo) {
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;
        $username = $input['username'] ?? 'Bilinmeyen';

        logAuditAction($pdo, 'LOGOUT', 'YONETIM_PANELI', "Kullanıcı: '$username' oturumu kapattı.");

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Oturum güvenli bir şekilde kapatıldı.'
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleVatandasSorgu($pdo) {
    try {
        $q = trim($_GET['q'] ?? '');
        $idParam = intval($_GET['id'] ?? 0);
        $mezarlikId = intval($_GET['mezarlik_id'] ?? 0);
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 20);
        $isAdmin = isset($_GET['admin_key']);

        if (!$isAdmin && $idParam <= 0 && mb_strlen($q, 'UTF-8') < 2) {
            sendJsonResponse([
                'status' => 'success',
                'count' => 0,
                'data' => [],
                'message' => 'Lütfen aramak için en az 2 karakter giriniz.'
            ]);
        }

        $concatExpr = "CONCAT(d.ad, ' ', d.soyad)";

        $sql = "SELECT d.*, COALESCE(m.ad, 'Yeni Mezarlık') as mezarlik_adi 
                FROM defin_kayitlari d
                LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id
                WHERE 1=1";
        $params = [];

        if (!$isAdmin) {
            $sql .= " AND d.onay_durumu = 'onaylandi'";
        }

        // Admin panelinden onay durumu filtresi
        $onayFiltre = trim($_GET['onay_durumu'] ?? '');
        if ($isAdmin && !empty($onayFiltre) && in_array($onayFiltre, ['bekliyor', 'onaylandi', 'reddedildi'])) {
            $sql .= " AND d.onay_durumu = :onay_filtre";
            $params[':onay_filtre'] = $onayFiltre;
        }

        if ($idParam > 0) {
            $sql .= " AND d.id = :id_param";
            $params[':id_param'] = $idParam;
        }

        if (!empty($q)) {
            // Türkçe harf dönüşümleri: İ->i, I->i, ı->i, Ü->u, Ö->o, Ş->s, Ç->c, Ğ->g
            $qClean = str_replace(
                ['İ', 'I', 'ı', 'Ü', 'ü', 'Ö', 'ö', 'Ş', 'ş', 'Ç', 'ç', 'Ğ', 'ğ'],
                ['i', 'i', 'i', 'u', 'u', 'o', 'o', 's', 's', 'c', 'c', 'g', 'g'],
                $q
            );
            $words = array_filter(explode(' ', mb_strtolower($qClean, 'UTF-8')));

            $i = 0;
            foreach ($words as $word) {
                $i++;
                $paramKey = ":q{$i}";

                // SQL tarafında tüm Türkçe karakterleri normalize et
                $trRepl = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(%s), 'İ', 'i'), 'I', 'i'), 'ı', 'i'), 'Ü', 'u'), 'Ö', 'o'), 'Ş', 's'), 'Ç', 'c'), 'Ğ', 'g')";
                $sqlAd = sprintf($trRepl, "d.ad");
                $sqlSoyad = sprintf($trRepl, "d.soyad");
                $sqlConcat = sprintf($trRepl, $concatExpr);

                $sql .= " AND (
                    {$sqlAd} LIKE {$paramKey} OR 
                    {$sqlSoyad} LIKE {$paramKey} OR 
                    {$sqlConcat} LIKE {$paramKey}
                )";
                $params[$paramKey] = '%' . $word . '%';
            }
        }

        if ($mezarlikId > 0) {
            $sql .= " AND d.mezarlik_id = :mezarlik_id";
            $params[':mezarlik_id'] = $mezarlikId;
        }

        // --- Pagination Logic ---
        // First count total records matching the criteria
        $countSql = "SELECT COUNT(*) FROM defin_kayitlari d LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id WHERE 1=1" . substr($sql, strpos($sql, "WHERE 1=1") + 9);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();

        $totalPages = ceil($totalRecords / $limit);
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        $sql .= " ORDER BY d.id DESC LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rawResults = $stmt->fetchAll();

        $results = [];
        foreach ($rawResults as $r) {
            $adaNo = $r['ada_no_text'] ?? '';
            $parselNo = $r['parsel_no_text'] ?? '';

            $results[] = [
                'id' => $r['id'],
                'ad' => $r['ad'],
                'soyad' => $r['soyad'],
                'tc_no' => $r['tc_no'] ?? null,
                'defin_tarihi' => formatDisplayDate($r['defin_tarihi'] ?? ''),
                'olum_tarihi' => formatDisplayDate($r['olum_tarihi'] ?? ''),
                'dogum_tarihi' => formatDisplayDate($r['dogum_tarihi'] ?? ''),
                'dogum_yeri' => $r['dogum_yeri'] ?? 'Çiftlikköy',
                'belge_no' => $r['belge_no'] ?? '',
                'defin_bolum' => $r['defin_bolum'] ?? 'SIRADAN',
                'baba_adi' => $r['baba_adi'] ?? '',
                'anne_adi' => $r['anne_adi'] ?? '',
                'mezarlik_id' => $r['mezarlik_id'] ?? 1,
                'ada_no' => $adaNo,
                'parsel_no' => $parselNo,
                'lat' => $r['lat'] ?? null,
                'lng' => $r['lng'] ?? null,
                'mezarlik_adi' => $r['mezarlik_adi'] ?? 'Yeni Mezarlık',
                'yakin_ad_soyad' => $r['yakin_ad_soyad'] ?? '',
                'yakin_akrabalik' => $r['yakin_akrabalik'] ?? 'YAKINI',
                'yakin_telefon' => $r['yakin_telefon'] ?? '',
                'yakin_adres' => $r['yakin_adres'] ?? '',
                'olum_nedeni' => $r['olum_nedeni'] ?? 'Doğal Ölüm',
                'gorsel_url' => $r['gorsel_url'] ?? null
            ];
        }

        if (!empty($q) && !$isAdmin) {
            logAuditAction($pdo, 'VATANDAS_SORGU', 'VATANDAS_PORTALI', "Arama kelimesi: '$q', Bulunan kayıt: " . count($results));
        }

        sendJsonResponse([
            'status' => 'success',
            'count' => count($results), // Count of current page
            'totalRecords' => $totalRecords,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'data' => $results
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Sorgulama hatası: ' . $e->getMessage()], 500);
    }
}

function handleMezarliklar($pdo) {
    try {
        $stmt = $pdo->query("SELECT m.id, m.ad, m.kod, m.aciklama, m.tarihce, (SELECT COUNT(id) FROM defin_kayitlari WHERE mezarlik_id = m.id) as defin_sayisi FROM mezarliklar m");
        sendJsonResponse(['status' => 'success', 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        error_log("Mezarliklar Query Error: " . $e->getMessage());
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleMezarlikEkle($pdo) {
    requirePermission('can_add');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $ad = trim($input['ad'] ?? '');
        $aciklama = trim($input['aciklama'] ?? '');
        $tarihce = trim($input['tarihce'] ?? '');
        $kapasite = $input['kapasite'] !== '' ? intval($input['kapasite']) : null;
        $alan_m2 = $input['alan_m2'] !== '' ? intval($input['alan_m2']) : null;
        
        if (empty($ad)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Mezarlık adı zorunludur.'], 400);
        }

        // Slugify / Create Kod
        $kod = strtolower(str_replace([' ', 'ı', 'ğ', 'ü', 'ş', 'ö', 'ç', 'I', 'Ğ', 'Ü', 'Ş', 'Ö', 'Ç'], ['-', 'i', 'g', 'u', 's', 'o', 'c', 'i', 'g', 'u', 's', 'o', 'c'], $ad));
        $kod = preg_replace('/[^a-z0-9\-]/', '', $kod);

        $stmt = $pdo->prepare("INSERT INTO mezarliklar (ad, kod, aciklama, tarihce, kapasite, alan_m2) VALUES (:ad, :kod, :aciklama, :tarihce, :kapasite, :alan)");
        $stmt->execute([
            ':ad' => $ad, 
            ':kod' => $kod, 
            ':aciklama' => $aciklama, 
            ':tarihce' => $tarihce,
            ':kapasite' => $kapasite,
            ':alan' => $alan_m2
        ]);

        logAuditAction($pdo, 'MEZARLIK_EKLE', 'YONETIM_PANELI', "Yeni mezarlık eklendi: $ad");
        sendJsonResponse(['status' => 'success', 'message' => 'Mezarlık başarıyla eklendi.']);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Ekleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleMezarlikDuzenle($pdo) {
    requirePermission('can_edit');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = intval($input['id'] ?? 0);
        $ad = trim($input['ad'] ?? '');
        $aciklama = trim($input['aciklama'] ?? '');
        $tarihce = trim($input['tarihce'] ?? '');
        $kapasite = $input['kapasite'] !== '' ? intval($input['kapasite']) : null;
        $alan_m2 = $input['alan_m2'] !== '' ? intval($input['alan_m2']) : null;
        
        if ($id <= 0 || empty($ad)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz ID veya Mezarlık adı.'], 400);
        }

        $stmt = $pdo->prepare("UPDATE mezarliklar SET ad = :ad, aciklama = :aciklama, tarihce = :tarihce, kapasite = :kapasite, alan_m2 = :alan WHERE id = :id");
        $stmt->execute([
            ':ad' => $ad, 
            ':aciklama' => $aciklama, 
            ':tarihce' => $tarihce, 
            ':kapasite' => $kapasite,
            ':alan' => $alan_m2,
            ':id' => $id
        ]);

        logAuditAction($pdo, 'MEZARLIK_DUZENLE', 'YONETIM_PANELI', "Mezarlık güncellendi: $ad");
        sendJsonResponse(['status' => 'success', 'message' => 'Mezarlık başarıyla güncellendi.']);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Düzenleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleMezarlikSil($pdo) {
    requirePermission('can_delete');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = intval($input['id'] ?? 0);

        if ($id <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz ID.'], 400);
        }

        // Check if there are graves in this cemetery
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM defin_kayitlari WHERE mezarlik_id = :id");
        $checkStmt->execute([':id' => $id]);
        $count = $checkStmt->fetchColumn();

        if ($count > 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Bu mezarlığa kayıtlı meftalar bulunduğu için silinemez. Önce kayıtları taşıyın veya silin.'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM mezarliklar WHERE id = :id");
        $stmt->execute([':id' => $id]);

        logAuditAction($pdo, 'MEZARLIK_SIL', 'YONETIM_PANELI', "Mezarlık silindi (ID: $id)");
        sendJsonResponse(['status' => 'success', 'message' => 'Mezarlık başarıyla silindi.']);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Silme hatası: ' . $e->getMessage()], 500);
    }
}

function handleHaritaVerisi($pdo) {
    try {
        $sql = "SELECT d.id, d.ad, d.soyad, d.lat, d.lng, d.ada_no_text as ada_no, d.parsel_no_text as parsel_no, m.ad as mezarlik_adi 
                FROM defin_kayitlari d 
                LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id 
                WHERE d.lat IS NOT NULL AND d.lng IS NOT NULL";
        $stmt = $pdo->query($sql);
        $results = $stmt->fetchAll();

        $features = [];
        foreach ($results as $row) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [ (float)$row['lng'], (float)$row['lat'] ]
                ],
                'properties' => [
                    'id' => $row['id'],
                    'ad_soyad' => $row['ad'] . ' ' . $row['soyad'],
                    'mezarlik_adi' => $row['mezarlik_adi'],
                    'ada' => $row['ada_no'],
                    'parsel' => $row['parsel_no']
                ]
            ];
        }

        sendJsonResponse([
            'type' => 'FeatureCollection',
            'features' => $features
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['type' => 'FeatureCollection', 'features' => []]);
    }
}

function handleDefinEkle($pdo) {
    requirePermission('can_add');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $adUpper = mb_strtoupper(trim($input['ad'] ?? ''), 'UTF-8');
        $soyadUpper = mb_strtoupper(trim($input['soyad'] ?? ''), 'UTF-8');
        $mezarlikId = intval($input['mezarlik_id'] ?? 0);
        $ignoreDup = isset($input['ignore_duplicate']) ? intval($input['ignore_duplicate']) : 0;

        if (empty($adUpper) || empty($soyadUpper)) {
            sendJsonResponse(['status' => 'error', 'message' => 'Lütfen meftanın adını ve soyadını giriniz!'], 400);
        }
        if ($mezarlikId <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Lütfen defin yapılacak mezarlığı seçiniz!'], 400);
        }

        // Seçilen mezarlığın veritabanında var olup olmadığını kontrol et (Silinmiş olabilir)
        $mCheck = $pdo->prepare("SELECT id FROM mezarliklar WHERE id = :id");
        $mCheck->execute([':id' => $mezarlikId]);
        if (!$mCheck->fetchColumn()) {
            sendJsonResponse(['status' => 'error', 'message' => 'Seçtiğiniz mezarlık sistemde bulunamadı (silinmiş olabilir). Lütfen sayfayı yenileyip tekrar deneyin.'], 400);
        }

        if ($ignoreDup !== 1) {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM defin_kayitlari WHERE ad = :a AND soyad = :s");
            $checkStmt->execute([':a' => $adUpper, ':s' => $soyadUpper]);
            $dupCount = $checkStmt->fetchColumn();

            if ($dupCount > 0) {
                sendJsonResponse([
                    'status' => 'duplicate_warning',
                    'count' => $dupCount,
                    'message' => "Veritabanında '$adUpper $soyadUpper' ismiyle zaten $dupCount adet kayıt mevcut! Yine de eklemek istiyor musunuz?"
                ], 409);
            }
        }

        $definTarihi = formatDbDate($input['defin_tarihi'] ?? date('Y-m-d'));
        $olumTarihi = formatDbDate($input['olum_tarihi'] ?? null);
        $dogumTarihi = formatDbDate($input['dogum_tarihi'] ?? null);

        $sql = "INSERT INTO defin_kayitlari 
                (tc_no, ad, soyad, baba_adi, anne_adi, dogum_tarihi, dogum_yeri, olum_tarihi, belge_no, defin_tarihi, defin_bolum, olum_nedeni, mezarlik_id, ada_no_text, parsel_no_text, lat, lng, yakin_ad_soyad, yakin_akrabalik, yakin_telefon, yakin_adres, aciklama, onay_durumu, gorsel_url) 
                VALUES 
                (:tc_no, :ad, :soyad, :baba_adi, :anne_adi, :dogum_tarihi, :dogum_yeri, :olum_tarihi, :belge_no, :defin_tarihi, :defin_bolum, :olum_nedeni, :mezarlik_id, :ada_no_t, :parsel_no_t, :lat, :lng, :yakin_ad_soyad, :yakin_akrabalik, :yakin_telefon, :yakin_adres, :aciklama, :onay_durumu, :gorsel_url)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tc_no' => $input['tc_no'] ?? null,
            ':ad' => mb_strtoupper($input['ad'], 'UTF-8'),
            ':soyad' => mb_strtoupper($input['soyad'], 'UTF-8'),
            ':baba_adi' => mb_strtoupper($input['baba_adi'] ?? '', 'UTF-8'),
            ':anne_adi' => mb_strtoupper($input['anne_adi'] ?? '', 'UTF-8'),
            ':dogum_tarihi' => $dogumTarihi,
            ':dogum_yeri' => $input['dogum_yeri'] ?? 'Çiftlikköy',
            ':olum_tarihi' => $olumTarihi,
            ':belge_no' => $input['belge_no'] ?? '',
            ':defin_tarihi' => $definTarihi,
            ':defin_bolum' => $input['defin_bolum'] ?? 'SIRADAN',
            ':olum_nedeni' => $input['olum_nedeni'] ?? 'Doğal Ölüm',
            ':mezarlik_id' => intval($input['mezarlik_id']),
            ':ada_no_t' => $input['ada_no'] ?? '',
            ':parsel_no_t' => $input['parsel_no'] ?? '',
            ':lat' => isset($input['lat']) ? floatval($input['lat']) : null,
            ':lng' => isset($input['lng']) ? floatval($input['lng']) : null,
            ':yakin_ad_soyad' => $input['yakin_ad_soyad'] ?? '',
            ':yakin_akrabalik' => $input['yakin_akrabalik'] ?? 'YAKINI',
            ':yakin_telefon' => $input['yakin_telefon'] ?? '',
            ':yakin_adres' => $input['yakin_adres'] ?? '',
            ':aciklama' => $input['aciklama'] ?? '',
            ':onay_durumu' => $input['onay_durumu'] ?? 'onaylandi',
            ':gorsel_url' => $input['gorsel_url'] ?? null
        ]);

        $newId = $pdo->lastInsertId();
        $fullName = mb_strtoupper($input['ad'] . ' ' . $input['soyad'], 'UTF-8');

        logAuditAction($pdo, 'KAYIT_EKLENDI', 'YONETIM_PANELI', "Yeni mefta kaydı eklendi (ID: #$newId, İsim: $fullName, Defin Tarihi: {$input['defin_tarihi']})");

        sendJsonResponse([
            'status' => 'success',
            'message' => 'Defin kaydı başarıyla eklendi.',
            'id' => $newId
        ], 201);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Kayıt ekleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleDefinGuncelle($pdo) {
    requirePermission('can_edit');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $id = intval($input['id'] ?? 0);
        $mezarlikId = intval($input['mezarlik_id'] ?? 0);
        
        if ($id <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz Kayıt ID!'], 400);
        }
        if ($mezarlikId <= 0) {
            sendJsonResponse(['status' => 'error', 'message' => 'Lütfen defin yapılacak mezarlığı seçiniz!'], 400);
        }

        // Seçilen mezarlığın veritabanında var olup olmadığını kontrol et (Silinmiş olabilir)
        $mCheck = $pdo->prepare("SELECT id FROM mezarliklar WHERE id = :id");
        $mCheck->execute([':id' => $mezarlikId]);
        if (!$mCheck->fetchColumn()) {
            sendJsonResponse(['status' => 'error', 'message' => 'Seçtiğiniz mezarlık sistemde bulunamadı (silinmiş olabilir). Lütfen sayfayı yenileyip tekrar deneyin.'], 400);
        }

        $definTarihi = formatDbDate($input['defin_tarihi'] ?? date('Y-m-d'));
        $olumTarihi = formatDbDate($input['olum_tarihi'] ?? null);
        $dogumTarihi = formatDbDate($input['dogum_tarihi'] ?? null);

        $sql = "UPDATE defin_kayitlari SET 
                ad = :ad,
                soyad = :soyad,
                tc_no = :tc_no,
                baba_adi = :baba_adi,
                anne_adi = :anne_adi,
                dogum_yeri = :dogum_yeri,
                dogum_tarihi = :dogum_tarihi,
                olum_tarihi = :olum_tarihi,
                belge_no = :belge_no,
                olum_nedeni = :olum_nedeni,
                mezarlik_id = :mezarlik_id,
                defin_bolum = :defin_bolum,
                ada_no_text = :ada_no,
                parsel_no_text = :parsel_no,
                defin_tarihi = :defin_tarihi,
                lat = :lat,
                lng = :lng,
                yakin_ad_soyad = :yakin_ad_soyad,
                yakin_akrabalik = :yakin_akrabalik,
                yakin_telefon = :yakin_telefon,
                yakin_adres = :yakin_adres,
                onay_durumu = COALESCE(:onay_durumu, onay_durumu),
                gorsel_url = :gorsel_url
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':ad' => mb_strtoupper($input['ad'], 'UTF-8'),
            ':soyad' => mb_strtoupper($input['soyad'], 'UTF-8'),
            ':tc_no' => $input['tc_no'] ?? null,
            ':baba_adi' => mb_strtoupper($input['baba_adi'] ?? '', 'UTF-8'),
            ':anne_adi' => mb_strtoupper($input['anne_adi'] ?? '', 'UTF-8'),
            ':dogum_yeri' => $input['dogum_yeri'] ?? 'Çiftlikköy',
            ':dogum_tarihi' => $dogumTarihi,
            ':olum_tarihi' => $olumTarihi,
            ':belge_no' => $input['belge_no'] ?? '',
            ':olum_nedeni' => $input['olum_nedeni'] ?? 'DOĞAL ÖLÜM',
            ':mezarlik_id' => intval($input['mezarlik_id']),
            ':defin_bolum' => $input['defin_bolum'] ?? 'SIRADAN',
            ':ada_no' => $input['ada_no'] ?? '',
            ':parsel_no' => $input['parsel_no'] ?? '',
            ':defin_tarihi' => $definTarihi,
            ':lat' => isset($input['lat']) && $input['lat'] !== '' ? floatval($input['lat']) : null,
            ':lng' => isset($input['lng']) && $input['lng'] !== '' ? floatval($input['lng']) : null,
            ':yakin_ad_soyad' => $input['yakin_ad_soyad'] ?? '',
            ':yakin_akrabalik' => $input['yakin_akrabalik'] ?? 'YAKINI',
            ':yakin_telefon' => $input['yakin_telefon'] ?? '',
            ':yakin_adres' => $input['yakin_adres'] ?? '',
            ':onay_durumu' => $input['onay_durumu'] ?? null,
            ':gorsel_url' => $input['gorsel_url'] ?? null
        ]);

        $fullName = mb_strtoupper($input['ad'] . ' ' . $input['soyad'], 'UTF-8');
        logAuditAction($pdo, 'KAYIT_GUNCELLENDI', 'YONETIM_PANELI', "Mefta kaydı güncellendi (ID: #$id, İsim: $fullName)");

        sendJsonResponse([
            'status' => 'success',
            'message' => "#$id Nolu Defin Kaydı Başarıyla Güncellendi."
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Güncelleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleDefinSil($pdo) {
    requirePermission('can_delete');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $id = intval($input['id'] ?? $_GET['id'] ?? 0);
        $idsStr = trim($input['ids'] ?? $_GET['ids'] ?? '');

        // Veritabanı Transaction Güvencesi
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if (!empty($idsStr)) {
            $ids = array_filter(array_map('intval', explode(',', $idsStr)));
            if (empty($ids)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                sendJsonResponse(['status' => 'error', 'message' => 'Silinecek geçerli kayıt ID bulunamadı!'], 400);
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM defin_kayitlari WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $deletedCount = $stmt->rowCount();

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            logAuditAction($pdo, 'TOPLU_DEFIN_SILINDI', 'YONETIM_PANELI', "Süper Admin tarafından toplu defin kaydı silindi. Silinen ID'ler: " . implode(', ', $ids) . " (Silinen miktar: $deletedCount)");

            sendJsonResponse([
                'status' => 'success',
                'deleted_count' => $deletedCount,
                'message' => "$deletedCount adet defin kaydı veritabanından kalıcı olarak silindi."
            ]);
        } else {
            if ($id <= 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz Silme ID!'], 400);
            }

            $stmtName = $pdo->prepare("SELECT ad, soyad FROM defin_kayitlari WHERE id = :id LIMIT 1");
            $stmtName->execute([':id' => $id]);
            $rec = $stmtName->fetch();
            $fullName = $rec ? mb_strtoupper($rec['ad'] . ' ' . $rec['soyad'], 'UTF-8') : "Bilinmeyen (ID: $id)";

            $stmt = $pdo->prepare("DELETE FROM defin_kayitlari WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $affected = $stmt->rowCount();
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($affected > 0) {
                logAuditAction($pdo, 'KAYIT_SILINDI', 'YONETIM_PANELI', "Mefta kaydı veritabanından kalıcı olarak silindi (ID: #$id, İsim: $fullName)");

                sendJsonResponse([
                    'status' => 'success',
                    'message' => "#$id Nolu Defin Kaydı Veritabanından Kalıcı Olarak Silindi."
                ]);
            } else {
                sendJsonResponse([
                    'status' => 'error',
                    'message' => "#$id Nolu Defin Kaydı Veritabanında Bulunamadı veya Zaten Silinmiş!"
                ], 404);
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJsonResponse(['status' => 'error', 'message' => 'Silme hatası: ' . $e->getMessage()], 500);
    }
}

function handleOnayGuncelle($pdo) {
    global $currentUser;
    if (!$currentUser || $currentUser['role'] !== 'superadmin') {
        sendJsonResponse(['status' => 'error', 'message' => 'Sadece Süper Admin kayıtları onaylayabilir!'], 403);
    }
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? $_POST;

        $durum = $input['onay_durumu'] ?? '';
        if (!in_array($durum, ['bekliyor', 'onaylandi', 'reddedildi'])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz onay durumu!'], 400);
        }

        // Toplu onay desteği
        $idsStr = trim($input['ids'] ?? '');
        $singleId = intval($input['id'] ?? 0);

        if (!empty($idsStr)) {
            $ids = array_filter(array_map('intval', explode(',', $idsStr)));
            if (empty($ids)) {
                sendJsonResponse(['status' => 'error', 'message' => 'Geçerli kayıt ID bulunamadı!'], 400);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE defin_kayitlari SET onay_durumu = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$durum], $ids));
            $affected = $stmt->rowCount();

            logAuditAction($pdo, 'TOPLU_ONAY_GUNCELLENDI', 'YONETIM_PANELI', "Toplu onay güncellendi: $affected kayıt '$durum' yapıldı (ID'ler: " . implode(', ', $ids) . ")");

            sendJsonResponse([
                'status' => 'success',
                'affected' => $affected,
                'message' => "$affected adet kayıt başarıyla güncellendi."
            ]);
        } elseif ($singleId > 0) {
            $stmt = $pdo->prepare("UPDATE defin_kayitlari SET onay_durumu = :durum WHERE id = :id");
            $stmt->execute([':durum' => $durum, ':id' => $singleId]);

            logAuditAction($pdo, 'ONAY_GUNCELLENDI', 'YONETIM_PANELI', "Mefta kaydı onay durumu '$durum' yapıldı (ID: #$singleId)");

            sendJsonResponse([
                'status' => 'success',
                'message' => "Kayıt durumu başarıyla güncellendi."
            ]);
        } else {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz parametreler!'], 400);
        }
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Onay güncelleme hatası: ' . $e->getMessage()], 500);
    }
}

function handleAuditLogs($pdo) {
    requirePermission('can_export');
    try {
        $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100");
        $results = $stmt->fetchAll();
        foreach ($results as &$r) {
            $rawDate = $r['created_at'] ?? '';
            $r['created_at'] = !empty($rawDate) ? date('d/m/Y H:i:s', strtotime($rawDate)) : date('d/m/Y H:i:s');
        }
        sendJsonResponse([
            'status' => 'success',
            'data' => $results
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleStats($pdo) {
    try {
        $totalDefin = $pdo->query("SELECT COUNT(*) FROM defin_kayitlari")->fetchColumn();
        $totalMezarlik = $pdo->query("SELECT COUNT(*) FROM mezarliklar")->fetchColumn();
        $latestDefin = $pdo->query("SELECT MAX(defin_tarihi) FROM defin_kayitlari")->fetchColumn();

        sendJsonResponse([
            'status' => 'success',
            'stats' => [
                'toplam_defin' => intval($totalDefin),
                'toplam_mezarlik' => intval($totalMezarlik),
                'son_defin_tarihi' => formatDisplayDate($latestDefin)
            ]
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
}

function handleRaporlar($pdo) {
    requirePermission('can_export');
    try {
        // 1. Genel KPI'lar
        $totalDefin = $pdo->query("SELECT COUNT(*) FROM defin_kayitlari")->fetchColumn();
        $totalMezarlik = $pdo->query("SELECT COUNT(*) FROM mezarliklar")->fetchColumn();
        $totalOnayBekleyen = $pdo->query("SELECT COUNT(*) FROM defin_kayitlari WHERE onay_durumu = 'bekliyor'")->fetchColumn();
        $totalOnaylanan = $pdo->query("SELECT COUNT(*) FROM defin_kayitlari WHERE onay_durumu = 'onaylandi'")->fetchColumn();
        $totalKonumlu = $pdo->query("SELECT COUNT(*) FROM defin_kayitlari WHERE lat IS NOT NULL AND lng IS NOT NULL")->fetchColumn();
        $totalGorselli = $pdo->query("SELECT COUNT(*) FROM defin_kayitlari WHERE gorsel_url IS NOT NULL AND gorsel_url != ''")->fetchColumn();

        // 2. Mezarlık Bazlı Dağılım
        $mezarlikDagilim = $pdo->query("
            SELECT m.id, m.ad as mezarlik_adi, 
                   COUNT(d.id) as toplam_defin,
                   SUM(CASE WHEN d.onay_durumu = 'onaylandi' THEN 1 ELSE 0 END) as onayli,
                   SUM(CASE WHEN d.onay_durumu = 'bekliyor' THEN 1 ELSE 0 END) as bekleyen,
                   SUM(CASE WHEN d.lat IS NOT NULL THEN 1 ELSE 0 END) as konumlu,
                   MIN(d.defin_tarihi) as ilk_defin,
                   MAX(d.defin_tarihi) as son_defin
            FROM mezarliklar m
            LEFT JOIN defin_kayitlari d ON d.mezarlik_id = m.id
            GROUP BY m.id, m.ad
            ORDER BY toplam_defin DESC
        ")->fetchAll();

        // 3. Yıllara Göre Defin Trendi
        $yillikTrend = $pdo->query("
            SELECT YEAR(defin_tarihi) as yil, COUNT(*) as sayi
            FROM defin_kayitlari
            WHERE defin_tarihi IS NOT NULL AND defin_tarihi != '0000-00-00'
            GROUP BY YEAR(defin_tarihi)
            ORDER BY yil ASC
        ")->fetchAll();

        // 4. Bu Yılın Aylık Dağılımı
        $currentYear = date('Y');
        $aylikDagilim = $pdo->prepare("
            SELECT MONTH(defin_tarihi) as ay, COUNT(*) as sayi
            FROM defin_kayitlari
            WHERE YEAR(defin_tarihi) = :yil AND defin_tarihi IS NOT NULL
            GROUP BY MONTH(defin_tarihi)
            ORDER BY ay ASC
        ");
        $aylikDagilim->execute([':yil' => $currentYear]);
        $aylikData = $aylikDagilim->fetchAll();

        // 5. Defin Bölüm Dağılımı
        $bolumDagilim = $pdo->query("
            SELECT COALESCE(defin_bolum, 'BELİRTİLMEMİŞ') as bolum, COUNT(*) as sayi
            FROM defin_kayitlari
            GROUP BY defin_bolum
            ORDER BY sayi DESC
        ")->fetchAll();

        // 6. Ölüm Nedeni Dağılımı
        $olumNedeniDagilim = $pdo->query("
            SELECT COALESCE(olum_nedeni, 'BELİRTİLMEMİŞ') as neden, COUNT(*) as sayi
            FROM defin_kayitlari
            GROUP BY olum_nedeni
            ORDER BY sayi DESC
            LIMIT 10
        ")->fetchAll();

        // 7. Son 30 Gün Aktivitesi
        $son30Gun = $pdo->query("
            SELECT DATE(created_at) as tarih, COUNT(*) as sayi
            FROM defin_kayitlari
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY tarih ASC
        ")->fetchAll();

        sendJsonResponse([
            'status' => 'success',
            'data' => [
                'kpilar' => [
                    'toplam_defin' => intval($totalDefin),
                    'toplam_mezarlik' => intval($totalMezarlik),
                    'onay_bekleyen' => intval($totalOnayBekleyen),
                    'onaylanan' => intval($totalOnaylanan),
                    'konumlu' => intval($totalKonumlu),
                    'gorselli' => intval($totalGorselli)
                ],
                'mezarlik_dagilim' => $mezarlikDagilim,
                'yillik_trend' => $yillikTrend,
                'aylik_dagilim' => $aylikData,
                'bolum_dagilim' => $bolumDagilim,
                'olum_nedeni_dagilim' => $olumNedeniDagilim,
                'son_30_gun' => $son30Gun,
                'rapor_tarihi' => date('d/m/Y H:i')
            ]
        ]);
    } catch (Exception $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Rapor hatası: ' . $e->getMessage()], 500);
    }
}

function handleTopluKayıtEkle($pdo) {
    requirePermission('can_add');
    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        
        if (empty($input['records']) || !is_array($input['records'])) {
            sendJsonResponse(['status' => 'error', 'message' => 'Geçersiz kayıt listesi!'], 400);
        }

        $records = $input['records'];
        $count = 0;
        
        $sql = "INSERT INTO defin_kayitlari 
                (tc_no, ad, soyad, baba_adi, anne_adi, dogum_tarihi, dogum_yeri, olum_tarihi, belge_no, defin_tarihi, defin_bolum, olum_nedeni, mezarlik_id, ada_no_text, parsel_no_text, lat, lng, yakin_ad_soyad, yakin_akrabalik, yakin_telefon, yakin_adres) 
                VALUES 
                (:tc_no, :ad, :soyad, :baba_adi, :anne_adi, :dogum_tarihi, :dogum_yeri, :olum_tarihi, :belge_no, :defin_tarihi, :defin_bolum, :olum_nedeni, :mezarlik_id, :ada_no, :parsel_no, :lat, :lng, :yakin_ad_soyad, :yakin_akrabalik, :yakin_telefon, :yakin_adres)";
        
        $stmt = $pdo->prepare($sql);
        
        // Geçerli mezarlık ID'lerini al
        $validMezarlikIds = $pdo->query("SELECT id FROM mezarliklar")->fetchAll(PDO::FETCH_COLUMN);
        
        $pdo->beginTransaction();
        foreach ($records as $r) {
            // Frontend'in cache'li olma ihtimaline karşı tüm olası anahtar isimlerini kontrol et
            $ad = $r['ad'] ?? $r['Ad (Zorunlu)'] ?? $r['ad (Zorunlu)'] ?? '';
            $soyad = $r['soyad'] ?? $r['Soyad (Zorunlu)'] ?? $r['soyad (Zorunlu)'] ?? '';
            $mezarlik_id = $r['mezarlik_id'] ?? $r['Mezarlık ID (Zorunlu)'] ?? $r['mezarlik_id (Zorunlu)'] ?? 0;
            
            if (empty($ad) || empty($soyad)) continue;
            
            $mId = intval($mezarlik_id);
            if ($mId <= 0) continue; // Skip records with invalid cemetery
            
            if (!in_array($mId, $validMezarlikIds)) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                sendJsonResponse(['status' => 'error', 'message' => "Yükleme Başarısız: '{$ad} {$soyad}' adlı kişinin Mezarlık ID'si ({$mId}) sisteminizde bulunamadı. Lütfen Sistemdeki geçerli bir mezarlık ID'sini yazın."], 400);
                exit;
            }
            
            $tc_no = $r['tc_no'] ?? $r['TC Kimlik No'] ?? null;
            $baba_adi = $r['baba_adi'] ?? $r['Baba Adı'] ?? '';
            $anne_adi = $r['anne_adi'] ?? $r['Anne Adı'] ?? '';
            $dogum_yeri = $r['dogum_yeri'] ?? $r['Doğum Yeri'] ?? 'Çiftlikköy';
            $belge_no = $r['belge_no'] ?? $r['Belge No'] ?? '';
            $defin_bolum = $r['defin_bolum'] ?? $r['Defin Bölüm'] ?? 'SIRADAN';
            $olum_nedeni = $r['olum_nedeni'] ?? $r['Ölüm Nedeni'] ?? 'Doğal Ölüm';
            $ada_no = $r['ada_no_text'] ?? $r['ada_no'] ?? $r['Ada No'] ?? '';
            $parsel_no = $r['parsel_no_text'] ?? $r['parsel_no'] ?? $r['Parsel No'] ?? '';
            
            $lat = $r['lat'] ?? null;
            $lng = $r['lng'] ?? null;
            if ($lat === '') $lat = null;
            if ($lng === '') $lng = null;
            
            $yakin_ad_soyad = $r['yakin_ad_soyad'] ?? $r['Yakın Ad Soyad'] ?? '';
            $yakin_akrabalik = $r['yakin_akrabalik'] ?? $r['Yakın Akrabalık'] ?? 'YAKINI';
            $yakin_telefon = $r['yakin_telefon'] ?? $r['Yakın Telefon'] ?? '';
            $yakin_adres = $r['yakin_adres'] ?? $r['Yakın Adres'] ?? '';
            
            $definTarihiRaw = $r['defin_tarihi'] ?? $r['Defin Tarihi'] ?? date('Y-m-d');
            $olumTarihiRaw = $r['olum_tarihi'] ?? $r['Ölüm Tarihi'] ?? null;
            $dogumTarihiRaw = $r['dogum_tarihi'] ?? $r['Doğum Tarihi'] ?? null;
            
            $definTarihi = formatDbDate($definTarihiRaw);
            $olumTarihi = formatDbDate($olumTarihiRaw);
            $dogumTarihi = formatDbDate($dogumTarihiRaw);

            $stmt->execute([
                ':tc_no' => $tc_no,
                ':ad' => mb_strtoupper($ad, 'UTF-8'),
                ':soyad' => mb_strtoupper($soyad, 'UTF-8'),
                ':baba_adi' => mb_strtoupper($baba_adi, 'UTF-8'),
                ':anne_adi' => mb_strtoupper($anne_adi, 'UTF-8'),
                ':dogum_tarihi' => $dogumTarihi,
                ':dogum_yeri' => $dogum_yeri,
                ':olum_tarihi' => $olumTarihi,
                ':belge_no' => $belge_no,
                ':defin_tarihi' => $definTarihi,
                ':defin_bolum' => $defin_bolum,
                ':olum_nedeni' => $olum_nedeni,
                ':mezarlik_id' => $mId,
                ':ada_no' => $ada_no,
                ':parsel_no' => $parsel_no,
                ':lat' => $lat !== null ? floatval($lat) : null,
                ':lng' => $lng !== null ? floatval($lng) : null,
                ':yakin_ad_soyad' => $yakin_ad_soyad,
                ':yakin_akrabalik' => $yakin_akrabalik,
                ':yakin_telefon' => $yakin_telefon,
                ':yakin_adres' => $yakin_adres
            ]);
            $count++;
        }
        $pdo->commit();

        logAuditAction($pdo, 'TOPLU_KAYIT_EKLENDI', 'YONETIM_PANELI', "Excel içe aktarımı ile toplam $count adet defin kaydı eklendi.");

        sendJsonResponse([
            'status' => 'success',
            'message' => "Excel dosyasındaki $count adet defin kaydı başarıyla sisteme aktarıldı."
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendJsonResponse(['status' => 'error', 'message' => 'Toplu kayıt ekleme hatası: ' . $e->getMessage()], 500);
    }
}

/**
 * Otomatik Dinamik QR Kod Üretici (Endroid QR Code PHP Kütüphanesi ile)
 */
function handleQrCode($pdo) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        exit('Gecersiz Kayit ID');
    }

    $stmt = $pdo->prepare("SELECT * FROM defin_kayitlari WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        http_response_code(404);
        exit('Kayit Bulunamadi');
    }

    // QR kod taratıldığında doğrudan mefta detay sayfasına yönlendirir (id ile)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qrData = "{$protocol}://{$host}/index.html?id={$id}";

    try {
        $qrCode = new \Endroid\QrCode\QrCode($qrData);
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);

        header('Content-Type: ' . $result->getMimeType());
        header('Cache-Control: public, max-age=86400');
        echo $result->getString();
        exit();
    } catch (\Exception $e) {
        http_response_code(500);
        exit('QR Kod Uretim Hatasi: ' . $e->getMessage());
    }
}

/**
 * Tekli veya Seçili Çoklu Kayıtlar İçin Yazdırılabilir QR Etiket PDF Oluşturucu (mPDF)
 */
function handlePrintQrPdf($pdo) {
    require_once __DIR__ . '/../vendor/autoload.php';

    $idsParam = $_GET['ids'] ?? '';
    if (empty($idsParam)) {
        http_response_code(400);
        exit('En az bir kayit ID belirtilmelidir.');
    }

    $idArray = array_filter(array_map('intval', explode(',', $idsParam)));
    if (empty($idArray)) {
        http_response_code(400);
        exit('Gecersiz kayit ID listesi.');
    }

    $inQuery = implode(',', array_fill(0, count($idArray), '?'));
    $stmt = $pdo->prepare("SELECT d.*, COALESCE(m.ad, 'Yeni Mezarlık') as mezarlik_adi FROM defin_kayitlari d LEFT JOIN mezarliklar m ON d.mezarlik_id = m.id WHERE d.id IN ($inQuery) ORDER BY d.id DESC");
    $stmt->execute($idArray);
    $records = $stmt->fetchAll();

    if (empty($records)) {
        http_response_code(404);
        exit('Yazdirilacak kayit bulunamadi.');
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 12,
        'margin_bottom' => 12
    ]);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = "{$protocol}://{$host}";

    $html = '
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1e293b; }
        .page-header { text-align: center; border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 15px; }
        .page-header h2 { color: #1e3a8a; margin: 0 0 4px 0; font-size: 16px; text-transform: uppercase; }
        .page-header p { color: #64748b; margin: 0; font-size: 10px; font-weight: bold; }
        .qr-grid { width: 100%; border-collapse: collapse; }
        .qr-card { width: 48%; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px; box-sizing: border-box; background: #ffffff; text-align: center; vertical-align: top; margin-bottom: 12px; }
        .qr-title { font-size: 13px; font-weight: bold; color: #1e3a8a; margin-bottom: 4px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
        .qr-info { font-size: 10px; color: #334155; margin-bottom: 6px; line-height: 1.3; }
        .qr-img { width: 110px; height: 110px; border: 1px solid #cbd5e1; padding: 3px; border-radius: 6px; }
        .qr-footer { font-size: 8px; color: #64748b; margin-top: 4px; font-style: italic; }
    </style>
    <div class="page-header">
        <h2>ÇİFTLİKKÖY BELEDİYESİ MEZARLIK BİLGİ SİSTEMİ</h2>
        <p>MEZAR YERİ VE KAREKOD (QR) ETIKET BASKI FORMU</p>
    </div>
    <table class="qr-grid">
    ';

    $count = 0;
    foreach ($records as $r) {
        if ($count % 2 == 0) {
            $html .= '<tr>';
        }

        // QR kodu doğrudan Endroid kütüphanesi ile base64 verisi olarak üret (index.html?id=ID yönlendirmeli)
        $qrData = "{$baseUrl}/index.html?id={$r['id']}";

        $qrCodeObj = new \Endroid\QrCode\QrCode($qrData);
        $writerObj = new \Endroid\QrCode\Writer\PngWriter();
        $resultObj = $writerObj->write($qrCodeObj);
        $qrBase64 = 'data:' . $resultObj->getMimeType() . ';base64,' . base64_encode($resultObj->getString());

        $definTarihi = !empty($r['defin_tarihi']) ? date('d.m.Y', strtotime($r['defin_tarihi'])) : '-';

        $html .= '
        <td class="qr-card">
            <div class="qr-title">' . htmlspecialchars($r['ad'] . ' ' . $r['soyad']) . '</div>
            <div class="qr-info">
                <b>Mezarlık:</b> ' . htmlspecialchars($r['mezarlik_adi']) . '<br>
                <b>Ada / Parsel:</b> Ada ' . htmlspecialchars($r['ada_no_text'] ?? '-') . ' / Parsel ' . htmlspecialchars($r['parsel_no_text'] ?? '-') . '<br>
                <b>Defin Tarihi:</b> ' . $definTarihi . ' | <b>Kayıt #:</b> ' . $r['id'] . '
            </div>
            <div>
                <img src="' . $qrBase64 . '" class="qr-img">
            </div>
            <div class="qr-footer">Akıllı telefon ile tara & navigasyon başlat</div>
        </td>
        ';

        if ($count % 2 == 1) {
            $html .= '</tr>';
        }
        $count++;
    }

    if ($count % 2 != 0) {
        $html .= '<td style="width:48%;"></td></tr>';
    }

    $html .= '</table>';

    $mpdf->WriteHTML($html);
    $mpdf->Output('MBS_QR_Etiketleri.pdf', 'I');
    exit();
}

/**
 * 📸 MEZAR TAŞI GÖRSELİ YÜKLEME VE OTOMATİK WEBP SIKIŞTIRMA (GD)
 */
function handleUploadGorsel($pdo) {
    if (!isset($_FILES['gorsel'])) {
        sendJsonResponse(['status' => 'error', 'message' => 'Lütfen geçerli bir görsel dosyası seçiniz (Dosya sunucuya ulaşmadı).'], 400);
    }
    if ($_FILES['gorsel']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['gorsel']['error'];
        $msg = 'Dosya yükleme hatası. (Hata Kodu: ' . $err . ')';
        if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE) {
            $msg = 'Seçtiğiniz fotoğraf çok büyük! (Sunucu boyut limitini aşıyor). Lütfen boyutunu küçültüp tekrar deneyin.';
        }
        sendJsonResponse(['status' => 'error', 'message' => $msg], 400);
    }

    $file = $_FILES['gorsel'];
    $tmpPath = $file['tmp_name'];
    $info = getimagesize($tmpPath);

    if (!$info) {
        sendJsonResponse(['status' => 'error', 'message' => 'Yüklenen dosya geçerli bir resim formatında değil.'], 400);
    }

    $mime = $info['mime'];
    $img = null;
    switch ($mime) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $img = @imagecreatefrompng($tmpPath);
            break;
        case 'image/webp':
            $img = @imagecreatefromwebp($tmpPath);
            break;
    }

    if (!$img) {
        sendJsonResponse(['status' => 'error', 'message' => 'Resim işlenirken sunucu hatası oluştu. Desteklenen türler: JPG, PNG, WEBP'], 400);
    }

    // Orijinal boyutlar
    $origWidth = imagesx($img);
    $origHeight = imagesy($img);
    $maxDim = 1000; // Maksimum 1000px genişlik/yükseklik

    if ($origWidth > $maxDim || $origHeight > $maxDim) {
        if ($origWidth >= $origHeight) {
            $newWidth = $maxDim;
            $newHeight = (int)($origHeight * ($maxDim / $origWidth));
        } else {
            $newHeight = $maxDim;
            $newWidth = (int)($origWidth * ($maxDim / $origHeight));
        }

        $newImg = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($newImg, false);
            imagesavealpha($newImg, true);
            $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
            imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($img);
        $img = $newImg;
    }

    $uploadDir = __DIR__ . '/../uploads/mezarlar/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = 'mezar_' . time() . '_' . uniqid() . '.webp';
    $targetPath = $uploadDir . $fileName;

    // WebP %80 kalite oranı ile ultra hafif kaydetme
    if (imagewebp($img, $targetPath, 80)) {
        imagedestroy($img);
        $fileUrl = 'uploads/mezarlar/' . $fileName;
        $fileSizeKb = round(filesize($targetPath) / 1024, 1);

        logAuditAction($pdo, 'GÖRSEL_YÜKLENDİ', 'YONETIM_PANELI', "Mezar taşı görseli başarıyla WebP formatına dönüştürüldü ({$fileName}, {$fileSizeKb} KB)");

        sendJsonResponse([
            'status' => 'success',
            'message' => "Görsel başarıyla WebP formatına dönüştürüldü ({$fileSizeKb} KB).",
            'url' => $fileUrl,
            'size_kb' => $fileSizeKb
        ]);
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'WebP resmi oluşturulurken hata meydana geldi.'], 500);
    }
}

/**
 * 📸 KIOSK REKLAM VE LOGO YÜKLEME (ORİJİNAL FORMAT KORUNUR)
 */
function handleUploadGeneric($pdo, $folderName) {
    if (!isset($_FILES['gorsel'])) {
        sendJsonResponse(['status' => 'error', 'message' => 'Lütfen geçerli bir dosya seçiniz.'], 400);
    }
    if ($_FILES['gorsel']['error'] !== UPLOAD_ERR_OK) {
        sendJsonResponse(['status' => 'error', 'message' => 'Dosya yükleme hatası. Hata kodu: ' . $_FILES['gorsel']['error']], 400);
    }

    $file = $_FILES['gorsel'];
    $tmpPath = $file['tmp_name'];
    $info = getimagesize($tmpPath);

    if (!$info && mime_content_type($tmpPath) !== 'image/svg+xml') {
        sendJsonResponse(['status' => 'error', 'message' => 'Yüklenen dosya geçerli bir resim değil.'], 400);
    }

    $mime = mime_content_type($tmpPath);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/svg'];
    if (!in_array($mime, $allowedMimes)) {
        sendJsonResponse(['status' => 'error', 'message' => 'Desteklenmeyen dosya formatı: ' . $mime], 400);
    }

    $ext = 'jpg';
    if ($mime == 'image/png') $ext = 'png';
    if ($mime == 'image/gif') $ext = 'gif';
    if ($mime == 'image/webp') $ext = 'webp';
    if (strpos($mime, 'svg') !== false) $ext = 'svg';

    $uploadDir = __DIR__ . "/../uploads/{$folderName}/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filename = uniqid() . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($tmpPath, $targetPath)) {
        $url = "uploads/{$folderName}/{$filename}";
        sendJsonResponse(['status' => 'success', 'url' => $url]);
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Dosya kaydedilemedi.'], 500);
    }
}

function handleDownloadExcelTemplate() {
    $filename = "MBS_Toplu_Kayit_Sablonu.csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel to read characters correctly
    fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));

    $headers = [
        "ad (Zorunlu)", "soyad (Zorunlu)", "tc_no", "baba_adi", "anne_adi",
        "dogum_tarihi", "dogum_yeri", "olum_tarihi", "olum_nedeni", "defin_tarihi",
        "defin_bolum", "mezarlik_id (Zorunlu)", "ada_no", "parsel_no", "belge_no",
        "yakin_ad_soyad", "yakin_akrabalik", "yakin_telefon", "yakin_adres"
    ];
    
    fputcsv($output, $headers, ';');
    
    $exampleRow = [
        "ÖRNEK", "KİŞİ", "11111111111", "AHMET", "AYŞE",
        "01.01.1950", "YALOVA", "15.06.2023", "DOĞAL ÖLÜM", "16.06.2023",
        "SIRADAN", "1", "12", "45", "BLG-12345",
        "MEHMET KİŞİ", "OĞLU", "05551112233", "Örnek Mah. Test Sok. No:1"
    ];
    
    fputcsv($output, $exampleRow, ';');
    fclose($output);
    exit();
}
