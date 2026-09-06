<?php
require_once 'includes/admin_auth.php';

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

// Get current user role
$current_user_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$current_user_stmt->execute([$_SESSION['user_id']]);
$current_user = $current_user_stmt->fetch();
$current_user_role = $current_user['role'];

// Handle payment status update
if (isset($_GET['approve']) && is_numeric($_GET['approve'])) {
    $payment_id = (int)$_GET['approve'];

    // Get the payment details first
    $stmt = $pdo->prepare("SELECT p.user_id, p.amount, p.status, u.parent_id 
                          FROM payments p 
                          JOIN users u ON p.user_id = u.id 
                          WHERE p.id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();

    if ($payment && $payment['status'] === 'pending') {
        // Authorization check for sub_admin
        if ($current_user_role === 'sub_admin') {
            $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
            if (!in_array($payment['user_id'], $referral_tree_ids)) {
                $error = "You are not authorized to approve payments from this user.";
                $payment = null; // Prevent processing
            }
        }

        if ($payment) {
            try {
                // Start transaction
                $pdo->beginTransaction();

                // Check if this payment is linked to a special task
                $special_task_stmt = $pdo->prepare("
                    SELECT std.*, p.amount as paid_amount 
                    FROM special_task_deposits std
                    JOIN payments p ON std.payment_id = p.id
                    WHERE std.payment_id = ? AND std.status = 'pending'
                ");
                $special_task_stmt->execute([$payment_id]);
                $special_task_deposit = $special_task_stmt->fetch();

                $balance_to_add = $payment['amount']; // Default: add full amount
                $was_fully_completed = false;

                if ($special_task_deposit) {
                    // Calculate total already credited for this special task
                    $cumulative_stmt = $pdo->prepare("
                        SELECT SUM(credited_amount) as total_credited 
                        FROM special_task_deposits 
                        WHERE user_id = ? AND task_position = ? AND status IN ('approved', 'pending')
                    ");
                    $cumulative_stmt->execute([$special_task_deposit['user_id'], $special_task_deposit['task_position']]);
                    $cumulative_result = $cumulative_stmt->fetch();
                    $total_already_credited = $cumulative_result['total_credited'] ?? 0;
                    
                    // Calculate how much we can add without exceeding special task cost
                    $remaining_allowed = $special_task_deposit['required_amount'] - $total_already_credited;
                    $balance_to_add = min($payment['amount'], $remaining_allowed);
                    
                    // Update credited amount for this specific payment
                    $update_credited_stmt = $pdo->prepare("
                        UPDATE special_task_deposits 
                        SET credited_amount = ? 
                        WHERE payment_id = ? AND status = 'pending'
                    ");
                    $update_credited_stmt->execute([$balance_to_add, $payment_id]);
                    
                    // Update ALL pending deposits for this special task to approved if fully paid
                    if ($total_already_credited + $balance_to_add >= $special_task_deposit['required_amount']) {
                        $update_all_stmt = $pdo->prepare("
                            UPDATE special_task_deposits 
                            SET status = 'approved', verified_at = NOW() 
                            WHERE user_id = ? AND task_position = ? AND status = 'pending'
                        ");
                        $update_all_stmt->execute([$special_task_deposit['user_id'], $special_task_deposit['task_position']]);
                        $was_fully_completed = true;
                    }
                }

                // Update payment status to approved
                $stmt = $pdo->prepare("UPDATE payments SET status = 'approved' WHERE id = ?");
                $stmt->execute([$payment_id]);

                // Update user's balance with calculated amount (ONLY deposit, NO reward)
                $update_balance = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $update_balance->execute([$balance_to_add, $payment['user_id']]);

                // FIX: Only send notification - DO NOT auto-complete task or add reward
                // Reward is added ONLY when user manually completes task in tasks_list.php
                if ($special_task_deposit && $was_fully_completed) {
                    $notification_msg = "✅ Your deposit for Special Task #{$special_task_deposit['task_position']} has been approved. Complete the task to earn your reward!";
                    $notification_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, created_at) 
                        VALUES (?, ?, NOW())
                    ");
                    $notification_stmt->execute([$special_task_deposit['user_id'], $notification_msg]);
                }

                $pdo->commit();
                
                if ($special_task_deposit) {
                    $new_total_credited = $total_already_credited + $balance_to_add;
                    if ($was_fully_completed) {
                        $success = "Payment approved successfully! Special task #{$special_task_deposit['task_position']} deposit verified. Balance added: $" . number_format($balance_to_add, 2) . ". User must complete task to earn reward.";
                    } else {
                        $success = "Payment approved successfully! Balance added: $" . number_format($balance_to_add, 2) . 
                                  " (Special task progress: $" . number_format($new_total_credited, 2) . 
                                  " of $" . number_format($special_task_deposit['required_amount'], 2) . ")";
                    }
                } else {
                    $success = "Payment approved successfully! Balance added: $" . number_format($balance_to_add, 2);
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error approving payment: " . $e->getMessage();
            }
        }
    } else {
        $error = "Payment not found or already processed.";
    }
} elseif (isset($_GET['reject']) && is_numeric($_GET['reject'])) {
    $payment_id = (int)$_GET['reject'];
    
    // Authorization check for sub_admin
    if ($current_user_role === 'sub_admin') {
        $stmt = $pdo->prepare("SELECT p.user_id FROM payments p WHERE p.id = ?");
        $stmt->execute([$payment_id]);
        $payment_user = $stmt->fetch();
        
        if ($payment_user) {
            $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
            if (!in_array($payment_user['user_id'], $referral_tree_ids)) {
                $error = "You are not authorized to reject payments from this user.";
                $payment_user = null; // Prevent processing
            }
        }
    }
    
    if (!isset($error)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE payments SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$payment_id]);
            
            // Also reject any linked special task deposits
            $special_deposit_stmt = $pdo->prepare("
                UPDATE special_task_deposits 
                SET status = 'rejected' 
                WHERE payment_id = ? AND status = 'pending'
            ");
            $special_deposit_stmt->execute([$payment_id]);
            
            $pdo->commit();
            $success = "Payment rejected successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error rejecting payment: " . $e->getMessage();
        }
    }
}

// Handle admin message for special task deposits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $payment_id = (int)$_POST['payment_id'];
    $remaining_amount = (float)$_POST['remaining_amount'];
    
    // Validate remaining amount
    if ($remaining_amount <= 0) {
        $error = "Remaining amount must be greater than 0.";
    } else {
        // Authorization check for sub_admin
        if ($current_user_role === 'sub_admin') {
            $stmt = $pdo->prepare("SELECT p.user_id FROM payments p WHERE p.id = ?");
            $stmt->execute([$payment_id]);
            $payment_user = $stmt->fetch();
            
            if ($payment_user) {
                $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
                if (!in_array($payment_user['user_id'], $referral_tree_ids)) {
                    $error = "You are not authorized to send messages to this user.";
                    $payment_user = null; // Prevent processing
                }
            }
        }
        
        if (!isset($error)) {
            try {
                // Get the special task deposit record with payment amount
                $special_deposit_stmt = $pdo->prepare("
                    SELECT std.*, p.amount as paid_amount 
                    FROM special_task_deposits std
                    JOIN payments p ON std.payment_id = p.id
                    WHERE std.payment_id = ? AND std.status = 'pending'
                ");
                $special_deposit_stmt->execute([$payment_id]);
                $special_deposit = $special_deposit_stmt->fetch();
                
                if ($special_deposit) {
                    // Calculate actual remaining required amount based on total already credited
                    $cumulative_stmt = $pdo->prepare("
                        SELECT SUM(credited_amount) as total_credited 
                        FROM special_task_deposits 
                        WHERE user_id = ? AND task_position = ? AND status IN ('approved', 'pending')
                    ");
                    $cumulative_stmt->execute([$special_deposit['user_id'], $special_deposit['task_position']]);
                    $cumulative_result = $cumulative_stmt->fetch();
                    $total_already_credited = $cumulative_result['total_credited'] ?? 0;
                    
                    $actual_remaining_required = $special_deposit['required_amount'] - $total_already_credited;
                    
                    // Use the minimum between admin input and actual remaining required
                    $final_remaining_amount = min($remaining_amount, $actual_remaining_required);
                    
                    // Update the remaining amount in the special task deposit record
                    $update_stmt = $pdo->prepare("
                        UPDATE special_task_deposits 
                        SET remaining_amount = ? 
                        WHERE payment_id = ? AND status = 'pending'
                    ");
                    $update_stmt->execute([$final_remaining_amount, $payment_id]);
                    
                    // Create notification for user
                    $notification_msg = "Admin message: You need to deposit additional $" . number_format($final_remaining_amount, 2) . " to complete your special task.";
                    $notification_stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, message, created_at) 
                        VALUES (?, ?, NOW())
                    ");
                    $notification_stmt->execute([$special_deposit['user_id'], $notification_msg]);
                    
                    $success = "Message sent successfully. User will see they need to deposit additional $" . number_format($final_remaining_amount, 2);
                } else {
                    $error = "No pending special task deposit found for this payment.";
                }
            } catch (Exception $e) {
                $error = "Error sending message: " . $e->getMessage();
            }
        }
    }
}

// Get payments based on user role
if ($current_user_role === 'sub_admin') {
    // Subadmin: Only show payments from referral network
    $referral_tree_ids = getReferralTreeIds($pdo, $_SESSION['user_id']);
    
    if (!empty($referral_tree_ids)) {
        $placeholders = str_repeat('?,', count($referral_tree_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT p.*, u.username 
            FROM payments p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.user_id IN ($placeholders)
            ORDER BY p.uploaded_at DESC
        ");
        $stmt->execute($referral_tree_ids);
        $payments = $stmt->fetchAll();
    } else {
        $payments = []; // No referrals yet
    }
} else {
    // Admin: Show all payments
    $stmt = $pdo->query("
        SELECT p.*, u.username 
        FROM payments p 
        JOIN users u ON p.user_id = u.id 
        ORDER BY p.uploaded_at DESC
    ");
    $payments = $stmt->fetchAll();
}

// Get unread notifications count
$unread_notifications = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
$unread_notifications->execute([$_SESSION['user_id']]);
$unread_count = $unread_notifications->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Payments - Task Website</title>
<link rel="stylesheet" href="../assets/css/adminpayment.css">
<style>
.status-pending { color: #e67e22; font-weight:bold; }
.status-approved { color: #27ae60; font-weight:bold; }
.status-rejected { color: #e74c3c; font-weight:bold; }
.success-message{background:#e8f5e9;color:#2e7d32;padding:10px;border-radius:4px;margin-bottom:20px;}
.error-message{background:#ffebee;color:#c62828;padding:10px;border-radius:4px;margin-bottom:20px;}
.special-task-badge { background: linear-gradient(135deg, #e67e22, #d35400); color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: bold; margin-left: 5px; display: inline-block; }
.special-task-info { background: rgba(230, 126, 34, 0.1); padding: 8px; border-radius: 5px; margin-top: 5px; font-size: 0.8rem; border-left: 3px solid #e67e22; }
.admin-message-form { background: rgba(52, 152, 219, 0.1); padding: 15px; border-radius: 8px; margin: 10px 0; border: 1px solid rgba(52, 152, 219, 0.3); }
.admin-message-form h4 { color: #3498db; margin-bottom: 10px; font-size: 1rem; }
.message-form-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.message-input-group { flex: 1; min-width: 200px; }
.message-input-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #2c3e50; font-size: 0.9rem; }
.message-input { width: 100%; padding: 8px 12px; border: 1px solid #bdc3c7; border-radius: 4px; font-size: 1rem; }
.message-input:focus { outline: none; border-color: #3498db; box-shadow: 0 0 5px rgba(52, 152, 219, 0.3); }
.message-btn { background: linear-gradient(135deg, #3498db, #2980b9); color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 0.9rem; font-weight: bold; transition: all 0.3s ease; white-space: nowrap; }
.message-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3); }
.remaining-amount-info { background: rgba(231, 76, 60, 0.1); padding: 8px; border-radius: 5px; margin-top: 5px; font-size: 0.8rem; border-left: 3px solid #e74c3c; color: #c0392b; }
.remaining-amount-info strong { color: #e74c3c; }
.progress-info { background: rgba(39, 174, 96, 0.1); padding: 8px; border-radius: 5px; margin-top: 5px; font-size: 0.8rem; border-left: 3px solid #27ae60; color: #27ae60; }
.progress-info strong { color: #27ae60; }
.btn { display: inline-block; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-weight: bold; cursor: pointer; border: none; transition: all 0.3s ease; }
.btn-primary { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3); }
.btn-secondary { background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: white; }
.btn-secondary:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(149, 165, 166, 0.3); }
@media (max-width: 768px) { .message-form-row { flex-direction: column; align-items: stretch; } .message-input-group { min-width: auto; } .message-btn { align-self: flex-start; } }
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
<h2>Manage Payment Proofs 
    <?php if ($current_user_role === 'sub_admin'): ?>
        <span style="font-size: 14px; color: #e67e22;">(Showing payments from your referral network only)</span>
    <?php endif; ?>
</h2>

<?php if (isset($success)): ?>
<div class="success-message"><p><?php echo htmlspecialchars($success); ?></p></div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="error-message"><p><?php echo htmlspecialchars($error); ?></p></div>
<?php endif; ?>

<div class="table-container">
<table>
<thead>
<tr>
<th>ID</th>
<th>User</th>
<th>Amount</th>
<th>File</th>
<th>Payment Method</th>
<th>Status</th>
<th>Uploaded At</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php if (empty($payments)): ?>
    <tr>
        <td colspan="8" style="text-align: center; padding: 40px; color: #6c757d;">
            <?php if ($current_user_role === 'sub_admin'): ?>
                No payments found from your referral network.
            <?php else: ?>
                No payments found.
            <?php endif; ?>
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($payments as $payment): ?>
    <tr>
        <td>
            <?php echo $payment['id']; ?>
            <?php
            try {
                $special_task_stmt = $pdo->prepare("
                    SELECT std.*, p.amount as paid_amount 
                    FROM special_task_deposits std
                    JOIN payments p ON std.payment_id = p.id
                    WHERE std.payment_id = ?
                ");
                $special_task_stmt->execute([$payment['id']]);
                $special_task_deposit = $special_task_stmt->fetch();
            } catch (Exception $e) {
                $special_task_deposit = false;
            }
            
            if ($special_task_deposit): ?>
                <span class="special-task-badge" title="Special Task Deposit">🛡️</span>
            <?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($payment['username']); ?></td>
        <td>$<?php echo number_format($payment['amount'], 2); ?></td>
        <td><a href="https://thepossiblitesto.kesug.com/<?php echo $payment['file_path']; ?>" target="_blank" class="btn btn-primary">View Proof</a></td>
        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
        <td><span class="status-<?php echo $payment['status']; ?>"><?php echo ucfirst($payment['status']); ?></span></td>
        <td><?php echo date('M j, Y g:i A', strtotime($payment['uploaded_at'])); ?></td>
        <td>
        <?php if ($payment['status'] === 'pending'): ?>
            <a href="?approve=<?php echo $payment['id']; ?>" class="btn btn-primary" onclick="return confirm('Are you sure you want to approve this payment?')">Approve</a>
            <a href="?reject=<?php echo $payment['id']; ?>" class="btn btn-secondary" onclick="return confirm('Are you sure you want to reject this payment?')">Reject</a>
        <?php else: ?>
            <span>No actions</span>
        <?php endif; ?>
        </td>
    </tr>
    <?php if ($special_task_deposit): ?>
    <tr style="background: rgba(230, 126, 34, 0.05);">
    <td colspan="8" class="special-task-info">
        <strong>🛡️ Special Task Information:</strong>
        Task Position: #<?php echo $special_task_deposit['task_position']; ?> | 
        Required Amount: $<?php echo number_format($special_task_deposit['required_amount'], 2); ?> | 
        Paid Amount: $<?php echo number_format($special_task_deposit['paid_amount'], 2); ?> |
        Credited Amount: $<?php echo number_format(isset($special_task_deposit['credited_amount']) ? $special_task_deposit['credited_amount'] : 0, 2); ?> |
        Deposit Status: <span class="status-<?php echo $special_task_deposit['status']; ?>"><?php echo ucfirst($special_task_deposit['status']); ?></span>
        <?php if ($special_task_deposit['verified_at']): ?>
            | Verified: <?php echo date('M j, Y g:i A', strtotime($special_task_deposit['verified_at'])); ?>
        <?php endif; ?>
        
        <?php 
        $cumulative_stmt = $pdo->prepare("
            SELECT SUM(credited_amount) as total_credited 
            FROM special_task_deposits 
            WHERE user_id = ? AND task_position = ? AND status IN ('approved', 'pending')
        ");
        $cumulative_stmt->execute([$special_task_deposit['user_id'], $special_task_deposit['task_position']]);
        $cumulative_result = $cumulative_stmt->fetch();
        $total_already_credited = $cumulative_result['total_credited'] ?? 0;
        
        $remaining_required = $special_task_deposit['required_amount'] - $total_already_credited;
        ?>
        
        <?php if ($remaining_required > 0): ?>
            <div class="progress-info">
                <strong>Progress:</strong> $<?php echo number_format($total_already_credited, 2); ?> credited of $<?php echo number_format($special_task_deposit['required_amount'], 2); ?> | 
                <strong>Remaining:</strong> $<?php echo number_format($remaining_required, 2); ?>
            </div>
        <?php else: ?>
            <div class="progress-info">
                <strong>✅ Completed:</strong> Full amount of $<?php echo number_format($special_task_deposit['required_amount'], 2); ?> has been credited.
            </div>
        <?php endif; ?>
        
        <?php if ($special_task_deposit['remaining_amount'] > 0): ?>
            <div class="remaining-amount-info">
                <strong>Admin Message:</strong> User needs to deposit additional $<?php echo number_format($special_task_deposit['remaining_amount'], 2); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($special_task_deposit['status'] === 'pending' && $remaining_required > 0): ?>
            <div class="admin-message-form">
                <h4>💬 Send Message to User</h4>
                <form method="POST">
                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                    <div class="message-form-row">
                        <div class="message-input-group">
                            <label for="remaining_amount_<?php echo $payment['id']; ?>">
                                Additional Amount Needed (Max: $<?php echo number_format($remaining_required, 2); ?>):
                            </label>
                            <input type="number" 
                                   id="remaining_amount_<?php echo $payment['id']; ?>" 
                                   name="remaining_amount" 
                                   class="message-input" 
                                   step="0.01" 
                                   min="0.01" 
                                   max="<?php echo $remaining_required; ?>"
                                   placeholder="Enter additional amount needed"
                                   required>
                        </div>
                        <button type="submit" name="send_message" class="message-btn">
                            📤 Send Message
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</main>

<footer>&copy; <?php echo date('Y'); ?> Task Website</footer>
</div>
</body>
</html>