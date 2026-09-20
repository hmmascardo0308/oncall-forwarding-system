<?php
// get_po_details.php
session_start();
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$po_number = $_GET['po_number'] ?? '';
$summary_only = isset($_GET['summary']) && $_GET['summary'] === 'true';

if (!$po_number) {
    echo json_encode(['success' => false, 'message' => 'PO number required']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM purchase_order WHERE po_number = ? ORDER BY item_code ASC");
$stmt->bind_param("s", $po_number);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];

while ($row = $result->fetch_assoc()) {
    $qty_ordered = floatval($row['qty_ordered']);
    $qty_received = floatval($row['qty_received'] ?? 0);
    $unit_cost = floatval($row['unit_cost'] ?? 0);
    $total_amount = floatval($row['total_amount'] ?? 0);

    if ($qty_received >= $qty_ordered) {
        $amount_paid = $total_amount;
    } else {
        $amount_paid = $qty_received * $unit_cost;
    }
    $row['amount_paid'] = $amount_paid;

    // Get current stock
    $stock_stmt = $conn->prepare("SELECT current_stock FROM item_masterlist WHERE item_code = ?");
    $stock_stmt->bind_param("s", $row['item_code']);
    $stock_stmt->execute();
    $stock_result = $stock_stmt->get_result();
    $stock_data = $stock_result->fetch_assoc();
    $row['current_stock'] = $stock_data ? floatval($stock_data['current_stock']) : 0;
    $stock_stmt->close();
    
    $rows[] = $row;
}

$stmt->close();

// ─── Resolve the correct Total Amount Paid + Auto-upgrade ───
$delivery_payment = $rows[0]['delivery_payment'] ?? 'Pending';
$delivery_status  = $rows[0]['delivery_status'] ?? 'Pending';

// ─── CHANGED: MAX, not SUM — net_amount_due is the same on every item row ───
$due_stmt = $conn->prepare("SELECT MAX(net_amount_due) AS due_total FROM purchase_order WHERE po_number = ?");
$due_stmt->bind_param("s", $po_number);
$due_stmt->execute();
$due_res = $due_stmt->get_result()->fetch_assoc();
$due_stmt->close();
$net_due_total = floatval($due_res['due_total'] ?? 0);

$pay_stmt = $conn->prepare("SELECT SUM(amount_paid) AS paid_total FROM po_payment_history WHERE po_number = ?");
$pay_stmt->bind_param("s", $po_number);
$pay_stmt->execute();
$pay_res = $pay_stmt->get_result()->fetch_assoc();
$pay_stmt->close();
$paid_total = floatval($pay_res['paid_total'] ?? 0);

// ── Auto-upgrade: payments >= net due → Fully Received / Fully Paid ──
//   Don't override explicit Cancelled or Late Delivery statuses.
$is_explicit = in_array($delivery_status, ['Cancelled', 'Late Delivery'], true);
$auto_upgraded = false;
if (!$is_explicit
    && $net_due_total > 0
    && $paid_total > 0
    && $paid_total >= $net_due_total
) {
    $delivery_status  = 'Fully Received';
    $delivery_payment = 'Fully Paid';
    $auto_upgraded = true;
}

// Compute the resolved Total Amount Paid
$total_amount_paid = 0.0;
if ($delivery_payment === 'Fully Paid') {
    $total_amount_paid = $net_due_total;
} elseif ($delivery_payment === 'Partially Paid') {
    $total_amount_paid = $paid_total;
}

// Reflect the (possibly upgraded) status in the returned rows so the edit modal shows it
if (!empty($rows)) {
    foreach ($rows as &$r) {
        $r['delivery_status']  = $delivery_status;
        $r['delivery_payment'] = $delivery_payment;
        // Keep total_amount_paid consistent on every row for the modal
        $r['total_amount_paid'] = $total_amount_paid > 0 ? $total_amount_paid : ($r['total_amount_paid'] ?? 0);
    }
    unset($r);
}

// ─── CHANGED: locked only when Fully Received + Fully Paid AND history covers net due ───
$is_locked = ($delivery_status === 'Fully Received')
          && ($delivery_payment === 'Fully Paid')
          && ($net_due_total > 0 && $paid_total >= $net_due_total);
// ─── END CHANGED ───

header('Content-Type: application/json');

if ($summary_only) {
    if (!empty($rows)) {
        $po = $rows[0];
        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'total_amount_paid' => $total_amount_paid,
            // ─── CHANGED ───
            'paid_from_history' => $paid_total,
            'net_amount_due'    => $net_due_total,
            'balance'           => max(0, $net_due_total - $paid_total),
            'locked'            => $is_locked,
            // ─── END CHANGED ───
            'delivery_status' => $delivery_status,
            'delivery_payment' => $delivery_payment,
            'delivered_date' => $po['delivered_date'] ?? null,
            'received_by' => $po['received_by'] ?? null,
            'auto_upgraded' => $auto_upgraded
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'rows' => [],
            'total_amount_paid' => 0
        ]);
    }
} else {
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'total_amount_paid' => $total_amount_paid,
        // ─── CHANGED ───
        'paid_from_history' => $paid_total,
        'net_amount_due'    => $net_due_total,
        'balance'           => max(0, $net_due_total - $paid_total),
        'locked'            => $is_locked,
        // ─── END CHANGED ───
        'delivery_status' => $delivery_status,
        'delivery_payment' => $delivery_payment,
        'auto_upgraded' => $auto_upgraded
    ]);
}

$conn->close();
?>