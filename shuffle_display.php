<?php
/**
 * Shuffle Display - Enhanced Version
 * 
 * Customer-facing interface for the gift shuffle animation.
 * This enhanced version includes:
 * - Better authentication and security
 * - Responsive design improvements
 * - Performance optimizations
 * - Enhanced error handling
 * - Improved animations
 */

// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Include theme loader
define('INCLUDED', true);
require_once "includes/theme_loader.php";

// Validate access code
if (!isset($_GET['code']) || empty($_GET['code'])) {
    die("<div style='text-align:center; margin-top:50px; font-family:Arial,sans-serif;'><h1>Error</h1><p>Access code is required to view this page</p><p><a href='index.php'>Return to login page</a></p></div>");
}

$access_code = filter_var($_GET['code'], FILTER_SANITIZE_STRING);

// Enhanced security - verify user is logged in OR validate a special display token
$isAuthorizedDisplay = false;

// Option 1: Check if user is logged in
if (isLoggedIn()) {
    $isAuthorizedDisplay = true;
}
// Option 2: Check for a valid display token in the session
elseif (isset($_SESSION['display_token']) && isset($_SESSION['display_session_id'])) {
    try {
        // Verify the token is valid for this access code
        $session = executeQuery(
            "SELECT id FROM shuffle_sessions 
             WHERE access_code = ? AND status = 'active'",
            [$access_code],
            's'
        );
        
        if (!empty($session) && $_SESSION['display_session_id'] == $session[0]['id']) {
            $isAuthorizedDisplay = true;
        }
    } catch (Exception $e) {
        error_log("Error validating display token: " . $e->getMessage());
    }
}

// If not authorized through either method, redirect to auth page
if (!$isAuthorizedDisplay) {
    // Store the requested access code for after authentication
    $_SESSION['requested_display_code'] = $access_code;
    header("Location: display_auth.php");
    exit;
}

// Track previous breakdown ID for change detection
$last_breakdown_id = isset($_GET['last_bd']) ? filter_var($_GET['last_bd'], FILTER_VALIDATE_INT) : 0;

// Validate access code and get session details
try {
    $session = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, gb.total_number,
               ss.theme_id, ss.collect_customer_info,  
               (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count,
               (SELECT SUM(bg.quantity) FROM breakdown_gifts bg WHERE bg.breakdown_id = ss.breakdown_id) as total_gifts,
               ss.breakdown_id
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         WHERE ss.access_code = ? AND ss.status = 'active'",
        [$access_code],
        's'
    );
    
    if (empty($session)) {
        die("<div style='text-align:center; margin-top:50px; font-family:Arial,sans-serif;'><h1>Error</h1><p>Invalid or expired access code</p><p><a href='index.php'>Return to login page</a></p></div>");
    }
    
    $session = $session[0];
    $session_id = $session['id'];
    
    // Check for breakdown changes
    $breakdown_changed = false;
    if ($last_breakdown_id > 0 && $session['breakdown_id'] != $last_breakdown_id) {
        $breakdown_changed = true;
    }
    
    // Get current round
    $current_round = getCurrentRound($session_id);
    
    if (!$current_round) {
        // No active round, try to create one
        $current_round = getOrCreateNextRound($session_id);
        
        if (!$current_round) {
            die("<div style='text-align:center; margin-top:50px; font-family:Arial,sans-serif;'><h1>Error</h1><p>Could not initialize breakdown round</p><p><a href='index.php'>Return to login page</a></p></div>");
        }
    }
    
    $round_id = $current_round['id'];
    $round_number = $current_round['round_number'];
    
    // Get gifts remaining in current round
    $round_gifts = getRoundGifts($round_id);
    $gifts_remaining = 0;
    
    foreach ($round_gifts as $gift) {
        $remaining = $gift['quantity_available'] - $gift['quantity_used'];
        if ($remaining > 0) {
            $gifts_remaining += $remaining;
        }
    }
    
    // Check if gifts are still available in current round
    $shuffle_enabled = ($gifts_remaining > 0);
    
    // Get theme information
    $theme_id = $session['theme_id'] ?? 1;
    $theme = getThemeById($theme_id);
    $themeAssets = loadThemeAssets($theme_id);
    $themeHtml = getThemeHtml($theme_id);
    
} catch (Exception $e) {
    error_log("Error validating access code: " . $e->getMessage());
    die("<div style='text-align:center; margin-top:50px; font-family:Arial,sans-serif;'><h1>Error</h1><p>An error occurred while validating the access code</p><p><a href='index.php'>Return to login page</a></p></div>");
}

