<?php
// sales_order.php
session_start();
// Set timezone to match your location
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name  = $_SESSION['full_name'] ?? $username;

$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);

$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

$role_display_name = getRoleDisplayName($user_roles);

$today  = date('Y');

// Get the last SO number for the current year to generate the next preview
$preview_so = 'SO-' . $today . '-00001'; // fallback default

$so_query = "SELECT sales_order_no FROM sales_order 
             WHERE sales_order_no LIKE 'SO-$today-%' 
             ORDER BY id DESC LIMIT 1";
$so_result = $conn->query($so_query);

if ($so_result && $so_result->num_rows > 0) {
    $last_so = $so_result->fetch_assoc()['sales_order_no'];
    
    // Extract the numeric suffix (last segment after the final dash)
    $parts = explode('-', $last_so);
    $last_number = (int) end($parts);
    $next_number = $last_number + 1;
    
    $preview_so = 'SO-' . $today . '-' . str_pad($next_number, 5, '0', STR_PAD_LEFT);
}

$order_date_val    = date('Y-m-d');
$delivery_date_val = date('Y-m-d');

// Fetch all customers for dropdown (include with_special_process)
$customers_query = "SELECT customer_code, full_name, contact_person, full_address, with_special_process FROM customer_masterlist ORDER BY full_name";
$customers_result = $conn->query($customers_query);
$customers = [];
if ($customers_result && $customers_result->num_rows > 0) {
    while ($row = $customers_result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Fetch all SOs for dropdown
$so_list = [];
$so_list_query = "SELECT sales_order_no, customer_name FROM sales_order ORDER BY created_date DESC";
$so_list_result = $conn->query($so_list_query);
if ($so_list_result && $so_list_result->num_rows > 0) {
    while ($row = $so_list_result->fetch_assoc()) {
        $so_list[] = $row;
    }
}

// Fetch all drivers
$drivers_query = "SELECT employee_code, full_name, profile_picture FROM employee_list WHERE position = 'DRIVER' AND status = 'active' ORDER BY full_name";
$drivers_result = $conn->query($drivers_query);
$drivers = [];
if ($drivers_result && $drivers_result->num_rows > 0) {
    while ($row = $drivers_result->fetch_assoc()) {
        $drivers[] = $row;
    }
}

// Fetch special charge kinds
$charge_kinds = [];
$charge_kinds_query = "SELECT DISTINCT charge_kind FROM special_charge_kinds ORDER BY charge_kind";
$charge_kinds_result = @$conn->query($charge_kinds_query);
if ($charge_kinds_result && $charge_kinds_result->num_rows > 0) {
    while ($row = $charge_kinds_result->fetch_assoc()) {
        $charge_kinds[] = $row['charge_kind'];
    }
}
if (empty($charge_kinds)) {
    $charge_kinds = ['Fuel Subsidy', 'Allowance', 'Toll Fee', 'Parking Fee', 'Waiting Time', 'Extra Labor', 'Other'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Order | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="stylesheet" href="css/sales_order.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <style>
        .driver-image-preview {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.55);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(2px);
        }
        .driver-image-preview.active { display: flex; }
        .driver-image-preview-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            max-width: 340px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            position: relative;
            text-align: center;
            animation: driverPreviewPop 0.22s ease-out;
        }
        @keyframes driverPreviewPop {
            from { transform: scale(0.9); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }
        .driver-image-preview-close {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: #f1f5f9;
            color: #475569;
            font-size: 20px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .driver-image-preview-close:hover {
            background: #dc2626;
            color: #ffffff;
            transform: rotate(90deg);
        }
        .driver-image-preview-card img {
            width: 220px;
            height: 220px;
            object-fit: cover;
            border-radius: 12px;
            border: 3px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            display: block;
            margin: 8px auto 14px;
        }
        .driver-image-preview-name {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .driver-image-preview-code {
            font-size: 12px;
            color: #64748b;
            letter-spacing: 0.5px;
        }
        .driver-image-preview-placeholder {
            width: 220px;
            height: 220px;
            margin: 8px auto 14px;
            border-radius: 12px;
            border: 3px dashed #cbd5e1;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            gap: 8px;
        }
        .driver-image-preview-placeholder i { width: 56px; height: 56px; }
        .driver-image-preview-placeholder span { font-size: 12px; font-weight: 500; }
        .driver-image-preview-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .driver-field-wrap {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }
        .driver-field-wrap select { flex: 1; min-width: 0; }
        .view-driver-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 0 12px;
            height: 40px;
            border-radius: 8px;
            border: 1.5px solid var(--accent-blue, #2563eb);
            background: #eff6ff;
            color: var(--accent-blue, #2563eb);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .view-driver-btn:hover:not(:disabled) {
            background: var(--accent-blue, #2563eb);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }
        .view-driver-btn:active:not(:disabled) { transform: translateY(0); }
        .view-driver-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #94a3b8;
        }
        .view-driver-btn i { width: 14px; height: 14px; }

        /* Special Process badge */
        .special-process-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .special-process-badge.none {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        .special-process-badge.has {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fcd34d;
        }
        .special-process-badge i { width: 14px; height: 14px; }

        /* Special charges card highlight */
        #specialChargesCard {
            border-left: 4px solid #f59e0b;
        }
        .charge-kind-select, .charge-amount-input {
            font-family: inherit;
        }
    </style>
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

<!-- Driver Profile Picture Preview Modal -->
<div class="driver-image-preview" id="driverImagePreview">
    <div class="driver-image-preview-card">
        <button type="button" class="driver-image-preview-close" id="closeDriverImagePreview" title="Close">×</button>
        <div class="driver-image-preview-label">Driver Profile</div>
        <div id="driverImagePreviewContent"></div>
        <div class="driver-image-preview-name" id="driverImagePreviewName"></div>
        <div class="driver-image-preview-code" id="driverImagePreviewCode"></div>
    </div>
</div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Sales Order</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?= htmlspecialchars($full_name) ?></span>
            <span style="margin-left:10px;color:var(--text-muted);"><?= htmlspecialchars($username) ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <h1>
                    <span class="icon-wrap"><i data-lucide="file-plus" style="width:18px;height:18px;"></i></span>
                    New Sales Order
                </h1>
                <div style="display:flex;align-items:center;gap:8px;border: 2px solid #c5c5c5;padding: 6px; background: #00670c; border-radius: 8px;">
                    <a href="sales_order_all.php" style="font-size: 14px; color: white; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="list" style="width:16px;height:16px;"></i>
                        View Existing Sales Orders
                    </a>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="so-tag"><?= htmlspecialchars($preview_so) ?></span>
                <span class="status-draft">Draft</span>
            </div>
        </div>

        <!-- Order Information -->
        <div class="form-card">
            <div class="form-card-title"><i data-lucide="info" style="width:16px;height:16px;"></i> Order Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Customer <span class="required">*</span></label>
                    <select id="customerSelect" required>
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= htmlspecialchars($customer['customer_code']) ?>" 
                                    data-full-name="<?= htmlspecialchars($customer['full_name']) ?>"
                                    data-contact-person="<?= htmlspecialchars($customer['contact_person']) ?>"
                                    data-full-address="<?= htmlspecialchars($customer['full_address']) ?>"
                                    data-special-process="<?= (int)($customer['with_special_process'] ?? 0) ?>">
                                <?= htmlspecialchars($customer['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Customer Code</label>
                    <input type="text" id="customerCode" readonly value="">
                </div>
                <div class="form-group">
                    <label>Order Date</label>
                    <input type="date" id="orderDate" value="<?= $order_date_val ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Delivery Date</label>
                    <input type="date" id="deliveryDate" value="<?= $delivery_date_val ?>" readonly>
                </div>

                <!-- Special Process Display -->
                <div class="form-group">
                    <label>Special Process</label>
                    <div style="padding-top:6px;">
                        <span id="specialProcessBadge" class="special-process-badge none">
                            <i data-lucide="minus-circle"></i> None
                        </span>
                    </div>
                </div>
                <div class="form-group"></div>

                <div class="form-group">
                    <label>Plate Number</label>
                    <select id="plateNumberSelect" disabled>
                        <option value="">-- Select Plate Number --</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Truck Code</label>
                    <select id="truckCodeSelect" disabled>
                        <option value="">-- Select Truck Code --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assigned Truck</label>
                    <select id="assignedTruckSelect" disabled>
                        <option value="">-- Select Assigned Truck --</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Truck Type</label>
                    <input type="text" id="truckType" readonly placeholder="Truck type will appear here">
                </div>
                
                <div class="form-group">
                    <label>Zone</label>
                    <select id="pricingZoneSelect" disabled>
                        <option value="">-- Select truck first --</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Payment Terms</label>
                    <input type="text" id="paymentTerms" readonly placeholder="Select pricing first">
                </div>

                <div class="form-group">
                    <label>Driver <span class="required">*</span></label>
                    <div class="driver-field-wrap">
                        <select id="driverSelect" required disabled>
                            <option value="">-- Select Driver --</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= htmlspecialchars($driver['employee_code']) ?>" 
                                        data-full-name="<?= htmlspecialchars($driver['full_name']) ?>"
                                        data-profile-picture="<?= htmlspecialchars($driver['profile_picture'] ?? '') ?>">
                                    <?= htmlspecialchars($driver['employee_code']) . ' - ' . htmlspecialchars($driver['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="view-driver-btn" id="viewDriverBtn" disabled title="View driver's profile picture">
                            <i data-lucide="image"></i> View Driver
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label>Driver Name</label>
                    <input type="text" id="driverName" readonly placeholder="Driver name will appear here">
                </div>

                <div class="form-group">
                    <label>Sales Rep</label>
                    <input type="text" id="salesRep" readonly placeholder="Auto-filled">
                </div>
                
                <div class="form-group">
                    <label>Destination From</label>
                    <input type="text" id="destinationFrom" readonly>
                </div>
                
                <div class="form-group">
                    <label>Destination To</label>
                    <input type="text" id="destinationTo" readonly>
                </div>
                
                <div class="form-group full-width">
                    <label>Delivery Address</label>
                    <textarea id="deliveryAddress" readonly placeholder="Full delivery address..."></textarea>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <div class="form-card">
            <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="package" style="width:16px;height:16px;"></i> Line Items</div>
            <div class="table-wrapper">
                <table id="lineItemsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item Description</th>
                            <th>Unit</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Discount (%)</th>
                            <th>Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="lineItemsBody">
                        <tr class="line-item-row">
                            <td>1</td>
                            <td>
                                <input type="text" class="item-description" style="width:100%;" placeholder="-- Select a truck --" readonly>
                             </td>
                            <td><input type="text" class="unit-input" value="TRIP" style="width:70px;text-align:center;"></td>
                            <td><input type="number" class="qty-input" value="1" min="1" style="width:70px;text-align:center;"></td>
                            <td><input type="number" class="price-input" value="0.00" step="0.01" style="width:110px;text-align:right;" readonly></td>
                            <td><input type="number" class="discount-input" value="0" min="0" max="100" style="width:80px;text-align:center;"></td>
                            <td class="amount-display" style="font-weight:600;text-align:right;">₱0.00</td>
                            <td><button type="button" class="remove-row-btn" disabled><i data-lucide="trash-2" style="width:16px;height:16px; color: black;"></i></button></td>
                         </tr>
                    </tbody>
                 </table>
            </div>
            <div style="margin-top: 16px;">
                <button type="button" id="addRowBtn" class="btn-secondary" disabled>
                    <i data-lucide="plus-circle"></i> Add Item
                </button>
            </div>
        </div>

        <!-- Special Charges (only visible when customer has special process) -->
        <div class="form-card" id="specialChargesCard" style="display:none;">
            <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black; display:flex; align-items:center; gap:8px;">
                <i data-lucide="receipt" style="width:16px;height:16px;"></i> Special Charges
                <span style="font-size:11px;font-weight:600;color:#b45309;background:#fef3c7;padding:3px 10px;border-radius:12px;margin-left:6px;">
                    This customer requires special charges
                </span>
            </div>
            <div class="table-wrapper">
                <table id="specialChargesTable">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th style="min-width:200px;">Charge Kind</th>
                            <th style="width:180px;">Amount</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="specialChargesBody">
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 16px;">
                <button type="button" id="addSpecialChargeBtn" class="btn-secondary">
                    <i data-lucide="plus-circle"></i> Add Special Charge
                </button>
            </div>
        </div>

        <!-- Notes + Totals -->
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="form-card" style="flex:1;min-width:260px;">
                <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Notes & Instructions</div>
                <textarea id="notes" placeholder="Add internal notes or special delivery instructions..." style="width:100%;min-height:100px;" disabled></textarea>
            </div>

            <div class="totals-box">
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">Subtotal</span><span id="subtotal">₱0.00</span></div>
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">Discount</span><span id="totalDiscount">−₱0.00</span></div>
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">VAT (12%)</span><span id="vatAmount">₱0.00</span></div>
                <div class="totals-row" id="specialChargesTotalRow" style="display:none; background:#fffbeb; border-radius:6px; padding-left:8px; padding-right:8px;">
                    <span style="font-size: 15px; font-weight: 800; color: #b45309;">Special Charges</span>
                    <span id="specialChargesTotal" style="color:#b45309; font-weight:700;">₱0.00</span>
                </div>
                <div class="totals-row total-final"><span>Total Due</span><span id="totalDue">₱0.00</span></div>
            </div>
        </div>

        <div class="actions-row" style="margin-top:32px;">
            <a href="sales_order.php" class="btn-secondary"><i data-lucide="x"></i> Cancel</a>
            <button class="btn-secondary" id="printBtn" disabled><i data-lucide="printer"></i> Print SO</button>
            <a href="sales_order.php" class="btn-secondary"><i data-lucide="list-plus"></i> Create New</a>
            <button class="btn-primary" id="saveBtn" disabled><i data-lucide="save"></i> Save</button>
        </div>
    </div>
</main>

<script>
lucide.createIcons();

const userRoles = <?php echo json_encode($user_roles); ?>;
const allowedPages = <?php echo json_encode($allowed_pages); ?>;
const chargeKinds = <?php echo json_encode($charge_kinds); ?>;

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

modal.addEventListener('click', function(e) {
    if (e.target === modal) closeModal();
});

// ============================================================
// DRIVER PROFILE PICTURE PREVIEW
// ============================================================
const driverImagePreview = document.getElementById('driverImagePreview');
const driverImagePreviewContent = document.getElementById('driverImagePreviewContent');
const driverImagePreviewName = document.getElementById('driverImagePreviewName');
const driverImagePreviewCode = document.getElementById('driverImagePreviewCode');
const closeDriverImagePreviewBtn = document.getElementById('closeDriverImagePreview');
const viewDriverBtn = document.getElementById('viewDriverBtn');

function openDriverImagePreview(driverCode, driverName, profilePicture) {
    driverImagePreviewName.textContent = driverName || 'Unknown Driver';
    driverImagePreviewCode.textContent = driverCode || '';

    if (profilePicture) {
        driverImagePreviewContent.innerHTML = `
            <img src="../uploads/employee_images/${profilePicture}" alt="${driverName}">
        `;
    } else {
        driverImagePreviewContent.innerHTML = `
            <div class="driver-image-preview-placeholder">
                <i data-lucide="user"></i>
                <span>No profile picture uploaded</span>
            </div>
        `;
    }

    driverImagePreview.classList.add('active');
    lucide.createIcons();
}

function closeDriverImagePreview() {
    driverImagePreview.classList.remove('active');
    setTimeout(() => {
        if (!driverImagePreview.classList.contains('active')) {
            driverImagePreviewContent.innerHTML = '';
        }
    }, 200);
}

function viewSelectedDriver() {
    const driverSelect = document.getElementById('driverSelect');
    const selectedOption = driverSelect.selectedOptions[0];
    const driverCode = driverSelect.value || '';
    if (!driverCode || !selectedOption) return;
    const driverName = selectedOption.dataset.fullName || '';
    const profilePicture = selectedOption.dataset.profilePicture || '';
    openDriverImagePreview(driverCode, driverName, profilePicture);
}

closeDriverImagePreviewBtn?.addEventListener('click', closeDriverImagePreview);
viewDriverBtn?.addEventListener('click', viewSelectedDriver);

driverImagePreview?.addEventListener('click', function(e) {
    if (e.target === driverImagePreview) closeDriverImagePreview();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (driverImagePreview.classList.contains('active')) {
            closeDriverImagePreview();
        } else if (modal.style.display === 'flex') {
            closeModal();
        }
    }
});

// Store pricing data globally
let pricingData = {};
let truckData = {};
let truckDataByPlate = {};

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function enablePriceEditing() {
    document.querySelectorAll('.price-input').forEach(input => {
        input.readOnly = false;
        input.disabled = false;
        input.style.backgroundColor = '#ffffff';
    });
}

function disablePriceEditing() {
    document.querySelectorAll('.price-input').forEach(input => {
        input.readOnly = true;
        input.disabled = true;
        input.style.backgroundColor = '#f5f5f5';
    });
}

async function fetchTruckCodes(customerCode) {
    if (!customerCode) {
        document.getElementById('truckCodeSelect').innerHTML = '<option value="">-- Select Truck Code --</option>';
        return;
    }
    try {
        const response = await fetch(`get_customer_pricing.php?customer_code=${customerCode}`);
        const data = await response.json();
        
        if (data.success) {
            pricingData = data.pricing;
            truckData = data.trucks;
            truckDataByPlate = data.trucks_by_plate || {};
            
            const truckCodeSelect = document.getElementById('truckCodeSelect');
            truckCodeSelect.innerHTML = '<option value="">-- Select Truck Code --</option>';
            truckCodeSelect.disabled = false;
            
            const uniqueTruckCodes = [...new Set(data.pricing.map(p => p.truck_code))];
            uniqueTruckCodes.forEach(truckCode => {
                const option = document.createElement('option');
                option.value = truckCode;
                option.textContent = truckCode;
                truckCodeSelect.appendChild(option);
            });

            const plateNumberSelect = document.getElementById('plateNumberSelect');
            plateNumberSelect.innerHTML = '<option value="">-- Select Plate Number --</option>';
            plateNumberSelect.disabled = false;
            
            const uniquePlateNumbers = [...new Set(data.pricing.map(p => p.plate_number))];
            uniquePlateNumbers.forEach(plateNumber => {
                const option = document.createElement('option');
                option.value = plateNumber;
                option.textContent = plateNumber;
                const truckDetails = truckDataByPlate[plateNumber];
                if (truckDetails) {
                    option.dataset.truckCode = truckDetails.truck_code;
                    option.dataset.brand = truckDetails.brand;
                    option.dataset.model = truckDetails.model;
                    option.dataset.truckType = truckDetails.truck_type || '';
                    option.dataset.truckId = truckDetails.id;
                }
                plateNumberSelect.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error fetching truck codes:', error);
    }
}

function findTruckByPlate(plateNumber) {
    if (!plateNumber) return null;
    return truckDataByPlate[plateNumber] || null;
}

function findTruckByCode(truckCode) {
    if (!truckCode) return null;
    const trucks = truckData[truckCode] || [];
    return trucks.length > 0 ? trucks[0] : null;
}

// Helper: build a pricing <option> element with all needed datasets
function buildPricingOption(pricing) {
    const option = document.createElement('option');

    const ratePerTrip   = pricing.rate_per_trip   !== null && pricing.rate_per_trip   !== undefined ? pricing.rate_per_trip   : '';
    const minimumCharge = pricing.minimum_charge  !== null && pricing.minimum_charge  !== undefined ? pricing.minimum_charge  : '';
    const effective     = pricing.effective_unit_price !== null && pricing.effective_unit_price !== undefined
                          ? pricing.effective_unit_price
                          : (ratePerTrip !== '' ? ratePerTrip : (minimumCharge !== '' ? minimumCharge : 0));

    option.value = JSON.stringify({
        zone_from: pricing.zone_from,
        zone_to: pricing.zone_to,
        rate_per_trip: ratePerTrip,
        minimum_charge: minimumCharge,
        effective_unit_price: effective,
        payment_terms: pricing.payment_terms
    });
    option.textContent = `${pricing.zone_from} - ${pricing.zone_to}`;

    option.dataset.zoneFrom         = pricing.zone_from ?? '';
    option.dataset.zoneTo           = pricing.zone_to ?? '';
    option.dataset.ratePerTrip      = ratePerTrip;
    option.dataset.minimumCharge    = minimumCharge;
    option.dataset.effectivePrice   = effective;
    option.dataset.paymentTerms     = pricing.payment_terms ?? '';

    return option;
}

function handlePlateNumberSelect() {
    const plateNumber = document.getElementById('plateNumberSelect').value;
    const truckCodeSelect = document.getElementById('truckCodeSelect');
    const assignedTruckSelect = document.getElementById('assignedTruckSelect');
    const pricingZoneSelect = document.getElementById('pricingZoneSelect');
    const truckTypeInput = document.getElementById('truckType');

    if (!plateNumber) {
        truckCodeSelect.value = '';
        assignedTruckSelect.innerHTML = '<option value="">-- Select Assigned Truck --</option>';
        assignedTruckSelect.disabled = true;
        pricingZoneSelect.innerHTML = '<option value="">-- Select truck first --</option>';
        pricingZoneSelect.disabled = true;
        truckTypeInput.value = '';
        document.querySelector('.item-description').value = '-- Select a truck --';
        disablePriceEditing();
        handlePricingSelect();
        return;
    }

    const truckDetails = findTruckByPlate(plateNumber);

    if (!truckDetails) {
        truckCodeSelect.value = '';
        assignedTruckSelect.innerHTML = '<option value="">-- No truck details --</option>';
        assignedTruckSelect.disabled = true;
        pricingZoneSelect.innerHTML = '<option value="">-- No pricing --</option>';
        pricingZoneSelect.disabled = true;
        truckTypeInput.value = '';
        document.querySelector('.item-description').value = '';
        disablePriceEditing();
        handlePricingSelect();
        return;
    }

    truckCodeSelect.value = truckDetails.truck_code;

    assignedTruckSelect.innerHTML = '<option value="">-- Select Assigned Truck --</option>';
    const assignedTruckOption = document.createElement('option');
    assignedTruckOption.value = truckDetails.truck_code;
    assignedTruckOption.textContent = `${truckDetails.brand} [${truckDetails.model}]`;
    assignedTruckOption.dataset.truckId = truckDetails.id;
    assignedTruckOption.dataset.brand = truckDetails.brand;
    assignedTruckOption.dataset.model = truckDetails.model;
    assignedTruckOption.dataset.truckType = truckDetails.truck_type || '';
    assignedTruckSelect.appendChild(assignedTruckOption);
    assignedTruckSelect.selectedIndex = 1;
    assignedTruckSelect.disabled = false;

    truckTypeInput.value = truckDetails.truck_type || '';
    document.querySelector('.item-description').value = `${plateNumber} - ${truckDetails.brand} - ${truckDetails.model} (${truckDetails.truck_type || 'N/A'})`;

    const pricingForTruck = pricingData.filter(p => p.truck_code === truckDetails.truck_code);

    pricingZoneSelect.innerHTML = '<option value="">-- Select Pricing --</option>';
    pricingZoneSelect.disabled = false;

    if (pricingForTruck.length > 0) {
        pricingForTruck.forEach(pricing => {
            pricingZoneSelect.appendChild(buildPricingOption(pricing));
        });
    } else {
        pricingZoneSelect.innerHTML = '<option value="">-- No pricing found --</option>';
    }

    if (pricingZoneSelect.options.length === 2) {
        pricingZoneSelect.selectedIndex = 1;
    }
    
    enablePriceEditing();
    handlePricingSelect();
}

function handleTruckCodeSelect() {
    const truckCode = document.getElementById('truckCodeSelect').value;
    const plateNumberSelect = document.getElementById('plateNumberSelect');
    const assignedTruckSelect = document.getElementById('assignedTruckSelect');
    const pricingZoneSelect = document.getElementById('pricingZoneSelect');
    const truckTypeInput = document.getElementById('truckType');

    if (!truckCode) {
        plateNumberSelect.value = '';
        assignedTruckSelect.innerHTML = '<option value="">-- Select Assigned Truck --</option>';
        assignedTruckSelect.disabled = true;
        pricingZoneSelect.innerHTML = '<option value="">-- Select truck first --</option>';
        pricingZoneSelect.disabled = true;
        truckTypeInput.value = '';
        document.querySelector('.item-description').value = '-- Select a truck --';
        disablePriceEditing();
        handlePricingSelect();
        return;
    }

    const truckDetails = findTruckByCode(truckCode);

    if (!truckDetails) {
        plateNumberSelect.value = '';
        assignedTruckSelect.innerHTML = '<option value="">-- No truck details --</option>';
        assignedTruckSelect.disabled = true;
        pricingZoneSelect.innerHTML = '<option value="">-- No pricing --</option>';
        pricingZoneSelect.disabled = true;
        truckTypeInput.value = '';
        document.querySelector('.item-description').value = '';
        disablePriceEditing();
        handlePricingSelect();
        return;
    }

    plateNumberSelect.value = truckDetails.plate_number;

    assignedTruckSelect.innerHTML = '<option value="">-- Select Assigned Truck --</option>';
    const assignedTruckOption = document.createElement('option');
    assignedTruckOption.value = truckDetails.truck_code;
    assignedTruckOption.textContent = `${truckDetails.brand} [${truckDetails.model}]`;
    assignedTruckOption.dataset.truckId = truckDetails.id;
    assignedTruckOption.dataset.brand = truckDetails.brand;
    assignedTruckOption.dataset.model = truckDetails.model;
    assignedTruckOption.dataset.truckType = truckDetails.truck_type || '';
    assignedTruckSelect.appendChild(assignedTruckOption);
    assignedTruckSelect.selectedIndex = 1;
    assignedTruckSelect.disabled = false;

    truckTypeInput.value = truckDetails.truck_type || '';
    document.querySelector('.item-description').value = `${truckDetails.plate_number} - ${truckDetails.brand} - ${truckDetails.model} (${truckDetails.truck_type || 'N/A'})`;

    const pricingForTruck = pricingData.filter(p => p.truck_code === truckCode);

    pricingZoneSelect.innerHTML = '<option value="">-- Select Pricing --</option>';
    pricingZoneSelect.disabled = false;

    if (pricingForTruck.length > 0) {
        pricingForTruck.forEach(pricing => {
            pricingZoneSelect.appendChild(buildPricingOption(pricing));
        });
    } else {
        pricingZoneSelect.innerHTML = '<option value="">-- No pricing found --</option>';
    }

    if (pricingZoneSelect.options.length === 2) {
        pricingZoneSelect.selectedIndex = 1;
    }
    
    enablePriceEditing();
    handlePricingSelect();
}

function handlePricingSelect() {
    const selectedOption = document.getElementById('pricingZoneSelect').selectedOptions[0];
    
    if (!selectedOption) {
        document.getElementById('destinationFrom').value = '';
        document.getElementById('destinationTo').value = '';
        document.getElementById('paymentTerms').value = 'Select pricing first';
        return;
    }
    
    document.getElementById('destinationFrom').value = selectedOption.dataset.zoneFrom || '';
    document.getElementById('destinationTo').value = selectedOption.dataset.zoneTo || '';
    document.getElementById('paymentTerms').value = selectedOption.dataset.paymentTerms || '';
    
    // Prefer rate_per_trip → fallback to minimum_charge → fallback to effective_unit_price → 0
    const ratePerTrip   = parseFloat(selectedOption.dataset.ratePerTrip);
    const minimumCharge = parseFloat(selectedOption.dataset.minimumCharge);
    const effective     = parseFloat(selectedOption.dataset.effectivePrice);
    
    let unitPrice = 0;
    if (!isNaN(ratePerTrip) && ratePerTrip > 0) {
        unitPrice = ratePerTrip;
    } else if (!isNaN(minimumCharge) && minimumCharge > 0) {
        unitPrice = minimumCharge;
    } else if (!isNaN(effective) && effective > 0) {
        unitPrice = effective;
    }
    
    document.querySelectorAll('.price-input').forEach(input => {
        input.value = unitPrice.toFixed(2);
        input.readOnly = false;
        input.disabled = false;
        input.style.backgroundColor = '#ffffff';
    });
    
    calculateTotals();
}

// ============================================================
// SPECIAL CHARGES FUNCTIONS
// ============================================================

function resetSpecialCharges() {
    document.getElementById('specialChargesBody').innerHTML = '';
    document.getElementById('specialChargesTotal').textContent = '₱0.00';
}

function addSpecialChargeRow(prefillKind = '', prefillAmount = '') {
    const tbody = document.getElementById('specialChargesBody');
    const rowCount = tbody.children.length;
    const newRow = document.createElement('tr');
    newRow.className = 'special-charge-row';
    
    let kindOptions = '<option value="">-- Select Charge Kind --</option>';
    chargeKinds.forEach(kind => {
        const selected = (kind === prefillKind) ? 'selected' : '';
        kindOptions += `<option value="${kind}" ${selected}>${kind}</option>`;
    });
    
    const amountValue = prefillAmount !== '' ? prefillAmount : '0.00';
    
    newRow.innerHTML = `
        <td style="text-align:center;font-weight:600;">${rowCount + 1}</td>
        <td>
            <select class="charge-kind-select" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;">
                ${kindOptions}
            </select>
        </td>
        <td>
            <input type="number" class="charge-amount-input" value="${amountValue}" step="0.01" min="0" 
                   style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;text-align:right;">
        </td>
        <td style="text-align:center;">
            <button type="button" class="remove-special-charge-btn" 
                    style="background:none;border:none;cursor:pointer;padding:6px;border-radius:6px;transition:background 0.15s;">
                <i data-lucide="trash-2" style="width:16px;height:16px;color:#dc2626;"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(newRow);
    
    newRow.querySelector('.charge-amount-input').addEventListener('input', calculateTotals);
    newRow.querySelector('.charge-kind-select').addEventListener('change', calculateTotals);
    
    newRow.querySelector('.remove-special-charge-btn').addEventListener('click', function() {
        newRow.remove();
        Array.from(tbody.children).forEach((row, index) => {
            row.children[0].textContent = index + 1;
        });
        calculateTotals();
        if (tbody.children.length === 0) {
            document.getElementById('specialChargesCard').style.display = 'none';
            document.getElementById('specialChargesTotalRow').style.display = 'none';
        }
    });
    
    lucide.createIcons();
    calculateTotals();
}

function updateSpecialProcessBadge(hasSpecialProcess) {
    const badge = document.getElementById('specialProcessBadge');
    if (hasSpecialProcess) {
        badge.className = 'special-process-badge has';
        badge.innerHTML = '<i data-lucide="alert-circle"></i> Yes — With Special Process';
    } else {
        badge.className = 'special-process-badge none';
        badge.innerHTML = '<i data-lucide="minus-circle"></i> None';
    }
    lucide.createIcons();
}

// ============================================================
// CALCULATE TOTALS (includes special charges)
// ============================================================
function calculateTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    
    document.querySelectorAll('.line-item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
        
        const amount = qty * price;
        const discountAmount = amount * (discount / 100);
        const lineTotal = amount - discountAmount;
        
        row.querySelector('.amount-display').textContent = formatCurrency(lineTotal);
        
        subtotal += amount;
        totalDiscount += discountAmount;
    });
    
    // Special charges total
    let specialChargesTotal = 0;
    document.querySelectorAll('.special-charge-row').forEach(row => {
        const amount = parseFloat(row.querySelector('.charge-amount-input').value) || 0;
        specialChargesTotal += amount;
    });
    
    const vat = (subtotal - totalDiscount) * 0.12;
    const totalDue = subtotal - totalDiscount + vat + specialChargesTotal;
    
    document.getElementById('subtotal').textContent = formatCurrency(subtotal);
    document.getElementById('totalDiscount').textContent = '−' + formatCurrency(totalDiscount);
    document.getElementById('vatAmount').textContent = formatCurrency(vat);
    document.getElementById('specialChargesTotal').textContent = formatCurrency(specialChargesTotal);
    document.getElementById('totalDue').textContent = formatCurrency(totalDue);
}

// ============================================================
// SAVE ORDER (includes special charges)
// ============================================================
async function saveOrder() {
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';

    try {
        const customerSelect = document.getElementById('customerSelect');
        const selectedCustomer = customerSelect.selectedOptions[0];
        const assignedTruckSelect = document.getElementById('assignedTruckSelect');
        const selectedTruck = assignedTruckSelect.selectedOptions[0];
        const driverSelect = document.getElementById('driverSelect');

        if (!driverSelect.value) {
            alert('Please select a driver for this order.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        if (!customerSelect.value) {
            alert('Please select a customer for this order.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        if (!document.getElementById('plateNumberSelect').value) {
            alert('Please select a truck (plate number) for this order.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        // Collect special charges
        const specialCharges = [];
        let specialChargeError = false;
        document.querySelectorAll('.special-charge-row').forEach(row => {
            const chargeKind = row.querySelector('.charge-kind-select').value;
            const chargeAmount = parseFloat(row.querySelector('.charge-amount-input').value) || 0;
            if (chargeKind && chargeAmount > 0) {
                specialCharges.push({
                    charge_kind: chargeKind,
                    charge_amount: chargeAmount
                });
            } else if (chargeKind && chargeAmount <= 0) {
                specialChargeError = true;
            }
        });
        
        if (specialChargeError) {
            alert('Please enter a valid amount (> 0) for all special charges.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        const firstRow = document.querySelector('.line-item-row');
        const unitPrice = parseFloat(firstRow.querySelector('.price-input').value) || 0;
        const qty = parseFloat(firstRow.querySelector('.qty-input').value) || 0;
        const discountPercent = parseFloat(firstRow.querySelector('.discount-input').value) || 0;
        const unit = firstRow.querySelector('.unit-input').value || 'TRIP';
        
        const rowAmount = (unitPrice * qty) - ((unitPrice * qty) * (discountPercent / 100));
        
        const discountText = document.getElementById('totalDiscount').textContent.replace(/[^\d.-]/g, '');
        const discountAmount = Math.abs(parseFloat(discountText) || 0);

        const payload = {
            customer_code: document.getElementById('customerCode').value || '',
            customer_name: selectedCustomer ? selectedCustomer.dataset.fullName : '',
            truck_code: document.getElementById('truckCodeSelect').value || '',
            plate_number: document.getElementById('plateNumberSelect').value || '',
            brand: selectedTruck ? selectedTruck.dataset.brand : '',
            model: selectedTruck ? selectedTruck.dataset.model : '',
            truck_type: document.getElementById('truckType').value || '',
            unit: unit,
            destination_from: document.getElementById('destinationFrom').value || '',
            destination_to: document.getElementById('destinationTo').value || '',
            order_date: document.getElementById('orderDate').value || '',
            delivery_date: document.getElementById('deliveryDate').value || '',
            delivery_address: document.getElementById('deliveryAddress').value || '',
            payment_terms: document.getElementById('paymentTerms').value || '',
            unit_price: unitPrice,
            discount_percent: discountPercent,
            amount: rowAmount,
            discount_amount: discountAmount,
            quantity: qty,
            notes: document.getElementById('notes').value || '',
            driver: driverSelect.value || '',
            special_charges: specialCharges
        };

        console.log('Sending payload:', payload);

        const response = await fetch('save_so.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            const text = await response.text();
            console.error('Server response error:', text);
            throw new Error('Server returned status ' + response.status + ': ' + text.substring(0, 100));
        }

        let result;
        try {
            result = await response.json();
        } catch (jsonError) {
            const text = await response.text();
            console.error('Invalid JSON response:', text);
            throw new Error('Server returned invalid JSON: ' + text.substring(0, 100));
        }

        console.log('Response data:', result);
        
        if (result.success) {
            alert('Success! Sales Order ' + result.so_no + ' created.');
            window.location.reload();
        } else {
            alert('Error: ' + (result.message || 'Unknown error occurred'));
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
        }
    } catch (error) {
        console.error('Save error:', error);
        alert('Error: ' + error.message);
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
        lucide.createIcons();
    }
}

// ============================================================
// EVENT LISTENERS
// ============================================================

// Customer selection — checks special process from data attribute
document.getElementById('customerSelect').addEventListener('change', function() {
    const selectedOption = this.selectedOptions[0];
    const customerCode = this.value;
    const fullName = selectedOption.dataset.fullName || '';
    const contactPerson = selectedOption.dataset.contactPerson || '';
    const fullAddress = selectedOption.dataset.fullAddress || '';
    const hasSpecialProcess = selectedOption.dataset.specialProcess === '1';
    
    document.getElementById('customerCode').value = customerCode || '';
    document.getElementById('salesRep').value = contactPerson || '';
    document.getElementById('deliveryAddress').value = fullAddress || '';
    
    // Reset dependent fields
    document.getElementById('truckCodeSelect').innerHTML = '<option value="">-- Select Truck Code --</option>';
    document.getElementById('truckCodeSelect').disabled = true;
    document.getElementById('plateNumberSelect').innerHTML = '<option value="">-- Select Plate Number --</option>';
    document.getElementById('plateNumberSelect').disabled = true;
    document.getElementById('assignedTruckSelect').innerHTML = '<option value="">-- Select Assigned Truck --</option>';
    document.getElementById('assignedTruckSelect').disabled = true;
    document.getElementById('pricingZoneSelect').innerHTML = '<option value="">-- Select truck first --</option>';
    document.getElementById('pricingZoneSelect').disabled = true;
    document.getElementById('truckType').value = '';
    document.getElementById('destinationFrom').value = '';
    document.getElementById('destinationTo').value = '';
    document.getElementById('paymentTerms').value = 'Select pricing first';

    // Reset driver
    document.getElementById('driverSelect').value = '';
    document.getElementById('driverName').value = '';
    viewDriverBtn.disabled = true;
    closeDriverImagePreview();
    
    // Reset special charges
    resetSpecialCharges();
    
    disablePriceEditing();
    
    if (customerCode) {
        fetchTruckCodes(customerCode);
        document.getElementById('driverSelect').disabled = false;
        document.querySelectorAll('.item-description, .qty-input, .discount-input, #notes, #saveBtn, #printBtn, #addRowBtn, .remove-row-btn').forEach(el => {
            if (el.classList) el.disabled = false;
        });
        
        // Handle special process
        if (hasSpecialProcess) {
            updateSpecialProcessBadge(true);
            document.getElementById('specialChargesCard').style.display = 'block';
            document.getElementById('specialChargesTotalRow').style.display = 'flex';
            addSpecialChargeRow(); // auto-add first row
        } else {
            updateSpecialProcessBadge(false);
            document.getElementById('specialChargesCard').style.display = 'none';
            document.getElementById('specialChargesTotalRow').style.display = 'none';
        }
    } else {
        updateSpecialProcessBadge(false);
        document.getElementById('specialChargesCard').style.display = 'none';
        document.getElementById('specialChargesTotalRow').style.display = 'none';
        document.getElementById('driverSelect').disabled = true;
        document.querySelectorAll('.item-description, .qty-input, .discount-input, #notes, #saveBtn, #printBtn, #addRowBtn, .remove-row-btn').forEach(el => {
            if (el.classList) el.disabled = true;
        });
    }
    
    calculateTotals();
});

// Driver selection
document.getElementById('driverSelect').addEventListener('change', function() {
    const selectedOption = this.selectedOptions[0];
    const driverName = selectedOption ? selectedOption.dataset.fullName : '';
    const driverCode = this.value || '';
    const profilePicture = selectedOption ? selectedOption.dataset.profilePicture : '';

    document.getElementById('driverName').value = driverName || '';

    if (driverCode) {
        viewDriverBtn.disabled = false;
        openDriverImagePreview(driverCode, driverName, profilePicture);
    } else {
        viewDriverBtn.disabled = true;
        closeDriverImagePreview();
    }
});

// Truck selection
document.getElementById('truckCodeSelect').addEventListener('change', handleTruckCodeSelect);
document.getElementById('plateNumberSelect').addEventListener('change', handlePlateNumberSelect);
document.getElementById('pricingZoneSelect').addEventListener('change', handlePricingSelect);

// Add special charge button
document.getElementById('addSpecialChargeBtn').addEventListener('click', function() {
    addSpecialChargeRow();
});

// Add new line item row
document.getElementById('addRowBtn').addEventListener('click', function() {
    const tbody = document.getElementById('lineItemsBody');
    const rowCount = tbody.children.length;
    const newRow = document.createElement('tr');
    newRow.className = 'line-item-row';
    newRow.innerHTML = `
        <td>${rowCount + 1}</td>
        <td><input type="text" class="item-description" style="width:100%;" placeholder="-- Select a truck --" readonly></td>
        <td><input type="text" class="unit-input" value="TRIP" style="width:70px;text-align:center;"></td>
        <td><input type="number" class="qty-input" value="1" min="1" style="width:70px;text-align:center;"></td>
        <td><input type="number" class="price-input" value="0.00" step="0.01" style="width:110px;text-align:right;" readonly></td>
        <td><input type="number" class="discount-input" value="0" min="0" max="100" style="width:80px;text-align:center;"></td>
        <td class="amount-display" style="font-weight:600;text-align:right;">₱0.00</td>
        <td><button type="button" class="remove-row-btn"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button></td>
    `;
    tbody.appendChild(newRow);
    
    newRow.querySelector('.qty-input').addEventListener('input', calculateTotals);
    newRow.querySelector('.discount-input').addEventListener('input', calculateTotals);
    newRow.querySelector('.price-input').addEventListener('input', calculateTotals);
    
    if (document.getElementById('plateNumberSelect').value) {
        newRow.querySelector('.price-input').readOnly = false;
        newRow.querySelector('.price-input').disabled = false;
        newRow.querySelector('.price-input').style.backgroundColor = '#ffffff';
    }
    
    newRow.querySelector('.remove-row-btn').addEventListener('click', function() {
        if (tbody.children.length > 1) {
            newRow.remove();
            Array.from(tbody.children).forEach((row, index) => {
                row.children[0].textContent = index + 1;
            });
            calculateTotals();
        }
    });
    
    lucide.createIcons();
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input') || 
        e.target.classList.contains('discount-input') || 
        e.target.classList.contains('price-input') ||
        e.target.classList.contains('charge-amount-input')) {
        calculateTotals();
    }
});

document.getElementById('orderDate').addEventListener('change', function() {
    document.getElementById('deliveryDate').value = this.value;
});

document.getElementById('saveBtn').addEventListener('click', saveOrder);

// Initial setup
lucide.createIcons();
disablePriceEditing();

document.querySelectorAll('.restricted-item').forEach(el => {
    el.addEventListener('click', e => {
        e.preventDefault();
        modal.style.display = 'flex';
    });
});
</script>
</body>
</html>