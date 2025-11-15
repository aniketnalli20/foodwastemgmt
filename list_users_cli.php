<?php
require_once __DIR__ . '/app.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line.\n");
    exit(1);
}

$limit = isset($argv[1]) ? (int)$argv[1] : 10;
if ($limit <= 0) $limit = 10;

try {
    $stmt = $pdo->prepare('SELECT id, username, email, phone, address FROM users ORDER BY id ASC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        echo "No users found.\n";
        exit(0);
    }
    echo "Showing {$limit} users (password: demo1234 unless changed):\n";
    foreach ($rows as $u) {
        echo 'id=' . (int)$u['id'] . ', username=' . (string)$u['username'] . ', email=' . (string)$u['email'] . ', phone=' . (string)($u['phone'] ?? '') . ', address=' . (string)($u['address'] ?? '') . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to list users: ' . $e->getMessage() . "\n");
    exit(1);
}