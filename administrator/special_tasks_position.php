<?php
require_once 'includes/init.php';
require_once 'includes/admin_auth.php';
require_once 'includes/functions.php';

// Get user ID from URL
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Get user info
$user_stmt = $pdo->prepare("SELECT id, username, daily_task_limit, special_task_positions FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

if (!$user) {
    $_SESSION['error_message'] = "User not found.";
    header("Location: users.php");
    exit;
}

// Create or update user_special_tasks table with all required columns
try {
    // First check if table exists
    $table_exists = $pdo->query("SHOW TABLES LIKE 'user_special_tasks'")->rowCount() > 0;
    
    if (!$table_exists) {
        // Create table if it doesn't exist
        $pdo->exec("
            CREATE TABLE user_special_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                position INT NOT NULL,
                task_id INT NOT NULL,
                assigned_by_admin TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                custom_reward DECIMAL(10,2) DEFAULT NULL,
                custom_cost DECIMAL(10,2) DEFAULT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_position (user_id, position),
                KEY idx_user_id (user_id),
                KEY idx_task_id (task_id),
                KEY idx_is_active (is_active),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            )
        ");
    } else {
        // Table exists, check if columns exist and add them if missing
        $columns = $pdo->query("SHOW COLUMNS FROM user_special_tasks")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('assigned_by_admin', $columns)) {
            $pdo->exec("ALTER TABLE user_special_tasks ADD COLUMN assigned_by_admin TINYINT(1) NOT NULL DEFAULT 1 AFTER position");
        }
        
        if (!in_array('is_active', $columns)) {
            $pdo->exec("ALTER TABLE user_special_tasks ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER assigned_by_admin");
        }
        
        if (!in_array('updated_at', $columns)) {
            $pdo->exec("ALTER TABLE user_special_tasks ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER assigned_at");
        }
        
        if (!in_array('custom_reward', $columns)) {
            $pdo->exec("ALTER TABLE user_special_tasks ADD COLUMN custom_reward DECIMAL(10,2) DEFAULT NULL");
        }
        
        if (!in_array('custom_cost', $columns)) {
            $pdo->exec("ALTER TABLE user_special_tasks ADD COLUMN custom_cost DECIMAL(10,2) DEFAULT NULL");
        }
        
        // Add indexes if they don't exist
        try {
            $pdo->exec("ALTER TABLE user_special_tasks ADD INDEX idx_is_active (is_active)");
        } catch (PDOException $e) {
            // Index might already exist
        }
    }
} catch (PDOException $e) {
    error_log("Table creation/alteration error: " . $e->getMessage());
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
}

