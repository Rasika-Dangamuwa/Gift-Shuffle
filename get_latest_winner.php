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
$last_round = isset($_GET['last_round']) ? (int)$_GET['last_round'] : 0;

try {
    // Get the latest winner if newer than last_round
    $latest_winner = executeQuery(
        "SELECT gw.*, g.name, g.description
         FROM gift_winners gw
         JOIN gifts g ON gw.gift_id = g.id
         WHERE gw.session_id = ? AND gw.round_number > ?
         ORDER BY gw.round_number DESC
         LIMIT 1",
        [$session_id, $last_round],
        'ii'
    );
    
    if (empty($latest_winner)) {
        // No new winners
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'new_winner' => false,
            'current_round' => $last_round
        ]);
        exit;
    }
    
    $winner = $latest_winner[0];
    
    // Return the latest winner info
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'new_winner' => true,
        'current_round' => $winner['round_number'],
        'latest_gift' => [
            'id' => $winner['gift_id'],
            'name' => $winner['name'],
            'description' => $winner['description'],
            'boosted' => (bool)$winner['boosted']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_latest_winner: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while getting the latest winner'
    ]);
}
?>