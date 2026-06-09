<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/questions.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Question Bank'; $pageKey = 'question_bank';
$msg = ''; $msgType = 'info';

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add' || $postAction === 'edit') {
        $cat  = sanitize($db, $_POST['category'] ?? 'gk');
        $q    = sanitize($db, $_POST['question_text'] ?? '');
        $a    = sanitize($db, $_POST['option_a'] ?? '');
        $b    = sanitize($db, $_POST['option_b'] ?? '');
        $c    = sanitize($db, $_POST['option_c'] ?? '');
        $d    = sanitize($db, $_POST['option_d'] ?? '');
        $ans  = sanitize($db, $_POST['correct_answer'] ?? 'A');
        $exp  = sanitize($db, $_POST['explanation'] ?? '');
        $diff = sanitize($db, $_POST['difficulty'] ?? 'medium');

        if (!$q || !$a || !$b || !$c || !$d) {
            $msg='All question fields are required.'; $msgType='error';
        } else {
            if ($postAction==='add') {
                $stmt=$db->prepare("INSERT INTO question_bank (category,question_text,option_a,option_b,option_c,option_d,correct_answer,explanation,difficulty) VALUES(?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sssssssss',$cat,$q,$a,$b,$c,$d,$ans,$exp,$diff);
                $stmt->execute()?($msg='Question added!'):($msg='Error: '.$stmt->error);
                $stmt->close(); $msgType='success';
            } else {
                $qid=(int)$_POST['question_id'];
                $stmt=$db->prepare("UPDATE question_bank SET category=?,question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_answer=?,explanation=?,difficulty=? WHERE id=?");
                $stmt->bind_param('sssssssssi',$cat,$q,$a,$b,$c,$d,$ans,$exp,$diff,$qid);
                $stmt->execute(); $stmt->close();
                $msg='Question updated.'; $msgType='success';
            }
        }
    } elseif ($postAction==='delete') {
        $qid=(int)$_POST['question_id'];
        $db->query("DELETE FROM question_bank WHERE id=$qid");
        $msg='Question deleted.'; $msgType='info';
    } elseif ($postAction==='bulk_json') {
        $json=trim($_POST['json_data']??'');
        $qs=json_decode($json,true);
        if(is_array($qs)) {
            $import = importQuestionsToBank($db, $qs);
            $dup = (int) ($import['duplicates'] ?? 0);
            $msg="Imported: {$import['added']} added, {$import['errors']} failed" . ($dup ? ", {$dup} duplicates skipped" : '') . '.'; $msgType='success';
        } else { $msg='Invalid JSON format.'; $msgType='error'; }
    } elseif ($postAction==='bulk_csv') {
        if (empty($_FILES['csv_file']['tmp_name'])) {
            $msg='Please choose a CSV file.'; $msgType='error';
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $rows = [];
            if ($handle) {
                $headers = null;
                while (($row = fgetcsv($handle)) !== false) {
                    if ($headers === null) {
                        $headers = array_map(fn($h) => strtolower(trim($h)), $row);
                        continue;
                    }
                    if (count(array_filter($row)) === 0) continue;
                    $data = [];
                    foreach ($headers as $i => $key) {
                        $data[$key] = trim($row[$i] ?? '');
                    }
                    $rows[] = [
                        'category'       => $data['category'] ?? 'gk',
                        'question_text'  => $data['question_text'] ?? $data['question'] ?? '',
                        'option_a'       => $data['option_a'] ?? '',
                        'option_b'       => $data['option_b'] ?? '',
                        'option_c'       => $data['option_c'] ?? '',
                        'option_d'       => $data['option_d'] ?? '',
                        'correct_answer' => $data['correct_answer'] ?? 'A',
                        'difficulty'     => $data['difficulty'] ?? 'medium',
                        'explanation'    => $data['explanation'] ?? '',
                    ];
                }
                fclose($handle);
            }
            if (empty($rows)) {
                $msg='CSV file is empty or invalid.'; $msgType='error';
            } else {
                $import = importQuestionsToBank($db, $rows);
                $dup = (int) ($import['duplicates'] ?? 0);
                $msg="CSV import: {$import['added']} added, {$import['errors']} failed" . ($dup ? ", {$dup} duplicates skipped" : '') . '.'; $msgType='success';
            }
        }
    } elseif ($postAction==='ai_generate') {
        set_time_limit(600);

        $provider = normalizeAiProvider($_POST['ai_provider'] ?? 'groq');
        $cat      = sanitize($db, $_POST['category'] ?? 'gk');
        $count    = max(1, min(50, (int) ($_POST['question_count'] ?? 5)));
        $creds    = getAiProviderCredentials($db, $provider);

        if (!$creds['api_key']) {
            $labels = ['groq' => 'Groq', 'gemini' => 'Gemini', 'openai' => 'ChatGPT (OpenAI)'];
            $msg = 'Add your ' . ($labels[$provider] ?? 'AI') . ' API key in Admin → Settings first.';
            $msgType = 'error';
        } else {
            $generated = generateCategoryQuestions($provider, $creds['api_key'], $cat, $count, $creds['model']);
            if (isset($generated['error'])) {
                $partial = $generated['partial'] ?? null;
                if (is_array($partial) && !empty($partial)) {
                    $import = importQuestionsToBank($db, $partial);
                    $dup = (int) ($import['duplicates'] ?? 0);
                    $msg = "Saved {$import['added']} question(s)" . ($dup ? ", {$dup} duplicates skipped" : '') . '. ' . $generated['error'];
                    $msgType = $import['added'] > 0 ? 'success' : 'error';
                } else {
                    $msg = $generated['error'];
                    $msgType = 'error';
                }
            } else {
                $import = importQuestionsToBank($db, $generated);
                $labels = ['groq' => 'Groq', 'gemini' => 'Gemini', 'openai' => 'ChatGPT'];
                $dup = (int) ($import['duplicates'] ?? 0);
                $msg = ($labels[$provider] ?? 'AI') . " generated {$import['added']} question(s) ({$import['errors']} failed" . ($dup ? ", {$dup} duplicates skipped" : '') . ').';
                $msgType = 'success';
            }
        }
    }
}

