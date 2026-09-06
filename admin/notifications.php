<?php
require_once '../includes/admin_auth.php';

// Mark notification as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = (int)$_GET['mark_read'];
    $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = TRUE WHERE id = ?");
    $stmt->execute([$notification_id]);
    header("Location: notifications.php");
    exit();
}

// Mark all notifications as read
if (isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = TRUE");
    $stmt->execute();
    header("Location: notifications.php");
    exit();
}

// Handle deposit approval/rejection
if (isset($_POST['update_deposit_status'])) {
    $deposit_id = (int)$_POST['deposit_id'];
    $status = $_POST['status'];
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    // Update deposit status
    $stmt = $pdo->prepare("UPDATE deposits SET status = ?, admin_notes = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $admin_notes, $deposit_id]);
    
    // If approved, update user balance
    if ($status === 'approved') {
        $stmt = $pdo->prepare("SELECT user_id, amount FROM deposits WHERE id = ?");
        $stmt->execute([$deposit_id]);
        $deposit = $stmt->fetch();
        
        if ($deposit) {
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$deposit['amount'], $deposit['user_id']]);
            
            // Send notification to user
            $message = "Your deposit of $" . number_format($deposit['amount'], 2) . " has been approved.";
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt->execute([$deposit['user_id'], $message]);
        }
    } elseif ($status === 'rejected') {
        // Send rejection notification to user
        $stmt = $pdo->prepare("SELECT user_id, amount FROM deposits WHERE id = ?");
        $stmt->execute([$deposit_id]);
        $deposit = $stmt->fetch();
        
        if ($deposit) {
            $message = "Your deposit of $" . number_format($deposit['amount'], 2) . " has been rejected.";
            if (!empty($admin_notes)) {
                $message .= " Reason: " . $admin_notes;
            }
            
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt->execute([$deposit['user_id'], $message]);
        }
    }
    
    // Redirect to avoid form resubmission
    header("Location: notifications.php");
    exit();
}

// Handle support ticket response
if (isset($_POST['update_ticket_status'])) {
    $ticket_id = (int)$_POST['ticket_id'];
    $status = $_POST['status'];
    $admin_response = trim($_POST['admin_response']);
    
    // Update ticket status
    $stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, admin_response = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $admin_response, $ticket_id]);
    
    // Get ticket info
    $stmt = $pdo->prepare("SELECT user_id, subject FROM support_tickets WHERE id = ?");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch();
    
    if ($ticket) {
        // Notify user about ticket update
        $user_message = "Your support ticket #{$ticket_id} ({$ticket['subject']}) has been {$status}.";
        if (!empty($admin_response)) {
            $user_message .= "\n\nAdmin Response: " . $admin_response;
        }
        
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->execute([$ticket['user_id'], $user_message]);
    }
    
    // Redirect to avoid form resubmission
    header("Location: notifications.php");
    exit();
}

