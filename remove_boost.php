<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Check if user is logged in
requireLogin();

// Handle boost removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_boost') {
    $boost_id = (int)$_POST['boost_id'];
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    
    try {
        // Begin transaction
        $conn = getConnection();
        $conn->begin_transaction();
        
        // Verify the boost belongs to a session created by this user
        $verify = executeQueryWithConnection(
            $conn,
            "SELECT gb.*, br.round_number 
             FROM gift_boosts gb
             JOIN shuffle_sessions ss ON gb.session_id = ss.id
             JOIN breakdown_rounds br ON gb.round_id = br.id
             WHERE gb.id = ? AND ss.created_by = ?",
            [$boost_id, $_SESSION['id']],
            'ii'
        );
        
        if (empty($verify)) {
            // Either boost doesn't exist or user doesn't have permission
            throw new Exception("Boost not found or you don't have permission to remove it");
        }
        
        // Delete the boost
        executeQueryWithConnection(
            $conn,
            "DELETE FROM gift_boosts WHERE id = ?",
            [$boost_id],
            'i'
        );
        
        // Log the action
        $session_id = $verify[0]['session_id'];
        $round_number = $verify[0]['round_number'];
        
        logActivity(
            $_SESSION["id"],
            "remove_boost",
            "Removed boost for round {$round_number} in session (ID: {$session_id})"
        );
        
        $conn->commit();
        
        // Set success message and redirect
        $_SESSION['success_message'] = "Boost removed successfully.";
        header("location: round_control_panel.php?id=" . $session_id);
        exit;
        
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        
        error_log("Error removing boost: " . $e->getMessage());
        
        $_SESSION['error_message'] = "Failed to remove boost: " . $e->getMessage();
        header("location: round_control_panel.php?id=" . $session_id);
        exit;
    }
} else {
    // Invalid request, redirect to dashboard
    header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
    exit;
}
?>