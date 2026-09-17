<?php
// =====================
// Session Start
// =====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================
// CSRF Protection
// =====================
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// =====================
// User Authentication & Authorization
// =====================
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function is_admin() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'main_admin', 'sub_admin']);
}

function is_main_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'main_admin';
}

function require_main_admin() {
    if (!is_logged_in() || !is_main_admin()) {
        header('Location: ../index.php');
        exit;
    }
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

// =====================
// User Helpers
// =====================
function get_user_balance($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() ?? 0;
}

function format_balance($amount) {
    return number_format((float)$amount, 2, '.', '');
}

// =====================
// Commission Calculation
// =====================
function calc_commission($amount, $percentage) {
    return round($amount * ($percentage / 100), 2);
}

// =====================
// Invitation Key Generator
// =====================
function generate_invitation_key($length = 12) {
    return 'INV' . strtoupper(bin2hex(random_bytes(8)));
}

// =====================
// Referral Number Generator
// =====================
function generateReferralNumber() {
    global $pdo;
    
    // Generate a unique 8-digit referral number
    do {
        $referral_number = str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
        
        // Check if referral number already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referral_number = ?");
        $stmt->execute([$referral_number]);
        $exists = $stmt->fetchColumn();
    } while ($exists > 0);
    
    return $referral_number;
}

// =====================
// Referral Tracking Functions
// =====================
function trackReferral($user_id, $referrer_key, $pdo) {
    if (empty($referrer_key)) {
        return false;
    }
    
    // Find referrer by invitation key
    $stmt = $pdo->prepare("SELECT id FROM users WHERE invitation_key = ?");
    $stmt->execute([$referrer_key]);
    $referrer = $stmt->fetch();
    
    if (!$referrer) {
        return false;
    }
    
    $referrer_id = $referrer['id'];
    
    // Update user's parent_id (for subadmin filtering) and referrer_id
    $stmt = $pdo->prepare("UPDATE users SET referrer_id = ?, parent_id = ? WHERE id = ?");
    $stmt->execute([$referrer_id, $referrer_id, $user_id]);
    
    // Record level 1 commission (0 amount for now)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO commissions (referrer_id, user_id, level, commission_amount) 
            VALUES (?, ?, 1, 0)
        ");
        $stmt->execute([$referrer_id, $user_id]);
    } catch (Exception $e) {
        error_log("Level 1 commission insert failed: " . $e->getMessage());
    }
    
    // Find level 2 referrer (referrer's referrer)
    $stmt = $pdo->prepare("SELECT referrer_id FROM users WHERE id = ?");
    $stmt->execute([$referrer_id]);
    $level2_referrer = $stmt->fetch();
    
    if ($level2_referrer && $level2_referrer['referrer_id']) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO commissions (referrer_id, user_id, level, commission_amount) 
                VALUES (?, ?, 2, 0)
            ");
            $stmt->execute([$level2_referrer['referrer_id'], $user_id]);
        } catch (Exception $e) {
            error_log("Level 2 commission insert failed: " . $e->getMessage());
        }
        
        // Find level 3 referrer (level 2's referrer)
        $stmt = $pdo->prepare("SELECT referrer_id FROM users WHERE id = ?");
        $stmt->execute([$level2_referrer['referrer_id']]);
        $level3_referrer = $stmt->fetch();
        
        if ($level3_referrer && $level3_referrer['referrer_id']) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO commissions (referrer_id, user_id, level, commission_amount) 
                    VALUES (?, ?, 3, 0)
                ");
                $stmt->execute([$level3_referrer['referrer_id'], $user_id]);
            } catch (Exception $e) {
                error_log("Level 3 commission insert failed: " . $e->getMessage());
            }
        }
    }
    
    return true;
}