// Get admin notifications
$stmt = $pdo->prepare("
    SELECT an.*, 
           st.subject as ticket_subject, st.message as ticket_message, st.status as ticket_status,
           st.created_at as ticket_date, u.username as user_name, u.id as user_id,
           d.id as deposit_id, d.proof_image, d.amount, d.payment_method, 
           d.user_id as deposit_user_id, d.created_at as deposit_date,
           du.username as depositor_username
    FROM admin_notifications an
    LEFT JOIN support_tickets st ON an.reference_id = st.id AND an.type = 'support_ticket'
    LEFT JOIN users u ON st.user_id = u.id
    LEFT JOIN deposits d ON an.reference_id = d.id AND an.type = 'deposit'
    LEFT JOIN users du ON d.user_id = du.id
    ORDER BY an.is_read, an.created_at DESC
");
$stmt->execute();
$notifications = $stmt->fetchAll();

// Get unread notifications count
$unread_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM admin_notifications WHERE is_read = FALSE");
$unread_stmt->execute();
$unread_count = $unread_stmt->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Notifications - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background: #343a40;
            color: white;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        nav a {
            color: white;
            text-decoration: none;
            margin-right: 15px;
        }
        nav a:hover {
            text-decoration: underline;
        }
        .notification {
            background: white;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .notification.unread {
            border-left: 4px solid #3498db;
            background: #f8ffff;
        }
        .notification.read {
            opacity: 0.7;
        }
        .notification:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .notification-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .btn-success {
            background-color: #198754;
            border-color: #198754;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #000;
        }
        .modal-content {
            border-radius: 10px;
        }
        .proof-image {
            max-width: 100%;
            border-radius: 5px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .proof-image:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .deposit-info, .ticket-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #6c757d;
        }
        .deposit-info {
            border-left-color: #28a745;
        }
        .ticket-info {
            border-left-color: #ffc107;
        }
        .image-info {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .badge {
            font-size: 0.85em;
            padding: 0.35em 0.65em;
        }
        .sound-control {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: white;
            padding: 10px;
            border-radius: 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .notification-type {
            font-weight: bold;
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .filter-buttons {
            margin-bottom: 20px;
        }
        .filter-buttons .btn {
            margin-right: 5px;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #0d6efd;
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
                <a href="settings.php">Settings</a>
                <?php if ($_SESSION['role'] === 'MAIN ADMIN'): ?>
                    <a href="usersedit.php">Users Edit</a>
                <?php endif; ?>
                <a href="notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </header>
        
        <main>
            <h2>Admin Notifications</h2>
            
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card text-center">
                        <div class="stats-number"><?php echo $unread_count; ?></div>
                        <div>Unread Notifications</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card text-center">
                        <?php
                        $pending_deposits = $pdo->query("SELECT COUNT(*) as count FROM deposits WHERE status = 'pending'")->fetch()['count'];
                        ?>
                        <div class="stats-number"><?php echo $pending_deposits; ?></div>
                        <div>Pending Deposits</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card text-center">
                        <?php
                        $open_tickets = $pdo->query("SELECT COUNT(*) as count FROM support_tickets WHERE status IN ('open', 'pending')")->fetch()['count'];
                        ?>
                        <div class="stats-number"><?php echo $open_tickets; ?></div>
                        <div>Open Tickets</div>
                    </div>
                </div>
            </div>
            
            <?php if ($unread_count > 0): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <a href="?mark_all_read" class="btn btn-primary">
                            <i class="fas fa-check-double me-1"></i>Mark All as Read
                        </a>
                        <div class="btn-group filter-buttons">
                            <a href="?filter=all" class="btn btn-outline-primary">All</a>
                            <a href="?filter=unread" class="btn btn-outline-primary">Unread</a>
                            <a href="?filter=deposits" class="btn btn-outline-success">Deposits</a>
                            <a href="?filter=tickets" class="btn btn-outline-warning">Tickets</a>
                        </div>
                    </div>
                    <div>
                        <button id="enableSound" class="btn btn-success">
                            <i class="fas fa-volume-up me-1"></i>Enable Sound
                        </button>
                        <button id="disableSound" class="btn btn-secondary" style="display: none;">
                            <i class="fas fa-volume-mute me-1"></i>Disable Sound
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (empty($notifications)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No notifications found.
                </div>
            <?php else: ?>
                <div class="notifications-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                            <div class="notification-type">
                                <i class="fas fa-<?php echo $notification['type'] === 'support_ticket' ? 'ticket-alt' : 'money-bill-wave'; ?> me-1"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $notification['type'])); ?>
                            </div>
                            <p><strong><?php echo htmlspecialchars($notification['title']); ?></strong></p>
                            <p><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                            
                            <?php if ($notification['type'] === 'deposit' && $notification['deposit_id']): ?>
                                <div class="deposit-info">
                                    <p><strong>Deposit Details:</strong></p>
                                    <p><strong>User:</strong> <?php echo htmlspecialchars($notification['depositor_username']); ?> (ID: <?php echo $notification['deposit_user_id']; ?>)</p>
                                    <p><strong>Amount:</strong> $<?php echo number_format($notification['amount'], 2); ?></p>
                                    <p><strong>Method:</strong> <?php echo htmlspecialchars($notification['payment_method']); ?></p>
                                    <p><strong>Date:</strong> <?php echo date('M j, Y g:i A', strtotime($notification['deposit_date'])); ?></p>
                                    
                                    <?php if ($notification['proof_image']): ?>
                                        <p>
                                            <strong>Proof Image:</strong><br>
                                            <?php
                                            $image_path = "../uploads/deposits/" . htmlspecialchars($notification['proof_image']);
                                            $image_exists = file_exists($image_path);
                                            ?>
                                            <?php if ($image_exists): ?>
                                                <img src="<?php echo $image_path; ?>" 
                                                     class="proof-image" 
                                                     style="max-width: 300px;" 
                                                     onclick="viewImage(this.src)"
                                                     alt="Deposit proof">
                                                <div class="image-info">
                                                    <i class="fas fa-image me-1"></i> 
                                                    <?php echo htmlspecialchars($notification['proof_image']); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-warning">
                                                    <i class="fas fa-exclamation-triangle me-2"></i> 
                                                    Proof image not found: <?php echo htmlspecialchars($notification['proof_image']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if ($notification['status'] === 'pending'): ?>
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#depositModal<?php echo $notification['deposit_id']; ?>">
                                                <i class="fas fa-check me-1"></i>Review Deposit
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <p class="mt-2">
                                            <strong>Status:</strong> 
                                            <span class="badge bg-<?php echo $notification['status'] === 'approved' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($notification['status']); ?>
                                            </span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Deposit Review Modal -->
                                <div class="modal fade" id="depositModal<?php echo $notification['deposit_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Review Deposit #<?php echo $notification['deposit_id']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <input type="hidden" name="deposit_id" value="<?php echo $notification['deposit_id']; ?>">
                                                <div class="modal-body">
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>User:</strong></label>
                                                                <p><?php echo htmlspecialchars($notification['depositor_username']); ?> (ID: <?php echo $notification['deposit_user_id']; ?>)</p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>Amount:</strong></label>
                                                                <p>$<?php echo number_format($notification['amount'], 2); ?></p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>Payment Method:</strong></label>
                                                                <p><?php echo htmlspecialchars($notification['payment_method']); ?></p>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label"><strong>Date:</strong></label>
                                                                <p><?php echo date('M j, Y g:i A', strtotime($notification['deposit_date'])); ?></p>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <?php if ($notification['proof_image'] && $image_exists): ?>
                                                                <label class="form-label"><strong>Proof Image:</strong></label>
                                                                <img src="<?php echo $image_path; ?>" 
                                                                     class="proof-image img-fluid" 
                                                                     alt="Deposit proof">
                                                                <div class="image-info">
                                                                    <i class="fas fa-image me-1"></i> 
                                                                    <?php echo htmlspecialchars($notification['proof_image']); ?>
                                                                </div>
                                                            <?php elseif ($notification['proof_image']): ?>
                                                                <div class="alert alert-warning">
                                                                    <i class="fas fa-exclamation-triangle me-2"></i> 
                                                                    Proof image not found: <?php echo htmlspecialchars($notification['proof_image']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label for="admin_notes<?php echo $notification['deposit_id']; ?>" class="form-label">Admin Notes</label>
                                                        <textarea class="form-control" id="admin_notes<?php echo $notification['deposit_id']; ?>" 
                                                                  name="admin_notes" rows="3" placeholder="Optional notes for the user"></textarea>
                                                    </div>
                                                    
                                                    <div class="text-center">
                                                        <button type="submit" name="update_deposit_status" value="approved" class="btn btn-success me-2">
                                                            <i class="fas fa-check me-1"></i> Approve Deposit
                                                        </button>
                                                        <button type="submit" name="update_deposit_status" value="rejected" class="btn btn-danger">
                                                            <i class="fas fa-times me-1"></i> Reject Deposit
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            
                            <?php elseif ($notification['type'] === 'support_ticket' && $notification['ticket_subject']): ?>
                                <div class="ticket-info">
                                    <p><strong>Ticket Details:</strong></p>
                                    <p><strong>User:</strong> <?php echo htmlspecialchars($notification['user_name']); ?> (ID: <?php echo $notification['user_id']; ?>)</p>
                                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($notification['ticket_subject']); ?></p>
                                    <p><strong>Message:</strong> <?php echo nl2br(htmlspecialchars($notification['ticket_message'])); ?></p>
                                    <p><strong>Date:</strong> <?php echo date('M j, Y g:i A', strtotime($notification['ticket_date'])); ?></p>
                                    <p>
                                        <strong>Status:</strong> 
                                        <span class="badge bg-<?php 
                                            echo $notification['ticket_status'] === 'open' ? 'success' : 
                                                 ($notification['ticket_status'] === 'pending' ? 'warning' : 'secondary'); 
                                        ?>">
                                            <?php echo ucfirst($notification['ticket_status']); ?>
                                        </span>
                                    </p>
                                    
                                    <?php if ($notification['ticket_status'] === 'open' || $notification['ticket_status'] === 'pending'): ?>
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ticketModal<?php echo $notification['reference_id']; ?>">
                                                <i class="fas fa-reply me-1"></i>Respond to Ticket
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Ticket Response Modal -->
                                <div class="modal fade" id="ticketModal<?php echo $notification['reference_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Respond to Ticket #<?php echo $notification['reference_id']; ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST" action="">
                                                <input type="hidden" name="ticket_id" value="<?php echo $notification['reference_id']; ?>">
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label"><strong>User:</strong></label>
                                                        <p><?php echo htmlspecialchars($notification['user_name']); ?> (ID: <?php echo $notification['user_id']; ?>)</p>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><strong>Subject:</strong></label>
                                                        <p><?php echo htmlspecialchars($notification['ticket_subject']); ?></p>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><strong>Original Message:</strong></label>
                                                        <p><?php echo nl2br(htmlspecialchars($notification['ticket_message'])); ?></p>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><strong>Date:</strong></label>
                                                        <p><?php echo date('M j, Y g:i A', strtotime($notification['ticket_date'])); ?></p>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label for="admin_response<?php echo $notification['reference_id']; ?>" class="form-label">Your Response</label>
                                                        <textarea class="form-control" id="admin_response<?php echo $notification['reference_id']; ?>" 
                                                                  name="admin_response" rows="4" placeholder="Enter your response to the user..."></textarea>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label for="status<?php echo $notification['reference_id']; ?>" class="form-label">Update Status</label>
                                                        <select class="form-control" id="status<?php echo $notification['reference_id']; ?>" name="status">
                                                            <option value="open" <?php echo $notification['ticket_status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                                            <option value="pending" <?php echo $notification['ticket_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                            <option value="closed">Closed</option>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="text-center">
                                                        <button type="submit" name="update_ticket_status" class="btn btn-success">
                                                            <i class="fas fa-check me-1"></i> Update Ticket
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="notification-meta">
                                <span><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></span>
                                <?php if (!$notification['is_read']): ?>
                                    <a href="?mark_read=<?php echo $notification['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-check me-1"></i>Mark as Read
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
        
        <footer class="mt-4 text-center">
            <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
        </footer>
    </div>
    
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Proof Image</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid" alt="Proof image">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Audio element for notification sound -->
    <audio id="notificationSound" preload="auto">
        <source src="../assets/sounds/notification.mp3" type="audio/mpeg">
        <source src="../assets/sounds/notification.ogg" type="audio/ogg">
    </audio>

    <!-- Sound Control -->
    <div class="sound-control">
        <button id="soundToggle" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-volume-mute"></i>
        </button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let soundEnabled = false;
        const notificationSound = document.getElementById('notificationSound');
        
        // Toggle sound
        document.getElementById('soundToggle').addEventListener('click', function() {
            soundEnabled = !soundEnabled;
            const icon = this.querySelector('i');
            if (soundEnabled) {
                icon.className = 'fas fa-volume-up';
                this.className = 'btn btn-sm btn-outline-success';
                // Test the sound
                try {
                    notificationSound.play();
                } catch (e) {
                    console.log('Could not play sound:', e);
                }
            } else {
                icon.className = 'fas fa-volume-mute';
                this.className = 'btn btn-sm btn-outline-primary';
            }
        });
        
        function viewImage(src) {
            document.getElementById('modalImage').src = src;
            var imageModal = new bootstrap.Modal(document.getElementById('imageModal'));
            imageModal.show();
        }
        
        // Check for new notifications every 30 seconds
        setInterval(function() {
            fetch('../includes/check_admin_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.unread_count > 0 && soundEnabled) {
                        // Play notification sound
                        try {
                            notificationSound.play();
                        } catch (e) {
                            console.log('Could not play sound:', e);
                        }
                        
                        // Update notification badge
                        const notificationLink = document.querySelector('a[href="notifications.php"]');
                        if (notificationLink) {
                            notificationLink.textContent = `Notifications (${data.unread_count})`;
                        }
                        
                        // Show desktop notification if permitted
                        if (Notification.permission === 'granted') {
                            new Notification('New Notification', {
                                body: 'You have new notifications waiting',
                                icon: '../assets/images/notification-icon.png'
                            });
                        }
                    }
                })
                .catch(error => console.error('Error checking notifications:', error));
        }, 30000); // Check every 30 seconds
        
        // Request notification permission
        document.addEventListener('DOMContentLoaded', function() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        });
        
        // Auto-open modals if specified in URL
        <?php if (isset($_GET['review_deposit']) && is_numeric($_GET['review_deposit'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var depositModal = new bootstrap.Modal(document.getElementById('depositModal<?php echo $_GET['review_deposit']; ?>'));
                depositModal.show();
            });
        <?php endif; ?>
        
        <?php if (isset($_GET['review_ticket']) && is_numeric($_GET['review_ticket'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var ticketModal = new bootstrap.Modal(document.getElementById('ticketModal<?php echo $_GET['review_ticket']; ?>'));
                ticketModal.show();
            });
        <?php endif; ?>
    </script>
</body>
</html>