<?php
require_once __DIR__ . '/api/config.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT id, username, password FROM users");
while ($row = $stmt->fetch()) {
    if (strlen($row['password']) !== 128) {
        $hashed = hash('sha512', $row['password']);
        $update = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
        $update->execute([':p' => $hashed, ':id' => $row['id']]);
        echo "Updated user {$row['username']}\n";
    }
}
echo "Done.\n";
