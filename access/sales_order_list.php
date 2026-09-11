<?php

// sales_order_list.php
session_start();
require_once __DIR__ . '/../config/config.php';

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

// Check if user has access to sales order list page (admin or sales_order_maker)
// customer_pricer does NOT have access
$can_access_so_list = $is_admin || in_array('sales_order_maker', $user_roles);

if (!$can_access_so_list) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Sales Order List page."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages based on roles (for sidebar access control)
// Define allowed pages based on roles
$allowed_pages = [
    'admin' => ['home.php', 'all_users.php', 'suppliers.php', 'items.php', 'customer.php', 'truck_masterlist.php', 'trailers.php', 'prime_movers.php', 'customer_pricing.php', 'purchase_order.php', 'sales_order.php', 'service_invoice.php', 'company_profile.php', 'purchase_orders_list.php', 'purchase_orders_view.php', 'general_settings.php', 'reset_password.php', 'sales_order_all.php', 'sales_order_list.php', 'aged_payables.php', 'reports.php', 'aged_receivables.php', 'employee_list.php'],
    'user' => ['home.php', 'suppliers.php', 'items.php', 'customer.php', 'truck_masterlist.php', 'trailers.php', 'prime_movers.php', 'customer_pricing.php', 'company_profile.php', 'purchase_orders_list.php', 'purchase_orders_view.php'],
    'purchase_order_maker' => ['home.php', 'purchase_order.php', 'company_profile.php', 'purchase_orders_list.php', 'purchase_orders_view.php'],
    'sales_order_maker' => ['home.php', 'sales_order.php', 'company_profile.php', 'sales_order_all.php', 'sales_order_list.php'],
    'service_invoice_maker' => ['home.php', 'service_invoice.php', 'company_profile.php'],
    'customer_pricer' => ['home.php', 'customer_pricing.php', 'company_profile.php']
];

// Function to check if user has access to a specific page
function hasAccess($page, $user_roles, $allowed_pages) {
    if (in_array('admin', $user_roles)) {
        return true;
    }
    foreach ($user_roles as $role) {
        if (isset($allowed_pages[$role]) && in_array($page, $allowed_pages[$role])) {
            return true;
        }
    }
    return false;
}

// Function to get display name for roles (for the badge)
function getRoleDisplayName($user_roles) {
    if (in_array('admin', $user_roles)) {
        return 'Admin';
    }
    
    $role_names = [];
    foreach ($user_roles as $role) {
        switch ($role) {
            case 'purchase_order_maker':
                $role_names[] = 'PO Maker';
                break;
            case 'sales_order_maker':
                $role_names[] = 'SO Maker';
                break;
            case 'service_invoice_maker':
                $role_names[] = 'SI Maker';
                break;
            case 'customer_pricer':
                $role_names[] = 'Customer Pricer';
                break;
            default:
                $role_names[] = ucfirst(str_replace('_', ' ', $role));
        }
    }
    
    return implode(' + ', $role_names);
}

$role_display_name = getRoleDisplayName($user_roles);

// ── Load the requested SO ─────────────────────────────────────────────────────
$so_no = trim($_GET['so'] ?? '');
$so    = null;

