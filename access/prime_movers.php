<?php
// prime_movers.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

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

// Check if user has access to prime mover page
// Allow access to: admin, user, service_invoice_maker (view only), prime_mover_register (full access)
$can_access_prime_mover = $is_admin || in_array('user', $user_roles) || in_array('service_invoice_maker', $user_roles) || in_array('prime_mover_register', $user_roles);

if (!$can_access_prime_mover) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Prime Movers Masterlist page."
    ];
    header("Location: home.php");
    exit;
}

// Check if user can add/edit prime movers
// Allow: admin, user, and prime_mover_register
$can_manage_prime_movers = $is_admin || in_array('user', $user_roles) || in_array('prime_mover_register', $user_roles);

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Generate next prime mover code (PM-001, PM-002, ...)
$next_code = 'PM-001';
$code_query = "SELECT prime_mover_code 
               FROM prime_movers_masterlist 
               WHERE prime_mover_code LIKE 'PM-%' 
               ORDER BY CAST(SUBSTRING(prime_mover_code, 4) AS UNSIGNED) DESC 
               LIMIT 1";

$code_result = mysqli_query($conn, $code_query);
if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
    if (preg_match('/^PM-(\d+)$/', $row['prime_mover_code'], $matches)) {
        $next_number = (int)$matches[1] + 1;
        $next_code   = sprintf('PM-%03d', $next_number);
    }
}

// Handle Add Prime Mover - Only allow if user can manage
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prime_mover'])) {
    // Check permission
    if (!$can_manage_prime_movers) {
        $message = "You don't have permission to add prime movers.";
        $message_type = 'error';
    } else {
        $fields = [
            'prime_mover_code'    => trim($_POST['prime_mover_code'] ?? ''),
            'plate_number'        => trim($_POST['plate_number'] ?? ''),
            'brand'               => trim($_POST['brand'] ?? ''),
            'model'               => trim($_POST['model'] ?? ''),
            'color'               => trim($_POST['color'] ?? ''),
            'engine_number'       => trim($_POST['engine_number'] ?? ''),
            'chassis_number'      => trim($_POST['chassis_number'] ?? ''),
            'status'              => trim($_POST['status'] ?? 'Active'),
            'notes'               => trim($_POST['notes'] ?? ''),
        ];

        // Required fields
        $required = ['prime_mover_code', 'plate_number', 'brand', 'model'];
        $errors = [];

        foreach ($required as $field) {
            if (empty($fields[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }

        if (!empty($errors)) {
            $message = implode("<br>", $errors);
            $message_type = 'error';
        } else {
            // Check duplicate prime_mover_code
            $check_query = "SELECT id FROM prime_movers_masterlist WHERE prime_mover_code = ? LIMIT 1";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "s", $fields['prime_mover_code']);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);

            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $message = "Prime Mover code <strong>" . htmlspecialchars($fields['prime_mover_code']) . "</strong> already exists.";
                $message_type = 'error';
            } else {
                $created_by = $_SESSION['username'];
                $created_at = date('Y-m-d H:i:s');

                $insert_query = "
                    INSERT INTO prime_movers_masterlist (
                        prime_mover_code, plate_number, brand, model, color,
                        engine_number, chassis_number, status, notes,
                        created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";

                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param(
                    $stmt, "sssssssssss",
                    $fields['prime_mover_code'],
                    $fields['plate_number'],
                    $fields['brand'],
                    $fields['model'],
                    $fields['color'],
                    $fields['engine_number'],
                    $fields['chassis_number'],
                    $fields['status'],
                    $fields['notes'],
                    $created_by,
                    $created_at
                );

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Prime Mover <strong>" . htmlspecialchars($fields['plate_number']) . "</strong> registered successfully!";
                    $message_type = 'success';
                } else {
                    $message = "Database error: " . mysqli_error($conn);
                    $message_type = 'error';
                }
                mysqli_stmt_close($stmt);
            }
            mysqli_stmt_close($check_stmt);
        }
    }
}

