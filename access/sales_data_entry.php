<?php
session_start();
// require_once __DIR__ . '/../config/config.php'; 

if (!isset($_SESSION['username'])) {
    // header("Location: login.php");
    // exit;
}

$username   = $_SESSION['username'] ?? "Guest";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Receipt - <?php echo $username; ?></title>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
        <link rel="stylesheet" href="css/sales_entry.css?v=<?= time(); ?>">


     <script src="../js/lucide.js"></script>


</head>
<body>

<div class="header-back">
    <a href="home.php" class="back">&nbsp;&nbsp;&nbsp;⬅️Back</a>
</div>

<div class="receipt-box">
    <div class="header">
        <div class="header-top">
            <h2>ONCALL FORWARDING CORPORATION</h2>
            <h2 class="title">SERVICE INVOICE</h2>
        </div>
        <div class="header-info">
            Green Field Subd., Inayawan, Cebu City<br>
            Fax/Tel. 420-0946 / 383-7076<br>
            Vat Reg. TIN: 240-099-925-000
        </div>
        <hr>
    </div>

    <div class="date-section">
        DATE: <span contenteditable="true" style="border-bottom: 1px dotted #000; min-width: 100px; display: inline-block;">January 13</span>, 20<span contenteditable="true" style="border-bottom: 1px dotted #000; min-width: 30px; display: inline-block;">26</span>
    </div>

    <table class="customer-table">
        <tr>
            <th width="45%">CUSTOMER NAME:</th>
            <th width="20%">TIN:</th>
            <th width="35%">ADDRESS:</th>
        </tr>
        <tr>
            <td contenteditable="true" style="padding: 8px;">[ Enter Name ]</td>
            <td contenteditable="true" style="padding: 8px;">[ 000-000-000 ]</td>
            <td contenteditable="true" style="padding: 8px;">[ Enter Address ]</td>
        </tr>
    </table>

    <table class="business-table" style="width:75%; margin-top: .2%;">
        <tr>
            <th width="45%">BUSINESS STYLE:</th>
            <th width="30%">OSCA/PWD NO.:</th>
            <th width="25%">SIGNATURE:</th>
        </tr>
        <tr>
            <td contenteditable="true" style="padding: 8px;">...</td>
            <td contenteditable="true" style="padding: 8px;">...</td>
            <td style="padding: 8px;">&nbsp;</td>
        </tr>
    </table>

    <div class="main-container">
        <div class="left-column" style="margin-top: .2%;"> 
            <table class="payment-table" style="width:100.1%;">
                <thead>
                    <tr>
                        <th width="60%">IN PAYMENT OF THE FOLLOWING<br>SERVICES/TRANSACTION/DESCRIPTION:</th>
                        <th width="10%">QTY</th>
                        <th width="15%">UNIT PRICE</th>
                        <th width="15%">AMOUNT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for($i=0; $i<4; $i++): ?>
                    <tr>
                        <td contenteditable="true" style="padding: 8px;">...</td>
                        <td contenteditable="true" style="padding: 8px;">0</td>
                        <td contenteditable="true" style="padding: 8px;">0.00</td>
                        <td contenteditable="true" style="padding: 8px;">0.00</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="right-column">
            <table class="calc-table" style="margin-top: -3%;">
                <tr><td style="color: black;">TOTAL SALES (VAT INC.)</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr><td style="color: black;">LESS: 12% VAT</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr><td style="color: black;">AMOUNT: NET OF VAT</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr><td style="color: black;">LESS: SC/PWD DISCOUNT</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr><td style="color: black;">TOTAL DUE</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr><td style="color: black;">LESS: WITHHOLDING TAX</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr><td style="color: black;">TOTAL AMOUNT DUE</td><td class="val-cell" contenteditable="true">0.00</td></tr>
                <tr>
                    <td class="vat-box" colspan="2"  style="color: black;">
                        VATABLE (V) <span style="float:right; color: blue;" contenteditable="true">0.00</span><br>
                        VAT EXEMPT (E) <span style="float:right; color: blue;" contenteditable="true">0.00</span><br>
                        ZERO-RATED (Z) <span style="float:right; color: blue;" contenteditable="true">0.00</span><br>
                        VAT (12%) <span style="float:right; color: blue;" contenteditable="true">0.00</span><br>
                        TOTAL <span style="float:right; color: blue;" contenteditable="true">0.00</span>
                    </td>
                </tr>
            </table>

            <div class="payment-method">
                <span contenteditable="true">[ ] CASH</span>
                <span contenteditable="true">[ ] CHECK</span>
                <span contenteditable="true">[ ] CREDIT</span>
            </div>

            <div class="signature-area">
                <div class="signature-line">
                    CASHIER/AUTHORIZED PERSON <br>
                </div>
            </div>
        </div>
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
                    <td style="color: black;">PL'S PRINTING PRESS</td>
                    <td style="color: black;">221-630-585-0000 NV</td>
                    <td style="color: black;">TAGUNOL, BASAK, CEBU CITY</td>
                    <td style="color: black;">082MP20240000000009</td>
                    <td style="color: black;">01-14-19</td>
                    <td style="color: black;">01-14-24</td>
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
                    <td contenteditable="true"  style="color: black;">&nbsp;</td>
                    <td contenteditable="true"  style="color: black;">50</td>
                    <td contenteditable="true"  style="color: black;">50</td>
                    <td contenteditable="true"  style="color: black;">2x</td>
                    <td contenteditable="true"  style="color: black;">7001-9500</td>
                    <td contenteditable="true"  style="color: black;">082AU20230000001373</td>
                    <td contenteditable="true"  style="color: black;">&nbsp;</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

<script>
        lucide.createIcons();
        </script>
</body>
</html>