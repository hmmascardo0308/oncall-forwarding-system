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

// ---------------------------------------------------------------
// Default date filter: current week (Monday to Sunday)
// ---------------------------------------------------------------
$today = new DateTime('now', new DateTimeZone('Asia/Manila'));
$dayOfWeek = (int)$today->format('N'); // 1 = Monday, 7 = Sunday
$monday = clone $today;
$monday->modify('-' . ($dayOfWeek - 1) . ' days');
$sunday = clone $monday;
$sunday->modify('+6 days');

$default_date_from = $monday->format('Y-m-d');
$default_date_to   = $sunday->format('Y-m-d');

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$purchase_type_filter = isset($_GET['purchase_type']) ? trim($_GET['purchase_type']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$delivery_status_filter = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$delivery_payment_filter = isset($_GET['delivery_payment']) ? trim($_GET['delivery_payment']) : '';

// Use default weekly range if no date parameters are provided at all
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : $default_date_from;
$date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : $default_date_to;

// If the user explicitly cleared the dates via the "Clear" link, we still want the weekly default.
// Detect "clear" by checking if the request has no filter keys at all besides possibly page.
$has_any_filter = isset($_GET['search']) || isset($_GET['purchase_type']) || isset($_GET['supplier'])
    || isset($_GET['delivery_status']) || isset($_GET['delivery_payment'])
    || isset($_GET['date_from']) || isset($_GET['date_to']);

$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'po_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query — one row per item (not grouped by po_number)
$query = "SELECT 
            po.id,
            po.po_number,
            po.po_date,
            po.supplier_code,
            COALESCE(sl.supplier_name, po.supplier_name) AS supplier_name,
            po.item_code,
            COALESCE(im.item_name, po.item) AS item_name,
            po.purchase_type,
            po.truck_code,
            tm.plate_number,
            po.qty_ordered,
            po.unit,
            po.unit_cost,
            po.net_amount_due,
            po.total_amount_paid,
            po.payment_method,
            po.delivery_status,
            po.delivery_payment,
            po.created_by,
            po.created_at
          FROM purchase_order po
          LEFT JOIN supplier_lists sl 
                 ON sl.supplier_code = po.supplier_code COLLATE utf8mb4_general_ci
          LEFT JOIN item_masterlist im 
                 ON im.item_code = po.item_code COLLATE utf8mb4_general_ci
          LEFT JOIN truck_masterlist tm 
                 ON tm.truck_code = po.truck_code COLLATE utf8mb4_general_ci
          WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (po.po_number LIKE ? OR po.supplier_name LIKE ? OR po.supplier_code LIKE ? OR sl.supplier_name LIKE ? OR po.item LIKE ? OR po.item_code LIKE ? OR im.item_name LIKE ? OR po.created_by LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssssss";
}

if (!empty($purchase_type_filter)) {
    $query .= " AND po.purchase_type = ?";
    $params[] = $purchase_type_filter;
    $types .= "s";
}

if (!empty($supplier_filter)) {
    $query .= " AND po.supplier_code LIKE ?";
    $params[] = "%$supplier_filter%";
    $types .= "s";
}

if (!empty($delivery_status_filter)) {
    $query .= " AND po.delivery_status = ?";
    $params[] = $delivery_status_filter;
    $types .= "s";
}

if (!empty($delivery_payment_filter)) {
    $query .= " AND po.delivery_payment = ?";
    $params[] = $delivery_payment_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(po.po_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(po.po_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['po_date', 'po_number', 'supplier_code', 'item_code', 'purchase_type', 'qty_ordered', 'unit_cost', 'net_amount_due', 'total_amount_paid', 'delivery_status', 'delivery_payment', 'payment_method', 'created_by'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'po_date';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$query .= " ORDER BY po.$sort_by $sort_order, po.id ASC";

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

/**
 * Group items by PO number.
 * Debit / Credit / Remarks are header-level (same value across item rows of the same PO),
 * so we take them from the first item row of each group.
 */
$grouped_pos = [];
foreach ($purchase_orders as $row) {
    $po_num = $row['po_number'];

    if (!isset($grouped_pos[$po_num])) {
        // Credit now comes directly from total_amount_paid
        $credit = $row['total_amount_paid'] ?? 0;

        // Build remarks string for the PO
        $remarks_parts = [];
        if (!empty($row['delivery_status']))        $remarks_parts[] = $row['delivery_status'];
        if (!empty($row['delivery_payment']))       $remarks_parts[] = $row['delivery_payment'];
        if (!empty($row['payment_method']))         $remarks_parts[] = $row['payment_method'];
        $remarks = implode(' | ', $remarks_parts) ?: 'N/A';

        $grouped_pos[$po_num] = [
            'po_number' => $po_num,
            'items'     => [],
            // Header-level values taken from the first item row
            'debit'     => (float)($row['net_amount_due'] ?? 0),
            'credit'    => (float)$credit,
            'remarks'   => $remarks,
        ];
    }

    $grouped_pos[$po_num]['items'][] = $row;
}

// Pre-compute totals for footer (once per PO, not per item)
$total_debit  = 0;
$total_credit = 0;
foreach ($grouped_pos as $group) {
    $total_debit  += $group['debit'];
    $total_credit += $group['credit'];
}

// Get unique purchase types for filter dropdown
$purchase_type_query = "SELECT DISTINCT purchase_type FROM purchase_order WHERE purchase_type IS NOT NULL AND purchase_type != '' ORDER BY purchase_type";
$purchase_type_result = $conn->query($purchase_type_query);
$purchase_types = [];
if ($purchase_type_result) {
    while ($row = $purchase_type_result->fetch_assoc()) {
        $purchase_types[] = $row['purchase_type'];
    }
}

// Get unique suppliers for filter dropdown (use supplier_lists for name)
$supplier_query = "SELECT DISTINCT sl.supplier_code, sl.supplier_name 
                   FROM supplier_lists sl 
                   WHERE sl.supplier_code IS NOT NULL AND sl.supplier_code != '' 
                   ORDER BY sl.supplier_code";
$supplier_result = $conn->query($supplier_query);
$suppliers = [];
if ($supplier_result) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row;
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

// Get unique delivery payments for filter dropdown
$delivery_payment_query = "SELECT DISTINCT delivery_payment FROM purchase_order WHERE delivery_payment IS NOT NULL AND delivery_payment != '' ORDER BY delivery_payment";
$delivery_payment_result = $conn->query($delivery_payment_query);
$delivery_payments = [];
if ($delivery_payment_result) {
    while ($row = $delivery_payment_result->fetch_assoc()) {
        $delivery_payments[] = $row['delivery_payment'];
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
                    <!-- <a href="export_purchase_order_list.php<?php 
                        $params = [];
                        if ($search) $params[] = 'search=' . urlencode($search);
                        if ($purchase_type_filter) $params[] = 'purchase_type=' . urlencode($purchase_type_filter);
                        if ($supplier_filter) $params[] = 'supplier=' . urlencode($supplier_filter);
                        if ($delivery_status_filter) $params[] = 'delivery_status=' . urlencode($delivery_status_filter);
                        if ($delivery_payment_filter) $params[] = 'delivery_payment=' . urlencode($delivery_payment_filter);
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
                    </a> -->
                    <!-- Add this after the Export to Excel button -->
<a href="export_po_pdf.php<?php 
    $params = [];
    if ($search) $params[] = 'search=' . urlencode($search);
    if ($purchase_type_filter) $params[] = 'purchase_type=' . urlencode($purchase_type_filter);
    if ($supplier_filter) $params[] = 'supplier=' . urlencode($supplier_filter);
    if ($delivery_status_filter) $params[] = 'delivery_status=' . urlencode($delivery_status_filter);
    if ($delivery_payment_filter) $params[] = 'delivery_payment=' . urlencode($delivery_payment_filter);
    if ($date_from) $params[] = 'date_from=' . urlencode($date_from);
    if ($date_to) $params[] = 'date_to=' . urlencode($date_to);
    if ($sort_by) $params[] = 'sort_by=' . urlencode($sort_by);
    if ($sort_order) $params[] = 'sort_order=' . urlencode($sort_order);
    echo $params ? '?' . implode('&', $params) : '';
?>" 
   class="btn-export btn-pdf" 
   title="Export to PDF"
   target="_blank">
    <i data-lucide="file-text"></i>
    <span>Export to PDF</span>
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
                    <label>Purpose:</label>
                    <select name="purchase_type">
                        <option value="">All</option>
                        <?php foreach ($purchase_types as $ptype): ?>
                            <option value="<?php echo htmlspecialchars($ptype); ?>" <?php echo $purchase_type_filter == $ptype ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ptype); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Supplier:</label>
                    <select name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>" <?php echo $supplier_filter == $supplier['supplier_code'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['supplier_code'] . ' - ' . $supplier['supplier_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Delivery Status:</label>
                    <select name="delivery_status">
                        <option value="">All</option>
                        <?php foreach ($delivery_statuses as $dstatus): ?>
                            <option value="<?php echo htmlspecialchars($dstatus); ?>" <?php echo $delivery_status_filter == $dstatus ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dstatus); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Delivery Payment:</label>
                    <select name="delivery_payment">
                        <option value="">All</option>
                        <?php foreach ($delivery_payments as $dpayment): ?>
                            <option value="<?php echo htmlspecialchars($dpayment); ?>" <?php echo $delivery_payment_filter == $dpayment ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dpayment); ?>
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
                <?php if (count($grouped_pos) > 0): ?>
                     <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'po_date', 'sort_order' => $sort_by == 'po_date' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Date
                                        <?php if ($sort_by == 'po_date'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'po_number', 'sort_order' => $sort_by == 'po_number' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        PO Number
                                        <?php if ($sort_by == 'po_number'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'supplier_code', 'sort_order' => $sort_by == 'supplier_code' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Supplier
                                        <?php if ($sort_by == 'supplier_code'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'item_code', 'sort_order' => $sort_by == 'item_code' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Item
                                        <?php if ($sort_by == 'item_code'): ?>
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
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Other Description</th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'qty_ordered', 'sort_order' => $sort_by == 'qty_ordered' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Qty
                                        <?php if ($sort_by == 'qty_ordered'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'unit_cost', 'sort_order' => $sort_by == 'unit_cost' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Unit Price
                                        <?php if ($sort_by == 'unit_cost'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'net_amount_due', 'sort_order' => $sort_by == 'net_amount_due' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Debit Amount / <br> Total Amount
                                        <?php if ($sort_by == 'net_amount_due'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'total_amount_paid', 'sort_order' => $sort_by == 'total_amount_paid' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Credit Amount / <br> Total Paid
                                        <?php if ($sort_by == 'total_amount_paid'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Balance</th>
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Remarks</th>
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
    <?php foreach ($grouped_pos as $group): ?>
        <?php 
        $items = $group['items'];
        $item_count = count($items);
        $is_first = true;
        ?>
        <?php foreach ($items as $po): ?>
            <tr>
                <td><?php echo $po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '-'; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                </td>
                <td>
                    <?php 
                    echo htmlspecialchars($po['supplier_name'] ?: ($po['supplier_code'] ?: 'N/A'));
                    ?>
                </td>
                <td>
                    <?php 
                    echo htmlspecialchars($po['item_name'] ?: ($po['item_code'] ?: 'N/A'));
                    ?>
                </td>
                <td><?php echo htmlspecialchars($po['purchase_type'] ?: 'N/A'); ?></td>
                <td>
                    <?php 
                    if (strcasecmp(trim($po['purchase_type'] ?? ''), 'For Truck Repair and Maintenance') === 0) {
                        echo htmlspecialchars($po['plate_number'] ?: ($po['truck_code'] ?: '-'));
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <td>
                    <?php 
                    $qty = $po['qty_ordered'] ?? '';
                    $unit = $po['unit'] ?? '';
                    echo htmlspecialchars(trim($qty . ' ' . $unit));
                    ?>
                </td>
                <td class="amount"><?php echo number_format($po['unit_cost'] ?? 0, 2); ?></td>

                <?php if ($is_first): ?>
                    <!-- Debit / Credit / Balance / Remarks: merged across all items of this PO -->
                    <td class="amount" rowspan="<?php echo $item_count; ?>">
                        <?php echo number_format($group['debit'], 2); ?>
                    </td>
                    <td class="amount" rowspan="<?php echo $item_count; ?>">
                        <?php echo number_format($group['credit'], 2); ?>
                    </td>
                    <td class="amount" rowspan="<?php echo $item_count; ?>">
                        <?php 
                        $balance = $group['debit'] - $group['credit'];
                        echo number_format($balance, 2);
                        ?>
                    </td>
                    <td rowspan="<?php echo $item_count; ?>">
                        <?php echo htmlspecialchars($group['remarks']); ?>
                    </td>

                    <!-- View button: ONE per PO, spans all item rows -->
                    <td rowspan="<?php echo $item_count; ?>" style="vertical-align: middle; text-align: center;">
                        <a href="purchase_orders_view.php?po_number=<?php echo urlencode($po['po_number']); ?>" class="action-btn" title="View PO">
                            <i data-lucide="eye"></i>
                            View
                        </a>
                    </td>
                <?php endif; ?>
            </tr>
            <?php $is_first = false; ?>
        <?php endforeach; ?>
    <?php endforeach; ?>
</tbody>
                        <tfoot>
                            <tr>
                                <td colspan="8" style="text-align: right; font-weight: 600;">Total:</td>
                                <td class="amount" style="font-weight: 700;">
                                    <?php echo number_format($total_debit, 2); ?>
                                </td>
                                <td class="amount" style="font-weight: 700;">
                                    <?php echo number_format($total_credit, 2); ?>
                                </td>
                                <td class="amount" style="font-weight: 700;">
                                    <?php echo number_format($total_debit - $total_credit, 2); ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                    <div class="table-footer">
                        <span>Showing <?php echo count($grouped_pos); ?> PO(s), <?php echo count($purchase_orders); ?> item(s)</span>
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