<?php
// Start session
session_start();

// Include database connection
require_once "config/db_connect.php";

// Check if user is logged in
requireLogin();

// Check if user has appropriate permissions
if ($_SESSION["role"] !== "manager" ) {
    header("location: access_denied.php");
    exit;
}

// Initialize variables
$name = "";
$description = "";
$is_active = 1;
$errors = [];
$success_message = "";

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
    $image_url = null;
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
                $image_url = $target_file;
            } else {
                $errors["image"] = "Failed to upload image. Please try again.";
            }
        }
    }
    
    // If no errors, insert gift into database
    if (empty($errors)) {
        try {
            // Begin transaction
            $conn = getConnection();
            $conn->begin_transaction();
            
            // Insert gift
            $stmt = $conn->prepare("INSERT INTO gifts (name, description, image_url, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssis", $name, $description, $image_url, $is_active, $_SESSION["id"]);
            $stmt->execute();
            
            // Get the new gift ID
            $gift_id = $conn->insert_id;
            
            // Commit transaction
            $conn->commit();
            
            // Log activity
            logActivity($_SESSION["id"], "create_gift", "Created new gift: {$name}");
            
            // Set success message
            $success_message = "Gift added successfully!";
            
            // Reset form fields
            $name = "";
            $description = "";
            $is_active = 1;
        } catch (Exception $e) {
            // Rollback on error
            if (isset($conn)) {
                $conn->rollback();
            }
            
            error_log("Error adding gift: " . $e->getMessage());
            $errors["general"] = "An error occurred while adding the gift. Please try again.";
        }
    }
}

// Get list of existing gifts for the table
try {
    $gifts = executeQuery(
        "SELECT g.*, u.username as created_by_name
         FROM gifts g
         LEFT JOIN users u ON g.created_by = u.id
         ORDER BY g.created_at DESC
         LIMIT 5",
        [],
        ''
    );
} catch (Exception $e) {
    error_log("Error getting gifts: " . $e->getMessage());
    $gifts = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Gift - Gift Shuffle System</title>
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

        /* Navbar */
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            display: none;
            margin-top: 15px;
            text-align: center;
        }

        .preview-area img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        /* Table styles */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }

        .table th {
            font-weight: 600;
            color: var(--text-secondary);
            background-color: #f8f9fa;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover td {
            background-color: #f8f9fa;
        }

        .table-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.active {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }

        .status-badge.inactive {
            background-color: rgba(108, 117, 125, 0.1);
            color: var(--text-secondary);
        }

        /* Buttons */
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

        .action-icon {
            padding: 6px;
            border-radius: 6px;
            color: white;
            margin-right: 5px;
            display: inline-flex;
            text-decoration: none;
        }

        .action-icon.view {
            background-color: var(--info-color);
        }

        .action-icon.edit {
            background-color: var(--warning-color);
        }

        .action-icon.delete {
            background-color: var(--danger-color);
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
            <h1 class="page-title">Gift Management</h1>
            <a href="gifts.php" class="back-btn">
                <i class="fas fa-th-large"></i>
                View All Gifts
            </a>
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

        <!-- Add Gift Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-plus-circle"></i>
                Add New Gift
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
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
                        <label>Gift Image (PNG recommended)*</label>
                        <div class="file-upload" id="dropArea">
                            <input type="file" id="gift_image" name="gift_image" accept=".png,.jpg,.jpeg" style="display:none;">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h3>Drop your image here</h3>
                            <p>or click to browse (PNG format recommended)</p>
                            <div class="preview-area" id="imagePreview">
                                <img id="previewImage" src="#" alt="Preview">
                            </div>
                        </div>
                        <?php if (isset($errors["image"])): ?>
                            <div class="invalid-feedback" style="display: block;"><?php echo $errors["image"]; ?></div>
                        <?php endif; ?>
                        <div class="form-text">For the best appearance on the shuffle display, use a PNG image with transparent background.</div>
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
                            Save Gift
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Recently Added Gifts -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-history"></i>
                Recently Added Gifts
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($gifts)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No gifts found. Start by adding one!</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($gifts as $gift): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($gift['image_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($gift['image_url']); ?>" alt="<?php echo htmlspecialchars($gift['name']); ?>" class="table-image">
                                            <?php else: ?>
                                                <div style="width: 60px; height: 60px; background-color: #f1f3f4; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-gift" style="font-size: 24px; color: #6c757d;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($gift['name']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $gift['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $gift['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($gift['created_by_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo date('M j, Y, g:i a', strtotime($gift['created_at'])); ?></td>
                                        <td>
                                            <a href="view_gift.php?id=<?php echo $gift['id']; ?>" class="action-icon view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_gift.php?id=<?php echo $gift['id']; ?>" class="action-icon edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="gifts.php?action=<?php echo $gift['is_active'] ? 'deactivate' : 'activate'; ?>&id=<?php echo $gift['id']; ?>" 
                                               class="action-icon <?php echo $gift['is_active'] ? 'delete' : 'view'; ?>" 
                                               title="<?php echo $gift['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                               onclick="return confirm('Are you sure you want to <?php echo $gift['is_active'] ? 'deactivate' : 'activate'; ?> this gift?');">
                                                <i class="fas fa-<?php echo $gift['is_active'] ? 'times' : 'check'; ?>"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="gifts.php" class="btn btn-secondary">
                        <i class="fas fa-th-large"></i>
                        View All Gifts
                    </a>
                </div>
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