<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
if (!isLoggedIn()) {
    header("location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Gift Shuffle System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1a73e8;
            --primary-hover: #1557b0;
            --error-color: #dc3545;
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

        .access-denied-container {
            background: var(--card-color);
            width: 100%;
            max-width: 500px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            text-align: center;
            padding: 40px;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .error-icon {
            font-size: 5rem;
            color: var(--error-color);
            margin-bottom: 20px;
        }

        h1 {
            color: var(--text-color);
            font-size: 2rem;
            margin-bottom: 15px;
        }

        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
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

        .buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        @media (max-width: 480px) {
            .access-denied-container {
                padding: 30px 20px;
            }
            
            h1 {
                font-size: 1.8rem;
            }
            
            .buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="access-denied-container">
        <div class="error-icon">
            <i class="fas fa-ban"></i>
        </div>
        <h1>Access Denied</h1>
        <p>You don't have permission to access this page. Please contact your administrator if you believe this is a mistake.</p>
        <div class="buttons">
            <?php if ($_SESSION['role'] === 'propagandist'): ?>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-home"></i>
                    Go to Dashboard
                </a>
            <?php elseif ($_SESSION['role'] === 'manager'): ?>
                <a href="manager_dashboard.php" class="btn btn-primary">
                    <i class="fas fa-home"></i>
                    Go to Dashboard
                </a>
            <?php else: ?>
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i>
                    Go to Homepage
                </a>
            <?php endif; ?>
            
            <a href="logout.php" class="btn btn-secondary">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>
</body>
</html>