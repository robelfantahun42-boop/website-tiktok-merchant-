<?php
require_once '../includes/admin_auth.php';

$errors = [];
$success = '';

// Handle deposit status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }
    
    $deposit_id = (int)$_POST['deposit_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve') {
        $pdo->beginTransaction();
        try {
            // Get deposit details
            $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ?");
            $stmt->execute([$deposit_id]);
            $deposit = $stmt->fetch();
            
            if ($deposit && $deposit['status'] === 'pending') {
                // Update deposit status
                $stmt = $pdo->prepare("UPDATE deposits SET status = 'approved' WHERE id = ?");
                $stmt->execute([$deposit_id]);
                
                // Add funds to user balance
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$deposit['amount'], $deposit['user_id']]);
                
                // Record transaction
                $stmt = $pdo->prepare("INSERT INTO transactions (admin_id, user_id, amount, type, note) VALUES (?, ?, ?, 'topup', ?)");
                $note = "Deposit approved: " . $deposit['payment_method'] . 
                       ($deposit['transaction_id'] ? " (ID: " . $deposit['transaction_id'] . ")" : "");
                $stmt->execute([$_SESSION['user_id'], $deposit['user_id'], $deposit['amount'], $note]);
                
                // Send notification to user
                $message = "Your deposit of $" . format_balance($deposit['amount']) . " has been approved. Your balance has been updated.";
                send_notification($deposit['user_id'], $message);
                
                $pdo->commit();
                $success = "Deposit approved successfully. User's balance has been updated.";
            } else {
                $errors[] = "Deposit not found or already processed.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Failed to approve deposit: " . $e->getMessage();
        }
    } 
    elseif ($action === 'reject') {
        $reason = trim($_POST['reason']);
        
        if (empty($reason)) {
            $errors[] = "Please provide a reason for rejection.";
        } else {
            $stmt = $pdo->prepare("UPDATE deposits SET status = 'rejected', admin_notes = ? WHERE id = ?");
            if ($stmt->execute([$reason, $deposit_id])) {
                // Send notification to user
                $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ?");
                $stmt->execute([$deposit_id]);
                $deposit = $stmt->fetch();
                
                $message = "Your deposit of $" . format_balance($deposit['amount']) . " was rejected. Reason: " . htmlspecialchars($reason);
                send_notification($deposit['user_id'], $message);
                
                $success = "Deposit rejected successfully.";
            } else {
                $errors[] = "Failed to reject deposit.";
            }
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query based on filters
$where_clause = "";
$params = [];

if ($status_filter !== 'all') {
    $where_clause = "WHERE d.status = ?";
    $params[] = $status_filter;
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM deposits d JOIN users u ON d.user_id = u.id $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_deposits = $count_stmt->fetch()['total'];
$total_pages = ceil($total_deposits / $limit);

// Get deposits with user information
$sql = "
    SELECT d.*, u.username, u.email 
    FROM deposits d 
    JOIN users u ON d.user_id = u.id 
    $where_clause
    ORDER BY d.created_at DESC 
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deposits = $stmt->fetchAll();

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
    <title>Manage Deposits - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 5px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
        }
        .pagination a {
            margin: 0 5px;
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #007bff;
        }
        .pagination a.active {
            background-color: #007bff;
            color: white;
            border: 1px solid #007bff;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        .btn-approve {
            background-color: #28a745;
            color: white;
        }
        .btn-reject {
            background-color: #dc3545;
            color: white;
        }
        .proof-image {
            max-width: 100px;
            max-height: 100px;
            cursor: pointer;
        }
        .proof-modal-image {
            max-width: 100%;
            max-height: 80vh;
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
                <a href="deposits.php">Deposits</a>
                <a href="tasks.php">Tasks</a>
                <a href="payments.php">Payments</a>
                <a href="commissions.php">Commissions</a>
                <a href="settings.php">Settings</a>
                <a href="notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
                <a href="../logout.php">Logout</a>
            </nav>
        </header>
        
        <main>
            <h2>Manage Deposit Requests</h2>
            
            <?php if (!empty($success)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Filter Form -->
            <div class="filter-section">
                <form method="get" class="filter-form">
                    <label for="status">Filter by Status:</label>
                    <select id="status" name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Deposits</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </form>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Transaction ID</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deposits)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">No deposits found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($deposits as $deposit): ?>
                                <tr>
                                    <td><?php echo $deposit['id']; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($deposit['username']); ?><br>
                                        <small><?php echo htmlspecialchars($deposit['email']); ?></small>
                                    </td>
                                    <td>$<?php echo format_balance($deposit['amount']); ?></td>
                                    <td><?php echo htmlspecialchars($deposit['payment_method']); ?></td>
                                    <td><?php echo $deposit['transaction_id'] ? htmlspecialchars($deposit['transaction_id']) : 'N/A'; ?></td>
                                    <td>
                                        <?php if (!empty($deposit['proof_image'])): ?>
                                            <img src="../uploads/proofs/<?php echo htmlspecialchars($deposit['proof_image']); ?>" 
                                                 alt="Proof" class="proof-image" 
                                                 onclick="openProofModal(this.src)">
                                        <?php else: ?>
                                            No proof
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo $deposit['status']; ?>">
                                            <?php echo ucfirst($deposit['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($deposit['created_at'])); ?></td>
                                    <td>
                                        <?php if ($deposit['status'] === 'pending'): ?>
                                            <div class="action-buttons">
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                    <input type="hidden" name="deposit_id" value="<?php echo $deposit['id']; ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" class="btn btn-approve" onclick="return confirm('Are you sure you want to approve this deposit?')">Approve</button>
                                                </form>
                                                <button class="btn btn-reject" onclick="openRejectModal(<?php echo $deposit['id']; ?>)">Reject</button>
                                            </div>
                                        <?php else: ?>
                                            <em>Processed</em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?>" 
                               class="<?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeRejectModal()">&times;</span>
            <h3>Reject Deposit</h3>
            <form id="rejectForm" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="deposit_id" id="rejectDepositId">
                <input type="hidden" name="action" value="reject">
                
                <div class="form-group">
                    <label for="reason">Reason for rejection:</label>
                    <textarea id="reason" name="reason" rows="4" required style="width: 100%;"></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-reject">Confirm Rejection</button>
                    <button type="button" class="btn" onclick="closeRejectModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Proof Image Modal -->
    <div id="proofModal" class="modal" onclick="closeProofModal()">
        <span class="close" onclick="closeProofModal()">&times;</span>
        <img class="proof-modal-image" id="proofModalImage">
    </div>
    
    <script>
        function openRejectModal(depositId) {
            document.getElementById('rejectDepositId').value = depositId;
            document.getElementById('rejectModal').style.display = 'block';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.getElementById('reason').value = '';
        }
        
        function openProofModal(imageSrc) {
            document.getElementById('proofModalImage').src = imageSrc;
            document.getElementById('proofModal').style.display = 'block';
        }
        
        function closeProofModal() {
            document.getElementById('proofModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeRejectModal();
                closeProofModal();
            }
        };
    </script>
</body>
</html>