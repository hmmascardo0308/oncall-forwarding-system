<?php
// service_invoice_edit.php
session_start();
// Set timezone to match your location
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ .  '/../config/access_control.php'; // Include centralized access control

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name  = $_SESSION['full_name'] ?? $username;
$user_roles = array_map('trim', explode(',', $user_type));
$is_admin   = in_array('admin', $user_roles);

// Use centralized access control
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header("Location: service_invoice.php");
    exit;
}

// Fetch existing data
$stmt = $conn->prepare("SELECT * FROM service_invoice WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    die("Invoice not found.");
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_invoice'])) {
    // Sanitize inputs
    $payment_status = $_POST['payment_status'] ?? 'Unpaid';
    $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
    $payment_terms  = $_POST['payment_terms'] ?? '';
    $paid_at_val    = !empty($_POST['paid_at']) ? $_POST['paid_at'] : null;
    $notes          = $_POST['notes'] ?? '';
    
    // Basic details
    $customer_name    = $_POST['customer_name'] ?? '';
    $delivery_address = $_POST['delivery_address'] ?? '';
    $invoice_date     = $_POST['invoice_date'] ?? null;
    $due_date         = $_POST['due_date'] ?? null;

    // Line items / Financials
    $quantity         = floatval($_POST['quantity'] ?? 0);
    $unit_price       = floatval($_POST['unit_price'] ?? 0);
    $discount_percent = floatval($_POST['discount_percent'] ?? 0);
    $vat_percent      = floatval($_POST['vat_percent'] ?? 12);
    
    // Recalculate amounts to ensure consistency
    $amount           = $quantity * $unit_price;
    $discount_amount  = $amount * ($discount_percent / 100);
    $net_amount       = $amount - $discount_amount;
    $vat_amount       = $net_amount * ($vat_percent / 100);
    $total_amount     = $net_amount + $vat_amount;
    
    $updated_at = date('Y-m-d H:i:s');
    
    // Prepare SQL
    $update_sql = "UPDATE service_invoice SET 
        customer_name = ?, delivery_address = ?, invoice_date = ?, due_date = ?,
        quantity = ?, unit_price = ?, discount_percent = ?, vat_percent = ?,
        amount = ?, discount_amount = ?, vat_amount = ?, total_amount = ?,
        payment_status = ?, payment_method = ?, payment_terms = ?, paid_at = ?,
        notes = ?, updated_by = ?, updated_at = ?
        WHERE id = ?";
        
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ssssddddddddsssssssi", 
        $customer_name, $delivery_address, $invoice_date, $due_date,
        $quantity, $unit_price, $discount_percent, $vat_percent,
        $amount, $discount_amount, $vat_amount, $total_amount,
        $payment_status, $payment_method, $payment_terms, $paid_at_val,
        $notes, $username, $updated_at, $id
    );
    
    if ($stmt->execute()) {
        $_SESSION['flash_message'] = [
            'type' => 'success', 
            'text' => "Invoice #{$invoice['invoice_no']} updated successfully."
        ];
        header("Location: service_invoice_list.php?id=$id");
        exit;
    } else {
        $error = "Error updating invoice: " . $conn->error;
    }
}

// Role display - now using centralized function
$role_display_name = getRoleDisplayName($user_roles);

// Define allowed pages for sidebar - now using centralized $allowed_pages from access_control.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice #<?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="stylesheet" href="css/service_invoice.css?v=<?= time(); ?>">
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
   
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

