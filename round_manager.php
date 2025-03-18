<?php
/**
 * Round Manager - Enhanced Version
 * 
 * Core functionality for managing rounds, gifts, and boosts in the Gift Shuffle System.
 * This enhanced version includes:
 * - Improved security with proper input validation
 * - Better error handling with specific exceptions
 * - Optimized database queries with prepared statements
 * - Modularized functions with clear responsibilities
 * - Comprehensive logging
 */

// Include database connection
require_once "config/db_connect.php";

/**
 * Gets the current active round for a session
 * 
 * @param int $session_id The session ID
 * @return array|null The active round data or null if not found
 * @throws Exception If database error occurs
 */
function getCurrentRound($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID provided");
    }
    
    try {
        $round = executeQuery(
            "SELECT br.*, gb.total_number, gb.name as breakdown_name
             FROM breakdown_rounds br
             JOIN gift_breakdowns gb ON br.breakdown_id = gb.id
             WHERE br.session_id = ? AND br.status = 'active'
             ORDER BY br.round_number DESC
             LIMIT 1",
            [$session_id],
            'i'
        );
        
        return !empty($round) ? $round[0] : null;
    } catch (Exception $e) {
        error_log("Error getting current round: " . $e->getMessage());
        throw new Exception("Failed to retrieve current round: " . $e->getMessage());
    }
}

/**
 * Gets all gifts for a specific round with their availability
 * 
 * @param int $round_id The round ID
 * @return array The gift data
 * @throws Exception If database error occurs
 */
function getRoundGifts($round_id) {
    // Validate input
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    if (!$round_id) {
        throw new InvalidArgumentException("Invalid round ID provided");
    }
    
    try {
        $gifts = executeQuery(
            "SELECT rg.*, g.name, g.description
             FROM round_gifts rg
             JOIN gifts g ON rg.gift_id = g.id
             WHERE rg.round_id = ?
             ORDER BY g.name",
            [$round_id],
            'i'
        );
        
        return $gifts;
    } catch (Exception $e) {
        error_log("Error getting round gifts: " . $e->getMessage());
        throw new Exception("Failed to retrieve gifts for this round: " . $e->getMessage());
    }
}

/**
 * Creates a new round for a session
 * 
 * @param int $session_id The session ID
 * @param int $breakdown_id The breakdown ID
 * @param int $round_number The round number
 * @return int|false The new round ID or false on failure
 * @throws Exception If database error occurs
 */
function createNewRound($session_id, $breakdown_id, $round_number) {
    // Validate inputs
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    $breakdown_id = filter_var($breakdown_id, FILTER_VALIDATE_INT);
    $round_number = filter_var($round_number, FILTER_VALIDATE_INT);
    
    if (!$session_id || !$breakdown_id || !$round_number) {
        throw new InvalidArgumentException("Invalid arguments for creating new round");
    }
    
    try {
        // Begin transaction
        $conn = getConnection();
        $conn->begin_transaction();
        
        // Complete the previous round if exists
        executeQueryWithConnection(
            $conn,
            "UPDATE breakdown_rounds 
             SET status = 'completed', completed_at = NOW() 
             WHERE session_id = ? AND status = 'active'",
            [$session_id],
            'i'
        );
        
        // Create new round
        $result = executeQueryWithConnection(
            $conn,
            "INSERT INTO breakdown_rounds (session_id, breakdown_id, round_number, status) 
             VALUES (?, ?, ?, 'active')",
            [$session_id, $breakdown_id, $round_number],
            'iii'
        );
        
        $round_id = $conn->insert_id;
        
        // Populate round_gifts from breakdown_gifts
        executeQueryWithConnection(
            $conn,
            "INSERT INTO round_gifts (round_id, gift_id, quantity_available, quantity_used)
             SELECT ?, bg.gift_id, bg.quantity, 0
             FROM breakdown_gifts bg
             WHERE bg.breakdown_id = ?",
            [$round_id, $breakdown_id],
            'ii'
        );
        
        // Update session's breakdown_round
        executeQueryWithConnection(
            $conn,
            "UPDATE shuffle_sessions SET breakdown_round = ? WHERE id = ?",
            [$round_number, $session_id],
            'ii'
        );
        
        // Commit transaction
        $conn->commit();
        
        // Log the action
        $user_id = $_SESSION["id"] ?? 0;
        logActivity(
            $user_id,
            "new_round_created",
            "Created new breakdown round {$round_number} for session (ID: {$session_id})"
        );
        
        return $round_id;
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        
        error_log("Error creating new round: " . $e->getMessage());
        throw new Exception("Failed to create new round: " . $e->getMessage());
    }
}

