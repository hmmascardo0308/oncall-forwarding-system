<?php
// suppliers.php
session_start();
date_default_timezone_set('Asia/Manila');

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
$full_name  = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Check if user has access to suppliers page (view only for purchase_order_maker)
$can_access_suppliers = $is_admin || in_array('user', $user_roles) || in_array('purchase_order_maker', $user_roles);

if (!$can_access_suppliers) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Suppliers page."
    ];
    header("Location: home.php");
    exit;
}

// Check if user can add/edit suppliers (admin and user roles only)
$can_manage_suppliers = $is_admin || in_array('user', $user_roles);

// Use centralized access control
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

// Role display name - now using centralized function
$role_display_name = getRoleDisplayName($user_roles);

// Generate next supplier code (SUP-001, SUP-002, ...)
$next_code = 'SUP-001';
$code_query = "SELECT supplier_code 
               FROM supplier_lists 
               WHERE supplier_code LIKE 'SUP-%' 
               ORDER BY CAST(SUBSTRING(supplier_code, 5) AS UNSIGNED) DESC 
               LIMIT 1";

$code_result = mysqli_query($conn, $code_query);
if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
    if (preg_match('/^SUP-(\d+)$/', $row['supplier_code'], $matches)) {
        $last_number = (int)$matches[1];
        $next_number = $last_number + 1;
        $next_code   = sprintf('SUP-%03d', $next_number);
    }
}

// Fetch payment terms from general_settings (each term is in a separate row)
$payment_terms_options = [];
$terms_query = "SELECT terms FROM general_settings WHERE terms_by = 'Supplier Term' ORDER BY CAST(terms AS UNSIGNED) ASC";
$terms_result = mysqli_query($conn, $terms_query);
if ($terms_result) {
    while ($terms_row = mysqli_fetch_assoc($terms_result)) {
        if (!empty($terms_row['terms'])) {
            $payment_terms_options[] = trim($terms_row['terms']);
        }
    }
}

