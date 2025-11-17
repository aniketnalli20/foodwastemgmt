<?php
require_once __DIR__ . '/app.php';
header('Content-Type: application/json');
// Must be logged in
if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$followerId = (int)($_SESSION['user_id'] ?? 0);
$targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
$contribNameRaw = isset($_POST['contributor_name']) ? trim((string)$_POST['contributor_name']) : '';
$contribName = $contribNameRaw !== '' ? $contribNameRaw : null;
$mode = strtolower(trim((string)($_POST['mode'] ?? 'toggle')));

if ($targetUserId <= 0 && !$contribName) {
    echo json_encode(['ok' => false, 'error' => 'invalid_target']);
    exit;
}
if ($targetUserId > 0 && $targetUserId === $followerId) {
    echo json_encode(['ok' => false, 'error' => 'self']);
    exit;
}

try {
    global $pdo, $DB_DRIVER;
    if ($mode === 'unfollow') {
        if ($targetUserId > 0) {
            $st = $pdo->prepare('DELETE FROM follows WHERE follower_user_id = ? AND target_user_id = ?');
            $st->execute([$followerId, $targetUserId]);
        } else {
            $st = $pdo->prepare('DELETE FROM follows WHERE follower_user_id = ? AND contributor_name = ?');
            $st->execute([$followerId, $contribName]);
        }
        echo json_encode(['ok' => true, 'following' => false]);
        exit;
    }
    // toggle / follow
    // Check exists
    if ($targetUserId > 0) {
        $st = $pdo->prepare('SELECT id FROM follows WHERE follower_user_id = ? AND target_user_id = ?');
        $st->execute([$followerId, $targetUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('DELETE FROM follows WHERE id = ?')->execute([(int)$row['id']]);
            echo json_encode(['ok' => true, 'following' => false]);
            exit;
        }
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO follows (follower_user_id, target_user_id, created_at) VALUES (?, ?, ?)')
            ->execute([$followerId, $targetUserId, $now]);
        echo json_encode(['ok' => true, 'following' => true]);
        exit;
    } else if ($contribName) {
        $st = $pdo->prepare('SELECT id FROM follows WHERE follower_user_id = ? AND contributor_name = ?');
        $st->execute([$followerId, $contribName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('DELETE FROM follows WHERE id = ?')->execute([(int)$row['id']]);
            echo json_encode(['ok' => true, 'following' => false]);
            exit;
        }
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO follows (follower_user_id, contributor_name, created_at) VALUES (?, ?, ?)')
            ->execute([$followerId, $contribName, $now]);
        echo json_encode(['ok' => true, 'following' => true]);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server']);
    exit;
}