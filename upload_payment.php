<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Redirect if not logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

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

// Get current user info for header
$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user_info = $user_stmt->fetch();
$username = $user_info['username'];

$errors = [];
$success = '';

// FIXED: Use generate_csrf_token() instead of csrf_token()
$csrf_token = generate_csrf_token();

// NEW: Check if this is a special task deposit
$is_special_task_deposit = isset($_SESSION['special_task_deposit']);
$special_task_info = $is_special_task_deposit ? $_SESSION['special_task_deposit'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    }

    $amount = floatval($_POST['amount']);
    $payment_method = trim($_POST['payment_method']);
    $transaction_id = trim($_POST['transaction_id']);

    // NEW: Validate amount for special task deposits
    if ($is_special_task_deposit && $amount != $special_task_info['required_amount']) {
        $errors[] = "For this special task, you must deposit exactly $" . format_balance($special_task_info['required_amount']) . ".";
    }

    if ($amount <= 0) $errors[] = "Amount must be greater than 0.";
    if (empty($payment_method)) $errors[] = "Payment method is required.";

    $proof_image = '';
    if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
        $result = validate_image($_FILES['proof_image']);
        if ($result === true) {
            $ext = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
            $proof_image = 'deposit_' . time() . '_' . random_str(3) . '.' . $ext;
            if (!file_exists('uploads/deposits')) mkdir('uploads/deposits', 0777, true);
            $target_path = 'uploads/deposits/' . $proof_image;
            if (!move_uploaded_file($_FILES['proof_image']['tmp_name'], $target_path)) {
                $errors[] = "Failed to upload image.";
            }
        } else {
            $errors[] = $result;
        }
    } else {
        $errors[] = "Proof image is required.";
    }

    if (empty($errors)) {
        // Insert into payments table
        $stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, payment_method, transaction_id, file_path, status, uploaded_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$_SESSION['user_id'], $amount, $payment_method, $transaction_id, $target_path]);
        $payment_id = $pdo->lastInsertId();

        // NEW: If this is a special task deposit, create special_task_deposits record
        if ($is_special_task_deposit) {
            $special_deposit_stmt = $pdo->prepare("
                INSERT INTO special_task_deposits 
                (user_id, task_position, required_amount, payment_id, status, created_at) 
                VALUES (?, ?, ?, ?, 'pending', NOW())
            ");
            $special_deposit_stmt->execute([
                $user_id,
                $special_task_info['position'],
                $special_task_info['required_amount'],
                $payment_id
            ]);
            
            // Clear the session after successful submission
            unset($_SESSION['special_task_deposit']);
        }

        // Notify admin
        $admin_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
        $admin_ids = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admin_ids as $admin_id) {
            $notify = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $notify->execute([$admin_id, "New payment submitted by user ID ".$_SESSION['user_id']]);
        }

        $success = "Deposit submitted successfully!";
        
        // NEW: Redirect back to tasks list if this was a special task deposit
        if ($is_special_task_deposit && isset($special_task_info['return_url'])) {
            header("Location: " . $special_task_info['return_url'] . "?deposit_submitted=1");
            exit;
        }
    }
}

