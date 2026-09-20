<?php
// service_invoice_list_all.php
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
$payment_status_filter = isset($_GET['payment_status']) ? trim($_GET['payment_status']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'invoice_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query
$query = "SELECT * FROM service_invoice WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (invoice_no LIKE ? OR customer_name LIKE ? OR customer_code LIKE ? OR sales_order_no LIKE ? OR truck_code LIKE ? OR plate_number LIKE ?)";
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

if (!empty($payment_status_filter)) {
    $query .= " AND payment_status = ?";
    $params[] = $payment_status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND invoice_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND invoice_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['invoice_no', 'customer_name', 'invoice_date', 'status', 'payment_status', 'total_amount', 'created_date'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'invoice_date';
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

$service_invoices = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $service_invoices[] = $row;
    }
}

// ============================================================
// FETCH PER-INVOICE AGGREGATES (charge_amount, vat, discount, subtotal, balance)
// Because one invoice can have multiple SOs, we sum across the rows
// ============================================================
$invoice_aggregates = []; // keyed by invoice_no

if (!empty($service_invoices)) {
    // Group rows by invoice_no and aggregate
    foreach ($service_invoices as $row) {
        $inv_no = $row['invoice_no'];
        if (!isset($invoice_aggregates[$inv_no])) {
            $invoice_aggregates[$inv_no] = [
                'item_count'      => 0,
                'subtotal'        => 0,   // sum of `amount` (net) across rows
                'discount_total'  => 0,
                'vat_total'       => 0,
                'charge_total'    => 0,   // NEW: sum of `charge_amount`
                'total_amount'    => 0,
                'amount_paid'     => floatval($row['amount_paid'] ?? 0),
                'additional_fee'  => floatval($row['additional_fee'] ?? 0),
                'payment_status'  => $row['payment_status'] ?? '',
                'due_date'        => $row['due_date'] ?? '',
                'sales_orders'    => [],
                'payment_method'  => $row['payment_method'] ?? '',
                'payment_terms'   => $row['payment_terms'] ?? '',
                'customer_code'   => $row['customer_code'] ?? '',
                'customer_name'   => $row['customer_name'] ?? '',
                'invoice_date'    => $row['invoice_date'] ?? '',
                'created_date'    => $row['created_date'] ?? '',
                'created_by'      => $row['created_by'] ?? '',
                'updated_by'      => $row['updated_by'] ?? '',
                'updated_at'      => $row['updated_at'] ?? '',
                'paid_at'         => $row['paid_at'] ?? '',
                'notes'           => $row['notes'] ?? '',
                'delivery_address'=> $row['delivery_address'] ?? '',
            ];
        }
        $invoice_aggregates[$inv_no]['item_count']++;
        $invoice_aggregates[$inv_no]['subtotal']       += floatval($row['amount'] ?? 0);
        $invoice_aggregates[$inv_no]['discount_total'] += floatval($row['discount_amount'] ?? 0);
        $invoice_aggregates[$inv_no]['vat_total']      += floatval($row['vat_amount'] ?? 0);
        $invoice_aggregates[$inv_no]['charge_total']   += floatval($row['charge_amount'] ?? 0); // NEW
        $invoice_aggregates[$inv_no]['total_amount']   += floatval($row['total_amount'] ?? 0);
        if (!empty($row['sales_order_no'])) {
            $invoice_aggregates[$inv_no]['sales_orders'][] = $row['sales_order_no'];
        }
        // Prefer the latest non-empty values (invoice-level fields are the same across rows)
        if (!empty($row['payment_method'])) $invoice_aggregates[$inv_no]['payment_method'] = $row['payment_method'];
        if (!empty($row['payment_terms']))  $invoice_aggregates[$inv_no]['payment_terms']  = $row['payment_terms'];
        if (!empty($row['notes']))          $invoice_aggregates[$inv_no]['notes']          = $row['notes'];
    }
}

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM service_invoice WHERE status IS NOT NULL AND status != '' ORDER BY status";
$status_result = $conn->query($status_query);
$statuses = [];
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[] = $row['status'];
    }
}

