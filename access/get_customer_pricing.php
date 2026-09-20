<?php
// get_customer_pricing.php
session_start();

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$customer_code = $_GET['customer_code'] ?? '';

if (empty($customer_code)) {
    echo json_encode(['success' => false, 'error' => 'Customer code required']);
    exit;
}

// Get pricing and truck information in one query
// effective_unit_price prefers rate_per_trip, then minimum_charge, then 0
$query = "
    SELECT 
        cp.*,
        COALESCE(cp.rate_per_trip, cp.minimum_charge, 0) AS effective_unit_price,
        tm.id as truck_id,
        tm.truck_code,
        tm.brand,
        tm.model,
        tm.plate_number,
        tm.truck_type
    FROM customer_pricing cp
    JOIN truck_masterlist tm ON cp.truck_code = tm.truck_code
    WHERE cp.customer_code = ? 
    ORDER BY cp.zone_from, cp.zone_to
";

$stmt = $conn->prepare($query);
$stmt->bind_param('s', $customer_code);
$stmt->execute();
$result = $stmt->get_result();

$pricing = [];
$trucks_map = [];

while ($row = $result->fetch_assoc()) {
    $pricing[] = $row;
    
    // Collect unique truck details using plate_number as the key
    if (!isset($trucks_map[$row['plate_number']])) {
        $trucks_map[$row['plate_number']] = [
            'id' => $row['truck_id'],
            'truck_code' => $row['truck_code'],
            'brand' => $row['brand'],
            'model' => $row['model'],
            'plate_number' => $row['plate_number'],
            'truck_type' => $row['truck_type']
        ];
    }
}

// Reformat trucks data
$trucks_by_code = [];
$trucks_by_plate = [];

foreach ($trucks_map as $plate_number => $truck_details) {
    $trucks_by_code[$truck_details['truck_code']] = [$truck_details];
    $trucks_by_plate[$plate_number] = $truck_details;
}

echo json_encode([
    'success' => true,
    'pricing' => $pricing,
    'trucks' => $trucks_by_code,
    'trucks_by_plate' => $trucks_by_plate
]);

$stmt->close();
$conn->close();
?>