<?php
/**
 * Theme Loader - Handles loading external animation themes
 * 
 * This file is responsible for:
 * - Finding available themes in the themes directory
 * - Loading theme configuration
 * - Providing theme assets to the shuffle display
 */

// Prevent direct access
if (!defined('INCLUDED') && basename($_SERVER['PHP_SELF']) === 'theme_loader.php') {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// Define INCLUDED constant if not already defined
if (!defined('INCLUDED')) {
    define('INCLUDED', true);
}

/**
 * Get all available themes from the themes directory
 * 
 * @return array Array of theme information
 */
function getAvailableThemes() {
    $themes = [];
    
    // Try to get themes from database first
    try {
        $dbThemes = executeQuery(
            "SELECT * FROM themes WHERE is_active = TRUE ORDER BY name",
            [],
            ''
        );
        
        foreach ($dbThemes as $theme) {
            $themes[$theme['id']] = [
                'id' => $theme['id'],
                'name' => $theme['name'],
                'description' => $theme['description'],
                'preview_image' => $theme['preview_image'],
                'is_default' => $theme['is_default'],
                'directory' => $theme['directory'],
                'path' => __DIR__ . '/../themes/' . $theme['directory']
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching themes from database: " . $e->getMessage());
        // Continue with directory scanning if database fails
    }
    
    // If no themes found in database, scan the themes directory
    if (empty($themes)) {
        $themes = scanDirectoryForThemes();
    }
    
    // Add default built-in themes for backward compatibility
    $defaultThemes = getDefaultThemes();
    foreach ($defaultThemes as $id => $theme) {
        if (!isset($themes[$id])) {
            $themes[$id] = $theme;
        }
    }
    
    return $themes;
}

/**
 * Scan the themes directory for themes
 * 
 * @return array Array of theme information
 */
function scanDirectoryForThemes() {
    $themesDir = __DIR__ . '/../themes/';
    $themes = [];
    
    // Check if themes directory exists
    if (!is_dir($themesDir)) {
        mkdir($themesDir, 0755, true);
        return $themes;
    }
    
    // Get all directories in the themes folder
    $dirList = scandir($themesDir);
    
    foreach ($dirList as $dir) {
        // Skip . and .. directories and non-directories
        if ($dir === '.' || $dir === '..' || !is_dir($themesDir . $dir)) {
            continue;
        }
        
        // Check for theme.json configuration file
        $configFile = $themesDir . $dir . '/theme.json';
        if (file_exists($configFile)) {
            $themeConfig = json_decode(file_get_contents($configFile), true);
            
            // Validate theme configuration
            if (isset($themeConfig['id']) && isset($themeConfig['name'])) {
                $themeId = $themeConfig['id'];
                $themeConfig['directory'] = $dir;
                $themeConfig['path'] = $themesDir . $dir;
                
                // Check for preview image
                $previewFile = $themesDir . $dir . '/preview.jpg';
                if (file_exists($previewFile)) {
                    $themeConfig['preview_image'] = 'themes/' . $dir . '/preview.jpg';
                }
                
                $themes[$themeId] = $themeConfig;
            }
        }
    }
    
    return $themes;
}

/**
 * Get built-in default themes
 * 
 * @return array Array of default theme information
 */
function getDefaultThemes() {
    return [
        1 => [
            'id' => 1,
            'name' => 'Spinning Wheel',
            'description' => 'Classic spinning fortune wheel that lands on a prize',
            'preview_image' => 'images/themes/wheel.jpg',
            'class' => 'wheel-theme',
            'is_default' => true
        ],
        2 => [
            'id' => 2,
            'name' => 'Gift Box Opening',
            'description' => 'Animated gift box that opens to reveal the prize',
            'preview_image' => 'images/themes/gift_box.jpg',
            'class' => 'gift-box-theme',
            'is_default' => true
        ],
        3 => [
            'id' => 3,
            'name' => 'Slot Machine',
            'description' => 'Slot machine reels that spin and land on the prize',
            'preview_image' => 'images/themes/slot_machine.jpg',
            'class' => 'slot-machine-theme',
            'is_default' => true
        ],
        4 => [
            'id' => 4,
            'name' => 'Scratch Card',
            'description' => 'Digital scratch card that reveals the prize when scratched',
            'preview_image' => 'images/themes/scratch_card.jpg',
            'class' => 'scratch-card-theme',
            'is_default' => true
        ]
    ];
}

/**
 * Get a specific theme by ID
 * 
 * @param int $themeId The theme ID to retrieve
 * @return array|null Theme information or null if not found
 */
function getThemeById($themeId) {
    // Try to get the theme from database first
    try {
        $theme = executeQuery(
            "SELECT * FROM themes WHERE id = ?",
            [$themeId],
            'i'
        );
        
        if (!empty($theme)) {
            $theme = $theme[0];
            return [
                'id' => $theme['id'],
                'name' => $theme['name'],
                'description' => $theme['description'],
                'preview_image' => $theme['preview_image'],
                'is_default' => $theme['is_default'],
                'directory' => $theme['directory'],
                'path' => __DIR__ . '/../themes/' . $theme['directory']
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting theme from database: " . $e->getMessage());
        // Continue with other methods if database fails
    }
    
    // Try to get from all available themes
    $themes = getAvailableThemes();
    
    // First check if theme exists in available themes
    if (isset($themes[$themeId])) {
        return $themes[$themeId];
    }
    
    // Fall back to default themes if not found
    $defaultThemes = getDefaultThemes();
    return isset($defaultThemes[$themeId]) ? $defaultThemes[$themeId] : null;
}

/**
 * Load theme assets (CSS and JavaScript)
 * 
 * @param int $themeId The theme ID to load
 * @return array CSS and JavaScript links
 */
function loadThemeAssets($themeId) {
    $theme = getThemeById($themeId);
    
    if (!$theme) {
        // Fall back to default theme
        $theme = getDefaultThemes()[1];
    }
    
    $assets = [
        'css' => [],
        'js' => []
    ];
    
    // Check if it's a default built-in theme
    if (isset($theme['is_default']) && $theme['is_default']) {
        // Use built-in CSS/JS
        return $assets;
    }
    
    // External theme - check if we have a path
    if (!isset($theme['path']) || !is_dir($theme['path'])) {
        return $assets;
    }
    
    $themePath = $theme['path'];
    
    // Add CSS files
    if (file_exists($themePath . '/css')) {
        $cssFiles = glob($themePath . '/css/*.css');
        foreach ($cssFiles as $cssFile) {
            $assets['css'][] = 'themes/' . $theme['directory'] . '/css/' . basename($cssFile);
        }
    }
    
    // Add JavaScript files
    if (file_exists($themePath . '/js')) {
        $jsFiles = glob($themePath . '/js/*.js');
        foreach ($jsFiles as $jsFile) {
            $assets['js'][] = 'themes/' . $theme['directory'] . '/js/' . basename($jsFile);
        }
    }
    
    return $assets;
}

/**
 * Get theme HTML to include in shuffle display
 * 
 * @param int $themeId The theme ID to load
 * @return string HTML content for the theme
 */
function getThemeHtml($themeId) {
    $theme = getThemeById($themeId);
    
    if (!$theme) {
        // Fall back to default theme
        $theme = getDefaultThemes()[1];
    }
    
    // Check if it's a default built-in theme
    if (isset($theme['is_default']) && $theme['is_default']) {
        // Return default theme HTML based on ID
        return getDefaultThemeHtml($themeId);
    }
    
    // External theme - load HTML from theme file
    if (!isset($theme['path'])) {
        return getDefaultThemeHtml(1); // Fallback
    }
    
    $themePath = $theme['path'];
    $htmlFile = $themePath . '/theme.html';
    
    if (file_exists($htmlFile)) {
        return file_get_contents($htmlFile);
    }
    
    // Fallback to default if no HTML file found
    return getDefaultThemeHtml(1);
}

/**
 * Get HTML for default built-in themes
 * 
 * @param int $themeId Default theme ID
 * @return string HTML content for the theme
 */
function getDefaultThemeHtml($themeId) {
    switch ($themeId) {
        case 1: // Spinning Wheel
            return '
                <div class="wheel-animation">
                    <div class="wheel"></div>
                    <div class="wheel-center">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div class="wheel-pointer"></div>
                </div>
            ';
            
        case 2: // Gift Box Opening
            return '
                <div class="gift-box-animation">
                    <div class="gift-box">
                        <div class="gift-box-base"></div>
                        <div class="gift-box-lid"></div>
                        <div class="gift-ribbon"></div>
                    </div>
                </div>
            ';
            
        case 3: // Slot Machine
            return '
                <div class="slot-machine-animation">
                    <div class="slot-reels">
                        <div class="slot-reel">
                            <div class="slot-reel-items">
                                <div class="slot-reel-item">🎁</div>
                                <div class="slot-reel-item">💰</div>
                                <div class="slot-reel-item">🎯</div>
                                <div class="slot-reel-item">🏆</div>
                                <div class="slot-reel-item">💎</div>
                                <div class="slot-reel-item">🎊</div>
                                <div class="slot-reel-item">🎁</div>
                            </div>
                        </div>
                        <div class="slot-reel">
                            <div class="slot-reel-items" style="animation-delay: 0.2s">
                                <div class="slot-reel-item">💎</div>
                                <div class="slot-reel-item">🎁</div>
                                <div class="slot-reel-item">🏆</div>
                                <div class="slot-reel-item">🎯</div>
                                <div class="slot-reel-item">💰</div>
                                <div class="slot-reel-item">🎊</div>
                                <div class="slot-reel-item">💎</div>
                            </div>
                        </div>
                        <div class="slot-reel">
                            <div class="slot-reel-items" style="animation-delay: 0.4s">
                                <div class="slot-reel-item">🏆</div>
                                <div class="slot-reel-item">🎊</div>
                                <div class="slot-reel-item">💰</div>
                                <div class="slot-reel-item">🎁</div>
                                <div class="slot-reel-item">🎯</div>
                                <div class="slot-reel-item">💎</div>
                                <div class="slot-reel-item">🏆</div>
                            </div>
                        </div>
                    </div>
                    <div class="slot-lever"></div>
                </div>
            ';
            
        case 4: // Scratch Card
            return '
                <div class="scratch-card-animation">
                    <div class="scratch-content">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div class="scratch-overlay"></div>
                </div>
            ';
            
        default:
            // Default to wheel if unknown theme
            return getDefaultThemeHtml(1);
    }
}
?>