<?php

// service_invoice_list.php
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
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Use centralized access control
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages, 'home.php');

// Role display name - now using centralized function
$role_display_name = getRoleDisplayName($user_roles);

// Function to get status display and CSS class
function getStatusInfo($status) {
    if (empty($status)) {
        $status = 'Unpaid';
    }
    
    $status_lower = strtolower($status);
    
    $class_map = [
        'unpaid' => 'status-unpaid',
        'paid' => 'status-paid',
        'partial' => 'status-partial',
        'created' => 'status-created',
        'cancelled' => 'status-cancelled',
        'overdue' => 'status-overdue',
        'void' => 'status-void',
        'billed' => 'status-billed'
    ];
    
    $css_class = isset($class_map[$status_lower]) ? $class_map[$status_lower] : 'status-default';
    
    return [
        'display' => ucfirst($status),
        'css_class' => $css_class,
        'raw' => $status
    ];
}

// Function to get Manila time
function getManilaTime() {
    date_default_timezone_set('Asia/Manila');
    return date('Y-m-d H:i:s');
}

// Function to check if invoice is overdue
function isInvoiceOverdue($due_date, $payment_status) {
    if (empty($due_date)) {
        return false;
    }
    $current_date = date('Y-m-d');
    $status_lower = strtolower($payment_status);
    if ($status_lower === 'paid' || $status_lower === 'cancelled' || $status_lower === 'void' || $status_lower === 'partial' || $status_lower === 'billed') {
        return false;
    }
    return $current_date > $due_date;
}

// Function to get payment history for an invoice
function getPaymentHistory($conn, $invoice_no) {
    $history = [];
    $sql = "SELECT * FROM payment_history WHERE invoice_no = '$invoice_no' ORDER BY payment_date DESC";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
    }
    return $history;
}

// Function to calculate total paid for an invoice
function getTotalPaid($conn, $invoice_no) {
    $sql = "SELECT SUM(amount_paid) as total FROM payment_history WHERE invoice_no = '$invoice_no'";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return floatval($row['total'] ?? 0);
    }
    return 0;
}

// Function to check and update paid status if balance is zero
function checkAndUpdatePaidStatus($conn, $invoice_no, $username) {
    $manila_time = getManilaTime();
    
    // Get current invoice data
    $sql = "SELECT total_amount, additional_fee FROM service_invoice WHERE invoice_no = '$invoice_no'";
    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        return false;
    }
    $invoice = $result->fetch_assoc();
    $total_amount = floatval($invoice['total_amount']);
    $additional_fee = floatval($invoice['additional_fee'] ?? 0);
    $total_paid = getTotalPaid($conn, $invoice_no);
    
    // Calculate balance
    $balance = $total_amount - $total_paid + $additional_fee;
    
    // If balance is zero or less, mark as paid
    if ($balance <= 0) {
        $update_sql = "UPDATE service_invoice 
                       SET payment_status = 'Paid', 
                           paid_at = '$manila_time',
                           updated_by = '$username',
                           updated_at = '$manila_time',
                           amount_paid = '$total_paid'
                       WHERE invoice_no = '$invoice_no'";
        return $conn->query($update_sql);
    }
    
    return true;
}

// Function to upload file
function uploadAttachment($file) {
    $upload_dir = "C:/xampp/htdocs/oncall-forwarding/supporting_attachment/";
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Generate unique filename
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
    
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file type. Allowed: ' . implode(', ', $allowed_extensions)];
    }
    
    // Max file size: 10MB
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File size exceeds 10MB limit.'];
    }
    
    $new_filename = time() . '_' . uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return ['success' => true, 'filename' => $new_filename];
    } else {
        return ['success' => false, 'message' => 'Failed to upload file.'];
    }
}

