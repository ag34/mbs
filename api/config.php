<?php
/**
 * Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS)
 * API Veritabanı ve Genel Konfigürasyon
 */

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-MBS-User, X-MBS-Name, X-MBS-Role, X-MBS-Token");

// Cache engelleyici header'lar — API yanıtları hiçbir zaman cache'lenmemeli
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ====================================================================
// VERİTABANI AYARLARI
// ====================================================================
define('DB_TYPE', 'mysql'); 

define('MYSQL_HOST', '127.0.0.1'); 
define('MYSQL_DB',   'epikmedya_mbs');
define('MYSQL_USER', 'epikmedya_admin');
define('MYSQL_PASS', 'Jamiryo123.');

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            if (DB_TYPE === 'sqlite') {
                $dbPath = __DIR__ . '/../db/mbs_database.sqlite';
                $pdo = new PDO("sqlite:" . $dbPath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
                $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key VARCHAR(100) PRIMARY KEY,
                    setting_value TEXT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
            } else {
                $dsn = "mysql:host=" . MYSQL_HOST . ";dbname=" . MYSQL_DB . ";charset=utf8mb4";
                $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 2,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]);
            }
            initMySqlDb($pdo);
        } catch (PDOException $e) {
            sendJsonResponse(['status' => 'error', 'message' => 'Veritabanı bağlantı hatası: ' . $e->getMessage()], 500);
        }
    }
    return $pdo;
}

function initMySqlDb($pdo) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // 1. Create system_settings if not exists
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Seed default settings on MySQL
    $defaultSettings = [
        ['logo_url', 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg'],
        ['birim_sorumlusu_ad', 'Ali KAPLAN'],
        ['birim_sorumlusu_unvan', 'Park ve Bahçeler Müd. V.'],
        ['mezarlik_sorumlusu_ad', 'Bedri DEMİREL'],
        ['mezarlik_sorumlusu_unvan', 'Mezarlık Birim Sorumlusu'],
        ['belediye_adi', 'T.C. ÇİFTLİKKÖY BELEDİYESİ BAŞKANLIĞI'],
        ['mudurluk_adi', 'PARK VE BAHÇELER MÜDÜRLÜĞÜ'],
        ['ip_restriction_enabled', '0'],
        ['allowed_ips', ''],
        ['vatansms_api_id', ''],
        ['vatansms_api_key', ''],
        ['vatansms_sender', '']
    ];
    
    if ($driver === 'sqlite') {
        $stmtSet = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    } else {
        $stmtSet = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    }
    
    foreach ($defaultSettings as $s) {
        $stmtSet->execute($s);
    }

    // 2. Alter columns for defin_kayitlari if they are missing
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN ada_no_text VARCHAR(100) NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN parsel_no_text VARCHAR(100) NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN dogum_yeri VARCHAR(150) DEFAULT 'Çiftlikköy'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN belge_no VARCHAR(100) NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN defin_bolum VARCHAR(50) DEFAULT 'SIRADAN'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN yakin_akrabalik VARCHAR(100) DEFAULT 'YAKINI'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN yakin_adres TEXT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE defin_kayitlari ADD COLUMN gorsel_url VARCHAR(255) NULL"); } catch(Exception $e) {}

    // Alter columns for mezarliklar
    try { $pdo->exec("ALTER TABLE mezarliklar ADD COLUMN aciklama TEXT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE mezarliklar ADD COLUMN tarihce TEXT NULL"); } catch(Exception $e) {}
        
    // 3. Alter columns for audit_logs
    try { $pdo->exec("ALTER TABLE audit_logs ADD COLUMN ip_adresi VARCHAR(50) DEFAULT '127.0.0.1'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE audit_logs ADD COLUMN kullanici VARCHAR(150) DEFAULT 'Sistem'"); } catch(Exception $e) {}

    // 4. Alter columns for users (Kullanıcı Bazlı Özel İzinler)
    try { $pdo->exec("ALTER TABLE users ADD COLUMN eposta VARCHAR(150) NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN telefon VARCHAR(20) NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN otp_expires_at DATETIME NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN can_delete INT DEFAULT 0"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN can_edit INT DEFAULT 1"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN can_add INT DEFAULT 1"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN can_export INT DEFAULT 1"); } catch(Exception $e) {}
    try { $pdo->exec("UPDATE users SET can_delete = 1, can_edit = 1, can_add = 1, can_export = 1 WHERE role = 'superadmin'"); } catch(Exception $e) {}

    // 5. Gorsel URL Migration to JSON array
    try { $pdo->exec("ALTER TABLE defin_kayitlari MODIFY COLUMN gorsel_url TEXT NULL"); } catch(Exception $e) {}
    try {
        $stmt = $pdo->query("SELECT id, gorsel_url FROM defin_kayitlari WHERE gorsel_url IS NOT NULL AND gorsel_url != ''");
        if ($stmt) {
            $records = $stmt->fetchAll();
            $updateStmt = $pdo->prepare("UPDATE defin_kayitlari SET gorsel_url = :new_url WHERE id = :id");
            foreach($records as $rec) {
                $val = trim($rec['gorsel_url']);
                // Check if it's already a JSON array
                if (strpos($val, '[') !== 0) {
                    $newVal = json_encode([$val], JSON_UNESCAPED_UNICODE);
                    $updateStmt->execute([':new_url' => $newVal, ':id' => $rec['id']]);
                }
            }
        }
    } catch(Exception $e) {}
}

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}
