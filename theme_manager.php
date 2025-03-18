<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Include theme loader
define('INCLUDED', true);
require_once "includes/theme_loader.php";

// Check if user is logged in
requireLogin();

// Check if user is a manager
if ($_SESSION["role"] !== "manager") {
    header("location: access_denied.php");
    exit;
}

// Initialize variables
$upload_message = "";
$upload_error = "";

// Create themes table if it doesn't exist
try {
    executeQuery(
        "CREATE TABLE IF NOT EXISTS themes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            preview_image VARCHAR(255),
            theme_path VARCHAR(255) NOT NULL,
            directory VARCHAR(255) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        [],
        ''
    );
    
    // Check if default themes exist in database, if not add them
    $default_check = executeQuery(
        "SELECT COUNT(*) as count FROM themes WHERE is_default = 1",
        [],
        ''
    );
    
    if ($default_check[0]['count'] == 0) {
        // Add default themes to database
        $defaultThemes = getDefaultThemes();
        foreach ($defaultThemes as $theme) {
            executeQuery(
                "INSERT INTO themes (id, name, description, preview_image, theme_path, directory, is_active, is_default) 
                 VALUES (?, ?, ?, ?, ?, ?, 1, 1)",
                [
                    $theme['id'], 
                    $theme['name'], 
                    $theme['description'], 
                    $theme['preview_image'],
                    $theme['class'] ?? '',
                    $theme['class'] ?? ''
                ],
                'isssss'
            );
        }
    }
} catch (Exception $e) {
    error_log("Error creating themes table: " . $e->getMessage());
    // We'll continue anyway since the table might already exist
}

