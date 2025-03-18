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

// Check if theme ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No theme ID provided.";
    header("location: theme_manager.php");
    exit;
}

$theme_id = (int)$_GET['id'];

// Get theme details
try {
    // First check the database
    $theme = executeQuery(
        "SELECT * FROM themes WHERE id = ?",
        [$theme_id],
        'i'
    );
    
    if (empty($theme)) {
        // Try using the theme loader
        $theme_info = getThemeById($theme_id);
        
        if (!$theme_info) {
            $_SESSION['error_message'] = "Theme not found.";
            header("location: theme_manager.php");
            exit;
        }
        
        $theme = [$theme_info];
    }
    
    $theme = $theme[0];
    
    // Load theme assets
    $themeAssets = loadThemeAssets($theme_id);
    
    // Get theme HTML content
    $themeHtml = getThemeHtml($theme_id);
    
} catch (Exception $e) {
    error_log("Error getting theme: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while retrieving theme details.";
    header("location: theme_manager.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Preview: <?php echo htmlspecialchars($theme['name']); ?> - Gift Shuffle System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Include theme CSS files -->
    <?php foreach ($themeAssets['css'] as $cssFile): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile); ?>">
    <?php endforeach; ?>
    
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

        /* Card styles */
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

        /* Preview area */
        .preview-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
        }

        .preview-area {
            width: 100%;
            height: 400px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f8f9fa;
            overflow: hidden;
            position: relative;
        }

        .preview-controls {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .preview-btn {
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

        .btn-secondary {
            background: #f1f3f4;
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background: #e2e6ea;
        }

        /* Theme details card */
        .theme-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .theme-details {
                grid-template-columns: 1fr;
            }
        }

        .detail-group {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .detail-value {
            color: var(--text-secondary);
        }

        /* Files list */
        .files-list {
            list-style: none;
            margin-top: 15px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .file-item:last-child {
            border-bottom: none;
        }

        .file-icon {
            color: var(--text-secondary);
            font-size: 1.2rem;
        }

        .file-name {
            color: var(--text-color);
            font-size: 0.9rem;
        }

        /* Theme code section */
        .code-section {
            margin-top: 20px;
        }

        .code-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
        }

        .code-tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            color: var(--text-secondary);
        }

        .code-tab:hover {
            color: var(--primary-color);
        }

        .code-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .code-content {
            display: none;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 0 0 8px 8px;
            max-height: 400px;
            overflow-y: auto;
        }

        .code-content.active {
            display: block;
        }

        pre {
            margin: 0;
            white-space: pre-wrap;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9rem;
            color: #333;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 30px;
        }

        /* Default animation styles */
        .wheel-animation {
            position: relative;
            width: 300px;
            height: 300px;
            margin: 0 auto;
        }

        .wheel {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: conic-gradient(
                #1a73e8 0% 10%,
                #6c5ce7 10% 20%,
                #e74c3c 20% 30%,
                #2ecc71 30% 40%,
                #f39c12 40% 50%,
                #9b59b6 50% 60%,
                #3498db 60% 70%,
                #e67e22 70% 80%,
                #1abc9c 80% 90%,
                #f1c40f 90% 100%
            );
            transform: rotate(0deg);
            transition: transform 3s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        .spinning .wheel {
            transform: rotate(var(--turn-amount, 1800deg));
        }

        .wheel-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
            z-index: 10;
        }

        .wheel-center i {
            font-size: 24px;
            color: var(--primary-color);
        }

        .wheel-pointer {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 40px;
            background: white;
            clip-path: polygon(50% 0, 100% 100%, 0 100%);
            z-index: 5;
        }

        /* Gift Box Animation */
        .gift-box-animation {
            position: relative;
            width: 200px;
            height: 200px;
            margin: 0 auto;
            perspective: 1000px;
        }

        .gift-box {
            width: 100%;
            height: 100%;
            position: relative;
            transform-style: preserve-3d;
        }

        .gift-box-base {
            position: absolute;
            width: 100%;
            height: 80%;
            bottom: 0;
            background: #e74c3c;
            border-radius: 5px;
        }

        .gift-box-lid {
            position: absolute;
            width: 110%;
            height: 30%;
            top: -5%;
            left: -5%;
            background: #c0392b;
            border-radius: 5px;
            transform-origin: center top;
            transition: transform 1s ease-in-out;
        }

        .shuffling .gift-box-lid {
            transform: rotateX(-120deg);
        }

        .gift-ribbon {
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 200px;
            background: #f1c40f;
            border-radius: 3px;
            z-index: 5;
        }

        .gift-ribbon::before {
            content: '';
            position: absolute;
            top: 40%;
            left: -90px;
            width: 200px;
            height: 20px;
            background: #f1c40f;
            border-radius: 3px;
        }

        /* Slot Machine Animation */
        .slot-machine-animation {
            position: relative;
            width: 300px;
            height: 200px;
            margin: 0 auto;
            background: #2c3e50;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
        }

        .slot-reels {
            display: flex;
            justify-content: space-around;
            height: 100px;
            background: white;
            border-radius: 5px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .slot-reel {
            width: 33.33%;
            overflow: hidden;
            border-right: 1px solid #ddd;
        }

        .slot-reel:last-child {
            border-right: none;
        }

        .slot-reel-items {
            display: flex;
            flex-direction: column;
            animation: none;
        }

        .shuffling .slot-reel-items {
            animation: slotSpin 0.5s linear infinite;
        }

        @keyframes slotSpin {
            0% { transform: translateY(0); }
            100% { transform: translateY(calc(-100% / 7)); }
        }

        .slot-reel-item {
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        .slot-lever {
            position: absolute;
            top: 40%;
            right: -30px;
            width: 20px;
            height: 80px;
            background: #e74c3c;
            border-radius: 10px;
            cursor: pointer;
        }

        .slot-lever::before {
            content: '';
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 30px;
            height: 30px;
            background: #f1c40f;
            border-radius: 50%;
        }

        /* Scratch Card Animation */
        .scratch-card-animation {
            position: relative;
            width: 300px;
            height: 200px;
            margin: 0 auto;
            border-radius: 10px;
            overflow: hidden;
        }

        .scratch-content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: var(--primary-color);
        }

        .scratch-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #6c5ce7, #1a73e8);
            transition: opacity 1.5s ease-out;
        }

        .shuffling .scratch-overlay {
            opacity: 0;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }

            .container {
                padding: 0 15px;
            }

            .preview-area {
                height: 300px;
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
            <a href="theme_manager.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Theme Manager
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Theme Preview: <?php echo htmlspecialchars($theme['name']); ?></h1>
        </div>

        <!-- Theme Preview Section -->
        <div class="preview-container">
            <div class="preview-area" id="previewArea">
                <?php echo $themeHtml; ?>
            </div>
            <div class="preview-controls">
                <button class="preview-btn btn-primary" id="playBtn">
                    <i class="fas fa-play"></i>
                    Play Animation
                </button>
                <button class="preview-btn btn-secondary" id="resetBtn">
                    <i class="fas fa-redo"></i>
                    Reset
                </button>
            </div>
        </div>

        <!-- Theme Details Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle"></i>
                Theme Details
            </div>
            <div class="card-body">
                <div class="theme-details">
                    <div>
                        <div class="detail-group">
                            <div class="detail-label">Theme Name</div>
                            <div class="detail-value"><?php echo htmlspecialchars($theme['name']); ?></div>
                        </div>
                        <div class="detail-group">
                            <div class="detail-label">Theme ID</div>
                            <div class="detail-value"><?php echo $theme['id']; ?></div>
                        </div>
                        <div class="detail-group">
                            <div class="detail-label">Description</div>
                            <div class="detail-value"><?php echo htmlspecialchars($theme['description'] ?? 'No description available'); ?></div>
                        </div>
                        <div class="detail-group">
                            <div class="detail-label">Status</div>
                            <div class="detail-value" style="color: <?php echo isset($theme['is_active']) && $theme['is_active'] ? 'var(--success-color)' : 'var(--danger-color)'; ?>;">
                                <?php echo isset($theme['is_active']) && $theme['is_active'] ? 'Active' : 'Inactive'; ?>
                            </div>
                        </div>
                        <div class="detail-group">
                            <div class="detail-label">Type</div>
                            <div class="detail-value">
                                <?php echo isset($theme['is_default']) && $theme['is_default'] ? 'Default System Theme' : 'Custom Theme'; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="detail-group">
                            <div class="detail-label">Theme Files</div>
                            <?php if (isset($theme['directory']) && !empty($theme['directory']) && !isset($theme['is_default'])): ?>
                                <?php 
                                $themeDir = __DIR__ . '/themes/' . $theme['directory'];
                                if (is_dir($themeDir)): 
                                    $files = scandir($themeDir);
                                ?>
                                    <ul class="files-list">
                                        <?php foreach ($files as $file): ?>
                                            <?php if ($file !== '.' && $file !== '..'): ?>
                                                <li class="file-item">
                                                    <span class="file-icon">
                                                        <?php if (is_dir($themeDir . '/' . $file)): ?>
                                                            <i class="fas fa-folder"></i>
                                                        <?php elseif (pathinfo($file, PATHINFO_EXTENSION) === 'html'): ?>
                                                            <i class="fas fa-file-code"></i>
                                                        <?php elseif (pathinfo($file, PATHINFO_EXTENSION) === 'css'): ?>
                                                            <i class="fas fa-file-code"></i>
                                                        <?php elseif (pathinfo($file, PATHINFO_EXTENSION) === 'js'): ?>
                                                            <i class="fas fa-file-code"></i>
                                                        <?php elseif (pathinfo($file, PATHINFO_EXTENSION) === 'json'): ?>
                                                            <i class="fas fa-file-code"></i>
                                                        <?php elseif (in_array(pathinfo($file, PATHINFO_EXTENSION), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                            <i class="fas fa-file-image"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-file"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="file-name"><?php echo $file; ?></span>
                                                </li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="detail-value">Theme directory not found</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="detail-value">Built-in theme (files not accessible)</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (isset($theme['directory']) && !empty($theme['directory']) && !isset($theme['is_default'])): ?>
                    <?php 
                    $themeDir = __DIR__ . '/themes/' . $theme['directory'];
                    $htmlFile = $themeDir . '/theme.html';
                    $jsFiles = is_dir($themeDir . '/js') ? glob($themeDir . '/js/*.js') : [];
                    $cssFiles = is_dir($themeDir . '/css') ? glob($themeDir . '/css/*.css') : [];
                    ?>
                    
                    <div class="code-section">
                        <div class="code-tabs">
                            <?php if (file_exists($htmlFile)): ?>
                                <div class="code-tab active" data-tab="html">HTML Structure</div>
                            <?php endif; ?>
                            
                            <?php if (!empty($cssFiles)): ?>
                                <div class="code-tab" data-tab="css">CSS Styling</div>
                            <?php endif; ?>
                            
                            <?php if (!empty($jsFiles)): ?>
                                <div class="code-tab" data-tab="js">JavaScript</div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (file_exists($htmlFile)): ?>
                            <div class="code-content active" id="html-content">
                                <pre><?php echo htmlspecialchars(file_get_contents($htmlFile)); ?></pre>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($cssFiles)): ?>
                            <div class="code-content" id="css-content">
                                <?php foreach ($cssFiles as $cssFile): ?>
                                    <h4 style="margin-bottom: 10px;"><?php echo basename($cssFile); ?></h4>
                                    <pre><?php echo htmlspecialchars(file_get_contents($cssFile)); ?></pre>
                                    <?php if ($cssFile !== end($cssFiles)): ?>
                                        <hr style="margin: 15px 0; border-top: 1px dashed #ccc;">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($jsFiles)): ?>
                            <div class="code-content" id="js-content">
                                <?php foreach ($jsFiles as $jsFile): ?>
                                    <h4 style="margin-bottom: 10px;"><?php echo basename($jsFile); ?></h4>
                                    <pre><?php echo htmlspecialchars(file_get_contents($jsFile)); ?></pre>
                                    <?php if ($jsFile !== end($jsFiles)): ?>
                                        <hr style="margin: 15px 0; border-top: 1px dashed #ccc;">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Gift Shuffle System. All rights reserved.</p>
    </div>

    <!-- Include theme JS files -->
    <?php foreach ($themeAssets['js'] as $jsFile): ?>
    <script src="<?php echo htmlspecialchars($jsFile); ?>"></script>
    <?php endforeach; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const previewArea = document.getElementById('previewArea');
            const playBtn = document.getElementById('playBtn');
            const resetBtn = document.getElementById('resetBtn');
            
            // Animation control functions
            function playAnimation() {
                previewArea.classList.add('shuffling');
                previewArea.classList.add('spinning');
                
                // If it's a theme with a custom play function
                if (window.treasureTheme && typeof window.treasureTheme.start === 'function') {
                    window.treasureTheme.start();
                }
                
                // Dispatch event for theme compatibility
                const startEvent = new Event('startGiftShuffle');
                document.dispatchEvent(startEvent);
                
                // For wheel animation
                const wheel = previewArea.querySelector('.wheel');
                if (wheel) {
                    const randomDegrees = 1440 + Math.floor(Math.random() * 360);
                    previewArea.style.setProperty('--turn-amount', randomDegrees + 'deg');
                }
            }
            
            function resetAnimation() {
                previewArea.classList.remove('shuffling');
                previewArea.classList.remove('spinning');
                
                // If it's a theme with a custom reset function
                if (window.treasureTheme && typeof window.treasureTheme.reset === 'function') {
                    window.treasureTheme.reset();
                }
                
                // Dispatch event for theme compatibility
                const resetEvent = new Event('resetAnimation');
                document.dispatchEvent(resetEvent);
            }
            
            // Add event listeners
            playBtn.addEventListener('click', playAnimation);
            resetBtn.addEventListener('click', resetAnimation);
            
            // Code tabs switching
            const codeTabs = document.querySelectorAll('.code-tab');
            const codeContents = document.querySelectorAll('.code-content');
            
            codeTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update active class on tabs
                    codeTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update active class on contents
                    codeContents.forEach(content => content.classList.remove('active'));
                    document.getElementById(tabId + '-content').classList.add('active');
                });
            });
        });
    </script>
</body>
</html>