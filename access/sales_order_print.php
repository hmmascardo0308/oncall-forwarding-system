<?php
// sales_order_print.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$so_no = $_GET['so'] ?? '';
if (!$so_no) {
    die("Sales Order number is required.");
}

$safe_so_no = $conn->real_escape_string($so_no);

$query = "SELECT so.*, 
                 cm.full_address, cm.tin, cm.contact_person, 
                 el.full_name as driver_name,
                 tm.truck_type   as tm_truck_type,
                 tm.wheel_count  as tm_wheel_count,
                 tm.footer       as tm_footer,
                 tm.body_type    as tm_body_type
          FROM `oncall_forwarding`.`sales_order` so
          LEFT JOIN `oncall_forwarding`.`customer_masterlist` cm 
                 ON so.customer_code = cm.customer_code
          LEFT JOIN `oncall_forwarding`.`employee_list` el 
                 ON so.driver = el.employee_code COLLATE utf8mb4_general_ci
          LEFT JOIN `oncall_forwarding`.`truck_masterlist` tm 
                 ON so.truck_code = tm.truck_code COLLATE utf8mb4_general_ci
          WHERE so.sales_order_no = '{$safe_so_no}' LIMIT 1";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Sales Order not found.");
}

$so = $result->fetch_assoc();

// ── Fetch special charges (include charge_vat) ────────────────────────────────
$special_charges           = [];
$special_charges_total     = 0.0;
$special_charges_vat_total = 0.0;

$charge_res = $conn->query("
    SELECT charge_kind, charge_amount, charge_vat
    FROM `oncall_forwarding`.`sales_order_special_charge`
    WHERE sales_order_no = '{$safe_so_no}'
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
            'vat'    => $vat,
        ];
        $special_charges_total     += $amount;
        $special_charges_vat_total += $vat;
    }
}

// special_charges_vat from SO header (12 or NULL)
$sc_vat_is_enabled = ($so['special_charges_vat'] !== null && $so['special_charges_vat'] !== '');
$sc_vat_percent    = $sc_vat_is_enabled ? floatval($so['special_charges_vat']) : 0.0;

// ── Resolve truck display fields ─────────────────────────────────────────────
// Footer is read from sales_order.footer (the priced combo footer at the time
// of creation) — NOT from truck_masterlist, which can change over time.
// Fall back to tm_footer only for legacy rows saved before the footer fix.
$truck_type_display  = $so['truck_type']  ?? ($so['tm_truck_type']  ?? '');
$wheel_count_display = $so['tm_wheel_count'] ?? '';

$footer_display = $so['footer'] ?? '';
if ($footer_display === null || $footer_display === '') {
    $footer_display = $so['tm_footer'] ?? '';
}

$body_type_display   = $so['tm_body_type']   ?? '';

// ── Totals ────────────────────────────────────────────────────────────────────
$vat_percent_safe = (float)($so['vat_percent'] ?? 0);   // NULL → 0 (NON-VAT)

$subtotal        = $so['quantity'] * $so['unit_price'];
$discount_amount = $so['discount_amount'];
$net_of_discount = $so['amount']; // already net of discount
$vat_amount      = $net_of_discount * ($vat_percent_safe / 100);
$total_due       = $net_of_discount + $vat_amount + $special_charges_total + $special_charges_vat_total;

$driver_name = $so['driver_name'] ?? $so['driver'] ?? 'Not Assigned';

// ── Line item description ────────────────────────────────────────────────────
$line_item_title = 'Trucking Service: ' . trim(($so['brand'] ?? '') . ' ' . ($so['model'] ?? '')) . ' (' . ($so['plate_number'] ?? '') . ')';

// Footer is a text field (e.g. "20 Footer", "40 Footer"), so DO NOT cast to int.
$line_item_meta_parts = [];
if (!empty($truck_type_display))  $line_item_meta_parts[] = htmlspecialchars($truck_type_display);
if (!empty($wheel_count_display)) $line_item_meta_parts[] = (int)$wheel_count_display . ' Wheels';
if (!empty($footer_display))      $line_item_meta_parts[] = htmlspecialchars($footer_display);
if (!empty($body_type_display))   $line_item_meta_parts[] = htmlspecialchars($body_type_display);
$line_item_meta = implode(' · ', $line_item_meta_parts);

