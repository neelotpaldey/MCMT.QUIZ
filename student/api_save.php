<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireStudentLogin();

header('Content-Type: application/json');

try {
    $db   = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        $data = $_POST;
    }

    $sessionId = (int) ($data['session_id'] ?? 0);
    $studentId = (int) $_SESSION['student_id'];

    $stmt = $db->prepare(
        'SELECT id, exam_id FROM student_exam_sessions WHERE id=? AND student_id=? AND submitted_at IS NULL'
    );
    $stmt->bind_param('ii', $sessionId, $studentId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid session'], 400);
    }

    if (!empty($data['auto_submit'])) {
        submitExam($db, $sessionId, (int) $session['exam_id']);
        jsonResponse(['status' => 'submitted']);
    }

    if (!empty($data['log_violation'])) {
        jsonResponse(['status' => 'logged']);
    }

    $questionId = (int) ($data['question_id'] ?? 0);
    $selectedAnswer = isset($data['selected_answer']) && in_array($data['selected_answer'], ['A', 'B', 'C', 'D'], true)
        ? $data['selected_answer']
        : null;
    $isMarked = (int) ($data['is_marked_review'] ?? 0);

    if (!$questionId) {
        jsonResponse(['status' => 'error', 'message' => 'No question id'], 400);
    }

    $stmt = $db->prepare(
        'INSERT INTO student_answers (session_id, question_id, selected_answer, is_marked_review)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE selected_answer=VALUES(selected_answer), is_marked_review=VALUES(is_marked_review)'
    );
    $stmt->bind_param('iisi', $sessionId, $questionId, $selectedAnswer, $isMarked);
    $stmt->execute();
    $stmt->close();

    jsonResponse(['status' => 'ok']);
} catch (mysqli_sql_exception $e) {
    handleDbException($e, [
        'context' => 'api_save',
        'json'    => true,
        'message' => 'Could not save your answer. Please try again.',
    ]);
}

function submitExam(mysqli $db, int $sessionId, int $examId): void
{
    $stmt = $db->prepare('SELECT submitted_at FROM student_exam_sessions WHERE id=?');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['submitted_at']) {
        return;
    }

    $stmt = $db->prepare('UPDATE student_exam_sessions SET submitted_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('SELECT * FROM exams WHERE id=?');
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exam) {
        return;
    }

    $source  = $exam['question_source'];
    $answers = [];
    $ansRes  = $db->query("SELECT * FROM student_answers WHERE session_id=$sessionId");
    while ($ansRow = $ansRes->fetch_assoc()) {
        $answers[$ansRow['question_id']] = $ansRow;
    }

    $qids = array_keys($answers);
    if (empty($qids)) {
        computeResult($db, $sessionId, $examId, $exam, 0, 0, 0, 0);
        return;
    }

    $qidStr = implode(',', array_map('intval', $qids));
    if ($source === 'bank') {
        $qRes = $db->query("SELECT id, correct_answer FROM question_bank WHERE id IN ($qidStr)");
    } else {
        $qRes = $db->query("SELECT id, correct_answer FROM ai_generated_questions WHERE id IN ($qidStr)");
    }

    $correct = $wrong = $skipped = $attempted = 0;
    while ($q = $qRes->fetch_assoc()) {
        $a = $answers[$q['id']] ?? null;
        if (!$a || !$a['selected_answer']) {
            $skipped++;
            continue;
        }
        $attempted++;
        if ($a['selected_answer'] === $q['correct_answer']) {
            $correct++;
        } else {
            $wrong++;
        }
    }

    computeResult($db, $sessionId, $examId, $exam, $correct, $wrong, $skipped, $attempted);
}

function computeResult(mysqli $db, int $sessionId, int $examId, array $exam, int $correct, int $wrong, int $skipped, int $attempted): void
{
    $total  = (int) $exam['total_questions'];
    $pos    = (float) $exam['marks_per_correct'];
    $neg    = (float) $exam['negative_marks'];
    $marks  = round($correct * $pos - $wrong * $neg, 2);
    $pct    = $total > 0 ? round($marks / $exam['total_marks'] * 100, 2) : 0;
    $passed = ($marks >= $exam['passing_marks']) ? 1 : 0;

    $stmt = $db->prepare('SELECT student_id FROM student_exam_sessions WHERE id=?');
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $ses = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ses) {
        return;
    }

    $sid = (int) $ses['student_id'];
    $stmt = $db->prepare(
        'INSERT IGNORE INTO exam_results
         (session_id, student_id, exam_id, total_questions, attempted, correct, wrong, skipped, marks_obtained, percentage, is_passed)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('iiiiiiiiddi', $sessionId, $sid, $examId, $total, $attempted, $correct, $wrong, $skipped, $marks, $pct, $passed);
    $stmt->execute();
    $stmt->close();
}
