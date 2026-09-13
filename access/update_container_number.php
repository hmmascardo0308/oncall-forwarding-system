<?php
session_start();
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$so_no = trim($input['so_no'] ?? '');
$container_number = trim($input['container_number'] ?? '');

if (empty($so_no)) {
    echo json_encode(['success' => false, 'message' => 'Sales Order number is required']);
    exit;
}

$safe_so = $conn->real_escape_string($so_no);
$safe_container = $conn->real_escape_string($container_number);
$updated_by = $_SESSION['username'] ?? '';

$sql = "UPDATE `oncall_forwarding`.`sales_order` 
        SET container_number = '{$safe_container}',
            updated_by = '{$updated_by}',
            updated_at = NOW()
        WHERE sales_order_no = '{$safe_so}'";

if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}