<?php
// print_issuance.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_roles = array_map('trim', explode(',', $_SESSION['user_type'] ?? 'user'));
$is_admin   = in_array('admin', $user_roles);

if (!$is_admin) {
    http_response_code(403);
    exit('Access denied.');
}

$reference_no = trim($_GET['reference_no'] ?? '');
$is_preview   = isset($_GET['preview']) && $_GET['preview'] === '1';
$auto_print   = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

if ($reference_no === '') {
    http_response_code(400);
    exit('Missing reference number.');
}

// ── Fetch header info (now includes creator's full_name) ─────────────
$header_sql = "
    SELECT ii.*,
           cm.full_name    AS customer_full_name,
           tm.plate_number AS plate_number,
           tm.brand        AS truck_brand,
           tm.model        AS truck_model,
           tm.truck_type   AS truck_type,
           au.full_name    AS created_by_full_name
    FROM item_issuance ii
    LEFT JOIN customer_masterlist cm ON cm.customer_code = ii.customer_code
    LEFT JOIN truck_masterlist    tm ON tm.truck_code    = ii.truck_code
    LEFT JOIN all_users           au ON au.username      = ii.created_by
    WHERE ii.reference_no = ?
    ORDER BY ii.id ASC
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $header_sql);
mysqli_stmt_bind_param($stmt, "s", $reference_no);
mysqli_stmt_execute($stmt);
$header = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$header) {
    http_response_code(404);
    exit('Issuance not found.');
}

// Fetch all item lines
$items_sql = "
    SELECT ii.*, im.unit_of_measure
    FROM item_issuance ii
    LEFT JOIN item_masterlist im
        ON im.item_code = ii.item_code AND im.part_number = ii.part_number
    WHERE ii.reference_no = ?
    ORDER BY ii.id ASC
";
$stmt = mysqli_prepare($conn, $items_sql);
mysqli_stmt_bind_param($stmt, "s", $reference_no);
mysqli_stmt_execute($stmt);
$items_res = mysqli_stmt_get_result($stmt);
$items = [];
while ($r = mysqli_fetch_assoc($items_res)) $items[] = $r;
mysqli_stmt_close($stmt);

$total_qty = array_sum(array_column($items, 'quantity'));

// Recipient display
if ($header['issuance_type'] === 'customer') {
    $recipient = $header['customer_full_name'] ?: $header['customer_code'];
} elseif ($header['issuance_type'] === 'truck') {
    $recipient = $header['plate_number'] ?: $header['truck_code'];
    $truck_desc = trim(implode(' · ', array_filter([
        $header['truck_brand'] ?? '',
        $header['truck_model'] ?? '',
        $header['truck_type']  ?? '',
    ])));
    if ($truck_desc !== '') $recipient .= ' — ' . $truck_desc;
} else {
    $recipient = 'Internal Use';
}

