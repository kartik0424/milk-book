<?php
/**
 * MilkBook - Statement API
 * GET ?customer_id={id}&from={date}&to={date}
 * Returns ledger statement with opening balance, chronologically ordered entries,
 * and running balance for each entry.
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

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
if ($customerId <= 0) {
    json_response(null, 422, false, ['message' => 'Valid customer_id is required.']);
}

// Fetch customer
$stmtCust = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
$stmtCust->execute([':id' => $customerId]);
$customer = $stmtCust->fetch();

if (!$customer) {
    json_response(null, 404, false, ['message' => "Customer with ID {$customerId} not found."]);
}

// Date range filters
$fromDate = isset($_GET['from']) && trim($_GET['from']) !== '' ? trim($_GET['from']) : date('Y-01-01');
$toDate = isset($_GET['to']) && trim($_GET['to']) !== '' ? trim($_GET['to']) : date('Y-m-t');

if (!validate_date($fromDate) || !validate_date($toDate)) {
    json_response(null, 422, false, ['message' => 'Invalid date range. Use YYYY-MM-DD.']);
}
if ($fromDate > $toDate) {
    json_response(null, 422, false, ['message' => 'from date cannot be later than to date.']);
}

// 1. Calculate Opening Balance before $fromDate
$stmtOpen = $pdo->prepare(
    "SELECT IFNULL(SUM(debit) - SUM(credit), 0.00) AS opening_balance 
     FROM ledger 
     WHERE customer_id = :cid AND entry_date < :from_date"
);
$stmtOpen->execute([
    ':cid'       => $customerId,
    ':from_date' => $fromDate
]);
$openingBalance = round_money((float)$stmtOpen->fetchColumn());

// 2. Fetch Ledger rows in range
$stmtEntries = $pdo->prepare(
    "SELECT id, entry_date, type, ref_id, description, debit, credit, created_at
     FROM ledger
     WHERE customer_id = :cid AND entry_date BETWEEN :from_date AND :to_date
     ORDER BY entry_date ASC, id ASC"
);
$stmtEntries->execute([
    ':cid'       => $customerId,
    ':from_date' => $fromDate,
    ':to_date'   => $toDate
]);
$rawEntries = $stmtEntries->fetchAll();

$runningBalance = $openingBalance;
$entries = [];
$totalPeriodDebit = 0.00;
$totalPeriodCredit = 0.00;

foreach ($rawEntries as $row) {
    $debit = round_money((float)$row['debit']);
    $credit = round_money((float)$row['credit']);
    $runningBalance = round_money($runningBalance + $debit - $credit);

    $totalPeriodDebit += $debit;
    $totalPeriodCredit += $credit;

    $entries[] = [
        'id'              => (int)$row['id'],
        'entry_date'      => $row['entry_date'],
        'type'            => $row['type'],
        'ref_id'          => $row['ref_id'] ? (int)$row['ref_id'] : null,
        'description'     => $row['description'],
        'debit'           => $debit,
        'credit'          => $credit,
        'running_balance' => $runningBalance
    ];
}

$closingBalance = $runningBalance;

// Fetch business settings for printable statement header
$stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($s = $stmtSettings->fetch()) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

json_response([
    'customer' => [
        'id'            => (int)$customer['id'],
        'name'          => $customer['name'],
        'mobile'        => $customer['mobile'],
        'address'       => $customer['address'],
        'committed_qty' => (float)$customer['committed_qty'],
        'custom_rate'   => $customer['custom_rate'] !== null ? (float)$customer['custom_rate'] : null,
        'start_date'    => $customer['start_date']
    ],
    'period' => [
        'from' => $fromDate,
        'to'   => $toDate
    ],
    'opening_balance'     => $openingBalance,
    'total_period_debit'  => round_money($totalPeriodDebit),
    'total_period_credit' => round_money($totalPeriodCredit),
    'closing_balance'     => $closingBalance,
    'balance_status'      => $closingBalance > 0 ? 'pending' : ($closingBalance < 0 ? 'advance' : 'clear'),
    'entries'             => $entries,
    'business' => [
        'name'    => $settings['business_name'] ?? 'MilkBook Dairy',
        'owner'   => $settings['owner_name'] ?? 'Milkman',
        'phone'   => $settings['business_phone'] ?? ($settings['phone'] ?? ''),
        'upi_id'  => $settings['upi_id'] ?? '',
        'address' => $settings['business_address'] ?? ($settings['address'] ?? '')
    ]
]);
