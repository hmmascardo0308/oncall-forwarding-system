<?php
// get_employee_image.php
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$employee_code = isset($_GET['employee_code']) ? trim($_GET['employee_code']) : '';
if ($employee_code === '') {
    echo json_encode(null);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, employee_code, full_name, profile_picture, created_at, created_by
     FROM employee_list WHERE employee_code = ? LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "s", $employee_code);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);
mysqli_close($conn);

if ($row) {
    echo json_encode([
        'id'             => $row['id'],
        'employee_code'  => $row['employee_code'],
        'full_name'      => $row['full_name'],
        'profile_picture'=> $row['profile_picture'],
        'created_at'     => $row['created_at'],
        'created_by'     => $row['created_by']
    ]);
} else {
    echo json_encode(null);
}