// Handle theme upload
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'upload_theme') {
        // Theme upload logic
        if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
            $tempFile = $_FILES['theme_zip']['tmp_name'];
            $themeName = isset($_POST['theme_name']) ? trim($_POST['theme_name']) : '';
            $themeDescription = isset($_POST['theme_description']) ? trim($_POST['theme_description']) : '';
            
            if (empty($themeName)) {
                $upload_error = "Theme name is required";
            } else {
                // Create themes directory if it doesn't exist
                $themesDir = __DIR__ . '/themes/';
                if (!is_dir($themesDir)) {
                    mkdir($themesDir, 0755, true);
                }
                
                // Create a unique directory name based on theme name
                $themeDirName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $themeName));
                $themeDirName = preg_replace('/-+/', '-', $themeDirName); // Replace multiple dashes with single dash
                $themeDir = $themesDir . $themeDirName;
                
                // Check if directory already exists
                if (is_dir($themeDir)) {
                    $upload_error = "A theme with this name already exists";
                } else {
                    // Begin transaction
                    $conn = getConnection();
                    $conn->begin_transaction();
                    
                    try {
                        // Create the theme directory
                        mkdir($themeDir, 0755, true);
                        
                        // Extract the ZIP file
                        $zip = new ZipArchive;
                        if ($zip->open($tempFile) === TRUE) {
                            $zip->extractTo($themeDir);
                            $zip->close();
                            
                            // Check if theme.json exists
                            $themeConfig = [];
                            $configFile = $themeDir . '/theme.json';
                            
                            if (file_exists($configFile)) {
                                $themeConfig = json_decode(file_get_contents($configFile), true);
                            } else {
                                // Create a basic theme.json file
                                $themeConfig = [
                                    'name' => $themeName,
                                    'description' => $themeDescription,
                                    'version' => '1.0',
                                    'author' => isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown'
                                ];
                                
                                file_put_contents($configFile, json_encode($themeConfig, JSON_PRETTY_PRINT));
                            }
                            
                            // Check for preview image
                            $previewImage = '';
                            if (file_exists($themeDir . '/preview.jpg')) {
                                $previewImage = 'themes/' . $themeDirName . '/preview.jpg';
                            } elseif (file_exists($themeDir . '/preview.png')) {
                                $previewImage = 'themes/' . $themeDirName . '/preview.png';
                            }
                            
                            // Add theme to database
                            $stmt = $conn->prepare(
                                "INSERT INTO themes (name, description, preview_image, theme_path, directory, is_active, is_default) 
                                 VALUES (?, ?, ?, ?, ?, 1, 0)"
                            );
                            
                            $stmt->bind_param(
                                'sssss',
                                $themeName,
                                $themeDescription,
                                $previewImage,
                                $themeDirName,
                                $themeDirName
                            );
                            
                            $stmt->execute();
                            $themeId = $conn->insert_id;
                            
                            // Log activity
                            logActivity(
                                $_SESSION["id"],
                                "theme_upload",
                                "Uploaded new theme: {$themeName} (ID: {$themeId})"
                            );
                            
                            $conn->commit();
                            $upload_message = "Theme uploaded and installed successfully!";
                            
                        } else {
                            throw new Exception("Failed to extract the ZIP file");
                        }
                    } catch (Exception $e) {
                        // Rollback transaction
                        $conn->rollback();
                        
                        // Clean up the created directory
                        if (is_dir($themeDir)) {
                            removeDirectory($themeDir);
                        }
                        
                        $upload_error = "Error: " . $e->getMessage();
                        error_log("Error in theme upload: " . $e->getMessage());
                    }
                }
            }
        } else {
            $upload_error = "Please select a valid ZIP file";
        }
    } elseif ($_POST['action'] === 'toggle_theme') {
        // Theme activation/deactivation logic
        $themeId = (int)$_POST['theme_id'];
        $isActive = (int)$_POST['status'];
        
        try {
            // Check if it's a default theme (we don't allow deactivating defaults)
            $themeCheck = executeQuery(
                "SELECT is_default, name FROM themes WHERE id = ?",
                [$themeId],
                'i'
            );
            
            if (!empty($themeCheck) && $themeCheck[0]['is_default'] == 1 && $isActive == 0) {
                $upload_error = "Default themes cannot be deactivated";
            } else {
                executeQuery(
                    "UPDATE themes SET is_active = ?, updated_at = NOW() WHERE id = ?",
                    [$isActive, $themeId],
                    'ii'
                );
                
                $themeName = $themeCheck[0]['name'] ?? 'Theme';
                $action = $isActive ? 'activated' : 'deactivated';
                
                // Log activity
                logActivity(
                    $_SESSION["id"],
                    "theme_toggle",
                    "Theme {$action}: {$themeName} (ID: {$themeId})"
                );
                
                $upload_message = "Theme {$action} successfully!";
            }
        } catch (Exception $e) {
            error_log("Error toggling theme: " . $e->getMessage());
            $upload_error = "Failed to update theme status: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete_theme') {
        // Theme deletion logic
        $themeId = (int)$_POST['theme_id'];
        
        try {
            // Check if it's a default theme (we don't allow deleting defaults)
            $themeCheck = executeQuery(
                "SELECT is_default, name, directory FROM themes WHERE id = ?",
                [$themeId],
                'i'
            );
            
            if (empty($themeCheck)) {
                $upload_error = "Theme not found";
            } elseif ($themeCheck[0]['is_default'] == 1) {
                $upload_error = "Default themes cannot be deleted";
            } else {
                $themeName = $themeCheck[0]['name'];
                $themeDir = $themeCheck[0]['directory'];
                
                // Begin transaction
                $conn = getConnection();
                $conn->begin_transaction();
                
                try {
                    // Delete from database
                    executeQueryWithConnection(
                        $conn,
                        "DELETE FROM themes WHERE id = ?",
                        [$themeId],
                        'i'
                    );
                    
                    // Delete directory if it exists
                    $fullPath = __DIR__ . '/themes/' . $themeDir;
                    if (is_dir($fullPath)) {
                        removeDirectory($fullPath);
                    }
                    
                    // Log activity
                    logActivity(
                        $_SESSION["id"],
                        "theme_delete",
                        "Deleted theme: {$themeName} (ID: {$themeId})"
                    );
                    
                    $conn->commit();
                    $upload_message = "Theme deleted successfully!";
                } catch (Exception $e) {
                    // Rollback transaction
                    $conn->rollback();
                    throw $e;
                }
            }
        } catch (Exception $e) {
            error_log("Error deleting theme: " . $e->getMessage());
            $upload_error = "Failed to delete theme: " . $e->getMessage();
        }
    }
}

