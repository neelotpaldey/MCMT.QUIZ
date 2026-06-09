<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();
$db = getDB();

$examId = (int) ($_GET['id'] ?? $_POST['exam_id'] ?? 0);
if (!$examId) {
    header('Location: ' . BASE_URL . '/admin/manage_exams.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->bind_param('i', $examId);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exam) {
    header('Location: ' . BASE_URL . '/admin/manage_exams.php');
    exit;
}

$liveLocked   = (int) $exam['is_started'] === 1;
$sourceLocked = $liveLocked || examHasStudentSessions($db, $examId);
$locked       = $sourceLocked;

$pageTitle = 'Edit Exam';
$pageKey   = 'manage_exams';
$success   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = sanitize($db, $_POST['title'] ?? '');
    $desc         = sanitize($db, $_POST['description'] ?? '');
    $duration     = (int) ($_POST['duration'] ?? 30);
    $totalMarks   = (int) ($_POST['total_marks'] ?? 0);
    $passingMark  = (int) ($_POST['passing_marks'] ?? 0);
    $posMarks     = (float) ($_POST['marks_correct'] ?? 2);
    $negMarks     = (float) ($_POST['marks_negative'] ?? 0);
    $instructions = sanitize($db, $_POST['instructions'] ?? '');
    $showResults  = isset($_POST['show_results']) ? 1 : 0;

    if ($liveLocked) {
        $totalQ = (int) $exam['total_questions'];
        $gkQ    = (int) $exam['gk_questions'];
        $enQ    = (int) $exam['english_questions'];
        $logQ   = (int) $exam['logical_questions'];
    } else {
        $totalQ = (int) ($_POST['total_questions'] ?? 20);
        $gkQ    = (int) ($_POST['gk_questions'] ?? 0);
        $enQ    = (int) ($_POST['english_questions'] ?? 0);
        $logQ   = (int) ($_POST['logical_questions'] ?? 0);
    }

    if ($sourceLocked) {
        $source   = $exam['question_source'];
        $apiKey   = $exam['api_key'] ?? '';
        $apiModel = $exam['api_model'] ?? '';
    } else {
        $source   = sanitize($db, $_POST['question_source'] ?? 'bank');
        $apiKey   = sanitize($db, $_POST['api_key'] ?? '');
        $apiModel = sanitize($db, $_POST['api_model'] ?? '');
    }

    if (!$title) {
        $error = 'Exam title is required.';
    } elseif ($gkQ + $enQ + $logQ !== $totalQ) {
        $error = "Category questions ($gkQ+$enQ+$logQ) must equal total questions ($totalQ).";
    } elseif (!$sourceLocked && $source !== 'bank' && !$apiKey) {
        $error = 'API key is required for AI-generated questions.';
    } else {
        if ($totalMarks === 0) {
            $totalMarks = $totalQ * (int) $posMarks;
        }

        try {
            $stmt = $db->prepare(
                'UPDATE exams SET title=?, description=?, duration_minutes=?, total_questions=?,
                 gk_questions=?, english_questions=?, logical_questions=?,
                 total_marks=?, passing_marks=?, marks_per_correct=?, negative_marks=?,
                 question_source=?, api_key=?, api_model=?, instructions=?, show_results=?
                 WHERE id=?'
            );
            $stmt->bind_param(
                'ssiiiiiiiddssssii',
                $title, $desc, $duration, $totalQ, $gkQ, $enQ, $logQ,
                $totalMarks, $passingMark, $posMarks, $negMarks,
                $source, $apiKey, $apiModel, $instructions, $showResults, $examId
            );
            $stmt->execute();
            $stmt->close();

            $success = 'Exam updated successfully.';
            $stmt2 = $db->prepare('SELECT * FROM exams WHERE id = ?');
            $stmt2->bind_param('i', $examId);
            $stmt2->execute();
            $exam = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            $liveLocked   = (int) $exam['is_started'] === 1;
            $sourceLocked = $liveLocked || examHasStudentSessions($db, $examId);
            $locked       = $sourceLocked;
        } catch (mysqli_sql_exception $e) {
            logDbError($e, 'edit_exam');
            $error = 'Could not save exam. Please try again.';
        }
    }
}

