<?php
// customer_pricing.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

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

$user_roles = array_map('trim', explode(',', $user_type));

$is_admin             = in_array('admin', $user_roles);
$is_customer_pricer   = in_array('customer_pricer', $user_roles);
$is_sales_order_maker = in_array('sales_order_maker', $user_roles);

$can_access_pricing = $is_admin || in_array('user', $user_roles) || $is_customer_pricer || $is_sales_order_maker;

if (!$can_access_pricing) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Customer Pricing page."
    ];
    header("Location: home.php");
    exit;
}

$role_display_name = getRoleDisplayName($user_roles);

// Handle Delete Request
if (isset($_GET['delete_id']) && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {
    $delete_id = intval($_GET['delete_id']);
    $stmt = mysqli_prepare($conn, "DELETE FROM customer_pricing WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $delete_id);

    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Pricing record deleted successfully!"];
    } else {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => "Failed to delete pricing record."];
    }
    mysqli_stmt_close($stmt);
    header("Location: customer_pricing.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pricing']) && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {

    $pricing_id = isset($_POST['pricing_id']) ? intval($_POST['pricing_id']) : 0;

    $data = [
        'customer_code'   => trim($_POST['customer_code'] ?? ''),
        'full_name'       => trim($_POST['full_name'] ?? ''),
        'truck_type'      => !empty($_POST['truck_type']) ? trim($_POST['truck_type']) : null,
        'footer'          => (isset($_POST['footer']) && trim($_POST['footer']) !== '') ? trim($_POST['footer']) : null,
        'wheel_count'     => !empty($_POST['wheel_count']) ? intval($_POST['wheel_count']) : null,
        'rate_per_km'     => !empty($_POST['rate_per_km']) ? floatval($_POST['rate_per_km']) : null,
        'rate_per_hour'   => !empty($_POST['rate_per_hour']) ? floatval($_POST['rate_per_hour']) : null,
        'rate_per_trip'   => !empty($_POST['rate_per_trip']) ? floatval($_POST['rate_per_trip']) : null,
        'minimum_charge'  => !empty($_POST['minimum_charge']) ? floatval($_POST['minimum_charge']) : null,
        'rate_per_kg'     => !empty($_POST['rate_per_kg']) ? floatval($_POST['rate_per_kg']) : null,
        'rate_per_cbm'    => !empty($_POST['rate_per_cbm']) ? floatval($_POST['rate_per_cbm']) : null,
        'zone_from'       => !empty($_POST['zone_from']) ? trim($_POST['zone_from']) : null,
        'zone_to'         => !empty($_POST['zone_to']) ? trim($_POST['zone_to']) : null,
        'min_distance_km' => !empty($_POST['min_distance_km']) ? floatval($_POST['min_distance_km']) : null,
        'max_distance_km' => !empty($_POST['max_distance_km']) ? floatval($_POST['max_distance_km']) : null,
        'min_weight_kg'   => !empty($_POST['min_weight_kg']) ? floatval($_POST['min_weight_kg']) : null,
        'max_weight_kg'   => !empty($_POST['max_weight_kg']) ? floatval($_POST['max_weight_kg']) : null,
        'effective_from'  => !empty($_POST['effective_from']) ? $_POST['effective_from'] : null,
        'effective_until' => !empty($_POST['effective_until']) ? $_POST['effective_until'] : null,
        'status'          => trim($_POST['status'] ?? 'active'),
        'notes'           => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
    ];

    $errors = [];
    if (empty($data['customer_code'])) $errors[] = "Customer Code is required.";
    if (empty($data['full_name']))     $errors[] = "Customer Name is required.";

    if (!empty($errors)) {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => implode("<br>", $errors)];
        header("Location: customer_pricing.php");
        exit;
    }

    if ($pricing_id > 0 && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {
        $sql = "UPDATE customer_pricing SET 
                customer_code = ?, full_name = ?, truck_type = ?, footer = ?, wheel_count = ?,
                rate_per_km = ?, rate_per_hour = ?, rate_per_trip = ?, minimum_charge = ?,
                rate_per_kg = ?, rate_per_cbm = ?,
                zone_from = ?, zone_to = ?, min_distance_km = ?, max_distance_km = ?,
                min_weight_kg = ?, max_weight_kg = ?, effective_from = ?, effective_until = ?,
                status = ?, notes = ?, updated_at = NOW(), updated_by = ?
                WHERE id = ?";

        $stmt = mysqli_prepare($conn, $sql);
        // 23 placeholders: s s s s i | d d d d d d | s s | d d d d | s s s s s | i
        mysqli_stmt_bind_param($stmt, "ssssiddddddssddddsssssi",
            $data['customer_code'], $data['full_name'], $data['truck_type'], $data['footer'], $data['wheel_count'],
            $data['rate_per_km'], $data['rate_per_hour'], $data['rate_per_trip'], $data['minimum_charge'],
            $data['rate_per_kg'], $data['rate_per_cbm'],
            $data['zone_from'], $data['zone_to'], $data['min_distance_km'], $data['max_distance_km'],
            $data['min_weight_kg'], $data['max_weight_kg'], $data['effective_from'], $data['effective_until'],
            $data['status'], $data['notes'], $username, $pricing_id
        );
        $action = "updated";
    } else {
        $sql = "INSERT INTO customer_pricing (
            customer_code, full_name, truck_type, footer, wheel_count,
            rate_per_km, rate_per_hour, rate_per_trip, minimum_charge,
            rate_per_kg, rate_per_cbm,
            zone_from, zone_to, min_distance_km, max_distance_km,
            min_weight_kg, max_weight_kg, effective_from, effective_until,
            status, notes, created_at, created_by, updated_at, updated_by
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, NOW(), ?, NOW(), ?
        )";

        $stmt = mysqli_prepare($conn, $sql);
        // 23 placeholders: s s s s i | d d d d d d | s s | d d d d | s s s s s s
        mysqli_stmt_bind_param($stmt, "ssssiddddddssddddssssss",
            $data['customer_code'], $data['full_name'], $data['truck_type'], $data['footer'], $data['wheel_count'],
            $data['rate_per_km'], $data['rate_per_hour'], $data['rate_per_trip'], $data['minimum_charge'],
            $data['rate_per_kg'], $data['rate_per_cbm'],
            $data['zone_from'], $data['zone_to'], $data['min_distance_km'], $data['max_distance_km'],
            $data['min_weight_kg'], $data['max_weight_kg'], $data['effective_from'], $data['effective_until'],
            $data['status'], $data['notes'], $username, $username
        );
        $action = "added";
    }

    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Pricing record $action successfully!"];
    } else {
        $_SESSION['flash_message'] = ['type' => 'error', 'text' => "Failed to save pricing record: " . mysqli_error($conn)];
    }
    mysqli_stmt_close($stmt);
    header("Location: customer_pricing.php");
    exit;
}

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $pricing_query = mysqli_query($conn, "
        SELECT * FROM customer_pricing 
        WHERE customer_code LIKE '%$search_term%' 
        OR full_name LIKE '%$search_term%'
        OR truck_type LIKE '%$search_term%'
        OR footer LIKE '%$search_term%'
        OR wheel_count LIKE '%$search_term%'
        OR zone_from LIKE '%$search_term%'
        OR zone_to LIKE '%$search_term%'
        ORDER BY created_at ASC
    ");
} else {
    $pricing_query = mysqli_query($conn, "SELECT * FROM customer_pricing ORDER BY created_at ASC");
}

