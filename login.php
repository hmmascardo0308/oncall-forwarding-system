<?php
session_start();
include 'config/config.php';
date_default_timezone_set('Asia/Manila');

$message = "";
$pending_reset_error = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    
    // Check if it's a forgot password request
    if (isset($_POST['forgot_password'])) {
        $username = strtoupper(trim($_POST['username'])); // Convert to uppercase
        
        // Check if username exists
        $check_stmt = $conn->prepare("SELECT id, username, user_type FROM all_users WHERE username = ? LIMIT 1");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 1) {
            $user = $check_result->fetch_assoc();
            $user_type = $user['user_type'];
            
            // Check if there's already a PENDING request for this username
            $pending_check_stmt = $conn->prepare("
                SELECT id, status 
                FROM password_reset 
                WHERE username = ? AND LOWER(status) = 'pending'
                LIMIT 1
            ");
            $pending_check_stmt->bind_param("s", $username);
            $pending_check_stmt->execute();
            $pending_result = $pending_check_stmt->get_result();
            
            if ($pending_result->num_rows > 0) {
                // There's already a pending request
                $_SESSION['reset_request_error'] = "You already have a pending password reset request. Please wait for your administrator to process it.";
            } else {
                // Insert into password_reset table
                $insert_stmt = $conn->prepare("
                    INSERT INTO password_reset (username, user_type, date_requested, status) 
                    VALUES (?, ?, NOW(), 'Pending')
                ");
                $insert_stmt->bind_param("ss", $username, $user_type);
                
                if ($insert_stmt->execute()) {
                    $_SESSION['reset_request_success'] = "Contact your system administrator.";
                } else {
                    $_SESSION['reset_request_error'] = "Failed to submit request. Please try again.";
                }
                $insert_stmt->close();
            }
            $pending_check_stmt->close();
        } else {
            $_SESSION['reset_request_error'] = "Username not found. Please check your username.";
        }
        $check_stmt->close();
        
        header("Location: login.php");
        exit;
    }
    
    // Regular login
    $username = strtoupper(trim($_POST['username'])); // Convert to uppercase
    $password = $_POST['password'];

    $stmt = $conn->prepare("
        SELECT id, username, password, user_type, status, full_name
        FROM all_users
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Check for pending password reset request BEFORE authentication
        $pending_check_stmt = $conn->prepare("
            SELECT id, status 
            FROM password_reset 
            WHERE username = ? AND LOWER(status) = 'pending'
            LIMIT 1
        ");
        $pending_check_stmt->bind_param("s", $username);
        $pending_check_stmt->execute();
        $pending_result = $pending_check_stmt->get_result();
        
        if ($pending_result->num_rows > 0) {
            // User has a pending reset request - prevent login
            $_SESSION['pending_reset_error'] = "You cannot login because you have a pending password reset request. Please wait for your administrator to process it.";
            $pending_check_stmt->close();
            header("Location: login.php");
            exit;
        }
        $pending_check_stmt->close();

        if ($user['status'] !== 'active') {
            $message = "Account is inactive. If this is a mistake, please contact administrator.";
        } elseif (password_verify($password, $user['password'])) {

            // Set session variables
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];

            // Check if using default password
            if ($password === 'Oncall1234') {
                $_SESSION['force_password_change'] = true;
            } else {
                unset($_SESSION['force_password_change']);
            }

            $_SESSION['login_success'] = "Login successful! Welcome back.";

            // Update last_online
            $now = date("Y-m-d H:i:s");
            $update = $conn->prepare("UPDATE all_users SET last_online = ? WHERE id = ?");
            $update->bind_param("si", $now, $user['id']);
            $update->execute();

            header("Location: access/home.php");
            exit;

        } else {
            $message = "Invalid username or password.";
        }
    } else {
        $message = "Invalid username or password.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link rel="icon" type="image/png" href="images/oncall-forwarding.png">
    <link rel="stylesheet" href="login.css?v=<?= time(); ?>">
    <style>
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            animation: slideDown 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #333;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .close:hover {
            color: #000;
        }
        
        .modal p {
            color: #666;
            margin-bottom: 20px;
        }
        
        .modal input[type="text"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
            text-transform: uppercase;
        }
        
        .modal button {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        
        .modal button:hover {
            background-color: #0056b3;
        }
        
        .modal .cancel-btn {
            background-color: #6c757d;
            margin-top: 10px;
        }
        
        .modal .cancel-btn:hover {
            background-color: #5a6268;
        }
        
        .forgot-link {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #007bff;
            cursor: pointer;
            text-decoration: underline;
        }
        
        .forgot-link:hover {
            color: #0056b3;
        }
        
        .success-message {
            color: #28a745;
            background-color: #d4edda;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .error-message {
            color: #dc3545;
            background-color: #f8d7da;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }

        input[name="username"] {
            text-transform: uppercase;
        }

        .back-home-container {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .back-home-btn {
            display: inline-block;
            padding: 10px 30px;
            background-color: #00413d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .back-home-btn:hover {
            background-color: #213488;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .back-home-btn i {
            margin-right: 8px;
        }

        @media (max-width: 480px) {
            .back-home-btn {
                width: 100%;
                padding: 12px 20px;
                text-align: center;
            }
        }

        /* Success Popup Modal - New Styles */
        .success-popup {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            animation: fadeIn 0.3s;
        }
        
        .success-popup-content {
            background-color: #ffffff;
            margin: 10% auto;
            padding: 40px 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideDown 0.4s ease;
            position: relative;
        }
        
        .success-popup .success-icon {
            font-size: 56px;
            margin-bottom: 10px;
            display: block;
        }
        
        .success-popup h3 {
            color: #2883a7;
            font-size: 22px;
            margin: 10px 0 12px 0;
        }
        
        .success-popup p {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        
        .success-popup .close-success-btn {
            background: linear-gradient(135deg, #28a790, #1e737e);
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: auto;
        }
        
        .success-popup .close-success-btn:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 15px rgba(40, 167, 142, 0.4);
        }
        
        /* Hide the old success message banner */
        .success-message {
            display: none;
        }

        /* Warning/Pending error popup styles */
        .pending-error-popup {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            animation: fadeIn 0.3s;
        }
        
        .pending-error-content {
            background-color: #ffffff;
            margin: 10% auto;
            padding: 40px 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideDown 0.4s ease;
            position: relative;
        }
        
        .pending-error-content .error-icon {
            font-size: 56px;
            margin-bottom: 10px;
            display: block;
        }
        
        .pending-error-content h3 {
            color: #dc3545;
            font-size: 22px;
            margin: 10px 0 12px 0;
        }
        
        .pending-error-content p {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        
        .pending-error-content .close-error-btn {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: auto;
        }
        
        .pending-error-content .close-error-btn:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
        }

        /* Login Pending Reset Popup */
        .login-pending-popup {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            animation: fadeIn 0.3s;
        }
        
        .login-pending-content {
            background-color: #ffffff;
            margin: 10% auto;
            padding: 40px 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideDown 0.4s ease;
            position: relative;
        }
        
        .login-pending-content .warning-icon {
            font-size: 56px;
            margin-bottom: 10px;
            display: block;
        }
        
        .login-pending-content h3 {
            color: #ff3535;
            font-size: 22px;
            margin: 10px 0 12px 0;
        }
        
        .login-pending-content p {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        
        .login-pending-content .close-pending-btn {
            background: linear-gradient(135deg, #2d769e, #000f93);
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: auto;
        }
        
        .login-pending-content .close-pending-btn:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.4);
        }
    </style>
</head>
<body>

<div class="login-container">
    <h2>Login</h2>

    <?php if (isset($_SESSION['reset_request_success'])): ?>
        <!-- Success message is now shown as a popup modal -->
        <div id="successPopup" class="success-popup" style="display: block;">
            <div class="success-popup-content">
                <span class="success-icon">✅</span>
                <h3>Request Submitted!</h3>
                <p><?= $_SESSION['reset_request_success']; ?></p>
                <button class="close-success-btn" onclick="goToWelcome()">Close</button>
            </div>
        </div>
        <?php unset($_SESSION['reset_request_success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['reset_request_error'])): ?>
        <!-- Check if error is about pending request -->
        <?php if (strpos($_SESSION['reset_request_error'], 'pending') !== false): ?>
            <!-- Show as popup modal for pending error -->
            <div id="pendingErrorPopup" class="pending-error-popup" style="display: block;">
                <div class="pending-error-content">
                    <span class="error-icon">⏳</span>
                    <h3>Request Already Submitted!</h3>
                    <p><?= $_SESSION['reset_request_error']; ?></p>
                    <button class="close-error-btn" onclick="goToWelcome()">Close</button>
                </div>
            </div>
        <?php else: ?>
            <!-- Show as regular error message for other errors -->
            <div class="error-message"><?= $_SESSION['reset_request_error']; ?></div>
        <?php endif; ?>
        <?php unset($_SESSION['reset_request_error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['pending_reset_error'])): ?>
        <!-- Login Pending Reset Popup -->
        <div id="loginPendingPopup" class="login-pending-popup" style="display: block;">
            <div class="login-pending-content">
                <span class="warning-icon">🚫</span>
                <h3>Access Denied!</h3>
                <p><?= $_SESSION['pending_reset_error']; ?></p>
                <button class="close-pending-btn" onclick="closeLoginPendingPopup()">OK, Got It</button>
            </div>
        </div>
        <?php unset($_SESSION['pending_reset_error']); ?>
    <?php endif; ?>
    
    <?php if ($message): ?>
        <p class="message"><?php echo $message; ?></p>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="text" name="username" placeholder="Username" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
    
    <span class="forgot-link" onclick="openModal()">Forgot Password?</span>
    
    <div class="back-home-container">
        <a href="welcome.php" class="back-home-btn">
            &#8592; Back to Homepage
        </a>
    </div>
</div>

<!-- Forgot Password Modal -->
<div id="forgotModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Forgot Password</h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <p>Please enter your username to request a password reset.</p>
        <form method="POST" action="">
            <input type="text" name="username" placeholder="Enter your username" required style="text-transform: uppercase;" oninput="this.value = this.value.toUpperCase()">
            <button type="submit" name="forgot_password">Submit Request</button>
            <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
        </form>
    </div>
</div>

<script>
    // Get the modal
    var modal = document.getElementById('forgotModal');
    
    // Function to open modal
    function openModal() {
        modal.style.display = 'block';
    }
    
    // Function to close modal
    function closeModal() {
        modal.style.display = 'none';
    }
    
    // Close modal when clicking outside of it
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
    
    // Function to go to welcome.php
    function goToWelcome() {
        window.location.href = 'welcome.php';
    }
    
    // Function to close login pending popup and stay on login page
    function closeLoginPendingPopup() {
        document.getElementById('loginPendingPopup').style.display = 'none';
    }
    
    // Auto-close success popup after 5 seconds (optional)
    <?php if (isset($_SESSION['reset_request_success'])): ?>
        setTimeout(function() {
            var popup = document.getElementById('successPopup');
            if (popup) {
                popup.style.display = 'none';
                window.location.href = 'welcome.php';
            }
        }, 5000);
    <?php endif; ?>
</script>

</body>
</html>