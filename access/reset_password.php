<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

// Flash message handling
$flash = null;
if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Get user data
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Admin';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Access Control - Only admin can access this page
if (!$is_admin) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access this page."
    ];
    header("Location: home.php");
    exit;
}

// $allowed_pages, hasAccess(), and getRoleDisplayName() now come from access_control.php

$role_display_name = getRoleDisplayName($user_roles);

// Handle Password Reset Approval/Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_action'])) {
    $reset_id = (int) $_POST['reset_id'];
    $action = $_POST['reset_action'];

    $check_stmt = $conn->prepare("SELECT status, username FROM password_reset WHERE id = ?");
    $check_stmt->bind_param("i", $reset_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_row = $check_result->fetch_assoc()) {
        if (strtolower($check_row['status']) !== 'pending') {
            $_SESSION['flash_message'] = [
                'text' => "This request has already been processed.",
                'type' => 'error'
            ];
            header("Location: reset_password.php");
            exit;
        }

        $reset_username = $check_row['username'];

        if ($action === 'approve') {
            $new_password = 'Oncall1234';
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $update_stmt = $conn->prepare("UPDATE all_users SET password = ? WHERE username = ?");
            $update_stmt->bind_param("ss", $hashed_password, $reset_username);
            $update_stmt->execute();
            $update_stmt->close();

            $reset_stmt = $conn->prepare("UPDATE password_reset SET status = 'Approved', resetted_by = ?, resetted_at = NOW() WHERE id = ?");
            $reset_stmt->bind_param("si", $full_name, $reset_id);
            $reset_stmt->execute();
            $reset_stmt->close();

            $_SESSION['flash_message'] = [
                'text' => "Password reset approved! New password: <strong>{$new_password}</strong>",
                'type' => 'success'
            ];
        } elseif ($action === 'reject') {
            $reset_stmt = $conn->prepare("UPDATE password_reset SET status = 'Rejected', rejected_by = ?, rejected_at = NOW() WHERE id = ?");
            $reset_stmt->bind_param("si", $full_name, $reset_id);
            $reset_stmt->execute();
            $reset_stmt->close();

            $_SESSION['flash_message'] = [
                'text' => "Password reset request rejected.",
                'type' => 'error'
            ];
        }
    } else {
        $_SESSION['flash_message'] = [
            'text' => "Invalid reset request.",
            'type' => 'error'
        ];
    }
    $check_stmt->close();

    header("Location: reset_password.php");
    exit;
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch password reset requests with search filter - JOIN with all_users to get full_name
// FIX: Explicitly cast collation for the join to avoid collation mismatch
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $reset_query = "SELECT pr.id, pr.username, pr.user_type, pr.date_requested, pr.status, 
                           pr.resetted_by, pr.resetted_at, pr.rejected_by, pr.rejected_at,
                           u.full_name
                    FROM password_reset pr
                    LEFT JOIN all_users u ON pr.username COLLATE utf8mb4_general_ci = u.username COLLATE utf8mb4_general_ci
                    WHERE pr.username LIKE '%$search_term%'
                    OR pr.user_type LIKE '%$search_term%'
                    OR pr.status LIKE '%$search_term%'
                    OR u.full_name LIKE '%$search_term%'
                    ORDER BY
                        CASE
                            WHEN pr.status = 'Pending' THEN 0
                            ELSE 1
                        END,
                        pr.date_requested DESC";
} else {
    $reset_query = "SELECT pr.id, pr.username, pr.user_type, pr.date_requested, pr.status, 
                           pr.resetted_by, pr.resetted_at, pr.rejected_by, pr.rejected_at,
                           u.full_name
                    FROM password_reset pr
                    LEFT JOIN all_users u ON pr.username COLLATE utf8mb4_general_ci = u.username COLLATE utf8mb4_general_ci
                    ORDER BY
                        CASE
                            WHEN pr.status = 'Pending' THEN 0
                            ELSE 1
                        END,
                        pr.date_requested DESC";
}

$reset_result = $conn->query($reset_query);
$reset_requests = [];
if ($reset_result) {
    while ($row = $reset_result->fetch_assoc()) {
        $reset_requests[] = $row;
    }
}

// Count pending requests
$pending_count = 0;
foreach ($reset_requests as $request) {
    if (strtolower($request['status']) === 'pending') {
        $pending_count++;
    }
}

