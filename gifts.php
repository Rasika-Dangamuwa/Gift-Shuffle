<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Initialize variables
$success_message = "";
$error_message = "";

// Get filter parameters from URL or set defaults
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Handle gift deactivation/activation
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $gift_id = (int)$_GET['id'];
    
    if ($action === 'deactivate' || $action === 'activate') {
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
            
            // Maintain filter parameters in redirect
            $redirect_params = http_build_query([
                'search' => $search_term,
                'status' => $status_filter
            ]);
            header("Location: gifts.php?$redirect_params");
            exit;
        } catch (Exception $e) {
            error_log("Error changing gift status: " . $e->getMessage());
            $error_message = "An error occurred while changing gift status";
        }
    }
}

// Get all gifts
try {
    // Base query
    $query = "SELECT g.* FROM gifts g";
    $params = [];
    $types = '';
    
    // Build the WHERE clause based on filters
    $where_clauses = [];
    
    // Add conditions to the query based on status filter
    if ($status_filter === 'active') {
        $where_clauses[] = "g.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_clauses[] = "g.is_active = 0";
    }
    
    // Combine WHERE clauses if any exist
    if (!empty($where_clauses)) {
        $query .= " WHERE " . implode(' AND ', $where_clauses);
    }
    
    // Add final order by
    $query .= " ORDER BY g.is_active DESC, g.name ASC";
    
    // Execute the query
    $gifts = executeQuery($query, $params, $types);
} catch (Exception $e) {
    error_log("Error getting gifts: " . $e->getMessage());
    $gifts = [];
    $error_message = "An error occurred while retrieving gifts";
}

// Count gifts by status
$active_count = 0;
$inactive_count = 0;

foreach ($gifts as $gift) {
    if ($gift['is_active']) {
        $active_count++;
    } else {
        $inactive_count++;
    }
}

