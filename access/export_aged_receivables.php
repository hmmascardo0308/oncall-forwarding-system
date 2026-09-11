<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';

require_once __DIR__ .  '/../config/access_control.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$user_roles = array_map('trim', explode(',', $user_type));

// Check access
if (!hasAccess('aged_receivables.php', $user_roles, $allowed_pages)) {
    header("Location: home.php");
    exit;
}

// Fetch aged receivables data
$current_date = date('Y-m-d');
$current_timestamp = strtotime($current_date);

$query = "SELECT 
            invoice_no,
            customer_code,
            customer_name,
            sales_order_no,
            invoice_date,
            due_date,
            payment_terms,
            total_amount,
            payment_status,
            status,
            created_date
          FROM service_invoice 
          WHERE status IN ('Active', 'Pending', 'Partially Paid', 'Overdue', 'Created')
          AND payment_status IN ('Unpaid', 'Partial', 'Overdue')
          AND total_amount > 0
          GROUP BY invoice_no, customer_code, customer_name, sales_order_no, invoice_date, due_date, payment_terms, total_amount, payment_status, status, created_date
          ORDER BY customer_name, due_date";

$result = $conn->query($query);

$aged_receivables = [];
$totals = [
    '0-30' => 0,
    '31-60' => 0,
    '61-90' => 0,
    'over_90' => 0,
    'total_due' => 0
];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if (empty($row['due_date']) || 
            $row['due_date'] == '0000-00-00' || 
            $row['due_date'] == 'NULL' ||
            strtotime($row['due_date']) === false) {
            continue;
        }
        
        $due_date = strtotime($row['due_date']);
        $days_overdue = floor(($current_timestamp - $due_date) / (60 * 60 * 24));
        
        if ($days_overdue < 0) {
            $aging_category = '0-30';
            $display_days = abs($days_overdue);
            $overdue_status = 'Not Yet Due';
        } else {
            if ($days_overdue <= 30) {
                $aging_category = '0-30';
            } elseif ($days_overdue <= 60) {
                $aging_category = '31-60';
            } elseif ($days_overdue <= 90) {
                $aging_category = '61-90';
            } else {
                $aging_category = 'over_90';
            }
            $display_days = $days_overdue;
            $overdue_status = 'Overdue';
        }
        
        $amount_due = floatval($row['total_amount']);
        
        $row['due_date_formatted'] = date('Y-m-d', $due_date);
        $row['days_overdue'] = $days_overdue;
        $row['display_days'] = $display_days;
        $row['aging_category'] = $aging_category;
        $row['overdue_status'] = $overdue_status;
        $row['amount_due'] = $amount_due;
        
        $aged_receivables[] = $row;
        
        if ($amount_due > 0) {
            $totals[$aging_category] += $amount_due;
            $totals['total_due'] += $amount_due;
        }
    }
}

