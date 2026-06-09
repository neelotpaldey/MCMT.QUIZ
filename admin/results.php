<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Results'; $pageKey = 'results';

// Filters
$examId  = (int)($_GET['exam_id'] ?? 0);
$search  = sanitize($db, $_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = 25; $offset = ($page-1)*$limit;

$where = 'WHERE 1=1';
if ($examId) $where .= " AND er.exam_id=$examId";
if ($search) $where .= " AND (s.full_name LIKE '%$search%' OR s.mobile LIKE '%$search%')";

$total  = $db->query("SELECT COUNT(*) FROM exam_results er JOIN students s ON s.id=er.student_id $where")->fetch_row()[0];
$pages  = ceil($total/$limit);
$results = $db->query("
    SELECT er.*, s.full_name, s.mobile, s.roll_number, e.title as exam_title, e.total_marks as exam_total_marks
    FROM exam_results er
    JOIN students s ON s.id=er.student_id
    JOIN exams e ON e.id=er.exam_id
    $where ORDER BY er.submitted_at DESC LIMIT $limit OFFSET $offset
");

// Stats for selected exam
$stats = null;
if ($examId) {
    $stats = $db->query("SELECT
        COUNT(*) as total, SUM(is_passed) as passed,
        ROUND(AVG(percentage),1) as avg_pct,
        MAX(marks_obtained) as max_marks, MIN(marks_obtained) as min_marks,
        ROUND(AVG(correct),1) as avg_correct
        FROM exam_results WHERE exam_id=$examId")->fetch_assoc();
}

// Exam list for filter
$examList = $db->query("SELECT id,title FROM exams ORDER BY created_at DESC");

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">📈 Exam Results</div>
    <div style="display:flex;gap:.6rem">
      <button class="btn btn-outline btn-sm" onclick="exportCSV()">📥 Export CSV</button>
    </div>
  </div>
  <div class="page-body">
    <!-- Filters -->
    <div class="card" style="margin-bottom:1rem">
      <form method="GET" style="display:flex;gap:.8rem;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <label style="display:block;font-size:.75rem;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em">Filter by Exam</label>
          <select class="form-control" name="exam_id" onchange="this.form.submit()">
            <option value="">All Exams</option>
            <?php while($e=$examList->fetch_assoc()): ?>
            <option value="<?= $e['id'] ?>" <?= $examId===$e['id']?'selected':'' ?>><?= htmlspecialchars($e['title']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>
        <div style="flex:1;min-width:200px">
          <label style="display:block;font-size:.75rem;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em">Search Student</label>
          <input class="form-control" type="text" name="search" placeholder="Name or mobile..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <?php if ($examId): ?><input type="hidden" name="exam_id" value="<?= $examId ?>"><?php endif; ?>
        <button class="btn btn-primary" type="submit">🔍 Filter</button>
        <a href="results.php"><button class="btn btn-outline" type="button">✕ Clear</button></a>
      </form>
    </div>

    <!-- Exam stats -->
    <?php if ($stats && $examId): ?>
    <div class="stats-grid" style="margin-bottom:1rem">
      <div class="stat-card" style="--accent-color:rgba(39,174,96,.1)">
        <div class="stat-icon">✅</div>
        <div class="stat-val"><?= $stats['passed'] ?>/<?= $stats['total'] ?></div>
        <div class="stat-lbl">Passed / Total</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(30,136,229,.1)">
        <div class="stat-icon">📊</div>
        <div class="stat-val"><?= $stats['avg_pct'] ?>%</div>
        <div class="stat-lbl">Average Score</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(255,183,3,.1)">
        <div class="stat-icon">🏆</div>
        <div class="stat-val"><?= $stats['max_marks'] ?></div>
        <div class="stat-lbl">Highest Marks</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(192,57,43,.1)">
        <div class="stat-icon">📉</div>
        <div class="stat-val"><?= $stats['min_marks'] ?></div>
        <div class="stat-lbl">Lowest Marks</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Results Table -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Results <span style="color:var(--muted);font-size:.85rem">(<?= $total ?> records)</span></div>
      </div>
      <table class="tbl" id="resultsTable">
        <thead>
          <tr>
            <th>#</th><th>Student</th><th>Mobile</th><th>Exam</th>
            <th>Score</th><th>%</th><th>✓</th><th>✕</th><th>—</th>
            <th>Result</th><th>Submitted</th>
          </tr>
        </thead>
        <tbody>
        <?php $rank=($page-1)*$limit+1; while($r=$results->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--muted)"><?= $rank++ ?></td>
            <td>
              <strong><?= htmlspecialchars($r['full_name']) ?></strong>
              <?php if($r['roll_number']): ?><br><span style="font-size:.72rem;color:var(--muted)"><?= htmlspecialchars($r['roll_number']) ?></span><?php endif; ?>
            </td>
            <td style="font-family:'DM Mono',monospace;font-size:.82rem"><?= htmlspecialchars($r['mobile']) ?></td>
            <td style="font-size:.8rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($r['exam_title']) ?>">
              <?= htmlspecialchars($r['exam_title']) ?>
            </td>
            <td><strong><?= $r['marks_obtained'] ?></strong><span style="color:var(--muted);font-size:.78rem">/<?= $r['exam_total_marks'] ?></span></td>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="width:40px;height:5px;border-radius:3px;background:rgba(255,255,255,.1);overflow:hidden">
                  <div style="width:<?= $r['percentage'] ?>%;height:100%;background:<?= $r['percentage']>=50?'#27ae60':'#c0392b' ?>"></div>
                </div>
                <?= $r['percentage'] ?>%
              </div>
            </td>
            <td style="color:#81c784"><?= $r['correct'] ?></td>
            <td style="color:#ef9a9a"><?= $r['wrong'] ?></td>
            <td style="color:var(--muted)"><?= $r['skipped'] ?></td>
            <td><span class="badge <?= $r['is_passed']?'badge-pass':'badge-fail' ?>"><?= $r['is_passed']?'PASS':'FAIL' ?></span></td>
            <td style="font-size:.78rem;color:var(--muted)"><?= date('d M Y<\b\r>h:i A', strtotime($r['submitted_at'])) ?></td>
          </tr>
        <?php endwhile; ?>
        <?php if ($total===0): ?>
          <tr><td colspan="11" style="text-align:center;padding:3rem;color:var(--muted)">No results found</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <?php if ($pages > 1): ?>
      <div class="pagination">
        <?php for ($p=1;$p<=$pages;$p++): ?>
          <a href="?page=<?= $p ?>&exam_id=<?= $examId ?>&search=<?= urlencode($search) ?>">
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
function exportCSV() {
  const rows = [['Rank','Name','Mobile','Exam','Marks','Percentage','Correct','Wrong','Skipped','Result','Submitted']];
  document.querySelectorAll('#resultsTable tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length < 2) return;
    rows.push([
      cells[0].textContent.trim(), cells[1].textContent.trim().split('\n')[0],
      cells[2].textContent.trim(), cells[3].textContent.trim(),
      cells[4].textContent.trim(), cells[5].textContent.trim(),
      cells[6].textContent.trim(), cells[7].textContent.trim(),
      cells[8].textContent.trim(), cells[9].textContent.trim(),
      cells[10].textContent.trim()
    ]);
  });
  const csv = rows.map(r=>r.map(c=>'"'+c.replace(/"/g,'""')+'"').join(',')).join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const a = document.createElement('a'); a.href=URL.createObjectURL(blob);
  a.download='exam_results_'+Date.now()+'.csv'; a.click();
}
</script>
</body></html>
