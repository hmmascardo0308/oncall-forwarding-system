<?php
include '../config/config.php';
header('Content-Type: application/json');

if (!isset($_GET['customer_code']) || !isset($_GET['truck_code'])) {
    echo json_encode([]);
    exit;
}

$customer_code = $conn->real_escape_string($_GET['customer_code']);
$truck_code    = $conn->real_escape_string($_GET['truck_code']);

$sql = "SELECT id, zone_from, zone_to, rate_per_trip, minimum_charge, payment_terms
        FROM `oncall_forwarding`.`customer_pricing` 
        WHERE customer_code = '$customer_code' 
          AND truck_code    = '$truck_code' 
        ORDER BY zone_from, zone_to";

$result = $conn->query($sql);
$zones = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $zones[] = [
            'pricing_id' => $row['id'],
            'zone_from' => $row['zone_from'],
            'zone_to' => $row['zone_to'],
            'price' => !empty($row['rate_per_trip']) ? $row['rate_per_trip'] : ($row['minimum_charge'] ?? 0),
            'payment_terms' => $row['payment_terms'] ?? ''
        ];
    }
}

echo json_encode($zones);

$conn->close();
?>