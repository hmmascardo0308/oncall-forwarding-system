<?php

// sales_order.php
session_start();
// Set timezone to match your location
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into array
$user_roles = array_map('trim', explode(',', $user_type));

$is_admin = in_array('admin', $user_roles);

// Use centralized access control
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

// Role display name - now using centralized function
$role_display_name = getRoleDisplayName($user_roles);

// Preview SO number (just for display)
$today  = date('Y');
$preview_so = 'SO-' . $today . '-00001'; // simplified — no real count

$order_date_val    = date('Y-m-d');
$delivery_date_val = date('Y-m-d'); // Same as order date

// Fetch all customers for dropdown
$customers_query = "SELECT customer_code, full_name, contact_person, full_address FROM customer_masterlist ORDER BY full_name";
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

// Fetch all drivers from employee_list where position = 'DRIVER'
$drivers_query = "SELECT employee_code, full_name FROM employee_list WHERE position = 'DRIVER' AND status = 'active' ORDER BY full_name";
$drivers_result = $conn->query($drivers_query);
$drivers = [];
if ($drivers_result && $drivers_result->num_rows > 0) {
    while ($row = $drivers_result->fetch_assoc()) {
        $drivers[] = $row;
    }
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
                                    data-full-address="<?= htmlspecialchars($customer['full_address']) ?>">
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

                <!-- Driver Selection -->
                <div class="form-group">
                    <label>Driver <span class="required">*</span></label>
                    <select id="driverSelect" required disabled>
                        <option value="">-- Select Driver --</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?= htmlspecialchars($driver['employee_code']) ?>" 
                                    data-full-name="<?= htmlspecialchars($driver['full_name']) ?>">
                                <?= htmlspecialchars($driver['employee_code']) . ' - ' . htmlspecialchars($driver['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                            <td><button type="button" class="remove-row-btn" disabled><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button></td>
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

        <!-- Notes + Totals -->
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="form-card" style="flex:1;min-width:260px;">
                <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Notes & Instructions</div>
                <textarea id="notes" placeholder="Add internal notes or special delivery instructions..." style="width:100%;min-height:100px;" disabled></textarea>
            </div>

            <div class="totals-box">
                <div class="totals-row"><span  style="font-size: 15px; font-weight: 800; color: black;">Subtotal</span><span id="subtotal">₱0.00</span></div>
                <div class="totals-row"><span  style="font-size: 15px; font-weight: 800; color: black;">Discount</span><span id="totalDiscount">−₱0.00</span></div>
                <div class="totals-row"><span  style="font-size: 15px; font-weight: 800; color: black;">VAT (12%)</span><span id="vatAmount">₱0.00</span></div>
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

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal.style.display === 'flex') {
        closeModal();
    }
});

// Store pricing data globally
let pricingData = {};
let truckData = {};
let truckDataByPlate = {};

// Function to format currency with comma separators
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// Function to enable price editing
function enablePriceEditing() {
    document.querySelectorAll('.price-input').forEach(input => {
        input.readOnly = false;
        input.disabled = false;
        input.style.backgroundColor = '#ffffff';
    });
}

// Function to disable price editing
function disablePriceEditing() {
    document.querySelectorAll('.price-input').forEach(input => {
        input.readOnly = true;
        input.disabled = true;
        input.style.backgroundColor = '#f5f5f5';
    });
}

