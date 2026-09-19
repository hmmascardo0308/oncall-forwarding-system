<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

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
$is_admin   = in_array('admin', $user_roles);

$can_access_items = $is_admin || in_array('user', $user_roles) || in_array('purchase_order_maker', $user_roles) || in_array('item_register', $user_roles);

if (!$can_access_items) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Items page."
    ];
    header("Location: home.php");
    exit;
}

$can_manage_items = $is_admin || in_array('user', $user_roles) || in_array('item_register', $user_roles);

$role_display_name = getRoleDisplayName($user_roles);
$current_page = basename($_SERVER['PHP_SELF']);

// ============================================================
// IMAGE UPLOAD CONFIGURATION
// ============================================================
define('ITEM_IMAGE_DIR', __DIR__ . '/../uploads/items_images/');
define('ITEM_IMAGE_URL', '../uploads/items_images/');
define('MAX_ITEM_IMAGES', 3);

$allowed_image_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// Helper: Save uploaded images for an item
function saveItemImages($conn, $item_code, $item_name, $files) {
    global $allowed_image_ext;

    $saved  = [];
    $errors = [];

    if (empty($files['name'][0])) {
        return ['saved' => $saved, 'errors' => $errors];
    }

    if (!is_dir(ITEM_IMAGE_DIR)) {
        if (!mkdir(ITEM_IMAGE_DIR, 0755, true)) {
            return ['saved' => [], 'errors' => ['Failed to create upload directory.']];
        }
    }

    $count = count($files['name']);
    if ($count > MAX_ITEM_IMAGES) {
        return ['saved' => [], 'errors' => ['Maximum of ' . MAX_ITEM_IMAGES . ' images allowed per upload.']];
    }

    // Get current session username for created_by
    $created_by = $_SESSION['username'] ?? 'Unknown';

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            if ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors[] = "Error uploading file: " . htmlspecialchars($files['name'][$i]);
            }
            continue;
        }

        $tmp_name  = $files['tmp_name'][$i];
        $orig_name = $files['name'][$i];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_image_ext)) {
            $errors[] = "Invalid file type: " . htmlspecialchars($orig_name);
            continue;
        }

        $check = @getimagesize($tmp_name);
        if ($check === false) {
            $errors[] = "File is not a valid image: " . htmlspecialchars($orig_name);
            continue;
        }

        $safe_code = preg_replace('/[^A-Za-z0-9_\-]/', '_', $item_code);
        $filename  = $safe_code . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target    = ITEM_IMAGE_DIR . $filename;

        if (move_uploaded_file($tmp_name, $target)) {
            // Asia/Manila datetime
            $created_at = date('Y-m-d H:i:s');

            $stmt = mysqli_prepare($conn, "INSERT INTO item_images (item_code, item_name, item_image, created_at, created_by) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $item_code, $item_name, $filename, $created_at, $created_by);
            if (mysqli_stmt_execute($stmt)) {
                $saved[] = $filename;
            } else {
                @unlink($target);
                $errors[] = "DB insert failed for " . htmlspecialchars($orig_name);
            }
            mysqli_stmt_close($stmt);
        } else {
            $errors[] = "Failed to save " . htmlspecialchars($orig_name);
        }
    }

    return ['saved' => $saved, 'errors' => $errors];
}

// Helper: Fetch existing images for an item
function getItemImages($conn, $item_code) {
    $images = [];
    $stmt = mysqli_prepare($conn, "SELECT id, item_image, created_at, created_by FROM item_images WHERE item_code = ? ORDER BY id ASC");
    mysqli_stmt_bind_param($stmt, "s", $item_code);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $images[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $images;
}

// Helper: Delete a single image record and file
function deleteItemImage($conn, $image_id) {
    $stmt = mysqli_prepare($conn, "SELECT item_image FROM item_images WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $image_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        $file = ITEM_IMAGE_DIR . $row['item_image'];
        if (file_exists($file)) @unlink($file);
    }
    mysqli_stmt_close($stmt);

    $del = mysqli_prepare($conn, "DELETE FROM item_images WHERE id = ?");
    mysqli_stmt_bind_param($del, "i", $image_id);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);
}

// Helper function to uppercase text fields
function uppercaseFields($data) {
    $uppercase_fields = ['item_name', 'category', 'subcategory', 'description', 'brand',
                         'part_number', 'compatible_models', 'unit_of_measure', 'location_bin', 'notes'];

    foreach ($uppercase_fields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = strtoupper(trim($data[$field]));
        }
    }

    return $data;
}

// Get next item code (ITM-001, ITM-002, ...)
$next_code = 'ITM-001';

$code_query = "SELECT item_code 
               FROM item_masterlist 
               WHERE item_code LIKE 'ITM-%' 
               ORDER BY CAST(SUBSTRING(item_code, 5) AS UNSIGNED) DESC 
               LIMIT 1";

$code_result = mysqli_query($conn, $code_query);

if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
    if (preg_match('/^ITM-(\d+)$/', $row['item_code'], $matches)) {
        $last_number = (int)$matches[1];
        $next_number = $last_number + 1;
        $next_code   = sprintf('ITM-%03d', $next_number);
    }
}

// Fetch distinct item names for autocomplete
$item_names_query = "SELECT DISTINCT item_name FROM item_masterlist ORDER BY item_name ASC";
$item_names_result = mysqli_query($conn, $item_names_query);
$item_names = [];
while ($row = mysqli_fetch_assoc($item_names_result)) {
    $item_names[] = $row['item_name'];
}

