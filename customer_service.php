<?php
require_once 'includes/init.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

require_login();

$user_id = get_current_user_id();
$errors = [];
$success = '';

// Get user info
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Get FAQ categories and questions
$faq_categories = $pdo->query("SELECT * FROM faq_categories WHERE is_active = 1 ORDER BY display_order")->fetchAll();
$faqs = [];
foreach ($faq_categories as $category) {
    $stmt = $pdo->prepare("SELECT * FROM faqs WHERE category_id = ? AND is_active = 1 ORDER BY display_order");
    $stmt->execute([$category['id']]);
    $faqs[$category['id']] = $stmt->fetchAll();
}

// Get automated responses from database
$automated_responses = $pdo->query("SELECT keyword, response FROM automated_responses WHERE is_active = 1")->fetchAll();

// Handle new ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    
    // Validate inputs
    if (empty($subject)) {
        $errors[] = "Subject is required.";
    }
    
    if (empty($message)) {
        $errors[] = "Message is required.";
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Check for automated response keywords
            $auto_response = null;
            $is_auto_response = false;
            $matched_keyword = '';
            $matched_response = '';
            
            foreach ($automated_responses as $response) {
                if (stripos($message, $response['keyword']) !== false) {
                    $auto_response = $response['response'];
                    $matched_keyword = $response['keyword'];
                    $matched_response = $response['response'];
                    $is_auto_response = true;
                    break;
                }
            }
            
            if ($is_auto_response) {
                // Create support ticket with automated response status
                $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, category_id, subject, message, status, is_auto_response, auto_response_keyword) VALUES (?, ?, ?, ?, 'auto_responded', 1, ?)");
                $stmt->execute([$user_id, $category_id, $subject, $message, $matched_keyword]);
                $ticket_id = $pdo->lastInsertId();
                
                // Add automated response as a reply
                $auto_reply_message = "Automated Response for keyword: \"{$matched_keyword}\"\n\n{$auto_response}";
                $stmt = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin_reply, is_auto_response) VALUES (?, ?, ?, 1, 1)");
                $stmt->execute([$ticket_id, 0, $auto_reply_message]);
                
                // Update ticket status to closed if it's a complete automated response
                $stmt = $pdo->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ?");
                $stmt->execute([$ticket_id]);
                
                $success = "
                <div class='alert alert-success'>
                    <h5><i class='fas fa-robot me-2'></i>Instant Response Received!</h5>
                    <p>Your query has been automatically processed based on the keyword \"<strong>{$matched_keyword}</strong>\".</p>
                    <div class='response-content mt-3'>
                        <strong>Automated Response:</strong>
                        <div class='response-text'>" . nl2br(htmlspecialchars($matched_response)) . "</div>
                    </div>
                    <p class='mt-3 mb-0'><small><i class='fas fa-info-circle me-1'></i>If this doesn't answer your question, please submit a new ticket with more details.</small></p>
                </div>";
                
            } else {
                // Create regular support ticket for admin review
                $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, category_id, subject, message, status, is_auto_response) VALUES (?, ?, ?, ?, 'open', 0)");
                $stmt->execute([$user_id, $category_id, $subject, $message]);
                $ticket_id = $pdo->lastInsertId();
                
                // Notify admin about new ticket
                $admin_message = "New support ticket #{$ticket_id} from user: {$user['username']} - {$subject}";
                $stmt = $pdo->prepare("INSERT INTO admin_notifications (type, title, message, reference_id, is_read) VALUES ('support_ticket', 'New Support Ticket', ?, ?, 0)");
                $stmt->execute([$admin_message, $ticket_id]);
                
                $success = "
                <div class='alert alert-success'>
                    <h5><i class='fas fa-check-circle me-2'></i>Ticket Submitted Successfully!</h5>
                    <p>Your support ticket has been submitted successfully. We'll get back to you soon.</p>
                    <p class='mb-0'><strong>Ticket ID:</strong> #{$ticket_id}</p>
                </div>";
            }
            
            $pdo->commit();
            
            // Reset form if it was an automated response
            if ($is_auto_response) {
                $subject = $message = '';
                $category_id = 0;
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Failed to submit your ticket. Please try again.";
        }
    }
}