/**
 * Updates a round gift's used quantity
 * 
 * @param int $round_id The round ID
 * @param int $gift_id The gift ID
 * @return bool Success or failure
 * @throws Exception If database error occurs
 */
function updateRoundGiftUsage($round_id, $gift_id) {
    // Validate inputs
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    $gift_id = filter_var($gift_id, FILTER_VALIDATE_INT);
    
    if (!$round_id || !$gift_id) {
        throw new InvalidArgumentException("Invalid round ID or gift ID");
    }
    
    try {
        $result = executeQuery(
            "UPDATE round_gifts 
             SET quantity_used = quantity_used + 1 
             WHERE round_id = ? AND gift_id = ? AND quantity_used < quantity_available",
            [$round_id, $gift_id],
            'ii'
        );
        
        if ($result === false) {
            throw new Exception("Failed to update gift usage");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error updating round gift usage: " . $e->getMessage());
        throw new Exception("Failed to update gift usage: " . $e->getMessage());
    }
}

/**
 * Checks if all gifts in a round are used
 * 
 * @param int $round_id The round ID
 * @return bool True if all gifts are used
 * @throws Exception If database error occurs
 */
function isRoundComplete($round_id) {
    // Validate input
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    if (!$round_id) {
        throw new InvalidArgumentException("Invalid round ID");
    }
    
    try {
        $result = executeQuery(
            "SELECT COUNT(*) as total, 
                    SUM(CASE WHEN quantity_used < quantity_available THEN 1 ELSE 0 END) as available
             FROM round_gifts
             WHERE round_id = ?",
            [$round_id],
            'i'
        );
        
        if (!empty($result)) {
            $total = $result[0]['total'];
            $available = $result[0]['available'];
            
            return $total > 0 && $available == 0;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking if round complete: " . $e->getMessage());
        throw new Exception("Failed to check round completion status: " . $e->getMessage());
    }
}

/**
 * Gets a boost for a specific round
 * 
 * @param int $round_id The round ID
 * @return array|null The boost data or null if not found
 * @throws Exception If database error occurs
 */
function getRoundBoost($round_id) {
    // Validate input
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    if (!$round_id) {
        throw new InvalidArgumentException("Invalid round ID");
    }
    
    try {
        $boost = executeQuery(
            "SELECT gb.*, g.name as gift_name, g.description
             FROM gift_boosts gb
             JOIN gifts g ON gb.gift_id = g.id
             WHERE gb.round_id = ?
             LIMIT 1",
            [$round_id],
            'i'
        );
        
        return !empty($boost) ? $boost[0] : null;
    } catch (Exception $e) {
        error_log("Error getting round boost: " . $e->getMessage());
        return null; // Non-critical error, return null instead of throwing
    }
}

/**
 * Gets a boost for a specific play round number in a session
 * 
 * @param int $session_id The session ID
 * @param int $play_round_number The play round number
 * @return array|null The boost data or null if not found
 * @throws Exception If database error occurs
 */
function getPlayRoundBoost($session_id, $play_round_number) {
    // Validate inputs
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    $play_round_number = filter_var($play_round_number, FILTER_VALIDATE_INT);
    
    if (!$session_id || !$play_round_number) {
        throw new InvalidArgumentException("Invalid session ID or play round number");
    }
    
    try {
        $boost = executeQuery(
            "SELECT gb.*, g.name as gift_name, g.description
             FROM gift_boosts gb
             JOIN gifts g ON gb.gift_id = g.id
             WHERE gb.session_id = ? AND gb.target_round = ?
             LIMIT 1",
            [$session_id, $play_round_number],
            'ii'
        );
        
        return !empty($boost) ? $boost[0] : null;
    } catch (Exception $e) {
        error_log("Error getting play round boost: " . $e->getMessage());
        return null; // Non-critical error, return null instead of throwing
    }
}

/**
 * Gets all boosts for a session
 * 
 * @param int $session_id The session ID
 * @return array The boost data
 * @throws Exception If database error occurs
 */
function getSessionBoosts($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID");
    }
    
    try {
        $boosts = executeQuery(
            "SELECT gb.*, g.name as gift_name, br.round_number
             FROM gift_boosts gb
             JOIN gifts g ON gb.gift_id = g.id
             JOIN breakdown_rounds br ON gb.round_id = br.id
             WHERE gb.session_id = ?
             ORDER BY gb.target_round ASC",
            [$session_id],
            'i'
        );
        
        return $boosts;
    } catch (Exception $e) {
        error_log("Error getting session boosts: " . $e->getMessage());
        return []; // Return empty array for non-critical error
    }
}

/**
 * Sets a boost for a specific play round
 * 
 * @param int $session_id The session ID
 * @param int $round_id The breakdown round ID
 * @param int $gift_id The gift ID
 * @param int $target_round The target play round number to boost
 * @return bool Success or failure
 * @throws Exception If database error occurs
 */
function setRoundBoost($session_id, $round_id, $gift_id, $target_round = 0) {
    // Validate inputs
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    $gift_id = filter_var($gift_id, FILTER_VALIDATE_INT);
    $target_round = filter_var($target_round, FILTER_VALIDATE_INT);
    
    if (!$session_id || !$round_id || !$gift_id) {
        throw new InvalidArgumentException("Invalid parameters for setting boost");
    }
    
    try {
        // Start transaction
        $conn = getConnection();
        $conn->begin_transaction();
        
        // Check if a boost already exists for this target round
        $existing = executeQueryWithConnection(
            $conn,
            "SELECT id FROM gift_boosts WHERE session_id = ? AND target_round = ?",
            [$session_id, $target_round],
            'ii'
        );
        
        if (!empty($existing)) {
            // Update existing boost
            executeQueryWithConnection(
                $conn,
                "UPDATE gift_boosts SET gift_id = ?, round_id = ? WHERE id = ?",
                [$gift_id, $round_id, $existing[0]['id']],
                'iii'
            );
        } else {
            // Create new boost
            executeQueryWithConnection(
                $conn,
                "INSERT INTO gift_boosts (session_id, round_id, gift_id, target_round, created_at) 
                 VALUES (?, ?, ?, ?, NOW())",
                [$session_id, $round_id, $gift_id, $target_round],
                'iiii'
            );
        }
        
        // Get round number for logging
        $round = executeQueryWithConnection(
            $conn,
            "SELECT round_number FROM breakdown_rounds WHERE id = ?",
            [$round_id],
            'i'
        );
        
        $round_number = $round[0]['round_number'] ?? 'unknown';
        
        // Commit transaction
        $conn->commit();
        
        // Log the activity
        $user_id = $_SESSION["id"] ?? 0;
        logActivity(
            $user_id,
            "set_boost",
            "Set boost for play round {$target_round} in session (ID: {$session_id})"
        );
        
        return true;
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        
        error_log("Error setting round boost: " . $e->getMessage());
        throw new Exception("Failed to set boost: " . $e->getMessage());
    }
}

/**
 * Removes a boost
 * 
 * @param int $boost_id The boost ID
 * @return bool Success or failure
 * @throws Exception If database error occurs
 */
function removeBoost($boost_id) {
    // Validate input
    $boost_id = filter_var($boost_id, FILTER_VALIDATE_INT);
    if (!$boost_id) {
        throw new InvalidArgumentException("Invalid boost ID");
    }
    
    try {
        // Get boost details for logging
        $boost = executeQuery(
            "SELECT gb.*, br.round_number 
             FROM gift_boosts gb
             JOIN breakdown_rounds br ON gb.round_id = br.id
             WHERE gb.id = ?",
            [$boost_id],
            'i'
        );
        
        if (empty($boost)) {
            throw new Exception("Boost not found");
        }
        
        $session_id = $boost[0]['session_id'];
        $round_number = $boost[0]['round_number'];
        $target_round = $boost[0]['target_round'];
        
        // Remove the boost
        $result = executeQuery(
            "DELETE FROM gift_boosts WHERE id = ?",
            [$boost_id],
            'i'
        );
        
        if ($result === false) {
            throw new Exception("Failed to delete boost");
        }
        
        // Log the activity
        $user_id = $_SESSION["id"] ?? 0;
        logActivity(
            $user_id,
            "remove_boost",
            "Removed boost for play round {$target_round} in session (ID: {$session_id})"
        );
        
        return true;
    } catch (Exception $e) {
        error_log("Error removing boost: " . $e->getMessage());
        throw new Exception("Failed to remove boost: " . $e->getMessage());
    }
}

/**
 * Finds or creates the next round for a session
 * 
 * @param int $session_id The session ID
 * @return array|null The next round data or null on failure
 * @throws Exception If database error occurs
 */
function getOrCreateNextRound($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID");
    }
    
    try {
        // Get current round
        $current_round = getCurrentRound($session_id);
        
        if ($current_round) {
            // Check if current round is complete
            if (isRoundComplete($current_round['id'])) {
                // Create next round
                $next_round_number = $current_round['round_number'] + 1;
                $round_id = createNewRound($session_id, $current_round['breakdown_id'], $next_round_number);
                
                if ($round_id) {
                    return getCurrentRound($session_id);
                }
            } else {
                // Current round still active
                return $current_round;
            }
        } else {
            // No current round, get session info
            $session = executeQuery(
                "SELECT id, breakdown_id FROM shuffle_sessions WHERE id = ?",
                [$session_id],
                'i'
            );
            
            if (!empty($session)) {
                // Create first round
                $round_id = createNewRound($session_id, $session[0]['breakdown_id'], 1);
                
                if ($round_id) {
                    return getCurrentRound($session_id);
                }
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error getting or creating next round: " . $e->getMessage());
        throw new Exception("Failed to get or create next round: " . $e->getMessage());
    }
}

/**
 * Gets a random available gift from a round
 * 
 * @param int $round_id The breakdown round ID
 * @param int $session_id The session ID (optional)
 * @param int $play_round_number The play round number (optional)
 * @return array|null The selected gift or null if none available
 * @throws Exception If database error occurs
 */
function getRandomAvailableGift($round_id, $session_id = null, $play_round_number = null) {
    // Validate inputs
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    $session_id = $session_id ? filter_var($session_id, FILTER_VALIDATE_INT) : null;
    $play_round_number = $play_round_number ? filter_var($play_round_number, FILTER_VALIDATE_INT) : null;
    
    if (!$round_id) {
        throw new InvalidArgumentException("Invalid round ID");
    }
    
    try {
        // Check for a boost for this specific play round if provided
        if ($session_id && $play_round_number) {
            $play_boost = getPlayRoundBoost($session_id, $play_round_number);
            
            if ($play_boost) {
                // Check if the boosted gift is still available
                $gift = executeQuery(
                    "SELECT rg.*, g.name, g.description 
                     FROM round_gifts rg
                     JOIN gifts g ON rg.gift_id = g.id
                     WHERE rg.round_id = ? AND rg.gift_id = ? 
                           AND rg.quantity_used < rg.quantity_available",
                    [$round_id, $play_boost['gift_id']],
                    'ii'
                );
                
                if (!empty($gift)) {
                    // Use the boosted gift
                    return [
                        'gift' => $gift[0],
                        'boosted' => true
                    ];
                }
            }
        }
        
        // Check for a boost for the breakdown round as fallback
        $boost = getRoundBoost($round_id);
        
        if ($boost) {
            // Check if the boosted gift is still available
            $gift = executeQuery(
                "SELECT rg.*, g.name, g.description 
                 FROM round_gifts rg
                 JOIN gifts g ON rg.gift_id = g.id
                 WHERE rg.round_id = ? AND rg.gift_id = ? 
                       AND rg.quantity_used < rg.quantity_available",
                [$round_id, $boost['gift_id']],
                'ii'
            );
            
            if (!empty($gift)) {
                // Use the boosted gift
                return [
                    'gift' => $gift[0],
                    'boosted' => true
                ];
            }
        }
        
        // No boost or boosted gift unavailable, use weighted random selection
        $gifts = executeQuery(
            "SELECT rg.*, g.name, g.description 
             FROM round_gifts rg
             JOIN gifts g ON rg.gift_id = g.id
             WHERE rg.round_id = ? AND rg.quantity_used < rg.quantity_available",
            [$round_id],
            'i'
        );
        
        if (empty($gifts)) {
            return null;
        }
        
        // Create weighted array for random selection
        $weighted_gifts = [];
        foreach ($gifts as $gift) {
            $available = $gift['quantity_available'] - $gift['quantity_used'];
            for ($i = 0; $i < $available; $i++) {
                $weighted_gifts[] = $gift;
            }
        }
        
        if (empty($weighted_gifts)) {
            return null;
        }
        
        // Random selection
        $random_index = array_rand($weighted_gifts);
        return [
            'gift' => $weighted_gifts[$random_index],
            'boosted' => false
        ];
    } catch (Exception $e) {
        error_log("Error getting random available gift: " . $e->getMessage());
        throw new Exception("Failed to get a random gift: " . $e->getMessage());
    }
}

/**
 * Records a winner for a round
 * 
 * @param int $session_id The session ID
 * @param int $round_id The round ID
 * @param int $gift_id The gift ID
 * @param string|null $winner_name The winner's name
 * @param string|null $winner_nic The winner's NIC
 * @param string|null $winner_phone The winner's phone
 * @param bool $boosted Whether this was a boosted gift
 * @return array|false The winner data or false on failure
 * @throws Exception If database error occurs
 */
function recordWinner($session_id, $round_id, $gift_id, $winner_name, $winner_nic, $winner_phone, $boosted) {
    // Validate inputs
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    $round_id = filter_var($round_id, FILTER_VALIDATE_INT);
    $gift_id = filter_var($gift_id, FILTER_VALIDATE_INT);
    $winner_name = $winner_name ? filter_var($winner_name, FILTER_SANITIZE_STRING) : null;
    $winner_nic = $winner_nic ? filter_var($winner_nic, FILTER_SANITIZE_STRING) : null;
    $winner_phone = $winner_phone ? filter_var($winner_phone, FILTER_SANITIZE_STRING) : null;
    $boosted = (bool)$boosted;
    
    if (!$session_id || !$round_id || !$gift_id) {
        throw new InvalidArgumentException("Invalid parameters for recording winner");
    }
    
    try {
        // Begin transaction
        $conn = getConnection();
        $conn->begin_transaction();
        
        // Get the next round number within the session
        $next_round = executeQueryWithConnection(
            $conn,
            "SELECT IFNULL(MAX(round_number), 0) + 1 as next_round FROM gift_winners WHERE session_id = ?",
            [$session_id],
            'i'
        );
        
        $round_number = $next_round[0]['next_round'] ?? 1;
        
        // Record the winner
        executeQueryWithConnection(
            $conn,
            "INSERT INTO gift_winners (
                session_id, round_id, gift_id, winner_name, winner_nic, winner_phone,
                win_time, round_number, boosted
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
            [
                $session_id, 
                $round_id, 
                $gift_id, 
                $winner_name, 
                $winner_nic, 
                $winner_phone, 
                $round_number, 
                $boosted ? 1 : 0
            ],
            'iiisssis'
        );
        
        $winner_id = $conn->insert_id;
        
        // Update round gift usage
        executeQueryWithConnection(
            $conn,
            "UPDATE round_gifts 
             SET quantity_used = quantity_used + 1 
             WHERE round_id = ? AND gift_id = ?",
            [$round_id, $gift_id],
            'ii'
        );
        
        // Commit transaction
        $conn->commit();
        
        // Log this event
        $user_id = $_SESSION["id"] ?? 0;
        logActivity(
            $user_id,
            "winner_recorded",
            "Recorded winner for session ID: {$session_id}, gift ID: {$gift_id}, round: {$round_number}" .
            ($boosted ? " (Boosted)" : "")
        );
        
        // Return the winner data
        return [
            'id' => $winner_id,
            'round_number' => $round_number,
            'session_id' => $session_id,
            'round_id' => $round_id,
            'gift_id' => $gift_id,
            'boosted' => $boosted
        ];
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        
        error_log("Error recording winner: " . $e->getMessage());
        throw new Exception("Failed to record winner: " . $e->getMessage());
    }
}

/**
 * Gets recent winners for a session
 * 
 * @param int $session_id The session ID
 * @param int $limit Maximum number of winners to return
 * @return array The recent winners
 * @throws Exception If database error occurs
 */
function getRecentWinners($session_id, $limit = 10) {
    // Validate inputs
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    $limit = filter_var($limit, FILTER_VALIDATE_INT);
    
    if (!$session_id || !$limit) {
        throw new InvalidArgumentException("Invalid session ID or limit");
    }
    
    try {
        $winners = executeQuery(
            "SELECT gw.*, g.name as gift_name
             FROM gift_winners gw
             JOIN gifts g ON gw.gift_id = g.id
             WHERE gw.session_id = ?
             ORDER BY gw.win_time DESC
             LIMIT ?",
            [$session_id, $limit],
            'ii'
        );
        
        return $winners;
    } catch (Exception $e) {
        error_log("Error getting recent winners: " . $e->getMessage());
        return []; // Return empty array for non-critical error
    }
}

/**
 * Gets the session details including current round and gift information
 * 
 * @param int $session_id The session ID
 * @return array|null The session details or null if not found
 * @throws Exception If database error occurs
 */
function getSessionDetails($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID");
    }
    
    try {
        // Get basic session details
        $session = executeQuery(
            "SELECT ss.*, gb.name as breakdown_name, gb.total_number,
                 (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count
             FROM shuffle_sessions ss
             JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
             WHERE ss.id = ?",
            [$session_id],
            'i'
        );
        
        if (empty($session)) {
            return null;
        }
        
        $session = $session[0];
        
        // Get current round
        $current_round = getCurrentRound($session_id);
        if ($current_round) {
            $session['current_round'] = $current_round;
            
            // Get round gifts
            $round_gifts = getRoundGifts($current_round['id']);
            $session['gifts'] = $round_gifts;
            
            // Calculate remaining gifts
            $total_gifts = 0;
            $remaining_gifts = 0;
            
            foreach ($round_gifts as $gift) {
                $total_gifts += $gift['quantity_available'];
                $remaining = $gift['quantity_available'] - $gift['quantity_used'];
                if ($remaining > 0) {
                    $remaining_gifts += $remaining;
                }
            }
            
            $session['total_gifts'] = $total_gifts;
            $session['remaining_gifts'] = $remaining_gifts;
        }
        
        // Get boosts
        $session['boosts'] = getSessionBoosts($session_id);
        
        return $session;
    } catch (Exception $e) {
        error_log("Error getting session details: " . $e->getMessage());
        throw new Exception("Failed to get session details: " . $e->getMessage());
    }
}

/**
 * Gets session statistics including round details, winners, and gifts
 * 
 * @param int $session_id The session ID
 * @return array The session statistics
 * @throws Exception If database error occurs
 */
function getSessionStatistics($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID");
    }
    
    try {
        // Get session details
        $session = executeQuery(
            "SELECT ss.*, gb.name as breakdown_name, gb.total_number
             FROM shuffle_sessions ss
             JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
             WHERE ss.id = ?",
            [$session_id],
            'i'
        );
        
        if (empty($session)) {
            throw new Exception("Session not found");
        }
        
        $stats = [
            'session' => $session[0],
            'rounds' => [],
            'gifts' => [],
            'winners_count' => 0,
            'boost_count' => 0
        ];
        
        // Get rounds
        $rounds = executeQuery(
            "SELECT br.*, 
                (SELECT COUNT(*) FROM gift_winners gw WHERE gw.round_id = br.id) as winners_count
             FROM breakdown_rounds br
             WHERE br.session_id = ?
             ORDER BY br.round_number ASC",
            [$session_id],
            'i'
        );
        
        $stats['rounds'] = $rounds;
        
        // Get gift distribution
        $gifts = executeQuery(
            "SELECT g.id, g.name, g.description,
                COUNT(gw.id) as winners_count,
                SUM(CASE WHEN gw.boosted = 1 THEN 1 ELSE 0 END) as boosted_count
             FROM gifts g
             JOIN gift_winners gw ON g.id = gw.gift_id
             WHERE gw.session_id = ?
             GROUP BY g.id
             ORDER BY winners_count DESC",
            [$session_id],
            'i'
        );
        
        $stats['gifts'] = $gifts;
        
        // Get overall statistics
        $overall = executeQuery(
            "SELECT 
                COUNT(gw.id) as winners_count,
                SUM(CASE WHEN gw.boosted = 1 THEN 1 ELSE 0 END) as boost_count,
                MIN(gw.win_time) as first_win_time,
                MAX(gw.win_time) as last_win_time
             FROM gift_winners gw
             WHERE gw.session_id = ?",
            [$session_id],
            'i'
        );
        
        if (!empty($overall)) {
            $stats['winners_count'] = $overall[0]['winners_count'] ?? 0;
            $stats['boost_count'] = $overall[0]['boost_count'] ?? 0;
            $stats['first_win_time'] = $overall[0]['first_win_time'] ?? null;
            $stats['last_win_time'] = $overall[0]['last_win_time'] ?? null;
            
            if ($stats['first_win_time'] && $stats['last_win_time']) {
                $start = new DateTime($stats['first_win_time']);
                $end = new DateTime($stats['last_win_time']);
                $diff = $start->diff($end);
                $stats['duration'] = $diff->format('%H:%I:%S');
            }
        }
        
        return $stats;
    } catch (Exception $e) {
        error_log("Error getting session statistics: " . $e->getMessage());
        throw new Exception("Failed to get session statistics: " . $e->getMessage());
    }
}

/**
 * Completes a session
 * 
 * @param int $session_id The session ID
 * @return bool Success or failure
 * @throws Exception If database error occurs
 */
function completeSession($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID");
    }
    
    try {
        // Begin transaction
        $conn = getConnection();
        $conn->begin_transaction();
        
        // End the session
        executeQueryWithConnection(
            $conn,
            "UPDATE shuffle_sessions SET status = 'completed', end_time = NOW() WHERE id = ?",
            [$session_id],
            'i'
        );
        
        // Complete current round
        executeQueryWithConnection(
            $conn,
            "UPDATE breakdown_rounds SET status = 'completed', completed_at = NOW() 
             WHERE session_id = ? AND status = 'active'",
            [$session_id],
            'i'
        );
        
        $conn->commit();
        
        // Log the activity
        $user_id = $_SESSION["id"] ?? 0;
        logActivity(
            $user_id,
            "complete_session",
            "Completed gift shuffle session (ID: {$session_id})"
        );
        
        return true;
    } catch (Exception $e) {
        // Rollback on error
        if (isset($conn) && $conn->ping()) {
            $conn->rollback();
        }
        
        error_log("Error completing session: " . $e->getMessage());
        throw new Exception("Failed to complete session: " . $e->getMessage());
    }
}

