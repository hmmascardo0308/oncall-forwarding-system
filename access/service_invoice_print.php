<?php
// service_invoice_print.php
session_start();

require_once __DIR__ . '/../config/config.php';


if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access.");
}

// Support both id and invoice_no parameters
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$invoice_no = isset($_GET['invoice_no']) ? mysqli_real_escape_string($conn, $_GET['invoice_no']) : '';

if ($invoice_id <= 0 && empty($invoice_no)) {
    die("Service Invoice ID or Invoice Number is required.");
}

// Build query based on what we have
if (!empty($invoice_no)) {
    $query = "SELECT si.*, cm.tin, cm.contact_person 
              FROM `service_invoice` si
              LEFT JOIN `customer_masterlist` cm ON si.customer_code = cm.customer_code
              WHERE si.invoice_no = '{$invoice_no}' 
              ORDER BY si.id ASC";
} else {
    $query = "SELECT si.*, cm.tin, cm.contact_person 
              FROM `service_invoice` si
              LEFT JOIN `customer_masterlist` cm ON si.customer_code = cm.customer_code
              WHERE si.id = {$invoice_id} 
              ORDER BY si.id ASC";
}

$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Service Invoice not found.");
}

// Fetch all rows for this invoice
$invoices = [];
while ($row = $result->fetch_assoc()) {
    $invoices[] = $row;
}

// Use the first invoice for header info
$invoice = $invoices[0];

// Calculate totals across all items
$subtotal = 0;
$discount_amount = 0;
$vat_amount = 0;
$total_due = 0;

foreach ($invoices as $inv) {
    $subtotal += floatval($inv['amount']);
    $discount_amount += floatval($inv['discount_amount']);
    $vat_amount += floatval($inv['vat_amount']);
    $total_due += floatval($inv['total_amount']);
}

$net_of_discount = $subtotal - $discount_amount;

