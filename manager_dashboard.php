<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Check if user has manager role
if ($_SESSION["role"] != "manager") {
    header("location: access_denied.php");
    exit;
}

// Get user information
try {
    $user = executeQuery(
        "SELECT * FROM users WHERE id = ?",
        [$_SESSION["id"]],
        'i'
    )[0];
} catch (Exception $e) {
    error_log("Error getting user information: " . $e->getMessage());
    $user = ['full_name' => $_SESSION["username"]];
}

// Get recent activity logs
try {
    $activity_logs = executeQuery(
        "SELECT al.*, u.username 
         FROM activity_log al
         LEFT JOIN users u ON al.user_id = u.id
         ORDER BY al.created_at DESC
         LIMIT 10",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting activity logs: " . $e->getMessage());
    $activity_logs = [];
}

// Get active sessions
try {
    $active_sessions = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, 
            (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         WHERE ss.status = 'active'
         ORDER BY ss.start_time DESC
         LIMIT 5",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting active sessions: " . $e->getMessage());
    $active_sessions = [];
}

// Get system statistics
try {
    $stats = executeQuery(
        "SELECT 
            (SELECT COUNT(*) FROM gifts WHERE is_active = 1) as active_gifts,
            (SELECT COUNT(*) FROM gift_breakdowns WHERE is_active = 1) as active_breakdowns,
            (SELECT COUNT(*) FROM shuffle_sessions WHERE status = 'active') as active_sessions,
            (SELECT COUNT(*) FROM gift_winners) as total_winners
        ",
        [],
        ''
    )[0];
} catch (Exception $e) {
    error_log("Error getting system statistics: " . $e->getMessage());
    $stats = [
        'active_gifts' => 0,
        'active_breakdowns' => 0,
        'active_sessions' => 0,
        'total_winners' => 0
    ];
}

// Get gift distribution by type
try {
    $gift_distribution = executeQuery(
        "SELECT g.name, COUNT(gw.id) as win_count
         FROM gifts g
         LEFT JOIN gift_winners gw ON g.id = gw.gift_id
         GROUP BY g.id
         ORDER BY win_count DESC
         LIMIT 5",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting gift distribution: " . $e->getMessage());
    $gift_distribution = [];
}

