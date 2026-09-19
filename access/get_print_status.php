<?php
// get_print_status.php
session_start();

require_once __DIR__ . '/../config/config.php';


// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get PO number from GET
$po_number = $_GET['po_number'] ?? '';
if (empty($po_number)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'PO number is required']);
    exit;
}

// Sanitize
$safe_po_number = $conn->real_escape_string($po_number);

// Get print status
$query = $conn->query("SELECT print_status FROM purchase_order WHERE po_number = '$safe_po_number' LIMIT 1");
if ($query->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Purchase Order not found']);
    exit;
}

$row = $query->fetch_assoc();
echo json_encode([
    'success' => true,
    'print_status' => $row['print_status'] ?? 'Unprinted'
]);
?>