// Resolve creator's display name — prefer full_name, fall back to username
$created_by_display = !empty($header['created_by_full_name'])
    ? $header['created_by_full_name']
    : ($header['created_by'] ?: '—');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Issuance <?php echo htmlspecialchars($reference_no); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', Arial, sans-serif;
            color: #111;
            margin: 0;
            padding: 32px;
            font-size: 13px;
            background: #fff;
        }
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #111;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .doc-header h1 { margin: 0 0 4px 0; font-size: 20px; letter-spacing: 0.5px; }
        .doc-header .company { font-size: 12px; color: #555; }
        .doc-header .ref { text-align: right; }
        .doc-header .ref .label {
            font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .doc-header .ref .value { font-size: 18px; font-weight: 700; }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px 24px;
            margin-bottom: 22px;
        }
        .meta-grid .row { display: flex; gap: 8px; }
        .meta-grid .row .k { color: #555; min-width: 110px; }
        .meta-grid .row .v { font-weight: 600; }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f1f5f9;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #334155;
        }
        td.num { text-align: right; }
        td.center { text-align: center; }
        tfoot td { background: #f8fafc; font-weight: 700; }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending  { background: #fef3c7; color: #b45309; }
        .status-approved { background: #dcfce7; color: #15803d; }
        .status-rejected { background: #fee2e2; color: #b91c1c; }

        .signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin-top: 60px;
        }
        .sig-block { text-align: center; }
        .sig-line {
            border-top: 1px solid #111;
            margin-bottom: 6px;
            height: 40px;
        }
        .sig-label {
            font-size: 11px;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .footer-note {
            margin-top: 40px;
            font-size: 11px;
            color: #777;
            text-align: center;
        }

        <?php if ($is_preview): ?>
        .no-print-bar { display: none !important; }
        <?php endif; ?>

        @media print {
            body { padding: 16px; }
            .no-print { display: none !important; }
            @page { size: A4; margin: 15mm; }
        }

        .no-print-bar {
            margin-bottom: 16px;
            text-align: right;
        }
        .no-print-bar button {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .no-print-bar button:hover { background: #1d4ed8; }

        .sig-name {
    font-size: 13px;
    font-weight: 600;
    color: #111;
    text-align: center;
    min-height: 18px;
    margin-bottom: 2px;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
    </style>
</head>
<body>

<?php if (!$is_preview): ?>
<div class="no-print-bar no-print">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
</div>
<?php endif; ?>

<div class="doc-header">
    <div>
        <h1>ITEM ISSUANCE SLIP</h1>J
        <div class="company">ONCALL FORWARDING CORPORATION</div>
    </div>z`
    <div class="ref">
        <div class="label">Reference No.</div>
        <div class="value"><?php echo htmlspecialchars($header['reference_no']); ?></div>
        <div style="margin-top:6px;">
            <span class="status-badge status-<?php echo htmlspecialchars($header['status']); ?>">
                <?php echo htmlspecialchars($header['status']); ?>
            </span>
        </div>
    </div>
</div>

<div class="meta-grid">
    <div class="row"><span class="k">Date:</span><span class="v"><?php echo htmlspecialchars(date('F d, Y', strtotime($header['issuance_date']))); ?></span></div>
    <div class="row"><span class="k">Type:</span><span class="v"><?php echo htmlspecialchars(ucfirst($header['issuance_type'])); ?></span></div>
    <div class="row"><span class="k">Issued To:</span><span class="v"><?php echo htmlspecialchars($recipient); ?></span></div>
    <div class="row"><span class="k">Purpose:</span><span class="v"><?php echo htmlspecialchars($header['purpose'] ?: '—'); ?></span></div>
    <div class="row" style="grid-column: span 2;"><span class="k">Remarks:</span><span class="v"><?php echo htmlspecialchars($header['remarks'] ?: '—'); ?></span></div>
    <div class="row"><span class="k">Created By:</span><span class="v"><?php echo htmlspecialchars($created_by_display); ?></span></div>
    <div class="row"><span class="k">Created At:</span><span class="v"><?php echo htmlspecialchars(date('F d, Y H:i', strtotime($header['created_at']))); ?></span></div>
</div>

<table>
    <thead>
        <tr>
            <th style="width:40px;">#</th>
            <th style="width:110px;">Item Code</th>
            <th>Item Name</th>
            <th style="width:110px;">Part No.</th>
            <th style="width:100px;">Supplier</th>
            <th style="width:70px;" class="center">Qty</th>
            <th style="width:70px;" class="center">UOM</th>
        </tr>
    </thead>
    <tbody>
        <?php $n = 1; foreach ($items as $it): ?>
            <tr>
                <td class="center"><?php echo $n++; ?></td>
                <td><?php echo htmlspecialchars($it['item_code']); ?></td>
                <td><?php echo htmlspecialchars($it['item_name']); ?></td>
                <td><?php echo htmlspecialchars($it['part_number'] ?: '—'); ?></td>
                <td><?php echo htmlspecialchars($it['supplier_code'] ?: '—'); ?></td>
                <td class="center"><?php echo (int)$it['quantity']; ?></td>
                <td class="center"><?php echo htmlspecialchars($it['unit_of_measure'] ?: '—'); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" style="text-align:right;">TOTAL QUANTITY</td>
            <td class="center"><?php echo (int)$total_qty; ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>

<div class="signatures">
    <div class="sig-block">
        <div class="sig-name"><?php echo htmlspecialchars($created_by_display); ?></div>
        <div class="sig-line"></div>
        <div class="sig-label">Issued By</div>
    </div>
    <div class="sig-block">
        <div class="sig-name">&nbsp;</div>
        <div class="sig-line"></div>
        <div class="sig-label">Approved By</div>
    </div>
    <div class="sig-block">
        <div class="sig-name">&nbsp;</div>
        <div class="sig-line"></div>
        <div class="sig-label">Received By</div>
    </div>
</div>

<div class="footer-note">
    This document is system-generated. Reference: <?php echo htmlspecialchars($header['reference_no']); ?>
</div>

<?php if ($auto_print): ?>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
</script>
<?php endif; ?>

</body>
</html>