<?php

// item_issuance.php
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

// Check if user has access to items issuance page
// Allow purchase_order_maker to view and process issuance
// Allow item_register to view and process issuance
$can_access_issuance = $is_admin || in_array('user', $user_roles) || in_array('purchase_order_maker', $user_roles) || in_array('item_register', $user_roles);

if (!$can_access_issuance) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Item Issuance page."
    ];
    header("Location: home.php");
    exit;
}

// Check if user can process/submit issuance (admin, user, and item_register roles only)
// purchase_order_maker can view but NOT submit
$can_process_issuance = $is_admin || in_array('user', $user_roles) || in_array('item_register', $user_roles);

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch all active items for dropdown - include supplier_code and id
$items_query = "SELECT id, item_code, item_name, current_stock, unit_of_measure, selling_price, supplier_code 
                FROM item_masterlist 
                WHERE status = 1 
                ORDER BY item_name ASC, supplier_code ASC";
$items_result = mysqli_query($conn, $items_query);

// Fetch customers for dropdown (using customer_code)
$customers_query = "SELECT id, customer_code, full_name FROM customer_masterlist WHERE status = 'Active' ORDER BY full_name ASC";
$customers_result = mysqli_query($conn, $customers_query);

// Fetch trucks for dropdown (using truck_code - assuming you have truck_code field)
$trucks_query = "SELECT id, truck_code, plate_number, model FROM truck_masterlist WHERE status = 'Active' ORDER BY plate_number ASC";
$trucks_result = mysqli_query($conn, $trucks_query);

// Get next issuance reference number
$next_ref_no = 'ISS-001';

$ref_query = "SELECT reference_no 
              FROM item_issuance 
              WHERE reference_no LIKE 'ISS-%' 
              ORDER BY CAST(SUBSTRING(reference_no, 5) AS UNSIGNED) DESC 
              LIMIT 1";

$ref_result = mysqli_query($conn, $ref_query);

if ($ref_result && $row = mysqli_fetch_assoc($ref_result)) {
    if (preg_match('/^ISS-(\d+)$/', $row['reference_no'], $matches)) {
        $last_number = (int)$matches[1];
        $next_number = $last_number + 1;
        $next_ref_no = sprintf('ISS-%03d', $next_number);
    }
}

$message = '';
$message_type = '';
$success_reference = '';

