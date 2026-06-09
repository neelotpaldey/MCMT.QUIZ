<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/questions.php';
requireStudentLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/student/instructions.php');
    exit;
}

$db        = getDB();
$studentId = (int) $_SESSION['student_id'];
$examId    = (int) ($_POST['exam_id'] ?? 0);

if (!$examId) {
    header('Location: ' . BASE_URL . '/student/instructions.php?err=no_exam');
    exit;
}

try {
    $stmt = $db->prepare('SELECT * FROM exams WHERE id=? AND is_active=1 AND is_started=1');
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exam) {
        header('Location: ' . BASE_URL . '/student/instructions.php?err=not_started');
        exit;
    }

    clearStaleStudentExamSession($db, $studentId, $examId, $exam);
    $existingSession = getCurrentStudentExamSession($db, $studentId, $exam);

    if ($existingSession) {
        $_SESSION['session_id'] = (int) $existingSession['id'];
        $_SESSION['exam_id']    = $examId;
        if ($existingSession['submitted_at']) {
            header('Location: ' . BASE_URL . '/student/submit.php');
            exit;
        }
        header('Location: ' . BASE_URL . '/student/exam.php');
        exit;
    }

    $source = $exam['question_source'];
    $gkQ    = (int) $exam['gk_questions'];
    $enQ    = (int) $exam['english_questions'];
    $logQ   = (int) $exam['logical_questions'];
    $questions = [];

    if ($source === 'bank') {
        $questions = getBankQuestions($db, $examId, $gkQ, $enQ, $logQ);
    } elseif ($source === 'gemini') {
        $result = generateGeminiQuestions($exam['api_key'], $gkQ, $enQ, $logQ, $exam['api_model'] ?? getDefaultGeminiModel());
        $questions = isset($result['error']) ? getBankQuestions($db, $examId, $gkQ, $enQ, $logQ) : $result;
    } elseif ($source === 'groq') {
        $result = generateGroqQuestions($exam['api_key'], $gkQ, $enQ, $logQ, $exam['api_model'] ?? 'llama3-8b-8192');
        $questions = isset($result['error']) ? getBankQuestions($db, $examId, $gkQ, $enQ, $logQ) : $result;
    }

    if (empty($questions)) {
        header('Location: ' . BASE_URL . '/student/instructions.php?err=no_questions');
        exit;
    }

    $sessionId = null;

    $db->begin_transaction();
    try {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $db->prepare(
            "INSERT INTO student_exam_sessions (student_id, exam_id, started_at, ip_address, question_order)
             VALUES (?, ?, NOW(), ?, '[]')"
        );
        $stmt->bind_param('iis', $studentId, $examId, $ip);
        $stmt->execute();
        $sessionId = (int) $db->insert_id;
        $stmt->close();

        if ($source === 'bank') {
            $qids = array_column($questions, 'id');
        } else {
            $qids = saveAIQuestionsForSession($db, $sessionId, $questions);
        }

        shuffle($qids);

        $qorderJson = json_encode($qids);
        $upd = $db->prepare('UPDATE student_exam_sessions SET question_order=? WHERE id=?');
        $upd->bind_param('si', $qorderJson, $sessionId);
        $upd->execute();
        $upd->close();

        $insertStmt = $db->prepare('INSERT IGNORE INTO student_answers (session_id, question_id) VALUES (?,?)');
        foreach ($qids as $qid) {
            $qidInt = (int) $qid;
            $insertStmt->bind_param('ii', $sessionId, $qidInt);
            $insertStmt->execute();
        }
        $insertStmt->close();

        $db->commit();
    } catch (mysqli_sql_exception $e) {
        $db->rollback();
        if (isDuplicateKeyException($e)) {
            redirectExistingExamSession($db, $studentId, $examId);
        }
        throw $e;
    }

    $_SESSION['session_id']  = $sessionId;
    $_SESSION['exam_id']     = $examId;
    $_SESSION['exam_source'] = $source;

    header('Location: ' . BASE_URL . '/student/exam.php');
    exit;
} catch (mysqli_sql_exception $e) {
    handleDbException($e, [
        'context'  => 'start_exam',
        'redirect' => BASE_URL . '/student/instructions.php?err=system',
    ]);
}
