<?php
// employee_list.php
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

// Access Control - Only admin can access this page directly
if (!$is_admin) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Employee List page."
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

// Function to generate next employee code
function generateEmployeeCode($conn) {
    // Get the latest employee code
    $query = "SELECT employee_code FROM employee_list ORDER BY id DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_code = $row['employee_code'];
        // Extract the number from EMP-XXXXX
        $num = intval(substr($last_code, 4));
        $next_num = $num + 1;
    } else {
        // Start with 1 if no employees exist
        $next_num = 1;
    }
    
    // Format with leading zeros (5 digits)
    return 'EMP-' . str_pad($next_num, 5, '0', STR_PAD_LEFT);
}

// Handle Add Employee form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    // Auto-generate employee code
    $employee_code = generateEmployeeCode($conn);
    
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $date_hired = !empty($_POST['date_hired']) ? $_POST['date_hired'] : null;
    $date_separated = !empty($_POST['date_separated']) ? $_POST['date_separated'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Build full name with suffix
    $full_name_parts = array_filter([$first_name, $middle_name, $last_name]);
    $full_name = implode(' ', $full_name_parts);
    if (!empty($suffix)) {
        $full_name .= ' ' . $suffix;
    }
    $full_name = preg_replace('/\s+/', ' ', $full_name);
    
    $errors = [];
    if (empty($first_name)) $errors[] = "First name is required.";
    if (empty($last_name)) $errors[] = "Last name is required.";
    if (empty($position)) $errors[] = "Position is required.";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    
    if (empty($errors)) {
        $created_by = $username;
        
        $stmt = $conn->prepare("
            INSERT INTO employee_list 
            (employee_code, first_name, middle_name, last_name, suffix, full_name, email, contact_number, 
             department, position, location, status, date_hired, date_separated, notes, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->bind_param("ssssssssssssssss", 
            $employee_code, $first_name, $middle_name, $last_name, $suffix, $full_name, 
            $email, $contact_number, $department, $position, $location, $status, 
            $date_hired, $date_separated, $notes, $created_by
        );
        
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = [
                'text' => "Employee added successfully! Code: " . $employee_code,
                'type' => 'success'
            ];
            header("Location: employee_list.php");
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

// Handle Edit Employee form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_employee'])) {
    $edit_employee_id = $_POST['employee_id'];
    $employee_code = trim($_POST['employee_code'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $date_hired = !empty($_POST['date_hired']) ? $_POST['date_hired'] : null;
    $date_separated = !empty($_POST['date_separated']) ? $_POST['date_separated'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Build full name with suffix
    $full_name_parts = array_filter([$first_name, $middle_name, $last_name]);
    $full_name = implode(' ', $full_name_parts);
    if (!empty($suffix)) {
        $full_name .= ' ' . $suffix;
    }
    $full_name = preg_replace('/\s+/', ' ', $full_name);
    
    $errors = [];
    if (empty($employee_code)) $errors[] = "Employee ID is required.";
    if (empty($first_name)) $errors[] = "First name is required.";
    if (empty($last_name)) $errors[] = "Last name is required.";
    if (empty($position)) $errors[] = "Position is required.";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    
    if (empty($errors)) {
        // Check if employee code exists for other employees
        $stmt = $conn->prepare("SELECT id FROM employee_list WHERE employee_code = ? AND id != ?");
        $stmt->bind_param("si", $employee_code, $edit_employee_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Employee ID already exists for another employee.";
        }
        $stmt->close();
    }
    
    if (empty($errors)) {
        $updated_by = $username;
        
        $stmt = $conn->prepare("
            UPDATE employee_list 
            SET employee_code = ?, first_name = ?, middle_name = ?, last_name = ?, suffix = ?, full_name = ?,
                email = ?, contact_number = ?, department = ?, position = ?, location = ?, status = ?,
                date_hired = ?, date_separated = ?, notes = ?, updated_at = NOW(), updated_by = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssssssssssssi", 
            $employee_code, $first_name, $middle_name, $last_name, $suffix, $full_name,
            $email, $contact_number, $department, $position, $location, $status,
            $date_hired, $date_separated, $notes, $updated_by, $edit_employee_id
        );
        
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = [
                'text' => "Employee updated successfully!",
                'type' => 'success'
            ];
            header("Location: employee_list.php");
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

// Fetch employees with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT *, 
              DATE_FORMAT(updated_at, '%M %d, %Y %h:%i %p') as updated_at_formatted,
              DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') as created_at_formatted
              FROM employee_list 
              WHERE employee_code LIKE '%$search_term%' 
              OR first_name LIKE '%$search_term%'
              OR middle_name LIKE '%$search_term%'
              OR last_name LIKE '%$search_term%'
              OR full_name LIKE '%$search_term%'
              OR email LIKE '%$search_term%'
              OR department LIKE '%$search_term%'
              OR position LIKE '%$search_term%'
              OR location LIKE '%$search_term%'
              ORDER BY created_at DESC";
} else {
    $query = "SELECT *, 
              DATE_FORMAT(updated_at, '%M %d, %Y %h:%i %p') as updated_at_formatted,
              DATE_FORMAT(created_at, '%M %d, %Y %h:%i %p') as created_at_formatted
              FROM employee_list ORDER BY created_at DESC";
}

$result = $conn->query($query);
$employees = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Get the next employee code for display
$next_employee_code = generateEmployeeCode($conn);

// Common position list for dropdown
$common_positions = [
    'ACCOUNTANT' => 'Accountant',
    'ADMINISTRATIVE ASSISTANT' => 'Administrative Assistant',
    'BOOKKEEPER' => 'Bookkeeper',
    'CASHIER' => 'Cashier',
    'CLERK' => 'Clerk',
    'COORDINATOR' => 'Coordinator',
    'DISPATCHER' => 'Dispatcher',
    'DRIVER' => 'Driver',
    'FLEET MANAGER' => 'Fleet Manager',
    'HUMAN RESOURCES' => 'Human Resources',
    'INVENTORY CLERK' => 'Inventory Clerk',
    'LOGISTICS COORDINATOR' => 'Logistics Coordinator',
    'MAINTENANCE' => 'Maintenance',
    'MANAGER' => 'Manager',
    'MECHANIC' => 'Mechanic',
    'OPERATIONS MANAGER' => 'Operations Manager',
    'PURCHASING AGENT' => 'Purchasing Agent',
    'RECEPTIONIST' => 'Receptionist',
    'SALES REPRESENTATIVE' => 'Sales Representative',
    'SECURITY GUARD' => 'Security Guard',
    'SHIPPING CLERK' => 'Shipping Clerk',
    'SUPERVISOR' => 'Supervisor',
    'WAREHOUSE WORKER' => 'Warehouse Worker',
    'OTHER' => 'Other'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/employee_list.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Employee Masterlist</span></span>
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
            <div>
                <h1 style="font-size: 24px; font-weight: 600; margin: 0;">Employee Directory</h1>
                <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                    Next employee code: <strong style="color: var(--accent-blue);"><?php echo $next_employee_code; ?></strong>
                </div>
            </div>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Name, Code, Email, Department..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <button id="openAddModal" style="background: var(--accent-blue); color: white; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; white-space: nowrap;">
                    <i data-lucide="user-plus" style="width: 18px;"></i> Add Employee
                </button>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo count($employees); ?> found)
                <a href="employee_list.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No employees found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="employee_list.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all employees
                                    </a>
                                <?php else: ?>
                                    No employees found in the system.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $e): ?>
                            <tr>
                                <td>
                                    <span class="employee-code"><?php echo htmlspecialchars($e['employee_code']); ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($e['full_name']); ?></div>
                                    <?php if (!empty($e['suffix'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($e['suffix']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($e['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($e['email']); ?>" style="color: var(--accent-blue); text-decoration: none; font-size: 13px;">
                                            <?php echo htmlspecialchars($e['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 13px;">—</span>
                                    <?php endif; ?>
                                    <?php if (!empty($e['contact_number'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($e['contact_number']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-department"><?php echo htmlspecialchars($e['department'] ?: 'N/A'); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($e['position'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($e['location'] ?: 'N/A'); ?></td>
                                <td>
                                    <div class="status-<?php echo strtolower($e['status'] ?? 'active'); ?>">
                                        <span style="font-size: 14px;">●</span> <?php echo ucfirst(htmlspecialchars($e['status'] ?? 'Active')); ?>
                                    </div>
                                </td>
                                <td>
                                    <button class="action-btn edit-employee-btn" data-employee='<?php echo json_encode($e); ?>'>
                                        <i data-lucide="edit-3" style="width: 18px;"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Employee Count -->
        <div style="margin-top: 16px; font-size: 14px; color: var(--text-muted);">
            Total Employees: <strong><?php echo count($employees); ?></strong>
        </div>
    </div>
</main>

<!-- Add Employee Modal -->
<div class="modal-overlay" id="addEmployeeModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Add New Employee</div>
            <button class="modal-close" id="closeAddModal">×</button>
        </div>
        <form method="POST" id="addEmployeeForm">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group col-3">
                        <label for="add_employee_code">Employee ID</label>
                        <div class="code-input-wrapper">
                            <input type="text" id="add_employee_code" name="employee_code" 
                                   value="<?php echo $next_employee_code; ?>" 
                                   readonly>
                            <span class="auto-generate-badge">Auto</span>
                        </div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">
                            Automatically generated
                        </div>
                    </div>

                    <div class="form-group col-3">
                        <label for="add_first_name">First Name *</label>
                        <input type="text" id="add_first_name" name="first_name" required 
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_middle_name">Middle Name</label>
                        <input type="text" id="add_middle_name" name="middle_name"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_last_name">Last Name *</label>
                        <input type="text" id="add_last_name" name="last_name" required
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-2">
                        <label for="add_suffix">Suffix</label>
                        <input type="text" id="add_suffix" name="suffix" placeholder="e.g. Jr., Sr., III"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-4">
                        <label for="add_full_name">Full Name</label>
                        <input type="text" id="add_full_name" name="full_name_display" readonly style="background:#f8fafc; font-weight:500;">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_email">Email</label>
                        <input type="email" id="add_email" name="email">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_contact_number">Contact Number</label>
                        <input type="text" id="add_contact_number" name="contact_number">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_department">Department</label>
                        <input type="text" id="add_department" name="department"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_position">Position *</label>
                        <select id="add_position" name="position" required>
                            <option value="">Select Position</option>
                            <?php foreach ($common_positions as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-3">
                        <label for="add_location">Location</label>
                        <input type="text" id="add_location" name="location"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_status">Status</label>
                        <select id="add_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="on-leave">On Leave</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <div class="form-group col-3">
                        <label for="add_date_hired">Date Hired</label>
                        <input type="date" id="add_date_hired" name="date_hired">
                    </div>

                    <div class="form-group col-3">
                        <label for="add_date_separated">Date Separated</label>
                        <input type="date" id="add_date_separated" name="date_separated">
                    </div>

                    <div class="form-group col-6">
                        <label for="add_notes">Notes</label>
                        <input type="text" id="add_notes" name="notes" placeholder="Additional notes about the employee">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelAddModal">Cancel</button>
                <button type="submit" name="add_employee" class="btn btn-primary">Add Employee</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal-overlay" id="editEmployeeModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">Edit Employee</div>
            <button class="modal-close" id="closeEditModal">×</button>
        </div>
        <form method="POST" id="editEmployeeForm">
            <input type="hidden" name="employee_id" id="edit_employee_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group col-3">
                        <label for="edit_employee_code">Employee ID *</label>
                        <input type="text" id="edit_employee_code" name="employee_code" required 
                               style="text-transform: uppercase; font-weight:600; color:var(--accent-blue); background:#f8fafc;">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_first_name">First Name *</label>
                        <input type="text" id="edit_first_name" name="first_name" required 
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_middle_name">Middle Name</label>
                        <input type="text" id="edit_middle_name" name="middle_name"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_last_name">Last Name *</label>
                        <input type="text" id="edit_last_name" name="last_name" required
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-2">
                        <label for="edit_suffix">Suffix</label>
                        <input type="text" id="edit_suffix" name="suffix" placeholder="e.g. Jr., Sr., III"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-4">
                        <label for="edit_full_name">Full Name</label>
                        <input type="text" id="edit_full_name" name="full_name_display" readonly style="background:#f8fafc; font-weight:500;">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_contact_number">Contact Number</label>
                        <input type="text" id="edit_contact_number" name="contact_number">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_department">Department</label>
                        <input type="text" id="edit_department" name="department"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_position">Position *</label>
                        <select id="edit_position" name="position" required>
                            <option value="">Select Position</option>
                            <?php foreach ($common_positions as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_location">Location</label>
                        <input type="text" id="edit_location" name="location"
                            oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="on-leave">On Leave</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_date_hired">Date Hired</label>
                        <input type="date" id="edit_date_hired" name="date_hired">
                    </div>

                    <div class="form-group col-3">
                        <label for="edit_date_separated">Date Separated</label>
                        <input type="date" id="edit_date_separated" name="date_separated">
                    </div>

                    <div class="form-group col-6">
                        <label for="edit_notes">Notes</label>
                        <input type="text" id="edit_notes" name="notes" placeholder="Additional notes about the employee">
                    </div>

                    <!-- Audit Trail Section -->
                    <div class="form-group col-6">
                        <label style="font-size: 13px; color: var(--text-muted); font-weight: 600; margin-bottom: 8px; display: block; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                            <i data-lucide="clock" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle; margin-right: 6px;"></i>
                            Audit Trail
                        </label>
                        <div class="audit-trail">
                            <div class="audit-row">
                                <span class="audit-label">Created By</span>
                                <span class="audit-value" id="edit_created_by">-</span>
                            </div>
                            <div class="audit-row">
                                <span class="audit-label">Created At</span>
                                <span class="audit-value" id="edit_created_at">-</span>
                            </div>
                            <div class="audit-row">
                                <span class="audit-label">Last Updated By</span>
                                <span class="audit-value" id="edit_updated_by">-</span>
                            </div>
                            <div class="audit-row">
                                <span class="audit-label">Last Updated At</span>
                                <span class="audit-value" id="edit_updated_at">-</span>
                            </div>
                        </div>
                        <input type="hidden" name="updated_by" id="edit_updated_by_hidden">
                        <input type="hidden" name="updated_at" id="edit_updated_at_hidden">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelEditModal">Cancel</button>
                <button type="submit" name="edit_employee" class="btn btn-primary">Save Changes</button>
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
    }, 3000);
    <?php endif; ?>

    // User roles from PHP
    const userRoles = <?php echo json_encode($user_roles); ?>;
    
    // Allowed pages from PHP
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    
    // Access Denied Modal
    const modal = document.getElementById('accessModal');

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
        if (e.target === modal) closeModal();
    });

    // ── Search Functionality ──────────────────────────────────────
    const searchInput = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearSearch');
    const searchForm = searchInput?.closest('form');

    let searchTimeout;
    searchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.trim() === '' && window.location.search.includes('search=')) {
                window.location.href = window.location.pathname;
            } else if (this.value.trim() !== '') {
                searchForm?.submit();
            }
        }, 300);
    });

    clearBtn?.addEventListener('click', function() {
        searchInput.value = '';
        this.classList.remove('visible');
        window.location.href = window.location.pathname;
    });

    searchInput?.addEventListener('input', function() {
        if (this.value.trim() !== '') {
            clearBtn?.classList.add('visible');
        } else {
            clearBtn?.classList.remove('visible');
        }
    });

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

    // ── Add Employee Modal ──────────────────────────────────────
    const addModal = document.getElementById('addEmployeeModal');
    const openAddBtn = document.getElementById('openAddModal');
    const closeAddBtn = document.getElementById('closeAddModal');
    const cancelAddBtn = document.getElementById('cancelAddModal');
    const addEmployeeForm = document.getElementById('addEmployeeForm');

    function openAddEmployeeModal() { 
        addModal.style.display = 'flex';
        // Refresh the employee code when opening the modal
        fetch('get_next_employee_code.php')
            .then(response => response.json())
            .then(data => {
                if (data.code) {
                    document.getElementById('add_employee_code').value = data.code;
                }
            })
            .catch(() => {
                // Fallback: use the PHP-generated value already in the input
            });
    }

    // Check if any field in the add form has a value (excluding the auto-generated employee code)
    function isAddFormDirty() {
        const fields = [
            'add_first_name',
            'add_middle_name',
            'add_last_name',
            'add_suffix',
            'add_email',
            'add_contact_number',
            'add_department',
            'add_position',
            'add_location',
            'add_status',
            'add_date_hired',
            'add_date_separated',
            'add_notes'
        ];
        for (const id of fields) {
            const el = document.getElementById(id);
            if (!el) continue;
            if (el.tagName === 'SELECT') {
                // Status has a default value 'active', so treat it as dirty only if non-default
                if (id === 'add_status') {
                    if (el.value && el.value !== 'active') return true;
                } else if (el.value) {
                    return true;
                }
            } else if (el.value && el.value.trim() !== '') {
                return true;
            }
        }
        return false;
    }

    function resetAddForm() {
        addEmployeeForm.reset();
        const fullNameInput = document.getElementById('add_full_name');
        if (fullNameInput) fullNameInput.value = '';
    }

    function closeAddEmployeeModal(skipConfirm = false) {
        if (!skipConfirm && isAddFormDirty()) {
            const confirmDiscard = confirm("You have unsaved employee data. Are you sure you want to cancel adding this employee?\n\nClick OK to discard and close, or Cancel to keep editing.");
            if (!confirmDiscard) {
                return; // Keep the modal open
            }
        }
        resetAddForm();
        addModal.style.display = 'none';
    }

    openAddBtn?.addEventListener('click', openAddEmployeeModal);
    closeAddBtn?.addEventListener('click', () => closeAddEmployeeModal());
    cancelAddBtn?.addEventListener('click', () => closeAddEmployeeModal());

    addModal?.addEventListener('click', (e) => {
        if (e.target === addModal) closeAddEmployeeModal();
    });

    // Reset form when the modal is opened fresh
    openAddBtn?.addEventListener('click', function() {
        resetAddForm();
    });

    // Auto-generate full name for Add Modal
    const addFirstNameInput = document.getElementById('add_first_name');
    const addMiddleNameInput = document.getElementById('add_middle_name');
    const addLastNameInput = document.getElementById('add_last_name');
    const addSuffixInput = document.getElementById('add_suffix');
    const addFullNameInput = document.getElementById('add_full_name');

    function updateAddFullName() {
        const parts = [
            (addFirstNameInput?.value || '').trim(),
            (addMiddleNameInput?.value || '').trim(),
            (addLastNameInput?.value || '').trim()
        ].filter(Boolean);
        let fullName = parts.join(' ');
        const suffix = (addSuffixInput?.value || '').trim();
        if (suffix) {
            fullName += ' ' + suffix;
        }
        if (addFullNameInput) addFullNameInput.value = fullName;
    }

    [addFirstNameInput, addMiddleNameInput, addLastNameInput, addSuffixInput].forEach(input => {
        input?.addEventListener('input', updateAddFullName);
    });

    // ── Edit Employee Modal ──────────────────────────────────────
    const editModal = document.getElementById('editEmployeeModal');
    const closeEditBtn = document.getElementById('closeEditModal');
    const cancelEditBtn = document.getElementById('cancelEditModal');
    const editEmployeeForm = document.getElementById('editEmployeeForm');

    // Edit Modal fields
    const editEmployeeId = document.getElementById('edit_employee_id');
    const editEmployeeCode = document.getElementById('edit_employee_code');
    const editFirstName = document.getElementById('edit_first_name');
    const editMiddleName = document.getElementById('edit_middle_name');
    const editLastName = document.getElementById('edit_last_name');
    const editSuffix = document.getElementById('edit_suffix');
    const editFullName = document.getElementById('edit_full_name');
    const editEmail = document.getElementById('edit_email');
    const editContactNumber = document.getElementById('edit_contact_number');
    const editDepartment = document.getElementById('edit_department');
    const editPosition = document.getElementById('edit_position');
    const editLocation = document.getElementById('edit_location');
    const editStatus = document.getElementById('edit_status');
    const editDateHired = document.getElementById('edit_date_hired');
    const editDateSeparated = document.getElementById('edit_date_separated');
    const editNotes = document.getElementById('edit_notes');
    
    // Audit trail fields
    const editCreatedBy = document.getElementById('edit_created_by');
    const editCreatedAt = document.getElementById('edit_created_at');
    const editUpdatedBy = document.getElementById('edit_updated_by');
    const editUpdatedAt = document.getElementById('edit_updated_at');

    function updateEditFullName() {
        const parts = [
            (editFirstName?.value || '').trim(),
            (editMiddleName?.value || '').trim(),
            (editLastName?.value || '').trim()
        ].filter(Boolean);
        let fullName = parts.join(' ');
        const suffix = (editSuffix?.value || '').trim();
        if (suffix) {
            fullName += ' ' + suffix;
        }
        if (editFullName) editFullName.value = fullName;
    }

    [editFirstName, editMiddleName, editLastName, editSuffix].forEach(input => {
        input?.addEventListener('input', updateEditFullName);
    });

    // Open Edit Modal with employee data
    document.querySelectorAll('.edit-employee-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const employeeData = JSON.parse(this.dataset.employee);
            
            editEmployeeId.value = employeeData.id;
            editEmployeeCode.value = employeeData.employee_code;
            editFirstName.value = employeeData.first_name;
            editMiddleName.value = employeeData.middle_name || '';
            editLastName.value = employeeData.last_name;
            editSuffix.value = employeeData.suffix || '';
            editEmail.value = employeeData.email;
            editContactNumber.value = employeeData.contact_number || '';
            editDepartment.value = employeeData.department || '';
            editPosition.value = employeeData.position || '';
            editLocation.value = employeeData.location || '';
            editStatus.value = employeeData.status || 'active';
            editDateHired.value = employeeData.date_hired || '';
            editDateSeparated.value = employeeData.date_separated || '';
            editNotes.value = employeeData.notes || '';
            
            // Set audit trail data
            editCreatedBy.textContent = employeeData.created_by || '-';
            editCreatedAt.textContent = employeeData.created_at_formatted || employeeData.created_at || '-';
            editUpdatedBy.textContent = employeeData.updated_by || 'Never';
            editUpdatedAt.textContent = employeeData.updated_at_formatted || employeeData.updated_at || 'Never';
            
            updateEditFullName();
            
            editModal.style.display = 'flex';
        });
    });

    function closeEditModal() {
        editModal.style.display = 'none';
    }

    closeEditBtn?.addEventListener('click', closeEditModal);
    cancelEditBtn?.addEventListener('click', closeEditModal);

    editModal?.addEventListener('click', (e) => {
        if (e.target === editModal) closeEditModal();
    });

    // ── Escape key closes all modals ────────────────────────────
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (addModal?.style.display === 'flex') closeAddEmployeeModal();
            if (editModal?.style.display === 'flex') closeEditModal();
            if (modal?.style.display === 'flex') closeModal();
        }
    });
</script>
</body>
</html>