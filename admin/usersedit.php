<?php
session_start();

require_once '../includes/init.php';
require_once '../includes/admin_auth.php';
require_once '../includes/functions.php';

require_admin(); // ensure admin is logged in

$errors = [];
$success = '';

// ==========================
// MAIN ADMIN CHECK
// ==========================
$current_admin_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($current_admin_role, ['main admin', 'main_admin'])) {
    header('Location: dashboard.php');
    exit();
}

// ==========================
// REFERRAL NUMBER FUNCTIONS
// ==========================
function ensureReferralNumbersExist() {
    global $pdo;
    
    // Get all users without referral numbers
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE referral_number IS NULL OR referral_number = ''");
    $stmt->execute();
    $users_without_referral = $stmt->fetchAll();
    
    $updated_count = 0;
    
    foreach ($users_without_referral as $user) {
        $referral_number = generateReferralNumber();
        
        $update_stmt = $pdo->prepare("UPDATE users SET referral_number = ? WHERE id = ?");
        if ($update_stmt->execute([$referral_number, $user['id']])) {
            $updated_count++;
        }
    }
    
    return $updated_count;
}

// Ensure all existing users have referral numbers
$new_referral_numbers_assigned = ensureReferralNumbersExist();

// ==========================
// USER MANAGEMENT LOGIC
// ==========================
if (isset($_POST['update_user'])) {
    $user_id = (int)$_POST['user_id'];
    $username = trim($_POST['username']);
    $phone = trim($_POST['phone']);
    $role = trim($_POST['role']);
    $balance = (float)$_POST['balance'];
    $password = trim($_POST['password']);
    $referral_number = trim($_POST['referral_number']);

    // Validate inputs
    if (empty($username) || empty($phone) || empty($role)) {
        $errors[] = "Username, phone, and role are required.";
    } elseif (!empty($referral_number) && !preg_match('/^\d{8}$/', $referral_number)) {
        $errors[] = "Referral number must be exactly 8 digits.";
    } else {
        try {
            // Check if username or phone already exists (excluding current user)
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR phone = ?) AND id != ?");
            $check_stmt->execute([$username, $phone, $user_id]);
            $user_exists = $check_stmt->fetchColumn();
            
            // Check if referral number already exists (excluding current user)
            if (!empty($referral_number)) {
                $referral_check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referral_number = ? AND id != ?");
                $referral_check_stmt->execute([$referral_number, $user_id]);
                $referral_exists = $referral_check_stmt->fetchColumn();
                
                if ($referral_exists > 0) {
                    $errors[] = "Referral number '$referral_number' already exists. Please choose a different referral number.";
                }
            }
            
            if ($user_exists > 0) {
                $errors[] = "Username or phone number already exists. Please choose different values.";
            } elseif (empty($errors)) {
                // Set admin flags based on role
                $is_admin = ($role === 'sub_admin' || $role === 'main_admin') ? 1 : 0;
                $is_main_admin = ($role === 'main_admin') ? 1 : 0;
                
                // Generate referral number if empty
                if (empty($referral_number)) {
                    $referral_number = generateReferralNumber();
                }
                
                if (!empty($password)) {
                    // Update with password
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, phone = ?, role = ?, balance = ?, password_hash = ?, is_admin = ?, is_main_admin = ?, referral_number = ? WHERE id = ?");
                    $stmt->execute([$username, $phone, $role, $balance, $password_hash, $is_admin, $is_main_admin, $referral_number, $user_id]);
                } else {
                    // Update without password
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, phone = ?, role = ?, balance = ?, is_admin = ?, is_main_admin = ?, referral_number = ? WHERE id = ?");
                    $stmt->execute([$username, $phone, $role, $balance, $is_admin, $is_main_admin, $referral_number, $user_id]);
                }
                $success = "User updated successfully!";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

if (isset($_POST['add_user'])) {
    $username = trim($_POST['new_username']);
    $phone = trim($_POST['new_phone']);
    $role = trim($_POST['new_role']);
    $balance = (float)$_POST['new_balance'];
    $password = trim($_POST['new_password']);
    $referral_number = trim($_POST['new_referral_number']);

    // Validate inputs
    if (empty($username) || empty($phone) || empty($role) || empty($password)) {
        $errors[] = "All fields are required for new user.";
    } elseif (!empty($referral_number) && !preg_match('/^\d{8}$/', $referral_number)) {
        $errors[] = "Referral number must be exactly 8 digits.";
    } else {
        try {
            // Check if username or phone already exists
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR phone = ?");
            $check_stmt->execute([$username, $phone]);
            $user_exists = $check_stmt->fetchColumn();
            
            // Check if referral number already exists
            if (!empty($referral_number)) {
                $referral_check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referral_number = ?");
                $referral_check_stmt->execute([$referral_number]);
                $referral_exists = $referral_check_stmt->fetchColumn();
                
                if ($referral_exists > 0) {
                    $errors[] = "Referral number '$referral_number' already exists. Please choose a different referral number or leave blank to auto-generate.";
                }
            }
            
            if ($user_exists > 0) {
                $errors[] = "Username or phone number already exists. Please choose different values.";
            } elseif (empty($errors)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $invitation_key = generate_invitation_key();
                
                // Generate referral number if empty
                if (empty($referral_number)) {
                    $referral_number = generateReferralNumber();
                }
                
                // Set admin flags based on role
                $is_admin = ($role === 'sub_admin' || $role === 'main_admin') ? 1 : 0;
                $is_main_admin = ($role === 'main_admin') ? 1 : 0;
                
                $stmt = $pdo->prepare("INSERT INTO users (username, phone, role, balance, password_hash, invitation_key, is_admin, is_main_admin, referral_number, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$username, $phone, $role, $balance, $password_hash, $invitation_key, $is_admin, $is_main_admin, $referral_number]);
                $success = "User added successfully with referral number: $referral_number!";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

if (isset($_GET['delete_user']) && is_numeric($_GET['delete_user'])) {
    $user_id = (int)$_GET['delete_user'];
    
    // Prevent admin from deleting themselves
    if ($user_id == $_SESSION['user_id']) {
        $errors[] = "You cannot delete your own account.";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $success = "User deleted successfully!";
        } catch (PDOException $e) {
            $errors[] = "Error deleting user: " . $e->getMessage();
        }
    }
}

// ==========================
// AUTOMATED RESPONSES LOGIC
// ==========================
if (isset($_POST['add_auto_response'])) {
    $keyword = trim($_POST['keyword']);
    $response = trim($_POST['response']);

    if (!empty($keyword) && !empty($response)) {
        // Check if keyword already exists
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM automated_responses WHERE keyword = ?");
        $check_stmt->execute([$keyword]);
        $keyword_exists = $check_stmt->fetchColumn();
        
        if ($keyword_exists > 0) {
            $errors[] = "Keyword '$keyword' already exists. Please choose a different keyword.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO automated_responses (keyword, response) VALUES (?, ?)");
                $stmt->execute([$keyword, $response]);
                $success = "Automated response added successfully!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Integrity constraint violation
                    $errors[] = "Keyword '$keyword' already exists. Please choose a different keyword.";
                } else {
                    $errors[] = "Database error: " . $e->getMessage();
                }
            }
        }
    } else {
        $errors[] = "Both keyword and response are required.";
    }
}

if (isset($_POST['update_auto_response'])) {
    $id = (int)$_POST['id'];
    $keyword = trim($_POST['keyword']);
    $response = trim($_POST['response']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($keyword) && !empty($response)) {
        // Check if keyword already exists (excluding current record)
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM automated_responses WHERE keyword = ? AND id != ?");
        $check_stmt->execute([$keyword, $id]);
        $keyword_exists = $check_stmt->fetchColumn();
        
        if ($keyword_exists > 0) {
            $errors[] = "Keyword '$keyword' already exists. Please choose a different keyword.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE automated_responses SET keyword = ?, response = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$keyword, $response, $is_active, $id]);
                $success = "Automated response updated successfully!";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) { // Integrity constraint violation
                    $errors[] = "Keyword '$keyword' already exists. Please choose a different keyword.";
                } else {
                    $errors[] = "Database error: " . $e->getMessage();
                }
            }
        }
    } else {
        $errors[] = "Both keyword and response are required.";
    }
}

if (isset($_GET['delete_auto_response']) && is_numeric($_GET['delete_auto_response'])) {
    $id = (int)$_GET['delete_auto_response'];
    try {
        $stmt = $pdo->prepare("DELETE FROM automated_responses WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Automated response deleted successfully!";
    } catch (PDOException $e) {
        $errors[] = "Error deleting response: " . $e->getMessage();
    }
}

// Fetch all users
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

// Fetch automated responses
$auto_responses = $pdo->query("SELECT * FROM automated_responses ORDER BY keyword")->fetchAll();

// Unread notifications for header
$unread_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
$unread_stmt->execute([$_SESSION['user_id']]);
$unread_count = $unread_stmt->fetch()['count'] ?? 0;

// User counts for stats
$total_users = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'")->fetch()['count'];
$active_users = $total_users;
$admin_users = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role IN ('main_admin', 'sub_admin', 'admin')")->fetch()['count'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - User Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admindashboard.css">
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
        nav a.active {
            text-decoration: underline;
        }
        nav a:hover {
            text-decoration: underline;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .dashboard-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
        }
        .dashboard-card h3 {
            color: #6c757d;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #0d6efd;
            margin: 10px 0;
        }
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .btn-success {
            background-color: #198754;
            border-color: #198754;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .btn-warning {
            background-color: #ffc107;
            border-color: #ffc107;
            color: #000;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .modal-content {
            border-radius: 10px;
            border: none;
        }
        .badge {
            font-size: 0.85em;
            padding: 0.35em 0.65em;
        }
        .auto-response-card, .user-management-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #0d6efd;
        }
        .auto-response-card {
            border-left-color: #764ba2;
        }
        .user-management-card {
            border-left-color: #4facfe;
        }
        .tab-content {
            background: white;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .nav-tabs {
            border-bottom: 2px solid #dee2e6;
        }
        .nav-tabs .nav-link {
            border: none;
            padding: 12px 25px;
            color: #6c757d;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            background: #0d6efd;
            color: white;
            border: none;
            border-radius: 5px 5px 0 0;
        }
        .nav-tabs .nav-link:hover {
            border: none;
            color: #0d6efd;
        }
        .user-table {
            font-size: 0.9rem;
        }
        .role-badge {
            font-size: 0.75em;
        }
        .current-user {
            background-color: #fff3cd !important;
        }
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        table {
            margin-bottom: 0;
        }
        thead {
            background: #343a40;
            color: white;
        }
        .form-control {
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        .alert {
            border-radius: 8px;
            border: none;
        }
        .referral-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #198754;
            background-color: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
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
            <a href="withdrawals.php">Withdrawals</a>
            <a href="settings.php">Settings</a>
            <a href="usersedit.php" class="active">Users Edit</a>
            <a href="notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
            <a href="../logout.php">Logout</a>
        </nav>
    </header>

    <main>
        <h2>User Management & Automated Responses</h2>

        <!-- Stats Cards -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>Total Users</h3>
                <p class="stat-number"><?php echo count($users); ?></p>
                <a href="#users" class="btn btn-primary">Manage Users</a>
            </div>

            <div class="dashboard-card">
                <h3>Regular Users</h3>
                <p class="stat-number"><?php echo $total_users; ?></p>
                <a href="#users" class="btn btn-primary">View Users</a>
            </div>

            <div class="dashboard-card">
                <h3>Admin Users</h3>
                <p class="stat-number"><?php echo $admin_users; ?></p>
                <a href="#users" class="btn btn-primary">Manage Admins</a>
            </div>

            <div class="dashboard-card">
                <h3>Auto Responses</h3>
                <p class="stat-number"><?php echo count($auto_responses); ?></p>
                <a href="#auto-responses" class="btn btn-primary">Manage Responses</a>
            </div>
        </div>

        <?php if ($new_referral_numbers_assigned > 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> 
                Successfully assigned referral numbers to <?php echo $new_referral_numbers_assigned; ?> users who were missing them.
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs" id="usersEditTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab" aria-controls="users" aria-selected="true">
                    <i class="fas fa-users me-1"></i>User Management
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="auto-responses-tab" data-bs-toggle="tab" data-bs-target="#auto-responses" type="button" role="tab" aria-controls="auto-responses" aria-selected="false">
                    <i class="fas fa-robot me-1"></i>Automated Responses
                </button>
            </li>
        </ul>

        <div class="tab-content mt-3">
            
            <!-- User Management Tab -->
            <div class="tab-pane fade show active" id="users" role="tabpanel" aria-labelledby="users-tab">
                <div class="user-management-card">
                    <h4><i class="fas fa-users-cog me-2"></i>User Management</h4>
                    <p class="mb-0">Manage all users, edit their roles, balances, referral numbers, and reset passwords.</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach($errors as $err): ?>
                            <p class="mb-1"><?php echo htmlspecialchars($err); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Add New User -->
                <div class="card mb-4">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label for="new_username" class="form-label">Username *</label>
                                        <input type="text" class="form-control" id="new_username" name="new_username" required>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label for="new_phone" class="form-label">Phone Number *</label>
                                        <input type="tel" class="form-control" id="new_phone" name="new_phone" placeholder="+1234567890" required>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label for="new_role" class="form-label">Role *</label>
                                        <select class="form-control" id="new_role" name="new_role" required>
                                            <option value="user">User</option>
                                            <option value="sub_admin">Sub Admin</option>
                                            <option value="main_admin">Main Admin</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label for="new_balance" class="form-label">Balance</label>
                                        <input type="number" step="0.01" class="form-control" id="new_balance" name="new_balance" value="0.00">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label for="new_referral_number" class="form-label">Referral Number</label>
                                        <input type="text" class="form-control" id="new_referral_number" name="new_referral_number" 
                                               placeholder="Auto-generate" maxlength="8" pattern="\d{8}">
                                        <div class="form-text">8 digits (leave blank to auto-generate)</div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">Password *</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_user" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Add User
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="table-container">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Users (<?php echo count($users); ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if(empty($users)): ?>
                            <div class="alert alert-info m-3">
                                <i class="fas fa-info-circle me-2"></i> No users found.
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped user-table mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Phone</th>
                                        <th>Referral Number</th>
                                        <th>Role</th>
                                        <th>Balance</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($users as $user): ?>
                                    <tr class="<?php echo $user['id'] == $_SESSION['user_id'] ? 'current-user' : ''; ?>">
                                        <td><?php echo $user['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($user['username']); ?>
                                            <?php if($user['id'] == $_SESSION['user_id']): ?>
                                                <span class="badge bg-warning role-badge">You</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if(!empty($user['referral_number'])): ?>
                                                <span class="referral-number"><?php echo htmlspecialchars($user['referral_number']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Missing</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $user['role'] == 'main_admin' ? 'danger' : 
                                                    ($user['role'] == 'sub_admin' ? 'warning' : 'secondary'); 
                                            ?> role-badge">
                                                <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                            </span>
                                        </td>
                                        <td>$<?php echo number_format($user['balance'], 2); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editUserModal<?php echo $user['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if($user['id'] != $_SESSION['user_id']): ?>
                                                    <a href="?delete_user=<?php echo $user['id']; ?>" 
                                                       class="btn btn-danger" 
                                                       onclick="return confirm('Are you sure you want to delete user <?php echo htmlspecialchars($user['username']); ?>? This action cannot be undone.')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <button class="btn btn-danger" disabled title="Cannot delete your own account">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Edit User Modal -->
                                            <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1" aria-labelledby="editUserModalLabel<?php echo $user['id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-dark text-white">
                                                            <h5 class="modal-title" id="editUserModalLabel<?php echo $user['id']; ?>">Edit User: <?php echo htmlspecialchars($user['username']); ?></h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <form method="POST" action="">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <div class="modal-body">
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="username<?php echo $user['id']; ?>" class="form-label">Username *</label>
                                                                            <input type="text" class="form-control" id="username<?php echo $user['id']; ?>" 
                                                                                   name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="phone<?php echo $user['id']; ?>" class="form-label">Phone Number *</label>
                                                                            <input type="tel" class="form-control" id="phone<?php echo $user['id']; ?>" 
                                                                                   name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="referral_number<?php echo $user['id']; ?>" class="form-label">Referral Number</label>
                                                                            <input type="text" class="form-control" id="referral_number<?php echo $user['id']; ?>" 
                                                                                   name="referral_number" value="<?php echo htmlspecialchars($user['referral_number'] ?? ''); ?>" 
                                                                                   maxlength="8" pattern="\d{8}">
                                                                            <div class="form-text">8 digits (leave blank to auto-generate)</div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="role<?php echo $user['id']; ?>" class="form-label">Role *</label>
                                                                            <select class="form-control" id="role<?php echo $user['id']; ?>" name="role" required>
                                                                                <option value="user" <?php echo $user['role'] == 'user' ? 'selected' : ''; ?>>User</option>
                                                                                <option value="sub_admin" <?php echo $user['role'] == 'sub_admin' ? 'selected' : ''; ?>>Sub Admin</option>
                                                                                <option value="main_admin" <?php echo $user['role'] == 'main_admin' ? 'selected' : ''; ?>>Main Admin</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="balance<?php echo $user['id']; ?>" class="form-label">Balance</label>
                                                                            <input type="number" step="0.01" class="form-control" id="balance<?php echo $user['id']; ?>" 
                                                                                   name="balance" value="<?php echo number_format($user['balance'], 2); ?>">
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="mb-3">
                                                                            <label for="password<?php echo $user['id']; ?>" class="form-label">New Password (leave blank to keep current)</label>
                                                                            <input type="password" class="form-control" id="password<?php echo $user['id']; ?>" 
                                                                                   name="password" placeholder="Enter new password">
                                                                            <div class="form-text">Leave blank if you don't want to change the password</div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="update_user" class="btn btn-success">
                                                                    <i class="fas fa-save me-2"></i>Update User
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Automated Responses Tab -->
            <div class="tab-pane fade" id="auto-responses" role="tabpanel" aria-labelledby="auto-responses-tab">
                <div class="auto-response-card">
                    <h4><i class="fas fa-robot me-2"></i>Manage Automated Responses</h4>
                    <p class="mb-0">Set up automatic replies for common customer queries. When users include these keywords in their support tickets, they'll receive instant automated responses.</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach($errors as $err): ?>
                            <p class="mb-1"><?php echo htmlspecialchars($err); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <!-- Add New Automated Response -->
                <div class="card mb-4">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add New Automated Response</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="keyword" class="form-label">Keyword *</label>
                                        <input type="text" class="form-control" id="keyword" name="keyword" 
                                               placeholder="e.g., TRC20, payment, withdrawal" required>
                                        <div class="form-text">Case-insensitive keyword that triggers this response</div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="mb-3">
                                        <label for="response" class="form-label">Response *</label>
                                        <textarea class="form-control" id="response" name="response" 
                                                  rows="3" placeholder="Enter the automated response message..." required></textarea>
                                        <div class="form-text">This message will be sent automatically when the keyword is detected</div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="add_auto_response" class="btn btn-success">
                                <i class="fas fa-save me-2"></i>Add Automated Response
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Existing Automated Responses -->
                <div class="table-container">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Existing Automated Responses</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if(empty($auto_responses)): ?>
                            <div class="alert alert-info m-3">
                                <i class="fas fa-info-circle me-2"></i> No automated responses configured yet.
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Keyword</th>
                                        <th>Response</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($auto_responses as $response): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($response['keyword']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="max-height: 100px; overflow-y: auto;">
                                                <?php echo nl2br(htmlspecialchars($response['response'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $response['is_active'] ? 'success' : 'danger'; ?>">
                                                <?php echo $response['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editModal<?php echo $response['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?delete_auto_response=<?php echo $response['id']; ?>" 
                                                   class="btn btn-danger" 
                                                   onclick="return confirm('Are you sure you want to delete this automated response?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>

                                            <!-- Edit Modal -->
                                            <div class="modal fade" id="editModal<?php echo $response['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $response['id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-dark text-white">
                                                            <h5 class="modal-title" id="editModalLabel<?php echo $response['id']; ?>">Edit Automated Response</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <form method="POST" action="">
                                                            <input type="hidden" name="id" value="<?php echo $response['id']; ?>">
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label for="keyword<?php echo $response['id']; ?>" class="form-label">Keyword *</label>
                                                                    <input type="text" class="form-control" id="keyword<?php echo $response['id']; ?>" 
                                                                           name="keyword" value="<?php echo htmlspecialchars($response['keyword']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="response<?php echo $response['id']; ?>" class="form-label">Response *</label>
                                                                    <textarea class="form-control" id="response<?php echo $response['id']; ?>" 
                                                                              name="response" rows="5" required><?php echo htmlspecialchars($response['response']); ?></textarea>
                                                                </div>
                                                                <div class="mb-3 form-check">
                                                                    <input type="checkbox" class="form-check-input" id="is_active<?php echo $response['id']; ?>" 
                                                                           name="is_active" value="1" <?php echo $response['is_active'] ? 'checked' : ''; ?>>
                                                                    <label class="form-check-label" for="is_active<?php echo $response['id']; ?>">
                                                                        Active (response will be sent when keyword is detected)
                                                                    </label>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="update_auto_response" class="btn btn-success">
                                                                    <i class="fas fa-save me-2"></i>Update Response
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Tab persistence
    document.addEventListener('DOMContentLoaded', function() {
        // Check for saved tab
        const savedTab = localStorage.getItem('usersEditTab');
        if (savedTab) {
            const tab = new bootstrap.Tab(document.querySelector(savedTab));
            tab.show();
        }
        
        // Save tab on change
        const tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
        tabEls.forEach(tabEl => {
            tabEl.addEventListener('shown.bs.tab', function (event) {
                localStorage.setItem('usersEditTab', event.target.getAttribute('data-bs-target'));
            });
        });

        // Referral number validation
        const referralInputs = document.querySelectorAll('input[name="referral_number"], input[name="new_referral_number"]');
        referralInputs.forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').substring(0, 8);
            });
        });
    });
</script>
</body>
</html>