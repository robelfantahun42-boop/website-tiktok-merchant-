<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection placeholder
// $pdo should already be defined in init.php

// =====================
// CSRF Protection
// =====================
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// =====================
// User Authentication & Authorization
// =====================
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        if (!is_logged_in()) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'main_admin', 'sub_admin']);
    }
}

if (!function_exists('require_admin')) {
    function require_admin() {
        if (!is_logged_in() || !is_admin()) {
            header('Location: ../index.php');
            exit;
        }
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return $_SESSION['user_id'] ?? null;
    }
}

// =====================
// User Helpers
// =====================
if (!function_exists('get_user_balance')) {
    function get_user_balance($user_id) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn() ?? 0;
    }
}

if (!function_exists('format_balance')) {
    function format_balance($amount) {
        return number_format((float)$amount, 2, '.', '');
    }
}

// =====================
// Commission Calculation
// =====================
if (!function_exists('calc_commission')) {
    function calc_commission($amount, $percentage) {
        return round($amount * ($percentage / 100), 2);
    }
}

// =====================
// Invitation Key Generator
// =====================
if (!function_exists('generate_invitation_key')) {
    function generate_invitation_key($length = 12) {
        return bin2hex(random_bytes($length / 2));
    }
}

// =====================
// Notifications
// =====================
if (!function_exists('send_notification')) {
    function send_notification($user_id, $message) {
        // Example: insert into notifications table
        // global $pdo;
        // $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        // $stmt->execute([$user_id, $message]);
    }
}

// =====================
// File Upload Validation
// =====================
if (!function_exists('validate_image')) {
    function validate_image($file) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 2 * 1024 * 1024; // 2MB

        if (!in_array($file['type'], $allowed_types)) {
            return "Invalid file type. Only JPG, PNG, and PDF allowed.";
        }

        if ($file['size'] > $max_size) {
            return "File size exceeds 2MB limit.";
        }

        return true;
    }
}

// =====================
// Extra Admin Helper (for admin-only includes)
// =====================
if (!function_exists('require_admin_auth')) {
    function require_admin_auth() {
        if (!is_logged_in() || !is_admin()) {
            header('Location: ../index.php');
            exit;
        }
    }
}
?>