// Helper function to uppercase text fields (except email)
function uppercaseFields($data) {
    $uppercase_fields = ['supplier_name', 'business_reg_no', 'tin', 'street', 'barangay', 
                         'town_municipality', 'postal_code', 'province', 'country', 
                         'full_address', 'website', 'contact_person', 'phone_number', 
                         'position', 'bank_name', 'bank_number', 'notes'];
    
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

// Handle form submission for add - Only allow if user can manage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    // Check if user has permission to add suppliers
    if (!$can_manage_suppliers) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "You don't have permission to add suppliers."
        ];
        header("Location: suppliers.php");
        exit;
    }
    
    $fields = [
        'supplier_code'     => trim($_POST['supplier_code'] ?? ''),
        'supplier_name'     => trim($_POST['supplier_name'] ?? ''),
        'vat_type'          => trim($_POST['vat_type'] ?? ''),
        'status'            => trim($_POST['status'] ?? 'Active'),
        'business_reg_no'   => trim($_POST['business_reg_no'] ?? ''),
        'tin'               => trim($_POST['tin'] ?? ''),
        'street'            => trim($_POST['street'] ?? ''),
        'barangay'          => trim($_POST['barangay'] ?? ''),
        'town_municipality' => trim($_POST['town_municipality'] ?? ''),
        'postal_code'       => trim($_POST['postal_code'] ?? ''),
        'province'          => trim($_POST['province'] ?? ''),
        'country'           => trim($_POST['country'] ?? 'Philippines'),
        'full_address'      => trim($_POST['full_address'] ?? ''),
        'website'           => trim($_POST['website'] ?? ''),
        'contact_person'    => trim($_POST['contact_person'] ?? ''),
        'email'             => trim($_POST['email'] ?? ''),
        'phone_number'      => trim($_POST['phone_number'] ?? ''),
        'position'          => trim($_POST['position'] ?? ''),
        'bank_name'         => trim($_POST['bank_name'] ?? ''),
        'bank_number'       => trim($_POST['bank_number'] ?? ''),
        'notes'             => trim($_POST['notes'] ?? ''),
    ];

    // Apply uppercase transformation to text fields (email to lowercase)
    $fields = uppercaseFields($fields);

    // Get payment term from POST (single value)
    $payment_term = isset($_POST['payment_term']) ? trim($_POST['payment_term']) : '';

    $required = ['supplier_code', 'supplier_name', 'contact_person', 'email'];
    $errors = [];

    foreach ($required as $field) {
        if (empty($fields[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
        }
    }

    if (empty($payment_term)) {
        $errors[] = "Payment term is required.";
    }

    if (empty($fields['vat_type'])) {
        $errors[] = "VAT Type is required.";
    }

    if (!empty($errors)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => implode("<br>", $errors)
        ];
        header("Location: suppliers.php");
        exit;
    }

    // Check duplicate code
    $check_sql = "SELECT id FROM supplier_lists WHERE supplier_code = ? LIMIT 1";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "s", $fields['supplier_code']);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);

    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Supplier code <strong>" . htmlspecialchars($fields['supplier_code']) . "</strong> already exists."
        ];
        mysqli_stmt_close($check_stmt);
        header("Location: suppliers.php");
        exit;
    }
    mysqli_stmt_close($check_stmt);

    // Insert new supplier with vat_type
    $created_by   = $_SESSION['username'];
    $created_date = date('Y-m-d H:i:s');

    $insert_sql = "
        INSERT INTO supplier_lists (
            supplier_code, supplier_name, vat_type, status,
            business_reg_no, tin, street, barangay, town_municipality,
            postal_code, province, country, full_address, website,
            contact_person, email, phone_number, position,
            payment_terms, bank_name, bank_number, notes,
            created_date, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param(
        $stmt, "ssssssssssssssssssssssss",
        $fields['supplier_code'], $fields['supplier_name'], $fields['vat_type'], $fields['status'],
        $fields['business_reg_no'], $fields['tin'], $fields['street'], $fields['barangay'],
        $fields['town_municipality'], $fields['postal_code'], $fields['province'], $fields['country'],
        $fields['full_address'], $fields['website'], $fields['contact_person'], $fields['email'],
        $fields['phone_number'], $fields['position'], $payment_term, $fields['bank_name'],
        $fields['bank_number'], $fields['notes'], $created_date, $created_by
    );

    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => "Supplier <strong>" . htmlspecialchars($fields['supplier_name']) . "</strong> added successfully!"
        ];
        header("Location: suppliers.php");
        exit;
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Database error: " . mysqli_error($conn)
        ];
        header("Location: suppliers.php");
        exit;
    }

    mysqli_stmt_close($stmt);
}

