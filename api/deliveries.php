<?php
/**
 * MilkBook - Deliveries API Endpoint
 * Handles daily sheet queries, bulk saving with rate snapshots,
 * monthly register matrix, and single-cell edits.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = Database::getConnection();
check_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// -------------------------------------------------------------
// GET: Daily Sheet OR Monthly Register View
// -------------------------------------------------------------
if ($method === 'GET') {
    $view = $_GET['view'] ?? 'daily';

    // ---------------------------------------------------------
    // View 1: Monthly Register Grid
    // ---------------------------------------------------------
    if ($view === 'register') {
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        if ($month < 1 || $month > 12) {
            json_response(null, 422, false, ['message' => 'Invalid month. Must be between 1 and 12.']);
        }
        if ($year < 2020 || $year > 2099) {
            json_response(null, 422, false, ['message' => 'Invalid year.']);
        }

        $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // Fetch customers who were active or had deliveries during this month
        $sqlCust = "SELECT DISTINCT c.id, c.name, c.address, c.committed_qty, c.custom_rate, c.route_order, c.start_date, c.is_active
                    FROM customers c
                    LEFT JOIN deliveries d ON d.customer_id = c.id AND d.delivery_date BETWEEN :start AND :end_del
                    WHERE (c.is_active = 1 AND c.start_date <= :end_cust) OR d.id IS NOT NULL
                    ORDER BY c.route_order ASC, c.name ASC";
        $stmtCust = $pdo->prepare($sqlCust);
        $stmtCust->execute([':start' => $monthStart, ':end_del' => $monthEnd, ':end_cust' => $monthEnd]);
        $customersList = $stmtCust->fetchAll();

        // Fetch all deliveries for this month
        $sqlDel = "SELECT id, customer_id, delivery_date, quantity, rate, amount, invoice_id
                   FROM deliveries
                   WHERE delivery_date BETWEEN :start AND :end";
        $stmtDel = $pdo->prepare($sqlDel);
        $stmtDel->execute([':start' => $monthStart, ':end' => $monthEnd]);
        $deliveryRows = $stmtDel->fetchAll();

        // Index deliveries by customer_id and day
        $matrix = [];
        foreach ($deliveryRows as $del) {
            $cid = (int)$del['customer_id'];
            $day = (int)date('j', strtotime($del['delivery_date']));
            $matrix[$cid][$day] = [
                'id'       => (int)$del['id'],
                'quantity' => (float)$del['quantity'],
                'rate'     => (float)$del['rate'],
                'amount'   => (float)$del['amount'],
                'locked'   => !empty($del['invoice_id']),
                'skipped'  => (float)$del['quantity'] === 0.0
            ];
        }

        // Initialize day column totals
        $dayTotals = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dayTotals[$d] = [
                'litres'    => 0.00,
                'delivered' => 0,
                'skipped'   => 0,
                'unrecorded'=> 0
            ];
        }

        $gridCustomers = [];
        $grandTotalLitres = 0.00;
        $grandTotalAmount = 0.00;

        foreach ($customersList as $cust) {
            $cid = (int)$cust['id'];
            $startDate = $cust['start_date'];
            $committedQty = (float)$cust['committed_qty'];

            $custRowDays = [];
            $rowTotalLitres = 0.00;
            $rowTotalAmount = 0.00;
            $daysDelivered = 0;
            $daysSkipped = 0;
            $daysUnrecorded = 0;

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);

                if ($dateStr < $startDate) {
                    $custRowDays[$d] = [
                        'not_started' => true,
                        'quantity'    => null,
                        'locked'      => false
                    ];
                } elseif (isset($matrix[$cid][$d])) {
                    $cell = $matrix[$cid][$d];
                    $qty = $cell['quantity'];
                    $amt = $cell['amount'];

                    $custRowDays[$d] = $cell;
                    $rowTotalLitres += $qty;
                    $rowTotalAmount += $amt;

                    $dayTotals[$d]['litres'] += $qty;
                    if ($qty > 0) {
                        $daysDelivered++;
                        $dayTotals[$d]['delivered']++;
                    } else {
                        $daysSkipped++;
                        $dayTotals[$d]['skipped']++;
                    }
                } else {
                    // Unrecorded past or future day
                    $custRowDays[$d] = null;
                    $daysUnrecorded++;
                    $dayTotals[$d]['unrecorded']++;
                }
            }

            $grandTotalLitres += $rowTotalLitres;
            $grandTotalAmount += $rowTotalAmount;

            $gridCustomers[] = [
                'id'             => $cid,
                'name'           => $cust['name'],
                'address'        => $cust['address'],
                'committed_qty'  => $committedQty,
                'route_order'    => (int)$cust['route_order'],
                'start_date'     => $startDate,
                'days'           => $custRowDays,
                'total_litres'   => round($rowTotalLitres, 2),
                'total_amount'   => round_money($rowTotalAmount),
                'days_delivered' => $daysDelivered,
                'days_skipped'   => $daysSkipped,
                'days_unrecorded'=> $daysUnrecorded
            ];
        }

        // Round day column totals
        foreach ($dayTotals as $d => $t) {
            $dayTotals[$d]['litres'] = round($t['litres'], 2);
        }

        json_response([
            'month'              => $month,
            'year'               => $year,
            'days_in_month'      => $daysInMonth,
            'customers'          => $gridCustomers,
            'day_totals'         => $dayTotals,
            'grand_total_litres' => round($grandTotalLitres, 2),
            'grand_total_amount' => round_money($grandTotalAmount)
        ]);
    }

    // ---------------------------------------------------------
    // View 2: Daily Sheet (Default)
    // ---------------------------------------------------------
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!validate_date($date)) {
        json_response(null, 422, false, ['message' => 'Invalid date format. Expected YYYY-MM-DD.']);
    }

    // Query active customers on or before delivery date, with delivery data joined
    $sql = "SELECT c.id AS customer_id, c.name, c.address, c.committed_qty, c.custom_rate, c.route_order, c.start_date,
                   d.id AS delivery_id, d.quantity AS saved_quantity, d.rate AS saved_rate, d.amount AS saved_amount, d.invoice_id
            FROM customers c
            LEFT JOIN deliveries d ON d.customer_id = c.id AND d.delivery_date = :date
            WHERE c.is_active = 1 AND c.start_date <= :date_filter
            ORDER BY c.route_order ASC, c.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':date'        => $date,
        ':date_filter' => $date
    ]);
    $rows = $stmt->fetchAll();

    $customers = [];
    $totalCustomers = count($rows);
    $savedCount = 0;
    $deliveredCount = 0;
    $skippedCount = 0;
    $changedCount = 0;
    $totalLitres = 0.00;
    $committedLitres = 0.00;

    foreach ($rows as $row) {
        $cid = (int)$row['customer_id'];
        $committedQty = (float)$row['committed_qty'];
        $committedLitres += $committedQty;

        $hasSaved = ($row['saved_quantity'] !== null);
        $savedQty = $hasSaved ? (float)$row['saved_quantity'] : null;
        $isLocked = !empty($row['invoice_id']);

        // Determine rate: if saved, use snapshot rate; otherwise resolve via get_rate_for
        $rate = $row['saved_rate'] !== null 
            ? (float)$row['saved_rate'] 
            : get_rate_for($pdo, $cid, $date);

        $amount = $row['saved_amount'] !== null 
            ? (float)$row['saved_amount'] 
            : ($savedQty !== null ? round_money($savedQty * $rate) : null);

        if ($hasSaved) {
            $savedCount++;
            $totalLitres += $savedQty;
            if ($savedQty > 0) {
                $deliveredCount++;
            } else {
                $skippedCount++;
            }
            if ($savedQty !== $committedQty) {
                $changedCount++;
            }
        }

        $customers[] = [
            'customer_id'    => $cid,
            'name'           => $row['name'],
            'address'        => $row['address'],
            'committed_qty'  => $committedQty,
            'saved_quantity' => $savedQty,
            'rate'           => $rate,
            'amount'         => $amount,
            'locked'         => $isLocked,
            'delivery_id'    => $row['delivery_id'] !== null ? (int)$row['delivery_id'] : null
        ];
    }

    json_response([
        'date'      => $date,
        'customers' => $customers,
        'summary'   => [
            'total_customers'  => $totalCustomers,
            'saved_count'      => $savedCount,
            'delivered_count'  => $deliveredCount,
            'skipped_count'    => $skippedCount,
            'changed_count'    => $changedCount,
            'total_litres'     => round($totalLitres, 2),
            'committed_litres' => round($committedLitres, 2),
            'is_all_saved'     => ($totalCustomers > 0 && $savedCount === $totalCustomers)
        ]
    ]);
}

// -------------------------------------------------------------
// POST: Bulk Save Daily Sheet (Atomic Transaction)
// -------------------------------------------------------------
if ($method === 'POST') {
    $body = read_json_body();
    $date = trim((string)($body['date'] ?? ''));

    if (!validate_date($date)) {
        json_response(null, 422, false, ['message' => 'Invalid or missing delivery date. Expected YYYY-MM-DD.']);
    }

    $entries = $body['entries'] ?? [];
    if (!is_array($entries) || empty($entries)) {
        json_response(null, 422, false, ['message' => 'No delivery entries provided.']);
    }

    // Validate entries structure before database operations
    $sanitizedEntries = [];
    $customerIds = [];
    foreach ($entries as $idx => $entry) {
        $cid = (int)($entry['customer_id'] ?? 0);
        if ($cid <= 0) {
            json_response(null, 422, false, ['message' => "Invalid customer ID at entry index {$idx}."]);
        }

        $qty = $entry['quantity'] ?? null;
        if ($qty === null || !validate_delivery_qty($qty)) {
            json_response(null, 422, false, [
                'message' => "Invalid delivery quantity for customer ID {$cid}. Must be >= 0, <= 20, and a multiple of 0.25 litres."
            ]);
        }

        $sanitizedEntries[] = [
            'customer_id' => $cid,
            'quantity'    => round((float)$qty, 2)
        ];
        $customerIds[] = $cid;
    }

    // Begin single atomic transaction
    $pdo->beginTransaction();

    try {
        // 1. Fetch customer committed quantities for summary calculation
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $sqlComm = "SELECT id, committed_qty FROM customers WHERE id IN ({$placeholders})";
        $stmtComm = $pdo->prepare($sqlComm);
        $stmtComm->execute($customerIds);
        $committedMap = [];
        while ($c = $stmtComm->fetch()) {
            $committedMap[(int)$c['id']] = (float)$c['committed_qty'];
        }

        // 3. Upsert each row with rate snapshot
        $sqlUpsert = "INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount)
                      VALUES (:cid, :date, :quantity, :rate, :amount)
                      ON DUPLICATE KEY UPDATE
                          quantity = VALUES(quantity),
                          rate = VALUES(rate),
                          amount = VALUES(amount),
                          updated_at = NOW()";
        $stmtUpsert = $pdo->prepare($sqlUpsert);

        $totalLitres = 0.00;
        $deliveredCount = 0;
        $skippedCount = 0;
        $changedCount = 0;

        foreach ($sanitizedEntries as $entry) {
            $cid = $entry['customer_id'];
            $qty = $entry['quantity'];

            // Compute rate snapshot using get_rate_for
            $rate = get_rate_for($pdo, $cid, $date);
            $amount = round_money($qty * $rate);

            $stmtUpsert->execute([
                ':cid'      => $cid,
                ':date'     => $date,
                ':quantity' => $qty,
                ':rate'     => $rate,
                ':amount'   => $amount
            ]);

            $totalLitres += $qty;
            if ($qty > 0) {
                $deliveredCount++;
            } else {
                $skippedCount++;
            }

            $committed = $committedMap[$cid] ?? $qty;
            if ($qty !== $committed) {
                $changedCount++;
            }
        }

        $pdo->commit();

        json_response([
            'date'            => $date,
            'saved_count'     => count($sanitizedEntries),
            'total_litres'    => round($totalLitres, 2),
            'delivered_count' => $deliveredCount,
            'skipped_count'   => $skippedCount,
            'changed_count'   => $changedCount
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -------------------------------------------------------------
// PUT: Single Cell Edit (From Monthly Register)
// -------------------------------------------------------------
if ($method === 'PUT') {
    $body = read_json_body();
    $cid = (int)($body['customer_id'] ?? 0);
    $date = trim((string)($body['date'] ?? ''));
    $qty = $body['quantity'] ?? null;

    if ($cid <= 0) {
        json_response(null, 422, false, ['message' => 'Invalid or missing customer ID.']);
    }
    if (!validate_date($date)) {
        json_response(null, 422, false, ['message' => 'Invalid date format. Expected YYYY-MM-DD.']);
    }
    if ($qty === null || !validate_delivery_qty($qty)) {
        json_response(null, 422, false, [
            'message' => 'Quantity must be >= 0, <= 20, and an exact multiple of 0.25 litres.'
        ]);
    }

    $qtyVal = round((float)$qty, 2);

    // Fetch existing delivery if present
    $stmtCheck = $pdo->prepare(
        'SELECT id, invoice_id FROM deliveries WHERE customer_id = :cid AND delivery_date = :date LIMIT 1'
    );
    $stmtCheck->execute([':cid' => $cid, ':date' => $date]);
    $existing = $stmtCheck->fetch();
    $existingInvoiceId = ($existing && !empty($existing['invoice_id'])) ? (int)$existing['invoice_id'] : null;

    // Compute rate snapshot via get_rate_for
    $rate = get_rate_for($pdo, $cid, $date);
    $amount = round_money($qtyVal * $rate);

    $sqlUpsert = "INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount)
                  VALUES (:cid, :date, :quantity, :rate, :amount)
                  ON DUPLICATE KEY UPDATE
                      quantity = VALUES(quantity),
                      rate = VALUES(rate),
                      amount = VALUES(amount),
                      updated_at = NOW()";
    $stmtUpsert = $pdo->prepare($sqlUpsert);
    $stmtUpsert->execute([
        ':cid'      => $cid,
        ':date'     => $date,
        ':quantity' => $qtyVal,
        ':rate'     => $rate,
        ':amount'   => $amount
    ]);

    json_response([
        'customer_id' => $cid,
        'date'        => $date,
        'quantity'    => $qtyVal,
        'rate'        => $rate,
        'amount'      => $amount,
        'locked'      => false,
        'skipped'     => ($qtyVal === 0.0)
    ]);
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
