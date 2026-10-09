<?php
/**
 * MilkBook - Customers API Endpoint
 * Handles CRUD operations for customers with route ordering, validation,
 * and delivery constraint checks.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = Database::getConnection();
check_auth();

$method = $_SERVER['REQUEST_METHOD'];

// Handle preflight OPTIONS request
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// Current default rate helper
function get_current_default_rate(PDO $pdo): float {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT rate_per_litre FROM milk_rates 
         WHERE effective_from <= :today 
         ORDER BY effective_from DESC, id DESC 
         LIMIT 1'
    );
    $stmt->execute([':today' => $today]);
    $row = $stmt->fetch();
    return $row ? round_money((float)$row['rate_per_litre']) : 0.00;
}

// Fetch single customer with stats
function get_customer_by_id(PDO $pdo, int $id): ?array {
    $sql = "SELECT c.*, 
                   (SELECT COUNT(*) FROM deliveries d WHERE d.customer_id = c.id) AS delivery_count,
                   (SELECT IFNULL(SUM(debit) - SUM(credit), 0.00) FROM ledger l WHERE l.customer_id = c.id) AS balance
            FROM customers c
            WHERE c.id = :id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $defaultRate = get_current_default_rate($pdo);
    $row['id'] = (int)$row['id'];
    $row['committed_qty'] = (float)$row['committed_qty'];
    $row['custom_rate'] = $row['custom_rate'] !== null ? (float)$row['custom_rate'] : null;
    $row['effective_rate'] = $row['custom_rate'] !== null ? $row['custom_rate'] : $defaultRate;
    $row['route_order'] = (int)$row['route_order'];
    $row['is_active'] = (int)$row['is_active'];
    $row['delivery_count'] = (int)$row['delivery_count'];
    $row['balance'] = round_money((float)$row['balance']);

    return $row;
}

// -------------------------------------------------------------
// GET: Fetch customer list or single customer
// -------------------------------------------------------------
if ($method === 'GET') {
    if (isset($_GET['id']) && trim($_GET['id']) !== '') {
        $id = (int)$_GET['id'];
        $customer = get_customer_by_id($pdo, $id);

        if (!$customer) {
            json_response(null, 404, false, [
                'message' => "Customer with ID {$id} not found."
            ]);
        }

        json_response($customer);
    }

    // List query
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : 'active'; // 'active', 'inactive', 'all'

    $conditions = [];
    $params = [];

    if ($status === 'active') {
        $conditions[] = 'c.is_active = 1';
    } elseif ($status === 'inactive') {
        $conditions[] = 'c.is_active = 0';
    }

    if ($search !== '') {
        $conditions[] = '(c.name LIKE :search_name OR c.mobile LIKE :search_mobile OR c.address LIKE :search_address)';
        $searchParam = '%' . $search . '%';
        $params[':search_name'] = $searchParam;
        $params[':search_mobile'] = $searchParam;
        $params[':search_address'] = $searchParam;
    }

    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $sql = "SELECT c.*, 
                   (SELECT COUNT(*) FROM deliveries d WHERE d.customer_id = c.id) AS delivery_count,
                   (SELECT IFNULL(SUM(debit) - SUM(credit), 0.00) FROM ledger l WHERE l.customer_id = c.id) AS balance
            FROM customers c
            {$whereClause}
            ORDER BY c.route_order ASC, c.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $defaultRate = get_current_default_rate($pdo);
    $customers = [];
    $totalCommittedQty = 0.00;
    $activeCount = 0;

    foreach ($rows as $row) {
        $customRate = $row['custom_rate'] !== null ? (float)$row['custom_rate'] : null;
        $qty = (float)$row['committed_qty'];
        $isActive = (int)$row['is_active'];

        if ($isActive) {
            $totalCommittedQty += $qty;
            $activeCount++;
        }

        $customers[] = [
            'id'             => (int)$row['id'],
            'name'           => $row['name'],
            'address'        => $row['address'],
            'mobile'         => $row['mobile'],
            'committed_qty'  => $qty,
            'custom_rate'    => $customRate,
            'effective_rate' => $customRate !== null ? $customRate : $defaultRate,
            'route_order'    => (int)$row['route_order'],
            'start_date'     => $row['start_date'],
            'is_active'      => $isActive,
            'notes'          => $row['notes'],
            'delivery_count' => (int)$row['delivery_count'],
            'balance'        => round_money((float)$row['balance']),
            'created_at'     => $row['created_at'],
            'updated_at'     => $row['updated_at']
        ];
    }

    // Overall totals for dashboard/summary cards, independent of current tab filter
    $stmtOverall = $pdo->query(
        "SELECT 
            COUNT(*) AS total_customers,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_customers,
            IFNULL(SUM(CASE WHEN is_active = 1 THEN committed_qty ELSE 0 END), 0.00) AS total_committed_qty
         FROM customers"
    );
    $overall = $stmtOverall->fetch();

    // Return customers and summary stats
    json_response([
        'customers' => $customers,
        'summary'   => [
            'total_customers'       => (int)($overall['total_customers'] ?? count($rows)),
            'active_customers'      => (int)($overall['active_customers'] ?? $activeCount),
            'total_committed_qty'   => round_money((float)($overall['total_committed_qty'] ?? $totalCommittedQty)),
            'current_default_rate'  => $defaultRate
        ]
    ]);
}

// -------------------------------------------------------------
// Validation Helper for POST & PUT
// -------------------------------------------------------------
function validate_customer_payload(PDO $pdo, array $body, ?int $currentId = null): array {
    $errors = [];
    $warnings = [];

    // Name validation
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        $errors['name'] = 'Customer name is required.';
    } elseif (mb_strlen($name) > 100) {
        $errors['name'] = 'Customer name must not exceed 100 characters.';
    }

    // Mobile validation
    $mobileRaw = trim((string)($body['mobile'] ?? ''));
    $mobile = validate_mobile($mobileRaw);
    if (!$mobile) {
        $errors['mobile'] = 'Please enter a valid 10-digit Indian mobile number.';
    } else {
        // Check for duplicate mobile
        $dupSql = 'SELECT id, name FROM customers WHERE mobile = :mobile';
        $dupParams = [':mobile' => $mobile];
        if ($currentId !== null) {
            $dupSql .= ' AND id != :id';
            $dupParams[':id'] = $currentId;
        }
        $dupSql .= ' LIMIT 1';
        $stmt = $pdo->prepare($dupSql);
        $stmt->execute($dupParams);
        $duplicate = $stmt->fetch();

        if ($duplicate) {
            $warnings['mobile'] = "Notice: Mobile {$mobile} is already registered to '{$duplicate['name']}' (ID: {$duplicate['id']}).";
        }
    }

    // Committed quantity validation
    $committedQty = $body['committed_qty'] ?? 1.00;
    if (!is_numeric($committedQty) || !validate_qty($committedQty)) {
        $errors['committed_qty'] = 'Daily committed quantity must be greater than 0 and a multiple of 0.25 (e.g. 0.50, 1.00, 1.25).';
    }

    // Custom rate validation (optional)
    $customRate = null;
    if (isset($body['custom_rate']) && $body['custom_rate'] !== '' && $body['custom_rate'] !== null) {
        if (!is_numeric($body['custom_rate']) || (float)$body['custom_rate'] <= 0) {
            $errors['custom_rate'] = 'Custom rate must be a valid positive number, or left empty to use default rate.';
        } else {
            $customRate = round_money((float)$body['custom_rate']);
        }
    }

    // Start date validation
    $startDate = trim((string)($body['start_date'] ?? date('Y-m-d')));
    if (!validate_date($startDate)) {
        $errors['start_date'] = 'Start date must be in YYYY-MM-DD format.';
    }

    // Route order
    $routeOrder = isset($body['route_order']) && is_numeric($body['route_order']) 
        ? (int)$body['route_order'] 
        : 0;

    // Is active
    $isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1;

    $address = trim((string)($body['address'] ?? ''));
    $notes = trim((string)($body['notes'] ?? ''));

    return [
        'errors'    => $errors,
        'warnings'  => $warnings,
        'validated' => [
            'name'          => $name,
            'mobile'        => $mobile,
            'address'       => $address !== '' ? $address : null,
            'committed_qty' => (float)$committedQty,
            'custom_rate'   => $customRate,
            'route_order'   => $routeOrder,
            'start_date'    => $startDate,
            'is_active'     => $isActive,
            'notes'         => $notes !== '' ? $notes : null
        ]
    ];
}

// -------------------------------------------------------------
// POST: Create Customer
// -------------------------------------------------------------
if ($method === 'POST') {
    $body = read_json_body();
    $result = validate_customer_payload($pdo, $body);

    if (!empty($result['errors'])) {
        json_response(null, 422, false, [
            'message' => 'Customer validation failed.',
            'fields'  => $result['errors']
        ]);
    }

    $val = $result['validated'];

    // Auto route order if not provided or 0
    if ($val['route_order'] <= 0) {
        $stmtMax = $pdo->query('SELECT IFNULL(MAX(route_order), 0) + 1 FROM customers');
        $val['route_order'] = (int)$stmtMax->fetchColumn();
    }

    $sql = 'INSERT INTO customers (name, address, mobile, committed_qty, custom_rate, route_order, start_date, is_active, notes)
            VALUES (:name, :address, :mobile, :committed_qty, :custom_rate, :route_order, :start_date, :is_active, :notes)';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name'          => $val['name'],
        ':address'       => $val['address'],
        ':mobile'        => $val['mobile'],
        ':committed_qty' => $val['committed_qty'],
        ':custom_rate'   => $val['custom_rate'],
        ':route_order'   => $val['route_order'],
        ':start_date'    => $val['start_date'],
        ':is_active'     => $val['is_active'],
        ':notes'         => $val['notes']
    ]);

    $newId = (int)$pdo->lastInsertId();
    $created = get_customer_by_id($pdo, $newId);

    json_response([
        'customer' => $created,
        'warnings' => $result['warnings']
    ], 201);
}

// -------------------------------------------------------------
// PUT: Update Customer
// -------------------------------------------------------------
if ($method === 'PUT') {
    $body = read_json_body();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($body['id'] ?? 0);

    if ($id <= 0) {
        json_response(null, 400, false, [
            'message' => 'Missing or invalid customer ID for update.'
        ]);
    }

    $existing = get_customer_by_id($pdo, $id);
    if (!$existing) {
        json_response(null, 404, false, [
            'message' => "Customer with ID {$id} not found."
        ]);
    }

    $result = validate_customer_payload($pdo, $body, $id);
    if (!empty($result['errors'])) {
        json_response(null, 422, false, [
            'message' => 'Customer validation failed.',
            'fields'  => $result['errors']
        ]);
    }

    $val = $result['validated'];

    $sql = 'UPDATE customers 
            SET name = :name,
                address = :address,
                mobile = :mobile,
                committed_qty = :committed_qty,
                custom_rate = :custom_rate,
                route_order = :route_order,
                start_date = :start_date,
                is_active = :is_active,
                notes = :notes
            WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name'          => $val['name'],
        ':address'       => $val['address'],
        ':mobile'        => $val['mobile'],
        ':committed_qty' => $val['committed_qty'],
        ':custom_rate'   => $val['custom_rate'],
        ':route_order'   => $val['route_order'],
        ':start_date'    => $val['start_date'],
        ':is_active'     => $val['is_active'],
        ':notes'         => $val['notes'],
        ':id'            => $id
    ]);

    $updated = get_customer_by_id($pdo, $id);

    json_response([
        'customer' => $updated,
        'warnings' => $result['warnings']
    ]);
}

// -------------------------------------------------------------
// DELETE: Delete customer or soft-deactivate
// When dues are paid (balance <= 0) or user forces delete,
// cleanly removes customer and associated records in a transaction.
// -------------------------------------------------------------
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $body = read_json_body();
    if ($id <= 0) {
        $id = (int)($body['id'] ?? 0);
    }

    if ($id <= 0) {
        json_response(null, 400, false, [
            'message' => 'Missing or invalid customer ID for deletion.'
        ]);
    }

    $customer = get_customer_by_id($pdo, $id);
    if (!$customer) {
        json_response(null, 404, false, [
            'message' => "Customer with ID {$id} not found."
        ]);
    }

    $action = $_GET['action'] ?? ($body['action'] ?? 'delete');
    $force = !empty($_GET['force']) || !empty($body['force']);

    // If explicit deactivation requested
    if ($action === 'deactivate') {
        $stmtDeactivate = $pdo->prepare('UPDATE customers SET is_active = 0 WHERE id = :id');
        $stmtDeactivate->execute([':id' => $id]);
        json_response([
            'action'  => 'deactivated',
            'message' => "Customer '{$customer['name']}' has been marked as Inactive."
        ]);
    }

    // Check balance
    $balance = (float)$customer['balance'];
    if ($balance > 0.00 && !$force) {
        json_response(null, 422, false, [
            'message' => "Cannot delete customer because they have pending dues of " . format_currency($balance) . ". Please clear all dues before deleting, or choose Deactivate.",
            'balance' => $balance
        ]);
    }

    // Customer has 0 dues (paid all dues) or force is specified: proceed with full deletion in transaction
    $pdo->beginTransaction();
    try {
        // 1. Delete deliveries
        $stmtDel = $pdo->prepare('DELETE FROM deliveries WHERE customer_id = :id');
        $stmtDel->execute([':id' => $id]);
        $delCount = $stmtDel->rowCount();

        // 2. Delete ledger entries
        $stmtLed = $pdo->prepare('DELETE FROM ledger WHERE customer_id = :id');
        $stmtLed->execute([':id' => $id]);

        // 3. Delete payments
        $stmtPay = $pdo->prepare('DELETE FROM payments WHERE customer_id = :id');
        $stmtPay->execute([':id' => $id]);

        // 4. Delete invoices
        $stmtInv = $pdo->prepare('DELETE FROM invoices WHERE customer_id = :id');
        $stmtInv->execute([':id' => $id]);

        // 5. Delete customer
        $stmtCust = $pdo->prepare('DELETE FROM customers WHERE id = :id');
        $stmtCust->execute([':id' => $id]);

        $pdo->commit();

        json_response([
            'action'          => 'deleted',
            'customer_name'   => $customer['name'],
            'deleted_records' => $delCount,
            'message'         => "Customer '{$customer['name']}' permanently deleted successfully."
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
