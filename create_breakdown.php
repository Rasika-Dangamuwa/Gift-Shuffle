<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Get all available gifts
try {
    $gifts = executeQuery(
        "SELECT * FROM gifts WHERE is_active = TRUE ORDER BY name",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting gifts: " . $e->getMessage());
    $gifts = [];
}

// Initialize variables to store form data
$name = "";
$total_number = "";
$gift_quantities = [];
$is_active = true;

// Error messages
$name_err = "";
$total_number_err = "";
$gifts_err = "";
$general_err = "";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate name
    if (empty(trim($_POST["name"]))) {
        $name_err = "Please enter a name for the breakdown.";
    } else {
        $name = trim($_POST["name"]);
        
        // Check if name already exists
        try {
            $result = executeQuery(
                "SELECT id FROM gift_breakdowns WHERE name = ?",
                [$name],
                's'
            );
            
            if (count($result) > 0) {
                $name_err = "This breakdown name already exists.";
            }
        } catch (Exception $e) {
            error_log("Error checking breakdown name: " . $e->getMessage());
            $general_err = "An error occurred. Please try again.";
        }
    }
    
    // Validate total number
    if (empty(trim($_POST["total_number"]))) {
        $total_number_err = "Please enter the total number.";
    } else {
        $total_number = (int)trim($_POST["total_number"]);
        if ($total_number <= 0) {
            $total_number_err = "Total number must be greater than zero.";
        }
    }
    
    // Validate gift quantities
    $total_gifts = 0;
    $gift_quantities = [];
    
    if (isset($_POST["gift_quantities"]) && is_array($_POST["gift_quantities"])) {
        foreach ($_POST["gift_quantities"] as $gift_id => $quantity) {
            $quantity = (int)$quantity;
            if ($quantity > 0) {
                $gift_quantities[$gift_id] = $quantity;
                $total_gifts += $quantity;
            }
        }
    }
    
    if (empty($gift_quantities)) {
        $gifts_err = "Please specify at least one gift with a quantity greater than zero.";
    } elseif ($total_gifts !== $total_number) {
        $gifts_err = "The sum of gift quantities ({$total_gifts}) must equal the total number ({$total_number}).";
    }
    
    // Set active status
    $is_active = isset($_POST["is_active"]) && $_POST["is_active"] === "1";
    
    // If no errors, create the breakdown
    if (empty($name_err) && empty($total_number_err) && empty($gifts_err) && empty($general_err)) {
        try {
            // Begin transaction
            $conn = getConnection();
            $conn->begin_transaction();
            
            // Insert breakdown
            $insert_breakdown = $conn->prepare(
                "INSERT INTO gift_breakdowns (name, total_number, is_active, created_by) 
                 VALUES (?, ?, ?, ?)"
            );
            
            $insert_breakdown->bind_param("siis", $name, $total_number, $is_active, $_SESSION["id"]);
            $insert_breakdown->execute();
            
            $breakdown_id = $conn->insert_id;
            
            // Insert gift breakdowns
            $insert_gift = $conn->prepare(
                "INSERT INTO breakdown_gifts (breakdown_id, gift_id, quantity) 
                 VALUES (?, ?, ?)"
            );
            
            foreach ($gift_quantities as $gift_id => $quantity) {
                $insert_gift->bind_param("iii", $breakdown_id, $gift_id, $quantity);
                $insert_gift->execute();
            }
            
            // Commit transaction
            $conn->commit();
            
            // Log activity
            logActivity($_SESSION["id"], "create_breakdown", "Created new gift breakdown: {$name}");
            
            // Set success message and redirect
            $_SESSION["success_message"] = "Gift breakdown created successfully!";
            header("location: gift_breakdowns.php");
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            if (isset($conn)) {
                $conn->rollback();
            }
            
            error_log("Error creating gift breakdown: " . $e->getMessage());
            $general_err = "An error occurred while creating the breakdown. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Gift Breakdown - Gift Shuffle System</title>
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
            max-width: 900px;
            margin: 80px auto 30px;
            padding: 0 20px;
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
            font-size: 1.5rem;
            font-weight: 600;
        }

        .card-body {
            padding: 30px;
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

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
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
        }

        /* Gift list section */
        .gifts-section {
            margin-top: 30px;
            border-top: 1px solid var(--border-color);
            padding-top: 30px;
        }

        .gifts-section h3 {
            font-size: 1.3rem;
            margin-bottom: 20px;
            color: var(--text-color);
        }

        .gift-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .gift-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .gift-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .gift-info {
            flex-grow: 1;
        }

        .gift-name {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-color);
        }

        .gift-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .gift-input {
            width: 80px;
            padding: 8px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            text-align: center;
        }

        .gift-input:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        /* Submit button */
        .btn-container {
            margin-top: 30px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
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

        /* Summary section */
        .summary-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .summary-title {
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-list {
            margin-top: 15px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .summary-item:last-child {
            border-bottom: none;
            font-weight: 600;
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
            
            .card-body {
                padding: 20px;
            }
            
            .gift-list {
                grid-template-columns: 1fr;
            }
            
            .btn-container {
                flex-direction: column;
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
            <a href="gift_breakdowns.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Breakdowns
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="card">
            <div class="card-header">
                Create New Gift Breakdown
            </div>
            <div class="card-body">
                <?php if (!empty($general_err)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $general_err; ?>
                    </div>
                <?php endif; ?>

                <form id="breakdownForm" method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="form-group">
                        <label for="name">Breakdown Name*</label>
                        <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $name; ?>" required>
                        <?php if (!empty($name_err)): ?>
                            <div class="invalid-feedback"><?php echo $name_err; ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            For example: "Standard 50", "Premium 100", "Event Special"
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="total_number">Total Number*</label>
                        <input type="number" name="total_number" id="total_number" class="form-control <?php echo (!empty($total_number_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $total_number; ?>" min="1" required>
                        <?php if (!empty($total_number_err)): ?>
                            <div class="invalid-feedback"><?php echo $total_number_err; ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            The total number of gifts for this breakdown (e.g., 50, 100, 200)
                        </div>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?php echo $is_active ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">
                            Active (available for new sessions)
                        </label>
                    </div>

                    <div class="gifts-section">
                        <h3>
                            <i class="fas fa-boxes"></i>
                            Gift Distribution
                        </h3>
                        
                        <?php if (empty($gifts)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                No gifts found in the system. Please add gifts before creating a breakdown.
                            </div>
                        <?php else: ?>
                            <?php if (!empty($gifts_err)): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?php echo $gifts_err; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-text" style="margin-bottom: 15px;">
                                Specify the quantity for each gift type. The sum must equal the total number (
                                <span id="totalNumberDisplay"><?php echo $total_number ?: 0; ?></span>).
                                Current sum: <span id="currentSum">0</span>
                            </div>
                            
                            <div class="gift-list">
                                <?php foreach ($gifts as $gift): ?>
                                    <div class="gift-item">
                                        <div class="gift-icon">
                                            <i class="fas fa-gift"></i>
                                        </div>
                                        <div class="gift-info">
                                            <div class="gift-name"><?php echo htmlspecialchars($gift['name']); ?></div>
                                            <div class="gift-description">
                                                <?php echo htmlspecialchars(substr($gift['description'] ?? '', 0, 50)); ?>
                                                <?php if (strlen($gift['description'] ?? '') > 50): ?>...<?php endif; ?>
                                            </div>
                                        </div>
                                        <input type="number" 
                                            name="gift_quantities[<?php echo $gift['id']; ?>]" 
                                            class="gift-input" 
                                            value="<?php echo $gift_quantities[$gift['id']] ?? 0; ?>" 
                                            min="0" 
                                            data-gift-id="<?php echo $gift['id']; ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="summary-section">
                                <div class="summary-title">
                                    <i class="fas fa-calculator"></i>
                                    Distribution Summary
                                </div>
                                <div id="summaryList" class="summary-list">
                                    <!-- Summary items will be inserted here via JavaScript -->
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="btn-container">
                        <a href="gift_breakdowns.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <?php if (!empty($gifts)): ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                Create Breakdown
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>&copy; <?php echo date('Y'); ?> Nestlé Lanka Gift Shuffle System. All rights reserved.</p>
    </div>

    <script>
        // Gift quantity calculation
        document.addEventListener('DOMContentLoaded', function() {
            const totalNumberInput = document.getElementById('total_number');
            const totalNumberDisplay = document.getElementById('totalNumberDisplay');
            const currentSumDisplay = document.getElementById('currentSum');
            const summaryList = document.getElementById('summaryList');
            const giftInputs = document.querySelectorAll('.gift-input');
            const giftNames = {};
            
            // Store gift names for summary
            document.querySelectorAll('.gift-item').forEach(item => {
                const giftId = item.querySelector('.gift-input').dataset.giftId;
                const giftName = item.querySelector('.gift-name').textContent;
                giftNames[giftId] = giftName;
            });
            
            // Update total number display when input changes
            totalNumberInput?.addEventListener('input', function() {
                totalNumberDisplay.textContent = this.value || 0;
                updateSummary();
            });
            
            // Update current sum when any gift quantity changes
            giftInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Ensure non-negative values
                    if (this.value < 0) {
                        this.value = 0;
                    }
                    
                    updateSummary();
                });
            });
            
            // Function to update summary
            function updateSummary() {
                let currentSum = 0;
                const giftQuantities = {};
                
                // Calculate sum and collect quantities
                giftInputs.forEach(input => {
                    const quantity = parseInt(input.value) || 0;
                    const giftId = input.dataset.giftId;
                    
                    currentSum += quantity;
                    
                    if (quantity > 0) {
                        giftQuantities[giftId] = quantity;
                    }
                });
                
                // Update current sum display
                currentSumDisplay.textContent = currentSum;
                
                // Check if sum matches total
                const totalNumber = parseInt(totalNumberInput.value) || 0;
                if (currentSum !== totalNumber) {
                    currentSumDisplay.style.color = 'red';
                } else {
                    currentSumDisplay.style.color = 'green';
                }
                
                // Build summary HTML
                let summaryHTML = '';
                
                // Add items for each gift with quantity > 0
                Object.keys(giftQuantities).forEach(giftId => {
                    summaryHTML += `
                        <div class="summary-item">
                            <span>${giftNames[giftId]}</span>
                            <span>${giftQuantities[giftId]}</span>
                        </div>
                    `;
                });
                
                // Add total row
                summaryHTML += `
                    <div class="summary-item">
                        <span>Total</span>
                        <span>${currentSum} / ${totalNumber}</span>
                    </div>
                `;
                
                // Update summary list
                summaryList.innerHTML = summaryHTML;
            }
            
            // Initialize summary on page load
            updateSummary();
        });
    </script>
</body>
</html>