$deposits = get_user_deposits($_SESSION['user_id'], 5);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo trans('upload_proof'); ?> - TikTok Shop</title>
<link rel="stylesheet" href="assets/css/uploadpayment.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    /* Special Task Deposit Banner */
    .special-task-banner {
        background: linear-gradient(135deg, #e67e22, #d35400);
        color: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        border: 1px solid rgba(230, 126, 34, 0.3);
        text-align: center;
    }

    .special-task-banner h3 {
        margin: 0 0 10px 0;
        font-size: 1.4rem;
    }

    .special-task-banner p {
        margin: 5px 0;
        font-size: 1rem;
    }

    .special-task-amount {
        font-size: 2rem;
        font-weight: bold;
        margin: 10px 0;
        text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
    }

    .special-task-note {
        background: rgba(255, 255, 255, 0.2);
        padding: 10px;
        border-radius: 5px;
        margin-top: 10px;
        font-size: 0.9rem;
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
                <a href="dashboard.php"><?php echo trans('Home'); ?></a>
                <a href="tasks_list.php"><?php echo trans('Orders'); ?></a>
                <a href="upload_payment.php" class="active"><?php echo trans('upload_proof'); ?></a>
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
            <h2>Deposit Funds</h2>

            <?php if(!empty($errors)): ?>
            <div class="error-message">
                <?php foreach($errors as $err): ?>
                    <p><?php echo htmlspecialchars($err); ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if($success): ?>
            <div class="success-message">
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
            <?php endif; ?>

            <!-- NEW: Special Task Deposit Banner -->
            <?php if ($is_special_task_deposit): ?>
            <div class="special-task-banner">
                <h3>🛡️ Special Task Deposit</h3>
                <p>You're depositing for <strong>Special Task #<?php echo $special_task_info['position']; ?></strong></p>
                <div class="special-task-amount">
                    $<?php echo format_balance($special_task_info['required_amount']); ?>
                </div>
                <p>Required deposit amount for this special task</p>
                <div class="special-task-note">
                    <i class="fas fa-info-circle"></i>
                    After approval, you'll be able to complete the task and earn your reward of $<?php echo format_balance($special_task_info['reward']); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3>Submit Deposit Proof</h3>
                <form method="post" enctype="multipart/form-data" class="deposit-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label for="amount">Amount:</label>
                        <input type="number" id="amount" name="amount" step="0.01" min="1" required class="form-input" 
                               placeholder="Enter deposit amount"
                               <?php if ($is_special_task_deposit): ?>
                               value="<?php echo $special_task_info['required_amount']; ?>"
                               readonly
                               <?php endif; ?>>
                        <span class="input-hint">
                            <?php if ($is_special_task_deposit): ?>
                                Fixed amount for Special Task #<?php echo $special_task_info['position']; ?>
                            <?php else: ?>
                                Minimum deposit: $1.00
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_method">Payment Method:</label>
                        <select id="payment_method" name="payment_method" required class="form-input">
                            <option value="">Select Payment Method</option>
                            <option value="Cryptocurrency">Cryptocurrency</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="transaction_id">Transaction ID (Optional):</label>
                        <input type="text" id="transaction_id" name="transaction_id" class="form-input" placeholder="Enter transaction ID if available">
                        <span class="input-hint">Provide transaction ID for faster verification</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="proof_image">Proof Image:</label>
                        <div class="file-input-wrapper">
                            <input type="file" id="proof_image" name="proof_image" accept="image/*" required>
                        </div>
                        <span class="file-hint">Accepted formats: JPG, PNG, GIF | Max size: 5MB</span>
                        
                        <!-- Image Preview Container -->
                        <div class="file-upload-preview" id="imagePreview" style="display: none;">
                            <div class="preview-container">
                                <img src="" alt="Preview" class="preview-image" id="previewImage">
                                <button type="button" class="preview-remove" onclick="removeImagePreview()">×</button>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">
                        <?php if ($is_special_task_deposit): ?>
                            <i class="fas fa-shield-alt"></i> Submit Special Task Deposit
                        <?php else: ?>
                            Submit Deposit
                        <?php endif; ?>
                    </button>

                    <?php if ($is_special_task_deposit): ?>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="tasks_list.php" class="btn btn-secondary" style="text-decoration: none; display: inline-block; padding: 10px 20px;">
                            <i class="fas fa-arrow-left"></i> Back to Tasks
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card">
                <h3>Recent Deposits</h3>
                <?php if(empty($deposits)): ?>
                    <div class="no-data">
                        <p>No deposits found.</p>
                        <p class="no-data-hint">Your deposit history will appear here</p>
                    </div>
                <?php else: ?>
                <div class="deposits-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($deposits as $d): ?>
                            <tr>
                                <td class="amount-cell">$<?php echo format_balance($d['amount']); ?></td>
                                <td class="method-cell"><?php echo htmlspecialchars($d['payment_method']); ?></td>
                                <td>
                                    <span class="status-<?php echo $d['status']; ?>">
                                        <?php echo ucfirst($d['status']); ?>
                                    </span>
                                </td>
                                <td class="date-cell"><?php echo date('M j, Y', strtotime($d['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="deposit-info">
                <h4>Deposit Guidelines</h4>
                <ul class="info-list">
                    <li>Ensure the proof image clearly shows transaction details</li>
                    <li>Include transaction ID when available for faster processing</li>
                    <li>Deposits are typically processed within 24 hours</li>
                    <li>Contact support if your deposit is not processed within 48 hours</li>
                </ul>
            </div>
        </main>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
        </footer>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Processing Deposit...</div>
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
            window.location.href = 'upload_payment.php?lang=' + lang;
        }

        // Image preview functionality
        const proofImageInput = document.getElementById('proof_image');
        const imagePreview = document.getElementById('imagePreview');
        const previewImage = document.getElementById('previewImage');

        proofImageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    imagePreview.style.display = 'block';
                }
                
                reader.readAsDataURL(file);
            } else {
                imagePreview.style.display = 'none';
            }
        });

        function removeImagePreview() {
            proofImageInput.value = '';
            imagePreview.style.display = 'none';
        }

        // Form submission loading
        const depositForm = document.querySelector('.deposit-form');
        const loadingOverlay = document.getElementById('loadingOverlay');

        if (depositForm) {
            depositForm.addEventListener('submit', function(e) {
                // Basic validation
                const amount = document.getElementById('amount').value;
                const paymentMethod = document.getElementById('payment_method').value;
                const proofImage = document.getElementById('proof_image').files[0];
                
                if (!amount || amount <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid amount.');
                    return;
                }
                
                if (!paymentMethod) {
                    e.preventDefault();
                    alert('Please select a payment method.');
                    return;
                }
                
                if (!proofImage) {
                    e.preventDefault();
                    alert('Please upload a proof image.');
                    return;
                }
                
                // Show loading overlay
                loadingOverlay.classList.add('active');
                
                // Hide loading if form submission fails
                setTimeout(() => {
                    if (!depositForm.checkValidity()) {
                        loadingOverlay.classList.remove('active');
                    }
                }, 5000);
            });
        }

        // Hide loading overlay if page is fully loaded and form wasn't submitted
        window.addEventListener('load', function() {
            loadingOverlay.classList.remove('active');
        });

        // Enhanced file input styling
        document.addEventListener('DOMContentLoaded', function() {
            const fileInputs = document.querySelectorAll('input[type="file"]');
            
            fileInputs.forEach(input => {
                input.addEventListener('dragenter', function(e) {
                    e.preventDefault();
                    this.style.borderColor = '#fe2858';
                    this.style.background = 'rgba(254, 40, 88, 0.15)';
                });
                
                input.addEventListener('dragleave', function(e) {
                    e.preventDefault();
                    this.style.borderColor = 'rgba(42, 240, 234, 0.3)';
                    this.style.background = 'rgba(4, 4, 4, 0.4)';
                });
                
                input.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.borderColor = 'rgba(42, 240, 234, 0.3)';
                    this.style.background = 'rgba(4, 4, 4, 0.4)';
                });
            });
        });
    </script>
</body>
</html>