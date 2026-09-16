<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ .  '/../config/access_control.php'; // Include centralized access control

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

// ============================================================
// CUSTOMER PAGE PERMISSIONS
// ============================================================

// Users who can VIEW the Customer Masterlist
$can_view_customer =
    $is_admin ||
    in_array('user', $user_roles) ||
    in_array('customer_pricer', $user_roles) ||
    in_array('sales_order_maker', $user_roles) ||
    in_array('customer_register', $user_roles);

// Users who can REGISTER or EDIT customers
$can_manage_customer =
    $is_admin ||
    in_array('user', $user_roles) ||
    in_array('customer_register', $user_roles);

// Block users who cannot view the Customer Masterlist
if (!$can_view_customer) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Customer page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);

// Generate next customer code (CUST-00001 style)
$next_code = 'CUST-00001';

$code_query = "SELECT customer_code FROM customer_masterlist 
               WHERE customer_code LIKE 'CUST-%' 
               ORDER BY CAST(SUBSTRING(customer_code, 6) AS UNSIGNED) DESC 
               LIMIT 1";

$code_result = mysqli_query($conn, $code_query);

if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
    $last_code = $row['customer_code'];
    $last_number = (int) substr($last_code, 5);
    $next_number = $last_number + 1;
    $next_code = sprintf('CUST-%05d', $next_number);
}

// Fetch payment terms from general_settings
$payment_terms_query = "SELECT terms FROM general_settings WHERE terms_by = 'Customer Term' ORDER BY terms ASC";
$payment_terms_result = mysqli_query($conn, $payment_terms_query);
$payment_terms = [];
while ($row = mysqli_fetch_assoc($payment_terms_result)) {
    $payment_terms[] = $row['terms'];
}

// Handle messages from session (after redirect)
$message = '';
$message_type = '';

if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Helper function to uppercase text fields (except email)
function uppercaseFields($data) {
    $uppercase_fields = ['company_name', 'tin', 'first_name', 'middle_name', 'last_name', 
                         'contact_person', 'position', 'contact_number', 'street', 
                         'barangay', 'town_municipality', 'province', 'postal_code', 'country',
                         'id_type', 'id_number', 'notes', 'full_address', 'payment_terms'];
    
    foreach ($uppercase_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = strtoupper(trim($data[$field]));
        }
    }
    
    // Email should be lowercase
    if (isset($data['email']) && !empty($data['email'])) {
        $data['email'] = strtolower(trim($data['email']));
    }
    
    return $data;
}