$edit_record = null;
if (isset($_GET['edit_id']) && ($is_admin || $is_customer_pricer || $is_sales_order_maker)) {
    $edit_id = intval($_GET['edit_id']);
    $edit_query = mysqli_query($conn, "SELECT * FROM customer_pricing WHERE id = $edit_id");
    $edit_record = mysqli_fetch_assoc($edit_query);
}

$customers_query = mysqli_query($conn, "SELECT customer_code, full_name FROM customer_masterlist ORDER BY full_name");
$customers_list = [];
while ($c = mysqli_fetch_assoc($customers_query)) {
    $customers_list[] = $c;
}

// Fixed truck type options
$truck_types = ['LIGHT DUTY', 'MEDIUM DUTY', 'HEAVY DUTY', 'EXTRA HEAVY DUTY', 'PRIME MOVER', 'WHEELER'];
// Fixed footer options
$footer_options = ['20 Footer', '40 Footer', '2 x 20 Footer'];
// Fixed wheel count options
$wheel_count_options = [6, 10, 12];

$customers_json = json_encode($customers_list);
$truck_types_json = json_encode($truck_types);
$edit_record_json = $edit_record ? json_encode($edit_record) : 'null';

$can_modify = $is_admin || $is_customer_pricer || $is_sales_order_maker;
$can_delete = $is_admin || $is_customer_pricer || $is_sales_order_maker;
$can_edit   = $is_admin || $is_customer_pricer || $is_sales_order_maker;
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

<div id="accessModal" class="modal-overlay">
    <div class="access-modal">
        <i data-lucide="shield-off"></i>
        <h3>Access Denied</h3>
        <p>You don't have permission to access this page.</p>
        <button class="modal-btn" onclick="closeModal()">OK</button>
    </div>
</div>

