<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if session ID is provided
if (!isset($_GET['session_id']) || empty($_GET['session_id'])) {
    echo '<div style="text-align: center; padding: 20px; color: #6c757d;">Error: Session ID is required</div>';
    exit;
}

$session_id = (int)$_GET['session_id'];

try {
    // Get recent winners
    $recent_winners = executeQuery(
        "SELECT gw.*, g.name as gift_name
         FROM gift_winners gw
         JOIN gifts g ON gw.gift_id = g.id
         WHERE gw.session_id = ?
         ORDER BY gw.win_time DESC
         LIMIT 10",
        [$session_id],
        'i'
    );
    
    if (empty($recent_winners)) {
        echo '<div style="text-align: center; padding: 20px; color: #6c757d;">No winners yet</div>';
        exit;
    }
    
    // Output winners list
    foreach ($recent_winners as $winner) {
        ?>
        <div class="winner-row">
            <div class="winner-info">
                <div class="winner-name">
                    <?php echo !empty($winner['winner_name']) ? htmlspecialchars($winner['winner_name']) : 'Anonymous Winner'; ?>
                    <span class="feed-timestamp"><?php echo date('h:i A', strtotime($winner['win_time'])); ?></span>
                </div>
                <div class="winner-details">
                    <div class="winner-detail">
                        <i class="fas fa-trophy"></i>
                        <span>Round <?php echo $winner['round_number']; ?></span>
                    </div>
                    <?php if (!empty($winner['winner_nic'])): ?>
                        <div class="winner-detail">
                            <i class="fas fa-id-card"></i>
                            <span><?php echo htmlspecialchars($winner['winner_nic']); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($winner['winner_phone'])): ?>
                        <div class="winner-detail">
                            <i class="fas fa-phone"></i>
                            <span><?php echo htmlspecialchars($winner['winner_phone']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="winner-gift">
                <?php echo htmlspecialchars($winner['gift_name']); ?>
            </div>
        </div>
        <?php
    }
    
} catch (Exception $e) {
    error_log("Error getting recent winners: " . $e->getMessage());
    echo '<div style="text-align: center; padding: 20px; color: #6c757d;">Error loading winners</div>';
}
?>