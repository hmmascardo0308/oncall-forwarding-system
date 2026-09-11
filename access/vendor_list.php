<?php
// vendor_list.php
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
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'supplier_name';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'ASC';

// Build query for suppliers
$query = "SELECT * FROM supplier_lists WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (supplier_code LIKE ? OR supplier_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone_number LIKE ? OR tin LIKE ?)";
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

// Add sorting
$allowed_sort = ['supplier_code', 'supplier_name', 'status', 'contact_person', 'payment_terms', 'vat_type', 'tin'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'supplier_name';
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

$suppliers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM supplier_lists WHERE status IS NOT NULL AND status != '' ORDER BY status";
$status_result = $conn->query($status_query);
$statuses = [];
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[] = $row['status'];
    }
}

// Function to calculate supplier balance from purchase orders
function getSupplierBalance($conn, $supplier_code) {
    // Calculate total amount due for this supplier
    // Only include POs where:
    // - delivery_status = 'Received' OR delivery_status = 'Delivered'
    // - delivery_payment = 'Pending' OR delivery_payment = 'Partial'
    // - status = 'Created' OR status = 'Approved'
    $query = "
        SELECT 
            SUM(net_amount_due) AS total_balance
        FROM purchase_order
        WHERE supplier_code = ?
        AND delivery_status IN ('Received', 'Delivered')
        AND delivery_payment IN ('Pending', 'Partial')
        AND status IN ('Created', 'Approved')
    ";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $supplier_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return floatval($row['total_balance'] ?? 0);
        }
    }
    return 0;
}

// Function to get status badge color
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    if (strpos($status, 'active') !== false) {
        return 'badge-success';
    } elseif (strpos($status, 'inactive') !== false) {
        return 'badge-danger';
    } elseif (strpos($status, 'pending') !== false || strpos($status, 'review') !== false) {
        return 'badge-warning';
    } else {
        return 'badge-secondary';
    }
}