// Get user's previous tickets
$user_tickets = $pdo->prepare("SELECT st.*, sc.name as category_name, st.created_at as ticket_date 
                              FROM support_tickets st 
                              LEFT JOIN support_categories sc ON st.category_id = sc.id 
                              WHERE st.user_id = ? 
                              ORDER BY st.created_at DESC 
                              LIMIT 10");
$user_tickets->execute([$user_id]);
$tickets = $user_tickets->fetchAll();

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
    <title>Customer Service - Task Website</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Roboto', sans-serif;
        }

        body {
            background: #040404;
            color: #ffffff;
            line-height: 1.6;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* Header Styles */
        header {
            background: linear-gradient(135deg, #040404 0%, #0a0a0a 100%);
            color: #fff;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(254, 40, 88, 0.3);
            position: relative;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            padding: 0;
            z-index: 1001;
        }

        .logo {
            height: 35px;
            width: auto;
            display: block;
            margin: 0;
            padding: 0;
        }

        .logo-container span {
            font-size: 1.5rem;
            font-weight: bold;
            color: #fe2858;
            margin: 0;
            padding: 0;
            line-height: 1;
            text-shadow: 0 0 10px rgba(254, 40, 88, 0.5);
        }

        header h1 {
            font-size: 1.8rem;
            color: #2af0ea;
            text-align: center;
            flex: 1;
            margin: 0;
        }

        /* Navigation */
        nav {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            transition: all 0.3s ease;
        }

        nav a {
            color: #ffffff;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            font-weight: 500;
        }

        nav a:hover {
            color: #fe2858;
            background: rgba(254, 40, 88, 0.1);
            border-color: rgba(254, 40, 88, 0.3);
            transform: translateY(-2px);
        }

        /* Language Selector */
        .language-selector {
            z-index: 1001;
        }

        .language-selector select {
            padding: 0.5rem;
            border-radius: 5px;
            border: 1px solid #2af0ea;
            background: rgba(4, 4, 4, 0.8);
            color: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .language-selector select:focus {
            outline: none;
            border-color: #fe2858;
            box-shadow: 0 0 10px rgba(254, 40, 88, 0.3);
        }

        /* Hamburger Menu */
        .menu-toggle {
            display: none;
            flex-direction: column;
            justify-content: space-between;
            width: 30px;
            height: 21px;
            cursor: pointer;
            z-index: 1001;
            background: transparent;
            border: none;
            padding: 0;
        }

        .menu-toggle span {
            height: 3px;
            width: 100%;
            background-color: #ffffff;
            border-radius: 3px;
            transition: all 0.3s ease;
            transform-origin: center;
        }

        .menu-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(6px, 6px);
            background-color: #fe2858;
        }

        .menu-toggle.active span:nth-child(2) {
            opacity: 0;
            transform: scale(0);
        }

        .menu-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(6px, -6px);
            background-color: #fe2858;
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        /* Cards */
        .card {
            background: rgba(4, 4, 4, 0.8);
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(42, 240, 234, 0.1);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }

        .card:hover {
            border-color: rgba(42, 240, 234, 0.3);
            box-shadow: 0 8px 25px rgba(42, 240, 234, 0.1);
        }

        .card h3 {
            color: #fe2858;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }

        .card h4 {
            color: #2af0ea;
            margin: 1rem 0 0.5rem 0;
            font-size: 1.1rem;
        }

        .card h5 {
            color: #2af0ea;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        /* FAQ Styles */
        .faq-category {
            margin-bottom: 2rem;
        }

        .faq-category h4 {
            color: #2af0ea;
            font-size: 1.3rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #fe2858;
        }

        .faq-item {
            margin-bottom: 1rem;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .faq-question {
            background: linear-gradient(135deg, rgba(57, 118, 132, 0.1), rgba(4, 4, 4, 0.9));
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 8px;
            font-weight: 600;
            color: #ffffff;
            display: flex;
            align-items: center;
            border: 1px solid rgba(42, 240, 234, 0.2);
        }

        .faq-question:hover {
            background: linear-gradient(135deg, rgba(254, 40, 88, 0.1), rgba(4, 4, 4, 0.9));
            border-color: rgba(254, 40, 88, 0.3);
        }

        .faq-answer {
            padding: 1.5rem;
            background: rgba(4, 4, 4, 0.9);
            border-top: 1px solid rgba(42, 240, 234, 0.1);
            display: none;
            line-height: 1.6;
            color: #e0e0e0;
            border-left: 3px solid #fe2858;
        }

        /* Form Styles */
        .form-label {
            font-weight: 600;
            color: #2af0ea;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .form-control {
            border: 2px solid rgba(42, 240, 234, 0.2);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: rgba(4, 4, 4, 0.6);
            color: #ffffff;
        }

        .form-control:focus {
            border-color: #fe2858;
            box-shadow: 0 0 0 0.2rem rgba(254, 40, 88, 0.25);
            background: rgba(4, 4, 4, 0.8);
            color: #ffffff;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #fe2858, #de8c9d);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #de8c9d, #fe2858);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(254, 40, 88, 0.3);
        }

        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 5px solid;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(42, 240, 234, 0.1), rgba(4, 4, 4, 0.9));
            border-left-color: #2af0ea;
            color: #e0e0e0;
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(254, 40, 88, 0.1), rgba(4, 4, 4, 0.9));
            border-left-color: #fe2858;
            color: #e0e0e0;
        }

        /* Automated Response Styles */
        .automated-response {
            background: linear-gradient(135deg, rgba(57, 118, 132, 0.1), rgba(4, 4, 4, 0.9));
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 5px solid #2af0ea;
        }

        .keyword-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .keyword-badge {
            background: linear-gradient(135deg, #fe2858, #de8c9d);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .keyword-hint {
            font-size: 0.9rem;
            color: #e0e0e0;
            font-style: italic;
        }

        .keyword-detected {
            color: #2af0ea;
            font-weight: 600;
        }

        /* Response Preview Styles */
        .response-preview {
            background: linear-gradient(135deg, rgba(57, 118, 132, 0.1), rgba(4, 4, 4, 0.9));
            border: 2px dashed #fe2858;
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            display: none;
        }

        .response-preview.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        .response-preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(254, 40, 88, 0.3);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .response-preview-header i {
            color: #fe2858;
            font-size: 1.2rem;
        }

        .response-preview-header span:first-of-type {
            font-weight: 600;
            color: #2af0ea;
            font-size: 1.1rem;
        }

        .auto-response-indicator {
            display: flex;
            align-items: center;
            font-size: 0.875rem;
            color: #fe2858;
        }

        .typing-indicator {
            display: inline-flex;
            margin-right: 0.5rem;
        }

        .typing-dot {
            width: 4px;
            height: 4px;
            background: #fe2858;
            border-radius: 50%;
            margin: 0 1px;
            animation: typing 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }

        .response-content {
            background: rgba(4, 4, 4, 0.6);
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #fe2858;
            line-height: 1.6;
            color: #e0e0e0;
        }

        .response-text {
            background: rgba(4, 4, 4, 0.6);
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #2af0ea;
            line-height: 1.6;
            color: #e0e0e0;
            margin-top: 0.5rem;
        }

        /* Ticket Styles */
        .ticket {
            background: rgba(4, 4, 4, 0.6);
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border-left: 4px solid #fe2858;
        }

        .ticket h5 {
            color: #2af0ea;
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
        }

        .ticket-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open { background: rgba(42, 240, 234, 0.2); color: #2af0ea; border: 1px solid rgba(42, 240, 234, 0.3); }
        .status-closed { background: rgba(254, 40, 88, 0.2); color: #fe2858; border: 1px solid rgba(254, 40, 88, 0.3); }
        .status-auto_responded { background: rgba(255, 193, 7, 0.2); color: #ffc107; border: 1px solid rgba(255, 193, 7, 0.3); }

        .auto-response-indicator-small {
            background: rgba(57, 118, 132, 0.2);
            color: #2af0ea;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
            border: 1px solid rgba(42, 240, 234, 0.3);
        }

        /* Copy Feedback */
        .copy-feedback {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2af0ea;
            color: #040404;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            box-shadow: 0 4px 12px rgba(42, 240, 234, 0.3);
            z-index: 1000;
            transform: translateX(150%);
            transition: transform 0.3s ease;
            font-weight: 600;
        }

        .copy-feedback.show {
            transform: translateX(0);
        }

        /* Response Line Styles - Responsive */
        .response-line {
            margin-bottom: 0.5rem;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .response-line.with-colon {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .colon-prefix {
            font-weight: bold;
            flex-shrink: 0;
            color: #2af0ea;
            font-size: 0.9rem;
        }

        .colon-content {
            flex: 1;
            color: #e0e0e0;
            font-size: 0.85rem;
            word-break: break-word;
            min-width: 0;
        }

        .copy-colon-btn {
            background: transparent;
            border: 1px solid #fe2858;
            color: #fe2858;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
            flex-shrink: 0;
            cursor: pointer;
            white-space: nowrap;
        }

        .copy-colon-btn:hover {
            background: #fe2858;
            color: #ffffff;
        }

        .copy-colon-btn.copied {
            background: #2af0ea;
            border-color: #2af0ea;
            color: #040404;
        }

        /* Animations */
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mobile Navigation */
        @media (max-width: 768px) {
            .menu-toggle {
                display: flex;
            }

            nav {
                position: fixed;
                top: 0;
                right: -100%;
                width: 280px;
                height: 100vh;
                background: linear-gradient(135deg, #040404 0%, #0a0a0a 100%);
                flex-direction: column;
                align-items: flex-start;
                justify-content: flex-start;
                padding-top: 80px;
                padding-left: 2rem;
                z-index: 1000;
                border-left: 1px solid rgba(254, 40, 88, 0.3);
                box-shadow: -5px 0 15px rgba(0, 0, 0, 0.5);
                transition: right 0.3s ease;
                gap: 0;
            }

            nav.active {
                right: 0;
            }

            nav a {
                display: block;
                width: calc(100% - 2rem);
                padding: 1rem;
                margin: 0.5rem 0;
                border-radius: 8px;
                border: 1px solid rgba(42, 240, 234, 0.2);
                background: rgba(4, 4, 4, 0.6);
                transition: all 0.3s ease;
                font-size: 1rem;
            }

            nav a:hover {
                background: rgba(254, 40, 88, 0.2);
                border-color: rgba(254, 40, 88, 0.4);
                transform: translateX(5px);
            }

            .language-selector {
                position: absolute;
                top: 1rem;
                right: 4rem;
            }

            /* Overlay when menu is open */
            .nav-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                z-index: 999;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .nav-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            /* Header adjustments for mobile */
            .header-content {
                padding: 0 0.5rem;
            }

            header h1 {
                font-size: 1.4rem;
                margin-left: 0.5rem;
            }

            .logo-container span {
                font-size: 1.3rem;
            }

            .logo {
                height: 30px;
            }

            .container {
                padding: 1rem 0.5rem;
            }

            .card {
                padding: 1rem;
            }

            .response-preview-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .keyword-list {
                justify-content: center;
            }

            .copy-feedback {
                top: 10px;
                right: 10px;
                left: 10px;
                text-align: center;
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }

            /* Mobile responsive text sizes */
            .response-line {
                font-size: 0.9rem;
            }

            .colon-prefix {
                font-size: 0.85rem;
            }

            .colon-content {
                font-size: 0.8rem;
            }

            .copy-colon-btn {
                font-size: 0.65rem;
                padding: 0.2rem 0.4rem;
            }
        }

        /* Small mobile devices */
        @media (max-width: 480px) {
            .header-content {
                flex-wrap: nowrap;
            }

            header h1 {
                font-size: 1.2rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 150px;
            }

            .logo-container span {
                font-size: 1.1rem;
            }

            .logo {
                height: 25px;
            }

            .language-selector {
                right: 3.5rem;
            }

            .language-selector select {
                padding: 0.4rem;
                font-size: 0.8rem;
            }

            nav {
                width: 250px;
            }

            .container {
                padding: 1rem 0.5rem;
            }

            .card {
                padding: 0.8rem;
            }

            /* Extra small screen text adjustments */
            .response-line {
                font-size: 0.85rem;
            }

            .colon-prefix {
                font-size: 0.8rem;
            }

            .colon-content {
                font-size: 0.75rem;
            }

            .response-line.with-colon {
                gap: 0.3rem;
            }

            .copy-colon-btn {
                font-size: 0.6rem;
                padding: 0.15rem 0.3rem;
            }
        }

        /* Very small mobile devices */
        @media (max-width: 360px) {
            .response-line.with-colon {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }

            .colon-prefix {
                width: 100%;
            }

            .colon-content {
                width: 100%;
            }

            .copy-colon-btn {
                align-self: flex-end;
                margin-top: 0.25rem;
            }
        }

        /* Body lock when menu is open */
        body.menu-open {
            overflow: hidden;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo-container">
                <span>TaskSite</span>
            </div>
            <h1>Customer Service</h1>
            <button class="menu-toggle" id="menuToggle">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <nav id="mainNav">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
                <a href="tasks_list.php"><i class="fas fa-tasks me-1"></i>Tasks</a>
                <a href="upload_payment.php"><i class="fas fa-upload me-1"></i>Upload Proof</a>
                <a href="customer_service.php"><i class="fas fa-headset me-1"></i>Customer Service</a>
                <a href="profile.php"><i class="fas fa-user me-1"></i>Profile</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt me-1"></i>Logout</a>
            </nav>
            <div class="nav-overlay" id="navOverlay"></div>
        </div>
    </header>

    <div class="container">
        <div class="row">
            <div class="col-12 col-lg-8">
                <!-- FAQ Section -->
                <div class="card">
                    <h3><i class="fas fa-question-circle me-2"></i>Frequently Asked Questions</h3>
                    <div class="card-body">
                        <?php foreach ($faq_categories as $category): ?>
                            <div class="faq-category">
                                <h4><?php echo htmlspecialchars($category['name']); ?></h4>
                                <?php if (isset($faqs[$category['id']]) && !empty($faqs[$category['id']])): ?>
                                    <?php foreach ($faqs[$category['id']] as $faq): ?>
                                        <div class="faq-item">
                                            <div class="faq-question" onclick="toggleFAQ(this)">
                                                <i class="fas fa-question me-2"></i>
                                                <?php echo htmlspecialchars($faq['question']); ?>
                                            </div>
                                            <div class="faq-answer">
                                                <?php echo nl2br(htmlspecialchars($faq['answer'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">No questions available in this category.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Support Ticket Form -->
                <div class="card">
                    <h3><i class="fas fa-headset me-2"></i>Contact Support</h3>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <?php foreach ($errors as $error): ?>
                                    <p class="mb-1"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <?php echo $success; ?>
                        <?php endif; ?>

                        <!-- Automated Responses Info -->
                        <?php if (!empty($automated_responses)): ?>
                            <div class="automated-response">
                                <h5><i class="fas fa-robot me-2"></i>Instant Automated Responses</h5>
                                <p class="mb-2">Get instant answers for common questions. Include these keywords in your message:</p>
                                <div class="keyword-list">
                                    <?php foreach ($automated_responses as $response): ?>
                                        <span class="keyword-badge"><?php echo htmlspecialchars($response['keyword']); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <p class="keyword-hint mb-0">Type any of the keywords above in your message to get an instant automated response!</p>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="supportForm">
                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject *</label>
                                <input type="text" class="form-control" id="subject" name="subject" 
                                       value="<?php echo isset($subject) ? htmlspecialchars($subject) : ''; ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-control" id="category_id" name="category_id">
                                    <option value="0">General Inquiry</option>
                                    <?php 
                                    $support_categories = $pdo->query("SELECT * FROM support_categories WHERE is_active = 1 ORDER BY name")->fetchAll();
                                    foreach ($support_categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo (isset($category_id) && $category_id == $cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="message" class="form-label">Message *</label>
                                <textarea class="form-control" id="message" name="message" rows="5" required><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                                <div class="keyword-hint" id="keywordHint">
                                    <i class="fas fa-lightbulb me-1"></i>Start typing to see if we can provide an instant automated response!
                                </div>
                            </div>
                            
                            <!-- Real-time Response Preview -->
                            <div class="response-preview" id="responsePreview">
                                <div class="response-preview-header">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-robot me-2"></i>
                                        <span>Instant Response Preview</span>
                                    </div>
                                    <span class="auto-response-indicator">
                                        <span class="typing-indicator">
                                            <span class="typing-dot"></span>
                                            <span class="typing-dot"></span>
                                            <span class="typing-dot"></span>
                                        </span>
                                        Generating response...
                                    </span>
                                </div>
                                <div class="response-preview-body">
                                    <div class="response-content" id="previewContent">
                                        <!-- Response will be inserted here by JavaScript -->
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        This is an automated response. Submit your ticket to get this instant answer.
                                    </small>
                                </div>
                            </div>
                            
                            <button type="submit" name="submit_ticket" class="btn btn-primary mt-3 w-100">
                                <i class="fas fa-paper-plane me-2"></i>Submit Ticket
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <!-- Recent Tickets -->
                <div class="card">
                    <h3><i class="fas fa-ticket-alt me-2"></i>Your Recent Tickets</h3>
                    <div class="card-body">
                        <?php if (empty($tickets)): ?>
                            <p class="text-muted">You haven't submitted any support tickets yet.</p>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <div class="ticket">
                                    <h5><?php echo htmlspecialchars($ticket['subject']); ?></h5>
                                    <p class="text-muted">
                                        <small><?php echo date('M j, Y g:i A', strtotime($ticket['ticket_date'])); ?></small>
                                    </p>
                                    <p class="mb-2">
                                        Status: 
                                        <span class="ticket-status status-<?php echo $ticket['status']; ?>">
                                            <?php 
                                            $status_display = $ticket['status'];
                                            if ($status_display == 'auto_responded') {
                                                echo 'Automatically Responded';
                                            } else {
                                                echo ucfirst($status_display);
                                            }
                                            ?>
                                        </span>
                                        <?php if ($ticket['is_auto_response']): ?>
                                            <span class="auto-response-indicator-small">
                                                <i class="fas fa-robot" title="Automated Response"></i>
                                                Instant Reply
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($ticket['category_name']): ?>
                                        <p class="mb-0">
                                            <small>Category: <?php echo htmlspecialchars($ticket['category_name']); ?></small>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="card">
                    <h3><i class="fas fa-info-circle me-2"></i>Contact Information</h3>
                    <div class="card-body">
                        <p><strong>Email:</strong> support@tiktokshop.com</p>
                        <p><strong>Response Time:</strong> Usually within 24 hours</p>
                        <p><strong>Working Hours:</strong> 9:00 AM - 6:00 PM (Monday - Friday)</p>
                        <p class="mb-0"><strong>Instant Support:</strong> Automated responses available 24/7 for common queries</p>
                    </div>
                </div>

                <!-- Quick Tips -->
                <div class="card">
                    <h3><i class="fas fa-tips me-2"></i>Quick Tips</h3>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6><i class="fas fa-bolt me-2 text-warning"></i>For Instant Responses</h6>
                            <p class="small text-muted mb-2">Include keywords like: TRC20, payment, withdrawal, wallet, balance</p>
                        </div>
                        <div class="mb-3">
                            <h6><i class="fas fa-clock me-2 text-info"></i>Faster Support</h6>
                            <p class="small text-muted mb-2">Be specific in your subject line and include all relevant details</p>
                        </div>
                        <div class="mb-0">
                            <h6><i class="fas fa-history me-2 text-success"></i>Check Status</h6>
                            <p class="small text-muted mb-0">Monitor your ticket status in the Recent Tickets section</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Copy Feedback Element -->
    <div class="copy-feedback" id="copyFeedback">
        <i class="fas fa-check-circle me-2"></i>Text copied to clipboard!
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu functionality
        const menuToggle = document.getElementById('menuToggle');
        const mainNav = document.getElementById('mainNav');
        const navOverlay = document.getElementById('navOverlay');
        const body = document.body;

        menuToggle.addEventListener('click', function() {
            this.classList.toggle('active');
            mainNav.classList.toggle('active');
            navOverlay.classList.toggle('active');
            body.classList.toggle('menu-open');
        });

        navOverlay.addEventListener('click', function() {
            menuToggle.classList.remove('active');
            mainNav.classList.remove('active');
            this.classList.remove('active');
            body.classList.remove('menu-open');
        });

        // FAQ toggle function
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const isVisible = answer.style.display === 'block';
            
            answer.style.display = isVisible ? 'none' : 'block';
            
            // Toggle icon
            const icon = element.querySelector('i');
            if (isVisible) {
                icon.className = 'fas fa-question me-2';
            } else {
                icon.className = 'fas fa-chevron-down me-2';
            }
        }

        // Automated response detection
        const automatedResponses = <?php echo json_encode($automated_responses); ?>;
        const messageInput = document.getElementById('message');
        const keywordHint = document.getElementById('keywordHint');
        const responsePreview = document.getElementById('responsePreview');
        const previewContent = document.getElementById('previewContent');
        const copyFeedback = document.getElementById('copyFeedback');
        let detectionTimeout;
        let currentResponse = '';

        // Function to extract text after colon in the same line
        function extractTextAfterColon(line) {
            const colonIndex = line.indexOf(':');
            if (colonIndex !== -1) {
                return line.substring(colonIndex + 1).trim();
            }
            return line.trim();
        }

        // Function to parse response and add individual copy buttons for lines with colons
        function parseResponseWithCopyButtons(response) {
            const lines = response.split('\n');
            let html = '';
            
            lines.forEach((line, index) => {
                const trimmedLine = line.trim();
                if (trimmedLine) {
                    const colonIndex = trimmedLine.indexOf(':');
                    if (colonIndex !== -1) {
                        const prefix = trimmedLine.substring(0, colonIndex + 1);
                        const content = trimmedLine.substring(colonIndex + 1).trim();
                        const contentToCopy = extractTextAfterColon(trimmedLine);
                        
                        html += `<div class="response-line with-colon">
                            <span class="colon-prefix">${prefix}</span>
                            <span class="colon-content">${content}</span>
                            <button type="button" class="btn btn-sm copy-colon-btn" data-content="${contentToCopy.replace(/"/g, '&quot;')}">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>`;
                    } else {
                        html += `<div class="response-line">${trimmedLine}</div>`;
                    }
                }
            });
            
            return html;
        }

        // Copy text function
        function copyText(text, button = null) {
            navigator.clipboard.writeText(text).then(() => {
                // Show feedback
                copyFeedback.classList.add('show');
                setTimeout(() => {
                    copyFeedback.classList.remove('show');
                }, 3000);

                // Update button state if provided
                if (button) {
                    const originalHTML = button.innerHTML;
                    button.classList.add('copied');
                    button.innerHTML = '<i class="fas fa-check"></i>';
                    
                    setTimeout(() => {
                        button.classList.remove('copied');
                        button.innerHTML = originalHTML;
                    }, 2000);
                }
            }).catch(err => {
                console.error('Failed to copy text: ', err);
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                // Show feedback even with fallback
                copyFeedback.classList.add('show');
                setTimeout(() => {
                    copyFeedback.classList.remove('show');
                }, 3000);
            });
        }

        // Set up copy button event listeners
        document.addEventListener('click', function(e) {
            // Individual line copy (text after colon)
            if (e.target.closest('.copy-colon-btn')) {
                const button = e.target.closest('.copy-colon-btn');
                const content = button.getAttribute('data-content');
                copyText(content, button);
            }
        });

        messageInput.addEventListener('input', function() {
            const message = this.value.toLowerCase();
            
            // Clear previous timeout
            clearTimeout(detectionTimeout);
            
            // Set new timeout to avoid too frequent updates
            detectionTimeout = setTimeout(() => {
                detectKeywords(message);
            }, 500);
        });

        function detectKeywords(message) {
            let detectedKeywords = [];
            let matchedResponse = '';

            // Check for keyword matches
            automatedResponses.forEach(response => {
                if (message.includes(response.keyword.toLowerCase()) && message.length > 3) {
                    detectedKeywords.push(response.keyword);
                    if (!matchedResponse) {
                        matchedResponse = response.response;
                    }
                }
            });

            // Update UI based on detection
            if (detectedKeywords.length > 0) {
                // Show positive feedback
                keywordHint.innerHTML = `<i class="fas fa-check-circle me-1 text-success"></i>Keywords detected: <strong>${detectedKeywords.join(', ')}</strong> - You'll get an instant response!`;
                keywordHint.className = 'keyword-hint keyword-detected';
                
                // Show response preview
                showResponsePreview(matchedResponse);
            } else {
                // Show neutral hint
                if (message.length > 0) {
                    keywordHint.innerHTML = `<i class="fas fa-lightbulb me-1"></i>No keywords detected. Try including words like: ${automatedResponses.slice(0, 3).map(r => r.keyword).join(', ')}`;
                    keywordHint.className = 'keyword-hint';
                } else {
                    keywordHint.innerHTML = `<i class="fas fa-lightbulb me-1"></i>Start typing to see if we can provide an instant automated response!`;
                    keywordHint.className = 'keyword-hint';
                }
                
                // Hide response preview
                hideResponsePreview();
            }
        }

        function showResponsePreview(response) {
            currentResponse = response;
            const parsedHTML = parseResponseWithCopyButtons(response);
            previewContent.innerHTML = parsedHTML;
            responsePreview.classList.add('active');
            
            // Add slight delay for typing effect
            setTimeout(() => {
                const typingIndicator = responsePreview.querySelector('.typing-indicator');
                if (typingIndicator) {
                    typingIndicator.style.display = 'none';
                }
            }, 1000);
        }

        function hideResponsePreview() {
            responsePreview.classList.remove('active');
            const typingIndicator = responsePreview.querySelector('.typing-indicator');
            if (typingIndicator) {
                typingIndicator.style.display = 'inline-flex';
            }
        }

        // Auto-open first FAQ item on page load
        document.addEventListener('DOMContentLoaded', function() {
            const firstQuestion = document.querySelector('.faq-question');
            if (firstQuestion) {
                toggleFAQ(firstQuestion);
            }

            // Check if there's existing message content on page load
            if (messageInput.value.length > 0) {
                detectKeywords(messageInput.value.toLowerCase());
            }
        });

        // Form submission enhancement
        document.getElementById('supportForm').addEventListener('submit', function(e) {
            const message = messageInput.value.toLowerCase();
            let hasKeywords = false;

            automatedResponses.forEach(response => {
                if (message.includes(response.keyword.toLowerCase())) {
                    hasKeywords = true;
                }
            });

            if (hasKeywords) {
                // Show loading state for instant responses
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-robot me-2"></i>Processing Instant Response...';
                submitBtn.disabled = true;

                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 2000);
            }
        });
    </script>
</body>
</html>