<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Check if user is logged in
requireLogin();

// Check if session ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No session ID provided.";
    header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
    exit;
}

$session_id = (int)$_GET['id'];

try {
    // Get session details
    $session = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, gb.total_number,
            (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count,
            ss.theme_id, ss.collect_customer_info, ss.breakdown_round
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         WHERE ss.id = ? AND ss.created_by = ?",
        [$session_id, $_SESSION['id']],
        'ii'
    );
    
    if (empty($session)) {
        $_SESSION['error_message'] = "Session not found or you don't have permission to access it.";
        header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
        exit;
    }
    
    $session = $session[0];
    
    // Check if session is active
    if ($session['status'] !== 'active') {
        $_SESSION['error_message'] = "This session is already " . $session['status'] . ".";
        header("location: view_session.php?id=" . $session_id);
        exit;
    }
    
    // Get current round
    $current_round = getCurrentRound($session_id);
    
    if (!$current_round) {
        // No active round, create the first one
        $round = getOrCreateNextRound($session_id);
        
        if (!$round) {
            $_SESSION['error_message'] = "Could not initialize breakdown round.";
            header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
            exit;
        }
        
        $current_round = $round;
    }
    
    $round_id = $current_round['id'];
    $round_number = $current_round['round_number'];
    
    // Get all gifts for this round with quantities
    $round_gifts = getRoundGifts($round_id);
    
    // Calculate total gifts and remaining gifts
    $total_gifts = 0;
    $remaining_gifts = 0;
    
    foreach ($round_gifts as $gift) {
        $total_gifts += $gift['quantity_available'];
        $remaining = $gift['quantity_available'] - $gift['quantity_used'];
        if ($remaining > 0) {
            $remaining_gifts += $remaining;
        }
    }
    
    // Get upcoming rounds
    $upcoming_rounds = executeQuery(
        "SELECT br.*, gb.name as breakdown_name, gb.total_number,
                (SELECT COUNT(*) FROM gift_winners gw WHERE gw.round_id = br.id) as winners_count
         FROM breakdown_rounds br
         JOIN gift_breakdowns gb ON br.breakdown_id = gb.id
         WHERE br.session_id = ? AND br.round_number > ?
         ORDER BY br.round_number ASC",
        [$session_id, $round_number],
        'ii'
    );
    
    // Get next play round for boosting
    $next_play_round_query = executeQuery(
        "SELECT IFNULL(MAX(round_number), 0) + 1 as next_round FROM gift_winners WHERE session_id = ?",
        [$session_id],
        'i'
    );
    $next_play_round = $next_play_round_query[0]['next_round'] ?? 1;
    
    // Get boosts for current and upcoming rounds
    $boosts = getSessionBoosts($session_id);
    
    // Get recent winners
    $recent_winners = executeQuery(
        "SELECT gw.*, g.name as gift_name
         FROM gift_winners gw
         JOIN gifts g ON gw.gift_id = g.id
         WHERE gw.session_id = ?
         ORDER BY gw.win_time DESC
         LIMIT 10",
        [$session_id],
        'i'
    );
    
} catch (Exception $e) {
    error_log("Error getting session details: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while retrieving session details.";
    header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
    exit;
}

// Get animation theme name
$theme_names = [
    1 => 'Spinning Wheel',
    2 => 'Gift Box Opening',
    3 => 'Slot Machine',
    4 => 'Scratch Card'
];
$theme_name = $theme_names[$session['theme_id']] ?? 'Default Theme';

// Get display password
$display_password = "";
try {
    $password_setting = executeQuery(
        "SELECT setting_value FROM system_settings WHERE setting_name = 'display_password'",
        [],
        ''
    );
    
    if (!empty($password_setting)) {
        $display_password = $password_setting[0]['setting_value'];
    }
} catch (Exception $e) {
    error_log("Error getting display password: " . $e->getMessage());
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle session update
    if (isset($_POST['action']) && $_POST['action'] === 'update_session') {
        $event_name = trim($_POST['event_name']);
        $vehicle_number = trim($_POST['vehicle_number']);
        $theme_id = (int)$_POST['theme_id'];
        $collect_customer_info = isset($_POST['collect_customer_info']) ? 1 : 0;
        $display_password = trim($_POST['display_password'] ?? '');
        
        try {
            executeQuery(
                "UPDATE shuffle_sessions SET 
                event_name = ?, vehicle_number = ?, 
                theme_id = ?, collect_customer_info = ? 
                WHERE id = ?",
                [$event_name, $vehicle_number, $theme_id, $collect_customer_info, $session_id],
                'ssiis'
            );
            
            // Update display password if provided
            if (!empty($display_password)) {
                $password_exists = executeQuery(
                    "SELECT id FROM system_settings WHERE setting_name = 'display_password'",
                    [],
                    ''
                );
                
                if (empty($password_exists)) {
                    // Insert new display password
                    executeQuery(
                        "INSERT INTO system_settings (setting_name, setting_value, updated_by) 
                         VALUES ('display_password', ?, ?)",
                        [$display_password, $_SESSION['id']],
                        'si'
                    );
                } else {
                    // Update existing display password
                    executeQuery(
                        "UPDATE system_settings SET setting_value = ?, updated_by = ?
                         WHERE setting_name = 'display_password'",
                        [$display_password, $_SESSION['id']],
                        'si'
                    );
                }
            }
            
            // Log activity
            logActivity(
                $_SESSION["id"],
                "update_session",
                "Updated gift shuffle session details (ID: {$session_id})"
            );
            
            $_SESSION['success_message'] = "Session details updated successfully!";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id . "&tab=settings");
            exit;
        } catch (Exception $e) {
            error_log("Error updating session: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while updating the session.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id . "&tab=settings");
            exit;
        }
    }
    
    // Handle boost setup
    if (isset($_POST['action']) && $_POST['action'] === 'set_boost') {
        $gift_id = (int)$_POST['gift_id'];
        $target_round = isset($_POST['target_round']) ? (int)$_POST['target_round'] : 0;
        
        try {
            // Set the boost for the target play round
            $result = setRoundBoost($session_id, $round_id, $gift_id, $target_round);
            
            if ($result) {
                $_SESSION['success_message'] = "Boost set for play round {$target_round}!";
            } else {
                $_SESSION['error_message'] = "Failed to set boost.";
            }
            
            $tab = isset($_POST['source_tab']) ? $_POST['source_tab'] : 'control';
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id . "&tab=" . $tab);
            exit;
        } catch (Exception $e) {
            error_log("Error setting boost: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while setting the boost.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
            exit;
        }
    }
    
    // Handle create new round
    if (isset($_POST['action']) && $_POST['action'] === 'create_round') {
        try {
            // Complete current round and create new one
            $next_round_number = $round_number + 1;
            $new_round_id = createNewRound($session_id, $session['breakdown_id'], $next_round_number);
            
            if ($new_round_id) {
                $_SESSION['success_message'] = "New round created successfully!";
                header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id . "&tab=rounds");
                exit;
            } else {
                $_SESSION['error_message'] = "Failed to create new round.";
                header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id . "&tab=rounds");
                exit;
            }
        } catch (Exception $e) {
            error_log("Error creating new round: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while creating a new round.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id . "&tab=rounds");
            exit;
        }
    }
    
    // Handle complete session
    if (isset($_POST['action']) && $_POST['action'] === 'complete_session') {
        try {
            // Begin transaction
            $conn = getConnection();
            $conn->begin_transaction();
            
            // End the session
            executeQueryWithConnection(
                $conn,
                "UPDATE shuffle_sessions SET status = 'completed', end_time = NOW() WHERE id = ?",
                [$session_id],
                'i'
            );
            
            // Complete current round
            executeQueryWithConnection(
                $conn,
                "UPDATE breakdown_rounds SET status = 'completed', completed_at = NOW() 
                 WHERE session_id = ? AND status = 'active'",
                [$session_id],
                'i'
            );
            
            $conn->commit();
            
            // Log activity
            logActivity(
                $_SESSION["id"],
                "complete_session",
                "Completed gift shuffle session (ID: {$session_id})"
            );
            
            // Set success message and redirect
            $_SESSION['success_message'] = "Session completed successfully!";
            header("location: view_session.php?id=" . $session_id);
            exit;
        } catch (Exception $e) {
            // Rollback on error
            if (isset($conn) && $conn->ping()) {
                $conn->rollback();
            }
            
            error_log("Error completing session: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while completing the session.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
            exit;
        }
    }
}

// Check for success message
$success_message = "";
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Check for error message
$error_message = "";
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get current active tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'control';

// Generate a unique display URL for easy copying
$display_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/shuffle_display.php?code=" . $session['access_code'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Control - Gift Shuffle System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a73e8;
            --primary-hover: #1557b0;
            --secondary-color: #6c5ce7;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --background-color: #f5f9ff;
            --card-color: #ffffff;
            --text-color: #333333;
            --text-secondary: #6c757d;
            --border-color: #e1e1e1;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--background-color);
            min-height: 100vh;
        }

        /* Navbar styles */
        .navbar {
            background: white;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            padding: 15px 30px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
        }

        .logo i {
            font-size: 1.8rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            background: #f1f3f4;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: background 0.3s ease;
        }

        .back-btn:hover {
            background: #e2e6ea;
        }

        /* Main container */
        .container {
            max-width: 1200px;
            margin: 80px auto 30px;
            padding: 0 20px;
        }

        /* Session header card */
        .session-header-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .session-header-card .card-header {
            background: linear-gradient(135deg, #1a73e8, #6c5ce7);
            color: white;
            padding: 20px;
            position: relative;
        }

        .session-header-card .card-body {
            padding: 20px;
        }

        .session-title {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .session-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #f5f5f5;
            font-size: 0.9rem;
        }

        /* Alert messages */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: var(--danger-color);
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: var(--info-color);
            border: 1px solid #bee5eb;
        }

        /* Stats boxes */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Main content grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 992px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Card styles */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 20px;
        }

        /* Display link section */
        .display-link-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .display-link-title {
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .display-link-container {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .display-link-input {
            flex-grow: 1;
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .copy-btn {
            padding: 10px 15px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s ease;
        }

        .copy-btn:hover {
            background: var(--primary-hover);
        }

        .display-link-note {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .access-code-box {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
            border: 2px dashed var(--primary-color);
        }

        .access-code-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .access-code {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            letter-spacing: 5px;
        }

        .display-password {
            font-size: 1.2rem;
            margin-top: 10px;
            color: var(--secondary-color);
        }

        /* Boost management section */
        .boost-section {
            margin-bottom: 20px;
        }

        .boost-title {
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .boost-form {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .boost-field {
            flex: 1;
        }

        .boost-field label {
            display: block;
            margin-bottom: 5px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .boost-field select, .boost-field input {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .boost-btn {
            align-self: flex-end;
            padding: 10px 20px;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .boost-btn:hover {
            background: #5649d1;
        }

        .boost-list {
            background: #f8f9fa;
            border-radius: 8px;
            overflow: hidden;
        }

        .boost-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .boost-item:last-child {
            border-bottom: none;
        }

        .boost-info {
            display: flex;
            flex-direction: column;
        }

        .boost-round {
            font-weight: 600;
            color: var(--primary-color);
        }

        .boost-gift {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .boost-remove {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: 6px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .boost-remove:hover {
            background: #c82333;
        }

        .boost-empty {
            padding: 20px;
            text-align: center;
            color: var(--text-secondary);
        }

        /* Gift section */
        .gift-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .gift-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }

        .gift-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }

        .gift-card.selected {
            border-color: var(--primary-color);
            background: rgba(26, 115, 232, 0.05);
        }

        .gift-card.empty {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .gift-name {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
        }

        .gift-quantity {
            color: var(--text-secondary);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .gift-progress {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin-top: 10px;
            overflow: hidden;
        }

        .gift-progress-bar {
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
        }

        /* Recent winners section */
        .winners-list {
            height: 450px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        .winner-item {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.3s ease;
        }

        .winner-item:last-child {
            border-bottom: none;
        }

        .winner-item:hover {
            background: #f8f9fa;
        }

        .winner-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .winner-info {
            flex-grow: 1;
        }

        .winner-name {
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .winner-time {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .winner-details {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 5px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .winner-detail {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .winner-gift {
            background: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .boosted-badge {
            background: rgba(108, 92, 231, 0.1);
            color: var(--secondary-color);
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 5px;
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .action-btn {
            flex: 1;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.3s ease, transform 0.2s ease;
            text-decoration: none;
        }

        .action-btn:hover {
            transform: translateY(-3px);
        }

        .action-btn.primary {
            background: var(--primary-color);
        }

        .action-btn.primary:hover {
            background: var(--primary-hover);
        }

        .action-btn.danger {
            background: var(--danger-color);
        }

        .action-btn.danger:hover {
            background: #c82333;
        }

        .action-btn.success {
            background: var(--success-color);
        }

        .action-btn.success:hover {
            background: #218838;
        }

        /* Settings section */
        .settings-form {
            margin-top: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .form-check-label {
            cursor: pointer;
            user-select: none;
        }

        .theme-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .theme-card {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .theme-card:hover {
            border-color: var(--primary-color);
        }

        .theme-card.selected {
            border-color: var(--primary-color);
            background: rgba(26, 115, 232, 0.05);
        }

        .theme-radio {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .save-btn {
            width: 100%;
            padding: 12px 20px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.3s ease;
        }

        .save-btn:hover {
            background: var(--primary-hover);
        }

        /* Hide scrollbar in webkit browsers */
        .winners-list::-webkit-scrollbar {
            width: 6px;
        }

        .winners-list::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .winners-list::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        .winners-list::-webkit-scrollbar-thumb:hover {
            background: #999;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 30px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }

        .tab:hover {
            color: var(--primary-color);
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Round Status Section */
        .round-status-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            padding: 20px;
            text-align: center;
        }

        .round-number-display {
            font-size: 3.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .round-label {
            color: var(--text-secondary);
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .round-progress {
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            margin: 15px 0;
            overflow: hidden;
        }

        .round-progress-bar {
            height: 100%;
            background: var(--primary-color);
            border-radius: 6px;
        }

        .round-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?php echo $_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'; ?>" class="logo">
            <i class="fas fa-gift"></i>
            <span>Gift Shuffle</span>
        </a>
        <div class="user-menu">
            <a href="<?php echo $_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container">
        <!-- Session Header Card -->
        <div class="session-header-card">
            <div class="card-header">
                <h1 class="session-title"><?php echo htmlspecialchars($session['event_name']); ?></h1>
                <div class="session-meta">
                    <div class="meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span><?php echo date('M j, Y', strtotime($session['session_date'])); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-clock"></i>
                        <span>Started: <?php echo date('h:i A', strtotime($session['start_time'])); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-truck"></i>
                        <span><?php echo htmlspecialchars($session['vehicle_number']); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-palette"></i>
                        <span>Theme: <?php echo htmlspecialchars($theme_name); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-th-large"></i>
                        <span>Breakdown: <?php echo htmlspecialchars($session['breakdown_name']); ?></span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Access Code Box -->
                <div class="access-code-box">
                    <div class="access-code-label">GIFT SHUFFLE ACCESS CODE</div>
                    <div class="access-code"><?php echo htmlspecialchars($session['access_code']); ?></div>
                    <?php if (!empty($display_password)): ?>
                    <div class="display-password">Display Password: <?php echo htmlspecialchars($display_password); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Shuffle Display Link -->
                <div class="display-link-section">
                    <div class="display-link-title">
                        <i class="fas fa-external-link-alt"></i>
                        Shuffle Display Link
                    </div>
                    <div class="display-link-container">
                        <input type="text" value="<?php echo htmlspecialchars($display_url); ?>" id="displayUrl" class="display-link-input" readonly>
                        <button class="copy-btn" onclick="copyToClipboard()">
                            <i class="fas fa-copy"></i>
                            Copy
                        </button>
                        <a href="<?php echo htmlspecialchars($display_url); ?>" target="_blank" class="copy-btn">
                            <i class="fas fa-external-link-alt"></i>
                            Open
                        </a>
                    </div>
                    <div class="display-link-note">
                        Open this URL on the display screen visible to customers for the gift shuffle.
                    </div>
                </div>

                <!-- Shuffle Statistics -->
                <div class="stats-grid">
                    <div class="stat-box" id="currentRoundBox">
                        <div class="stat-value" id="currentRoundValue"><?php echo $round_number; ?></div>
                        <div class="stat-label">Current Round</div>
                    </div>
                    <div class="stat-box" id="totalGiftsBox">
                        <div class="stat-value" id="totalGiftsValue"><?php echo $total_gifts; ?></div>
                        <div class="stat-label">Total Gifts in Round</div>
                    </div>
                    <div class="stat-box" id="remainingGiftsBox">
                        <div class="stat-value" id="remainingGiftsValue"><?php echo $remaining_gifts; ?></div>
                        <div class="stat-label">Gifts Remaining</div>
                    </div>
                    <div class="stat-box" id="winnersCountBox">
                        <div class="stat-value" id="winnersCountValue"><?php echo $session['winners_count']; ?></div>
                        <div class="stat-label">Total Winners</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab <?php echo $current_tab === 'control' ? 'active' : ''; ?>" data-tab="control">
                <i class="fas fa-gamepad"></i> Shuffle Control
            </div>
            <div class="tab <?php echo $current_tab === 'rounds' ? 'active' : ''; ?>" data-tab="rounds">
                <i class="fas fa-sync-alt"></i> Round Management
            </div>
            <div class="tab <?php echo $current_tab === 'boost' ? 'active' : ''; ?>" data-tab="boost">
                <i class="fas fa-bolt"></i> Boost Management
            </div>
            <div class="tab <?php echo $current_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                <i class="fas fa-cog"></i> Session Settings
            </div>
        </div>

        <!-- Shuffle Control Tab -->
        <div class="tab-content <?php echo $current_tab === 'control' ? 'active' : ''; ?>" id="control-tab">
            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Available Gifts Card -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-gift"></i>
                            Available Gifts
                        </div>
                        <div class="card-body">
                            <div class="gift-grid" id="giftGrid">
                                <?php foreach ($round_gifts as $gift): ?>
                                    <?php 
                                    $remaining = $gift['quantity_available'] - $gift['quantity_used'];
                                    $progressPercentage = ($gift['quantity_used'] / $gift['quantity_available']) * 100;
                                    ?>
                                    <div class="gift-card <?php echo $remaining <= 0 ? 'empty' : ''; ?>" data-gift-id="<?php echo $gift['gift_id']; ?>">
                                        <div class="gift-name"><?php echo htmlspecialchars($gift['name']); ?></div>
                                        <div class="gift-quantity">
                                            <i class="fas fa-box-open"></i>
                                            <span><?php echo $remaining; ?> of <?php echo $gift['quantity_available']; ?> remaining</span>
                                        </div>
                                        <div class="gift-progress">
                                            <div class="gift-progress-bar" style="width: <?php echo $progressPercentage; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Boost Form -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-bolt"></i>
                            Quick Boost
                        </div>
                        <div class="card-body">
                            <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" class="boost-form">
                                <input type="hidden" name="action" value="set_boost">
                                <input type="hidden" name="source_tab" value="control">
                                <div class="boost-field">
                                    <label for="target_round">Target Play Round</label>
                                    <input type="number" id="target_round" name="target_round" min="1" value="<?php echo $next_play_round; ?>" class="form-control">
                                </div>
                                <div class="boost-field">
                                    <label for="gift_id">Select Gift</label>
                                    <select id="gift_id" name="gift_id" class="form-control" required>
                                        <option value="">-- Select a gift --</option>
                                        <?php foreach ($round_gifts as $gift): ?>
                                            <?php $remaining = $gift['quantity_available'] - $gift['quantity_used']; ?>
                                            <?php if ($remaining > 0): ?>
                                            <option value="<?php echo $gift['gift_id']; ?>">
                                                <?php echo htmlspecialchars($gift['name']); ?> (<?php echo $remaining; ?> left)
                                            </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="boost-btn">
                                    <i class="fas fa-bolt"></i>
                                    Set Boost
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Recent Winners Card -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-trophy"></i>
                            Recent Winners
                        </div>
                        <div class="card-body">
                            <div class="winners-list" id="winnersList">
                                <?php if (empty($recent_winners)): ?>
                                    <div class="boost-empty">No winners yet</div>
                                <?php else: ?>
                                    <?php foreach ($recent_winners as $winner): ?>
                                        <div class="winner-item">
                                            <div class="winner-icon">
                                                <i class="fas fa-gift"></i>
                                            </div>
                                            <div class="winner-info">
                                                <div class="winner-name">
                                                    <?php echo !empty($winner['winner_name']) ? htmlspecialchars($winner['winner_name']) : 'Anonymous Winner'; ?>
                                                    <span class="winner-time"><?php echo date('h:i A', strtotime($winner['win_time'])); ?></span>
                                                </div>
                                                <div class="winner-details">
                                                    <div class="winner-detail">
                                                        <i class="fas fa-hashtag"></i>
                                                        Round <?php echo $winner['round_number']; ?>
                                                    </div>
                                                    <?php if (!empty($winner['winner_nic'])): ?>
                                                    <div class="winner-detail">
                                                        <i class="fas fa-id-card"></i>
                                                        <?php echo htmlspecialchars($winner['winner_nic']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($winner['winner_phone'])): ?>
                                                    <div class="winner-detail">
                                                        <i class="fas fa-phone"></i>
                                                        <?php echo htmlspecialchars($winner['winner_phone']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="winner-gift">
                                                    <?php echo htmlspecialchars($winner['gift_name']); ?>
                                                    <?php if ($winner['boosted']): ?>
                                                    <span class="boosted-badge">Boosted</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="<?php echo htmlspecialchars($display_url); ?>" target="_blank" class="action-btn primary">
                    <i class="fas fa-external-link-alt"></i>
                    Open Shuffle Display
                </a>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" style="flex: 1;">
                    <input type="hidden" name="action" value="complete_session">
                    <button type="submit" class="action-btn danger" onclick="return confirm('Are you sure you want to end this session? This action cannot be undone.');">
                        <i class="fas fa-stop-circle"></i>
                        End Session
                    </button>
                </form>
            </div>
        </div>

        <!-- Round Management Tab -->
        <div class="tab-content <?php echo $current_tab === 'rounds' ? 'active' : ''; ?>" id="rounds-tab">
            <!-- Round Status Card -->
            <div class="round-status-card">
                <div class="round-label">CURRENT BREAKDOWN ROUND</div>
                <div class="round-number-display"><?php echo $round_number; ?></div>
                
                <div class="round-progress">
                    <div class="round-progress-bar" style="width: <?php echo ($total_gifts > 0) ? (($total_gifts - $remaining_gifts) / $total_gifts * 100) : 0; ?>%"></div>
                </div>
                
                <div class="round-stats">
                    <div><?php echo $total_gifts - $remaining_gifts; ?> gifts given</div>
                    <div><?php echo $remaining_gifts; ?> gifts remaining</div>
                </div>
                
                <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id . '&tab=rounds'); ?>">
                    <input type="hidden" name="action" value="create_round">
                    <button type="submit" class="action-btn success">
                        <i class="fas fa-plus-circle"></i>
                        Create New Round
                    </button>
                </form>
            </div>

            <!-- Gift Status Table -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-gift"></i>
                    Current Round Gifts
                </div>
                <div class="card-body">
                    <table class="table" style="width: 100%; border-collapse: collapse;">
                        <thead style="background-color: #f8f9fa;">
                            <tr>
                                <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e1e1;">Gift Name</th>
                                <th style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;">Total</th>
                                <th style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;">Used</th>
                                <th style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;">Remaining</th>
                                <th style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;">Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($round_gifts as $gift): ?>
                                <?php 
                                $remaining = $gift['quantity_available'] - $gift['quantity_used'];
                                $progressPercentage = ($gift['quantity_used'] / $gift['quantity_available']) * 100;
                                ?>
                                <tr>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;"><?php echo htmlspecialchars($gift['name']); ?></td>
                                    <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $gift['quantity_available']; ?></td>
                                    <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $gift['quantity_used']; ?></td>
                                    <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $remaining; ?></td>
                                    <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;">
                                        <div style="height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $progressPercentage; ?>%; background: <?php echo $remaining > 0 ? 'var(--primary-color)' : 'var(--success-color)'; ?>; border-radius: 4px;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Round History Table -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history"></i>
                    Round History
                </div>
                <div class="card-body">
                    <table class="table" style="width: 100%; border-collapse: collapse;">
                        <thead style="background-color: #f8f9fa;">
                            <tr>
                                <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e1e1;">Round #</th>
                                <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e1e1;">Started</th>
                                <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e1e1;">Status</th>
                                <th style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;">Winners</th>
                                <th style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;">Gifts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Current Round -->
                            <tr style="background-color: rgba(26, 115, 232, 0.05);">
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1; font-weight: 600;"><?php echo $round_number; ?> (Current)</td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;"><?php echo date('M j, g:i A', strtotime($current_round['started_at'])); ?></td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;">
                                    <span style="background: rgba(40, 167, 69, 0.1); color: var(--success-color); padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">Active</span>
                                </td>
                                <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $total_gifts - $remaining_gifts; ?></td>
                                <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $total_gifts; ?></td>
                            </tr>
                            
                            <!-- Past Rounds -->
                            <?php
                            // Get all previous rounds for this session
                            $past_rounds = executeQuery(
                                "SELECT br.*, 
                                        (SELECT COUNT(*) FROM gift_winners gw WHERE gw.round_id = br.id) as winners_count,
                                        (SELECT SUM(rg.quantity_available) FROM round_gifts rg WHERE rg.round_id = br.id) as total_gifts
                                 FROM breakdown_rounds br
                                 WHERE br.session_id = ? AND br.round_number < ?
                                 ORDER BY br.round_number DESC",
                                [$session_id, $round_number],
                                'ii'
                            );
                            
                            foreach ($past_rounds as $past_round):
                            ?>
                            <tr>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;"><?php echo $past_round['round_number']; ?></td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;"><?php echo date('M j, g:i A', strtotime($past_round['started_at'])); ?></td>
                                <td style="padding: 12px 15px; border-bottom: 1px solid #e1e1e1;">
                                    <span style="background: rgba(108, 117, 125, 0.1); color: var(--text-secondary); padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">Completed</span>
                                </td>
                                <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $past_round['winners_count']; ?></td>
                                <td style="padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e1e1;"><?php echo $past_round['total_gifts']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="<?php echo htmlspecialchars($display_url); ?>" target="_blank" class="action-btn primary">
                    <i class="fas fa-external-link-alt"></i>
                    Open Shuffle Display
                </a>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" style="flex: 1;">
                    <input type="hidden" name="action" value="complete_session">
                    <button type="submit" class="action-btn danger" onclick="return confirm('Are you sure you want to end this session? This action cannot be undone.');">
                        <i class="fas fa-stop-circle"></i>
                        End Session
                    </button>
                </form>
            </div>
        </div>

        <!-- Boost Management Tab -->
        <div class="tab-content <?php echo $current_tab === 'boost' ? 'active' : ''; ?>" id="boost-tab">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bolt"></i>
                    Boost Management
                </div>
                <div class="card-body">
                    <div class="boost-section">
                        <div class="boost-title">
                            <i class="fas fa-magic"></i>
                            Set Boost for Play Round
                        </div>
                        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" class="boost-form">
                            <input type="hidden" name="action" value="set_boost">
                            <input type="hidden" name="source_tab" value="boost">
                            <div class="boost-field">
                                <label for="target_round">Target Play Round</label>
                                <input type="number" id="target_round" name="target_round" min="1" value="<?php echo $next_play_round; ?>" class="form-control">
                            </div>
                            <div class="boost-field">
                                <label for="gift_id">Select Gift</label>
                                <select id="gift_id" name="gift_id" class="form-control" required>
                                    <option value="">-- Select a gift --</option>
                                    <?php foreach ($round_gifts as $gift): ?>
                                        <?php $remaining = $gift['quantity_available'] - $gift['quantity_used']; ?>
                                        <?php if ($remaining > 0): ?>
                                        <option value="<?php echo $gift['gift_id']; ?>">
                                            <?php echo htmlspecialchars($gift['name']); ?> (<?php echo $remaining; ?> left)
                                        </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="boost-btn">
                                <i class="fas fa-bolt"></i>
                                Set Boost
                            </button>
                        </form>

                        <div class="boost-title" style="margin-top: 20px;">
                            <i class="fas fa-list"></i>
                            Active Boosts
                        </div>
                        <div class="boost-list">
                            <?php if (empty($boosts)): ?>
                                <div class="boost-empty">No active boosts</div>
                            <?php else: ?>
                                <?php foreach ($boosts as $boost): ?>
                                    <div class="boost-item">
                                        <div class="boost-info">
                                            <div class="boost-round">Play Round <?php echo $boost['target_round']; ?></div>
                                            <div class="boost-gift"><?php echo htmlspecialchars($boost['gift_name']); ?></div>
                                        </div>
                                        <form method="post" action="remove_boost.php">
                                            <input type="hidden" name="action" value="remove_boost">
                                            <input type="hidden" name="boost_id" value="<?php echo $boost['id']; ?>">
                                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                                            <button type="submit" class="boost-remove" title="Remove Boost">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Available Gifts for Boost -->
                    <div class="gift-grid" id="boostGiftGrid" style="margin-top: 30px;">
                        <?php foreach ($round_gifts as $gift): ?>
                            <?php 
                            $remaining = $gift['quantity_available'] - $gift['quantity_used'];
                            $progressPercentage = ($gift['quantity_used'] / $gift['quantity_available']) * 100;
                            ?>
                            <div class="gift-card <?php echo $remaining <= 0 ? 'empty' : ''; ?>" data-gift-id="<?php echo $gift['gift_id']; ?>">
                                <div class="gift-name"><?php echo htmlspecialchars($gift['name']); ?></div>
                                <div class="gift-quantity">
                                    <i class="fas fa-box-open"></i>
                                    <span><?php echo $remaining; ?> of <?php echo $gift['quantity_available']; ?> remaining</span>
                                </div>
                                <div class="gift-progress">
                                    <div class="gift-progress-bar" style="width: <?php echo $progressPercentage; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div class="tab-content <?php echo $current_tab === 'settings' ? 'active' : ''; ?>" id="settings-tab">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-cog"></i>
                    Session Settings
                </div>
                <div class="card-body">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" class="settings-form">
                        <input type="hidden" name="action" value="update_session">
                        
                        <div class="form-group">
                            <label for="event_name">Event Name</label>
                            <input type="text" name="event_name" id="event_name" class="form-control" value="<?php echo htmlspecialchars($session['event_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="vehicle_number">Vehicle Number</label>
                            <select name="vehicle_number" id="vehicle_number" class="form-control" required>
                                <?php
                                // Try to get vehicles from the database
                                try {
                                    $vehicles = executeQuery(
                                        "SELECT vehicle_number, vehicle_name FROM vehicles 
                                         WHERE is_active = TRUE 
                                         ORDER BY vehicle_name",
                                        [],
                                        ''
                                    );
                                } catch (Exception $e) {
                                    // Fallback for older systems without vehicles table
                                    $vehicles = [
                                        ['vehicle_number' => 'LJ-1764', 'vehicle_name' => 'Vehicle 1 (LJ-1764)'],
                                        ['vehicle_number' => 'KV-7842', 'vehicle_name' => 'Vehicle 2 (KV-7842)'],
                                        ['vehicle_number' => 'PH-3519', 'vehicle_name' => 'Vehicle 3 (PH-3519)'],
                                        ['vehicle_number' => 'CB-8024', 'vehicle_name' => 'Vehicle 4 (CB-8024)']
                                    ];
                                }
                                
                                foreach ($vehicles as $vehicle):
                                ?>
                                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_number']); ?>"
                                        <?php echo $session['vehicle_number'] === $vehicle['vehicle_number'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_name'] ?? $vehicle['vehicle_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Animation Theme</label>
                            <div class="theme-options">
                                <?php foreach ($theme_names as $id => $name): ?>
                                    <label class="theme-card <?php echo $session['theme_id'] == $id ? 'selected' : ''; ?>">
                                        <input type="radio" name="theme_id" value="<?php echo $id; ?>" class="theme-radio" 
                                            <?php echo $session['theme_id'] == $id ? 'checked' : ''; ?>>
                                        <div class="theme-name"><?php echo htmlspecialchars($name); ?></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" id="collect_customer_info" name="collect_customer_info" value="1" class="form-check-input" 
                                <?php echo $session['collect_customer_info'] ? 'checked' : ''; ?>>
                            <label for="collect_customer_info" class="form-check-label">
                                Collect Customer Information
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label for="display_password">Display Password</label>
                            <input type="text" name="display_password" id="display_password" class="form-control" 
                                value="<?php echo htmlspecialchars($display_password); ?>" 
                                placeholder="Enter display password">
                            <div class="form-text">This password is required when connecting display screens to this session.</div>
                        </div>
                        
                        <button type="submit" class="save-btn">
                            <i class="fas fa-save"></i>
                            Save Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        // Copy to clipboard function
        function copyToClipboard() {
            const displayUrl = document.getElementById('displayUrl');
            displayUrl.select();
            document.execCommand('copy');
            
            // Show feedback
            const copyBtn = document.querySelector('.copy-btn');
            const originalText = copyBtn.innerHTML;
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            
            setTimeout(() => {
                copyBtn.innerHTML = originalText;
            }, 2000);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update URL without reloading
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabId);
                    window.history.pushState({}, '', url);
                    
                    // Remove active class from all tabs
                    tabs.forEach(t => t.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    
                    // Hide all tab contents
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Show the selected tab content
                    document.getElementById(tabId + '-tab')?.classList.add('active');
                });
            });
            
            // Gift card selection in Control tab
            const giftCards = document.querySelectorAll('#giftGrid .gift-card:not(.empty)');
            const giftSelect = document.getElementById('gift_id');
            
            giftCards.forEach(card => {
                card.addEventListener('click', function() {
                    const giftId = this.getAttribute('data-gift-id');
                    
                    // Update the select element
                    if (giftSelect) {
                        giftSelect.value = giftId;
                    }
                    
                    // Update selected class
                    giftCards.forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            // Gift card selection in Boost tab
            const boostGiftCards = document.querySelectorAll('#boostGiftGrid .gift-card:not(.empty)');
            
            boostGiftCards.forEach(card => {
                card.addEventListener('click', function() {
                    const giftId = this.getAttribute('data-gift-id');
                    
                    // Find the gift select in the boost tab
                    const boostGiftSelect = document.querySelector('#boost-tab #gift_id');
                    
                    // Update the select element if it exists
                    if (boostGiftSelect) {
                        boostGiftSelect.value = giftId;
                    }
                    
                    // Update selected class
                    boostGiftCards.forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            // Theme card selection
            const themeCards = document.querySelectorAll('.theme-card');
            
            themeCards.forEach(card => {
                card.addEventListener('click', function() {
                    const radio = this.querySelector('.theme-radio');
                    radio.checked = true;
                    
                    // Update selected class
                    themeCards.forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            // Real-time updates using polling
            let lastWinnerId = 0;
            if (document.querySelectorAll('.winner-item').length > 0) {
                // Get ID of last winner if winners exist
                const lastWinner = document.querySelector('.winner-item');
                if (lastWinner) {
                    lastWinnerId = lastWinner.getAttribute('data-id') || 0;
                }
            }
            
            // Function to update UI with real-time data
            function updateSessionData() {
                fetch(`get_session_status.php?id=${<?php echo $session_id; ?>}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update statistics
                        document.getElementById('currentRoundValue').textContent = data.current_round || '<?php echo $round_number; ?>';
                        document.getElementById('remainingGiftsValue').textContent = data.remaining_gifts || '<?php echo $remaining_gifts; ?>';
                        document.getElementById('totalGiftsValue').textContent = data.total_gifts || '<?php echo $total_gifts; ?>';
                        document.getElementById('winnersCountValue').textContent = data.winners_count || '<?php echo $session['winners_count']; ?>';
                        
                        // Update round number in rounds tab if it exists
                        const roundNumberDisplay = document.querySelector('.round-number-display');
                        if (roundNumberDisplay) {
                            roundNumberDisplay.textContent = data.current_round || '<?php echo $round_number; ?>';
                        }
                        
                        // Update round progress bar in rounds tab if it exists
                        const roundProgressBar = document.querySelector('.round-progress-bar');
                        if (roundProgressBar && data.remaining_gifts && data.total_gifts) {
                            const percentage = ((data.total_gifts - data.remaining_gifts) / data.total_gifts * 100);
                            roundProgressBar.style.width = `${percentage}%`;
                        }
                        
                        // Update gifts grid if gifts data is available
                        if (data.gifts) {
                            updateGiftsGrid(data.gifts);
                        }
                        
                        // Update winners list if needed
                        if (data.recent_winners) {
                            updateWinnersList(data.recent_winners);
                        }
                        
                        // Update boosts if needed
                        if (data.boosts) {
                            updateBoostsList(data.boosts);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error updating session data:', error);
                });
            }
            
            // Function to update the gifts grid
            function updateGiftsGrid(gifts) {
                // Update both gift grids (control tab and boost tab)
                updateSingleGiftGrid('#giftGrid', gifts);
                updateSingleGiftGrid('#boostGiftGrid', gifts);
                
                // Also update the gift select dropdowns
                updateGiftSelects(gifts);
            }
            
            // Function to update a single gift grid
            function updateSingleGiftGrid(selector, gifts) {
                const giftGrid = document.querySelector(selector);
                if (!giftGrid) return;
                
                gifts.forEach(gift => {
                    const giftCard = giftGrid.querySelector(`.gift-card[data-gift-id="${gift.id}"]`);
                    if (giftCard) {
                        const quantityElement = giftCard.querySelector('.gift-quantity span');
                        const progressBar = giftCard.querySelector('.gift-progress-bar');
                        
                        if (quantityElement) {
                            quantityElement.textContent = `${gift.remaining} of ${gift.total_quantity} remaining`;
                        }
                        
                        if (progressBar) {
                            const percentage = ((gift.total_quantity - gift.remaining) / gift.total_quantity) * 100;
                            progressBar.style.width = `${percentage}%`;
                        }
                        
                        // Update empty class if needed
                        if (gift.remaining <= 0) {
                            giftCard.classList.add('empty');
                        } else {
                            giftCard.classList.remove('empty');
                        }
                    }
                });
            }
            
            // Function to update gift select options
            function updateGiftSelects(gifts) {
                // Update all gift selects on the page
                const giftSelects = document.querySelectorAll('select[name="gift_id"]');
                
                giftSelects.forEach(select => {
                    // Store current selection
                    const currentSelection = select.value;
                    
                    // Build new options
                    let options = '<option value="">-- Select a gift --</option>';
                    gifts.forEach(gift => {
                        if (gift.remaining > 0) {
                            options += `<option value="${gift.id}" ${currentSelection == gift.id ? 'selected' : ''}>
                                ${gift.name} (${gift.remaining} left)
                            </option>`;
                        }
                    });
                    
                    select.innerHTML = options;
                });
            }
            
            // Function to update winners list
            function updateWinnersList(winners) {
                const winnersList = document.getElementById('winnersList');
                if (!winnersList) return;
                
                if (winners.length === 0) {
                    winnersList.innerHTML = '<div class="boost-empty">No winners yet</div>';
                    return;
                }
                
                let winnersHtml = '';
                winners.forEach(winner => {
                    winnersHtml += `
                    <div class="winner-item" data-id="${winner.id}">
                        <div class="winner-icon">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="winner-info">
                            <div class="winner-name">
                                ${winner.winner_name || 'Anonymous Winner'}
                                <span class="winner-time">${winner.time}</span>
                            </div>
                            <div class="winner-details">
                                <div class="winner-detail">
                                    <i class="fas fa-hashtag"></i>
                                    Round ${winner.round_number}
                                </div>
                                ${winner.winner_nic ? `
                                <div class="winner-detail">
                                    <i class="fas fa-id-card"></i>
                                    ${winner.winner_nic}
                                </div>` : ''}
                                ${winner.winner_phone ? `
                                <div class="winner-detail">
                                    <i class="fas fa-phone"></i>
                                    ${winner.winner_phone}
                                </div>` : ''}
                            </div>
                            <div class="winner-gift">
                                ${winner.gift_name}
                                ${winner.boosted ? '<span class="boosted-badge">Boosted</span>' : ''}
                            </div>
                        </div>
                    </div>`;
                });
                
                winnersList.innerHTML = winnersHtml;
            }
            
            // Function to update boosts list
            function updateBoostsList(boosts) {
                // Update boost lists in both tabs
                updateSingleBoostList('#control-tab .boost-list', boosts);
                updateSingleBoostList('#boost-tab .boost-list', boosts);
            }
            
            // Function to update a single boost list
            function updateSingleBoostList(selector, boosts) {
                const boostsList = document.querySelector(selector);
                if (!boostsList) return;
                
                if (boosts.length === 0) {
                    boostsList.innerHTML = '<div class="boost-empty">No active boosts</div>';
                    return;
                }
                
                let boostsHtml = '';
                boosts.forEach(boost => {
                    boostsHtml += `
                    <div class="boost-item">
                        <div class="boost-info">
                            <div class="boost-round">Play Round ${boost.target_round}</div>
                            <div class="boost-gift">${boost.gift_name}</div>
                        </div>
                        <form method="post" action="remove_boost.php">
                            <input type="hidden" name="action" value="remove_boost">
                            <input type="hidden" name="boost_id" value="${boost.id}">
                            <input type="hidden" name="session_id" value="${<?php echo $session_id; ?>}">
                            <button type="submit" class="boost-remove" title="Remove Boost">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>`;
                });
                
                boostsList.innerHTML = boostsHtml;
            }
            
            // Start polling for updates every 3 seconds
            setInterval(updateSessionData, 3000);
            
            // Initial update
            updateSessionData();
        });
    </script>
</body>
</html>