<aside class="sidebar">
    <div class="sidebar-brand">ONCALL PANEL</div>
    <nav class="nav-group">
        <div class="nav-label">Menu</div>
        
        <!-- Home - Accessible to everyone -->
        <a href="home.php" onclick="return checkAccess('home.php')">
            <i data-lucide="layout-dashboard"></i> Home
        </a>
        
        <!-- Manage Users - Admin only -->
        <a href="all_users.php" onclick="return checkAccess('all_users.php')"
           class="<?php echo (!in_array('admin', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="users"></i> Manage Users
            <?php if (!in_array('admin', $user_roles)): ?>
                <span class="role-badge">(Admin only)</span>
            <?php endif; ?>
        </a>
        
        <!-- Suppliers Masterlist -->
        <a href="suppliers.php" onclick="return checkAccess('suppliers.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="brick-wall-shield"></i> Suppliers Masterlist
            <?php if (!in_array('admin', $user_roles) && !in_array('user', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Items Masterlist -->
        <a href="items.php" onclick="return checkAccess('items.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="list"></i> Items Masterlist
            <?php if (!in_array('admin', $user_roles) && !in_array('user', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Customer Masterlist -->
        <a href="customer.php" onclick="return checkAccess('customer.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="user-star"></i> Customer Masterlist
            <?php if (!in_array('admin', $user_roles) && !in_array('user', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Truck Masterlist -->
        <a href="truck_masterlist.php" onclick="return checkAccess('truck_masterlist.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="truck"></i> Truck Masterlist
            <?php if (!in_array('admin', $user_roles) && !in_array('user', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Customer Pricing -->
        <a href="customer_pricing.php" onclick="return checkAccess('customer_pricing.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('user', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="settings"></i> Customer Pricing
            <?php if (!in_array('admin', $user_roles) && !in_array('user', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Purchase Order -->
        <a href="purchase_order.php" onclick="return checkAccess('purchase_order.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('purchase_order_maker', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('purchase_order_maker', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="shopping-cart"></i> Purchase Order
            <?php if (!in_array('admin', $user_roles) && !in_array('purchase_order_maker', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Sales Order -->
        <a href="sales_order.php" onclick="return checkAccess('sales_order.php')"
           class="<?php echo (!in_array('admin', $user_roles) && !in_array('sales_order_maker', $user_roles)) ? 'restricted-item' : ''; ?>"
           style="<?php echo (!in_array('admin', $user_roles) && !in_array('sales_order_maker', $user_roles)) ? 'opacity:0.6;' : ''; ?>">
            <i data-lucide="file-plus"></i> Sales Order
            <?php if (!in_array('admin', $user_roles) && !in_array('sales_order_maker', $user_roles)): ?>
                <span class="role-badge">(Restricted)</span>
            <?php endif; ?>
        </a>
        
        <!-- Service Invoice - Current page -->
        <a href="service_invoice.php" class="active" onclick="return checkAccess('service_invoice.php')">
            <i data-lucide="philippine-peso"></i> Service Invoice
        </a>
    </nav>
    <div class="logout-btn">
        <a href="../logout.php" style="color:#f87171;"><i data-lucide="log-out"></i> Logout</a>
    </div>
</aside>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <a href="service_invoice.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;">Service Invoice</a> / Edit
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
            <span style="margin-left:10px;color:var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if (isset($error)): ?>
            <div style="background:#fee2e2;color:#991b1b;padding:12px;border-radius:6px;margin-bottom:20px;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h1>Edit Invoice <span style="color:var(--text-muted);"><?php echo htmlspecialchars($invoice['invoice_no']); ?></span></h1>
            <a href="service_invoice_list.php?id=<?php echo $id; ?>" class="btn-secondary">
                <i data-lucide="arrow-left" style="width:16px;"></i> Back to View
            </a>
        </div>

        <form method="POST">
            <input type="hidden" name="update_invoice" value="1">

            <!-- Payment Status & Date -->
            <div class="form-card" style="border-left: 4px solid var(--accent-blue);">
                <div class="form-section-title">
                    <i data-lucide="credit-card" style="width:18px;"></i> Payment Status Update
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Payment Status</label>
                        <select name="payment_status" id="payment_status" style="font-weight:600;">
                            <option value="Unpaid" <?php echo $invoice['payment_status'] == 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                            <option value="Paid" <?php echo $invoice['payment_status'] == 'Paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="Partial" <?php echo $invoice['payment_status'] == 'Partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="Cancelled" <?php echo $invoice['payment_status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Paid At (Date)</label>
                        <input type="date" name="paid_at" id="paid_at" value="<?php echo $invoice['paid_at']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method">
                            <option value="Bank Transfer" <?php echo $invoice['payment_method'] == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Cash" <?php echo $invoice['payment_method'] == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Check" <?php echo $invoice['payment_method'] == 'Check' ? 'selected' : ''; ?>>Check</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Payment Terms</label>
                        <input type="text" name="payment_terms" value="<?php echo htmlspecialchars($invoice['payment_terms']); ?>">
                    </div>
                </div>
            </div>

            <!-- Basic Invoice Info -->
            <div class="form-card">
                <div class="form-section-title"><i data-lucide="file-text" style="width:18px;"></i> Invoice Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Invoice Number</label>
                        <input type="text" value="<?php echo htmlspecialchars($invoice['invoice_no']); ?>" readonly class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Sales Order Ref</label>
                        <input type="text" value="<?php echo htmlspecialchars($invoice['sales_order_no']); ?>" readonly class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label>Invoice Date</label>
                        <input type="date" name="invoice_date" value="<?php echo $invoice['invoice_date']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" value="<?php echo $invoice['due_date']; ?>" required>
                    </div>
                </div>
            </div>

            <!-- Customer Info -->
            <div class="form-card">
                <div class="form-section-title"><i data-lucide="user" style="width:18px;"></i> Customer Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Customer Name</label>
                        <input type="text" name="customer_name" value="<?php echo htmlspecialchars($invoice['customer_name']); ?>">
                    </div>
                    <div class="form-group" style="grid-column: span 3;">
                        <label>Delivery Address</label>
                        <input type="text" name="delivery_address" value="<?php echo htmlspecialchars($invoice['delivery_address']); ?>">
                    </div>
                </div>
            </div>

            <!-- Truck Info (Read-only mostly) -->
            <div class="form-card">
                <div class="form-section-title"><i data-lucide="truck" style="width:18px;"></i> Truck & Trip</div>
                <div class="form-grid">
                    <div class="form-group"><label>Truck Code</label><input type="text" value="<?php echo $invoice['truck_code']; ?>" readonly class="readonly-field"></div>
                    <div class="form-group"><label>Plate Number</label><input type="text" value="<?php echo $invoice['plate_number']; ?>" readonly class="readonly-field"></div>
                    <div class="form-group"><label>Origin</label><input type="text" value="<?php echo $invoice['destination_from']; ?>" readonly class="readonly-field"></div>
                    <div class="form-group"><label>Destination</label><input type="text" value="<?php echo $invoice['destination_to']; ?>" readonly class="readonly-field"></div>
                </div>
            </div>

            <!-- Financials -->
            <div class="form-card">
                <div class="form-section-title"><i data-lucide="calculator" style="width:18px;"></i> Financials</div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Discount (%)</th>
                                <th>VAT (%)</th>
                                <th style="text-align:right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="number" name="quantity" id="quantity" value="<?php echo $invoice['quantity']; ?>" class="qty-input" oninput="calculateTotals()"></td>
                                <td><input type="number" name="unit_price" id="unit_price" step="0.01" value="<?php echo $invoice['unit_price']; ?>" class="price-input" oninput="calculateTotals()"></td>
                                <td><input type="number" name="discount_percent" id="discount_percent" value="<?php echo $invoice['discount_percent']; ?>" class="disc-input" oninput="calculateTotals()"></td>
                                <td><input type="number" name="vat_percent" id="vat_percent" value="<?php echo $invoice['vat_percent']; ?>" class="vat-input" oninput="calculateTotals()"></td>
                                <td style="text-align:right; font-weight:bold;">
                                    <span id="row_total_display">0.00</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div style="display:flex; justify-content:flex-end; margin-top:16px;">
                    <div class="totals-box" style="width:300px;">
                        <div class="totals-row"><span>Subtotal</span> <span id="subtotal_display">0.00</span></div>
                        <div class="totals-row"><span>Discount Amt</span> <span id="discount_display">0.00</span></div>
                        <div class="totals-row"><span>VAT Amt</span> <span id="vat_display">0.00</span></div>
                        <div class="totals-row total-final"><span>Total Due</span> <span id="total_display">0.00</span></div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div class="form-card">
                <div class="form-section-title"><i data-lucide="message-square" style="width:18px;"></i> Notes</div>
                <textarea name="notes" style="width:100%; min-height:80px; padding:10px; border:1px solid #cbd5e1; border-radius:6px;"><?php echo htmlspecialchars($invoice['notes']); ?></textarea>
            </div>

            <div class="actions-row" style="margin-top:24px; display:flex; gap:12px; justify-content:flex-end;">
                <a href="service_invoice_list.php?id=<?php echo $id; ?>" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary" style="padding:10px 24px;">
                    <i data-lucide="save" style="width:16px;"></i> Update Invoice
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

    function formatNumber(num) {
        return num.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function calculateTotals() {
        const qty = parseFloat(document.getElementById('quantity').value) || 0;
        const price = parseFloat(document.getElementById('unit_price').value) || 0;
        const discPct = parseFloat(document.getElementById('discount_percent').value) || 0;
        const vatPct = parseFloat(document.getElementById('vat_percent').value) || 0;

        const grossAmount = qty * price;
        const discountAmount = grossAmount * (discPct / 100);
        const netAmount = grossAmount - discountAmount;
        const vatAmount = netAmount * (vatPct / 100);
        const totalAmount = netAmount + vatAmount;

        document.getElementById('row_total_display').innerText = formatNumber(totalAmount);
        document.getElementById('subtotal_display').innerText = formatNumber(grossAmount);
        document.getElementById('discount_display').innerText = formatNumber(discountAmount);
        document.getElementById('vat_display').innerText = formatNumber(vatAmount);
        document.getElementById('total_display').innerText = formatNumber(totalAmount);
    }

    // Auto-set Paid At date when status changes to Paid
    document.getElementById('payment_status').addEventListener('change', function() {
        const paidAt = document.getElementById('paid_at');
        if (this.value === 'Paid' && !paidAt.value) {
            const today = new Date().toISOString().split('T')[0];
            paidAt.value = today;
        }
    });

    // Initial calculation
    calculateTotals();
</script>
</body>
</html>