<?php

// purchase_orders_all.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Check if user has access to purchase order page (admin or purchase_order_maker)
// customer_pricer does NOT have access
$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Purchase Order page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle filter parameters
$filter_supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filter_delivery_status = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$filter_delivery_payment = isset($_GET['delivery_payment']) ? trim($_GET['delivery_payment']) : '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = '';

// Add search condition if search term is provided
if (!empty($search_term)) {
    $where_conditions[] = "(po.po_number LIKE ? OR po.supplier_name LIKE ? OR po.supplier_code LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types .= 'sss';
}

if (!empty($filter_supplier)) {
    $where_conditions[] = "po.supplier_name LIKE ?";
    $params[] = "%$filter_supplier%";
    $types .= 's';
}

if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $where_conditions[] = "po.po_date BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
    $types .= 'ss';
} elseif (!empty($filter_date_from)) {
    $where_conditions[] = "po.po_date >= ?";
    $params[] = $filter_date_from;
    $types .= 's';
} elseif (!empty($filter_date_to)) {
    $where_conditions[] = "po.po_date <= ?";
    $params[] = $filter_date_to;
    $types .= 's';
}

if (!empty($filter_delivery_status)) {
    $where_conditions[] = "po.delivery_status = ?";
    $params[] = $filter_delivery_status;
    $types .= 's';
}

if (!empty($filter_delivery_payment)) {
    $where_conditions[] = "po.delivery_payment = ?";
    $params[] = $filter_delivery_payment;
    $types .= 's';
}

// Build the WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Fetch all purchase orders grouped by PO number with filters
$sql = "SELECT 
            po.po_number, 
            MAX(po.supplier_code) as supplier_code,
            MAX(po.supplier_name) as supplier_name,
            MAX(po.purchase_type) as purchase_type,
            MAX(po.po_date) as po_date,
            MAX(po.expected_delivery) as expected_delivery,
            MAX(po.payment_terms) as payment_terms,
            MAX(po.currency) as currency,
            MAX(po.delivery_address) as delivery_address,
            MAX(po.delivery_mode) as delivery_mode,
            MAX(po.warranty) as warranty,
            MAX(po.remarks) as remarks,
            MAX(po.freight) as freight,
            MAX(po.status) as status,
            MAX(po.created_by) as created_by,
            MAX(po.created_at) as created_at,
            MAX(po.delivery_status) as delivery_status,
            MAX(po.delivery_payment) as delivery_payment,
            MAX(po.delivered_date) as delivered_date,
            MAX(po.received_by) as received_by,
            SUM(po.subtotal) as subtotal,
            SUM(po.total_vat) as total_vat,
            SUM(po.total_amount_paid) as total_amount_paid,
            GROUP_CONCAT(CONCAT(po.item_code, '|', po.item, '|', po.qty_ordered, '|', IFNULL(po.qty_received, 0), '|', po.unit_cost, '|', po.total_amount, '|', IFNULL(po.total_amount_paid, 0)) SEPARATOR '||') as items_detail
        FROM purchase_order po
        $where_clause
        GROUP BY po.po_number 
        ORDER BY MAX(po.created_at) DESC";

// Prepare and execute the query with filters
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$purchase_orders = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items = [];
        $subtotal_amount_paid = 0;
        if ($row['items_detail']) {
            $items_raw = explode('||', $row['items_detail']);
            foreach ($items_raw as $item_raw) {
                $parts = explode('|', $item_raw);
                if (count($parts) >= 7) {
                    $qty_received = floatval($parts[3]);
                    $unit_cost = floatval($parts[4]);
                    $total_amount = floatval($parts[5]);
                    $total_amount_paid = floatval($parts[6]);
                    $qty_ordered = floatval($parts[2]);
                    
                    if ($qty_received >= $qty_ordered) {
                        $amount_paid = $total_amount;
                    } else {
                        $amount_paid = $qty_received * $unit_cost;
                    }
                    $subtotal_amount_paid += $amount_paid;
                    
                    $items[] = [
                        'item_code' => $parts[0],
                        'item_name' => $parts[1],
                        'qty_ordered' => $parts[2],
                        'qty_received' => $parts[3],
                        'unit_cost' => $parts[4],
                        'total_amount' => $parts[5],
                        'total_amount_paid' => $parts[6],
                        'amount_paid' => $amount_paid
                    ];
                }
            }
        }
        $row['items'] = $items;
        $row['total_amount_paid'] = $subtotal_amount_paid;
        $purchase_orders[] = $row;
    }
}

