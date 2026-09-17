<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle test actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['simulate_next_day'])) {
        // Simulate next day by modifying last_activity_date
        $stmt = $pdo->prepare("
            UPDATE user_continuous_progress 
            SET last_activity_date = DATE_SUB(NOW(), INTERVAL 1 DAY)
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $message = "✅ Simulated next day! Last activity date set to yesterday.";
        
    } elseif (isset($_POST['simulate_week_later'])) {
        // Simulate one week later
        $stmt = $pdo->prepare("
            UPDATE user_continuous_progress 
            SET last_activity_date = DATE_SUB(NOW(), INTERVAL 7 DAY)
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $message = "✅ Simulated one week later! Last activity date set to 7 days ago.";
        
    } elseif (isset($_POST['simulate_month_later'])) {
        // Simulate one month later
        $stmt = $pdo->prepare("
            UPDATE user_continuous_progress 
            SET last_activity_date = DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        $message = "✅ Simulated one month later! Last activity date set to 30 days ago.";
        
    } elseif (isset($_POST['reset_progress'])) {
        // Reset progress for testing
        $pdo->beginTransaction();
        try {
            $delete_progress = $pdo->prepare("DELETE FROM user_continuous_progress WHERE user_id = ?");
            $delete_progress->execute([$user_id]);
            
            $delete_tasks = $pdo->prepare("DELETE FROM user_tasks_rewards WHERE user_id = ?");
            $delete_tasks->execute([$user_id]);
            
            $pdo->commit();
            $message = "🔄 Progress has been reset! You'll start from task 1 when you go to tasks page.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error resetting: " . $e->getMessage();
        }
        
    } elseif (isset($_POST['complete_several_tasks'])) {
        // Complete several tasks automatically for testing
        $num_tasks = (int)$_POST['num_tasks'];
        
        // Get current progress
        $progress_stmt = $pdo->prepare("SELECT * FROM user_continuous_progress WHERE user_id = ?");
        $progress_stmt->execute([$user_id]);
        $progress = $progress_stmt->fetch();
        
        if ($progress) {
            // Get incomplete tasks
            $tasks_stmt = $pdo->prepare("
                SELECT * FROM user_tasks_rewards 
                WHERE user_id = ? AND is_completed = 0 
                ORDER BY task_order ASC 
                LIMIT ?
            ");
            $tasks_stmt->execute([$user_id, $num_tasks]);
            $tasks_to_complete = $tasks_stmt->fetchAll();
            
            $completed_count = 0;
            $total_reward = 0;
            
            foreach ($tasks_to_complete as $task) {
                $update_stmt = $pdo->prepare("
                    UPDATE user_tasks_rewards 
                    SET is_completed = 1, completed_at = NOW() 
                    WHERE id = ?
                ");
                $update_stmt->execute([$task['id']]);
                
                $total_reward += $task['assigned_reward'];
                $completed_count++;
            }
            
            // Update progress
            $new_completed = $progress['completed_tasks'] + $completed_count;
            $new_earned = $progress['total_earned'] + $total_reward;
            
            $update_progress = $pdo->prepare("
                UPDATE user_continuous_progress 
                SET completed_tasks = ?, total_earned = ?, last_activity_date = NOW()
                WHERE user_id = ?
            ");
            $update_progress->execute([$new_completed, $new_earned, $user_id]);
            
            // Update user balance
            $user_stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
            
            $new_balance = $user['balance'] + $total_reward;
            $update_balance = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $update_balance->execute([$new_balance, $user_id]);
            
            $message = "✅ Completed $completed_count tasks automatically! Earned: $" . format_balance($total_reward);
        } else {
            $error = "No progress found. Please visit tasks page first to initialize.";
        }
    }
}

// Get current user progress
$progress_stmt = $pdo->prepare("
    SELECT cp.*, 
           COUNT(utr.id) as total_tasks,
           SUM(CASE WHEN utr.is_completed = 1 THEN 1 ELSE 0 END) as completed_count
    FROM user_continuous_progress cp
    LEFT JOIN user_tasks_rewards utr ON cp.user_id = utr.user_id
    WHERE cp.user_id = ?
    GROUP BY cp.id
");
$progress_stmt->execute([$user_id]);
$progress = $progress_stmt->fetch();

// Get user info
$user_stmt = $pdo->prepare("SELECT username, balance, daily_task_limit, last_task_reset_date FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

// Get recent task completions
$recent_tasks = $pdo->prepare("
    SELECT utr.*, t.title 
    FROM user_tasks_rewards utr
    JOIN tasks t ON utr.task_id = t.id
    WHERE utr.user_id = ? AND utr.is_completed = 1
    ORDER BY utr.completed_at DESC
    LIMIT 10
");
$recent_tasks->execute([$user_id]);
$recent_completions = $recent_tasks->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Continuous Progress</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            color: #667eea;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .status-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .status-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .status-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        
        .status-value.success {
            color: #28a745;
        }
        
        .status-value.warning {
            color: #ffc107;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .task-completed {
            color: #28a745;
            font-weight: bold;
        }
        
        .nav-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            justify-content: center;
        }
        
        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .nav-links a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .test-scenario {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-top: 15px;
            border-radius: 5px;
        }
        
        .test-scenario h4 {
            color: #2196F3;
            margin-bottom: 10px;
        }
        
        .step {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .step-number {
            display: inline-block;
            width: 24px;
            height: 24px;
            background: #2196F3;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 24px;
            font-size: 12px;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Test Continuous Progress System</h1>
        
        <div class="nav-links">
            <a href="tasks_list.php">📋 Go to Tasks Page</a>
            <a href="dashboard.php">📊 Dashboard</a>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Current Status -->
        <div class="card">
            <h2>📊 Current Status</h2>
            <div class="status-grid">
                <div class="status-item">
                    <div class="status-label">Username</div>
                    <div class="status-value"><?php echo htmlspecialchars($user['username']); ?></div>
                </div>
                <div class="status-item">
                    <div class="status-label">Current Balance</div>
                    <div class="status-value success">$<?php echo format_balance($user['balance']); ?></div>
                </div>
                <div class="status-item">
                    <div class="status-label">Daily Task Limit</div>
                    <div class="status-value"><?php echo $user['daily_task_limit'] ?: 'Not Set'; ?></div>
                </div>
                <?php if ($progress): ?>
                    <div class="status-item">
                        <div class="status-label">Progress Status</div>
                        <div class="status-value <?php echo $progress['completed_tasks'] > 0 ? 'success' : 'warning'; ?>">
                            <?php echo $progress['completed_tasks']; ?> / <?php echo $progress['total_tasks']; ?> tasks
                        </div>
                    </div>
                    <div class="status-item">
                        <div class="status-label">Total Earned</div>
                        <div class="status-value success">$<?php echo format_balance($progress['total_earned']); ?></div>
                    </div>
                    <div class="status-item">
                        <div class="status-label">Last Activity</div>
                        <div class="status-value"><?php echo date('Y-m-d H:i:s', strtotime($progress['last_activity_date'])); ?></div>
                    </div>
                <?php else: ?>
                    <div class="status-item">
                        <div class="status-label">Progress Status</div>
                        <div class="status-value warning">Not initialized yet</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($user['last_task_reset_date']): ?>
                <div class="test-scenario">
                    <strong>📅 Last Reset:</strong> <?php echo date('Y-m-d H:i:s', strtotime($user['last_task_reset_date'])); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Test Scenarios -->
        <div class="card">
            <h2>🧪 Test Scenarios</h2>
            
            <div class="test-scenario">
                <h4>📝 Scenario 1: Complete a few tasks, then simulate next day</h4>
                <div class="step">1. Complete some tasks below or in tasks page</div>
                <div class="step">2. Click "Simulate Next Day" button</div>
                <div class="step">3. Go to tasks page - you should continue from same task, not start over</div>
            </div>
            
            <div class="test-scenario">
                <h4>📝 Scenario 2: Test week/month later</h4>
                <div class="step">1. Complete tasks up to a certain point</div>
                <div class="step">2. Click "Simulate One Week Later" or "Simulate One Month Later"</div>
                <div class="step">3. Go to tasks page - should still be on same task</div>
            </div>
            
            <div class="test-scenario">
                <h4>📝 Scenario 3: Test reset functionality</h4>
                <div class="step">1. Click "Reset Progress (Test)" to start over</div>
                <div class="step">2. Go to tasks page - should start from task 1</div>
                <div class="step">3. Complete a few tasks and simulate next day again</div>
            </div>
            
            <div class="button-group">
                <form method="POST" style="display: inline;">
                    <button type="submit" name="simulate_next_day" class="btn btn-info">📅 Simulate Next Day</button>
                </form>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="simulate_week_later" class="btn btn-info">📅 Simulate One Week Later</button>
                </form>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="simulate_month_later" class="btn btn-info">📅 Simulate One Month Later</button>
                </form>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="reset_progress" class="btn btn-danger" onclick="return confirm('Reset all progress? This will delete all task completions!')">🔄 Reset Progress (Test)</button>
                </form>
            </div>
        </div>
        
        <!-- Quick Task Completion -->
        <div class="card">
            <h2>⚡ Quick Task Completion (For Testing)</h2>
            <form method="POST">
                <div class="button-group">
                    <button type="submit" name="complete_several_tasks" value="1" class="btn btn-success">Complete 1 Task</button>
                    <button type="submit" name="complete_several_tasks" value="3" class="btn btn-success">Complete 3 Tasks</button>
                    <button type="submit" name="complete_several_tasks" value="5" class="btn btn-success">Complete 5 Tasks</button>
                    <input type="hidden" name="num_tasks" id="num_tasks" value="1">
                </div>
            </form>
            
            <script>
                document.querySelectorAll('button[value]').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        document.getElementById('num_tasks').value = this.value;
                        this.closest('form').submit();
                    });
                });
            </script>
        </div>
        
        <!-- Recent Completions -->
        <?php if ($recent_completions): ?>
            <div class="card">
                <h2>✅ Recent Task Completions</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Task Title</th>
                            <th>Reward</th>
                            <th>Task Order</th>
                            <th>Completed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_completions as $task): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($task['title']); ?></td>
                                <td class="task-completed">$<?php echo format_balance($task['assigned_reward']); ?></td>
                                <td>#<?php echo $task['task_order'] + 1; ?></td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($task['completed_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Test Instructions -->
        <div class="card">
            <h2>📖 Test Instructions</h2>
            <div class="test-scenario">
                <h4>✅ Expected Behavior (CORRECT):</h4>
                <div class="step">• Complete tasks 1-5, leave, come back next day → Should be on task 6</div>
                <div class="step">• Complete tasks 1-10, leave for a week → Should be on task 11</div>
                <div class="step">• Complete tasks 1-20, leave for a month → Should be on task 21</div>
                <div class="step">• Progress never resets unless admin manually resets</div>
            </div>
            
            <div class="test-scenario" style="border-left-color: #dc3545;">
                <h4>❌ What We're FIXING (Old Behavior):</h4>
                <div class="step">• Complete tasks 1-5 today → Tomorrow starts from task 1 (WRONG)</div>
                <div class="step">• Progress resets every day at midnight (WRONG for your client)</div>
            </div>
            
            <div class="button-group" style="margin-top: 20px;">
                <a href="tasks_list.php" class="btn btn-primary" target="_blank">🚀 Go Test Tasks Page (Open in New Tab)</a>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh status every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>