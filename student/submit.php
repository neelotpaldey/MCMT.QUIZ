<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireStudentLogin();
$db        = getDB();
$studentId = (int) $_SESSION['student_id'];
$sessionId = (int) ($_SESSION['session_id'] ?? $_POST['session_id'] ?? $_GET['session_id'] ?? 0);

if (!$sessionId) {
    header('Location: ' . BASE_URL . '/student/instructions.php');
    exit;
}

$result      = null;
$student     = null;
$exam        = null;
$session     = null;
$showResults = false;

try {
    $stmt = $db->prepare('SELECT * FROM student_exam_sessions WHERE id=? AND student_id=?');
    $stmt->bind_param('ii', $sessionId, $studentId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        header('Location: ' . BASE_URL . '/student/instructions.php');
        exit;
    }

    $examId = (int) $session['exam_id'];
    $exam   = $db->query("SELECT * FROM exams WHERE id=$examId")->fetch_assoc();
    $showResults = examShowsResultsToStudent($db, $exam ?: []);
    $source = $exam['question_source'] ?? 'bank';

    if (!$session['submitted_at']) {
        $db->query("UPDATE student_exam_sessions SET submitted_at=NOW() WHERE id=$sessionId");

        $ansRes  = $db->query("SELECT * FROM student_answers WHERE session_id=$sessionId");
        $answers = [];
        while ($r = $ansRes->fetch_assoc()) {
            $answers[$r['question_id']] = $r;
        }

        $qids = array_keys($answers);
        if (!empty($qids)) {
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
            $total  = (int) $exam['total_questions'];
            $marks  = round($correct * $exam['marks_per_correct'] - $wrong * $exam['negative_marks'], 2);
            $pct    = $total > 0 ? round($marks / $exam['total_marks'] * 100, 2) : 0;
            $passed = ($marks >= $exam['passing_marks']) ? 1 : 0;
            $db->query("INSERT IGNORE INTO exam_results
                (session_id,student_id,exam_id,total_questions,attempted,correct,wrong,skipped,marks_obtained,percentage,is_passed)
                VALUES ($sessionId,$studentId,$examId,$total,$attempted,$correct,$wrong,$skipped,$marks,$pct,$passed)");
        }

        $session['submitted_at'] = date('Y-m-d H:i:s');
    }

    $student = $db->query("SELECT * FROM students WHERE id=$studentId")->fetch_assoc();
    if ($showResults) {
        $result = $db->query("SELECT * FROM exam_results WHERE session_id=$sessionId")->fetch_assoc();
    }
} catch (mysqli_sql_exception $e) {
    handleDbException($e, [
        'context'  => 'submit',
        'redirect' => BASE_URL . '/student/instructions.php?err=system',
    ]);
}

$submittedAt = $session['submitted_at'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $showResults && $result ? 'Result' : 'Submitted' ?> – ExamPortal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<?php themeInitScript(); themeStylesheet(); themeScript(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root, [data-theme="dark"] {
  --bg:#0d1b2a; --text:#f8f9fa; --muted:rgba(255,255,255,.5);
  --card:rgba(255,255,255,.04); --border:rgba(255,255,255,.1);
  --sky:#1e88e5; --accent:#ffb703; --passed:#27ae60; --failed:#c0392b;
  --grad:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(30,136,229,.25) 0%,transparent 55%);
  --shadow:0 32px 64px rgba(0,0,0,.4);
}
body.student-submit { min-height:100vh; background:var(--bg); color:var(--text); font-family:'DM Sans',sans-serif;
  background-image:var(--grad); padding:2rem; display:flex; flex-direction:column; align-items:center; position:relative; }
.card { background:var(--card); border:1px solid var(--border); border-radius:24px;
  padding:3rem; max-width:640px; width:100%; box-shadow:var(--shadow);
  animation:slideUp .5s cubic-bezier(.22,.68,0,1.2) both; }
@keyframes slideUp { from{opacity:0;transform:translateY(28px)} to{opacity:1;transform:translateY(0)} }
.result-header { text-align:center; margin-bottom:2.5rem; }
.pass-badge { display:inline-flex; align-items:center; gap:8px; padding:6px 20px;
  border-radius:50px; font-size:.85rem; font-weight:700; margin-bottom:1rem; }
.pass-badge.passed { background:rgba(39,174,96,.2); border:1px solid rgba(39,174,96,.4); color:var(--passed); }
.pass-badge.failed { background:rgba(192,57,43,.2); border:1px solid rgba(192,57,43,.4); color:var(--failed); }
.submitted-icon { font-size:4rem; margin-bottom:1rem; }
.score-circle { width:160px; height:160px; border-radius:50%; margin:1.5rem auto;
  display:flex; flex-direction:column; align-items:center; justify-content:center; }
.score-circle.passed { background:radial-gradient(circle,rgba(39,174,96,.15),rgba(39,174,96,.05)); border:3px solid rgba(39,174,96,.5); }
.score-circle.failed { background:radial-gradient(circle,rgba(192,57,43,.15),rgba(192,57,43,.05)); border:3px solid rgba(192,57,43,.4); }
.score-pct { font-family:'Playfair Display',serif; font-size:2.8rem; font-weight:700; line-height:1; }
.score-pct.passed { color:var(--passed); }
.score-pct.failed { color:var(--failed); }
.score-label { font-size:.75rem; color:var(--muted); margin-top:4px; }
.marks-big { font-size:1.1rem; font-weight:600; margin-top:6px; }
.stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin:2rem 0; }
.stat-card { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:1.2rem; text-align:center; }
.stat-val { font-size:2rem; font-weight:700; }
.stat-lbl { font-size:.75rem; color:var(--muted); margin-top:4px; }
.info-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border); font-size:.9rem; }
.info-row:last-child { border:none; }
.info-key { color:var(--muted); }
.info-val { font-weight:600; }
.btn { display:block; width:100%; padding:13px; border-radius:12px; font-family:'DM Sans',sans-serif;
  font-size:.95rem; font-weight:600; cursor:pointer; border:none; text-align:center;
  text-decoration:none; margin-top:.8rem; transition:.18s; }
