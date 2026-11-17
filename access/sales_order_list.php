<?php
// sales_order_list.php
session_start();
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

$can_access_so_list = $is_admin || in_array('sales_order_maker', $user_roles);

if (!$can_access_so_list) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Sales Order List page."
    ];
    header("Location: home.php");
    exit;
}

$role_display_name = getRoleDisplayName($user_roles);

// ── Load the requested SO ─────────────────────────────────────────────────────
$so_no = trim($_GET['so'] ?? '');
$so    = null;

if ($so_no) {
    $safe = $conn->real_escape_string($so_no);
    $res = $conn->query("
        SELECT so.*, 
               el.full_name as driver_full_name,
               el.employee_code as driver_code,
               el.profile_picture as driver_profile_picture,
               tm.brand as tm_brand,
               tm.model as tm_model,
               tm.truck_type as tm_truck_type,
               tm.wheel_count as tm_wheel_count,
               tm.footer as tm_footer,
               tm.body_type as tm_body_type
        FROM `oncall_forwarding`.`sales_order` so
        LEFT JOIN `oncall_forwarding`.`employee_list` el 
            ON so.driver = el.employee_code COLLATE utf8mb4_general_ci
        LEFT JOIN `oncall_forwarding`.`truck_masterlist` tm
            ON so.truck_code = tm.truck_code COLLATE utf8mb4_general_ci
        WHERE so.sales_order_no = '{$safe}' 
        LIMIT 1
    ");
    if ($res) $so = $res->fetch_assoc();
}

if (!$so) {
    header("Location: sales_order.php");
    exit;
}

// ── Fetch special charges (include charge_vat) ────────────────────────────────
$special_charges       = [];
$special_charges_total = 0.0;
$special_charges_vat_total = 0.0;

$safe_so    = $conn->real_escape_string($so_no);
$charge_res = $conn->query("
    SELECT charge_kind, charge_amount, charge_vat
    FROM `oncall_forwarding`.`sales_order_special_charge`
    WHERE sales_order_no = '{$safe_so}'
    ORDER BY id ASC
");
if ($charge_res) {
    while ($row = $charge_res->fetch_assoc()) {
        $amount = floatval($row['charge_amount']);
        $vat    = ($row['charge_vat'] !== null && $row['charge_vat'] !== '')
                  ? floatval($row['charge_vat'])
                  : 0.0;
        $special_charges[] = [
            'kind'   => $row['charge_kind'],
            'amount' => $amount,
            'vat'    => $vat
        ];
        $special_charges_total     += $amount;
        $special_charges_vat_total += $vat;
    }
}

// special_charges_vat from the SO header (12 or NULL)
$sc_vat_is_enabled = ($so['special_charges_vat'] !== null && $so['special_charges_vat'] !== '');
$sc_vat_percent    = $sc_vat_is_enabled ? floatval($so['special_charges_vat']) : 0.0;

// ── Fetch customers (for Sales Rep fallback) ──────────────────────────────────
$customers = [];
$cr = $conn->query("SELECT full_name, customer_code, contact_person, full_address FROM `oncall_forwarding`.`customer_masterlist` ORDER BY full_name ASC");
if ($cr) while ($row = $cr->fetch_assoc()) $customers[] = $row;

// ── Status badge helper ───────────────────────────────────────────────────────
$status      = htmlspecialchars($so['status'] ?? 'Draft');
$statusClass = ($so['status'] === 'For Approval') ? 'status-approval' : 'status-draft';

// ── Sales Rep fallback ────────────────────────────────────────────────────────
$sales_rep_val = $so['sales_rep'] ?? '';
if (empty($sales_rep_val) && !empty($so['customer_code'])) {
    foreach ($customers as $c) {
        if ($c['customer_code'] === $so['customer_code']) {
            $sales_rep_val = $c['contact_person'];
            break;
        }
    }
}

// ── Driver display ────────────────────────────────────────────────────────────
$driver_display_name = $so['driver_full_name'] ?? $so['driver'] ?? 'Not Assigned';
$driver_code         = $so['driver'] ?? '';
$driver_profile_pic  = $so['driver_profile_picture'] ?? '';

// ── Truck combo ──────────────────────────────────────────────────────────────
// Footer is read from sales_order.footer (the value the user priced against
// at creation time) — NOT from truck_masterlist, which can change over time.
// Fall back to tm_footer only for legacy rows saved before the footer fix.
$truck_type_display  = $so['truck_type'] ?? ($so['tm_truck_type'] ?? '');
$wheel_count_display = $so['tm_wheel_count'] ?? '';

$footer_display = $so['footer'] ?? '';
if ($footer_display === null || $footer_display === '') {
    $footer_display = $so['tm_footer'] ?? '';
}

$body_type_display   = $so['tm_body_type'] ?? '';

// ── VAT: NULL means NON-VAT ───────────────────────────────────────────────────
$vat_is_nonvat  = ($so['vat_percent'] === null || $so['vat_percent'] === '');
$vat_percent_val = $vat_is_nonvat ? 0.0 : (float)$so['vat_percent'];

// ── Delivery Receipt ──────────────────────────────────────────────────────────
$delivery_receipt_display = $so['delivery_receipt_no'] ?? '';

// ── Line item description ─────────────────────────────────────────────────────
$item_desc = '';
if (!empty($so['plate_number'])) {
    $parts = [$so['plate_number']];
    if (!empty($so['brand']))  $parts[] = $so['brand'];
    if (!empty($so['model']))  $parts[] = $so['model'];
    if (!empty($so['truck_type'])) $parts[] = '(' . $so['truck_type'] . ')';
    $item_desc = implode(' — ', $parts);
}
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
    <style>
        .driver-image-preview {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.55);
            z-index: 9999; align-items: center; justify-content: center;
            padding: 20px; backdrop-filter: blur(2px);
        }
        .driver-image-preview.active { display: flex; }
        .driver-image-preview-card {
            background: #ffffff; border-radius: 16px; padding: 20px;
            max-width: 340px; width: 100%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            position: relative; text-align: center;
            animation: driverPreviewPop 0.22s ease-out;
        }
        @keyframes driverPreviewPop {
            from { transform: scale(0.9); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }
        .driver-image-preview-close {
            position: absolute; top: 10px; right: 10px;
            width: 32px; height: 32px; border-radius: 50%; border: none;
            background: #f1f5f9; color: #475569; font-size: 20px;
            font-weight: 700; line-height: 1; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s ease;
        }
        .driver-image-preview-close:hover {
            background: #dc2626; color: #ffffff; transform: rotate(90deg);
        }
        .driver-image-preview-card img {
            width: 220px; height: 220px; object-fit: cover;
            border-radius: 12px; border: 3px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            display: block; margin: 8px auto 14px;
        }
        .driver-image-preview-name { font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
        .driver-image-preview-code { font-size: 12px; color: #64748b; letter-spacing: 0.5px; }
        .driver-image-preview-placeholder {
            width: 220px; height: 220px; margin: 8px auto 14px;
            border-radius: 12px; border: 3px dashed #cbd5e1;
            background: #f8fafc;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: #94a3b8; gap: 8px;
        }
        .driver-image-preview-placeholder i { width: 56px; height: 56px; }
        .driver-image-preview-placeholder span { font-size: 12px; font-weight: 500; }
        .driver-image-preview-label {
            font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
            color: #94a3b8; font-weight: 600; margin-bottom: 4px;
        }

        .driver-field-wrap { display: flex; gap: 8px; align-items: stretch; }
        .driver-field-wrap input { flex: 1; min-width: 0; }
        .view-driver-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
            padding: 0 12px; height: 40px; border-radius: 8px;
            border: 1.5px solid var(--accent-blue, #2563eb);
            background: #eff6ff; color: var(--accent-blue, #2563eb);
            font-size: 12px; font-weight: 600; cursor: pointer;
            white-space: nowrap; transition: all 0.15s ease;
            font-family: inherit;
        }
        .view-driver-btn:hover:not(:disabled) {
            background: var(--accent-blue, #2563eb); color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }
        .view-driver-btn:disabled {
            opacity: 0.45; cursor: not-allowed;
            background: #f1f5f9; border-color: #cbd5e1; color: #94a3b8;
        }
        .view-driver-btn i { width: 14px; height: 14px; }

        .special-charges-title {
            font-size: 15px; font-weight: 800; color: black;
            margin-top: 20px; margin-bottom: 12px;
            display: flex; align-items: center; gap: 6px;
        }
        .special-charges-title i { width: 16px; height: 16px; }
        .special-charge-amount { text-align: right; font-weight: 700; color: #b45309; white-space: nowrap; }
        .special-charge-row td { background: #fffbeb; }
        .special-charges-total-row td {
            background: #fef3c7; font-weight: 800; color: #92400e;
            border-top: 2px solid #fcd34d;
        }
        .special-charges-banner {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 14px; background: #fffbeb;
            border: 1px solid #fcd34d; border-radius: 8px;
            color: #92400e; font-size: 13px; font-weight: 500;
            margin-bottom: 16px;
        }
        .special-charges-banner i { width: 16px; height: 16px; flex-shrink: 0; }
        .special-charges-banner strong { font-weight: 800; }

        /* ===== Delivery Receipt read-only field ===== */
        .dr-display-wrap {
            display: flex; align-items: stretch;
            border: 1px solid #cbd5e1; border-radius: 6px;
            overflow: hidden; background: #f8fafc;
        }
        .dr-display-prefix {
            display: flex; align-items: center;
            padding: 0 10px; background: #f1f5f9;
            color: #334155; font-weight: 600; font-size: 13.5px;
            border-right: 1px solid #e2e8f0; white-space: nowrap;
            user-select: none;
        }
        .dr-display-wrap input {
            flex: 1; border: none !important; outline: none !important;
            padding: 8px 10px; font-family: inherit; font-size: 14px;
            background: transparent; color: #0f172a; min-width: 0;
        }

        /* ===== NON-VAT badge ===== */
        .nonvat-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 12px;
            background: #f1f5f9; color: #475569;
            font-size: 11px; font-weight: 700;
            letter-spacing: 0.3px; text-transform: uppercase;
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
                <?php if ($vat_is_nonvat): ?>
                    <span class="nonvat-badge"><i data-lucide="minus-circle" style="width:12px;height:12px;"></i> Non-VAT</span>
                <?php endif; ?>
                <span class="<?= $statusClass ?>" id="status-badge"><?= $status ?></span>
            </div>
        </div>

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

        <?php if ($special_charges_total > 0): ?>
        <div class="special-charges-banner">
            <i data-lucide="receipt"></i>
            This Sales Order includes <strong><?= count($special_charges) ?></strong> special charge(s) totaling
            <strong><?= '₱' . number_format($special_charges_total, 2) ?></strong>
            <?php if ($special_charges_vat_total > 0): ?>
                + VAT <strong><?= '₱' . number_format($special_charges_vat_total, 2) ?></strong>
            <?php endif; ?>.
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
                <div class="form-group"><label>Payment Terms</label><input type="text" id="payment-terms" value="<?= htmlspecialchars($so['payment_terms'] ?? '') ?>" readonly></div>

                <!-- VAT status -->
                <div class="form-group">
                    <label>VAT</label>
                    <input type="text"
                           value="<?= $vat_is_nonvat ? 'NON-VAT' : 'VAT ' . number_format($vat_percent_val, 0) . '%' ?>"
                           readonly
                           style="font-weight:600; color: <?= $vat_is_nonvat ? '#64748b' : '#0f172a' ?>;">
                </div>

                <!-- Delivery Receipt No -->
                <div class="form-group">
                    <label>Delivery Receipt No</label>
                    <div class="dr-display-wrap">
                        <span class="dr-display-prefix">DR# -</span>
                        <input type="text"
                               id="delivery-receipt-no"
                               value="<?= htmlspecialchars(preg_replace('/^DR#\s*-\s*/i', '', $delivery_receipt_display)) ?>"
                               placeholder="—"
                               readonly>
                    </div>
                </div>

                <!-- Truck combo (read-only, from the saved SO) -->
                <div class="form-group"><label>Truck Type</label><input type="text" id="truck-type" value="<?= htmlspecialchars($truck_type_display) ?>" readonly></div>
                <div class="form-group"><label>Wheel Count</label><input type="text" id="wheel-count" value="<?= htmlspecialchars($wheel_count_display) ?>" readonly></div>
                <div class="form-group"><label>Footer</label><input type="text" id="footer" value="<?= htmlspecialchars($footer_display) ?>" readonly></div>
                <div class="form-group"><label>Body Type</label><input type="text" id="body-type" value="<?= htmlspecialchars($body_type_display) ?>" readonly></div>

                <!-- Driver Information -->
                <div class="form-group">
                    <label>Driver Code</label>
                    <input type="text" id="driver-code" value="<?= htmlspecialchars($driver_code) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Driver Name</label>
                    <div class="driver-field-wrap">
                        <input type="text" id="driver-name" value="<?= htmlspecialchars($driver_display_name) ?>" readonly style="font-weight:600;color:var(--accent-blue);">
                        <button type="button" class="view-driver-btn" id="viewDriverBtn" <?= empty($driver_code) ? 'disabled' : '' ?> title="View driver's profile picture">
                            <i data-lucide="image"></i> View Driver
                        </button>
                    </div>
                </div>

                <div class="form-group"><label>Sales Rep</label><input type="text" id="sales-rep" value="<?= htmlspecialchars($sales_rep_val) ?>" readonly></div>
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
            <div class="form-card-title" style="font-size: 15px; font-weight: 800; color: black;">
                <i data-lucide="package" style="width:16px;height:16px;"></i> Line Items
            </div>
            <div class="table-wrapper">
                 <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th style="min-width:280px;">Truck</th>
                            <th>Unit</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Discount (%)</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                         <tr>
                            <td style="color:var(--text-muted);font-weight:600;">1</td>
                            <td>
                                <input type="text"
                                       id="item-description"
                                       value="<?= htmlspecialchars($item_desc) ?>"
                                       readonly
                                       style="width:100%;background:#f1f5f9;font-weight:500;">
                            </td>
                            <td><input type="text" id="item-unit" value="<?= htmlspecialchars($so['unit'] ?? 'TRIP') ?>" style="width:70px;text-align:center;" readonly></td>
                            <td><input type="number" id="item-qty" value="<?= htmlspecialchars($so['quantity'] ?? 1) ?>" min="1" style="width:70px;text-align:center;" class="qty-input" readonly></td>
                            <td><input type="number" id="item-price" value="<?= htmlspecialchars($so['unit_price'] ?? '0.00') ?>" min="0" step="0.01" style="width:110px;text-align:right;" class="price-input" readonly></td>
                            <td><input type="number" id="item-disc" value="<?= htmlspecialchars($so['discount_percent'] ?? '0') ?>" min="0" max="100" style="width:80px;text-align:center;" class="disc-input" readonly></td>
                            <td class="amount-cell" style="font-weight:600;text-align:right;">₱0.00</td>
                         </tr>
                    </tbody>
                 </table>
            </div>

            <?php if (!empty($special_charges)): ?>
            <div class="special-charges-title">
                <i data-lucide="receipt"></i> Special Charges
                <?php if ($sc_vat_is_enabled): ?>
                    <span style="font-size:11px;font-weight:600;color:#b45309;background:#fef3c7;padding:3px 10px;border-radius:12px;margin-left:6px;">
                        VAT <?= number_format($sc_vat_percent, 0) ?>% applied
                    </span>
                <?php endif; ?>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Charge Description</th>
                            <th style="text-align:right;width:130px;">Amount</th>
                            <?php if ($sc_vat_is_enabled): ?>
                            <th style="text-align:right;width:110px;">VAT</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($special_charges as $idx => $charge): ?>
                        <tr class="special-charge-row">
                            <td style="color:var(--text-muted);font-weight:600;"><?= $idx + 1 ?></td>
                            <td>
                                <input type="text"
                                       value="<?= htmlspecialchars($charge['kind']) ?>"
                                       readonly
                                       style="width:100%;background:#fffbeb;font-weight:500;color:#92400e;border:1px solid #fcd34d;">
                            </td>
                            <td class="special-charge-amount">
                                <?= '₱' . number_format($charge['amount'], 2) ?>
                            </td>
                            <?php if ($sc_vat_is_enabled): ?>
                            <td class="special-charge-amount" style="color:#92400e;">
                                <?= '₱' . number_format($charge['vat'], 2) ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="special-charges-total-row">
                            <td colspan="<?= $sc_vat_is_enabled ? '2' : '2' ?>" style="text-align:right;">Total Special Charges</td>
                            <td class="special-charge-amount" style="color:#92400e;">
                                <?= '₱' . number_format($special_charges_total, 2) ?>
                            </td>
                            <?php if ($sc_vat_is_enabled): ?>
                            <td class="special-charge-amount" style="color:#92400e;">
                                <?= '₱' . number_format($special_charges_vat_total, 2) ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
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
                <?php if (!$vat_is_nonvat): ?>
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: black;">VAT (<?= number_format($vat_percent_val, 0) ?>%)</span><span id="vat">₱0.00</span></div>
                <?php else: ?>
                <div class="totals-row"><span style="font-size: 15px; font-weight: 800; color: #64748b;">VAT</span><span style="color:#64748b;font-weight:700;">NON-VAT</span></div>
                <?php endif; ?>
                <?php if ($special_charges_total > 0): ?>
                <div class="totals-row">
                    <span style="font-size: 15px; font-weight: 800; color: #b45309;">Special Charges</span>
                    <span id="special-charges" style="color:#b45309;font-weight:700;">₱0.00</span>
                </div>
                <?php if ($special_charges_vat_total > 0): ?>
                <div class="totals-row">
                    <span style="font-size: 15px; font-weight: 800; color: #b45309;">Special Charges VAT (<?= number_format($sc_vat_percent, 0) ?>%)</span>
                    <span id="special-charges-vat" style="color:#b45309;font-weight:700;">₱0.00</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="totals-row total-final"><span>Total Due</span><span id="grand-total">₱0.00</span></div>
            </div>
        </div>

        <div class="actions-row" style="margin-top:32px;">
            <a href="sales_order.php" class="btn-secondary"><i data-lucide="arrow-left"></i> Back to New SO</a>
            <a href="sales_order_print.php?so=<?= htmlspecialchars($so_no) ?>" target="_blank" class="btn-secondary"><i data-lucide="printer"></i> Print SO</a>
            <button class="btn-primary" id="btn-save-container"><i data-lucide="save"></i> Save Container Number</button>
        </div>
    </div>
</main>

<script>
lucide.createIcons();

const userRoles    = <?php echo json_encode($user_roles); ?>;
const allowedPages = <?php echo json_encode($allowed_pages); ?>;

const modal = document.getElementById('accessModal');
function closeModal() { modal.style.display = 'none'; }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

// ============================================================
// DRIVER PREVIEW
// ============================================================
const driverImagePreview        = document.getElementById('driverImagePreview');
const driverImagePreviewContent = document.getElementById('driverImagePreviewContent');
const driverImagePreviewName    = document.getElementById('driverImagePreviewName');
const driverImagePreviewCode    = document.getElementById('driverImagePreviewCode');
const viewDriverBtn             = document.getElementById('viewDriverBtn');

const DRIVER_DATA = {
    code: <?= json_encode($driver_code) ?>,
    name: <?= json_encode($driver_display_name) ?>,
    profilePicture: <?= json_encode($driver_profile_pic) ?>
};

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
    if (!DRIVER_DATA.code) return;
    openDriverImagePreview(DRIVER_DATA.code, DRIVER_DATA.name, DRIVER_DATA.profilePicture);
});
driverImagePreview?.addEventListener('click', e => { if (e.target === driverImagePreview) closeDriverImagePreview(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (driverImagePreview.classList.contains('active')) closeDriverImagePreview();
        else if (modal?.style.display === 'flex') closeModal();
    }
});

// ============================================================
// TOTALS
// ============================================================
// vat_percent: NULL means NON-VAT → treat as 0 for math
const PREFILL = {
    so_no:            <?= json_encode($so['sales_order_no']) ?>,
    vat_percent:      <?= json_encode($vat_percent_val) ?>,
    vat_is_nonvat:    <?= json_encode($vat_is_nonvat) ?>,
    container_number: <?= json_encode($so['container_number'] ?? '') ?>,
    special_charges_total: <?= json_encode($special_charges_total) ?>,
    special_charges_vat_total: <?= json_encode($special_charges_vat_total) ?>
};

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
    const r = calcRow();
    const net = r.sub - r.discAmt;
    const vat = PREFILL.vat_is_nonvat ? 0 : net * (PREFILL.vat_percent / 100);
    const special     = parseFloat(PREFILL.special_charges_total) || 0;
    const specialVat  = parseFloat(PREFILL.special_charges_vat_total) || 0;

    document.getElementById('subtotal').textContent = formatPHP(r.sub);
    document.getElementById('discount').textContent = '−' + formatPHP(r.discAmt);

    const vatEl = document.getElementById('vat');
    if (vatEl) vatEl.textContent = formatPHP(vat);

    const scEl = document.getElementById('special-charges');
    if (scEl) scEl.textContent = formatPHP(special);

    const scVatEl = document.getElementById('special-charges-vat');
    if (scVatEl) scVatEl.textContent = formatPHP(specialVat);

    document.getElementById('grand-total').textContent = formatPHP(net + vat + special + specialVat);
}
calcTotals();

// ============================================================
// TOAST
// ============================================================
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    setTimeout(() => t.className = '', 3500);
}

// ============================================================
// SAVE CONTAINER NUMBER
// ============================================================
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
            body: JSON.stringify({ so_no: PREFILL.so_no, container_number: containerNumber })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Container number saved successfully.', 'success');
            PREFILL.container_number = containerNumber;
        } else {
            showToast('Error: ' + (data.message || 'Failed to save.'), 'error');
        }
    } catch (err) {
        console.error('Save error:', err);
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Save Container Number';
        lucide.createIcons();
    }
}
document.getElementById('btn-save-container')?.addEventListener('click', saveContainerNumber);
document.getElementById('container-number')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); saveContainerNumber(); }
});
</script>
</body>
</html>