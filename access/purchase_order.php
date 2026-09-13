<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

// ─── AJAX Endpoints ───────────────────────────────────────────────────────────
if (isset($_GET['action'])) {

    header('Content-Type: application/json');

    if ($_GET['action'] === 'get_suppliers') {
        $stmt = $conn->query("SELECT supplier_code, supplier_name, payment_terms FROM oncall_forwarding.supplier_lists ORDER BY supplier_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    // ─── Get ALL Items (regardless of supplier) ──────────────────────────────
    if ($_GET['action'] === 'get_all_items') {
        $stmt = $conn->query("SELECT item_code, item_name, unit_of_measure, selling_price, purchase_price, part_number FROM oncall_forwarding.item_masterlist ORDER BY item_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }
    
    // ─── Get Trucks for dropdown ───────────────────────────────────────────────
    if ($_GET['action'] === 'get_trucks') {
        $stmt = $conn->query("SELECT truck_code, brand, model FROM oncall_forwarding.truck_masterlist ORDER BY truck_code");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }
    
    // ─── Get Trailers for dropdown ─────────────────────────────────────────────
    if ($_GET['action'] === 'get_trailers') {
        $stmt = $conn->query("SELECT trailer_code, manufacturer as brand, model FROM oncall_forwarding.trailer_masterlist ORDER BY trailer_code");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }
    
    // ─── Get Prime Movers for dropdown ─────────────────────────────────────────
    if ($_GET['action'] === 'get_prime_movers') {
        $stmt = $conn->query("SELECT prime_mover_code, brand, model FROM oncall_forwarding.prime_movers_masterlist ORDER BY prime_mover_code");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }
    
    // ─── Get Customers for dropdown ────────────────────────────────────────────
    if ($_GET['action'] === 'get_customers') {
        $stmt = $conn->query("SELECT customer_code, full_name FROM oncall_forwarding.customer_masterlist ORDER BY full_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }
    
    // ─── Get Company Address ───────────────────────────────────────────────────
    if ($_GET['action'] === 'get_company_address') {
        $stmt = $conn->query("SELECT full_address FROM oncall_forwarding.company_profile LIMIT 1");
        $row = $stmt->fetch_assoc();
        $address = $row ? $row['full_address'] : '';
        echo json_encode(['full_address' => $address]);
        exit;
    }
    
    // ─── Get Next PO Number Helper ───────────────────────────────────────────
    function getNextPONumber($conn) {
        $currentYear = date('Y');
        
        $sql = "SELECT po_number FROM purchase_order 
                WHERE po_number LIKE 'PO-" . $currentYear . "-%' 
                ORDER BY po_number DESC LIMIT 1";
        $result = $conn->query($sql);
        
        if ($result && $row = $result->fetch_assoc()) {
            $lastPONumber = $row['po_number'];
            $parts = explode('-', $lastPONumber);
            $lastSeq = intval(end($parts));
            $nextSeq = $lastSeq + 1;
        } else {
            $nextSeq = 1;
        }
        
        $formattedSeq = str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
        return 'PO-' . $currentYear . '-' . $formattedSeq;
    }
    
    // ─── Save Purchase Order (Submit for Approval) ─────────────────────────────
    if ($_GET['action'] === 'save_po' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['supplier_code']) || empty($input['purchase_type']) || empty($input['items'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }
        
        date_default_timezone_set('Asia/Manila');
        
        $po_number = getNextPONumber($conn);
        
        $supplier_code = $conn->real_escape_string($input['supplier_code']);
        $supplier_name = $conn->real_escape_string($input['supplier_name']);
        $purchase_type = $conn->real_escape_string($input['purchase_type']);
        $po_date = $conn->real_escape_string($input['po_date']);
        $expected_delivery = !empty($input['expected_delivery']) ? $conn->real_escape_string($input['expected_delivery']) : null;
        $payment_terms = $conn->real_escape_string($input['payment_terms']);
        $currency = $conn->real_escape_string($input['currency']);
        $delivery_address = $conn->real_escape_string($input['delivery_address']);
        $delivery_mode = $conn->real_escape_string($input['delivery_mode']);
        $warranty = $conn->real_escape_string($input['warranty']);
        $remarks = $conn->real_escape_string($input['remarks']);
        $freight = floatval($input['freight']);
        $subtotal = floatval($input['subtotal']);
        $total_vat = floatval($input['total_vat']);
        $total_amount = floatval($input['total_amount']);
        $with_vat = isset($input['with_vat']) ? intval($input['with_vat']) : 1;
        $withholding_tax_percent = isset($input['withholding_tax_percent']) ? floatval($input['withholding_tax_percent']) : 0;
        $withholding_tax_amount = isset($input['withholding_tax_amount']) ? floatval($input['withholding_tax_amount']) : 0;
        $net_amount_due = isset($input['net_amount_due']) ? floatval($input['net_amount_due']) : $total_amount;
        
        // Discount fields
        $discount_percent = isset($input['discount_percent']) ? floatval($input['discount_percent']) : 0;
        $discount_amount = isset($input['discount_amount']) ? floatval($input['discount_amount']) : 0;
        $item_discount_amount = isset($input['item_discount_amount']) ? floatval($input['item_discount_amount']) : 0;
        
        $created_by = $conn->real_escape_string($_SESSION['username'] ?? 'system');
        $created_at = date('Y-m-d H:i:s');
        $status = 'Created';
        
        // Vehicle fields based on purchase type
        $vehicle_code = !empty($input['vehicle_code']) ? $conn->real_escape_string($input['vehicle_code']) : null;
        $vehicle_brand = !empty($input['vehicle_brand']) ? $conn->real_escape_string($input['vehicle_brand']) : null;
        $vehicle_model = !empty($input['vehicle_model']) ? $conn->real_escape_string($input['vehicle_model']) : null;
        
        // Store vehicle type and code separately for reference
        $truck_code = null;
        $trailer_code = null;
        $prime_mover_code = null;
        $customer_code = null;
        
        // Determine which vehicle code field to use based on purchase type
        if ($purchase_type === 'For Truck Repair and Maintenance') {
            $truck_code = $vehicle_code;
        } elseif ($purchase_type === 'For Trailers') {
            $trailer_code = $vehicle_code;
        } elseif ($purchase_type === 'For Prime Movers') {
            $prime_mover_code = $vehicle_code;
        } elseif ($purchase_type === 'For Motorpool') {
            $motorpool_category = $input['motorpool_category'] ?? null;
            // Store in the appropriate field based on category
            if ($motorpool_category === 'customer') {
                $customer_code = $vehicle_code;
                $vehicle_brand = $input['motorpool_category_label'] ?? 'Customer';
            } elseif ($motorpool_category === 'truck') {
                $truck_code = $vehicle_code;
            } elseif ($motorpool_category === 'trailer') {
                $trailer_code = $vehicle_code;
            } elseif ($motorpool_category === 'prime_mover') {
                $prime_mover_code = $vehicle_code;
            }
        }
        
        $conn->begin_transaction();
        
        try {
            // Calculate item-level discount total
            $total_item_discount = 0;
            
            foreach ($input['items'] as $item) {
                $item_code = $conn->real_escape_string($item['item_code']);
                $item_name = $conn->real_escape_string($item['item_name']);
                $part_number = isset($item['part_number']) ? $conn->real_escape_string($item['part_number']) : '';
                $unit = $conn->real_escape_string($item['unit_of_measure']);
                $qty_ordered = intval($item['quantity']);
                $unit_cost = floatval($item['unit_cost']);
                $item_discount = isset($item['discount']) ? floatval($item['discount']) : 0;
                $item_discount_amount = isset($item['discount_amount']) ? floatval($item['discount_amount']) : 0;
                
                $total_item_discount += $item_discount_amount;
                
                $without_vat = floatval($item['without_vat']);
                $vat_amount = floatval($item['vat_amount']);
                $item_total = $unit_cost * $qty_ordered;
                
                $sql = "INSERT INTO purchase_order (
                    po_number, supplier_code, supplier_name, purchase_type, po_date, 
                    expected_delivery, payment_terms, currency, delivery_address, delivery_mode,
                    item_code, item, part_number, unit, qty_ordered, unit_cost, warranty, remarks, freight,
                    subtotal, total_vat, total_amount, status, created_by, created_at,
                    truck_code, brand, model, with_vat, withholding_tax_percent, withholding_tax_amount, net_amount_due,
                    trailer_code, prime_mover_code, customer_code,
                    discount_percent, discount_amount, item_discount_amount
                ) VALUES (
                    '$po_number', '$supplier_code', '$supplier_name', '$purchase_type', '$po_date',
                    " . ($expected_delivery ? "'$expected_delivery'" : "NULL") . ", '$payment_terms', '$currency', 
                    '$delivery_address', '$delivery_mode', '$item_code', '$item_name', '$part_number', '$unit', 
                    $qty_ordered, $unit_cost, '$warranty', '$remarks', $freight,
                    $without_vat, $vat_amount, $item_total, '$status', '$created_by', '$created_at',
                    " . ($truck_code ? "'$truck_code'" : "NULL") . ", " . ($vehicle_brand ? "'$vehicle_brand'" : "NULL") . ", " . ($vehicle_model ? "'$vehicle_model'" : "NULL") . ",
                    $with_vat, $withholding_tax_percent, $withholding_tax_amount, $net_amount_due,
                    " . ($trailer_code ? "'$trailer_code'" : "NULL") . ",
                    " . ($prime_mover_code ? "'$prime_mover_code'" : "NULL") . ",
                    " . ($customer_code ? "'$customer_code'" : "NULL") . ",
                    $discount_percent, $discount_amount, $item_discount_amount
                )";
                
                if (!$conn->query($sql)) {
                    throw new Exception("Error inserting item: " . $conn->error);
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Purchase Order submitted for approval successfully!',
                'po_number' => $po_number
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode([]);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Check if user has access to purchase order page (admin or purchase_order_maker)
$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Purchase Order page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);
$current_page = basename($_SERVER['PHP_SELF']);

function getNextPONumberDisplay($conn) {
    $currentYear = date('Y');
    
    $sql = "SELECT po_number FROM purchase_order 
            WHERE po_number LIKE 'PO-" . $currentYear . "-%' 
            ORDER BY po_number DESC LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $row = $result->fetch_assoc()) {
        $lastPONumber = $row['po_number'];
        $parts = explode('-', $lastPONumber);
        $lastSeq = intval(end($parts));
        $nextSeq = $lastSeq + 1;
    } else {
        $nextSeq = 1;
    }
    
    $formattedSeq = str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
    return 'PO-' . $currentYear . '-' . $formattedSeq;
}

$nextPONumber = getNextPONumberDisplay($conn);

$company_address = '';
$address_query = $conn->query("SELECT full_address FROM oncall_forwarding.company_profile LIMIT 1");
if ($address_query && $row = $address_query->fetch_assoc()) {
    $company_address = htmlspecialchars($row['full_address']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="stylesheet" href="css/purchase_order.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
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

<!-- Loading Modal -->
<div id="loadingModal" class="modal-overlay" style="display: none;">
    <div class="access-modal">
        <i data-lucide="loader" style="animation: spin 1s linear infinite;"></i>
        <h3>Processing...</h3>
        <p>Please wait while we submit your purchase order.</p>
    </div>
</div>

<!-- Include Sidebar -->
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Purchase Order</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <div class="page-header-actions">
                <h1>
                    <span class="icon-wrap"><i data-lucide="shopping-cart" style="width:18px;height:18px;"></i></span>
                    New Purchase Order
                </h1>
                 <span class="po-tag" id="po-number-display"><?php echo $nextPONumber; ?></span>
                <span class="status-pending" id="status-display">Draft</span>
                
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
               

                <a href="purchase_order_list_all.php" class="view-orders-btn">
                    <i data-lucide="list" style="width:16px;height:16px;"></i>
                    View Orders
                </a>

                <a href="purchase_orders_list.php" class="view-orders-btn">
                    <i data-lucide="list" style="width:16px;height:16px;"></i>
                    Receive Orders
                </a>
            </div>
        </div>

        <!-- PO Info -->
        <div class="form-card">
            <div class="form-card-title"><i data-lucide="truck" style="width:16px;height:16px;"></i> Supplier & Order Details</div>
            <div class="form-grid">

                <div class="form-group">
                    <label>Supplier Code</label>
                    <select id="supplier-code-select" onchange="onSupplierCodeChange(this.value)">
                        <option value="">-- Select Supplier Code --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Supplier Name</label>
                    <select id="supplier-name-select" onchange="onSupplierNameChange(this.value)">
                        <option value="">-- Select Supplier --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Purchase Type <span style="color: red;">*</span></label>
                    <select id="purchase-type" required onchange="onPurchaseTypeChange(this.value)">
                        <option value="">-- Select Purchase Type --</option>
                        <option value="For Office Supplies">For Office Supplies</option>
                        <option value="For Truck Repair and Maintenance">For Truck Repair and Maintenance</option>
                        <option value="For Trailers">For Trailers</option>
                        <option value="For Prime Movers">For Prime Movers</option>
                        <option value="For Motorpool">For Motorpool</option>
                        <option value="For Stock">For Stock</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>PO Date</label>
                    <input type="date" id="po-date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Expected Delivery</label>
                    <input type="date" id="expected-delivery">
                </div>
                <div class="form-group">
                    <label>Payment Terms</label>
                    <select id="payment-terms">
                        <option value="">-- Select Payment Terms --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Currency</label>
                    <select id="currency">
                        <option>PHP</option>
                        <option>USD</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Requested By</label>
                    <input type="text" id="requested-by" value="<?php echo htmlspecialchars($username); ?>">
                </div>
                <div class="form-group full-width">
                    <label>Delivery / Ship-To Address</label>
                    <textarea id="delivery-address" placeholder="Enter delivery address"><?php echo htmlspecialchars($company_address); ?></textarea>
                </div>
            </div>
            
            <!-- Vehicle Selection Section -->
            <div id="vehicle-section" class="vehicle-section" style="display: none;">
                <div class="vehicle-section-title" id="vehicle-section-title">
                    <i data-lucide="truck" style="width:16px;height:16px;"></i>
                    <span id="vehicle-section-label">Vehicle Information</span>
                    <span class="vehicle-badge truck" id="vehicle-badge">Vehicle</span>
                </div>
                
                <!-- Motorpool Category Selector (only shown for Motorpool) -->
                <div id="motorpool-category-container" style="display: none;">
                    <div style="font-size:13px; font-weight:500; color:var(--text-muted); margin-bottom:8px;">Select Category:</div>
                    <div class="motorpool-category-selector" id="motorpool-category-selector">
                        <button class="category-btn" data-category="truck" onclick="selectMotorpoolCategory('truck')">
                            <span class="badge-dot truck"></span> Truck
                        </button>
                        <button class="category-btn" data-category="trailer" onclick="selectMotorpoolCategory('trailer')">
                            <span class="badge-dot trailer"></span> Trailer
                        </button>
                        <button class="category-btn" data-category="prime_mover" onclick="selectMotorpoolCategory('prime_mover')">
                            <span class="badge-dot prime-mover"></span> Prime Mover
                        </button>
                        <button class="category-btn" data-category="customer" onclick="selectMotorpoolCategory('customer')">
                            <span class="badge-dot customer"></span> Customer
                        </button>
                    </div>
                </div>
                
                <div class="vehicle-grid">
                    <div class="form-group">
                        <label id="vehicle-code-label">Vehicle Code <span style="color: red;">*</span></label>
                        <select id="vehicle-code" onchange="onVehicleCodeChange(this.value)">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label id="vehicle-brand-label">Brand / Manufacturer</label>
                        <input type="text" id="vehicle-brand" readonly style="background: #f1f5f9;">
                    </div>
                    <div class="form-group" id="vehicle-model-group">
                        <label>Model</label>
                        <input type="text" id="vehicle-model" readonly style="background: #f1f5f9;">
                    </div>
                </div>
                <div id="vehicle-info-display" class="vehicle-info-display" style="display: none;"></div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="form-card">
            <div class="form-card-title">
                <i data-lucide="package" style="width:16px;height:16px;"></i> 
                Ordered Items
                <span class="vat-toggle-container">
                    <label>
                        <input type="checkbox" id="vat-toggle" checked onchange="toggleVAT()">
                        <span>Calculate VAT (12%)</span>
                    </label>
                    <span id="vat-status-badge" class="vat-status-badge inclusive">VAT Inclusive</span>
                </span>
            </div>

            <div id="no-items-notice" class="notice-info">
                <i data-lucide="info"></i>
                Loading available items...
            </div>

            <div id="items-section" style="display:none;">
                <div class="table-wrapper" style="overflow-x: auto;">
                    <table id="items-table" style="min-width: 1400px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item Code</th>
                                <th>Item / Description</th>
                                <th>Unit</th>
                                <th>Qty Ordered</th>
                                <th>Unit Cost</th>
                                <th>Discount %</th>
                                <th>Discount Amt</th>
                                <th>Without VAT</th>
                                <th>VAT Amount</th>
                                <th>Total Cost</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="items-body"></tbody>
                    </table>
                </div>
                <button class="add-row-btn" onclick="addRow()"><i data-lucide="plus" style="width:14px;height:14px;"></i> Add Item</button>
            </div>
        </div>

        <!-- Terms + Totals -->
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="form-card" style="flex:1;min-width:260px;">
                <div class="form-card-title"><i data-lucide="file-text" style="width:16px;height:16px;"></i> Terms & Conditions</div>
                <div class="terms-grid">
                    <div class="form-group">
                        <label>Delivery Mode</label>
                        <select id="delivery-mode">
                            <option>For Pick-Up</option>
                            <option>Delivered to Site</option>
                            <option>Third-Party Courier</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Warranty</label>
                        <input type="text" id="warranty" placeholder="e.g. 6 months">
                    </div>
                </div>
                <div class="form-group" style="margin-top:14px;">
                    <label>Remarks / Special Instructions</label>
                    <textarea id="remarks" placeholder="Add any special instructions for the supplier..."></textarea>
                </div>
            </div>
            <div>
                <div class="totals-box">
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">Item Subtotal</span><span id="item-subtotal-display">₱0.00</span></div>
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">Item Discounts</span><span id="item-discount-total" style="color: #dc2626;">₱0.00</span></div>
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">Subtotal (excl. tax)</span><span id="subtotal">₱0.00</span></div>
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">PO Discount</span>
                        <input type="number" id="po-discount-percent" value="0" min="0" max="100" step="0.01" style="width:60px;text-align:right;padding:4px 8px;" oninput="calcTotals()"> %
                        <span style="margin:0 4px;">=</span>
                        <input type="number" id="po-discount-amount" value="0" min="0" step="0.01" style="width:100px;text-align:right;padding:4px 8px;" oninput="calcTotals()">
                    </div>
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">Total VAT</span><span id="vat-total">₱0.00</span></div>
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">Shipping / Freight</span>
                        <input type="number" id="freight" value="0" min="0" step="0.01" style="width:100px;text-align:right;padding:4px 8px;" oninput="calcTotals()">
                    </div>
                    <div class="totals-row total-final"><span style="font-size: 20px; color: black; font-weight: 800;">Total PO Amount</span> <span id="grand-total">₱0.00</span></div>
                </div>
                
                <!-- Withholding Tax Section -->
                <div class="tax-section" id="tax-section">
                    <label class="tax-checkbox">
                        <input type="checkbox" id="apply-withholding-tax" onchange="toggleWithholdingTax()">
                        Apply 5% Withholding Tax (Expanded)
                    </label>
                    <div id="tax-details" style="display: none;">
                        <div class="tax-details">
                            <div class="tax-row">
                                <span class="tax-label">Total PO Amount: </span>
                                <span class="tax-value" id="tax-base-amount">₱0.00</span>
                            </div>
                            <div class="tax-row">
                                <span class="tax-label">Withholding Tax (5%):</span>
                                <span class="tax-value" id="withholding-tax-amount">₱0.00</span>
                            </div>
                            <div class="tax-row net-amount-row">
                                <span class="tax-label">Net Amount Due:</span>
                                <span class="tax-value" id="net-amount-due">₱0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions-row" style="margin-top:24px;">
            <a href="purchase_order.php" class="btn-secondary"><i data-lucide="x" style="width:15px;height:15px;"></i> Cancel</a>
            <button class="btn-primary" onclick="submitForApproval()"><i data-lucide="send" style="width:15px;height:15px;"></i> Submit</button>
        </div>
    </div>
</main>

<script>
    lucide.createIcons();

    const userRoles = <?php echo json_encode($user_roles); ?>;
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    const companyAddress = <?php echo json_encode($company_address); ?>;
    
    const modal = document.getElementById('accessModal');
    const loadingModal = document.getElementById('loadingModal');

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

    // ── State ──────────────────────────────────────────────────────────────────
    let suppliersData = [];
    let allItemsData  = [];
    let trucksData    = [];
    let trailersData  = [];
    let primeMoversData = [];
    let customersData = [];
    let rowCount      = 0;
    let isSyncing     = false;
    let selectedMotorpoolCategory = null;

    // ── Load Suppliers ─────────────────────────────────────────────────────────
    async function loadSuppliers() {
        try {
            const res  = await fetch('purchase_order.php?action=get_suppliers');
            suppliersData = await res.json();
            populateSupplierDropdowns();
        } catch (e) {
            console.error('Failed to load suppliers:', e);
        }
    }
    
    // ── Load All Items (regardless of supplier) ──────────────────────────────
    async function loadAllItems() {
        const notice = document.getElementById('no-items-notice');
        const section = document.getElementById('items-section');
        
        notice.innerHTML = `<span class="loading-text">
            <i data-lucide="loader"></i>
            Loading available items...</span>`;
        notice.style.display = 'block';
        section.style.display = 'none';
        lucide.createIcons();

        try {
            const res = await fetch('purchase_order.php?action=get_all_items');
            allItemsData = await res.json();
        } catch (e) {
            console.error('Failed to load items:', e);
            allItemsData = [];
        }

        if (allItemsData.length === 0) {
            notice.innerHTML = `<i data-lucide="package-x"></i>
                No items found in the masterlist. Please add items first.`;
            lucide.createIcons();
            return;
        }

        notice.style.display = 'none';
        section.style.display = 'block';
        
        // Add initial row
        addRow();
    }
    
    // ── Load Trucks ────────────────────────────────────────────────────────────
    async function loadTrucks() {
        try {
            const res = await fetch('purchase_order.php?action=get_trucks');
            trucksData = await res.json();
        } catch (e) {
            console.error('Failed to load trucks:', e);
        }
    }
    
    // ── Load Trailers ──────────────────────────────────────────────────────────
    async function loadTrailers() {
        try {
            const res = await fetch('purchase_order.php?action=get_trailers');
            trailersData = await res.json();
        } catch (e) {
            console.error('Failed to load trailers:', e);
        }
    }
    
    // ── Load Prime Movers ─────────────────────────────────────────────────────
    async function loadPrimeMovers() {
        try {
            const res = await fetch('purchase_order.php?action=get_prime_movers');
            primeMoversData = await res.json();
        } catch (e) {
            console.error('Failed to load prime movers:', e);
        }
    }
    
    // ── Load Customers ─────────────────────────────────────────────────────────
    async function loadCustomers() {
        try {
            const res = await fetch('purchase_order.php?action=get_customers');
            customersData = await res.json();
        } catch (e) {
            console.error('Failed to load customers:', e);
        }
    }
    
    // ── Build Item Options ────────────────────────────────────────────────────
    function buildItemOptions() {
        let opts = '<option value="">-- Select Item --</option>';
        allItemsData.forEach(item => {
            const price = item.selling_price || item.purchase_price || '0.00';
            const partNumber = item.part_number || '';
            const displayText = partNumber 
                ? `[${item.item_code}] ${item.item_name} <span style="font-size:11px;color:#666;">(${partNumber})</span>`
                : `[${item.item_code}] ${item.item_name}`;
            opts += `<option value="${item.item_code}"
                data-unit="${item.unit_of_measure}"
                data-price="${price}"
                data-part-number="${partNumber}">
                ${displayText}
            </option>`;
        });
        return opts;
    }

    // ── Populate Supplier Dropdowns ──────────────────────────────────────────
    function populateSupplierDropdowns() {
        const codeSelect = document.getElementById('supplier-code-select');
        const nameSelect = document.getElementById('supplier-name-select');

        codeSelect.innerHTML = '<option value="">-- Select Supplier Code --</option>';
        nameSelect.innerHTML = '<option value="">-- Select Supplier --</option>';

        suppliersData.forEach(s => {
            codeSelect.innerHTML += `<option value="${s.supplier_code}" data-payment-terms="${s.payment_terms || ''}">${s.supplier_code}</option>`;
            nameSelect.innerHTML += `<option value="${s.supplier_code}" data-payment-terms="${s.payment_terms || ''}">${s.supplier_name}</option>`;
        });
    }

    // ── Populate Payment Terms Dropdown ──────────────────────────────────────
    function populatePaymentTerms(paymentTermsString) {
        const paymentSelect = document.getElementById('payment-terms');
        
        paymentSelect.innerHTML = '<option value="">-- Select Payment Terms --</option>';
        
        if (!paymentTermsString || paymentTermsString.trim() === '') {
            return;
        }
        
        // Split by comma and trim each term
        const terms = paymentTermsString.split(',').map(term => term.trim()).filter(term => term !== '');
        
        // Remove duplicates
        const uniqueTerms = [...new Set(terms)];
        
        // Add each term as an option
        uniqueTerms.forEach(term => {
            const option = document.createElement('option');
            option.value = term;
            option.textContent = term;
            paymentSelect.appendChild(option);
        });
        
        // Auto-select the first term if available
        if (uniqueTerms.length > 0) {
            paymentSelect.value = uniqueTerms[0];
        }
    }

    // ── Supplier Code changed ──────────────────────────────────────────────────
    function onSupplierCodeChange(code) {
        if (isSyncing) return;
        isSyncing = true;
        
        const selectedOption = document.querySelector(`#supplier-code-select option[value="${code}"]`);
        const paymentTerms = selectedOption ? selectedOption.dataset.paymentTerms : '';
        
        populatePaymentTerms(paymentTerms);
        
        document.getElementById('supplier-name-select').value = code;
        isSyncing = false;
    }

    // ── Supplier Name changed ──────────────────────────────────────────────────
    function onSupplierNameChange(code) {
        if (isSyncing) return;
        isSyncing = true;
        
        const selectedOption = document.querySelector(`#supplier-name-select option[value="${code}"]`);
        const paymentTerms = selectedOption ? selectedOption.dataset.paymentTerms : '';
        
        populatePaymentTerms(paymentTerms);
        
        document.getElementById('supplier-code-select').value = code;
        isSyncing = false;
    }

    // ── Add Row ──────────────────────────────────────────────────────────────
    function addRow() {
        rowCount++;
        const tbody = document.getElementById('items-body');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="color:var(--text-muted);font-weight:600;">${rowCount}</td>
            <td class="item-code-cell" style="font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">—</td>
            <td>
                <select class="item-select" style="min-width:200px;" onchange="onItemChange(this)">
                    ${buildItemOptions()}
                </select>
            </td>
            <td><input type="text" class="unit-input" value="" style="width:80px;text-align:center;" readonly></td>
            <td><input type="number" value="1" min="1" style="width:80px;text-align:center;" class="qty-input" oninput="calcRowTotals(this)"></td>
            <td><input type="number" value="0.00" min="0" step="0.01" style="width:110px;text-align:right;" class="price-input" oninput="calcRowTotals(this)"></td>
            <td><input type="number" value="0" min="0" max="100" step="0.01" style="width:70px;text-align:center;" class="discount-percent-input" oninput="calcRowTotals(this)"></td>
            <td><input type="number" value="0.00" min="0" step="0.01" style="width:90px;text-align:right;" class="discount-amount-input" oninput="calcRowTotals(this)"></td>
            <td class="without-vat-cell" style="font-weight:500;text-align:right;">₱0.00</td>
            <td class="vat-amount-cell" style="font-weight:500;text-align:right;">₱0.00</td>
            <td class="amount-cell" style="font-weight:600;text-align:right;">₱0.00</td>
            <td><button class="remove-row" onclick="removeRow(this)"><i data-lucide="trash-2" style="width:15px;height:15px;"></i></button></td>
        `;
        tbody.appendChild(tr);
        lucide.createIcons();
        
        calculateRowTotals(tr);
    }

    // ── Calculate Row Totals ──────────────────────────────────────────────────
    function calculateRowTotals(row) {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const unitCost = parseFloat(row.querySelector('.price-input').value) || 0;
        const discountPercent = parseFloat(row.querySelector('.discount-percent-input').value) || 0;
        let discountAmount = parseFloat(row.querySelector('.discount-amount-input').value) || 0;
        const withVAT = document.getElementById('vat-toggle').checked;
        
        // Calculate item total before discount
        let itemSubtotal = qty * unitCost;
        
        // Calculate discount
        // If discount percent is entered, calculate discount amount from it
        if (discountPercent > 0) {
            discountAmount = itemSubtotal * (discountPercent / 100);
            row.querySelector('.discount-amount-input').value = discountAmount.toFixed(2);
        }
        // If discount amount is entered and discount percent is 0, use the amount
        // Otherwise, discount percent takes precedence
        
        // Apply discount
        let discountedTotal = itemSubtotal - discountAmount;
        
        let withoutVAT, vatAmount, totalCost;
        
        if (withVAT) {
            withoutVAT = discountedTotal / 1.12;
            vatAmount = discountedTotal - withoutVAT;
            totalCost = discountedTotal;
        } else {
            withoutVAT = discountedTotal;
            vatAmount = 0;
            totalCost = discountedTotal;
        }
        
        row.querySelector('.without-vat-cell').textContent = formatPHP(withoutVAT);
        row.querySelector('.vat-amount-cell').textContent = formatPHP(vatAmount);
        row.querySelector('.amount-cell').textContent = formatPHP(totalCost);
        
        return { totalCost, withoutVAT, vatAmount, discountAmount };
    }
    
    // ── Toggle VAT ────────────────────────────────────────────────────────────
    function toggleVAT() {
        document.querySelectorAll('#items-body tr').forEach(row => {
            calculateRowTotals(row);
        });
        calcTotals();
        
        const toggle = document.getElementById('vat-toggle');
        const label = toggle.closest('label');
        const badge = document.getElementById('vat-status-badge');
        
        if (toggle.checked) {
            label.querySelector('span').textContent = 'Calculate VAT (12%)';
            badge.textContent = 'VAT Inclusive';
            badge.className = 'vat-status-badge inclusive';
        } else {
            label.querySelector('span').textContent = 'No VAT (VAT-Exclusive)';
            badge.textContent = 'VAT Exclusive';
            badge.className = 'vat-status-badge exclusive';
        }
    }
    
    // ── Item Change ──────────────────────────────────────────────────────────
    function onItemChange(select) {
        const row = select.closest('tr');
        const selected = select.options[select.selectedIndex];

        if (!selected.value) {
            row.querySelector('.item-code-cell').textContent = '—';
            row.querySelector('.unit-input').value = '';
            row.querySelector('.price-input').value = '0.00';
            delete row.dataset.partNumber;
            calculateRowTotals(row);
            calcTotals();
            return;
        }

        const unit = selected.dataset.unit || '';
        const price = selected.dataset.price || '0.00';
        const partNumber = selected.dataset.partNumber || '';

        row.querySelector('.item-code-cell').textContent = selected.value;
        row.querySelector('.unit-input').value = unit;
        row.querySelector('.price-input').value = parseFloat(price).toFixed(2);
        
        // Store part number in the row data
        row.dataset.partNumber = partNumber;
        
        calculateRowTotals(row);
        calcTotals();
    }
    
    // ── Calculate Row Totals on Input ────────────────────────────────────────
    function calcRowTotals(inputElement) {
        const row = inputElement.closest('tr');
        calculateRowTotals(row);
        calcTotals();
    }

    // ── Remove Row ───────────────────────────────────────────────────────────
    function removeRow(btn) {
        const rows = document.querySelectorAll('#items-body tr');
        if (rows.length === 1) return;
        btn.closest('tr').remove();
        document.querySelectorAll('#items-body tr').forEach((r, i) => {
            r.cells[0].textContent = i + 1;
        });
        calcTotals();
    }

    // ── Format PHP ─────────────────────────────────────────────────────────────
    function formatPHP(val) {
        return '₱' + parseFloat(val).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // ── Calculate Totals ──────────────────────────────────────────────────────
    function calcTotals() {
        let totalItemSubtotal = 0; // Item subtotal before discounts
        let totalItemDiscount = 0;
        let subtotal = 0; // Subtotal after item discounts (excl. VAT)
        let totalVAT = 0;
        const withVAT = document.getElementById('vat-toggle').checked;
        
        document.querySelectorAll('#items-body tr').forEach(row => {
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const unitCost = parseFloat(row.querySelector('.price-input').value) || 0;
            const discountAmount = parseFloat(row.querySelector('.discount-amount-input').value) || 0;
            
            const itemSubtotal = qty * unitCost;
            totalItemSubtotal += itemSubtotal;
            const discountedTotal = itemSubtotal - discountAmount;
            totalItemDiscount += discountAmount;
            
            if (withVAT) {
                // VAT = 12/112 of discounted total
                const vatAmount = discountedTotal * (12 / 112);
                const withoutVAT = discountedTotal - vatAmount;
                subtotal += withoutVAT;
                totalVAT += vatAmount;
            } else {
                subtotal += discountedTotal;
            }
        });
        
        // Apply PO discount on subtotal (excl. VAT)
        const poDiscountPercent = parseFloat(document.getElementById('po-discount-percent').value) || 0;
        let poDiscountAmount = parseFloat(document.getElementById('po-discount-amount').value) || 0;
        
        if (poDiscountPercent > 0) {
            poDiscountAmount = subtotal * (poDiscountPercent / 100);
            document.getElementById('po-discount-amount').value = poDiscountAmount.toFixed(2);
        }
        
        const subtotalAfterPODiscount = subtotal - poDiscountAmount;
        const freight = parseFloat(document.getElementById('freight').value) || 0;
        
        // Grand Total = Subtotal (after item discounts) - PO Discount + VAT + Freight
        const grand = subtotalAfterPODiscount + totalVAT + freight;
        
        // Display values
        document.getElementById('item-subtotal-display').textContent = formatPHP(totalItemSubtotal);
        document.getElementById('item-discount-total').textContent = formatPHP(totalItemDiscount);
        document.getElementById('subtotal').textContent = formatPHP(subtotal);
        document.getElementById('vat-total').textContent = formatPHP(totalVAT);
        document.getElementById('grand-total').textContent = formatPHP(grand);
        
        updateWithholdingTax(grand);
        
        return { itemSubtotal: totalItemSubtotal, totalItemDiscount, subtotal, totalVAT, grand, poDiscountAmount };
    }
    
    // ── Withholding Tax Functions ──────────────────────────────────────────────
    function toggleWithholdingTax() {
        const isChecked = document.getElementById('apply-withholding-tax').checked;
        const taxDetails = document.getElementById('tax-details');
        
        if (isChecked) {
            taxDetails.style.display = 'block';
        } else {
            taxDetails.style.display = 'none';
        }
        
        const grandTotal = parseFloat(document.getElementById('grand-total').textContent.replace('₱', '').replace(/,/g, '')) || 0;
        updateWithholdingTax(grandTotal);
    }
    
    function updateWithholdingTax(grandTotal) {
        const isChecked = document.getElementById('apply-withholding-tax').checked;
        const taxBaseSpan = document.getElementById('tax-base-amount');
        const withholdingSpan = document.getElementById('withholding-tax-amount');
        const netAmountSpan = document.getElementById('net-amount-due');
        
        if (taxBaseSpan) {
            taxBaseSpan.textContent = formatPHP(grandTotal);
        }
        
        if (isChecked) {
            const withholdingTax = grandTotal * 0.05;
            const netAmount = grandTotal - withholdingTax;
            
            if (withholdingSpan) withholdingSpan.textContent = formatPHP(withholdingTax);
            if (netAmountSpan) netAmountSpan.textContent = formatPHP(netAmount);
        } else {
            if (withholdingSpan) withholdingSpan.textContent = formatPHP(0);
            if (netAmountSpan) netAmountSpan.textContent = formatPHP(grandTotal);
        }
    }

    // ── Select Motorpool Category ──────────────────────────────────────────────
    function selectMotorpoolCategory(category) {
        selectedMotorpoolCategory = category;
        
        // Update button styles
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.category === category) {
                btn.classList.add('active');
            }
        });
        
        // Populate the dropdown based on category
        populateMotorpoolDropdown(category);
        
        // Reset vehicle info display
        document.getElementById('vehicle-brand').value = '';
        document.getElementById('vehicle-model').value = '';
        document.getElementById('vehicle-info-display').style.display = 'none';
        document.getElementById('vehicle-code').value = '';
        
        // Update label
        const categoryLabels = {
            'truck': 'Truck Code',
            'trailer': 'Trailer Code',
            'prime_mover': 'Prime Mover Code',
            'customer': 'Customer Code'
        };
        document.getElementById('vehicle-code-label').textContent = categoryLabels[category] || 'Vehicle Code';
        
        // Update brand label
        const brandLabels = {
            'truck': 'Brand',
            'trailer': 'Manufacturer',
            'prime_mover': 'Brand',
            'customer': 'Customer Name'
        };
        document.getElementById('vehicle-brand-label').textContent = brandLabels[category] || 'Brand / Manufacturer';
        
        // Hide model field for customers (since customers don't have models)
        const modelGroup = document.getElementById('vehicle-model-group');
        if (category === 'customer') {
            modelGroup.style.display = 'none';
        } else {
            modelGroup.style.display = 'block';
        }
    }
    
    // ── Populate Motorpool Dropdown ───────────────────────────────────────────
    function populateMotorpoolDropdown(category) {
        const vehicleSelect = document.getElementById('vehicle-code');
        vehicleSelect.innerHTML = '<option value="">-- Select --</option>';
        
        let data = [];
        let codeField = '';
        let brandField = '';
        let modelField = '';
        
        switch(category) {
            case 'truck':
                data = trucksData;
                codeField = 'truck_code';
                brandField = 'brand';
                modelField = 'model';
                break;
            case 'trailer':
                data = trailersData;
                codeField = 'trailer_code';
                brandField = 'manufacturer';
                modelField = 'model';
                break;
            case 'prime_mover':
                data = primeMoversData;
                codeField = 'prime_mover_code';
                brandField = 'brand';
                modelField = 'model';
                break;
            case 'customer':
                data = customersData;
                codeField = 'customer_code';
                brandField = 'full_name';
                modelField = ''; // No model for customers
                break;
            default:
                return;
        }
        
        data.forEach(item => {
            const label = item[codeField] || '';
            const brandVal = item[brandField] || '';
            const modelVal = item[modelField] || '';
            // For customers, show "customer_code - full_name"
            const displayText = category === 'customer' 
                ? `${label} - ${brandVal}`
                : `${label}`;
            vehicleSelect.innerHTML += `<option value="${label}" data-brand="${brandVal}" data-model="${modelVal}">${displayText}</option>`;
        });
    }
    
    // ── Populate Vehicle Dropdown (for non-motorpool) ──────────────────────────
    function populateVehicleDropdown(vehicleType) {
        const vehicleSelect = document.getElementById('vehicle-code');
        vehicleSelect.innerHTML = '<option value="">-- Select --</option>';
        
        let data = [];
        let codeField = '';
        let brandField = '';
        let modelField = '';
        
        switch(vehicleType) {
            case 'truck':
                data = trucksData;
                codeField = 'truck_code';
                brandField = 'brand';
                modelField = 'model';
                break;
            case 'trailer':
                data = trailersData;
                codeField = 'trailer_code';
                brandField = 'manufacturer';
                modelField = 'model';
                break;
            case 'prime_mover':
                data = primeMoversData;
                codeField = 'prime_mover_code';
                brandField = 'brand';
                modelField = 'model';
                break;
            default:
                return;
        }
        
        data.forEach(item => {
            const label = item[codeField] || '';
            vehicleSelect.innerHTML += `<option value="${label}" data-brand="${item[brandField] || ''}" data-model="${item[modelField] || ''}">${label}</option>`;
        });
    }
    
    // ── Handle Vehicle Code Change ─────────────────────────────────────────────
    function onVehicleCodeChange(vehicleCode) {
        const purchaseType = document.getElementById('purchase-type').value;
        let selectedVehicle = null;
        let data = [];
        let codeField = '';
        let brandField = '';
        let modelField = '';
        let displayLabel = '';
        let isMotorpool = purchaseType === 'For Motorpool';
        
        if (isMotorpool) {
            // Use the selected motorpool category
            const category = selectedMotorpoolCategory || 'truck';
            switch(category) {
                case 'truck':
                    data = trucksData;
                    codeField = 'truck_code';
                    brandField = 'brand';
                    modelField = 'model';
                    displayLabel = 'Truck';
                    break;
                case 'trailer':
                    data = trailersData;
                    codeField = 'trailer_code';
                    brandField = 'manufacturer';
                    modelField = 'model';
                    displayLabel = 'Trailer';
                    break;
                case 'prime_mover':
                    data = primeMoversData;
                    codeField = 'prime_mover_code';
                    brandField = 'brand';
                    modelField = 'model';
                    displayLabel = 'Prime Mover';
                    break;
                case 'customer':
                    data = customersData;
                    codeField = 'customer_code';
                    brandField = 'full_name';
                    modelField = ''; // No model for customers
                    displayLabel = 'Customer';
                    break;
                default:
                    return;
            }
        } else {
            // Standard vehicle selection
            switch(purchaseType) {
                case 'For Truck Repair and Maintenance':
                    data = trucksData;
                    codeField = 'truck_code';
                    brandField = 'brand';
                    modelField = 'model';
                    displayLabel = 'Truck';
                    break;
                case 'For Trailers':
                    data = trailersData;
                    codeField = 'trailer_code';
                    brandField = 'manufacturer';
                    modelField = 'model';
                    displayLabel = 'Trailer';
                    break;
                case 'For Prime Movers':
                    data = primeMoversData;
                    codeField = 'prime_mover_code';
                    brandField = 'brand';
                    modelField = 'model';
                    displayLabel = 'Prime Mover';
                    break;
                default:
                    return;
            }
        }
        
        selectedVehicle = data.find(item => item[codeField] === vehicleCode);
        const brandInput = document.getElementById('vehicle-brand');
        const modelInput = document.getElementById('vehicle-model');
        const infoDisplay = document.getElementById('vehicle-info-display');
        
        if (selectedVehicle) {
            brandInput.value = selectedVehicle[brandField] || '';
            modelInput.value = selectedVehicle[modelField] || '';
            
            const brandLabel = isMotorpool && selectedMotorpoolCategory === 'customer' ? 'Customer Name' : 
                              (brandField === 'manufacturer' ? 'Manufacturer' : 'Brand');
            
            infoDisplay.style.display = 'block';
            let infoHTML = `<strong>Selected ${displayLabel}:</strong> ${selectedVehicle[codeField]}<br>`;
            infoHTML += `<strong>${brandLabel}:</strong> ${selectedVehicle[brandField] || 'N/A'}`;
            // Only show model if it exists (not for customers)
            if (selectedVehicle[modelField]) {
                infoHTML += `<br><strong>Model:</strong> ${selectedVehicle[modelField]}`;
            }
            infoDisplay.innerHTML = infoHTML;
        } else {
            brandInput.value = '';
            modelInput.value = '';
            infoDisplay.style.display = 'none';
        }
    }
    
    // ── Handle Purchase Type Change ──────────────────────────────────────────
    function onPurchaseTypeChange(purchaseType) {
        const vehicleSection = document.getElementById('vehicle-section');
        const sectionTitle = document.getElementById('vehicle-section-title');
        const sectionLabel = document.getElementById('vehicle-section-label');
        const vehicleBadge = document.getElementById('vehicle-badge');
        const codeLabel = document.getElementById('vehicle-code-label');
        const vehicleSelect = document.getElementById('vehicle-code');
        const brandInput = document.getElementById('vehicle-brand');
        const modelInput = document.getElementById('vehicle-model');
        const infoDisplay = document.getElementById('vehicle-info-display');
        const motorpoolContainer = document.getElementById('motorpool-category-container');
        const modelGroup = document.getElementById('vehicle-model-group');
        
        // Reset vehicle fields
        vehicleSelect.value = '';
        brandInput.value = '';
        modelInput.value = '';
        infoDisplay.style.display = 'none';
        
        // Determine which vehicle types need to show
        const vehicleTypes = ['For Truck Repair and Maintenance', 'For Trailers', 'For Prime Movers'];
        const isMotorpool = purchaseType === 'For Motorpool';
        
        if (vehicleTypes.includes(purchaseType)) {
            vehicleSection.style.display = 'block';
            motorpoolContainer.style.display = 'none';
            modelGroup.style.display = 'block'; // Show model for vehicles
            
            let icon = 'truck';
            let label = '';
            let badgeClass = '';
            let badgeText = '';
            let codeFieldLabel = '';
            
            switch(purchaseType) {
                case 'For Truck Repair and Maintenance':
                    icon = 'truck';
                    label = 'Truck Information';
                    badgeClass = 'truck';
                    badgeText = 'Truck';
                    codeFieldLabel = 'Truck Code';
                    populateVehicleDropdown('truck');
                    break;
                case 'For Trailers':
                    icon = 'box';
                    label = 'Trailer Information';
                    badgeClass = 'trailer';
                    badgeText = 'Trailer';
                    codeFieldLabel = 'Trailer Code';
                    populateVehicleDropdown('trailer');
                    break;
                case 'For Prime Movers':
                    icon = 'truck';
                    label = 'Prime Mover Information';
                    badgeClass = 'prime-mover';
                    badgeText = 'Prime Mover';
                    codeFieldLabel = 'Prime Mover Code';
                    populateVehicleDropdown('prime_mover');
                    break;
            }
            
            // Update section title
            sectionTitle.innerHTML = `
                <i data-lucide="${icon}" style="width:16px;height:16px;"></i>
                <span id="vehicle-section-label">${label}</span>
                <span class="vehicle-badge ${badgeClass}">${badgeText}</span>
            `;
            codeLabel.textContent = codeFieldLabel + ' *';
            
            // Recreate icons
            lucide.createIcons();
            
            // Make vehicle code required
            vehicleSelect.required = true;
            
            // Reset motorpool category
            selectedMotorpoolCategory = null;
            
        } else if (isMotorpool) {
            vehicleSection.style.display = 'block';
            motorpoolContainer.style.display = 'block';
            modelGroup.style.display = 'block'; // Will be hidden if customer is selected
            
            // Update section title
            sectionTitle.innerHTML = `
                <i data-lucide="users" style="width:16px;height:16px;"></i>
                <span id="vehicle-section-label">Motorpool Information</span>
                <span class="vehicle-badge motorpool">Motorpool</span>
            `;
            
            // Reset category selection
            document.querySelectorAll('.category-btn').forEach(btn => btn.classList.remove('active'));
            selectedMotorpoolCategory = null;
            codeLabel.textContent = 'Select a category above *';
            vehicleSelect.innerHTML = '<option value="">-- Select a category first --</option>';
            vehicleSelect.required = true;
            
            // Reset fields
            document.getElementById('vehicle-brand-label').textContent = 'Brand / Manufacturer';
            modelGroup.style.display = 'block';
            
            lucide.createIcons();
            
        } else {
            vehicleSection.style.display = 'none';
            motorpoolContainer.style.display = 'none';
            vehicleSelect.required = false;
            selectedMotorpoolCategory = null;
        }
    }

    // ── Collect Form Data ──────────────────────────────────────────────────────
    function collectFormData() {
        const totals = calcTotals();
        
        const supplierCode = document.getElementById('supplier-code-select').value;
        const purchaseType = document.getElementById('purchase-type').value;
        
        let supplierName = '';
        const selectedSupplier = suppliersData.find(s => s.supplier_code === supplierCode);
        if (selectedSupplier) {
            supplierName = selectedSupplier.supplier_name;
        }
        
        const applyWithholdingTax = document.getElementById('apply-withholding-tax').checked;
        let withholdingTaxPercent = 0;
        let withholdingTaxAmount = 0;
        let netAmountDue = totals.grand;
        
        if (applyWithholdingTax) {
            withholdingTaxPercent = 5;
            withholdingTaxAmount = totals.grand * 0.05;
            netAmountDue = totals.grand - withholdingTaxAmount;
        }
        
        const withVAT = document.getElementById('vat-toggle').checked;
        
        // Get PO-level discount
        const discountPercent = parseFloat(document.getElementById('po-discount-percent').value) || 0;
        const discountAmount = parseFloat(document.getElementById('po-discount-amount').value) || 0;
        
        // Get vehicle info based on purchase type
        let vehicleCode = null;
        let vehicleBrand = null;
        let vehicleModel = null;
        let motorpoolCategory = null;
        let motorpoolCategoryLabel = null;
        
        const vehicleTypes = ['For Truck Repair and Maintenance', 'For Trailers', 'For Prime Movers'];
        const isMotorpool = purchaseType === 'For Motorpool';
        
        if (vehicleTypes.includes(purchaseType)) {
            vehicleCode = document.getElementById('vehicle-code').value || null;
            vehicleBrand = document.getElementById('vehicle-brand').value || null;
            vehicleModel = document.getElementById('vehicle-model').value || null;
        } else if (isMotorpool) {
            vehicleCode = document.getElementById('vehicle-code').value || null;
            vehicleBrand = document.getElementById('vehicle-brand').value || null;
            vehicleModel = document.getElementById('vehicle-model').value || null;
            motorpoolCategory = selectedMotorpoolCategory;
            
            // Get the display label for the category
            const categoryLabels = {
                'truck': 'Truck',
                'trailer': 'Trailer',
                'prime_mover': 'Prime Mover',
                'customer': 'Customer'
            };
            motorpoolCategoryLabel = categoryLabels[motorpoolCategory] || motorpoolCategory;
        }
        
        const poData = {
            supplier_code: supplierCode,
            supplier_name: supplierName,
            purchase_type: purchaseType,
            po_date: document.getElementById('po-date').value,
            expected_delivery: document.getElementById('expected-delivery').value,
            payment_terms: document.getElementById('payment-terms').value,
            currency: document.getElementById('currency').value,
            requested_by: document.getElementById('requested-by').value,
            delivery_address: document.getElementById('delivery-address').value,
            delivery_mode: document.getElementById('delivery-mode').value,
            warranty: document.getElementById('warranty').value,
            remarks: document.getElementById('remarks').value,
            freight: parseFloat(document.getElementById('freight').value) || 0,
            subtotal: totals.subtotal,
            total_vat: totals.totalVAT,
            total_amount: totals.grand,
            with_vat: withVAT ? 1 : 0,
            withholding_tax_percent: withholdingTaxPercent,
            withholding_tax_amount: withholdingTaxAmount,
            net_amount_due: netAmountDue,
            discount_percent: discountPercent,
            discount_amount: discountAmount,
            item_discount_amount: totals.totalItemDiscount || 0,
            vehicle_code: vehicleCode,
            vehicle_brand: vehicleBrand,
            vehicle_model: vehicleModel,
            motorpool_category: motorpoolCategory,
            motorpool_category_label: motorpoolCategoryLabel,
            items: []
        };
        
        document.querySelectorAll('#items-body tr').forEach(row => {
            const itemSelect = row.querySelector('.item-select');
            const selectedOption = itemSelect.options[itemSelect.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                const qty = parseInt(row.querySelector('.qty-input').value) || 0;
                const unitCost = parseFloat(row.querySelector('.price-input').value) || 0;
                const discountPercent = parseFloat(row.querySelector('.discount-percent-input').value) || 0;
                const discountAmount = parseFloat(row.querySelector('.discount-amount-input').value) || 0;
                const partNumber = row.dataset.partNumber || '';
                
                // Calculate item total before discount
                let itemSubtotal = qty * unitCost;
                let finalDiscount = discountAmount;
                
                // If discount percent is entered, calculate discount amount
                if (discountPercent > 0) {
                    finalDiscount = itemSubtotal * (discountPercent / 100);
                }
                
                let discountedTotal = itemSubtotal - finalDiscount;
                let withoutVAT, vatAmount;
                
                if (withVAT) {
                    withoutVAT = discountedTotal / 1.12;
                    vatAmount = discountedTotal - withoutVAT;
                } else {
                    withoutVAT = discountedTotal;
                    vatAmount = 0;
                }
                
                // Extract item name without part number
                const fullText = selectedOption.text;
                let itemName = fullText;
                // Remove part number in parentheses if exists
                const partNumberMatch = fullText.match(/\([^)]*\)$/);
                if (partNumberMatch) {
                    itemName = fullText.replace(/\s*\([^)]*\)$/, '').trim();
                }
                // Remove item code
                const codeMatch = itemName.match(/^\[[^\]]*\]\s*/);
                if (codeMatch) {
                    itemName = itemName.replace(codeMatch[0], '').trim();
                }
                
                poData.items.push({
                    item_code: selectedOption.value,
                    item_name: itemName,
                    part_number: partNumber,
                    unit_of_measure: row.querySelector('.unit-input').value,
                    quantity: qty,
                    unit_cost: unitCost,
                    discount: finalDiscount,
                    discount_amount: finalDiscount,
                    without_vat: withoutVAT,
                    vat_amount: vatAmount,
                    total_cost: discountedTotal
                });
            }
        });
        
        return poData;
    }
    
    // ── Submit for Approval ──────────────────────────────────────────────────
    async function submitForApproval() {
        const supplierCode = document.getElementById('supplier-code-select').value;
        const purchaseType = document.getElementById('purchase-type').value;
        
        if (!supplierCode) {
            alert('Please select a supplier');
            return;
        }
        
        if (!purchaseType) {
            alert('Please select a purchase type');
            return;
        }
        
        // Check vehicle selection for relevant purchase types
        const vehicleTypes = ['For Truck Repair and Maintenance', 'For Trailers', 'For Prime Movers'];
        const isMotorpool = purchaseType === 'For Motorpool';
        
        if (vehicleTypes.includes(purchaseType)) {
            const vehicleCode = document.getElementById('vehicle-code').value;
            if (!vehicleCode) {
                const typeLabel = purchaseType === 'For Truck Repair and Maintenance' ? 'truck' :
                                  purchaseType === 'For Trailers' ? 'trailer' : 'prime mover';
                alert(`Please select a ${typeLabel} for this purchase order`);
                return;
            }
        } else if (isMotorpool) {
            if (!selectedMotorpoolCategory) {
                alert('Please select a vehicle category for Motorpool');
                return;
            }
            const vehicleCode = document.getElementById('vehicle-code').value;
            if (!vehicleCode) {
                alert('Please select a vehicle for this Motorpool purchase order');
                return;
            }
        }
        
        let hasItems = false;
        document.querySelectorAll('#items-body tr').forEach(row => {
            const itemSelect = row.querySelector('.item-select');
            if (itemSelect && itemSelect.value) {
                hasItems = true;
            }
        });
        
        if (!hasItems) {
            alert('Please add at least one item to the purchase order');
            return;
        }
        
        if (!confirm('Are you sure you want to submit this Purchase Order?')) {
            return;
        }
        
        loadingModal.style.display = 'flex';
        
        try {
            const poData = collectFormData();
            
            const response = await fetch('purchase_order.php?action=save_po', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(poData)
            });
            
            const result = await response.json();
            
            loadingModal.style.display = 'none';
            
            if (result.success) {
                alert(`Purchase Order ${result.po_number} successfully created!`);
                window.location.href = 'purchase_orders_list.php';
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            loadingModal.style.display = 'none';
            console.error('Error:', error);
            alert('An error occurred while submitting the purchase order. Please try again.');
        }
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    // Load suppliers, items, trucks, trailers, prime movers, and customers in parallel
    Promise.all([
        loadSuppliers(),
        loadAllItems(),
        loadTrucks(),
        loadTrailers(),
        loadPrimeMovers(),
        loadCustomers()
    ]).then(() => {
        // All data loaded
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal?.style.display === 'flex') {
            closeModal();
        }
    });
</script>
</body>
</html>