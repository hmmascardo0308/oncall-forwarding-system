<?php
// export_sales_order_list.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
include '../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$username = $_SESSION['username'] ?? 'Guest';

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Check access
$allowed_roles = ['admin', 'sales_order_maker', 'sales_order_checker', 'sales_order_approver'];
$has_access = false;
foreach ($user_roles as $role) {
    if (in_array($role, $allowed_roles)) {
        $has_access = true;
        break;
    }
}

if (!$has_access) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to export sales order data."
    ];
    header("Location: sales_order_list_all.php");
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'order_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query
$query = "SELECT * FROM sales_order WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (sales_order_no LIKE ? OR customer_name LIKE ? OR customer_code LIKE ? OR truck_code LIKE ? OR plate_number LIKE ? OR driver LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND order_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND order_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['sales_order_no', 'customer_name', 'order_date', 'delivery_date', 'status', 'amount', 'created_date'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'order_date';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$query .= " ORDER BY $sort_by $sort_order";

// Execute query
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
} else {
    $result = $conn->query($query);
}

$sales_orders = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_orders[] = $row;
    }
}

// Build export data
$export_data = [];

// Add header row with company info
$export_data[] = ['ONCALL FORWARDING CORPORATION'];
$export_data[] = ['Sales Order List Report'];
$export_data[] = ['Generated: ' . date('F d, Y h:i A')];
if ($search) {
    $export_data[] = ['Search Term: ' . $search];
}
if ($status_filter) {
    $export_data[] = ['Status Filter: ' . ucfirst($status_filter)];
}
if ($date_from) {
    $export_data[] = ['Date From: ' . date('M d, Y', strtotime($date_from))];
}
if ($date_to) {
    $export_data[] = ['Date To: ' . date('M d, Y', strtotime($date_to))];
}
$export_data[] = ['Total Sales Orders: ' . count($sales_orders)];

// Calculate total amount
$total_amount = array_sum(array_column($sales_orders, 'amount'));
$export_data[] = ['Total Amount: ₱ ' . number_format($total_amount, 2)];
$export_data[] = []; // Empty row for spacing

// Column headers - Full detailed list
$export_data[] = [
    '#',
    'Sales Order No.',
    'Customer Code',
    'Customer Name',
    'Order Date',
    'Delivery Date',
    'Payment Terms',
    'VAT %',
    'Truck Code',
    'Plate Number',
    'Brand',
    'Model',
    'Unit',
    'Driver',
    'Destination From',
    'Destination To',
    'Delivery Address',
    'Quantity',
    'Unit Price',
    'Discount %',
    'Amount',
    'Discount Amount',
    'Total Amount',
    'Notes',
    'Status',
    'Created By',
    'Created Date',
    'Updated By',
    'Updated At'
];

// Data rows
$counter = 1;
foreach ($sales_orders as $so) {
    $export_data[] = [
        $counter,
        $so['sales_order_no'] ?? '',
        $so['customer_code'] ?? '',
        $so['customer_name'] ?? '',
        $so['order_date'] ? date('M d, Y', strtotime($so['order_date'])) : '',
        $so['delivery_date'] ? date('M d, Y', strtotime($so['delivery_date'])) : '',
        $so['payment_terms'] ?? '',
        $so['vat_percent'] ? $so['vat_percent'] . '%' : '0%',
        $so['truck_code'] ?? '',
        $so['plate_number'] ?? '',
        $so['brand'] ?? '',
        $so['model'] ?? '',
        $so['unit'] ?? '',
        $so['driver'] ?? '',
        $so['destination_from'] ?? '',
        $so['destination_to'] ?? '',
        $so['delivery_address'] ?? '',
        number_format($so['quantity'] ?? 0),
        number_format($so['unit_price'] ?? 0, 2),
        $so['discount_percent'] ? $so['discount_percent'] . '%' : '0%',
        number_format($so['amount'] ?? 0, 2),
        number_format($so['discount_amount'] ?? 0, 2),
        number_format($so['amount'] ?? 0, 2),
        $so['notes'] ?? '',
        $so['status'] ?? '',
        $so['created_by'] ?? '',
        $so['created_date'] ? date('M d, Y h:i A', strtotime($so['created_date'])) : '',
        $so['updated_by'] ?? '',
        $so['updated_at'] ? date('M d, Y h:i A', strtotime($so['updated_at'])) : ''
    ];
    $counter++;
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Sales Orders Exported: ' . count($sales_orders)];
$export_data[] = ['Total Amount: ₱ ' . number_format($total_amount, 2)];

// Calculate summary by status
$status_summary = [];
foreach ($sales_orders as $so) {
    $status = $so['status'] ?? 'Unknown';
    if (!isset($status_summary[$status])) {
        $status_summary[$status] = ['count' => 0, 'amount' => 0];
    }
    $status_summary[$status]['count']++;
    $status_summary[$status]['amount'] += floatval($so['amount'] ?? 0);
}

$export_data[] = ['Status Summary:'];
foreach ($status_summary as $status => $data) {
    $export_data[] = ['  ' . ucfirst($status) . ':', $data['count'] . ' orders', '₱ ' . number_format($data['amount'], 2)];
}

$export_data[] = ['Exported by: ' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown')];
$export_data[] = ['Exported on: ' . date('F d, Y h:i A')];

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="sales_order_list_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Create the Excel file
$output = fopen('php://output', 'w');

// Write data to file using tab delimiter for Excel compatibility
foreach ($export_data as $row) {
    fputcsv($output, $row, "\t");
}

fclose($output);
exit;
?>