<script>
    const CUSTOMERS_DATA = <?= $customers_json ?>;
    const TRUCK_TYPES    = <?= $truck_types_json ?>;
    const EDIT_RECORD    = <?= $edit_record_json ?>;

    const customerByCode = {};
    const customerByName = {};
    CUSTOMERS_DATA.forEach(c => {
        customerByCode[c.customer_code] = c.full_name;
        customerByName[c.full_name]     = c.customer_code;
    });

    function wireCustomerSelects(codeId, nameId) {
        const codeEl = document.getElementById(codeId);
        const nameEl = document.getElementById(nameId);
        if (!codeEl || !nameEl) return;
        codeEl.addEventListener('change', () => { const m = customerByCode[codeEl.value]; if (m) nameEl.value = m; });
        nameEl.addEventListener('change', () => { const m = customerByName[nameEl.value]; if (m) codeEl.value = m; });
    }
</script>

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
                <form method="GET" action="" style="flex: 1; min-width: 300px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input type="text" name="search" placeholder="Search by Customer, Truck, Zone..." value="<?php echo htmlspecialchars($search_term); ?>" id="searchInput" autocomplete="off">
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

        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>" 
                (<?php echo mysqli_num_rows($pricing_query); ?> found)
                <a href="customer_pricing.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">Clear search</a>
            </div>
        <?php endif; ?>

        <div class="table-container">
    <div style="overflow-x: auto;">
         <table>
            <thead>
                 <tr>
                    <th>ID</th>
                    <th>Customer</th>
                    <th>Truck Type</th>
                    <th>Footer</th>
                    <th>Wheel Count</th>
                    <th>Rate/Trip</th>
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
                        <td><?= htmlspecialchars($row['truck_type'] ?? '-') ?></td>
                        <td><?= $row['footer'] !== null && $row['footer'] !== '' ? htmlspecialchars($row['footer']) : '-' ?></td>
                        <td><?= $row['wheel_count'] !== null && $row['wheel_count'] !== '' ? htmlspecialchars($row['wheel_count']) : '-' ?></td>
                        <td><?= $row['rate_per_trip']  ? '₱' . number_format($row['rate_per_trip'], 2)  : '-' ?></td>
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
                                <button onclick='openEditModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)' class="btn-icon btn-edit" title="Edit Pricing">
                                    <i data-lucide="edit-2" style="width:18px;"></i>
                                </button>
                                <a href="?delete_id=<?= $row['id'] ?>" class="btn-icon btn-delete" onclick="return confirm('Are you sure you want to delete this pricing record?')" title="Delete Pricing">
                                    <i data-lucide="trash-2" style="width:18px;"></i>
                                </a>
                            </div>
                         </td>
                        <?php endif; ?>
                     </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                     <tr>
                        <td colspan="<?php echo $can_delete ? '10' : '9'; ?>" style="text-align:center; padding:40px; color:#94a3b8;">
                            <?php if (!empty($search_term)): ?>
                                No pricing records found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                <br>
                                <a href="customer_pricing.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">View all records</a>
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

<!-- Add Pricing Modal -->
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
                            <label>Footer</label>
                            <select name="footer" id="add_footer">
                                <option value="">-- Select Footer --</option>
                                <?php foreach ($footer_options as $f): ?>
                                <option value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Wheel Count</label>
                            <select name="wheel_count" id="add_wheel_count">
                                <option value="">-- Select Wheel Count --</option>
                                <?php foreach ($wheel_count_options as $w): ?>
                                <option value="<?= $w ?>"><?= $w ?> Wheels</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">
                        <i data-lucide="philippine-peso" style="width:16px;"></i> Rate Information
                    </div>

                    <div class="col-2"><div class="form-group"><label>Rate per Trip (₱)</label><input type="number" step="0.0001" name="rate_per_trip"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per KM (₱) (optional)</label><input type="number" step="0.0001" name="rate_per_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per Hour (₱) (optional)</label><input type="number" step="0.0001" name="rate_per_hour"></div></div>

                    <div class="col-2"><div class="form-group"><label>Minimum Charge (₱) (optional)</label><input type="number" step="0.0001" name="minimum_charge"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per KG (₱) (optional)</label><input type="number" step="0.0001" name="rate_per_kg"></div></div>
                    <div class="col-2"><div class="form-group"><label>Rate per CBM (₱) (optional)</label><input type="number" step="0.0001" name="rate_per_cbm"></div></div>

                    <div class="form-section-title">
                        <i data-lucide="map-pin" style="width:16px;"></i> Zone & Distance Parameters
                    </div>

                    <div class="col-3">
                        <div class="form-group">
                            <label>Zone From</label>
                            <select name="zone_from">
                                <option value="">-- Select --</option>
                                <option value="SOUTH HARBOR TALISAY">SOUTH HARBOR TALISAY</option>
                                <option value="CEBU INTERNATIONAL PORT">CEBU INTERNATIONAL PORT</option>
                                <option value="CEBU">CEBU</option>
                                <option value="TALISAY CEBU">TALISAY CEBU</option>


                            </select>
                        </div>
                    </div>
                    <div class="col-3"><div class="form-group"><label>Zone To</label><input type="text" name="zone_to" placeholder="e.g., Mandaue City"></div></div>
                    <div class="col-2"><div class="form-group"><label>Min Distance (KM) (optional)</label><input type="number" step="0.0001" name="min_distance_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Max Distance (KM) (optional)</label><input type="number" step="0.0001" name="max_distance_km"></div></div>
                    <div class="col-2"><div class="form-group"><label>Min Weight (KG) (optional)</label><input type="number" step="0.0001" name="min_weight_kg"></div></div>
                    <div class="col-2"><div class="form-group"><label>Max Weight (KG) (optional)</label><input type="number" step="0.0001" name="max_weight_kg"></div></div>

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

