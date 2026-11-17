<?php

// item_issuance_approval.php

session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

// Get user data
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Admin';
$full_name  = $_SESSION['full_name'] ?? $username;

$user_roles = array_map('trim', explode(',', $user_type));
$is_admin   = in_array('admin', $user_roles);

// ── ACCESS CONTROL: ONLY ADMIN CAN ACCESS THIS PAGE ──────────
if (!$is_admin) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the Items page. Admin access only."
    ];
    header("Location: home.php");
    exit;
}

$can_access_items = true;
$can_manage_items = true;

$role_display_name = getRoleDisplayName($user_roles);
$current_page = basename($_SERVER['PHP_SELF']);

// ============================================================
// HANDLE APPROVE / REJECT ACTIONS
// ============================================================
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['issuance_id'])) {
    $issuance_id = (int)$_POST['issuance_id'];
    $action      = $_POST['action'];

    $allowed_actions = ['approved', 'rejected', 'pending'];
    if (in_array($action, $allowed_actions, true)) {
        $stmt = mysqli_prepare($conn, "UPDATE item_issuance SET status = ? WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $action, $issuance_id);
            if (mysqli_stmt_execute($stmt)) {
                $message = "Issuance #" . $issuance_id . " has been marked as <strong>" . ucfirst($action) . "</strong>.";
                $message_type = 'success';
            } else {
                $message = "Failed to update status: " . mysqli_error($conn);
                $message_type = 'error';
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = 'error';
        }
    }
}

// ============================================================
// FETCH ITEM ISSUANCE RECORDS (with search & status filter)
// ============================================================
$search_term   = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$where  = [];
$params = [];
$types  = '';

if (!empty($search_term)) {
    $like = '%' . $search_term . '%';
    $where[] = "(ii.reference_no LIKE ? OR ii.item_code LIKE ? OR ii.item_name LIKE ? OR ii.part_number LIKE ? OR ii.supplier_code LIKE ? OR ii.customer_code LIKE ? OR ii.truck_code LIKE ? OR ii.purpose LIKE ? OR cm.full_name LIKE ? OR tm.plate_number LIKE ? OR tm.truck_type LIKE ? OR tm.brand LIKE ? OR tm.model LIKE ? OR im.unit_of_measure LIKE ?)";
    for ($i = 0; $i < 14; $i++) {
        $params[] = $like;
        $types   .= 's';
    }
}

if (!empty($status_filter) && in_array($status_filter, ['pending', 'approved', 'rejected'], true)) {
    $where[]  = "ii.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}

$sql = "
    SELECT
        ii.*,
        cm.full_name        AS customer_full_name,
        tm.truck_type       AS truck_type,
        tm.plate_number     AS plate_number,
        tm.brand            AS truck_brand,
        tm.model            AS truck_model,
        im.unit_of_measure  AS unit_of_measure
    FROM item_issuance ii
    LEFT JOIN customer_masterlist cm ON cm.customer_code = ii.customer_code
    LEFT JOIN truck_masterlist    tm ON tm.truck_code    = ii.truck_code
    LEFT JOIN all_users au ON au.username = ii.created_by
    LEFT JOIN item_masterlist     im ON im.item_code     = ii.item_code
";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY ii.created_at DESC, ii.id DESC";

$issuances = [];
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $issuances[] = $row;
    }
    mysqli_stmt_close($stmt);
} else {
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $issuances[] = $row;
        }
    } else {
        $message = "Query error: " . mysqli_error($conn);
        $message_type = 'error';
    }
}

