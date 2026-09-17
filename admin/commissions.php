<?php
// Always include init first to load $pdo
require_once '../includes/init.php';

// Auth check for admin
require_once '../includes/admin_auth.php';

// Get all commissions with user information
try {
    $stmt = $pdo->query("
        SELECT c.*, 
               r.username AS referrer_username,
               u.username AS referred_username,
               t.title AS task_title
        FROM commissions c 
        JOIN users r ON c.referrer_id = r.id 
        JOIN users u ON c.user_id = u.id 
        JOIN tasks t ON c.task_id = t.id 
        ORDER BY c.created_at DESC
    ");
    $commissions = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error (commissions): " . $e->getMessage());
}

// Get unread notifications count
try {
    $unread_notifications = $pdo->prepare("
        SELECT COUNT(*) as count FROM notifications 
        WHERE user_id = ? AND is_read = FALSE
    ");
    $unread_notifications->execute([$_SESSION['user_id']]);
    $unread_count = $unread_notifications->fetch()['count'];
} catch (PDOException $e) {
    $unread_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commissions - Task Website</title>
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
            <h2>Commission History</h2>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Referrer</th>
                            <th>Referred User</th>
                            <th>Task</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($commissions)): ?>
                            <?php foreach ($commissions as $commission): ?>
                                <tr>
                                    <td><?php echo $commission['id']; ?></td>
                                    <td><?php echo htmlspecialchars($commission['referrer_username']); ?></td>
                                    <td><?php echo htmlspecialchars($commission['referred_username']); ?></td>
                                    <td><?php echo htmlspecialchars($commission['task_title']); ?></td>
                                    <td>$<?php echo format_balance($commission['commission_amount']); ?></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($commission['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">No commissions found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
        </footer>
    </div>
</body>
</html>
