<?php
require_once '../includes/init.php';
require_once '../includes/admin_auth.php';
require_once '../includes/functions.php';

// Function to get all user IDs in the referral tree (recursive)
function getReferralTreeIds($pdo, $root_user_id) {
    $all_ids = [];
    $current_level = [$root_user_id];
    
    while (!empty($current_level)) {
        $placeholders = str_repeat('?,', count($current_level) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id IN ($placeholders)");
        $stmt->execute($current_level);
        $next_level = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $all_ids = array_merge($all_ids, $current_level);
        $current_level = $next_level;
    }
    
    return array_unique($all_ids);
}

// Helper function to determine referral level
function getReferralLevel($pdo, $user_id, $root_user_id) {
    if ($user_id == $root_user_id) return 0;
    
    $level = 1;
    $current_id = $user_id;
    
    // Trace back through parent IDs until we reach the root user
    while ($current_id != $root_user_id) {
        $stmt = $pdo->prepare("SELECT parent_id FROM users WHERE id = ?");
        $stmt->execute([$current_id]);
        $parent = $stmt->fetch();
        
        if (!$parent || is_null($parent['parent_id'])) {
            return 0; // Not in referral tree
        }
        
        $current_id = $parent['parent_id'];
        if ($current_id == $root_user_id) {
            return $level;
        }
        $level++;
        
        // Safety check to prevent infinite loops
        if ($level > 10) {
            return 0;
        }
    }
    
    return 0;
}

// Handle daily earning limit update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_earning_limit'])) {
    $user_id = (int)$_POST['user_id'];
    $daily_earning_limit = (float)$_POST['daily_earning_limit'];
    
    $stmt = $pdo->prepare("UPDATE users SET daily_earning_limit = ? WHERE id = ?");
    if ($stmt->execute([$daily_earning_limit, $user_id])) {
        $_SESSION['success_message'] = "Daily earning limit updated successfully.";
    } else {
        $_SESSION['error_message'] = "Failed to update daily earning limit.";
    }
    
    header("Location: users.php");
    exit;
}

// Handle daily task limit update - WITH RESET FUNCTIONALITY
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task_limit'])) {
    $user_id = (int)$_POST['user_id'];
    $daily_task_limit = $_POST['daily_task_limit'] === '' ? NULL : (int)$_POST['daily_task_limit'];
    
    // Validate task limit (must be between 1 and 40 if set)
    if ($daily_task_limit !== NULL && ($daily_task_limit < 1 || $daily_task_limit > 40)) {
        $_SESSION['error_message'] = "Daily task limit must be between 1 and 40.";
        header("Location: users.php");
        exit;
    }
    
    // Get current task limit to check if we're changing from NULL to a value (trigger reset)
    $current_stmt = $pdo->prepare("SELECT daily_task_limit FROM users WHERE id = ?");
    $current_stmt->execute([$user_id]);
    $current_user = $current_stmt->fetch();
    $current_task_limit = $current_user['daily_task_limit'];
    
    $stmt = $pdo->prepare("UPDATE users SET daily_task_limit = ? WHERE id = ?");
    if ($stmt->execute([$daily_task_limit, $user_id])) {
        
        // TRIGGER RESET: If changing from NULL to any value, reset user's daily progress
        if ($current_task_limit === NULL && $daily_task_limit !== NULL) {
            // Reset user's daily progress
            $reset_stmt = $pdo->prepare("DELETE FROM user_daily_progress WHERE user_id = ?");
            $reset_stmt->execute([$user_id]);
            
            // Reset daily task rewards
            $reset_rewards_stmt = $pdo->prepare("DELETE FROM daily_task_rewards WHERE user_id = ? AND DATE(assignment_date) = CURDATE()");
            $reset_rewards_stmt->execute([$user_id]);
            
            $_SESSION['success_message'] = "Daily task limit set to $daily_task_limit and user tasks have been reset.";
        } else {
            $_SESSION['success_message'] = "Daily task limit updated successfully.";
        }
    } else {
        $_SESSION['error_message'] = "Failed to update daily task limit.";
    }
    
    header("Location: users.php");
    exit;
}

