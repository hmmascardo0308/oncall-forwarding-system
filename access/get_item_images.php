<?php
// get_item_images.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';


if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$item_code = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
if ($item_code === '') {
    echo json_encode([]);
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, item_image, created_at, created_by 
     FROM item_images 
     WHERE item_code = ? 
     ORDER BY id ASC"
);
mysqli_stmt_bind_param($stmt, "s", $item_code);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$images = [];
while ($row = mysqli_fetch_assoc($res)) {
    $images[] = $row;
}
mysqli_stmt_close($stmt);
mysqli_close($conn);

header('Content-Type: application/json');
echo json_encode($images);