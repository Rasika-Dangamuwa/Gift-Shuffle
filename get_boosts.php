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
$current_round_id = isset($_GET['current_round_id']) ? (int)$_GET['current_round_id'] : 0;

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
    
    // Get all rounds for this session (for the boosts UI)
    $rounds = executeQuery(
        "SELECT br.*, gb.name as breakdown_name, gb.total_number,
                (SELECT COUNT(*) FROM gift_winners gw WHERE gw.round_id = br.id) as winners_count
         FROM breakdown_rounds br
         JOIN gift_breakdowns gb ON br.breakdown_id = gb.id
         WHERE br.session_id = ?
         ORDER BY br.round_number ASC",
        [$session_id],
        'i'
    );
    
    // Get upcoming boosts (for rounds after the current one)
    $boosts = executeQuery(
        "SELECT gb.*, g.name as gift_name, br.round_number
         FROM gift_boosts gb
         JOIN gifts g ON gb.gift_id = g.id
         JOIN breakdown_rounds br ON gb.round_id = br.id
         WHERE gb.session_id = ? AND br.id > ?
         ORDER BY br.round_number ASC",
        [$session_id, $current_round_id],
        'ii'
    );
    
    // Return boosts data with available rounds info
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'rounds' => $rounds,
        'boosts' => $boosts
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_boosts: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while retrieving boosts data'
    ]);
}
?>