// Process gift selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        // Check if there are any gifts available in current round
        if (!$shuffle_enabled) {
            // Try to create a new round
            $new_round = getOrCreateNextRound($session_id);
            
            if ($new_round) {
                $current_round = $new_round;
                $round_id = $current_round['id'];
                $round_number = $current_round['round_number'];
                $shuffle_enabled = true;
                
                // Log this important event for monitoring
                error_log("IMPORTANT: New breakdown round started automatically. Session ID: {$session_id}, New round: {$round_number}");
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No more gifts available for this session'
                ]);
                exit;
            }
        }
        
        // Collect customer information if enabled
        $customer_name = null;
        $customer_nic = null;
        $customer_phone = null;
        
        if ($session['collect_customer_info']) {
            $customer_name = isset($_POST['customer_name']) ? filter_var(trim($_POST['customer_name']), FILTER_SANITIZE_STRING) : null;
            $customer_nic = isset($_POST['customer_nic']) ? filter_var(trim($_POST['customer_nic']), FILTER_SANITIZE_STRING) : null;
            $customer_phone = isset($_POST['customer_phone']) ? filter_var(trim($_POST['customer_phone']), FILTER_SANITIZE_STRING) : null;
            
            // Validate customer name if collection is enabled
            if (empty($customer_name)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Customer name is required'
                ]);
                exit;
            }
        }
        
        // Determine the next play round number
        $next_round_query = executeQuery(
            "SELECT IFNULL(MAX(round_number), 0) + 1 as next_round FROM gift_winners WHERE session_id = ?",
            [$session_id],
            'i'
        );
        $next_play_round = $next_round_query[0]['next_round'] ?? 1;
        
        // Get a random gift (with boost if available)
        // Pass both session_id and next_play_round to check for play round boosts
        $selection = getRandomAvailableGift($round_id, $session_id, $next_play_round);
        
        if (!$selection) {
            echo json_encode([
                'success' => false,
                'error' => 'No gifts available in the current round'
            ]);
            exit;
        }
        
        $selected_gift = $selection['gift'];
        $is_boosted = $selection['boosted'];
        
        // Record the winner
        $winner = recordWinner(
            $session_id,
            $round_id,
            $selected_gift['gift_id'],
            $customer_name,
            $customer_nic,
            $customer_phone,
            $is_boosted
        );
        
        if (!$winner) {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to record winner'
            ]);
            exit;
        }
        
        // Return the result
        echo json_encode([
            'success' => true,
            'gift' => [
                'id' => $selected_gift['gift_id'],
                'name' => $selected_gift['name'],
                'description' => $selected_gift['description'],
                'boosted' => $is_boosted
            ],
            'round_number' => $winner['round_number'],
            'breakdown_round' => $round_number
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Error in gift selection: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'An error occurred during gift selection. Please try again.'
        ]);
        exit;
    }
}

// Get recent winners for ticker
try {
    $recent_winners = executeQuery(
        "SELECT gw.winner_name, g.name as gift_name, gw.win_time
         FROM gift_winners gw
         JOIN gifts g ON gw.gift_id = g.id
         WHERE gw.session_id = ?
         ORDER BY gw.win_time DESC
         LIMIT 10",
        [$session_id],
        'i'
    );
} catch (Exception $e) {
    error_log("Error getting recent winners: " . $e->getMessage());
    $recent_winners = [];
}

// Check if customer info collection is enabled
$collect_info = $session['collect_customer_info'] ?? false;

