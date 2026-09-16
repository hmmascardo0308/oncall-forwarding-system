<?php
// reports.php
session_start();
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

// $allowed_pages, hasAccess(), and getRoleDisplayName() now come from access_control.php

// Enforce page access - show modal instead of redirecting
$current_page = basename($_SERVER['PHP_SELF']);
$has_access = hasAccess($current_page, $user_roles, $allowed_pages);
$show_access_denied = !$has_access;

// Function to calculate time ago accurately
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $current_time = time();
    $time_diff = $current_time - $timestamp;
    
    if ($time_diff < 0) {
        return 'Just now';
    } elseif ($time_diff < 60) {
        return 'Just now';
    } elseif ($time_diff < 3600) {
        $minutes = floor($time_diff / 60);
        return $minutes . 'm ago';
    } elseif ($time_diff < 86400) {
        $hours = floor($time_diff / 3600);
        return $hours . 'h ago';
    } elseif ($time_diff < 604800) {
        $days = floor($time_diff / 86400);
        return $days . 'd ago';
    } elseif ($time_diff < 2592000) {
        $weeks = floor($time_diff / 604800);
        return $weeks . 'w ago';
    } elseif ($time_diff < 31536000) {
        $months = floor($time_diff / 2592000);
        return $months . 'mo ago';
    } else {
        $years = floor($time_diff / 31536000);
        return $years . 'y ago';
    }
}

// Query for last online
$last_online_date = "Not Available";
$last_online_time = "";
$query = "SELECT last_online FROM all_users WHERE id = ?";
if ($stmt = $conn->prepare($query)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['last_online']) {
            $timestamp = strtotime($row['last_online']);
            $last_online_date = date("F j, Y", $timestamp);
            $last_online_time = date("h:i:s A", $timestamp);
        } else {
            $last_online_date = "First login";
        }
    }
    $stmt->close();
}

// Check for pending password reset requests (Admin only)
$pending_resets = 0;
$pending_resets_list = [];
if ($is_admin) {
    $reset_query = "SELECT id, username, user_type, date_requested 
                    FROM password_reset 
                    WHERE status = 'Pending' 
                    ORDER BY date_requested DESC";
    $reset_result = $conn->query($reset_query);
    if ($reset_result) {
        $pending_resets = $reset_result->num_rows;
        while ($row = $reset_result->fetch_assoc()) {
            $pending_resets_list[] = $row;
        }
    }
}