// Handle edit form submission - Only allow if user can manage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_supplier'])) {
    // Check if user has permission to edit suppliers
    if (!$can_manage_suppliers) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "You don't have permission to edit suppliers."
        ];
        header("Location: suppliers.php");
        exit;
    }
    
    $supplier_id = intval($_POST['supplier_id']);
    
    $fields = [
        'supplier_name'     => trim($_POST['supplier_name'] ?? ''),
        'vat_type'          => trim($_POST['vat_type'] ?? ''),
        'status'            => trim($_POST['status'] ?? 'Active'),
        'business_reg_no'   => trim($_POST['business_reg_no'] ?? ''),
        'tin'               => trim($_POST['tin'] ?? ''),
        'street'            => trim($_POST['street'] ?? ''),
        'barangay'          => trim($_POST['barangay'] ?? ''),
        'town_municipality' => trim($_POST['town_municipality'] ?? ''),
        'postal_code'       => trim($_POST['postal_code'] ?? ''),
        'province'          => trim($_POST['province'] ?? ''),
        'country'           => trim($_POST['country'] ?? 'Philippines'),
        'full_address'      => trim($_POST['full_address'] ?? ''),
        'website'           => trim($_POST['website'] ?? ''),
        'contact_person'    => trim($_POST['contact_person'] ?? ''),
        'email'             => trim($_POST['email'] ?? ''),
        'phone_number'      => trim($_POST['phone_number'] ?? ''),
        'position'          => trim($_POST['position'] ?? ''),
        'bank_name'         => trim($_POST['bank_name'] ?? ''),
        'bank_number'       => trim($_POST['bank_number'] ?? ''),
        'notes'             => trim($_POST['notes'] ?? ''),
    ];

    // Apply uppercase transformation to text fields (email to lowercase)
    $fields = uppercaseFields($fields);

    // Get payment term from POST (single value)
    $payment_term = isset($_POST['payment_term']) ? trim($_POST['payment_term']) : '';

    $required = ['supplier_name', 'contact_person', 'email'];
    $errors = [];

    foreach ($required as $field) {
        if (empty($fields[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required.";
        }
    }

    if (empty($payment_term)) {
        $errors[] = "Payment term is required.";
    }

    if (empty($fields['vat_type'])) {
        $errors[] = "VAT Type is required.";
    }

    if (!empty($errors)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => implode("<br>", $errors)
        ];
        header("Location: suppliers.php");
        exit;
    }

    // Update supplier
    $updated_by   = $_SESSION['username'];
    $updated_date = date('Y-m-d H:i:s');

    $update_sql = "
        UPDATE supplier_lists SET
            supplier_name = ?, vat_type = ?, status = ?,
            business_reg_no = ?, tin = ?, street = ?, barangay = ?, 
            town_municipality = ?, postal_code = ?, province = ?, country = ?,
            full_address = ?, website = ?, contact_person = ?, email = ?,
            phone_number = ?, position = ?, payment_terms = ?, bank_name = ?,
            bank_number = ?, notes = ?, updated_date = ?, updated_by = ?
        WHERE id = ?
    ";

    $stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param(
        $stmt, "sssssssssssssssssssssssi",
        $fields['supplier_name'], $fields['vat_type'], $fields['status'],
        $fields['business_reg_no'], $fields['tin'], $fields['street'], $fields['barangay'],
        $fields['town_municipality'], $fields['postal_code'], $fields['province'], $fields['country'],
        $fields['full_address'], $fields['website'], $fields['contact_person'], $fields['email'],
        $fields['phone_number'], $fields['position'], $payment_term, $fields['bank_name'],
        $fields['bank_number'], $fields['notes'], $updated_date, $updated_by, $supplier_id
    );

    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => "Supplier <strong>" . htmlspecialchars($fields['supplier_name']) . "</strong> updated successfully!"
        ];
        header("Location: suppliers.php");
        exit;
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Database error: " . mysqli_error($conn)
        ];
        header("Location: suppliers.php");
        exit;
    }

    mysqli_stmt_close($stmt);
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch suppliers with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT * FROM supplier_lists 
              WHERE supplier_code LIKE '%$search_term%' 
              OR supplier_name LIKE '%$search_term%'
              OR contact_person LIKE '%$search_term%'
              OR email LIKE '%$search_term%'
              OR phone_number LIKE '%$search_term%'
              OR vat_type LIKE '%$search_term%'
              OR town_municipality LIKE '%$search_term%'
              OR province LIKE '%$search_term%'
              ORDER BY supplier_name ASC";
} else {
    $query = "SELECT * FROM supplier_lists ORDER BY supplier_name ASC";
}

$result = mysqli_query($conn, $query);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers Masterlist | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="stylesheet" href="css/suppliers.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    
    
</head>
<body>

