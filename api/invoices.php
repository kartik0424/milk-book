<?php
/**
 * MilkBook - Invoices API Endpoint
 * Handles monthly billing generation, preview, listing, detail view, and voiding.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = Database::getConnection();
check_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// -------------------------------------------------------------
// Helper: Get Invoice Prefix from Settings
// -------------------------------------------------------------
function get_invoice_prefix(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    return ($row && trim($row['setting_value']) !== '') ? trim($row['setting_value']) : 'MB-';
}

// -------------------------------------------------------------
// Helper: Get Next Invoice Number for a Period
// -------------------------------------------------------------
function generate_invoice_no(PDO $pdo, int $year, int $month): string {
    $prefix = get_invoice_prefix($pdo);
    $periodStr = sprintf('%04d%02d', $year, $month);

    // Find highest sequence number for this prefix and period
    $likePattern = $prefix . $periodStr . '-%';
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no, '-', -1) AS UNSIGNED)) AS max_seq 
         FROM invoices 
         WHERE invoice_no LIKE :pattern"
    );
    $stmt->execute([':pattern' => $likePattern]);
    $row = $stmt->fetch();

    $nextSeq = ($row && $row['max_seq'] !== null) ? ((int)$row['max_seq'] + 1) : 1;
    return sprintf('%s%s-%04d', $prefix, $periodStr, $nextSeq);
}

// -------------------------------------------------------------
// Helper: Calculate Invoice Data for a Customer (Preview or Before Generate)
// Supports both Custom Date Range (from_date to to_date) and Calendar Month (year, month).
// Dynamically fetches deliveries according to recorded attendance.
// -------------------------------------------------------------
function calculate_customer_invoice_data(
    PDO $pdo, 
    int $customerId, 
    ?int $year = null, 
    ?int $month = null,
    ?string $customFrom = null,
    ?string $customTo = null
): array {
    if (!empty($customFrom) && !empty($customTo)) {
        if (!validate_date($customFrom) || !validate_date($customTo)) {
            throw new Exception("Invalid date format. Expected YYYY-MM-DD.");
        }
        if ($customFrom > $customTo) {
            throw new Exception("Start date cannot be after end date.");
        }
        $periodFrom = $customFrom;
        $periodTo = $customTo;
        $year = (int)date('Y', strtotime($periodFrom));
        $month = (int)date('n', strtotime($periodFrom));
        $daysInPeriod = (int)round((strtotime($periodTo) - strtotime($periodFrom)) / 86400) + 1;
        $periodLabel = date('d M Y', strtotime($periodFrom)) . ' - ' . date('d M Y', strtotime($periodTo));
    } else {
        $year = $year ?: (int)date('Y');
        $month = $month ?: (int)date('n');
        $daysInPeriod = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        $periodFrom = sprintf('%04d-%02d-01', $year, $month);
        $periodTo = sprintf('%04d-%02d-%02d', $year, $month, $daysInPeriod);
        $periodLabel = date('F Y', strtotime($periodFrom));
    }

    // Customer details
    $stmtCust = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
    $stmtCust->execute([':id' => $customerId]);
    $customer = $stmtCust->fetch();

    if (!$customer) {
        throw new Exception("Customer with ID {$customerId} not found.");
    }

    // Check if an invoice exists for the exact same date range (informational, not blocking)
    $stmtExisting = $pdo->prepare(
        "SELECT id, invoice_no, total_payable FROM invoices 
         WHERE customer_id = :cid AND period_from = :pfrom AND period_to = :pto 
         ORDER BY id DESC LIMIT 1"
    );
    $stmtExisting->execute([':cid' => $customerId, ':pfrom' => $periodFrom, ':pto' => $periodTo]);
    $existingInvoice = $stmtExisting->fetch();

    // Check unrecorded days in this date range
    $unrecordedDates = get_unrecorded_dates_range($pdo, $customerId, $periodFrom, $periodTo);

    // Fetch deliveries in this date range according to attendance
    $stmtDel = $pdo->prepare(
        "SELECT id, delivery_date, quantity, rate, amount, invoice_id 
         FROM deliveries 
         WHERE customer_id = :cid AND delivery_date BETWEEN :from AND :to 
         ORDER BY delivery_date ASC"
    );
    $stmtDel->execute([':cid' => $customerId, ':from' => $periodFrom, ':to' => $periodTo]);
    $deliveryRows = $stmtDel->fetchAll();

    $daysDelivered = 0;
    $daysSkipped = 0;
    $totalQty = 0.00;
    $milkAmount = 0.00;
    $rateGroups = [];
    $dailyMap = [];

    foreach ($deliveryRows as $row) {
        $qty = (float)$row['quantity'];
        $rate = (float)$row['rate'];
        $amt = (float)$row['amount'];
        $dateStr = $row['delivery_date'];
        $dayNum = (int)date('j', strtotime($dateStr));

        $itemData = [
            'date'       => $dateStr,
            'day'        => $dayNum,
            'quantity'   => $qty,
            'rate'       => $rate,
            'amount'     => $amt,
            'invoice_id' => $row['invoice_id']
        ];
        $dailyMap[$dateStr] = $itemData;
        $dailyMap[$dayNum] = $itemData;

        if ($qty > 0) {
            $daysDelivered++;
            $totalQty += $qty;
            $milkAmount += $amt;

            // Group by rate
            $rateKey = sprintf('%.2f', $rate);
            if (!isset($rateGroups[$rateKey])) {
                $rateGroups[$rateKey] = [
                    'rate'     => $rate,
                    'quantity' => 0.00,
                    'amount'   => 0.00,
                    'days'     => 0
                ];
            }
            $rateGroups[$rateKey]['quantity'] += $qty;
            $rateGroups[$rateKey]['amount'] += $amt;
            $rateGroups[$rateKey]['days']++;
        } else {
            $daysSkipped++;
        }
    }

    // Round grouped amounts
    $lineItems = [];
    foreach ($rateGroups as $rg) {
        $lineItems[] = [
            'rate'     => $rg['rate'],
            'quantity' => round($rg['quantity'], 2),
            'amount'   => round_money($rg['amount']),
            'days'     => $rg['days']
        ];
    }

    // Previous balance from ledger: SUM(debit) - SUM(credit)
    $stmtLedger = $pdo->prepare(
        "SELECT IFNULL(SUM(debit) - SUM(credit), 0.00) AS balance 
         FROM ledger 
         WHERE customer_id = :cid"
    );
    $stmtLedger->execute([':cid' => $customerId]);
    $prevBalance = round_money((float)$stmtLedger->fetchColumn());

    $milkAmount = round_money($milkAmount);
    $totalPayable = round_money($milkAmount + $prevBalance);

    $today = date('Y-m-d');
    $isPremature = ($periodTo > $today);

    return [
        'customer_id'       => (int)$customer['id'],
        'customer_name'     => $customer['name'],
        'customer_mobile'   => $customer['mobile'],
        'customer_address'  => $customer['address'],
        'committed_qty'     => (float)$customer['committed_qty'],
        'period_month'      => $month,
        'period_year'       => $year,
        'period_from'       => $periodFrom,
        'period_to'         => $periodTo,
        'period_label'      => $periodLabel,
        'days_in_period'    => $daysInPeriod,
        'days_in_month'     => $daysInPeriod,
        'days_delivered'    => $daysDelivered,
        'days_skipped'      => $daysSkipped,
        'total_qty'         => round($totalQty, 2),
        'milk_amount'       => $milkAmount,
        'previous_balance'  => $prevBalance,
        'total_payable'     => $totalPayable,
        'line_items'        => $lineItems,
        'daily_map'         => $dailyMap,
        'unrecorded_dates'  => $unrecordedDates,
        'unrecorded_count'  => count($unrecordedDates),
        'has_invoice'       => !empty($existingInvoice),
        'existing_invoice'  => $existingInvoice ? $existingInvoice['invoice_no'] : null,
        'is_out_of_order'   => false,
        'latest_period'     => null,
        'is_premature'      => $isPremature
    ];
}

// -------------------------------------------------------------
// GET: List Invoices, Single Detail, or Calculation Preview
// -------------------------------------------------------------
if ($method === 'GET') {
    // 1. Preview Mode: Calculate without saving
    if (isset($_GET['preview'])) {
        $customFrom = isset($_GET['from_date']) && trim($_GET['from_date']) !== '' ? trim($_GET['from_date']) : (isset($_GET['start_date']) ? trim($_GET['start_date']) : null);
        $customTo = isset($_GET['to_date']) && trim($_GET['to_date']) !== '' ? trim($_GET['to_date']) : (isset($_GET['end_date']) ? trim($_GET['end_date']) : null);

        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        if (!empty($customFrom) && !empty($customTo)) {
            if (!validate_date($customFrom) || !validate_date($customTo) || $customFrom > $customTo) {
                json_response(null, 422, false, ['message' => 'Invalid date range for preview. Expected YYYY-MM-DD.']);
            }
            $periodFrom = $customFrom;
            $periodTo = $customTo;
            $periodLabel = date('d M Y', strtotime($periodFrom)) . ' - ' . date('d M Y', strtotime($periodTo));
        } else {
            if ($month < 1 || $month > 12 || $year < 2020 || $year > 2099) {
                json_response(null, 422, false, ['message' => 'Invalid month or year for preview.']);
            }
            $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
            $periodFrom = sprintf('%04d-%02d-01', $year, $month);
            $periodTo = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
            $periodLabel = date('F Y', strtotime($periodFrom));
        }

        $customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== 'all' && $_GET['customer_id'] !== ''
            ? (int)$_GET['customer_id']
            : null;

        if ($customerId !== null) {
            // Single customer preview
            $calc = calculate_customer_invoice_data($pdo, $customerId, $year, $month, $customFrom, $customTo);
            json_response($calc);
        }

        // Bulk preview for all active customers
        $stmtAll = $pdo->prepare(
            "SELECT id FROM customers 
             WHERE is_active = 1 AND start_date <= :period_to 
             ORDER BY route_order ASC, name ASC"
        );
        $stmtAll->execute([':period_to' => $periodTo]);
        $custIds = $stmtAll->fetchAll(PDO::FETCH_COLUMN);

        $previews = [];
        $totalPayableSum = 0.00;
        $totalMilkAmountSum = 0.00;
        $readyCount = 0;
        $invoicedCount = 0;

        foreach ($custIds as $cid) {
            $calc = calculate_customer_invoice_data($pdo, (int)$cid, $year, $month, $customFrom, $customTo);
            $readyCount++;
            $totalPayableSum += $calc['total_payable'];
            $totalMilkAmountSum += $calc['milk_amount'];
            if ($calc['has_invoice']) {
                $invoicedCount++;
            }
            $previews[] = $calc;
        }

        json_response([
            'month'                 => $month,
            'year'                  => $year,
            'from_date'             => $periodFrom,
            'to_date'               => $periodTo,
            'period_label'          => $periodLabel,
            'customers'             => $previews,
            'summary'               => [
                'total_candidates'  => count($previews),
                'ready_to_generate' => $readyCount,
                'invoiced_count'    => $invoicedCount,
                'blocked_count'     => 0,
                'total_milk_amount' => round_money($totalMilkAmountSum),
                'total_payable'     => round_money($totalPayableSum)
            ]
        ]);
    }

    // 2. Single Invoice Detail by ID
    if (isset($_GET['id']) && trim($_GET['id']) !== '') {
        $id = (int)$_GET['id'];

        $sql = "SELECT i.*, c.name AS customer_name, c.mobile AS customer_mobile, c.address AS customer_address
                FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                WHERE i.id = :id
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $inv = $stmt->fetch();

        if (!$inv) {
            json_response(null, 404, false, ['message' => "Invoice with ID {$id} not found."]);
        }

        // Fetch Business Settings
        $stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        while ($s = $stmtSettings->fetch()) {
            $settings[$s['setting_key']] = $s['setting_value'];
        }

        // Fetch day-wise delivery entries for that month
        $stmtDel = $pdo->prepare(
            "SELECT delivery_date, quantity, rate, amount 
             FROM deliveries 
             WHERE customer_id = :cid AND delivery_date BETWEEN :from AND :to 
             ORDER BY delivery_date ASC"
        );
        $stmtDel->execute([
            ':cid'  => $inv['customer_id'],
            ':from' => $inv['period_from'],
            ':to'   => $inv['period_to']
        ]);
        $deliveries = $stmtDel->fetchAll();

        $dailyBreakdown = [];
        $rateGroups = [];
        foreach ($deliveries as $d) {
            $dayNum = (int)date('j', strtotime($d['delivery_date']));
            $qty = (float)$d['quantity'];
            $rate = (float)$d['rate'];
            $amt = (float)$d['amount'];

            $entry = [
                'date'     => $d['delivery_date'],
                'day'      => $dayNum,
                'quantity' => $qty,
                'rate'     => $rate,
                'amount'   => $amt
            ];
            $dailyBreakdown[$d['delivery_date']] = $entry;
            $dailyBreakdown[$dayNum] = $entry;

            if ($qty > 0) {
                $rk = sprintf('%.2f', $rate);
                if (!isset($rateGroups[$rk])) {
                    $rateGroups[$rk] = ['rate' => $rate, 'quantity' => 0.0, 'amount' => 0.0, 'days' => 0];
                }
                $rateGroups[$rk]['quantity'] += $qty;
                $rateGroups[$rk]['amount'] += $amt;
                $rateGroups[$rk]['days']++;
            }
        }

        // Fetch Payments linked to this invoice
        $stmtPay = $pdo->prepare(
            "SELECT id, amount, payment_date, mode, reference, note 
             FROM payments 
             WHERE invoice_id = :id 
             ORDER BY payment_date ASC"
        );
        $stmtPay->execute([':id' => $id]);
        $payments = $stmtPay->fetchAll();

        // Balance remaining to be collected on this bill
        $balanceDue = round_money((float)$inv['total_payable'] - (float)$inv['paid_amount']);

        json_response([
            'invoice' => [
                'id'               => (int)$inv['id'],
                'invoice_no'       => $inv['invoice_no'],
                'customer_id'      => (int)$inv['customer_id'],
                'customer_name'    => $inv['customer_name'],
                'customer_mobile'  => $inv['customer_mobile'],
                'customer_address' => $inv['customer_address'],
                'period_month'     => (int)$inv['period_month'],
                'period_year'      => (int)$inv['period_year'],
                'period_from'      => $inv['period_from'],
                'period_to'        => $inv['period_to'],
                'period_label'     => (date('Y-m-01', strtotime($inv['period_from'])) === $inv['period_from'] && date('Y-m-t', strtotime($inv['period_from'])) === $inv['period_to']) 
                                        ? date('F Y', strtotime($inv['period_from'])) 
                                        : (date('d M', strtotime($inv['period_from'])) . ' - ' . date('d M Y', strtotime($inv['period_to']))),
                'days_delivered'   => (int)$inv['days_delivered'],
                'total_qty'        => (float)$inv['total_qty'],
                'milk_amount'      => (float)$inv['milk_amount'],
                'previous_balance' => (float)$inv['previous_balance'],
                'total_payable'    => (float)$inv['total_payable'],
                'paid_amount'      => (float)$inv['paid_amount'],
                'balance_due'      => $balanceDue,
                'status'           => $inv['status'],
                'generated_at'     => $inv['generated_at'],
                'line_items'       => array_values($rateGroups),
                'daily_breakdown'  => $dailyBreakdown,
                'payments'         => $payments
            ],
            'business' => $settings
        ]);
    }

    // 3. Invoices List with Filters
    $month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;
    $customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : null;
    $status = isset($_GET['status']) && $_GET['status'] !== 'all' ? trim($_GET['status']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $where = [];
    $params = [];

    if ($month !== null) {
        $where[] = 'i.period_month = :month';
        $params[':month'] = $month;
    }
    if ($year !== null) {
        $where[] = 'i.period_year = :year';
        $params[':year'] = $year;
    }
    if ($customerId !== null) {
        $where[] = 'i.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
    if ($status !== null) {
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(c.name LIKE :s_name OR c.mobile LIKE :s_mobile OR i.invoice_no LIKE :s_inv)';
        $sp = '%' . $search . '%';
        $params[':s_name'] = $sp;
        $params[':s_mobile'] = $sp;
        $params[':s_inv'] = $sp;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT i.*, c.name AS customer_name, c.mobile AS customer_mobile, c.address AS customer_address
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            {$whereClause}
            ORDER BY i.period_year DESC, i.period_month DESC, i.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Map all invoices per customer to detect newer invoices
    $stmtAllInvs = $pdo->query(
        "SELECT id, invoice_no, customer_id, period_year, period_month 
         FROM invoices 
         ORDER BY period_year ASC, period_month ASC, id ASC"
    );
    $customerInvoices = [];
    while ($invRow = $stmtAllInvs->fetch()) {
        $cid = (int)$invRow['customer_id'];
        $customerInvoices[$cid][] = $invRow;
    }

    $invoices = [];
    $totalAmountSum = 0.00;
    $totalPaidSum = 0.00;
    $totalPendingSum = 0.00;

    foreach ($rows as $row) {
        $payable = (float)$row['total_payable'];
        $paid = (float)$row['paid_amount'];
        $pending = max(0.00, round_money($payable - $paid));

        $totalAmountSum += $payable;
        $totalPaidSum += $paid;
        $totalPendingSum += $pending;

        $cid = (int)$row['customer_id'];
        $curYear = (int)$row['period_year'];
        $curMonth = (int)$row['period_month'];
        $carriedForwardTo = null;

        if (isset($customerInvoices[$cid])) {
            foreach ($customerInvoices[$cid] as $otherInv) {
                $oYear = (int)$otherInv['period_year'];
                $oMonth = (int)$otherInv['period_month'];
                if ($oYear > $curYear || ($oYear === $curYear && $oMonth > $curMonth)) {
                    $carriedForwardTo = $otherInv['invoice_no'];
                    break;
                }
            }
        }

        $invoices[] = [
            'id'                 => (int)$row['id'],
            'invoice_no'         => $row['invoice_no'],
            'customer_id'        => $cid,
            'customer_name'      => $row['customer_name'],
            'customer_mobile'    => $row['customer_mobile'],
            'period_month'       => (int)$row['period_month'],
            'period_year'        => (int)$row['period_year'],
            'period_label'       => (date('Y-m-01', strtotime($row['period_from'])) === $row['period_from'] && date('Y-m-t', strtotime($row['period_from'])) === $row['period_to']) 
                                    ? date('F Y', strtotime($row['period_from'])) 
                                    : (date('d M', strtotime($row['period_from'])) . ' - ' . date('d M Y', strtotime($row['period_to']))),
            'period_from'        => $row['period_from'],
            'period_to'          => $row['period_to'],
            'days_delivered'     => (int)$row['days_delivered'],
            'total_qty'          => (float)$row['total_qty'],
            'milk_amount'        => (float)$row['milk_amount'],
            'previous_balance'   => (float)$row['previous_balance'],
            'total_payable'      => $payable,
            'paid_amount'        => $paid,
            'balance_due'        => $pending,
            'status'             => $row['status'],
            'carried_forward_to' => ($pending > 0) ? $carriedForwardTo : null,
            'generated_at'       => $row['generated_at']
        ];
    }

    // Single source of truth for outstanding total: strictly from ledger
    $stmtLedgerBal = $pdo->query(
        "SELECT IFNULL(SUM(balance), 0.00) FROM (
            SELECT SUM(debit) - SUM(credit) AS balance FROM ledger GROUP BY customer_id HAVING balance > 0
         ) t"
    );
    $ledgerPendingDues = round_money((float)$stmtLedgerBal->fetchColumn());

    json_response([
        'invoices' => $invoices,
        'summary'  => [
            'total_count'          => count($invoices),
            'total_payable'        => round_money($totalAmountSum),
            'total_paid'           => round_money($totalPaidSum),
            'total_pending'        => round_money($totalPendingSum),
            'total_ledger_pending' => $ledgerPendingDues
        ]
    ]);
}

// -------------------------------------------------------------
// POST: Generate Invoices (Single or Bulk, by Date Range or Month)
// -------------------------------------------------------------
if ($method === 'POST') {
    $body = read_json_body();

    $customFrom = trim($body['from_date'] ?? ($body['start_date'] ?? ($body['period_from'] ?? '')));
    $customTo = trim($body['to_date'] ?? ($body['end_date'] ?? ($body['period_to'] ?? '')));
    $missingDaysAction = $body['missing_days'] ?? 'ignore'; // 'fill' or 'ignore'

    if ($customFrom !== '' && $customTo !== '') {
        if (!validate_date($customFrom) || !validate_date($customTo) || $customFrom > $customTo) {
            json_response(null, 422, false, ['message' => 'Invalid date range for invoice generation. Expected YYYY-MM-DD.']);
        }
        $periodFrom = $customFrom;
        $periodTo = $customTo;
        $year = (int)date('Y', strtotime($periodFrom));
        $month = (int)date('n', strtotime($periodFrom));
    } else {
        $month = isset($body['month']) ? (int)$body['month'] : 0;
        $year = isset($body['year']) ? (int)$body['year'] : 0;

        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2099) {
            json_response(null, 422, false, ['message' => 'Invalid month or year for invoice generation.']);
        }

        $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        $periodFrom = sprintf('%04d-%02d-01', $year, $month);
        $periodTo = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
    }

    $isBulk = !empty($body['all']);

    // Gather customers to bill
    $customerIds = [];
    if ($isBulk) {
        $stmtAll = $pdo->prepare(
            "SELECT id FROM customers 
             WHERE is_active = 1 AND start_date <= :period_to 
             ORDER BY route_order ASC, name ASC"
        );
        $stmtAll->execute([':period_to' => $periodTo]);
        $customerIds = $stmtAll->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $cid = (int)($body['customer_id'] ?? 0);
        if ($cid <= 0) {
            json_response(null, 422, false, ['message' => 'Please select a valid customer.']);
        }
        $customerIds = [$cid];
    }

    $createdInvoices = [];
    $skippedCustomers = [];

    foreach ($customerIds as $cid) {
        $cid = (int)$cid;

        try {
            $data = calculate_customer_invoice_data($pdo, $cid, $year, $month, $periodFrom, $periodTo);

            // Transaction per customer invoice
            $pdo->beginTransaction();

            // 1. If missing_days action is 'fill', insert committed deliveries for missing days
            if ($missingDaysAction === 'fill' && !empty($data['unrecorded_dates'])) {
                $committedQty = $data['committed_qty'];
                $stmtInsertDel = $pdo->prepare(
                    "INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount)
                     VALUES (:cid, :date, :qty, :rate, :amount)
                     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), rate = VALUES(rate), amount = VALUES(amount)"
                );

                foreach ($data['unrecorded_dates'] as $mDate) {
                    $rate = get_rate_for($pdo, $cid, $mDate);
                    $amt = round_money($committedQty * $rate);
                    $stmtInsertDel->execute([
                        ':cid'    => $cid,
                        ':date'   => $mDate,
                        ':qty'    => $committedQty,
                        ':rate'   => $rate,
                        ':amount' => $amt
                    ]);
                }

                // Recalculate invoice numbers with newly filled rows
                $data = calculate_customer_invoice_data($pdo, $cid, $year, $month, $periodFrom, $periodTo);
            }

            // Only deliveries with quantity > 0 are billed
            $milkAmount = $data['milk_amount'];
            $daysDelivered = $data['days_delivered'];
            $totalQty = $data['total_qty'];
            $prevBalance = $data['previous_balance'];
            $totalPayable = $data['total_payable'];

            // Status determination
            // If total_payable <= 0, advance credit completely covers this bill!
            $status = ($totalPayable <= 0) ? 'paid' : 'unpaid';
            $paidAmount = ($totalPayable <= 0) ? $milkAmount : 0.00;

            // Generate next invoice number
            $invoiceNo = generate_invoice_no($pdo, $year, $month);

            // 2. Insert Invoice
            $sqlInv = "INSERT INTO invoices (
                           invoice_no, customer_id, period_month, period_year, 
                           period_from, period_to, days_delivered, total_qty, 
                           milk_amount, previous_balance, total_payable, paid_amount, status
                       ) VALUES (
                           :inv_no, :cid, :month, :year,
                           :from, :to, :days, :qty,
                           :milk_amt, :prev_bal, :payable, :paid_amt, :status
                       )";
            $stmtInv = $pdo->prepare($sqlInv);
            $stmtInv->execute([
                ':inv_no'   => $invoiceNo,
                ':cid'      => $cid,
                ':month'    => $month,
                ':year'     => $year,
                ':from'     => $periodFrom,
                ':to'       => $periodTo,
                ':days'     => $daysDelivered,
                ':qty'      => $totalQty,
                ':milk_amt' => $milkAmount,
                ':prev_bal' => $prevBalance,
                ':payable'  => $totalPayable,
                ':paid_amt' => $paidAmount,
                ':status'   => $status
            ]);

            $newInvoiceId = (int)$pdo->lastInsertId();

            // 3. Link delivery rows by setting invoice_id
            $stmtLock = $pdo->prepare(
                "UPDATE deliveries 
                 SET invoice_id = :inv_id 
                 WHERE customer_id = :cid AND delivery_date BETWEEN :from AND :to"
            );
            $stmtLock->execute([
                ':inv_id' => $newInvoiceId,
                ':cid'    => $cid,
                ':from'   => $periodFrom,
                ':to'     => $periodTo
            ]);

            // 4. Add Ledger Debit of milk_amount
            $periodLabel = ($periodFrom === $periodTo) 
                ? date('d M Y', strtotime($periodFrom)) 
                : (date('d M', strtotime($periodFrom)) . ' - ' . date('d M Y', strtotime($periodTo)));
            $ledgerDesc = sprintf("Invoice #%s (%s)", $invoiceNo, $periodLabel);
            $stmtLedger = $pdo->prepare(
                "INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit)
                 VALUES (:cid, :entry_date, 'invoice', :ref_id, :desc, :debit, 0.00)"
            );
            $stmtLedger->execute([
                ':cid'        => $cid,
                ':entry_date' => $periodTo,
                ':ref_id'     => $newInvoiceId,
                ':desc'       => $ledgerDesc,
                ':debit'      => $milkAmount
            ]);

            $pdo->commit();

            $createdInvoices[] = [
                'id'            => $newInvoiceId,
                'invoice_no'    => $invoiceNo,
                'customer_id'   => $cid,
                'customer_name' => $data['customer_name'],
                'period_from'   => $periodFrom,
                'period_to'     => $periodTo,
                'total_payable' => $totalPayable,
                'status'        => $status
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (!$isBulk) {
                throw $e;
            }

            $skippedCustomers[] = [
                'customer_id' => $cid,
                'reason'      => $e->getMessage()
            ];
        }
    }

    if (!$isBulk && empty($createdInvoices) && !empty($skippedCustomers)) {
        json_response(null, 422, false, [
            'message' => $skippedCustomers[0]['reason'] ?? 'Failed to generate invoice.'
        ]);
    }

    json_response([
        'created_count' => count($createdInvoices),
        'skipped_count' => count($skippedCustomers),
        'created'       => $createdInvoices,
        'skipped'       => $skippedCustomers
    ], 201);
}

// -------------------------------------------------------------
// DELETE: Delete / Void Invoice
// Reverses ledger debit, unlocks deliveries (invoice_id = NULL),
// unlinks any associated payments (invoice_id = NULL), and deletes invoice.
// -------------------------------------------------------------
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        $body = read_json_body();
        $id = (int)($body['id'] ?? 0);
    }

    if ($id <= 0) {
        json_response(null, 400, false, ['message' => 'Missing or invalid invoice ID for voiding.']);
    }

    $stmtInv = $pdo->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
    $stmtInv->execute([':id' => $id]);
    $invoice = $stmtInv->fetch();

    if (!$invoice) {
        json_response(null, 404, false, ['message' => "Invoice with ID {$id} not found."]);
    }

    // Begin atomic void transaction
    $pdo->beginTransaction();

    try {
        // 1. Unlink any payments recorded against this invoice
        $stmtUnlinkPay = $pdo->prepare("UPDATE payments SET invoice_id = NULL WHERE invoice_id = :id");
        $stmtUnlinkPay->execute([':id' => $id]);

        // 2. Unlock deliveries
        $stmtUnlock = $pdo->prepare("UPDATE deliveries SET invoice_id = NULL WHERE invoice_id = :id");
        $stmtUnlock->execute([':id' => $id]);

        // 3. Remove ledger debit
        $stmtDelLedger = $pdo->prepare("DELETE FROM ledger WHERE type = 'invoice' AND ref_id = :id");
        $stmtDelLedger->execute([':id' => $id]);

        // 4. Delete invoice
        $stmtDelInv = $pdo->prepare("DELETE FROM invoices WHERE id = :id");
        $stmtDelInv->execute([':id' => $id]);

        $pdo->commit();

        json_response([
            'voided_invoice_id' => $id,
            'invoice_no'        => $invoice['invoice_no'],
            'message'           => "Invoice {$invoice['invoice_no']} successfully deleted. Deliveries unlocked and ledger updated."
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
