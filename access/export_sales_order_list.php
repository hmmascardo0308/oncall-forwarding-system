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

// ============================================================
// FETCH SPECIAL CHARGES FOR ALL DISPLAYED SOs (bulk query)
// ============================================================
$special_charges_map = []; // keyed by sales_order_no => array of charges

if (!empty($sales_orders)) {
    $so_numbers = array_column($sales_orders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $charge_query = "SELECT sales_order_no, charge_kind, charge_amount 
                         FROM sales_order_special_charge 
                         WHERE sales_order_no IN ($placeholders)";
        $stmt = $conn->prepare($charge_query);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($so_numbers)), ...$so_numbers);
            $stmt->execute();
            $charge_result = $stmt->get_result();
            while ($row = $charge_result->fetch_assoc()) {
                $so_no = $row['sales_order_no'];
                if (!isset($special_charges_map[$so_no])) {
                    $special_charges_map[$so_no] = [];
                }
                $special_charges_map[$so_no][] = [
                    'kind'   => $row['charge_kind'],
                    'amount' => floatval($row['charge_amount'])
                ];
            }
        }
    }
}

// ============================================================
// HELPER: Compute the full amount breakdown for a single SO
// ============================================================
function computeSOAmounts($so, $special_charges_map) {
    $so_no = $so['sales_order_no'] ?? '';

    $net_amount      = floatval($so['amount'] ?? 0);          // already-discounted net
    $discount_amount = floatval($so['discount_amount'] ?? 0);
    $vat_percent     = floatval($so['vat_percent'] ?? 0);

    // Reconstruct the gross subtotal (before discount)
    $gross_subtotal = $net_amount + $discount_amount;

    // VAT is computed on the net amount (matches how save_so.php stored it)
    $vat_amount = $net_amount * ($vat_percent / 100);

    // Special charges
    $special_total = 0;
    if (!empty($special_charges_map[$so_no])) {
        foreach ($special_charges_map[$so_no] as $sc) {
            $special_total += floatval($sc['amount'] ?? 0);
        }
    }

    return [
        'gross_subtotal'  => $gross_subtotal,
        'net_amount'      => $net_amount,
        'discount_amount' => $discount_amount,
        'vat_percent'     => $vat_percent,
        'vat_amount'      => $vat_amount,
        'special_total'   => $special_total,
        'grand_total'     => $net_amount + $vat_amount + $special_total,
        'special_items'   => $special_charges_map[$so_no] ?? []
    ];
}

// ============================================================
// Compute totals
// ============================================================
$grand_total_sum   = 0;
$grand_vat_sum     = 0;
$grand_charges_sum = 0;
$grand_net_sum     = 0;
$grand_discount_sum = 0;
$grand_subtotal_sum = 0;

foreach ($sales_orders as $so) {
    $calc = computeSOAmounts($so, $special_charges_map);
    $grand_total_sum    += $calc['grand_total'];
    $grand_vat_sum      += $calc['vat_amount'];
    $grand_charges_sum  += $calc['special_total'];
    $grand_net_sum      += $calc['net_amount'];
    $grand_discount_sum += $calc['discount_amount'];
    $grand_subtotal_sum += $calc['gross_subtotal'];
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

// Enhanced summary totals
$export_data[] = ['Gross Subtotal: ₱ ' . number_format($grand_subtotal_sum, 2)];
$export_data[] = ['Total Discount: ₱ ' . number_format($grand_discount_sum, 2)];
$export_data[] = ['Net Amount: ₱ ' . number_format($grand_net_sum, 2)];
$export_data[] = ['Total VAT: ₱ ' . number_format($grand_vat_sum, 2)];
$export_data[] = ['Total Special Charges: ₱ ' . number_format($grand_charges_sum, 2)];
$export_data[] = ['GRAND TOTAL: ₱ ' . number_format($grand_total_sum, 2)];
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
    'Gross Subtotal',
    'Discount Amount',
    'Net Amount',
    'VAT Amount',
    'Special Charges',
    'Special Charges Breakdown',
    'GRAND TOTAL',
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
    $calc = computeSOAmounts($so, $special_charges_map);

    // Build special charges breakdown string for a single cell
    $special_breakdown = '';
    if (!empty($calc['special_items'])) {
        $parts = [];
        foreach ($calc['special_items'] as $item) {
            $parts[] = $item['kind'] . ': ₱' . number_format($item['amount'], 2);
        }
        $special_breakdown = implode('; ', $parts);
    }

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
        number_format($calc['gross_subtotal'], 2),
        number_format($calc['discount_amount'], 2),
        number_format($calc['net_amount'], 2),
        number_format($calc['vat_amount'], 2),
        number_format($calc['special_total'], 2),
        $special_breakdown,
        number_format($calc['grand_total'], 2),
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
$export_data[] = ['Gross Subtotal: ₱ ' . number_format($grand_subtotal_sum, 2)];
$export_data[] = ['Total Discount: ₱ ' . number_format($grand_discount_sum, 2)];
$export_data[] = ['Net Amount: ₱ ' . number_format($grand_net_sum, 2)];
$export_data[] = ['Total VAT: ₱ ' . number_format($grand_vat_sum, 2)];
$export_data[] = ['Total Special Charges: ₱ ' . number_format($grand_charges_sum, 2)];
$export_data[] = ['GRAND TOTAL: ₱ ' . number_format($grand_total_sum, 2)];

// Calculate summary by status (now includes grand_total)
$status_summary = [];
foreach ($sales_orders as $so) {
    $status = $so['status'] ?? 'Unknown';
    if (!isset($status_summary[$status])) {
        $status_summary[$status] = ['count' => 0, 'amount' => 0];
    }
    $calc = computeSOAmounts($so, $special_charges_map);
    $status_summary[$status]['count']++;
    $status_summary[$status]['amount'] += $calc['grand_total'];
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