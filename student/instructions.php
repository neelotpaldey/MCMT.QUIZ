<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireStudentLogin();
$db = getDB();

$exam = null;
$existingSession = null;
$loadError = '';

$errMessages = [
    'no_exam'      => 'No exam was selected. Please try again.',
    'inactive'     => 'This exam is no longer active.',
    'not_started'  => 'The exam has not been started yet. Please wait for the administrator.',
    'no_questions' => 'Could not load questions for this exam. Contact the administrator.',
    'system'       => 'A system error occurred. Please try again or contact the administrator.',
];

$pageError = $errMessages[$_GET['err'] ?? ''] ?? '';

try {
    $examRes = $db->query('SELECT * FROM exams WHERE is_active=1 AND is_started=1 ORDER BY started_at DESC LIMIT 1');
    $exam = $examRes->fetch_assoc();

    $studentId   = (int) $_SESSION['student_id'];
    $studentName = $_SESSION['student_name'];

    if ($exam) {
        clearStaleStudentExamSession($db, $studentId, (int) $exam['id'], $exam);
        $existingSession = getCurrentStudentExamSession($db, $studentId, $exam);
    }
} catch (mysqli_sql_exception $e) {
    logDbError($e, 'instructions');
    $loadError = 'Unable to load exam information. Please refresh the page or contact support.';
    $studentName = $_SESSION['student_name'] ?? 'Student';
}

