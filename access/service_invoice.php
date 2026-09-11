<?php
// service_invoice.php
session_start();
// Set timezone to match your location
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name  = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Use centralized access control
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

// Role display name - now using centralized function
$role_display_name = getRoleDisplayName($user_roles);

// Fetch distinct sales orders from database - with customer_code for grouping
// MODIFIED: Only fetch if filter is applied
$sales_orders = [];
$filter_customer = isset($_GET['filter_customer']) ? $_GET['filter_customer'] : '';

if (!empty($filter_customer)) {
    $sql = "SELECT DISTINCT sales_order_no, customer_name, customer_code, order_date, delivery_date,
            truck_code, brand, model, plate_number, unit_price, discount_percent, vat_percent, created_date, 
            destination_from, destination_to, delivery_address, payment_terms
            FROM sales_order 
            WHERE status != 'cancelled' 
            AND customer_code = ? 
            ORDER BY customer_code, created_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $filter_customer);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $sales_orders[] = $row;
        }
    }
    $stmt->close();
}

// Fetch existing service invoices for dropdown
$service_invoices = [];
$si_sql = "SELECT invoice_no, customer_name, MAX(created_date) as latest_date 
           FROM service_invoice 
           GROUP BY invoice_no, customer_name 
           ORDER BY latest_date DESC";
$si_result = $conn->query($si_sql);
if ($si_result && $si_result->num_rows > 0) {
    while ($row = $si_result->fetch_assoc()) {
        $service_invoices[] = $row;
    }
}

// Generate invoice number - Updated format: INV-YEAR-00001
function generateInvoiceNo($conn) {
    $year = date('Y');
    $prefix = 'INV-' . $year;
    
    $sql = "SELECT invoice_no FROM service_invoice 
            WHERE invoice_no LIKE '$prefix-%' 
            ORDER BY id DESC LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_no = intval(substr($row['invoice_no'], -5));
        $new_no = str_pad($last_no + 1, 5, '0', STR_PAD_LEFT);
    } else {
        $new_no = '00001';
    }
    
    return $prefix . '-' . $new_no;
}

