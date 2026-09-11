<?php
// customer_pricing.php
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
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);
$is_customer_pricer = in_array('customer_pricer', $user_roles);
$is_sales_order_maker = in_array('sales_order_maker', $user_roles);

// Check if user has access to customer pricing page (admin, user, customer_pricer, or sales_order_maker roles)
$can_access_pricing = $is_admin || in_array('user', $user_roles) || $is_customer_pricer || $is_sales_order_maker;

if (!$can_access_pricing) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Customer Pricing page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);



// Handle Delete Request (Admin, customer_pricer, or sales_order_maker can delete)
if (isset($_GET['delete_id']) && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {
    $delete_id = intval($_GET['delete_id']);
    $delete_sql = "DELETE FROM customer_pricing WHERE id = ?";
    $stmt = mysqli_prepare($conn, $delete_sql);
    mysqli_stmt_bind_param($stmt, "i", $delete_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => "Pricing record deleted successfully!"
        ];
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Failed to delete pricing record."
        ];
    }
    mysqli_stmt_close($stmt);
    header("Location: customer_pricing.php");
    exit;
}

// Handle form submission - Add/Edit pricing (Admin, customer_pricer, or sales_order_maker can add/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pricing']) && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {

    $pricing_id = isset($_POST['pricing_id']) ? intval($_POST['pricing_id']) : 0;
    
    $data = [
        'customer_code'     => trim($_POST['customer_code'] ?? ''),
        'full_name'         => trim($_POST['full_name'] ?? ''),
        'truck_code'        => !empty($_POST['truck_code']) ? trim($_POST['truck_code']) : null,
        'plate_number'      => !empty($_POST['plate_number']) ? trim($_POST['plate_number']) : null,
        'truck_type'        => !empty($_POST['truck_type']) ? trim($_POST['truck_type']) : null,
        'body_type'         => !empty($_POST['body_type']) ? trim($_POST['body_type']) : null,
        'wheel_count'       => !empty($_POST['wheel_count']) ? intval($_POST['wheel_count']) : null,
        'rate_per_km'       => !empty($_POST['rate_per_km']) ? floatval($_POST['rate_per_km']) : null,
        'rate_per_hour'     => !empty($_POST['rate_per_hour']) ? floatval($_POST['rate_per_hour']) : null,
        'rate_per_trip'     => !empty($_POST['rate_per_trip']) ? floatval($_POST['rate_per_trip']) : null,
        'minimum_charge'    => !empty($_POST['minimum_charge']) ? floatval($_POST['minimum_charge']) : null,
        'rate_per_kg'       => !empty($_POST['rate_per_kg']) ? floatval($_POST['rate_per_kg']) : null,
        'rate_per_cbm'      => !empty($_POST['rate_per_cbm']) ? floatval($_POST['rate_per_cbm']) : null,
        'zone_from'         => !empty($_POST['zone_from']) ? trim($_POST['zone_from']) : null,
        'zone_to'           => !empty($_POST['zone_to']) ? trim($_POST['zone_to']) : null,
        'min_distance_km'   => !empty($_POST['min_distance_km']) ? floatval($_POST['min_distance_km']) : null,
        'max_distance_km'   => !empty($_POST['max_distance_km']) ? floatval($_POST['max_distance_km']) : null,
        'min_weight_kg'     => !empty($_POST['min_weight_kg']) ? floatval($_POST['min_weight_kg']) : null,
        'max_weight_kg'     => !empty($_POST['max_weight_kg']) ? floatval($_POST['max_weight_kg']) : null,
        'effective_from'    => !empty($_POST['effective_from']) ? $_POST['effective_from'] : null,
        'effective_until'   => !empty($_POST['effective_until']) ? $_POST['effective_until'] : null,
        'status'            => trim($_POST['status'] ?? 'active'),
        'notes'             => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
    ];

    $errors = [];

    if (empty($data['customer_code'])) $errors[] = "Customer Code is required.";
    if (empty($data['full_name']))     $errors[] = "Customer Name is required.";

    if (!empty($errors)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => implode("<br>", $errors)
        ];
        header("Location: customer_pricing.php");
        exit;
    }

    // Update or Insert
    if ($pricing_id > 0 && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {
        // Admin, customer_pricer, or sales_order_maker can update existing records
        $sql = "UPDATE customer_pricing SET 
                customer_code = ?, full_name = ?, truck_code = ?, plate_number = ?,
                truck_type = ?, body_type = ?, wheel_count = ?,
                rate_per_km = ?, rate_per_hour = ?, rate_per_trip = ?, minimum_charge = ?,
                rate_per_kg = ?, rate_per_cbm = ?,
                zone_from = ?, zone_to = ?, min_distance_km = ?, max_distance_km = ?,
                min_weight_kg = ?, max_weight_kg = ?, effective_from = ?, effective_until = ?,
                status = ?, notes = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssssssddddddssddddsssssi",
            $data['customer_code'], $data['full_name'], $data['truck_code'], $data['plate_number'],
            $data['truck_type'], $data['body_type'], $data['wheel_count'],
            $data['rate_per_km'], $data['rate_per_hour'], $data['rate_per_trip'], $data['minimum_charge'],
            $data['rate_per_kg'], $data['rate_per_cbm'],
            $data['zone_from'], $data['zone_to'], $data['min_distance_km'], $data['max_distance_km'],
            $data['min_weight_kg'], $data['max_weight_kg'], $data['effective_from'], $data['effective_until'],
            $data['status'], $data['notes'], $username, $pricing_id
        );
        
        $action = "updated";
    } else {
        // Both admin, customer_pricer, and sales_order_maker can add new records
       $sql = "INSERT INTO customer_pricing (
        customer_code, full_name, truck_code, plate_number,
        truck_type, body_type, wheel_count,
        rate_per_km, rate_per_hour, rate_per_trip, minimum_charge,
        rate_per_kg, rate_per_cbm,
        zone_from, zone_to, min_distance_km, max_distance_km,
        min_weight_kg, max_weight_kg, effective_from, effective_until,
        status, notes, created_at, created_by, updated_at, updated_by
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, NOW(), ?, NOW(), ?
    )";

