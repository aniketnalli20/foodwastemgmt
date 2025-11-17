<?php
// Common bootstrap and helpers
session_start();

require_once __DIR__ . '/db.php';

// Compute base path dynamically so links work from a subfolder (e.g., /No%20starve/) or as a vhost root
$docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
$appDir = str_replace('\\', '/', rtrim(__DIR__, '/'));
$basePath = '/';
if ($docRoot !== '' && strncmp($appDir, $docRoot, strlen($docRoot)) === 0) {
    $rel = substr($appDir, strlen($docRoot));
    $basePath = $rel !== '' ? $rel : '/';
}
$BASE_PATH = rtrim(str_replace('\\', '/', $basePath), '/') . '/';

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        global $BASE_PATH;
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $next = '';
        if ($script) {
            // Reduce to basename to avoid leaking directories beyond app scope
            $base = basename($script);
            $next = $base;
            if ($query) {
                $next .= '?' . $query;
            }
        }
        $location = $BASE_PATH . 'login.php' . ($next ? ('?next=' . urlencode($next)) : '');
        header('Location: ' . $location);
        exit;
    }
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    global $pdo;
    $stmt = $pdo->prepare('SELECT id, username, email, phone, address, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function time_ago($datetime): string {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

/**
 * Append a simple record of a user entry to a text file.
 * Source can be 'register', 'import', etc.
 */
function log_user_entry(string $username, string $email, ?string $phone, ?string $address, string $source = 'register'): void {
    try {
        $path = __DIR__ . '/uploads/user_entries.txt';
        $date = gmdate('c');
        $line = $date
            . ' | ' . $source
            . ' | username=' . str_replace(["\r","\n"], '', (string)$username)
            . ' | email=' . str_replace(["\r","\n"], '', (string)$email)
            . ' | phone=' . str_replace(["\r","\n"], '', (string)($phone ?? ''))
            . ' | address=' . str_replace(["\r","\n"], '', (string)($address ?? ''));
        file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Swallow logging errors; never block user operations
    }
}

/**
 * Register a new user and return the inserted user ID.
 * Validates username, email, and password; optional phone and address.
 */
function register_user(string $username, string $email, string $password, ?string $phone = null, ?string $address = null): int {
    global $pdo;

    $username = trim($username);
    $email = strtolower(trim($email));
    $password = (string)$password;
    $phone = $phone !== null ? trim($phone) : null;
    $address = $address !== null ? trim($address) : null;

    if ($username === '') {
        throw new InvalidArgumentException('Username is required');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Valid email is required');
    }
    if (strlen($password) < 6) {
        throw new InvalidArgumentException('Password must be at least 6 characters long');
    }
    if ($phone !== null && $phone !== '' && !preg_match('/^[0-9+\-\s]{7,30}$/', $phone)) {
        throw new InvalidArgumentException('Phone must be 7–30 characters using digits, +, -, spaces');
    }
    if ($address !== null && strlen($address) > 5000) {
        throw new InvalidArgumentException('Address is too long');
    }

    // Ensure email is not already registered
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        throw new InvalidArgumentException('Email is already registered');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO users (username, email, phone, address, password_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$username, $email, ($phone !== '' ? $phone : null), ($address !== '' ? $address : null), $hash, $now]);
    // Log the entry to a separate text file
    log_user_entry($username, $email, ($phone !== '' ? $phone : null), ($address !== '' ? $address : null), 'register');
    // Ensure contributor record exists immediately for this username
    try { set_contributor_verified((string)$username, 0); } catch (Throwable $e) {}

    return (int)$pdo->lastInsertId();
}

/**
 * Create a campaign record.
 * Required: title, summary.
 * Optional: area, target_meals (int), start_date (YYYY-MM-DD), end_date (YYYY-MM-DD), status.
 * Returns inserted campaign ID.
 */
