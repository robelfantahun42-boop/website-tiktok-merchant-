<?php
$host = "sql103.infinityfree.com";
$username = "if0_41786885";
$password = "Roba201111";
$database = "if0_41786885_u890208008_taskdb";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // SQL to create the procedure
    $sql = "CREATE PROCEDURE `UpdateUserPassword` (
                IN `p_user_id` INT, 
                IN `p_encrypted_password` TEXT, 
                IN `p_encryption_key_id` INT
            )
            BEGIN
                INSERT INTO user_passwords (user_id, encrypted_password, encryption_key_id)
                VALUES (p_user_id, p_encrypted_password, p_encryption_key_id)
                ON DUPLICATE KEY UPDATE 
                    encrypted_password = VALUES(encrypted_password),
                    encryption_key_id = VALUES(encryption_key_id),
                    updated_at = CURRENT_TIMESTAMP;
            END";
    
    // Execute the query
    $pdo->exec($sql);
    
    echo "✅ Procedure 'UpdateUserPassword' created successfully!";
    
} catch(PDOException $e) {
    echo "❌ Error creating procedure: " . $e->getMessage();
}
?>