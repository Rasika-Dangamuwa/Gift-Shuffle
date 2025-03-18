<?php
// Initialize the session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Log the logout activity if the user is logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    try {
        logActivity($_SESSION["id"], "logout", "User logged out");
    } catch (Exception $e) {
        error_log("Error logging logout activity: " . $e->getMessage());
    }
}

// Unset all of the session variables
$_SESSION = array();

// Destroy the session.
session_destroy();

// Redirect to login page
header("location: index.php");
exit;
?>