function create_campaign(array $data, ?array $imageFile = null): int {
    global $pdo;

    $contributorName = trim((string)($data['contributor_name'] ?? ''));
    $location = trim((string)($data['location'] ?? ''));
    $crowdSize = isset($data['crowd_size']) && $data['crowd_size'] !== '' ? (int)$data['crowd_size'] : null;
    $closingTime = isset($data['closing_time']) ? trim((string)$data['closing_time']) : null;

    if ($contributorName === '' || $location === '' || $crowdSize === null || $closingTime === null) {
        throw new InvalidArgumentException('contributor_name, location, crowd_size, and closing_time are required');
    }

    // Default title should reflect the username directly, not "Campaign by ..."
    $title = trim((string)($data['title'] ?? $contributorName));
    // Do not inject a default summary; leave empty unless provided
    $summary = trim((string)($data['summary'] ?? ''));

    $area = isset($data['area']) ? trim((string)$data['area']) : $location;
    $targetMeals = isset($data['target_meals']) && $data['target_meals'] !== '' ? (int)$data['target_meals'] : null;
    $startDate = isset($data['start_date']) ? trim((string)$data['start_date']) : null;
    $endDate = isset($data['end_date']) ? trim((string)$data['end_date']) : null;
    // Default to 'open' so new campaigns show up immediately in the feed
    $status = isset($data['status']) && $data['status'] !== '' ? trim((string)$data['status']) : 'open';

    $imageUrl = isset($data['image_url']) ? trim((string)$data['image_url']) : null;
    if (!$imageUrl && $imageFile && isset($imageFile['tmp_name']) && is_uploaded_file($imageFile['tmp_name'])) {
        $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0777, true);
        }
        $ext = strtolower(pathinfo($imageFile['name'] ?? '', PATHINFO_EXTENSION));
        $fname = 'campaign_' . uniqid('', true) . ($ext ? ('.' . $ext) : '');
        $dest = $uploadsDir . DIRECTORY_SEPARATOR . $fname;
        if (!move_uploaded_file($imageFile['tmp_name'], $dest)) {
            throw new RuntimeException('failed to upload image');
        }
        $imageUrl = 'uploads/' . $fname;
    }

    $latitude = isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null;
    $longitude = isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null;

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $stmt = $pdo->prepare('INSERT INTO campaigns (title, summary, area, target_meals, start_date, end_date, status, created_at, contributor_name, location, crowd_size, image_url, closing_time, latitude, longitude, user_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $title,
        $summary,
        $area,
        $targetMeals,
        $startDate,
        $endDate,
        $status,
        gmdate('Y-m-d H:i:s'),
        $contributorName,
        $location,
        $crowdSize,
        $imageUrl,
        $closingTime,
        $latitude,
        $longitude,
        $userId,
    ]);

    return (int)$pdo->lastInsertId();
}

