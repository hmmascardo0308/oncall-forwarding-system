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

// ============================================================
// FETCH PER-INVOICE AGGREGATES (charge_amount, vat, discount, subtotal, balance)
// Because one invoice can have multiple SOs, we sum across the rows
// ============================================================
$invoice_aggregates = []; // keyed by invoice_no

if (!empty($service_invoices)) {
    foreach ($service_invoices as $row) {
        $inv_no = $row['invoice_no'];
        if (!isset($invoice_aggregates[$inv_no])) {
            $invoice_aggregates[$inv_no] = [
                'item_count'       => 0,
                'subtotal'         => 0,   // sum of `amount` (net) across rows
                'discount_total'   => 0,
                'vat_total'        => 0,
                'charge_total'     => 0,   // sum of `charge_amount`
                'total_amount'     => 0,
                'amount_paid'      => floatval($row['amount_paid'] ?? 0),
                'additional_fee'   => floatval($row['additional_fee'] ?? 0),
                'payment_status'   => $row['payment_status'] ?? '',
                'due_date'         => $row['due_date'] ?? '',
                'sales_orders'     => [],
                'payment_method'   => $row['payment_method'] ?? '',
                'payment_terms'    => $row['payment_terms'] ?? '',
                'customer_code'    => $row['customer_code'] ?? '',
                'customer_name'    => $row['customer_name'] ?? '',
                'invoice_date'     => $row['invoice_date'] ?? '',
                'created_date'     => $row['created_date'] ?? '',
                'created_by'       => $row['created_by'] ?? '',
                'updated_by'       => $row['updated_by'] ?? '',
                'updated_at'       => $row['updated_at'] ?? '',
                'paid_at'          => $row['paid_at'] ?? '',
                'notes'            => $row['notes'] ?? '',
                'delivery_address' => $row['delivery_address'] ?? '',
                'status'           => $row['status'] ?? '',
                // Vehicle (kept from first row; invoices are usually single-vehicle)
                'truck_code'       => $row['truck_code'] ?? '',
                'plate_number'     => $row['plate_number'] ?? '',
                'brand'            => $row['brand'] ?? '',
                'model'            => $row['model'] ?? '',
                'unit'             => $row['unit'] ?? '',
                'destination_from' => $row['destination_from'] ?? '',
                'destination_to'   => $row['destination_to'] ?? '',
                'order_date'       => $row['order_date'] ?? '',
                'delivery_date'    => $row['delivery_date'] ?? '',
                'quantity'         => 0,
                'unit_price'       => floatval($row['unit_price'] ?? 0),
                'vat_percent'      => $row['vat_percent'] ?? '',
                'discount_percent' => $row['discount_percent'] ?? '',
            ];
        }
        $invoice_aggregates[$inv_no]['item_count']++;
        $invoice_aggregates[$inv_no]['subtotal']       += floatval($row['amount'] ?? 0);
        $invoice_aggregates[$inv_no]['discount_total'] += floatval($row['discount_amount'] ?? 0);
        $invoice_aggregates[$inv_no]['vat_total']      += floatval($row['vat_amount'] ?? 0);
        $invoice_aggregates[$inv_no]['charge_total']   += floatval($row['charge_amount'] ?? 0); // NEW
        $invoice_aggregates[$inv_no]['total_amount']   += floatval($row['total_amount'] ?? 0);
        $invoice_aggregates[$inv_no]['quantity']       += floatval($row['quantity'] ?? 0);
        if (!empty($row['sales_order_no'])) {
            $invoice_aggregates[$inv_no]['sales_orders'][] = $row['sales_order_no'];
        }
        // Prefer latest non-empty invoice-level fields
        if (!empty($row['payment_method'])) $invoice_aggregates[$inv_no]['payment_method'] = $row['payment_method'];
        if (!empty($row['payment_terms']))  $invoice_aggregates[$inv_no]['payment_terms']  = $row['payment_terms'];
        if (!empty($row['notes']))          $invoice_aggregates[$inv_no]['notes']          = $row['notes'];
        if (!empty($row['payment_status'])) $invoice_aggregates[$inv_no]['payment_status'] = $row['payment_status'];
        if (!empty($row['due_date']))       $invoice_aggregates[$inv_no]['due_date']       = $row['due_date'];
        if (!empty($row['paid_at']))        $invoice_aggregates[$inv_no]['paid_at']        = $row['paid_at'];
    }
}

// ============================================================
// Compute report-level totals (mirrors list page footer)
// ============================================================
$grand_total_sum   = 0;
$grand_vat_sum     = 0;
$grand_disc_sum    = 0;
$grand_charge_sum  = 0;
$grand_paid_sum    = 0;
$grand_subtotal_sum = 0;
$grand_additional_fee_sum = 0;
$grand_balance_sum = 0;

