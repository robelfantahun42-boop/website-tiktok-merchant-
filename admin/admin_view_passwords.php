<?php
session_start();

require_once '../includes/init.php';
require_once '../includes/admin_auth.php';
require_once '../includes/functions.php';

require_admin(); // ensure admin is logged in

// Check if user has permission to view passwords
$current_admin_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($current_admin_role, ['main admin', 'main_admin', 'admin'])) {
    header('Location: index.php');
    exit;
}

// Get all users with their passwords (SIMPLIFIED QUERY)
$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.phone, u.created_at, 
           up.encrypted_password, up.updated_at as password_updated
    FROM users u
    LEFT JOIN user_passwords up ON u.id = up.user_id
    ORDER BY u.created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

// Get unread notifications count
$unread_stmt = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = $unread_stmt->fetch()['count'];

// Get pending withdrawals count for header
$pending_withdrawals = $pdo->query("SELECT COUNT(*) as count FROM withdrawals WHERE status = 'pending'")->fetch()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View User Passwords - Task Website</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        
        body {
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        header h1 {
            text-align: center;
            margin-bottom: 15px;
            font-size: 2rem;
        }
        
        nav {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        nav a:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        main {
            padding: 20px 0;
        }
        
        h2 {
            color: #333;
            margin-bottom: 20px;
            text-align: center;
            border-bottom: 2px solid #fe2858;
            padding-bottom: 10px;
        }
        
        .security-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .security-warning i {
            color: #ffc107;
            font-size: 1.2rem;
        }
        
        .action-buttons {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a0db5 0%, #1c68e8 100%);
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, #1e9e8a 100%);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-box {
            background: rgba(4, 4, 4, 0.8);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid rgba(42, 240, 234, 0.3);
            text-align: center;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2af0ea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #ffffff;
        }
        
        .table-container {
            overflow-x: auto;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(42, 240, 234, 0.1);
            color: #333333;
        }
        
        th {
            background: rgba(42, 240, 234, 0.1);
            color: #2af0ea;
            font-weight: bold;
        }
        
        tr:hover {
            background: rgba(42, 240, 234, 0.05);
        }
        
        .password-cell {
            font-family: 'Courier New', monospace;
            background-color: #fff3cd;
            font-weight: bold;
            max-width: 200px;
            word-break: break-all;
        }
        
        .no-password {
            color: #6c757d;
            font-style: italic;
        }
        
        .decryption-error {
            color: #dc3545;
            background-color: #f8d7da;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            display: inline-block;
            margin-top: 5px;
        }
        
        .decryption-success {
            color: #28a745;
            background-color: #d4edda;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            display: inline-block;
            margin-top: 5px;
        }
        
        .password-updated {
            font-size: 12px;
            color: #666;
            font-style: italic;
        }
        
        .encrypted-preview {
            font-family: 'Courier New', monospace;
            font-size: 10px;
            color: #6c757d;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #e9ecef;
            word-break: break-all;
        }
        
        .phone-number {
            color: #2af0ea;
            font-weight: 500;
        }
        
        .join-date {
            color: #333333;
            font-size: 12px;
        }
        
        footer {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            border-top: 1px solid #e9ecef;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            nav {
                flex-direction: column;
                align-items: center;
            }
            
            nav a {
                width: 100%;
                justify-content: center;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 10px;
            }
            
            .password-cell {
                max-width: 150px;
                font-size: 11px;
            }
        }
        
        @media (max-width: 480px) {
            .stats {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 10px;
            }
            
            header {
                padding: 15px;
            }
            
            header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>Task Website - Admin Panel</h1>
        <nav>
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="users.php"><i class="fas fa-users"></i> Users</a>
            <a href="tasks.php"><i class="fas fa-tasks"></i> Tasks</a>
            <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Payments</a>
            <a href="commissions.php"><i class="fas fa-hand-holding-usd"></i> Commissions</a>
            <a href="withdrawals.php"><i class="fas fa-wallet"></i> Withdrawals (<?php echo $pending_withdrawals; ?>)</a>
            <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="edittask.php"><i class="fas fa-edit"></i> Edit Task</a>
            <a href="notifications.php"><i class="fas fa-bell"></i> Notifications (<?php echo $unread_count; ?>)</a>
            <a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
    </header>
    
    <main>
        <h2>User Passwords </h2>
  
        <?php
        // Calculate statistics
        $with_passwords = 0;
        $decryption_errors = 0;
        $decryption_success = 0;
        foreach ($users as $user) {
            if (!empty($user['encrypted_password'])) {
                $with_passwords++;
                try {
                    $result = decrypt_password_for_admin($user['encrypted_password']);
                    if ($result) {
                        $decryption_success++;
                    } else {
                        $decryption_errors++;
                    }
                } catch (Exception $e) {
                    $decryption_errors++;
                }
            }
        }
        ?>
        
        <div class="stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo count($users); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $with_passwords; ?></div>
                <div class="stat-label">Users with Stored Passwords</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $decryption_success; ?></div>
                <div class="stat-label">Successfully Decrypted</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $decryption_errors; ?></div>
                <div class="stat-label">Decryption Errors</div>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Phone</th>
                        <th>Password (Decrypted)</th>
                        <th>Encrypted Preview</th>
                        <th>Registered</th>
                        <th>Password Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #6c757d;">
                                <h3>No users found</h3>
                                <p>There are no users in the system yet.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['id']) ?></td>
                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                            <td>
                                <span class="phone-number"><?= htmlspecialchars($user['phone']) ?></span>
                            </td>
                            <td class="password-cell">
                                <?php 
                                if ($user['encrypted_password']) {
                                    try {
                                        $decrypted_password = decrypt_password_for_admin($user['encrypted_password']);
                                        if ($decrypted_password) {
                                            echo htmlspecialchars($decrypted_password);
                                            echo '<div class="decryption-success"><i class="fas fa-check-circle"></i> Success</div>';
                                        } else {
                                            echo '<span class="decryption-error"><i class="fas fa-exclamation-circle"></i> Decryption Failed</span>';
                                        }
                                    } catch (Exception $e) {
                                        echo '<span class="decryption-error"><i class="fas fa-exclamation-triangle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</span>';
                                    }
                                } else {
                                    echo '<span class="no-password">N/A</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($user['encrypted_password']) {
                                    echo '<div class="encrypted-preview">' . htmlspecialchars(substr($user['encrypted_password'], 0, 30) . '...') . '</div>';
                                } else {
                                    echo '<span class="no-password">---</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="join-date"><?= htmlspecialchars(date('M j, Y', strtotime($user['created_at']))) ?></span>
                            </td>
                            <td>
                                <?php if ($user['password_updated']): ?>
                                    <span class="password-updated"><?= htmlspecialchars(date('M j, Y', strtotime($user['password_updated']))) ?></span>
                                <?php else: ?>
                                    <span class="no-password">Never</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>

<script>
    // Add any JavaScript functionality here if needed
    document.addEventListener('DOMContentLoaded', function() {
        console.log('User passwords page loaded');
        
        // Add click to copy functionality for passwords
        const passwordCells = document.querySelectorAll('.password-cell');
        passwordCells.forEach(cell => {
            cell.style.cursor = 'pointer';
            cell.title = 'Click to copy password';
            
            cell.addEventListener('click', function() {
                const passwordText = this.querySelector('span:first-child')?.textContent || 
                                   this.textContent.split('\n')[0];
                
                if (passwordText && passwordText !== 'N/A') {
                    navigator.clipboard.writeText(passwordText).then(() => {
                        const originalText = this.innerHTML;
                        this.innerHTML = '<span style="color: #28a745;">Copied to clipboard!</span>';
                        
                        setTimeout(() => {
                            this.innerHTML = originalText;
                        }, 2000);
                    }).catch(err => {
                        console.error('Failed to copy text: ', err);
                    });
                }
            });
        });
    });
</script>
</body>
</html>