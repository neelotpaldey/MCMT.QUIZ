<?php
// developed by @neelotpal.dey
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();
startStudentSession();

if (validateStudentSession($db)) {
    header('Location: ' . BASE_URL . '/student/instructions.php');
    exit;
}

$error = '';
$deviceWarning = false;
$pendingMobile = '';
$pendingDob = '';

if (isset($_GET['err']) && $_GET['err'] === 'other_device') {
    $error = 'Your account was opened on another device. Please log in again to continue here.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mobile = sanitize($db, $_POST['mobile'] ?? '');
    $dob    = sanitize($db, $_POST['dob'] ?? '');
    $forceLogin = isset($_POST['force_login']) && $_POST['force_login'] === '1';
    $pendingMobile = $_POST['mobile'] ?? '';
    $pendingDob = $_POST['dob'] ?? '';

    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        $error = 'Please enter a valid 10-digit Indian mobile number.';
    } elseif (empty($dob)) {
        $error = 'Please enter your date of birth.';
    } else {
        try {
            $stmt = $db->prepare('SELECT id, full_name, is_active FROM students WHERE mobile = ? AND dob = ?');
            $stmt->bind_param('ss', $mobile, $dob);
            $stmt->execute();
            $res     = $stmt->get_result();
            $student = $res->fetch_assoc();
            $stmt->close();

            if (!$student) {
                $error = 'Invalid mobile number or date of birth. Please check and try again.';
            } elseif (!$student['is_active']) {
                $error = 'Your account has been deactivated. Please contact the administrator.';
            } elseif (studentHasActiveLogin($db, (int) $student['id']) && !$forceLogin) {
                $deviceWarning = true;
            } else {
                loginStudent($db, (int) $student['id'], $student['full_name']);
                header('Location: ' . BASE_URL . '/student/instructions.php');
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            logDbError($e, 'student_login');
            $error = 'Unable to sign in right now. Please try again in a moment.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Login – ExamPortal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<?php themeInitScript(); themeStylesheet(); themeScript(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root, [data-theme="dark"] {
  --navy:   #0d1b2a;
  --ocean:  #1a3a5c;
  --sky:    #1e88e5;
  --accent: #ffb703;
  --white:  #f8f9fa;
  --glass:  rgba(255,255,255,0.07);
  --border: rgba(255,255,255,0.12);
  --error:  #ff5252;
  --radius: 14px;
}

body.student-login {
  min-height: 100vh;
  background: var(--navy);
  background-image:
    radial-gradient(ellipse 80% 60% at 20% -10%, rgba(30,136,229,.35) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 90% 110%, rgba(255,183,3,.15) 0%, transparent 55%);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'DM Sans', sans-serif;
  color: var(--white);
  padding: 2rem;
}

.card {
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--border);
  backdrop-filter: blur(20px);
  border-radius: 24px;
  padding: 3rem 3.5rem;
  width: 100%;
  max-width: 460px;
  box-shadow: 0 32px 64px rgba(0,0,0,.45);
  animation: slideUp .5s cubic-bezier(.22,.68,0,1.2) both;
}

@keyframes slideUp {
  from { opacity:0; transform:translateY(28px); }
  to   { opacity:1; transform:translateY(0); }
}

.logo-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 2rem;
}

