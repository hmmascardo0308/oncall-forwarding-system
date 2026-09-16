<?php
// purchase_order_view.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Check if user has access to purchase order page (admin or purchase_order_maker)
$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Purchase Order View page."
    ];
    header("Location: home.php");
    exit;
}

$role_display_name = getRoleDisplayName($user_roles);
$current_page = basename($_SERVER['PHP_SELF']);

// Get all distinct PO numbers (still needed to determine default selected PO)
$po_numbers_query = $conn->query("SELECT DISTINCT po_number FROM purchase_order ORDER BY po_number DESC");
$po_numbers = [];
while ($row = $po_numbers_query->fetch_assoc()) {
    $po_numbers[] = $row['po_number'];
}

// Get selected PO number from URL (or default to latest)
$selected_po = isset($_GET['po_number']) ? $_GET['po_number'] : (count($po_numbers) > 0 ? $po_numbers[0] : '');

// Fetch PO details for the selected PO number
$po_details = null;
$po_items = [];
$po_summary = null;
$selected_requested_by = '';

if ($selected_po) {
    // Get PO header info (using first row for summary)
    $header_query = $conn->query("SELECT * FROM purchase_order WHERE po_number = '$selected_po' LIMIT 1");
    $po_details = $header_query->fetch_assoc();
    
    // Get all items for this PO
    $items_query = $conn->query("SELECT * FROM purchase_order WHERE po_number = '$selected_po' ORDER BY id");
    while ($row = $items_query->fetch_assoc()) {
        $po_items[] = $row;
    }
    
    // Calculate summary from first row (discount/wht flags etc. are stored on every row)
    if (count($po_items) > 0) {
        $po_summary = [
            'subtotal' => $po_items[0]['subtotal'] ?? 0,
            'total_vat' => $po_items[0]['total_vat'] ?? 0,
            'freight' => $po_items[0]['freight'] ?? 0,
            'total_amount' => $po_items[0]['total_amount'] ?? 0,
            'withholding_tax_amount' => $po_items[0]['withholding_tax_amount'] ?? 0,
            'net_amount_due' => $po_items[0]['net_amount_due'] ?? 0,
            'with_vat' => $po_items[0]['with_vat'] ?? 1,
            'withholding_tax_percent' => $po_items[0]['withholding_tax_percent'] ?? 0,
            // Discount fields
            'discount_percent' => $po_items[0]['discount_percent'] ?? 0,
            'discount_amount' => $po_items[0]['discount_amount'] ?? 0,
            'item_discount_amount' => $po_items[0]['item_discount_amount'] ?? 0
        ];
    }
    
    // Get the requested_by value directly from the purchase_order table
    $selected_requested_by = $po_details['requested_by'] ?? '';
}

