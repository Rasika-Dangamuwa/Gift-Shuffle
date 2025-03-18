<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Get active gift breakdowns
try {
    $breakdowns = executeQuery(
        "SELECT gb.*, COUNT(bg.id) as total_gifts
         FROM gift_breakdowns gb
         LEFT JOIN breakdown_gifts bg ON gb.id = bg.breakdown_id
         WHERE gb.is_active = TRUE
         GROUP BY gb.id
         ORDER BY gb.name",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting gift breakdowns: " . $e->getMessage());
    $breakdowns = [];
}

// Get available vehicles
try {
    $vehicles = executeQuery(
        "SELECT vehicle_number, vehicle_name FROM vehicles 
         WHERE is_active = TRUE 
         ORDER BY vehicle_name",
        [],
        ''
    );
    
    // If no vehicles table exists, create a fallback array with standard vehicles
    if (empty($vehicles)) {
        $vehicles = [
            ['vehicle_number' => 'LJ-1764', 'vehicle_name' => 'Vehicle 1 (LJ-1764)'],
            ['vehicle_number' => 'KV-7842', 'vehicle_name' => 'Vehicle 2 (KV-7842)'],
            ['vehicle_number' => 'PH-3519', 'vehicle_name' => 'Vehicle 3 (PH-3519)'],
            ['vehicle_number' => 'CB-8024', 'vehicle_name' => 'Vehicle 4 (CB-8024)']
        ];
    }
} catch (Exception $e) {
    error_log("Error getting vehicles: " . $e->getMessage());
    // Fallback if the vehicles table doesn't exist
    $vehicles = [
        ['vehicle_number' => 'LJ-1764', 'vehicle_name' => 'Vehicle 1 (LJ-1764)'],
        ['vehicle_number' => 'KV-7842', 'vehicle_name' => 'Vehicle 2 (KV-7842)'],
        ['vehicle_number' => 'PH-3519', 'vehicle_name' => 'Vehicle 3 (PH-3519)'],
        ['vehicle_number' => 'CB-8024', 'vehicle_name' => 'Vehicle 4 (CB-8024)']
    ];
}

// Get available animation themes
$animation_themes = [
    1 => [
        'name' => 'Spinning Wheel',
        'description' => 'Classic spinning fortune wheel that lands on a prize',
        'preview_image' => 'images/themes/wheel.jpg'
    ],
    2 => [
        'name' => 'Gift Box Opening',
        'description' => 'Animated gift box that opens to reveal the prize',
        'preview_image' => 'images/themes/gift_box.jpg'
    ],
    3 => [
        'name' => 'Slot Machine',
        'description' => 'Slot machine reels that spin and land on the prize',
        'preview_image' => 'images/themes/slot_machine.jpg'
    ],
    4 => [
        'name' => 'Scratch Card',
        'description' => 'Digital scratch card that reveals the prize when scratched',
        'preview_image' => 'images/themes/scratch_card.jpg'
    ]
];

