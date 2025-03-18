<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

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
$current_round = isset($_GET['current_round']) ? (int)$_GET['current_round'] : 0;

try {
    // Get the current total winners count (round number)
    $result = executeQuery(
        "SELECT 
            (SELECT COUNT(*) FROM gift_winners WHERE session_id = ?) as current_round,
            ss.breakdown_id, ss.breakdown_round
         FROM shuffle_sessions ss
         WHERE ss.id = ?",
        [$session_id, $session_id],
        'ii'
    );
    
    if (empty($result)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Session not found'
        ]);
        exit;
    }
    
    $new_round = (int)$result[0]['current_round'];
    $breakdown_id = (int)$result[0]['breakdown_id'];
    $breakdown_round = (int)$result[0]['breakdown_round'];
    
    $response = [
        'success' => true,
        'current_round' => $new_round,
        'breakdown_id' => $breakdown_id,
        'breakdown_round' => $breakdown_round
    ];
    
    // If there's a new round (greater than what the client currently knows about)
    // then get the latest gift information for that round
    if ($new_round > $current_round) {
        $latest_gift = executeQuery(
            "SELECT gw.*, g.name, g.description
             FROM gift_winners gw
             JOIN gifts g ON gw.gift_id = g.id
             WHERE gw.session_id = ? AND gw.round_number = ?",
            [$session_id, $new_round],
            'ii'
        );
        
        if (!empty($latest_gift)) {
            $gift = $latest_gift[0];
            $response['latest_gift'] = [
                'id' => $gift['gift_id'],
                'name' => $gift['name'],
                'description' => $gift['description'],
                'boosted' => (bool)$gift['boosted']
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error in check_round: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while checking for updates'
    ]);
}
?>