// Filters
$catFilter  = $_GET['category'] ?? '';
$diffFilter = $_GET['difficulty'] ?? '';
$search     = sanitize($db, $_GET['search'] ?? '');
$page       = max(1,(int)($_GET['page']??1));
$limit=20; $offset=($page-1)*$limit;

$where='WHERE 1=1';
if($catFilter)  $where.=" AND category='$catFilter'";
if($diffFilter) $where.=" AND difficulty='$diffFilter'";
if($search)     $where.=" AND question_text LIKE '%$search%'";

$total = $db->query("SELECT COUNT(*) FROM question_bank $where")->fetch_row()[0];
$pages = ceil($total/$limit);
$questions = $db->query("SELECT * FROM question_bank $where ORDER BY id DESC LIMIT $limit OFFSET $offset");

// Category counts
$catCounts = [];
$ccRes = $db->query("SELECT category,COUNT(*) as cnt FROM question_bank GROUP BY category");
while($cc=$ccRes->fetch_assoc()) $catCounts[$cc['category']]=$cc['cnt'];

// Edit mode
$editQ = null;
if (isset($_GET['edit'])) {
    $eid=(int)$_GET['edit'];
    $editQ=$db->query("SELECT * FROM question_bank WHERE id=$eid")->fetch_assoc();
}

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">🗃️ Question Bank</div>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <button class="btn btn-outline btn-sm" onclick="toggleCsvForm()">📄 CSV Upload</button>
      <button class="btn btn-outline btn-sm" onclick="toggleAiForm()">⚡ AI Generate</button>
      <button class="btn btn-outline btn-sm" onclick="toggleBulkForm()">📥 Bulk Import JSON</button>
      <button class="btn btn-primary btn-sm" onclick="toggleAddForm()">➕ Add Question</button>
    </div>
  </div>
  <div class="page-body">
    <?php if($msg): ?><div class="alert alert-<?= $msgType ?>"><?= $msgType==='success'?'✅':($msgType==='error'?'❌':'ℹ️') ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid" style="margin-bottom:1rem">
      <div class="stat-card" style="--accent-color:rgba(100,181,246,.1)">
        <div class="stat-icon">🌍</div>
        <div class="stat-val"><?= $catCounts['gk']??0 ?></div>
        <div class="stat-lbl">General Knowledge</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(129,199,132,.1)">
        <div class="stat-icon">📝</div>
        <div class="stat-val"><?= $catCounts['english']??0 ?></div>
        <div class="stat-lbl">Basic English</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(206,147,216,.1)">
        <div class="stat-icon">🧠</div>
        <div class="stat-val"><?= $catCounts['logical']??0 ?></div>
        <div class="stat-lbl">Logical Reasoning</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-val"><?= array_sum($catCounts) ?></div>
        <div class="stat-lbl">Total Questions</div>
      </div>
    </div>

    <!-- Add/Edit Form -->
    <div id="addForm" class="card" style="margin-bottom:1rem;<?= $editQ?'':'display:none' ?>">
      <div class="card-header">
        <div class="card-title"><?= $editQ?'✏️ Edit Question':'➕ Add Question' ?></div>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('addForm').style.display='none'">✕</button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="<?= $editQ?'edit':'add' ?>">
        <?php if($editQ): ?><input type="hidden" name="question_id" value="<?= $editQ['id'] ?>"><?php endif; ?>
        <div class="form-row three">
          <div class="form-group">
            <label>Category *</label>
            <select class="form-control" name="category" required>
              <option value="gk" <?= ($editQ['category']??'')==='gk'?'selected':'' ?>>🌍 General Knowledge</option>
              <option value="english" <?= ($editQ['category']??'')==='english'?'selected':'' ?>>📝 Basic English</option>
              <option value="logical" <?= ($editQ['category']??'')==='logical'?'selected':'' ?>>🧠 Logical Reasoning</option>
            </select>
          </div>
          <div class="form-group">
            <label>Correct Answer *</label>
            <select class="form-control" name="correct_answer" required>
              <?php foreach(['A','B','C','D'] as $k): ?>
              <option value="<?= $k ?>" <?= ($editQ['correct_answer']??'A')===$k?'selected':'' ?>>Option <?= $k ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Difficulty</label>
            <select class="form-control" name="difficulty">
              <?php foreach(['easy','medium','hard'] as $d): ?>
              <option value="<?= $d ?>" <?= ($editQ['difficulty']??'medium')===$d?'selected':'' ?>><?= ucfirst($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Question Text *</label>
          <textarea class="form-control" name="question_text" rows="3" required><?= htmlspecialchars($editQ['question_text']??'') ?></textarea>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Option A *</label><input class="form-control" type="text" name="option_a" required value="<?= htmlspecialchars($editQ['option_a']??'') ?>"></div>
          <div class="form-group"><label>Option B *</label><input class="form-control" type="text" name="option_b" required value="<?= htmlspecialchars($editQ['option_b']??'') ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Option C *</label><input class="form-control" type="text" name="option_c" required value="<?= htmlspecialchars($editQ['option_c']??'') ?>"></div>
          <div class="form-group"><label>Option D *</label><input class="form-control" type="text" name="option_d" required value="<?= htmlspecialchars($editQ['option_d']??'') ?>"></div>
        </div>
        <div class="form-group">
          <label>Explanation (optional)</label>
          <textarea class="form-control" name="explanation" rows="2"><?= htmlspecialchars($editQ['explanation']??'') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?= $editQ?'💾 Update':'➕ Add Question' ?></button>
      </form>

      <!-- AI Generate (inside add section) -->
      <div style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border)">
        <div style="font-size:.9rem;font-weight:600;margin-bottom:.8rem">⚡ AI Generate Questions</div>
        <form method="POST" id="aiGenerateForm">
          <input type="hidden" name="action" value="ai_generate">
          <div class="form-row three">
            <div class="form-group">
              <label>AI Provider</label>
              <select class="form-control" name="ai_provider" required>
                <option value="groq">⚡ Groq</option>
                <option value="gemini">✨ Gemini</option>
                <option value="openai">💬 ChatGPT (OpenAI)</option>
              </select>
            </div>
            <div class="form-group">
              <label>Category</label>
              <select class="form-control" name="category" required>
                <option value="gk">🌍 General Knowledge</option>
                <option value="english">📝 Basic English</option>
                <option value="logical">🧠 Logical Reasoning</option>
              </select>
            </div>
            <div class="form-group">
              <label>Number of Questions (1–50)</label>
              <input class="form-control" type="number" name="question_count" min="1" max="50" value="5" required>
            </div>
          </div>
          <div class="form-group" style="margin-top:.8rem">
            <button type="submit" class="btn btn-primary" id="aiGenerateBtn">⚡ Generate & Save</button>
          </div>
          <p style="font-size:.78rem;color:var(--muted);margin-top:.5rem">
            API keys are configured in <a href="settings.php" style="color:var(--sky)">Settings</a>.
            Groq works best on the free tier. Gemini/OpenAI need quota/billing. Requests run in small batches with auto-retry.
          </p>
        </form>
        <div id="aiStatus" style="display:none;margin-top:.8rem;font-size:.85rem"></div>
      </div>
    </div>

    <!-- CSV Upload -->
    <div id="csvForm" class="card" style="margin-bottom:1rem;display:none">
      <div class="card-header">
        <div class="card-title">📄 Upload Questions (CSV)</div>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('csvForm').style.display='none'">✕</button>
      </div>
      <p style="font-size:.82rem;color:var(--muted);margin-bottom:.8rem">
        CSV must include a header row. Required columns:
        <code style="color:var(--accent)">category, question_text, option_a, option_b, option_c, option_d, correct_answer</code>.
        Optional: <code>difficulty</code>, <code>explanation</code>.
      </p>
      <div style="font-size:.75rem;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.8rem;margin-bottom:.8rem;font-family:'DM Mono',monospace">