// Handle Edit Prime Mover Submission - Only allow if user can manage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_prime_mover'])) {
    // Check permission
    if (!$can_manage_prime_movers) {
        $message = "You don't have permission to edit prime movers.";
        $message_type = 'error';
    } else {
        $prime_mover_id = intval($_POST['prime_mover_id']);
        
        $fields = [
            'plate_number'        => trim($_POST['plate_number'] ?? ''),
            'brand'               => trim($_POST['brand'] ?? ''),
            'model'               => trim($_POST['model'] ?? ''),
            'color'               => trim($_POST['color'] ?? ''),
            'engine_number'       => trim($_POST['engine_number'] ?? ''),
            'chassis_number'      => trim($_POST['chassis_number'] ?? ''),
            'status'              => trim($_POST['status'] ?? 'Active'),
            'notes'               => trim($_POST['notes'] ?? ''),
        ];

        // Required fields
        $required = ['plate_number', 'brand', 'model'];
        $errors = [];

        foreach ($required as $field) {
            if (empty($fields[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
            }
        }

        if (!empty($errors)) {
            $message = implode("<br>", $errors);
            $message_type = 'error';
        } else {
            $updated_by = $_SESSION['username'];
            $updated_at = date('Y-m-d H:i:s');

            $update_query = "
                UPDATE prime_movers_masterlist SET
                    plate_number = ?,
                    brand = ?,
                    model = ?,
                    color = ?,
                    engine_number = ?,
                    chassis_number = ?,
                    status = ?,
                    notes = ?,
                    updated_by = ?,
                    updated_at = ?
                WHERE id = ?
            ";

            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param(
                $stmt, "ssssssssssi",
                $fields['plate_number'],
                $fields['brand'],
                $fields['model'],
                $fields['color'],
                $fields['engine_number'],
                $fields['chassis_number'],
                $fields['status'],
                $fields['notes'],
                $updated_by,
                $updated_at,
                $prime_mover_id
            );

            if (mysqli_stmt_execute($stmt)) {
                $message = "Prime Mover <strong>" . htmlspecialchars($fields['plate_number']) . "</strong> updated successfully!";
                $message_type = 'success';
            } else {
                $message = "Database error: " . mysqli_error($conn);
                $message_type = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch prime movers with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT * FROM prime_movers_masterlist 
              WHERE prime_mover_code LIKE '%$search_term%' 
              OR plate_number LIKE '%$search_term%'
              OR brand LIKE '%$search_term%'
              OR model LIKE '%$search_term%'
              OR color LIKE '%$search_term%'
              OR engine_number LIKE '%$search_term%'
              OR chassis_number LIKE '%$search_term%'
              OR status LIKE '%$search_term%'
              ORDER BY prime_mover_code ASC";
} else {
    $query = "SELECT * FROM prime_movers_masterlist ORDER BY prime_mover_code ASC";
}

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prime Movers Masterlist | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/prime_movers.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Prime Movers Masterlist</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Permission Notice for View-Only Users -->
        <?php if (!$can_manage_prime_movers && $can_access_prime_mover): ?>
            <div class="permission-notice">
                <i data-lucide="eye"></i>
                <span>You are in <strong>View-Only</strong> mode. You can view prime mover records but cannot add or edit them.</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Prime Movers Fleet Directory</h1>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Code, Plate, Brand..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <?php if ($can_manage_prime_movers): ?>
                    <button id="openAddModal" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                        <i data-lucide="plus" style="width:18px;"></i> Register Prime Mover
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($result); ?> found)
                <a href="prime_movers.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
             <table>
                <thead>
                     <tr>
                        <th>Code</th>
                        <th>Plate Number</th>
                        <th>Brand & Model</th>
                        <th>Color</th>
                        <th>Engine / Chassis</th>
                        <th>Status</th>
                        <th>Action</th>
                     </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr data-prime-mover-id="<?php echo $row['id']; ?>">
                                <td style="font-weight:600; color:var(--accent-blue);"><?php echo htmlspecialchars($row['prime_mover_code'] ?? '—'); ?></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($row['plate_number'] ?? '—'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['color'] ?: '—'); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);">ID: <?php echo htmlspecialchars($row['prime_mover_code'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($row['color'])): ?>
                                        <span style="display:inline-block; width:16px; height:16px; border-radius:4px; background:<?php echo htmlspecialchars(strtolower($row['color'])); ?>; border:1px solid #e2e8f0; vertical-align:middle; margin-right:6px;"></span>
                                        <?php echo htmlspecialchars($row['color']); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:13px;">
                                        <span style="font-weight:500;">Engine:</span> <?php echo htmlspecialchars($row['engine_number'] ?: '—'); ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted);">
                                        <span style="font-weight:500;">Chassis:</span> <?php echo htmlspecialchars($row['chassis_number'] ?: '—'); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        $status = strtolower($row['status'] ?? 'active');
                                        $class = $status === 'active' ? 'status-active' : 
                                                 ($status === 'maintenance' ? 'status-maintenance' : 
                                                 ($status === 'retired' ? 'status-retired' : 'status-inactive'));
                                    ?>
                                    <span class="status-pill <?php echo $class; ?>">
                                        <?php echo htmlspecialchars($row['status'] ?: 'Active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($can_manage_prime_movers): ?>
                                        <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                            <i data-lucide="edit-2" style="width:18px;"></i>
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No prime movers found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="prime_movers.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all prime movers
                                    </a>
                                <?php else: ?>
                                    No prime movers found.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</main>

<!-- Add Prime Mover Modal -->
<div class="modal-overlay" id="addPrimeMoverModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Register New Prime Mover</h2>
            <button id="closeModal" style="background:#f1f5f9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;">×</button>
        </div>

        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section"><i data-lucide="info"></i> Basic Information</div>

                    <div class="form-group col-2">
                        <label>Prime Mover Code <span class="required">*</span></label>
                        <input type="text" name="prime_mover_code" value="<?php echo htmlspecialchars($next_code); ?>" readonly required>
                    </div>

                    <div class="form-group col-2">
                        <label>Plate Number <span class="required">*</span></label>
                        <input type="text" name="plate_number" required placeholder="ABC-1234">
                    </div>

                    <div class="form-group col-2">
                        <label>Brand <span class="required">*</span></label>
                        <input type="text" name="brand" required placeholder="Scania, Volvo, Mercedes...">
                    </div>

                    <div class="form-group col-2">
                        <label>Model <span class="required">*</span></label>
                        <input type="text" name="model" required placeholder="R-Series, FH, Actros...">
                    </div>

                    <div class="form-group col-2">
                        <label>Color</label>
                        <input type="text" name="color" placeholder="White, Blue, Red...">
                    </div>

                    <div class="form-section"><i data-lucide="settings"></i> Engine & Chassis Information</div>

                    <div class="form-group col-2">
                        <label>Engine Number</label>
                        <input type="text" name="engine_number" placeholder="Engine serial number">
                    </div>

                    <div class="form-group col-2">
                        <label>Chassis Number</label>
                        <input type="text" name="chassis_number" placeholder="Chassis / VIN number">
                    </div>

                    <div class="form-section"><i data-lucide="flag"></i> Status & Notes</div>

                    <div class="form-group col-2">
                        <label>Status</label>
                        <select name="status">
                            <option value="Active" selected>Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>

                    <div class="form-group col-6">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any remarks, special features, restrictions..."></textarea>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" id="closeModalBtn" class="btn btn-secondary">Cancel</button>
                <button type="submit" name="add_prime_mover" class="btn btn-save">Register Prime Mover</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Prime Mover Modal -->
<div class="modal-overlay" id="editPrimeMoverModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Edit Prime Mover</h2>
            <button id="closeEditModal" style="background:#f1f5f9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;">×</button>
        </div>

        <form method="POST" id="editForm">
            <input type="hidden" name="prime_mover_id" id="edit_prime_mover_id">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section"><i data-lucide="info"></i> Basic Information</div>

                    <div class="form-group col-2">
                        <label>Prime Mover Code</label>
                        <input type="text" id="edit_prime_mover_code" readonly style="background:#f8fafc; font-weight:600;">
                    </div>

                    <div class="form-group col-2">
                        <label>Plate Number <span class="required">*</span></label>
                        <input type="text" name="plate_number" id="edit_plate_number" required placeholder="ABC-1234">
                    </div>

                    <div class="form-group col-2">
                        <label>Brand <span class="required">*</span></label>
                        <input type="text" name="brand" id="edit_brand" required placeholder="Scania, Volvo, Mercedes...">
                    </div>

                    <div class="form-group col-2">
                        <label>Model <span class="required">*</span></label>
                        <input type="text" name="model" id="edit_model" required placeholder="R-Series, FH, Actros...">
                    </div>

                    <div class="form-group col-2">
                        <label>Color</label>
                        <input type="text" name="color" id="edit_color" placeholder="White, Blue, Red...">
                    </div>

                    <div class="form-section"><i data-lucide="settings"></i> Engine & Chassis Information</div>

                    <div class="form-group col-2">
                        <label>Engine Number</label>
                        <input type="text" name="engine_number" id="edit_engine_number" placeholder="Engine serial number">
                    </div>

                    <div class="form-group col-2">
                        <label>Chassis Number</label>
                        <input type="text" name="chassis_number" id="edit_chassis_number" placeholder="Chassis / VIN number">
                    </div>

                    <div class="form-section"><i data-lucide="flag"></i> Status & Notes</div>

                    <div class="form-group col-2">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>

                    <div class="form-group col-6">
                        <label>Notes</label>
                        <textarea name="notes" id="edit_notes" rows="2" placeholder="Any remarks, special features, restrictions..."></textarea>
                    </div>

                </div>
            </div>

            <div class="modal-footer">
                <button type="button" id="closeEditModalBtn" class="btn btn-secondary">Cancel</button>
                <button type="submit" name="edit_prime_mover" class="btn btn-save">Update Prime Mover</button>
            </div>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();

    // User roles from PHP
    const userRoles = <?php echo json_encode($user_roles); ?>;
    
    // Allowed pages from PHP
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    
    // Can manage prime movers
    const canManagePrimeMovers = <?php echo $can_manage_prime_movers ? 'true' : 'false'; ?>;
    
    // Modal element
    const modal = document.getElementById('accessModal');

    // Check access function
    function checkAccess(page) {
        // Admin has access to everything
        if (userRoles.includes('admin')) {
            return true;
        }
        
        // Check each role for access
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) {
                return true;
            }
        }
        
        // Show modal if not allowed
        modal.style.display = 'flex';
        return false; // Prevent navigation
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

    // ── Add Prime Mover Modal ───────────────────────────────────────────
    const addModal = document.getElementById('addPrimeMoverModal');
    const openAddBtn = document.getElementById('openAddModal');
    const closeAddBtns = [
        document.getElementById('closeModal'),
        document.getElementById('closeModalBtn')
    ];

    function openAddModal() { 
        // Check if user has permission
        if (!canManagePrimeMovers) {
            alert('You do not have permission to add prime movers.');
            return;
        }
        addModal.style.display = 'flex'; 
    }
    
    function closeAddModal() { addModal.style.display = 'none'; }

    openAddBtn?.addEventListener('click', openAddModal);
    closeAddBtns.forEach(btn => btn?.addEventListener('click', closeAddModal));

    addModal?.addEventListener('click', e => {
        if (e.target === addModal) closeAddModal();
    });

    // ── Edit Prime Mover Modal ───────────────────────────────────────────
    const editModal = document.getElementById('editPrimeMoverModal');
    const closeEditBtns = [
        document.getElementById('closeEditModal'),
        document.getElementById('closeEditModalBtn')
    ];

    function openEditModal(primeMoverData) {
        // Check if user has permission
        if (!canManagePrimeMovers) {
            alert('You do not have permission to edit prime movers.');
            return;
        }
        
        // Populate all form fields with prime mover data
        document.getElementById('edit_prime_mover_id').value = primeMoverData.id;
        document.getElementById('edit_prime_mover_code').value = primeMoverData.prime_mover_code || '';
        document.getElementById('edit_plate_number').value = primeMoverData.plate_number || '';
        document.getElementById('edit_brand').value = primeMoverData.brand || '';
        document.getElementById('edit_model').value = primeMoverData.model || '';
        document.getElementById('edit_color').value = primeMoverData.color || '';
        document.getElementById('edit_engine_number').value = primeMoverData.engine_number || '';
        document.getElementById('edit_chassis_number').value = primeMoverData.chassis_number || '';
        document.getElementById('edit_status').value = primeMoverData.status || 'Active';
        document.getElementById('edit_notes').value = primeMoverData.notes || '';
        
        editModal.style.display = 'flex';
        setTimeout(() => document.getElementById('edit_plate_number')?.focus(), 100);
    }

    function closeEditModal() {
        editModal.style.display = 'none';
    }

    closeEditBtns.forEach(btn => btn?.addEventListener('click', closeEditModal));

    editModal?.addEventListener('click', e => {
        if (e.target === editModal) closeEditModal();
    });

    // Make openEditModal available globally
    window.openEditModal = openEditModal;

    // Close modal with Escape key
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            if (addModal?.style.display === 'flex') closeAddModal();
            if (editModal?.style.display === 'flex') closeEditModal();
            if (modal?.style.display === 'flex') closeModal();
        }
    });
</script>
</body>
</html>