// Function to fetch truck codes for selected customer
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
            
            // Populate truck code dropdown
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

            // Also populate plate number dropdown
            const plateNumberSelect = document.getElementById('plateNumberSelect');
            plateNumberSelect.innerHTML = '<option value="">-- Select Plate Number --</option>';
            plateNumberSelect.disabled = false;
            
            const uniquePlateNumbers = [...new Set(data.pricing.map(p => p.plate_number))];
            
            uniquePlateNumbers.forEach(plateNumber => {
                const option = document.createElement('option');
                option.value = plateNumber;
                option.textContent = plateNumber;
                // Find the truck details for this plate number
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

// Function to find truck by plate number
function findTruckByPlate(plateNumber) {
    if (!plateNumber) return null;
    return truckDataByPlate[plateNumber] || null;
}

// Function to find truck by truck code
function findTruckByCode(truckCode) {
    if (!truckCode) return null;
    const trucks = truckData[truckCode] || [];
    return trucks.length > 0 ? trucks[0] : null;
}

// Function to handle plate number selection
function handlePlateNumberSelect() {
    const plateNumber = document.getElementById('plateNumberSelect').value;
    const truckCodeSelect = document.getElementById('truckCodeSelect');
    const assignedTruckSelect = document.getElementById('assignedTruckSelect');
    const pricingZoneSelect = document.getElementById('pricingZoneSelect');
    const truckTypeInput = document.getElementById('truckType');

    // Reset if no plate number
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

    // Get truck details by plate number
    const truckDetails = findTruckByPlate(plateNumber);

    if (!truckDetails) {
        console.error('No truck details found for plate:', plateNumber);
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

    // Set truck code
    truckCodeSelect.value = truckDetails.truck_code;

    // Populate and auto-select Assigned Truck
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

    // Set truck type
    truckTypeInput.value = truckDetails.truck_type || '';

    // Update item description with plate number and truck type
    document.querySelector('.item-description').value = `${plateNumber} - ${truckDetails.brand} - ${truckDetails.model} (${truckDetails.truck_type || 'N/A'})`;

    // Filter pricing for this truck
    const pricingForTruck = pricingData.filter(p => p.truck_code === truckDetails.truck_code);

    // Populate pricing/zone dropdown - show only zone_from and zone_to without amount
    pricingZoneSelect.innerHTML = '<option value="">-- Select Pricing --</option>';
    pricingZoneSelect.disabled = false;

    if (pricingForTruck.length > 0) {
        pricingForTruck.forEach(pricing => {
            const option = document.createElement('option');
            option.value = JSON.stringify({
                zone_from: pricing.zone_from,
                zone_to: pricing.zone_to,
                minimum_charge: pricing.minimum_charge,
                payment_terms: pricing.payment_terms
            });
            // Only show zone_from and zone_to without the amount
            option.textContent = `${pricing.zone_from} - ${pricing.zone_to}`;
            option.dataset.zoneFrom = pricing.zone_from;
            option.dataset.zoneTo = pricing.zone_to;
            option.dataset.minimumCharge = pricing.minimum_charge;
            option.dataset.paymentTerms = pricing.payment_terms;
            pricingZoneSelect.appendChild(option);
        });
    } else {
        pricingZoneSelect.innerHTML = '<option value="">-- No pricing found --</option>';
    }

    // Auto-select if only one pricing option exists
    if (pricingZoneSelect.options.length === 2) {
        pricingZoneSelect.selectedIndex = 1;
    }
    
    // Enable price editing after truck selection
    enablePriceEditing();
    handlePricingSelect();
}

// Function to handle truck code selection
function handleTruckCodeSelect() {
    const truckCode = document.getElementById('truckCodeSelect').value;
    const plateNumberSelect = document.getElementById('plateNumberSelect');
    const assignedTruckSelect = document.getElementById('assignedTruckSelect');
    const pricingZoneSelect = document.getElementById('pricingZoneSelect');
    const truckTypeInput = document.getElementById('truckType');

    // Reset if no truck code
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

    // Get truck details using truck_code
    const truckDetails = findTruckByCode(truckCode);

    if (!truckDetails) {
        console.error('No truck details found for code:', truckCode);
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

    // Set plate number
    plateNumberSelect.value = truckDetails.plate_number;

    // Populate and auto-select Assigned Truck
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

    // Set truck type
    truckTypeInput.value = truckDetails.truck_type || '';

    // Update item description with plate number and truck type
    document.querySelector('.item-description').value = `${truckDetails.plate_number} - ${truckDetails.brand} - ${truckDetails.model} (${truckDetails.truck_type || 'N/A'})`;

    // Filter pricing for this truck
    const pricingForTruck = pricingData.filter(p => p.truck_code === truckCode);

    // Populate pricing/zone dropdown - show only zone_from and zone_to without amount
    pricingZoneSelect.innerHTML = '<option value="">-- Select Pricing --</option>';
    pricingZoneSelect.disabled = false;

    if (pricingForTruck.length > 0) {
        pricingForTruck.forEach(pricing => {
            const option = document.createElement('option');
            option.value = JSON.stringify({
                zone_from: pricing.zone_from,
                zone_to: pricing.zone_to,
                minimum_charge: pricing.minimum_charge,
                payment_terms: pricing.payment_terms
            });
            // Only show zone_from and zone_to without the amount
            option.textContent = `${pricing.zone_from} - ${pricing.zone_to}`;
            option.dataset.zoneFrom = pricing.zone_from;
            option.dataset.zoneTo = pricing.zone_to;
            option.dataset.minimumCharge = pricing.minimum_charge;
            option.dataset.paymentTerms = pricing.payment_terms;
            pricingZoneSelect.appendChild(option);
        });
    } else {
        pricingZoneSelect.innerHTML = '<option value="">-- No pricing found --</option>';
    }

    // Auto-select if only one pricing option exists
    if (pricingZoneSelect.options.length === 2) {
        pricingZoneSelect.selectedIndex = 1;
    }
    
    // Enable price editing after truck selection
    enablePriceEditing();
    handlePricingSelect();
}

// Function to handle pricing selection
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
    
    // Update unit price in line items
    const minimumCharge = selectedOption.dataset.minimumCharge || '0';
    document.querySelectorAll('.price-input').forEach(input => {
        input.value = parseFloat(minimumCharge).toFixed(2);
        // Make price input editable
        input.readOnly = false;
        input.disabled = false;
        input.style.backgroundColor = '#ffffff';
    });
    
    calculateTotals();
}

// Function to calculate totals with comma formatting
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
        
        // Format with comma separators
        row.querySelector('.amount-display').textContent = formatCurrency(lineTotal);
        
        subtotal += amount;
        totalDiscount += discountAmount;
    });
    
    const vat = (subtotal - totalDiscount) * 0.12;
    const totalDue = subtotal - totalDiscount + vat;
    
    // Format all totals with comma separators
    document.getElementById('subtotal').textContent = formatCurrency(subtotal);
    document.getElementById('totalDiscount').textContent = '−' + formatCurrency(totalDiscount);
    document.getElementById('vatAmount').textContent = formatCurrency(vat);
    document.getElementById('totalDue').textContent = formatCurrency(totalDue);
}

