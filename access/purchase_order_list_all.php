<?php
// purchase_order_list_all.php
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
$username  = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role
$is_admin = in_array('admin', $user_roles);
$base_user_type = $is_admin ? 'admin' : 'user';

// Enforce page access
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages);

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$delivery_status_filter = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$payment_status_filter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query
$query = "SELECT * FROM purchase_order WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (po_number LIKE ? OR supplier_name LIKE ? OR supplier_code LIKE ? OR item LIKE ? OR item_code LIKE ? OR created_by LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
}

if (!empty($delivery_status_filter)) {
    $query .= " AND delivery_status = ?";
    $params[] = $delivery_status_filter;
    $types .= "s";
}

if (!empty($payment_status_filter)) {
    $query .= " AND delivery_payment = ?";
    $params[] = $payment_status_filter;
    $types .= "s";
}

if (!empty($supplier_filter)) {
    $query .= " AND supplier_name LIKE ?";
    $params[] = "%$supplier_filter%";
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['po_number', 'created_at', 'delivered_date', 'supplier_name', 'purchase_type', 'net_amount_due', 'delivery_status', 'delivery_payment', 'created_by'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'created_at';
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

$purchase_orders = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }
}

// Get unique delivery statuses for filter dropdown
$delivery_status_query = "SELECT DISTINCT delivery_status FROM purchase_order WHERE delivery_status IS NOT NULL AND delivery_status != '' ORDER BY delivery_status";
$delivery_status_result = $conn->query($delivery_status_query);
$delivery_statuses = [];
if ($delivery_status_result) {
    while ($row = $delivery_status_result->fetch_assoc()) {
        $delivery_statuses[] = $row['delivery_status'];
    }
}

// Get unique payment statuses for filter dropdown
$payment_status_query = "SELECT DISTINCT delivery_payment FROM purchase_order WHERE delivery_payment IS NOT NULL AND delivery_payment != '' ORDER BY delivery_payment";
$payment_status_result = $conn->query($payment_status_query);
$payment_statuses = [];
if ($payment_status_result) {
    while ($row = $payment_status_result->fetch_assoc()) {
        $payment_statuses[] = $row['delivery_payment'];
    }
}

// Get unique suppliers for filter dropdown
$supplier_query = "SELECT DISTINCT supplier_name FROM purchase_order WHERE supplier_name IS NOT NULL AND supplier_name != '' ORDER BY supplier_name";
$supplier_result = $conn->query($supplier_query);
$suppliers = [];
if ($supplier_result) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row['supplier_name'];
    }
}

// Function to get status badge color
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    if (strpos($status, 'completed') !== false || strpos($status, 'delivered') !== false) {
        return 'badge-success';
    } elseif (strpos($status, 'pending') !== false) {
        return 'badge-warning';
    } elseif (strpos($status, 'cancelled') !== false || strpos($status, 'canceled') !== false) {
        return 'badge-danger';
    } elseif (strpos($status, 'processing') !== false || strpos($status, 'ordered') !== false) {
        return 'badge-info';
    } else {
        return 'badge-secondary';
    }
}

// Function to format currency
function formatCurrency($amount) {
    return number_format($amount, 2);
}