// Initialize variables
$event_name = "";
$vehicle_number = "";
$breakdown_id = "";
$theme_id = 1; // Default to spinning wheel
$collect_customer_info = true; // Default to collecting customer info
$errors = [];

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate event name
    if (empty(trim($_POST["event_name"]))) {
        $errors["event_name"] = "Please enter an event name.";
    } else {
        $event_name = trim($_POST["event_name"]);
    }
    
    // Validate vehicle number
    if (empty(trim($_POST["vehicle_number"]))) {
        $errors["vehicle_number"] = "Please select a vehicle number.";
    } else {
        $vehicle_number = trim($_POST["vehicle_number"]);
        
        // Validate if vehicle number exists in the available vehicles
        $vehicle_exists = false;
        foreach ($vehicles as $vehicle) {
            if ($vehicle['vehicle_number'] === $vehicle_number) {
                $vehicle_exists = true;
                break;
            }
        }
        
        if (!$vehicle_exists) {
            $errors["vehicle_number"] = "Selected vehicle is not available.";
        }
    }
    
    // Validate breakdown selection
    if (empty($_POST["breakdown_id"])) {
        $errors["breakdown_id"] = "Please select a gift breakdown.";
    } else {
        $breakdown_id = $_POST["breakdown_id"];
        
        // Check if the selected breakdown exists and is active
        try {
            $result = executeQuery(
                "SELECT id FROM gift_breakdowns WHERE id = ? AND is_active = TRUE",
                [$breakdown_id],
                'i'
            );
            
            if (count($result) === 0) {
                $errors["breakdown_id"] = "Selected breakdown is not available.";
            }
        } catch (Exception $e) {
            error_log("Error verifying breakdown: " . $e->getMessage());
            $errors["general"] = "An error occurred. Please try again.";
        }
    }
    
    // Get animation theme
    if (isset($_POST["theme_id"]) && array_key_exists((int)$_POST["theme_id"], $animation_themes)) {
        $theme_id = (int)$_POST["theme_id"];
    }
    
    // Get customer info collection preference
    $collect_customer_info = isset($_POST["collect_customer_info"]) && $_POST["collect_customer_info"] == "1";
    
    // If no errors, create the session
    if (empty($errors)) {
        try {
            // Generate unique access code
            $access_code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
            
            // Set current date
            $session_date = date('Y-m-d');
            
            // Begin transaction
            $conn = getConnection();
            $conn->begin_transaction();
            
            // Insert new session
            $insert_session = $conn->prepare(
                "INSERT INTO shuffle_sessions (
                    event_name, breakdown_id, vehicle_number, 
                    session_date, start_time, access_code, 
                    status, created_by, theme_id, collect_customer_info
                ) VALUES (?, ?, ?, ?, NOW(), ?, 'active', ?, ?, ?)"
            );
            
            $collect_info_db = $collect_customer_info ? 1 : 0;
            
            $insert_session->bind_param(
                "sisssiii",
                $event_name,
                $breakdown_id,
                $vehicle_number,
                $session_date,
                $access_code,
                $_SESSION["id"],
                $theme_id,
                $collect_info_db
            );
            
            $insert_session->execute();
            $session_id = $conn->insert_id;
            
            // Commit transaction
            $conn->commit();
            
            // Log activity
            logActivity(
                $_SESSION["id"],
                "create_session",
                "Created new gift shuffle session: {$event_name} (ID: {$session_id})"
            );
            
            // Redirect to session control page
            $_SESSION["success_message"] = "Session created successfully! Access code: {$access_code}";
            header("location: session_control.php?id={$session_id}");
            exit;
        } catch (Exception $e) {
            // Rollback transaction on error
            if (isset($conn)) {
                $conn->rollback();
            }
            
            error_log("Error creating session: " . $e->getMessage());
            $errors["general"] = "An error occurred while creating the session. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Session - Gift Shuffle System</title>
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
            max-width: 800px;
            margin: 80px auto 30px;
            padding: 0 20px;
        }

        /* Card */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #1a73e8, #6c5ce7);
            color: white;
            padding: 20px;
        }

        .card-header h1 {
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .card-body {
            padding: 30px;
        }

        /* Form styles */
        .form-group {
            margin-bottom: 25px;
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

        .form-control.is-invalid {
            border-color: var(--danger-color);
        }

        .invalid-feedback {
            color: var(--danger-color);
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .form-text {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-top: 5px;
        }

        /* Alert */
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

        /* Breakdown cards */
        .breakdown-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .breakdown-card {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .breakdown-card:hover {
            border-color: var(--primary-color);
            background-color: #f8f9fa;
        }

        .breakdown-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(26, 115, 232, 0.05);
        }

        .breakdown-radio {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .breakdown-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .breakdown-name i {
            color: var(--primary-color);
        }

        .breakdown-details {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .breakdown-detail {
            display: flex;
            gap: 5px;
            margin-bottom: 3px;
        }

        /* Theme selection */
        .section-title {
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .theme-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .theme-card {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .theme-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .theme-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(26, 115, 232, 0.05);
        }

        .theme-radio {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .theme-preview {
            width: 100%;
            height: 120px;
            background-color: #f8f9fa;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .theme-info {
            padding: 12px;
        }

        .theme-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .theme-description {
            font-size: 0.8rem;
            color: var(--text-secondary);
            height: 40px;
            overflow: hidden;
        }

        .theme-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .theme-card.selected .theme-badge {
            opacity: 1;
        }

        /* Customer info toggle */
        .customer-info-toggle {
            margin-top: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .form-check-label {
            cursor: pointer;
            user-select: none;
            font-size: 0.95rem;
        }

        .customer-info-hint {
            margin-top: 8px;
            padding-left: 28px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Buttons */
        .btn-container {
            display: flex;
            justify-content: space-between;
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
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #f1f3f4;
            color: var(--text-color);
            border: none;
        }

        .btn-secondary:hover {
            background: #e2e6ea;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .empty-state p {
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

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }
            
            .container {
                padding: 0 15px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .breakdown-options {
                grid-template-columns: 1fr;
            }
            
            .theme-options {
                grid-template-columns: 1fr;
            }
            
            .btn-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .btn {
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
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h1>Start New Gift Shuffle Session</h1>
                <p>Create a new session to begin distributing gifts to winners.</p>
            </div>
            <div class="card-body">
                <?php if (isset($errors["general"])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $errors["general"]; ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($breakdowns)): ?>
                    <!-- No breakdowns available -->
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <h3>No Gift Breakdowns Available</h3>
                        <p>You need to create a gift breakdown before starting a session.</p>
                        <a href="create_breakdown.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i>
                            Create Breakdown
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Create session form -->
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="sessionForm">
                        <div class="form-group">
                            <label for="event_name">Event Name*</label>
                            <input type="text" name="event_name" id="event_name" class="form-control <?php echo isset($errors["event_name"]) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($event_name); ?>" required>
                            <?php if (isset($errors["event_name"])): ?>
                                <div class="invalid-feedback"><?php echo $errors["event_name"]; ?></div>
                            <?php endif; ?>
                            <div class="form-text">Enter a name for this event (e.g., "Town Event - Colombo", "Market Promotion")</div>
                        </div>

                        <div class="form-group">
                            <label for="vehicle_number">Vehicle Number*</label>
                            <select name="vehicle_number" id="vehicle_number" class="form-control <?php echo isset($errors["vehicle_number"]) ? 'is-invalid' : ''; ?>" required>
                                <option value="">-- Select Vehicle --</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle['vehicle_number']); ?>" <?php echo $vehicle_number === $vehicle['vehicle_number'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_name'] ?? $vehicle['vehicle_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors["vehicle_number"])): ?>
                                <div class="invalid-feedback"><?php echo $errors["vehicle_number"]; ?></div>
                            <?php endif; ?>
                            <div class="form-text">Select the vehicle or location identifier for this session</div>
                        </div>

                        <div class="form-group">
                            <label>Select Gift Breakdown*</label>
                            <?php if (isset($errors["breakdown_id"])): ?>
                                <div class="invalid-feedback"><?php echo $errors["breakdown_id"]; ?></div>
                            <?php endif; ?>
                            
                            <div class="breakdown-options">
                                <?php foreach ($breakdowns as $breakdown): ?>
                                    <label class="breakdown-card <?php echo $breakdown_id == $breakdown['id'] ? 'selected' : ''; ?>">
                                        <input type="radio" name="breakdown_id" value="<?php echo $breakdown['id']; ?>" class="breakdown-radio" 
                                            <?php echo $breakdown_id == $breakdown['id'] ? 'checked' : ''; ?>>
                                        <div class="breakdown-name">
                                            <i class="fas fa-gift"></i>
                                            <?php echo htmlspecialchars($breakdown['name']); ?>
                                        </div>
                                        <div class="breakdown-details">
                                            <div class="breakdown-detail">
                                                <i class="fas fa-cubes"></i>
                                                <span>Total Gifts: <?php echo number_format($breakdown['total_number']); ?></span>
                                            </div>
                                            <div class="breakdown-detail">
                                                <i class="fas fa-box"></i>
                                                <span>Gift Types: <?php echo number_format($breakdown['total_gifts']); ?></span>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Animation Theme Selection -->
                        <div class="form-group">
                            <h3 class="section-title">
                                <i class="fas fa-palette"></i>
                                Select Animation Theme
                            </h3>
                            <div class="form-text" style="margin-bottom: 10px;">
                                Choose how gifts will be revealed to customers on the display screen.
                            </div>
                            
                            <div class="theme-options">
                                <?php foreach ($animation_themes as $id => $theme): ?>
                                    <label class="theme-card <?php echo $theme_id == $id ? 'selected' : ''; ?>">
                                        <input type="radio" name="theme_id" value="<?php echo $id; ?>" class="theme-radio" 
                                            <?php echo $theme_id == $id ? 'checked' : ''; ?>>
                                        <div class="theme-badge">
                                            <i class="fas fa-check"></i> Selected
                                        </div>
                                        <div class="theme-preview" style="background-image: url('<?php echo htmlspecialchars($theme['preview_image']); ?>')"></div>
                                        <div class="theme-info">
                                            <div class="theme-name"><?php echo htmlspecialchars($theme['name']); ?></div>
                                            <div class="theme-description"><?php echo htmlspecialchars($theme['description']); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Customer Information Collection -->
                        <div class="customer-info-toggle">
                            <div class="form-check">
                                <input type="checkbox" id="collect_customer_info" name="collect_customer_info" value="1" class="form-check-input" 
                                    <?php echo $collect_customer_info ? 'checked' : ''; ?>>
                                <label for="collect_customer_info" class="form-check-label">
                                    Collect Customer Information
                                </label>
                            </div>
                            <div class="customer-info-hint">
                                When enabled, customers will be asked to provide their name, NIC number, and phone before playing.
                            </div>
                        </div>

                        <div class="btn-container">
                        <a href="<?php echo $_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'; ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-play"></i>
                                Start Session
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle breakdown selection
            const breakdownCards = document.querySelectorAll('.breakdown-card');
            const breakdownRadios = document.querySelectorAll('.breakdown-radio');
            
            breakdownCards.forEach(card => {
                card.addEventListener('click', function() {
                    // Find the radio input inside this card
                    const radio = this.querySelector('.breakdown-radio');
                    radio.checked = true;
                    
                    // Update selected class on all cards
                    breakdownCards.forEach(c => {
                        c.classList.remove('selected');
                    });
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                });
            });
            
            // Handle theme selection
            const themeCards = document.querySelectorAll('.theme-card');
            const themeRadios = document.querySelectorAll('.theme-radio');
            
            themeCards.forEach(card => {
                card.addEventListener('click', function() {
                    // Find the radio input inside this card
                    const radio = this.querySelector('.theme-radio');
                    radio.checked = true;
                    
                    // Update selected class on all cards
                    themeCards.forEach(c => {
                        c.classList.remove('selected');
                    });
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                });
            });
        });
    </script>
</body>
</html>