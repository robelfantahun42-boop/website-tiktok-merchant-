<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php'; // Required for is_logged_in() and is_admin()

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$phone = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    }

    if (empty($password)) {
        $errors[] = "Password is required.";
    }

    // Check credentials
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id, username, phone, password_hash, role, invitation_key FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['invitation_key'] = $user['invitation_key'];

            // Redirect based on role
            if ($user['role'] === 'main_admin' || $user['role'] === 'sub_admin') {
                header('Location: admin/dashboard.php');
            } else {
                header('Location: dashboard.php');
            }
            exit;
        } else {
            $errors[] = "Invalid phone number or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TikTok Shop</title>
   <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Roboto', sans-serif;
        }

        body {
            background: #040404;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        .video-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: -2;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(4, 4, 4, 0.8);
            z-index: -1;
        }

        .container {
            width: 100%;
            max-width: 450px;
            background: rgba(4, 4, 4, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            backdrop-filter: blur(10px);
            animation: slideUp 0.5s ease-out;
            border: 1px solid rgba(254, 40, 88, 0.3);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            background: linear-gradient(135deg, #040404 0%, #0a0a0a 100%);
            padding: 40px 20px;
            text-align: center;
            color: white;
            border-bottom: 1px solid rgba(254, 40, 88, 0.3);
        }

        .logo {
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
            border-radius: 50%;
            border: 4px solid #fe2858;
            box-shadow: 0 4px 15px rgba(254, 40, 88, 0.3);
        }

        .logo-container span {
            font-size: 28px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(254, 40, 88, 0.5);
            display: block;
            color: #fe2858;
        }

        .container h2 {
            text-align: center;
            color: #2af0ea;
            margin: 30px 0 25px 0;
            font-size: 24px;
            font-weight: 600;
        }

        .error-message {
            background: rgba(254, 40, 88, 0.1);
            color: #fe2858;
            padding: 15px;
            margin: 0 25px 25px 25px;
            border-radius: 10px;
            border: 1px solid rgba(254, 40, 88, 0.3);
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .success-message {
            background: rgba(42, 240, 234, 0.1);
            color: #2af0ea;
            padding: 15px;
            margin: 0 25px 25px 25px;
            border-radius: 10px;
            border: 1px solid rgba(42, 240, 234, 0.3);
            animation: bounceIn 0.6s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); opacity: 1; }
        }

        form {
            padding: 0 25px 25px 25px;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(4, 4, 4, 0.6);
            color: #ffffff;
        }

        .input-group input:focus {
            border-color: #2af0ea;
            background: rgba(4, 4, 4, 0.8);
            outline: none;
            box-shadow: 0 0 0 3px rgba(42, 240, 234, 0.1);
        }

        .input-group input::placeholder {
            color: #888;
        }

        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #fe2858, #de8c9d);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(254, 40, 88, 0.3);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(254, 40, 88, 0.4);
            background: linear-gradient(135deg, #de8c9d, #fe2858);
        }

        button:active {
            transform: translateY(0);
        }

        .form-footer {
            text-align: center;
            color: #888;
            font-size: 14px;
            padding: 0 25px 30px 25px;
            line-height: 1.6;
        }

        .form-footer a {
            color: #2af0ea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .form-footer a:hover {
            color: #fe2858;
            text-decoration: underline;
        }

        .forgot-password {
            text-align: center;
            margin-bottom: 20px;
        }

        .forgot-password a {
            color: #fe2858;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #2af0ea;
            text-decoration: underline;
        }

        /* Loading animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 15px;
            }
            
            .container {
                max-width: 400px;
            }
            
            .logo-container {
                padding: 30px 20px;
            }
            
            .logo {
                width: 70px;
                height: 70px;
            }
            
            .logo-container span {
                font-size: 24px;
            }
            
            .container h2 {
                font-size: 22px;
                margin: 25px 0 20px 0;
            }
            
            form {
                padding: 0 20px 20px 20px;
            }
            
            .input-group input {
                padding: 14px 18px;
                font-size: 16px;
            }
            
            button {
                padding: 14px;
            }
        }

        @media (max-width: 480px) {
            .container {
                border-radius: 15px;
            }
            
            .logo-container {
                padding: 25px 15px;
            }
            
            .logo {
                width: 60px;
                height: 60px;
            }
            
            .logo-container span {
                font-size: 22px;
            }
            
            .container h2 {
                font-size: 20px;
                margin: 20px 0 15px 0;
            }
            
            form {
                padding: 0 15px 15px 15px;
            }
            
            .error-message, .success-message {
                margin: 0 15px 15px 15px;
                padding: 12px;
            }
            
            .input-group input {
                padding: 12px 15px;
            }
            
            button {
                padding: 14px;
            }
            
            .form-footer {
                padding: 0 15px 20px 15px;
            }
        }

        /* Video fallback for unsupported browsers */
        @supports not (backdrop-filter: blur(10px)) {
            .container {
                background: rgba(4, 4, 4, 0.98);
            }
        }
    </style>
</head>
<body>
<!-- Background video -->
<video autoplay muted loop class="video-bg">
    <source src="https://player.vimeo.com/external/449394903.sd.mp4?s=5caaed7d1eafc19d6d02a3bde86a0a6c92b882a0&profile_id=164" type="video/mp4">
    Your browser does not support the video tag.
</video>
<div class="overlay"></div>

<div class="container">
    <div class="logo-container">
        <img src="assets/images/logo/tiktoklogo.png" alt="TikTok Shop Logo" class="logo">
        <span>TIKTOK SHOP</span>
    </div>
    
    <h2>Login to Your Account</h2>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <p><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['password_reset']) && $_GET['password_reset'] == 'success'): ?>
        <div class="success-message">
            <p>Password reset successfully! Please check your new password below and login.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

        <div class="input-group">
            <input type="tel" name="phone" placeholder="Phone Number" value="<?php echo htmlspecialchars($phone); ?>" required>
        </div>

        <div class="input-group">
            <input type="password" name="password" placeholder="Password" required>
        </div>

        <div class="forgot-password">
            <a href="forget_password.php">Forgot Password?</a>
        </div>

        <button type="submit">Login</button>
    </form>

    <p class="form-footer">Don't have an account? <a href="signup.php">Sign up here</a></p>
</div>

<script>
document.querySelector('form')?.addEventListener('submit', function(e) {
    const phone = document.querySelector('input[name="phone"]').value.trim();
    const password = document.querySelector('input[name="password"]').value;
    
    if (!phone) {
        e.preventDefault();
        alert('Please enter your phone number.');
        return false;
    }
    
    if (!password) {
        e.preventDefault();
        alert('Please enter your password.');
        return false;
    }
    
    // Add loading state
    const button = this.querySelector('button[type="submit"]');
    const originalText = button.innerHTML;
    button.innerHTML = '<div class="loading"></div> Logging in...';
    button.disabled = true;
    
    // Re-enable button after 3 seconds (in case of error)
    setTimeout(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    }, 3000);
});
</script>
</body>
</html>