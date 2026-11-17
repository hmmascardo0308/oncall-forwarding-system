<?php
// sales_order.php
session_start();
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
$is_admin   = in_array('admin', $user_roles);

$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

$role_display_name = getRoleDisplayName($user_roles);

$today      = date('Y');
$preview_so = 'SO-' . $today . '-00001';

$so_query = "SELECT sales_order_no FROM sales_order 
             WHERE sales_order_no LIKE 'SO-$today-%' 
             ORDER BY id DESC LIMIT 1";
$so_result = $conn->query($so_query);

if ($so_result && $so_result->num_rows > 0) {
    $last_so = $so_result->fetch_assoc()['sales_order_no'];
    $parts   = explode('-', $last_so);
    $last_number = (int) end($parts);
    $next_number = $last_number + 1;
    $preview_so  = 'SO-' . $today . '-' . str_pad($next_number, 5, '0', STR_PAD_LEFT);
}

$order_date_val    = date('Y-m-d');
$delivery_date_val = date('Y-m-d');

// Customers
$customers_query  = "SELECT customer_code, full_name, contact_person, full_address, with_special_process, payment_terms 
                     FROM customer_masterlist ORDER BY full_name";
$customers_result = $conn->query($customers_query);
$customers = [];
if ($customers_result && $customers_result->num_rows > 0) {
    while ($row = $customers_result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Drivers
$drivers_query = "SELECT employee_code, full_name, profile_picture 
                  FROM employee_list 
                  WHERE position = 'DRIVER' AND status = 'active' 
                  ORDER BY full_name";
$drivers_result = $conn->query($drivers_query);
$drivers = [];
if ($drivers_result && $drivers_result->num_rows > 0) {
    while ($row = $drivers_result->fetch_assoc()) {
        $drivers[] = $row;
    }
}

// Special charge kinds
$charge_kinds = [];
$charge_kinds_query  = "SELECT DISTINCT charge_kind FROM special_charge_kinds ORDER BY charge_kind";
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
        /* ===== No Pricing Modal ===== */
        .no-pricing-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(3px);
        }
        .no-pricing-modal-overlay.active {
            display: flex;
        }
        .no-pricing-modal {
            background: #ffffff;
            border-radius: 16px;
            max-width: 460px;
            width: 100%;
            padding: 28px 26px 22px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: npModalPop 0.22s ease-out;
            position: relative;
        }
        @keyframes npModalPop {
            from { transform: scale(0.92); opacity: 0; }
            to   { transform: scale(1);    opacity: 1; }
        }
        .no-pricing-modal .np-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #fef3c7;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .no-pricing-modal .np-icon-wrap i {
            width: 32px;
            height: 32px;
            color: #d97706;
        }
        .no-pricing-modal h3 {
            font-size: 19px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 8px;
        }
        .no-pricing-modal p {
            font-size: 14px;
            color: #64748b;
            line-height: 1.55;
            margin: 0 0 6px;
        }
        .no-pricing-modal .np-customer-name {
            font-weight: 700;
            color: #0f172a;
            background: #f1f5f9;
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            margin: 8px 0 14px;
            font-size: 13px;
        }
        .no-pricing-modal .np-hint {
            font-size: 12.5px;
            color: #94a3b8;
            font-style: italic;
            margin-bottom: 20px;
            display: block;
        }
        .no-pricing-modal .np-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .no-pricing-modal button {
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 22px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            justify-content: center;
        }
        .no-pricing-modal .np-btn-primary {
            background: #2654dc;
            color: #fff;
        }
        .no-pricing-modal .np-btn-primary:hover {
            background: #020ba6;
            transform: translateY(-1px);
        }
        .no-pricing-modal .np-btn-secondary {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .no-pricing-modal .np-btn-secondary:hover {
            background: #e2e8f0;
        }
        .no-pricing-modal .np-close-x {
            position: absolute;
            top: 12px;
            right: 14px;
            background: transparent;
            border: none;
            font-size: 24px;
            line-height: 1;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .no-pricing-modal .np-close-x:hover {
            background: #f1f5f9;
            color: #475569;
        }

        /* ===== VAT Checkbox ===== */
        .vat-checkbox-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            padding-top: 10px;
        }
        .vat-checkbox-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #2654dc;
        }
        .vat-checkbox-wrap label {
            cursor: pointer;
            font-weight: 500;
            color: #334155;
            font-size: 14px;
            margin: 0;
        }

        /* ===== Special Charges VAT Checkbox ===== */
        .sc-vat-checkbox-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding: 10px 12px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
        }
        .sc-vat-checkbox-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #b45309;
        }
        .sc-vat-checkbox-wrap label {
            cursor: pointer;
            font-weight: 600;
            color: #92400e;
            font-size: 13.5px;
            margin: 0;
        }

        /* ===== Delivery Receipt prefix input ===== */
        .dr-input-wrap {
            display: flex;
            align-items: stretch;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .dr-input-wrap:focus-within {
            border-color: #2654dc;
            box-shadow: 0 0 0 3px rgba(38, 84, 220, 0.12);
        }
        .dr-prefix {
            display: flex;
            align-items: center;
            padding: 0 10px;
            background: #f1f5f9;
            color: #334155;
            font-weight: 600;
            font-size: 13.5px;
            border-right: 1px solid #e2e8f0;
            white-space: nowrap;
            user-select: none;
        }
        .dr-input-wrap input.dr-input {
            flex: 1;
            border: none !important;
            outline: none !important;
            padding: 8px 10px;
            font-family: inherit;
            font-size: 14px;
            background: transparent;
            color: #0f172a;
            min-width: 0;
        }
    </style>
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

<!-- ===== NO PRICING MODAL ===== -->
<div id="noPricingModal" class="no-pricing-modal-overlay">
    <div class="no-pricing-modal">
        <button type="button" class="np-close-x" id="npCloseX" title="Close">×</button>
        <div class="np-icon-wrap">
            <i data-lucide="alert-triangle"></i>
        </div>
        <h3>No Pricing Found</h3>
        <p>The selected customer has no registered pricing yet.</p>
        <div class="np-customer-name" id="npCustomerName">—</div>
        <p class="np-hint">Please register pricing for this customer before creating a Sales Order.</p>
        <div class="np-actions">
            <button type="button" class="np-btn-primary" id="npGoToPricing">
                <i data-lucide="plus-circle" style="width:16px;height:16px;"></i>
                Add Pricing
            </button>
            <button type="button" class="np-btn-secondary" id="npChooseAnother">
                <i data-lucide="users" style="width:16px;height:16px;"></i>
                Choose Another Customer
            </button>
        </div>
    </div>
</div>

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
                                    data-special-process="<?= (int)($customer['with_special_process'] ?? 0) ?>"
                                    data-payment-terms="<?= htmlspecialchars($customer['payment_terms'] ?? '') ?>">
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
                    <label>Order Date <span style="color: #767676; font-style: italic;"> (editable)</span></label>
                    <input type="date" id="orderDate" value="<?= $order_date_val ?>">
                </div>
                <div class="form-group">
                    <label>Delivery Date <span style="color: #767676; font-style: italic;"> (editable)</span></label>
                    <input type="date" id="deliveryDate" value="<?= $delivery_date_val ?>">
                </div>

                <div class="form-group">
                    <label>Special Process</label>
                    <div style="padding-top:6px;">
                        <span id="specialProcessBadge" class="special-process-badge none">
                            <i data-lucide="minus-circle"></i> None
                        </span>
                    </div>
                </div>
                <div class="form-group"></div>

                <!-- ==== FILTER DROPDOWNS (populated from customer's pricing) ==== -->
                <div class="form-group">
                    <label>Truck Type</label>
                    <select id="filterTruckType" disabled>
                        <option value="">-- Select Customer First --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Wheel Count</label>
                    <select id="filterWheelCount" disabled>
                        <option value="">-- Select Truck Type First --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Footer</label>
                    <select id="filterFooter" disabled>
                        <option value="">-- Select Wheel Count First --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Zone</label>
                    <select id="pricingZoneSelect" disabled>
                        <option value="">-- Select Truck Type / Wheel / Footer --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Payment Terms</label>
                    <input type="text" id="paymentTerms" readonly placeholder="Auto-filled from customer">
                </div>

                <!-- VAT Checkbox -->
                <div class="form-group">
                    <label>VAT (12%)</label>
                    <div class="vat-checkbox-wrap">
                        <input type="checkbox" id="vatCheckbox" checked>
                        <label for="vatCheckbox">Include VAT in this Sales Order</label>
                    </div>
                </div>

                <!-- Delivery Receipt: prefix + editable input -->
                <div class="form-group">
                    <label>Delivery Receipt</label>
                    <div class="dr-input-wrap">
                        <span class="dr-prefix">DR# -</span>
                        <input type="text" id="deliveryReceipt" class="dr-input" placeholder="Enter DR number">
                    </div>
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
            <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;">
                <i data-lucide="package" style="width:16px;height:16px;"></i> Line Items
            </div>
            <div class="table-wrapper">
                <table id="lineItemsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th style="min-width:280px;">Truck</th>
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
                                <select class="truck-select" style="width:100%;" disabled>
                                    <option value="">-- Select Truck Type / Wheel / Footer first --</option>
                                </select>
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
                    <!-- <i data-lucide="plus-circle"></i> Add Item -->
                </button>
            </div>
        </div>

        <!-- Special Charges -->
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
                    <tbody id="specialChargesBody"></tbody>
                </table>
            </div>

            <!-- Special Charges VAT Checkbox -->
            <div class="sc-vat-checkbox-wrap" id="scVatWrap" style="display:none;">
                <input type="checkbox" id="specialChargesVatCheckbox" checked>
                <label for="specialChargesVatCheckbox">Include VAT (12%) on Special Charges</label>
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
                <div class="totals-row" id="vatRow"><span style="font-size: 15px; font-weight: 800; color: black;">VAT (12%)</span><span id="vatAmount">₱0.00</span></div>
                <div class="totals-row" id="specialChargesTotalRow" style="display:none; background:#fffbeb; border-radius:6px; padding-left:8px; padding-right:8px;">
                    <span style="font-size: 15px; font-weight: 800; color: #b45309;">Special Charges</span>
                    <span id="specialChargesTotal" style="color:#b45309; font-weight:700;">₱0.00</span>
                </div>
                <div class="totals-row" id="specialChargesVatRow" style="display:none; background:#fffbeb; border-radius:6px; padding-left:8px; padding-right:8px;">
                    <span style="font-size: 15px; font-weight: 800; color: #b45309;">Special Charges VAT (12%)</span>
                    <span id="specialChargesVatAmount" style="color:#b45309; font-weight:700;">₱0.00</span>
                </div>
                <div class="totals-row total-final"><span>Total Due</span><span id="totalDue">₱0.00</span></div>
            </div>
        </div>

        <div class="actions-row" style="margin-top:32px;">
            <a href="sales_order.php" class="btn-secondary"><i data-lucide="x"></i> Cancel</a>
            <a href="sales_order.php" class="btn-secondary"><i data-lucide="list-plus"></i> Create New</a>
            <button class="btn-primary" id="saveBtn" disabled><i data-lucide="save"></i> Save</button>
        </div>
    </div>
</main>

<script>
lucide.createIcons();

const userRoles    = <?php echo json_encode($user_roles); ?>;
const allowedPages = <?php echo json_encode($allowed_pages); ?>;
const chargeKinds  = <?php echo json_encode($charge_kinds); ?>;

const modal = document.getElementById('accessModal');
function closeModal() { modal.style.display = 'none'; }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

// ============================================================
// NO PRICING MODAL
// ============================================================
const noPricingModal     = document.getElementById('noPricingModal');
const npCustomerName     = document.getElementById('npCustomerName');
const npCloseX           = document.getElementById('npCloseX');
const npGoToPricing      = document.getElementById('npGoToPricing');
const npChooseAnother    = document.getElementById('npChooseAnother');

function openNoPricingModal(customerName, customerCode) {
    npCustomerName.textContent = customerName || customerCode || 'Unknown Customer';
    noPricingModal.classList.add('active');
    noPricingModal.dataset.customerCode = customerCode || '';
    lucide.createIcons();
}

function closeNoPricingModal() {
    noPricingModal.classList.remove('active');
}

npCloseX?.addEventListener('click', closeNoPricingModal);
noPricingModal?.addEventListener('click', e => {
    if (e.target === noPricingModal) closeNoPricingModal();
});

npGoToPricing?.addEventListener('click', () => {
    const code = noPricingModal.dataset.customerCode || '';
    window.location.href = 'customer_pricing.php?customer_code=' + encodeURIComponent(code);
});

npChooseAnother?.addEventListener('click', () => {
    closeNoPricingModal();
    const cs = document.getElementById('customerSelect');
    cs.value = '';
    cs.dispatchEvent(new Event('change'));
    cs.focus();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && noPricingModal.classList.contains('active')) {
        closeNoPricingModal();
    }
});

// ============================================================
// DRIVER IMAGE PREVIEW
// ============================================================
const driverImagePreview        = document.getElementById('driverImagePreview');
const driverImagePreviewContent = document.getElementById('driverImagePreviewContent');
const driverImagePreviewName    = document.getElementById('driverImagePreviewName');
const driverImagePreviewCode    = document.getElementById('driverImagePreviewCode');
const viewDriverBtn             = document.getElementById('viewDriverBtn');

function openDriverImagePreview(code, name, pic) {
    driverImagePreviewName.textContent = name || 'Unknown Driver';
    driverImagePreviewCode.textContent = code || '';
    if (pic) {
        driverImagePreviewContent.innerHTML = `<img src="../uploads/employee_images/${pic}" alt="${name}">`;
    } else {
        driverImagePreviewContent.innerHTML = `
            <div class="driver-image-preview-placeholder">
                <i data-lucide="user"></i><span>No profile picture uploaded</span>
            </div>`;
    }
    driverImagePreview.classList.add('active');
    lucide.createIcons();
}
function closeDriverImagePreview() {
    driverImagePreview.classList.remove('active');
    setTimeout(() => { if (!driverImagePreview.classList.contains('active')) driverImagePreviewContent.innerHTML = ''; }, 200);
}
document.getElementById('closeDriverImagePreview')?.addEventListener('click', closeDriverImagePreview);
viewDriverBtn?.addEventListener('click', () => {
    const sel = document.getElementById('driverSelect');
    const opt = sel.selectedOptions[0];
    if (!sel.value || !opt) return;
    openDriverImagePreview(sel.value, opt.dataset.fullName || '', opt.dataset.profilePicture || '');
});
driverImagePreview?.addEventListener('click', e => { if (e.target === driverImagePreview) closeDriverImagePreview(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (driverImagePreview.classList.contains('active')) closeDriverImagePreview();
        else if (modal.style.display === 'flex') closeModal();
    }
});

// ============================================================
// GLOBAL STATE
// ============================================================
let customerPricing = {
    combos: {},
    zonesByCombo: {},
    trucksByCombo: {}
};
let currentComboKey = '';
let currentZoneData = null;

// The combo's footer is tracked separately so we can send it to the
// backend regardless of which truck the user picks.
let currentComboFooter = '';

// ============================================================
// COMBO KEY BUILDER — mirrors get_customer_pricing.php
// ============================================================
function isPrimeMoverType(truckType) {
    return String(truckType || '').trim().toLowerCase() === 'prime mover';
}

function buildComboKey(truckType, wheelCount, footer) {
    const tt = String(truckType || '').trim();
    const ft = String(footer    || '').trim();

    if (isPrimeMoverType(tt)) {
        return `${tt}|${ft}`;
    }
    const wc = (wheelCount === null || wheelCount === undefined) ? '' : String(wheelCount).trim();
    return `${tt}|${wc}|${ft}`;
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ============================================================
// FETCH CUSTOMER PRICING
// ============================================================
async function fetchCustomerPricing(customerCode) {
    if (!customerCode) return;
    try {
        const res = await fetch(`get_customer_pricing.php?customer_code=${encodeURIComponent(customerCode)}`);
        const data = await res.json();
        if (!data.success) {
            console.error('Failed to load pricing:', data.error);
            return;
        }

        customerPricing.combos = {};
        (data.combos || []).forEach(c => {
            const key = buildComboKey(c.truck_type, c.wheel_count, c.footer);
            customerPricing.combos[key] = c;
        });
        customerPricing.zonesByCombo  = data.zones_by_combo  || {};
        customerPricing.trucksByCombo = data.trucks_by_combo || {};

        const typeSelect = document.getElementById('filterTruckType');

        if (Object.keys(customerPricing.combos).length === 0) {
            typeSelect.innerHTML = '<option value="">-- No pricing available --</option>';
            typeSelect.disabled = true;
            resetWheelFooterZone();
            resetAllLineItemTrucks();

            document.getElementById('saveBtn').disabled = true;
            document.getElementById('addRowBtn').disabled = true;
            document.getElementById('notes').disabled = true;
            document.querySelectorAll('.qty-input, .discount-input, .unit-input, .remove-row-btn').forEach(el => el.disabled = true);

            const cs = document.getElementById('customerSelect');
            const opt = cs.selectedOptions[0];
            const custName = opt ? (opt.dataset.fullName || cs.value) : cs.value;

            openNoPricingModal(custName, customerCode);
            return;
        }

        typeSelect.innerHTML = '<option value="">-- Select Truck Type --</option>';
        const seenTypes = new Set();
        Object.values(customerPricing.combos).forEach(c => {
            if (!seenTypes.has(c.truck_type)) {
                seenTypes.add(c.truck_type);
                const opt = document.createElement('option');
                opt.value = c.truck_type;
                opt.textContent = c.truck_type;
                typeSelect.appendChild(opt);
            }
        });
        typeSelect.disabled = false;

        resetWheelFooterZone();
        resetAllLineItemTrucks();

    } catch (err) {
        console.error('Error fetching pricing:', err);
    }
}

// ============================================================
// FILTER DROPDOWNS
// ============================================================
function resetWheelFooterZone() {
    const wc = document.getElementById('filterWheelCount');
    wc.innerHTML = '<option value="">-- Select Truck Type First --</option>';
    wc.disabled = true;

    const ft = document.getElementById('filterFooter');
    ft.innerHTML = '<option value="">-- Select Wheel Count First --</option>';
    ft.disabled = true;

    const zn = document.getElementById('pricingZoneSelect');
    zn.innerHTML = '<option value="">-- Select Truck Type / Wheel / Footer --</option>';
    zn.disabled = true;

    document.getElementById('destinationFrom').value = '';
    document.getElementById('destinationTo').value = '';
    currentComboKey = '';
    currentComboFooter = '';
    currentZoneData = null;
}

function resetAllLineItemTrucks() {
    document.querySelectorAll('.truck-select').forEach(sel => {
        sel.innerHTML = '<option value="">-- Select Truck Type / Wheel / Footer first --</option>';
        sel.disabled = true;
    });
    document.querySelectorAll('.price-input').forEach(inp => {
        inp.value = '0.00';
        inp.readOnly = true;
        inp.disabled = true;
        inp.style.backgroundColor = '#f5f5f5';
    });
    calculateTotals();
}

// Helper: collect distinct wheel counts for a type (ignoring footers)
function collectWheels(type) {
    const seen = new Set();
    const out  = [];
    Object.values(customerPricing.combos).forEach(c => {
        if (c.truck_type !== type) return;
        const w = (c.wheel_count === null || c.wheel_count === undefined) ? '' : String(c.wheel_count);
        if (!seen.has(w)) { seen.add(w); out.push(w); }
    });
    return out;
}

// Helper: collect distinct footers for type+wheel (may include null → treated as empty)
function collectFooters(type, wheel) {
    const seen = new Set();
    const out  = [];
    Object.values(customerPricing.combos).forEach(c => {
        if (c.truck_type !== type) return;
        if (String(c.wheel_count) !== String(wheel)) return;
        const f = (c.footer === null || c.footer === undefined) ? '' : String(c.footer);
        if (!seen.has(f)) { seen.add(f); out.push(f); }
    });
    return out;
}

document.getElementById('filterTruckType').addEventListener('change', function() {
    const type = this.value;
    const wc   = document.getElementById('filterWheelCount');
    const ft   = document.getElementById('filterFooter');
    const zn   = document.getElementById('pricingZoneSelect');

    wc.innerHTML = '<option value="">-- Select Wheel Count --</option>';
    ft.innerHTML = '<option value="">-- Select Footer --</option>';
    zn.innerHTML = '<option value="">-- Select Truck Type / Wheel / Footer --</option>';
    zn.disabled = true;
    currentComboKey = '';
    currentComboFooter = '';
    resetAllLineItemTrucks();

    if (!type) { resetWheelFooterZone(); return; }

    if (isPrimeMoverType(type)) {
        // PRIME MOVER — wheel not applicable; footer required
        wc.innerHTML = '<option value="">-- Not applicable for PRIME MOVER --</option>';
        wc.disabled  = true;

        ft.innerHTML = '<option value="">-- Select Footer --</option>';
        const seen = new Set();
        Object.values(customerPricing.combos).forEach(c => {
            if (c.truck_type === type && c.is_prime_mover) {
                const f = (c.footer === null || c.footer === undefined) ? '' : String(c.footer);
                if (f !== '' && !seen.has(f)) {
                    seen.add(f);
                    const opt = document.createElement('option');
                    opt.value = f;
                    opt.textContent = f;
                    ft.appendChild(opt);
                }
            }
        });

        if (ft.options.length <= 1) {
            ft.innerHTML = '<option value="">-- No footers found --</option>';
            ft.disabled = true;
        } else {
            ft.disabled = false;
        }
        return;
    }

    // Non-prime — populate wheel counts
    const wheels = collectWheels(type);
    if (wheels.length === 0) {
        wc.innerHTML = '<option value="">-- No wheel counts found --</option>';
        wc.disabled = true;
        return;
    }
    wheels.forEach(w => {
        const opt = document.createElement('option');
        opt.value = w;
        opt.textContent = (w === '') ? '(None)' : w;
        wc.appendChild(opt);
    });
    wc.disabled = false;

    // If only one wheel option, auto-select and cascade
    if (wc.options.length === 2) {
        wc.selectedIndex = 1;
        wc.dispatchEvent(new Event('change'));
    }
});

document.getElementById('filterWheelCount').addEventListener('change', function() {
    const type  = document.getElementById('filterTruckType').value;
    const wheel = this.value;
    const ft    = document.getElementById('filterFooter');
    const zn    = document.getElementById('pricingZoneSelect');

    if (isPrimeMoverType(type)) return;

    ft.innerHTML = '<option value="">-- Select Footer --</option>';
    zn.innerHTML = '<option value="">-- Select Truck Type / Wheel / Footer --</option>';
    zn.disabled = true;
    currentComboKey = '';
    currentComboFooter = '';
    resetAllLineItemTrucks();

    if (!type || wheel === '') {
        ft.innerHTML = '<option value="">-- Select Wheel Count First --</option>';
        ft.disabled = true;
        return;
    }

    const footers = collectFooters(type, wheel);

    if (footers.length === 0) {
        ft.innerHTML = '<option value="">-- No footers found --</option>';
        ft.disabled = true;
        return;
    }

    // Populate footers (empty string shown as "(None)")
    footers.forEach(f => {
        const opt = document.createElement('option');
        opt.value = f;
        opt.textContent = (f === '') ? '(None)' : f;
        ft.appendChild(opt);
    });
    ft.disabled = false;

    // Auto-select when only one footer option exists
    if (ft.options.length === 2) {
        ft.selectedIndex = 1;
        ft.dispatchEvent(new Event('change'));
    }
});

document.getElementById('filterFooter').addEventListener('change', function() {
    const type   = document.getElementById('filterTruckType').value;
    const wheel  = document.getElementById('filterWheelCount').value;
    const footer = this.value; // may be ''

    const zn = document.getElementById('pricingZoneSelect');
    zn.innerHTML = '<option value="">-- Select Zone --</option>';

    const prime = isPrimeMoverType(type);

    if (!type) {
        zn.disabled = true;
        currentComboKey = '';
        currentComboFooter = '';
        resetAllLineItemTrucks();
        return;
    }

    if (prime) {
        if (footer === '') {
            zn.disabled = true;
            currentComboKey = '';
            currentComboFooter = '';
            resetAllLineItemTrucks();
            return;
        }
    } else {
        // Non-prime requires wheel; footer can be empty
        if (wheel === '') {
            zn.disabled = true;
            currentComboKey = '';
            currentComboFooter = '';
            resetAllLineItemTrucks();
            return;
        }
    }

    // ★★★ Store the COMBO's footer (this is what should be saved) ★★★
    currentComboFooter = footer;

    currentComboKey = buildComboKey(type, wheel, footer);
    const zones = customerPricing.zonesByCombo[currentComboKey] || [];

    if (zones.length === 0) {
        zn.innerHTML = '<option value="">-- No zones found --</option>';
        zn.disabled = true;
        resetAllLineItemTrucks();
        return;
    }

    zones.forEach(z => {
        const opt = document.createElement('option');
        opt.value = JSON.stringify(z);
        opt.textContent = `${z.zone_from || '-'} → ${z.zone_to || '-'}`;
        opt.dataset.zoneFrom       = z.zone_from || '';
        opt.dataset.zoneTo         = z.zone_to || '';
        opt.dataset.effectivePrice = z.effective_unit_price || 0;
        opt.dataset.ratePerTrip    = z.rate_per_trip || '';
        opt.dataset.minimumCharge  = z.minimum_charge || '';
        zn.appendChild(opt);
    });
    zn.disabled = false;

    if (zn.options.length === 2) {
        zn.selectedIndex = 1;
    }

    populateLineItemTrucks();
    handleZoneSelect();
});

document.getElementById('pricingZoneSelect').addEventListener('change', handleZoneSelect);

function handleZoneSelect() {
    const sel = document.getElementById('pricingZoneSelect');
    const opt = sel.selectedOptions[0];

    if (!opt || !sel.value) {
        document.getElementById('destinationFrom').value = '';
        document.getElementById('destinationTo').value = '';
        currentZoneData = null;
        applyPriceToAllRows(0);
        return;
    }

    document.getElementById('destinationFrom').value = opt.dataset.zoneFrom || '';
    document.getElementById('destinationTo').value   = opt.dataset.zoneTo || '';

    const price = parseFloat(opt.dataset.effectivePrice) || 0;
    currentZoneData = {
        zone_from: opt.dataset.zoneFrom,
        zone_to: opt.dataset.zoneTo,
        price: price
    };
    applyPriceToAllRows(price);
}

// ============================================================
// LINE ITEMS
// ============================================================
function populateLineItemTrucks() {
    if (!currentComboKey) return;
    const trucks = customerPricing.trucksByCombo[currentComboKey] || [];

    document.querySelectorAll('.truck-select').forEach(sel => {
        sel.innerHTML = '<option value="">-- Select Truck --</option>';
        if (trucks.length === 0) {
            sel.innerHTML = '<option value="">-- No trucks available for this combo --</option>';
            sel.disabled = true;
            return;
        }
        trucks.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t.truck_code;
            opt.textContent = `${t.plate_number} — ${t.brand} ${t.model}`;
            opt.dataset.truckCode   = t.truck_code;
            opt.dataset.plateNumber = t.plate_number;
            opt.dataset.brand       = t.brand;
            opt.dataset.model       = t.model;
            opt.dataset.truckType   = t.truck_type;
            opt.dataset.wheelCount  = (t.wheel_count === null || t.wheel_count === undefined) ? '' : t.wheel_count;
            // Footer stored only for display/reference — NOT what gets saved
            opt.dataset.footer      = (t.footer === null || t.footer === undefined) ? '' : String(t.footer);
            opt.dataset.truckId     = t.id;
            sel.appendChild(opt);
        });
        sel.disabled = false;
    });
}

