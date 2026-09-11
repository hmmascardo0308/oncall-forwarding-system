<?php
// home.php
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

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

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

// Handle Forced Password Change
$pwd_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password_force'])) {
    $new_p = $_POST['new_password'] ?? '';
    $cnf_p = $_POST['confirm_password'] ?? '';

    if ($new_p !== $cnf_p) {
        $pwd_error = "Passwords do not match.";
    } elseif (strlen($new_p) <= 8) {
        $pwd_error = "Password must be more than 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $new_p)) {
        $pwd_error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_p)) {
        $pwd_error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[^a-zA-Z0-9]/', $new_p)) {
        $pwd_error = "Password must contain at least one special character.";
    } elseif ($new_p === 'OncallForwarding1234') {
        $pwd_error = "You cannot use the default password.";
    } else {
        $hashed = password_hash($new_p, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE all_users SET password = ? WHERE id = ?");
        $upd->bind_param("si", $hashed, $user_id);
        if ($upd->execute()) {
            unset($_SESSION['force_password_change']);
            $_SESSION['login_success'] = "Password updated successfully.";
            header("Location: home.php");
            exit;
        } else {
            $pwd_error = "Database error. Please try again.";
        }
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

// Check if we should show the password modal
$show_password_modal = isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'];

$role_display_name = getRoleDisplayName($user_roles);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>">
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
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Home</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>Welcome, <?php echo htmlspecialchars($username); ?>!</h1>
                <p>System overview and connectivity status.</p>
            </section>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Account Status</h3>
                    <div class="value">Active</div>
                    <small style="color:#10b981;">● System Online</small>
                </div>
                <div class="stat-card">
                    <h3>Role Permissions</h3>
                    <div class="value"><?php echo htmlspecialchars($role_display_name); ?></div>
                    <small style="color:var(--text-muted);">Multi-role Access</small>
                </div>
                <div class="stat-card">
                    <h3>Last Online</h3>
                    <div class="value">
                        <?php echo $last_online_date; ?><br>
                        <span class="time"><?php echo $last_online_time; ?></span>
                    </div>
                </div>
            </div>

            <!-- Notification Card - Replaces "No new notifications" -->
            <div class="stat-card notification-main-card <?php echo ($is_admin && $pending_resets > 0) ? 'has-notifications' : ''; ?>" 
                 <?php echo ($is_admin && $pending_resets > 0) ? 'onclick="window.location.href=\'reset_password.php\'"' : ''; ?>>
                
                <?php if ($is_admin && $pending_resets > 0): ?>
                    <div class="notification-content">
                        <div class="icon-wrapper">
                            <i data-lucide="bell-ring"></i>
                        </div>
                        <div class="notification-title">Password Reset Requests</div>
                        <div class="notification-count">
                            <?php echo $pending_resets; ?> pending request<?php echo $pending_resets > 1 ? 's' : ''; ?>
                        </div>
                        
                        <?php if (!empty($pending_resets_list)): ?>
                        <div class="notification-list">
                            <?php 
                            $display_count = 0;
                            foreach ($pending_resets_list as $reset): 
                                if ($display_count >= 3) break;
                                $display_count++;
                                $time_ago = timeAgo($reset['date_requested']);
                            ?>
                            <div class="notification-item">
                                <span class="username"><?php echo htmlspecialchars($reset['username']); ?></span>
                                <span style="color:var(--text-muted);">(<?php echo htmlspecialchars($reset['user_type']); ?>)</span>
                                <span class="time-ago">• <?php echo $time_ago; ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($pending_resets > 3): ?>
                            <div class="notification-item" style="color: var(--accent-blue); font-weight: 500;">
                                +<?php echo ($pending_resets - 3); ?> more requests
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="notification-detail">Click to review and process requests</div>
                        <a href="reset_password.php" class="view-link">View all requests →</a>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted);">No new notifications.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($show_password_modal): ?>
    <div class="force-modal-overlay" id="passwordModal">
        <div class="force-modal">
            <div style="margin-bottom: 16px;">
                <i data-lucide="shield-alert" style="width: 48px; height: 48px; color: #ef4444;"></i>
            </div>
            <h2>Security Update Required</h2>
            <p>You are using a default password. For your security, please update your password immediately to continue accessing the system.</p>
            
            <?php if ($pwd_error): ?>
                <div class="alert-box alert-err"><?= htmlspecialchars($pwd_error) ?></div>
            <?php endif; ?>

            <form method="POST" id="passwordForm">
                <div class="force-form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="newPassword" required autofocus>
                    <span class="pwd-req">Must be > 8 characters, with Uppercase, Lowercase & Special Character.</span>
                </div>
                <div class="force-form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirmPassword" required>
                </div>
                <div class="modal-actions">
                    <button type="submit" name="change_password_force" class="force-btn">Update Password</button>
                    <button type="button" class="dismiss-btn" onclick="dismissPasswordModal()">Close</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

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

        // Dismiss password modal
        function dismissPasswordModal() {
            window.location.href = '../logout.php';
        }

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
        });
    </script>
</body>
</html>