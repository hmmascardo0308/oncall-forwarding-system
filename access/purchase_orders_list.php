<?php
// purchase_order_list.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Guest';
$full_name  = $_SESSION['full_name'] ?? $username;

$user_roles = array_map('trim', explode(',', $user_type));
$is_admin = in_array('admin', $user_roles);

$can_access_po = $is_admin || in_array('purchase_order_maker', $user_roles);

if (!$can_access_po) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Purchase Order page."
    ];
    header("Location: home.php");
    exit;
}

$role_display_name = getRoleDisplayName($user_roles);
$current_page = basename($_SERVER['PHP_SELF']);

// ─── Delivery → Payment status mapping ────────────────────────────────
function getPaymentStatusForDelivery($delivery_status) {
    switch ($delivery_status) {
        case 'Pending':            return 'Pending';
        case 'Fully Received':     return 'Fully Paid';
        case 'Partially Received': return 'Partially Paid';
        case 'Cancelled':          return 'N/A';
        case 'Late Delivery':      return null;
        default:                   return 'Pending';
    }
}

// ─── Total Amount Paid rules ──────────────────────────────────────────
function resolveTotalAmountPaid($conn, $po_number, $delivery_payment) {
    if ($delivery_payment === 'Fully Paid') {
        $stmt = $conn->prepare("SELECT SUM(net_amount_due) AS due_total FROM purchase_order WHERE po_number = ?");
        $stmt->bind_param("s", $po_number);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return floatval($res['due_total'] ?? 0);
    } elseif ($delivery_payment === 'Partially Paid') {
        $stmt = $conn->prepare("SELECT SUM(amount_paid) AS paid_total FROM po_payment_history WHERE po_number = ?");
        $stmt->bind_param("s", $po_number);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return floatval($res['paid_total'] ?? 0);
    }
    return 0.0;
}

