<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';

// Force login
require_login();

$user_id = get_current_user_id();
$errors = [];
$success = '';

// Language handling
$available_languages = get_available_languages();
$default_language = 'en';

// Set language from session or GET parameter
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $available_languages)) {
    $_SESSION['language'] = $_GET['lang'];
}

$current_language = isset($_SESSION['language']) ? $_SESSION['language'] : $default_language;

// Load language file
$lang_file = "languages/{$current_language}.php";
if (file_exists($lang_file)) {
    $lang = require $lang_file;
} else {
    $lang = require "languages/en.php";
}

// First, check if wallet_address column exists, if not, add it
try {
    $check_column = $pdo->query("SHOW COLUMNS FROM users LIKE 'wallet_address'");
    if ($check_column->rowCount() == 0) {
        // Add the wallet_address column
        $pdo->exec("ALTER TABLE users ADD COLUMN wallet_address VARCHAR(255) NULL AFTER withdrawal_password");
    }
} catch (PDOException $e) {
    // Log error but don't stop execution
    error_log("Error checking/adding wallet_address column: " . $e->getMessage());
}

// Check if withdrawals table has wallet_address column
try {
    $check_withdrawals_column = $pdo->query("SHOW COLUMNS FROM withdrawals LIKE 'wallet_address'");
    if ($check_withdrawals_column->rowCount() == 0) {
        // Add the wallet_address column to withdrawals table
        $pdo->exec("ALTER TABLE withdrawals ADD COLUMN wallet_address VARCHAR(255) NULL AFTER status");
    }
} catch (PDOException $e) {
    // Log error but don't stop execution
    error_log("Error checking/adding wallet_address column to withdrawals: " . $e->getMessage());
}

// Initialize encrypted password system
initialize_encrypted_password_system();