// Handle Issuance Submission - Only allow if user can process
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_issuance'])) {
    // Check if user has permission to process issuance
    if (!$can_process_issuance) {
        $message = "You don't have permission to process item issuance.";
        $message_type = 'error';
    } else {
        $reference_no = trim($_POST['reference_no'] ?? '');
        $issuance_date = trim($_POST['issuance_date'] ?? date('Y-m-d'));
        $issuance_type = trim($_POST['issuance_type'] ?? '');
        $customer_code = trim($_POST['customer_code'] ?? '');
        $truck_code = trim($_POST['truck_code'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // Get items data from POST - now includes item_code and supplier_code
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['item_code']) && !empty($item['supplier_code']) && !empty($item['quantity']) && $item['quantity'] > 0) {
                    $items[] = [
                        'item_code' => trim($item['item_code']),
                        'supplier_code' => trim($item['supplier_code']),
                        'quantity' => intval($item['quantity'])
                    ];
                }
            }
        }
        
        $errors = [];
        
        // Validation
        if (empty($reference_no)) {
            $errors[] = "Reference number is required.";
        }
        if (empty($issuance_type)) {
            $errors[] = "Issuance type is required.";
        }
        if ($issuance_type === 'customer' && empty($customer_code)) {
            $errors[] = "Please select a customer.";
        }
        if ($issuance_type === 'truck' && empty($truck_code)) {
            $errors[] = "Please select a truck.";
        }
        if (empty($items)) {
            $errors[] = "Please add at least one item to issue.";
        }
        
        // Check stock availability and get selling prices - now using both item_code and supplier_code
        $items_data = [];
        if (empty($errors)) {
            foreach ($items as $item) {
                $stock_query = "SELECT id, item_code, item_name, current_stock, selling_price, supplier_code 
                               FROM item_masterlist 
                               WHERE item_code = '" . mysqli_real_escape_string($conn, $item['item_code']) . "' 
                               AND supplier_code = '" . mysqli_real_escape_string($conn, $item['supplier_code']) . "'
                               AND status = 1";
                $stock_result = mysqli_query($conn, $stock_query);
                $stock_data = mysqli_fetch_assoc($stock_result);
                
                if (!$stock_data) {
                    $errors[] = "Item " . htmlspecialchars($item['item_code']) . " with supplier " . htmlspecialchars($item['supplier_code']) . " not found.";
                } elseif ($stock_data['current_stock'] < $item['quantity']) {
                    $errors[] = "Insufficient stock for " . htmlspecialchars($stock_data['item_name']) . " (" . htmlspecialchars($stock_data['supplier_code']) . "). Available: " . $stock_data['current_stock'];
                } else {
                    $items_data[] = [
                        'item_code' => $item['item_code'],
                        'supplier_code' => $item['supplier_code'],
                        'quantity' => $item['quantity'],
                        'item_name' => $stock_data['item_name']
                    ];
                }
            }
        }
        
        if (!empty($errors)) {
            $message = implode("<br>", $errors);
            $message_type = 'error';
        } else {
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                $created_by = $_SESSION['username'];
                $created_at = date('Y-m-d H:i:s');
                
                // Insert each item as a separate row in item_issuance table
                foreach ($items_data as $item) {
                    $insert_issuance = "INSERT INTO item_issuance (
                        reference_no, item_code, supplier_code, quantity, customer_code, truck_code, 
                        issuance_date, issuance_type, purpose, remarks, status, 
                        created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)";
                    
                    $stmt = mysqli_prepare($conn, $insert_issuance);
                    mysqli_stmt_bind_param($stmt, "sssissssssss", 
                        $reference_no, 
                        $item['item_code'],
                        $item['supplier_code'],
                        $item['quantity'], 
                        $customer_code, 
                        $truck_code, 
                        $issuance_date, 
                        $issuance_type, 
                        $purpose, 
                        $remarks, 
                        $created_by, 
                        $created_at
                    );
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    
                    // Update stock quantity in item_masterlist using both item_code and supplier_code
                    $update_stock = "UPDATE item_masterlist 
                                     SET current_stock = current_stock - ? 
                                     WHERE item_code = ? AND supplier_code = ?";
                    $stmt = mysqli_prepare($conn, $update_stock);
                    mysqli_stmt_bind_param($stmt, "iss", $item['quantity'], $item['item_code'], $item['supplier_code']);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                
                mysqli_commit($conn);
                
                // Store success message in session for redirect
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'text' => "Item issuance <strong>" . htmlspecialchars($reference_no) . "</strong> created successfully!"
                ];
                
                // Redirect to the same page to prevent form resubmission
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Database error: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Check for flash message from session (after redirect)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message']['text'];
    $message_type = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']);
}

// Refresh reference number after redirect
$ref_result = mysqli_query($conn, $ref_query);
if ($ref_result && $row = mysqli_fetch_assoc($ref_result)) {
    if (preg_match('/^ISS-(\d+)$/', $row['reference_no'], $matches)) {
        $last_number = (int)$matches[1];
        $next_number = $last_number + 1;
        $next_ref_no = sprintf('ISS-%03d', $next_number);
    }
}

// Fetch recent issuances for display (grouped by reference_no)
$issuances_query = "SELECT 
                    reference_no, 
                    issuance_date, 
                    issuance_type, 
                    customer_code, 
                    truck_code, 
                    status, 
                    created_by, 
                    created_at,
                    COUNT(*) as total_items,
                    SUM(quantity) as total_quantity
                    FROM item_issuance
                    GROUP BY reference_no, issuance_date, issuance_type, customer_code, truck_code, status, created_by, created_at
                    ORDER BY created_at DESC
                    LIMIT 50";