// Get all themes from database
try {
    $themes = executeQuery(
        "SELECT * FROM themes ORDER BY is_default DESC, name ASC",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error retrieving themes: " . $e->getMessage());
    $themes = [];
    $upload_error = "Failed to retrieve themes from database: " . $e->getMessage();
}

// Helper function to remove a directory and its contents
function removeDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object === "." || $object === "..") {
            continue;
        }
        
        $fullPath = $dir . "/" . $object;
        if (is_dir($fullPath)) {
            removeDirectory($fullPath);
        } else {
            unlink($fullPath);
        }
    }
    
    return rmdir($dir);
}

// Get usage statistics for each theme
$themeUsage = [];
try {
    $usageData = executeQuery(
        "SELECT theme_id, COUNT(*) as session_count 
         FROM shuffle_sessions 
         GROUP BY theme_id",
        [],
        ''
    );
    
    foreach ($usageData as $usage) {
        $themeUsage[$usage['theme_id']] = $usage['session_count'];
    }
} catch (Exception $e) {
    error_log("Error getting theme usage: " . $e->getMessage());
    // Continue without usage data
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Manager - Gift Shuffle System</title>
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

        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        /* Upload form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-text {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-secondary {
            background: #f1f3f4;
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background: #e2e6ea;
        }

        /* Theme grid */
        .theme-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .theme-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .theme-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .theme-card.inactive {
            opacity: 0.7;
        }

        .theme-preview {
            width: 100%;
            height: 180px;
            background-color: #f8f9fa;
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #aaa;
            font-size: 3rem;
            position: relative;
        }

        .theme-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .theme-badge.default {
            background-color: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
        }

        .theme-badge.custom {
            background-color: rgba(108, 92, 231, 0.1);
            color: var(--secondary-color);
        }

        .theme-info {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .theme-name {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .theme-description {
            font-size: 0.95rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
            flex-grow: 1;
        }

        .theme-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .theme-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .theme-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .theme-action-btn {
            padding: 8px 12px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: opacity 0.3s ease;
            cursor: pointer;
            border: none;
        }

        .theme-action-btn:hover {
            opacity: 0.9;
        }

        .action-view {
            background-color: var(--info-color);
        }

        .action-activate {
            background-color: var(--success-color);
        }

        .action-deactivate {
            background-color: var(--warning-color);
            color: #212529;
        }

        .action-delete {
            background-color: var(--danger-color);
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
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            max-width: 500px;
            width: 100%;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            margin-bottom: 15px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* File upload zone */
        .file-upload-zone {
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-zone:hover {
            border-color: var(--primary-color);
            background-color: rgba(26, 115, 232, 0.05);
        }

        .file-upload-zone i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .file-upload-zone h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .file-upload-zone p {
            color: var(--text-secondary);
        }

        .file-name {
            margin-top: 10px;
            font-size: 0.9rem;
            color: var(--primary-color);
            font-weight: 500;
            display: none;
        }

        /* Tab navigation */
        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            color: var(--text-secondary);
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
        }

        /* Responsive adjustments */
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

            .theme-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-direction: column;
                border-bottom: none;
            }

            .tab {
                border-bottom: none;
                border-left: 3px solid transparent;
                padding: 10px 15px;
            }

            .tab.active {
                border-bottom-color: transparent;
                border-left-color: var(--primary-color);
                background-color: rgba(26, 115, 232, 0.05);
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="manager_dashboard.php" class="logo">
            <i class="fas fa-gift"></i>
            <span>Gift Shuffle</span>
        </a>
        <div class="user-menu">
            <a href="manager_dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Theme Manager</h1>
            <button class="btn btn-primary" id="uploadBtn">
                <i class="fas fa-plus"></i>
                Upload New Theme
            </button>
        </div>

        <?php if (!empty($upload_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $upload_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($upload_error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $upload_error; ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" data-tab="all-themes">All Themes</div>
            <div class="tab" data-tab="active-themes">Active Themes</div>
            <div class="tab" data-tab="inactive-themes">Inactive Themes</div>
            <div class="tab" data-tab="upload-theme">Upload Theme</div>
        </div>

        <!-- Upload Theme Form -->
        <div class="card tab-content" id="upload-theme-tab">
            <div class="card-header">Upload New Theme</div>
            <div class="card-body">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload_theme">
                    
                    <div class="form-group">
                        <label for="theme_name">Theme Name*</label>
                        <input type="text" name="theme_name" id="theme_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="theme_description">Theme Description</label>
                        <textarea name="theme_description" id="theme_description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Theme ZIP File*</label>
                        <div class="file-upload-zone" id="dropArea">
                            <input type="file" name="theme_zip" id="theme_zip" accept=".zip" required style="display: none;">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h3>Drop Theme ZIP File Here</h3>
                            <p>or click to browse files</p>
                            <div class="file-name" id="fileName"></div>
                        </div>
                                                    <div class="form-text">
                            Upload a ZIP file containing your theme files. The ZIP should include:
                            <ul style="margin-top: 8px; margin-left: 20px;">
                                <li>theme.json - Configuration file (optional)</li>
                                <li>theme.html - HTML structure for your animation</li>
                                <li>css/ - Directory for CSS files</li>
                                <li>js/ - Directory for JavaScript files</li>
                                <li>preview.jpg - Preview image for your theme</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div style="text-align: right;">
                        <button type="button" class="btn btn-secondary" id="cancelUploadBtn" style="margin-right: 10px;">
                            <i class="fas fa-times"></i>
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i>
                            Upload Theme
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- All Themes -->
        <div class="tab-content active" id="all-themes-tab">
            <div class="theme-grid">
                <?php foreach ($themes as $theme): ?>
                    <div class="theme-card <?php echo $theme['is_active'] ? '' : 'inactive'; ?>">
                        <div class="theme-preview" style="<?php echo !empty($theme['preview_image']) ? 'background-image: url(' . htmlspecialchars($theme['preview_image']) . ');' : ''; ?>">
                            <?php if (empty($theme['preview_image'])): ?>
                                <i class="fas fa-puzzle-piece"></i>
                            <?php endif; ?>
                            <span class="theme-badge <?php echo $theme['is_default'] ? 'default' : 'custom'; ?>">
                                <?php echo $theme['is_default'] ? 'Default' : 'Custom'; ?>
                            </span>
                        </div>
                        <div class="theme-info">
                            <h3 class="theme-name"><?php echo htmlspecialchars($theme['name']); ?></h3>
                            <p class="theme-description"><?php echo htmlspecialchars($theme['description'] ?? 'No description available'); ?></p>
                            <div class="theme-meta">
                                <div class="theme-meta-item">
                                    <i class="fas fa-hashtag"></i>
                                    <span>ID: <?php echo $theme['id']; ?></span>
                                </div>
                                <div class="theme-meta-item">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Used in <?php echo isset($themeUsage[$theme['id']]) ? $themeUsage[$theme['id']] : 0; ?> sessions</span>
                                </div>
                                <div class="theme-meta-item">
                                    <i class="fas fa-circle" style="color: <?php echo $theme['is_active'] ? 'var(--success-color)' : 'var(--danger-color)'; ?>; font-size: 0.8rem;"></i>
                                    <span><?php echo $theme['is_active'] ? 'Active' : 'Inactive'; ?></span>
                                </div>
                            </div>
                            <div class="theme-actions">
                                <a href="theme_preview.php?id=<?php echo $theme['id']; ?>" class="theme-action-btn action-view">
                                    <i class="fas fa-eye"></i>
                                    Preview
                                </a>
                                
                                <?php if (!$theme['is_default']): ?>
                                    <?php if ($theme['is_active']): ?>
                                        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_theme">
                                            <input type="hidden" name="theme_id" value="<?php echo $theme['id']; ?>">
                                            <input type="hidden" name="status" value="0">
                                            <button type="submit" class="theme-action-btn action-deactivate">
                                                <i class="fas fa-times-circle"></i>
                                                Deactivate
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_theme">
                                            <input type="hidden" name="theme_id" value="<?php echo $theme['id']; ?>">
                                            <input type="hidden" name="status" value="1">
                                            <button type="submit" class="theme-action-btn action-activate">
                                                <i class="fas fa-check-circle"></i>
                                                Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <button class="theme-action-btn action-delete delete-theme-btn" 
                                            data-theme-id="<?php echo $theme['id']; ?>"
                                            data-theme-name="<?php echo htmlspecialchars($theme['name']); ?>">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Active Themes -->
        <div class="tab-content" id="active-themes-tab">
            <div class="theme-grid">
                <?php 
                $activeThemes = array_filter($themes, function($theme) {
                    return $theme['is_active'] == 1;
                });
                
                if (empty($activeThemes)): 
                ?>
                    <div style="text-align: center; padding: 50px 20px; background: white; border-radius: 10px; grid-column: 1 / -1;">
                        <i class="fas fa-info-circle" style="font-size: 3rem; color: #ccc; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px; color: var(--text-color);">No Active Themes</h3>
                        <p style="color: var(--text-secondary); margin-bottom: 20px;">There are no active themes in the system.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($activeThemes as $theme): ?>
                        <div class="theme-card">
                            <div class="theme-preview" style="<?php echo !empty($theme['preview_image']) ? 'background-image: url(' . htmlspecialchars($theme['preview_image']) . ');' : ''; ?>">
                                <?php if (empty($theme['preview_image'])): ?>
                                    <i class="fas fa-puzzle-piece"></i>
                                <?php endif; ?>
                                <span class="theme-badge <?php echo $theme['is_default'] ? 'default' : 'custom'; ?>">
                                    <?php echo $theme['is_default'] ? 'Default' : 'Custom'; ?>
                                </span>
                            </div>
                            <div class="theme-info">
                                <h3 class="theme-name"><?php echo htmlspecialchars($theme['name']); ?></h3>
                                <p class="theme-description"><?php echo htmlspecialchars($theme['description'] ?? 'No description available'); ?></p>
                                <div class="theme-meta">
                                    <div class="theme-meta-item">
                                        <i class="fas fa-hashtag"></i>
                                        <span>ID: <?php echo $theme['id']; ?></span>
                                    </div>
                                    <div class="theme-meta-item">
                                        <i class="fas fa-chart-line"></i>
                                        <span>Used in <?php echo isset($themeUsage[$theme['id']]) ? $themeUsage[$theme['id']] : 0; ?> sessions</span>
                                    </div>
                                </div>
                                <div class="theme-actions">
                                    <a href="theme_preview.php?id=<?php echo $theme['id']; ?>" class="theme-action-btn action-view">
                                        <i class="fas fa-eye"></i>
                                        Preview
                                    </a>
                                    
                                    <?php if (!$theme['is_default']): ?>
                                        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_theme">
                                            <input type="hidden" name="theme_id" value="<?php echo $theme['id']; ?>">
                                            <input type="hidden" name="status" value="0">
                                            <button type="submit" class="theme-action-btn action-deactivate">
                                                <i class="fas fa-times-circle"></i>
                                                Deactivate
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Inactive Themes -->
        <div class="tab-content" id="inactive-themes-tab">
            <div class="theme-grid">
                <?php 
                $inactiveThemes = array_filter($themes, function($theme) {
                    return $theme['is_active'] == 0;
                });
                
                if (empty($inactiveThemes)): 
                ?>
                    <div style="text-align: center; padding: 50px 20px; background: white; border-radius: 10px; grid-column: 1 / -1;">
                        <i class="fas fa-info-circle" style="font-size: 3rem; color: #ccc; margin-bottom: 20px;"></i>
                        <h3 style="margin-bottom: 10px; color: var(--text-color);">No Inactive Themes</h3>
                        <p style="color: var(--text-secondary); margin-bottom: 20px;">There are no inactive themes in the system.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($inactiveThemes as $theme): ?>
                        <div class="theme-card inactive">
                            <div class="theme-preview" style="<?php echo !empty($theme['preview_image']) ? 'background-image: url(' . htmlspecialchars($theme['preview_image']) . ');' : ''; ?>">
                                <?php if (empty($theme['preview_image'])): ?>
                                    <i class="fas fa-puzzle-piece"></i>
                                <?php endif; ?>
                                <span class="theme-badge <?php echo $theme['is_default'] ? 'default' : 'custom'; ?>">
                                    <?php echo $theme['is_default'] ? 'Default' : 'Custom'; ?>
                                </span>
                            </div>
                            <div class="theme-info">
                                <h3 class="theme-name"><?php echo htmlspecialchars($theme['name']); ?></h3>
                                <p class="theme-description"><?php echo htmlspecialchars($theme['description'] ?? 'No description available'); ?></p>
                                <div class="theme-meta">
                                    <div class="theme-meta-item">
                                        <i class="fas fa-hashtag"></i>
                                        <span>ID: <?php echo $theme['id']; ?></span>
                                    </div>
                                    <div class="theme-meta-item">
                                        <i class="fas fa-chart-line"></i>
                                        <span>Used in <?php echo isset($themeUsage[$theme['id']]) ? $themeUsage[$theme['id']] : 0; ?> sessions</span>
                                    </div>
                                </div>
                                <div class="theme-actions">
                                    <a href="theme_preview.php?id=<?php echo $theme['id']; ?>" class="theme-action-btn action-view">
                                        <i class="fas fa-eye"></i>
                                        Preview
                                    </a>
                                    
                                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_theme">
                                        <input type="hidden" name="theme_id" value="<?php echo $theme['id']; ?>">
                                        <input type="hidden" name="status" value="1">
                                        <button type="submit" class="theme-action-btn action-activate">
                                            <i class="fas fa-check-circle"></i>
                                            Activate
                                        </button>
                                    </form>
                                    
                                    <button class="theme-action-btn action-delete delete-theme-btn" 
                                            data-theme-id="<?php echo $theme['id']; ?>"
                                            data-theme-name="<?php echo htmlspecialchars($theme['name']); ?>">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Theme Deletion</h5>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the theme: <strong id="themeNameDisplay"></strong>?</p>
                <p>This action cannot be undone. Any sessions using this theme will revert to the default theme.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelDeleteBtn">
                    Cancel
                </button>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <input type="hidden" name="action" value="delete_theme">
                    <input type="hidden" name="theme_id" id="themeIdInput">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i>
                        Delete Theme
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update active class on tabs
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update active class on tab contents
                    tabContents.forEach(content => content.classList.remove('active'));
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });

            // Upload form handling
            const uploadBtn = document.getElementById('uploadBtn');
            const cancelUploadBtn = document.getElementById('cancelUploadBtn');
            
            uploadBtn.addEventListener('click', function() {
                // Switch to upload tab
                tabs.forEach(t => t.classList.remove('active'));
                document.querySelector('[data-tab="upload-theme"]').classList.add('active');
                
                tabContents.forEach(content => content.classList.remove('active'));
                document.getElementById('upload-theme-tab').classList.add('active');
            });
            
            cancelUploadBtn.addEventListener('click', function() {
                // Switch back to all themes tab
                tabs.forEach(t => t.classList.remove('active'));
                document.querySelector('[data-tab="all-themes"]').classList.add('active');
                
                tabContents.forEach(content => content.classList.remove('active'));
                document.getElementById('all-themes-tab').classList.add('active');
                
                // Reset form
                document.getElementById('uploadForm').reset();
                document.getElementById('fileName').style.display = 'none';
            });
            
            // File upload handling
            const dropArea = document.getElementById('dropArea');
            const fileInput = document.getElementById('theme_zip');
            const fileName = document.getElementById('fileName');
            
            dropArea.addEventListener('click', function() {
                fileInput.click();
            });
            
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight() {
                dropArea.style.borderColor = 'var(--primary-color)';
                dropArea.style.backgroundColor = 'rgba(26, 115, 232, 0.05)';
            }
            
            function unhighlight() {
                dropArea.style.borderColor = 'var(--border-color)';
                dropArea.style.backgroundColor = 'transparent';
            }
            
            dropArea.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files.length > 0) {
                    fileInput.files = files;
                    updateFileName(files[0].name);
                }
            }
            
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    updateFileName(this.files[0].name);
                }
            });
            
            function updateFileName(name) {
                fileName.textContent = 'Selected file: ' + name;
                fileName.style.display = 'block';
            }
            
            // Delete theme modal
            const deleteModal = document.getElementById('deleteModal');
            const deleteButtons = document.querySelectorAll('.delete-theme-btn');
            const themeNameDisplay = document.getElementById('themeNameDisplay');
            const themeIdInput = document.getElementById('themeIdInput');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            const modalClose = document.querySelector('.modal-close');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const themeId = this.getAttribute('data-theme-id');
                    const themeName = this.getAttribute('data-theme-name');
                    
                    themeNameDisplay.textContent = themeName;
                    themeIdInput.value = themeId;
                    
                    deleteModal.style.display = 'flex';
                });
            });
            
            function closeModal() {
                deleteModal.style.display = 'none';
            }
            
            cancelDeleteBtn.addEventListener('click', closeModal);
            modalClose.addEventListener('click', closeModal);
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === deleteModal) {
                    closeModal();
                }
            });
        });
    </script>
</body>
</html>