// Handle balance adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_balance'])) {
    $user_id = (int)$_POST['user_id'];
    $balance_change = (float)$_POST['balance_change'];
    $reason = trim($_POST['reason']);
    
    // Get current balance
    $stmt = $pdo->prepare("SELECT balance, username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user) {
        $new_balance = $user['balance'] + $balance_change;
        
        // Update user balance
        $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
        if ($stmt->execute([$new_balance, $user_id])) {
            // Create balance_adjustments table if it doesn't exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS balance_adjustments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    admin_id INT NOT NULL,
                    old_balance DECIMAL(10,2) NOT NULL,
                    change_amount DECIMAL(10,2) NOT NULL,
                    new_balance DECIMAL(10,2) NOT NULL,
                    reason TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    FOREIGN KEY (admin_id) REFERENCES users(id)
                )
            ");
            
            // Log the balance adjustment
            $stmt = $pdo->prepare("INSERT INTO balance_adjustments (user_id, admin_id, old_balance, change_amount, new_balance, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $_SESSION['user_id'], $user['balance'], $balance_change, $new_balance, $reason]);
            
            $_SESSION['success_message'] = "Balance adjusted successfully for " . $user['username'] . ". New balance: $" . format_balance($new_balance);
        } else {
            $_SESSION['error_message'] = "Failed to adjust balance.";
        }
    } else {
        $_SESSION['error_message'] = "User not found.";
    }
    
    header("Location: users.php");
    exit;
}

// Handle manual progress reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_progress'])) {
    $user_id = (int)$_POST['user_id'];
    $username = $_POST['username'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Reset user's daily progress
        $reset_stmt = $pdo->prepare("DELETE FROM user_daily_progress WHERE user_id = ?");
        $reset_stmt->execute([$user_id]);
        
        // Reset daily task rewards
        $reset_rewards_stmt = $pdo->prepare("DELETE FROM daily_task_rewards WHERE user_id = ? AND DATE(assignment_date) = CURDATE()");
        $reset_rewards_stmt->execute([$user_id]);
        
        $pdo->commit();
        $_SESSION['success_message'] = "Daily progress has been reset for user: " . htmlspecialchars($username);
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Failed to reset progress: " . $e->getMessage();
    }
    
    header("Location: users.php");
    exit;
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get current user role and referral information
$current_user_stmt = $pdo->prepare("SELECT role, referral_number, invitation_key FROM users WHERE id = ?");
$current_user_stmt->execute([$_SESSION['user_id']]);
$current_user = $current_user_stmt->fetch();

// Get total users count based on role
if ($current_user['role'] === 'sub_admin') {
    // Subadmin can see all users in their referral tree (direct and indirect)
    $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
    
    if (!empty($referral_tree_ids)) {
        $placeholders = str_repeat('?,', count($referral_tree_ids) - 1) . '?';
        $total_users_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE id IN ($placeholders)");
        $total_users_stmt->execute($referral_tree_ids);
        $total_users = $total_users_stmt->fetch()['count'];
    } else {
        $total_users = 0;
    }
} else {
    // Admin can see all users
    $total_users = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
}

$total_pages = ceil($total_users / $limit);

