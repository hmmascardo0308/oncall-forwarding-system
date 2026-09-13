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
$full_name  = $_SESSION['full_name'] ?? $username;

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
            <span>Created: <strong><?= !empty($so['created_date']) ? date('F j, Y g:i A', strtotime($so['created_date'])) : '—' ?></strong></span>
            <?php if (!empty($so['updated_by'])): ?>
                <span>Updated by: <strong><?= htmlspecialchars($so['updated_by']) ?></strong></span>
                <span>Updated: <strong><?= htmlspecialchars($so['updated_at']) ?></strong></span>
            <?php endif; ?>
        </div>

        <?php if ($so['status'] === 'For Approval'): ?>
        <div class="readonly-banner">
            <i data-lucide="lock" style="width:16px;height:16px;"></i>
            This Sales Order has been submitted for approval and is read-only.
        </div>
        <?php endif; ?>

        <!-- Order Information -->
        <div class="form-card">
            <div class="form-card-title"><i data-lucide="info" style="width:16px;height:16px;"></i> Order Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Customer</label>
                    <select id="customer-select" disabled>
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
                <div class="form-group"><label>Order Date</label><input type="date" id="order-date" value="<?= htmlspecialchars($so['order_date'] ?? '') ?>" readonly></div>
                <div class="form-group"><label>Delivery Date</label><input type="date" id="delivery-date" value="<?= htmlspecialchars($so['delivery_date'] ?? '') ?>" readonly></div>
                <div class="form-group"><label>Payment Terms</label><input type="text" id="payment-terms" value="<?= htmlspecialchars($so['payment_terms'] ?? '') ?>" readonly placeholder="Enter payment terms"></div>

                <!-- Truck selects — populated by JS after fetching trucks for this customer -->
                <div class="form-group"><label>Truck Code</label>
                    <select id="truck-code" disabled><option value="">-- Select Truck --</option></select>
                </div>
                <div class="form-group"><label>Plate Number</label>
                    <select id="plate-number" disabled><option value="">-- Select Plate --</option></select>
                </div>
                <div class="form-group"><label>Assigned Truck</label>
                    <select id="assigned-truck" disabled><option value="">-- Select Assigned Truck --</option></select>
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
                <div class="form-group">
                    <label>Container Number <span style="color:#007155;font-size:11px;font-weight:600;">(editable)</span></label>
                    <input type="text" id="container-number" value="<?= htmlspecialchars($so['container_number'] ?? '') ?>" placeholder="Enter container number" style="font-weight:600;color:#007155;border:1px solid #007155;">
                </div>
                <div class="form-group full-width"><label>Delivery Address</label>
                    <textarea id="delivery-address" readonly><?= htmlspecialchars($so['delivery_address'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <div class="form-card">
            <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="package" style="width:16px;height:16px;"></i> Line Items</div>
            <div class="table-wrapper">
                 <table>
                    <thead> <tr><th>#</th><th>Item Description</th><th>Unit</th><th>Qty</th><th>Unit Price</th><th>Discount (%)</th><th>Amount</th></tr> </thead>
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
                            <td><input type="text" id="item-unit" value="<?= htmlspecialchars($so['unit'] ?? 'LOT') ?>" style="width:70px;text-align:center;" readonly></td>
                            <td><input type="number" id="item-qty" value="<?= htmlspecialchars($so['quantity'] ?? 1) ?>" min="1" style="width:70px;text-align:center;" class="qty-input" readonly></td>
                            <td><input type="number" id="item-price" value="<?= htmlspecialchars($so['unit_price'] ?? '0.00') ?>" min="0" step="0.01" style="width:110px;text-align:right;" class="price-input" readonly></td>
                            <td><input type="number" id="item-disc" value="<?= htmlspecialchars($so['discount_percent'] ?? '0') ?>" min="0" max="100" style="width:80px;text-align:center;" class="disc-input" readonly></td>
                            <td class="amount-cell" style="font-weight:600;text-align:right;">₱0.00</td>
                         </tr>
                    </tbody>
                 </table>
            </div>
        </div>

        <!-- Totals + Notes -->
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
            <div class="form-card" style="flex:1;min-width:260px;">
                <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Notes & Instructions</div>
                <textarea id="notes-field" style="width:100%;min-height:100px;" readonly><?= htmlspecialchars($so['notes'] ?? '') ?></textarea>
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
            <button class="btn-primary" id="btn-save-container"><i data-lucide="save"></i> Save Container Number</button>
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
    if (userRoles.includes('admin')) return true;
    for (let role of userRoles) {
        if (allowedPages[role] && allowedPages[role].includes(page)) {
            return true;
        }
    }
    modal.style.display = 'flex';
    return false;
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
    customer_code:    <?= json_encode($so['customer_code'] ?? '') ?>,
    truck_code:       <?= json_encode($so['truck_code']    ?? '') ?>,
    plate_number:     <?= json_encode($so['plate_number']  ?? '') ?>,
    brand:            <?= json_encode($so['brand']         ?? '') ?>,
    model:            <?= json_encode($so['model']         ?? '') ?>,
    so_no:            <?= json_encode($so['sales_order_no']) ?>,
    vat_percent:      <?= json_encode((float)($so['vat_percent'] ?? 12)) ?>,
    item_description: <?= json_encode($item_desc) ?>,
    driver_code:      <?= json_encode($so['driver'] ?? '') ?>,
    driver_name:      <?= json_encode($so['driver_full_name'] ?? 'Not Assigned') ?>,
    container_number: <?= json_encode($so['container_number'] ?? '') ?>
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

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    setTimeout(() => t.className = '', 3500);
}

// ── Save Container Number ─────────────────────────────────────────────────────
async function saveContainerNumber() {
    const containerNumber = document.getElementById('container-number').value.trim();
    const btn = document.getElementById('btn-save-container');

    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite;"></i> Saving...';
    lucide.createIcons();

    try {
        const res = await fetch('update_container_number.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                so_no: PREFILL.so_no,
                container_number: containerNumber
            })
        });
        const data = await res.json();

        if (data.success) {
            showToast('Container number saved successfully.', 'success');
            PREFILL.container_number = containerNumber;
        } else {
            showToast('Error: ' + (data.message || 'Failed to save.'), 'error');
        }
    } catch (error) {
        console.error('Save error:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Save Container Number';
        lucide.createIcons();
    }
}

