<?php
// =====================================
// Admin Authentication Bootstrap
// =====================================

// Ensure DB connection and session
require_once __DIR__ . '/init.php';

// Load shared helpers (auth, csrf, etc.)
require_once __DIR__ . '/functions.php';

// -------------------------------------
// Enforce admin login and role check
// -------------------------------------
function require_admin() {
    // Redirect to login if not logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: ../admin/index.php");
        exit;
    }

    // Normalize role for safe comparison
    $role = strtolower(trim($_SESSION['role']));

    // Allow both underscore and space versions of admin roles
    $allowed_roles = [
        'main admin', 'main_admin', 'main-admin',
        'sub admin', 'sub_admin', 'sub-admin',
        'admin'  // Also allow generic 'admin' role
    ];

    if (!in_array($role, $allowed_roles)) {
        // Redirect to dashboard if user is not allowed
        header("Location: ../admin/dashboard.php");
        exit;
    }
}

// Optional: automatically enforce on all admin pages
// Uncomment the line below if you want every page including this to require admin
// require_admin();