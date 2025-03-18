<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Check if gift ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No gift ID provided.";
    header("location: add_gift.php");
    exit;
}

$gift_id = (int)$_GET['id'];

// Initialize variables
$gift = [];
$usage_stats = [];
$error_message = "";
$success_message = "";

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle gift status change directly from this page if action is set
if (isset($_GET['action']) && in_array($_GET['action'], ['activate', 'deactivate'])) {
    $action = $_GET['action'];
    $is_active = ($action === 'activate') ? 1 : 0;
    
    try {
        executeQuery(
            "UPDATE gifts SET is_active = ?, updated_at = NOW() WHERE id = ?",
            [$is_active, $gift_id],
            'ii'
        );
        
        // Log activity
        $action_type = $is_active ? "activate_gift" : "deactivate_gift";
        $details = $is_active ? "Activated gift (ID: {$gift_id})" : "Deactivated gift (ID: {$gift_id})";
        
        logActivity($_SESSION["id"], $action_type, $details);
        
        $success_message = "Gift successfully " . ($is_active ? "activated" : "deactivated");
    } catch (Exception $e) {
        error_log("Error changing gift status: " . $e->getMessage());
        $error_message = "An error occurred while changing gift status";
    }
}

// Get gift details
try {
    $gift = executeQuery(
        "SELECT g.*, u.username as created_by_name, u.full_name as creator_full_name
         FROM gifts g
         LEFT JOIN users u ON g.created_by = u.id
         WHERE g.id = ?",
        [$gift_id],
        'i'
    );
    
    if (empty($gift)) {
        $_SESSION['error_message'] = "Gift not found.";
        header("location: add_gift.php");
        exit;
    }
    
    $gift = $gift[0];
    
    // Get usage statistics (how many times this gift has been used in sessions)
    $usage_stats = executeQuery(
        "SELECT COUNT(gw.id) as usage_count,
                COUNT(DISTINCT gw.session_id) as session_count,
                MAX(gw.win_time) as last_used,
                SUM(CASE WHEN gw.boosted = 1 THEN 1 ELSE 0 END) as boosted_count
         FROM gift_winners gw
         WHERE gw.gift_id = ?",
        [$gift_id],
        'i'
    );
    
    if (!empty($usage_stats)) {
        $usage_stats = $usage_stats[0];
    } else {
        $usage_stats = [
            'usage_count' => 0,
            'session_count' => 0,
            'last_used' => null,
            'boosted_count' => 0
        ];
    }
    
    // Get breakdown usage (where this gift is included)
    $breakdown_usage = executeQuery(
        "SELECT gb.id, gb.name, bg.quantity, 
                (SELECT COUNT(*) FROM shuffle_sessions ss WHERE ss.breakdown_id = gb.id) as session_count,
                (SELECT COUNT(*) FROM shuffle_sessions ss WHERE ss.breakdown_id = gb.id AND ss.status = 'active') as active_session_count
         FROM breakdown_gifts bg
         JOIN gift_breakdowns gb ON bg.breakdown_id = gb.id
         WHERE bg.gift_id = ?
         ORDER BY gb.created_at DESC",
        [$gift_id],
        'i'
    );
    
    // Get recent winners who received this gift
    $recent_winners = executeQuery(
        "SELECT gw.*, ss.event_name, ss.vehicle_number, ss.access_code 
         FROM gift_winners gw
         JOIN shuffle_sessions ss ON gw.session_id = ss.id
         WHERE gw.gift_id = ?
         ORDER BY gw.win_time DESC 
         LIMIT 5",
        [$gift_id],
        'i'
    );
    
} catch (Exception $e) {
    error_log("Error getting gift details: " . $e->getMessage());
    $error_message = "An error occurred while retrieving gift details: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Gift - Gift Shuffle System</title>
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
            color: var(--text-color);
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

        /* Alert messages */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: var(--danger-color);
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        /* Gift view section */
        .gift-view {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 30px;
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .gift-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            position: relative;
        }

        .gift-title {
            font-size: 1.8rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .gift-status {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .gift-status.active {
            background: rgba(40, 167, 69, 0.3);
            color: white;
        }

        .gift-status.inactive {
            background: rgba(220, 53, 69, 0.3);
            color: white;
        }

        .gift-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .gift-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .gift-content {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
            padding: 30px;
        }

        .gift-image-container {
            width: 100%;
            height: 300px;
            overflow: hidden;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            background-color: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: transform 0.3s ease;
        }

        .gift-image-container:hover {
            transform: scale(1.02);
        }

        .gift-image {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .gift-placeholder {
            font-size: 5rem;
            color: #e1e1e1;
        }

        .image-actions {
            position: absolute;
            bottom: 10px;
            right: 10px;
            display: flex;
            gap: 5px;
        }

        .image-action-btn {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.3s ease;
            border: none;
        }

        .image-action-btn:hover {
            background: rgba(0, 0, 0, 0.8);
        }

        .gift-info {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab:hover {
            color: var(--primary-color);
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .info-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 1.2rem;
            margin-bottom: 15px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .gift-description {
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
        }

        .stat-box {
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Breakdown usage table */
        .table-container {
            margin-top: 20px;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover td {
            background-color: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .badge-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }

        .badge-warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: #d39e00;
        }

        .badge-info {
            background-color: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
        }

        /* Action buttons */
        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-secondary {
            background-color: #f1f3f4;
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background-color: #e2e6ea;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background-color: #e0a800;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Winners list */
        .winner-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            gap: 15px;
        }

        .winner-item:last-child {
            border-bottom: none;
        }

        .winner-avatar {
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

        .winner-info {
            flex: 1;
        }

        .winner-name {
            font-weight: 600;
            margin-bottom: 3px;
        }

        .winner-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .winner-detail {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* No data message */
        .no-data {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-secondary);
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
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
        @media (max-width: 992px) {
            .gift-content {
                grid-template-columns: 1fr;
            }
            
            .gift-image-container {
                height: 250px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }
            
            .container {
                padding: 0 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .gift-meta {
                flex-direction: column;
                gap: 5px;
            }
            
            .actions {
                flex-wrap: wrap;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Print styles */
        @media print {
            .navbar, .actions, .back-btn, .image-actions, .tabs {
                display: none !important;
            }
            
            .container {
                margin: 0;
                padding: 0;
                width: 100%;
            }
            
            .gift-view {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            body {
                background: white;
            }
            
            .gift-content {
                grid-template-columns: 1fr;
            }
            
            .tab-content {
                display: block !important;
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
            <a href="gifts.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Gifts
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- Gift Details Section -->
        <div class="gift-view">
            <div class="gift-header">
                <h1 class="gift-title">
                    <i class="fas fa-gift"></i>
                    <?php echo htmlspecialchars($gift['name']); ?>
                </h1>
                <div class="gift-meta">
                    <div class="gift-meta-item">
                        <i class="fas fa-user"></i>
                        <span>Created by: <?php echo htmlspecialchars($gift['creator_full_name'] ?? $gift['created_by_name'] ?? 'Unknown'); ?></span>
                    </div>
                    <div class="gift-meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Created: <?php echo date('M j, Y', strtotime($gift['created_at'])); ?></span>
                    </div>
                    <?php if ($gift['created_at'] != $gift['updated_at']): ?>
                    <div class="gift-meta-item">
                        <i class="fas fa-edit"></i>
                        <span>Last Updated: <?php echo date('M j, Y', strtotime($gift['updated_at'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <span class="gift-status <?php echo $gift['is_active'] ? 'active' : 'inactive'; ?>">
                    <i class="fas <?php echo $gift['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                    <?php echo $gift['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
            
            <div class="gift-content">
                <div class="gift-image-container">
                    <?php if (!empty($gift['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($gift['image_url']); ?>" alt="<?php echo htmlspecialchars($gift['name']); ?>" class="gift-image">
                    <?php else: ?>
                        <div class="gift-placeholder">
                            <i class="fas fa-gift"></i>
                        </div>
                    <?php endif; ?>
                    
                    <div class="image-actions">
                        <?php if (!empty($gift['image_url'])): ?>
                            <a href="<?php echo htmlspecialchars($gift['image_url']); ?>" target="_blank" class="image-action-btn" title="View full image">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                            <button onclick="printGift()" class="image-action-btn" title="Print gift details">
                                <i class="fas fa-print"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="gift-info">
                    <div class="tabs">
                        <div class="tab active" data-tab="details">Details</div>
                        <div class="tab" data-tab="usage">Usage Statistics</div>
                        <div class="tab" data-tab="breakdowns">Breakdown Usage</div>
                        <div class="tab" data-tab="winners">Recent Winners</div>
                    </div>
                    
                    <!-- Details Tab -->
                    <div class="tab-content active" id="details-tab">
                        <div class="info-section">
                            <h2 class="section-title">
                                <i class="fas fa-info-circle"></i>
                                Description
                            </h2>
                            <?php if (!empty($gift['description'])): ?>
                                <p class="gift-description"><?php echo nl2br(htmlspecialchars($gift['description'])); ?></p>
                            <?php else: ?>
                                <p class="gift-description">No description available.</p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="info-section">
                            <h2 class="section-title">
                                <i class="fas fa-chart-pie"></i>
                                Overview
                            </h2>
                            
                            <div class="stats-grid">
                                <div class="stat-box">
                                    <div class="stat-value"><?php echo number_format($usage_stats['usage_count']); ?></div>
                                    <div class="stat-label">Times Used</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value"><?php echo number_format($usage_stats['session_count']); ?></div>
                                    <div class="stat-label">Sessions</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value"><?php echo number_format($usage_stats['boosted_count']); ?></div>
                                    <div class="stat-label">Times Boosted</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value">
                                        <?php 
                                        echo !empty($usage_stats['last_used']) 
                                            ? date('M j', strtotime($usage_stats['last_used'])) 
                                            : '-';
                                        ?>
                                    </div>
                                    <div class="stat-label">Last Used</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Usage Statistics Tab -->
                    <div class="tab-content" id="usage-tab">
                        <div class="info-section">
                            <h2 class="section-title">
                                <i class="fas fa-chart-bar"></i>
                                Usage Statistics
                            </h2>
                            
                            <?php if ($usage_stats['usage_count'] > 0): ?>
                                <div class="stats-grid">
                                    <div class="stat-box">
                                        <div class="stat-value"><?php echo number_format($usage_stats['usage_count']); ?></div>
                                        <div class="stat-label">Total Times Used</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-value"><?php echo number_format($usage_stats['session_count']); ?></div>
                                        <div class="stat-label">Unique Sessions</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-value"><?php echo number_format($usage_stats['boosted_count']); ?></div>
                                        <div class="stat-label">Times Boosted</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-value">
                                            <?php 
                                            echo !empty($usage_stats['last_used']) 
                                                ? date('M j, Y', strtotime($usage_stats['last_used'])) 
                                                : '-';
                                            ?>
                                        </div>
                                        <div class="stat-label">Last Used</div>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 20px;">
                                    <h3 style="font-size: 1.1rem; margin-bottom: 15px; color: var(--text-color);">
                                        <i class="fas fa-percentage"></i>
                                        Usage Statistics
                                    </h3>
                                    
                                    <?php
                                        // Calculate boost percentage
                                        $boost_percentage = $usage_stats['usage_count'] > 0 
                                            ? round(($usage_stats['boosted_count'] / $usage_stats['usage_count']) * 100, 1) 
                                            : 0;
                                    ?>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span>Boost Rate</span>
                                            <span><?php echo $boost_percentage; ?>%</span>
                                        </div>
                                        <div style="height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $boost_percentage; ?>%; background: var(--secondary-color);"></div>
                                        </div>
                                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 5px;">
                                            This gift was boosted in <?php echo $usage_stats['boosted_count']; ?> out of <?php echo $usage_stats['usage_count']; ?> uses
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-chart-line"></i>
                                    <p>This gift has not been used yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Breakdown Usage Tab -->
                    <div class="tab-content" id="breakdowns-tab">
                        <div class="info-section">
                            <h2 class="section-title">
                                <i class="fas fa-boxes"></i>
                                Breakdown Usage
                            </h2>
                            
                            <?php if (!empty($breakdown_usage)): ?>
                                <div class="table-container">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Breakdown</th>
                                                <th>Quantity</th>
                                                <th>Sessions</th>
                                                <th>Active Sessions</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($breakdown_usage as $usage): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($usage['name']); ?></td>
                                                    <td><?php echo number_format($usage['quantity']); ?></td>
                                                    <td><?php echo number_format($usage['session_count']); ?></td>
                                                    <td>
                                                        <?php if ($usage['active_session_count'] > 0): ?>
                                                            <span class="badge badge-success">
                                                                <?php echo number_format($usage['active_session_count']); ?> Active
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-secondary">
                                                                No active sessions
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="view_breakdown.php?id=<?php echo $usage['id']; ?>" style="color: var(--info-color); display: inline-flex; align-items: center; gap: 5px;">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-cubes"></i>
                                    <p>This gift has not been used in any breakdown yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Winners Tab -->
                    <div class="tab-content" id="winners-tab">
                        <div class="info-section">
                            <h2 class="section-title">
                                <i class="fas fa-trophy"></i>
                                Recent Winners
                            </h2>
                            
                            <?php if (!empty($recent_winners)): ?>
                                <div>
                                    <?php foreach ($recent_winners as $winner): ?>
                                        <div class="winner-item">
                                            <div class="winner-avatar">
                                                <?php echo !empty($winner['winner_name']) ? strtoupper(substr($winner['winner_name'], 0, 1)) : 'A'; ?>
                                            </div>
                                            <div class="winner-info">
                                                <div class="winner-name">
                                                    <?php echo !empty($winner['winner_name']) ? htmlspecialchars($winner['winner_name']) : 'Anonymous Winner'; ?>
                                                </div>
                                                <div class="winner-details">
                                                    <div class="winner-detail">
                                                        <i class="fas fa-calendar-alt"></i>
                                                        <span><?php echo date('M j, Y', strtotime($winner['win_time'])); ?></span>
                                                    </div>
                                                    <div class="winner-detail">
                                                        <i class="fas fa-store"></i>
                                                        <span><?php echo htmlspecialchars($winner['event_name']); ?></span>
                                                    </div>
                                                    <div class="winner-detail">
                                                        <i class="fas fa-truck"></i>
                                                        <span><?php echo htmlspecialchars($winner['vehicle_number']); ?></span>
                                                    </div>
                                                    <?php if ($winner['boosted']): ?>
                                                        <div class="winner-detail">
                                                            <span class="badge badge-info">
                                                                <i class="fas fa-bolt"></i> Boosted
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($recent_winners) >= 5): ?>
                                        <div style="text-align: center; padding: 15px;">
                                            <a href="gift_winners.php?gift_id=<?php echo $gift_id; ?>" style="color: var(--primary-color); font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fas fa-list"></i> View All Winners
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-users"></i>
                                    <p>No winners have received this gift yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="actions">
            <a href="gifts.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Back to Gifts
            </a>
            <a href="edit_gift.php?id=<?php echo $gift_id; ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i>
                Edit Gift
            </a>
            <?php if ($gift['is_active']): ?>
                <a href="view_gift.php?id=<?php echo $gift_id; ?>&action=deactivate" class="btn btn-danger" onclick="return confirm('Are you sure you want to deactivate this gift?')">
                    <i class="fas fa-ban"></i>
                    Deactivate Gift
                </a>
            <?php else: ?>
                <a href="view_gift.php?id=<?php echo $gift_id; ?>&action=activate" class="btn btn-success">
                    <i class="fas fa-check-circle"></i>
                    Activate Gift
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" onclick="printGift()">
                <i class="fas fa-print"></i>
                Print Gift Details
            </button>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Remove active class from all tabs
                    tabs.forEach(t => t.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    
                    // Hide all tab contents
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Show the selected tab content
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });
        });
        
        // Print function
        function printGift() {
            window.print();
        }
    </script>
</body>
</html>