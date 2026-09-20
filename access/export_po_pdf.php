<?php
// export_po_pdf.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$username  = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role
$is_admin = in_array('admin', $user_roles);
$base_user_type = $is_admin ? 'admin' : 'user';

// Enforce page access
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages);

// ---------------------------------------------------------------
// Default date filter: current week (Monday to Sunday)
// ---------------------------------------------------------------
$today = new DateTime('now', new DateTimeZone('Asia/Manila'));
$dayOfWeek = (int)$today->format('N');
$monday = clone $today;
$monday->modify('-' . ($dayOfWeek - 1) . ' days');
$sunday = clone $monday;
$sunday->modify('+6 days');

$default_date_from = $monday->format('Y-m-d');
$default_date_to   = $sunday->format('Y-m-d');

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$purchase_type_filter = isset($_GET['purchase_type']) ? trim($_GET['purchase_type']) : '';
$supplier_filter = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$delivery_status_filter = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$delivery_payment_filter = isset($_GET['delivery_payment']) ? trim($_GET['delivery_payment']) : '';

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : $default_date_from;
$date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : $default_date_to;

$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'po_date';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'DESC';

// Build query — one row per item
$query = "SELECT 
            po.id,
            po.po_number,
            po.po_date,
            po.supplier_code,
            COALESCE(sl.supplier_name, po.supplier_name) AS supplier_name,
            po.item_code,
            COALESCE(im.item_name, po.item) AS item_name,
            po.purchase_type,
            po.truck_code,
            tm.plate_number,
            po.qty_ordered,
            po.unit,
            po.unit_cost,
            po.net_amount_due,
            po.total_amount_paid,
            po.payment_method,
            po.delivery_status,
            po.delivery_payment,
            po.created_by,
            po.created_at
          FROM purchase_order po
          LEFT JOIN supplier_lists sl 
                 ON sl.supplier_code = po.supplier_code COLLATE utf8mb4_general_ci
          LEFT JOIN item_masterlist im 
                 ON im.item_code = po.item_code COLLATE utf8mb4_general_ci
          LEFT JOIN truck_masterlist tm 
                 ON tm.truck_code = po.truck_code COLLATE utf8mb4_general_ci
          WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (po.po_number LIKE ? OR po.supplier_name LIKE ? OR po.supplier_code LIKE ? OR sl.supplier_name LIKE ? OR po.item LIKE ? OR po.item_code LIKE ? OR im.item_name LIKE ? OR po.created_by LIKE ?)";
    $search_param = "%$search%";
    for ($i = 0; $i < 8; $i++) {
        $params[] = $search_param;
    }
    $types .= "ssssssss";
}

if (!empty($purchase_type_filter)) {
    $query .= " AND po.purchase_type = ?";
    $params[] = $purchase_type_filter;
    $types .= "s";
}

if (!empty($supplier_filter)) {
    $query .= " AND po.supplier_code LIKE ?";
    $params[] = "%$supplier_filter%";
    $types .= "s";
}

if (!empty($delivery_status_filter)) {
    $query .= " AND po.delivery_status = ?";
    $params[] = $delivery_status_filter;
    $types .= "s";
}

if (!empty($delivery_payment_filter)) {
    $query .= " AND po.delivery_payment = ?";
    $params[] = $delivery_payment_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(po.po_date) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(po.po_date) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['po_date', 'po_number', 'supplier_code', 'item_code', 'purchase_type', 'qty_ordered', 'unit_cost', 'net_amount_due', 'total_amount_paid', 'delivery_status', 'delivery_payment', 'payment_method', 'created_by'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'po_date';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$query .= " ORDER BY po.$sort_by $sort_order, po.id ASC";

// Execute query
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
} else {
    $result = $conn->query($query);
}

$purchase_orders = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $purchase_orders[] = $row;
    }
}

// Group items by PO number
$grouped_pos = [];
foreach ($purchase_orders as $row) {
    $po_num = $row['po_number'];

    if (!isset($grouped_pos[$po_num])) {
        $credit = $row['total_amount_paid'] ?? 0;

        $remarks_parts = [];
        if (!empty($row['delivery_status']))        $remarks_parts[] = $row['delivery_status'];
        if (!empty($row['delivery_payment']))       $remarks_parts[] = $row['delivery_payment'];
        if (!empty($row['payment_method']))         $remarks_parts[] = $row['payment_method'];
        $remarks = implode(' | ', $remarks_parts) ?: 'N/A';

        $grouped_pos[$po_num] = [
            'po_number' => $po_num,
            'items'     => [],
            'debit'     => (float)($row['net_amount_due'] ?? 0),
            'credit'    => (float)$credit,
            'remarks'   => $remarks,
        ];
    }

    $grouped_pos[$po_num]['items'][] = $row;
}

