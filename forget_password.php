<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';

// Initialize session variables for attempt tracking
if (!isset($_SESSION['reset_attempts'])) {
    $_SESSION['reset_attempts'] = 0;
}
if (!isset($_SESSION['last_attempt_time'])) {
    $_SESSION['last_attempt_time'] = 0;
}
if (!isset($_SESSION['locked_until'])) {
    $_SESSION['locked_until'] = 0;
}

$errors = [];
$success = false;
$new_password = '';
$phone = '';
$username = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    $phone = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');

    // Check if account is temporarily locked
    $current_time = time();
    if ($_SESSION['locked_until'] > $current_time) {
        $remaining_time = ceil(($_SESSION['locked_until'] - $current_time) / 60);
        $errors[] = "Too many attempts. Try again in {$remaining_time} minutes.";
    } else {
        // Reset lock if cooldown period has passed
        if ($_SESSION['locked_until'] > 0 && $_SESSION['locked_until'] <= $current_time) {
            $_SESSION['reset_attempts'] = 0;
            $_SESSION['locked_until'] = 0;
        }

        // Validate inputs
        if (empty($phone)) {
            $errors[] = "Phone number is required.";
        }

        if (empty($username)) {
            $errors[] = "Username is required.";
        }

        if (empty($errors)) {
            // Check if user exists with provided phone and username
            $stmt = $pdo->prepare("SELECT id, username, phone FROM users WHERE phone = ? AND username = ?");
            $stmt->execute([$phone, $username]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate new random password
                $new_password = generate_secure_password(10);
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update user's password
                $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $update_stmt->execute([$password_hash, $user['id']]);
                
                // Sync to encrypted password storage
                sync_password_to_encrypted_storage($user['id'], $new_password);
                
                // Set force password change flag
                $_SESSION['force_password_change'] = true;
                
                // Reset attempt counter on success
                $_SESSION['reset_attempts'] = 0;
                $_SESSION['locked_until'] = 0;
                
                $success = true;
            } else {
                // Increment attempt counter
                $_SESSION['reset_attempts']++;
                $_SESSION['last_attempt_time'] = $current_time;
                
                // Apply restrictions based on attempts
                if ($_SESSION['reset_attempts'] >= 3) {
                    $_SESSION['locked_until'] = $current_time + 900; // 15 minutes lock
                    $errors[] = "Too many failed attempts. Account temporarily locked for 15 minutes.";
                } elseif ($_SESSION['reset_attempts'] >= 2) {
                    $errors[] = "Invalid phone number or username. One more attempt before temporary lock.";
                } else {
                    $errors[] = "Invalid phone number or username.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - TikTok Shop</title>
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
            max-width: 500px;
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
            padding: 25px;
            margin: 0 25px 25px 25px;
            border-radius: 10px;
            border: 1px solid rgba(42, 240, 234, 0.3);
            text-align: center;
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

        .new-password-display {
            background: rgba(4, 4, 4, 0.6);
            border: 2px dashed #fe2858;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .new-password {
            font-family: 'Courier New', monospace;
            font-size: 24px;
            font-weight: bold;
            color: #2af0ea;
            margin: 15px 0;
            padding: 15px;
            background: rgba(4, 4, 4, 0.8);
            border-radius: 8px;
            letter-spacing: 2px;
            border: 1px solid rgba(42, 240, 234, 0.3);
        }

        .security-warning {
            background: rgba(254, 40, 88, 0.1);
            color: #fe2858;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid rgba(254, 40, 88, 0.3);
            font-size: 14px;
        }

        .form-footer {
            text-align: center;
            color: #888;
            font-size: 14px;
            padding: 0 25px 30px 25px;
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

        .login-redirect-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 15px 40px;
            background: linear-gradient(135deg, #2af0ea, #1ac9c4);
            color: #040404;
            text-decoration: none;
            border-radius: 12px;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(42, 240, 234, 0.3);
            border: none;
            cursor: pointer;
        }

        .login-redirect-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 240, 234, 0.4);
            background: linear-gradient(135deg, #1ac9c4, #2af0ea);
            color: #040404;
            text-decoration: none;
        }

        .login-redirect-btn:active {
            transform: translateY(0);
        }

        .btn-container {
            text-align: center;
            margin-top: 20px;
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
                max-width: 450px;
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
            
            .new-password {
                font-size: 20px;
                padding: 12px;
            }
            
            .login-redirect-btn {
                padding: 14px 35px;
                font-size: 15px;
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
            
            .new-password {
                font-size: 18px;
                padding: 10px;
            }
            
            .login-redirect-btn {
                padding: 12px 30px;
                font-size: 14px;
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
    
    <h2>Reset Your Password</h2>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <p><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success-message">
            <h3>✅ Password Reset Successful!</h3>
            <p>Your password has been reset successfully.</p>
            
            <div class="new-password-display">
                <p><strong>Your New Password:</strong></p>
                <div class="new-password"><?php echo htmlspecialchars($new_password); ?></div>
                <p>Please copy this password and use it to login.</p>
            </div>
            
            <div class="security-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Security Notice:</strong> You must change this password immediately after logging in for security reasons.
            </div>
            
            <div class="btn-container">
                <a href="login.php?password_reset=success" class="login-redirect-btn">
                    Continue to Login
                </a>
            </div>
        </div>
    <?php else: ?>
        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

            <div class="input-group">
                <input type="tel" name="phone" placeholder="Enter your phone number" value="<?php echo htmlspecialchars($phone); ?>" required>
            </div>

            <div class="input-group">
                <input type="text" name="username" placeholder="Enter your username" value="<?php echo htmlspecialchars($username); ?>" required>
            </div>

            <button type="submit">Reset Password</button>
        </form>

        <p class="form-footer">
            Remember your password? <a href="login.php">Back to Login</a>
        </p>
    <?php endif; ?>
</div>

<script>
document.querySelector('form')?.addEventListener('submit', function(e) {
    const phone = document.querySelector('input[name="phone"]').value.trim();
    const username = document.querySelector('input[name="username"]').value.trim();
    
    if (!phone) {
        e.preventDefault();
        alert('Please enter your phone number.');
        return false;
    }
    
    if (!username) {
        e.preventDefault();
        alert('Please enter your username.');
        return false;
    }
    
    // Add loading state
    const button = this.querySelector('button[type="submit"]');
    const originalText = button.innerHTML;
    button.innerHTML = '<div class="loading"></div> Resetting Password...';
    button.disabled = true;
    
    // Re-enable button after 3 seconds (in case of error)
    setTimeout(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    }, 3000);
});

// Copy password to clipboard functionality
function copyPassword() {
    const passwordElement = document.querySelector('.new-password');
    if (passwordElement) {
        const password = passwordElement.textContent;
        navigator.clipboard.writeText(password).then(function() {
            alert('Password copied to clipboard!');
        }, function(err) {
            console.error('Could not copy text: ', err);
        });
    }
}

// Auto-redirect to login after 10 seconds on success
<?php if ($success): ?>
setTimeout(function() {
    window.location.href = 'login.php?password_reset=success';
}, 10000);
<?php endif; ?>
</script>
</body>
</html>