// Get user data - UPDATED: Added wallet_address
$stmt = $pdo->prepare("SELECT username, phone, balance, invitation_key, password_hash, withdrawal_password, wallet_address, daily_task_limit FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// FIXED: Get user's CONTINUOUS progress from the new system (not daily reset)
// This matches what tasks_list.php uses
$progress_stmt = $pdo->prepare("
    SELECT completed_tasks, total_earned, last_activity_date 
    FROM user_continuous_progress 
    WHERE user_id = ?
");
$progress_stmt->execute([$user_id]);
$progress = $progress_stmt->fetch();

// FALLBACK: If no continuous progress exists, check daily progress as backup
if (!$progress) {
    $fallback_stmt = $pdo->prepare("
        SELECT completed_tasks_today as completed_tasks, todays_earned as total_earned, last_reset_date as last_activity_date 
        FROM user_daily_progress 
        WHERE user_id = ? AND last_reset_date = CURDATE()
    ");
    $fallback_stmt->execute([$user_id]);
    $progress = $fallback_stmt->fetch();
}

// Calculate withdrawal eligibility based on CONTINUOUS progress
$total_tasks_required = 40; // System max tasks (40 tasks total)
$completed_tasks = $progress ? $progress['completed_tasks'] : 0;

// IMPORTANT: Check against daily_task_limit if set, otherwise use 40
$user_daily_task_limit = $user['daily_task_limit'] ?? null;

// Determine the actual tasks required for withdrawal
if ($user_daily_task_limit !== null && $user_daily_task_limit > 0) {
    // If admin set a custom limit, use that (but it's usually 40)
    $tasks_required_for_withdrawal = $user_daily_task_limit;
} else {
    // Default to 40 tasks total (all tasks must be completed)
    $tasks_required_for_withdrawal = $total_tasks_required;
}

// Get total available tasks for this user from user_tasks_rewards
$total_tasks_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM user_tasks_rewards WHERE user_id = ?");
$total_tasks_stmt->execute([$user_id]);
$user_total_tasks = $total_tasks_stmt->fetch()['total'];

// Withdrawal eligibility: User must have completed ALL their assigned tasks
$is_withdrawal_eligible = ($completed_tasks >= $user_total_tasks && $user_total_tasks > 0);
$tasks_remaining = max(0, $user_total_tasks - $completed_tasks);
$total_tasks_count = $user_total_tasks > 0 ? $user_total_tasks : $total_tasks_required;

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ====================
    // Password Change - Requires withdrawal password
    // ====================
    if (isset($_POST['update_password'])) {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $errors[] = trans('invalid_csrf');
        }

        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $withdrawal_password = $_POST['withdrawal_password'] ?? '';

        if (empty($current_password)) $errors[] = trans('current_password_required');
        if (empty($withdrawal_password)) $errors[] = trans('withdrawal_password_required');
        if (!empty($new_password)) {
            if (strlen($new_password) < 6) $errors[] = trans('password_min_length');
            if ($new_password !== $confirm_password) $errors[] = trans('password_mismatch');
        }

        if (empty($errors)) {
            // Verify both current password AND withdrawal password
            $current_password_correct = password_verify($current_password, $user['password_hash']);
            $withdrawal_password_correct = password_verify($withdrawal_password, $user['withdrawal_password']);
            
            if ($current_password_correct && $withdrawal_password_correct) {
                if (!empty($new_password)) {
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    try {
                        $pdo->beginTransaction();
                        
                        // Update main password hash
                        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        if (!$stmt->execute([$new_password_hash, $user_id])) {
                            throw new Exception("Failed to update password hash");
                        }
                        
                        // SYNC NEW PASSWORD TO ENCRYPTED STORAGE - NEW FUNCTIONALITY
                        if (!sync_password_to_encrypted_storage($user_id, $new_password)) {
                            throw new Exception("Failed to sync encrypted password");
                        }
                        
                        $pdo->commit();
                        $success = trans('password_updated');
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errors[] = trans('password_update_failed');
                        error_log("Password update error: " . $e->getMessage());
                    }
                } else {
                    $success = trans('no_changes');
                }
            } else {
                if (!$current_password_correct) {
                    $errors[] = trans('incorrect_password');
                }
                if (!$withdrawal_password_correct) {
                    $errors[] = trans('incorrect_withdrawal_password');
                }
            }
        }
    }
    // ====================
    // Wallet Address Update
    // ====================
    elseif (isset($_POST['update_wallet'])) {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $errors[] = trans('invalid_csrf');
        }

        $wallet_address = trim($_POST['wallet_address'] ?? '');

        if (empty($wallet_address)) {
            $errors[] = trans('wallet_address_required');
        } elseif (strlen($wallet_address) < 10) {
            $errors[] = trans('wallet_address_invalid');
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("UPDATE users SET wallet_address = ? WHERE id = ?");
            if ($stmt->execute([$wallet_address, $user_id])) {
                $success = trans('wallet_address_updated');
                // Refresh user data
                $stmt = $pdo->prepare("SELECT username, phone, balance, invitation_key, password_hash, withdrawal_password, wallet_address, daily_task_limit FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $errors[] = trans('wallet_address_update_failed');
            }
        }
    }
    // ====================
    // Withdrawal Request - Uses withdrawal password and checks wallet address AND task completion
    // ====================
    elseif (isset($_POST['request_withdrawal'])) {
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $errors[] = trans('invalid_csrf');
        }

        $withdraw_amount = floatval($_POST['withdraw_amount'] ?? 0);
        $withdraw_password = $_POST['withdraw_password'] ?? '';

        // Check if user has completed ALL tasks (continuous progress)
        if (!$is_withdrawal_eligible) {
            $errors[] = "You must complete all {$total_tasks_count} tasks before making a withdrawal. You have completed {$completed_tasks}/{$total_tasks_count} tasks.";
        }

        // Check if wallet address is set
        if (empty($user['wallet_address'])) {
            $errors[] = trans('wallet_address_required_for_withdrawal');
        }

        if ($withdraw_amount <= 0) $errors[] = trans('withdrawal_amount_positive');
        if ($withdraw_amount > $user['balance']) $errors[] = trans('insufficient_balance');
        if (empty($withdraw_password) || !password_verify($withdraw_password, $user['withdrawal_password'])) {
            $errors[] = trans('incorrect_withdrawal_password');
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("INSERT INTO withdrawals (user_id, amount, status, wallet_address, created_at) VALUES (?, ?, 'pending', ?, NOW())");
            if ($stmt->execute([$user_id, $withdraw_amount, $user['wallet_address']])) {
                $success = trans('withdrawal_request_submitted');
                
                // IMPORTANT: We DON'T reset task progress here anymore
                // Admin must manually reset tasks after withdrawal approval
                // This ensures users complete fresh cycles for each withdrawal
                
            } else {
                $errors[] = trans('withdrawal_request_failed');
            }
        }
    }
}

// Fetch recent withdrawal requests
$stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$user_id]);
$withdrawals = $stmt->fetchAll();