// Count by status for the summary tabs
$status_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$count_res = mysqli_query($conn, "SELECT status, COUNT(*) AS cnt FROM item_issuance GROUP BY status");
if ($count_res) {
    while ($r = mysqli_fetch_assoc($count_res)) {
        if (isset($status_counts[$r['status']])) {
            $status_counts[$r['status']] = (int)$r['cnt'];
        }
    }
}
$status_counts['all'] = array_sum($status_counts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Issuance Approval | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/items.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    <style>
        .status-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .status-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .status-tab:hover { border-color: #94a3b8; }
        .status-tab.active {
            background: var(--accent-blue, #2563eb);
            border-color: var(--accent-blue, #2563eb);
            color: #fff;
        }
        .status-tab .count {
            background: rgba(0,0,0,0.08);
            border-radius: 999px;
            padding: 1px 8px;
            font-size: 12px;
        }
        .status-tab.active .count { background: rgba(255,255,255,0.25); }

        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending  { background: #fef3c7; color: #b45309; }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }

        .type-pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            background: #e0e7ff;
            color: #3730a3;
        }
        .type-customer { background: #dbeafe; color: #1d4ed8; }
        .type-truck    { background: #fef3c7; color: #b45309; }
        .type-internal { background: #e2e8f0; color: #334155; }

        .action-cell { display: flex; gap: 6px; flex-wrap: wrap; }
        .action-cell form { display: inline; margin: 0; }
        .btn-mini {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .btn-approve { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
        .btn-approve:hover { background: #bbf7d0; }
        .btn-reject  { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .btn-reject:hover  { background: #fecaca; }
        .btn-pending { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .btn-pending:hover { background: #fde68a; }
        .btn-pdf     { background: #e0e7ff; color: #3730a3; border-color: #c7d2fe; }
        .btn-pdf:hover     { background: #c7d2fe; }
        .btn-mini[disabled] { opacity: 0.45; cursor: not-allowed; }

        /* Issued-to detail block */
        .issued-to-name {
            font-weight: 600;
            color: #1e293b;
        }
        .issued-to-meta {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.35;
        }
        .issued-to-meta .chip {
            display: inline-block;
            background: #f1f5f9;
            border-radius: 4px;
            padding: 1px 6px;
            margin-right: 4px;
            font-size: 11px;
            color: #475569;
        }

        /* Quantity + unit-of-measure display */
        .qty-block {
            display: flex;
            align-items: baseline;
            gap: 4px;
        }
        .qty-value {
            font-weight: 700;
            font-size: 15px;
            color: #0f172a;
        }
        .qty-uom {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* ── PDF Preview Modal ────────────────────────────────── */
        .pdf-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .pdf-modal-overlay.active {
            display: flex;
        }
        .pdf-modal {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 960px;
            height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0,0,0,0.35);
        }
        .pdf-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .pdf-modal-header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }
        .pdf-modal-header .ref {
            font-size: 12px;
            color: #64748b;
            margin-left: 8px;
            font-weight: 500;
        }
        .pdf-modal-actions {
            display: flex;
            gap: 8px;
        }
        .pdf-modal-actions button {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .pdf-btn-download {
            background: var(--accent-blue, #2563eb);
            color: #fff;
        }
        .pdf-btn-download:hover { background: #1d4ed8; }
        .pdf-btn-close {
            background: #f1f5f9;
            color: #475569;
            border-color: #e2e8f0;
        }
        .pdf-btn-close:hover { background: #e2e8f0; }

        .pdf-modal-body {
            flex: 1;
            background: #475569;
            position: relative;
            overflow: hidden;
        }
        .pdf-modal-body iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: #fff;
        }
        .pdf-loading {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            gap: 12px;
            font-size: 14px;
        }
        .pdf-loading .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid rgba(255,255,255,0.25);
            border-top-color: #fff;
            border-radius: 50%;
            animation: pdfSpin 0.9s linear infinite;
        }
        @keyframes pdfSpin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<!-- Include Sidebar -->
<?php include 'sidebar.php'; ?>

<main class="main-content">
    <header>
        <div class="breadcrumb">
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Item Issuance Approval</span></span>
        </div>
        <div class="user-profile">
            <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>" id="alertMessage">
                <span class="alert-content"><?php echo $message; ?></span>
                <button class="alert-close" onclick="dismissAlert()">&times;</button>
                <div class="alert-timer"></div>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="font-size: 26px; font-weight: 600; margin: 0;">Item Issuance Approval</h1>
                <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 14px;">
                    Review and approve item issuance requests.
                </p>
            </div>

            <form method="GET" action="" style="min-width: 260px;">
                <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="search-container" style="position: relative; display: flex; align-items: center;">
                    <i data-lucide="search" style="position:absolute; left:12px; width:16px; height:16px; color:#94a3b8;"></i>
                    <input
                        type="text"
                        name="search"
                        placeholder="Search reference, item, customer, plate, unit..."
                        value="<?php echo htmlspecialchars($search_term); ?>"
                        id="searchInput"
                        autocomplete="off"
                        style="width:100%; padding:10px 36px 10px 36px; border:1px solid #e2e8f0; border-radius:8px; font-size:14px;"
                    >
                    <?php if (!empty($search_term)): ?>
                        <a href="?<?php echo $status_filter ? 'status_filter=' . urlencode($status_filter) : ''; ?>"
                           style="position:absolute; right:10px; color:#94a3b8; text-decoration:none; font-size:18px; line-height:1;">&times;</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Status filter tabs -->
        <div class="status-tabs">
            <a href="?<?php echo $search_term ? 'search=' . urlencode($search_term) : ''; ?>"
               class="status-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">
                <i data-lucide="list" style="width:14px;"></i> All
                <span class="count"><?php echo $status_counts['all']; ?></span>
            </a>
            <a href="?status_filter=pending<?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>"
               class="status-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                <i data-lucide="clock" style="width:14px;"></i> Pending
                <span class="count"><?php echo $status_counts['pending']; ?></span>
            </a>
            <a href="?status_filter=approved<?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>"
               class="status-tab <?php echo $status_filter === 'approved' ? 'active' : ''; ?>">
                <i data-lucide="check-circle" style="width:14px;"></i> Approved
                <span class="count"><?php echo $status_counts['approved']; ?></span>
            </a>
            <a href="?status_filter=rejected<?php echo $search_term ? '&search=' . urlencode($search_term) : ''; ?>"
               class="status-tab <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>">
                <i data-lucide="x-circle" style="width:14px;"></i> Rejected
                <span class="count"><?php echo $status_counts['rejected']; ?></span>
            </a>
        </div>

        <?php if (!empty($search_term)): ?>
            <div class="search-results-info" style="margin-bottom:16px; font-size:14px; color:#475569;">
                Showing results for "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                (<?php echo count($issuances); ?> found)
                <a href="<?php echo $status_filter ? '?status_filter=' . urlencode($status_filter) : '?'; ?>"
                   style="color: var(--accent-blue, #2563eb); text-decoration: none; margin-left: 8px; font-weight: 500;">
                    Clear search
                </a>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Ref No.</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Issued To</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($issuances)): ?>
                        <?php foreach ($issuances as $row): ?>
                            <?php
                                $status = $row['status'] ?? 'pending';
                                $type   = $row['issuance_type'] ?? 'internal';
                                $typeClass = 'type-' . $type;
                                $uom    = $row['unit_of_measure'] ?? '';
                            ?>
                            <tr>
                                <td style="font-weight:600; color:var(--accent-blue, #2563eb);">
                                    <?php echo htmlspecialchars($row['reference_no'] ?: '-'); ?>
                                </td>
                                <td>
                                    <div style="font-weight:500;"><?php echo htmlspecialchars($row['item_name'] ?: '-'); ?></div>
                                    <div style="font-size:12px; color:var(--text-muted);">
                                        <?php echo htmlspecialchars($row['item_code'] ?: ''); ?>
                                        <?php if (!empty($row['part_number'])): ?>
                                            &middot; PN: <?php echo htmlspecialchars($row['part_number']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="qty-block">
                                        <span class="qty-value"><?php echo (int)$row['quantity']; ?></span>
                                        <?php if (!empty($uom)): ?>
                                            <span class="qty-uom"><?php echo htmlspecialchars($uom); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($type === 'customer'): ?>
                                        <div class="issued-to-name">
                                            <?php
                                                echo htmlspecialchars(
                                                    $row['customer_full_name']
                                                    ?: ($row['customer_code'] ?: 'Unknown Customer')
                                                );
                                            ?>
                                        </div>
                                        <?php if (!empty($row['customer_code']) && !empty($row['customer_full_name'])): ?>
                                            <div class="issued-to-meta">
                                                <span class="chip"><?php echo htmlspecialchars($row['customer_code']); ?></span>
                                            </div>
                                        <?php endif; ?>

                                    <?php elseif ($type === 'truck'): ?>
                                        <div class="issued-to-name">
                                            <?php echo htmlspecialchars($row['plate_number'] ?: ($row['truck_code'] ?: 'Unknown Truck')); ?>
                                        </div>
                                        <div class="issued-to-meta">
                                            <?php if (!empty($row['truck_code'])): ?>
                                                <span class="chip"><?php echo htmlspecialchars($row['truck_code']); ?></span>
                                            <?php endif; ?>
                                            <?php
                                                $truck_desc_parts = [];
                                                if (!empty($row['truck_brand']))  $truck_desc_parts[] = $row['truck_brand'];
                                                if (!empty($row['truck_model']))  $truck_desc_parts[] = $row['truck_model'];
                                                if (!empty($row['truck_type']))   $truck_desc_parts[] = $row['truck_type'];
                                                echo htmlspecialchars(implode(' · ', $truck_desc_parts));
                                            ?>
                                        </div>

                                    <?php else: ?>
                                        <div class="issued-to-name">Internal</div>
                                    <?php endif; ?>

                                    <?php if (!empty($row['supplier_code'])): ?>
                                        <div class="issued-to-meta">
                                            Supplier: <?php echo htmlspecialchars($row['supplier_code']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="type-pill <?php echo $typeClass; ?>">
                                        <?php echo htmlspecialchars($type); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $row['issuance_date'] ? htmlspecialchars(date('M d, Y', strtotime($row['issuance_date']))) : '-'; ?>
                                </td>
                                <td style="max-width:200px;">
                                    <div><?php echo htmlspecialchars($row['purpose'] ?: '-'); ?></div>
                                    <?php if (!empty($row['remarks'])): ?>
                                        <div style="font-size:12px; color:var(--text-muted);">
                                            <?php echo htmlspecialchars($row['remarks']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill status-<?php echo htmlspecialchars($status); ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-cell">
                                        <?php if ($status !== 'approved'): ?>
                                            <form method="POST" onsubmit="return confirm('Approve this issuance?');">
                                                <input type="hidden" name="issuance_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="action" value="approved">
                                                <button type="submit" class="btn-mini btn-approve">
                                                    <i data-lucide="check" style="width:13px;"></i> Approve
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($status !== 'rejected'): ?>
                                            <form method="POST" onsubmit="return confirm('Reject this issuance?');">
                                                <input type="hidden" name="issuance_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="action" value="rejected">
                                                <button type="submit" class="btn-mini btn-reject">
                                                    <i data-lucide="x" style="width:13px;"></i> Reject
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($status !== 'pending'): ?>
                                            <form method="POST" onsubmit="return confirm('Reset this issuance to pending?');">
                                                <input type="hidden" name="issuance_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="action" value="pending">
                                                <button type="submit" class="btn-mini btn-pending">
                                                    <i data-lucide="rotate-ccw" style="width:13px;"></i> Reset
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- PDF Preview button -->
                                        <button type="button"
                                                class="btn-mini btn-pdf"
                                                onclick="openPdfPreview('<?php echo htmlspecialchars($row['reference_no'], ENT_QUOTES); ?>')">
                                            <i data-lucide="file-text" style="width:13px;"></i> PDF
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                                <?php if (!empty($search_term)): ?>
                                    No issuance records found matching "<strong><?php echo htmlspecialchars($search_term); ?></strong>"
                                <?php elseif (!empty($status_filter)): ?>
                                    No <strong><?php echo htmlspecialchars($status_filter); ?></strong> issuance records found.
                                <?php else: ?>
                                    No item issuance records found.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- ── PDF Preview Modal ─────────────────────────────────────── -->
<div class="pdf-modal-overlay" id="pdfModal">
    <div class="pdf-modal">
        <div class="pdf-modal-header">
            <div>
                <h2 style="display:inline;">Issuance Preview</h2>
                <span class="ref" id="pdfModalRef"></span>
            </div>
            <div class="pdf-modal-actions">
                <button type="button" class="pdf-btn-download" onclick="downloadPdf()">
                    <i data-lucide="download" style="width:15px;"></i> Save as PDF
                </button>
                <button type="button" class="pdf-btn-close" onclick="closePdfPreview()">
                    <i data-lucide="x" style="width:15px;"></i> Close
                </button>
            </div>
        </div>
        <div class="pdf-modal-body">
            <div class="pdf-loading" id="pdfLoading">
                <div class="spinner"></div>
                <div>Loading preview…</div>
            </div>
            <iframe id="pdfFrame" title="Issuance PDF Preview"></iframe>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    const userRoles = <?php echo json_encode($user_roles); ?>;
    const canManageItems = true;

    // ── Alert Message Auto-hide ────────────────────────────────
    function dismissAlert() {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            alert.classList.add('fade-out');
            setTimeout(() => { alert.style.display = 'none'; }, 500);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            const isSuccess = alert.classList.contains('alert-success');
            setTimeout(() => {
                alert.classList.add('fade-out');
                setTimeout(() => {
                    alert.style.display = 'none';
                    if (isSuccess) {
                        window.location.href = window.location.pathname + window.location.search;
                    }
                }, 500);
            }, 3000);
        }
    });

    // ── Search: auto-submit on Enter, uppercase as typed ──────
    const searchInput = document.getElementById('searchInput');
    searchInput?.addEventListener('input', function () {
        const start = this.selectionStart;
        const end = this.selectionEnd;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(start, end);
    });

    searchInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            this.closest('form').submit();
        }
    });

    // ── PDF Preview ───────────────────────────────────────────
    let currentPdfRef = '';

    function openPdfPreview(referenceNo) {
        if (!referenceNo || referenceNo.trim() === '' || referenceNo === '-') {
            alert('No reference number for this record. Cannot generate preview.');
            return;
        }

        currentPdfRef = referenceNo;

        const modal     = document.getElementById('pdfModal');
        const frame     = document.getElementById('pdfFrame');
        const loading   = document.getElementById('pdfLoading');
        const refLabel  = document.getElementById('pdfModalRef');

        refLabel.textContent = referenceNo;
        loading.style.display = 'flex';
        frame.src = 'about:blank';

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Build the preview URL — the print page is print-styled already
        const url = 'print_issuance.php?reference_no=' + encodeURIComponent(referenceNo) + '&preview=1';

        // Give the modal a moment to render before loading the iframe
        setTimeout(() => {
            frame.src = url;
        }, 80);

        frame.onload = function () {
            loading.style.display = 'none';
        };

        // Safety: hide loader if onload never fires (rare, but possible)
        setTimeout(() => {
            if (loading.style.display !== 'none') {
                loading.style.display = 'none';
            }
        }, 4000);

        lucide.createIcons();
    }

    function closePdfPreview() {
        const modal = document.getElementById('pdfModal');
        const frame = document.getElementById('pdfFrame');
        modal.classList.remove('active');
        frame.src = 'about:blank';
        document.body.style.overflow = '';
    }

    function downloadPdf() {
        if (!currentPdfRef) return;
        // The print page has @media print rules; opening it and calling print()
        // lets the user "Save as PDF" from the browser's print dialog.
        const w = window.open(
            'print_issuance.php?reference_no=' + encodeURIComponent(currentPdfRef) + '&autoprint=1',
            '_blank',
            'width=900,height=700'
        );
        if (!w) {
            alert('Please allow pop-ups for this site to save as PDF.');
        }
    }

    // Close on overlay click
    document.getElementById('pdfModal')?.addEventListener('click', function (e) {
        if (e.target === this) closePdfPreview();
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('pdfModal');
            if (modal?.classList.contains('active')) closePdfPreview();
        }
    });

    window.openPdfPreview  = openPdfPreview;
    window.closePdfPreview = closePdfPreview;
    window.downloadPdf     = downloadPdf;
    window.dismissAlert    = dismissAlert;
</script>
</body>
</html>