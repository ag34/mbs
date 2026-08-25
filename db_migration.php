<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/api/db.php'; // DB connection

try {
    // Add can_approve to users
    $pdo->exec("ALTER TABLE users ADD COLUMN can_approve INT DEFAULT 0;");
    echo "Added can_approve column.\n";
} catch (Exception $e) {
    echo "Error adding can_approve: " . $e->getMessage() . "\n";
}

try {
    // Insert SMTP settings
    $settings = [
        ['smtp_host', 'mail.example.com'],
        ['smtp_port', '587'],
        ['smtp_user', 'no-reply@example.com'],
        ['smtp_pass', 'password'],
        ['smtp_secure', 'tls'],
        ['2fa_method', 'sms'] // sms, email, both
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $s) {
        $stmt->execute([$s[0], $s[1]]);
    }
    echo "Added SMTP settings.\n";

} catch (Exception $e) {
    echo "Error inserting SMTP settings: " . $e->getMessage() . "\n";
}
?>
