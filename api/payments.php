<?php
/**
 * MilkBook - Payments API
 * POST: Record payment atomically (ledger credit, attach latest invoice, recompute invoice status, return balance_after & friendly message)
 * GET: List payments with customer, date range, mode filters
 * DELETE: Reverse payment, reverse ledger credit, recompute invoice status
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db_connection();
check_auth();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// -------------------------------------------------------------
// GET: List Payments with Filters
// -------------------------------------------------------------
if ($method === 'GET') {
    $customerId = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' && $_GET['customer_id'] !== 'all'
        ? (int)$_GET['customer_id']
        : null;
    $fromDate = isset($_GET['from_date']) && trim($_GET['from_date']) !== '' ? trim($_GET['from_date']) : null;
    $toDate = isset($_GET['to_date']) && trim($_GET['to_date']) !== '' ? trim($_GET['to_date']) : null;
    $mode = isset($_GET['mode']) && trim($_GET['mode']) !== '' && $_GET['mode'] !== 'all' ? trim($_GET['mode']) : null;
    $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100;

    $where = [];
    $params = [];

    if ($customerId !== null) {
        $where[] = 'p.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
    if ($fromDate !== null) {
        if (!validate_date($fromDate)) {
            json_response(null, 422, false, ['message' => 'Invalid from_date format. Use YYYY-MM-DD.']);
        }
        $where[] = 'p.payment_date >= :from_date';
        $params[':from_date'] = $fromDate;
    }
    if ($toDate !== null) {
        if (!validate_date($toDate)) {
            json_response(null, 422, false, ['message' => 'Invalid to_date format. Use YYYY-MM-DD.']);
        }
        $where[] = 'p.payment_date <= :to_date';
        $params[':to_date'] = $toDate;
    }
    if ($mode !== null) {
        $validModes = ['cash', 'upi', 'bank', 'other'];
        if (!in_array(strtolower($mode), $validModes, true)) {
            json_response(null, 422, false, ['message' => 'Invalid payment mode filter.']);
        }
        $where[] = 'p.mode = :mode';
        $params[':mode'] = strtolower($mode);
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT p.*, c.name AS customer_name, c.mobile AS customer_mobile, i.invoice_no
            FROM payments p
            JOIN customers c ON c.id = p.customer_id
            LEFT JOIN invoices i ON i.id = p.invoice_id
            {$whereSql}
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $payments = [];
    $totalAmount = 0.00;

    foreach ($rows as $row) {
        $amt = (float)$row['amount'];
        $totalAmount += $amt;

        $payments[] = [
            'id'              => (int)$row['id'],
            'customer_id'     => (int)$row['customer_id'],
            'customer_name'   => $row['customer_name'],
            'customer_mobile' => $row['customer_mobile'],
            'invoice_id'      => $row['invoice_id'] ? (int)$row['invoice_id'] : null,
            'invoice_no'      => $row['invoice_no'],
            'amount'          => round_money($amt),
            'payment_date'    => $row['payment_date'],
            'mode'            => $row['mode'],
            'reference'       => $row['reference'],
            'note'            => $row['note'],
            'created_at'      => $row['created_at']
        ];
    }

    json_response([
        'payments'     => $payments,
        'count'        => count($payments),
        'total_amount' => round_money($totalAmount)
    ]);
}

// -------------------------------------------------------------
// POST: Record Payment Atomically
// -------------------------------------------------------------
if ($method === 'POST') {
    $input = read_json_body();

    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
    $amount = isset($input['amount']) ? round_money((float)$input['amount']) : 0.00;
    $paymentDate = isset($input['payment_date']) && trim($input['payment_date']) !== ''
        ? trim($input['payment_date'])
        : date('Y-m-d');
    $mode = isset($input['mode']) ? strtolower(trim($input['mode'])) : 'cash';
    $reference = isset($input['reference']) ? trim($input['reference']) : null;
    $note = isset($input['note']) ? trim($input['note']) : null;

    $errors = [];
    if ($customerId <= 0) {
        $errors['customer_id'] = 'Valid customer is required.';
    }
    if ($amount <= 0) {
        $errors['amount'] = 'Payment amount must be greater than 0.';
    }
    if (!validate_date($paymentDate)) {
        $errors['payment_date'] = 'Invalid payment date. Use YYYY-MM-DD.';
    }
    $validModes = ['cash', 'upi', 'bank', 'other'];
    if (!in_array($mode, $validModes, true)) {
        $errors['mode'] = 'Invalid mode. Allowed: cash, upi, bank, other.';
    }

    if (!empty($errors)) {
        json_response(null, 422, false, [
            'message' => 'Validation failed.',
            'fields'  => $errors
        ]);
    }

    // Verify customer exists
    $stmtCust = $pdo->prepare("SELECT id, name FROM customers WHERE id = :id LIMIT 1");
    $stmtCust->execute([':id' => $customerId]);
    $customer = $stmtCust->fetch();
    if (!$customer) {
        json_response(null, 404, false, ['message' => "Customer with ID {$customerId} not found."]);
    }

    // Find customer's latest invoice (if any)
    $stmtLatestInv = $pdo->prepare(
        "SELECT id, invoice_no, total_payable, paid_amount 
         FROM invoices 
         WHERE customer_id = :cid 
         ORDER BY period_year DESC, period_month DESC, id DESC 
         LIMIT 1"
    );
    $stmtLatestInv->execute([':cid' => $customerId]);
    $latestInv = $stmtLatestInv->fetch();
    $invoiceId = $latestInv ? (int)$latestInv['id'] : null;

    $pdo->beginTransaction();

    try {
        // 1. Insert Payment
        $stmtPay = $pdo->prepare(
            "INSERT INTO payments (customer_id, invoice_id, amount, payment_date, mode, reference, note)
             VALUES (:cid, :inv_id, :amt, :pdate, :mode, :ref, :note)"
        );
        $stmtPay->execute([
            ':cid'    => $customerId,
            ':inv_id' => $invoiceId,
            ':amt'    => $amount,
            ':pdate'  => $paymentDate,
            ':mode'   => $mode,
            ':ref'    => $reference,
            ':note'   => $note
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // 2. Add Ledger Credit
        $modeUpper = strtoupper($mode);
        $refText = $reference ? " [Ref: {$reference}]" : "";
        $invText = $latestInv ? " for #{$latestInv['invoice_no']}" : "";
        $ledgerDesc = "Payment received ({$modeUpper}){$refText}{$invText}";

        $stmtLedger = $pdo->prepare(
            "INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit)
             VALUES (:cid, :edate, 'payment', :ref_id, :desc, 0.00, :credit)"
        );
        $stmtLedger->execute([
            ':cid'     => $customerId,
            ':edate'   => $paymentDate,
            ':ref_id'  => $paymentId,
            ':desc'    => $ledgerDesc,
            ':credit'  => $amount
        ]);

        // 3. Recompute Invoice Status if attached
        $invoiceStatusInfo = null;
        if ($invoiceId !== null) {
            $invoiceStatusInfo = recompute_invoice_status($pdo, $invoiceId);
        }

        // 4. Calculate new Ledger Balance: SUM(debit) - SUM(credit)
        $balanceAfter = get_customer_ledger_balance($pdo, $customerId);

        // Commit transaction
        $pdo->commit();

        // 5. Generate human-friendly message
        if ($balanceAfter > 0) {
            $msg = sprintf("₹%s still pending; it will be added to the next bill.", number_format($balanceAfter, 2));
        } elseif ($balanceAfter < 0) {
            $msg = sprintf("₹%s extra kept as advance credit; it will be deducted from the next bill.", number_format(abs($balanceAfter), 2));
        } else {
            $msg = "All dues cleared! Customer balance is now ₹0.00.";
        }

        json_response([
            'payment_id'          => $paymentId,
            'customer_id'         => $customerId,
            'customer_name'       => $customer['name'],
            'amount'              => $amount,
            'payment_date'        => $paymentDate,
            'mode'                => $mode,
            'invoice_id'          => $invoiceId,
            'invoice_no'          => $latestInv ? $latestInv['invoice_no'] : null,
            'invoice_status'      => $invoiceStatusInfo ? $invoiceStatusInfo['status'] : null,
            'balance_after'       => $balanceAfter,
            'message'             => $msg
        ], 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// -------------------------------------------------------------
// DELETE: Reverse Payment
// -------------------------------------------------------------
if ($method === 'DELETE') {
    $paymentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($paymentId <= 0) {
        json_response(null, 422, false, ['message' => 'Valid payment ID is required.']);
    }

    $stmtPay = $pdo->prepare("SELECT * FROM payments WHERE id = :id LIMIT 1");
    $stmtPay->execute([':id' => $paymentId]);
    $payment = $stmtPay->fetch();

    if (!$payment) {
        json_response(null, 404, false, ['message' => "Payment with ID {$paymentId} not found."]);
    }

    $customerId = (int)$payment['customer_id'];
    $invoiceId = $payment['invoice_id'] ? (int)$payment['invoice_id'] : null;

    $pdo->beginTransaction();

    try {
        // 1. Delete matching ledger credit entry
        $stmtDelLedger = $pdo->prepare("DELETE FROM ledger WHERE type = 'payment' AND ref_id = :pid");
        $stmtDelLedger->execute([':pid' => $paymentId]);

        // 2. Delete payment row
        $stmtDelPay = $pdo->prepare("DELETE FROM payments WHERE id = :pid");
        $stmtDelPay->execute([':pid' => $paymentId]);

        // 3. Recompute invoice status if invoice was attached
        $invoiceStatusInfo = null;
        if ($invoiceId !== null) {
            $invoiceStatusInfo = recompute_invoice_status($pdo, $invoiceId);
        }

        // 4. Calculate new ledger balance
        $balanceAfter = get_customer_ledger_balance($pdo, $customerId);

        $pdo->commit();

        json_response([
            'deleted_payment_id' => $paymentId,
            'customer_id'        => $customerId,
            'balance_after'      => $balanceAfter,
            'invoice_status'     => $invoiceStatusInfo ? $invoiceStatusInfo['status'] : null,
            'message'            => sprintf("Payment of ₹%s reversed successfully.", number_format((float)$payment['amount'], 2))
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