// Function to get supplier type badge color
function getSupplierTypeBadgeClass($type) {
    $type = strtolower($type);
    if (strpos($type, 'regular') !== false || strpos($type, 'preferred') !== false) {
        return 'badge-success';
    } elseif (strpos($type, 'temporary') !== false || strpos($type, 'trial') !== false) {
        return 'badge-warning';
    } elseif (strpos($type, 'blocked') !== false || strpos($type, 'suspended') !== false) {
        return 'badge-danger';
    } else {
        return 'badge-info';
    }
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
    <title>Vendor List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="css/vendor_list.css?v=<?= time(); ?>">
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
                <h2 id="detailSupplierName">Vendor Details</h2>
                <div class="header-badges">
                    <span class="badge-status" id="detailStatus">Status</span>
                    <span class="badge-status" id="detailType">Type</span>
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
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Vendor List</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <div class="page-header">
                <h1>Vendor List</h1>
                <div class="header-actions">
                    <!-- View only - no action buttons -->
                </div>
            </div>

            <!-- Filters -->
            <!-- Filters -->
<form method="GET" class="filters-bar" id="filterForm">
    <div class="search-wrapper">
        <i data-lucide="search" style="width:18px;height:18px;color:var(--text-muted);"></i>
        <input type="text" name="search" placeholder="Search Code, Name, Contact, Email, Phone, TIN..." value="<?php echo htmlspecialchars($search); ?>">
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

    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <a href="vendor_list.php" class="btn btn-outline btn-sm">Clear</a>
    
    <!-- Export Button -->
    <a href="export_vendor_list.php?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export' => '1']))); ?>" 
       class="btn btn-success btn-sm" style="margin-left: auto; background: #28a745; color: white; padding: 6px 14px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
        <i data-lucide="file-spreadsheet" style="width:16px;height:16px;"></i>
        Export to Excel
    </a>
</form>

            <!-- Table -->
            <div class="table-container">
                <?php if (count($suppliers) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'supplier_code', 'sort_order' => $sort_by == 'supplier_code' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>" style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Code
                                        <?php if ($sort_by == 'supplier_code'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'supplier_name', 'sort_order' => $sort_by == 'supplier_name' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>"  style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Supplier Name
                                        <?php if ($sort_by == 'supplier_name'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'payment_terms', 'sort_order' => $sort_by == 'payment_terms' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>"  style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Terms
                                        <?php if ($sort_by == 'payment_terms'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'vat_type', 'sort_order' => $sort_by == 'vat_type' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>"  style="color: #000000; font-weight: 900; font-size: 13px;">
                                        VAT Type
                                        <?php if ($sort_by == 'vat_type'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'tin', 'sort_order' => $sort_by == 'tin' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>"  style="color: #000000; font-weight: 900; font-size: 13px;">
                                        TIN
                                        <?php if ($sort_by == 'tin'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'contact_person', 'sort_order' => $sort_by == 'contact_person' && $sort_order == 'ASC' ? 'DESC' : 'ASC'])); ?>"  style="color: #000000; font-weight: 900; font-size: 13px;">
                                        Contact Person
                                        <?php if ($sort_by == 'contact_person'): ?>
                                            <i data-lucide="<?php echo $sort_order == 'ASC' ? 'chevron-up' : 'chevron-down'; ?>" style="width:14px;height:14px;"></i>
                                        <?php endif; ?>
                                    </a>
                                </th>
                                <th  style="color: #000000; font-weight: 900; font-size: 13px;">Email</th>
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Phone</th>
                                <th style="color: #000000; font-weight: 900; font-size: 13px;">Balance</th>
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
                            <?php foreach ($suppliers as $supplier): ?>
                                <?php 
                                $balance = getSupplierBalance($conn, $supplier['supplier_code']);
                                $balance_class = $balance > 0 ? 'balance-due' : 'balance-zero';
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($supplier['supplier_code']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($supplier['supplier_name']); ?>">
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </span>
                                    </td>
                                    <td>
    <?php 
    $payment_term = $supplier['payment_terms'] ?: 'N/A';
    if ($payment_term !== 'N/A' && is_numeric($payment_term)) {
        echo htmlspecialchars($payment_term) . ' DAYS';
    } else {
        echo htmlspecialchars($payment_term);
    }
    ?>
</td>
                                    <td><?php echo htmlspecialchars($supplier['vat_type'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['tin'] ?: 'N/A'); ?></td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($supplier['contact_person']); ?>">
                                            <?php echo htmlspecialchars($supplier['contact_person'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-ellipsis" title="<?php echo htmlspecialchars($supplier['email']); ?>">
                                            <?php echo htmlspecialchars($supplier['email'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplier['phone_number'] ?: 'N/A'); ?></td>
                                    <td>
                                        <span class="balance-amount <?php echo $balance_class; ?>">
                                            ₱<?php echo number_format($balance, 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo getStatusBadgeClass($supplier['status']); ?>">
                                            <?php echo htmlspecialchars($supplier['status'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn-view" onclick="showDetail(<?php echo htmlspecialchars(json_encode($supplier)); ?>)">
                                            <i data-lucide="eye" style="width:16px;height:16px;"></i>
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="table-footer">
                        <span>Showing <?php echo count($suppliers); ?> record(s)</span>
                    </div>
                <?php else: ?>
                    <div class="no-results">
                        <i data-lucide="file-search"></i>
                        <h3>No Vendors Found</h3>
                        <p>Try adjusting your search or filter criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
        .balance-amount {
            font-weight: 600;
            font-size: 14px;
        }
        .balance-due {
            color: #dc3545;
        }
        .balance-zero {
            color: #28a745;
        }
    </style>

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
        function showDetail(supplier) {
            const modal = document.getElementById('detailModal');
            const supplierName = document.getElementById('detailSupplierName');
            const status = document.getElementById('detailStatus');
            const type = document.getElementById('detailType');
            const content = document.getElementById('detailContent');

            // Set header
            supplierName.textContent = supplier.supplier_name || 'Vendor Details';
            
            // Set status badges
            status.className = 'badge-status ' + getStatusBadgeClass(supplier.status);
            status.textContent = supplier.status || 'N/A';
            
            type.className = 'badge-status ' + getSupplierTypeBadgeClass(supplier.supplier_type);
            type.textContent = supplier.supplier_type || 'N/A';

            // Build detail grid
            const fields = [
                // Basic Information
                { label: 'Supplier Code', value: supplier.supplier_code || 'N/A', full: false, section: 'basic' },
                { label: 'Supplier Name', value: supplier.supplier_name || 'N/A', full: false, section: 'basic' },
                { label: 'Supplier Type', value: supplier.supplier_type || 'N/A', full: false, section: 'basic' },
                { label: 'Status', value: supplier.status || 'N/A', full: false, section: 'basic' },
                { label: 'Payment Terms', value: supplier.payment_terms || 'N/A', full: false, section: 'basic' },
                { label: 'VAT Type', value: supplier.vat_type || 'N/A', full: false, section: 'basic' },
                
                // Registration Details
                { label: 'Business Reg No', value: supplier.business_reg_no || 'N/A', full: false, section: 'registration' },
                { label: 'TIN', value: supplier.tin || 'N/A', full: false, section: 'registration' },
                
                // Address
                { label: 'Street', value: supplier.street || 'N/A', full: false, section: 'address' },
                { label: 'Barangay', value: supplier.barangay || 'N/A', full: false, section: 'address' },
                { label: 'Town/Municipality', value: supplier.town_municipality || 'N/A', full: false, section: 'address' },
                { label: 'Postal Code', value: supplier.postal_code || 'N/A', full: false, section: 'address' },
                { label: 'Province', value: supplier.province || 'N/A', full: false, section: 'address' },
                { label: 'Country', value: supplier.country || 'N/A', full: false, section: 'address' },
                { label: 'Full Address', value: supplier.full_address || 'N/A', full: true, section: 'address' },
                
                // Contact Information
                { label: 'Contact Person', value: supplier.contact_person || 'N/A', full: false, section: 'contact' },
                { label: 'Position', value: supplier.position || 'N/A', full: false, section: 'contact' },
                { label: 'Email', value: supplier.email || 'N/A', full: false, section: 'contact' },
                { label: 'Phone Number', value: supplier.phone_number || 'N/A', full: false, section: 'contact' },
                { label: 'Website', value: supplier.website || 'N/A', full: false, section: 'contact' },
                
                // Financial & Payment
                { label: 'Bank Name', value: supplier.bank_name || 'N/A', full: false, section: 'financial' },
                { label: 'Bank Number', value: supplier.bank_number || 'N/A', full: false, section: 'financial' },
                
                // Additional
                { label: 'Notes', value: supplier.notes || 'N/A', full: true, section: 'notes' },
                
                // Audit
                { label: 'Created By', value: supplier.created_by || 'N/A', full: false, section: 'audit' },
                { label: 'Created Date', value: formatDateTime(supplier.created_date), full: false, section: 'audit' },
                { label: 'Updated By', value: supplier.updated_by || 'N/A', full: false, section: 'audit' },
                { label: 'Updated Date', value: supplier.updated_date ? formatDateTime(supplier.updated_date) : 'N/A', full: false, section: 'audit' },
            ];

            // Section definitions
            const sections = {
                basic: { title: 'Basic Information', order: 1 },
                registration: { title: 'Registration Details', order: 2 },
                address: { title: 'Address Information', order: 3 },
                contact: { title: 'Contact Information', order: 4 },
                financial: { title: 'Financial & Payment', order: 5 },
                notes: { title: 'Additional Notes', order: 6 },
                audit: { title: 'Audit Information', order: 7 }
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
                            html += `
                                <div class="detail-item ${fullClass}">
                                    <span class="label">${f.label}</span>
                                    <span class="value">${f.value}</span>
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
            if (s.includes('active')) return 'badge-success';
            if (s.includes('inactive')) return 'badge-danger';
            if (s.includes('pending') || s.includes('review')) return 'badge-warning';
            return 'badge-secondary';
        }

        function getSupplierTypeBadgeClass(type) {
            const t = (type || '').toLowerCase();
            if (t.includes('regular') || t.includes('preferred')) return 'badge-success';
            if (t.includes('temporary') || t.includes('trial')) return 'badge-warning';
            if (t.includes('blocked') || t.includes('suspended')) return 'badge-danger';
            return 'badge-info';
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
    </script>
</body>
</html>