$stmt = mysqli_prepare($conn, $sql);

mysqli_stmt_bind_param(
    $stmt,
    "sssssssddddddssddddssssss",
    $data['customer_code'],
    $data['full_name'],
    $data['truck_code'],
    $data['plate_number'],
    $data['truck_type'],
    $data['body_type'],
    $data['wheel_count'],
    $data['rate_per_km'],
    $data['rate_per_hour'],
    $data['rate_per_trip'],
    $data['minimum_charge'],
    $data['rate_per_kg'],
    $data['rate_per_cbm'],
    $data['zone_from'],
    $data['zone_to'],
    $data['min_distance_km'],
    $data['max_distance_km'],
    $data['min_weight_kg'],
    $data['max_weight_kg'],
    $data['effective_from'],
    $data['effective_until'],
    $data['status'],
    $data['notes'],
    $username,
    $username
);

$action = "added";

    }

    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['flash_message'] = [
            'type' => 'success',
            'text' => "Pricing record $action successfully!"
        ];
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Failed to save pricing record: " . mysqli_error($conn)
        ];
    }
    mysqli_stmt_close($stmt);
    
    header("Location: customer_pricing.php");
    exit;
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Load pricing records with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $pricing_query = mysqli_query($conn, "
        SELECT * FROM customer_pricing 
        WHERE customer_code LIKE '%$search_term%' 
        OR full_name LIKE '%$search_term%'
        OR truck_code LIKE '%$search_term%'
        OR plate_number LIKE '%$search_term%'
        OR truck_type LIKE '%$search_term%'
        OR body_type LIKE '%$search_term%'
        OR wheel_count LIKE '%$search_term%'
        OR zone_from LIKE '%$search_term%'
        OR zone_to LIKE '%$search_term%'
        ORDER BY created_at ASC
    ");
} else {
    $pricing_query = mysqli_query($conn, "
        SELECT * FROM customer_pricing 
        ORDER BY created_at ASC
    ");
}

// Get edit record if editing (Admin, customer_pricer, or sales_order_maker can edit)
$edit_record = null;
if (isset($_GET['edit_id']) && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {
    $edit_id = intval($_GET['edit_id']);
    $edit_query = mysqli_query($conn, "SELECT * FROM customer_pricing WHERE id = $edit_id");
    $edit_record = mysqli_fetch_assoc($edit_query);
}

// Fetch customers for dropdown
$customers_query = mysqli_query($conn, "SELECT customer_code, full_name FROM customer_masterlist ORDER BY full_name");
$customers_list = [];
while ($c = mysqli_fetch_assoc($customers_query)) {
    $customers_list[] = $c;
}

// Fetch trucks with all details
$trucks_query = mysqli_query($conn, "SELECT truck_code, plate_number, truck_type, body_type, wheel_count FROM truck_masterlist ORDER BY truck_code");
$trucks_list = [];
while ($t = mysqli_fetch_assoc($trucks_query)) {
    $trucks_list[] = $t;
}

// Fetch DISTINCT truck types, body types, and wheel counts from truck_masterlist
$truck_types = [];
$body_types = [];
$wheel_counts = [];

$type_query = mysqli_query($conn, "SELECT DISTINCT truck_type FROM truck_masterlist WHERE truck_type IS NOT NULL AND truck_type != '' ORDER BY truck_type");
while ($row = mysqli_fetch_assoc($type_query)) {
    $truck_types[] = $row['truck_type'];
}

$body_query = mysqli_query($conn, "SELECT DISTINCT body_type FROM truck_masterlist WHERE body_type IS NOT NULL AND body_type != '' ORDER BY body_type");
while ($row = mysqli_fetch_assoc($body_query)) {
    $body_types[] = $row['body_type'];
}

$wheel_query = mysqli_query($conn, "SELECT DISTINCT wheel_count FROM truck_masterlist WHERE wheel_count IS NOT NULL AND wheel_count != '' ORDER BY wheel_count");
while ($row = mysqli_fetch_assoc($wheel_query)) {
    $wheel_counts[] = $row['wheel_count'];
}

// JSON encode for JS use
$customers_json = json_encode($customers_list);
$trucks_json    = json_encode($trucks_list);
$truck_types_json = json_encode($truck_types);
$body_types_json = json_encode($body_types);
$wheel_counts_json = json_encode($wheel_counts);
$edit_record_json = $edit_record ? json_encode($edit_record) : 'null';

// Determine if user can modify data (admin, customer_pricer, or sales_order_maker)
$can_modify = $is_admin || $is_customer_pricer || $is_sales_order_maker;
// Determine if user can delete (admin, customer_pricer, or sales_order_maker)
$can_delete = $is_admin || $is_customer_pricer || $is_sales_order_maker;
// Determine if user can edit (admin, customer_pricer, or sales_order_maker)
$can_edit = $is_admin || $is_customer_pricer || $is_sales_order_maker;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Pricing | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/pricing.css?v=<?= time(); ?>">
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

<!-- PHP → JS data -->
<script>
    const CUSTOMERS_DATA = <?= $customers_json ?>;
    const TRUCKS_DATA    = <?= $trucks_json ?>;
    const TRUCK_TYPES    = <?= $truck_types_json ?>;
    const BODY_TYPES     = <?= $body_types_json ?>;
    const WHEEL_COUNTS   = <?= $wheel_counts_json ?>;
    const EDIT_RECORD    = <?= $edit_record_json ?>;

    const customerByCode = {};
    const customerByName = {};
    CUSTOMERS_DATA.forEach(c => {
        customerByCode[c.customer_code] = c.full_name;
        customerByName[c.full_name]     = c.customer_code;
    });

    // Build truck lookup maps
    const truckByCode = {};
    const truckByPlate = {};
    
    TRUCKS_DATA.forEach(t => {
        truckByCode[t.truck_code] = t;
        truckByPlate[t.plate_number] = t;
    });

    // Get truck code from combination of fields
    function getTruckCode(plate_number, truck_type, body_type, wheel_count) {
        let match = null;
        for (const truck of TRUCKS_DATA) {
            let matches = true;
            if (plate_number && truck.plate_number !== plate_number) matches = false;
            if (truck_type && truck.truck_type !== truck_type) matches = false;
            if (body_type && truck.body_type !== body_type) matches = false;
            if (wheel_count && String(truck.wheel_count) !== String(wheel_count)) matches = false;
            
            if (matches) {
                match = truck;
                break;
            }
        }
        return match ? match.truck_code : null;
    }

    // Get truck details by code
    function getTruckDetailsByCode(code) {
        return truckByCode[code] || null;
    }

    // Get truck details by plate
    function getTruckDetailsByPlate(plate) {
        return truckByPlate[plate] || null;
    }

    // Wire truck selection - using dropdowns populated from truck_masterlist
    function wireTruckSelects(codeId, plateId, typeId, bodyId, wheelId) {
        const codeEl = document.getElementById(codeId);
        const plateEl = document.getElementById(plateId);
        const typeEl = document.getElementById(typeId);
        const bodyEl = document.getElementById(bodyId);
        const wheelEl = document.getElementById(wheelId);
        
        if (!codeEl || !plateEl || !typeEl || !bodyEl || !wheelEl) return;

        // Function to update truck fields when truck code is selected
        codeEl.addEventListener('change', function() {
            const details = getTruckDetailsByCode(this.value);
            if (details) {
                plateEl.value = details.plate_number || '';
                setSelectValue(typeEl, details.truck_type || '');
                setSelectValue(bodyEl, details.body_type || '');
                setSelectValue(wheelEl, details.wheel_count || '');
            } else {
                if (this.value === '' || this.value === '-- Select Truck --') {
                    plateEl.value = '';
                    setSelectValue(typeEl, '');
                    setSelectValue(bodyEl, '');
                    setSelectValue(wheelEl, '');
                }
            }
        });

        // Function to set select value (works for both select and input)
        function setSelectValue(el, value) {
            if (el.tagName === 'SELECT') {
                el.value = value;
            } else {
                el.value = value;
            }
        }

        // Function to find matching truck based on combination of fields
        function findMatchingTruck() {
            const plate = plateEl.value.trim();
            const type = typeEl.value;
            const body = bodyEl.value;
            const wheel = wheelEl.value;
            
            let match = null;
            for (const truck of TRUCKS_DATA) {
                let matches = true;
                if (plate && truck.plate_number !== plate) matches = false;
                if (type && truck.truck_type !== type) matches = false;
                if (body && truck.body_type !== body) matches = false;
                if (wheel && String(truck.wheel_count) !== String(wheel)) matches = false;
                
                if (matches) {
                    match = truck;
                    break;
                }
            }
            
            if (match) {
                codeEl.value = match.truck_code;
                plateEl.value = match.plate_number || '';
                setSelectValue(typeEl, match.truck_type || '');
                setSelectValue(bodyEl, match.body_type || '');
                setSelectValue(wheelEl, match.wheel_count || '');
            }
            
            return match;
        }

        // When any truck detail field changes, try to find matching truck
        [plateEl, typeEl, bodyEl, wheelEl].forEach(el => {
            el.addEventListener('change', function() {
                if (plateEl.value || typeEl.value || bodyEl.value || wheelEl.value) {
                    findMatchingTruck();
                } else {
                    codeEl.value = '';
                }
            });
        });
    }

    function wireCustomerSelects(codeId, nameId) {
        const codeEl = document.getElementById(codeId);
        const nameEl = document.getElementById(nameId);
        if (!codeEl || !nameEl) return;
        codeEl.addEventListener('change', () => { const m = customerByCode[codeEl.value]; if (m) nameEl.value = m; });
        nameEl.addEventListener('change', () => { const m = customerByName[nameEl.value]; if (m) codeEl.value = m; });
    }

    function populateTruckDropdowns(prefix) {
        // Populate truck type dropdown
        const typeSelect = document.getElementById(prefix + '_truck_type');
        if (typeSelect && typeSelect.tagName === 'SELECT') {
            // Clear existing options except first
            while (typeSelect.options.length > 1) {
                typeSelect.remove(1);
            }
            TRUCK_TYPES.forEach(type => {
                const opt = document.createElement('option');
                opt.value = type;
                opt.textContent = type;
                typeSelect.appendChild(opt);
            });
        }

        // Populate body type dropdown
        const bodySelect = document.getElementById(prefix + '_body_type');
        if (bodySelect && bodySelect.tagName === 'SELECT') {
            while (bodySelect.options.length > 1) {
                bodySelect.remove(1);
            }
            BODY_TYPES.forEach(type => {
                const opt = document.createElement('option');
                opt.value = type;
                opt.textContent = type;
                bodySelect.appendChild(opt);
            });
        }

        // Populate wheel count dropdown
        const wheelSelect = document.getElementById(prefix + '_wheel_count');
        if (wheelSelect && wheelSelect.tagName === 'SELECT') {
            while (wheelSelect.options.length > 1) {
                wheelSelect.remove(1);
            }
            WHEEL_COUNTS.forEach(count => {
                const opt = document.createElement('option');
                opt.value = count;
                opt.textContent = count + ' Wheels';
                wheelSelect.appendChild(opt);
            });
        }
    }
</script>

<!-- Include Sidebar -->
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div>
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Customer Pricing</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">

        <?php if ($flash): ?>
            <div id="flash-message" class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
                <?= $flash['text'] ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Customer Pricing Management</h1>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 300px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Customer, Truck, Zone..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <?php if ($can_modify): ?>
                <button onclick="openAddModal()" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                    <i data-lucide="plus" style="width: 18px;"></i> Add New Pricing
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($pricing_query); ?> found)
                <a href="customer_pricing.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
    <div style="overflow-x: auto;">
         <table>
            <thead>
                 <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Truck/Plate</th>
                    <th>Truck Type</th>
                    <th>Body Type</th>
                    <th>Wheels</th>
                    <!-- <th>Rate/KM</th> -->
                    <th>Rate/Trip</th>
                    <!-- <th>Min Charge</th> -->
                    <th>Destination</th>
                    <th>Effective</th>
                    <th>Status</th>
                    <?php if ($can_delete): ?>
                    <th style="text-align: center;">Actions</th>
                    <?php endif; ?>
                 </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($pricing_query) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($pricing_query)): ?>
                     <tr>
                        <td><?= $row['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['customer_code']) ?></strong><br>
                            <small style="color:#64748b;"><?= htmlspecialchars($row['full_name']) ?></small>
                        </td>
                        <td>
                            <?php if ($row['truck_code'] || $row['plate_number']): ?>
                                <strong><?= htmlspecialchars($row['truck_code'] ?? '-') ?></strong><br>
                                <small style="color:#64748b;">
                                    <?= htmlspecialchars($row['plate_number'] ?? '-') ?>
                                </small>
                            <?php else: ?>
                                <span style="color:#94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['truck_type'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['body_type'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['wheel_count'] ?? '-') ?></td>
                        <!-- <td><?= $row['rate_per_km']    ? '₱' . number_format($row['rate_per_km'], 2)    : '-' ?></td> -->
                        <td><?= $row['rate_per_trip']  ? '₱' . number_format($row['rate_per_trip'], 2)  : '-' ?></td>
                        <!-- <td><?= $row['minimum_charge'] ? '₱' . number_format($row['minimum_charge'], 2) : '-' ?></td> -->
                        <td>
                            <?php if ($row['zone_from'] || $row['zone_to']): ?>
                                <small><?= htmlspecialchars($row['zone_from'] ?: '-') ?> → <?= htmlspecialchars($row['zone_to'] ?: '-') ?></small>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['effective_from']): ?>
                                <small><?= date('M d, Y', strtotime($row['effective_from'])) ?></small>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $row['status'] === 'active' ? 'active' : ($row['status'] === 'expired' ? 'expired' : 'inactive') ?>">
                                <?= ucfirst(htmlspecialchars($row['status'])) ?>
                            </span>
                        </td>
                        <?php if ($can_delete): ?>
                        <td>
                            <div class="action-buttons">
                                <button
                                    onclick='openEditModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)'
                                    class="btn-icon btn-edit"
                                    title="Edit Pricing">
                                    <i data-lucide="edit-2" style="width:18px;"></i>
                                </button>
                                <a href="?delete_id=<?= $row['id'] ?>"
                                   class="btn-icon btn-delete"
                                   onclick="return confirm('Are you sure you want to delete this pricing record?')"
                                   title="Delete Pricing">
                                    <i data-lucide="trash-2" style="width:18px;"></i>
                                </a>
                            </div>
                         </td>
                        <?php endif; ?>
                     </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                     <tr>
                        <td colspan="<?php echo $can_delete ? '12' : '11'; ?>" style="text-align:center; padding:40px; color:#94a3b8;">
                            <?php if (!empty($search_term)): ?>
                                No pricing records found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                <br>
                                <a href="customer_pricing.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                    View all records
                                </a>
                            <?php else: ?>
                                No pricing records found. Add your first pricing record above.
                            <?php endif; ?>
                         </td>
                     </tr>
                <?php endif; ?>
            </tbody>
         </table>
    </div>