// ─── Auto-upgrade helper ──────────────────────────────────────────────
function maybeAutoUpgradeStatus($conn, $po_number, $current_delivery_status, $current_delivery_payment) {
    if (in_array($current_delivery_status, ['Cancelled', 'Late Delivery'], true)) {
        return [$current_delivery_status, $current_delivery_payment, false];
    }

    $due_stmt = $conn->prepare("SELECT SUM(net_amount_due) AS due_total FROM purchase_order WHERE po_number = ?");
    $due_stmt->bind_param("s", $po_number);
    $due_stmt->execute();
    $due_res = $due_stmt->get_result()->fetch_assoc();
    $due_stmt->close();
    $net_due = floatval($due_res['due_total'] ?? 0);

    $pay_stmt = $conn->prepare("SELECT SUM(amount_paid) AS paid_total FROM po_payment_history WHERE po_number = ?");
    $pay_stmt->bind_param("s", $po_number);
    $pay_stmt->execute();
    $pay_res = $pay_stmt->get_result()->fetch_assoc();
    $pay_stmt->close();
    $paid = floatval($pay_res['paid_total'] ?? 0);

    if ($net_due > 0 && $paid > 0 && $paid >= $net_due) {
        return ['Fully Received', 'Fully Paid', true];
    }
    return [$current_delivery_status, $current_delivery_payment, false];
}

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    $po_number = $_POST['po_number'] ?? '';
    $field = $_POST['field'] ?? '';
    $items_update = $_POST['items_update'] ?? '';
    $delivery_info = $_POST['delivery_info'] ?? '';
    $payment_info = $_POST['payment_info'] ?? '';

    if (empty($po_number)) {
        echo json_encode(['success' => false, 'message' => 'Missing PO number']);
        exit;
    }

    // ─── Bulk delivery update ─────────────────────────────────
    if ($field === 'bulk_delivery_update' && !empty($delivery_info)) {
        $delivery_data = json_decode($delivery_info, true);
        if (!is_array($delivery_data)) {
            echo json_encode(['success' => false, 'message' => 'Invalid delivery data']);
            exit;
        }

        $conn->begin_transaction();

        try {
            $update_fields = [];
            $params = [];
            $types = '';

            if (isset($delivery_data['delivery_status'])) {
                $update_fields[] = "delivery_status = ?";
                $params[] = $delivery_data['delivery_status'];
                $types .= 's';
            }
            if (isset($delivery_data['delivery_payment'])) {
                $update_fields[] = "delivery_payment = ?";
                $params[] = $delivery_data['delivery_payment'];
                $types .= 's';
            }
            if (isset($delivery_data['delivered_date'])) {
                $update_fields[] = "delivered_date = ?";
                $params[] = $delivery_data['delivered_date'] ?: null;
                $types .= 's';
            }
            if (isset($delivery_data['received_by'])) {
                $update_fields[] = "received_by = ?";
                $params[] = $delivery_data['received_by'];
                $types .= 's';
            }

            if (empty($update_fields)) {
                throw new Exception("No fields to update");
            }

            $params[] = $po_number;
            $types .= 's';

            $sql = "UPDATE purchase_order SET " . implode(', ', $update_fields) . " WHERE po_number = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);

            if (!$stmt->execute()) {
                throw new Exception("Failed to update delivery info: " . $stmt->error);
            }
            $stmt->close();

            $conn->commit();

            $new_payment_status = $delivery_data['delivery_payment'] ?? null;
            if ($new_payment_status === null) {
                $lookup = $conn->prepare("SELECT delivery_payment, delivery_status FROM purchase_order WHERE po_number = ? LIMIT 1");
                $lookup->bind_param("s", $po_number);
                $lookup->execute();
                $lres = $lookup->get_result()->fetch_assoc();
                $lookup->close();
                $new_payment_status = $lres['delivery_payment'] ?? 'Pending';
                $lookup_status = $lres['delivery_status'] ?? 'Pending';
            } else {
                $lookup = $conn->prepare("SELECT delivery_status FROM purchase_order WHERE po_number = ? LIMIT 1");
                $lookup->bind_param("s", $po_number);
                $lookup->execute();
                $lres = $lookup->get_result()->fetch_assoc();
                $lookup->close();
                $lookup_status = $lres['delivery_status'] ?? 'Pending';
            }

            list($upgraded_status, $upgraded_payment, $did_upgrade) = maybeAutoUpgradeStatus(
                $conn, $po_number, $lookup_status, $new_payment_status
            );

            if ($did_upgrade) {
                $upd = $conn->prepare("UPDATE purchase_order SET delivery_status = 'Fully Received', delivery_payment = 'Fully Paid' WHERE po_number = ?");
                $upd->bind_param("s", $po_number);
                $upd->execute();
                $upd->close();
                $new_payment_status = $upgraded_payment;
            }

            $resolved_total = resolveTotalAmountPaid($conn, $po_number, $new_payment_status);

            echo json_encode([
                'success' => true,
                'message' => 'Delivery information updated successfully',
                'total_amount_paid' => $resolved_total,
                'delivery_payment' => $new_payment_status,
                'auto_upgraded' => $did_upgrade
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        $conn->close();
        exit;
    }

    // ─── Bulk item update ─────────────────────────────────────
    // Only qty_received + stock are updated here. Payment amounts are the
    // exclusive responsibility of po_payment_history (see bulk_payment_update).
    if ($field === 'bulk_update' && !empty($items_update)) {
        $items_data = json_decode($items_update, true);
        if (!is_array($items_data)) {
            echo json_encode(['success' => false, 'message' => 'Invalid items data']);
            exit;
        }

        $conn->begin_transaction();
        $updated_items = [];
        $failed_items = [];

        try {
            foreach ($items_data as $item) {
                $item_code = $item['item_code'] ?? '';
                $qty_received = floatval($item['qty_received'] ?? 0);

                if (empty($item_code)) {
                    $failed_items[] = ['item_code' => $item_code, 'reason' => 'Missing item code'];
                    continue;
                }

                $get_item_sql = "SELECT unit_cost, qty_ordered, total_amount as original_total_amount, supplier_code, item, net_amount_due FROM purchase_order WHERE po_number = ? AND item_code = ?";
                $stmt = $conn->prepare($get_item_sql);
                $stmt->bind_param("ss", $po_number, $item_code);
                $stmt->execute();
                $result = $stmt->get_result();
                $item_data = $result->fetch_assoc();
                $stmt->close();

                if (!$item_data) {
                    $failed_items[] = ['item_code' => $item_code, 'reason' => 'Item not found'];
                    continue;
                }

                $supplier_code = $item_data['supplier_code'];
                $item_name = $item_data['item'];

                $get_old_sql = "SELECT qty_received FROM purchase_order WHERE po_number = ? AND item_code = ?";
                $stmt = $conn->prepare($get_old_sql);
                $stmt->bind_param("ss", $po_number, $item_code);
                $stmt->execute();
                $old_result = $stmt->get_result();
                $old_data = $old_result->fetch_assoc();
                $old_qty_received = $old_data ? floatval($old_data['qty_received']) : 0;
                $stmt->close();

                $update_sql = "UPDATE purchase_order SET qty_received = ? WHERE po_number = ? AND item_code = ?";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("dss", $qty_received, $po_number, $item_code);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to update item $item_code: " . $stmt->error);
                }
                $stmt->close();

                $qty_difference = $qty_received - $old_qty_received;

                $update_stock_sql = "UPDATE item_masterlist
                                    SET current_stock = current_stock + ?
                                    WHERE item_code = ? AND item_name = ? AND supplier_code = ?";
                $stmt = $conn->prepare($update_stock_sql);
                $stmt->bind_param("dsss", $qty_difference, $item_code, $item_name, $supplier_code);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to update stock for $item_code: " . $stmt->error);
                }

                $rows_affected = $stmt->affected_rows;
                $stmt->close();

                if ($rows_affected == 0) {
                    $update_stock_sql = "UPDATE item_masterlist
                                        SET current_stock = current_stock + ?
                                        WHERE item_code = ? AND item_name = ?";
                    $stmt = $conn->prepare($update_stock_sql);
                    $stmt->bind_param("dss", $qty_difference, $item_code, $item_name);

                    if (!$stmt->execute()) {
                        throw new Exception("Failed to update stock for $item_code: " . $stmt->error);
                    }

                    $rows_affected = $stmt->affected_rows;
                    $stmt->close();

                    if ($rows_affected == 0) {
                        $update_stock_sql = "UPDATE item_masterlist
                                            SET current_stock = current_stock + ?
                                            WHERE item_code = ?";
                        $stmt = $conn->prepare($update_stock_sql);
                        $stmt->bind_param("ds", $qty_difference, $item_code);

                        if (!$stmt->execute()) {
                            throw new Exception("Failed to update stock for $item_code: " . $stmt->error);
                        }

                        $stmt->close();
                    }
                }

                $updated_items[] = [
                    'item_code' => $item_code,
                    'qty_received' => $qty_received,
                    'qty_difference' => $qty_difference
                ];
            }

            $conn->commit();

            $stock_info = [];
            foreach ($updated_items as $item) {
                $get_stock_sql = "SELECT current_stock FROM item_masterlist WHERE item_code = ?";
                $stmt = $conn->prepare($get_stock_sql);
                $stmt->bind_param("s", $item['item_code']);
                $stmt->execute();
                $stock_result = $stmt->get_result();
                $stock_data = $stock_result->fetch_assoc();
                $current_stock = $stock_data ? floatval($stock_data['current_stock']) : 0;
                $stmt->close();
                $stock_info[$item['item_code']] = $current_stock;
            }

            echo json_encode([
                'success' => true,
                'message' => 'All items updated successfully',
                'updated_items' => $updated_items,
                'stock_info' => $stock_info,
                'failed_items' => $failed_items
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        $conn->close();
        exit;
    }

    // ─── Payment history insert ───────────────────────────────
    // Records ONE row per PO (per payment event). The single shared amount
    // is written to every item row in purchase_order (NOT divided).
    if ($field === 'bulk_payment_update' && !empty($payment_info)) {
        $payment_data = json_decode($payment_info, true);
        if (!is_array($payment_data)) {
            echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
            exit;
        }

        $conn->begin_transaction();

        try {
            $payment_method = $payment_data['payment_method'] ?? '';
            $reference_no   = $payment_data['reference_no'] ?? '';
            $notes          = $payment_data['notes'] ?? '';
            $payment_date   = $payment_data['payment_date'] ?? date('Y-m-d');
            $amount_paid    = floatval($payment_data['amount_paid'] ?? 0);

            if (empty($payment_method)) {
                throw new Exception('Payment method is required for Partial payments');
            }
            if ($amount_paid <= 0) {
                throw new Exception('Amount paid must be greater than zero');
            }

            $attachment_path = null;
            if (isset($_FILES['supporting_attachment']) && $_FILES['supporting_attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/po_payments/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file = $_FILES['supporting_attachment'];
                $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception('Invalid file type. Allowed: ' . implode(', ', $allowed_ext));
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new Exception('File too large (max 5MB)');
                }

                $safe_name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                $filename = $po_number . '_' . time() . '_' . $safe_name . '.' . $ext;
                $target = $upload_dir . $filename;

                if (!move_uploaded_file($file['tmp_name'], $target)) {
                    throw new Exception('Failed to upload attachment');
                }
                $attachment_path = 'uploads/po_payments/' . $filename;
            }

            $created_by = $username;
            $now = date('Y-m-d H:i:s');

            // Single row per PO payment — item fields NULL.
            $insert_sql = "INSERT INTO po_payment_history
                (po_number, payment_date, amount_paid, payment_method, reference_no, notes, supporting_attachment, created_by, created_at, item_code, item_name, qty_received)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL)";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param(
                "ssdssssss",
                $po_number,
                $payment_date,
                $amount_paid,
                $payment_method,
                $reference_no,
                $notes,
                $attachment_path,
                $created_by,
                $now
            );

            if (!$stmt->execute()) {
                throw new Exception("Failed to insert payment history: " . $stmt->error);
            }
            $stmt->close();

            // Store payment meta on the PO.
            $upd = $conn->prepare("UPDATE purchase_order SET payment_method = ?, payment_notes = ?, paid_at = ? WHERE po_number = ?");
            $upd->bind_param("ssss", $payment_method, $notes, $payment_date, $po_number);
            $upd->execute();
            $upd->close();

            // ── Check if the PO is now fully paid ──
            $check_due_stmt = $conn->prepare("SELECT SUM(net_amount_due) AS due_total FROM purchase_order WHERE po_number = ?");
            $check_due_stmt->bind_param("s", $po_number);
            $check_due_stmt->execute();
            $due_row = $check_due_stmt->get_result()->fetch_assoc();
            $check_due_stmt->close();
            $net_due_total = floatval($due_row['due_total'] ?? 0);

            $check_paid_stmt = $conn->prepare("SELECT SUM(amount_paid) AS paid_total FROM po_payment_history WHERE po_number = ?");
            $check_paid_stmt->bind_param("s", $po_number);
            $check_paid_stmt->execute();
            $paid_row = $check_paid_stmt->get_result()->fetch_assoc();
            $check_paid_stmt->close();
            $paid_total = floatval($paid_row['paid_total'] ?? 0);

            $lookup = $conn->prepare("SELECT delivery_status FROM purchase_order WHERE po_number = ? LIMIT 1");
            $lookup->bind_param("s", $po_number);
            $lookup->execute();
            $lres = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            $current_delivery_status = $lres['delivery_status'] ?? 'Pending';

            $is_explicit = in_array($current_delivery_status, ['Cancelled', 'Late Delivery'], true);

            if (!$is_explicit && $net_due_total > 0 && $paid_total >= $net_due_total) {
                $upd_po = $conn->prepare("UPDATE purchase_order SET delivery_status = 'Fully Received', delivery_payment = 'Fully Paid', payment_method = ?, payment_notes = ?, paid_at = ? WHERE po_number = ?");
                $upd_po->bind_param("ssss", $payment_method, $notes, $payment_date, $po_number);
                $upd_po->execute();
                $upd_po->close();
                $auto_upgraded = true;
                $final_payment_status = 'Fully Paid';
            } else {
                $upd_po = $conn->prepare("UPDATE purchase_order SET delivery_payment = 'Partially Paid', payment_method = ?, payment_notes = ?, paid_at = ? WHERE po_number = ?");
                $upd_po->bind_param("ssss", $payment_method, $notes, $payment_date, $po_number);
                $upd_po->execute();
                $upd_po->close();
                $auto_upgraded = false;
                $final_payment_status = 'Partially Paid';
            }

            // ── Write the SAME shared amount to every item row ──
            // We intentionally do NOT divide by item count. Each item row stores the
            // full PO-level amount paid, and the list view uses MAX() (not SUM()) so
            // it isn't double-counted when grouped by PO.
            if ($paid_total > 0) {
                $sync = $conn->prepare("
                    UPDATE purchase_order
                    SET total_amount_paid = ?,
                        amount_paid        = ?
                    WHERE po_number = ?
                ");
                $sync->bind_param("dds", $paid_total, $paid_total, $po_number);
                $sync->execute();
                $sync->close();
            }

            $conn->commit();

            $resolved_total = resolveTotalAmountPaid($conn, $po_number, $final_payment_status);

            echo json_encode([
                'success' => true,
                'message' => $auto_upgraded
                    ? 'Payment complete — status auto-updated to Fully Received / Fully Paid'
                    : 'Partial payment recorded successfully',
                'attachment' => $attachment_path,
                'total_paid' => $amount_paid,
                'total_amount_paid' => $resolved_total,
                'delivery_payment' => $final_payment_status,
                'auto_upgraded' => $auto_upgraded
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        $conn->close();
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Direct field updates are not allowed. Use the Save Changes button.']);
    $conn->close();
    exit;
}

// ─── GET parameters ─────────────────────────────────────────
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filter_delivery_status = isset($_GET['delivery_status']) ? trim($_GET['delivery_status']) : '';
$filter_delivery_payment = isset($_GET['delivery_payment']) ? trim($_GET['delivery_payment']) : '';

$where_conditions = [];
$params = [];
$types = '';

if (!empty($search_term)) {
    $where_conditions[] = "(po.po_number LIKE ? OR po.supplier_name LIKE ? OR po.supplier_code LIKE ?)";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $types .= 'sss';
}

if (!empty($filter_supplier)) {
    $where_conditions[] = "po.supplier_name LIKE ?";
    $params[] = "%$filter_supplier%";
    $types .= 's';
}

if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $where_conditions[] = "po.po_date BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;
    $types .= 'ss';
} elseif (!empty($filter_date_from)) {
    $where_conditions[] = "po.po_date >= ?";
    $params[] = $filter_date_from;
    $types .= 's';
} elseif (!empty($filter_date_to)) {
    $where_conditions[] = "po.po_date <= ?";
    $params[] = $filter_date_to;
    $types .= 's';
}

if (!empty($filter_delivery_status)) {
    $where_conditions[] = "po.delivery_status = ?";
    $params[] = $filter_delivery_status;
    $types .= 's';
}

if (!empty($filter_delivery_payment)) {
    $where_conditions[] = "po.delivery_payment = ?";
    $params[] = $filter_delivery_payment;
    $types .= 's';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// NOTE: total_amount_paid uses MAX() — NOT SUM() — because every item row
// stores the same full PO-level amount (see bulk_payment_update).
$sql = "SELECT
            po.po_number,
            MAX(po.supplier_code) as supplier_code,
            MAX(po.supplier_name) as supplier_name,
            MAX(po.purchase_type) as purchase_type,
            MAX(po.po_date) as po_date,
            MAX(po.expected_delivery) as expected_delivery,
            MAX(po.payment_terms) as payment_terms,
            MAX(po.currency) as currency,
            MAX(po.delivery_address) as delivery_address,
            MAX(po.delivery_mode) as delivery_mode,
            MAX(po.warranty) as warranty,
            MAX(po.remarks) as remarks,
            MAX(po.freight) as freight,
            MAX(po.status) as status,
            MAX(po.created_by) as created_by,
            MAX(po.created_at) as created_at,
            MAX(po.delivery_status) as delivery_status,
            MAX(po.delivery_payment) as delivery_payment,
            MAX(po.delivered_date) as delivered_date,
            MAX(po.received_by) as received_by,
            SUM(po.subtotal) as subtotal,
            SUM(po.total_vat) as total_vat,
            MAX(po.total_amount_paid) as total_amount_paid,
            SUM(po.net_amount_due) as net_amount_due_total,
            GROUP_CONCAT(CONCAT(po.item_code, '|', po.item, '|', po.qty_ordered, '|', IFNULL(po.qty_received, 0), '|', po.unit_cost, '|', po.total_amount, '|', IFNULL(po.total_amount_paid, 0), '|', IFNULL(po.net_amount_due, 0), '|', IFNULL(po.unit, '')) SEPARATOR '||') as items_detail
        FROM purchase_order po
        $where_clause
        GROUP BY po.po_number
        ORDER BY MAX(po.created_at) DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$purchase_orders = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items = [];
        $subtotal_amount_paid = 0;
        if ($row['items_detail']) {
            $items_raw = explode('||', $row['items_detail']);
            foreach ($items_raw as $item_raw) {
                $parts = explode('|', $item_raw);
                if (count($parts) >= 9) {
                    $qty_received = floatval($parts[3]);
                    $unit_cost = floatval($parts[4]);
                    $total_amount = floatval($parts[5]);
                    $total_amount_paid = floatval($parts[6]);
                    $net_amount_due = floatval($parts[7]);
                    $qty_ordered = floatval($parts[2]);
                    $unit = $parts[8];

                    if ($qty_received >= $qty_ordered) {
                        $amount_paid = $net_amount_due;
                    } else {
                        $amount_paid = $qty_received * $unit_cost;
                    }
                    $subtotal_amount_paid += $amount_paid;

                    $items[] = [
                        'item_code' => $parts[0],
                        'item_name' => $parts[1],
                        'qty_ordered' => $parts[2],
                        'qty_received' => $parts[3],
                        'unit_cost' => $parts[4],
                        'total_amount' => $parts[5],
                        'total_amount_paid' => $parts[6],
                        'net_amount_due' => $parts[7],
                        'unit' => $parts[8],
                        'amount_paid' => $amount_paid
                    ];
                }
            }
        }
        $row['items'] = $items;
        $row['computed_amount_paid'] = $subtotal_amount_paid;

        // ── Auto-detect delivery status ──
        $current_delivery = $row['delivery_status'];
        $preserve_explicit = in_array($current_delivery, ['Cancelled', 'Late Delivery'], true);

        if (!$preserve_explicit) {
            $totalItems = count($items);
            $fullyReceivedCount = 0;
            $anyReceived = false;

            foreach ($items as $item) {
                $qtyOrd = floatval($item['qty_ordered']);
                $qtyRec = floatval($item['qty_received']);
                if ($qtyRec > 0) {
                    $anyReceived = true;
                }
                if ($qtyOrd > 0 && $qtyRec >= $qtyOrd) {
                    $fullyReceivedCount++;
                }
            }

            if (!$anyReceived) {
                if (!in_array($current_delivery, ['Pending', 'Fully Received', 'Partially Received'], true)) {
                    $row['delivery_status'] = 'Pending';
                } elseif (empty($current_delivery)) {
                    $row['delivery_status'] = 'Pending';
                }
            } elseif ($totalItems > 0 && $fullyReceivedCount === $totalItems) {
                $row['delivery_status'] = 'Fully Received';
            } elseif ($anyReceived && $fullyReceivedCount < $totalItems) {
                $row['delivery_status'] = 'Partially Received';
            }
        }

        // ── Keep payment status consistent with delivery ──
        if (!$preserve_explicit) {
            $expected_payment = getPaymentStatusForDelivery($row['delivery_status']);
            if ($expected_payment !== null) {
                $valid_current = in_array($row['delivery_payment'], ['Pending', 'Fully Paid', 'Partially Paid', 'N/A'], true);
                if (!$valid_current || $row['delivery_payment'] !== $expected_payment) {
                    $row['delivery_payment'] = $expected_payment;
                }
            }
        } else {
            if (!in_array($row['delivery_payment'], ['Fully Paid', 'Partially Paid'], true)) {
                $row['delivery_payment'] = 'Partially Paid';
            }
        }

        $purchase_orders[] = $row;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Total Amount Paid resolution + AUTO-UPGRADE to Fully Received / Fully Paid
// ═══════════════════════════════════════════════════════════════════════
$paid_totals = [];

if (!empty($purchase_orders)) {
    $po_numbers = array_column($purchase_orders, 'po_number');
    $placeholders = implode(',', array_fill(0, count($po_numbers), '?'));
    $ph_types = str_repeat('s', count($po_numbers));

    $pay_sql = "SELECT po_number, SUM(amount_paid) AS paid_total
                FROM po_payment_history
                WHERE po_number IN ($placeholders)
                GROUP BY po_number";
    $pay_stmt = $conn->prepare($pay_sql);
    $pay_stmt->bind_param($ph_types, ...$po_numbers);
    $pay_stmt->execute();
    $pay_res = $pay_stmt->get_result();
    while ($p = $pay_res->fetch_assoc()) {
        $paid_totals[$p['po_number']] = floatval($p['paid_total']);
    }
    $pay_stmt->close();
}

foreach ($purchase_orders as &$po_ref) {
    $po_num = $po_ref['po_number'];
    $delivery_payment = $po_ref['delivery_payment'];
    $net_due = floatval($po_ref['net_amount_due_total'] ?? 0);
    $paid_from_history = $paid_totals[$po_num] ?? 0.0;

    $is_explicit = in_array($po_ref['delivery_status'], ['Cancelled', 'Late Delivery'], true);

    if (!$is_explicit
        && $net_due > 0
        && $paid_from_history > 0
        && $paid_from_history >= $net_due
    ) {
        $po_ref['delivery_status']  = 'Fully Received';
        $po_ref['delivery_payment'] = 'Fully Paid';
        $delivery_payment = 'Fully Paid';
    }

    if ($delivery_payment === 'Fully Paid') {
        $po_ref['total_amount_paid'] = $net_due;
    } elseif ($delivery_payment === 'Partially Paid') {
        $po_ref['total_amount_paid'] = $paid_from_history;
    } else {
        $po_ref['total_amount_paid'] = 0.0;
    }
}
unset($po_ref);

// Get distinct suppliers for the filter dropdown
$supplier_sql = "SELECT DISTINCT supplier_name FROM purchase_order ORDER BY supplier_name";
$supplier_result = $conn->query($supplier_sql);
$suppliers = [];
if ($supplier_result && $supplier_result->num_rows > 0) {
    while ($row = $supplier_result->fetch_assoc()) {
        $suppliers[] = $row['supplier_name'];
    }
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Created':   return 'status-created';
        case 'Approved':  return 'status-approved';
        case 'Rejected':  return 'status-rejected';
        case 'Completed': return 'status-completed';
        case 'Cancelled': return 'status-cancelled';
        default:          return 'status-pending';
    }
}

function getStatusBadgeText($status) {
    switch ($status) {
        case 'Created':   return 'Created';
        case 'Approved':  return 'Approved';
        case 'Rejected':  return 'Rejected';
        case 'Completed': return 'Completed';
        case 'Cancelled': return 'Cancelled';
        default:          return $status;
    }
}

function getDeliveryStatusBadgeClass($status) {
    switch ($status) {
        case 'Fully Received':     return 'delivery-received';
        case 'Partially Received': return 'delivery-partial';
        case 'Cancelled':          return 'delivery-cancelled';
        case 'Late Delivery':      return 'delivery-late';
        case 'Pending':            return 'delivery-pending';
        default:                   return 'delivery-pending';
    }
}

function getDeliveryPaymentBadgeClass($payment) {
    switch ($payment) {
        case 'Fully Paid':     return 'payment-paid';
        case 'Partially Paid': return 'payment-partial';
        case 'Pending':        return 'payment-pending';
        case 'N/A':            return 'payment-na';
        default:               return 'payment-pending';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders List | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <!-- <link rel="stylesheet" href="css/purchase_order.css?v=<?= time(); ?>"> -->
    <link rel="stylesheet" href="css/po_list.css?v=<?= time(); ?>">
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

<!-- Edit PO Modal -->
<div id="editPOModal" class="modal-overlay-edit">
    <div class="edit-modal">
        <div class="edit-modal-header">
            <h3><i data-lucide="edit-2" style="width:18px;height:18px;"></i> Edit Purchase Order</h3>
            <button onclick="closeEditModal()" class="btn-close-modal">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
        </div>
        <div class="edit-modal-body" id="editModalContent">
            <div style="text-align:center;padding:40px;">
                <i data-lucide="loader" style="width:32px;height:32px;animation:spin 1s linear infinite;"></i>
                <p>Loading...</p>
            </div>
        </div>
    </div>
</div>

<!-- Cancelled PO Prompt Modal -->
<div id="cancelledPOModal" class="modal-overlay-edit">
    <div class="cancelled-po-modal">
        <div class="cancelled-po-icon">
            <i data-lucide="ban" style="width:48px;height:48px;"></i>
        </div>
        <h3 class="cancelled-po-title">This PO is already Cancelled</h3>
        <p class="cancelled-po-message">
            Purchase Order <strong id="cancelledPONumber">—</strong> has been cancelled and cannot be edited here.
        </p>
        <p class="cancelled-po-hint">
            If this was a mistake, you can restore it from the Purchase Order View page.
        </p>
        <div class="cancelled-po-actions">
            <button type="button" class="btn-secondary" onclick="closeCancelledPOModal()">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
                Close
            </button>
            <a href="#" id="cancelledPORestoreBtn" class="btn-primary">
                <i data-lucide="rotate-ccw" style="width:14px;height:14px;"></i>
                Restore / View PO
            </a>
        </div>
    </div>
</div>

<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted);font-size:14px;">
                ONCALL FORWARDING CORPORATION / <a href="purchase_order.php" style="color:red; font-weight: bold; font-size: 16px;text-decoration:none;"> Purchase Order</a> / <span style="color:red; font-weight: bold; font-size: 16px;">Purchase Orders List</span>
            </span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <div class="page-header">
            <h1>
                <span class="icon-wrap"><i data-lucide="list-ordered" style="width:18px;height:18px;"></i></span>
                Purchase Orders List
            </h1>
            <div class="header-actions">
                <form method="GET" action="" style="flex: 1; min-width: 200px;">
                    <div class="search-container">
                        <i data-lucide="search"></i>
                        <input
                            type="text"
                            name="search"
                            placeholder="Search by PO #, Supplier..."
                            value="<?php echo htmlspecialchars($search_term); ?>"
                            id="searchInput"
                            autocomplete="off"
                        >
                        <button type="button" class="clear-btn <?php echo !empty($search_term) ? 'visible' : ''; ?>" id="clearSearch" title="Clear search">
                            <i data-lucide="x" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                </form>
                <a href="purchase_order.php" class="btn-primary" style="white-space: nowrap;">
                    <i data-lucide="plus" style="width:15px;height:15px;"></i> New Purchase Order
                </a>
            </div>
        </div>

        <div class="filter-section">
            <div class="filter-group">
                <label><i data-lucide="building-2" style="width:12px;height:12px;"></i> Supplier</label>
                <select id="filterSupplier" onchange="applyFilters()">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo ($filter_supplier == $supplier) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label><i data-lucide="calendar" style="width:12px;height:12px;"></i> Date From</label>
                <input type="date" id="filterDateFrom" value="<?php echo htmlspecialchars($filter_date_from); ?>" onchange="applyFilters()">
            </div>

            <div class="filter-group">
                <label><i data-lucide="calendar" style="width:12px;height:12px;"></i> Date To</label>
                <input type="date" id="filterDateTo" value="<?php echo htmlspecialchars($filter_date_to); ?>" onchange="applyFilters()">
            </div>

            <div class="filter-group">
                <label><i data-lucide="package" style="width:12px;height:12px;"></i> Delivery Status</label>
                <select id="filterDeliveryStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo ($filter_delivery_status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Fully Received" <?php echo ($filter_delivery_status == 'Fully Received') ? 'selected' : ''; ?>>Fully Received</option>
                    <option value="Partially Received" <?php echo ($filter_delivery_status == 'Partially Received') ? 'selected' : ''; ?>>Partially Received</option>
                    <option value="Cancelled" <?php echo ($filter_delivery_status == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="Late Delivery" <?php echo ($filter_delivery_status == 'Late Delivery') ? 'selected' : ''; ?>>Late Delivery</option>
                </select>
            </div>

            <div class="filter-group">
                <label><i data-lucide="credit-card" style="width:12px;height:12px;"></i> Payment Status</label>
                <select id="filterPaymentStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="Pending" <?php echo ($filter_delivery_payment == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="Fully Paid" <?php echo ($filter_delivery_payment == 'Fully Paid') ? 'selected' : ''; ?>>Fully Paid</option>
                    <option value="Partially Paid" <?php echo ($filter_delivery_payment == 'Partially Paid') ? 'selected' : ''; ?>>Partially Paid</option>
                    <option value="N/A" <?php echo ($filter_delivery_payment == 'N/A') ? 'selected' : ''; ?>>N/A</option>
                </select>
            </div>

            <div class="filter-actions">
                <button class="btn-filter" onclick="applyFilters()">
                    <i data-lucide="search" style="width:14px;height:14px;"></i> Filter
                </button>
                <button class="btn-clear" onclick="clearFilters()">
                    <i data-lucide="x" style="width:14px;height:14px;"></i> Clear
                </button>
            </div>
        </div>

        <?php if (!empty($search_term)): ?>
            <div class="search-results-info">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                (<?php echo count($purchase_orders); ?> found)
                <a href="purchase_orders_list.php" style="color: var(--accent-orange); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-card-title">
                <i data-lucide="file-text" style="width:16px;height:16px;"></i>
                Purchase Orders
                <span class="results-count" style="margin-left: auto; font-weight: 400;">
                    Showing <strong><?php echo count($purchase_orders); ?></strong> result(s)
                </span>
            </div>

            <?php if (count($purchase_orders) > 0): ?>
                <div class="table-wrapper">
                    <table id="poTable">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>PO Date</th>
                                <th>Expected Delivery</th>
                                <th>Total Amount Paid</th>
                                <th>Status</th>
                                <th>Delivery Status</th>
                                <th>Delivery Payment</th>
                                <th>Delivered Date</th>
                                <th>Received By</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchase_orders as $po): ?>
                                <tr data-po-number="<?php echo htmlspecialchars($po['po_number']); ?>" ondblclick="editPurchaseOrder('<?php echo htmlspecialchars($po['po_number']); ?>')">
                                    <td style="font-weight: 600; color: var(--accent-orange);">
                                        <?php echo htmlspecialchars($po['po_number']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($po['supplier_name']); ?>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            <?php echo htmlspecialchars($po['supplier_code']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($po['po_date'])); ?></td>
                                    <td>
                                        <?php echo $po['expected_delivery'] ? date('M d, Y', strtotime($po['expected_delivery'])) : '—'; ?>
                                    </td>
                                    <td style="font-weight: 600;">
                                        ₱<?php echo number_format($po['total_amount_paid'], 2); ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo getStatusBadgeClass($po['status']); ?>">
                                            <?php echo getStatusBadgeText($po['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo getDeliveryStatusBadgeClass($po['delivery_status']); ?>">
                                            <?php echo htmlspecialchars($po['delivery_status'] ?: 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="<?php echo getDeliveryPaymentBadgeClass($po['delivery_payment']); ?>">
                                            <?php echo htmlspecialchars($po['delivery_payment'] ?: 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $po['delivered_date'] ? date('M d, Y', strtotime($po['delivered_date'])) : '—'; ?>
                                    </td>
                                    <td>
                                        <span class="received-by-text">
                                            <?php echo htmlspecialchars($po['received_by'] ?: '—'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($po['created_by']); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($po['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i data-lucide="shopping-cart"></i>
                    <h3>No Purchase Orders Found</h3>
                    <p>
                        <?php if (!empty($search_term)): ?>
                            No results found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                            <br>
                            <a href="purchase_orders_list.php" style="color: var(--accent-orange); text-decoration: none; font-weight: 500; display: inline-block; margin-top: 8px;">
                                View all purchase orders
                            </a>
                        <?php elseif (!empty($filter_supplier) || !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_delivery_status) || !empty($filter_delivery_payment)): ?>
                            No results found with the current filters.
                            <br>
                            <button onclick="clearFilters()" class="btn-secondary" style="margin-top: 8px;">
                                Clear filters
                            </button>
                        <?php else: ?>
                            No purchase orders found. Create your first purchase order.
                        <?php endif; ?>
                    </p>
                    <?php if (empty($search_term) && empty($filter_supplier) && empty($filter_date_from) && empty($filter_date_to) && empty($filter_delivery_status) && empty($filter_delivery_payment)): ?>
                        <a href="purchase_order.php" class="btn-primary" style="margin-top: 16px; display: inline-flex;">
                            <i data-lucide="plus"></i> Create Purchase Order
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
    lucide.createIcons();

    const currentSessionUser = '<?php echo $username; ?>';
    const userRoles = <?php echo json_encode($user_roles); ?>;
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;

    const modal = document.getElementById('accessModal');

    function getPaymentStatusForDelivery(delivery) {
        switch (delivery) {
            case 'Pending':            return 'Pending';
            case 'Fully Received':     return 'Fully Paid';
            case 'Partially Received': return 'Partially Paid';
            case 'Cancelled':          return 'N/A';
            case 'Late Delivery':      return null;
            default:                   return 'Pending';
        }
    }

    function checkAccess(page) {
        if (userRoles.includes('admin')) return true;
        for (let role of userRoles) {
            if (allowedPages[role] && allowedPages[role].includes(page)) return true;
        }
        modal.style.display = 'flex';
        return false;
    }

    function closeModal() { modal.style.display = 'none'; }

    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (modal?.style.display === 'flex') closeModal();
            if (document.getElementById('editPOModal')?.classList.contains('show')) closeEditModal();
            if (document.getElementById('cancelledPOModal')?.classList.contains('show')) closeCancelledPOModal();
        }
    });

    const searchInput = document.getElementById('searchInput');
    const clearBtn = document.getElementById('clearSearch');
    const searchForm = searchInput?.closest('form');

    let searchTimeout;
    searchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.trim() === '' && window.location.search.includes('search=')) {
                window.location.href = window.location.pathname;
            } else if (this.value.trim() !== '') {
                searchForm?.submit();
            }
        }, 300);
    });

    clearBtn?.addEventListener('click', function() {
        searchInput.value = '';
        this.classList.remove('visible');
        window.location.href = window.location.pathname;
    });

    searchInput?.addEventListener('input', function() {
        if (this.value.trim() !== '') {
            clearBtn?.classList.add('visible');
        } else {
            clearBtn?.classList.remove('visible');
        }
    });

    searchInput?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (this.value.trim() !== '') searchForm?.submit();
            else window.location.href = window.location.pathname;
        }
    });

    function applyFilters() {
        const supplier = document.getElementById('filterSupplier').value;
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;
        const deliveryStatus = document.getElementById('filterDeliveryStatus').value;
        const paymentStatus = document.getElementById('filterPaymentStatus').value;
        const search = document.getElementById('searchInput')?.value || '';

        let url = window.location.pathname + '?';
        if (search) url += 'search=' + encodeURIComponent(search) + '&';
        if (supplier) url += 'supplier=' + encodeURIComponent(supplier) + '&';
        if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
        if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
        if (deliveryStatus) url += 'delivery_status=' + encodeURIComponent(deliveryStatus) + '&';
        if (paymentStatus) url += 'delivery_payment=' + encodeURIComponent(paymentStatus) + '&';

        url = url.replace(/[?&]$/, '');
        if (url === window.location.pathname + '?') url = window.location.pathname;
        window.location.href = url;
    }

    function clearFilters() {
        window.location.href = window.location.pathname;
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const activeElement = document.activeElement;
            if (activeElement && (activeElement.id === 'filterDateFrom' || activeElement.id === 'filterDateTo')) {
                applyFilters();
            }
        }
    });

    let currentEditingPO = null;
    let changedItems = {};
    let changedDeliveryFields = {};
    let hasUnsavedChanges = false;
    let originalDeliveryData = {};
    let isPartialMode = false;
    let sharedAmountPaid = 0;

    // ─── Cancelled PO prompt modal helpers ───────────────────────
    function showCancelledPOModal(poNumber) {
        const modal = document.getElementById('cancelledPOModal');
        const poLabel = document.getElementById('cancelledPONumber');
        const restoreBtn = document.getElementById('cancelledPORestoreBtn');

        if (!modal) return;

        poLabel.textContent = poNumber;
        restoreBtn.href = 'purchase_orders_view.php?po_number=' + encodeURIComponent(poNumber);

        modal.classList.add('show');
        lucide.createIcons();
    }

    function closeCancelledPOModal() {
        const modal = document.getElementById('cancelledPOModal');
        if (modal) modal.classList.remove('show');
    }

    function editPurchaseOrder(poNumber) {
        const row = document.querySelector(`tr[data-po-number="${poNumber}"]`);
        const statusCell = row ? row.cells[5] : null; // Status column
        const currentStatus = statusCell ? statusCell.textContent.trim() : '';

        if (currentStatus === 'Cancelled') {
            showCancelledPOModal(poNumber);
            return;
        }

        currentEditingPO = poNumber;
        changedItems = {};
        changedDeliveryFields = {};
        hasUnsavedChanges = false;
        originalDeliveryData = {};
        isPartialMode = false;
        sharedAmountPaid = 0;

        const modal = document.getElementById('editPOModal');
        const content = document.getElementById('editModalContent');
        modal.classList.add('show');

        fetch(`get_po_details.php?po_number=${encodeURIComponent(poNumber)}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.rows.length) {
                    content.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;">Failed to load purchase order details.</div>`;
                    return;
                }
                const po = data.rows[0];
                originalDeliveryData = {
                    delivery_status: po.delivery_status || 'Pending',
                    delivery_payment: po.delivery_payment || 'Pending',
                    delivered_date: po.delivered_date || '',
                    received_by: po.received_by || ''
                };
                isPartialMode = (po.delivery_payment === 'Partially Paid');
                renderEditForm(data.rows);
                lucide.createIcons();
                updateUnsavedBadge();
            })
            .catch(() => {
                content.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;">Network error. Please try again.</div>`;
            });
    }

    function renderEditForm(rows) {
        const po = rows[0];
        const items = rows;

        const isFullyReceived = po.delivery_status === 'Fully Received';
        const isPartiallyReceived = po.delivery_status === 'Partially Received';
        const isCancelled = po.delivery_status === 'Cancelled';
        const isLate = po.delivery_status === 'Late Delivery';

        isPartialMode = (po.delivery_payment === 'Partially Paid');

        // Compute the default shared amount paid:
        // - If partially paid, the per-item total_amount_paid already holds the SAME
        //   full PO-level amount on every row (see backend), so we take MAX() — NOT SUM().
        // - Otherwise, compute from qty (net_due if fully received, else qty * unit_cost).
        let defaultSharedAmount = 0;
        if (isPartialMode) {
            const maxExistingPaid = items.reduce(
                (acc, it) => Math.max(acc, parseFloat(it.total_amount_paid) || 0),
                0
            );
            defaultSharedAmount = maxExistingPaid;
        } else {
            items.forEach(item => {
                const qtyOrdered = parseFloat(item.qty_ordered) || 0;
                const qtyReceived = parseFloat(item.qty_received) || 0;
                const unitCost = parseFloat(item.unit_cost) || 0;
                const netAmountDue = parseFloat(item.net_amount_due) || parseFloat(item.total_amount) || 0;
                if (qtyReceived >= qtyOrdered) defaultSharedAmount += netAmountDue;
                else defaultSharedAmount += qtyReceived * unitCost;
            });
        }
        sharedAmountPaid = defaultSharedAmount;

        // Items table — NO Net Amount Due, NO per-item Amount Paid
        const itemsHtml = items.map((item, index) => {
            const qtyOrdered = parseFloat(item.qty_ordered) || 0;
            const qtyReceived = parseFloat(item.qty_received) || 0;
            const unitCost = parseFloat(item.unit_cost) || 0;

            const extraBadge = (qtyReceived > qtyOrdered) ?
                `<span class="free-badge">+${(qtyReceived - qtyOrdered).toFixed(0)} Free</span>` : '';

            const qtyReceivedDisabled = isCancelled ? 'disabled' : '';
            const qtyReceivedStyle = isCancelled ? 'background-color:#f1f5f9;cursor:not-allowed;' : '';

            return `
            <tr data-item-code="${escHtml(item.item_code)}">
                <td style="text-align:center;">${index + 1}</td>
                <td>
                    <div style="font-weight:500;">${escHtml(item.item)}</div>
                    <div style="font-size:11px;color:#64748b;">${escHtml(item.item_code)}</div>
                </td>
                <td style="text-align:center;">${escHtml(item.unit || '')}</td>
                <td style="text-align:center;font-weight:600;">${parseInt(qtyOrdered).toLocaleString()}${extraBadge}</td>
                <td>
                    <input type="number"
                           class="qty-received-input"
                           id="qty_received_${escHtml(item.item_code)}"
                           data-item-code="${escHtml(item.item_code)}"
                           data-po-number="${escHtml(po.po_number)}"
                           data-unit-cost="${unitCost}"
                           data-original-qty-ordered="${qtyOrdered}"
                           data-original-qty-received="${qtyReceived}"
                           data-net-amount-due="${parseFloat(item.net_amount_due) || parseFloat(item.total_amount) || 0}"
                           data-supplier-code="${escHtml(item.supplier_code)}"
                           data-item-name="${escHtml(item.item)}"
                           value="${qtyReceived}"
                           min="0"
                           step="1"
                           ${qtyReceivedDisabled}
                           style="${qtyReceivedStyle}"
                           onchange="markItemChanged('${escHtml(item.item_code)}', this.value)">
                </td>
            </tr>
        `}).join('');

        document.getElementById('editModalContent').innerHTML = `
            <div class="edit-section">
                <div class="edit-section-title">
                    <i data-lucide="package"></i> Delivery Information
                </div>
                <div class="form-group">
                    <label>Delivery Status</label>
                    <select id="delivery_status"
                            data-field="delivery_status"
                            data-original-value="${escHtml(po.delivery_status || 'Pending')}"
                            onchange="onDeliveryStatusChange(this.value)">
                        <option value="Pending" ${po.delivery_status == 'Pending' || !po.delivery_status ? 'selected' : ''}>Pending</option>
                        <option value="Fully Received" ${po.delivery_status == 'Fully Received' ? 'selected' : ''}>Fully Received</option>
                        <option value="Partially Received" ${po.delivery_status == 'Partially Received' ? 'selected' : ''}>Partially Received</option>
                        <option value="Cancelled" ${po.delivery_status == 'Cancelled' ? 'selected' : ''}>Cancelled</option>
                        <option value="Late Delivery" ${po.delivery_status == 'Late Delivery' ? 'selected' : ''}>Late Delivery</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Status</label>
                    <select id="delivery_payment"
                            data-field="delivery_payment"
                            data-original-value="${escHtml(po.delivery_payment || 'Pending')}"
                            onchange="onPaymentStatusChange(this.value)"
                            ${isLate ? '' : 'disabled'}>
                        <option value="Pending" ${po.delivery_payment == 'Pending' ? 'selected' : ''}>Pending</option>
                        <option value="Fully Paid" ${po.delivery_payment == 'Fully Paid' ? 'selected' : ''}>Fully Paid</option>
                        <option value="Partially Paid" ${po.delivery_payment == 'Partially Paid' ? 'selected' : ''}>Partially Paid</option>
                        <option value="N/A" ${po.delivery_payment == 'N/A' ? 'selected' : ''}>N/A</option>
                    </select>
                    ${isLate ? '<small style="color:#0369a1;font-size:11px;">For Late Delivery, choose Fully Paid or Partially Paid.</small>' : '<small style="color:#64748b;font-size:11px;">Auto-set based on Delivery Status.</small>'}
                </div>
                <div class="form-group">
                    <label>Delivered Date</label>
                    <input type="date"
                           id="delivered_date"
                           data-field="delivered_date"
                           data-original-value="${po.delivered_date || ''}"
                           value="${po.delivered_date || ''}"
                           ${isFullyReceived || isPartiallyReceived ? 'required' : ''}
                           ${isCancelled ? 'disabled' : ''}
                           onchange="markDeliveryFieldChanged('delivered_date', this.value)">
                </div>
                <div class="form-group">
                    <label>Received By</label>
                    <input type="text"
                           id="received_by"
                           data-field="received_by"
                           data-original-value="${escHtml(po.received_by || '')}"
                           value="${escHtml(po.received_by || '')}"
                           placeholder="Enter name"
                           ${(isFullyReceived || isPartiallyReceived) ? 'readonly style="background-color: #f1f5f9; cursor: not-allowed;"' : ''}
                           ${isCancelled ? 'disabled' : ''}
                           onchange="markDeliveryFieldChanged('received_by', this.value)">
                </div>

                <div class="payment-fields-row ${isPartialMode ? 'show' : ''}" id="partialPaymentFields">
                    <div style="font-weight:600;margin-bottom:8px;color:#0369a1;">
                        <i data-lucide="credit-card" style="width:14px;height:14px;"></i> Partial Payment Details
                    </div>
                    <div class="payment-fields-grid">
                        <div class="form-group" style="margin:0;">
                            <label>Payment Method</label>
                            <select id="shared_payment_method">
                                <option value="">— Select —</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Check">Check</option>
                                <option value="GCash">GCash</option>
                                <option value="PayMaya">PayMaya</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Reference No</label>
                            <input type="text" id="shared_reference_no" placeholder="OR / Ref #">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Payment Date</label>
                            <input type="date" id="payment_date" value="${new Date().toISOString().split('T')[0]}">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Supporting Attachment</label>
                            <input type="file" id="supporting_attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                            <div class="attachment-preview" id="attachmentPreview"></div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:10px;">
                        <label>Notes</label>
                        <input type="text" id="payment_notes" placeholder="Optional notes for this payment">
                    </div>
                    <p class="partial-hint">
                        💡 When Payment Status is <strong>Partially Paid</strong>, the single
                        <strong>Amount Paid</strong> field below is saved as one
                        <code>po_payment_history</code> row for the whole PO.
                    </p>
                </div>
            </div>

            <div class="edit-section">
                <div class="edit-section-title">
                    <i data-lucide="clipboard-list"></i> Items & Quantity Received
                </div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Item Description</th>
                            <th style="width:70px;text-align:center;">Unit</th>
                            <th style="width:100px;text-align:center;">Qty Ordered</th>
                            <th style="width:130px;text-align:center;" class="th-hint" title="Total cumulative quantity received. When 'Fully Received' is selected, this auto-fills to Qty Ordered.">
                                Qty Received ⓘ
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>

                <div class="shared-amount-paid-box">
                    <label for="shared_amount_paid">
                        <i data-lucide="credit-card" style="width:14px;height:14px;"></i>
                        Amount Paid <small>(applied to the whole PO)</small>
                    </label>
                    <div class="shared-amount-paid-input-wrap">
                        <span>₱</span>
                        <input type="number"
                               id="shared_amount_paid"
                               value="${defaultSharedAmount.toFixed(2)}"
                               min="0"
                               step="0.01"
                               onchange="markSharedAmountPaidChanged(this.value)">
                    </div>
                    <p class="partial-hint" style="margin-top:8px;">
                        💡 This single amount is saved as one <code>po_payment_history</code> row for this PO
                        (not per item).
                    </p>
                </div>

                <div style="margin-top: 12px; font-size: 12px; color: #64748b; background: #f8fafc; padding: 8px 12px; border-radius: 6px;">
                    <i data-lucide="info" style="width: 12px; height: 12px;"></i>
                    <strong>Qty Received</strong> is the <em>total cumulative</em> quantity received so far.
                    When Delivery Status is set to <strong>Fully Received</strong>, all items auto-fill to their ordered quantity.
                    When <strong>Partially Received</strong>, enter any quantity up to the ordered amount.
                    <span style="display: block; margin-top: 6px; color: #2563eb;">
                        💡 <strong>Stock Update:</strong> Click the Save Changes button below to update quantities and stock.
                    </span>
                </div>
            </div>

            <div class="save-section">
                <span class="unsaved-changes" id="unsavedChangesBadge">⚠️ Unsaved changes</span>
                <span class="save-status" id="saveStatus"></span>
                <button onclick="closeEditModal()" class="btn-secondary" style="padding: 8px 20px;">Close</button>
                <button onclick="saveAllChanges()" class="btn-save" id="saveAllBtn">
                    <i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes
                </button>
            </div>
        `;
        lucide.createIcons();

        const fileInput = document.getElementById('supporting_attachment');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const preview = document.getElementById('attachmentPreview');
                if (this.files && this.files[0]) {
                    preview.textContent = 'Selected: ' + this.files[0].name + ' (' + (this.files[0].size/1024).toFixed(1) + ' KB)';
                } else {
                    preview.textContent = '';
                }
                hasUnsavedChanges = true;
                updateUnsavedBadge();
            });
        }
        ['shared_payment_method', 'shared_reference_no', 'payment_date', 'payment_notes'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => { hasUnsavedChanges = true; updateUnsavedBadge(); });
                el.addEventListener('input', () => { hasUnsavedChanges = true; updateUnsavedBadge(); });
            }
        });

        updateUnsavedBadge();
    }

    function onDeliveryStatusChange(value) {
        markDeliveryFieldChanged('delivery_status', value);

        const paymentSelect = document.getElementById('delivery_payment');
        if (!paymentSelect) return;

        const expected = getPaymentStatusForDelivery(value);
        let newPaymentValue = paymentSelect.value;

        if (value === 'Late Delivery') {
            paymentSelect.disabled = false;
            if (!['Fully Paid', 'Partially Paid'].includes(paymentSelect.value)) {
                newPaymentValue = 'Partially Paid';
                paymentSelect.value = 'Partially Paid';
                markDeliveryFieldChanged('delivery_payment', 'Partially Paid');
            } else {
                newPaymentValue = paymentSelect.value;
            }
            Array.from(paymentSelect.options).forEach(opt => {
                opt.hidden = !['Fully Paid', 'Partially Paid'].includes(opt.value);
            });
        } else {
            newPaymentValue = expected;
            paymentSelect.disabled = true;
            paymentSelect.value = expected;
            markDeliveryFieldChanged('delivery_payment', expected);
            Array.from(paymentSelect.options).forEach(opt => { opt.hidden = false; });
        }

        isPartialMode = (newPaymentValue === 'Partially Paid');
        const partialFields = document.getElementById('partialPaymentFields');
        if (partialFields) {
            if (isPartialMode) partialFields.classList.add('show');
            else partialFields.classList.remove('show');
        }

        const qtyInputs = document.querySelectorAll('.qty-received-input');
        const deliveredDate = document.getElementById('delivered_date');
        const receivedBy = document.getElementById('received_by');

        if (value === 'Cancelled') {
            qtyInputs.forEach(inp => {
                inp.disabled = true;
                inp.style.backgroundColor = '#f1f5f9';
                inp.style.cursor = 'not-allowed';
            });
            if (deliveredDate) { deliveredDate.disabled = true; deliveredDate.required = false; }
            if (receivedBy) { receivedBy.disabled = true; }
        } else if (value === 'Fully Received') {
            qtyInputs.forEach(inp => {
                inp.disabled = false;
                inp.style.backgroundColor = '';
                inp.style.cursor = '';

                const itemCode = inp.getAttribute('data-item-code');
                const qtyOrdered = parseFloat(inp.getAttribute('data-original-qty-ordered')) || 0;
                const currentVal = parseFloat(inp.value) || 0;

                if (currentVal !== qtyOrdered) {
                    inp.value = qtyOrdered;
                    markItemChanged(itemCode, qtyOrdered);
                    inp.style.backgroundColor = '#fef3c7';
                }
            });

            if (deliveredDate) {
                deliveredDate.disabled = false;
                deliveredDate.required = true;
                if (!deliveredDate.value) {
                    const today = new Date().toISOString().split('T')[0];
                    deliveredDate.value = today;
                    markDeliveryFieldChanged('delivered_date', today);
                }
            }
            if (receivedBy) {
                receivedBy.disabled = false;
                receivedBy.readOnly = true;
                receivedBy.style.backgroundColor = '#f1f5f9';
                receivedBy.style.cursor = 'not-allowed';
                if (!receivedBy.value) {
                    receivedBy.value = currentSessionUser;
                    markDeliveryFieldChanged('received_by', currentSessionUser);
                }
            }
        } else if (value === 'Partially Received') {
            qtyInputs.forEach(inp => {
                inp.disabled = false;
                inp.style.backgroundColor = '';
                inp.style.cursor = '';
            });
            if (deliveredDate) {
                deliveredDate.disabled = false;
                deliveredDate.required = true;
                if (!deliveredDate.value) {
                    const today = new Date().toISOString().split('T')[0];
                    deliveredDate.value = today;
                    markDeliveryFieldChanged('delivered_date', today);
                }
            }
            if (receivedBy) {
                receivedBy.disabled = false;
                receivedBy.readOnly = true;
                receivedBy.style.backgroundColor = '#f1f5f9';
                receivedBy.style.cursor = 'not-allowed';
                if (!receivedBy.value) {
                    receivedBy.value = currentSessionUser;
                    markDeliveryFieldChanged('received_by', currentSessionUser);
                }
            }
        } else {
            qtyInputs.forEach(inp => {
                inp.disabled = false;
                inp.style.backgroundColor = '';
                inp.style.cursor = '';
            });
            if (deliveredDate) { deliveredDate.disabled = false; deliveredDate.required = false; }
            if (receivedBy) {
                receivedBy.disabled = false;
                receivedBy.readOnly = false;
                receivedBy.style.backgroundColor = '';
                receivedBy.style.cursor = '';
            }
        }

        recalcSharedAmountFromItems();

        hasUnsavedChanges = Object.keys(changedItems).length > 0 || Object.keys(changedDeliveryFields).length > 0;
        updateUnsavedBadge();
    }

    function onPaymentStatusChange(value) {
        const deliveryStatus = document.getElementById('delivery_status')?.value;
        if (deliveryStatus !== 'Late Delivery') return;
        if (!['Fully Paid', 'Partially Paid'].includes(value)) return;

        markDeliveryFieldChanged('delivery_payment', value);
        isPartialMode = (value === 'Partially Paid');

        const partialFields = document.getElementById('partialPaymentFields');
        if (partialFields) {
            if (isPartialMode) partialFields.classList.add('show');
            else partialFields.classList.remove('show');
        }

        recalcSharedAmountFromItems();

        hasUnsavedChanges = true;
        updateUnsavedBadge();
    }

    function markItemChanged(itemCode, value) {
        const inputElement = document.getElementById(`qty_received_${itemCode}`);
        const originalQty = parseFloat(inputElement.getAttribute('data-original-qty-received')) || 0;
        const newQty = parseFloat(value) || 0;

        if (originalQty !== newQty) {
            changedItems[itemCode] = changedItems[itemCode] || {
                item_code: itemCode,
                qty_received: newQty,
                original_qty: originalQty
            };
            changedItems[itemCode].qty_received = newQty;

            const row = document.querySelector(`tr[data-item-code="${itemCode}"]`);
            if (row) row.style.backgroundColor = '#fef3c7';

            recalcSharedAmountFromItems();

            hasUnsavedChanges = true;
            updateUnsavedBadge();
        } else {
            if (changedItems[itemCode] && !changedItems[itemCode].amount_paid_changed) {
                delete changedItems[itemCode];
            }
            const row = document.querySelector(`tr[data-item-code="${itemCode}"]`);
            if (row && !changedItems[itemCode]) row.style.backgroundColor = '';
            hasUnsavedChanges = Object.keys(changedItems).length > 0 || Object.keys(changedDeliveryFields).length > 0;
            updateUnsavedBadge();
        }
    }

    function markSharedAmountPaidChanged(value) {
        sharedAmountPaid = parseFloat(value) || 0;

        document.querySelectorAll('tr[data-item-code]').forEach(r => r.classList.add('payment-changed'));

        hasUnsavedChanges = true;
        updateUnsavedBadge();
    }

    // Recalculate the shared amount from all item qty + delivery/payment status
    function recalcSharedAmountFromItems() {
        const paymentStatus = document.getElementById('delivery_payment')?.value;

        // If user is in partial-payment mode, leave the input alone (it's user-editable)
        if (paymentStatus === 'Partially Paid') {
            return;
        }

        let total = 0;
        document.querySelectorAll('.qty-received-input').forEach(inp => {
            const qtyReceived = parseFloat(inp.value) || 0;
            const qtyOrdered = parseFloat(inp.getAttribute('data-original-qty-ordered')) || 0;
            const unitCost = parseFloat(inp.getAttribute('data-unit-cost')) || 0;
            const netAmountDue = parseFloat(inp.getAttribute('data-net-amount-due')) || 0;

            if (qtyReceived >= qtyOrdered && qtyOrdered > 0) total += netAmountDue;
            else total += qtyReceived * unitCost;
        });

        sharedAmountPaid = total;
        const sharedInput = document.getElementById('shared_amount_paid');
        if (sharedInput) sharedInput.value = total.toFixed(2);
    }

    function markDeliveryFieldChanged(field, value) {
        const element = document.getElementById(field);
        const originalValue = element ? (element.getAttribute('data-original-value') || '') : '';

        if (originalValue !== value) {
            changedDeliveryFields[field] = value;
            if (element) element.classList.add('delivery-field-changed');
        } else {
            delete changedDeliveryFields[field];
            if (element) element.classList.remove('delivery-field-changed');
        }

        hasUnsavedChanges = Object.keys(changedItems).length > 0 || Object.keys(changedDeliveryFields).length > 0;
        updateUnsavedBadge();
    }

    function updateUnsavedBadge() {
        const badge = document.getElementById('unsavedChangesBadge');
        if (badge) {
            const hasChanges = Object.keys(changedItems).length > 0 || Object.keys(changedDeliveryFields).length > 0 ||
                               (document.getElementById('supporting_attachment')?.files?.length > 0);
            if (hasChanges) badge.classList.add('show');
            else badge.classList.remove('show');
        }
    }

    function saveAllChanges() {
        const saveBtn = document.getElementById('saveAllBtn');
        const statusEl = document.getElementById('saveStatus');

        const hasItemChanges = Object.keys(changedItems).length > 0;
        const hasDeliveryChanges = Object.keys(changedDeliveryFields).length > 0;
        const hasFile = document.getElementById('supporting_attachment')?.files?.length > 0;
        const isCurrentlyPartial = document.getElementById('delivery_payment')?.value === 'Partially Paid';

        if (!hasItemChanges && !hasDeliveryChanges && !hasFile) {
            statusEl.textContent = 'No changes to save';
            statusEl.className = 'save-status show error';
            setTimeout(() => { statusEl.className = 'save-status'; }, 3000);
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i data-lucide="loader" style="width:16px;height:16px;animation:spin 1s linear infinite;"></i> Saving...';
        saveBtn.className = 'btn-save btn-save-saving';

        statusEl.textContent = 'Saving changes...';
        statusEl.className = 'save-status show saving';

        const poNumber = currentEditingPO;
        let promises = [];

        if (hasDeliveryChanges) {
            const deliveryData = {};
            Object.keys(changedDeliveryFields).forEach(field => {
                deliveryData[field] = changedDeliveryFields[field];
            });

            const formData = new FormData();
            formData.append('po_number', poNumber);
            formData.append('field', 'bulk_delivery_update');
            formData.append('delivery_info', JSON.stringify(deliveryData));

            promises.push(
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                }).then(r => r.json())
            );
        }

        if (hasItemChanges) {
            const itemsData = Object.values(changedItems).map(it => {
                const qtyInput = document.getElementById(`qty_received_${it.item_code}`);
                return {
                    item_code: it.item_code,
                    qty_received: qtyInput ? parseFloat(qtyInput.value) || 0 : (it.qty_received || 0)
                };
            });

            const formData = new FormData();
            formData.append('po_number', poNumber);
            formData.append('field', 'bulk_update');
            formData.append('items_update', JSON.stringify(itemsData));

            promises.push(
                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                }).then(r => r.json())
            );
        }

        // Partial payment — send ONE shared amount for the whole PO
        if (isCurrentlyPartial && (hasItemChanges || hasFile || hasDeliveryChanges)) {
            const sharedMethod = document.getElementById('shared_payment_method')?.value || '';
            const sharedRef    = document.getElementById('shared_reference_no')?.value || '';
            const payDate      = document.getElementById('payment_date')?.value || new Date().toISOString().split('T')[0];
            const notes        = document.getElementById('payment_notes')?.value || '';
            const sharedAmount = parseFloat(document.getElementById('shared_amount_paid')?.value) || 0;

            if (sharedAmount > 0 || hasFile) {
                if (!sharedMethod) {
                    statusEl.textContent = '❌ Payment Method is required for Partial payments';
                    statusEl.className = 'save-status show error';
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes';
                    saveBtn.className = 'btn-save';
                    return;
                }
                if (sharedAmount <= 0) {
                    statusEl.textContent = '❌ Amount Paid must be greater than zero';
                    statusEl.className = 'save-status show error';
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes';
                    saveBtn.className = 'btn-save';
                    return;
                }

                const payPayload = {
                    payment_method: sharedMethod,
                    reference_no: sharedRef,
                    notes: notes,
                    payment_date: payDate,
                    amount_paid: sharedAmount
                };

                const formData = new FormData();
                formData.append('po_number', poNumber);
                formData.append('field', 'bulk_payment_update');
                formData.append('payment_info', JSON.stringify(payPayload));

                const fileInput = document.getElementById('supporting_attachment');
                if (fileInput && fileInput.files[0]) {
                    formData.append('supporting_attachment', fileInput.files[0]);
                }

                promises.push(
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    }).then(r => r.json())
                );
            }
        }

        Promise.all(promises)
            .then(results => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes';
                saveBtn.className = 'btn-save';

                let allSuccess = true;
                let messages = [];
                results.forEach(data => {
                    if (!data.success) {
                        allSuccess = false;
                        messages.push(data.message || 'Unknown error');
                    }
                });

                if (allSuccess) {
                    statusEl.textContent = '✅ All changes saved successfully!';
                    statusEl.className = 'save-status show success';

                    const autoUpgraded = results.some(r => r && r.auto_upgraded === true);
                    if (autoUpgraded) {
                        showNotification('🎉 Payment complete — status auto-updated to Fully Received / Fully Paid', 'success');
                    }

                    results.forEach(data => {
                        if (data.updated_items) {
                            data.updated_items.forEach(item => {
                                const inputElement = document.getElementById(`qty_received_${item.item_code}`);
                                if (inputElement) {
                                    inputElement.setAttribute('data-original-qty-received', item.qty_received);
                                    const row = document.querySelector(`tr[data-item-code="${item.item_code}"]`);
                                    if (row) {
                                        row.style.backgroundColor = '#dcfce7';
                                        setTimeout(() => {
                                            row.style.backgroundColor = '';
                                            row.classList.remove('payment-changed');
                                        }, 1500);
                                    }
                                }
                            });
                        }
                    });

                    Object.keys(changedDeliveryFields).forEach(field => {
                        const element = document.getElementById(field);
                        if (element) {
                            element.setAttribute('data-original-value', changedDeliveryFields[field]);
                            element.classList.remove('delivery-field-changed');
                        }
                    });

                    changedItems = {};
                    changedDeliveryFields = {};
                    hasUnsavedChanges = false;
                    updateUnsavedBadge();

                    const fileInput = document.getElementById('supporting_attachment');
                    if (fileInput) {
                        fileInput.value = '';
                        const preview = document.getElementById('attachmentPreview');
                        if (preview) preview.textContent = '';
                    }

                    if (poNumber) refreshTableRow(poNumber);

                    setTimeout(() => { statusEl.className = 'save-status'; }, 4000);
                } else {
                    statusEl.textContent = '❌ Error: ' + messages.join('; ');
                    statusEl.className = 'save-status show error';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes';
                saveBtn.className = 'btn-save';
                statusEl.textContent = '❌ Network error. Please try again.';
                statusEl.className = 'save-status show error';
            });
    }

    function refreshTableRow(poNumber) {
        fetch(`get_po_details.php?po_number=${encodeURIComponent(poNumber)}&summary=true`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.rows.length) {
                    const po = data.rows[0];
                    const row = document.querySelector(`tr[data-po-number="${poNumber}"]`);
                    if (row) {
                        const statusCell = row.cells[6];
                        if (statusCell) {
                            const statusClass = getDeliveryStatusBadgeClass(po.delivery_status);
                            statusCell.innerHTML = `<span class="${statusClass}">${po.delivery_status || 'Pending'}</span>`;
                        }
                        const paymentCell = row.cells[7];
                        if (paymentCell) {
                            const paymentClass = getDeliveryPaymentBadgeClass(po.delivery_payment);
                            paymentCell.innerHTML = `<span class="${paymentClass}">${po.delivery_payment || 'Pending'}</span>`;
                        }
                        const dateCell = row.cells[8];
                        if (dateCell && po.delivered_date) {
                            dateCell.textContent = new Date(po.delivered_date).toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric'});
                        } else if (dateCell) {
                            dateCell.textContent = '—';
                        }
                        const receivedCell = row.cells[9];
                        if (receivedCell && po.received_by) {
                            receivedCell.innerHTML = `<span class="received-by-text">${escHtml(po.received_by)}</span>`;
                        } else if (receivedCell) {
                            receivedCell.innerHTML = `<span class="received-by-text">—</span>`;
                        }
                        const totalCell = row.cells[4];
                        if (totalCell && data.total_amount_paid !== undefined) {
                            totalCell.textContent = `₱${parseFloat(data.total_amount_paid).toFixed(2)}`;
                        }
                    }
                }
            })
            .catch(err => console.error('Error refreshing row:', err));
    }

    function getDeliveryStatusBadgeClass(status) {
        switch(status) {
            case 'Fully Received':     return 'delivery-received';
            case 'Partially Received': return 'delivery-partial';
            case 'Cancelled':          return 'delivery-cancelled';
            case 'Late Delivery':      return 'delivery-late';
            default:                   return 'delivery-pending';
        }
    }

    function getDeliveryPaymentBadgeClass(payment) {
        switch(payment) {
            case 'Fully Paid':     return 'payment-paid';
            case 'Partially Paid': return 'payment-partial';
            case 'Pending':        return 'payment-pending';
            case 'N/A':            return 'payment-na';
            default:               return 'payment-pending';
        }
    }

    function closeEditModal() {
        if (hasUnsavedChanges || Object.keys(changedItems).length > 0 || Object.keys(changedDeliveryFields).length > 0) {
            if (!confirm('You have unsaved changes. Are you sure you want to close without saving?')) return;
        }
        document.getElementById('editPOModal').classList.remove('show');
        currentEditingPO = null;
        changedItems = {};
        changedDeliveryFields = {};
        hasUnsavedChanges = false;
        isPartialMode = false;
        sharedAmountPaid = 0;
    }

    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 14px 24px;
            background: ${type === 'success' ? '#10b981' : '#ef4444'};
            color: white;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            z-index: 10001;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            word-wrap: break-word;
        `;
        document.body.appendChild(notification);
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (document.body.contains(notification)) document.body.removeChild(notification);
            }, 300);
        }, 4000);
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.getElementById('editPOModal').addEventListener('click', function(e) {
        if (e.target === this && currentEditingPO) closeEditModal();
    });

    document.getElementById('cancelledPOModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeCancelledPOModal();
    });
</script>
</body>
</html>