// Function to save order
async function saveOrder() {
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';

    try {
        // Collect Data
        const customerSelect = document.getElementById('customerSelect');
        const selectedCustomer = customerSelect.selectedOptions[0];
        
        const assignedTruckSelect = document.getElementById('assignedTruckSelect');
        const selectedTruck = assignedTruckSelect.selectedOptions[0];

        const driverSelect = document.getElementById('driverSelect');

        // Validate driver selection
        if (!driverSelect.value) {
            alert('Please select a driver for this order.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        // Validate customer selection
        if (!customerSelect.value) {
            alert('Please select a customer for this order.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        // Validate truck selection
        if (!document.getElementById('plateNumberSelect').value) {
            alert('Please select a truck (plate number) for this order.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
            lucide.createIcons();
            return;
        }

        // Get first row data
        const firstRow = document.querySelector('.line-item-row');
        const unitPrice = parseFloat(firstRow.querySelector('.price-input').value) || 0;
        const qty = parseFloat(firstRow.querySelector('.qty-input').value) || 0;
        const discountPercent = parseFloat(firstRow.querySelector('.discount-input').value) || 0;
        const unit = firstRow.querySelector('.unit-input').value || 'TRIP';
        
        // Amount (Net of discount)
        const rowAmount = (unitPrice * qty) - ((unitPrice * qty) * (discountPercent / 100));
        
        // Global Discount Amount from total
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
            driver: driverSelect.value || ''
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

        // Check if response is OK
        if (!response.ok) {
            const text = await response.text();
            console.error('Server response error:', text);
            throw new Error('Server returned status ' + response.status + ': ' + text.substring(0, 100));
        }

        // Try to parse JSON
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

// Event Listeners
document.getElementById('customerSelect').addEventListener('change', function() {
    const selectedOption = this.selectedOptions[0];
    const customerCode = this.value;
    const fullName = selectedOption.dataset.fullName || '';
    const contactPerson = selectedOption.dataset.contactPerson || '';
    const fullAddress = selectedOption.dataset.fullAddress || '';
    
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
    
    // Disable price editing when no customer
    disablePriceEditing();
    
    // Enable dependent fields if customer is selected
    if (customerCode) {
        fetchTruckCodes(customerCode);
        document.getElementById('driverSelect').disabled = false;
        document.querySelectorAll('.item-description, .qty-input, .discount-input, #notes, #saveBtn, #printBtn, #addRowBtn, .remove-row-btn').forEach(el => {
            if (el.classList) {
                el.disabled = false;
            }
        });
    } else {
        document.getElementById('driverSelect').disabled = true;
        document.querySelectorAll('.item-description, .qty-input, .discount-input, #notes, #saveBtn, #printBtn, #addRowBtn, .remove-row-btn').forEach(el => {
            if (el.classList) {
                el.disabled = true;
            }
        });
    }
});

// Driver selection event listener
document.getElementById('driverSelect').addEventListener('change', function() {
    const selectedOption = this.selectedOptions[0];
    const driverName = selectedOption ? selectedOption.dataset.fullName : '';
    document.getElementById('driverName').value = driverName || '';
});

// Truck selection event listeners
document.getElementById('truckCodeSelect').addEventListener('change', handleTruckCodeSelect);
document.getElementById('plateNumberSelect').addEventListener('change', handlePlateNumberSelect);
document.getElementById('pricingZoneSelect').addEventListener('change', handlePricingSelect);

// Add new row functionality
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
    
    // Add event listeners to new row inputs
    newRow.querySelector('.qty-input').addEventListener('input', calculateTotals);
    newRow.querySelector('.discount-input').addEventListener('input', calculateTotals);
    newRow.querySelector('.price-input').addEventListener('input', calculateTotals);
    
    // Enable price editing if truck is already selected
    if (document.getElementById('plateNumberSelect').value) {
        newRow.querySelector('.price-input').readOnly = false;
        newRow.querySelector('.price-input').disabled = false;
        newRow.querySelector('.price-input').style.backgroundColor = '#ffffff';
    }
    
    newRow.querySelector('.remove-row-btn').addEventListener('click', function() {
        if (tbody.children.length > 1) {
            newRow.remove();
            // Renumber rows
            Array.from(tbody.children).forEach((row, index) => {
                row.children[0].textContent = index + 1;
            });
            calculateTotals();
        }
    });
    
    lucide.createIcons();
});

// Add event listeners for quantity, price, and discount changes
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input') || 
        e.target.classList.contains('discount-input') || 
        e.target.classList.contains('price-input')) {
        calculateTotals();
    }
});

// Sync delivery date with order date (in case order date changes)
document.getElementById('orderDate').addEventListener('change', function() {
    document.getElementById('deliveryDate').value = this.value;
});

// Save Button Listener
document.getElementById('saveBtn').addEventListener('click', saveOrder);

// Initial setup
lucide.createIcons();
disablePriceEditing();

// Optional: just prevent navigation for restricted items (visual only)
document.querySelectorAll('.restricted-item').forEach(el => {
    el.addEventListener('click', e => {
        e.preventDefault();
        modal.style.display = 'flex';
    });
});
</script>
</body>
</html>