function get_karma_balance(int $userId): int {
    global $pdo;
    $stmt = $pdo->prepare('SELECT balance FROM karma_wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['balance'] : 0;
}

function award_karma_coins(int $userId, int $amount, ?string $reason = null, ?string $refType = null, ?int $refId = null): int {
    global $pdo, $DB_DRIVER;
    if ($userId <= 0) throw new InvalidArgumentException('Invalid user');
    if ($amount <= 0) throw new InvalidArgumentException('Amount must be positive');
    $pdo->beginTransaction();
    try {
        $now = ($DB_DRIVER === 'pgsql') ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');
        if ($DB_DRIVER === 'pgsql') {
            $pdo->prepare('INSERT INTO karma_wallets (user_id, balance, updated_at) VALUES (?, ?, ?)
                           ON CONFLICT (user_id) DO UPDATE SET balance = karma_wallets.balance + EXCLUDED.balance, updated_at = EXCLUDED.updated_at')
                ->execute([$userId, $amount, $now]);
        } else {
            $pdo->prepare('INSERT INTO karma_wallets (user_id, balance, updated_at) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = VALUES(updated_at)')
                ->execute([$userId, $amount, $now]);
        }
        $pdo->prepare('INSERT INTO karma_events (user_id, amount, reason, ref_type, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $amount, ($reason !== '' ? $reason : null), ($refType !== '' ? $refType : null), ($refId ?: null), $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return get_karma_balance($userId);
}

// Admin helpers
function is_admin(): bool {
    return isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
}

function require_admin(): void {
    if (!is_logged_in() || !is_admin()) {
        global $BASE_PATH;
        $location = $BASE_PATH . 'login.php?next=' . urlencode('admin/index.php');
        header('Location: ' . $location);
        exit;
    }
}

// Conversion: 1000 coins = 10 paisa
function convert_coins_to_paisa(int $coins): int {
    if ($coins <= 0) return 0;
    return intdiv($coins, 1000) * 10;
}

// Redemption allowed only at 10 lakh (1,000,000) coins
function can_redeem(int $coins): bool {
    return $coins >= 1000000;
}

// Debit coins from wallet (records negative event)
function debit_karma_coins(int $userId, int $amount, ?string $reason = 'redeem'): int {
    global $pdo, $DB_DRIVER;
    if ($userId <= 0) throw new InvalidArgumentException('Invalid user');
    if ($amount <= 0) throw new InvalidArgumentException('Amount must be positive');
    $current = get_karma_balance($userId);
    if ($current < $amount) throw new InvalidArgumentException('Insufficient coins');
    $pdo->beginTransaction();
    try {
        $now = gmdate('Y-m-d H:i:s');
        if ($DB_DRIVER === 'pgsql') {
            $pdo->prepare('INSERT INTO karma_wallets (user_id, balance, updated_at) VALUES (?, ?, ?)
                           ON CONFLICT (user_id) DO UPDATE SET balance = karma_wallets.balance - EXCLUDED.balance, updated_at = EXCLUDED.updated_at')
                ->execute([$userId, $amount, $now]);
        } else {
            // Use UPDATE to subtract to avoid odd ON DUPLICATE arithmetic
            $pdo->prepare('UPDATE karma_wallets SET balance = balance - ?, updated_at = ? WHERE user_id = ?')
                ->execute([$amount, $now, $userId]);
        }
        $pdo->prepare('INSERT INTO karma_events (user_id, amount, reason, ref_type, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, -$amount, ($reason !== '' ? $reason : null), 'redeem', $userId, $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return get_karma_balance($userId);
}

// Redeem coins to cash (paisa); returns details
function redeem_karma_to_cash(int $userId): array {
    $balance = get_karma_balance($userId);
    if (!can_redeem($balance)) {
        return ['ok' => false, 'error' => 'threshold', 'balance' => $balance, 'paisa' => convert_coins_to_paisa($balance)];
    }
    // Redeem full balance to cash by blocks
    $redeemCoins = $balance - ($balance % 1000); // full blocks of 1000
    $paisa = convert_coins_to_paisa($redeemCoins);
    debit_karma_coins($userId, $redeemCoins, 'redeem');
    return ['ok' => true, 'coins' => $redeemCoins, 'paisa' => $paisa, 'balance' => get_karma_balance($userId)];
}

function get_followers_override(int $userId): ?int {
    global $pdo;
    $st = $pdo->prepare('SELECT followers_override FROM users WHERE id = ?');
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null) return null;
    return (int)$v;
}

function get_followers_count(int $userId): int {
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE target_user_id = ?');
    $st->execute([$userId]);
    return (int)($st->fetchColumn() ?: 0);
}

function get_effective_followers(int $userId): int {
    $ov = get_followers_override($userId);
    if ($ov !== null) return (int)$ov;
    return get_followers_count($userId);
}

function is_user_verified_by_username(string $username): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT verified FROM contributors WHERE name = ?');
    $st->execute([trim($username)]);
    return ((int)($st->fetchColumn() ?: 0)) === 1;
}

function has_wallet_access(int $userId): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT username FROM users WHERE id = ?');
    $st->execute([$userId]);
    $username = (string)($st->fetchColumn() ?: '');
    if ($username === '') return false;
    $verified = is_user_verified_by_username($username);
    $followers = get_effective_followers($userId);
    $coins = get_karma_balance($userId);
    return $verified && $followers >= 10000 && $coins >= 100000 && has_approved_kyc($userId);
}

function require_wallet_access_or_redirect(): void {
    if (!is_logged_in()) require_login();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!has_approved_kyc($uid)) {
        global $BASE_PATH;
        header('Location: ' . $BASE_PATH . 'kyc.php');
        exit;
    }
}

// Check if the user has an approved KYC request
function has_approved_kyc(int $userId): bool {
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT status FROM kyc_requests WHERE user_id = ? ORDER BY updated_at DESC, created_at DESC LIMIT 1');
        $st->execute([$userId]);
        $status = (string)($st->fetchColumn() ?: '');
        return $status === 'approved';
    } catch (Throwable $e) {
        return false;
    }
}

// Proceed to wallet after KYC approval; otherwise send to KYC page
function proceed_wallet_after_kyc(): void {
    if (!is_logged_in()) require_login();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    global $BASE_PATH;
    if (has_approved_kyc($uid)) {
        header('Location: ' . $BASE_PATH . 'wallet.php');
        exit;
    } else {
        header('Location: ' . $BASE_PATH . 'kyc.php');
        exit;
    }
}

function get_site_stats(): array {
    global $pdo;
    $stats = [
        'users' => 0,
        'campaigns' => 0,
        'campaigns_open' => 0,
        'campaigns_closed' => 0,
        'endorsements' => 0,
        'kyc_approved' => 0,
        'kyc_pending' => 0,
        'wallets' => 0,
    ];
    try { $stats['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['campaigns'] = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['campaigns_open'] = (int)$pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'open'")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['campaigns_closed'] = (int)$pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'closed'")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['endorsements'] = (int)($pdo->query('SELECT SUM(COALESCE(endorse_campaign,0)) FROM campaigns')->fetchColumn() ?: 0); } catch (Throwable $e) {}
    try { $stats['kyc_approved'] = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status = 'approved'")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['kyc_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
    try { $stats['wallets'] = (int)$pdo->query('SELECT COUNT(*) FROM karma_wallets')->fetchColumn(); } catch (Throwable $e) {}
    return $stats;
}

function set_contributor_verified(string $name, int $verified): void {
    global $pdo, $DB_DRIVER;
    $now = gmdate('Y-m-d H:i:s');
    if (($DB_DRIVER ?? 'mysql') === 'pgsql') {
        $pdo->prepare('INSERT INTO contributors (name, verified, created_at, updated_at) VALUES (?, ?, ?, ?)
                       ON CONFLICT (name) DO UPDATE SET verified = EXCLUDED.verified, updated_at = EXCLUDED.updated_at')
            ->execute([$name, $verified, $now, $now]);
    } else {
        $pdo->prepare('INSERT INTO contributors (name, verified, created_at, updated_at) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE verified = VALUES(verified), updated_at = VALUES(updated_at)')
            ->execute([$name, $verified, $now, $now]);
    }
}

function format_compact_number(int $n): string {
    if ($n >= 1000000000) {
        $v = round($n / 1000000000, 1);
        return rtrim(rtrim(sprintf('%.1f', $v), '0'), '.') . 'b';
    }
    if ($n >= 1000000) {
        $v = round($n / 1000000, 1);
        return rtrim(rtrim(sprintf('%.1f', $v), '0'), '.') . 'm';
    }
    if ($n >= 1000) {
        $v = round($n / 1000, 1);
        return rtrim(rtrim(sprintf('%.1f', $v), '0'), '.') . 'k';
    }
    return (string)$n;
}

// Auto-close campaigns whose closing_time or end_date has passed
function auto_close_expired_campaigns(): void {
    global $pdo;
    try {
        $nowDT = gmdate('Y-m-d H:i:s');
        $today = gmdate('Y-m-d');
        // Close campaigns by closing_time
        $sql1 = "UPDATE campaigns SET status = 'closed' WHERE status = 'open' AND closing_time IS NOT NULL AND closing_time <> '' AND closing_time <= ?";
        $st1 = $pdo->prepare($sql1);
        $st1->execute([$nowDT]);
        // Close campaigns by end_date
        $sql2 = "UPDATE campaigns SET status = 'closed' WHERE status = 'open' AND end_date IS NOT NULL AND end_date <> '' AND end_date < ?";
        $st2 = $pdo->prepare($sql2);
        $st2->execute([$today]);
    } catch (Throwable $e) { /* silent */ }
}

// Invoke auto-close on every request to keep status fresh without manual admin action
try { auto_close_expired_campaigns(); } catch (Throwable $e) {}
// Password reset helpers using filesystem tokens (expires in 1 hour)
function create_password_reset_token(int $userId, string $email): string {
    $token = bin2hex(random_bytes(16));
    $payload = [
        'user_id' => $userId,
        'email' => $email,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];
    try {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'password_resets';
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        file_put_contents($dir . DIRECTORY_SEPARATOR . $token . '.json', json_encode($payload), LOCK_EX);
    } catch (Throwable $e) {}
    return $token;
}

function read_password_reset_token(string $token): ?array {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'password_resets' . DIRECTORY_SEPARATOR . $token . '.json';
    if (!is_file($path)) return null;
    try {
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) return null;
        $exp = strtotime((string)($data['expires_at'] ?? ''));
        if ($exp !== false && time() > $exp) { @unlink($path); return null; }
        return $data;
    } catch (Throwable $e) { return null; }
}

function complete_password_reset(string $token, string $newPassword): bool {
    global $pdo;
    $rec = read_password_reset_token($token);
    if (!$rec) return false;
    $userId = (int)($rec['user_id'] ?? 0);
    if ($userId <= 0) return false;
    if (strlen($newPassword) < 6) return false;
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    try {
        $st = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $st->execute([$hash, $userId]);
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'password_resets' . DIRECTORY_SEPARATOR . $token . '.json';
        @unlink($path);
        return true;
    } catch (Throwable $e) { return false; }
}

// Minimal mail sender with optional Resend (free) integration
function send_mail(string $to, string $subject, string $html, ?string $text = null): bool {
    $apiKey = getenv('RESEND_API_KEY') ?: '';
    $from = getenv('MAIL_FROM') ?: 'No Starve <noreply@nostrv.local>'; // configurable sender
    if ($apiKey !== '') {
        // Use Resend API
        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text ?? strip_tags($html),
        ]);
        try {
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) return true;
        } catch (Throwable $e) {
            // fall through to mail()
        }
    }
    // Fallback to PHP mail()
    $headers = "MIME-Version: 1.0\r\n" .
               "Content-type:text/html;charset=UTF-8\r\n" .
               "From: " . $from . "\r\n";
    try { return @mail($to, $subject, $html, $headers); } catch (Throwable $e) { return false; }
}

function send_password_reset_email(string $to, string $link): bool {
    $subject = 'Reset your No Starve password';
    $html = '<div style="font-family:system-ui,Segoe UI,Arial;">'
          . '<p>Click the button below to reset your password. The link expires in 1 hour.</p>'
          . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 14px;background:#1a7aff;color:#fff;text-decoration:none;border-radius:8px;">Reset Password</a></p>'
          . '<p>If the button doesn\'t work, copy and paste this link into your browser:</p>'
          . '<p>' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>'
          . '</div>';
    return send_mail($to, $subject, $html);
}

function classify_contributor_kind(string $name): string {
    $n = strtolower(trim($name));
    if ($n === '') return 'other';
    $r = ['restaurant','hotel','cafe','eatery','food court','bakery'];
    foreach ($r as $k) { if (strpos($n, $k) !== false) return 'restaurant_firm'; }
    $m = ['mess','canteen','tiffin','dabbawala','hostel mess'];
    foreach ($m as $k) { if (strpos($n, $k) !== false) return 'mess_firm'; }
    $h = ['home','homemade','home cooked','free food','community kitchen','langar','prasad'];
    foreach ($h as $k) { if (strpos($n, $k) !== false) return 'home_cooked_free_foods'; }
    return 'other';
}

function classify_user_roles(int $userId): array {
    global $pdo;
    $user = null;
    try { $st = $pdo->prepare('SELECT username, email, address FROM users WHERE id = ?'); $st->execute([$userId]); $user = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    $email = strtolower((string)($user['email'] ?? ''));
    $addr = strtolower((string)($user['address'] ?? ''));
    $userType = 'general';
    $campaignsCount = 0;
    $endorseCount = 0;
    try { $st = $pdo->prepare('SELECT COUNT(*) FROM campaigns WHERE user_id = ?'); $st->execute([$userId]); $campaignsCount = (int)($st->fetchColumn() ?: 0); } catch (Throwable $e) {}
    try { $st = $pdo->prepare('SELECT COUNT(*) FROM endorsements WHERE user_id = ?'); $st->execute([$userId]); $endorseCount = (int)($st->fetchColumn() ?: 0); } catch (Throwable $e) {}
    $studentKeys = ['college','university','student','campus','hostel'];
    $bachelorKeys = ['pg','paying guest','hostel','co-living','coliving','flat','bachelor'];
    foreach ($studentKeys as $k) { if ($addr && strpos($addr, $k) !== false) { $userType = 'student'; break; } }
    if ($userType === 'general' && (strpos($email, '.edu') !== false)) $userType = 'student';
    if ($userType === 'general') { foreach ($bachelorKeys as $k) { if ($addr && strpos($addr, $k) !== false) { $userType = 'bachelor'; break; } } }
    if ($campaignsCount >= 3 || $endorseCount >= 10) $userType = 'social_activist';
    $contribKinds = [];
    try {
        $st = $pdo->prepare('SELECT DISTINCT contributor_name FROM campaigns WHERE user_id = ? AND contributor_name IS NOT NULL AND contributor_name <> \'\'');
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) { $kind = classify_contributor_kind((string)($r['contributor_name'] ?? '')); if ($kind) $contribKinds[$kind] = true; }
    } catch (Throwable $e) {}
    return [
        'user_type' => $userType,
        'contributor_types' => array_keys($contribKinds),
    ];
}