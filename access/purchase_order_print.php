<?php
date_default_timezone_set('Asia/Manila');
// purchase_order_print.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

$po_number = $_GET['po_number'] ?? '';
if (!$po_number) {
    die("Purchase Order number is required.");
}

$safe_po_number = $conn->real_escape_string($po_number);

// Get PO header info
$header_query = $conn->query("SELECT * FROM purchase_order WHERE po_number = '$safe_po_number' LIMIT 1");
$po_details = $header_query->fetch_assoc();

if (!$po_details) {
    die("Purchase Order not found.");
}

// Check if this is a reprint - check the URL parameter
$is_reprint = isset($_GET['is_reprint']) && $_GET['is_reprint'] == 1;

// Get requested_by directly from the purchase_order table
$requested_by = $po_details['requested_by'] ?? '';

// Get all items for this PO
$items_query = $conn->query("SELECT * FROM purchase_order WHERE po_number = '$safe_po_number' ORDER BY id");
$po_items = [];
while ($row = $items_query->fetch_assoc()) {
    $po_items[] = $row;
}

if (empty($po_items)) {
    die("No items found for this Purchase Order.");
}

// ─── CORRECTED TOTALS – matches purchase_order.php creation logic exactly ──
// Unit costs are VAT-inclusive when with_vat = 1.

$first_item = $po_items[0];

$item_subtotal_before_discounts = 0;
$total_item_discount            = 0;

foreach ($po_items as $item) {
    $unit_cost     = floatval($item['unit_cost']);
    $qty_ordered   = intval($item['qty_ordered']);
    $item_discount = floatval($item['item_discount_amount'] ?? 0);

    $item_subtotal_before_discounts += $unit_cost * $qty_ordered;
    $total_item_discount            += $item_discount;
}

$discounted_inclusive = $item_subtotal_before_discounts - $total_item_discount;

$with_vat                = intval($first_item['with_vat'] ?? 1);
$po_discount_amount      = floatval($first_item['discount_amount'] ?? 0);
$po_discount_percent     = floatval($first_item['discount_percent'] ?? 0);
$freight                 = floatval($first_item['freight'] ?? 0);
$withholding_tax_percent = floatval($first_item['withholding_tax_percent'] ?? 0);

if ($with_vat) {
    // Subtotal (excl. tax) BEFORE PO discount
    $display_subtotal = $discounted_inclusive / 1.12;
} else {
    $display_subtotal = $discounted_inclusive;
}

// PO discount applied to exclusive subtotal
$subtotal_after_po_discount = $display_subtotal - $po_discount_amount;
if ($subtotal_after_po_discount < 0) $subtotal_after_po_discount = 0;

// VAT recomputed on the discounted exclusive subtotal
if ($with_vat) {
    $display_total_vat = $subtotal_after_po_discount * 0.12;
} else {
    $display_total_vat = 0;
}

$grand_total = $subtotal_after_po_discount + $display_total_vat + $freight;

// Withholding base = exclusive subtotal AFTER PO discount
$wht_base = $subtotal_after_po_discount;
if ($withholding_tax_percent > 0) {
    $withholding_tax_amount = $wht_base * ($withholding_tax_percent / 100);
    $display_net_amount_due = $grand_total - $withholding_tax_amount;
} else {
    $withholding_tax_amount = 0;
    $display_net_amount_due = $grand_total;
}

// Aliases used by the rest of the template
$discount_amount  = $po_discount_amount;
$discount_percent = $po_discount_percent;

// Get company profile
$company_query = $conn->query("SELECT * FROM company_profile LIMIT 1");
$company = $company_query->fetch_assoc();

$company_name = $company['company_name'] ?? 'ONCALL FORWARDING CORPORATION';
$company_address = $company['address'] ?? 'Lopez Compound, F. Jaca St., Inayawan Laray, Talisay City, Cebu';
$company_phone = $company['phone'] ?? '(032) 383-7076';
$company_tin = $company['tin'] ?? '240-099-925-000';

// Get supplier address from supplier_lists
$supplier_code = $po_details['supplier_code'] ?? '';
$supplier_address = '';
if ($supplier_code) {
    $safe_supplier_code = $conn->real_escape_string($supplier_code);
    $supplier_query = $conn->query("SELECT full_address FROM supplier_lists WHERE supplier_code = '$safe_supplier_code' LIMIT 1");
    if ($supplier_query && $supplier_query->num_rows > 0) {
        $supplier_data = $supplier_query->fetch_assoc();
        $supplier_address = strtoupper($supplier_data['full_address'] ?? '');
    }
}