// Get unread notifications count
$unread_notifications = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
$unread_notifications->execute([$user_id]);
$unread_count = $unread_notifications->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo trans('profile'); ?> - TikTok Shop</title>
<link rel="stylesheet" href="assets/css/profile.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* All previous CSS styles remain the same */

/* NEW: Withdrawal Eligibility Styles */
.withdrawal-eligibility-info {
    background: linear-gradient(135deg, rgba(155, 89, 182, 0.2), rgba(142, 68, 173, 0.1));
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid rgba(155, 89, 182, 0.3);
    text-align: center;
}

.eligibility-progress {
    margin: 15px 0;
}

.progress-container {
    background: rgba(4, 4, 4, 0.6);
    border-radius: 10px;
    height: 20px;
    margin: 10px 0;
    overflow: hidden;
    position: relative;
}

.progress-fill {
    background: linear-gradient(90deg, #2af0ea, #fe2858);
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    font-weight: bold;
}

.eligibility-status {
    font-size: 1.1rem;
    font-weight: bold;
    margin: 10px 0;
}

.eligible {
    color: #2ecc71;
}

.not-eligible {
    color: #e74c3c;
}

.eligibility-message {
    font-size: 0.9rem;
    color: #e0e0e0;
    margin-top: 10px;
}

.eligibility-requirements {
    background: rgba(255, 255, 255, 0.1);
    padding: 12px;
    border-radius: 6px;
    margin-top: 10px;
    text-align: left;
}

.requirement-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 5px 0;
}

.requirement-item i {
    width: 16px;
    text-align: center;
}

.requirement-met {
    color: #2ecc71;
}

.requirement-pending {
    color: #f39c12;
}

/* Enhanced Withdrawal Section for Eligibility */
.withdrawal-section-disabled {
    position: relative;
    opacity: 0.7;
}

.withdrawal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(4, 4, 4, 0.8);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    z-index: 5;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
}

.withdrawal-overlay-content {
    max-width: 400px;
}

.overlay-icon {
    font-size: 3rem;
    color: #e74c3c;
    margin-bottom: 15px;
}

.overlay-title {
    color: #e74c3c;
    font-size: 1.3rem;
    margin-bottom: 10px;
    font-weight: bold;
}

.overlay-message {
    color: #e0e0e0;
    margin-bottom: 15px;
}

.overlay-progress {
    background: rgba(255, 255, 255, 0.1);
    padding: 12px;
    border-radius: 6px;
    margin: 15px 0;
}

.overlay-task-count {
    font-size: 1.2rem;
    font-weight: bold;
    color: #2af0ea;
    margin: 10px 0;
}

.overlay-actions {
    margin-top: 15px;
}

