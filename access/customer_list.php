<?php
// customer_list.php
session_start();
// Set timezone to match your location
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

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

$success_msg = null;
if (isset($_SESSION['login_success'])) {
    $success_msg = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

$role_display_name = getRoleDisplayName($user_roles);

// Handle search/filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build the query
$query = "SELECT 
            id,
            customer_code,
            customer_type,
            company_name,
            tin,
            first_name,
            middle_name,
            last_name,
            full_name,
            contact_person,
            position,
            contact_number,
            email,
            street,
            barangay,
            town_municipality,
            province,
            postal_code,
            country,
            pickup_delivery_zone,
            id_type,
            id_number,
            credit_limit,
            credit_terms,
            status,
            customer_since,
            total_rentals,
            loyalty_tier,
            notes,
            created_at,
            created_by,
            updated_at,
            updated_by,
            full_address
        FROM customer_masterlist
        WHERE 1=1";

$params = [];
$types = "";

// Apply status filter
if ($status_filter) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Apply search filter
if ($search) {
    $query .= " AND (customer_code LIKE ? 
                    OR full_name LIKE ? 
                    OR company_name LIKE ? 
                    OR contact_number LIKE ? 
                    OR email LIKE ?
                    OR tin LIKE ?)";
    $search_pattern = "%" . $search . "%";
    $params = array_merge($params, [$search_pattern, $search_pattern, $search_pattern, $search_pattern, $search_pattern, $search_pattern]);
    $types .= "ssssss";
}

$query .= " ORDER BY full_name ASC";

// Execute query
$customers = [];
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Get status counts for filter badges
$status_counts = [];
$count_query = "SELECT status, COUNT(*) as count FROM customer_masterlist GROUP BY status";
$count_result = $conn->query($count_query);
if ($count_result) {
    while ($row = $count_result->fetch_assoc()) {
        $status_counts[$row['status']] = $row['count'];
    }
}
$total_customers = count($customers);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>">
        <link rel="stylesheet" href="css/customer_list.css?v=<?= time(); ?>">

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

    <!-- Customer Detail Modal -->
    <div id="detailModal" class="detail-modal-overlay" onclick="closeDetailModal(event)">
        <div class="detail-modal" onclick="event.stopPropagation()">
            <div class="detail-modal-header">
                <div class="header-info">
                    <h2 id="detailCustomerName">Customer Name</h2>
                    <div class="sub-info">
                        <span id="detailCustomerCode">Code: -</span>
                        <span style="margin: 0 8px;">•</span>
                        <span id="detailStatus">Status: -</span>
                    </div>
                </div>
                <button class="detail-modal-close" onclick="closeDetailModal()">
                    <i data-lucide="x"></i>
                </button>
            </div>
            <div class="detail-modal-body">
                <div class="detail-grid" id="detailContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
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
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Customer List</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>👥 Customer List</h1>
                <p>View and manage customer information. <span style="font-weight:400;color:var(--text-muted);font-size:14px;display:inline-block;margin-top:4px;"><i data-lucide="mouse-pointer-2" style="width:14px;height:14px;display:inline-block;vertical-align:middle;"></i> Double-click any row for full details</span></p>
            </section>

           <div class="toolbar">
    <div class="toolbar-left">
        <form method="GET" class="search-box">
            <i data-lucide="search"></i>
            <input type="text" name="search" placeholder="Search customers..." value="<?php echo htmlspecialchars($search); ?>">
            <?php if ($status_filter): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <?php endif; ?>
        </form>
        
        <div class="filter-badges">
            <a href="?<?php echo $search ? 'search=' . urlencode($search) : ''; ?>" 
               class="filter-badge <?php echo !$status_filter ? 'active' : ''; ?>">
                All <span class="count"><?php echo array_sum($status_counts); ?></span>
            </a>
            <?php foreach ($status_counts as $status => $count): ?>
                <?php if ($status && $status !== ''): ?>
                    <a href="?status=<?php echo urlencode($status); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" 
                       class="filter-badge <?php echo $status_filter === $status ? 'active' : ''; ?>">
                        <?php echo ucfirst($status); ?> <span class="count"><?php echo $count; ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($status_filter): ?>
                <a href="?<?php echo $search ? 'search=' . urlencode($search) : ''; ?>" class="clear-filter">Clear</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="toolbar-right">
        <!-- EXPORT BUTTON - Added here -->
        <a href="export_customer_list.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?><?php echo $status_filter ? ($search ? '&' : '?') . 'status=' . urlencode($status_filter) : ''; ?>" 
           class="btn-export" title="Export to Excel">
            <i data-lucide="file-spreadsheet"></i>
            <span>Export to Excel</span>
        </a>
        <span class="result-count"><?php echo $total_customers; ?> customer<?php echo $total_customers !== 1 ? 's' : ''; ?></span>
    </div>