document.getElementById('btn-save-container')?.addEventListener('click', saveContainerNumber);

// Allow Enter key in container-number field to trigger save
document.getElementById('container-number')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        saveContainerNumber();
    }
});

// ── Populate Truck selects (read-only, just display) ─────────────────────────
const els = {
    truckCode: document.getElementById('truck-code'),
    plate:     document.getElementById('plate-number'),
    assigned:  document.getElementById('assigned-truck')
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
    });
}

// ── Auto-load trucks for pre-filled customer on page load ─────────────────────
if (PREFILL.customer_code) {
    fetch(`get_customer_trucks.php?customer_code=${encodeURIComponent(PREFILL.customer_code)}`)
        .then(r => r.json())
        .then(data => {
            populateTrucks(Array.isArray(data) ? data : []);
            calcTotals();
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

<style>
/* ── Toast Notification ──────────────────────────────────────────────────── */
#toast {
    position: fixed;
    top: 30px;
    right: 30px;
    padding: 14px 24px;
    border-radius: 10px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
    background: #10b981;
    box-shadow: 0 10px 25px rgba(16, 185, 129, 0.25), 0 4px 10px rgba(0, 0, 0, 0.08);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-20px) scale(0.95);
    transition: opacity 0.35s ease, transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), visibility 0.35s;
    z-index: 9999;
    max-width: 380px;
    line-height: 1.4;
    display: flex;
    align-items: center;
    gap: 10px;
    pointer-events: none;
}
#toast::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ffffff;
    flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
}
#toast.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
}
#toast.show.success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    box-shadow: 0 10px 25px rgba(16, 185, 129, 0.35), 0 4px 10px rgba(0, 0, 0, 0.1);
}
#toast.show.error {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    box-shadow: 0 10px 25px rgba(239, 68, 68, 0.35), 0 4px 10px rgba(0, 0, 0, 0.1);
}

/* ── Container Number Field ──────────────────────────────────────────────── */
#container-number {
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}
#container-number:focus {
    outline: none;
    border-color: #007155;
    box-shadow: 0 0 0 3px rgba(0, 113, 85, 0.15);
    background: #ffffff;
}

/* ── Save Button Loading State ───────────────────────────────────────────── */
#btn-save-container:disabled {
    opacity: 0.75;
    cursor: not-allowed;
    transform: none;
}
#btn-save-container i[data-lucide="loader"] {
    animation: spin 1s linear infinite;
}

/* ── Spin Animation ──────────────────────────────────────────────────────── */
@keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 600px) {
    #toast {
        top: 16px;
        right: 16px;
        left: 16px;
        max-width: none;
        font-size: 13px;
        padding: 12px 18px;
    }
}
</style>
</body>
</html>