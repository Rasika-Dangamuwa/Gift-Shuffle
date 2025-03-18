<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Check if session ID is provided
if (!isset($_GET['session_id']) || empty($_GET['session_id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Session ID is required'
    ]);
    exit;
}

$session_id = (int)$_GET['session_id'];

try {
    // Get session status, breakdown info
    $session = executeQuery(
        "SELECT ss.status, ss.breakdown_id, ss.theme_id, ss.collect_customer_info,
                gb.name as breakdown_name, gb.total_number, 
                (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         WHERE ss.id = ?",
        [$session_id],
        'i'
    );
    
    if (empty($session)) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Session not found'
        ]);
        exit;
    }
    
    $session = $session[0];
    
    // Get the current active round
    $current_round = getCurrentRound($session_id);
    
    if (!$current_round) {
        // No active round, try to create one
        $round = getOrCreateNextRound($session_id);
        
        if (!$round) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Could not get or create an active round for this session'
            ]);
            exit;
        }
        
        $current_round = $round;
    }
    
    $round_id = $current_round['id'];
    $round_number = $current_round['round_number'];
    
    // Get all gifts for this round with quantities
    $round_gifts = getRoundGifts($round_id);
    
    // Calculate gifts remaining
    $gifts_remaining = 0;
    foreach ($round_gifts as $gift) {
        $remaining = $gift['quantity_available'] - $gift['quantity_used'];
        if ($remaining > 0) {
            $gifts_remaining += $remaining;
        }
    }
    
    $is_active = ($session['status'] === 'active');
    
    // Return status info
    header('Content-Type: application/json');
    echo json_encode([
        'active' => $is_active,
        'gifts_remaining' => $gifts_remaining,
        'winners_count' => $session['winners_count'],
        'breakdown_id' => $session['breakdown_id'],
        'breakdown_name' => $session['breakdown_name'],
        'breakdown_round' => $round_number,
        'round_id' => $round_id,
        'theme_id' => $session['theme_id'],
        'collect_customer_info' => $session['collect_customer_info']
    ]);
    
} catch (Exception $e) {
    error_log("Error in check_session_status: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'An error occurred while checking session status'
    ]);
}
?>