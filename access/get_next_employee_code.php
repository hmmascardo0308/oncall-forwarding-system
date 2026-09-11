<?php
session_start();
include '../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Function to generate next employee code
function generateEmployeeCode($conn) {
    $query = "SELECT employee_code FROM employee_list ORDER BY id DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['employee_code'];
        $num = intval(substr($last_code, 4));
        $next_num = $num + 1;
    } else {
        $next_num = 1;
    }
    
    return 'EMP-' . str_pad($next_num, 5, '0', STR_PAD_LEFT);
}

header('Content-Type: application/json');
echo json_encode(['code' => generateEmployeeCode($conn)]);
exit;