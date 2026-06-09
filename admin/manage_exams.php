<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Manage Exams'; $pageKey = 'manage_exams';
$msg = ''; $msgType = 'info';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $examId = (int)($_POST['exam_id'] ?? 0);

    switch ($action) {
        case 'activate':
            $db->query("UPDATE exams SET is_active=1 WHERE id=$examId");
            $msg = 'Exam activated. Students can now see it.'; $msgType='success'; break;
        case 'deactivate':
            $db->query("UPDATE exams SET is_active=0, is_started=0 WHERE id=$examId");
            $msg = 'Exam deactivated.'; $msgType='info'; break;
        case 'start':
            $db->query("UPDATE exams SET is_started=1, started_at=NOW(), is_active=1 WHERE id=$examId");
            $msg = '🚀 Exam started! Students can now begin.'; $msgType='success'; break;
        case 'stop':
            $db->query("UPDATE exams SET is_started=0 WHERE id=$examId");
            // Auto-submit all active sessions for this exam
            $sessions = $db->query("SELECT id, exam_id, student_id FROM student_exam_sessions WHERE exam_id=$examId AND submitted_at IS NULL");
            while ($s = $sessions->fetch_assoc()) {
                $db->query("UPDATE student_exam_sessions SET submitted_at=NOW() WHERE id={$s['id']}");
                // Calculate results
                $source = $db->query("SELECT question_source FROM exams WHERE id=$examId")->fetch_assoc()['question_source'];
                $ansRes = $db->query("SELECT * FROM student_answers WHERE session_id={$s['id']}");
                $answers = [];
                while ($a = $ansRes->fetch_assoc()) $answers[$a['question_id']] = $a;
                $qids = implode(',', array_map('intval', array_keys($answers)));
                if ($qids) {
                    $qRes = $source==='bank'
                        ? $db->query("SELECT id,correct_answer FROM question_bank WHERE id IN ($qids)")
                        : $db->query("SELECT id,correct_answer FROM ai_generated_questions WHERE id IN ($qids)");
                    $c=$w=$sk=$at=0;
                    while ($q=$qRes->fetch_assoc()) {
                        $a=$answers[$q['id']]??null;
                        if(!$a||!$a['selected_answer']){$sk++;continue;}
                        $at++;
                        if($a['selected_answer']===$q['correct_answer'])$c++;else$w++;
                    }
                    $exam2=$db->query("SELECT * FROM exams WHERE id=$examId")->fetch_assoc();
                    $marks=round($c*$exam2['marks_per_correct']-$w*$exam2['negative_marks'],2);
                    $pct=$exam2['total_marks']>0?round($marks/$exam2['total_marks']*100,2):0;
                    $passed=($marks>=$exam2['passing_marks'])?1:0;
                    $total=$exam2['total_questions'];
                    $sid2=$s['student_id'];
                    $db->query("INSERT IGNORE INTO exam_results (session_id,student_id,exam_id,total_questions,attempted,correct,wrong,skipped,marks_obtained,percentage,is_passed)
                        VALUES ({$s['id']},$sid2,$examId,$total,$at,$c,$w,$sk,$marks,$pct,$passed)");
                }
            }
            $msg = 'Exam stopped. All pending sessions auto-submitted.'; $msgType='info'; break;
        case 'delete':
            $db->query("DELETE FROM exam_results WHERE exam_id=$examId");
            $db->query("DELETE FROM student_answers WHERE session_id IN (SELECT id FROM student_exam_sessions WHERE exam_id=$examId)");
            $db->query("DELETE FROM ai_generated_questions WHERE session_id IN (SELECT id FROM student_exam_sessions WHERE exam_id=$examId)");
            $db->query("DELETE FROM student_exam_sessions WHERE exam_id=$examId");
            $db->query("DELETE FROM exams WHERE id=$examId");
            $msg = 'Exam deleted.'; $msgType='info'; break;

        case 'update_questions':
            $examRow = $db->query("SELECT is_started FROM exams WHERE id=$examId")->fetch_assoc();
            if (!$examRow) {
                $msg = 'Exam not found.'; $msgType = 'error'; break;
            }
            if ((int) $examRow['is_started'] === 1) {
                $msg = 'Cannot change question count while exam is live.'; $msgType = 'error'; break;
            }
            $totalQ = (int) ($_POST['total_questions'] ?? 0);
            $gkQ    = (int) ($_POST['gk_questions'] ?? 0);
            $enQ    = (int) ($_POST['english_questions'] ?? 0);
            $logQ   = (int) ($_POST['logical_questions'] ?? 0);
            if ($totalQ < 1) {
                $msg = 'Total questions must be at least 1.'; $msgType = 'error'; break;
            }
            if ($gkQ + $enQ + $logQ !== $totalQ) {
                $msg = "Category questions ($gkQ+$enQ+$logQ) must equal total ($totalQ)."; $msgType = 'error'; break;
            }
            $stmt = $db->prepare('UPDATE exams SET total_questions=?, gk_questions=?, english_questions=?, logical_questions=? WHERE id=?');
            $stmt->bind_param('iiiii', $totalQ, $gkQ, $enQ, $logQ, $examId);
            $stmt->execute();
            $stmt->close();
            $msg = "Question count updated to $totalQ."; $msgType = 'success'; break;

        case 'send_result_emails':
            if (!isSmtpConfigured($db)) {
                $msg = 'SMTP is not configured. Go to Settings → Email (SMTP) first.'; $msgType = 'error'; break;
            }
            $customMessage = trim($_POST['email_message'] ?? '');
            $emailSubject  = trim($_POST['email_subject'] ?? '');
            if ($customMessage === '') {
                $msg = 'Please enter a custom message.'; $msgType = 'error'; break;
            }
            $examRow = $db->query("SELECT * FROM exams WHERE id=$examId")->fetch_assoc();
            if (!$examRow) {
                $msg = 'Exam not found.'; $msgType = 'error'; break;
            }
            if ($emailSubject === '') {
                $emailSubject = 'Your Result: ' . $examRow['title'];
            }
            $results = $db->query("
                SELECT er.*, s.full_name, s.email, s.mobile, s.roll_number
                FROM exam_results er
                JOIN students s ON s.id = er.student_id
                WHERE er.exam_id = $examId
                ORDER BY s.full_name
            ");
            $sent = 0; $skipped = 0; $failed = 0; $authError = '';
            while ($row = $results->fetch_assoc()) {
                $student = [
                    'full_name'   => $row['full_name'],
                    'email'       => $row['email'],
                    'mobile'      => $row['mobile'],
                    'roll_number' => $row['roll_number'],
                ];
                $email = trim($student['email'] ?? '');
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    continue;
                }
                try {
                    sendExamResultEmail($db, $row, $student, $examRow, $customMessage, $emailSubject);
                    $sent++;
                    throttleBulkEmail();
                } catch (Throwable $e) {
                    $failed++;
                    logDbError($e, 'send_result_emails');
                    if (isSmtpAuthError($e)) {
                        $authError = $e->getMessage();
                        break;
                    }
                }
            }
            if ($authError !== '') {
                $msg = $authError; $msgType = 'error';
            } elseif ($sent === 0 && $skipped > 0 && $failed === 0) {
                $msg = "No emails sent — $skipped participant(s) have no email on file."; $msgType = 'error';
            } elseif ($failed > 0) {
                $msg = "Sent $sent email(s), skipped $skipped (no email), failed $failed.";
                $msgType = $sent > 0 ? 'info' : 'error';
            } else {
                $msg = "Successfully sent results to $sent participant(s)" . ($skipped ? " ($skipped skipped — no email)" : '') . '.';
                $msgType = 'success';
            }
            break;
    }
}