<!-- Access Denied Modal -->
<div id="accessModal" class="access-modal-overlay">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Suppliers Masterlist</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                <?= $flash['text'] ?>
            </div>
        <?php endif; ?>

        <!-- Permission Notice for View-Only Users -->
        <?php if (!$can_manage_suppliers): ?>
            <div class="permission-notice">
                <i data-lucide="eye"></i>
                <span>You are in <strong>View-Only</strong> mode. You can view supplier records but cannot add or edit suppliers.</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Suppliers Directory</h1>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 300px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Code, Name, Contact..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <?php if ($can_manage_suppliers): ?>
                <button id="openAddModal" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                    <i data-lucide="plus" style="width:18px;"></i> Add Supplier
                </button>
                <?php else: ?>
                <span class="view-only-text">View Only</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($result); ?> found)
                <a href="suppliers.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Supplier Name</th>
                        <th>VAT Type</th>
                        <th>Contact Person</th>
                        <th>Email / Phone</th>
                        <th>Payment Terms</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td style="font-weight:600; color:var(--accent-blue);"><?php echo htmlspecialchars($row['supplier_code']); ?></td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars($row['supplier_name']); ?></div>
                                </td>
                                <td>
                                    <?php 
                                    $vat_type = $row['vat_type'] ?? '';
                                    $vat_class = '';
                                    if ($vat_type == 'Taxable (12%)') {
                                        $vat_class = 'vat-taxable';
                                    } elseif ($vat_type == 'Zero-Rated (0%)') {
                                        $vat_class = 'vat-zero-rated';
                                    } elseif ($vat_type == 'Exempt (0%)') {
                                        $vat_class = 'vat-exempt';
                                    }
                                    ?>
                                    <span class="vat-badge <?php echo $vat_class; ?>">
                                        <?php echo htmlspecialchars($vat_type ?: '—'); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['contact_person'] ?: '—'); ?></td>
                                <td>
                                    <div style="font-size:13px;"><?php echo htmlspecialchars($row['email'] ?: '—'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['phone_number'] ?: '—'); ?></div>
                                </td>
                                <td>
                                    <div class="payment-terms-display">
                                        <?php if (!empty($row['payment_terms'])): ?>
                                            <?php 
                                            $terms = array_map('trim', explode(',', $row['payment_terms']));
                                            $formatted_terms = array_map(function($term) {
                                                $term_upper = strtoupper($term);
                                                // If it's COD or contains only letters, don't add 'DAYS'
                                                if ($term_upper === 'COD' || !preg_match('/\d/', $term)) {
                                                    return $term;
                                                }
                                                // If it's a number or contains a number, add 'DAYS'
                                                return $term . ' DAYS';
                                            }, $terms);
                                            ?>
                                            <span class="payment-term-tag"><?php echo htmlspecialchars(implode(', ', $formatted_terms)); ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted); font-size:12px;">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['town_municipality'] . ($row['province'] ? ', ' . $row['province'] : '')) ?: '—'; ?></td>
                                <td>
                                    <?php $statusClass = (strtolower($row['status']) === 'active') ? 'status-active' : 'status-inactive'; ?>
                                    <span class="status-pill <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($row['status'] ?: 'Unknown'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-cell">
                                        <?php if ($can_manage_suppliers): ?>
                                        <button class="edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                            <i data-lucide="edit-2" style="width:16px;"></i> View / Edit
                                        </button>
                                        <?php else: ?>
                                        <button class="edit-btn" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                            <i data-lucide="eye" style="width:16px;"></i> View
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No suppliers found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="suppliers.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all suppliers
                                    </a>
                                <?php else: ?>
                                    No suppliers found in the database.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Add Supplier Modal -->
