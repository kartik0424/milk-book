<?php
/**
 * MilkBook - Helper Functions
 * JSON response envelope, request parsing, validation, money rounding,
 * rate resolution, and global exception/error handling.
 */

// Global Exception Handler: ensures we NEVER leak stack traces to client
set_exception_handler(function (Throwable $e) {
    error_log(sprintf(
        "[MilkBook Error] %s in %s:%d\nTrace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    $statusCode = 500;
    if ($e instanceof InvalidArgumentException) {
        $statusCode = 422;
    }

    json_response(null, $statusCode, false, [
        'message' => $e->getMessage() ?: 'Internal server error occurred.',
        'fields'  => []
    ]);
});

// Convert PHP errors into ErrorException for uniform handling
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Output JSON response with standard envelope.
 * 
 * Success envelope:
 * { "success": true, "data": ... }
 * 
 * Error envelope:
 * { "success": false, "error": { "message": "...", "fields": { ... } } }
 *
 * @param mixed $data
 * @param int $statusCode
 * @param bool $success
 * @param array|null $error ['message' => string, 'fields' => array]
 * @return never
 */
function json_response(mixed $data = null, int $statusCode = 200, bool $success = true, ?array $error = null): never {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    $response = ['success' => $success];

    if ($success) {
        $response['data'] = $data;
    } else {
        $response['error'] = [
            'message' => $error['message'] ?? 'An error occurred.',
            'fields'  => $error['fields'] ?? []
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read and decode JSON request body.
 *
 * @return array
 */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_response(null, 400, false, [
            'message' => 'Malformed JSON body: ' . json_last_error_msg(),
            'fields'  => []
        ]);
    }

    return is_array($data) ? $data : [];
}

/**
 * Round money to 2 decimal places to prevent floating-point drift.
 *
 * @param float|int|string $amount
 * @return float
 */
function round_money(float|int|string $amount): float {
    return round((float)$amount, 2);
}

/**
 * Validate and normalize a 10-digit Indian mobile number.
 * Accepts formats like:
 * - "9876543210"
 * - "+91 98765 43210"
 * - "09876543210"
 * - "+919876543210"
 *
 * @param string $mobile
 * @return string|false Normalized 10-digit number or false if invalid
 */
function validate_mobile(string $mobile): string|false {
    // Strip non-digit characters except leading plus
    $cleaned = preg_replace('/[^\d+]/', '', trim($mobile));

    // Strip leading +91 or 91 if it results in a 10 digit number
    if (str_starts_with($cleaned, '+91')) {
        $cleaned = substr($cleaned, 3);
    } elseif (strlen($cleaned) === 12 && str_starts_with($cleaned, '91')) {
        $cleaned = substr($cleaned, 2);
    } elseif (strlen($cleaned) === 11 && str_starts_with($cleaned, '0')) {
        $cleaned = substr($cleaned, 1);
    }

    // Must be exactly 10 digits starting with 6, 7, 8, or 9
    if (preg_match('/^[6-9]\d{9}$/', $cleaned)) {
        return $cleaned;
    }

    return false;
}

/**
 * Validate committed milk quantity.
 * Must be > 0 and an exact multiple of 0.25 (e.g. 0.25, 0.50, 0.75, 1.00, 1.25, etc.)
 *
 * @param float|int|string $qty
 * @return bool
 */
function validate_qty(float|int|string $qty): bool {
    $val = (float)$qty;
    if ($val <= 0 || $val > 999.99) {
        return false;
    }

    // Multiply by 100 and round to integer to check divisibility by 25 (0.25 * 100 = 25)
    $cents = (int)round($val * 100);
    return ($cents % 25) === 0;
}

/**
 * Validate YYYY-MM-DD date.
 *
 * @param string $date
 * @return bool
 */
function validate_date(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = explode('-', $date);
    return checkdate((int)$m, (int)$d, (int)$y);
}

/**
 * Determine the effective milk rate for a customer on a given date.
 * If customer has a custom_rate set (> 0), returns it.
 * Otherwise, queries milk_rates for the latest rate with effective_from <= $date.
 * Throws Exception if no rate can be resolved.
 *
 * @param PDO $pdo
 * @param int $customerId
 * @param string $date YYYY-MM-DD
 * @return float
 * @throws Exception
 */
function get_rate_for(PDO $pdo, int $customerId, string $date): float {
    // 1. Check if customer has a custom rate
    $stmt = $pdo->prepare('SELECT custom_rate FROM customers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch();

    if ($customer === false) {
        throw new Exception("Customer with ID {$customerId} not found.");
    }

    if ($customer['custom_rate'] !== null && (float)$customer['custom_rate'] > 0) {
        return round_money((float)$customer['custom_rate']);
    }

    // 2. Fetch default rate active on or before the given date
    $stmt = $pdo->prepare(
        'SELECT rate_per_litre FROM milk_rates 
         WHERE effective_from <= :delivery_date 
         ORDER BY effective_from DESC, id DESC 
         LIMIT 1'
    );
    $stmt->execute([':delivery_date' => $date]);
    $rateRow = $stmt->fetch();

    if ($rateRow !== false && isset($rateRow['rate_per_litre'])) {
        return round_money((float)$rateRow['rate_per_litre']);
    }

    throw new Exception("No active milk rate found on or before {$date}. Please configure a rate in Settings.");
}

/**
 * Validate delivery quantity.
 * Must be >= 0 (0 represents skipped / no delivery), <= 20, and an exact multiple of 0.25.
 *
 * @param float|int|string $qty
 * @return bool
 */
function validate_delivery_qty(float|int|string $qty): bool {
    if (!is_numeric($qty)) {
        return false;
    }
    $val = (float)$qty;
    if ($val < 0 || $val > 20.00) {
        return false;
    }

    $cents = (int)round($val * 100);
    return ($cents % 25) === 0;
}

/**
 * Get all dates in a given month that are on or after the customer's start_date
 * but have NO recorded delivery row. Reusable in Step 3 (Invoicing).
 *
 * @param PDO $pdo
 * @param int $customerId
 * @param int $year
 * @param int $month
 * @return array Array of YYYY-MM-DD date strings
 */
function get_unrecorded_dates(PDO $pdo, int $customerId, int $year, int $month): array {
    $stmtCust = $pdo->prepare('SELECT start_date FROM customers WHERE id = :id LIMIT 1');
    $stmtCust->execute([':id' => $customerId]);
    $cust = $stmtCust->fetch();

    if (!$cust) {
        throw new Exception("Customer with ID {$customerId} not found.");
    }

    $startDate = $cust['start_date'];
    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

    // Fetch recorded delivery dates for this customer in this month
    $stmtDel = $pdo->prepare(
        'SELECT delivery_date FROM deliveries 
         WHERE customer_id = :cid AND delivery_date BETWEEN :start AND :end'
    );
    $stmtDel->execute([
        ':cid'   => $customerId,
        ':start' => $monthStart,
        ':end'   => $monthEnd
    ]);
    $recordedRows = $stmtDel->fetchAll(PDO::FETCH_COLUMN);
    $recordedSet = array_flip($recordedRows);

    $unrecorded = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if ($dateStr >= $startDate && !isset($recordedSet[$dateStr])) {
            $unrecorded[] = $dateStr;
        }
    }

    return $unrecorded;
}

/**
 * Get all dates in an arbitrary date range that are on or after the customer's start_date
 * but have NO recorded delivery row.
 *
 * @param PDO $pdo
 * @param int $customerId
 * @param string $startDateStr YYYY-MM-DD
 * @param string $endDateStr YYYY-MM-DD
 * @return array Array of YYYY-MM-DD date strings
 */
function get_unrecorded_dates_range(PDO $pdo, int $customerId, string $startDateStr, string $endDateStr): array {
    $stmtCust = $pdo->prepare('SELECT start_date FROM customers WHERE id = :id LIMIT 1');
    $stmtCust->execute([':id' => $customerId]);
    $cust = $stmtCust->fetch();

    if (!$cust) {
        throw new Exception("Customer with ID {$customerId} not found.");
    }

    $custStartDate = $cust['start_date'];

    // Fetch recorded delivery dates for this customer in this range
    $stmtDel = $pdo->prepare(
        'SELECT delivery_date FROM deliveries 
         WHERE customer_id = :cid AND delivery_date BETWEEN :start AND :end'
    );
    $stmtDel->execute([
        ':cid'   => $customerId,
        ':start' => $startDateStr,
        ':end'   => $endDateStr
    ]);
    $recordedRows = $stmtDel->fetchAll(PDO::FETCH_COLUMN);
    $recordedSet = array_flip($recordedRows);

    $unrecorded = [];
    $current = strtotime($startDateStr);
    $end = strtotime($endDateStr);

    while ($current <= $end) {
        $dateStr = date('Y-m-d', $current);
        if ($dateStr >= $custStartDate && !isset($recordedSet[$dateStr])) {
            $unrecorded[] = $dateStr;
        }
        $current = strtotime('+1 day', $current);
    }

    return $unrecorded;
}

/**
 * Calculate customer balance strictly from ledger: SUM(debit) - SUM(credit).
 * Positive = pending dues, Negative = advance credit.
 *
 * @param PDO $pdo
 * @param int $customerId
 * @return float
 */
function get_customer_ledger_balance(PDO $pdo, int $customerId): float {
    $stmt = $pdo->prepare(
        "SELECT IFNULL(SUM(debit) - SUM(credit), 0.00) AS balance 
         FROM ledger 
         WHERE customer_id = :cid"
    );
    $stmt->execute([':cid' => $customerId]);
    return round_money((float)$stmt->fetchColumn());
}

/**
 * Recompute invoice paid_amount and status from attached payments.
 *
 * Status rules:
 * - unpaid: paid == 0 (when total_payable > 0)
 * - partial: 0 < paid < total_payable
 * - paid: paid == total_payable (or total_payable <= 0 and paid >= 0)
 * - overpaid: paid > total_payable
 *
 * @param PDO $pdo
 * @param int $invoiceId
 * @return array
 */
function recompute_invoice_status(PDO $pdo, int $invoiceId): array {
    $stmtInv = $pdo->prepare("SELECT total_payable FROM invoices WHERE id = :id LIMIT 1");
    $stmtInv->execute([':id' => $invoiceId]);
    $inv = $stmtInv->fetch();
    if (!$inv) {
        throw new Exception("Invoice ID {$invoiceId} not found.");
    }
    $payable = round_money((float)$inv['total_payable']);

    $stmtPay = $pdo->prepare("SELECT IFNULL(SUM(amount), 0.00) FROM payments WHERE invoice_id = :id");
    $stmtPay->execute([':id' => $invoiceId]);
    $paid = round_money((float)$stmtPay->fetchColumn());

    if ($payable <= 0) {
        $status = ($paid > 0) ? 'overpaid' : 'paid';
    } else {
        if ($paid <= 0) {
            $status = 'unpaid';
        } elseif ($paid < $payable) {
            $status = 'partial';
        } elseif ($paid == $payable) {
            $status = 'paid';
        } else {
            $status = 'overpaid';
        }
    }

    $stmtUpd = $pdo->prepare(
        "UPDATE invoices 
         SET paid_amount = :paid, status = :status 
         WHERE id = :id"
    );
    $stmtUpd->execute([
        ':paid'   => $paid,
        ':status' => $status,
        ':id'     => $invoiceId
    ]);

    return [
        'invoice_id'    => $invoiceId,
        'paid_amount'   => $paid,
        'status'        => $status,
        'total_payable' => $payable
    ];
}

/**
 * Check if the user is authenticated when AUTH_ENABLED is true.
 * Also verifies CSRF token on POST/PUT/DELETE/PATCH.
 */
function check_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!defined('AUTH_ENABLED') || !AUTH_ENABLED) {
        return; // Authentication disabled
    }

    if (empty($_SESSION['auth_user'])) {
        json_response(null, 401, false, ['message' => 'Unauthorized. Please login to continue.']);
    }

    // CSRF verification on state-modifying requests
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? $headers['X-CSRF-Token'] 
            ?? $headers['x-csrf-token'] 
            ?? ($_POST['csrf_token'] ?? null);

        if (!$token) {
            $json = read_json_body();
            if (isset($json['csrf_token'])) {
                $token = $json['csrf_token'];
            }
        }

        if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
            json_response(null, 403, false, ['message' => 'CSRF verification failed. Invalid or missing token.']);
        }
    }
}

/**
 * Get or generate CSRF token for the active session.
 */
function get_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