// Process any quick actions (if applicable)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["action"])) {
        switch ($_POST["action"]) {
            case "create_breakdown":
                header("location: create_breakdown.php");
                exit;
                break;
            case "add_gift":
                header("location: add_gift.php");
                exit;
                break;
            case "create_session":
                header("location: create_session.php");
                exit;
                break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - Gift Shuffle System</title>
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

        /* Navbar styles */
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
        }

        .logo i {
            font-size: 1.8rem;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .logout-btn {
            padding: 8px 15px;
            background: #f1f3f4;
            color: var(--text-color);
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .logout-btn:hover {
            background: #e2e6ea;
        }

        /* Main container */
        .container {
            max-width: 1200px;
            margin: 80px auto 30px;
            padding: 0 20px;
        }

        /* Dashboard header */
        .dashboard-header {
            margin-bottom: 30px;
        }

        .welcome-text {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .date-display {
            color: var(--text-secondary);
        }

        /* Stats cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-title {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.primary {
            background: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
        }

        .stat-icon.success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .stat-icon.warning {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }

        .stat-icon.info {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Main grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Quick actions */
        .quick-actions {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .action-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border-color: var(--primary-color);
        }

        .action-icon {
            font-size: 2rem;
            margin-bottom: 15px;
            color: var(--primary-color);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .action-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Active sessions */
        .active-sessions {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .session-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }

        .session-item:last-child {
            border-bottom: none;
        }

        .session-item:hover {
            background-color: #f8f9fa;
        }

        .session-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .session-name {
            font-weight: 600;
            color: var(--text-color);
        }

        .session-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .session-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .session-detail {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .session-actions {
            display: flex;
            gap: 10px;
        }

        .session-btn {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: opacity 0.3s ease;
        }

        .session-btn:hover {
            opacity: 0.9;
        }

        .btn-control {
            background-color: var(--primary-color);
        }

        .btn-view {
            background-color: var(--info-color);
        }

        /* Activity log */
        .activity-log {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .activity-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .activity-icon.login {
            background-color: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
        }

        .activity-icon.create {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .activity-icon.update {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }

        .activity-icon.delete {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }

        .activity-icon.default {
            background-color: rgba(108, 117, 125, 0.1);
            color: var(--text-secondary);
        }

        .activity-content {
            flex: 1;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .activity-title {
            font-weight: 600;
            color: var(--text-color);
        }

        .activity-time {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .activity-details {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        /* Charts container */
        .charts-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .chart-container {
            margin-top: 20px;
            height: 300px;
        }

        /* View all links */
        .view-all {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            margin-top: 10px;
            justify-content: center;
        }

        .view-all:hover {
            text-decoration: underline;
        }

        /* No data */
        .no-data {
            padding: 30px;
            text-align: center;
            color: var(--text-secondary);
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 30px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }
            
            .container {
                padding: 0 15px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .user-details {
                display: none;
            }
        }

        @media (max-width: 576px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="logo">
            <i class="fas fa-gift"></i>
            <span>Gift Shuffle</span>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <div class="avatar">
                    <?php echo substr($user['full_name'] ?? $_SESSION["username"], 0, 1); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? $_SESSION["username"]); ?></div>
                    <div class="user-role">Manager</div>
                </div>
            </div>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <h1 class="welcome-text">Welcome back, <?php echo htmlspecialchars($user['full_name'] ?? $_SESSION["username"]); ?></h1>
            <p class="date-display">Today is <?php echo date('l, F j, Y'); ?></p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Active Gifts</div>
                    <div class="stat-icon primary">
                        <i class="fas fa-gift"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['active_gifts']); ?></div>
                <div class="stat-description">Available for distribution</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Breakdowns</div>
                    <div class="stat-icon success">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['active_breakdowns']); ?></div>
                <div class="stat-description">Active gift breakdowns</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Active Sessions</div>
                    <div class="stat-icon warning">
                        <i class="fas fa-gamepad"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['active_sessions']); ?></div>
                <div class="stat-description">Gift distribution sessions</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Winners</div>
                    <div class="stat-icon info">
                        <i class="fas fa-trophy"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_winners']); ?></div>
                <div class="stat-description">All-time gift winners</div>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2 class="section-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </h2>
                    <div class="actions-grid">
                        <form method="post" action="">
                            <input type="hidden" name="action" value="create_breakdown">
                            <button type="submit" class="action-card" style="width: 100%; border: none; text-align: center;">
                                <div class="action-icon">
                                    <i class="fas fa-boxes"></i>
                                </div>
                                <div class="action-title">Create Breakdown</div>
                                <div class="action-description">Define new gift distributions</div>
                            </button>
                        </form>
                        
                        <form method="post" action="">
                            <input type="hidden" name="action" value="add_gift">
                            <button type="submit" class="action-card" style="width: 100%; border: none; text-align: center;">
                                <div class="action-icon">
                                    <i class="fas fa-gift"></i>
                                </div>
                                <div class="action-title">Add Gift</div>
                                <div class="action-description">Create a new gift item</div>
                            </button>
                        </form>
                        
                        <form method="post" action="">
                            <input type="hidden" name="action" value="create_session">
                            <button type="submit" class="action-card" style="width: 100%; border: none; text-align: center;">
                                <div class="action-icon">
                                    <i class="fas fa-play-circle"></i>
                                </div>
                                <div class="action-title">Start Session</div>
                                <div class="action-description">Begin a new shuffle session</div>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Active Sessions -->
                <div class="active-sessions">
                    <h2 class="section-title">
                        <i class="fas fa-play-circle"></i>
                        Active Sessions
                    </h2>
                    
                    <?php if (empty($active_sessions)): ?>
                        <div class="no-data">
                            <i class="fas fa-hourglass"></i>
                            <p>No active sessions at the moment</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($active_sessions as $session): ?>
                            <div class="session-item">
                                <div class="session-header">
                                    <div class="session-name"><?php echo htmlspecialchars($session['event_name']); ?></div>
                                    <div class="session-badge">Active</div>
                                </div>
                                <div class="session-details">
                                    <div class="session-detail">
                                        <i class="fas fa-th-large"></i>
                                        <span><?php echo htmlspecialchars($session['breakdown_name']); ?></span>
                                    </div>
                                    <div class="session-detail">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span><?php echo date('M j, Y', strtotime($session['session_date'])); ?></span>
                                    </div>
                                    <div class="session-detail">
                                        <i class="fas fa-clock"></i>
                                        <span>Started: <?php echo date('h:i A', strtotime($session['start_time'])); ?></span>
                                    </div>
                                    <div class="session-detail">
                                        <i class="fas fa-trophy"></i>
                                        <span><?php echo number_format($session['winners_count']); ?> winners</span>
                                    </div>
                                </div>
                                <div class="session-actions">
                                    <a href="session_control.php?id=<?php echo $session['id']; ?>" class="session-btn btn-control">
                                        <i class="fas fa-gamepad"></i>
                                        Control
                                    </a>
                                    <a href="view_session.php?id=<?php echo $session['id']; ?>" class="session-btn btn-view">
                                        <i class="fas fa-eye"></i>
                                        View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <a href="session_history.php" class="view-all">
                            <i class="fas fa-arrow-right"></i>
                            View All Sessions
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Gift Distribution Chart -->
                <div class="charts-container">
                    <h2 class="section-title">
                        <i class="fas fa-chart-pie"></i>
                        Gift Distribution
                    </h2>
                    <div class="chart-container">
                        <canvas id="giftDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- Activity Log -->
                <div class="activity-log">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i>
                        Recent Activity
                    </h2>
                    
                    <?php if (empty($activity_logs)): ?>
                        <div class="no-data">
                            <i class="fas fa-clipboard-list"></i>
                            <p>No activity recorded yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activity_logs as $log): ?>
                            <div class="activity-item">
                                <?php
                                    $icon_class = 'default';
                                    $icon = 'fa-clock';
                                    
                                    if (strpos($log['activity_type'], 'login') !== false) {
                                        $icon_class = 'login';
                                        $icon = 'fa-sign-in-alt';
                                    } elseif (strpos($log['activity_type'], 'create') !== false) {
                                        $icon_class = 'create';
                                        $icon = 'fa-plus-circle';
                                    } elseif (strpos($log['activity_type'], 'update') !== false) {
                                        $icon_class = 'update';
                                        $icon = 'fa-edit';
                                    } elseif (strpos($log['activity_type'], 'delete') !== false || strpos($log['activity_type'], 'deactivate') !== false) {
                                        $icon_class = 'delete';
                                        $icon = 'fa-trash-alt';
                                    }
                                ?>
                                <div class="activity-icon <?php echo $icon_class; ?>">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-header">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars($log['username'] ?? 'System'); ?>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('M j, g:i A', strtotime($log['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="activity-details">
                                        <?php echo htmlspecialchars($log['details']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <a href="activity_logs.php" class="view-all">
                            <i class="fas fa-arrow-right"></i>
                            View All Activity
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- System Management Links -->
                <div class="quick-actions" style="margin-top: 20px;">
                    <h2 class="section-title">
                        <i class="fas fa-cog"></i>
                        System Management
                    </h2>
                    <div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
                        <a href="gifts.php" style="text-decoration: none;">
                            <div class="action-card">
                                <div class="action-title">Gift Management</div>
                                <div class="action-description">Manage all gifts in the system</div>
                            </div>
                        </a>
                        <a href="gift_breakdowns.php" style="text-decoration: none;">
                            <div class="action-card">
                                <div class="action-title">Breakdown Management</div>
                                <div class="action-description">Configure gift breakdowns</div>
                            </div>
                        </a>
                        <a href="session_history.php" style="text-decoration: none;">
                            <div class="action-card">
                                <div class="action-title">Session History</div>
                                <div class="action-description">View all shuffle sessions</div>
                            </div>
                        </a>
                        <a href="theme_manager.php" style="text-decoration: none;">
                            <div class="action-card">
                                <div class="action-title">Theme Manager</div>
                                <div class="action-description">Customize shuffle themes</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Gift distribution chart
            const giftDistributionCtx = document.getElementById('giftDistributionChart').getContext('2d');
            
            // Prepare data from PHP
            const giftLabels = [
                <?php foreach ($gift_distribution as $gift): ?>
                    "<?php echo addslashes($gift['name']); ?>",
                <?php endforeach; ?>
            ];
            
            const giftData = [
                <?php foreach ($gift_distribution as $gift): ?>
                    <?php echo $gift['win_count']; ?>,
                <?php endforeach; ?>
            ];
            
            // If no data, add placeholder
            if (giftLabels.length === 0) {
                giftLabels.push('No data');
                giftData.push(0);
            }
            
            const giftDistributionChart = new Chart(giftDistributionCtx, {
                type: 'bar',
                data: {
                    labels: giftLabels,
                    datasets: [{
                        label: 'Gifts Distributed',
                        data: giftData,
                        backgroundColor: 'rgba(26, 115, 232, 0.7)',
                        borderColor: 'rgba(26, 115, 232, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>