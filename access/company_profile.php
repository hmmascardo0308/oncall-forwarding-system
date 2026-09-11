<?php
// company_profile.php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/access_control.php'; // Include centralized access control

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

// Define base role - if 'admin' exists, user is admin
$is_admin = in_array('admin', $user_roles);
$base_user_type = $is_admin ? 'admin' : 'user';

// Define allowed pages based on roles - Now using centralized $allowed_pages from access_control.php

// Function to check if user has access to a specific page - Now using centralized hasAccess() function

// Function to get display name for roles - Now using centralized getRoleDisplayName() function

// Set current page for sidebar
$current_page = basename($_SERVER['PHP_SELF']);

// Handle Company Profile Update (Only for admin)
$success_msg = null;
$error_msg = null;

// Fetch current company profile data
$company_query = "SELECT * FROM company_profile LIMIT 1";
$company_result = $conn->query($company_query);
$company_data = $company_result->fetch_assoc();

// If no data exists, create default entry
if (!$company_data) {
    $insert_default = "INSERT INTO company_profile (company_name, street, barangay, town_city, province, postal_code, country, full_address, phone, email, tin) 
                       VALUES ('Oncall Forwarding Corporation', '', '', '', '', '', '', '', '', '', '')";
    $conn->query($insert_default);
    $company_result = $conn->query($company_query);
    $company_data = $company_result->fetch_assoc();
}

// Handle form submission (Only admin can update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $is_admin) {
    $company_name = mysqli_real_escape_string($conn, $_POST['company_name'] ?? '');
    $street = mysqli_real_escape_string($conn, $_POST['street'] ?? '');
    $barangay = mysqli_real_escape_string($conn, $_POST['barangay'] ?? '');
    $town_city = mysqli_real_escape_string($conn, $_POST['town_city'] ?? '');
    $province = mysqli_real_escape_string($conn, $_POST['province'] ?? '');
    $postal_code = intval($_POST['postal_code'] ?? 0);
    $country = mysqli_real_escape_string($conn, $_POST['country'] ?? '');
    $full_address = mysqli_real_escape_string($conn, $_POST['full_address'] ?? '');
    $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $tin = mysqli_real_escape_string($conn, $_POST['tin'] ?? '');
    
    // Handle logo upload
    $logo_path = $company_data['logo_path'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        $file_type = $_FILES['logo']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            $upload_dir = '../uploads/company/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $file_name = 'company_logo_' . time() . '.' . $file_ext;
            $target_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                $logo_path = 'uploads/company/' . $file_name;
            } else {
                $error_msg = "Failed to upload logo.";
            }
        } else {
            $error_msg = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        }
    }
    
    if (!$error_msg) {
        $update_query = "UPDATE company_profile SET 
                        company_name = '$company_name',
                        street = '$street',
                        barangay = '$barangay',
                        town_city = '$town_city',
                        province = '$province',
                        postal_code = $postal_code,
                        country = '$country',
                        full_address = '$full_address',
                        phone = '$phone',
                        email = '$email',
                        tin = '$tin',
                        logo_path = '$logo_path',
                        updated_at = NOW()
                        WHERE id = " . $company_data['id'];
        
        if ($conn->query($update_query)) {
            $success_msg = "Company profile updated successfully!";
            // Refresh data
            $company_result = $conn->query($company_query);
            $company_data = $company_result->fetch_assoc();
        } else {
            $error_msg = "Error updating profile: " . $conn->error;
        }
    }
}

$role_display_name = getRoleDisplayName($user_roles);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Profile | Oncall Forwarding</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../js/lucide.js"></script>
    <link rel="icon" type="image/png" href="../images/oncall-forwarding.png">
    <link rel="stylesheet" href="css/company.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="sidebar.css?v=<?= time(); ?>">
    
   
