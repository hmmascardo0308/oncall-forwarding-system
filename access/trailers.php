<?php
// trailers.php
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

// Generate next trailer code (TRL-001, TRL-002, ...)
$next_code = 'TRL-001';
$code_query = "SELECT trailer_code 
               FROM trailer_masterlist 
               WHERE trailer_code LIKE 'TRL-%' 
               ORDER BY CAST(SUBSTRING(trailer_code, 5) AS UNSIGNED) DESC 
               LIMIT 1";

$code_result = mysqli_query($conn, $code_query);
if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
    if (preg_match('/^TRL-(\d+)$/', $row['trailer_code'], $matches)) {
        $next_number = (int)$matches[1] + 1;
        $next_code   = sprintf('TRL-%03d', $next_number);
    }
}

// Helper function to uppercase text fields
function uppercaseFields($data) {
    $uppercase_fields = ['plate_number', 'trailer_type', 'manufacturer', 'model', 
                         'color', 'vin', 'notes'];
    
    foreach ($uppercase_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = strtoupper(trim($data[$field]));
        }
    }
    
    return $data;
}

// Handle Add Trailer
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trailer'])) {
    $fields = [
        'trailer_code'        => trim($_POST['trailer_code'] ?? ''),
        'plate_number'        => trim($_POST['plate_number'] ?? ''),
        'trailer_type'        => trim($_POST['trailer_type'] ?? ''),
        'manufacturer'        => trim($_POST['manufacturer'] ?? ''),
        'model'               => trim($_POST['model'] ?? ''),
        'color'               => trim($_POST['color'] ?? ''),
        'vin'                 => trim($_POST['vin'] ?? ''),
        'length'              => trim($_POST['length'] ?? ''),
        'width'               => trim($_POST['width'] ?? ''),
        'height'              => trim($_POST['height'] ?? ''),
        'weight_kg'           => trim($_POST['weight_kg'] ?? ''),
        'max_capacity_kg'     => trim($_POST['max_capacity_kg'] ?? ''),
        'purchase_date'       => trim($_POST['purchase_date'] ?? ''),
        'status'              => trim($_POST['status'] ?? 'Active'),
        'notes'               => trim($_POST['notes'] ?? ''),
    ];

    // Apply uppercase transformation to text fields
    $fields = uppercaseFields($fields);

    // Required fields
    $required = ['trailer_code', 'plate_number', 'manufacturer', 'model'];
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
        // Check duplicate trailer_code
        $check_query = "SELECT id FROM trailer_masterlist WHERE trailer_code = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "s", $fields['trailer_code']);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);

        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $message = "Trailer code <strong>" . htmlspecialchars($fields['trailer_code']) . "</strong> already exists.";
            $message_type = 'error';
        } else {
            $created_by = $_SESSION['username'];
            $created_date = date('Y-m-d H:i:s');

            $insert_query = "
                INSERT INTO trailer_masterlist (
                    trailer_code, plate_number, trailer_type, manufacturer, model,
                    color, vin, length, width, height, weight_kg, max_capacity_kg,
                    purchase_date, status, notes, created_date, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param(
                $stmt, "sssssssdddsssssss",
                $fields['trailer_code'],
                $fields['plate_number'],
                $fields['trailer_type'],
                $fields['manufacturer'],
                $fields['model'],
                $fields['color'],
                $fields['vin'],
                $fields['length'],
                $fields['width'],
                $fields['height'],
                $fields['weight_kg'],
                $fields['max_capacity_kg'],
                $fields['purchase_date'],
                $fields['status'],
                $fields['notes'],
                $created_date,
                $created_by
            );

            if (mysqli_stmt_execute($stmt)) {
                $message = "Trailer <strong>" . htmlspecialchars($fields['plate_number']) . "</strong> registered successfully!";
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

// Handle Edit Trailer Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_trailer'])) {
    $trailer_id = intval($_POST['trailer_id']);
    
    $fields = [
        'plate_number'        => trim($_POST['plate_number'] ?? ''),
        'trailer_type'        => trim($_POST['trailer_type'] ?? ''),
        'manufacturer'        => trim($_POST['manufacturer'] ?? ''),
        'model'               => trim($_POST['model'] ?? ''),
        'color'               => trim($_POST['color'] ?? ''),
        'vin'                 => trim($_POST['vin'] ?? ''),
        'length'              => trim($_POST['length'] ?? ''),
        'width'               => trim($_POST['width'] ?? ''),
        'height'              => trim($_POST['height'] ?? ''),
        'weight_kg'           => trim($_POST['weight_kg'] ?? ''),
        'max_capacity_kg'     => trim($_POST['max_capacity_kg'] ?? ''),
        'purchase_date'       => trim($_POST['purchase_date'] ?? ''),
        'status'              => trim($_POST['status'] ?? 'Active'),
        'notes'               => trim($_POST['notes'] ?? ''),
    ];

    // Apply uppercase transformation to text fields
    $fields = uppercaseFields($fields);

    // Required fields
    $required = ['plate_number', 'manufacturer', 'model'];
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
        $updated_date = date('Y-m-d H:i:s');

        $update_query = "
            UPDATE trailer_masterlist SET
                plate_number = ?,
                trailer_type = ?,
                manufacturer = ?,
                model = ?,
                color = ?,
                vin = ?,
                length = ?,
                width = ?,
                height = ?,
                weight_kg = ?,
                max_capacity_kg = ?,
                purchase_date = ?,
                status = ?,
                notes = ?,
                updated_date = ?,
                updated_by = ?
            WHERE id = ?
        ";

        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param(
            $stmt, "ssssssdddsssssssi",
            $fields['plate_number'],
            $fields['trailer_type'],
            $fields['manufacturer'],
            $fields['model'],
            $fields['color'],
            $fields['vin'],
            $fields['length'],
            $fields['width'],
            $fields['height'],
            $fields['weight_kg'],
            $fields['max_capacity_kg'],
            $fields['purchase_date'],
            $fields['status'],
            $fields['notes'],
            $updated_date,
            $updated_by,
            $trailer_id
        );

        if (mysqli_stmt_execute($stmt)) {
            $message = "Trailer <strong>" . htmlspecialchars($fields['plate_number']) . "</strong> updated successfully!";
            $message_type = 'success';
        } else {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = 'error';
        }
        mysqli_stmt_close($stmt);
    }
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch trailers with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT * FROM trailer_masterlist 
              WHERE trailer_code LIKE '%$search_term%' 
              OR plate_number LIKE '%$search_term%'
              OR vin LIKE '%$search_term%'
              OR manufacturer LIKE '%$search_term%'
              OR model LIKE '%$search_term%'
              OR trailer_type LIKE '%$search_term%'
              OR color LIKE '%$search_term%'
              OR status LIKE '%$search_term%'
              ORDER BY trailer_code ASC";
} else {
    $query = "SELECT * FROM trailer_masterlist ORDER BY trailer_code ASC";
}

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trailer Masterlist | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/trailers.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Trailer Masterlist</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Trailer Fleet Directory</h1>
            
            <div class="header-actions">
                <!-- Search Bar -->
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input 
                            type="text" 
                            name="search" 
                            placeholder="Search by Code, Plate, Manufacturer..." 
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                
                <button id="openAddModal" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                    <i data-lucide="plus" style="width:18px;"></i> Register Trailer
                </button>
            </div>
        </div>

        <!-- Search Results Info -->
        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($result); ?> found)
                <a href="trailers.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
             <table>
                <thead>
                     <tr>
                        <th>Trailer Code</th>
                        <th>Plate Number</th>
                        <th>Manufacturer & Model</th>
                        <th>Type</th>
                        <th>Dimensions (L×W×H)</th>
                        <th>Max Capacity (kg)</th>
                        <th>Status</th>
                        <th>Action</th>
                     </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr data-trailer-id="<?php echo $row['id']; ?>">
                                <td style="font-weight:600; color:var(--accent-blue);"><?php echo htmlspecialchars($row['trailer_code'] ?? '—'); ?></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($row['plate_number'] ?? '—'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['vin'] ?: 'No VIN'); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars(($row['manufacturer'] ?? '') . ' ' . ($row['model'] ?? '')); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['color'] ?: '—'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($row['trailer_type'] ?: '—'); ?></td>
                                <td>
                                    <div style="font-size:13px;">
                                        <?php 
                                            $dimensions = [];
                                            if ($row['length']) $dimensions[] = $row['length'] . 'm';
                                            if ($row['width']) $dimensions[] = $row['width'] . 'm';
                                            if ($row['height']) $dimensions[] = $row['height'] . 'm';
                                            echo !empty($dimensions) ? implode(' × ', $dimensions) : '—';
                                        ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted);">
                                        <?php echo $row['weight_kg'] ? number_format($row['weight_kg'], 0) . ' kg' : ''; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?php echo $row['max_capacity_kg'] ? number_format($row['max_capacity_kg'], 0) . ' kg' : '—'; ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo $row['purchase_date'] ? date('M Y', strtotime($row['purchase_date'])) : ''; ?></div>
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
                                    <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <i data-lucide="edit-2" style="width:18px;"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No trailers found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="trailers.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all trailers
                                    </a>
                                <?php else: ?>
                                    No trailers found.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>
