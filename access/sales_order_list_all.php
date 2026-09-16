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
    <!-- <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>"> -->
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
                            <?php foreach ($sales_orders as $so): ?>
                                <tr ondblclick="showDetail(<?php echo htmlspecialchars(json_encode($so)); ?>)">
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
                                    <td class="amount"><?php echo formatCurrency($so['amount']); ?></td>
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
                        <span>Total Amount: <strong><?php echo formatCurrency(array_sum(array_column($sales_orders, 'amount'))); ?></strong></span>
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

        // Detail Modal Functions
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
                
                // Financial
                { label: 'Quantity', value: so.quantity || '0', full: false, section: 'financial' },
                { label: 'Unit Price', value: formatCurrency(so.unit_price), full: false, section: 'financial' },
                { label: 'Discount %', value: so.discount_percent ? so.discount_percent + '%' : '0%', full: false, section: 'financial' },
                { label: 'Amount', value: formatCurrency(so.amount), full: false, section: 'financial' },
                { label: 'Discount Amount', value: formatCurrency(so.discount_amount), full: false, section: 'financial' },
                
                // Additional
                { label: 'Notes', value: so.notes || 'N/A', full: true, section: 'notes' },
                { label: 'Created By', value: so.created_by || 'N/A', full: false, section: 'audit' },
                { label: 'Created Date', value: formatDateTime(so.created_date), full: false, section: 'audit' },
                { label: 'Updated By', value: so.updated_by || 'N/A', full: false, section: 'audit' },
                { label: 'Updated At', value: so.updated_at ? formatDateTime(so.updated_at) : 'N/A', full: false, section: 'audit' },
            ];

            // Section definitions
            const sections = {
                basic: { title: 'Basic Information', order: 1 },
                vehicle: { title: 'Vehicle Details', order: 2 },
                destination: { title: 'Route & Delivery', order: 3 },
                financial: { title: 'Financial Details', order: 4 },
                notes: { title: 'Additional Notes', order: 5 },
                audit: { title: 'Audit Information', order: 6 }
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
                    // Check if any field in this section has a non-empty value
                    const hasValue = grouped[sectionKey].some(f => f.value !== 'N/A' && f.value !== '');
                    if (hasValue) {
                        html += `<div class="detail-section-title">${sections[sectionKey].title}</div>`;
                        grouped[sectionKey].forEach(f => {
                            const fullClass = f.full ? 'full-width' : '';
                            const amountClass = (f.label.includes('Amount') || f.label.includes('Price') || f.label.includes('Total')) ? ' amount' : '';
                            html += `
                                <div class="detail-item ${fullClass}">
                                    <span class="label">${f.label}</span>
                                    <span class="value${amountClass}">${f.value}</span>
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
            return Number(amount).toFixed(2);
        }
    </script>
</body>
</html>