function applyPriceToAllRows(price) {
    document.querySelectorAll('.price-input').forEach(inp => {
        inp.value = parseFloat(price || 0).toFixed(2);
        inp.readOnly = false;
        inp.disabled = false;
        inp.style.backgroundColor = '#ffffff';
    });
    calculateTotals();
}

function wireLineItemRow(row) {
    const truckSel = row.querySelector('.truck-select');
    const priceInp = row.querySelector('.price-input');
    const qtyInp   = row.querySelector('.qty-input');
    const discInp  = row.querySelector('.discount-input');
    const removeBtn = row.querySelector('.remove-row-btn');

    truckSel.addEventListener('change', function() {
        calculateTotals();
    });

    qtyInp.addEventListener('input', calculateTotals);
    discInp.addEventListener('input', calculateTotals);
    priceInp.addEventListener('input', calculateTotals);

    removeBtn.addEventListener('click', function() {
        const body = document.getElementById('lineItemsBody');
        if (body.children.length > 1) {
            row.remove();
            Array.from(body.children).forEach((r, i) => r.children[0].textContent = i + 1);
            calculateTotals();
        }
    });
}

document.getElementById('addRowBtn').addEventListener('click', function() {
    const body = document.getElementById('lineItemsBody');
    const rowCount = body.children.length;
    const newRow = document.createElement('tr');
    newRow.className = 'line-item-row';
    newRow.innerHTML = `
        <td>${rowCount + 1}</td>
        <td>
            <select class="truck-select" style="width:100%;">
                <option value="">-- Select Truck --</option>
            </select>
        </td>
        <td><input type="text" class="unit-input" value="TRIP" style="width:70px;text-align:center;"></td>
        <td><input type="number" class="qty-input" value="1" min="1" style="width:70px;text-align:center;"></td>
        <td><input type="number" class="price-input" value="0.00" step="0.01" style="width:110px;text-align:right;"></td>
        <td><input type="number" class="discount-input" value="0" min="0" max="100" style="width:80px;text-align:center;"></td>
        <td class="amount-display" style="font-weight:600;text-align:right;">₱0.00</td>
        <td><button type="button" class="remove-row-btn"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button></td>
    `;
    body.appendChild(newRow);
    lucide.createIcons();
    wireLineItemRow(newRow);

    populateLineItemTrucks();

    if (currentZoneData) {
        newRow.querySelector('.price-input').value = parseFloat(currentZoneData.price || 0).toFixed(2);
    }
    calculateTotals();
});