category,question_text,option_a,option_b,option_c,option_d,correct_answer,difficulty<br>
gk,"Capital of India?","Mumbai","Delhi","Kolkata","Chennai",B,easy
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="bulk_csv">
        <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required style="margin-bottom:.8rem">
        <button type="submit" class="btn btn-primary">📄 Upload CSV</button>
      </form>
    </div>

    <!-- Bulk JSON Import -->
    <div id="bulkForm" class="card" style="margin-bottom:1rem;display:none">
      <div class="card-header">
        <div class="card-title">📥 Bulk Import Questions (JSON)</div>
        <button class="btn btn-outline btn-sm" onclick="document.getElementById('bulkForm').style.display='none'">✕</button>
      </div>
      <div style="font-size:.78rem;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.8rem;margin-bottom:.8rem;font-family:'DM Mono',monospace;white-space:pre">[{"category":"gk","question_text":"...","option_a":"...","option_b":"...","option_c":"...","option_d":"...","correct_answer":"A"}]</div>
      <form method="POST">
        <input type="hidden" name="action" value="bulk_json">
        <textarea class="form-control" name="json_data" rows="8" placeholder='Paste JSON array of questions...'></textarea>
        <button type="submit" class="btn btn-primary" style="margin-top:.8rem">📥 Import</button>
      </form>
    </div>

    <!-- Filter + Search -->
    <div class="card" style="margin-bottom:1rem">
      <form method="GET" style="display:flex;gap:.8rem;align-items:flex-end;flex-wrap:wrap">
        <div>
          <label style="display:block;font-size:.72rem;color:var(--muted);margin-bottom:4px">Category</label>
          <select class="form-control" name="category" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <option value="gk" <?= $catFilter==='gk'?'selected':'' ?>>🌍 GK</option>
            <option value="english" <?= $catFilter==='english'?'selected':'' ?>>📝 English</option>
            <option value="logical" <?= $catFilter==='logical'?'selected':'' ?>>🧠 Logical</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.72rem;color:var(--muted);margin-bottom:4px">Difficulty</label>
          <select class="form-control" name="difficulty" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="easy" <?= $diffFilter==='easy'?'selected':'' ?>>Easy</option>
            <option value="medium" <?= $diffFilter==='medium'?'selected':'' ?>>Medium</option>
            <option value="hard" <?= $diffFilter==='hard'?'selected':'' ?>>Hard</option>
          </select>
        </div>
        <div style="flex:1;min-width:200px">
          <label style="display:block;font-size:.72rem;color:var(--muted);margin-bottom:4px">Search</label>
          <input class="form-control" type="text" name="search" placeholder="Search question text..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <button class="btn btn-primary" type="submit">🔍</button>
        <a href="question_bank.php"><button class="btn btn-outline" type="button">✕</button></a>
      </form>
    </div>

    <!-- Questions List -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Questions <span style="color:var(--muted);font-size:.85rem">(<?= $total ?>)</span></div>
      </div>
      <?php $i=$offset+1; while($q=$questions->fetch_assoc()): ?>
      <div style="border-bottom:1px solid var(--border);padding:1rem 0;<?= $i===$offset+1?'':'margin-top:0' ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
          <div style="flex:1">
            <div style="display:flex;gap:6px;margin-bottom:.4rem;flex-wrap:wrap">
              <span style="font-size:.72rem;color:var(--muted)">#<?= $q['id'] ?></span>
              <span class="badge" style="font-size:.68rem;<?= ['gk'=>'background:rgba(100,181,246,.15);color:#64b5f6','english'=>'background:rgba(129,199,132,.15);color:#81c784','logical'=>'background:rgba(206,147,216,.15);color:#ce93d8'][$q['category']] ?>">
                <?= ['gk'=>'🌍 GK','english'=>'📝 English','logical'=>'🧠 Logical'][$q['category']] ?>
              </span>
              <span class="badge badge-<?= $q['difficulty']==='easy'?'active':($q['difficulty']==='hard'?'fail':'inactive') ?>" style="font-size:.68rem"><?= ucfirst($q['difficulty']) ?></span>
            </div>
            <div style="font-size:.9rem;margin-bottom:.5rem;line-height:1.5"><?= htmlspecialchars($q['question_text']) ?></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px;font-size:.78rem">
              <?php foreach(['A','B','C','D'] as $opt): ?>
              <div style="color:<?= $q['correct_answer']===$opt?'#81c784':'rgba(255,255,255,.5)' ?>">
                <strong><?= $opt ?>:</strong> <?= htmlspecialchars($q['option_'.$opt]) ?>
                <?= $q['correct_answer']===$opt?' ✓':'' ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="display:flex;gap:.4rem;flex-shrink:0">
            <a href="?edit=<?= $q['id'] ?>"><button class="btn btn-outline btn-sm">✏️</button></a>
            <form method="POST" onsubmit="return confirm('Delete this question?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
            </form>
          </div>
        </div>
      </div>
      <?php $i++; endwhile; ?>
      <?php if($total===0): ?><div style="text-align:center;padding:3rem;color:var(--muted)">No questions found</div><?php endif; ?>

      <?php if($pages>1): ?>
      <div class="pagination" style="margin-top:1rem">
        <?php for($p=1;$p<=$pages;$p++): ?>
          <a href="?page=<?= $p ?>&category=<?= $catFilter ?>&difficulty=<?= $diffFilter ?>&search=<?= urlencode($search) ?>">
            <button class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></button>
          </a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</div>

