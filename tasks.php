<?php
require_once 'includes/admin_auth.php';
require_once 'includes/functions.php';

// Handle task deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $task_id = (int)$_GET['delete'];

    if (verify_csrf_token($_GET['csrf_token'] ?? '')) {
        // Get task image path before deletion
        $stmt = $pdo->prepare("SELECT image_path FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        
        // Delete task from database
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        if ($stmt->execute([$task_id])) {
            // Delete associated image file
            if ($task && !empty($task['image_path']) && file_exists($task['image_path'])) {
                unlink($task['image_path']);
            }
            $_SESSION['success_message'] = "Task deleted successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to delete task.";
        }
    } else {
        $_SESSION['error_message'] = "Invalid CSRF token.";
    }

    header("Location: tasks.php");
    exit;
}

// Handle bulk actions
if (isset($_POST['bulk_action']) && isset($_POST['task_ids'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $task_ids = $_POST['task_ids'];
        $placeholders = str_repeat('?,', count($task_ids) - 1) . '?';
        
        switch ($_POST['bulk_action']) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE tasks SET is_active = TRUE WHERE id IN ($placeholders)");
                if ($stmt->execute($task_ids)) {
                    $_SESSION['success_message'] = "Selected tasks activated successfully.";
                }
                break;
                
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE tasks SET is_active = FALSE WHERE id IN ($placeholders)");
                if ($stmt->execute($task_ids)) {
                    $_SESSION['success_message'] = "Selected tasks deactivated successfully.";
                }
                break;
                
            case 'delete':
                // Get image paths before deletion
                $stmt = $pdo->prepare("SELECT image_path FROM tasks WHERE id IN ($placeholders)");
                $stmt->execute($task_ids);
                $tasks = $stmt->fetchAll();
                
                // Delete tasks
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id IN ($placeholders)");
                if ($stmt->execute($task_ids)) {
                    // Delete associated image files
                    foreach ($tasks as $task) {
                        if ($task['image_path'] && file_exists($task['image_path'])) {
                            unlink($task['image_path']);
                        }
                    }
                    $_SESSION['success_message'] = "Selected tasks deleted successfully.";
                }
                break;
        }
    } else {
        $_SESSION['error_message'] = "Invalid CSRF token.";
    }
    
    header("Location: tasks.php");
    exit;
}

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query with filters
$query = "
    SELECT t.*, u.username AS created_by_name,
           (SELECT COUNT(*) FROM user_progress WHERE FIND_IN_SET(t.id, REPLACE(REPLACE(REPLACE(completed_tasks, '[', ''), ']', ''), '\"', ''))) as completion_count
    FROM tasks t 
    LEFT JOIN users u ON t.created_by = u.id 
    WHERE 1=1
";

$params = [];

// Apply type filter
if ($filter_type !== 'all') {
    switch ($filter_type) {
        case 'regular':
            $query .= " AND t.is_topup_task = FALSE AND t.is_special = FALSE";
            break;
        case 'special':
            $query .= " AND t.is_special = TRUE";
            break;
        case 'topup':
            $query .= " AND t.is_topup_task = TRUE";
            break;
    }
}

// Apply status filter
if ($filter_status !== 'all') {
    $query .= " AND t.is_active = ?";
    $params[] = ($filter_status === 'active') ? 1 : 0;
}

// Apply search filter
if (!empty($search_query)) {
    $query .= " AND (t.title LIKE ? OR t.description LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
}

// Add ordering
$query .= " ORDER BY t.is_topup_task ASC, t.is_special DESC, t.created_at DESC";

// Get tasks
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback query if the main query fails
    error_log("Task query failed: " . $e->getMessage());
    $query = "
        SELECT t.*, u.username AS created_by_name, 0 as completion_count
        FROM tasks t 
        LEFT JOIN users u ON t.created_by = u.id 
        WHERE 1=1
    ";
    
    // Rebuild query without completion count
    if ($filter_type !== 'all') {
        switch ($filter_type) {
            case 'regular':
                $query .= " AND t.is_topup_task = FALSE AND t.is_special = FALSE";
                break;
            case 'special':
                $query .= " AND t.is_special = TRUE";
                break;
            case 'topup':
                $query .= " AND t.is_topup_task = TRUE";
                break;
        }
    }

    if ($filter_status !== 'all') {
        $query .= " AND t.is_active = ?";
        $params = [($filter_status === 'active') ? 1 : 0];
    }

    if (!empty($search_query)) {
        $query .= " AND (t.title LIKE ? OR t.description LIKE ?)";
        $search_term = "%$search_query%";
        $params[] = $search_term;
        $params[] = $search_term;
    }

    $query .= " ORDER BY t.is_topup_task ASC, t.is_special DESC, t.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
}