// Get unique customers count
$unique_customers = [];
foreach ($aged_receivables as $item) {
    if (!in_array($item['customer_name'], $unique_customers)) {
        $unique_customers[] = $item['customer_name'];
    }
}
$total_customers = count($unique_customers);

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="Aged_Receivables_Report_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Start HTML table for Excel
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<style>
    body { font-family: Arial, sans-serif; font-size: 11px; }
    .header-title { font-size: 18px; font-weight: bold; color: #1a1a2e; margin-bottom: 5px; }
    .header-subtitle { font-size: 12px; color: #666; margin-bottom: 15px; }
    .summary-section { margin-bottom: 15px; }
    .summary-table { border-collapse: collapse; margin-bottom: 15px; }
    .summary-table td { padding: 5px 15px 5px 0; font-size: 11px; }
    .summary-table .label { font-weight: bold; color: #555; }
    .summary-table .value { font-weight: bold; }
    table.data-table { border-collapse: collapse; width: 100%; font-size: 10px; }
    table.data-table th { 
        background-color: #1a1a2e; 
        color: #ffffff; 
        padding: 8px 10px; 
        text-align: left;
        font-weight: bold;
        border: 1px solid #333;
    }
    table.data-table td { 
        padding: 6px 10px; 
        border: 1px solid #ddd;
        text-align: left;
    }
    table.data-table tr:nth-child(even) { background-color: #f9f9f9; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .amount { font-weight: bold; }
    .amount-zero { color: #999; }
    .overdue-badge { 
        display: inline-block;
        padding: 1px 8px;
        border-radius: 3px;
        font-size: 9px;
        font-weight: bold;
    }
    .overdue-yes { background-color: #fee2e2; color: #dc2626; }
    .overdue-no { background-color: #dcfce7; color: #16a34a; }
    .status-badge {
        display: inline-block;
        padding: 1px 8px;
        border-radius: 3px;
        font-size: 9px;
        font-weight: bold;
    }
    .status-unpaid { background-color: #fef3c7; color: #d97706; }
    .status-partial { background-color: #dbeafe; color: #2563eb; }
    .status-overdue { background-color: #fee2e2; color: #dc2626; }
    .footer-total { 
        font-weight: bold; 
        font-size: 12px;
        padding-top: 10px;
        border-top: 2px solid #333;
    }
    .aging-badge {
        font-weight: bold;
    }
    .aging-0-30 { color: #16a34a; }
    .aging-31-60 { color: #f59e0b; }
    .aging-61-90 { color: #d97706; }
    .aging-over-90 { color: #dc2626; }
    .category-title { 
        font-weight: bold; 
        margin-top: 15px; 
        margin-bottom: 5px;
        font-size: 13px;
        color: #1a1a2e;
    }
</style>';
echo '</head>';
echo '<body>';

// Header
echo '<div class="header-title">ONCALL FORWARDING CORPORATION</div>';
echo '<div class="header-subtitle">Aged Receivables Report - As of ' . date('F d, Y') . '</div>';

// Summary Section
echo '<div class="summary-section">';
echo '<table class="summary-table">';
echo '<tr>';
echo '  <td class="label">Total Customers:</td>';
echo '  <td class="value">' . number_format($total_customers) . '</td>';
echo '  <td class="label" style="padding-left:30px;">Total Invoices:</td>';
echo '  <td class="value">' . number_format(count($aged_receivables)) . '</td>';
echo '</tr>';
echo '<tr>';
echo '  <td class="label">0-30 Days:</td>';
echo '  <td class="value" style="color:#16a34a;">₱' . number_format($totals['0-30'], 2) . '</td>';
echo '  <td class="label" style="padding-left:30px;">31-60 Days:</td>';
echo '  <td class="value" style="color:#f59e0b;">₱' . number_format($totals['31-60'], 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '  <td class="label">61-90 Days:</td>';
echo '  <td class="value" style="color:#d97706;">₱' . number_format($totals['61-90'], 2) . '</td>';
echo '  <td class="label" style="padding-left:30px;">Over 90 Days:</td>';
echo '  <td class="value" style="color:#dc2626;">₱' . number_format($totals['over_90'], 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '  <td class="label" style="font-size:13px;">Total Amount Due:</td>';
echo '  <td class="value" style="font-size:13px; color:#7c3aed;">₱' . number_format($totals['total_due'], 2) . '</td>';
echo '</tr>';
echo '</table>';
echo '</div>';

// Main Data Table
echo '<table class="data-table">';
echo '<thead>';
echo '<tr>';
echo '  <th>Customer</th>';
echo '  <th>Invoice No.</th>';
echo '  <th>SO No.</th>';
echo '  <th>Invoice Date</th>';
echo '  <th>Due Date</th>';
echo '  <th>Days</th>';
echo '  <th>Payment Terms</th>';
echo '  <th>Status</th>';
echo '  <th class="text-center">0-30</th>';
echo '  <th class="text-center">31-60</th>';
echo '  <th class="text-center">61-90</th>';
echo '  <th class="text-center">Over 90</th>';
echo '  <th class="text-right">Amount Due</th>';
echo '  <th>Payment Status</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if (count($aged_receivables) > 0) {
    foreach ($aged_receivables as $row) {
        // Format aging amounts
        $amount_0_30 = ($row['aging_category'] == '0-30') ? '₱' . number_format($row['amount_due'], 2) : '-';
        $amount_31_60 = ($row['aging_category'] == '31-60') ? '₱' . number_format($row['amount_due'], 2) : '-';
        $amount_61_90 = ($row['aging_category'] == '61-90') ? '₱' . number_format($row['amount_due'], 2) : '-';
        $amount_over_90 = ($row['aging_category'] == 'over_90') ? '₱' . number_format($row['amount_due'], 2) : '-';
        
        // Payment status class
        $status_class = 'status-' . strtolower(str_replace(' ', '-', $row['payment_status']));
        
        echo '<tr>';
        echo '  <td>' . htmlspecialchars($row['customer_name']) . '<br><span style="font-size:9px;color:#999;">' . htmlspecialchars($row['customer_code']) . '</span></td>';
        echo '  <td>' . htmlspecialchars($row['invoice_no']) . '</td>';
        echo '  <td>' . htmlspecialchars($row['sales_order_no']) . '</td>';
        echo '  <td>' . date('M d, Y', strtotime($row['invoice_date'])) . '</td>';
        echo '  <td>' . date('M d, Y', strtotime($row['due_date'])) . '</td>';
        echo '  <td>' . $row['display_days'] . '</td>';
        echo '  <td>' . htmlspecialchars($row['payment_terms']) . ' days</td>';
        echo '  <td>';
        if ($row['overdue_status'] == 'Overdue') {
            echo '<span class="overdue-badge overdue-yes">Overdue</span>';
        } else {
            echo '<span class="overdue-badge overdue-no">Not Yet Due</span>';
        }
        echo '  </td>';
        echo '  <td class="text-center">' . $amount_0_30 . '</td>';
        echo '  <td class="text-center">' . $amount_31_60 . '</td>';
        echo '  <td class="text-center">' . $amount_61_90 . '</td>';
        echo '  <td class="text-center">' . $amount_over_90 . '</td>';
        echo '  <td class="text-right"><span class="amount">₱' . number_format($row['amount_due'], 2) . '</span></td>';
        echo '  <td><span class="status-badge ' . $status_class . '">' . htmlspecialchars($row['payment_status']) . '</span></td>';
        echo '</tr>';
    }
} else {
    echo '<tr>';
    echo '  <td colspan="14" style="text-align:center;padding:20px;color:#999;">No aged receivables found.</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';

// Footer with total
if (count($aged_receivables) > 0) {
    echo '<div style="margin-top:10px;text-align:right;">';
    echo '<span style="font-weight:bold;">Total Records: ' . count($aged_receivables) . ' | Total Amount Due: ₱' . number_format($totals['total_due'], 2) . '</span>';
    echo '</div>';
}

echo '<div style="margin-top:20px;font-size:10px;color:#999;border-top:1px solid #ddd;padding-top:10px;">';
echo 'Generated on: ' . date('Y-m-d H:i:s') . ' | Generated by: ' . htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
echo '</div>';

echo '</body>';
echo '</html>';

$conn->close();
exit;
?>