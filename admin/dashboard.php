<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Dashboard'; $pageKey = 'dashboard';
include __DIR__ . '/layout_head.php';

// Stats
$totalStudents  = $db->query("SELECT COUNT(*) FROM students WHERE is_active=1")->fetch_row()[0];
$totalExams     = $db->query("SELECT COUNT(*) FROM exams")->fetch_row()[0];
$totalQuestions = $db->query("SELECT COUNT(*) FROM question_bank")->fetch_row()[0];
$totalResults   = $db->query("SELECT COUNT(*) FROM exam_results")->fetch_row()[0];
$passRate       = $db->query("SELECT ROUND(AVG(is_passed)*100,1) FROM exam_results")->fetch_row()[0] ?? 0;
$activeExam     = $db->query("SELECT * FROM exams WHERE is_active=1 AND is_started=1 ORDER BY started_at DESC LIMIT 1")->fetch_assoc();

// Recent results
$recentResults = $db->query("SELECT er.*, s.full_name, e.title FROM exam_results er
  JOIN students s ON s.id=er.student_id
  JOIN exams e ON e.id=er.exam_id
  ORDER BY er.submitted_at DESC LIMIT 8");
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">📊 Dashboard</div>
    <div class="topbar-right">
      <?php if ($activeExam): ?>
        <span class="badge badge-active">🔴 LIVE: <?= htmlspecialchars($activeExam['title']) ?></span>
      <?php endif; ?>
      <span style="font-size:.8rem;color:var(--muted)"><?= date('D, d M Y') ?></span>
    </div>
  </div>
  <div class="page-body">
    <?php if ($activeExam): ?>
    <div class="alert alert-info" style="display:flex;justify-content:space-between;align-items:center">
      <span>🔴 <strong><?= htmlspecialchars($activeExam['title']) ?></strong> is currently LIVE — <?= $activeExam['duration_minutes'] ?> min exam</span>
      <a href="manage_exams.php"><button class="btn btn-sm btn-outline">Manage →</button></a>
    </div>
    <?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card" style="--accent-color:rgba(30,136,229,.12)">
        <div class="stat-icon">👥</div>
        <div class="stat-val"><?= $totalStudents ?></div>
        <div class="stat-lbl">Active Students</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(255,183,3,.1)">
        <div class="stat-icon">📋</div>
        <div class="stat-val"><?= $totalExams ?></div>
        <div class="stat-lbl">Total Exams</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(155,89,182,.1)">
        <div class="stat-icon">🗃️</div>
        <div class="stat-val"><?= $totalQuestions ?></div>
        <div class="stat-lbl">Question Bank</div>
      </div>
      <div class="stat-card" style="--accent-color:rgba(39,174,96,.1)">
        <div class="stat-icon">📈</div>
        <div class="stat-val"><?= $passRate ?>%</div>
        <div class="stat-lbl">Overall Pass Rate</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent Results</div>
          <a href="results.php"><button class="btn btn-sm btn-outline">View All</button></a>
        </div>
        <table class="tbl">
          <thead><tr><th>Student</th><th>Exam</th><th>Score</th><th>Result</th></tr></thead>
          <tbody>
          <?php while($r=$recentResults->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars(substr($r['title'],0,20)) ?>...</td>
            <td><?= $r['marks_obtained'] ?>/<?= $r['total_questions']*2 ?></td>
            <td><span class="badge <?= $r['is_passed']?'badge-pass':'badge-fail' ?>"><?= $r['is_passed']?'PASS':'FAIL' ?></span></td>
          </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Quick Actions</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:.8rem">
          <a href="create_exam.php"><button class="btn btn-primary" style="width:100%;text-align:left;padding:12px 16px">➕ Create New Exam</button></a>
          <a href="students.php?action=add"><button class="btn btn-outline" style="width:100%;text-align:left;padding:12px 16px">👤 Add Student</button></a>
          <a href="question_bank.php"><button class="btn btn-outline" style="width:100%;text-align:left;padding:12px 16px">🗃️ Manage Questions</button></a>
          <a href="results.php"><button class="btn btn-outline" style="width:100%;text-align:left;padding:12px 16px">📊 View All Results</button></a>
          <a href="manage_exams.php"><button class="btn btn-success" style="width:100%;text-align:left;padding:12px 16px">🚀 Start/Stop Exam</button></a>
        </div>
      </div>
    </div>
  </div>
</div>
</div></body></html>
