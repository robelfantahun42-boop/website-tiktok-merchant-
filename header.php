<?php
// header.php - Common header for all pages
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : trans('dashboard'); ?> - Task Website</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo-container">
                <img src="assets/images/logo/tiktoklogo.png" alt="TikTask Hub Logo" class="logo">
                <span class="logo-text">TIKTOK SHOP</span>
            </div>
            
            <div class="header-middle">
                <?php if (isset($username)): ?>
                <h1 class="welcome-text"><?php echo trans('welcome', ['name' => htmlspecialchars($username)]); ?></h1>
                <?php endif; ?>
            </div>

            <div class="header-right">
                <!-- Language Selector -->
                <div class="language-selector">
                    <select onchange="changeLanguage(this.value)">
                        <option value="en" <?php echo $current_language == 'en' ? 'selected' : ''; ?>>English</option>
                        <option value="es" <?php echo $current_language == 'es' ? 'selected' : ''; ?>>Español</option>
                        <option value="ur" <?php echo $current_language == 'ur' ? 'selected' : ''; ?>>اردو</option>
                        <option value="ru" <?php echo $current_language == 'ru' ? 'selected' : ''; ?>>Русский</option>
                    </select>
                </div>
                
                <!-- Hamburger Menu Button -->
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>

        <!-- Navigation Menu -->
        <nav id="mainNav" class="main-navigation">
            <div class="nav-container">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <?php echo trans('dashboard'); ?>
                </a>
                <a href="tasks_list.php" class="nav-link">
                    <i class="fas fa-tasks"></i>
                    <?php echo trans('tasks'); ?>
                </a>
                <a href="upload_payment.php" class="nav-link">
                    <i class="fas fa-upload"></i>
                    <?php echo trans('upload_proof'); ?>
                </a>
                <a href="profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    <?php echo trans('profile'); ?>
                </a>
                <a href="logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <?php echo trans('logout'); ?>
                </a>
            </div>
        </nav>

        <!-- Mobile Navigation Overlay -->
        <div class="nav-overlay" id="navOverlay"></div>
    </header>