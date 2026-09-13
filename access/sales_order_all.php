<?php

// sales_order_all.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into array
$user_roles = array_map('trim', explode(',', $user_type));

$is_admin = in_array('admin', $user_roles);
// customer_pricer does NOT have access to sales order page
$can_access_so = $is_admin || in_array('sales_order_maker', $user_roles) || in_array('service_invoice_maker', $user_roles);

if (!$can_access_so) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Sales Order page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles
$allowed_pages = [
    'admin' => ['home.php', 'all_users.php', 'suppliers.php', 'items.php', 'customer.php', 'truck_masterlist.php', 'trailers.php', 'prime_movers.php', 'customer_pricing.php', 'purchase_order.php', 'sales_order.php', 'service_invoice.php', 'company_profile.php', 'purchase_orders_list.php', 'purchase_orders_view.php', 'general_settings.php', 'reset_password.php', 'sales_order_all.php', 'sales_order_list.php', 'aged_payables.php', 'reports.php', 'aged_receivables.php', 'employee_list.php'],
    'user' => ['home.php', 'suppliers.php', 'items.php', 'customer.php', 'truck_masterlist.php', 'trailers.php', 'prime_movers.php', 'customer_pricing.php', 'company_profile.php', 'purchase_orders_list.php', 'purchase_orders_view.php'],
    'purchase_order_maker' => ['home.php', 'purchase_order.php', 'company_profile.php', 'purchase_orders_list.php', 'purchase_orders_view.php'],
    'sales_order_maker' => ['home.php', 'sales_order.php', 'company_profile.php', 'sales_order_all.php', 'sales_order_list.php'],
    'service_invoice_maker' => ['home.php', 'service_invoice.php', 'company_profile.php'],
    'customer_pricer' => ['home.php', 'customer_pricing.php', 'company_profile.php']
];

// Function to check if user has access to a specific page
function hasAccess($page, $user_roles, $allowed_pages) {
    if (in_array('admin', $user_roles)) {
        return true;
    }
    foreach ($user_roles as $role) {
        if (isset($allowed_pages[$role]) && in_array($page, $allowed_pages[$role])) {
            return true;
        }
    }
    return false;
}

// Role display name
function getRoleDisplayName($roles) {
    if (in_array('admin', $roles)) return 'Admin';
    
    $names = [];
    foreach ($roles as $r) {
        switch ($r) {
            case 'purchase_order_maker': $names[] = 'PO Maker'; break;
            case 'sales_order_maker':    $names[] = 'SO Maker'; break;
            case 'service_invoice_maker':  $names[] = 'SI Maker'; break;
            case 'customer_pricer':      $names[] = 'Customer Pricer'; break;
            default: $names[] = ucfirst(str_replace('_', ' ', $r));
        }
    }
    return implode(' + ', $names);
}

$role_display_name = getRoleDisplayName($user_roles);

