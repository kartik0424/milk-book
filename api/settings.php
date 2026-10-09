<?php
/**
 * MilkBook - Settings API Endpoint
 * Handles retrieval and update of application settings.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = Database::getConnection();
check_auth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// -------------------------------------------------------------
// GET: Retrieve all settings as key-value map
// -------------------------------------------------------------
if ($method === 'GET') {
    $stmt = $pdo->query('SELECT setting_key, setting_value, updated_at FROM settings');
    $rows = $stmt->fetchAll();

    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    json_response($settings);
}

// -------------------------------------------------------------
// PUT: Update settings
// -------------------------------------------------------------
if ($method === 'PUT') {
    $body = read_json_body();

    if (empty($body)) {
        json_response(null, 400, false, [
            'message' => 'No settings provided to update.'
        ]);
    }

    // Allowed settings keys
    $allowedKeys = [
        'business_name',
        'business_phone',
        'business_address',
        'upi_id',
        'invoice_prefix',
        'invoice_footer'
    ];

    $stmtUpsert = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) 
         VALUES (:key, :value) 
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    $errors = [];

    // Optional validations for specific keys
    if (isset($body['business_phone']) && trim((string)$body['business_phone']) !== '') {
        $cleanPhone = validate_mobile((string)$body['business_phone']);
        if (!$cleanPhone) {
            $errors['business_phone'] = 'Business phone must be a valid 10-digit Indian mobile number.';
        } else {
            $body['business_phone'] = $cleanPhone;
        }
    }

    if (isset($body['upi_id']) && trim((string)$body['upi_id']) !== '') {
        if (!preg_match('/^[\w.\-_]{2,256}@[a-zA-Z]{2,64}$/', trim((string)$body['upi_id']))) {
            $errors['upi_id'] = 'Please enter a valid UPI ID (e.g. username@bank).';
        }
    }

    if (!empty($errors)) {
        json_response(null, 422, false, [
            'message' => 'Settings validation failed.',
            'fields'  => $errors
        ]);
    }

    foreach ($body as $key => $value) {
        if (in_array($key, $allowedKeys, true)) {
            $stmtUpsert->execute([
                ':key'   => $key,
                ':value' => is_scalar($value) ? trim((string)$value) : ''
            ]);
        }
    }

    // Return full updated settings
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $updatedRows = $stmt->fetchAll();
    $updatedSettings = [];
    foreach ($updatedRows as $row) {
        $updatedSettings[$row['setting_key']] = $row['setting_value'];
    }

    json_response($updatedSettings);
}

json_response(null, 405, false, ['message' => "Method {$method} not allowed."]);
