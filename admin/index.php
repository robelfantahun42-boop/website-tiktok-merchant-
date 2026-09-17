<?php
session_start();

// Include required files
require_once '../includes/init.php';        // Database connection ($pdo)
require_once '../includes/functions.php';   // Helper functions like CSRF
require_once '../includes/auth.php';        // Optional (if you use require_login etc.)

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$username = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username)) $errors[] = "Username is required.";
    if (empty($password)) $errors[] = "Password is required.";

    if (empty($errors)) {
        try {
            // Check for admin users (main_admin, sub_admin, or users with is_admin = 1)
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, role, is_admin, is_main_admin, invitation_key
                FROM users
                WHERE username = ?
                AND (role IN ('main_admin', 'sub_admin', 'admin') OR is_admin = 1 OR is_main_admin = 1)
                LIMIT 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                // Login success
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = strtolower($user['role']);
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['is_main_admin'] = $user['is_main_admin'];
                $_SESSION['invitation_key'] = $user['invitation_key'] ?? null;

                // Redirect to admin dashboard
                header('Location: dashboard.php');
                exit;
            } else {
                $errors[] = "Invalid username or password, or you don't have admin access.";
            }

        } catch (PDOException $e) {
            $errors[] = "Database error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login - Task Website</title>
<link rel="stylesheet" href="../assets/css/adminindex.css">
<style>
    body {
        font-family: 'Roboto', sans-serif;
        background: linear-gradient(135deg, #f0f4f8, #dfe9f3);
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 450px;
        margin: 100px auto;
        background: #fff;
        padding: 40px 30px;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.1);
    }
    h1 {
        text-align: center;
        color: #333;
        margin-bottom: 25px;
    }
    .error-message p {
        background: #ffe6e6;
        color: #d9534f;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 10px;
    }
    .form-group {
        margin-bottom: 20px;
    }
    label {
        display: block;
        font-weight: bold;
        color: #555;
        margin-bottom: 6px;
    }
    input[type="text"], input[type="password"] {
        width: 100%;
        padding: 12px;
        border-radius: 6px;
        border: 1px solid #ccc;
        font-size: 16px;
        transition: border-color 0.3s ease;
    }
    input[type="text"]:focus, input[type="password"]:focus {
        border-color: #007bff;
        outline: none;
    }
    button {
        width: 100%;
        background: #007bff;
        border: none;
        color: #fff;
        padding: 12px;
        font-size: 17px;
        border-radius: 6px;
        cursor: pointer;
        transition: background 0.3s;
    }
    button:hover {
        background: #0056b3;
    }
    footer {
        text-align: center;
        margin-top: 40px;
        color: #777;
        font-size: 14px;
    }
    .test-credentials {
        margin-top: 25px;
        background: #f9f9f9;
        padding: 12px 15px;
        border-radius: 8px;
        border: 1px solid #eee;
    }
    .test-credentials h3 {
        font-size: 16px;
        margin-bottom: 8px;
        color: #333;
    }
    .test-credentials p {
        font-size: 14px;
        margin: 4px 0;
    }
</style>
</head>
<body>

<div class="container">
    <h1>Admin Panel Login</h1>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token(); ?>">

        <div class="form-group">
            <label for="username">Admin Username</label>
            <input type="text" id="username" name="username" 
                   value="<?= htmlspecialchars($username); ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Admin Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit">Login</button>
    </form>

    <div class="test-credentials">
        <h3>Admin Access</h3>
        <p>Only users with admin roles (main_admin, sub_admin) can login here.</p>
        <p>Regular users should use the main website login.</p>
    </div>
</div>

<footer>
    <p>&copy; <?= date('Y'); ?> Task Website. All rights reserved.</p>
</footer>

</body>
</html>