// Get distinct suppliers for the filter dropdown
$supplier_sql = "SELECT DISTINCT supplier_name FROM purchase_order ORDER BY supplier_name";
$supplier_result = $conn->query($supplier_sql);
$suppliers = [];
if ($supplier_result && $supplier_result->num_rows > 0) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row['supplier_name'];
    }
}

// Get status badge functions
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Created':
            return 'status-created';
        case 'Approved':
            return 'status-approved';
        case 'Rejected':
            return 'status-rejected';
        case 'Completed':
            return 'status-completed';
        default:
            return 'status-pending';
    }
}

function getStatusBadgeText($status) {
    switch ($status) {
        case 'Created':
            return 'Created';
        case 'Approved':
            return 'Approved';
        case 'Rejected':
            return 'Rejected';
        case 'Completed':
            return 'Completed';
        default:
            return $status;
    }
}

function getDeliveryStatusBadgeClass($status) {
    switch ($status) {
        case 'Received':
            return 'delivery-received';
        case 'Cancelled':
            return 'delivery-cancelled';
        case 'Late Delivery':
            return 'delivery-late';
        case 'Pending':
            return 'delivery-pending';
        default:
            return 'delivery-pending';
    }
}

function getDeliveryPaymentBadgeClass($payment) {
    switch ($payment) {
        case 'Paid':
            return 'payment-paid';
        case 'Unpaid':
            return 'payment-unpaid';
        case 'Pending':
            return 'payment-pending';
        default:
            return 'payment-pending';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Purchase Orders | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/purchase_order.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/po_all.css?v=<?= time(); ?>">

    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
  
</head>
<body>

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
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <a href="purchase_order.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;"> Purchase Order</a> / <span style="color:red; font-weight: bold; font-size: 16px;">All Purchase Orders</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <h1>
                <span class="icon-wrap"><i data-lucide="list-ordered" style="width:18px;height:18px;"></i></span>
                All Purchase Orders
            </h1>
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by PO #, Supplier..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                <a href="purchase_order.php" class="btn-primary" style="white-space: nowrap;">
                    <i data-lucide="plus" style="width:15px;height:15px;"></i> New Purchase Order
                </a>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-group">
                <label><i data-lucide="building-2" style="width:12px;height:12px;"></i> Supplier</label>
                <select id="filterSupplier" onchange="applyFilters()">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($filter_supplier == $supplier) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label><i data-lucide="calendar" style="width:12px;height:12px;"></i> Date From</label>
                <input type="date" id="filterDateFrom" value="<?php echo htmlspecialchars($filter_date_from); ?>" onchange="applyFilters()">
            </div>
            
            <div class="filter-group">
                <label><i data-lucide="calendar" style="width:12px;height:12px;"></i> Date To</label>
                <input type="date" id="filterDateTo" value="<?php echo htmlspecialchars($filter_date_to); ?>" onchange="applyFilters()">
            </div>
            
            <div class="filter-group">
                <label><i data-lucide="package" style="width:12px;height:12px;"></i> Delivery Status</label>
                <select id="filterDeliveryStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo ($filter_delivery_status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Received" <?php echo ($filter_delivery_status == 'Received') ? 'selected' : ''; ?>>Received</option>
                    <option value="Cancelled" <?php echo ($filter_delivery_status == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="Late Delivery" <?php echo ($filter_delivery_status == 'Late Delivery') ? 'selected' : ''; ?>>Late Delivery</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label><i data-lucide="credit-card" style="width:12px;height:12px;"></i> Payment Status</label>
                <select id="filterPaymentStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo ($filter_delivery_payment == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Paid" <?php echo ($filter_delivery_payment == 'Paid') ? 'selected' : ''; ?>>Paid</option>
                    <option value="Unpaid" <?php echo ($filter_delivery_payment == 'Unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                </select>
            </div>
            
            <div class="filter-actions">
                <button class="btn-filter" onclick="applyFilters()">
                    <i data-lucide="search" style="width:14px;height:14px;"></i> Filter
                </button>
                <button class="btn-clear" onclick="clearFilters()">
                    <i data-lucide="x" style="width:14px;height:14px;"></i> Clear
                </button>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo count($purchase_orders); ?> found)
                <a href="purchase_orders_list.php" style="color: var(--accent-orange); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-card-title">
                <i data-lucide="file-text" style="width:16px;height:16px;"></i> 
                Purchase Orders
                <span class="results-count" style="margin-left: auto; font-weight: 400;">
                    Showing <strong><?php echo count($purchase_orders); ?></strong> result(s)
                </span>
            </div>

            <?php if (count($purchase_orders) > 0): ?>
      <div class="table-wrapper">
            <table id="poTable">
        <thead>
            <tr>
                <th>PO Number</th>
                <th>Supplier</th>
                <th>PO Date</th>
                <th>Expected Delivery</th>
                <th>Total Amount Paid</th>
                <th>Status</th>
                <th>Delivery Status</th>
                <th>Delivery Payment</th>
                <th>Delivered Date</th>
                <th>Received By</th>
                <th>Created By</th>
                <th>Created Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($purchase_orders as $po): ?>
                <tr data-po-number="<?php echo htmlspecialchars($po['po_number']); ?>">
                    <td style="font-weight: 600; color: var(--accent-orange);">
                        <?php echo htmlspecialchars($po['po_number']); ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($po['supplier_name']); ?>
                        <div style="font-size: 11px; color: var(--text-muted);">
                            <?php echo htmlspecialchars($po['supplier_code']); ?>
                        </div>
                    </td>
                    <td><?php echo date('m/d/Y', strtotime($po['po_date'])); ?></td>
                    <td>
                        <?php echo $po['expected_delivery'] ? date('m/d/Y', strtotime($po['expected_delivery'])) : '—'; ?>
                    </td>
                    <td style="font-weight: 600; text-align: right;">
                        ₱ <?php echo number_format($po['total_amount_paid'], 2); ?>
                    </td>
                    <td>
                        <span class="<?php echo getStatusBadgeClass($po['status']); ?>">
                            <?php echo getStatusBadgeText($po['status']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="<?php echo getDeliveryStatusBadgeClass($po['delivery_status']); ?>">
                            <?php echo htmlspecialchars($po['delivery_status'] ?: 'Pending'); ?>
                        </span>
                    </td>
                    <td>
                        <span class="<?php echo getDeliveryPaymentBadgeClass($po['delivery_payment']); ?>">
                            <?php echo htmlspecialchars($po['delivery_payment'] ?: 'Pending'); ?>
                        </span>
                    </td>
                    <td>
                        <?php echo $po['delivered_date'] ? date('m/d/Y', strtotime($po['delivered_date'])) : '—'; ?>
                    </td>
                    <td>
                        <span class="received-by-text">
                            <?php echo htmlspecialchars($po['received_by'] ?: '—'); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($po['created_by']); ?></td>
                    <td><?php echo date('m/d/Y', strtotime($po['created_at'])); ?></td>
                    <td>
                        <a href="purchase_orders_view.php?po_number=<?php echo urlencode($po['po_number']); ?>" 
                           class="btn-secondary" 
                           style="padding: 6px 12px; font-size: 13px; white-space: nowrap;"
                           title="View Purchase Order">
                            <i data-lucide="eye" style="width:14px;height:14px;"></i> View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
            
            <?php else: ?>
                <div class="empty-state">
                    <i data-lucide="shopping-cart"></i>
                    <h3>No Purchase Orders Found</h3>
                    <p>
                        <?php if (!empty($search_term)): ?>
                            No results found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                            <br>
                            <a href="purchase_orders_list.php" style="color: var(--accent-orange); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                View all purchase orders
                            </a>
                        <?php elseif (!empty($filter_supplier) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_delivery_status) || !empty($filter_delivery_payment)): ?>
                            No results found with the current filters.
                            <br>
                            <button onclick="clearFilters()" class="btn-secondary" style="margin-top: 8px;">
                                Clear filters
                            </button>
                        <?php else: ?>
                            No purchase orders found. Create your first purchase order.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($search_term) && empty($filter_supplier) && empty($filter_date_from) && empty($filter_date_to) && empty($filter_delivery_status) && empty($filter_delivery_payment)): ?>
                        <a href="purchase_order.php" class="btn-primary" style="margin-top: 16px; display: inline-flex;">
                            <i data-lucide="plus"></i> Create Purchase Order
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
    lucide.createIcons();

    // User roles from PHP
    const currentSessionUser = '<?php echo $username; ?>';
    const userRoles = <?php echo json_encode($user_roles); ?>;
    
    // Allowed pages from PHP
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    
    // Modal element
    const modal = document.getElementById('accessModal');

    // Check access function
    function checkAccess(page) {
        if (userRoles.includes('admin')) {
            return true;
        }
        
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
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (modal?.style.display === 'flex') closeModal();
        }
    });

    // ── Search Functionality ──────────────────────────────────────
    const searchInput = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearSearch');
    const searchForm = searchInput?.closest('form');

    // Auto-submit on input (with debounce)
    let searchTimeout;
    searchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.trim() === '' && window.location.search.includes('search=')) {
                // If search is cleared, redirect to remove search param
                window.location.href = window.location.pathname;
            } else if (this.value.trim() !== '') {
                searchForm?.submit();
            }
        }, 300);
    });

    // Clear button functionality
    clearBtn?.addEventListener('click', function() {
        searchInput.value = '';
        this.classList.remove('visible');
        window.location.href = window.location.pathname;
    });

    // Show/hide clear button based on input value
    searchInput?.addEventListener('input', function() {
        if (this.value.trim() !== '') {
            clearBtn?.classList.add('visible');
        } else {
            clearBtn?.classList.remove('visible');
        }
    });

    // Submit on Enter key
    searchInput?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (this.value.trim() !== '') {
                searchForm?.submit();
            } else {
                window.location.href = window.location.pathname;
            }
        }
    });

    // Filter functions
    function applyFilters() {
        const supplier = document.getElementById('filterSupplier').value;
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;
        const deliveryStatus = document.getElementById('filterDeliveryStatus').value;
        const paymentStatus = document.getElementById('filterPaymentStatus').value;
        const search = document.getElementById('searchInput')?.value || '';
        
        let url = window.location.pathname + '?';
        if (search) url += 'search=' + encodeURIComponent(search) + '&';
        if (supplier) url += 'supplier=' + encodeURIComponent(supplier) + '&';
        if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
        if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
        if (deliveryStatus) url += 'delivery_status=' + encodeURIComponent(deliveryStatus) + '&';
        if (paymentStatus) url += 'delivery_payment=' + encodeURIComponent(paymentStatus) + '&';
        
        // Remove trailing & or ?
        url = url.replace(/[?&]$/, '');
        
        // If no filters, just go to the page without parameters
        if (url === window.location.pathname + '?') {
            url = window.location.pathname;
        }
        
        window.location.href = url;
    }

    function clearFilters() {
        window.location.href = window.location.pathname;
    }

    // Allow Enter key to trigger filter
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const activeElement = document.activeElement;
            if (activeElement && (activeElement.id === 'filterDateFrom' || activeElement.id === 'filterDateTo')) {
                applyFilters();
            }
        }
    });
</script>
</body>
</html>