function addTaskCommission($user_id, $task_reward, $task_id, $pdo) {
    // Get user's referrers from commissions table
    $stmt = $pdo->prepare("
        SELECT c.referrer_id, c.level 
        FROM commissions c 
        WHERE c.user_id = ? 
        ORDER BY c.level
    ");
    $stmt->execute([$user_id]);
    $referrers = $stmt->fetchAll();
    
    // Commission rates for different levels
    $commission_rates = [
        1 => 0.10, // 10% for level 1
        2 => 0.05, // 5% for level 2  
        3 => 0.025 // 2.5% for level 3
    ];
    
    foreach ($referrers as $ref) {
        $commission_rate = $commission_rates[$ref['level']] ?? 0;
        $commission_amount = $task_reward * $commission_rate;
        
        if ($commission_amount > 0) {
            // Update commission record
            $update_stmt = $pdo->prepare("
                UPDATE commissions 
                SET commission_amount = commission_amount + ?,
                    task_id = ?,
                    description = CONCAT('Commission from task completion - $', ?)
                WHERE referrer_id = ? AND user_id = ? AND level = ?
            ");
            $update_stmt->execute([$commission_amount, $task_id, $task_reward, $ref['referrer_id'], $user_id, $ref['level']]);
            
            // Update referrer's balance
            $balance_stmt = $pdo->prepare("
                UPDATE users 
                SET balance = balance + ? 
                WHERE id = ?
            ");
            $balance_stmt->execute([$commission_amount, $ref['referrer_id']]);
        }
    }
    
    return true;
}

// =====================
// Notifications
// =====================
function send_notification($user_id, $message) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$user_id, $message]);
        return true;
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        return false;
    }
}

// =====================
// File Upload Validation
// =====================
function validate_image($file) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'application/pdf'];
    $max_size = 2 * 1024 * 1024; // 2MB

    if (!in_array($file['type'], $allowed_types)) {
        return "Invalid file type. Only JPG, PNG, GIF, PDF allowed.";
    }

    if ($file['size'] > $max_size) {
        return "File size exceeds 2MB limit.";
    }

    return true;
}

// =====================
// Random String Generator
// =====================
function random_str($length = 5) {
    return bin2hex(random_bytes($length));
}

// =====================
// Deposit Functions
// =====================
function submit_deposit($user_id, $amount, $payment_method, $transaction_id, $proof_image) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO deposits (user_id, amount, payment_method, transaction_id, proof_image, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    if ($stmt->execute([$user_id, $amount, $payment_method, $transaction_id, $proof_image])) {
        return $pdo->lastInsertId();
    }
    return false;
}

function get_user_deposits($user_id, $limit = 5) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM deposits WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$user_id, $limit]);
    return $stmt->fetchAll();
}

// =====================
// Language Functions
// =====================
function trans($key, $replacements = []) {
    global $lang;
    
    if (isset($lang[$key])) {
        $translation = $lang[$key];
        
        // Replace placeholders with actual values
        foreach ($replacements as $placeholder => $value) {
            $translation = str_replace(":$placeholder", $value, $translation);
        }
        
        return $translation;
    }
    
    return $key; // Return the key if translation not found
}

function get_available_languages() {
    return [
        'en' => 'English',
        'es' => 'Español',
        'ur' => 'اردو',
        'ru' => 'Русский'
    ];
}

// =====================
// Task Management Functions
// =====================
function get_user_completed_tasks_today($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS completed_count
        FROM user_tasks ut
        JOIN tasks t ON ut.task_id = t.id
        WHERE ut.user_id = ? AND ut.status = 'completed' AND t.is_special = 0
        AND ut.completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result['completed_count'] ?? 0;
}

function get_user_total_completed_tasks($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_completed
        FROM user_tasks 
        WHERE user_id = ? AND status = 'completed'
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result['total_completed'] ?? 0;
}

function get_total_available_tasks() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT COUNT(*) AS total_tasks 
        FROM tasks 
        WHERE is_active = TRUE
    ");
    $result = $stmt->fetch();
    return $result['total_tasks'] ?? 0;
}