// Get user full name from all_users (for Prepared By)
$created_by = $po_details['created_by'] ?? '';
$user_fullname = '';
if ($created_by) {
    $safe_created_by = $conn->real_escape_string($created_by);
    $user_query = $conn->query("SELECT full_name FROM all_users WHERE username = '$safe_created_by' LIMIT 1");
    if ($user_query && $user_query->num_rows > 0) {
        $user_data = $user_query->fetch_assoc();
        $user_fullname = strtoupper($user_data['full_name'] ?? '');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Order - <?= htmlspecialchars($po_number) ?></title>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/po_print.css?v=<?= time(); ?>">

  
</head>
<body>

<button class="print-button no-print" onclick="window.print()">🖨️ Print</button>

<div class="print-container">
    
    <?php if ($is_reprint): ?>
    <div class="reprint-badge">RE-PRINTED</div>
    <?php endif; ?>
    
    <!-- HEADER -->
    <div class="po-header">
        <div class="logo-section">
            <img src="../images/company_logo.png" alt="Company Logo">
        </div>
        <div class="company-section">
            <h1>ONCALL FORWARDING CORPORATION</h1>
            <div class="address">Lopez Compound, F. Jaca St., Inayawan Laray, Talisay City, Cebu</div>
            <div class="contact">Telefax No. (032) 383-7076 &nbsp;|&nbsp; TIN No.: 240-099-925-000 VAT REG.</div>
        </div>
        <div class="po-title-section">
            <h2>PURCHASE ORDER</h2>
            <div class="po-detail">
                <div class="detail-row">
                    <span class="detail-label">PO No.:</span>
                    <span class="detail-value"><?= htmlspecialchars($po_number) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date:</span>
                    <span class="detail-value"><?= date('M d, Y', strtotime($po_details['po_date'])) ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Delivery Date:</span>
                    <span class="detail-value"><?= $po_details['expected_delivery'] ? date('M d, Y', strtotime($po_details['expected_delivery'])) : 'N/A' ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">PR No.:</span>
                    <span class="detail-value">&nbsp;</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Terms:</span>
                    <span class="detail-value"><?= htmlspecialchars($po_details['payment_terms']) ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- INFO ROW -->
    <div class="info-row">
        <div class="info-left">
            <p>
                <strong>Supplier:</strong> <?= htmlspecialchars($po_details['supplier_name']) ?><br>
                <strong>Attention:</strong> &nbsp;<br>
                <strong>Fax No.:</strong> &nbsp;<br>
                <strong>Address:</strong><?= htmlspecialchars($supplier_address) ?>
            </p>
        </div>
        <div class="info-left" style="text-align: right; flex: 0 0 auto;">
            <p style="font-size: 10px;">
                <span class="status-badge-print <?= strtolower($po_details['status'] ?? 'created') ?>">
                    <?= htmlspecialchars($po_details['status'] ?? 'Created') ?>
                </span>
                <span class="vat-badge-print <?= $with_vat ? 'inclusive' : 'exclusive' ?>">
                    <?= $with_vat ? 'VAT Inclusive' : 'VAT Exclusive' ?>
                </span>
                <?php if ($is_reprint): ?>
                <span style="background: #fee2e2; color: #991b1b; padding: 2px 10px; border-radius: 10px; font-size: 10px; font-weight: 600; margin-left: 5px;">REPRINT</span>
                <?php endif; ?>
            </p>
        </div>
    </div>
    
    <!-- ITEMS TABLE -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 100px;">Item Code</th>
                <th>Item Description</th>
                <th style="width: 50px;">Unit</th>
                <th style="width: 50px; text-align: center;">Qty</th>
                <th style="width: 80px; text-align: right;">Unit Cost</th>
                <th style="width: 80px; text-align: right;">Discount</th>
                <th style="width: 90px; text-align: right;">Total Cost</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            foreach ($po_items as $item): 
                $unit_cost = floatval($item['unit_cost']);
                $qty_ordered = intval($item['qty_ordered']);
                $item_discount = floatval($item['item_discount_amount'] ?? 0);
                $item_subtotal = $unit_cost * $qty_ordered;
                $discounted_total = $item_subtotal - $item_discount;
                
                // Calculate discount percentage
                $discount_percent_display = 0;
                if ($item_subtotal > 0 && $item_discount > 0) {
                    $discount_percent_display = ($item_discount / $item_subtotal) * 100;
                }
            ?>
            <tr>
                <td><?= htmlspecialchars($item['item_code']) ?></td>
                <td><?= htmlspecialchars($item['item']) ?></td>
                <td class="text-center"><?= htmlspecialchars($item['unit']) ?></td>
                <td class="text-center"><?= $qty_ordered ?></td>
                <td class="text-right"><?= number_format($unit_cost, 2) ?></td>
                <td class="text-right" style="<?= $item_discount > 0 ? 'color:#dc2626;font-weight:600;' : '' ?>">
                    <?php 
                    if ($item_discount > 0) {
                        echo number_format($discount_percent_display, 2) . '% (₱' . number_format($item_discount, 2) . ')';
                    } else {
                        echo '₱0.00';
                    }
                    ?>
                </td>
                <td class="text-right"><?= number_format($discounted_total, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- TOTALS SECTION -->
    <div class="totals-box-print">
        <div class="totals-row-print">
            <span class="label">Item Subtotal:</span>
            <span class="value">₱ <?= number_format($item_subtotal_before_discounts, 2) ?></span>
        </div>
        
        <?php if ($total_item_discount > 0): ?>
        <div class="totals-row-print discount-row">
            <span class="label">Item Discounts:</span>
            <span class="value">- ₱ <?= number_format($total_item_discount, 2) ?></span>
        </div>
        <?php endif; ?>
        
        <div class="totals-row-print" style="border-top:1px dashed #ccc;padding-top:4px;margin-top:4px;">
            <span class="label">Subtotal (excl. tax):</span>
            <span class="value">₱ <?= number_format($display_subtotal, 2) ?></span>
        </div>
        
        <?php if ($discount_percent > 0 && $discount_amount > 0): ?>
        <div class="totals-row-print discount-row">
            <span class="label">PO Discount <?= $discount_percent > 0 ? '(' . number_format($discount_percent, 2) . '%)' : '' ?>:</span>
            <span class="value">- ₱ <?= number_format($discount_amount, 2) ?></span>
        </div>
        <?php endif; ?>
        
        <div class="totals-row-print">
            <span class="label">Total VAT:</span>
            <span class="value">₱ <?= number_format($display_total_vat, 2) ?></span>
        </div>
        
        <div class="totals-row-print">
            <span class="label">Shipping / Freight:</span>
            <span class="value">₱ <?= number_format($freight, 2) ?></span>
        </div>
        
        <div class="totals-row-print total-final">
            <span class="label">TOTAL PO AMOUNT:</span>
            <span class="value">₱ <?= number_format($grand_total, 2) ?></span>
        </div>
    </div>
    
    <!-- WITHHOLDING TAX SECTION -->
    <?php if ($withholding_tax_percent > 0): ?>
    <div class="tax-section-print">
        <div class="tax-row-print">
            <span class="tax-label">Subtotal (excl. tax):</span>
            <span class="tax-value">₱ <?= number_format($wht_base, 2) ?></span>
        </div>
        <div class="tax-row-print">
            <span class="tax-label">Withholding Tax (<?= $withholding_tax_percent ?>%):</span>
            <span class="tax-value">₱ <?= number_format($withholding_tax_amount, 2) ?></span>
        </div>
        <div class="tax-row-print net-amount-row">
            <span class="tax-label">Net Amount Due:</span>
            <span class="tax-value">₱ <?= number_format($display_net_amount_due, 2) ?></span>
        </div>
    </div>
    <?php else: ?>
    <div class="tax-section-print" style="background: #f0fdf4; border-color: #86efac;">
        <div class="tax-row-print net-amount-row" style="border-top: none; padding-top: 0; margin-top: 0;">
            <span class="tax-label">GRAND TOTAL:</span>
            <span class="tax-value">₱ <?= number_format($grand_total, 2) ?></span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- SIGNATURES -->
    <div class="signatures">
        <div class="signature-block">
            <div class="label">Requested by:</div>
            <div class="name"><?= !empty($requested_by) ? htmlspecialchars(strtoupper($requested_by)) : '_________________________' ?></div>
            <div class="line"></div>
            <div class="date-line">
                <span class="date-label">Date:</span>
                <span class="underline"><?= date('M d, Y') ?></span>
            </div>
        </div>
        
        <div class="signature-block">
            <div class="label">Prepared by:</div>
            <div class="name"><?= htmlspecialchars($user_fullname) ?></div>
            <div class="line"></div>
            <div class="date-line">
                <span class="date-label">Date:</span>
                <span class="underline"><?= date('M d, Y', strtotime($po_details['created_at'])) ?></span>
            </div>
        </div>
        
        <div class="signature-block">
            <div class="label">Approved by:</div>
            <div class="name">GILBERT CHAN</div>
            <div class="line"></div>
            <div class="date-line">
                <span class="date-label">Date:</span>
                <span class="underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
            </div>
        </div>
    </div>
    
    <!-- FOOTER -->
    <div class="footer-info">
        <?php if ($is_reprint): ?>
        <span style="color: #dc2626; font-weight: 600;">🔁 REPRINTED on <?= date('M d, Y h:i A') ?></span>
        <?php else: ?>
        <span style="color: #059669;">📄 ORIGINAL PRINT on <?= date('M d, Y h:i A') ?></span>
        <?php endif; ?>
    </div>

</div>

</body>
</html>