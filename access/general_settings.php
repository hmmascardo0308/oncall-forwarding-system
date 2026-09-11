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

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

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

// Function to get display name for roles (for the badge) - Now using centralized getRoleDisplayName() function
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
    <link rel="stylesheet" href="css/users.css?v=<?= time(); ?>">
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

    // Event Listeners for Add buttons
    document.getElementById('addSupplierBtn').addEventListener('click', function() {
        addTerm('supplier');
    });

    document.getElementById('addCustomerBtn').addEventListener('click', function() {
        addTerm('customer');
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

    // Input validation - allow only numbers and letters (for COD)
    document.querySelectorAll('input[type="text"]').forEach(input => {
        input.addEventListener('input', function() {
            // Convert to uppercase for COD
            if (this.value.trim().toUpperCase() === 'COD') {
                this.value = 'COD';
            }
        });
    });
</script>

</body>
</html>