// =====================
// Referral Statistics Functions
// =====================
function get_referral_stats($user_id) {
    global $pdo;
    
    $referral_stats = ['referral_count' => 0, 'total_commission' => 0];
    $hierarchical_stats = [];

    // Method 1: Count from commissions table (most accurate)
    $table_check = $pdo->query("SHOW TABLES LIKE 'commissions'");
    if ($table_check->rowCount() > 0) {
        // Check if commissions table has our user's data
        $check_commissions = $pdo->prepare("SELECT COUNT(*) FROM commissions WHERE referrer_id = ?");
        $check_commissions->execute([$user_id]);
        $has_commissions = $check_commissions->fetchColumn() > 0;
        
        if ($has_commissions) {
            // Direct referrals (level 1) from commissions table
            $referrals_stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT user_id) AS referral_count, 
                       COALESCE(SUM(commission_amount), 0) AS total_commission
                FROM commissions
                WHERE referrer_id = ? AND level = 1
            ");
            $referrals_stmt->execute([$user_id]);
            $referral_stats = $referrals_stmt->fetch();

            // Hierarchical stats (all levels)
            $hierarchical_stmt = $pdo->prepare("
                SELECT level, 
                       COUNT(DISTINCT user_id) AS referral_count, 
                       COALESCE(SUM(commission_amount), 0) AS total_commission
                FROM commissions
                WHERE referrer_id = ?
                GROUP BY level
                ORDER BY level ASC
            ");
            $hierarchical_stmt->execute([$user_id]);
            $hierarchical_stats = $hierarchical_stmt->fetchAll();
        }
    }

    // Method 2: If no commissions data, count direct referrals from users table
    if ($referral_stats['referral_count'] == 0) {
        // Check if users table has referrer_id field
        $columns_check = $pdo->query("SHOW COLUMNS FROM users LIKE 'referrer_id'");
        if ($columns_check->rowCount() > 0) {
            // Count direct referrals
            $direct_referrals_stmt = $pdo->prepare("
                SELECT COUNT(*) AS referral_count 
                FROM users 
                WHERE referrer_id = ?
            ");
            $direct_referrals_stmt->execute([$user_id]);
            $direct_referrals = $direct_referrals_stmt->fetch();
            $referral_stats['referral_count'] = $direct_referrals['referral_count'] ?? 0;
            
            // Create hierarchical stats manually
            if ($referral_stats['referral_count'] > 0) {
                $hierarchical_stats = [
                    ['level' => 1, 'referral_count' => $referral_stats['referral_count'], 'total_commission' => 0]
                ];
            }
        }
    }

    return [
        'referral_stats' => $referral_stats,
        'hierarchical_stats' => $hierarchical_stats
    ];
}

// =====================
// Utility Functions
// =====================
function sanitize_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// =====================
// Database Helper Functions
// =====================
function get_settings() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
    return $stmt->fetch() ?? [];
}