if ($so_no) {
    $safe = $conn->real_escape_string($so_no);
    // Join with employee_list to get driver full name
    // Use CAST or CONVERT to handle collation mismatch
    $res = $conn->query("
        SELECT so.*, 
               el.full_name as driver_full_name,
               el.employee_code as driver_code
        FROM `oncall_forwarding`.`sales_order` so
        LEFT JOIN `oncall_forwarding`.`employee_list` el 
            ON so.driver = el.employee_code COLLATE utf8mb4_general_ci
        WHERE so.sales_order_no = '{$safe}' 
        LIMIT 1
    ");
    if ($res) $so = $res->fetch_assoc();
}

if (!$so) {
    // SO not found — bounce back
    header("Location: sales_order.php");
    exit;
}

// ── Fetch customers ───────────────────────────────────────────────────────────
$customers = [];
$cr = $conn->query("SELECT full_name, customer_code, contact_person, full_address FROM `oncall_forwarding`.`customer_masterlist` ORDER BY full_name ASC");
if ($cr) while ($row = $cr->fetch_assoc()) $customers[] = $row;

// ── Status badge helper ───────────────────────────────────────────────────────
$status      = htmlspecialchars($so['status'] ?? 'Draft');
$statusClass = ($so['status'] === 'For Approval') ? 'status-approval' : 'status-draft';

// ── Determine Sales Rep (Fallback to Contact Person) ──────────────────────────
$sales_rep_val = $so['sales_rep'] ?? '';
if (empty($sales_rep_val) && !empty($so['customer_code'])) {
    foreach ($customers as $c) {
        if ($c['customer_code'] === $so['customer_code']) {
            $sales_rep_val = $c['contact_person'];
            break;
        }
    }
}

// ── Get driver display name ──────────────────────────────────────────────────
$driver_display_name = $so['driver_full_name'] ?? $so['driver'] ?? 'Not Assigned';
$driver_code = $so['driver'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($so['sales_order_no']) ?> | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/sales_order_list.css?v=<?= time(); ?>">
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

<div id="toast"></div>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION /
                <a href="sales_order.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;">Sales Order</a> /
                <?= htmlspecialchars($so['sales_order_no']) ?>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?= htmlspecialchars($full_name) ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?= htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <h1>
                    <span class="icon-wrap"><i data-lucide="file-text" style="width:18px;height:18px;"></i></span>
                    Sales Order
                </h1>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button class="btn-secondary" onclick="window.location.href='sales_order_all.php'" style="padding: 6px 12px; font-size: 15px; white-space: nowrap; background-color: #007155; color: white;">
                        <i data-lucide="list" style="width:14px;height:14px;"></i> View All SO
                    </button>
                    <button class="btn-primary" onclick="window.location.href='sales_order.php'" style="padding: 6px 12px; font-size: 15px; white-space: nowrap;">
                        <i data-lucide="plus" style="width:14px;height:14px;"></i> Create New SO
                    </button>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="so-tag"><?= htmlspecialchars($so['sales_order_no']) ?></span>
                <span class="<?= $statusClass ?>" id="status-badge"><?= $status ?></span>
            </div>
        </div>

        <!-- Meta info row -->
        <div class="meta-info">
            <span>Created by: <strong><?= htmlspecialchars($so['created_by'] ?? '—') ?></strong></span>
            <span>Created:<strong>
        <?= !empty($so['created_date']) ? date('F j, Y g:i A', strtotime($so['created_date'])) : '—' ?>
    </strong>
</span>
            <?php if (!empty($so['updated_by'])): ?>
                <span>Updated by: <strong><?= htmlspecialchars($so['updated_by']) ?></strong></span>
                <span>Updated: <strong><?= htmlspecialchars($so['updated_at']) ?></strong></span>
            <?php endif; ?>
        </div>

        <?php if ($so['status'] === 'For Approval'): ?>
        <div class="readonly-banner">
            <i data-lucide="lock" style="width:16px;height:16px;"></i>
            This Sales Order has been submitted for approval and is read-only. Use <strong>Revert to Draft</strong> to make changes.
        </div>
        <?php endif; ?>

        <!-- Order Information -->
        <div class="form-card <?= ($so['status'] === 'For Approval') ? 'readonly-mode' : '' ?>">
            <div class="form-card-title"><i data-lucide="info" style="width:16px;height:16px;"></i> Order Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Customer</label>
                    <select id="customer-select">
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($customers as $cust):
                            $sel = ($cust['customer_code'] === $so['customer_code']) ? 'selected' : '';
                        ?>
                            <option value="<?= htmlspecialchars($cust['customer_code']) ?>"
                                    data-name="<?= htmlspecialchars($cust['full_name']) ?>"
                                    data-contact="<?= htmlspecialchars($cust['contact_person'] ?? '') ?>"
                                    data-full-address="<?= htmlspecialchars($cust['full_address'] ?? '') ?>"
                                    <?= $sel ?>>
                                <?= htmlspecialchars($cust['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Customer Code</label><input type="text" id="customer-code" value="<?= htmlspecialchars($so['customer_code'] ?? '') ?>" readonly></div>
                <div class="form-group"><label>Order Date</label><input type="date" id="order-date" value="<?= htmlspecialchars($so['order_date'] ?? '') ?>"></div>
                <div class="form-group"><label>Delivery Date</label><input type="date" id="delivery-date" value="<?= htmlspecialchars($so['delivery_date'] ?? '') ?>"></div>
                <div class="form-group"><label>Payment Terms</label><input type="text" id="payment-terms" value="<?= htmlspecialchars($so['payment_terms'] ?? '') ?>" placeholder="Enter payment terms"></div>

                <!-- Truck selects — populated by JS after fetching trucks for this customer -->
                <div class="form-group"><label>Truck Code</label>
                    <select id="truck-code"><option value="">-- Select Truck --</option></select>
                </div>
                <div class="form-group"><label>Plate Number</label>
                    <select id="plate-number"><option value="">-- Select Plate --</option></select>
                </div>
                <div class="form-group"><label>Assigned Truck</label>
                    <select id="assigned-truck"><option value="">-- Select Assigned Truck --</option></select>
                </div>

                <!-- Driver Information - Display Only -->
                <div class="form-group">
                    <label>Driver Code</label>
                    <input type="text" id="driver-code" value="<?= htmlspecialchars($driver_code) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Driver Name</label>
                    <input type="text" id="driver-name" value="<?= htmlspecialchars($driver_display_name) ?>" readonly style="font-weight:600;color:var(--accent-blue);">
                </div>

                <div class="form-group"><label>Sales Rep</label><input type="text" id="sales-rep" value="<?= htmlspecialchars($sales_rep_val) ?>" readonly placeholder="Auto-filled."></div>
                <div class="form-group"><label>Destination From</label><input type="text" id="destination-from" value="<?= htmlspecialchars($so['destination_from'] ?? '') ?>" readonly></div>
                <div class="form-group"><label>Destination To</label><input type="text" id="destination-to" value="<?= htmlspecialchars($so['destination_to'] ?? '') ?>" readonly></div>
                <div class="form-group full-width"><label>Delivery Address</label>
                    <textarea id="delivery-address" readonly><?= htmlspecialchars($so['delivery_address'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <div class="form-card <?= ($so['status'] === 'For Approval') ? 'readonly-mode' : '' ?>">
            <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="package" style="width:16px;height:16px;"></i> Line Items</div>
            <div class="table-wrapper">
                 <table>
                    <thead> <tr><th>#</th><th>Item Description</th><th>Unit</th><th>Qty</th><th>Unit Price</th><th>Discount (%)</th><th>Amount</th> </thead>
                    <tbody id="items-body">
                         <tr>
                            <td style="color:var(--text-muted);font-weight:600;">1</td>
                            <?php
                                $item_desc = '';
                                if (!empty($so['truck_code'])) {
                                    $parts = [$so['truck_code']];
                                    if (!empty($so['brand'])) $parts[] = $so['brand'];
                                    if (!empty($so['model'])) $parts[] = $so['model'];
                                    $item_desc = implode(' - ', $parts);
                                }
                            ?>
                            <td><input type="text" id="item-description" value="<?= htmlspecialchars($item_desc) ?>" readonly style="width:100%;background:#f1f5f9;font-weight:500;"></td>
                            <td><input type="text" id="item-unit" value="<?= htmlspecialchars($so['unit'] ?? 'LOT') ?>" style="width:70px;text-align:center;"></td>
                            <td><input type="number" id="item-qty" value="<?= htmlspecialchars($so['quantity'] ?? 1) ?>" min="1" style="width:70px;text-align:center;" class="qty-input" readonly></td>
                            <td><input type="number" id="item-price" value="<?= htmlspecialchars($so['unit_price'] ?? '0.00') ?>" min="0" step="0.01" style="width:110px;text-align:right;" class="price-input"></td>
                            <td><input type="number" id="item-disc" value="<?= htmlspecialchars($so['discount_percent'] ?? '0') ?>" min="0" max="100" style="width:80px;text-align:center;" class="disc-input"></td>
                            <td class="amount-cell" style="font-weight:600;text-align:right;">₱0.00</td>
                         </tr>
                    </tbody>
                 </table>
            </div>
        </div>

        <!-- Totals + Notes -->
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="form-card <?= ($so['status'] === 'For Approval') ? 'readonly-mode' : '' ?>" style="flex:1;min-width:260px;">
                <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Notes & Instructions</div>
                <textarea id="notes-field" style="width:100%;min-height:100px;"><?= htmlspecialchars($so['notes'] ?? '') ?></textarea>
            </div>
            <div class="totals-box">
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">Subtotal</span><span id="subtotal">₱0.00</span></div>
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">Discount</span><span id="discount">−₱0.00</span></div>
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">VAT (<?= htmlspecialchars($so['vat_percent'] ?? 12) ?>%)</span><span id="vat">₱0.00</span></div>
                <div class="totals-row total-final"><span>Total Due</span><span id="grand-total">₱0.00</span></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="actions-row" style="margin-top:32px;">
            <a href="sales_order.php" class="btn-secondary"><i data-lucide="arrow-left"></i> Back to New SO</a>
            <a href="sales_order_print.php?so=<?= htmlspecialchars($so_no) ?>" target="_blank" class="btn-secondary"><i data-lucide="printer"></i> Print SO</a>
            <?php if ($so['status'] === 'Draft'): ?>
                <button class="btn-secondary" id="btn-save-draft"><i data-lucide="save"></i> Save Changes</button>
                <button class="btn-primary" id="btn-submit"><i data-lucide="send"></i> Submit For Approval</button>
            <?php elseif ($so['status'] === 'For Approval'): ?>
                <button class="btn-secondary" id="btn-revert"><i data-lucide="rotate-ccw"></i> Revert to Draft</button>
            <?php endif; ?>
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

// ── Prefilled data from PHP ───────────────────────────────────────────────────
const PREFILL = {
    customer_code: <?= json_encode($so['customer_code'] ?? '') ?>,
    truck_code:    <?= json_encode($so['truck_code']    ?? '') ?>,
    plate_number:  <?= json_encode($so['plate_number']  ?? '') ?>,
    brand:         <?= json_encode($so['brand']         ?? '') ?>,
    model:         <?= json_encode($so['model']         ?? '') ?>,
    so_no:         <?= json_encode($so['sales_order_no']) ?>,
    vat_percent:   <?= json_encode((float)($so['vat_percent'] ?? 12)) ?>,
    item_description: <?= json_encode($item_desc) ?>,
    driver_code:   <?= json_encode($so['driver'] ?? '') ?>,
    driver_name:   <?= json_encode($so['driver_full_name'] ?? 'Not Assigned') ?>
};

// ── Formatting ────────────────────────────────────────────────────────────────
function formatPHP(val) {
    return '₱' + parseFloat(val || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function calcRow() {
    const row = document.querySelector('#items-body tr'); if (!row) return { sub: 0, discAmt: 0 };
    const qty   = parseFloat(row.querySelector('.qty-input')?.value)   || 0;
    const price = parseFloat(row.querySelector('.price-input')?.value) || 0;
    const disc  = parseFloat(row.querySelector('.disc-input')?.value)  || 0;
    const sub = qty * price, discAmt = sub * (disc / 100);
    const cell = row.querySelector('.amount-cell'); if (cell) cell.textContent = formatPHP(sub - discAmt);
    return { sub, discAmt };
}
function calcTotals() {
    const r = calcRow(), net = r.sub - r.discAmt, vat = net * (PREFILL.vat_percent / 100);
    document.getElementById('subtotal').textContent    = formatPHP(r.sub);
    document.getElementById('discount').textContent    = '−' + formatPHP(r.discAmt);
    document.getElementById('vat').textContent         = formatPHP(vat);
    document.getElementById('grand-total').textContent = formatPHP(net + vat);
}
document.getElementById('items-body').addEventListener('input', calcTotals);

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast'); t.textContent = msg; t.className = 'show ' + type;
    setTimeout(() => t.className = '', 3500);
}

// ── Collect payload ───────────────────────────────────────────────────────────
function collectPayload(status) {
    const row = document.querySelector('#items-body tr');
    const qty   = parseFloat(row?.querySelector('.qty-input')?.value)   || 0;
    const price = parseFloat(row?.querySelector('.price-input')?.value) || 0;
    const disc  = parseFloat(row?.querySelector('.disc-input')?.value)  || 0;
    const unit  = row?.querySelector('td:nth-child(3) input')?.value    || '';
    const sub = qty * price, discAmt = sub * (disc / 100);
    const custSel = document.getElementById('customer-select');
    const custOpt = custSel.selectedOptions[0];
    return {
        so_no:            PREFILL.so_no,
        customer_code:    custSel.value,
        customer_name:    custOpt?.dataset.name || '',
        sales_rep:        document.getElementById('sales-rep').value,
        truck_code:       document.getElementById('truck-code').value,
        plate_number:     document.getElementById('plate-number').value,
        brand:            selectedTruckInfo?.brand || PREFILL.brand,
        model:            selectedTruckInfo?.model || PREFILL.model,
        unit,
        destination_from: document.getElementById('destination-from').value,
        destination_to:   document.getElementById('destination-to').value,
        order_date:       document.getElementById('order-date').value,
        delivery_date:    document.getElementById('delivery-date').value,
        delivery_address: document.getElementById('delivery-address').value,
        payment_terms:    document.getElementById('payment-terms').value,
        vat_percent:      PREFILL.vat_percent,
        unit_price:       price, discount_percent: disc,
        amount:           sub - discAmt, discount_amount: discAmt, quantity: qty,
        notes:            document.getElementById('notes-field').value,
        driver:           PREFILL.driver_code,
        status
    };
}

// Save/update — calls update_sales_order.php
async function updateSO(status) {
    const payload = collectPayload(status);
    if (!payload.customer_code) { showToast('Please select a customer.', 'error'); return; }
    document.querySelectorAll('#btn-save-draft, #btn-submit, #btn-revert').forEach(b => { if (b) b.disabled = true; });
    try {
        const res  = await fetch('update_sales_order.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) {
            const badge = document.getElementById('status-badge');
            if (status === 'Draft') { badge.className = 'status-draft'; badge.textContent = 'Draft'; }
            else { badge.className = 'status-approval'; badge.textContent = 'For Approval'; }
            showToast(`${PREFILL.so_no} updated — ${status}`, 'success');
            // Reload after short delay so action buttons reflect new status
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast('Error: ' + (data.message || 'Update failed.'), 'error');
            document.querySelectorAll('#btn-save-draft, #btn-submit, #btn-revert').forEach(b => { if (b) b.disabled = false; });
        }
    } catch { showToast('Network error. Please try again.', 'error'); document.querySelectorAll('#btn-save-draft, #btn-submit, #btn-revert').forEach(b => { if (b) b.disabled = false; }); }
}

document.getElementById('btn-save-draft')?.addEventListener('click', () => updateSO('Draft'));
document.getElementById('btn-submit')?.addEventListener('click',     () => updateSO('For Approval'));
document.getElementById('btn-revert')?.addEventListener('click',     () => updateSO('Draft'));

// ── Customer + Truck Logic ────────────────────────────────────────────────────
let currentCustomerCode = PREFILL.customer_code;
let currentTrucks = [], selectedTruckInfo = null;

const els = {
    customer: document.getElementById('customer-select'), code: document.getElementById('customer-code'),
    rep: document.getElementById('sales-rep'), truckCode: document.getElementById('truck-code'),
    plate: document.getElementById('plate-number'), assigned: document.getElementById('assigned-truck'),
    destFrom: document.getElementById('destination-from'), destTo: document.getElementById('destination-to'),
    deliveryAddr: document.getElementById('delivery-address')
};

function populateTrucks(trucks) {
    els.truckCode.innerHTML = '<option value="">-- Select Truck --</option>';
    els.plate.innerHTML     = '<option value="">-- Select Plate --</option>';
    els.assigned.innerHTML  = '<option value="">-- Select Assigned Truck --</option>';
    trucks.forEach(t => {
        const selT = t.truck_code   === PREFILL.truck_code   ? 'selected' : '';
        const selP = t.plate_number === PREFILL.plate_number ? 'selected' : '';
        const selA = t.truck_code   === PREFILL.truck_code   ? 'selected' : '';
        els.truckCode.insertAdjacentHTML('beforeend', `<option value="${t.truck_code}" ${selT}>${t.truck_code}</option>`);
        els.plate.insertAdjacentHTML('beforeend',     `<option value="${t.plate_number}" ${selP}>${t.plate_number}</option>`);
        els.assigned.insertAdjacentHTML('beforeend',  `<option value="${t.truck_code}" ${selA}>${t.brand} [${t.model}]</option>`);
        if (t.truck_code === PREFILL.truck_code) {
            selectedTruckInfo = { truck_code: t.truck_code, brand: t.brand, model: t.model, plate_number: t.plate_number };
        }
    });
}

function clearZones() { els.destFrom.value = ''; els.destTo.value = ''; }

function fetchZones(tc, callback) {
    if (!currentCustomerCode || !tc) return clearZones();
    fetch(`get_customer_truck_zones.php?customer_code=${encodeURIComponent(currentCustomerCode)}&truck_code=${encodeURIComponent(tc)}`)
        .then(r => r.json()).then(d => {
            // Only overwrite if zones not already set from DB
            if (!els.destFrom.value) els.destFrom.value = d?.zone_from || '';
            if (!els.destTo.value)   els.destTo.value   = d?.zone_to   || '';
            if (callback) callback();
        }).catch(() => { if (callback) callback(); });
}

function syncTruckFields(source) {
    let code = '';
    if (source === 'truck')     code = els.truckCode.value;
    else if (source === 'plate')     code = currentTrucks.find(t => t.plate_number === els.plate.value)?.truck_code || '';
    else if (source === 'assigned')  code = els.assigned.value;
    if (code && currentTrucks.length) {
        const truck = currentTrucks.find(t => t.truck_code === code);
        if (truck) {
            els.truckCode.value = truck.truck_code; els.plate.value = truck.plate_number; els.assigned.value = truck.truck_code;
            selectedTruckInfo = { truck_code: truck.truck_code, brand: truck.brand, model: truck.model, plate_number: truck.plate_number };
            fetchZones(truck.truck_code);
        }
    }
}

els.customer.addEventListener('change', function () {
    currentCustomerCode = this.value.trim();
    if (!currentCustomerCode) { els.code.value = ''; els.rep.value = ''; if (els.deliveryAddr) els.deliveryAddr.value = ''; currentTrucks = []; selectedTruckInfo = null; populateTrucks([]); return; }
    els.code.value = currentCustomerCode; els.rep.value = this.selectedOptions[0]?.dataset.contact || '';
    if (els.deliveryAddr && !els.deliveryAddr.value) els.deliveryAddr.value = this.selectedOptions[0]?.dataset.fullAddress?.trim() || '';
    fetch(`get_customer_trucks.php?customer_code=${encodeURIComponent(currentCustomerCode)}`)
        .then(r => r.json()).then(data => { currentTrucks = Array.isArray(data) ? data : []; populateTrucks(currentTrucks); })
        .catch(() => { currentTrucks = []; populateTrucks([]); });
});

els.truckCode.addEventListener('change', () => syncTruckFields('truck'));
els.plate.addEventListener('change',     () => syncTruckFields('plate'));
els.assigned.addEventListener('change',  () => syncTruckFields('assigned'));

// ── Auto-load trucks for pre-filled customer on page load ─────────────────────
if (PREFILL.customer_code) {
    fetch(`get_customer_trucks.php?customer_code=${encodeURIComponent(PREFILL.customer_code)}`)
        .then(r => r.json())
        .then(data => {
            currentTrucks = Array.isArray(data) ? data : [];
            populateTrucks(currentTrucks);
            calcTotals(); // recalc after everything is ready
        })
        .catch(() => calcTotals());
} else {
    calcTotals();
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && modal?.style.display === 'flex') {
        closeModal();
    }
});
</script>
</body>
</html>