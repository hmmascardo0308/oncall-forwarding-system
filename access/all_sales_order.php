<?php
// all_sales_order.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

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

// Check if user has access to view invoice (admin or service_invoice_maker)
$can_access_si = $is_admin || in_array('service_invoice_maker', $user_roles);

if (!$can_access_si) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to view Service Invoices."
    ];
    header("Location: home.php");
    exit;
}

// Define allowed pages for sidebar - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

$role_display_name = getRoleDisplayName($user_roles);

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Get invoice_no from URL
$invoice_no = isset($_GET['invoice_no']) ? mysqli_real_escape_string($conn, $_GET['invoice_no']) : '';
$view_mode = !empty($invoice_no);

// If viewing a specific invoice, fetch it
if ($view_mode) {
    $sql = "SELECT si.*, 
            DATE_FORMAT(si.invoice_date, '%M %d, %Y') as formatted_invoice_date,
            DATE_FORMAT(si.due_date, '%M %d, %Y') as formatted_due_date,
            DATE_FORMAT(si.order_date, '%M %d, %Y') as formatted_order_date,
            DATE_FORMAT(si.delivery_date, '%M %d, %Y') as formatted_delivery_date,
            DATE_FORMAT(si.paid_at, '%M %d, %Y') as formatted_paid_at,
            DATE_FORMAT(si.created_date, '%M %d, %Y %h:%i %p') as formatted_created_date,
            DATE_FORMAT(si.updated_at, '%M %d, %Y %h:%i %p') as formatted_updated_at
            FROM service_invoice si 
            WHERE si.invoice_no = '$invoice_no'
            ORDER BY si.id ASC";
    $result = $conn->query($sql);
    $invoices = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $invoices[] = $row;
        }
    }
    
    if (empty($invoices)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => "Service Invoice #$invoice_no not found."
        ];
        header("Location: service_invoice_list.php");
        exit;
    }
    
    // Get the first invoice for header info
    $invoice = $invoices[0];
} else {
    // List all invoices
    $sql = "SELECT si.invoice_no, si.customer_code, si.customer_name, 
            COUNT(si.id) as item_count,
            SUM(si.total_amount) as total_amount,
            si.payment_status, si.payment_method, si.payment_terms,
            MAX(si.created_date) as latest_date,
            MAX(si.invoice_date) as invoice_date,
            MAX(si.due_date) as due_date,
            GROUP_CONCAT(DISTINCT si.sales_order_no) as sales_orders
            FROM service_invoice si 
            GROUP BY si.invoice_no, si.customer_code, si.customer_name, 
                     si.payment_status, si.payment_method, si.payment_terms
            ORDER BY MAX(si.created_date) DESC";
    $result = $conn->query($sql);
    $invoices = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $invoices[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view_mode ? 'View Service Invoice #' . htmlspecialchars($invoice_no) : 'Service Invoices List'; ?> | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/service_invoice.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/all_sales.css?v=<?= time(); ?>">

    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
</head>
<body>

<!-- Access Denied Modal -->
<div id="accessModal" class="modal-overlay">
    <div class="access-modal">
        <i data-lucide="shield-off"></i>
        <h3>Access Denied</h3>
        <p>You don't have permission to access this page.</p>
        <button class="modal-btn" onclick="closeModal()">OK</button>
    </div>
</div>

<!-- Include Sidebar -->
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / 
                <a href="service_invoice.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;">Service Invoice</a>
                <?php if ($view_mode): ?>
                    / <span style="color:red; font-weight: bold; font-size: 16px;"> View #<?php echo htmlspecialchars($invoice_no); ?> </span>
                <?php else: ?>
                    / <span style="color:red; font-weight: bold; font-size: 16px;"> List</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="<?php echo $_SESSION['flash_message']['type'] === 'success' ? 'success-message' : 'error-message'; ?>" style="margin-bottom: 20px;">
                <?php 
                echo htmlspecialchars($_SESSION['flash_message']['text']);
                unset($_SESSION['flash_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if ($view_mode): ?>
            <!-- ============================================ -->
            <!-- VIEW MODE - Display Single Invoice           -->
            <!-- ============================================ -->
            <div class="page-header">
                <h1>
                    <span class="icon-wrap"><i data-lucide="philippine-peso" style="width:18px;height:18px;"></i></span>
                    Service Invoice Details
                </h1>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span class="inv-tag"><?php echo htmlspecialchars($invoice_no); ?></span>
                    <span class="status-<?php echo strtolower($invoice['payment_status'] ?? 'created'); ?>">
                        <?php echo ucfirst($invoice['payment_status'] ?? 'Created'); ?>
                    </span>
                    <span style="font-size:13px;color:var(--text-muted);">
                        <?php echo count($invoices); ?> item(s)
                    </span>
                </div>
            </div>

            <!-- Invoice Header Banner -->
            <div class="invoice-header-view">
                <div>
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div>
                            <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">INVOICE NUMBER</div>
                            <div class="inv-number-large"><?php echo htmlspecialchars($invoice_no); ?></div>
                        </div>
                        <div class="meta-badge">
                            <i data-lucide="calendar" style="width:16px;height:16px;color:var(--accent-green);"></i>
                            <span>Issued: <?php echo htmlspecialchars($invoice['formatted_invoice_date'] ?? date('F d, Y')); ?></span>
                        </div>
                        <div class="meta-badge">
                            <i data-lucide="clock" style="width:16px;height:16px;color:var(--accent-green);"></i>
                            <span>Due: <?php echo htmlspecialchars($invoice['formatted_due_date'] ?? '—'); ?></span>
                        </div>
                    </div>
                </div>
                <div>
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">STATUS</div>
                    <div>
                        <span class="status-<?php echo strtolower($invoice['payment_status'] ?? 'created'); ?>">
                            <?php echo ucfirst($invoice['payment_status'] ?? 'Created'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Company & Customer Info -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
                <!-- Company Info -->
                <div class="form-card" style="margin-bottom:0;">
                    <div class="form-card-title"><i data-lucide="building" style="width:16px;height:16px;"></i> From</div>
                    <div class="view-section">
                        <div class="info-row">
                            <div class="info-label">Company</div>
                            <div class="info-value"><strong>Oncall Forwarding Corporation</strong></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">TIN</div>
                            <div class="info-value">240-099-925-000</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Address</div>
                            <div class="info-value">Green Field Subd., Inayawan, Cebu City</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Contact</div>
                            <div class="info-value">420-0946 / 383-7076</div>
                        </div>
                    </div>
                </div>

                <!-- Customer Info -->
                <div class="form-card" style="margin-bottom:0;">
                    <div class="form-card-title"><i data-lucide="user-check" style="width:16px;height:16px;"></i> Bill To</div>
                    <div class="view-section">
                        <div class="info-row">
                            <div class="info-label">Customer Code</div>
                            <div class="info-value"><strong><?php echo htmlspecialchars($invoice['customer_code'] ?: '—'); ?></strong></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Customer Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($invoice['customer_name'] ?: '—'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Delivery Address</div>
                            <div class="info-value"><?php echo htmlspecialchars($invoice['delivery_address'] ?: '—'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items / Charges with Truck Details per row -->
            <div class="form-card" style="margin-bottom:24px;">
                <div class="form-card-title"><i data-lucide="list" style="width:16px;height:16px;"></i> Invoice Line Items</div>
                <div class="table-wrapper" style="overflow-x:auto;">
                    <table style="min-width:1200px;">
                        <thead>
                            <tr>
                                <th style="min-width:100px;">SO No.</th>
                                <th style="min-width:180px;">Truck Details</th>
                                <th style="min-width:180px;">Destination</th>
                                <th style="min-width:100px;">Order Date</th>
                                <th style="min-width:100px;">Delivery Date</th>
                                <th style="text-align:center;min-width:50px;">Qty</th>
                                <th style="text-align:right;min-width:100px;">Unit Price</th>
                                <th style="text-align:right;min-width:100px;">Discount</th>
                                <th style="text-align:right;min-width:100px;">VAT</th>
                                <th style="text-align:right;min-width:120px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $subtotal = 0;
                            $total_discount = 0;
                            $total_vat = 0;
                            $grand_total = 0;
                            
                            foreach ($invoices as $item): 
                                $subtotal += floatval($item['amount']);
                                $total_discount += floatval($item['discount_amount']);
                                $total_vat += floatval($item['vat_amount']);
                                $grand_total += floatval($item['total_amount']);
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($item['sales_order_no'] ?: '—'); ?></strong></td>
                                    <td style="font-size:12px;">
                                        <strong><?php echo htmlspecialchars($item['truck_code'] ?: '—'); ?></strong>
                                        <?php if (!empty($item['plate_number'])): ?>
                                            <br><small>Plate: <?php echo htmlspecialchars($item['plate_number']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($item['brand']) || !empty($item['model'])): ?>
                                            <br><small><?php echo htmlspecialchars(trim($item['brand'] . ' ' . $item['model'])); ?></small>
                                        <?php endif; ?>
                                        <br><small>Unit: <?php echo htmlspecialchars($item['unit'] ?: 'TRIP'); ?></small>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php if (!empty($item['destination_from']) || !empty($item['destination_to'])): ?>
                                            <strong><?php echo htmlspecialchars($item['destination_from'] ?: ''); ?></strong>
                                            <?php if (!empty($item['destination_from']) && !empty($item['destination_to'])): ?>
                                                <span style="color:#94a3b8;"> → </span>
                                            <?php endif; ?>
                                            <strong><?php echo htmlspecialchars($item['destination_to'] ?: ''); ?></strong>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php echo !empty($item['order_date']) ? date('M d, Y', strtotime($item['order_date'])) : '—'; ?>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php echo !empty($item['delivery_date']) ? date('M d, Y', strtotime($item['delivery_date'])) : '—'; ?>
                                    </td>
                                    <td style="text-align:center;"><?php echo number_format($item['quantity'], 0); ?></td>
                                    <td style="text-align:right;">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td style="text-align:right;color:#dc2626;">₱<?php echo number_format($item['discount_amount'], 2); ?></td>
                                    <td style="text-align:right;">₱<?php echo number_format($item['vat_amount'], 2); ?></td>
                                    <td style="text-align:right;font-weight:600;">₱<?php echo number_format($item['total_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="9" style="text-align:right;font-weight:600;">Subtotal:</td>
                                <td style="text-align:right;font-weight:600;">₱<?php echo number_format($subtotal, 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="9" style="text-align:right;font-weight:600;">Total Discount:</td>
                                <td style="text-align:right;font-weight:600;color:#dc2626;">−₱<?php echo number_format($total_discount, 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="9" style="text-align:right;font-weight:600;">Total VAT:</td>
                                <td style="text-align:right;font-weight:600;">₱<?php echo number_format($total_vat, 2); ?></td>
                            </tr>
                            <tr style="border-top:2px solid #16a34a;">
                                <td colspan="9" style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">GRAND TOTAL:</td>
                                <td style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">₱<?php echo number_format($grand_total, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Payment & Totals -->
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <!-- Payment Details & Notes -->
                <div style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:16px;">
                    <!-- Payment Method -->
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="credit-card" style="width:16px;height:16px;"></i> Payment Information</div>
                        <div class="view-section">
                            <div class="info-row">
                                <div class="info-label">Payment Method</div>
                                <div class="info-value">
                                    <span class="meta-badge" style="padding:4px 10px;">
                                        <i data-lucide="<?php 
                                            $method = $invoice['payment_method'] ?? '';
                                            echo $method == 'Bank Transfer' ? 'landmark' : 
                                                ($method == 'Cash' ? 'banknote' : 
                                                ($method == 'Check' ? 'check-square' : 'credit-card')); 
                                        ?>" style="width:14px;height:14px;"></i>
                                        <?php echo htmlspecialchars($invoice['payment_method'] ?: 'Not specified'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Payment Terms</div>
                                <div class="info-value"><?php echo htmlspecialchars($invoice['payment_terms'] ?: '—'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Payment Status</div>
                                <div class="info-value">
                                    <span class="status-<?php echo strtolower($invoice['payment_status'] ?? 'created'); ?>">
                                        <?php echo ucfirst($invoice['payment_status'] ?? 'Created'); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if (!empty($invoice['paid_at'])): ?>
                            <div class="info-row">
                                <div class="info-label">Paid At</div>
                                <div class="info-value"><?php echo htmlspecialchars($invoice['formatted_paid_at']); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($invoice['status'])): ?>
                            <div class="info-row">
                                <div class="info-label">Invoice Status</div>
                                <div class="info-value">
                                    <span class="status-<?php echo strtolower($invoice['status']); ?>">
                                        <?php echo ucfirst($invoice['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Remarks / Notes -->
                    <?php if (!empty($invoice['notes'])): ?>
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Remarks / Notes</div>
                        <div class="view-section">
                            <p style="margin:0;font-size:14px;line-height:1.6;color:var(--text-main);">
                                <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Sales Orders Reference -->
                    <?php 
                    $so_numbers = [];
                    foreach ($invoices as $item) {
                        if (!empty($item['sales_order_no'])) {
                            $so_numbers[] = $item['sales_order_no'];
                        }
                    }
                    $so_numbers = array_unique($so_numbers);
                    if (!empty($so_numbers)): 
                    ?>
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="file-text" style="width:16px;height:16px;"></i> Reference Sales Orders</div>
                        <div class="view-section">
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <?php foreach ($so_numbers as $so_no): ?>
                                    <span class="so-ref-badge"><?php echo htmlspecialchars($so_no); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Totals Summary -->
                <div style="min-width:280px;">
                    <div class="totals-box">
                        <div class="totals-row">
                            <span>Subtotal</span>
                            <span>₱<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="totals-row">
                            <span>Discount</span>
                            <span style="color:#dc2626;">−₱<?php echo number_format($total_discount, 2); ?></span>
                        </div>
                        <div class="totals-row">
                            <span>VAT Amount</span>
                            <span>₱<?php echo number_format($total_vat, 2); ?></span>
                        </div>
                        <div class="totals-row total-final">
                            <span>Total Amount</span>
                            <span class="amount-due">₱<?php echo number_format($grand_total, 2); ?></span>
                        </div>
                    </div>
                    
                    <div style="margin-top:16px;text-align:right;font-size:12px;color:var(--text-muted);background:#f8fafc;padding:12px;border-radius:8px;">
                        <div>Prepared by: <strong><?php echo htmlspecialchars($invoice['created_by'] ?: $username); ?></strong></div>
                        <div style="margin-top:4px;">Created: <?php echo htmlspecialchars($invoice['formatted_created_date'] ?? date('F d, Y h:i A')); ?></div>
                        <?php if (!empty($invoice['updated_by'])): ?>
                            <div style="margin-top:4px;border-top:1px solid #e2e8f0;padding-top:4px;">Updated by: <strong><?php echo htmlspecialchars($invoice['updated_by']); ?></strong></div>
                            <div style="margin-top:2px;">Last Updated: <?php echo htmlspecialchars($invoice['formatted_updated_at'] ?? ''); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="actions-row" style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
                <a href="service_invoice_list.php" class="btn-secondary">
                    <i data-lucide="arrow-left" style="width:15px;height:15px;"></i> Back to List
                </a>
                <a href="service_invoice_edit.php?invoice_no=<?php echo urlencode($invoice_no); ?>" class="btn-secondary">
                    <i data-lucide="edit" style="width:15px;height:15px;"></i> Edit Invoice
                </a>
                <a href="service_invoice_print.php?invoice_no=<?php echo urlencode($invoice_no); ?>" target="_blank" class="btn-secondary">
                    <i data-lucide="printer" style="width:15px;height:15px;"></i> Print Invoice
                </a>
                <?php if (strtolower($invoice['payment_status'] ?? '') === 'unpaid'): ?>
                <a href="service_invoice_mark_paid.php?invoice_no=<?php echo urlencode($invoice_no); ?>" class="btn-primary" onclick="return confirm('Mark this invoice as Paid?')">
                    <i data-lucide="check-circle" style="width:15px;height:15px;"></i> Mark as Paid
                </a>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ============================================ -->
            <!-- LIST MODE - Display All Invoices             -->
            <!-- ============================================ -->
            <div class="page-header">
                <h1>
                    <span class="icon-wrap"><i data-lucide="philippine-peso" style="width:18px;height:18px;"></i></span>
                    Service Invoices
                </h1>
                <a href="service_invoice.php" class="btn-primary">
                    <i data-lucide="plus" style="width:15px;height:15px;"></i> New Invoice
                </a>
            </div>

            <!-- Filters -->
            <div class="list-header">
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search by invoice # or customer..." onkeyup="filterInvoices()">
                    <select id="statusFilter" class="filter-select" onchange="filterInvoices()">
                        <option value="">All Status</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="created">Created</option>
                    </select>
                </div>
                <div style="font-size:14px;color:var(--text-muted);">
                    Total: <strong id="invoiceCount"><?php echo count($invoices); ?></strong> invoice(s)
                </div>
            </div>

            <?php if (empty($invoices)): ?>
                <div class="empty-state">
                    <i data-lucide="file-text"></i>
                    <h3>No Service Invoices Found</h3>
                    <p style="color:var(--text-muted);">Create your first service invoice by clicking the "New Invoice" button.</p>
                </div>
            <?php else: ?>
                <div id="invoiceList">
                    <?php foreach ($invoices as $inv): ?>
                        <div class="invoice-card" data-invoice="<?php echo htmlspecialchars($inv['invoice_no']); ?>" data-customer="<?php echo htmlspecialchars(strtolower($inv['customer_name'])); ?>" data-status="<?php echo strtolower($inv['payment_status'] ?? 'created'); ?>">
                            <div style="display:flex;align-items:center;gap:16px;flex:1;min-width:200px;">
                                <span class="inv-code"><?php echo htmlspecialchars($inv['invoice_no']); ?></span>
                                <span class="inv-customer">
                                    <strong><?php echo htmlspecialchars($inv['customer_code'] ?: ''); ?></strong>
                                    <?php echo htmlspecialchars($inv['customer_name']); ?>
                                </span>
                            </div>
                            <div class="inv-meta">
                                <span>
                                    <i data-lucide="calendar"></i>
                                    <?php echo date('M d, Y', strtotime($inv['invoice_date'] ?? $inv['latest_date'])); ?>
                                </span>
                                <span>
                                    <i data-lucide="file-text"></i>
                                    <?php echo $inv['item_count']; ?> item(s)
                                </span>
                                <span>
                                    <i data-lucide="credit-card"></i>
                                    <?php echo htmlspecialchars($inv['payment_method'] ?: '—'); ?>
                                </span>
                                <span class="status-<?php echo strtolower($inv['payment_status'] ?? 'created'); ?>">
                                    <?php echo ucfirst($inv['payment_status'] ?? 'Created'); ?>
                                </span>
                            </div>
                            <span class="inv-total">₱<?php echo number_format($inv['total_amount'], 2); ?></span>
                            <div class="inv-actions">
                                <a href="service_invoice_list.php?invoice_no=<?php echo urlencode($inv['invoice_no']); ?>" class="btn-view">
                                    <i data-lucide="eye" style="width:14px;height:14px;"></i> View
                                </a>
                                <a href="service_invoice_edit.php?invoice_no=<?php echo urlencode($inv['invoice_no']); ?>" class="btn-edit">
                                    <i data-lucide="edit" style="width:14px;height:14px;"></i> Edit
                                </a>
                                <a href="service_invoice_print.php?invoice_no=<?php echo urlencode($inv['invoice_no']); ?>" target="_blank" class="btn-print">
                                    <i data-lucide="printer" style="width:14px;height:14px;"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<script>
    lucide.createIcons();

    // User roles from PHP
    const userRoles = <?php echo json_encode($user_roles); ?>;
    
    // Allowed pages from PHP
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    
    // Modal element
    const modal = document.getElementById('accessModal');

    // Check access function
    function checkAccess(page) {
        if (userRoles.includes('admin')) {
            return true;
        }
        
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) {
                return true;
            }
        }
        
        modal.style.display = 'flex';
        return false;
    }

    // Close modal function
    function closeModal() {
        modal.style.display = 'none';
    }

    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal?.style.display === 'flex') {
            closeModal();
        }
    });

    <?php if (!$view_mode): ?>
    // Filter invoices in list mode
    function filterInvoices() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const status = document.getElementById('statusFilter').value.toLowerCase();
        const cards = document.querySelectorAll('.invoice-card');
        let visible = 0;
        
        cards.forEach(card => {
            const invoice = card.dataset.invoice.toLowerCase();
            const customer = card.dataset.customer;
            const cardStatus = card.dataset.status;
            
            let matches = true;
            
            if (search) {
                matches = invoice.includes(search) || customer.includes(search);
            }
            
            if (matches && status) {
                matches = cardStatus === status;
            }
            
            card.style.display = matches ? 'flex' : 'none';
            if (matches) visible++;
        });
        
        document.getElementById('invoiceCount').textContent = visible;
    }
    <?php endif; ?>
</script>
</body>
</html>