// Pre-compute totals
$total_debit  = 0;
$total_credit = 0;
foreach ($grouped_pos as $group) {
    $total_debit  += $group['debit'];
    $total_credit += $group['credit'];
}

// Build filter description for the report
$filter_description = [];
if (!empty($search)) $filter_description[] = "Search: " . $search;
if (!empty($purchase_type_filter)) $filter_description[] = "Purpose: " . $purchase_type_filter;
if (!empty($supplier_filter)) $filter_description[] = "Supplier: " . $supplier_filter;
if (!empty($delivery_status_filter)) $filter_description[] = "Delivery Status: " . $delivery_status_filter;
if (!empty($delivery_payment_filter)) $filter_description[] = "Delivery Payment: " . $delivery_payment_filter;
if (!empty($date_from)) $filter_description[] = "From: " . date('M d, Y', strtotime($date_from));
if (!empty($date_to)) $filter_description[] = "To: " . date('M d, Y', strtotime($date_to));

$filter_text = !empty($filter_description) ? implode(' | ', $filter_description) : 'No filters applied';

// Company info
$company_name = "ONCALL FORWARDING CORPORATION";
$report_title = "Purchase Order List Report";
$generated_at = date('F d, Y h:i A');

// Format currency
function formatCurrency($amount) {
    return number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order List Report - <?php echo $generated_at; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            background: #fff;
            padding: 15px;
        }

        .report-header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1a1a1a;
        }

        .report-header h1 {
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }

        .report-header h2 {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .report-meta {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #555;
            margin-top: 8px;
            flex-wrap: wrap;
            gap: 5px;
        }

        .report-meta span {
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 3px;
        }

        .filter-info {
            font-size: 9px;
            color: #666;
            margin-bottom: 10px;
            padding: 5px 8px;
            background: #f9fafb;
            border-left: 3px solid #dc2626;
            border-radius: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-bottom: 10px;
        }

        thead {
            display: table-header-group;
        }

        thead th {
            background: #1e293b;
            color: #fff;
            padding: 5px 4px;
            text-align: left;
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #334155;
            white-space: nowrap;
        }

        tbody td {
            padding: 4px;
            border: 1px solid #d1d5db;
            vertical-align: top;
            word-wrap: break-word;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        tbody tr:hover {
            background: #f3f4f6;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .amount {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            white-space: nowrap;
        }

        .po-number {
            font-weight: 700;
            color: #1e40af;
        }

        .balance-positive {
            color: #dc2626;
            font-weight: 700;
        }

        .balance-zero {
            color: #16a34a;
            font-weight: 700;
        }

        .balance-negative {
            color: #16a34a;
            font-weight: 700;
        }

        tfoot td {
            background: #1e293b;
            color: #fff;
            padding: 6px 4px;
            font-weight: 700;
            font-size: 10px;
            border: 1px solid #334155;
        }

        tfoot .amount {
            color: #fbbf24;
        }

        .report-footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #d1d5db;
            display: flex;
            justify-content: space-between;
            font-size: 8px;
            color: #888;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #888;
            font-size: 12px;
        }

        .no-data h3 {
            font-size: 14px;
            margin-bottom: 5px;
            color: #555;
        }

        .summary-box {
            display: flex;
            justify-content: flex-end;
            gap: 20px;
            margin-bottom: 15px;
            padding: 10px 15px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }

        .summary-item {
            text-align: right;
        }

        .summary-item .label {
            font-size: 8px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
        }

        .summary-item .value {
            font-size: 13px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }

        .summary-item .value.debit {
            color: #dc2626;
        }

        .summary-item .value.credit {
            color: #16a34a;
        }

        .summary-item .value.balance {
            color: #1e40af;
        }

        @media print {
            body {
                padding: 5px;
                font-size: 9px;
            }

            .no-print {
                display: none !important;
            }

            table {
                font-size: 8px;
            }

            thead th {
                font-size: 7px;
                padding: 4px 3px;
            }

            tbody td {
                padding: 3px;
            }

            @page {
                size: landscape;
                margin: 8mm;
            }
        }

        .print-button {
            position: fixed;
            top: 15px;
            right: 15px;
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
            transition: all 0.2s;
            z-index: 1000;
        }

        .print-button:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4);
        }

        .print-button i {
            margin-right: 6px;
        }

        @media print {
            .print-button {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <button class="print-button no-print" onclick="window.print()">
        🖨️ Print / Save as PDF
    </button>

    <div class="report-header">
        <h1><?php echo $company_name; ?></h1>
        <h2><?php echo $report_title; ?></h2>
        <div class="report-meta">
            <span>Generated: <?php echo $generated_at; ?></span>
            <span>Generated by: <?php echo htmlspecialchars($full_name); ?></span>
            <span>Total POs: <?php echo count($grouped_pos); ?></span>
            <span>Total Items: <?php echo count($purchase_orders); ?></span>
        </div>
    </div>

    <?php if (!empty($filter_description)): ?>
    <div class="filter-info">
        <strong>Filters Applied:</strong> <?php echo htmlspecialchars($filter_text); ?>
    </div>
    <?php endif; ?>

    <?php if (count($grouped_pos) > 0): ?>

        <!-- Summary Box -->
        <div class="summary-box">
            <div class="summary-item">
                <div class="label">Total Debit</div>
                <div class="value debit">₱<?php echo number_format($total_debit, 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Total Credit</div>
                <div class="value credit">₱<?php echo number_format($total_credit, 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Total Balance</div>
                <div class="value balance">₱<?php echo number_format($total_debit - $total_credit, 2); ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 7%;">Date</th>
                    <th style="width: 10%;">PO Number</th>
                    <th style="width: 12%;">Supplier</th>
                    <th style="width: 12%;">Item</th>
                    <th style="width: 9%;">Purpose</th>
                    <th style="width: 8%;">Other Description</th>
                    <th style="width: 5%;">Qty</th>
                    <th style="width: 7%;">Unit Price</th>
                    <th style="width: 8%;">Debit Amount</th>
                    <th style="width: 8%;">Credit Amount</th>
                    <th style="width: 7%;">Balance</th>
                    <th style="width: 7%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped_pos as $group): ?>
                    <?php 
                    $items = $group['items'];
                    $item_count = count($items);
                    $is_first = true;
                    ?>
                    <?php foreach ($items as $po): ?>
                        <tr>
                            <td><?php echo $po['po_date'] ? date('M d, Y', strtotime($po['po_date'])) : '-'; ?></td>
                            <td class="po-number"><?php echo htmlspecialchars($po['po_number']); ?></td>
                            <td><?php echo htmlspecialchars($po['supplier_name'] ?: ($po['supplier_code'] ?: 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars($po['item_name'] ?: ($po['item_code'] ?: 'N/A')); ?></td>
                            <td><?php echo htmlspecialchars($po['purchase_type'] ?: 'N/A'); ?></td>
                            <td>
                                <?php 
                                if (strcasecmp(trim($po['purchase_type'] ?? ''), 'For Truck Repair and Maintenance') === 0) {
                                    echo htmlspecialchars($po['plate_number'] ?: ($po['truck_code'] ?: '-'));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td class="text-center">
                                <?php 
                                $qty = $po['qty_ordered'] ?? '';
                                $unit = $po['unit'] ?? '';
                                echo htmlspecialchars(trim($qty . ' ' . $unit));
                                ?>
                            </td>
                            <td class="amount"><?php echo number_format($po['unit_cost'] ?? 0, 2); ?></td>

                            <?php if ($is_first): ?>
                                <td class="amount" rowspan="<?php echo $item_count; ?>">
                                    <?php echo number_format($group['debit'], 2); ?>
                                </td>
                                <td class="amount" rowspan="<?php echo $item_count; ?>">
                                    <?php echo number_format($group['credit'], 2); ?>
                                </td>
                                <td class="amount <?php 
                                    $balance = $group['debit'] - $group['credit'];
                                    if ($balance > 0) echo 'balance-positive';
                                    elseif ($balance < 0) echo 'balance-negative';
                                    else echo 'balance-zero';
                                ?>" rowspan="<?php echo $item_count; ?>">
                                    <?php echo number_format($balance, 2); ?>
                                </td>
                                <td rowspan="<?php echo $item_count; ?>">
                                    <?php echo htmlspecialchars($group['remarks']); ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php $is_first = false; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="8" style="text-align: right;">GRAND TOTAL:</td>
                    <td class="amount">₱<?php echo number_format($total_debit, 2); ?></td>
                    <td class="amount">₱<?php echo number_format($total_credit, 2); ?></td>
                    <td class="amount">₱<?php echo number_format($total_debit - $total_credit, 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    <?php else: ?>
        <div class="no-data">
            <h3>No Purchase Orders Found</h3>
            <p>Try adjusting your search or filter criteria.</p>
        </div>
    <?php endif; ?>

    <div class="report-footer">
        <span>ONCALL FORWARDING CORPORATION — Purchase Order List Report</span>
        <span>Page 1 of 1 | <?php echo $generated_at; ?></span>
    </div>

    <script>
        // Auto-trigger print dialog when the page loads (optional)
        // Uncomment the line below if you want the print dialog to open automatically
        // window.onload = function() { window.print(); };
    </script>

</body>
</html>