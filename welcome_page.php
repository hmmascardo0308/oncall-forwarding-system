<?php
session_start();

// If the user is already logged in, redirect them to the home page
if (isset($_SESSION['user_id'])) {
    header("Location: access/home.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome | OnCall Forwarding</title>
    <link rel="icon" type="image/png" href="images/oncall-forwarding.png">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Segoe UI", Tahoma, Arial, sans-serif;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background-color: #f8f9fa;
            overflow: hidden;
        }

        /* Background watermark similar to login page */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image: url('images/company logo.png');
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0.05;
            z-index: -1;
        }

        .welcome-card {
            background: #ffffff;
            padding: 50px 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            text-align: center;
            max-width: 480px;
            width: 90%;
            animation: fadeUp 0.8s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .logo {
            width: 100px;
            margin-bottom: 24px;
        }

        h1 { margin-bottom: 16px; color: #1e293b; font-size: 26px; font-weight: 700; }
        p { color: #64748b; margin-bottom: 32px; line-height: 1.6; font-size: 15px; }

        .btn-start {
            display: inline-block; text-decoration: none;
            background: #4ca1af; color: white; padding: 14px 40px;
            border-radius: 8px; font-weight: 600; font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(76, 161, 175, 0.3);
        }
        .btn-start:hover { background: #3b8d99; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(76, 161, 175, 0.4); }
        
        @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <div class="welcome-card">
        <img src="images/oncall-forwarding.png" alt="OnCall Logo" class="logo">
        <h1>Welcome to OnCall</h1>
        <p>Streamline your forwarding operations with our integrated vehicle rental and management system.</p>
        <a href="login.php" class="btn-start">Go to Login</a>
    </div>
</body>
</html>
