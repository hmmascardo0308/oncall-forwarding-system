<?php
// sales_order_list_all.php
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
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'order_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query
$query = "SELECT * FROM sales_order WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (sales_order_no LIKE ? OR customer_name LIKE ? OR customer_code LIKE ? OR truck_code LIKE ? OR plate_number LIKE ? OR driver LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND order_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND order_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['sales_order_no', 'customer_name', 'order_date', 'delivery_date', 'status', 'amount', 'created_date'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'order_date';
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

$sales_orders = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_orders[] = $row;
    }
}

// ============================================================
// FETCH SPECIAL CHARGES FOR ALL DISPLAYED SOs (bulk query)
// ============================================================
$special_charges_map = []; // keyed by sales_order_no => array of charges

if (!empty($sales_orders)) {
    $so_numbers = array_column($sales_orders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $charge_query = "SELECT sales_order_no, charge_kind, charge_amount 
                         FROM sales_order_special_charge 
                         WHERE sales_order_no IN ($placeholders)";
        $stmt = $conn->prepare($charge_query);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($so_numbers)), ...$so_numbers);
            $stmt->execute();
            $charge_result = $stmt->get_result();
            while ($row = $charge_result->fetch_assoc()) {
                $so_no = $row['sales_order_no'];
                if (!isset($special_charges_map[$so_no])) {
                    $special_charges_map[$so_no] = [];
                }
                $special_charges_map[$so_no][] = [
                    'kind'   => $row['charge_kind'],
                    'amount' => floatval($row['charge_amount'])
                ];
            }
        }
    }
}

// ============================================================
// HELPER: Compute the full amount breakdown for a single SO
// ============================================================
function computeSOAmounts($so, $special_charges_map) {
    $so_no = $so['sales_order_no'] ?? '';

    $net_amount      = floatval($so['amount'] ?? 0);          // already-discounted net
    $discount_amount = floatval($so['discount_amount'] ?? 0);
    $vat_percent     = floatval($so['vat_percent'] ?? 0);

    // Reconstruct the gross subtotal (before discount)
    $gross_subtotal = $net_amount + $discount_amount;

    // VAT is computed on the net amount (matches how save_so.php stored it)
    $vat_amount = $net_amount * ($vat_percent / 100);

    // Special charges
    $special_total = 0;
    if (!empty($special_charges_map[$so_no])) {
        foreach ($special_charges_map[$so_no] as $sc) {
            $special_total += floatval($sc['amount'] ?? 0);
        }
    }

    return [
        'gross_subtotal'  => $gross_subtotal,
        'net_amount'      => $net_amount,
        'discount_amount' => $discount_amount,
        'vat_percent'     => $vat_percent,
        'vat_amount'      => $vat_amount,
        'special_total'   => $special_total,
        'grand_total'     => $net_amount + $vat_amount + $special_total,
        'special_items'   => $special_charges_map[$so_no] ?? []
    ];
}

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM sales_order WHERE status IS NOT NULL AND status != '' ORDER BY status";
$status_result = $conn->query($status_query);
$statuses = [];
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[] = $row['status'];
    }
}

// Function to get status badge color
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    if (strpos($status, 'completed') !== false || strpos($status, 'delivered') !== false || strpos($status, 'approved') !== false) {
        return 'badge-success';
    } elseif (strpos($status, 'pending') !== false || strpos($status, 'draft') !== false) {
        return 'badge-warning';
    } elseif (strpos($status, 'cancelled') !== false || strpos($status, 'canceled') !== false || strpos($status, 'void') !== false) {
        return 'badge-danger';
    } elseif (strpos($status, 'processing') !== false || strpos($status, 'in progress') !== false) {
        return 'badge-info';
    } else {
        return 'badge-secondary';
    }
}