// Handle cancel / restore PO action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_po') {
    header('Content-Type: application/json');

    $po_num       = $_POST['po_number'] ?? '';
    $cancelled_by = $_POST['cancelled_by'] ?? '';

    if (!$po_num) {
        echo json_encode(['success' => false, 'message' => 'PO number is required.']);
        exit;
    }

    // Get current status
    $stmt = $conn->prepare("SELECT status FROM purchase_order WHERE po_number = ? LIMIT 1");
    $stmt->bind_param("s", $po_num);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Purchase order not found.']);
        exit;
    }

    $current_status = strtolower($row['status'] ?? '');

    if ($current_status === 'cancelled') {
        // Restore to Created
        $stmt = $conn->prepare("UPDATE purchase_order SET status = 'Created', cancelled_by = NULL, cancelled_at = NULL WHERE po_number = ?");
        $stmt->bind_param("s", $po_num);
        $new_status       = 'Created';
        $message          = 'Purchase order has been restored to Created.';
        $cancelled_by_out = null;
        $cancelled_at_out = null;
    } else {
        // Cancel the PO — use PHP-generated Asia/Manila timestamp
        $cancelled_at = date('Y-m-d H:i:s'); // Asia/Manila
        $stmt = $conn->prepare("UPDATE purchase_order SET status = 'Cancelled', cancelled_by = ?, cancelled_at = ? WHERE po_number = ?");
        $stmt->bind_param("sss", $cancelled_by, $cancelled_at, $po_num);
        $new_status       = 'Cancelled';
        $message          = 'Purchase order has been cancelled.';
        $cancelled_by_out = $cancelled_by;
        $cancelled_at_out = date('F d, Y h:i A', strtotime($cancelled_at));
    }

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode([
            'success'      => true,
            'message'      => $message,
            'new_status'   => $new_status,
            'cancelled_by' => $cancelled_by_out,
            'cancelled_at' => $cancelled_at_out
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// Determine if the selected PO has already been printed
$is_printed = false;
if ($po_details && isset($po_details['print_status']) && strtolower($po_details['print_status']) === 'printed') {
    $is_printed = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order View | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <!-- <link rel="stylesheet" href="css/purchase_order.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/po_view.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">

  
</head>
<body>

<!-- Include Sidebar -->
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <a href="purchase_order.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;"> Purchase Order</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Purchase Orders View</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <div class="page-header-actions">
                <h1>
                    <span class="icon-wrap"><i data-lucide="eye" style="width:18px;height:18px;"></i></span>
                    Purchase Order View
                </h1>
                
                <a href="purchase_order.php" class="back-btn">
                    <i data-lucide="plus" style="width:16px;height:16px;"></i>
                    New PO
                </a>

               
                
                <a href="#" 
                   class="print-btn <?= $is_printed ? 'is-reprint' : ''; ?>" 
                   id="printPOBtn" 
                   style="text-decoration: none;" 
                   data-po-number="<?= urlencode($selected_po) ?>" 
                   data-requested-by="<?= urlencode($selected_requested_by) ?>"
                   data-is-printed="<?= $is_printed ? '1' : '0'; ?>">
                    <i data-lucide="<?= $is_printed ? 'rotate-ccw' : 'printer'; ?>" style="width:16px;height:16px;"></i>
                    <?= $is_printed ? 'Re-print' : 'Print'; ?>
                </a>

                <a href="purchase_order_list_all.php" class="view-orders-btn">
                    <i data-lucide="list" style="width:16px;height:16px;"></i>
                    View Orders
                </a>

                <a href="purchase_orders_list.php" class="view-orders-btn">
                    <i data-lucide="list" style="width:16px;height:16px;"></i>
                    Receive Orders
                </a>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="view-only-badge">
                    <i data-lucide="lock" style="width:14px;height:14px;"></i>
                    View Only
                </span>
            </div>
        </div>

        <?php if ($po_details && !empty($po_items)): ?>
            <?php $is_cancelled = strtolower($po_details['status'] ?? '') === 'cancelled'; ?>

            <!-- PO Info -->
            <div class="form-card">
                <div class="form-card-title">
                    <i data-lucide="truck" style="width:16px;height:16px;"></i> 
                    Supplier & Order Details
                    <span style="margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <?php if ($po_details && isset($po_details['print_status'])): ?>
                <span class="print-status-badge <?= strtolower($po_details['print_status']) === 'printed' ? 'printed' : 'unprinted' ?>">
                    <?= htmlspecialchars($po_details['print_status'] ?? 'Unprinted') ?>
                </span>
                <?php endif; ?>
                        <span class="po-tag"><?php echo htmlspecialchars($po_details['po_number']); ?></span>
                        <!-- <span class="status-badge <?php echo strtolower($po_details['status'] ?? 'created'); ?>" id="po-status-badge">
                            <?php echo htmlspecialchars($po_details['status'] ?? 'Created'); ?>
                        </span> -->
                        <button type="button" 
                                class="cancel-toggle-btn <?php echo $is_cancelled ? 'is-cancelled' : ''; ?>" 
                                id="cancelPOBtn"
                                data-po-number="<?php echo htmlspecialchars($po_details['po_number']); ?>"
                                data-cancelled="<?php echo $is_cancelled ? '1' : '0'; ?>"
                                title="<?php echo $is_cancelled ? 'Restore this purchase order' : 'Cancel this purchase order'; ?>">
                            <i data-lucide="<?php echo $is_cancelled ? 'rotate-ccw' : 'ban'; ?>" style="width:14px;height:14px;"></i>
                            <span><?php echo $is_cancelled ? 'Restore PO' : 'Cancel PO'; ?></span>
                        </button>
                        <?php if ($is_cancelled && !empty($po_details['cancelled_by'])): ?>
                        <span class="cancelled-info" title="Cancelled by <?php echo htmlspecialchars($po_details['cancelled_by']); ?> on <?php echo date('F d, Y h:i A', strtotime($po_details['cancelled_at'])); ?>">
                            <i data-lucide="info" style="width:12px;height:12px;"></i>
                            <?php echo htmlspecialchars($po_details['cancelled_by']); ?>
                        </span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="po-header-info">
                    <div class="info-item">
                        <span class="label">Supplier Code</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['supplier_code']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Supplier Name</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['supplier_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Purpose</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['purchase_type']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">PO Date</span>
                        <span class="value"><?php echo date('F d, Y', strtotime($po_details['po_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Delivery Date</span>
                        <span class="value"><?php echo $po_details['expected_delivery'] ? date('F d, Y', strtotime($po_details['expected_delivery'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-item">
    <span class="label">Payment Terms</span>
    <span class="value">
        <?php 
        $payment_terms = trim($po_details['payment_terms'] ?? '');
        if ($payment_terms !== '' && is_numeric($payment_terms)) {
            echo htmlspecialchars($payment_terms . ' DAYS');
        } else {
            echo htmlspecialchars($payment_terms);
        }
        ?>
    </span>
</div>
                    <div class="info-item">
                        <span class="label">Currency</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['currency']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Requested By</span>
                        <span class="value">
                            <?php echo htmlspecialchars($selected_requested_by ?: 'N/A'); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label">Created By</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['created_by']); ?></span>
                    </div>
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <span class="label">Delivery / Ship-To Address</span>
                        <span class="value"><?php echo nl2br(htmlspecialchars($po_details['delivery_address'])); ?></span>
                    </div>
                </div>
                
                <!-- Truck Information (if applicable) -->
                <?php if ($po_details['truck_code']): ?>
                <div class="truck-section" style="margin-top: 0; padding-top: 16px; border-top: 1px solid #f1f5f9;">
                    <div class="truck-section-title">
                        <i data-lucide="truck" style="width:16px;height:16px;"></i>
                        Truck Information
                    </div>
                    <div class="truck-info-display-view">
                        <strong>Truck Code:</strong> <?php echo htmlspecialchars($po_details['truck_code']); ?><br>
                        <strong>Brand:</strong> <?php echo htmlspecialchars($po_details['brand'] ?? 'N/A'); ?><br>
                        <strong>Model:</strong> <?php echo htmlspecialchars($po_details['model'] ?? 'N/A'); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Items Table -->
            <div class="form-card">
                <div class="form-card-title">
                    <i data-lucide="package" style="width:16px;height:16px;"></i> 
                    Ordered Items
                    <span style="margin-left: auto; font-size: 13px; font-weight: 400;">
                        <span class="vat-status-badge-view <?php echo ($po_summary['with_vat'] ?? 1) ? 'inclusive' : 'exclusive'; ?>">
                            <?php echo ($po_summary['with_vat'] ?? 1) ? 'VAT Inclusive' : 'VAT Exclusive'; ?>
                        </span>
                    </span>
                </div>

                <div class="table-wrapper" style="overflow-x: auto;">
                    <table id="items-table" style="min-width: 1400px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item Code</th>
                                <th>Item / Description - Part Number</th>
                                <th>Unit</th>
                                <th>Qty Ordered</th>
                                <th>Qty Received</th>
                                <th>Unit Cost</th>
                                <th>Discount %</th>
                                <th>Discount Amt</th>
                                <th>Without VAT</th>
                                <th>VAT Amount</th>
                                <th>Total Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            $with_vat = $po_summary['with_vat'] ?? 1;
                            $total_item_discount = 0;

                            foreach ($po_items as $item): 
                                $unit_cost = floatval($item['unit_cost']);
                                $qty_ordered = intval($item['qty_ordered']);
                                $qty_received = intval($item['qty_received'] ?? 0);
                                
                                // Calculate item discount
                                $item_subtotal = $unit_cost * $qty_ordered;
                                $item_discount = floatval($item['item_discount_amount'] ?? 0);
                                $item_discount_percent = 0;
                                
                                // Calculate discount percentage if possible
                                if ($item_subtotal > 0 && $item_discount > 0) {
                                    $item_discount_percent = ($item_discount / $item_subtotal) * 100;
                                }
                                
                                $total_item_discount += $item_discount;
                                $discounted_total = $item_subtotal - $item_discount;
                                
                                // Same logic as creation page: VAT portion of the discounted inclusive amount
                                if ($with_vat) {
                                    $without_vat = $discounted_total / 1.12;
                                    $vat_amount  = $discounted_total - $without_vat;
                                } else {
                                    $without_vat = $discounted_total;
                                    $vat_amount  = 0;
                                }
                            ?>
                            <tr>
                                <td style="color:var(--text-muted);font-weight:600;"><?php echo $counter++; ?></td>
                                <td class="item-code-cell" style="font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">
                                    <?php echo htmlspecialchars($item['item_code']); ?>
                                </td>
                                <td>
                                    <?php 
                                    $displayName = htmlspecialchars($item['item']);
                                    if (!empty($item['part_number'])) {
                                        $displayName .= ' <span style="font-size:11px;color:#666;">(' . htmlspecialchars($item['part_number']) . ')</span>';
                                    }
                                    echo $displayName;
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                <td style="text-align:center;"><?php echo $qty_ordered; ?></td>
                                <td style="text-align:center;"><?php echo $qty_received; ?></td>
                                <td style="text-align:right;"><?php echo number_format($unit_cost, 2); ?></td>
                                <td style="text-align:center;<?php echo $item_discount_percent > 0 ? 'color:#dc2626;font-weight:600;' : 'color:var(--text-muted);'; ?>">
                                    <?php echo $item_discount_percent > 0 ? number_format($item_discount_percent, 2) . '%' : '0%'; ?>
                                </td>
                                <td style="text-align:right;<?php echo $item_discount > 0 ? 'color:#dc2626;font-weight:600;' : 'color:var(--text-muted);'; ?>">
                                    ₱<?php echo number_format($item_discount, 2); ?>
                                </td>
                                <td style="text-align:right;font-weight:500;">₱<?php echo number_format($without_vat, 2); ?></td>
                                <td style="text-align:right;font-weight:500;">₱<?php echo number_format($vat_amount, 2); ?></td>
                                <td style="text-align:right;font-weight:600;">₱<?php echo number_format($discounted_total, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Terms + Totals -->
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div class="form-card" style="flex:1;min-width:260px;">
                    <div class="form-card-title"><i data-lucide="file-text" style="width:16px;height:16px;"></i> Terms & Conditions</div>
                    <div class="terms-grid">
                        <div class="form-group">
                            <label>Delivery Mode</label>
                            <div style="padding: 8px 12px; background: #f8fafc; border-radius: 6px; font-size: 14px;">
                                <?php echo htmlspecialchars($po_details['delivery_mode']); ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Warranty</label>
                            <div style="padding: 8px 12px; background: #f8fafc; border-radius: 6px; font-size: 14px;">
                                <?php echo htmlspecialchars($po_details['warranty'] ?: 'N/A'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:14px;">
                        <label>Remarks / Special Instructions</label>
                        <div style="padding: 8px 12px; background: #f8fafc; border-radius: 6px; font-size: 14px; min-height: 50px;">
                            <?php echo nl2br(htmlspecialchars($po_details['remarks'] ?: 'No remarks')); ?>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="totals-box">
                        <?php 
                        // ─── CORRECTED TOTALS – matches purchase_order.php creation logic exactly ──
                        // Unit costs are VAT-inclusive when with_vat = 1.

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

                        $with_vat                = intval($po_summary['with_vat'] ?? 1);
                        $po_discount_amount      = floatval($po_summary['discount_amount'] ?? 0);
                        $po_discount_percent     = floatval($po_summary['discount_percent'] ?? 0);
                        $freight                 = floatval($po_summary['freight'] ?? 0);
                        $withholding_tax_percent = floatval($po_summary['withholding_tax_percent'] ?? 0);

                        if ($with_vat) {
                            // Subtotal (excl. tax) BEFORE PO discount
                            $subtotal = $discounted_inclusive / 1.12;
                        } else {
                            $subtotal = $discounted_inclusive;
                        }

                        // PO discount is applied to the exclusive subtotal
                        $subtotal_after_po_discount = $subtotal - $po_discount_amount;
                        if ($subtotal_after_po_discount < 0) $subtotal_after_po_discount = 0;

                        // VAT is recomputed on the discounted exclusive subtotal
                        if ($with_vat) {
                            $total_vat = $subtotal_after_po_discount * 0.12;
                        } else {
                            $total_vat = 0;
                        }

                        $grand_total = $subtotal_after_po_discount + $total_vat + $freight;

                        // Withholding base = Subtotal (excl. tax) AFTER PO discount
                        $wht_base = $subtotal_after_po_discount;
                        if ($withholding_tax_percent > 0) {
                            $withholding_tax_amount = $wht_base * ($withholding_tax_percent / 100);
                            $net_amount_due         = $grand_total - $withholding_tax_amount;
                        } else {
                            $withholding_tax_amount = 0;
                            $net_amount_due         = $grand_total;
                        }

                        // Keep summary array in sync (used by the withholding section below)
                        $po_summary['subtotal']               = $subtotal;
                        $po_summary['total_vat']              = $total_vat;
                        $po_summary['total_amount']           = $grand_total;
                        $po_summary['withholding_tax_amount'] = $withholding_tax_amount;
                        $po_summary['net_amount_due']         = $net_amount_due;
                        $po_summary['item_discount_amount']   = $total_item_discount;
                        ?>
                        <div class="totals-row">
                            <span>Item Subtotal</span>
                            <span>₱<?php echo number_format($item_subtotal_before_discounts, 2); ?></span>
                        </div>
                        
                        <?php if ($total_item_discount > 0): ?>
                        <div class="totals-row" style="color:#dc2626;">
                            <span>Item Discounts</span>
                            <span>- ₱<?php echo number_format($total_item_discount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="totals-row" style="border-top:1px dashed #d1d5db;padding-top:6px;">
                            <span>Subtotal (excl. tax)</span>
                            <span>₱<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        
                        <?php if ($po_discount_percent > 0 && $po_discount_amount > 0): ?>
                        <div class="totals-row" style="color:#dc2626;">
                            <span>PO Discount (<?php echo number_format($po_discount_percent, 2); ?>%)</span>
                            <span>- ₱<?php echo number_format($po_discount_amount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="totals-row">
                            <span>Total VAT</span>
                            <span>₱<?php echo number_format($total_vat, 2); ?></span>
                        </div>
                        <div class="totals-row">
                            <span>Shipping / Freight</span>
                            <span>₱<?php echo number_format($freight, 2); ?></span>
                        </div>
                        <div class="totals-row total-final">
                            <span>Total PO Amount</span> 
                            <span>₱<?php echo number_format($grand_total, 2); ?></span>
                        </div>
                    </div>
                    
                    <!-- Withholding Tax Section -->
                    <?php if (isset($po_summary['withholding_tax_percent']) && $po_summary['withholding_tax_percent'] > 0): ?>
                    <div class="tax-section-view">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span style="font-weight:500;color:#854d0e;">
                                <i data-lucide="percent" style="width:14px;height:14px;"></i>
                                Withholding Tax (<?php echo $po_summary['withholding_tax_percent']; ?>%)
                            </span>
                        </div>
                        <div class="tax-details-view">
                            <div class="tax-row-view">
                                <span class="tax-label-view">Subtotal (excl. tax): </span>
                                <span class="tax-value-view">₱<?php echo number_format($wht_base, 2); ?></span>
                            </div>
                            <div class="tax-row-view">
                                <span class="tax-label-view">Withholding Tax (<?php echo $po_summary['withholding_tax_percent']; ?>%):</span>
                                <span class="tax-value-view">₱<?php echo number_format($withholding_tax_amount, 2); ?></span>
                            </div>
                            <div class="tax-row-view net-amount-row-view">
                                <span class="tax-label-view">Net Amount Due:</span>
                                <span class="tax-value-view">₱<?php echo number_format($net_amount_due, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="tax-section-view">
                        <div style="display:flex;align-items:center;gap:8px;padding:4px 0;">
                            <span style="font-weight:500;color:#854d0e;">
                                <i data-lucide="check-circle" style="width:14px;height:14px;"></i>
                                No Withholding Tax Applied
                            </span>
                        </div>
                        <div class="tax-details-view">
                            <div class="tax-row-view net-amount-row-view">
                                <span class="tax-label-view">Net Amount Due:</span>
                                <span class="tax-value-view">₱<?php echo number_format($grand_total, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Footer Info -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 13px; color: var(--text-muted); flex-wrap: wrap; gap: 10px;">
                <span>Created by: <?php echo htmlspecialchars($po_details['created_by']); ?></span>
                <span>Created at: <?php echo date('F d, Y h:i A', strtotime($po_details['created_at'])); ?></span>
                <?php if ($po_details['delivery_status']): ?>
                <span>Delivery Status: <?php echo htmlspecialchars($po_details['delivery_status']); ?></span>
                <?php endif; ?>
                <?php if ($is_cancelled && !empty($po_details['cancelled_by'])): ?>
                <span style="color:#b91c1c;">
                    <i data-lucide="ban" style="width:12px;height:12px;display:inline;"></i>
                    Cancelled by: <?php echo htmlspecialchars($po_details['cancelled_by']); ?> 
                    on <?php echo date('F d, Y h:i A', strtotime($po_details['cancelled_at'])); ?>
                </span>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- No PO Selected -->
            <div class="form-card">
                <div class="no-po-selected">
                    <i data-lucide="file-search"></i>
                    <h3 style="margin: 12px 0 8px; color: var(--text-main);">No Purchase Order Selected</h3>
                    <p style="color: var(--text-muted);">Please select a purchase order from the dropdown above to view its details.</p>
                    <?php if (empty($po_numbers)): ?>
                        <p style="color: var(--text-muted); margin-top: 8px;">No purchase orders have been created yet.</p>
                        <a href="purchase_order.php" class="btn-primary" style="display: inline-flex; margin-top: 16px; text-decoration: none;">
                            <i data-lucide="plus" style="width:15px;height:15px;"></i> Create First PO
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
    lucide.createIcons();
    
    // Handle Cancel / Restore PO toggle
    const cancelBtn = document.getElementById('cancelPOBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            const poNumber    = this.getAttribute('data-po-number');
            const isCancelled = this.getAttribute('data-cancelled') === '1';

            const confirmMsg = isCancelled
                ? 'Are you sure you want to restore this purchase order to "Created" status?'
                : 'Are you sure you want to CANCEL this purchase order? This action will mark it as cancelled.';

            if (!confirm(confirmMsg)) return;

            // Determine who is cancelling (logged-in user's full name)
            const cancelledBy = <?php echo json_encode($username); ?>;

            // Loading state
            const originalHTML = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<i data-lucide="loader" style="width:14px;height:14px;animation:spin 1s linear infinite;"></i><span>Processing...</span>';
            lucide.createIcons();

            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=cancel_po'
                    + '&po_number=' + encodeURIComponent(poNumber)
                    + '&cancelled_by=' + encodeURIComponent(cancelledBy)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload to reflect new status, cancelled_by, and cancelled_at
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                    this.disabled = false;
                    this.innerHTML = originalHTML;
                    lucide.createIcons();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while processing your request.');
                this.disabled = false;
                this.innerHTML = originalHTML;
                lucide.createIcons();
            });
        });
    }
    
    // Handle print button click
    const printBtn = document.getElementById('printPOBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const poNumber    = this.getAttribute('data-po-number');
            const requestedBy = this.getAttribute('data-requested-by') || '';
            const isAlreadyPrinted = this.getAttribute('data-is-printed') === '1';
            
            if (!poNumber) {
                alert('No purchase order selected.');
                return;
            }
            
            // Warn if requested_by is empty (but allow printing)
            if (!requestedBy || requestedBy === '') {
                if (!confirm('No "Requested By" person is set for this purchase order. Continue printing anyway?')) {
                    return;
                }
            }
            
            // Show loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite;"></i> Loading...';
            lucide.createIcons();
            
            // Update the print status via AJAX
            fetch('update_print_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'po_number=' + encodeURIComponent(poNumber)
            })
            .then(response => response.json())
            .then(updateData => {
                // Restore button text
                this.innerHTML = originalText;
                lucide.createIcons();
                
                // Open print page with reprint flag only (requested_by now comes from DB)
                let printUrl = 'purchase_order_print.php?po_number=' + encodeURIComponent(poNumber);
                if (isAlreadyPrinted) {
                    printUrl += '&is_reprint=1';
                }
                window.open(printUrl, '_blank');
                
                if (updateData.success) {
                    // Refresh the page to update print status badge & button label
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                } else {
                    console.warn('Print status update failed: ' + (updateData.message || 'Unknown error'));
                    // Still reload so badge reflects current state after re-check
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Restore button text
                this.innerHTML = originalText;
                lucide.createIcons();
                // Still open the print page even if status update fails
                let printUrl = 'purchase_order_print.php?po_number=' + encodeURIComponent(poNumber);
                if (isAlreadyPrinted) {
                    printUrl += '&is_reprint=1';
                }
                window.open(printUrl, '_blank');
            });
        });
    }
</script>

</body>
</html>