$total_count = count($gifts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gifts - Gift Shuffle System</title>
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
            text-align: center;
            transition: transform 0.3s ease;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }

        .total-gifts {
            color: var(--primary-color);
        }

        .active-gifts {
            color: var(--success-color);
        }

        .inactive-gifts {
            color: var(--danger-color);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Action buttons */
        .action-btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        /* Search and Filter */
        .search-filter {
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .search-filter-header {
            margin-bottom: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-filter-content {
            display: flex;
            gap: 15px;
        }

        .search-box {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .clear-search {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            display: none;
            background: none;
            border: none;
            font-size: 1rem;
        }

        .filter-select {
            min-width: 150px;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            background-color: white;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .search-results {
            margin-top: 15px;
            font-size: 0.9rem;
            color: var(--text-secondary);
            display: none;
        }

        /* Gifts grid */
        .gifts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .gift-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 2px solid transparent;
        }

        .gift-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .gift-card.inactive {
            opacity: 0.7;
            border-color: #f8d7da;
        }

        .gift-image {
            width: 100%;
            height: 180px;
            background-color: #f8f9fa;
            overflow: hidden;
            position: relative;
        }

        .gift-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gift-image-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-secondary);
            font-size: 3rem;
        }

        .gift-info {
            padding: 20px;
        }

        .gift-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .gift-description {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 15px;
            min-height: 40px;
            max-height: 60px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .gift-status {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
            margin-bottom: 15px;
        }

        .status-active {
            color: var(--success-color);
        }

        .status-inactive {
            color: var(--danger-color);
        }

        .gift-actions {
            display: flex;
            gap: 10px;
        }

        .gift-action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: opacity 0.3s ease;
        }

        .gift-action-btn:hover {
            opacity: 0.9;
        }

        .action-view {
            background-color: var(--info-color);
        }

        .action-edit {
            background-color: var(--warning-color);
        }

        .action-activate {
            background-color: var(--success-color);
        }

        .action-deactivate {
            background-color: var(--danger-color);
        }

        /* Empty state */
        .empty-state {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 50px 20px;
            text-align: center;
        }

        .empty-icon {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }

        .empty-title {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .empty-message {
            color: var(--text-secondary);
            margin-bottom: 25px;
        }

        /* No results */
        .no-results {
            text-align: center;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }

        .no-results-icon {
            font-size: 3rem;
            color: #e1e1e1;
            margin-bottom: 15px;
        }

        .no-results-title {
            font-size: 1.2rem;
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .no-results-message {
            color: var(--text-secondary);
            margin-bottom: 20px;
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
            .gifts-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }
            
            .container {
                padding: 0 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .search-filter-content {
                flex-direction: column;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
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
            <h1 class="page-title">Gift Management</h1>
            <a href="add_gift.php" class="action-btn btn-primary">
                <i class="fas fa-plus"></i>
                Add New Gift
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

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card" data-filter="all">
                <div class="stat-value total-gifts"><?php echo $total_count; ?></div>
                <div class="stat-label">Total Gifts</div>
            </div>
            <div class="stat-card" data-filter="active">
                <div class="stat-value active-gifts"><?php echo $active_count; ?></div>
                <div class="stat-label">Active Gifts</div>
            </div>
            <div class="stat-card" data-filter="inactive">
                <div class="stat-value inactive-gifts"><?php echo $inactive_count; ?></div>
                <div class="stat-label">Inactive Gifts</div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-filter">
            <div class="search-filter-header">
                <i class="fas fa-search"></i>
                Search and Filter Gifts
            </div>
            <div class="search-filter-content">
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search by gift name or description..." value="<?php echo htmlspecialchars($search_term); ?>">
                    <button id="clearSearch" class="clear-search" title="Clear search">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <select id="statusFilter" class="filter-select">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
                </select>
            </div>
            <div id="searchResults" class="search-results">
                Showing <span id="resultsCount">0</span> of <?php echo $total_count; ?> gifts
            </div>
        </div>

        <!-- Gifts Grid -->
        <?php if (empty($gifts)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-gift"></i>
                </div>
                <h3 class="empty-title">No Gifts Found</h3>
                <p class="empty-message">You haven't added any gifts yet. Click the button below to add your first gift.</p>
                <a href="add_gift.php" class="action-btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add New Gift
                </a>
            </div>
        <?php else: ?>
            <div id="giftsGrid" class="gifts-grid">
                <?php foreach ($gifts as $gift): ?>
                    <div class="gift-card <?php echo $gift['is_active'] ? '' : 'inactive'; ?>" data-status="<?php echo $gift['is_active'] ? 'active' : 'inactive'; ?>">
                        <div class="gift-image">
                            <?php if (!empty($gift['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($gift['image_url']); ?>" alt="<?php echo htmlspecialchars($gift['name']); ?>">
                            <?php else: ?>
                                <div class="gift-image-placeholder">
                                    <i class="fas fa-gift"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="gift-info">
                            <h3 class="gift-name"><?php echo htmlspecialchars($gift['name']); ?></h3>
                            
                            <div class="gift-description">
                                <?php echo empty($gift['description']) ? 'No description available' : htmlspecialchars($gift['description']); ?>
                            </div>
                            
                            <div class="gift-status <?php echo $gift['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                <i class="fas <?php echo $gift['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                <span><?php echo $gift['is_active'] ? 'Active' : 'Inactive'; ?></span>
                            </div>
                            
                            <div class="gift-actions">
                                <a href="view_gift.php?id=<?php echo $gift['id']; ?>" class="gift-action-btn action-view">
                                    <i class="fas fa-eye"></i>
                                    View
                                </a>
                                
                                <a href="edit_gift.php?id=<?php echo $gift['id']; ?>" class="gift-action-btn action-edit">
                                    <i class="fas fa-edit"></i>
                                    Edit
                                </a>
                                
                                <?php if ($gift['is_active']): ?>
                                    <a href="gifts.php?action=deactivate&id=<?php echo $gift['id']; ?>&search=<?php echo urlencode($search_term); ?>&status=<?php echo $status_filter; ?>" class="gift-action-btn action-deactivate" onclick="return confirm('Are you sure you want to deactivate this gift?')">
                                        <i class="fas fa-ban"></i>
                                        Deactivate
                                    </a>
                                <?php else: ?>
                                    <a href="gifts.php?action=activate&id=<?php echo $gift['id']; ?>&search=<?php echo urlencode($search_term); ?>&status=<?php echo $status_filter; ?>" class="gift-action-btn action-activate">
                                        <i class="fas fa-check"></i>
                                        Activate
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- No results message (hidden by default) -->
            <div id="noResults" class="no-results" style="display: none;">
                <div class="no-results-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h3 class="no-results-title">No matching gifts found</h3>
                <p class="no-results-message">Try adjusting your search term or filter criteria</p>
                <button id="resetFilters" class="action-btn btn-primary">
                    <i class="fas fa-undo"></i>
                    Reset Filters
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Cache DOM elements
            const searchInput = document.getElementById('searchInput');
            const clearSearchBtn = document.getElementById('clearSearch');
            const statusFilter = document.getElementById('statusFilter');
            const giftsGrid = document.getElementById('giftsGrid');
            const giftCards = document.querySelectorAll('.gift-card');
            const searchResults = document.getElementById('searchResults');
            const resultsCount = document.getElementById('resultsCount');
            const noResults = document.getElementById('noResults');
            const resetFiltersBtn = document.getElementById('resetFilters');
            const statCards = document.querySelectorAll('.stat-card');
            
            // Update URL with current filters
            function updateURL() {
                const searchParams = new URLSearchParams();
                if (searchInput.value) {
                    searchParams.set('search', searchInput.value);
                }
                searchParams.set('status', statusFilter.value);
                
                const newURL = `${window.location.pathname}?${searchParams.toString()}`;
                window.history.pushState({ path: newURL }, '', newURL);
            }
            
            // Filter gifts based on search input and status filter
            function filterGifts() {
                const searchTerm = searchInput.value.toLowerCase();
                const statusValue = statusFilter.value;
                let visibleCount = 0;
                
                // Show/hide clear search button
                clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
                
                // Filter each gift card
                giftCards.forEach(card => {
                    const name = card.querySelector('.gift-name').textContent.toLowerCase();
                    const description = card.querySelector('.gift-description').textContent.toLowerCase();
                    const status = card.getAttribute('data-status');
                    
                    // Check if card matches search and filter criteria
                    const matchesSearch = !searchTerm || name.includes(searchTerm) || description.includes(searchTerm);
                    const matchesStatus = statusValue === 'all' || status === statusValue;
                    
                    // Show or hide card based on matches
                    if (matchesSearch && matchesStatus) {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Update results count
                resultsCount.textContent = visibleCount;
                
                // Show/hide search results message
                searchResults.style.display = (searchTerm || statusValue !== 'all') ? 'block' : 'none';
                
                // Show/hide no results message
                if (visibleCount === 0 && giftCards.length > 0) {
                    noResults.style.display = 'block';
                    giftsGrid.style.display = 'none';
                } else {
                    noResults.style.display = 'none';
                    giftsGrid.style.display = 'grid';
                }
                
                // Update URL
                updateURL();
            }
            
            // Event listeners
            if (searchInput && statusFilter && giftsGrid) {
                // Filter on input and change events
                searchInput.addEventListener('input', filterGifts);
                statusFilter.addEventListener('change', filterGifts);
                
                // Clear search button
                clearSearchBtn.addEventListener('click', function() {
                    searchInput.value = '';
                    filterGifts();
                });
                
                // Reset filters button
                resetFiltersBtn?.addEventListener('click', function() {
                    searchInput.value = '';
                    statusFilter.value = 'all';
                    filterGifts();
                });
                
                // Stat card filtering
                statCards.forEach(card => {
                    card.addEventListener('click', function() {
                        const filter = this.getAttribute('data-filter');
                        statusFilter.value = filter;
                        filterGifts();
                    });
                });
                
                // Run filter on page load
                filterGifts();
            }
        });
    </script>
</body>
</html>