// Get statistics
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_tasks,
        SUM(is_active) as active_tasks,
        SUM(is_special) as special_tasks,
        SUM(is_topup_task) as topup_tasks
    FROM tasks
");
$stats = $stats_stmt->fetch();

// Get unread notifications count
$unread_notifications = $pdo->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_id = ? AND is_read = FALSE
");
$unread_notifications->execute([$_SESSION['user_id']]);
$unread_count = $unread_notifications->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks - Task Website</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Task Status */
        .topup-task { background-color: #fff3cd; border-left: 4px solid #ffc107; }
        .inactive-task { background-color: #f8f9fa; opacity: 0.7; }
        
        /* Badges */
        .special-task-badge { background: #e67e22; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .topup-task-badge { background: #ffc107; color: black; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .active-badge { background: #28a745; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .inactive-badge { background: #6c757d; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        
        /* Images */
        .task-image { width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 2px solid #ddd; }
        .no-image { width: 60px; height: 60px; background: #f5f5f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 12px; }
        
        /* Task Cards Grid */
        .tasks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
        .task-card { background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; transition: transform 0.3s ease, box-shadow 0.3s ease; border: 1px solid #e9ecef; cursor: pointer; }
        .task-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.15); }
        .task-card-image { width: 100%; height: 160px; object-fit: cover; }
        .task-card-content { padding: 1.5rem; }
        .task-card-title { font-size: 1.2rem; font-weight: bold; margin-bottom: 0.5rem; color: #333; }
        .task-card-description { color: #666; margin-bottom: 1rem; font-size: 0.9rem; line-height: 1.4; }
        .task-card-details { display: flex; justify-content: space-between; margin-bottom: 1rem; }
        .task-reward { color: #28a745; font-weight: bold; }
        .task-cost { color: #dc3545; }
        .task-completions { color: #6c757d; font-size: 0.8rem; }
        .task-card-badges { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
        .task-card-actions { display: flex; gap: 0.5rem; }
        
        /* Buttons */
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; transition: all 0.3s ease; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-warning:hover { background: #e0a800; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-info:hover { background: #138496; }
        
        /* View Toggle */
        .view-toggle { margin: 1rem 0; display: flex; gap: 1rem; }
        .view-toggle-btn { padding: 0.5rem 1rem; border: 2px solid #007bff; background: white; color: #007bff; border-radius: 6px; cursor: pointer; transition: all 0.3s ease; }
        .view-toggle-btn.active { background: #007bff; color: white; }
        .view-toggle-btn:hover { background: #007bff; color: white; }
        
        /* Messages */
        .success-message { background: #d4edda; color: #155724; padding: 1rem; border-radius: 5px; margin: 1rem 0; border: 1px solid #c3e6cb; }
        .error-message { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 5px; margin: 1rem 0; border: 1px solid #f5c6cb; }
        
        /* Filters */
        .filters { background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; border: 1px solid #dee2e6; }
        .filter-group { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .filter-item { display: flex; flex-direction: column; gap: 0.5rem; }
        .filter-item label { font-weight: bold; color: #495057; font-size: 0.9rem; }
        .filter-item select, .filter-item input { padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        .filter-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
        
        /* Statistics */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #007bff; cursor: pointer; transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
        .stat-number { font-size: 2rem; font-weight: bold; color: #007bff; }
        .stat-label { color: #6c757d; font-size: 0.9rem; }
        
        /* Bulk Actions */
        .bulk-actions { background: #e9ecef; padding: 1rem; border-radius: 6px; margin: 1rem 0; display: none; }
        .bulk-actions.active { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .select-all { margin-right: 1rem; }
        
        /* Table */
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background: #f8f9fa; font-weight: bold; color: #495057; }
        tr:hover { background: #f8f9fa; }
        
        /* Checkbox */
        .task-checkbox { transform: scale(1.2); }
        
        /* Clickable rows */
        .clickable-row { cursor: pointer; transition: background-color 0.2s ease; }
        .clickable-row:hover { background-color: #e9ecef !important; }
        
        /* Quick Actions */
        .quick-actions { display: flex; gap: 0.5rem; margin: 1rem 0; flex-wrap: wrap; }
        
        /* Modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 10% auto; padding: 20px; border-radius: 8px; width: 400px; max-width: 90%; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .modal-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem; }
        
        /* Task Preview */
        .task-preview { background: #f8f9fa; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #007bff; }
        .task-preview h4 { margin: 0 0 0.5rem 0; color: #333; }
        .task-preview p { margin: 0.25rem 0; color: #666; }
        
        /* All Tasks Table */
        .all-tasks-table { margin-top: 2rem; }
        .all-tasks-table h3 { color: #333; margin-bottom: 1rem; border-bottom: 2px solid #007bff; padding-bottom: 0.5rem; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .tasks-grid { grid-template-columns: 1fr; }
            .filter-group { flex-direction: column; align-items: stretch; }
            .task-card-actions { flex-direction: column; }
            .bulk-actions { flex-direction: column; align-items: flex-start; }
            table { font-size: 0.9rem; }
            th, td { padding: 0.5rem; }
            .quick-actions { flex-direction: column; }
            .all-tasks-table { overflow-x: auto; }
        }
    </style>
 <link rel="stylesheet" href="assets/css/admintask.css">

</head>
<body>
<div class="container">
    <header>
        <h1>Task Website - Admin Panel</h1>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="users.php">Users</a>
            <a href="tasks.php">Tasks</a>
            <a href="payments.php">Payments</a>
            <a href="commissions.php">Commissions</a>
            <a href="settings.php">Settings</a>
            <a href="notifications.php">Notifications (<?php echo $unread_count; ?>)</a>
            <a href="../logout.php">Logout</a>
        </nav>
    </header>

    <main>
        <h2>Manage Tasks</h2>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card" onclick="showAllTasks()">
                <div class="stat-number"><?php echo $stats['total_tasks']; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
            <div class="stat-card" onclick="filterByStatus('active')">
                <div class="stat-number"><?php echo $stats['active_tasks']; ?></div>
                <div class="stat-label">Active Tasks</div>
            </div>
            <div class="stat-card" onclick="filterByType('special')">
                <div class="stat-number"><?php echo $stats['special_tasks']; ?></div>
                <div class="stat-label">Special Tasks</div>
            </div>
            <div class="stat-card" onclick="filterByType('topup')">
                <div class="stat-number"><?php echo $stats['topup_tasks']; ?></div>
                <div class="stat-label">Top-up Tasks</div>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <p><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <p><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></p>
            </div>
        <?php endif; ?>

        <!-- Quick Action Buttons -->
        <div class="quick-actions">
            <a href="edit_task.php" class="btn btn-primary">➕ Create New Task</a>
            <a href="edit_task.php?type=topup" class="btn btn-warning">💰 Create Top-up Task</a>
            <a href="edit_task.php?type=special" class="btn btn-success">⭐ Create Special Task</a>
            <button class="btn btn-info" onclick="showQuickStats()">📊 Quick Stats</button>
        </div>

        <!-- Task Summary -->
        <div class="task-preview">
            <h4>📋 Task Summary</h4>
            <p><strong>Total Tasks:</strong> <?php echo $stats['total_tasks']; ?> | 
               <strong>Active:</strong> <?php echo $stats['active_tasks']; ?> | 
               <strong>Inactive:</strong> <?php echo $stats['total_tasks'] - $stats['active_tasks']; ?></p>
            <p><strong>Regular:</strong> <?php echo $stats['total_tasks'] - $stats['special_tasks'] - $stats['topup_tasks']; ?> | 
               <strong>Special:</strong> <?php echo $stats['special_tasks']; ?> | 
               <strong>Top-up:</strong> <?php echo $stats['topup_tasks']; ?></p>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" id="filterForm">
                <div class="filter-group">
                    <div class="filter-item">
                        <label for="type">Task Type:</label>
                        <select name="type" id="type" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="regular" <?php echo $filter_type === 'regular' ? 'selected' : ''; ?>>Regular</option>
                            <option value="special" <?php echo $filter_type === 'special' ? 'selected' : ''; ?>>Special</option>
                            <option value="topup" <?php echo $filter_type === 'topup' ? 'selected' : ''; ?>>Top-up</option>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="status">Status:</label>
                        <select name="status" id="status" onchange="document.getElementById('filterForm').submit()">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="search">Search:</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Search tasks...">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="tasks.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions" id="bulkActions">
            <div class="select-all">
                <input type="checkbox" id="selectAllBulk" onchange="toggleBulkSelection()">
                <label for="selectAllBulk">Select All</label>
            </div>
            <select id="bulkActionSelect" class="btn">
                <option value="">Bulk Actions</option>
                <option value="activate">Activate Selected</option>
                <option value="deactivate">Deactivate Selected</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button type="button" class="btn btn-primary" onclick="applyBulkAction()">Apply</button>
            <button type="button" class="btn btn-secondary" onclick="clearBulkSelection()">Cancel</button>
        </div>

        <!-- View Toggle -->
        <div class="view-toggle">
            <button class="view-toggle-btn active" onclick="showTableView()">📊 Table View</button>
            <button class="view-toggle-btn" onclick="showCardView()">🃏 Card View</button>
        </div>

        <!-- Table View -->
        <div id="tableView" class="table-container">
            <form id="bulkForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <table>
                    <thead>
                    <tr>
                        <th width="30"><input type="checkbox" id="selectAll" onchange="toggleAllSelection()"></th>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Reward</th>
                        <th>Cost</th>
                        <th>Completions</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (count($tasks) > 0): ?>
                        <?php foreach ($tasks as $task): ?>
                            <tr class="clickable-row <?php echo $task['is_topup_task'] ? 'topup-task' : ''; echo !$task['is_active'] ? ' inactive-task' : ''; ?>" 
                                onclick="editTask(<?php echo $task['id']; ?>)"
                                data-task-id="<?php echo $task['id']; ?>">
                                <td onclick="event.stopPropagation();">
                                    <input type="checkbox" class="task-checkbox" name="task_ids[]" value="<?php echo $task['id']; ?>" onchange="updateBulkActions()">
                                </td>
                                <td><?php echo $task['id']; ?></td>
                                <td>
                                    <?php if (!empty($task['image_path']) && file_exists($task['image_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($task['image_path']); ?>" alt="Task Image" class="task-image">
                                    <?php else: ?>
                                        <div class="no-image">No Image</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                    <?php if ($task['is_special']): ?>
                                        <span class="special-task-badge">Special</span>
                                    <?php endif; ?>
                                    <?php if ($task['is_topup_task']): ?>
                                        <span class="topup-task-badge">Top-up</span>
                                    <?php endif; ?>
                                </td>
                                <td>$<?php echo format_balance($task['reward']); ?></td>
                                <td>$<?php echo format_balance($task['cost']); ?></td>
                                <td><?php echo $task['completion_count']; ?></td>
                                <td>
                                    <?php if ($task['is_topup_task']): ?>
                                        <span style="color: #ffc107;">💰 Top-up</span>
                                    <?php elseif ($task['is_special']): ?>
                                        <span style="color: #e67e22;">⭐ Special</span>
                                    <?php else: ?>
                                        <span style="color: #007bff;">📝 Regular</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($task['is_active']): ?>
                                        <span class="active-badge">✅ Active</span>
                                    <?php else: ?>
                                        <span class="inactive-badge">❌ Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $task['created_by_name'] ? htmlspecialchars($task['created_by_name']) : 'System'; ?></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($task['created_at'])); ?></td>
                                <td onclick="event.stopPropagation();">
                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                        <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary">✏️ Edit</a>
                                        <button class="btn btn-danger" onclick="event.stopPropagation(); confirmDelete(<?php echo $task['id']; ?>)">🗑️ Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 3rem; color: #666;">
                                <h3>No tasks found</h3>
                                <p>No tasks match your current filters. <a href="tasks.php" style="color: #007bff;">Clear filters</a> or create a new task.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>

        <!-- Card View -->
        <div id="cardView" class="tasks-grid" style="display: none;">
            <?php if (count($tasks) > 0): ?>
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card <?php echo $task['is_topup_task'] ? 'topup-task' : ''; echo !$task['is_active'] ? ' inactive-task' : ''; ?>" 
                         onclick="editTask(<?php echo $task['id']; ?>)"
                         data-task-id="<?php echo $task['id']; ?>">
                        <?php if (!empty($task['image_path']) && file_exists($task['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($task['image_path']); ?>" alt="Task Image" class="task-card-image">
                        <?php else: ?>
                            <div style="width: 100%; height: 160px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; text-align: center; padding: 1rem;">
                                <?php echo htmlspecialchars($task['title']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="task-card-content">
                            <div class="task-card-title"><?php echo htmlspecialchars($task['title']); ?></div>
                            <div class="task-card-description"><?php echo htmlspecialchars($task['description']); ?></div>
                            
                            <div class="task-card-details">
                                <div class="task-reward">💰 $<?php echo format_balance($task['reward']); ?></div>
                                <?php if ($task['cost'] > 0): ?>
                                    <div class="task-cost">💸 $<?php echo format_balance($task['cost']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="task-completions">✅ Completed <?php echo $task['completion_count']; ?> times</div>
                            
                            <div class="task-card-badges">
                                <?php if ($task['is_special']): ?>
                                    <span class="special-task-badge">⭐ Special</span>
                                <?php endif; ?>
                                <?php if ($task['is_topup_task']): ?>
                                    <span class="topup-task-badge">💰 Top-up</span>
                                <?php endif; ?>
                                <?php if ($task['is_active']): ?>
                                    <span class="active-badge">✅ Active</span>
                                <?php else: ?>
                                    <span class="inactive-badge">❌ Inactive</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="task-card-actions" onclick="event.stopPropagation();">
                                <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary">✏️ Edit</a>
                                <button class="btn btn-danger" onclick="confirmDelete(<?php echo $task['id']; ?>)">🗑️ Delete</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: #666;">
                    <h3>No tasks found</h3>
                    <p>No tasks match your current filters. <a href="tasks.php" style="color: #007bff;">Clear filters</a> or create a new task.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- All Available Tasks Table -->
        <div class="all-tasks-table">
            <h3>📋 All Available Tasks (<?php echo count($tasks); ?> tasks)</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Reward</th>
                            <th>Cost</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Completions</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tasks) > 0): ?>
                            <?php foreach ($tasks as $task): ?>
                                <tr class="<?php echo $task['is_topup_task'] ? 'topup-task' : ''; echo !$task['is_active'] ? ' inactive-task' : ''; ?>">
                                    <td><strong>#<?php echo $task['id']; ?></strong></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <?php if (!empty($task['image_path']) && file_exists($task['image_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($task['image_path']); ?>" alt="Task Image" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                                <div style="display: flex; gap: 0.25rem; margin-top: 0.25rem;">
                                                    <?php if ($task['is_special']): ?>
                                                        <span class="special-task-badge">S</span>
                                                    <?php endif; ?>
                                                    <?php if ($task['is_topup_task']): ?>
                                                        <span class="topup-task-badge">T</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($task['description']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="color: #28a745; font-weight: bold;">$<?php echo format_balance($task['reward']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($task['cost'] > 0): ?>
                                            <span style="color: #dc3545;">$<?php echo format_balance($task['cost']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #6c757d;">Free</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($task['is_topup_task']): ?>
                                            <span style="color: #ffc107; font-weight: bold;">💰 Top-up</span>
                                        <?php elseif ($task['is_special']): ?>
                                            <span style="color: #e67e22; font-weight: bold;">⭐ Special</span>
                                        <?php else: ?>
                                            <span style="color: #007bff;">📝 Regular</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($task['is_active']): ?>
                                            <span class="active-badge">Active</span>
                                        <?php else: ?>
                                            <span class="inactive-badge">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: bold; color: #6c757d;"><?php echo $task['completion_count']; ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo date('M j, Y', strtotime($task['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 0.25rem; flex-wrap: wrap;">
                                            <a href="edit_task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">Edit</a>
                                            <button class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" onclick="confirmDelete(<?php echo $task['id']; ?>)">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 2rem; color: #666;">
                                    No tasks available. Create your first task to get started!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Task Website. All rights reserved.</p>
    </footer>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3>🗑️ Confirm Deletion</h3>
        <p>Are you sure you want to delete this task? This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-danger" id="confirmDeleteBtn">Delete Task</button>
        </div>
    </div>
</div>

<script>
    // View Toggle Functions
    function showTableView() {
        document.getElementById('tableView').style.display = 'block';
        document.getElementById('cardView').style.display = 'none';
        document.querySelectorAll('.view-toggle-btn')[0].classList.add('active');
        document.querySelectorAll('.view-toggle-btn')[1].classList.remove('active');
    }

    function showCardView() {
        document.getElementById('tableView').style.display = 'none';
        document.getElementById('cardView').style.display = 'grid';
        document.querySelectorAll('.view-toggle-btn')[0].classList.remove('active');
        document.querySelectorAll('.view-toggle-btn')[1].classList.add('active');
    }

    // Bulk Selection Functions
    function toggleAllSelection() {
        const checkboxes = document.querySelectorAll('.task-checkbox');
        const selectAll = document.getElementById('selectAll').checked;
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll;
        });
        updateBulkActions();
    }

    function toggleBulkSelection() {
        const checkboxes = document.querySelectorAll('.task-checkbox');
        const selectAllBulk = document.getElementById('selectAllBulk').checked;
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAllBulk;
        });
        updateBulkActions();
    }

    function updateBulkActions() {
        const checkboxes = document.querySelectorAll('.task-checkbox');
        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        const bulkActions = document.getElementById('bulkActions');
        
        if (checkedCount > 0) {
            bulkActions.classList.add('active');
        } else {
            bulkActions.classList.remove('active');
        }
        
        // Update select all checkboxes
        document.getElementById('selectAll').checked = checkedCount === checkboxes.length;
        document.getElementById('selectAllBulk').checked = checkedCount === checkboxes.length;
    }

    function clearBulkSelection() {
        const checkboxes = document.querySelectorAll('.task-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateBulkActions();
    }

    function applyBulkAction() {
        const action = document.getElementById('bulkActionSelect').value;
        if (!action) {
            alert('Please select a bulk action.');
            return;
        }
        
        const checkedCount = document.querySelectorAll('.task-checkbox:checked').length;
        if (checkedCount === 0) {
            alert('Please select at least one task.');
            return;
        }
        
        if (action === 'delete' && !confirm(`Are you sure you want to delete ${checkedCount} task(s)? This action cannot be undone.`)) {
            return;
        }
        
        document.getElementById('bulkForm').bulk_action.value = action;
        document.getElementById('bulkForm').submit();
    }

    // Task Management Functions
    function editTask(taskId) {
        window.location.href = `edit_task.php?id=${taskId}`;
    }

    function confirmDelete(taskId) {
        const modal = document.getElementById('deleteModal');
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        
        confirmBtn.onclick = function() {
            window.location.href = `tasks.php?delete=${taskId}&csrf_token=<?php echo generate_csrf_token(); ?>`;
        };
        
        modal.style.display = 'block';
    }

    function closeModal() {
        document.getElementById('deleteModal').style.display = 'none';
    }

    // Filter Functions
    function showAllTasks() {
        window.location.href = 'tasks.php';
    }

    function filterByStatus(status) {
        window.location.href = `tasks.php?status=${status}`;
    }

    function filterByType(type) {
        window.location.href = `tasks.php?type=${type}`;
    }

    function showQuickStats() {
        const totalTasks = <?php echo $stats['total_tasks']; ?>;
        const activeTasks = <?php echo $stats['active_tasks']; ?>;
        const specialTasks = <?php echo $stats['special_tasks']; ?>;
        const topupTasks = <?php echo $stats['topup_tasks']; ?>;
        const regularTasks = totalTasks - specialTasks - topupTasks;
        const inactiveTasks = totalTasks - activeTasks;
        const activeRate = totalTasks > 0 ? ((activeTasks / totalTasks) * 100).toFixed(1) : 0;
        
        alert(`📊 Task Statistics:\n\n` +
              `Total Tasks: ${totalTasks}\n` +
              `Active Tasks: ${activeTasks}\n` +
              `Inactive Tasks: ${inactiveTasks}\n` +
              `Regular Tasks: ${regularTasks}\n` +
              `Special Tasks: ${specialTasks}\n` +
              `Top-up Tasks: ${topupTasks}\n\n` +
              `Active Rate: ${activeRate}%`);
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('deleteModal');
        if (event.target === modal) {
            closeModal();
        }
    }

    // Add bulk action field to form
    document.addEventListener('DOMContentLoaded', function() {
        const bulkForm = document.getElementById('bulkForm');
        const bulkActionInput = document.createElement('input');
        bulkActionInput.type = 'hidden';
        bulkActionInput.name = 'bulk_action';
        bulkActionInput.id = 'bulk_action';
        bulkForm.appendChild(bulkActionInput);
        
        // Initialize view
        showTableView();
    });
</script>
</body>
</html>