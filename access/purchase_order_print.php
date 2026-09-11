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

// Get requested_by from URL parameter (passed from view page)
$requested_by = isset($_GET['requested_by']) ? $_GET['requested_by'] : '';

// Get all items for this PO
$items_query = $conn->query("SELECT * FROM purchase_order WHERE po_number = '$safe_po_number' ORDER BY id");
$po_items = [];
while ($row = $items_query->fetch_assoc()) {
    $po_items[] = $row;
}

if (empty($po_items)) {
    die("No items found for this Purchase Order.");
}

// Calculate summary from first item (all items in same PO should have same totals)
$first_item = $po_items[0];
$with_vat = $first_item['with_vat'] ?? 1;
$subtotal = $first_item['subtotal'] ?? 0;
$total_vat = $first_item['total_vat'] ?? 0;
$freight = $first_item['freight'] ?? 0;
$withholding_tax_percent = $first_item['withholding_tax_percent'] ?? 0;
$withholding_tax_amount = $first_item['withholding_tax_amount'] ?? 0;
$net_amount_due = $first_item['net_amount_due'] ?? 0;

// Discount fields
$discount_percent = $first_item['discount_percent'] ?? 0;
$discount_amount = $first_item['discount_amount'] ?? 0;
$item_discount_amount = $first_item['item_discount_amount'] ?? 0;

// --- RECALCULATE TOTALS FROM ITEMS ---
$item_subtotal_before_discounts = 0;
$total_item_discount = 0;
$recalculated_subtotal = 0;
$recalculated_total_vat = 0;

foreach ($po_items as $item) {
    $unit_cost = floatval($item['unit_cost']);
    $qty_ordered = intval($item['qty_ordered']);
    $item_discount = floatval($item['item_discount_amount'] ?? 0);
    
    $item_subtotal = $unit_cost * $qty_ordered;
    $item_subtotal_before_discounts += $item_subtotal;
    $total_item_discount += $item_discount;
    $discounted_total = $item_subtotal - $item_discount;
    
    if ($with_vat) {
        $without_vat = $discounted_total / 1.12;
        $vat_amount = $discounted_total - $without_vat;
        $recalculated_subtotal += $without_vat;
        $recalculated_total_vat += $vat_amount;
    } else {
        $recalculated_subtotal += $discounted_total;
    }
}

// Use the recalculated values
$display_subtotal = $recalculated_subtotal;
$display_total_vat = $recalculated_total_vat;

// Calculate grand total: Subtotal (after item discounts) - PO Discount + VAT + Freight
$grand_total = $display_subtotal - $discount_amount + $display_total_vat + $freight;

