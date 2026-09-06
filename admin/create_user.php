<?php
require_once '../includes/admin_auth.php';

$errors = [];
$success = '';
$user = [
    'id' => null,
    'username' => '',
    'email' => '',
    'role' => 'user',
    'parent_id' => '',
    'balance' => 0
];
$is_edit = false;

// Check if editing existing user
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $user_id = (int)$_GET['edit'];
    $is_edit = true;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        header('Location: users.php');
        exit;
    }
    
    // Safely assign values with fallbacks
    $user = [
        'id' => $user_data['id'],
        'username' => $user_data['username'] ?? '',
        'email' => $user_data['email'] ?? '',
        'role' => $user_data['role'] ?? 'user',
        'parent_id' => $user_data['parent_id'] ?? '',
        'balance' => $user_data['balance'] ?? 0
    ];
}

// Get all admins for parent selection
$admins = $pdo->query("SELECT id, username FROM users WHERE role IN ('main_admin', 'sub_admin')")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }
    
    // Get current user data if editing
    if ($is_edit) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get POST values - use current values if not provided
    $username = isset($_POST['username']) && $_POST['username'] !== '' ? trim($_POST['username']) : ($is_edit ? $current_user['username'] : '');
    $email = isset($_POST['email']) && $_POST['email'] !== '' ? trim($_POST['email']) : ($is_edit ? ($current_user['email'] ?? '') : '');
    $role = isset($_POST['role']) ? $_POST['role'] : ($is_edit ? $current_user['role'] : 'user');
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $balance = isset($_POST['balance']) && $_POST['balance'] !== '' ? (float)$_POST['balance'] : ($is_edit ? ($current_user['balance'] ?? 0) : 0);
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Track what's being updated
    $update_password = !empty($password);
    
    // Validate inputs only if they are being changed
    $has_errors = false;
    
    if (isset($_POST['username']) && $_POST['username'] !== '') {
        if (empty($username)) {
            $errors[] = "Username is required.";
            $has_errors = true;
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores.";
            $has_errors = true;
        }
    }
    
    if (isset($_POST['email']) && $_POST['email'] !== '') {
        if (empty($email)) {
            $errors[] = "Email is required.";
            $has_errors = true;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
            $has_errors = true;
        }
    }
    
    if ($update_password) {
        if (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters long.";
            $has_errors = true;
        }
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
            $has_errors = true;
        }
    }
    
    // Check if username already exists (only if username is being changed)
    if (!$has_errors && isset($_POST['username']) && $_POST['username'] !== '' && $username !== $current_user['username']) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user['id']]);
        if ($stmt->fetch()) {
            $errors[] = "Username already exists.";
            $has_errors = true;
        }
    }
    
    // Check if email already exists (only if email is being changed)
    if (!$has_errors && isset($_POST['email']) && $_POST['email'] !== '' && $email !== $current_user['email']) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $errors[] = "Email already exists.";
            $has_errors = true;
        }
    }
    
    if (!$has_errors && empty($errors)) {
        if ($is_edit) {
            // Build update query with only the fields that were provided
            $update_fields = [];
            $params = [];
            
            if (isset($_POST['username']) && $_POST['username'] !== '') {
                $update_fields[] = "username = ?";
                $params[] = $username;
            }
            
            if (isset($_POST['email']) && $_POST['email'] !== '') {
                $update_fields[] = "email = ?";
                $params[] = $email;
            }
            
            if (isset($_POST['role'])) {
                $update_fields[] = "role = ?";
                $params[] = $role;
            }
            
            if (isset($_POST['parent_id'])) {
                $update_fields[] = "parent_id = ?";
                $params[] = $parent_id;
            }
            
            if (isset($_POST['balance']) && $_POST['balance'] !== '') {
                $update_fields[] = "balance = ?";
                $params[] = $balance;
            }
            
            if ($update_password) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $update_fields[] = "password_hash = ?";
                $params[] = $password_hash;
            }
            
            if (!empty($update_fields)) {
                $sql = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                $params[] = $user['id'];
                
                $stmt = $pdo->prepare($sql);
                if ($stmt->execute($params)) {
                    $success = "User updated successfully.";
                    // Refresh user data after update
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    $user = [
                        'id' => $user_data['id'],
                        'username' => $user_data['username'] ?? '',
                        'email' => $user_data['email'] ?? '',
                        'role' => $user_data['role'] ?? 'user',
                        'parent_id' => $user_data['parent_id'] ?? '',
                        'balance' => $user_data['balance'] ?? 0
                    ];
                    // Clear password fields
                    $password = '';
                    $confirm_password = '';
                } else {
                    $errors[] = "Failed to update user. Please try again.";
                }
            } else {
                $errors[] = "No fields to update.";
            }
        } else {
            // Create new user
            if (empty($password)) {
                $errors[] = "Password is required for new users.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $invitation_key = generate_invitation_key();
                
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, parent_id, balance, invitation_key) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$username, $email, $password_hash, $role, $parent_id, $balance, $invitation_key])) {
                    $success = "User created successfully.";
                    // Reset form
                    $user = [
                        'id' => null,
                        'username' => '',
                        'email' => '',
                        'role' => 'user',
                        'parent_id' => '',
                        'balance' => 0
                    ];
                    $password = '';
                    $confirm_password = '';
                } else {
                    $errors[] = "Failed to create user. Please try again.";
                }
            }
        }
    }
}