</div>

            <div class="table-container">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Customer Code</th>
                                <th>Full Name</th>
                                <th>Company</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>TIN</th>
                                <th>Credit Limit</th>
                                <th>Status</th>
                                <th>Since</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i data-lucide="users"></i>
                                            <h3>No customers found</h3>
                                            <p><?php echo $search ? 'Try adjusting your search terms.' : 'No customers in the system.'; ?></p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $customer): ?>
                                    <?php 
                                    $status_class = 'status-active';
                                    $status_text = $customer['status'] ?? 'Active';
                                    if (strtolower($status_text) === 'inactive') {
                                        $status_class = 'status-inactive';
                                    } elseif (strtolower($status_text) === 'pending') {
                                        $status_class = 'status-pending';
                                    } elseif (strtolower($status_text) === 'active' || !$status_text) {
                                        $status_text = 'Active';
                                        $status_class = 'status-active';
                                    }
                                    ?>
                                    <tr ondblclick="showCustomerDetail(<?php echo htmlspecialchars(json_encode($customer)); ?>)">
                                        <td><span class="customer-code"><?php echo htmlspecialchars($customer['customer_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($customer['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['company_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['contact_number'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['tin'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($customer['credit_limit'] > 0): ?>
                                                <span class="credit-limit positive">₱<?php echo number_format($customer['credit_limit'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="credit-limit zero">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($status_text); ?></span></td>
                                        <td><?php echo $customer['customer_since'] ? date('M d, Y', strtotime($customer['customer_since'])) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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

        // Close modal function
        function closeModal() {
            modal.style.display = 'none';
        }

        // Close modal when clicking outside
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeModal();
            }
            if (e.key === 'Escape' && document.getElementById('detailModal').classList.contains('active')) {
                closeDetailModal();
            }
        });

        // Auto-submit search on Enter
        document.querySelector('.search-box input')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                this.closest('form').submit();
            }
        });

        // Customer Detail Modal Functions
        function showCustomerDetail(customer) {
            const modal = document.getElementById('detailModal');
            const nameEl = document.getElementById('detailCustomerName');
            const codeEl = document.getElementById('detailCustomerCode');
            const statusEl = document.getElementById('detailStatus');
            const contentEl = document.getElementById('detailContent');

            // Set header info
            nameEl.textContent = customer.full_name || 'Unknown Customer';
            codeEl.textContent = 'Code: ' + (customer.customer_code || '-');
            
            const statusText = customer.status || 'Active';
            const statusClass = statusText.toLowerCase() === 'inactive' ? 'status-inactive' : 
                               statusText.toLowerCase() === 'pending' ? 'status-pending' : 'status-active';
            statusEl.innerHTML = 'Status: <span class="status-badge ' + statusClass + '">' + 
                                ucfirst(statusText) + '</span>';

            // Build detail content
            const sections = [
                {
                    title: 'Personal Information',
                    fields: [
                        { label: 'Full Name', value: customer.full_name },
                        { label: 'First Name', value: customer.first_name },
                        { label: 'Middle Name', value: customer.middle_name },
                        { label: 'Last Name', value: customer.last_name },
                        { label: 'Customer Type', value: customer.customer_type },
                        { label: 'Contact Person', value: customer.contact_person },
                        { label: 'Position', value: customer.position },
                        { label: 'Contact Number', value: customer.contact_number },
                        { label: 'Email', value: customer.email }
                    ]
                },
                {
                    title: 'Company & Address',
                    fields: [
                        { label: 'Company Name', value: customer.company_name },
                        { label: 'TIN', value: customer.tin },
                        { label: 'Street', value: customer.street },
                        { label: 'Barangay', value: customer.barangay },
                        { label: 'Town/Municipality', value: customer.town_municipality },
                        { label: 'Province', value: customer.province },
                        { label: 'Postal Code', value: customer.postal_code },
                        { label: 'Country', value: customer.country },
                        { label: 'Full Address', value: customer.full_address, full: true },
                        { label: 'Pickup/Delivery Zone', value: customer.pickup_delivery_zone, full: true }
                    ]
                },
                {
                    title: 'Business Information',
                    fields: [
                        { label: 'Credit Limit', value: customer.credit_limit > 0 ? '₱' + parseFloat(customer.credit_limit).toFixed(2) : '-' },
                        { label: 'Credit Terms', value: customer.credit_terms },
                        { label: 'Customer Since', value: customer.customer_since ? formatDate(customer.customer_since) : '-' },
                        { label: 'Total Rentals', value: customer.total_rentals },
                        { label: 'Loyalty Tier', value: customer.loyalty_tier },
                        { label: 'ID Type', value: customer.id_type },
                        { label: 'ID Number', value: customer.id_number }
                    ]
                },
                {
                    title: 'Audit Information',
                    fields: [
                        { label: 'Created At', value: customer.created_at ? formatDateTime(customer.created_at) : '-' },
                        { label: 'Created By', value: customer.created_by },
                        { label: 'Updated At', value: customer.updated_at ? formatDateTime(customer.updated_at) : '-' },
                        { label: 'Updated By', value: customer.updated_by },
                        { label: 'Notes', value: customer.notes, full: true }
                    ]
                }
            ];

            let html = '';
            sections.forEach((section, index) => {
                const hasVisibleFields = section.fields.some(f => f.value && f.value !== '-');
                if (!hasVisibleFields) return;
                
                if (index > 0) {
                    html += '<div class="detail-section-title">' + section.title + '</div>';
                } else {
                    html += '<div class="detail-section-title">' + section.title + '</div>';
                }
                
                section.fields.forEach(field => {
                    const value = field.value && field.value.trim() !== '' ? field.value : '-';
                    const isEmpty = value === '-';
                    const fullClass = field.full ? ' full-width' : '';
                    html += `
                        <div class="detail-group${fullClass}">
                            <span class="label">${field.label}</span>
                            <span class="value${isEmpty ? ' empty' : ''}">${escapeHtml(value)}</span>
                        </div>
                    `;
                });
            });

            contentEl.innerHTML = html;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            // Re-render Lucide icons in modal
            lucide.createIcons();
        }

        function closeDetailModal(event) {
            if (event && event.target !== event.currentTarget) return;
            const modal = document.getElementById('detailModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Utility functions
        function ucfirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }

        function formatDateTime(dateStr) {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleString('en-US', { 
                month: 'short', 
                day: '2-digit', 
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function escapeHtml(text) {
            if (!text) return '-';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close detail modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDetailModal();
            }
        });
    </script>
</body>
</html>