// Handle form submission for adding customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {

    // Server-side permission check: only admin/user/customer_register can register customers
    if (!$can_manage_customer) {
        $_SESSION['message'] = "You don't have permission to register a customer.";
        $_SESSION['message_type'] = 'error';
        header("Location: customer.php");
        exit;
    }

    $fields = [
        'customer_code'     => $next_code,
        'customer_type'     => trim($_POST['customer_type'] ?? ''),
        'company_name'      => trim($_POST['company_name'] ?? ''),
        'tin'               => trim($_POST['tin'] ?? ''),
        'first_name'        => trim($_POST['first_name'] ?? ''),
        'middle_name'       => trim($_POST['middle_name'] ?? ''),
        'last_name'         => trim($_POST['last_name'] ?? ''),
        'contact_person'    => trim($_POST['contact_person'] ?? ''),
        'position'          => trim($_POST['position'] ?? ''),
        'contact_number'    => trim($_POST['contact_number'] ?? ''),
        'email'             => trim($_POST['email'] ?? ''),
        'street'            => trim($_POST['street'] ?? ''),
        'barangay'          => trim($_POST['barangay'] ?? ''),
        'town_municipality' => trim($_POST['town_municipality'] ?? ''),
        'province'          => trim($_POST['province'] ?? ''),
        'postal_code'       => trim($_POST['postal_code'] ?? ''),
        'country'           => trim($_POST['country'] ?? 'Philippines'),
        'id_type'           => trim($_POST['id_type'] ?? ''),
        'id_number'         => trim($_POST['id_number'] ?? ''),
        'status'            => trim($_POST['status'] ?? 'Active'),
        'customer_since'    => trim($_POST['customer_since'] ?? date('Y-m-d')),
        'loyalty_tier'      => trim($_POST['loyalty_tier'] ?? 'Standard'),
        'payment_terms'     => trim($_POST['payment_terms'] ?? ''),
        'notes'             => trim($_POST['notes'] ?? ''),
        'full_address'      => trim($_POST['full_address'] ?? ''),
        'with_special_process' => isset($_POST['with_special_process']) ? 1 : 0,
    ];

    // Apply uppercase transformation to text fields (email to lowercase)
    $fields = uppercaseFields($fields);

    // Build full_name
    if ($fields['customer_type'] === 'Individual') {
        $name_parts = array_filter([
            $fields['first_name'],
            $fields['middle_name'],
            $fields['last_name']
        ]);
        $fields['full_name'] = implode(' ', $name_parts);
    } else {
        $fields['full_name'] = $fields['company_name'];
    }

    // Required fields
    $required = ['customer_type', 'contact_number', 'status'];

    if ($fields['customer_type'] === 'Company') {
        $required[] = 'company_name';
    } elseif ($fields['customer_type'] === 'Individual') {
        $required[] = 'first_name';
        $required[] = 'last_name';
    }

    $errors = [];
    foreach ($required as $field) {
        if (empty($fields[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['message'] = implode("<br>", $errors);
        $_SESSION['message_type'] = 'error';
        header("Location: customer.php");
        exit;
    } else {
        $created_by = $_SESSION['username'] ?? 'System';
        $created_at = date('Y-m-d H:i:s');

        $insert_query = "
            INSERT INTO customer_masterlist (
                customer_code, customer_type, company_name, tin, 
                first_name, middle_name, last_name, full_name,
                contact_person, position, contact_number, email,
                street, barangay, town_municipality, province, 
                postal_code, country, full_address,
                id_type, id_number, status, customer_since, 
                loyalty_tier, payment_terms, notes, with_special_process, created_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param(
            $stmt, "ssssssssssssssssssssssssssssi",   // 28 's' + 1 'i'
            $fields['customer_code'],
            $fields['customer_type'], $fields['company_name'], $fields['tin'],
            $fields['first_name'], $fields['middle_name'], $fields['last_name'], $fields['full_name'],
            $fields['contact_person'], $fields['position'], $fields['contact_number'], $fields['email'],
            $fields['street'], $fields['barangay'], $fields['town_municipality'],
            $fields['province'], $fields['postal_code'], $fields['country'],
            $fields['full_address'],
            $fields['id_type'], $fields['id_number'],
            $fields['status'], $fields['customer_since'],
            $fields['loyalty_tier'], $fields['payment_terms'], $fields['notes'],
            $fields['with_special_process'],
            $created_at, $created_by
        );

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['message'] = "Customer added successfully!";
            $_SESSION['message_type'] = 'success';
            header("Location: customer.php");
            exit;
        } else {
            $_SESSION['message'] = "Database error: " . mysqli_error($conn);
            $_SESSION['message_type'] = 'error';
            header("Location: customer.php");
            exit;
        }

        mysqli_stmt_close($stmt);
    }
}

// Handle Edit Customer Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_customer'])) {

    // Server-side permission check: only admin/user/customer_register can edit customers
    if (!$can_manage_customer) {
        $_SESSION['message'] = "You don't have permission to edit a customer.";
        $_SESSION['message_type'] = 'error';
        header("Location: customer.php");
        exit;
    }

    $customer_id = intval($_POST['customer_id']);
    
    $fields = [
        'customer_type'     => trim($_POST['customer_type'] ?? ''),
        'company_name'      => trim($_POST['company_name'] ?? ''),
        'tin'               => trim($_POST['tin'] ?? ''),
        'first_name'        => trim($_POST['first_name'] ?? ''),
        'middle_name'       => trim($_POST['middle_name'] ?? ''),
        'last_name'         => trim($_POST['last_name'] ?? ''),
        'contact_person'    => trim($_POST['contact_person'] ?? ''),
        'position'          => trim($_POST['position'] ?? ''),
        'contact_number'    => trim($_POST['contact_number'] ?? ''),
        'email'             => trim($_POST['email'] ?? ''),
        'street'            => trim($_POST['street'] ?? ''),
        'barangay'          => trim($_POST['barangay'] ?? ''),
        'town_municipality' => trim($_POST['town_municipality'] ?? ''),
        'province'          => trim($_POST['province'] ?? ''),
        'postal_code'       => trim($_POST['postal_code'] ?? ''),
        'country'           => trim($_POST['country'] ?? 'Philippines'),
        'id_type'           => trim($_POST['id_type'] ?? ''),
        'id_number'         => trim($_POST['id_number'] ?? ''),
        'status'            => trim($_POST['status'] ?? 'Active'),
        'customer_since'    => trim($_POST['customer_since'] ?? date('Y-m-d')),
        'loyalty_tier'      => trim($_POST['loyalty_tier'] ?? 'Standard'),
        'payment_terms'     => trim($_POST['payment_terms'] ?? ''),
        'notes'             => trim($_POST['notes'] ?? ''),
        'full_address'      => trim($_POST['full_address'] ?? ''),
        'with_special_process' => isset($_POST['with_special_process']) ? 1 : 0,
    ];

    // Apply uppercase transformation to text fields (email to lowercase)
    $fields = uppercaseFields($fields);

    // Build full_name
    if ($fields['customer_type'] === 'Individual') {
        $name_parts = array_filter([
            $fields['first_name'],
            $fields['middle_name'],
            $fields['last_name']
        ]);
        $fields['full_name'] = implode(' ', $name_parts);
    } else {
        $fields['full_name'] = $fields['company_name'];
    }

    // Required fields
    $required = ['customer_type', 'contact_number', 'status'];

    if ($fields['customer_type'] === 'Company') {
        $required[] = 'company_name';
    } elseif ($fields['customer_type'] === 'Individual') {
        $required[] = 'first_name';
        $required[] = 'last_name';
    }

    $errors = [];
    foreach ($required as $field) {
        if (empty($fields[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
        }
    }

    if (!empty($errors)) {
        $_SESSION['message'] = implode("<br>", $errors);
        $_SESSION['message_type'] = 'error';
        header("Location: customer.php");
        exit;
    } else {
        $update_query = "
            UPDATE customer_masterlist SET
                customer_type = ?, company_name = ?, tin = ?,
                first_name = ?, middle_name = ?, last_name = ?, full_name = ?,
                contact_person = ?, position = ?, contact_number = ?, email = ?,
                street = ?, barangay = ?, town_municipality = ?, province = ?,
                postal_code = ?, country = ?, full_address = ?,
                id_type = ?, id_number = ?, status = ?, customer_since = ?,
                loyalty_tier = ?, payment_terms = ?, notes = ?, with_special_process = ?
            WHERE id = ?
        ";

        $stmt = mysqli_prepare($conn, $update_query);

mysqli_stmt_bind_param(
    $stmt, "ssssssssssssssssssssssssssi", // 26 's' + 1 'i'
    $fields['customer_type'],
    $fields['company_name'],
    $fields['tin'],
    $fields['first_name'],
    $fields['middle_name'],
    $fields['last_name'],
    $fields['full_name'],
    $fields['contact_person'],
    $fields['position'],
    $fields['contact_number'],
    $fields['email'],
    $fields['street'],
    $fields['barangay'],
    $fields['town_municipality'],
    $fields['province'],
    $fields['postal_code'],
    $fields['country'],
    $fields['full_address'],
    $fields['id_type'],
    $fields['id_number'],
    $fields['status'],
    $fields['customer_since'],
    $fields['loyalty_tier'],
    $fields['payment_terms'],
    $fields['notes'],
    $fields['with_special_process'],
    $customer_id
);

        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['message'] = "Customer updated successfully!";
            $_SESSION['message_type'] = 'success';
            header("Location: customer.php");
            exit;
        } else {
            $_SESSION['message'] = "Database error: " . mysqli_error($conn);
            $_SESSION['message_type'] = 'error';
            header("Location: customer.php");
            exit;
        }

        mysqli_stmt_close($stmt);
    }
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch customers with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT * FROM customer_masterlist 
              WHERE customer_code LIKE '%$search_term%' 
              OR full_name LIKE '%$search_term%'
              OR company_name LIKE '%$search_term%'
              OR contact_number LIKE '%$search_term%'
              OR email LIKE '%$search_term%'
              ORDER BY full_name ASC";
} else {
    $query = "SELECT * FROM customer_masterlist ORDER BY full_name ASC";
}

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Masterlist | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/customer.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Customer Masterlist</span></span>
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
        <?php if (!$can_manage_customer): ?>
            <div class="permission-notice">
                <i data-lucide="eye"></i>
                <span>You are in <strong>View-Only</strong> mode. You can view customer records but cannot add or edit customers.</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Customers Directory</h1>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 300px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Code, Name, or Contact..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <?php if ($can_manage_customer): ?>
                    <button id="openAddModal" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                        <i data-lucide="plus" style="width:18px;"></i> Add Customer
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($result); ?> found)
                <a href="customer.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
             <table>
                <thead>
                     <tr>
                        <th>Customer Code</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th>Terms</th>
                        <th>Status</th>
                        <th>Special Process</th>
                        <th>Action</th>
                     </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr data-customer-id="<?php echo $row['id']; ?>">
                                <td style="font-weight:600; color:var(--accent-blue);">
                                    <?php echo htmlspecialchars($row['customer_code'] ?: '—'); ?>
                                 </td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars($row['full_name'] ?: '—'); ?></div>
                                    <?php if ($row['company_name'] && $row['customer_type'] === 'Company'): ?>
                                        <div style="font-size:12px; color:var(--text-muted);">TIN: <?php echo htmlspecialchars($row['tin'] ?: 'N/A'); ?></div>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <div style="font-size:13px;"><?php echo htmlspecialchars($row['contact_number'] ?: '—'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['email'] ?: '—'); ?></div>
                                 </td>
                                <td><?php echo htmlspecialchars($row['town_municipality'] . ($row['province'] ? ', ' . $row['province'] : '')) ?: '—'; ?></td>
                                <td>
    <?php 
    $payment_term = $row['payment_terms'] ?: '—';
    if ($payment_term !== '—' && is_numeric($payment_term)) {
        echo htmlspecialchars($payment_term) . ' DAYS';
    } else {
        echo htmlspecialchars($payment_term);
    }
    ?>
</td>
                                <td>
                                    <span class="status-pill <?php echo strtolower($row['status']) === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo htmlspecialchars($row['status'] ?: 'Unknown'); ?>
                                    </span>
                                 </td>
                                <td>
                                    <?php if (!empty($row['with_special_process']) && $row['with_special_process'] == 1): ?>
                                        <span class="status-pill" style="background:#bdeaff; color:#071f33; border:1px solid #207fab; display:inline-flex; align-items:center; gap:4px;">
                                            <i data-lucide="alert-triangle" style="width:12px; height:12px;"></i>
                                            YES
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <?php if ($can_manage_customer): ?>
                                        <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="Edit Customer">
                                            <i data-lucide="edit-2" style="width:18px;"></i> View / Edit
                                        </button>
                                    <?php else: ?>
                                        <button class="action-btn edit-btn" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="View Customer">
                                            <i data-lucide="eye" style="width:18px;"></i> View
                                        </button>
                                    <?php endif; ?>
                                 </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No customers found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="customer.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all customers
                                    </a>
                                <?php else: ?>
                                    No customers found.
                                <?php endif; ?>
                             </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</main>

<!-- Add Customer Modal -->
<div class="modal-overlay" id="addCustomerModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--sidebar-dark);">Register New Customer</h2>
            <button id="closeModal" style="background:#f1f5f9; border:none; width:36px; height:36px; border-radius:50%; cursor:pointer; color:#64748b; font-size:20px; line-height:1;">×</button>
        </div>

        <form method="POST" action="">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section">
                        <i data-lucide="info"></i> Customer Type & Status
                    </div>

                    <div class="col-span-6">
                        <label>Customer Type <span class="required">*</span></label>
                        <select name="customer_type" id="customerType" required>
                            <option value="">Select type</option>
                            <option value="Individual">Individual</option>
                            <option value="Company">Company / Business</option>
                        </select>
                    </div>

                    <div class="col-span-6">
                        <label>Status <span class="required">*</span></label>
                        <select name="status" required>
                            <option value="Active" selected>Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- COMPANY FIELDS -->
                    <div id="companyFields" class="hidden col-span-12" style="display: contents;">
                        <div class="form-section">
                            <i data-lucide="building-2"></i> Company Information
                        </div>

                        <div class="col-span-8">
                            <label>Company Name <span class="required company-required">*</span></label>
                            <input type="text" name="company_name" id="companyName" placeholder="Enter company name">
                        </div>

                        <div class="col-span-4">
                            <label>TIN</label>
                            <input type="text" name="tin" placeholder="000-000-000-000">
                        </div>
                    </div>

                    <!-- INDIVIDUAL FIELDS -->
                    <div id="individualFields" class="hidden col-span-12" style="display: contents;">
                        <div class="form-section">
                            <i data-lucide="user"></i> Personal Information
                        </div>

                        <div class="col-span-4">
                            <label>First Name <span class="required individual-required">*</span></label>
                            <input type="text" name="first_name" id="firstName" placeholder="First name">
                        </div>

                        <div class="col-span-4">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" id="middleName" placeholder="Middle name (optional)">
                        </div>

                        <div class="col-span-4">
                            <label>Last Name <span class="required individual-required">*</span></label>
                            <input type="text" name="last_name" id="lastName" placeholder="Last name">
                        </div>
                    </div>

                    <!-- SHARED DISPLAY NAME FIELD -->
                    <div class="col-span-12">
                        <label>Display Name <small class="hint">(auto-generated based on customer type)</small></label>
                        <input type="text" id="fullNamePreview" readonly placeholder="(will be generated)">
                    </div>

                    <div class="form-section">
                        <i data-lucide="contact"></i> Contact Details
                    </div>

                    <div class="col-span-12" style="display:flex; align-items:center; gap:12px; margin-top:-8px;">
                        <input type="checkbox" id="sameAsCustomer" style="width:18px; height:18px;">
                        <label for="sameAsCustomer" id="sameAsLabel" style="margin:0; cursor:pointer; font-size:14px;">Contact person is the same as customer</label>
                    </div>

                    <div class="col-span-6">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="contactPerson" placeholder="Enter contact person name">
                    </div>

                    <div class="col-span-6">
                        <label>Position / Role</label>
                        <input type="text" name="position" placeholder="e.g. Normal Customer, Manager, Owner, Procurement">
                    </div>

                    <div class="col-span-6">
                        <label>Contact Number <span class="required">*</span></label>
                        <input type="tel" name="contact_number" required placeholder="09XXXXXXXXX">
                    </div>

                    <div class="col-span-6">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="email@example.com">
                    </div>

                    <!-- Address section -->
                    <div class="form-section">
                        <i data-lucide="map-pin"></i> Address
                    </div>

                    <div class="col-span-12">
                        <label>Street / Building / Unit No.</label>
                        <input type="text" name="street" id="street" placeholder="e.g. 123 Sampaguita St., Unit 405">
                    </div>

                    <div class="col-span-4">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="barangay" placeholder="Barangay">
                    </div>

                    <div class="col-span-4">
                        <label>City / Municipality <span class="required">*</span></label>
                        <input type="text" name="town_municipality" id="town" required placeholder="Cebu City">
                    </div>

                    <div class="col-span-4">
                        <label>Province</label>
                        <input type="text" name="province" id="province" placeholder="Cebu">
                    </div>

                    <div class="col-span-4">
                        <label>Postal Code</label>
                        <input type="text" name="postal_code" id="postal" placeholder="6000">
                    </div>

                    <div class="col-span-4">
                        <label>Country</label>
                        <input type="text" name="country" value="Philippines" readonly>
                    </div>

                    <div class="col-span-12">
                        <label>Complete Address <small class="hint">(auto-generated)</small></label>
                        <input type="text" id="fullAddressPreview" readonly placeholder="(will be generated)">
                        <input type="hidden" name="full_address" id="fullAddress">
                    </div>

                    <!-- Identification & Other -->
                    <div class="form-section">
                        <i data-lucide="shield-check"></i> Identification & Other
                    </div>

                    <div class="col-span-6">
                        <label>ID Type</label>
                        <select name="id_type">
                            <option value="">— Select —</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="SSS">SSS</option>
                            <option value="UMID">UMID</option>
                            <option value="Passport">Passport</option>
                            <option value="PRC">PRC ID</option>
                            <option value="Voter's ID">Voter's ID</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="col-span-6">
                        <label>ID Number</label>
                        <input type="text" name="id_number" placeholder="ID number">
                    </div>

                    <div class="col-span-6">
                        <label>Customer Since</label>
                        <input type="date" name="customer_since" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="col-span-6">
                        <label>Loyalty Tier</label>
                        <select name="loyalty_tier">
                            <option value="Standard" selected>Standard</option>
                            <option value="Silver">Silver</option>
                            <option value="Gold">Gold</option>
                            <option value="Platinum">Platinum</option>
                            <option value="VIP">VIP</option>
                        </select>
                    </div>

                    <div class="col-span-6">
                        <label>Payment Terms</label>
                        <select name="payment_terms" id="payment_terms">
                            <option value="">— Select Payment Terms —</option>
                            <?php foreach ($payment_terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>"><?php echo htmlspecialchars($term); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-span-12">
                        <label>Internal Notes</label>
                        <textarea name="notes" rows="3" placeholder="Special instructions, remarks, preferences..."></textarea>
                    </div>

                    <!-- WITH SPECIAL PROCESS CHECKBOX -->
                    <div class="col-span-12" style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#bdeaff; border:1px solid #207fab; border-radius:8px; margin-top:8px;">
                        <input type="checkbox" name="with_special_process" id="with_special_process" value="1" style="width:18px; height:18px; cursor:pointer;">
                        <label for="with_special_process" style="margin:0; cursor:pointer; font-size:14px; font-weight:500; color:#071f33; display:flex; align-items:center; gap:6px;">
                            <i data-lucide="alert-triangle" style="width:16px; height:16px;"></i>
                            With Special Process
                        </label>
                    </div>

                </div>
            </div>

            <div style="padding:20px 32px; border-top:1px solid #f1f5f9; background:#fff; text-align:right;">
                <button type="button" id="closeModalBtn" class="btn btn-secondary" style="margin-right:12px;">Cancel</button>
                <button type="submit" name="add_customer" class="btn btn-save">Register Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal-overlay" id="editCustomerModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--sidebar-dark);">
                <span id="editModalTitle">Edit Customer</span> <span id="editCustomerNameDisplay" style="font-weight:400; font-size:20px; color:red; font-weight: bolder;"></span>
            </h2>
            <button id="closeEditModal" style="background:#f1f5f9; border:none; width:36px; height:36px; border-radius:50%; cursor:pointer; color:#64748b; font-size:20px; line-height:1;">×</button>
        </div>

        <form method="POST" action="" id="editForm">
            <input type="hidden" name="customer_id" id="edit_customer_id">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section">
                        <i data-lucide="info"></i> Customer Type & Status
                    </div>

                    <div class="col-span-6">
                        <label>Customer Code</label>
                        <input type="text" id="edit_customer_code" readonly style="background:#f8fafc; font-weight:600;">
                    </div>

                    <div class="col-span-6">
                        <label>Customer Type <span class="required" id="edit_type_required">*</span></label>
                        <select name="customer_type" id="edit_customer_type" required>
                            <option value="">Select type</option>
                            <option value="Individual">Individual</option>
                            <option value="Company">Company / Business</option>
                        </select>
                    </div>

                    <div class="col-span-6">
                        <label>Status <span class="required" id="edit_status_required">*</span></label>
                        <select name="status" id="edit_status" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- COMPANY FIELDS (Edit) -->
                    <div id="editCompanyFields" class="hidden col-span-12" style="display: contents;">
                        <div class="form-section">
                            <i data-lucide="building-2"></i> Company Information
                        </div>

                        <div class="col-span-8">
                            <label>Company Name <span class="required company-required" id="edit_company_required">*</span></label>
                            <input type="text" name="company_name" id="edit_company_name" placeholder="Enter company name">
                        </div>

                        <div class="col-span-4">
                            <label>TIN</label>
                            <input type="text" name="tin" id="edit_tin" placeholder="000-000-000-000">
                        </div>
                    </div>

                    <!-- INDIVIDUAL FIELDS (Edit) -->
                    <div id="editIndividualFields" class="hidden col-span-12" style="display: contents;">
                        <div class="form-section">
                            <i data-lucide="user"></i> Personal Information
                        </div>

                        <div class="col-span-4">
                            <label>First Name <span class="required individual-required" id="edit_first_required">*</span></label>
                            <input type="text" name="first_name" id="edit_first_name" placeholder="First name">
                        </div>

                        <div class="col-span-4">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name" id="edit_middle_name" placeholder="Middle name (optional)">
                        </div>

                        <div class="col-span-4">
                            <label>Last Name <span class="required individual-required" id="edit_last_required">*</span></label>
                            <input type="text" name="last_name" id="edit_last_name" placeholder="Last name">
                        </div>
                    </div>

                    <!-- SHARED DISPLAY NAME FIELD (Edit) -->
                    <div class="col-span-12">
                        <label>Display Name <small class="hint">(auto-generated based on customer type)</small></label>
                        <input type="text" id="edit_full_name_preview" readonly placeholder="(will be generated)">
                    </div>

                    <div class="form-section">
                        <i data-lucide="contact"></i> Contact Details
                    </div>

                    <div class="col-span-12" style="display:flex; align-items:center; gap:12px; margin-top:-8px;">
                        <input type="checkbox" id="edit_same_as_customer" style="width:18px; height:18px;">
                        <label for="edit_same_as_customer" id="edit_same_as_label" style="margin:0; cursor:pointer; font-size:14px;">Contact person is the same as customer</label>
                    </div>

                    <div class="col-span-6">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="edit_contact_person" placeholder="Enter contact person name">
                    </div>

                    <div class="col-span-6">
                        <label>Position / Role</label>
                        <input type="text" name="position" id="edit_position" placeholder="e.g. Normal Customer, Manager, Owner, Procurement">
                    </div>

                    <div class="col-span-6">
                        <label>Contact Number <span class="required" id="edit_contact_required">*</span></label>
                        <input type="tel" name="contact_number" id="edit_contact_number" required placeholder="09XXXXXXXXX">
                    </div>

                    <div class="col-span-6">
                        <label>Email Address</label>
                        <input type="email" name="email" id="edit_email" placeholder="email@example.com">
                    </div>

                    <!-- Address section (Edit) -->
                    <div class="form-section">
                        <i data-lucide="map-pin"></i> Address
                    </div>

                    <div class="col-span-12">
                        <label>Street / Building / Unit No.</label>
                        <input type="text" name="street" id="edit_street" placeholder="e.g. 123 Sampaguita St., Unit 405">
                    </div>

                    <div class="col-span-4">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="edit_barangay" placeholder="Barangay">
                    </div>

                    <div class="col-span-4">
                        <label>City / Municipality <span class="required" id="edit_city_required">*</span></label>
                        <input type="text" name="town_municipality" id="edit_town" required placeholder="Cebu City">
                    </div>

                    <div class="col-span-4">
                        <label>Province</label>
                        <input type="text" name="province" id="edit_province" placeholder="Cebu">
                    </div>

                    <div class="col-span-4">
                        <label>Postal Code</label>
                        <input type="text" name="postal_code" id="edit_postal" placeholder="6000">
                    </div>

                    <div class="col-span-4">
                        <label>Country</label>
                        <input type="text" name="country" id="edit_country" value="Philippines" readonly>
                    </div>

                    <div class="col-span-12">
                        <label>Complete Address <small class="hint">(auto-generated)</small></label>
                        <input type="text" id="edit_full_address_preview" readonly placeholder="(will be generated)">
                        <input type="hidden" name="full_address" id="edit_full_address">
                    </div>

                    <!-- Identification & Other (Edit) -->
                    <div class="form-section">
                        <i data-lucide="shield-check"></i> Identification & Other
                    </div>

                    <div class="col-span-6">
                        <label>ID Type</label>
                        <select name="id_type" id="edit_id_type">
                            <option value="">— Select —</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="SSS">SSS</option>
                            <option value="UMID">UMID</option>
                            <option value="Passport">Passport</option>
                            <option value="PRC">PRC ID</option>
                            <option value="Voter's ID">Voter's ID</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="col-span-6">
                        <label>ID Number</label>
                        <input type="text" name="id_number" id="edit_id_number" placeholder="ID number">
                    </div>

                    <div class="col-span-6">
                        <label>Customer Since</label>
                        <input type="date" name="customer_since" id="edit_customer_since">
                    </div>

                    <div class="col-span-6">
                        <label>Loyalty Tier</label>
                        <select name="loyalty_tier" id="edit_loyalty_tier">
                            <option value="Standard">Standard</option>
                            <option value="Silver">Silver</option>
                            <option value="Gold">Gold</option>
                            <option value="Platinum">Platinum</option>
                            <option value="VIP">VIP</option>
                        </select>
                    </div>

                    <div class="col-span-6">
                        <label>Payment Terms</label>
                        <select name="payment_terms" id="edit_payment_terms">
                            <option value="">— Select Payment Terms —</option>
                            <?php foreach ($payment_terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>"><?php echo htmlspecialchars($term); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-span-12">
                        <label>Internal Notes</label>
                        <textarea name="notes" id="edit_notes" rows="3" placeholder="Special instructions, remarks, preferences..."></textarea>
                    </div>

                    <!-- WITH SPECIAL PROCESS CHECKBOX (Edit) -->
                    <div class="col-span-12" style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#bdeaff; border:1px solid #207fab; border-radius:8px; margin-top:8px;">
                        <input type="checkbox" name="with_special_process" id="edit_with_special_process" value="1" style="width:18px; height:18px; cursor:pointer;">
                        <label for="edit_with_special_process" style="margin:0; cursor:pointer; font-size:14px; font-weight:500; color:#071f33; display:flex; align-items:center; gap:6px;">
                            <i data-lucide="alert-triangle" style="width:16px; height:16px;"></i>
                            With Special Process
                        </label>
                    </div>

                </div>
            </div>

            <div id="editModalFooter" style="padding:20px 32px; border-top:1px solid #f1f5f9; background:#fff; text-align:right;">
                <button type="button" id="closeEditModalBtn" class="btn btn-secondary" style="margin-right:12px;">Cancel</button>
                <button type="submit" name="edit_customer" class="btn btn-save">Update Customer</button>
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

// Customer permissions from PHP
const canManageCustomer = <?php echo $can_manage_customer ? 'true' : 'false'; ?>;

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

// ============================================================
// UPPERCASE INPUT HANDLING (EMAIL REMAINS LOWERCASE)
// ============================================================

// Function to apply uppercase to text inputs (excluding email fields)
function applyUppercaseToInputs() {
    // Get all text inputs, textareas (excluding email fields)
    const inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]):not([type="radio"]):not([type="email"]):not([name="email"]):not([id="edit_email"]):not([id="email"]), textarea');
    
    inputs.forEach(input => {
        // Skip email fields
        if (input.type === 'email' || input.name === 'email' || input.id === 'edit_email' || input.id === 'email') {
            return;
        }
        
        // Store original value before input event to prevent double transformation
        input.addEventListener('input', function(e) {
            // Don't uppercase placeholder text
            if (this.placeholder) {
                // Preserve cursor position
                const start = this.selectionStart;
                const end = this.selectionEnd;
                
                // Convert to uppercase
                this.value = this.value.toUpperCase();
                
                // Restore cursor position
                this.setSelectionRange(start, end);
            }
        });
    });
}