// Function to format currency
function formatCurrency($amount) {
    if ($amount === null || $amount === '' || is_nan($amount)) return '0.00';
    return number_format((float)$amount, 2);
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
    <title>Sales Order List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/so_list_all.css?v=<?= time(); ?>">
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

    <!-- Detail Modal -->
    <div class="detail-modal-overlay" id="detailModal">
        <div class="detail-modal">
            <button class="modal-close" onclick="closeDetailModal()">&times;</button>
            <div class="modal-header">
                <h2 id="detailOrderNo">Sales Order</h2>
                <div class="header-badges">
                    <span class="badge-status" id="detailStatus">Status</span>
                </div>
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
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Sales Order List</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <div class="page-header">
    <h1>📦 Sales Orders</h1>
    <div class="header-actions">
        <!-- EXPORT BUTTON - Added here -->
        <a href="export_sales_order_list.php<?php 
            $params = [];
            if ($search) $params[] = 'search=' . urlencode($search);
            if ($status_filter) $params[] = 'status=' . urlencode($status_filter);
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
                    <i data-lucide="search" style="width:18px;height:18px;color:var(--text-muted);"></i>
                    <input type="text" name="search" placeholder="Search SO #, Customer, Truck, Plate, Driver..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label>Status:</label>
                    <select name="status">
                        <option value="">All Status</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status_filter == $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
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
                <a href="sales_order_list_all.php" class="btn btn-outline btn-sm">Clear</a>
            </form>

            <!-- Table -->
            <div class="table-container">
                <?php if (count($sales_orders) > 0): ?>
                    <?php
                    // Pre-compute totals for the footer
                    $grand_total_sum = 0;
                    $grand_vat_sum = 0;
                    $grand_charges_sum = 0;
                    ?>
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'sales_order_no', 'sort_order' => $sort_by == 'sales_order_no' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        SO #
                                        <?php if ($sort_by == 'sales_order_no'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'customer_name', 'sort_order' => $sort_by == 'customer_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Customer
                                        <?php if ($sort_by == 'customer_name'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Truck</th>
                                <th>Plate</th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'order_date', 'sort_order' => $sort_by == 'order_date' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Order Date
                                        <?php if ($sort_by == 'order_date'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'delivery_date', 'sort_order' => $sort_by == 'delivery_date' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Delivery
                                        <?php if ($sort_by == 'delivery_date'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Qty</th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'amount', 'sort_order' => $sort_by == 'amount' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Amount
                                        <?php if ($sort_by == 'amount'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'status', 'sort_order' => $sort_by == 'status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Status
                                        <?php if ($sort_by == 'status'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'created_date', 'sort_order' => $sort_by == 'created_date' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Created
                                        <?php if ($sort_by == 'created_date'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_orders as $so): 
                                $calc = computeSOAmounts($so, $special_charges_map);
                                $grand_total_sum   += $calc['grand_total'];
                                $grand_vat_sum     += $calc['vat_amount'];
                                $grand_charges_sum += $calc['special_total'];
                            ?>
                                <tr ondblclick='showDetail(<?php echo htmlspecialchars(json_encode(array_merge($so, [
                                    "computed" => $calc
                                ])), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <td>
                                        <strong><?php echo htmlspecialchars($so['sales_order_no']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($so['customer_name']); ?>">
                                            <?php echo htmlspecialchars($so['customer_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($so['truck_code'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($so['plate_number'] ?: 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($so['order_date'])); ?></td>
                                    <td><?php echo $so['delivery_date'] ? date('M d, Y', strtotime($so['delivery_date'])) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($so['quantity'] ?: '0'); ?></td>
                                    <td class="amount">
                                        <div style="font-weight:600;">
                                            ₱<?php echo formatCurrency($calc['grand_total']); ?>
                                        </div>
                                        <?php if ($calc['discount_amount'] > 0): ?>
                                        <div style="font-size:11px;color:#64748b;font-weight:400;">
                                            Subtotal: ₱<?php echo formatCurrency($calc['gross_subtotal']); ?>
                                        </div>
                                        <div style="font-size:11px;color:#64748b;font-weight:400;">
                                            Disc: ₱<?php echo formatCurrency($calc['discount_amount']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($calc['vat_percent'] > 0): ?>
                                        <div style="font-size:10px;color:#94a3b8;">
                                            VAT (<?php echo htmlspecialchars($so['vat_percent']); ?>%): ₱<?php echo formatCurrency($calc['vat_amount']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($calc['special_total'] > 0): ?>
                                        <div style="font-size:10px;color:#b45309;font-weight:600;margin-top:3px;border-top:1px dashed #fcd34d;padding-top:3px;">
                                            Special Charges: ₱<?php echo formatCurrency($calc['special_total']); ?>
                                        </div>
                                        <?php foreach ($calc['special_items'] as $item): ?>
                                        <div style="font-size:10px;color:#b45309;font-weight:400;padding-left:8px;">
                                            • <?php echo htmlspecialchars($item['kind']); ?>: ₱<?php echo formatCurrency($item['amount']); ?>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo getStatusBadgeClass($so['status']); ?>">
                                            <?php echo htmlspecialchars($so['status'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($so['created_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="table-footer">
                        <span>Showing <?php echo count($sales_orders); ?> record(s)</span>
                        <span style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                            <?php if ($grand_vat_sum > 0): ?>
                            <span>Total VAT: <strong>₱<?php echo formatCurrency($grand_vat_sum); ?></strong></span>
                            <?php endif; ?>
                            <?php if ($grand_charges_sum > 0): ?>
                            <span style="color:#b45309;">Total Special Charges: <strong>₱<?php echo formatCurrency($grand_charges_sum); ?></strong></span>
                            <?php endif; ?>
                            <span>Grand Total: <strong>₱<?php echo formatCurrency($grand_total_sum); ?></strong></span>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i data-lucide="file-search"></i>
                        <h3>No Sales Orders Found</h3>
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

        // ============================================================
        // Detail Modal Functions
        // ============================================================
        function showDetail(so) {
            const modal = document.getElementById('detailModal');
            const orderNo = document.getElementById('detailOrderNo');
            const status = document.getElementById('detailStatus');
            const content = document.getElementById('detailContent');

            // Set header
            orderNo.textContent = so.sales_order_no;
            
            // Set status badge
            status.className = 'badge-status ' + getStatusBadgeClass(so.status);
            status.textContent = so.status || 'N/A';

            // Computed amounts (from PHP, or recompute)
            const calc = so.computed || {
                gross_subtotal:  parseFloat(so.amount || 0) + parseFloat(so.discount_amount || 0),
                net_amount:      parseFloat(so.amount || 0),
                discount_amount: parseFloat(so.discount_amount || 0),
                vat_percent:     parseFloat(so.vat_percent || 0),
                vat_amount:      parseFloat(so.amount || 0) * (parseFloat(so.vat_percent || 0) / 100),
                special_total:   0,
                grand_total:     parseFloat(so.amount || 0) + (parseFloat(so.amount || 0) * (parseFloat(so.vat_percent || 0) / 100)),
                special_items:   []
            };

            // Build detail grid
            const fields = [
                // Basic Info
                { label: 'Customer Code', value: so.customer_code || 'N/A', full: false, section: 'basic' },
                { label: 'Customer Name', value: so.customer_name || 'N/A', full: false, section: 'basic' },
                { label: 'Order Date', value: formatDate(so.order_date), full: false, section: 'basic' },
                { label: 'Delivery Date', value: so.delivery_date ? formatDate(so.delivery_date) : 'N/A', full: false, section: 'basic' },
                { label: 'Payment Terms', value: so.payment_terms || 'N/A', full: false, section: 'basic' },
                { label: 'VAT %', value: so.vat_percent ? so.vat_percent + '%' : '0%', full: false, section: 'basic' },
                
                // Vehicle Details
                { label: 'Truck Code', value: so.truck_code || 'N/A', full: false, section: 'vehicle' },
                { label: 'Plate Number', value: so.plate_number || 'N/A', full: false, section: 'vehicle' },
                { label: 'Brand', value: so.brand || 'N/A', full: false, section: 'vehicle' },
                { label: 'Model', value: so.model || 'N/A', full: false, section: 'vehicle' },
                { label: 'Unit', value: so.unit || 'N/A', full: false, section: 'vehicle' },
                { label: 'Driver', value: so.driver || 'N/A', full: false, section: 'vehicle' },
                
                // Route & Delivery
                { label: 'Destination From', value: so.destination_from || 'N/A', full: true, section: 'destination' },
                { label: 'Destination To', value: so.destination_to || 'N/A', full: true, section: 'destination' },
                { label: 'Delivery Address', value: so.delivery_address || 'N/A', full: true, section: 'destination' },
                
                // Financial — now includes VAT & special charges
                { label: 'Quantity', value: so.quantity || '0', full: false, section: 'financial' },
                { label: 'Unit Price', value: '₱' + formatCurrency(so.unit_price), full: false, section: 'financial' },
                { label: 'Subtotal (before discount)', value: '₱' + formatCurrency(calc.gross_subtotal), full: false, section: 'financial' },
                { label: 'Discount %', value: so.discount_percent ? so.discount_percent + '%' : '0%', full: false, section: 'financial' },
                { label: 'Discount Amount', value: '₱' + formatCurrency(calc.discount_amount), full: false, section: 'financial' },
                { label: 'Net Amount', value: '₱' + formatCurrency(calc.net_amount), full: false, section: 'financial' },
                { label: 'VAT (' + (calc.vat_percent || 0) + '%)', value: '₱' + formatCurrency(calc.vat_amount), full: false, section: 'financial' },
                { label: 'Special Charges', value: calc.special_total > 0 ? '₱' + formatCurrency(calc.special_total) : '₱0.00', full: false, section: 'financial', amount: true, highlight: calc.special_total > 0 },
                { label: 'TOTAL AMOUNT DUE', value: '₱' + formatCurrency(calc.grand_total), full: false, section: 'financial', amount: true, bold: true },
            ];

            // Special charge breakdown (if any)
            if (calc.special_items && calc.special_items.length > 0) {
                fields.push({ label: '— Special Charges Breakdown —', value: '', full: true, section: 'financial', divider: true });
                calc.special_items.forEach(item => {
                    fields.push({
                        label: '• ' + item.kind,
                        value: '₱' + formatCurrency(item.amount),
                        full: false,
                        section: 'financial',
                        amount: true,
                        subItem: true
                    });
                });
            }

            fields.push(
                // Additional
                { label: 'Notes', value: so.notes || 'N/A', full: true, section: 'notes' },
                { label: 'Created By', value: so.created_by || 'N/A', full: false, section: 'audit' },
                { label: 'Created Date', value: formatDateTime(so.created_date), full: false, section: 'audit' },
                { label: 'Updated By', value: so.updated_by || 'N/A', full: false, section: 'audit' },
                { label: 'Updated At', value: so.updated_at ? formatDateTime(so.updated_at) : 'N/A', full: false, section: 'audit' }
            );

            // Section definitions
            const sections = {
                basic:       { title: 'Basic Information',     order: 1 },
                vehicle:     { title: 'Vehicle Details',       order: 2 },
                destination: { title: 'Route & Delivery',      order: 3 },
                financial:   { title: 'Financial Details',     order: 4 },
                notes:       { title: 'Additional Notes',      order: 5 },
                audit:       { title: 'Audit Information',     order: 6 }
            };

            // Group fields by section
            const grouped = {};
            fields.forEach(f => {
                if (!grouped[f.section]) grouped[f.section] = [];
                grouped[f.section].push(f);
            });

            // Build HTML
            let html = '';
            Object.keys(sections).forEach(sectionKey => {
                if (grouped[sectionKey] && grouped[sectionKey].length > 0) {
                    // Skip if all values are N/A
                    const hasValue = grouped[sectionKey].some(f => f.value !== 'N/A' && f.value !== '');
                    if (hasValue) {
                        html += `<div class="detail-section-title">${sections[sectionKey].title}</div>`;
                        grouped[sectionKey].forEach(f => {
                            // Handle divider
                            if (f.divider) {
                                html += `<div class="detail-divider" style="grid-column:1/-1;margin:10px 0 6px;border-top:1px dashed #e2e8f0;padding-top:6px;font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px;">${f.label}</div>`;
                                return;
                            }
                            const fullClass  = f.full ? 'full-width' : '';
                            const amountClass = f.amount ? ' amount' : '';
                            const boldStyle = f.bold ? ' style="font-weight:700;color:#16a34a;font-size:15px;"' : '';
                            const highlightStyle = f.highlight ? ' style="color:#b45309;font-weight:600;"' : '';
                            const subItemStyle = f.subItem ? ' style="padding-left:20px;font-size:12px;color:#b45309;"' : '';
                            html += `
                                <div class="detail-item ${fullClass}"${subItemStyle}>
                                    <span class="label">${f.label}</span>
                                    <span class="value${amountClass}"${boldStyle}${highlightStyle}>${f.value}</span>
                                </div>
                            `;
                        });
                    }
                }
            });

            content.innerHTML = html;
            modal.classList.add('active');
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
            if (s.includes('completed') || s.includes('delivered') || s.includes('approved')) return 'badge-success';
            if (s.includes('pending') || s.includes('draft')) return 'badge-warning';
            if (s.includes('cancelled') || s.includes('canceled') || s.includes('void')) return 'badge-danger';
            if (s.includes('processing') || s.includes('in progress')) return 'badge-info';
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

        function formatCurrency(amount) {
            if (amount === null || amount === undefined || isNaN(amount)) return '0.00';
            return Number(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
    </script>
</body>
</html>