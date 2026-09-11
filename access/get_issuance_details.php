<?php
session_start();
include '../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$reference_no = isset($_GET['reference_no']) ? mysqli_real_escape_string($conn, $_GET['reference_no']) : '';

if (empty($reference_no)) {
    echo json_encode(['success' => false, 'message' => 'Reference number is required']);
    exit;
}

// Get issuance header info (using first record as header)
$header_query = "SELECT * FROM item_issuance WHERE reference_no = '$reference_no' LIMIT 1";
$header_result = mysqli_query($conn, $header_query);

if (!$header_result || mysqli_num_rows($header_result) == 0) {
    echo json_encode(['success' => false, 'message' => 'Issuance not found']);
    exit;
}

$issuance = mysqli_fetch_assoc($header_result);

// Determine recipient display name
$recipient = 'Internal Use';
if ($issuance['issuance_type'] == 'customer' && !empty($issuance['customer_code'])) {
    $customer_query = "SELECT full_name FROM customer_masterlist WHERE customer_code = '" . mysqli_real_escape_string($conn, $issuance['customer_code']) . "'";
    $customer_result = mysqli_query($conn, $customer_query);
    $customer = mysqli_fetch_assoc($customer_result);
    $recipient = $customer ? $customer['full_name'] : $issuance['customer_code'];
} elseif ($issuance['issuance_type'] == 'truck' && !empty($issuance['truck_code'])) {
    $truck_query = "SELECT plate_number FROM truck_masterlist WHERE truck_code = '" . mysqli_real_escape_string($conn, $issuance['truck_code']) . "'";
    $truck_result = mysqli_query($conn, $truck_query);
    $truck = mysqli_fetch_assoc($truck_result);
    $recipient = $truck ? $truck['plate_number'] : $issuance['truck_code'];
}

// Get all items for this issuance with supplier information
// Connect: item_issuance.item_code -> item_masterlist.item_code -> item_masterlist.supplier_code -> supplier_lists.supplier_code
$items_query = "SELECT 
                    ii.*, 
                    im.item_name, 
                    im.unit_of_measure,
                    im.supplier_code,
                    sl.supplier_name
                FROM item_issuance ii
                LEFT JOIN item_masterlist im ON ii.item_code = im.item_code
                LEFT JOIN supplier_lists sl ON im.supplier_code = sl.supplier_code
                WHERE ii.reference_no = '$reference_no'
                ORDER BY ii.id ASC";
$items_result = mysqli_query($conn, $items_query);

$items = [];
$total_quantity = 0;

while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = [
        'item_code' => $item['item_code'],
        'item_name' => $item['item_name'] ?? 'N/A',
        'quantity' => intval($item['quantity']),
        'unit_of_measure' => $item['unit_of_measure'] ?? 'pcs',
        'supplier_code' => $item['supplier_code'] ?? 'N/A',
        'supplier_name' => $item['supplier_name'] ?? 'N/A'
    ];
    $total_quantity += intval($item['quantity']);
}

echo json_encode([
    'success' => true,
    'issuance' => [
        'reference_no' => $issuance['reference_no'],
        'issuance_date' => date('M d, Y', strtotime($issuance['issuance_date'])),
        'issuance_type' => ucfirst($issuance['issuance_type']),
        'recipient' => $recipient,
        'purpose' => $issuance['purpose'],
        'remarks' => $issuance['remarks'],
        'status' => $issuance['status'],
        'created_by' => $issuance['created_by'],
        'created_at' => date('M d, Y h:i A', strtotime($issuance['created_at']))
    ],
    'items' => $items,
    'total_quantity' => $total_quantity
]);
?>