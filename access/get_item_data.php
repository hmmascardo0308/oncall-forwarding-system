<?php
session_start();
include '../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get item name from POST request
$item_name = isset($_POST['item_name']) ? trim($_POST['item_name']) : '';

if (empty($item_name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Item name is required']);
    exit;
}

// Fetch only the needed fields: item_code, category, subcategory
$query = "SELECT item_code, category, subcategory 
          FROM item_masterlist 
          WHERE item_name = ? 
          LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $item_name);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    // Return only the needed fields
    echo json_encode($row);
} else {
    echo json_encode(null);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>