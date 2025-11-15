<?php
// CLI CSV importer for users: php import_users_cli.php "C:\\path\\to\\Indian-Name.csv"
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/db.php';

function slugify($str): string {
    $s = strtolower(trim((string)$str));
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'user';
}

function gen_email(string $name, int $idx = 0): string {
    $slug = slugify($name);
    $suffix = $idx > 0 ? ('.' . $idx) : '';
    return $slug . $suffix . '@nostrv.com';
}

function gen_phone(int $seed = 0): string {
    $base = 9000000000;
    $num = $base + ($seed % 999999);
    return (string)$num;
}

// Parse args
$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "Usage: php import_users_cli.php \"C:\\path\\to\\Indian-Name.csv\"\n");
    exit(1);
}

if (!file_exists($path)) {
    fwrite(STDERR, "CSV not found: {$path}\n");
    exit(1);
}

$fp = fopen($path, 'r');
if (!$fp) {
    fwrite(STDERR, "Unable to open CSV: {$path}\n");
    exit(1);
}

$header = fgetcsv($fp);
if (!$header) {
    fwrite(STDERR, "CSV is empty: {$path}\n");
    exit(1);
}

// Build header map (lowercased keys)
$map = [];
foreach ($header as $i => $col) {
    $key = strtolower(trim((string)$col));
    $map[$key] = $i;
}

$idxName = $map['name'] ?? ($map['full name'] ?? null);
$idxFirst = $map['first name'] ?? ($map['first'] ?? null);
$idxLast = $map['last name'] ?? ($map['last'] ?? null);
$idxEmail = $map['email'] ?? null;
$idxPhone = $map['phone'] ?? ($map['mobile'] ?? ($map['contact'] ?? null));
$idxAddress = $map['address'] ?? ($map['street'] ?? ($map['location'] ?? null));
$idxUsername = $map['username'] ?? null;

$inserted = 0;
$skipped = 0;
$details = [];
$seenEmails = [];
$now = gmdate('Y-m-d H:i:s');
$stmt = $pdo->prepare('INSERT INTO users (username, email, phone, address, password_hash, created_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address)');

$rowNum = 1; // header line accounted for
while (($row = fgetcsv($fp)) !== false) {
    $rowNum++;
    $first = ($idxFirst !== null && isset($row[$idxFirst])) ? trim((string)$row[$idxFirst]) : '';
    $last = ($idxLast !== null && isset($row[$idxLast])) ? trim((string)$row[$idxLast]) : '';
    $name = ($idxName !== null && isset($row[$idxName])) ? trim((string)$row[$idxName]) : '';
    if ($name === '') $name = trim(($first . ' ' . $last));

    $username = ($idxUsername !== null && isset($row[$idxUsername])) ? trim((string)$row[$idxUsername]) : '';
    if ($username === '') $username = $name ?: ($first ?: 'User');

    $email = ($idxEmail !== null && isset($row[$idxEmail])) ? strtolower(trim((string)$row[$idxEmail])) : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $i = 0; $candidate = '';
        do { $candidate = gen_email($username, $i); $i++; } while (isset($seenEmails[$candidate]));
        $email = $candidate;
    }

    $seenEmails[$email] = true;
    $phone = ($idxPhone !== null && isset($row[$idxPhone])) ? preg_replace('/\D+/', '', (string)$row[$idxPhone]) : '';
    if ($phone === '') $phone = gen_phone($rowNum);
    $address = ($idxAddress !== null && isset($row[$idxAddress])) ? trim((string)$row[$idxAddress]) : '';
    $passwordHash = password_hash('demo1234', PASSWORD_DEFAULT);

    try {
        $stmt->execute([$username, $email, $phone, $address, $passwordHash, $now]);
        $affected = $stmt->rowCount();
        if ($affected === 1) { $inserted++; } else { $skipped++; }
    } catch (Throwable $e) {
        $details[] = "Row {$rowNum} failed: " . $e->getMessage();
        $skipped++;
    }
}
fclose($fp);

echo "Import complete: inserted {$inserted}, skipped {$skipped}.\n";
if (!empty($details)) {
    foreach ($details as $d) echo $d . "\n";
}