// Create special_task_deposits table for deposit tracking
try {
    $table_exists = $pdo->query("SHOW TABLES LIKE 'special_task_deposits'")->rowCount() > 0;
    
    if (!$table_exists) {
        $pdo->exec("
            CREATE TABLE special_task_deposits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                task_position INT NOT NULL,
                deposit_amount DECIMAL(10,2) NOT NULL,
                deposit_date DATE NOT NULL,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                admin_message TEXT NULL,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_position_date (user_id, task_position, deposit_date),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }
} catch (PDOException $e) {
    error_log("Special task deposits table creation error: " . $e->getMessage());
}

// Handle form submission for special task positions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_positions'])) {
        $special_task_positions = isset($_POST['special_positions']) ? $_POST['special_positions'] : [];
        
        // Validate positions - FIX: Ensure positions are stored as 1-indexed integers
        $valid_positions = [];
        foreach ($special_task_positions as $position) {
            $pos = (int)$position;
            // FIX: Keep position as-is (1-indexed), no -1 offset
            if ($pos > 0 && $pos <= $user['daily_task_limit']) {
                $valid_positions[] = $pos;
            }
        }
        
        // Remove duplicates and sort
        $valid_positions = array_unique($valid_positions);
        sort($valid_positions);
        
        // Convert to JSON string for storage
        $positions_json = json_encode(array_values($valid_positions));
        
        // Update user record
        $update_stmt = $pdo->prepare("UPDATE users SET special_task_positions = ? WHERE id = ?");
        if ($update_stmt->execute([$positions_json, $user_id])) {
            $_SESSION['success_message'] = "Special task positions updated successfully for " . $user['username'];
        } else {
            $_SESSION['error_message'] = "Failed to update special task positions.";
        }
        
        header("Location: special_tasks_position.php?user_id=" . $user_id);
        exit;
    }
    
    // Handle multiple positions input
    if (isset($_POST['add_multiple_positions'])) {
        $multiple_positions_input = trim($_POST['multiple_positions'] ?? '');
        
        if (!empty($multiple_positions_input)) {
            // Parse multiple positions input
            $new_positions = parseMultiplePositions($multiple_positions_input, $user['daily_task_limit']);
            
            if (!empty($new_positions)) {
                // Get current positions
                $current_positions = [];
                if (!empty($user['special_task_positions'])) {
                    $current_positions = json_decode($user['special_task_positions'], true);
                    if (!is_array($current_positions)) {
                        $current_positions = [];
                    }
                }
                
                // Merge and remove duplicates
                $all_positions = array_merge($current_positions, $new_positions);
                $all_positions = array_unique($all_positions);
                sort($all_positions);
                
                // Update database
                $positions_json = json_encode(array_values($all_positions));
                $update_stmt = $pdo->prepare("UPDATE users SET special_task_positions = ? WHERE id = ?");
                
                if ($update_stmt->execute([$positions_json, $user_id])) {
                    $_SESSION['success_message'] = "Added " . count($new_positions) . " new positions for " . $user['username'];
                } else {
                    $_SESSION['error_message'] = "Failed to add multiple positions.";
                }
            } else {
                $_SESSION['error_message'] = "No valid positions found in the input.";
            }
        } else {
            $_SESSION['error_message'] = "Please enter positions to add.";
        }
        
        header("Location: special_tasks_position.php?user_id=" . $user_id);
        exit;
    }
    
    // Handle task assignment
    if (isset($_POST['assign_tasks'])) {
        $task_assignments = isset($_POST['task_assignments']) ? $_POST['task_assignments'] : [];
        $custom_rewards = isset($_POST['custom_rewards']) ? $_POST['custom_rewards'] : [];
        $custom_costs = isset($_POST['custom_costs']) ? $_POST['custom_costs'] : [];
        
        // Use transaction for safety
        try {
            $pdo->beginTransaction();
            
            // First, deactivate existing assignments for this user
            $deactivate_stmt = $pdo->prepare("
                UPDATE user_special_tasks 
                SET is_active = 0 
                WHERE user_id = ?
            ");
            $deactivate_stmt->execute([$user_id]);
            
            // Insert or update new assignments
            $insert_stmt = $pdo->prepare("
                INSERT INTO user_special_tasks (user_id, position, task_id, custom_reward, custom_cost, assigned_by_admin, is_active) 
                VALUES (?, ?, ?, ?, ?, 1, 1)
                ON DUPLICATE KEY UPDATE 
                    task_id = VALUES(task_id),
                    custom_reward = VALUES(custom_reward),
                    custom_cost = VALUES(custom_cost),
                    assigned_by_admin = 1,
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $assignments_made = false;
            
            foreach ($task_assignments as $position => $task_id) {
                if (!empty($task_id) && $task_id > 0) {
                    // FIX: Ensure position is integer (1-indexed)
                    $position_int = (int)$position;
                    
                    $custom_reward = isset($custom_rewards[$position]) && $custom_rewards[$position] !== '' ? 
                                   floatval($custom_rewards[$position]) : null;
                    $custom_cost = isset($custom_costs[$position]) && $custom_costs[$position] !== '' ? 
                                 floatval($custom_costs[$position]) : null;
                    
                    $insert_stmt->execute([$user_id, $position_int, $task_id, $custom_reward, $custom_cost]);
                    $assignments_made = true;
                }
            }
            
            $pdo->commit();
            
            // FIX: Sync users.special_task_positions with actual assigned positions in user_special_tasks
            $sync_positions = [];
            $sync_stmt = $pdo->prepare("SELECT position FROM user_special_tasks WHERE user_id = ? AND is_active = 1 ORDER BY position");
            $sync_stmt->execute([$user_id]);
            $sync_results = $sync_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if ($sync_results) {
                $sync_positions = array_map('intval', $sync_results);
                sort($sync_positions);
            }
            
            $positions_json = json_encode(array_values($sync_positions));
            $update_sync = $pdo->prepare("UPDATE users SET special_task_positions = ? WHERE id = ?");
            $update_sync->execute([$positions_json, $user_id]);
            
            if ($assignments_made) {
                $_SESSION['success_message'] = "Special tasks assigned successfully.";
            } else {
                $_SESSION['success_message'] = "All special tasks cleared successfully.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error assigning tasks: " . $e->getMessage();
        }
        
        header("Location: special_tasks_position.php?user_id=" . $user_id);
        exit;
    }
}

// Function to parse multiple positions input
function parseMultiplePositions($input, $max_limit) {
    $positions = [];
    
    // Remove any extra spaces
    $input = preg_replace('/\s+/', ' ', trim($input));
    
    // Handle comma-separated values
    if (strpos($input, ',') !== false) {
        $parts = explode(',', $input);
        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, '-') !== false) {
                // Handle ranges (e.g., "1-5")
                $range_parts = explode('-', $part);
                if (count($range_parts) === 2) {
                    $start = (int)trim($range_parts[0]);
                    $end = (int)trim($range_parts[1]);
                    if ($start > 0 && $end >= $start && $end <= $max_limit) {
                        for ($i = $start; $i <= $end; $i++) {
                            $positions[] = $i;
                        }
                    }
                }
            } else {
                // Handle single numbers
                $num = (int)$part;
                if ($num > 0 && $num <= $max_limit) {
                    $positions[] = $num;
                }
            }
        }
    }
    // Handle space-separated values
    elseif (strpos($input, ' ') !== false) {
        $parts = explode(' ', $input);
        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, '-') !== false) {
                // Handle ranges
                $range_parts = explode('-', $part);
                if (count($range_parts) === 2) {
                    $start = (int)trim($range_parts[0]);
                    $end = (int)trim($range_parts[1]);
                    if ($start > 0 && $end >= $start && $end <= $max_limit) {
                        for ($i = $start; $i <= $end; $i++) {
                            $positions[] = $i;
                        }
                    }
                }
            } else {
                // Handle single numbers
                $num = (int)$part;
                if ($num > 0 && $num <= $max_limit) {
                    $positions[] = $num;
                }
            }
        }
    }
    // Handle ranges only (e.g., "1-5")
    elseif (strpos($input, '-') !== false) {
        $range_parts = explode('-', $input);
        if (count($range_parts) === 2) {
            $start = (int)trim($range_parts[0]);
            $end = (int)trim($range_parts[1]);
            if ($start > 0 && $end >= $start && $end <= $max_limit) {
                for ($i = $start; $i <= $end; $i++) {
                    $positions[] = $i;
                }
            }
        }
    }
    // Handle single number
    else {
        $num = (int)$input;
        if ($num > 0 && $num <= $max_limit) {
            $positions[] = $num;
        }
    }
    
    return array_unique($positions);
}