.btn-home { background:linear-gradient(135deg,var(--sky),#1565c0); color:#fff; box-shadow:0 6px 20px rgba(30,136,229,.35); }
.btn-home:hover { transform:translateY(-1px); }
.subtitle { color:var(--muted); font-size:.95rem; line-height:1.6; margin-top:.5rem; }
</style>
</head>
<body class="student-submit">
<?php themeToggleButton('theme-toggle-fixed'); ?>

<?php if (!$showResults || (!$result && $submittedAt)): ?>
<div class="card" style="text-align:center;padding:3rem 2.5rem;">
  <div class="submitted-icon">✅</div>
  <h2 style="font-family:'Playfair Display',serif;font-size:1.8rem;margin-bottom:.5rem">Exam Submitted</h2>
  <p class="subtitle">
    Thank you, <strong><?= htmlspecialchars($student['full_name'] ?? 'Student') ?></strong>.<br>
    Your answers for <strong><?= htmlspecialchars($exam['title'] ?? 'the exam') ?></strong> have been recorded successfully.
  </p>
  <?php if ($submittedAt): ?>
  <p class="subtitle" style="margin-top:1rem;font-size:.85rem">
    Submitted on <?= date('d M Y, h:i A', strtotime($submittedAt)) ?>
  </p>
  <?php endif; ?>
  <p class="subtitle" style="margin-top:1rem;font-size:.85rem">
    Results will be shared by your administrator if applicable.
  </p>
  <a href="<?= BASE_URL ?>/student/logout.php" class="btn btn-home">Logout</a>
</div>

<?php elseif ($result): ?>
<?php $passed = $result['is_passed']; ?>
<div class="card">
  <div class="result-header">
    <div class="pass-badge <?= $passed ? 'passed' : 'failed' ?>">
      <?= $passed ? '🏆 PASSED' : '❌ FAILED' ?>
    </div>
    <h2 style="font-family:'Playfair Display',serif;font-size:1.6rem;margin-bottom:.4rem">
      <?= htmlspecialchars($student['full_name']) ?>
    </h2>
    <p style="color:var(--muted);font-size:.9rem"><?= htmlspecialchars($exam['title']) ?></p>

    <div class="score-circle <?= $passed ? 'passed' : 'failed' ?>">
      <div class="score-pct <?= $passed ? 'passed' : 'failed' ?>"><?= $result['percentage'] ?>%</div>
      <div class="score-label">Score</div>
      <div class="marks-big"><?= $result['marks_obtained'] ?> / <?= $exam['total_marks'] ?></div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-val" style="color:var(--passed)"><?= $result['correct'] ?></div>
      <div class="stat-lbl">✓ Correct</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:var(--failed)"><?= $result['wrong'] ?></div>
      <div class="stat-lbl">✕ Wrong</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:var(--muted)"><?= $result['skipped'] ?></div>
      <div class="stat-lbl">— Skipped</div>
    </div>
    <div class="stat-card">
      <div class="stat-val" style="color:var(--accent)"><?= $result['attempted'] ?></div>
      <div class="stat-lbl">Attempted</div>
    </div>
  </div>

  <div class="info-row"><span class="info-key">Total Questions</span><span class="info-val"><?= $result['total_questions'] ?></span></div>
  <div class="info-row"><span class="info-key">Marks Obtained</span><span class="info-val"><?= $result['marks_obtained'] ?></span></div>
  <div class="info-row"><span class="info-key">Passing Marks</span><span class="info-val"><?= $exam['passing_marks'] ?></span></div>
  <div class="info-row"><span class="info-key">Result</span>
    <span class="info-val" style="color:<?= $passed ? 'var(--passed)' : 'var(--failed)' ?>"><?= $passed ? 'PASS' : 'FAIL' ?></span>
  </div>
  <div class="info-row"><span class="info-key">Submitted At</span><span class="info-val"><?= date('d M Y, h:i A', strtotime($result['submitted_at'])) ?></span></div>

  <a href="<?= BASE_URL ?>/student/logout.php" class="btn btn-home">Logout</a>
</div>

<?php else: ?>
<div class="card" style="text-align:center;padding:4rem 2rem;">
  <div style="font-size:3rem;margin-bottom:1rem">⏳</div>
  <h2>Processing your results...</h2>
  <p class="subtitle">Please wait a moment.</p>
  <script>setTimeout(()=>location.reload(), 2000);</script>
</div>
<?php endif; ?>

</body>
</html>
