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

// Check for username error from session
$username_error = null;
$username_error_data = null;
if (isset($_SESSION['username_error'])) {
    $username_error = $_SESSION['username_error'];
    $username_error_data = $_SESSION['username_error_data'] ?? null;
    unset($_SESSION['username_error']);
    unset($_SESSION['username_error_data']);
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

// Access Control - Only admin can access this page directly
if (!$is_admin) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Users Management page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles (for sidebar access control)
// Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page
// Now using centralized hasAccess() function from access_control.php

// Function to get display name for roles (for the badge)
// Now using centralized getRoleDisplayName() function from access_control.php

$role_display_name = getRoleDisplayName($user_roles);

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Handle Add User form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $id_number   = trim($_POST['id_number'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $username_input = strtoupper(trim($_POST['username'] ?? ''));
    $password    = $_POST['password'] ?? '';

    // Handle multi-select user_type
    $user_type_arr = $_POST['user_type'] ?? [];
    if (!is_array($user_type_arr) || empty($user_type_arr)) {
        $user_type_arr = ['user'];
    }
    $user_type_val = implode(',', array_map('trim', $user_type_arr));

    $status = 'active';

    $full_name = trim("$first_name $middle_name $last_name");
    $full_name = preg_replace('/\s+/', ' ', $full_name);

    $errors = [];
    if (empty($id_number))        $errors[] = "ID Number is required.";
    if (empty($first_name))       $errors[] = "First name is required.";
    if (empty($last_name))        $errors[] = "Last name is required.";
    if (empty($username_input))   $errors[] = "Username is required.";
    if (strlen($password) < 6)    $errors[] = "Password must be at least 6 characters.";
    if (empty($user_type_arr))    $errors[] = "At least one role is required.";

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM all_users WHERE username = ?");
        $stmt->bind_param("s", $username_input);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            // Store error in session for modal display
            $_SESSION['username_error'] = "Username '" . htmlspecialchars($username_input) . "' already exists. Please choose a different username.";
            $_SESSION['username_error_data'] = [
                'id_number' => $id_number,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'username' => $username_input,
                'user_type' => $user_type_arr
            ];
            header("Location: all_users.php#username-error");
            exit;
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO all_users 
            (id_number, first_name, middle_name, last_name, full_name, username, password, user_type, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("sssssssss", 
            $id_number, $first_name, $middle_name, $last_name, $full_name, 
            $username_input, $hashed_password, $user_type_val, $status
        );

        if ($stmt->execute()) {
            $_SESSION['flash_message'] = [
                'text' => "User created successfully!",
                'type' => 'success'
            ];
            header("Location: all_users.php");
            exit;
        } else {
            $flash = [
                'text' => "Database error: " . $conn->error,
                'type' => 'error'
            ];
        }
        $stmt->close();
    } else {
        $flash = [
            'text' => implode("<br>", $errors),
            'type' => 'error'
        ];
    }
}

// Handle Edit User form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $edit_user_id = $_POST['user_id'];
    $id_number   = trim($_POST['id_number'] ?? '');
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $username_input = strtoupper(trim($_POST['username'] ?? ''));
    $status = trim($_POST['status'] ?? 'active');

    // Handle multi-select user_type
    $user_type_arr = $_POST['user_type'] ?? [];
    if (!is_array($user_type_arr) || empty($user_type_arr)) {
        $user_type_arr = ['user'];
    }
    $user_type_val = implode(',', array_map('trim', $user_type_arr));

    $full_name = trim("$first_name $middle_name $last_name");
    $full_name = preg_replace('/\s+/', ' ', $full_name);

    $errors = [];
    if (empty($id_number))        $errors[] = "ID Number is required.";
    if (empty($first_name))       $errors[] = "First name is required.";
    if (empty($last_name))        $errors[] = "Last name is required.";
    if (empty($username_input))   $errors[] = "Username is required.";
    if (empty($user_type_arr))    $errors[] = "At least one role is required.";

    if (empty($errors)) {
        // Check if username exists for other users
        $stmt = $conn->prepare("SELECT id FROM all_users WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $username_input, $edit_user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Username already exists for another user.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE all_users 
            SET id_number = ?, first_name = ?, middle_name = ?, last_name = ?, 
                full_name = ?, username = ?, user_type = ?, status = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssssi", 
            $id_number, $first_name, $middle_name, $last_name, 
            $full_name, $username_input, $user_type_val, $status, $edit_user_id
        );

        if ($stmt->execute()) {
            $_SESSION['flash_message'] = [
                'text' => "User updated successfully!",
                'type' => 'success'
            ];
            header("Location: all_users.php");
            exit;
        } else {
            $flash = [
                'text' => "Database error: " . $conn->error,
                'type' => 'error'
            ];
        }
        $stmt->close();
    } else {
        $flash = [
            'text' => implode("<br>", $errors),
            'type' => 'error'
        ];
    }
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch users with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT id, id_number, first_name, middle_name, last_name, full_name, username, user_type, status, created_at, last_online 
              FROM all_users 
              WHERE id_number LIKE '%$search_term%' 
              OR first_name LIKE '%$search_term%'
              OR middle_name LIKE '%$search_term%'
              OR last_name LIKE '%$search_term%'
              OR full_name LIKE '%$search_term%'
              OR username LIKE '%$search_term%'
              OR user_type LIKE '%$search_term%'
              ORDER BY created_at DESC";
} else {
    $query = "SELECT id, id_number, first_name, middle_name, last_name, full_name, username, user_type, status, created_at, last_online 
              FROM all_users ORDER BY created_at DESC";
}

$result = $conn->query($query);
$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/users.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Manage Users</span></span>
        </div>
        <div class="user-profile">
            <span class="badge badge-admin"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flashMessage">
                <?= $flash['text'] ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 24px; font-weight: 600; margin: 0;">User Directory</h1>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Name, ID, Username..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <a href="reset_password.php" class="reset-requests-link" style="display:flex; align-items:center; gap:8px; text-decoration: none; color: white; font-weight: 500; white-space: nowrap;">
                        <i data-lucide="key" style="width: 18px;"></i> Password Reset Requests
                    </a>
                    <button id="openAddModal" style="background: var(--accent-blue); color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; white-space: nowrap;">
                        <i data-lucide="user-plus" style="width: 18px;"></i> Add New User
                    </button>
                </div>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo count($users); ?> found)
                <a href="all_users.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID Number</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Online</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No users found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="all_users.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all users
                                    </a>
                                <?php else: ?>
                                    No users found in the system.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo htmlspecialchars($u['id_number']); ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td>
                                    <div class="badges-cell">
                                        <?php foreach (explode(',', $u['user_type']) as $role): 
                                            $role = trim($role);
                                            if (empty($role)) continue;
                                        ?>
                                            <span class="badge <?php echo getRoleBadgeClass($role); ?>">
                                                <?php echo htmlspecialchars(getReadableRoleName($role)); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="status-<?php echo strtolower($u['status'] ?? 'active'); ?>">
                                        <span style="font-size: 14px;">●</span> <?php echo ucfirst(htmlspecialchars($u['status'] ?? 'Active')); ?>
                                    </div>
                                </td>
                                <td style="color: var(--text-muted); font-size: 12px;">
                                    <?php echo $u['last_online'] ? date("M j, Y H:i", strtotime($u['last_online'])) : 'Never'; ?>
                                </td>
                                <td>
                                    <button class="action-btn edit-user-btn" data-user='<?php echo json_encode($u); ?>'>
                                        <i data-lucide="edit-3" style="width: 18px;"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Username Error Modal -->
