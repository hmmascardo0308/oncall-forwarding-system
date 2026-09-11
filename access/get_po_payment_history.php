<?php
session_start();
include '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$po_number = $_GET['po_number'] ?? '';
$item_code = $_GET['item_code'] ?? '';

if (!$po_number) {
    echo json_encode(['success' => false, 'message' => 'PO number required']);
    exit;
}

$params = [];
$types = '';
$sql = "SELECT * FROM po_payment_history WHERE po_number = ?";
$params[] = $po_number;
$types .= 's';

if (!empty($item_code)) {
    $sql .= " AND item_code = ?";
    $params[] = $item_code;
    $types .= 's';
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$history = [];
$total_paid = 0;

while ($row = $result->fetch_assoc()) {
    $total_paid += floatval($row['amount_paid']);
    $history[] = $row;
}

// Get PO details for the summary
$po_stmt = $conn->prepare("SELECT supplier_name, net_amount_due FROM purchase_order WHERE po_number = ? LIMIT 1");
$po_stmt->bind_param("s", $po_number);
$po_stmt->execute();
$po_result = $po_stmt->get_result();
$po_details = $po_result->fetch_assoc();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'history' => $history,
    'total_paid' => $total_paid,
    'po_details' => $po_details
]);

$stmt->close();
$po_stmt->close();
$conn->close();
?>