// Handle AJAX requests for updating payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment') {
    header('Content-Type: application/json');
    
    $invoice_no = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status']);
    $amount_paid = isset($_POST['amount_paid']) ? floatval($_POST['amount_paid']) : 0;
    $additional_fee = isset($_POST['additional_fee']) ? floatval($_POST['additional_fee']) : 0;
    $payment_method = isset($_POST['payment_method']) ? mysqli_real_escape_string($conn, $_POST['payment_method']) : '';
    $reference_no = isset($_POST['reference_no']) ? mysqli_real_escape_string($conn, $_POST['reference_no']) : '';
    $payment_notes = isset($_POST['payment_notes']) ? mysqli_real_escape_string($conn, $_POST['payment_notes']) : '';
    $username = $_SESSION['username'] ?? 'system';
    $manila_time = getManilaTime();
    
    // Handle file upload
    $attachment_filename = '';
    if (isset($_FILES['payment_attachment']) && $_FILES['payment_attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_result = uploadAttachment($_FILES['payment_attachment']);
        if ($upload_result['success']) {
            $attachment_filename = $upload_result['filename'];
        } else {
            echo json_encode(['success' => false, 'message' => $upload_result['message']]);
            exit;
        }
    }
    
    // Get current invoice data
    $check_sql = "SELECT invoice_no, total_amount, amount_paid, additional_fee, payment_status, due_date FROM service_invoice WHERE invoice_no = '$invoice_no' LIMIT 1";
    $check_result = $conn->query($check_sql);
    
    if (!$check_result || $check_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit;
    }
    
    $invoice_data = $check_result->fetch_assoc();
    $total_amount = floatval($invoice_data['total_amount']);
    $current_amount_paid = floatval($invoice_data['amount_paid'] ?? 0);
    $current_additional_fee = floatval($invoice_data['additional_fee'] ?? 0);
    $current_status = strtolower($invoice_data['payment_status'] ?? '');
    
    // Validate amount paid
    if ($payment_status === 'paid' && $amount_paid < $total_amount) {
        echo json_encode(['success' => false, 'message' => 'Amount paid must be equal to or greater than total amount for Paid status']);
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert payment history record
        if ($payment_status === 'partial' || $payment_status === 'paid') {
            // For partial payments, make sure amount is valid
            if ($payment_status === 'partial' && ($amount_paid <= 0 || $amount_paid >= $total_amount)) {
                throw new Exception('Amount paid must be greater than 0 and less than total amount for Partial status');
            }
            
            $insert_sql = "INSERT INTO payment_history (invoice_no, payment_date, amount_paid, payment_method, reference_no, notes, supporting_attachment, created_by) 
                           VALUES ('$invoice_no', '$manila_time', '$amount_paid', '$payment_method', '$reference_no', '$payment_notes', '$attachment_filename', '$username')";
            if (!$conn->query($insert_sql)) {
                throw new Exception('Failed to insert payment history: ' . $conn->error);
            }
        }
        
        // Calculate total paid from history
        $total_paid = getTotalPaid($conn, $invoice_no);
        
        // Build update query
        $update_fields = [];
        
        // If status is partial and balance will be zero after this payment, set to paid
        $new_balance = $total_amount - $total_paid + $additional_fee;
        
        if ($payment_status === 'partial' && $new_balance <= 0) {
            // This payment makes the balance zero, so mark as paid
            $update_fields[] = "payment_status = 'Paid'";
            $update_fields[] = "paid_at = '$manila_time'";
            $update_fields[] = "amount_paid = '$total_paid'";
            $update_fields[] = "additional_fee = '0'";
            $update_fields[] = "updated_by = '$username'";
            $update_fields[] = "updated_at = '$manila_time'";
        } elseif ($payment_status === 'paid') {
            $update_fields[] = "payment_status = 'Paid'";
            $update_fields[] = "paid_at = '$manila_time'";
            $update_fields[] = "amount_paid = '$amount_paid'";
            $update_fields[] = "additional_fee = '0'";
            $update_fields[] = "updated_by = '$username'";
            $update_fields[] = "updated_at = '$manila_time'";
        } elseif ($payment_status === 'partial') {
            $update_fields[] = "payment_status = 'Partial'";
            $update_fields[] = "paid_at = NULL";
            $update_fields[] = "amount_paid = '$total_paid'";
            $update_fields[] = "updated_by = '$username'";
            $update_fields[] = "updated_at = '$manila_time'";
            if ($additional_fee > 0) {
                $update_fields[] = "additional_fee = '" . ($current_additional_fee + $additional_fee) . "'";
            }
        } elseif ($payment_status === 'unpaid') {
            $update_fields[] = "payment_status = 'Unpaid'";
            $update_fields[] = "amount_paid = '0'";
            $update_fields[] = "paid_at = NULL";
            $update_fields[] = "additional_fee = '0'";
            $update_fields[] = "updated_by = '$username'";
            $update_fields[] = "updated_at = '$manila_time'";
            // Delete payment history when reverting to unpaid
            $conn->query("DELETE FROM payment_history WHERE invoice_no = '$invoice_no'");
        } elseif ($payment_status === 'billed') {
            $update_fields[] = "payment_status = 'Billed'";
            $update_fields[] = "paid_at = NULL";
            $update_fields[] = "additional_fee = '0'";
            $update_fields[] = "amount_paid = '$current_amount_paid'";
            $update_fields[] = "updated_by = '$username'";
            $update_fields[] = "updated_at = '$manila_time'";
        } else {
            // Overdue or other statuses
            $update_fields[] = "payment_status = '$payment_status'";
            $update_fields[] = "updated_by = '$username'";
            $update_fields[] = "updated_at = '$manila_time'";
            if ($amount_paid > 0) {
                $update_fields[] = "amount_paid = '$amount_paid'";
            }
            if ($additional_fee > 0) {
                $update_fields[] = "additional_fee = '" . ($current_additional_fee + $additional_fee) . "'";
            }
        }
        
        $update_sql = "UPDATE service_invoice SET " . implode(', ', $update_fields) . " WHERE invoice_no = '$invoice_no'";
        
        if (!$conn->query($update_sql)) {
            throw new Exception('Failed to update invoice: ' . $conn->error);
        }
        
        $conn->commit();
        
        // Determine the final status to return
        $final_status = $payment_status;
        if ($payment_status === 'partial' && $new_balance <= 0) {
            $final_status = 'paid';
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Payment status updated successfully',
            'final_status' => $final_status,
            'balance' => $new_balance
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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
    
    $invoice = $invoices[0];
    
    // Get payment history
    $payment_history = getPaymentHistory($conn, $invoice_no);
    $total_paid = getTotalPaid($conn, $invoice_no);
    
    // Calculate totals from all invoice items
    $subtotal = 0;
    $total_discount = 0;
    $total_vat = 0;
    $total_charges = 0;      // NEW: aggregate special charges
    $grand_total = 0;
    
    foreach ($invoices as $item) {
        $subtotal        += floatval($item['amount']);
        $total_discount  += floatval($item['discount_amount']);
        $total_vat       += floatval($item['vat_amount']);
        $total_charges   += floatval($item['charge_amount'] ?? 0);  // NEW
        $grand_total     += floatval($item['total_amount']);
    }
    
    // Check if invoice should be marked as paid (balance zero)
    $additional_fee = floatval($invoice['additional_fee'] ?? 0);
    $balance = $grand_total - $total_paid + $additional_fee;
    
    if ($balance <= 0 && strtolower($invoice['payment_status'] ?? '') !== 'paid') {
        $update_sql = "UPDATE service_invoice 
                       SET payment_status = 'Paid', 
                           paid_at = '" . getManilaTime() . "',
                           updated_by = '{$_SESSION['username']}',
                           updated_at = '" . getManilaTime() . "',
                           amount_paid = '$total_paid'
                       WHERE invoice_no = '$invoice_no'";
        $conn->query($update_sql);
        $invoice['payment_status'] = 'Paid';
    }
    
    // Check if invoice is overdue
    if (isInvoiceOverdue($invoice['due_date'], $invoice['payment_status'] ?? '')) {
        if (strtolower($invoice['payment_status'] ?? '') !== 'overdue') {
            $update_overdue = "UPDATE service_invoice SET payment_status = 'Overdue', updated_by = '{$_SESSION['username']}', updated_at = '" . getManilaTime() . "' WHERE invoice_no = '$invoice_no'";
            $conn->query($update_overdue);
            $invoice['payment_status'] = 'Overdue';
        }
    }
} else {
    // List all invoices - check and update overdue statuses
    $list_sql = "SELECT si.invoice_no, si.payment_status, si.due_date FROM service_invoice si";
    $list_result = $conn->query($list_sql);
    if ($list_result && $list_result->num_rows > 0) {
        while ($row = $list_result->fetch_assoc()) {
            // Check if invoice should be marked as paid (balance zero)
            $total_paid = getTotalPaid($conn, $row['invoice_no']);
            $total_amount_sql = "SELECT total_amount, additional_fee FROM service_invoice WHERE invoice_no = '{$row['invoice_no']}'";
            $total_result = $conn->query($total_amount_sql);
            if ($total_result && $total_result->num_rows > 0) {
                $inv_data = $total_result->fetch_assoc();
                $balance = floatval($inv_data['total_amount']) - $total_paid + floatval($inv_data['additional_fee'] ?? 0);
                if ($balance <= 0 && strtolower($row['payment_status'] ?? '') !== 'paid') {
                    $update_paid = "UPDATE service_invoice 
                                   SET payment_status = 'Paid', 
                                       paid_at = '" . getManilaTime() . "',
                                       updated_by = '{$_SESSION['username']}',
                                       updated_at = '" . getManilaTime() . "',
                                       amount_paid = '$total_paid'
                                   WHERE invoice_no = '{$row['invoice_no']}'";
                    $conn->query($update_paid);
                }
            }
            
            if (isInvoiceOverdue($row['due_date'], $row['payment_status'] ?? '')) {
                if (strtolower($row['payment_status'] ?? '') !== 'overdue') {
                    $update_overdue = "UPDATE service_invoice SET payment_status = 'Overdue', updated_by = '{$_SESSION['username']}', updated_at = '" . getManilaTime() . "' WHERE invoice_no = '{$row['invoice_no']}'";
                    $conn->query($update_overdue);
                }
            }
        }
    }
    
    // NEW: also aggregate charge_amount per invoice in the list view
    $sql = "SELECT si.invoice_no, si.customer_code, si.customer_name, 
            COUNT(si.id) as item_count,
            SUM(si.total_amount) as total_amount,
            SUM(si.charge_amount) as total_charges,
            si.payment_status, si.payment_method, si.payment_terms,
            si.amount_paid, si.additional_fee, si.due_date,
            MAX(si.created_date) as latest_date,
            MAX(si.invoice_date) as invoice_date,
            MAX(si.due_date) as due_date,
            GROUP_CONCAT(DISTINCT si.sales_order_no) as sales_orders
            FROM service_invoice si 
            GROUP BY si.invoice_no, si.customer_code, si.customer_name, 
                     si.payment_status, si.payment_method, si.payment_terms,
                     si.amount_paid, si.additional_fee, si.due_date
            ORDER BY MAX(si.created_date) DESC";
    $result = $conn->query($sql);
    $invoices = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $invoices[] = $row;
        }
    }
}

