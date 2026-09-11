<?php
// export_aged_payables.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';

$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);

// Check if user has access to this page
if (!hasAccess('aged_payables.php', $user_roles, $allowed_pages)) {
    header("Location: home.php");
    exit;
}

// Fetch aged payables data (same logic as aged_payables.php)
$current_date = date('Y-m-d');
$current_timestamp = strtotime($current_date);

$query = "SELECT 
            po_number,
            supplier_name,
            supplier_code,
            po_date,
            expected_delivery,
            payment_terms,
            SUM(total_amount) as total_amount,
            SUM(total_amount_paid) as total_amount_paid,
            SUM(net_amount_due) as net_amount_due,
            status,
            created_at
          FROM purchase_order 
          WHERE status IN ('Pending', 'Partially Paid', 'Overdue', 'Approved', 'Created')
          GROUP BY po_number, supplier_name, supplier_code, po_date, expected_delivery, payment_terms, status, created_at
          ORDER BY supplier_name, expected_delivery";

$result = $conn->query($query);

$aged_payables = [];
$totals = [
    '0-30' => 0,
    '31-60' => 0,
    '61-90' => 0,
    'over_90' => 0,
    'total_due' => 0
];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if (empty($row['expected_delivery']) || 
            $row['expected_delivery'] == '0000-00-00' || 
            $row['expected_delivery'] == 'NULL' ||
            strtotime($row['expected_delivery']) === false) {
            continue;
        }
        
        $expected_delivery = strtotime($row['expected_delivery']);
        $payment_terms_days = intval($row['payment_terms']);
        if ($payment_terms_days <= 0) {
            $payment_terms_days = 0;
        }
        
        $due_date = strtotime("+{$payment_terms_days} days", $expected_delivery);
        $days_overdue = floor(($current_timestamp - $due_date) / (60 * 60 * 24));
        
        if ($days_overdue < 0) {
            $aging_category = '0-30';
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
            $overdue_status = 'Overdue';
        }
        
        $amount_due = floatval($row['net_amount_due']);
        
        $row['expected_delivery_date'] = date('Y-m-d', $expected_delivery);
        $row['due_date'] = date('Y-m-d', $due_date);
        $row['days_overdue'] = $days_overdue;
        $row['payment_terms_days'] = $payment_terms_days;
        $row['aging_category'] = $aging_category;
        $row['overdue_status'] = $overdue_status;
        $row['amount_due'] = $amount_due;
        
        $aged_payables[] = $row;
        
        if ($amount_due > 0) {
            $totals[$aging_category] += $amount_due;
            $totals['total_due'] += $amount_due;
        }
    }
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="aged_payables_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Create the Excel content
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<style>
    th { background-color: #4a90d9; color: #ffffff; font-weight: bold; padding: 8px; border: 1px solid #333; }
    td { padding: 6px 8px; border: 1px solid #ccc; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .amount { font-weight: bold; }
    .total-row { background-color: #f0f0f0; font-weight: bold; }
    .summary-row { background-color: #e8f0fe; }
    .overdue-yes { color: #dc3545; }
    .overdue-no { color: #28a745; }
</style>';
echo '</head>';
echo '<body>';

// Title
echo '<h2>Aged Payables Report</h2>';
echo '<p>Generated on: ' . date('F j, Y h:i:s A') . '</p>';

// Summary Cards Data
echo '<h3>Summary</h3>';
echo '<table>';
echo '<tr>';
echo '<th>Total Suppliers</th>';
echo '<th>Total POs</th>';
echo '<th>0-30 Days</th>';
echo '<th>31-60 Days</th>';
echo '<th>61-90 Days</th>';
echo '<th>Over 90 Days</th>';
echo '<th>Total Amount Due</th>';
echo '</tr>';

$unique_suppliers = [];
foreach ($aged_payables as $item) {
    if (!in_array($item['supplier_name'], $unique_suppliers)) {
        $unique_suppliers[] = $item['supplier_name'];
    }
}
$total_suppliers = count($unique_suppliers);
$total_po_count = count($aged_payables);

echo '<tr class="summary-row">';
echo '<td class="text-center">' . number_format($total_suppliers) . '</td>';
echo '<td class="text-center">' . number_format($total_po_count) . '</td>';
echo '<td class="text-right">₱' . number_format($totals['0-30'], 2) . '</td>';
echo '<td class="text-right">₱' . number_format($totals['31-60'], 2) . '</td>';
echo '<td class="text-right">₱' . number_format($totals['61-90'], 2) . '</td>';
echo '<td class="text-right">₱' . number_format($totals['over_90'], 2) . '</td>';
echo '<td class="text-right">₱' . number_format($totals['total_due'], 2) . '</td>';
echo '</tr>';
echo '</table>';

echo '<br>';

// Main Data Table
echo '<h3>Detailed Aged Payables</h3>';
echo '<table>';
echo '<thead>';
echo '<tr>';
echo '<th>Supplier</th>';
echo '<th>Supplier Code</th>';
echo '<th>PO Number</th>';
echo '<th>PO Date</th>';
echo '<th>Expected Delivery</th>';
echo '<th>Due Date</th>';
echo '<th>Days Overdue</th>';
echo '<th>Overdue Status</th>';
echo '<th>Payment Terms</th>';
echo '<th class="text-center">0-30</th>';
echo '<th class="text-center">31-60</th>';
echo '<th class="text-center">61-90</th>';
echo '<th class="text-center">Over 90</th>';
echo '<th class="text-right">Amount Due</th>';
echo '<th>Status</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

if (count($aged_payables) > 0) {
    foreach ($aged_payables as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['supplier_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['supplier_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['po_number']) . '</td>';
        echo '<td>' . date('M d, Y', strtotime($row['po_date'])) . '</td>';
        echo '<td>' . date('M d, Y', strtotime($row['expected_delivery'])) . '</td>';
        echo '<td>' . date('M d, Y', strtotime($row['due_date'])) . '</td>';
        echo '<td class="text-center">' . $row['days_overdue'] . '</td>';
        echo '<td>' . $row['overdue_status'] . '</td>';
        echo '<td class="text-center">' . $row['payment_terms_days'] . ' days</td>';
        
        // Aging category columns
        echo '<td class="text-center">';
        if ($row['aging_category'] == '0-30') {
            echo '₱' . number_format($row['amount_due'], 2);
        } else {
            echo '-';
        }
        echo '</td>';
        
        echo '<td class="text-center">';
        if ($row['aging_category'] == '31-60') {
            echo '₱' . number_format($row['amount_due'], 2);
        } else {
            echo '-';
        }
        echo '</td>';
        
        echo '<td class="text-center">';
        if ($row['aging_category'] == '61-90') {
            echo '₱' . number_format($row['amount_due'], 2);
        } else {
            echo '-';
        }
        echo '</td>';
        
        echo '<td class="text-center">';
        if ($row['aging_category'] == 'over_90') {
            echo '₱' . number_format($row['amount_due'], 2);
        } else {
            echo '-';
        }
        echo '</td>';
        
        echo '<td class="text-right amount">₱' . number_format($row['amount_due'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($row['status']) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr>';
    echo '<td colspan="15" style="text-align:center;">No aged payables found.</td>';
    echo '</tr>';
}

// Totals row
if (count($aged_payables) > 0) {
    echo '<tr class="total-row">';
    echo '<td colspan="9" style="text-align:right;">TOTAL:</td>';
    echo '<td class="text-center">₱' . number_format($totals['0-30'], 2) . '</td>';
    echo '<td class="text-center">₱' . number_format($totals['31-60'], 2) . '</td>';
    echo '<td class="text-center">₱' . number_format($totals['61-90'], 2) . '</td>';
    echo '<td class="text-center">₱' . number_format($totals['over_90'], 2) . '</td>';
    echo '<td class="text-right amount">₱' . number_format($totals['total_due'], 2) . '</td>';
    echo '<td></td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';

echo '</body>';
echo '</html>';

$conn->close();
?>