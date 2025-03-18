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

// Handle theme upload
$upload_message = "";
$upload_error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'upload_theme') {
        // Theme upload logic
        if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
            $tempFile = $_FILES['theme_zip']['tmp_name'];
            $themeName = isset($_POST['theme_name']) ? trim($_POST['theme_name']) : '';
            
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
                    // Create the theme directory
                    mkdir($themeDir, 0755, true);
                    
                    // Extract the ZIP file
                    $zip = new ZipArchive;
                    if ($zip->open($tempFile) === TRUE) {
                        $zip->extractTo($themeDir);
                        $zip->close();
                        
                        // Check if theme.json exists
                        if (!file_exists($themeDir . '/theme.json')) {
                            // Create a basic theme.json file
                            $themeConfig = [
                                'id' => time(), // Use timestamp as ID
                                'name' => $themeName,
                                'description' => isset($_POST['theme_description']) ? trim($_POST['theme_description']) : '',
                                'version' => '1.0',
                                'author' => isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown'
                            ];
                            
                            file_put_contents($themeDir . '/theme.json', json_encode($themeConfig, JSON_PRETTY_PRINT));
                        }
                        
                        $upload_message = "Theme uploaded and installed successfully!";
                        
                        // Log activity
                        logActivity(
                            $_SESSION["id"],
                            "theme_upload",
                            "Uploaded new theme: {$themeName}"
                        );
                    } else {
                        $upload_error = "Failed to extract the ZIP file";
                        // Clean up the created directory
                        if (is_dir($themeDir)) {
                            removeDirectory($themeDir);
                        }
                    }
                }
            }
        } else {
            $upload_error = "Please select a valid ZIP file";
        }
    } elseif ($_POST['action'] === 'delete_theme') {
        // Theme deletion logic
        $themeDir = isset($_POST['theme_dir']) ? trim($_POST['theme_dir']) : '';
        $themeName = isset($_POST['theme_name']) ? trim($_POST['theme_name']) : 'Unknown theme';
        
        if (!empty($themeDir)) {
            $fullPath = __DIR__ . '/themes/' . $themeDir;
            
            // Ensure the path is within the themes directory for security
            if (strpos(realpath($fullPath), realpath(__DIR__ . '/themes/')) === 0 && is_dir($fullPath)) {
                if (removeDirectory($fullPath)) {
                    $upload_message = "Theme deleted successfully!";
                    
                    // Log activity
                    logActivity(
                        $_SESSION["id"],
                        "theme_delete",
                        "Deleted theme: {$themeName}"
                    );
                } else {
                    $upload_error = "Failed to delete the theme directory";
                }
            } else {
                $upload_error = "Invalid theme directory";
            }
        } else {
            $upload_error = "Theme directory not specified";
        }
    }
}

