<?php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$created_by = $_SESSION['username'] ?? 'system';

//  Auto-generate SO number 
// Format: SO-YYYYMMDD-###  (sequential per day)
$today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Ymd');

$seq_sql  = "SELECT COUNT(*) AS cnt FROM `oncall_forwarding`.`sales_order`
             WHERE sales_order_no LIKE 'SO-{$today}-%'";
$seq_res  = $conn->query($seq_sql);
$seq_row  = $seq_res ? $seq_res->fetch_assoc() : ['cnt' => 0];
$next_seq = (int)$seq_row['cnt'] + 1;
$so_no    = 'SO-' . $today . '-' . sprintf('%03d', $next_seq);

// Manila timestamp 
$created_date = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

// Sanitize inputs 
//$s = fn($v) => $conn->real_escape_string(trim($v ?? ''));
$s = function($v) use ($conn) { return $conn->real_escape_string(trim($v ?? '')); };

$customer_code     = $s($input['customer_code']);
$customer_name     = $s($input['customer_name']);
$truck_code        = $s($input['truck_code']);
$plate_number      = $s($input['plate_number']);
$brand             = $s($input['brand']);
$model             = $s($input['model']);
$unit              = $s($input['unit']);
$destination_from  = $s($input['destination_from']);
$destination_to    = $s($input['destination_to']);
$order_date        = $s($input['order_date']);
$delivery_date     = $s($input['delivery_date']);
$delivery_address  = $s($input['delivery_address']);
$payment_terms     = $s($input['payment_terms']);
$sales_rep         = $s($input['sales_rep']);
$notes             = $s($input['notes']);
$status            = 'Created';

$vat_percent       = (float)($input['vat_percent']   ?? 12);
$unit_price        = (float)($input['unit_price']     ?? 0);
$discount_percent  = (float)($input['discount_percent'] ?? 0);
$amount            = (float)($input['amount']         ?? 0);
$discount_amount   = (float)($input['discount_amount'] ?? 0);
$quantity          = (float)($input['quantity']       ?? 1);

// Validate required
if (empty($customer_code) || empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Customer and status are required.']);
    exit;
}

$sql = "INSERT INTO `oncall_forwarding`.`sales_order`
        (sales_order_no, customer_code, customer_name, truck_code, plate_number,
         brand, model, unit, destination_from, destination_to,
         order_date, delivery_date, delivery_address, payment_terms, sales_rep,
         vat_percent, unit_price, discount_percent, amount, discount_amount,
         quantity, notes, status, created_by, created_date, updated_by, updated_at)
        VALUES
        ('{$so_no}', '{$customer_code}', '{$customer_name}', '{$truck_code}', '{$plate_number}',
         '{$brand}', '{$model}', '{$unit}', '{$destination_from}', '{$destination_to}',
         " . ($order_date   ? "'{$order_date}'"   : "NULL") . ",
         " . ($delivery_date ? "'{$delivery_date}'" : "NULL") . ",
         '{$delivery_address}', '{$payment_terms}', '{$sales_rep}',
         {$vat_percent}, {$unit_price}, {$discount_percent}, {$amount}, {$discount_amount},
         {$quantity}, '{$notes}', '{$status}', '{$s($created_by)}', '{$created_date}',
         NULL, NULL)";

if ($conn->query($sql)) {
    echo json_encode(['success' => true, 'so_no' => $so_no]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}