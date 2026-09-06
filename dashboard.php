<?php
// Error reporting (remove after debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// FIXED: Correct relative paths (no leading slash)
require_once 'includes/init.php';        // Database connection ($pdo)
require_once 'includes/functions.php';   // Helper functions like CSRF, format_balance, etc.
require_once 'includes/auth.php';        // Has require_admin() function

// Ensure admin is logged in
require_admin();

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

// ==========================
// Dashboard Statistics
// ==========================
try {
    // Get current admin role and info
    $current_admin_role = strtolower(trim($_SESSION['role'] ?? ''));
    if (empty($current_admin_role)) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_admin_role = strtolower(trim($stmt->fetchColumn() ?: ''));
        $_SESSION['role'] = $current_admin_role;
    }

    // Role-based data filtering
    if ($current_admin_role === 'sub_admin') {
        // Sub-admin: Only show data from referral network
        $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
        
        if (!empty($referral_tree_ids)) {
            $placeholders = str_repeat('?,', count($referral_tree_ids) - 1) . '?';
            
            // Total users in referral network
            $total_users_stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM users WHERE id IN ($placeholders) AND role = 'user'");
            $total_users_stmt->execute($referral_tree_ids);
            $total_users = $total_users_stmt->fetch()['count'];
            
            // Total tasks (sub-admin sees all tasks)
            $task_count = $pdo->query("SELECT COUNT(*) AS count FROM tasks")->fetch()['count'];
            
            // Pending payments from referral network
            $pending_payments_stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM payments WHERE status = 'pending' AND user_id IN ($placeholders)");
            $pending_payments_stmt->execute($referral_tree_ids);
            $pending_payments = $pending_payments_stmt->fetch()['count'];
            
            // Total commissions from referral network
            $total_commission_stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount),0) AS total FROM commissions WHERE referrer_id IN ($placeholders)");
            $total_commission_stmt->execute($referral_tree_ids);
            $total_commission = $total_commission_stmt->fetch()['total'];
            
            // Pending withdrawals from referral network
            $pending_withdrawals_stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM withdrawals WHERE status = 'pending' AND user_id IN ($placeholders)");
            $pending_withdrawals_stmt->execute($referral_tree_ids);
            $pending_withdrawals = $pending_withdrawals_stmt->fetch()['count'];
            
            // Recent tasks from referral network
            $recent_tasks_stmt = $pdo->prepare("
                SELECT ut.*, u.username, t.title 
                FROM user_tasks ut
                JOIN users u ON ut.user_id = u.id
                JOIN tasks t ON ut.task_id = t.id
                WHERE ut.user_id IN ($placeholders)
                ORDER BY ut.created_at DESC
                LIMIT 5
            ");
            $recent_tasks_stmt->execute($referral_tree_ids);
            $recent_tasks = $recent_tasks_stmt->fetchAll();
        } else {
            // No referrals yet
            $total_users = 0;
            $task_count = $pdo->query("SELECT COUNT(*) AS count FROM tasks")->fetch()['count'];
            $pending_payments = 0;
            $total_commission = 0;
            $pending_withdrawals = 0;
            $recent_tasks = [];
        }
    } else {
        // Admin: Show all data
        // Total users
        $total_users = $pdo->query("SELECT COUNT(*) AS count FROM users WHERE role = 'user'")->fetch()['count'];

        // Total tasks
        $task_count = $pdo->query("SELECT COUNT(*) AS count FROM tasks")->fetch()['count'];

        // Pending payments
        $pending_payments = $pdo->query("SELECT COUNT(*) AS count FROM payments WHERE status = 'pending'")->fetch()['count'];

        // Total commissions
        $total_commission = $pdo->query("SELECT COALESCE(SUM(commission_amount),0) AS total FROM commissions")->fetch()['total'];

        // Pending withdrawals
        $pending_withdrawals = $pdo->query("SELECT COUNT(*) AS count FROM withdrawals WHERE status = 'pending'")->fetch()['count'];

        // Recent tasks
        $recent_tasks = $pdo->query("
            SELECT ut.*, u.username, t.title 
            FROM user_tasks ut
            JOIN users u ON ut.user_id = u.id
            JOIN tasks t ON ut.task_id = t.id
            ORDER BY ut.created_at DESC
            LIMIT 5
        ")->fetchAll();
    }

    // Unread notifications (personal to admin)
    $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$_SESSION['user_id']]);
    $unread_count = $stmt->fetch()['count'] ?? 0;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - Task Website</title>