function get_user_by_id($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function get_user_by_email($email) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

// =====================
// Security Functions
// =====================
function generate_secure_password($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function validate_password_strength($password) {
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number";
    }
    return true;
}

// =====================
// ENCRYPTED PASSWORD STORAGE FUNCTIONS - COMPLETELY FIXED (NO WARNINGS)
// =====================

/**
 * Simple encryption function for passwords - FIXED VERSION
 */
function simple_encrypt_password($plain_password) {
    // Use a fixed key for consistency - 32 characters for AES-256
    $key = 'tiktok-shop-encryption-key-2024!';
    $method = 'AES-256-CBC';
    $iv_length = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($iv_length);
    
    $encrypted = openssl_encrypt($plain_password, $method, $key, OPENSSL_RAW_DATA, $iv);
    
    // Combine IV and encrypted data, then base64 encode
    $combined = $iv . $encrypted;
    return base64_encode($combined);
}

/**
 * Simple decryption function for passwords - FIXED VERSION (NO WARNINGS)
 */
function simple_decrypt_password($encrypted_password) {
    // Temporarily suppress warnings for IV length issues
    $error_reporting = error_reporting();
    error_reporting($error_reporting & ~E_WARNING);
    
    try {
        if (empty($encrypted_password)) {
            error_reporting($error_reporting);
            return null;
        }
        
        $key = 'tiktok-shop-encryption-key-2024!'; // Must match encryption key
        $method = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($method);
        
        // Decode the base64 string
        $data = base64_decode($encrypted_password);
        if ($data === false) {
            error_reporting($error_reporting);
            return null;
        }
        
        // Extract IV and encrypted data
        if (strlen($data) < $iv_length) {
            // If data is too short, it might be using a different encryption method
            error_reporting($error_reporting);
            return null;
        }
        
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        
        // Ensure IV is exactly the right length
        if (strlen($iv) < $iv_length) {
            $iv = str_pad($iv, $iv_length, "\0");
        } elseif (strlen($iv) > $iv_length) {
            $iv = substr($iv, 0, $iv_length);
        }
        
        $decrypted = @openssl_decrypt($encrypted, $method, $key, OPENSSL_RAW_DATA, $iv);
        
        // Restore error reporting
        error_reporting($error_reporting);
        
        if ($decrypted === false) {
            return null;
        }
        
        return $decrypted;
    } catch (Exception $e) {
        // Restore error reporting in case of exception
        error_reporting($error_reporting);
        return null;
    }
}

/**
 * Universal password decryption with multiple fallback methods (NO WARNINGS)
 */
function universal_decrypt_password($encrypted_password) {
    if (empty($encrypted_password)) {
        return null;
    }
    
    // Method 1: Try the fixed AES decryption (silent mode)
    $result = @simple_decrypt_password($encrypted_password);
    if ($result !== null && $result !== false) {
        return $result;
    }
    
    // Method 2: Try legacy base64 decode
    $decoded = @base64_decode($encrypted_password, true);
    if ($decoded !== false) {
        // Check if it looks like a password (printable characters)
        if (preg_match('/^[a-zA-Z0-9!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>?~ ]+$/', $decoded)) {
            return $decoded;
        }
    }
    
    // Method 3: Check if it's already plain text
    if (preg_match('/^[a-zA-Z0-9!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>?~ ]+$/', $encrypted_password)) {
        return $encrypted_password;
    }
    
    // Method 4: Try simple XOR decryption (for very old passwords)
    $xor_result = simple_xor_decrypt($encrypted_password);
    if ($xor_result !== null) {
        return $xor_result;
    }
    
    return null;
}

/**
 * Simple XOR decryption for legacy passwords
 */
function simple_xor_decrypt($encrypted_password) {
    $key = 'simple-key';
    $decoded = base64_decode($encrypted_password, true);
    if ($decoded === false) {
        return null;
    }
    
    $result = '';
    $key_length = strlen($key);
    for ($i = 0; $i < strlen($decoded); $i++) {
        $result .= $decoded[$i] ^ $key[$i % $key_length];
    }
    
    // Check if result looks like a valid password
    if (preg_match('/^[a-zA-Z0-9!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>?~ ]+$/', $result)) {
        return $result;
    }
    
    return null;
}

/**
 * Sync user password to encrypted storage - FIXED VERSION
 */
function sync_password_to_encrypted_storage($user_id, $plain_password) {
    global $pdo;
    
    try {
        $encrypted_password = simple_encrypt_password($plain_password);
        
        if ($encrypted_password === false) {
            throw new Exception("Password encryption failed");
        }
        
        // First check if record exists
        $check_stmt = $pdo->prepare("SELECT id FROM user_passwords WHERE user_id = ?");
        $check_stmt->execute([$user_id]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            // Update existing record
            $stmt = $pdo->prepare("
                UPDATE user_passwords 
                SET encrypted_password = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $result = $stmt->execute([$encrypted_password, $user_id]);
        } else {
            // Insert new record
            $stmt = $pdo->prepare("
                INSERT INTO user_passwords (user_id, encrypted_password, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())
            ");
            $result = $stmt->execute([$user_id, $encrypted_password]);
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Password sync failed for user $user_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Decrypt password for admin view - UNIVERSAL VERSION (NO WARNINGS)
 */
function decrypt_password_for_admin($encrypted_password) {
    if (empty($encrypted_password)) {
        throw new Exception("Empty encrypted password");
    }
    
    $decrypted = @universal_decrypt_password($encrypted_password);
    
    if ($decrypted === null) {
        throw new Exception("All decryption methods failed");
    }
    
    return $decrypted;
}

/**
 * Initialize encrypted password system - SIMPLIFIED
 */
function initialize_encrypted_password_system() {
    global $pdo;
    
    try {
        // Check if user_passwords table exists
        $table_exists = $pdo->query("SHOW TABLES LIKE 'user_passwords'")->rowCount() > 0;
        
        if (!$table_exists) {
            // Create simplified user_passwords table
            $pdo->exec("
                CREATE TABLE user_passwords (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL UNIQUE,
                    encrypted_password TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Encryption system init error: " . $e->getMessage());
        return false;
    }
}

function get_user_encrypted_password($user_id) {
    global $pdo;
    
    if (!is_admin()) {
        return null;
    }
    
    $stmt = $pdo->prepare("
        SELECT up.encrypted_password 
        FROM user_passwords up 
        WHERE up.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch();
    
    if (!$data || empty($data['encrypted_password'])) {
        return null;
    }
    
    try {
        return decrypt_password_for_admin($data['encrypted_password']);
    } catch (Exception $e) {
        error_log("Password decryption failed for user $user_id: " . $e->getMessage());
        return null;
    }
}

// =====================
// Task Completion Functions
// =====================
function complete_task($user_id, $task_id) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Get task details
        $task_stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND is_active = TRUE");
        $task_stmt->execute([$task_id]);
        $task = $task_stmt->fetch();
        
        if (!$task) {
            throw new Exception("Task not found or inactive");
        }
        
        // Check if user already completed this task
        $check_stmt = $pdo->prepare("SELECT id FROM user_tasks WHERE user_id = ? AND task_id = ?");
        $check_stmt->execute([$user_id, $task_id]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            throw new Exception("Task already completed");
        }
        
        // Record task completion
        $insert_stmt = $pdo->prepare("
            INSERT INTO user_tasks (user_id, task_id, status, completed_at) 
            VALUES (?, ?, 'completed', NOW())
        ");
        $insert_stmt->execute([$user_id, $task_id]);
        
        // Update user balance
        $reward = $task['reward_amount'];
        $update_stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $update_stmt->execute([$reward, $user_id]);
        
        // Add commission to referrers
        addTaskCommission($user_id, $reward, $task_id, $pdo);
        
        $pdo->commit();
        return [
            'success' => true,
            'reward' => $reward,
            'message' => 'Task completed successfully! Reward: $' . $reward
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// =====================
// Withdrawal Functions
// =====================
function submit_withdrawal($user_id, $amount, $payment_method, $account_details) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Check user balance
        $user_balance = get_user_balance($user_id);
        if ($user_balance < $amount) {
            throw new Exception("Insufficient balance");
        }
        
        // Check minimum withdrawal amount
        $settings = get_settings();
        $min_withdrawal = $settings['min_withdrawal'] ?? 1.00;
        if ($amount < $min_withdrawal) {
            throw new Exception("Minimum withdrawal amount is $" . $min_withdrawal);
        }
        
        // Deduct from user balance
        $update_stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $update_stmt->execute([$amount, $user_id]);
        
        // Record withdrawal
        $insert_stmt = $pdo->prepare("
            INSERT INTO withdrawals (user_id, amount, payment_method, account_details, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $insert_stmt->execute([$user_id, $amount, $payment_method, $account_details]);
        
        $withdrawal_id = $pdo->lastInsertId();
        
        $pdo->commit();
        return $withdrawal_id;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_user_withdrawals($user_id, $limit = 5) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$user_id, $limit]);
    return $stmt->fetchAll();
}

// =====================
// Admin Functions
// =====================
function get_all_users($limit = 50, $offset = 0) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    return $stmt->fetchAll();
}

function get_total_users() {
    global $pdo;
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    return $stmt->fetchColumn();
}

function update_user_status($user_id, $status) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
    return $stmt->execute([$status, $user_id]);
}

function update_user_balance($user_id, $new_balance) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
    return $stmt->execute([$new_balance, $user_id]);
}

// =====================
// Statistics Functions
// =====================
function get_site_statistics() {
    global $pdo;
    
    $stats = [];
    
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $stats['total_users'] = $stmt->fetchColumn();
    
    // Active users (last 30 days)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as active_users FROM user_tasks WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['active_users'] = $stmt->fetchColumn();
    
    // Total tasks completed
    $stmt = $pdo->query("SELECT COUNT(*) as total_tasks_completed FROM user_tasks WHERE status = 'completed'");
    $stats['total_tasks_completed'] = $stmt->fetchColumn();
    
    // Total commissions paid
    $stmt = $pdo->query("SELECT COALESCE(SUM(commission_amount), 0) as total_commissions FROM commissions");
    $stats['total_commissions'] = $stmt->fetchColumn();
    
    // Total withdrawals
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total_withdrawals FROM withdrawals WHERE status = 'approved'");
    $stats['total_withdrawals'] = $stmt->fetchColumn();
    
    // Pending withdrawals
    $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as pending_withdrawals FROM withdrawals WHERE status = 'pending'");
    $stats['pending_withdrawals'] = $stmt->fetchColumn();
    
    return $stats;
}

// =====================
// Email Functions
// =====================
function send_email($to, $subject, $message, $headers = '') {
    // Basic email sending function
    if (empty($headers)) {
        $headers = "From: noreply@yoursite.com\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    }
    
    return mail($to, $subject, $message, $headers);
}

function send_welcome_email($user_email, $username) {
    $subject = "Welcome to Our Platform!";
    $message = "
    <html>
    <head>
        <title>Welcome!</title>
    </head>
    <body>
        <h2>Welcome to Our Platform, " . htmlspecialchars($username) . "!</h2>
        <p>Thank you for joining us. We're excited to have you on board.</p>
        <p>Start completing tasks and earning rewards today!</p>
        <br>
        <p>Best regards,<br>Your Platform Team</p>
    </body>
    </html>
    ";
    
    return send_email($user_email, $subject, $message);
}

// =====================
// Maintenance Functions
// =====================
function cleanup_old_sessions() {
    global $pdo;
    // Delete sessions older than 30 days
    $stmt = $pdo->prepare("DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    return $stmt->execute();
}

function cleanup_old_notifications() {
    global $pdo;
    // Delete read notifications older than 90 days
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE is_read = TRUE AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    return $stmt->execute();
}

// =====================
// Backup Functions
// =====================
function create_database_backup() {
    global $pdo;
    
    $backup_dir = 'backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    $backup_file = $backup_dir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
    $tables = array();
    
    // Get all tables
    $result = $pdo->query('SHOW TABLES');
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    $output = '';
    
    foreach ($tables as $table) {
        // Table structure
        $result = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $result->fetch(PDO::FETCH_NUM);
        $output .= "\n\n" . $row[1] . ";\n\n";
        
        // Table data
        $result = $pdo->query("SELECT * FROM `$table`");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $output .= "INSERT INTO `$table` VALUES(";
            for ($j = 0; $j < count($row); $j++) {
                $row[$j] = addslashes($row[$j]);
                $row[$j] = str_replace("\n", "\\n", $row[$j]);
                if (isset($row[$j])) {
                    $output .= '"' . $row[$j] . '"';
                } else {
                    $output .= '""';
                }
                if ($j < (count($row) - 1)) {
                    $output .= ',';
                }
            }
            $output .= ");\n";
        }
        $output .= "\n";
    }
    
    // Save file
    return file_put_contents($backup_file, $output) !== false;
}

// =====================
// Security Headers
// =====================
function set_security_headers() {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// =====================
// Input Validation
// =====================
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_amount($amount) {
    return is_numeric($amount) && $amount > 0;
}

function validate_date($date, $format = 'Y-m-d H:i:s') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) == $date;
}

// =====================
// API Response Helpers
// =====================
function api_success($data = [], $message = 'Success') {
    return json_response([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

function api_error($message = 'Error', $status_code = 400) {
    return json_response([
        'success' => false,
        'message' => $message
    ], $status_code);
}

// =====================
// Debug Functions
// =====================
function debug_log($data, $label = 'DEBUG') {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("[$label] " . print_r($data, true));
    }
}

function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// =====================
// Error Handling
// =====================
function log_error($message) {
    $log_file = __DIR__ . '/../logs/error.log';
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0755, true);
    }
    error_log(date('Y-m-d H:i:s') . " - " . $message . "\n", 3, $log_file);
}

function display_error($message) {
    return '<div class="alert alert-danger">' . htmlspecialchars($message) . '</div>';
}

function display_success($message) {
    return '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
}

// =====================
// Initialize essential systems
// =====================
function initialize_system() {
    // Set security headers
    set_security_headers();
    
    // Set default timezone
    date_default_timezone_set('UTC');
    
    // Initialize encrypted password system
    initialize_encrypted_password_system();
}

// Initialize system
initialize_system();
?>