<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $db = getDB();
    $examId = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : null;
    if ($examId !== null && $examId < 1) {
        $examId = null;
    }

    echo json_encode(getLiveExamsMonitor($db, $examId));
} catch (Throwable $e) {
    logDbError($e, 'api_live_students');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not load live student data.']);
}
