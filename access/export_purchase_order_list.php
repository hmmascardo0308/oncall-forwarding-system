<?php
// export_purchase_order_list.php
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
$allowed_roles = ['admin', 'purchase_order_maker', 'purchase_order_checker', 'purchase_order_approver'];
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
        'text' => "You don't have permission to export purchase order data."
    ];
    header("Location: purchase_order_list_all.php");
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$delivery_status_filter = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$payment_status_filter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query
$query = "SELECT * FROM purchase_order WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (po_number LIKE ? OR supplier_name LIKE ? OR supplier_code LIKE ? OR item LIKE ? OR item_code LIKE ? OR created_by LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
}

if (!empty($delivery_status_filter)) {
    $query .= " AND delivery_status = ?";
    $params[] = $delivery_status_filter;
    $types .= "s";
}

if (!empty($payment_status_filter)) {
    $query .= " AND delivery_payment = ?";
    $params[] = $payment_status_filter;
    $types .= "s";
}

if (!empty($supplier_filter)) {
    $query .= " AND supplier_name LIKE ?";
    $params[] = "%$supplier_filter%";
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['po_number', 'created_at', 'delivered_date', 'supplier_name', 'purchase_type', 'net_amount_due', 'delivery_status', 'delivery_payment', 'created_by'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'created_at';
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

$purchase_orders = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }
}

// Build export data
$export_data = [];

// Add header row with company info
$export_data[] = ['ONCALL FORWARDING CORPORATION'];
$export_data[] = ['Purchase Order List Report'];
$export_data[] = ['Generated: ' . date('F d, Y h:i A')];
if ($search) {
    $export_data[] = ['Search Term: ' . $search];
}
if ($delivery_status_filter) {
    $export_data[] = ['Delivery Status Filter: ' . ucfirst($delivery_status_filter)];
}
if ($payment_status_filter) {
    $export_data[] = ['Payment Status Filter: ' . ucfirst($payment_status_filter)];
}
if ($supplier_filter) {
    $export_data[] = ['Supplier Filter: ' . $supplier_filter];
}
if ($date_from) {
    $export_data[] = ['Date From: ' . date('M d, Y', strtotime($date_from))];
}
if ($date_to) {
    $export_data[] = ['Date To: ' . date('M d, Y', strtotime($date_to))];
}
$export_data[] = ['Total Purchase Orders: ' . count($purchase_orders)];

// Calculate total amount
$total_amount = array_sum(array_column($purchase_orders, 'net_amount_due'));
$export_data[] = ['Total Amount: ₱ ' . number_format($total_amount, 2)];
$export_data[] = []; // Empty row for spacing

// Column headers - Updated to match new table layout
$export_data[] = [
    '#',
    'PO Number',
    'Transaction Date',
    'Delivery Date',
    'Supplier Code',
    'Supplier Name',
    'Purpose (Purchase Type)',
    'Amount (Net Amount Due)',
    'PO Status (Delivery Status)',
    'Payment Status (Delivery Payment)',
    'Created By',
    // Additional details (kept for full data export)
    'PO Date',
    'Expected Delivery',
    'Payment Terms',
    'Currency',
    'Delivery Mode',
    'Delivery Address',
    'Truck Code',
    'Trailer Code',
    'Prime Mover',
    'Customer Code',
    'Brand',
    'Model',
    'Item Code',
    'Item',
    'Unit',
    'Qty Ordered',
    'Qty Received',
    'Warranty',
    'Remarks',
    'Unit Cost',
    'Subtotal',
    'Total VAT',
    'Total Amount',
    'Total Amount Paid',
    'Withholding Tax %',
    'Withholding Tax Amount',
    'Status',
    'Received By',
    'Updated By',
    'Updated At',
    'Print Status'
];

// Data rows
$counter = 1;
foreach ($purchase_orders as $po) {
    $export_data[] = [
        $counter,
        $po['po_number'] ?? '',
        $po['created_at'] ? date('M d, Y h:i A', strtotime($po['created_at'])) : '',
        $po['delivered_date'] ? date('M d, Y', strtotime($po['delivered_date'])) : '',
        $po['supplier_code'] ?? '',
        $po['supplier_name'] ?? '',
        $po['purchase_type'] ?? '',
        number_format($po['net_amount_due'] ?? 0, 2),
        $po['delivery_status'] ?? '',
        $po['delivery_payment'] ?? '',
        $po['created_by'] ?? '',
        // Additional details
        $po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '',
        $po['expected_delivery'] ? date('M d, Y', strtotime($po['expected_delivery'])) : '',
        $po['payment_terms'] ?? '',
        $po['currency'] ?? '',
        $po['delivery_mode'] ?? '',
        $po['delivery_address'] ?? '',
        $po['truck_code'] ?? '',
        $po['trailer_code'] ?? '',
        $po['prime_mover_code'] ?? '',
        $po['customer_code'] ?? '',
        $po['brand'] ?? '',
        $po['model'] ?? '',
        $po['item_code'] ?? '',
        $po['item'] ?? '',
        $po['unit'] ?? '',
        number_format($po['qty_ordered'] ?? 0),
        number_format($po['qty_received'] ?? 0),
        $po['warranty'] ?? '',
        $po['remarks'] ?? '',
        number_format($po['unit_cost'] ?? 0, 2),
        number_format($po['subtotal'] ?? 0, 2),
        number_format($po['total_vat'] ?? 0, 2),
        number_format($po['total_amount'] ?? 0, 2),
        number_format($po['total_amount_paid'] ?? 0, 2),
        $po['withholding_tax_percent'] ? $po['withholding_tax_percent'] . '%' : '',
        number_format($po['withholding_tax_amount'] ?? 0, 2),
        $po['status'] ?? '',
        $po['received_by'] ?? '',
        $po['updated_by'] ?? '',
        $po['updated_at'] ? date('M d, Y h:i A', strtotime($po['updated_at'])) : '',
        $po['print_status'] ?? ''
    ];
    $counter++;
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Purchase Orders Exported: ' . count($purchase_orders)];
$export_data[] = ['Total Amount: ₱ ' . number_format($total_amount, 2)];
$export_data[] = ['Exported by: ' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown')];

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="purchase_order_list_' . date('Y-m-d') . '.xls"');
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