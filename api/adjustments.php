<?php
/**
 * MilkBook - Adjustments API
 * POST { customer_id, type: 'writeoff'|'discount'|'refund'|'manual', amount, reason, date? }
 * Write-off or discount reduces pending dues (ledger credit)
 * Refund returns advance credit (ledger debit)
 * Auditable in ledger with required reason
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = get_db_connection();
check_auth();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $input = read_json_body();

    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
    $type = isset($input['type']) ? strtolower(trim($input['type'])) : '';
    $amount = isset($input['amount']) ? round_money((float)$input['amount']) : 0.00;
    $reason = isset($input['reason']) ? trim($input['reason']) : '';
    $entryDate = isset($input['entry_date']) && trim($input['entry_date']) !== ''
        ? trim($input['entry_date'])
        : date('Y-m-d');
    $direction = isset($input['direction']) ? strtolower(trim($input['direction'])) : null; // For manual adjustments

    $errors = [];
    if ($customerId <= 0) {
        $errors['customer_id'] = 'Valid customer ID is required.';
    }
    $allowedTypes = ['writeoff', 'discount', 'refund', 'manual'];
    if (!in_array($type, $allowedTypes, true)) {
        $errors['type'] = 'Invalid type. Allowed: writeoff, discount, refund, manual.';
    }
    if ($amount <= 0) {
        $errors['amount'] = 'Amount must be greater than 0.';
    }
    if ($reason === '') {
        $errors['reason'] = 'Reason is required for all adjustments.';
    }
    if (!validate_date($entryDate)) {
        $errors['entry_date'] = 'Invalid date format. Use YYYY-MM-DD.';
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

    // Determine debit vs credit
    // writeoff or discount: reduces pending dues -> CREDIT
    // refund: returns advance credit -> DEBIT
    // manual: if direction specified ('debit' or 'credit'), else default credit
    $debit = 0.00;
    $credit = 0.00;

    if ($type === 'writeoff' || $type === 'discount') {
        $credit = $amount;
    } elseif ($type === 'refund') {
        $debit = $amount;
    } elseif ($type === 'manual') {
        if ($direction === 'debit') {
            $debit = $amount;
        } else {
            $credit = $amount;
        }
    }

    $typeLabels = [
        'writeoff' => 'Write-off',
        'discount' => 'Discount',
        'refund'   => 'Refund',
        'manual'   => 'Manual Adjustment'
    ];
    $label = $typeLabels[$type] ?? 'Adjustment';
    $description = sprintf("Adjustment (%s): %s", $label, $reason);

    $pdo->beginTransaction();

    try {
        $stmtLedger = $pdo->prepare(
            "INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit)
             VALUES (:cid, :edate, 'adjustment', NULL, :desc, :debit, :credit)"
        );
        $stmtLedger->execute([
            ':cid'    => $customerId,
            ':edate'  => $entryDate,
            ':desc'   => $description,
            ':debit'  => $debit,
            ':credit' => $credit
        ]);
        $ledgerId = (int)$pdo->lastInsertId();

        // Calculate new balance
        $balanceAfter = get_customer_ledger_balance($pdo, $customerId);

        $pdo->commit();

        json_response([
            'ledger_id'     => $ledgerId,
            'customer_id'   => $customerId,
            'type'          => $type,
            'amount'        => $amount,
            'direction'     => $debit > 0 ? 'debit' : 'credit',
            'balance_after' => $balanceAfter,
            'message'       => sprintf("%s of ₹%s recorded successfully. New balance: ₹%s", $label, number_format($amount, 2), number_format($balanceAfter, 2))
        ], 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
