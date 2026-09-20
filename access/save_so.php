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
$truck_type       = $conn->real_escape_string($input['truck_type'] ?? '');
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

// Start transaction
$conn->begin_transaction();

try {
    // Insert sales order
    $sql = "INSERT INTO sales_order (
                sales_order_no, customer_code, customer_name, truck_code, plate_number, 
                brand, model, truck_type, unit, destination_from, destination_to, 
                order_date, delivery_date, delivery_address, payment_terms, vat_percent, 
                unit_price, discount_percent, amount, discount_amount, quantity, 
                notes, status, created_by, created_date, driver
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }

    $stmt->bind_param(
        'sssssssssssssssddddddsssss',
        $so_no, $customer_code, $customer_name, $truck_code, $plate_number,
        $brand, $model, $truck_type, $unit, $destination_from, $destination_to,
        $order_date, $delivery_date, $delivery_address, $payment_terms, $vat_percent,
        $unit_price, $discount_percent, $amount, $discount_amount, $quantity,
        $notes, $status, $created_by, $created_date, $driver
    );

    if (!$stmt->execute()) {
        throw new Exception('Error saving order: ' . $stmt->error);
    }
    $stmt->close();

    // Insert special charges
    $special_charges = $input['special_charges'] ?? [];
    
    if (!empty($special_charges) && is_array($special_charges)) {
        $charge_sql = "INSERT INTO sales_order_special_charge 
                       (sales_order_no, customer_code, customer_name, charge_kind, charge_amount) 
                       VALUES (?, ?, ?, ?, ?)";
        $charge_stmt = $conn->prepare($charge_sql);
        
        if (!$charge_stmt) {
            throw new Exception('Database error preparing special charge: ' . $conn->error);
        }
        
        foreach ($special_charges as $charge) {
            $charge_kind = $conn->real_escape_string($charge['charge_kind'] ?? '');
            $charge_amount = floatval($charge['charge_amount'] ?? 0);
            
            if (empty($charge_kind) || $charge_amount <= 0) {
                continue;
            }
            
            $charge_stmt->bind_param(
                'ssssd',
                $so_no,
                $customer_code,
                $customer_name,
                $charge_kind,
                $charge_amount
            );
            
            if (!$charge_stmt->execute()) {
                throw new Exception('Error saving special charge: ' . $charge_stmt->error);
            }
        }
        $charge_stmt->close();
    }

    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'so_no' => $so_no,
        'message' => 'Sales Order saved successfully.'
    ]);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>