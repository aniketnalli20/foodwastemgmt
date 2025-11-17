<?php
require_once __DIR__ . '/app.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // Meals Made: sum of target_meals or crowd_size on open campaigns
    $mealsSaved = 0;
    try {
        $mealsSaved = (int)($pdo->query("SELECT COALESCE(SUM(COALESCE(target_meals, crowd_size)), 0) FROM campaigns WHERE status = 'open'")->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    // Contributors: distinct users who created campaigns
    $donorsCount = 0;
    try {
        $donorsCount = (int)($pdo->query("SELECT COUNT(DISTINCT user_id) FROM campaigns WHERE user_id IS NOT NULL")->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    // Partners: distinct contributor_name values from campaigns
    $partnersCount = 0;
    try {
        $partnersCount = (int)($pdo->query("SELECT COUNT(DISTINCT contributor_name) FROM campaigns WHERE contributor_name IS NOT NULL AND contributor_name <> ''")->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    // Active Users: distinct users who endorsed in the last 30 days
    $activeUsersCount = 0;
    try {
        $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * 24 * 3600));
        $st = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM endorsements WHERE user_id IS NOT NULL AND created_at >= ?');
        $st->execute([$cutoff]);
        $activeUsersCount = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    echo json_encode([
        'mealsSaved' => $mealsSaved,
        'donorsCount' => $donorsCount,
        'partnersCount' => $partnersCount,
        'activeUsersCount' => $activeUsersCount,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}