// Get all SO numbers
$so_numbers = array_unique(array_column($invoices, 'sales_order_no'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Service Invoice - <?= htmlspecialchars($invoice['invoice_no']) ?></title>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <style>
        /* Global Base */
        * {
            box-sizing: border-box;
        }

        body { 
            font-family: Arial, Helvetica, sans-serif; 
            background: #dfdfdf; 
            margin: 0;
            padding: 20px;
        }
        
        .print-container { 
            width: 8.5in; 
            min-height: 5.5in;
            margin: auto; 
            background: #fff; 
            padding: 12px; 
            border: 1px solid #ccc; 
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .header { 
            margin-bottom: 5px; 
        }
        
        .header-top { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
        }
        
        .header-top h2 { 
            margin: 0; 
            font-size: 16px; 
            font-weight: 900; 
        }
        
        .header-top .title { 
            font-size: 18px; 
        }

        .header-top .invoice-no {
            font-size: 12px;
            font-weight: normal;
            text-align: right;
            margin-top: 2px;
            color: #333;
        }
        
        .header-info { 
            font-size: 11px; 
            line-height: 1.2; 
            margin-top: 2px; 
        }

        .date-section { 
            text-align: right; 
            margin-bottom: 5px; 
            font-size: 11px; 
        }

        /* Core Grid Layout Structure */
        .layout-grid {
            display: grid;
            grid-template-columns: 73% 27%;
            gap: 0px;
            width: 100%;
            margin-top: 3px;
        }

        .left-section {
            display: flex;
            flex-direction: column;
            /* justify-content: space-between; */
        }

        .right-section {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            margin-left: -1px; /* Overlap borders cleanly */
        }

        /* Tables Formatting */
        table { 
            border-collapse: collapse; 
            width: 100%; 
            table-layout: fixed;
        }
        
        th, td { 
            border: 1px solid #000; 
            padding: 3px 5px; 
            font-size: 10px; 
            text-align: left; 
            vertical-align: top; 
            font-weight: bold;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        th { 
            font-weight: bold; 
            background-color: #fff; 
        }
        
        td { 
            color: #000; 
            font-weight: bold; 
        }

        .payment-table td { 
            height: 22px; 
        }

        .printers-table, .printers-table-lower { 
            width: 100%; 
            margin-top: 10px; 
        }
        
        .printers-table th, .printers-table td, 
        .printers-table-lower th, .printers-table-lower td { 
            font-size: 6.5px; 
            text-align: center; 
            padding: 2px; 
        }

        .calc-table td { 
            font-size: 8.5px; 
            padding: 3px; 
        }
        
        .calc-table .val-cell { 
            text-align: right; 
            font-weight: bold; 
            color: #000;
        }
        
        .calc-table .vat-box { 
            vertical-align: top; 
            line-height: 1.3;
        }

        .payment-method { 
            margin-top: 4px; 
            font-size: 10px; 
            text-align: center; 
            font-weight: bold; 
        }
        
        .signature-area { 
            margin-top: 15px; 
            text-align: center; 
        }
        
        .signature-line { 
            border-top: 1px solid #000; 
            display: inline-block; 
            width: 90%; 
            margin-top: 2px; 
            font-size: 9px; 
            font-weight: bold; 
        }

        .empty-row td {
            color: transparent !important;
            height: 22px;
        }

        /* Print Media Overrides */
        @media print {
            @page {
                size: 8.5in 5.5in portrait; /* Standard half-sheet size */
                margin: 0; /* Clear browser default header/footer margins */
            }

            body { 
                background: none; 
                padding: 0; 
                margin: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .print-container { 
                box-shadow: none; 
                border: none; 
                width: 100%;
                height: 100%;
                padding: 0.25in;
            }

            .no-print { 
                display: none !important; 
            }
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
        }

        .print-button:hover {
            background: #1d4ed8;
        }
    </style>
</head>
<body>

<button class="print-button no-print" onclick="window.print()">🖨️ Print</button>

<div class="print-container">
    <div>
        <div class="header">
            <div class="header-top">
                <h2>ONCALL FORWARDING CORPORATION</h2>
                <div style="text-align: right;">
                    <h2 class="title">SERVICE INVOICE</h2>
                    <div class="invoice-no">INVOICE NO: <?= htmlspecialchars($invoice['invoice_no']) ?></div>
                </div>
            </div>
            <div class="header-info">
                Green Field Subd., Inayawan, Cebu City<br>
                Fax/Tel. 420-0946 / 383-7076<br>
                Vat Reg. TIN: 240-099-925-000
            </div>
            <hr>
        </div>

        <div class="date-section">
            DATE: <span style="border-bottom: 1px dotted #000; min-width: 100px; display: inline-block;">
                <?= !empty($invoice['invoice_date']) ? date('F d', strtotime($invoice['invoice_date'])) : '__________' ?>
            </span>, 20<span style="border-bottom: 1px dotted #000; min-width: 30px; display: inline-block;">
                <?= !empty($invoice['invoice_date']) ? date('y', strtotime($invoice['invoice_date'])) : '__' ?>
            </span>
        </div>

        <table class="customer-table">
            <tr>
                <th width="45%">CUSTOMER NAME:</th>
                <th width="20%">TIN:</th>
                <th width="35%">ADDRESS:</th>
            </tr>
            <tr>
                <td><?= htmlspecialchars($invoice['customer_name'] ?? '[ Enter Name ]') ?></td>
                <td><?= htmlspecialchars($invoice['tin'] ?? '[ 000-000-000 ]') ?></td>
                <td><?= htmlspecialchars($invoice['delivery_address'] ?? '[ Enter Address ]') ?></td>
            </tr>
        </table>

        <div class="layout-grid">
            
            <!-- Left Side Layout -->
            <div class="left-section">
                <div>
                    <table class="business-table">
                        <tr>
                            <th width="45%">BUSINESS STYLE:</th>
                            <th width="30%">OSCA/PWD NO.:</th>
                            <th width="25%">SIGNATURE:</th>
                        </tr>
                        <tr>
                            <td><?= htmlspecialchars($invoice['business_style'] ?? '...') ?></td>
                            <td><?= htmlspecialchars($invoice['osca_pwd_no'] ?? '...') ?></td>
                            <td>&nbsp;</td>
                        </tr>
                    </table>

                    <table class="payment-table">
                        <thead>
                            <tr>
                                <th width="60%">IN PAYMENT OF THE FOLLOWING<br>SERVICES/TRANSACTION/DESCRIPTION:</th>
                                <th width="10%">QTY</th>
                                <th width="15%">UNIT PRICE</th>
                                <th width="15%">AMOUNT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_count = count($invoices);
                            for ($i = 0; $i < 4; $i++): 
                                if ($i < $row_count):
                                    $inv = $invoices[$i];
                            ?>
                            <tr>
                                <td><strong>SO: <?= htmlspecialchars($inv['sales_order_no'] ?? '—') ?></strong></td>
                                <td><?= htmlspecialchars($inv['quantity'] ?? 1) ?></td>
                                <td><?= number_format($inv['unit_price'] ?? 0, 2) ?></td>
                                <td><?= number_format(($inv['quantity'] ?? 1) * ($inv['unit_price'] ?? 0), 2) ?></td>
                            </tr>
                            <?php else: ?>
                            <tr class="empty-row">
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                            <?php 
                                endif;
                            endfor; 
                            ?>
                        </tbody>
                    </table>
                </div>

                <div class="footer-tables">
                    <table class="printers-table">
                        <thead>
                            <tr>
                                <th>PRINTER'S NAME</th>
                                <th>TIN</th>
                                <th>ADDRESS</th>
                                <th>PRINTER'S ACCREDITATION NO.</th>
                                <th>DATE ISSUED</th>
                                <th>EXPIRY DATE</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>PL'S PRINTING PRESS</td>
                                <td>221-630-585-0000 NV</td>
                                <td>TAGUNOL, BASAK, CEBU CITY</td>
                                <td>082MP20240000000009</td>
                                <td>01-14-19</td>
                                <td>01-14-24</td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="printers-table-lower">
                        <thead>
                            <tr>
                                <th>PTU RO:</th>
                                <th>BOOKLET NO:</th>
                                <th>SETS:</th>
                                <th>COPIES PER SET:</th>
                                <th>SERIAL NO.:</th>
                                <th>BIR ATP NO:</th>
                                <th>DATE ISSUED:</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>&nbsp;</td>
                                <td>50</td>
                                <td>50</td>
                                <td>2x</td>
                                <td>7001-9500</td>
                                <td>082AU20230000001373</td>
                                <td>&nbsp;</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Side Layout -->
            <div class="right-section">
                <table class="calc-table">
                    <tr><td>TOTAL SALES (VAT INC.)</td><td class="val-cell"><?= number_format($subtotal, 2) ?></td></tr>
                    <tr><td>LESS: <?= number_format($invoice['vat_percent'] ?? 12, 0) ?>% VAT</td><td class="val-cell"><?= number_format($vat_amount, 2) ?></td></tr>
                    <tr><td>AMOUNT: NET OF VAT</td><td class="val-cell"><?= number_format($subtotal - $vat_amount, 2) ?></td></tr>
                    <tr><td>LESS: SC/PWD DISCOUNT</td><td class="val-cell"><?= number_format($discount_amount, 2) ?></td></tr>
                    <tr><td>TOTAL DUE</td><td class="val-cell"><?= number_format($total_due, 2) ?></td></tr>
                    <tr><td>LESS: WITHHOLDING TAX</td><td class="val-cell"><?= number_format($invoice['withholding_tax'] ?? 0, 2) ?></td></tr>
                    <tr><td>TOTAL AMOUNT DUE</td><td class="val-cell"><?= number_format($total_due - ($invoice['withholding_tax'] ?? 0), 2) ?></td></tr>
                    <tr>
                        <td class="vat-box" colspan="2">
                            VATABLE (V) <span style="float:right; color: blue;"><?= number_format($subtotal - $vat_amount, 2) ?></span><br>
                            VAT EXEMPT (E) <span style="float:right; color: blue;">0.00</span><br>
                            ZERO-RATED (Z) <span style="float:right; color: blue;">0.00</span><br>
                            VAT (<?= number_format($invoice['vat_percent'] ?? 12, 0) ?>%) <span style="float:right; color: blue;"><?= number_format($vat_amount, 2) ?></span><br>
                            TOTAL <span style="float:right; color: blue;"><?= number_format($subtotal, 2) ?></span>
                        </td>
                    </tr>
                </table>

                <div>
                    <div class="payment-method">
                        <span>[ <?= strtoupper($invoice['payment_method'] ?? 'CASH') ?> ]</span>
                    </div>

                    <div class="signature-area">
                        <div class="signature-line">
                            CASHIER/AUTHORIZED PERSON
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

</body>
</html>