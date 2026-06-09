<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';
requireAdminLogin();
$db = getDB();

$pageTitle = 'Manage Students'; $pageKey = 'students';
$msg = ''; $msgType = 'info';
$action = $_GET['action'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add' || $postAction === 'edit') {
        $fullName  = sanitize($db, $_POST['full_name'] ?? '');
        $mobile    = sanitize($db, $_POST['mobile'] ?? '');
        $dob       = sanitize($db, $_POST['dob'] ?? '');
        $email     = sanitize($db, $_POST['email'] ?? '');
        $rollNo    = sanitize($db, $_POST['roll_number'] ?? '');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if (!$fullName || !$mobile || !$dob) {
            $msg = 'Name, Mobile, and DOB are required.'; $msgType = 'error';
        } elseif (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
            $msg = 'Invalid mobile number.'; $msgType = 'error';
        } else {
            if ($postAction === 'add') {
                $stmt = $db->prepare("INSERT INTO students (full_name,mobile,dob,email,roll_number,is_active) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('sssssi', $fullName,$mobile,$dob,$email,$rollNo,$isActive);
                if ($stmt->execute()) { $msg='Student added successfully!'; $msgType='success'; }
                else { $msg='Error: Mobile may already be registered.'; $msgType='error'; }
                $stmt->close();
            } else {
                $sid = (int)$_POST['student_id'];
                $stmt = $db->prepare("UPDATE students SET full_name=?,mobile=?,dob=?,email=?,roll_number=?,is_active=? WHERE id=?");
                $stmt->bind_param('sssssii', $fullName,$mobile,$dob,$email,$rollNo,$isActive,$sid);
                $stmt->execute(); $stmt->close();
                $msg='Student updated.'; $msgType='success';
            }
        }
    } elseif ($postAction === 'bulk_import') {
        $csv = $_POST['csv_data'] ?? '';
        $lines = explode("\n", trim($csv));
        $added = 0; $errors = 0;
        foreach ($lines as $line) {
            $parts = str_getcsv(trim($line));
            if (count($parts) < 3) continue;
            $fn = trim($parts[0]); $mob = trim($parts[1]); $dob = trim($parts[2]);
            $email = trim($parts[3] ?? ''); $roll = trim($parts[4] ?? '');
            if (!$fn || !$mob || !$dob) { $errors++; continue; }
            $stmt = $db->prepare("INSERT IGNORE INTO students (full_name,mobile,dob,email,roll_number) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssss',$fn,$mob,$dob,$email,$roll);
            $stmt->execute() ? $added++ : $errors++;
            $stmt->close();
        }
        $msg = "Bulk import: $added added, $errors failed."; $msgType='success';
    } elseif ($postAction === 'toggle') {
        $sid = (int)$_POST['student_id'];
        $db->query("UPDATE students SET is_active = 1-is_active WHERE id=$sid");
        $msg='Status updated.'; $msgType='success';
    } elseif ($postAction === 'delete') {
        $sid = (int)$_POST['student_id'];
        $db->query("DELETE FROM student_answers WHERE session_id IN (SELECT id FROM student_exam_sessions WHERE student_id=$sid)");
        $db->query("DELETE FROM exam_results WHERE student_id=$sid");
        $db->query("DELETE FROM student_exam_sessions WHERE student_id=$sid");
        $db->query("DELETE FROM students WHERE id=$sid");
        $msg='Student deleted.'; $msgType='info';
    } elseif ($postAction === 'send_student_emails') {
        if (!isSmtpConfigured($db)) {
            $msg = 'SMTP is not configured. Go to Settings → Email (SMTP) first.'; $msgType = 'error';
        } else {
            $customMessage = trim($_POST['email_message'] ?? '');
            $emailSubject  = trim($_POST['email_subject'] ?? '');
            if ($customMessage === '') {
                $msg = 'Please enter a custom message.'; $msgType = 'error';
            } else {
                if ($emailSubject === '') {
                    $emailSubject = 'Message from Exam Portal';
                }
                $onlyActive = isset($_POST['only_active']);
                $whereMail  = "email IS NOT NULL AND email != ''";
                if ($onlyActive) {
                    $whereMail .= ' AND is_active = 1';
                }
                $rows = $db->query("SELECT * FROM students WHERE $whereMail ORDER BY full_name");
                $sent = 0; $skipped = 0; $failed = 0; $authError = '';
                while ($student = $rows->fetch_assoc()) {
                    $email = trim($student['email'] ?? '');
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skipped++;
                        continue;
                    }
                    try {
                        sendCustomStudentEmail($db, $student, $customMessage, $emailSubject);
                        $sent++;
                        throttleBulkEmail();
                    } catch (Throwable $e) {
                        $failed++;
                        logDbError($e, 'send_student_emails');
                        if (isSmtpAuthError($e)) {
                            $authError = $e->getMessage();
                            break;
                        }
                    }
                }
                if ($authError !== '') {
                    $msg = $authError; $msgType = 'error';
                } elseif ($sent === 0 && $failed === 0) {
                    $msg = 'No emails sent — no students with valid email on file.'; $msgType = 'error';
                } elseif ($failed > 0) {
                    $msg = "Sent $sent email(s), failed $failed."; $msgType = $sent > 0 ? 'info' : 'error';
                } else {
                    $msg = "Successfully sent email to $sent student(s)."; $msgType = 'success';
                }
            }
        }
    }
    $action='list';
}

