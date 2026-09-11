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
// (Role-specific check is intentional: only admin / PO makers can enter this page,
//  even though the generic 'user' role includes this page in $allowed_pages)
$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Purchase Order View page."
    ];
    header("Location: home.php");
    exit;
}

// $allowed_pages, hasAccess(), and getRoleDisplayName() now come from access_control.php

$role_display_name = getRoleDisplayName($user_roles);
$current_page = basename($_SERVER['PHP_SELF']);

// Get all distinct PO numbers for filter
$po_numbers_query = $conn->query("SELECT DISTINCT po_number FROM purchase_order ORDER BY po_number DESC");
$po_numbers = [];
while ($row = $po_numbers_query->fetch_assoc()) {
    $po_numbers[] = $row['po_number'];
}

// Get selected PO number from URL
$selected_po = isset($_GET['po_number']) ? $_GET['po_number'] : (count($po_numbers) > 0 ? $po_numbers[0] : '');

// Fetch employee list for dropdown
$employee_query = $conn->query("SELECT full_name FROM employee_list ORDER BY full_name ASC");
$employees = [];
while ($row = $employee_query->fetch_assoc()) {
    $employees[] = $row['full_name'];
}

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
    
    // Calculate summary
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
    
    // Get the requested_by value from session or POST
    $selected_requested_by = isset($_GET['requested_by']) ? $_GET['requested_by'] : 
                            (isset($_SESSION['selected_requested_by_' . $selected_po]) ? $_SESSION['selected_requested_by_' . $selected_po] : '');
}

