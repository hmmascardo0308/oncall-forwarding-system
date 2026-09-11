<?php
session_start();
include 'config/config.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $id_number   = $_POST['id_number'];
    $first_name  = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name   = $_POST['last_name'];
    $username    = $_POST['username'];
    $password    = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $user_type   = $_POST['user_type'];
    $status      = "active";

    $full_name = trim($first_name . " " . $middle_name . " " . $last_name);
    $created_at = date("Y-m-d H:i:s");

    $stmt = $conn->prepare("
        INSERT INTO all_users 
        (id_number, first_name, middle_name, last_name, full_name, username, password, user_type, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "ssssssssss",
        $id_number,
        $first_name,
        $middle_name,
        $last_name,
        $full_name,
        $username,
        $password,
        $user_type,
        $status,
        $created_at
    );

    if ($stmt->execute()) {
        $message = "Registration successful!";
    } else {
        $message = "Error: " . $stmt->error;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
    <style>
        body { font-family: Arial; }
        form { width: 400px; margin: auto; }
        input, select, button {
            width: 100%;
            padding: 8px;
            margin: 6px 0;
        }
    </style>
</head>
<body>

<h2 align="center">User Registration</h2>
<p align="center"><?php echo $message; ?></p>

<form method="POST" action="">
    <input type="text" name="id_number" placeholder="ID Number" required>

    <input type="text" name="first_name" placeholder="First Name" required>
    <input type="text" name="middle_name" placeholder="Middle Name">
    <input type="text" name="last_name" placeholder="Last Name" required>

    <input type="text" name="username" placeholder="Username" required>

    <input type="password" name="password" placeholder="Password" required>

    <select name="user_type" required>
        <option value="">Select User Type</option>
        <option value="admin">Admin</option>
        <option value="user">User</option>
    </select>

    <button type="submit">Register</button>
</form>

</body>
</html>