<!-- FIXED: Correct CSS paths -->
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/admindashboard.css">
<style>
nav a.active { color: #ff6600; font-weight: bold; }
.dashboard-grid { display: flex; gap: 20px; flex-wrap: wrap; }
.dashboard-card { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); flex: 1 1 200px; text-align: center; }
.stat-number { font-size: 2rem; font-weight: bold; margin-bottom: 10px; }
.status-pending { color: orange; font-weight: bold; }
.status-completed { color: green; font-weight: bold; }
.status-failed { color: red; font-weight: bold; }
.table-container { overflow-x: auto; }
.sub-admin-note {
    background: #e67e22;
    color: white;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 20px;
    text-align: center;
    font-weight: bold;
}
.btn { 
    display: inline-block; 
    padding: 8px 16px; 
    background: #007bff; 
    color: white; 
    text-decoration: none; 
    border-radius: 4px; 
    border: none; 
    cursor: pointer; 
    margin: 2px;
}
.btn:hover { background: #0056b3; }
.btn-primary { background: #007bff; }
.btn-success { background: #28a745; }
.btn-danger { background: #dc3545; }
.btn-warning { background: #ffc107; color: #000; }
.btn-info { background: #17a2b8; }
.mt-4 { margin-top: 20px; }
.text-center { text-align: center; }
</style>
</head>
<body>
<div class="container">
<header>
    <h1>tiktok shop - Admin Panel</h1>
    <nav>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="users.php">Users</a>
        <a href="tasks.php">Tasks</a>
        <a href="payments.php">Payments</a>
        <a href="commissions.php">Commissions</a>
        <a href="withdrawals.php">Withdrawals (<?= $pending_withdrawals; ?>)</a>
        <a href="settings.php">Settings</a>
        <a href="edittask.php">Edit Task</a>
        <?php if (in_array($current_admin_role, ['main admin', 'main_admin'])): ?>
            <a href="usersedit.php">Users Edit</a>
        <?php endif; ?>
        <!-- ADDED: View Passwords Link -->
        <?php if (in_array($current_admin_role, ['main admin', 'main_admin', 'admin'])): ?>
            <a href="admin_view_passwords.php" style="color: #dc3545; font-weight: bold;">View Passwords</a>
        <?php endif; ?>
        <a href="notifications.php">Notifications (<?= $unread_count; ?>)</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main>
<h2>Welcome, <?= htmlspecialchars($_SESSION['username']); ?>!</h2>
<p>You are logged in as: <strong style="color:#007bff; text-transform:capitalize;"><?= str_replace('_',' ', htmlspecialchars($current_admin_role)); ?></strong></p>

<?php if ($current_admin_role === 'sub_admin'): ?>
   <!-- Sub admin specific content could go here -->
<?php endif; ?>

<div class="dashboard-grid">
    <div class="dashboard-card">
        <h3>Users</h3>
        <p class="stat-number"><?= $total_users; ?></p>
        <a href="users.php" class="btn btn-primary">Manage Users</a>
    </div>

    <div class="dashboard-card">
        <h3>Tasks</h3>
        <p class="stat-number"><?= $task_count; ?></p>
        <a href="tasks.php" class="btn btn-primary">Manage Tasks</a>
    </div>

    <div class="dashboard-card">
        <h3>Pending Payments</h3>
        <p class="stat-number"><?= $pending_payments; ?></p>
        <a href="payments.php" class="btn btn-primary">Review Payments</a>
    </div>

    <div class="dashboard-card">
        <h3>Total Commission</h3>
        <p class="stat-number">$<?= format_balance($total_commission); ?></p>
        <a href="commissions.php" class="btn btn-primary">View Commissions</a>
    </div>

    <div class="dashboard-card">
        <h3>Withdrawals</h3>
        <p class="stat-number"><?= $pending_withdrawals; ?></p>
        <a href="withdrawals.php" class="btn btn-primary">Manage Withdrawals</a>
    </div>

    <!-- ADDED: Quick Access to View Passwords -->
    <?php if (in_array($current_admin_role, ['main admin', 'main_admin', 'admin'])): ?>
    <div class="dashboard-card" style="border: 2px solid #dc3545;">
        <h3 style="color: #dc3545;">User Passwords</h3>
        <p class="stat-number">🔐</p>
        <a href="admin_view_passwords.php" class="btn btn-danger">View All Passwords</a>
    </div>
    <?php endif; ?>
</div>

<div class="recent-activities mt-4">
    <h3>Recent Task Completions</h3>
    <?php if (empty($recent_tasks)): ?>
        <p>
            <?php if ($current_admin_role === 'sub_admin'): ?>
                No recent activities in your referral network.
            <?php else: ?>
                No recent activities.
            <?php endif; ?>
        </p>
    <?php else: ?>
    <div class="table-container">
        <table class="table table-striped" border="1" cellpadding="10" style="width:100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Task</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent_tasks as $activity): ?>
                <tr>
                    <td><?= htmlspecialchars($activity['username']); ?></td>
                    <td><?= htmlspecialchars($activity['title']); ?></td>
                    <td><span class="status-<?= strtolower($activity['status']); ?>"><?= ucfirst($activity['status']); ?></span></td>
                    <td><?= date('M j, Y g:i A', strtotime($activity['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ADDED: Quick Actions Section -->
<div class="quick-actions mt-4">
    <h3>Quick Actions</h3>
    <div class="dashboard-grid">
        <a href="users.php" class="btn btn-primary">Add New User</a>
        <a href="tasks.php" class="btn btn-success">Create New Task</a>
        <a href="withdrawals.php" class="btn btn-warning">Process Withdrawals</a>
        <?php if (in_array($current_admin_role, ['main admin', 'main_admin', 'admin'])): ?>
            <a href="admin_view_passwords.php" class="btn btn-danger">View User Passwords</a>
        <?php endif; ?>
    </div>
</div>
</main>

<footer class="mt-4 text-center">
    <p>&copy; <?= date('Y'); ?> Task Website. All rights reserved.</p>
</footer>
</div>
</body>
</html>