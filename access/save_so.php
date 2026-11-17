<?php
// save_so.php

session_start();

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Manila');

// ============================================================
// HELPER: Return JSON response
// ============================================================
function jsonResponse($success, $message, $extra = [])
{
    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ============================================================
// AUTHENTICATION
// ============================================================
if (!isset($_SESSION['user_id'])) {
    jsonResponse(false, 'Unauthorized');
}

// ============================================================
// READ JSON INPUT
// ============================================================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    jsonResponse(false, 'Invalid input data.');
}

// ============================================================
// PREPARE DATA
// Prepared statements preserve text values such as "20 Footer".
// ============================================================
$customer_code = trim((string)($input['customer_code'] ?? ''));
$customer_name = trim((string)($input['customer_name'] ?? ''));
$truck_code    = trim((string)($input['truck_code'] ?? ''));
$plate_number  = trim((string)($input['plate_number'] ?? ''));
$brand         = trim((string)($input['brand'] ?? ''));
$model         = trim((string)($input['model'] ?? ''));
$truck_type    = trim((string)($input['truck_type'] ?? ''));

// FOOTER: preserve the complete text value.
// The frontend now sends the COMBO's footer (from customer_pricing),
// not the truck's own footer, so this is the authoritative value.
// Examples: "20 Footer", "40 Footer", "20", "40", or "" (no footer)
$footer = trim((string)($input['footer'] ?? ''));
$footer = ($footer === '') ? null : $footer;

$unit             = trim((string)($input['unit'] ?? ''));
$destination_from = trim((string)($input['destination_from'] ?? ''));
$destination_to   = trim((string)($input['destination_to'] ?? ''));

$order_date = !empty($input['order_date'])
    ? $input['order_date']
    : null;

$delivery_date = !empty($input['delivery_date'])
    ? $input['delivery_date']
    : null;

$delivery_address = trim((string)($input['delivery_address'] ?? ''));
$payment_terms    = trim((string)($input['payment_terms'] ?? ''));
$notes            = trim((string)($input['notes'] ?? ''));
$driver           = trim((string)($input['driver'] ?? ''));

$status     = 'Created';
$created_by = $_SESSION['username'] ?? 'System';

$created_date = (new DateTime(
    'now',
    new DateTimeZone('Asia/Manila')
))->format('Y-m-d H:i:s');

// ============================================================
// DELIVERY RECEIPT
// Normalize to "DR# - XXXXX"
// ============================================================
$delivery_receipt_raw = trim(
    (string)($input['delivery_receipt'] ?? '')
);

if ($delivery_receipt_raw !== '') {
    $dr_value = preg_replace(
        '/^DR#\s*-\s*/i',
        '',
        $delivery_receipt_raw
    );

    $dr_value = trim($dr_value);

    $delivery_receipt = ($dr_value !== '')
        ? 'DR# - ' . $dr_value
        : '';
} else {
    $delivery_receipt = '';
}

// ============================================================
// VAT (main order)
// Checked   = 12
// Unchecked = NULL
// ============================================================
if (array_key_exists('vat_enabled', $input)) {
    $vat_enabled = filter_var(
        $input['vat_enabled'],
        FILTER_VALIDATE_BOOLEAN
    );
} else {
    // Backward compatibility with older frontend
    $vat_enabled = (float)($input['vat_percent'] ?? 0) > 0;
}

$vat_percent = $vat_enabled ? 12.00 : null;

// ============================================================
// SPECIAL CHARGES VAT (optional, independent of main VAT)
// Checked   = 12.00 in special_charges_vat
// Unchecked = NULL
// ============================================================
if (array_key_exists('special_charges_vat_enabled', $input)) {
    $special_charges_vat_enabled = filter_var(
        $input['special_charges_vat_enabled'],
        FILTER_VALIDATE_BOOLEAN
    );
} else {
    $special_charges_vat_enabled = false;
}

$special_charges_vat = $special_charges_vat_enabled ? 12.00 : null;

// ============================================================
// NUMERIC VALUES
// ============================================================
$unit_price       = (float)($input['unit_price'] ?? 0);
$discount_percent = (float)($input['discount_percent'] ?? 0);
$amount           = (float)($input['amount'] ?? 0);
$discount_amount  = (float)($input['discount_amount'] ?? 0);
$quantity         = (float)($input['quantity'] ?? 0);

// ============================================================
// GENERATE SALES ORDER NUMBER
// SO-YYYY-00001
// ============================================================
$conn->begin_transaction();

