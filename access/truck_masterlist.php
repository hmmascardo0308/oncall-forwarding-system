<?php
// truck_masterlist.php
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
$full_name  = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Use centralized access control
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

// Role display name - now using centralized function
$role_display_name = getRoleDisplayName($user_roles);

// Check if user can manage trucks (add/edit)
// - admin: full access
// - truck_register: can add/edit
// - user: can add/edit (existing)
// - service_invoice_maker: view only (cannot add/edit)
$can_manage_trucks = $is_admin || in_array('user', $user_roles) || in_array('truck_register', $user_roles);

// Check if user has view access (all authenticated users should have view access)
$can_view_trucks = $is_admin || in_array('user', $user_roles) || in_array('truck_register', $user_roles) || in_array('service_invoice_maker', $user_roles);

if (!$can_view_trucks) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Truck Masterlist page."
    ];
    header("Location: home.php");
    exit;
}

// Helper function to uppercase text fields
function uppercaseFields($data) {
    $uppercase_fields = ['plate_number', 'vin', 'brand', 'model', 'truck_type', 'body_type', 
                         'axle_configuration', 'fuel_type', 'parking_place', 'notes', 'footer'];
    
    foreach ($uppercase_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = strtoupper(trim($data[$field]));
        }
    }
    
    return $data;
}

// Generate next truck code (TRK-001, TRK-002, ...)
$next_code = 'TRK-001';
$code_query = "SELECT truck_code 
               FROM truck_masterlist 
               WHERE truck_code LIKE 'TRK-%' 
               ORDER BY CAST(SUBSTRING(truck_code, 5) AS UNSIGNED) DESC 
               LIMIT 1";

$code_result = mysqli_query($conn, $code_query);
if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
    if (preg_match('/^TRK-(\d+)$/', $row['truck_code'], $matches)) {
        $next_number = (int)$matches[1] + 1;
        $next_code   = sprintf('TRK-%03d', $next_number);
    }
}