<div class="modal-overlay" id="usernameErrorModal">
    <div class="access-modal">
        <i data-lucide="alert-circle" style="color: #dc2626; width: 48px; height: 48px;"></i>
        <h3 style="color: #dc2626;">Not Allowed Duplicate</h3>
        <p id="usernameErrorMessage" style="margin: 16px 0; color: #475569; font-size: 15px;"></p>
        <div id="usernameErrorData" style="display: none;"></div>
        <button class="modal-btn" id="usernameErrorCloseBtn">OK</button>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Create New User</div>
            <button class="modal-close" id="closeAddModal">×</button>
        </div>
        <form method="POST" id="addUserForm">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label for="add_id_number">ID Number *</label>
                        <input type="text" id="add_id_number" name="id_number" required>
                    </div>

                    <div class="form-group col-2">
                        <label for="add_first_name">First Name *</label>
                        <input type="text" id="add_first_name" name="first_name" required 
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-2">
                        <label for="add_middle_name">Middle Name</label>
                        <input type="text" id="add_middle_name" name="middle_name"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-2">
                        <label for="add_last_name">Last Name *</label>
                        <input type="text" id="add_last_name" name="last_name" required
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-6">
                        <label for="add_full_name">Full Name</label>
                        <input type="text" id="add_full_name" name="full_name_display" readonly style="background:#f8fafc; font-weight:500;">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_username">Username *</label>
                        <input type="text" id="add_username" name="username" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase();">
                    </div>

                    <div class="form-group password-wrapper col-3">
                        <label for="add_password">Password *</label>
                        <input type="text" id="add_password" name="password" required minlength="6" value="Oncall1234">
                        <button type="button" class="toggle-password" id="toggleAddPassword">
                            <i data-lucide="eye-off" id="addEyeIcon"></i>
                        </button>
                    </div>

                    <div class="form-group col-6">
                        <label>Role *</label>
                        <div class="multi-select-wrapper" id="addRoleDropdown">
                            <div class="multi-select-trigger" id="addRoleTrigger">
                                <span id="addRoleDisplay">Select role(s)...</span>
                                <i data-lucide="chevron-down" class="role-chevron"></i>
                            </div>
                            <div class="multi-select-dropdown" id="addRoleOptions">
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="admin"> Admin
                                </label>
                                <!-- <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="user"> User
                                </label> -->
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="purchase_order_maker"> Purchase Order Maker
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="sales_order_maker"> Sales Order Maker
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="service_invoice_maker"> Service Invoice Maker
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="customer_pricer"> Customer Pricer
                                </label>
                                <!-- NEW USER TYPES START -->
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="item_register"> Item Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="supplier_register"> Supplier Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="truck_register"> Truck Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="trailer_register"> Trailer Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="prime_mover_register"> Prime Mover Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="customer_register"> Customer Register
                                </label>
                                <!-- NEW USER TYPES END -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelAddModal">Cancel</button>
                <button type="submit" name="add_user" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Edit User</div>
            <button class="modal-close" id="closeEditModal">×</button>
        </div>
        <form method="POST" id="editUserForm">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group col-6">
                        <label for="edit_id_number">ID Number *</label>
                        <input type="text" id="edit_id_number" name="id_number" required>
                    </div>

                    <div class="form-group col-2">
                        <label for="edit_first_name">First Name *</label>
                        <input type="text" id="edit_first_name" name="first_name" required 
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-2">
                        <label for="edit_middle_name">Middle Name</label>
                        <input type="text" id="edit_middle_name" name="middle_name"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-2">
                        <label for="edit_last_name">Last Name *</label>
                        <input type="text" id="edit_last_name" name="last_name" required
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-6">
                        <label for="edit_full_name">Full Name</label>
                        <input type="text" id="edit_full_name" name="full_name_display" readonly style="background:#f8fafc; font-weight:500;">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_username">Username *</label>
                        <input type="text" id="edit_username" name="username" required
                               style="text-transform: uppercase;"
                               oninput="this.value = this.value.toUpperCase();">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_status">Status *</label>
                        <select id="edit_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group col-6">
                        <label>Role *</label>
                        <div class="multi-select-wrapper" id="editRoleDropdown">
                            <div class="multi-select-trigger" id="editRoleTrigger">
                                <span id="editRoleDisplay">Select role(s)...</span>
                                <i data-lucide="chevron-down" class="role-chevron"></i>
                            </div>
                            <div class="multi-select-dropdown" id="editRoleOptions">
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="admin"> Admin
                                </label>
                                <!-- <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="user"> User
                                </label> -->
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="purchase_order_maker"> Purchase Order Maker
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="sales_order_maker"> Sales Order Maker
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="service_invoice_maker"> Service Invoice Maker
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="customer_pricer"> Customer Pricer
                                </label>
                                <!-- NEW USER TYPES START -->
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="item_register"> Item Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="supplier_register"> Supplier Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="truck_register"> Truck Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="trailer_register"> Trailer Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="prime_mover_register"> Prime Mover Register
                                </label>
                                <label class="checkbox-option">
                                    <input type="checkbox" name="user_type[]" value="customer_register"> Customer Register
                                </label>
                                <!-- NEW USER TYPES END -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelEditModal">Cancel</button>
                <button type="submit" name="edit_user" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();

    // Flash message auto-dismiss and refresh
    <?php if ($flash): ?>
    setTimeout(function() {
        const flashMsg = document.getElementById('flashMessage');
        if (flashMsg) {
            flashMsg.classList.add('alert-fade-out');
            setTimeout(function() {
                window.location.reload();
            }, 500);
        } else {
            window.location.reload();
        }
    }, 5000);
    <?php endif; ?>

    // User roles from PHP
    const userRoles = <?php echo json_encode($user_roles); ?>;
    
    // Allowed pages from PHP
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    
    // Access Denied Modal
    const modal = document.getElementById('accessModal');

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
        return false;
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
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

    // Add User Modal
    const addModal = document.getElementById('addUserModal');
    const openAddBtn = document.getElementById('openAddModal');
    const closeAddBtn = document.getElementById('closeAddModal');
    const cancelAddBtn = document.getElementById('cancelAddModal');

    function openAddUserModal() { 
        addModal.style.display = 'flex'; 
    }

    function clearAddUserForm() {
        // Clear all input fields
        const addIdNumber = document.getElementById('add_id_number');
        const addFirstName = document.getElementById('add_first_name');
        const addMiddleName = document.getElementById('add_middle_name');
        const addLastName = document.getElementById('add_last_name');
        const addFullName = document.getElementById('add_full_name');
        const addUsername = document.getElementById('add_username');
        const addPassword = document.getElementById('add_password');
        
        if (addIdNumber) addIdNumber.value = '';
        if (addFirstName) addFirstName.value = '';
        if (addMiddleName) addMiddleName.value = '';
        if (addLastName) addLastName.value = '';
        if (addFullName) addFullName.value = '';
        if (addUsername) addUsername.value = '';
        if (addPassword) addPassword.value = 'Oncall1234';
        
        // Uncheck all role checkboxes
        const addRoleCheckboxes = document.querySelectorAll('#addRoleOptions input[type="checkbox"]');
        addRoleCheckboxes.forEach(cb => {
            cb.checked = false;
        });
        
        // Reset role display
        const addRoleDisplay = document.getElementById('addRoleDisplay');
        if (addRoleDisplay) {
            addRoleDisplay.textContent = 'Select role(s)...';
            addRoleDisplay.classList.remove('has-value');
        }
        
        // Reset password visibility to text (visible)
        const addPasswordInput = document.getElementById('add_password');
        const addEyeIcon = document.getElementById('addEyeIcon');
        if (addPasswordInput) {
            addPasswordInput.type = 'text';
        }
        if (addEyeIcon) {
            addEyeIcon.setAttribute('data-lucide', 'eye-off');
            lucide.createIcons();
        }
    }

    function closeAddUserModal() { 
        addModal.style.display = 'none';
        clearAddUserForm();
    }

    openAddBtn?.addEventListener('click', openAddUserModal);
    closeAddBtn?.addEventListener('click', closeAddUserModal);
    cancelAddBtn?.addEventListener('click', closeAddUserModal);

    addModal?.addEventListener('click', (e) => {
        if (e.target === addModal) closeAddUserModal();
    });

    // Auto-generate full name for Add Modal
    const addFirstNameInput = document.getElementById('add_first_name');
    const addMiddleNameInput = document.getElementById('add_middle_name');
    const addLastNameInput = document.getElementById('add_last_name');
    const addFullNameInput = document.getElementById('add_full_name');

    function updateAddFullName() {
        const parts = [
            (addFirstNameInput?.value || '').trim(),
            (addMiddleNameInput?.value || '').trim(),
            (addLastNameInput?.value || '').trim()
        ].filter(Boolean);
        if (addFullNameInput) addFullNameInput.value = parts.join(' ');
    }

    [addFirstNameInput, addMiddleNameInput, addLastNameInput].forEach(input => {
        input?.addEventListener('input', updateAddFullName);
    });

    // Password toggle for Add Modal - now starts with text visible
    const addPasswordInput = document.getElementById('add_password');
    const addToggleBtn = document.getElementById('toggleAddPassword');
    const addEyeIcon = document.getElementById('addEyeIcon');

    addToggleBtn?.addEventListener('click', () => {
        const isText = addPasswordInput.type === 'text';
        addPasswordInput.type = isText ? 'password' : 'text';
        addEyeIcon.setAttribute('data-lucide', isText ? 'eye' : 'eye-off');
        lucide.createIcons();
    });

    // Multi-select role dropdown for Add Modal
    const addRoleTrigger = document.getElementById('addRoleTrigger');
    const addRoleOptions = document.getElementById('addRoleOptions');
    const addRoleDisplay = document.getElementById('addRoleDisplay');
    const addRoleCheckboxes = addRoleOptions?.querySelectorAll('input[type="checkbox"]');

    addRoleTrigger?.addEventListener('click', (e) => {
        e.stopPropagation();
        addRoleOptions.classList.toggle('open');
        addRoleTrigger.classList.toggle('open');
    });

    function updateAddRoleDisplay() {
        const selected = [...addRoleCheckboxes].map(cb => {
            if (cb.checked) {
                let label = cb.value.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                // Custom labels for new user types
                const customLabels = {
                    'item_register': 'Item Register',
                    'supplier_register': 'Supplier Register',
                    'truck_register': 'Truck Register',
                    'trailer_register': 'Trailer Register',
                    'prime_mover_register': 'Prime Mover Register',
                    'customer_register': 'Customer Register'
                };
                if (customLabels[cb.value]) {
                    label = customLabels[cb.value];
                } else if (cb.value === 'purchase_order_maker') {
                    label = 'PO Maker';
                } else if (cb.value === 'sales_order_maker') {
                    label = 'SO Maker';
                } else if (cb.value === 'service_invoice_maker') {
                    label = 'SI Maker';
                } else if (cb.value === 'customer_pricer') {
                    label = 'Customer Pricer';
                }
                return label;
            }
            return null;
        }).filter(Boolean);
        
        if (selected.length === 0) {
            addRoleDisplay.textContent = 'Select role(s)...';
            addRoleDisplay.classList.remove('has-value');
        } else {
            addRoleDisplay.textContent = selected.join(', ');
            addRoleDisplay.classList.add('has-value');
        }
    }

    addRoleCheckboxes?.forEach(cb => cb.addEventListener('change', updateAddRoleDisplay));

    // Form validation for Add Modal with better error handling
    document.getElementById('addUserForm')?.addEventListener('submit', function(e) {
        const anyChecked = [...addRoleCheckboxes].some(c => c.checked);
        if (!anyChecked) {
            e.preventDefault();
            alert('Please select at least one role.');
            addRoleTrigger.click();
            return;
        }
        
        // Check if username field is empty
        const username = document.getElementById('add_username');
        if (!username || username.value.trim() === '') {
            e.preventDefault();
            alert('Username is required.');
            username?.focus();
            return;
        }
        
        // Password validation
        const password = document.getElementById('add_password');
        if (!password || password.value.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters.');
            password?.focus();
            return;
        }
    });

    // Edit User Modal functionality
    const editModal = document.getElementById('editUserModal');
    const closeEditBtn = document.getElementById('closeEditModal');
    const cancelEditBtn = document.getElementById('cancelEditModal');
    const editUserForm = document.getElementById('editUserForm');

    // Edit Modal fields
    const editUserId = document.getElementById('edit_user_id');
    const editIdNumber = document.getElementById('edit_id_number');
    const editFirstName = document.getElementById('edit_first_name');
    const editMiddleName = document.getElementById('edit_middle_name');
    const editLastName = document.getElementById('edit_last_name');
    const editFullName = document.getElementById('edit_full_name');
    const editUsername = document.getElementById('edit_username');
    const editStatus = document.getElementById('edit_status');
    
    // Edit Modal role elements
    const editRoleTrigger = document.getElementById('editRoleTrigger');
    const editRoleOptions = document.getElementById('editRoleOptions');
    const editRoleDisplay = document.getElementById('editRoleDisplay');
    const editRoleCheckboxes = editRoleOptions?.querySelectorAll('input[type="checkbox"]');

    // Setup Edit Modal role dropdown
    editRoleTrigger?.addEventListener('click', (e) => {
        e.stopPropagation();
        editRoleOptions.classList.toggle('open');
        editRoleTrigger.classList.toggle('open');
    });

    function updateEditRoleDisplay() {
        const selected = [...editRoleCheckboxes].map(cb => {
            if (cb.checked) {
                let label = cb.value.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                // Custom labels for new user types
                const customLabels = {
                    'item_register': 'Item Register',
                    'supplier_register': 'Supplier Register',
                    'truck_register': 'Truck Register',
                    'trailer_register': 'Trailer Register',
                    'prime_mover_register': 'Prime Mover Register',
                    'customer_register': 'Customer Register'
                };
                if (customLabels[cb.value]) {
                    label = customLabels[cb.value];
                } else if (cb.value === 'purchase_order_maker') {
                    label = 'PO Maker';
                } else if (cb.value === 'sales_order_maker') {
                    label = 'SO Maker';
                } else if (cb.value === 'service_invoice_maker') {
                    label = 'SI Maker';
                } else if (cb.value === 'customer_pricer') {
                    label = 'Customer Pricer';
                }
                return label;
            }
            return null;
        }).filter(Boolean);
        
        if (selected.length === 0) {
            editRoleDisplay.textContent = 'Select role(s)...';
            editRoleDisplay.classList.remove('has-value');
        } else {
            editRoleDisplay.textContent = selected.join(', ');
            editRoleDisplay.classList.add('has-value');
        }
    }

    editRoleCheckboxes?.forEach(cb => cb.addEventListener('change', updateEditRoleDisplay));

    // Close dropdown when clicking outside for Edit Modal
    document.addEventListener('click', (e) => {
        if (!editRoleTrigger?.contains(e.target) && !editRoleOptions?.contains(e.target)) {
            editRoleOptions?.classList.remove('open');
            editRoleTrigger?.classList.remove('open');
        }
    });

    // Auto-generate full name for Edit Modal
    function updateEditFullName() {
        const parts = [
            (editFirstName?.value || '').trim(),
            (editMiddleName?.value || '').trim(),
            (editLastName?.value || '').trim()
        ].filter(Boolean);
        if (editFullName) editFullName.value = parts.join(' ');
    }

    [editFirstName, editMiddleName, editLastName].forEach(input => {
        input?.addEventListener('input', updateEditFullName);
    });

    // Open Edit Modal with user data
    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const userData = JSON.parse(this.dataset.user);
            
            // Populate form fields
            editUserId.value = userData.id;
            editIdNumber.value = userData.id_number;
            editFirstName.value = userData.first_name;
            editMiddleName.value = userData.middle_name || '';
            editLastName.value = userData.last_name;
            editUsername.value = userData.username.toUpperCase();
            editStatus.value = userData.status || 'active';
            
            // Update full name
            updateEditFullName();
            
            // Set roles in checkboxes
            const userRoles = userData.user_type.split(',').map(r => r.trim());
            editRoleCheckboxes.forEach(cb => {
                cb.checked = userRoles.includes(cb.value);
            });
            
            // Update role display
            updateEditRoleDisplay();
            
            // Show modal
            editModal.style.display = 'flex';
        });
    });

    // Close Edit Modal
    function closeEditModal() {
        editModal.style.display = 'none';
    }

    closeEditBtn?.addEventListener('click', closeEditModal);
    cancelEditBtn?.addEventListener('click', closeEditModal);

    editModal?.addEventListener('click', (e) => {
        if (e.target === editModal) closeEditModal();
    });

    // Form validation for Edit Modal
    editUserForm?.addEventListener('submit', function(e) {
        const anyChecked = [...editRoleCheckboxes].some(c => c.checked);
        if (!anyChecked) {
            e.preventDefault();
            alert('Please select at least one role.');
            editRoleTrigger.click();
        }
    });

    // Username Error Modal handling
    const usernameErrorModal = document.getElementById('usernameErrorModal');
    const usernameErrorMessage = document.getElementById('usernameErrorMessage');
    const usernameErrorCloseBtn = document.getElementById('usernameErrorCloseBtn');
    
    // Check for username error and show modal
    <?php if ($username_error): ?>
        (function() {
            const errorMessage = <?php echo json_encode($username_error); ?>;
            const errorData = <?php echo json_encode($username_error_data); ?>;
            
            // Display error message
            if (usernameErrorMessage) {
                usernameErrorMessage.textContent = errorMessage;
            }
            
            // Show modal
            if (usernameErrorModal) {
                usernameErrorModal.style.display = 'flex';
                
                // Auto-close after 5 seconds
                setTimeout(function() {
                    closeUsernameErrorModal();
                }, 5000);
            }
            
            // Store form data for repopulation
            if (errorData) {
                // Pre-fill add user form with previous data
                const addIdNumber = document.getElementById('add_id_number');
                const addFirstName = document.getElementById('add_first_name');
                const addMiddleName = document.getElementById('add_middle_name');
                const addLastName = document.getElementById('add_last_name');
                const addUsername = document.getElementById('add_username');
                
                if (addIdNumber) addIdNumber.value = errorData.id_number || '';
                if (addFirstName) addFirstName.value = errorData.first_name || '';
                if (addMiddleName) addMiddleName.value = errorData.middle_name || '';
                if (addLastName) addLastName.value = errorData.last_name || '';
                if (addUsername) addUsername.value = errorData.username || '';
                
                // Update full name
                updateAddFullName();
                
                // Restore role checkboxes
                if (errorData.user_type && Array.isArray(errorData.user_type)) {
                    const addRoleCheckboxes = document.querySelectorAll('#addRoleOptions input[type="checkbox"]');
                    addRoleCheckboxes.forEach(cb => {
                        cb.checked = errorData.user_type.includes(cb.value);
                    });
                    updateAddRoleDisplay();
                }
            }
        })();
    <?php endif; ?>
    
    function closeUsernameErrorModal() {
        if (usernameErrorModal) {
            usernameErrorModal.classList.add('modal-fade-out');
            setTimeout(function() {
                usernameErrorModal.style.display = 'none';
                usernameErrorModal.classList.remove('modal-fade-out');
                // Remove hash from URL
                if (window.location.hash) {
                    history.pushState(null, null, window.location.pathname);
                }
                // Clear the form when error modal is closed
                clearAddUserForm();
            }, 500);
        }
    }
    
    // Close modal on button click
    usernameErrorCloseBtn?.addEventListener('click', function() {
        closeUsernameErrorModal();
    });
    
    // Close modal on backdrop click
    usernameErrorModal?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeUsernameErrorModal();
        }
    });
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (addModal?.style.display === 'flex') closeAddUserModal();
            if (editModal?.style.display === 'flex') closeEditModal();
            if (modal?.style.display === 'flex') closeModal();
            if (usernameErrorModal?.style.display === 'flex') closeUsernameErrorModal();
            addRoleOptions?.classList.remove('open');
            addRoleTrigger?.classList.remove('open');
            editRoleOptions?.classList.remove('open');
            editRoleTrigger?.classList.remove('open');
        }
    });
</script>
</body>
</html>