// Generate CSRF token for form submission
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gift Shuffle</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Include external theme CSS files -->
    <?php foreach ($themeAssets['css'] as $cssFile): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile); ?>">
    <?php endforeach; ?>
    
    <style>
        :root {
            --primary-color: #1a73e8;
            --secondary-color: #6c5ce7;
            --background-color: #f5f9ff;
            --text-light: #ffffff;
            --text-dark: #202124;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
        }

        /* Reset all margins and paddings */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Make the body fill the viewport */
        body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background-color: var(--background-color);
        }
        
        /* Make the main content fill the viewport */
        .main-content {
            width: 100vw;
            height: 100vh;
            padding: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        /* Adjust the game container to be centered */
        .shuffle-container {
            max-width: none;
            width: 90vw;
            height: 70vh;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        /* Customer information form */
        .customer-info-form {
            text-align: left;
            margin-bottom: 30px;
            width: 100%;
            max-width: 500px;
        }

        .form-title {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: var(--primary-color);
            text-align: center;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-dark);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .error-message {
            color: var(--danger-color);
            font-size: 0.9rem;
            margin-top: 5px;
            display: none;
        }

        /* Play button */
        .play-button {
            padding: 20px 50px;
            font-size: 1.5rem;
            font-weight: 700;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px auto;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .play-button:hover {
            background: var(--secondary-color);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .play-button:active {
            transform: translateY(0);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        }

        .play-button i {
            font-size: 1.8rem;
        }

        .play-button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Continue button style */
        .continue-button {
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: 700;
            background: var(--success-color);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 30px auto 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .continue-button:hover {
            background: #219a3a;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .continue-button:active {
            transform: translateY(0);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        }

        .continue-button i {
            font-size: 1.5rem;
        }

        .ready-text {
            font-size: 1.8rem;
            color: var(--text-dark);
            animation: pulse 2s infinite;
            margin-bottom: 20px;
        }

        @keyframes pulse {
            0% { opacity: 0.8; }
            50% { opacity: 1; }
            100% { opacity: 0.8; }
        }

        /* Result state */
        .shuffling-state, .result-state {
            display: none;
            width: 100%;
        }

        .result-header {
            margin-bottom: 20px;
            font-size: 2rem;
            color: var(--primary-color);
        }

        .gift-reveal {
            font-size: 3.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 20px;
            animation: popIn 0.5s ease-out;
        }

        @keyframes popIn {
            0% { transform: scale(0); opacity: 0; }
            70% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }

        .gift-description {
            font-size: 1.5rem;
            color: #666;
            margin-bottom: 30px;
        }

        .boost-badge {
            display: none; /* Always hide boost badge from customers */
            padding: 10px 20px;
            background: rgba(108, 92, 231, 0.1);
            color: var(--secondary-color);
            border-radius: 30px;
            font-size: 1.2rem;
            font-weight: 600;
            margin-top: 20px;
        }

        /* Confetti */
        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background: var(--primary-color);
            animation: confetti 5s ease-in-out infinite;
            opacity: 0;
        }

        @keyframes confetti {
            0% { transform: translateY(-100%) rotate(0deg); opacity: 1; }
            100% { transform: translateY(1000%) rotate(720deg); opacity: 0; }
        }

        /* Recent winners ticker */
        .winners-ticker {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.8);
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            padding: 10px;
            z-index: 100;
        }

        .ticker-title {
            font-size: 1rem;
            color: var(--text-dark);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ticker-title i {
            color: var(--primary-color);
        }

        .ticker-container {
            overflow: hidden;
            white-space: nowrap;
            position: relative;
        }

        .ticker-track {
            display: inline-block;
            animation: ticker 30s linear infinite;
        }

        @keyframes ticker {
            0% { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }

        .ticker-item {
            display: inline-block;
            padding: 8px 15px;
            margin-right: 15px;
            background: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Fullscreen button */
        .fullscreen-btn {
            position: fixed;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            transition: background 0.3s ease;
        }

        .fullscreen-btn:hover {
            background: rgba(0, 0, 0, 0.4);
        }

        /* Auth indicator for security visibility */
        .auth-indicator {
            position: fixed;
            top: 10px;
            left: 10px;
            font-size: 0.8rem;
            background: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
            padding: 5px 10px;
            border-radius: 20px;
            z-index: 1000;
        }
        
        /* Round indicator */
        .round-indicator {
            position: fixed;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.9rem;
            background: rgba(108, 92, 231, 0.2);
            color: var(--secondary-color);
            padding: 5px 15px;
            border-radius: 20px;
            z-index: 1000;
            font-weight: 600;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 8px solid rgba(255, 255, 255, 0.1);
            border-top-color: var(--primary-color);
            animation: spin 1s infinite linear;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            color: white;
            font-size: 1.2rem;
            margin-top: 20px;
        }

        /* Error message styles */
        .error-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        .error-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .error-box {
            background: white;
            border-radius: 10px;
            padding: 30px;
            max-width: 90%;
            width: 400px;
            text-align: center;
        }

        .error-icon {
            font-size: 3rem;
            color: var(--danger-color);
            margin-bottom: 15px;
        }

        .error-title {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .error-message {
            margin-bottom: 20px;
            color: #666;
        }

        .error-button {
            padding: 10px 25px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .error-button:hover {
            background: var(--primary-hover);
        }

        /* Connection status indicator */
        .connection-status {
            position: fixed;
            bottom: 40px;
            right: 10px;
            font-size: 0.8rem;
            background: rgba(40, 167, 69, 0.2);
            color: var(--success-color);
            padding: 5px 10px;
            border-radius: 20px;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .connection-status.offline {
            background: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }

        .connection-status i {
            font-size: 0.7rem;
        }

        /* Accessibility improvements */
        .screen-reader-text {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .shuffle-container {
                width: 95vw;
                padding: 20px;
                height: 80vh;
            }
            
            .play-button {
                padding: 15px 30px;
                font-size: 1.2rem;
            }
            
            .ready-text {
                font-size: 1.4rem;
            }
            
            .gift-reveal {
                font-size: 2.5rem;
            }

            .form-title {
                font-size: 1.3rem;
            }

            .customer-info-form {
                max-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .shuffle-container {
                padding: 15px;
                height: 85vh;
            }

            .play-button {
                padding: 12px 25px;
                font-size: 1.1rem;
            }

            .ready-text {
                font-size: 1.2rem;
            }

            .gift-reveal {
                font-size: 2rem;
            }

            .gift-description {
                font-size: 1.2rem;
            }

            .continue-button {
                padding: 12px 30px;
                font-size: 1.1rem;
            }
        }

        /* Theme specific styles - Wheel Animation */
        .wheel-animation {
            position: relative;
            width: 300px;
            height: 300px;
            margin: 0 auto 30px;
        }

        .wheel {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: conic-gradient(
                #1a73e8 0% 10%,
                #6c5ce7 10% 20%,
                #e74c3c 20% 30%,
                #2ecc71 30% 40%,
                #f39c12 40% 50%,
                #9b59b6 50% 60%,
                #3498db 60% 70%,
                #e67e22 70% 80%,
                #1abc9c 80% 90%,
                #f1c40f 90% 100%
            );
            transform: rotate(0deg);
            transition: transform 3s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        .spinning .wheel {
            transform: rotate(var(--turn-amount, 1800deg));
        }

        .wheel-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
            z-index: 10;
        }

        .wheel-center i {
            font-size: 24px;
            color: var(--primary-color);
        }

        .wheel-pointer {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 40px;
            background: white;
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
            z-index: 5;
        }

        /* Theme specific styles - Gift Box Animation */
        .gift-box-animation {
            position: relative;
            width: 200px;
            height: 200px;
            margin: 0 auto 30px;
            perspective: 1000px;
        }

        .gift-box {
            width: 100%;
            height: 100%;
            position: relative;
            transform-style: preserve-3d;
        }

        .gift-box-base {
            position: absolute;
            width: 100%;
            height: 80%;
            bottom: 0;
            background: #e74c3c;
            border-radius: 5px;
        }

        .gift-box-lid {
            position: absolute;
            width: 110%;
            height: 30%;
            top: -5%;
            left: -5%;
            background: #c0392b;
            border-radius: 5px;
            transform-origin: center top;
            transition: transform 1s ease-in-out;
        }

        .shuffling .gift-box-lid {
            transform: rotateX(-120deg);
        }

        .gift-ribbon {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 200px;
            background: #f1c40f;
            border-radius: 3px;
            z-index: 5;
        }

        .gift-ribbon::before {
            content: '';
            position: absolute;
            top: 40%;
            left: -90px;
            width: 200px;
            height: 20px;
            background: #f1c40f;
            border-radius: 3px;
        }

        /* Theme specific styles - Slot Machine Animation */
        .slot-machine-animation {
            position: relative;
            width: 300px;
            height: 200px;
            margin: 0 auto 30px;
            background: #2c3e50;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
        }

        .slot-reels {
            display: flex;
            justify-content: space-around;
            height: 100px;
            background: white;
            border-radius: 5px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .slot-reel {
            width: 33.33%;
            overflow: hidden;
            border-right: 1px solid #ddd;
        }

        .slot-reel:last-child {
            border-right: none;
        }

        .slot-reel-items {
            display: flex;
            flex-direction: column;
            animation: none;
        }

        .shuffling .slot-reel-items {
            animation: slotSpin 0.5s linear infinite;
        }

        @keyframes slotSpin {
            0% { transform: translateY(0); }
            100% { transform: translateY(calc(-100% / 7)); }
        }

        .slot-reel-item {
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        .slot-lever {
            position: absolute;
            top: 40%;
            right: -30px;
            width: 20px;
            height: 80px;
            background: #e74c3c;
            border-radius: 10px;
            cursor: pointer;
        }

        .slot-lever::before {
            content: '';
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 30px;
            background: #f1c40f;
            border-radius: 50%;
        }

        /* Theme specific styles - Scratch Card Animation */
        .scratch-card-animation {
            position: relative;
            width: 300px;
            height: 200px;
            margin: 0 auto 30px;
            border-radius: 10px;
            overflow: hidden;
        }

        .scratch-content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: var(--primary-color);
        }

        .scratch-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #6c5ce7, #1a73e8);
            transition: opacity 1.5s ease-out;
        }

        .shuffling .scratch-overlay {
            opacity: 0;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div>
            <div class="loading-spinner"></div>
            <div class="loading-text">Processing...</div>
        </div>
    </div>

    <!-- Error Overlay -->
    <div class="error-overlay" id="errorOverlay">
        <div class="error-box">
            <div class="error-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="error-title">Error</div>
            <div class="error-message" id="errorMessage">Something went wrong. Please try again.</div>
            <button class="error-button" id="errorCloseBtn">OK</button>
        </div>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Auth Indicator (visible only for testing) -->
        <?php if (isLoggedIn()): ?>
        <div class="auth-indicator">
            <i class="fas fa-shield-alt"></i> Secure Mode
        </div>
        <?php endif; ?>
        
        <!-- Fullscreen Button -->
        <button class="fullscreen-btn" id="fullscreenBtn" aria-label="Toggle fullscreen">
            <i class="fas fa-expand"></i>
            <span class="screen-reader-text">Toggle fullscreen</span>
        </button>
        
        <!-- Round Indicator -->
        <div class="round-indicator">
            <i class="fas fa-sync-alt"></i> Round <span id="roundCounter"><?php echo $round_number; ?></span>
        </div>
        
        <!-- Connection Status -->
        <div class="connection-status" id="connectionStatus">
            <i class="fas fa-circle"></i>
            <span>Connected</span>
        </div>
        
        <!-- Shuffle Container -->
        <div class="shuffle-container" id="shuffleContainer">
            <!-- Ready State -->
            <div class="ready-state" id="readyState">
                <div class="ready-text">Try Your Luck!</div>
                
                <?php if ($collect_info): ?>
                <!-- Customer Information Form (only shown if collection is enabled) -->
                <div class="customer-info-form" id="customerInfoForm">
                    <h2 class="form-title">Enter Your Information to Play</h2>
                    
                    <div class="form-group">
                        <label for="customerName">Your Name</label>
                        <input type="text" id="customerName" class="form-control" required aria-required="true">
                        <div class="error-message" id="nameError">Please enter your name</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="customerNIC">NIC Number</label>
                        <input type="text" id="customerNIC" class="form-control">
                        <div class="form-text">Enter your National ID number</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="customerPhone">Phone Number</label>
                        <input type="tel" id="customerPhone" class="form-control">
                    </div>
                </div>
                <?php endif; ?>

                <!-- Play Button -->
                <button type="button" id="playButton" class="play-button" aria-label="Press to play the shuffle game">
                    <i class="fas fa-play-circle"></i>
                    <span>Press to Play</span>
                </button>
            </div>

            <!-- Shuffling State -->
            <div class="shuffling-state" id="shufflingState">
                <!-- Animations based on theme -->
                <?php echo $themeHtml; ?>
                
                <div class="ready-text">Selecting your gift...</div>
            </div>

            <!-- Result State -->
            <div class="result-state" id="resultState">
                <div class="result-header">Congratulations!</div>
                <div class="gift-reveal" id="giftReveal"></div>
                <div class="gift-description" id="giftDescription"></div>
                <!-- Boost badge is hidden from customers -->
                <div class="boost-badge" id="boostBadge" style="display: none;">
                    <i class="fas fa-bolt"></i>
                    Special Selection
                </div>
                
                <!-- Continue Button -->
                <button type="button" id="continueButton" class="continue-button" aria-label="Continue to the next shuffle">
                    <i class="fas fa-check-circle"></i>
                    <span>Continue</span>
                </button>
            </div>
        </div>

        <!-- Recent Winners Ticker -->
        <div class="winners-ticker">
            <div class="ticker-title">
                <i class="fas fa-trophy"></i>
                Recent Winners
            </div>
            <div class="ticker-container">
                <div class="ticker-track" id="tickerTrack">
                    <?php if (!empty($recent_winners)): ?>
                        <?php foreach ($recent_winners as $winner): ?>
                            <div class="ticker-item">
                                <?php echo !empty($winner['winner_name']) ? htmlspecialchars($winner['winner_name']) : 'Anonymous Winner'; ?> 
                                won <?php echo htmlspecialchars($winner['gift_name']); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="ticker-item">Be the first winner!</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Include external theme JavaScript files -->
    <?php foreach ($themeAssets['js'] as $jsFile): ?>
    <script src="<?php echo htmlspecialchars($jsFile); ?>"></script>
    <?php endforeach; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // DOM Elements
            const shuffleContainer = document.getElementById('shuffleContainer');
            const readyState = document.getElementById('readyState');
            const shufflingState = document.getElementById('shufflingState');
            const resultState = document.getElementById('resultState');
            const playButton = document.getElementById('playButton');
            const giftReveal = document.getElementById('giftReveal');
            const giftDescription = document.getElementById('giftDescription');
            const boostBadge = document.getElementById('boostBadge');
            const tickerTrack = document.getElementById('tickerTrack');
            const fullscreenBtn = document.getElementById('fullscreenBtn');
            const continueButton = document.getElementById('continueButton');
            const roundCounter = document.getElementById('roundCounter');
            const loadingOverlay = document.getElementById('loadingOverlay');
            const errorOverlay = document.getElementById('errorOverlay');
            const errorMessage = document.getElementById('errorMessage');
            const errorCloseBtn = document.getElementById('errorCloseBtn');
            const connectionStatus = document.getElementById('connectionStatus');
            
            // Customer info form elements (if enabled)
            const collectInfo = <?php echo $collect_info ? 'true' : 'false'; ?>;
            const customerInfoForm = document.getElementById('customerInfoForm');
            const customerName = document.getElementById('customerName');
            const customerNIC = document.getElementById('customerNIC');
            const customerPhone = document.getElementById('customerPhone');
            const nameError = document.getElementById('nameError');
            
            // State variables
            let isShuffling = false;
            let shuffleTimeout;
            let currentBreakdownId = <?php echo $session['breakdown_id']; ?>;
            let currentRoundNumber = <?php echo $round_number; ?>;
            let statusCheckInterval;
            let isOnline = true;
            const csrfToken = "<?php echo $csrf_token; ?>";
            
            // Show error message
            function showError(message) {
                errorMessage.textContent = message || 'An error occurred. Please try again.';
                errorOverlay.classList.add('active');
            }
            
            // Close error message
            errorCloseBtn.addEventListener('click', function() {
                errorOverlay.classList.remove('active');
            });
            
            // Show/hide loading overlay
            function showLoading() {
                loadingOverlay.classList.add('active');
            }
            
            function hideLoading() {
                loadingOverlay.classList.remove('active');
            }
            
            // Network status detection
            window.addEventListener('online', function() {
                isOnline = true;
                connectionStatus.classList.remove('offline');
                connectionStatus.innerHTML = '<i class="fas fa-circle"></i> Connected';
            });
            
            window.addEventListener('offline', function() {
                isOnline = false;
                connectionStatus.classList.add('offline');
                connectionStatus.innerHTML = '<i class="fas fa-circle"></i> Offline';
            });
            
            // Toggle fullscreen
            fullscreenBtn.addEventListener('click', function() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(err => {
                        console.log(`Error attempting to enable fullscreen: ${err.message}`);
                    });
                    fullscreenBtn.innerHTML = '<i class="fas fa-compress"></i><span class="screen-reader-text">Exit fullscreen</span>';
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
fullscreenBtn.innerHTML = '<i class="fas fa-expand"></i><span class="screen-reader-text">Toggle fullscreen</span>';
}
}
});

// Document fullscreen change event
document.addEventListener('fullscreenchange', function() {
    if (document.fullscreenElement) {
        fullscreenBtn.innerHTML = '<i class="fas fa-compress"></i><span class="screen-reader-text">Exit fullscreen</span>';
    } else {
        fullscreenBtn.innerHTML = '<i class="fas fa-expand"></i><span class="screen-reader-text">Toggle fullscreen</span>';
    }
});

// Play button click handler
playButton.addEventListener('click', function() {
    if (isShuffling || playButton.disabled || !isOnline) return;
    
    // Validate customer info if collection is enabled
    if (collectInfo) {
        if (!customerName.value.trim()) {
            nameError.style.display = 'block';
            customerName.focus();
            return;
        } else {
            nameError.style.display = 'none';
        }
    }
    
    // Start shuffle
    startShuffle();
});

// Continue button click handler
continueButton.addEventListener('click', function() {
    resetToReady();
});

// Function to start the shuffle
function startShuffle() {
    if (!isOnline) {
        showError('You are currently offline. Please check your internet connection and try again.');
        return;
    }
    
    isShuffling = true;
    showLoading();
    
    // Hide ready state, show shuffling state
    readyState.style.display = 'none';
    shufflingState.style.display = 'block';
    resultState.style.display = 'none';
    
    // Add shuffling class to activate animations
    shuffleContainer.classList.add('shuffling');
    
    // Prepare form data
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    
    // Add customer info if enabled
    if (collectInfo) {
        formData.append('customer_name', customerName.value.trim());
        formData.append('customer_nic', customerNIC.value.trim());
        formData.append('customer_phone', customerPhone.value.trim());
    }
    
    // Send request to server
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        hideLoading();
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        // Show results after delay to see the animation
        shuffleTimeout = setTimeout(() => {
            if (data.success) {
                // Check if the breakdown round has changed
                if (data.breakdown_round && data.breakdown_round != currentRoundNumber) {
                    currentRoundNumber = data.breakdown_round;
                    
                    // Update the round counter display
                    if (roundCounter) {
                        roundCounter.textContent = currentRoundNumber;
                        // Add highlight effect
                        roundCounter.parentElement.classList.add('highlight');
                        setTimeout(() => {
                            roundCounter.parentElement.classList.remove('highlight');
                        }, 2000);
                    }
                }
                
                showGiftResult(data.gift);
                updateTickerWithNewWinner(data.gift);
            } else {
                showError(data.error || 'An error occurred during the shuffle. Please try again.');
                resetToReady();
            }
        }, 3000); // Match this to animation duration
    })
    .catch(error => {
        hideLoading();
        console.error('Error:', error);
        showError('An error occurred during the shuffle. Please try again.');
        resetToReady();
    });
}

// Function to show gift result
function showGiftResult(gift) {
    // Remove shuffling class to stop animations
    shuffleContainer.classList.remove('shuffling');
    
    // Hide shuffling state, show result state
    shufflingState.style.display = 'none';
    resultState.style.display = 'block';
    
    // Set gift details
    giftReveal.textContent = gift.name;
    giftDescription.textContent = gift.description || 'Enjoy your gift!';
    
    // Make sure the boost badge is always hidden for customers
    if (boostBadge) {
        boostBadge.style.display = 'none';
    }
    
    // Create confetti
    createConfetti();
}

// Function to reset to ready state
function resetToReady() {
    resultState.style.display = 'none';
    shufflingState.style.display = 'none';
    readyState.style.display = 'block';
    
    // Clear form fields if info collection is enabled
    if (collectInfo) {
        customerName.value = '';
        customerNIC.value = '';
        customerPhone.value = '';
    }
    
    isShuffling = false;
}

// Create confetti elements
function createConfetti() {
    // Remove any existing confetti
    document.querySelectorAll('.confetti').forEach(el => el.remove());
    
    for (let i = 0; i < 50; i++) {
        const confetti = document.createElement('div');
        confetti.classList.add('confetti');
        
        // Random properties
        confetti.style.left = Math.random() * 100 + '%';
        confetti.style.width = Math.random() * 10 + 5 + 'px';
        confetti.style.height = confetti.style.width;
        confetti.style.background = getRandomColor();
        confetti.style.animationDelay = Math.random() * 5 + 's';
        confetti.style.animationDuration = Math.random() * 3 + 2 + 's';
        
        shuffleContainer.appendChild(confetti);
        
        // Remove after animation
        setTimeout(() => {
            confetti.remove();
        }, 5000);
    }
}

// Get random color for confetti
function getRandomColor() {
    const colors = ['#1a73e8', '#6c5ce7', '#4caf50', '#ff9800', '#f44336', '#9c27b0', '#e91e63', '#00bcd4'];
    return colors[Math.floor(Math.random() * colors.length)];
}

// Update ticker with new winner
function updateTickerWithNewWinner(gift) {
    const winner = collectInfo ? customerName.value.trim() : 'Anonymous Winner';
    const newItem = document.createElement('div');
    newItem.classList.add('ticker-item');
    newItem.textContent = `${winner} won ${gift.name}`;
    
    // Add to beginning of ticker
    tickerTrack.insertBefore(newItem, tickerTrack.firstChild);
    
    // Restart animation
    tickerTrack.style.animation = 'none';
    void tickerTrack.offsetWidth; // Trigger reflow
    tickerTrack.style.animation = 'ticker 30s linear infinite';
}

// Check for session status updates and breakdown changes periodically
function startStatusChecks() {
    statusCheckInterval = setInterval(function() {
        if (!isOnline) return;
        
        fetch(`check_session_status.php?session_id=<?php echo $session_id; ?>&t=${Date.now()}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            // Check if breakdown has changed
            if (data.breakdown_id != currentBreakdownId) {
                // Reload the page with the new breakdown ID
                window.location.href = `shuffle_display.php?code=<?php echo $access_code; ?>&last_bd=${currentBreakdownId}`;
                return;
            }
            
            // Check if breakdown round has changed
            if (data.breakdown_round && data.breakdown_round != currentRoundNumber) {
                currentRoundNumber = data.breakdown_round;
                
                // Update the round counter display
                if (roundCounter) {
                    roundCounter.textContent = currentRoundNumber;
                    // Add highlight effect
                    roundCounter.parentElement.classList.add('highlight');
                    setTimeout(() => {
                        roundCounter.parentElement.classList.remove('highlight');
                    }, 2000);
                }
            }
            
            // Check if session is still active
            if (!data.active) {
                playButton.disabled = true;
                const message = document.querySelector('.ready-text');
                if (message) {
                    message.textContent = 'This session has ended.';
                }
                return;
            }
            
            // Check if gifts remain in the current breakdown
            // Instead of disabling the button, we'll always allow play since
            // breakdown rounds will continue automatically
            if (playButton.disabled) {
                playButton.disabled = false;
                const message = document.querySelector('.ready-text');
                if (message) {
                    message.textContent = 'Try Your Luck!';
                }
            }
        })
        .catch(error => {
            console.error('Error checking session status:', error);
        });
    }, 3000); // Check every 3 seconds
}

// Start status checks
startStatusChecks();

// Clean up timeouts when leaving the page
window.addEventListener('beforeunload', function() {
    clearTimeout(shuffleTimeout);
    clearInterval(statusCheckInterval);
});

<?php if ($breakdown_changed): ?>
// If breakdown just changed, show a notification
const notification = document.createElement('div');
notification.style.position = 'fixed';
notification.style.top = '20px';
notification.style.left = '50%';
notification.style.transform = 'translateX(-50%)';
notification.style.background = 'rgba(108, 92, 231, 0.9)';
notification.style.color = 'white';
notification.style.padding = '15px 30px';
notification.style.borderRadius = '50px';
notification.style.zIndex = '2000';
notification.style.boxShadow = '0 5px 15px rgba(0,0,0,0.3)';
notification.innerHTML = '<i class="fas fa-sync-alt" style="margin-right: 10px;"></i> New gifts are now available!';

document.body.appendChild(notification);

setTimeout(() => {
    notification.style.opacity = '0';
    notification.style.transition = 'opacity 0.5s ease';
    setTimeout(() => notification.remove(), 500);
}, 3000);
<?php endif; ?>

// Add service worker for better offline support
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js')
    .then(reg => console.log('Service worker registered'))
    .catch(err => console.log('Service worker not registered', err));
}

// Improve accessibility
document.querySelectorAll('.form-control').forEach(input => {
    input.addEventListener('invalid', function() {
        const errorElement = document.getElementById(this.id + 'Error');
        if (errorElement) {
            errorElement.style.display = 'block';
        }
    });
});

// Keyboard navigation improvement
playButton.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.click();
    }
});

continueButton.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.click();
    }
});
});
    </script>
</body>
</html>