<?php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'taskdb');
define('DB_USER', 'root');
define('DB_PASS', '');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// CSRF protection functions
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function format_balance($amount) {
    return number_format($amount, 2);
}

function get_user_balance($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    return $user ? $user['balance'] : 0;
}

function calc_commission($amount, $percentage) {
    return ($amount * $percentage) / 100;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success = "";

// Check if task ID is provided
if (!isset($_GET['task_id']) || !is_numeric($_GET['task_id'])) {
    header('Location: dashboard.php');
    exit;
}

$task_id = (int)$_GET['task_id'];

// Get task details
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND is_active = TRUE");
$stmt->execute([$task_id]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: dashboard.php');
    exit;
}

// Check if user has already attempted this task
$stmt = $pdo->prepare("SELECT * FROM user_tasks WHERE user_id = ? AND task_id = ?");
$stmt->execute([$user_id, $task_id]);
$user_task = $stmt->fetch();

if ($user_task) {
    $_SESSION['error'] = "You have already completed this task.";
    header('Location: dashboard.php');
    exit;
}

// Check if transactions table exists
$stmt = $pdo->prepare("SHOW TABLES LIKE 'transactions'");
$stmt->execute();
$transactions_table_exists = $stmt->fetch();

// Handle task completion form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    } else {
        // Get user's current balance
        $user_balance = get_user_balance($user_id);

        // Check if user can start the task (considering deposit)
        if ($task['cost'] > 0 && $user_balance < $task['cost']) {
            $errors[] = "Insufficient balance to complete this task. You need $" . format_balance($task['cost']) . 
                        " but your balance is $" . format_balance($user_balance) . 
                        ". Please <a href='deposit.php'>make a deposit</a> first.";
        }

        // If special task requires deposit
        if ($task['is_special'] && $task['deposit_required'] > 0 && $user_balance < $task['deposit_required']) {
            $errors[] = "This special task requires a $" . format_balance($task['deposit_required']) .
                        " deposit. Your current balance is $" . format_balance($user_balance) . 
                        ". Please <a href='deposit.php'>make a deposit</a> first.";
        }

        if (empty($errors)) {
            // Start transaction
            $pdo->beginTransaction();
            try {
                // Deduct task cost
                if ($task['cost'] > 0) {
                    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$task['cost'], $user_id]);
                    
                    if ($transactions_table_exists) {
                        $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, note) VALUES (?, ?, 'deduction', ?)");
                        $stmt->execute([$user_id, $task['cost'], "Task cost: " . $task['title']]);
                    }
                }

                // Record user task
                $stmt = $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status) VALUES (?, ?, 'completed')");
                $stmt->execute([$user_id, $task_id]);

                // Add task reward
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$task['reward'], $user_id]);
                
                if ($transactions_table_exists) {
                    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, note) VALUES (?, ?, 'reward', ?)");
                    $stmt->execute([$user_id, $task['reward'], "Task reward: " . $task['title']]);
                }

                // Handle referrer commission
                $stmt = $pdo->prepare("SELECT referrer_id FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $referrer = $stmt->fetchColumn();

                if ($referrer) {
                    // Get commission percentage from settings or use default
                    $commission_percentage = 10; // Default 10%
                    
                    $stmt = $pdo->prepare("SHOW TABLES LIKE 'settings'");
                    $stmt->execute();
                    $settings_table_exists = $stmt->fetch();
                    
                    if ($settings_table_exists) {
                        $settings_stmt = $pdo->query("SELECT commission_percentage FROM settings LIMIT 1");
                        $settings = $settings_stmt->fetch();
                        if ($settings) {
                            $commission_percentage = $settings['commission_percentage'];
                        }
                    }
                    
                    $commission_amount = calc_commission($task['reward'], $commission_percentage);

                    if ($commission_amount > 0) {
                        $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                        $stmt->execute([$commission_amount, $referrer]);

                        if ($transactions_table_exists) {
                            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, note) VALUES (?, ?, 'commission', ?)");
                            $stmt->execute([$referrer, $commission_amount, "Commission from user " . $_SESSION['username'] . " for task: " . $task['title']]);
                        }

                        // Check if commissions table exists
                        $stmt = $pdo->prepare("SHOW TABLES LIKE 'commissions'");
                        $stmt->execute();
                        $commissions_table_exists = $stmt->fetch();
                        
                        if ($commissions_table_exists) {
                            // Check what columns exist in the commissions table
                            $stmt = $pdo->prepare("SHOW COLUMNS FROM commissions");
                            $stmt->execute();
                            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            // Check if user_id column exists (instead of referred_user_id)
                            if (in_array('user_id', $columns)) {
                                if (in_array('task_id', $columns)) {
                                    $stmt = $pdo->prepare("INSERT INTO commissions (referrer_id, user_id, task_id, commission_amount) VALUES (?, ?, ?, ?)");
                                    $stmt->execute([$referrer, $user_id, $task_id, $commission_amount]);
                                } else {
                                    $stmt = $pdo->prepare("INSERT INTO commissions (referrer_id, user_id, commission_amount) VALUES (?, ?, ?)");
                                    $stmt->execute([$referrer, $user_id, $commission_amount]);
                                }
                            } 
                            // Check if referred_user_id column exists
                            else if (in_array('referred_user_id', $columns)) {
                                if (in_array('task_id', $columns)) {
                                    $stmt = $pdo->prepare("INSERT INTO commissions (referrer_id, referred_user_id, task_id, commission_amount) VALUES (?, ?, ?, ?)");
                                    $stmt->execute([$referrer, $user_id, $task_id, $commission_amount]);
                                } else {
                                    $stmt = $pdo->prepare("INSERT INTO commissions (referrer_id, referred_user_id, commission_amount) VALUES (?, ?, ?)");
                                    $stmt->execute([$referrer, $user_id, $commission_amount]);
                                }
                            }
                            // Fallback to just referrer_id and commission_amount
                            else {
                                $stmt = $pdo->prepare("INSERT INTO commissions (referrer_id, commission_amount) VALUES (?, ?)");
                                $stmt->execute([$referrer, $commission_amount]);
                            }
                        }
                    }
                }

                // Reset last_reset_at for special tasks
                if ($task['is_special']) {
                    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'last_reset_at'");
                    $stmt->execute();
                    $last_reset_column = $stmt->fetch();
                    
                    if ($last_reset_column) {
                        $stmt = $pdo->prepare("UPDATE users SET last_reset_at = NOW() WHERE id = ?");
                        $stmt->execute([$user_id]);
                    }
                }

                $pdo->commit();
                $_SESSION['success'] = "Task completed successfully! Reward: $" . format_balance($task['reward']);
                header('Location: dashboard.php');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = "Error completing task: " . $e->getMessage();
                error_log("Complete task error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Task - Task Website</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        header {
            background: #2c3e50;
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        header h1 {
            font-size: 28px;
            font-weight: 700;
        }
        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        nav a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            transition: background 0.3s;
        }
        nav a:hover {
            background: rgba(255,255,255,0.1);
        }
        main {
            padding: 30px;
        }
        .task-complete {
            max-width: 800px;
            margin: 0 auto;
        }
        .task-complete h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 28px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .task-details {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #3498db;
        }
        .task-details p {
            margin-bottom: 10px;
            font-size: 16px;
        }
        .error-message {
            background: #ffecec;
            color: #d32f2f;
            padding: 16px;
            margin-bottom: 20px;
            border-radius: 8px;
            border-left: 5px solid #f44336;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .btn-primary {
            background: linear-gradient(to right, #3498db, #2980b9);
            color: white;
        }
        .btn-primary:hover {
            background: linear-gradient(to right, #2980b9, #3498db);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        .btn-secondary:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }
        footer {
            background: #ecf0f1;
            text-align: center;
            padding: 20px;
            margin-top: 30px;
            color: #7f8c8d;
        }
        @media (max-width: 768px) {
            header {
                flex-direction: column;
                text-align: center;
            }
            nav {
                margin-top: 15px;
                justify-content: center;
            }
            .btn {
                width: 100%;
                margin-right: 0;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>Task Website</h1>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="tasks_list.php">Tasks</a>
            <a href="upload_payment.php">Upload Proof</a>
            <a href="deposit.php">Make Deposit</a>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <main>
        <div class="task-complete">
            <h2>Complete Task: <?php echo htmlspecialchars($task['title']); ?></h2>

            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo $error; ?></p>
                    <?php endforeach; ?>
                </div>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            <?php else: ?>
                <div class="task-details">
                    <p><?php echo htmlspecialchars($task['description']); ?></p>
                    <p>Reward: $<?php echo format_balance($task['reward']); ?></p>
                    <?php if ($task['cost'] > 0): ?>
                        <p>Cost: $<?php echo format_balance($task['cost']); ?></p>
                    <?php endif; ?>
                    <?php if ($task['deposit_required'] > 0): ?>
                        <p>Deposit Required: $<?php echo format_balance($task['deposit_required']); ?></p>
                    <?php endif; ?>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <button type="submit" class="btn btn-primary">Confirm Completion</button>
                    <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>
</body>
</html>