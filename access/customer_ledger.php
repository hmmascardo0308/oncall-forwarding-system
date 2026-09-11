<?php
// customer_ledger.php
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
$full_name = $_SESSION['full_name'] ?? $username;

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
        'text' => "You don't have permission to access this page."
    ];
    header("Location: home.php");
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

// Success/Error messages
$success_msg = null;
if (isset($_SESSION['login_success'])) {
    $success_msg = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

$role_display_name = getRoleDisplayName($user_roles);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Ledger | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="css/customer_led.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
</head>
<body>

    <?php if ($success_msg): ?>
        <div id="flash-message"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <a href="reports.php" style="color:red; font-weight:bold; font-size:16px; text-decoration:none;">
    Reports
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Customer Ledger</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>Customer Ledger</h1>
                <p>View all customers and their sales order history.</p>
            </section>

            <div class="ledger-container">
                <div class="ledger-header">
                    <h2>
                        Customer Records
                        <span class="order-count" style="margin-left: 8px;"><?php echo count($customers); ?></span>
                    </h2>
                    <div class="ledger-controls">
                        <!-- EXPORT BUTTON - Added here -->
                        <a href="export_customer_ledger.php" class="btn-export" title="Export to Excel">
                            <i data-lucide="file-spreadsheet"></i>
                            <span>Export to Excel</span>
                        </a>
                        <div class="search-box">
                            <i data-lucide="search"></i>
                            <input type="text" id="searchInput" placeholder="Search customers..." onkeyup="filterTable()">
                        </div>
                    </div>
                </div>

                <?php if (empty($customers)): ?>
                    <div class="no-data">
                        <i data-lucide="users"></i>
                        <p>No customers found.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="ledger-table" id="customerTable">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"></th>
                                    <th>Customer Code</th>
                                    <th>Full Name / Company</th>
                                    <th>Contact</th>
                                    <th>Credit Limit</th>
                                    <th>Status</th>
                                    <th>Orders</th>
                                    <th>Since</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): 
                                    $customer_code = $customer['customer_code'];
                                    $orders = $sales_orders[$customer_code] ?? [];
                                    $order_count = count($orders);
                                    $invoice_list = $invoices[$customer_code] ?? [];
                                ?>
                                <tr class="customer-row" data-customer="<?php echo htmlspecialchars($customer['full_name'] . ' ' . $customer['company_name']); ?>" data-code="<?php echo htmlspecialchars($customer_code); ?>">
                                    <td>
                                        <?php if ($order_count > 0): ?>
                                        <button class="expand-btn" onclick="toggleOrders(this)" aria-label="Toggle orders">
                                            <i data-lucide="chevron-down"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="customer-code-badge"><?php echo htmlspecialchars($customer_code); ?></span>
                                    </td>
                                    <td class="customer-name-cell">
                                        <?php 
                                        $display_name = !empty($customer['full_name']) ? $customer['full_name'] : $customer['company_name'];
                                        echo htmlspecialchars($display_name);
                                        if (!empty($customer['company_name']) && empty($customer['full_name'])) {
                                            // Already showing company name
                                        } elseif (!empty($customer['company_name']) && !empty($customer['full_name'])) {
                                            echo '<br><span style="font-size:12px;color:var(--text-muted);">' . htmlspecialchars($customer['company_name']) . '</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($customer['contact_number'])): ?>
                                            <span style="font-size:13px;"><?php echo htmlspecialchars($customer['contact_number']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($customer['email'])): ?>
                                            <br><span style="font-size:12px;color:var(--text-muted);"><?php echo htmlspecialchars($customer['email']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $credit_limit = $customer['credit_limit'] ?? 0;
                                        if ($credit_limit > 0): ?>
                                            <span style="font-weight:600;">₱ <?php echo number_format($credit_limit, 2); ?></span>
                                            <br><span style="font-size:11px;color:var(--text-muted);"><?php echo htmlspecialchars($customer['credit_terms'] ?? 'N/A'); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower($customer['status'] ?? 'Active'); ?>">
                                            <?php echo htmlspecialchars($customer['status'] ?? 'Active'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($order_count > 0): ?>
                                            <span class="order-count"><?php echo $order_count; ?> orders</span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted); font-size:13px;">No orders</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:13px; color:var(--text-muted);">
                                        <?php 
                                        if (!empty($customer['customer_since'])) {
                                            echo date('M d, Y', strtotime($customer['customer_since']));
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php if ($order_count > 0): ?>
                                <tr class="order-details-row" style="display:none;">
                                    <td colspan="8" style="padding: 0;">
                                        <div class="order-details open">
                                            <span class="sub-table-label">📋 Sales Orders</span>
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>SO No.</th>
                                                        <th>Order Date</th>
                                                        <th>Delivery Date</th>
                                                        <th>Truck / Plate</th>
                                                        <th>Amount</th>
                                                        <th>Status</th>
                                                        <th>Invoices</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($orders as $order): 
                                                        $order_invoices = array_filter($invoice_list, function($inv) use ($order) {
                                                            return $inv['sales_order_no'] == $order['sales_order_no'];
                                                        });
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <a href="sales_order_list.php?so_no=<?php echo urlencode($order['sales_order_no']); ?>" 
                                                               style="color:var(--accent-blue); text-decoration:none; font-weight:500;">
                                                                <?php echo htmlspecialchars($order['sales_order_no']); ?>
                                                            </a>
                                                        </td>
                                                        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                                        <td><?php echo date('M d, Y', strtotime($order['delivery_date'])); ?></td>
                                                        <td>
                                                            <?php echo htmlspecialchars($order['truck_code'] ?? '—'); ?>
                                                            <br><span style="font-size:12px;color:var(--text-muted);"><?php echo htmlspecialchars($order['plate_number'] ?? ''); ?></span>
                                                        </td>
                                                        <td style="font-weight:600;">₱ <?php echo number_format($order['amount'] ?? 0, 2); ?></td>
                                                        <td>
                                                            <span class="status-badge <?php echo strtolower($order['status'] ?? 'pending'); ?>">
                                                                <?php echo htmlspecialchars($order['status'] ?? 'Pending'); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($order_invoices)): ?>
                                                                <?php foreach ($order_invoices as $inv): ?>
                                                                    <a href="service_invoice.php?invoice_no=<?php echo urlencode($inv['invoice_no']); ?>" 
                                                                       class="view-invoice-link" style="font-size:12px; display:block;">
                                                                        <?php echo htmlspecialchars($inv['invoice_no']); ?>
                                                                        <span class="status-badge <?php echo strtolower($inv['payment_status'] ?? 'unpaid'); ?>" style="font-size:10px;">
                                                                            <?php echo htmlspecialchars($inv['payment_status'] ?? '—'); ?>
                                                                        </span>
                                                                    </a>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <span style="color:var(--text-muted); font-size:12px;">No invoices</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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

        // Toggle order details
        function toggleOrders(btn) {
            const row = btn.closest('tr');
            const detailsRow = row.nextElementSibling;
            
            if (detailsRow && detailsRow.classList.contains('order-details-row')) {
                const isHidden = detailsRow.style.display === 'none';
                detailsRow.style.display = isHidden ? 'table-row' : 'none';
                btn.classList.toggle('rotated');
            }
        }

        // Search filter
        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const rows = document.querySelectorAll('.customer-row');
            
            rows.forEach(row => {
                const customerData = row.getAttribute('data-customer') || '';
                const codeData = row.getAttribute('data-code') || '';
                const text = (customerData + ' ' + codeData).toLowerCase();
                
                if (text.includes(filter)) {
                    row.style.display = '';
                    // Hide details row if it exists
                    const detailsRow = row.nextElementSibling;
                    if (detailsRow && detailsRow.classList.contains('order-details-row')) {
                        if (filter.length > 0) {
                            // Keep details hidden during search
                            detailsRow.style.display = 'none';
                            const btn = row.querySelector('.expand-btn');
                            if (btn) btn.classList.remove('rotated');
                        }
                    }
                } else {
                    row.style.display = 'none';
                    const detailsRow = row.nextElementSibling;
                    if (detailsRow && detailsRow.classList.contains('order-details-row')) {
                        detailsRow.style.display = 'none';
                        const btn = row.querySelector('.expand-btn');
                        if (btn) btn.classList.remove('rotated');
                    }
                }
            });
        }

        // Expose functions globally
        window.toggleOrders = toggleOrders;
        window.filterTable = filterTable;
    </script>

</body>
</html>