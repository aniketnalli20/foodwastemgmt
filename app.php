<?php
// Common bootstrap and helpers
session_start();

require_once __DIR__ . '/db.php';

// Compute base path dynamically so links work from a subfolder (e.g., /No%20starve/) or as a vhost root
$BASE_PATH = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

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
 * Create a campaign record.
 * Required: title, summary.
 * Optional: area, target_meals (int), start_date (YYYY-MM-DD), end_date (YYYY-MM-DD), status.
 * Returns inserted campaign ID.
 */
function create_campaign(array $data, ?array $imageFile = null): int {
    global $pdo;

    $contributorName = trim((string)($data['contributor_name'] ?? ''));
    $community = isset($data['community']) ? trim((string)$data['community']) : '';
    $location = trim((string)($data['location'] ?? ''));
    $crowdSize = isset($data['crowd_size']) && $data['crowd_size'] !== '' ? (int)$data['crowd_size'] : null;
    $closingTime = isset($data['closing_time']) ? trim((string)$data['closing_time']) : null;

    if ($contributorName === '' || $location === '' || $crowdSize === null || $closingTime === null) {
        throw new InvalidArgumentException('contributor_name, location, crowd_size, and closing_time are required');
    }

    $title = trim((string)($data['title'] ?? 'Campaign by ' . $contributorName));
    // Keep default summary generic to avoid duplicating meta details on the community page
    $summary = trim((string)($data['summary'] ?? 'Surplus food available; volunteers needed.'));

    $area = isset($data['area']) ? trim((string)$data['area']) : $location;
    $targetMeals = isset($data['target_meals']) && $data['target_meals'] !== '' ? (int)$data['target_meals'] : null;
    $startDate = isset($data['start_date']) ? trim((string)$data['start_date']) : null;
    $endDate = isset($data['end_date']) ? trim((string)$data['end_date']) : null;
    $status = isset($data['status']) && $data['status'] !== '' ? trim((string)$data['status']) : 'draft';

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

    $stmt = $pdo->prepare('INSERT INTO campaigns (title, summary, area, target_meals, start_date, end_date, status, created_at, contributor_name, community, location, crowd_size, image_url, closing_time, latitude, longitude)
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
        $community,
        $location,
        $crowdSize,
        $imageUrl,
        $closingTime,
        $latitude,
        $longitude,
    ]);

    return (int)$pdo->lastInsertId();
}