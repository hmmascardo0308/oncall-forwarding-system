<?php
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
if (!hasAccess('aged_receivables.php', $user_roles, $allowed_pages)) {
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

// Fetch aged receivables data
$current_date = date('Y-m-d');
$current_timestamp = strtotime($current_date);

// Query from service_invoice table
// Include only unpaid or partially paid invoices
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
        // Skip if due_date is empty, NULL, or invalid
        if (empty($row['due_date']) || 
            $row['due_date'] == '0000-00-00' || 
            $row['due_date'] == 'NULL' ||
            strtotime($row['due_date']) === false) {
            continue;
        }
        
        // Calculate days overdue based on due_date
        $due_date = strtotime($row['due_date']);
        
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
        
        // Get amount due
        $amount_due = floatval($row['total_amount']);
        
        // Store data
        $row['due_date_formatted'] = date('Y-m-d', $due_date);
        $row['days_overdue'] = $days_overdue;
        $row['display_days'] = $display_days;
        $row['aging_category'] = $aging_category;
        $row['overdue_status'] = $overdue_status;
        $row['amount_due'] = $amount_due;
        
        $aged_receivables[] = $row;
        
        // Add to totals (only if amount is > 0)
        if ($amount_due > 0) {
            $totals[$aging_category] += $amount_due;
            $totals['total_due'] += $amount_due;
        }
    }
}

// Get summary statistics
$total_customers = 0;
$total_invoice_count = count($aged_receivables);
$unique_customers = [];

foreach ($aged_receivables as $item) {
    if (!in_array($item['customer_name'], $unique_customers)) {
        $unique_customers[] = $item['customer_name'];
    }
}
$total_customers = count($unique_customers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aged Receivables | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="css/aged_rec.css?v=<?= time(); ?>">

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
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Aged Receivables</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>📋 Aged Receivables</h1>
                <p>Monitor customer receivables by aging category based on invoice due date.</p>
            </section>

            

            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="label">Total Customers</div>
                    <div class="value info"><?php echo number_format($total_customers); ?></div>
                </div>
                <div class="summary-card">
                    <div class="label">Total Invoices</div>
                    <div class="value"><?php echo number_format($total_invoice_count); ?></div>
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
                <div class="table-header">
        <h3>Aged Receivables Report</h3>
        <div class="table-header-actions">
            <span class="record-count"><?php echo count($aged_receivables); ?> records</span>
            <a href="export_aged_receivables.php" class="export-btn">
                <i data-lucide="file-spreadsheet"></i> Export to Excel
            </a>
        </div>
    </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Invoice No.</th>
                                <th>SO No.</th>
                                <th>Invoice Date</th>
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
                            <?php if (count($aged_receivables) > 0): ?>
                                <?php foreach ($aged_receivables as $row): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:500;"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                        <div style="font-size:11px; color:var(--text-muted);"><?php echo htmlspecialchars($row['customer_code']); ?></div>
                                    </td>
                                    <td>
                                        <span class="font-mono"><?php echo htmlspecialchars($row['invoice_no']); ?></span>
                                    </td>
                                    <td>
                                        <span class="font-mono"><?php echo htmlspecialchars($row['sales_order_no']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($row['invoice_date'])); ?>
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
                                        <?php echo htmlspecialchars($row['payment_terms']); ?> days
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
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $row['payment_status'])); ?>">
                                                <?php echo htmlspecialchars($row['payment_status']); ?>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <i data-lucide="inbox"></i>
                                            <p>No aged receivables found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($aged_receivables) > 0): ?>
                <div class="table-footer">
                    <span>Total Records: <?php echo count($aged_receivables); ?></span>
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