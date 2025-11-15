<?php
require_once __DIR__ . '/app.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // Meals Saved: count of listings that have been claimed
    $mealsSaved = 0;
    try {
        $mealsSaved = (int)$pdo->query('SELECT COUNT(*) FROM listings WHERE claimed_at IS NOT NULL')->fetchColumn();
    } catch (Throwable $e) {}

    // Donors: distinct donors who have posted listings
    $donorsCount = 0;
    try {
        $donorsCount = (int)$pdo->query('SELECT COUNT(DISTINCT donor_name) FROM listings')->fetchColumn();
    } catch (Throwable $e) {}

    // Partners: total campaigns created
    $partnersCount = 0;
    try {
        $partnersCount = (int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
    } catch (Throwable $e) {}

    echo json_encode([
        'mealsSaved' => $mealsSaved,
        'donorsCount' => $donorsCount,
        'partnersCount' => $partnersCount,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}