<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/questions.php';
requireStudentLogin();

$db        = getDB();
$studentId = (int)$_SESSION['student_id'];
$sessionId = (int)($_SESSION['session_id'] ?? 0);
$examId    = (int)($_SESSION['exam_id'] ?? 0);

if (!$sessionId || !$examId) {
    header('Location: ' . BASE_URL . '/student/instructions.php');
    exit;
}

try {
    $examStmt = $db->prepare('SELECT * FROM exams WHERE id=?');
    $examStmt->bind_param('i', $examId);
    $examStmt->execute();
    $exam = $examStmt->get_result()->fetch_assoc();
    $examStmt->close();

    $sesStmt = $db->prepare(
        'SELECT * FROM student_exam_sessions WHERE id=? AND student_id=? AND submitted_at IS NULL'
    );
    $sesStmt->bind_param('ii', $sessionId, $studentId);
    $sesStmt->execute();
    $session = $sesStmt->get_result()->fetch_assoc();
    $sesStmt->close();
} catch (mysqli_sql_exception $e) {
    handleDbException($e, [
        'context'  => 'exam_load',
        'redirect' => BASE_URL . '/student/instructions.php?err=system',
    ]);
}

if (!$session || !$exam) {
    header('Location: ' . BASE_URL . '/student/submit.php');
    exit;
}

// Calculate remaining time
$startedAt    = strtotime($session['started_at']);
$durationSec  = (int)$exam['duration_minutes'] * 60;
$elapsed      = time() - $startedAt;
$remaining    = max(0, $durationSec - $elapsed);

if ($remaining <= 0) {
    header('Location: ' . BASE_URL . '/student/submit.php?auto=1');
    exit;
}

// Load questions
$source    = $exam['question_source'];
$qOrder    = json_decode($session['question_order'], true);
$qids      = implode(',', array_map('intval', $qOrder));

if ($source === 'bank') {
    $qRes = $db->query("SELECT * FROM question_bank WHERE id IN ($qids)");
} else {
    $qRes = $db->query("SELECT * FROM ai_generated_questions WHERE id IN ($qids)");
}
$qMap = [];
while ($row = $qRes->fetch_assoc()) { $qMap[$row['id']] = $row; }

$questions = [];
foreach ($qOrder as $qid) {
    if (isset($qMap[$qid])) $questions[] = $qMap[$qid];
}

// Load all answers for this session
$ansRes = $db->query("SELECT * FROM student_answers WHERE session_id=$sessionId");
$answers = [];
while ($row = $ansRes->fetch_assoc()) {
    $answers[$row['question_id']] = $row;
}