document.querySelectorAll('.line-item-row').forEach(wireLineItemRow);

// ============================================================
// SPECIAL CHARGES
// ============================================================
function resetSpecialCharges() {
    document.getElementById('specialChargesBody').innerHTML = '';
    document.getElementById('specialChargesTotal').textContent = '₱0.00';
    document.getElementById('specialChargesVatAmount').textContent = '₱0.00';
    document.getElementById('scVatWrap').style.display = 'none';
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
        Array.from(tbody.children).forEach((row, i) => row.children[0].textContent = i + 1);
        calculateTotals();
        if (tbody.children.length === 0) {
            document.getElementById('specialChargesCard').style.display = 'none';
            document.getElementById('specialChargesTotalRow').style.display = 'none';
            document.getElementById('specialChargesVatRow').style.display = 'none';
            document.getElementById('scVatWrap').style.display = 'none';
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

document.getElementById('addSpecialChargeBtn').addEventListener('click', () => addSpecialChargeRow());

// ============================================================
// TOTALS (VAT optional via checkbox + Special Charges VAT)
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

    let specialChargesTotal = 0;
    document.querySelectorAll('.special-charge-row').forEach(row => {
        const amount = parseFloat(row.querySelector('.charge-amount-input').value) || 0;
        specialChargesTotal += amount;
    });

    // Main order VAT
    const vatCheckbox = document.getElementById('vatCheckbox');
    const vatEnabled = vatCheckbox ? vatCheckbox.checked : true;
    const vat = vatEnabled ? (subtotal - totalDiscount) * 0.12 : 0;

    // Special Charges VAT (independent)
    const scVatCheckbox = document.getElementById('specialChargesVatCheckbox');
    const scVatEnabled = scVatCheckbox ? scVatCheckbox.checked : false;
    const specialChargesVat = (scVatEnabled && specialChargesTotal > 0)
        ? specialChargesTotal * 0.12
        : 0;

    const totalDue = subtotal - totalDiscount + vat + specialChargesTotal + specialChargesVat;

    document.getElementById('subtotal').textContent = formatCurrency(subtotal);
    document.getElementById('totalDiscount').textContent = '−' + formatCurrency(totalDiscount);
    document.getElementById('vatAmount').textContent = formatCurrency(vat);
    document.getElementById('specialChargesTotal').textContent = formatCurrency(specialChargesTotal);
    document.getElementById('specialChargesVatAmount').textContent = formatCurrency(specialChargesVat);
    document.getElementById('totalDue').textContent = formatCurrency(totalDue);

    // Visual feedback
    const vatRow = document.getElementById('vatRow');
    if (vatRow) {
        vatRow.style.opacity = vatEnabled ? '1' : '0.5';
    }

    const scVatRow = document.getElementById('specialChargesVatRow');
    if (scVatRow) {
        scVatRow.style.display = (specialChargesTotal > 0) ? 'flex' : 'none';
        scVatRow.style.opacity = scVatEnabled ? '1' : '0.5';
    }
}

// ============================================================
// DATE SYNC
// ============================================================
let deliveryDateManuallyEdited = false;

const orderDateInput    = document.getElementById('orderDate');
const deliveryDateInput = document.getElementById('deliveryDate');

orderDateInput.addEventListener('change', function() {
    if (this.value) {
        deliveryDateInput.value = this.value;
        deliveryDateManuallyEdited = false;
    }
});

deliveryDateInput.addEventListener('change', function() {
    deliveryDateManuallyEdited = true;
});

// ============================================================
// VAT CHECKBOXES
// ============================================================
document.getElementById('vatCheckbox')?.addEventListener('change', calculateTotals);
document.getElementById('specialChargesVatCheckbox')?.addEventListener('change', calculateTotals);

// ============================================================
// DELIVERY RECEIPT — user types only the number, prefix is UI-only
// ============================================================
const deliveryReceiptInput = document.getElementById('deliveryReceipt');
deliveryReceiptInput?.addEventListener('input', function() {
    if (this.value.startsWith('DR#')) {
        this.value = this.value.replace(/^\s*DR#\s*[-–—]?\s*/i, '');
    }
    if (this.value.startsWith(' ')) {
        this.value = this.value.trimStart();
    }
});

// ============================================================
// CUSTOMER SELECT
// ============================================================
document.getElementById('customerSelect').addEventListener('change', function() {
    const selectedOption = this.selectedOptions[0];
    const customerCode   = this.value;
    const contactPerson  = selectedOption.dataset.contactPerson || '';
    const fullAddress    = selectedOption.dataset.fullAddress || '';
    const paymentTerms   = selectedOption.dataset.paymentTerms || '';
    const hasSpecialProcess = selectedOption.dataset.specialProcess === '1';

    document.getElementById('customerCode').value      = customerCode || '';
    document.getElementById('salesRep').value          = contactPerson || '';
    document.getElementById('deliveryAddress').value   = fullAddress || '';
    document.getElementById('paymentTerms').value      = paymentTerms;

    const vatCb = document.getElementById('vatCheckbox');
    if (vatCb) vatCb.checked = true;
    if (deliveryReceiptInput) deliveryReceiptInput.value = '';

    // Reset special charges VAT checkbox to checked by default
    const scVatCb = document.getElementById('specialChargesVatCheckbox');
    if (scVatCb) scVatCb.checked = true;

    const tt = document.getElementById('filterTruckType');
    tt.innerHTML = '<option value="">-- Select Customer First --</option>';
    tt.disabled = true;
    resetWheelFooterZone();
    resetAllLineItemTrucks();

    document.getElementById('driverSelect').value = '';
    document.getElementById('driverName').value = '';
    viewDriverBtn.disabled = true;
    closeDriverImagePreview();

    resetSpecialCharges();
    closeNoPricingModal();

    if (customerCode) {
        tt.innerHTML = '<option value="">-- Select Truck Type --</option>';
        fetchCustomerPricing(customerCode);

        document.getElementById('driverSelect').disabled = false;
        document.querySelectorAll('#notes, #saveBtn, #printBtn, #addRowBtn').forEach(el => {
            if (el) el.disabled = false;
        });
        document.querySelectorAll('.qty-input, .discount-input, .unit-input').forEach(el => el.disabled = false);
        document.querySelectorAll('.remove-row-btn').forEach(el => el.disabled = false);

        if (hasSpecialProcess) {
            updateSpecialProcessBadge(true);
            document.getElementById('specialChargesCard').style.display = 'block';
            document.getElementById('specialChargesTotalRow').style.display = 'flex';
            document.getElementById('scVatWrap').style.display = 'flex';
            addSpecialChargeRow();
        } else {
            updateSpecialProcessBadge(false);
            document.getElementById('specialChargesCard').style.display = 'none';
            document.getElementById('specialChargesTotalRow').style.display = 'none';
            document.getElementById('specialChargesVatRow').style.display = 'none';
            document.getElementById('scVatWrap').style.display = 'none';
        }
    } else {
        updateSpecialProcessBadge(false);
        document.getElementById('specialChargesCard').style.display = 'none';
        document.getElementById('specialChargesTotalRow').style.display = 'none';
        document.getElementById('specialChargesVatRow').style.display = 'none';
        document.getElementById('scVatWrap').style.display = 'none';
        document.getElementById('driverSelect').disabled = true;
        document.querySelectorAll('#notes, #saveBtn, #printBtn, #addRowBtn').forEach(el => {
            if (el) el.disabled = true;
        });
    }

    calculateTotals();
});

// Driver select
document.getElementById('driverSelect').addEventListener('change', function() {
    const opt = this.selectedOptions[0];
    const name = opt ? opt.dataset.fullName : '';
    const pic  = opt ? opt.dataset.profilePicture : '';

    document.getElementById('driverName').value = name || '';
    if (this.value) {
        viewDriverBtn.disabled = false;
        openDriverImagePreview(this.value, name, pic);
    } else {
        viewDriverBtn.disabled = true;
        closeDriverImagePreview();
    }
});

// ============================================================
// SAVE ORDER
// ============================================================
async function saveOrder() {
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';

    try {
        const customerSelect   = document.getElementById('customerSelect');
        const selectedCustomer = customerSelect.selectedOptions[0];
        const driverSelect     = document.getElementById('driverSelect');

        if (!customerSelect.value) {
            alert('Please select a customer.');
            return resetSaveBtn();
        }
        if (!currentComboKey) {
            alert('Please complete the Truck Type / Footer / Zone selection.');
            return resetSaveBtn();
        }
        if (!driverSelect.value) {
            alert('Please select a driver.');
            return resetSaveBtn();
        }

        const rows = document.querySelectorAll('.line-item-row');
        if (rows.length === 0) {
            alert('Add at least one line item.');
            return resetSaveBtn();
        }
        let missingTruck = false;
        rows.forEach(r => { if (!r.querySelector('.truck-select').value) missingTruck = true; });
        if (missingTruck) {
            alert('Please select a truck on every line item.');
            return resetSaveBtn();
        }

        const specialCharges = [];
        let specialChargeError = false;
        document.querySelectorAll('.special-charge-row').forEach(row => {
            const kind   = row.querySelector('.charge-kind-select').value;
            const amount = parseFloat(row.querySelector('.charge-amount-input').value) || 0;
            if (kind && amount > 0) {
                specialCharges.push({ charge_kind: kind, charge_amount: amount });
            } else if (kind && amount <= 0) {
                specialChargeError = true;
            }
        });
        if (specialChargeError) {
            alert('Please enter a valid amount (> 0) for all special charges.');
            return resetSaveBtn();
        }

        const firstRow = rows[0];
        const truckSel = firstRow.querySelector('.truck-select');
        const truckOpt = truckSel.selectedOptions[0];

        const unitPrice = parseFloat(firstRow.querySelector('.price-input').value) || 0;
        const qty       = parseFloat(firstRow.querySelector('.qty-input').value) || 0;
        const discount  = parseFloat(firstRow.querySelector('.discount-input').value) || 0;
        const unit      = firstRow.querySelector('.unit-input').value || 'TRIP';

        const rowAmount       = (unitPrice * qty) - ((unitPrice * qty) * (discount / 100));
        const discountAmount  = (unitPrice * qty) * (discount / 100);

        const vatCheckbox = document.getElementById('vatCheckbox');
        const vatEnabled  = !!(vatCheckbox && vatCheckbox.checked);
        const vatPercent  = vatEnabled ? 12 : 0;

        // Special Charges VAT
        const scVatCheckbox = document.getElementById('specialChargesVatCheckbox');
        const specialChargesVatEnabled = !!(scVatCheckbox && scVatCheckbox.checked);

        const drUserInput = (deliveryReceiptInput?.value || '').trim();
        const deliveryReceiptValue = drUserInput !== '' ? ('DR# - ' + drUserInput) : '';

        // ★★★ FIX: Use the COMBO's footer (from filterFooter dropdown),
        // NOT the truck's own footer. The combo defines what was priced;
        // the truck's footer field is incidental and may be blank or wrong.
        const footerValue = currentComboFooter || '';

        const payload = {
            customer_code: document.getElementById('customerCode').value || '',
            customer_name: selectedCustomer ? selectedCustomer.dataset.fullName : '',
            truck_code:    truckOpt.dataset.truckCode   || '',
            plate_number:  truckOpt.dataset.plateNumber || '',
            brand:         truckOpt.dataset.brand       || '',
            model:         truckOpt.dataset.model       || '',
            truck_type:    truckOpt.dataset.truckType   || '',
            footer:        footerValue,
            unit:          unit,
            destination_from: document.getElementById('destinationFrom').value || '',
            destination_to:   document.getElementById('destinationTo').value || '',
            order_date:       document.getElementById('orderDate').value || '',
            delivery_date:    document.getElementById('deliveryDate').value || '',
            delivery_address: document.getElementById('deliveryAddress').value || '',
            payment_terms:    document.getElementById('paymentTerms').value || '',
            unit_price:       unitPrice,
            discount_percent: discount,
            amount:           rowAmount,
            discount_amount:  discountAmount,
            quantity:         qty,
            notes:            document.getElementById('notes').value || '',
            driver:           driverSelect.value || '',
            special_charges:  specialCharges,
            vat_enabled:      vatEnabled,
            vat_percent:      vatPercent,
            special_charges_vat_enabled: specialChargesVatEnabled,
            delivery_receipt: deliveryReceiptValue
        };

        const response = await fetch('save_so.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            const text = await response.text();
            throw new Error('Server returned status ' + response.status + ': ' + text.substring(0, 100));
        }

        let result;
        try { result = await response.json(); }
        catch (jErr) {
            const text = await response.text();
            throw new Error('Invalid JSON: ' + text.substring(0, 100));
        }

        if (result.success) {
            alert('Success! Sales Order ' + result.so_no + ' created.');
            window.location.reload();
        } else {
            alert('Error: ' + (result.message || 'Unknown error'));
            resetSaveBtn();
        }

    } catch (err) {
        console.error('Save error:', err);
        alert('Error: ' + err.message);
        resetSaveBtn();
    }

    function resetSaveBtn() {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i data-lucide="save"></i> Save';
        lucide.createIcons();
    }
}

document.getElementById('saveBtn').addEventListener('click', saveOrder);

// ============================================================
// INITIAL
// ============================================================
lucide.createIcons();
disableAllInputs();
calculateTotals();

function disableAllInputs() {
    document.querySelectorAll('.qty-input, .discount-input, .unit-input, .remove-row-btn').forEach(el => el.disabled = true);
    document.querySelectorAll('.price-input').forEach(el => {
        el.readOnly = true;
        el.disabled = true;
        el.style.backgroundColor = '#f5f5f5';
    });
}
</script>
</body>
</html>