<?php
session_start();

require_once __DIR__ . '/../config/config.php';


header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$sales_order_no = isset($_GET['so_no']) ? mysqli_real_escape_string($conn, $_GET['so_no']) : '';

if (empty($sales_order_no)) {
    echo json_encode(['success' => false, 'message' => 'Sales Order number is required']);
    exit;
}

// Fetch complete sales order details
$sql = "SELECT * FROM sales_order WHERE sales_order_no = '$sales_order_no' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $data = $result->fetch_assoc();
    
    // Format dates if needed
    if ($data['order_date']) {
        $data['order_date'] = date('Y-m-d', strtotime($data['order_date']));
    }
    if ($data['delivery_date']) {
        $data['delivery_date'] = date('Y-m-d', strtotime($data['delivery_date']));
    }
    
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Sales Order not found'
    ]);
}
?>