<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

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
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    // Verify the user has access to this session
    $session_check = executeQuery(
        "SELECT id FROM shuffle_sessions WHERE id = ? AND created_by = ?",
        [$session_id, $_SESSION['id']],
        'ii'
    );
    
    if (empty($session_check)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Session not found or you don\'t have permission to access it'
        ]);
        exit;
    }
    
    // Get winners for the session
    $winners = executeQuery(
        "SELECT gw.id, gw.round_number, gw.winner_name, gw.win_time, 
                g.name as gift_name, gw.boosted
         FROM gift_winners gw
         JOIN gifts g ON gw.gift_id = g.id
         WHERE gw.session_id = ?
         ORDER BY gw.win_time DESC
         LIMIT ?",
        [$session_id, $limit],
        'ii'
    );
    
    // Format winners for display
    $formatted_winners = [];
    
    foreach ($winners as $winner) {
        $formatted_winners[] = [
            'id' => $winner['id'],
            'round_number' => $winner['round_number'],
            'winner_name' => !empty($winner['winner_name']) ? $winner['winner_name'] : null,
            'win_time' => date('h:i A', strtotime($winner['win_time'])),
            'gift_name' => $winner['gift_name'],
            'boosted' => (bool)$winner['boosted']
        ];
    }
    
    // Return winners data
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'winners' => $formatted_winners
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_winners: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while retrieving winners data'
    ]);
}
?>