// If withholding tax is applied, calculate net amount due
$display_net_amount_due = $net_amount_due > 0 ? $net_amount_due : $grand_total;

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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            background: #fff;
            padding: 20px;
        }
        
        .no-print {
            display: block;
        }
        
        .print-button {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            z-index: 1000;
            font-size: 14px;
        }
        
        .print-button:hover {
            background: #1d4ed8;
        }
        
        .print-container {
            max-width: 8.5in;
            margin: 0 auto;
            padding: 20px 30px;
            background: #fff;
            position: relative;
            border: 1px solid #ddd;
        }
        
        /* Reprint Badge */
        .reprint-badge {
            position: absolute;
            top: 50px;
            left: 40px;
            background: #ffffff35;
            color: #696969;
            padding: 8px 20px;
            border-radius: 4px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 2px;
            transform: rotate(-15deg);
            border: 3px solid #000000;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 10;
            opacity: 0.9;
        }
        
        /* HEADER */
        .po-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        
        .logo-section {
            flex: 0 0 80px;
            margin-top: -5%;
        }
        
        .logo-section img {
            max-width: 160px;
            height: auto;
        }
        
        .company-section {
            flex: 1;
            text-align: center;
            padding: 0 15px;
            margin-top: -5%;

        }
        
        .company-section h1 {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }
        
        .company-section .address {
            font-size: 11px;
            color: #333;
            margin-bottom: 2px;
        }
        
        .company-section .contact {
            font-size: 11px;
            color: #333;
        }
        
        .po-title-section {
            flex: 0 0 180px;
            text-align: left;
            border-left: 2px solid #000;
            padding-left: 15px;
        }
        
        .po-title-section h2 {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .po-title-section .po-detail {
            font-size: 11px;
            line-height: 1.2;
        }
        
        .po-title-section .po-detail .detail-row {
            display: flex;
            align-items: baseline;
        }
        
        .po-title-section .po-detail .detail-label {
            display: inline-block;
            width: 80px;
            font-weight: 600;
        }
        
        .po-title-section .po-detail .detail-value {
            display: inline-block;
        }
        
        /* INFO ROW */
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ccc;
        }
        
        .info-left {
            flex: 1;
        }
        
        .info-left p {
            font-size: 11px;
            line-height: 1.8;
        }
        
        .info-left strong {
            display: inline-block;
            width: 80px;
        }
        
        .info-left .supplier-address {
            font-weight: normal;
            display: block;
            margin-left: 80px;
            font-size: 11px;
        }
        
        /* TABLE */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin: 10px 0;
        }
        
        .items-table th {
            background: #f0f0f0;
            border: 1px solid #000;
            padding: 6px 8px;
            text-align: center;
            font-weight: 700;
        }
        
        .items-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            text-align: left;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .items-table .text-center {
            text-align: center;
        }
        
        /* TOTALS BOX */
        .totals-box-print {
            margin-top: 10px;
            border-top: 2px solid #000;
            padding-top: 8px;
            text-align: right;
        }
        
        .totals-row-print {
            display: flex;
            justify-content: flex-end;
            padding: 3px 0;
            font-size: 11px;
        }
        
        .totals-row-print .label {
            width: 200px;
            text-align: right;
            padding-right: 20px;
            font-weight: 500;
        }
        
        .totals-row-print .value {
            width: 120px;
            text-align: right;
            font-weight: 500;
        }
        
        .totals-row-print.discount-row .label {
            color: #dc2626;
            font-weight: 600;
        }
        
        .totals-row-print.discount-row .value {
            color: #dc2626;
            font-weight: 600;
        }
        
        .totals-row-print.total-final {
            font-size: 14px;
            font-weight: 700;
            border-top: 2px solid #000;
            padding-top: 8px;
            margin-top: 4px;
        }
        
        .totals-row-print.total-final .label {
            font-size: 14px;
        }
        
        .totals-row-print.total-final .value {
            font-size: 14px;
        }
        
        .tax-section-print {
            margin-top: 8px;
            padding: 8px 12px;
            background: #fefce8;
            border: 1px solid #fde68a;
            border-radius: 4px;
            text-align: right;
        }
        
        .tax-section-print .tax-row-print {
            display: flex;
            justify-content: flex-end;
            padding: 2px 0;
            font-size: 11px;
        }
        
        .tax-section-print .tax-row-print .tax-label {
            width: 200px;
            text-align: right;
            padding-right: 20px;
        }
        
        .tax-section-print .tax-row-print .tax-value {
            width: 120px;
            text-align: right;
            font-weight: 500;
        }
        
        .tax-section-print .tax-row-print.net-amount-row {
            font-weight: 700;
            font-size: 13px;
            border-top: 1px solid #fde68a;
            padding-top: 4px;
            margin-top: 4px;
        }
        
        /* GRAND TOTAL - Legacy support */
        .grand-total-row {
            margin-top: 5px;
            text-align: right;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 0;
            border-top: 2px solid #000;
        }
        
        .grand-total-row span {
            display: inline-block;
            width: 120px;
        }
        
        /* SIGNATURES */
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            padding-top: 20px;
        }
        
        .signature-block {
            flex: 1;
            text-align: center;
            max-width: 350px;
        }
        
        .signature-block .label {
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 5px;
            text-align: center;
        }
        
        .signature-block .name {
            font-size: 12px;
            font-weight: 700;
            margin-top: 15px;
            text-transform: uppercase;
            text-align: center;
        }
        
        .signature-block .line {
            border-bottom: 1px solid #000;
            width: 80%;
            margin: 5px auto 0 auto;
        }
        
        .signature-block .date-line {
            margin-top: 10px;
            font-size: 11px;
            text-align: center;
        }
        
        .signature-block .date-line span {
            display: inline-block;
        }
        
        .signature-block .date-line .date-label {
            width: 40px;
        }
        
        .signature-block .date-line .underline {
            border-bottom: 1px solid #000;
            display: inline-block;
            width: 150px;
            text-align: center;
        }
        
        /* Footer */
        .footer-info {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            font-size: 10px;
            color: #666;
            text-align: center;
        }
        
        .status-badge-print {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 5px;
        }
        .status-badge-print.created { background: #fef3c7; color: #92400e; }
        .status-badge-print.approved { background: #d1fae5; color: #065f46; }
        .status-badge-print.received { background: #dbeafe; color: #1e40af; }
        .status-badge-print.completed { background: #d1fae5; color: #065f46; }
        .status-badge-print.cancelled { background: #fee2e2; color: #991b1b; }
        
        .vat-badge-print {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 600;
            margin-left: 5px;
        }
        .vat-badge-print.inclusive { background: #dbeafe; color: #1e40af; }
        .vat-badge-print.exclusive { background: #fef3c7; color: #92400e; }
        
        /* PRINT STYLES */
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                padding: 0;
                background: #fff;
            }
            
            .print-container {
                border: none;
                padding: 15px 25px;
                max-width: 100%;
            }
            
            .reprint-badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .items-table th {
                background: #f0f0f0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .status-badge-print {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .vat-badge-print {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .tax-section-print {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
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
            <span class="tax-label">Total PO Amount:</span>
            <span class="tax-value">₱ <?= number_format($grand_total, 2) ?></span>
        </div>
        <div class="tax-row-print">
            <span class="tax-label">Withholding Tax (<?= $withholding_tax_percent ?>%):</span>
            <span class="tax-value">₱ <?= number_format($withholding_tax_amount, 2) ?></span>
        </div>
        <div class="tax-row-print net-amount-row">
            <span class="tax-label">GRAND TOTAL:</span>
            <span class="tax-value">₱ <?= number_format($grand_total - $withholding_tax_amount, 2) ?></span>
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