<div class="modal-overlay" id="addSupplierModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--sidebar-dark);">Register New Supplier</h2>
            <button id="closeModal" style="background:#f1f5f9; border:none; width:32px; height:32px; border-radius:50%; cursor:pointer; color:#64748b; font-size:20px;">×</button>
        </div>

        <div class="modal-body">
            <form method="POST" id="addSupplierForm">
                <div class="form-grid">
                    <!-- Basic Information Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="info" style="width:16px;"></i> Basic Information
                    </div>

                    <!-- Supplier Code (Hidden) -->
                    <div class="col-12">
                        <input type="hidden" name="supplier_code" value="<?= htmlspecialchars($next_code) ?>">
                    </div>

                    <!-- Supplier Name and VAT Type row -->
                    <div class="col-8">
                        <label>Supplier Name <span class="required">*</span></label>
                        <input type="text" name="supplier_name" required>
                    </div>
                    <div class="col-4">
                        <label>VAT Type <span class="required">*</span></label>
                        <select name="vat_type" required>
                            <option value="">Select VAT Type</option>
                            <option value="Taxable (12%)">Taxable (12%)</option>
                            <option value="Zero-Rated (0%)">Zero-Rated (0%)</option>
                            <option value="Exempt (0%)">Exempt (0%)</option>
                            <option value="Non-VAT">Non-VAT</option>

                        </select>
                    </div>

                    <!-- TIN and Status row -->
                    <div class="col-8">
                        <label>TIN</label>
                        <input type="text" name="tin" placeholder="000-000-000-000">
                    </div>
                    <div class="col-4">
                        <label>Status</label>
                        <select name="status">
                            <option value="Active" selected>Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- Payment Terms Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="credit-card" style="width:16px;"></i> Payment Term <span class="required">*</span>
                    </div>

                    <div class="col-12">
                        <label>Select Payment Term</label>
                        <select name="payment_term" class="form-select" required>
                            <option value="">Select Payment Term</option>
                            <?php foreach ($payment_terms_options as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>"><?php echo htmlspecialchars($term); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Office Address Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="map-pin" style="width:16px;"></i> Office Address
                    </div>

                    <!-- Street, Barangay, City/Municipality row -->
                    <div class="col-4">
                        <label>Street/Building</label>
                        <input type="text" name="street">
                    </div>
                    <div class="col-4">
                        <label>Barangay</label>
                        <input type="text" name="barangay">
                    </div>
                    <div class="col-4">
                        <label>City/Municipality</label>
                        <input type="text" name="town_municipality">
                    </div>

                    <!-- Province, Postal Code, Country row -->
                    <div class="col-4">
                        <label>Province</label>
                        <input type="text" name="province">
                    </div>
                    <div class="col-4">
                        <label>Postal Code</label>
                        <input type="text" name="postal_code">
                    </div>
                    <div class="col-4">
                        <label>Country</label>
                        <input type="text" name="country" value="Philippines">
                    </div>

                    <!-- Full Address (auto-generated) -->
                    <div class="col-12">
                        <label>Full Address (auto-generated)</label>
                        <textarea name="full_address" rows="1" readonly style="background:#f8fafc; resize:none;"></textarea>
                    </div>

                    <!-- Contact Details Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="contact" style="width:16px;"></i> Contact Details
                    </div>

                    <!-- Primary Contact Person and Position row -->
                    <div class="col-6">
                        <label>Primary Contact Person <span class="required">*</span></label>
                        <input type="text" name="contact_person" required placeholder="Full Name">
                    </div>
                    <div class="col-6">
                        <label>Position</label>
                        <input type="text" name="position" placeholder="e.g. Sales Manager">
                    </div>

                    <!-- Email and Phone row -->
                    <div class="col-6">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="col-6">
                        <label>Phone / Mobile</label>
                        <input type="tel" name="phone_number" placeholder="09XXXXXXXXX">
                    </div>

                    <!-- Financial & Notes Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="building-2" style="width:16px;"></i> Banking Information
                    </div>

                    <!-- Account Name, Bank Account No row -->
                    <div class="col-6">
                        <label>Account Name</label>
                        <input type="text" name="bank_name">
                    </div>
                    <div class="col-6">
                        <label>Bank Account No.</label>
                        <input type="text" name="bank_number">
                    </div>

                    <!-- Internal Notes -->
                    <div class="col-12">
                        <label>Internal Notes</label>
                        <textarea name="notes" rows="3" placeholder="Any additional information..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer" style="margin-top: 24px; padding: 16px 0 0 0;">
                    <button type="button" id="closeModalBtn" class="btn btn-secondary">Discard</button>
                    <button type="submit" name="add_supplier" class="btn btn-save">Register Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal-overlay" id="editSupplierModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--sidebar-dark);">
                <span id="editModalTitle">Edit Supplier</span> <span id="editSupplierNameDisplay" style="font-weight:400; font-size:20px; color:red; font-weight: bolder;"></span>
            </h2>
            <button id="closeEditModal" style="background:#f1f5f9; border:none; width:32px; height:32px; border-radius:50%; cursor:pointer; color:#64748b; font-size:20px;">×</button>
        </div>

        <div class="modal-body">
            <form method="POST" id="editSupplierForm">
                <input type="hidden" name="supplier_id" id="edit_supplier_id">
                
                <div class="form-grid">
                    <!-- Basic Information Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="info" style="width:16px;"></i> Basic Information
                    </div>

                    <!-- Supplier Code Display (readonly) -->
                    <div class="col-12">
                        <label>Supplier Code</label>
                        <input type="text" id="edit_supplier_code" readonly style="background:#f8fafc; color:#64748b;">
                    </div>

                    <!-- Supplier Name and VAT Type row -->
                    <div class="col-8">
                        <label>Supplier Name <span class="required" id="edit_name_required">*</span></label>
                        <input type="text" name="supplier_name" id="edit_supplier_name" required>
                    </div>
                    <div class="col-4">
                        <label>VAT Type <span class="required" id="edit_vat_required">*</span></label>
                        <select name="vat_type" id="edit_vat_type" required>
                            <option value="">Select VAT Type</option>
                            <option value="Taxable (12%)">Taxable (12%)</option>
                            <option value="Zero-Rated (0%)">Zero-Rated (0%)</option>
                            <option value="Exempt (0%)">Exempt (0%)</option>
                            <option value="Non-VAT">Non-VAT</option>

                        </select>
                    </div>

                    <!-- TIN and Status row -->
                    <div class="col-8">
                        <label>TIN</label>
                        <input type="text" name="tin" id="edit_tin" placeholder="000-000-000-000">
                    </div>
                    <div class="col-4">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <!-- Payment Terms Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="credit-card" style="width:16px;"></i> Payment Term <span class="required" id="edit_payment_required">*</span>
                    </div>

                    <div class="col-12">
                        <label>Select Payment Term</label>
                        <select name="payment_term" id="edit_payment_term" class="form-select" required>
                            <option value="">Select Payment Term</option>
                            <?php foreach ($payment_terms_options as $term): ?>
                                <option value="<?php echo htmlspecialchars($term); ?>"><?php echo htmlspecialchars($term); ?> days</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Office Address Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="map-pin" style="width:16px;"></i> Office Address
                    </div>

                    <!-- Street, Barangay, City/Municipality row -->
                    <div class="col-4">
                        <label>Street/Building</label>
                        <input type="text" name="street" id="edit_street">
                    </div>
                    <div class="col-4">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="edit_barangay">
                    </div>
                    <div class="col-4">
                        <label>City/Municipality</label>
                        <input type="text" name="town_municipality" id="edit_town_municipality">
                    </div>

                    <!-- Province, Postal Code, Country row -->
                    <div class="col-4">
                        <label>Province</label>
                        <input type="text" name="province" id="edit_province">
                    </div>
                    <div class="col-4">
                        <label>Postal Code</label>
                        <input type="text" name="postal_code" id="edit_postal_code">
                    </div>
                    <div class="col-4">
                        <label>Country</label>
                        <input type="text" name="country" id="edit_country" value="Philippines">
                    </div>

                    <!-- Full Address (auto-generated) -->
                    <div class="col-12">
                        <label>Full Address (auto-generated)</label>
                        <textarea name="full_address" id="edit_full_address" rows="1" readonly style="background:#f8fafc; resize:none;"></textarea>
                    </div>

                    <!-- Contact Details Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="contact" style="width:16px;"></i> Contact Details
                    </div>

                    <!-- Primary Contact Person and Position row -->
                    <div class="col-6">
                        <label>Primary Contact Person <span class="required" id="edit_contact_required">*</span></label>
                        <input type="text" name="contact_person" id="edit_contact_person" required placeholder="Full Name">
                    </div>
                    <div class="col-6">
                        <label>Position</label>
                        <input type="text" name="position" id="edit_position" placeholder="e.g. Sales Manager">
                    </div>

                    <!-- Email and Phone row -->
                    <div class="col-6">
                        <label>Email Address <span class="required" id="edit_email_required">*</span></label>
                        <input type="email" name="email" id="edit_email" required>
                    </div>
                    <div class="col-6">
                        <label>Phone / Mobile</label>
                        <input type="tel" name="phone_number" id="edit_phone_number" placeholder="09XXXXXXXXX">
                    </div>

                    <!-- Financial & Notes Section -->
                    <div class="col-12 form-section">
                        <i data-lucide="building-2" style="width:16px;"></i> Banking Information
                    </div>

                    <!-- Account Name, Bank Account No row -->
                    <div class="col-6">
                        <label>Account Name</label>
                        <input type="text" name="bank_name" id="edit_bank_name">
                    </div>
                    <div class="col-6">
                        <label>Bank Account No.</label>
                        <input type="text" name="bank_number" id="edit_bank_number">
                    </div>

                    <!-- Internal Notes -->
                    <div class="col-12">
                        <label>Internal Notes</label>
                        <textarea name="notes" id="edit_notes" rows="3" placeholder="Any additional information..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer" id="editModalFooter" style="margin-top: 24px; padding: 16px 0 0 0;">
                    <button type="button" id="closeEditModalBtn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="edit_supplier" class="btn btn-save">Update Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
