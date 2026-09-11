<?php
// export_purchase_orders.php
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

// Check access - only admin or purchase_order_maker
$is_admin = in_array('admin', $user_roles);
$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to export purchase order data."
    ];
    header("Location: purchase_orders_all.php");
    exit;
}

// Get filter parameters from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filter_delivery_status = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$filter_delivery_payment = isset($_GET['delivery_payment']) ? trim($_GET['delivery_payment']) : '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = '';

// Add search condition if search term is provided
if (!empty($search_term)) {
    $where_conditions[] = "(po.po_number LIKE ? OR po.supplier_name LIKE ? OR po.supplier_code LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types .= 'sss';
}

if (!empty($filter_supplier)) {
    $where_conditions[] = "po.supplier_name LIKE ?";
    $params[] = "%$filter_supplier%";
    $types .= 's';
}

if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $where_conditions[] = "po.po_date BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
    $types .= 'ss';
} elseif (!empty($filter_date_from)) {
    $where_conditions[] = "po.po_date >= ?";
    $params[] = $filter_date_from;
    $types .= 's';
} elseif (!empty($filter_date_to)) {
    $where_conditions[] = "po.po_date <= ?";
    $params[] = $filter_date_to;
    $types .= 's';
}

if (!empty($filter_delivery_status)) {
    $where_conditions[] = "po.delivery_status = ?";
    $params[] = $filter_delivery_status;
    $types .= 's';
}

if (!empty($filter_delivery_payment)) {
    $where_conditions[] = "po.delivery_payment = ?";
    $params[] = $filter_delivery_payment;
    $types .= 's';
}

// Build the WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Fetch all purchase orders grouped by PO number with filters
$sql = "SELECT 
            po.po_number, 
            MAX(po.supplier_code) as supplier_code,
            MAX(po.supplier_name) as supplier_name,
            MAX(po.purchase_type) as purchase_type,
            MAX(po.po_date) as po_date,
            MAX(po.expected_delivery) as expected_delivery,
            MAX(po.payment_terms) as payment_terms,
            MAX(po.currency) as currency,
            MAX(po.delivery_address) as delivery_address,
            MAX(po.delivery_mode) as delivery_mode,
            MAX(po.warranty) as warranty,
            MAX(po.remarks) as remarks,
            MAX(po.freight) as freight,
            MAX(po.status) as status,
            MAX(po.created_by) as created_by,
            MAX(po.created_at) as created_at,
            MAX(po.delivery_status) as delivery_status,
            MAX(po.delivery_payment) as delivery_payment,
            MAX(po.delivered_date) as delivered_date,
            MAX(po.received_by) as received_by,
            SUM(po.subtotal) as subtotal,
            SUM(po.total_vat) as total_vat,
            SUM(po.total_amount_paid) as total_amount_paid,
            GROUP_CONCAT(CONCAT(po.item_code, '|', po.item, '|', po.qty_ordered, '|', IFNULL(po.qty_received, 0), '|', po.unit_cost, '|', po.total_amount, '|', IFNULL(po.total_amount_paid, 0)) SEPARATOR '||') as items_detail
        FROM purchase_order po
        $where_clause
        GROUP BY po.po_number 
        ORDER BY MAX(po.created_at) DESC";

// Prepare and execute the query with filters
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$purchase_orders = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items = [];
        $subtotal_amount_paid = 0;
        if ($row['items_detail']) {
            $items_raw = explode('||', $row['items_detail']);
            foreach ($items_raw as $item_raw) {
                $parts = explode('|', $item_raw);
                if (count($parts) >= 7) {
                    $qty_received = floatval($parts[3]);
                    $unit_cost = floatval($parts[4]);
                    $total_amount = floatval($parts[5]);
                    $total_amount_paid = floatval($parts[6]);
                    $qty_ordered = floatval($parts[2]);
                    
                    if ($qty_received >= $qty_ordered) {
                        $amount_paid = $total_amount;
                    } else {
                        $amount_paid = $qty_received * $unit_cost;
                    }
                    $subtotal_amount_paid += $amount_paid;
                    
                    $items[] = [
                        'item_code' => $parts[0],
                        'item_name' => $parts[1],
                        'qty_ordered' => $parts[2],
                        'qty_received' => $parts[3],
                        'unit_cost' => $parts[4],
                        'total_amount' => $parts[5],
                        'total_amount_paid' => $parts[6],
                        'amount_paid' => $amount_paid
                    ];
                }
            }
        }
        $row['items'] = $items;
        $row['total_amount_paid'] = $subtotal_amount_paid;
        $purchase_orders[] = $row;
    }
}

// Build export data
$export_data = [];