</main>

<!-- Add Trailer Modal -->
<div class="modal-overlay" id="addTrailerModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Register New Trailer</h2>
            <button id="closeModal" style="background:#f1f5f9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;">×</button>
        </div>

        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section"><i data-lucide="info"></i> Basic Information</div>

                    <div class="form-group col-2">
                        <label>Trailer Code <span class="required">*</span></label>
                        <input type="text" name="trailer_code" value="<?php echo htmlspecialchars($next_code); ?>" readonly required>
                    </div>

                    <div class="form-group col-2">
                        <label>Plate Number <span class="required">*</span></label>
                        <input type="text" name="plate_number" required placeholder="ABC-1234">
                    </div>

                    <div class="form-group col-2">
                        <label>Manufacturer <span class="required">*</span></label>
                        <input type="text" name="manufacturer" required placeholder="Isuzu, Hino, Fuso...">
                    </div>

                    <div class="form-group col-2">
                        <label>Model <span class="required">*</span></label>
                        <input type="text" name="model" required placeholder="Elf, Forward, Super Great...">
                    </div>

                    <div class="form-group col-2">
                        <label>Color</label>
                        <input type="text" name="color" placeholder="White, Blue, Red...">
                    </div>

                    <div class="form-group col-2">
                        <label>VIN / Chassis No.</label>
                        <input type="text" name="vin" placeholder="17-digit VIN">
                    </div>

                    <div class="form-section"><i data-lucide="trailer"></i> Trailer Specifications</div>

                    <div class="form-group col-2">
                        <label>Trailer Type</label>
                        <select name="trailer_type">
                            <option value="">—</option>
                            <option value="FLATBED">Flatbed</option>
                            <option value="CLOSED VAN">Closed Van</option>
                            <option value="REFRIGERATED">Refrigerated</option>
                            <option value="CONTAINER CHASSIS">Container Chassis</option>
                            <option value="LOWBOY">Lowboy</option>
                            <option value="STEP DECK">Step Deck</option>
                            <option value="DRY VAN">Dry Van</option>
                            <option value="REEFER">Reefer</option>
                            <option value="CAR CARRIER">Car Carrier</option>
                            <option value="TANKER">Tanker</option>
                            <option value="CURTAIN SIDE">Curtain Side</option>
                            <option value="DROP DECK">Drop Deck</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Length (m)</label>
                        <input type="number" step="0.01" name="length" placeholder="e.g., 14.6">
                    </div>

                    <div class="form-group col-2">
                        <label>Width (m)</label>
                        <input type="number" step="0.01" name="width" placeholder="e.g., 2.44">
                    </div>

                    <div class="form-group col-2">
                        <label>Height (m)</label>
                        <input type="number" step="0.01" name="height" placeholder="e.g., 2.59">
                    </div>

                    <div class="form-group col-2">
                        <label>Weight (kg)</label>
                        <input type="number" step="0.01" name="weight_kg" placeholder="Tare weight">
                    </div>

                    <div class="form-group col-2">
                        <label>Max Capacity (kg)</label>
                        <input type="number" step="0.01" name="max_capacity_kg" placeholder="Max load kg">
                    </div>

                    <div class="form-section"><i data-lucide="calendar"></i> Purchase & Status Information</div>

                    <div class="form-group col-2">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date">
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
                <button type="submit" name="add_trailer" class="btn btn-save">Register Trailer</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Trailer Modal -->
