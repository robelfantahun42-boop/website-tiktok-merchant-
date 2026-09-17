<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login();

// Language handling
$available_languages = get_available_languages();
$default_language = 'en';

if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $available_languages)) {
    $_SESSION['language'] = $_GET['lang'];
}

$current_language = isset($_SESSION['language']) ? $_SESSION['language'] : $default_language;

$lang_file = "languages/{$current_language}.php";
if (file_exists($lang_file)) {
    $lang = require $lang_file;
} else {
    $lang = require "languages/en.php";
}

// Get current user data
$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT balance, daily_earning_limit, daily_task_limit, special_task_positions FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

$can_do_tasks = ($user['daily_task_limit'] !== NULL && $user['daily_task_limit'] > 0);

// Get user's CONTINUOUS progress
if ($can_do_tasks) {
    $progress_stmt = $pdo->prepare("SELECT * FROM user_continuous_progress WHERE user_id = ?");
    $progress_stmt->execute([$user_id]);
    $progress = $progress_stmt->fetch();

    if (!$progress) {
        initialize_continuous_progress($pdo, $user_id, $user['daily_earning_limit']);
        $progress_stmt->execute([$user_id]);
        $progress = $progress_stmt->fetch();
    }
} else {
    $progress = null;
}

$daily_task_limit = $user['daily_task_limit'] ?? 0;
$system_max_tasks = 40;
$effective_daily_limit = ($daily_task_limit > 0) ? min($daily_task_limit, $system_max_tasks) : 0;

// Get special task positions
$special_task_positions = [];
if (!empty($user['special_task_positions'])) {
    $special_task_positions = json_decode($user['special_task_positions'], true);
}

