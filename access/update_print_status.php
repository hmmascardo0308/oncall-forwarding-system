<?php
// update_print_status.php
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

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get PO number from POST
$po_number = $_POST['po_number'] ?? '';
if (empty($po_number)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'PO number is required']);
    exit;
}

// Sanitize
$safe_po_number = $conn->real_escape_string($po_number);

// Check if PO exists and get current status
$check_query = $conn->query("SELECT po_number, print_status FROM purchase_order WHERE po_number = '$safe_po_number' LIMIT 1");
if ($check_query->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Purchase Order not found']);
    exit;
}

$row = $check_query->fetch_assoc();
$current_status = $row['print_status'] ?? 'Unprinted';

// Update print_status to 'Printed' regardless
$update_query = $conn->query("UPDATE purchase_order SET print_status = 'Printed' WHERE po_number = '$safe_po_number'");

if ($update_query) {
    echo json_encode([
        'success' => true, 
        'message' => 'Print status updated successfully',
        'was_already_printed' => ($current_status === 'Printed')
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update print status: ' . $conn->error]);
}
?>