<?php
// aged_payables.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$username  = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role
$is_admin = in_array('admin', $user_roles);
$base_user_type = $is_admin ? 'admin' : 'user';

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Check if user has access to this page
if (!hasAccess('aged_payables.php', $user_roles, $allowed_pages)) {
    header("Location: home.php");
    exit;
}

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

// Query for last online
$last_online_date = "Not Available";
$last_online_time = "";
$query = "SELECT last_online FROM all_users WHERE id = ?";
if ($stmt = $conn->prepare($query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['last_online']) {
            $timestamp = strtotime($row['last_online']);
            $last_online_date = date("F j, Y", $timestamp);
            $last_online_time = date("h:i:s A", $timestamp);
        } else {
            $last_online_date = "First login";
        }
    }
    $stmt->close();
}

$role_display_name = getRoleDisplayName($user_roles);

// Fetch aged payables data
$current_date = date('Y-m-d');
$current_timestamp = strtotime($current_date);

// Group by PO number to avoid duplicates from multiple items
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
        // Skip if expected_delivery is empty, NULL, or invalid
        if (empty($row['expected_delivery']) || 
            $row['expected_delivery'] == '0000-00-00' || 
            $row['expected_delivery'] == 'NULL' ||
            strtotime($row['expected_delivery']) === false) {
            continue;
        }
        
        // Calculate due date (expected_delivery + payment_terms)
        $expected_delivery = strtotime($row['expected_delivery']);
        
        // Get payment terms (convert to integer)
        $payment_terms_days = intval($row['payment_terms']);
        if ($payment_terms_days <= 0) {
            $payment_terms_days = 0;
        }
        
        // Calculate the due date by adding payment terms to expected delivery
        $due_date = strtotime("+{$payment_terms_days} days", $expected_delivery);
        
        // Calculate days overdue (days past the due date)
        $days_overdue = floor(($current_timestamp - $due_date) / (60 * 60 * 24));
        
        // Determine aging category based on days overdue
        if ($days_overdue < 0) {
            // Not overdue yet (due date is in the future)
            $aging_category = '0-30';
            $display_days = abs($days_overdue);
            $overdue_status = 'Not Yet Due';
        } else {
            // Overdue - determine the category
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
        
        // Get amount due (SUM of all items for this PO)
        $amount_due = floatval($row['net_amount_due']);
        
        // Store data
        $row['expected_delivery_date'] = date('Y-m-d', $expected_delivery);
        $row['due_date'] = date('Y-m-d', $due_date);
        $row['days_overdue'] = $days_overdue;
        $row['display_days'] = $display_days;
        $row['payment_terms_days'] = $payment_terms_days;
        $row['aging_category'] = $aging_category;
        $row['overdue_status'] = $overdue_status;
        $row['amount_due'] = $amount_due;
        
        $aged_payables[] = $row;
        
        // Add to totals (only if amount is > 0)
        if ($amount_due > 0) {
            $totals[$aging_category] += $amount_due;
            $totals['total_due'] += $amount_due;
        }
    }
}

// Get summary statistics
$total_suppliers = 0;
$total_po_count = count($aged_payables);
$unique_suppliers = [];