// Handle Add Truck - Only allow if user can manage trucks
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_truck'])) {
    // Check permission
    if (!$can_manage_trucks) {
        $message = "You don't have permission to add trucks.";
        $message_type = 'error';
    } else {
        $fields = [
            'truck_code'          => trim($_POST['truck_code'] ?? ''),
            'plate_number'        => trim($_POST['plate_number'] ?? ''),
            'vin'                 => trim($_POST['vin'] ?? ''),
            'brand'               => trim($_POST['brand'] ?? ''),
            'model'               => trim($_POST['model'] ?? ''),
            'year_manufactured'   => trim($_POST['year_manufactured'] ?? ''),
            'truck_type'          => trim($_POST['truck_type'] ?? ''),
            'body_type'           => trim($_POST['body_type'] ?? ''),
            'axle_configuration'  => trim($_POST['axle_configuration'] ?? ''),
            'wheel_count'         => trim($_POST['wheel_count'] ?? ''),
            'vehicle_weight'      => trim($_POST['vehicle_weight'] ?? ''),
            'payload_capacity_kg' => trim($_POST['payload_capacity_kg'] ?? ''),
            'payload_capacity_cbm'=> trim($_POST['payload_capacity_cbm'] ?? ''),
            'cargo_length_m'      => trim($_POST['cargo_length_m'] ?? ''),
            'cargo_width_m'       => trim($_POST['cargo_width_m'] ?? ''),
            'cargo_height_m'      => trim($_POST['cargo_height_m'] ?? ''),
            'footer'              => trim($_POST['footer'] ?? ''),
            'fuel_type'           => trim($_POST['fuel_type'] ?? ''),
            'status'              => trim($_POST['status'] ?? 'Active'),
            'current_mileage'     => trim($_POST['current_mileage'] ?? ''),
            'last_maintenance_date' => trim($_POST['last_maintenance_date'] ?? ''),
            'parking_place'       => trim($_POST['parking_place'] ?? ''),
            'notes'               => trim($_POST['notes'] ?? ''),
        ];

        // Apply uppercase transformation to text fields
        $fields = uppercaseFields($fields);

        // Set current_mileage to NULL if empty
        $fields['current_mileage'] = !empty($fields['current_mileage']) ? $fields['current_mileage'] : null;

        // Required fields
        $required = ['truck_code', 'plate_number', 'brand', 'model'];
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
            // Check duplicate truck_code
            $check_query = "SELECT id FROM truck_masterlist WHERE truck_code = ? LIMIT 1";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "s", $fields['truck_code']);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);

            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $message = "Truck code <strong>" . htmlspecialchars($fields['truck_code']) . "</strong> already exists.";
                $message_type = 'error';
            } else {
                $created_by = $_SESSION['username'];
                $created_at = date('Y-m-d H:i:s');

                $insert_query = "
    INSERT INTO truck_masterlist (
        truck_code, plate_number, vin, brand, model, year_manufactured,
        truck_type, body_type, axle_configuration, wheel_count, vehicle_weight,
        payload_capacity_kg, payload_capacity_cbm, cargo_length_m, cargo_width_m, cargo_height_m,
        footer, fuel_type, status, current_mileage, last_maintenance_date,
        parking_place, notes, created_at, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = mysqli_prepare($conn, $insert_query);
mysqli_stmt_bind_param(
    $stmt, 
    "sssssissssidddddsssssssss",
    $fields['truck_code'],
    $fields['plate_number'],
    $fields['vin'],
    $fields['brand'],
    $fields['model'],
    $fields['year_manufactured'],
    $fields['truck_type'],
    $fields['body_type'],
    $fields['axle_configuration'],
    $fields['wheel_count'],
    $fields['vehicle_weight'],
    $fields['payload_capacity_kg'],
    $fields['payload_capacity_cbm'],
    $fields['cargo_length_m'],
    $fields['cargo_width_m'],
    $fields['cargo_height_m'],
    $fields['footer'],
    $fields['fuel_type'],
    $fields['status'],
    $fields['current_mileage'],
    $fields['last_maintenance_date'],
    $fields['parking_place'],
    $fields['notes'],
    $created_at,
    $created_by
);

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Truck <strong>" . htmlspecialchars($fields['plate_number']) . "</strong> registered successfully!";
                    $message_type = 'success';
                    
                    // Update next code
                    $code_result = mysqli_query($conn, $code_query);
                    if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
                        if (preg_match('/^TRK-(\d+)$/', $row['truck_code'], $matches)) {
                            $next_number = (int)$matches[1] + 1;
                            $next_code   = sprintf('TRK-%03d', $next_number);
                        }
                    }
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

// Handle Edit Truck Submission - Only allow if user can manage trucks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_truck'])) {
    // Check permission
    if (!$can_manage_trucks) {
        $message = "You don't have permission to edit trucks.";
        $message_type = 'error';
    } else {
        $truck_id = intval($_POST['truck_id']);
        
        $fields = [
            'plate_number'        => trim($_POST['plate_number'] ?? ''),
            'vin'                 => trim($_POST['vin'] ?? ''),
            'brand'               => trim($_POST['brand'] ?? ''),
            'model'               => trim($_POST['model'] ?? ''),
            'year_manufactured'   => trim($_POST['year_manufactured'] ?? ''),
            'truck_type'          => trim($_POST['truck_type'] ?? ''),
            'body_type'           => trim($_POST['body_type'] ?? ''),
            'axle_configuration'  => trim($_POST['axle_configuration'] ?? ''),
            'wheel_count'         => trim($_POST['wheel_count'] ?? ''),
            'vehicle_weight'      => trim($_POST['vehicle_weight'] ?? ''),
            'payload_capacity_kg' => trim($_POST['payload_capacity_kg'] ?? ''),
            'payload_capacity_cbm'=> trim($_POST['payload_capacity_cbm'] ?? ''),
            'cargo_length_m'      => trim($_POST['cargo_length_m'] ?? ''),
            'cargo_width_m'       => trim($_POST['cargo_width_m'] ?? ''),
            'cargo_height_m'      => trim($_POST['cargo_height_m'] ?? ''),
            'footer'              => trim($_POST['footer'] ?? ''),
            'fuel_type'           => trim($_POST['fuel_type'] ?? ''),
            'status'              => trim($_POST['status'] ?? 'Active'),
            'current_mileage'     => trim($_POST['current_mileage'] ?? ''),
            'last_maintenance_date' => trim($_POST['last_maintenance_date'] ?? ''),
            'parking_place'       => trim($_POST['parking_place'] ?? ''),
            'notes'               => trim($_POST['notes'] ?? ''),
        ];

        // Apply uppercase transformation to text fields
        $fields = uppercaseFields($fields);

        // Set current_mileage to NULL if empty
        $fields['current_mileage'] = !empty($fields['current_mileage']) ? $fields['current_mileage'] : null;

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
            $update_query = "
                UPDATE truck_masterlist SET
                    plate_number = ?, vin = ?, brand = ?, model = ?, year_manufactured = ?,
                    truck_type = ?, body_type = ?, axle_configuration = ?, wheel_count = ?,
                    vehicle_weight = ?, payload_capacity_kg = ?, payload_capacity_cbm = ?,
                    cargo_length_m = ?, cargo_width_m = ?, cargo_height_m = ?,
                    footer = ?, fuel_type = ?, status = ?, current_mileage = ?,
                    last_maintenance_date = ?, parking_place = ?, notes = ?
                WHERE id = ?
            ";

            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param(
                $stmt, "ssssissssidddddsssssssi",
                $fields['plate_number'],
                $fields['vin'],
                $fields['brand'],
                $fields['model'],
                $fields['year_manufactured'],
                $fields['truck_type'],
                $fields['body_type'],
                $fields['axle_configuration'],
                $fields['wheel_count'],
                $fields['vehicle_weight'],
                $fields['payload_capacity_kg'],
                $fields['payload_capacity_cbm'],
                $fields['cargo_length_m'],
                $fields['cargo_width_m'],
                $fields['cargo_height_m'],
                $fields['footer'],
                $fields['fuel_type'],
                $fields['status'],
                $fields['current_mileage'],
                $fields['last_maintenance_date'],
                $fields['parking_place'],
                $fields['notes'],
                $truck_id
            );

            if (mysqli_stmt_execute($stmt)) {
                $message = "Truck <strong>" . htmlspecialchars($fields['plate_number']) . "</strong> updated successfully!";
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

// Fetch trucks with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT * FROM truck_masterlist 
              WHERE truck_code LIKE '%$search_term%' 
              OR plate_number LIKE '%$search_term%'
              OR vin LIKE '%$search_term%'
              OR brand LIKE '%$search_term%'
              OR model LIKE '%$search_term%'
              OR truck_type LIKE '%$search_term%'
              OR body_type LIKE '%$search_term%'
              OR parking_place LIKE '%$search_term%'
              OR footer LIKE '%$search_term%'
              ORDER BY truck_code ASC";
} else {
    $query = "SELECT * FROM truck_masterlist ORDER BY truck_code ASC";
}

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Truck Masterlist | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/truck.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Truck Masterlist</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <?php echo $message; ?>
                <button class="alert-close" onclick="closeAlert(this)" aria-label="Close alert">&times;</button>
            </div>
        <?php endif; ?>

        <!-- View-Only Notice for service_invoice_maker -->
        <?php if (!$can_manage_trucks && in_array('service_invoice_maker', $user_roles)): ?>
            <div class="view-only-notice">
                <i data-lucide="eye"></i>
                <span>You are in <strong>View-Only</strong> mode. You can view truck records but cannot add or edit trucks.</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Truck Fleet Directory</h1>
            
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
                
                <?php if ($can_manage_trucks): ?>
                <button id="openAddModal" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                    <i data-lucide="plus" style="width:18px;"></i> Register Truck
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($result); ?> found)
                <a href="truck_masterlist.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
             <table>
                <thead>
                     <tr>
                        <th>Truck Code</th>
                        <th>Plate Number</th>
                        <th>Brand & Model</th>
                        <th>Type / Body</th>
                        <th>Payload (kg / cbm)</th>
                        <th>Status</th>
                        <th>Action</th>
                     </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr data-truck-id="<?php echo $row['id']; ?>">
                                <td style="font-weight:600; color:var(--accent-blue);"><?php echo htmlspecialchars($row['truck_code'] ?? '—'); ?></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($row['plate_number'] ?? '—'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['vin'] ?: 'No VIN'); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['year_manufactured'] ?: '—'); ?></div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($row['truck_type'] ?: '—'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['body_type'] ?: '—'); ?></div>
                                </td>
                                <td>
                                    <div style="font-size:13px;"><?php echo $row['payload_capacity_kg'] ? number_format($row['payload_capacity_kg'], 0) . ' kg' : '—'; ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo $row['payload_capacity_cbm'] ? number_format($row['payload_capacity_cbm'], 1) . ' cbm' : ''; ?></div>
                                </td>
                                <td>
                                    <?php 
                                        $status = strtolower($row['status'] ?? 'unknown');
                                        $class = $status === 'active' ? 'status-active' : 
                                                 ($status === 'maintenance' ? 'status-maintenance' : 'status-inactive');
                                    ?>
                                    <span class="status-pill <?php echo $class; ?>">
                                        <?php echo htmlspecialchars($row['status'] ?: 'Unknown'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($can_manage_trucks): ?>
                                    <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <i data-lucide="edit-2" style="width:18px;"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="action-btn view-only-btn">
                                        <i data-lucide="eye" style="width:18px;"></i> View
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No trucks found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="truck_masterlist.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all trucks
                                    </a>
                                <?php else: ?>
                                    No trucks found.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</main>

<!-- Add Truck Modal -->
<div class="modal-overlay" id="addTruckModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Register New Truck</h2>
            <button id="closeModal" style="background:#f1f5f9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;">×</button>
        </div>

        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section"><i data-lucide="info"></i> Basic Information</div>

                    <div class="form-group col-2">
                        <label>Truck Code <span class="required">*</span></label>
                        <input type="text" name="truck_code" value="<?php echo htmlspecialchars($next_code); ?>" readonly required>
                    </div>

                    <div class="form-group col-2">
                        <label>Plate Number <span class="required">*</span></label>
                        <input type="text" name="plate_number" required placeholder="ABC-1234">
                    </div>

                    <div class="form-group col-2">
                        <label>VIN / Chassis No.</label>
                        <input type="text" name="vin" placeholder="17-digit VIN">
                    </div>

                    <div class="form-group col-2">
                        <label>Brand <span class="required">*</span></label>
                        <input type="text" name="brand" required placeholder="Isuzu, Hino, Fuso...">
                    </div>

                    <div class="form-group col-2">
                        <label>Model <span class="required">*</span></label>
                        <input type="text" name="model" required placeholder="Elf, Forward, Super Great...">
                    </div>

                    <div class="form-group col-2">
                        <label>Year</label>
                        <input type="number" name="year_manufactured" min="1980" max="<?php echo date('Y')+1; ?>" placeholder="<?php echo date('Y'); ?>">
                    </div>

                    <div class="form-section"><i data-lucide="truck"></i> Specifications</div>

                    <div class="form-group col-2">
                        <label>Truck Type</label>
                        <select name="truck_type">
                            <option value="">—</option>
                            <option value="LIGHT DUTY">Light Duty</option>
                            <option value="MEDIUM DUTY">Medium Duty</option>
                            <option value="HEAVY DUTY">Heavy Duty</option>
                            <option value="EXTRA HEAVY DUTY">Extra Heavy Duty</option>
                            <option value="PRIME MOVER">Prime Mover</option>
                            <option value="WHEELER">Wheeler</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Body Type</label>
                        <select name="body_type">
                            <option value="">—</option>
                            <option value="CLOSED VAN">Closed Van</option>
                            <option value="WING VAN">Wing Van</option>
                            <option value="FLATBED">Flatbed</option>
                            <option value="REFRIGERATED">Refrigerated</option>
                            <option value="CONTAINER">Container</option>
                            <option value="ALUMINUM VAN">Aluminum Van</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Footer</label>
                        <select name="footer">
                            <option value="">—</option>
                            <option value="20">20</option>
                            <option value="40">40</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Axle Config</label>
                        <input type="text" name="axle_configuration" placeholder="4×2 / 6×2 / 6×4 ...">
                    </div>

                    <div class="form-group col-2">
                        <label>Wheel Count</label>
                        <input type="number" name="wheel_count" placeholder="6, 10, 12...">
                    </div>

                    <div class="form-group col-2">
                        <label>Vehicle Weight (kg)</label>
                        <input type="number" step="0.01" name="vehicle_weight" placeholder="Tare weight">
                    </div>

                    <div class="form-section"><i data-lucide="package"></i> Cargo Capacity</div>

                    <div class="form-group col-2">
                        <label>Payload (kg)</label>
                        <input type="number" step="0.01" name="payload_capacity_kg" placeholder="Max load kg">
                    </div>

                    <div class="form-group col-2">
                        <label>Payload (cbm)</label>
                        <input type="number" step="0.01" name="payload_capacity_cbm" placeholder="Volume">
                    </div>

                    <div class="form-group col-2">
                        <label>Cargo Length (m)</label>
                        <input type="number" step="0.01" name="cargo_length_m">
                    </div>

                    <div class="form-group col-2">
                        <label>Width (m)</label>
                        <input type="number" step="0.01" name="cargo_width_m">
                    </div>

                    <div class="form-group col-2">
                        <label>Height (m)</label>
                        <input type="number" step="0.01" name="cargo_height_m">
                    </div>

                    

                    <div class="form-group col-2">
                        <label>Fuel Type</label>
                        <select name="fuel_type">
                            <option value="">—</option>
                            <option value="DIESEL">Diesel</option>
                            <option value="GASOLINE">Gasoline</option>
                            <option value="ELECTRIC">Electric</option>
                        </select>
                    </div>

                    <div class="form-section"><i data-lucide="wrench"></i> Maintenance & Location</div>

                    <div class="form-group col-2">
                        <label>Current Mileage (km)</label>
                        <input type="number" step="0.1" name="current_mileage" placeholder="Optional">
                    </div>

                    <div class="form-group col-2">
                        <label>Last Maintenance</label>
                        <input type="date" name="last_maintenance_date">
                    </div>

                    <div class="form-group col-2">
                        <label>Parking Location</label>
                        <input type="text" name="parking_place" placeholder="Yard A, Cebu Base...">
                    </div>

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
                <button type="submit" name="add_truck" class="btn btn-save">Register Truck</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Truck Modal -->
<div class="modal-overlay" id="editTruckModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Edit Truck</h2>
            <button id="closeEditModal" style="background:#f1f5f9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;">×</button>
        </div>

        <form method="POST" id="editForm">
            <input type="hidden" name="truck_id" id="edit_truck_id">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section"><i data-lucide="info"></i> Basic Information</div>

                    <div class="form-group col-2">
                        <label>Truck Code</label>
                        <input type="text" id="edit_truck_code" readonly style="background:#f8fafc; font-weight:600;">
                    </div>

                    <div class="form-group col-2">
                        <label>Plate Number <span class="required">*</span></label>
                        <input type="text" name="plate_number" id="edit_plate_number" required placeholder="ABC-1234">
                    </div>

                    <div class="form-group col-2">
                        <label>VIN / Chassis No.</label>
                        <input type="text" name="vin" id="edit_vin" placeholder="17-digit VIN">
                    </div>

                    <div class="form-group col-2">
                        <label>Brand <span class="required">*</span></label>
                        <input type="text" name="brand" id="edit_brand" required placeholder="Isuzu, Hino, Fuso...">
                    </div>

                    <div class="form-group col-2">
                        <label>Model <span class="required">*</span></label>
                        <input type="text" name="model" id="edit_model" required placeholder="Elf, Forward, Super Great...">
                    </div>

                    <div class="form-group col-2">
                        <label>Year</label>
                        <input type="number" name="year_manufactured" id="edit_year_manufactured" min="1980" max="<?php echo date('Y')+1; ?>" placeholder="<?php echo date('Y'); ?>">
                    </div>

                    <div class="form-section"><i data-lucide="truck"></i> Specifications</div>

                    <div class="form-group col-2">
                        <label>Truck Type</label>
                        <select name="truck_type" id="edit_truck_type">
                            <option value="">—</option>
                            <option value="LIGHT DUTY">Light Duty</option>
                            <option value="MEDIUM DUTY">Medium Duty</option>
                            <option value="HEAVY DUTY">Heavy Duty</option>
                            <option value="EXTRA HEAVY DUTY">Extra Heavy Duty</option>
                            <option value="PRIME MOVER">Prime Mover</option>
                            <option value="WHEELER">Wheeler</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Body Type</label>
                        <select name="body_type" id="edit_body_type">
                            <option value="">—</option>
                            <option value="CLOSED VAN">Closed Van</option>
                            <option value="WING VAN">Wing Van</option>
                            <option value="FLATBED">Flatbed</option>
                            <option value="REFRIGERATED">Refrigerated</option>
                            <option value="CONTAINER">Container</option>
                            <option value="ALUMINUM VAN">Aluminum Van</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Footer</label>
                        <select name="footer" id="edit_footer">
                            <option value="">—</option>
                            <option value="20">20</option>
                            <option value="40">40</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Axle Config</label>
                        <input type="text" name="axle_configuration" id="edit_axle_configuration" placeholder="4×2 / 6×2 / 6×4 ...">
                    </div>

                    <div class="form-group col-2">
                        <label>Wheel Count</label>
                        <input type="number" name="wheel_count" id="edit_wheel_count" placeholder="6, 10, 12...">
                    </div>

                    <div class="form-group col-2">
                        <label>Vehicle Weight (kg)</label>
                        <input type="number" step="0.01" name="vehicle_weight" id="edit_vehicle_weight" placeholder="Tare weight">
                    </div>

                    <div class="form-section"><i data-lucide="package"></i> Cargo Capacity</div>

                    <div class="form-group col-2">
                        <label>Payload (kg)</label>
                        <input type="number" step="0.01" name="payload_capacity_kg" id="edit_payload_capacity_kg" placeholder="Max load kg">
                    </div>

                    <div class="form-group col-2">
                        <label>Payload (cbm)</label>
                        <input type="number" step="0.01" name="payload_capacity_cbm" id="edit_payload_capacity_cbm" placeholder="Volume">
                    </div>

                    <div class="form-group col-2">
                        <label>Cargo Length (m)</label>
                        <input type="number" step="0.01" name="cargo_length_m" id="edit_cargo_length_m">
                    </div>

                    <div class="form-group col-2">
                        <label>Width (m)</label>
                        <input type="number" step="0.01" name="cargo_width_m" id="edit_cargo_width_m">
                    </div>

                    <div class="form-group col-2">
                        <label>Height (m)</label>
                        <input type="number" step="0.01" name="cargo_height_m" id="edit_cargo_height_m">
                    </div>

                    

                    <div class="form-group col-2">
                        <label>Fuel Type</label>
                        <select name="fuel_type" id="edit_fuel_type">
                            <option value="">—</option>
                            <option value="DIESEL">Diesel</option>
                            <option value="GASOLINE">Gasoline</option>
                            <option value="ELECTRIC">Electric</option>
                        </select>
                    </div>

                    <div class="form-section"><i data-lucide="wrench"></i> Maintenance & Location</div>

                    <div class="form-group col-2">
                        <label>Current Mileage (km)</label>
                        <input type="number" step="0.1" name="current_mileage" id="edit_current_mileage" placeholder="Optional">
                    </div>

                    <div class="form-group col-2">
                        <label>Last Maintenance</label>
                        <input type="date" name="last_maintenance_date" id="edit_last_maintenance_date">
                    </div>

                    <div class="form-group col-2">
                        <label>Parking Location</label>
                        <input type="text" name="parking_place" id="edit_parking_place" placeholder="Yard A, Cebu Base...">
                    </div>

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
                <button type="submit" name="edit_truck" class="btn btn-save">Update Truck</button>
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
    
    // Check if user can manage trucks
    const canManageTrucks = <?php echo $can_manage_trucks ? 'true' : 'false'; ?>;
    
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
    // UPPERCASE INPUT HANDLING
    // ============================================================

    // Function to apply uppercase to text inputs (excluding number and date fields)
    function applyUppercaseToInputs() {
        // Get all text inputs, textareas (excluding number, date, hidden, checkbox, radio)
        const inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]):not([type="radio"]):not([type="number"]):not([type="email"]), textarea');
        
        inputs.forEach(input => {
            // Skip number inputs
            if (input.type === 'number') {
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
        const addModal = document.getElementById('addTruckModal');
        const editModal = document.getElementById('editTruckModal');
        
        [addModal, editModal].forEach(modal => {
            if (modal) {
                // Uppercase for non-number fields
                const inputs = modal.querySelectorAll('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]):not([type="radio"]):not([type="number"]):not([type="email"]), textarea');
                inputs.forEach(input => {
                    input.addEventListener('input', function() {
                        const start = this.selectionStart;
                        const end = this.selectionEnd;
                        this.value = this.value.toUpperCase();
                        this.setSelectionRange(start, end);
                    });
                });
            }
        });
    }

    // Apply transformations to all input fields on the page
    document.addEventListener('DOMContentLoaded', function() {
        applyUppercaseToInputs();
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

    // ── Alert Message Functions ──────────────────────────────────────
    function closeAlert(button) {
        const alert = button.closest('.alert');
        if (alert) {
            alert.classList.add('alert-hiding');
            setTimeout(function() {
                alert.style.display = 'none';
            }, 400);
        }
    }

    // Auto-hide alerts after 3 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                if (alert.style.display !== 'none') {
                    alert.classList.add('alert-hiding');
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 400);
                }
            }, 3000);
        });
    });

    // ── Search Functionality ──────────────────────────────────────
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

    // ── Add Truck Modal ───────────────────────────────────────────
    const addModal = document.getElementById('addTruckModal');
    const openAddBtn = document.getElementById('openAddModal');
    const closeAddBtns = [
        document.getElementById('closeModal'),
        document.getElementById('closeModalBtn')
    ];

    function openAddModal() {
        if (!canManageTrucks) {
            alert('You are in view-only mode. You cannot add trucks.');
            return;
        }
        addModal.style.display = 'flex';
        setTimeout(() => {
            applyTransformationsToModalInputs();
        }, 100);
    }
    
    function closeAddModal() { 
        addModal.style.display = 'none'; 
    }

    openAddBtn?.addEventListener('click', openAddModal);
    closeAddBtns.forEach(btn => btn?.addEventListener('click', closeAddModal));

    addModal?.addEventListener('click', e => {
        if (e.target === addModal) closeAddModal();
    });

    // ── Edit Truck Modal ───────────────────────────────────────────
    const editModal = document.getElementById('editTruckModal');
    const closeEditBtns = [
        document.getElementById('closeEditModal'),
        document.getElementById('closeEditModalBtn')
    ];

    function openEditModal(truckData) {
        // Check permission
        if (!canManageTrucks) {
            alert('You are in view-only mode. You cannot edit trucks.');
            return;
        }
        
        // Populate all form fields with truck data
        document.getElementById('edit_truck_id').value = truckData.id;
        document.getElementById('edit_truck_code').value = truckData.truck_code || '';
        document.getElementById('edit_plate_number').value = truckData.plate_number || '';
        document.getElementById('edit_vin').value = truckData.vin || '';
        document.getElementById('edit_brand').value = truckData.brand || '';
        document.getElementById('edit_model').value = truckData.model || '';
        document.getElementById('edit_year_manufactured').value = truckData.year_manufactured || '';
        
        // Set select values - they now have value attributes matching the uppercase data
        document.getElementById('edit_truck_type').value = truckData.truck_type || '';
        document.getElementById('edit_body_type').value = truckData.body_type || '';
        document.getElementById('edit_footer').value = truckData.footer || '';
        document.getElementById('edit_fuel_type').value = truckData.fuel_type || '';
        document.getElementById('edit_status').value = truckData.status || 'Active';
        
        document.getElementById('edit_axle_configuration').value = truckData.axle_configuration || '';
        document.getElementById('edit_wheel_count').value = truckData.wheel_count || '';
        document.getElementById('edit_vehicle_weight').value = truckData.vehicle_weight || '';
        document.getElementById('edit_payload_capacity_kg').value = truckData.payload_capacity_kg || '';
        document.getElementById('edit_payload_capacity_cbm').value = truckData.payload_capacity_cbm || '';
        document.getElementById('edit_cargo_length_m').value = truckData.cargo_length_m || '';
        document.getElementById('edit_cargo_width_m').value = truckData.cargo_width_m || '';
        document.getElementById('edit_cargo_height_m').value = truckData.cargo_height_m || '';
        document.getElementById('edit_current_mileage').value = truckData.current_mileage || '';
        document.getElementById('edit_last_maintenance_date').value = truckData.last_maintenance_date || '';
        document.getElementById('edit_parking_place').value = truckData.parking_place || '';
        document.getElementById('edit_notes').value = truckData.notes || '';
        
        editModal.style.display = 'flex';
        setTimeout(() => {
            document.getElementById('edit_plate_number')?.focus();
            applyTransformationsToModalInputs();
        }, 100);
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