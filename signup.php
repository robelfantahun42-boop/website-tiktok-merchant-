<?php
session_start();

// Database configuration
define('DB_HOST', 'sql103.infinityfree.com');
define('DB_NAME', 'if0_41786885_u890208008_taskdb');
define('DB_USER', 'if0_41786885');
define('DB_PASS', 'Roba201111');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Include functions
require_once 'includes/functions.php';

$errors = [];
$success = false;
$username = $phone = $invitation_key = '';
$new_invitation_key = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $withdrawal_password = $_POST['withdrawal_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $confirm_withdrawal_password = $_POST['confirm_withdrawal_password'] ?? '';
    $invitation_key = trim($_POST['invitation_key'] ?? '');

    // Enhanced password validation
    if (empty($username)) $errors[] = "Username is required.";
    if (empty($phone)) $errors[] = "Phone number is required.";
    if (empty($password)) $errors[] = "Password is required.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters long.";
    if (!preg_match('/[A-Za-z]/', $password)) $errors[] = "Password must contain at least one letter.";
    if (!preg_match('/[0-9]/', $password)) $errors[] = "Password must contain at least one number.";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match.";
    
    // Withdrawal password validation
    if (empty($withdrawal_password)) $errors[] = "Withdrawal password is required.";
    if (strlen($withdrawal_password) < 4) $errors[] = "Withdrawal password must be at least 4 characters long.";
    if ($withdrawal_password !== $confirm_withdrawal_password) $errors[] = "Withdrawal passwords do not match.";
    
    if (empty($invitation_key)) $errors[] = "Invitation key is required.";

    // Check invitation key
    $referrer = null; // FIX: Initialize referrer variable
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE invitation_key = ?");
        $stmt->execute([$invitation_key]);
        $referrer = $stmt->fetch();

        if (!$referrer) {
            $errors[] = "Invalid invitation key. Please enter a correct key.";
        }
    }

    // Check username/phone uniqueness
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR phone = ?");
        $stmt->execute([$username, $phone]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Username or phone number already exists.";
        }
    }

    // Insert user
    if (empty($errors) && $referrer) { // FIX: Check if referrer exists before inserting
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $withdrawal_password_hash = password_hash($withdrawal_password, PASSWORD_DEFAULT);
        $new_invitation_key = generate_invitation_key();
        $referral_number = generateReferralNumber();

        // FIXED: Use simple_encrypt_password instead of encrypt_password_for_admin
        $encrypted_password = simple_encrypt_password($password);

        $transaction_started = false;
        try {
            // Start transaction
            $pdo->beginTransaction();
            $transaction_started = true;
            
            // Insert new user
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username, phone, password_hash, withdrawal_password, 
                    invitation_key, referrer_id, parent_id, referral_number,
                    role, balance, daily_task_limit, created_at
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user', 0, 40, NOW())
            ");
            $stmt->execute([
                $username, 
                $phone, 
                $password_hash, 
                $withdrawal_password_hash, 
                $new_invitation_key, 
                $referrer['id'],
                $referrer['id'],
                $referral_number
            ]);
            $new_user_id = $pdo->lastInsertId();
            
            // Store encrypted password in user_passwords table
            // FIXED: Use encryption_key_id = 1 (default key)
            $stmt = $pdo->prepare("
                INSERT INTO user_passwords (user_id, encrypted_password, encryption_key_id, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([$new_user_id, $encrypted_password]);
            
            // Track the referral
            trackReferral($new_user_id, $invitation_key, $pdo);
            
            $pdo->commit();
            $success = true;
        } catch (Exception $e) {
            if ($transaction_started) {
                try {
                    $pdo->rollBack();
                } catch (Exception $rollback_e) {
                    error_log("Rollback failed: " . $rollback_e->getMessage());
                }
            }
            $errors[] = "Registration failed: " . $e->getMessage();
            error_log("Registration error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up - TikTok Shop</title>
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
    }

    .container {
        width: 100%;
        max-width: 500px;
        background: rgba(4, 4, 4, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        overflow: hidden;
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
        padding: 30px 20px;
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

    .logo-container h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 5px;
        text-shadow: 0 2px 4px rgba(254, 40, 88, 0.5);
        color: #fe2858;
    }

    .logo-container h2 {
        font-size: 18px;
        font-weight: 400;
        opacity: 0.9;
        color: #2af0ea;
    }

    .form-content {
        padding: 30px;
    }

    .input-group {
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #2af0ea;
        font-size: 14px;
    }

    input[type="text"],
    input[type="tel"],
    input[type="password"] {
        width: 100%;
        padding: 15px;
        border: 2px solid rgba(42, 240, 234, 0.2);
        border-radius: 10px;
        font-size: 16px;
        transition: all 0.3s ease;
        background: rgba(4, 4, 4, 0.6);
        color: #ffffff;
    }

    input[type="text"]:focus,
    input[type="tel"]:focus,
    input[type="password"]:focus {
        border-color: #2af0ea;
        background: rgba(4, 4, 4, 0.8);
        outline: none;
        box-shadow: 0 0 0 3px rgba(42, 240, 234, 0.1);
    }

    small {
        color: #888;
        font-size: 12px;
        display: block;
        margin-top: 5px;
    }

    button {
        width: 100%;
        padding: 15px;
        background: linear-gradient(135deg, #fe2858, #de8c9d);
        color: white;
        border: none;
        border-radius: 10px;
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

    button:disabled {
        background: #666;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .error {
        background: rgba(254, 40, 88, 0.1);
        color: #fe2858;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        border: 1px solid rgba(254, 40, 88, 0.3);
        animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }

    .error ul {
        margin: 0;
        padding-left: 20px;
    }

    .success {
        background: rgba(42, 240, 234, 0.1);
        color: #2af0ea;
        padding: 25px;
        border-radius: 10px;
        margin-bottom: 20px;
        border: 1px solid rgba(42, 240, 234, 0.3);
        text-align: center;
        animation: bounceIn 0.6s ease-out;
    }

    @keyframes bounceIn {
        0% { transform: scale(0.3); opacity: 0; }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); opacity: 1; }
    }

    .invitation-info {
        margin-top: 20px;
        padding: 20px;
        background: rgba(42, 240, 234, 0.1);
        border-radius: 10px;
        border: 2px dashed #2af0ea;
    }

    .invitation-key {
        font-family: 'Courier New', monospace;
        font-size: 20px;
        font-weight: bold;
        color: #fe2858;
        margin: 15px 0;
        padding: 15px;
        background: rgba(4, 4, 4, 0.6);
        border-radius: 8px;
        text-align: center;
        letter-spacing: 2px;
        border: 1px solid rgba(254, 40, 88, 0.3);
    }

    .referral-number-display {
        margin-top: 15px;
        padding: 15px;
        background: rgba(254, 40, 88, 0.1);
        border-radius: 8px;
        border: 2px dashed #fe2858;
    }

    .referral-number {
        font-family: 'Courier New', monospace;
        font-size: 18px;
        font-weight: bold;
        color: #2af0ea;
        margin: 10px 0;
        padding: 12px;
        background: rgba(4, 4, 4, 0.6);
        border-radius: 6px;
        text-align: center;
        letter-spacing: 1px;
        border: 1px solid rgba(42, 240, 234, 0.3);
    }

    .form-footer {
        text-align: center;
        margin-top: 25px;
        color: #888;
        font-size: 14px;
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

    .password-requirements {
        background: rgba(4, 4, 4, 0.6);
        border: 1px solid rgba(42, 240, 234, 0.2);
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
        font-size: 13px;
    }

    .password-requirements ul {
        margin: 8px 0;
        padding-left: 20px;
    }

    .requirement {
        color: #fe2858;
        transition: color 0.3s ease;
    }

    .requirement.met {
        color: #2af0ea;
    }

    .password-strength {
        height: 6px;
        border-radius: 3px;
        margin: 10px 0;
        background: rgba(4, 4, 4, 0.6);
        transition: all 0.3s ease;
        border: 1px solid rgba(42, 240, 234, 0.2);
    }

    .strength-weak { background: #fe2858; width: 25%; }
    .strength-medium { background: #ffc107; width: 50%; }
    .strength-strong { background: #2af0ea; width: 100%; }

    #password-match, #withdrawal-password-match {
        font-weight: bold;
        font-size: 13px;
        margin-top: 8px;
        padding: 5px;
        border-radius: 5px;
        text-align: center;
    }

    .withdrawal-password-info {
        background: rgba(42, 240, 234, 0.1);
        border: 1px solid rgba(42, 240, 234, 0.3);
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
        font-size: 13px;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        body {
            padding: 15px;
        }
        
        .container {
            border-radius: 15px;
        }
        
        .form-content {
            padding: 20px;
        }
        
        .logo-container {
            padding: 25px 15px;
        }
        
        .logo {
            width: 70px;
            height: 70px;
        }
        
        .logo-container h1 {
            font-size: 24px;
        }
        
        .logo-container h2 {
            font-size: 16px;
        }
        
        input[type="text"],
        input[type="tel"],
        input[type="password"] {
            padding: 12px;
            font-size: 16px;
        }
        
        button {
            padding: 14px;
        }
    }

    @media (max-width: 480px) {
        .container {
            border-radius: 12px;
        }
        
        .form-content {
            padding: 15px;
        }
        
        .logo-container {
            padding: 20px 15px;
        }
        
        .logo {
            width: 60px;
            height: 60px;
        }
        
        .logo-container h1 {
            font-size: 22px;
        }
        
        .invitation-key {
            font-size: 16px;
            padding: 12px;
        }
        
        .referral-number {
            font-size: 16px;
            padding: 10px;
        }
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
</style>
</head>
<body>
<div class="container">
    <div class="logo-container">
        <img src="assets/images/logo/tiktoklogo.png" alt="TikTok Shop Logo" class="logo">
        <h1>TikTok Shop</h1>
        <h2>Create Your Account</h2>
    </div>

    <div class="form-content">
        <?php if (!empty($errors)): ?>
            <div class="error">
                <ul>
                    <?php foreach($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success">
                <h3>🎉 Registration Successful!</h3>
                <p style="margin: 15px 0;">You can now <a href="login.php" style="color: #2af0ea; font-weight: bold;">login to your account</a>.</p>
                
                <div class="invitation-info">
                    <p><strong>Your Invitation Key:</strong></p>
                    <div class="invitation-key">
                        <?php echo htmlspecialchars($new_invitation_key); ?>
                    </div>
                    <small>Share this key with others to earn referral commissions!</small>
                </div>
                
                <div class="referral-number-display">
                    <p><strong>Your Referral Number:</strong></p>
                    <div class="referral-number">
                        <?php echo htmlspecialchars($referral_number); ?>
                    </div>
                    <small>Share this number for quick referrals!</small>
                </div>
            </div>
        <?php else: ?>
        <form method="POST" action="" id="registrationForm">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

            <div class="input-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username ?? ''); ?>" required placeholder="Enter your username">
            </div>

            <div class="input-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone ?? ''); ?>" required placeholder="Enter your phone number">
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter your password">
                <div class="password-requirements">
                    <strong>Password Requirements:</strong>
                    <ul>
                        <li id="req-length" class="requirement">At least 6 characters</li>
                        <li id="req-letter" class="requirement">Contains at least one letter</li>
                        <li id="req-number" class="requirement">Contains at least one number</li>
                    </ul>
                    <div class="password-strength" id="password-strength"></div>
                </div>
            </div>

            <div class="input-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm your password">
                <div id="password-match" style="margin-top: 5px;"></div>
            </div>

            <div class="input-group">
                <label for="withdrawal_password">Withdrawal Password</label>
                <input type="password" id="withdrawal_password" name="withdrawal_password" required placeholder="Enter withdrawal password">
                <div class="withdrawal-password-info">
                    <strong>Withdrawal Password Information:</strong>
                    <p>This password will be used to authorize withdrawals from your account. It must be at least 4 characters long.</p>
                </div>
            </div>

            <div class="input-group">
                <label for="confirm_withdrawal_password">Confirm Withdrawal Password</label>
                <input type="password" id="confirm_withdrawal_password" name="confirm_withdrawal_password" required placeholder="Confirm withdrawal password">
                <div id="withdrawal-password-match" style="margin-top: 5px;"></div>
            </div>

            <div class="input-group">
                <label for="invitation_key">Invitation Key</label>
                <input type="text" id="invitation_key" name="invitation_key" value="<?php echo htmlspecialchars($invitation_key ?? ''); ?>" required placeholder="Enter invitation key">
                <small>You need a valid invitation key from an existing user to register</small>
            </div>

            <button type="submit" id="submitBtn">
                <span id="submitText">Sign Up</span>
            </button>
        </form>

        <div class="form-footer">
            Already have an account? <a href="login.php">Login here</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const withdrawalPasswordInput = document.getElementById('withdrawal_password');
    const confirmWithdrawalPasswordInput = document.getElementById('confirm_withdrawal_password');
    const passwordMatch = document.getElementById('password-match');
    const withdrawalPasswordMatch = document.getElementById('withdrawal-password-match');
    const passwordStrength = document.getElementById('password-strength');
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    
    // Password requirements elements
    const reqLength = document.getElementById('req-length');
    const reqLetter = document.getElementById('req-letter');
    const reqNumber = document.getElementById('req-number');
    
    function checkPasswordStrength(password) {
        let strength = 0;
        
        // Length check
        if (password.length >= 6) {
            strength++;
            reqLength.classList.add('met');
        } else {
            reqLength.classList.remove('met');
        }
        
        // Letter check
        if (/[A-Za-z]/.test(password)) {
            strength++;
            reqLetter.classList.add('met');
        } else {
            reqLetter.classList.remove('met');
        }
        
        // Number check
        if (/[0-9]/.test(password)) {
            strength++;
            reqNumber.classList.add('met');
        } else {
            reqNumber.classList.remove('met');
        }
        
        // Update strength indicator
        passwordStrength.className = 'password-strength';
        if (password.length > 0) {
            if (strength === 1) {
                passwordStrength.classList.add('strength-weak');
            } else if (strength === 2) {
                passwordStrength.classList.add('strength-medium');
            } else if (strength === 3) {
                passwordStrength.classList.add('strength-strong');
            }
        }
        
        return strength === 3;
    }
    
    function checkPasswordMatch() {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        
        if (confirmPassword.length === 0) {
            passwordMatch.textContent = '';
            passwordMatch.style.background = '';
        } else if (password === confirmPassword) {
            passwordMatch.textContent = '✓ Passwords match';
            passwordMatch.style.background = '#d4edda';
            passwordMatch.style.color = '#155724';
        } else {
            passwordMatch.textContent = '✗ Passwords do not match';
            passwordMatch.style.background = '#f8d7da';
            passwordMatch.style.color = '#721c24';
        }
    }
    
    function checkWithdrawalPasswordMatch() {
        const withdrawalPassword = withdrawalPasswordInput.value;
        const confirmWithdrawalPassword = confirmWithdrawalPasswordInput.value;
        
        if (confirmWithdrawalPassword.length === 0) {
            withdrawalPasswordMatch.textContent = '';
            withdrawalPasswordMatch.style.background = '';
        } else if (withdrawalPassword === confirmWithdrawalPassword) {
            withdrawalPasswordMatch.textContent = '✓ Withdrawal passwords match';
            withdrawalPasswordMatch.style.background = '#d4edda';
            withdrawalPasswordMatch.style.color = '#155724';
        } else {
            withdrawalPasswordMatch.textContent = '✗ Withdrawal passwords do not match';
            withdrawalPasswordMatch.style.background = '#f8d7da';
            withdrawalPasswordMatch.style.color = '#721c24';
        }
    }
    
    function validateForm() {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        const withdrawalPassword = withdrawalPasswordInput.value;
        const confirmWithdrawalPassword = confirmWithdrawalPasswordInput.value;
        
        const isStrongPassword = checkPasswordStrength(password);
        const passwordsMatch = password === confirmPassword;
        const withdrawalPasswordsMatch = withdrawalPassword === confirmWithdrawalPassword;
        const isWithdrawalPasswordValid = withdrawalPassword.length >= 4;
        
        submitBtn.disabled = !(isStrongPassword && passwordsMatch && withdrawalPasswordsMatch && isWithdrawalPasswordValid);
    }
    
    // Event listeners
    passwordInput.addEventListener('input', function() {
        checkPasswordStrength(this.value);
        checkPasswordMatch();
        validateForm();
    });
    
    confirmPasswordInput.addEventListener('input', function() {
        checkPasswordMatch();
        validateForm();
    });
    
    withdrawalPasswordInput.addEventListener('input', function() {
        checkWithdrawalPasswordMatch();
        validateForm();
    });
    
    confirmWithdrawalPasswordInput.addEventListener('input', function() {
        checkWithdrawalPasswordMatch();
        validateForm();
    });
    
    // Form submission loading state
    document.getElementById('registrationForm').addEventListener('submit', function(e) {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        const withdrawalPassword = withdrawalPasswordInput.value;
        const confirmWithdrawalPassword = confirmWithdrawalPasswordInput.value;
        
        if (password.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long!');
            return false;
        }
        
        if (!/[A-Za-z]/.test(password)) {
            e.preventDefault();
            alert('Password must contain at least one letter!');
            return false;
        }
        
        if (!/[0-9]/.test(password)) {
            e.preventDefault();
            alert('Password must contain at least one number!');
            return false;
        }
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match!');
            return false;
        }
        
        if (withdrawalPassword.length < 4) {
            e.preventDefault();
            alert('Withdrawal password must be at least 4 characters long!');
            return false;
        }
        
        if (withdrawalPassword !== confirmWithdrawalPassword) {
            e.preventDefault();
            alert('Withdrawal passwords do not match!');
            return false;
        }
        
        // Show loading state
        submitBtn.disabled = true;
        submitText.innerHTML = '<div class="loading"></div> Processing...';
    });
    
    // Initial validation
    validateForm();
});
</script>
</body>
</html>