function getDisplayStatus($payment_status, $due_date) {
    $status_lower = strtolower($payment_status ?? 'unpaid');
    
    if (in_array($status_lower, ['partial', 'paid', 'billed', 'cancelled', 'void'])) {
        return $status_lower;
    }
    
    if ($status_lower === 'unpaid' || $status_lower === 'overdue') {
        if (isInvoiceOverdue($due_date, $payment_status)) {
            return 'overdue';
        }
        return 'unpaid';
    }
    
    return $status_lower;
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
    <link rel="stylesheet" href="css/service_invoice.css?v=<?= time(); ?>">
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
                    / View #<?php echo htmlspecialchars($invoice_no); ?>
                <?php else: ?>
                    / List
                <?php endif; ?>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($role_display_name); ?></span>
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
            <?php 
            $display_status = getDisplayStatus($invoice['payment_status'] ?? '', $invoice['due_date'] ?? '');
            $status_info = getStatusInfo($display_status);
            $amount_paid = floatval($total_paid ?? 0);
            $additional_fee = floatval($invoice['additional_fee'] ?? 0);
            $balance = $grand_total - $amount_paid + $additional_fee;
            ?>
            <div class="page-header">
                <h1>
                    <span class="icon-wrap"><i data-lucide="philippine-peso" style="width:18px;height:18px;"></i></span>
                    Service Invoice Details
                </h1>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span class="inv-tag"><?php echo htmlspecialchars($invoice_no); ?></span>
                    <span class="<?php echo $status_info['css_class']; ?>">
                        <?php echo $status_info['display']; ?>
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
                        <?php if (!empty($invoice['paid_at'])): ?>
                        <div class="meta-badge">
                            <i data-lucide="check-circle" style="width:16px;height:16px;color:var(--accent-green);"></i>
                            <span>Paid: <?php echo htmlspecialchars($invoice['formatted_paid_at']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">STATUS</div>
                    <div>
                        <span class="<?php echo $status_info['css_class']; ?>">
                            <?php echo $status_info['display']; ?>
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

            <!-- Line Items -->
            <div class="form-card" style="margin-bottom:24px;">
                <div class="form-card-title"><i data-lucide="list" style="width:16px;height:16px;"></i> Invoice Line Items</div>
                <div class="table-wrapper" style="overflow-x:auto;">
                    <table style="min-width:1350px;">
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
                                <th style="text-align:right;min-width:120px;">Special Charges</th>
                                <th style="text-align:right;min-width:120px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $subtotal = 0;
                            $total_discount = 0;
                            $total_vat = 0;
                            $total_charges = 0;
                            $grand_total = 0;
                            
                            foreach ($invoices as $item): 
                                $subtotal        += floatval($item['amount']);
                                $total_discount  += floatval($item['discount_amount']);
                                $total_vat       += floatval($item['vat_amount']);
                                $total_charges   += floatval($item['charge_amount'] ?? 0);
                                $grand_total     += floatval($item['total_amount']);
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
                                    <!-- NEW: Special Charges column -->
                                    <td style="text-align:right;color:#b45309;font-weight:600;">
                                        ₱<?php echo number_format(floatval($item['charge_amount'] ?? 0), 2); ?>
                                    </td>
                                    <td style="text-align:right;font-weight:600;">₱<?php echo number_format($item['total_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="10" style="text-align:right;font-weight:600;">Subtotal:</td>
                                <td style="text-align:right;font-weight:600;">₱<?php echo number_format($subtotal, 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="10" style="text-align:right;font-weight:600;">Total Discount:</td>
                                <td style="text-align:right;font-weight:600;color:#dc2626;">−₱<?php echo number_format($total_discount, 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="10" style="text-align:right;font-weight:600;">Total VAT:</td>
                                <td style="text-align:right;font-weight:600;">₱<?php echo number_format($total_vat, 2); ?></td>
                            </tr>
                            <?php if ($total_charges > 0): ?>
                            <tr>
                                <td colspan="10" style="text-align:right;font-weight:600;color:#b45309;">Total Special Charges:</td>
                                <td style="text-align:right;font-weight:600;color:#b45309;">₱<?php echo number_format($total_charges, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr style="border-top:2px solid #16a34a;">
                                <td colspan="10" style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">GRAND TOTAL:</td>
                                <td style="text-align:right;font-weight:700;font-size:16px;color:#16a34a;">₱<?php echo number_format($grand_total, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Payment & Totals -->
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:16px;">
                    <!-- Payment Information -->
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
                                    <span class="<?php echo $status_info['css_class']; ?>">
                                        <?php echo $status_info['display']; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Total Amount</div>
                                <div class="info-value"><strong>₱<?php echo number_format($grand_total, 2); ?></strong></div>
                            </div>
                            <?php if ($amount_paid > 0): ?>
                            <div class="info-row">
                                <div class="info-label">Total Paid</div>
                                <div class="info-value"><strong>₱<?php echo number_format($amount_paid, 2); ?></strong></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($additional_fee > 0): ?>
                            <div class="info-row">
                                <div class="info-label">Additional Fee</div>
                                <div class="info-value" style="color:#dc2626;"><strong>₱<?php echo number_format($additional_fee, 2); ?></strong></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($balance > 0 && strtolower($display_status) !== 'paid' && strtolower($display_status) !== 'billed'): ?>
                            <div class="info-row">
                                <div class="info-label">Balance Due</div>
                                <div class="info-value" style="color:#dc2626;"><strong>₱<?php echo number_format($balance, 2); ?></strong></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($invoice['paid_at'])): ?>
                            <div class="info-row">
                                <div class="info-label">Paid At</div>
                                <div class="info-value"><?php echo htmlspecialchars($invoice['formatted_paid_at']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Payment History -->
                    <?php if (!empty($payment_history)): ?>
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="clock" style="width:16px;height:16px;"></i> Payment History</div>
                        <div class="view-section" style="padding:8px 0;">
                            <table class="payment-history-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount Paid</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Attachment</th>
                                        <th>Notes</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment_history as $payment): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y h:i A', strtotime($payment['payment_date'])); ?></td>
                                        <td class="text-right">₱<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_method'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['reference_no'] ?: '—'); ?></td>
                                        <td>
                                            <?php if (!empty($payment['supporting_attachment'])): ?>
                                                <a href="../supporting_attachment/<?php echo htmlspecialchars($payment['supporting_attachment']); ?>" target="_blank" class="payment-attachment-link">
                                                    <i data-lucide="paperclip" style="width:14px;height:14px;display:inline-block;"></i> View
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['notes'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['created_by'] ?: '—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="1" style="text-align:right;">Total Paid:</th>
                                        <th class="text-right payment-history-total">₱<?php echo number_format($amount_paid, 2); ?></th>
                                        <th colspan="5"></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Payment Status Update Form -->
                    <?php if (strtolower($display_status) !== 'paid' && 
                              strtolower($display_status) !== 'cancelled' && 
                              strtolower($display_status) !== 'void'): ?>
                    <div class="form-card" style="margin-bottom:0;">
                        <div class="form-card-title"><i data-lucide="edit-3" style="width:16px;height:16px;"></i> Update Payment Status</div>
                        <div class="payment-status-form">
                            <form id="paymentStatusForm" enctype="multipart/form-data">
                                <input type="hidden" name="invoice_no" value="<?php echo htmlspecialchars($invoice_no); ?>">
                                <input type="hidden" name="action" value="update_payment">
                                
                                <div class="form-group-horizontal">
                                    <div class="field-item">
                                        <label for="payment_status">Status:</label>
                                        <select name="payment_status" id="payment_status" onchange="togglePaymentFields()">
                                            <option value="unpaid" <?php echo $display_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                            <option value="billed" <?php echo $display_status === 'billed' ? 'selected' : ''; ?>>Billed</option>
                                            <option value="partial" <?php echo $display_status === 'partial' ? 'selected' : ''; ?>>Partially Paid</option>
                                            <option value="paid" <?php echo $display_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                            <option value="overdue" <?php echo $display_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        </select>
                                    </div>
                                    
                                    <div id="amount_paid_field" class="field-item hidden-field">
                                        <label for="amount_paid">Amount Paid:</label>
                                        <div class="input-group">
                                            <span class="currency-symbol">₱</span>
                                            <input type="number" name="amount_paid" id="amount_paid" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo $amount_paid > 0 ? number_format($amount_paid, 2) : ''; ?>"
                                                   placeholder="Enter amount">
                                            <span class="max-hint">Max: ₱<?php echo number_format($grand_total, 2); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div id="payment_method_field" class="field-item hidden-field">
                                        <label for="payment_method">Method:</label>
                                        <select name="payment_method" id="payment_method" style="width:130px;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;height:36px;">
                                            <option value="">Select</option>
                                            <option value="Cash">Cash</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="Check">Check</option>
                                            <option value="Credit Card">Credit Card</option>
                                            <option value="GCash">GCash</option>
                                            <option value="PayMaya">PayMaya</option>
                                        </select>
                                    </div>
                                    
                                    <div id="reference_field" class="field-item hidden-field">
                                        <label for="reference_no">Reference:</label>
                                        <input type="text" name="reference_no" id="reference_no" placeholder="Ref #" style="width:130px;">
                                    </div>
                                    
                                    <div id="attachment_field" class="field-item hidden-field">
                                        <label for="payment_attachment">Attachment:</label>
                                        <input type="file" name="payment_attachment" id="payment_attachment" 
                                               accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx" 
                                               style="width:150px;padding:4px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                                    </div>
                                    
                                    <div id="additional_fee_field" class="field-item hidden-field">
                                        <label for="additional_fee">Late Fee:</label>
                                        <div class="input-group">
                                            <span class="currency-symbol">₱</span>
                                            <input type="number" name="additional_fee" id="additional_fee" 
                                                   step="0.01" min="0" 
                                                   value="<?php echo $additional_fee > 0 ? number_format($additional_fee, 2) : ''; ?>"
                                                   placeholder="Enter amount">
                                        </div>
                                    </div>
                                    
                                    <div class="field-item field-item-button">
                                        <button type="submit" class="btn-update" id="updateBtn">
                                            <i data-lucide="save" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"></i>
                                            Update
                                        </button>
                                    </div>
                                    
                                    <div class="field-item field-item-info">
                                        <span style="font-size:12px;color:var(--text-muted);white-space:nowrap;">
                                            Last updated by: <?php echo htmlspecialchars($invoice['updated_by'] ?? '—'); ?>
                                        </span>
                                    </div>
                                </div>
                                <div id="payment_notes_field" class="field-item hidden-field" style="margin-top:8px;display:flex;align-items:center;gap:8px;">
                                    <label for="payment_notes" style="font-weight:500;font-size:13px;color:var(--text-main);white-space:nowrap;">Notes:</label>
                                    <input type="text" name="payment_notes" id="payment_notes" placeholder="Payment notes..." style="flex:1;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;height:36px;">
                                </div>
                                <div id="balance_warning" style="display:none;margin-top:8px;padding:8px 12px;background:#fef9c3;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#854d0e;">
                                    <i data-lucide="info" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"></i>
                                    This payment will make the balance zero. The status will automatically change to <strong>Paid</strong>.
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
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
                        <?php if ($total_charges > 0): ?>
                        <div class="totals-row" style="background:#fffbeb;border-radius:6px;padding-left:8px;padding-right:8px;">
                            <span style="color:#b45309;">Special Charges</span>
                            <span style="color:#b45309;font-weight:700;">₱<?php echo number_format($total_charges, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="totals-row total-final">
                            <span>Total Amount</span>
                            <span class="amount-due">₱<?php echo number_format($grand_total, 2); ?></span>
                        </div>
                        <?php if ($amount_paid > 0): ?>
                        <div class="totals-row" style="border-top:1px solid #e2e8f0;padding-top:8px;color:#16a34a;">
                            <span>Total Paid</span>
                            <span>₱<?php echo number_format($amount_paid, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($additional_fee > 0): ?>
                        <div class="totals-row" style="color:#dc2626;">
                            <span>Additional Fee</span>
                            <span>₱<?php echo number_format($additional_fee, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($balance > 0 && strtolower($display_status) !== 'paid' && strtolower($display_status) !== 'billed'): ?>
                        <div class="totals-row" style="border-top:2px solid #dc2626;padding-top:8px;color:#dc2626;font-weight:700;">
                            <span>Balance Due</span>
                            <span>₱<?php echo number_format($balance, 2); ?></span>
                        </div>
                        <?php endif; ?>
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
                <a href="service_invoice_print.php?invoice_no=<?php echo urlencode($invoice_no); ?>" target="_blank" class="btn-secondary">
                    <i data-lucide="printer" style="width:15px;height:15px;"></i> Print Invoice
                </a>
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
                        <option value="billed">Billed</option>
                        <option value="partial">Partially Paid</option>
                        <option value="paid">Paid</option>
                        <option value="created">Created</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="overdue">Overdue</option>
                        <option value="void">Void</option>
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
                    <?php foreach ($invoices as $inv): 
                        $display_status = getDisplayStatus($inv['payment_status'] ?? '', $inv['due_date'] ?? '');
                        $status_info = getStatusInfo($display_status);
                        $inv_total = floatval($inv['total_amount'] ?? 0);
                        $inv_charges = floatval($inv['total_charges'] ?? 0);  // NEW
                        $inv_paid = getTotalPaid($conn, $inv['invoice_no']);
                        $inv_fee = floatval($inv['additional_fee'] ?? 0);
                        $inv_balance = $inv_total - $inv_paid + $inv_fee;
                    ?>
                        <div class="invoice-card" data-invoice="<?php echo htmlspecialchars($inv['invoice_no']); ?>" data-customer="<?php echo htmlspecialchars(strtolower($inv['customer_name'])); ?>" data-status="<?php echo strtolower($display_status); ?>">
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
                                <span class="<?php echo $status_info['css_class']; ?>">
                                    <?php echo $status_info['display']; ?>
                                </span>
                                <?php if ($inv_charges > 0): ?>
                                <span style="font-size:11px;color:#b45309;font-weight:600;">
                                    Charges: ₱<?php echo number_format($inv_charges, 2); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($inv_paid > 0): ?>
                                <span style="font-size:11px;color:var(--text-muted);">
                                    Paid: ₱<?php echo number_format($inv_paid, 2); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($inv_balance > 0 && strtolower($display_status) !== 'paid' && strtolower($display_status) !== 'billed'): ?>
                                <span style="font-size:11px;color:#dc2626;font-weight:600;">
                                    Balance: ₱<?php echo number_format($inv_balance, 2); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span class="inv-total">₱<?php echo number_format($inv_total, 2); ?></span>
                            <div class="inv-actions">
                                <a href="service_invoice_list.php?invoice_no=<?php echo urlencode($inv['invoice_no']); ?>" class="btn-view">
                                    <i data-lucide="eye" style="width:14px;height:14px;"></i> View
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

    const userRoles = <?php echo json_encode($user_roles); ?>;
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    const modal = document.getElementById('accessModal');

    function checkAccess(page) {
        if (userRoles.includes('admin')) return true;
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) {
                return true;
            }
        }
        modal.style.display = 'flex';
        return false;
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal?.style.display === 'flex') closeModal();
    });

    function togglePaymentFields() {
        const status = document.getElementById('payment_status').value;
        const amountField = document.getElementById('amount_paid_field');
        const methodField = document.getElementById('payment_method_field');
        const referenceField = document.getElementById('reference_field');
        const attachmentField = document.getElementById('attachment_field');
        const feeField = document.getElementById('additional_fee_field');
        const notesField = document.getElementById('payment_notes_field');
        const amountInput = document.getElementById('amount_paid');
        const feeInput = document.getElementById('additional_fee');
        const warningDiv = document.getElementById('balance_warning');
        const totalAmount = <?php echo $grand_total ?? 0; ?>;
        const currentPaid = <?php echo $amount_paid ?? 0; ?>;
        
        // Hide all fields by default
        amountField.classList.add('hidden-field');
        methodField.classList.add('hidden-field');
        referenceField.classList.add('hidden-field');
        attachmentField.classList.add('hidden-field');
        feeField.classList.add('hidden-field');
        notesField.classList.add('hidden-field');
        warningDiv.style.display = 'none';
        amountInput.disabled = true;
        feeInput.disabled = true;
        amountInput.required = false;
        feeInput.required = false;
        amountInput.readOnly = false;
        
        if (status === 'partial') {
            // Partial: show all payment fields including attachment
            amountField.classList.remove('hidden-field');
            methodField.classList.remove('hidden-field');
            referenceField.classList.remove('hidden-field');
            attachmentField.classList.remove('hidden-field');
            feeField.classList.remove('hidden-field');
            notesField.classList.remove('hidden-field');
            amountInput.disabled = false;
            amountInput.required = true;
            amountInput.max = totalAmount - 0.01;
            if (!amountInput.value) {
                amountInput.value = '';
            }
            feeInput.disabled = false;
            
            // Check if this payment will make the balance zero
            const remaining = totalAmount - currentPaid;
            if (remaining > 0) {
                amountInput.addEventListener('input', function() {
                    const entered = parseFloat(this.value) || 0;
                    if (entered >= remaining) {
                        warningDiv.style.display = 'block';
                    } else {
                        warningDiv.style.display = 'none';
                    }
                });
            }
        } else if (status === 'paid') {
            // Paid: show amount paid (readonly, auto-filled), method, reference, attachment
            amountField.classList.remove('hidden-field');
            methodField.classList.remove('hidden-field');
            referenceField.classList.remove('hidden-field');
            attachmentField.classList.remove('hidden-field');
            notesField.classList.remove('hidden-field');
            amountInput.disabled = false;
            amountInput.required = true;
            amountInput.value = totalAmount.toFixed(2);
            amountInput.readOnly = true;
            feeField.classList.add('hidden-field');
            feeInput.disabled = true;
            warningDiv.style.display = 'none';
        } else if (status === 'overdue') {
            // Overdue: show fee, amount, method, reference, attachment
            feeField.classList.remove('hidden-field');
            feeInput.disabled = false;
            amountField.classList.remove('hidden-field');
            methodField.classList.remove('hidden-field');
            referenceField.classList.remove('hidden-field');
            attachmentField.classList.remove('hidden-field');
            notesField.classList.remove('hidden-field');
            amountInput.disabled = false;
            if (!amountInput.value) {
                amountInput.value = currentPaid > 0 ? currentPaid.toFixed(2) : '';
            }
            warningDiv.style.display = 'none';
        } else if (status === 'billed') {
            // Billed: hide all payment fields
            amountField.classList.add('hidden-field');
            methodField.classList.add('hidden-field');
            referenceField.classList.add('hidden-field');
            attachmentField.classList.add('hidden-field');
            feeField.classList.add('hidden-field');
            notesField.classList.add('hidden-field');
            amountInput.disabled = true;
            feeInput.disabled = true;
            amountInput.value = '';
            feeInput.value = '';
            warningDiv.style.display = 'none';
        } else {
            // Unpaid: show amount, method, reference, attachment
            amountField.classList.remove('hidden-field');
            methodField.classList.remove('hidden-field');
            referenceField.classList.remove('hidden-field');
            attachmentField.classList.remove('hidden-field');
            notesField.classList.remove('hidden-field');
            amountInput.disabled = false;
            amountInput.value = '';
            amountInput.readOnly = false;
            warningDiv.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('paymentStatusForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(form);
                const status = formData.get('payment_status');
                const amountPaid = parseFloat(formData.get('amount_paid')) || 0;
                const additionalFee = parseFloat(formData.get('additional_fee')) || 0;
                const totalAmount = <?php echo $grand_total ?? 0; ?>;
                const currentPaid = <?php echo $amount_paid ?? 0; ?>;
                
                if (status === 'paid' && amountPaid < totalAmount) {
                    showToast('Amount paid must be equal to total amount for Paid status.', 'error');
                    return;
                }
                
                if (status === 'partial' && (amountPaid <= 0 || amountPaid >= totalAmount)) {
                    showToast('Amount paid must be greater than 0 and less than total amount for Partial status.', 'error');
                    return;
                }
                
                // Check if partial payment will make balance zero
                let finalStatus = status;
                if (status === 'partial') {
                    const remaining = totalAmount - currentPaid;
                    if (amountPaid >= remaining) {
                        // This payment will make the balance zero, show confirmation
                        if (!confirm('This payment will make the balance zero. The status will be automatically changed to PAID. Continue?')) {
                            return;
                        }
                        // The server will handle changing status to Paid
                    }
                }
                
                const btn = document.getElementById('updateBtn');
                btn.disabled = true;
                btn.innerHTML = '<i data-lucide="loader-circle" style="width:16px;height:16px;display:inline-block;vertical-align:middle;animation:spin 1s linear infinite;"></i> Updating...';
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let msg = data.message;
                        if (data.final_status === 'paid' && status === 'partial') {
                            msg = 'Payment recorded successfully. Balance is now zero, status changed to PAID.';
                        }
                        showToast(msg, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"></i> Update Payment';
                        lucide.createIcons();
                    }
                })
                .catch(error => {
                    showToast('An error occurred: ' + error.message, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"></i> Update Payment';
                    lucide.createIcons();
                });
            });
        }
        
        togglePaymentFields();
    });

    function showToast(message, type) {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    <?php if (!$view_mode): ?>
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