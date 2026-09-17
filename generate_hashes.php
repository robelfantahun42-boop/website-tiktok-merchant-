<?php
echo "<h2>New Password Hashes</h2>";

$passwords = [
    'admin123',
    'subadmin123',
    'user123'
];

foreach ($passwords as $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    echo "<b>Password:</b> $password<br>";
    echo "<b>Hash:</b> $hash<br>";
    echo "<b>Verification:</b> " . (password_verify($password, $hash) ? 'SUCCESS' : 'FAILED') . "<br><br>";
}
?>