// Handle Add Item Submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $fields = [
        'item_code'          => trim($_POST['item_code'] ?? ''),
        'item_name'          => trim($_POST['item_name'] ?? ''),
        'category'           => trim($_POST['category'] ?? ''),
        'subcategory'        => trim($_POST['subcategory'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'brand'              => trim($_POST['brand'] ?? ''),
        'part_number'        => trim($_POST['part_number'] ?? ''),
        'compatible_models'  => trim($_POST['compatible_models'] ?? ''),
        'unit_of_measure'    => trim($_POST['unit_of_measure'] ?? ''),
        'purchase_price'     => trim($_POST['purchase_price'] ?? '0'),
        'selling_price'      => trim($_POST['selling_price'] ?? '0'),
        'reorder_point'      => trim($_POST['reorder_point'] ?? '0'),
        'reorder_quantity'   => trim($_POST['reorder_quantity'] ?? '0'),
        'current_stock'      => trim($_POST['current_stock'] ?? '0'),
        'min_stock'          => trim($_POST['min_stock'] ?? '0'),
        'location_bin'       => trim($_POST['location_bin'] ?? ''),
        'status'             => trim($_POST['status'] ?? '1'),
        'notes'              => trim($_POST['notes'] ?? ''),
    ];

    $fields = uppercaseFields($fields);

    $required = ['item_code', 'item_name', 'category', 'unit_of_measure'];
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
        $duplicate_query = "SELECT id FROM item_masterlist WHERE item_code = ? LIMIT 1";
        $dup_stmt = mysqli_prepare($conn, $duplicate_query);
        mysqli_stmt_bind_param($dup_stmt, "s", $fields['item_code']);
        mysqli_stmt_execute($dup_stmt);
        mysqli_stmt_store_result($dup_stmt);

        if (mysqli_stmt_num_rows($dup_stmt) > 0) {
            $message = "Item Code <strong>" . htmlspecialchars($fields['item_code']) . "</strong> already exists.";
            $message_type = 'error';
            mysqli_stmt_close($dup_stmt);
        } else {
            mysqli_stmt_close($dup_stmt);

            $created_by   = $_SESSION['username'];
            $created_at   = date('Y-m-d H:i:s');
            $last_updated_stock = date('Y-m-d');

            $insert_query = "
                INSERT INTO item_masterlist (
                    item_code, item_name, category, subcategory, description,
                    brand, part_number, compatible_models, unit_of_measure,
                    purchase_price, selling_price, reorder_point,
                    reorder_quantity, current_stock, min_stock, location_bin,
                    status, last_updated_stock, notes, created_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = mysqli_prepare($conn, $insert_query);
            if (!$stmt) {
                $message = "Database prepare error: " . mysqli_error($conn);
                $message_type = 'error';
            } else {
                $purchase_price   = (float)$fields['purchase_price'];
                $selling_price    = (float)$fields['selling_price'];
                $reorder_point    = (int)$fields['reorder_point'];
                $reorder_quantity = (int)$fields['reorder_quantity'];
                $current_stock    = (int)$fields['current_stock'];
                $min_stock        = (int)$fields['min_stock'];
                $status           = (int)$fields['status'];

                mysqli_stmt_bind_param(
                    $stmt,
                    "sssssssssddiiiisissss",
                    $fields['item_code'],
                    $fields['item_name'],
                    $fields['category'],
                    $fields['subcategory'],
                    $fields['description'],
                    $fields['brand'],
                    $fields['part_number'],
                    $fields['compatible_models'],
                    $fields['unit_of_measure'],
                    $purchase_price,
                    $selling_price,
                    $reorder_point,
                    $reorder_quantity,
                    $current_stock,
                    $min_stock,
                    $fields['location_bin'],
                    $status,
                    $last_updated_stock,
                    $fields['notes'],
                    $created_at,
                    $created_by
                );

                if (mysqli_stmt_execute($stmt)) {
                    $message = "Item <strong>" . htmlspecialchars($fields['item_name']) . "</strong> added successfully!";
                    $message_type = 'success';

                    // Handle image uploads
                    if (!empty($_FILES['item_images']['name'][0])) {
                        $imgResult = saveItemImages($conn, $fields['item_code'], $fields['item_name'], $_FILES['item_images']);
                        if (!empty($imgResult['errors'])) {
                            $message .= "<br>Image warnings: " . implode("<br>", $imgResult['errors']);
                            $message_type = 'warning';
                        }
                        if (!empty($imgResult['saved'])) {
                            $message .= "<br>" . count($imgResult['saved']) . " image(s) uploaded.";
                        }
                    }

                    // Update next code
                    $code_result = mysqli_query($conn, $code_query);
                    if ($code_result && $row = mysqli_fetch_assoc($code_result)) {
                        if (preg_match('/^ITM-(\d+)$/', $row['item_code'], $matches)) {
                            $last_number = (int)$matches[1];
                            $next_number = $last_number + 1;
                            $next_code   = sprintf('ITM-%03d', $next_number);
                        }
                    }
                } else {
                    $message = "Database error: " . mysqli_error($conn);
                    $message_type = 'error';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Handle Edit Item Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $item_id = intval($_POST['item_id']);

    $fields = [
        'item_name'          => trim($_POST['item_name'] ?? ''),
        'category'           => trim($_POST['category'] ?? ''),
        'subcategory'        => trim($_POST['subcategory'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'brand'              => trim($_POST['brand'] ?? ''),
        'part_number'        => trim($_POST['part_number'] ?? ''),
        'compatible_models'  => trim($_POST['compatible_models'] ?? ''),
        'unit_of_measure'    => trim($_POST['unit_of_measure'] ?? ''),
        'purchase_price'     => trim($_POST['purchase_price'] ?? '0'),
        'selling_price'      => trim($_POST['selling_price'] ?? '0'),
        'reorder_point'      => trim($_POST['reorder_point'] ?? '0'),
        'reorder_quantity'   => trim($_POST['reorder_quantity'] ?? '0'),
        'current_stock'      => trim($_POST['current_stock'] ?? '0'),
        'min_stock'          => trim($_POST['min_stock'] ?? '0'),
        'location_bin'       => trim($_POST['location_bin'] ?? ''),
        'status'             => trim($_POST['status'] ?? '1'),
        'notes'              => trim($_POST['notes'] ?? ''),
    ];

    $fields = uppercaseFields($fields);

    $required = ['item_name', 'category', 'unit_of_measure'];
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
            UPDATE item_masterlist SET
                item_name = ?, category = ?, subcategory = ?, description = ?,
                brand = ?, part_number = ?, compatible_models = ?, unit_of_measure = ?,
                purchase_price = ?, selling_price = ?, reorder_point = ?,
                reorder_quantity = ?, current_stock = ?, min_stock = ?, location_bin = ?,
                status = ?, notes = ?, last_updated_stock = ?
            WHERE id = ?
        ";

        $last_updated_stock = date('Y-m-d');
        $stmt = mysqli_prepare($conn, $update_query);
        $purchase_price   = (float)$fields['purchase_price'];
        $selling_price    = (float)$fields['selling_price'];
        $reorder_point    = (int)$fields['reorder_point'];
        $reorder_quantity = (int)$fields['reorder_quantity'];
        $current_stock    = (int)$fields['current_stock'];
        $min_stock        = (int)$fields['min_stock'];
        $status           = (int)$fields['status'];
        $item_id          = (int)$item_id;

        mysqli_stmt_bind_param(
            $stmt,
            "sssssssssddiiiisssi",
            $fields['item_name'],
            $fields['category'],
            $fields['subcategory'],
            $fields['description'],
            $fields['brand'],
            $fields['part_number'],
            $fields['compatible_models'],
            $fields['unit_of_measure'],
            $purchase_price,
            $selling_price,
            $reorder_point,
            $reorder_quantity,
            $current_stock,
            $min_stock,
            $fields['location_bin'],
            $status,
            $fields['notes'],
            $last_updated_stock,
            $item_id
        );

        if (mysqli_stmt_execute($stmt)) {
            // Get current item_code for image operations
            $cur_stmt = mysqli_prepare($conn, "SELECT item_code FROM item_masterlist WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($cur_stmt, "i", $item_id);
            mysqli_stmt_execute($cur_stmt);
            $cur_res = mysqli_stmt_get_result($cur_stmt);
            $cur_row = mysqli_fetch_assoc($cur_res);
            $current_item_code = $cur_row['item_code'] ?? '';
            mysqli_stmt_close($cur_stmt);

            // Delete marked images
            if (!empty($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                foreach ($_POST['delete_images'] as $img_id) {
                    deleteItemImage($conn, (int)$img_id);
                }
            }

            $message = "Item <strong>" . htmlspecialchars($fields['item_name']) . "</strong> updated successfully!";
            $message_type = 'success';

            // Handle new image uploads
            if (!empty($_FILES['item_images']['name'][0])) {
                $existing  = getItemImages($conn, $current_item_code);
                $remaining = MAX_ITEM_IMAGES - count($existing);
                if ($remaining <= 0) {
                    $message .= "<br>Image limit reached (" . MAX_ITEM_IMAGES . "). No new images uploaded.";
                    $message_type = 'warning';
                } else {
                    $files = $_FILES['item_images'];
                    $limited = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
                    for ($i = 0; $i < min(count($files['name']), $remaining); $i++) {
                        foreach ($files as $k => $v) $limited[$k][] = $v[$i];
                    }
                    $imgResult = saveItemImages($conn, $current_item_code, $fields['item_name'], $limited);
                    if (!empty($imgResult['errors'])) {
                        $message .= "<br>Image warnings: " . implode("<br>", $imgResult['errors']);
                        $message_type = 'warning';
                    }
                    if (!empty($imgResult['saved'])) {
                        $message .= "<br>" . count($imgResult['saved']) . " image(s) uploaded.";
                    }
                }
            }
        } else {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = 'error';
        }
        mysqli_stmt_close($stmt);
    }
}

// Get search term from GET
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items with search filter
if (!empty($search_term)) {
    $search_term = mysqli_real_escape_string($conn, $search_term);
    $query = "SELECT * FROM item_masterlist 
              WHERE item_code LIKE '%$search_term%' 
              OR item_name LIKE '%$search_term%'
              OR category LIKE '%$search_term%'
              OR subcategory LIKE '%$search_term%'
              OR brand LIKE '%$search_term%'
              OR part_number LIKE '%$search_term%'
              OR description LIKE '%$search_term%'
              ORDER BY item_name ASC";
} else {
    $query = "SELECT * FROM item_masterlist ORDER BY item_code ASC";
}

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items Masterlist | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/items.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Items Masterlist</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <span class="alert-content"><?php echo $message; ?></span>
                <button class="alert-close" onclick="dismissAlert()">&times;</button>
                <div class="alert-timer"></div>
            </div>
        <?php endif; ?>

        <?php if (!$can_manage_items): ?>
            <div class="permission-notice">
                <i data-lucide="eye"></i>
                <span>You are in <strong>View-Only</strong> mode. You can view item records but cannot add or edit items.</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; flex-wrap: wrap; gap: 16px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Items Directory</h1>

            <div class="header-actions">
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input
                            type="text"
                            name="search"
                            placeholder="Search by Code, Name, Brand..."
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>

                <div style="display: flex; gap: 12px;">
                    <a href="item_issuance.php" class="btn btn-secondary" style="display:flex; align-items:center; gap:8px; text-decoration: none;">
                        <i data-lucide="clipboard-list" style="width:18px;"></i> Item Issuance
                    </a>
                    <?php if ($can_manage_items): ?>
                    <button id="openAddModal" class="btn btn-primary" style="display:flex; align-items:center; gap:8px; white-space: nowrap;">
                        <i data-lucide="plus" style="width:18px;"></i> Add Item
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                (<?php echo mysqli_num_rows($result); ?> found)
                <a href="items.php" style="color: var(--accent-blue); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
             <table>
                <thead>
                     <tr>
                        <th>Code</th>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Stock</th>
                        <th>Unit Price</th>
                        <th>Status</th>
                        <th>Action</th>
                     </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <?php
                                $stockClass = ($row['current_stock'] == 0) ? 'stock-zero' : 'stock-normal';
                            ?>
                            <tr data-item-id="<?php echo $row['id']; ?>">
                                <td style="font-weight:600; color:var(--accent-blue);"><?php echo htmlspecialchars($row['item_code']); ?></td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['description'] ?: '-'); ?></div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($row['category'] ?: '-'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($row['subcategory'] ?: ''); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($row['brand'] ?: '-'); ?></td>
                                <td>
                                    <span class="stock-badge <?php echo $stockClass; ?>">
                                        <?php echo number_format($row['current_stock']); ?> <?php echo htmlspecialchars($row['unit_of_measure']); ?>
                                    </span>
                                </td>
                                <td style="font-weight:500; text-align: right;">₱ <?php echo number_format($row['selling_price'], 2); ?></td>
                                <td>
                                    <?php $statusClass = ($row['status'] == 1) ? 'status-active' : 'status-inactive'; ?>
                                    <span class="status-pill <?php echo $statusClass; ?>">
                                        <?php echo ($row['status'] == 1) ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn edit-btn" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <?php if ($can_manage_items): ?>
                                            <i data-lucide="edit-2" style="width:18px;"></i> View / Edit
                                        <?php else: ?>
                                            <i data-lucide="eye" style="width:18px;"></i> View
                                        <?php endif; ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No items found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                    <br>
                                    <a href="items.php" style="color: var(--accent-blue); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                        View all items
                                    </a>
                                <?php else: ?>
                                    No items found in the database.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--sidebar-dark);">Register New Item</h2>
            </div>
            <button id="closeModal" style="background:#f1f5f9; border:none; width:32px; height:32px; border-radius:50%; cursor:pointer; color:#64748b; display:flex; align-items:center; justify-content:center;">×</button>
        </div>

        <form method="POST" action="" id="addItemForm" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-section">
                        <i data-lucide="info" style="width:16px;"></i> Basic Information
                    </div>

                    <div class="form-group col-2">
                        <label>Item Code <span class="required">*</span></label>
                        <input type="text" name="item_code" id="item_code" value="<?php echo htmlspecialchars($next_code); ?>" required style="background:#f8fafc; font-weight:600;" readonly>
                    </div>

                    <div class="form-group col-4">
                        <label>Item Name <span class="required">*</span></label>
                        <div class="autocomplete-container">
                            <input type="text" name="item_name" id="item_name" required placeholder="Start typing item name..." autocomplete="off">
                            <div id="autocomplete-list" class="autocomplete-list"></div>
                            <div id="autofetch-indicator" class="autofetch-indicator">
                                <i data-lucide="info" style="width:12px; display:inline;"></i> Auto-filled from existing item
                            </div>
                        </div>
                    </div>

                    <div class="form-group col-2">
                        <label>Category <span class="required">*</span></label>
                        <select name="category" id="category" required>
                            <option value="">Select Category</option>
                            <option value="Spare Parts">Spare Parts</option>
                            <option value="Equipment">Equipment</option>
                            <option value="Tools">Tools</option>
                            <option value="Consumables">Consumables</option>
                            <option value="Accessories">Accessories</option>
                            <option value="Services">Services</option>
                            <option value="Lubricants">Lubricants</option>

                        </select>
                    </div>

                    <div class="form-group col-2">
                        <label>Subcategory</label>
                        <input type="text" name="subcategory" id="subcategory" placeholder="e.g. Engine Parts">
                    </div>

                    <div class="form-group col-2">
                        <label>Brand</label>
                        <input type="text" name="brand" id="brand" placeholder="Manufacturer brand">
                    </div>

                    <div class="form-group col-2">
                        <label>Part Number</label>
                        <input type="text" name="part_number" id="part_number" placeholder="SKU/Part #">
                    </div>

                    <div class="form-group col-2">
                        <label>Unit of Measure <span class="required">*</span></label>
                        <select name="unit_of_measure" id="unit_of_measure" required>
                            <option value="">Select Unit</option>
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="box">Box</option>
                            <option value="set">Set</option>
                            <option value="kg">Kilogram (kg)</option>
                            <option value="ltr">Liter (ltr)</option>
                            <option value="mtr">Meter (mtr)</option>
                            <option value="pack">Pack</option>
                        </select>
                    </div>

                    <div class="form-group col-6">
                        <label>Description</label>
                        <textarea name="description" id="description" rows="2" placeholder="Brief description of the item..."></textarea>
                    </div>

                    <div class="form-group col-6">
                        <label>Compatible Models</label>
                        <input type="text" name="compatible_models" id="compatible_models" placeholder="e.g. Isuzu FTR, Hino 500 Series">
                    </div>

                    <div class="form-section">
                        <i data-lucide="tag" style="width:16px;"></i> Pricing
                    </div>

                    <div class="form-group col-3">
                        <label>Purchase Price (₱)</label>
                        <input type="number" name="purchase_price" id="purchase_price" step="0.01" min="0" value="0.00">
                    </div>

                    <div class="form-group col-3">
                        <label>Selling Price (₱)</label>
                        <input type="number" name="selling_price" id="selling_price" step="0.01" min="0" value="0.00">
                    </div>

                    <div class="form-section">
                        <i data-lucide="package" style="width:16px;"></i> Inventory Details
                    </div>

                    <div class="form-group col-2">
                        <label>Current Stock</label>
                        <input type="number" name="current_stock" id="current_stock" min="0" value="0">
                    </div>

                    <div class="form-group col-2">
                        <label>Minimum Stock</label>
                        <input type="number" name="min_stock" id="min_stock" min="0" value="0">
                    </div>

                    <div class="form-group col-2">
                        <label>Reorder Point</label>
                        <input type="number" name="reorder_point" id="reorder_point" min="0" value="0">
                        <small class="hint">Alert when stock reaches this level</small>
                    </div>

                    <div class="form-group col-2">
                        <label>Reorder Quantity</label>
                        <input type="number" name="reorder_quantity" id="reorder_quantity" min="0" value="0">
                        <small class="hint">Qty to order when restocking</small>
                    </div>

                    <div class="form-group col-2">
                        <label>Location/Bin</label>
                        <input type="text" name="location_bin" id="location_bin" placeholder="e.g. A-12-03">
                    </div>

                    <div class="form-group col-2">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>

                    <div class="form-section">
                        <i data-lucide="image" style="width:16px;"></i> Item Images <small style="font-weight:400; text-transform:none; color:#64748b;">(max 3, jpg/png/gif/webp)</small>
                    </div>

                    <div class="form-group col-6" style="grid-column: span 6;">
                        <label>Attach Images</label>
                        <input type="file" name="item_images[]" id="add_item_images" accept="image/*" multiple>
                        <div id="add_image_preview" class="image-preview-grid"></div>
                    </div>

                    <div class="form-group col-6">
                        <label>Internal Notes</label>
                        <textarea name="notes" id="notes" rows="2" placeholder="Any additional information..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" id="closeModalBtn" class="btn btn-secondary">Discard</button>
                <button type="submit" name="add_item" class="btn btn-save">Register Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal-overlay" id="editItemModal">
    <div class="modal">
        <div class="modal-header">
            <div>
                <h2 style="margin:0; font-size:20px; font-weight:700; color:var(--sidebar-dark);" id="editModalTitle">Edit Item</h2>
            </div>
            <button id="closeEditModal" style="background:#f1f5f9; border:none; width:32px; height:32px; border-radius:50%; cursor:pointer; color:#64748b; display:flex; align-items:center; justify-content:center;">×</button>
        </div>

        <form method="POST" action="" id="editForm" enctype="multipart/form-data">
            <input type="hidden" name="item_id" id="edit_item_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-section">
                        <i data-lucide="info" style="width:16px;"></i> Basic Information
                    </div>

                    <div class="form-group col-2">
                        <label>Item Code</label>
                        <input type="text" id="edit_item_code" disabled style="background:#f8fafc; font-weight:600;">
                    </div>

                    <div class="form-group col-4">
                        <label>Item Name <span class="required">*</span></label>
                        <input type="text" name="item_name" id="edit_item_name" required placeholder="Enter item name">
                    </div>

                    <div class="form-group col-2">
                        <label>Category <span class="required">*</span></label>
                        <select name="category" id="edit_category" required>
    <option value="">Select Category</option>
    <option value="SPARE PARTS">Spare Parts</option>
    <option value="EQUIPMENT">Equipment</option>
    <option value="TOOLS">Tools</option>
    <option value="CONSUMABLES">Consumables</option>
    <option value="ACCESSORIES">Accessories</option>
    <option value="SERVICES">Services</option>
    <option value="LUBRICANTS">Lubricants</option>

</select>
                    </div>

                    <div class="form-group col-2">
                        <label>Subcategory</label>
                        <input type="text" name="subcategory" id="edit_subcategory" placeholder="e.g. Engine Parts">
                    </div>

                    <div class="form-group col-2">
                        <label>Brand</label>
                        <input type="text" name="brand" id="edit_brand" placeholder="Manufacturer brand">
                    </div>

                    <div class="form-group col-2">
                        <label>Part Number</label>
                        <input type="text" name="part_number" id="edit_part_number" placeholder="SKU/Part #">
                    </div>

                    <div class="form-group col-2">
                        <label>Unit of Measure <span class="required">*</span></label>
                        <select name="unit_of_measure" id="edit_unit_of_measure" required>
    <option value="">Select Unit</option>
    <option value="PCS">Pieces (pcs)</option>
    <option value="BOX">Box</option>
    <option value="SET">Set</option>
    <option value="KG">Kilogram (kg)</option>
    <option value="LTR">Liter (ltr)</option>
    <option value="MTR">Meter (mtr)</option>
    <option value="PACK">Pack</option>
</select>
                    </div>

                    <div class="form-group col-6">
                        <label>Description</label>
                        <textarea name="description" id="edit_description" rows="2" placeholder="Brief description of the item..."></textarea>
                    </div>

                    <div class="form-group col-6">
                        <label>Compatible Models</label>
                        <input type="text" name="compatible_models" id="edit_compatible_models" placeholder="e.g. Isuzu FTR, Hino 500 Series">
                    </div>

                    <div class="form-section">
                        <i data-lucide="tag" style="width:16px;"></i> Pricing
                    </div>

                    <div class="form-group col-3">
                        <label>Purchase Price (₱)</label>
                        <input type="number" name="purchase_price" id="edit_purchase_price" step="0.01" min="0" value="0.00">
                    </div>

                    <div class="form-group col-3">
                        <label>Selling Price (₱)</label>
                        <input type="number" name="selling_price" id="edit_selling_price" step="0.01" min="0" value="0.00">
                    </div>

                    <div class="form-section">
                        <i data-lucide="package" style="width:16px;"></i> Inventory Details
                    </div>

                    <div class="form-group col-2">
                        <label>Current Stock</label>
                        <input type="number" name="current_stock" id="edit_current_stock" min="0" value="0">
                    </div>

                    <div class="form-group col-2">
                        <label>Minimum Stock</label>
                        <input type="number" name="min_stock" id="edit_min_stock" min="0" value="0">
                    </div>

                    <div class="form-group col-2">
                        <label>Reorder Point</label>
                        <input type="number" name="reorder_point" id="edit_reorder_point" min="0" value="0">
                        <small class="hint">Alert when stock reaches this level</small>
                    </div>

                    <div class="form-group col-2">
                        <label>Reorder Quantity</label>
                        <input type="number" name="reorder_quantity" id="edit_reorder_quantity" min="0" value="0">
                        <small class="hint">Qty to order when restocking</small>
                    </div>

                    <div class="form-group col-2">
                        <label>Location/Bin</label>
                        <input type="text" name="location_bin" id="edit_location_bin" placeholder="e.g. A-12-03">
                    </div>

                    <div class="form-group col-2">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>

                    <div class="form-section">
                        <i data-lucide="image" style="width:16px;"></i> Item Images <small style="font-weight:400; text-transform:none; color:#64748b;">(max 3 total)</small>
                    </div>

                    <div class="form-group col-6" style="grid-column: span 6;">
                        <label>Existing Images</label>
                        <div id="edit_existing_images" class="image-preview-grid">
                            <div style="color:#94a3b8; font-size:12px;">No images attached.</div>
                        </div>
                    </div>

                    <div class="form-group col-6" style="grid-column: span 6;">
                        <label>Add New Images</label>
                        <input type="file" name="item_images[]" id="edit_item_images" accept="image/*" multiple>
                        <div id="edit_new_image_preview" class="image-preview-grid"></div>
                    </div>

                    <div class="form-group col-6">
                        <label>Internal Notes</label>
                        <textarea name="notes" id="edit_notes" rows="2" placeholder="Any additional information..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer" id="editModalFooter">
                <button type="button" id="closeEditModalBtn" class="btn btn-secondary">Cancel</button>
                <button type="submit" name="edit_item" class="btn btn-save">Update Item</button>
            </div>
        </form>
    </div>
</div>

<script>
    lucide.createIcons();

    const userRoles = <?php echo json_encode($user_roles); ?>;
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    const canManageItems = <?php echo $can_manage_items ? 'true' : 'false'; ?>;

    const modal = document.getElementById('accessModal');

    function checkAccess(page) {
        if (userRoles.includes('admin')) return true;
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) return true;
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

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (modal?.style.display === 'flex') closeModal();
            if (addModal?.style.display === 'flex') closeAddModal();
            if (editModal?.style.display === 'flex') closeEditModal();
        }
    });

    // ============================================================
    // UPPERCASE INPUT HANDLING
    // ============================================================

    function applyUppercaseToInputs() {
        const inputs = document.querySelectorAll('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]):not([type="radio"]):not([type="number"]):not([type="email"]):not([type="file"]), textarea');
        inputs.forEach(input => {
            if (input.type === 'number') return;
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

    function applyTransformationsToModalInputs() {
        const addModal = document.getElementById('addItemModal');
        const editModal = document.getElementById('editItemModal');

        [addModal, editModal].forEach(modal => {
            if (modal) {
                const inputs = modal.querySelectorAll('input:not([type="hidden"]):not([type="date"]):not([type="checkbox"]):not([type="radio"]):not([type="number"]):not([type="email"]):not([type="file"]), textarea');
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

    function toUpperCaseData(data) {
        const uppercaseFields = ['item_name', 'category', 'subcategory', 'description', 'brand',
                                  'part_number', 'compatible_models', 'unit_of_measure', 'location_bin', 'notes'];
        const result = {...data};
        uppercaseFields.forEach(field => {
            if (result[field] && typeof result[field] === 'string') {
                result[field] = result[field].toUpperCase();
            }
        });
        return result;
    }

    document.addEventListener('DOMContentLoaded', function() {
        applyUppercaseToInputs();
        applyUppercaseToSearch();
        applyTransformationsToModalInputs();

        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    applyTransformationsToModalInputs();
                }
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });

    // ── Alert Message Auto-hide ────────────────────────────────
    function dismissAlert() {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            alert.classList.add('fade-out');
            setTimeout(function() {
                alert.style.display = 'none';
            }, 500);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            const isSuccess = alert.classList.contains('alert-success');
            setTimeout(function() {
                alert.classList.add('fade-out');
                setTimeout(function() {
                    alert.style.display = 'none';
                    if (isSuccess) {
                        window.location.href = window.location.pathname;
                    }
                }, 500);
            }, 3000);
        }
    });

    // ── Search Functionality ──────────────────────────────────────
    const searchInput = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearSearch');
    const searchForm = searchInput?.closest('form');

    let searchTimeout;
    searchInput?.addEventListener('input', function() {
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

    // ── Add Item Modal ────────────────────────────────────────────
    const addModal = document.getElementById('addItemModal');
    const openAddBtn = document.getElementById('openAddModal');
    const closeAddBtns = [
        document.getElementById('closeModal'),
        document.getElementById('closeModalBtn')
    ];

    function openAddModal() {
        if (!canManageItems) {
            alert('You are in view-only mode. You cannot add items.');
            return;
        }
        addModal.style.display = 'flex';
        setTimeout(() => {
            document.getElementById('item_name')?.focus();
            applyTransformationsToModalInputs();
        }, 100);
        document.getElementById('autofetch-indicator').classList.remove('show');
    }

    function closeAddModal() {
        addModal.style.display = 'none';
        document.getElementById('addItemForm').reset();
        document.getElementById('item_code').value = '<?php echo $next_code; ?>';
        document.getElementById('autocomplete-list').classList.remove('show');
        document.getElementById('autofetch-indicator').classList.remove('show');
        document.getElementById('add_image_preview').innerHTML = '';
        const inp = document.getElementById('add_item_images');
        if (inp) inp.value = '';
        selectedItemData = null;
    }

    openAddBtn?.addEventListener('click', openAddModal);
    closeAddBtns.forEach(btn => btn?.addEventListener('click', closeAddModal));

    addModal?.addEventListener('click', e => {
        if (e.target === addModal) closeAddModal();
    });

    // ── Autocomplete Functionality ──────────────────────────────
    const itemNames = <?php echo json_encode($item_names); ?>;
    const itemNameInput = document.getElementById('item_name');
    const autocompleteList = document.getElementById('autocomplete-list');
    const autofetchIndicator = document.getElementById('autofetch-indicator');
    let selectedItemData = null;

    function fetchItemData(itemName) {
        return new Promise((resolve, reject) => {
            const exists = itemNames.includes(itemName);
            if (!exists) {
                resolve(null);
                return;
            }

            fetch('get_item_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'item_name=' + encodeURIComponent(itemName)
            })
            .then(response => response.json())
            .then(data => {
                resolve(data);
            })
            .catch(error => {
                console.error('Error fetching item data:', error);
                reject(error);
            });
        });
    }

    itemNameInput.addEventListener('input', function(e) {
        const start = this.selectionStart;
        const end = this.selectionEnd;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(start, end);

        const searchValue = this.value.trim();

        if (searchValue.length === 0) {
            autocompleteList.classList.remove('show');
            autofetchIndicator.classList.remove('show');
            return;
        }

        const matches = itemNames.filter(name =>
            name.toLowerCase().includes(searchValue.toLowerCase())
        );

        if (matches.length === 0) {
            autocompleteList.classList.remove('show');
            return;
        }

        let html = '';
        matches.slice(0, 10).forEach(name => {
            html += `
                <div class="autocomplete-item" data-item-name="${name}">
                    <div class="item-name">${name}</div>
                </div>
            `;
        });

        autocompleteList.innerHTML = html;
        autocompleteList.classList.add('show');
    });

    autocompleteList.addEventListener('click', function(e) {
        const item = e.target.closest('.autocomplete-item');
        if (!item) return;

        const itemName = item.dataset.itemName;
        itemNameInput.value = itemName;
        autocompleteList.classList.remove('show');

        autofetchIndicator.textContent = 'Loading item data...';
        autofetchIndicator.classList.add('show');

        fetchItemData(itemName)
            .then(data => {
                if (data) {
                    document.getElementById('item_code').value = data.item_code || '';
                    document.getElementById('category').value = data.category ? data.category.toUpperCase() : '';
                    document.getElementById('subcategory').value = data.subcategory ? data.subcategory.toUpperCase() : '';

                    selectedItemData = data;

                    autofetchIndicator.innerHTML = '<i data-lucide="check-circle" style="width:12px; display:inline;"></i> Auto-filled: Item Code, Category, and Subcategory from existing item';
                    autofetchIndicator.classList.add('show');

                    lucide.createIcons();
                } else {
                    autofetchIndicator.classList.remove('show');
                    selectedItemData = null;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                autofetchIndicator.textContent = 'Error fetching item data';
                autofetchIndicator.classList.add('show');
            });
    });

    itemNameInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const selected = autocompleteList.querySelector('.autocomplete-item:hover');
            if (selected) {
                selected.click();
                e.preventDefault();
            }
        }
    });

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-container')) {
            autocompleteList.classList.remove('show');
        }
    });

    // ── Edit Item Modal ────────────────────────────────────────────
    const editModal = document.getElementById('editItemModal');
    const closeEditBtns = [
        document.getElementById('closeEditModal'),
        document.getElementById('closeEditModalBtn')
    ];

    function setItemFormReadonly(isReadonly) {
        const form = document.getElementById('editForm');
        const inputs = form.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea');
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

        const requiredStars = document.querySelectorAll('#editForm .required');
        requiredStars.forEach(star => {
            star.style.display = isReadonly ? 'none' : 'inline';
        });

        // Hide image upload input if readonly
        const editImgInput = document.getElementById('edit_item_images');
        if (editImgInput) {
            editImgInput.style.display = isReadonly ? 'none' : '';
            const lbl = editImgInput.previousElementSibling;
            if (lbl && lbl.tagName === 'LABEL') {
                lbl.style.display = isReadonly ? 'none' : '';
            }
        }
    }

    function openEditModal(itemData) {
        const data = toUpperCaseData(itemData);

        if (!canManageItems) {
            document.getElementById('editModalTitle').textContent = 'View Item';
            setItemFormReadonly(true);
            document.getElementById('editModalFooter').style.display = 'none';
        } else {
            document.getElementById('editModalTitle').textContent = 'Edit Item';
            setItemFormReadonly(false);
            document.getElementById('editModalFooter').style.display = 'flex';
        }

        document.getElementById('edit_item_id').value = data.id;
        document.getElementById('edit_item_code').value = data.item_code;
        document.getElementById('edit_item_name').value = data.item_name || '';
        document.getElementById('edit_category').value = data.category || '';
        document.getElementById('edit_subcategory').value = data.subcategory || '';
        document.getElementById('edit_brand').value = data.brand || '';
        document.getElementById('edit_part_number').value = data.part_number || '';
        document.getElementById('edit_unit_of_measure').value = data.unit_of_measure || '';
        document.getElementById('edit_description').value = data.description || '';
        document.getElementById('edit_compatible_models').value = data.compatible_models || '';
        document.getElementById('edit_purchase_price').value = data.purchase_price || 0;
        document.getElementById('edit_selling_price').value = data.selling_price || 0;
        document.getElementById('edit_current_stock').value = data.current_stock || 0;
        document.getElementById('edit_min_stock').value = data.min_stock || 0;
        document.getElementById('edit_reorder_point').value = data.reorder_point || 0;
        document.getElementById('edit_reorder_quantity').value = data.reorder_quantity || 0;
        document.getElementById('edit_location_bin').value = data.location_bin || '';
        document.getElementById('edit_status').value = data.status || 1;
        document.getElementById('edit_notes').value = data.notes || '';

        // Reset image UI
        window.__editExistingCount = 0;
        document.getElementById('edit_new_image_preview').innerHTML = '';
        const editInp = document.getElementById('edit_item_images');
        if (editInp) editInp.value = '';

        // Load existing images
        loadExistingImages(data.item_code);

        editModal.style.display = 'flex';
        setTimeout(() => {
            if (canManageItems) document.getElementById('edit_item_name')?.focus();
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

    window.openEditModal = openEditModal;

    // ── Autocomplete for Edit Modal ──────────────────────────────
    itemNameInput.addEventListener('focus', function() {
        if (this.value.trim().length > 0) {
            this.dispatchEvent(new Event('input'));
        }
    });

    itemNameInput.addEventListener('blur', function() {
        setTimeout(() => {
            if (!autocompleteList.matches(':hover')) {
                autocompleteList.classList.remove('show');
            }
        }, 200);
    });

    itemNameInput.addEventListener('change', function() {
        const currentValue = this.value.trim();
        if (selectedItemData && selectedItemData.item_name !== currentValue) {
            selectedItemData = null;
            document.getElementById('item_code').value = '<?php echo $next_code; ?>';
            document.getElementById('category').value = '';
            document.getElementById('subcategory').value = '';
            autofetchIndicator.classList.remove('show');
        }
    });

    itemNameInput.addEventListener('input', function() {
        const currentValue = this.value.trim().toUpperCase();
        if (selectedItemData && selectedItemData.item_name !== currentValue) {
            const exists = itemNames.some(name => name === currentValue);
            if (!exists) {
                selectedItemData = null;
                document.getElementById('item_code').value = '<?php echo $next_code; ?>';
                autofetchIndicator.classList.remove('show');
            }
        }
    });

    // ============================================================
    // IMAGE UPLOAD HANDLING
    // ============================================================
    const MAX_IMAGES = 3;

    // Render preview for Add modal
    function renderAddPreviews(files) {
        const container = document.getElementById('add_image_preview');
        container.innerHTML = '';
        Array.from(files).slice(0, MAX_IMAGES).forEach((file) => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = e => {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `<img src="${e.target.result}" alt="preview">`;
                container.appendChild(div);
            };
            reader.readAsDataURL(file);
        });
        if (files.length > MAX_IMAGES) {
            const warn = document.createElement('div');
            warn.style.cssText = 'grid-column: 1/-1; color: #dc2626; font-size: 12px;';
            warn.textContent = `Only the first ${MAX_IMAGES} images will be uploaded.`;
            container.appendChild(warn);
        }
    }

    const addImageInput = document.getElementById('add_item_images');
    addImageInput?.addEventListener('change', function () {
        renderAddPreviews(this.files);
    });

    // Render preview for Edit modal (new files)
    const editImageInput = document.getElementById('edit_item_images');
    editImageInput?.addEventListener('change', function () {
        const container = document.getElementById('edit_new_image_preview');
        container.innerHTML = '';
        const remaining = MAX_IMAGES - (window.__editExistingCount || 0);
        Array.from(this.files).slice(0, remaining).forEach(file => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = e => {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.innerHTML = `<img src="${e.target.result}" alt="preview">`;
                container.appendChild(div);
            };
            reader.readAsDataURL(file);
        });
        if (this.files.length > remaining) {
            const warn = document.createElement('div');
            warn.style.cssText = 'grid-column: 1/-1; color: #dc2626; font-size: 12px;';
            warn.textContent = `Only ${remaining} more image(s) can be added (max ${MAX_IMAGES} total).`;
            container.appendChild(warn);
        }
    });

    // Fetch and render existing images in Edit modal
    async function loadExistingImages(item_code) {
        const container = document.getElementById('edit_existing_images');
        if (!container) return;
        container.innerHTML = '<div style="color:#94a3b8; font-size:12px;">Loading...</div>';
        try {
            const res = await fetch('get_item_images.php?item_code=' + encodeURIComponent(item_code));
            const images = await res.json();
            window.__editExistingCount = images.length;
            container.innerHTML = '';
            if (!images.length) {
                container.innerHTML = '<div style="color:#94a3b8; font-size:12px;">No images attached.</div>';
                return;
            }
            images.forEach(img => {
                const div = document.createElement('div');
                div.className = 'image-preview-item';
                div.dataset.imageId = img.id;

                // Tooltip shows upload datetime and uploader (Asia/Manila)
                const uploadedLabel = img.created_at
                    ? `Uploaded: ${img.created_at}${img.created_by ? ' by ' + img.created_by : ''}`
                    : '';

                div.innerHTML = `
                    <img src="../uploads/items_images/${img.item_image}" alt="item image" title="${uploadedLabel}">
                    ${canManageItems ? '<button type="button" class="remove-image-btn" title="Mark for deletion">&times;</button>' : ''}
                `;

                if (canManageItems) {
                    div.querySelector('.remove-image-btn').addEventListener('click', () => {
                        const isMarked = div.classList.toggle('marked-for-delete');
                        let hidden = div.querySelector('input[name="delete_images[]"]');
                        if (isMarked) {
                            if (!hidden) {
                                hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = 'delete_images[]';
                                hidden.value = img.id;
                                div.appendChild(hidden);
                            }
                        } else if (hidden) {
                            hidden.remove();
                        }
                    });
                }
                container.appendChild(div);
            });
        } catch (err) {
            console.error(err);
            container.innerHTML = '<div style="color:#dc2626; font-size:12px;">Failed to load images.</div>';
        }
    }
</script>
</body>
</html>