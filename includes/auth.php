<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/exams.php';

ensureExamSchema(getDB());

// ── Session helpers ────────────────────────────────────────────────
// Admin and student use separate session cookies so both can stay logged in
// in the same browser (e.g. admin tab + student tab while testing).
function closeSessionIfActive(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function sessionCookieParams(): array
{
    return [
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'EXAM_ADMIN_SESS') {
        return;
    }
    closeSessionIfActive();
    session_name('EXAM_ADMIN_SESS');
    session_set_cookie_params(sessionCookieParams());
    session_start();
}

function startStudentSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'EXAM_STUDENT_SESS') {
        return;
    }
    closeSessionIfActive();
    session_name('EXAM_STUDENT_SESS');
    session_set_cookie_params(sessionCookieParams());
    session_start();
}

/** @deprecated Use startAdminSession() or startStudentSession() */
function startSecureSession(): void
{
    startStudentSession();
}

// ── Student auth ───────────────────────────────────────────────────
function isStudentLoggedIn(): bool
{
    startStudentSession();

    return isset($_SESSION['student_id']) && !empty($_SESSION['student_id']);
}

function clearStudentLoginToken(mysqli $db, int $studentId): void
{
    $stmt = $db->prepare('UPDATE students SET login_token = NULL, login_token_at = NULL WHERE id = ?');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->close();
}

function studentHasActiveLogin(mysqli $db, int $studentId): bool
{
    $stmt = $db->prepare('SELECT login_token FROM students WHERE id = ?');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row['login_token']);
}

function validateStudentSession(mysqli $db): bool
{
    startStudentSession();
    $studentId = (int) ($_SESSION['student_id'] ?? 0);
    $token = (string) ($_SESSION['student_token'] ?? '');
    if ($studentId < 1 || $token === '') {
        return false;
    }

    $stmt = $db->prepare('SELECT login_token FROM students WHERE id = ? AND is_active = 1');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row && hash_equals((string) $row['login_token'], $token);
}

function invalidateStudentSession(): void
{
    startStudentSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function requireStudentLogin(): void
{
    $db = getDB();
    if (!validateStudentSession($db)) {
        if (isStudentLoggedIn()) {
            invalidateStudentSession();
            header('Location: ' . BASE_URL . '/student/login.php?err=other_device');
            exit;
        }
        header('Location: ' . BASE_URL . '/student/login.php');
        exit;
    }
}

function loginStudent(mysqli $db, int $studentId, string $studentName): void
{
    $token = bin2hex(random_bytes(32));
    startStudentSession();
    session_regenerate_id(true);
    $_SESSION['student_id']     = $studentId;
    $_SESSION['student_name']   = $studentName;
    $_SESSION['student_token']  = $token;
    $_SESSION['login_time']     = time();

    $stmt = $db->prepare('UPDATE students SET login_token = ?, login_token_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $token, $studentId);
    $stmt->execute();
    $stmt->close();
}

function logoutStudent(): void
{
    startStudentSession();
    $studentId = (int) ($_SESSION['student_id'] ?? 0);
    if ($studentId > 0) {
        clearStudentLoginToken(getDB(), $studentId);
    }
    invalidateStudentSession();
    header('Location: ' . BASE_URL . '/student/login.php');
    exit;
}

// ── Admin auth ─────────────────────────────────────────────────────
function isAdminLoggedIn() {
    startAdminSession();
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function loginAdmin($adminId, $adminName) {
    startAdminSession();
    session_regenerate_id(true);
    $_SESSION['admin_id']   = $adminId;
    $_SESSION['admin_name'] = $adminName;
    $_SESSION['admin_time'] = time();
}

function logoutAdmin() {
    startAdminSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}

// ── Utility ────────────────────────────────────────────────────────
function sanitize($db, $value) {
    return $db->real_escape_string(trim(strip_tags($value)));
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// ── BASE_URL: reliable on XAMPP/Windows & Linux ───────────────────
// Uses __FILE__ and DOCUMENT_ROOT so it never depends on SCRIPT_NAME,
// which Apache sets inconsistently for directory-index requests.
(function () {
    $scheme  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'];

    // Normalise both paths to forward-slashes and lowercase on Windows
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    // __DIR__ of THIS file is …/exam_system/includes
    // We want …/exam_system  →  go one level up
    $appRoot  = str_replace('\\', '/', dirname(__DIR__));

    // Make $appRoot relative to $docRoot
    $relative = str_replace($docRoot, '', $appRoot);

    // Ensure it starts with / and has no trailing slash
    $relative = '/' . ltrim($relative, '/');
    $relative = rtrim($relative, '/');

    define('BASE_URL', $scheme . '://' . $host . $relative);
})();

// ── Database exception handling ────────────────────────────────────
function isJsonRequest(): bool
{
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return str_contains($script, 'api_') || str_contains($script, 'api_save');
}

function handleDbException(Throwable $e, array $options = []): never
{
    logDbError($e, $options['context'] ?? '');

    $message = $options['message']
        ?? 'A system error occurred. Please try again or contact the administrator.';

    if ($options['json'] ?? isJsonRequest()) {
        jsonResponse(['status' => 'error', 'message' => $message], 500);
    }

    $redirect = $options['redirect'] ?? (BASE_URL . '/student/instructions.php?err=system');
    redirect($redirect);
}

function redirectExistingExamSession(mysqli $db, int $studentId, int $examId): never
{
    $stmt = $db->prepare('SELECT id, submitted_at FROM student_exam_sessions WHERE student_id=? AND exam_id=?');
    $stmt->bind_param('ii', $studentId, $examId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        handleDbException(new RuntimeException('Session row missing after duplicate key'), [
            'context' => 'redirectExistingExamSession',
        ]);
    }

    $_SESSION['session_id'] = (int) $row['id'];
    $_SESSION['exam_id']    = $examId;

    if ($row['submitted_at']) {
        redirect(BASE_URL . '/student/submit.php');
    }
    redirect(BASE_URL . '/student/exam.php');
}

set_exception_handler(function (Throwable $e) {
    if ($e instanceof mysqli_sql_exception) {
        handleDbException($e);
    }

    logDbError($e, 'uncaught');
    http_response_code(500);

    if (isJsonRequest()) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
    } else {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
        echo '<h1>Something went wrong</h1>';
        echo '<p>Please try again later or contact the administrator.</p></body></html>';
    }
    exit;
});