$route_text = htmlspecialchars($so['destination_from'] ?? '') . ' → ' . htmlspecialchars($so['destination_to'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Order - <?= htmlspecialchars($so['sales_order_no']) ?></title>
    <link rel="stylesheet" href="css/so_print.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <style>
        /* ── Special charges (print-friendly) ─────────────────────────── */
        .special-charges-block {
            margin-top: 8px;
            margin-bottom: 8px;
        }
        .special-charges-block h4 {
            font-size: 11px;
            font-weight: 700;
            color: #92400e;
            margin: 0 0 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .special-charges-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .special-charges-table th,
        .special-charges-table td {
            border: 1px solid #d1d5db;
            padding: 3px 6px;
            text-align: left;
        }
        .special-charges-table th {
            background: #fef3c7;
            color: #92400e;
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .special-charges-table td.amount {
            text-align: right;
            font-weight: 600;
            white-space: nowrap;
            color: #92400e;
        }
        .special-charges-table tr.special-total td {
            background: #fffbeb;
            font-weight: 800;
            color: #92400e;
            border-top: 2px solid #fcd34d;
        }
    </style>
</head>
<body>

<button class="print-button no-print" onclick="window.print()">🖨️ Print</button>

<div class="print-container">

    <!-- =========================================================
         GUARD'S COPY (Upper Half)
         ========================================================= -->
    <div class="copy-wrapper">
        <div class="watermark watermark-guard">GUARD'S COPY</div>
        <div class="copy-label copy-label-guard">GUARD'S COPY</div>

        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>ONCALL FORWARDING CORPORATION</h1>
                <p>Green Field Subd., Inayawan, Cebu City</p>
                <p>Fax/Tel. 420-0946 / 383-7076</p>
                <p>Vat Reg. TIN: 240-099-925-000</p>
            </div>
            <div class="so-title">
                <h2>SALES ORDER</h2>
                <p><strong>SO No:</strong> <?= htmlspecialchars($so['sales_order_no']) ?></p>
                <p><strong>Status:</strong> <?= htmlspecialchars($so['status']) ?></p>
            </div>
        </div>

        <!-- Details Grid -->
        <div class="details-grid">
            <div class="grid-section">
                <h3>Bill To</h3>
                <p><strong>Customer:</strong> <?= htmlspecialchars($so['customer_name']) ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($so['full_address'] ?? 'N/A') ?></p>
                <p><strong>TIN:</strong> <?= htmlspecialchars($so['tin'] ?? 'N/A') ?></p>
                <p><strong>Contact:</strong> <?= htmlspecialchars($so['contact_person'] ?? 'N/A') ?></p>
            </div>
            <div class="grid-section">
                <h3>Details</h3>
                <p><strong>Order Date:</strong> <?= date('M d, Y', strtotime($so['order_date'])) ?></p>
                <p><strong>Delivery Date:</strong> <?= !empty($so['delivery_date']) ? date('M d, Y', strtotime($so['delivery_date'])) : 'N/A' ?></p>
                <p><strong>Payment Terms:</strong> <?= htmlspecialchars($so['payment_terms'] ?? 'N/A') ?></p>
                <p><strong>Sales Rep:</strong> <?= htmlspecialchars(!empty($so['sales_rep']) ? $so['sales_rep'] : ($so['contact_person'] ?? 'N/A')) ?></p>
                <p><strong>Driver:</strong> <?= htmlspecialchars($driver_name) ?></p>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:35%">Description</th>
                    <th style="width:10%" class="text-center">Qty</th>
                    <th style="width:10%">Unit</th>
                    <th style="width:20%" class="text-right">Unit Price</th>
                    <th style="width:20%" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>
                        <?= htmlspecialchars($line_item_title) ?> | 
                        <?php if (!empty($line_item_meta)): ?>
                            <small><?= $line_item_meta ?></small>|
                        <?php endif; ?>
                        <small>Route: <?= $route_text ?></small>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($so['quantity']) ?></td>
                    <td><?= htmlspecialchars($so['unit']) ?></td>
                    <td class="text-right"><?= number_format($so['unit_price'], 2) ?></td>
                    <td class="text-right"><?= number_format($so['quantity'] * $so['unit_price'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Special Charges -->
        <?php if (!empty($special_charges)): ?>
        <div class="special-charges-block">
            <h4>Special Charges<?= $sc_vat_is_enabled ? ' (VAT ' . number_format($sc_vat_percent, 0) . '% applied)' : '' ?></h4>
            <table class="special-charges-table">
                <thead>
                    <tr>
                        <th style="width:8%;">#</th>
                        <th>Description</th>
                        <th style="width:20%; text-align:right;">Amount</th>
                        <?php if ($sc_vat_is_enabled): ?>
                        <th style="width:18%; text-align:right;">VAT</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($special_charges as $idx => $charge): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= htmlspecialchars($charge['kind']) ?></td>
                        <td class="amount">₱ <?= number_format($charge['amount'], 2) ?></td>
                        <?php if ($sc_vat_is_enabled): ?>
                        <td class="amount">₱ <?= number_format($charge['vat'], 2) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($sc_vat_is_enabled && $special_charges_vat_total > 0): ?>
                    <tr class="special-total">
                        <td colspan="2" style="text-align:right;">Total Special Charges VAT</td>
                        <td class="amount" colspan="2">₱ <?= number_format($special_charges_vat_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tbody>
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value"><?= number_format($subtotal, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Discount (<?= number_format($so['discount_percent'], 2) ?>%)</td>
                        <td class="value">- <?= number_format($discount_amount, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Net of Discount</td>
                        <td class="value"><?= number_format($net_of_discount, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">
                            <?= ($vat_percent_safe > 0) ? 'VAT (' . number_format($vat_percent_safe, 0) . '%)' : 'VAT' ?>
                        </td>
                        <td class="value">
                            <?= ($vat_percent_safe > 0) ? number_format($vat_amount, 2) : 'NON-VAT' ?>
                        </td>
                    </tr>
                    <?php if ($special_charges_total > 0): ?>
                    <tr>
                        <td class="label" style="color:#92400e;">Special Charges</td>
                        <td class="value" style="color:#92400e;font-weight:700;"><?= number_format($special_charges_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($special_charges_vat_total > 0): ?>
                    <tr>
                        <td class="label" style="color:#92400e;">Special Charges VAT (<?= number_format($sc_vat_percent, 0) ?>%)</td>
                        <td class="value" style="color:#92400e;font-weight:700;"><?= number_format($special_charges_vat_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="grand-total">
                        <td class="label">Total Due</td>
                        <td class="value">₱ <?= number_format($total_due, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if (!empty($so['notes'])): ?>
        <div class="footer-notes">
            <h4>Notes & Instructions:</h4>
            <p><?= nl2br(htmlspecialchars($so['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cut Line -->
    <div class="cut-line no-print">
        <span>✂ CUT HERE ✂</span>
    </div>

    <!-- =========================================================
         DRIVER'S COPY (Lower Half)
         ========================================================= -->
    <div class="copy-wrapper">
        <div class="watermark watermark-driver">DRIVER'S COPY</div>
        <div class="copy-label copy-label-driver">DRIVER'S COPY</div>

        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <h1>ONCALL FORWARDING CORPORATION</h1>
                <p>Green Field Subd., Inayawan, Cebu City</p>
                <p>Fax/Tel. 420-0946 / 383-7076</p>
                <p>Vat Reg. TIN: 240-099-925-000</p>
            </div>
            <div class="so-title">
                <h2>SALES ORDER</h2>
                <p><strong>SO No:</strong> <?= htmlspecialchars($so['sales_order_no']) ?></p>
                <p><strong>Status:</strong> <?= htmlspecialchars($so['status']) ?></p>
            </div>
        </div>

        <!-- Details Grid -->
        <div class="details-grid">
            <div class="grid-section">
                <h3>Bill To</h3>
                <p><strong>Customer:</strong> <?= htmlspecialchars($so['customer_name']) ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($so['full_address'] ?? 'N/A') ?></p>
                <p><strong>TIN:</strong> <?= htmlspecialchars($so['tin'] ?? 'N/A') ?></p>
                <p><strong>Contact:</strong> <?= htmlspecialchars($so['contact_person'] ?? 'N/A') ?></p>
            </div>
            <div class="grid-section">
                <h3>Details</h3>
                <p><strong>Order Date:</strong> <?= date('M d, Y', strtotime($so['order_date'])) ?></p>
                <p><strong>Delivery Date:</strong> <?= !empty($so['delivery_date']) ? date('M d, Y', strtotime($so['delivery_date'])) : 'N/A' ?></p>
                <p><strong>Payment Terms:</strong> <?= htmlspecialchars($so['payment_terms'] ?? 'N/A') ?></p>
                <p><strong>Sales Rep:</strong> <?= htmlspecialchars(!empty($so['sales_rep']) ? $so['sales_rep'] : ($so['contact_person'] ?? 'N/A')) ?></p>
                <p><strong>Driver:</strong> <?= htmlspecialchars($driver_name) ?></p>
                <p><strong>Container Number:</strong> ___________________________</p>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:35%">Description</th>
                    <th style="width:10%" class="text-center">Qty</th>
                    <th style="width:10%">Unit</th>
                    <th style="width:20%" class="text-right">Unit Price</th>
                    <th style="width:20%" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>
                        <?= htmlspecialchars($line_item_title) ?> |
                        <?php if (!empty($line_item_meta)): ?>
                            <small><?= $line_item_meta ?></small>|
                        <?php endif; ?>
                        <small>Route: <?= $route_text ?></small>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($so['quantity']) ?></td>
                    <td><?= htmlspecialchars($so['unit']) ?></td>
                    <td class="text-right"><?= number_format($so['unit_price'], 2) ?></td>
                    <td class="text-right"><?= number_format($so['quantity'] * $so['unit_price'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Special Charges -->
        <?php if (!empty($special_charges)): ?>
        <div class="special-charges-block">
            <h4>Special Charges<?= $sc_vat_is_enabled ? ' (VAT ' . number_format($sc_vat_percent, 0) . '% applied)' : '' ?></h4>
            <table class="special-charges-table">
                <thead>
                    <tr>
                        <th style="width:8%;">#</th>
                        <th>Description</th>
                        <th style="width:20%; text-align:right;">Amount</th>
                        <?php if ($sc_vat_is_enabled): ?>
                        <th style="width:18%; text-align:right;">VAT</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($special_charges as $idx => $charge): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= htmlspecialchars($charge['kind']) ?></td>
                        <td class="amount">₱ <?= number_format($charge['amount'], 2) ?></td>
                        <?php if ($sc_vat_is_enabled): ?>
                        <td class="amount">₱ <?= number_format($charge['vat'], 2) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($sc_vat_is_enabled && $special_charges_vat_total > 0): ?>
                    <tr class="special-total">
                        <td colspan="2" style="text-align:right;">Total Special Charges VAT</td>
                        <td class="amount" colspan="2">₱ <?= number_format($special_charges_vat_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tbody>
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value"><?= number_format($subtotal, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Discount (<?= number_format($so['discount_percent'], 2) ?>%)</td>
                        <td class="value">- <?= number_format($discount_amount, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Net of Discount</td>
                        <td class="value"><?= number_format($net_of_discount, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">
                            <?= ($vat_percent_safe > 0) ? 'VAT (' . number_format($vat_percent_safe, 0) . '%)' : 'VAT' ?>
                        </td>
                        <td class="value">
                            <?= ($vat_percent_safe > 0) ? number_format($vat_amount, 2) : 'NON-VAT' ?>
                        </td>
                    </tr>
                    <?php if ($special_charges_total > 0): ?>
                    <tr>
                        <td class="label" style="color:#92400e;">Special Charges</td>
                        <td class="value" style="color:#92400e;font-weight:700;"><?= number_format($special_charges_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($special_charges_vat_total > 0): ?>
                    <tr>
                        <td class="label" style="color:#92400e;">Special Charges VAT (<?= number_format($sc_vat_percent, 0) ?>%)</td>
                        <td class="value" style="color:#92400e;font-weight:700;"><?= number_format($special_charges_vat_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="grand-total">
                        <td class="label">Total Due</td>
                        <td class="value">₱ <?= number_format($total_due, 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if (!empty($so['notes'])): ?>
        <div class="footer-notes">
            <h4>Notes & Instructions:</h4>
            <p><?= nl2br(htmlspecialchars($so['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Signature Section for Driver -->
        <div class="signature-section">
            <div>
                <p><strong>Received by:</strong> ___________________________</p>
                <p class="sub-text">Signature over printed name</p>
            </div>
            <div style="text-align: right;">
                <p><strong>Date:</strong> _______________</p>
            </div>
        </div>
    </div>

</div>

</body>
</html>