$role_display_name = getRoleDisplayName($user_roles);
$success_msg = isset($_SESSION['login_success']) ? $_SESSION['login_success'] : null;
if (isset($_SESSION['login_success'])) unset($_SESSION['login_success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="css/po_list_all.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
  
    <style>
        /* Additional styles for the new layout */
        .badge-payment {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-payment.paid {
            background: #d4edda;
            color: #155724;
        }
        .badge-payment.unpaid {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-payment.partial {
            background: #fff3cd;
            color: #856404;
        }
        .badge-payment.na {
            background: #e2e3e5;
            color: #383d41;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
        }
        .action-btn:hover {
            background: #0056b3;
            color: white;
        }
        .action-btn i {
            width: 14px;
            height: 14px;
        }
        .table-container table th a {
            color: var(--text-color);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .table-container table th a:hover {
            color: var(--primary-color);
        }
        .filter-group input[type="text"] {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            min-width: 150px;
        }
        .table-container table tfoot td {
    padding: 12px;
    background: #f8f9fa;
    border-top: 2px solid #dee2e6;
  
}
.table-container table tfoot td.amount {
    text-align: right;
    color: #014e13;
    font-size: 15px;

}
    </style>
</head>
<body>

    <?php if ($success_msg): ?>
        <div id="flash-message"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <!-- Access Denied Modal -->
    <div id="accessModal" class="modal-overlay">
        <div class="access-modal">
            <i data-lucide="shield-off"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access this page.</p>
            <button class="modal-btn" onclick="closeModal()">OK</button>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="detail-modal-overlay" id="detailModal">
        <div class="detail-modal">
            <button class="modal-close" onclick="closeDetailModal()">&times;</button>
            <div class="modal-header">
                <h2 id="detailPoNumber">Purchase Order</h2>
                <span class="badge-status" id="detailStatus">Status</span>
            </div>
            <div class="detail-grid" id="detailContent">
                <!-- Populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <a href="reports.php" style="color:red; font-weight:bold; font-size:16px; text-decoration:none;">
    Reports
</a> /  <span style="color:red; font-weight: bold; font-size: 16px;">Purchase Order List</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <div class="page-header">
                <h1>📋 Purchase Orders</h1>
                <div class="header-actions">
                    <!-- EXPORT BUTTON -->
                    <a href="export_purchase_order_list.php<?php 
                        $params = [];
                        if ($search) $params[] = 'search=' . urlencode($search);
                        if ($delivery_status_filter) $params[] = 'delivery_status=' . urlencode($delivery_status_filter);
                        if ($payment_status_filter) $params[] = 'payment_status=' . urlencode($payment_status_filter);
                        if ($supplier_filter) $params[] = 'supplier=' . urlencode($supplier_filter);
                        if ($date_from) $params[] = 'date_from=' . urlencode($date_from);
                        if ($date_to) $params[] = 'date_to=' . urlencode($date_to);
                        if ($sort_by) $params[] = 'sort_by=' . urlencode($sort_by);
                        if ($sort_order) $params[] = 'sort_order=' . urlencode($sort_order);
                        echo $params ? '?' . implode('&', $params) : '';
                    ?>" 
                       class="btn-export" 
                       title="Export to Excel">
                        <i data-lucide="file-spreadsheet"></i>
                        <span>Export to Excel</span>
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="filters-bar" id="filterForm">
                <div class="search-wrapper">
                    <i data-lucide="search" style="width:25px;height:25px;color: black;"></i>
                    <input type="text" name="search" placeholder="Search PO #, Supplier, Item, Created By..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label>Delivery Status:</label>
                    <select name="delivery_status">
                        <option value="">All</option>
                        <?php foreach ($delivery_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $delivery_status_filter == $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Payment Status:</label>
                    <select name="payment_status">
                        <option value="">All</option>
                        <?php foreach ($payment_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $payment_status_filter == $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Supplier:</label>
                    <select name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo $supplier_filter == $supplier ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>From:</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>

                <div class="filter-group">
                    <label>To:</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="purchase_order_list_all.php" class="btn btn-outline btn-sm">Clear</a>
            </form>

            <!-- Table -->
            <div class="table-container">
                <?php if (count($purchase_orders) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'po_number', 'sort_order' => $sort_by == 'po_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        PO Number
                                        <?php if ($sort_by == 'po_number'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'created_at', 'sort_order' => $sort_by == 'created_at' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Transaction Date
                                        <?php if ($sort_by == 'created_at'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'delivered_date', 'sort_order' => $sort_by == 'delivered_date' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Delivery Date
                                        <?php if ($sort_by == 'delivered_date'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'supplier_name', 'sort_order' => $sort_by == 'supplier_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Supplier
                                        <?php if ($sort_by == 'supplier_name'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'purchase_type', 'sort_order' => $sort_by == 'purchase_type' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Purpose
                                        <?php if ($sort_by == 'purchase_type'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'net_amount_due', 'sort_order' => $sort_by == 'net_amount_due' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Amount
                                        <?php if ($sort_by == 'net_amount_due'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'delivery_status', 'sort_order' => $sort_by == 'delivery_status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        PO Status
                                        <?php if ($sort_by == 'delivery_status'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'delivery_payment', 'sort_order' => $sort_by == 'delivery_payment' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Payment Status
                                        <?php if ($sort_by == 'delivery_payment'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'created_by', 'sort_order' => $sort_by == 'created_by' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Created By
                                        <?php if ($sort_by == 'created_by'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchase_orders as $po): ?>
                                <tr ondblclick="showDetail(<?php echo htmlspecialchars(json_encode($po)); ?>)">
                                    <td>
                                        <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                                    </td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($po['created_at'])); ?></td>
                                    <td><?php echo $po['delivered_date'] ? date('M d, Y', strtotime($po['delivered_date'])) : '-'; ?></td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($po['supplier_name']); ?>">
                                            <?php echo htmlspecialchars($po['supplier_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($po['purchase_type'] ?: 'N/A'); ?></td>
                                    <td class="amount"><?php echo number_format($po['net_amount_due'] ?? 0, 2); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo getStatusBadgeClass($po['delivery_status']); ?>">
                                            <?php echo htmlspecialchars($po['delivery_status'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $payment = strtolower($po['delivery_payment'] ?? '');
                                        $paymentClass = 'na';
                                        if (strpos($payment, 'paid') !== false) $paymentClass = 'paid';
                                        elseif (strpos($payment, 'unpaid') !== false || strpos($payment, 'not paid') !== false) $paymentClass = 'unpaid';
                                        elseif (strpos($payment, 'partial') !== false) $paymentClass = 'partial';
                                        ?>
                                        <span class="badge-payment <?php echo $paymentClass; ?>">
                                            <?php echo htmlspecialchars($po['delivery_payment'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($po['created_by'] ?: 'N/A'); ?></td>
                                    <td>
                                        <a href="purchase_orders_view.php?po_number=<?php echo urlencode($po['po_number']); ?>" class="action-btn" title="View PO">
                                            <i data-lucide="eye"></i>
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" style="text-align: right; font-weight: 600;">Total Amount:</td>
                                <td class="amount" style="font-weight: 700;">
                                    <?php echo number_format(array_sum(array_column($purchase_orders, 'net_amount_due')), 2); ?>
                                </td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="table-footer">
                        <span>Showing <?php echo count($purchase_orders); ?> record(s)</span>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i data-lucide="file-search"></i>
                        <h3>No Purchase Orders Found</h3>
                        <p>Try adjusting your search or filter criteria.</p>
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

        // User roles from PHP
        const userRoles = <?php echo json_encode($user_roles); ?>;
        const allowedPages = <?php echo json_encode($allowed_pages); ?>;
        const modal = document.getElementById('accessModal');

        function checkAccess(page) {
            if (userRoles.includes('admin')) return true;
            for (let role of userRoles) {
                if (allowedPages[role] && allowedPages[role].includes(page)) {
                    return true;
                }
            }
            modal.style.display = 'flex';
            return false;
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });

        // Detail Modal Functions
        function showDetail(po) {
            const modal = document.getElementById('detailModal');
            const poNumber = document.getElementById('detailPoNumber');
            const status = document.getElementById('detailStatus');
            const content = document.getElementById('detailContent');

            // Set header
            poNumber.textContent = 'PO #' + po.po_number;
            
            // Set status badge
            const statusClass = getStatusBadgeClass(po.delivery_status);
            status.className = 'badge-status ' + statusClass;
            status.textContent = po.delivery_status || 'N/A';

            // Build detail grid
            const fields = [
                { label: 'Supplier Code', value: po.supplier_code, full: false },
                { label: 'Supplier Name', value: po.supplier_name, full: false },
                { label: 'Purchase Type', value: po.purchase_type, full: false },
                { label: 'PO Date', value: formatDate(po.po_date), full: false },
                { label: 'Expected Delivery', value: po.expected_delivery ? formatDate(po.expected_delivery) : 'N/A', full: false },
                { label: 'Payment Terms', value: po.payment_terms || 'N/A', full: false },
                { label: 'Currency', value: po.currency || 'N/A', full: false },
                { label: 'Delivery Mode', value: po.delivery_mode || 'N/A', full: false },
                { label: 'Delivery Address', value: po.delivery_address || 'N/A', full: true },
                { label: 'Truck Code', value: po.truck_code || 'N/A', full: false },
                { label: 'Trailer Code', value: po.trailer_code || 'N/A', full: false },
                { label: 'Prime Mover', value: po.prime_mover_code || 'N/A', full: false },
                { label: 'Customer Code', value: po.customer_code || 'N/A', full: false },
                { label: 'Brand', value: po.brand || 'N/A', full: false },
                { label: 'Model', value: po.model || 'N/A', full: false },
                { label: 'Item Code', value: po.item_code || 'N/A', full: false },
                { label: 'Item', value: po.item || 'N/A', full: false },
                { label: 'Unit', value: po.unit || 'N/A', full: false },
                { label: 'Qty Ordered', value: formatNumber(po.qty_ordered), full: false },
                { label: 'Qty Received', value: formatNumber(po.qty_received), full: false },
                { label: 'Warranty', value: po.warranty || 'N/A', full: false },
                { label: 'Remarks', value: po.remarks || 'N/A', full: true },
                { label: 'Unit Cost', value: formatCurrency(po.unit_cost), full: false },
                { label: 'Subtotal', value: formatCurrency(po.subtotal), full: false },
                { label: 'Total VAT', value: formatCurrency(po.total_vat), full: false },
                { label: 'Total Amount', value: formatCurrency(po.total_amount), full: false },
                { label: 'Total Amount Paid', value: formatCurrency(po.total_amount_paid), full: false },
                { label: 'Withholding Tax %', value: po.withholding_tax_percent ? po.withholding_tax_percent + '%' : 'N/A', full: false },
                { label: 'Withholding Tax Amount', value: formatCurrency(po.withholding_tax_amount), full: false },
                { label: 'Net Amount Due', value: formatCurrency(po.net_amount_due), full: false },
                { label: 'Delivery Status', value: po.delivery_status || 'N/A', full: false },
                { label: 'Delivery Payment', value: po.delivery_payment || 'N/A', full: false },
                { label: 'Received By', value: po.received_by || 'N/A', full: false },
                { label: 'Delivered Date', value: po.delivered_date ? formatDate(po.delivered_date) : 'N/A', full: false },
                { label: 'Created By', value: po.created_by || 'N/A', full: false },
                { label: 'Created At', value: formatDateTime(po.created_at), full: false },
                { label: 'Print Status', value: po.print_status || 'N/A', full: false },
            ];

            let html = '';
            
            // Build all fields with sections
            let hasVehicleSection = false;
            let hasFinancialSection = false;
            let hasDeliverySection = false;

            fields.forEach((f) => {
                const label = f.label.toLowerCase().replace(/ /g, '_');
                
                // Vehicle section
                if (['truck_code', 'trailer_code', 'prime_mover_code', 'customer_code', 'brand', 'model'].includes(label)) {
                    if (!hasVehicleSection) {
                        html += `<div class="detail-section-title">Vehicle Details</div>`;
                        hasVehicleSection = true;
                    }
                    html += buildDetailItem(f);
                    return;
                }

                // Financial section
                if (['unit_cost', 'subtotal', 'total_vat', 'total_amount', 'total_amount_paid', 'withholding_tax_%', 'withholding_tax_amount', 'net_amount_due'].includes(label)) {
                    if (!hasFinancialSection) {
                        html += `<div class="detail-section-title">Financial Details</div>`;
                        hasFinancialSection = true;
                    }
                    html += buildDetailItem(f);
                    return;
                }

                // Delivery section
                if (['delivery_status', 'delivery_payment', 'received_by', 'delivered_date', 'print_status'].includes(label)) {
                    if (!hasDeliverySection) {
                        html += `<div class="detail-section-title">Delivery Details</div>`;
                        hasDeliverySection = true;
                    }
                    html += buildDetailItem(f);
                    return;
                }

                // Regular fields
                html += buildDetailItem(f);
            });

            content.innerHTML = html;
            modal.classList.add('active');
        }

        function buildDetailItem(field) {
            const fullClass = field.full ? 'full-width' : '';
            return `
                <div class="detail-item ${fullClass}">
                    <span class="label">${field.label}</span>
                    <span class="value${field.label.includes('Amount') || field.label.includes('Total') || field.label.includes('Subtotal') ? ' amount' : ''}">${field.value}</span>
                </div>
            `;
        }

        function closeDetailModal() {
            document.getElementById('detailModal').classList.remove('active');
        }

        // Close modal on overlay click
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const detailModal = document.getElementById('detailModal');
                if (detailModal.classList.contains('active')) {
                    closeDetailModal();
                }
            }
        });

        // Helper functions
        function getStatusBadgeClass(status) {
            const s = (status || '').toLowerCase();
            if (s.includes('completed') || s.includes('delivered')) return 'badge-success';
            if (s.includes('pending')) return 'badge-warning';
            if (s.includes('cancelled') || s.includes('canceled')) return 'badge-danger';
            if (s.includes('processing') || s.includes('ordered')) return 'badge-info';
            return 'badge-secondary';
        }

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }

        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) + ' ' +
                   d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        function formatNumber(num) {
            return num ? Number(num).toLocaleString() : '0';
        }

        function formatCurrency(amount) {
            if (amount === null || amount === undefined || isNaN(amount)) return '0.00';
            return Number(amount).toFixed(2);
        }
    </script>
</body>
</html>