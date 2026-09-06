<?php
require_once '../includes/admin_auth.php';

// Mark notification as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = (int)$_GET['mark_read'];
    $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = TRUE WHERE id = ?");
    $stmt->execute([$notification_id]);
}

// Mark all notifications as read
if (isset($_GET['mark_all_read'])) {
    $stmt = $pdo->prepare("UPDATE admin_notifications SET is_read = TRUE");
    $stmt->execute();
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
    header("Location: admin_notifications.php");
    exit();
}

// Get admin notifications
$stmt = $pdo->prepare("
    SELECT an.*, st.subject as ticket_subject, st.message as ticket_message, 
           st.created_at as ticket_date, u.username as user_name, u.id as user_id,
           st.status as ticket_status
    FROM admin_notifications an
    LEFT JOIN support_tickets st ON an.reference_id = st.id AND an.type = 'support_ticket'
    LEFT JOIN users u ON st.user_id = u.id
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
            max-width: 1200px;
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
        }
        .notification.unread {
            border-left: 4px solid #3498db;
        }
        .notification.read {
            opacity: 0.7;
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
        .ticket-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 4px solid #6c757d;
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
                <a href="admin_notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </header>
        
        <main>
            <h2>Admin Notifications</h2>
            
            <?php if ($unread_count > 0): ?>
                <p>
                    <a href="?mark_all_read" class="btn btn-primary">Mark All as Read</a>
                    <button id="enableSound" class="btn btn-success">
                        <i class="fas fa-volume-up me-1"></i>Enable Sound
                    </button>
                    <button id="disableSound" class="btn btn-secondary" style="display: none;">
                        <i class="fas fa-volume-mute me-1"></i>Disable Sound
                    </button>
                </p>
            <?php endif; ?>
            
            <?php if (empty($notifications)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No notifications found.
                </div>
            <?php else: ?>
                <div class="notifications-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                            <p><strong><?php echo htmlspecialchars($notification['title']); ?></strong></p>
                            <p><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                            
                            <?php if ($notification['type'] === 'support_ticket' && $notification['ticket_subject']): ?>
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
                                    <a href="?mark_read=<?php echo $notification['id']; ?>" class="btn btn-primary btn-sm">Mark as Read</a>
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
    
    <!-- Audio element for notification sound -->
    <audio id="notificationSound" preload="auto">
        <source src="../assets/sounds/notification.mp3" type="audio/mpeg">
        <source src="../assets/sounds/notification.ogg" type="audio/ogg">
    </audio>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let soundEnabled = false;
        const notificationSound = document.getElementById('notificationSound');
        
        document.getElementById('enableSound').addEventListener('click', function() {
            soundEnabled = true;
            document.getElementById('enableSound').style.display = 'none';
            document.getElementById('disableSound').style.display = 'inline-block';
            
            // Test the sound
            try {
                notificationSound.play();
            } catch (e) {
                console.log('Could not play sound:', e);
            }
        });
        
        document.getElementById('disableSound').addEventListener('click', function() {
            soundEnabled = false;
            document.getElementById('enableSound').style.display = 'inline-block';
            document.getElementById('disableSound').style.display = 'none';
        });
        
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
                        const notificationLink = document.querySelector('a[href="admin_notifications.php"]');
                        if (notificationLink) {
                            notificationLink.textContent = `Notifications (${data.unread_count})`;
                        }
                    }
                })
                .catch(error => console.error('Error checking notifications:', error));
        }, 30000); // Check every 30 seconds
    </script>
</body>
</html>