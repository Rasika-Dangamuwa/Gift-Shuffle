<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Get all gift breakdowns
try {
    $breakdowns = executeQuery(
        "SELECT gb.*, 
            COUNT(DISTINCT bg.gift_id) as gift_types, 
            SUM(bg.quantity) as total_gifts,
            (SELECT COUNT(*) FROM shuffle_sessions ss WHERE ss.breakdown_id = gb.id AND ss.status != 'completed') as active_sessions,
            u.username as created_by_name
         FROM gift_breakdowns gb
         LEFT JOIN breakdown_gifts bg ON gb.id = bg.breakdown_id
         LEFT JOIN users u ON gb.created_by = u.id
         GROUP BY gb.id
         ORDER BY gb.created_at DESC",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting gift breakdowns: " . $e->getMessage());
    $breakdowns = [];
}

// Check for success/error message from session
$success_message = "";
$error_message = "";

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gift Breakdowns - Gift Shuffle System</title>
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

        /* Header */
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

        /* Breakdown cards grid */
        .breakdowns-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .breakdown-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .breakdown-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .breakdown-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            position: relative;
        }

        .breakdown-header h3 {
            font-size: 1.3rem;
            margin-bottom: 5px;
        }

        .breakdown-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.active {
            background: #28a74540;
            color: white;
        }

        .status-badge.inactive {
            background: #6c757d40;
            color: white;
        }

        .breakdown-body {
            padding: 20px;
        }

        .breakdown-stat {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .breakdown-stat:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .stat-value {
            font-weight: 600;
        }

        .breakdown-footer {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 5px;
            color: white;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: opacity 0.3s ease;
        }

        .action-btn:hover {
            opacity: 0.9;
        }

        .action-btn.view {
            background-color: var(--info-color);
        }

        .action-btn.edit {
            background-color: var(--warning-color);
        }

        .action-btn.delete {
            background-color: var(--danger-color);
        }

        .action-btn.activate {
            background-color: var(--success-color);
        }

        /* Create button */
        .create-btn {
            padding: 10px 20px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s ease;
            text-decoration: none;
        }

        .create-btn:hover {
            background-color: var(--primary-hover);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .empty-state-icon {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 25px;
        }

        /* Back button */
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
            
            .breakdown-header h3 {
                font-size: 1.1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .create-btn {
                width: 100%;
                justify-content: center;
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
        <div class="page-header">
            <h1 class="page-title">Gift Breakdowns</h1>
            <a href="create_breakdown.php" class="create-btn">
                <i class="fas fa-plus"></i>
                Create New Breakdown
            </a>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($breakdowns)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <h3>No Gift Breakdowns Found</h3>
                <p>Create your first gift breakdown to start organizing your prizes.</p>
                <a href="create_breakdown.php" class="create-btn">
                    <i class="fas fa-plus"></i>
                    Create New Breakdown
                </a>
            </div>
        <?php else: ?>
            <div class="breakdowns-grid">
                <?php foreach ($breakdowns as $breakdown): ?>
                    <div class="breakdown-card">
                        <div class="breakdown-header">
                            <h3><?php echo htmlspecialchars($breakdown['name']); ?></h3>
                            <div class="breakdown-meta">
                                <span>Created by: <?php echo htmlspecialchars($breakdown['created_by_name']); ?></span>
                                <span><?php echo date('M j, Y', strtotime($breakdown['created_at'])); ?></span>
                            </div>
                            <span class="status-badge <?php echo $breakdown['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $breakdown['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="breakdown-body">
                            <div class="breakdown-stat">
                                <div class="stat-label">Total Number</div>
                                <div class="stat-value"><?php echo number_format($breakdown['total_number']); ?></div>
                            </div>
                            <div class="breakdown-stat">
                                <div class="stat-label">Gift Types</div>
                                <div class="stat-value"><?php echo number_format($breakdown['gift_types']); ?></div>
                            </div>
                            <div class="breakdown-stat">
                                <div class="stat-label">Total Gifts</div>
                                <div class="stat-value"><?php echo number_format($breakdown['total_gifts'] ?? 0); ?></div>
                            </div>
                            <div class="breakdown-stat">
                                <div class="stat-label">Active Sessions</div>
                                <div class="stat-value"><?php echo number_format($breakdown['active_sessions']); ?></div>
                            </div>
                        </div>
                        <div class="breakdown-footer">
                            <a href="view_breakdown.php?id=<?php echo $breakdown['id']; ?>" class="action-btn view">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <?php if ($breakdown['active_sessions'] == 0): ?>
                                <a href="edit_breakdown.php?id=<?php echo $breakdown['id']; ?>" class="action-btn edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                
                                <?php if ($breakdown['is_active']): ?>
                                    <a href="toggle_breakdown.php?id=<?php echo $breakdown['id']; ?>&status=0" class="action-btn delete">
                                        <i class="fas fa-times"></i> Deactivate
                                    </a>
                                <?php else: ?>
                                    <a href="toggle_breakdown.php?id=<?php echo $breakdown['id']; ?>&status=1" class="action-btn activate">
                                        <i class="fas fa-check"></i> Activate
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>
</body>
</html>