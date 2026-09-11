<?php
// export_vendor_ledger.php
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
$allowed_roles = ['supplier_register', 'admin', 'purchase_order_maker'];
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
        'text' => "You don't have permission to export vendor ledger data."
    ];
    header("Location: vendor_ledger.php");
    exit;
}

// Get selected supplier filter
$selected_supplier = isset($_GET['supplier_code']) ? trim($_GET['supplier_code']) : '';

if (empty($selected_supplier)) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "Please select a vendor to export."
    ];
    header("Location: vendor_ledger.php");
    exit;
}

// Get supplier info
$supplier_info = null;
$supplier_info_query = "SELECT * FROM supplier_lists WHERE supplier_code = ?";
$stmt = $conn->prepare($supplier_info_query);
$stmt->bind_param("s", $selected_supplier);
$stmt->execute();
$supplier_info_result = $stmt->get_result();
$supplier_info = $supplier_info_result->fetch_assoc();

if (!$supplier_info) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "Vendor not found."
    ];
    header("Location: vendor_ledger.php");
    exit;
}

// Get purchase orders for this supplier - GROUP BY po_number to avoid duplicates
$po_query = "SELECT 
                po_number,
                supplier_code,
                supplier_name,
                purchase_type,
                MAX(po_date) as po_date,
                MAX(payment_terms) as payment_terms,
                MAX(with_vat) as with_vat,
                MAX(status) as status,
                MAX(delivery_status) as delivery_status,
                MAX(delivery_payment) as delivery_payment,
                MAX(remarks) as remarks,
                MAX(created_at) as created_at,
                MAX(total_vat) as total_vat,
                SUM(subtotal) as subtotal,
                SUM(total_amount) as total_amount,
                SUM(total_amount_paid) as total_amount_paid,
                SUM(withholding_tax_amount) as withholding_tax_amount,
                SUM(net_amount_due) as net_amount_due
             FROM purchase_order 
             WHERE supplier_code = ?
             GROUP BY po_number, supplier_code, supplier_name, purchase_type
             ORDER BY MAX(po_date) ASC, MAX(created_at) ASC";

$stmt = $conn->prepare($po_query);
$stmt->bind_param("s", $selected_supplier);
$stmt->execute();
$po_result = $stmt->get_result();

$purchase_orders = [];
if ($po_result) {
    while ($row = $po_result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }
}

// Build export data
$export_data = [];

// Add header row with company info
$export_data[] = ['ONCALL FORWARDING CORPORATION'];
$export_data[] = ['Vendor Ledger Report'];
$export_data[] = ['Generated: ' . date('F d, Y h:i A')];
$export_data[] = [];
$export_data[] = ['VENDOR INFORMATION'];
$export_data[] = ['Supplier Code:', $supplier_info['supplier_code']];
$export_data[] = ['Supplier Name:', $supplier_info['supplier_name']];
$export_data[] = ['Supplier Type:', $supplier_info['supplier_type'] ?? 'N/A'];
$export_data[] = ['Payment Terms:', $supplier_info['payment_terms'] ?? 'N/A'];
$export_data[] = ['VAT Type:', $supplier_info['vat_type'] ?? 'N/A'];
$export_data[] = ['TIN:', $supplier_info['tin'] ?? 'N/A'];
$export_data[] = ['Status:', $supplier_info['status'] ?? 'Active'];
$export_data[] = [];
$export_data[] = ['TRANSACTION SUMMARY'];
$export_data[] = ['Total POs:', count($purchase_orders)];

// Calculate totals
$total_debits = 0;
$total_credits = 0;
$running_balance = 0;

foreach ($purchase_orders as $po) {
    $po_status = strtolower($po['status'] ?? '');
    $is_cancelled = in_array($po_status, ['cancelled', 'void']);
    $delivery_status = strtolower($po['delivery_status'] ?? '');
    $delivery_payment = strtolower($po['delivery_payment'] ?? '');
    
    $net_amount_due = floatval($po['net_amount_due'] ?? 0);
    $total_amount_paid = floatval($po['total_amount_paid'] ?? 0);
    
    $debit = 0;
    $credit = 0;
    
    if (!$is_cancelled && in_array($delivery_status, ['received', 'delivered'])) {
        if ($net_amount_due > 0) {
            $debit = $net_amount_due;
            $total_debits += $net_amount_due;
        }
        if ($total_amount_paid > 0) {
            $credit = $total_amount_paid;
            $total_credits += $total_amount_paid;
        }
    }
    $running_balance += $debit - $credit;
}

$export_data[] = ['Total Debit (Amount Owed):', '₱ ' . number_format($total_debits, 2)];
$export_data[] = ['Total Credit (Amount Paid):', '₱ ' . number_format($total_credits, 2)];
$export_data[] = ['Final Running Balance:', '₱ ' . number_format($running_balance, 2) . ' (' . ($running_balance > 0 ? 'Still Owed' : ($running_balance < 0 ? 'Overpaid' : 'Settled')) . ')'];
$export_data[] = [];

