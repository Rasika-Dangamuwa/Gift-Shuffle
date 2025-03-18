<?php
/**
 * Set Boost - Enhanced Version
 * 
 * Handles setting up boosts for specific gifts in selected rounds.
 * This enhanced version includes:
 * - Improved input validation and sanitization
 * - CSRF protection
 * - Enhanced error handling
 * - Better JSON responses
 */

// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Check if user is logged in
requireLogin();

// Set content type for all responses
header('Content-Type: application/json');

// CSRF Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid security token. Please refresh the page and try again.'
        ]);
        exit;
    }
}

// Handle boost setup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $required_params = ['session_id', 'round_id', 'gift_id', 'target_round'];
    $missing_params = [];
    
    foreach ($required_params as $param) {
        if (!isset($_POST[$param]) || $_POST[$param] === '') {
            $missing_params[] = $param;
        }
    }
    
    if (!empty($missing_params)) {
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameters: ' . implode(', ', $missing_params)
        ]);
        exit;
    }
    
    // Sanitize and validate inputs
    $session_id = filter_var($_POST['session_id'], FILTER_VALIDATE_INT);
    $round_id = filter_var($_POST['round_id'], FILTER_VALIDATE_INT);
    $gift_id = filter_var($_POST['gift_id'], FILTER_VALIDATE_INT);
    $target_round = filter_var($_POST['target_round'], FILTER_VALIDATE_INT);
    
    if (!$session_id || !$round_id || !$gift_id || $target_round === false) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid parameter values. Please check your inputs.'
        ]);
        exit;
    }
    
    try {
        // Verify the session belongs to this user
        $session_check = executeQuery(
            "SELECT id FROM shuffle_sessions WHERE id = ? AND created_by = ?",
            [$session_id, $_SESSION['id']],
            'ii'
        );
        
        if (empty($session_check)) {
            echo json_encode([
                'success' => false,
                'error' => 'Session not found or you do not have permission to access it'
            ]);
            exit;
        }
        
        // Verify the round exists and belongs to this session
        $round_check = executeQuery(
            "SELECT id FROM breakdown_rounds WHERE id = ? AND session_id = ?",
            [$round_id, $session_id],
            'ii'
        );
        
        if (empty($round_check)) {
            echo json_encode([
                'success' => false,
                'error' => 'Round not found or does not belong to this session'
            ]);
            exit;
        }
        
        // Verify the gift is available in this round
        $gift_check = executeQuery(
            "SELECT rg.* FROM round_gifts rg
             WHERE rg.round_id = ? AND rg.gift_id = ? 
                   AND rg.quantity_used < rg.quantity_available",
            [$round_id, $gift_id],
            'ii'
        );
        
        if (empty($gift_check)) {
            echo json_encode([
                'success' => false,
                'error' => 'This gift is not available in the selected round'
            ]);
            exit;
        }
        
        // Set the boost with target round (play round)
        $result = setRoundBoost($session_id, $round_id, $gift_id, $target_round);
        
        if ($result) {
            // Get gift info for response
            $gift_info = executeQuery(
                "SELECT name FROM gifts WHERE id = ?",
                [$gift_id],
                'i'
            );
            
            $gift_name = $gift_info[0]['name'] ?? 'Unknown Gift';
            
            echo json_encode([
                'success' => true,
                'message' => 'Boost set for play round ' . $target_round,
                'data' => [
                    'session_id' => $session_id,
                    'round_id' => $round_id,
                    'gift_id' => $gift_id,
                    'gift_name' => $gift_name,
                    'target_round' => $target_round
                ]
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to set boost'
            ]);
            exit;
        }
    } catch (Exception $e) {
        error_log("Error setting boost: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'An error occurred while setting the boost: ' . $e->getMessage()
        ]);
        exit;
    }
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Handle GET request to fetch available gifts for a round
    if (isset($_GET['round_id'])) {
        $round_id = filter_var($_GET['round_id'], FILTER_VALIDATE_INT);
        
        if (!$round_id) {
            echo json_encode([
                'success' => false,
                'error' => 'Invalid round ID'
            ]);
            exit;
        }
        
        try {
            // Verify user has access to this round
            $round_check = executeQuery(
                "SELECT br.id, br.session_id 
                 FROM breakdown_rounds br
                 JOIN shuffle_sessions ss ON br.session_id = ss.id
                 WHERE br.id = ? AND ss.created_by = ?",
                [$round_id, $_SESSION['id']],
                'ii'
            );
            
            if (empty($round_check)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Round not found or you do not have permission to access it'
                ]);
                exit;
            }
            
            // Get available gifts for this round
            $round_gifts = executeQuery(
                "SELECT rg.*, g.name, g.description, 
                        (rg.quantity_available - rg.quantity_used) as remaining
                 FROM round_gifts rg
                 JOIN gifts g ON rg.gift_id = g.id
                 WHERE rg.round_id = ? AND rg.quantity_used < rg.quantity_available
                 ORDER BY g.name",
                [$round_id],
                'i'
            );
            
            echo json_encode([
                'success' => true,
                'gifts' => $round_gifts,
                'round_id' => $round_id,
                'session_id' => $round_check[0]['session_id']
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Error getting round gifts: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'An error occurred while retrieving gifts: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Missing round_id parameter'
        ]);
        exit;
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request method. Only POST and GET are supported.'
    ]);
    exit;
}
?>