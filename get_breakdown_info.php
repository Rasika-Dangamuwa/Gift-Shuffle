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

try {
    // Get the current session details with breakdown info
    $session = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, gb.total_number,
            (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count,
            ss.breakdown_round
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
            'error' => 'Session not found or you don\'t have permission to access it'
        ]);
        exit;
    }
    
    $session = $session[0];
    $winners_count = $session['winners_count'];
    $total_number = $session['total_number'];
    $current_breakdown_round = $session['breakdown_round'];
    
    // Calculate current breakdown round (1-based indexing)
    $calculated_breakdown_round = floor($winners_count / $total_number) + 1;
    if ($winners_count > 0 && $winners_count % $total_number === 0) {
        $calculated_breakdown_round = $winners_count / $total_number;
    }
    
    // Debug - log the calculated breakdown round
    error_log("Calculated breakdown round: {$calculated_breakdown_round}, DB breakdown round: {$current_breakdown_round}, Winners: {$winners_count}, Total per round: {$total_number}");
    
    // If there's a mismatch between calculated and stored breakdown round, update the database
    if ($calculated_breakdown_round != $current_breakdown_round) {
        executeQuery(
            "UPDATE shuffle_sessions SET breakdown_round = ?, updated_at = NOW() WHERE id = ?",
            [$calculated_breakdown_round, $session_id],
            'ii'
        );
        
        // Log this significant event
        logActivity(
            $_SESSION["id"],
            "breakdown_round_update",
            "Updated breakdown round from {$current_breakdown_round} to {$calculated_breakdown_round} for session (ID: {$session_id})"
        );
        
        // Update session data with new round number
        $current_breakdown_round = $calculated_breakdown_round;
    }
    
    // Calculate gifts in current round
    $gifts_in_current_round = $winners_count % $total_number;
    if ($gifts_in_current_round === 0 && $winners_count > 0) {
        $gifts_in_current_round = $total_number;
    }
    
    $gifts_remaining_in_round = $total_number - $gifts_in_current_round;
    
    // Get total gifts available in this breakdown
    $total_gifts_query = executeQuery(
        "SELECT SUM(bg.quantity) as total_gifts
         FROM breakdown_gifts bg
         WHERE bg.breakdown_id = ?",
        [$session['breakdown_id']],
        'i'
    );
    
    $total_gifts = $total_gifts_query[0]['total_gifts'] ?? 0;
    
    // Calculate used gifts within current round ONLY
    $used_in_current_round_query = executeQuery(
        "SELECT COUNT(*) as count FROM gift_winners 
         WHERE session_id = ? 
         AND round_number > (? - 1) * ? 
         AND round_number <= ? * ?",
        [$session_id, $current_breakdown_round, $total_number, $current_breakdown_round, $total_number],
        'iiiii'
    );
    
    $used_in_current_round = $used_in_current_round_query[0]['count'] ?? 0;
    
    // Debug - log used gifts in current round
    error_log("Used in current round {$current_breakdown_round}: {$used_in_current_round}, Total gifts: {$total_gifts}");
    
    // Calculate remaining gifts for this round
    $remaining_gifts = $total_gifts - $used_in_current_round;
    
    // If we've just completed a round, we should show full count for next round
    if ($gifts_in_current_round === $total_number) {
        $remaining_gifts = $total_gifts;
    }
    
    // Return the breakdown info
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'breakdown' => [
            'id' => $session['breakdown_id'],
            'name' => $session['breakdown_name'],
            'total_number' => $total_number,
            'winners_count' => $winners_count,
            'breakdown_round' => $current_breakdown_round,
            'gifts_in_current_round' => $gifts_in_current_round,
            'gifts_remaining_in_round' => $gifts_remaining_in_round,
            'total_gifts' => $total_gifts,
            'remaining_gifts' => $remaining_gifts
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_breakdown_info: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while retrieving breakdown information: ' . $e->getMessage()
    ]);
}
?>