<?php
require_once 'includes/admin_auth.php';
require_once 'includes/functions.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $reward = (float)$_POST['reward'];
    $cost = (float)$_POST['cost'];
    $is_special = isset($_POST['is_special']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $is_topup_task = isset($_POST['is_topup_task']) ? 1 : 0;
    
    // Validate inputs
    if (empty($title) || empty($description)) {
        $_SESSION['error_message'] = "Title and description are required.";
    } elseif ($reward < 0) {
        $_SESSION['error_message'] = "Reward must be a positive number.";
    } elseif ($cost < 0) {
        $_SESSION['error_message'] = "Cost must be a positive number.";
    } else {
        // Handle image upload
        $image_path = null;
        if (isset($_FILES['task_image']) && $_FILES['task_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/tasks/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = $_FILES['task_image']['type'];
            $file_size = $_FILES['task_image']['size'];
            $file_tmp = $_FILES['task_image']['tmp_name'];
            
            // Validate file type
            if (!in_array($file_type, $allowed_types)) {
                $_SESSION['error_message'] = "Only JPG, JPEG, PNG, GIF, and WebP images are allowed.";
                header("Location: edit_task.php" . (isset($_POST['task_id']) ? '?id=' . $_POST['task_id'] : ''));
                exit;
            }
            
            // Validate file size (max 5MB)
            if ($file_size > 5 * 1024 * 1024) {
                $_SESSION['error_message'] = "Image size must be less than 5MB.";
                header("Location: edit_task.php" . (isset($_POST['task_id']) ? '?id=' . $_POST['task_id'] : ''));
                exit;
            }
            
            // Generate filename using task title and timestamp
            $file_extension = pathinfo($_FILES['task_image']['name'], PATHINFO_EXTENSION);
            
            // Clean the task title for filename
            $clean_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
            $clean_title = strtolower($clean_title);
            $clean_title = substr($clean_title, 0, 50); // Limit length
            
            // Generate filename: task_title_timestamp.extension
            $timestamp = time();
            $filename = 'task_' . $clean_title . '_' . $timestamp . '.' . $file_extension;
            $image_path = $upload_dir . $filename;
            
            // Ensure filename is unique by adding counter if file already exists
            $counter = 1;
            $original_path = $image_path;
            while (file_exists($image_path)) {
                $filename = 'task_' . $clean_title . '_' . $timestamp . '_' . $counter . '.' . $file_extension;
                $image_path = $upload_dir . $filename;
                $counter++;
            }
            
            // Move uploaded file
            if (!move_uploaded_file($file_tmp, $image_path)) {
                $_SESSION['error_message'] = "Failed to upload image.";
                header("Location: edit_task.php" . (isset($_POST['task_id']) ? '?id=' . $_POST['task_id'] : ''));
                exit;
            }
        }
        
        if (isset($_POST['task_id'])) {
            // Update existing task
            $task_id = (int)$_POST['task_id'];
            
            // Get old image path to delete if new image is uploaded
            $old_image_path = null;
            if ($image_path) {
                $stmt = $pdo->prepare("SELECT image_path FROM tasks WHERE id = ?");
                $stmt->execute([$task_id]);
                $old_task = $stmt->fetch();
                $old_image_path = $old_task ? $old_task['image_path'] : null;
            }
            
            if ($image_path) {
                $stmt = $pdo->prepare("
                    UPDATE tasks 
                    SET title = ?, description = ?, reward = ?, cost = ?, is_special = ?, is_active = ?, is_topup_task = ?, image_path = ?
                    WHERE id = ?
                ");
                $result = $stmt->execute([$title, $description, $reward, $cost, $is_special, $is_active, $is_topup_task, $image_path, $task_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE tasks 
                    SET title = ?, description = ?, reward = ?, cost = ?, is_special = ?, is_active = ?, is_topup_task = ?
                    WHERE id = ?
                ");
                $result = $stmt->execute([$title, $description, $reward, $cost, $is_special, $is_active, $is_topup_task, $task_id]);
            }
            
            if ($result) {
                // Delete old image if new one was uploaded
                if ($old_image_path && $image_path && file_exists($old_image_path)) {
                    unlink($old_image_path);
                }
                $_SESSION['success_message'] = "Task updated successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to update task.";
            }
        } else {
            // Create new task
            $stmt = $pdo->prepare("
                INSERT INTO tasks (title, description, reward, cost, is_special, is_active, is_topup_task, image_path, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt->execute([$title, $description, $reward, $cost, $is_special, $is_active, $is_topup_task, $image_path, $_SESSION['user_id']])) {
                $_SESSION['success_message'] = "Task created successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to create task.";
            }
        }
        
        header("Location: tasks.php");
        exit;
    }
}

// Get task data if editing
$task = null;
$is_topup = isset($_GET['type']) && $_GET['type'] === 'topup';
if (isset($_GET['id'])) {
    $task_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        $_SESSION['error_message'] = "Task not found.";
        header("Location: tasks.php");
        exit;
    }
    
    $is_topup = $task['is_topup_task'];
}

// Get unread notifications count
$unread_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = $unread_stmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($task) ? 'Edit' : 'Create'; ?> Task - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .image-preview { max-width: 300px; max-height: 200px; margin-top: 10px; border-radius: 8px; border: 2px solid #ddd; display: none; }
        .current-image { max-width: 300px; max-height: 200px; margin-top: 10px; border-radius: 8px; border: 2px solid #ddd; }
        .image-upload-area { border: 2px dashed #007bff; border-radius: 8px; padding: 2rem; text-align: center; margin: 1rem 0; cursor: pointer; transition: background-color 0.3s ease; }
        .image-upload-area:hover { background-color: #f8f9fa; }
        .image-upload-area.dragover { background-color: #e3f2fd; border-color: #2196f3; }
        .upload-icon { font-size: 3rem; color: #007bff; margin-bottom: 1rem; }
        .form-group { margin-bottom: 1.5rem; }
        .checkbox-group label { display: block; margin-bottom: 0.5rem; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 1rem; }
        .btn-primary { background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .success-message { background: #d4edda; color: #155724; padding: 1rem; border-radius: 5px; margin: 1rem 0; border: 1px solid #c3e6cb; }
        .error-message { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 5px; margin: 1rem 0; border: 1px solid #f5c6cb; }
        .info-message { background: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 5px; margin: 1rem 0; border: 1px solid #bee5eb; }
        .form-container { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: bold; color: #333; }
        .form-group input, .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px; font-size: 1rem; }
        .form-row { display: flex; gap: 1rem; }
        .form-row .form-group { flex: 1; }
        .form-actions { display: flex; gap: 1rem; margin-top: 2rem; }
        .filename-preview { margin-top: 10px; padding: 8px; background: #f8f9fa; border-radius: 4px; font-size: 0.9rem; color: #666; display: none; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>Task Website - Admin Panel</h1>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="users.php">Users</a>
            <a href="tasks.php">Tasks</a>
            <a href="payments.php">Payments</a>
            <a href="commissions.php">Commissions</a>
            <a href="settings.php">Settings</a>
            <a href="notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
            <a href="../logout.php">Logout</a>
        </nav>
    </header>

    <main>
        <h2><?php echo isset($task) ? 'Edit Task' : ($is_topup ? 'Create Top-up Task' : 'Create New Task'); ?></h2>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <p><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <p><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-container" enctype="multipart/form-data">
            <?php if (isset($task)): ?>
                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label for="title">Task Title:</label>
                <input type="text" id="title" name="title" 
                       value="<?php echo isset($task) ? htmlspecialchars($task['title']) : ''; ?>" 
                       required
                       oninput="updateFilenamePreview()">
                <small style="color: #666;">This will be used for the image filename</small>
            </div>
            
            <div class="form-group">
                <label for="description">Task Description:</label>
                <textarea id="description" name="description" rows="4" required><?php echo isset($task) ? htmlspecialchars($task['description']) : ''; ?></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="reward">Reward Amount ($):</label>
                    <input type="number" id="reward" name="reward" step="0.01" min="0"
                           value="<?php echo isset($task) ? $task['reward'] : '0.00'; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="cost">Cost Amount ($):</label>
                    <input type="number" id="cost" name="cost" step="0.01" min="0"
                           value="<?php echo isset($task) ? $task['cost'] : '0.00'; ?>" required>
                </div>
            </div>
            
            <!-- Image Upload Section -->
            <div class="form-group">
                <label>Task Image:</label>
                <div class="image-upload-area" id="imageUploadArea">
                    <div class="upload-icon">📷</div>
                    <p>Click to upload or drag and drop</p>
                    <p style="font-size: 0.9rem; color: #666;">PNG, JPG, GIF, WebP up to 5MB</p>
                    <input type="file" id="task_image" name="task_image" accept="image/*" style="display: none;" onchange="previewImage(this)">
                </div>
                
                <!-- Filename Preview -->
                <div id="filenamePreview" class="filename-preview">
                    <strong>File will be saved as:</strong> <span id="previewFilename"></span>
                </div>
                
                <!-- Current Image Preview -->
                <?php if (isset($task) && !empty($task['image_path']) && file_exists($task['image_path'])): ?>
                    <div>
                        <p><strong>Current Image:</strong></p>
                        <img src="<?php echo htmlspecialchars($task['image_path']); ?>" alt="Current Task Image" class="current-image">
                        <p style="font-size: 0.8rem; color: #666; margin-top: 5px;">
                            Current filename: <?php echo basename($task['image_path']); ?>
                        </p>
                    </div>
                <?php endif; ?>
                
                <!-- New Image Preview -->
                <img id="imagePreview" class="image-preview" alt="Image Preview">
            </div>
            
            <div class="form-group checkbox-group">
                <label>
                    <input type="checkbox" name="is_special" value="1" 
                           <?php echo (isset($task) && $task['is_special']) ? 'checked' : ''; ?>>
                    Special Task
                </label>
                
                <label>
                    <input type="checkbox" name="is_topup_task" value="1" 
                           <?php echo (isset($task) && $task['is_topup_task']) ? 'checked' : ($is_topup ? 'checked' : ''); ?>>
                    Top-up Task (Required when users reach their task limit)
                </label>
                
                <label>
                    <input type="checkbox" name="is_active" value="1" 
                           <?php echo !isset($task) || $task['is_active'] ? 'checked' : ''; ?>>
                    Active Task
                </label>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php echo isset($task) ? 'Update Task' : 'Create Task'; ?>
                </button>
                <a href="tasks.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
        
        <?php if ($is_topup || (isset($task) && $task['is_topup_task'])): ?>
            <div class="info-message">
                <h4>About Top-up Tasks:</h4>
                <ul>
                    <li>Top-up tasks are shown to users when they reach their daily task limit</li>
                    <li>Users must complete the top-up task before they can continue with regular tasks</li>
                    <li>These tasks help manage user activity and can be used for additional verification</li>
                    <li>Top-up tasks are displayed with special styling to indicate their importance</li>
                </ul>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>

<script>
    // Image upload functionality
    document.getElementById('imageUploadArea').addEventListener('click', function() {
        document.getElementById('task_image').click();
    });

    // Drag and drop functionality
    const imageUploadArea = document.getElementById('imageUploadArea');
    
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        imageUploadArea.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        imageUploadArea.addEventListener(eventName, highlight, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        imageUploadArea.addEventListener(eventName, unhighlight, false);
    });

    function highlight() {
        imageUploadArea.classList.add('dragover');
    }

    function unhighlight() {
        imageUploadArea.classList.remove('dragover');
    }

    imageUploadArea.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        document.getElementById('task_image').files = files;
        previewImage({files: files});
        updateFilenamePreview();
    }

    function previewImage(input) {
        const file = input.files ? input.files[0] : (input.files ? input.files[0] : null);
        const preview = document.getElementById('imagePreview');
        
        if (file) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
            
            reader.readAsDataURL(file);
            updateFilenamePreview();
        } else {
            preview.style.display = 'none';
        }
    }

    // Update filename preview based on task title
    function updateFilenamePreview() {
        const titleInput = document.getElementById('title');
        const fileInput = document.getElementById('task_image');
        const filenamePreview = document.getElementById('filenamePreview');
        const previewFilename = document.getElementById('previewFilename');
        
        if (titleInput.value.trim() && fileInput.files.length > 0) {
            const taskTitle = titleInput.value.trim();
            const file = fileInput.files[0];
            const fileExtension = file.name.split('.').pop().toLowerCase();
            
            // Clean the task title for filename
            let cleanTitle = taskTitle.replace(/[^a-zA-Z0-9_-]/g, '_');
            cleanTitle = cleanTitle.toLowerCase();
            cleanTitle = cleanTitle.substring(0, 50); // Limit length
            
            // Generate preview filename
            const timestamp = Math.floor(Date.now() / 1000);
            const previewName = `task_${cleanTitle}_${timestamp}.${fileExtension}`;
            
            previewFilename.textContent = previewName;
            filenamePreview.style.display = 'block';
        } else {
            filenamePreview.style.display = 'none';
        }
    }

    // Update filename preview when title changes
    document.getElementById('title').addEventListener('input', updateFilenamePreview);
    document.getElementById('task_image').addEventListener('change', updateFilenamePreview);

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const title = document.getElementById('title').value.trim();
        const description = document.getElementById('description').value.trim();
        const reward = document.getElementById('reward').value;
        const cost = document.getElementById('cost').value;
        
        if (!title) {
            e.preventDefault();
            alert('Please enter a task title.');
            return;
        }
        
        if (!description) {
            e.preventDefault();
            alert('Please enter a task description.');
            return;
        }
        
        if (reward < 0) {
            e.preventDefault();
            alert('Reward must be a positive number.');
            return;
        }
        
        if (cost < 0) {
            e.preventDefault();
            alert('Cost must be a positive number.');
            return;
        }
    });
</script>
</body>
</html>