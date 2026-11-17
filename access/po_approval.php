<?php
// po_approval.php
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

// Handle status update (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    $po_number = trim($_POST['po_number'] ?? '');
    $new_status = trim($_POST['status'] ?? '');

    if (empty($po_number) || empty($new_status)) {
        echo json_encode(['success' => false, 'message' => 'Missing PO number or status.']);
        exit;
    }

    // Only allow Approved or Rejected
    if (!in_array($new_status, ['Approved', 'Rejected'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid status. Only Approved or Rejected are allowed.']);
        exit;
    }

    // ✅ LOCK CHECK: Once Approved, status can no longer be changed
    $lock_check = $conn->prepare("SELECT status FROM purchase_order WHERE po_number = ? LIMIT 1");
    $lock_check->bind_param("s", $po_number);
    $lock_check->execute();
    $lock_result = $lock_check->get_result()->fetch_assoc();
    $lock_check->close();

    if ($lock_result && strcasecmp(trim($lock_result['status'] ?? ''), 'Approved') === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'This PO is already Approved and can no longer be changed.',
            'locked'  => true
        ]);
        exit;
    }

    // Check if this PO contains fuel items — if so, status is mandatory
    // Use the `item` column (not `item_name`) from purchase_order
    $fuel_check = $conn->prepare("SELECT COUNT(*) AS fuel_count FROM purchase_order WHERE po_number = ? AND item LIKE '%fuel%'");
    $fuel_check->bind_param("s", $po_number);
    $fuel_check->execute();
    $fuel_result = $fuel_check->get_result()->fetch_assoc();
    $fuel_check->close();

    $is_fuel = ($fuel_result['fuel_count'] > 0);

    // Update status, approved_at, approved_by
    $approved_at = date('Y-m-d H:i:s');
    $approved_by = $full_name;
    $stmt = $conn->prepare("UPDATE purchase_order SET status = ?, approved_at = ?, approved_by = ? WHERE po_number = ?");
    $stmt->bind_param("ssss", $new_status, $approved_at, $approved_by, $po_number);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Status updated to ' . $new_status . '.',
            'approved_at' => $approved_at,
            'approved_by' => $approved_by
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$purchase_type_filter = isset($_GET['purchase_type']) ? trim($_GET['purchase_type']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$delivery_status_filter = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$delivery_payment_filter = isset($_GET['delivery_payment']) ? trim($_GET['delivery_payment']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// No default date range — leave blank unless the user provides one
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

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
            po.item,
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
            po.status,
            po.approved_at,
            po.approved_by,
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

if (!empty($status_filter)) {
    $query .= " AND po.status = ?";
    $params[] = $status_filter;
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
$allowed_sort = ['po_date', 'po_number', 'supplier_code', 'item_code', 'purchase_type', 'qty_ordered', 'unit_cost', 'net_amount_due', 'total_amount_paid', 'delivery_status', 'delivery_payment', 'payment_method', 'created_by', 'status'];
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
 * Debit is header-level (same value across item rows of the same PO),
 * so we take it from the first item row of each group.
 */
$grouped_pos = [];
foreach ($purchase_orders as $row) {
    $po_num = $row['po_number'];

    if (!isset($grouped_pos[$po_num])) {
        $grouped_pos[$po_num] = [
            'po_number' => $po_num,
            'items'     => [],
            // Header-level value taken from the first item row
            'debit'     => (float)($row['net_amount_due'] ?? 0),
            'status'    => $row['status'] ?? '',
            'approved_at' => $row['approved_at'] ?? null,
            'approved_by' => $row['approved_by'] ?? null,
            'has_fuel'  => false,
        ];
    }

    // Check if any item in this PO is fuel (use item_name which is aliased)
    if (!empty($row['item_name']) && stripos($row['item_name'], 'fuel') !== false) {
        $grouped_pos[$po_num]['has_fuel'] = true;
    }
    // Also check the raw `item` field in case item_name is null
    if (!empty($row['item']) && stripos($row['item'], 'fuel') !== false) {
        $grouped_pos[$po_num]['has_fuel'] = true;
    }

    $grouped_pos[$po_num]['items'][] = $row;
}

// Pre-compute totals for footer (once per PO, not per item)
$total_debit  = 0;
foreach ($grouped_pos as $group) {
    $total_debit  += $group['debit'];
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

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM purchase_order WHERE status IS NOT NULL AND status != '' ORDER BY status";
$status_result = $conn->query($status_query);
$statuses = [];
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[] = $row['status'];
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
    <title>PO Approval | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/po_list_all.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <style>
        .status-select {
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #ccc;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .status-select:focus {
            outline: none;
            border-color: #2563eb;
        }
        .status-select.approved {
            background: #dcfce7;
            color: #166534;
            font-weight: 600;
            border-color: #86efac;
        }
        .status-select.rejected {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
        }
        .status-select.pending {
            background: #c3f8fe;
            color: #003d76;
            border-color: #aaf7ff;
        }
        /* ✅ Locked (Approved) selects */
        .status-select:disabled {
            opacity: 0.95;
            cursor: not-allowed;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23166534' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='11' width='18' height='11' rx='2' ry='2'/><path d='M7 11V7a5 5 0 0 1 10 0v4'/></svg>");
            background-repeat: no-repeat;
            background-position: right 8px center;
            padding-right: 28px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }
        .fuel-reminder {
            display: inline-block;
            margin-left: 6px;
            padding: 2px 8px;
            background: #fef3c7;
            color: #92400e;
            font-size: 11px;
            border-radius: 4px;
            font-weight: 600;
            border: 1px solid #fcd34d;
        }
        .status-cell {
            text-align: center;
            vertical-align: middle;
            padding: 8px 6px;
        }
        .approved-info {
            display: block;
            font-size: 10px;
            color: #6b7280;
            margin-top: 4px;
            line-height: 1.3;
        }
        .locked-info {
            display: block;
            font-size: 10px;
            color: #166534;
            margin-top: 4px;
            line-height: 1.3;
            font-weight: 600;
        }
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 14px 22px;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            z-index: 99999;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.3s, transform 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 380px;
        }
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }
        .toast.warning { background: #d97706; }
        .fuel-badge {
            display: inline-block;
            background: #dc2626;
            color: #fff;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 4px;
            font-weight: 700;
            vertical-align: middle;
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

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION /  <span style="color:red; font-weight: bold; font-size: 16px;">PO Approval</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <div class="page-header">
                <h1>📋 PO Approval</h1>
            </div>

            <!-- Fuel Reminder Note -->
            <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px;">
                <i data-lucide="alert-triangle" style="color: #d97706; width: 20px; height: 20px; flex-shrink: 0; margin-top: 1px;"></i>
                <div style="font-size: 13px; color: #92400e; line-height: 1.5;">
                    <strong>Reminder:</strong> Setting the status is <strong>mandatory</strong> for items with <strong>"Fuel"</strong> in the item name.
                    <span class="fuel-badge">FUEL</span> badge indicates mandatory status.
                    <br><strong>Note:</strong> Once a PO is <strong>Approved</strong>, its status becomes <strong>permanent and can no longer be changed</strong>.
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
                    <label>Status:</label>
                    <select name="status">
                        <option value="">All</option>
                        <?php foreach ($statuses as $st): ?>
                            <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $status_filter == $st ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($st); ?>
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
                                        Total Amount
                                        <?php if ($sort_by == 'net_amount_due'): ?>
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
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
    <?php foreach ($grouped_pos as $group): ?>
        <?php 
        $items = $group['items'];
        $item_count = count($items);
        $is_first = true;
        $has_fuel = $group['has_fuel'];
        $current_status = $group['status'] ?? '';
        // ✅ Locked when status is "Approved"
        $is_locked = (strcasecmp(trim($current_status), 'Approved') === 0);
        ?>
        <?php foreach ($items as $po): ?>
            <tr>
                <td><?php echo $po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '-'; ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                    <?php if ($has_fuel): ?>
                        <span class="fuel-badge" title="Status is mandatory for fuel items">FUEL</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php 
                    echo htmlspecialchars($po['supplier_name'] ?: ($po['supplier_code'] ?: 'N/A'));
                    ?>
                </td>
                <td>
                    <?php 
                    $item_display = $po['item_name'] ?: ($po['item_code'] ?: 'N/A');
                    echo htmlspecialchars($item_display);
                    if ($has_fuel && stripos($item_display, 'fuel') !== false) {
                        echo ' <span class="fuel-badge">FUEL</span>';
                    }
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
                    <!-- Total Amount: merged across all items of this PO -->
                    <td class="amount" rowspan="<?php echo $item_count; ?>">
                        <?php echo number_format($group['debit'], 2); ?>
                    </td>

                    <!-- Status dropdown: merged across all items of this PO -->
                    <td rowspan="<?php echo $item_count; ?>" class="status-cell">
                        <select class="status-select <?php echo strtolower($current_status) === 'approved' ? 'approved' : (strtolower($current_status) === 'rejected' ? 'rejected' : 'pending'); ?>"
                                onchange="updateStatus('<?php echo htmlspecialchars($po['po_number'], ENT_QUOTES); ?>', this, <?php echo $has_fuel ? 'true' : 'false'; ?>)"
                                data-original="<?php echo htmlspecialchars($current_status, ENT_QUOTES); ?>"
                                data-has-fuel="<?php echo $has_fuel ? '1' : '0'; ?>"
                                data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
                                <?php echo $is_locked ? 'disabled title="Approved POs are locked and cannot be changed."' : ''; ?>>
                            <option value="" <?php echo $current_status === '' ? 'selected' : ''; ?>>-- Select --</option>
                            <option value="Approved" <?php echo $current_status === 'Approved' ? 'selected' : ''; ?>>Approve</option>
                            <option value="Rejected" <?php echo $current_status === 'Rejected' ? 'selected' : ''; ?>>Reject</option>
                        </select>
                        <?php if ($is_locked): ?>
                            <span class="locked-info">🔒 Locked (Approved)</span>
                        <?php endif; ?>
                        <?php if (!empty($group['approved_at']) || !empty($group['approved_by'])): ?>
                            <span class="approved-info">
                                <?php if (!empty($group['approved_by'])): ?>
                                    By: <?php echo htmlspecialchars($group['approved_by']); ?><br>
                                <?php endif; ?>
                                <?php if (!empty($group['approved_at'])): ?>
                                    <?php echo date('M d, Y h:i A', strtotime($group['approved_at'])); ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($has_fuel && empty($current_status)): ?>
                            <span class="fuel-reminder">⚠ Required</span>
                        <?php endif; ?>
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

        // Toast helper
        function showToast(message, type) {
            const existing = document.querySelector('.toast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.innerHTML = '<i data-lucide="' + (type === 'success' ? 'check-circle' : (type === 'error' ? 'x-circle' : 'alert-triangle')) + '" style="width:18px;height:18px;"></i> ' + message;
            document.body.appendChild(toast);
            lucide.createIcons();

            requestAnimationFrame(() => {
                toast.classList.add('show');
            });

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        // Update status via AJAX
        function updateStatus(poNumber, selectEl, hasFuel) {
            const newStatus = selectEl.value;
            const originalStatus = selectEl.dataset.original || '';

            // ✅ Lock: Approved POs cannot be changed
            if (selectEl.dataset.locked === '1' || originalStatus === 'Approved' || selectEl.disabled) {
                showToast('This PO is already Approved and can no longer be changed.', 'warning');
                selectEl.value = 'Approved';
                return;
            }

            // If fuel and status is empty, block it
            if (hasFuel && newStatus === '') {
                showToast('Status is mandatory for fuel items. Please select Approved or Rejected.', 'warning');
                selectEl.value = originalStatus;
                return;
            }

            // If not fuel and status is empty, allow it (optional)
            // If status is the same as original, do nothing
            if (newStatus === originalStatus) {
                return;
            }

            // Disable during request
            selectEl.disabled = true;

            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('po_number', poNumber);
            formData.append('status', newStatus);

            fetch('po_approval.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the select's class and dataset
                    selectEl.classList.remove('approved', 'rejected', 'pending');
                    if (newStatus === 'Approved') {
                        selectEl.classList.add('approved');
                    } else if (newStatus === 'Rejected') {
                        selectEl.classList.add('rejected');
                    } else {
                        selectEl.classList.add('pending');
                    }
                    selectEl.dataset.original = newStatus;

                    // ✅ If newly approved, lock it permanently
                    if (newStatus === 'Approved') {
                        selectEl.dataset.locked = '1';
                        selectEl.disabled = true;
                        selectEl.title = 'Approved POs are locked and cannot be changed.';

                        const statusCell = selectEl.closest('.status-cell');
                        if (statusCell && !statusCell.querySelector('.locked-info')) {
                            const lockedInfo = document.createElement('span');
                            lockedInfo.className = 'locked-info';
                            lockedInfo.textContent = '🔒 Locked (Approved)';
                            // Insert after the select
                            selectEl.insertAdjacentElement('afterend', lockedInfo);
                        }
                    } else {
                        // Re-enable for non-approved statuses
                        selectEl.disabled = false;
                    }

                    // Update approved info
                    const statusCell = selectEl.closest('.status-cell');
                    let approvedInfo = statusCell.querySelector('.approved-info');
                    if (data.approved_at || data.approved_by) {
                        if (!approvedInfo) {
                            approvedInfo = document.createElement('span');
                            approvedInfo.className = 'approved-info';
                            statusCell.appendChild(approvedInfo);
                        }
                        approvedInfo.innerHTML = (data.approved_by ? 'By: ' + data.approved_by + '<br>' : '') +
                                                  (data.approved_at ? data.approved_at : '');
                    } else if (approvedInfo) {
                        approvedInfo.remove();
                    }

                    // Remove fuel reminder if status is now set
                    const reminder = statusCell.querySelector('.fuel-reminder');
                    if (reminder && newStatus !== '') {
                        reminder.remove();
                    }

                    showToast(data.message, 'success');
                } else {
                    // Revert on failure
                    selectEl.disabled = false;
                    selectEl.value = originalStatus;

                    // If server says it's locked, apply lock locally too
                    if (data.locked) {
                        selectEl.dataset.locked = '1';
                        selectEl.disabled = true;
                        selectEl.value = 'Approved';
                        selectEl.classList.remove('approved', 'rejected', 'pending');
                        selectEl.classList.add('approved');
                        selectEl.dataset.original = 'Approved';

                        const statusCell = selectEl.closest('.status-cell');
                        if (statusCell && !statusCell.querySelector('.locked-info')) {
                            const lockedInfo = document.createElement('span');
                            lockedInfo.className = 'locked-info';
                            lockedInfo.textContent = '🔒 Locked (Approved)';
                            selectEl.insertAdjacentElement('afterend', lockedInfo);
                        }
                    }

                    showToast(data.message || 'Failed to update status.', 'error');
                }
            })
            .catch(error => {
                selectEl.disabled = false;
                selectEl.value = originalStatus;
                showToast('An error occurred. Please try again.', 'error');
                console.error('Error:', error);
            });
        }

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