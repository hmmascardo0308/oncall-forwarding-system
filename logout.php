<?php
session_start();

// Destroy all session data
session_unset();
session_destroy();

// Redirect to welcome page
header("Location: welcome.php");
exit;
?>