// Check if flash message is a success (approval) to auto-refresh
$auto_refresh = false;
$is_success = false;
if ($flash && isset($flash['type']) && $flash['type'] === 'success' && strpos($flash['text'], 'Password reset approved') !== false) {
    $auto_refresh = true;
    $is_success = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Requests | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/users.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="css/reset.css?v=<?= time(); ?>">

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

    <?php include 'sidebar.php'; ?>
 
    <!-- MAIN CONTENT -->
    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <a href="all_users.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;">Manage Users</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Password Reset Request</span></span>
            </div>
            <div class="user-profile">
                <span class="badge badge-admin"><?php echo htmlspecialchars($full_name); ?></span>
                <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
            </div>
        </header>
        
        <div class="reset-management">

            <!-- Info Note -->
            <div class="info-note">
                <i data-lucide="info" style="width:16px;height:16px;display:inline;margin-right:8px;"></i>
                <strong>Note:</strong> Password reset requests are processed one at a time. Double-click <strong>Approve</strong> to confirm the reset — the password will be set to <strong>Oncall1234</strong>.
            </div>

            <!-- Flash Message -->
            <?php if ($flash): ?>
            <div class="flash-message <?= $flash['type'] ?>" id="flashMessage">
                <span><?= $flash['text'] ?></span>
                <?php if ($auto_refresh): ?>
                    <span class="countdown-timer" id="countdownTimer">3</span>
                <?php endif; ?>
                <button onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <h1>
                    <i data-lucide="key-round"></i> Password Reset Requests
                    <?php if ($pending_count > 0): ?>
                        <span class="badge-pending"><?= $pending_count ?> Pending</span>
                    <?php endif; ?>
                </h1>
                <div class="header-actions">
                    <!-- Search Bar -->
                    <form method="GET" action="" style="flex: 1; min-width: 200px;">
                        <div class="search-container">
                            <i data-lucide="search"></i>
                            <input 
                                type="text" 
                                name="search" 
                                placeholder="Search by username or full name..." 
                                value="<?php echo htmlspecialchars($search_term); ?>"
                                id="searchInput"
                                autocomplete="off"
                            >
                            <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                            </button>
                        </div>
                    </form>
                    <button class="refresh-btn" onclick="location.reload()">
                        <i data-lucide="refresh-cw" style="width:16px;height:16px;"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Search Results Info -->
            <?php if (!empty($search_term)): ?>
                <div class="search-results-info">
                    Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                    (<?php echo count($reset_requests); ?> found)
                    <a href="reset_password.php" style="color: #2563eb; text-decoration: none; margin-left: 8px; font-weight: 500;">
                        Clear search
                    </a>
                </div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <select id="statusFilter" onchange="filterTable()">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
                <span style="color:#94a3b8;font-size:14px;">
                    Total: <?= count($reset_requests) ?> requests
                </span>
            </div>

            <!-- Requests Table -->
            <?php if (!empty($reset_requests)): ?>
                <table class="requests-table" id="requestsTable">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>User Type</th>
                            <th>Date Requested</th>
                            <th>Status</th>
                            <th>Processed By</th>
                            <th>Processed Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reset_requests as $request):
                            $status_lower = strtolower($request['status']);
                            $is_pending = ($status_lower === 'pending');

                            $processed_by = $request['resetted_by'] ?: $request['rejected_by'];
                            $processed_at = $request['resetted_at'] ?: $request['rejected_at'];
                            $full_name_display = !empty($request['full_name']) ? $request['full_name'] : '—';
                        ?>
                        <tr class="<?= $is_pending ? 'pending-row' : '' ?>" data-username="<?= strtolower(htmlspecialchars($request['username'])) ?>" data-status="<?= $status_lower ?>">
                            <td><strong><?= htmlspecialchars($request['username']) ?></strong></td>
                            <td class="full-name-cell"><?= htmlspecialchars($full_name_display) ?></td>
                            <td><?= htmlspecialchars($request['user_type']) ?></td>
                            <td><?= date('M d, Y h:i A', strtotime($request['date_requested'])) ?></td>
                            <td>
                                <span class="status-badge <?= $status_lower ?>">
                                    <?= htmlspecialchars($request['status']) ?>
                                </span>
                            </td>
                            <td><?= $processed_by ? htmlspecialchars($processed_by) : '—' ?></td>
                            <td><?= $processed_at ? date('M d, Y h:i A', strtotime($processed_at)) : '—' ?></td>
                            <td>
                                <?php if ($is_pending): ?>
                                    <div class="action-buttons">
                                        <form method="POST" class="reset-form" style="display:inline;">
                                            <input type="hidden" name="reset_id" value="<?= $request['id'] ?>">
                                            <input type="hidden" name="reset_action" value="approve">
                                            <button type="button" class="btn-approve"
                                                    data-username="<?= htmlspecialchars($request['username']) ?>">
                                                <i data-lucide="check" style="width:14px;height:14px;display:inline;"></i>
                                                <span class="btn-label">Approve</span>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return handleRejectSubmit(this, '<?= htmlspecialchars($request['username'], ENT_QUOTES) ?>');">
                                            <input type="hidden" name="reset_id" value="<?= $request['id'] ?>">
                                            <input type="hidden" name="reset_action" value="reject">
                                            <button type="submit" class="btn-reject">
                                                <i data-lucide="x" style="width:14px;height:14px;display:inline;"></i> Reject
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:13px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i data-lucide="inbox"></i>
                    <h3>
                        <?php if (!empty($search_term)): ?>
                            No results found for "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                        <?php else: ?>
                            No Password Reset Requests
                        <?php endif; ?>
                    </h3>
                    <p>
                        <?php if (!empty($search_term)): ?>
                            <a href="reset_password.php" style="color: #2563eb; text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                View all requests
                            </a>
                        <?php else: ?>
                            There are no password reset requests at this time.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        lucide.createIcons();

        // ── Auto-refresh for success messages ─────────────────────────
        <?php if ($auto_refresh): ?>
        (function() {
            let countdown = 3;
            const timerElement = document.getElementById('countdownTimer');
            const flashMessage = document.getElementById('flashMessage');
            
            if (timerElement) {
                const interval = setInterval(function() {
                    countdown--;
                    if (countdown > 0) {
                        timerElement.textContent = countdown;
                    } else {
                        clearInterval(interval);
                        // Fade out the flash message
                        if (flashMessage) {
                            flashMessage.style.opacity = '0';
                            flashMessage.style.transition = 'opacity 0.5s ease';
                        }
                        // Refresh the page after a brief delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 600);
                    }
                }, 1000);
            } else {
                // Fallback: refresh after 3 seconds if timer element not found
                setTimeout(function() {
                    window.location.reload();
                }, 3000);
            }
        })();
        <?php endif; ?>

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

        // ── Filter Table (client-side status filter) ──────────────────
        function filterTable() {
            const statusFilter = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('#requestsTable tbody tr');

            rows.forEach(row => {
                const status = row.getAttribute('data-status') || '';
                const matchesStatus = statusFilter === 'all' || status === statusFilter;

                row.style.display = matchesStatus ? '' : 'none';
            });
        }

        // ── One-request-at-a-time enforcement ─────────────────────────
        let actionInProgress = false;

        function disableAllActionButtons() {
            document.querySelectorAll('.btn-approve, .btn-reject').forEach(btn => {
                btn.disabled = true;
            });
        }

        // ── Double-click confirmation for password reset (Approve) ────
        const ARM_TIMEOUT_MS = 3000;

        document.querySelectorAll('.btn-approve').forEach(btn => {
            let armed = false;
            let armTimer = null;
            const label = btn.querySelector('.btn-label');
            const defaultLabel = label.textContent;

            function disarm() {
                armed = false;
                btn.classList.remove('armed');
                label.textContent = defaultLabel;
                clearTimeout(armTimer);
            }

            function doApprove() {
                if (actionInProgress) return;
                actionInProgress = true;
                disableAllActionButtons();
                btn.closest('form').submit();
            }

            btn.addEventListener('click', function () {
                if (actionInProgress) return;

                if (!armed) {
                    armed = true;
                    btn.classList.add('armed');
                    label.textContent = 'Click again to confirm';
                    armTimer = setTimeout(disarm, ARM_TIMEOUT_MS);
                } else {
                    doApprove();
                }
            });

            btn.addEventListener('dblclick', function () {
                if (actionInProgress) return;
                doApprove();
            });
        });

        function handleRejectSubmit(form, username) {
            if (actionInProgress) return false;
            const confirmed = confirm('Reject password reset for ' + username + '?');
            if (confirmed) {
                actionInProgress = true;
                disableAllActionButtons();
            }
            return confirmed;
        }

        // ── Access control check for sidebar ──────────────────────────
        function checkAccess(page) {
            const allowedPages = <?= json_encode($allowed_pages) ?>;
            const userRoles = <?= json_encode($user_roles) ?>;

            let hasAccess = false;
            for (let role of userRoles) {
                if (allowedPages[role] && allowedPages[role].includes(page)) {
                    hasAccess = true;
                    break;
                }
            }

            if (!hasAccess) {
                document.getElementById('accessModal').style.display = 'flex';
                return false;
            }
            return true;
        }

        function closeModal() {
            document.getElementById('accessModal').style.display = 'none';
        }

        // ── Initialize filter on page load ────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            filterTable();
        });
    </script>

</body>
</html>