$currentQ = max(0, min((int)($_GET['q'] ?? 0), count($questions) - 1));
$totalQ   = count($questions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($exam['title']) ?> – ExamPortal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<?php themeInitScript(); themeStylesheet(); themeScript(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root, [data-theme="dark"] {
  --navy:#0d1b2a; --sky:#1e88e5; --accent:#ffb703;
  --answered:#27ae60; --not-answered:#c0392b; --marked:#8e44ad;
  --ans-marked:#2980b9; --not-visited:#4a5568;
  --border:rgba(255,255,255,.1); --card:rgba(255,255,255,.04);
  --white:#f8f9fa; --radius:12px; --topbar-bg:#0a1520;
}
html,body { height:100%; overflow:hidden; user-select:none; }
body.student-exam {
  background:var(--navy); color:var(--white);
  font-family:'DM Sans',sans-serif; display:flex; flex-direction:column;
}
/* Topbar */
.topbar {
  display:flex; align-items:center; justify-content:space-between;
  padding:.7rem 1.5rem; background:var(--topbar-bg);
  border-bottom:1px solid var(--border); flex-shrink:0;
}
.exam-title { font-size:.95rem; font-weight:600; }
.timer {
  font-family:'DM Mono',monospace; font-size:1.15rem;
  background:rgba(255,183,3,.1); border:1px solid rgba(255,183,3,.3);
  border-radius:8px; padding:5px 16px; color:var(--accent);
  letter-spacing:.08em;
}
.timer.danger { background:rgba(192,57,43,.15); border-color:rgba(192,57,43,.5); color:#ff5252; animation:blink 1s infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.5} }
.marks-badge {
  font-size:.8rem; background:var(--card); border:1px solid var(--border);
  border-radius:8px; padding:4px 12px; color:rgba(255,255,255,.7);
}
.marks-badge span { color:var(--accent); font-weight:600; }

/* Main layout */
.main { display:flex; flex:1; overflow:hidden; }

/* Question area */
.q-area { flex:1; display:flex; flex-direction:column; overflow-y:auto; padding:1.5rem 2rem; }
.q-meta {
  display:flex; justify-content:space-between; align-items:center;
  margin-bottom:1rem;
}
.q-num { font-weight:700; font-size:1rem; }
.q-timer-mini { font-family:'DM Mono',monospace; font-size:.82rem; color:rgba(255,255,255,.4); }
.category-tag {
  display:inline-block; padding:2px 10px; border-radius:50px;
  font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em;
  margin-bottom:.8rem;
}
.cat-gk      { background:rgba(30,136,229,.2);  color:#64b5f6; }
.cat-english { background:rgba(39,174,96,.2);   color:#81c784; }
.cat-logical { background:rgba(155,89,182,.2);  color:#ce93d8; }

.q-text {
  font-size:1.05rem; line-height:1.7; margin-bottom:1.5rem;
  color:rgba(255,255,255,.9); font-weight:400;
}

/* Options */
.options { display:flex; flex-direction:column; gap:.7rem; margin-bottom:2rem; }
.option {
  display:flex; align-items:center; gap:14px;
  background:rgba(255,255,255,.04); border:2px solid rgba(255,255,255,.08);
  border-radius:var(--radius); padding:.9rem 1.2rem;
  cursor:pointer; transition:.18s; position:relative;
}
.option:hover { border-color:rgba(30,136,229,.4); background:rgba(30,136,229,.07); }
.option.selected {
  border-color:var(--sky); background:rgba(30,136,229,.15);
}
.option-key {
  width:32px; height:32px; border-radius:8px; flex-shrink:0;
  background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12);
  display:flex; align-items:center; justify-content:center;
  font-weight:700; font-size:.85rem;
  transition:.18s;
}
.option.selected .option-key { background:var(--sky); border-color:var(--sky); }
.option-text { font-size:.95rem; line-height:1.5; }

/* Action buttons */
.q-actions { display:flex; gap:.8rem; flex-wrap:wrap; padding-top:1rem; border-top:1px solid var(--border); margin-top:auto; }
.btn {
  padding:10px 20px; border-radius:var(--radius); font-family:'DM Sans',sans-serif;
  font-size:.88rem; font-weight:600; cursor:pointer; transition:.18s; border:none;
}
.btn-mark { background:rgba(155,89,182,.2); color:#ce93d8; border:1px solid rgba(155,89,182,.4); }
.btn-mark:hover { background:rgba(155,89,182,.35); }
.btn-clear { background:rgba(255,255,255,.06); color:rgba(255,255,255,.6); border:1px solid var(--border); }
.btn-clear:hover { background:rgba(255,255,255,.1); }
.btn-prev { background:rgba(255,255,255,.06); color:rgba(255,255,255,.7); border:1px solid var(--border); }
.btn-prev:hover { background:rgba(255,255,255,.1); }
.btn-next { background:linear-gradient(135deg,var(--sky),#1565c0); color:#fff; box-shadow:0 4px 16px rgba(30,136,229,.35); }
.btn-next:hover { transform:translateY(-1px); }
.btn-submit { background:linear-gradient(135deg,#27ae60,#1e8449); color:#fff; margin-left:auto; }
.btn-submit:hover { transform:translateY(-1px); }
.btn:disabled { opacity:.3; cursor:not-allowed; transform:none !important; }

/* Right palette */
.palette {
  width:260px; flex-shrink:0; border-left:1px solid var(--border);
  background:rgba(0,0,0,.2); overflow-y:auto; padding:1rem;
  display:flex; flex-direction:column; gap:1rem;
}
.palette-header { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.4); }
.legend { display:grid; grid-template-columns:1fr 1fr; gap:6px; }
.leg-item { display:flex; align-items:center; gap:6px; font-size:.72rem; color:rgba(255,255,255,.55); }
.leg-dot { width:22px;height:22px;border-radius:5px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700; }
.leg-not-visited  { background:var(--not-visited); }
.leg-not-answered { background:var(--not-answered); }
.leg-answered     { background:var(--answered); }
.leg-marked       { background:var(--marked); }
.leg-ans-marked   { background:var(--ans-marked); }

.palette-summary {
  display:flex; flex-wrap:wrap; gap:6px;
}
.pal-stat {
  display:flex; align-items:center; gap:5px; font-size:.75rem;
  background:rgba(255,255,255,.04); border:1px solid var(--border);
  border-radius:8px; padding:4px 8px;
}
.pal-dot { width:10px;height:10px;border-radius:50%;flex-shrink:0; }

.q-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:5px; }
.q-btn {
  aspect-ratio:1; border:none; border-radius:7px;
  font-size:.75rem; font-weight:700; cursor:pointer; transition:.15s;
  background:var(--not-visited); color:rgba(255,255,255,.5);
}
.q-btn:hover { filter:brightness(1.3); }
.q-btn.current { ring:2px solid white; box-shadow:0 0 0 2px var(--accent); }
.q-btn.not-answered { background:var(--not-answered); color:#fff; }
.q-btn.answered { background:var(--answered); color:#fff; }
.q-btn.marked { background:var(--marked); color:#fff; }
.q-btn.ans-marked { background:var(--ans-marked); color:#fff; }

/* Fullscreen overlay */
.fullscreen-warn {
  display:none; position:fixed; inset:0; z-index:9999;
  background:rgba(13,27,42,.97); align-items:center; justify-content:center; flex-direction:column;
  gap:1.5rem; text-align:center; padding:2rem;
}
.fullscreen-warn.show { display:flex; }
.warn-icon { font-size:4rem; }
.warn-title { font-size:1.8rem; font-weight:700; color:var(--accent); }
.warn-sub { color:rgba(255,255,255,.6); max-width:400px; line-height:1.7; }
.warn-count { font-size:3rem; font-weight:700; color:#ff5252; }
.btn-reenter { padding:14px 32px; background:var(--sky); color:#fff; border:none; border-radius:12px; font-size:1rem; font-weight:700; cursor:pointer; }

/* Submit modal */
.modal-overlay {
  display:none; position:fixed; inset:0; z-index:999;
  background:rgba(0,0,0,.7); backdrop-filter:blur(6px);
  align-items:center; justify-content:center;
}
.modal-overlay.show { display:flex; }
.modal {
  background:var(--modal-bg, #0d1b2a); border:1px solid var(--border);
  border-radius:20px; padding:2.5rem; max-width:440px; width:90%;
  box-shadow:0 32px 64px rgba(0,0,0,.5);
}
.modal h3 { font-size:1.4rem; margin-bottom:.5rem; }
.modal-stats { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; margin:1.2rem 0; }
.modal-stat {
  background:var(--card); border:1px solid var(--border);
  border-radius:10px; padding:.8rem; text-align:center;
}
.modal-stat-val { font-size:1.6rem; font-weight:700; }
.modal-stat-lbl { font-size:.72rem; color:rgba(255,255,255,.5); margin-top:2px; }
.modal-btns { display:flex; gap:.8rem; }
.btn-cancel { flex:1; background:transparent; border:1px solid var(--border); color:rgba(255,255,255,.6); }
.btn-confirm { flex:1; background:linear-gradient(135deg,#c0392b,#922b21); color:#fff; box-shadow:0 4px 16px rgba(192,57,43,.4); }
</style>
</head>
<body class="student-exam">
<!-- Fullscreen Warning -->
<div class="fullscreen-warn" id="fsWarn">
  <div class="warn-icon">🔒</div>
  <div class="warn-title">Exam Security Alert</div>
  <div class="warn-sub">You exited full-screen mode. This incident has been recorded. You have <strong id="warnCount">3</strong> warning(s) remaining before your exam is auto-submitted.</div>
  <button class="btn-reenter" onclick="reenterFullscreen()">🖥️ Return to Exam</button>
</div>

<!-- Submit Modal -->
<div class="modal-overlay" id="submitModal">
  <div class="modal">
    <h3>📤 Submit Exam?</h3>
    <p style="color:rgba(255,255,255,.6);font-size:.9rem;">Please review your progress before final submission:</p>
    <div class="modal-stats">
      <div class="modal-stat">
        <div class="modal-stat-val" style="color:var(--answered)" id="ms-answered">0</div>
        <div class="modal-stat-lbl">Answered</div>
      </div>
      <div class="modal-stat">
        <div class="modal-stat-val" style="color:var(--not-answered)" id="ms-notanswered">0</div>
        <div class="modal-stat-lbl">Not Answered</div>
      </div>
      <div class="modal-stat">
        <div class="modal-stat-val" style="color:var(--marked)" id="ms-marked">0</div>
        <div class="modal-stat-lbl">Marked for Review</div>
      </div>
      <div class="modal-stat">
        <div class="modal-stat-val" style="color:rgba(255,255,255,.4)" id="ms-notvisited">0</div>
        <div class="modal-stat-lbl">Not Visited</div>
      </div>
    </div>
    <div style="font-size:.82rem;color:rgba(255,183,3,.8);margin-bottom:1.2rem;">
      ⚠️ Once submitted, you cannot change your answers.
    </div>
    <div class="modal-btns">
      <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
      <form method="POST" action="<?= BASE_URL ?>/student/submit.php" style="flex:1">
        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
        <button type="submit" class="btn btn-confirm" style="width:100%">✓ Final Submit</button>
      </form>
    </div>
  </div>
</div>

<!-- Topbar -->
<div class="topbar">
  <div class="exam-title"><?= htmlspecialchars($exam['title']) ?></div>
  <div style="display:flex;align-items:center;gap:.8rem">
    <?php themeToggleButton('theme-toggle-inline'); ?>
    <div class="timer" id="globalTimer">00:00:00</div>
    <div class="marks-badge">+<span><?= $exam['marks_per_correct'] ?></span> / -<span style="color:#ff8a80"><?= $exam['negative_marks'] ?></span></div>
  </div>
</div>

<!-- Main -->
<div class="main">
  <div class="q-area" id="qArea">
    <?php
    $q    = $questions[$currentQ];
    $qid  = $q['id'];
    $ans  = $answers[$qid] ?? null;
    $sel  = $ans['selected_answer'] ?? '';
    $mark = (int)($ans['is_marked_review'] ?? 0);
    $cat  = $q['category'] ?? 'gk';
    $catLabels = ['gk'=>'General Knowledge','english'=>'Basic English','logical'=>'Logical Reasoning'];
    ?>
    <div class="q-meta">
      <div class="q-num">Question <?= $currentQ + 1 ?> of <?= $totalQ ?></div>
      <div class="q-timer-mini">⏱ Time per question tracked</div>
    </div>

    <div class="category-tag cat-<?= $cat ?>"><?= $catLabels[$cat] ?? $cat ?></div>

    <div class="q-text" id="qText">
      <?= nl2br(htmlspecialchars($q['question_text'])) ?>
    </div>

    <form id="answerForm">
      <input type="hidden" name="session_id" value="<?= $sessionId ?>">
      <input type="hidden" name="question_id" value="<?= $qid ?>">
      <input type="hidden" name="current_q" value="<?= $currentQ ?>">

      <div class="options" id="optionsContainer">
        <?php
        $opts = ['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']];
        foreach ($opts as $key => $text):
        ?>
        <div class="option <?= ($sel === $key) ? 'selected' : '' ?>"
             data-key="<?= $key ?>" onclick="selectOption('<?= $key ?>')">
          <div class="option-key"><?= $key ?></div>
          <div class="option-text"><?= htmlspecialchars($text) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="q-actions">
        <button type="button" class="btn btn-mark <?= $mark ? 'btn-next' : '' ?>"
                onclick="markReview()" id="markBtn">
          <?= $mark ? '🔖 Marked' : '🔖 Mark for Review' ?>
        </button>
        <button type="button" class="btn btn-clear" onclick="clearResponse()">⊘ Clear Response</button>
        <button type="button" class="btn btn-prev" onclick="navigate(<?= $currentQ - 1 ?>)"
                <?= $currentQ === 0 ? 'disabled' : '' ?>>← Prev</button>
        <button type="button" class="btn btn-next" onclick="saveAndNext()">Save & Next →</button>
        <button type="button" class="btn btn-submit" onclick="openModal()">Submit Test</button>
      </div>
    </form>
  </div>

  <!-- Right Palette -->
  <div class="palette">
    <div class="palette-header">Question Palette</div>

    <div class="legend">
      <div class="leg-item"><div class="leg-dot leg-not-visited">·</div> Not Visited</div>
      <div class="leg-item"><div class="leg-dot leg-not-answered">✕</div> Not Answered</div>
      <div class="leg-item"><div class="leg-dot leg-answered">✓</div> Answered</div>
      <div class="leg-item"><div class="leg-dot leg-marked">🔖</div> Marked</div>
      <div class="leg-item"><div class="leg-dot leg-ans-marked">✓</div> Ans+Marked</div>
    </div>

    <div class="palette-summary" id="palSummary"></div>

    <div class="palette-header">All Questions</div>
    <div class="q-grid" id="qGrid"></div>
  </div>
</div>

<script>
// ── State ──────────────────────────────────────────────────────────
const SESSION_ID   = <?= $sessionId ?>;
const TOTAL_Q      = <?= $totalQ ?>;
const EXAM_ID      = <?= $examId ?>;
const REMAINING    = <?= $remaining ?>;
const BASE_URL     = "<?= BASE_URL ?>";
const EXAM_DURATION = <?= $durationSec ?>;

// Build state from PHP
const qids = <?= json_encode($qOrder) ?>;
const answersState = {};
<?php foreach ($answers as $qid2 => $ans2): ?>
answersState[<?= $qid2 ?>] = {
  selected: "<?= addslashes($ans2['selected_answer'] ?? '') ?>",
  marked: <?= (int)$ans2['is_marked_review'] ?>,
  visited: true
};
<?php endforeach; ?>

let currentQ    = <?= $currentQ ?>;
let selectedAns = "<?= addslashes($sel) ?>";
let isMarked    = <?= $mark ?>;
let warnings    = 0;
const MAX_WARNINGS = 3;

// ── Timer ──────────────────────────────────────────────────────────
let remaining = REMAINING;
const timerEl = document.getElementById('globalTimer');

function fmtTime(s) {
  const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), ss = s%60;
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(ss).padStart(2,'0')}`;
}

const timerInterval = setInterval(() => {
  remaining--;
  timerEl.textContent = fmtTime(remaining);
  if (remaining <= 300) timerEl.classList.add('danger');
  if (remaining <= 0) { clearInterval(timerInterval); autoSubmit(); }
}, 1000);
timerEl.textContent = fmtTime(remaining);

function autoSubmit() {
  fetch(`${BASE_URL}/student/api_save.php`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ session_id:SESSION_ID, auto_submit:true })
  }).then(() => { window.location.href = `${BASE_URL}/student/submit.php?auto=1&session_id=${SESSION_ID}`; });
}

// ── Option selection ───────────────────────────────────────────────
function selectOption(key) {
  selectedAns = (selectedAns === key) ? '' : key;
  document.querySelectorAll('.option').forEach(o => {
    o.classList.toggle('selected', o.dataset.key === selectedAns);
  });
}

function clearResponse() {
  selectedAns = '';
  document.querySelectorAll('.option').forEach(o => o.classList.remove('selected'));
}

function markReview() {
  isMarked = !isMarked;
  const btn = document.getElementById('markBtn');
  btn.textContent = isMarked ? '🔖 Marked' : '🔖 Mark for Review';
  btn.classList.toggle('btn-next', isMarked);
}

// ── Save answer via API ────────────────────────────────────────────
function saveAnswer(callback) {
  const qid = qids[currentQ];
  fetch(`${BASE_URL}/student/api_save.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      session_id: SESSION_ID,
      question_id: qid,
      selected_answer: selectedAns || null,
      is_marked_review: isMarked ? 1 : 0
    })
  })
  .then(r => r.json())
  .then(d => {
    answersState[qid] = { selected: selectedAns, marked: isMarked ? 1 : 0, visited: true };
    updatePalette();
    if (callback) callback();
  })
  .catch(() => { if (callback) callback(); });
}

// ── Navigation ─────────────────────────────────────────────────────
function saveAndNext() {
  saveAnswer(() => navigate(currentQ + 1));
}

function navigate(idx) {
  if (idx < 0 || idx >= TOTAL_Q) return;
  saveAnswer(() => {
    window.location.href = `${BASE_URL}/student/exam.php?q=${idx}`;
  });
}

// ── Palette ────────────────────────────────────────────────────────
function getStatus(qid) {
  const a = answersState[qid];
  if (!a || !a.visited) return 'not-visited';
  if (a.selected && a.marked) return 'ans-marked';
  if (a.selected)  return 'answered';
  if (a.marked)    return 'marked';
  return 'not-answered';
}

function updatePalette() {
  const grid = document.getElementById('qGrid');
  const summary = document.getElementById('palSummary');

  let counts = {'answered':0,'not-answered':0,'marked':0,'ans-marked':0,'not-visited':0};
  grid.innerHTML = '';

  qids.forEach((qid, idx) => {
    const st = getStatus(qid);
    counts[st]++;
    const btn = document.createElement('button');
    btn.className = `q-btn ${st} ${idx === currentQ ? 'current' : ''}`;
    btn.textContent = idx + 1;
    btn.onclick = () => navigate(idx);
    grid.appendChild(btn);
  });

  summary.innerHTML = `
    <div class="pal-stat"><div class="pal-dot" style="background:var(--answered)"></div> ${counts.answered}</div>
    <div class="pal-stat"><div class="pal-dot" style="background:var(--not-answered)"></div> ${counts['not-answered']}</div>
    <div class="pal-stat"><div class="pal-dot" style="background:var(--marked)"></div> ${counts.marked}</div>
    <div class="pal-stat"><div class="pal-dot" style="background:rgba(255,255,255,.25)"></div> ${counts['not-visited']}</div>
  `;
}

// ── Modal ──────────────────────────────────────────────────────────
function openModal() {
  let ans=0,notAns=0,mrk=0,notVis=0;
  qids.forEach(qid => {
    const st = getStatus(qid);
    if (st==='answered'||st==='ans-marked') ans++;
    else if (st==='not-answered') notAns++;
    else if (st==='marked') mrk++;
    else notVis++;
  });
  document.getElementById('ms-answered').textContent    = ans;
  document.getElementById('ms-notanswered').textContent = notAns;
  document.getElementById('ms-marked').textContent      = mrk;
  document.getElementById('ms-notvisited').textContent  = notVis;
  document.getElementById('submitModal').classList.add('show');
}
function closeModal() { document.getElementById('submitModal').classList.remove('show'); }

// ── Fullscreen enforcement ─────────────────────────────────────────
function enterFullscreen() {
  const el = document.documentElement;
  if (el.requestFullscreen) el.requestFullscreen();
  else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
  else if (el.msRequestFullscreen) el.msRequestFullscreen();
}

function reenterFullscreen() {
  enterFullscreen();
  document.getElementById('fsWarn').classList.remove('show');
}

document.addEventListener('fullscreenchange', () => {
  if (!document.fullscreenElement) {
    warnings++;
    const rem = MAX_WARNINGS - warnings;
    document.getElementById('warnCount').textContent = rem;
    if (rem <= 0) { autoSubmit(); }
    else { document.getElementById('fsWarn').classList.add('show'); }
    // Log warning
    fetch(`${BASE_URL}/student/api_save.php`, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ session_id:SESSION_ID, log_violation:'fullscreen_exit', count:warnings })
    });
  }
});

// Enter fullscreen on load
window.addEventListener('load', () => { setTimeout(enterFullscreen, 800); });

// ── Keyboard lock ──────────────────────────────────────────────────
document.addEventListener('keydown', (e) => {
  // Allow only: Arrow keys for navigation, Escape shows warning
  const allowed = ['ArrowLeft','ArrowRight','Tab'];
  if (!allowed.includes(e.key)) {
    e.preventDefault();
    return false;
  }
  if (e.key === 'ArrowRight') saveAndNext();
  if (e.key === 'ArrowLeft' && currentQ > 0) navigate(currentQ - 1);
});

// ── Right-click disable ────────────────────────────────────────────
document.addEventListener('contextmenu', e => e.preventDefault());
document.addEventListener('copy',  e => e.preventDefault());
document.addEventListener('cut',   e => e.preventDefault());
document.addEventListener('paste', e => e.preventDefault());

// ── Prevent tab switching (visibility change) ──────────────────────
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    warnings++;
    if (warnings >= MAX_WARNINGS) autoSubmit();
  }
});

// ── Init palette ───────────────────────────────────────────────────
updatePalette();
// Mark current as visited
const curQid = qids[currentQ];
if (!answersState[curQid]) answersState[curQid] = { selected:'', marked:0, visited:true };
else answersState[curQid].visited = true;
updatePalette();
</script>
</body>
</html>
