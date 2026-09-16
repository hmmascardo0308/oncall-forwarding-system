<?php
// vendor_ledger.php - Vendor Ledger with PO Details
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$username = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);

// Check access - only supplier_register, admin, purchase_order_maker
$allowed_roles = ['supplier_register', 'admin', 'purchase_order_maker'];
$has_access = false;
foreach ($user_roles as $role) {
    if (in_array($role, $allowed_roles)) {
        $has_access = true;
        break;
    }
}

if (!$has_access) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access this page."
    ];
    header("Location: home.php");
    exit;
}

// Get selected supplier filter
$selected_supplier = isset($_GET['supplier_code']) ? trim($_GET['supplier_code']) : '';

// Get all suppliers for dropdown
$suppliers = [];
$supplier_query = "SELECT supplier_code, supplier_name, supplier_type, status, 
                          payment_terms, vat_type, tin
                   FROM supplier_lists 
                   WHERE status = 'Active' 
                   ORDER BY supplier_name ASC";
$supplier_result = $conn->query($supplier_query);
if ($supplier_result) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Get purchase orders based on selected supplier
$purchase_orders = [];
$supplier_info = null;
$running_balance = 0;
$total_debits = 0;
$total_credits = 0;

if (!empty($selected_supplier)) {
    // Get supplier info
    $supplier_info_query = "SELECT * FROM supplier_lists WHERE supplier_code = ?";
    $stmt = $conn->prepare($supplier_info_query);
    $stmt->bind_param("s", $selected_supplier);
    $stmt->execute();
    $supplier_info_result = $stmt->get_result();
    $supplier_info = $supplier_info_result->fetch_assoc();

    // Get purchase orders for this supplier
    $po_query = "SELECT 
                    po_number,
                    supplier_code,
                    supplier_name,
                    purchase_type,
                    MAX(po_date) as po_date,
                    MAX(payment_terms) as payment_terms,
                    MAX(with_vat) as with_vat,
                    MAX(status) as status,
                    MAX(delivery_status) as delivery_status,
                    MAX(delivery_payment) as delivery_payment,
                    MAX(remarks) as remarks,
                    MAX(created_at) as created_at,
                    MAX(total_vat) as total_vat,
                    SUM(subtotal) as subtotal,
                    SUM(total_amount) as total_amount,
                    SUM(total_amount_paid) as total_amount_paid,
                    SUM(withholding_tax_amount) as withholding_tax_amount,
                    SUM(net_amount_due) as net_amount_due
                 FROM purchase_order 
                 WHERE supplier_code = ?
                 GROUP BY po_number, supplier_code, supplier_name, purchase_type
                 ORDER BY MAX(po_date) ASC, MAX(created_at) ASC";
    $stmt = $conn->prepare($po_query);
    $stmt->bind_param("s", $selected_supplier);
    $stmt->execute();
    $po_result = $stmt->get_result();
    if ($po_result) {
        while ($row = $po_result->fetch_assoc()) {
            $purchase_orders[] = $row;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Fetch payment history totals from po_payment_history for all POs
    // of this supplier (single query → keyed by po_number).
    // ═══════════════════════════════════════════════════════════════════
    $payment_history_totals = [];   // po_number => amount_paid sum
    $payment_history_counts = [];   // po_number => row count

    if (!empty($purchase_orders)) {
        $po_numbers = array_column($purchase_orders, 'po_number');
        $placeholders = implode(',', array_fill(0, count($po_numbers), '?'));
        $ph_types = str_repeat('s', count($po_numbers));

        $ph_sql = "SELECT po_number, 
                          SUM(amount_paid) AS total_paid, 
                          COUNT(*) AS payment_count
                   FROM po_payment_history 
                   WHERE po_number IN ($placeholders)
                   GROUP BY po_number";
        $ph_stmt = $conn->prepare($ph_sql);
        $ph_stmt->bind_param($ph_types, ...$po_numbers);
        $ph_stmt->execute();
        $ph_res = $ph_stmt->get_result();
        while ($r = $ph_res->fetch_assoc()) {
            $payment_history_totals[$r['po_number']] = floatval($r['total_paid']);
            $payment_history_counts[$r['po_number']] = intval($r['payment_count']);
        }
        $ph_stmt->close();
    }
}

// ─── Badge class helper: supplier status ─────────────────────────────
function getStatusBadgeClass($status) {
    $status = strtolower($status);
    if (strpos($status, 'active') !== false || strpos($status, 'completed') !== false || strpos($status, 'received') !== false || strpos($status, 'approved') !== false) {
        return 'badge-success';
    } elseif (strpos($status, 'inactive') !== false || strpos($status, 'cancelled') !== false || strpos($status, 'void') !== false) {
        return 'badge-danger';
    } elseif (strpos($status, 'pending') !== false || strpos($status, 'review') !== false || strpos($status, 'draft') !== false) {
        return 'badge-warning';
    } else {
        return 'badge-secondary';
    }
}

// ─── Badge class helper: delivery status ─────────────────────────────
function getDeliveryStatusBadgeClass($status) {
    if (empty($status)) {
        return 'badge-secondary';
    }
    switch ($status) {
        case 'Fully Received':     return 'badge-success';
        case 'Partially Received': return 'badge-warning';
        case 'Cancelled':          return 'badge-danger';
        case 'Late Delivery':      return 'badge-info';
        case 'Pending':            return 'badge-secondary';
        default:
            $s = strtolower($status);
            if (strpos($s, 'received') !== false || strpos($s, 'delivered') !== false) {
                return 'badge-success';
            } elseif (strpos($s, 'pending') !== false) {
                return 'badge-warning';
            } elseif (strpos($s, 'cancelled') !== false || strpos($s, 'rejected') !== false) {
                return 'badge-danger';
            }
            return 'badge-secondary';
    }
}

// ─── Badge class helper: payment status ──────────────────────────────
function getPaymentBadgeClass($status) {
    if (empty($status)) {
        return 'badge-secondary';
    }
    switch ($status) {
        case 'Fully Paid':     return 'badge-success';
        case 'Partially Paid': return 'badge-warning';
        case 'Pending':        return 'badge-danger';
        case 'N/A':            return 'badge-secondary';
        default:
            $s = strtolower($status);
            if (strpos($s, 'paid') !== false && strpos($s, 'partial') === false && strpos($s, 'unpaid') === false) {
                return 'badge-success';
            } elseif (strpos($s, 'partial') !== false) {
                return 'badge-warning';
            } elseif (strpos($s, 'pending') !== false || strpos($s, 'unpaid') !== false) {
                return 'badge-danger';
            }
            return 'badge-secondary';
    }
}

// ═══════════════════════════════════════════════════════════════════════
// resolveLedgerStatus()
//
// Determines the DISPLAY status for a PO row. A PO is considered
// Fully Received + Fully Paid when EITHER of these conditions is met:
//
//   (A) purchase_order.total_amount_paid >= purchase_order.net_amount_due
//   (B) po_payment_history SUM(amount_paid) >= purchase_order.net_amount_due
//
// Cancelled / Late Delivery statuses are never overridden.
// ═══════════════════════════════════════════════════════════════════════
function resolveLedgerStatus($po, $payment_history_total) {
    $raw_delivery_status  = $po['delivery_status']  ?? '';
    $raw_delivery_payment = $po['delivery_payment'] ?? '';

    $net_amount_due         = floatval($po['net_amount_due']         ?? 0);
    $po_total_amount_paid   = floatval($po['total_amount_paid']      ?? 0);
    $ph_total_amount_paid   = floatval($payment_history_total        ?? 0);

    // Never override explicit cancellations / late delivery
    if (in_array($raw_delivery_status, ['Cancelled', 'Late Delivery'], true)) {
        return [
            'delivery_status'  => $raw_delivery_status,
            'delivery_payment' => $raw_delivery_payment ?: 'Pending',
            'auto_upgraded'    => false,
            'paid_source'      => null,
        ];
    }

    // Condition A — purchase_order.total_amount_paid already covers net_amount_due
    $conditionA = ($net_amount_due > 0 && $po_total_amount_paid >= $net_amount_due);

    // Condition B — po_payment_history SUM(amount_paid) covers net_amount_due
    $conditionB = ($net_amount_due > 0 && $ph_total_amount_paid >= $net_amount_due);

    if ($conditionA || $conditionB) {
        return [
            'delivery_status'  => 'Fully Received',
            'delivery_payment' => 'Fully Paid',
            'auto_upgraded'    => true,
            'paid_source'      => $conditionB ? 'po_payment_history' : 'purchase_order',
        ];
    }

    // Not fully paid — fall back to stored values, but sanity-check payment status
    $stored_payment = $raw_delivery_payment ?: 'Pending';

    // If any partial amount has been paid but stored status says Fully Paid, downgrade
    $anyPaid = ($po_total_amount_paid > 0 || $ph_total_amount_paid > 0);
    if ($anyPaid && $net_amount_due > 0
        && $po_total_amount_paid < $net_amount_due
        && $ph_total_amount_paid < $net_amount_due
        && $stored_payment === 'Fully Paid') {
        $stored_payment = 'Partially Paid';
    }

    return [
        'delivery_status'  => $raw_delivery_status ?: 'Pending',
        'delivery_payment' => $stored_payment,
        'auto_upgraded'    => false,
        'paid_source'      => null,
    ];
}

$success_msg = null;
if (isset($_SESSION['login_success'])) {
    $success_msg = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

// Use the function from access_control.php
$role_display_name = getRoleDisplayName($user_roles);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Ledger | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/home.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/vendor_ledger.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">

   
</head>
<body>

    <?php if ($success_msg): ?>
        <div id="flash-message"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header>
            <div class="breadcrumb">
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <a href="reports.php" style="color:red; font-weight:bold; font-size:16px; text-decoration:none;">
    Reports
</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Vendor Ledger</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>Vendor Ledger</h1>
            </section>

            <div class="ledger-container">
                <div class="ledger-header">
                <h2>Vendor Ledger</h2>
                <div class="ledger-controls">
                    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <select name="supplier_code" onchange="this.form.submit()">
                <option value="">Select Vendor</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>" 
                        <?php echo $selected_supplier == $supplier['supplier_code'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($supplier['supplier_code'] . ' - ' . $supplier['supplier_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (!empty($selected_supplier)): ?>
                <a href="export_vendor_ledger.php?supplier_code=<?php echo urlencode($selected_supplier); ?>" 
                   class="btn-export" 
                   title="Export to Excel">
                    <i data-lucide="file-spreadsheet"></i>
                    <span>Export to Excel</span>
                </a>
            <?php endif; ?>

            <?php if (!empty($selected_supplier)): ?>
                <a href="vendor_ledger.php" class="btn btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

                <?php if (!empty($selected_supplier) && $supplier_info): ?>
                    <!-- Supplier Summary -->
                    <div class="supplier-summary">
                        <div class="supplier-summary-item">
                            <span class="label">Supplier Code</span>
                            <span class="value"><?php echo htmlspecialchars($supplier_info['supplier_code']); ?></span>
                        </div>
                        <div class="supplier-summary-item">
                            <span class="label">Supplier Name</span>
                            <span class="value"><?php echo htmlspecialchars($supplier_info['supplier_name']); ?></span>
                        </div>
                        <div class="supplier-summary-item">
                            <span class="label">Terms</span>
                            <span class="value"><?php echo htmlspecialchars($supplier_info['payment_terms'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="supplier-summary-item">
                            <span class="label">VAT Type</span>
                            <span class="value"><?php echo htmlspecialchars($supplier_info['vat_type'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="supplier-summary-item">
                            <span class="label">TIN</span>
                            <span class="value"><?php echo htmlspecialchars($supplier_info['tin'] ?: 'N/A'); ?></span>
                        </div>
                        <div class="supplier-summary-item">
                            <span class="label">Status</span>
                            <span class="value">
                                <span class="badge-status <?php echo getStatusBadgeClass($supplier_info['status']); ?>">
                                    <?php echo htmlspecialchars($supplier_info['status'] ?: 'Active'); ?>
                                </span>
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($purchase_orders)): ?>
                        <div class="table-wrapper">
                            <table class="ledger-table">
                                <thead>
                                    <tr>
                                        <th>Supplier Code</th>
                                        <th>Supplier Name</th>
                                        <th>Payment<br>Terms</th>
                                        <th>Transaction Date</th>
                                        <th>Reference No.</th>
                                        <th>Type</th>
                                        <th>Purpose</th>
                                        <th>VAT Type</th>
                                        <th>Amount</th>
                                        <th>Debit (Owed)</th>
                                        <th>Credit (Paid)</th>
                                        <th>Balance</th>
                                        <th>Remarks</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $running_balance = 0;
                                    $has_transactions = false;
                                    $total_debits = 0;
                                    $total_credits = 0;

                                    foreach ($purchase_orders as $po):
                                        // Skip cancelled or voided POs from balance calculation but still show them
                                        $po_status = strtolower($po['status'] ?? '');
                                        $is_cancelled = in_array($po_status, ['cancelled', 'void']);

                                        // ── Payment history total for this PO ──
                                        $ph_total = $payment_history_totals[$po['po_number']] ?? 0.0;
                                        $ph_count = $payment_history_counts[$po['po_number']] ?? 0;

                                        // ── Resolve display status (auto-upgrade logic) ──
                                        $resolved = resolveLedgerStatus($po, $ph_total);

                                        $display_delivery_status  = $resolved['delivery_status'];
                                        $display_delivery_payment = $resolved['delivery_payment'];
                                        $auto_upgraded            = $resolved['auto_upgraded'];
                                        $paid_source              = $resolved['paid_source'];

                                        // Effective total paid: use po_payment_history if greater
                                        $po_total_amount_paid = floatval($po['total_amount_paid'] ?? 0);
                                        $effective_total_paid = max($po_total_amount_paid, $ph_total);

                                        // Determine debit and credit based on resolved status
                                        $debit = 0;
                                        $credit = 0;
                                        $amount = 0;
                                        $remark = '';

                                        $net_amount_due = floatval($po['net_amount_due'] ?? 0);

                                        $is_fully_received = ($display_delivery_status === 'Fully Received');

                                        if ($is_fully_received && !$is_cancelled) {
                                            // AMOUNT & DEBIT: full amount owed
                                            if ($net_amount_due > 0) {
                                                $amount = $net_amount_due;
                                                $debit  = $net_amount_due;
                                                $total_debits += $net_amount_due;
                                            }

                                            // CREDIT: amount paid (from either source, whichever is greater)
                                            if ($effective_total_paid > 0) {
                                                $credit = $effective_total_paid;
                                                $total_credits += $effective_total_paid;
                                            }

                                            // Remark
                                            $remaining = $net_amount_due - $effective_total_paid;
                                            if ($effective_total_paid >= $net_amount_due && $net_amount_due > 0) {
                                                $remark = 'Fully paid';
                                                if ($auto_upgraded) {
                                                    $remark .= $paid_source === 'po_payment_history'
                                                        ? ' (via payment history)'
                                                        : ' (via PO total)';
                                                }
                                            } elseif ($effective_total_paid > 0 && $effective_total_paid < $net_amount_due) {
                                                $remark = 'Partial payment - Balance: ₱ ' . number_format($remaining, 2);
                                            } elseif ($net_amount_due == 0) {
                                                $remark = 'Zero amount';
                                            } else {
                                                $remark = 'Awaiting payment';
                                            }
                                        } elseif (!$is_cancelled) {
                                            // Partially Received / Pending / Late Delivery — still record partial payments
                                            if ($net_amount_due > 0) {
                                                $amount = $net_amount_due;
                                            }
                                            if ($effective_total_paid > 0) {
                                                $credit = $effective_total_paid;
                                                $total_credits += $effective_total_paid;
                                                $debit  = $net_amount_due;
                                                $total_debits += $net_amount_due;

                                                $remaining = $net_amount_due - $effective_total_paid;
                                                if ($effective_total_paid < $net_amount_due) {
                                                    $remark = 'Partial payment - Balance: ₱ ' . number_format($remaining, 2);
                                                } else {
                                                    $remark = 'Fully paid';
                                                }
                                            } else {
                                                $remark = 'Pending delivery';
                                            }
                                        }

                                        if ($is_cancelled) {
                                            $remark = 'Cancelled / Void';
                                        }

                                        // Update Balance
                                        if (!$is_cancelled && ($debit > 0 || $credit > 0)) {
                                            $running_balance += $debit - $credit;
                                            $has_transactions = true;
                                        }

                                        $row_class = $is_cancelled ? 'cancelled-row' : '';

                                        $payment_terms_display = !empty($po['payment_terms']) ? $po['payment_terms'] : ($supplier_info['payment_terms'] ?? 'N/A');
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <span class="supplier-code-badge"><?php echo htmlspecialchars($po['supplier_code']); ?></span>
                                        </td>
                                        <td class="supplier-name-cell">
                                            <span class="text-ellipsis" title="<?php echo htmlspecialchars($po['supplier_name']); ?>">
                                                <?php echo htmlspecialchars($po['supplier_name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment_terms_display); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($po['po_date'])); ?></td>
                                        <td>
                                            <a href="purchase_orders_view.php?po_number=<?php echo urlencode($po['po_number']); ?>" 
                                               class="po-link">
                                                <?php echo htmlspecialchars($po['po_number']); ?>
                                            </a>
                                        </td>
                                        <td>Purchase Journal</td>
                                        <td><?php echo htmlspecialchars($po['purchase_type'] ?: '—'); ?></td>
                                        <td>
                                            <?php if ($po['with_vat'] == 1): ?>
                                                <span class="badge-status badge-success">With VAT</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-secondary">VAT Exempt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="amount-amount">
                                            <?php echo $amount > 0 ? '₱ ' . number_format($amount, 2) : '—'; ?>
                                        </td>
                                        <td class="amount-debit">
                                            <?php echo $debit > 0 ? '₱ ' . number_format($debit, 2) : '—'; ?>
                                        </td>
                                        <td class="amount-credit">
                                            <?php echo $credit > 0 ? '₱ ' . number_format($credit, 2) : '—'; ?>
                                        </td>
                                        <td class="amount-balance" style="color: <?php 
                                            if ($is_cancelled) echo '#6b7280';
                                            elseif ($running_balance > 0) echo '#dc2626';
                                            elseif ($running_balance < 0) echo '#059669';
                                            else echo '#6b7280';
                                        ?>;">
                                            <?php 
                                            if ($is_cancelled) {
                                                echo '—';
                                            } else {
                                                echo '₱ ' . number_format($running_balance, 2);
                                            }
                                            ?>
                                        </td>

                                        <!-- REMARKS -->
                                        <td>
                                            <span style="font-size: 12px; color: #6b7280;">
                                                <?php echo htmlspecialchars($remark ?: ($po['remarks'] ?: '—')); ?>
                                            </span>
                                            <?php if ($ph_count > 0): ?>
                                                <br>
                                                <span style="font-size: 10px; color: #94a3b8;">
                                                    <?php echo $ph_count; ?> payment<?php echo $ph_count > 1 ? 's' : ''; ?> recorded
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- STATUS: resolved delivery_status + delivery_payment -->
                                        <td>
                                            <div class="status-cell-badges">
                                                <span class="badge-status <?php echo getDeliveryStatusBadgeClass($display_delivery_status); ?>">
                                                    <?php echo htmlspecialchars($display_delivery_status ?: 'Pending'); ?>
                                                </span>
                                                <span class="badge-status <?php echo getPaymentBadgeClass($display_delivery_payment); ?>">
                                                    <?php echo htmlspecialchars($display_delivery_payment ?: 'Pending'); ?>
                                                </span>
                                                <?php if ($auto_upgraded): ?>
                                                    <span class="auto-badge" title="Auto-detected from <?php echo $paid_source === 'po_payment_history' ? 'po_payment_history' : 'purchase_order.total_amount_paid'; ?>">
                                                        Auto
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($purchase_orders)): ?>
                                        <tr>
                                            <td colspan="14" style="text-align: center; padding: 30px; color: #6b7280;">
                                                No purchase orders found for this vendor.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($has_transactions): ?>
                        <div class="summary-cards">
                            <div class="summary-grid">
                                <div class="summary-item">
                                    <span class="label">Total Debit (Amount Owed)</span>
                                    <div class="value" style="color: #dc2626;">
                                        ₱ <?php echo number_format($total_debits, 2); ?>
                                    </div>
                                </div>
                                <div class="summary-item">
                                    <span class="label">Total Credit (Amount Paid)</span>
                                    <div class="value" style="color: #059669;">
                                        ₱ <?php echo number_format($total_credits, 2); ?>
                                    </div>
                                </div>
                                <div class="summary-item">
                                    <span class="label">Final Balance</span>
                                    <div class="value" style="color: <?php echo $running_balance > 0 ? '#dc2626' : ($running_balance < 0 ? '#059669' : '#6b7280'); ?>;">
                                        ₱ <?php echo number_format($running_balance, 2); ?>
                                        <span style="font-size: 14px; font-weight: 400; color: #6b7280; margin-left: 8px;">
                                            (<?php echo $running_balance > 0 ? 'Still Owed' : ($running_balance < 0 ? 'Overpaid' : 'Settled'); ?>)
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="summary-divider">
                                <span><span class="badge-status badge-danger">Debit</span> = Total amount owed to vendor</span>
                                <span><span class="badge-status badge-success">Credit</span> = Total amount paid to vendor</span>
                                <span><span class="badge-status badge-warning">Balance</span> = Debit - Credit (remaining amount owed)</span>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="no-data">
                            <i data-lucide="file-text"></i>
                            <p>No purchase orders found for this vendor.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-data">
                        <i data-lucide="building-2"></i>
                        <p style="font-size: 16px; font-weight: 500; color: #374151;">Select a vendor to view ledger</p>
                        <p style="font-size: 14px; color: #6b7280;">Choose a vendor from the dropdown above to see their transaction history.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flash-message');
            if (flash) {
                setTimeout(() => { 
                    flash.style.opacity = '0'; 
                    setTimeout(() => flash.remove(), 500); 
                }, 3000);
            }
        });
    </script>

</body>
</html>