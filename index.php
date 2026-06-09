<?php
// developed by @neelotpal.dey
// Root entry point — always opens the student portal (not admin).
require_once __DIR__ . '/includes/auth.php';

$db = getDB();
if (validateStudentSession($db)) {
    header('Location: ' . BASE_URL . '/student/instructions.php');
} else {
    header('Location: ' . BASE_URL . '/student/login.php');
}
exit;