// Get unique payment statuses for filter dropdown
$payment_status_query = "SELECT DISTINCT payment_status FROM service_invoice WHERE payment_status IS NOT NULL AND payment_status != '' ORDER BY payment_status";
$payment_status_result = $conn->query($payment_status_query);
$payment_statuses = [];
if ($payment_status_result) {
    while ($row = $payment_status_result->fetch_assoc()) {
        $payment_statuses[] = $row['payment_status'];
    }
}

// Function to get status badge color
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    if (strpos($status, 'completed') !== false || strpos($status, 'paid') !== false || strpos($status, 'approved') !== false) {
        return 'badge-success';
    } elseif (strpos($status, 'pending') !== false || strpos($status, 'draft') !== false) {
        return 'badge-warning';
    } elseif (strpos($status, 'cancelled') !== false || strpos($status, 'canceled') !== false || strpos($status, 'void') !== false) {
        return 'badge-danger';
    } elseif (strpos($status, 'processing') !== false || strpos($status, 'partial') !== false) {
        return 'badge-info';
    } else {
        return 'badge-secondary';
    }
}

function getPaymentStatusBadgeClass($status) {
    $status = strtolower($status);
    if (strpos($status, 'paid') !== false || strpos($status, 'completed') !== false) {
        return 'badge-success';
    } elseif (strpos($status, 'pending') !== false || strpos($status, 'due') !== false) {
        return 'badge-warning';
    } elseif (strpos($status, 'partial') !== false) {
        return 'badge-info';
    } elseif (strpos($status, 'overdue') !== false) {
        return 'badge-danger';
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
    <title>Service Invoice List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/si_list_all.css?v=<?= time(); ?>">
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
                <h2 id="detailInvoiceNo">Service Invoice</h2>
                <div class="header-badges">
                    <span class="badge-status" id="detailStatus">Status</span>
                    <span class="badge-status" id="detailPaymentStatus">Payment</span>
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
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Service Invoice List</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <div class="page-header">
    <h1>📄 Service Invoices</h1>
    <div class="header-actions">
        <!-- EXPORT BUTTON - Added here -->
        <a href="export_service_invoice_list.php<?php 
            $params = [];
            if ($search) $params[] = 'search=' . urlencode($search);
            if ($status_filter) $params[] = 'status=' . urlencode($status_filter);
            if ($payment_status_filter) $params[] = 'payment_status=' . urlencode($payment_status_filter);
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
                    <input type="text" name="search" placeholder="Search Invoice #, Customer, SO #, Truck, Plate..." value="<?php echo htmlspecialchars($search); ?>">
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
                    <label>Payment:</label>
                    <select name="payment_status">
                        <option value="">All Payment</option>
                        <?php foreach ($payment_statuses as $pstatus): ?>
                            <option value="<?php echo htmlspecialchars($pstatus); ?>" <?php echo $payment_status_filter == $pstatus ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pstatus); ?>
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
                <a href="service_invoice_list_all.php" class="btn btn-outline btn-sm">Clear</a>
            </form>

            <!-- Table -->
            <div class="table-container">
                <?php if (count($service_invoices) > 0): ?>
                    <?php
                    // Pre-compute totals for the footer
                    $grand_total_sum   = 0;
                    $grand_vat_sum     = 0;
                    $grand_disc_sum    = 0;
                    $grand_charge_sum  = 0;
                    $grand_paid_sum    = 0;
                    ?>
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'invoice_no', 'sort_order' => $sort_by == 'invoice_no' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Invoice #
                                        <?php if ($sort_by == 'invoice_no'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>SO #</th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'customer_name', 'sort_order' => $sort_by == 'customer_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Customer
                                        <?php if ($sort_by == 'customer_name'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Truck</th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'invoice_date', 'sort_order' => $sort_by == 'invoice_date' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Invoice Date
                                        <?php if ($sort_by == 'invoice_date'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>Qty</th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'total_amount', 'sort_order' => $sort_by == 'total_amount' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Total
                                        <?php if ($sort_by == 'total_amount'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'payment_status', 'sort_order' => $sort_by == 'payment_status' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>">
                                        Payment
                                        <?php if ($sort_by == 'payment_status'): ?>
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
                            <?php foreach ($service_invoices as $si): 
                                $agg = $invoice_aggregates[$si['invoice_no']] ?? null;
                                if (!$agg) continue;

                                $grand_total_sum   += $agg['total_amount'];
                                $grand_vat_sum     += $agg['vat_total'];
                                $grand_disc_sum    += $agg['discount_total'];
                                $grand_charge_sum  += $agg['charge_total'];
                                $grand_paid_sum    += $agg['amount_paid'];

                                // Build SO list display (if multiple SOs on one invoice)
                                $so_list_display = !empty($agg['sales_orders']) ? implode(', ', $agg['sales_orders']) : 'N/A';
                                $so_count = count($agg['sales_orders']);
                            ?>
                                <tr ondblclick='showDetail(<?php echo htmlspecialchars(json_encode(array_merge($si, [
                                    "aggregated" => $agg
                                ])), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <td>
                                        <strong><?php echo htmlspecialchars($si['invoice_no']); ?></strong>
                                        <?php if ($agg['item_count'] > 1): ?>
                                        <div style="font-size:10px;color:#64748b;font-weight:400;">
                                            <?php echo $agg['item_count']; ?> items
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:11px;">
                                        <?php if ($so_count > 1): ?>
                                            <span title="<?php echo htmlspecialchars($so_list_display); ?>" style="cursor:help;">
                                                <?php echo htmlspecialchars($agg['sales_orders'][0]); ?>
                                                <span style="color:#94a3b8;">+<?php echo ($so_count - 1); ?> more</span>
                                            </span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($so_list_display); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($si['customer_name']); ?>">
                                            <?php echo htmlspecialchars($si['customer_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($si['truck_code'] . ' - ' . $si['plate_number']); ?>">
                                            <?php echo htmlspecialchars($si['truck_code'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($si['invoice_date'])); ?></td>
                                    <td><?php echo number_format($si['quantity']); ?></td>
                                    <td class="amount">
                                        <div style="font-weight:600;">
                                            ₱<?php echo formatCurrency($agg['total_amount']); ?>
                                        </div>
                                        <?php if ($agg['discount_total'] > 0): ?>
                                        <div style="font-size:11px;color:#64748b;font-weight:400;">
                                            Disc: ₱<?php echo formatCurrency($agg['discount_total']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($agg['vat_total'] > 0): ?>
                                        <div style="font-size:10px;color:#94a3b8;">
                                            VAT: ₱<?php echo formatCurrency($agg['vat_total']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($agg['charge_total'] > 0): ?>
                                        <div style="font-size:10px;color:#b45309;font-weight:600;margin-top:3px;border-top:1px dashed #fcd34d;padding-top:3px;">
                                            Charges: ₱<?php echo formatCurrency($agg['charge_total']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($agg['amount_paid'] > 0): ?>
                                        <div style="font-size:10px;color:#16a34a;">
                                            Paid: ₱<?php echo formatCurrency($agg['amount_paid']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo getPaymentStatusBadgeClass($si['payment_status']); ?>">
                                            <?php echo htmlspecialchars($si['payment_status'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo getStatusBadgeClass($si['status']); ?>">
                                            <?php echo htmlspecialchars($si['status'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($si['created_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="table-footer">
                        <span>Showing <?php echo count($service_invoices); ?> record(s)</span>
                        <span style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                            <?php if ($grand_disc_sum > 0): ?>
                            <span style="color:#dc2626;">Total Discount: <strong>₱<?php echo formatCurrency($grand_disc_sum); ?></strong></span>
                            <?php endif; ?>
                            <?php if ($grand_vat_sum > 0): ?>
                            <span>Total VAT: <strong>₱<?php echo formatCurrency($grand_vat_sum); ?></strong></span>
                            <?php endif; ?>
                            <?php if ($grand_charge_sum > 0): ?>
                            <span style="color:#b45309;">Total Special Charges: <strong>₱<?php echo formatCurrency($grand_charge_sum); ?></strong></span>
                            <?php endif; ?>
                            <?php if ($grand_paid_sum > 0): ?>
                            <span style="color:#16a34a;">Total Paid: <strong>₱<?php echo formatCurrency($grand_paid_sum); ?></strong></span>
                            <?php endif; ?>
                            <span>Grand Total: <strong>₱<?php echo formatCurrency($grand_total_sum); ?></strong></span>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i data-lucide="file-search"></i>
                        <h3>No Service Invoices Found</h3>
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
        function showDetail(si) {
            const modal = document.getElementById('detailModal');
            const invoiceNo = document.getElementById('detailInvoiceNo');
            const status = document.getElementById('detailStatus');
            const paymentStatus = document.getElementById('detailPaymentStatus');
            const content = document.getElementById('detailContent');

            // Set header
            invoiceNo.textContent = si.invoice_no;
            
            // Set status badges
            status.className = 'badge-status ' + getStatusBadgeClass(si.status);
            status.textContent = si.status || 'N/A';
            
            paymentStatus.className = 'badge-status ' + getPaymentStatusBadgeClass(si.payment_status);
            paymentStatus.textContent = si.payment_status || 'N/A';

            // Aggregated totals (from PHP)
            const agg = si.aggregated || {
                item_count: 1,
                subtotal: parseFloat(si.amount || 0),
                discount_total: parseFloat(si.discount_amount || 0),
                vat_total: parseFloat(si.vat_amount || 0),
                charge_total: parseFloat(si.charge_amount || 0),
                total_amount: parseFloat(si.total_amount || 0),
                amount_paid: parseFloat(si.amount_paid || 0),
                additional_fee: parseFloat(si.additional_fee || 0),
                sales_orders: si.sales_order_no ? [si.sales_order_no] : []
            };

            // Balance
            const balance = agg.total_amount - agg.amount_paid + agg.additional_fee;

            // Build detail grid
            const fields = [
                // Basic Info
                { label: 'Sales Order #', value: agg.sales_orders.length > 0 ? agg.sales_orders.join(', ') : 'N/A', full: true, section: 'basic' },
                { label: 'Customer Code', value: si.customer_code || 'N/A', full: false, section: 'basic' },
                { label: 'Customer Name', value: si.customer_name || 'N/A', full: false, section: 'basic' },
                { label: 'Order Date', value: formatDate(si.order_date), full: false, section: 'basic' },
                { label: 'Delivery Date', value: si.delivery_date ? formatDate(si.delivery_date) : 'N/A', full: false, section: 'basic' },
                { label: 'Invoice Date', value: formatDate(si.invoice_date), full: false, section: 'basic' },
                { label: 'Due Date', value: si.due_date ? formatDate(si.due_date) : 'N/A', full: false, section: 'basic' },
                { label: 'Payment Terms', value: si.payment_terms || 'N/A', full: false, section: 'basic' },
                
                // Vehicle Details
                { label: 'Truck Code', value: si.truck_code || 'N/A', full: false, section: 'vehicle' },
                { label: 'Plate Number', value: si.plate_number || 'N/A', full: false, section: 'vehicle' },
                { label: 'Brand', value: si.brand || 'N/A', full: false, section: 'vehicle' },
                { label: 'Model', value: si.model || 'N/A', full: false, section: 'vehicle' },
                { label: 'Unit', value: si.unit || 'N/A', full: false, section: 'vehicle' },
                
                // Destination
                { label: 'Destination From', value: si.destination_from || 'N/A', full: true, section: 'destination' },
                { label: 'Destination To', value: si.destination_to || 'N/A', full: true, section: 'destination' },
                { label: 'Delivery Address', value: si.delivery_address || 'N/A', full: true, section: 'destination' },
                
                // Financial — aggregated across all SOs in this invoice
                { label: 'Total Quantity', value: formatNumber(si.quantity), full: false, section: 'financial' },
                { label: 'Subtotal (Net)', value: '₱' + formatCurrency(agg.subtotal), full: false, section: 'financial' },
                { label: 'Discount', value: '₱' + formatCurrency(agg.discount_total), full: false, section: 'financial', highlight: agg.discount_total > 0 },
                { label: 'VAT Amount', value: '₱' + formatCurrency(agg.vat_total), full: false, section: 'financial' },
                { label: 'Special Charges', value: '₱' + formatCurrency(agg.charge_total), full: false, section: 'financial', highlight: agg.charge_total > 0 },
                { label: 'Total Amount', value: '₱' + formatCurrency(agg.total_amount), full: false, section: 'financial', bold: true },
                { label: 'Amount Paid', value: '₱' + formatCurrency(agg.amount_paid), full: false, section: 'financial', green: agg.amount_paid > 0 },
                { label: 'Additional Fee', value: '₱' + formatCurrency(agg.additional_fee), full: false, section: 'financial', red: agg.additional_fee > 0 },
                { label: 'Balance Due', value: '₱' + formatCurrency(Math.max(0, balance)), full: false, section: 'financial', red: balance > 0, bold: balance > 0 },
                
                // Payment
                { label: 'Payment Method', value: si.payment_method || 'N/A', full: false, section: 'payment' },
                { label: 'Paid At', value: si.paid_at ? formatDate(si.paid_at) : 'N/A', full: false, section: 'payment' },
                
                // Additional
                { label: 'Notes', value: si.notes || 'N/A', full: true, section: 'notes' },
                { label: 'Created By', value: si.created_by || 'N/A', full: false, section: 'audit' },
                { label: 'Created Date', value: formatDateTime(si.created_date), full: false, section: 'audit' },
                { label: 'Updated By', value: si.updated_by || 'N/A', full: false, section: 'audit' },
                { label: 'Updated At', value: si.updated_at ? formatDateTime(si.updated_at) : 'N/A', full: false, section: 'audit' }
            ];

            // Section definitions
            const sections = {
                basic:       { title: 'Basic Information',   order: 1 },
                vehicle:     { title: 'Vehicle Details',     order: 2 },
                destination: { title: 'Route & Delivery',    order: 3 },
                financial:   { title: 'Financial Details',   order: 4 },
                payment:     { title: 'Payment Details',     order: 5 },
                notes:       { title: 'Additional Notes',    order: 6 },
                audit:       { title: 'Audit Information',   order: 7 }
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
                            const fullClass  = f.full ? 'full-width' : '';
                            const amountClass = (f.label.includes('Amount') || f.label.includes('Total') || f.label.includes('Price') || f.label.includes('Fee') || f.label.includes('Discount') || f.label.includes('VAT') || f.label.includes('Charges') || f.label.includes('Balance')) ? ' amount' : '';
                            
                            let styleOverride = '';
                            if (f.bold) styleOverride = 'font-weight:700;';
                            if (f.green) styleOverride += 'color:#16a34a;';
                            if (f.red) styleOverride += 'color:#dc2626;font-weight:600;';
                            if (f.highlight) styleOverride += 'color:#b45309;font-weight:600;';
                            
                            html += `
                                <div class="detail-item ${fullClass}">
                                    <span class="label">${f.label}</span>
                                    <span class="value${amountClass}"${styleOverride ? ' style="' + styleOverride + '"' : ''}>${f.value}</span>
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
            if (s.includes('completed') || s.includes('paid') || s.includes('approved')) return 'badge-success';
            if (s.includes('pending') || s.includes('draft')) return 'badge-warning';
            if (s.includes('cancelled') || s.includes('canceled') || s.includes('void')) return 'badge-danger';
            if (s.includes('processing') || s.includes('partial')) return 'badge-info';
            return 'badge-secondary';
        }

        function getPaymentStatusBadgeClass(status) {
            const s = (status || '').toLowerCase();
            if (s.includes('paid') || s.includes('completed')) return 'badge-success';
            if (s.includes('pending') || s.includes('due')) return 'badge-warning';
            if (s.includes('partial')) return 'badge-info';
            if (s.includes('overdue')) return 'badge-danger';
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
            if (num === null || num === undefined || isNaN(num)) return '0';
            return Number(num).toLocaleString();
        }

        function formatCurrency(amount) {
            if (amount === null || amount === undefined || isNaN(amount)) return '0.00';
            return Number(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
    </script>
</body>
</html>