$issuances_result = mysqli_query($conn, $issuances_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Issuance | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/items.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/items_issue.css?v=<?= time(); ?>">

    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
 
</head>
<body>

<!-- Access Denied Modal -->
<div id="accessModal" class="modal-overlay access-modal-overlay">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / 
                <a href="items.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;">Items Masterlist</a> / 
                <a href="item_issuance.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;">
                    Item Issuance
                </a>
            </span>
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
        <?php if (!$can_process_issuance): ?>
            <div class="permission-notice">
                <i data-lucide="eye"></i>
                <span>You are in <strong>View-Only</strong> mode. You can view issuance records but cannot create new issuances.</span>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px;">
            <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Item Issuance</h1>
            <a href="items.php" class="btn btn-secondary" style="display:flex; align-items:center; gap:8px; text-decoration: none;">
                <i data-lucide="arrow-left" style="width:18px;"></i> Back to Items
            </a>
        </div>

        <!-- Issuance Form -->
        <div class="table-container" style="margin-bottom: 32px;">
            <form method="POST" action="" id="issuanceForm">
                <div style="padding: 24px;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Reference No.</label>
                            <input type="text" name="reference_no" value="<?php echo htmlspecialchars($next_ref_no); ?>" readonly style="background:#f8fafc; font-weight:600;" <?php echo !$can_process_issuance ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label>Issuance Date</label>
                            <input type="date" name="issuance_date" value="<?php echo date('Y-m-d'); ?>" required <?php echo !$can_process_issuance ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group col-span-2">
                            <label>Issuance Type <span class="required">*</span></label>
                            <div class="issuance-type-group">
                                <label>
                                    <input type="radio" name="issuance_type" value="customer" required <?php echo !$can_process_issuance ? 'disabled' : ''; ?>> To Customer
                                </label>
                                <label>
                                    <input type="radio" name="issuance_type" value="truck" required <?php echo !$can_process_issuance ? 'disabled' : ''; ?>> To Truck
                                </label>
                                <label>
                                    <input type="radio" name="issuance_type" value="internal" required <?php echo !$can_process_issuance ? 'disabled' : ''; ?>> Internal Use
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group" id="customerGroup" style="display: none;">
                            <label>Customer</label>
                            <select name="customer_code" <?php echo !$can_process_issuance ? 'disabled' : ''; ?>>
                                <option value="">Select Customer</option>
                                <?php 
                                mysqli_data_seek($customers_result, 0);
                                while ($customer = mysqli_fetch_assoc($customers_result)): ?>
                                    <option value="<?php echo htmlspecialchars($customer['customer_code']); ?>">
                                        <?php echo htmlspecialchars($customer['full_name']); ?> (<?php echo htmlspecialchars($customer['customer_code']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="truckGroup" style="display: none;">
                            <label>Truck</label>
                            <select name="truck_code" <?php echo !$can_process_issuance ? 'disabled' : ''; ?>>
                                <option value="">Select Truck</option>
                                <?php 
                                mysqli_data_seek($trucks_result, 0);
                                while ($truck = mysqli_fetch_assoc($trucks_result)): ?>
                                    <option value="<?php echo htmlspecialchars($truck['truck_code']); ?>">
                                        <?php echo htmlspecialchars($truck['plate_number']); ?> - <?php echo htmlspecialchars($truck['model']); ?> (<?php echo htmlspecialchars($truck['truck_code']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group col-span-2">
                            <label>Purpose</label>
                            <input type="text" name="purpose" placeholder="e.g., Repair, Maintenance, Stock Transfer" <?php echo !$can_process_issuance ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group col-span-4">
                            <label>Remarks</label>
                            <textarea name="remarks" rows="2" placeholder="Additional notes..." <?php echo !$can_process_issuance ? 'disabled' : ''; ?>></textarea>
                        </div>
                    </div>
                    
                    <!-- Items Section -->
                    <div style="margin-top: 24px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <h3 style="font-size: 16px; font-weight: 600;">Items to Issue</h3>
                            <?php if ($can_process_issuance): ?>
                            <button type="button" id="addItemBtn" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                <i data-lucide="plus" style="width: 14px;"></i> Add Item
                            </button>
                            <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 13px;">View Only</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="items-table-container">
                            <table class="items-table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th style="min-width: 280px;">Item (Code - Name - Supplier)</th>
                                        <th style="width: 120px;">Available Stock</th>
                                        <th style="width: 120px;">Quantity</th>
                                        <th>UOM</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody">
                                    <tr id="noItemsRow">
                                        <td colspan="5" style="text-align: center; color: var(--text-muted);">No items added. Click "Add Item" to begin.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="summary-box">
                            <div class="summary-item">
                                <span>Total Items:</span>
                                <span id="totalItemsCount">0</span>
                            </div>
                            <div class="summary-item">
                                <span>Total Quantity:</span>
                                <span id="totalQuantity">0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-light);">
                        <?php if ($can_process_issuance): ?>
                            <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset</button>
                            <button type="submit" name="submit_issuance" class="btn btn-primary">Submit Issuance</button>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 14px;">You are in view-only mode. Contact administrator for write access.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Recent Issuances Table -->
        <div style="margin-top: 32px;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Recent Issuances</h2>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference No.</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Recipient</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Total Items</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($issuances_result) > 0): ?>
                            <?php while ($issuance = mysqli_fetch_assoc($issuances_result)): 
                                // Determine recipient display
                                if ($issuance['issuance_type'] == 'customer') {
                                    // Get customer name from customer_code
                                    $customer_name_query = "SELECT full_name FROM customer_masterlist WHERE customer_code = '" . mysqli_real_escape_string($conn, $issuance['customer_code']) . "'";
                                    $customer_name_result = mysqli_query($conn, $customer_name_query);
                                    $customer = mysqli_fetch_assoc($customer_name_result);
                                    $recipient = !empty($customer['full_name']) ? $customer['full_name'] : $issuance['customer_code'];
                                } elseif ($issuance['issuance_type'] == 'truck') {
                                    // Get truck info from truck_code
                                    $truck_info_query = "SELECT plate_number FROM truck_masterlist WHERE truck_code = '" . mysqli_real_escape_string($conn, $issuance['truck_code']) . "'";
                                    $truck_info_result = mysqli_query($conn, $truck_info_query);
                                    $truck = mysqli_fetch_assoc($truck_info_result);
                                    $recipient = !empty($truck['plate_number']) ? $truck['plate_number'] : $issuance['truck_code'];
                                } else {
                                    $recipient = 'Internal Use';
                                }
                                
                                // Get supplier info for this issuance
                                $supplier_query = "SELECT DISTINCT sl.supplier_name 
                                                  FROM item_issuance ii
                                                  LEFT JOIN item_masterlist im ON ii.item_code = im.item_code
                                                  LEFT JOIN supplier_lists sl ON im.supplier_code = sl.supplier_code
                                                  WHERE ii.reference_no = '" . mysqli_real_escape_string($conn, $issuance['reference_no']) . "'
                                                  AND sl.supplier_name IS NOT NULL
                                                  LIMIT 1";
                                $supplier_result = mysqli_query($conn, $supplier_query);
                                $supplier_data = mysqli_fetch_assoc($supplier_result);
                                $supplier_name = $supplier_data ? $supplier_data['supplier_name'] : 'N/A';
                            ?>
                                <tr>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($issuance['reference_no']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($issuance['issuance_date'])); ?></td>
                                    <td><?php echo ucfirst($issuance['issuance_type']); ?></td>
                                    <td><?php echo htmlspecialchars($recipient); ?></td>
                                    <td><?php echo htmlspecialchars($supplier_name); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $issuance['status']; ?>">
                                            <?php echo ucfirst($issuance['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $issuance['total_items']; ?> items (<?php echo $issuance['total_quantity']; ?> qty)</td>
                                    <td>
                                        <button class="view-details-btn" onclick="viewIssuanceDetails('<?php echo htmlspecialchars($issuance['reference_no']); ?>')">
                                            <i data-lucide="eye" style="width: 16px;"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding: 40px; color: var(--text-muted);">
                                    No issuance records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- View Details Modal -->
<div class="modal-overlay" id="detailsModal" style="display: none;">
    <div class="modal" style="max-width: 800px;">
        <div class="modal-header">
            <h2 style="margin:0; font-size:20px; font-weight:700;">Issuance Details</h2>
            <button id="closeDetailsModal" style="background:#f1f5f9; border:none; width:32px; height:32px; border-radius:50%; cursor:pointer;">×</button>
        </div>
        <div class="modal-body" id="detailsContent">
            <!-- Content will be loaded via AJAX -->
            <div style="text-align: center; padding: 40px;">Loading...</div>
        </div>
        <div class="modal-footer">
            <button type="button" id="closeDetailsModalBtn" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();
    
    // Items data for dropdown - includes supplier_code and stock
    const itemsData = <?php
        $items_array = [];
        mysqli_data_seek($items_result, 0);
        while ($item = mysqli_fetch_assoc($items_result)) {
            // Create combined display: item_code - item_name (supplier_code)
            $display_text = $item['item_code'] . ' - ' . $item['item_name'] . ' (' . $item['supplier_code'] . ')';
            $items_array[] = [
                'id' => $item['id'],
                'item_code' => $item['item_code'],
                'item_name' => $item['item_name'],
                'supplier_code' => $item['supplier_code'],
                'display_text' => $display_text,
                'current_stock' => (int)$item['current_stock'],
                'unit_of_measure' => $item['unit_of_measure'],
                'selling_price' => $item['selling_price']
            ];
        }
        echo json_encode($items_array);
    ?>;
    
    let itemCounter = 0;
    
    // User roles from PHP
    const userRoles = <?php echo json_encode($user_roles); ?>;
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    const modal = document.getElementById('accessModal');
    const canProcessIssuance = <?php echo $can_process_issuance ? 'true' : 'false'; ?>;
    
    function checkAccess(page) {
        if (userRoles.includes('admin')) return true;
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) return true;
        }
        if (modal) modal.style.display = 'flex';
        return false;
    }
    
    function closeModal() {
        if (modal) modal.style.display = 'none';
    }
    
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }
    
    // Toggle customer/truck fields based on issuance type
    const issuanceTypeRadios = document.querySelectorAll('input[name="issuance_type"]');
    const customerGroup = document.getElementById('customerGroup');
    const truckGroup = document.getElementById('truckGroup');
    
    function toggleRecipientFields() {
        const selectedType = document.querySelector('input[name="issuance_type"]:checked')?.value;
        if (customerGroup) customerGroup.style.display = selectedType === 'customer' ? 'block' : 'none';
        if (truckGroup) truckGroup.style.display = selectedType === 'truck' ? 'block' : 'none';
        
        // Update required attributes
        const customerSelect = document.querySelector('select[name="customer_code"]');
        const truckSelect = document.querySelector('select[name="truck_code"]');
        if (customerSelect) customerSelect.required = (selectedType === 'customer');
        if (truckSelect) truckSelect.required = (selectedType === 'truck');
    }
    
    if (issuanceTypeRadios) {
        issuanceTypeRadios.forEach(radio => {
            radio.addEventListener('change', toggleRecipientFields);
        });
    }
    
    toggleRecipientFields();
    
    // Escape HTML function
    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    // Function to get stock color class
    function getStockColorClass(stock) {
        if (stock <= 0) return 'stock-low';
        if (stock <= 10) return 'stock-medium';
        return 'stock-high';
    }
    
    // Add item to table
    function addItemRow() {
        // Don't allow adding items if user can't process issuance
        if (!canProcessIssuance) {
            alert('You are in view-only mode. You cannot add items.');
            return;
        }
        
        const tbody = document.getElementById('itemsTableBody');
        const noItemsRow = document.getElementById('noItemsRow');
        
        if (noItemsRow) {
            noItemsRow.remove();
        }
        
        const rowId = itemCounter++;
        const row = document.createElement('tr');
        row.id = `item-row-${rowId}`;
        row.innerHTML = `
            <td>
                <select name="items[${rowId}][item_code]" class="item-select" data-row-id="${rowId}" required style="min-width: 280px; max-width: 400px;">
                    <option value="">Select Item</option>
                    ${itemsData.map(item => 
                        `<option value="${item.item_code}" 
                                data-supplier-code="${escapeHtml(item.supplier_code)}"
                                data-item-name="${escapeHtml(item.item_name)}" 
                                data-supplier="${escapeHtml(item.supplier_code)}"
                                data-stock="${item.current_stock}" 
                                data-uom="${escapeHtml(item.unit_of_measure)}"
                                data-item-id="${item.id}">
                            ${escapeHtml(item.display_text)}
                        </option>`
                    ).join('')}
                </select>
                <input type="hidden" name="items[${rowId}][supplier_code]" class="supplier-code-input" value="">
            </td>
            <td class="stock-display" style="text-align: center;">-</td>
            <td><input type="number" name="items[${rowId}][quantity]" class="item-quantity" min="1" value="1" style="width: 80px; text-align: center;" disabled></td>
            <td class="uom-display">-</td>
            <td><button type="button" class="remove-item-btn" onclick="removeItemRow(${rowId})">✕</button></td>
        `;
        
        tbody.appendChild(row);
        
        // Add event listeners
        const select = row.querySelector('.item-select');
        const quantityInput = row.querySelector('.item-quantity');
        const supplierHidden = row.querySelector('.supplier-code-input');
        
        select.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const stock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
            const uom = selectedOption.getAttribute('data-uom');
            const itemName = selectedOption.getAttribute('data-item-name');
            const supplierCode = selectedOption.getAttribute('data-supplier-code');
            
            const stockSpan = row.querySelector('.stock-display');
            const uomSpan = row.querySelector('.uom-display');
            
            // Update stock display with color coding
            if (stockSpan) {
                stockSpan.textContent = stock;
                stockSpan.className = 'stock-display ' + getStockColorClass(stock);
            }
            if (uomSpan) uomSpan.textContent = uom || '-';
            if (supplierHidden) supplierHidden.value = supplierCode || '';
            
            if (quantityInput) {
                quantityInput.disabled = false;
                quantityInput.max = stock;
                quantityInput.value = 1;
                quantityInput.placeholder = stock > 0 ? `Max: ${stock}` : 'No stock';
                
                // Update on input
                quantityInput.oninput = function() {
                    const maxStock = parseInt(this.max) || 0;
                    const currentValue = parseInt(this.value) || 0;
                    if (currentValue > maxStock && maxStock > 0) {
                        alert(`Maximum available stock is ${maxStock} for this item.`);
                        this.value = maxStock;
                    } else if (currentValue <= 0) {
                        this.value = 1;
                    }
                    updateSummary();
                };
            }
            
            updateSummary();
        });
        
        // Initialize quantity input
        if (quantityInput) {
            quantityInput.addEventListener('input', function() {
                updateSummary();
            });
        }
        
        updateSummary();
    }
    
    function updateSummary() {
        const rows = document.querySelectorAll('#itemsTableBody tr:not(#noItemsRow)');
        let totalItems = rows.length;
        let totalQuantity = 0;
        
        rows.forEach(row => {
            const quantity = parseFloat(row.querySelector('.item-quantity')?.value) || 0;
            totalQuantity += quantity;
        });
        
        const totalItemsSpan = document.getElementById('totalItemsCount');
        const totalQuantitySpan = document.getElementById('totalQuantity');
        
        if (totalItemsSpan) totalItemsSpan.textContent = totalItems;
        if (totalQuantitySpan) totalQuantitySpan.textContent = totalQuantity;
    }
    
    function removeItemRow(rowId) {
        const row = document.getElementById(`item-row-${rowId}`);
        if (row) {
            row.remove();
            updateSummary();
            
            const tbody = document.getElementById('itemsTableBody');
            if (tbody && tbody.children.length === 0) {
                tbody.innerHTML = '<tr id="noItemsRow"><td colspan="5" style="text-align: center; color: var(--text-muted);">No items added. Click "Add Item" to begin.</td></tr>';
            }
        }
    }
    
    const addItemBtn = document.getElementById('addItemBtn');
    if (addItemBtn) {
        addItemBtn.addEventListener('click', addItemRow);
    }
    
    function resetForm() {
        if (!canProcessIssuance) {
            alert('You are in view-only mode. You cannot reset the form.');
            return;
        }
        const form = document.getElementById('issuanceForm');
        if (form) form.reset();
        const tbody = document.getElementById('itemsTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr id="noItemsRow"><td colspan="5" style="text-align: center; color: var(--text-muted);">No items added. Click "Add Item" to begin.</td></tr>';
        }
        itemCounter = 0;
        updateSummary();
        toggleRecipientFields();
        
        // Clear any selected radio buttons
        const radios = document.querySelectorAll('input[name="issuance_type"]');
        radios.forEach(radio => radio.checked = false);
    }
    
    // View issuance details
    function viewIssuanceDetails(referenceNo) {
        const detailsModal = document.getElementById('detailsModal');
        const detailsContent = document.getElementById('detailsContent');
        
        if (!detailsModal || !detailsContent) return;
        
        detailsContent.innerHTML = '<div style="text-align: center; padding: 40px;"><i data-lucide="loader" style="width: 32px; height: 32px; animation: spin 1s linear infinite;"></i><br>Loading...</div>';
        detailsModal.style.display = 'flex';
        lucide.createIcons();
        
        // Fetch details via AJAX
        fetch(`get_issuance_details.php?reference_no=${encodeURIComponent(referenceNo)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let itemsHtml = '';
                    if (data.items && data.items.length > 0) {
                        data.items.forEach(item => {
                            itemsHtml += `
                                <tr>
                                    <td style="padding: 10px;">${escapeHtml(item.item_code)}</td>
                                    <td style="padding: 10px;">${escapeHtml(item.item_name)}</td>
                                    <td style="padding: 10px; text-align: center;">${escapeHtml(item.supplier_code || 'N/A')}</td>
                                    <td style="padding: 10px; text-align: center;">${escapeHtml(item.supplier_name || 'N/A')}</td>
                                    <td style="padding: 10px; text-align: center;">${item.quantity}</td>
                                    <td style="padding: 10px; text-align: center;">${escapeHtml(item.unit_of_measure)}</td>
                                </tr>
                            `;
                        });
                    } else {
                        itemsHtml = '<tr><td colspan="6" style="text-align: center; padding: 20px;">No items found</td></tr>';
                    }
                    
                    detailsContent.innerHTML = `
                        <div style="margin-bottom: 20px;">
                            <div class="form-grid" style="grid-template-columns: repeat(2, 1fr); gap: 12px;">
                                <div><strong>Reference No:</strong><br>${escapeHtml(data.issuance.reference_no)}</div>
                                <div><strong>Date:</strong><br>${escapeHtml(data.issuance.issuance_date)}</div>
                                <div><strong>Type:</strong><br>${escapeHtml(data.issuance.issuance_type)}</div>
                                <div><strong>Recipient:</strong><br>${escapeHtml(data.issuance.recipient)}</div>
                                <div><strong>Purpose:</strong><br>${escapeHtml(data.issuance.purpose || 'N/A')}</div>
                                <div><strong>Status:</strong><br><span class="status-badge status-${data.issuance.status}">${escapeHtml(data.issuance.status)}</span></div>
                                <div><strong>Created By:</strong><br>${escapeHtml(data.issuance.created_by)}</div>
                                <div><strong>Created At:</strong><br>${escapeHtml(data.issuance.created_at)}</div>
                                ${data.issuance.remarks ? `<div style="grid-column: span 2;"><strong>Remarks:</strong><br>${escapeHtml(data.issuance.remarks)}</div>` : ''}
                            </div>
                        </div>
                        <h4 style="margin: 16px 0 12px 0;">Items Issued</h4>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: var(--bg-gray);">
                                        <th style="padding: 10px; text-align: left;">Item Code</th>
                                        <th style="padding: 10px; text-align: left;">Item Name</th>
                                        <th style="padding: 10px; text-align: center;">Supplier Code</th>
                                        <th style="padding: 10px; text-align: center;">Supplier Name</th>
                                        <th style="padding: 10px; text-align: center;">Quantity</th>
                                        <th style="padding: 10px; text-align: center;">UOM</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                    <tr style="font-weight: bold; background: var(--bg-gray);">
                                        <td colspan="4" style="text-align: right; padding: 10px;">Total Quantity:</td>
                                        <td style="padding: 10px; text-align: center;">${data.total_quantity}</td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    `;
                } else {
                    detailsContent.innerHTML = `<div style="text-align: center; padding: 40px; color: red;">${escapeHtml(data.message)}</div>`;
                }
                lucide.createIcons();
            })
            .catch(error => {
                console.error('Error:', error);
                detailsContent.innerHTML = `<div style="text-align: center; padding: 40px; color: red;">Error loading details. Please try again.</div>`;
            });
    }
    
    // Modal close handlers
    const detailsModal = document.getElementById('detailsModal');
    const closeDetailsBtns = ['closeDetailsModal', 'closeDetailsModalBtn'];
    
    closeDetailsBtns.forEach(id => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.addEventListener('click', () => {
                if (detailsModal) detailsModal.style.display = 'none';
            });
        }
    });
    
    if (detailsModal) {
        detailsModal.addEventListener('click', (e) => {
            if (e.target === detailsModal) {
                detailsModal.style.display = 'none';
            }
        });
    }
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (modal && modal.style.display === 'flex') closeModal();
            if (detailsModal && detailsModal.style.display === 'flex') detailsModal.style.display = 'none';
        }
    });
    
    // Make functions globally available
    window.viewIssuanceDetails = viewIssuanceDetails;
    window.removeItemRow = removeItemRow;
    window.resetForm = resetForm;
    window.closeModal = closeModal;
    window.addItemRow = addItemRow;
    
    // Add animation for loader
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
    
    // Auto-hide alert after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
</script>
</body>
</html>