// Add header row with company info
$export_data[] = ['ONCALL FORWARDING CORPORATION'];
$export_data[] = ['Purchase Orders Report'];
$export_data[] = ['Generated: ' . date('F d, Y h:i A')];
if ($search_term) {
    $export_data[] = ['Search Term: ' . $search_term];
}
if ($filter_supplier) {
    $export_data[] = ['Supplier Filter: ' . $filter_supplier];
}
if ($filter_date_from && $filter_date_to) {
    $export_data[] = ['Date Range: ' . date('M d, Y', strtotime($filter_date_from)) . ' to ' . date('M d, Y', strtotime($filter_date_to))];
} elseif ($filter_date_from) {
    $export_data[] = ['Date From: ' . date('M d, Y', strtotime($filter_date_from))];
} elseif ($filter_date_to) {
    $export_data[] = ['Date To: ' . date('M d, Y', strtotime($filter_date_to))];
}
if ($filter_delivery_status) {
    $export_data[] = ['Delivery Status: ' . $filter_delivery_status];
}
if ($filter_delivery_payment) {
    $export_data[] = ['Payment Status: ' . $filter_delivery_payment];
}
$export_data[] = ['Total Purchase Orders: ' . count($purchase_orders)];
$export_data[] = []; // Empty row for spacing

// Column headers
$export_data[] = [
    '#',
    'PO Number',
    'Supplier Code',
    'Supplier Name',
    'Purchase Type',
    'PO Date',
    'Expected Delivery',
    'Payment Terms',
    'Currency',
    'Delivery Address',
    'Delivery Mode',
    'Warranty',
    'Freight',
    'Remarks',
    'Status',
    'Delivery Status',
    'Delivery Payment',
    'Delivered Date',
    'Received By',
    'Subtotal',
    'Total VAT',
    'Total Amount Paid',
    'Created By',
    'Created At',
    'Item Code',
    'Item Name',
    'Qty Ordered',
    'Qty Received',
    'Unit Cost',
    'Total Amount',
    'Amount Paid'
];

// Data rows
$counter = 1;
foreach ($purchase_orders as $po) {
    if (empty($po['items'])) {
        // PO with no items
        $export_data[] = [
            $counter,
            $po['po_number'],
            $po['supplier_code'] ?? '',
            $po['supplier_name'] ?? '',
            $po['purchase_type'] ?? '',
            $po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '',
            $po['expected_delivery'] ? date('M d, Y', strtotime($po['expected_delivery'])) : '',
            $po['payment_terms'] ?? '',
            $po['currency'] ?? 'PHP',
            $po['delivery_address'] ?? '',
            $po['delivery_mode'] ?? '',
            $po['warranty'] ?? '',
            $po['freight'] ?? '',
            $po['remarks'] ?? '',
            $po['status'] ?? '',
            $po['delivery_status'] ?? 'Pending',
            $po['delivery_payment'] ?? 'Pending',
            $po['delivered_date'] ? date('M d, Y', strtotime($po['delivered_date'])) : '',
            $po['received_by'] ?? '',
            number_format($po['subtotal'] ?? 0, 2),
            number_format($po['total_vat'] ?? 0, 2),
            number_format($po['total_amount_paid'] ?? 0, 2),
            $po['created_by'] ?? '',
            $po['created_at'] ? date('M d, Y h:i A', strtotime($po['created_at'])) : '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];
        $counter++;
    } else {
        // PO with items - show each item on separate row
        $first_row = true;
        foreach ($po['items'] as $item) {
            $export_data[] = [
                $first_row ? $counter : '',
                $first_row ? $po['po_number'] : '',
                $first_row ? ($po['supplier_code'] ?? '') : '',
                $first_row ? ($po['supplier_name'] ?? '') : '',
                $first_row ? ($po['purchase_type'] ?? '') : '',
                $first_row ? ($po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '') : '',
                $first_row ? ($po['expected_delivery'] ? date('M d, Y', strtotime($po['expected_delivery'])) : '') : '',
                $first_row ? ($po['payment_terms'] ?? '') : '',
                $first_row ? ($po['currency'] ?? 'PHP') : '',
                $first_row ? ($po['delivery_address'] ?? '') : '',
                $first_row ? ($po['delivery_mode'] ?? '') : '',
                $first_row ? ($po['warranty'] ?? '') : '',
                $first_row ? ($po['freight'] ?? '') : '',
                $first_row ? ($po['remarks'] ?? '') : '',
                $first_row ? ($po['status'] ?? '') : '',
                $first_row ? ($po['delivery_status'] ?? 'Pending') : '',
                $first_row ? ($po['delivery_payment'] ?? 'Pending') : '',
                $first_row ? ($po['delivered_date'] ? date('M d, Y', strtotime($po['delivered_date'])) : '') : '',
                $first_row ? ($po['received_by'] ?? '') : '',
                $first_row ? number_format($po['subtotal'] ?? 0, 2) : '',
                $first_row ? number_format($po['total_vat'] ?? 0, 2) : '',
                $first_row ? number_format($po['total_amount_paid'] ?? 0, 2) : '',
                $first_row ? ($po['created_by'] ?? '') : '',
                $first_row ? ($po['created_at'] ? date('M d, Y h:i A', strtotime($po['created_at'])) : '') : '',
                $item['item_code'] ?? '',
                $item['item_name'] ?? '',
                $item['qty_ordered'] ?? '',
                $item['qty_received'] ?? '',
                number_format($item['unit_cost'] ?? 0, 2),
                number_format($item['total_amount'] ?? 0, 2),
                number_format($item['amount_paid'] ?? 0, 2)
            ];
            $first_row = false;
        }
        $counter++;
    }
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Purchase Orders: ' . count($purchase_orders)];
$export_data[] = ['Exported by: ' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown')];

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="purchase_orders_' . date('Y-m-d') . '.xls"');
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