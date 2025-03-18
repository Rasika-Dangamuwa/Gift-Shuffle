<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Define variables and initialize with empty values
$email = "";
$email_err = "";
$success_msg = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Validate email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter your email.";
    } else {
        $email = sanitizeInput($_POST["email"]);
        // Check if email format is valid
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email_err = "Invalid email format.";
        }
    }
    
    // Check input errors before proceeding
    if (empty($email_err)) {
        try {
            // Check if email exists in database
            $result = executeQuery(
                "SELECT id, username FROM users WHERE email = ?",
                [$email],
                's'
            );
            
            if (count($result) > 0) {
                $user_id = $result[0]['id'];
                $username = $result[0]['username'];
                
                // Generate a unique token
                $token = bin2hex(random_bytes(32));
                $token_hash = password_hash($token, PASSWORD_DEFAULT);
                
                // Set token expiry time (24 hours from now)
                $expiry_time = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                // Delete any existing reset token for this user
                executeQuery(
                    "DELETE FROM password_resets WHERE user_id = ?",
                    [$user_id],
                    'i'
                );
                
                // Store the reset token in the database
                executeQuery(
                    "INSERT INTO password_resets (user_id, token, expiry_time) VALUES (?, ?, ?)",
                    [$user_id, $token_hash, $expiry_time],
                    'iss'
                );
                
                // In a real application, you would send an email with a reset link
                // For now, we'll just show the token on the page for demonstration purposes
                
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
                
                $success_msg = "A password reset link has been sent to your email. Please check your inbox.";
                
                // Log the activity
                logActivity($user_id, "password_reset_request", "Password reset requested for user: $username");
                
                // In a real application, you would send an email here
                // For demonstration, we'll just show the reset link
                // DO NOT include this in a production environment
                $debug_msg = "DEBUG: Your reset link is: " . $reset_link;
            } else {
                // For security reasons, show the same message even if email doesn't exist
                $success_msg = "If your email is registered, a password reset link has been sent. Please check your inbox.";
            }
        } catch (Exception $e) {
            error_log("Error in password reset: " . $e->getMessage());
            $email_err = "An error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Gift Shuffle System</title>
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

        .forgot-container {
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

        .forgot-header {
            padding: 30px;
            text-align: center;
            background: linear-gradient(135deg, #1a73e8, #6c5ce7);
            color: white;
        }

        .forgot-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .forgot-header p {
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .forgot-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .forgot-form {
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

        .debug-info {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #666;
            word-break: break-all;
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

        @media (max-width: 480px) {
            .forgot-container {
                border-radius: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-header">
            <div class="forgot-icon">
                <i class="fas fa-key"></i>
            </div>
            <h1>Forgot Password</h1>
            <p>Enter your email to reset your password</p>
        </div>

        <div class="forgot-form">
            <?php if (!empty($email_err)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $email_err; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_msg; ?>
                </div>
                
                <?php if (isset($debug_msg)): ?>
                    <div class="debug-info">
                        <?php echo $debug_msg; ?>
                    </div>
                <?php endif; ?>
                
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            <?php else: ?>
                <form id="forgotForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" id="email" value="<?php echo $email; ?>" required>
                    </div>

                    <button type="submit" class="reset-btn" id="resetBtn">
                        Reset Password
                        <span class="loading" id="loading"></span>
                    </button>
                </form>
                
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('forgotForm')?.addEventListener('submit', function() {
            document.getElementById('loading').style.display = 'inline-block';
            document.getElementById('resetBtn').textContent = 'Processing...';
            document.getElementById('resetBtn').disabled = true;
        });
    </script>
</body>
</html>