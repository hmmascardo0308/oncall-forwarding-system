<?php
// export_service_invoice_list.php
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
$allowed_roles = ['admin', 'service_invoice_maker', 'service_invoice_checker', 'service_invoice_approver'];
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
        'text' => "You don't have permission to export service invoice data."
    ];
    header("Location: service_invoice_list_all.php");
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$payment_status_filter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'invoice_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query
$query = "SELECT * FROM service_invoice WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (invoice_no LIKE ? OR customer_name LIKE ? OR customer_code LIKE ? OR sales_order_no LIKE ? OR truck_code LIKE ? OR plate_number LIKE ?)";
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

if (!empty($payment_status_filter)) {
    $query .= " AND payment_status = ?";
    $params[] = $payment_status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND invoice_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND invoice_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['invoice_no', 'customer_name', 'invoice_date', 'status', 'payment_status', 'total_amount', 'created_date'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'invoice_date';
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

$service_invoices = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $service_invoices[] = $row;
    }
}

// Build export data
$export_data = [];

// Add header row with company info
$export_data[] = ['ONCALL FORWARDING CORPORATION'];
$export_data[] = ['Service Invoice List Report'];
$export_data[] = ['Generated: ' . date('F d, Y h:i A')];
if ($search) {
    $export_data[] = ['Search Term: ' . $search];
}
if ($status_filter) {
    $export_data[] = ['Status Filter: ' . ucfirst($status_filter)];
}
if ($payment_status_filter) {
    $export_data[] = ['Payment Status Filter: ' . ucfirst($payment_status_filter)];
}
if ($date_from) {
    $export_data[] = ['Date From: ' . date('M d, Y', strtotime($date_from))];
}
if ($date_to) {
    $export_data[] = ['Date To: ' . date('M d, Y', strtotime($date_to))];
}
$export_data[] = ['Total Invoices: ' . count($service_invoices)];

// Calculate total amount
$total_amount = array_sum(array_column($service_invoices, 'total_amount'));
$export_data[] = ['Total Amount: ₱ ' . number_format($total_amount, 2)];
$export_data[] = []; // Empty row for spacing

// Column headers - Full detailed list
$export_data[] = [
    '#',
    'Invoice No.',
    'Sales Order No.',
    'Customer Code',
    'Customer Name',
    'Order Date',
    'Delivery Date',
    'Invoice Date',
    'Due Date',
    'Payment Terms',
    'Truck Code',
    'Plate Number',
    'Brand',
    'Model',
    'Unit',
    'Destination From',
    'Destination To',
    'Delivery Address',
    'Quantity',
    'Unit Price',
    'VAT %',
    'Discount %',
    'Amount',
    'Discount Amount',
    'VAT Amount',
    'Total Amount',
    'Amount Paid',
    'Additional Fee',
    'Payment Method',
    'Payment Status',
    'Paid At',
    'Status',
    'Notes',
    'Created By',
    'Created Date',
    'Updated By',
    'Updated At'
];

// Data rows
$counter = 1;
foreach ($service_invoices as $si) {
    $export_data[] = [
        $counter,
        $si['invoice_no'] ?? '',
        $si['sales_order_no'] ?? '',
        $si['customer_code'] ?? '',
        $si['customer_name'] ?? '',
        $si['order_date'] ? date('M d, Y', strtotime($si['order_date'])) : '',
        $si['delivery_date'] ? date('M d, Y', strtotime($si['delivery_date'])) : '',
        $si['invoice_date'] ? date('M d, Y', strtotime($si['invoice_date'])) : '',
        $si['due_date'] ? date('M d, Y', strtotime($si['due_date'])) : '',
        $si['payment_terms'] ?? '',
        $si['truck_code'] ?? '',
        $si['plate_number'] ?? '',
        $si['brand'] ?? '',
        $si['model'] ?? '',
        $si['unit'] ?? '',
        $si['destination_from'] ?? '',
        $si['destination_to'] ?? '',
        $si['delivery_address'] ?? '',
        number_format($si['quantity'] ?? 0),
        number_format($si['unit_price'] ?? 0, 2),
        $si['vat_percent'] ? $si['vat_percent'] . '%' : '0%',
        $si['discount_percent'] ? $si['discount_percent'] . '%' : '0%',
        number_format($si['amount'] ?? 0, 2),
        number_format($si['discount_amount'] ?? 0, 2),
        number_format($si['vat_amount'] ?? 0, 2),
        number_format($si['total_amount'] ?? 0, 2),
        number_format($si['amount_paid'] ?? 0, 2),
        number_format($si['additional_fee'] ?? 0, 2),
        $si['payment_method'] ?? '',
        $si['payment_status'] ?? '',
        $si['paid_at'] ? date('M d, Y h:i A', strtotime($si['paid_at'])) : '',
        $si['status'] ?? '',
        $si['notes'] ?? '',
        $si['created_by'] ?? '',
        $si['created_date'] ? date('M d, Y h:i A', strtotime($si['created_date'])) : '',
        $si['updated_by'] ?? '',
        $si['updated_at'] ? date('M d, Y h:i A', strtotime($si['updated_at'])) : ''
    ];
    $counter++;
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Invoices Exported: ' . count($service_invoices)];
$export_data[] = ['Total Amount: ₱ ' . number_format($total_amount, 2)];

// Calculate summary by payment status
$payment_summary = [];
foreach ($service_invoices as $si) {
    $status = $si['payment_status'] ?? 'Unknown';
    if (!isset($payment_summary[$status])) {
        $payment_summary[$status] = ['count' => 0, 'amount' => 0];
    }
    $payment_summary[$status]['count']++;
    $payment_summary[$status]['amount'] += floatval($si['total_amount'] ?? 0);
}

$export_data[] = ['Payment Status Summary:'];
foreach ($payment_summary as $status => $data) {
    $export_data[] = ['  ' . ucfirst($status) . ':', $data['count'] . ' invoices', '₱ ' . number_format($data['amount'], 2)];
}

$export_data[] = ['Exported by: ' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown')];
$export_data[] = ['Exported on: ' . date('F d, Y h:i A')];

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="service_invoice_list_' . date('Y-m-d') . '.xls"');
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