<?php
/**
 * MilkBook - Balances API
 * Returns live customer balances computed strictly from the ledger: SUM(debit) - SUM(credit)
 * Positive = pending dues, Negative = advance credit.
 * Outstanding totals ALWAYS come from the ledger, never from summing invoice statuses.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db_connection();
check_auth();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET') {
    json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
}

$activeOnly = !isset($_GET['all']) || $_GET['all'] !== '1';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : ''; // pending, advance, clear

// Base query joining customer with aggregated ledger
$sql = "SELECT c.id, c.name, c.mobile, c.address, c.committed_qty, c.custom_rate, c.route_order, c.is_active,
               IFNULL(l.total_debit, 0.00) AS total_debit,
               IFNULL(l.total_credit, 0.00) AS total_credit,
               ROUND(IFNULL(l.total_debit, 0.00) - IFNULL(l.total_credit, 0.00), 2) AS current_balance
        FROM customers c
        LEFT JOIN (
            SELECT customer_id,
                   SUM(debit) AS total_debit,
                   SUM(credit) AS total_credit
            FROM ledger
            GROUP BY customer_id
        ) l ON l.customer_id = c.id
        WHERE 1=1";

$params = [];

if ($activeOnly) {
    $sql .= " AND c.is_active = 1";
}

if ($search !== '') {
    $sql .= " AND (c.name LIKE :s_name OR c.mobile LIKE :s_mob)";
    $params[':s_name'] = "%{$search}%";
    $params[':s_mob'] = "%{$search}%";
}

$sql .= " ORDER BY c.route_order ASC, c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Fetch latest invoices for all customers to provide context
$stmtInv = $pdo->query(
    "SELECT i.id, i.customer_id, i.invoice_no, i.period_month, i.period_year, 
            i.total_payable, i.paid_amount, i.status
     FROM invoices i
     INNER JOIN (
         SELECT customer_id, MAX(id) AS max_id
         FROM invoices
         GROUP BY customer_id
     ) latest ON i.id = latest.max_id"
);
$latestInvoices = [];
while ($inv = $stmtInv->fetch()) {
    $latestInvoices[(int)$inv['customer_id']] = [
        'id'            => (int)$inv['id'],
        'invoice_no'    => $inv['invoice_no'],
        'period_month'  => (int)$inv['period_month'],
        'period_year'   => (int)$inv['period_year'],
        'total_payable' => (float)$inv['total_payable'],
        'paid_amount'   => (float)$inv['paid_amount'],
        'status'        => $inv['status']
    ];
}

$customers = [];
$totalPendingDues = 0.00;
$totalAdvanceHeld = 0.00;

foreach ($rows as $row) {
    $cid = (int)$row['id'];
    $bal = round_money((float)$row['current_balance']);

    if ($bal > 0) {
        $balStatus = 'pending';
        $totalPendingDues += $bal;
    } elseif ($bal < 0) {
        $balStatus = 'advance';
        $totalAdvanceHeld += abs($bal);
    } else {
        $balStatus = 'clear';
    }

    // Apply status filter if requested
    if ($statusFilter !== '' && $statusFilter !== 'all' && $statusFilter !== $balStatus) {
        continue;
    }

    $customers[] = [
        'id'             => $cid,
        'name'           => $row['name'],
        'mobile'         => $row['mobile'],
        'address'        => $row['address'],
        'committed_qty'  => (float)$row['committed_qty'],
        'custom_rate'    => $row['custom_rate'] !== null ? (float)$row['custom_rate'] : null,
        'route_order'    => (int)$row['route_order'],
        'is_active'      => (int)$row['is_active'],
        'total_debit'    => (float)$row['total_debit'],
        'total_credit'   => (float)$row['total_credit'],
        'balance'        => $bal,
        'balance_status' => $balStatus,
        'latest_invoice' => $latestInvoices[$cid] ?? null
    ];
}

json_response([
    'customers'          => $customers,
    'total_count'        => count($customers),
    'total_pending_dues' => round_money($totalPendingDues),
    'total_advance_held' => round_money($totalAdvanceHeld),
    'net_balance'        => round_money($totalPendingDues - $totalAdvanceHeld)
]);