<script>
function toggleAddForm() {
  const f=document.getElementById('addForm');
  f.style.display=f.style.display==='none'?'block':'none';
}
function toggleBulkForm() {
  const f=document.getElementById('bulkForm');
  f.style.display=f.style.display==='none'?'block':'none';
}
function toggleCsvForm() {
  const f=document.getElementById('csvForm');
  f.style.display=f.style.display==='none'?'block':'none';
  if (f.style.display==='block') f.scrollIntoView({behavior:'smooth'});
}
function toggleAiForm() {
  toggleAddForm();
  document.getElementById('addForm').scrollIntoView({behavior:'smooth'});
}
document.getElementById('aiGenerateForm')?.addEventListener('submit', function(e){
  const btn = document.getElementById('aiGenerateBtn');
  const status = document.getElementById('aiStatus');
  btn.disabled = true;
  btn.textContent = '⏳ Generating...';
  status.style.display = 'block';
  status.style.color = 'var(--muted)';
  status.textContent = 'Generating questions in batches — please wait (Groq may take up to a few minutes for large counts)...';
});
<?php if($editQ): ?>
document.getElementById('addForm').style.display='block';
document.getElementById('addForm').scrollIntoView({behavior:'smooth'});
<?php endif; ?>
</script>
</body></html>