<!-- Edit Pricing Modal -->
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
                            <label>Footer</label>
                            <select name="footer" id="edit_footer">
                                <option value="">-- Select Footer --</option>
                                <?php foreach ($footer_options as $f): ?>
                                <option value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-2">
                        <div class="form-group">
                            <label>Wheel Count</label>
                            <select name="wheel_count" id="edit_wheel_count">
                                <option value="">-- Select Wheel Count --</option>
                                <?php foreach ($wheel_count_options as $w): ?>
                                <option value="<?= $w ?>"><?= $w ?> Wheels</option>
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
                                <option value="SOUTH HARBOR TALISAY">SOUTH HARBOR TALISAY</option>
                                <option value="CEBU INTERNATIONAL PORT">CEBU INTERNATIONAL PORT</option>
                                <option value="CEBU">CEBU</option>
                                <option value="TALISAY CEBU">TALISAY CEBU</option>

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

    const userRoles = <?php echo json_encode($user_roles); ?>;
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    const modal = document.getElementById('accessModal');

    function checkAccess(page) {
        if (userRoles.includes('admin')) return true;
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) return true;
        }
        modal.style.display = 'flex';
        return false;
    }

    function closeModal() { modal.style.display = 'none'; }

    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });

    // Search
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
        if (this.value.trim() !== '') clearBtn?.classList.add('visible');
        else clearBtn?.classList.remove('visible');
    });

    searchInput?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (this.value.trim() !== '') searchForm?.submit();
            else window.location.href = window.location.pathname;
        }
    });

    setTimeout(() => {
        const flash = document.getElementById('flash-message');
        if (flash) setTimeout(() => flash.remove(), 3000);
    }, 100);

    // Modals
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

    function openEditModal(row) {
        if (!editModalElem) return;

        document.getElementById('edit_pricing_id').value = row.id;

        setSelect('edit_customer_code', row.customer_code);
        setSelect('edit_full_name',     row.full_name);
        setSelect('edit_truck_type',    row.truck_type ?? '');
        setSelect('edit_footer', normalizeFooterValue(row.footer ?? ''));
        setSelect('edit_wheel_count',   row.wheel_count ?? '');

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

    // Convert old numeric footer values to the new text labels for editing.
    function normalizeFooterValue(value) {
        const legacyValues = { '20': '20 Footer', '40': '40 Footer', '2': '2 x 20 Footer' };
        const normalized = String(value ?? '').trim();
        return legacyValues[normalized] ?? normalized;
    }

    function setSelect(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        const normalizedValue = value == null ? '' : String(value);
        el.value = normalizedValue;
        // Preserve an existing database value even if it is not one of the current options.
        if (normalizedValue !== '' && el.value !== normalizedValue) {
            const option = document.createElement('option');
            option.value = normalizedValue;
            option.textContent = normalizedValue;
            el.appendChild(option);
            el.value = normalizedValue;
        }
    }

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (addModal?.classList.contains('open'))  closeAddModal();
        if (editModalElem?.classList.contains('open')) closeEditModal();
        if (modal?.style.display === 'flex') closeModal();
    });

    if (addModal) addModal.addEventListener('click', e => { if (e.target.id === 'addModal') closeAddModal(); });
    if (editModalElem) editModalElem.addEventListener('click', e => { if (e.target.id === 'editModal') closeEditModal(); });

    // Auto-link customer dropdowns
    wireCustomerSelects('add_customer_code', 'add_full_name');
    wireCustomerSelects('edit_customer_code', 'edit_full_name');

    setTimeout(() => lucide.createIcons(), 100);
</script>
</body>
</html>