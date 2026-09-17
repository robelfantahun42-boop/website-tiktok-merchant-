<?php
// ======================
// Admin Withdrawals Page
// ======================
require_once '../includes/init.php';          // $pdo defined
require_once '../includes/admin_auth.php';    // Admin login check
require_once '../includes/functions.php';     // Helper functions

// Function to get all user IDs in the referral tree (recursive) - Same as users.php
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

// Get current user role
$current_user_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$current_user_stmt->execute([$_SESSION['user_id']]);
$current_user = $current_user_stmt->fetch();

$errors = [];
$success = '';

// Handle Approve / Reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $withdrawal_id = intval($_POST['withdrawal_id']);
    $action = $_POST['action'];

    // Fetch the withdrawal
    $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? AND status = 'pending'");
    $stmt->execute([$withdrawal_id]);
    $withdrawal = $stmt->fetch();

    if (!$withdrawal) {
        $errors[] = "Withdrawal request not found or already processed.";
    } else {
        $user_id = $withdrawal['user_id'];
        $amount = $withdrawal['amount'];
        
        // For sub-admin: Check if the withdrawal user is in their referral tree
        if ($current_user['role'] === 'sub_admin') {
            $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
            
            if (!in_array($user_id, $referral_tree_ids)) {
                $errors[] = "You are not authorized to process this withdrawal request.";
                $withdrawal = false; // Prevent further processing
            }
        }

        if ($withdrawal) {
            if ($action === 'approve') {
                // Check user balance
                $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_balance = $stmt->fetchColumn();

                if ($user_balance >= $amount) {
                    // Deduct balance
                    $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$amount, $user_id]);

                    // Update withdrawal status
                    $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'approved' WHERE id = ?");
                    $stmt->execute([$withdrawal_id]);

                    $success = "Withdrawal request approved.";
                } else {
                    $errors[] = "User does not have enough balance.";
                }

            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$withdrawal_id]);
                $success = "Withdrawal request rejected.";
            }
        }
    }
}

// Build withdrawal query based on user role
if ($current_user['role'] === 'sub_admin') {
    // Sub-admin: Only show withdrawals from users in their referral tree
    $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
    
    if (!empty($referral_tree_ids)) {
        $placeholders = str_repeat('?,', count($referral_tree_ids) - 1) . '?';
        $query = "
            SELECT w.*, u.username, u.phone
            FROM withdrawals w
            JOIN users u ON w.user_id = u.id
            WHERE w.user_id IN ($placeholders)
            ORDER BY w.created_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($referral_tree_ids);
        $withdrawals = $stmt->fetchAll();
    } else {
        $withdrawals = []; // No referrals yet
    }
} else {
    // Main Admin: Show all withdrawals
    $withdrawals = $pdo->query("
        SELECT w.*, u.username, u.phone
        FROM withdrawals w
        JOIN users u ON w.user_id = u.id
        ORDER BY w.created_at DESC
    ")->fetchAll();
}

// Get pending withdrawals count for header
if ($current_user['role'] === 'sub_admin') {
    if (!empty($referral_tree_ids)) {
        $placeholders = str_repeat('?,', count($referral_tree_ids) - 1) . '?';
        $pending_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM withdrawals WHERE status = 'pending' AND user_id IN ($placeholders)");
        $pending_stmt->execute($referral_tree_ids);
        $pending_withdrawals = $pending_stmt->fetch()['count'];
    } else {
        $pending_withdrawals = 0;
    }
} else {
    $pending_withdrawals = $pdo->query("SELECT COUNT(*) as count FROM withdrawals WHERE status = 'pending'")->fetch()['count'];
}

// Get unread notifications count for header
$unread_stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = $unread_stmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin - Withdrawals</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
body { font-family: Arial, sans-serif; background:#f5f7f9; margin:0; }
.container { max-width:1200px; margin:auto; padding:1rem; }
header { background:#333; color:#fff; padding:1rem; text-align:center; }
nav a { color:#fff; margin:0 10px; text-decoration:none; }
nav a:hover { text-decoration:underline; }
table { width:100%; border-collapse:collapse; margin-top:20px; }
th, td { border:1px solid #ddd; padding:10px; text-align:left; }
th { background:#f4f4f4; }
.status-pending { color:#ff9800; font-weight:bold; }
.status-approved { color:#4caf50; font-weight:bold; }
.status-rejected { color:#f44336; font-weight:bold; }
button { padding:5px 10px; border:none; border-radius:4px; cursor:pointer; color:#fff; }
button.approve { background:#4caf50; }
button.reject { background:#f44336; }
.error-message { background:#ffebee; color:#c62828; padding:10px; border-radius:4px; margin-bottom:15px; }
.success-message { background:#e8f5e9; color:#2e7d32; padding:10px; border-radius:4px; margin-bottom:15px; }
.subadmin-info { 
    background: #e3f2fd; 
    color: #0d47a1; 
    padding: 10px; 
    border-radius: 4px; 
    margin-bottom: 15px; 
    border-left: 4px solid #2196f3;
}
</style>
<link rel="stylesheet" href="../assets/css/adminwithdrawal.css">
</head>
<body>
<div class="container">
<header>
<h1>Admin Panel - Withdrawals</h1>
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
<h2>Withdrawal Requests 
    <?php if ($current_user['role'] === 'sub_admin'): ?>
    <?php endif; ?>
</h2>
<?php if (!empty($errors)): ?>
<div class="error-message">
<?php foreach ($errors as $error): ?>
<p><?php echo htmlspecialchars($error); ?></p>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="success-message">
<p><?php echo htmlspecialchars($success); ?></p>
</div>
<?php endif; ?>

<?php if (empty($withdrawals)): ?>
<p>
    <?php if ($current_user['role'] === 'sub_admin'): ?>
        No withdrawal requests found in your referral network.
    <?php else: ?>
        No withdrawal requests found.
    <?php endif; ?>
</p>
<?php else: ?>
<table>
<thead>
<tr>
<th>ID</th>
<th>User</th>
<th>Phone</th>
<th>Amount</th>
<th>Status</th>
<th>Date</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($withdrawals as $w): ?>
<tr>
<td><?php echo $w['id']; ?></td>
<td><?php echo htmlspecialchars($w['username']); ?></td>
<td><?php echo htmlspecialchars($w['phone'] ?? 'N/A'); ?></td>
<td>$<?php echo format_balance($w['amount']); ?></td>
<td class="status-<?php echo $w['status']; ?>"><?php echo ucfirst($w['status']); ?></td>
<td><?php echo date('M j, Y g:i A', strtotime($w['created_at'])); ?></td>
<td>
<?php if ($w['status'] === 'pending'): ?>
<form method="post" style="display:inline;">
<input type="hidden" name="withdrawal_id" value="<?php echo $w['id']; ?>">
<button type="submit" name="action" value="approve" class="approve">Approve</button>
<button type="submit" name="action" value="reject" class="reject">Reject</button>
</form>
<?php else: ?>
<span>N/A</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</main>

<footer style="text-align:center; margin-top:2rem;">&copy; <?php echo date('Y'); ?> Task Website</footer>
</div>
</body>
</html>