// Pagination variables
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get filter parameters
$search_filter = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$customer_filter = isset($_GET['customer']) ? trim($_GET['customer']) : '';
$truck_filter = isset($_GET['truck']) ? trim($_GET['truck']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Check if any filters are applied (excluding page)
$has_other_filters = !empty($search_filter) || !empty($customer_filter) || !empty($truck_filter) || !empty($status_filter);

// Set default date range to current month if no date filters and no other filters are applied
if (empty($date_from) && empty($date_to) && !$has_other_filters) {
    $date_from = date('Y-m-01'); // First day of current month
    $date_to = date('Y-m-d');    // Today
}

// Build the WHERE clause
$where_clauses = [];
$params = [];
$types = "";

// Search filter (text search)
if (!empty($search_filter)) {
    $search_term = "%{$search_filter}%";
    $where_clauses[] = "(sales_order_no LIKE ? OR customer_name LIKE ? OR truck_code LIKE ? OR plate_number LIKE ? OR brand LIKE ? OR model LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ssssss";
}

// Date range filter
if (!empty($date_from)) {
    $where_clauses[] = "DATE(created_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_clauses[] = "DATE(created_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Customer filter
if (!empty($customer_filter)) {
    $where_clauses[] = "customer_code = ?";
    $params[] = $customer_filter;
    $types .= "s";
}

// Truck filter
if (!empty($truck_filter)) {
    $where_clauses[] = "truck_code = ?";
    $params[] = $truck_filter;
    $types .= "s";
}

// Status filter
if (!empty($status_filter)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM sales_order $where_sql";
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$count_result = $stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// Fetch sales orders with pagination
$salesOrders = [];
$query = "SELECT 
    id,
    sales_order_no,
    customer_code,
    customer_name,
    truck_code,
    plate_number,
    brand,
    model,
    unit,
    destination_from,
    destination_to,
    order_date,
    delivery_date,
    delivery_address,
    payment_terms,
    vat_percent,
    unit_price,
    discount_percent,
    amount,
    discount_amount,
    quantity,
    notes,
    status,
    created_by,
    created_date,
    updated_by,
    updated_at
FROM sales_order 
$where_sql
ORDER BY created_date DESC
LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $salesOrders[] = $row;
    }
}

// Get invoice status and payment status for each sales order
$invoice_statuses = [];
$payment_statuses = [];

if (!empty($salesOrders)) {
    $so_numbers = array_column($salesOrders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $invoice_query = "SELECT sales_order_no, invoice_no, status, payment_status 
                          FROM service_invoice 
                          WHERE sales_order_no IN ($placeholders)";
        $stmt = $conn->prepare($invoice_query);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($so_numbers)), ...$so_numbers);
            $stmt->execute();
            $invoice_result = $stmt->get_result();
            
            while ($row = $invoice_result->fetch_assoc()) {
                $invoice_statuses[$row['sales_order_no']] = [
                    'invoice_no' => $row['invoice_no'],
                    'status' => $row['status']
                ];
                $payment_statuses[$row['sales_order_no']] = [
                    'invoice_no' => $row['invoice_no'],
                    'payment_status' => $row['payment_status']
                ];
            }
        }
    }
}

// Fetch customers for dropdown
$customers = [];
$customer_query = "SELECT DISTINCT customer_code, customer_name FROM sales_order ORDER BY customer_name";
$customer_result = $conn->query($customer_query);
if ($customer_result && $customer_result->num_rows > 0) {
    while ($row = $customer_result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Fetch trucks for dropdown
$trucks = [];
$truck_query = "SELECT DISTINCT truck_code, brand, model FROM sales_order ORDER BY truck_code";
$truck_result = $conn->query($truck_query);
if ($truck_result && $truck_result->num_rows > 0) {
    while ($row = $truck_result->fetch_assoc()) {
        $trucks[] = $row;
    }
}

// Get status badge class
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'draft':
            return 'status-draft';
        case 'pending':
            return 'status-pending';
        case 'approved':
            return 'status-approved';
        case 'completed':
            return 'status-completed';
        case 'cancelled':
            return 'status-cancelled';
        default:
            return 'status-default';
    }
}

// Get invoice status badge class
function getInvoiceStatusBadgeClass($status) {
    $status = strtolower($status);
    switch ($status) {
        case 'draft':
            return 'status-draft';
        case 'pending':
            return 'status-pending';
        case 'approved':
            return 'status-approved';
        case 'completed':
            return 'status-completed';
        case 'cancelled':
            return 'status-cancelled';
        case 'paid':
            return 'status-paid';
        default:
            return 'status-default';
    }
}

// Get payment status badge class
function getPaymentStatusBadgeClass($status) {
    $status = strtolower($status);
    // If status is empty or null, default to 'Unpaid'
    if (empty($status)) {
        $status = 'unpaid';
    }
    switch ($status) {
        case 'paid':
            return 'status-paid';
        case 'unpaid':
            return 'status-unpaid';
        case 'partial':
            return 'status-partial';
        case 'overdue':
            return 'status-overdue';
        case 'cancelled':
            return 'status-cancelled';
        case 'void':
            return 'status-void';
        default:
            return 'status-default';
    }
}