</div>

    </div>
</main>

<!-- Add Pricing Modal - Only shown to users who can modify -->
<?php if ($can_modify): ?>
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">
                <i data-lucide="file-plus" style="width:22px;"></i>
                Add New Pricing Record
            </div>
            <button class="modal-close" onclick="closeAddModal()">×</button>
        </div>
        <form method="POST" id="add-pricing-form">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section-title">
                        <i data-lucide="users" style="width:16px;"></i> Customer & Truck Information
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Customer Code <span class="required">*</span></label>
                            <select name="customer_code" id="add_customer_code" required>
                                <option value="">-- Select Customer Code --</option>
                                <?php foreach ($customers_list as $c): ?>
                                <option value="<?= htmlspecialchars($c['customer_code']) ?>"><?= htmlspecialchars($c['customer_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Customer Name <span class="required">*</span></label>
                            <select name="full_name" id="add_full_name" required>
                                <option value="">-- Select Customer Name --</option>
                                <?php foreach ($customers_list as $c): ?>
                                <option value="<?= htmlspecialchars($c['full_name']) ?>"><?= htmlspecialchars($c['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Truck Code</label>
                            <select name="truck_code" id="add_truck_code">
                                <option value="">-- Select Truck --</option>
                                <?php foreach ($trucks_list as $t): ?>
                                <option value="<?= htmlspecialchars($t['truck_code']) ?>">
                                    <?= htmlspecialchars($t['truck_code']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:#64748b; font-size:11px; display:block; margin-top:2px;">
                                Select truck OR choose from the options below to auto-match
                            </small>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Plate Number</label>
                            <input type="text" name="plate_number" id="add_plate_number" placeholder="Enter plate number" autocomplete="off" list="add_plate_list">
                            <datalist id="add_plate_list">
                                <?php foreach ($trucks_list as $t): ?>
                                    <option value="<?= htmlspecialchars($t['plate_number']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Truck Type</label>
                            <select name="truck_type" id="add_truck_type">
                                <option value="">-- Select Truck Type --</option>
                                <?php foreach ($truck_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Body Type</label>
                            <select name="body_type" id="add_body_type">
                                <option value="">-- Select Body Type --</option>
                                <?php foreach ($body_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Wheel Count</label>
                            <select name="wheel_count" id="add_wheel_count">
                                <option value="">-- Select Wheel Count --</option>
                                <?php foreach ($wheel_counts as $count): ?>
                                <option value="<?= htmlspecialchars($count) ?>"><?= htmlspecialchars($count) ?> Wheels</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">
                        <i data-lucide="philippine-peso" style="width:16px;"></i> Rate Information
                    </div>

                    <div class="col-2"><div class="form-group"><label>Rate per Trip (₱)</label><input type="number" step="0.0001" name="rate_per_trip"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per KM (₱)</label><input type="number" step="0.0001" name="rate_per_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per Hour (₱)</label><input type="number" step="0.0001" name="rate_per_hour"></div></div>
                    
                    <div class="col-2"><div class="form-group"><label>Minimum Charge (₱)</label><input type="number" step="0.0001" name="minimum_charge"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per KG (₱)</label><input type="number" step="0.0001" name="rate_per_kg"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per CBM (₱)</label><input type="number" step="0.0001" name="rate_per_cbm"></div></div>

                    <div class="form-section-title">
                        <i data-lucide="map-pin" style="width:16px;"></i> Zone & Distance Parameters
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Zone From</label>
                            <select name="zone_from">
                                <option value="">-- Select --</option>
                                <option value="Lapu-Lapu City Yard">Lapu-Lapu City Yard</option>
                                <option value="Talisay City Yard">Talisay City Yard</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-3"><div class="form-group"><label>Zone To</label><input type="text" name="zone_to" placeholder="e.g., Mandaue City"></div></div>
                    <div class="col-2"><div class="form-group"><label>Min Distance (KM)</label><input type="number" step="0.0001" name="min_distance_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Max Distance (KM)</label><input type="number" step="0.0001" name="max_distance_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Min Weight (KG)</label><input type="number" step="0.0001" name="min_weight_kg"></div></div>
                    <div class="col-2"><div class="form-group"><label>Max Weight (KG)</label><input type="number" step="0.0001" name="max_weight_kg"></div></div>

                    <div class="form-section-title">
                        <i data-lucide="calendar" style="width:16px;"></i> Validity & Status
                    </div>

                    <div class="col-3"><div class="form-group"><label>Effective From</label><input type="date" name="effective_from"></div></div>
                    <div class="col-3"><div class="form-group"><label>Effective Until</label><input type="date" name="effective_until"></div></div>
                    
                    <div class="col-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" rows="3" placeholder="Additional notes or comments"></textarea>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeAddModal()" class="btn btn-secondary">
                    <i data-lucide="x"></i> Cancel
                </button>
                <button type="submit" name="save_pricing" class="btn btn-primary">
                    <i data-lucide="save"></i> Save Pricing
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Edit Pricing Modal - Only visible to users who can edit -->
<?php if ($can_edit): ?>
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title">
                <i data-lucide="file-edit" style="width:22px;"></i>
                Edit Pricing Record
            </div>
            <button class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        <form method="POST" id="edit-pricing-form">
            <input type="hidden" name="pricing_id" id="edit_pricing_id">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section-title">
                        <i data-lucide="users" style="width:16px;"></i> Customer & Truck Information
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Customer Code <span class="required">*</span></label>
                            <select name="customer_code" id="edit_customer_code" required>
                                <option value="">-- Select Customer Code --</option>
                                <?php foreach ($customers_list as $c): ?>
                                <option value="<?= htmlspecialchars($c['customer_code']) ?>"><?= htmlspecialchars($c['customer_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Customer Name <span class="required">*</span></label>
                            <select name="full_name" id="edit_full_name" required>
                                <option value="">-- Select Customer Name --</option>
                                <?php foreach ($customers_list as $c): ?>
                                <option value="<?= htmlspecialchars($c['full_name']) ?>"><?= htmlspecialchars($c['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Truck Code</label>
                            <select name="truck_code" id="edit_truck_code">
                                <option value="">-- Select Truck --</option>
                                <?php foreach ($trucks_list as $t): ?>
                                <option value="<?= htmlspecialchars($t['truck_code']) ?>">
                                    <?= htmlspecialchars($t['truck_code']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:#64748b; font-size:11px; display:block; margin-top:2px;">
                                Select truck OR choose from the options below to auto-match
                            </small>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Plate Number</label>
                            <input type="text" name="plate_number" id="edit_plate_number" placeholder="Enter plate number" autocomplete="off" list="edit_plate_list">
                            <datalist id="edit_plate_list">
                                <?php foreach ($trucks_list as $t): ?>
                                    <option value="<?= htmlspecialchars($t['plate_number']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Truck Type</label>
                            <select name="truck_type" id="edit_truck_type">
                                <option value="">-- Select Truck Type --</option>
                                <?php foreach ($truck_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Body Type</label>
                            <select name="body_type" id="edit_body_type">
                                <option value="">-- Select Body Type --</option>
                                <?php foreach ($body_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Wheel Count</label>
                            <select name="wheel_count" id="edit_wheel_count">
                                <option value="">-- Select Wheel Count --</option>
                                <?php foreach ($wheel_counts as $count): ?>
                                <option value="<?= htmlspecialchars($count) ?>"><?= htmlspecialchars($count) ?> Wheels</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">
                        <i data-lucide="dollar-sign" style="width:16px;"></i> Rate Information
                    </div>

                    <div class="col-2"><div class="form-group"><label>Rate per Trip (₱)</label><input type="number" step="0.0001" name="rate_per_trip" id="edit_rate_per_trip"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per KM (₱)</label><input type="number" step="0.0001" name="rate_per_km" id="edit_rate_per_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per Hour (₱)</label><input type="number" step="0.0001" name="rate_per_hour" id="edit_rate_per_hour"></div></div>
                    
                    <div class="col-2"><div class="form-group"><label>Minimum Charge (₱)</label><input type="number" step="0.0001" name="minimum_charge" id="edit_minimum_charge"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per KG (₱)</label><input type="number" step="0.0001" name="rate_per_kg" id="edit_rate_per_kg"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per CBM (₱)</label><input type="number" step="0.0001" name="rate_per_cbm" id="edit_rate_per_cbm"></div></div>

                    <div class="form-section-title">
                        <i data-lucide="map-pin" style="width:16px;"></i> Zone & Distance Parameters
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Zone From</label>
                            <select name="zone_from" id="edit_zone_from">
                                <option value="">-- Select --</option>
                                <option value="Lapu-Lapu City Yard">Lapu-Lapu City Yard</option>
                                <option value="Talisay City Yard">Talisay City Yard</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-3"><div class="form-group"><label>Zone To</label><input type="text" name="zone_to" id="edit_zone_to" placeholder="e.g., Mandaue City"></div></div>
                    <div class="col-2"><div class="form-group"><label>Min Distance (KM)</label><input type="number" step="0.0001" name="min_distance_km" id="edit_min_distance_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Max Distance (KM)</label><input type="number" step="0.0001" name="max_distance_km" id="edit_max_distance_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Min Weight (KG)</label><input type="number" step="0.0001" name="min_weight_kg" id="edit_min_weight_kg"></div></div>
                    <div class="col-2"><div class="form-group"><label>Max Weight (KG)</label><input type="number" step="0.0001" name="max_weight_kg" id="edit_max_weight_kg"></div></div>

                    <div class="form-section-title">
                        <i data-lucide="calendar" style="width:16px;"></i> Validity & Status
                    </div>

                    <div class="col-3"><div class="form-group"><label>Effective From</label><input type="date" name="effective_from" id="edit_effective_from"></div></div>
                    <div class="col-3"><div class="form-group"><label>Effective Until</label><input type="date" name="effective_until" id="edit_effective_until"></div></div>
                    
                    <div class="col-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="edit_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" id="edit_notes" rows="3" placeholder="Additional notes or comments"></textarea>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary">
                    <i data-lucide="x"></i> Cancel
                </button>
                <button type="submit" name="save_pricing" class="btn btn-success">
                    <i data-lucide="save"></i> Update Pricing
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    lucide.createIcons();

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

    //  Search Functionality 
    const searchInput = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearSearch');
    const searchForm = searchInput?.closest('form');

    // Auto-submit on input (with debounce)
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

    // Auto-dismiss flash message
    setTimeout(() => {
        const flash = document.getElementById('flash-message');
        if (flash) {
            setTimeout(() => flash.remove(), 3000);
        }
    }, 100);

    //  Add Modal Controls 
    const addModal = document.getElementById('addModal');
    const editModalElem = document.getElementById('editModal');

    function openAddModal() {
        if (addModal) {
            addModal.classList.add('open');
            document.body.style.overflow = 'hidden';
            const form = document.getElementById('add-pricing-form');
            if (form) form.reset();
        }
    }
    function closeAddModal() {
        if (addModal) {
            addModal.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    //  Edit Modal Controls 
    function openEditModal(row) {
        if (!editModalElem) return;
        
        document.getElementById('edit_pricing_id').value = row.id;

        setSelect('edit_customer_code', row.customer_code);
        setSelect('edit_full_name',     row.full_name);
        setSelect('edit_truck_code',    row.truck_code);
        document.getElementById('edit_plate_number').value = row.plate_number ?? '';
        setSelect('edit_truck_type',   row.truck_type ?? '');
        setSelect('edit_body_type',    row.body_type ?? '');
        setSelect('edit_wheel_count',  row.wheel_count ?? '');

        document.getElementById('edit_rate_per_km').value    = row.rate_per_km    ?? '';
        document.getElementById('edit_rate_per_hour').value  = row.rate_per_hour  ?? '';
        document.getElementById('edit_rate_per_trip').value  = row.rate_per_trip  ?? '';
        document.getElementById('edit_minimum_charge').value = row.minimum_charge ?? '';
        document.getElementById('edit_rate_per_kg').value    = row.rate_per_kg    ?? '';
        document.getElementById('edit_rate_per_cbm').value   = row.rate_per_cbm   ?? '';

        setSelect('edit_zone_from', row.zone_from);
        document.getElementById('edit_zone_to').value          = row.zone_to          ?? '';
        document.getElementById('edit_min_distance_km').value  = row.min_distance_km  ?? '';
        document.getElementById('edit_max_distance_km').value  = row.max_distance_km  ?? '';
        document.getElementById('edit_min_weight_kg').value    = row.min_weight_kg    ?? '';
        document.getElementById('edit_max_weight_kg').value    = row.max_weight_kg    ?? '';

        document.getElementById('edit_effective_from').value  = row.effective_from  ?? '';
        document.getElementById('edit_effective_until').value = row.effective_until ?? '';
        setSelect('edit_status', row.status ?? 'active');

        document.getElementById('edit_notes').value = row.notes ?? '';

        lucide.createIcons();
        editModalElem.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    
    function closeEditModal() {
        if (editModalElem) {
            editModalElem.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    function setSelect(id, value) {
        const el = document.getElementById(id);
        if (el && value != null) {
            el.value = value;
        }
    }

    //  Close all modals with Escape 
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (addModal?.classList.contains('open'))  closeAddModal();
        if (editModalElem?.classList.contains('open')) closeEditModal();
        if (modal?.style.display === 'flex') closeModal();
    });

    //  Backdrop click close for add & edit 
    if (addModal) {
        addModal.addEventListener('click', e => {
            if (e.target.id === 'addModal') closeAddModal();
        });
    }
    if (editModalElem) {
        editModalElem.addEventListener('click', e => {
            if (e.target.id === 'editModal') closeEditModal();
        });
    }

    //  Auto-link dropdowns 
    wireCustomerSelects('add_customer_code', 'add_full_name');
    wireTruckSelects('add_truck_code', 'add_plate_number', 'add_truck_type', 'add_body_type', 'add_wheel_count');
    wireCustomerSelects('edit_customer_code', 'edit_full_name');
    wireTruckSelects('edit_truck_code', 'edit_plate_number', 'edit_truck_type', 'edit_body_type', 'edit_wheel_count');

    setTimeout(() => lucide.createIcons(), 100);
</script>
</body>
</html>