<?php
// developed by @neelotpal.dey

function ensureExamSchema(mysqli $db): void
{
    $check = $db->query("SHOW COLUMNS FROM exams LIKE 'show_results'");
    if ($check->num_rows === 0) {
        $db->query(
            'ALTER TABLE exams ADD COLUMN show_results TINYINT(1) NOT NULL DEFAULT 1 AFTER instructions'
        );
    }

    $tokenCheck = $db->query("SHOW COLUMNS FROM students LIKE 'login_token'");
    if ($tokenCheck->num_rows === 0) {
        $db->query('ALTER TABLE students ADD COLUMN login_token VARCHAR(64) NULL AFTER is_active');
        $db->query('ALTER TABLE students ADD COLUMN login_token_at TIMESTAMP NULL AFTER login_token');
    }
}

function examShowsResultsToStudent(mysqli $db, array $exam): bool
{
    if (!showStudentResults($db)) {
        return false;
    }

    if (array_key_exists('show_results', $exam)) {
        return (int) $exam['show_results'] === 1;
    }

    return true;
}

function sessionBelongsToCurrentExamRun(array $exam, array $session): bool
{
    if (empty($exam['started_at']) || empty($session['started_at'])) {
        return false;
    }

    $examStart = strtotime((string) $exam['started_at']);
    $sessionStart = strtotime((string) $session['started_at']);

    return $examStart !== false && $sessionStart !== false && $sessionStart >= $examStart;
}

function getStudentExamSession(mysqli $db, int $studentId, int $examId): ?array
{
    $stmt = $db->prepare('SELECT * FROM student_exam_sessions WHERE student_id = ? AND exam_id = ?');
    $stmt->bind_param('ii', $studentId, $examId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function getCurrentStudentExamSession(mysqli $db, int $studentId, array $exam): ?array
{
    $examId = (int) ($exam['id'] ?? 0);
    if ($examId < 1) {
        return null;
    }

    $session = getStudentExamSession($db, $studentId, $examId);
    if (!$session || !sessionBelongsToCurrentExamRun($exam, $session)) {
        return null;
    }

    return $session;
}

function removeStudentExamSession(mysqli $db, int $sessionId): void
{
    $sessionId = (int) $sessionId;
    if ($sessionId < 1) {
        return;
    }

    $db->query("DELETE FROM student_answers WHERE session_id = $sessionId");
    $db->query("DELETE FROM ai_generated_questions WHERE session_id = $sessionId");
    $db->query("DELETE FROM exam_results WHERE session_id = $sessionId");
    $db->query("DELETE FROM student_exam_sessions WHERE id = $sessionId");
}

function clearStaleStudentExamSession(mysqli $db, int $studentId, int $examId, array $exam): void
{
    $session = getStudentExamSession($db, $studentId, $examId);
    if (!$session || sessionBelongsToCurrentExamRun($exam, $session)) {
        return;
    }

    removeStudentExamSession($db, (int) $session['id']);
}

function examHasStudentSessions(mysqli $db, int $examId): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM student_exam_sessions WHERE exam_id = ? AND started_at IS NOT NULL'
    );
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();

    return $count > 0;
}

function examLiveActivitySeconds(): int
{
    return 120;
}

function formatExamLiveTimestamp(?string $timestamp): ?string
{
    if ($timestamp === null || $timestamp === '') {
        return null;
    }

    $ts = strtotime($timestamp);
    if ($ts === false) {
        return null;
    }

    return date('d M Y, h:i A', $ts);
}

