<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";
require_once "round_manager.php";

// Check if user is logged in
requireLogin();

// Check if session ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No session ID provided.";
    header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
    exit;
}

$session_id = (int)$_GET['id'];

try {
    // Get session details
    $session = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, gb.total_number,
            (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count,
            ss.theme_id, ss.collect_customer_info
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         WHERE ss.id = ? AND ss.created_by = ?",
        [$session_id, $_SESSION['id']],
        'ii'
    );
    
    if (empty($session)) {
        $_SESSION['error_message'] = "Session not found or you don't have permission to access it.";
        header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
        exit;
    }
    
    $session = $session[0];
    
    // Check if session is active
    if ($session['status'] !== 'active') {
        $_SESSION['error_message'] = "This session is already " . $session['status'] . ".";
        header("location: view_session.php?id=" . $session_id);
        exit;
    }
    
    // Get current active round
    $current_round = getCurrentRound($session_id);
    
    if (!$current_round) {
        // No active round, create the first one
        $round = getOrCreateNextRound($session_id);
        
        if (!$round) {
            $_SESSION['error_message'] = "Could not initialize breakdown round.";
            header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
            exit;
        }
        
        $current_round = $round;
    }
    
    $round_id = $current_round['id'];
    $round_number = $current_round['round_number'];
    
    // Get all gifts for this round with quantities
    $round_gifts = getRoundGifts($round_id);
    
    // Calculate total gifts and remaining gifts
    $total_gifts = 0;
    $remaining_gifts = 0;
    
    foreach ($round_gifts as $gift) {
        $total_gifts += $gift['quantity_available'];
        $remaining = $gift['quantity_available'] - $gift['quantity_used'];
        if ($remaining > 0) {
            $remaining_gifts += $remaining;
        }
    }
    
    // Get upcoming rounds
    $upcoming_rounds = executeQuery(
        "SELECT br.*, gb.name as breakdown_name, gb.total_number,
                (SELECT COUNT(*) FROM gift_winners gw WHERE gw.round_id = br.id) as winners_count
         FROM breakdown_rounds br
         JOIN gift_breakdowns gb ON br.breakdown_id = gb.id
         WHERE br.session_id = ? AND br.round_number > ?
         ORDER BY br.round_number ASC",
        [$session_id, $round_number],
        'ii'
    );
    
    // Get boosts for current and upcoming rounds
    $boosts = getSessionBoosts($session_id);
    
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
    
} catch (Exception $e) {
    error_log("Error getting round control panel data: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while loading the round control panel.";
    header("location: " . ($_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'));
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle create new round
    if (isset($_POST['action']) && $_POST['action'] === 'create_round') {
        try {
            // Complete current round and create new one
            $next_round_number = $round_number + 1;
            $new_round_id = createNewRound($session_id, $session['breakdown_id'], $next_round_number);
            
            if ($new_round_id) {
                $_SESSION['success_message'] = "New round created successfully!";
                header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
                exit;
            } else {
                $_SESSION['error_message'] = "Failed to create new round.";
                header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
                exit;
            }
        } catch (Exception $e) {
            error_log("Error creating new round: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while creating a new round.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
            exit;
        }
    }
    
    // Handle set boost
    if (isset($_POST['action']) && $_POST['action'] === 'set_boost') {
        $target_round_id = (int)$_POST['target_round_id'];
        $gift_id = (int)$_POST['gift_id'];
        
        try {
            $result = setRoundBoost($session_id, $target_round_id, $gift_id);
            
            if ($result) {
                $_SESSION['success_message'] = "Boost set successfully!";
                header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
                exit;
            } else {
                $_SESSION['error_message'] = "Failed to set boost.";
                header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
                exit;
            }
        } catch (Exception $e) {
            error_log("Error setting boost: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while setting the boost.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
            exit;
        }
    }
    
    // Handle end session
    if (isset($_POST['action']) && $_POST['action'] === 'end_session') {
        try {
            // Update session status
            executeQuery(
                "UPDATE shuffle_sessions SET status = 'completed', end_time = NOW() WHERE id = ?",
                [$session_id],
                'i'
            );
            
            // Complete current round
            executeQuery(
                "UPDATE breakdown_rounds SET status = 'completed', completed_at = NOW() WHERE session_id = ? AND status = 'active'",
                [$session_id],
                'i'
            );
            
            // Log activity
            logActivity(
                $_SESSION["id"],
                "complete_session",
                "Completed gift shuffle session (ID: {$session_id})"
            );
            
            $_SESSION['success_message'] = "Session completed successfully!";
            header("location: view_session.php?id=" . $session_id);
            exit;
        } catch (Exception $e) {
            error_log("Error completing session: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred while completing the session.";
            header("location: " . $_SERVER['PHP_SELF'] . "?id=" . $session_id);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Round Control Panel - Gift Shuffle System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a73e8;
            --primary-hover: #1557b0;
            --secondary-color: #6c5ce7;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --background-color: #f5f9ff;
            --card-color: #ffffff;
            --text-color: #333333;
            --text-secondary: #6c757d;
            --border-color: #e1e1e1;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--background-color);
            min-height: 100vh;
        }

        /* Navbar */
        .navbar {
            background: white;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            padding: 15px 30px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
        }

        .logo i {
            font-size: 1.8rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            background: #f1f3f4;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: background 0.3s ease;
        }

        .back-btn:hover {
            background: #e2e6ea;
        }

        /* Main container */
        .container {
            max-width: 1200px;
            margin: 80px auto 30px;
            padding: 0 20px;
        }

        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 1.8rem;
            color: var(--text-color);
        }

        /* Alert messages */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: var(--danger-color);
            border: 1px solid #f5c6cb;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .card-body {
            padding: 20px;
        }

        /* Round status card */
        .round-status {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }

        .status-item {
            flex: 1;
            min-width: 200px;
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .status-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--primary-color);
        }

        .status-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        /* Gift list */
        .gift-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .gift-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
        }

        .gift-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .gift-item.selected {
            border: 2px solid var(--primary-color);
            background-color: rgba(26, 115, 232, 0.05);
        }

        .gift-item.empty {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .gift-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .gift-quantity {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .quantity-bar {
            height: 6px;
            background-color: #e9ecef;
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }

        .quantity-progress {
            height: 100%;
            background-color: var(--primary-color);
            border-radius: 3px;
        }

        /* Boost section */
        .boost-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: var(--text-color);
        }

        .boost-form {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .boost-btn {
            padding: 10px 20px;
            background-color: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease;
            align-self: flex-end;
        }

        .boost-btn:hover {
            background-color: #5649d1;
        }

        /* Boost list */
        .boost-list {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }

        .boost-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .boost-item:last-child {
            border-bottom: none;
        }

        .boost-detail {
            display: flex;
            flex-direction: column;
        }

        .boost-round {
            font-weight: 600;
            color: var(--text-color);
        }

        .boost-gift {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .boost-remove {
            color: var(--danger-color);
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .boost-remove:hover {
            opacity: 0.7;
        }

        /* Winners list */
        .winners-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .winner-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .winner-row:last-child {
            border-bottom: none;
        }

        .winner-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .winner-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .winner-detail {
            font-size: 0.85rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .gift-badge {
            padding: 4px 10px;
            background-color: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Action buttons */
        .action-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease;
            text-decoration: none;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 30px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            padding: 20px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }

            .container {
                padding: 0 15px;
            }

            .boost-form {
                flex-direction: column;
            }

            .boost-btn {
                align-self: stretch;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?php echo $_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'; ?>" class="logo">
            <i class="fas fa-gift"></i>
            <span>Gift Shuffle</span>
        </a>
        <div class="user-menu">
            <a href="<?php echo $_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Round Control Panel</h1>
            <div>
                <a href="shuffle_display.php?code=<?php echo $session['access_code']; ?>" target="_blank" class="action-btn btn-primary">
                    <i class="fas fa-external-link-alt"></i>
                    Open Display
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Session Info Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle"></i>
                Session Information
            </div>
            <div class="card-body">
                <h2 style="margin-bottom: 10px;"><?php echo htmlspecialchars($session['event_name']); ?></h2>
                <p style="color: var(--text-secondary); margin-bottom: 20px;">
                    <strong>Access Code:</strong> <?php echo htmlspecialchars($session['access_code']); ?> |
                    <strong>Breakdown:</strong> <?php echo htmlspecialchars($session['breakdown_name']); ?> |
                    <strong>Vehicle:</strong> <?php echo htmlspecialchars($session['vehicle_number']); ?>
                </p>

                <div class="round-status">
                    <div class="status-item">
                        <div class="status-value"><?php echo $round_number; ?></div>
                        <div class="status-label">Current Round</div>
                    </div>
                    <div class="status-item">
                        <div class="status-value"><?php echo $remaining_gifts; ?></div>
                        <div class="status-label">Gifts Remaining</div>
                    </div>
                    <div class="status-item">
                        <div class="status-value"><?php echo $total_gifts; ?></div>
                        <div class="status-label">Total Gifts in Round</div>
                    </div>
                    <div class="status-item">
                        <div class="status-value"><?php echo $session['winners_count']; ?></div>
                        <div class="status-label">Total Winners</div>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; justify-content: space-between;">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" style="flex: 1;">
                        <input type="hidden" name="action" value="create_round">
                        <button type="submit" class="action-btn btn-success" style="width: 100%;">
                            <i class="fas fa-plus-circle"></i>
                            Create New Round
                        </button>
                    </form>
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" style="flex: 1;">
                        <input type="hidden" name="action" value="end_session">
                        <button type="submit" class="action-btn btn-danger" style="width: 100%;" onclick="return confirm('Are you sure you want to end this session?');">
                            <i class="fas fa-stop-circle"></i>
                            End Session
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Gifts & Boost Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-gift"></i>
                Gifts & Boosts
            </div>
            <div class="card-body">
                <h3 class="section-title">Current Round Gifts</h3>
                
                <div class="gift-list">
                    <?php foreach ($round_gifts as $gift): ?>
                        <?php 
                            $remaining = $gift['quantity_available'] - $gift['quantity_used'];
                            $percentage = ($gift['quantity_used'] / $gift['quantity_available']) * 100;
                        ?>
                        <div class="gift-item <?php echo $remaining <= 0 ? 'empty' : ''; ?>" data-gift-id="<?php echo $gift['gift_id']; ?>">
                            <div class="gift-name"><?php echo htmlspecialchars($gift['name']); ?></div>
                            <div class="gift-quantity">
                                <span><?php echo $remaining; ?></span> / <?php echo $gift['quantity_available']; ?> remaining
                            </div>
                            <div class="quantity-bar">
                                <div class="quantity-progress" style="width: <?php echo $percentage; ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="boost-section">
                    <h3 class="section-title">Set Boost for Round</h3>
                    
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $session_id); ?>" class="boost-form">
                        <input type="hidden" name="action" value="set_boost">
                        
                        <div class="form-group">
                            <label for="target_round_id">Select Round</label>
                            <select id="target_round_id" name="target_round_id" class="form-select" required>
                                <option value="<?php echo $round_id; ?>">Current Round (<?php echo $round_number; ?>)</option>
                                <?php foreach ($upcoming_rounds as $round): ?>
                                    <option value="<?php echo $round['id']; ?>">
                                        Round <?php echo $round['round_number']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="gift_id">Select Gift</label>
                            <select id="gift_id" name="gift_id" class="form-select" required>
                                <option value="">-- Select Gift --</option>
                                <?php foreach ($round_gifts as $gift): ?>
                                    <?php $remaining = $gift['quantity_available'] - $gift['quantity_used']; ?>
                                    <?php if ($remaining > 0): ?>
                                        <option value="<?php echo $gift['gift_id']; ?>">
                                            <?php echo htmlspecialchars($gift['name']); ?> (<?php echo $remaining; ?> left)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="boost-btn">
                            <i class="fas fa-bolt"></i>
                            Set Boost
                        </button>
                    </form>
                    
                    <h3 class="section-title" style="margin-top: 20px;">Active Boosts</h3>
                    
                    <div class="boost-list">
                        <?php if (empty($boosts)): ?>
                            <p style="text-align: center; color: var(--text-secondary); padding: 20px;">No active boosts</p>
                        <?php else: ?>
                            <?php foreach ($boosts as $boost): ?>
                                <div class="boost-item">
                                    <div class="boost-detail">
                                        <span class="boost-round">Round <?php echo $boost['round_number']; ?></span>
                                        <span class="boost-gift"><?php echo htmlspecialchars($boost['gift_name']); ?></span>
                                    </div>
                                    <form method="post" action="remove_boost.php">
                                        <input type="hidden" name="action" value="remove_boost">
                                        <input type="hidden" name="boost_id" value="<?php echo $boost['id']; ?>">
                                        <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                                        <button type="submit" class="boost-remove" title="Remove Boost">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Winners Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-trophy"></i>
                Recent Winners
            </div>
            <div class="card-body">
                <div class="winners-list">
                    <?php if (empty($recent_winners)): ?>
                        <p style="text-align: center; color: var(--text-secondary); padding: 20px;">No winners yet</p>
                    <?php else: ?>
                        <?php foreach ($recent_winners as $winner): ?>
                            <div class="winner-row">
                                <div class="winner-info">
                                    <div class="winner-name">
                                        <?php echo !empty($winner['winner_name']) ? htmlspecialchars($winner['winner_name']) : 'Anonymous Winner'; ?>
                                    </div>
                                    <div class="winner-detail">
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php echo date('M j, Y, g:i A', strtotime($winner['win_time'])); ?>
                                    </div>
                                    <div class="winner-detail">
                                        <i class="fas fa-trophy"></i>
                                        Round <?php echo $winner['round_number']; ?>
                                        <?php if ($winner['boosted']): ?>
                                            <span style="color: var(--secondary-color); font-weight: 600; margin-left: 5px;">(Boosted)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="gift-badge">
                                    <?php echo htmlspecialchars($winner['gift_name']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Gift selection
            const giftItems = document.querySelectorAll('.gift-item:not(.empty)');
            const giftSelect = document.getElementById('gift_id');
            
            giftItems.forEach(item => {
                item.addEventListener('click', function() {
                    const giftId = this.getAttribute('data-gift-id');
                    
                    // Update select element
                    giftSelect.value = giftId;
                    
                    // Update UI
                    giftItems.forEach(g => g.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
            
            // Auto-refresh the page every 30 seconds to keep data fresh
            setTimeout(function() {
                window.location.reload();
            }, 30000);
        });
    </script>
</body>
</html>