/**
 * Gets or creates a round for a session
 * 
 * @param int $session_id The session ID
 * @param int $round_number The specific round number to get or create
 * @return array|null The round data or null on failure
 * @throws Exception If database error occurs
 */
function getOrCreateSpecificRound($session_id, $round_number) {
    // Validate inputs
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    $round_number = filter_var($round_number, FILTER_VALIDATE_INT);
    
    if (!$session_id || !$round_number) {
        throw new InvalidArgumentException("Invalid session ID or round number");
    }
    
    try {
        // Check if the round already exists
        $round = executeQuery(
            "SELECT br.* FROM breakdown_rounds br
             WHERE br.session_id = ? AND br.round_number = ?
             LIMIT 1",
            [$session_id, $round_number],
            'ii'
        );
        
        if (!empty($round)) {
            return $round[0];
        }
        
        // Round doesn't exist, need to create it
        // Get session details to know breakdown ID
        $session = executeQuery(
            "SELECT id, breakdown_id FROM shuffle_sessions WHERE id = ?",
            [$session_id],
            'i'
        );
        
        if (empty($session)) {
            throw new Exception("Session not found");
        }
        
        // Create the round
        $round_id = createNewRound($session_id, $session[0]['breakdown_id'], $round_number);
        
        if (!$round_id) {
            throw new Exception("Failed to create round");
        }
        
        // Get the newly created round
        $round = executeQuery(
            "SELECT br.* FROM breakdown_rounds br
             WHERE br.id = ?
             LIMIT 1",
            [$round_id],
            'i'
        );
        
        return !empty($round) ? $round[0] : null;
    } catch (Exception $e) {
        error_log("Error getting or creating specific round: " . $e->getMessage());
        throw new Exception("Failed to get or create round: " . $e->getMessage());
    }
}

/**
 * Gets upcoming play round number for a session
 * 
 * @param int $session_id The session ID
 * @return int The next play round number
 * @throws Exception If database error occurs
 */
function getNextPlayRound($session_id) {
    // Validate input
    $session_id = filter_var($session_id, FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new InvalidArgumentException("Invalid session ID");
    }
    
    try {
        $result = executeQuery(
            "SELECT IFNULL(MAX(round_number), 0) + 1 as next_round 
             FROM gift_winners 
             WHERE session_id = ?",
            [$session_id],
            'i'
        );
        
        return $result[0]['next_round'] ?? 1;
    } catch (Exception $e) {
        error_log("Error getting next play round: " . $e->getMessage());
        return 1; // Default to 1 if error occurs
    }
}
?>