<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Check if user is logged in
requireLogin();

// Check if session ID is provided
if (!isset($_GET['session_id']) || empty($_GET['session_id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Session ID is required'
    ]);
    exit;
}

$session_id = (int)$_GET['session_id'];

try {
    // Get session details including breakdown and current round
    $session = executeQuery(
        "SELECT ss.*, gb.total_number
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         WHERE ss.id = ? AND ss.created_by = ?",
        [$session_id, $_SESSION['id']],
        'ii'
    );
    
    if (empty($session)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Session not found or you do not have permission to access it'
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
                'success' => false,
                'error' => 'Could not get or create an active round for this session'
            ]);
            exit;
        }
        
        $current_round = $round;
    }
    
    $round_id = $current_round['id'];
    
    // Get all gifts for this round with quantities
    $round_gifts = getRoundGifts($round_id);
    
    // Format the response
    $result = [];
    foreach ($round_gifts as $gift) {
        $remaining = $gift['quantity_available'] - $gift['quantity_used'];
        
        // Ensure remaining is not negative
        if ($remaining < 0) {
            $remaining = 0;
        }
        
        // Debug log gift quantities
        error_log("Gift ID {$gift['gift_id']}: Total {$gift['quantity_available']}, Used {$gift['quantity_used']}, Remaining {$remaining}");
        
        $result[] = [
            'id' => $gift['gift_id'],
            'name' => $gift['name'],
            'total_quantity' => $gift['quantity_available'],
            'used_quantity' => $gift['quantity_used'],
            'remaining' => $remaining
        ];
    }
    
    // Return gift quantities as JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'round_id' => $round_id,
        'round_number' => $current_round['round_number'],
        'gifts' => $result
    ]);
    
} catch (Exception $e) {
    error_log("Error getting gift quantities: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while retrieving gift quantities: ' . $e->getMessage()
    ]);
}
?>