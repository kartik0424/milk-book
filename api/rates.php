<?php
/**
 * MilkBook - Milk Rates API Endpoint
 * Handles fetching rate history & current active rate, and registering new rates.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = Database::getConnection();
check_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// -------------------------------------------------------------
// GET: Current Rate & Rate History
// -------------------------------------------------------------
if ($method === 'GET') {
    $today = date('Y-m-d');

    // Current active rate
    $stmtCurrent = $pdo->prepare(
        'SELECT * FROM milk_rates 
         WHERE effective_from <= :today 
         ORDER BY effective_from DESC, id DESC 
         LIMIT 1'
    );
    $stmtCurrent->execute([':today' => $today]);
    $current = $stmtCurrent->fetch();

    if ($current) {
        $current['id'] = (int)$current['id'];
        $current['rate_per_litre'] = (float)$current['rate_per_litre'];
    }

    // Full history
    $stmtHistory = $pdo->query(
        'SELECT * FROM milk_rates 
         ORDER BY effective_from DESC, id DESC'
    );
    $historyRows = $stmtHistory->fetchAll();

    $history = [];
    foreach ($historyRows as $row) {
        $history[] = [
            'id'             => (int)$row['id'],
            'rate_per_litre' => (float)$row['rate_per_litre'],
            'effective_from' => $row['effective_from'],
            'created_at'     => $row['created_at'],
            'is_current'     => $current && ((int)$row['id'] === $current['id'])
        ];
    }

    json_response([
        'current_rate' => $current,
        'history'      => $history
    ]);
}

// -------------------------------------------------------------
// POST: Add New Milk Rate
// -------------------------------------------------------------
if ($method === 'POST') {
    $body = read_json_body();
    $errors = [];

    $rate = $body['rate_per_litre'] ?? null;
    if ($rate === null || !is_numeric($rate) || (float)$rate <= 0) {
        $errors['rate_per_litre'] = 'Rate per litre must be a positive number greater than 0.';
    }

    $effectiveFrom = trim((string)($body['effective_from'] ?? ''));
    if (!validate_date($effectiveFrom)) {
        $errors['effective_from'] = 'Effective from date must be a valid date in YYYY-MM-DD format.';
    }

    if (!empty($errors)) {
        json_response(null, 422, false, [
            'message' => 'Validation failed for new milk rate.',
            'fields'  => $errors
        ]);
    }

    $rateVal = round_money((float)$rate);

    $stmt = $pdo->prepare('INSERT INTO milk_rates (rate_per_litre, effective_from) VALUES (:rate, :effective_from)');
    $stmt->execute([
        ':rate'           => $rateVal,
        ':effective_from' => $effectiveFrom
    ]);

    $newId = (int)$pdo->lastInsertId();

    $stmtGet = $pdo->prepare('SELECT * FROM milk_rates WHERE id = :id');
    $stmtGet->execute([':id' => $newId]);
    $newRow = $stmtGet->fetch();
    $newRow['id'] = (int)$newRow['id'];
    $newRow['rate_per_litre'] = (float)$newRow['rate_per_litre'];

    json_response($newRow, 201);
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
