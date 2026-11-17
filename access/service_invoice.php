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
// FETCH SALES ORDERS
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
                so.payment_terms, so.delivery_receipt_no
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
// FETCH SPECIAL CHARGES FOR EACH SO
// ============================================================
$special_charges_map = [];

if (!empty($sales_orders)) {
    $so_numbers = array_column($sales_orders, 'sales_order_no');
    if (!empty($so_numbers)) {
        $placeholders = implode(',', array_fill(0, count($so_numbers), '?'));
        $charge_query = "SELECT sales_order_no, charge_kind, charge_amount, charge_vat 
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
                    'amount' => floatval($row['charge_amount']),
                    'vat'    => ($row['charge_vat'] !== null && $row['charge_vat'] !== '') ? floatval($row['charge_vat']) : 0.0
                ];
            }
        }
    }
}

// ============================================================
// FETCH ALREADY-INVOICED SO NUMBERS
// ============================================================
$invoiced_so_map = [];

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

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_invoice') {
        $selected_so_data = isset($_POST['selected_so_data']) ? json_decode($_POST['selected_so_data'], true) : [];
        $invoice_no       = trim($_POST['invoice_no'] ?? '');

        if (empty($selected_so_data)) {
            $error = "Please select at least one Sales Order.";
        } elseif ($invoice_no === '') {
            $error = "Please enter the Service Invoice number.";
        } else {
            $invoice_no     = mysqli_real_escape_string($conn, $invoice_no);
            $customer_code  = mysqli_real_escape_string($conn, $_POST['customer_code'] ?? '');
            $customer_name  = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
            $payment_terms  = mysqli_real_escape_string($conn, $_POST['payment_terms'] ?? '');
            $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? 'Unpaid');
            $notes          = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
            $status         = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Created');
            $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'Bank Transfer');
            $invoice_date   = !empty($_POST['invoice_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['invoice_date']) . "'" : 'NULL';
            $due_date       = !empty($_POST['due_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['due_date']) . "'" : 'NULL';
            $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');

            // ── GUARD: reject duplicate invoice_no (must be unique per official document) ──
            $inv_dup_check = $conn->prepare("SELECT invoice_no, customer_name FROM service_invoice WHERE invoice_no = ? LIMIT 1");
            if ($inv_dup_check) {
                $inv_dup_check->bind_param("s", $invoice_no);
                $inv_dup_check->execute();
                $inv_dup_result = $inv_dup_check->get_result();
                if ($inv_dup_result && $inv_dup_result->num_rows > 0) {
                    $existing_inv = $inv_dup_result->fetch_assoc();
                    $error = "Invoice number <strong>" . htmlspecialchars($invoice_no) . "</strong> already exists (Customer: " 
                           . htmlspecialchars($existing_inv['customer_name']) . "). Please enter a different Service Invoice number.";
                }
                $inv_dup_check->close();
            }
            // ── end guard ──

            if (!isset($error)) {
                $insert_count = 0;
                $grand_total = 0;

                foreach ($selected_so_data as $so) {
                    $sales_order_no    = mysqli_real_escape_string($conn, $so['sales_order_no']);
                    $delivery_receipt_no = mysqli_real_escape_string($conn, $so['delivery_receipt_no'] ?? '');

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
                    // ── VAT: only 12 applies; NULL/empty/0 means no VAT ──
                    $vat_percent_raw  = $so['vat_percent'] ?? null;
                    $vat_percent      = ($vat_percent_raw !== null && $vat_percent_raw !== '' && floatval($vat_percent_raw) == 12) ? 12.0 : 0.0;
                    $quantity         = floatval($so['quantity'] ?? 1);
                    $special_charges  = $so['special_charges'] ?? [];

                    // Net amount AFTER discount
                    $net_amount = ($unit_price * $quantity) - $discount_amount;
                    if ($net_amount < 0) $net_amount = 0;

                    // VAT on the net amount (only if 12%)
                    $vat_amount = ($vat_percent == 12) ? ($net_amount * 0.12) : 0.0;

                    // Sum special charges + their VAT for this SO
                    $charge_amount = 0;
                    $charge_vat_amount = 0;
                    foreach ($special_charges as $sc) {
                        $charge_amount += floatval($sc['amount'] ?? 0);
                        $charge_vat_amount += floatval($sc['vat'] ?? 0);
                    }

                    // Final total for this SO (net + VAT + special charges + special charges VAT)
                    $total = $net_amount + $vat_amount + $charge_amount + $charge_vat_amount;

                    $grand_total += $total;

                    $amount_field = $net_amount;

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
                    header("Location: service_invoice_list.php?invoice_no=" . urlencode($invoice_no));
                    exit;
                }
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/service_invoice.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <style>
        /* ── Badges ── */
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
        }
        .so-badge-invoiced { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .so-badge-locked   { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* ═══════════════════════════════════════════════════════════
           TABLE LAYOUT FOR MODAL
           ═══════════════════════════════════════════════════════════ */
        .so-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
        }
        .so-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            border-top: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .so-table th:first-child { border-top-left-radius: 6px; border-left: 1px solid #e2e8f0; }
        .so-table th:last-child { border-top-right-radius: 6px; border-right: 1px solid #e2e8f0; text-align: right; }
        .so-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eef2f7;
            color: #334155;
            vertical-align: middle;
            background: #fff;
        }
        .so-table tr:last-child td { border-bottom: none; }
        
        /* Row Hover & Selection */
        .so-table tbody tr { transition: background-color 0.15s; cursor: pointer; }
        .so-table tbody tr:hover { background-color: #f8fafc; }
        .so-table tbody tr.selected { background-color: #eff6ff !important; }
        .so-table tbody tr.selected td { border-bottom-color: #bfdbfe; }
        .so-table tbody tr.invoiced { opacity: 0.6; cursor: not-allowed; }
        .so-table tbody tr.invoiced:hover { background-color: #fff; }

        /* Checkbox column */
        .so-checkbox-cell { width: 40px; text-align: center; padding-right: 0; }
        .so-checkbox-cell input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #3b82f6;
        }
        .so-checkbox-cell input[type="checkbox"]:disabled { cursor: not-allowed; }

        /* Specific column alignments */
        .so-text-right { text-align: right; font-weight: 600; color: #16a34a; }
        .so-text-muted { color: #64748b; font-size: 11px; }
        .so-so-code { font-weight: 600; color: #0f172a; white-space: nowrap; }
        .so-dest { font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; }

        /* ═══════════════════════════════════════════════════════════
           PROMINENT INVOICE NUMBER FIELD
           ═══════════════════════════════════════════════════════════ */
        .invoice-no-banner {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 16px;
            align-items: center;
            padding: 18px 20px;
            margin: 4px 0 20px 0;
            background: linear-gradient(135deg, #f0f9ff 0%, #ecfeff 100%);
            border: 2px solid #53c0e1;
            border-left: 6px solid #53c0e1;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(83, 192, 225, 0.12);
        }
        .invoice-no-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            background: #53c0e1;
            color: #fff;
            border-radius: 10px;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(83, 192, 225, 0.35);
        }
        .invoice-no-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        .invoice-no-field label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #0c4a6e;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .required-pill {
            display: inline-block;
            background: #dc2626;
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.8px;
            padding: 2px 8px;
            border-radius: 10px;
            text-transform: uppercase;
        }
        #invoice_no_input {
            width: 100%;
            padding: 12px 16px;
            font-family: 'Inter', ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #0f172a;
            text-transform: uppercase;
            background: #fff;
            border: 2px solid #bae6fd;
            border-radius: 8px;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        #invoice_no_input::placeholder {
            font-size: 14px;
            font-weight: 400;
            letter-spacing: 0.3px;
            color: #94a3b8;
            text-transform: none;
        }
        #invoice_no_input:focus {
            outline: none;
            border-color: #53c0e1;
            box-shadow: 0 0 0 4px rgba(83, 192, 225, 0.18);
            background: #fff;
        }
        #invoice_no_input.is-invalid {
            border-color: #dc2626;
            background: #fef2f2;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
        }
        #invoice_no_input.is-valid {
            border-color: #16a34a;
            background: #f0fdf4;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.10);
        }
        .invoice-no-hint {
            font-size: 11px;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 5px;
            line-height: 1.4;
        }
        .invoice-no-hint i { color: #53c0e1; flex-shrink: 0; }
        .invoice-no-preview {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            padding-left: 16px;
            border-left: 1px dashed #bae6fd;
            min-width: 140px;
        }
        .invoice-no-preview .preview-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #64748b;
        }
        .invoice-no-preview .preview-value {
            font-family: 'Inter', ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 15px;
            font-weight: 700;
            color: #0c4a6e;
            letter-spacing: 0.5px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        @media (max-width: 780px) {
            .invoice-no-banner { grid-template-columns: auto 1fr; gap: 12px; }
            .invoice-no-preview {
                grid-column: 1 / -1;
                align-items: flex-start;
                padding-left: 0;
                padding-top: 8px;
                border-left: none;
                border-top: 1px dashed #bae6fd;
            }
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
            <div class="error-message" style="margin-bottom: 20px;"><?php echo $error; ?></div>
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
                        <select onchange="if(this.value) window.location.href='service_invoice_list.php?invoice_no='+encodeURIComponent(this.value)">
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
                <span class="inv-tag" id="headerInvoiceNo">— Enter Invoice No. —</span>
                <span class="status-unpaid">Draft</span>
            </div>
        </div>

        <!-- Sales Order Selection Modal -->
        <div id="soModal" class="so-modal-overlay">
            <div class="so-modal" style="max-width: 1050px; width: 90%;"> <!-- Slightly widened for table -->
                <div class="so-modal-header">
                    <div>
                        <h3><i data-lucide="list-checks" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i> Select Sales Order</h3>
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

                <div class="so-modal-body" style="padding: 0;">
                    <table class="so-table" id="soTableModal">
                        <thead>
                            <tr>
                                <th class="so-checkbox-cell"></th>
                                <th>SO No.</th>
                                <th>Plate No.</th>
                                <th>Brand</th>
                                <th>Model</th>
                                <th>Destination</th>
                                <th>DR No.</th>
                                <th>Created Date</th>
                                <th style="text-align:right;">Amount</th>
                                <th></th> <!-- For badges -->
                            </tr>
                        </thead>
                        <tbody id="soTableBodyModal">
                            <!-- Rows injected via JS -->
                        </tbody>
                    </table>
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
                <div class="inv-number" id="bannerInvoiceNo">—</div>
                <div class="inv-date">Date: <?php echo date('F j, Y'); ?></div>
                <div class="inv-date">Due: <span id="displayDueDate">—</span></div>
            </div>
        </div>

        <form id="invoiceForm" method="POST" action="">
            <input type="hidden" name="action" value="save_invoice">
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

                <!-- Hidden data container for JS -->
                <div id="soDataStore" style="display:none;">
                    <?php if (!empty($sales_orders)): ?>
                        <?php foreach ($sales_orders as $so): ?>
                            <?php
                                $so_no = $so['sales_order_no'];
                                $so_special = $special_charges_map[$so_no] ?? [];
                                $so_special_total = 0;
                                $so_special_vat_total = 0;
                                foreach ($so_special as $sc) {
                                    $so_special_total += $sc['amount'];
                                    $so_special_vat_total += floatval($sc['vat'] ?? 0);
                                }
                                $vat_raw = $so['vat_percent'] ?? null;
                                $vat_norm = ($vat_raw !== null && $vat_raw !== '' && floatval($vat_raw) == 12) ? 12 : 0;
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
                                 data-delivery-receipt="<?php echo htmlspecialchars($so['delivery_receipt_no'] ?? ''); ?>"
                                 data-created-date="<?php echo htmlspecialchars($so['created_date'] ?? ''); ?>"
                                 data-unit-price="<?php echo htmlspecialchars($so['unit_price'] ?? 0); ?>"
                                 data-discount-percent="<?php echo htmlspecialchars($so['discount_percent'] ?? 0); ?>"
                                 data-discount-amount="<?php echo htmlspecialchars($so['discount_amount'] ?? 0); ?>"
                                 data-vat-percent="<?php echo $vat_norm; ?>"
                                 data-net-amount="<?php echo htmlspecialchars($so['amount'] ?? 0); ?>"
                                 data-quantity="<?php echo htmlspecialchars($so['quantity'] ?? 1); ?>"
                                 data-special-charges='<?php echo htmlspecialchars(json_encode($so_special), ENT_QUOTES, "UTF-8"); ?>'
                                 data-special-total="<?php echo $so_special_total; ?>"
                                 data-special-vat-total="<?php echo $so_special_vat_total; ?>"
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

            <!-- ═══════════════════════════════════════════════════════════
                 INVOICE DETAILS + PROMINENT INVOICE NUMBER FIELD
                 ═══════════════════════════════════════════════════════════ -->
            <div class="form-card">
                <div class="form-card-title"><i data-lucide="user-check" style="width:16px;height:16px;"></i> Bill To / Invoice Details</div>

                <!-- Prominent invoice-number banner -->
                <div class="invoice-no-banner">
                    <div class="invoice-no-icon">
                        <i data-lucide="file-text" style="width:22px;height:22px;"></i>
                    </div>
                    <div class="invoice-no-field">
                        <label for="invoice_no_input">
                            Service Invoice No.
                            <span class="required-pill">Required</span>
                        </label>
                        <input type="text"
                               name="invoice_no"
                               id="invoice_no_input"
                               placeholder="Type the official invoice number… e.g. SI-2026-00001"
                               maxlength="50"
                               autocomplete="off"
                               spellcheck="false"
                               required>
                        <small class="invoice-no-hint">
                            <i data-lucide="info" style="width:12px;height:12px;"></i>
                            Enter the official invoice number printed on the document. Must be unique — the system rejects duplicates.
                        </small>
                    </div>
                    <div class="invoice-no-preview">
                        <span class="preview-label">Preview</span>
                        <span class="preview-value" id="invoiceNoPreview">—</span>
                    </div>
                </div>

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
                                <th style="width:12%;">SO No.</th>
                                <th style="width:10%;">Plate No.</th>
                                <th style="width:10%;">Brand</th>
                                <th style="width:10%;">Model</th>
                                <th style="width:20%;">Destination</th>
                                <th style="width:10%;">DR No.</th>
                                <th style="width:8%;">Qty</th>
                                <th style="width:10%;text-align:right;">Unit Price</th>
                                <th style="width:10%;text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="no-items-row">
                                <td colspan="9" style="text-align:center;color:var(--text-muted);padding:20px;">
                                    Select sales orders to populate line items
                                </td>
                            </tr>
                        </tbody>
                        <tfoot id="items-footer" style="display:none;">
                            <tr>
                                <td colspan="8" style="text-align:right;font-weight:600;">Subtotal:</td>
                                <td style="text-align:right;font-weight:600;" id="subtotal_display">₱0.00</td>
                            </tr>
                            <tr>
                                <td colspan="8" style="text-align:right;font-weight:600;">Discount:</td>
                                <td style="text-align:right;font-weight:600;color:#dc2626;" id="discount_display">−₱0.00</td>
                            </tr>
                            <tr id="vat-row">
                                <td colspan="8" style="text-align:right;font-weight:600;">VAT (12%):</td>
                                <td style="text-align:right;font-weight:600;" id="vat_display">₱0.00</td>
                            </tr>
                            <tr id="special-charges-row" style="display:none;">
                                <td colspan="8" style="text-align:right;font-weight:600;color:#b45309;">Special Charges:</td>
                                <td style="text-align:right;font-weight:600;color:#b45309;" id="special_display">₱0.00</td>
                            </tr>
                            <tr id="special-charges-vat-row" style="display:none;">
                                <td colspan="8" style="text-align:right;font-weight:600;color:#b45309;">Special Charges VAT:</td>
                                <td style="text-align:right;font-weight:600;color:#b45309;" id="special_vat_display">₱0.00</td>
                            </tr>
                            <tr style="border-top:2px solid #16a34a;">
                                <td colspan="8" style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">TOTAL:</td>
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
                        <div class="totals-row" id="special_vat_total_row" style="display:none;background:#fffbeb;border-radius:6px;padding-left:8px;padding-right:8px;">
                            <span style="color:#b45309;">Special Charges VAT</span>
                            <span id="special_vat_total" style="color:#b45309;font-weight:700;">₱0.00</span>
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

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(String(dateStr).replace(' ', 'T'));
    if (isNaN(d.getTime())) return escapeHtml(dateStr);
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================================
// VAT HELPER — VAT only applies when vat_percent == 12
// ============================================================
function computeVat(net, vatPercent) {
    const vp = parseFloat(vatPercent);
    if (isNaN(vp) || vp !== 12) return 0;
    return net * 0.12;
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
function calculateTotals() {
    const items = document.querySelectorAll('#items-body tr:not(#no-items-row)');
    let subtotal = 0;
    let totalDiscount = 0;
    let totalVat = 0;
    let totalSpecial = 0;
    let grandTotal = 0;

    let totalSpecialVat = 0;

    items.forEach(row => {
        const qty             = parseFloat(row.dataset.qty) || 1;
        const unitPrice       = parseFloat(row.dataset.unitPrice) || 0;
        const discountAmount  = parseFloat(row.dataset.discountAmount) || 0;
        const vatPercent      = parseFloat(row.dataset.vatPercent) || 0;
        const specialTotal    = parseFloat(row.dataset.specialTotal) || 0;
        const specialVatTotal = parseFloat(row.dataset.specialVatTotal) || 0;

        const gross = unitPrice * qty;
        const net   = gross - discountAmount;
        const vat   = computeVat(net, vatPercent);
        const rowTotal = net + vat + specialTotal + specialVatTotal;

        subtotal      += gross;
        totalDiscount += discountAmount;
        totalVat      += vat;
        totalSpecial  += specialTotal;
        totalSpecialVat += specialVatTotal;
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

    const vatRow = document.getElementById('vat-row');
    if (totalVat > 0) vatRow.style.display = '';
    else vatRow.style.display = 'none';

    const specialRow = document.getElementById('special-charges-row');
    const specialTotalRow = document.getElementById('special_total_row');
    const specialVatRow = document.getElementById('special-charges-vat-row');
    const specialVatTotalRow = document.getElementById('special_vat_total_row');
    if (totalSpecial > 0) {
        specialRow.style.display = '';
        specialTotalRow.style.display = 'flex';
        document.getElementById('special_display').textContent = formatPHP(totalSpecial);
        document.getElementById('special_total').textContent = formatPHP(totalSpecial);
    } else {
        specialRow.style.display = 'none';
        specialTotalRow.style.display = 'none';
    }
    if (totalSpecialVat > 0) {
        if (specialVatRow) specialVatRow.style.display = '';
        if (specialVatTotalRow) specialVatTotalRow.style.display = 'flex';
        const svd = document.getElementById('special_vat_display');
        const svt = document.getElementById('special_vat_total');
        if (svd) svd.textContent = formatPHP(totalSpecialVat);
        if (svt) svt.textContent = formatPHP(totalSpecialVat);
    } else {
        if (specialVatRow) specialVatRow.style.display = 'none';
        if (specialVatTotalRow) specialVatTotalRow.style.display = 'none';
    }

    document.getElementById('amount').value = subtotal.toFixed(2);
    document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
    document.getElementById('vat_amount').value = totalVat.toFixed(2);
    document.getElementById('total_amount').value = grandTotal.toFixed(2);
}

function calculateDueDate() {
    const terms = document.getElementById('payment_terms').value;
    const dueDateInput = document.getElementById('due_date');
    const displayDueDate = document.getElementById('displayDueDate');

    if (!terms || terms.trim() === '') return;

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

// ============================================================
// INVOICE NUMBER — sync header / banner / preview displays
// ============================================================
function syncInvoiceNoDisplay() {
    const input  = document.getElementById('invoice_no_input');
    const header = document.getElementById('headerInvoiceNo');
    const banner = document.getElementById('bannerInvoiceNo');
    const preview = document.getElementById('invoiceNoPreview');

    // Collapse whitespace + uppercase
    const raw = (input.value || '').replace(/\s+/g, ' ').trim();
    const val = raw.toUpperCase();

    // Keep the input value clean (avoid caret jumps)
    if (input.value !== val) {
        input.value = val;
    }

    if (val) {
        header.textContent  = val;
        banner.textContent  = val;
        preview.textContent = val;
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
    } else {
        header.textContent  = '— Enter Invoice No. —';
        banner.textContent  = '—';
        preview.textContent = '—';
        input.classList.remove('is-valid');
    }
}

// ============================================================
// SALES ORDER MODAL
// ============================================================
function buildSOModalContent() {
    const tbody = document.getElementById('soTableBodyModal');
    const dataItems = document.querySelectorAll('#soDataStore .so-data-item');
    
    if (dataItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8;">No sales orders available.</td></tr>';
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
            const vatP = parseFloat(o.dataset.vatPercent) || 0;
            const spec = parseFloat(o.dataset.specialTotal) || 0;
            const specVat = parseFloat(o.dataset.specialVatTotal) || 0;
            const net = (up * qty) - disc;
            groupTotal += net + computeVat(net, vatP) + spec + specVat;
        });
        
        // Group header row
        html += `
            <tr style="background-color: #f1f5f9; border-top: 2px solid #e2e8f0;">
                <td colspan="10" style="padding: 10px 12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span>
                            <span style="background:#e2e8f0;color:#475569;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-right:8px;">${escapeHtml(group.code)}</span>
                            <strong style="font-size:13px;color:#0f172a;">${escapeHtml(group.name)}</strong>
                        </span>
                        <span style="font-size:12px;color:#475569;">
                            ${group.orders.length} order(s) &nbsp;|&nbsp; Total: <strong style="color:#16a34a;">${formatPHP(groupTotal)}</strong>
                        </span>
                    </div>
                </td>
            </tr>
        `;
        
        group.orders.forEach(item => {
            const soNo    = item.dataset.soId;
            const plate   = item.dataset.plate || '';
            const brand   = item.dataset.brand || '';
            const model   = item.dataset.model || '';
            const drNo    = item.dataset.deliveryReceipt || '';
            const createdDate = item.dataset.createdDate || '';
            const from    = (item.dataset.destinationFrom || '').substring(0, 14);
            const to      = (item.dataset.destinationTo   || '').substring(0, 14);
            const qty     = parseFloat(item.dataset.quantity) || 1;
            const up      = parseFloat(item.dataset.unitPrice) || 0;
            const disc    = parseFloat(item.dataset.discountAmount) || 0;
            const vatP    = parseFloat(item.dataset.vatPercent) || 0;
            const spec    = parseFloat(item.dataset.specialTotal) || 0;
            const specVat = parseFloat(item.dataset.specialVatTotal) || 0;
            const net     = (up * qty) - disc;
            const total   = net + computeVat(net, vatP) + spec + specVat;

            const isChecked = selectedData.some(d => d.sales_order_no === soNo);
            const alreadyInvoiced = item.dataset.alreadyInvoiced === '1';
            const existingInvoiceNo = item.dataset.existingInvoice || '';
            const customerMismatch = selectedCustomerCode && selectedCustomerCode !== item.dataset.customerCode;
            const disabled = alreadyInvoiced || customerMismatch;

            let rowClass = 'so-row';
            if (isChecked) rowClass += ' selected';
            if (alreadyInvoiced) rowClass += ' invoiced';

            let badgeHtml = '';
            if (alreadyInvoiced) {
                badgeHtml = `<span class="so-badge so-badge-invoiced" title="Invoice #${escapeHtml(existingInvoiceNo)}">✓ Invoiced</span>`;
            } else if (customerMismatch) {
                badgeHtml = `<span class="so-badge so-badge-locked">Locked</span>`;
            }

            html += `
                <tr class="${rowClass}" data-customer="${escapeHtml(item.dataset.customerCode)}" data-so-no="${escapeHtml(soNo)}">
                    <td class="so-checkbox-cell">
                        <input type="checkbox" 
                               value="${escapeHtml(soNo)}"
                               data-already-invoiced="${alreadyInvoiced ? '1' : '0'}"
                               data-existing-invoice="${escapeHtml(existingInvoiceNo)}"
                               data-so-id="${escapeHtml(soNo)}"
                               data-customer-code="${escapeHtml(item.dataset.customerCode)}"
                               data-customer-name="${escapeHtml(item.dataset.customerName)}"
                               data-truck="${escapeHtml(item.dataset.truck || '')}"
                               data-plate="${escapeHtml(plate)}"
                               data-brand="${escapeHtml(brand)}"
                               data-model="${escapeHtml(model)}"
                               data-delivery-receipt="${escapeHtml(drNo)}"
                               data-created-date="${escapeHtml(createdDate)}"
                               data-unit-price="${item.dataset.unitPrice || 0}"
                               data-quantity="${item.dataset.quantity || 1}"
                               data-discount-percent="${item.dataset.discountPercent || 0}"
                               data-discount-amount="${item.dataset.discountAmount || 0}"
                               data-vat-percent="${item.dataset.vatPercent || 0}"
                               data-special-total="${item.dataset.specialTotal || 0}"
                               data-special-vat-total="${item.dataset.specialVatTotal || 0}"
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
                    </td>
                    <td class="so-so-code">${escapeHtml(soNo)}</td>
                    <td>${escapeHtml(plate || '—')}</td>
                    <td>${escapeHtml(brand || '—')}</td>
                    <td>${escapeHtml(model || '—')}</td>
                    <td class="so-dest">${escapeHtml(from)} → ${escapeHtml(to)}</td>
                    <td>${escapeHtml(drNo || '—')}</td>
                    <td class="so-text-muted">${formatDate(createdDate)}</td>
                    <td class="so-text-right">${formatPHP(total)}</td>
                    <td style="text-align:right;">${badgeHtml}</td>
                </tr>
            `;
        });
    });
    
    tbody.innerHTML = html;
    lucide.createIcons();
    
    const firstItem = dataItems[0];
    document.getElementById('modalCustomerName').textContent = firstItem.dataset.customerName;
    updateModalCounters();
}

// Add click listener to rows for easier selection
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('soTableBodyModal').addEventListener('click', function(e) {
        // If the click was directly on the checkbox, let its own handler take care of it
        if (e.target.tagName.toLowerCase() === 'input') return;
        
        const tr = e.target.closest('tr');
        if (!tr || !tr.classList.contains('so-row')) return;
        
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb && !cb.disabled) {
            cb.checked = !cb.checked;
            handleCheckboxChange(cb);
        }
    });
});

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
        total += net + computeVat(net, d.vat_percent) + d.special_total + (d.special_vat_total || 0);
    });
    document.getElementById('modalSelectedCount').textContent = count;
    document.getElementById('modalSelectedTotal').textContent = formatPHP(total);
}

function selectAllInModal() {
    document.querySelectorAll('#soTableBodyModal input[type="checkbox"]:not(:disabled)').forEach(cb => {
        if (cb.dataset.alreadyInvoiced === '1') return;
        if (!cb.checked) {
            cb.checked = true;
            handleCheckboxChange(cb);
        }
    });
}

function deselectAllInModal() {
    document.querySelectorAll('#soTableBodyModal input[type="checkbox"]').forEach(cb => { cb.checked = false; });
    selectedCustomerCode = '';
    selectedCustomerName = '';
    selectedData = [];
    document.querySelectorAll('#soTableBodyModal .so-row').forEach(tr => {
        tr.classList.remove('selected');
        const cb = tr.querySelector('input[type="checkbox"]');
        if (cb) {
            cb.disabled = cb.dataset.alreadyInvoiced === '1';
        }
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
    const tr = checkbox.closest('tr');

    if (checked && checkbox.dataset.alreadyInvoiced === '1') {
        const invNo = checkbox.dataset.existingInvoice || 'an existing invoice';
        alert('This Sales Order has already been invoiced (' + invNo + ').\nIt cannot be added to a new invoice.');
        checkbox.checked = false;
        return;
    }

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
    
    if (checked) {
        tr.classList.add('selected');
    } else {
        tr.classList.remove('selected');
    }
    
    const allRows = document.querySelectorAll('#soTableBodyModal .so-row');
    let anyChecked = false;
    selectedData = [];
    
    allRows.forEach(row => {
        const cb = row.querySelector('input[type="checkbox"]');
        if (cb && cb.checked) {
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
                delivery_receipt_no: cb.dataset.deliveryReceipt || '',
                created_date: cb.dataset.createdDate || '',
                unit_price: parseFloat(cb.dataset.unitPrice) || 0,
                quantity: parseFloat(cb.dataset.quantity) || 1,
                discount_percent: parseFloat(cb.dataset.discountPercent) || 0,
                discount_amount: parseFloat(cb.dataset.discountAmount) || 0,
                vat_percent: (parseFloat(cb.dataset.vatPercent) === 12) ? 12 : 0,
                special_total: parseFloat(cb.dataset.specialTotal) || 0,
                special_vat_total: parseFloat(cb.dataset.specialVatTotal) || 0,
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
        allRows.forEach(row => {
            const cb = row.querySelector('input[type="checkbox"]');
            if (!cb) return;
            
            const isInvoiced = cb.dataset.alreadyInvoiced === '1';
            const isDifferentCustomer = cb.dataset.customerCode !== selectedCustomerCode && !cb.checked;

            if (isInvoiced) {
                cb.disabled = true;
            } else if (isDifferentCustomer) {
                cb.disabled = true;
            } else {
                cb.disabled = false;
            }
        });
    } else {
        allRows.forEach(row => {
            const cb = row.querySelector('input[type="checkbox"]');
            if (!cb) return;
            cb.disabled = cb.dataset.alreadyInvoiced === '1';
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
// RENDER LINE ITEMS
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
        row.dataset.specialVatTotal = data.special_vat_total || 0;
        
        const from = (data.destination_from || '').substring(0, 15);
        const to = (data.destination_to || '').substring(0, 15);
        
        const net = (data.unit_price * data.quantity) - data.discount_amount;
        const vat = computeVat(net, data.vat_percent);
        const rowTotal = net + vat + data.special_total + (data.special_vat_total || 0);
        
        row.innerHTML = `
            <td><strong>${escapeHtml(data.sales_order_no)}</strong></td>
            <td>${escapeHtml(data.plate_number || '—')}</td>
            <td>${escapeHtml(data.brand || '—')}</td>
            <td>${escapeHtml(data.model || '—')}</td>
            <td style="font-size:11px;">${escapeHtml(from)} → ${escapeHtml(to)}</td>
            <td>${escapeHtml(data.delivery_receipt_no || '—')}</td>
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
        total += net + computeVat(net, data.vat_percent) + data.special_total + (data.special_vat_total || 0);
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

function calculateDueDateFromTerms(terms) {
    const dueDateInput = document.getElementById('due_date');
    const displayDueDate = document.getElementById('displayDueDate');

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
            dueDateInput.value = '';
            displayDueDate.textContent = '—';
            return;
        }
    }

    dueDateInput.value = dueDate.toISOString().split('T')[0];
    displayDueDate.textContent = dueDateInput.value;
}

function validateForm() {
    const invoiceNoInput = document.getElementById('invoice_no_input');
    const invoiceNo = (invoiceNoInput.value || '').trim();

    if (invoiceNo === '') {
        invoiceNoInput.classList.add('is-invalid');
        invoiceNoInput.classList.remove('is-valid');
        invoiceNoInput.focus();
        alert('Please enter the Service Invoice number.');
        return false;
    }

    if (invoiceNo.length > 50) {
        invoiceNoInput.classList.add('is-invalid');
        invoiceNoInput.classList.remove('is-valid');
        alert('Invoice number must not exceed 50 characters.');
        return false;
    }

    invoiceNoInput.classList.remove('is-invalid');

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
    const invNoInput = document.getElementById('invoice_no_input');
    invNoInput.addEventListener('input', syncInvoiceNoDisplay);
    invNoInput.addEventListener('keyup', syncInvoiceNoDisplay);
    invNoInput.addEventListener('blur', syncInvoiceNoDisplay);
    syncInvoiceNoDisplay();

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