// Function to apply lowercase to email fields
function applyLowercaseToEmail() {
    const emailInputs = document.querySelectorAll('input[type="email"], input[name="email"], input[id="edit_email"], input[id="email"]');
    
    emailInputs.forEach(input => {
        input.addEventListener('input', function() {
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.toLowerCase();
            this.setSelectionRange(start, end);
        });
    });
}

// Apply uppercase to search input specifically
function applyUppercaseToSearch() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(start, end);
        });
    }
}

// Apply transformations to all inputs within modals (add and edit)
function applyTransformationsToModalInputs() {
    const addModal = document.getElementById('addCustomerModal');
    const editModal = document.getElementById('editCustomerModal');
    
    [addModal, editModal].forEach(modal => {
        if (modal) {
            // Uppercase for non-email fields
            const inputs = modal.querySelectorAll('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]):not([type="radio"]):not([type="email"]):not([name="email"]):not([id="edit_email"]):not([id="email"]), textarea');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    this.value = this.value.toUpperCase();
                    this.setSelectionRange(start, end);
                });
            });
            
            // Lowercase for email fields
            const emailInputs = modal.querySelectorAll('input[type="email"], input[name="email"], input[id="edit_email"], input[id="email"]');
            emailInputs.forEach(input => {
                input.addEventListener('input', function() {
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    this.value = this.value.toLowerCase();
                    this.setSelectionRange(start, end);
                });
            });
        }
    });
}