// Get user's tasks
if ($can_do_tasks) {
    $rewards_stmt = $pdo->prepare("
        SELECT utr.task_id, utr.assigned_reward, utr.task_order, utr.is_completed, 
               t.title, t.description, t.image_path, t.cost, t.reward as default_reward
        FROM user_tasks_rewards utr
        JOIN tasks t ON utr.task_id = t.id
        WHERE utr.user_id = ?
        ORDER BY utr.task_order ASC
    ");
    $rewards_stmt->execute([$user_id]);
    $user_tasks = $rewards_stmt->fetchAll();
} else {
    $user_tasks = [];
}

// Get special tasks with custom values
$special_tasks_stmt = $pdo->prepare("
    SELECT ust.position, t.*, ust.custom_reward, ust.custom_cost
    FROM user_special_tasks ust 
    JOIN tasks t ON ust.task_id = t.id 
    WHERE ust.user_id = ? 
    AND t.is_active = TRUE 
    AND ust.position BETWEEN 1 AND 40
    AND ust.assigned_by_admin = 1
    AND ust.is_active = 1
    ORDER BY ust.position ASC
");
$special_tasks_stmt->execute([$user_id]);
$user_special_tasks = $special_tasks_stmt->fetchAll();

$special_tasks_map = [];
foreach ($user_special_tasks as $special_task) {
    $special_tasks_map[$special_task['position']] = $special_task;
}

// Calculate progress
if ($can_do_tasks && $progress) {
    $completed_tasks = $progress['completed_tasks'];
    $total_tasks_available = count($user_tasks);
    $all_tasks_completed = ($completed_tasks >= $total_tasks_available && $total_tasks_available > 0);
    $task_limit_reached = ($completed_tasks >= $effective_daily_limit && $effective_daily_limit > 0 && !$all_tasks_completed);
} else {
    $completed_tasks = 0;
    $all_tasks_completed = false;
    $task_limit_reached = false;
}

// Calculate current task
$current_position = 0;
$is_special_task = false;
$has_custom_values = false;
$current_task = null;
$current_task_reward = 0;
$current_task_cost = 0;
$next_task_number = 0;
$special_task_deposit_required = false;
$required_deposit_amount = 0;
$admin_deposit_message = null;
$remaining_amount_needed = 0;
$has_pending_deposit = false;
$deposit_already_approved = false;

if ($can_do_tasks && $progress && !$all_tasks_completed && !$task_limit_reached) {
    $current_position = $progress['completed_tasks'] + 1;
    $next_task_number = $current_position;
    
    // Find current task (task_order is 0-indexed)
    foreach ($user_tasks as $task) {
        if ($task['task_order'] == $current_position - 1 && !$task['is_completed']) {
            $current_task = $task;
            $current_task_reward = $task['assigned_reward'];
            $current_task_cost = $task['cost'];
            
            // Check if this is a special task position
            if (isset($special_tasks_map[$current_position])) {
                $is_special_task = true;
                $special_task = $special_tasks_map[$current_position];
                $has_custom_values = ($special_task['custom_reward'] !== null || $special_task['custom_cost'] !== null);
                
                // Use custom values if set
                if ($special_task['custom_reward'] !== null) {
                    $current_task_reward = $special_task['custom_reward'];
                }
                if ($special_task['custom_cost'] !== null) {
                    $current_task_cost = $special_task['custom_cost'];
                }
                
                // Check deposit requirement
                if ($current_task_cost > 0) {
                    $deposit_check_stmt = $pdo->prepare("
                        SELECT * FROM special_task_deposits 
                        WHERE user_id = ? AND task_position = ? AND status = 'approved'
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $deposit_check_stmt->execute([$user_id, $current_position]);
                    $deposit_data = $deposit_check_stmt->fetch();
                    
                    if ($deposit_data) {
                        $special_task_deposit_required = false;
                        $deposit_already_approved = true;
                    } else {
                        $special_task_deposit_required = true;
                        $required_deposit_amount = $current_task_cost;
                    }
                }
            }
            break;
        }
    }
}

// Check for admin deposit messages
if ($can_do_tasks && $progress && $is_special_task && $special_task_deposit_required) {
    $admin_message_stmt = $pdo->prepare("
        SELECT remaining_amount, status 
        FROM special_task_deposits 
        WHERE user_id = ? AND task_position = ? AND status = 'pending'
        ORDER BY created_at DESC LIMIT 1
    ");
    $admin_message_stmt->execute([$user_id, $current_position]);
    $admin_message_data = $admin_message_stmt->fetch();

    if ($admin_message_data && $admin_message_data['remaining_amount'] > 0) {
        $admin_deposit_message = true;
        $remaining_amount_needed = $admin_message_data['remaining_amount'];
        $has_pending_deposit = true;
    }
}

// Handle task completion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['complete_task']) && $current_task) {
        if (!$can_do_tasks) {
            $error_message = "Your account is not authorized to complete tasks.";
        } elseif ($is_special_task && $special_task_deposit_required && !$deposit_already_approved) {
            $error_message = "Deposit required to complete this special task. Please deposit $" . number_format($required_deposit_amount, 2) . " first.";
        } elseif ($current_task['is_completed']) {
            $error_message = "This task has already been completed.";
        }
        
        if (!isset($error_message)) {
            try {
                $pdo->beginTransaction();
                
                // Calculate profit: reward for both normal AND special tasks
                $total_profit = $current_task_reward;
                $task_id = $current_task['task_id'];
                $task_order = $current_task['task_order'];
                
                // Mark task as completed
                $update_stmt = $pdo->prepare("
                    UPDATE user_tasks_rewards 
                    SET is_completed = 1, completed_at = NOW() 
                    WHERE user_id = ? AND task_id = ? AND task_order = ? AND is_completed = 0
                ");
                $update_stmt->execute([$user_id, $task_id, $task_order]);
                
                if ($update_stmt->rowCount() === 0) {
                    throw new Exception("Task already completed or not found.");
                }
                
                // Update continuous progress
                $new_completed = $progress['completed_tasks'] + 1;
                $new_earned = $progress['total_earned'] + $total_profit;
                
                $update_progress_stmt = $pdo->prepare("
                    UPDATE user_continuous_progress 
                    SET completed_tasks = ?, total_earned = ?, last_activity_date = NOW()
                    WHERE user_id = ?
                ");
                $update_progress_stmt->execute([$new_completed, $new_earned, $user_id]);
                
                // Update user balance: ADD reward for both task types
                if ($total_profit > 0) {
                    $new_balance = $user['balance'] + $total_profit;
                    $update_balance_stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
                    $update_balance_stmt->execute([$new_balance, $user_id]);
                    $user['balance'] = $new_balance;
                }
                
                $pdo->commit();
                
                header("Location: tasks_list.php?completed=true");
                exit;
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Task completion error: " . $e->getMessage());
                $error_message = "Error completing task: " . $e->getMessage();
            }
        }
    }
    
    // Handle deposit verification for the CURRENT task position
    if (isset($_POST['verify_deposit']) && isset($_POST['task_position'])) {
        $task_position = intval($_POST['task_position']);
        
        if ($is_special_task && $task_position == $current_position && $special_task_deposit_required) {
            $_SESSION['special_task_deposit'] = [
                'position' => $task_position,
                'required_amount' => $current_task_cost,
                'task_id' => $current_task['task_id'],
                'reward' => $current_task_reward,
                'return_url' => 'tasks_list.php'
            ];
            header("Location: customer_service.php");
            exit;
        } else {
            $error_message = "Invalid task position.";
        }
    }
    
    if (isset($_POST['verify_additional_deposit']) && isset($_POST['task_position'])) {
        $task_position = intval($_POST['task_position']);
        
        if ($is_special_task && $task_position == $current_position && $admin_deposit_message && $remaining_amount_needed > 0) {
            $_SESSION['special_task_deposit'] = [
                'position' => $task_position,
                'required_amount' => $remaining_amount_needed,
                'task_id' => $current_task['task_id'],
                'reward' => $current_task_reward,
                'return_url' => 'tasks_list.php',
                'is_additional' => true
            ];
            header("Location: customer_service.php");
            exit;
        } else {
            $error_message = "Invalid request for additional deposit.";
        }
    }
}

// Get user info for header
$user_info_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$user_info_stmt->execute([$user_id]);
$user_info = $user_info_stmt->fetch();
$username = $user_info['username'];

// Helper functions
function getTaskImagePath($image_path) {
    if (empty($image_path)) return null;
    if (strpos($image_path, 'http') === 0) return $image_path;
    
    $paths = [
        $image_path,
        'assets/images/tasks/' . $image_path,
        'uploads/tasks/' . $image_path,
        str_replace('../assets/images/tasks/', 'assets/images/tasks/', $image_path)
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) return $path;
    }
    return null;
}

function initialize_continuous_progress($pdo, $user_id, $daily_limit) {
    $pdo->beginTransaction();
    
    try {
        $check_stmt = $pdo->prepare("SELECT id FROM user_continuous_progress WHERE user_id = ?");
        $check_stmt->execute([$user_id]);
        if ($check_stmt->fetch()) {
            $pdo->commit();
            return;
        }
        
        $special_tasks_stmt = $pdo->prepare("
            SELECT ust.position, t.id, t.reward, t.cost, ust.custom_reward, ust.custom_cost
            FROM user_special_tasks ust 
            JOIN tasks t ON ust.task_id = t.id 
            WHERE ust.user_id = ? AND t.is_active = TRUE AND ust.is_active = 1
            ORDER BY ust.position ASC
        ");
        $special_tasks_stmt->execute([$user_id]);
        $user_special_tasks = $special_tasks_stmt->fetchAll();
        
        $special_task_count = count($user_special_tasks);
        $normal_task_count = 40 - $special_task_count;
        
        $normal_tasks_stmt = $pdo->prepare("
            SELECT id, reward, cost 
            FROM tasks 
            WHERE is_active = TRUE 
            AND (is_special_task = 0 OR is_special_task IS NULL)
            AND (is_topup_task = 0 OR is_topup_task IS NULL)
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $normal_tasks_stmt->execute([$normal_task_count]);
        $normal_tasks = $normal_tasks_stmt->fetchAll();
        
        $insert_progress = $pdo->prepare("
            INSERT INTO user_continuous_progress (user_id, completed_tasks, total_earned, last_activity_date)
            VALUES (?, 0, 0, NOW())
        ");
        $insert_progress->execute([$user_id]);
        
        // Generate rewards based on daily earning limit divided by 40 tasks
        $rewards = generate_continuous_rewards($daily_limit, $normal_task_count);
        
        $task_sequence = array_fill(0, 40, null);
        
        foreach ($user_special_tasks as $special_task) {
            $position = $special_task['position'] - 1;
            if ($position >= 0 && $position < 40) {
                $reward = $special_task['custom_reward'] !== null ? $special_task['custom_reward'] : $special_task['reward'];
                $task_sequence[$position] = [
                    'task_id' => $special_task['id'],
                    'reward' => $reward,
                    'cost' => $special_task['cost']
                ];
            }
        }
        
        $normal_index = 0;
        for ($i = 0; $i < 40 && $normal_index < count($normal_tasks); $i++) {
            if ($task_sequence[$i] === null) {
                // Assign reward from the generated rewards array for normal tasks
                $normal_reward = ($normal_index < count($rewards)) ? $rewards[$normal_index] : 0;
                $task_sequence[$i] = [
                    'task_id' => $normal_tasks[$normal_index]['id'],
                    'reward' => $normal_reward,
                    'cost' => 0
                ];
                $normal_index++;
            }
        }
        
        $insert_task = $pdo->prepare("
            INSERT INTO user_tasks_rewards (user_id, task_id, assigned_reward, task_order, is_completed)
            VALUES (?, ?, ?, ?, 0)
        ");
        
        foreach ($task_sequence as $order => $task_data) {
            if ($task_data !== null) {
                $insert_task->execute([$user_id, $task_data['task_id'], $task_data['reward'], $order]);
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error initializing progress: " . $e->getMessage());
    }
}

// Generate rewards based on daily earning limit divided by 40 tasks
function generate_continuous_rewards($daily_limit, $normal_task_count) {
    if ($normal_task_count == 0) return [];
    
    // If daily limit is 0 or negative, ALL rewards are 0
    if ($daily_limit <= 0) {
        return array_fill(0, $normal_task_count, 0);
    }
    
    // Calculate base reward: daily limit divided by 40 tasks (system max)
    $base_reward = $daily_limit / 40;
    
    // Generate rewards for normal tasks
    $rewards = array_fill(0, $normal_task_count, round($base_reward, 2));
    
    return $rewards;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo trans('tasks'); ?> - Task Website</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Roboto', sans-serif; }
        body { background: #040404; color: #ffffff; line-height: 1.6; overflow-x: hidden; }
        header { background: linear-gradient(135deg, #040404 0%, #0a0a0a 100%); color: #fff; padding: 1rem 0; border-bottom: 1px solid rgba(254, 40, 88, 0.3); position: relative; }
        .header-content { max-width: 1200px; margin: 0 auto; padding: 0 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; position: relative; }
        .logo-container { display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0; z-index: 1001; }
        .logo { height: 35px; width: auto; display: block; margin: 0; padding: 0; }
        .logo-container span { font-size: 1.5rem; font-weight: bold; color: #fe2858; margin: 0; padding: 0; line-height: 1; text-shadow: 0 0 10px rgba(254, 40, 88, 0.5); }
        header h1 { font-size: 1.8rem; color: #2af0ea; text-align: center; flex: 1; margin: 0; }
        nav { display: flex; gap: 1rem; flex-wrap: wrap; transition: all 0.3s ease; }
        nav a { color: #ffffff; text-decoration: none; padding: 0.5rem 1rem; border-radius: 5px; transition: all 0.3s ease; border: 1px solid transparent; font-weight: 500; }
        nav a:hover, nav a.active { color: #fe2858; background: rgba(254, 40, 88, 0.1); border-color: rgba(254, 40, 88, 0.3); transform: translateY(-2px); }
        .language-selector { z-index: 1001; }
        .language-selector select { padding: 0.5rem; border-radius: 5px; border: 1px solid #2af0ea; background: rgba(4, 4, 4, 0.8); color: #ffffff; cursor: pointer; transition: all 0.3s ease; font-size: 0.9rem; }
        .menu-toggle { display: none; flex-direction: column; justify-content: space-between; width: 30px; height: 21px; cursor: pointer; z-index: 1001; background: transparent; border: none; padding: 0; }
        .menu-toggle span { height: 3px; width: 100%; background-color: #ffffff; border-radius: 3px; transition: all 0.3s ease; transform-origin: center; }
        .menu-toggle.active span:nth-child(1) { transform: rotate(45deg) translate(6px, 6px); background-color: #fe2858; }
        .menu-toggle.active span:nth-child(2) { opacity: 0; transform: scale(0); }
        .menu-toggle.active span:nth-child(3) { transform: rotate(-45deg) translate(6px, -6px); background-color: #fe2858; }
        @media (max-width: 768px) { .menu-toggle { display: flex; } nav { position: fixed; top: 0; right: -100%; width: 280px; height: 100vh; background: linear-gradient(135deg, #040404 0%, #0a0a0a 100%); flex-direction: column; align-items: flex-start; justify-content: flex-start; padding-top: 80px; padding-left: 2rem; z-index: 1000; border-left: 1px solid rgba(254, 40, 88, 0.3); box-shadow: -5px 0 15px rgba(0, 0, 0, 0.5); transition: right 0.3s ease; gap: 0; } nav.active { right: 0; } nav a { display: block; width: calc(100% - 2rem); padding: 1rem; margin: 0.5rem 0; border-radius: 8px; border: 1px solid rgba(42, 240, 234, 0.2); background: rgba(4, 4, 4, 0.6); transition: all 0.3s ease; font-size: 1rem; } nav a:hover, nav a.active { background: rgba(254, 40, 88, 0.2); border-color: rgba(254, 40, 88, 0.4); transform: translateX(5px); } .language-selector { position: absolute; top: 1rem; right: 4rem; } .nav-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 999; opacity: 0; visibility: hidden; transition: all 0.3s ease; } .nav-overlay.active { opacity: 1; visibility: visible; } .header-content { padding: 0 0.5rem; } header h1 { font-size: 1.4rem; margin-left: 0.5rem; } .logo-container span { font-size: 1.3rem; } .logo { height: 30px; } }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem 1rem; }
        main { max-width: 800px; margin: 0 auto; }
        main h2 { color: #2af0ea; text-align: center; margin-bottom: 2rem; font-size: 2rem; text-shadow: 0 0 10px rgba(42, 240, 234, 0.3); }
        .balance-info { background: linear-gradient(135deg, rgba(57, 118, 132, 0.1), rgba(4, 4, 4, 0.9)); padding: 1.5rem; border-radius: 10px; margin-bottom: 2rem; border: 1px solid rgba(42, 240, 234, 0.2); text-align: center; }
        .balance-info p { margin: 0.5rem 0; font-size: 1.1rem; }
        .balance-info strong { color: #fe2858; }
        .daily-progress-info { background: rgba(4, 4, 4, 0.8); padding: 1.5rem; border-radius: 10px; margin-bottom: 2rem; border: 1px solid rgba(42, 240, 234, 0.1); text-align: center; }
        .daily-progress-info h3 { color: #2af0ea; margin-bottom: 1rem; }
        .daily-progress-info p { margin-bottom: 1rem; color: #e0e0e0; }
        .progress-bar-container { background: rgba(4, 4, 4, 0.6); border-radius: 10px; height: 20px; border: 1px solid rgba(42, 240, 234, 0.2); overflow: hidden; margin: 1rem 0; }
        .progress-bar-fill { height: 100%; border-radius: 10px; background: linear-gradient(90deg, #fe2858, #de8c9d); transition: width 0.5s ease; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: bold; }
        .error-message { background: rgba(254, 40, 88, 0.1); color: #fe2858; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; border: 1px solid rgba(254, 40, 88, 0.3); text-align: center; }
        .no-tasks-message { text-align: center; color: #888; font-style: italic; padding: 3rem; font-size: 1.1rem; }
        .completed-message { background: linear-gradient(135deg, rgba(42, 240, 234, 0.1), rgba(4, 4, 4, 0.9)); padding: 2rem; border-radius: 10px; text-align: center; border: 1px solid rgba(42, 240, 234, 0.3); margin-bottom: 2rem; }
        .completed-message h3 { color: #2af0ea; margin-bottom: 1rem; }
        .completed-message p { color: #e0e0e0; margin: 0.5rem 0; }
        .auto-reload-message { background: linear-gradient(135deg, #3498db, #2980b9); color: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 0.7; } 50% { opacity: 1; } 100% { opacity: 0.7; } }
        .continuous-progress-info { background: linear-gradient(135deg, rgba(155, 89, 182, 0.2), rgba(142, 68, 173, 0.1)); padding: 12px; border-radius: 8px; margin-bottom: 20px; border: 1px solid rgba(155, 89, 182, 0.3); text-align: center; font-size: 0.9rem; }
        .total-earnings { background: linear-gradient(135deg, rgba(52, 152, 219, 0.2), rgba(41, 128, 185, 0.1)); padding: 12px; border-radius: 8px; margin-bottom: 20px; border: 1px solid rgba(52, 152, 219, 0.3); text-align: center; }
        .task-card { background: rgba(4, 4, 4, 0.8); border-radius: 15px; overflow: hidden; margin-bottom: 2rem; border: 1px solid rgba(42, 240, 234, 0.1); box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3); transition: all 0.3s ease; position: relative; }
        .task-card:hover { border-color: rgba(42, 240, 234, 0.3); box-shadow: 0 8px 25px rgba(42, 240, 234, 0.1); transform: translateY(-2px); }
        .task-card-image { width: 100%; height: 200px; object-fit: cover; border-bottom: 1px solid rgba(42, 240, 234, 0.1); }
        .no-image-placeholder { width: 100%; height: 200px; background: linear-gradient(135deg, rgba(57, 118, 132, 0.2), rgba(254, 40, 88, 0.1)); display: flex; align-items: center; justify-content: center; color: #2af0ea; font-size: 1.2rem; font-weight: bold; border-bottom: 1px solid rgba(42, 240, 234, 0.1); }
        .task-card-content { padding: 1.5rem; }
        .task-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; }
        .task-card-title { color: #2af0ea; font-size: 1.3rem; margin: 0; flex: 1; }
        .task-card-badges { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .task-number-badge { background: linear-gradient(135deg, #2af0ea, #fe2858); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; }
        .custom-values-badge { background: linear-gradient(135deg, #e67e22, #f39c12); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; }
        .task-card-description { color: #e0e0e0; margin-bottom: 1.5rem; line-height: 1.6; }
        .task-card-details { background: rgba(4, 4, 4, 0.6); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid rgba(42, 240, 234, 0.1); }
        .task-detail-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; padding: 0.25rem 0; }
        .task-detail-row:last-child { margin-bottom: 0; }
        .task-detail-row span:first-child { color: #e0e0e0; }
        .task-reward { color: #2af0ea; font-weight: bold; font-size: 1.1rem; }
        .task-cost { color: #fe2858; font-weight: bold; }
        .deposit-required-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(4, 4, 4, 0.95); display: flex; flex-direction: column; justify-content: center; align-items: center; z-index: 10; border-radius: 12px; padding: 15px; overflow-y: auto; }
        .deposit-required-content { text-align: center; padding: 20px; max-width: 100%; width: 100%; }
        .deposit-icon { font-size: 3rem; color: #e67e22; margin-bottom: 15px; }
        .deposit-title { color: #e67e22; font-size: 1.5rem; margin-bottom: 10px; font-weight: bold; }
        .deposit-amount { font-size: 2rem; color: #2af0ea; font-weight: bold; margin: 15px 0; }
        .deposit-instructions { background: rgba(230, 126, 34, 0.1); padding: 12px; border-radius: 8px; border: 1px solid rgba(230, 126, 34, 0.3); margin: 15px 0; text-align: left; }
        .deposit-steps { list-style: none; padding: 0; }
        .deposit-step { padding: 8px 0; display: flex; align-items: center; gap: 10px; }
        .deposit-step i { color: #e67e22; width: 20px; }
        .deposit-action-buttons { margin-top: 20px; display: flex; flex-direction: column; gap: 12px; align-items: center; }
        .deposit-btn { background: linear-gradient(135deg, #e67e22, #d35400); color: white; border: none; padding: 12px 20px; border-radius: 8px; font-size: 1rem; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; width: 100%; max-width: 300px; justify-content: center; }
        .deposit-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(230, 126, 34, 0.3); }
        .deposit-btn-secondary { background: linear-gradient(135deg, #3498db, #2980b9); }
        .task-complete-btn { background: linear-gradient(135deg, #fe2858, #de8c9d); color: #ffffff; border: none; padding: 15px 30px; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer; width: 100%; transition: all 0.3s ease; }
        .task-complete-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(254, 40, 88, 0.3); }
        .task-complete-btn:disabled { background: #666; cursor: not-allowed; }
        footer { text-align: center; margin-top: 3rem; padding: 2rem 1rem; border-top: 1px solid rgba(42, 240, 234, 0.1); color: #888; }
        .fullscreen-loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(4, 4, 4, 0.98), rgba(57, 118, 132, 0.95)); display: none; justify-content: center; align-items: center; flex-direction: column; z-index: 9999; backdrop-filter: blur(10px); }
        .fullscreen-loading-overlay.active { display: flex; }
        .fullscreen-spinner { width: 80px; height: 80px; border: 5px solid rgba(42, 240, 234, 0.3); border-top: 5px solid #2af0ea; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 30px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .fullscreen-loading-title { font-size: 2rem; font-weight: bold; margin-bottom: 20px; color: #2af0ea; }
        .fullscreen-loading-text { font-size: 1.2rem; margin-bottom: 30px; color: #e0e0e0; }
        body.menu-open { overflow: hidden; }
        .future-balance-info { background: linear-gradient(135deg, rgba(46, 204, 113, 0.2), rgba(39, 174, 96, 0.1)); padding: 12px; border-radius: 8px; margin: 15px 0; border: 1px solid rgba(46, 204, 113, 0.3); text-align: center; }
        .future-balance-title { color: #2ecc71; font-size: 1.1rem; margin-bottom: 8px; font-weight: bold; }
        .future-balance-amount { font-size: 1.6rem; color: #2af0ea; font-weight: bold; margin: 8px 0; }
        .balance-breakdown { display: flex; flex-direction: column; gap: 10px; margin: 12px 0; }
        .balance-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
        .balance-item { text-align: center; flex: 1; min-width: 80px; }
        .balance-label { font-size: 0.8rem; color: #b0b0b0; }
        .balance-value { font-size: 0.95rem; font-weight: bold; }
        .balance-current { color: #3498db; }
        .balance-deposit { color: #e67e22; }
        .balance-reward { color: #2ecc71; }
        .balance-future { color: #2af0ea; }
        .deposit-note { font-size: 0.85rem; color: #b0b0b0; margin-top: 12px; font-style: italic; }
        .pending-deposit-info { background: rgba(52, 152, 219, 0.1); padding: 12px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3); margin: 12px 0; text-align: center; }
        .admin-message-alert { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: center; }
        .no-reward-badge { background: linear-gradient(135deg, #6c757d, #495057); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; }
        .deposit-approved-badge { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; }
        .balance-arrow { display: flex; align-items: center; color: #666; font-size: 0.9rem; padding: 0 5px; }
        .reward-badge { background: linear-gradient(135deg, #2af0ea, #fe2858); color: white; padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; white-space: nowrap; }
        .daily-limit-info { background: linear-gradient(135deg, rgba(155, 89, 182, 0.2), rgba(142, 68, 173, 0.1)); padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid rgba(155, 89, 182, 0.3); text-align: center; }
        .daily-limit-amount { font-size: 1.5rem; font-weight: bold; color: #9b59b6; margin: 10px 0; }
        .daily-limit-explanation { font-size: 0.9rem; color: #e0e0e0; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="fullscreen-loading-overlay" id="fullscreenLoadingOverlay">
        <div class="fullscreen-loading-content">
            <div class="fullscreen-spinner"></div>
            <h2 class="fullscreen-loading-title">Processing Your Order</h2>
            <p class="fullscreen-loading-text">Please wait while we complete your transaction...</p>
        </div>
    </div>

    <header>
        <div class="header-content">
            <div class="logo-container">
                <img src="assets/images/logo/tiktoklogo.png" alt="TikTask Hub Logo" class="logo">
                <span>TIKTOK SHOP</span>
            </div>
            <h1><?php echo trans('welcome', ['name' => htmlspecialchars($username)]); ?></h1>
            <button class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <nav id="mainNav">
                <a href="dashboard.php"><?php echo trans('Home'); ?></a>
                <a href="tasks_list.php" class="active"><?php echo trans('Orders'); ?></a>
                <a href="upload_payment.php"><?php echo trans('upload_proof'); ?></a>
                <a href="profile.php"><?php echo trans('profile'); ?></a>
                <a href="logout.php"><?php echo trans('logout'); ?></a>
            </nav>
            <div class="language-selector">
                <select onchange="changeLanguage(this.value)">
                    <option value="en" <?php echo $current_language == 'en' ? 'selected' : ''; ?>>English</option>
                    <option value="es" <?php echo $current_language == 'es' ? 'selected' : ''; ?>>Español</option>
                    <option value="ur" <?php echo $current_language == 'ur' ? 'selected' : ''; ?>>اردو</option>
                    <option value="ru" <?php echo $current_language == 'ru' ? 'selected' : ''; ?>>Русский</option>
                </select>
            </div>
            <div class="nav-overlay" id="navOverlay"></div>
        </div>
    </header>

    <div class="container" id="main-content">
        <main>
            <h2>Your Orders</h2>
            
            <!-- Daily Limit Information -->
            <div class="daily-limit-info">
                <i class="fas fa-chart-line"></i>
                <strong>Daily Earning Limit:</strong>
                <div class="daily-limit-amount">
                    $<?php echo number_format($user['daily_earning_limit'] ?? 0, 2); ?>
                </div>
                <div class="daily-limit-explanation">
                    You earn $<?php echo number_format(($user['daily_earning_limit'] ?? 0) / 40, 2); ?> per order 
                    (<?php echo number_format($user['daily_earning_limit'] ?? 0, 2); ?> ÷ 40 orders)
                </div>
            </div>
            
            <div class="auto-reload-message" id="autoReloadMessage" style="display: none;">
                <i class="fas fa-sync-alt fa-spin"></i> 
                <strong>Loading next order...</strong> Please wait while we prepare your next order.
            </div>
          
            <div class="continuous-progress-info">
                <i class="fas fa-chart-line"></i> 
                <strong>Continuous Progress System:</strong> Your progress is saved. You can leave and come back anytime - you'll continue from where you left off!
            </div>
            
            <div class="completion-message" id="completionMessage" style="display: none;">
                <h3>✅ Order Completed Successfully!</h3>
                <p>Your balance has been updated and you're now viewing the next order.</p>
            </div>
            
            <div class="total-earnings">
                <i class="fas fa-coins"></i> 
                <strong>Total Earned from Orders:</strong> $<?php echo $progress ? number_format($progress['total_earned'], 2) : '0.00'; ?>
            </div>
            
            <?php if (!$can_do_tasks): ?>
                <div style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; padding: 30px; border-radius: 12px; text-align: center; margin: 20px 0;">
                    <i class="fas fa-ban" style="font-size: 4rem; margin-bottom: 20px; display: block;"></i>
                    <h3>Order Access Restricted</h3>
                    <p>Your account currently does not have a daily order limit set.</p>
                    <p>Please contact the administrator to set your daily task limit.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($can_do_tasks && $progress): ?>
                <div class="daily-progress-info">
                    <h3>Your Progress</h3>
                    <p><strong>Completed:</strong> <?php echo $completed_tasks; ?> / <?php echo $total_tasks_available; ?> orders</p>
                    <?php if ($task_limit_reached): ?>
                        <div class="completed-message" style="margin-top: 10px; padding: 10px;">
                            <i class="fas fa-check-circle"></i> Daily limit reached! Contact admin to reset your tasks.
                        </div>
                    <?php elseif ($all_tasks_completed): ?>
                        <div class="completed-message" style="margin-top: 10px; padding: 10px;">
                            <i class="fas fa-trophy"></i> Congratulations! You've completed all orders! Contact admin to reset and get more orders.
                        </div>
                    <?php else: ?>
                        <p><strong>Current Order:</strong> Order <?php echo $next_task_number; ?> of <?php echo $total_tasks_available; ?>
                        <?php if ($is_special_task): ?> (Special Order)<?php else: ?> (Regular Order)<?php endif; ?>
                        </p>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width: <?php echo ($completed_tasks / $total_tasks_available) * 100; ?>%;">
                                <?php echo round(($completed_tasks / $total_tasks_available) * 100); ?>%
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="balance-info">
                <p><strong>Your Current Balance:</strong> $<?php echo number_format($user['balance'], 2); ?></p>
                <?php if ($can_do_tasks && $current_task && !$all_tasks_completed && !$task_limit_reached): ?>
                    <?php 
                    $preview_future_balance = $user['balance'];
                    if ($is_special_task && $special_task_deposit_required) {
                        $preview_future_balance += $required_deposit_amount;
                    }
                    $preview_future_balance += $current_task_reward;
                    ?>
                    <p style="margin-top: 10px; color: #2ecc71;">
                        <i class="fas fa-chart-line"></i> 
                        <strong>Future Balance after this order:</strong> $<?php echo number_format($preview_future_balance, 2); ?>
                        <?php if ($is_special_task && $special_task_deposit_required): ?>
                            <span style="font-size: 0.9em; color: #b0b0b0; display: block;">
                                (Includes deposit: $<?php echo number_format($required_deposit_amount, 2); ?> + reward: $<?php echo number_format($current_task_reward, 2); ?>)
                            </span>
                        <?php elseif (!$is_special_task && $current_task_reward > 0): ?>
                            <span style="font-size: 0.9em; color: #b0b0b0; display: block;">
                                (Reward: $<?php echo number_format($current_task_reward, 2); ?>)
                            </span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <?php if ($all_tasks_completed && $can_do_tasks): ?>
                <div class="completed-message">
                    <h3>🎉 Congratulations!</h3>
                    <p>You have completed all available orders!</p>
                    <p>You've earned a total of $<?php echo number_format($progress['total_earned'], 2); ?> from orders.</p>
                    <p><strong>Contact customer service to reset your orders and get more!</strong></p>
                </div>
            <?php endif; ?>
            
            <?php if ($task_limit_reached && $can_do_tasks && !$all_tasks_completed): ?>
                <div class="completed-message">
                    <h3>🎉 Daily Limit Reached!</h3>
                    <p>You have completed your daily limit of <?php echo $effective_daily_limit; ?> orders.</p>
                    <p>Contact admin to reset your orders for more orders!</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <?php if ($current_task && !$all_tasks_completed && $can_do_tasks && !$task_limit_reached): ?>
                <div class="task-card" id="currentTask">
                    <?php if ($is_special_task && $special_task_deposit_required): ?>
                        <div class="deposit-required-overlay">
                            <div class="deposit-required-content">
                                <div class="deposit-icon"><i class="fas fa-lock"></i></div>
                                <h2 class="deposit-title">Deposit Required</h2>
                                <p>This special order requires a deposit to continue.</p>
                                <div class="deposit-amount">$<?php echo number_format($required_deposit_amount, 2); ?></div>

                                <?php if ($admin_deposit_message && $remaining_amount_needed > 0): ?>
                                    <div class="admin-message-alert">
                                        <div class="admin-message-content">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <strong>Admin Message:</strong> You need to deposit additional $<?php echo number_format($remaining_amount_needed, 2); ?> to proceed.
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php 
                                $future_balance = $user['balance'] + $required_deposit_amount + $current_task_reward;
                                ?>
                                <div class="future-balance-info">
                                    <div class="future-balance-title"><i class="fas fa-chart-line"></i> After Completing This Order:</div>
                                    <div class="future-balance-amount">$<?php echo number_format($future_balance, 2); ?></div>
                                    <div class="balance-breakdown">
                                        <div class="balance-row">
                                            <div class="balance-item">
                                                <div class="balance-label">Current Balance</div>
                                                <div class="balance-value balance-current">$<?php echo number_format($user['balance'], 2); ?></div>
                                            </div>
                                            <div class="balance-arrow"><i class="fas fa-plus"></i></div>
                                            <div class="balance-item">
                                                <div class="balance-label">Deposit</div>
                                                <div class="balance-value balance-deposit">$<?php echo number_format($required_deposit_amount, 2); ?></div>
                                            </div>
                                            <div class="balance-arrow"><i class="fas fa-plus"></i></div>
                                            <div class="balance-item">
                                                <div class="balance-label">Reward</div>
                                                <div class="balance-value balance-reward">$<?php echo number_format($current_task_reward, 2); ?></div>
                                            </div>
                                            <div class="balance-arrow"><i class="fas fa-equals"></i></div>
                                            <div class="balance-item">
                                                <div class="balance-label">Future Balance</div>
                                                <div class="balance-value balance-future">$<?php echo number_format($future_balance, 2); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="deposit-note">
                                        <i class="fas fa-info-circle"></i> 
                                        <strong>Note:</strong> Your deposit of $<?php echo number_format($required_deposit_amount, 2); ?> will be added to your balance after approval.
                                    </div>
                                </div>
                                
                                <div class="deposit-instructions">
                                    <h4>How to proceed:</h4>
                                    <ul class="deposit-steps">
                                        <li class="deposit-step"><i class="fas fa-arrow-right"></i> <span>Make a deposit of <strong>$<?php echo number_format($required_deposit_amount, 2); ?></strong></span></li>
                                        <li class="deposit-step"><i class="fas fa-arrow-right"></i> <span>Upload your payment proof for approval</span></li>
                                        <li class="deposit-step"><i class="fas fa-arrow-right"></i> <span>Wait for customer service to verify</span></li>
                                        <li class="deposit-step"><i class="fas fa-arrow-right"></i> <span>Complete the order and earn your reward</span></li>
                                    </ul>
                                </div>

                                <?php
                                $pending_deposit_stmt = $pdo->prepare("SELECT * FROM special_task_deposits WHERE user_id = ? AND task_position = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                                $pending_deposit_stmt->execute([$user_id, $current_position]);
                                $pending_deposit = $pending_deposit_stmt->fetch();
                                ?>

                                <?php if ($pending_deposit): ?>
                                    <div class="pending-deposit-info">
                                        <i class="fas fa-clock"></i>
                                        <h4>Deposit Pending Approval</h4>
                                        <p>Your deposit of <strong>$<?php echo number_format($pending_deposit['required_amount'], 2); ?></strong> is waiting for customer service approval.</p>
                                        <p>Please wait while we verify your payment.</p>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="deposit-action-buttons">
                                    <!-- UPLOAD DEPOSIT PROOF BUTTON - ALWAYS VISIBLE WHEN DEPOSIT REQUIRED -->
                                    <form method="POST" style="width: 100%; max-width: 300px;">
                                        <input type="hidden" name="task_position" value="<?php echo $current_position; ?>">
                                        <button type="submit" name="verify_deposit" class="deposit-btn">
                                            <i class="fas fa-upload"></i> Upload Deposit Proof
                                        </button>
                                    </form>
                                    
                                    <!-- Additional deposit button (only for admin messages) -->
                                    <?php if ($admin_deposit_message && $remaining_amount_needed > 0): ?>
                                        <form method="POST" style="width: 100%; max-width: 300px;">
                                            <input type="hidden" name="task_position" value="<?php echo $current_position; ?>">
                                            <button type="submit" name="verify_additional_deposit" class="deposit-btn" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                                                <i class="fas fa-upload"></i> Upload Additional $<?php echo number_format($remaining_amount_needed, 2); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <a href="dashboard.php" class="deposit-btn deposit-btn-secondary">
                                        <i class="fas fa-home"></i> Return to Home
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php 
                    $task_image_path = getTaskImagePath($current_task['image_path']);
                    if ($task_image_path && file_exists($task_image_path)): ?>
                        <img src="<?php echo htmlspecialchars($task_image_path); ?>" alt="Task Image" class="task-card-image">
                    <?php else: ?>
                        <div class="no-image-placeholder">
                            <div>
                                <i class="fas fa-tasks" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                                <h3><?php echo htmlspecialchars($current_task['title']); ?></h3>
                                <p>No image available</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="task-card-content">
                        <div class="task-card-header">
                            <h3 class="task-card-title"><?php echo htmlspecialchars($current_task['title']); ?></h3>
                            <div class="task-card-badges">
                                <?php if ($is_special_task): ?>
                                    <span class="task-number-badge">Order <?php echo $next_task_number; ?> of <?php echo $total_tasks_available; ?></span>
                                    <?php if ($deposit_already_approved): ?>
                                        <span class="deposit-approved-badge">✅ Deposit Verified</span>
                                    <?php endif; ?>
                                    <span class="custom-values-badge">⭐ Special Order - Earns Reward</span>
                                <?php else: ?>
                                    <span class="reward-badge">💰 Earns $<?php echo number_format($current_task_reward, 2); ?></span>
                                <?php endif; ?>
                                <?php if ($is_special_task && $has_custom_values): ?>
                                    <span class="custom-values-badge">Custom Values</span>
                                <?php endif; ?>
                                <?php if ($special_task_deposit_required): ?>
                                    <span class="custom-values-badge" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">Deposit Required</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="task-card-description"><?php echo htmlspecialchars($current_task['description']); ?></p>
                        <div class="task-card-details">
                            <?php if ($current_task_reward > 0): ?>
                                <div class="task-detail-row">
                                    <span><strong>Reward:</strong></span>
                                    <span class="task-reward">+ $<?php echo number_format($current_task_reward, 2); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_special_task && $current_task_cost > 0): ?>
                                <div class="task-detail-row">
                                    <span><strong>Required Deposit:</strong></span>
                                    <span class="task-cost">$<?php echo number_format($current_task_cost, 2); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_special_task && $current_task_cost > 0 && !$special_task_deposit_required): ?>
                                <div class="deposit-note" style="margin-top: 10px; padding: 10px; background: rgba(46, 204, 113, 0.1); border-radius: 5px;">
                                    <i class="fas fa-check-circle"></i> <strong>Deposit already verified!</strong> You can complete this order now.
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_special_task && $current_task_cost > 0 && $special_task_deposit_required): ?>
                                <div class="deposit-note" style="margin-top: 10px; padding: 10px; background: rgba(230, 126, 34, 0.1); border-radius: 5px;">
                                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> This special order requires a deposit. Click "Upload Deposit Proof" to proceed.
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!$is_special_task && $current_task_reward > 0): ?>
                                <div class="deposit-note" style="margin-top: 10px; padding: 10px; background: rgba(46, 204, 113, 0.1); border-radius: 5px;">
                                    <i class="fas fa-gift"></i> <strong>Reward:</strong> Complete this order to earn $<?php echo number_format($current_task_reward, 2); ?>!
                                </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST" class="task-form" id="taskForm">
                            <button type="submit" name="complete_task" class="task-complete-btn" <?php echo ($special_task_deposit_required) ? 'disabled' : ''; ?> onclick="showFullscreenLoading()">
                                <?php if ($special_task_deposit_required): ?>
                                    Deposit Required to Continue
                                <?php elseif ($current_task_reward > 0): ?>
                                    Complete Order & Earn $<?php echo number_format($current_task_reward, 2); ?> Reward
                                <?php else: ?>
                                    Complete Order
                                <?php endif; ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php elseif (!$all_tasks_completed && $can_do_tasks && !$task_limit_reached): ?>
                <div style="text-align: center; padding: 3rem;">
                    <h3>No Orders Available</h3>
                    <p>There are no orders available for you at the moment. Please check back later.</p>
                </div>
            <?php endif; ?>
        </main>
        <footer>
            <p>&copy; <?php echo date('Y'); ?> TikTok Shop. All rights reserved.</p>
        </footer>
    </div>

    <script>
        const menuToggle = document.getElementById('menuToggle');
        const mainNav = document.getElementById('mainNav');
        const navOverlay = document.getElementById('navOverlay');
        if (menuToggle && mainNav && navOverlay) {
            menuToggle.addEventListener('click', function() {
                menuToggle.classList.toggle('active');
                mainNav.classList.toggle('active');
                navOverlay.classList.toggle('active');
                document.body.classList.toggle('menu-open');
            });
            navOverlay.addEventListener('click', function() {
                menuToggle.classList.remove('active');
                mainNav.classList.remove('active');
                navOverlay.classList.remove('active');
                document.body.classList.remove('menu-open');
            });
        }
        function showFullscreenLoading() {
            document.getElementById('fullscreenLoadingOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
            const autoMsg = document.getElementById('autoReloadMessage');
            if(autoMsg) autoMsg.style.display = 'block';
        }
        function changeLanguage(lang) {
            window.location.href = 'tasks_list.php?lang=' + lang;
        }
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('completed') === 'true') {
                const msg = document.getElementById('completionMessage');
                if (msg) msg.style.display = 'block';
                setTimeout(() => { if(msg) msg.style.display = 'none'; }, 5000);
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
    </script>
</body>
</html>