// Handle requested_by selection via AJAX (for immediate save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_requested_by') {
    $po_num = $_POST['po_number'] ?? '';
    $requested_by = $_POST['requested_by'] ?? '';
    if ($po_num && $requested_by) {
        $_SESSION['selected_requested_by_' . $po_num] = $requested_by;
    }
    exit;
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
    <link rel="stylesheet" href="css/purchase_order.css?v=<?= time(); ?>">
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

                <a href="purchase_orders_list.php" class="back-btn">
                    <i data-lucide="list" style="width:16px;height:16px;"></i>
                    Receive Orders
                </a>
                
                <a href="#" class="print-btn" id="printPOBtn" style="text-decoration: none;" data-po-number="<?= urlencode($selected_po) ?>" data-requested-by="<?= urlencode($selected_requested_by) ?>">
                    <i data-lucide="printer" style="width:16px;height:16px;"></i>
                    Print
                </a>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="view-only-badge">
                    <i data-lucide="lock" style="width:14px;height:14px;"></i>
                    View Only
                </span>
                <?php if ($po_details && isset($po_details['print_status'])): ?>
                <span class="print-status-badge <?= strtolower($po_details['print_status']) === 'printed' ? 'printed' : 'unprinted' ?>">
                    <?= htmlspecialchars($po_details['print_status'] ?? 'Unprinted') ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <label>
                <i data-lucide="filter" style="width:16px;height:16px;"></i>
                Select Purchase Order:
            </label>
            <select id="po-filter" onchange="window.location.href='?po_number=' + encodeURIComponent(this.value)">
                <?php foreach ($po_numbers as $po): ?>
                    <option value="<?php echo htmlspecialchars($po); ?>" <?php echo $selected_po === $po ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($po); ?>
                        <?php 
                        // Check if this PO has been printed
                        $status_query = $conn->query("SELECT print_status FROM purchase_order WHERE po_number = '$po' LIMIT 1");
                        $status_row = $status_query->fetch_assoc();
                        if ($status_row && $status_row['print_status'] === 'Printed') {
                            echo ' (Printed)';
                        }
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($po_numbers)): ?>
                <span style="color: var(--text-muted); font-size: 14px;">No purchase orders found.</span>
            <?php endif; ?>
        </div>

        <?php if ($po_details && !empty($po_items)): ?>
            <!-- PO Info -->
            <div class="form-card">
                <div class="form-card-title">
                    <i data-lucide="truck" style="width:16px;height:16px;"></i> 
                    Supplier & Order Details
                    <span style="margin-left: auto;">
                        <span class="po-tag"><?php echo htmlspecialchars($po_details['po_number']); ?></span>
                        <span class="status-badge <?php echo strtolower($po_details['status'] ?? 'created'); ?>">
                            <?php echo htmlspecialchars($po_details['status'] ?? 'Created'); ?>
                        </span>
                        <?php if (isset($po_details['print_status']) && $po_details['print_status'] === 'Printed'): ?>
                        <span class="print-status-badge printed" style="margin-left: 8px;">
                            <i data-lucide="check-circle" style="width:12px;height:12px;display:inline;"></i>
                            Printed
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
                        <span class="label">Purchase Type</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['purchase_type']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">PO Date</span>
                        <span class="value"><?php echo date('F d, Y', strtotime($po_details['po_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Expected Delivery</span>
                        <span class="value"><?php echo $po_details['expected_delivery'] ? date('F d, Y', strtotime($po_details['expected_delivery'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Payment Terms</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['payment_terms']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Currency</span>
                        <span class="value"><?php echo htmlspecialchars($po_details['currency']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Requested By</span>
                        <span class="value">
                            <select id="requested_by_dropdown" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 13px; background: white; min-width: 150px;">
                                <option value="">Select Requestor</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo htmlspecialchars($emp); ?>" <?php echo $selected_requested_by === $emp ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                                
                                if ($with_vat) {
                                    $without_vat = $discounted_total / 1.12;
                                    $vat_amount = $discounted_total - $without_vat;
                                } else {
                                    $without_vat = $discounted_total;
                                    $vat_amount = 0;
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
                        // Calculate item subtotal before discounts from the items
                        $item_subtotal_before_discounts = 0;
                        foreach ($po_items as $item) {
                            $item_subtotal_before_discounts += floatval($item['unit_cost']) * intval($item['qty_ordered']);
                        }
                        
                        // Get values from summary
                        $subtotal = $po_summary['subtotal'] ?? 0;
                        $total_vat = $po_summary['total_vat'] ?? 0;
                        $freight = $po_summary['freight'] ?? 0;
                        $po_discount = $po_summary['discount_amount'] ?? 0;
                        $item_discount = $po_summary['item_discount_amount'] ?? 0;
                        $po_discount_percent = $po_summary['discount_percent'] ?? 0;
                        
                        // Calculate the actual grand total
                        $grand_total = $subtotal - $po_discount + $total_vat + $freight;
                        ?>
                        <div class="totals-row">
                            <span>Item Subtotal</span>
                            <span>₱<?php echo number_format($item_subtotal_before_discounts, 2); ?></span>
                        </div>
                        
                        <?php if ($item_discount > 0): ?>
                        <div class="totals-row" style="color:#dc2626;">
                            <span>Item Discounts</span>
                            <span>- ₱<?php echo number_format($item_discount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="totals-row" style="border-top:1px dashed #d1d5db;padding-top:6px;">
                            <span>Subtotal (excl. tax)</span>
                            <span>₱<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        
                        <?php if ($po_discount_percent > 0 && $po_discount > 0): ?>
                        <div class="totals-row" style="color:#dc2626;">
                            <span>PO Discount (<?php echo number_format($po_discount_percent, 2); ?>%)</span>
                            <span>- ₱<?php echo number_format($po_discount, 2); ?></span>
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
                                <span class="tax-label-view">Total PO Amount: </span>
                                <span class="tax-value-view">₱<?php echo number_format($grand_total, 2); ?></span>
                            </div>
                            <div class="tax-row-view">
                                <span class="tax-label-view">Withholding Tax (<?php echo $po_summary['withholding_tax_percent']; ?>%):</span>
                                <span class="tax-value-view">₱<?php echo number_format($po_summary['withholding_tax_amount'] ?? 0, 2); ?></span>
                            </div>
                            <div class="tax-row-view net-amount-row-view">
                                <span class="tax-label-view">Net Amount Due:</span>
                                <span class="tax-value-view">₱<?php echo number_format($po_summary['net_amount_due'] ?? $grand_total, 2); ?></span>
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
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 13px; color: var(--text-muted);">
                <span>Created by: <?php echo htmlspecialchars($po_details['created_by']); ?></span>
                <span>Created at: <?php echo date('F d, Y h:i A', strtotime($po_details['created_at'])); ?></span>
                <?php if ($po_details['delivery_status']): ?>
                <span>Delivery Status: <?php echo htmlspecialchars($po_details['delivery_status']); ?></span>
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
    
    // Handle requested_by dropdown change
    document.getElementById('requested_by_dropdown').addEventListener('change', function() {
        const poNumber = '<?php echo htmlspecialchars($selected_po); ?>';
        const requestedBy = this.value;
        
        if (poNumber) {
            // Save to session via AJAX
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=set_requested_by&po_number=' + encodeURIComponent(poNumber) + '&requested_by=' + encodeURIComponent(requestedBy)
            });
            
            // Update the print button data attribute
            document.getElementById('printPOBtn').setAttribute('data-requested-by', requestedBy);
        }
    });
    
    // Handle print button click
    document.getElementById('printPOBtn').addEventListener('click', function(e) {
        e.preventDefault();
        const poNumber = this.getAttribute('data-po-number');
        const requestedBy = this.getAttribute('data-requested-by') || '';
        
        if (!poNumber) {
            alert('No purchase order selected.');
            return;
        }
        
        // Check if requester is selected
        if (!requestedBy || requestedBy === '') {
            alert('Please select a Requested By person before printing.');
            // Highlight the dropdown
            const dropdown = document.getElementById('requested_by_dropdown');
            dropdown.style.borderColor = '#dc2626';
            dropdown.style.backgroundColor = '#fee2e2';
            setTimeout(() => {
                dropdown.style.borderColor = '#d1d5db';
                dropdown.style.backgroundColor = 'white';
            }, 3000);
            return;
        }
        
        // Show loading state
        const originalText = this.innerHTML;
        this.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite;"></i> Loading...';
        lucide.createIcons();
        
        // First, check the current print status
        fetch('get_print_status.php?po_number=' + encodeURIComponent(poNumber))
        .then(response => response.json())
        .then(statusData => {
            const isAlreadyPrinted = statusData.print_status === 'Printed';
            
            // Then update the print status via AJAX
            return fetch('update_print_status.php', {
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
                
                if (updateData.success) {
                    // Open print page with reprint flag and requested_by
                    let printUrl = 'purchase_order_print.php?po_number=' + encodeURIComponent(poNumber);
                    if (isAlreadyPrinted) {
                        printUrl += '&is_reprint=1';
                    }
                    if (requestedBy) {
                        printUrl += '&requested_by=' + encodeURIComponent(requestedBy);
                    }
                    window.open(printUrl, '_blank');
                    
                    // Refresh the page to update print status badge
                    setTimeout(() => {
                        location.reload();
                    }, 500);
                } else {
                    alert('Error updating print status: ' + (updateData.message || 'Unknown error'));
                    // Still open the print page even if status update fails
                    let printUrl = 'purchase_order_print.php?po_number=' + encodeURIComponent(poNumber);
                    if (isAlreadyPrinted) {
                        printUrl += '&is_reprint=1';
                    }
                    if (requestedBy) {
                        printUrl += '&requested_by=' + encodeURIComponent(requestedBy);
                    }
                    window.open(printUrl, '_blank');
                }
            });
        })
        .catch(error => {
            console.error('Error:', error);
            // Restore button text
            this.innerHTML = originalText;
            lucide.createIcons();
            // Still open the print page even if status update fails
            let printUrl = 'purchase_order_print.php?po_number=' + encodeURIComponent(poNumber);
            if (requestedBy) {
                printUrl += '&requested_by=' + encodeURIComponent(requestedBy);
            }
            window.open(printUrl, '_blank');
        });
    });
</script>

</body>
</html>