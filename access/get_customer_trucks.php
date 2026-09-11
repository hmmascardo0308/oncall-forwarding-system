<?php
include '../config/config.php';
header('Content-Type: application/json');

if (!isset($_GET['customer_code'])) {
    echo json_encode([]);
    exit;
}

$customer_code = $conn->real_escape_string($_GET['customer_code']);

$sql = "SELECT 
            cp.truck_code, 
            cp.plate_number, 
            tm.brand,
            tm.model
        FROM `oncall_forwarding`.`customer_pricing` cp 
        INNER JOIN `oncall_forwarding`.`truck_masterlist` tm 
            ON cp.truck_code = tm.truck_code 
        WHERE cp.customer_code = '$customer_code'
        GROUP BY cp.truck_code, cp.plate_number, tm.brand, tm.model
        ORDER BY tm.brand, tm.model";

$result = $conn->query($sql);
$trucks = [];

while ($row = $result->fetch_assoc()) {
    $trucks[] = [
        'truck_code'  => $row['truck_code'],
        'plate_number'=> $row['plate_number'],
        'brand'       => $row['brand'],
        'model'       => $row['model']
    ];
}

echo json_encode($trucks);
?>