foreach ($aged_payables as $item) {
    if (!in_array($item['supplier_name'], $unique_suppliers)) {
        $unique_suppliers[] = $item['supplier_name'];
    }
}
$total_suppliers = count($unique_suppliers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aged Payables | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/aged_pay.css?v=<?= time(); ?>">

    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
  
</head>
<body>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <a href="reports.php" style="color:red; font-weight:bold; font-size:16px; text-decoration:none;">
    Reports
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Aged Payables</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>📋 Aged Payables</h1>
                <p>Monitor supplier payables by aging category based on expected delivery date and payment terms.</p>
            </section>

            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="label">Total Suppliers</div>
                    <div class="value info"><?php echo number_format($total_suppliers); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total POs</div>
                    <div class="value"><?php echo number_format($total_po_count); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">0-30 Days</div>
                    <div class="value success">₱<?php echo number_format($totals['0-30'], 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">31-60 Days</div>
                    <div class="value warning">₱<?php echo number_format($totals['31-60'], 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">61-90 Days</div>
                    <div class="value" style="color:#d97706;">₱<?php echo number_format($totals['61-90'], 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Over 90 Days</div>
                    <div class="value danger">₱<?php echo number_format($totals['over_90'], 2); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Amount Due</div>
                    <div class="value" style="color:#7c3aed;">₱<?php echo number_format($totals['total_due'], 2); ?></div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-container">
                <!-- In the table-header div, add this button -->
<div class="table-header">
    <h3>Aged Payables Report</h3>
    <div style="display:flex; align-items:center; gap:15px;">
        <span class="record-count"><?php echo count($aged_payables); ?> records</span>
        <a href="export_aged_payables.php" class="export-btn" style="
            background: #4a90d9;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        " onmouseover="this.style.background='#3a7bc8'" onmouseout="this.style.background='#4a90d9'">
            <i data-lucide="file-spreadsheet" style="width:18px;height:18px;"></i>
            Export to Excel
        </a>
    </div>
</div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>PO Number</th>
                                <th>Expected Delivery</th>
                                <th>Due Date</th>
                                <th>Payment Terms</th>
                                <th class="text-center">0-30</th>
                                <th class="text-center">31-60</th>
                                <th class="text-center">61-90</th>
                                <th class="text-center">Over 90 Days</th>
                                <th class="text-right">Amount Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($aged_payables) > 0): ?>
                                <?php foreach ($aged_payables as $row): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:500;"><?php echo htmlspecialchars($row['supplier_name']); ?></div>
                                        <div style="font-size:11px; color:var(--text-muted);"><?php echo htmlspecialchars($row['supplier_code']); ?></div>
                                    </td>
                                    <td>
                                        <span class="font-mono"><?php echo htmlspecialchars($row['po_number']); ?></span>
                                        <div style="font-size:11px; color:var(--text-muted);">
                                            <?php echo date('M d, Y', strtotime($row['po_date'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($row['expected_delivery']) && $row['expected_delivery'] != '0000-00-00') {
                                            echo date('M d, Y', strtotime($row['expected_delivery']));
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($row['due_date'])); ?>
                                        <div style="font-size:11px; color:var(--text-muted);">
                                            <?php 
                                            if ($row['days_overdue'] < 0) {
                                                echo abs($row['days_overdue']) . ' days until due';
                                            } else {
                                                echo $row['days_overdue'] . ' days overdue';
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo $row['payment_terms_days']; ?> days
                                        <div>
                                            <?php if ($row['overdue_status'] == 'Overdue'): ?>
                                                <span class="overdue-badge overdue-yes">Overdue</span>
                                            <?php elseif ($row['overdue_status'] == 'Not Yet Due'): ?>
                                                <span class="overdue-badge overdue-no">Not Yet Due</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['aging_category'] == '0-30'): ?>
                                            <span class="aging-badge aging-0-30">₱<?php echo number_format($row['amount_due'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['aging_category'] == '31-60'): ?>
                                            <span class="aging-badge aging-31-60">₱<?php echo number_format($row['amount_due'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['aging_category'] == '61-90'): ?>
                                            <span class="aging-badge aging-61-90">₱<?php echo number_format($row['amount_due'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['aging_category'] == 'over_90'): ?>
                                            <span class="aging-badge aging-over-90">₱<?php echo number_format($row['amount_due'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($row['amount_due'] > 0): ?>
                                            <span class="amount">₱<?php echo number_format($row['amount_due'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="amount-zero">₱0.00</span>
                                        <?php endif; ?>
                                        <div>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i data-lucide="inbox"></i>
                                            <p>No aged payables found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($aged_payables) > 0): ?>
                <div class="table-footer">
                    <span>Total Records: <?php echo count($aged_payables); ?></span>
                    <span class="total-amount">Total Amount Due: ₱<?php echo number_format($totals['total_due'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        // Flash message auto-dismiss
        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flash-message');
            if (flash) {
                setTimeout(() => { 
                    flash.style.opacity = '0'; 
                    setTimeout(() => flash.remove(), 500); 
                }, 3000);
            }
        });
    </script>
</body>
</html>