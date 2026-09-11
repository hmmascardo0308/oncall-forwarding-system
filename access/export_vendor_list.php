<?php
// export_vendor_list.php
session_start();
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? 'user';
$username  = $_SESSION['username'] ?? 'Guest';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role
$is_admin = in_array('admin', $user_roles);
$base_user_type = $is_admin ? 'admin' : 'user';

// Enforce page access
$current_page = basename($_SERVER['PHP_SELF']);
requireAccess($user_roles, $current_page, $allowed_pages);

// Get filter parameters (same as vendor_list.php)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$sort_by = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'supplier_name';
$sort_order = isset($_GET['sort_order']) ? trim($_GET['sort_order']) : 'ASC';

// Build query for suppliers
$query = "SELECT * FROM supplier_lists WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (supplier_code LIKE ? OR supplier_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR phone_number LIKE ? OR tin LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssssss";
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

// Add sorting
$allowed_sort = ['supplier_code', 'supplier_name', 'status', 'contact_person', 'payment_terms', 'vat_type', 'tin'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'supplier_name';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
$query .= " ORDER BY $sort_by $sort_order";

// Execute query
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($query);
    }
} else {
    $result = $conn->query($query);
}

$suppliers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $suppliers[] = $row;
    }
}

// Function to calculate supplier balance from purchase orders
function getSupplierBalance($conn, $supplier_code) {
    $query = "
        SELECT 
            SUM(net_amount_due) AS total_balance
        FROM purchase_order
        WHERE supplier_code = ?
        AND delivery_status IN ('Received', 'Delivered')
        AND delivery_payment IN ('Pending', 'Partial')
        AND status IN ('Created', 'Approved')
    ";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $supplier_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return floatval($row['total_balance'] ?? 0);
        }
    }
    return 0;
}

// Function to get status text
function getStatusText($status) {
    return $status ?: 'N/A';
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="vendor_list_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Create the Excel output
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
echo '<head>';
echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Vendors</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
echo '<style>
    th {
        background-color: #4CAF50;
        color: white;
        font-weight: bold;
        padding: 8px;
        border: 1px solid #000;
        text-align: left;
    }
    td {
        padding: 6px 8px;
        border: 1px solid #ccc;
        text-align: left;
    }
    .balance-due {
        color: #dc3545;
        font-weight: bold;
    }
    .balance-zero {
        color: #28a745;
        font-weight: bold;
    }
    .status-active {
        color: #28a745;
        font-weight: bold;
    }
    .status-inactive {
        color: #dc3545;
        font-weight: bold;
    }
    .status-pending {
        color: #ffc107;
        font-weight: bold;
    }
</style>';
echo '</head>';
echo '<body>';
echo '<table>';

// Table Header
echo '<thead>';
echo '<tr>';
echo '<th>Code</th>';
echo '<th>Supplier Name</th>';
echo '<th>Payment Terms</th>';
echo '<th>VAT Type</th>';
echo '<th>TIN</th>';
echo '<th>Contact Person</th>';
echo '<th>Email</th>';
echo '<th>Phone</th>';
echo '<th>Balance</th>';
echo '<th>Status</th>';
echo '</tr>';
echo '</thead>';

// Table Body
echo '<tbody>';
if (count($suppliers) > 0) {
    foreach ($suppliers as $supplier) {
        $balance = getSupplierBalance($conn, $supplier['supplier_code']);
        $balance_class = $balance > 0 ? 'balance-due' : 'balance-zero';
        $balance_display = '₱' . number_format($balance, 2);
        
        // Status class for styling
        $status = strtolower($supplier['status'] ?? '');
        $status_class = 'status-';
        if (strpos($status, 'active') !== false) {
            $status_class .= 'active';
        } elseif (strpos($status, 'inactive') !== false) {
            $status_class .= 'inactive';
        } elseif (strpos($status, 'pending') !== false || strpos($status, 'review') !== false) {
            $status_class .= 'pending';
        } else {
            $status_class = '';
        }
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($supplier['supplier_code'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['supplier_name'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['payment_terms'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['vat_type'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['tin'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['contact_person'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['email'] ?: 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($supplier['phone_number'] ?: 'N/A') . '</td>';
        echo '<td class="' . $balance_class . '">' . $balance_display . '</td>';
        echo '<td class="' . $status_class . '">' . htmlspecialchars($supplier['status'] ?: 'N/A') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr>';
    echo '<td colspan="10" style="text-align: center; padding: 20px;">No vendors found</td>';
    echo '</tr>';
}
echo '</tbody>';
echo '</table>';
echo '</body>';
echo '</html>';

// Close database connection
$conn->close();
exit;
?>