// Apply transformations to all input fields on the page
document.addEventListener('DOMContentLoaded', function() {
    applyUppercaseToInputs();
    applyLowercaseToEmail();
    applyUppercaseToSearch();
    applyTransformationsToModalInputs();
    
    // Also apply to dynamically created elements
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                // Re-apply transformations to new inputs
                applyTransformationsToModalInputs();
            }
        });
    });
    
    observer.observe(document.body, { childList: true, subtree: true });
});

// ============================================================
// SEARCH FUNCTIONALITY
// ============================================================

const searchInput = document.getElementById('searchInput');
const clearBtn = document.getElementById('clearSearch');
const searchForm = searchInput?.closest('form');

// Auto-submit on input (with debounce)
let searchTimeout;
searchInput?.addEventListener('input', function() {
    // Convert search input to uppercase
    const start = this.selectionStart;
    const end = this.selectionEnd;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(start, end);
    
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

// ============================================================
// ADD CUSTOMER MODAL
// ============================================================

const addModal = document.getElementById('addCustomerModal');
const openAddBtn = document.getElementById('openAddModal');
const closeAddBtns = [
    document.getElementById('closeModal'),
    document.getElementById('closeModalBtn')
];

function openAddCustomerModal() {
    if (!canManageCustomer) {
        modal.style.display = 'flex';
        return;
    }

    addModal.style.display = 'flex'; 
    setTimeout(() => {
        toggleCustomerType();
        updateFullName();
        buildFullAddress();
        // Apply transformations to inputs in the add modal
        applyTransformationsToModalInputs();
    }, 100);
}

function closeAddCustomerModal() { addModal.style.display = 'none'; }

openAddBtn?.addEventListener('click', openAddCustomerModal);
closeAddBtns.forEach(b => b?.addEventListener('click', closeAddCustomerModal));

addModal?.addEventListener('click', e => { 
    if (e.target === addModal) closeAddCustomerModal(); 
});

// ============================================================
// EDIT CUSTOMER MODAL
// ============================================================

const editModal = document.getElementById('editCustomerModal');
const closeEditBtns = [
    document.getElementById('closeEditModal'),
    document.getElementById('closeEditModalBtn')
];

// Function to set form fields to readonly
function setEditFormReadonly(isReadonly) {
    const form = document.getElementById('editForm');
    const inputs = form.querySelectorAll('input:not([type="hidden"]), select, textarea');
    inputs.forEach(input => {
        if (isReadonly) {
            input.setAttribute('readonly', 'readonly');
            input.setAttribute('disabled', 'disabled');
            if (input.tagName === 'SELECT') {
                input.disabled = true;
            }
            // Disable checkboxes in view mode
            if (input.type === 'checkbox') {
                input.disabled = true;
            }
        } else {
            input.removeAttribute('readonly');
            input.removeAttribute('disabled');
            if (input.tagName === 'SELECT') {
                input.disabled = false;
            }
            // Re-enable checkboxes in edit mode
            if (input.type === 'checkbox') {
                input.disabled = false;
            }
        }
    });
    
    // Hide/show required asterisks
    const requiredStars = document.querySelectorAll('#editForm .required');
    requiredStars.forEach(star => {
        star.style.display = isReadonly ? 'none' : 'inline';
    });
    
    // Disable/enable checkbox
    const sameCheckbox = document.getElementById('edit_same_as_customer');
    if (sameCheckbox) {
        sameCheckbox.disabled = isReadonly;
    }
}

function openEditModal(customerData) {
    if (!canManageCustomer) {
        modal.style.display = 'flex';
        return;
    }

    // Set to edit mode
    document.getElementById('editModalTitle').textContent = 'Edit Customer';
    setEditFormReadonly(false);
    document.getElementById('editModalFooter').style.display = 'flex';

    // Populate form
    populateEditForm(customerData);
    
    editModal.style.display = 'flex';
    setTimeout(() => {
        document.getElementById('edit_contact_number')?.focus();
        // Apply transformations to inputs in the edit modal
        applyTransformationsToModalInputs();
    }, 100);
}

function openViewModal(customerData) {
    // Set to view mode
    document.getElementById('editModalTitle').textContent = 'View Customer';
    setEditFormReadonly(true);
    document.getElementById('editModalFooter').style.display = 'none';

    // Populate form
    populateEditForm(customerData);
    
    editModal.style.display = 'flex';
}

function populateEditForm(customerData) {
    document.getElementById('edit_customer_id').value = customerData.id;
    document.getElementById('edit_customer_code').value = customerData.customer_code;
    document.getElementById('edit_customer_type').value = customerData.customer_type || '';
    document.getElementById('edit_status').value = customerData.status || 'Active';
    
    // Company fields
    document.getElementById('edit_company_name').value = customerData.company_name || '';
    document.getElementById('edit_tin').value = customerData.tin || '';
    
    // Individual fields
    document.getElementById('edit_first_name').value = customerData.first_name || '';
    document.getElementById('edit_middle_name').value = customerData.middle_name || '';
    document.getElementById('edit_last_name').value = customerData.last_name || '';
    
    // Contact fields
    document.getElementById('edit_contact_person').value = customerData.contact_person || '';
    document.getElementById('edit_position').value = customerData.position || '';
    document.getElementById('edit_contact_number').value = customerData.contact_number || '';
    document.getElementById('edit_email').value = customerData.email || '';
    
    // Address fields
    document.getElementById('edit_street').value = customerData.street || '';
    document.getElementById('edit_barangay').value = customerData.barangay || '';
    document.getElementById('edit_town').value = customerData.town_municipality || '';
    document.getElementById('edit_province').value = customerData.province || '';
    document.getElementById('edit_postal').value = customerData.postal_code || '';
    document.getElementById('edit_country').value = customerData.country || 'Philippines';
    
    // Identification
    document.getElementById('edit_id_type').value = customerData.id_type || '';
    document.getElementById('edit_id_number').value = customerData.id_number || '';
    
    // Other
    document.getElementById('edit_customer_since').value = customerData.customer_since || '';
    document.getElementById('edit_loyalty_tier').value = customerData.loyalty_tier || 'Standard';
    document.getElementById('edit_payment_terms').value = customerData.payment_terms || '';
    document.getElementById('edit_notes').value = customerData.notes || '';
    
    // Special Process checkbox
    document.getElementById('edit_with_special_process').checked = customerData.with_special_process == 1;
    
    // Toggle the appropriate fields based on customer type
    toggleEditCustomerType();
    
    // Update previews
    updateEditFullName();
    buildEditFullAddress();
    
    // Update header with customer name
    const nameDisplay = document.getElementById('editCustomerNameDisplay');
    if (customerData.full_name) {
        nameDisplay.textContent = `(${customerData.full_name})`;
    } else {
        nameDisplay.textContent = '';
    }
}

function closeEditModal() {
    editModal.style.display = 'none';
}

closeEditBtns.forEach(btn => btn?.addEventListener('click', closeEditModal));

editModal?.addEventListener('click', e => {
    if (e.target === editModal) closeEditModal();
});

// Make functions available globally
window.openEditModal = openEditModal;
window.openViewModal = openViewModal;

// ============================================================
// ADD CUSTOMER - TYPE TOGGLE LOGIC
// ============================================================

const customerType     = document.getElementById('customerType');
const companyFields    = document.getElementById('companyFields');
const individualFields = document.getElementById('individualFields');
const sameCheckbox     = document.getElementById('sameAsCustomer');
const sameLabel        = document.getElementById('sameAsLabel');
const contactPerson    = document.getElementById('contactPerson');

function toggleCustomerType() {
    const type = customerType.value;

    companyFields.classList.toggle('hidden', type !== 'Company');
    individualFields.classList.toggle('hidden', type !== 'Individual');

    const companyNameInput = document.getElementById('companyName');
    const firstNameInput   = document.getElementById('firstName');
    const lastNameInput    = document.getElementById('lastName');

    if (companyNameInput) companyNameInput.required = (type === 'Company');
    if (firstNameInput)   firstNameInput.required   = (type === 'Individual');
    if (lastNameInput)    lastNameInput.required    = (type === 'Individual');

    if (type === 'Company') {
        sameCheckbox.disabled = true;
        sameCheckbox.checked = false;
        sameLabel.style.opacity = '0.6';
        sameLabel.style.cursor = 'not-allowed';
        contactPerson.readOnly = false;
        contactPerson.style.background = '#fff';
    } else {
        sameCheckbox.disabled = false;
        sameLabel.style.opacity = '1';
        sameLabel.style.cursor = 'pointer';
    }

    updateFullName();
}

customerType.addEventListener('change', toggleCustomerType);

// ============================================================
// EDIT CUSTOMER - TYPE TOGGLE LOGIC
// ============================================================

const editCustomerType     = document.getElementById('edit_customer_type');
const editCompanyFields    = document.getElementById('editCompanyFields');
const editIndividualFields = document.getElementById('editIndividualFields');
const editSameCheckbox     = document.getElementById('edit_same_as_customer');
const editSameLabel        = document.getElementById('edit_same_as_label');
const editContactPerson    = document.getElementById('edit_contact_person');

function toggleEditCustomerType() {
    const type = editCustomerType.value;

    editCompanyFields.classList.toggle('hidden', type !== 'Company');
    editIndividualFields.classList.toggle('hidden', type !== 'Individual');

    const companyNameInput = document.getElementById('edit_company_name');
    const firstNameInput   = document.getElementById('edit_first_name');
    const lastNameInput    = document.getElementById('edit_last_name');

    if (companyNameInput) companyNameInput.required = (type === 'Company');
    if (firstNameInput)   firstNameInput.required   = (type === 'Individual');
    if (lastNameInput)    lastNameInput.required    = (type === 'Individual');

    if (type === 'Company') {
        editSameCheckbox.disabled = true;
        editSameCheckbox.checked = false;
        editSameLabel.style.opacity = '0.6';
        editSameLabel.style.cursor = 'not-allowed';
        editContactPerson.readOnly = false;
        editContactPerson.style.background = '#fff';
    } else {
        editSameCheckbox.disabled = false;
        editSameLabel.style.opacity = '1';
        editSameLabel.style.cursor = 'pointer';
    }

    updateEditFullName();
}

editCustomerType.addEventListener('change', toggleEditCustomerType);

// ============================================================
// ADD CUSTOMER - NAME GENERATION
// ============================================================

const firstName    = document.getElementById('firstName');
const middleName   = document.getElementById('middleName');
const lastName     = document.getElementById('lastName');
const companyName  = document.getElementById('companyName');
const fullNamePrev = document.getElementById('fullNamePreview');

function updateFullName() {
    const type = customerType.value;
    let name = '';

    if (type === 'Company' && companyName) {
        name = companyName.value.trim().toUpperCase();
    } else if (type === 'Individual') {
        const parts = [
            (firstName?.value || '').trim().toUpperCase(),
            (middleName?.value || '').trim().toUpperCase(),
            (lastName?.value || '').trim().toUpperCase()
        ].filter(Boolean);
        name = parts.join(' ');
    }

    if (fullNamePrev) {
        fullNamePrev.value = name || '(will be generated)';
    }

    if (sameCheckbox.checked && !sameCheckbox.disabled) {
        contactPerson.value = name || '';
    }
}

[firstName, middleName, lastName, companyName].forEach(el => {
    if (el) el.addEventListener('input', updateFullName);
});

// ============================================================
// EDIT CUSTOMER - NAME GENERATION
// ============================================================

const editFirstName    = document.getElementById('edit_first_name');
const editMiddleName   = document.getElementById('edit_middle_name');
const editLastName     = document.getElementById('edit_last_name');
const editCompanyName  = document.getElementById('edit_company_name');
const editFullNamePrev = document.getElementById('edit_full_name_preview');

function updateEditFullName() {
    const type = editCustomerType.value;
    let name = '';

    if (type === 'Company' && editCompanyName) {
        name = editCompanyName.value.trim().toUpperCase();
    } else if (type === 'Individual') {
        const parts = [
            (editFirstName?.value || '').trim().toUpperCase(),
            (editMiddleName?.value || '').trim().toUpperCase(),
            (editLastName?.value || '').trim().toUpperCase()
        ].filter(Boolean);
        name = parts.join(' ');
    }

    if (editFullNamePrev) {
        editFullNamePrev.value = name || '(will be generated)';
    }

    if (editSameCheckbox.checked && !editSameCheckbox.disabled) {
        editContactPerson.value = name || '';
    }
}

[editFirstName, editMiddleName, editLastName, editCompanyName].forEach(el => {
    if (el) el.addEventListener('input', updateEditFullName);
});

// ============================================================
// SAME AS CUSTOMER CHECKBOX LOGIC (ADD)
// ============================================================

sameCheckbox.addEventListener('change', () => {
    if (sameCheckbox.checked && !sameCheckbox.disabled) {
        contactPerson.value = fullNamePrev.value === '(will be generated)' ? '' : fullNamePrev.value.toUpperCase();
        contactPerson.readOnly = true;
        contactPerson.style.background = '#f8fafc';
    } else {
        contactPerson.readOnly = false;
        contactPerson.style.background = '#fff';
    }
});

// ============================================================
// SAME AS CUSTOMER CHECKBOX LOGIC (EDIT)
// ============================================================

editSameCheckbox.addEventListener('change', () => {
    if (editSameCheckbox.checked && !editSameCheckbox.disabled) {
        editContactPerson.value = editFullNamePrev.value === '(will be generated)' ? '' : editFullNamePrev.value.toUpperCase();
        editContactPerson.readOnly = true;
        editContactPerson.style.background = '#f8fafc';
    } else {
        editContactPerson.readOnly = false;
        editContactPerson.style.background = '#fff';
    }
});

// ============================================================
// ADDRESS GENERATION (ADD)
// ============================================================

const addrFields = ['street','barangay','town','province','postal'].map(id => document.getElementById(id));
const countryField = document.querySelector('input[name="country"]');
const addrPreview = document.getElementById('fullAddressPreview');
const addrHidden  = document.getElementById('fullAddress');

function buildFullAddress() {
    const parts = [
        ...addrFields.map(f => (f?.value || '').trim().toUpperCase()).filter(Boolean),
        (countryField?.value || '').trim().toUpperCase()
    ].filter(Boolean);

    const full = parts.join(', ');
    if (addrPreview) addrPreview.value = full || '(will be generated)';
    if (addrHidden) addrHidden.value = full;
}

[...addrFields, countryField].forEach(el => {
    if (el) el.addEventListener('input', buildFullAddress);
});

// ============================================================
// ADDRESS GENERATION (EDIT)
// ============================================================

const editAddrFields = ['edit_street','edit_barangay','edit_town','edit_province','edit_postal'].map(id => document.getElementById(id));
const editCountryField = document.getElementById('edit_country');
const editAddrPreview = document.getElementById('edit_full_address_preview');
const editAddrHidden  = document.getElementById('edit_full_address');

function buildEditFullAddress() {
    const parts = [
        ...editAddrFields.map(f => (f?.value || '').trim().toUpperCase()).filter(Boolean),
        (editCountryField?.value || '').trim().toUpperCase()
    ].filter(Boolean);

    const full = parts.join(', ');
    if (editAddrPreview) editAddrPreview.value = full || '(will be generated)';
    if (editAddrHidden) editAddrHidden.value = full;
}

[...editAddrFields, editCountryField].forEach(el => {
    if (el) el.addEventListener('input', buildEditFullAddress);
});

// ============================================================
// CLOSE MODAL WITH ESCAPE KEY
// ============================================================

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (addModal?.style.display === 'flex') closeAddCustomerModal();
        if (editModal?.style.display === 'flex') closeEditModal();
        if (modal?.style.display === 'flex') closeModal();
    }
});

// ============================================================
// INITIAL CALLS
// ============================================================

toggleCustomerType();
updateFullName();
buildFullAddress();
toggleEditCustomerType();
updateEditFullName();
buildEditFullAddress();

// Apply transformations to all inputs on initial load
setTimeout(() => {
    applyUppercaseToInputs();
    applyLowercaseToEmail();
    applyTransformationsToModalInputs();
}, 100);
</script>
</body>
</html>