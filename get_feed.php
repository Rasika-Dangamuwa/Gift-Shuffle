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
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

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
    
    // Get feed items (winners) after the offset
    $feed = executeQuery(
        "SELECT gw.id, gw.round_number, gw.winner_name, gw.win_time, 
                g.name as gift_name, gw.boosted
         FROM gift_winners gw
         JOIN gifts g ON gw.gift_id = g.id
         WHERE gw.session_id = ? AND gw.id > ?
         ORDER BY gw.id DESC
         LIMIT 10",
        [$session_id, $offset],
        'ii'
    );
    
    // Format feed items for display
    $formatted_feed = [];
    $new_offset = $offset;
    
    foreach ($feed as $item) {
        $formatted_feed[] = [
            'id' => $item['id'],
            'round_number' => $item['round_number'],
            'winner_name' => !empty($item['winner_name']) ? $item['winner_name'] : 'Anonymous Customer',
            'time' => date('h:i A', strtotime($item['win_time'])),
            'gift_name' => $item['gift_name'],
            'boosted' => (bool)$item['boosted']
        ];
        
        // Update offset to the highest ID
        if ($item['id'] > $new_offset) {
            $new_offset = $item['id'];
        }
    }
    
    // Return feed data
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'feed' => $formatted_feed,
        'new_offset' => $new_offset
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_feed: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while retrieving feed data'
    ]);
}
?>