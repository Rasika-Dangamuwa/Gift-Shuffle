<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Define variables and initialize with empty values
$new_password = $confirm_password = "";
$new_password_err = $confirm_password_err = $token_err = "";
$token = "";
$success = false;

// Check if token is provided in the URL
if (empty($_GET["token"])) {
    $token_err = "Invalid password reset link.";
} else {
    $token = $_GET["token"];
    
    // Process form data when form is submitted
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        
        // Validate new password
        if (empty(trim($_POST["new_password"]))) {
            $new_password_err = "Please enter the new password.";
        } elseif (strlen(trim($_POST["new_password"])) < 8) {
            $new_password_err = "Password must have at least 8 characters.";
        } else {
            $new_password = trim($_POST["new_password"]);
        }
        
        // Validate confirm password
        if (empty(trim($_POST["confirm_password"]))) {
            $confirm_password_err = "Please confirm the password.";
        } else {
            $confirm_password = trim($_POST["confirm_password"]);
            if (empty($new_password_err) && ($new_password != $confirm_password)) {
                $confirm_password_err = "Passwords did not match.";
            }
        }
        
        // Check input errors before updating the password
        if (empty($new_password_err) && empty($confirm_password_err) && empty($token_err)) {
            try {
                // Get current time for comparison with expiry time
                $current_time = date('Y-m-d H:i:s');
                
                // Fetch token record from database
                $token_records = executeQuery(
                    "SELECT pr.id, pr.user_id, pr.token, pr.used, u.username 
                     FROM password_resets pr 
                     JOIN users u ON pr.user_id = u.id 
                     WHERE pr.expiry_time > ? AND pr.used = 0 
                     ORDER BY pr.created_at DESC",
                    [$current_time],
                    's'
                );
                
                $valid_token = false;
                $user_id = null;
                $username = null;
                $reset_id = null;
                
                // Check all non-expired tokens
                foreach ($token_records as $record) {
                    if (password_verify($token, $record['token'])) {
                        $valid_token = true;
                        $user_id = $record['user_id'];
                        $username = $record['username'];
                        $reset_id = $record['id'];
                        break;
                    }
                }
                
                if ($valid_token) {
                    // Hash the new password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update user's password
                    executeQuery(
                        "UPDATE users SET password = ? WHERE id = ?",
                        [$hashed_password, $user_id],
                        'si'
                    );
                    
                    // Mark the token as used
                    executeQuery(
                        "UPDATE password_resets SET used = 1 WHERE id = ?",
                        [$reset_id],
                        'i'
                    );
                    
                    // Log the activity
                    logActivity($user_id, "password_reset", "Password reset successful for user: $username");
                    
                    $success = true;
                } else {
                    $token_err = "The password reset link is invalid or has expired.";
                }
            } catch (Exception $e) {
                error_log("Error in password reset: " . $e->getMessage());
                $token_err = "An error occurred. Please try again later.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Gift Shuffle System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a73e8;
            --primary-hover: #1557b0;
            --error-color: #dc3545;
            --success-color: #28a745;
            --background-color: #f5f9ff;
            --card-color: #ffffff;
            --text-color: #333333;
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .reset-container {
            background: var(--card-color);
            width: 100%;
            max-width: 400px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .reset-header {
            padding: 30px;
            text-align: center;
            background: linear-gradient(135deg, #1a73e8, #6c5ce7);
            color: white;
        }

        .reset-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .reset-header p {
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .reset-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .reset-form {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            color: var(--text-color);
        }

        .form-group input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-group i {
            position: absolute;
            left: 15px;
            top: 41px;
            color: #999;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background-color: #ffebed;
            color: var(--error-color);
            border: 1px solid #ffd0d4;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: var(--success-color);
            border: 1px solid #c8e6c9;
        }

        .reset-btn {
            width: 100%;
            padding: 12px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .reset-btn:hover {
            background: var(--primary-hover);
        }

        .loading {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .back-link {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            margin-top: 20px;
            transition: color 0.3s ease;
        }

        .back-link:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .password-strength {
            height: 5px;
            margin-top: 10px;
            border-radius: 5px;
            background: #e0e0e0;
            overflow: hidden;
        }

        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-text {
            font-size: 0.8rem;
            margin-top: 5px;
            display: none;
        }

        @media (max-width: 480px) {
            .reset-container {
                border-radius: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-header">
            <div class="reset-icon">
                <i class="fas fa-lock-open"></i>
            </div>
            <h1>Reset Password</h1>
            <p>Create a new password for your account</p>
        </div>

        <div class="reset-form">
            <?php if (!empty($token_err)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $token_err; ?>
                </div>
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Your password has been reset successfully.
                </div>
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            <?php else: ?>
                <?php if (!empty($new_password_err) || !empty($confirm_password_err)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo !empty($new_password_err) ? $new_password_err : $confirm_password_err; ?>
                    </div>
                <?php endif; ?>

                <form id="resetForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?token=' . $token); ?>" method="post">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <i class="fas fa-lock"></i>
                        <input type="password" name="new_password" id="new_password" required>
                        <div class="password-strength">
                            <div class="password-strength-meter" id="passwordStrengthMeter"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <i class="fas fa-lock"></i>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>

                    <button type="submit" class="reset-btn" id="resetBtn">
                        Set New Password
                        <span class="loading" id="loading"></span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const resetForm = document.getElementById('resetForm');
        const passwordInput = document.getElementById('new_password');
        const confirmInput = document.getElementById('confirm_password');
        const strengthMeter = document.getElementById('passwordStrengthMeter');
        const strengthText = document.getElementById('strengthText');

        // Password strength checker
        passwordInput?.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let feedback = '';

            if (password.length > 0) {
                // Length check
                if (password.length >= 8) strength += 25;
                
                // Contains lowercase letter
                if (/[a-z]/.test(password)) strength += 25;
                
                // Contains uppercase letter
                if (/[A-Z]/.test(password)) strength += 25;
                
                // Contains number
                if (/[0-9]/.test(password)) strength += 25;
                
                // Contains special character
                if (/[^A-Za-z0-9]/.test(password)) strength += 25;
                
                // Cap at 100
                strength = Math.min(strength, 100);
            }

            // Update the strength meter
            strengthMeter.style.width = strength + '%';
            
            // Set color based on strength
            if (strength < 25) {
                strengthMeter.style.backgroundColor = '#ff4949';
                feedback = 'Very weak password';
            } else if (strength < 50) {
                strengthMeter.style.backgroundColor = '#fd7e14';
                feedback = 'Weak password';
            } else if (strength < 75) {
                strengthMeter.style.backgroundColor = '#ffc107';
                feedback = 'Moderate password';
            } else {
                strengthMeter.style.backgroundColor = '#28a745';
                feedback = 'Strong password';
            }
            
            // Show strength text
            strengthText.textContent = feedback;
            strengthText.style.display = password.length > 0 ? 'block' : 'none';
        });

        // Password matching check
        confirmInput?.addEventListener('input', function() {
            if (this.value && passwordInput.value) {
                if (this.value !== passwordInput.value) {
                    this.style.borderColor = '#dc3545';
                } else {
                    this.style.borderColor = '#28a745';
                }
            } else {
                this.style.borderColor = '#e1e1e1';
            }
        });

        // Form submission
        resetForm?.addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'inline-block';
            document.getElementById('resetBtn').textContent = 'Processing...';
            document.getElementById('resetBtn').disabled = true;
        });
    </script>
</body>
</html>