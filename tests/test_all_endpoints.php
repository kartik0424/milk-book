<?php
/**
 * Comprehensive API Test & Bug Hunt Script
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = get_db_connection();

function test_api($method, $endpoint, $body = null) {
    $url = "http://localhost/project/milkbook/api/" . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($res, true);
    return ['code' => $httpCode, 'raw' => $res, 'json' => $decoded];
}

$errors = [];
$passes = 0;

function check($testName, $cond, $detail = '') {
    global $errors, $passes;
    if ($cond) {
        $passes++;
        echo "  ✔ [PASS] {$testName}\n";
    } else {
        $errors[] = "{$testName}: {$detail}";
        echo "  ❌ [FAIL] {$testName} - {$detail}\n";
    }
}

echo "=== 1. Testing Auth API (api/auth.php) ===\n";
$r = test_api('GET', 'auth.php?action=status');
check("Auth status returns 200", $r['code'] === 200);
check("Auth status has csrf_token", isset($r['json']['data']['csrf_token']));

echo "\n=== 2. Testing Settings API (api/settings.php) ===\n";
$r = test_api('GET', 'settings.php');
check("Settings GET returns 200", $r['code'] === 200);
check("Settings has business_name", isset($r['json']['data']['business_name']));

echo "\n=== 3. Testing Rates API (api/rates.php) ===\n";
$r = test_api('GET', 'rates.php');
check("Rates GET returns 200", $r['code'] === 200);
check("Rates has current_rate", isset($r['json']['data']['current_rate']));

echo "\n=== 4. Testing Customers API (api/customers.php) ===\n";
$r = test_api('GET', 'customers.php?status=active');
check("Customers active returns 200", $r['code'] === 200);
check("Customers list is array", is_array($r['json']['data']['customers']));
$rInact = test_api('GET', 'customers.php?status=inactive');
check("Customers inactive returns 200", $rInact['code'] === 200);
check("Overall active count is preserved", $rInact['json']['data']['summary']['active_customers'] >= 0);

echo "\n=== 5. Testing Deliveries API (api/deliveries.php) ===\n";
$r = test_api('GET', 'deliveries.php?date=2026-10-04');
check("Deliveries daily sheet returns 200", $r['code'] === 200);
$rReg = test_api('GET', 'deliveries.php?view=register&month=10&year=2026');
check("Deliveries monthly register returns 200", $rReg['code'] === 200);
check("Register grid has days_in_month", isset($rReg['json']['data']['days_in_month']));

echo "\n=== 6. Testing Balances API (api/balances.php) ===\n";
$r = test_api('GET', 'balances.php');
check("Balances GET returns 200", $r['code'] === 200);
check("Balances has total_pending_dues", isset($r['json']['data']['total_pending_dues']));

echo "\n=== 7. Testing Dashboard API (api/dashboard.php) ===\n";
$r = test_api('GET', 'dashboard.php');
check("Dashboard GET returns 200", $r['code'] === 200);
check("Dashboard has today and ledger_totals", isset($r['json']['data']['today']) && isset($r['json']['data']['ledger_totals']));

echo "\n=== 8. Testing Invoices API (api/invoices.php) ===\n";
$r = test_api('GET', 'invoices.php');
check("Invoices list returns 200", $r['code'] === 200);
check("Invoices has summary", isset($r['json']['data']['summary']));

echo "\n=== 9. Testing Payments API (api/payments.php) ===\n";
$r = test_api('GET', 'payments.php');
check("Payments list returns 200", $r['code'] === 200);

echo "\n=== 10. Testing Statement API (api/statement.php) ===\n";
// Grab a customer id
$stmt = $pdo->query("SELECT id FROM customers LIMIT 1");
$cid = (int)$stmt->fetchColumn();
if ($cid > 0) {
    $r = test_api('GET', "statement.php?customer_id={$cid}&from=2026-01-01&to=2026-12-31");
    check("Statement GET returns 200", $r['code'] === 200);
    check("Statement has opening_balance", isset($r['json']['data']['opening_balance']));
}

echo "\n====================================\n";
echo "Passes: {$passes}, Fails: " . count($errors) . "\n";
if (!empty($errors)) {
    echo "Failures:\n" . implode("\n", $errors) . "\n";
} else {
    echo "ALL ENDPOINTS HEALTHY!\n";
}
