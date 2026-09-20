<?php

// general_settings.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

// Flash message handling
$flash = null;
if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Get user data
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id    = $_SESSION['user_id'];
$user_type  = $_SESSION['user_type'] ?? 'user';
$username   = $_SESSION['username'] ?? 'Admin';
$full_name = $_SESSION['full_name'] ?? $username;

// Convert comma-separated roles into an array
$user_roles = array_map('trim', explode(',', $user_type));

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);

// Access Control - Only admin can access this page directly
if (!$is_admin) {
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => "You don't have permission to access the General Settings page."
    ];
    header("Location: home.php");
    exit;
}

$role_display_name = getRoleDisplayName($user_roles);

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if ($action === 'add_term') {
        $terms_by = isset($_POST['terms_by']) ? trim($_POST['terms_by']) : '';
        $terms = isset($_POST['terms']) ? trim($_POST['terms']) : '';
        
        if (empty($terms_by) || empty($terms)) {
            $response = ['success' => false, 'message' => 'Please enter valid term details'];
            echo json_encode($response);
            exit;
        }
        
        // Validate: must be either a number or "COD"
        $isNumber = is_numeric($terms) && $terms > 0;
        $isCOD = strtoupper($terms) === 'COD';
        
        if (!$isNumber && !$isCOD) {
            $response = ['success' => false, 'message' => 'Term must be a number (e.g., 30) or "COD"'];
            echo json_encode($response);
            exit;
        }
        
        // If it's a number, ensure it's positive
        if ($isNumber) {
            $terms = intval($terms);
            if ($terms <= 0) {
                $response = ['success' => false, 'message' => 'Please enter a positive number'];
                echo json_encode($response);
                exit;
            }
        }
        
        // Check if term already exists for this type
        $check_sql = "SELECT id FROM general_settings WHERE terms_by = ? AND terms = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $terms_by, $terms);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This term already exists for ' . $terms_by];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        // Insert new term
        $sql = "INSERT INTO general_settings (terms_by, terms) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $terms_by, $terms);
        
        if ($stmt->execute()) {
            $response = [
                'success' => true, 
                'message' => 'Term added successfully!',
                'id' => $stmt->insert_id,
                'terms_by' => $terms_by,
                'terms' => $terms
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to add term'];
        }
        $stmt->close();
        
    } elseif ($action === 'delete_term') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid term ID'];
            echo json_encode($response);
            exit;
        }
        
        $sql = "DELETE FROM general_settings WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Term deleted successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete term'];
        }
        $stmt->close();
        
    } elseif ($action === 'update_term') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $terms = isset($_POST['terms']) ? trim($_POST['terms']) : '';
        
        if ($id <= 0 || empty($terms)) {
            $response = ['success' => false, 'message' => 'Invalid input'];
            echo json_encode($response);
            exit;
        }
        
        // Validate: must be either a number or "COD"
        $isNumber = is_numeric($terms) && $terms > 0;
        $isCOD = strtoupper($terms) === 'COD';
        
        if (!$isNumber && !$isCOD) {
            $response = ['success' => false, 'message' => 'Term must be a number (e.g., 30) or "COD"'];
            echo json_encode($response);
            exit;
        }
        
        $sql = "UPDATE general_settings SET terms = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $terms, $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Term updated successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update term'];
        }
        $stmt->close();

    } elseif ($action === 'add_special_charge') {
        $charge_kind = isset($_POST['charge_kind']) ? trim($_POST['charge_kind']) : '';
        
        if (empty($charge_kind)) {
            $response = ['success' => false, 'message' => 'Please enter a special charge name'];
            echo json_encode($response);
            exit;
        }
        
        // Check if charge kind already exists
        $check_sql = "SELECT id FROM special_charge_kinds WHERE charge_kind = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $charge_kind);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This special charge already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        // Insert new special charge
        $sql = "INSERT INTO special_charge_kinds (charge_kind) VALUES (?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $charge_kind);
        
        if ($stmt->execute()) {
            $response = [
                'success' => true, 
                'message' => 'Special charge added successfully!',
                'id' => $stmt->insert_id,
                'charge_kind' => $charge_kind
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to add special charge'];
        }
        $stmt->close();

    } elseif ($action === 'delete_special_charge') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid special charge ID'];
            echo json_encode($response);
            exit;
        }
        
        $sql = "DELETE FROM special_charge_kinds WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Special charge deleted successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete special charge'];
        }
        $stmt->close();

    } elseif ($action === 'update_special_charge') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $charge_kind = isset($_POST['charge_kind']) ? trim($_POST['charge_kind']) : '';
        
        if ($id <= 0 || empty($charge_kind)) {
            $response = ['success' => false, 'message' => 'Invalid input'];
            echo json_encode($response);
            exit;
        }
        
        // Check if new name already exists for a different record
        $check_sql = "SELECT id FROM special_charge_kinds WHERE charge_kind = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $charge_kind, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This special charge already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "UPDATE special_charge_kinds SET charge_kind = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $charge_kind, $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Special charge updated successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update special charge'];
        }
        $stmt->close();

    // ===== WORK DEPARTMENT ACTIONS =====
    } elseif ($action === 'add_work_department') {
        $work_department = isset($_POST['work_department']) ? trim($_POST['work_department']) : '';
        
        if (empty($work_department)) {
            $response = ['success' => false, 'message' => 'Please enter a work department'];
            echo json_encode($response);
            exit;
        }
        
        $work_department = strtoupper($work_department);
        
        // Check if work department already exists
        $check_sql = "SELECT id FROM work_department WHERE work_department = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $work_department);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This work department already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "INSERT INTO work_department (work_department) VALUES (?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $work_department);
        
        if ($stmt->execute()) {
            $response = [
                'success' => true, 
                'message' => 'Work department added successfully!',
                'id' => $stmt->insert_id,
                'work_department' => $work_department
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to add work department'];
        }
        $stmt->close();

    } elseif ($action === 'delete_work_department') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid work department ID'];
            echo json_encode($response);
            exit;
        }
        
        $sql = "DELETE FROM work_department WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Work department deleted successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete work department'];
        }
        $stmt->close();

    } elseif ($action === 'update_work_department') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $work_department = isset($_POST['work_department']) ? trim($_POST['work_department']) : '';
        
        if ($id <= 0 || empty($work_department)) {
            $response = ['success' => false, 'message' => 'Invalid input'];
            echo json_encode($response);
            exit;
        }
        
        $work_department = strtoupper($work_department);
        
        // Check if new name already exists for a different record
        $check_sql = "SELECT id FROM work_department WHERE work_department = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $work_department, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This work department already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "UPDATE work_department SET work_department = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $work_department, $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Work department updated successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update work department'];
        }
        $stmt->close();

    // ===== WORK LOCATION ACTIONS =====
    } elseif ($action === 'add_work_location') {
        $work_location = isset($_POST['work_location']) ? trim($_POST['work_location']) : '';
        
        if (empty($work_location)) {
            $response = ['success' => false, 'message' => 'Please enter a work location'];
            echo json_encode($response);
            exit;
        }
        
        $work_location = strtoupper($work_location);
        
        // Check if work location already exists
        $check_sql = "SELECT id FROM work_location WHERE work_location = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $work_location);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This work location already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "INSERT INTO work_location (work_location) VALUES (?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $work_location);
        
        if ($stmt->execute()) {
            $response = [
                'success' => true, 
                'message' => 'Work location added successfully!',
                'id' => $stmt->insert_id,
                'work_location' => $work_location
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to add work location'];
        }
        $stmt->close();

    } elseif ($action === 'delete_work_location') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid work location ID'];
            echo json_encode($response);
            exit;
        }
        
        $sql = "DELETE FROM work_location WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Work location deleted successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete work location'];
        }
        $stmt->close();

    } elseif ($action === 'update_work_location') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $work_location = isset($_POST['work_location']) ? trim($_POST['work_location']) : '';
        
        if ($id <= 0 || empty($work_location)) {
            $response = ['success' => false, 'message' => 'Invalid input'];
            echo json_encode($response);
            exit;
        }
        
        $work_location = strtoupper($work_location);
        
        // Check if new name already exists for a different record
        $check_sql = "SELECT id FROM work_location WHERE work_location = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $work_location, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This work location already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "UPDATE work_location SET work_location = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $work_location, $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Work location updated successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update work location'];
        }
        $stmt->close();

    // ===== WORK POSITION ACTIONS =====
    } elseif ($action === 'add_work_position') {
        $work_position = isset($_POST['work_position']) ? trim($_POST['work_position']) : '';
        
        if (empty($work_position)) {
            $response = ['success' => false, 'message' => 'Please enter a work position'];
            echo json_encode($response);
            exit;
        }
        
        $work_position = strtoupper($work_position);
        
        // Check if work position already exists
        $check_sql = "SELECT id FROM work_position WHERE work_position = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $work_position);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This work position already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "INSERT INTO work_position (work_position) VALUES (?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $work_position);
        
        if ($stmt->execute()) {
            $response = [
                'success' => true, 
                'message' => 'Work position added successfully!',
                'id' => $stmt->insert_id,
                'work_position' => $work_position
            ];
        } else {
            $response = ['success' => false, 'message' => 'Failed to add work position'];
        }
        $stmt->close();

    } elseif ($action === 'delete_work_position') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid work position ID'];
            echo json_encode($response);
            exit;
        }
        
        $sql = "DELETE FROM work_position WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Work position deleted successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to delete work position'];
        }
        $stmt->close();

    } elseif ($action === 'update_work_position') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $work_position = isset($_POST['work_position']) ? trim($_POST['work_position']) : '';
        
        if ($id <= 0 || empty($work_position)) {
            $response = ['success' => false, 'message' => 'Invalid input'];
            echo json_encode($response);
            exit;
        }
        
        $work_position = strtoupper($work_position);
        
        // Check if new name already exists for a different record
        $check_sql = "SELECT id FROM work_position WHERE work_position = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $work_position, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $response = ['success' => false, 'message' => 'This work position already exists'];
            $check_stmt->close();
            echo json_encode($response);
            exit;
        }
        $check_stmt->close();
        
        $sql = "UPDATE work_position SET work_position = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $work_position, $id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Work position updated successfully!'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update work position'];
        }
        $stmt->close();
    }
    
    echo json_encode($response);
    exit;
}