.logo-icon {
  width: 44px; height: 44px;
  background: linear-gradient(135deg, var(--sky), #42a5f5);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
}

.logo-text {
  font-family: 'Playfair Display', serif;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -0.02em;
}

.logo-text span { color: var(--accent); }

h2 {
  font-size: 1.6rem;
  font-weight: 600;
  margin-bottom: 0.4rem;
}

.subtitle {
  font-size: 0.9rem;
  color: rgba(255,255,255,0.5);
  margin-bottom: 2rem;
}

label {
  display: block;
  font-size: 0.82rem;
  font-weight: 500;
  color: rgba(255,255,255,0.7);
  margin-bottom: 6px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.input-wrap {
  position: relative;
  margin-bottom: 1.4rem;
}

.input-wrap .icon {
  position: absolute;
  left: 14px; top: 50%;
  transform: translateY(-50%);
  font-size: 1.1rem;
  opacity: 0.5;
  pointer-events: none;
}

input {
  width: 100%;
  background: rgba(255,255,255,0.06);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 13px 14px 13px 44px;
  color: var(--white);
  font-family: 'DM Sans', sans-serif;
  font-size: 1rem;
  outline: none;
  transition: border-color .2s, background .2s;
}
input:focus {
  border-color: var(--sky);
  background: rgba(30,136,229,.08);
}
input::-webkit-calendar-picker-indicator { filter: invert(1) opacity(0.5); }

.error-box {
  background: rgba(255,82,82,0.12);
  border: 1px solid rgba(255,82,82,0.35);
  border-radius: 10px;
  padding: 12px 16px;
  font-size: 0.88rem;
  color: #ff8a80;
  margin-bottom: 1.4rem;
  display: flex;
  gap: 8px;
  align-items: flex-start;
}

.btn {
  width: 100%;
  padding: 14px;
  background: linear-gradient(135deg, var(--sky), #1565c0);
  border: none;
  border-radius: var(--radius);
  color: #fff;
  font-family: 'DM Sans', sans-serif;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  letter-spacing: 0.02em;
  transition: transform .15s, box-shadow .15s, opacity .15s;
  box-shadow: 0 8px 24px rgba(30,136,229,.4);
}
.btn:hover { transform: translateY(-1px); box-shadow: 0 12px 32px rgba(30,136,229,.5); }
.btn:active { transform: translateY(0); }

.divider {
  text-align: center;
  color: rgba(255,255,255,0.3);
  font-size: 0.8rem;
  margin: 1.4rem 0;
  position: relative;
}
.divider::before, .divider::after {
  content: '';
  position: absolute;
  top: 50%; width: 38%; height: 1px;
  background: var(--border);
}
.divider::before { left: 0; }
.divider::after  { right: 0; }

.info-note {
  text-align: center;
  font-size: 0.82rem;
  color: rgba(255,255,255,0.4);
  line-height: 1.6;
}
.info-note strong { color: var(--accent); }

.device-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.65);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  z-index: 1000;
}
.device-modal {
  width: 100%;
  max-width: 420px;
  background: #132033;
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 1.5rem;
  box-shadow: 0 24px 48px rgba(0,0,0,.45);
}
.device-modal h3 {
  font-size: 1.1rem;
  margin-bottom: .75rem;
}
.device-modal p {
  font-size: .9rem;
  color: rgba(255,255,255,.72);
  line-height: 1.55;
  margin-bottom: 1.2rem;
}
.device-modal-actions {
  display: flex;
  gap: .75rem;
}
.device-modal-actions .btn,
.device-modal-actions .btn-secondary {
  flex: 1;
  width: auto;
  padding: 12px;
}
.btn-secondary {
  background: rgba(255,255,255,.08);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--white);
  font-family: 'DM Sans', sans-serif;
  font-size: .95rem;
  font-weight: 600;
  cursor: pointer;
}
</style>
</head>
<body class="student-login">
<?php themeToggleButton('theme-toggle-fixed'); ?>
<div class="card">
  <div class="logo-row">
    <div class="logo-icon">🎓</div>
    <div class="logo-text">Exam<span>Portal</span></div>
  </div>

  <h2>Student Login</h2>
  <p class="subtitle">Enter your credentials to access the exam</p>

  <?php if ($error): ?>
    <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off" id="loginForm">
    <div class="input-wrap">
      <label>Mobile Number</label>
      <span class="icon">📱</span>
      <input type="tel" name="mobile" placeholder="10-digit mobile number"
             pattern="[6-9][0-9]{9}" maxlength="10" required
             value="<?= htmlspecialchars($pendingMobile !== '' ? $pendingMobile : ($_POST['mobile'] ?? '')) ?>">
    </div>

    <div class="input-wrap">
      <label>Date of Birth</label>
      <span class="icon">📅</span>
      <input type="date" name="dob" required
             max="<?= date('Y-m-d', strtotime('-10 years')) ?>"
             value="<?= htmlspecialchars($pendingDob !== '' ? $pendingDob : ($_POST['dob'] ?? '')) ?>">
    </div>

    <button type="submit" class="btn">Login to Exam Portal →</button>
  </form>

  <?php if ($deviceWarning): ?>
  <div class="device-modal-backdrop" id="deviceWarningModal">
    <div class="device-modal" role="dialog" aria-modal="true" aria-labelledby="deviceWarningTitle">
      <h3 id="deviceWarningTitle">Already logged in elsewhere</h3>
      <p>
        This account is already active on another device or browser.
        If you continue here, the previous session will be closed and you will log in on this device.
      </p>
      <form method="POST" class="device-modal-actions">
        <input type="hidden" name="mobile" value="<?= htmlspecialchars($pendingMobile) ?>">
        <input type="hidden" name="dob" value="<?= htmlspecialchars($pendingDob) ?>">
        <input type="hidden" name="force_login" value="1">
        <button type="button" class="btn-secondary" onclick="document.getElementById('deviceWarningModal').remove()">No, Cancel</button>
        <button type="submit" class="btn">Yes, Login Here</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="divider">secure login</div>
  <p class="info-note">
    Use your registered <strong>mobile number</strong> and <strong>date of birth</strong>.<br>
    Contact your administrator if you face any issues.
  </p>
</div>
</body>
</html>