$step = (int) ($_GET['step'] ?? 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instructions – ExamPortal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<?php themeInitScript(); themeStylesheet(); themeScript(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
:root, [data-theme="dark"] {
  --navy: #0d1b2a; --ocean: #1a3a5c; --sky: #1e88e5;
  --accent: #ffb703; --white: #f8f9fa;
  --border: rgba(255,255,255,0.1); --card: rgba(255,255,255,0.04);
  --radius: 14px;
}
body.student-page {
  min-height:100vh; background:var(--navy);
  background-image: radial-gradient(ellipse 80% 60% at 20% -10%, rgba(30,136,229,.3) 0%, transparent 55%);
  font-family:'DM Sans',sans-serif; color:var(--white); display:flex; flex-direction:column;
}
.topbar {
  display:flex; align-items:center; justify-content:space-between;
  padding:1rem 2rem; background:rgba(13,27,42,0.9);
  border-bottom:1px solid var(--border); backdrop-filter:blur(12px);
  position:sticky; top:0; z-index:100;
}
.logo { font-family:'Playfair Display',serif; font-size:1.3rem; }
.logo span { color:var(--accent); }
.student-pill {
  background:var(--card); border:1px solid var(--border);
  border-radius:50px; padding:6px 16px;
  font-size:0.85rem; display:flex; align-items:center; gap:8px;
}
.avatar { width:28px;height:28px;background:var(--sky);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px; }
.main { flex:1; display:flex; padding:2rem; gap:2rem; max-width:1200px; margin:0 auto; width:100%; }
.content-panel {
  flex:1; background:var(--card); border:1px solid var(--border);
  border-radius:20px; padding:2.5rem; overflow-y:auto; max-height:calc(100vh - 120px);
}
.sidebar {
  width:240px; display:flex; flex-direction:column; gap:1rem;
}
.sidebar-card {
  background:var(--card); border:1px solid var(--border);
  border-radius:var(--radius); padding:1.2rem;
}
.sidebar-card h4 { font-size:0.8rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.5); margin-bottom:.8rem; }
.stat-row { display:flex; justify-content:space-between; font-size:0.88rem; padding:4px 0; }
.stat-val { color:var(--accent); font-weight:600; }
.section-title { font-family:'Playfair Display',serif; font-size:1.6rem; margin-bottom:1.5rem; }
.step-tabs { display:flex; gap:8px; margin-bottom:2rem; }
.step-tab {
  padding:6px 18px; border-radius:50px; font-size:0.85rem; font-weight:500; cursor:pointer;
  border:1px solid var(--border); transition:.2s;
}
.step-tab.active { background:var(--sky); border-color:var(--sky); color:#fff; }
.step-tab.done { background:rgba(30,136,229,.15); border-color:rgba(30,136,229,.4); color:rgba(255,255,255,.8); }

/* Legend symbols */
.legend-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:1.5rem 0; }
.legend-item { display:flex; align-items:center; gap:10px; font-size:0.88rem; }
.badge {
  width:34px;height:34px; border-radius:8px; display:flex;align-items:center;justify-content:center;
  font-size:13px; font-weight:700; flex-shrink:0;
}
.badge.not-visited { background:#555; color:#aaa; }
.badge.not-answered { background:#c0392b; color:#fff; }
.badge.answered { background:#27ae60; color:#fff; }
.badge.marked { background:#8e44ad; color:#fff; }
.badge.answered-marked { background:#2980b9; color:#fff; position:relative; }
.badge.answered-marked::after { content:'✓'; position:absolute; bottom:-4px; right:-4px; font-size:10px; background:#27ae60; border-radius:50%; width:16px;height:16px;display:flex;align-items:center;justify-content:center; }

.instruction-list { list-style:none; }
.instruction-list li {
  display:flex; align-items:flex-start; gap:12px;
  padding:10px 0; border-bottom:1px solid rgba(255,255,255,.05);
  font-size:0.92rem; line-height:1.6; color:rgba(255,255,255,.85);
}
.instruction-list li::before { content:'•'; color:var(--sky); font-size:1.2rem; flex-shrink:0; margin-top:1px; }

.warning-box {
  background:rgba(255,183,3,.08); border:1px solid rgba(255,183,3,.3);
  border-radius:var(--radius); padding:14px 18px;
  font-size:0.88rem; color:rgba(255,183,3,.9); margin:1.5rem 0;
}

.agreement {
  background:rgba(30,136,229,.07); border:1px solid rgba(30,136,229,.2);
  border-radius:var(--radius); padding:1.2rem 1.5rem;
  font-size:0.85rem; color:rgba(255,255,255,.7); line-height:1.7; margin-top:1.5rem;
}
label.agree-label { display:flex; gap:10px; align-items:flex-start; cursor:pointer; }
label.agree-label input { margin-top:3px; width:16px;height:16px; accent-color:var(--sky); }

.footer-bar {
  display:flex; justify-content:space-between; align-items:center;
  padding:1.2rem 2rem; border-top:1px solid var(--border);
  background:rgba(13,27,42,0.9); backdrop-filter:blur(12px);
  position:sticky; bottom:0;
}
.btn {
  padding:12px 28px; border-radius:var(--radius); font-family:'DM Sans',sans-serif;
  font-size:0.95rem; font-weight:600; cursor:pointer; transition:.2s;
  border:none; letter-spacing:.02em;
}
.btn-primary {
  background:linear-gradient(135deg,var(--sky),#1565c0);
  color:#fff; box-shadow:0 6px 20px rgba(30,136,229,.4);
}
.btn-primary:hover { transform:translateY(-1px); }
.btn-outline {
  background:transparent; color:rgba(255,255,255,.7);
  border:1px solid var(--border);
}
.btn-outline:hover { border-color:rgba(255,255,255,.3); }
.btn:disabled { opacity:.4; cursor:not-allowed; transform:none !important; }
.btn-start {
  background:linear-gradient(135deg,#27ae60,#1e8449);
  color:#fff; box-shadow:0 6px 20px rgba(39,174,96,.4); font-size:1rem;
}
.btn-start:hover { transform:translateY(-1px); }

.no-exam {
  text-align:center; padding:4rem 2rem;
}
.no-exam .emoji { font-size:4rem; margin-bottom:1rem; }
</style>
</head>
<body class="student-page">
<div class="topbar">
  <div class="logo">Exam<span>Portal</span></div>
  <div style="display:flex;align-items:center;gap:.8rem">
  <?php themeToggleButton('theme-toggle-inline'); ?>
  <div class="student-pill">
    <div class="avatar">👤</div>
    <?= htmlspecialchars($studentName) ?>
    &nbsp;|&nbsp;
    <a href="<?= BASE_URL ?>/student/logout.php" style="color:rgba(255,255,255,.5);font-size:.8rem;text-decoration:none;">Logout</a>
  </div>
  </div>
</div>

<div class="main">
  <div class="content-panel">
    <?php if ($pageError || $loadError): ?>
      <div class="warning-box" style="margin-bottom:1.5rem;">
        ⚠️ <?= htmlspecialchars($pageError ?: $loadError) ?>
      </div>
    <?php endif; ?>

    <?php if ($existingSession && !$existingSession['submitted_at']): ?>
      <div class="warning-box" style="margin-bottom:1.5rem;background:rgba(30,136,229,.08);border-color:rgba(30,136,229,.3);color:rgba(255,255,255,.85);">
        You have an exam in progress.
        <a href="<?= BASE_URL ?>/student/exam.php" style="color:var(--accent);font-weight:600;">Resume exam →</a>
      </div>
    <?php elseif ($existingSession && $existingSession['submitted_at']): ?>
      <div class="warning-box" style="margin-bottom:1.5rem;background:rgba(39,174,96,.08);border-color:rgba(39,174,96,.3);color:rgba(255,255,255,.85);">
        You have already completed this exam.
        <a href="<?= BASE_URL ?>/student/submit.php" style="color:var(--accent);font-weight:600;">View result →</a>
      </div>
    <?php endif; ?>

    <?php if (!$exam): ?>
      <div class="no-exam">
        <div class="emoji">🕐</div>
        <h2>No Active Exam</h2>
        <p style="color:rgba(255,255,255,.5);margin-top:.5rem;">Please wait for the administrator to start an exam.</p>
      </div>
    <?php elseif ($step === 1): ?>
      <div class="step-tabs">
        <div class="step-tab active">Step 1 · General Instructions</div>
        <div class="step-tab">Step 2 · Rules & Agreement</div>
      </div>
      <h2 class="section-title">📋 General Instructions</h2>
      <p style="color:rgba(255,255,255,.6);margin-bottom:1.5rem;">Please read all instructions carefully before beginning the exam.</p>

      <ul class="instruction-list">
        <li>Total duration of examination is <strong><?= $exam['duration_minutes'] ?> minutes</strong>.</li>
        <li>The clock will be set at the server. The countdown timer in the top-right corner will display remaining time.</li>
        <li>When the timer reaches zero, the examination will end automatically.</li>
        <li>Total Questions: <strong><?= $exam['total_questions'] ?></strong> &nbsp;|&nbsp; Total Marks: <strong><?= $exam['total_marks'] ?></strong></li>
        <li>Marks for correct answer: <strong>+<?= $exam['marks_per_correct'] ?></strong> &nbsp;|&nbsp; Negative marking: <strong>-<?= $exam['negative_marks'] ?></strong></li>
      </ul>

      <h3 style="margin:1.5rem 0 1rem;font-size:1.1rem;">Question Palette Legend</h3>
      <div class="legend-grid">
        <div class="legend-item"><div class="badge not-visited">1</div> Not visited yet</div>
        <div class="legend-item"><div class="badge not-answered">1</div> Not answered</div>
        <div class="legend-item"><div class="badge answered">1</div> Answered</div>
        <div class="legend-item"><div class="badge marked">1</div> Marked for Review (not answered)</div>
        <div class="legend-item"><div class="badge answered-marked">1</div> Answered & Marked for Review</div>
      </div>

      <h3 style="margin:1.5rem 0 1rem;font-size:1.1rem;">Navigating a Question</h3>
      <ul class="instruction-list">
        <li>To select your answer, click the button of one of the options.</li>
        <li>To deselect, click the same option again or click <strong>Clear Response</strong>.</li>
        <li>To save and move forward, click <strong>Save & Next</strong>.</li>
        <li>To flag a question for later, click <strong>Mark for Review & Next</strong>.</li>
        <li>Answered & Marked questions <em>will</em> be evaluated.</li>
        <li>Marked-only (not answered) questions will <em>not</em> be evaluated.</li>
      </ul>

      <?php if ($exam['instructions']): ?>
        <h3 style="margin:1.5rem 0 1rem;font-size:1.1rem;">Exam-Specific Instructions</h3>
        <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.2rem;line-height:1.8;font-size:.9rem;color:rgba(255,255,255,.8);">
          <?= nl2br(htmlspecialchars($exam['instructions'])) ?>
        </div>
      <?php endif; ?>

    <?php elseif ($step === 2): ?>
      <div class="step-tabs">
        <div class="step-tab done">✓ Step 1 · General Instructions</div>
        <div class="step-tab active">Step 2 · Rules & Agreement</div>
      </div>
      <h2 class="section-title">📜 Rules & Agreement</h2>

      <ul class="instruction-list">
        <li>Ensure you have a stable internet connection before starting.</li>
        <li>You <strong>must attempt all the questions</strong>.</li>
        <li>Some questions may have negative marking — attempt carefully.</li>
        <li>The exam must be completed within the allotted time.</li>
        <li>Do <strong>not</strong> close the browser or exit full-screen during the exam.</li>
        <li>Any attempt to copy, print, or take screenshots is strictly prohibited.</li>
        <li>Submit your answers before the time limit expires.</li>
        <li>Read each question carefully and double-check your answers before submitting.</li>
        <li>Right-click and keyboard shortcuts (Copy/Paste) are disabled during the exam.</li>
        <li>Exiting full-screen will generate a warning; repeated violations may auto-submit your paper.</li>
      </ul>

      <div class="warning-box">
        ⚠️ Using unfair means of any sort will lead to <strong>immediate disqualification</strong> and a ban from future examinations.
      </div>

      <form method="POST" action="<?= BASE_URL ?>/student/start_exam.php" id="startForm">
        <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
        <div class="agreement">
          <label class="agree-label">
            <input type="checkbox" id="agreeCheck" required <?= ($existingSession && $existingSession['submitted_at']) ? 'disabled' : '' ?>>
            I have read all instructions carefully and have understood them. I agree not to cheat or use unfair means in this examination. I understand that using unfair means of any sort for my own or someone else's advantage will lead to my immediate disqualification.
          </label>
        </div>
        <br>
        <?php if ($existingSession && $existingSession['submitted_at']): ?>
          <a href="<?= BASE_URL ?>/student/submit.php" class="btn btn-start" style="display:inline-block;text-decoration:none;text-align:center;">View your result</a>
        <?php elseif (!(int) $exam['is_started']): ?>
          <button type="button" class="btn btn-start" disabled>⏳ Waiting for exam to start</button>
        <?php else: ?>
        <button type="submit" class="btn btn-start" id="startBtn" disabled>🚀 I am ready to begin</button>
        <?php endif; ?>
      </form>
      <script>
        (function () {
          var agree = document.getElementById('agreeCheck');
          var startBtn = document.getElementById('startBtn');
          if (!agree || !startBtn) return;
          agree.addEventListener('change', function () {
            startBtn.disabled = !this.checked;
          });
        })();
      </script>
    <?php endif; ?>
  </div>

  <div class="sidebar">
    <?php if ($exam): ?>
    <div class="sidebar-card">
      <h4>Exam Info</h4>
      <div class="stat-row"><span>Exam</span><span class="stat-val" style="max-width:120px;text-align:right;font-size:.78rem;"><?= htmlspecialchars($exam['title']) ?></span></div>
      <div class="stat-row"><span>Duration</span><span class="stat-val"><?= $exam['duration_minutes'] ?> min</span></div>
      <div class="stat-row"><span>Questions</span><span class="stat-val"><?= $exam['total_questions'] ?></span></div>
      <div class="stat-row"><span>Total Marks</span><span class="stat-val"><?= $exam['total_marks'] ?></span></div>
      <div class="stat-row"><span>Passing</span><span class="stat-val"><?= $exam['passing_marks'] ?></span></div>
      <div class="stat-row"><span>+Marks</span><span class="stat-val">+<?= $exam['marks_per_correct'] ?></span></div>
      <div class="stat-row"><span>-Marks</span><span class="stat-val">-<?= $exam['negative_marks'] ?></span></div>
    </div>
    <div class="sidebar-card">
      <h4>Categories</h4>
      <?php if ($exam['gk_questions'] > 0): ?>
      <div class="stat-row"><span>General Knowledge</span><span class="stat-val"><?= $exam['gk_questions'] ?>Q</span></div>
      <?php endif; ?>
      <?php if ($exam['english_questions'] > 0): ?>
      <div class="stat-row"><span>Basic English</span><span class="stat-val"><?= $exam['english_questions'] ?>Q</span></div>
      <?php endif; ?>
      <?php if ($exam['logical_questions'] > 0): ?>
      <div class="stat-row"><span>Logical Reasoning</span><span class="stat-val"><?= $exam['logical_questions'] ?>Q</span></div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="sidebar-card">
      <h4>Status</h4>
      <p style="font-size:.85rem;color:rgba(255,255,255,.5);">Waiting for exam to start...</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="footer-bar">
  <?php if ($step === 1): ?>
    <span style="color:rgba(255,255,255,.4);font-size:.85rem;">Step 1 of 2</span>
    <?php if ($exam): ?>
      <a href="?step=2"><button class="btn btn-primary">Next →</button></a>
    <?php else: ?>
      <button class="btn btn-primary" disabled>No Active Exam</button>
    <?php endif; ?>
  <?php else: ?>
    <a href="?step=1"><button class="btn btn-outline">← Previous</button></a>
    <span style="color:rgba(255,255,255,.4);font-size:.85rem;">Step 2 of 2</span>
  <?php endif; ?>
</div>
</body>
</html>
