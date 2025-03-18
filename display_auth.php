<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Initialize variables
$error_message = "";
$access_code = "";

// Check if there's a requested display code in session
if (isset($_SESSION['requested_display_code'])) {
    $access_code = $_SESSION['requested_display_code'];
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $access_code = trim($_POST['access_code']);
    $password = trim($_POST['password']);
    
    // Basic validation
    if (empty($access_code)) {
        $error_message = "Access code is required";
    } elseif (empty($password)) {
        $error_message = "Password is required";
    } else {
        try {
            // Check if access code exists and session is active
            $session = executeQuery(
                "SELECT ss.id, ss.access_code 
                 FROM shuffle_sessions ss
                 WHERE ss.access_code = ? AND ss.status = 'active'",
                [$access_code],
                's'
            );
            
            if (empty($session)) {
                $error_message = "Invalid access code or session not active";
            } else {
                // Validate the display password against system setting
                $setting = executeQuery(
                    "SELECT setting_value FROM system_settings WHERE setting_name = 'display_password'",
                    [],
                    ''
                );
                
                $session_id = $session[0]['id'];
                
                // Check if there's a system setting for display password
                if (!empty($setting)) {
                    // Use direct comparison for display password
                    // This is simpler and more reliable than password_verify for this case
                    $valid_password = $setting[0]['setting_value'];
                    $password_match = ($password === $valid_password);
                } else {
                    // Fallback to default password if no setting exists
                    $valid_password = "shuffle123"; 
                    $password_match = ($password === $valid_password);
                }
                
                if ($password_match) {
                    // Generate a temporary display token
                    $token = bin2hex(random_bytes(16));
                    
                    // Store token in session
                    $_SESSION['display_token'] = $token;
                    $_SESSION['display_session_id'] = $session_id;
                    
                    // Log this authorization
                    logActivity(
                        0, // System action
                        "display_auth",
                        "Display authenticated for session (ID: {$session_id})"
                    );
                    
                    // Redirect to the display page
                    header("Location: shuffle_display.php?code=" . $access_code);
                    exit;
                } else {
                    $error_message = "Invalid password";
                }
            }
        } catch (Exception $e) {
            error_log("Error in display authentication: " . $e->getMessage());
            $error_message = "An error occurred during authentication. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Display Authentication - Gift Shuffle System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a73e8;
            --primary-hover: #1557b0;
            --secondary-color: #6c5ce7;
            --error-color: #dc3545;
            --background-color: #f5f9ff;
            --card-color: #ffffff;
            --text-color: #333333;
            --text-secondary: #6c757d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--background-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            background-color: var(--card-color);
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .auth-header {
            background: linear-gradient(135deg, #1a73e8, #6c5ce7);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .auth-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .auth-title {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .auth-subtitle {
            opacity: 0.85;
            font-size: 0.95rem;
        }

        .auth-form {
            padding: 30px;
        }

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
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-hint {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        .auth-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 1rem;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .auth-btn:hover {
            background-color: var(--primary-hover);
        }

        .auth-btn:disabled {
            background-color: #b0b0b0;
            cursor: not-allowed;
        }

        .error-message {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--error-color);
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-link {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .login-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            margin-left: 5px;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 500px) {
            .auth-container {
                border-radius: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <div class="auth-icon">
                <i class="fas fa-tv"></i>
            </div>
            <h1 class="auth-title">Display Authentication</h1>
            <p class="auth-subtitle">Enter password to access shuffle display</p>
        </div>
        
        <div class="auth-form">
            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="form-group">
                    <label for="access_code">Access Code</label>
                    <input 
                        type="text" 
                        id="access_code" 
                        name="access_code" 
                        class="form-control" 
                        placeholder="Enter shuffle access code" 
                        value="<?php echo htmlspecialchars($access_code); ?>" 
                        required
                    >
                    <div class="form-hint">The 6-character code from the control panel</div>
                </div>
                
                <div class="form-group">
                    <label for="password">Display Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Enter display password" 
                        required
                    >
                    <div class="form-hint">Ask your administrator for the display password</div>
                </div>
                
                <button type="submit" class="auth-btn" id="authButton">
                    <i class="fas fa-unlock-alt"></i>
                    Authenticate Display
                </button>
            </form>
            
            <div class="login-link">
                <span>Or</span>
                <a href="index.php">Login to your account</a>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const authButton = document.getElementById('authButton');
            
            form.addEventListener('submit', function() {
                // Disable button and show loading state
                authButton.disabled = true;
                authButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Authenticating...';
            });
        });
    </script>
</body>
</html>