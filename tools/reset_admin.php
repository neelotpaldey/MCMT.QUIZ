<?php
// developed by @neelotpal.dey
/**
 * One-time admin reset. Run from CLI: php tools/reset_admin.php
 * Or open once in browser, then delete this file.
 */
require_once __DIR__ . '/../includes/db.php';

$username = 'admin';
$password = 'admin123';
$fullName = 'System Administrator';
$email    = 'admin@examportal.com';

$hash = password_hash($password, PASSWORD_DEFAULT);

$db = getDB();

$stmt = $db->prepare('SELECT id FROM admin_users WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $id = (int) $existing['id'];
    $stmt = $db->prepare('UPDATE admin_users SET password = ?, full_name = ?, email = ? WHERE id = ?');
    $stmt->bind_param('sssi', $hash, $fullName, $email, $id);
    $stmt->execute();
    $stmt->close();
    $action = 'updated';
} else {
    $stmt = $db->prepare(
        'INSERT INTO admin_users (username, password, full_name, email) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('ssss', $username, $hash, $fullName, $email);
    $stmt->execute();
    $stmt->close();
    $action = 'created';
}

$verified = password_verify($password, $hash);

$out = [
    'status'   => 'ok',
    'action'   => $action,
    'username' => $username,
    'password' => $password,
    'verified' => $verified,
    'login'    => 'http://localhost/exam_system/admin/login.php',
];

if (PHP_SAPI === 'cli') {
    echo "Admin account {$action} successfully.\n";
    echo "Username: {$username}\n";
    echo "Password: {$password}\n";
    echo "Login:    {$out['login']}\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Admin account {$action}.\n\n";
    echo "Username: {$username}\n";
    echo "Password: {$password}\n\n";
    echo "Login at: {$out['login']}\n\n";
    echo "Delete tools/reset_admin.php after use.\n";
}
