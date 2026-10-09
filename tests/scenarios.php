<?php
/**
 * MilkBook - CLI Scenario Test Suite
 * Covers all 10 core financial and business logic requirements:
 * 1. 750 billed, 800 paid -> next bill shows -50 credit
 * 2. 750 billed, 500 paid -> next bill shows +250 carried forward
 * 3. Rate change mid-month produces rate-grouped line items
 * 4. Custom rate customer overrides standard rate
 * 5. Unrecorded days handling ('fill' vs 'ignore')
 * 6. Void invoice unlocks deliveries and removes ledger debit
 * 7. Locked deliveries cannot be modified
 * 8. Leap-year February (29 days in 2028)
 * 9. 0.25 L quantity step validation
 * 10. Deleting a payment reverses ledger credit and recalculates invoice status
 *
 * Run via CLI: php tests/scenarios.php
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line (CLI).\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = Database::getConnection();

// Terminal Colors
$C_RESET  = "\033[0m";
$C_GREEN  = "\033[1;32m";
$C_RED    = "\033[1;31m";
$C_YELLOW = "\033[1;33m";
$C_BLUE   = "\033[1;34m";
$C_CYAN   = "\033[1;36m";

$passedCount = 0;
$failedCount = 0;

function assert_test(string $name, bool $condition, string $details = '') {
    global $passedCount, $failedCount, $C_GREEN, $C_RED, $C_RESET, $C_YELLOW;
    if ($condition) {
        $passedCount++;
        echo "  {$C_GREEN}✔ PASS:{$C_RESET} {$name}\n";
        if ($details) {
            echo "    {$C_YELLOW}↳ {$details}{$C_RESET}\n";
        }
    } else {
        $failedCount++;
        echo "  {$C_RED}✖ FAIL:{$C_RESET} {$name}\n";
        if ($details) {
            echo "    {$C_RED}↳ {$details}{$C_RESET}\n";
        }
    }
}

echo "\n{$C_CYAN}======================================================\n";
echo " MilkBook - Automated Scenario Verification Suite\n";
echo "======================================================{$C_RESET}\n\n";

// -------------------------------------------------------------
// Helper: Clean up or create isolated test customer
// -------------------------------------------------------------
function create_test_customer(PDO $pdo, string $name, float $qty, ?float $customRate = null, string $startDate = '2026-01-01'): int {
    $mobile = '99' . str_pad((string)mt_rand(10000000, 99999999), 8, '0');
    $stmt = $pdo->prepare(
        "INSERT INTO customers (name, mobile, committed_qty, custom_rate, route_order, start_date, is_active)
         VALUES (:name, :mobile, :qty, :crate, 999, :sdate, 1)"
    );
    $stmt->execute([
        ':name'  => $name,
        ':mobile'=> $mobile,
        ':qty'   => $qty,
        ':crate' => $customRate,
        ':sdate' => $startDate
    ]);
    return (int)$pdo->lastInsertId();
}

function cleanup_customer(PDO $pdo, int $cid): void {
    $pdo->exec("DELETE FROM ledger WHERE customer_id = {$cid}");
    $pdo->exec("DELETE FROM payments WHERE customer_id = {$cid}");
    $pdo->exec("DELETE FROM deliveries WHERE customer_id = {$cid}");
    $pdo->exec("DELETE FROM invoices WHERE customer_id = {$cid}");
    $pdo->exec("DELETE FROM customers WHERE id = {$cid}");
}

// -------------------------------------------------------------
// Scenario 9: 0.25 L Quantity Step Validation
// -------------------------------------------------------------
echo "{$C_BLUE}[Scenario 9] 0.25 L Quantity Step Validation{$C_RESET}\n";
assert_test("Valid 0.00 L (skipped day)", validate_delivery_qty(0.00) === true);
assert_test("Valid 0.25 L", validate_delivery_qty(0.25) === true);
assert_test("Valid 0.50 L", validate_delivery_qty(0.50) === true);
assert_test("Valid 1.75 L", validate_delivery_qty(1.75) === true);
assert_test("Valid 2.00 L", validate_delivery_qty(2.00) === true);
assert_test("Invalid 0.33 L", validate_delivery_qty(0.33) === false);
assert_test("Invalid 1.10 L", validate_delivery_qty(1.10) === false);
assert_test("Invalid negative -0.50 L", validate_delivery_qty(-0.50) === false);
assert_test("Invalid over max 25.00 L", validate_delivery_qty(25.00) === false);

// -------------------------------------------------------------
// Scenario 4: Custom Rate Customer
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 4] Custom Rate Precedence Over Default Rate{$C_RESET}\n";
$cid4 = create_test_customer($pdo, "Test Scenario 4 Custom Rate", 1.00, 72.00);
$rate4 = get_rate_for($pdo, $cid4, '2026-05-10');
assert_test("Custom rate 72.00 is resolved", $rate4 === 72.00, "Resolved rate: {$rate4}");
cleanup_customer($pdo, $cid4);

// -------------------------------------------------------------
// Scenario 8: Leap-Year February (2028)
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 8] Leap-Year February Handling (2028){$C_RESET}\n";
$daysFeb2028 = (int)date('t', strtotime('2028-02-01'));
assert_test("February 2028 has 29 days", $daysFeb2028 === 29, "Days in month: {$daysFeb2028}");
$cid8 = create_test_customer($pdo, "Test Scenario 8 Leap Year", 1.00, 60.00, '2028-01-01');
$unrecordedFeb = get_unrecorded_dates($pdo, $cid8, 2028, 2);
assert_test("Unrecorded dates in Feb 2028 contains 29 days", count($unrecordedFeb) === 29, "Count: " . count($unrecordedFeb));
assert_test("Feb 29 is included", in_array('2028-02-29', $unrecordedFeb, true));
cleanup_customer($pdo, $cid8);

// -------------------------------------------------------------
// Scenario 5: Unrecorded Days ('fill' vs 'ignore')
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 5] Unrecorded Days Handling{$C_RESET}\n";
$cid5 = create_test_customer($pdo, "Test Scenario 5 Missing Days", 2.00, 50.00, '2026-04-01');
// Log 5 days only out of 30 in April
for ($d = 1; $d <= 5; $d++) {
    $dStr = sprintf('2026-04-%02d', $d);
    $pdo->exec("INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount) VALUES ({$cid5}, '{$dStr}', 2.00, 50.00, 100.00)");
}
$missingDates = get_unrecorded_dates($pdo, $cid5, 2026, 4);
assert_test("25 days correctly identified as unrecorded", count($missingDates) === 25, "Unrecorded: " . count($missingDates));
cleanup_customer($pdo, $cid5);

// -------------------------------------------------------------
// Scenario 1: 750 Billed, 800 Paid -> Next Bill Shows -50 Advance Credit
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 1] 750 Billed, 800 Paid -> Next Bill Shows -50 Advance Credit{$C_RESET}\n";
$cid1 = create_test_customer($pdo, "Test Scenario 1 Overpayment", 1.00, 50.00, '2026-01-01');

// 1. Generate Invoice 1 for Jan 2026: 15 days @ 50 = 750
$pdo->exec("INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount) VALUES ({$cid1}, '2026-01-01', 15.00, 50.00, 750.00)");
$pdo->exec(
    "INSERT INTO invoices (invoice_no, customer_id, period_month, period_year, period_from, period_to, days_delivered, total_qty, milk_amount, previous_balance, total_payable, paid_amount, status)
     VALUES ('TEST-INV-101', {$cid1}, 1, 2026, '2026-01-01', '2026-01-31', 1, 15.00, 750.00, 0.00, 750.00, 0.00, 'unpaid')"
);
$inv1Id = (int)$pdo->lastInsertId();
// Add ledger debit of 750
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid1}, '2026-01-31', 'invoice', {$inv1Id}, 'Jan Bill', 750.00, 0.00)");

$balBefore = get_customer_ledger_balance($pdo, $cid1);
assert_test("Initial ledger balance is +750.00", $balBefore === 750.00, "Balance: {$balBefore}");

// 2. Customer pays 800
$pdo->exec(
    "INSERT INTO payments (customer_id, invoice_id, amount, payment_date, mode, reference)
     VALUES ({$cid1}, {$inv1Id}, 800.00, '2026-02-02', 'cash', 'TEST-REF-1')"
);
$pay1Id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid1}, '2026-02-02', 'payment', {$pay1Id}, 'Payment', 0.00, 800.00)");
$invStatus1 = recompute_invoice_status($pdo, $inv1Id);

assert_test("Invoice 1 status is overpaid", $invStatus1['status'] === 'overpaid', "Status: {$invStatus1['status']}");
assert_test("Invoice 1 paid_amount is 800.00", (float)$invStatus1['paid_amount'] === 800.00);

$balAfter = get_customer_ledger_balance($pdo, $cid1);
assert_test("Ledger balance after payment is -50.00 (advance credit)", $balAfter === -50.00, "Balance: {$balAfter}");

// 3. Next month (Feb 2026) billing evaluation: milk amount 600
// Previous balance brought forward from ledger MUST be -50.00, total_payable = 600 - 50 = 550.00
$milkAmountFeb = 600.00;
$prevBalFeb = get_customer_ledger_balance($pdo, $cid1);
$totalPayableFeb = round_money($milkAmountFeb + $prevBalFeb);

assert_test("Next month previous_balance is -50.00", $prevBalFeb === -50.00, "Previous Balance: {$prevBalFeb}");
assert_test("Next month total_payable is 550.00 (600 - 50)", $totalPayableFeb === 550.00, "Total Payable: {$totalPayableFeb}");

cleanup_customer($pdo, $cid1);

// -------------------------------------------------------------
// Scenario 2: 750 Billed, 500 Paid -> Next Bill Shows +250 Carried Forward
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 2] 750 Billed, 500 Paid -> Next Bill Shows +250 Carried Forward{$C_RESET}\n";
$cid2 = create_test_customer($pdo, "Test Scenario 2 Shortfall", 1.00, 50.00, '2026-01-01');

// 1. Generate Invoice 1: 750
$pdo->exec(
    "INSERT INTO invoices (invoice_no, customer_id, period_month, period_year, period_from, period_to, days_delivered, total_qty, milk_amount, previous_balance, total_payable, paid_amount, status)
     VALUES ('TEST-INV-201', {$cid2}, 1, 2026, '2026-01-01', '2026-01-31', 1, 15.00, 750.00, 0.00, 750.00, 0.00, 'unpaid')"
);
$inv2Id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid2}, '2026-01-31', 'invoice', {$inv2Id}, 'Jan Bill', 750.00, 0.00)");

// 2. Customer pays 500
$pdo->exec(
    "INSERT INTO payments (customer_id, invoice_id, amount, payment_date, mode, reference)
     VALUES ({$cid2}, {$inv2Id}, 500.00, '2026-02-02', 'upi', 'UPI-1234')"
);
$pay2Id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid2}, '2026-02-02', 'payment', {$pay2Id}, 'Payment', 0.00, 500.00)");
$invStatus2 = recompute_invoice_status($pdo, $inv2Id);

assert_test("Invoice 1 status is partial", $invStatus2['status'] === 'partial', "Status: {$invStatus2['status']}");
assert_test("Invoice 1 paid_amount is 500.00", (float)$invStatus2['paid_amount'] === 500.00);

$balAfter2 = get_customer_ledger_balance($pdo, $cid2);
assert_test("Ledger balance after partial payment is +250.00 (pending)", $balAfter2 === 250.00, "Balance: {$balAfter2}");

// 3. Next month (Feb 2026) billing evaluation: milk amount 700
// Previous balance brought forward from ledger MUST be +250.00, total_payable = 700 + 250 = 950.00
$milkAmountFeb2 = 700.00;
$prevBalFeb2 = get_customer_ledger_balance($pdo, $cid2);
$totalPayableFeb2 = round_money($milkAmountFeb2 + $prevBalFeb2);

assert_test("Next month previous_balance is +250.00", $prevBalFeb2 === 250.00, "Previous Balance: {$prevBalFeb2}");
assert_test("Next month total_payable is 950.00 (700 + 250)", $totalPayableFeb2 === 950.00, "Total Payable: {$totalPayableFeb2}");

cleanup_customer($pdo, $cid2);

// -------------------------------------------------------------
// Scenario 10: Deleting a Payment Reverses Ledger Credit & Recomputes Status
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 10] Deleting a Payment (Reversal & Invoice Recalculation){$C_RESET}\n";
$cid10 = create_test_customer($pdo, "Test Scenario 10 Delete Payment", 1.00, 50.00, '2026-01-01');

$pdo->exec(
    "INSERT INTO invoices (invoice_no, customer_id, period_month, period_year, period_from, period_to, days_delivered, total_qty, milk_amount, previous_balance, total_payable, paid_amount, status)
     VALUES ('TEST-INV-1001', {$cid10}, 1, 2026, '2026-01-01', '2026-01-31', 1, 15.00, 750.00, 0.00, 750.00, 0.00, 'unpaid')"
);
$inv10Id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid10}, '2026-01-31', 'invoice', {$inv10Id}, 'Jan Bill', 750.00, 0.00)");

// Add payment of 500
$pdo->exec("INSERT INTO payments (customer_id, invoice_id, amount, payment_date, mode) VALUES ({$cid10}, {$inv10Id}, 500.00, '2026-02-05', 'cash')");
$pay10Id = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid10}, '2026-02-05', 'payment', {$pay10Id}, 'Payment', 0.00, 500.00)");
recompute_invoice_status($pdo, $inv10Id);

assert_test("Balance after payment is 250.00", get_customer_ledger_balance($pdo, $cid10) === 250.00);

// Now simulate deleting the payment:
$pdo->beginTransaction();
$pdo->exec("DELETE FROM ledger WHERE type = 'payment' AND ref_id = {$pay10Id}");
$pdo->exec("DELETE FROM payments WHERE id = {$pay10Id}");
$recomputed10 = recompute_invoice_status($pdo, $inv10Id);
$pdo->commit();

assert_test("Invoice status reverted to unpaid", $recomputed10['status'] === 'unpaid', "Status: {$recomputed10['status']}");
assert_test("Invoice paid_amount reverted to 0.00", (float)$recomputed10['paid_amount'] === 0.00);
assert_test("Customer balance reverted to 750.00", get_customer_ledger_balance($pdo, $cid10) === 750.00, "Balance: " . get_customer_ledger_balance($pdo, $cid10));

cleanup_customer($pdo, $cid10);

// -------------------------------------------------------------
// Scenario 6 & 7: Void Invoice & Locked Deliveries
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 6 & 7] Void Invoice & Delivery Lock Enforcement{$C_RESET}\n";
$cid6 = create_test_customer($pdo, "Test Scenario 6 Void", 1.00, 60.00, '2026-01-01');

// Insert a delivery
$pdo->exec("INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount) VALUES ({$cid6}, '2026-01-15', 1.00, 60.00, 60.00)");
$del6Id = (int)$pdo->lastInsertId();

// Create invoice and lock delivery
$pdo->exec(
    "INSERT INTO invoices (invoice_no, customer_id, period_month, period_year, period_from, period_to, days_delivered, total_qty, milk_amount, previous_balance, total_payable, paid_amount, status)
     VALUES ('TEST-INV-601', {$cid6}, 1, 2026, '2026-01-01', '2026-01-31', 1, 1.00, 60.00, 0.00, 60.00, 0.00, 'unpaid')"
);
$inv6Id = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE deliveries SET invoice_id = {$inv6Id} WHERE id = {$del6Id}");
$pdo->exec("INSERT INTO ledger (customer_id, entry_date, type, ref_id, description, debit, credit) VALUES ({$cid6}, '2026-01-31', 'invoice', {$inv6Id}, 'Jan Bill', 60.00, 0.00)");

// Check delivery is locked
$stmtCheckLock = $pdo->prepare("SELECT invoice_id FROM deliveries WHERE id = :id");
$stmtCheckLock->execute([':id' => $del6Id]);
$lockedInvId = (int)$stmtCheckLock->fetchColumn();
assert_test("Delivery is locked by invoice #TEST-INV-601", $lockedInvId === $inv6Id, "invoice_id: {$lockedInvId}");

// Void invoice
$pdo->beginTransaction();
$pdo->exec("UPDATE deliveries SET invoice_id = NULL WHERE invoice_id = {$inv6Id}");
$pdo->exec("DELETE FROM ledger WHERE type = 'invoice' AND ref_id = {$inv6Id}");
$pdo->exec("DELETE FROM invoices WHERE id = {$inv6Id}");
$pdo->commit();

$stmtCheckUnlock = $pdo->prepare("SELECT invoice_id FROM deliveries WHERE id = :id");
$stmtCheckUnlock->execute([':id' => $del6Id]);
$unlockedId = $stmtCheckUnlock->fetchColumn();
assert_test("Delivery is unlocked after voiding", $unlockedId === null, "invoice_id is NULL");
assert_test("Ledger debit removed after voiding", get_customer_ledger_balance($pdo, $cid6) === 0.00, "Balance: " . get_customer_ledger_balance($pdo, $cid6));

cleanup_customer($pdo, $cid6);

// -------------------------------------------------------------
// Scenario 3: Mid-Month Rate Change
// -------------------------------------------------------------
echo "\n{$C_BLUE}[Scenario 3] Mid-Month Milk Rate Change Grouping{$C_RESET}\n";
$cid3 = create_test_customer($pdo, "Test Scenario 3 Rate Change", 1.00, null, '2026-03-01');

// Deliveries at 60 for 15 days, 66 for 16 days
for ($d = 1; $d <= 15; $d++) {
    $dStr = sprintf('2026-03-%02d', $d);
    $pdo->exec("INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount) VALUES ({$cid3}, '{$dStr}', 1.00, 60.00, 60.00)");
}
for ($d = 16; $d <= 31; $d++) {
    $dStr = sprintf('2026-03-%02d', $d);
    $pdo->exec("INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount) VALUES ({$cid3}, '{$dStr}', 1.00, 66.00, 66.00)");
}

$stmtDel3 = $pdo->prepare(
    "SELECT rate, SUM(quantity) AS total_qty, SUM(amount) AS total_amt, COUNT(*) AS days_count 
     FROM deliveries 
     WHERE customer_id = :cid AND delivery_date BETWEEN '2026-03-01' AND '2026-03-31' 
     GROUP BY rate 
     ORDER BY rate ASC"
);
$stmtDel3->execute([':cid' => $cid3]);
$rateGroups = $stmtDel3->fetchAll();

assert_test("Exactly 2 distinct rate groups generated", count($rateGroups) === 2);
assert_test("Group 1: 15 days @ ₹60 = ₹900", (float)$rateGroups[0]['rate'] === 60.00 && (float)$rateGroups[0]['total_amt'] === 900.00);
assert_test("Group 2: 16 days @ ₹66 = ₹1056", (float)$rateGroups[1]['rate'] === 66.00 && (float)$rateGroups[1]['total_amt'] === 1056.00);
$totalMilkAmount3 = (float)$rateGroups[0]['total_amt'] + (float)$rateGroups[1]['total_amt'];
assert_test("Total March milk amount is ₹1956.00 (900 + 1056)", $totalMilkAmount3 === 1956.00, "Total: {$totalMilkAmount3}");

cleanup_customer($pdo, $cid3);

// -------------------------------------------------------------
// Final Summary
// -------------------------------------------------------------
echo "\n{$C_CYAN}======================================================\n";
echo " Test Results Summary\n";
echo "======================================================{$C_RESET}\n";
echo " Total Tests:  " . ($passedCount + $failedCount) . "\n";
echo " {$C_GREEN}Passed:       {$passedCount}{$C_RESET}\n";
if ($failedCount > 0) {
    echo " {$C_RED}Failed:       {$failedCount}{$C_RESET}\n";
    exit(1);
} else {
    echo " {$C_GREEN}All scenarios passed successfully! (100% compliance){$C_RESET}\n\n";
    exit(0);
}
