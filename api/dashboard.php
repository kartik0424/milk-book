<?php
/**
 * MilkBook - Dashboard Analytics API
 * Provides:
 * - Today's litres, delivery recorded progress & sheet status
 * - Active customers count
 * - Month's billed and collected totals
 * - Total pending dues and total advance held (strictly from the ledger)
 * - Top pending customers with WhatsApp reminder deep-links
 * - Advance credit customers list
 * - Monthly report table and CSV export
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = get_db_connection();
check_auth();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET') {
    json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
}

$today = date('Y-m-d');
$currentMonth = (int)date('n');
$currentYear = (int)date('Y');
$monthStart = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
$monthEnd = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, (int)date('t', strtotime($monthStart)));

// 1. Settings (UPI ID, Business Name, etc.)
$stmtSettings = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings = [];
while ($s = $stmtSettings->fetch()) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
$upiId = $settings['upi_id'] ?? '';
$businessName = $settings['business_name'] ?? 'MilkBook Dairy';

// 2. Customer Counts & Today's Progress
$stmtActive = $pdo->prepare(
    "SELECT COUNT(*) FROM customers WHERE is_active = 1 AND start_date <= :today"
);
$stmtActive->execute([':today' => $today]);
$activeCustomersCount = (int)$stmtActive->fetchColumn();

$stmtCommitted = $pdo->prepare(
    "SELECT IFNULL(SUM(committed_qty), 0.00) FROM customers WHERE is_active = 1 AND start_date <= :today"
);
$stmtCommitted->execute([':today' => $today]);
$committedLitresToday = round((float)$stmtCommitted->fetchColumn(), 2);

$stmtDelToday = $pdo->prepare(
    "SELECT COUNT(*) AS recorded_count, IFNULL(SUM(quantity), 0.00) AS delivered_litres 
     FROM deliveries 
     WHERE delivery_date = :today"
);
$stmtDelToday->execute([':today' => $today]);
$delToday = $stmtDelToday->fetch();
$recordedTodayCount = (int)$delToday['recorded_count'];
$deliveredLitresToday = round((float)$delToday['delivered_litres'], 2);

$todaySheetSaved = ($activeCustomersCount > 0 && $recordedTodayCount >= $activeCustomersCount);

// 3. Ledger Balances Single Source of Truth
// Calculate balance per customer strictly from ledger: SUM(debit) - SUM(credit)
$stmtLedgerAll = $pdo->query(
    "SELECT c.id, c.name, c.mobile, c.address,
            IFNULL(SUM(l.debit), 0.00) - IFNULL(SUM(l.credit), 0.00) AS balance
     FROM customers c
     LEFT JOIN ledger l ON l.customer_id = c.id
     WHERE c.is_active = 1
     GROUP BY c.id, c.name, c.mobile, c.address"
);
$ledgerRows = $stmtLedgerAll->fetchAll();

$totalPendingDues = 0.00;
$totalAdvanceHeld = 0.00;
$pendingCustomers = [];
$advanceCustomers = [];

foreach ($ledgerRows as $r) {
    $bal = round_money((float)$r['balance']);
    if ($bal > 0) {
        $totalPendingDues += $bal;

        // Compose WhatsApp reminder
        $cleanMobile = validate_mobile($r['mobile']) ?: $r['mobile'];
        $waMsg = sprintf(
            "Namaste %s ji, your milk bill pending balance with %s is ₹%s.%s Thank you!",
            $r['name'],
            $businessName,
            number_format($bal, 2),
            $upiId ? " Kindly pay via UPI to: {$upiId}." : ""
        );
        $waUrl = "https://wa.me/91" . $cleanMobile . "?text=" . urlencode($waMsg);

        $pendingCustomers[] = [
            'customer_id'   => (int)$r['id'],
            'name'          => $r['name'],
            'mobile'        => $r['mobile'],
            'address'       => $r['address'],
            'balance'       => $bal,
            'whatsapp_url'  => $waUrl,
            'whatsapp_msg'  => $waMsg
        ];
    } elseif ($bal < 0) {
        $adv = abs($bal);
        $totalAdvanceHeld += $adv;
        $advanceCustomers[] = [
            'customer_id' => (int)$r['id'],
            'name'        => $r['name'],
            'mobile'      => $r['mobile'],
            'address'     => $r['address'],
            'advance'     => $adv,
            'balance'     => $bal
        ];
    }
}

// Sort top pending debtors descending
usort($pendingCustomers, fn($a, $b) => $b['balance'] <=> $a['balance']);
// Sort advance customers descending
usort($advanceCustomers, fn($a, $b) => $b['advance'] <=> $a['advance']);

// 4. This Month's Billed and Collected
$stmtBilled = $pdo->prepare(
    "SELECT IFNULL(SUM(milk_amount), 0.00) FROM invoices 
     WHERE period_year = :year AND period_month = :month"
);
$stmtBilled->execute([':year' => $currentYear, ':month' => $currentMonth]);
$monthBilled = round_money((float)$stmtBilled->fetchColumn());

$stmtCollected = $pdo->prepare(
    "SELECT IFNULL(SUM(amount), 0.00) FROM payments 
     WHERE payment_date BETWEEN :start AND :end"
);
$stmtCollected->execute([':start' => $monthStart, ':end' => $monthEnd]);
$monthCollected = round_money((float)$stmtCollected->fetchColumn());

// 5. Monthly Report Data (for dashboard table and CSV export)
$reportMonth = isset($_GET['report_month']) ? (int)$_GET['report_month'] : $currentMonth;
$reportYear = isset($_GET['report_year']) ? (int)$_GET['report_year'] : $currentYear;
$reportStart = sprintf('%04d-%02d-01', $reportYear, $reportMonth);
$reportEnd = sprintf('%04d-%02d-%02d', $reportYear, $reportMonth, (int)date('t', strtotime($reportStart)));

$stmtMonthlyReport = $pdo->prepare(
    "SELECT c.id, c.name, c.mobile, c.committed_qty,
            IFNULL(del.total_qty, 0.00) AS delivered_litres,
            IFNULL(del.days_count, 0) AS days_delivered,
            inv.invoice_no,
            inv.milk_amount,
            inv.total_payable,
            inv.invoice_paid,
            inv.invoice_status,
            IFNULL(pay.month_paid, 0.00) AS month_payments,
            IFNULL(led.current_balance, 0.00) AS current_balance
     FROM customers c
     LEFT JOIN (
         SELECT customer_id, 
                SUM(quantity) AS total_qty,
                COUNT(CASE WHEN quantity > 0 THEN 1 END) AS days_count
         FROM deliveries
         WHERE delivery_date BETWEEN :d_start AND :d_end
         GROUP BY customer_id
     ) del ON del.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id,
                GROUP_CONCAT(invoice_no ORDER BY id ASC SEPARATOR ', ') AS invoice_no,
                SUM(milk_amount) AS milk_amount,
                SUM(total_payable) AS total_payable,
                SUM(paid_amount) AS invoice_paid,
                CASE 
                    WHEN SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) > 0 THEN 'unpaid'
                    WHEN SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) > 0 THEN 'partial'
                    WHEN COUNT(*) > 0 THEN 'paid'
                    ELSE NULL
                END AS invoice_status
         FROM invoices
         WHERE period_year = :inv_year AND period_month = :inv_month
         GROUP BY customer_id
     ) inv ON inv.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id, SUM(amount) AS month_paid
         FROM payments
         WHERE payment_date BETWEEN :p_start AND :p_end
         GROUP BY customer_id
     ) pay ON pay.customer_id = c.id
     LEFT JOIN (
         SELECT customer_id, SUM(debit) - SUM(credit) AS current_balance
         FROM ledger
         GROUP BY customer_id
     ) led ON led.customer_id = c.id
     WHERE c.is_active = 1
     ORDER BY c.route_order ASC, c.name ASC"
);

$stmtMonthlyReport->execute([
    ':d_start'   => $reportStart,
    ':d_end'     => $reportEnd,
    ':inv_year'  => $reportYear,
    ':inv_month' => $reportMonth,
    ':p_start'   => $reportStart,
    ':p_end'     => $reportEnd
]);
$monthlyReportRows = $stmtMonthlyReport->fetchAll();

$monthlyReport = [];
foreach ($monthlyReportRows as $row) {
    $monthlyReport[] = [
        'customer_id'      => (int)$row['id'],
        'name'             => $row['name'],
        'mobile'           => $row['mobile'],
        'committed_qty'    => (float)$row['committed_qty'],
        'delivered_litres' => (float)$row['delivered_litres'],
        'days_delivered'   => (int)$row['days_delivered'],
        'invoice_no'       => $row['invoice_no'],
        'billed_amount'    => $row['milk_amount'] !== null ? round_money((float)$row['milk_amount']) : 0.00,
        'total_payable'    => $row['total_payable'] !== null ? round_money((float)$row['total_payable']) : 0.00,
        'paid_amount'      => round_money((float)($row['invoice_paid'] ?? $row['month_payments'])),
        'invoice_status'   => $row['invoice_status'] ?? 'not_generated',
        'current_balance'  => round_money((float)$row['current_balance'])
    ];
}

// 6. CSV Export if requested
if (isset($_GET['export']) && strtolower($_GET['export']) === 'csv') {
    $filename = sprintf("MilkBook_Report_%04d_%02d.csv", $reportYear, $reportMonth);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Output BOM for Excel UTF-8 display
    fputs($output, "\xEF\xBB\xBF");

    // CSV Header
    fputcsv($output, ['Customer Name', 'Mobile', 'Committed Daily (L)', 'Delivered Days', 'Total Litres', 'Invoice #', 'Billed (₹)', 'Paid (₹)', 'Status', 'Current Balance (₹)']);

    foreach ($monthlyReport as $item) {
        fputcsv($output, [
            $item['name'],
            $item['mobile'],
            number_format($item['committed_qty'], 2),
            $item['days_delivered'],
            number_format($item['delivered_litres'], 2),
            $item['invoice_no'] ?: 'Not Generated',
            number_format($item['billed_amount'], 2),
            number_format($item['paid_amount'], 2),
            strtoupper($item['invoice_status']),
            number_format($item['current_balance'], 2)
        ]);
    }
    fclose($output);
    exit;
}

// 7. Normal JSON Response
header('Content-Type: application/json; charset=utf-8');
json_response([
    'today' => [
        'date'                   => $today,
        'active_customers'       => $activeCustomersCount,
        'committed_litres'       => $committedLitresToday,
        'delivered_litres'       => $deliveredLitresToday,
        'recorded_count'         => $recordedTodayCount,
        'sheet_saved'            => $todaySheetSaved,
        'pending_sheet_count'    => max(0, $activeCustomersCount - $recordedTodayCount)
    ],
    'ledger_totals' => [
        'total_pending_dues'     => round_money($totalPendingDues),
        'total_advance_held'     => round_money($totalAdvanceHeld),
        'net_outstanding'        => round_money($totalPendingDues - $totalAdvanceHeld)
    ],
    'month_totals' => [
        'period_label'           => date('F Y', strtotime($monthStart)),
        'billed_amount'          => $monthBilled,
        'collected_amount'       => $monthCollected
    ],
    'top_pending'                => array_slice($pendingCustomers, 0, 10),
    'advance_customers'          => array_slice($advanceCustomers, 0, 10),
    'monthly_report'             => [
        'year'                   => $reportYear,
        'month'                  => $reportMonth,
        'period_label'           => date('F Y', strtotime($reportStart)),
        'rows'                   => $monthlyReport
    ]
]);
