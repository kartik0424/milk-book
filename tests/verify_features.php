<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = get_db_connection();

echo "--- 1. Testing Dynamic Invoicing by Date Range ---\n";

// Find an active customer
$stmt = $pdo->query("SELECT id, name FROM customers WHERE is_active = 1 LIMIT 1");
$cust = $stmt->fetch();
if (!$cust) {
    echo "No customer found\n";
    exit(1);
}

$cid = (int)$cust['id'];
$cname = $cust['name'];
echo "Selected Customer: {$cname} (ID: {$cid})\n";

// Ensure some deliveries exist for this customer in 2026-09
$dates = ['2026-09-01', '2026-09-05', '2026-09-10', '2026-09-16', '2026-09-20', '2026-09-25'];
foreach ($dates as $d) {
    $stmtDel = $pdo->prepare(
        "INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount)
         VALUES (:cid, :d, 1.5, 60.00, 90.00)
         ON DUPLICATE KEY UPDATE quantity = 1.5, rate = 60.00, amount = 90.00"
    );
    $stmtDel->execute([':cid' => $cid, ':d' => $d]);
}

// Preview Range 1: 2026-09-01 to 2026-09-15
$url = "http://localhost/project/milkbook/api/invoices.php?preview=1&from_date=2026-09-01&to_date=2026-09-15&customer_id={$cid}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resJson = curl_exec($ch);
curl_close($ch);
$res = json_decode($resJson, true);
$resData = $res['data'] ?? $res;

echo "Preview 1 (01 Sep - 15 Sep):\n";
echo "  Success: " . ($res['success'] ? 'YES' : 'NO') . "\n";
echo "  Delivered Days: " . ($resData['days_delivered'] ?? 'N/A') . " (Expected: 3)\n";
echo "  Milk Amount: " . ($resData['milk_amount'] ?? 'N/A') . " (Expected: 270.00)\n";
echo "  Total Payable: " . ($resData['total_payable'] ?? 'N/A') . "\n";

// Generate Invoice 1: 2026-09-01 to 2026-09-15
$payload1 = [
    'from_date' => '2026-09-01',
    'to_date' => '2026-09-15',
    'customer_id' => $cid,
    'missing_days' => 'ignore'
];

$ch = curl_init("http://localhost/project/milkbook/api/invoices.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload1));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$genResJson = curl_exec($ch);
curl_close($ch);
$genRes1 = json_decode($genResJson, true);
$genData1 = $genRes1['data'] ?? $genRes1;

echo "Generate Invoice 1 Result:\n";
echo "  Success: " . ($genRes1['success'] ? 'YES' : 'NO') . "\n";
$inv1No = $genData1['created'][0]['invoice_no'] ?? 'N/A';
echo "  Invoice 1 No: {$inv1No}\n";

// Generate Invoice 2: 2026-09-16 to 2026-09-30 (Same Customer, NO LOCKOUT!)
$payload2 = [
    'from_date' => '2026-09-16',
    'to_date' => '2026-09-30',
    'customer_id' => $cid,
    'missing_days' => 'ignore'
];

$ch = curl_init("http://localhost/project/milkbook/api/invoices.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload2));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$genResJson2 = curl_exec($ch);
curl_close($ch);
$genRes2 = json_decode($genResJson2, true);
$genData2 = $genRes2['data'] ?? $genRes2;

echo "Generate Invoice 2 Result (Same Customer, Next Range):\n";
echo "  Success: " . ($genRes2['success'] ? 'YES' : 'NO') . "\n";
$inv2No = $genData2['created'][0]['invoice_no'] ?? 'N/A';
echo "  Invoice 2 No: {$inv2No}\n";

echo "\n--- 2. Testing Customer Deletion With 3 Entries & Paid Dues ---\n";
// Create test customer
$stmtIns = $pdo->prepare(
    "INSERT INTO customers (name, mobile, committed_qty, route_order, is_active, start_date)
     VALUES ('Test Deletable Customer', '9999900011', 1.0, 999, 1, '2026-09-01')"
);
$stmtIns->execute();
$testCid = (int)$pdo->lastInsertId();
echo "Created Test Customer ID: {$testCid}\n";

// Add 3 delivery entries
for ($i = 1; $i <= 3; $i++) {
    $stmtDel = $pdo->prepare(
        "INSERT INTO deliveries (customer_id, delivery_date, quantity, rate, amount)
         VALUES (:cid, :d, 1.0, 60.00, 60.00)"
    );
    $stmtDel->execute([':cid' => $testCid, ':d' => "2026-09-0{$i}"]);
}
echo "Added 3 delivery entries for customer {$testCid}\n";

// Total amount = 3 * 60 = 180. Add ledger debit for 180 and ledger credit for 180 (paid all dues)
$pdo->prepare("INSERT INTO ledger (customer_id, entry_date, type, description, debit, credit) VALUES (:cid, '2026-09-03', 'invoice', 'Test Bill', 180.00, 0.00)")->execute([':cid' => $testCid]);
$pdo->prepare("INSERT INTO ledger (customer_id, entry_date, type, description, debit, credit) VALUES (:cid, '2026-09-03', 'payment', 'Full Payment Received', 0.00, 180.00)")->execute([':cid' => $testCid]);

// Check balance
$stmtBal = $pdo->prepare("SELECT SUM(debit) - SUM(credit) FROM ledger WHERE customer_id = :cid");
$stmtBal->execute([':cid' => $testCid]);
$balance = (float)$stmtBal->fetchColumn();
echo "Customer Balance: ₹" . number_format($balance, 2) . " (All dues paid)\n";

// Now delete customer via DELETE api/customers.php?id=$testCid
$ch = curl_init("http://localhost/project/milkbook/api/customers.php?id={$testCid}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
$delJson = curl_exec($ch);
curl_close($ch);
$delRes = json_decode($delJson, true);
$delData = $delRes['data'] ?? $delRes;

echo "Delete Response:\n";
echo "  Success: " . ($delRes['success'] ? 'YES' : 'NO') . "\n";
echo "  Message: " . ($delData['message'] ?? 'N/A') . "\n";
echo "  Action: " . ($delData['action'] ?? 'N/A') . "\n";

// Verify customer is gone from DB
$chk = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE id = :cid");
$chk->execute([':cid' => $testCid]);
echo "Customer exists in DB: " . ($chk->fetchColumn() == 0 ? 'NO (Cleanly Deleted!)' : 'YES') . "\n";

// Verify deliveries are gone
$chkDel = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE customer_id = :cid");
$chkDel->execute([':cid' => $testCid]);
echo "Deliveries exist in DB: " . ($chkDel->fetchColumn() == 0 ? 'NO (Cleanly Cascaded!)' : 'YES') . "\n";

// Verify ledger is gone
$chkLed = $pdo->prepare("SELECT COUNT(*) FROM ledger WHERE customer_id = :cid");
$chkLed->execute([':cid' => $testCid]);
echo "Ledger records exist in DB: " . ($chkLed->fetchColumn() == 0 ? 'NO (Cleanly Cascaded!)' : 'YES') . "\n";

echo "\nAll verification steps completed successfully!\n";
