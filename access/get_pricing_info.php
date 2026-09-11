<?php
// get_pricing_info.php
include '../config/config.php';
header('Content-Type: application/json');

if (!isset($_GET['customer_code']) || !isset($_GET['truck_code'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$customer_code = $conn->real_escape_string($_GET['customer_code']);
$truck_code    = $conn->real_escape_string($_GET['truck_code']);

$sql = "SELECT 
            minimum_charge,
            zone_from,
            zone_to,
            truck_code
        FROM `oncall_forwarding`.`customer_pricing`
        WHERE customer_code = '$customer_code'
          AND truck_code    = '$truck_code'
        LIMIT 1";

$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode([
        'success'         => true,
        'minimum_charge'  => floatval($row['minimum_charge'] ?? 0),
        'zone_from'       => $row['zone_from'] ?? '',
        'zone_to'         => $row['zone_to']   ?? '',
        'truck_code'      => $row['truck_code']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'minimum_charge' => 0,
        'zone_from' => '',
        'zone_to'   => ''
    ]);
}

$conn->close();
?>