$success_msg = null;
if (isset($_SESSION['login_success'])) {
    $success_msg = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

$role_display_name = getRoleDisplayName($user_roles);

// Report definitions with icons and colors
$reports = [
    'aged_payables' => [
        'title' => 'Aged Payables',
        'icon' => 'file-text',
        'color' => '#ef4444',
        'bg_color' => '#fef2f2',
        'page' => 'aged_payables.php'
    ],
    'aged_receivables' => [
        'title' => 'Aged Receivables',
        'icon' => 'file-text',
        'color' => '#f59e0b',
        'bg_color' => '#fffbeb',
        'page' => 'aged_receivables.php'
    ],
    'customer_ledgers' => [
        'title' => 'Customer Ledgers',
        'icon' => 'users',
        'color' => '#3b82f6',
        'bg_color' => '#eff6ff',
        'page' => 'customer_ledger.php'  // ✅ Now points to customer_ledger.php
    ],
    'customer_list' => [
        'title' => 'Customer List',
        'icon' => 'user',
        'color' => '#8b5cf6',
        'bg_color' => '#f5f3ff',
        'page' => 'customer_list.php'  // To be created
    ],
    'purchase_order_list' => [
        'title' => 'Purchase Order List',
        'icon' => 'shopping-cart',
        'color' => '#059669',
        'bg_color' => '#ecfdf5',
        'page' => 'purchase_order_list_all.php'  // To be created
    ],
    'purchase_order_registering' => [
        'title' => 'Purchase Order Register',
        'icon' => 'clipboard',
        'color' => '#0891b2',
        'bg_color' => '#ecfeff',
        'page' => 'purchase_order.php'  // To be created
    ],
        'vendor_ledgers' => [
        'title' => 'Vendor Ledgers',
        'icon' => 'briefcase',
        'color' => '#6b7280',
        'bg_color' => '#f3f4f6',
        'page' => 'vendor_ledger.php'  // To be created
    ],
    'vendor_list' => [
        'title' => 'Vendor List',
        'icon' => 'truck',
        'color' => '#4b5563',
        'bg_color' => '#f9fafb',
        'page' => 'vendor_list.php'  // To be created
    ],
    'service_invoice_list' => [
        'title' => 'Service Invoice List',
        'icon' => 'weight',
        'color' => '#dc2626',
        'bg_color' => '#fef2f2',
        'page' => 'service_invoice_list_all.php'  // To be created
    ],
    'service_invoice_register' => [
        'title' => 'Service Invoice Register',
        'icon' => 'book-open',
        'color' => '#7c3aed',
        'bg_color' => '#f5f3ff',
        'page' => 'service_invoice.php'  // To be created
    ],
    'sales_order_list' => [
        'title' => 'Sales Order List',
        'icon' => 'shopping-bag',
        'color' => '#2563eb',
        'bg_color' => '#eff6ff',
        'page' => 'sales_order_list_all.php'  // To be created
    ],
    'sales_order_register' => [
        'title' => 'Sales Order Register',
        'icon' => 'book',
        'color' => '#d97706',
        'bg_color' => '#fffbeb',
        'page' => 'sales_order.php'  // To be created
    ],

];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/report.css?v=<?= time(); ?>">

    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
   
</head>
<body>

    <?php if ($success_msg): ?>
        <div id="flash-message"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <!-- Access Denied Modal -->
    <div id="accessModal" class="modal-overlay" style="<?php echo $show_access_denied ? 'display: flex;' : 'display: none;'; ?>">
        <div class="access-modal">
            <i data-lucide="shield-off"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access the reports page.</p>
            <button class="modal-btn" onclick="closeModal()">OK</button>
        </div>
    </div>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Reports</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>📊 Reports</h1>
                <p>Access all system reports and documents.</p>
            </section>

            <div class="section-header">
                <h2>Available Reports</h2>
                <span class="report-count"><?php echo count($reports); ?> reports available</span>
            </div>

            <div class="report-grid">
                <?php foreach ($reports as $key => $report): ?>
                    <?php 
                    $is_available = ($report['page'] !== '#');
                    $page_url = $is_available ? $report['page'] : 'javascript:void(0)';
                    $disabled_class = $is_available ? '' : 'disabled';
                    
                    // Check if user has access to this specific report page
                    $report_has_access = $is_available ? hasAccess($report['page'], $user_roles, $allowed_pages) : false;
                    ?>
                    <a href="<?php echo $page_url; ?>" 
                       class="report-card <?php echo $disabled_class; ?>"
                       <?php if (!$is_available): ?>
                       onclick="showComingSoon('<?php echo $report['title']; ?>'); return false;"
                       <?php elseif (!$report_has_access): ?>
                       onclick="showAccessDenied('<?php echo $report['title']; ?>'); return false;"
                       <?php endif; ?>>
                        <div class="card-header">
                            <div class="icon-wrapper" style="background-color: <?php echo $report['bg_color']; ?>;">
                                <i data-lucide="<?php echo $report['icon']; ?>" style="color: <?php echo $report['color']; ?>;"></i>
                            </div>
                            <span class="arrow-indicator">→</span>
                        </div>
                        <h3 class="report-title">
                            <?php echo $report['title']; ?>
                            <?php if (!$is_available): ?>
                                <span class="coming-soon-badge">Coming Soon</span>
                            <?php elseif (!$report_has_access): ?>
                                <span class="coming-soon-badge" style="background: #fef2f2; color: #ef4444;">No Access</span>
                            <?php endif; ?>
                        </h3>
                        <p class="report-subtitle">
                            <?php if ($is_available && $report_has_access): ?>
                                View and manage <?php echo strtolower($report['title']); ?>
                            <?php elseif ($is_available && !$report_has_access): ?>
                                You don't have permission to access this report
                            <?php else: ?>
                                This report is currently being developed
                            <?php endif; ?>
                        </p>
                    </a>
                <?php endforeach; ?>
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
            const modal = document.getElementById('accessModal');
            modal.style.display = 'none';
            // Redirect to home after closing
            window.location.href = 'home.php';
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
        });

        // Show coming soon message
        function showComingSoon(reportTitle) {
            const msg = document.createElement('div');
            msg.style.cssText = `
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%);
                background: #1e293b;
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                opacity: 0;
                transition: opacity 0.3s ease;
            `;
            msg.textContent = `📄 ${reportTitle} - Coming Soon`;
            document.body.appendChild(msg);
            
            setTimeout(() => { msg.style.opacity = '1'; }, 10);
            setTimeout(() => { 
                msg.style.opacity = '0'; 
                setTimeout(() => msg.remove(), 300);
            }, 2000);
        }

        // Show access denied message for individual reports
        function showAccessDenied(reportTitle) {
            const msg = document.createElement('div');
            msg.style.cssText = `
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%);
                background: #dc2626;
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                opacity: 0;
                transition: opacity 0.3s ease;
            `;
            msg.textContent = `⛔ ${reportTitle} - Access Denied`;
            document.body.appendChild(msg);
            
            setTimeout(() => { msg.style.opacity = '1'; }, 10);
            setTimeout(() => { 
                msg.style.opacity = '0'; 
                setTimeout(() => msg.remove(), 300);
            }, 2000);
        }

        // If access is denied for the whole page, show modal immediately
        <?php if ($show_access_denied): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('accessModal');
            if (modal) {
                modal.style.display = 'flex';
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>