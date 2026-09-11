<?php
// save_so.php
session_start();

require_once __DIR__ . '/../config/config.php';


header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get raw POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

// Generate SO Number: SO-YYYY-#####
$today = date('Y');
$prefix = "SO-{$today}-";

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM sales_order WHERE sales_order_no LIKE CONCAT(?, '%')");
$stmt->bind_param('s', $prefix);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$next_seq = ($row['cnt'] ?? 0) + 1;
$so_no = $prefix . sprintf('%05d', $next_seq);
$stmt->close();

// Prepare data for insertion
$customer_code    = $conn->real_escape_string($input['customer_code'] ?? '');
$customer_name    = $conn->real_escape_string($input['customer_name'] ?? '');
$truck_code       = $conn->real_escape_string($input['truck_code'] ?? '');
$plate_number     = $conn->real_escape_string($input['plate_number'] ?? '');
$brand            = $conn->real_escape_string($input['brand'] ?? '');
$model            = $conn->real_escape_string($input['model'] ?? '');
$truck_type       = $conn->real_escape_string($input['truck_type'] ?? '');   // ← added
$unit             = $conn->real_escape_string($input['unit'] ?? '');
$destination_from = $conn->real_escape_string($input['destination_from'] ?? '');
$destination_to   = $conn->real_escape_string($input['destination_to'] ?? '');
$order_date       = !empty($input['order_date']) ? $input['order_date'] : null;
$delivery_date    = !empty($input['delivery_date']) ? $input['delivery_date'] : null;
$delivery_address = $conn->real_escape_string($input['delivery_address'] ?? '');
$payment_terms    = $conn->real_escape_string($input['payment_terms'] ?? '');
$notes            = $conn->real_escape_string($input['notes'] ?? '');
$driver           = $conn->real_escape_string($input['driver'] ?? '');
$status           = 'Created';
$created_by       = $_SESSION['username'] ?? 'System';
$created_date     = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

// Numeric values
$vat_percent      = 12.00;
$unit_price       = floatval($input['unit_price'] ?? 0);
$discount_percent = floatval($input['discount_percent'] ?? 0);
$amount           = floatval($input['amount'] ?? 0);
$discount_amount  = floatval($input['discount_amount'] ?? 0);
$quantity         = floatval($input['quantity'] ?? 0);

// Insert Query – added truck_type
$sql = "INSERT INTO sales_order (
            sales_order_no, customer_code, customer_name, truck_code, plate_number, 
            brand, model, truck_type, unit, destination_from, destination_to, 
            order_date, delivery_date, delivery_address, payment_terms, vat_percent, 
            unit_price, discount_percent, amount, discount_amount, quantity, 
            notes, status, created_by, created_date, driver
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param(
    'sssssssssssssssddddddsssss',
    $so_no, $customer_code, $customer_name, $truck_code, $plate_number,
    $brand, $model, $truck_type, $unit, $destination_from, $destination_to,
    $order_date, $delivery_date, $delivery_address, $payment_terms, $vat_percent,
    $unit_price, $discount_percent, $amount, $discount_amount, $quantity,
    $notes, $status, $created_by, $created_date, $driver
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'so_no' => $so_no, 'message' => 'Sales Order saved successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error saving order: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>