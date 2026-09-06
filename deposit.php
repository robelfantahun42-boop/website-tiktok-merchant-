<?php
session_start();
require_once 'includes/init.php';
require_once 'includes/functions.php'; // functions including validate_image(), require_login(), etc.

require_login();

$user_id = $_SESSION['user_id'];
$errors = [];
$success = '';

// Get user's current balance
$stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$balance = $user['balance'];

// Get any pending deposits
$stmt = $pdo->prepare("SELECT COUNT(*) as pending_deposits FROM deposits WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$user_id]);
$pending_deposits = $stmt->fetch()['pending_deposits'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    $amount = (float)$_POST['amount'];
    $payment_method = trim($_POST['payment_method']);
    $transaction_id = trim($_POST['transaction_id']);

    if ($amount <= 0) $errors[] = "Deposit amount must be greater than 0.";
    if ($amount > 1000) $errors[] = "Maximum deposit amount is $1000.";
    if (empty($payment_method)) $errors[] = "Payment method is required.";

    if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "Payment proof image is required.";
    } else {
        $file = $_FILES['proof_image'];
        $validation = validate_image($file);
        if ($validation !== true) $errors[] = $validation;
    }

    if (empty($errors)) {
        if (!is_dir('uploads/deposits')) mkdir('uploads/deposits', 0755, true);

        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'deposit_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
        $file_path = 'uploads/deposits/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            $stmt = $pdo->prepare("
                INSERT INTO deposits (user_id, amount, payment_method, transaction_id, proof_image)
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($stmt->execute([$user_id, $amount, $payment_method, $transaction_id, $file_path])) {
                $success = "Deposit request submitted successfully. It will be reviewed by an admin within 24 hours.";
                $message = "User " . $_SESSION['username'] . " submitted a deposit request of $" . number_format($amount, 2);
                $admin_stmt = $pdo->query("SELECT id FROM users WHERE role IN ('main_admin','sub_admin')");
                $admins = $admin_stmt->fetchAll();
                foreach ($admins as $admin) send_notification($admin['id'], $message);
            } else {
                $errors[] = "Failed to submit deposit request. Please try again.";
                unlink($file_path);
            }
        } else {
            $errors[] = "Failed to upload proof image. Please try again.";
        }
    }
}