try {
    // Lock BOTH tables that will be written to.
    // Under LOCK TABLES you can only touch the tables listed here.
    $lockResult = $conn->query(
        'LOCK TABLES sales_order WRITE, sales_order_special_charge WRITE'
    );

    if (!$lockResult) {
        throw new Exception(
            'Unable to lock tables: ' . $conn->error
        );
    }

    $year   = date('Y');
    $prefix = "SO-{$year}-";

    $numberSql = "
        SELECT COALESCE(
            MAX(CAST(SUBSTRING(sales_order_no, " . (strlen($prefix) + 1) . ") AS UNSIGNED)),
            0
        ) AS max_seq
        FROM sales_order
        WHERE sales_order_no LIKE CONCAT(?, '%')
    ";

    $numberStmt = $conn->prepare($numberSql);

    if (!$numberStmt) {
        throw new Exception(
            'Unable to prepare sales order number query: ' . $conn->error
        );
    }

    $numberStmt->bind_param('s', $prefix);

    if (!$numberStmt->execute()) {
        throw new Exception(
            'Unable to generate sales order number: ' . $numberStmt->error
        );
    }

    $numberResult = $numberStmt->get_result();
    $numberRow    = $numberResult->fetch_assoc();

    $next_seq = ((int)($numberRow['max_seq'] ?? 0)) + 1;
    $so_no    = $prefix . sprintf('%05d', $next_seq);

    $numberStmt->close();

    // ========================================================
    // INSERT SALES ORDER
    // 29 columns = 29 placeholders
    //
    // vat_percent and special_charges_vat bound as 's' so NULL
    // is preserved reliably. MySQL casts the string "12.00"
    // correctly into DECIMAL and keeps NULL as NULL.
    // ========================================================
    $sql = "
        INSERT INTO sales_order (
            sales_order_no,
            customer_code,
            customer_name,
            truck_code,
            plate_number,
            brand,
            model,
            truck_type,
            footer,
            unit,
            destination_from,
            destination_to,
            order_date,
            delivery_date,
            delivery_address,
            payment_terms,
            vat_percent,
            special_charges_vat,
            unit_price,
            discount_percent,
            amount,
            discount_amount,
            quantity,
            notes,
            status,
            created_by,
            created_date,
            driver,
            delivery_receipt_no
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception(
            'Unable to prepare sales order insert: ' . $conn->error
        );
    }

    // Bind as string so NULL is sent correctly
    $vat_param                 = ($vat_percent === null) ? null : (string)$vat_percent;
    $special_charges_vat_param = ($special_charges_vat === null) ? null : (string)$special_charges_vat;

    $stmt->bind_param(
        'ssssssssssssssssssdddddssssss',
        $so_no,
        $customer_code,
        $customer_name,
        $truck_code,
        $plate_number,
        $brand,
        $model,
        $truck_type,
        $footer,
        $unit,
        $destination_from,
        $destination_to,
        $order_date,
        $delivery_date,
        $delivery_address,
        $payment_terms,
        $vat_param,
        $special_charges_vat_param,
        $unit_price,
        $discount_percent,
        $amount,
        $discount_amount,
        $quantity,
        $notes,
        $status,
        $created_by,
        $created_date,
        $driver,
        $delivery_receipt
    );

    if (!$stmt->execute()) {
        throw new Exception(
            'Error saving sales order: ' . $stmt->error
        );
    }

    $stmt->close();

    // ========================================================
    // INSERT SPECIAL CHARGES
    // ========================================================
    $special_charges = $input['special_charges'] ?? [];

    if (!is_array($special_charges)) {
        throw new Exception('Invalid special charges data.');
    }

    if (!empty($special_charges)) {
        $chargeSql = "
            INSERT INTO sales_order_special_charge (
                sales_order_no,
                customer_code,
                customer_name,
                charge_kind,
                charge_amount,
                charge_vat
            ) VALUES (?, ?, ?, ?, ?, ?)
        ";

        $chargeStmt = $conn->prepare($chargeSql);

        if (!$chargeStmt) {
            throw new Exception(
                'Unable to prepare special charge insert: ' . $conn->error
            );
        }

        foreach ($special_charges as $charge) {
            if (!is_array($charge)) {
                continue;
            }

            $charge_kind = trim(
                (string)($charge['charge_kind'] ?? '')
            );

            $charge_amount = (float)(
                $charge['charge_amount'] ?? 0
            );

            if ($charge_kind === '' || $charge_amount <= 0) {
                continue;
            }

            // charge_vat = amount × 0.12 when special charges VAT is enabled, else NULL
            $charge_vat = $special_charges_vat_enabled
                ? round($charge_amount * 0.12, 2)
                : null;

            $charge_vat_param = ($charge_vat === null) ? null : (string)$charge_vat;

            $chargeStmt->bind_param(
                'ssssds',
                $so_no,
                $customer_code,
                $customer_name,
                $charge_kind,
                $charge_amount,
                $charge_vat_param
            );

            if (!$chargeStmt->execute()) {
                throw new Exception(
                    'Error saving special charge: ' . $chargeStmt->error
                );
            }
        }

        $chargeStmt->close();
    }

    // Release table locks before committing.
    $conn->query('UNLOCK TABLES');

    // Commit the transaction.
    $conn->commit();

    jsonResponse(true, 'Sales Order saved successfully.', [
        'so_no'                 => $so_no,
        'footer'                => $footer,
        'special_charges_vat'   => $special_charges_vat
    ]);

} catch (Throwable $e) {
    // Always try to release locks on error
    @$conn->query('UNLOCK TABLES');

    // Roll back database changes.
    @$conn->rollback();

    error_log('save_so.php error: ' . $e->getMessage());

    jsonResponse(false, 'Error saving Sales Order: ' . $e->getMessage());

} finally {
    $conn->close();
}
?>