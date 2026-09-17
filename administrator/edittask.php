<?php
session_start();

// Database configuration
define('DB_HOST', 'sql103.infinityfree.com');
define('DB_NAME', 'if0_41786885_u890208008_taskdb');
define('DB_USER', 'if0_41786885');
define('DB_PASS', 'Roba201111');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// -------- DELETE TASK --------
if (isset($_GET['delete'])) {
    $task_id = intval($_GET['delete']);
    
    try {
        $pdo->beginTransaction();
        
        // First delete related records from daily_task_rewards table
        $stmt = $pdo->prepare("DELETE FROM daily_task_rewards WHERE task_id = ?");
        $stmt->execute([$task_id]);
        
        // Then delete the task
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        
        $pdo->commit();
        $_SESSION['message'] = "✅ Task #$task_id deleted successfully!";
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        
        // Check if it's a foreign key constraint error
        if ($e->getCode() == '23000') {
            $_SESSION['message'] = "❌ Cannot delete task #$task_id. It has related records in other tables. Please delete related records first.";
        } else {
            $_SESSION['message'] = "❌ Error deleting task: " . $e->getMessage();
        }
    }
    
    header("Location: edittask.php");
    exit;
}

// -------- EDIT TASK --------
if (isset($_POST['edit_task'])) {
    $task_id = intval($_POST['task_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $reward = floatval($_POST['reward']);
    $cost = floatval($_POST['cost']);
    $deposit = floatval($_POST['deposit_required']);
    $is_special = intval($_POST['is_special']);
    $status = intval($_POST['is_active']);

    try {
        $stmt = $pdo->prepare("UPDATE tasks 
                               SET title=?, description=?, reward=?, cost=?, deposit_required=?, is_special=?, is_active=? 
                               WHERE id=?");
        $stmt->execute([$title, $description, $reward, $cost, $deposit, $is_special, $status, $task_id]);

        $_SESSION['message'] = "✅ Task #$task_id updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['message'] = "❌ Error updating task: " . $e->getMessage();
    }
    
    header("Location: edittask.php");
    exit;
}

// -------- FETCH ALL TASKS --------
$stmt = $pdo->query("SELECT * FROM tasks ORDER BY id DESC");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin - Manage Tasks</title>
<style>
    body {
        font-family: Arial, sans-serif;
        background: #f4f6f8;
        margin: 0;
        padding: 0;
    }
    .container {
        width: 95%;
        max-width: 1300px;
        margin: 40px auto;
        background: #fff;
        padding: 25px;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h1 {
        text-align: center;
        color: #333;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 25px;
        font-size: 15px;
    }
    th, td {
        padding: 10px;
        border-bottom: 1px solid #ddd;
        text-align: left;
    }
    th {
        background: #333;
        color: #fff;
    }
    tr:hover {
        background: #f9f9f9;
    }
    .btn {
        padding: 6px 12px;
        border: none;
        border-radius: 4px;
        text-decoration: none;
        cursor: pointer;
        font-size: 14px;
        display: inline-block;
        margin: 2px;
    }
    .btn-edit {
        background: #0275d8;
        color: white;
    }
    .btn-edit:hover {
        background: #025aa5;
    }
    .btn-delete {
        background: #d9534f;
        color: white;
    }
    .btn-delete:hover {
        background: #c9302c;
    }
    .message {
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
        text-align: center;
    }
    .message.success {
        background: #dff0d8;
        color: #3c763d;
        border: 1px solid #d6e9c6;
    }
    .message.error {
        background: #f2dede;
        color: #a94442;
        border: 1px solid #ebccd1;
    }
    .edit-form {
        background: #f8f9fa;
        border: 1px solid #ddd;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 6px;
    }
    label {
        display: block;
        margin-top: 8px;
        font-weight: bold;
    }
    input[type=text], textarea, select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 4px;
        margin-top: 4px;
        box-sizing: border-box;
    }
    img.task-img {
        width: 70px;
        height: 70px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #ddd;
    }
    .btn-save {
        background: #5cb85c;
        color: white;
        margin-top: 10px;
    }
    .btn-save:hover {
        background: #4cae4c;
    }
    .actions-cell {
        white-space: nowrap;
    }
</style>
</head>
<body>

<div class="container">
    <h1>🧩 Manage All Tasks</h1>

    <?php if (!empty($_SESSION['message'])): ?>
        <?php 
        $messageClass = strpos($_SESSION['message'], '❌') !== false ? 'error' : 'success';
        ?>
        <div class="message <?= $messageClass ?>"><?= htmlspecialchars($_SESSION['message']) ?></div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <?php if (count($tasks) > 0): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Image</th>
                <th>Title</th>
                <th>Description</th>
                <th>Reward</th>
                <th>Cost</th>
                <th>Deposit</th>
                <th>Special</th>
                <th>Status</th>
                <th>Created By</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>

            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td><?= htmlspecialchars($task['id']) ?></td>
                    <td>
                        <?php if (!empty($task['image_path'])): ?>
                            <img src="<?= htmlspecialchars($task['image_path']) ?>" class="task-img" alt="Task Image">
                        <?php else: ?>
                            <span style="color:#888;">No image</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($task['title']) ?></td>
                    <td><?= htmlspecialchars($task['description']) ?></td>
                    <td><?= htmlspecialchars($task['reward']) ?></td>
                    <td><?= htmlspecialchars($task['cost']) ?></td>
                    <td><?= htmlspecialchars($task['deposit_required']) ?></td>
                    <td><?= $task['is_special'] ? 'Yes' : 'No' ?></td>
                    <td><?= $task['is_active'] ? 'Active' : 'Inactive' ?></td>
                    <td><?= htmlspecialchars($task['created_by']) ?></td>
                    <td><?= htmlspecialchars($task['created_at']) ?></td>
                    <td class="actions-cell">
                        <button class="btn btn-edit" onclick="toggleEdit(<?= $task['id'] ?>)">Edit</button>
                        <a href="?delete=<?= $task['id'] ?>" class="btn btn-delete"
                           onclick="return confirm('Are you sure you want to delete task #<?= $task['id'] ?>? This will also delete related records.');">Delete</a>
                    </td>
                </tr>

                <!-- Inline Edit Form -->
                <tr id="edit-<?= $task['id'] ?>" style="display:none;">
                    <td colspan="12">
                        <form method="post" class="edit-form">
                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                            
                            <label>Title:</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($task['title']) ?>" required>

                            <label>Description:</label>
                            <textarea name="description" rows="4" required><?= htmlspecialchars($task['description']) ?></textarea>

                            <label>Reward:</label>
                            <input type="number" step="0.01" name="reward" value="<?= htmlspecialchars($task['reward']) ?>" required>

                            <label>Cost:</label>
                            <input type="number" step="0.01" name="cost" value="<?= htmlspecialchars($task['cost']) ?>">

                            <label>Deposit Required:</label>
                            <input type="number" step="0.01" name="deposit_required" value="<?= htmlspecialchars($task['deposit_required']) ?>">

                            <label>Special Task:</label>
                            <select name="is_special">
                                <option value="1" <?= $task['is_special'] ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= !$task['is_special'] ? 'selected' : '' ?>>No</option>
                            </select>

                            <label>Status:</label>
                            <select name="is_active">
                                <option value="1" <?= $task['is_active'] ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= !$task['is_active'] ? 'selected' : '' ?>>Inactive</option>
                            </select>

                            <button type="submit" name="edit_task" class="btn btn-save">💾 Save Changes</button>
                            <button type="button" class="btn" onclick="toggleEdit(<?= $task['id'] ?>)">Cancel</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No tasks available.</p>
    <?php endif; ?>
</div>

<script>
function toggleEdit(id) {
    const formRow = document.getElementById('edit-' + id);
    formRow.style.display = formRow.style.display === 'none' ? 'table-row' : 'none';
}
</script>

</body>
</html>