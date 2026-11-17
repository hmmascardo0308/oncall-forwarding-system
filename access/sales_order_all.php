<?php
// sales_order_all.php
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

$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);
$base_user_type = $is_admin ? 'admin' : 'user';

$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages);

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'order_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// ============================================================
// DEFAULT DATE FILTERS: current month (1st of month -> today)
// ============================================================
// - First visit (no query string)          -> default to current month
// - "Clear" clicked (?clear=1)             -> reset to current month
// - Any other submission                   -> use submitted values
if (isset($_GET['clear']) || empty($_GET)) {
    $date_from = date('Y-m-01');   // first day of current month
    $date_to   = date('Y-m-d');    // today
} else {
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
}

$query = "SELECT * FROM sales_order WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (sales_order_no LIKE ? OR customer_name LIKE ? OR customer_code LIKE ? OR truck_code LIKE ? OR plate_number LIKE ? OR driver LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= "ssssss";
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND created_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    // Include the whole "to" day even if created_date is DATETIME
    $query .= " AND created_date < ?";
    $params[] = date('Y-m-d', strtotime($date_to . ' +1 day'));
    $types .= "s";
}

$allowed_sort = ['sales_order_no', 'customer_name', 'order_date', 'delivery_date', 'status', 'amount', 'created_date'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'order_date';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$query .= " ORDER BY $sort_by $sort_order";

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
// FETCH SPECIAL CHARGES (include charge_vat)
// ============================================================
$special_charges_map = [];

if (!empty($sales_orders)) {
    $so_numbers = array_column($sales_orders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $charge_query = "SELECT sales_order_no, charge_kind, charge_amount, charge_vat 
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
                    'amount' => floatval($row['charge_amount']),
                    'vat'    => ($row['charge_vat'] !== null && $row['charge_vat'] !== '') ? floatval($row['charge_vat']) : 0.0
                ];
            }
        }
    }
}

function computeSOAmounts($so, $special_charges_map) {
    $so_no = $so['sales_order_no'] ?? '';

    $net_amount      = floatval($so['amount'] ?? 0);
    $discount_amount = floatval($so['discount_amount'] ?? 0);
    $vat_percent     = floatval($so['vat_percent'] ?? 0);

    $gross_subtotal = $net_amount + $discount_amount;
    $vat_amount = $net_amount * ($vat_percent / 100);

    $special_total = 0;
    $special_vat_total = 0;
    if (!empty($special_charges_map[$so_no])) {
        foreach ($special_charges_map[$so_no] as $sc) {
            $special_total += floatval($sc['amount'] ?? 0);
            $special_vat_total += floatval($sc['vat'] ?? 0);
        }
    }

    return [
        'gross_subtotal'     => $gross_subtotal,
        'net_amount'         => $net_amount,
        'discount_amount'    => $discount_amount,
        'vat_percent'        => $vat_percent,
        'vat_amount'         => $vat_amount,
        'special_total'      => $special_total,
        'special_vat_total'  => $special_vat_total,
        'grand_total'        => $net_amount + $vat_amount + $special_total + $special_vat_total,
        'special_items'      => $special_charges_map[$so_no] ?? []
    ];
}

$status_query = "SELECT DISTINCT status FROM sales_order WHERE status IS NOT NULL AND status != '' ORDER BY status";
$status_result = $conn->query($status_query);
$statuses = [];
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[] = $row['status'];
    }
}

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
    <title>Sales Orders | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/so_list_all.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <style>
        .btn-view {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-view:hover {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
            transform: translateY(-1px);
        }
        .btn-view i {
            width: 16px;
            height: 16px;
        }
        td.actions {
            text-align: center;
            white-space: nowrap;
        }
    </style>
</head>
<body>

    <?php if ($success_msg): ?>
        <div id="flash-message"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <div id="accessModal" class="modal-overlay">
        <div class="access-modal">
            <i data-lucide="shield-off"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access this page.</p>
            <button class="modal-btn" onclick="closeModal()">OK</button>
        </div>
    </div>

    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <a href="reports.php" style="color:red; font-weight:bold; font-size:16px; text-decoration:none;">Reports</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Sales Orders</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <div class="page-header">
                <h1>📦 Sales Orders</h1>
            </div>

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
                <button type="submit" class="btn btn-primary btn-sm" style="background-color: #1d4ed8;">Apply</button>
                <a href="sales_order_all.php?clear=1" class="btn btn-outline btn-sm">Clear</a>
                <a href="sales_order.php" class="btn btn-primary btn-sm" style="background-color: #1d4ed8;">New Sales Order</a>
            </form>

            <div class="table-container">
                <?php if (count($sales_orders) > 0): ?>
                    <?php
                    $grand_total_sum = 0;
                    $grand_vat_sum = 0;
                    $grand_charges_sum = 0;
                    $grand_charges_vat_sum = 0;
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
                                <th style="width:70px; text-align:center;">View</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_orders as $so): 
                                $calc = computeSOAmounts($so, $special_charges_map);
                                $grand_total_sum += $calc['grand_total'];
                                $grand_vat_sum += $calc['vat_amount'];
                                $grand_charges_sum += $calc['special_total'];
                                $grand_charges_vat_sum += $calc['special_vat_total'];
                                $so_no = htmlspecialchars($so['sales_order_no']);
                            ?>
                                <tr>
                                    <td><strong><?php echo $so_no; ?></strong></td>
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
                                    <td class="amount" style="text-align: right;">
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
                                            <?php if (!empty($item['vat'])): ?>
                                            <span style="color:#92400e;">(+VAT ₱<?php echo formatCurrency($item['vat']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                        <?php if ($calc['special_vat_total'] > 0): ?>
                                        <div style="font-size:10px;color:#92400e;font-weight:600;">
                                            Charges VAT: ₱<?php echo formatCurrency($calc['special_vat_total']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo getStatusBadgeClass($so['status']); ?>">
                                            <?php echo htmlspecialchars($so['status'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($so['created_date'])); ?></td>
                                    <td class="actions">
                                        <a href="sales_order_list.php?so=<?php echo urlencode($so['sales_order_no']); ?>" 
                                           class="btn-view" 
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           title="View Sales Order <?php echo $so_no; ?>">
                                            <i data-lucide="eye"></i>
                                        </a>
                                    </td>
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
                            <?php if ($grand_charges_vat_sum > 0): ?>
                            <span style="color:#92400e;">Total Special Charges VAT: <strong>₱<?php echo formatCurrency($grand_charges_vat_sum); ?></strong></span>
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

        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flash-message');
            if (flash) {
                setTimeout(() => { 
                    flash.style.opacity = '0'; 
                    setTimeout(() => flash.remove(), 500); 
                }, 3000);
            }
        });

        const userRoles = <?php echo json_encode($user_roles); ?>;
        const allowedPages = <?php echo json_encode($allowed_pages); ?>;
        const modal = document.getElementById('accessModal');

        function checkAccess(page) {
            if (userRoles.includes('admin')) return true;
            for (let role of userRoles) {
                if (allowedPages[role] && allowedPages[role].includes(page)) return true;
            }
            modal.style.display = 'flex';
            return false;
        }
        function closeModal() { modal.style.display = 'none'; }
        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });
    </script>
</body>
</html>