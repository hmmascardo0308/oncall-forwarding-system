<?php
// export_customer_ledger.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$username = $_SESSION['username'] ?? 'Guest';

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);

// Check access - only customer_register, admin, customer_pricer, sales_order_maker, service_invoice_maker
$allowed_roles = ['customer_register', 'admin', 'customer_pricer', 'sales_order_maker', 'service_invoice_maker'];
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
        'text' => "You don't have permission to export this data."
    ];
    header("Location: customer_ledger.php");
    exit;
}

// Get all customers
$customers = [];
$customer_query = "SELECT id, customer_code, full_name, company_name, contact_number, email, status, customer_since, credit_limit, credit_terms 
                   FROM customer_masterlist 
                   WHERE status = 'Active' 
                   ORDER BY full_name ASC";
$customer_result = $conn->query($customer_query);
if ($customer_result) {
    while ($row = $customer_result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Get all sales orders with customer info
$sales_orders = [];
$sales_query = "SELECT id, sales_order_no, customer_code, customer_name, order_date, delivery_date, 
                       amount, status, created_date, truck_code, plate_number
                FROM sales_order 
                ORDER BY created_date DESC";
$sales_result = $conn->query($sales_query);
if ($sales_result) {
    while ($row = $sales_result->fetch_assoc()) {
        $sales_orders[$row['customer_code']][] = $row;
    }
}

// Get service invoices for additional details
$invoices = [];
$invoice_query = "SELECT id, invoice_no, sales_order_no, customer_code, customer_name, 
                         invoice_date, total_amount, payment_status, status
                  FROM service_invoice 
                  ORDER BY invoice_date DESC";
$invoice_result = $conn->query($invoice_query);
if ($invoice_result) {
    while ($row = $invoice_result->fetch_assoc()) {
        $invoices[$row['customer_code']][] = $row;
    }
}

// Build export data
$export_data = [];
$row_num = 1;

// Add header row with company info
$export_data[] = ['ONCALL FORWARDING CORPORATION'];
$export_data[] = ['Customer Ledger Report'];
$export_data[] = ['Generated: ' . date('F d, Y h:i A')];
$export_data[] = []; // Empty row for spacing

// Column headers
$export_data[] = [
    '#',
    'Customer Code',
    'Customer Name',
    'Company',
    'Contact Number',
    'Email',
    'Status',
    'Customer Since',
    'Credit Limit',
    'Credit Terms',
    'Total Orders',
    'Total Sales Amount',
    'Sales Order No.',
    'Order Date',
    'Delivery Date',
    'Truck Code',
    'Plate Number',
    'SO Status',
    'Invoice No.',
    'Invoice Date',
    'Invoice Amount',
    'Payment Status',
    'Invoice Status'
];

// Data rows
$counter = 1;
foreach ($customers as $customer) {
    $customer_code = $customer['customer_code'];
    $orders = $sales_orders[$customer_code] ?? [];
    $order_count = count($orders);
    $total_sales = 0;
    
    // Calculate total sales for this customer
    foreach ($orders as $order) {
        $total_sales += floatval($order['amount'] ?? 0);
    }
    
    $invoice_list = $invoices[$customer_code] ?? [];
    
    $customer_name = !empty($customer['full_name']) ? $customer['full_name'] : $customer['company_name'];
    $company_name = !empty($customer['company_name']) ? $customer['company_name'] : '';
    $credit_limit = $customer['credit_limit'] ?? 0;
    $credit_terms = $customer['credit_terms'] ?? 'N/A';
    $status = $customer['status'] ?? 'Active';
    $customer_since = !empty($customer['customer_since']) ? date('M d, Y', strtotime($customer['customer_since'])) : '—';
    $contact = $customer['contact_number'] ?? '';
    $email = $customer['email'] ?? '';
    
    // If no orders, add single row with customer info only
    if (empty($orders)) {
        $export_data[] = [
            $counter,
            $customer_code,
            $customer_name,
            $company_name,
            $contact,
            $email,
            $status,
            $customer_since,
            $credit_limit > 0 ? '₱ ' . number_format($credit_limit, 2) : 'N/A',
            $credit_terms,
            0,
            '₱ 0.00',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—'
        ];
        $counter++;
        $row_num++;
    } else {
        // Add rows for each order
        $first_row = true;
        foreach ($orders as $order) {
            $order_invoices = array_filter($invoice_list, function($inv) use ($order) {
                return $inv['sales_order_no'] == $order['sales_order_no'];
            });
            
            $row_data = [
                $first_row ? $counter : '',
                $first_row ? $customer_code : '',
                $first_row ? $customer_name : '',
                $first_row ? $company_name : '',
                $first_row ? $contact : '',
                $first_row ? $email : '',
                $first_row ? $status : '',
                $first_row ? $customer_since : '',
                $first_row ? ($credit_limit > 0 ? '₱ ' . number_format($credit_limit, 2) : 'N/A') : '',
                $first_row ? $credit_terms : '',
                $first_row ? $order_count : '',
                $first_row ? '₱ ' . number_format($total_sales, 2) : '',
                $order['sales_order_no'] ?? '—',
                isset($order['order_date']) ? date('M d, Y', strtotime($order['order_date'])) : '—',
                isset($order['delivery_date']) ? date('M d, Y', strtotime($order['delivery_date'])) : '—',
                $order['truck_code'] ?? '—',
                $order['plate_number'] ?? '—',
                $order['status'] ?? 'Pending',
                '',
                '',
                '',
                '',
                ''
            ];
            
            // If there are invoices, add them as separate rows under the SO
            if (!empty($order_invoices)) {
                $export_data[] = $row_data;
                $row_num++;
                $first_row = false;
                
                foreach ($order_invoices as $inv) {
                    $export_data[] = [
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        $inv['invoice_no'] ?? '—',
                        isset($inv['invoice_date']) ? date('M d, Y', strtotime($inv['invoice_date'])) : '—',
                        '₱ ' . number_format($inv['total_amount'] ?? 0, 2),
                        $inv['payment_status'] ?? '—',
                        $inv['status'] ?? '—'
                    ];
                    $row_num++;
                }
            } else {
                // No invoices, just add the SO row
                $export_data[] = $row_data;
                $row_num++;
                $first_row = false;
            }
        }
        $counter++;
    }
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Customers: ' . count($customers)];
$export_data[] = ['Total Rows: ' . ($row_num - 5)]; // Adjust for header rows

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="customer_ledger_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Create the Excel file
$output = fopen('php://output', 'w');

// Write data to file
foreach ($export_data as $row) {
    fputcsv($output, $row, "\t"); // Using tab delimiter for Excel compatibility
}

fclose($output);
exit;
?>