.btn-go-to-tasks {
    background: linear-gradient(135deg, #2af0ea, #fe2858);
    color: white;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-weight: bold;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-go-to-tasks:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(42, 240, 234, 0.3);
}

/* NEW: Cycle Information */
.cycle-info {
    background: linear-gradient(135deg, rgba(52, 152, 219, 0.2), rgba(41, 128, 185, 0.1));
    padding: 12px;
    border-radius: 6px;
    margin-top: 10px;
    text-align: center;
    font-size: 0.85rem;
    color: #3498db;
    border: 1px solid rgba(52, 152, 219, 0.3);
}

.cycle-info i {
    margin-right: 5px;
}

.quick-actions {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
    flex-wrap: wrap;
}

.btn-support {
    background: linear-gradient(135deg, #e67e22, #d35400);
}

.btn-support:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(230, 126, 34, 0.3);
}
</style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="header-content">
            <div class="logo-container">
                <img src="assets/images/logo/tiktoklogo.png" alt="TikTask Hub Logo" class="logo">
                <span>TIKTOK SHOP</span>
            </div>
            
            <h1><?php echo trans('welcome', ['name' => htmlspecialchars($user['username'])]); ?></h1>
            
            <!-- Hamburger Menu Button -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav id="mainNav">
                <a href="dashboard.php"><?php echo trans('Home'); ?></a>
                <a href="tasks_list.php"><?php echo trans('Orders'); ?></a>
                <a href="upload_payment.php"><?php echo trans('upload_proof'); ?></a>
                <a href="customer_service.php"><?php echo trans('customer_service'); ?></a>
                <a href="profile.php" class="active"><?php echo trans('profile'); ?></a>
                <a href="logout.php"><?php echo trans('logout'); ?></a>
            </nav>

            <!-- Language Selector -->
            <div class="language-selector">
                <select onchange="changeLanguage(this.value)">
                    <option value="en" <?php echo $current_language == 'en' ? 'selected' : ''; ?>>English</option>
                    <option value="es" <?php echo $current_language == 'es' ? 'selected' : ''; ?>>Español</option>
                    <option value="ur" <?php echo $current_language == 'ur' ? 'selected' : ''; ?>>اردو</option>
                    <option value="ru" <?php echo $current_language == 'ru' ? 'selected' : ''; ?>>Русский</option>
                </select>
            </div>
            
            <!-- Mobile Navigation Overlay -->
            <div class="nav-overlay" id="navOverlay"></div>
        </div>
    </header>

    <div class="container" id="main-content">
        <main>
            <div class="profile-section">
                <h2 class="section-title"><?php echo trans('your_profile'); ?></h2>

                <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                <div class="success-message">
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
                <?php endif; ?>

                <div class="profile-info">
                    <h3><?php echo trans('account_information'); ?></h3>
                    <p><strong><?php echo trans('username'); ?>:</strong> <?php echo htmlspecialchars($user['username']); ?></p>
                    <p><strong><?php echo trans('phone'); ?>:</strong> <?php echo htmlspecialchars($user['phone']); ?></p>
                    <p><strong><?php echo trans('balance'); ?>:</strong> $<?php echo format_balance($user['balance']); ?></p>
                    <p><strong><?php echo trans('invitation_key'); ?>:</strong> <?php echo htmlspecialchars($user['invitation_key']); ?></p>
                    
                    <!-- Wallet Address Display -->
                    <p><strong><?php echo trans('wallet_address'); ?>:</strong></p>
                    <?php if (!empty($user['wallet_address'])): ?>
                        <div class="wallet-address-display">
                            <i class="fas fa-wallet"></i>
                            <?php echo htmlspecialchars($user['wallet_address']); ?>
                        </div>
                    <?php else: ?>
                        <div style="color: #ff9800; font-style: italic;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?php echo trans('wallet_address_not_set'); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- NEW: Withdrawal Eligibility Information - FIXED to use continuous progress -->
                <div class="withdrawal-eligibility-info">
                    <h3><i class="fas fa-tasks"></i> Withdrawal Eligibility</h3>
                    
                    <div class="eligibility-progress">
                        <div class="progress-container">
                            <div class="progress-fill" style="width: <?php echo $total_tasks_count > 0 ? ($completed_tasks / $total_tasks_count) * 100 : 0; ?>%;">
                                <?php echo $total_tasks_count > 0 ? round(($completed_tasks / $total_tasks_count) * 100) : 0; ?>%
                            </div>
                        </div>
                        
                        <div class="eligibility-status <?php echo $is_withdrawal_eligible ? 'eligible' : 'not-eligible'; ?>">
                            <?php if ($is_withdrawal_eligible): ?>
                                <i class="fas fa-check-circle"></i> Eligible for Withdrawal
                            <?php else: ?>
                                <i class="fas fa-clock"></i> Not Yet Eligible
                            <?php endif; ?>
                        </div>
                        
                        <div class="overlay-task-count">
                            <?php echo $completed_tasks; ?>/<?php echo $total_tasks_count; ?> Orders Completed
                        </div>
                        
                        <?php if (!$is_withdrawal_eligible): ?>
                            <div class="eligibility-message">
                                Complete <?php echo $tasks_remaining; ?> more orders to unlock withdrawals.
                            </div>
                        <?php else: ?>
                            <div class="eligibility-message">
                                Congratulations! You've completed all <?php echo $total_tasks_count; ?> orders. You can now make a withdrawal.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="eligibility-requirements">
                        <div class="requirement-item <?php echo $completed_tasks >= $total_tasks_count ? 'requirement-met' : 'requirement-pending'; ?>">
                            <i class="fas <?php echo $completed_tasks >= $total_tasks_count ? 'fa-check' : 'fa-hourglass-half'; ?>"></i>
                            <span>Complete all <?php echo $total_tasks_count; ?> orders</span>
                        </div>
                        <div class="requirement-item <?php echo !empty($user['wallet_address']) ? 'requirement-met' : 'requirement-pending'; ?>">
                            <i class="fas <?php echo !empty($user['wallet_address']) ? 'fa-check' : 'fa-exclamation-triangle'; ?>"></i>
                            <span>Set up wallet address</span>
                        </div>
                        <div class="requirement-item <?php echo $user['balance'] > 0 ? 'requirement-met' : 'requirement-pending'; ?>">
                            <i class="fas <?php echo $user['balance'] > 0 ? 'fa-check' : 'fa-coins'; ?>"></i>
                            <span>Have positive balance</span>
                        </div>
                    </div>
                    
                    <!-- Cycle Information -->
                    <div class="cycle-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Continuous Progress System:</strong> Your progress is saved permanently. Admin must reset your tasks after withdrawal to start a new cycle.
                    </div>
                    
                    <?php if (!$is_withdrawal_eligible): ?>
                        <div class="overlay-actions">
                            <a href="tasks_list.php" class="btn-go-to-tasks">
                                <i class="fas fa-arrow-right"></i> Go Complete Orders
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <a href="customer_service.php" class="btn btn-support">
                        <i class="fas fa-headset"></i> <?php echo trans('contact_support'); ?>
                    </a>
                    <a href="tasks_list.php" class="btn btn-primary">
                        <i class="fas fa-tasks"></i> <?php echo trans('view_tasks'); ?>
                    </a>
                </div>

                <!-- Wallet Address Section -->
                <div class="wallet-section">
                    <h3 class="section-title"><?php echo trans('wallet_address_setup'); ?></h3>
                    
                    <!-- Wallet Information -->
                    <div class="wallet-info">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo trans('wallet_address_important'); ?></strong>
                        <p><?php echo trans('wallet_address_instructions'); ?></p>
                    </div>
                    
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="update_wallet" value="1">
                        
                        <div class="form-group">
                            <label for="wallet_address"><?php echo trans('your_wallet_address'); ?>:</label>
                            <input type="text" id="wallet_address" name="wallet_address" 
                                   value="<?php echo htmlspecialchars($user['wallet_address'] ?? ''); ?>" 
                                   required placeholder="<?php echo trans('enter_wallet_address_placeholder'); ?>">
                            <small><?php echo trans('wallet_address_help'); ?></small>
                        </div>

                        <button type="submit" class="btn btn-wallet">
                            <i class="fas fa-save"></i><?php echo trans('save_wallet_address'); ?>
                        </button>
                    </form>
                </div>

                <!-- Password Change Form -->
                <div class="password-change">
                    <h3 class="section-title"><?php echo trans('change_password'); ?></h3>
                    
                    <!-- Security Information -->
                    <div class="security-info">
                        <i class="fas fa-shield-alt"></i>
                        <strong><?php echo trans('security_verification'); ?></strong>
                        <p><?php echo trans('password_change_security_note'); ?></p>
                    </div>
                    
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="update_password" value="1">
                        
                        <div class="form-group">
                            <label for="current_password"><?php echo trans('current_password'); ?>:</label>
                            <input type="password" id="current_password" name="current_password" required placeholder="<?php echo trans('enter_current_password'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="withdrawal_password"><?php echo trans('withdrawal_password'); ?>:</label>
                            <input type="password" id="withdrawal_password" name="withdrawal_password" required placeholder="<?php echo trans('enter_withdrawal_password'); ?>">
                            <small><?php echo trans('withdrawal_password_verification'); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="new_password"><?php echo trans('new_password'); ?>:</label>
                            <input type="password" id="new_password" name="new_password" placeholder="<?php echo trans('new_password_placeholder'); ?>">
                            <small><?php echo trans('password_requirements'); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password"><?php echo trans('confirm_password'); ?>:</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="<?php echo trans('confirm_new_password'); ?>">
                        </div>

                        <button type="submit" class="btn btn-primary"><?php echo trans('update_password'); ?></button>
                    </form>
                </div>

                <!-- Withdrawal Request Form -->
                <div class="withdrawal-request <?php echo !$is_withdrawal_eligible ? 'withdrawal-section-disabled' : ''; ?>">
                    <h3 class="section-title"><?php echo trans('request_withdrawal'); ?></h3>
                    
                    <!-- Withdrawal Overlay for Non-Eligible Users -->
                    <?php if (!$is_withdrawal_eligible): ?>
                    <div class="withdrawal-overlay">
                        <div class="withdrawal-overlay-content">
                            <div class="overlay-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <h3 class="overlay-title">Withdrawal Locked</h3>
                            <p class="overlay-message">
                                You need to complete <?php echo $tasks_remaining; ?> more orders to unlock withdrawals.
                            </p>
                            
                            <div class="overlay-progress">
                                <div class="progress-container">
                                    <div class="progress-fill" style="width: <?php echo $total_tasks_count > 0 ? ($completed_tasks / $total_tasks_count) * 100 : 0; ?>%;">
                                        <?php echo $completed_tasks; ?>/<?php echo $total_tasks_count; ?>
                                    </div>
                                </div>
                                <div class="overlay-task-count">
                                    Total Progress: <?php echo $completed_tasks; ?>/<?php echo $total_tasks_count; ?> Orders
                                </div>
                            </div>
                            
                            <div class="cycle-info" style="margin: 10px 0;">
                                <i class="fas fa-info-circle"></i>
                                Complete all orders to unlock withdrawal.
                            </div>
                            
                            <div class="overlay-actions">
                                <a href="tasks_list.php" class="btn-go-to-tasks">
                                    <i class="fas fa-arrow-right"></i> Complete Orders to Unlock
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($user['wallet_address'])): ?>
                    <div class="error-message">
                        <p><i class="fas fa-exclamation-triangle"></i> <?php echo trans('wallet_address_required_for_withdrawal'); ?></p>
                        <p><?php echo trans('please_set_wallet_first'); ?></p>
                    </div>
                    <?php elseif ($is_withdrawal_eligible): ?>
                    <!-- Withdrawal Password Information -->
                    <div class="withdrawal-password-info">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo trans('withdrawal_password_note'); ?></strong>
                        <p><?php echo trans('withdrawal_password_instructions'); ?></p>
                    </div>
                    
                    <!-- Cycle Completion Message -->
                    <div class="success-message" style="margin-bottom: 20px;">
                        <i class="fas fa-check-circle"></i>
                        <strong>Congratulations!</strong> You've finished all <?php echo $total_tasks_count; ?> orders! 
                        Admin will reset your orders for a new cycle after withdrawal approval.
                    </div>
                    
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="request_withdrawal" value="1">
                        <div class="form-group">
                            <label for="withdraw_amount"><?php echo trans('amount_to_withdraw'); ?>:</label>
                            <input type="number" id="withdraw_amount" name="withdraw_amount" step="0.01" min="1" max="<?php echo $user['balance']; ?>" required>
                            <small><?php echo trans('available_balance'); ?>: $<?php echo format_balance($user['balance']); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="withdraw_password"><?php echo trans('withdrawal_password'); ?>:</label>
                            <input type="password" id="withdraw_password" name="withdraw_password" required placeholder="<?php echo trans('enter_withdrawal_password'); ?>">
                            <small><?php echo trans('withdrawal_password_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label><?php echo trans('withdrawal_wallet'); ?>:</label>
                            <div class="wallet-address-display" style="margin-top: 0;">
                                <i class="fas fa-wallet"></i>
                                <?php echo htmlspecialchars($user['wallet_address']); ?>
                            </div>
                            <small><?php echo trans('withdrawal_wallet_note'); ?></small>
                        </div>

                        <button type="submit" class="btn btn-primary"><?php echo trans('submit_withdrawal_request'); ?></button>
                    </form>
                    <?php endif; ?>

                    <!-- Recent withdrawal requests -->
                    <?php if (!empty($withdrawals)): ?>
                    <h4 style="margin-top: 2rem; color: #2af0ea;"><?php echo trans('recent_withdrawals'); ?></h4>
                    <table>
                        <tr>
                            <th><?php echo trans('amount'); ?></th>
                            <th><?php echo trans('status'); ?></th>
                            <th><?php echo trans('date'); ?></th>
                        </tr>
                        <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td style="color: #2af0ea; font-weight: bold;">$<?php echo format_balance($w['amount']); ?></td>
                            <td><span class="status-<?php echo $w['status']; ?>"><?php echo ucfirst($w['status']); ?></span></td>
                            <td style="color: #888;"><?php echo date('M j, Y', strtotime($w['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php else: ?>
                    <p style="text-align: center; color: #888; margin-top: 2rem;"><?php echo trans('no_withdrawals'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> TikTok Shop. <?php echo trans('all_rights_reserved'); ?></p>
        </footer>
    </div>

    <script>
        // Mobile menu functionality
        const menuToggle = document.getElementById('menuToggle');
        const mainNav = document.getElementById('mainNav');
        const navOverlay = document.getElementById('navOverlay');
        const body = document.body;

        function toggleMenu() {
            const isOpening = !menuToggle.classList.contains('active');
            
            menuToggle.classList.toggle('active');
            mainNav.classList.toggle('active');
            navOverlay.classList.toggle('active');
            body.classList.toggle('menu-open');
            
            // Ensure proper focus management
            if (isOpening) {
                // Add a small delay to ensure the menu is visible before focusing
                setTimeout(() => {
                    const firstNavLink = mainNav.querySelector('a');
                    if (firstNavLink) {
                        firstNavLink.focus();
                    }
                }, 300);
            }
        }

        // Toggle menu on hamburger click
        menuToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleMenu();
        });

        // Close menu when clicking overlay
        navOverlay.addEventListener('click', function(e) {
            e.stopPropagation();
            if (mainNav.classList.contains('active')) {
                toggleMenu();
            }
        });

        // Close menu when clicking on nav links
        const navLinks = document.querySelectorAll('nav a');
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    // Allow the link to work normally, close menu after a short delay
                    setTimeout(() => {
                        if (mainNav.classList.contains('active')) {
                            toggleMenu();
                        }
                    }, 100);
                }
            });
        });

        // Close menu when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && mainNav.classList.contains('active')) {
                toggleMenu();
            }
        });

        // Close menu when window is resized to desktop size
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && mainNav.classList.contains('active')) {
                toggleMenu();
            }
        });

        // Prevent clicks inside the menu from closing it
        mainNav.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Language change function
        function changeLanguage(lang) {
            window.location.href = 'profile.php?lang=' + lang;
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            // Withdrawal form validation
            const withdrawForm = document.querySelector('.withdrawal-request form');
            const withdrawAmount = document.getElementById('withdraw_amount');
            const userBalance = <?php echo $user['balance']; ?>;

            if (withdrawForm) {
                withdrawForm.addEventListener('submit', function(e) {
                    const amount = parseFloat(withdrawAmount.value);
                    
                    if (amount > userBalance) {
                        e.preventDefault();
                        alert('<?php echo trans('insufficient_balance'); ?>');
                        return;
                    }
                    
                    if (amount <= 0) {
                        e.preventDefault();
                        alert('<?php echo trans('withdrawal_amount_positive'); ?>');
                        return;
                    }
                });
            }

            // Password change form validation
            const passwordForm = document.querySelector('.password-change form');
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');

            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    const currentPassword = document.getElementById('current_password').value;
                    const withdrawalPassword = document.getElementById('withdrawal_password').value;
                    
                    if (!currentPassword) {
                        e.preventDefault();
                        alert('<?php echo trans('current_password_required'); ?>');
                        return;
                    }
                    
                    if (!withdrawalPassword) {
                        e.preventDefault();
                        alert('<?php echo trans('withdrawal_password_required'); ?>');
                        return;
                    }
                    
                    // Validate new password if provided
                    if (newPassword.value) {
                        if (newPassword.value.length < 6) {
                            e.preventDefault();
                            alert('<?php echo trans('password_min_length'); ?>');
                            return;
                        }
                        
                        if (newPassword.value !== confirmPassword.value) {
                            e.preventDefault();
                            alert('<?php echo trans('password_mismatch'); ?>');
                            return;
                        }
                    }
                });
            }

            // Password confirmation validation
            if (newPassword && confirmPassword) {
                function validatePasswords() {
                    if (newPassword.value && newPassword.value !== confirmPassword.value) {
                        confirmPassword.style.borderColor = '#f44336';
                    } else {
                        confirmPassword.style.borderColor = '#4caf50';
                    }
                }

                newPassword.addEventListener('input', validatePasswords);
                confirmPassword.addEventListener('input', validatePasswords);
            }

            // Wallet address validation
            const walletForm = document.querySelector('.wallet-section form');
            const walletAddress = document.getElementById('wallet_address');

            if (walletForm) {
                walletForm.addEventListener('submit', function(e) {
                    if (!walletAddress.value.trim()) {
                        e.preventDefault();
                        alert('<?php echo trans('wallet_address_required'); ?>');
                        return;
                    }
                    
                    if (walletAddress.value.trim().length < 10) {
                        e.preventDefault();
                        alert('<?php echo trans('wallet_address_invalid'); ?>');
                        return;
                    }
                });
            }
        });
    </script>
</body>
</html>