// Get all available themes
$themes = getAvailableThemes();

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

        /* Alerts */
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

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
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
        }

        .theme-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
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
        }

        .theme-info {
            padding: 20px;
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
            min-height: 60px;
        }

        .theme-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .theme-actions {
            display: flex;
            justify-content: space-between;
        }

        .theme-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .theme-badge.default {
            background-color: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
        }

        .theme-badge.external {
            background-color: rgba(108, 92, 231, 0.1);
            color: var(--secondary-color);
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }

            .container {
                padding: 0 15px;
            }

            .theme-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
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

        <!-- Theme Upload Card -->
        <div class="card" id="uploadCard" style="display: none;">
            <div class="card-header">Upload New Theme</div>
            <div class="card-body">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_theme">
                    
                    <div class="form-group">
                        <label for="theme_name">Theme Name</label>
                        <input type="text" name="theme_name" id="theme_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="theme_description">Theme Description</label>
                        <textarea name="theme_description" id="theme_description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="theme_zip">Theme ZIP File</label>
                        <input type="file" name="theme_zip" id="theme_zip" class="form-control" accept=".zip" required>
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
                        <button type="button" class="btn btn-secondary" id="cancelUploadBtn" style="background: #f1f3f4; color: var(--text-color); margin-right: 10px;">
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

        <!-- Installed Themes -->
        <div class="card">
            <div class="card-header">Installed Themes</div>
            <div class="card-body">
                <div class="theme-grid">
                    <?php foreach ($themes as $theme): ?>
                        <div class="theme-card">
                            <div class="theme-preview" style="<?php echo isset($theme['preview_image']) ? 'background-image: url(' . htmlspecialchars($theme['preview_image']) . ');' : ''; ?>">
                                <?php if (!isset($theme['preview_image'])): ?>
                                    <i class="fas fa-puzzle-piece"></i>
                                <?php endif; ?>
                            </div>
                            <div class="theme-info">
                                <span class="theme-badge <?php echo isset($theme['is_default']) && $theme['is_default'] ? 'default' : 'external'; ?>">
                                    <?php echo isset($theme['is_default']) && $theme['is_default'] ? 'Default' : 'External'; ?>
                                </span>
                                <h3 class="theme-name"><?php echo htmlspecialchars($theme['name']); ?></h3>
                                <p class="theme-description"><?php echo isset($theme['description']) ? htmlspecialchars($theme['description']) : 'No description available'; ?></p>
                                <div class="theme-meta">
                                    <span>ID: <?php echo htmlspecialchars($theme['id']); ?></span>
                                    <?php if (isset($theme['version'])): ?>
                                        <span>Version: <?php echo htmlspecialchars($theme['version']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="theme-actions">
                                    <a href="theme_preview.php?id=<?php echo $theme['id']; ?>" class="btn btn-primary" style="font-size: 0.9rem; padding: 8px 12px;">
                                        <i class="fas fa-eye"></i>
                                        Preview
                                    </a>
                                    <?php if (!isset($theme['is_default']) || !$theme['is_default']): ?>
                                        <button class="btn btn-danger delete-theme-btn" 
                                                data-theme-id="<?php echo $theme['id']; ?>"
                                                data-theme-name="<?php echo htmlspecialchars($theme['name']); ?>"
                                                data-theme-dir="<?php echo isset($theme['directory']) ? htmlspecialchars($theme['directory']) : ''; ?>"
                                                style="font-size: 0.9rem; padding: 8px 12px;">
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
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Deletion</h5>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the theme: <strong id="themeNameDisplay"></strong>?</p>
                <p>This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelDeleteBtn" style="background: #f1f3f4; color: var(--text-color);">
                    Cancel
                </button>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <input type="hidden" name="action" value="delete_theme">
                    <input type="hidden" name="theme_dir" id="themeDir">
                    <input type="hidden" name="theme_name" id="themeName">
                    <button type="submit" class="btn btn-danger">
                        Delete Theme
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Upload form toggle
            const uploadBtn = document.getElementById('uploadBtn');
            const uploadCard = document.getElementById('uploadCard');
            const cancelUploadBtn = document.getElementById('cancelUploadBtn');
            
            uploadBtn.addEventListener('click', function() {
                uploadCard.style.display = 'block';
                uploadBtn.style.display = 'none';
            });
            
            cancelUploadBtn.addEventListener('click', function() {
                uploadCard.style.display = 'none';
                uploadBtn.style.display = 'inline-flex';
            });
            
            // Delete theme modal
            const deleteModal = document.getElementById('deleteModal');
            const themeNameDisplay = document.getElementById('themeNameDisplay');
            const themeDir = document.getElementById('themeDir');
            const themeName = document.getElementById('themeName');
            const deleteButtons = document.querySelectorAll('.delete-theme-btn');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            const modalClose = document.querySelector('.modal-close');
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-theme-id');
                    const name = this.getAttribute('data-theme-name');
                    const dir = this.getAttribute('data-theme-dir');
                    
                    themeNameDisplay.textContent = name;
                    themeDir.value = dir;
                    themeName.value = name;
                    
                    deleteModal.style.display = 'flex';
                });
            });
            
            cancelDeleteBtn.addEventListener('click', function() {
                deleteModal.style.display = 'none';
            });
            
            modalClose.addEventListener('click', function() {
                deleteModal.style.display = 'none';
            });
            
            // Close modal when clicking outside
            window.addEventListener('click', function(event) {
                if (event.target === deleteModal) {
                    deleteModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>