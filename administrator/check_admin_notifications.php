<?php
require_once 'admin_auth.php';

header('Content-Type: application/json');

// Get unread notifications count
$unread_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM admin_notifications WHERE is_read = FALSE");
$unread_stmt->execute();
$unread_count = $unread_stmt->fetch()['count'];

echo json_encode(['unread_count' => $unread_count]);
?>