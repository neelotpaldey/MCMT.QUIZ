<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Create Exam'; $pageKey = 'create_exam';
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = sanitize($db, $_POST['title'] ?? '');
    $desc        = sanitize($db, $_POST['description'] ?? '');
    $duration    = (int)($_POST['duration'] ?? 30);
    $totalQ      = (int)($_POST['total_questions'] ?? 20);
    $gkQ         = (int)($_POST['gk_questions'] ?? 0);
    $enQ         = (int)($_POST['english_questions'] ?? 0);
    $logQ        = (int)($_POST['logical_questions'] ?? 0);
    $totalMarks  = (int)($_POST['total_marks'] ?? 0);
    $passingMark = (int)($_POST['passing_marks'] ?? 0);
    $posMarks    = (float)($_POST['marks_correct'] ?? 2);
    $negMarks    = (float)($_POST['marks_negative'] ?? 0);
    $source      = sanitize($db, $_POST['question_source'] ?? 'bank');
    $apiKey      = sanitize($db, $_POST['api_key'] ?? '');
    $apiModel    = sanitize($db, $_POST['api_model'] ?? '');
    $instructions = sanitize($db, $_POST['instructions'] ?? '');
    $showResults  = isset($_POST['show_results']) ? 1 : 0;
    $adminId     = (int)$_SESSION['admin_id'];

    // Validate
    if (!$title) $error = 'Exam title is required.';
    elseif ($gkQ + $enQ + $logQ !== $totalQ) $error = "Category questions ($gkQ+$enQ+$logQ) must equal total questions ($totalQ).";
    elseif ($source !== 'bank' && !$apiKey) $error = 'API key is required for AI-generated questions.';
    else {
        if ($totalMarks === 0) $totalMarks = $totalQ * (int)$posMarks;
        $stmt = $db->prepare(
            "INSERT INTO exams (title,description,duration_minutes,total_questions,gk_questions,english_questions,logical_questions,
             total_marks,passing_marks,marks_per_correct,negative_marks,question_source,api_key,api_model,instructions,show_results,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssiiiiiiiddssssii',
            $title,$desc,$duration,$totalQ,$gkQ,$enQ,$logQ,
            $totalMarks,$passingMark,$posMarks,$negMarks,$source,$apiKey,$apiModel,$instructions,$showResults,$adminId
        );
        if ($stmt->execute()) {
            $success = 'Exam created successfully! You can now activate and start it from Manage Exams.';
        } else {
            $error = 'Database error: ' . $stmt->error;
        }
        $stmt->close();
    }
}

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">➕ Create New Exam</div>
    <a href="manage_exams.php"><button class="btn btn-outline">← Manage Exams</button></a>
  </div>
  <div class="page-body">
    <?php if($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
    <?php if($error):   ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

    <form method="POST" id="examForm">
      <div style="display:grid;grid-template-columns:1fr 380px;gap:1.2rem">
        <!-- Left column -->
        <div style="display:flex;flex-direction:column;gap:1.2rem">
          <div class="card">
            <div class="card-title" style="margin-bottom:1.2rem">📋 Exam Details</div>
            <div class="form-group">
              <label>Exam Title *</label>
              <input class="form-control" type="text" name="title" placeholder="e.g. Full Length Mock Test 1 – UGC NET Paper 1" required>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea class="form-control" name="description" rows="2" placeholder="Brief description (optional)"></textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Duration (minutes) *</label>
                <input class="form-control" type="number" name="duration" value="30" min="5" max="300" required>
              </div>
              <div class="form-group">
                <label>Total Questions *</label>
                <input class="form-control" type="number" name="total_questions" id="totalQ" value="20" min="5" max="100" required>
              </div>
            </div>
            <div class="form-row three">
              <div class="form-group">
                <label>Total Marks</label>
                <input class="form-control" type="number" name="total_marks" id="totalMarks" value="40" min="1">
              </div>
              <div class="form-group">
                <label>Passing Marks</label>
                <input class="form-control" type="number" name="passing_marks" id="passingMarks" value="20" min="1">
              </div>
              <div class="form-group">
                <label>+Mark / Question</label>
                <input class="form-control" type="number" step="0.5" name="marks_correct" id="marksPer" value="2" min="0.5" max="10">
              </div>
            </div>
            <div class="form-group">
              <label>Negative Marking (per wrong)</label>
              <input class="form-control" type="number" step="0.25" name="marks_negative" value="0" min="0" max="5">
            </div>
            <div class="form-group">
              <label>Special Instructions (shown to students)</label>
              <textarea class="form-control" name="instructions" rows="3" placeholder="Any exam-specific instructions..."></textarea>
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.92rem;color:var(--white)">
                <input type="checkbox" name="show_results" value="1" checked
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

          <!-- Question source -->
          <div class="card">
            <div class="card-title" style="margin-bottom:1.2rem">🤖 Question Source</div>
            <div class="tabs" id="sourceTabs">
              <div class="tab active" onclick="setSource('bank')">🗃️ Question Bank</div>
              <div class="tab" onclick="setSource('gemini')">✨ Google Gemini</div>
              <div class="tab" onclick="setSource('groq')">⚡ Groq AI</div>
            </div>
            <input type="hidden" name="question_source" id="sourceInput" value="bank">

            <div id="bankSection">
              <div class="alert alert-info">Questions will be randomly pulled from your question bank. Each student gets a unique randomized set.</div>
            </div>

            <div id="geminiSection" style="display:none">
              <div class="form-group">
                <label>Gemini API Key *</label>
                <input class="form-control secret-field" type="password" name="api_key" id="geminiKey" placeholder="AIza..." autocomplete="off">
              </div>
              <div class="form-group">
                <label>Model</label>
                <select class="form-control" name="api_model" id="geminiModel">
                  <?php foreach (getGeminiModelOptions() as $val => $label): ?>
                  <option value="<?= htmlspecialchars($val) ?>" <?= $val === getDefaultGeminiModel() ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="button" class="btn btn-outline btn-sm" onclick="testAPI('gemini')" id="testGeminiBtn">🧪 Test & Preview Questions</button>
            </div>

            <div id="groqSection" style="display:none">
              <div class="form-group">
                <label>Groq API Key *</label>
                <input class="form-control secret-field" type="password" name="api_key" id="groqKey" placeholder="gsk_..." autocomplete="off">
              </div>
              <div class="form-group">
                <label>Model</label>
                <select class="form-control" name="api_model" id="groqModel">
                  <option value="llama3-8b-8192">llama3-8b-8192 (Fast)</option>
                  <option value="llama3-70b-8192">llama3-70b-8192 (Accurate)</option>
                  <option value="mixtral-8x7b-32768">mixtral-8x7b-32768</option>
                  <option value="gemma2-9b-it">gemma2-9b-it</option>
                </select>
              </div>
              <button type="button" class="btn btn-outline btn-sm" onclick="testAPI('groq')" id="testGroqBtn">🧪 Test & Preview Questions</button>
            </div>

            <div id="previewContainer" style="display:none;margin-top:1rem">
              <div class="card-title" style="margin-bottom:.8rem;font-size:.9rem">Preview (first 3 questions)</div>
              <div id="previewContent"></div>
            </div>
          </div>
        </div>

        <!-- Right column: question distribution -->
        <div>
          <div class="card" style="position:sticky;top:0">
            <div class="card-title" style="margin-bottom:1.2rem">📊 Question Distribution</div>
            <div class="alert alert-info" id="qAlert" style="font-size:.8rem;margin-bottom:1rem">
              Total must equal <strong id="qTotal">20</strong> questions
            </div>

            <div class="form-group">
              <label>🌍 General Knowledge</label>
              <input class="form-control" type="number" name="gk_questions" id="gkQ" value="7" min="0" oninput="onCategoryChange()" onchange="onCategoryChange()">
            </div>
            <div class="form-group">
              <label>📝 Basic English</label>
              <input class="form-control" type="number" name="english_questions" id="enQ" value="7" min="0" oninput="onCategoryChange()" onchange="onCategoryChange()">
            </div>
            <div class="form-group">
              <label>🧠 Logical Reasoning</label>
              <input class="form-control" type="number" name="logical_questions" id="logQ" value="6" min="0" oninput="onCategoryChange()" onchange="onCategoryChange()">
            </div>

            <div style="background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:10px;padding:.8rem;margin-bottom:1.2rem">
              <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.5rem">
                <span>Assigned</span><span id="qAssigned" style="color:var(--accent)">20</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:.85rem">
                <span>Remaining</span><span id="qRemaining" style="color:#81c784">0</span>
              </div>
            </div>

            <!-- Visual bar -->
            <div style="height:8px;border-radius:4px;background:rgba(255,255,255,.1);overflow:hidden;margin-bottom:1.2rem;display:flex">
              <div id="barGk"  style="background:#64b5f6;transition:.3s" title="GK"></div>
              <div id="barEn"  style="background:#81c784;transition:.3s" title="English"></div>
              <div id="barLog" style="background:#ce93d8;transition:.3s" title="Logical"></div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:13px" id="submitBtn">
              🚀 Create Exam
            </button>
            <p style="font-size:.72rem;color:var(--muted);text-align:center;margin-top:.6rem">You can activate the exam after creation</p>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
</div>

<script>
let currentSource = 'bank';

function setSource(src) {
  currentSource = src;
  document.getElementById('sourceInput').value = src;
  document.querySelectorAll('.tab').forEach((t,i)=>{
    t.classList.toggle('active',['bank','gemini','groq'][i]===src);
  });
  document.getElementById('bankSection').style.display   = src==='bank'?'block':'none';
  document.getElementById('geminiSection').style.display = src==='gemini'?'block':'none';
  document.getElementById('groqSection').style.display   = src==='groq'?'block':'none';
}

function formatMarkValue(value) {
  const rounded = Math.round(value * 100) / 100;
  return Number.isInteger(rounded) ? rounded : rounded.toFixed(1);
}

function updateTotalMarks() {
  const total = parseInt(document.getElementById('totalQ').value, 10) || 0;
  const mpp   = parseFloat(document.getElementById('marksPer').value) || 2;
  const marks = total * mpp;
  const passing = marks / 2;

  document.getElementById('totalMarks').value = formatMarkValue(marks);
  document.getElementById('passingMarks').value = formatMarkValue(passing);
}

function onCategoryChange() {
  const gk  = parseInt(document.getElementById('gkQ').value, 10) || 0;
  const en  = parseInt(document.getElementById('enQ').value, 10) || 0;
  const log = parseInt(document.getElementById('logQ').value, 10) || 0;
  document.getElementById('totalQ').value = gk + en + log;
  updateQCount();
}

function updateQCount() {
  const total = parseInt(document.getElementById('totalQ').value, 10) || 0;
  const gk    = parseInt(document.getElementById('gkQ').value, 10) || 0;
  const en    = parseInt(document.getElementById('enQ').value, 10) || 0;
  const log   = parseInt(document.getElementById('logQ').value, 10) || 0;
  const assigned = gk + en + log;
  const remaining = total - assigned;

  document.getElementById('qTotal').textContent     = total;
  document.getElementById('qAssigned').textContent  = assigned;
  document.getElementById('qRemaining').textContent = remaining;
  document.getElementById('qRemaining').style.color = remaining === 0 ? '#81c784' : '#ef9a9a';

  const al = document.getElementById('qAlert');
  al.className = 'alert ' + (remaining === 0 ? 'alert-success' : 'alert-error');
  al.innerHTML = remaining === 0
    ? `✅ All <strong>${total}</strong> questions assigned`
    : `⚠️ ${Math.abs(remaining)} question(s) ${remaining > 0 ? 'still unassigned' : 'over-assigned'}`;

  if (total > 0) {
    document.getElementById('barGk').style.width  = (gk / total * 100) + '%';
    document.getElementById('barEn').style.width  = (en / total * 100) + '%';
    document.getElementById('barLog').style.width = (log / total * 100) + '%';
  }

  updateTotalMarks();
}

document.getElementById('totalQ').addEventListener('input', updateQCount);
document.getElementById('totalQ').addEventListener('change', updateQCount);
document.getElementById('marksPer').addEventListener('input', updateTotalMarks);
document.getElementById('marksPer').addEventListener('change', updateTotalMarks);
updateQCount();

async function testAPI(provider) {
  const gk  = parseInt(document.getElementById('gkQ').value)||2;
  const en  = parseInt(document.getElementById('enQ').value)||2;
  const log = parseInt(document.getElementById('logQ').value)||1;
  const key = provider==='gemini'
    ? document.getElementById('geminiKey').value
    : document.getElementById('groqKey').value;

  if (!key) { alert('Please enter the API key first.'); return; }

  const btnId = provider==='gemini'?'testGeminiBtn':'testGroqBtn';
  const btn = document.getElementById(btnId);
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Generating...';

  try {
    const res = await fetch('<?= BASE_URL ?>/admin/api_test_questions.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ provider, api_key:key, gk_questions:Math.min(gk,3), english_questions:Math.min(en,3), logical_questions:Math.min(log,3),
        api_model: provider==='gemini' ? document.getElementById('geminiModel').value : document.getElementById('groqModel').value })
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    let html = '';
    data.slice(0,3).forEach((q,i) => {
      html += `<div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:.8rem;margin-bottom:.6rem">
        <div style="font-size:.75rem;color:var(--muted);margin-bottom:.3rem">#${i+1} · ${q.category}</div>
        <div style="font-size:.85rem;margin-bottom:.5rem">${q.question_text}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:.78rem;color:var(--muted)">
          <span style="color:${q.correct_answer==='A'?'#81c784':'inherit'}">A: ${q.option_a}</span>
          <span style="color:${q.correct_answer==='B'?'#81c784':'inherit'}">B: ${q.option_b}</span>
          <span style="color:${q.correct_answer==='C'?'#81c784':'inherit'}">C: ${q.option_c}</span>
          <span style="color:${q.correct_answer==='D'?'#81c784':'inherit'}">D: ${q.option_d}</span>
        </div>
      </div>`;
    });
    document.getElementById('previewContent').innerHTML = html;
    document.getElementById('previewContainer').style.display='block';
    btn.innerHTML='✅ Test Passed! Questions look good';
  } catch(e) {
    btn.innerHTML='❌ Error: '+e.message;
    btn.style.color='#ef9a9a';
  }
  btn.disabled = false;
}
</script>
</body></html>