// Format currency
function formatCurrency($amount) {
    if ($amount === null || $amount === '') return '₱0.00';
    return '₱' . number_format(floatval($amount), 2);
}

// Get month name for display
$display_month = '';
if (!empty($date_from) && !empty($date_to)) {
    $display_month = date('F Y', strtotime($date_from));
}

// Check if using default month view
$is_default_month_view = empty($_GET['date_from']) && empty($_GET['date_to']) && 
                          empty($_GET['search']) && empty($_GET['customer']) && 
                          empty($_GET['truck']) && empty($_GET['status']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Sales Orders | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="css/sales_order.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    
</head>
<body>

<!-- Access Denied Modal -->
<div id="accessModal" class="modal-overlay">
    <div class="access-modal">
        <i data-lucide="shield-off"></i>
        <h3>Access Denied</h3>
        <p>You don't have permission to access this page.</p>
        <button class="modal-btn" onclick="document.getElementById('accessModal').style.display='none'">OK</button>
    </div>
</div>

<!-- Include Sidebar -->
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Sales Orders</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?= htmlspecialchars($full_name) ?></span>
            <span style="margin-left:10px;color:var(--text-muted);"><?= htmlspecialchars($username) ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <h1>
                <span class="icon-wrap"><i data-lucide="file-text" style="width:18px;height:18px;"></i></span>
                All Sales Orders
                <span class="count-badge"><?= $total_records ?></span>
            </h1>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <?php if (!empty($search_filter) || !empty($date_from) || !empty($date_to) || !empty($customer_filter) || !empty($truck_filter) || !empty($status_filter)): ?>
                <a href="?" class="btn-filter btn-filter-secondary" style="text-decoration:none;">
                    <i data-lucide="x-circle" style="width:16px;height:16px;"></i> Clear Filters
                </a>
                <?php endif; ?>
                <?php if ($is_admin || in_array('sales_order_maker', $user_roles)): ?>
                <a href="sales_order.php" class="btn-filter btn-filter-primary" style="text-decoration:none;">
                    <i data-lucide="plus-circle" style="width:18px;height:18px;"></i>
                    New SO
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Month Navigator -->
        <div class="month-navigator">
            <span class="label"><i data-lucide="calendar" style="width:16px;height:16px;display:inline;vertical-align:middle;"></i> Month View:</span>
            <?php
            // Get current month from date filters or use current month
            $current_month = !empty($date_from) ? date('Y-m', strtotime($date_from)) : date('Y-m');
            $prev_month = date('Y-m', strtotime($current_month . '-01 -1 month'));
            $next_month = date('Y-m', strtotime($current_month . '-01 +1 month'));
            $current_month_display = date('F Y', strtotime($current_month . '-01'));
            
            // Build query params for navigation
            $nav_params = '';
            if (!empty($search_filter)) $nav_params .= '&search=' . urlencode($search_filter);
            if (!empty($customer_filter)) $nav_params .= '&customer=' . urlencode($customer_filter);
            if (!empty($truck_filter)) $nav_params .= '&truck=' . urlencode($truck_filter);
            if (!empty($status_filter)) $nav_params .= '&status=' . urlencode($status_filter);
            ?>
            <a href="?date_from=<?= date('Y-m-01', strtotime($prev_month)) ?>&date_to=<?= date('Y-m-t', strtotime($prev_month)) ?><?= $nav_params ?>" 
               class="month-nav-btn">
                <i data-lucide="chevron-left" style="width:14px;height:14px;"></i> <?= date('M', strtotime($prev_month)) ?>
            </a>
            <span class="month-display"><?= $current_month_display ?></span>
            <a href="?date_from=<?= date('Y-m-01', strtotime($next_month)) ?>&date_to=<?= date('Y-m-t', strtotime($next_month)) ?><?= $nav_params ?>" 
               class="month-nav-btn">
                <?= date('M', strtotime($next_month)) ?> <i data-lucide="chevron-right" style="width:14px;height:14px;"></i>
            </a>
            <?php if (!$is_default_month_view): ?>
            <a href="?" class="month-nav-btn primary">
                <i data-lucide="calendar" style="width:14px;height:14px;"></i> This Month
            </a>
            <?php endif; ?>
        </div>

        <!-- Filter Section -->
        <form method="GET" action="" class="filter-section" id="filterForm">
            <!-- Text Search -->
            <div class="filter-group">
                <label for="searchFilter">Search</label>
                <input type="text" name="search" id="searchFilter" 
                       placeholder="SO #, Customer, Truck..." 
                       value="<?= htmlspecialchars($search_filter) ?>">
            </div>

            <!-- Date From -->
            <div class="filter-group">
                <label for="dateFrom">Date From</label>
                <input type="date" name="date_from" id="dateFrom" 
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <!-- Date To -->
            <div class="filter-group">
                <label for="dateTo">Date To</label>
                <input type="date" name="date_to" id="dateTo" 
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <!-- Customer Filter -->
            <div class="filter-group">
                <label for="customerFilter">Customer</label>
                <select name="customer" id="customerFilter">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                    <option value="<?= htmlspecialchars($customer['customer_code']) ?>" 
                        <?= $customer_filter == $customer['customer_code'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($customer['customer_name']) ?> (<?= htmlspecialchars($customer['customer_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Truck Filter -->
            <div class="filter-group">
                <label for="truckFilter">Truck</label>
                <select name="truck" id="truckFilter">
                    <option value="">All Trucks</option>
                    <?php foreach ($trucks as $truck): ?>
                    <option value="<?= htmlspecialchars($truck['truck_code']) ?>" 
                        <?= $truck_filter == $truck['truck_code'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($truck['truck_code']) ?> - <?= htmlspecialchars($truck['brand']) ?> <?= htmlspecialchars($truck['model']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status Filter -->
            <div class="filter-group">
                <label for="statusFilter">Status</label>
                <select name="status" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="Draft" <?= $status_filter == 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approved" <?= $status_filter == 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <!-- Buttons -->
            <div class="filter-buttons">
                <button type="submit" class="btn-filter btn-filter-primary">
                    <i data-lucide="search" style="width:16px;height:16px;"></i> Search
                </button>
                <a href="?" class="btn-filter btn-filter-secondary">
                    <i data-lucide="x" style="width:16px;height:16px;"></i> Reset
                </a>
            </div>
        </form>

        <!-- Filter Stats -->
        <?php if (!empty($search_filter) || !empty($date_from) || !empty($date_to) || !empty($customer_filter) || !empty($truck_filter) || !empty($status_filter)): ?>
        <div class="filter-stats">
            Showing <strong><?= count($salesOrders) ?></strong> results 
            <?php if (!empty($search_filter)): ?>
            for search "<strong><?= htmlspecialchars($search_filter) ?></strong>"
            <?php endif; ?>
            <?php if (!empty($date_from) && !empty($date_to)): ?>
            from <strong><?= date('M d, Y', strtotime($date_from)) ?></strong> to <strong><?= date('M d, Y', strtotime($date_to)) ?></strong>
            <?php elseif (!empty($date_from)): ?>
            from <strong><?= date('M d, Y', strtotime($date_from)) ?></strong>
            <?php elseif (!empty($date_to)): ?>
            up to <strong><?= date('M d, Y', strtotime($date_to)) ?></strong>
            <?php endif; ?>
            <?php if (!empty($customer_filter)): ?>
            for customer <strong><?= htmlspecialchars($customer_filter) ?></strong>
            <?php endif; ?>
            <?php if (!empty($truck_filter)): ?>
            for truck <strong><?= htmlspecialchars($truck_filter) ?></strong>
            <?php endif; ?>
            <?php if (!empty($status_filter)): ?>
            with status <strong><?= htmlspecialchars($status_filter) ?></strong>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Default Month Banner -->
        <?php if ($is_default_month_view): ?>
        <div class="default-month-banner">
            <i data-lucide="calendar" style="width:16px;height:16px;"></i>
            Showing orders for <strong><?= date('F Y') ?></strong> (default view)
            <span style="font-size:12px;color:#64748b;margin-left:8px;">
                — Use the month navigator above or date filters to change
            </span>
        </div>
        <?php endif; ?>

        <!-- Table -->
        <div class="table-container">
            <?php if (count($salesOrders) > 0): ?>
            <table class="data-table" id="salesOrderTable">
                <thead>
                    <tr>
                        <th>SO #</th>
                        <th>Customer</th>
                        <th>Truck</th>
                        <th>Plate #</th>
                        <th>From → To</th>
                        <th>Order Date</th>
                        <th>Delivery Date</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>Invoice Status</th>
                        <th>Payment Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($salesOrders as $order): ?>
                    <tr>
                        <td>
                            <div class="so-number"><?= htmlspecialchars($order['sales_order_no']) ?></div>
                            <div class="so-date"><?= date('M d, Y', strtotime($order['created_date'] ?? $order['order_date'])) ?></div>
                        </td>
                        <td>
                            <div class="customer-name"><?= htmlspecialchars($order['customer_name']) ?></div>
                            <div class="customer-code"><?= htmlspecialchars($order['customer_code']) ?></div>
                        </td>
                        <td>
                            <?= htmlspecialchars($order['brand']) ?>
                            <div class="truck-detail"><?= htmlspecialchars($order['model']) ?></div>
                            <div class="truck-detail">Code: <?= htmlspecialchars($order['truck_code']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($order['plate_number']) ?></td>
                        <td>
                            <?= htmlspecialchars($order['destination_from']) ?>
                            <div style="font-size:12px;color:#94a3b8;">→ <?= htmlspecialchars($order['destination_to']) ?></div>
                        </td>
                        <td><?= date('M d, Y', strtotime($order['order_date'])) ?></td>
                        <td><?= date('M d, Y', strtotime($order['delivery_date'])) ?></td>
                        <td class="text-right" style="font-weight:600;">
    <?php 
    // Calculate total amount due (amount + VAT)
    $total_due = floatval($order['amount']) + (floatval($order['amount']) * floatval($order['vat_percent'] ?? 0) / 100);
    echo formatCurrency($total_due);
    ?>
    <?php if ($order['discount_amount'] > 0): ?>
    <div style="font-size:11px;color:#64748b;font-weight:400;">
        Subtotal: <?= formatCurrency($order['amount']) ?>
    </div>
    <div style="font-size:11px;color:#64748b;font-weight:400;">
        Disc: <?= formatCurrency($order['discount_amount']) ?>
    </div>
    <?php endif; ?>
    <?php if ($order['vat_percent']): ?>
    <div style="font-size:10px;color:#94a3b8;">
        VAT (<?= htmlspecialchars($order['vat_percent']) ?>%): <?= formatCurrency(floatval($order['amount']) * floatval($order['vat_percent']) / 100) ?>
    </div>
    <?php endif; ?>
</td>
                        <td>
                            <span class="status-badge <?= getStatusBadgeClass($order['status'] ?? '') ?>">
                                <?= htmlspecialchars($order['status'] ?? 'N/A') ?>
                            </span>
                            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                                Qty: <?= htmlspecialchars($order['quantity']) ?>
                            </div>
                        </td>
                        <td>
                            <?php 
                            $so_no = $order['sales_order_no'];
                            if (isset($invoice_statuses[$so_no])): 
                                $invoice = $invoice_statuses[$so_no];
                            ?>
                                <span class="status-badge <?= getInvoiceStatusBadgeClass($invoice['status']) ?>" style="font-size:10px;">
                                    <?= htmlspecialchars($invoice['status'] ?? 'N/A') ?>
                                </span>
                                <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                                    Inv #: <?= htmlspecialchars($invoice['invoice_no']) ?>
                                </div>
                            <?php else: ?>
                                <span style="font-size:11px;color:#94a3b8;">Not Invoiced</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if (isset($payment_statuses[$so_no])): 
                                $payment = $payment_statuses[$so_no];
                                $payment_status_display = !empty($payment['payment_status']) ? $payment['payment_status'] : 'Unpaid';
                            ?>
                                <span class="payment-status-badge <?= getPaymentStatusBadgeClass($payment_status_display) ?>">
                                    <?= ucfirst($payment_status_display) ?>
                                </span>
                                <div style="font-size:10px;color:#94a3b8;margin-top:2px;">
                                    Inv #: <?= htmlspecialchars($payment['invoice_no']) ?>
                                </div>
                            <?php else: ?>
                                <span style="font-size:11px;color:#94a3b8;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-cell">
                                <a href="sales_order_list.php?so=<?= urlencode($order['sales_order_no']) ?>" class="btn-action btn-view">
                                    <i data-lucide="eye" style="width:14px;height:14px;"></i>
                                </a>
                                <!-- Edit and Delete buttons removed as requested -->
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i data-lucide="file-text"></i>
                <h3>No Sales Orders Found</h3>
                <p><?= (!empty($search_filter) || !empty($date_from) || !empty($date_to) || !empty($customer_filter) || !empty($truck_filter) || !empty($status_filter)) 
                    ? 'No orders match your filter criteria.' 
                    : 'No sales orders found for ' . date('F Y') . '. Start by creating your first sales order.' ?></p>
                <?php if (!empty($search_filter) || !empty($date_from) || !empty($date_to) || !empty($customer_filter) || !empty($truck_filter) || !empty($status_filter)): ?>
                <a href="?" style="display:inline-block;margin-top:12px;padding:8px 20px;background:#f1f5f9;color:#475569;border-radius:6px;text-decoration:none;">
                    Clear All Filters
                </a>
                <?php else: ?>
                <a href="sales_order.php" style="display:inline-block;margin-top:16px;padding:8px 24px;background:#2563eb;color:white;border-radius:6px;text-decoration:none;">
                    Create Sales Order
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Showing <?= $offset + 1 ?> - <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> orders
            </div>
            <div class="pagination-buttons">
                <?php 
                $query_params = '&search=' . urlencode($search_filter) . 
                                '&date_from=' . urlencode($date_from) . 
                                '&date_to=' . urlencode($date_to) . 
                                '&customer=' . urlencode($customer_filter) . 
                                '&truck=' . urlencode($truck_filter) . 
                                '&status=' . urlencode($status_filter);
                ?>
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $query_params ?>" class="pagination-btn">
                    <i data-lucide="chevron-left" style="width:16px;height:16px;"></i> Prev
                </a>
                <?php else: ?>
                <span class="pagination-btn disabled">Prev</span>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1) {
                    echo '<a href="?page=1' . $query_params . '" class="pagination-btn">1</a>';
                    if ($start_page > 2) echo '<span class="pagination-btn disabled">…</span>';
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    $active = $i === $page ? 'active' : '';
                    echo '<a href="?page=' . $i . $query_params . '" class="pagination-btn ' . $active . '">' . $i . '</a>';
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) echo '<span class="pagination-btn disabled">…</span>';
                    echo '<a href="?page=' . $total_pages . $query_params . '" class="pagination-btn">' . $total_pages . '</a>';
                }
                ?>

                <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?><?= $query_params ?>" class="pagination-btn">
                    Next <i data-lucide="chevron-right" style="width:16px;height:16px;"></i>
                </a>
                <?php else: ?>
                <span class="pagination-btn disabled">Next</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
lucide.createIcons();

// User roles from PHP
const userRoles = <?php echo json_encode($user_roles); ?>;
    
// Allowed pages from PHP
const allowedPages = <?php echo json_encode($allowed_pages); ?>;

// Check access function
function checkAccess(page) {
    if (userRoles.includes('admin')) return true;
    for (let role of userRoles) {
        if (allowedPages[role] && allowedPages[role].includes(page)) {
            return true;
        }
    }
    const modal = document.getElementById('accessModal');
    modal.style.display = 'flex';
    return false;
}

// Auto-submit search on Enter
document.getElementById('searchFilter')?.addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('filterForm').submit();
    }
});

// Recreate icons after any DOM updates
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
});
</script>
</body>
</html>