// Edit mode - load student
$editStudent = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    $editStudent = $db->query("SELECT * FROM students WHERE id=$sid")->fetch_assoc();
}

// Search & pagination
$search = sanitize($db, $_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20; $offset = ($page-1)*$limit;

$where = $search ? "WHERE full_name LIKE '%$search%' OR mobile LIKE '%$search%' OR roll_number LIKE '%$search%' OR email LIKE '%$search%'" : '';
$total = $db->query("SELECT COUNT(*) FROM students $where")->fetch_row()[0];
$pages = ceil($total/$limit);
$students = $db->query("SELECT * FROM students $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$emailStats = $db->query("SELECT
    COUNT(*) AS total_students,
    SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) AS with_email,
    SUM(CASE WHEN is_active = 1 AND email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) AS active_with_email
    FROM students")->fetch_assoc();
$smtpReady = isSmtpConfigured($db);
$defaultStudentEmailMessage = "Hello {name},\n\nThis is a message from Exam Portal regarding your account.\n\nRegards,\nExam Administration";

include __DIR__ . '/layout_head.php';
?>
<div class="content">
  <div class="topbar">
    <div class="page-title">👥 Manage Students</div>
    <div style="display:flex;gap:.6rem">
      <button class="btn btn-outline btn-sm" onclick="toggleBulk()">📥 Bulk Import</button>
      <button class="btn btn-primary btn-sm" onclick="showAddForm()">➕ Add Student</button>
    </div>
  </div>
  <div class="page-body">
    <?php if($msg): ?><div class="alert alert-<?= $msgType ?>"><?= $msgType==='success'?'✅':'❌' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- Add/Edit Form -->
    <div id="studentForm" class="card" style="margin-bottom:1.2rem;<?= ($action==='add'||$editStudent)?'':'display:none' ?>">
      <div class="card-header">
        <div class="card-title"><?= $editStudent ? '✏️ Edit Student' : '➕ Add Student' ?></div>
        <button class="btn btn-outline btn-sm" onclick="hideForm()">✕ Cancel</button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="<?= $editStudent?'edit':'add' ?>">
        <?php if ($editStudent): ?><input type="hidden" name="student_id" value="<?= $editStudent['id'] ?>"><?php endif; ?>
        <div class="form-row three">
          <div class="form-group">
            <label>Full Name *</label>
            <input class="form-control" type="text" name="full_name" required value="<?= htmlspecialchars($editStudent['full_name']??'') ?>">
          </div>
          <div class="form-group">
            <label>Mobile Number *</label>
            <input class="form-control" type="tel" name="mobile" pattern="[6-9][0-9]{9}" maxlength="10" required value="<?= htmlspecialchars($editStudent['mobile']??'') ?>">
          </div>
          <div class="form-group">
            <label>Date of Birth *</label>
            <input class="form-control" type="date" name="dob" required value="<?= htmlspecialchars($editStudent['dob']??'') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Email <span style="color:var(--muted);font-weight:400;text-transform:none">(required for result emails)</span></label>
            <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($editStudent['email']??'') ?>" placeholder="student@email.com">
          </div>
          <div class="form-group">
            <label>Roll Number</label>
            <input class="form-control" type="text" name="roll_number" value="<?= htmlspecialchars($editStudent['roll_number']??'') ?>">
          </div>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:10px">
          <label class="switch">
            <input type="checkbox" name="is_active" <?= ($editStudent['is_active']??1)?'checked':'' ?>>
            <span class="slider"></span>
          </label>
          <span style="font-size:.88rem">Active (can login)</span>
        </div>
        <button type="submit" class="btn btn-primary"><?= $editStudent?'💾 Update Student':'➕ Add Student' ?></button>
      </form>
    </div>

    <!-- Email Students -->
    <div class="card" style="margin-bottom:1.2rem">
      <details class="exam-panel-details">
        <summary style="cursor:pointer;font-size:.95rem;font-weight:600;color:var(--accent)">📧 Email Students</summary>
        <div style="margin-top:1rem">
          <?php if (!$smtpReady): ?>
          <div class="alert alert-error" style="font-size:.82rem;margin-bottom:.8rem">
            ⚠️ SMTP not configured. <a href="settings.php" style="color:var(--sky)">Configure email in Settings</a> first.
          </div>
          <?php endif; ?>
          <div style="font-size:.82rem;color:var(--muted);margin-bottom:.8rem">
            <?= (int) $emailStats['with_email'] ?> of <?= (int) $emailStats['total_students'] ?> students have email on file.
            Each student receives an individual message.
          </div>
          <form method="POST" onsubmit="return confirm('Send this email to all students with email addresses?')">
            <input type="hidden" name="action" value="send_student_emails">
            <div class="form-group" style="margin-bottom:.8rem">
              <label style="font-size:.75rem;color:var(--muted)">Email Subject</label>
              <input class="form-control" type="text" name="email_subject" value="Message from Exam Portal"
                     <?= !$smtpReady ? 'disabled' : '' ?>>
            </div>
            <div class="form-group" style="margin-bottom:.8rem">
              <label style="font-size:.75rem;color:var(--muted)">Custom Message</label>
              <textarea class="form-control" name="email_message" rows="4" required
                        <?= !$smtpReady ? 'disabled' : '' ?>><?= htmlspecialchars($defaultStudentEmailMessage) ?></textarea>
              <div style="font-size:.72rem;color:var(--muted);margin-top:.4rem">
                Placeholders: {name}, {email}, {mobile}, {roll_number}, {dob}
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;margin-bottom:.8rem;cursor:pointer">
              <input type="checkbox" name="only_active" value="1" checked style="accent-color:var(--sky)">
              Only active students (<?= (int) $emailStats['active_with_email'] ?> with email)
            </label>
            <button class="btn btn-primary btn-sm" type="submit"
                    <?= (!$smtpReady || (int)$emailStats['active_with_email'] === 0) ? 'disabled' : '' ?>>
              📤 Send to All (<?= (int) $emailStats['active_with_email'] ?>)
            </button>
          </form>
        </div>
      </details>
    </div>

    <!-- Bulk Import -->
    <div id="bulkForm" class="card" style="margin-bottom:1.2rem;display:none">
      <div class="card-header">
        <div class="card-title">📥 Bulk Import Students (CSV)</div>
        <button class="btn btn-outline btn-sm" onclick="hideBulk()">✕</button>
      </div>
      <p style="font-size:.82rem;color:var(--muted);margin-bottom:.8rem">Format: Full Name, Mobile, DOB (YYYY-MM-DD), Email (optional), Roll Number (optional)</p>
      <div style="font-size:.78rem;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;padding:.6rem;margin-bottom:.8rem;font-family:'DM Mono',monospace">
        Ravi Kumar,9876543210,2003-05-15,ravi@email.com,ROLL001<br>
        Priya Sharma,8765432109,2004-02-20,,ROLL002
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="bulk_import">
        <textarea class="form-control" name="csv_data" rows="6" placeholder="Paste CSV data here..."></textarea>
        <button type="submit" class="btn btn-primary" style="margin-top:.8rem">📥 Import Students</button>
      </form>
    </div>

    <!-- Search -->
    <div class="card" style="margin-bottom:1rem">
      <form method="GET" style="display:flex;gap:.8rem;align-items:center">
        <input class="form-control" type="text" name="search" placeholder="🔍 Search by name, mobile, email, roll number..." value="<?= htmlspecialchars($search) ?>" style="flex:1">
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if($search): ?><a href="students.php"><button class="btn btn-outline" type="button">✕ Clear</button></a><?php endif; ?>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title">All Students <span style="color:var(--muted);font-size:.85rem">(<?= $total ?>)</span></div>
      </div>
      <table class="tbl">
        <thead>
          <tr><th>#</th><th>Name</th><th>Mobile</th><th>Email</th><th>DOB</th><th>Roll No</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php $i = $offset+1; while ($s=$students->fetch_assoc()): ?>
          <tr>
            <td style="color:var(--muted)"><?= $i++ ?></td>
            <td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td>
            <td style="font-family:'DM Mono',monospace;font-size:.85rem"><?= htmlspecialchars($s['mobile']) ?></td>
            <td style="font-size:.82rem">
              <?php if (!empty($s['email'])): ?>
                <a href="mailto:<?= htmlspecialchars($s['email']) ?>" style="color:var(--sky)"><?= htmlspecialchars($s['email']) ?></a>
              <?php else: ?>
                <span style="color:var(--muted)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.82rem;color:var(--muted)"><?= date('d M Y', strtotime($s['dob'])) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($s['roll_number']??'-') ?></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <button class="badge <?= $s['is_active']?'badge-active':'badge-inactive' ?>" type="submit" style="cursor:pointer;border:none">
                  <?= $s['is_active']?'✅ Active':'⛔ Inactive' ?>
                </button>
              </form>
            </td>
            <td style="font-size:.78rem;color:var(--muted)"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:.4rem">
                <a href="?action=edit&id=<?= $s['id'] ?>"><button class="btn btn-outline btn-sm">✏️</button></a>
                <form method="POST" onsubmit="return confirm('Delete this student and ALL their exam data?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">🗑️</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endwhile; ?>
        <?php if ($total === 0): ?>
          <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--muted)">No students found</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <?php if ($pages > 1): ?>
      <div class="pagination">
        <?php for ($p=1;$p<=$pages;$p++): ?>
          <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>">
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
function showAddForm() {
  document.getElementById('studentForm').style.display='block';
  document.getElementById('bulkForm').style.display='none';
  document.getElementById('studentForm').scrollIntoView({behavior:'smooth'});
}
function hideForm() { document.getElementById('studentForm').style.display='none'; }
function toggleBulk() {
  const b = document.getElementById('bulkForm');
  b.style.display = b.style.display==='none'?'block':'none';
}
function hideBulk() { document.getElementById('bulkForm').style.display='none'; }
</script>
<style>
.exam-panel-details summary{list-style:none;display:flex;align-items:center;gap:6px}
.exam-panel-details summary::-webkit-details-marker{display:none}
</style>
</body></html>
