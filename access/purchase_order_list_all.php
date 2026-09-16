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
$po_status_filter = isset($_GET['po_status']) ? trim($_GET['po_status']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query — GROUP BY po_number so each PO is shown only once.
// All header-level columns are the same for every item row of the same PO,
// so MIN() just picks one value without changing it.
$query = "SELECT 
            po_number,
            MIN(status)            AS status,
            MIN(created_at)        AS created_at,
            MIN(delivered_date)    AS delivered_date,
            MIN(supplier_name)     AS supplier_name,
            MIN(purchase_type)     AS purchase_type,
            MIN(net_amount_due)    AS net_amount_due,
            MIN(delivery_status)   AS delivery_status,
            MIN(delivery_payment)  AS delivery_payment,
            MIN(created_by)        AS created_by
          FROM purchase_order WHERE 1=1";
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

if (!empty($po_status_filter)) {
    $query .= " AND status = ?";
    $params[] = $po_status_filter;
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

// Add GROUP BY (one row per PO)
$query .= " GROUP BY po_number";

// Add sorting
$allowed_sort = ['po_number', 'status', 'created_at', 'delivered_date', 'supplier_name', 'purchase_type', 'net_amount_due', 'delivery_status', 'delivery_payment', 'created_by'];
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

// Get unique PO statuses for filter dropdown
$po_status_query = "SELECT DISTINCT status FROM purchase_order WHERE status IS NOT NULL AND status != '' ORDER BY status";
$po_status_result = $conn->query($po_status_query);
$po_statuses = [];
if ($po_status_result) {
    while ($row = $po_status_result->fetch_assoc()) {
        $po_statuses[] = $row['status'];
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

// Function to get status badge color (delivery status)
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

// Function to get PO status badge class (Created / Approved / Rejected / Completed / Cancelled)
function getPoStatusBadgeClass($status) {
    switch (strtolower(trim($status ?? ''))) {
        case 'created':   return 'status-created';
        case 'approved':  return 'status-approved';
        case 'rejected':  return 'status-rejected';
        case 'completed': return 'status-completed';
        case 'cancelled':
        case 'canceled':  return 'status-cancelled';
        default:          return 'status-pending';
    }
}

// Function to get PO status display text
function getPoStatusText($status) {
    $status = trim($status ?? '');
    if ($status === '') return 'Created';
    return $status;
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
    <!-- <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/po_list_all.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
  

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
                        if ($po_status_filter) $params[] = 'po_status=' . urlencode($po_status_filter);
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
                    <label>PO Status:</label>
                    <select name="po_status">
                        <option value="">All</option>
                        <?php foreach ($po_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $po_status_filter == $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'status', 'sort_order' => $sort_by == 'status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Status
                                        <?php if ($sort_by == 'status'): ?>
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
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge-status-po <?php echo getPoStatusBadgeClass($po['status'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(getPoStatusText($po['status'] ?? '')); ?>
                                        </span>
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
                                <td colspan="6" style="text-align: right; font-weight: 600;">Total Amount:</td>
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
    </script>
</body>
</html>