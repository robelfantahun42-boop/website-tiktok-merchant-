<?php
require_once 'includes/admin_auth.php';
require_main_admin(); // Only main admin can change settings

$errors = [];
$success = '';

// Get current settings
$stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
$settings = $stmt->fetch();

// Get all tasks for special task selection
$tasks = $pdo->query("SELECT id, title FROM tasks WHERE is_active = TRUE ORDER BY title")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }
    
    $max_tasks_limit = (int)$_POST['max_tasks_limit'];
    $commission_percentage = (float)$_POST['commission_percentage'];
    $special_task_id = !empty($_POST['special_task_id']) ? (int)$_POST['special_task_id'] : null;
    
    // Validate inputs
    if ($max_tasks_limit < 1) {
        $errors[] = "Max tasks limit must be at least 1.";
    }
    
    if ($commission_percentage < 0 || $commission_percentage > 100) {
        $errors[] = "Commission percentage must be between 0 and 100.";
    }
    
    if ($special_task_id) {
        $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ?");
        $stmt->execute([$special_task_id]);
        if (!$stmt->fetch()) {
            $errors[] = "Invalid special task selected.";
        }
    }
    
    if (empty($errors)) {
        if ($settings) {
            // Update existing settings
            $stmt = $pdo->prepare("UPDATE settings SET max_tasks_limit = ?, commission_percentage = ?, special_task_id = ? WHERE id = ?");
            if ($stmt->execute([$max_tasks_limit, $commission_percentage, $special_task_id, $settings['id']])) {
                $success = "Settings updated successfully.";
                $settings = [
                    'max_tasks_limit' => $max_tasks_limit,
                    'commission_percentage' => $commission_percentage,
                    'special_task_id' => $special_task_id
                ];
            } else {
                $errors[] = "Failed to update settings. Please try again.";
            }
        } else {
            // Insert new settings (shouldn't happen but just in case)
            $stmt = $pdo->prepare("INSERT INTO settings (max_tasks_limit, commission_percentage, special_task_id) VALUES (?, ?, ?)");
            if ($stmt->execute([$max_tasks_limit, $commission_percentage, $special_task_id])) {
                $success = "Settings created successfully.";
                $settings = [
                    'max_tasks_limit' => $max_tasks_limit,
                    'commission_percentage' => $commission_percentage,
                    'special_task_id' => $special_task_id
                ];
            } else {
                $errors[] = "Failed to create settings. Please try again.";
            }
        }
    }
}

// Get unread notifications count
$unread_notifications = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unread_notifications->execute([$_SESSION['user_id']]);
$unread_count = $unread_notifications->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            <h2>System Settings</h2>
            
            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="success-message">
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div class="form-group">
                    <label for="max_tasks_limit">Max Tasks Limit:</label>
                    <input type="number" id="max_tasks_limit" name="max_tasks_limit" min="1" value="<?php echo $settings['max_tasks_limit']; ?>" required>
                    <small>Maximum number of normal tasks a user can complete before requiring a special task.</small>
                </div>
                
                <div class="form-group">
                    <label for="commission_percentage">Commission Percentage:</label>
                    <input type="number" id="commission_percentage" name="commission_percentage" min="0" max="100" step="0.01" value="<?php echo $settings['commission_percentage']; ?>" required>
                    <small>Percentage of task reward that goes to the referrer.</small>
                </div>
                
                <div class="form-group">
                    <label for="special_task_id">Special Task:</label>
                    <select id="special_task_id" name="special_task_id">
                        <option value="">None</option>
                        <?php foreach ($tasks as $task): ?>
                            <option value="<?php echo $task['id']; ?>" <?php echo $settings['special_task_id'] == $task['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($task['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Special task that users must complete to reset their task limit.</small>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </main>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
        </footer>
    </div>
</body>
</html>