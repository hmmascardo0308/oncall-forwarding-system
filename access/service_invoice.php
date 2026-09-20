<?php
// service_invoice.php
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
$is_admin = in_array('admin', $user_roles);

$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

$role_display_name = getRoleDisplayName($user_roles);

// ============================================================
// FETCH SALES ORDERS (with discount_amount, amount, vat_percent, etc.)
// ============================================================
$sales_orders = [];
$filter_customer = isset($_GET['filter_customer']) ? $_GET['filter_customer'] : '';

if (!empty($filter_customer)) {
    $sql = "SELECT DISTINCT 
                so.sales_order_no, so.customer_name, so.customer_code, 
                so.order_date, so.delivery_date,
                so.truck_code, so.brand, so.model, so.plate_number, 
                so.unit_price, so.discount_percent, so.discount_amount, 
                so.vat_percent, so.amount, so.quantity,
                so.created_date, 
                so.destination_from, so.destination_to, so.delivery_address, 
                so.payment_terms
            FROM sales_order so
            WHERE so.status != 'cancelled' 
            AND so.customer_code = ? 
            ORDER BY so.customer_code, so.created_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $filter_customer);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $sales_orders[] = $row;
        }
    }
    $stmt->close();
}

// ============================================================
// FETCH SPECIAL CHARGES FOR EACH SO (bulk query)
// ============================================================
$special_charges_map = []; // keyed by sales_order_no

if (!empty($sales_orders)) {
    $so_numbers = array_column($sales_orders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $charge_query = "SELECT sales_order_no, charge_kind, charge_amount 
                         FROM sales_order_special_charge 
                         WHERE sales_order_no IN ($placeholders)";
        $stmt = $conn->prepare($charge_query);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($so_numbers)), ...$so_numbers);
            $stmt->execute();
            $charge_result = $stmt->get_result();
            while ($row = $charge_result->fetch_assoc()) {
                $so_no = $row['sales_order_no'];
                if (!isset($special_charges_map[$so_no])) {
                    $special_charges_map[$so_no] = [];
                }
                $special_charges_map[$so_no][] = [
                    'kind'   => $row['charge_kind'],
                    'amount' => floatval($row['charge_amount'])
                ];
            }
        }
    }
}

// ============================================================
// FETCH ALREADY-INVOICED SO NUMBERS
// Any SO that already exists in service_invoice cannot be invoiced again
// ============================================================
$invoiced_so_map = []; // keyed by sales_order_no => invoice_no

if (!empty($sales_orders)) {
    $so_numbers = array_column($sales_orders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $inv_check_sql = "SELECT sales_order_no, invoice_no 
                          FROM service_invoice 
                          WHERE sales_order_no IN ($placeholders)";
        $stmt = $conn->prepare($inv_check_sql);
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($so_numbers)), ...$so_numbers);
            $stmt->execute();
            $inv_check_result = $stmt->get_result();
            while ($row = $inv_check_result->fetch_assoc()) {
                // If multiple invoices exist for the same SO, keep the first one
                if (!isset($invoiced_so_map[$row['sales_order_no']])) {
                    $invoiced_so_map[$row['sales_order_no']] = $row['invoice_no'];
                }
            }
        }
    }
}

// Fetch existing service invoices for dropdown
$service_invoices = [];
$si_sql = "SELECT invoice_no, customer_name, MAX(created_date) as latest_date 
           FROM service_invoice 
           GROUP BY invoice_no, customer_name 
           ORDER BY latest_date DESC";
$si_result = $conn->query($si_sql);
if ($si_result && $si_result->num_rows > 0) {
    while ($row = $si_result->fetch_assoc()) {
        $service_invoices[] = $row;
    }
}

function generateInvoiceNo($conn) {
    $year = date('Y');
    $prefix = 'INV-' . $year;
    $sql = "SELECT invoice_no FROM service_invoice 
            WHERE invoice_no LIKE '$prefix-%' 
            ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_no = intval(substr($row['invoice_no'], -5));
        $new_no = str_pad($last_no + 1, 5, '0', STR_PAD_LEFT);
    } else {
        $new_no = '00001';
    }
    return $prefix . '-' . $new_no;
}