</head>
<body>

    <?php if ($success_msg): ?>
        <div id="flash-message"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
        <div id="error-message"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

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
                <span style="color:var(--text-muted); font-size:14px;">ONCALL FORWARDING CORPORATION / <span style="color:red; font-weight: bold; font-size: 16px;">Company Profiles</span></span>
            </div>
            <div class="user-profile">
                <span class="badge"><?php echo htmlspecialchars($full_name); ?></span>
                <?php if (!$is_admin): ?>
                    <span class="readonly-badge">View Only</span>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-body">
            <section class="welcome-section">
                <h1>Company Profile</h1>
                <p>View company information and branding.</p>
                <?php if (!$is_admin): ?>
                    <p style="font-size: 14px; color: var(--text-muted); margin-top: 5px;">
                        <i data-lucide="eye" style="width: 16px; height: 16px; display: inline;"></i> 
                        You are in view-only mode. Contact an administrator for changes.
                    </p>
                <?php endif; ?>
            </section>

            <?php if (!$is_admin): ?>
            <div class="edit-permission-note">
                <i data-lucide="lock" style="width: 20px; height: 20px;"></i>
                <p><strong>View Only Mode:</strong> You are viewing the company profile. Only administrators can edit this information.</p>
            </div>
            <?php endif; ?>

            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-logo">
                        <?php if (!empty($company_data['logo_path']) && file_exists('../' . $company_data['logo_path'])): ?>
                            <img src="../<?php echo htmlspecialchars($company_data['logo_path']); ?>" alt="Company Logo">
                        <?php else: ?>
                            <i data-lucide="building-2"></i>
                        <?php endif; ?>
                    </div>
                    <h2><?php echo htmlspecialchars($company_data['company_name'] ?? 'Oncall Forwarding Corporation'); ?></h2>
                    <p>Company Information & Settings</p>
                </div>
                
                <form method="POST" enctype="multipart/form-data" class="profile-form <?php echo (!$is_admin) ? 'readonly-mode' : ''; ?>" id="companyForm">
                    <div class="logo-upload">
                        <div class="current-logo">
                            <label for="logo" style="<?php echo (!$is_admin) ? 'background-color: #e2e8f0; cursor: not-allowed; opacity: 0.7;' : ''; ?>">
                                <i data-lucide="camera"></i> Change Company Logo
                            </label>
                            <input type="file" name="logo" id="logo" accept="image/jpeg,image/png,image/jpg,image/gif" <?php echo (!$is_admin) ? 'disabled' : ''; ?>>
                        </div>
                        <small style="color: var(--text-muted);">Recommended: Square image, max 2MB. JPG, PNG, or GIF only.</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" name="company_name" id="company_name" value="<?php echo htmlspecialchars($company_data['company_name'] ?? ''); ?>" required <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="form-group">
                        <label>Street Address</label>
                        <input type="text" name="street" id="street" value="<?php echo htmlspecialchars($company_data['street'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="form-group">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="barangay" value="<?php echo htmlspecialchars($company_data['barangay'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Town/City</label>
                            <input type="text" name="town_city" id="town_city" value="<?php echo htmlspecialchars($company_data['town_city'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label>Province</label>
                            <input type="text" name="province" id="province" value="<?php echo htmlspecialchars($company_data['province'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Postal Code</label>
                            <input type="number" name="postal_code" id="postal_code" value="<?php echo htmlspecialchars($company_data['postal_code'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" id="country" value="<?php echo htmlspecialchars($company_data['country'] ?? ''); ?>" placeholder="Philippines" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Full Address (Auto-generated)</label>
                        <textarea name="full_address" id="full_address" rows="2" readonly style="background: #f8fafc; cursor: not-allowed;"><?php echo htmlspecialchars($company_data['full_address'] ?? ''); ?></textarea>
                        <small style="color: var(--text-muted);">This field is automatically generated from the address components above.</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($company_data['phone'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($company_data['email'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>TIN (Tax Identification Number)</label>
                        <input type="text" name="tin" value="<?php echo htmlspecialchars($company_data['tin'] ?? ''); ?>" <?php echo (!$is_admin) ? 'readonly' : ''; ?>>
                    </div>
                    
                    <div class="address-preview" id="addressPreview">
                        <strong>Address Preview:</strong><br>
                        <span id="previewText"><?php echo htmlspecialchars($company_data['full_address'] ?? 'No address entered yet.'); ?></span>
                    </div>
                    
                    <?php if ($is_admin): ?>
                    <button type="submit" name="update_profile" class="btn-primary">
                        <i data-lucide="save"></i> Save Changes
                    </button>
                    <?php else: ?>
                    <div style="background: #f1f5f9; padding: 12px; border-radius: 8px; text-align: center; margin-top: 20px;">
                        <i data-lucide="lock" style="width: 16px; height: 16px; display: inline-block;"></i>
                        <span style="font-size: 14px; color: var(--text-muted);">View only mode - Contact administrator to edit</span>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        // Auto-generate full address (only if fields are not readonly)
        function generateFullAddress() {
            const street = document.getElementById('street')?.value || '';
            const barangay = document.getElementById('barangay')?.value || '';
            const townCity = document.getElementById('town_city')?.value || '';
            const province = document.getElementById('province')?.value || '';
            const postalCode = document.getElementById('postal_code')?.value || '';
            const country = document.getElementById('country')?.value || '';
            
            let addressParts = [];
            
            if (street) addressParts.push(street);
            if (barangay) addressParts.push(barangay);
            if (townCity) addressParts.push(townCity);
            if (province) addressParts.push(province);
            if (postalCode) addressParts.push(postalCode);
            if (country) addressParts.push(country);
            
            const fullAddress = addressParts.join(', ');
            const fullAddressField = document.getElementById('full_address');
            const previewText = document.getElementById('previewText');
            
            if (fullAddressField) {
                fullAddressField.value = fullAddress;
            }
            
            if (previewText) {
                previewText.textContent = fullAddress || 'No address entered yet.';
            }
        }
        
        <?php if ($is_admin): ?>
        // Add event listeners to address fields (only for admin)
        const addressFields = ['street', 'barangay', 'town_city', 'province', 'postal_code', 'country'];
        addressFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('input', generateFullAddress);
                field.addEventListener('change', generateFullAddress);
            }
        });
        <?php endif; ?>
        
        // Initial generation
        generateFullAddress();

        // Flash message auto-dismiss
        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.getElementById('flash-message');
            if (flash) {
                setTimeout(() => { 
                    flash.style.opacity = '0'; 
                    setTimeout(() => flash.remove(), 500); 
                }, 3000);
            }
            
            const errorMsg = document.getElementById('error-message');
            if (errorMsg) {
                setTimeout(() => { 
                    errorMsg.style.opacity = '0'; 
                    setTimeout(() => errorMsg.remove(), 500); 
                }, 3000);
            }
        });

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
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeModal();
            }
        });
        
        <?php if ($is_admin): ?>
        // Logo file name preview (only for admin)
        document.getElementById('logo')?.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            if (fileName) {
                const label = document.querySelector('.logo-upload label');
                if (label) {
                    label.innerHTML = '<i data-lucide="camera"></i> ' + fileName;
                    lucide.createIcons();
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>