// Get all terms from database
$supplier_terms = [];
$customer_terms = [];

$sql = "SELECT id, terms_by, terms FROM general_settings ORDER BY terms_by, terms";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($row['terms_by'] === 'Supplier Term') {
            $supplier_terms[] = $row;
        } elseif ($row['terms_by'] === 'Customer Term') {
            $customer_terms[] = $row;
        }
    }
}

// Get all special charges from database
$special_charges = [];
$sql = "SELECT id, charge_kind FROM special_charge_kinds ORDER BY charge_kind ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $special_charges[] = $row;
    }
}

// Get all work departments from database
$work_departments = [];
$sql = "SELECT id, work_department FROM work_department ORDER BY work_department ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $work_departments[] = $row;
    }
}

// Get all work locations from database
$work_locations = [];
$sql = "SELECT id, work_location FROM work_location ORDER BY work_location ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $work_locations[] = $row;
    }
}

// Get all work positions from database
$work_positions = [];
$sql = "SELECT id, work_position FROM work_position ORDER BY work_position ASC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $work_positions[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Settings | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/settings.css?v=<?= time(); ?>">
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
            <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">General Settings</span></span>
        </div>
        <div class="user-profile">
            <span class="badge badge-admin"><?php echo htmlspecialchars($full_name); ?></span>
            <span style="margin-left: 10px; color: var(--text-muted);"><?php echo htmlspecialchars($username); ?></span>
        </div>
    </header>

    <div class="content-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flashMessage">
                <?= $flash['text'] ?>
            </div>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h1 style="font-size: 24px; font-weight: 600; margin: 0;">General Settings</h1>
        </div>

        <!-- Settings Container -->
        <div class="settings-container">
            <!-- Supplier Term Card -->
            <div class="settings-card" id="supplierCard">
                <div class="settings-card-header">
                    <div class="left">
                        <div class="icon supplier">
                            <i data-lucide="truck" style="width: 20px; height: 20px;"></i>
                        </div>
                        <h2>Supplier Terms</h2>
                    </div>
                    <span class="term-count" id="supplierCount"><?= count($supplier_terms) ?> terms</span>
                </div>
                <div class="settings-card-body">
                    <!-- Add Term Section -->
                    <div class="add-term-section">
                        <input 
                            type="text" 
                            id="supplierTermInput" 
                            placeholder="Enter days (e.g., 30) or COD" 
                            class="input-supplier"
                        >
                        <button class="btn-add supplier-btn" id="addSupplierBtn">
                            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                            Add Term
                        </button>
                    </div>
                    
                    <!-- Terms List -->
                    <div class="terms-list" id="supplierTermsList">
                        <?php if (empty($supplier_terms)): ?>
                            <div class="empty-state">
                                <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                                <p>No supplier terms added yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($supplier_terms as $term): ?>
                                <div class="term-item" data-id="<?= $term['id'] ?>" data-type="supplier">
                                    <div class="term-info">
                                        <span class="term-label">Term:</span>
                                        <span class="term-value supplier-value" id="supplierDisplay_<?= $term['id'] ?>">
                                            <?= htmlspecialchars($term['terms']) ?> <?= is_numeric($term['terms']) ? 'DAYS' : '' ?>
                                        </span>
                                        <input type="text" class="edit-input" id="supplierEdit_<?= $term['id'] ?>" 
                                               value="<?= htmlspecialchars($term['terms']) ?>" style="display:none;">
                                    </div>
                                    <div class="term-actions">
                                        <button class="btn-edit" onclick="editTerm(<?= $term['id'] ?>, 'supplier')">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-delete" onclick="deleteTerm(<?= $term['id'] ?>, 'supplier')">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-save" onclick="saveTerm(<?= $term['id'] ?>, 'supplier')" style="display:none;">
                                            <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-cancel" onclick="cancelEdit(<?= $term['id'] ?>, 'supplier')" style="display:none;">
                                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="supplierSuccess" class="success-message">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                        <span id="supplierSuccessText">Supplier Term updated successfully!</span>
                    </div>
                    <div class="info-note" style="margin-top: 12px;">
                        <i data-lucide="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                        Enter a number (e.g., 30) for days or "COD" for Cash on Delivery.
                    </div>
                </div>
            </div>

            <!-- Customer Term Card -->
            <div class="settings-card" id="customerCard">
                <div class="settings-card-header">
                    <div class="left">
                        <div class="icon customer">
                            <i data-lucide="users" style="width: 20px; height: 20px;"></i>
                        </div>
                        <h2>Customer Terms</h2>
                    </div>
                    <span class="term-count" id="customerCount"><?= count($customer_terms) ?> terms</span>
                </div>
                <div class="settings-card-body">
                    <!-- Add Term Section -->
                    <div class="add-term-section">
                        <input 
                            type="text" 
                            id="customerTermInput" 
                            placeholder="Enter days (e.g., 30) or COD" 
                            class="input-customer"
                        >
                        <button class="btn-add customer-btn" id="addCustomerBtn">
                            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                            Add Term
                        </button>
                    </div>
                    
                    <!-- Terms List -->
                    <div class="terms-list" id="customerTermsList">
                        <?php if (empty($customer_terms)): ?>
                            <div class="empty-state">
                                <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                                <p>No customer terms added yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($customer_terms as $term): ?>
                                <div class="term-item" data-id="<?= $term['id'] ?>" data-type="customer">
                                    <div class="term-info">
                                        <span class="term-label">Term:</span>
                                        <span class="term-value customer-value" id="customerDisplay_<?= $term['id'] ?>">
                                            <?= htmlspecialchars($term['terms']) ?> <?= is_numeric($term['terms']) ? 'DAYS' : '' ?>
                                        </span>
                                        <input type="text" class="edit-input" id="customerEdit_<?= $term['id'] ?>" 
                                               value="<?= htmlspecialchars($term['terms']) ?>" style="display:none;">
                                    </div>
                                    <div class="term-actions">
                                        <button class="btn-edit" onclick="editTerm(<?= $term['id'] ?>, 'customer')">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-delete" onclick="deleteTerm(<?= $term['id'] ?>, 'customer')">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-save" onclick="saveTerm(<?= $term['id'] ?>, 'customer')" style="display:none;">
                                            <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-cancel" onclick="cancelEdit(<?= $term['id'] ?>, 'customer')" style="display:none;">
                                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="customerSuccess" class="success-message">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                        <span id="customerSuccessText">Customer Term updated successfully!</span>
                    </div>
                    <div class="info-note" style="margin-top: 12px;">
                        <i data-lucide="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                        Enter a number (e.g., 30) for days or "COD" for Cash on Delivery.
                    </div>
                </div>
            </div>

            <!-- Special Charge Card -->
            <div class="settings-card" id="specialChargeCard">
                <div class="settings-card-header">
                    <div class="left">
                        <div class="icon special-charge">
                            <i data-lucide="hand-coins" style="width: 20px; height: 20px;"></i>
                        </div>
                        <h2>Special Charges</h2>
                    </div>
                    <span class="term-count" id="specialChargeCount"><?= count($special_charges) ?> charges</span>
                </div>
                <div class="settings-card-body">
                    <!-- Add Special Charge Section -->
                    <div class="add-term-section">
                        <input 
                            type="text" 
                            id="specialChargeInput" 
                            placeholder="Enter special charge name (e.g., Fuel Surcharge)" 
                            class="input-special-charge"
                        >
                        <button class="btn-add special-charge-btn" id="addSpecialChargeBtn">
                            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                            Add Charge
                        </button>
                    </div>
                    
                    <!-- Special Charges List -->
                    <div class="terms-list" id="specialChargesList">
                        <?php if (empty($special_charges)): ?>
                            <div class="empty-state">
                                <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                                <p>No special charges added yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($special_charges as $charge): ?>
                                <div class="term-item" data-id="<?= $charge['id'] ?>" data-type="special-charge">
                                    <div class="term-info">
                                        <span class="term-label">Charge:</span>
                                        <span class="term-value special-charge-value" id="specialChargeDisplay_<?= $charge['id'] ?>">
                                            <?= htmlspecialchars($charge['charge_kind']) ?>
                                        </span>
                                        <input type="text" class="edit-input" id="specialChargeEdit_<?= $charge['id'] ?>" 
                                               value="<?= htmlspecialchars($charge['charge_kind']) ?>" style="display:none;">
                                    </div>
                                    <div class="term-actions">
                                        <button class="btn-edit" onclick="editSpecialCharge(<?= $charge['id'] ?>)">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-delete" onclick="deleteSpecialCharge(<?= $charge['id'] ?>)">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-save" onclick="saveSpecialCharge(<?= $charge['id'] ?>)" style="display:none;">
                                            <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-cancel" onclick="cancelEditSpecialCharge(<?= $charge['id'] ?>)" style="display:none;">
                                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="specialChargeSuccess" class="success-message">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                        <span id="specialChargeSuccessText">Special charge updated successfully!</span>
                    </div>
                    <div class="info-note" style="margin-top: 12px;">
                        <i data-lucide="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                        Enter a descriptive name for the special charge (e.g., Fuel Surcharge, Handling Fee).
                    </div>
                </div>
            </div>

            <!-- Work Department Card -->
            <div class="settings-card" id="workDepartmentCard">
                <div class="settings-card-header">
                    <div class="left">
                        <div class="icon work-department">
                            <i data-lucide="building-2" style="width: 20px; height: 20px;"></i>
                        </div>
                        <h2>Work Departments</h2>
                    </div>
                    <span class="term-count" id="workDepartmentCount"><?= count($work_departments) ?> departments</span>
                </div>
                <div class="settings-card-body">
                    <div class="add-term-section">
                        <input 
                            type="text" 
                            id="workDepartmentInput" 
                            placeholder="Enter work department (e.g., OPERATIONS)" 
                            class="input-work-department"
                        >
                        <button class="btn-add work-department-btn" id="addWorkDepartmentBtn">
                            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                            Add Department
                        </button>
                    </div>
                    
                    <div class="terms-list" id="workDepartmentsList">
                        <?php if (empty($work_departments)): ?>
                            <div class="empty-state">
                                <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                                <p>No work departments added yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($work_departments as $dept): ?>
                                <div class="term-item" data-id="<?= $dept['id'] ?>" data-type="work-department">
                                    <div class="term-info">
                                        <span class="term-label">Department:</span>
                                        <span class="term-value work-department-value" id="workDepartmentDisplay_<?= $dept['id'] ?>">
                                            <?= htmlspecialchars($dept['work_department']) ?>
                                        </span>
                                        <input type="text" class="edit-input" id="workDepartmentEdit_<?= $dept['id'] ?>" 
                                               value="<?= htmlspecialchars($dept['work_department']) ?>" style="display:none;">
                                    </div>
                                    <div class="term-actions">
                                        <button class="btn-edit" onclick="editWorkDepartment(<?= $dept['id'] ?>)">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-delete" onclick="deleteWorkDepartment(<?= $dept['id'] ?>)">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-save" onclick="saveWorkDepartment(<?= $dept['id'] ?>)" style="display:none;">
                                            <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-cancel" onclick="cancelEditWorkDepartment(<?= $dept['id'] ?>)" style="display:none;">
                                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="workDepartmentSuccess" class="success-message">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                        <span id="workDepartmentSuccessText">Work department updated successfully!</span>
                    </div>
                    <div class="info-note" style="margin-top: 12px;">
                        <i data-lucide="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                        Enter a work department name (e.g., OPERATIONS, SALES, HR). Input will be converted to uppercase.
                    </div>
                </div>
            </div>

            <!-- Work Location Card -->
            <div class="settings-card" id="workLocationCard">
                <div class="settings-card-header">
                    <div class="left">
                        <div class="icon work-location">
                            <i data-lucide="map-pin" style="width: 20px; height: 20px;"></i>
                        </div>
                        <h2>Work Locations</h2>
                    </div>
                    <span class="term-count" id="workLocationCount"><?= count($work_locations) ?> locations</span>
                </div>
                <div class="settings-card-body">
                    <div class="add-term-section">
                        <input 
                            type="text" 
                            id="workLocationInput" 
                            placeholder="Enter work location (e.g., MANILA)" 
                            class="input-work-location"
                        >
                        <button class="btn-add work-location-btn" id="addWorkLocationBtn">
                            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                            Add Location
                        </button>
                    </div>
                    
                    <div class="terms-list" id="workLocationsList">
                        <?php if (empty($work_locations)): ?>
                            <div class="empty-state">
                                <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                                <p>No work locations added yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($work_locations as $loc): ?>
                                <div class="term-item" data-id="<?= $loc['id'] ?>" data-type="work-location">
                                    <div class="term-info">
                                        <span class="term-label">Location:</span>
                                        <span class="term-value work-location-value" id="workLocationDisplay_<?= $loc['id'] ?>">
                                            <?= htmlspecialchars($loc['work_location']) ?>
                                        </span>
                                        <input type="text" class="edit-input" id="workLocationEdit_<?= $loc['id'] ?>" 
                                               value="<?= htmlspecialchars($loc['work_location']) ?>" style="display:none;">
                                    </div>
                                    <div class="term-actions">
                                        <button class="btn-edit" onclick="editWorkLocation(<?= $loc['id'] ?>)">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-delete" onclick="deleteWorkLocation(<?= $loc['id'] ?>)">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-save" onclick="saveWorkLocation(<?= $loc['id'] ?>)" style="display:none;">
                                            <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-cancel" onclick="cancelEditWorkLocation(<?= $loc['id'] ?>)" style="display:none;">
                                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="workLocationSuccess" class="success-message">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                        <span id="workLocationSuccessText">Work location updated successfully!</span>
                    </div>
                    <div class="info-note" style="margin-top: 12px;">
                        <i data-lucide="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                        Enter a work location (e.g., MANILA, CEBU). Input will be converted to uppercase.
                    </div>
                </div>
            </div>

            <!-- Work Position Card -->
            <div class="settings-card" id="workPositionCard">
                <div class="settings-card-header">
                    <div class="left">
                        <div class="icon work-position">
                            <i data-lucide="briefcase" style="width: 20px; height: 20px;"></i>
                        </div>
                        <h2>Work Positions</h2>
                    </div>
                    <span class="term-count" id="workPositionCount"><?= count($work_positions) ?> positions</span>
                </div>
                <div class="settings-card-body">
                    <div class="add-term-section">
                        <input 
                            type="text" 
                            id="workPositionInput" 
                            placeholder="Enter work position (e.g., MANAGER)" 
                            class="input-work-position"
                        >
                        <button class="btn-add work-position-btn" id="addWorkPositionBtn">
                            <i data-lucide="plus" style="width: 16px; height: 16px;"></i>
                            Add Position
                        </button>
                    </div>
                    
                    <div class="terms-list" id="workPositionsList">
                        <?php if (empty($work_positions)): ?>
                            <div class="empty-state">
                                <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                                <p>No work positions added yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($work_positions as $pos): ?>
                                <div class="term-item" data-id="<?= $pos['id'] ?>" data-type="work-position">
                                    <div class="term-info">
                                        <span class="term-label">Position:</span>
                                        <span class="term-value work-position-value" id="workPositionDisplay_<?= $pos['id'] ?>">
                                            <?= htmlspecialchars($pos['work_position']) ?>
                                        </span>
                                        <input type="text" class="edit-input" id="workPositionEdit_<?= $pos['id'] ?>" 
                                               value="<?= htmlspecialchars($pos['work_position']) ?>" style="display:none;">
                                    </div>
                                    <div class="term-actions">
                                        <button class="btn-edit" onclick="editWorkPosition(<?= $pos['id'] ?>)">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-delete" onclick="deleteWorkPosition(<?= $pos['id'] ?>)">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-save" onclick="saveWorkPosition(<?= $pos['id'] ?>)" style="display:none;">
                                            <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button class="btn-cancel" onclick="cancelEditWorkPosition(<?= $pos['id'] ?>)" style="display:none;">
                                            <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="workPositionSuccess" class="success-message">
                        <i data-lucide="check-circle" style="width: 16px; height: 16px; vertical-align: middle;"></i>
                        <span id="workPositionSuccessText">Work position updated successfully!</span>
                    </div>
                    <div class="info-note" style="margin-top: 12px;">
                        <i data-lucide="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                        Enter a work position (e.g., MANAGER, STAFF). Input will be converted to uppercase.
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
    lucide.createIcons();

    // Flash message auto-dismiss
    <?php if ($flash): ?>
    setTimeout(function() {
        const flashMsg = document.getElementById('flashMessage');
        if (flashMsg) {
            flashMsg.classList.add('alert-fade-out');
            setTimeout(function() {
                window.location.reload();
            }, 500);
        } else {
            window.location.reload();
        }
    }, 3000);
    <?php endif; ?>

    // User roles from PHP
    const userRoles = <?php echo json_encode($user_roles); ?>;
    
    // Allowed pages from PHP
    const allowedPages = <?php echo json_encode($allowed_pages); ?>;
    
    // Access Denied Modal
    const modal = document.getElementById('accessModal');

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

    function closeModal() {
        modal.style.display = 'none';
    }

    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });

    // Show success message
    function showSuccess(elementId, message) {
        const successDiv = document.getElementById(elementId);
        const textSpan = document.getElementById(elementId + 'Text');
        if (textSpan) {
            textSpan.textContent = message;
        }
        successDiv.style.display = 'block';
        setTimeout(() => {
            successDiv.style.display = 'none';
        }, 3000);
    }

    // Validate term input
    function validateTerm(value) {
        value = value.trim().toUpperCase();
        if (!value) {
            return { valid: false, message: 'Please enter a term (number of days or COD).' };
        }
        
        // Check if it's a number or COD
        const isNumber = /^\d+$/.test(value);
        const isCOD = value === 'COD';
        
        if (!isNumber && !isCOD) {
            return { valid: false, message: 'Please enter a valid number (e.g., 30) or "COD".' };
        }
        
        // For numbers, validate range
        if (isNumber) {
            const numValue = parseInt(value);
            if (numValue < 1 || numValue > 999) {
                return { valid: false, message: 'Please enter a number between 1 and 999 days.' };
            }
        }
        
        return { valid: true, value: value };
    }

    // Validate special charge input
    function validateSpecialCharge(value) {
        value = value.trim();
        if (!value) {
            return { valid: false, message: 'Please enter a special charge name.' };
        }
        if (value.length > 45) {
            return { valid: false, message: 'Special charge name must be 45 characters or less.' };
        }
        return { valid: true, value: value };
    }

    // Validate work setting input (department, location, position)
    function validateWorkSetting(value, label) {
        value = value.trim();
        if (!value) {
            return { valid: false, message: 'Please enter a ' + label + '.' };
        }
        if (value.length > 45) {
            return { valid: false, message: label + ' must be 45 characters or less.' };
        }
        return { valid: true, value: value.toUpperCase() };
    }

    // Add Term
    function addTerm(type) {
        const inputId = type === 'supplier' ? 'supplierTermInput' : 'customerTermInput';
        const btnId = type === 'supplier' ? 'addSupplierBtn' : 'addCustomerBtn';
        const listId = type === 'supplier' ? 'supplierTermsList' : 'customerTermsList';
        const successId = type === 'supplier' ? 'supplierSuccess' : 'customerSuccess';
        const countId = type === 'supplier' ? 'supplierCount' : 'customerCount';
        
        const input = document.getElementById(inputId);
        const btn = document.getElementById(btnId);
        const rawValue = input.value;
        
        // Validate input
        const validation = validateTerm(rawValue);
        if (!validation.valid) {
            alert(validation.message);
            input.focus();
            return;
        }
        
        const value = validation.value;

        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Adding...';

        const formData = new FormData();
        formData.append('action', 'add_term');
        formData.append('terms_by', type === 'supplier' ? 'Supplier Term' : 'Customer Term');
        formData.append('terms', value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Add new term to the list
                const displayValue = value + (isNaN(value) ? '' : ' days');
                const termHtml = `
                    <div class="term-item" data-id="${data.id}" data-type="${type}">
                        <div class="term-info">
                            <span class="term-label">Term:</span>
                            <span class="term-value ${type}-value" id="${type}Display_${data.id}">
                                ${data.terms} ${isNaN(data.terms) ? '' : 'days'}
                            </span>
                            <input type="text" class="edit-input" id="${type}Edit_${data.id}" 
                                   value="${data.terms}" style="display:none;">
                        </div>
                        <div class="term-actions">
                            <button class="btn-edit" onclick="editTerm(${data.id}, '${type}')">
                                <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-delete" onclick="deleteTerm(${data.id}, '${type}')">
                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-save" onclick="saveTerm(${data.id}, '${type}')" style="display:none;">
                                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-cancel" onclick="cancelEdit(${data.id}, '${type}')" style="display:none;">
                                <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                            </button>
                        </div>
                    </div>
                `;
                
                const list = document.getElementById(listId);
                const emptyState = list.querySelector('.empty-state');
                if (emptyState) {
                    list.innerHTML = termHtml;
                } else {
                    list.insertAdjacentHTML('beforeend', termHtml);
                }
                
                // Update count
                const countEl = document.getElementById(countId);
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount + 1) + ' terms';
                
                // Clear input
                input.value = '';
                
                // Show success
                showSuccess(successId, data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while adding the term. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="plus" style="width: 16px; height: 16px;"></i> Add Term';
            lucide.createIcons();
        });
    }

    // Delete Term
    function deleteTerm(id, type) {
        if (!confirm('Are you sure you want to delete this term?')) {
            return;
        }
        
        const listId = type === 'supplier' ? 'supplierTermsList' : 'customerTermsList';
        const countId = type === 'supplier' ? 'supplierCount' : 'customerCount';
        const successId = type === 'supplier' ? 'supplierSuccess' : 'customerSuccess';
        
        const formData = new FormData();
        formData.append('action', 'delete_term');
        formData.append('id', id);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove term from list
                const list = document.getElementById(listId);
                const termItem = list.querySelector(`[data-id="${id}"]`);
                if (termItem) {
                    termItem.remove();
                }
                
                // Update count
                const countEl = document.getElementById(countId);
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount - 1) + ' terms';
                
                // Check if list is empty
                if (list.children.length === 0) {
                    list.innerHTML = `
                        <div class="empty-state">
                            <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                            <p>No ${type} terms added yet</p>
                        </div>
                    `;
                }
                
                showSuccess(successId, data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while deleting the term. Please try again.');
        });
    }

    // Edit Term
    function editTerm(id, type) {
        const termItem = document.querySelector(`[data-id="${id}"]`);
        const displaySpan = document.getElementById(`${type}Display_${id}`);
        const editInput = document.getElementById(`${type}Edit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');
        
        // Get the actual term value without "days" text
        let termValue = displaySpan.textContent.trim();
        // Remove "days" suffix if present
        termValue = termValue.replace(/\s*days$/, '');
        
        // Hide display, show edit
        displaySpan.style.display = 'none';
        editInput.style.display = 'block';
        editInput.value = termValue;
        
        // Show/hide buttons
        editBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
        saveBtn.style.display = 'inline-flex';
        cancelBtn.style.display = 'inline-flex';
        
        termItem.classList.add('editing');
        editInput.focus();
        editInput.select();
    }

    // Cancel Edit
    function cancelEdit(id, type) {
        const termItem = document.querySelector(`[data-id="${id}"]`);
        const displaySpan = document.getElementById(`${type}Display_${id}`);
        const editInput = document.getElementById(`${type}Edit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');
        
        // Show display, hide edit
        displaySpan.style.display = 'inline';
        editInput.style.display = 'none';
        
        // Show/hide buttons
        editBtn.style.display = 'inline-flex';
        deleteBtn.style.display = 'inline-flex';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        
        termItem.classList.remove('editing');
    }

    // Save Term
    function saveTerm(id, type) {
        const editInput = document.getElementById(`${type}Edit_${id}`);
        const displaySpan = document.getElementById(`${type}Display_${id}`);
        const successId = type === 'supplier' ? 'supplierSuccess' : 'customerSuccess';
        const rawValue = editInput.value;
        
        // Validate input
        const validation = validateTerm(rawValue);
        if (!validation.valid) {
            alert(validation.message);
            editInput.focus();
            return;
        }
        
        const value = validation.value;

        const formData = new FormData();
        formData.append('action', 'update_term');
        formData.append('id', id);
        formData.append('terms', value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update display
                displaySpan.textContent = value + (isNaN(value) ? '' : ' days');
                cancelEdit(id, type);
                showSuccess(successId, data.message);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the term. Please try again.');
        });
    }

    // ===== SPECIAL CHARGE FUNCTIONS =====

    // Add Special Charge
    function addSpecialCharge() {
        const input = document.getElementById('specialChargeInput');
        const btn = document.getElementById('addSpecialChargeBtn');
        const rawValue = input.value;
        
        const validation = validateSpecialCharge(rawValue);
        if (!validation.valid) {
            alert(validation.message);
            input.focus();
            return;
        }
        
        const value = validation.value;

        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Adding...';

        const formData = new FormData();
        formData.append('action', 'add_special_charge');
        formData.append('charge_kind', value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const termHtml = `
                    <div class="term-item" data-id="${data.id}" data-type="special-charge">
                        <div class="term-info">
                            <span class="term-label">Charge:</span>
                            <span class="term-value special-charge-value" id="specialChargeDisplay_${data.id}">
                                ${data.charge_kind}
                            </span>
                            <input type="text" class="edit-input" id="specialChargeEdit_${data.id}" 
                                   value="${data.charge_kind}" style="display:none;">
                        </div>
                        <div class="term-actions">
                            <button class="btn-edit" onclick="editSpecialCharge(${data.id})">
                                <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-delete" onclick="deleteSpecialCharge(${data.id})">
                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-save" onclick="saveSpecialCharge(${data.id})" style="display:none;">
                                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-cancel" onclick="cancelEditSpecialCharge(${data.id})" style="display:none;">
                                <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                            </button>
                        </div>
                    </div>
                `;
                
                const list = document.getElementById('specialChargesList');
                const emptyState = list.querySelector('.empty-state');
                if (emptyState) {
                    list.innerHTML = termHtml;
                } else {
                    list.insertAdjacentHTML('beforeend', termHtml);
                }
                
                // Update count
                const countEl = document.getElementById('specialChargeCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount + 1) + ' charges';
                
                input.value = '';
                showSuccess('specialChargeSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while adding the special charge. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="plus" style="width: 16px; height: 16px;"></i> Add Charge';
            lucide.createIcons();
        });
    }

    // Delete Special Charge
    function deleteSpecialCharge(id) {
        if (!confirm('Are you sure you want to delete this special charge?')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'delete_special_charge');
        formData.append('id', id);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const list = document.getElementById('specialChargesList');
                const termItem = list.querySelector(`[data-id="${id}"]`);
                if (termItem) {
                    termItem.remove();
                }
                
                const countEl = document.getElementById('specialChargeCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount - 1) + ' charges';
                
                if (list.children.length === 0) {
                    list.innerHTML = `
                        <div class="empty-state">
                            <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                            <p>No special charges added yet</p>
                        </div>
                    `;
                }
                
                showSuccess('specialChargeSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while deleting the special charge. Please try again.');
        });
    }

    // Edit Special Charge
    function editSpecialCharge(id) {
        const termItem = document.querySelector(`#specialChargesList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`specialChargeDisplay_${id}`);
        const editInput = document.getElementById(`specialChargeEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');
        
        displaySpan.style.display = 'none';
        editInput.style.display = 'block';
        editInput.value = displaySpan.textContent.trim();
        
        editBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
        saveBtn.style.display = 'inline-flex';
        cancelBtn.style.display = 'inline-flex';
        
        termItem.classList.add('editing');
        editInput.focus();
        editInput.select();
    }

    // Cancel Edit Special Charge
    function cancelEditSpecialCharge(id) {
        const termItem = document.querySelector(`#specialChargesList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`specialChargeDisplay_${id}`);
        const editInput = document.getElementById(`specialChargeEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');
        
        displaySpan.style.display = 'inline';
        editInput.style.display = 'none';
        
        editBtn.style.display = 'inline-flex';
        deleteBtn.style.display = 'inline-flex';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        
        termItem.classList.remove('editing');
    }

    // Save Special Charge
    function saveSpecialCharge(id) {
        const editInput = document.getElementById(`specialChargeEdit_${id}`);
        const displaySpan = document.getElementById(`specialChargeDisplay_${id}`);
        const rawValue = editInput.value;
        
        const validation = validateSpecialCharge(rawValue);
        if (!validation.valid) {
            alert(validation.message);
            editInput.focus();
            return;
        }
        
        const value = validation.value;

        const formData = new FormData();
        formData.append('action', 'update_special_charge');
        formData.append('id', id);
        formData.append('charge_kind', value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySpan.textContent = value;
                cancelEditSpecialCharge(id);
                showSuccess('specialChargeSuccess', data.message);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the special charge. Please try again.');
        });
    }

    // ===== WORK DEPARTMENT FUNCTIONS =====

    function addWorkDepartment() {
        const input = document.getElementById('workDepartmentInput');
        const btn = document.getElementById('addWorkDepartmentBtn');
        const validation = validateWorkSetting(input.value, 'work department');
        if (!validation.valid) {
            alert(validation.message);
            input.focus();
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Adding...';

        const formData = new FormData();
        formData.append('action', 'add_work_department');
        formData.append('work_department', validation.value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const html = `
                    <div class="term-item" data-id="${data.id}" data-type="work-department">
                        <div class="term-info">
                            <span class="term-label">Department:</span>
                            <span class="term-value work-department-value" id="workDepartmentDisplay_${data.id}">
                                ${data.work_department}
                            </span>
                            <input type="text" class="edit-input" id="workDepartmentEdit_${data.id}" 
                                   value="${data.work_department}" style="display:none;">
                        </div>
                        <div class="term-actions">
                            <button class="btn-edit" onclick="editWorkDepartment(${data.id})">
                                <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-delete" onclick="deleteWorkDepartment(${data.id})">
                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-save" onclick="saveWorkDepartment(${data.id})" style="display:none;">
                                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-cancel" onclick="cancelEditWorkDepartment(${data.id})" style="display:none;">
                                <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                            </button>
                        </div>
                    </div>
                `;
                const list = document.getElementById('workDepartmentsList');
                const emptyState = list.querySelector('.empty-state');
                if (emptyState) {
                    list.innerHTML = html;
                } else {
                    list.insertAdjacentHTML('beforeend', html);
                }
                const countEl = document.getElementById('workDepartmentCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount + 1) + ' departments';
                input.value = '';
                showSuccess('workDepartmentSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="plus" style="width: 16px; height: 16px;"></i> Add Department';
            lucide.createIcons();
        });
    }

    function deleteWorkDepartment(id) {
        if (!confirm('Are you sure you want to delete this work department?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_work_department');
        formData.append('id', id);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const list = document.getElementById('workDepartmentsList');
                const termItem = list.querySelector(`[data-id="${id}"]`);
                if (termItem) termItem.remove();
                const countEl = document.getElementById('workDepartmentCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount - 1) + ' departments';
                if (list.children.length === 0) {
                    list.innerHTML = `
                        <div class="empty-state">
                            <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                            <p>No work departments added yet</p>
                        </div>
                    `;
                }
                showSuccess('workDepartmentSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function editWorkDepartment(id) {
        const termItem = document.querySelector(`#workDepartmentsList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`workDepartmentDisplay_${id}`);
        const editInput = document.getElementById(`workDepartmentEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');

        displaySpan.style.display = 'none';
        editInput.style.display = 'block';
        editInput.value = displaySpan.textContent.trim();
        editBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
        saveBtn.style.display = 'inline-flex';
        cancelBtn.style.display = 'inline-flex';
        termItem.classList.add('editing');
        editInput.focus();
        editInput.select();
    }

    function cancelEditWorkDepartment(id) {
        const termItem = document.querySelector(`#workDepartmentsList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`workDepartmentDisplay_${id}`);
        const editInput = document.getElementById(`workDepartmentEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');

        displaySpan.style.display = 'inline';
        editInput.style.display = 'none';
        editBtn.style.display = 'inline-flex';
        deleteBtn.style.display = 'inline-flex';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        termItem.classList.remove('editing');
    }

    function saveWorkDepartment(id) {
        const editInput = document.getElementById(`workDepartmentEdit_${id}`);
        const displaySpan = document.getElementById(`workDepartmentDisplay_${id}`);
        const validation = validateWorkSetting(editInput.value, 'work department');
        if (!validation.valid) {
            alert(validation.message);
            editInput.focus();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_work_department');
        formData.append('id', id);
        formData.append('work_department', validation.value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySpan.textContent = validation.value;
                cancelEditWorkDepartment(id);
                showSuccess('workDepartmentSuccess', data.message);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    // ===== WORK LOCATION FUNCTIONS =====

    function addWorkLocation() {
        const input = document.getElementById('workLocationInput');
        const btn = document.getElementById('addWorkLocationBtn');
        const validation = validateWorkSetting(input.value, 'work location');
        if (!validation.valid) {
            alert(validation.message);
            input.focus();
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Adding...';

        const formData = new FormData();
        formData.append('action', 'add_work_location');
        formData.append('work_location', validation.value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const html = `
                    <div class="term-item" data-id="${data.id}" data-type="work-location">
                        <div class="term-info">
                            <span class="term-label">Location:</span>
                            <span class="term-value work-location-value" id="workLocationDisplay_${data.id}">
                                ${data.work_location}
                            </span>
                            <input type="text" class="edit-input" id="workLocationEdit_${data.id}" 
                                   value="${data.work_location}" style="display:none;">
                        </div>
                        <div class="term-actions">
                            <button class="btn-edit" onclick="editWorkLocation(${data.id})">
                                <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-delete" onclick="deleteWorkLocation(${data.id})">
                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-save" onclick="saveWorkLocation(${data.id})" style="display:none;">
                                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-cancel" onclick="cancelEditWorkLocation(${data.id})" style="display:none;">
                                <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                            </button>
                        </div>
                    </div>
                `;
                const list = document.getElementById('workLocationsList');
                const emptyState = list.querySelector('.empty-state');
                if (emptyState) {
                    list.innerHTML = html;
                } else {
                    list.insertAdjacentHTML('beforeend', html);
                }
                const countEl = document.getElementById('workLocationCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount + 1) + ' locations';
                input.value = '';
                showSuccess('workLocationSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="plus" style="width: 16px; height: 16px;"></i> Add Location';
            lucide.createIcons();
        });
    }

    function deleteWorkLocation(id) {
        if (!confirm('Are you sure you want to delete this work location?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_work_location');
        formData.append('id', id);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const list = document.getElementById('workLocationsList');
                const termItem = list.querySelector(`[data-id="${id}"]`);
                if (termItem) termItem.remove();
                const countEl = document.getElementById('workLocationCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount - 1) + ' locations';
                if (list.children.length === 0) {
                    list.innerHTML = `
                        <div class="empty-state">
                            <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                            <p>No work locations added yet</p>
                        </div>
                    `;
                }
                showSuccess('workLocationSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function editWorkLocation(id) {
        const termItem = document.querySelector(`#workLocationsList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`workLocationDisplay_${id}`);
        const editInput = document.getElementById(`workLocationEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');

        displaySpan.style.display = 'none';
        editInput.style.display = 'block';
        editInput.value = displaySpan.textContent.trim();
        editBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
        saveBtn.style.display = 'inline-flex';
        cancelBtn.style.display = 'inline-flex';
        termItem.classList.add('editing');
        editInput.focus();
        editInput.select();
    }

    function cancelEditWorkLocation(id) {
        const termItem = document.querySelector(`#workLocationsList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`workLocationDisplay_${id}`);
        const editInput = document.getElementById(`workLocationEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');

        displaySpan.style.display = 'inline';
        editInput.style.display = 'none';
        editBtn.style.display = 'inline-flex';
        deleteBtn.style.display = 'inline-flex';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        termItem.classList.remove('editing');
    }

    function saveWorkLocation(id) {
        const editInput = document.getElementById(`workLocationEdit_${id}`);
        const displaySpan = document.getElementById(`workLocationDisplay_${id}`);
        const validation = validateWorkSetting(editInput.value, 'work location');
        if (!validation.valid) {
            alert(validation.message);
            editInput.focus();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_work_location');
        formData.append('id', id);
        formData.append('work_location', validation.value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySpan.textContent = validation.value;
                cancelEditWorkLocation(id);
                showSuccess('workLocationSuccess', data.message);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    // ===== WORK POSITION FUNCTIONS =====

    function addWorkPosition() {
        const input = document.getElementById('workPositionInput');
        const btn = document.getElementById('addWorkPositionBtn');
        const validation = validateWorkSetting(input.value, 'work position');
        if (!validation.valid) {
            alert(validation.message);
            input.focus();
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Adding...';

        const formData = new FormData();
        formData.append('action', 'add_work_position');
        formData.append('work_position', validation.value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const html = `
                    <div class="term-item" data-id="${data.id}" data-type="work-position">
                        <div class="term-info">
                            <span class="term-label">Position:</span>
                            <span class="term-value work-position-value" id="workPositionDisplay_${data.id}">
                                ${data.work_position}
                            </span>
                            <input type="text" class="edit-input" id="workPositionEdit_${data.id}" 
                                   value="${data.work_position}" style="display:none;">
                        </div>
                        <div class="term-actions">
                            <button class="btn-edit" onclick="editWorkPosition(${data.id})">
                                <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-delete" onclick="deleteWorkPosition(${data.id})">
                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-save" onclick="saveWorkPosition(${data.id})" style="display:none;">
                                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                            </button>
                            <button class="btn-cancel" onclick="cancelEditWorkPosition(${data.id})" style="display:none;">
                                <i data-lucide="x" style="width: 14px; height: 14px;"></i>
                            </button>
                        </div>
                    </div>
                `;
                const list = document.getElementById('workPositionsList');
                const emptyState = list.querySelector('.empty-state');
                if (emptyState) {
                    list.innerHTML = html;
                } else {
                    list.insertAdjacentHTML('beforeend', html);
                }
                const countEl = document.getElementById('workPositionCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount + 1) + ' positions';
                input.value = '';
                showSuccess('workPositionSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="plus" style="width: 16px; height: 16px;"></i> Add Position';
            lucide.createIcons();
        });
    }

    function deleteWorkPosition(id) {
        if (!confirm('Are you sure you want to delete this work position?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_work_position');
        formData.append('id', id);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const list = document.getElementById('workPositionsList');
                const termItem = list.querySelector(`[data-id="${id}"]`);
                if (termItem) termItem.remove();
                const countEl = document.getElementById('workPositionCount');
                const currentCount = parseInt(countEl.textContent);
                countEl.textContent = (currentCount - 1) + ' positions';
                if (list.children.length === 0) {
                    list.innerHTML = `
                        <div class="empty-state">
                            <i data-lucide="inbox" style="width: 32px; height: 32px;"></i>
                            <p>No work positions added yet</p>
                        </div>
                    `;
                }
                showSuccess('workPositionSuccess', data.message);
                lucide.createIcons();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    function editWorkPosition(id) {
        const termItem = document.querySelector(`#workPositionsList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`workPositionDisplay_${id}`);
        const editInput = document.getElementById(`workPositionEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');

        displaySpan.style.display = 'none';
        editInput.style.display = 'block';
        editInput.value = displaySpan.textContent.trim();
        editBtn.style.display = 'none';
        deleteBtn.style.display = 'none';
        saveBtn.style.display = 'inline-flex';
        cancelBtn.style.display = 'inline-flex';
        termItem.classList.add('editing');
        editInput.focus();
        editInput.select();
    }

    function cancelEditWorkPosition(id) {
        const termItem = document.querySelector(`#workPositionsList [data-id="${id}"]`);
        const displaySpan = document.getElementById(`workPositionDisplay_${id}`);
        const editInput = document.getElementById(`workPositionEdit_${id}`);
        const editBtn = termItem.querySelector('.btn-edit');
        const deleteBtn = termItem.querySelector('.btn-delete');
        const saveBtn = termItem.querySelector('.btn-save');
        const cancelBtn = termItem.querySelector('.btn-cancel');

        displaySpan.style.display = 'inline';
        editInput.style.display = 'none';
        editBtn.style.display = 'inline-flex';
        deleteBtn.style.display = 'inline-flex';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        termItem.classList.remove('editing');
    }

    function saveWorkPosition(id) {
        const editInput = document.getElementById(`workPositionEdit_${id}`);
        const displaySpan = document.getElementById(`workPositionDisplay_${id}`);
        const validation = validateWorkSetting(editInput.value, 'work position');
        if (!validation.valid) {
            alert(validation.message);
            editInput.focus();
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_work_position');
        formData.append('id', id);
        formData.append('work_position', validation.value);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySpan.textContent = validation.value;
                cancelEditWorkPosition(id);
                showSuccess('workPositionSuccess', data.message);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }

    // ===== EVENT LISTENERS =====

    document.getElementById('addSupplierBtn').addEventListener('click', function() {
        addTerm('supplier');
    });

    document.getElementById('addCustomerBtn').addEventListener('click', function() {
        addTerm('customer');
    });

    document.getElementById('addSpecialChargeBtn').addEventListener('click', function() {
        addSpecialCharge();
    });

    document.getElementById('addWorkDepartmentBtn').addEventListener('click', function() {
        addWorkDepartment();
    });

    document.getElementById('addWorkLocationBtn').addEventListener('click', function() {
        addWorkLocation();
    });

    document.getElementById('addWorkPositionBtn').addEventListener('click', function() {
        addWorkPosition();
    });

    // Enter key support for inputs
    document.getElementById('supplierTermInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('addSupplierBtn').click();
        }
    });

    document.getElementById('customerTermInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('addCustomerBtn').click();
        }
    });

    document.getElementById('specialChargeInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('addSpecialChargeBtn').click();
        }
    });

    document.getElementById('workDepartmentInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('addWorkDepartmentBtn').click();
        }
    });

    document.getElementById('workLocationInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('addWorkLocationBtn').click();
        }
    });

    document.getElementById('workPositionInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('addWorkPositionBtn').click();
        }
    });

    // Auto-uppercase for work setting inputs
    ['workDepartmentInput', 'workLocationInput', 'workPositionInput'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }
    });

    // Input validation - allow only numbers and letters (for COD)
    document.querySelectorAll('input[type="text"]').forEach(input => {
        input.addEventListener('input', function() {
            if (this.value.trim().toUpperCase() === 'COD') {
                this.value = 'COD';
            }
        });
    });
</script>

</body>
</html>