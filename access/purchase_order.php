<?php
// purchase_order.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

// ─── AJAX Endpoints ───────────────────────────────────────────────────────────
if (isset($_GET['action'])) {

    header('Content-Type: application/json');

    if ($_GET['action'] === 'get_suppliers') {
        $stmt = $conn->query("SELECT supplier_code, supplier_name, payment_terms FROM oncall_forwarding.supplier_lists ORDER BY supplier_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($_GET['action'] === 'get_all_items') {
        $stmt = $conn->query("SELECT item_code, category, item_name, unit_of_measure, selling_price, purchase_price, part_number FROM oncall_forwarding.item_masterlist where category != 'VEHICLE FUEL' ORDER BY item_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($_GET['action'] === 'get_trucks') {
        $stmt = $conn->query("SELECT truck_code, brand, model, truck_type, plate_number FROM oncall_forwarding.truck_masterlist ORDER BY truck_code");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($_GET['action'] === 'get_trailers') {
        $stmt = $conn->query("SELECT trailer_code, plate_number, trailer_type, manufacturer, model FROM oncall_forwarding.trailer_masterlist ORDER BY trailer_code");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($_GET['action'] === 'get_customers') {
        $stmt = $conn->query("SELECT customer_code, full_name FROM oncall_forwarding.customer_masterlist ORDER BY full_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($_GET['action'] === 'get_employees') {
        $stmt = $conn->query("SELECT employee_code, full_name FROM oncall_forwarding.employee_list ORDER BY full_name");
        $rows = $stmt->fetch_all(MYSQLI_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($_GET['action'] === 'get_company_address') {
        $stmt = $conn->query("SELECT full_address FROM oncall_forwarding.company_profile LIMIT 1");
        $row = $stmt->fetch_assoc();
        $address = $row ? $row['full_address'] : '';
        echo json_encode(['full_address' => $address]);
        exit;
    }

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
        $labor = isset($input['labor']) ? floatval($input['labor']) : 0;

        $subtotal = floatval($input['subtotal']);
        $total_vat = floatval($input['total_vat']);
        $total_amount = floatval($input['total_amount']);
        $with_vat = isset($input['with_vat']) ? intval($input['with_vat']) : 1;

        $discount_percent = isset($input['discount_percent']) ? floatval($input['discount_percent']) : 0;
        $discount_amount = isset($input['discount_amount']) ? floatval($input['discount_amount']) : 0;
        $item_discount_amount = isset($input['item_discount_amount']) ? floatval($input['item_discount_amount']) : 0;

        $withholding_tax_percent = isset($input['withholding_tax_percent']) ? floatval($input['withholding_tax_percent']) : 0;

        if ($withholding_tax_percent > 0) {
            $withholding_base = $subtotal - $discount_amount;
            if ($withholding_base < 0) $withholding_base = 0;
            $withholding_tax_amount = $withholding_base * ($withholding_tax_percent / 100);
            $net_amount_due = $total_amount - $withholding_tax_amount;
        } else {
            $withholding_tax_amount = 0;
            $net_amount_due = $total_amount;
        }

        $created_by = $conn->real_escape_string($_SESSION['username'] ?? 'system');
        $created_at = date('Y-m-d H:i:s');
        $status = 'Created';

        $vehicle_code = !empty($input['vehicle_code']) ? $conn->real_escape_string($input['vehicle_code']) : null;
        $vehicle_brand = !empty($input['vehicle_brand']) ? $conn->real_escape_string($input['vehicle_brand']) : null;
        $vehicle_model = !empty($input['vehicle_model']) ? $conn->real_escape_string($input['vehicle_model']) : null;

        // Always initialize these variables
        $truck_code = null;
        $trailer_code = null;
        $prime_mover_code = null;

        if ($purchase_type === 'For Truck Repair and Maintenance') {
            // Multi-truck: header-level truck_code stays NULL; each item carries its own
            $truck_code = null;
        } elseif ($purchase_type === 'For Trailers') {
            $trailer_code = $vehicle_code;
        } elseif ($purchase_type === 'For Prime Movers') {
            $prime_mover_code = $vehicle_code;
        }
        // For Motorpool, Office Supplies, Stock → all remain null

        $conn->begin_transaction();

        try {
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

                // Per-item truck code for multi-truck mode
                $item_truck_code = null;
                if ($purchase_type === 'For Truck Repair and Maintenance') {
                    $item_truck_code = !empty($item['truck_code'])
                        ? $conn->real_escape_string($item['truck_code'])
                        : null;
                } else {
                    $item_truck_code = $truck_code;
                }

                // Per-item requested_by
                $requested_by = isset($item['requested_by']) ? $conn->real_escape_string($item['requested_by']) : '';

                $sql = "INSERT INTO purchase_order (
                    po_number, supplier_code, supplier_name, purchase_type, po_date, 
                    expected_delivery, payment_terms, currency, delivery_address, delivery_mode,
                    item_code, item, part_number, unit, qty_ordered, unit_cost, warranty, remarks, freight, labor,
                    subtotal, total_vat, total_amount, status, created_by, created_at,
                    truck_code, brand, model, with_vat, withholding_tax_percent, withholding_tax_amount, net_amount_due,
                    trailer_code, prime_mover_code,
                    discount_percent, discount_amount, item_discount_amount, requested_by
                ) VALUES (
                    '$po_number', '$supplier_code', '$supplier_name', '$purchase_type', '$po_date',
                    " . ($expected_delivery ? "'$expected_delivery'" : "NULL") . ", '$payment_terms', '$currency', 
                    '$delivery_address', '$delivery_mode', '$item_code', '$item_name', '$part_number', '$unit', 
                    $qty_ordered, $unit_cost, '$warranty', '$remarks', $freight, $labor,
                    $without_vat, $vat_amount, $item_total, '$status', '$created_by', '$created_at',
                    " . ($item_truck_code ? "'$item_truck_code'" : "NULL") . ", " . ($vehicle_brand ? "'$vehicle_brand'" : "NULL") . ", " . ($vehicle_model ? "'$vehicle_model'" : "NULL") . ",
                    $with_vat, $withholding_tax_percent, $withholding_tax_amount, $net_amount_due,
                    " . ($trailer_code ? "'$trailer_code'" : "NULL") . ",
                    " . ($prime_mover_code ? "'$prime_mover_code'" : "NULL") . ",
                    $discount_percent, $discount_amount, $item_discount_amount, '$requested_by'
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

$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);
$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Purchase Order page."
    ];
    header("Location: home.php");
    exit;
}

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

                <a href="purchase_order_fuel.php" class="view-orders-btn">
                    <i data-lucide="list" style="width:16px;height:16px;"></i>
                    Fuel Purchase Order
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
                    <label>Processed By</label>
                    <input type="text" id="processed-by" value="<?php echo htmlspecialchars($username); ?>" readonly>
                </div>
                <!-- Requested By moved to Ordered Items (per row) -->
                <div class="form-group full-width">
                    <label>Delivery / Ship-To Address</label>
                    <textarea id="delivery-address" placeholder="Enter delivery address"><?php echo htmlspecialchars($company_address); ?></textarea>
                </div>
            </div>
            
            <!-- Vehicle Selection Section (Trailers / Prime Movers only) -->
            <div id="vehicle-section" class="vehicle-section" style="display: none;">
                <div class="vehicle-section-title" id="vehicle-section-title">
                    <i data-lucide="truck" style="width:16px;height:16px;"></i>
                    <span id="vehicle-section-label">Vehicle Information</span>
                    <span class="vehicle-badge truck" id="vehicle-badge">Vehicle</span>
                </div>
                
                <!-- Motorpool Category Selector (kept but never shown for Motorpool type) -->
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
                
                <div class="vehicle-grid" id="single-vehicle-grid">
                    <div class="form-group">
                        <label id="vehicle-code-label">Vehicle Code <span style="color: red;">*</span></label>
                        <select id="vehicle-code" onchange="onVehicleCodeChange(this.value)">
                            <option value="">-- Select --</option>
                        </select>
                    </div>
                    <div class="form-group" id="vehicle-plate-group">
                        <label id="vehicle-plate-label">Plate Number</label>
                        <select id="vehicle-plate-select" onchange="onVehiclePlateChange(this.value)" style="display:none;">
                            <option value="">-- Select Plate Number --</option>
                        </select>
                        <input type="text" id="vehicle-plate" readonly style="background: #f1f5f9;">
                    </div>
                    <div class="form-group" id="vehicle-brand-group">
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
                    <table id="items-table" style="min-width: 1600px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item Code</th>
                                <th>Item / Description</th>
                                <th id="truck-col-header" style="display:none;">Plate Number / Truck</th>
                                <th>Requested By <span style="color:red;">*</span></th>
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
                    <div class="totals-row"><span style="font-size: 15px; color: black; font-weight: 800;">Labor <span style="font-size:12px;font-weight:500;color:#64748b;">(optional)</span></span>
                        <input type="number" id="labor" value="0" min="0" step="0.01" style="width:100px;text-align:right;padding:4px 8px;" oninput="calcTotals()" placeholder="0.00">
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
                                <span class="tax-label">Subtotal (excl. tax):</span>
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

    // ── State ──────────────────────────────────────────────────────────────────
    let suppliersData = [];
    let allItemsData  = [];
    let trucksData    = [];
    let trailersData  = [];
    let customersData = [];
    let employeesData = [];
    let rowCount      = 0;
    let isSyncing     = false;
    let isVehicleSyncing = false;
    let selectedMotorpoolCategory = null;

    // Truck column is shown for Truck Repair type (no pre-selection of trucks)
    let isTruckRepairMode = false;

    // ── Helpers ────────────────────────────────────────────────────────────────
    function isPrimeMover(truck) {
        const type = (truck.truck_type || '').toLowerCase().replace(/[\s_]/g, '');
        return type === 'primemover';
    }

    function getRegularTrucks() {
        return trucksData.filter(t => !isPrimeMover(t));
    }

    function getPrimeMoverTrucks() {
        return trucksData.filter(t => isPrimeMover(t));
    }

    function isTruckRepairModeActive() {
        return document.getElementById('purchase-type').value === 'For Truck Repair and Maintenance';
    }

    function getCurrentVehicleDataset() {
        const purchaseType = document.getElementById('purchase-type').value;

        switch(purchaseType) {
            case 'For Truck Repair and Maintenance': return { data: getRegularTrucks(), codeField: 'truck_code', brandField: 'brand', modelField: 'model', label: 'Truck' };
            case 'For Trailers':                     return { data: trailersData, codeField: 'trailer_code', brandField: 'manufacturer', modelField: 'model', label: 'Trailer' };
            case 'For Prime Movers':                 return { data: getPrimeMoverTrucks(), codeField: 'truck_code', brandField: 'brand', modelField: 'model', label: 'Prime Mover' };
        }
        return null;
    }

    function isPlateDropdown() {
        const info = getCurrentVehicleDataset();
        if (!info) return false;
        return info.label === 'Truck' || info.label === 'Prime Mover' || info.label === 'Trailer';
    }

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

    // ── Load All Items ────────────────────────────────────────────────────────
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
        addRow();
    }

    async function loadTrucks() {
        try {
            const res = await fetch('purchase_order.php?action=get_trucks');
            trucksData = await res.json();
        } catch (e) {
            console.error('Failed to load trucks:', e);
        }
    }

    async function loadTrailers() {
        try {
            const res = await fetch('purchase_order.php?action=get_trailers');
            trailersData = await res.json();
        } catch (e) {
            console.error('Failed to load trailers:', e);
        }
    }

    async function loadCustomers() {
        try {
            const res = await fetch('purchase_order.php?action=get_customers');
            customersData = await res.json();
        } catch (e) {
            console.error('Failed to load customers:', e);
        }
    }

    async function loadEmployees() {
        try {
            const res = await fetch('purchase_order.php?action=get_employees');
            employeesData = await res.json();
        } catch (e) {
            console.error('Failed to load employees:', e);
        }
    }

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

    function populatePaymentTerms(paymentTermsString) {
        const paymentSelect = document.getElementById('payment-terms');
        paymentSelect.innerHTML = '<option value="">-- Select Payment Terms --</option>';
        if (!paymentTermsString || paymentTermsString.trim() === '') return;
        const terms = paymentTermsString.split(',').map(term => term.trim()).filter(term => term !== '');
        const uniqueTerms = [...new Set(terms)];
        uniqueTerms.forEach(term => {
            const option = document.createElement('option');
            option.value = term;
            option.textContent = term;
            paymentSelect.appendChild(option);
        });
        if (uniqueTerms.length > 0) paymentSelect.value = uniqueTerms[0];
    }

    function onSupplierCodeChange(code) {
        if (isSyncing) return;
        isSyncing = true;
        const selectedOption = document.querySelector(`#supplier-code-select option[value="${code}"]`);
        const paymentTerms = selectedOption ? selectedOption.dataset.paymentTerms : '';
        populatePaymentTerms(paymentTerms);
        document.getElementById('supplier-name-select').value = code;
        isSyncing = false;
    }

    function onSupplierNameChange(code) {
        if (isSyncing) return;
        isSyncing = true;
        const selectedOption = document.querySelector(`#supplier-name-select option[value="${code}"]`);
        const paymentTerms = selectedOption ? selectedOption.dataset.paymentTerms : '';
        populatePaymentTerms(paymentTerms);
        document.getElementById('supplier-code-select').value = code;
        isSyncing = false;
    }

    // ── Truck assignment column (for Truck Repair – all trucks available) ─────
    function refreshTruckAssignmentColumn() {
        const header = document.getElementById('truck-col-header');
        const cells = document.querySelectorAll('.truck-assign-cell');
        const shouldShow = isTruckRepairModeActive();
        
        if (header) header.style.display = shouldShow ? 'table-cell' : 'none';
        cells.forEach(cell => {
            cell.style.display = shouldShow ? 'table-cell' : 'none';
            if (shouldShow) {
                populateTruckAssignSelect(cell.querySelector('.truck-assign-select'));
            }
        });
    }

    function populateTruckAssignSelect(select) {
        if (!select) return;
        const currentVal = select.value;
        const trucks = getRegularTrucks();
        
        select.innerHTML = '<option value="">-- Select Truck --</option>';
        trucks.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.truck_code;
            opt.textContent = `${t.plate_number || 'N/A'} — ${t.truck_code}`;
            select.appendChild(opt);
        });
        
        if (currentVal && trucks.some(t => t.truck_code === currentVal)) {
            select.value = currentVal;
        }
        updateTruckAssignStyle(select);
    }

    function updateTruckAssignStyle(select) {
        if (!select) return;
        if (select.value) select.classList.add('is-assigned');
        else select.classList.remove('is-assigned');
    }

    function onTruckAssignChange(select) {
        updateTruckAssignStyle(select);
    }

    // ── Requested By (per row) ────────────────────────────────────────────────
    function populateRequestedBySelect(select) {
        if (!select) return;
        const currentVal = select.value;
        select.innerHTML = '<option value="">-- Select Employee --</option>';
        employeesData.forEach(emp => {
            const opt = document.createElement('option');
            opt.value = emp.full_name;
            opt.textContent = emp.full_name;
            select.appendChild(opt);
        });
        if (currentVal) select.value = currentVal;
    }

    function refreshRequestedByColumns() {
        document.querySelectorAll('.requested-by-select').forEach(sel => {
            populateRequestedBySelect(sel);
        });
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
                <div class="item-search-wrap">
                    <input type="text"
                           class="item-search-input"
                           placeholder="Search item by code, name or part no..."
                           autocomplete="off"
                           oninput="onItemInput(this)"
                           onfocus="onItemInput(this)"
                           onkeydown="onItemKeydown(event, this)"
                           onblur="onItemBlur(this)">
                    <input type="hidden" class="item-code-hidden" value="">
                    <div class="item-suggestions"></div>
                </div>
            </td>
            <td class="truck-assign-cell" style="display:none;">
                <select class="truck-assign-select" onchange="onTruckAssignChange(this)">
                    <option value="">-- Select Truck --</option>
                </select>
            </td>
            <td>
                <select class="requested-by-select" style="min-width:160px;">
                    <option value="">-- Select Employee --</option>
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
        
        // Populate selects for this new row
        populateRequestedBySelect(tr.querySelector('.requested-by-select'));
        refreshTruckAssignmentColumn();
        calculateRowTotals(tr);
    }

    // ── Searchable Item Input ──────────────────────────────────────────────────
    function onItemInput(input) {
        const wrap = input.closest('.item-search-wrap');
        const suggestions = wrap.querySelector('.item-suggestions');
        const hidden = wrap.querySelector('.item-code-hidden');

        if (hidden.value) {
            const sel = allItemsData.find(i => i.item_code === hidden.value);
            if (sel) {
                const pn = (sel.part_number && sel.part_number !== '0') ? ` (${sel.part_number})` : '';
                const expected = `[${sel.item_code}] ${sel.item_name}${pn}`;
                if (input.value !== expected) {
                    hidden.value = '';
                    input.classList.remove('is-selected');
                    delete input.closest('tr').dataset.partNumber;
                    const row = input.closest('tr');
                    row.querySelector('.item-code-cell').textContent = '—';
                    row.querySelector('.unit-input').value = '';
                    row.querySelector('.price-input').value = '0.00';
                    calculateRowTotals(row);
                    calcTotals();
                }
            }
        }

        const query = input.value.trim().toLowerCase();
        let matches = allItemsData;
        if (query) {
            matches = allItemsData.filter(item => {
                const code = (item.item_code || '').toLowerCase();
                const name = (item.item_name || '').toLowerCase();
                const pn   = (item.part_number && item.part_number !== '0') ? item.part_number.toLowerCase() : '';
                return code.includes(query) || name.includes(query) || pn.includes(query);
            });
        }

        const limited = matches.slice(0, 50);

        if (limited.length === 0) {
            suggestions.innerHTML = `<div class="item-suggestion-empty">No matching items</div>`;
        } else {
            suggestions.innerHTML = limited.map((item) => {
                const hasPN = item.part_number && item.part_number !== '0';
                const pn = hasPN ? `<span class="sg-pn">(${item.part_number})</span>` : '';
                return `<div class="item-suggestion"
                             data-code="${item.item_code}"
                             onmousedown="selectItemSuggestion(event, this)">
                    <span class="sg-code">[${item.item_code}]</span> ${item.item_name} ${pn}
                </div>`;
            }).join('');
        }

        suggestions.classList.add('open');
        suggestions.dataset.activeIdx = '-1';
    }

    function onItemKeydown(event, input) {
        const wrap = input.closest('.item-search-wrap');
        const suggestions = wrap.querySelector('.item-suggestions');
        if (!suggestions.classList.contains('open')) return;
        const items = suggestions.querySelectorAll('.item-suggestion');
        if (items.length === 0) return;

        let activeIdx = parseInt(suggestions.dataset.activeIdx || '-1');

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIdx = Math.min(activeIdx + 1, items.length - 1);
            items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
            suggestions.dataset.activeIdx = activeIdx;
            items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIdx = Math.max(activeIdx - 1, 0);
            items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
            suggestions.dataset.activeIdx = activeIdx;
            items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeIdx >= 0 && items[activeIdx]) {
                selectItemSuggestion(new Event('mousedown'), items[activeIdx]);
            }
        } else if (event.key === 'Escape') {
            suggestions.classList.remove('open');
        }
    }

    function onItemBlur(input) {
        const wrap = input.closest('.item-search-wrap');
        const suggestions = wrap.querySelector('.item-suggestions');
        setTimeout(() => suggestions.classList.remove('open'), 150);
    }

    function selectItemSuggestion(event, el) {
        event.preventDefault();
        const wrap = el.closest('.item-search-wrap');
        const row = el.closest('tr');
        const input = wrap.querySelector('.item-search-input');
        const hidden = wrap.querySelector('.item-code-hidden');
        const suggestions = wrap.querySelector('.item-suggestions');

        const code = el.dataset.code;
        const item = allItemsData.find(i => i.item_code === code);
        if (!item) return;

        const price = item.selling_price || item.purchase_price || '0.00';
        const hasPN = item.part_number && item.part_number !== '0';
        const pn = hasPN ? ` (${item.part_number})` : '';

        input.value = `[${item.item_code}] ${item.item_name}${pn}`;
        input.classList.add('is-selected');
        hidden.value = item.item_code;
        row.dataset.partNumber = hasPN ? item.part_number : '';

        row.querySelector('.item-code-cell').textContent = item.item_code;
        row.querySelector('.unit-input').value = item.unit_of_measure || '';
        row.querySelector('.price-input').value = parseFloat(price).toFixed(2);

        suggestions.classList.remove('open');

        calculateRowTotals(row);
        calcTotals();
    }

    // ── Calculate Row Totals ──────────────────────────────────────────────────
    function calculateRowTotals(row) {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const unitCost = parseFloat(row.querySelector('.price-input').value) || 0;
        const discountPercent = parseFloat(row.querySelector('.discount-percent-input').value) || 0;
        let discountAmount = parseFloat(row.querySelector('.discount-amount-input').value) || 0;
        const withVAT = document.getElementById('vat-toggle').checked;
        
        let itemSubtotal = qty * unitCost;
        
        if (discountPercent > 0) {
            discountAmount = itemSubtotal * (discountPercent / 100);
            row.querySelector('.discount-amount-input').value = discountAmount.toFixed(2);
        }
        
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

    function calcRowTotals(inputElement) {
        const row = inputElement.closest('tr');
        calculateRowTotals(row);
        calcTotals();
    }

    function removeRow(btn) {
        const rows = document.querySelectorAll('#items-body tr');
        if (rows.length === 1) return;
        btn.closest('tr').remove();
        document.querySelectorAll('#items-body tr').forEach((r, i) => {
            r.cells[0].textContent = i + 1;
        });
        rowCount = document.querySelectorAll('#items-body tr').length;
        calcTotals();
    }

    function formatPHP(val) {
        return '₱' + parseFloat(val).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function calcTotals() {
        let totalItemSubtotal = 0;
        let totalItemDiscount = 0;
        let subtotal = 0;
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
                const vatAmount = discountedTotal * (12 / 112);
                const withoutVAT = discountedTotal - vatAmount;
                subtotal += withoutVAT;
            } else {
                subtotal += discountedTotal;
            }
        });
        
        const poDiscountPercent = parseFloat(document.getElementById('po-discount-percent').value) || 0;
        let poDiscountAmount = parseFloat(document.getElementById('po-discount-amount').value) || 0;
        
        if (poDiscountPercent > 0) {
            poDiscountAmount = subtotal * (poDiscountPercent / 100);
            document.getElementById('po-discount-amount').value = poDiscountAmount.toFixed(2);
        }
        
        const subtotalAfterPODiscount = subtotal - poDiscountAmount;
        
        let finalVAT = 0;
        if (withVAT) {
            finalVAT = subtotalAfterPODiscount * 0.12;
        }
        
        const freight = parseFloat(document.getElementById('freight').value) || 0;
        const labor = parseFloat(document.getElementById('labor').value) || 0;
        const grand = subtotalAfterPODiscount + finalVAT + freight + labor;
        
        document.getElementById('item-subtotal-display').textContent = formatPHP(totalItemSubtotal);
        document.getElementById('item-discount-total').textContent = formatPHP(totalItemDiscount);
        document.getElementById('subtotal').textContent = formatPHP(subtotal);
        document.getElementById('vat-total').textContent = formatPHP(finalVAT);
        document.getElementById('grand-total').textContent = formatPHP(grand);
        
        updateWithholdingTax(subtotalAfterPODiscount, grand);
        
        return {
            itemSubtotal: totalItemSubtotal,
            totalItemDiscount,
            subtotal,
            totalVAT: finalVAT,
            grand,
            poDiscountAmount,
            subtotalAfterPODiscount,
            labor
        };
    }

    function toggleWithholdingTax() {
        const isChecked = document.getElementById('apply-withholding-tax').checked;
        const taxDetails = document.getElementById('tax-details');
        taxDetails.style.display = isChecked ? 'block' : 'none';
        const totals = calcTotals();
        updateWithholdingTax(totals.subtotalAfterPODiscount, totals.grand);
    }

    function updateWithholdingTax(taxBase, grandTotal) {
        const isChecked = document.getElementById('apply-withholding-tax').checked;
        const taxBaseSpan = document.getElementById('tax-base-amount');
        const withholdingSpan = document.getElementById('withholding-tax-amount');
        const netAmountSpan = document.getElementById('net-amount-due');
        
        if (taxBaseSpan) taxBaseSpan.textContent = formatPHP(taxBase);
        
        if (isChecked) {
            const withholdingTax = taxBase * 0.05;
            const netAmount = grandTotal - withholdingTax;
            if (withholdingSpan) withholdingSpan.textContent = formatPHP(withholdingTax);
            if (netAmountSpan) netAmountSpan.textContent = formatPHP(netAmount);
        } else {
            if (withholdingSpan) withholdingSpan.textContent = formatPHP(0);
            if (netAmountSpan) netAmountSpan.textContent = formatPHP(grandTotal);
        }
    }

    // ── Vehicle selection helpers (still used by Trailers & Prime Movers) ─────
    function selectMotorpoolCategory(category) {
        // Kept for compatibility but never called when purchase type is For Motorpool
        selectedMotorpoolCategory = category;
    }

    function updatePlateFieldMode() {
        const plateSelect = document.getElementById('vehicle-plate-select');
        const plateInput  = document.getElementById('vehicle-plate');
        const plateLabel  = document.getElementById('vehicle-plate-label');
        const brandGroup  = document.getElementById('vehicle-brand-group');
        const modelGroup  = document.getElementById('vehicle-model-group');

        const info = getCurrentVehicleDataset();
        const isTrailer = info && info.label === 'Trailer';

        if (isPlateDropdown()) {
            plateLabel.textContent = 'Plate Number';
            plateSelect.style.display = 'block';
            plateInput.style.display  = 'none';
        } else {
            plateLabel.textContent = 'Plate Number';
            plateSelect.style.display = 'none';
            plateInput.style.display  = 'block';
        }

        if (isTrailer) {
            brandGroup.style.display = 'none';
            modelGroup.style.display = 'none';
        } else if (info) {
            brandGroup.style.display = 'block';
            modelGroup.style.display = 'block';
        }
    }

    function populatePlateNumberDropdown() {
        const plateSelect = document.getElementById('vehicle-plate-select');
        const info = getCurrentVehicleDataset();
        if (!info) return;
        
        plateSelect.innerHTML = '<option value="">-- Select Plate Number --</option>';
        info.data.forEach(item => {
            const plate = item.plate_number || '';
            const code = item[info.codeField] || '';
            if (plate) {
                const displayText = `${plate} — ${code}`;
                plateSelect.innerHTML += `<option value="${plate}" data-code="${code}">${displayText}</option>`;
            }
        });
    }

    function populateVehicleDropdown(vehicleType) {
        const vehicleSelect = document.getElementById('vehicle-code');
        vehicleSelect.innerHTML = '<option value="">-- Select --</option>';
        
        let data = [], codeField = '', brandField = '', modelField = '';
        
        switch(vehicleType) {
            case 'truck':
                data = getRegularTrucks();
                codeField = 'truck_code'; brandField = 'brand'; modelField = 'model';
                break;
            case 'trailer':
                data = trailersData;
                codeField = 'trailer_code'; brandField = 'manufacturer'; modelField = 'model';
                break;
            case 'prime_mover':
                data = getPrimeMoverTrucks();
                codeField = 'truck_code'; brandField = 'brand'; modelField = 'model';
                break;
            default:
                return;
        }
        
        data.forEach(item => {
            const label = item[codeField] || '';
            vehicleSelect.innerHTML += `<option value="${label}" data-brand="${item[brandField] || ''}" data-model="${item[modelField] || ''}" data-plate="${item.plate_number || ''}">${label}</option>`;
        });

        populatePlateNumberDropdown();
    }

    function onVehicleCodeChange(vehicleCode) {
        if (isVehicleSyncing) return;
        isVehicleSyncing = true;

        const info = getCurrentVehicleDataset();
        if (!info) { isVehicleSyncing = false; return; }

        const selectedVehicle = info.data.find(item => item[info.codeField] === vehicleCode);
        const brandInput = document.getElementById('vehicle-brand');
        const modelInput = document.getElementById('vehicle-model');
        const plateInput = document.getElementById('vehicle-plate');
        const plateSelect = document.getElementById('vehicle-plate-select');
        const infoDisplay = document.getElementById('vehicle-info-display');

        if (selectedVehicle) {
            brandInput.value = selectedVehicle[info.brandField] || '';
            modelInput.value = selectedVehicle[info.modelField] || '';
            plateInput.value = selectedVehicle.plate_number || '';

            if (isPlateDropdown()) {
                const targetPlate = selectedVehicle.plate_number || '';
                if (plateSelect.value !== targetPlate) {
                    const opt = Array.from(plateSelect.options).find(o => o.value === targetPlate);
                    plateSelect.value = opt ? targetPlate : '';
                }
            }

            renderVehicleInfo(selectedVehicle, info);
        } else {
            brandInput.value = '';
            modelInput.value = '';
            plateInput.value = '';
            if (isPlateDropdown()) plateSelect.value = '';
            infoDisplay.style.display = 'none';
        }

        isVehicleSyncing = false;
    }

    function onVehiclePlateChange(plateValue) {
        if (isVehicleSyncing) return;
        isVehicleSyncing = true;

        const info = getCurrentVehicleDataset();
        if (!info) { isVehicleSyncing = false; return; }

        const selectedVehicle = info.data.find(item => (item.plate_number || '') === plateValue);
        const codeSelect = document.getElementById('vehicle-code');
        const brandInput = document.getElementById('vehicle-brand');
        const modelInput = document.getElementById('vehicle-model');
        const plateInput = document.getElementById('vehicle-plate');
        const infoDisplay = document.getElementById('vehicle-info-display');

        if (selectedVehicle) {
            const targetCode = selectedVehicle[info.codeField] || '';
            if (codeSelect.value !== targetCode) codeSelect.value = targetCode;
            brandInput.value = selectedVehicle[info.brandField] || '';
            modelInput.value = selectedVehicle[info.modelField] || '';
            plateInput.value = selectedVehicle.plate_number || '';
            renderVehicleInfo(selectedVehicle, info);
        } else {
            codeSelect.value = '';
            brandInput.value = '';
            modelInput.value = '';
            plateInput.value = '';
            infoDisplay.style.display = 'none';
        }

        isVehicleSyncing = false;
    }

    function renderVehicleInfo(selectedVehicle, info) {
        const infoDisplay = document.getElementById('vehicle-info-display');
        
        infoDisplay.style.display = 'block';
        let infoHTML = `<strong>Selected ${info.label}:</strong> ${selectedVehicle[info.codeField]}<br>`;

        if (info.label === 'Trailer') {
            if (selectedVehicle.plate_number) infoHTML += `<strong>Plate Number:</strong> ${selectedVehicle.plate_number}`;
            if (selectedVehicle.trailer_type) infoHTML += `<br><strong>Trailer Type:</strong> ${selectedVehicle.trailer_type}`;
        } else {
            const brandLabel = (info.brandField === 'manufacturer' ? 'Manufacturer' : 'Brand');
            infoHTML += `<strong>${brandLabel}:</strong> ${selectedVehicle[info.brandField] || 'N/A'}`;
            if (selectedVehicle[info.modelField]) infoHTML += `<br><strong>Model:</strong> ${selectedVehicle[info.modelField]}`;
            if (selectedVehicle.plate_number) infoHTML += `<br><strong>Plate Number:</strong> ${selectedVehicle.plate_number}`;
        }

        infoDisplay.innerHTML = infoHTML;
    }

    // ── Purchase Type Change ──────────────────────────────────────────────────
    function onPurchaseTypeChange(purchaseType) {
        const vehicleSection = document.getElementById('vehicle-section');
        const sectionTitle = document.getElementById('vehicle-section-title');
        const codeLabel = document.getElementById('vehicle-code-label');
        const vehicleSelect = document.getElementById('vehicle-code');
        const brandInput = document.getElementById('vehicle-brand');
        const modelInput = document.getElementById('vehicle-model');
        const plateInput = document.getElementById('vehicle-plate');
        const plateSelect = document.getElementById('vehicle-plate-select');
        const infoDisplay = document.getElementById('vehicle-info-display');
        const motorpoolContainer = document.getElementById('motorpool-category-container');
        const brandGroup = document.getElementById('vehicle-brand-group');
        const modelGroup = document.getElementById('vehicle-model-group');
        const plateGroup = document.getElementById('vehicle-plate-group');
        const singleVehicleGrid = document.getElementById('single-vehicle-grid');
        
        // Reset vehicle fields
        vehicleSelect.value = '';
        brandInput.value = '';
        modelInput.value = '';
        plateInput.value = '';
        plateSelect.value = '';
        infoDisplay.style.display = 'none';
        
        isTruckRepairMode = false;
        
        const vehicleTypes = ['For Trailers', 'For Prime Movers'];
        
        if (purchaseType === 'For Truck Repair and Maintenance') {
            // No top-level Truck Information section. Trucks are chosen per item row.
            isTruckRepairMode = true;
            vehicleSection.style.display = 'none';
            motorpoolContainer.style.display = 'none';
            if (singleVehicleGrid) singleVehicleGrid.style.display = 'grid';
            vehicleSelect.required = false;
            
        } else if (vehicleTypes.includes(purchaseType)) {
            // SINGLE VEHICLE MODE (Trailer / Prime Mover)
            vehicleSection.style.display = 'block';
            motorpoolContainer.style.display = 'none';
            if (singleVehicleGrid) singleVehicleGrid.style.display = 'grid';
            
            let icon, label, badgeClass, badgeText, codeFieldLabel;
            
            if (purchaseType === 'For Trailers') {
                icon = 'box'; label = 'Trailer Information';
                badgeClass = 'trailer'; badgeText = 'Trailer';
                codeFieldLabel = 'Trailer Code';
                populateVehicleDropdown('trailer');
                brandGroup.style.display = 'none';
                modelGroup.style.display = 'none';
                plateGroup.style.display = 'block';
            } else {
                icon = 'truck'; label = 'Prime Mover Information';
                badgeClass = 'prime-mover'; badgeText = 'Prime Mover';
                codeFieldLabel = 'Prime Mover Code';
                populateVehicleDropdown('prime_mover');
                brandGroup.style.display = 'block';
                modelGroup.style.display = 'block';
                plateGroup.style.display = 'block';
            }
            
            sectionTitle.innerHTML = `
                <i data-lucide="${icon}" style="width:16px;height:16px;"></i>
                <span id="vehicle-section-label">${label}</span>
                <span class="vehicle-badge ${badgeClass}">${badgeText}</span>
            `;
            codeLabel.textContent = codeFieldLabel + ' *';
            vehicleSelect.required = true;
            selectedMotorpoolCategory = null;
            updatePlateFieldMode();
            lucide.createIcons();
            
        } else {
            // For Office Supplies, For Stock, For Motorpool, or empty → hide everything
            vehicleSection.style.display = 'none';
            motorpoolContainer.style.display = 'none';
            vehicleSelect.required = false;
            selectedMotorpoolCategory = null;
        }
        
        refreshTruckAssignmentColumn();
    }

    // ── Collect Form Data ──────────────────────────────────────────────────────
    function collectFormData() {
        const totals = calcTotals();
        
        const supplierCode = document.getElementById('supplier-code-select').value;
        const purchaseType = document.getElementById('purchase-type').value;
        
        let supplierName = '';
        const selectedSupplier = suppliersData.find(s => s.supplier_code === supplierCode);
        if (selectedSupplier) supplierName = selectedSupplier.supplier_name;
        
        const applyWithholdingTax = document.getElementById('apply-withholding-tax').checked;
        let withholdingTaxPercent = 0;
        let withholdingTaxAmount = 0;
        let netAmountDue = totals.grand;
        
        if (applyWithholdingTax) {
            const withholdingBase = totals.subtotalAfterPODiscount;
            withholdingTaxPercent = 5;
            withholdingTaxAmount = withholdingBase * 0.05;
            netAmountDue = totals.grand - withholdingTaxAmount;
        }
        
        const withVAT = document.getElementById('vat-toggle').checked;
        const discountPercent = parseFloat(document.getElementById('po-discount-percent').value) || 0;
        const discountAmount = parseFloat(document.getElementById('po-discount-amount').value) || 0;
        
        let vehicleCode = null, vehicleBrand = null, vehicleModel = null;
        
        if (purchaseType === 'For Truck Repair and Maintenance') {
            // Multi-item + multi-truck: header-level vehicle fields stay null
            vehicleCode = null;
            vehicleBrand = null;
            vehicleModel = null;
        } else if (purchaseType === 'For Trailers' || purchaseType === 'For Prime Movers') {
            vehicleCode = document.getElementById('vehicle-code').value || null;
            vehicleBrand = document.getElementById('vehicle-brand').value || null;
            vehicleModel = document.getElementById('vehicle-model').value || null;
        }
        // For Motorpool / Office Supplies / Stock → all vehicle fields remain null
        
        const poData = {
            supplier_code: supplierCode,
            supplier_name: supplierName,
            purchase_type: purchaseType,
            po_date: document.getElementById('po-date').value,
            expected_delivery: document.getElementById('expected-delivery').value,
            payment_terms: document.getElementById('payment-terms').value,
            currency: document.getElementById('currency').value,
            delivery_address: document.getElementById('delivery-address').value,
            delivery_mode: document.getElementById('delivery-mode').value,
            warranty: document.getElementById('warranty').value,
            remarks: document.getElementById('remarks').value,
            freight: parseFloat(document.getElementById('freight').value) || 0,
            labor: parseFloat(document.getElementById('labor').value) || 0,
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
            items: []
        };
        
        document.querySelectorAll('#items-body tr').forEach(row => {
            const hidden = row.querySelector('.item-code-hidden');
            const code = hidden ? hidden.value : '';
            if (!code) return;

            const item = allItemsData.find(i => i.item_code === code);
            if (!item) return;

            const qty = parseInt(row.querySelector('.qty-input').value) || 0;
            const unitCost = parseFloat(row.querySelector('.price-input').value) || 0;
            const discountPercentRow = parseFloat(row.querySelector('.discount-percent-input').value) || 0;
            const discountAmountRow = parseFloat(row.querySelector('.discount-amount-input').value) || 0;
            const partNumber = row.dataset.partNumber || '';

            let itemSubtotal = qty * unitCost;
            let finalDiscount = discountAmountRow;

            if (discountPercentRow > 0) {
                finalDiscount = itemSubtotal * (discountPercentRow / 100);
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

            // Per-item truck code for Truck Repair mode
            let itemTruckCode = null;
            if (purchaseType === 'For Truck Repair and Maintenance') {
                const truckSelect = row.querySelector('.truck-assign-select');
                itemTruckCode = truckSelect ? truckSelect.value : null;
            }

            // Per-item requested_by
            const requestedBySelect = row.querySelector('.requested-by-select');
            const requestedBy = requestedBySelect ? requestedBySelect.value : '';

            poData.items.push({
                item_code: item.item_code,
                item_name: item.item_name,
                part_number: partNumber,
                unit_of_measure: item.unit_of_measure || row.querySelector('.unit-input').value,
                quantity: qty,
                unit_cost: unitCost,
                discount: finalDiscount,
                discount_amount: finalDiscount,
                without_vat: withoutVAT,
                vat_amount: vatAmount,
                total_cost: discountedTotal,
                truck_code: itemTruckCode,
                requested_by: requestedBy
            });
        });
        
        return poData;
    }

    // ── Submit for Approval ──────────────────────────────────────────────────
    async function submitForApproval() {
        const supplierCode = document.getElementById('supplier-code-select').value;
        const purchaseType = document.getElementById('purchase-type').value;
        
        if (!supplierCode) { alert('Please select a supplier'); return; }
        if (!purchaseType) { alert('Please select a purchase type'); return; }
        
        // Collect rows that have an item selected
        const itemRows = [];
        document.querySelectorAll('#items-body tr').forEach((row, idx) => {
            const hidden = row.querySelector('.item-code-hidden');
            if (hidden && hidden.value) {
                itemRows.push({ row, idx: idx + 1 });
            }
        });
        
        if (itemRows.length === 0) {
            alert('Please add at least one item to the purchase order');
            return;
        }
        
        // Validate Requested By on every item row
        const missingRequester = [];
        itemRows.forEach(({ row, idx }) => {
            const sel = row.querySelector('.requested-by-select');
            if (!sel || !sel.value) missingRequester.push(idx);
        });
        if (missingRequester.length > 0) {
            alert(`Please select Requested By for the following item row(s): ${missingRequester.join(', ')}`);
            return;
        }
        
        if (purchaseType === 'For Truck Repair and Maintenance') {
            // Validate every item row has a truck assigned
            const unassignedRows = [];
            itemRows.forEach(({ row, idx }) => {
                const truckSelect = row.querySelector('.truck-assign-select');
                if (truckSelect && !truckSelect.value) {
                    unassignedRows.push(idx);
                }
            });
            
            if (unassignedRows.length > 0) {
                alert(`Please assign a truck to the following item row(s): ${unassignedRows.join(', ')}`);
                return;
            }
            
        } else if (purchaseType === 'For Trailers' || purchaseType === 'For Prime Movers') {
            const vehicleCode = document.getElementById('vehicle-code').value;
            if (!vehicleCode) {
                const typeLabel = purchaseType === 'For Trailers' ? 'trailer' : 'prime mover';
                alert(`Please select a ${typeLabel} for this purchase order`);
                return;
            }
        }
        // For Motorpool – no vehicle validation required
        
        if (!confirm('Are you sure you want to submit this Purchase Order?')) return;
        
        loadingModal.style.display = 'flex';
        
        try {
            const poData = collectFormData();
            
            const response = await fetch('purchase_order.php?action=save_po', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
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
    Promise.all([
        loadSuppliers(),
        loadAllItems(),
        loadTrucks(),
        loadTrailers(),
        loadCustomers(),
        loadEmployees()
    ]).then(() => {
        // Employees are loaded; populate any existing requested-by selects
        refreshRequestedByColumns();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal?.style.display === 'flex') {
            closeModal();
        }
    });
</script>
</body>
</html>