// Column headers - Updated to match the new table structure
$export_data[] = [
    '#',
    'Supplier Code',
    'Supplier Name',
    'Payment Terms',
    'Transaction Date',
    'Reference No.',
    'Po Type',
    'VAT Type',
    'Debit (Amount Owed)',
    'Credit (Amount Paid)',
    'Running Balance',
    'Remarks',
    'Status'
];

// Data rows
$counter = 1;
$running_balance = 0;

foreach ($purchase_orders as $po) {
    $po_status = strtolower($po['status'] ?? '');
    $is_cancelled = in_array($po_status, ['cancelled', 'void']);
    $delivery_status = strtolower($po['delivery_status'] ?? '');
    $delivery_payment = strtolower($po['delivery_payment'] ?? '');
    
    $net_amount_due = floatval($po['net_amount_due'] ?? 0);
    $total_amount_paid = floatval($po['total_amount_paid'] ?? 0);
    $total_amount = floatval($po['total_amount'] ?? 0);
    
    $debit = 0;
    $credit = 0;
    $remark = '';
    $payment_status = '—';
    
    // Determine debit and credit based on delivery_status and delivery_payment
    $has_completed = !empty($delivery_status);
    $has_payment_info = !empty($delivery_payment) && $delivery_payment != 'unpaid';
    
    if ($has_completed && !$is_cancelled) {
        // For received/delivered items
        if (in_array($delivery_status, ['received', 'delivered'])) {
            // DEBIT: The full amount owed (net_amount_due) is recorded as debit
            if ($net_amount_due > 0) {
                $debit = $net_amount_due;
            }
            
            // CREDIT: The amount paid (total_amount_paid) is recorded as credit
            if ($total_amount_paid > 0) {
                $credit = $total_amount_paid;
            }
            
            // Determine payment status and remark
            $remaining = $net_amount_due - $total_amount_paid;
            
            if ($total_amount_paid >= $net_amount_due && $net_amount_due > 0) {
                $remark = 'Fully paid';
                $payment_status = 'Paid';
            } elseif ($total_amount_paid > 0 && $total_amount_paid < $net_amount_due) {
                $remark = 'Partial payment - Balance: ₱ ' . number_format($remaining, 2);
                $payment_status = 'Partial';
            } elseif ($net_amount_due == 0) {
                $remark = 'Zero amount';
                $payment_status = 'Paid';
            } else {
                $remark = 'Awaiting payment';
                $payment_status = 'Pending';
            }
        }
    } elseif ($is_cancelled) {
        $remark = 'Cancelled / Void';
        $payment_status = 'Cancelled';
    } else {
        $remark = 'Pending delivery';
        $payment_status = 'Pending';
    }
    
    // Update running balance (only for non-cancelled transactions with debit/credit)
    if (!$is_cancelled && ($debit > 0 || $credit > 0)) {
        $running_balance += $debit - $credit;
    }
    
    // Get payment terms
    $payment_terms_display = !empty($po['payment_terms']) ? $po['payment_terms'] : ($supplier_info['payment_terms'] ?? 'N/A');
    
    // Determine status display
    $status_display = $po['status'] ?? '—';
    if (!empty($po['delivery_status'])) {
        $status_display = $po['delivery_status'];
    }
    
    $export_data[] = [
        $counter,
        $po['supplier_code'] ?? '',
        $po['supplier_name'] ?? '',
        $payment_terms_display,
        $po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '',
        $po['po_number'] ?? '',
        'Purchase Journal',
        $po['with_vat'] == 1 ? 'With VAT' : 'VAT Exempt',
        $debit > 0 ? '₱ ' . number_format($debit, 2) : '—',
        $credit > 0 ? '₱ ' . number_format($credit, 2) : '—',
        $is_cancelled ? '—' : '₱ ' . number_format($running_balance, 2),
        $remark,
        $status_display
    ];
    $counter++;
}

// Add summary footer
$export_data[] = [];
$export_data[] = ['--- END OF REPORT ---'];
$export_data[] = ['Total Purchase Orders: ' . count($purchase_orders)];
$export_data[] = ['Total Debit (Owed): ₱ ' . number_format($total_debits, 2)];
$export_data[] = ['Total Credit (Paid): ₱ ' . number_format($total_credits, 2)];
$export_data[] = ['Final Balance: ₱ ' . number_format($running_balance, 2)];
$export_data[] = ['Exported by: ' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown')];
$export_data[] = ['Exported on: ' . date('F d, Y h:i A')];

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="vendor_ledger_' . $selected_supplier . '_' . date('Y-m-d') . '.xls"');
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