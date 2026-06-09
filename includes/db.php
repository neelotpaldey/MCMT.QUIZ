<?php
// developed by @neelotpal.dey
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'exam_system');

function logDbError(Throwable $e, string $context = ''): void
{
    $ctx = $context ? " [$context]" : '';
    error_log("[exam_system{$ctx}] {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
}

function isDuplicateKeyException(mysqli_sql_exception $e): bool
{
    return (int) $e->getCode() === 1062;
}

function getDB(): mysqli
{
    global $conn;
    return $conn;
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    logDbError($e, 'connect');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    die('Database connection failed. Please try again later or contact the administrator.');
}