lucide.createIcons();

// User roles from PHP
const userRoles = <?php echo json_encode($user_roles); ?>;

// Allowed pages from PHP
const allowedPages = <?php echo json_encode($allowed_pages); ?>;

// Can manage suppliers flag
const canManageSuppliers = <?php echo $can_manage_suppliers ? 'true' : 'false'; ?>;

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

function closeModal() {
    modal.style.display = 'none';
}

modal.addEventListener('click', function(e) {
    if (e.target === modal) {
        closeModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display === 'flex') {
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
        
        input.addEventListener('input', function(e) {
            if (this.placeholder) {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = this.value.toUpperCase();
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
    const addModal = document.getElementById('addSupplierModal');
    const editModal = document.getElementById('editSupplierModal');
    
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
// ADD SUPPLIER MODAL
// ============================================================

const addModal = document.getElementById('addSupplierModal');
const openAddBtn = document.getElementById('openAddModal');
const closeAddBtns = [
    document.getElementById('closeModal'),
    document.getElementById('closeModalBtn')
];

function openAddModalFunction() {
    if (!canManageSuppliers) {
        alert('You are in view-only mode. You cannot add suppliers.');
        return;
    }
    addModal.style.display = 'flex';
    document.getElementById('addSupplierForm').reset();
    updateFullAddress();
    lucide.createIcons();
    setTimeout(() => {
        applyTransformationsToModalInputs();
    }, 100);
}

function closeAddModal() { addModal.style.display = 'none'; }

openAddBtn?.addEventListener('click', openAddModalFunction);
closeAddBtns.forEach(btn => btn?.addEventListener('click', closeAddModal));

addModal?.addEventListener('click', e => {
    if (e.target === addModal) closeAddModal();
});

// ============================================================
// EDIT SUPPLIER MODAL
// ============================================================

const editModal = document.getElementById('editSupplierModal');
const closeEditBtns = [
    document.getElementById('closeEditModal'),
    document.getElementById('closeEditModalBtn')
];

function closeEditModal() { 
    editModal.style.display = 'none'; 
}

// Function to set form fields to readonly
function setFormReadonly(isReadonly) {
    const form = document.getElementById('editSupplierForm');
    const inputs = form.querySelectorAll('input:not([type="hidden"]), select, textarea');
    inputs.forEach(input => {
        if (isReadonly) {
            input.setAttribute('readonly', 'readonly');
            input.setAttribute('disabled', 'disabled');
            if (input.tagName === 'SELECT') {
                input.disabled = true;
            }
        } else {
            input.removeAttribute('readonly');
            input.removeAttribute('disabled');
            if (input.tagName === 'SELECT') {
                input.disabled = false;
            }
        }
    });
    
    const requiredStars = document.querySelectorAll('#editSupplierForm .required');
    requiredStars.forEach(star => {
        star.style.display = isReadonly ? 'none' : 'inline';
    });
}

function openEditModal(supplier) {
    if (!canManageSuppliers) {
        alert('You are in view-only mode. You cannot edit suppliers.');
        return;
    }
    
    document.getElementById('editModalTitle').textContent = 'Edit Supplier';
    setFormReadonly(false);
    document.getElementById('editModalFooter').style.display = 'flex';
    
    populateEditForm(supplier);
    
    editModal.style.display = 'flex';
    lucide.createIcons();
    setTimeout(() => {
        applyTransformationsToModalInputs();
    }, 100);
}

function openViewModal(supplier) {
    document.getElementById('editModalTitle').textContent = 'View Supplier';
    setFormReadonly(true);
    document.getElementById('editModalFooter').style.display = 'none';
    
    populateEditForm(supplier);
    
    editModal.style.display = 'flex';
    lucide.createIcons();
}

function populateEditForm(supplier) {
    document.getElementById('edit_supplier_id').value = supplier.id;
    document.getElementById('edit_supplier_code').value = supplier.supplier_code;
    document.getElementById('edit_supplier_name').value = supplier.supplier_name || '';
    document.getElementById('edit_vat_type').value = supplier.vat_type || '';
    document.getElementById('edit_tin').value = supplier.tin || '';
    document.getElementById('edit_status').value = supplier.status || 'Active';
    document.getElementById('edit_street').value = supplier.street || '';
    document.getElementById('edit_barangay').value = supplier.barangay || '';
    document.getElementById('edit_town_municipality').value = supplier.town_municipality || '';
    document.getElementById('edit_province').value = supplier.province || '';
    document.getElementById('edit_postal_code').value = supplier.postal_code || '';
    document.getElementById('edit_country').value = supplier.country || 'Philippines';
    document.getElementById('edit_full_address').value = supplier.full_address || '';
    document.getElementById('edit_contact_person').value = supplier.contact_person || '';
    document.getElementById('edit_position').value = supplier.position || '';
    document.getElementById('edit_email').value = supplier.email || '';
    document.getElementById('edit_phone_number').value = supplier.phone_number || '';
    document.getElementById('edit_bank_name').value = supplier.bank_name || '';
    document.getElementById('edit_bank_number').value = supplier.bank_number || '';
    document.getElementById('edit_notes').value = supplier.notes || '';
    
    document.getElementById('edit_payment_term').value = supplier.payment_terms || '';
    
    const nameDisplay = document.getElementById('editSupplierNameDisplay');
    if (supplier.supplier_name) {
        nameDisplay.textContent = `(${supplier.supplier_name})`;
    } else {
        nameDisplay.textContent = '';
    }
}

closeEditBtns.forEach(btn => btn?.addEventListener('click', closeEditModal));

editModal?.addEventListener('click', e => {
    if (e.target === editModal) closeEditModal();
});

// ============================================================
// AUTO-GENERATE FULL ADDRESS
// ============================================================

function updateFullAddress() {
    const fields = ['street', 'barangay', 'town_municipality', 'province', 'postal_code', 'country'];
    const values = fields.map(f => {
        const input = document.querySelector(`#addSupplierForm input[name="${f}"]`);
        return input?.value.trim().toUpperCase() || '';
    });
    const parts = values.filter(v => v);
    const fullAddress = document.querySelector('#addSupplierForm textarea[name="full_address"]');
    if (fullAddress) fullAddress.value = parts.join(', ');
}

['street', 'barangay', 'town_municipality', 'province', 'postal_code', 'country'].forEach(field => {
    const input = document.querySelector(`#addSupplierForm input[name="${field}"]`);
    if (input) {
        input.addEventListener('input', updateFullAddress);
        input.addEventListener('change', updateFullAddress);
        input.addEventListener('blur', updateFullAddress);
    }
});

function updateEditFullAddress() {
    const fields = ['street', 'barangay', 'town_municipality', 'province', 'postal_code', 'country'];
    const values = fields.map(f => {
        const input = document.querySelector(`#editSupplierForm input[name="${f}"]`);
        return input?.value.trim().toUpperCase() || '';
    });
    const parts = values.filter(v => v);
    const fullAddress = document.querySelector('#editSupplierForm textarea[name="full_address"]');
    if (fullAddress) fullAddress.value = parts.join(', ');
}

['street', 'barangay', 'town_municipality', 'province', 'postal_code', 'country'].forEach(field => {
    const input = document.querySelector(`#editSupplierForm input[name="${f}"]`);
    if (input) {
        input.addEventListener('input', updateEditFullAddress);
        input.addEventListener('change', updateEditFullAddress);
        input.addEventListener('blur', updateEditFullAddress);
    }
});

// ============================================================
// CLOSE MODALS WITH ESCAPE KEY
// ============================================================

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (addModal?.style.display === 'flex') closeAddModal();
        if (editModal?.style.display === 'flex') closeEditModal();
        if (modal?.style.display === 'flex') closeModal();
    }
});

// ============================================================
// MAKE FUNCTIONS GLOBALLY AVAILABLE
// ============================================================

window.openEditModal = openEditModal;
window.openViewModal = openViewModal;

// ============================================================
// INITIAL CALLS
// ============================================================

setTimeout(() => {
    applyUppercaseToInputs();
    applyLowercaseToEmail();
    applyTransformationsToModalInputs();
}, 100);
</script>
</body>
</html>