foreach ($invoice_aggregates as $inv_no => $agg) {
    $grand_total_sum          += $agg['total_amount'];
    $grand_vat_sum            += $agg['vat_total'];
    $grand_disc_sum           += $agg['discount_total'];
    $grand_charge_sum         += $agg['charge_total'];
    $grand_paid_sum           += $agg['amount_paid'];
    $grand_subtotal_sum       += $agg['subtotal'];
    $grand_additional_fee_sum += $agg['additional_fee'];
    $balance = $agg['total_amount'] - $agg['amount_paid'] + $agg['additional_fee'];
    $grand_balance_sum += max(0, $balance);
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
$export_data[] = ['Total Invoices: ' . count($invoice_aggregates)];

// Enhanced summary totals
$export_data[] = ['Subtotal (Net): ₱ ' . number_format($grand_subtotal_sum, 2)];
$export_data[] = ['Total Discount: ₱ ' . number_format($grand_disc_sum, 2)];
$export_data[] = ['Total VAT: ₱ ' . number_format($grand_vat_sum, 2)];
$export_data[] = ['Total Special Charges: ₱ ' . number_format($grand_charge_sum, 2)];
$export_data[] = ['Total Amount: ₱ ' . number_format($grand_total_sum, 2)];
$export_data[] = ['Total Paid: ₱ ' . number_format($grand_paid_sum, 2)];
$export_data[] = ['Total Additional Fees: ₱ ' . number_format($grand_additional_fee_sum, 2)];
$export_data[] = ['Outstanding Balance: ₱ ' . number_format($grand_balance_sum, 2)];
$export_data[] = []; // Empty row for spacing

// Column headers - Aggregated one row per invoice
$export_data[] = [
    '#',
    'Invoice No.',
    'Sales Order No(s).',
    'Items',
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
    'Subtotal (Net)',
    'Discount Amount',
    'VAT Amount',
    'Special Charges',
    'Total Amount',
    'Amount Paid',
    'Additional Fee',
    'Balance Due',
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

// Data rows — one row per aggregated invoice
$counter = 1;
foreach ($invoice_aggregates as $inv_no => $agg) {
    $balance = $agg['total_amount'] - $agg['amount_paid'] + $agg['additional_fee'];
    $so_list = !empty($agg['sales_orders']) ? implode(', ', $agg['sales_orders']) : '';

    $export_data[] = [
        $counter,
        $inv_no,
        $so_list,
        $agg['item_count'],
        $agg['customer_code'],
        $agg['customer_name'],
        $agg['order_date'] ? date('M d, Y', strtotime($agg['order_date'])) : '',
        $agg['delivery_date'] ? date('M d, Y', strtotime($agg['delivery_date'])) : '',
        $agg['invoice_date'] ? date('M d, Y', strtotime($agg['invoice_date'])) : '',
        $agg['due_date'] ? date('M d, Y', strtotime($agg['due_date'])) : '',
        $agg['payment_terms'],
        $agg['truck_code'],
        $agg['plate_number'],
        $agg['brand'],
        $agg['model'],
        $agg['unit'],
        $agg['destination_from'],
        $agg['destination_to'],
        $agg['delivery_address'],
        number_format($agg['quantity']),
        number_format($agg['unit_price'], 2),
        $agg['vat_percent'] ? $agg['vat_percent'] . '%' : '0%',
        $agg['discount_percent'] ? $agg['discount_percent'] . '%' : '0%',
        number_format($agg['subtotal'], 2),
        number_format($agg['discount_total'], 2),
        number_format($agg['vat_total'], 2),
        number_format($agg['charge_total'], 2),
        number_format($agg['total_amount'], 2),
        number_format($agg['amount_paid'], 2),
        number_format($agg['additional_fee'], 2),
        number_format(max(0, $balance), 2),
        $agg['payment_method'],
        $agg['payment_status'],
        $agg['paid_at'] ? date('M d, Y h:i A', strtotime($agg['paid_at'])) : '',
        $agg['status'],
        $agg['notes'],
        $agg['created_by'],
        $agg['created_date'] ? date('M d, Y h:i A', strtotime($agg['created_date'])) : '',
        $agg['updated_by'],
        $agg['updated_at'] ? date('M d, Y h:i A', strtotime($agg['updated_at'])) : ''
    ];
    $counter++;
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Invoices Exported: ' . count($invoice_aggregates)];
$export_data[] = ['Subtotal (Net): ₱ ' . number_format($grand_subtotal_sum, 2)];
$export_data[] = ['Total Discount: ₱ ' . number_format($grand_disc_sum, 2)];
$export_data[] = ['Total VAT: ₱ ' . number_format($grand_vat_sum, 2)];
$export_data[] = ['Total Special Charges: ₱ ' . number_format($grand_charge_sum, 2)];
$export_data[] = ['Total Amount: ₱ ' . number_format($grand_total_sum, 2)];
$export_data[] = ['Total Paid: ₱ ' . number_format($grand_paid_sum, 2)];
$export_data[] = ['Total Additional Fees: ₱ ' . number_format($grand_additional_fee_sum, 2)];
$export_data[] = ['Outstanding Balance: ₱ ' . number_format($grand_balance_sum, 2)];

// Calculate summary by payment status (now uses grand total per invoice)
$payment_summary = [];
foreach ($invoice_aggregates as $inv_no => $agg) {
    $status = $agg['payment_status'] ?: 'Unknown';
    if (!isset($payment_summary[$status])) {
        $payment_summary[$status] = ['count' => 0, 'amount' => 0];
    }
    $payment_summary[$status]['count']++;
    $payment_summary[$status]['amount'] += $agg['total_amount'];
}

$export_data[] = ['Payment Status Summary:'];
foreach ($payment_summary as $status => $data) {
    $export_data[] = ['  ' . ucfirst($status) . ':', $data['count'] . ' invoices', '₱ ' . number_format($data['amount'], 2)];
}

// Summary by invoice status
$invoice_status_summary = [];
foreach ($invoice_aggregates as $inv_no => $agg) {
    $status = $agg['status'] ?: 'Unknown';
    if (!isset($invoice_status_summary[$status])) {
        $invoice_status_summary[$status] = ['count' => 0, 'amount' => 0];
    }
    $invoice_status_summary[$status]['count']++;
    $invoice_status_summary[$status]['amount'] += $agg['total_amount'];
}

$export_data[] = ['Invoice Status Summary:'];
foreach ($invoice_status_summary as $status => $data) {
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