// Load exams with stats
$exams = $db->query("
    SELECT e.*,
        (SELECT COUNT(*) FROM student_exam_sessions ses WHERE ses.exam_id=e.id AND ses.started_at IS NOT NULL) as sessions_count,
        (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id=e.id) as results_count,
        (SELECT COUNT(*) FROM exam_results er4 JOIN students s4 ON s4.id=er4.student_id
            WHERE er4.exam_id=e.id AND s4.email IS NOT NULL AND s4.email != '') as email_ready_count,
        (SELECT ROUND(AVG(er2.percentage),1) FROM exam_results er2 WHERE er2.exam_id=e.id) as avg_score,
        (SELECT ROUND(AVG(er3.is_passed)*100,0) FROM exam_results er3 WHERE er3.exam_id=e.id) as pass_rate
    FROM exams e ORDER BY e.created_at DESC
");

$smtpReady = isSmtpConfigured($db);
$defaultEmailMessage = "Thank you for participating in {exam_title}. Your individual result is below.\n\nYou scored {marks} out of {total_marks} ({percentage}%) and your result is: {result}.";
$liveMonitor = getLiveExamsMonitor($db);
$liveByExamId = [];
foreach ($liveMonitor['exams'] as $liveExam) {
    $liveByExamId[(int) $liveExam['exam_id']] = $liveExam;
}

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">📋 Manage Exams</div>
    <a href="create_exam.php"><button class="btn btn-primary">➕ Create New Exam</button></a>
  </div>
  <div class="page-body">
    <?php if($msg): ?><div class="alert alert-<?= $msgType ?>">
      <?= $msgType==='success'?'✅':($msgType==='error'?'❌':'ℹ️') ?> <?= htmlspecialchars($msg) ?>
    </div><?php endif; ?>

    <?php if (!empty($liveMonitor['exams'])): ?>
    <div class="card live-summary-card" style="margin-bottom:1.2rem;border:1px solid rgba(255,82,82,.35);background:rgba(255,82,82,.06)">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
        <div>
          <div style="font-size:1rem;font-weight:600;margin-bottom:.35rem">🟢 Live Exam Monitor</div>
          <div style="font-size:.85rem;color:var(--muted)">
            <span id="liveSummaryLive"><?= (int) $liveMonitor['total_live'] ?></span> student(s) in exam ·
            <span id="liveSummaryActive"><?= (int) $liveMonitor['total_active_now'] ?></span> active right now
          </div>
        </div>
        <div style="font-size:.78rem;color:var(--muted)">
          Auto-refresh · <span id="liveSummaryUpdated"><?= htmlspecialchars(date('h:i:s A')) ?></span>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php while ($exam = $exams->fetch_assoc()): ?>
    <?php $liveExam = $liveByExamId[(int) $exam['id']] ?? null; ?>
    <div class="card" style="margin-bottom:1.2rem">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem">
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:.4rem;flex-wrap:wrap">
            <h3 style="font-size:1.1rem;font-weight:600"><?= htmlspecialchars($exam['title']) ?></h3>
            <?php if ($exam['is_started']): ?>
              <span class="badge" style="background:rgba(255,82,82,.2);border:1px solid rgba(255,82,82,.4);color:#ff8a80;animation:pulse 1.5s infinite">🔴 LIVE</span>
            <?php elseif ($exam['is_active']): ?>
              <span class="badge badge-active">✅ Active</span>
            <?php else: ?>
              <span class="badge badge-inactive">⏸ Inactive</span>
            <?php endif; ?>
            <span class="badge badge-<?= $exam['question_source'] ?>">
              <?= ['bank'=>'🗃️ Question Bank','gemini'=>'✨ Gemini AI','groq'=>'⚡ Groq AI'][$exam['question_source']] ?>
            </span>
          </div>
          <div style="display:flex;gap:1.5rem;font-size:.82rem;color:var(--muted);flex-wrap:wrap">
            <span>⏱ <?= $exam['duration_minutes'] ?> min</span>
            <span>❓ <?= $exam['total_questions'] ?> questions</span>
            <span>📊 <?= $exam['total_marks'] ?> marks (pass: <?= $exam['passing_marks'] ?>)</span>
            <span>+<?= $exam['marks_per_correct'] ?> / -<?= $exam['negative_marks'] ?></span>
            <span><?= (int)($exam['show_results'] ?? 1) === 1 ? '👁 Results shown' : '🔒 Results hidden' ?></span>
            <span>👥 <?= $exam['sessions_count'] ?> takers</span>
            <?php if ($exam['is_started']): ?>
            <span class="live-meta-count" data-exam-id="<?= (int) $exam['id'] ?>">
              🟢 <?= (int) ($liveExam['live_count'] ?? 0) ?> live ·
              <?= (int) ($liveExam['active_now_count'] ?? 0) ?> active now
            </span>
            <?php endif; ?>
            <?php if($exam['results_count']>0): ?>
            <span>📈 Avg: <?= $exam['avg_score'] ?>% | Pass: <?= $exam['pass_rate'] ?>%</span>
            <?php endif; ?>
          </div>
          <?php if ($exam['gk_questions'] || $exam['english_questions'] || $exam['logical_questions']): ?>
          <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap">
            <?php if($exam['gk_questions']): ?><span style="font-size:.75rem;background:rgba(100,181,246,.1);border:1px solid rgba(100,181,246,.2);border-radius:6px;padding:2px 8px;color:#64b5f6">GK: <?= $exam['gk_questions'] ?>Q</span><?php endif; ?>
            <?php if($exam['english_questions']): ?><span style="font-size:.75rem;background:rgba(129,199,132,.1);border:1px solid rgba(129,199,132,.2);border-radius:6px;padding:2px 8px;color:#81c784">English: <?= $exam['english_questions'] ?>Q</span><?php endif; ?>
            <?php if($exam['logical_questions']): ?><span style="font-size:.75rem;background:rgba(206,147,216,.1);border:1px solid rgba(206,147,216,.2);border-radius:6px;padding:2px 8px;color:#ce93d8">Logical: <?= $exam['logical_questions'] ?>Q</span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap;flex-shrink:0">
          <!-- Start/Stop -->
          <?php if ($exam['is_started']): ?>
            <form method="POST" onsubmit="return confirm('Stop exam and auto-submit all active students?')">
              <input type="hidden" name="action" value="stop">
              <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">⏹ Stop Exam</button>
            </form>
          <?php else: ?>
            <?php if (!$exam['is_active']): ?>
            <form method="POST">
              <input type="hidden" name="action" value="activate">
              <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
              <button class="btn btn-outline btn-sm">✅ Activate</button>
            </form>
            <?php else: ?>
            <form method="POST">
              <input type="hidden" name="action" value="deactivate">
              <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
              <button class="btn btn-outline btn-sm">⏸ Deactivate</button>
            </form>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Start this exam? Students will be able to begin.')">
              <input type="hidden" name="action" value="start">
              <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
              <button class="btn btn-success btn-sm" <?= !$exam['is_active']?'disabled':'' ?>>🚀 Start Exam</button>
            </form>
          <?php endif; ?>

          <a href="edit_exam.php?id=<?= $exam['id'] ?>"><button class="btn btn-outline btn-sm" type="button">✏️ Edit</button></a>
          <a href="results.php?exam_id=<?= $exam['id'] ?>"><button class="btn btn-outline btn-sm">📊 Results</button></a>

          <form method="POST" onsubmit="return confirm('DELETE this exam and ALL its data? This cannot be undone!')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
          </form>
        </div>
      </div>

      <?php if ((int) $exam['is_started']): ?>
      <div class="live-exam-panel" data-exam-id="<?= (int) $exam['id'] ?>"
           style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
          <div style="font-size:.9rem;font-weight:600;color:#81c784">
            👁 Students Live Now
          </div>
          <div style="font-size:.78rem;color:var(--muted)">
            Active = answered or opened within <?= (int) examLiveActivitySeconds() ?> seconds
          </div>
        </div>
        <div class="live-exam-stats" style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.9rem">
          <div style="min-width:120px;padding:.65rem .85rem;border-radius:10px;background:rgba(129,199,132,.12);border:1px solid rgba(129,199,132,.25)">
            <div style="font-size:.72rem;color:var(--muted)">In exam</div>
            <div class="live-count-total" style="font-size:1.35rem;font-weight:700;color:#81c784">
              <?= (int) ($liveExam['live_count'] ?? 0) ?>
            </div>
          </div>
          <div style="min-width:120px;padding:.65rem .85rem;border-radius:10px;background:rgba(30,136,229,.12);border:1px solid rgba(30,136,229,.25)">
            <div style="font-size:.72rem;color:var(--muted)">Active now</div>
            <div class="live-count-active" style="font-size:1.35rem;font-weight:700;color:var(--sky)">
              <?= (int) ($liveExam['active_now_count'] ?? 0) ?>
            </div>
          </div>
        </div>
        <div class="live-student-list">
          <?php if (!empty($liveExam['students'])): ?>
          <div style="overflow-x:auto">
            <table class="tbl" style="font-size:.82rem">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Roll No.</th>
                  <th>Mobile</th>
                  <th>Status</th>
                  <th>Started</th>
                  <th>Last activity</th>
                  <th>Answered</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($liveExam['students'] as $liveStudent): ?>
                <tr>
                  <td><?= htmlspecialchars($liveStudent['full_name']) ?></td>
                  <td><?= htmlspecialchars($liveStudent['roll_number'] ?: '—') ?></td>
                  <td><?= htmlspecialchars($liveStudent['mobile'] ?: '—') ?></td>
                  <td>
                    <span class="live-status-badge live-status-<?= htmlspecialchars($liveStudent['status']) ?>">
                      <?= $liveStudent['status'] === 'active' ? '🟢' : '🟡' ?>
                      <?= htmlspecialchars($liveStudent['status_label']) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($liveStudent['started_label'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($liveStudent['last_label'] ?? '—') ?></td>
                  <td><?= (int) $liveStudent['answered_count'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <div class="live-empty" style="font-size:.85rem;color:var(--muted);padding:.75rem 0">
            No students are in this exam right now. Counts refresh automatically when someone starts.
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!(int) $exam['is_started']): ?>
      <details class="exam-panel-details" style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
        <summary style="cursor:pointer;font-size:.88rem;font-weight:600;color:var(--sky)">✏️ Change Question Count</summary>
        <form method="POST" style="margin-top:.8rem" onsubmit="return validateQForm(this)">
          <input type="hidden" name="action" value="update_questions">
          <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
          <div style="display:flex;gap:.8rem;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;min-width:100px">
              <label style="font-size:.72rem;color:var(--muted)">Total Questions</label>
              <input class="form-control" type="number" name="total_questions" value="<?= (int) $exam['total_questions'] ?>"
                     min="1" max="100" required style="padding:.45rem .6rem;font-size:.85rem"
                     oninput="syncManageQTotal(this.form)" onchange="syncManageQTotal(this.form)">
            </div>
            <div class="form-group" style="margin:0;min-width:80px">
              <label style="font-size:.72rem;color:var(--muted)">GK</label>
              <input class="form-control q-cat" type="number" name="gk_questions" value="<?= (int) $exam['gk_questions'] ?>"
                     min="0" style="padding:.45rem .6rem;font-size:.85rem"
                     oninput="syncManageQFromCats(this.form)" onchange="syncManageQFromCats(this.form)">
            </div>
            <div class="form-group" style="margin:0;min-width:80px">
              <label style="font-size:.72rem;color:var(--muted)">English</label>
              <input class="form-control q-cat" type="number" name="english_questions" value="<?= (int) $exam['english_questions'] ?>"
                     min="0" style="padding:.45rem .6rem;font-size:.85rem"
                     oninput="syncManageQFromCats(this.form)" onchange="syncManageQFromCats(this.form)">
            </div>
            <div class="form-group" style="margin:0;min-width:80px">
              <label style="font-size:.72rem;color:var(--muted)">Logical</label>
              <input class="form-control q-cat" type="number" name="logical_questions" value="<?= (int) $exam['logical_questions'] ?>"
                     min="0" style="padding:.45rem .6rem;font-size:.85rem"
                     oninput="syncManageQFromCats(this.form)" onchange="syncManageQFromCats(this.form)">
            </div>
            <button class="btn btn-primary btn-sm" type="submit">💾 Update</button>
          </div>
          <div class="q-form-hint" style="font-size:.75rem;color:var(--muted);margin-top:.5rem"></div>
        </form>
      </details>
      <?php endif; ?>

      <?php if ((int) $exam['results_count'] > 0): ?>
      <details class="exam-panel-details" style="margin-top:.8rem;border-top:1px solid var(--border);padding-top:1rem">
        <summary style="cursor:pointer;font-size:.88rem;font-weight:600;color:var(--accent)">📧 Email Results to Participants</summary>
        <div style="margin-top:.8rem">
          <?php if (!$smtpReady): ?>
          <div class="alert alert-error" style="font-size:.82rem;margin-bottom:.8rem">
            ⚠️ SMTP not configured. <a href="settings.php" style="color:var(--sky)">Configure email in Settings</a> first.
          </div>
          <?php endif; ?>
          <div style="font-size:.8rem;color:var(--muted);margin-bottom:.8rem">
            <?= (int) $exam['results_count'] ?> result(s) available —
            <?= (int) $exam['email_ready_count'] ?> with email on file.
            Each participant receives their own result individually.
          </div>
          <form method="POST" onsubmit="return confirm('Send result emails to all participants with email addresses?')">
            <input type="hidden" name="action" value="send_result_emails">
            <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
            <div class="form-group" style="margin-bottom:.8rem">
              <label style="font-size:.75rem;color:var(--muted)">Email Subject</label>
              <input class="form-control" type="text" name="email_subject"
                     value="Your Result: <?= htmlspecialchars($exam['title']) ?>"
                     style="padding:.5rem .7rem;font-size:.85rem" <?= !$smtpReady ? 'disabled' : '' ?>>
            </div>
            <div class="form-group" style="margin-bottom:.8rem">
              <label style="font-size:.75rem;color:var(--muted)">Custom Message</label>
              <textarea class="form-control" name="email_message" rows="4" required
                        style="padding:.5rem .7rem;font-size:.85rem" <?= !$smtpReady ? 'disabled' : '' ?>><?= htmlspecialchars($defaultEmailMessage) ?></textarea>
              <div style="font-size:.72rem;color:var(--muted);margin-top:.4rem">
                Placeholders: {name}, {exam_title}, {marks}, {total_marks}, {percentage}, {result}, {correct}, {wrong}, {skipped}, {attempted}, {roll_number}, {mobile}
              </div>
            </div>
            <button class="btn btn-primary btn-sm" type="submit" <?= (!$smtpReady || (int)$exam['email_ready_count'] === 0) ? 'disabled' : '' ?>>
              📤 Send to All (<?= (int) $exam['email_ready_count'] ?>)
            </button>
          </form>
        </div>
      </details>
      <?php endif; ?>
    </div>
    <?php endwhile; ?>
  </div>
</div>
</div>
<style>
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}
.exam-panel-details summary{list-style:none;display:flex;align-items:center;gap:6px}
.exam-panel-details summary::-webkit-details-marker{display:none}
.exam-panel-details[open] summary{margin-bottom:.2rem}
.live-status-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:.75rem}
.live-status-active{background:rgba(129,199,132,.15);color:#81c784}
.live-status-idle{background:rgba(255,183,3,.12);color:#ffb703}
</style>
<script>
const LIVE_REFRESH_MS = 15000;
const LIVE_API_URL = 'api_live_students.php';

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function renderLiveStudentRows(students) {
  if (!students.length) {
    return '';
  }
  return students.map(function (student) {
    const statusIcon = student.status === 'active' ? '🟢' : '🟡';
    return '<tr>'
      + '<td>' + escapeHtml(student.full_name) + '</td>'
      + '<td>' + escapeHtml(student.roll_number || '—') + '</td>'
      + '<td>' + escapeHtml(student.mobile || '—') + '</td>'
      + '<td><span class="live-status-badge live-status-' + escapeHtml(student.status) + '">'
      + statusIcon + ' ' + escapeHtml(student.status_label) + '</span></td>'
      + '<td>' + escapeHtml(student.started_label || '—') + '</td>'
      + '<td>' + escapeHtml(student.last_label || '—') + '</td>'
      + '<td>' + Number(student.answered_count || 0) + '</td>'
      + '</tr>';
  }).join('');
}

function renderLiveStudentList(students) {
  if (!students.length) {
    return '<div class="live-empty" style="font-size:.85rem;color:var(--muted);padding:.75rem 0">'
      + 'No students are in this exam right now. Counts refresh automatically when someone starts.'
      + '</div>';
  }
  return '<div style="overflow-x:auto"><table class="tbl" style="font-size:.82rem"><thead><tr>'
    + '<th>Student</th><th>Roll No.</th><th>Mobile</th><th>Status</th><th>Started</th><th>Last activity</th><th>Answered</th>'
    + '</tr></thead><tbody>' + renderLiveStudentRows(students) + '</tbody></table></div>';
}

function updateLiveExamPanel(examData) {
  const panel = document.querySelector('.live-exam-panel[data-exam-id="' + examData.exam_id + '"]');
  if (!panel) return;

  const totalEl = panel.querySelector('.live-count-total');
  const activeEl = panel.querySelector('.live-count-active');
  const listEl = panel.querySelector('.live-student-list');
  if (totalEl) totalEl.textContent = examData.live_count;
  if (activeEl) activeEl.textContent = examData.active_now_count;
  if (listEl) listEl.innerHTML = renderLiveStudentList(examData.students || []);

  const meta = document.querySelector('.live-meta-count[data-exam-id="' + examData.exam_id + '"]');
  if (meta) {
    meta.textContent = '🟢 ' + examData.live_count + ' live · ' + examData.active_now_count + ' active now';
  }
}

function updateLiveSummary(data) {
  const liveEl = document.getElementById('liveSummaryLive');
  const activeEl = document.getElementById('liveSummaryActive');
  const updatedEl = document.getElementById('liveSummaryUpdated');
  if (liveEl) liveEl.textContent = data.total_live;
  if (activeEl) activeEl.textContent = data.total_active_now;
  if (updatedEl) updatedEl.textContent = new Date().toLocaleTimeString();
}

async function refreshLiveMonitor() {
  if (!document.querySelector('.live-exam-panel, .live-summary-card')) return;
  try {
    const response = await fetch(LIVE_API_URL, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    });
    if (!response.ok) return;
    const data = await response.json();
    if (!data || !Array.isArray(data.exams)) return;
    updateLiveSummary(data);
    data.exams.forEach(updateLiveExamPanel);
  } catch (err) {
    // ignore transient refresh errors
  }
}

if (document.querySelector('.live-exam-panel, .live-summary-card')) {
  setInterval(refreshLiveMonitor, LIVE_REFRESH_MS);
}

function syncManageQFromCats(form) {
  const gk = parseInt(form.gk_questions.value, 10) || 0;
  const en = parseInt(form.english_questions.value, 10) || 0;
  const log = parseInt(form.logical_questions.value, 10) || 0;
  form.total_questions.value = gk + en + log;
  updateManageQHint(form);
}
function syncManageQTotal(form) {
  updateManageQHint(form);
}
function updateManageQHint(form) {
  const total = parseInt(form.total_questions.value, 10) || 0;
  const gk = parseInt(form.gk_questions.value, 10) || 0;
  const en = parseInt(form.english_questions.value, 10) || 0;
  const log = parseInt(form.logical_questions.value, 10) || 0;
  const assigned = gk + en + log;
  const hint = form.querySelector('.q-form-hint');
  if (!hint) return;
  if (assigned === total) {
    hint.textContent = '✅ Categories match total (' + total + ')';
    hint.style.color = '#81c784';
  } else {
    hint.textContent = '⚠️ Categories (' + assigned + ') must equal total (' + total + ')';
    hint.style.color = '#ef9a9a';
  }
}
function validateQForm(form) {
  const total = parseInt(form.total_questions.value, 10) || 0;
  const gk = parseInt(form.gk_questions.value, 10) || 0;
  const en = parseInt(form.english_questions.value, 10) || 0;
  const log = parseInt(form.logical_questions.value, 10) || 0;
  if (gk + en + log !== total) {
    alert('Category questions must equal total questions (' + total + ').');
    return false;
  }
  return true;
}
document.querySelectorAll('.exam-panel-details form').forEach(f => {
  if (f.querySelector('[name=total_questions]')) updateManageQHint(f);
});
</script>
</body></html>