$invoice_no = generateInvoiceNo($conn);

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_invoice') {
        $selected_so_data = isset($_POST['selected_so_data']) ? json_decode($_POST['selected_so_data'], true) : [];

        if (empty($selected_so_data)) {
            $error = "Please select at least one Sales Order.";
        } else {
            $invoice_no     = mysqli_real_escape_string($conn, $_POST['invoice_no']);
            $customer_code  = mysqli_real_escape_string($conn, $_POST['customer_code'] ?? '');
            $customer_name  = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
            // No more 'Net 30' fallback — empty string if not provided
            $payment_terms  = mysqli_real_escape_string($conn, $_POST['payment_terms'] ?? '');
            $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? 'Unpaid');
            $notes          = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
            $status         = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Created');
            $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'Bank Transfer');
            $invoice_date   = !empty($_POST['invoice_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['invoice_date']) . "'" : 'NULL';
            $due_date       = !empty($_POST['due_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['due_date']) . "'" : 'NULL';
            $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');

            $insert_count = 0;
            $grand_total = 0;

            foreach ($selected_so_data as $so) {
                $sales_order_no    = mysqli_real_escape_string($conn, $so['sales_order_no']);

                // ── GUARD: reject if this SO is already invoiced ──
                $dup_check = $conn->prepare("SELECT invoice_no FROM service_invoice WHERE sales_order_no = ? LIMIT 1");
                if ($dup_check) {
                    $dup_check->bind_param("s", $sales_order_no);
                    $dup_check->execute();
                    $dup_result = $dup_check->get_result();
                    if ($dup_result && $dup_result->num_rows > 0) {
                        $existing = $dup_result->fetch_assoc();
                        $error = "Sales Order $sales_order_no has already been invoiced (Invoice #{$existing['invoice_no']}). Please deselect it and try again.";
                        break;
                    }
                    $dup_check->close();
                }
                // ── end guard ──

                $truck_code        = mysqli_real_escape_string($conn, $so['truck_code'] ?? '');
                $plate_number      = mysqli_real_escape_string($conn, $so['plate_number'] ?? '');
                $brand             = mysqli_real_escape_string($conn, $so['brand'] ?? '');
                $model             = mysqli_real_escape_string($conn, $so['model'] ?? '');
                $unit              = 'TRIP';
                $destination_from  = mysqli_real_escape_string($conn, $so['destination_from'] ?? '');
                $destination_to    = mysqli_real_escape_string($conn, $so['destination_to'] ?? '');
                $order_date        = !empty($so['order_date']) ? "'" . mysqli_real_escape_string($conn, $so['order_date']) . "'" : 'NULL';
                $delivery_date     = !empty($so['delivery_date']) ? "'" . mysqli_real_escape_string($conn, $so['delivery_date']) . "'" : 'NULL';

                $unit_price       = floatval($so['unit_price'] ?? 0);
                $discount_percent = floatval($so['discount_percent'] ?? 0);
                $discount_amount  = floatval($so['discount_amount'] ?? 0);
                $vat_percent      = floatval($so['vat_percent'] ?? 12);
                $quantity         = floatval($so['quantity'] ?? 1);
                $special_charges  = $so['special_charges'] ?? [];

                // Net amount AFTER discount
                $net_amount = ($unit_price * $quantity) - $discount_amount;
                if ($net_amount < 0) $net_amount = 0;

                // VAT on the net amount
                $vat_amount = $net_amount * ($vat_percent / 100);

                // ── Sum special charges for this SO ──
                $charge_amount = 0;
                foreach ($special_charges as $sc) {
                    $charge_amount += floatval($sc['amount'] ?? 0);
                }

                // Final total for this SO (net + VAT + special charges)
                $total = $net_amount + $vat_amount + $charge_amount;

                $grand_total += $total;

                // Amount = net amount (matches sales_order.amount convention)
                $amount_field = $net_amount;

                // ── CHANGED: include `charge_amount` in the INSERT ──
                $insert_sql = "INSERT INTO service_invoice (
                    invoice_no, sales_order_id, sales_order_no, 
                    customer_code, customer_name,
                    truck_code, plate_number, brand, model, unit, 
                    destination_from, destination_to,
                    delivery_address, order_date, delivery_date, invoice_date, due_date,
                    quantity, unit_price, vat_percent, discount_percent, 
                    amount, discount_amount, vat_amount, charge_amount, total_amount, 
                    payment_terms, payment_method, payment_status, notes, status,
                    created_by, created_date
                ) VALUES (
                    '$invoice_no', NULL, '$sales_order_no',
                    '$customer_code', '$customer_name',
                    '$truck_code', '$plate_number', '$brand', '$model', '$unit', 
                    '$destination_from', '$destination_to',
                    '$delivery_address', $order_date, $delivery_date, $invoice_date, $due_date,
                    $quantity, $unit_price, $vat_percent, $discount_percent, 
                    $amount_field, $discount_amount, $vat_amount, $charge_amount, $total,
                    '$payment_terms', '$payment_method', '$payment_status', '$notes', '$status',
                    '$username', NOW()
                )";

                if ($conn->query($insert_sql)) {
                    $insert_count++;
                } else {
                    $error = "Error saving invoice row for SO $sales_order_no: " . $conn->error;
                    break;
                }
            }

            if ($insert_count > 0 && !isset($error)) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'text' => "Service Invoice #$invoice_no has been created successfully with $insert_count sales order(s). Total: ₱" . number_format($grand_total, 2)
                ];
                header("Location: service_invoice_list.php?invoice_no=" . $invoice_no);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Invoice | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/service_invoice.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <style>
        /* Badge styles for already-invoiced / different-customer SOs */
        .so-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.3px;
            white-space: nowrap;
            flex-shrink: 0;
            margin-left: 8px;
        }
        .so-badge-invoiced {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .so-badge-locked {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }
    </style>
</head>
<body>

<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner"></div>
</div>

<div id="accessModal" class="modal-overlay">
    <div class="access-modal">
        <i data-lucide="shield-off"></i>
        <h3>Access Denied</h3>
        <p>You don't have permission to access this page.</p>
        <button class="modal-btn" onclick="closeModal()">OK</button>
    </div>
</div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Service Invoice</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if (isset($error)): ?>
            <div class="error-message" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="<?php echo $_SESSION['flash_message']['type'] === 'success' ? 'success-message' : 'error-message'; ?>" style="margin-bottom: 20px;">
                <?php 
                echo htmlspecialchars($_SESSION['flash_message']['text']);
                unset($_SESSION['flash_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <h1>
                    <span class="icon-wrap"><i data-lucide="philippine-peso" style="width:18px;height:18px;"></i></span>
                    New Service Invoice
                </h1>
                <div style="display:flex;align-items:center;gap:8px;border-left: 2px solid #e2e8f0;padding-left:16px;">
                    <span style="font-size: 14px; color: var(--text-muted);">View Existing:</span>
                    <div class="so-selector-wrap">
                        <select onchange="if(this.value) window.location.href='service_invoice_list.php?invoice_no='+this.value">
                            <option value="">-- Select a Service Invoice --</option>
                            <?php foreach ($service_invoices as $si): ?>
                                <option value="<?php echo htmlspecialchars($si['invoice_no']); ?>">
                                    <?php echo htmlspecialchars($si['invoice_no'] . ' - ' . $si['customer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="inv-tag"><?php echo $invoice_no; ?></span>
                <span class="status-unpaid">Draft</span>
            </div>
        </div>

        <!-- Sales Order Selection Modal -->
        <div id="soModal" class="so-modal-overlay">
            <div class="so-modal">
                <div class="so-modal-header">
                    <div>
                        <h3><i data-lucide="list-checks" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i> Select Sales Orders</h3>
                        <p style="margin:6px 0 0 0;font-size:13px;color:#64748b;">
                            Customer: <strong id="modalCustomerName">—</strong>
                            <span style="margin-left:16px;">Selected: <strong id="modalSelectedCount">0</strong></span>
                        </p>
                    </div>
                    <button type="button" class="so-modal-close" onclick="closeSOModal()">
                        <i data-lucide="x" style="width:20px;height:20px;"></i>
                    </button>
                </div>

                <div class="so-modal-toolbar">
                    <button type="button" class="btn-secondary" onclick="selectAllInModal()" style="padding:6px 12px;font-size:12px;">
                        <i data-lucide="check-square" style="width:14px;height:14px;"></i> Select All
                    </button>
                    <button type="button" class="btn-secondary" onclick="deselectAllInModal()" style="padding:6px 12px;font-size:12px;">
                        <i data-lucide="square" style="width:14px;height:14px;"></i> Deselect All
                    </button>
                    <span style="margin-left:auto;font-size:13px;color:var(--text-muted);">
                        Total: <strong id="modalSelectedTotal" style="color:#16a34a;">₱0.00</strong>
                    </span>
                </div>

                <div class="so-modal-body">
                    <div class="so-checkbox-grid" id="soCheckboxGridModal"></div>
                </div>

                <div class="so-modal-footer">
                    <span style="font-size:13px;color:var(--text-muted);">
                        <i data-lucide="info" style="width:14px;height:14px;vertical-align:middle;"></i>
                        Checked orders are added to the invoice line items automatically.
                    </span>
                    <button type="button" class="btn-primary" onclick="closeSOModal()" style="padding:8px 20px;">
                        <i data-lucide="check" style="width:15px;height:15px;"></i> Done
                    </button>
                </div>
            </div>
        </div>

        <!-- Invoice Header Banner -->
        <div class="invoice-banner">
            <div>
                <div class="company-name">Oncall Forwarding Corporation</div>
                <div class="company-sub">TIN: 240-099-925-000 | VAT Registered</div>
                <div class="company-sub" style="margin-top:6px;">Green Field Subd., Inayawan, Cebu City</div>
                <div class="company-sub">Fax/Tel. 420-0946 / 383-7076</div>
            </div>
            <div class="inv-meta">
                <div class="inv-label">Service Invoice</div>
                <div class="inv-number"><?php echo $invoice_no; ?></div>
                <div class="inv-date">Date: <?php echo date('F j, Y'); ?></div>
                <div class="inv-date">Due: <span id="displayDueDate">—</span></div>
            </div>
        </div>

        <form id="invoiceForm" method="POST" action="">
            <input type="hidden" name="action" value="save_invoice">
            <input type="hidden" name="invoice_no" value="<?php echo $invoice_no; ?>">
            <input type="hidden" name="status" value="Created">
            <input type="hidden" name="payment_status" value="Unpaid">
            <input type="hidden" name="customer_code" id="customer_code_input">
            <input type="hidden" name="customer_name" id="customer_name_input">
            <input type="hidden" name="selected_so_data" id="selected_so_data">

            <!-- Link to SO -->
            <div class="form-card" style="padding:18px 24px;">
                <div style="display:flex;align-items:center;gap:10px;font-size:16px;font-weight:500;color:black;margin-bottom:12px;">
                    <i data-lucide="link" style="width:15px;height:15px;color:var(--accent-green);"></i> Select Sales Orders to Consolidate
                </div>
                
                <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <label style="font-size:13px;font-weight:500;">Filter by Customer:</label>
                        <select id="customer_filter" style="padding:6px 12px;border-radius:6px;border:1px solid #e2e8f0;">
                            <option value="">-- Select Customer --</option>
                            <?php 
                            $customer_sql = "SELECT DISTINCT customer_code, customer_name FROM sales_order WHERE status != 'cancelled' ORDER BY customer_code";
                            $customer_result = $conn->query($customer_sql);
                            if ($customer_result && $customer_result->num_rows > 0) {
                                while ($row = $customer_result->fetch_assoc()) {
                                    $selected = ($filter_customer == $row['customer_code']) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($row['customer_code']) . '" ' . $selected . '>' . htmlspecialchars($row['customer_code'] . ' - ' . $row['customer_name']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" class="search-button" onclick="applyFilter()">
                        <i data-lucide="search" style="width:15px;height:15px;vertical-align:middle;"></i> Search
                    </button>
                    <?php if (!empty($filter_customer)): ?>
                        <button type="button" class="btn-secondary" onclick="clearFilter()" style="padding:6px 12px;font-size:12px;">
                            <i data-lucide="x" style="width:14px;height:14px;"></i> Clear Filter
                        </button>
                        <span class="filter-info">
                            <i data-lucide="user" style="width:13px;height:13px;vertical-align:middle;"></i>
                            Showing orders for: <strong><?php echo htmlspecialchars($filter_customer); ?></strong>
                        </span>
                    <?php endif; ?>
                    <span style="font-size:13px;color:var(--text-muted);margin-left:auto;">
                        Selected: <span id="selected_count">0</span> Sales Order(s)
                    </span>
                    <span style="font-size:13px;color:var(--text-muted);">
                        Total Amount: <strong id="selected_total_display" style="color:#16a34a;">₱0.00</strong>
                    </span>
                </div>

                <!-- Hidden data container for JS to read filtered SOs -->
                <div id="soDataStore" style="display:none;">
                    <?php if (!empty($sales_orders)): ?>
                        <?php foreach ($sales_orders as $so): ?>
                            <?php
                                $so_no = $so['sales_order_no'];
                                $so_special = $special_charges_map[$so_no] ?? [];
                                $so_special_total = 0;
                                foreach ($so_special as $sc) $so_special_total += $sc['amount'];
                            ?>
                            <div class="so-data-item"
                                 data-so-id="<?php echo htmlspecialchars($so['sales_order_no']); ?>"
                                 data-already-invoiced="<?php echo isset($invoiced_so_map[$so['sales_order_no']]) ? '1' : '0'; ?>"
                                 data-existing-invoice="<?php echo isset($invoiced_so_map[$so['sales_order_no']]) ? htmlspecialchars($invoiced_so_map[$so['sales_order_no']]) : ''; ?>"
                                 data-customer-code="<?php echo htmlspecialchars($so['customer_code']); ?>"
                                 data-customer-name="<?php echo htmlspecialchars($so['customer_name']); ?>"
                                 data-truck="<?php echo htmlspecialchars($so['truck_code']); ?>"
                                 data-plate="<?php echo htmlspecialchars($so['plate_number'] ?? ''); ?>"
                                 data-brand="<?php echo htmlspecialchars($so['brand']); ?>"
                                 data-model="<?php echo htmlspecialchars($so['model']); ?>"
                                 data-unit-price="<?php echo htmlspecialchars($so['unit_price'] ?? 0); ?>"
                                 data-discount-percent="<?php echo htmlspecialchars($so['discount_percent'] ?? 0); ?>"
                                 data-discount-amount="<?php echo htmlspecialchars($so['discount_amount'] ?? 0); ?>"
                                 data-vat-percent="<?php echo htmlspecialchars($so['vat_percent'] ?? 12); ?>"
                                 data-net-amount="<?php echo htmlspecialchars($so['amount'] ?? 0); ?>"
                                 data-quantity="<?php echo htmlspecialchars($so['quantity'] ?? 1); ?>"
                                 data-special-charges='<?php echo htmlspecialchars(json_encode($so_special), ENT_QUOTES, "UTF-8"); ?>'
                                 data-special-total="<?php echo $so_special_total; ?>"
                                 data-payment-terms="<?php echo htmlspecialchars($so['payment_terms'] ?? ''); ?>"
                                 data-destination-from="<?php echo htmlspecialchars($so['destination_from'] ?? ''); ?>"
                                 data-destination-to="<?php echo htmlspecialchars($so['destination_to'] ?? ''); ?>"
                                 data-order-date="<?php echo htmlspecialchars($so['order_date'] ?? ''); ?>"
                                 data-delivery-date="<?php echo htmlspecialchars($so['delivery_date'] ?? ''); ?>"
                                 data-delivery-address="<?php echo htmlspecialchars($so['delivery_address'] ?? ''); ?>">
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php
                // Count how many SOs are already invoiced for this customer
                $already_invoiced_count = 0;
                if (!empty($sales_orders)) {
                    foreach ($sales_orders as $so) {
                        if (isset($invoiced_so_map[$so['sales_order_no']])) {
                            $already_invoiced_count++;
                        }
                    }
                }
                ?>

                <?php if ($already_invoiced_count > 0): ?>
                <div class="invoiced-warning-banner" style="
                    background: #fef3c7;
                    border: 1px solid #fcd34d;
                    border-left: 4px solid #f59e0b;
                    border-radius: 8px;
                    padding: 12px 16px;
                    margin-bottom: 12px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    font-size: 13px;
                    color: #92400e;
                ">
                    <i data-lucide="alert-triangle" style="width:18px;height:18px;flex-shrink:0;color:#d97706;"></i>
                    <span>
                        <strong><?php echo $already_invoiced_count; ?></strong>
                        Sales Order<?php echo $already_invoiced_count > 1 ? 's have' : ' has'; ?>
                        already been invoiced and <?php echo $already_invoiced_count > 1 ? 'are' : 'is'; ?>
                        marked as <strong>Invoiced</strong> below. They cannot be selected again.
                    </span>
                </div>
                <?php endif; ?>

                <!-- Prompt / button to open modal -->
                <div id="soPromptBox" class="so-prompt-box">
                    <?php if (empty($filter_customer)): ?>
                        <div class="empty-state">
                            <div style="font-size:48px;margin-bottom:12px;">🔍</div>
                            <h4 style="color:#64748b;">No Customer Selected</h4>
                            <p style="color:#94a3b8;font-size:14px;">Please select a customer above and click Search to load their sales orders.</p>
                        </div>
                    <?php elseif (empty($sales_orders)): ?>
                        <div class="empty-state">
                            <div style="font-size:48px;margin-bottom:12px;">📋</div>
                            <h4 style="color:#64748b;">No Orders Found</h4>
                            <p style="color:#94a3b8;font-size:14px;">No active sales orders found for this customer.</p>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center;padding:20px;">
                            <i data-lucide="package-search" style="width:40px;height:40px;color:#53c0e1;margin-bottom:8px;"></i>
                            <h4 style="color:#334155;margin-bottom:6px;">
                                <?php 
                                $totalOrders = count($sales_orders);
                                echo $totalOrders . ' Sales Order' . ($totalOrders > 1 ? 's' : '') . ' available for ' . htmlspecialchars($filter_customer);
                                ?>
                            </h4>
                            <p style="color:#64748b;font-size:13px;margin-bottom:14px;">Click the button below to select which orders to include in this invoice.</p>
                            <button type="button" class="btn-primary" onclick="openSOModal()" style="padding:10px 22px;font-size:14px;">
                                <i data-lucide="list-checks" style="width:16px;height:16px;"></i> Select Sales Orders
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Invoice Details -->
            <div class="form-card">
                <div class="form-card-title"><i data-lucide="user-check" style="width:16px;height:16px;"></i> Bill To / Invoice Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Customer Code</label>
                        <input type="text" id="customer_code_display" readonly placeholder="Select Sales Order(s)" class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Customer Name</label>
                        <input type="text" id="customer_name_display" readonly placeholder="Select Sales Order(s)" class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Invoice Date</label>
                        <input type="date" name="invoice_date" id="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <!-- Removed `required` so it can be empty when no payment terms -->
                        <input type="date" name="due_date" id="due_date">
                    </div>
                    <div class="form-group">
                        <label>Payment Terms</label>
                        <input type="text" name="payment_terms" id="payment_terms" readonly placeholder="Auto-filled from SO" class="readonly-field">
                        <input type="hidden" name="payment_terms_value" id="payment_terms_value">
                    </div>
                    <div class="form-group">
                        <label>Delivery Address</label>
                        <input type="text" name="delivery_address" id="delivery_address" placeholder="Delivery address">
                    </div>
                </div>
            </div>

            <!-- Line Items / Charges -->
            <div class="form-card">
                <div class="form-card-title"><i data-lucide="list" style="width:16px;height:16px;"></i> Invoice Line Items</div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:15%;">SO No.</th>
                                <th style="width:20%;">Truck</th>
                                <th style="width:25%;">Destination</th>
                                <th style="width:10%;">Qty</th>
                                <th style="width:15%;text-align:right;">Unit Price</th>
                                <th style="width:15%;text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="no-items-row">
                                <td colspan="6" style="text-align:center;color:var(--text-muted);padding:20px;">
                                    Select sales orders to populate line items
                                </td>
                            </tr>
                        </tbody>
                        <tfoot id="items-footer" style="display:none;">
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:600;">Subtotal:</td>
                                <td style="text-align:right;font-weight:600;" id="subtotal_display">₱0.00</td>
                            </tr>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:600;">Discount:</td>
                                <td style="text-align:right;font-weight:600;color:#dc2626;" id="discount_display">−₱0.00</td>
                            </tr>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:600;">VAT (12%):</td>
                                <td style="text-align:right;font-weight:600;" id="vat_display">₱0.00</td>
                            </tr>
                            <tr id="special-charges-row" style="display:none;">
                                <td colspan="5" style="text-align:right;font-weight:600;color:#b45309;">Special Charges:</td>
                                <td style="text-align:right;font-weight:600;color:#b45309;" id="special_display">₱0.00</td>
                            </tr>
                            <tr style="border-top:2px solid #16a34a;">
                                <td colspan="5" style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">TOTAL:</td>
                                <td style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;" id="grand_total_display">₱0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <input type="hidden" name="amount" id="amount" value="0">
                <input type="hidden" name="discount_amount" id="discount_amount" value="0">
                <input type="hidden" name="vat_amount" id="vat_amount" value="0">
                <input type="hidden" name="total_amount" id="total_amount" value="0">
            </div>

            <!-- Payment Method + Totals -->
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:16px;">
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="credit-card" style="width:16px;height:16px;"></i> Payment Method</div>
                        <div class="payment-status-row" id="payment-methods">
                            <button type="button" class="payment-method-btn selected" onclick="selectPayment(this, 'Bank Transfer')">
                                <i data-lucide="landmark"></i> Bank Transfer
                            </button>
                            <button type="button" class="payment-method-btn" onclick="selectPayment(this, 'Cash')">
                                <i data-lucide="banknote"></i> Cash
                            </button>
                            <button type="button" class="payment-method-btn" onclick="selectPayment(this, 'Check')">
                                <i data-lucide="check-square"></i> Check
                            </button>
                        </div>
                        <input type="hidden" name="payment_method" id="payment_method" value="Bank Transfer">
                    </div>
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Remarks / Notes</div>
                        <textarea name="notes" id="notes" placeholder="Add payment instructions, bank details, or notes for the customer..." style="width:100%;min-height:90px;"></textarea>
                    </div>
                </div>

                <div>
                    <div class="totals-box">
                        <div class="totals-row"><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
                        <div class="totals-row"><span>Discount</span><span id="discount">−₱0.00</span></div>
                        <div class="totals-row"><span>VAT Amount</span><span id="vat">₱0.00</span></div>
                        <div class="totals-row" id="special_total_row" style="display:none;background:#fffbeb;border-radius:6px;padding-left:8px;padding-right:8px;">
                            <span style="color:#b45309;">Special Charges</span>
                            <span id="special_total" style="color:#b45309;font-weight:700;">₱0.00</span>
                        </div>
                        <div class="totals-row total-final">
                            <span>Total Amount</span>
                            <span class="amount-due" id="grand_total">₱0.00</span>
                        </div>
                    </div>
                    <div style="margin-top:12px;text-align:right;font-size:12px;color:var(--text-muted);">
                        Prepared by: <strong><?php echo htmlspecialchars($username); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="actions-row" style="margin-top:24px;">
                <a href="service_invoice_list.php" class="btn-secondary"><i data-lucide="x" style="width:15px;height:15px;"></i> Cancel</a>
                <button type="submit" class="btn-primary" onclick="return validateForm()">
                    <i data-lucide="save" style="width:15px;height:15px;"></i> Create Invoice
                </button>
            </div>
        </form>
    </div>
</main>

<script>
lucide.createIcons();

const userRoles = <?php echo json_encode($user_roles); ?>;
const allowedPages = <?php echo json_encode($allowed_pages); ?>;
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
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && modal?.style.display === 'flex') closeModal();
});

function formatPHP(val) {
    return '₱' + parseFloat(val || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================================
// CALCULATE TOTALS — uses discount_amount + special charges
// ============================================================
function calculateTotals() {
    const items = document.querySelectorAll('#items-body tr:not(#no-items-row)');
    let subtotal = 0;
    let totalDiscount = 0;
    let totalVat = 0;
    let totalSpecial = 0;
    let grandTotal = 0;

    items.forEach(row => {
        const qty             = parseFloat(row.dataset.qty) || 1;
        const unitPrice       = parseFloat(row.dataset.unitPrice) || 0;
        const discountAmount  = parseFloat(row.dataset.discountAmount) || 0;
        const vatPercent      = parseFloat(row.dataset.vatPercent) || 12;
        const specialTotal    = parseFloat(row.dataset.specialTotal) || 0;

        const gross = unitPrice * qty;
        const net   = gross - discountAmount;
        const vat   = net * (vatPercent / 100);
        const rowTotal = net + vat + specialTotal;

        subtotal      += gross;
        totalDiscount += discountAmount;
        totalVat      += vat;
        totalSpecial  += specialTotal;
        grandTotal    += rowTotal;
    });

    document.getElementById('subtotal').textContent = formatPHP(subtotal);
    document.getElementById('discount').textContent = '−' + formatPHP(totalDiscount);
    document.getElementById('vat').textContent = formatPHP(totalVat);
    document.getElementById('grand_total').textContent = formatPHP(grandTotal);

    document.getElementById('subtotal_display').textContent = formatPHP(subtotal);
    document.getElementById('discount_display').textContent = '−' + formatPHP(totalDiscount);
    document.getElementById('vat_display').textContent = formatPHP(totalVat);
    document.getElementById('grand_total_display').textContent = formatPHP(grandTotal);

    // Special charges row in footer
    const specialRow = document.getElementById('special-charges-row');
    const specialTotalRow = document.getElementById('special_total_row');
    if (totalSpecial > 0) {
        specialRow.style.display = '';
        specialTotalRow.style.display = 'flex';
        document.getElementById('special_display').textContent = formatPHP(totalSpecial);
        document.getElementById('special_total').textContent = formatPHP(totalSpecial);
    } else {
        specialRow.style.display = 'none';
        specialTotalRow.style.display = 'none';
    }

    document.getElementById('amount').value = subtotal.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
    document.getElementById('vat_amount').value = totalVat.toFixed(2);
    document.getElementById('total_amount').value = grandTotal.toFixed(2);
}

// If payment terms are empty, leave the due date alone
function calculateDueDate() {
    const terms = document.getElementById('payment_terms').value;
    const dueDateInput = document.getElementById('due_date');
    const displayDueDate = document.getElementById('displayDueDate');

    if (!terms || terms.trim() === '') {
        // Do nothing — leave due date as user set it (or empty)
        return;
    }

    const invoiceDate = new Date(document.getElementById('invoice_date').value);
    if (isNaN(invoiceDate.getTime())) return;

    if (terms === 'Due on Receipt') {
        dueDateInput.value = document.getElementById('invoice_date').value;
    } else if (terms === 'Net 30') {
        invoiceDate.setDate(invoiceDate.getDate() + 30);
        dueDateInput.value = invoiceDate.toISOString().split('T')[0];
    } else if (terms === 'Net 60') {
        invoiceDate.setDate(invoiceDate.getDate() + 60);
        dueDateInput.value = invoiceDate.toISOString().split('T')[0];
    } else if (terms === 'Net 90') {
        invoiceDate.setDate(invoiceDate.getDate() + 90);
        dueDateInput.value = invoiceDate.toISOString().split('T')[0];
    }
    displayDueDate.textContent = dueDateInput.value;
}

function selectPayment(btn, method) {
    document.querySelectorAll('.payment-method-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('payment_method').value = method;
}

let selectedCustomerCode = '';
let selectedCustomerName = '';
let selectedData = [];

// ===== Sales Order Modal Logic =====
function buildSOModalContent() {
    const grid = document.getElementById('soCheckboxGridModal');
    const dataItems = document.querySelectorAll('#soDataStore .so-data-item');
    
    if (dataItems.length === 0) {
        grid.innerHTML = '<div class="empty-state" style="text-align:center;padding:40px;color:#94a3b8;">No sales orders available.</div>';
        return;
    }
    
    const groups = {};
    dataItems.forEach(item => {
        const key = item.dataset.customerCode + '|' + item.dataset.customerName;
        if (!groups[key]) {
            groups[key] = { code: item.dataset.customerCode, name: item.dataset.customerName, orders: [] };
        }
        groups[key].orders.push(item);
    });
    
    let html = '';
    Object.values(groups).forEach(group => {
        let groupTotal = 0;
        group.orders.forEach(o => {
            const qty = parseFloat(o.dataset.quantity) || 1;
            const up  = parseFloat(o.dataset.unitPrice) || 0;
            const disc = parseFloat(o.dataset.discountAmount) || 0;
            const vatP = parseFloat(o.dataset.vatPercent) || 12;
            const spec = parseFloat(o.dataset.specialTotal) || 0;
            const net = (up * qty) - disc;
            groupTotal += net + (net * vatP / 100) + spec;
        });
        
        html += `
            <div style="margin-bottom:8px;">
                <div class="customer-group-header">
                    <span>
                        <span class="customer-code-badge">${escapeHtml(group.code)}</span>
                        ${escapeHtml(group.name)}
                    </span>
                    <span>
                        <span class="so-count">${group.orders.length} order(s)</span>
                        <span class="total-amount" style="margin-left:12px;">
                            Total: ${formatPHP(groupTotal)}
                        </span>
                    </span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;padding-left:8px;">
        `;
        
        group.orders.forEach(item => {
            const soNo = item.dataset.soId;
            const truck = item.dataset.truck;
            const from = (item.dataset.destinationFrom || '').substring(0, 10);
            const to = (item.dataset.destinationTo || '').substring(0, 10);
            const qty  = parseFloat(item.dataset.quantity) || 1;
            const up   = parseFloat(item.dataset.unitPrice) || 0;
            const disc = parseFloat(item.dataset.discountAmount) || 0;
            const vatP = parseFloat(item.dataset.vatPercent) || 12;
            const spec = parseFloat(item.dataset.specialTotal) || 0;
            const net  = (up * qty) - disc;
            const total = net + (net * vatP / 100) + spec;
            
            const isChecked = selectedData.some(d => d.sales_order_no === soNo);
            const alreadyInvoiced = item.dataset.alreadyInvoiced === '1';
            const existingInvoiceNo = item.dataset.existingInvoice || '';
            const customerMismatch = selectedCustomerCode && selectedCustomerCode !== item.dataset.customerCode;

            // SO is disabled if: already invoiced OR customer mismatch
            const disabled = alreadyInvoiced || customerMismatch;

            // Wrapper opacity: dim if either condition
            let wrapperOpacity = '1';
            if (alreadyInvoiced) wrapperOpacity = '0.55';
            else if (customerMismatch) wrapperOpacity = '0.5';

            // Badge text — prioritise "Already Invoiced" over customer mismatch
            let badgeHtml = '';
            if (alreadyInvoiced) {
                badgeHtml = `<span class="so-badge so-badge-invoiced" title="Invoice #${escapeHtml(existingInvoiceNo)}">✓ Invoiced</span>`;
            } else if (customerMismatch) {
                badgeHtml = `<span class="so-badge so-badge-locked">Different customer</span>`;
            }

            html += `
                <label class="so-checkbox-item" data-customer="${escapeHtml(item.dataset.customerCode)}" style="opacity:${wrapperOpacity};">
                    <input type="checkbox" 
                           value="${escapeHtml(soNo)}"
                           data-already-invoiced="${alreadyInvoiced ? '1' : '0'}"
                           data-existing-invoice="${escapeHtml(existingInvoiceNo)}"
                           data-so-id="${escapeHtml(soNo)}"
                           data-customer-code="${escapeHtml(item.dataset.customerCode)}"
                           data-customer-name="${escapeHtml(item.dataset.customerName)}"
                           data-truck="${escapeHtml(truck)}"
                           data-plate="${escapeHtml(item.dataset.plate || '')}"
                           data-brand="${escapeHtml(item.dataset.brand || '')}"
                           data-model="${escapeHtml(item.dataset.model || '')}"
                           data-unit-price="${item.dataset.unitPrice || 0}"
                           data-quantity="${item.dataset.quantity || 1}"
                           data-discount-percent="${item.dataset.discountPercent || 0}"
                           data-discount-amount="${item.dataset.discountAmount || 0}"
                           data-vat-percent="${item.dataset.vatPercent || 12}"
                           data-special-total="${item.dataset.specialTotal || 0}"
                           data-special-charges="${escapeHtml(item.dataset.specialCharges || '[]')}"
                           data-payment-terms="${escapeHtml(item.dataset.paymentTerms || '')}"
                           data-destination-from="${escapeHtml(item.dataset.destinationFrom || '')}"
                           data-destination-to="${escapeHtml(item.dataset.destinationTo || '')}"
                           data-order-date="${escapeHtml(item.dataset.orderDate || '')}"
                           data-delivery-date="${escapeHtml(item.dataset.deliveryDate || '')}"
                           data-delivery-address="${escapeHtml(item.dataset.deliveryAddress || '')}"
                           ${isChecked ? 'checked' : ''}
                           ${disabled ? 'disabled' : ''}
                           onchange="handleCheckboxChange(this)">
                    <span class="so-code">${escapeHtml(soNo)}</span>
                    <span class="so-truck">${escapeHtml(truck)}</span>
                    <span class="so-destination">${escapeHtml(from)} → ${escapeHtml(to)}</span>
                    <span class="so-amount">${formatPHP(total)}</span>
                    ${badgeHtml}
                </label>
            `;
        });
        
        html += `</div></div>`;
    });
    
    grid.innerHTML = html;
    lucide.createIcons();
    
    const firstItem = dataItems[0];
    document.getElementById('modalCustomerName').textContent = firstItem.dataset.customerName;
    updateModalCounters();
}

function openSOModal() {
    buildSOModalContent();
    document.getElementById('soModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeSOModal() {
    document.getElementById('soModal').classList.remove('active');
    document.body.style.overflow = '';
}

function updateModalCounters() {
    const count = selectedData.length;
    let total = 0;
    selectedData.forEach(d => {
        const net = (d.unit_price * d.quantity) - d.discount_amount;
        total += net + (net * d.vat_percent / 100) + d.special_total;
    });
    document.getElementById('modalSelectedCount').textContent = count;
    document.getElementById('modalSelectedTotal').textContent = formatPHP(total);
}

function selectAllInModal() {
    document.querySelectorAll('#soCheckboxGridModal input[type="checkbox"]:not(:disabled)').forEach(cb => {
        // Skip already-invoiced SOs (they are disabled anyway, but double-guard)
        if (cb.dataset.alreadyInvoiced === '1') return;
        if (!cb.checked) {
            cb.checked = true;
            handleCheckboxChange(cb);
        }
    });
}

function deselectAllInModal() {
    document.querySelectorAll('#soCheckboxGridModal input[type="checkbox"]').forEach(cb => { cb.checked = false; });
    selectedCustomerCode = '';
    selectedCustomerName = '';
    selectedData = [];
    document.querySelectorAll('#soCheckboxGridModal .so-checkbox-item input[type="checkbox"]').forEach(cb => {
        cb.disabled = false;
        cb.closest('.so-checkbox-item').style.opacity = '1';
    });
    // Re-apply invoiced disable state after resetting
    document.querySelectorAll('#soCheckboxGridModal input[type="checkbox"][data-already-invoiced="1"]').forEach(cb => {
        cb.disabled = true;
        cb.closest('.so-checkbox-item').style.opacity = '0.55';
    });
    updateSelectionCounters();
    clearDetails();
    renderLineItems();
    updateModalCounters();
}

function handleCheckboxChange(checkbox) {
    const checked = checkbox.checked;
    const customerCode = checkbox.dataset.customerCode;
    const customerName = checkbox.dataset.customerName;

    // ── GUARD: reject already-invoiced SO ──
    if (checked && checkbox.dataset.alreadyInvoiced === '1') {
        const invNo = checkbox.dataset.existingInvoice || 'an existing invoice';
        alert('This Sales Order has already been invoiced (' + invNo + ').\nIt cannot be added to a new invoice.');
        checkbox.checked = false;
        return;
    }
    // ── end guard ──

    if (checked) {
        if (!selectedCustomerCode) {
            selectedCustomerCode = customerCode;
            selectedCustomerName = customerName;
        } else if (selectedCustomerCode !== customerCode) {
            alert('You can only select Sales Orders from the same customer. Current customer: ' + selectedCustomerName);
            checkbox.checked = false;
            return;
        }
    }
    
    const allCheckboxes = document.querySelectorAll('#soCheckboxGridModal .so-checkbox-item input[type="checkbox"]');
    let anyChecked = false;
    selectedData = [];
    
    allCheckboxes.forEach(cb => {
        if (cb.checked) {
            anyChecked = true;
            selectedCustomerCode = cb.dataset.customerCode;
            selectedCustomerName = cb.dataset.customerName;
            let specialCharges = [];
            try { specialCharges = JSON.parse(cb.dataset.specialCharges || '[]'); } catch(e) { specialCharges = []; }
            selectedData.push({
                sales_order_no: cb.value,
                customer_code: cb.dataset.customerCode,
                customer_name: cb.dataset.customerName,
                truck_code: cb.dataset.truck || '',
                plate_number: cb.dataset.plate || '',
                brand: cb.dataset.brand || '',
                model: cb.dataset.model || '',
                unit_price: parseFloat(cb.dataset.unitPrice) || 0,
                quantity: parseFloat(cb.dataset.quantity) || 1,
                discount_percent: parseFloat(cb.dataset.discountPercent) || 0,
                discount_amount: parseFloat(cb.dataset.discountAmount) || 0,
                vat_percent: parseFloat(cb.dataset.vatPercent) || 12,
                special_total: parseFloat(cb.dataset.specialTotal) || 0,
                special_charges: specialCharges,
                destination_from: cb.dataset.destinationFrom || '',
                destination_to: cb.dataset.destinationTo || '',
                order_date: cb.dataset.orderDate || '',
                delivery_date: cb.dataset.deliveryDate || '',
                delivery_address: cb.dataset.deliveryAddress || '',
                payment_terms: cb.dataset.paymentTerms || ''
            });
        }
    });
    
    if (anyChecked) {
        allCheckboxes.forEach(cb => {
            const isInvoiced = cb.dataset.alreadyInvoiced === '1';
            const isDifferentCustomer = cb.dataset.customerCode !== selectedCustomerCode && !cb.checked;

            if (isInvoiced) {
                // Already-invoiced stay disabled forever
                cb.disabled = true;
                cb.closest('.so-checkbox-item').style.opacity = '0.55';
            } else if (isDifferentCustomer) {
                cb.disabled = true;
                cb.closest('.so-checkbox-item').style.opacity = '0.5';
            } else {
                cb.disabled = false;
                cb.closest('.so-checkbox-item').style.opacity = '1';
            }
        });
    } else {
        allCheckboxes.forEach(cb => {
            const isInvoiced = cb.dataset.alreadyInvoiced === '1';
            if (isInvoiced) {
                cb.disabled = true;
                cb.closest('.so-checkbox-item').style.opacity = '0.55';
            } else {
                cb.disabled = false;
                cb.closest('.so-checkbox-item').style.opacity = '1';
            }
        });
        selectedCustomerCode = '';
        selectedCustomerName = '';
        selectedData = [];
    }
    
    updateSelectionCounters();
    loadSelectedDetails();
    renderLineItems();
    updateModalCounters();
}

// ============================================================
// RENDER LINE ITEMS — shows correct per-SO total
// ============================================================
function renderLineItems() {
    const tbody = document.getElementById('items-body');
    const noItemsRow = document.getElementById('no-items-row');
    const footer = document.getElementById('items-footer');
    
    const existingRows = tbody.querySelectorAll('tr:not(#no-items-row)');
    existingRows.forEach(row => row.remove());
    
    if (selectedData.length === 0) {
        noItemsRow.style.display = '';
        footer.style.display = 'none';
        return;
    }
    
    noItemsRow.style.display = 'none';
    footer.style.display = '';
    
    selectedData.forEach((data) => {
        const row = document.createElement('tr');
        row.dataset.unitPrice     = data.unit_price;
        row.dataset.qty           = data.quantity;
        row.dataset.discountAmount = data.discount_amount;
        row.dataset.vatPercent    = data.vat_percent;
        row.dataset.specialTotal  = data.special_total;
        
        const from = (data.destination_from || '').substring(0, 15);
        const to = (data.destination_to || '').substring(0, 15);
        
        const net = (data.unit_price * data.quantity) - data.discount_amount;
        const vat = net * (data.vat_percent / 100);
        const rowTotal = net + vat + data.special_total;
        
        row.innerHTML = `
            <td><strong>${escapeHtml(data.sales_order_no)}</strong></td>
            <td>${escapeHtml(data.truck_code)}</td>
            <td style="font-size:11px;">${escapeHtml(from)} → ${escapeHtml(to)}</td>
            <td style="text-align:center;">${data.quantity}</td>
            <td style="text-align:right;">${formatPHP(data.unit_price)}</td>
            <td style="text-align:right;font-weight:600;">${formatPHP(rowTotal)}</td>
        `;
        tbody.appendChild(row);
    });
    
    calculateTotals();
}

function applyFilter() {
    const filter = document.getElementById('customer_filter').value;
    if (filter === '') {
        alert('Please select a customer first.');
        return;
    }
    window.location.href = 'service_invoice.php?filter_customer=' + encodeURIComponent(filter);
}

function clearFilter() {
    closeSOModal();
    window.location.href = 'service_invoice.php';
}

function updateSelectionCounters() {
    const count = selectedData.length;
    document.getElementById('selected_count').textContent = count;
    let total = 0;
    selectedData.forEach(data => {
        const net = (data.unit_price * data.quantity) - data.discount_amount;
        total += net + (net * data.vat_percent / 100) + data.special_total;
    });
    document.getElementById('selected_total_display').textContent = formatPHP(total);
}

function loadSelectedDetails() {
    if (selectedData.length === 0) {
        clearDetails();
        return;
    }
    const first = selectedData[0];
    document.getElementById('customer_code_display').value = first.customer_code;
    document.getElementById('customer_name_display').value = first.customer_name;
    document.getElementById('customer_code_input').value = first.customer_code;
    document.getElementById('customer_name_input').value = first.customer_name;
    if (first.delivery_address) document.getElementById('delivery_address').value = first.delivery_address;
    // No more 'Net 30' fallback
    const paymentTerms = first.payment_terms || '';
    document.getElementById('payment_terms').value = paymentTerms;
    document.getElementById('payment_terms_value').value = paymentTerms;
    document.getElementById('selected_so_data').value = JSON.stringify(selectedData);
    calculateDueDateFromTerms(paymentTerms);
    updateModalCounters();
}

function clearDetails() {
    document.getElementById('customer_code_display').value = '';
    document.getElementById('customer_name_display').value = '';
    document.getElementById('customer_code_input').value = '';
    document.getElementById('customer_name_input').value = '';
    document.getElementById('payment_terms').value = '';
    document.getElementById('payment_terms_value').value = '';
    document.getElementById('selected_so_data').value = '';
    document.getElementById('delivery_address').value = '';
}

// If payment terms are empty or unrecognized, clear the due date
function calculateDueDateFromTerms(terms) {
    const dueDateInput = document.getElementById('due_date');
    const displayDueDate = document.getElementById('displayDueDate');

    // If no payment terms, clear the due date
    if (!terms || terms.trim() === '') {
        dueDateInput.value = '';
        displayDueDate.textContent = '—';
        return;
    }

    const invoiceDate = new Date(document.getElementById('invoice_date').value);
    if (isNaN(invoiceDate.getTime())) {
        dueDateInput.value = '';
        displayDueDate.textContent = '—';
        return;
    }

    let dueDate = new Date(invoiceDate);

    if (terms === 'Due on Receipt') {
        dueDate = invoiceDate;
    } else if (terms === 'Net 30') {
        dueDate.setDate(invoiceDate.getDate() + 30);
    } else if (terms === 'Net 60') {
        dueDate.setDate(invoiceDate.getDate() + 60);
    } else if (terms === 'Net 90') {
        dueDate.setDate(invoiceDate.getDate() + 90);
    } else {
        const match = terms.match(/Net\s+(\d+)/i);
        if (match && match[1]) {
            dueDate.setDate(invoiceDate.getDate() + parseInt(match[1]));
        } else {
            // Unknown terms format — clear the due date
            dueDateInput.value = '';
            displayDueDate.textContent = '—';
            return;
        }
    }

    dueDateInput.value = dueDate.toISOString().split('T')[0];
    displayDueDate.textContent = dueDateInput.value;
}

function validateForm() {
    if (selectedData.length === 0) {
        alert('Please select at least one Sales Order.');
        return false;
    }
    let customerCode = null;
    let valid = true;
    selectedData.forEach(data => {
        if (!customerCode) customerCode = data.customer_code;
        else if (customerCode !== data.customer_code) valid = false;
    });
    if (!valid) {
        alert('All selected Sales Orders must have the same customer.');
        return false;
    }
    document.getElementById('loadingOverlay').style.display = 'flex';
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    calculateDueDate();
    renderLineItems();
    <?php if (!empty($filter_customer) && !empty($sales_orders)): ?>
    openSOModal();
    <?php endif; ?>
});

document.getElementById('invoice_date').addEventListener('change', calculateDueDate);

document.getElementById('soModal').addEventListener('click', function(e) {
    if (e.target === this) closeSOModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const soModal = document.getElementById('soModal');
        if (soModal && soModal.classList.contains('active')) closeSOModal();
    }
});
</script>
</body>
</html>