$e = $exam;
$sourceVal = $e['question_source'] ?? 'bank';

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">✏️ Edit Exam</div>
    <a href="manage_exams.php"><button class="btn btn-outline">← Manage Exams</button></a>
  </div>
  <div class="page-body">
    <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($liveLocked): ?>
    <div class="alert alert-info">ℹ️ This exam is live — question counts cannot be changed until you stop the exam.</div>
    <?php elseif ($sourceLocked): ?>
    <div class="alert alert-info">ℹ️ Students have taken this exam — question source is locked. You can still change question counts, title, marks, and instructions.</div>
    <?php endif; ?>

    <form method="POST" id="examForm">
      <input type="hidden" name="exam_id" value="<?= (int) $examId ?>">
      <div style="display:grid;grid-template-columns:1fr 380px;gap:1.2rem">
        <div style="display:flex;flex-direction:column;gap:1.2rem">
          <div class="card">
            <div class="card-title" style="margin-bottom:1.2rem">📋 Exam Details</div>
            <div class="form-group">
              <label>Exam Title *</label>
              <input class="form-control" type="text" name="title" value="<?= htmlspecialchars($e['title']) ?>" required>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($e['description'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Duration (minutes) *</label>
                <input class="form-control" type="number" name="duration" value="<?= (int) $e['duration_minutes'] ?>" min="5" max="300" required>
              </div>
              <div class="form-group">
                <label>Total Questions *</label>
                <input class="form-control" type="number" name="total_questions" id="totalQ" value="<?= (int) $e['total_questions'] ?>" min="1" max="100" required <?= $liveLocked ? 'readonly' : '' ?>>
              </div>
            </div>
            <div class="form-row three">
              <div class="form-group">
                <label>Total Marks</label>
                <input class="form-control" type="number" name="total_marks" id="totalMarks" value="<?= (int) $e['total_marks'] ?>" min="1">
              </div>
              <div class="form-group">
                <label>Passing Marks</label>
                <input class="form-control" type="number" name="passing_marks" id="passingMarks" value="<?= (int) $e['passing_marks'] ?>" min="1">
              </div>
              <div class="form-group">
                <label>+Mark / Question</label>
                <input class="form-control" type="number" step="0.5" name="marks_correct" id="marksPer" value="<?= htmlspecialchars($e['marks_per_correct']) ?>" min="0.5" max="10">
              </div>
            </div>
            <div class="form-group">
              <label>Negative Marking (per wrong)</label>
              <input class="form-control" type="number" step="0.25" name="marks_negative" value="<?= htmlspecialchars($e['negative_marks']) ?>" min="0" max="5">
            </div>
            <div class="form-group">
              <label>Special Instructions (shown to students)</label>
              <textarea class="form-control" name="instructions" rows="3"><?= htmlspecialchars($e['instructions'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.92rem;color:var(--white)">
                <input type="checkbox" name="show_results" value="1" <?= (int) ($e['show_results'] ?? 1) === 1 ? 'checked' : '' ?>
                       style="width:18px;height:18px;margin-top:3px;accent-color:var(--sky)">
                <span>
                  <strong>Show results to students after submit</strong><br>
                  <span style="color:var(--muted);font-size:.85rem;font-weight:400">
                    When off, students only see an “Exam Submitted” message (no score or pass/fail).
                  </span>
                </span>
              </label>
            </div>
          </div>

          <div class="card" <?= $sourceLocked ? 'style="opacity:.85"' : '' ?>>
            <div class="card-title" style="margin-bottom:1.2rem">🤖 Question Source</div>
            <?php if ($sourceLocked): ?>
            <div class="alert alert-info">
              Source: <strong><?= ['bank'=>'Question Bank','gemini'=>'Gemini AI','groq'=>'Groq AI'][$sourceVal] ?? $sourceVal ?></strong>
              — locked because students have already taken this exam.
            </div>
            <?php else: ?>
            <div class="tabs" id="sourceTabs">
              <div class="tab <?= $sourceVal === 'bank' ? 'active' : '' ?>" onclick="setSource('bank')">🗃️ Question Bank</div>
              <div class="tab <?= $sourceVal === 'gemini' ? 'active' : '' ?>" onclick="setSource('gemini')">✨ Google Gemini</div>
              <div class="tab <?= $sourceVal === 'groq' ? 'active' : '' ?>" onclick="setSource('groq')">⚡ Groq AI</div>
            </div>
            <input type="hidden" name="question_source" id="sourceInput" value="<?= htmlspecialchars($sourceVal) ?>">
            <div id="bankSection" style="display:<?= $sourceVal === 'bank' ? 'block' : 'none' ?>">
              <div class="alert alert-info">Questions are pulled from your question bank per student session.</div>
            </div>
            <div id="geminiSection" style="display:<?= $sourceVal === 'gemini' ? 'block' : 'none' ?>">
              <div class="form-group">
                <label>Gemini API Key *</label>
                <input class="form-control secret-field" type="password" name="api_key" id="geminiKey" value="<?= htmlspecialchars($e['api_key'] ?? '') ?>" placeholder="AIza..." autocomplete="off">
              </div>
              <div class="form-group">
                <label>Model</label>
                <select class="form-control" name="api_model" id="geminiModel">
                  <?php foreach (getGeminiModelOptions() as $val => $label): ?>
                  <option value="<?= htmlspecialchars($val) ?>" <?= ($e['api_model'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div id="groqSection" style="display:<?= $sourceVal === 'groq' ? 'block' : 'none' ?>">
              <div class="form-group">
                <label>Groq API Key *</label>
                <input class="form-control secret-field" type="password" name="api_key" id="groqKey" value="<?= htmlspecialchars($e['api_key'] ?? '') ?>" placeholder="gsk_..." autocomplete="off">
              </div>
              <div class="form-group">
                <label>Model</label>
                <select class="form-control" name="api_model" id="groqModel">
                  <?php foreach ([
                      'llama3-8b-8192' => 'llama3-8b-8192 (Fast)',
                      'llama3-70b-8192' => 'llama3-70b-8192 (Accurate)',
                      'mixtral-8x7b-32768' => 'mixtral-8x7b-32768',
                      'gemma2-9b-it' => 'gemma2-9b-it',
                  ] as $val => $label): ?>
                  <option value="<?= $val ?>" <?= ($e['api_model'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div>
          <div class="card" style="position:sticky;top:0">
            <div class="card-title" style="margin-bottom:1.2rem">📊 Question Distribution</div>
            <div class="alert alert-info" id="qAlert" style="font-size:.8rem;margin-bottom:1rem">
              Total must equal <strong id="qTotal"><?= (int) $e['total_questions'] ?></strong> questions
            </div>
            <div class="form-group">
              <label>🌍 General Knowledge</label>
              <input class="form-control" type="number" name="gk_questions" id="gkQ" value="<?= (int) $e['gk_questions'] ?>" min="0" <?= $liveLocked ? 'readonly' : 'oninput="onCategoryChange()" onchange="onCategoryChange()"' ?>>
            </div>
            <div class="form-group">
              <label>📝 Basic English</label>
              <input class="form-control" type="number" name="english_questions" id="enQ" value="<?= (int) $e['english_questions'] ?>" min="0" <?= $liveLocked ? 'readonly' : 'oninput="onCategoryChange()" onchange="onCategoryChange()"' ?>>
            </div>
            <div class="form-group">
              <label>🧠 Logical Reasoning</label>
              <input class="form-control" type="number" name="logical_questions" id="logQ" value="<?= (int) $e['logical_questions'] ?>" min="0" <?= $liveLocked ? 'readonly' : 'oninput="onCategoryChange()" onchange="onCategoryChange()"' ?>>
            </div>
            <div style="background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;padding:.8rem;margin-bottom:1.2rem">
              <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.5rem">
                <span>Assigned</span><span id="qAssigned" style="color:var(--accent)">0</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:.85rem">
                <span>Remaining</span><span id="qRemaining" style="color:#81c784">0</span>
              </div>
            </div>
            <div style="height:8px;border-radius:4px;background:rgba(255,255,255,.1);overflow:hidden;margin-bottom:1.2rem;display:flex">
              <div id="barGk" style="background:#64b5f6;transition:.3s"></div>
              <div id="barEn" style="background:#81c784;transition:.3s"></div>
              <div id="barLog" style="background:#ce93d8;transition:.3s"></div>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;padding:13px">💾 Save Changes</button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
</div>

<script>
const examLiveLocked = <?= $liveLocked ? 'true' : 'false' ?>;
const examSourceLocked = <?= $sourceLocked ? 'true' : 'false' ?>;

function setSource(src) {
  if (examSourceLocked) return;
  document.getElementById('sourceInput').value = src;
  document.querySelectorAll('.tab').forEach((t, i) => {
    t.classList.toggle('active', ['bank', 'gemini', 'groq'][i] === src);
  });
  document.getElementById('bankSection').style.display = src === 'bank' ? 'block' : 'none';
  document.getElementById('geminiSection').style.display = src === 'gemini' ? 'block' : 'none';
  document.getElementById('groqSection').style.display = src === 'groq' ? 'block' : 'none';
}

function formatMarkValue(value) {
  const rounded = Math.round(value * 100) / 100;
  return Number.isInteger(rounded) ? rounded : rounded.toFixed(1);
}

function updateTotalMarks() {
  const total = parseInt(document.getElementById('totalQ').value, 10) || 0;
  const mpp = parseFloat(document.getElementById('marksPer').value) || 2;
  document.getElementById('totalMarks').value = formatMarkValue(total * mpp);
  document.getElementById('passingMarks').value = formatMarkValue(total * mpp / 2);
}

function onCategoryChange() {
  if (examLiveLocked) return;
  const gk = parseInt(document.getElementById('gkQ').value, 10) || 0;
  const en = parseInt(document.getElementById('enQ').value, 10) || 0;
  const log = parseInt(document.getElementById('logQ').value, 10) || 0;
  document.getElementById('totalQ').value = gk + en + log;
  updateQCount();
}

function updateQCount() {
  const total = parseInt(document.getElementById('totalQ').value, 10) || 0;
  const gk = parseInt(document.getElementById('gkQ').value, 10) || 0;
  const en = parseInt(document.getElementById('enQ').value, 10) || 0;
  const log = parseInt(document.getElementById('logQ').value, 10) || 0;
  const assigned = gk + en + log;
  const remaining = total - assigned;

  document.getElementById('qTotal').textContent = total;
  document.getElementById('qAssigned').textContent = assigned;
  document.getElementById('qRemaining').textContent = remaining;
  document.getElementById('qRemaining').style.color = remaining === 0 ? '#81c784' : '#ef9a9a';

  const al = document.getElementById('qAlert');
  al.className = 'alert ' + (remaining === 0 ? 'alert-success' : 'alert-error');
  al.innerHTML = remaining === 0
    ? `✅ All <strong>${total}</strong> questions assigned`
    : `⚠️ ${Math.abs(remaining)} question(s) ${remaining > 0 ? 'still unassigned' : 'over-assigned'}`;

  if (total > 0) {
    document.getElementById('barGk').style.width = (gk / total * 100) + '%';
    document.getElementById('barEn').style.width = (en / total * 100) + '%';
    document.getElementById('barLog').style.width = (log / total * 100) + '%';
  }
}

if (!examLiveLocked) {
  document.getElementById('totalQ').addEventListener('input', updateQCount);
  document.getElementById('totalQ').addEventListener('change', updateQCount);
  document.getElementById('marksPer').addEventListener('input', updateTotalMarks);
  document.getElementById('marksPer').addEventListener('change', updateTotalMarks);
}
updateQCount();
</script>
</body></html>