// Get unread notifications count
$unread_notifications = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unread_notifications->execute([$_SESSION['user_id']]);
$unread_count = $unread_notifications->fetch()['count'];

// Helper function to safely output values
function safe_html($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit ? 'Edit' : 'Create'; ?> User - Task Website</title>
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
            <h2><?php echo $is_edit ? 'Edit User' : 'Create New User'; ?></h2>
            
            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo safe_html($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="success-message">
                    <p><?php echo safe_html($success); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" value="<?php echo safe_html($user['username']); ?>" <?php echo $is_edit ? '' : 'required'; ?>>
                    <?php if ($is_edit): ?>
                        <small>Leave empty to keep current username</small>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" value="<?php echo safe_html($user['email']); ?>" <?php echo $is_edit ? '' : 'required'; ?>>
                    <?php if ($is_edit): ?>
                        <small>Leave empty to keep current email</small>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="role">Role:</label>
                    <select id="role" name="role" <?php echo $is_edit ? '' : 'required'; ?>>
                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="sub_admin" <?php echo $user['role'] === 'sub_admin' ? 'selected' : ''; ?>>Sub Admin</option>
                        <option value="main_admin" <?php echo $user['role'] === 'main_admin' ? 'selected' : ''; ?>>Main Admin</option>
                    </select>
                </div>
                
                <div class="form-group" id="parent-id-group" style="<?php echo $user['role'] === 'user' ? 'display: none;' : ''; ?>">
                    <label for="parent_id">Parent Admin:</label>
                    <select id="parent_id" name="parent_id">
                        <option value="">None</option>
                        <?php foreach ($admins as $admin): ?>
                            <option value="<?php echo $admin['id']; ?>" <?php echo $user['parent_id'] == $admin['id'] ? 'selected' : ''; ?>>
                                <?php echo safe_html($admin['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="balance">Balance:</label>
                    <input type="number" id="balance" name="balance" step="0.01" value="<?php echo safe_html($user['balance']); ?>" <?php echo $is_edit ? '' : 'required'; ?>>
                    <?php if ($is_edit): ?>
                        <small>Leave empty to keep current balance</small>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="password"><?php echo $is_edit ? 'New Password (leave blank to keep current):' : 'Password:'; ?></label>
                    <input type="password" id="password" name="password" <?php echo $is_edit ? '' : 'required'; ?>>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" <?php echo $is_edit ? '' : 'required'; ?>>
                </div>
                
                <button type="submit" class="btn btn-primary"><?php echo $is_edit ? 'Update User' : 'Create User'; ?></button>
                <a href="users.php" class="btn btn-secondary">Cancel</a>
            </form>
        </main>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
        </footer>
        
        <script>
            // Show/hide parent ID field based on role selection
            const roleSelect = document.getElementById('role');
            const parentIdGroup = document.getElementById('parent-id-group');
            
            function toggleParentField() {
                if (roleSelect && parentIdGroup) {
                    parentIdGroup.style.display = roleSelect.value === 'user' ? 'none' : 'block';
                }
            }
            
            if (roleSelect) {
                roleSelect.addEventListener('change', toggleParentField);
                toggleParentField();
            }
        </script>
    </div>
</body>
</html>