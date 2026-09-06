<?php
session_start();
require_once 'includes/init.php';
require_once 'includes/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TikTok  Shop - Complete Orders & Earn Rewards</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/index.css">
</head>
<body>


<!-- Background video -->
<video autoplay muted loop class="video-bg">
    <source src="assets/video/video1.mp4" type="video/mp4">
    Your browser does not support the video tag.
</video>

<div class="overlay"></div>

<div class="container">
<header>
<div class="logo-container"><img src="assets/images/logo/tiktoklogo.png" alt="TikTask Hub Logo" class="logo"><span style="font-weight: bold;">TIKTOK SHOP</span>

</div>
  
    <div class="menu-toggle" id="mobile-menu">
        <span></span>
        <span></span>
        <span></span>
    </div>
    <nav id="main-nav">
        <div class="close-menu" id="close-menu"><i class="fas fa-times"></i></div>
        <?php if (is_logged_in()): ?>
            <a href="dashboard.php">Home</a>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="signup.php">Sign Up</a>
        <?php endif; ?>
    </nav>
</header>

<main>
    <section class="hero">
        <h2>Do Simple Actions. Earn Real Rewards. Level Up Your Life.</h2>
        <p>Join a global community turning everyday actions into real income.Start growing your rewards today!</p>
        <?php if (!is_logged_in()): ?>
            <div class="cta-buttons">
                <a href="signup.php" class="btn btn-primary">Get Started</a>
                <a href="login.php" class="btn btn-secondary">Login</a>
            </div>
        <?php else: ?>
            <div class="cta-buttons">
                <a href="dashboard.php" class="btn btn-primary">Go to Home</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="features">
        <div class="feature-box">
            <h3>Simple Orders</h3>
            <p>Complete easy orders in minutes and earn points instantly.</p>
        </div>
        <div class="feature-box">
            <h3>Referral Rewards</h3>
            <p>Invite friends and earn additional commissions from their activity.</p>
        </div>
        <div class="feature-box">
            <h3>Fast Payouts</h3>
            <p>Withdraw your earnings quickly via multiple payout methods.</p>
        </div>
    </section>

    <section class="stats">
        <div class="stat">
            <h3>10K+</h3>
            <p>Active Users</p>
        </div>
        <div class="stat">
            <h3>$50K+</h3>
            <p>Paid Rewards</p>
        </div>
        <div class="stat">
            <h3>5K+</h3>
            <p>Orders Completed</p>
        </div>
    </section>

    <section class="testimonials">
        <div class="testimonial">
            <p>"TikTok Shop helped me earn extra income every week! Highly recommended."</p>
            <h4>- Jane D.</h4>
        </div>
        <div class="testimonial">
            <p>"The referral system is amazing. I got my friends onboard and earned more rewards."</p>
            <h4>- Mark T.</h4>
        </div>
    </section>

    <section class="cta-section">
        <h2>Start Earning Today!</h2>
        <a href="signup.php">Join Now</a>
    </section>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> TikTok Shop. All rights reserved.</p>
    <p>
        <a href="privacy.php">Privacy Policy</a> | 
        <a href="terms.php">Terms of Service</a>
    </p>
</footer>

<!-- Scroll to top button -->
<div class="scroll-to-top" id="scrollToTop">
    <i class="fas fa-chevron-up"></i>
</div>
</div>

<script>
// Mobile menu functionality
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenu = document.getElementById('mobile-menu');
    const closeMenu = document.getElementById('close-menu');
    const mainNav = document.getElementById('main-nav');
    const scrollToTop = document.getElementById('scrollToTop');

    mobileMenu.addEventListener('click', () => {
        mainNav.classList.add('active');
        mobileMenu.classList.add('active');
        document.body.style.overflow = 'hidden';
    });

    closeMenu.addEventListener('click', () => {
        mainNav.classList.remove('active');
        mobileMenu.classList.remove('active');
        document.body.style.overflow = 'auto';
    });

    document.querySelectorAll('#main-nav a').forEach(link => {
        link.addEventListener('click', () => {
            mainNav.classList.remove('active');
            mobileMenu.classList.remove('active');
            document.body.style.overflow = 'auto';
        });
    });

    // Scroll to top functionality
    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 300) {
            scrollToTop.classList.add('visible');
        } else {
            scrollToTop.classList.remove('visible');
        }
    });

    scrollToTop.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
});
</script>

</body>
</html>