function classifyExamLiveStudent(array $row, int $thresholdSeconds): array
{
    $now = time();
    $startedTs = strtotime((string) $row['started_at']) ?: 0;
    $lastActivity = $row['last_activity'] ?? null;
    $lastTs = $lastActivity ? (strtotime((string) $lastActivity) ?: 0) : 0;
    $referenceTs = max($startedTs, $lastTs);
    $secondsAgo = $referenceTs > 0 ? max(0, $now - $referenceTs) : 9999;
    $isActiveNow = $secondsAgo <= $thresholdSeconds;

    return [
        'session_id'      => (int) $row['session_id'],
        'student_id'      => (int) $row['student_id'],
        'full_name'       => $row['full_name'] ?? '',
        'roll_number'     => $row['roll_number'] ?? '',
        'mobile'          => $row['mobile'] ?? '',
        'started_at'      => $row['started_at'] ?? null,
        'started_label'   => formatExamLiveTimestamp($row['started_at'] ?? null),
        'last_activity'   => $lastActivity,
        'last_label'      => formatExamLiveTimestamp($lastActivity),
        'answered_count'  => (int) ($row['answered_count'] ?? 0),
        'ip_address'      => $row['ip_address'] ?? '',
        'status'          => $isActiveNow ? 'active' : 'idle',
        'status_label'    => $isActiveNow ? 'Active now' : 'In exam (idle)',
        'seconds_since'   => $secondsAgo,
    ];
}

function getExamLiveStudents(mysqli $db, int $examId): array
{
    $stmt = $db->prepare(
        'SELECT
            ses.id AS session_id,
            s.id AS student_id,
            s.full_name,
            s.roll_number,
            s.mobile,
            ses.started_at,
            ses.ip_address,
            (
                SELECT MAX(sa.answered_at)
                FROM student_answers sa
                WHERE sa.session_id = ses.id
            ) AS last_activity,
            (
                SELECT COUNT(*)
                FROM student_answers sa
                WHERE sa.session_id = ses.id AND sa.selected_answer IS NOT NULL
            ) AS answered_count
        FROM student_exam_sessions ses
        INNER JOIN students s ON s.id = ses.student_id
        WHERE ses.exam_id = ?
          AND ses.started_at IS NOT NULL
          AND ses.submitted_at IS NULL
        ORDER BY COALESCE(last_activity, ses.started_at) DESC, s.full_name ASC'
    );
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $result = $stmt->get_result();

    $threshold = examLiveActivitySeconds();
    $students = [];
    $activeNow = 0;
    while ($row = $result->fetch_assoc()) {
        $student = classifyExamLiveStudent($row, $threshold);
        if ($student['status'] === 'active') {
            $activeNow++;
        }
        $students[] = $student;
    }
    $stmt->close();

    return [
        'live_count'       => count($students),
        'active_now_count' => $activeNow,
        'students'         => $students,
    ];
}

function getLiveExamsMonitor(mysqli $db, ?int $examId = null): array
{
    $params = [];
    $sql = 'SELECT id, title, is_started, duration_minutes, started_at
            FROM exams
            WHERE is_started = 1';
    if ($examId !== null) {
        $sql .= ' AND id = ?';
        $params[] = $examId;
    }
    $sql .= ' ORDER BY started_at DESC';

    if ($params) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $examId);
        $stmt->execute();
        $examRows = $stmt->get_result();
    } else {
        $examRows = $db->query($sql);
    }

    $exams = [];
    $totalLive = 0;
    $totalActiveNow = 0;
    while ($exam = $examRows->fetch_assoc()) {
        $live = getExamLiveStudents($db, (int) $exam['id']);
        $totalLive += $live['live_count'];
        $totalActiveNow += $live['active_now_count'];
        $exams[] = [
            'exam_id'          => (int) $exam['id'],
            'title'            => $exam['title'],
            'duration_minutes' => (int) $exam['duration_minutes'],
            'live_count'       => $live['live_count'],
            'active_now_count' => $live['active_now_count'],
            'students'         => $live['students'],
        ];
    }

    if (isset($stmt)) {
        $stmt->close();
    }

    return [
        'refreshed_at'            => date('c'),
        'activity_window_seconds' => examLiveActivitySeconds(),
        'total_live'              => $totalLive,
        'total_active_now'        => $totalActiveNow,
        'exams'                   => $exams,
    ];
}