// Get current special task positions
$current_positions = [];
if (!empty($user['special_task_positions'])) {
    $current_positions = json_decode($user['special_task_positions'], true);
    if (!is_array($current_positions)) {
        $current_positions = [];
    }
    // FIX: Ensure positions are integers
    $current_positions = array_map('intval', $current_positions);
}

// Get ALL available active tasks for selection (not just top-up tasks)
$tasks_stmt = $pdo->query("
    SELECT id, title, reward, cost, is_special, is_topup_task 
    FROM tasks 
    WHERE is_active = TRUE 
    ORDER BY is_special DESC, is_topup_task DESC, title ASC
");
$available_tasks = $tasks_stmt->fetchAll();

// Get assigned special tasks for this user (only active ones)
$assigned_tasks = [];
try {
    $assigned_stmt = $pdo->prepare("
        SELECT ust.position, t.id as task_id, t.title, t.reward, t.cost, t.is_special, t.is_topup_task,
               ust.custom_reward, ust.custom_cost 
        FROM user_special_tasks ust 
        LEFT JOIN tasks t ON ust.task_id = t.id 
        WHERE ust.user_id = ? AND ust.is_active = 1
        ORDER BY ust.position
    ");
    $assigned_stmt->execute([$user_id]);
    $assigned_tasks = $assigned_stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching assigned tasks: " . $e->getMessage());
}

// Create a map for easier access
$assigned_tasks_map = [];
foreach ($assigned_tasks as $assigned) {
    $assigned_tasks_map[$assigned['position']] = $assigned;
}

// Get unread notifications count
$unread_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = $unread_stmt->fetch()['count'];

// Get pending withdrawals count for header
$pending_withdrawals = $pdo->query("SELECT COUNT(*) as count FROM withdrawals WHERE status = 'pending'")->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Special Tasks Position - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* All existing CSS styles remain the same - keeping your original styling */
        .balance-adjustment-form {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            margin: 10px 0;
        }
        
        .balance-adjustment-form h4 {
            color: #2af0ea;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #2af0ea;
            font-weight: bold;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.6);
            color: #ffffff;
            font-size: 14px;
        }
        
        .form-group textarea {
            height: 80px;
            resize: vertical;
        }
        
        .wallet-address {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #fe2858;
            background: rgba(4, 4, 4, 0.6);
            padding: 5px 8px;
            border-radius: 4px;
            border: 1px solid rgba(254, 40, 88, 0.2);
            word-break: break-all;
        }
        
        .phone-number {
            color: #2af0ea;
            font-weight: 500;
        }
        
        .balance-change-positive {
            color: #2af0ea;
            font-weight: bold;
        }
        
        .balance-change-negative {
            color: #fe2858;
            font-weight: bold;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
        }
        
        .modal-content {
            background: rgba(4, 4, 4, 0.95);
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            width: 90%;
            max-width: 500px;
            color: #ffffff;
        }
        
        .close {
            color: #fe2858;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #2af0ea;
        }
        
        .balance-display {
            background: rgba(4, 4, 4, 0.6);
            border: 1px solid rgba(42, 240, 234, 0.2);
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .daily-progress {
            background: rgba(4, 4, 4, 0.6);
            border: 1px solid rgba(42, 240, 234, 0.2);
            padding: 8px 12px;
            border-radius: 5px;
            margin-top: 5px;
        }
        
        .progress-info {
            font-size: 12px;
            color: #2af0ea;
        }
        
        .task-limit-input {
            width: 80px !important;
            display: inline-block !important;
            margin-right: 5px;
        }
        
        .earning-limit-input {
            width: 100px !important;
            display: inline-block !important;
            margin-right: 5px;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            display: inline-block;
            margin-top: 2px;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .task-limit-container {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .task-limit-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .btn-special {
            background: #e67e22;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            display: inline-block;
        }
        
        .btn-special:hover {
            background: #d35400;
        }

        /* Special Tasks Position Styles */
        .positions-section, .assignments-section {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            margin: 10px 0;
        }
        
        .positions-section h3, .assignments-section h3 {
            color: #2af0ea;
            margin-bottom: 15px;
        }
        
        .section-description {
            color: #ffffff;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .positions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 10px;
            margin: 15px 0;
        }
        
        .position-checkbox {
            display: none;
        }
        
        .position-label {
            display: block;
            padding: 12px 8px;
            text-align: center;
            background: rgba(4, 4, 4, 0.6);
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #ffffff;
        }
        
        .position-label:hover {
            border-color: #2af0ea;
            background: rgba(42, 240, 234, 0.1);
        }
        
        .position-checkbox:checked + .position-label {
            background: rgba(42, 240, 234, 0.2);
            border-color: #2af0ea;
            color: #2af0ea;
            box-shadow: 0 0 10px rgba(42, 240, 234, 0.3);
        }
        
        .position-checkbox:checked + .position-label.assigned {
            background: rgba(254, 40, 88, 0.2);
            border-color: #fe2858;
            color: #fe2858;
            box-shadow: 0 0 10px rgba(254, 40, 88, 0.3);
        }
        
        .assignment-row {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid rgba(42, 240, 234, 0.1);
            background: rgba(4, 4, 4, 0.6);
            margin-bottom: 10px;
            border-radius: 8px;
            gap: 15px;
        }
        
        .position-display {
            width: 100px;
            font-weight: bold;
            color: #2af0ea;
            font-size: 16px;
        }
        
        .task-select {
            flex: 1;
            padding: 10px;
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.8);
            color: #ffffff;
            font-size: 14px;
        }
        
        .task-select:focus {
            border-color: #2af0ea;
            outline: none;
        }
        
        .task-select option {
            background: rgba(4, 4, 4, 0.9);
            color: #ffffff;
        }
        
        .custom-fields {
            display: flex;
            gap: 10px;
            min-width: 250px;
        }
        
        .custom-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .custom-field label {
            font-size: 12px;
            color: #2af0ea;
            font-weight: bold;
        }
        
        .custom-input {
            width: 100px;
            padding: 8px;
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.8);
            color: #ffffff;
            font-size: 12px;
        }
        
        .custom-input:focus {
            border-color: #2af0ea;
            outline: none;
        }
        
        .task-info {
            color: #fe2858;
            font-size: 0.9em;
            min-width: 150px;
            text-align: center;
            font-weight: bold;
        }
        
        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-start;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .current-assignments {
            margin-top: 30px;
        }
        
        .assignment-card {
            background: rgba(4, 4, 4, 0.6);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(254, 40, 88, 0.2);
            margin-bottom: 10px;
        }
        
        .assignment-position {
            color: #2af0ea;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .assignment-task {
            color: #ffffff;
            margin-bottom: 5px;
        }
        
        .assignment-reward {
            color: #fe2858;
            font-weight: bold;
        }
        
        .custom-values {
            color: #e67e22;
            font-style: italic;
            margin-top: 5px;
        }
        
        .task-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 5px;
        }
        
        .badge-special {
            background: #e67e22;
            color: white;
        }
        
        .badge-topup {
            background: #ffc107;
            color: black;
        }
        
        .badge-regular {
            background: #007bff;
            color: white;
        }
        
        .no-tasks-message {
            text-align: center;
            color: #6c757d;
            padding: 40px 20px;
            background: rgba(4, 4, 4, 0.6);
            border-radius: 8px;
            border: 2px dashed rgba(42, 240, 234, 0.2);
        }
        
        .manual-position-input {
            display: flex;
            gap: 10px;
            margin: 15px 0;
            align-items: center;
        }
        
        .position-input {
            width: 100px;
            padding: 8px;
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.6);
            color: #ffffff;
        }
        
        .multiple-positions-input {
            flex: 1;
            padding: 8px;
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.6);
            color: #ffffff;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .current-positions-list {
            background: rgba(4, 4, 4, 0.6);
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            border: 1px solid rgba(42, 240, 234, 0.2);
        }
        
        .positions-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        
        .position-tag {
            background: rgba(42, 240, 234, 0.2);
            color: #2af0ea;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            border: 1px solid rgba(42, 240, 234, 0.3);
        }
        
        .position-tag.assigned {
            background: rgba(254, 40, 88, 0.2);
            color: #fe2858;
            border: 1px solid rgba(254, 40, 88, 0.3);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.2);
            border-color: rgba(40, 167, 69, 0.3);
            color: #d4edda;
        }
        
        .alert-error {
            background: rgba(220, 53, 69, 0.2);
            border-color: rgba(220, 53, 69, 0.3);
            color: #f8d7da;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: rgba(4, 4, 4, 0.6);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(42, 240, 234, 0.2);
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2af0ea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #ffffff;
        }
        
        .user-info-card {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            margin-bottom: 20px;
        }
        
        .user-info-card h3 {
            color: #2af0ea;
            margin-bottom: 10px;
        }

        /* Header Styles */
        header {
            background: rgba(4, 4, 4, 0.9);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid rgba(42, 240, 234, 0.2);
        }
        
        header h1 {
            color: #2af0ea;
            text-align: center;
            margin-bottom: 15px;
        }
        
        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
        }
        
        nav a {
            background: rgba(42, 240, 234, 0.1);
            color: #ffffff;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            border: 1px solid rgba(42, 240, 234, 0.2);
            transition: all 0.3s ease;
        }
        
        nav a:hover {
            background: rgba(42, 240, 234, 0.2);
            border-color: #2af0ea;
        }
        
        main {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(42, 240, 234, 0.2);
        }
        
        h2 {
            color: #2af0ea;
            text-align: center;
            margin-bottom: 20px;
        }
        
        footer {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
            color: #6c757d;
        }

        /* Multiple Positions Styles */
        .multiple-positions-section {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            margin: 10px 0;
        }
        
        .input-examples {
            margin-top: 10px;
            padding: 10px;
            background: rgba(4, 4, 4, 0.6);
            border-radius: 5px;
            border: 1px solid rgba(42, 240, 234, 0.2);
        }
        
        .example-item {
            margin: 5px 0;
            font-size: 12px;
            color: #2af0ea;
        }
        
        .input-group {
            display: flex;
            gap: 10px;
            margin: 15px 0;
            align-items: flex-end;
        }
        
        .input-field {
            flex: 1;
        }
        
        .input-field label {
            display: block;
            margin-bottom: 5px;
            color: #2af0ea;
            font-weight: bold;
        }

        .deposit-info {
            background: rgba(230, 126, 34, 0.1);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(230, 126, 34, 0.3);
            margin: 10px 0;
        }
        
        .deposit-info h4 {
            color: #e67e22;
            margin-bottom: 10px;
        }
        
        .deposit-note {
            color: #e67e22;
            font-style: italic;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .quick-actions-bar {
            margin: 15px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            padding: 8px 15px;
            background: rgba(42, 240, 234, 0.1);
            border: 1px solid rgba(42, 240, 234, 0.3);
            border-radius: 6px;
            cursor: pointer;
            color: #ffffff;
            transition: all 0.3s ease;
        }
        
        .quick-btn:hover {
            background: rgba(42, 240, 234, 0.2);
            border-color: #2af0ea;
        }
        
        .quick-btn-danger {
            background: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.3);
        }
        
        .quick-btn-danger:hover {
            background: rgba(220, 53, 69, 0.2);
            border-color: #dc3545;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .positions-grid {
                grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            }
            
            .assignment-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .custom-fields {
                flex-direction: column;
                min-width: auto;
            }
            
            .custom-input {
                width: 100%;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .input-group {
                flex-direction: column;
                align-items: stretch;
            }
        }
        
        .net-positive {
            color: #2af0ea !important;
        }
        
        .net-negative {
            color: #fe2858 !important;
        }
        
        .net-zero {
            color: #6c757d !important;
        }
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
            <a href="special_tasks_position.php">Special Tasks Positions</a>
            <a href="payments.php">Payments</a>
            <a href="commissions.php">Commissions</a>
            <a href="withdrawals.php">Withdrawals (<?php echo $pending_withdrawals; ?>)</a>
            <a href="settings.php">Settings</a>
            <a href="edittask.php">Edit Task</a>
            <a href="notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
            <a href="../logout.php">Logout</a>
        </nav>
    </header>

    <main>
        <h2>Manage Special Tasks Position - <?php echo htmlspecialchars($user['username']); ?></h2>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <p><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <p><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></p>
            </div>
        <?php endif; ?>

        <!-- User Information -->
        <div class="user-info-card">
            <h3>User Information</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="stat-label">Username</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $user['daily_task_limit'] ?: '40 (Unlimited)'; ?></div>
                    <div class="stat-label">Daily Task Limit</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">#<?php echo $user_id; ?></div>
                    <div class="stat-label">User ID</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($current_positions); ?> Positions</div>
                    <div class="stat-label">Special Positions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($assigned_tasks); ?> Tasks</div>
                    <div class="stat-label">Assigned Tasks</div>
                </div>
            </div>
        </div>

        <!-- Multiple Positions Section -->
        <form method="POST" class="multiple-positions-section">
            <h3>Add Multiple Positions</h3>
            <div class="section-description">
                <p>Add multiple positions at once using various formats. Positions will be added to the existing ones.</p>
            </div>

            <div class="input-group">
                <div class="input-field">
                    <label for="multiple_positions">Enter Positions:</label>
                    <input type="text" id="multiple_positions" name="multiple_positions" class="multiple-positions-input" 
                           placeholder="e.g., 1,3,5 or 1-5 or 1,3-6,8">
                </div>
                <button type="submit" name="add_multiple_positions" class="btn btn-success">
                    Add Multiple Positions
                </button>
            </div>

            <div class="input-examples">
                <strong>Examples:</strong>
                <div class="example-item">• Single: 5</div>
                <div class="example-item">• Multiple: 1,3,5,7</div>
                <div class="example-item">• Range: 1-10</div>
                <div class="example-item">• Mixed: 1,3-6,8,10-12</div>
            </div>
        </form>

        <!-- Set Special Task Positions -->
        <form method="POST" class="positions-section">
            <h3>Set Special Task Positions</h3>
            <div class="section-description">
                <p>Select positions where special tasks should appear. Users must complete these special tasks before proceeding to the next task.</p>
            </div>

            <!-- Manual Position Input -->
            <div class="manual-position-input">
                <input type="number" id="manualPosition" class="position-input" min="1" max="<?php echo $user['daily_task_limit']; ?>" placeholder="Enter position">
                <button type="button" class="btn btn-primary btn-sm" onclick="addManualPosition()">Add Single Position</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="clearAllPositions()">Clear All</button>
            </div>

            <!-- Current Positions Display -->
            <?php if (!empty($current_positions)): ?>
                <div class="current-positions-list">
                    <strong>Current Special Positions (<?php echo count($current_positions); ?>):</strong>
                    <div class="positions-list">
                        <?php foreach ($current_positions as $position): ?>
                            <span class="position-tag <?php echo isset($assigned_tasks_map[$position]) ? 'assigned' : ''; ?>">
                                Position <?php echo $position; ?>
                                <?php if (isset($assigned_tasks_map[$position])): ?>
                                    (Assigned)
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="current-positions-list">
                    <strong>Current Special Positions (0):</strong>
                    <div class="positions-list">
                        <span style="color: #6c757d; font-style: italic;">No special positions set yet</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Positions Grid -->
            <div class="positions-grid">
                <?php for ($i = 1; $i <= $user['daily_task_limit']; $i++): ?>
                    <div>
                        <input type="checkbox" name="special_positions[]" value="<?php echo $i; ?>" 
                               id="pos_<?php echo $i; ?>" class="position-checkbox"
                               <?php echo in_array($i, $current_positions) ? 'checked' : ''; ?>>
                        <label for="pos_<?php echo $i; ?>" class="position-label <?php echo isset($assigned_tasks_map[$i]) ? 'assigned' : ''; ?>">
                            #<?php echo $i; ?>
                            <?php if (isset($assigned_tasks_map[$i])): ?>
                                <br><small>Assigned</small>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endfor; ?>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="update_positions" class="btn btn-primary">
                    Save Positions
                </button>
                <a href="users.php" class="btn btn-secondary">
                    Back to Users
                </a>
            </div>
        </form>

        <!-- Assign Special Tasks -->
        <?php if (!empty($current_positions)): ?>
            <form method="POST" class="assignments-section">
                <input type="hidden" name="assign_tasks" value="1">
                <h3>Assign Special Tasks to Positions</h3>
                <div class="section-description">
                    <p>Select tasks for each special position. Users must complete these tasks in order. You can also set custom rewards and deposits.</p>
                </div>
                
                <!-- Quick Actions -->
                <div class="quick-actions-bar">
                    <button type="button" class="quick-btn" onclick="fillAllPositions('random')">🎲 Fill Random Tasks</button>
                    <button type="button" class="quick-btn" onclick="fillAllPositions('first')">📋 Fill with First Task</button>
                    <button type="button" class="quick-btn quick-btn-danger" onclick="clearAllAssignments()">🗑️ Clear All Assignments</button>
                </div>
                
                <?php foreach ($current_positions as $position): ?>
                    <?php 
                    $assigned_task = isset($assigned_tasks_map[$position]) ? $assigned_tasks_map[$position] : null;
                    $custom_reward = $assigned_task ? ($assigned_task['custom_reward'] !== null ? $assigned_task['custom_reward'] : '') : '';
                    $custom_cost = $assigned_task ? ($assigned_task['custom_cost'] !== null ? $assigned_task['custom_cost'] : '') : '';
                    ?>
                    <div class="assignment-row">
                        <div class="position-display">Position #<?php echo $position; ?></div>
                        <select name="task_assignments[<?php echo $position; ?>]" class="task-select" required onchange="updateNetCalculation(this, <?php echo $position; ?>)">
                            <option value="">-- Select Task --</option>
                            <?php foreach ($available_tasks as $task): ?>
                                <?php
                                $task_badge = '';
                                if ($task['is_special']) $task_badge = ' [SPECIAL]';
                                if ($task['is_topup_task']) $task_badge = ' [TOP-UP]';
                                ?>
                                <option value="<?php echo $task['id']; ?>" 
                                    data-reward="<?php echo $task['reward']; ?>"
                                    data-cost="<?php echo $task['cost']; ?>"
                                    data-special="<?php echo $task['is_special']; ?>"
                                    data-topup="<?php echo $task['is_topup_task']; ?>"
                                    <?php echo ($assigned_task && $assigned_task['task_id'] == $task['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($task['title']); ?><?php echo $task_badge; ?> 
                                    (Reward: $<?php echo format_balance($task['reward']); ?>, 
                                    Deposit: $<?php echo format_balance($task['cost']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div class="custom-fields">
                            <div class="custom-field">
                                <label for="custom_reward_<?php echo $position; ?>">Custom Reward ($)</label>
                                <input type="number" step="0.01" min="0" 
                                       name="custom_rewards[<?php echo $position; ?>]" 
                                       id="custom_reward_<?php echo $position; ?>" 
                                       class="custom-input" 
                                       placeholder="Default"
                                       value="<?php echo $custom_reward; ?>"
                                       onchange="updateNetCalculationManual(<?php echo $position; ?>)">
                            </div>
                            <div class="custom-field">
                                <label for="custom_cost_<?php echo $position; ?>">Required Deposit ($)</label>
                                <input type="number" step="0.01" min="0" 
                                       name="custom_costs[<?php echo $position; ?>]" 
                                       id="custom_cost_<?php echo $position; ?>" 
                                       class="custom-input" 
                                       placeholder="Default"
                                       value="<?php echo $custom_cost; ?>"
                                       onchange="updateNetCalculationManual(<?php echo $position; ?>)">
                            </div>
                        </div>
                        
                        <div class="task-info" id="net_info_<?php echo $position; ?>">
                            User Gets: $0.00
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">
                        Assign Tasks
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearAllAssignments()">
                        Clear All
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="no-tasks-message">
                <h3>No Special Positions Set</h3>
                <p>No special task positions have been configured for this user.</p>
                <p>Please set special task positions in the section above to enable task assignments.</p>
            </div>
        <?php endif; ?>

        <!-- Current Assignments Display -->
        <?php if (!empty($assigned_tasks)): ?>
            <div class="current-assignments">
                <h3>Current Special Task Assignments (<?php echo count($assigned_tasks); ?>)</h3>
                <?php foreach ($assigned_tasks as $assignment): ?>
                    <?php
                    $final_reward = $assignment['custom_reward'] !== null ? $assignment['custom_reward'] : $assignment['reward'];
                    $final_cost = $assignment['custom_cost'] !== null ? $assignment['custom_cost'] : $assignment['cost'];
                    $user_profit = $final_reward;
                    $net_class = 'net-positive';
                    ?>
                    <div class="assignment-card">
                        <div class="assignment-position">Position #<?php echo $assignment['position']; ?></div>
                        <div class="assignment-task">
                            <?php echo htmlspecialchars($assignment['title']); ?>
                            <?php if ($assignment['is_special']): ?>
                                <span class="task-badge badge-special">SPECIAL</span>
                            <?php endif; ?>
                            <?php if ($assignment['is_topup_task']): ?>
                                <span class="task-badge badge-topup">TOP-UP</span>
                            <?php endif; ?>
                        </div>
                        <div class="assignment-reward">
                            <?php if ($assignment['custom_reward'] !== null || $assignment['custom_cost'] !== null): ?>
                                <div class="custom-values">
                                    Custom Values: 
                                    Reward: $<?php echo format_balance($final_reward); ?>, 
                                    Required Deposit: $<?php echo format_balance($final_cost); ?>
                                </div>
                            <?php else: ?>
                                Default Values: 
                                Reward: $<?php echo format_balance($assignment['reward']); ?>, 
                                Required Deposit: $<?php echo format_balance($assignment['cost']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="assignment-reward <?php echo $net_class; ?>">
                            User Profit: $<?php echo format_balance($user_profit); ?>
                            (Keeps deposit + gets reward)
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($current_positions)): ?>
            <div class="no-tasks-message">
                <h3>No Tasks Assigned to Positions</h3>
                <p>Special positions are configured but no tasks have been assigned yet.</p>
                <p>Use the "Assign Special Tasks" section above to assign tasks to each position.</p>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>

<script>
    // Fill all positions with tasks
    function fillAllPositions(pattern) {
        if (!confirm('This will overwrite all current task assignments. Continue?')) {
            return;
        }
        
        const selects = document.querySelectorAll('.task-select');
        const tasks = <?php echo json_encode($available_tasks); ?>;
        
        selects.forEach((select, index) => {
            if (pattern === 'random' && tasks.length > 0) {
                const randomIndex = Math.floor(Math.random() * tasks.length);
                select.value = tasks[randomIndex].id;
            } else if (pattern === 'first' && tasks.length > 0) {
                select.value = tasks[0].id;
            }
            
            // Trigger change event
            const event = new Event('change');
            select.dispatchEvent(event);
        });
    }

    // Add manual position
    function addManualPosition() {
        const manualInput = document.getElementById('manualPosition');
        const position = parseInt(manualInput.value);
        const maxLimit = <?php echo $user['daily_task_limit']; ?>;
        
        if (position && position >= 1 && position <= maxLimit) {
            const checkbox = document.getElementById('pos_' + position);
            if (checkbox) {
                checkbox.checked = true;
                manualInput.value = '';
                updatePositionsDisplay();
            }
        } else {
            alert('Please enter a valid position between 1 and ' + maxLimit);
        }
    }

    // Clear all positions
    function clearAllPositions() {
        if (confirm('Are you sure you want to clear all positions?')) {
            const checkboxes = document.querySelectorAll('.position-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updatePositionsDisplay();
        }
    }

    // Clear all assignments
    function clearAllAssignments() {
        if (confirm('Are you sure you want to clear all task assignments?')) {
            const selects = document.querySelectorAll('.task-select');
            selects.forEach(select => {
                select.selectedIndex = 0;
            });
            const customRewards = document.querySelectorAll('.custom-input[name^="custom_rewards"]');
            const customCosts = document.querySelectorAll('.custom-input[name^="custom_costs"]');
            customRewards.forEach(input => input.value = '');
            customCosts.forEach(input => input.value = '');
            updateAllNetCalculations();
        }
    }

    // Update net calculation when task selection changes
    function updateNetCalculation(selectElement, position) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const rewardInput = document.getElementById('custom_reward_' + position);
        const costInput = document.getElementById('custom_cost_' + position);
        const netInfo = document.getElementById('net_info_' + position);
        
        if (selectedOption.value) {
            const defaultReward = parseFloat(selectedOption.getAttribute('data-reward')) || 0;
            const defaultCost = parseFloat(selectedOption.getAttribute('data-cost')) || 0;
            
            // If custom fields are empty, show default values
            if (rewardInput.value === '') {
                rewardInput.placeholder = defaultReward.toFixed(2);
            }
            if (costInput.value === '') {
                costInput.placeholder = defaultCost.toFixed(2);
            }
            
            // Calculate user profit (reward only - no cost deduction)
            const reward = rewardInput.value !== '' ? parseFloat(rewardInput.value) : defaultReward;
            const userProfit = reward;
            
            // Update net display
            netInfo.innerHTML = `User Gets: $${userProfit.toFixed(2)}`;
            netInfo.className = 'task-info net-positive';
            netInfo.title = `User deposits $${(rewardInput.value !== '' ? parseFloat(rewardInput.value) : defaultCost).toFixed(2)} → gets $${reward.toFixed(2)} reward = $${userProfit.toFixed(2)} profit (keeps deposit)`;
        } else {
            rewardInput.placeholder = 'Default';
            costInput.placeholder = 'Default';
            netInfo.innerHTML = 'User Gets: $0.00';
            netInfo.className = 'task-info net-zero';
        }
    }

    // Update net calculation when custom values change
    function updateNetCalculationManual(position) {
        const selectElement = document.querySelector(`select[name="task_assignments[${position}]"]`);
        if (selectElement && selectElement.value) {
            updateNetCalculation(selectElement, position);
        }
    }

    // Update all net calculations
    function updateAllNetCalculations() {
        const selects = document.querySelectorAll('.task-select');
        selects.forEach(select => {
            const name = select.name;
            const match = name.match(/\[(\d+)\]/);
            if (match) {
                const position = match[1];
                updateNetCalculation(select, position);
            }
        });
    }

    // Update positions display
    function updatePositionsDisplay() {
        // This function would update the visual display of current positions
        console.log('Positions updated - remember to save changes');
    }

    // Initialize net calculations on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateAllNetCalculations();
        
        // Allow Enter key in manual position input
        const manualInput = document.getElementById('manualPosition');
        if (manualInput) {
            manualInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addManualPosition();
                }
            });
        }
    });
</script>
</body>
</html>