$invoice_no = generateInvoiceNo($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_invoice') {
        // Get selected sales orders
        $selected_so_data = isset($_POST['selected_so_data']) ? json_decode($_POST['selected_so_data'], true) : [];
        
        if (empty($selected_so_data)) {
            $error = "Please select at least one Sales Order.";
        } else {
            // Prepare common data
            $invoice_no = mysqli_real_escape_string($conn, $_POST['invoice_no']);
            $customer_code = mysqli_real_escape_string($conn, $_POST['customer_code'] ?? '');
            $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
            $payment_terms = mysqli_real_escape_string($conn, $_POST['payment_terms'] ?? 'Net 30');
            $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? 'Unpaid');
            $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
            $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Created');
            $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'Bank Transfer');
            $invoice_date = !empty($_POST['invoice_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['invoice_date']) . "'" : 'NULL';
            $due_date = !empty($_POST['due_date']) ? "'" . mysqli_real_escape_string($conn, $_POST['due_date']) . "'" : 'NULL';
            $delivery_address = mysqli_real_escape_string($conn, $_POST['delivery_address'] ?? '');
            
            $insert_count = 0;
            $grand_total = 0;
            
            // Insert each selected sales order as a separate row
            foreach ($selected_so_data as $so) {
                $sales_order_no = mysqli_real_escape_string($conn, $so['sales_order_no']);
                $truck_code = mysqli_real_escape_string($conn, $so['truck_code'] ?? '');
                $plate_number = mysqli_real_escape_string($conn, $so['plate_number'] ?? '');
                $brand = mysqli_real_escape_string($conn, $so['brand'] ?? '');
                $model = mysqli_real_escape_string($conn, $so['model'] ?? '');
                $unit = 'TRIP';
                $destination_from = mysqli_real_escape_string($conn, $so['destination_from'] ?? '');
                $destination_to = mysqli_real_escape_string($conn, $so['destination_to'] ?? '');
                $order_date = !empty($so['order_date']) ? "'" . mysqli_real_escape_string($conn, $so['order_date']) . "'" : 'NULL';
                $delivery_date = !empty($so['delivery_date']) ? "'" . mysqli_real_escape_string($conn, $so['delivery_date']) . "'" : 'NULL';
                
                $unit_price = floatval($so['unit_price'] ?? 0);
                $discount_percent = floatval($so['discount_percent'] ?? 0);
                $vat_percent = floatval($so['vat_percent'] ?? 12);
                $quantity = 1;
                
                // Calculate amounts for this row
                $discount_amount = $unit_price * ($discount_percent / 100);
                $net_amount = $unit_price - $discount_amount;
                $vat_amount = $net_amount * ($vat_percent / 100);
                $total = $net_amount + $vat_amount;
                
                $grand_total += $total;
                
                // Insert row
                $insert_sql = "INSERT INTO service_invoice (
                    invoice_no, sales_order_id, sales_order_no, 
                    customer_code, customer_name,
                    truck_code, plate_number, brand, model, unit, destination_from, destination_to,
                    delivery_address, order_date, delivery_date, invoice_date, due_date,
                    quantity, unit_price, vat_percent, discount_percent, amount, discount_amount,
                    vat_amount, total_amount, payment_terms, payment_method, payment_status, notes, status,
                    created_by, created_date
                ) VALUES (
                    '$invoice_no', NULL, '$sales_order_no',
                    '$customer_code', '$customer_name',
                    '$truck_code', '$plate_number', '$brand', '$model', '$unit', '$destination_from', '$destination_to',
                    '$delivery_address', $order_date, $delivery_date, $invoice_date, $due_date,
                    $quantity, $unit_price, $vat_percent, $discount_percent, 
                    $unit_price, $discount_amount, $vat_amount, $total,
                    '$payment_terms', '$payment_method', '$payment_status', '$notes', '$status',
                    '$username', NOW()
                )";
                
                if ($conn->query($insert_sql)) {
                    $insert_count++;
                } else {
                    $error = "Error saving invoice row for SO $sales_order_no: " . $conn->error;
                    break;
                }
            }
            
            if ($insert_count > 0 && !isset($error)) {
                $_SESSION['flash_message'] = [
                    'type' => 'success',
                    'text' => "Service Invoice #$invoice_no has been created successfully with $insert_count sales order(s). Total: ₱" . number_format($grand_total, 2)
                ];
                header("Location: service_invoice_list.php?invoice_no=" . $invoice_no);
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Invoice | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/service_invoice.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
</head>
<body>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner"></div>
</div>

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
                ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Service Invoice</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if (isset($error)): ?>
            <div class="error-message" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="<?php echo $_SESSION['flash_message']['type'] === 'success' ? 'success-message' : 'error-message'; ?>" style="margin-bottom: 20px;">
                <?php 
                echo htmlspecialchars($_SESSION['flash_message']['text']);
                unset($_SESSION['flash_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <h1>
                    <span class="icon-wrap"><i data-lucide="philippine-peso" style="width:18px;height:18px;"></i></span>
                    New Service Invoice
                </h1>
                <div style="display:flex;align-items:center;gap:8px;border-left: 2px solid #e2e8f0;padding-left:16px;">
                    <span style="font-size: 14px; color: var(--text-muted);">View Existing:</span>
                    <div class="so-selector-wrap">
                        <select onchange="if(this.value) window.location.href='service_invoice_list.php?invoice_no='+this.value">
                            <option value="">-- Select a Service Invoice --</option>
                            <?php foreach ($service_invoices as $si): ?>
                                <option value="<?php echo htmlspecialchars($si['invoice_no']); ?>">
                                    <?php echo htmlspecialchars($si['invoice_no'] . ' - ' . $si['customer_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="inv-tag"><?php echo $invoice_no; ?></span>
                <span class="status-unpaid">Draft</span>
            </div>
        </div>

        <!-- Invoice Header Banner -->
        <div class="invoice-banner">
            <div>
                <div class="company-name">Oncall Forwarding Corporation</div>
                <div class="company-sub">TIN: 240-099-925-000 | VAT Registered</div>
                <div class="company-sub" style="margin-top:6px;">Green Field Subd., Inayawan, Cebu City</div>
                <div class="company-sub">Fax/Tel. 420-0946 / 383-7076</div>
            </div>
            <div class="inv-meta">
                <div class="inv-label">Service Invoice</div>
                <div class="inv-number"><?php echo $invoice_no; ?></div>
                <div class="inv-date">Date: <?php echo date('F j, Y'); ?></div>
                <div class="inv-date">Due: <span id="displayDueDate">—</span></div>
            </div>
        </div>

        <form id="invoiceForm" method="POST" action="">
            <input type="hidden" name="action" value="save_invoice">
            <input type="hidden" name="invoice_no" value="<?php echo $invoice_no; ?>">
            <input type="hidden" name="status" value="Created">
            <input type="hidden" name="payment_status" value="Unpaid">
            <input type="hidden" name="customer_code" id="customer_code_input">
            <input type="hidden" name="customer_name" id="customer_name_input">
            <input type="hidden" name="selected_so_data" id="selected_so_data">

            <!-- Link to SO -->
            <div class="form-card" style="padding:18px 24px;">
                <div style="display:flex;align-items:center;gap:10px;font-size:16px;font-weight:500;color:black;margin-bottom:12px;">
                    <i data-lucide="link" style="width:15px;height:15px;color:var(--accent-green);"></i> Select Sales Orders to Consolidate
                </div>
                
                <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <label style="font-size:13px;font-weight:500;">Filter by Customer:</label>
                        <select id="customer_filter" style="padding:6px 12px;border-radius:6px;border:1px solid #e2e8f0;">
                            <option value="">-- Select Customer --</option>
                            <?php 
                            // Fetch all unique customers for the dropdown
                            $customer_sql = "SELECT DISTINCT customer_code, customer_name FROM sales_order WHERE status != 'cancelled' ORDER BY customer_code";
                            $customer_result = $conn->query($customer_sql);
                            if ($customer_result && $customer_result->num_rows > 0) {
                                while ($row = $customer_result->fetch_assoc()) {
                                    $selected = ($filter_customer == $row['customer_code']) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($row['customer_code']) . '" ' . $selected . '>' . htmlspecialchars($row['customer_code'] . ' - ' . $row['customer_name']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" class="search-button" onclick="applyFilter()">
                        <i data-lucide="search" style="width:15px;height:15px;vertical-align:middle;"></i> Search
                    </button>
                    <?php if (!empty($filter_customer)): ?>
                        <button type="button" class="btn-secondary" onclick="clearFilter()" style="padding:6px 12px;font-size:12px;">
                            <i data-lucide="x" style="width:14px;height:14px;"></i> Clear Filter
                        </button>
                        <span class="filter-info">
                            <i data-lucide="user" style="width:13px;height:13px;vertical-align:middle;"></i>
                            Showing orders for: <strong><?php echo htmlspecialchars($filter_customer); ?></strong>
                        </span>
                    <?php endif; ?>
                    <span style="font-size:13px;color:var(--text-muted);margin-left:auto;">
                        Selected: <span id="selected_count">0</span> Sales Order(s)
                    </span>
                    <span style="font-size:13px;color:var(--text-muted);">
                        Total Amount: <strong id="selected_total_display" style="color:#16a34a;">₱0.00</strong>
                    </span>
                </div>

                <div class="so-checkbox-grid" id="soCheckboxGrid">
                    <?php if (empty($filter_customer)): ?>
                        <div class="empty-state">
                            <div style="font-size:48px;margin-bottom:12px;">🔍</div>
                            <h4 style="color:#64748b;">No Sales Orders Loaded</h4>
                            <p style="color:#94a3b8;font-size:14px;">Please select a customer and click Search to load their sales orders.</p>
                        </div>
                    <?php elseif (empty($sales_orders)): ?>
                        <div class="empty-state">
                            <div style="font-size:48px;margin-bottom:12px;">📋</div>
                            <h4 style="color:#64748b;">No Orders Found</h4>
                            <p style="color:#94a3b8;font-size:14px;">No active sales orders found for this customer.</p>
                        </div>
                    <?php else: ?>
                        <?php 
                        $grouped = [];
                        foreach ($sales_orders as $so) {
                            $key = $so['customer_code'] . '|' . $so['customer_name'];
                            if (!isset($grouped[$key])) {
                                $grouped[$key] = [
                                    'customer_code' => $so['customer_code'],
                                    'customer_name' => $so['customer_name'],
                                    'orders' => []
                                ];
                            }
                            $grouped[$key]['orders'][] = $so;
                        }
                        ?>
                        <?php foreach ($grouped as $group): ?>
                            <div style="grid-column:1/-1;margin-bottom:4px;">
                                <div class="customer-group-header">
                                    <span>
                                        <span class="customer-code-badge"><?php echo htmlspecialchars($group['customer_code']); ?></span>
                                        <?php echo htmlspecialchars($group['customer_name']); ?>
                                    </span>
                                    <span>
                                        <span class="so-count"><?php echo count($group['orders']); ?> order(s)</span>
                                        <span class="total-amount" style="margin-left:12px;">
                                            Total: ₱<?php 
                                                $group_total = 0;
                                                foreach ($group['orders'] as $so) {
                                                    $group_total += floatval($so['unit_price'] ?? 0);
                                                }
                                                echo number_format($group_total, 2);
                                            ?>
                                        </span>
                                    </span>
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(350px,1fr));gap:8px;padding-left:8px;">
                                    <?php foreach ($group['orders'] as $so): ?>
                                        <label class="so-checkbox-item" data-customer="<?php echo htmlspecialchars($so['customer_code']); ?>">
                                            <input type="checkbox" 
                                                   name="so_checkbox[]" 
                                                   value="<?php echo htmlspecialchars($so['sales_order_no']); ?>"
                                                   data-so-id="<?php echo htmlspecialchars($so['sales_order_no']); ?>"
                                                   data-customer-code="<?php echo htmlspecialchars($so['customer_code']); ?>"
                                                   data-customer-name="<?php echo htmlspecialchars($so['customer_name']); ?>"
                                                   data-truck="<?php echo htmlspecialchars($so['truck_code']); ?>"
                                                   data-plate="<?php echo htmlspecialchars($so['plate_number'] ?? ''); ?>"
                                                   data-brand="<?php echo htmlspecialchars($so['brand']); ?>"
                                                   data-model="<?php echo htmlspecialchars($so['model']); ?>"
                                                   data-unit-price="<?php echo htmlspecialchars($so['unit_price'] ?? 0); ?>"
                                                   data-discount-percent="<?php echo htmlspecialchars($so['discount_percent'] ?? 0); ?>"
                                                   data-vat-percent="<?php echo htmlspecialchars($so['vat_percent'] ?? 12); ?>"
                                                   data-payment-terms="<?php echo htmlspecialchars($so['payment_terms'] ?? 'Net 30'); ?>"
                                                   data-destination-from="<?php echo htmlspecialchars($so['destination_from'] ?? ''); ?>"
                                                   data-destination-to="<?php echo htmlspecialchars($so['destination_to'] ?? ''); ?>"
                                                   data-order-date="<?php echo htmlspecialchars($so['order_date'] ?? ''); ?>"
                                                   data-delivery-date="<?php echo htmlspecialchars($so['delivery_date'] ?? ''); ?>"
                                                   data-delivery-address="<?php echo htmlspecialchars($so['delivery_address'] ?? ''); ?>"
                                                   onchange="handleCheckboxChange(this)">
                                            <span class="so-code"><?php echo htmlspecialchars($so['sales_order_no']); ?></span>
                                            <span class="so-truck"><?php echo htmlspecialchars($so['truck_code']); ?></span>
                                            <span class="so-destination">
                                                <?php 
                                                $from = substr($so['destination_from'] ?? '', 0, 10);
                                                $to = substr($so['destination_to'] ?? '', 0, 10);
                                                echo $from . ' → ' . $to;
                                                ?>
                                            </span>
                                            <span class="so-amount">₱<?php echo number_format(floatval($so['unit_price'] ?? 0), 2); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Selected SO Summary with Line Items Preview -->
                <div class="selected-so-summary" id="selectedSummary">
                    <div style="font-weight:600;font-size:13px;color:#166534;margin-bottom:6px;">
                        <i data-lucide="check-circle" style="width:16px;height:16px;"></i> Selected Sales Orders:
                    </div>
                    <div id="summaryList"></div>
                    <div class="line-items-preview" id="lineItemsPreview" style="display:none;">
                        <table>
                            <thead>
                                <tr>
                                    <th>SO No.</th>
                                    <th>Truck</th>
                                    <th>From → To</th>
                                    <th style="text-align:right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody">
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="3" style="text-align:right;font-weight:700;">GRAND TOTAL</td>
                                    <td style="text-align:right;font-weight:700;color:#16a34a;" id="previewGrandTotal">₱0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Invoice Details -->
            <div class="form-card">
                <div class="form-card-title"><i data-lucide="user-check" style="width:16px;height:16px;"></i> Bill To / Invoice Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Customer Code</label>
                        <input type="text" id="customer_code_display" readonly placeholder="Select Sales Order(s)" class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Customer Name</label>
                        <input type="text" id="customer_name_display" readonly placeholder="Select Sales Order(s)" class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Invoice Date</label>
                        <input type="date" name="invoice_date" id="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="due_date" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Terms</label>
                        <input type="text" name="payment_terms" id="payment_terms" readonly placeholder="Auto-filled from SO" class="readonly-field">
                        <input type="hidden" name="payment_terms_value" id="payment_terms_value">
                    </div>
                    <div class="form-group">
                        <label>Delivery Address</label>
                        <input type="text" name="delivery_address" id="delivery_address" placeholder="Delivery address">
                    </div>
                </div>
            </div>

            <!-- Line Items / Charges -->
            <div class="form-card">
                <div class="form-card-title"><i data-lucide="list" style="width:16px;height:16px;"></i> Invoice Line Items</div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:15%;">SO No.</th>
                                <th style="width:20%;">Truck</th>
                                <th style="width:25%;">Destination</th>
                                <th style="width:10%;">Qty</th>
                                <th style="width:15%;text-align:right;">Unit Price</th>
                                <th style="width:15%;text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="no-items-row">
                                <td colspan="6" style="text-align:center;color:var(--text-muted);padding:20px;">
                                    Select sales orders to populate line items
                                </td>
                            </tr>
                        </tbody>
                        <tfoot id="items-footer" style="display:none;">
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:600;">Subtotal:</td>
                                <td style="text-align:right;font-weight:600;" id="subtotal_display">₱0.00</td>
                            </tr>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:600;">Discount:</td>
                                <td style="text-align:right;font-weight:600;color:#dc2626;" id="discount_display">−₱0.00</td>
                            </tr>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:600;">VAT (12%):</td>
                                <td style="text-align:right;font-weight:600;" id="vat_display">₱0.00</td>
                            </tr>
                            <tr style="border-top:2px solid #16a34a;">
                                <td colspan="5" style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">TOTAL:</td>
                                <td style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;" id="grand_total_display">₱0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <input type="hidden" name="amount" id="amount" value="0">
                <input type="hidden" name="discount_amount" id="discount_amount" value="0">
                <input type="hidden" name="vat_amount" id="vat_amount" value="0">
                <input type="hidden" name="total_amount" id="total_amount" value="0">
            </div>

            <!-- Payment Method + Totals -->
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:16px;">
                    <!-- Payment Method -->
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="credit-card" style="width:16px;height:16px;"></i> Payment Method</div>
                        <div class="payment-status-row" id="payment-methods">
                            <button type="button" class="payment-method-btn selected" onclick="selectPayment(this, 'Bank Transfer')">
                                <i data-lucide="landmark"></i> Bank Transfer
                            </button>
                            <button type="button" class="payment-method-btn" onclick="selectPayment(this, 'Cash')">
                                <i data-lucide="banknote"></i> Cash
                            </button>
                            <button type="button" class="payment-method-btn" onclick="selectPayment(this, 'Check')">
                                <i data-lucide="check-square"></i> Check
                            </button>
                        </div>
                        <input type="hidden" name="payment_method" id="payment_method" value="Bank Transfer">
                    </div>
                    <!-- Remarks -->
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="message-square" style="width:16px;height:16px;"></i> Remarks / Notes</div>
                        <textarea name="notes" id="notes" placeholder="Add payment instructions, bank details, or notes for the customer..." style="width:100%;min-height:90px;"></textarea>
                    </div>
                </div>

                <div>
                    <div class="totals-box">
                        <div class="totals-row"><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
                        <div class="totals-row"><span>Discount</span><span id="discount">−₱0.00</span></div>
                        <div class="totals-row"><span>VAT Amount</span><span id="vat">₱0.00</span></div>
                        <div class="totals-row total-final">
                            <span>Total Amount</span>
                            <span class="amount-due" id="grand_total">₱0.00</span>
                        </div>
                    </div>
                    <div style="margin-top:12px;text-align:right;font-size:12px;color:var(--text-muted);">
                        Prepared by: <strong><?php echo htmlspecialchars($username); ?></strong>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="actions-row" style="margin-top:24px;">
                <a href="service_invoice_list.php" class="btn-secondary"><i data-lucide="x" style="width:15px;height:15px;"></i> Cancel</a>
                <button type="submit" class="btn-primary" onclick="return validateForm()">
                    <i data-lucide="save" style="width:15px;height:15px;"></i> Create Invoice
                </button>
            </div>
        </form>
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

    function formatPHP(val) {
        return '₱' + parseFloat(val).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function calculateTotals() {
        const items = document.querySelectorAll('#items-body tr:not(#no-items-row)');
        let subtotal = 0;
        let totalDiscount = 0;
        let totalVat = 0;
        let grandTotal = 0;
        
        items.forEach(row => {
            const price = parseFloat(row.dataset.unitPrice) || 0;
            const discountPercent = parseFloat(row.dataset.discountPercent) || 0;
            const vatPercent = parseFloat(row.dataset.vatPercent) || 12;
            
            const discountAmount = price * (discountPercent / 100);
            const netAmount = price - discountAmount;
            const vatAmount = netAmount * (vatPercent / 100);
            const total = netAmount + vatAmount;
            
            subtotal += price;
            totalDiscount += discountAmount;
            totalVat += vatAmount;
            grandTotal += total;
        });
        
        // Update display
        document.getElementById('subtotal').textContent = formatPHP(subtotal);
        document.getElementById('discount').textContent = '−' + formatPHP(totalDiscount);
        document.getElementById('vat').textContent = formatPHP(totalVat);
        document.getElementById('grand_total').textContent = formatPHP(grandTotal);
        
        document.getElementById('subtotal_display').textContent = formatPHP(subtotal);
        document.getElementById('discount_display').textContent = '−' + formatPHP(totalDiscount);
        document.getElementById('vat_display').textContent = formatPHP(totalVat);
        document.getElementById('grand_total_display').textContent = formatPHP(grandTotal);
        
        // Update hidden fields
        document.getElementById('amount').value = subtotal.toFixed(2);
        document.getElementById('discount_amount').value = totalDiscount.toFixed(2);
        document.getElementById('vat_amount').value = totalVat.toFixed(2);
        document.getElementById('total_amount').value = grandTotal.toFixed(2);
    }

    function calculateDueDate() {
        const terms = document.getElementById('payment_terms').value;
        const invoiceDate = new Date(document.getElementById('invoice_date').value);
        
        if (terms === 'Due on Receipt') {
            document.getElementById('due_date').value = document.getElementById('invoice_date').value;
        } else if (terms === 'Net 30') {
            invoiceDate.setDate(invoiceDate.getDate() + 30);
            document.getElementById('due_date').value = invoiceDate.toISOString().split('T')[0];
        } else if (terms === 'Net 60') {
            invoiceDate.setDate(invoiceDate.getDate() + 60);
            document.getElementById('due_date').value = invoiceDate.toISOString().split('T')[0];
        } else if (terms === 'Net 90') {
            invoiceDate.setDate(invoiceDate.getDate() + 90);
            document.getElementById('due_date').value = invoiceDate.toISOString().split('T')[0];
        }
        
        document.getElementById('displayDueDate').textContent = document.getElementById('due_date').value;
    }

    function selectPayment(btn, method) {
        document.querySelectorAll('.payment-method-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        document.getElementById('payment_method').value = method;
    }

    let selectedCustomerCode = '';
    let selectedCustomerName = '';
    let selectedData = [];

    function handleCheckboxChange(checkbox) {
        const checked = checkbox.checked;
        const customerCode = checkbox.dataset.customerCode;
        const customerName = checkbox.dataset.customerName;
        
        if (checked) {
            // If this is the first selection, set the customer
            if (!selectedCustomerCode) {
                selectedCustomerCode = customerCode;
                selectedCustomerName = customerName;
            } else if (selectedCustomerCode !== customerCode) {
                // If trying to select from different customer, show warning
                alert('You can only select Sales Orders from the same customer. Current customer: ' + selectedCustomerName);
                checkbox.checked = false;
                return;
            }
        }
        
        // Update all checkboxes - disable those from different customers
        const allCheckboxes = document.querySelectorAll('.so-checkbox-item input[type="checkbox"]');
        let anyChecked = false;
        selectedData = [];
        
        allCheckboxes.forEach(cb => {
            if (cb.checked) {
                anyChecked = true;
                selectedCustomerCode = cb.dataset.customerCode;
                selectedCustomerName = cb.dataset.customerName;
                selectedData.push({
                    sales_order_no: cb.value,
                    customer_code: cb.dataset.customerCode,
                    customer_name: cb.dataset.customerName,
                    truck_code: cb.dataset.truck || '',
                    plate_number: cb.dataset.plate || '',
                    brand: cb.dataset.brand || '',
                    model: cb.dataset.model || '',
                    unit_price: parseFloat(cb.dataset.unitPrice) || 0,
                    discount_percent: parseFloat(cb.dataset.discountPercent) || 0,
                    vat_percent: parseFloat(cb.dataset.vatPercent) || 12,
                    destination_from: cb.dataset.destinationFrom || '',
                    destination_to: cb.dataset.destinationTo || '',
                    order_date: cb.dataset.orderDate || '',
                    delivery_date: cb.dataset.deliveryDate || '',
                    delivery_address: cb.dataset.deliveryAddress || '',
                    payment_terms: cb.dataset.paymentTerms || 'Net 30'
                });
            }
        });
        
        if (anyChecked) {
            allCheckboxes.forEach(cb => {
                if (cb.dataset.customerCode !== selectedCustomerCode && !cb.checked) {
                    cb.disabled = true;
                    cb.closest('.so-checkbox-item').style.opacity = '0.5';
                } else {
                    cb.disabled = false;
                    cb.closest('.so-checkbox-item').style.opacity = '1';
                }
            });
        } else {
            allCheckboxes.forEach(cb => {
                cb.disabled = false;
                cb.closest('.so-checkbox-item').style.opacity = '1';
            });
            selectedCustomerCode = '';
            selectedCustomerName = '';
            selectedData = [];
        }
        
        updateSelectedSummary();
        loadSelectedDetails();
        renderLineItems();
    }

    function renderLineItems() {
        const tbody = document.getElementById('items-body');
        const noItemsRow = document.getElementById('no-items-row');
        const footer = document.getElementById('items-footer');
        
        // Clear existing items except the "no items" row
        const existingRows = tbody.querySelectorAll('tr:not(#no-items-row)');
        existingRows.forEach(row => row.remove());
        
        if (selectedData.length === 0) {
            noItemsRow.style.display = '';
            footer.style.display = 'none';
            return;
        }
        
        noItemsRow.style.display = 'none';
        footer.style.display = '';
        
        selectedData.forEach((data, index) => {
            const row = document.createElement('tr');
            row.dataset.unitPrice = data.unit_price;
            row.dataset.discountPercent = data.discount_percent;
            row.dataset.vatPercent = data.vat_percent;
            
            const from = (data.destination_from || '').substring(0, 15);
            const to = (data.destination_to || '').substring(0, 15);
            
            row.innerHTML = `
                <td><strong>${data.sales_order_no}</strong></td>
                <td>${data.truck_code}</td>
                <td style="font-size:11px;">${from} → ${to}</td>
                <td style="text-align:center;">1</td>
                <td style="text-align:right;">${formatPHP(data.unit_price)}</td>
                <td style="text-align:right;font-weight:600;">${formatPHP(data.unit_price)}</td>
            `;
            tbody.appendChild(row);
        });
        
        // Update line items preview
        const previewBody = document.getElementById('lineItemsBody');
        previewBody.innerHTML = '';
        let grandTotal = 0;
        
        selectedData.forEach(data => {
            const tr = document.createElement('tr');
            const from = (data.destination_from || '').substring(0, 12);
            const to = (data.destination_to || '').substring(0, 12);
            tr.innerHTML = `
                <td>${data.sales_order_no}</td>
                <td>${data.truck_code}</td>
                <td style="font-size:10px;">${from} → ${to}</td>
                <td style="text-align:right;">${formatPHP(data.unit_price)}</td>
            `;
            previewBody.appendChild(tr);
            grandTotal += data.unit_price;
        });
        
        document.getElementById('previewGrandTotal').textContent = formatPHP(grandTotal);
        document.getElementById('lineItemsPreview').style.display = selectedData.length > 0 ? '' : 'none';
        
        calculateTotals();
    }

    function applyFilter() {
        const filter = document.getElementById('customer_filter').value;
        if (filter === '') {
            alert('Please select a customer first.');
            return;
        }
        window.location.href = 'service_invoice.php?filter_customer=' + encodeURIComponent(filter);
    }

    function clearFilter() {
        window.location.href = 'service_invoice.php';
    }

    function selectAll() {
        const visibleItems = document.querySelectorAll('.so-checkbox-item');
        visibleItems.forEach(item => {
            const cb = item.querySelector('input[type="checkbox"]');
            if (cb && !cb.disabled) {
                cb.checked = true;
                handleCheckboxChange(cb);
            }
        });
    }

    function deselectAll() {
        const allCheckboxes = document.querySelectorAll('.so-checkbox-item input[type="checkbox"]');
        allCheckboxes.forEach(cb => {
            cb.checked = false;
            cb.disabled = false;
            cb.closest('.so-checkbox-item').style.opacity = '1';
        });
        selectedCustomerCode = '';
        selectedCustomerName = '';
        selectedData = [];
        updateSelectedSummary();
        clearDetails();
        renderLineItems();
        document.getElementById('selected_total_display').textContent = '₱0.00';
    }

    function updateSelectedSummary() {
        const count = selectedData.length;
        document.getElementById('selected_count').textContent = count;
        
        const summary = document.getElementById('selectedSummary');
        const list = document.getElementById('summaryList');
        
        if (count === 0) {
            summary.classList.remove('active');
            return;
        }
        
        summary.classList.add('active');
        let html = '';
        let total = 0;
        selectedData.forEach(data => {
            total += data.unit_price;
            html += `<div class="summary-item">
                <span class="so-code">${data.sales_order_no}</span>
                <span style="color:#64748b;">${data.truck_code}</span>
                <span style="color:#64748b;font-size:11px;">${data.brand} ${data.model}</span>
                <span class="so-amount">${formatPHP(data.unit_price)}</span>
            </div>`;
        });
        list.innerHTML = html;
        document.getElementById('selected_total_display').textContent = formatPHP(total);
    }

    function loadSelectedDetails() {
        if (selectedData.length === 0) {
            clearDetails();
            return;
        }
        
        // Get customer info from first selected
        const first = selectedData[0];
        
        document.getElementById('customer_code_display').value = first.customer_code;
        document.getElementById('customer_name_display').value = first.customer_name;
        document.getElementById('customer_code_input').value = first.customer_code;
        document.getElementById('customer_name_input').value = first.customer_name;
        
        // Set delivery address from first selected SO if available
        if (first.delivery_address) {
            document.getElementById('delivery_address').value = first.delivery_address;
        }
        
        // Get payment terms from first selected
        const paymentTerms = first.payment_terms || 'Net 30';
        document.getElementById('payment_terms').value = paymentTerms;
        document.getElementById('payment_terms_value').value = paymentTerms;
        
        // Update hidden field with selected data
        document.getElementById('selected_so_data').value = JSON.stringify(selectedData);
        
        calculateDueDateFromTerms(paymentTerms);
    }

    function clearDetails() {
        document.getElementById('customer_code_display').value = '';
        document.getElementById('customer_name_display').value = '';
        document.getElementById('customer_code_input').value = '';
        document.getElementById('customer_name_input').value = '';
        document.getElementById('payment_terms').value = '';
        document.getElementById('payment_terms_value').value = '';
        document.getElementById('selected_so_data').value = '';
        document.getElementById('delivery_address').value = '';
        document.getElementById('lineItemsPreview').style.display = 'none';
    }

    function calculateDueDateFromTerms(terms) {
        const invoiceDate = new Date(document.getElementById('invoice_date').value);
        let dueDate = new Date(invoiceDate);
        
        if (terms === 'Due on Receipt') {
            dueDate = invoiceDate;
        } else if (terms === 'Net 30') {
            dueDate.setDate(invoiceDate.getDate() + 30);
        } else if (terms === 'Net 60') {
            dueDate.setDate(invoiceDate.getDate() + 60);
        } else if (terms === 'Net 90') {
            dueDate.setDate(invoiceDate.getDate() + 90);
        } else {
            const match = terms.match(/Net\s+(\d+)/i);
            if (match && match[1]) {
                const days = parseInt(match[1]);
                dueDate.setDate(invoiceDate.getDate() + days);
            } else {
                dueDate.setDate(invoiceDate.getDate() + 30);
            }
        }
        
        document.getElementById('due_date').value = dueDate.toISOString().split('T')[0];
        document.getElementById('displayDueDate').textContent = document.getElementById('due_date').value;
    }

    function validateForm() {
        if (selectedData.length === 0) {
            alert('Please select at least one Sales Order.');
            return false;
        }
        
        // Check if all selected have same customer
        let customerCode = null;
        let valid = true;
        selectedData.forEach(data => {
            if (!customerCode) customerCode = data.customer_code;
            else if (customerCode !== data.customer_code) valid = false;
        });
        
        if (!valid) {
            alert('All selected Sales Orders must have the same customer.');
            return false;
        }
        
        document.getElementById('loadingOverlay').style.display = 'flex';
        return true;
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        calculateDueDate();
        renderLineItems();
    });

    document.getElementById('invoice_date').addEventListener('change', calculateDueDate);
</script>
</body>
</html>