// Get users with pagination and daily progress based on role
if ($current_user['role'] === 'sub_admin') {
    // Subadmin: Show all users in their referral tree (direct and indirect)
    if (!empty($referral_tree_ids)) {
        $placeholders = str_repeat('?,', count($referral_tree_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   (SELECT username FROM users WHERE id = u.parent_id) as parent_username,
                   (SELECT COUNT(*) FROM commissions WHERE referrer_id = u.id) as referral_count,
                   (SELECT COALESCE(SUM(commission_amount), 0) FROM commissions WHERE referrer_id = u.id) as total_commission,
                   upd.completed_tasks_today,
                   upd.todays_earned,
                   upd.current_task_index,
                   upd.last_reset_date
            FROM users u 
            LEFT JOIN user_daily_progress upd ON u.id = upd.user_id AND upd.last_reset_date = CURDATE()
            WHERE u.id IN ($placeholders)
            ORDER BY u.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        // Combine referral tree IDs with limit and offset parameters
        $params = array_merge($referral_tree_ids, [$limit, $offset]);
        $stmt->execute($params);
    } else {
        // No referrals yet
        $stmt = $pdo->prepare("SELECT 0 as dummy WHERE 1=0");
        $stmt->execute();
    }
} else {
    // Admin: Show all users
    $stmt = $pdo->prepare("
        SELECT u.*, 
               (SELECT username FROM users WHERE id = u.parent_id) as parent_username,
               (SELECT COUNT(*) FROM commissions WHERE referrer_id = u.id) as referral_count,
               (SELECT COALESCE(SUM(commission_amount), 0) FROM commissions WHERE referrer_id = u.id) as total_commission,
               upd.completed_tasks_today,
               upd.todays_earned,
               upd.current_task_index,
               upd.last_reset_date
        FROM users u 
        LEFT JOIN user_daily_progress upd ON u.id = upd.user_id AND upd.last_reset_date = CURDATE()
        ORDER BY u.created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
}
$users = $stmt->fetchAll();

// Get unread notifications count
$unread_stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
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
    <title>Manage Users - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/adminuser.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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
            padding: 10px;
            border-radius: 8px;
            margin-top: 5px;
            min-width: 180px;
        }
        
        .progress-item {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .progress-item:last-child {
            margin-bottom: 0;
        }
        
        .progress-label {
            color: #2af0ea;
            font-size: 12px;
            font-weight: bold;
            min-width: 70px;
        }
        
        .progress-value {
            color: #ffffff;
            font-weight: bold;
            font-size: 13px;
            text-align: right;
            flex-grow: 1;
            margin-left: 10px;
        }
        
        .progress-percentage {
            color: #fe2858;
            font-weight: bold;
            font-size: 12px;
            background: rgba(254, 40, 88, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
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
        
        .btn-danger {
            background: #dc3545;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            display: inline-block;
        }
        
        .btn-danger:hover {
            background: #c82333;
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
        
        .btn-reset {
            background: #9b59b6;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            display: inline-block;
            margin-top: 5px;
            width: 100%;
        }
        
        .btn-reset:hover {
            background: #8e44ad;
        }
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .role-sub_admin {
            background: rgba(230, 126, 34, 0.2);
            color: #e67e22;
            border: 1px solid rgba(230, 126, 34, 0.3);
        }
        
        .role-admin {
            background: rgba(42, 240, 234, 0.2);
            color: #2af0ea;
            border: 1px solid rgba(42, 240, 234, 0.3);
        }
        
        .role-user {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
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
        
        .table-container {
            overflow-x: auto;
            margin: 20px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(42, 240, 234, 0.1);
            color: #333333;
        }
        
        th {
            background: rgba(42, 240, 234, 0.1);
            color: #2af0ea;
            font-weight: bold;
        }
        
        tr:hover {
            background: rgba(42, 240, 234, 0.05);
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a0db5 0%, #1c68e8 100%);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-pagination {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
            padding: 15px;
        }
        
        .pagination-info {
            color: #2af0ea;
            font-weight: bold;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .referral-count, .commission-cell, .balance-cell {
            font-weight: bold;
            text-align: center;
        }
        
        .commission-cell {
            color: #fe2858;
        }
        
        .balance-cell {
            color: #2af0ea;
        }
        
        .no-wallet, .no-parent {
            color: #6c757d;
            font-style: italic;
        }
        
        .parent-user {
            color: #e67e22;
            font-weight: 500;
        }
        
        .join-date {
            color: #333333;
            font-size: 12px;
        }
        
        .no-limit-warning {
            color: #dc3545;
            font-weight: bold;
            font-size: 12px;
            background: rgba(220, 53, 69, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .limit-set {
            color: #28a745;
            font-weight: bold;
            font-size: 12px;
            background: rgba(40, 167, 69, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        /* Referral level styles */
        .referral-level {
            background: rgba(42, 240, 234, 0.2);
            color: #2af0ea;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            border: 1px solid rgba(42, 240, 234, 0.3);
            text-align: center;
            display: inline-block;
        }
        
        .level-0 {
            background: rgba(42, 240, 234, 0.3);
            color: #2af0ea;
            border: 1px solid rgba(42, 240, 234, 0.4);
        }
        
        .level-1 {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .level-2 {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .level-3 {
            background: rgba(253, 126, 20, 0.2);
            color: #fd7e14;
            border: 1px solid rgba(253, 126, 20, 0.3);
        }
        
        .level-4-plus {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.3);
        }
        
        .no-level {
            color: #6c757d;
            font-style: italic;
        }
        
        /* New styles for referral code display */
        .referral-info-card {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(42, 240, 234, 0.3);
            margin: 20px 0;
            box-shadow: 0 4px 15px rgba(42, 240, 234, 0.1);
        }
        
        .referral-info-header {
            color: #2af0ea;
            font-size: 1.5rem;
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
        }
        
        .referral-codes-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        .referral-code-item {
            background: rgba(4, 4, 4, 0.6);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(254, 40, 88, 0.2);
        }
        
        .referral-code-label {
            color: #2af0ea;
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .referral-code-value {
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: bold;
            color: #fe2858;
            background: rgba(4, 4, 4, 0.8);
            padding: 10px;
            border-radius: 5px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            word-break: break-all;
            margin-bottom: 10px;
        }
        
        .copy-btn {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .copy-btn:hover {
            background: #138496;
            transform: translateY(-1px);
        }
        
        .copy-btn.copied {
            background: #28a745;
        }
        
        .referral-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-item {
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
        
        .referral-instructions {
            background: rgba(4, 4, 4, 0.6);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(42, 240, 234, 0.2);
            margin-top: 20px;
        }
        
        .instructions-title {
            color: #2af0ea;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .instructions-list {
            color: #ffffff;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .instructions-list li {
            margin-bottom: 8px;
        }
        
        /* New styles for progress display */
        .progress-details {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .progress-status {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
        }
        
        .progress-icon {
            color: #2af0ea;
        }
        
        .progress-text {
            color: #333333;
        }
        
        .progress-complete {
            color: #28a745;
            font-weight: bold;
        }
        
        .progress-incomplete {
            color: #dc3545;
            font-weight: bold;
        }
        
        .progress-reset-date {
            font-size: 11px;
            color: #6c757d;
            margin-top: 3px;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .referral-codes-container {
                grid-template-columns: 1fr;
            }
            
            .referral-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table-container {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .daily-progress {
                min-width: 150px;
            }
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
        <h2>Manage Users 
            <?php if ($current_user['role'] === 'sub_admin'): ?>
                <span style="font-size: 14px; color: #e67e22;">(Showing your entire referral network)</span>
            <?php endif; ?>
        </h2>
        
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

        <!-- Subadmin Referral Information -->
        <?php if ($current_user['role'] === 'sub_admin'): ?>
            <div class="referral-info-card">
                <div class="referral-info-header">
                    <i class="fas fa-user-friends"></i> Your Referral Information
                </div>
                
                <div class="referral-codes-container">
                    <div class="referral-code-item">
                        <div class="referral-code-label">
                            <i class="fas fa-hashtag"></i> Referral Number
                        </div>
                        <div class="referral-code-value" id="referralNumber">
                            <?php echo !empty($current_user['referral_number']) ? htmlspecialchars($current_user['referral_number']) : 'Not Assigned'; ?>
                        </div>
                        <?php if (!empty($current_user['referral_number'])): ?>
                            <button class="copy-btn" onclick="copyToClipboard('referralNumber', this)">
                                <i class="fas fa-copy"></i> Copy Referral Number
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="referral-code-item">
                        <div class="referral-code-label">
                            <i class="fas fa-key"></i> Invitation Key
                        </div>
                        <div class="referral-code-value" id="invitationKey">
                            <?php echo !empty($current_user['invitation_key']) ? htmlspecialchars($current_user['invitation_key']) : 'Not Available'; ?>
                        </div>
                        <?php if (!empty($current_user['invitation_key'])): ?>
                            <button class="copy-btn" onclick="copyToClipboard('invitationKey', this)">
                                <i class="fas fa-copy"></i> Copy Invitation Key
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="referral-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $total_users; ?></div>
                        <div class="stat-label">Total Referred Users</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $pending_withdrawals; ?></div>
                        <div class="stat-label">Pending Withdrawals</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $unread_count; ?></div>
                        <div class="stat-label">Unread Notifications</div>
                    </div>
                </div>
                
              
            </div>
        <?php endif; ?>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Phone Number</th>
                        <th>Wallet Address</th>
                        <th>Role</th>
                        <th>Balance</th>
                        <th>Daily Earning Limit</th>
                        <th>Daily Task Limit</th>
                        <th>Today's Progress</th>
                        <th>Today's Earned</th>
                        <th>Referrals</th>
                        <th>Commission</th>
                        <th>Parent</th>
                        <th>Referral Level</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="16" style="text-align: center; padding: 40px; color: #6c757d;">
                                <?php if ($current_user['role'] === 'sub_admin'): ?>
                                    No users found in your referral network. Share your referral codes to get started!
                                <?php else: ?>
                                    No users found.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): 
                            // Get progress data from user_daily_progress table (same as profile.php)
                            $completed_today = $user['completed_tasks_today'] ?? 0;
                            $todays_earned = $user['todays_earned'] ?? 0;
                            $current_task = $user['current_task_index'] ?? 0;
                            $last_reset_date = $user['last_reset_date'] ?? null;
                            
                            $daily_limit = $user['daily_earning_limit'] ?? 0;
                            $daily_task_limit = $user['daily_task_limit'] ?? NULL;
                            
                            // If daily_task_limit is NULL, user has no limit (new users)
                            $display_task_limit = is_null($daily_task_limit) ? 'No Limit' : $daily_task_limit;
                            $tasks_completed_out_of = is_null($daily_task_limit) ? $completed_today : $completed_today . '/' . $daily_task_limit;
                            
                            // Calculate percentage if limit is set
                            $percentage = 0;
                            if (!is_null($daily_task_limit) && $daily_task_limit > 0) {
                                $percentage = round(($completed_today / $daily_task_limit) * 100);
                            }
                            
                            $limit_status = is_null($daily_task_limit) ? 'no-limit-warning' : 'limit-set';
                            $limit_status_text = is_null($daily_task_limit) ? 'LIMIT NOT SET' : 'LIMIT SET';
                            
                            // Calculate referral level for sub_admin
                            $referral_level = '';
                            if ($current_user['role'] === 'sub_admin') {
                                $referral_level = getReferralLevel($pdo, $user['id'], $_SESSION['user_id']);
                            }
                        ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td>
                                    <div class="user-info">
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                        <?php if (is_null($daily_task_limit)): ?>
                                            <span class="<?php echo $limit_status; ?>"><?php echo $limit_status_text; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="phone-number"><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($user['wallet_address'])): ?>
                                        <span class="wallet-address">
                                            <?php echo htmlspecialchars($user['wallet_address']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-wallet">Not Set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                    </span>
                                </td>
                                <td class="balance-cell">$<?php echo format_balance($user['balance']); ?></td>
                                <td>
                                    <form method="POST" class="earning-limit-form">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <input type="number" name="daily_earning_limit" value="<?php echo $daily_limit; ?>" 
                                               class="earning-limit-input" step="0.01" min="0" max="1000">
                                        <button type="submit" name="update_earning_limit" class="btn btn-secondary btn-sm">Update</button>
                                    </form>
                                </td>
                                <td>
                                    <div class="task-limit-container">
                                        <form method="POST" class="task-limit-form" onsubmit="return validateTaskLimit(this)">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="number" name="daily_task_limit" value="<?php echo $daily_task_limit ?? ''; ?>" 
                                                   class="task-limit-input" min="1" max="40" placeholder="Set Limit" required
                                                   title="Daily task limit must be between 1 and 40">
                                            <div class="task-limit-buttons">
                                                <button type="submit" name="update_task_limit" class="btn btn-secondary btn-sm">
                                                    <?php echo is_null($daily_task_limit) ? 'Set Limit' : 'Update Limit'; ?>
                                                </button>
                                                <?php if (!is_null($daily_task_limit)): ?>
                                                    <button type="button" onclick="removeTaskLimit(<?php echo $user['id']; ?>)" class="btn btn-danger btn-sm">Remove Limit</button>
                                                <?php endif; ?>
                                                <a href="special_tasks_position.php?user_id=<?php echo $user['id']; ?>" 
                                                   class="btn-special">Special Tasks</a>
                                            </div>
                                        </form>
                                        <?php if (is_null($daily_task_limit)): ?>
                                            <div class="no-limit-warning" style="margin-top: 5px;">
                                                ⚠️ User cannot do tasks until limit is set!
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="daily-progress">
                                        <div class="progress-item">
                                            <span class="progress-label">Completed:</span>
                                            <span class="progress-value">
                                                <?php echo $tasks_completed_out_of; ?>
                                                <?php if (!is_null($daily_task_limit) && $daily_task_limit > 0): ?>
                                                    <span class="progress-percentage"><?php echo $percentage; ?>%</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                  
                                    </div>
                                </td>
                                <td class="balance-cell">$<?php echo format_balance($todays_earned); ?></td>
                                <td>
                                    <span class="referral-count"><?php echo $user['referral_count']; ?></span>
                                </td>
                                <td class="commission-cell">$<?php echo format_balance($user['total_commission']); ?></td>
                                <td>
                                    <?php if ($user['parent_username']): ?>
                                        <span class="parent-user"><?php echo htmlspecialchars($user['parent_username']); ?></span>
                                    <?php else: ?>
                                        <span class="no-parent">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($current_user['role'] === 'sub_admin' && $referral_level !== ''): ?>
                                        <?php
                                        $level_class = 'referral-level ';
                                        if ($referral_level == 0) {
                                            $level_class .= 'level-0';
                                        } elseif ($referral_level == 1) {
                                            $level_class .= 'level-1';
                                        } elseif ($referral_level == 2) {
                                            $level_class .= 'level-2';
                                        } elseif ($referral_level == 3) {
                                            $level_class .= 'level-3';
                                        } else {
                                            $level_class .= 'level-4-plus';
                                        }
                                        ?>
                                        <span class="<?php echo $level_class; ?>">
                                            Level <?php echo $referral_level; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="no-level">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="join-date"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="create_user.php?edit=<?php echo $user['id']; ?>" class="btn btn-primary btn-sm">Edit</a>
                                        <button onclick="openBalanceModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', <?php echo $user['balance']; ?>)" 
                                                class="btn btn-secondary btn-sm">Adjust Balance</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" class="btn btn-secondary btn-pagination">Previous</a>
                <?php endif; ?>
                
                <span class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="btn btn-secondary btn-pagination">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
    
    <!-- Balance Adjustment Modal -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeBalanceModal()">&times;</span>
            <h3>Adjust User Balance</h3>
            <form id="balanceForm" method="POST">
                <input type="hidden" name="user_id" id="adjust_user_id">
                <input type="hidden" name="adjust_balance" value="1">
                
                <div class="form-group">
                    <label for="current_balance">Current Balance:</label>
                    <input type="text" id="current_balance" readonly class="balance-display">
                </div>
                
                <div class="form-group">
                    <label for="balance_change">Balance Change:</label>
                    <input type="number" id="balance_change" name="balance_change" step="0.01" required 
                           placeholder="Positive to add, negative to deduct">
                    <small>Enter positive amount to add funds, negative amount to deduct funds</small>
                </div>
                
                <div class="form-group">
                    <label for="new_balance">New Balance:</label>
                    <input type="text" id="new_balance" readonly class="balance-display">
                </div>
                
                <div class="form-group">
                    <label for="reason">Reason:</label>
                    <textarea id="reason" name="reason" required placeholder="Enter reason for balance adjustment"></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Confirm Adjustment</button>
                    <button type="button" class="btn btn-secondary" onclick="closeBalanceModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>

<script>
    function openBalanceModal(userId, username, currentBalance) {
        document.getElementById('adjust_user_id').value = userId;
        document.getElementById('current_balance').value = '$' + parseFloat(currentBalance).toFixed(2);
        document.getElementById('new_balance').value = '$' + parseFloat(currentBalance).toFixed(2);
        document.getElementById('balance_change').value = '';
        document.getElementById('reason').value = '';
        
        document.getElementById('balanceModal').style.display = 'block';
        document.querySelector('.modal-content h3').textContent = 'Adjust Balance - ' + username;
    }
    
    function closeBalanceModal() {
        document.getElementById('balanceModal').style.display = 'none';
    }
    
    function removeTaskLimit(userId) {
        if (confirm('Are you sure you want to remove the daily task limit for this user? They will not be able to do any tasks until you set a limit again.')) {
            // Create a hidden form to submit the removal
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = userId;
            
            const taskLimitInput = document.createElement('input');
            taskLimitInput.type = 'hidden';
            taskLimitInput.name = 'daily_task_limit';
            taskLimitInput.value = '';
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'update_task_limit';
            submitInput.value = '1';
            
            form.appendChild(userIdInput);
            form.appendChild(taskLimitInput);
            form.appendChild(submitInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function validateTaskLimit(form) {
        const taskLimitInput = form.querySelector('input[name="daily_task_limit"]');
        const taskLimit = parseInt(taskLimitInput.value);
        
        if (isNaN(taskLimit) || taskLimit < 1 || taskLimit > 40) {
            alert('Daily task limit must be a number between 1 and 40.');
            taskLimitInput.focus();
            return false;
        }
        
        return true;
    }
    
    // Copy to clipboard function
    function copyToClipboard(elementId, button) {
        const element = document.getElementById(elementId);
        const text = element.textContent.trim();
        
        navigator.clipboard.writeText(text).then(function() {
            // Change button text and style temporarily
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-check"></i> Copied!';
            button.classList.add('copied');
            
            setTimeout(function() {
                button.innerHTML = originalText;
                button.classList.remove('copied');
            }, 2000);
        }).catch(function(err) {
            console.error('Failed to copy text: ', err);
            alert('Failed to copy text. Please try again.');
        });
    }
    
    // Calculate new balance when change amount is entered
    document.addEventListener('DOMContentLoaded', function() {
        const balanceChange = document.getElementById('balance_change');
        const currentBalance = document.getElementById('current_balance');
        const newBalance = document.getElementById('new_balance');
        
        if (balanceChange) {
            balanceChange.addEventListener('input', function() {
                const current = parseFloat(document.getElementById('current_balance').value.replace('$', '')) || 0;
                const change = parseFloat(this.value) || 0;
                const newBal = current + change;
                
                document.getElementById('new_balance').value = '$' + newBal.toFixed(2);
                
                // Color coding for positive/negative changes
                if (change > 0) {
                    newBalance.className = 'balance-display balance-change-positive';
                } else if (change < 0) {
                    newBalance.className = 'balance-display balance-change-negative';
                } else {
                    newBalance.className = 'balance-display';
                }
            });
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('balanceModal');
            if (event.target === modal) {
                closeBalanceModal();
            }
        });
    });
</script>
</body>
</html>