<div class="modal-overlay" id="editTrailerModal">
    <div class="modal">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Edit Trailer</h2>
            <button id="closeEditModal" style="background:#f1f5f9;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;">×</button>
        </div>

        <form method="POST" id="editForm">
            <input type="hidden" name="trailer_id" id="edit_trailer_id">
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section"><i data-lucide="info"></i> Basic Information</div>

                    <div class="form-group col-2">
                        <label>Trailer Code</label>
                        <input type="text" id="edit_trailer_code" readonly style="background:#f8fafc; font-weight:600;">
                    </div>

                    <div class="form-group col-2">
                        <label>Plate Number <span class="required">*</span></label>
                        <input type="text" name="plate_number" id="edit_plate_number" required placeholder="ABC-1234">
                    </div>

                    <div class="form-group col-2">
                        <label>Manufacturer <span class="required">*</span></label>
                        <input type="text" name="manufacturer" id="edit_manufacturer" required placeholder="Isuzu, Hino, Fuso...">
                    </div>

                    <div class="form-group col-2">
                        <label>Model <span class="required">*</span></label>
                        <input type="text" name="model" id="edit_model" required placeholder="Elf, Forward, Super Great...">
                    </div>

                    <div class="form-group col-2">
                        <label>Color</label>
                        <input type="text" name="color" id="edit_color" placeholder="White, Blue, Red...">
                    </div>

                    <div class="form-group col-2">
                        <label>VIN / Chassis No.</label>
                        <input type="text" name="vin" id="edit_vin" placeholder="17-digit VIN">
                    </div>

                    <div class="form-section"><i data-lucide="trailer"></i> Trailer Specifications</div>

                    <div class="form-group col-2">
                        <label>Trailer Type</label>
                        <select name="trailer_type" id="edit_trailer_type">
                            <option value="">—</option>
                            <option value="FLATBED">Flatbed</option>
                            <option value="CLOSED VAN">Closed Van</option>
                            <option value="REFRIGERATED">Refrigerated</option>
                            <option value="CONTAINER CHASSIS">Container Chassis</option>
                            <option value="LOWBOY">Lowboy</option>
                            <option value="STEP DECK">Step Deck</option>
                            <option value="DRY VAN">Dry Van</option>
                            <option value="REEFER">Reefer</option>
                            <option value="CAR CARRIER">Car Carrier</option>
                            <option value="TANKER">Tanker</option>
                            <option value="CURTAIN SIDE">Curtain Side</option>
                            <option value="DROP DECK">Drop Deck</option>
                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Length (m)</label>
                        <input type="number" step="0.01" name="length" id="edit_length" placeholder="e.g., 14.6">
                    </div>

                    <div class="form-group col-2">
                        <label>Width (m)</label>
                        <input type="number" step="0.01" name="width" id="edit_width" placeholder="e.g., 2.44">
                    </div>

                    <div class="form-group col-2">
                        <label>Height (m)</label>
                        <input type="number" step="0.01" name="height" id="edit_height" placeholder="e.g., 2.59">
                    </div>

                    <div class="form-group col-2">
                        <label>Weight (kg)</label>
                        <input type="number" step="0.01" name="weight_kg" id="edit_weight_kg" placeholder="Tare weight">
                    </div>

                    <div class="form-group col-2">
                        <label>Max Capacity (kg)</label>
                        <input type="number" step="0.01" name="max_capacity_kg" id="edit_max_capacity_kg" placeholder="Max load kg">
                    </div>

                    <div class="form-section"><i data-lucide="calendar"></i> Purchase & Status Information</div>

                    <div class="form-group col-2">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" id="edit_purchase_date">
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
                <button type="submit" name="edit_trailer" class="btn btn-save">Update Trailer</button>
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
        const addModal = document.getElementById('addTrailerModal');
        const editModal = document.getElementById('editTrailerModal');
        
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

    // Function to convert data to uppercase when populating edit form
    function toUpperCaseData(data) {
        const uppercaseFields = ['plate_number', 'trailer_type', 'manufacturer', 'model', 'color', 'vin', 'notes'];
        const result = {...data};
        uppercaseFields.forEach(field => {
            if (result[field] && typeof result[field] === 'string') {
                result[field] = result[field].toUpperCase();
            }
        });
        return result;
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

    // ── Add Trailer Modal ───────────────────────────────────────────
    const addModal = document.getElementById('addTrailerModal');
    const openAddBtn = document.getElementById('openAddModal');
    const closeAddBtns = [
        document.getElementById('closeModal'),
        document.getElementById('closeModalBtn')
    ];

    function openAddModal() { 
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

    // ── Edit Trailer Modal ───────────────────────────────────────────
    const editModal = document.getElementById('editTrailerModal');
    const closeEditBtns = [
        document.getElementById('closeEditModal'),
        document.getElementById('closeEditModalBtn')
    ];

    function openEditModal(trailerData) {
        // Convert data to uppercase before populating
        const data = toUpperCaseData(trailerData);
        
        // Populate all form fields with trailer data (now uppercase)
        document.getElementById('edit_trailer_id').value = data.id;
        document.getElementById('edit_trailer_code').value = data.trailer_code || '';
        document.getElementById('edit_plate_number').value = data.plate_number || '';
        document.getElementById('edit_manufacturer').value = data.manufacturer || '';
        document.getElementById('edit_model').value = data.model || '';
        document.getElementById('edit_color').value = data.color || '';
        document.getElementById('edit_vin').value = data.vin || '';
        
        // Set select values - they now have value attributes matching uppercase data
        document.getElementById('edit_trailer_type').value = data.trailer_type || '';
        document.getElementById('edit_status').value = data.status || 'Active';
        
        document.getElementById('edit_length').value = data.length || '';
        document.getElementById('edit_width').value = data.width || '';
        document.getElementById('edit_height').value = data.height || '';
        document.getElementById('edit_weight_kg').value = data.weight_kg || '';
        document.getElementById('edit_max_capacity_kg').value = data.max_capacity_kg || '';
        document.getElementById('edit_purchase_date').value = data.purchase_date || '';
        document.getElementById('edit_notes').value = data.notes || '';
        
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