<?php
    $host = "localhost";
    $username = "root";
    $password = "Password1";
    $database = "oncall_forwarding";

    // Create DB Connection
    $conn = mysqli_connect($host, $username, $password, $database);


    // Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

?>
