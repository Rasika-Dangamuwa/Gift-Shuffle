<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Check if user has appropriate permissions
if ($_SESSION["role"] !== "manager") {
    header("location: access_denied.php");
    exit;
}

// Check if gift ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No gift ID provided.";
    header("location: add_gift.php");
    exit;
}

$gift_id = (int)$_GET['id'];

// Initialize variables
$name = "";
$description = "";
$image_url = "";
$is_active = 1;
$errors = [];
$success_message = "";

// Get gift details
try {
    $gift = executeQuery(
        "SELECT * FROM gifts WHERE id = ?",
        [$gift_id],
        'i'
    );
    
    if (empty($gift)) {
        $_SESSION['error_message'] = "Gift not found.";
        header("location: add_gift.php");
        exit;
    }
    
    $gift = $gift[0];
    $name = $gift['name'];
    $description = $gift['description'];
    $image_url = $gift['image_url'];
    $is_active = $gift['is_active'];
} catch (Exception $e) {
    error_log("Error getting gift details: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while retrieving gift details.";
    header("location: add_gift.php");
    exit;
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate name
    if (empty(trim($_POST["name"]))) {
        $errors["name"] = "Please enter a gift name.";
    } else {
        $name = trim($_POST["name"]);
    }
    
    // Description is optional
    $description = trim($_POST["description"] ?? "");
    
    // Check if is_active is set
    $is_active = isset($_POST["is_active"]) ? 1 : 0;
    
    // Handle image upload
    $new_image_url = $image_url; // Default to existing image
    if (isset($_FILES['gift_image']) && $_FILES['gift_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/png', 'image/jpeg', 'image/jpg'];
        $file_type = $_FILES['gift_image']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors["image"] = "Only PNG and JPEG images are allowed.";
        } else {
            // Create uploads directory if it doesn't exist
            $upload_dir = 'uploads/gifts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate a unique filename
            $file_extension = pathinfo($_FILES['gift_image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('gift_') . '.' . $file_extension;
            $target_file = $upload_dir . $filename;
            
            // Move the uploaded file
            if (move_uploaded_file($_FILES['gift_image']['tmp_name'], $target_file)) {
                $new_image_url = $target_file;
                
                // Delete old image if it exists and is not the default
                if (!empty($image_url) && file_exists($image_url) && $image_url != 'uploads/gifts/default.png') {
                    unlink($image_url);
                }
            } else {
                $errors["image"] = "Failed to upload image. Please try again.";
            }
        }
    }
    
    // If no errors, update gift in database
    if (empty($errors)) {
        try {
            // Begin transaction
            $conn = getConnection();
            $conn->begin_transaction();
            
            // Update gift
            $stmt = $conn->prepare("UPDATE gifts SET name = ?, description = ?, image_url = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssii", $name, $description, $new_image_url, $is_active, $gift_id);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Log activity
            logActivity($_SESSION["id"], "update_gift", "Updated gift: {$name}");
            
            // Set success message
            $success_message = "Gift updated successfully!";
            
            // Update local variables
            $image_url = $new_image_url;
        } catch (Exception $e) {
            // Rollback on error
            if (isset($conn)) {
                $conn->rollback();
            }
            
            error_log("Error updating gift: " . $e->getMessage());
            $errors["general"] = "An error occurred while updating the gift. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Gift - Gift Shuffle System</title>
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

        /* Form styles */
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

        .invalid-feedback {
            color: var(--danger-color);
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

        /* File upload area */
        .file-upload {
            border: 2px dashed var(--border-color);
            padding: 30px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .file-upload:hover {
            border-color: var(--primary-color);
        }

        .file-upload i {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .file-upload h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .file-upload p {
            color: var(--text-secondary);
            margin-bottom: 0;
        }

        .preview-area {
            margin-top: 15px;
            text-align: center;
        }

        .preview-area img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        /* Button styles */
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
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: #f1f3f4;
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background-color: #e2e6ea;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
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
        <a href="<?php echo $_SESSION['role'] === 'manager' ? 'manager_dashboard.php' : 'dashboard.php'; ?>" class="logo">
            <i class="fas fa-gift"></i>
            <span>Gift Shuffle</span>
        </a>
        <div class="user-menu">
            <a href="gifts.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Gifts
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Edit Gift</h1>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errors["general"])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $errors["general"]; ?>
            </div>
        <?php endif; ?>

        <!-- Edit Gift Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-edit"></i>
                Edit Gift: <?php echo htmlspecialchars($name); ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $gift_id); ?>" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="name">Gift Name*</label>
                        <input type="text" id="name" name="name" class="form-control <?php echo isset($errors["name"]) ? 'is-invalid' : ''; ?>" 
                               value="<?php echo htmlspecialchars($name); ?>" required>
                        <?php if (isset($errors["name"])): ?>
                            <div class="invalid-feedback"><?php echo $errors["name"]; ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="4"><?php echo htmlspecialchars($description); ?></textarea>
                        <div class="form-text">Provide a brief description of the gift (optional).</div>
                    </div>

                    <div class="form-group">
                        <label>Gift Image (PNG recommended)</label>
                        <div class="file-upload" id="dropArea">
                            <input type="file" id="gift_image" name="gift_image" accept=".png,.jpg,.jpeg" style="display:none;">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h3>Drop a new image here</h3>
                            <p>or click to browse (PNG format recommended)</p>
                            <div class="preview-area" id="imagePreview" style="display: <?php echo !empty($image_url) ? 'block' : 'none'; ?>">
                                <img id="previewImage" src="<?php echo !empty($image_url) ? htmlspecialchars($image_url) : '#'; ?>" alt="Preview">
                            </div>
                        </div>
                        <?php if (isset($errors["image"])): ?>
                            <div class="invalid-feedback" style="display: block;"><?php echo $errors["image"]; ?></div>
                        <?php endif; ?>
                        <div class="form-text">For the best appearance on the shuffle display, use a PNG image with transparent background. Leave empty to keep the current image.</div>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" id="is_active" name="is_active" class="form-check-input" <?php echo $is_active ? 'checked' : ''; ?>>
                        <label for="is_active" class="form-check-label">Active (available for use in breakdowns)</label>
                    </div>

                    <div style="text-align: right;">
                        <a href="gifts.php" class="btn btn-secondary" style="margin-right: 10px;">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Update Gift
                        </button>
                    </div>
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
            const dropArea = document.getElementById('dropArea');
            const fileInput = document.getElementById('gift_image');
            const imagePreview = document.getElementById('imagePreview');
            const previewImage = document.getElementById('previewImage');
            
            // Open file browser when clicking on the drop area
            dropArea.addEventListener('click', function() {
                fileInput.click();
            });
            
            // Handle drag and drop events
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
            }
            
            function unhighlight() {
                dropArea.style.borderColor = 'var(--border-color)';
            }
            
            // Handle dropped files
            dropArea.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files.length > 0) {
                    fileInput.files = files;
                    updatePreview(files[0]);
                }
            }
            
            // Handle selected files
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    updatePreview(this.files[0]);
                }
            });
            
            // Update image preview
            function updatePreview(file) {
                if (!file.type.match('image.*')) {
                    alert('Please select an image file (PNG or JPEG).');
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    imagePreview.style.display = 'block';
                }
                
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>