// Get current user info for header
$user_info_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$user_info_stmt->execute([$user_id]);
$user_info = $user_info_stmt->fetch();
$username = $user_info['username'];

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Deposit - Task Website</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Additional styles for deposit page */
        .deposit-form {
            background: rgba(4, 4, 4, 0.8);
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(42, 240, 234, 0.1);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .deposit-form:hover {
            border-color: rgba(42, 240, 234, 0.3);
            box-shadow: 0 8px 25px rgba(42, 240, 234, 0.1);
        }

        .deposit-form h2 {
            color: #fe2858;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.8rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2af0ea;
            font-weight: bold;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(42, 240, 234, 0.3);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.6);
            color: #ffffff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #fe2858;
            box-shadow: 0 0 10px rgba(254, 40, 88, 0.3);
        }

        .form-group small {
            display: block;
            margin-top: 0.5rem;
            color: #888;
            font-size: 0.8rem;
        }

        .balance-info {
            background: linear-gradient(135deg, rgba(57, 118, 132, 0.1), rgba(4, 4, 4, 0.9));
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(42, 240, 234, 0.2);
            text-align: center;
        }

        .balance-info p {
            margin: 0.5rem 0;
            color: #e0e0e0;
        }

        .balance-info strong {
            color: #2af0ea;
            font-size: 1.1rem;
        }

        .error-message {
            background: rgba(254, 40, 88, 0.1);
            border: 1px solid rgba(254, 40, 88, 0.3);
            color: #fe2858;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .success-message {
            background: rgba(42, 240, 234, 0.1);
            border: 1px solid rgba(42, 240, 234, 0.3);
            color: #2af0ea;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .btn-primary {
            width: 100%;
            padding: 1rem;
            font-size: 1.1rem;
            background: linear-gradient(135deg, #fe2858, #de8c9d);
            border: none;
            border-radius: 5px;
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: bold;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #de8c9d, #fe2858);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(254, 40, 88, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }

        .quick-action-card {
            background: linear-gradient(135deg, rgba(254, 40, 88, 0.1), rgba(4, 4, 4, 0.9));
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            border: 1px solid rgba(254, 40, 88, 0.2);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }

        .quick-action-card:hover {
            transform: translateY(-3px);
            border-color: rgba(254, 40, 88, 0.4);
            box-shadow: 0 8px 25px rgba(254, 40, 88, 0.2);
        }

        .quick-action-icon {
            font-size: 2rem;
            color: #fe2858;
            margin-bottom: 0.5rem;
        }

        .quick-action-title {
            font-size: 1.1rem;
            color: #2af0ea;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        .quick-action-desc {
            font-size: 0.9rem;
            color: #e0e0e0;
        }

        /* File input styling */
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-input-custom {
            padding: 0.75rem;
            border: 1px solid rgba(42, 240, 234, 0.3);
            border-radius: 5px;
            background: rgba(4, 4, 4, 0.6);
            color: #888;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input-custom:hover {
            border-color: #fe2858;
            color: #ffffff;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .deposit-form {
                padding: 1.5rem;
                margin: 1rem;
            }

            .quick-actions {
                grid-template-columns: 1fr;
                margin: 1rem;
            }
        }

        @media (max-width: 480px) {
            .deposit-form {
                padding: 1rem;
                margin: 0.5rem;
            }

            .deposit-form h2 {
                font-size: 1.5rem;
            }
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
            
            <h1><?php echo trans('welcome', ['name' => htmlspecialchars($username)]); ?></h1>
            
            <!-- Hamburger Menu Button -->
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <nav id="mainNav">
                <a href="dashboard.php"><?php echo trans('dashboard'); ?></a>
                <a href="tasks_list.php"><?php echo trans('tasks'); ?></a>
                <a href="upload_payment.php"><?php echo trans('upload_proof'); ?></a>
                <a href="deposit.php" class="active"><?php echo trans('deposit'); ?></a>
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

    <div class="container" id="main-content">
        <main>
            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="dashboard.php" class="quick-action-card">
                    <div class="quick-action-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="quick-action-title">Dashboard</div>
                    <div class="quick-action-desc">View your earnings and stats</div>
                </a>
                <a href="tasks_list.php" class="quick-action-card">
                    <div class="quick-action-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="quick-action-title">Tasks</div>
                    <div class="quick-action-desc">Complete tasks to earn money</div>
                </a>
                <a href="upload_payment.php" class="quick-action-card">
                    <div class="quick-action-icon">
                        <i class="fas fa-upload"></i>
                    </div>
                    <div class="quick-action-title">Upload Proof</div>
                    <div class="quick-action-desc">Submit task completion proof</div>
                </a>
            </div>

            <div class="deposit-form">
                <h2><i class="fas fa-money-bill-wave"></i> Make a Deposit</h2>
                
                <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <?php foreach ($errors as $error): ?>
                        <p><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                <div class="success-message">
                    <p><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></p>
                </div>
                <?php endif; ?>

                <div class="balance-info">
                    <p><i class="fas fa-wallet"></i> Your current balance: <strong>$<?php echo format_balance($balance); ?></strong></p>
                    <?php if ($pending_deposits > 0): ?>
                    <p><i class="fas fa-clock"></i> You have <strong><?php echo $pending_deposits; ?></strong> deposit(s) pending approval.</p>
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="form-group">
                        <label for="amount"><i class="fas fa-dollar-sign"></i> Deposit Amount ($):</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="1" max="1000" required 
                               placeholder="Enter amount between $1 - $1000">
                        <small>Minimum: $1, Maximum: $1000</small>
                    </div>

                    <div class="form-group">
                        <label for="payment_method"><i class="fas fa-credit-card"></i> Payment Method:</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="">Select Payment Method</option>
                            <option value="crypto">Cryptocurrency</option>
                           
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="transaction_id"><i class="fas fa-receipt"></i> Transaction ID (Optional):</label>
                        <input type="text" id="transaction_id" name="transaction_id" 
                               placeholder="Enter transaction reference if available">
                    </div>

                    <div class="form-group">
                        <label for="proof_image"><i class="fas fa-camera"></i> Payment Proof (Screenshot/Receipt):</label>
                        <div class="file-input-wrapper">
                            <div class="file-input-custom">
                                <i class="fas fa-cloud-upload-alt"></i> Click to upload payment proof
                            </div>
                            <input type="file" id="proof_image" name="proof_image" accept=".jpg,.jpeg,.png,.pdf" required>
                        </div>
                        <small>Upload a clear image of your payment confirmation (JPG, PNG, or PDF, max 2MB)</small>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Deposit Request
                    </button>
                </form>
            </div>

            <!-- Additional Info Card -->
            <div class="card">
                <h3><i class="fas fa-info-circle"></i> Deposit Information</h3>
                <div class="stats-list">
                    <div class="stat-item">
                        <span class="stat-label">Processing Time</span>
                        <span class="stat-value">24-48 Hours</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Minimum Deposit</span>
                        <span class="stat-value">$1.00</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Maximum Deposit</span>
                        <span class="stat-value">$1,000.00</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Supported Formats</span>
                        <span class="stat-value">JPG, PNG, PDF</span>
                    </div>
                </div>
            </div>
        </main>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Mobile menu functionality
        const menuToggle = document.getElementById('menuToggle');
        const mainNav = document.getElementById('mainNav');
        const navOverlay = document.getElementById('navOverlay');
        const body = document.body;

        if (menuToggle && mainNav && navOverlay) {
            menuToggle.addEventListener('click', function() {
                menuToggle.classList.toggle('active');
                mainNav.classList.toggle('active');
                navOverlay.classList.toggle('active');
                body.classList.toggle('menu-open');
            });

            navOverlay.addEventListener('click', function() {
                menuToggle.classList.remove('active');
                mainNav.classList.remove('active');
                navOverlay.classList.remove('active');
                body.classList.remove('menu-open');
            });

            // Close menu when clicking on nav links
            const navLinks = mainNav.querySelectorAll('a');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    menuToggle.classList.remove('active');
                    mainNav.classList.remove('active');
                    navOverlay.classList.remove('active');
                    body.classList.remove('menu-open');
                });
            });
        }

        // File input display
        const fileInput = document.getElementById('proof_image');
        const fileInputCustom = document.querySelector('.file-input-custom');

        if (fileInput && fileInputCustom) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    fileInputCustom.innerHTML = `<i class="fas fa-file"></i> ${this.files[0].name}`;
                    fileInputCustom.style.color = '#2af0ea';
                } else {
                    fileInputCustom.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Click to upload payment proof';
                    fileInputCustom.style.color = '#888';
                }
            });
        }

        // Form validation
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const amount = document.getElementById('amount');
                const paymentMethod = document.getElementById('payment_method');
                const proofImage = document.getElementById('proof_image');
                
                if (amount.value <= 0) {
                    e.preventDefault();
                    alert('Deposit amount must be greater than 0.');
                    amount.focus();
                    return false;
                }
                
                if (amount.value > 1000) {
                    e.preventDefault();
                    alert('Maximum deposit amount is $1000.');
                    amount.focus();
                    return false;
                }
                
                if (!paymentMethod.value) {
                    e.preventDefault();
                    alert('Please select a payment method.');
                    paymentMethod.focus();
                    return false;
                }
                
                if (!proofImage.files || !proofImage.files[0]) {
                    e.preventDefault();
                    alert('Please upload payment proof.');
                    return false;
                }
            });
        }

        // Language change function
        function changeLanguage(lang) {
            window.location.href = 'deposit.php?lang=' + lang;
        }
    </script>
</body>
</html>