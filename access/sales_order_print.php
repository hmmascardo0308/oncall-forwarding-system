<?php
// sales_order_print.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    // Or just show an error, since this is for printing.
    die("Unauthorized access.");
}

$so_no = $_GET['so'] ?? '';
if (!$so_no) {
    die("Sales Order number is required.");
}

$safe_so_no = $conn->real_escape_string($so_no);
$query = "SELECT so.*, cm.full_address, cm.tin, cm.contact_person, el.full_name as driver_name
          FROM `oncall_forwarding`.`sales_order` so
          LEFT JOIN `oncall_forwarding`.`customer_masterlist` cm ON so.customer_code = cm.customer_code
          LEFT JOIN `oncall_forwarding`.`employee_list` el ON so.driver = el.employee_code COLLATE utf8mb4_general_ci
          WHERE so.sales_order_no = '{$safe_so_no}' LIMIT 1";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Sales Order not found.");
}

$so = $result->fetch_assoc();

// Calculate totals
$subtotal = $so['amount'];
$discount_amount = $so['discount_amount'];
$net_of_discount = $subtotal; // The 'amount' field is already net of discount
$vat_amount = $net_of_discount * ($so['vat_percent'] / 100);
$total_due = $net_of_discount + $vat_amount;

// Get driver name
$driver_name = $so['driver_name'] ?? $so['driver'] ?? 'Not Assigned';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Order - <?= htmlspecialchars($so['sales_order_no']) ?></title>
    <link rel="stylesheet" href="css/so_print.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
  
</head>
<body>

<button class="print-button no-print" onclick="window.print()">🖨️ Print</button>

<div class="print-container">
    
    <!-- GUARD'S COPY (Upper Half) -->
    <div class="copy-wrapper">
        <!-- Watermark - GUARD'S COPY -->
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
                        Trucking Service: <?= htmlspecialchars($so['brand'] . ' ' . $so['model']) ?> (<?= htmlspecialchars($so['plate_number']) ?>)<br>
                        <small>Route: <?= htmlspecialchars($so['destination_from']) ?> → <?= htmlspecialchars($so['destination_to']) ?></small>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($so['quantity']) ?></td>
                    <td><?= htmlspecialchars($so['unit']) ?></td>
                    <td class="text-right"><?= number_format($so['unit_price'], 2) ?></td>
                    <td class="text-right"><?= number_format($so['quantity'] * $so['unit_price'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tbody>
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value"><?= number_format($so['quantity'] * $so['unit_price'], 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Discount (<?= number_format($so['discount_percent'], 2) ?>%)</td>
                        <td class="value">- <?= number_format($so['discount_amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Net of Discount</td>
                        <td class="value"><?= number_format($net_of_discount, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">VAT (<?= number_format($so['vat_percent'], 0) ?>%)</td>
                        <td class="value"><?= number_format($vat_amount, 2) ?></td>
                    </tr>
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

    <!-- DRIVER'S COPY (Lower Half) -->
    <div class="copy-wrapper">
        <!-- Watermark - DRIVER'S COPY -->
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
                        Trucking Service: <?= htmlspecialchars($so['brand'] . ' ' . $so['model']) ?> (<?= htmlspecialchars($so['plate_number']) ?>)<br>
                        <small>Route: <?= htmlspecialchars($so['destination_from']) ?> → <?= htmlspecialchars($so['destination_to']) ?></small>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($so['quantity']) ?></td>
                    <td><?= htmlspecialchars($so['unit']) ?></td>
                    <td class="text-right"><?= number_format($so['unit_price'], 2) ?></td>
                    <td class="text-right"><?= number_format($so['quantity'] * $so['unit_price'], 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tbody>
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value"><?= number_format($so['quantity'] * $so['unit_price'], 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Discount (<?= number_format($so['discount_percent'], 2) ?>%)</td>
                        <td class="value">- <?= number_format($so['discount_amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Net of Discount</td>
                        <td class="value"><?= number_format($net_of_discount, 2) ?></td>
                    </tr>
                    <tr>
                        <td class="label">VAT (<?= number_format($so['vat_percent'], 0) ?>%)</td>
                        <td class="value"><?= number_format($vat_amount, 2) ?></td>
                    </tr>
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