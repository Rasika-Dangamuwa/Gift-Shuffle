<?php
// Start the session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in, if not redirect to login page
requireLogin();

// Check if user has propagandist role
if ($_SESSION["role"] != "propagandist") {
    // Redirect to appropriate dashboard based on role
    if ($_SESSION["role"] == "manager") {
        header("location: manager_dashboard.php");
    } else {
        header("location: index.php");
    }
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

// Get active gift breakdowns
try {
    $breakdowns = executeQuery(
        "SELECT gb.*, COUNT(bg.id) as total_gifts, 
            (SELECT COUNT(*) FROM shuffle_sessions ss WHERE ss.breakdown_id = gb.id AND ss.status = 'active') as active_sessions
         FROM gift_breakdowns gb
         LEFT JOIN breakdown_gifts bg ON gb.id = bg.breakdown_id
         WHERE gb.is_active = TRUE
         GROUP BY gb.id
         ORDER BY gb.created_at DESC",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting gift breakdowns: " . $e->getMessage());
    $breakdowns = [];
}

// Get active sessions
try {
    $active_sessions = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, 
            u.username as created_by_username,
            (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         JOIN users u ON ss.created_by = u.id
         WHERE ss.status = 'active' AND ss.created_by = ?
         ORDER BY ss.start_time DESC",
        [$_SESSION["id"]],
        'i'
    );
} catch (Exception $e) {
    error_log("Error getting active sessions: " . $e->getMessage());
    $active_sessions = [];
}

// Get recent (last 5) completed sessions
try {
    $recent_sessions = executeQuery(
        "SELECT ss.*, gb.name as breakdown_name, 
            u.username as created_by_username,
            (SELECT COUNT(*) FROM gift_winners gw WHERE gw.session_id = ss.id) as winners_count
         FROM shuffle_sessions ss
         JOIN gift_breakdowns gb ON ss.breakdown_id = gb.id
         JOIN users u ON ss.created_by = u.id
         WHERE ss.status = 'completed' AND ss.created_by = ?
         ORDER BY ss.end_time DESC
         LIMIT 5",
        [$_SESSION["id"]],
        'i'
    );
} catch (Exception $e) {
    error_log("Error getting recent sessions: " . $e->getMessage());
    $recent_sessions = [];
}

// Get statistics
try {
    $stats = executeQuery(
        "SELECT 
            (SELECT COUNT(*) FROM shuffle_sessions WHERE created_by = ?) as total_sessions,
            (SELECT COUNT(*) FROM gift_winners gw
             JOIN shuffle_sessions ss ON gw.session_id = ss.id
             WHERE ss.created_by = ?) as total_winners,
            (SELECT COUNT(DISTINCT date(ss.start_time)) FROM shuffle_sessions ss
             WHERE ss.created_by = ?) as total_days
        ",
        [$_SESSION["id"], $_SESSION["id"], $_SESSION["id"]],
        'iii'
    )[0];
} catch (Exception $e) {
    error_log("Error getting statistics: " . $e->getMessage());
    $stats = ['total_sessions' => 0, 'total_winners' => 0, 'total_days' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gift Shuffle System</title>
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

        .page-title {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .welcome-text {
            color: var(--text-secondary);
        }

        /* Stats cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Quick actions */
        .quick-actions {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.3rem;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
        }

        .action-btn {
            padding: 15px;
            border-radius: 8px;
            border: none;
            background: var(--primary-color);
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.3s ease, transform 0.3s ease;
            text-decoration: none;
        }

        .action-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }

        .action-btn.secondary {
            background: var(--secondary-color);
        }

        .action-btn.secondary:hover {
            background: #5649d1;
        }

        /* Breakdowns section */
        .breakdowns-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            font-weight: 600;
            color: var(--text-secondary);
            background: #f8f9fa;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover td {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.active {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .status-badge.inactive {
            background: rgba(108, 117, 125, 0.1);
            color: var(--text-secondary);
        }

        .action-icon {
            padding: 6px;
            border-radius: 6px;
            color: white;
            transition: background 0.3s ease;
        }

        .action-icon.view {
            background: var(--info-color);
        }

        .action-icon.edit {
            background: var(--warning-color);
        }

        .action-icon.delete {
            background: var(--danger-color);
        }

        .action-icon:hover {
            opacity: 0.8;
        }

        /* Session sections */
        .sessions-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .active-sessions,
        .recent-sessions {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .session-card {
            padding: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .session-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border-color: var(--primary-color);
        }

        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .session-title {
            font-weight: 600;
            color: var(--text-color);
        }

        .session-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .info-item i {
            width: 16px;
            color: var(--primary-color);
        }

        .session-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .winners-count {
            font-size: 0.85rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .winners-count i {
            color: var(--success-color);
        }

        .session-btn {
            padding: 6px 12px;
            border-radius: 6px;
            background: var(--primary-color);
            color: white;
            border: none;
            font-size: 0.85rem;
            cursor: pointer;
            transition: background 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .session-btn:hover {
            background: var(--primary-hover);
        }

        .session-btn.view {
            background: var(--info-color);
        }

        .session-btn.view:hover {
            background: #138496;
        }

        .session-btn.resume {
            background: var(--success-color);
        }

        .session-btn.resume:hover {
            background: #218838;
        }

        .empty-state {
            padding: 30px;
            text-align: center;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.2;
        }

        .empty-state p {
            margin-bottom: 20px;
        }

        .create-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease;
            text-decoration: none;
        }

        .create-btn:hover {
            background: var(--primary-hover);
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
            
            .user-name {
                display: none;
            }
            
            .user-role {
                display: none;
            }
            
            .sessions-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
                <div>
                    <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? $_SESSION["username"]); ?></div>
                    <div class="user-role">Propagandist</div>
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
            <h1 class="page-title">Dashboard</h1>
            <p class="welcome-text">Welcome back, <?php echo htmlspecialchars($user['full_name'] ?? $_SESSION["username"]); ?>. Here's an overview of your gift shuffle activities.</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Sessions</div>
                    <div class="stat-icon primary">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_sessions']); ?></div>
                <div class="stat-description">Sessions created</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total Winners</div>
                    <div class="stat-icon success">
                        <i class="fas fa-trophy"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_winners']); ?></div>
                <div class="stat-description">Gifts distributed</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Active Days</div>
                    <div class="stat-icon warning">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_days']); ?></div>
                <div class="stat-description">Days of activity</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i>
                Quick Actions
            </h2>
            <div class="action-buttons">
                <a href="create_session.php" class="action-btn">
                    <i class="fas fa-plus-circle"></i>
                    Start New Session
                </a>
                <a href="gift_breakdowns.php" class="action-btn secondary">
                    <i class="fas fa-boxes"></i>
                    Manage Gift Breakdowns
                </a>
            </div>
        </div>

        <!-- Gift Breakdowns -->
        <div class="breakdowns-container">
            <h2 class="section-title">
                <i class="fas fa-th-large"></i>
                Available Gift Breakdowns
            </h2>
            
            <?php if (empty($breakdowns)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No gift breakdowns available yet.</p>
                    <a href="create_breakdown.php" class="create-btn">
                        <i class="fas fa-plus"></i>
                        Create Breakdown
                    </a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Total Number</th>
                            <th>Gift Types</th>
                            <th>Status</th>
                            <th>Active Sessions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breakdowns as $breakdown): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($breakdown['name']); ?></td>
                                <td><?php echo number_format($breakdown['total_number']); ?></td>
                                <td><?php echo number_format($breakdown['total_gifts']); ?> gifts</td>
                                <td>
                                    <span class="status-badge <?php echo $breakdown['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $breakdown['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($breakdown['active_sessions']); ?></td>
                                <td style="white-space: nowrap;">
                                    <a href="view_breakdown.php?id=<?php echo $breakdown['id']; ?>" class="action-icon view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_breakdown.php?id=<?php echo $breakdown['id']; ?>" class="action-icon edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($breakdown['active_sessions'] === 0): ?>
                                        <a href="toggle_breakdown.php?id=<?php echo $breakdown['id']; ?>&status=<?php echo $breakdown['is_active'] ? '0' : '1'; ?>" class="action-icon <?php echo $breakdown['is_active'] ? 'delete' : 'view'; ?>" title="<?php echo $breakdown['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                            <i class="fas fa-<?php echo $breakdown['is_active'] ? 'times' : 'check'; ?>"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Sessions -->
        <div class="sessions-container">
            <!-- Active Sessions -->
            <div class="active-sessions">
                <h2 class="section-title">
                    <i class="fas fa-play-circle"></i>
                    Active Sessions
                </h2>
                
                <?php if (empty($active_sessions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-hourglass-start"></i>
                        <p>No active sessions.</p>
                        <a href="create_session.php" class="create-btn">
                            <i class="fas fa-plus"></i>
                            Start New Session
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($active_sessions as $session): ?>
                        <div class="session-card">
                            <div class="session-header">
                                <div class="session-title"><?php echo htmlspecialchars($session['event_name']); ?></div>
                                <span class="status-badge active">Active</span>
                            </div>
                            <div class="session-info">
                                <div class="info-item">
                                    <i class="fas fa-th-large"></i>
                                    <span><?php echo htmlspecialchars($session['breakdown_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo date('M j, Y', strtotime($session['session_date'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Started: <?php echo date('h:i A', strtotime($session['start_time'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-truck"></i>
                                    <span><?php echo htmlspecialchars($session['vehicle_number']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-key"></i>
                                    <span>Access Code: <strong><?php echo htmlspecialchars($session['access_code']); ?></strong></span>
                                </div>
                            </div>
                            <div class="session-footer">
                                <div class="winners-count">
                                    <i class="fas fa-trophy"></i>
                                    <span><?php echo number_format($session['winners_count']); ?> winners so far</span>
                                </div>
                                <a href="session_control.php?id=<?php echo $session['id']; ?>" class="session-btn resume">
                                    <i class="fas fa-play"></i>
                                    Control Session
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Recent Sessions -->
            <div class="recent-sessions">
                <h2 class="section-title">
                    <i class="fas fa-history"></i>
                    Recent Sessions
                </h2>
                
                <?php if (empty($recent_sessions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No completed sessions yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_sessions as $session): ?>
                        <div class="session-card">
                            <div class="session-header">
                                <div class="session-title"><?php echo htmlspecialchars($session['event_name']); ?></div>
                                <span class="status-badge inactive">Completed</span>
                            </div>
                            <div class="session-info">
                                <div class="info-item">
                                    <i class="fas fa-th-large"></i>
                                    <span><?php echo htmlspecialchars($session['breakdown_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?php echo date('M j, Y', strtotime($session['session_date'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Duration: <?php 
                                        $start = new DateTime($session['start_time']);
                                        $end = new DateTime($session['end_time']);
                                        $diff = $start->diff($end);
                                        echo $diff->format('%h hours, %i minutes');
                                    ?></span>
                                </div>
                            </div>
                            <div class="session-footer">
                                <div class="winners-count">
                                    <i class="fas fa-trophy"></i>
                                    <span><?php echo number_format($session['winners_count']); ?> total winners</span>
                                </div>
                                <a href="view_session.php?id=<?php echo $session['id']; ?>" class="session-btn view">
                                    <i class="fas fa-eye"></i>
                                    View Results
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="session_history.php" class="create-btn">
                            <i class="fas fa-list"></i>
                            View All Sessions
                        </a>
                        </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>
</body>
</html>