<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login(); // Force login

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

// Get current user info
$user_id = get_current_user_id();
$stmt = $pdo->prepare("SELECT username, balance, invitation_key, daily_earning_limit FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get user's daily progress
$progress_stmt = $pdo->prepare("
    SELECT completed_tasks_today, todays_earned, current_task_index 
    FROM user_daily_progress 
    WHERE user_id = ? AND last_reset_date = CURDATE()
");
$progress_stmt->execute([$user_id]);
$progress = $progress_stmt->fetch();

$completed_today = $progress['completed_tasks_today'] ?? 0;
$earned_today = $progress['todays_earned'] ?? 0;
$current_task = $progress['current_task_index'] ?? 0;

// Get total completed tasks (all time)
$total_completed_stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_completed
    FROM daily_task_rewards 
    WHERE user_id = ? AND is_completed = 1
");
$total_completed_stmt->execute([$user_id]);
$total_completed = $total_completed_stmt->fetch()['total_completed'] ?? 0;

// Fetch total available tasks
$total_tasks_stmt = $pdo->query("
    SELECT COUNT(*) AS total_tasks 
    FROM tasks 
    WHERE is_active = TRUE
");
$total_tasks = $total_tasks_stmt->fetch()['total_tasks'] ?? 0;

// ==============================
// SIMPLIFIED AND RELIABLE Referral stats
// ==============================
$referral_stats = ['referral_count' => 0, 'total_commission' => 0];
$hierarchical_stats = [];

// Method 1: Check if users table has referrer_id column
$columns_check = $pdo->query("SHOW COLUMNS FROM users LIKE 'referrer_id'");
if ($columns_check->rowCount() > 0) {
    // Count direct referrals from users table (most reliable)
    $direct_referrals_stmt = $pdo->prepare("
        SELECT COUNT(*) AS referral_count 
        FROM users 
        WHERE referrer_id = ?
    ");
    $direct_referrals_stmt->execute([$user_id]);
    $direct_referrals = $direct_referrals_stmt->fetch();
    $referral_stats['referral_count'] = $direct_referrals['referral_count'] ?? 0;
    
    // Get commission data if available
    $table_check = $pdo->query("SHOW TABLES LIKE 'commissions'");
    if ($table_check->rowCount() > 0) {
        $commission_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(commission_amount), 0) AS total_commission
            FROM commissions
            WHERE referrer_id = ?
        ");
        $commission_stmt->execute([$user_id]);
        $commission_data = $commission_stmt->fetch();
        $referral_stats['total_commission'] = $commission_data['total_commission'] ?? 0;
        
        // Get hierarchical stats from commissions table
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
    
    // If no hierarchical stats from commissions, create from direct referrals
    if (empty($hierarchical_stats) && $referral_stats['referral_count'] > 0) {
        $hierarchical_stats = [
            ['level' => 1, 'referral_count' => $referral_stats['referral_count'], 'total_commission' => $referral_stats['total_commission']]
        ];
    }
}

// DEBUG: If still no referrals, let's check what's actually in the database
if ($referral_stats['referral_count'] == 0) {
    // Debug query to see if there are any users with this user as referrer
    $debug_stmt = $pdo->prepare("SELECT id, username, referrer_id FROM users WHERE referrer_id = ?");
    $debug_stmt->execute([$user_id]);
    $actual_referrals = $debug_stmt->fetchAll();
    
    if (count($actual_referrals) > 0) {
        $referral_stats['referral_count'] = count($actual_referrals);
        $hierarchical_stats = [
            ['level' => 1, 'referral_count' => count($actual_referrals), 'total_commission' => 0]
        ];
    }
}

// Pending deposits
$deposit_stmt = $pdo->prepare("SELECT COUNT(*) AS pending_deposits FROM deposits WHERE user_id = ? AND status = 'pending'");
$deposit_stmt->execute([$user_id]);
$pending_deposits = $deposit_stmt->fetch()['pending_deposits'] ?? 0;

// User data
$balance = $user['balance'];
$invitation_key = $user['invitation_key'];
$username = $user['username'];
$daily_earning_limit = $user['daily_earning_limit'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo trans('dashboard'); ?> - TikTok Shop</title>
<link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<header>
    <div class="header-content">
        <div class="logo-container">
            <img src="assets/images/logo/tiktoklogo.png" alt="TikTask Hub Logo" class="logo">
            <span>TIKTOK SHOP</span>
        </div>
        
        <h1><?php echo trans('welcome', ['name' => htmlspecialchars($username)]); ?></h1>
        
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
            <a href="profile.php"><?php echo trans('profile'); ?></a>
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

<div class="container">
    <!-- Task Completion Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="stat-number"><?php echo $completed_today; ?> / 40</div>
            <div class="stat-label"><?php echo trans('tasks_completed_today', ['completed' => $completed_today]); ?></div>
        </div>
      
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="stat-number">$<?php echo format_balance($balance); ?></div>
            <div class="stat-label"><?php echo trans('your_balance', ['balance' => format_balance($balance)]); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number"><?php echo $referral_stats['referral_count']; ?></div>
            <div class="stat-label"><?php echo trans('direct_referrals', ['count' => $referral_stats['referral_count']]); ?></div>
        </div>
    </div>

    <!-- Progress Bar for Today's Tasks -->
    <div class="card">
        <h3><?php echo trans('today_progress'); ?></h3>
        <p><?php echo trans('completed_today', ['completed' => $completed_today, 'limit' => 40]); ?></p>
        <p><strong>Current Task:</strong> #<?php echo $current_task + 1; ?> of 40</p>
         <div class="progress-container">
            <div class="progress-bar" style="width: <?php echo min(100, ($completed_today / 40) * 100); ?>%;">
                <?php echo round(($completed_today / 40) * 100); ?>%
            </div>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="card">
            <h3><?php echo trans('referral_program'); ?></h3>
            <p><?php echo trans('invitation_key'); ?></p>
            <div class="invitation-link">
                <input type="text" value="<?php echo htmlspecialchars($invitation_key); ?>" readonly>
                <button onclick="copyInvitationKey()"><?php echo trans('copy'); ?></button>
            </div>
            <p><strong><?php echo trans('direct_referrals', ['count' => $referral_stats['referral_count']]); ?></strong></p>
            <p><?php echo trans('total_commission', ['commission' => format_balance($referral_stats['total_commission'])]); ?></p>
            
            <h4><?php echo trans('hierarchical_referrals'); ?></h4>
            <div class="referral-table">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo trans('level'); ?></th>
                            <th><?php echo trans('referrals'); ?></th>
                            <th><?php echo trans('commission'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($hierarchical_stats)): ?>
                        <tr>
                            <td colspan="3" class="no-data"><?php echo trans('no_referrals'); ?></td>
                        </tr>
                        <?php else: foreach($hierarchical_stats as $stat): ?>
                        <tr>
                            <td><?php echo trans('level'); ?> <?php echo $stat['level']; ?></td>
                            <td><?php echo $stat['referral_count']; ?></td>
                            <td>$<?php echo format_balance($stat['total_commission']); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3><?php echo trans('task_statistics'); ?></h3>
            <div class="stats-list">
                <div class="stat-item">
                    <span class="stat-label"><?php echo trans('today_completed'); ?></span>
                    <span class="stat-value"><?php echo $completed_today; ?>/40</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?php echo trans('earned_today'); ?></span>
                    <span class="stat-value">$<?php echo format_balance($earned_today); ?>/</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?php echo trans('all_time_completed'); ?></span>
                    <span class="stat-value"><?php echo $total_completed; ?> tasks</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?php echo trans('daily_earning_limit'); ?></span>
                </div>
            </div>
        </div>

        <div class="card">
            <h3><?php echo trans('deposits'); ?></h3>
            <?php if($pending_deposits > 0): ?>
            <div class="deposit-status pending">
                <i class="fas fa-clock"></i>
                <p><?php echo trans('pending_deposits', ['count' => $pending_deposits]); ?></p>
                <a href="deposit.php" class="btn btn-secondary"><?php echo trans('view_deposits'); ?></a>
            </div>
            <?php else: ?>
            <div class="deposit-status clear">
                <i class="fas fa-check-circle"></i>
                <p><?php echo trans('no_pending_deposits'); ?></p>
                <a href="deposit.php" class="btn"><?php echo trans('make_deposit'); ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<footer>
    <p>&copy; <?php echo date('Y'); ?> <?php echo trans('footer', ['year' => date('Y')]); ?></p>
</footer>
<script src="assets/js/menu.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
<script>
function copyInvitationKey() {
    const input = document.querySelector('.invitation-link input');
    input.select();
    document.execCommand('copy');
    alert('<?php echo trans('invitation_key_copied'); ?>');
}

function changeLanguage(lang) {
    window.location.href = 'dashboard.php?lang=' + lang;
}
</script>
</body>
</html>